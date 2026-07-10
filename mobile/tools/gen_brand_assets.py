#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AI 伴读 品牌资产生成器（零依赖，仅用标准库 zlib/struct/math）。

设计调性：温润、书卷气、学术感 —— 深咖底 + 金色"翻开的书"。
与电脑端阅读器（resources/js/reader.js）的纸张/金气质一致。

产出：
  assets/icon.png   1024x1024  应用图标（全幅方图，系统会自动做圆角遮罩）
  assets/splash.png 1080x1920  开机画面（expo-splash-screen 用）

如需重新生成，改下面的调色板 / 几何参数后直接 `python gen_brand_assets.py`。
"""
import zlib
import struct
import math
import os

# ── 调色板（与 Web 阅读器一致）──────────────────────────────────────
ESPRESSO_TOP = (38, 31, 24)    # 261F18 深咖（上）
ESPRESSO_BOT = (22, 17, 12)    # 16110C 深咖（下）
GOLD         = (226, 168, 58)  # E2A83A 金（书页）
GOLD_DEEP    = (150, 104, 32)  # 966820 深金（书脊 / 文字行）

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, "..", "assets")

# ── PNG 编码 ────────────────────────────────────────────────────────
def write_png(path, w, h, pixels):
    raw = bytearray()
    stride = w * 4
    for y in range(h):
        raw.append(0)  # filter type 0 (None)
        raw.extend(pixels[y * stride:(y + 1) * stride])
    comp = zlib.compress(bytes(raw), 9)

    def chunk(typ, data):
        return (struct.pack('>I', len(data)) + typ + data +
                struct.pack('>I', zlib.crc32(typ + data) & 0xffffffff))

    png = b'\x89PNG\r\n\x1a\n'
    png += chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0))
    png += chunk(b'IDAT', comp)
    png += chunk(b'IEND', b'')
    with open(path, 'wb') as f:
        f.write(png)


def new_buf(w, h, color):
    r, g, b = color
    return bytearray([r, g, b, 255]) * (w * h)


def set_px(pix, w, x, y, color):
    if x < 0 or y < 0 or x >= w or y >= len(pix) // (w * 4):
        return
    i = (y * w + x) * 4
    pix[i] = color[0]
    pix[i + 1] = color[1]
    pix[i + 2] = color[2]
    pix[i + 3] = 255


def fill_rect(pix, w, h, x0, y0, x1, y1, color):
    for y in range(int(y0), int(y1) + 1):
        for x in range(int(x0), int(x1) + 1):
            set_px(pix, w, x, y, color)


def fill_poly(pix, w, h, pts, color):
    xs = [p[0] for p in pts]
    ys = [p[1] for p in pts]
    minx = max(0, int(min(xs)))
    maxx = min(w - 1, int(max(xs)) + 1)
    miny = max(0, int(min(ys)))
    maxy = min(h - 1, int(max(ys)) + 1)
    n = len(pts)
    for y in range(miny, maxy + 1):
        xint = []
        for i in range(n):
            x1, y1 = pts[i]
            x2, y2 = pts[(i + 1) % n]
            if (y1 <= y < y2) or (y2 <= y < y1):
                xint.append(x1 + (y - y1) / (y2 - y1) * (x2 - x1))
        xint.sort()
        for k in range(0, len(xint) - 1, 2):
            xa = int(math.ceil(xint[k] - 0.5))
            xb = int(math.floor(xint[k + 1] - 0.5))
            for x in range(max(minx, xa), min(maxx + 1, xb + 1)):
                set_px(pix, w, x, y, color)


def bg_gradient(pix, w, h, top, bot):
    for y in range(h):
        t = y / (h - 1)
        r = int(top[0] + (bot[0] - top[0]) * t)
        g = int(top[1] + (bot[1] - top[1]) * t)
        b = int(top[2] + (bot[2] - top[2]) * t)
        fill_rect(pix, w, h, 0, y, w - 1, y, (r, g, b))


# ── 书的几何（以 1024 画布的中心 512,512 为基准）─────────────────────
def draw_book(pix, w, h, cx, cy, scale):
    ctr = (512, 512)

    def tf(p):
        return ((p[0] - ctr[0]) * scale + cx, (p[1] - ctr[1]) * scale + cy)

    left = [tf(p) for p in [(512, 372), (300, 344), (300, 684), (512, 712)]]
    right = [tf(p) for p in [(512, 372), (724, 344), (724, 684), (512, 712)]]
    fill_poly(pix, w, h, left, GOLD)
    fill_poly(pix, w, h, right, GOLD)

    spine = [tf(p) for p in [(498, 372), (526, 372), (526, 712), (498, 712)]]
    fill_poly(pix, w, h, spine, GOLD_DEEP)

    for ly in [430, 486, 542, 598]:
        lL = [tf(p) for p in [(320, ly - 4.5), (496, ly - 4.5),
                              (496, ly + 4.5), (320, ly + 4.5)]]
        lR = [tf(p) for p in [(528, ly - 4.5), (704, ly - 4.5),
                              (704, ly + 4.5), (528, ly + 4.5)]]
        fill_poly(pix, w, h, lL, GOLD_DEEP)
        fill_poly(pix, w, h, lR, GOLD_DEEP)


# 5x7 点阵字（仅 A / I，用于 splash 上的 "AI" 字标）
FONT = {
    'A': ["01110", "10001", "10001", "11111", "10001", "10001", "10001"],
    'I': ["11111", "00100", "00100", "00100", "00100", "00100", "11111"],
}


def stamp(pix, w, h, ch, x0, y0, scale, color):
    for r, row in enumerate(FONT[ch]):
        for c, bit in enumerate(row):
            if bit == '1':
                fill_rect(pix, w, h, x0 + c * scale, y0 + r * scale,
                          x0 + c * scale + scale - 1, y0 + r * scale + scale - 1,
                          color)


def main():
    os.makedirs(ASSETS, exist_ok=True)

    # ── 图标 1024x1024 ──────────────────────────────────────────────
    W = H = 1024
    pix = new_buf(W, H, ESPRESSO_TOP)
    bg_gradient(pix, W, H, ESPRESSO_TOP, ESPRESSO_BOT)
    draw_book(pix, W, H, 512, 512, 1.0)
    fill_rect(pix, W, H, 372, 742, 652, 748, GOLD)  # 书下的"书架"短线
    write_png(os.path.join(ASSETS, "icon.png"), W, H, pix)
    print("wrote icon.png", W, "x", H)

    # ── 开机画面 1080x1920 ─────────────────────────────────────────
    W, H = 1080, 1920
    pix = new_buf(W, H, ESPRESSO_TOP)
    bg_gradient(pix, W, H, ESPRESSO_TOP, ESPRESSO_BOT)
    draw_book(pix, W, H, 540, 800, 1.5)
    fill_rect(pix, W, H, 455, 1180, 625, 1186, GOLD)  # 书标下方的细分隔线
    stamp(pix, W, H, 'A', 415, 1230, 22, GOLD)
    stamp(pix, W, H, 'I', 555, 1230, 22, GOLD)
    write_png(os.path.join(ASSETS, "splash.png"), W, H, pix)
    print("wrote splash.png", W, "x", H)


if __name__ == "__main__":
    main()
