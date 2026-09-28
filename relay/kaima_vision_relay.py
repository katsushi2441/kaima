#!/usr/bin/env python3
"""0.3 の Ollama(gemma4) への共通の中継（port 18343）。heteml の PHP デモは社内の Ollama に届かないので、ここを通す。

もとは kaima デモ用の vision 中継。**gemma4 の中継を製品ごとにポートを分けて立てない**ために、ここに寄せた
（2026-09-29：klchatbot 用に 18394 を新しく立てて「多重にポートを使うな」と叱責された）。

- KAIMA_RELAY_TOKEN（kaima）… これまでどおり。画像つきの OpenAI 形式を Ollama ネイティブ /api/chat に変換（think:false）
- RELAY_CLIENT_<名前>=<合言葉>（klchatbot など）… Ollama の OpenAI 互換 /v1/chat/completions にそのまま中継。
  逐次表示(stream)と道具(tools)が通る。モデルは gemma4 に固定、思考は切る(reasoning_effort=none)、max_tokens は 1200 まで
- 回数制限: kaima は IP ごと（従来どおり）。ほかの製品は heteml から同じ IP で来るので、製品ごとに1時間 RELAY_CLIENT_RATE 回
- **デモで DeepSeek を使わない**（有料サービス専用）。ここは gemma4 にしかつながない
標準ライブラリのみ。systemd user unit: kaima-vision-relay.service
"""
import hmac
import http.server
import json
import os
import threading
import time
import urllib.error
import urllib.request

PORT = int(os.environ.get("KAIMA_RELAY_PORT", "18343"))
TOKEN = os.environ.get("KAIMA_RELAY_TOKEN", "")
UPSTREAM = os.environ.get("KAIMA_RELAY_UPSTREAM", "http://192.168.0.3:11434/api/chat")
MODEL = os.environ.get("KAIMA_RELAY_MODEL", "gemma4:12b-it-qat")
RATE_PER_HOUR = int(os.environ.get("KAIMA_RELAY_RATE", "40"))
# 共通の中継として受ける製品: RELAY_CLIENT_KLCHATBOT=<合言葉> のように環境変数で渡す
CLIENTS = {k[len("RELAY_CLIENT_"):].lower(): v for k, v in os.environ.items()
           if k.startswith("RELAY_CLIENT_") and k != "RELAY_CLIENT_RATE" and v}
CLIENT_RATE = int(os.environ.get("RELAY_CLIENT_RATE", "300"))
OPENAI_UPSTREAM = os.environ.get("RELAY_OPENAI_UPSTREAM", "http://192.168.0.3:11434/v1/chat/completions")
PASS_MAX_TOKENS = 1200
PASS_SEM = threading.BoundedSemaphore(2)   # GPU を詰まらせない（同時2本まで）

# vista-ats の /analyze も、ここで受ける（もとは vista-ats-relay :18346）。
# 処理は vista-ats の製品コード（vista-ats/relay/vista_ats_relay.py）をそのまま読み込んで使う（写さない）。
# **バックエンドは ollama(gemma4) に固定**。vista のコードには OpenAI 互換(DeepSeek)の道もあるが、デモでは通さない。
VISTA = None
VISTA_RELAY_PY = os.environ.get("VISTA_RELAY_PY", "/home/kojima/work/vista-ats/relay/vista_ats_relay.py")
if os.environ.get("RELAY_VISTA_TOKEN") and os.path.exists(VISTA_RELAY_PY):
    import importlib.util
    os.environ["VISTA_RELAY_BACKEND"] = "ollama"
    os.environ["VISTA_RELAY_TOKEN"] = os.environ["RELAY_VISTA_TOKEN"]
    os.environ.setdefault("VISTA_OLLAMA_URL", "http://192.168.0.3:11434")
    os.environ.setdefault("VISTA_OLLAMA_MODEL", MODEL)
    _spec = importlib.util.spec_from_file_location("vista_ats_relay", VISTA_RELAY_PY)
    VISTA = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(VISTA)
    assert VISTA.BACKEND == "ollama"

hits = {}  # ip -> [timestamps]


def rate_ok(ip, limit=None):
    now = time.time()
    limit = RATE_PER_HOUR if limit is None else limit
    lst = [t for t in hits.get(ip, []) if t > now - 3600]
    if len(lst) >= limit:
        hits[ip] = lst
        return False
    lst.append(now)
    hits[ip] = lst
    return True


def client_of(auth):
    """合言葉から、どの製品かを返す（kaima / klchatbot など）。合わなければ None"""
    if TOKEN and hmac.compare_digest(auth, f"Bearer {TOKEN}"):
        return "kaima"
    for name, tok in CLIENTS.items():
        if hmac.compare_digest(auth, f"Bearer {tok}"):
            return name
    return None


class H(http.server.BaseHTTPRequestHandler):
    def _json(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _send(self, status, obj):   # vista-ats のコードが使う名前
        return self._json(status, obj)

    def do_GET(self):
        if VISTA is not None and self.path.rstrip("/") == "/health":
            return VISTA.Handler.do_GET(self)
        if self.path == "/healthz":
            return self._json(200, {"ok": 1, "app": "kaima-vision-relay", "model": MODEL,
                                    "clients": ["kaima"] + sorted(CLIENTS) + (["vista-ats"] if VISTA else [])})
        return self._json(404, {"error": "not found"})

    def do_POST(self):
        if VISTA is not None and self.path.rstrip("/") == "/analyze":
            return VISTA.Handler.do_POST(self)   # 合言葉(X-Vista-Token)の確かめも vista のコードで行う
        if self.path != "/v1/chat/completions":
            return self._json(404, {"error": "not found"})
        client = client_of(self.headers.get("Authorization", ""))
        if not client:
            return self._json(401, {"error": {"message": "invalid token"}})
        ip = self.client_address[0]
        if client != "kaima":
            if not rate_ok("client:" + client, CLIENT_RATE):
                return self._json(429, {"error": {"message": "rate limited"}})
            return self._passthrough()
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

    def _passthrough(self):
        """kaima 以外の製品: Ollama の OpenAI 互換口へそのまま中継する（stream・tools が通る）"""
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 256 * 1024:
                return self._json(413 if length > 0 else 400, {"error": {"message": "bad body size"}})
            req = json.loads(self.rfile.read(length))
        except Exception:  # noqa: BLE001
            return self._json(400, {"error": {"message": "bad json"}})
        if not isinstance(req, dict) or not isinstance(req.get("messages"), list):
            return self._json(400, {"error": {"message": "messages required"}})
        req["model"] = MODEL                 # 頼まれたモデル名は使わない（gemma4 固定）
        req["reasoning_effort"] = "none"     # gemma4 は思考型。切らないと空応答になる
        try:
            req["max_tokens"] = min(int(req.get("max_tokens") or PASS_MAX_TOKENS), PASS_MAX_TOKENS)
        except (TypeError, ValueError):
            req["max_tokens"] = PASS_MAX_TOKENS
        if not PASS_SEM.acquire(timeout=30):
            return self._json(429, {"error": {"message": "busy"}})
        try:
            up = urllib.request.Request(OPENAI_UPSTREAM, data=json.dumps(req).encode(),
                                        headers={"Content-Type": "application/json"}, method="POST")
            try:
                resp = urllib.request.urlopen(up, timeout=180)
            except urllib.error.HTTPError as e:
                return self._json(e.code if 400 <= e.code < 600 else 502, {"error": {"message": f"upstream {e.code}"}})
            except Exception:  # noqa: BLE001
                return self._json(502, {"error": {"message": "upstream unavailable"}})
            with resp:
                self.send_response(200)
                self.send_header("Content-Type", resp.headers.get("Content-Type", "application/json"))
                self.send_header("Cache-Control", "no-cache")
                self.end_headers()
                while True:
                    chunk = resp.read1(8192)
                    if not chunk:
                        break
                    try:
                        self.wfile.write(chunk)
                        self.wfile.flush()
                    except (BrokenPipeError, ConnectionResetError):
                        break
        finally:
            PASS_SEM.release()

    def log_message(self, fmt, *args):
        print(f"{time.strftime('%H:%M:%S')} {self.client_address[0]} {fmt % args}", flush=True)


if __name__ == "__main__":
    if not TOKEN:
        raise SystemExit("KAIMA_RELAY_TOKEN を設定してください")
    print(f"kaima-vision-relay :{PORT} → {UPSTREAM} ({MODEL})", flush=True)
    http.server.ThreadingHTTPServer(("0.0.0.0", PORT), H).serve_forever()
