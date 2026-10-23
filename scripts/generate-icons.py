"""Gera os ícones PNG da PWA (192, 512 e maskable) sem dependências externas.

Desenho: fundo azul marinho (#0B1F3A) com um "L" laranja (#FF7A00).
Uso: python scripts/generate-icons.py
"""
import os
import struct
import zlib

NAVY = (0x0B, 0x1F, 0x3A, 255)
ORANGE = (0xFF, 0x7A, 0x00, 255)
CLEAR = (0, 0, 0, 0)
OUT = os.path.join(os.path.dirname(__file__), "..", "public", "icons")


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


def in_rounded_rect(x, y, size, radius):
    r = radius
    cx = min(max(x, r), size - 1 - r)
    cy = min(max(y, r), size - 1 - r)
    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r


def in_letter(x, y, size, scale):
    # "L" desenhado numa grelha relativa, centrado; scale < 1 encolhe para a zona segura
    u = (x / size - 0.5) / scale + 0.5
    v = (y / size - 0.5) / scale + 0.5
    stem = 0.30 <= u <= 0.44 and 0.22 <= v <= 0.78
    foot = 0.30 <= u <= 0.72 and 0.64 <= v <= 0.78
    return stem or foot


def icon(size, maskable):
    def pixel(x, y):
        if not maskable and not in_rounded_rect(x, y, size, int(size * 0.22)):
            return CLEAR
        return ORANGE if in_letter(x, y, size, 0.8 if maskable else 1.0) else NAVY

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
