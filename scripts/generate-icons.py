"""
Gera os ícones da PWA (public/icons) e o favicon a partir da marca BISCA.

Desenho: duas setas em "S", a de cima laranja (#FF7300) e a de baixo quase preta (#222222), sobre fundo branco
(logótipo oficial enviado pelo cliente a 2026-10-28). Os mesmos caminhos existem em SVG em
resources/js/components/BrandLogo.vue (coordenadas da imagem original, viewBox 530 105 555 600).

Uso: python scripts/generate-icons.py  (precisa do Pillow: pip install pillow)
"""
import os
import re

from PIL import Image, ImageDraw

OUT = os.path.join(os.path.dirname(__file__), "..", "public", "icons")

WHITE = (255, 255, 255, 255)
ORANGE = (0xFF, 0x73, 0x00, 255)
INK = (0x22, 0x22, 0x22, 255)

# Iguais aos de BrandLogo.vue (só M, L, C e Z, coordenadas absolutas)
ORANGE_ARROW = (
    "M565 437 C540 330 590 200 720 140 C820 100 940 120 1015 202 L1057 155 L1077 360 L885 293 L940 262 "
    "C880 205 790 190 720 215 C630 245 575 330 565 437 Z"
)
INK_ARROW = (
    "M615 450 C700 395 800 360 880 357 C990 355 1072 430 1072 515 C1072 620 950 698 815 698 "
    "C720 698 640 665 597 610 L552 657 L537 450 L727 520 L670 550 C720 600 780 612 830 612 "
    "C920 612 982 570 982 518 C982 460 920 420 840 420 C760 420 680 432 615 450 Z"
)
BOX = (537, 115, 1077, 698)  # x0, y0, x1, y1 da marca


def flatten(d, steps=24):
    """Converte o caminho num polígono (as curvas cúbicas viram segmentos)."""
    tokens = re.findall(r"[MLCZ]|-?\d+(?:\.\d+)?", d)
    points, i, cmd = [], 0, None
    while i < len(tokens):
        if tokens[i] in "MLCZ":
            cmd = tokens[i]
            i += 1
            if cmd == "Z":
                continue
        if cmd in ("M", "L"):
            points.append((float(tokens[i]), float(tokens[i + 1])))
            i += 2
        elif cmd == "C":
            x0, y0 = points[-1]
            x1, y1, x2, y2, x3, y3 = map(float, tokens[i:i + 6])
            for s in range(1, steps + 1):
                t = s / steps
                a, b, c, e = (1 - t) ** 3, 3 * (1 - t) ** 2 * t, 3 * (1 - t) * t * t, t ** 3
                points.append((a * x0 + b * x1 + c * x2 + e * x3, a * y0 + b * y1 + c * y2 + e * y3))
            i += 6
    return points


def icon(size, maskable, rounded=True):
    """maskable: fundo branco a cheio e marca mais pequena (zona segura de 80%)."""
    ss = 4
    big = size * ss
    img = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    if maskable or not rounded:
        draw.rectangle((0, 0, big, big), fill=WHITE)
    else:
        draw.rounded_rectangle((0, 0, big - 1, big - 1), radius=int(big * 0.22), fill=WHITE)

    x0, y0, x1, y1 = BOX
    scale = (0.62 if maskable else 0.8) * big / max(x1 - x0, y1 - y0)
    ox = (big - (x1 - x0) * scale) / 2 - x0 * scale
    oy = (big - (y1 - y0) * scale) / 2 - y0 * scale
    for d, colour in ((INK_ARROW, INK), (ORANGE_ARROW, ORANGE)):
        draw.polygon([(ox + x * scale, oy + y * scale) for x, y in flatten(d)], fill=colour)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for size in (192, 512):
        icon(size, False).save(os.path.join(OUT, f"icon-{size}.png"))
        icon(size, True).save(os.path.join(OUT, f"icon-maskable-{size}.png"))
    icon(180, True).save(os.path.join(OUT, "apple-touch-icon.png"))
    favicon = icon(32, False)
    favicon.save(os.path.join(OUT, "favicon-32.png"))
    favicon.save(os.path.join(OUT, "..", "favicon.ico"), sizes=[(32, 32)])
    print("Ícones gerados em", os.path.abspath(OUT))
