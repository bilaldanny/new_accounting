"""Generates 33 Xelora Systems accounting mockups (1200x630 PNG) into ./screenshots using Pillow only.

Run: python generate_mockups.py
"""
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFont

OUT = Path(__file__).parent / "screenshots"
W, H, S = 1200, 630, 2  # canvas size and supersampling factor

BG, SIDE, PANEL, PANEL2, LINE = "#0a192f", "#081528", "#0f2340", "#132b4d", "#1f3a60"
TX, MUT = "#f1f5f9", "#8da2c0"
CYAN, BLUE, GREEN, RED, AMBER, VIOLET = "#00f2fe", "#3b82f6", "#34d399", "#f87171", "#fbbf24", "#a78bfa"
STATUS = {}
for _names, _col in [("Paid Matched Filed In Stock Received Active Approved Processed Connected Posted Completed On Track", GREEN),
                     ("Overdue Unmatched Out of Stock Cancelled Over Budget", RED),
                     ("Pending Review Due Soon Low Stock Partial On Hold Watch", AMBER),
                     ("Open Upcoming", "#7db0ff"), ("Syncing In Transit", CYAN), ("Draft", "#9fb2cf")]:
    for _n in [x for x in _names.replace("In Stock", "In_Stock").replace("Out of Stock", "Out_of_Stock").replace("Low Stock", "Low_Stock").replace("Due Soon", "Due_Soon").replace("On Hold", "On_Hold").replace("On Track", "On_Track").replace("Over Budget", "Over_Budget").replace("In Transit", "In_Transit").split()]:
        STATUS[_n.replace("_", " ")] = _col

FONT_FILES = {False: ["segoeui.ttf", "arial.ttf"], True: ["segoeuib.ttf", "arialbd.ttf"]}
_fonts = {}


def font(size, bold=False):
    key = (size, bold)
    if key not in _fonts:
        for f in FONT_FILES[bold]:
            try:
                _fonts[key] = ImageFont.truetype(f, int(size * S))
                break
            except OSError:
                continue
    return _fonts[key]


def rgb(h):
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def mix(a, b, t):
    return tuple(int(x * t + y * (1 - t)) for x, y in zip(rgb(a), rgb(b)))


class Canvas:
    def __init__(self):
        self.im = Image.new("RGB", (W * S, H * S), BG)
        self.d = ImageDraw.Draw(self.im)

    def rect(self, x, y, w, h, fill=None, outline=None, r=0):
        self.d.rounded_rectangle([x * S, y * S, (x + w) * S - 1, (y + h) * S - 1], radius=r * S, fill=fill, outline=outline, width=S if outline else 0)

    def text(self, x, y, t, size=12, col=TX, bold=False, a="l"):
        self.d.text((x * S, y * S), str(t), font=font(size, bold), fill=col, anchor=a + "m")

    def tw(self, t, size=12, bold=False):
        return font(size, bold).getlength(str(t)) / S

    def fit(self, t, maxw, size=12, bold=False):
        t = str(t)
        if self.tw(t, size, bold) <= maxw:
            return t
        while t and self.tw(t + "…", size, bold) > maxw:
            t = t[:-1]
        return t + "…"

    def line(self, pts, col, w=1.0, dash=None):
        pts = [(x * S, y * S) for x, y in pts]
        if not dash:
            self.d.line(pts, fill=col, width=max(1, int(w * S)), joint="curve")
            return
        for (x1, y1), (x2, y2) in zip(pts, pts[1:]):
            ln = ((x2 - x1) ** 2 + (y2 - y1) ** 2) ** .5
            n = max(1, int(ln / (dash * S)))
            for i in range(0, n, 2):
                a, b = i / n, min(1, (i + 1) / n)
                self.d.line([(x1 + (x2 - x1) * a, y1 + (y2 - y1) * a), (x1 + (x2 - x1) * b, y1 + (y2 - y1) * b)], fill=col, width=int(w * S))

    def grad(self, x, y, w, h, c1, c2, r=0, vertical=False):
        g = Image.new("RGB", (int(w * S), int(h * S)))
        gd = ImageDraw.Draw(g)
        n = g.height if vertical else g.width
        for i in range(n):
            col = mix(c2, c1, i / max(1, n - 1))
            gd.line([(0, i), (g.width, i)] if vertical else [(i, 0), (i, g.height)], fill=col)
        m = Image.new("L", g.size, 0)
        ImageDraw.Draw(m).rounded_rectangle([0, 0, g.width - 1, g.height - 1], radius=r * S, fill=255)
        self.im.paste(g, (int(x * S), int(y * S)), m)

    def area(self, pts, base, col, alpha=90):
        poly = [(x * S, y * S) for x, y in pts] + [(pts[-1][0] * S, base * S), (pts[0][0] * S, base * S)]
        m = Image.new("L", self.im.size, 0)
        ImageDraw.Draw(m).polygon(poly, fill=255)
        top = min(y for _, y in pts) * S
        ramp = Image.new("L", self.im.size, 0)
        rd = ImageDraw.Draw(ramp)
        for yy in range(int(top), int(base * S)):
            rd.line([(0, yy), (self.im.width, yy)], fill=int(alpha * (1 - (yy - top) / max(1, base * S - top))))
        self.im.paste(Image.new("RGB", self.im.size, rgb(col)), (0, 0), ImageChops.multiply(m, ramp))

    def dot(self, x, y, r, col, ring=PANEL):
        self.d.ellipse([(x - r - 2) * S, (y - r - 2) * S, (x + r + 2) * S, (y + r + 2) * S], fill=ring)
        self.d.ellipse([(x - r) * S, (y - r) * S, (x + r) * S, (y + r) * S], fill=col)

    def tri(self, x, y, up, col):
        pts = [(x, y - 4), (x - 4, y + 3), (x + 4, y + 3)] if up else [(x, y + 4), (x - 4, y - 3), (x + 4, y - 3)]
        self.d.polygon([(a * S, b * S) for a, b in pts], fill=col)

    def check(self, x, y, col=GREEN):
        self.line([(x - 4, y), (x - 1, y + 3), (x + 5, y - 4)], col, 2)

    def badge(self, x, y, t, a="l"):
        col = STATUS.get(t, "#9fb2cf")
        w = self.tw(t, 10, True) + 18
        x = x if a == "l" else x - w
        self.rect(x, y - 9, w, 18, fill=mix(col, PANEL, .17), r=9)
        self.text(x + w / 2, y, t, 10, col, True, "m")
        return w

    def bar(self, x, y, w, pct, col=None, h=6):
        self.rect(x, y - h / 2, w, h, fill=PANEL2, r=3)
        fill_w = max(h, w * min(100, pct) / 100)
        if col:
            self.rect(x, y - h / 2, fill_w, h, fill=col, r=3)
        else:
            self.grad(x, y - h / 2, fill_w, h, CYAN, BLUE, r=3)

    def save(self, path):
        self.im.resize((W, H), Image.LANCZOS).save(path, optimize=True)


NAV = ["Dashboard", "Invoices", "Inventory", "Purchases", "Contacts", "Banking", "Payroll", "Assets", "Tax", "Reports", "Settings"]
X0, XW, Y0, YB = 196, 984, 68, 614


def shell(active, title, sub, action):
    c = Canvas()
    c.rect(0, 0, 176, H, fill=SIDE)
    c.line([(176, 0), (176, H)], LINE)
    c.grad(14, 14, 26, 26, CYAN, BLUE, r=8)
    c.text(50, 27, "Xelora", 15, TX, True)
    for i, n in enumerate(NAV):
        y = 70 + i * 30
        if i == active:
            c.grad(10, y - 14, 156, 28, "#123b5c", "#10294a", r=7)
            c.rect(10, y - 14, 3, 28, fill=CYAN)
        c.text(26, y, n, 12.5, TX if i == active else MUT)
    c.line([(176, 52), (W, 52)], LINE)
    c.text(196, 19, title, 17, TX, True)
    c.text(196, 38, sub, 11, MUT)
    c.grad(1086, 12, 28, 28, BLUE, BLUE, r=14)
    c.text(1100, 26, "XS", 10, TX, True, "m")
    bw = c.tw(action, 12, True) + 28
    c.grad(1076 - bw, 11, bw, 30, CYAN, BLUE, r=8)
    c.text(1076 - bw / 2, 26, action, 12, "#04121f", True, "m")
    pill = "FY 2026 · Xelora Systems (Pvt) Ltd"
    pw = c.tw(pill, 11.5) + 24
    c.rect(1076 - bw - 10 - pw, 11, pw, 30, fill=PANEL, outline=LINE, r=8)
    c.text(1076 - bw - 10 - pw / 2, 26, pill, 11.5, MUT, False, "m")
    return c


def card(c, x, y, w, h, title=None, sub=None, fill=PANEL):
    c.rect(x, y, w, h, fill=fill, outline=LINE, r=12)
    if title:
        c.text(x + 15, y + 19, title, 12.5, "#cbd8ea", True)
    if sub:
        c.text(x + w - 15, y + 19, c.fit(sub, w * .55, 11), 11, MUT, False, "r")
    return x + 15, y + 38, w - 30


def kpis(c, items, y=Y0, h=66):
    w = (XW - 12 * (len(items) - 1)) / len(items)
    for i, (lab, val, delta, up) in enumerate(items):
        x = X0 + i * (w + 12)
        card(c, x, y, w, h)
        c.text(x + 15, y + 16, lab, 11.5, MUT)
        c.text(x + 15, y + 37, val, 21, TX, True)
        if delta:
            col = GREEN if up else RED
            c.tri(x + 19, y + 55, up, col)
            c.text(x + 27, y + 55, delta, 11, col)
    return y + h + 12


def cols(weights, y0=None, gap=12):
    tot = sum(weights)
    avail = XW - gap * (len(weights) - 1)
    out, x = [], X0
    for wt in weights:
        w = avail * wt / tot
        out.append((x, w))
        x += w + gap
    return out


def table(c, x, y, w, heads, rows, right=(), total=None, rh=30):
    ncol = len(heads)
    nat = []
    for i in range(ncol):
        best = c.tw(heads[i].upper(), 10, True) + 16
        for r in list(rows) + ([total] if total else []):
            v = r[i]
            if isinstance(v, tuple):
                best = max(best, 100 + c.tw(v[2], 12) + 16)
            elif v in STATUS:
                best = max(best, c.tw(v, 10, True) + 34)
            else:
                best = max(best, c.tw(str(v).lstrip("*"), 12, str(v).startswith("*")) + 16)
        nat.append(best)
    s = sum(nat)
    cw = [n * (w / s) for n in nat] if s > w else [n + (w - s) / ncol for n in nat]
    xs = [x]
    for k in cw:
        xs.append(xs[-1] + k)
    for i, hd in enumerate(heads):
        c.text(xs[i + 1] - 8 if i in right else xs[i] + 8, y + 8, hd.upper(), 10, MUT, True, "r" if i in right else "l")
    yy = y + 22
    allrows = [(r, False) for r in rows] + ([(total, True)] if total else [])
    for r, is_tot in allrows:
        if is_tot:
            c.rect(x, yy, w, rh, fill=mix(BLUE, PANEL, .1))
        c.line([(x, yy), (x + w, yy)], "#2c4d7c" if is_tot else LINE)
        cy = yy + rh / 2
        for i, v in enumerate(r):
            cx0, cx1 = xs[i], xs[i + 1]
            if isinstance(v, tuple):
                bw = min(100, cx1 - cx0 - c.tw(v[2], 12) - 20)
                c.bar(cx0 + 8, cy, bw, v[0], v[1])
                c.text(cx0 + 16 + bw, cy, v[2], 12)
            elif v in STATUS:
                c.badge(cx1 - 8 if i in right else cx0 + 8, cy, v, "r" if i in right else "l")
            else:
                bold = is_tot or str(v).startswith("*")
                t = c.fit(str(v).lstrip("*"), cx1 - cx0 - 14, 12, bold)
                col = MUT if t in ("—", "") else TX
                if i in right:
                    c.text(cx1 - 8, cy, t, 12, col, bold, "r")
                else:
                    c.text(cx0 + 8, cy, t, 12, col, bold)
        yy += rh
    return yy


def tcard(c, x, y, w, h, title, sub, heads, rows, right=(), total=None, rh=30):
    ix, iy, iw = card(c, x, y, w, h, title, sub)
    return table(c, ix, iy, iw, heads, rows, right, total, rh)


def kv_card(c, x, y, w, h, title, pairs, sub=None):
    ix, iy, iw = card(c, x, y, w, h, title, sub)
    for i, (k, v) in enumerate(pairs):
        yy = iy + i * 30
        c.line([(ix, yy), (ix + iw, yy)], LINE)
        c.text(ix, yy + 15, k, 12, MUT)
        c.text(ix + iw, yy + 15, v, 12, TX, True, "r")


def fields_card(c, x, y, w, h, title, items, sub=None, gap=50):
    ix, iy, iw = card(c, x, y, w, h, title, sub)
    for i, (k, v) in enumerate(items):
        yy = iy + i * gap
        c.text(ix, yy + 6, k, 11, MUT)
        c.rect(ix, yy + 16, iw, 28, fill=BG, outline=LINE, r=8)
        c.text(ix + 10, yy + 30, c.fit(v, iw - 20, 12), 12)


def bars_card(c, x, y, w, h, title, items, sub=None):
    ix, iy, iw = card(c, x, y, w, h, title, sub)
    for i, (k, pct, col, val) in enumerate(items):
        yy = iy + i * 46
        c.text(ix, yy + 8, k, 12, MUT)
        c.text(ix + iw, yy + 8, val, 12, TX, True, "r")
        c.bar(ix, yy + 26, iw, pct, col)


def line_chart(c, x, y, w, h, series, labels, ymax=None, dash_from=None, unit="M"):
    pl, pb, pt = 36, 20, 6
    ymax = ymax or max(max(v) for v, _ in series) * 1.1
    iw, ih = w - pl - 6, h - pb - pt
    n = len(labels)
    for k in range(5):
        yy = y + pt + ih * k / 4
        c.line([(x + pl, yy), (x + w - 6, yy)], LINE)
        c.text(x, yy, f"{ymax * (1 - k / 4):.0f}{unit}", 10, MUT)
    xs = [x + pl + iw * i / (n - 1) for i in range(n)]
    for i, lab in enumerate(labels):
        c.text(xs[i], y + h - 6, lab, 10, MUT, False, "m")
    for si, (vals, col) in enumerate(series):
        pts = [(xs[i], y + pt + ih * (1 - v / ymax)) for i, v in enumerate(vals)]
        if si == 0:
            c.area(pts, y + pt + ih, col, 80)
        sp = dash_from if dash_from else len(pts)
        c.line(pts[:sp], col, 2.4)
        if sp < len(pts):
            c.line(pts[sp - 1:], col, 2.4, dash=5)
        c.dot(*pts[sp - 1], 4, col)


def bar_chart(c, x, y, w, h, labels, series, ymax=None, unit="M"):
    pl, pb, pt = 36, 20, 6
    ymax = ymax or max(max(v) for v, _ in series) * 1.15
    iw, ih = w - pl - 6, h - pb - pt
    slot = iw / len(labels)
    bw = min(24, slot * .7 / len(series))
    for k in range(5):
        yy = y + pt + ih * k / 4
        c.line([(x + pl, yy), (x + w - 6, yy)], LINE)
        c.text(x, yy, f"{ymax * (1 - k / 4):.0f}{unit}", 10, MUT)
    for i, lab in enumerate(labels):
        cx = x + pl + slot * (i + .5)
        c.text(cx, y + h - 6, lab, 10, MUT, False, "m")
        for j, (vals, col) in enumerate(series):
            bh = ih * vals[i] / ymax
            bx = cx - bw * len(series) / 2 + j * bw
            c.rect(bx, y + pt + ih - bh, bw - 2, bh, fill=col, r=3)


def legend(c, x, y, items):
    for name, col in items:
        c.rect(x, y - 4, 8, 8, fill=col, r=2)
        c.text(x + 13, y, name, 11, MUT)
        x += 13 + c.tw(name, 11) + 14


def donut(c, cx, cy, r, parts, big, small):
    total = sum(v for _, v, _ in parts)
    a = -90.0
    for _, v, col in parts:
        sweep = 360 * v / total
        c.d.arc([(cx - r) * S, (cy - r) * S, (cx + r) * S, (cy + r) * S], a + 1, a + sweep - 1, fill=col, width=int(16 * S))
        a += sweep
    c.text(cx, cy - 5, big, 12 if r < 50 else 15, TX, True, "m")
    c.text(cx, cy + 12, small, 10, MUT, False, "m")


def new(active, title, sub, action="Export", k=None):
    c = shell(active, title, sub, action)
    return c, (kpis(c, k) if k else Y0)


MON = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]


def full_table(active, title, sub, action, k, ctitle, csub, heads, rows, right=(), total=None):
    c, y = new(active, title, sub, action, k)
    tcard(c, X0, y, XW, YB - y, ctitle, csub, heads, rows, right, total)
    return c


# ---------- screens ----------
def s01():
    c, y = new(0, "Financial Dashboard", "Overview · January – December 2026", "Export", [
        ("Total Revenue", "PKR 314.3M", "18.4% vs last year", True), ("Total Expenses", "PKR 212.0M", "9.1% vs last year", False),
        ("Net Profit", "PKR 102.3M", "32.6% vs last year", True), ("Monthly Growth", "+10.2%", "Dec vs Nov", True)])
    (x1, w1), (x2, w2) = cols([2, 1])
    ix, iy, iw = card(c, x1, y, w1, YB - y, "Income vs Expenses")
    legend(c, x1 + w1 - 140, y + 19, [("Income", CYAN), ("Expenses", BLUE)])
    line_chart(c, ix, iy + 4, iw, YB - y - 52,
               [([18.2, 19.5, 21.8, 20.4, 23.9, 25.6, 27.1, 26.4, 29.8, 31.2, 33.5, 36.9], CYAN), ([13.1, 13.9, 15.2, 14.8, 16.4, 17.1, 18.3, 18.0, 19.7, 20.5, 21.6, 23.2], BLUE)], MON)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Revenue by Channel")
    donut(c, ix + 55, iy + 70, 46, [("Retail", 46, CYAN), ("Wholesale", 31, BLUE), ("Online", 15, VIOLET), ("Export", 8, GREEN)], "PKR 314M", "FY 2026")
    for i, (n, p, col) in enumerate([("Retail", 46, CYAN), ("Wholesale", 31, BLUE), ("Online", 15, VIOLET), ("Export", 8, GREEN)]):
        c.rect(ix + 122, iy + 32 + i * 24 - 4, 8, 8, fill=col, r=2)
        c.text(ix + 136, iy + 32 + i * 24, n, 12, MUT)
        c.text(ix + iw, iy + 32 + i * 24, f"{p}%", 12, TX, True, "r")
    c.text(ix, iy + 150, "Top customer", 11, MUT)
    c.text(ix, iy + 170, "Al-Noor Traders — PKR 28.4M", 13, TX, True)
    return c


def s02():
    c, y = new(1, "New Invoice", "Invoices › Create", "Save")
    (x1, w1), (x2, w2) = cols([2, 1])
    ix, iy, iw = card(c, x1, y, w1, YB - y)
    fw = (iw - 20) / 3
    for i, (k, v) in enumerate([("Bill to", "Al-Noor Traders, Karachi"), ("Invoice #", "INV-2026-01842"), ("Due date", "15 Nov 2026 · Net 30")]):
        fx = ix + i * (fw + 10)
        c.text(fx, iy + 4, k, 11, MUT)
        c.rect(fx, iy + 14, fw, 28, fill=BG, outline=LINE, r=8)
        c.text(fx + 10, iy + 28, v, 12)
    yy = table(c, ix, iy + 58, iw, ["Item", "Qty", "Rate", "Amount"], [
        ["*Cotton Fabric Roll (50m)", "40", "PKR 18,500", "PKR 740,000"], ["*Stitching Thread Box", "120", "PKR 2,350", "PKR 282,000"],
        ["*Packaging Carton L", "300", "PKR 410", "PKR 123,000"], ["*Dye Concentrate 5L", "25", "PKR 7,800", "PKR 195,000"]], right=(1, 2, 3))
    c.text(ix, yy + 18, "+ Add line item", 12, CYAN)
    c.text(ix, yy + 48, "Notes", 11, MUT)
    c.rect(ix, yy + 58, iw, 44, fill=BG, outline=LINE, r=8)
    c.text(ix + 10, yy + 80, "Payment via Meezan Bank A/C 0102-XXXXXX-01 or JazzCash. Thank you.", 11.5, MUT)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Summary")
    c.badge(x2 + w2 - 15, y + 19, "Draft", "r")
    for i, (k, v) in enumerate([("Subtotal", "PKR 1,340,000"), ("Discount (3%)", "− PKR 40,200"), ("GST 18%", "PKR 233,964")]):
        c.text(ix, iy + 14 + i * 30, k, 12, MUT)
        c.text(ix + iw, iy + 14 + i * 30, v, 12, TX, True, "r")
    c.line([(ix, iy + 108), (ix + iw, iy + 108)], LINE)
    c.text(ix, iy + 128, "Total due", 11, MUT)
    c.text(ix, iy + 158, "PKR 1,533,764", 24, CYAN, True)
    c.grad(ix, iy + 200, iw, 34, CYAN, BLUE, r=8)
    c.text(ix + iw / 2, iy + 217, "Send Invoice", 12.5, "#04121f", True, "m")
    c.rect(ix, iy + 244, iw, 30, fill=PANEL2, outline=LINE, r=8)
    c.text(ix + iw / 2, iy + 259, "Save as Draft · Download PDF", 11.5, MUT, False, "m")
    return c


def s03():
    rows = [["XS-1001", "*Cotton Fabric Roll", "Textile", (70, None, "420"), "In Stock", "PKR 18,500"], ["XS-1002", "*Stitching Thread Box", "Supplies", (21, AMBER, "85"), "Low Stock", "PKR 2,350"],
            ["XS-1003", "*Packaging Carton L", "Packaging", (92, None, "1,850"), "In Stock", "PKR 410"], ["XS-1004", "*Dye Concentrate 5L", "Chemicals", (0, RED, "0"), "Out of Stock", "PKR 7,800"],
            ["XS-1005", "*Zipper YKK 20in", "Trims", (80, None, "3,200"), "In Stock", "PKR 65"], ["XS-1006", "*Polyester Yarn Cone", "Textile", (28, AMBER, "140"), "Low Stock", "PKR 1,980"],
            ["XS-1007", "*Label Tag Roll", "Packaging", (96, None, "960"), "In Stock", "PKR 540"]]
    return full_table(2, "Inventory & Stock", "Main warehouse · live levels", "+ Add Item", [
        ("Stock Value", "PKR 86.4M", "4.2% this month", True), ("SKUs Tracked", "1,248", "36 new", True), ("Low Stock Items", "42", "7 more than last week", False), ("Turnover Ratio", "6.8x", "0.4x", True)],
        "Stock Levels", "Showing 7 of 1,248", ["SKU", "Product", "Category", "On hand", "Status", "Unit cost"], rows, right=(5,))


def s04():
    c, y = new(9, "Profit & Loss", "Reports › Financial Statements")
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Profit & Loss Statement", "Year ended 31 Dec 2026 · PKR", ["Particulars", "FY 2026", "FY 2025", "Change"], [
        ["Revenue", "314,320,000", "266,010,000", "+18.2%"], ["Cost of Goods Sold", "(166,400,000)", "(144,200,000)", "+15.4%"], ["*Gross Profit", "*147,920,000", "*121,810,000", "*+21.4%"],
        ["Salaries & Wages", "(48,600,000)", "(43,100,000)", "+12.8%"], ["Rent & Utilities", "(11,250,000)", "(10,400,000)", "+8.2%"], ["Marketing", "(7,900,000)", "(6,300,000)", "+25.4%"],
        ["Depreciation", "(4,800,000)", "(4,500,000)", "+6.7%"], ["Other Operating", "(7,330,000)", "(6,610,000)", "+10.9%"]], right=(1, 2, 3),
        total=["Net Profit before Tax", "68,040,000", "50,900,000", "+33.7%"], rh=36)
    bars_card(c, x2, y, w2, YB - y, "Margins", [("Gross margin", 47.1 * 1.6, None, "47.1%"), ("Operating margin", 24.8 * 1.6, None, "24.8%"), ("Net margin", 21.6 * 1.6, None, "21.6%")])
    c.text(x2 + 15, y + 205, "Tax provision (29%)", 11, MUT)
    c.text(x2 + 15, y + 230, "PKR 19.7M", 20, CYAN, True)
    return c


def s05():
    cats = [("Raw Materials", 38, CYAN, "62.4M", "18%"), ("Salaries", 23, BLUE, "48.6M", "—"), ("Rent & Utilities", 11, VIOLET, "11.3M", "17%"), ("Logistics", 9, GREEN, "9.4M", "18%"), ("Marketing", 7, AMBER, "7.9M", "13%"), ("Other", 12, RED, "25.1M", "18%")]
    c, y = new(9, "Expense Categories", "Reports › Expenses & Tax")
    (x1, w1), (x2, w2) = cols([1, 2])
    ix, iy, iw = card(c, x1, y, w1, YB - y, "Expenses by Category")
    donut(c, ix + iw / 2, iy + 110, 62, [(n, v, col) for n, v, col, *_ in cats], "PKR 164M", "Total")
    for i, (n, v, col, *_ ) in enumerate(cats):
        c.rect(ix, iy + 215 + i * 22 - 4, 8, 8, fill=col, r=2)
        c.text(ix + 14, iy + 215 + i * 22, n, 12, MUT)
        c.text(ix + iw, iy + 215 + i * 22, f"{v}%", 12, TX, True, "r")
    ty = tcard(c, x2, y, w2, 300, "Category & Tax Breakdown", "FY 2026", ["Category", "Amount", "Share", "Tax rate"], [[n, f"PKR {a}", f"{v}%", t] for n, v, col, a, t in cats], right=(1, 2, 3), rh=32)
    for i, (k, v, col) in enumerate([("Input GST claimable", "PKR 18.9M", CYAN), ("WHT deducted", "PKR 3.2M", TX)]):
        bw = (w2 - 12) / 2
        card(c, x2 + i * (bw + 12), y + 312, bw, YB - y - 312, fill=PANEL2)
        c.text(x2 + i * (bw + 12) + 15, y + 340, k, 11, MUT)
        c.text(x2 + i * (bw + 12) + 15, y + 368, v, 19, col, True)
    return c


def s06():
    rows = [["*Karachi Textile Mills", "Raw Materials", "PKR 4,820,000", "Net 30", "4.8", "Active"], ["*Lahore Packaging Co.", "Packaging", "PKR 1,260,000", "Net 15", "4.5", "Active"],
            ["*Faisal Chemicals", "Chemicals", "PKR 2,975,000", "Net 45", "4.1", "On Hold"], ["*Indus Logistics", "Freight", "PKR 640,000", "Net 7", "4.7", "Active"],
            ["*Sialkot Trims & Co.", "Trims", "PKR 385,500", "Net 30", "4.3", "Active"], ["*Pak Energy Solutions", "Utilities", "PKR 1,120,000", "Due", "3.9", "Pending"],
            ["*Multan Yarn Traders", "Raw Materials", "PKR 3,410,000", "Net 30", "4.6", "Active"]]
    return full_table(4, "Vendors & Suppliers", "Contacts › Vendors", "+ New Vendor", [("Active Vendors", "186", "8 new", True), ("Outstanding Payable", "PKR 41.2M", "3.1%", False), ("Avg. Payment Terms", "28 days", "2 days", True)],
                      "Vendor List", "Sorted by activity", ["Vendor", "Category", "Outstanding", "Terms", "Rating", "Status"], rows, right=(2,))


def s07():
    c, y = new(1, "Receivable / Payable", "Aging summary as of 31 Dec 2026")
    for (x, w), (title, tot, col, bk, rows) in zip(cols([1, 1]), [
        ("Accounts Receivable", "PKR 62.8M", CYAN, [31, 17, 8, 4, 3], [["*Al-Noor Traders", "PKR 8.4M", "Pending"], ["*Gulberg Retail", "PKR 5.1M", "Overdue"], ["*Zam Zam Stores", "PKR 3.9M", "Paid"]]),
        ("Accounts Payable", "PKR 41.2M", BLUE, [19, 12, 6, 3, 1], [["*Karachi Textile Mills", "PKR 4.8M", "Pending"], ["*Faisal Chemicals", "PKR 3.0M", "Overdue"], ["*Indus Logistics", "PKR 0.6M", "Paid"]])]):
        ix, iy, iw = card(c, x, y, w, YB - y, title)
        c.text(ix, iy + 14, tot, 24, col, True)
        bw = iw / 5
        for i, (n, b) in enumerate(zip(["Current", "1-30", "31-60", "61-90", "90+"], bk)):
            bx = ix + i * bw + bw / 2
            hh = 90 * b / bk[0]
            c.rect(bx - 15, iy + 150 - hh, 30, hh, fill=[col, col, AMBER, RED, RED][i], r=4)
            c.text(bx, iy + 164, n, 10.5, MUT, False, "m")
            c.text(bx, iy + 180, f"{b}M", 11, TX, False, "m")
        table(c, ix, iy + 196, iw, ["Party", "Amount", "Status"], rows, right=(1,))
    return c


def s08():
    rows = [["RCT-20931", "*Al-Noor Traders", "Bank Transfer", "PKR 1,533,764", "Paid", "24 Oct"], ["RCT-20930", "*Walk-in · POS 2", "Cash", "PKR 18,450", "Paid", "24 Oct"], ["RCT-20929", "*Gulberg Retail", "Cheque", "PKR 642,000", "Pending", "23 Oct"],
            ["RCT-20928", "*Online · Order 7731", "JazzCash", "PKR 9,990", "Paid", "23 Oct"], ["RCT-20927", "*Zam Zam Stores", "Bank Transfer", "PKR 2,210,500", "Overdue", "22 Oct"], ["RCT-20926", "*Walk-in · POS 1", "Card", "PKR 31,200", "Paid", "22 Oct"],
            ["RCT-20925", "*Metro Wholesale", "Bank Transfer", "PKR 874,300", "Paid", "21 Oct"], ["RCT-20924", "*Online · Order 7728", "EasyPaisa", "PKR 4,750", "Paid", "21 Oct"]]
    return full_table(1, "Sales Receipts", "Transaction history", "+ New Receipt", [("Today's Sales", "PKR 3.42M", "12.3%", True), ("Receipts This Month", "PKR 38.9M", "8.4%", True), ("Avg. Ticket", "PKR 64,200", "2.1%", False)],
                      "Recent Transactions", None, ["Receipt", "Customer", "Method", "Amount", "Status", "Date"], rows, right=(3,))


def s09():
    c, y = new(9, "Cash Flow Analytics", "Trailing 8 months + 4-month forecast", "Export", [
        ("Cash on Hand", "PKR 41.0M", "6.2% this month", True), ("Operating Cash Flow", "PKR 11.4M", "14.8%", True), ("Projected (Dec)", "PKR 55.0M", "Forecast", True), ("Burn Runway", "9.4 months", "0.8 mo", True)])
    ix, iy, iw = card(c, X0, y, XW, YB - y, "Cash Flow & Forecast", "dashed = forecast")
    legend(c, X0 + XW - 260, y + 19, [("Cash balance", CYAN), ("Outflows", BLUE)])
    line_chart(c, ix, iy + 4, iw, YB - y - 52, [([24, 27, 22, 30, 34, 31, 38, 41, 44, 47, 52, 55], CYAN), ([19, 21, 20, 24, 26, 25, 28, 30, 33, 35, 38, 40], BLUE)], MON, dash_from=8)
    return c


def s10():
    c, y = new(5, "Multi-currency Reconciliation", "Banking › Reconcile payments in PKR", "Auto-Match")
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Payment Reconciliation", "Auto-converted to PKR", ["Ccy", "Foreign amount", "Rate", "PKR value", "Status"], [
        ["USD", "$ 42,500", "280.15", "*PKR 11,906,375", "Matched"], ["AED", "AED 118,000", "76.30", "*PKR 9,003,400", "Matched"], ["PKR", "—", "1.00", "*PKR 4,250,000", "Matched"], ["USD", "$ 12,800", "280.40", "*PKR 3,589,120", "Review"],
        ["GBP", "£ 6,200", "356.90", "*PKR 2,212,780", "Unmatched"], ["PKR", "—", "1.00", "*PKR 1,860,000", "Matched"], ["EUR", "€ 9,400", "303.10", "*PKR 2,849,140", "Review"]], right=(1, 2, 3), rh=34)
    kv_card(c, x2, y, w2, 210, "Live Exchange Rates", [("USD / PKR", "280.15"), ("AED / PKR", "76.30"), ("GBP / PKR", "356.90"), ("EUR / PKR", "303.10")], "SBP")
    ix, iy, iw = card(c, x2, y + 222, w2, YB - y - 222)
    c.text(ix, iy - 14, "Reconciled", 11, MUT)
    c.bar(ix, iy + 6, iw, 71)
    c.text(ix, iy + 24, "71% · 5 of 7 matched", 12, TX, True)
    c.text(ix, iy + 54, "Unrealised FX gain", 11, MUT)
    c.text(ix, iy + 78, "+ PKR 412,600", 19, GREEN, True)
    return c


def s11():
    c, y = new(9, "Balance Sheet", "As at 31 December 2026")
    specs = [("Assets", [["Cash & Bank", "41,050,000"], ["Accounts Receivable", "62,800,000"], ["Inventory", "86,400,000"], ["Prepaid & Other", "9,150,000"], ["Fixed Assets (net)", "118,300,000"]], ["Total Assets", "317,700,000"]),
             ("Liabilities", [["Accounts Payable", "41,200,000"], ["Short-term Loans", "28,000,000"], ["Tax Payable", "14,650,000"], ["Long-term Debt", "52,000,000"]], ["Total Liabilities", "135,850,000"]),
             ("Equity Tracking", [["Share Capital", "80,000,000"], ["Retained Earnings", "74,810,000"], ["Current Year Profit", "27,040,000"]], ["Total Equity", "181,850,000"])]
    for (x, w), (t, r, tot) in zip(cols([1, 1, 1]), specs):
        yy = tcard(c, x, y, w, YB - y, t, None, ["Account", "PKR"], r, right=(1,), total=tot, rh=32)
    x, w = cols([1, 1, 1])[2]
    c.text(x + 15, yy + 36, "Debt-to-equity", 11, MUT)
    c.bar(x + 15, yy + 56, w - 70, 74)
    c.text(x + w - 15, yy + 56, "0.74", 12, TX, True, "r")
    c.text(x + 15, yy + 84, "Current ratio", 11, MUT)
    c.bar(x + 15, yy + 104, w - 70, 80, GREEN)
    c.text(x + w - 15, yy + 104, "2.1", 12, TX, True, "r")
    return c


def s12():
    rows = [["PO-4410", "*Karachi Textile Mills", "PKR 4,820,000", "GRN-3321", (100, None, "100%"), "Received"], ["PO-4411", "*Lahore Packaging Co.", "PKR 1,260,000", "GRN-3322", (60, None, "60%"), "Partial"],
            ["PO-4412", "*Faisal Chemicals", "PKR 2,975,000", "—", (0, None, "0%"), "Open"], ["PO-4413", "*Sialkot Trims & Co.", "PKR 385,500", "GRN-3324", (100, None, "100%"), "Received"],
            ["PO-4414", "*Multan Yarn Traders", "PKR 3,410,000", "GRN-3325", (35, None, "35%"), "Partial"], ["PO-4415", "*Indus Logistics", "PKR 640,000", "—", (0, None, "0%"), "Open"]]
    return full_table(3, "Purchase Orders & GRN", "Purchases › Orders", "+ New PO", [("Open POs", "24", "3 this week", True), ("Pending GRNs", "11", "2", False), ("PO Value (MTD)", "PKR 28.6M", "9.7%", True), ("On-time Delivery", "93%", "1.5%", True)],
                      "Purchase Orders", "Goods received notes linked", ["PO #", "Vendor", "Value", "GRN", "Received", "Status"], rows, right=(2,))


def s13():
    c, y = new(7, "Asset Management", "Depreciation tracker", "+ Add Asset")
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Fixed Asset Register", "Reducing balance", ["Asset", "Class", "Cost", "Acc. Depn.", "Net book value", "Rate"], [
        ["*Dyeing Machine DM-200", "Machinery", "PKR 24.0M", "PKR 9.6M", "PKR 14.4M", "10%"], ["*Delivery Truck Hino 500", "Vehicles", "PKR 12.5M", "PKR 5.0M", "PKR 7.5M", "20%"],
        ["*Warehouse Racking", "Fixtures", "PKR 3.8M", "PKR 1.14M", "PKR 2.66M", "10%"], ["*Office Laptops (24)", "IT Equipment", "PKR 7.2M", "PKR 4.32M", "PKR 2.88M", "30%"], ["*Karachi Factory", "Property", "PKR 90.0M", "PKR 18.0M", "PKR 72.0M", "5%"]],
        right=(2, 3, 4, 5), rh=38)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Depreciation by Quarter")
    bar_chart(c, ix, iy + 4, iw, 230, ["Q1", "Q2", "Q3", "Q4"], [([1.1, 1.2, 1.2, 1.3], CYAN)])
    c.text(ix, iy + 262, "Annual charge", 11, MUT)
    c.text(ix, iy + 286, "PKR 4.8M", 19, CYAN, True)
    return c


def s14():
    rows = [["14:32:08", "*Bilal Y.", "Posted journal JV-2210", "Journal", "192.168.1.14"], ["14:11:45", "*Sana R.", "Approved invoice INV-1842", "Invoice", "192.168.1.22"], ["13:58:20", "*Usman K.", "Changed credit limit: Gulberg Retail", "Customer", "10.0.0.8"],
            ["13:40:02", "*Ayesha M.", "Exported Balance Sheet (PDF)", "Report", "192.168.1.31"], ["12:15:51", "*Bilal Y.", "Deleted draft PO-4409", "Purchase", "192.168.1.14"], ["11:47:13", "*System", "Bank feed imported: 48 transactions", "Banking", "—"],
            ["10:22:39", "*Sana R.", "Role changed: Accountant to Manager", "Users", "192.168.1.22"], ["09:05:17", "*Usman K.", "Signed in (2FA verified)", "Auth", "10.0.0.8"]]
    return full_table(10, "Audit Log", "Settings › Activity trail · 24 Oct 2026", "Export CSV", None, "Activity Trail", "Immutable · retained 7 years", ["Time", "User", "Action", "Module", "IP address"], rows)


def s15():
    c, y = new(5, "Bank Feed", "Banking › Statement import", "Import Statement")
    for (x, w), (n, v, s) in zip(cols([1, 1, 1]), [("HBL — Current ****4821", "PKR 18,240,500", "Connected"), ("Meezan Bank ****0192", "PKR 14,905,000", "Connected"), ("UBL Business ****7730", "PKR 7,904,500", "Syncing")]):
        card(c, x, y, w, 92)
        c.text(x + 15, y + 20, n, 12.5, TX, True)
        c.badge(x + w - 15, y + 20, s, "r")
        c.text(x + 15, y + 52, v, 21, TX, True)
        c.text(x + 15, y + 76, "Last sync 2 min ago", 11, MUT)
    y += 104
    tcard(c, X0, y, XW, YB - y, "Imported Statement Lines", "Drag a .CSV / .OFX file to import", ["Date", "Description", "Amount (PKR)", "Matched to", "Status"], [
        ["24 Oct", "*IBFT FROM AL-NOOR TRADERS", "+ 1,533,764", "INV-1842", "Matched"], ["24 Oct", "*K-ELECTRIC BILL 09/26", "− 412,300", "Utilities", "Matched"], ["23 Oct", "*CHQ DEP 004412", "+ 642,000", "Suggest: INV-1839", "Review"],
        ["23 Oct", "*SALARY TRF OCT", "− 6,480,000", "Payroll Oct", "Matched"], ["22 Oct", "*UNKNOWN CREDIT 88213", "+ 95,000", "No match", "Unmatched"]], right=(2,), rh=34)
    return c


def s16():
    return full_table(8, "Tax & FBR Compliance", "Tax › Filings summary", "File Return", [("Output Sales Tax", "PKR 56.6M", "18.4%", True), ("Input Tax Credit", "PKR 41.9M", "12.0%", True), ("Net Payable to FBR", "PKR 14.65M", "Sep return", True), ("Compliance Score", "98%", "Active filer", True)],
                      "Filing Calendar", "NTN 4567890-1 · Active Taxpayer", ["Return", "Due date", "Amount", "Status"], [
        ["*Sales Tax Return (Sep)", "15 Oct 2026", "PKR 14.65M", "Filed"], ["*Sales Tax Return (Oct)", "15 Nov 2026", "PKR 16.20M", "Due Soon"], ["*WHT Statement (Q3)", "30 Oct 2026", "PKR 3.20M", "Due Soon"],
        ["*Income Tax Advance", "15 Dec 2026", "PKR 4.80M", "Upcoming"], ["*Annual Income Tax Return", "31 Dec 2026", "PKR 19.70M", "Upcoming"]], right=(2,))


def s17():
    rows = [["*Ahmed Raza", "Production Manager", "250,000", "30,000", "38,500", "*241,500", "Approved"], ["*Sana Riaz", "Senior Accountant", "180,000", "20,000", "24,200", "*175,800", "Approved"], ["*Usman Khan", "Sales Lead", "150,000", "45,000", "22,000", "*173,000", "Approved"],
            ["*Ayesha Malik", "HR Executive", "110,000", "10,000", "11,400", "*108,600", "Approved"], ["*Bilal Younus", "Systems Engineer", "220,000", "15,000", "31,800", "*203,200", "Approved"], ["*Hina Shahid", "Warehouse Supervisor", "95,000", "12,000", "8,900", "*98,100", "Approved"]]
    return full_table(6, "Payroll — October 2026", "Salary sheet", "Process Payroll", [("Gross Payroll", "PKR 6.48M", "3.2%", True), ("Deductions", "PKR 0.94M", "1.1%", False), ("Net Payable", "PKR 5.54M", "3.6%", True), ("Employees", "142", "4 new", True)],
                      "Salary Sheet", "Pay date 28 Oct", ["Employee", "Designation", "Basic", "Allowances", "Tax & EOBI", "Net pay", "Status"], rows, right=(2, 3, 4, 5))


def s18():
    d = [("Al-Noor Traders", 5000000, 3800000, "Paid"), ("Gulberg Retail", 3000000, 2950000, "Overdue"), ("Zam Zam Stores", 4000000, 1500000, "Paid"), ("Metro Wholesale", 8000000, 6900000, "Pending"), ("Crescent Mart", 2500000, 600000, "Paid"), ("Lakson Distributors", 6000000, 5700000, "Overdue")]
    rows = [[f"*{n}", f"PKR {lim:,}", f"PKR {bal:,}", (bal * 100 // lim, RED if bal * 100 // lim > 90 else AMBER if bal * 100 // lim > 70 else None, f"{bal * 100 // lim}%"), s] for n, lim, bal, s in d]
    return full_table(4, "Customer Ledger", "Contacts › Credit limits", "Export", [("Total Receivable", "PKR 62.8M", "5.2%", False), ("Credit Extended", "PKR 98.5M", "Limit total", True), ("Avg. Days to Pay", "34", "3 days", True)],
                      "Credit Limit Utilisation", "Alert above 90%", ["Customer", "Credit limit", "Balance", "Utilisation", "Status"], rows, right=(1, 2))


def s19():
    c, y = new(2, "Warehouse Analytics", "Stock movement & transfers", "+ New Transfer")
    (x1, w1), (x2, w2) = cols([1, 1])
    ix, iy, iw = card(c, x1, y, w1, YB - y, "Movement by Warehouse")
    legend(c, x1 + w1 - 150, y + 19, [("Inbound", CYAN), ("Outbound", BLUE)])
    bar_chart(c, ix, iy + 4, iw, YB - y - 52, ["Karachi", "Lahore", "Islamabad", "Multan", "F'bad"], [([42, 28, 19, 12, 9], CYAN), ([36, 31, 16, 10, 11], BLUE)], unit="k")
    tcard(c, x2, y, w2, 290, "Recent Transfers", None, ["Ref", "Route", "Items", "Status"], [["TRF-1180", "*Karachi → Lahore", "Cotton ×200", "In Transit"], ["TRF-1179", "*Lahore → Isb.", "Packaging ×1,200", "Completed"],
                                                                                      ["TRF-1178", "*Karachi → Isb.", "Thread ×300", "Completed"], ["TRF-1177", "*Isb. → Karachi", "Returns ×85", "Pending"]], rh=34)
    for i, (k, v, col) in enumerate([("Units moved (30d)", "124,300", TX), ("Avg. transfer time", "1.8 days", CYAN)]):
        bw = (w2 - 12) / 2
        card(c, x2 + i * (bw + 12), y + 302, bw, YB - y - 302, fill=PANEL2)
        c.text(x2 + i * (bw + 12) + 15, y + 330, k, 11, MUT)
        c.text(x2 + i * (bw + 12) + 15, y + 358, v, 19, col, True)
    return c


def s20():
    d = [("Production", [(30, 31.5), (32, 33.4), (35, 34.1), (38, 41.2)]), ("Sales & Marketing", [(8, 7.2), (9, 9.9), (9, 8.6), (11, 12.4)]), ("Administration", [(6, 5.8), (6, 6.1), (6, 5.9), (7, 7.2)]),
         ("Logistics", [(5, 5.4), (5.5, 5.2), (6, 6.7), (7, 7.9)]), ("IT & Systems", [(2, 1.7), (2, 2.3), (2.5, 2.2), (3, 2.8)])]
    c, y = new(9, "Budget Planning", "Monthly & yearly · FY 2026", "Edit Budget", [("Annual Budget", "PKR 215.0M", "Approved", True), ("Actual Spend", "PKR 221.8M", "3.2% over", False), ("Departments On Track", "3 of 5", None, True)])
    ix, iy, iw = card(c, X0, y, XW, YB - y, "Budget vs Actuals", "PKR millions · Variance (Actual − Budget)")
    dw = 200
    cw = (iw - dw) / 4
    for q in range(4):
        c.text(ix + dw + cw * (q + 1) - 8, iy + 8, f"Q{q + 1}", 10, MUT, True, "r")
    for r, (n, qs) in enumerate(d):
        yy = iy + 22 + r * 62
        c.text(ix + 8, yy + 31, n, 12.5, TX, True)
        for q, (b, a) in enumerate(qs):
            v = (a - b) / b * 100
            col = RED if v > 3 else GREEN if v < -3 else AMBER
            x = ix + dw + q * cw
            c.rect(x + 3, yy + 3, cw - 6, 56, fill=mix(col, PANEL, .15), r=6)
            c.text(x + cw - 14, yy + 20, f"{b:g} → {a:g}", 10.5, MUT, False, "r")
            c.text(x + cw - 14, yy + 42, f"{v:+.1f}%", 14, col, True, "r")
    return c


def s21():
    c, y = new(1, "Invoices", "Billing › All invoices", "+ New Invoice", [("Outstanding", "PKR 5.6M", "4 invoices", True), ("Overdue", "PKR 2.85M", "2 invoices", False), ("Paid (30d)", "PKR 18.4M", "11.2%", True)])
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Invoices", "All · Draft · Pending · Overdue · Paid", ["Invoice", "Customer", "Amount", "Due", "Status", "Reminder"], [
        ["INV-1842", "*Al-Noor Traders", "PKR 1,533,764", "15 Nov", "Pending", ""], ["INV-1841", "*Gulberg Retail", "PKR 642,000", "02 Oct", "Overdue", "Sent"], ["INV-1840", "*Metro Wholesale", "PKR 874,300", "28 Oct", "Paid", ""],
        ["INV-1839", "*Zam Zam Stores", "PKR 2,210,500", "20 Sep", "Overdue", "2nd sent"], ["INV-1838", "*Crescent Mart", "PKR 355,000", "30 Oct", "Pending", ""], ["INV-1837", "*Lakson Distributors", "PKR 1,980,000", "12 Oct", "Paid", ""]], right=(2,), rh=36)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Recurring Invoices")
    for i, (n, f, d) in enumerate([("Monthly Retainer — Al-Noor", "PKR 450,000 / month", "01 Nov"), ("Quarterly Support — Metro", "PKR 1.2M / quarter", "01 Jan"), ("Annual License — Crescent", "PKR 3.6M / year", "15 Mar")]):
        yy = iy + i * 62
        c.line([(ix, yy), (ix + iw, yy)], LINE)
        c.text(ix, yy + 16, c.fit(n, iw - 70, 12, True), 12, TX, True)
        c.badge(ix + iw, yy + 16, "Active", "r")
        c.text(ix, yy + 40, f"{f} · next {d}", 11, MUT)
    c.text(ix, iy + 215, "Auto payment reminders", 11, MUT)
    c.text(ix, iy + 237, "3 days before · on due date · +7 days", 12)
    return c


def s22():
    rows = [["QT-0721", "*Karachi Garments Ltd", "PKR 3,450,000", "30 Oct", "Pending", ""], ["QT-0720", "*Hyderabad Retail", "PKR 880,000", "28 Oct", "Approved", "Convert to invoice  →"], ["QT-0719", "*Faisalabad Mills", "PKR 6,200,000", "25 Oct", "Pending", ""],
            ["QT-0718", "*Peshawar Traders", "PKR 415,000", "20 Oct", "Cancelled", ""], ["QT-0717", "*Rawalpindi Mart", "PKR 1,275,000", "18 Oct", "Approved", "Convert to invoice  →"], ["QT-0716", "*Quetta Supplies", "PKR 2,060,000", "15 Oct", "Draft", ""]]
    return full_table(1, "Estimates & Quotations", "Billing › Quotes", "+ New Quote", [("Open Quotes", "PKR 12.4M", "9 quotes", True), ("Win Rate", "64%", "5.0%", True), ("Avg. Time to Accept", "3.2 days", "0.6 d", True)],
                      "Quotations", "Valid for 30 days", ["Quote", "Customer", "Value", "Expires", "Status", ""], rows, right=(2,))


def s23():
    c, y = new(2, "Item Catalog", "Inventory › Products & pricing", "+ Add Item")
    (x1, w1), (x2, w2) = cols([3, 1])
    tcard(c, x1, y, w1, YB - y, "Products", "1,248 SKUs", ["SKU", "Item", "Category", "Cost", "Price", "Margin", "Status"], [
        ["XS-1001", "*Cotton Fabric Roll", "Textile", "PKR 14,200", "PKR 18,500", "23%", "Active"], ["XS-1002", "*Stitching Thread Box", "Supplies", "PKR 1,700", "PKR 2,350", "28%", "Active"], ["XS-1003", "*Packaging Carton L", "Packaging", "PKR 290", "PKR 410", "29%", "Active"],
        ["XS-1005", "*Zipper YKK 20in", "Trims", "PKR 41", "PKR 65", "37%", "Active"], ["XS-1006", "*Polyester Yarn Cone", "Textile", "PKR 1,420", "PKR 1,980", "28%", "Active"], ["XS-1007", "*Label Tag Roll", "Packaging", "PKR 380", "PKR 540", "30%", "Active"]], right=(3, 4, 5), rh=38)
    kv_card(c, x2, y, w2, 220, "Categories", [("Textile", "412"), ("Supplies", "188"), ("Packaging", "236"), ("Trims", "301"), ("Chemicals", "111")])
    return c


def s24():
    c, y = new(9, "Journal Entries & General Ledger", "Accounting › Journals", "+ New Journal")
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Journal Entries", "Debits = Credits", ["Ref", "Date", "Narration", "Account", "Debit", "Credit"], [
        ["*JV-2210", "24 Oct", "Salary — October", "Salaries Expense", "6,480,000", ""], ["", "", "", "Bank — HBL 4821", "", "6,480,000"], ["*JV-2211", "24 Oct", "Depreciation", "Depreciation Exp.", "400,000", ""],
        ["", "", "", "Accum. Depreciation", "", "400,000"], ["*JV-2212", "23 Oct", "Accrued utilities", "Utilities Expense", "412,300", ""], ["", "", "", "Accrued Liabilities", "", "412,300"]],
        right=(4, 5), total=["", "", "", "Total", "7,292,300", "7,292,300"])
    kv_card(c, x2, y, w2, 190, "General Ledger", [("1010 Cash & Bank", "41,050,000"), ("1200 Receivables", "62,800,000"), ("1400 Inventory", "86,400,000"), ("2100 Payables", "(41,200,000)"), ("4000 Sales", "(314,320,000)")], "PKR")
    return c


def s25():
    c, y = new(9, "Expense Tracking", "Expenses › Receipts", "+ Add Expense", [("Expenses (MTD)", "PKR 4.92M", "6.3%", False), ("Awaiting Approval", "12", "PKR 612K", True)])
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Recent Expenses", None, ["Merchant", "Category", "Date", "Amount", "Status"], [
        ["*K-Electric", "Utilities", "24 Oct", "PKR 412,300", "Approved"], ["*Shell Fuel Card", "Fuel", "23 Oct", "PKR 86,500", "Pending"], ["*Daraz Office Supplies", "Office", "22 Oct", "PKR 29,800", "Approved"],
        ["*Facebook Ads", "Marketing", "21 Oct", "PKR 215,000", "Pending"], ["*Client Lunch — Avari", "Meals", "20 Oct", "PKR 18,400", "Review"]], right=(3,), rh=38)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Upload Receipts")
    c.d.rounded_rectangle([ix * S, (iy + 4) * S, (ix + iw) * S, (iy + 140) * S], radius=12 * S, outline="#2c4d7c", width=S)
    c.line([(ix + iw / 2, iy + 78), (ix + iw / 2, iy + 46)], CYAN, 3)
    c.line([(ix + iw / 2 - 10, iy + 56), (ix + iw / 2, iy + 44), (ix + iw / 2 + 10, iy + 56)], CYAN, 3)
    c.text(ix + iw / 2, iy + 96, "Drop receipts here", 13, TX, True, "m")
    c.text(ix + iw / 2, iy + 116, "OCR auto-fills vendor, date, amount", 10.5, MUT, False, "m")
    c.text(ix, iy + 170, "Scanned this month", 11, MUT)
    c.text(ix, iy + 194, "184 receipts", 20, CYAN, True)
    return c


def s26():
    c, y = new(9, "Cash Flow Statement", "Reports › Financial statements")
    (x1, w1), (x2, w2) = cols([2, 1])
    tcard(c, x1, y, w1, YB - y, "Cash Flow Statement", "Year ended 31 Dec 2026 · PKR", ["Particulars", "Amount"], [
        ["*Operating Activities", ""], ["Net profit", "68,040,000"], ["Depreciation", "4,800,000"], ["Increase in receivables", "(9,300,000)"], ["Increase in inventory", "(7,100,000)"], ["Increase in payables", "6,400,000"],
        ["*Investing Activities", ""], ["Purchase of machinery", "(24,000,000)"], ["*Financing Activities", ""], ["Loan proceeds", "20,000,000"], ["Dividends paid", "(5,000,000)"]],
        right=(1,), total=["Net increase in cash", "53,840,000"], rh=31)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Net Cash by Activity")
    bar_chart(c, ix, iy + 4, iw, 240, ["Operating", "Investing", "Financing"], [([62.8, 24.0, 15.0], CYAN)])
    c.text(ix, iy + 280, "Closing cash", 11, MUT)
    c.text(ix, iy + 304, "PKR 41.05M", 19, CYAN, True)
    return c


def s27():
    rows = [["1010", "*Cash & Bank", "41,050,000", ""], ["1200", "*Accounts Receivable", "62,800,000", ""], ["1400", "*Inventory", "86,400,000", ""], ["1600", "*Fixed Assets", "118,300,000", ""], ["2100", "*Accounts Payable", "", "41,200,000"],
            ["2300", "*Tax Payable", "", "14,650,000"], ["3000", "*Share Capital", "", "80,000,000"], ["4000", "*Sales Revenue", "", "314,320,000"], ["5000", "*Cost of Goods Sold", "166,400,000", ""], ["6000", "*Operating Expenses", "79,880,000", ""]]
    return full_table(9, "Trial Balance", "Reports › As at 31 Dec 2026", "Export", None, "Trial Balance", "Debits equal credits", ["Code", "Account", "Debit (PKR)", "Credit (PKR)"], rows, right=(2, 3), total=["", "Total", "554,530,000", "554,530,000"])


def aging(active, title, sub, parties):
    rows = [[f"*{n}"] + [f"{v:.1f}" if v else "—" for v in b] + [f"*{sum(b):.1f}"] for n, b in parties]
    tot = ["Total"] + [f"{sum(p[1][i] for p in parties):.1f}" for i in range(5)] + [f"{sum(sum(p[1]) for p in parties):.1f}"]
    return full_table(active, title, sub, "Export", None, title, "PKR millions", ["Party", "Current", "1-30", "31-60", "61-90", "90+", "Total"], rows, right=(1, 2, 3, 4, 5, 6), total=tot)


def s28():
    return aging(9, "AR Aging Summary", "Accounts receivable as of 31 Dec 2026", [("Al-Noor Traders", [4.2, 2.1, 1.4, .7, 0]), ("Gulberg Retail", [1.2, 1.8, 1.3, .6, .2]), ("Metro Wholesale", [6.1, 2.2, .9, 0, 0]),
                                                                                    ("Zam Zam Stores", [2.4, 1.7, 1.1, 1.3, .9]), ("Crescent Mart", [1.9, .6, 0, 0, 0]), ("Lakson Distributors", [3.8, 2.9, 1.6, .7, .4])])


def s29():
    return aging(9, "AP Aging Summary", "Accounts payable as of 31 Dec 2026", [("Karachi Textile Mills", [2.8, 1.2, .5, .3, 0]), ("Lahore Packaging Co.", [.9, .3, 0, 0, 0]), ("Faisal Chemicals", [.8, .9, .7, .4, .2]),
                                                                                    ("Indus Logistics", [.5, .1, 0, 0, 0]), ("Multan Yarn Traders", [1.9, 1.0, .4, .1, 0]), ("Pak Energy Solutions", [.7, .4, 0, 0, 0])])


def s30():
    c, y = new(5, "Multi-Currency", "Banking › Exchange rates", "+ Add Currency")
    (x1, w1), (x2, w2) = cols([2, 1])
    cur = [("USD", "US Dollar", "280.15", [277, 278, 278.5, 279, 279.6, 280.15]), ("AED", "UAE Dirham", "76.30", [75.5, 75.8, 76, 76.1, 76.2, 76.3]), ("GBP", "Pound Sterling", "356.90", [360, 359, 358, 357.5, 357.2, 356.9]),
           ("EUR", "Euro", "303.10", [300, 301, 301.5, 302, 302.6, 303.1]), ("SAR", "Saudi Riyal", "74.70", [74.1, 74.2, 74.4, 74.5, 74.6, 74.7])]
    yy = tcard(c, x1, y, w1, YB - y, "Exchange Rates", "1 unit → PKR · 6-day trend", ["Code", "Currency", "Rate", "Trend", "Status"], [[a, f"*{b}", f"*{r}", "", "Active"] for a, b, r, _ in cur], right=(2,), rh=44)
    ix, iy, iw = x1 + 15, y + 38 + 22, w1 - 30
    for i, (_, _, _, v) in enumerate(cur):
        lo, hi = min(v), max(v)
        px = ix + iw * .62
        c.line([(px + k * 16, iy + i * 44 + 32 - 20 * (val - lo) / (hi - lo)) for k, val in enumerate(v)], CYAN, 2)
    fields_card(c, x2, y, w2, 250, "Currency Settings", [("Base currency", "PKR — Pakistani Rupee"), ("Rate source", "State Bank of Pakistan · daily")])
    c.text(x2 + 15, y + 150, "Unrealised FX gain/loss", 11, MUT)
    c.text(x2 + 15, y + 176, "+ PKR 412,600", 19, GREEN, True)
    return c


def s31():
    c, y = new(1, "Point of Sale", "POS 2 · Cashier: Hina S. · synced with inventory", "Close Shift")
    (x1, w1), (x2, w2) = cols([2, 1])
    prods = [("Cotton Fabric", "18,500"), ("Thread Box", "2,350"), ("Zipper 20in", "65"), ("Label Roll", "540"), ("Carton L", "410"), ("Yarn Cone", "1,980"), ("Dye 5L", "7,800"), ("Gift Wrap", "150")]
    pw = (w1 - 30) / 4
    for i, (n, p) in enumerate(prods):
        px, py = x1 + (i % 4) * (pw + 10), y + (i // 4) * 130
        card(c, px, py, pw, 120, fill=PANEL2)
        c.grad(px + 10, py + 10, pw - 20, 50, "#0b4a6a", "#1c3c7a", r=7)
        c.text(px + 12, py + 78, n, 12.5, TX, True)
        c.text(px + 12, py + 98, f"PKR {p}", 11, MUT)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Current Sale")
    c.badge(x2 + w2 - 15, y + 19, "Pending", "r")
    ty = table(c, ix, iy, iw, ["Item", "Qty", "Total"], [["Cotton Fabric", "2", "37,000"], ["Thread Box", "5", "11,750"], ["Zipper 20in", "40", "2,600"]], right=(1, 2))
    c.line([(ix, ty + 8), (ix + iw, ty + 8)], LINE)
    c.text(ix, ty + 24, "GST 18%", 11.5, MUT)
    c.text(ix + iw, ty + 24, "PKR 9,171", 12, TX, True, "r")
    c.text(ix, ty + 60, "PKR 60,121", 25, CYAN, True)
    c.grad(ix, ty + 90, iw, 34, CYAN, BLUE, r=8)
    c.text(ix + iw / 2, ty + 107, "Charge · Cash / Card / JazzCash", 11.5, "#04121f", True, "m")
    return c


def s32():
    c, y = new(10, "Users & Roles", "Settings › Team access", "+ Invite User")
    (x1, w1), (x2, w2) = cols([3, 2])
    tcard(c, x1, y, w1, YB - y, "Team Members", "5 of 25 seats", ["Name", "Email", "Role", "Last active", "Status"], [
        ["*Bilal Younus", "bilal@xelora.pk", "Owner", "Just now", "Active"], ["*Sana Riaz", "sana@xelora.pk", "Accountant", "12 min ago", "Active"], ["*Usman Khan", "usman@xelora.pk", "Sales Manager", "2 h ago", "Active"],
        ["*Ayesha Malik", "ayesha@xelora.pk", "HR", "Yesterday", "Active"], ["*Hina Shahid", "hina@xelora.pk", "Cashier", "Yesterday", "On Hold"]], rh=40)
    ix, iy, iw = card(c, x2, y, w2, YB - y, "Permission Matrix")
    perms = [("View reports", 1, 1, 1, 0), ("Post journals", 1, 1, 0, 0), ("Create invoices", 1, 1, 1, 0), ("Approve payments", 1, 1, 0, 0), ("Use POS", 1, 0, 1, 1), ("Manage users", 1, 0, 0, 0)]
    cw = (iw - 150) / 4
    for j, h in enumerate(["Owner", "Acct.", "Sales", "Cashier"]):
        c.text(ix + 150 + cw * (j + .5), iy + 8, h.upper(), 10, MUT, True, "m")
    for r, (n, *v) in enumerate(perms):
        yy = iy + 22 + r * 34
        c.line([(ix, yy), (ix + iw, yy)], LINE)
        c.text(ix, yy + 17, n, 12)
        for j, ok in enumerate(v):
            if ok:
                c.check(ix + 150 + cw * (j + .5), yy + 17)
            else:
                c.text(ix + 150 + cw * (j + .5), yy + 17, "—", 12, MUT, False, "m")
    return c


def s33():
    c, y = new(10, "Company Settings", "Settings › Profile & tax", "Save Changes")
    (x1, w1), (x2, w2) = cols([1, 1])
    fields_card(c, x1, y, w1, YB - y, "Company Profile", [("Legal name", "Xelora Systems (Pvt) Ltd"), ("NTN", "4567890-1"), ("STRN", "17-00-2345-001-91"), ("Address", "Office 12, Clifton Block 5, Karachi 75600"), ("Fiscal year", "1 Jan – 31 Dec")], gap=58)
    fields_card(c, x2, y, w2, YB - y, "Tax Settings", [("Base currency", "PKR — Pakistani Rupee"), ("Default sales tax (GST)", "18% · FBR registered"), ("Withholding tax (WHT)", "Enabled — 4.5% on supplies"), ("Invoice numbering", "INV-2026-#####")], gap=58)
    c.badge(x2 + 15, y + 262, "Active")
    c.text(x2 + 80, y + 262, "FBR e-invoicing integration", 11.5, MUT)
    return c


SCREENS = [("acc_01_dashboard", s01), ("acc_02_invoice", s02), ("acc_03_inventory", s03), ("acc_04_profit_loss", s04), ("acc_05_expenses_tax", s05),
           ("acc_06_vendors", s06), ("acc_07_receivable_payable", s07), ("acc_08_sales_receipts", s08), ("acc_09_cashflow", s09), ("acc_10_reconciliation", s10),
           ("acc_11_balance_sheet", s11), ("acc_12_purchase_orders", s12), ("acc_13_assets", s13), ("acc_14_audit_log", s14), ("acc_15_bank_feed", s15),
           ("acc_16_tax_fbr", s16), ("acc_17_payroll", s17), ("acc_18_customer_ledger", s18), ("acc_19_warehouse", s19), ("acc_20_budget", s20),
           ("acc_21_invoice_list", s21), ("acc_22_estimates", s22), ("acc_23_item_catalog", s23), ("acc_24_journal_ledger", s24), ("acc_25_expense_receipts", s25),
           ("acc_26_cash_flow_statement", s26), ("acc_27_trial_balance", s27), ("acc_28_ar_aging", s28), ("acc_29_ap_aging", s29), ("acc_30_multi_currency", s30),
           ("acc_31_pos", s31), ("acc_32_users_roles", s32), ("acc_33_company_settings", s33)]


def main():
    OUT.mkdir(exist_ok=True)
    for name, fn in SCREENS:
        fn().save(OUT / f"{name}.png")
        print("saved", name)


if __name__ == "__main__":
    main()
