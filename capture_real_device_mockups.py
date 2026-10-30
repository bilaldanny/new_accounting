"""Takes real screenshots of the running app with Playwright at four device sizes, then frames them as device mockups.

1. Captures every page in PAGES at mobile 375x812, tablet 768x1024, laptop 1366x768 and desktop 1920x1080
   -> real_device_mockups/raw/<page>_<device>.png (genuine browser screenshots, no drawing).
2. Frames those screenshots in phone / tablet / laptop / monitor frames, alone and combined, 1200x630
   -> real_device_mockups/*.png

Environment (all optional):
  BASE_URL      default http://127.0.0.1:8099   (the app; `php artisan serve --port=8099`)
  APP_EMAIL / APP_PASSWORD   the account to sign in with
  SKIP_CAPTURE=1             only rebuild the framed images from the existing raw shots

Run: python capture_real_device_mockups.py
"""
import os
from pathlib import Path

from PIL import Image, ImageDraw
from playwright.sync_api import sync_playwright

import generate_device_mockups as dev
from generate_device_mockups import Z, S, browser, laptop, monitor, phone, put, put3d
from generate_pure_mockups import bgscene, rounded

ROOT = Path(__file__).parent
OUT = ROOT / "real_device_mockups"
RAW = OUT / "raw"

BASE_URL = os.getenv("BASE_URL", "http://127.0.0.1:8099").rstrip("/")
EMAIL = os.getenv("APP_EMAIL", "history-admin@demo.invalid")
PASSWORD = os.getenv("APP_PASSWORD", "Showcase#2026")

# name: (width, height, device scale factor, is_mobile)
VIEWPORTS = {"mobile": (375, 812, 2, True), "tablet": (768, 1024, 2, True), "laptop": (1366, 768, 2, False), "desktop": (1920, 1080, 1, False)}
PAGES = [("dashboard", "/dashboard"), ("pos", "/sell/pos/add"), ("invoices", "/sell"), ("expenses", "/expense"), ("customers", "/customer"),
         ("inventory", "/product"), ("stock-report", "/report/stock"), ("profit-loss", "/report/profit-loss"), ("balance-sheet", "/report/balance-sheet"), ("purchases", "/purchase")]

SETTLED = "() => !/Loading|Checking|Preparing/.test(document.body.innerText)"
TOUR_OFF = "for (let i = 1; i <= 30; i++) { localStorage.setItem('app-tour-seen:' + i, '1'); }"


def capture():
    RAW.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser_ = p.chromium.launch(channel="chrome", headless=True)
        state = RAW / "_state.json"
        ctx = browser_.new_context(viewport={"width": 1366, "height": 768})
        page = ctx.new_page()
        page.goto(f"{BASE_URL}/login", wait_until="domcontentloaded")
        page.fill('input[name="email"]', EMAIL)
        page.fill('input[name="password"]', PASSWORD)
        page.click("button.auth-submit-btn")
        page.wait_for_url(lambda u: "/login" not in u, timeout=60000)
        ctx.storage_state(path=str(state))
        ctx.close()

        for device, (w, h, dsf, mobile) in VIEWPORTS.items():
            ctx = browser_.new_context(viewport={"width": w, "height": h}, device_scale_factor=dsf, is_mobile=mobile, has_touch=mobile, storage_state=str(state))
            ctx.add_init_script(TOUR_OFF)
            page = ctx.new_page()
            for name, path in PAGES:
                page.goto(f"{BASE_URL}{path}", wait_until="domcontentloaded")
                try:
                    page.wait_for_load_state("networkidle", timeout=30000)
                except Exception:
                    pass
                page.wait_for_timeout(6000)
                try:
                    page.wait_for_function(SETTLED, timeout=60000)
                except Exception:
                    pass
                page.wait_for_timeout(2500)
                page.screenshot(path=str(RAW / f"{name}_{device}.png"))
                print("captured", name, device)
            ctx.close()
        browser_.close()
        state.unlink(missing_ok=True)


# ---------- framing ----------
def tablet_portrait(screen, w):
    b = w * .045
    ow, oh = Z(w), Z(w * 1.333)
    img = Image.new("RGBA", (ow, oh), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([0, 0, ow - 1, oh - 1], radius=Z(w * .06), fill=(14, 18, 27, 255), outline=(70, 84, 110, 255), width=S)
    img.alpha_composite(rounded(dev.cover(screen, ow - 2 * Z(b), oh - 2 * Z(b)), Z(w * .025)), (Z(b), Z(b)))
    d.ellipse([ow / 2 - 3, Z(b / 2) - 3, ow / 2 + 3, Z(b / 2) + 3], fill=(40, 50, 70, 255))
    return img


def load(page):
    return {d: Image.open(RAW / f"{page}_{d}.png").convert("RGB") for d in VIEWPORTS}


def s_laptop(c, s):
    put(c, laptop(s["laptop"], 860), 150, 80, 880)


def s_desktop(c, s):
    put(c, monitor(s["desktop"], 840), 180, 30, 840)


def s_phone(c, s):
    put(c, phone(s["mobile"], 230), 470, 22, 260)


def s_tablet(c, s):
    put(c, tablet_portrait(s["tablet"], 430), 385, 22, 430)


def l1(c, s):
    put(c, laptop(s["laptop"], 760), 130, 90, 770)
    put(c, phone(s["mobile"], 170), 800, 250, 190)


def l2(c, s):
    put(c, monitor(s["desktop"], 720), 130, 50, 720)
    put(c, phone(s["mobile"], 160), 880, 240, 180)


def l3(c, s):
    put(c, monitor(s["desktop"], 660), 60, 60, 660)
    put(c, tablet_portrait(s["tablet"], 230), 740, 150, 230)
    put(c, phone(s["mobile"], 150), 990, 250, 170)


def l4(c, s):
    put(c, laptop(s["laptop"], 640), 70, 110, 650)
    put(c, tablet_portrait(s["tablet"], 240), 720, 140, 240)
    put(c, phone(s["mobile"], 150), 970, 200, 170)


def l5(c, s):
    put(c, laptop(s["laptop"], 700), 250, 80, 710)
    put(c, phone(s["mobile"], 140), 100, 260, 160)
    put(c, phone(s["mobile"], 140), 940, 260, 160)


def l6(c, s):
    put3d(c, laptop(s["laptop"], 760), 580, 320, 760, yaw=-24, pitch=5, thickness=7)
    put3d(c, phone(s["mobile"], 150), 1000, 420, 165, yaw=-24, pitch=5, thickness=6)


def l7(c, s):
    put(c, browser(s["desktop"], 820), 90, 110, 820)
    put(c, phone(s["mobile"], 180), 850, 130, 200)


def compose():
    n = 0
    names = []

    def out(layout, shots, tag):
        nonlocal n
        n += 1
        c = bgscene(40 + n)
        layout(c, shots)
        name = f"real_{n:02d}_{tag}"
        c.resize((dev.W, dev.H), Image.LANCZOS).convert("RGB").save(OUT / f"{name}.png", optimize=True)
        names.append(name)
        print("saved", name)

    for page in ("dashboard", "pos"):
        s = load(page)
        for layout, tag in [(s_phone, "mobile"), (s_tablet, "tablet"), (s_laptop, "laptop"), (s_desktop, "desktop"), (l3, "monitor_tablet_phone"), (l4, "laptop_tablet_phone"), (l6, "laptop_phone_3d")]:
            out(layout, s, f"{page}_{tag}")
    for i, (page, _) in enumerate(PAGES[2:]):
        s = load(page)
        plan = [(s_laptop, "laptop"), (s_phone, "mobile"), (l1, "laptop_phone"), (l5, "laptop_two_phones")] if i % 2 == 0 else \
               [(s_desktop, "desktop"), (s_tablet, "tablet"), (l2, "monitor_phone"), (l7, "browser_phone")]
        for layout, tag in plan:
            out(layout, s, f"{page}_{tag}")
    return names


def main():
    OUT.mkdir(exist_ok=True)
    if os.getenv("SKIP_CAPTURE") != "1":
        capture()
    names = compose()
    print(len(names), "framed images in", OUT)


if __name__ == "__main__":
    main()
