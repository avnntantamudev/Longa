"""Gera os ícones PNG da PWA (192, 512, maskable, apple-touch) e o favicon, sem dependências externas.

Desenho: marca Garimpo — pino de localização laranja (#ED731D) com um "G", sobre fundo branco
(resources/brand/garimpo-logo.jpg). A mesma geometria existe em SVG em resources/js/components/BrandLogo.vue
(viewBox 0 0 78.6 100).

Uso: python scripts/generate-icons.py
"""
import os
import struct
import zlib

WHITE = (0xFF, 0xFF, 0xFF, 255)
ORANGE = (0xED, 0x73, 0x1D, 255)
CLEAR = (0, 0, 0, 0)
OUT = os.path.join(os.path.dirname(__file__), "..", "public", "icons")

# Geometria do SVG (viewBox 0 0 78.6 100): pino = círculo + ponta; furo, abertura em diagonal e barra do G
PIN_CIRCLE = (39.3, 39.3, 39.3)
PIN_TIP = [(9.3, 64.7), (69.3, 64.7), (39.3, 100.0)]
HOLE = (39.3, 39.3, 21.5)
OPENING = [(51.3, 34.6), (80.0, 34.6), (80.0, 6.1)]
BAR_END = (37.5, 40.7, 6.1)
BAR = (37.5, 34.6, 78.6, 46.8)  # x0, y0, x1, y1
MARK_RATIO = 0.786  # largura / altura

SUPERSAMPLE = 4  # antialiasing


def write_png(path, size, pixel):
    rows = b"".join(
        b"\x00" + b"".join(bytes(pixel(x, y)) for x in range(size)) for y in range(size)
    )

    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))

    png = b"\x89PNG\r\n\x1a\n"
    png += chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0))
    png += chunk(b"IDAT", zlib.compress(rows, 9))
    png += chunk(b"IEND", b"")
    with open(path, "wb") as fh:
        fh.write(png)


def write_ico(path, png_path, size):
    # ICO com uma única imagem PNG embebida (suportado por todos os browsers atuais)
    with open(png_path, "rb") as fh:
        data = fh.read()
    header = struct.pack("<HHH", 0, 1, 1)
    entry = struct.pack("<BBBBHHII", size % 256, size % 256, 0, 0, 1, 32, len(data), 6 + 16)
    with open(path, "wb") as fh:
        fh.write(header + entry + data)


def in_polygon(u, v, poly):
    inside = False
    j = len(poly) - 1
    for i, (xi, yi) in enumerate(poly):
        xj, yj = poly[j]
        if (yi > v) != (yj > v) and u < (xj - xi) * (v - yi) / (yj - yi) + xi:
            inside = not inside
        j = i
    return inside


def in_circle(x, y, c):
    cx, cy, r = c
    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r


def in_mark(u, v):
    # (u, v) no quadrado unitário; a marca ocupa a altura toda, centrada na horizontal
    x = (u - (1 - MARK_RATIO) / 2) * 100
    y = v * 100
    pin = in_circle(x, y, PIN_CIRCLE) or in_polygon(x, y, PIN_TIP)
    cut = in_circle(x, y, HOLE) or in_polygon(x, y, OPENING)
    x0, y0, x1, y1 = BAR
    bar = in_circle(x, y, PIN_CIRCLE) and (in_circle(x, y, BAR_END) or (x0 <= x <= x1 and y0 <= y <= y1))
    return (pin and not cut) or bar


def in_rounded_rect(u, v, radius):
    cx = min(max(u, radius), 1 - radius)
    cy = min(max(v, radius), 1 - radius)
    return (u - cx) ** 2 + (v - cy) ** 2 <= radius * radius


def icon(size, maskable):
    # maskable: o desenho ocupa a zona segura central (80%) e o fundo cobre tudo
    scale = 0.6 if maskable else 0.8

    def sample(u, v):
        if not maskable and not in_rounded_rect(u, v, 0.22):
            return CLEAR
        mu = (u - 0.5) / scale + 0.5
        mv = (v - 0.5) / scale + 0.5
        return ORANGE if in_mark(mu, mv) else WHITE

    def pixel(x, y):
        acc = [0, 0, 0, 0]
        for sy in range(SUPERSAMPLE):
            for sx in range(SUPERSAMPLE):
                c = sample((x + (sx + 0.5) / SUPERSAMPLE) / size, (y + (sy + 0.5) / SUPERSAMPLE) / size)
                for k in range(4):
                    acc[k] += c[k]
        n = SUPERSAMPLE * SUPERSAMPLE
        alpha = acc[3] / n
        if alpha == 0:
            return CLEAR
        # cor média ponderada pela opacidade
        return tuple(int(acc[k] / n * 255 / alpha) if k < 3 else int(alpha) for k in range(4))

    return pixel


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for size in (192, 512):
        write_png(os.path.join(OUT, f"icon-{size}.png"), size, icon(size, False))
        write_png(os.path.join(OUT, f"icon-maskable-{size}.png"), size, icon(size, True))
    write_png(os.path.join(OUT, "apple-touch-icon.png"), 180, icon(180, True))
    favicon_png = os.path.join(OUT, "favicon-32.png")
    write_png(favicon_png, 32, icon(32, False))
    write_ico(os.path.join(OUT, "..", "favicon.ico"), favicon_png, 32)
    print("Ícones gerados em", os.path.abspath(OUT))
