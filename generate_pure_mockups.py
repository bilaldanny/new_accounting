"""Generates 45 text-free UI wireframe mockups (1200x630 PNG) of an accounting SaaS into ./pure_mockups, Pillow only.

No text, labels or logos are drawn anywhere: every screen is made of shapes (cards, skeleton bars, charts, tables, inputs).
Reuses the device frames and 3D warp of generate_device_mockups.py.

Run: python generate_pure_mockups.py
"""
import math
import random
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter

import generate_device_mockups as dev
from generate_device_mockups import H, S, W, Z, browser, laptop, monitor, phone, put, put3d, reflect, rounded

OUT = Path(__file__).parent / "pure_mockups"

BG, SIDE, PANEL, PANEL2, LINE = (10, 25, 47), (8, 21, 40), (15, 35, 64), (19, 43, 77), (31, 58, 96)
SK, SK2, WHITEISH = (49, 77, 116), (92, 120, 160), (205, 216, 232)
LIGHT, LIGHT2, SKD, SKD2 = (234, 239, 247), (216, 225, 239), (176, 189, 210), (128, 145, 175)
CYAN, BLUE, GREEN, RED, AMBER, VIOLET = (0, 242, 254), (59, 130, 246), (52, 211, 153), (248, 113, 113), (251, 191, 36), (167, 139, 250)
ACC = [CYAN, BLUE, GREEN, AMBER, VIOLET, RED]


def mix(a, b, t):
    return tuple(int(x * t + y * (1 - t)) for x, y in zip(a, b))


class Wire:
    """A drawing surface in design units; k scales it to pixels."""

    def __init__(self, w, h, k=1.0, bg=BG):
        self.w, self.h, self.k = w, h, k
        self.im = Image.new("RGB", (int(w * k), int(h * k)), bg)
        self.d = ImageDraw.Draw(self.im)

    def _b(self, x, y, w, h):
        return [x * self.k, y * self.k, (x + w) * self.k - 1, (y + h) * self.k - 1]

    def rr(self, x, y, w, h, r=8, fill=None, out=None, ow=1):
        self.d.rounded_rectangle(self._b(x, y, w, h), radius=r * self.k, fill=fill, outline=out, width=max(1, int(ow * self.k)) if out else 0)

    def pill(self, x, y, w, h, fill=None, out=None):
        self.rr(x, y, w, h, h / 2, fill, out)

    def circ(self, cx, cy, r, fill=None, out=None):
        self.d.ellipse([(cx - r) * self.k, (cy - r) * self.k, (cx + r) * self.k, (cy + r) * self.k], fill=fill, outline=out, width=max(1, int(self.k)) if out else 0)

    def line(self, pts, col, w=1.0):
        self.d.line([(x * self.k, y * self.k) for x, y in pts], fill=col, width=max(1, int(w * self.k)), joint="curve")

    def poly(self, pts, fill):
        self.d.polygon([(x * self.k, y * self.k) for x, y in pts], fill=fill)

    def skel(self, x, y, w, h=8, col=SK):
        self.pill(x, y, max(h, w), h, col)

    def arc(self, cx, cy, r, a0, a1, col, width):
        self.d.arc([(cx - r) * self.k, (cy - r) * self.k, (cx + r) * self.k, (cy + r) * self.k], a0, a1, fill=col, width=int(width * self.k))

    def grad(self, x, y, w, h, c1=CYAN, c2=BLUE, r=0, vertical=False):
        gw, gh = max(1, int(w * self.k)), max(1, int(h * self.k))
        n = gh if vertical else gw
        strip = Image.new("RGB", (1, n) if vertical else (n, 1))
        for i in range(n):
            strip.putpixel((0, i) if vertical else (i, 0), mix(c2, c1, i / max(1, n - 1)))
        g = strip.resize((gw, gh))
        m = Image.new("L", (gw, gh), 0)
        ImageDraw.Draw(m).rounded_rectangle([0, 0, gw - 1, gh - 1], radius=r * self.k, fill=255)
        self.im.paste(g, (int(x * self.k), int(y * self.k)), m)

    def area(self, pts, base, col, strength=.45):
        k = self.k
        for px in range(int(pts[0][0] * k), int(pts[-1][0] * k)):
            xx = px / k
            for (x0, y0), (x1, y1) in zip(pts, pts[1:]):
                if x0 <= xx <= x1:
                    yy = y0 + (y1 - y0) * (xx - x0) / max(1e-6, x1 - x0)
                    top = yy * k
                    bot = base * k
                    steps = max(1, int((bot - top) / 3))
                    for s in range(steps):
                        t = s / steps
                        y = top + (bot - top) * t
                        c = mix(col, PANEL, strength * (1 - t) ** 1.5)
                        self.d.line([(px, y), (px, y + 3)], fill=c)
                    break


def smooth(rng, n, lo, hi, trend=.0):
    v, out = rng.uniform(.3, .6), []
    for i in range(n):
        v += rng.uniform(-.12, .12) + trend
        v = min(.95, max(.08, v))
        out.append(lo + (hi - lo) * v)
    return out


# ---------- blocks: fn(wr, x, y, w, h, rng) ----------
def panel(wr, x, y, w, h, light=False):
    wr.rr(x, y, w, h, 14, fill=LIGHT if light else PANEL, out=LIGHT2 if light else LINE)


def head(wr, x, y, w, rng, light=False, legend=False):
    wr.skel(x + 18, y + 18, rng.randint(70, 130), 10, SKD2 if light else SK2)
    if legend:
        for i, c in enumerate([CYAN, BLUE]):
            wr.circ(x + w - 90 + i * 44, y + 23, 4, c)
            wr.skel(x + w - 82 + i * 44, y + 20, 24, 6, SKD if light else SK)
    else:
        for i in range(3):
            wr.circ(x + w - 24 - i * 12, y + 23, 2.5, SKD if light else SK2)


def b_kpi(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    wr.skel(x + 18, y + 18, rng.randint(50, 80), 8, SK)
    wr.skel(x + 18, y + h * .42, w * rng.uniform(.4, .55), 18, WHITEISH)
    up = rng.random() > .25
    col = GREEN if up else RED
    wr.pill(x + 18, y + h - 30, 54, 16, mix(col, PANEL, .2))
    wr.poly([(x + 28, y + h - 25), (x + 34, y + h - 25), (x + 31, y + h - 20)] if not up else [(x + 28, y + h - 20), (x + 34, y + h - 20), (x + 31, y + h - 25)], col)
    wr.skel(x + 40, y + h - 25, 22, 6, mix(col, PANEL, .6))
    pts = [(x + w - 90 + i * 8, y + h - 24 - v * 28) for i, v in enumerate([rng.uniform(.1, 1) for _ in range(10)])]
    wr.line(pts, rng.choice([CYAN, BLUE]), 2)


def b_area(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng, legend=True)
    gx, gy, gw, gh = x + 52, y + 50, w - 74, h - 84
    for i in range(5):
        yy = gy + gh * i / 4
        wr.line([(gx, yy), (gx + gw, yy)], LINE, 1)
        wr.skel(x + 18, yy - 3, 22, 6, SK)
    n = 12
    for i in range(n):
        wr.skel(gx + gw * i / (n - 1) - 8, y + h - 24, 16, 6, SK)
    for si, (col, lo, hi) in enumerate([(CYAN, .35, .95), (BLUE, .1, .6)]):
        ys = smooth(rng, n, lo, hi, .02 if si == 0 else .0)
        pts = [(gx + gw * i / (n - 1), gy + gh * (1 - ys[i])) for i in range(n)]
        if si == 0:
            wr.area(pts, gy + gh, col)
        wr.line(pts, col, 2.6)
        wr.circ(*pts[-1], 5, col, None)
        wr.circ(*pts[-1], 2.5, PANEL)


def b_bars(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng, legend=True)
    gx, gy, gw, gh = x + 52, y + 50, w - 74, h - 84
    for i in range(5):
        wr.line([(gx, gy + gh * i / 4), (gx + gw, gy + gh * i / 4)], LINE, 1)
        wr.skel(x + 18, gy + gh * i / 4 - 3, 22, 6, SK)
    n = rng.randint(8, 12)
    slot = gw / n
    bw = min(16, slot * .32)
    for i in range(n):
        cx = gx + slot * (i + .5)
        for j, col in enumerate([CYAN, BLUE]):
            v = rng.uniform(.2, 1) * (1 if j == 0 else .7)
            wr.rr(cx - bw + j * bw, gy + gh * (1 - v), bw - 2, gh * v, 3, col)
        wr.skel(cx - 8, y + h - 24, 16, 6, SK)


def b_donut(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    d = min(h - 70, w * .5)
    cx, cy = x + 24 + d / 2, y + 44 + (h - 50) / 2
    parts = [rng.uniform(.5, 1.5) for _ in range(4)]
    tot, a = sum(parts), -90
    for p, col in zip(parts, [CYAN, BLUE, VIOLET, GREEN]):
        sw = 360 * p / tot
        wr.arc(cx, cy, d / 2 - 9, a + 1.5, a + sw - 1.5, col, 18)
        a += sw
    wr.skel(cx - 22, cy - 7, 44, 10, WHITEISH)
    wr.skel(cx - 14, cy + 9, 28, 6, SK)
    lx = cx + d / 2 + 26
    for i, col in enumerate([CYAN, BLUE, VIOLET, GREEN]):
        yy = cy - 48 + i * 32
        wr.circ(lx, yy + 4, 5, col)
        wr.skel(lx + 14, yy, min(80, w - (lx - x) - 90), 8, SK2)
        wr.skel(x + w - 50, yy, 32, 8, SK)


def b_table(wr, x, y, w, h, rng, light=False):
    panel(wr, x, y, w, h, light)
    head(wr, x, y, w, rng, light)
    c = rng.randint(4, 6) if w > 420 else 3
    colx = [x + 20]
    firstw = (w - 40) * (.3 if c > 3 else .45)
    rest = (w - 40 - firstw) / (c - 1)
    colx += [x + 20 + firstw + rest * i for i in range(c - 1)]
    wr.rr(x + 10, y + 46, w - 20, 26, 8, fill=LIGHT2 if light else PANEL2)
    for i, cx in enumerate(colx):
        wr.skel(cx, y + 56, rng.randint(28, 50), 6, SKD2 if light else SK2)
    rowh = 42
    n = int((h - 90) // rowh)
    for r in range(n):
        yy = y + 80 + r * rowh
        wr.line([(x + 14, yy - 4), (x + w - 14, yy - 4)], LIGHT2 if light else LINE, 1)
        wr.circ(colx[0] + 11, yy + 16, 11, mix(rng.choice(ACC), LIGHT if light else PANEL, .5))
        wr.skel(colx[0] + 30, yy + 8, rng.randint(60, int(max(70, firstw - 50))), 8, SKD2 if light else SK2)
        wr.skel(colx[0] + 30, yy + 21, rng.randint(30, 60), 6, SKD if light else SK)
        for i in range(1, c):
            if i == c - 2 and c > 3:
                col = rng.choice([GREEN, GREEN, AMBER, RED, BLUE])
                wr.pill(colx[i], yy + 8, 62, 18, mix(col, LIGHT if light else PANEL, .22))
                wr.circ(colx[i] + 11, yy + 17, 3.5, col)
                wr.skel(colx[i] + 20, yy + 14, 28, 6, mix(col, LIGHT if light else PANEL, .7))
            elif i == c - 1:
                wr.skel(colx[i] + rest - 20 - 60, yy + 13, rng.randint(40, 60), 8, SKD2 if light else WHITEISH if rng.random() > .5 else SK2)
            else:
                wr.skel(colx[i], yy + 13, rng.randint(36, int(max(40, rest - 30))), 8, SKD if light else SK)


def b_list(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    n = int((h - 56) // 54)
    for i in range(n):
        yy = y + 50 + i * 54
        col = ACC[(i + rng.randint(0, 5)) % 6]
        wr.rr(x + 18, yy + 4, 34, 34, 10, mix(col, PANEL, .22))
        wr.circ(x + 35, yy + 21, 6, col)
        wr.skel(x + 64, yy + 9, rng.randint(70, 130), 8, SK2)
        wr.skel(x + 64, yy + 24, rng.randint(40, 80), 6, SK)
        wr.skel(x + w - 70, yy + 15, 50, 8, WHITEISH if i % 2 == 0 else SK2)


def b_form(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    cols = 2 if w > 420 else 1
    fw = (w - 36 - 16 * (cols - 1)) / cols
    n = int((h - 120) // 66)
    for i in range(max(1, n)):
        for c in range(cols):
            fx, fy = x + 18 + c * (fw + 16), y + 52 + i * 66
            wr.skel(fx, fy, rng.randint(40, 80), 7, SK2)
            wr.rr(fx, fy + 16, fw, 38, 9, fill=BG, out=LINE)
            if rng.random() > .5:
                wr.skel(fx + 14, fy + 31, rng.randint(50, int(fw * .5)), 8, SK)
            if rng.random() > .6:
                wr.poly([(fx + fw - 26, fy + 32), (fx + fw - 14, fy + 32), (fx + fw - 20, fy + 40)], SK2)
    by = y + h - 56
    wr.grad(x + 18, by, 130, 38, CYAN, BLUE, 19)
    wr.skel(x + 54, by + 15, 58, 8, (6, 22, 40))
    wr.pill(x + 160, by, 100, 38, None, LINE)


def b_invoice(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h, True)
    wr.skel(x + 28, y + 28, 130, 14, SKD2)
    wr.skel(x + 28, y + 52, 90, 7, SKD)
    wr.pill(x + w - 110, y + 28, 82, 24, mix(GREEN, LIGHT, .25))
    wr.circ(x + w - 95, y + 40, 4, GREEN)
    for i in range(3):
        fx = x + 28 + i * ((w - 56) / 3)
        wr.skel(fx, y + 86, 50, 6, SKD)
        wr.skel(fx, y + 100, 90, 9, SKD2)
    wr.rr(x + 20, y + 130, w - 40, 28, 8, LIGHT2)
    for i, cx in enumerate([.05, .45, .65, .85]):
        wr.skel(x + 20 + (w - 40) * cx + 10, y + 140, 40, 7, SKD2)
    n = int((h - 300) // 38)
    for r in range(max(2, n)):
        yy = y + 170 + r * 38
        wr.line([(x + 24, yy + 30), (x + w - 24, yy + 30)], LIGHT2, 1)
        wr.skel(x + 30, yy + 8, rng.randint(90, 160), 8, SKD2)
        wr.skel(x + 30, yy + 20, rng.randint(50, 90), 6, SKD)
        for cx in (.5, .66, .86):
            wr.skel(x + 20 + (w - 40) * cx + 10, yy + 11, rng.randint(30, 50), 8, SKD)
    by = y + h - 108
    wr.rr(x + w - 230, by, 200, 88, 12, LIGHT2)
    for i in range(2):
        wr.skel(x + w - 214, by + 14 + i * 20, 70, 7, SKD)
        wr.skel(x + w - 100, by + 14 + i * 20, 56, 7, SKD2)
    wr.grad(x + w - 214, by + 56, 168, 22, CYAN, BLUE, 11)


def b_kanban(wr, x, y, w, h, rng):
    n = 4 if w > 600 else 2
    cw = (w - 14 * (n - 1)) / n
    for i in range(n):
        cx = x + i * (cw + 14)
        wr.rr(cx, y, cw, h, 14, fill=(12, 30, 56), out=LINE)
        wr.circ(cx + 24, y + 26, 6, ACC[i % 6])
        wr.skel(cx + 38, y + 22, 70, 8, SK2)
        wr.pill(cx + cw - 46, y + 18, 30, 16, PANEL2)
        m = int((h - 60) // 112)
        for j in range(max(1, m)):
            cy = y + 52 + j * 112
            wr.rr(cx + 12, cy, cw - 24, 100, 11, fill=PANEL, out=LINE)
            wr.skel(cx + 26, cy + 14, rng.randint(70, int(cw - 80)), 8, SK2)
            wr.skel(cx + 26, cy + 30, rng.randint(40, int(cw - 100)), 6, SK)
            wr.pill(cx + 26, cy + 50, 52, 16, mix(rng.choice(ACC), PANEL, .25))
            wr.circ(cx + 36, cy + 82, 9, SK2)
            wr.circ(cx + 52, cy + 82, 9, mix(rng.choice(ACC), PANEL, .6))
            wr.skel(cx + cw - 80, cy + 79, 44, 6, SK)


def b_heat(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    cols, rows = 14, max(3, int((h - 70) // 34))
    cw, ch = (w - 70) / cols, (h - 70) / rows
    for r in range(rows):
        wr.skel(x + 18, y + 54 + r * ch + ch / 2 - 3, 24, 6, SK)
        for c in range(cols):
            v = rng.random() ** 1.4
            col = mix(CYAN if (r + c) % 3 else BLUE, PANEL2, v * .9 + .05)
            wr.rr(x + 52 + c * cw, y + 54 + r * ch, cw - 4, ch - 4, 5, col)


def b_products(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    cols = 4 if w > 560 else 2
    rows = max(1, int((h - 60) // 190))
    cw = (w - 36 - 14 * (cols - 1)) / cols
    for r in range(rows):
        for c in range(cols):
            cx, cy = x + 18 + c * (cw + 14), y + 52 + r * 190
            wr.rr(cx, cy, cw, 176, 12, PANEL2, LINE)
            a, b = rng.sample(ACC, 2)
            wr.grad(cx + 8, cy + 8, cw - 16, 96, mix(a, PANEL, .4), mix(b, PANEL, .35), 9)
            wr.skel(cx + 12, cy + 116, rng.randint(50, int(cw - 40)), 8, SK2)
            wr.skel(cx + 12, cy + 131, rng.randint(30, 60), 6, SK)
            wr.pill(cx + 12, cy + 148, 56, 18, mix(CYAN, PANEL2, .3))
            wr.circ(cx + cw - 22, cy + 157, 9, BLUE)


def b_statement(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    n = int((h - 70) // 32)
    for i in range(n):
        yy = y + 52 + i * 32
        section, total = i % 6 == 0, i % 6 == 5
        if section:
            wr.rr(x + 12, yy - 3, w - 24, 26, 7, PANEL2)
        if total:
            wr.line([(x + 18, yy - 4), (x + w - 18, yy - 4)], SK2, 1)
            wr.rr(x + 12, yy - 2, w - 24, 26, 7, mix(CYAN, PANEL, .1))
        ind = 0 if section or total else rng.choice([22, 22, 44])
        wr.skel(x + 22 + ind, yy + 6, rng.randint(80, 190) if not section else 110, 9 if section else 7, SK2 if section or total else SK)
        wr.skel(x + w - 34 - rng.randint(60, 100), yy + 6, 80, 8 if not total else 10, CYAN if total else WHITEISH if section else SK2)


def b_progress(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    n = max(2, int((h - 60) // 52))
    for i in range(n):
        yy = y + 54 + i * 52
        wr.skel(x + 20, yy, rng.randint(70, 120), 8, SK2)
        wr.skel(x + w - 70, yy, 50, 8, SK)
        wr.pill(x + 20, yy + 20, w - 40, 10, PANEL2)
        wr.grad(x + 20, yy + 20, (w - 40) * rng.uniform(.25, .95), 10, ACC[i % 4], BLUE, 5)


def b_cart(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    n = max(2, int((h - 220) // 60))
    for i in range(n):
        yy = y + 50 + i * 60
        wr.grad(x + 18, yy, 44, 44, mix(rng.choice(ACC), PANEL, .4), mix(BLUE, PANEL, .3), 10)
        wr.skel(x + 74, yy + 8, rng.randint(70, 110), 8, SK2)
        wr.skel(x + 74, yy + 24, rng.randint(40, 70), 6, SK)
        wr.pill(x + w - 80, yy + 12, 62, 22, PANEL2, LINE)
    by = y + h - 160
    for i in range(3):
        wr.skel(x + 20, by + i * 24, 60, 7, SK)
        wr.skel(x + w - 90, by + i * 24, 70, 7, SK2 if i < 2 else WHITEISH)
    wr.line([(x + 18, by - 10), (x + w - 18, by - 10)], LINE, 1)
    wr.grad(x + 18, y + h - 64, w - 36, 46, CYAN, BLUE, 12)
    wr.skel(x + w / 2 - 36, y + h - 46, 72, 10, (6, 22, 40))


def b_recon(wr, x, y, w, h, rng):
    cw = (w - 120) / 2
    for side in range(2):
        cx = x + side * (cw + 120)
        panel(wr, cx, y, cw, h)
        head(wr, cx, y, cw, rng)
        n = int((h - 60) // 62)
        for i in range(n):
            yy = y + 52 + i * 62
            ok = i % 3 != 2
            wr.rr(cx + 14, yy, cw - 28, 52, 10, PANEL2, GREEN if ok else LINE)
            wr.circ(cx + 38, yy + 26, 12, mix(ACC[i % 5], PANEL2, .5))
            wr.skel(cx + 60, yy + 14, rng.randint(70, 120), 8, SK2)
            wr.skel(cx + 60, yy + 30, rng.randint(40, 70), 6, SK)
            wr.skel(cx + cw - 90, yy + 20, 60, 8, WHITEISH)
            if side == 0 and ok:
                wr.line([(cx + cw - 14, yy + 26), (cx + cw + 106, yy + 26)], mix(GREEN, PANEL, .6), 2)
    for i in range(int((h - 60) // 62)):
        if i % 3 != 2:
            wr.circ(x + cw + 60, y + 78 + i * 62, 12, mix(GREEN, PANEL, .35), GREEN)
            wr.line([(x + cw + 54, y + 78 + i * 62), (x + cw + 58, y + 82 + i * 62), (x + cw + 67, y + 73 + i * 62)], GREEN, 2)


def b_calendar(wr, x, y, w, h, rng):
    panel(wr, x, y, w, h)
    head(wr, x, y, w, rng)
    cw, ch = (w - 36) / 7, (h - 96) / 5
    for c in range(7):
        wr.skel(x + 18 + c * cw + cw / 2 - 14, y + 52, 28, 6, SK2)
    for r in range(5):
        for c in range(7):
            cx, cy = x + 18 + c * cw, y + 72 + r * ch
            wr.rr(cx + 2, cy + 2, cw - 4, ch - 4, 8, None, LINE)
            wr.skel(cx + 10, cy + 10, 14, 6, SK)
            if rng.random() > .65:
                col = rng.choice(ACC)
                wr.pill(cx + 8, cy + ch - 28, cw - 24, 14, mix(col, PANEL, .3))
            if r == 2 and c == 3:
                wr.circ(cx + cw - 18, cy + 16, 8, CYAN)


def b_tabs(wr, x, y, w, h, rng):
    for i in range(5):
        if i == 0:
            wr.grad(x + i * 112, y + h / 2 - 18, 100, 36, CYAN, BLUE, 18)
            wr.skel(x + 24, y + h / 2 - 4, 52, 8, (6, 22, 40))
        else:
            wr.pill(x + i * 112, y + h / 2 - 18, 100, 36, PANEL, LINE)
            wr.skel(x + i * 112 + 24, y + h / 2 - 4, 52, 8, SK2)
    wr.pill(x + w - 260, y + h / 2 - 18, 260, 36, PANEL, LINE)
    wr.circ(x + w - 240, y + h / 2, 7, SK2)
    wr.skel(x + w - 222, y + h / 2 - 4, 90, 8, SK)


def b_hero(wr, x, y, w, h, rng):
    wr.grad(x, y, w, h, (14, 120, 170), (40, 70, 190), 18)
    wr.skel(x + 20, y + 22, 70, 8, mix(WHITEISH, BLUE, .6))
    wr.skel(x + 20, y + 44, w * .5, 20, WHITEISH)
    ys = smooth(rng, 10, .15, .85)
    wr.line([(x + 20 + (w - 40) * i / 9, y + h - 20 - (h * .4) * ys[i]) for i in range(10)], WHITEISH, 2.4)
    wr.pill(x + w - 90, y + 22, 70, 22, mix(WHITEISH, BLUE, .35))


def b_actions(wr, x, y, w, h, rng):
    for i in range(4):
        cx = x + w * (i + .5) / 4
        wr.circ(cx, y + 26, 24, mix(ACC[i], PANEL, .22))
        wr.circ(cx, y + 26, 8, ACC[i])
        wr.skel(cx - 18, y + 64, 36, 6, SK2)


def b_seg(wr, x, y, w, h, rng):
    wr.pill(x, y + 4, w, 40, PANEL, LINE)
    wr.grad(x + 4, y + 8, w / 3 - 4, 32, CYAN, BLUE, 16)
    for i in range(1, 3):
        wr.skel(x + w * i / 3 + w / 6 - 22, y + 21, 44, 7, SK2)
    wr.skel(x + w / 6 - 22, y + 21, 44, 7, (6, 22, 40))


Kx = lambda n: [(1, b_kpi)] * n


# ---------- shells ----------
def desktop_shell(wr, rng, collapsed=False):
    sw = 86 if collapsed else 244
    wr.rr(0, 0, sw, wr.h, 0, SIDE)
    wr.line([(sw, 0), (sw, wr.h)], LINE, 1)
    wr.rr(16, 18, sw - 32, 44, 12, PANEL, LINE)
    wr.grad(26, 28, 24, 24, CYAN, BLUE, 8)
    if not collapsed:
        wr.skel(60, 33, 90, 8, SK2)
        wr.skel(60, 46, 50, 6, SK)
    active = rng.randint(0, 5)
    for i in range(9):
        yy = 92 + i * 46
        if i == active:
            wr.grad(12, yy - 6, sw - 24, 40, (18, 62, 96), (14, 38, 72), 11)
            wr.rr(12, yy - 6, 4, 40, 2, CYAN)
        wr.rr(28, yy + 4, 20, 20, 6, mix(CYAN if i == active else SK2, SIDE, .85 if i != active else .5), None)
        wr.circ(38, yy + 14, 4, CYAN if i == active else SK2)
        if not collapsed:
            wr.skel(62, yy + 10, rng.randint(70, 120), 8, WHITEISH if i == active else SK)
    wr.circ(40, wr.h - 40, 16, SK2)
    if not collapsed:
        wr.skel(66, wr.h - 46, 80, 8, SK2)
        wr.skel(66, wr.h - 32, 50, 6, SK)
    wr.line([(sw, 72), (wr.w, 72)], LINE, 1)
    wr.pill(sw + 28, 16, 340, 40, PANEL, LINE)
    wr.circ(sw + 52, 36, 7, SK2)
    wr.skel(sw + 68, 32, 110, 8, SK)
    wr.circ(wr.w - 270, 36, 18, None, LINE)
    wr.circ(wr.w - 270, 36, 5, SK2)
    wr.circ(wr.w - 222, 36, 18, None, LINE)
    wr.circ(wr.w - 222, 30, 3, RED)
    wr.circ(wr.w - 174, 36, 18, SK2)
    wr.grad(wr.w - 140, 16, 112, 40, CYAN, BLUE, 20)
    wr.skel(wr.w - 118, 32, 68, 8, (6, 22, 40))
    return (sw + 28, 94, wr.w - sw - 56, wr.h - 94 - 26)


def layout(wr, rect, rows, rng, gap=16):
    x, y, w, h = rect
    px = sum(r[0] for r in rows if r[0] > 1)
    fr = sum(r[0] for r in rows if r[0] <= 1) or 1
    avail = h - gap * (len(rows) - 1) - px
    cy = y
    for rh, items in rows:
        hh = rh if rh > 1 else avail * rh / fr
        tw = sum(i[0] for i in items)
        iw = w - gap * (len(items) - 1)
        cx = x
        for wt, blk in items:
            bw = iw * wt / tw
            if callable(blk):
                blk(wr, cx, cy, bw, hh, rng)
            else:
                layout(wr, (cx, cy, bw, hh), blk[1], rng, gap)
            cx += bw + gap
        cy += hh + gap


V = lambda rows: ("v", [(r[0], [(1, r[1])]) for r in rows])
DESK = [
    [(.2, Kx(4)), (.45, [(2, b_area), (1, b_donut)]), (.35, [(2, b_table), (1, b_list)])],
    [(.2, Kx(3)), (.8, [(1, b_table)])],
    [(1, [(1, b_form), (1.25, b_invoice)])],
    [(.2, Kx(4)), (.8, [(2, b_table), (1, b_bars)])],
    [(1, [(2, b_statement), (1, V([(.5, b_donut), (.5, b_progress)]))])],
    [(.1, [(1, b_tabs)]), (.9, [(1, b_kanban)])],
    [(.5, [(2, b_area), (1, b_bars)]), (.5, [(2, b_heat), (1, b_donut)])],
    [(.18, Kx(3)), (.82, [(1, b_recon)])],
    [(1, [(2, b_calendar), (1, V([(.5, b_list), (.5, b_progress)]))])],
    [(1, [(2, b_products), (1, b_cart)])],
    [(1, [(1, V([(.5, b_form), (.5, b_form)])), (1, V([(.4, b_progress), (.6, b_list)]))])],
    [(.45, [(1, b_bars), (1, b_area)]), (.55, [(1, b_table), (1, b_donut)])],
    [(.2, Kx(4)), (.4, [(2, b_area), (1, b_donut)]), (.4, [(1, b_table)])],
    [(.26, Kx(3)), (.74, [(2, b_heat), (1, V([(.5, b_progress), (.5, b_bars)]))])],
    [(.2, Kx(4)), (.8, [(3, b_table), (1.2, V([(.5, b_donut), (.5, b_list)]))])],
]


def desk_screen(i, w=1600, h=840, k=1.0, collapsed=None, seed=None):
    rng = random.Random(seed if seed is not None else 100 + i)
    wr = Wire(w, h, k)
    rect = desktop_shell(wr, rng, i % 3 == 1 if collapsed is None else collapsed)
    layout(wr, rect, DESK[i % len(DESK)], rng)
    return wr.im


def mobile_shell(wr, rng):
    wr.circ(36, 90, 18, SK2)
    wr.skel(64, 78, 110, 10, WHITEISH)
    wr.skel(64, 96, 70, 7, SK)
    wr.circ(342, 90, 18, None, LINE)
    wr.circ(342, 90, 5, SK2)
    wr.circ(350, 80, 4, RED)
    h = wr.h
    wr.rr(0, h - 92, wr.w, 92, 0, PANEL)
    wr.line([(0, h - 92), (wr.w, h - 92)], LINE, 1)
    act = rng.randint(0, 4)
    for i in range(5):
        cx = wr.w * (i + .5) / 5
        if i == act:
            wr.grad(cx - 26, h - 80, 52, 36, CYAN, BLUE, 18)
            wr.circ(cx, h - 62, 6, (6, 22, 40))
        else:
            wr.circ(cx, h - 62, 7, SK2)
        wr.skel(cx - 12, h - 38, 24, 5, SK if i != act else CYAN)
    wr.pill(wr.w / 2 - 64, h - 14, 128, 5, SK2)
    return (16, 124, wr.w - 32, h - 124 - 108)


MOB = [
    [(150, [(1, b_hero)]), (86, [(1, b_actions)]), (1, [(1, b_list)])],
    [(96, Kx(2)), (.45, [(1, b_area)]), (.55, [(1, b_list)])],
    [(48, [(1, b_seg)]), (.5, [(1, b_donut)]), (.5, [(1, b_progress)])],
    [(1, [(1, b_form)])],
    [(.7, [(1, b_products)]), (.3, [(1, b_cart)])],
    [(.55, [(1, b_statement)]), (.45, [(1, b_bars)])],
    [(.4, [(1, b_list)]), (.6, [(1, b_heat)])],
    [(.8, [(1, b_invoice)]), (.2, [(1, b_progress)])],
    [(96, Kx(2)), (96, Kx(2)), (.6, [(1, b_bars)])],
    [(.6, [(1, b_calendar)]), (.4, [(1, b_list)])],
]


def mob_screen(i, k=2.0, seed=None):
    rng = random.Random(seed if seed is not None else 500 + i)
    wr = Wire(390, 844, k)
    rect = mobile_shell(wr, rng)
    layout(wr, rect, MOB[i % len(MOB)], rng, gap=12)
    return wr.im


def tab_screen(i, k=1.0):
    return desk_screen(i + 3, 1200, 900, k, collapsed=True, seed=900 + i)


def widget(kind, w, h, seed, k=2.0):
    rng = random.Random(seed)
    wr = Wire(w, h, k)
    kind(wr, 0, 0, w, h, rng)
    return rounded(wr.im.convert("RGBA"), int(14 * k))


def tablet(screen, w):
    b = w * .035
    ow, oh = Z(w), Z(w * .75)
    img = Image.new("RGBA", (ow, oh), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([0, 0, ow - 1, oh - 1], radius=Z(w * .05), fill=(14, 18, 27, 255), outline=(70, 84, 110, 255), width=S)
    scr = dev.cover(screen, ow - 2 * Z(b), oh - 2 * Z(b))
    img.alpha_composite(rounded(scr, Z(w * .02)), (Z(b), Z(b)))
    d.ellipse([ow - Z(b / 2) - 3, oh / 2 - 3, ow - Z(b / 2) + 3, oh / 2 + 3], fill=(40, 50, 70, 255))
    return img


# ---------- scenes ----------
def bgscene(seed):
    rng = random.Random(seed)
    c = Image.new("RGBA", (W * S, H * S), BG + (255,))
    for col in (CYAN, BLUE, BLUE):
        x, y, r = rng.randint(50, 1150), rng.randint(40, 600), rng.randint(220, 380)
        g = Image.new("RGBA", c.size, (0, 0, 0, 0))
        ImageDraw.Draw(g).ellipse([Z(x - r), Z(y - r), Z(x + r), Z(y + r)], fill=col + (rng.randint(35, 75),))
        c.alpha_composite(g.filter(ImageFilter.GaussianBlur(Z(110))))
    d = ImageDraw.Draw(c)
    for gx in range(0, W, 40):
        for gy in range(0, H, 40):
            d.ellipse([Z(gx) - 1, Z(gy) - 1, Z(gx) + 1, Z(gy) + 1], fill=(40, 70, 110, 80))
    return c


def save(img, name):
    OUT.mkdir(exist_ok=True)
    img.resize((W, H), Image.LANCZOS).convert("RGB").save(OUT / f"{name}.png", optimize=True)
    print("saved", name)


def D(i):
    return desk_screen(i, k=1.0)


def M(i):
    return mob_screen(i, 2.0)


# ---------- 15 desktop ----------
def make_desktop():
    for i in range(15):
        save(desk_screen(i, k=1.5), f"mockup_desktop_{i + 1:02d}")


# ---------- 10 mobile ----------
def make_mobile():
    def pp(c, idx, x, y, w, seed=None):
        put(c, phone(M(idx).convert("RGB"), w * .88), x, y, w)

    c = bgscene(1); pp(c, 0, 470, 22, 260); save(c, "mockup_mobile_01")
    c = bgscene(2); pp(c, 1, 340, 40, 232); pp(c, 2, 620, 70, 232); save(c, "mockup_mobile_02")
    c = bgscene(3)
    for n, x, y in [(3, 226, 110), (0, 492, 72), (4, 758, 110)]:
        pp(c, n, x, y, 216)
    save(c, "mockup_mobile_03")
    c = bgscene(4)
    for n, x in [(5, 110), (6, 360), (7, 610), (8, 860)]:
        pp(c, n, x, 110, 232)
    save(c, "mockup_mobile_04")
    c = bgscene(5)  # flat screens, no frame
    for i, n in enumerate([0, 1, 4, 2, 9]):
        im = rounded(M(n).convert("RGBA"), Z(18))
        lay, pad = dev.shadowed(im.resize((Z(200), int(im.height * Z(200) / im.width)), Image.LANCZOS))
        c.alpha_composite(lay, (Z(50 + i * 220) - pad, Z(90 + (i % 2) * 40) - pad))
    save(c, "mockup_mobile_05")
    c = bgscene(6); pp(c, 2, 200, 40, 250)
    for j, (kind, x, y, w, h) in enumerate([(b_area, 520, 70, 480, 220), (b_kpi, 600, 320, 230, 120), (b_donut, 850, 300, 280, 230)]):
        lay, pad = dev.shadowed(widget(kind, w, h, 60 + j))
        c.alpha_composite(lay, (Z(x) - pad, Z(y) - pad))
    save(c, "mockup_mobile_06")
    c = bgscene(7); pp(c, 5, 300, 100, 232); pp(c, 6, 668, 100, 232); pp(c, 0, 484, 50, 232); save(c, "mockup_mobile_07")
    c = bgscene(8)
    put(c, tablet(tab_screen(0), 520), 140, 150, 520)
    pp(c, 3, 720, 70, 250)
    save(c, "mockup_mobile_08")
    c = bgscene(9)
    for i in range(5):
        pp(c, i + 3, 60 + i * 215, 150 - (i % 2) * 40, 185)
    save(c, "mockup_mobile_09")
    c = bgscene(10)
    pp(c, 1, 250, 40, 230); pp(c, 8, 720, 70, 230)
    for j, (kind, x, y, w, h) in enumerate([(b_kpi, 470, 150, 200, 110), (b_donut, 465, 330, 250, 200)]):
        lay, pad = dev.shadowed(widget(kind, w, h, 80 + j))
        c.alpha_composite(lay, (Z(x) - pad, Z(y) - pad))
    save(c, "mockup_mobile_10")


# ---------- 10 combined ----------
def make_combined():
    def pp(c, idx, x, y, w):
        put(c, phone(M(idx).convert("RGB"), w * .88), x, y, w)

    c = bgscene(11); put(c, laptop(D(0), 760), 130, 90, 770); pp(c, 0, 800, 250, 190); save(c, "mockup_combo_01")
    c = bgscene(12); put(c, browser(D(3), 820), 90, 110, 820); pp(c, 1, 850, 120, 200); save(c, "mockup_combo_02")
    c = bgscene(13); put(c, monitor(D(6), 720), 130, 50, 720); pp(c, 2, 880, 240, 180); save(c, "mockup_combo_03")
    c = bgscene(14); put(c, laptop(D(1), 660), 90, 90, 670); put(c, tablet(tab_screen(2), 300), 700, 330, 300); pp(c, 3, 960, 190, 170); save(c, "mockup_combo_04")
    c = bgscene(15); put(c, browser(D(9), 720), 70, 90, 720); put(c, tablet(tab_screen(4), 270), 700, 120, 270); pp(c, 4, 940, 260, 190); save(c, "mockup_combo_05")
    c = bgscene(16); put(c, browser(D(4), 600), 130, 50, 600); put(c, browser(D(12), 640), 250, 190, 640); pp(c, 5, 910, 290, 180); save(c, "mockup_combo_06")
    c = bgscene(17); put(c, laptop(D(14), 700), 250, 80, 710); pp(c, 6, 100, 270, 170); pp(c, 7, 930, 270, 170); save(c, "mockup_combo_07")
    c = bgscene(18); put(c, monitor(D(7), 620), 70, 70, 620); put(c, tablet(tab_screen(5), 280), 720, 150, 280); pp(c, 8, 960, 250, 180); save(c, "mockup_combo_08")
    c = bgscene(19); put(c, tablet(tab_screen(1), 640), 90, 100, 640); pp(c, 9, 720, 70, 250); put(c, tablet(tab_screen(3), 220), 960, 330, 220); save(c, "mockup_combo_09")
    c = bgscene(20); put(c, laptop(D(10), 700), 150, 130, 710); pp(c, 2, 850, 240, 180)
    for j, (kind, x, y, w, h) in enumerate([(b_kpi, 90, 40, 220, 100), (b_donut, 800, 40, 260, 170)]):
        lay, pad = dev.shadowed(widget(kind, w, h, 120 + j))
        c.alpha_composite(lay, (Z(x) - pad, Z(y) - pad))
    save(c, "mockup_combo_10")


# ---------- 10 isometric / 3D ----------
def make_3d():
    def ph(idx, w):
        return phone(M(idx).convert("RGB"), w)

    c = bgscene(21); put3d(c, laptop(D(0), 800), 600, 315, 800, yaw=-26, pitch=5, thickness=7); save(c, "mockup_3d_01")
    c = bgscene(22); put3d(c, laptop(D(7), 800), 600, 315, 800, yaw=26, pitch=5, thickness=7); save(c, "mockup_3d_02")
    c = bgscene(23); put3d(c, monitor(D(6), 760), 520, 290, 760, yaw=-18, pitch=4, thickness=7); put3d(c, ph(0, 170), 960, 400, 175, yaw=-18, pitch=5, thickness=6); save(c, "mockup_3d_03")
    c = bgscene(24)
    for i, n in enumerate([1, 4, 0]):
        put3d(c, browser(D(n), 520), 560 + i * 25, 190 + i * 105, 470, yaw=-38, pitch=24, thickness=3)
    save(c, "mockup_3d_04")
    c = bgscene(25)
    for j, (kind, w, h, cx, cy) in enumerate([(b_area, 360, 200, 300, 200), (b_kpi, 200, 110, 620, 150), (b_donut, 260, 200, 880, 220), (b_table, 420, 260, 560, 400), (b_bars, 320, 200, 920, 470), (b_kpi, 190, 100, 250, 450), (b_list, 250, 240, 160, 300)]):
        put3d(c, widget(kind, w, h, 200 + j), cx, cy, w, yaw=-35, pitch=28, thickness=3)
    save(c, "mockup_3d_05")
    c = bgscene(26); put3d(c, ph(1, 215), 470, 315, 230, yaw=26, pitch=5, thickness=7); put3d(c, ph(3, 215), 730, 335, 230, yaw=-22, pitch=5, thickness=7); save(c, "mockup_3d_06")
    c = bgscene(27)
    for i, n in enumerate([0, 1, 2, 4]):
        put3d(c, ph(n, 170), 330 + 190 * i, 350 - 25 * i, 180, yaw=30, pitch=6, thickness=6)
    save(c, "mockup_3d_07")
    c = bgscene(28)
    pos, size = put3d(c, browser(D(5), 860), 600, 250, 860, yaw=0, pitch=-24, thickness=4, shadow=False)
    reflect(c, pos[0], pos[1] + size[1] - Z(130), pos[0] + size[0], pos[1] + size[1], 60)
    save(c, "mockup_3d_08")
    c = bgscene(29); put3d(c, laptop(D(12), 660), 480, 300, 660, yaw=-22, pitch=5, thickness=7); put3d(c, tablet(tab_screen(0), 260), 820, 410, 260, yaw=-22, pitch=5, thickness=5); put3d(c, ph(2, 150), 1010, 380, 160, yaw=-22, pitch=5, thickness=5); save(c, "mockup_3d_09")
    c = bgscene(30)
    put3d(c, browser(D(8), 880), 600, 380, 880, yaw=-24, pitch=34, thickness=4)
    for j, (kind, w, h, cx, cy) in enumerate([(b_kpi, 200, 100, 330, 120), (b_donut, 240, 170, 620, 90), (b_kpi, 200, 100, 900, 130)]):
        put3d(c, widget(kind, w, h, 300 + j), cx, cy, w, yaw=-24, pitch=34, thickness=3)
    save(c, "mockup_3d_10")


def main():
    make_desktop()
    make_mobile()
    make_combined()
    make_3d()


if __name__ == "__main__":
    main()
