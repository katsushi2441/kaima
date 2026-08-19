#!/usr/bin/env python3
"""kaimaデモ用のvision中継。OpenAI互換の/v1/chat/completionsを受け、
ローカルOllama(gemma4)へ転送する。トークン必須・モデル固定・レート制限つき。
標準ライブラリのみ。systemd user unit: kaima-vision-relay.service (port 18343)
"""
import http.server
import json
import os
import time
import urllib.request

PORT = int(os.environ.get("KAIMA_RELAY_PORT", "18343"))
TOKEN = os.environ.get("KAIMA_RELAY_TOKEN", "")
UPSTREAM = os.environ.get("KAIMA_RELAY_UPSTREAM", "http://192.168.0.3:11434/api/chat")
MODEL = os.environ.get("KAIMA_RELAY_MODEL", "gemma4:12b-it-qat")
RATE_PER_HOUR = int(os.environ.get("KAIMA_RELAY_RATE", "40"))

hits = {}  # ip -> [timestamps]


def rate_ok(ip):
    now = time.time()
    lst = [t for t in hits.get(ip, []) if t > now - 3600]
    if len(lst) >= RATE_PER_HOUR:
        hits[ip] = lst
        return False
    lst.append(now)
    hits[ip] = lst
    return True


class H(http.server.BaseHTTPRequestHandler):
    def _json(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/healthz":
            return self._json(200, {"ok": 1, "app": "kaima-vision-relay"})
        return self._json(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/v1/chat/completions":
            return self._json(404, {"error": "not found"})
        auth = self.headers.get("Authorization", "")
        if not TOKEN or auth != f"Bearer {TOKEN}":
            return self._json(401, {"error": {"message": "invalid token"}})
        ip = self.client_address[0]
        if not rate_ok(ip):
            return self._json(429, {"error": {"message": "rate limited"}})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length > 12 * 1024 * 1024:
                return self._json(413, {"error": {"message": "payload too large"}})
            payload = json.loads(self.rfile.read(length))
        except Exception as exc:
            return self._json(400, {"error": {"message": f"bad json: {exc}"}})
        # OpenAI形式→Ollamaネイティブ/api/chatに変換する。
        # gemma4は思考型のためthink:false必須(OpenAI互換経由では指定できず、
        # 隠れ推論がmax_tokensを食い潰してcontentが空になる)。
        msgs = []
        for m in payload.get("messages", []):
            content = m.get("content", "")
            text_parts, images = [], []
            if isinstance(content, list):
                for part in content:
                    if part.get("type") == "text":
                        text_parts.append(part.get("text", ""))
                    elif part.get("type") == "image_url":
                        url = (part.get("image_url") or {}).get("url", "")
                        if url.startswith("data:"):
                            images.append(url.split(",", 1)[1])
            else:
                text_parts.append(str(content))
            om = {"role": m.get("role", "user"), "content": "\n".join(text_parts)}
            if images:
                om["images"] = images
            msgs.append(om)
        upstream_payload = {
            "model": MODEL,
            "messages": msgs,
            "stream": False,
            "think": False,
            "options": {"temperature": payload.get("temperature", 0),
                        "num_predict": min(int(payload.get("max_tokens", 700) or 700), 900)},
        }
        req = urllib.request.Request(UPSTREAM, data=json.dumps(upstream_payload).encode(),
                                     headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=150) as res:
                up = json.loads(res.read())
            answer = ((up.get("message") or {}).get("content") or "")
            out = {"choices": [{"index": 0, "finish_reason": up.get("done_reason", "stop"),
                                "message": {"role": "assistant", "content": answer}}],
                   "model": MODEL}
            return self._json(200, out)
        except Exception as exc:
            return self._json(502, {"error": {"message": f"upstream: {exc}"}})

    def log_message(self, fmt, *args):
        print(f"{time.strftime('%H:%M:%S')} {self.client_address[0]} {fmt % args}", flush=True)


if __name__ == "__main__":
    if not TOKEN:
        raise SystemExit("KAIMA_RELAY_TOKEN を設定してください")
    print(f"kaima-vision-relay :{PORT} → {UPSTREAM} ({MODEL})", flush=True)
    http.server.ThreadingHTTPServer(("0.0.0.0", PORT), H).serve_forever()
