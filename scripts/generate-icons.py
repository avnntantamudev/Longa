"""Gera os ícones PNG da PWA (192, 512, maskable, apple-touch) e o favicon, sem dependências externas.

Desenho (alinhado com o kit de telas): livro aberto laranja (#FF7A00) com um ponto por cima da
lombada, sobre fundo azul marinho (#0B1F3A). O mesmo desenho existe em SVG em
resources/js/components/BrandLogo.vue.

Uso: python scripts/generate-icons.py
"""
import os
import struct
import zlib

NAVY = (0x0B, 0x1F, 0x3A, 255)
ORANGE = (0xFF, 0x7A, 0x00, 255)
CLEAR = (0, 0, 0, 0)
OUT = os.path.join(os.path.dirname(__file__), "..", "public", "icons")

# Geometria num quadrado unitário (0..1), igual à do SVG (viewBox 0 0 100 100)
LEFT_PAGE = [(0.14, 0.36), (0.47, 0.44), (0.47, 0.84), (0.14, 0.76)]
RIGHT_PAGE = [(0.53, 0.44), (0.86, 0.36), (0.86, 0.76), (0.53, 0.84)]
DOT = (0.5, 0.25, 0.085)  # centro x, centro y, raio

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


def in_mark(u, v):
    cx, cy, r = DOT
    return in_polygon(u, v, LEFT_PAGE) or in_polygon(u, v, RIGHT_PAGE) or (u - cx) ** 2 + (v - cy) ** 2 <= r * r


def in_rounded_rect(u, v, radius):
    cx = min(max(u, radius), 1 - radius)
    cy = min(max(v, radius), 1 - radius)
    return (u - cx) ** 2 + (v - cy) ** 2 <= radius * radius


def icon(size, maskable):
    # maskable: o desenho ocupa a zona segura central (80%) e o fundo cobre tudo
    scale = 0.72 if maskable else 0.92

    def sample(u, v):
        if not maskable and not in_rounded_rect(u, v, 0.22):
            return CLEAR
        mu = (u - 0.5) / scale + 0.5
        mv = (v - 0.5) / scale + 0.5
        return ORANGE if in_mark(mu, mv) else NAVY

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
