import os

metadata = {
    # EN Pages
    "site/index.html": {
        "title": "FUXICO — Word-of-Mouth Street Mischief Vault & Gists",
        "desc": "Lightweight ephemeral file sharing, encrypted one-shots, pastebin, markdown docs, and git repos with an authentic Travesti Zine spirit.",
        "url": "https://fuxico.2lp.in/",
    },
    "site/installation.html": {
        "title": "FUXICO — Server Installation and Deployment Manual",
        "desc": "Complete deployment guide for running Fuxico via FrankenPHP, Caddy, Docker, or standalone PHP on VPS and Single-Board Computers.",
        "url": "https://fuxico.2lp.in/installation.html",
    },
    "site/downloads.html": {
        "title": "FUXICO — CLI Clients, Binaries & Standalone Downloads",
        "desc": "Download native Rust and Bash CLI tools, single-file server distributions, and view SBC hardware architecture compatibility matrices.",
        "url": "https://fuxico.2lp.in/downloads.html",
    },
    "site/commands.html": {
        "title": "FUXICO — CLI Command-Line Interface Reference Manual",
        "desc": "Reference manual for the fux command-line tool: upload files, create gists, shorten URLs, and manage shares with full UNIX pipe support.",
        "url": "https://fuxico.2lp.in/commands.html",
    },
    "site/protocol.html": {
        "title": "FUXICO — Protocols, API Specs & Client Compatibility",
        "desc": "Drop-in compatibility specs for 0x0.st, rustypaste, raw curl pipelines, and smart HTTP git push/clone endpoints without bloat.",
        "url": "https://fuxico.2lp.in/protocol.html",
    },
    "site/pajuba.html": {
        "title": "FUXICO — Pajubá & Travesti Cultural Vocabulary Guide",
        "desc": "Explore the rich cultural dialect of Pajubá embedded in Fuxico CLI flags, web interface terminology, and counterculture cybernetic zine art.",
        "url": "https://fuxico.2lp.in/pajuba.html",
    },
    "site/preview.html": {
        "title": "FUXICO — Visual Gallery, Artworks & UI Screenshots",
        "desc": "Visual showcase of Trava Zine collage artwork, high-contrast photocopier aesthetics, localized UI screenshots, and OpenGraph social cards.",
        "url": "https://fuxico.2lp.in/preview.html",
    },

    # PT Pages
    "site/pt/index.html": {
        "title": "FUXICO — A Malícia Popular do Boca a Boca e Fofocas",
        "desc": "Compartilhamento efêmero de arquivos, links curtos, queima-de-arquivo, gists e repositórios git com a malícia e estética travesti zine.",
        "url": "https://fuxico.2lp.in/pt/",
    },
    "site/pt/installation.html": {
        "title": "FUXICO — Guia Completo de Instalação e Deploy no Servidor",
        "desc": "Manual completo para hospedar seu servidor Fuxico usando FrankenPHP, Caddy, Docker ou PHP puro em VPS e mini PCs Single-Board.",
        "url": "https://fuxico.2lp.in/pt/installation.html",
    },
    "site/pt/downloads.html": {
        "title": "FUXICO — Clientes CLI, Binários Oficiais e Pacotes Prontos",
        "desc": "Baixe executáveis nativos em Rust e Bash, pacotes standalone PHP e consulte o guia de placas SBCs ARM64 e x86_64 para seu nó local.",
        "url": "https://fuxico.2lp.in/pt/downloads.html",
    },
    "site/pt/commands.html": {
        "title": "FUXICO — Manual Completo de Linha de Comando da CLI",
        "desc": "Guia prático da ferramenta fux: suba arquivos com flags em pajubá, encurte links, gerencie tokens e queime arquivos direto do terminal.",
        "url": "https://fuxico.2lp.in/pt/commands.html",
    },
    "site/pt/protocol.html": {
        "title": "FUXICO — Protocolos, Especificação de API e Clientes",
        "desc": "Compatibilidade nativa com 0x0.st, rustypaste, scripts curl e repositórios git remotos via HTTP com zero dependências externas.",
        "url": "https://fuxico.2lp.in/pt/protocol.html",
    },
    "site/pt/pajuba.html": {
        "title": "FUXICO — Guia Cultural e Vocabulário do Dialeto Pajubá",
        "desc": "Conheça a história e o significado das expressões do Pajubá incorporadas na interface web, flags de terminal e manifesto do Fuxico.",
        "url": "https://fuxico.2lp.in/pt/pajuba.html",
    }
}

for rel_path, data in metadata.items():
    if not os.path.exists(rel_path):
        print(f"NOT FOUND: {rel_path}")
        continue
    with open(rel_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "og:title" in content:
        print(f"Skipping {rel_path} (already has og:title)")
        continue

    title = data["title"]
    desc = data["desc"]
    url = data["url"]

    meta_block = f"""  <!-- OpenGraph / Social Meta (GOST Chapter 11) -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="{title}">
  <meta property="og:description" content="{desc}">
  <meta property="og:image" content="https://fuxico.2lp.in/assets/og_preview.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:url" content="{url}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{title}">
  <meta name="twitter:description" content="{desc}">
  <meta name="twitter:image" content="https://fuxico.2lp.in/assets/og_preview.png">
"""

    if "</head>" in content:
        content = content.replace("</head>", meta_block + "</head>")
        with open(rel_path, "w", encoding="utf-8") as f:
            f.write(content)
        print(f"Updated {rel_path}")
    else:
        print(f"ERROR: No </head> found in {rel_path}")
