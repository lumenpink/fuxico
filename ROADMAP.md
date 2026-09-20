# PicoShare Roadmap

This roadmap breaks down the modernization of **picoshare** (Ticket #1) into discrete, incremental, and verifiable phases.

---

## 🗺️ Phases Overview

- [x] **Phase 1: Architecture, Storage Lockdown & SQLite Engine**
  - [x] 1.1 Secure storage structure (`data/files/` locked with `.htaccess` `Require all denied`).
  - [x] 1.2 SQLite database engine (`data/picoshare.sqlite`) with migrations and indexed schema.
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
  - [x] 3.1 Lumen Pink dark-mode design system with responsive layout and micro-animations.
  - [x] 3.2 File Upload view (drag & drop, custom slug, expiration dropdown, oneshot switch, secret URL switch).
  - [x] 3.3 URL Shortener view (target URL, custom slug, expiration, oneshot).
  - [x] 3.4 File Manager Dashboard view:
    - [x] Authenticated file and URL list with search and filtering.
    - [x] Media preview player (audio, video, images, syntax/text).
    - [x] Expiration countdown badges and oneshot indicators.
    - [x] Copy link, edit expiration, and single/bulk delete actions.
    - [x] Storage usage statistics.
  - [x] 3.5 Client-side token persistence in `localStorage`.

- [x] **Phase 4: Automated Test Suite with Pest**
  - [x] 4.1 Setup Pest (`pestphp/pest`) in `composer.json`.
  - [x] 4.2 Unit Tests: MIME validator, cryptographic exemption, slug generator, expiration parser.
  - [x] 4.3 Functional Tests: File upload, URL shortener, stream headers, oneshot burn-on-read, expiration.
  - [x] 4.4 Integration Tests: 0x0.st protocol conformity, rustypaste endpoint conformity, management API.

- [x] **Phase 5: Client CLIs (Bash & Rust)**
  - [x] 5.1 Native Bash CLI (`bin/picoshare`) with cURL wrapper and clipboard integration.
  - [x] 5.2 Native Rust CLI (`cli/`) with progress bar and configuration profile support.
  - [x] 5.3 Verify compatibility with upstream `rustypaste-cli`.

- [x] **Phase 6: Documentation & Specification Parity**
  - [x] 6.1 Complete `README.md` with cURL usage guide, self-hosting, and architecture.
  - [x] 6.2 `docs/API.md` documenting both 0x0.st and rustypaste protocols.
  - [x] 6.3 `docs/DEPLOYMENT.md` for Apache, Nginx, and Caddy with PHP-FPM.
  - [x] 6.4 Code styling pass with Laravel Pint.
