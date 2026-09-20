# 🪶 PicoShare

> Minimalist, high-performance file sharing, temporary vault, and URL shortener supporting **100% of the 0x0.st (nullpointer)** and **rustypaste** protocols, paired with a sleek Web UI and file management dashboard.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net/)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/License-GPL-3.0-or-later_1.0-lightgrey.svg)](LICENSE)
[![Tests: Pest](https://img.shields.io/badge/tests-Pest%20PHP-ff2d20.svg)](https://pestphp.com/)
[![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-orange.svg)](https://github.com/laravel/pint)
[![CI](https://codeberg.org/lumenpink/picoshare/workflows/ci.yml/badge.svg)](https://codeberg.org/lumenpink/picoshare/actions)

---

## ✨ Features

- **⚡ Dual Protocol Engine:** 100% drop-in compatibility with:
  - **The Null Pointer (`0x0.st`)**: `file`, `url` (remote download), `shorten` (URL redirect), `secret` (16-char slugs), `expires` (hours or ms epoch), and `X-Token` management endpoints (`POST /<slug>` to delete or adjust retention).
  - **`rustypaste`**: `curl -F "file=@..."`, `url` shortening, `expire: 10m` headers, `oneshot: true`, `GET /list` JSON endpoint, and `DELETE /<slug>` method.
- **🛡️ Direct Access Lockdown:** All stored files are shielded via `.htaccess` (`Require all denied`). Every request is strictly authenticated, validated, and streamed through the PHP engine with `X-Content-Type-Options: nosniff`.
- **🔍 Strict MIME Verification with Crypto Exemption:**
  - Enforces strict matching between file extensions and true magic-byte MIME types.
  - Blacklists executable and script formats (`.php`, `.sh`, `.exe`, `.cgi`, etc.).
  - **Cryptographic Exemption:** Encrypted `.gpg`, `.asc`, and `.pgp` streams are recognized as valid cryptographic payloads regardless of binary randomness.
- **🗄️ SQLite Metadata Engine:** Instant indexing, view counters, expiration purging, and atomic file lookups via `pdo_sqlite` (zero external database daemon required).
- **🎨 Modern Web UI & Management Dashboard:**
  - Embedded single-page application with a sleek dark-mode aesthetic and Lumen Pink (`#db2777`) branding.
  - Drag-and-drop file uploader and URL shortener.
  - Interactive **Dashboard** to search, preview media (images, audio, video), monitor expiration countdowns, copy links, and delete items.
  - Local authentication persistence via `localStorage`.
- **💻 Native Client Tooling:**
  - **Bash CLI** (`bin/picoshare`) with clipboard integration and standard Unix flags.
  - **Rust CLI** (`cli/`) standalone binary built with `clap` and `ureq`.

---

## 🚀 Quick Start

### 1. Run with Docker Compose (Recommended)
```bash
# Clone the repository
git clone https://codeberg.org/lumenpink/picoshare.git && cd picoshare

# Start PicoShare and automated image updater (Watchtower)
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

### 1. Bash CLI (`bin/picoshare`)
```bash
# Upload a file
./bin/picoshare photo.png

# Upload with 24-hour expiration, secret slug, and copy link to clipboard
./bin/picoshare -e 24 -s -c document.pdf

# Pipe standard input
cat ~/.bashrc | ./bin/picoshare -n bashrc.txt

# Shorten a URL
./bin/picoshare -u https://example.com -n mylink

# Delete an item
./bin/picoshare -d abcde -t <X-Token>

# List files
./bin/picoshare -l
```

### 2. Rust CLI (`cli/`)
```bash
# Build the binary
cargo build --release --manifest-path cli/Cargo.toml

# Upload a file
./cli/target/release/picoshare-cli photo.png

# Shorten URL
./cli/target/release/picoshare-cli -u https://news.ycombinator.com -n hn

# List files
./cli/target/release/picoshare-cli -l
```

---

## ⚙️ Configuration

Set any of the following environment variables (or export them in your web server / systemd unit):

| Variable | Default | Description |
| :--- | :--- | :--- |
| `PICOSHARE_PUBLIC_MODE` | `false` | When `true`, uploads are open to everyone (0x0.st public mode). When `false`, uploads require a master token. |
| `PICOSHARE_AUTH_TOKENS` | `tatanka` | Comma-separated list of valid master tokens. |
| `PICOSHARE_DATA_DIR` | `__DIR__/data` | Root directory for SQLite database and uploaded files. |
| `PICOSHARE_MAX_UPLOAD_SIZE`| `524288000` | Max file size in bytes (default: 500 MB). |
| `PICOSHARE_SLUG_LENGTH` | `5` | Length of standard random slugs. |
| `PICOSHARE_SECRET_SLUG_LENGTH` | `16` | Length of secret slugs. |
| `PICOSHARE_BASE_URL` | *(auto)* | Override base URL (e.g. `https://pico.example.com`). |

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
