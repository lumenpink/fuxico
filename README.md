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
  - Tipografia de alto contraste (`Anton` + `Space Mono`).
  - **Dual Language (PT-BR / EN):** Detecção automática via `Accept-Language` com microcopy sarcástica e acolhedora (*"Despeja o podre aqui"*, *"Solta o verbo"*, *"Essa fofoca já prescreveu"*).
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

### 2. Run Locally with Built-in PHP Server
- Requires PHP 8.2+ (`pdo_sqlite`, `fileinfo`, `curl`, `mbstring`).
```bash
php -S 127.0.0.1:8080 index.php
```

### 3. Build & Publish Docker Image
```bash
# Build and publish to Docker Hub with automatic version tagging
./scripts/docker_publish.sh --dry-run
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

## 🛠️ Client CLIs

### 1. `fux` CLI (`bin/fux`)
```bash
# Upload de arquivo padrão (TTL default 24h)
./bin/fux photo.png

# Modo Queima de Arquivo (-q: auto-destruição no 1º download)
./bin/fux -q relatorio.pdf

# Definir expiração explícita e copiar link pro clipboard
./bin/fux -e 2h -c backup.gpg

# Suporte a stdin pipe (sem gerar arquivo temporário manual)
cat dump.sql | ./bin/fux -e 2h

# Regra de ouro: deboche no stderr, URL limpa no stdout!
url=$(./bin/fux segredo.pdf --quiet)

# Encurtar uma URL
./bin/fux -u https://example.com -n meulink

# Queimar/Apagar fofoca com token
./bin/fux -d abcde -t <X-Token>

# Listar fofocas ativas
./bin/fux -l
```

*(O atalho `./bin/picoshare` é mantido como wrapper para retrocompatibilidade).*

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

*(Legacy `PICOSHARE_*` environment variables remain supported as fallbacks).*

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
