# ⚡ FUXICO

> **A malícia popular do boca a boca.** Compartilhar arquivo na borda não precisa parecer um manifesto de guerra fria; pode ser a esperteza de passar a informação adiante pelo puro prazer de fazer a roda girar. Estética de zine e risografia independente, 100% de compatibilidade com os protocolos **0x0.st (nullpointer)** e **rustypaste**, e a CLI mais rápida de digitar: `fux`.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net/)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL-3.0-or-later_1.0-lightgrey.svg)](LICENSE)
[![Tests: Pest](https://img.shields.io/badge/tests-Pest%20PHP-ff2d20.svg)](https://pestphp.com/)
[![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-orange.svg)](https://github.com/laravel/pint)
[![CI](https://codeberg.org/lumenpink/fuxico/workflows/ci.yml/badge.svg)](https://codeberg.org/lumenpink/fuxico/actions)

---

## ✨ Features

- **⚡ Dual Protocol Engine:** 100% drop-in compatibility with:
  - **The Null Pointer (`0x0.st`)**: `file`, `url` (remote download), `shorten` (URL redirect), `secret` (16-char slugs), `expires` (hours or ms epoch), and `X-Token` management endpoints (`POST /<slug>` to delete or adjust retention).
  - **`rustypaste`**: `curl -F "file=@..."`, `url` shortening, `expire: 10m` headers, `oneshot: true`, `GET /list` JSON endpoint, and `DELETE /<slug>` method.
- **🎨 Estética Zine & Risografia Independente:**
  - Visual ácido e marcante: amarelo-marca-texto (`#e2f835`), roxo-berinjela (`#121016`), magenta elétrico (`#ff2a85`) e texturas de meio-tom (*halftone*).
  - **Faixa do Orgulho Trans:** Faixa de 5 listras em azul bebê (`#5bcefa`), rosa (`#f5a9b8`) e branco (`#ffffff`) coroando o card principal e telas de erro.
  - Tipografia de alto contraste (`Anton` + `Space Mono`).
  - **Dual Language (PT-BR / EN):** Detecção automática via `Accept-Language` com microcopy sarcástica, acolhedora e identificação explícita de compartilhamento efêmero de arquivos e encurtador de links (*"Larga a pedrada aqui"*, *"Solta o verbo"*, *"Essa fofoca já prescreveu"*).
  - **Crédito Travesti no Rodapé:** Inscrição multilíngue (*"Orgulhosamente produzido por uma travesti :)"* / *"Proudly produced by a travesti :)"*) com links contextuais para os verbetes de identidade de gênero na Wikipédia.
- **💻 CLI `fux` (Ergonomia Máxima):**
  - **Regra de ouro:** deboche no `stderr` (`[fux] espalhando o bafão...`), URL pura no `stdout` para nunca quebrar pipes (`xclip`, `wl-copy`, scripts).
  - Suporte a `-q` (queima de arquivo) e piping direto de `stdin` (`cat dump.sql | fux`).
- **🛡️ Direct Access Lockdown:** Todos os arquivos são blindados. Acesso sempre intermediado e validado via streaming PHP com `X-Content-Type-Options: nosniff`.
- **🔍 Strict MIME Verification with Crypto Exemption:**
  - Verificação estrita de magic bytes vs extensão.
  - Bloqueio de executáveis e scripts (`.php`, `.sh`, `.exe`, etc.).
  - **Isenção Criptográfica:** Payloads encriptados `.gpg`, `.asc` e `.pgp` são aceitos sem restrições.
- **🗄️ SQLite Metadata Engine:** Indexação instantânea, contagem de views, purga atômica e zero necessidade de banco externo.

---

## 🚀 Quick Start

### 1. Run with Docker Compose (Recommended)
```bash
# Clone the repository
git clone https://codeberg.org/lumenpink/fuxico.git && cd fuxico

# Start Fuxico and automated image updater (Watchtower)
docker compose up -d
```
Visit [http://localhost:8080](http://localhost:8080) to access the service.

### 2. Portable Single-File Mode (Zero Composer / Zero Dependencies)
Download `fuxico.php` and run directly on any server with PHP 8.2+:
```bash
# Baixar o arquivo único e rodar instantaneamente:
curl -O https://codeberg.org/lumenpink/fuxico/raw/branch/main/fuxico.php
FUXICO_PUBLIC_MODE=true php -S 127.0.0.1:8080 fuxico.php
```

### 3. Run Locally with Built-in PHP Server (Modular Mode)
- Requires PHP 8.2+ (`pdo_sqlite`, `fileinfo`).
```bash
php -S 127.0.0.1:8080 index.php
```

### 4. Compile Single-File Portable Edition
Bundle all modular source files into `dist/fuxico.php` and root `fuxico.php`:
```bash
composer compile
# or directly:
php compile.php --check
```

### 5. Build & Publish Docker Images
```bash
# Test building and tagging locally without pushing
./scripts/docker_publish.sh --dry-run

# Build and push production image (lumenpink/fuxico)
./scripts/docker_publish.sh

# Build and push CI test runner image (lumenpink/fuxico-ci)
./scripts/docker_publish.sh --ci

# Build and push both images
./scripts/docker_publish.sh --all
```

### 6. Build Complete Release Bundles (Server & Clients)
Compiles standalone server (`fuxico.php`), packages Bash client (`fux`), compiles native Rust binary (`fux-linux-x86_64`), generates SHA-256 checksums, and produces a consolidated release archive in `dist/`:
```bash
composer bundle
# or directly:
./scripts/build_bundles.sh
```

---

## 📡 Command-Line Usage (cURL)

### 0x0.st / Nullpointer Protocol

```bash
# Upload a file
curl -F 'file=@photo.png' http://localhost:8080/

# Upload with expiration (e.g. 24 hours)
curl -F 'file=@report.pdf' -F 'expires=24' http://localhost:8080/

# Upload with an unguessable 16-character secret slug
curl -F 'file=@backup.gpg' -F 'secret=' http://localhost:8080/

# Upload oneshot (burns / deletes immediately after first download)
curl -F 'file=@secret.txt' -F 'oneshot=true' http://localhost:8080/

# Shorten a URL
curl -F 'shorten=https://github.com/lumenpink' http://localhost:8080/

# Friendly filename subpath alias (e.g., download with friendly name)
curl http://localhost:8080/abcde.png/custom-avatar.png

# Manage / Delete a file using the returned X-Token
curl -F 'token=<X-Token>' -F 'delete=' http://localhost:8080/abcde.png

# Update expiration using X-Token (e.g. 48 hours)
curl -F 'token=<X-Token>' -F 'expires=48' http://localhost:8080/abcde.png
```

### Rustypaste Protocol

```bash
# Upload with custom expiration duration header
curl -F 'file=@image.png' -H 'expire: 10m' -H 'Authorization: Bearer <token>' http://localhost:8080/

# List active files (JSON format)
curl -H 'Authorization: Bearer <token>' http://localhost:8080/list

# Delete an item directly via HTTP DELETE
curl -X DELETE -H 'Authorization: Bearer <token>' http://localhost:8080/abcde.png
```

---

## 🛠️ Client CLIs (`fux`)

Ambos os clientes (Bash e Rust) compartilham o mesmo nome curto (`fux`), aceitam arquivo de configuração unificado (`~/.config/fux/config.toml`), suportam os dois dialetos (`nullpointer` e `rustypaste`) e possuem opções bilingues (EN & PT-BR).

### Arquivo de Configuração Compartilhado (`~/.config/fux/config.toml`)
```toml
server = "http://localhost:8080"
token = "tatanka"
dialect = "nullpointer" # ou "rustypaste"
# expires = "24h"
# oneshot = false
```

### 1. `fux` CLI em Bash (`bin/fux`)
```bash
# Upload padrão com expiração e cópia pro clipboard:
./bin/fux photo.png --caduca 2h --copia

# Queima-de-arquivo (-q / --queima: auto-destruição no 1º download)
./bin/fux -q relatorio.pdf

# Modo silencioso (deboche no stderr silenciado, apenas URL pura no stdout)
url=$(./bin/fux segredo.pdf --caladinho)

# Suporte a stdin pipe
cat dump.sql | ./bin/fux --caduca 2h

# Seleção de dialeto explícita (nullpointer vs rustypaste)
./bin/fux foto.png --dialeto rustypaste

# Encurtar uma URL
./bin/fux --encurta https://example.com --nome meulink

# Apagar fofoca com token
./bin/fux --apaga abcde --ficha <X-Token>

# Listar fofocas ativas
./bin/fux --lista
```

### 2. `fux` CLI Nativo em Rust (`cli/`)
Cliente compilado na especificação **Rust 2024 spec** com as mesmíssimas opções e aliases:
```bash
# Compilar binário nativo otimizado:
cd cli && cargo build --release

# Uso idêntico ao script bash:
./cli/target/release/fux foto.png --caduca 2h --copia
./cli/target/release/fux --encurta https://example.com --segredo
./cli/target/release/fux --ajuda
```

---

## ⚙️ Configuration

Set any of the following environment variables (or export them in your web server / systemd unit):

| Variable | Default | Description |
| :--- | :--- | :--- |
| `FUXICO_PUBLIC_MODE` | `false` | When `true`, uploads are open to everyone (0x0.st public mode). When `false`, uploads require a master token. |
| `FUXICO_AUTH_TOKENS` | `tatanka` | Comma-separated list of valid master tokens. |
| `FUXICO_DATA_DIR` | `__DIR__/data` | Root directory for SQLite database and uploaded files. |
| `FUXICO_MAX_UPLOAD_SIZE`| `524288000` | Max file size in bytes (default: 500 MB). |
| `FUXICO_SLUG_LENGTH` | `5` | Length of standard random slugs. |
| `FUXICO_SECRET_SLUG_LENGTH` | `16` | Length of secret slugs. |
| `FUXICO_BASE_URL` | *(auto)* | Override base URL (e.g. `https://fux.example.com`). |
| `FUXICO_LANG` | `en` *(or auto)*| Force default UI language (`pt` or `en`). |

---

## 🧪 Testing & Code Quality

```bash
# Run automated test suite with Pest
composer test

# Run tests with code coverage & junit
composer test:coverage

# Run static security analysis with Psalm
composer psalm

# Code style inspection and formatting with Laravel Pint
composer lint
composer format

# Run full local CI verification suite
composer ci
```

---

## 📄 Documentation

- [API Specification](docs/API.md): Comprehensive endpoints and query parameter specs.
- [Deployment Guide](docs/DEPLOYMENT.md): Production setup for Apache, Nginx, and Caddy with PHP-FPM.
- [Development Roadmap](ROADMAP.md): Phase breakdown and implementation criteria.

---

## 📜 License

This software is released under the **Creative Commons Zero v1.0 Universal (GPL-3.0-or-later)** public domain dedication.
