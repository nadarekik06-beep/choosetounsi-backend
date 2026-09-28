# -*- coding: utf-8 -*-
"""
Placeholder product photos for `php artisan demo:catalog` (dev only).

Usage:  python make_demo_images.py specs.json
specs.json: [{"path": "C:/.../storage/app/public/products/demo/x.png",
              "title": "Berber Wool Rug", "category": "Home & Living",
              "brand": "Bayti", "colors": ["#99f6e4", "#0f766e"]  (light top, dark bottom)}, ...]

Draws a 600x800 (3:4, the storefront card ratio) gradient card with the product
name, category and brand. Needs Pillow (already in the AI service venv).
"""
import json
import os
import sys
import textwrap

from PIL import Image, ImageDraw, ImageFilter, ImageFont

W, H = 600, 800
FONT_DIRS = ["C:/Windows/Fonts", "/usr/share/fonts/truetype/dejavu", "/Library/Fonts"]


def font(names, size):
    for d in FONT_DIRS:
        for n in names:
            p = os.path.join(d, n)
            if os.path.exists(p):
                return ImageFont.truetype(p, size)
    return ImageFont.load_default()


BOLD = ["segoeuib.ttf", "arialbd.ttf", "DejaVuSans-Bold.ttf", "Arial Bold.ttf"]
REGULAR = ["segoeui.ttf", "arial.ttf", "DejaVuSans.ttf", "Arial.ttf"]


def hex_rgb(h):
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def draw_card(spec):
    c1, c2 = hex_rgb(spec["colors"][0]), hex_rgb(spec["colors"][1])
    img = Image.new("RGB", (W, H))
    px = ImageDraw.Draw(img)
    for y in range(H):  # diagonal-ish vertical gradient
        t = y / (H - 1)
        px.line([(0, y), (W, y)], fill=tuple(int(c1[i] + (c2[i] - c1[i]) * t) for i in range(3)))

    # soft light blob behind the "product"
    glow = Image.new("L", (W, H), 0)
    ImageDraw.Draw(glow).ellipse([90, 170, 510, 590], fill=150)
    glow = glow.filter(ImageFilter.GaussianBlur(60))
    img = Image.composite(Image.new("RGB", (W, H), (255, 255, 255)), img, glow)

    d = ImageDraw.Draw(img)
    # product "tile": rounded square with the initials
    d.rounded_rectangle([190, 250, 410, 470], radius=44, fill=(255, 255, 255), outline=c2, width=6)
    initials = "".join(w[0] for w in spec["title"].split()[:2]).upper()
    f_ini = font(BOLD, 92)
    tw = d.textlength(initials, font=f_ini)
    d.text(((W - tw) / 2, 300), initials, font=f_ini, fill=c2)

    # category pill (top)
    f_cat = font(BOLD, 22)
    cat = spec.get("category", "").upper()
    cw = d.textlength(cat, font=f_cat)
    d.rounded_rectangle([(W - cw) / 2 - 18, 60, (W + cw) / 2 + 18, 100], radius=20, fill=(255, 255, 255))
    d.text(((W - cw) / 2, 66), cat, font=f_cat, fill=c2)

    # title (wrapped) + brand
    f_title = font(BOLD, 40)
    shadow = tuple(int(v * 0.55) for v in c2)
    y = 540
    for line in textwrap.wrap(spec["title"], width=22)[:3]:
        lw = d.textlength(line, font=f_title)
        d.text(((W - lw) / 2 + 2, y + 2), line, font=f_title, fill=shadow)
        d.text(((W - lw) / 2, y), line, font=f_title, fill=(255, 255, 255))
        y += 50
    if spec.get("brand"):
        f_brand = font(REGULAR, 26)
        b = spec["brand"]
        bw = d.textlength(b, font=f_brand)
        d.text(((W - bw) / 2, H - 70), b, font=f_brand, fill=(255, 255, 255))

    os.makedirs(os.path.dirname(spec["path"]), exist_ok=True)
    img.save(spec["path"], "PNG", optimize=True)


def main():
    with open(sys.argv[1], encoding="utf-8") as f:
        specs = json.load(f)
    for s in specs:
        draw_card(s)
    print(f"generated {len(specs)} images")


if __name__ == "__main__":
    main()
