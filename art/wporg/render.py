"""Render the WordPress.org assets (banner and icon) from banner.html and icon.html.

    python art/wporg/render.py

Writes banner-772x250.jpg, banner-1544x500.jpg, icon-128x128.png and icon-256x256.png next to
this script. These files go to the SVN repository's /assets folder, not into the plugin zip.
Needs Pillow and Edge or Chrome.
"""

import os
import subprocess
import tempfile
from pathlib import Path

from PIL import Image

CANDIDATES = [
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    "/usr/bin/google-chrome",
]
browser = os.environ.get("EDGE") or next(p for p in CANDIDATES if Path(p).exists())
here = Path(__file__).resolve().parent

JOBS = [
    ("banner.html", 772, 250, 1, "banner-772x250.jpg"),
    ("banner.html", 772, 250, 2, "banner-1544x500.jpg"),
    ("icon.html", 128, 128, 1, "icon-128x128.png"),
    ("icon.html", 128, 128, 2, "icon-256x256.png"),
]

for page, w, h, scale, out in JOBS:
    png = Path(tempfile.gettempdir()) / f"wporg-{out}.png"
    subprocess.run(
        [browser, "--headless", "--hide-scrollbars", f"--window-size={w},{h}",
         f"--force-device-scale-factor={scale}", "--virtual-time-budget=8000",
         f"--screenshot={png}", (here / page).as_uri()],
        check=True,
    )
    img = Image.open(png).convert("RGB").crop((0, 0, w * scale, h * scale))
    target = here / out
    if out.endswith(".jpg"):
        img.save(target, quality=88, optimize=True)
    else:
        img.save(target, optimize=True)
    png.unlink()
    print(f"wrote {target.name} {img.size}")
