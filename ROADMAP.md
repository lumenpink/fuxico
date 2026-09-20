# FUXICO Roadmap

This roadmap breaks down the evolution and modernization of **Fuxico** into discrete, incremental, and verifiable phases.

---

## 🗺️ Phases Overview

- [x] **Phase 1: Architecture, Storage Lockdown & SQLite Engine**
  - [x] 1.1 Secure storage structure (`data/files/` locked with `.htaccess` `Require all denied`).
  - [x] 1.2 SQLite database engine (`data/fuxico.sqlite`) with migrations and indexed schema.
  - [x] 1.3 Configuration system (Public vs. Private mode, Auth tokens, limits, retention defaults).
  - [x] 1.4 Strict MIME-type vs. extension validation with `.gpg` cryptographic exemption.

- [x] **Phase 2: Nullpointer (0x0.st) & Rustypaste Dual-Protocol Engine**
  - [x] 2.1 Multipart upload (`file`, `url` remote download, `shorten` URL redirect).
  - [x] 2.2 Advanced parameters (`secret` high-entropy slugs, `expires`/`expire` duration parser, `oneshot`).
  - [x] 2.3 `X-Token` generation and response header emission.
  - [x] 2.4 Mediated file streaming with safe Content-Disposition, MIME detection, and subpath aliases (`/<slug>.<ext>/<name>`).
  - [x] 2.5 0x0.st file management endpoint (`POST /<slug>` with `token` for `delete` and `expires`).
  - [x] 2.6 Rustypaste compatibility (`DELETE /<slug>`, `GET /list` JSON listing).
  - [x] 2.7 Automated expiration cleanup and oneshot self-destruction.

- [x] **Phase 3: Embedded Modern Web UI & Management Dashboard**
  - [x] 3.1 Dark-mode design system with responsive layout and micro-animations.
  - [x] 3.2 File Upload view (drag & drop, custom slug, expiration dropdown, oneshot switch, secret URL switch).
  - [x] 3.3 URL Shortener view (target URL, custom slug, expiration, oneshot).
  - [x] 3.4 File Manager Dashboard view:
    - [x] Authenticated file and URL list with search and filtering.
    - [x] Media preview player (audio, video, images, syntax/text).
    - [x] Real-time expiration countdown and view statistics.
    - [x] One-click deletion and clipboard actions.
    - [x] Token persistence in `localStorage`.

- [x] **Phase 4: Test Suite & Code Quality**
  - [x] 4.1 Unit tests for Config, Database, Storage, MIME validator, and I18n.
  - [x] 4.2 Feature/Integration tests for 0x0.st and rustypaste protocols end-to-end.
  - [x] 4.3 Static analysis with Psalm (Level 1 / 97%+ type coverage).
  - [x] 4.4 Code style enforcement with Laravel Pint.

- [x] **Phase 5: Client Tooling & Documentation**
  - [x] 5.1 Native Bash CLI (`bin/fux` and `bin/picoshare` wrapper) with cURL wrapper and clipboard integration.
  - [x] 5.2 Standalone Rust CLI (`cli/`) upgraded to Rust 2024 spec and ureq 3.4.
  - [x] 5.3 Complete API documentation (`docs/API.md`) and deployment guide (`docs/DEPLOYMENT.md`).

- [x] **Phase 6: 12-Factor Docker & Publishing Pipeline**
  - [x] 6.1 Multi-stage / single-stage FrankenPHP Alpine container on port `:8080` with `auto_https off`.
  - [x] 6.2 Pre-configured `docker-compose.yml` with persistent storage and scoped Watchtower auto-updates.
  - [x] 6.3 Automated build and publish script (`scripts/docker_publish.sh`) with semantic versioning.

- [x] **Phase 7: Rebranding to FUXICO: Zine Aesthetic, Dual Language & fux CLI**
  - [x] 7.1 Zine and independent risography visual identity with acid contrast palette (`#e2f835`, `#121016`, `#ff2a85`).
  - [x] 7.2 Dual Language i18n (`src/I18n.php`) supporting PT-BR and EN with streetwise microcopy.
  - [x] 7.3 `fux` CLI ergonomics (deboche no stderr, clean URL no stdout, `-q` queima-de-arquivo, stdin pipe).
  - [x] 7.4 Migration of repository and environment configurations to `fuxico`.

- [x] **Phase 8: Single-File Compiler & Standalone Distribution (closes #10)**
  - [x] 8.1 Standalone bundler `compile.php` generating portable single-file `fuxico.php`.
  - [x] 8.2 Runtime environment validation, namespace aliasing, and test isolation.
