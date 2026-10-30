"""Scales the real application screenshots taken by tests/Browser/MarketingScreenshotsTest.php to 1200x630 into screenshots/real.

Run: python generate_real_screenshots.py
"""
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).parent
SRC = ROOT / "tests" / "Browser" / "screenshots"
OUT = ROOT / "screenshots" / "real"
W, H = 1200, 630

OUT.mkdir(parents=True, exist_ok=True)
for src in sorted(SRC.glob("mk-*.png")):
    im = Image.open(src).convert("RGB")
    ratio = W / H
    if im.width / im.height > ratio:  # too wide: trim the sides
        nw = int(im.height * ratio)
        im = im.crop(((im.width - nw) // 2, 0, (im.width + nw) // 2, im.height))
    else:  # too tall: keep the top of the page
        im = im.crop((0, 0, im.width, int(im.width / ratio)))
    dest = OUT / f"real_{src.stem[3:].replace('-', '_', 1)}.png"
    im.resize((W, H), Image.LANCZOS).save(dest, optimize=True)
    print("saved", dest.name)
