#!/usr/bin/env python3
# kappstore用の商品画像。実画面(承認画面=原本×AI読取)を主役にする。
import sys
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 675
SHOT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/kaima_shot.png"
OUT = "outputs/kaima_product.png"
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"

img = Image.new("RGB", (W, H), "#f6f2f8")
d = ImageDraw.Draw(img)
for x in range(W):
    t = x / W
    r = int(0x7a + (0x9a - 0x7a) * t); g = int(0x4a + (0x5a - 0x4a) * t); b = int(0x8c + (0x6c - 0x8c) * t)
    d.line([(x, 0), (x, 9)], fill=(r, g, b))

f_t = ImageFont.truetype(BLACK, 50)
f_t2 = ImageFont.truetype(BLACK, 30)
f_s = ImageFont.truetype(BOLD, 24)
f_b = ImageFont.truetype(BOLD, 21)
f_n = ImageFont.truetype(BOLD, 17)

shot = Image.open(SHOT).convert("RGB").crop((60, 60, 1040, 840))
sw = 560
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS)
fx, fy = W - sw - 34, 120
d.rounded_rectangle([fx - 10, fy - 10, fx + sw + 10, fy + sh + 10], radius=18, fill="#241a2c")
img.paste(shot, (fx, fy))
d.text((fx + 4, fy + sh + 20), "実画面: 原本と見比べて1タップ承認(DNS検証の警告つき)", font=f_n, fill="#6a5c76")

lx = 44
d.text((lx, 46), "AI名刺解析・名刺台帳", font=f_t, fill="#241a2c")
d.text((lx, 108), "Kurage AI Meishi Analysis", font=f_t2, fill="#7a4a8c")
d.text((lx, 162), "撮って投げるだけ。\n読み取りはAI、確定はあなた。", font=f_s, fill="#3a2f44")

feats = [
    "スマホ撮影→AIが12項目を自動読取",
    "AIは下書きまで。台帳反映は人の承認",
    "メールDNS実在・電話・郵便番号を検証",
    "名寄せ・役職の変遷履歴・接点記録",
    "「この会社、誰が知ってる?」に答える",
    "PHP+SQLite。レンタルサーバーで動く",
]
y = 246
for f in feats:
    d.ellipse([lx, y + 7, lx + 12, y + 19], fill="#7a4a8c")
    d.text((lx + 24, y), f, font=f_b, fill="#3f3449")
    y += 44

d.rounded_rectangle([lx, y + 16, lx + 430, y + 74], radius=12, fill="#7a4a8c")
d.text((lx + 22, y + 30), "買い切り 55,000円(税込) / 月額なし", font=f_s, fill="#ffffff")

img.save(OUT)
print("saved:", OUT, img.size)
