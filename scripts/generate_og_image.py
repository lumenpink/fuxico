#!/usr/bin/env python3
"""
Gera o card oficial de Open Graph e banner social (1200x630 pixels)
para o Fuxico em estrita conformidade com o Capítulo 11 da norma GOST:
- Resolução Obrigatória: 1200x630 pixels (proporção 1.91:1, PNG RGB).
- Safe Zone 4:3 centralizada: miolo útil de 760px (x=220 a 980), anti-recorte WhatsApp/mobile.
- Limites rígidos de caracteres anti-truncamento.
- Identidade visual Trava Zine & Street Riot (Rosa Elétrico + Amarelo Ácido + Faixa Trans).
- Destino: site/assets/og_preview.png e site/assets/poster.png.
"""

import os
import shutil
from PIL import Image, ImageDraw, ImageFont, ImageFilter

W, H = 1200, 630

# Paleta Zine / Travesti Riot
C_BG_CANVAS   = (16, 13, 22, 255)       # Chassis escuro arroxeado
C_BG_PANEL    = (26, 22, 34, 255)       # Superfície dos cartões/tags
C_BORDER      = (56, 48, 71, 220)       # Borda técnica
C_GRID_DOT    = (226, 248, 53, 20)      # Grid halftone sutil
C_ACID_YELLOW = (226, 248, 53, 255)     # Amarelo ácido
C_PINK_RIOT   = (255, 42, 133, 255)     # Rosa elétrico
C_WHITE       = (245, 244, 239, 255)    # Branco papel zine
C_TEXT_MUTED  = (159, 151, 173, 240)    # Cinza lilás
C_GREEN_LED   = (52, 211, 153, 255)     # Verde status

# Cores da Bandeira Trans
C_TRANS_BLUE  = (91, 206, 250, 255)
C_TRANS_PINK  = (245, 169, 184, 255)
C_TRANS_WHITE = (255, 255, 255, 255)

FONT_BOLD_CANDIDATES = [
    "/usr/share/fonts/truetype/firacode/FiraCode-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
]

FONT_REG_CANDIDATES = [
    "/usr/share/fonts/truetype/firacode/FiraCode-Regular.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf",
]

def get_font(candidates, size):
    for path in candidates:
        if os.path.exists(path):
            try:
                return ImageFont.truetype(path, size)
            except Exception:
                continue
    return ImageFont.load_default()

def draw_centered_text(draw, y, text, font, fill, shadow=None, shadow_offset=2):
    bbox = draw.textbbox((0, 0), text, font=font)
    tw = bbox[2] - bbox[0]
    x = (W - tw) // 2
    if shadow:
        draw.text((x + shadow_offset, y + shadow_offset), text, font=font, fill=shadow)
    draw.text((x, y), text, font=font, fill=fill)
    return x, y, tw, bbox[3] - bbox[1]

def create_og_image(output_path="site/assets/og_preview.png"):
    base = Image.new("RGBA", (W, H), C_BG_CANVAS)

    # 1. Halftone / Dot Grid de Fundo
    grid_draw = ImageDraw.Draw(base)
    spacing = 24
    for gx in range(0, W, spacing):
        for gy in range(0, H, spacing):
            grid_draw.rectangle([gx, gy, gx + 1, gy + 1], fill=C_GRID_DOT)

    # 2. Glow Neon Ambiental (Rosa Elétrico à esquerda, Amarelo Ácido à direita)
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gdraw = ImageDraw.Draw(glow)
    gdraw.ellipse([240, 120, 700, 500], fill=(255, 42, 133, 40))
    gdraw.ellipse([540, 140, 960, 520], fill=(226, 248, 53, 30))
    glow = glow.filter(ImageFilter.GaussianBlur(85))
    base = Image.alpha_composite(base, glow)
    draw = ImageDraw.Draw(base)

    # 3. Trans Pride Ribbon no Topo (10px)
    stripe_h = 2
    draw.rectangle([0, 0, W, stripe_h], fill=C_TRANS_BLUE)
    draw.rectangle([0, stripe_h, W, stripe_h * 2], fill=C_TRANS_PINK)
    draw.rectangle([0, stripe_h * 2, W, stripe_h * 3], fill=C_TRANS_WHITE)
    draw.rectangle([0, stripe_h * 3, W, stripe_h * 4], fill=C_TRANS_PINK)
    draw.rectangle([0, stripe_h * 4, W, stripe_h * 5], fill=C_TRANS_BLUE)

    # 4. Fontes
    f_badge = get_font(FONT_BOLD_CANDIDATES, 15)
    f_brand = get_font(FONT_BOLD_CANDIDATES, 92)
    f_title = get_font(FONT_BOLD_CANDIDATES, 32)
    f_sub   = get_font(FONT_REG_CANDIDATES, 21)
    f_tag   = get_font(FONT_BOLD_CANDIDATES, 14)
    f_foot  = get_font(FONT_BOLD_CANDIDATES, 18)
    f_meta  = get_font(FONT_REG_CANDIDATES, 16)

    # 5. Badge Superior Estilo Duct-Tape (Safe Zone, y=56)
    # Limite rígido GOST: <= 35 caracteres
    badge_text = "★ FERVO & TECNOLOGIA TRAVESTI ★"
    bbox_b = draw.textbbox((0, 0), badge_text, font=f_badge)
    bw = bbox_b[2] - bbox_b[0]
    bh = bbox_b[3] - bbox_b[1]
    bx = (W - bw) // 2
    by = 56

    # Fita adesiva amarela com leve rotação visual ou sombra dura preta
    draw.rectangle([bx - 18, by - 5, bx + bw + 18, by + bh + 5], fill=(0, 0, 0, 240))
    draw.rectangle([bx - 20, by - 7, bx + bw + 16, by + bh + 3], fill=C_ACID_YELLOW)
    draw.text((bx - 2, by - 2), badge_text, font=f_badge, fill=(0, 0, 0, 255))

    # 6. Hero Brand: "FUXICO" (y=118)
    brand_text = "FUXICO"
    bbox_br = draw.textbbox((0, 0), brand_text, font=f_brand)
    brw = bbox_br[2] - bbox_br[0]
    brx = (W - brw) // 2
    brand_y = 116

    # Brutalist 3D Shadow (Preto + Rosa Elétrico)
    draw.text((brx + 6, brand_y + 6), brand_text, font=f_brand, fill=(0, 0, 0, 255))
    draw.text((brx + 3, brand_y + 3), brand_text, font=f_brand, fill=C_PINK_RIOT)
    draw.text((brx, brand_y), brand_text, font=f_brand, fill=C_ACID_YELLOW)

    # Linha divisória técnica sob a marca
    bar_y = brand_y + (bbox_br[3] - bbox_br[1]) + 16
    bar_w = 760
    bar_x = (W - bar_w) // 2
    draw.line([(bar_x, bar_y), (bar_x + bar_w, bar_y)], fill=C_PINK_RIOT, width=2)
    # Detalhe central amarelo
    draw.line([(bar_x + (bar_w // 2) - 45, bar_y), (bar_x + (bar_w // 2) + 45, bar_y)], fill=C_ACID_YELLOW, width=3)

    # 7. Título Principal (Safe Zone, y=260)
    # Limite GOST: 35 a 45 caracteres
    t_y = bar_y + 24
    draw_centered_text(
        draw, t_y, "A malícia popular do boca a boca",
        f_title, C_WHITE, shadow=(0, 0, 0, 230), shadow_offset=2
    )

    # 8. Subtítulo / Linha de Apoio (Safe Zone, y=310)
    # Limite GOST: 50 a 60 caracteres
    s_y = t_y + 48
    draw_centered_text(
        draw, s_y, "Envios efêmeros, Markdown Docs, Gists e links curtos",
        f_sub, C_ACID_YELLOW
    )

    # 9. Badges de Protocolo & Recursos (Safe Zone, y=412)
    tags = ["0X0.ST DROP-IN", "RUSTYPASTE PROTOCOL", "CLI EM RUST & BASH", "100% STANDALONE"]
    tag_total_w = sum(draw.textbbox((0, 0), f"[ {t} ]", font=f_tag)[2] for t in tags) + (len(tags) - 1) * 14
    tx = (W - tag_total_w) // 2
    ty = s_y + 54

    for t in tags:
        label = f"[ {t} ]"
        lbox = draw.textbbox((0, 0), label, font=f_tag)
        lw = lbox[2] - lbox[0]
        lh = lbox[3] - lbox[1]
        draw.rectangle([tx - 6, ty - 4, tx + lw + 6, ty + lh + 4], fill=C_BG_PANEL, outline=C_BORDER, width=1)
        draw.text((tx, ty), label, font=f_tag, fill=C_TEXT_MUTED)
        tx += lw + 14

    # 10. Rodapé da Imagem (Safe Zone, y=542, padding inferior > 85px)
    foot_y = H - 85
    f_bar_w = 760
    f_bar_x = (W - f_bar_w) // 2
    draw.line([(f_bar_x, foot_y - 18), (f_bar_x + f_bar_w, foot_y - 18)], fill=C_BORDER, width=1)

    # LED verde de status
    led_radius = 5
    draw.ellipse([f_bar_x, foot_y - 2, f_bar_x + led_radius * 2, foot_y + led_radius * 2 - 2], fill=C_GREEN_LED)

    # Texto de rodapé
    foot_text = "fuxico.2lp.in"
    draw.text((f_bar_x + 18, foot_y - 5), foot_text, font=f_foot, fill=C_WHITE)

    meta_text = "CCCP // MIT LICENSE // codeberg.org/lumenpink/fuxico"
    bbox_m = draw.textbbox((0, 0), meta_text, font=f_meta)
    mw = bbox_m[2] - bbox_m[0]
    draw.text((f_bar_x + f_bar_w - mw, foot_y - 3), meta_text, font=f_meta, fill=C_TEXT_MUTED)

    # Salvar Imagem
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    base = base.convert("RGB")
    base.save(output_path, "PNG", optimize=True)
    print(f"✅ Card oficial OpenGraph gerado com sucesso: {output_path} (1200x630, norma GOST Cap. 11)")

    # Copiar também para poster.png conforme especificado na issue #33
    poster_path = os.path.join(os.path.dirname(output_path), "poster.png")
    shutil.copy2(output_path, poster_path)
    print(f"✅ Banner poster.png sincronizado: {poster_path}")

if __name__ == "__main__":
    create_og_image("site/assets/og_preview.png")
