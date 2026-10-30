"""Builds 3D, mobile and desktop+mobile device mockups (1200x630 PNG) from the real application screenshots, using Pillow only.

Inputs:  tests/Browser/screenshots/mk-*.png (desktop, MarketingScreenshotsTest) and mob-*.png (phone, MobileScreenshotsTest).
Outputs: screenshots/3d, screenshots/mobile, screenshots/desktop_mobile.
Run: python generate_device_mockups.py
"""
import math
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).parent
SHOTS = ROOT / "tests" / "Browser" / "screenshots"
OUT = ROOT / "screenshots"
W, H, S = 1200, 630, 2
BG, CYAN, BLUE, TX, MUT = (10, 25, 47), (0, 242, 254), (59, 130, 246), (241, 245, 249), (141, 162, 192)


def Z(v):
    return int(round(v * S))


_fonts = {}


def font(size, bold=False):
    k = (size, bold)
    if k not in _fonts:
        for f in (["segoeuib.ttf", "arialbd.ttf"] if bold else ["segoeui.ttf", "arial.ttf"]):
            try:
                _fonts[k] = ImageFont.truetype(f, Z(size))
                break
            except OSError:
                continue
    return _fonts[k]


def shot(prefix, name):
    return Image.open(next(SHOTS.glob(f"{prefix}-{name}*.png"))).convert("RGB")


def cover(img, w, h):
    r = max(w / img.width, h / img.height)
    im = img.resize((int(img.width * r) + 1, int(img.height * r) + 1), Image.LANCZOS)
    return im.crop((0, 0, w, h))  # keep the top of the page


def rounded(img, r):
    img = img.convert("RGBA")
    m = Image.new("L", img.size, 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, img.width - 1, img.height - 1], radius=r, fill=255)
    img.putalpha(ImageChops.multiply(img.getchannel("A"), m))
    return img


def vgrad(w, h, top, bottom):
    g = Image.new("RGB", (1, h))
    for y in range(h):
        t = y / max(1, h - 1)
        g.putpixel((0, y), tuple(int(a * (1 - t) + b * t) for a, b in zip(top, bottom)))
    return g.resize((w, h))


# ---------- scene ----------
def scene():
    c = Image.new("RGBA", (W * S, H * S), BG + (255,))
    for (x, y, r, col, a) in [(980, 120, 330, CYAN, 70), (260, 560, 380, BLUE, 80), (640, 330, 260, BLUE, 40)]:
        g = Image.new("RGBA", c.size, (0, 0, 0, 0))
        ImageDraw.Draw(g).ellipse([Z(x - r), Z(y - r), Z(x + r), Z(y + r)], fill=col + (a,))
        c.alpha_composite(g.filter(ImageFilter.GaussianBlur(Z(120))))
    d = ImageDraw.Draw(c)
    for gx in range(0, W, 40):
        for gy in range(0, H, 40):
            d.ellipse([Z(gx) - 1, Z(gy) - 1, Z(gx) + 1, Z(gy) + 1], fill=(40, 70, 110, 90))
    return c


def text(c, x, y, s, size, fill=TX, bold=False, anchor="l"):
    ImageDraw.Draw(c).text((Z(x), Z(y)), s, font=font(size, bold), fill=fill, anchor=anchor + "m")


def brand(c, x=60, y=48):
    d = ImageDraw.Draw(c)
    g = vgrad(Z(26), Z(26), CYAN, BLUE)
    c.paste(rounded(g, Z(8)), (Z(x), Z(y - 13)), rounded(g, Z(8)))
    text(c, x + 36, y, "Xelora Systems", 17, TX, True)


def headline(c, lines, sub, x=60, y=150, size=38, accent=None):
    for i, ln in enumerate(lines):
        col = CYAN if accent == i else TX
        text(c, x, y + i * (size * 1.22), ln, size, col, True)
    text(c, x, y + len(lines) * size * 1.22 + 18, sub, 15, MUT)


def chips(c, x, y, items):
    for i, t in enumerate(items):
        yy = y + i * 34
        w = ImageDraw.Draw(c).textlength(t, font=font(12.5)) / S + 36
        d = ImageDraw.Draw(c)
        d.rounded_rectangle([Z(x), Z(yy - 13), Z(x + w), Z(yy + 13)], radius=Z(13), fill=(19, 43, 77, 255), outline=(31, 58, 96, 255), width=S)
        d.ellipse([Z(x + 12) - 4, Z(yy) - 4, Z(x + 12) + 4, Z(yy) + 4], fill=CYAN)
        text(c, x + 26, yy, t, 12.5, TX)


# ---------- devices (all sizes in 1x pixels, returned as 2x RGBA) ----------
def shadowed(layer, blur=18, dy=18, opacity=170):
    pad = Z(blur * 3)
    out = Image.new("RGBA", (layer.width + 2 * pad, layer.height + 2 * pad), (0, 0, 0, 0))
    sh = Image.new("RGBA", out.size, (0, 0, 0, 0))
    a = layer.getchannel("A").point(lambda v: v * opacity // 255)
    sh.paste((0, 0, 0, 255), (pad, pad + Z(dy)), a)
    out.alpha_composite(sh.filter(ImageFilter.GaussianBlur(Z(blur))))
    out.alpha_composite(layer, (pad, pad))
    return out, pad


def phone(screen, w):
    pad = w * 0.04
    sw, sh = Z(w), Z(w * 2.164)
    ow, oh = sw + Z(pad * 2), sh + Z(pad * 2)
    img = Image.new("RGBA", (ow + Z(6), oh), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    ox = Z(3)
    d.rounded_rectangle([ox, 0, ox + ow - 1, oh - 1], radius=int(ow * .16), fill=(14, 18, 27, 255), outline=(70, 84, 110, 255), width=S)
    for (yy, hh, side) in [(0.18, 0.05, "l"), (0.26, 0.09, "l"), (0.2, 0.12, "r")]:
        x0 = 0 if side == "l" else ox + ow
        d.rounded_rectangle([x0, int(oh * yy), x0 + Z(3), int(oh * (yy + hh))], radius=Z(1.5), fill=(45, 56, 78, 255))
    scr = rounded(cover(screen, sw, sh), int(sw * .125))
    img.alpha_composite(scr, (ox + Z(pad), Z(pad)))
    d.rounded_rectangle([ox + ow / 2 - sw * .15, Z(pad) + sw * .035, ox + ow / 2 + sw * .15, Z(pad) + sw * .035 + sw * .085], radius=int(sw * .045), fill=(5, 7, 12, 255))
    return img


def laptop(screen, w):
    b = w * .018
    lid_w, lid_h = Z(w), Z(w * .0 + (w - 2 * b) / 1.906 + b * 2.6)
    base_h = Z(w * .03)
    full_w = Z(w * 1.1)
    img = Image.new("RGBA", (full_w, lid_h + base_h), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    lx = (full_w - lid_w) // 2
    d.rounded_rectangle([lx, 0, lx + lid_w - 1, lid_h - 1], radius=Z(14), fill=(12, 16, 24, 255), outline=(82, 96, 122, 255), width=S)
    scr = cover(screen, Z(w - 2 * b), Z((w - 2 * b) / 1.906))
    img.alpha_composite(rounded(scr, Z(5)), (lx + Z(b), Z(b)))
    d.ellipse([lx + lid_w / 2 - 3, Z(b / 2) - 3, lx + lid_w / 2 + 3, Z(b / 2) + 3], fill=(40, 50, 70, 255))
    base = vgrad(full_w, base_h, (214, 221, 232), (120, 132, 150)).convert("RGBA")
    m = Image.new("L", base.size, 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, full_w - 1, base_h - 1], radius=Z(6), fill=255)
    ImageDraw.Draw(m).rectangle([0, 0, full_w, Z(4)], fill=255)
    base.putalpha(m)
    img.alpha_composite(base, (0, lid_h))
    d.rounded_rectangle([full_w / 2 - Z(w * .07), lid_h, full_w / 2 + Z(w * .07), lid_h + Z(w * .012)], radius=Z(4), fill=(150, 160, 178, 255))
    return img


def browser(screen, w, url=""):
    bar = 30
    sw, sh = Z(w), Z(w / 1.906)
    img = Image.new("RGBA", (sw, sh + Z(bar)), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rectangle([0, 0, sw, Z(bar)], fill=(22, 36, 61, 255))
    for i, col in enumerate([(248, 113, 113), (251, 191, 36), (52, 211, 153)]):
        d.ellipse([Z(14 + i * 18) - Z(5), Z(bar / 2) - Z(5), Z(14 + i * 18) + Z(5), Z(bar / 2) + Z(5)], fill=col + (255,))
    d.rounded_rectangle([Z(86), Z(6), sw - Z(86), Z(bar - 6)], radius=Z(9), fill=(10, 25, 47, 255))
    d.text((sw / 2, Z(bar / 2)), url, font=font(11), fill=MUT + (255,), anchor="mm")
    img.alpha_composite(cover(screen, sw, sh).convert("RGBA"), (0, Z(bar)))
    return rounded(img, Z(10))


def monitor(screen, w):
    b = w * .016
    sw, sh = Z(w - 2 * b), Z((w - 2 * b) / 1.906)
    top = sh + Z(b * 3.2)
    img = Image.new("RGBA", (Z(w), top + Z(w * .12)), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([0, 0, Z(w) - 1, top - 1], radius=Z(12), fill=(12, 16, 24, 255), outline=(82, 96, 122, 255), width=S)
    img.alpha_composite(rounded(cover(screen, sw, sh), Z(4)), (Z(b), Z(b)))
    d.polygon([(Z(w * .44), top), (Z(w * .56), top), (Z(w * .6), top + Z(w * .09)), (Z(w * .4), top + Z(w * .09))], fill=(150, 160, 178, 255))
    d.rounded_rectangle([Z(w * .32), top + Z(w * .085), Z(w * .68), top + Z(w * .105)], radius=Z(6), fill=(195, 203, 216, 255))
    return img


# ---------- 3D warp ----------
def solve(a, b):
    n = len(b)
    m = [row[:] + [b[i]] for i, row in enumerate(a)]
    for i in range(n):
        p = max(range(i, n), key=lambda r: abs(m[r][i]))
        m[i], m[p] = m[p], m[i]
        for r in range(n):
            if r != i:
                f = m[r][i] / m[i][i]
                m[r] = [x - f * y for x, y in zip(m[r], m[i])]
    return [m[i][n] / m[i][i] for i in range(n)]


def coeffs(dst, src):
    rows, rhs = [], []
    for (x, y), (u, v) in zip(dst, src):
        rows.append([x, y, 1, 0, 0, 0, -u * x, -u * y]); rhs.append(u)
        rows.append([0, 0, 0, x, y, 1, -v * x, -v * y]); rhs.append(v)
    return solve(rows, rhs)


def quad(cx, cy, w, h, yaw=0, pitch=0, z=0, d=1500):
    """Projects a w x h plane (1x units) centred at cx,cy, turned by yaw/pitch degrees, back to 2x canvas points."""
    ya, pa = math.radians(yaw), math.radians(pitch)
    pts = []
    for sx, sy in [(-1, -1), (1, -1), (1, 1), (-1, 1)]:
        x, y, zz = sx * w / 2, sy * h / 2, z
        x, zz = x * math.cos(ya) + zz * math.sin(ya), -x * math.sin(ya) + zz * math.cos(ya)
        y, zz = y * math.cos(pa) - zz * math.sin(pa), y * math.sin(pa) + zz * math.cos(pa)
        k = d / (d + zz)
        pts.append((Z(cx + x * k), Z(cy + y * k)))
    return pts


def warp(layer, dst):
    xs, ys = [p[0] for p in dst], [p[1] for p in dst]
    x0, y0, x1, y1 = int(min(xs)) - 2, int(min(ys)) - 2, int(max(xs)) + 3, int(max(ys)) + 3
    local = [(x - x0, y - y0) for x, y in dst]
    src = [(0, 0), (layer.width, 0), (layer.width, layer.height), (0, layer.height)]
    c = coeffs(local, src)
    out = layer.transform((x1 - x0, y1 - y0), Image.PERSPECTIVE, c, Image.BICUBIC)
    return out, (x0, y0)


def put3d(c, layer, cx, cy, w_1x, yaw=0, pitch=0, thickness=0, shadow=True, light=False):
    h_1x = layer.height / S * (w_1x / (layer.width / S))
    lay = layer.resize((Z(w_1x), Z(h_1x)), Image.LANCZOS)
    if shadow:
        gq = quad(cx, cy + h_1x * .5 + 18, w_1x * .95, h_1x * .08, yaw, pitch)
        sh = Image.new("RGBA", c.size, (0, 0, 0, 0))
        ImageDraw.Draw(sh).polygon(gq, fill=(0, 0, 0, 150))
        c.alpha_composite(sh.filter(ImageFilter.GaussianBlur(Z(16))))
    steps = int(thickness)
    for i in range(steps, 0, -1):
        dark = Image.new("RGBA", lay.size, (0, 0, 0, 0))
        dark.paste(Image.new("RGBA", lay.size, (26, 34, 52, 255)), (0, 0), lay.getchannel("A"))
        out, pos = warp(dark, quad(cx, cy, w_1x, h_1x, yaw, pitch, z=i * 1.2))
        c.alpha_composite(out, pos)
    out, pos = warp(lay, quad(cx, cy, w_1x, h_1x, yaw, pitch))
    c.alpha_composite(out, pos)
    return pos, out.size


def put(c, layer, x, y, w_1x, shadow=True):
    lay = layer.resize((Z(w_1x), int(layer.height * Z(w_1x) / layer.width)), Image.LANCZOS)
    if shadow:
        lay2, pad = shadowed(lay)
        c.alpha_composite(lay2, (Z(x) - pad, Z(y) - pad))
    else:
        c.alpha_composite(lay, (Z(x), Z(y)))
    return lay.height / S


def reflect(c, x0, y0, x1, y1, strength=70):
    region = c.crop((x0, y0, x1, y1)).transpose(Image.FLIP_TOP_BOTTOM)
    h = y1 - y0
    fade = Image.new("L", region.size)
    for y in range(h):
        fade.paste(int(strength * (1 - y / h) ** 2), (0, y, region.width, y + 1))
    region.putalpha(ImageChops.multiply(region.getchannel("A"), fade))
    c.alpha_composite(region, (x0, y1))


def card(c, x, y, w, label, value, delta=None, ok=True):
    d = ImageDraw.Draw(c)
    d.rounded_rectangle([Z(x) + 4, Z(y) + 10, Z(x + w) + 4, Z(y + 62) + 10], radius=Z(12), fill=(0, 0, 0, 90))
    d.rounded_rectangle([Z(x), Z(y), Z(x + w), Z(y + 62)], radius=Z(12), fill=(19, 43, 77, 245), outline=(44, 77, 124, 255), width=S)
    text(c, x + 16, y + 18, label, 11.5, MUT)
    text(c, x + 16, y + 42, value, 17, TX, True)
    if delta:
        text(c, x + w - 14, y + 42, delta, 12, (52, 211, 153) if ok else (248, 113, 113), True, "r")


def save(c, folder, name):
    d = OUT / folder
    d.mkdir(parents=True, exist_ok=True)
    c.resize((W, H), Image.LANCZOS).convert("RGB").save(d / f"{name}.png", optimize=True)
    print("saved", folder, name)


# ---------- 3D set ----------
def mob(n):
    return Image.open(next(SHOTS.glob(f"mob-{n}*.png"))).convert("RGB")


def d3_01():
    c = scene()
    put3d(c, laptop(shot("mk", "01"), 800), 600, 315, 800, yaw=-24, pitch=5, thickness=7)
    save(c, "3d", "3d_01_laptop_dashboard")


def d3_02():
    c = scene()
    put3d(c, phone(mob("01"), 215), 470, 315, 230, yaw=26, pitch=5, thickness=7)
    put3d(c, phone(mob("07"), 215), 730, 335, 230, yaw=-22, pitch=5, thickness=7)
    save(c, "3d", "3d_02_two_phones")


def d3_03():
    c = scene()
    for i, (n, cy) in enumerate([("08", 200), ("02", 320), ("01", 440)]):
        put3d(c, browser(shot("mk", n), 520), 570 + i * 20, cy, 520, yaw=-32, pitch=16, thickness=3)
    save(c, "3d", "3d_03_stacked_screens")


def d3_04():
    c = scene()
    put3d(c, laptop(shot("mk", "07"), 700), 540, 315, 700, yaw=-20, pitch=5, thickness=7)
    put3d(c, phone(mob("03"), 170), 950, 410, 175, yaw=-20, pitch=5, thickness=6)
    save(c, "3d", "3d_04_laptop_and_phone")


def d3_05():
    c = scene()
    pos, size = put3d(c, browser(shot("mk", "07"), 860), 600, 250, 860, yaw=0, pitch=-24, thickness=4, shadow=False)
    reflect(c, pos[0], pos[1] + size[1] - Z(130), pos[0] + size[0], pos[1] + size[1], 60)
    save(c, "3d", "3d_05_reclined_report")


def d3_06():
    c = scene()
    for i, n in enumerate(["03", "02", "01", "04"]):
        put3d(c, phone(mob(n), 170), 330 + 190 * i, 350 - 25 * i, 180, yaw=30, pitch=6, thickness=6)
    save(c, "3d", "3d_06_phone_row")


# ---------- mobile set ----------
def m_01():
    c = scene()
    for n, x, y in [("07", 226, 110), ("01", 492, 72), ("02", 758, 110)]:
        put(c, phone(mob(n), 190), x, y, 216)
    save(c, "mobile", "mobile_01_three_phones")


def m_02():
    c = scene()
    put(c, phone(mob("01"), 205), 340, 40, 232)
    put(c, phone(mob("04"), 205), 620, 70, 232)
    save(c, "mobile", "mobile_02_two_phones")


def m_03():
    c = scene()
    put(c, phone(mob("01"), 230), 470, 22, 260)
    save(c, "mobile", "mobile_03_single_phone")


def m_04():
    c = scene()
    for n, x in [("03", 110), ("04", 360), ("08", 610), ("07", 860)]:
        put(c, phone(mob(n), 205), x, 110, 232)
    save(c, "mobile", "mobile_04_four_phones")


def m_05():
    c = scene()
    put(c, phone(mob("08"), 205), 300, 100, 232)
    put(c, phone(mob("07"), 205), 668, 100, 232)
    put(c, phone(mob("01"), 205), 484, 50, 232)
    save(c, "mobile", "mobile_05_phone_trio")


# ---------- desktop + mobile set ----------
def dm_01():
    c = scene()
    put(c, laptop(shot("mk", "01"), 760), 130, 90, 770)
    put(c, phone(mob("02"), 170), 800, 250, 190)
    save(c, "desktop_mobile", "combo_01_laptop_phone")


def dm_02():
    c = scene()
    put(c, browser(shot("mk", "08"), 820), 90, 110, 820)
    put(c, phone(mob("07"), 180), 850, 120, 200)
    save(c, "desktop_mobile", "combo_02_browser_phone")


def dm_03():
    c = scene()
    put(c, laptop(shot("mk", "02"), 700), 250, 80, 710)
    put(c, phone(mob("04"), 150), 100, 270, 170)
    put(c, phone(mob("03"), 150), 930, 270, 170)
    save(c, "desktop_mobile", "combo_03_laptop_two_phones")


def dm_04():
    c = scene()
    put(c, monitor(shot("mk", "09"), 720), 130, 50, 720)
    put(c, phone(mob("11"), 160), 880, 240, 180)
    save(c, "desktop_mobile", "combo_04_monitor_phone")


def dm_05():
    c = scene()
    put(c, browser(shot("mk", "03"), 600), 130, 50, 600)
    put(c, browser(shot("mk", "02"), 620), 250, 190, 640)
    put(c, phone(mob("03"), 160), 910, 290, 180)
    save(c, "desktop_mobile", "combo_05_layered_windows_phone")


def main():
    for fn in (d3_01, d3_02, d3_03, d3_04, d3_05, d3_06, m_01, m_02, m_03, m_04, m_05, dm_01, dm_02, dm_03, dm_04, dm_05):
        fn()


if __name__ == "__main__":
    main()
