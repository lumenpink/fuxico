# Fuxico API Specification

Fuxico implements complete protocol parity with **0x0.st (The Null Pointer)** and **rustypaste**, alongside custom REST endpoints for the embedded Web Dashboard.

---

## 1. 0x0.st Protocol Endpoints

### 1.1 Upload / Shorten (`POST /`)
Sends data encoded as `multipart/form-data`.

#### Parameters:
| Field | Type | Description |
| :--- | :--- | :--- |
| `file` | File data | The file content to upload. Mutually exclusive with `url` and `shorten`. |
| `url` | String | Remote file URL to fetch and store, or URL to shorten. |
| `shorten` | String | Target URL to shorten (creates a 302 redirect). |
| `secret` | Flag | If present, generates a 16-character high-entropy slug instead of 5 characters. |
| `expires` | String/Number | Sets maximum retention in hours (e.g. `24`) or Unix timestamp in milliseconds. |
| `oneshot` | Boolean/Flag | If set (`true` or present), deletes the file immediately upon first access. |
| `customName` | String | Optional custom slug name. |

#### Response:
- **Status:** `200 OK`
- **Headers:** `X-Token: <token>` (random 32-hex management token)
- **Body:** Plain text URL ending in `\n` (e.g. `https://pico.example.com/a1b2c.png\n`)

---

### 1.2 File Retrieval (`GET /<slug>.<ext>` or `GET /<slug>`)
Streams the stored file or redirects to the destination URL.

#### Friendly Path Aliases:
Appending a trailing filename (e.g., `https://pico.example.com/a1b2c.png/avatar.png`) returns the file with `Content-Disposition: inline; filename="avatar.png"`.

#### Range Requests:
Supports `Range: bytes=start-end` for video/audio seeking and resumable downloads.

---

### 1.3 File Management (`POST /<slug>.<ext>` or `POST /<slug>`)
Performs administrative operations on an uploaded item.

#### Parameters:
| Field | Type | Description |
| :--- | :--- | :--- |
| `token` | String | **Required.** The secret `X-Token` returned during upload. |
| `delete` | Flag | If present, permanently deletes the file and record. |
| `expires` | String/Number | If present, sets a new expiration date in hours or timestamp. |

#### Response:
- `File deleted.\n` (200 OK)
- `Expiration updated.\n` (200 OK)
- `Invalid management token.\n` (401 Unauthorized)

---

## 2. Rustypaste Protocol Endpoints

### 2.1 File Listing (`GET /list`)
Returns a JSON array of active files. Oneshot items are excluded.

#### Authentication:
Requires `Authorization: Bearer <master_token>` header.

#### Response:
```json
[
  {
    "file_name": "example.png",
    "file_size": 1048576,
    "expires_at": 1726839600,
    "url": "https://pico.example.com/example.png"
  }
]
```

---

### 2.2 Direct Deletion (`DELETE /<slug>.<ext>` or `DELETE /<slug>`)
Permanently removes the specified file.

#### Authentication:
Requires `Authorization: Bearer <master_token>` OR `Authorization: Bearer <x_token>`.

---

## 3. Web Dashboard REST API

Protected by `Authorization: Bearer <master_token>`.

- `GET /api/stats`: Returns overall item counts, total bytes, and views.
- `GET /api/items?search=&type=&limit=50&offset=0`: Returns paginated records with total count.
- `DELETE /api/items/<slug>`: Deletes the item by slug.

---

## 4. Client CLI & Dialect Specifications (`fux`)

Both Bash (`bin/fux`) and Native Rust (`cli/target/release/fux`) clients support dual dialect selection via `-D, --dialect, --dialeto <nullpointer|rustypaste>` and configuration files (`~/.config/fux/config.toml`):

### Dialect Mapping
| Feature | `nullpointer` (0x0.st) | `rustypaste` |
|---|---|---|
| **Upload TTL** | Form field `expires=<val>` | Header `expire: <val>` |
| **Oneshot** | Form field `oneshot=true` | Header `oneshot: true` |
| **Shorten** | Form field `shorten=<url>` | Form field `url=<url>` |
| **Deletion** | `POST /<slug> -F token=... -F delete=` | `DELETE /<slug> -H "Authorization: Bearer ..."` |
| **Listing** | Via `GET /list` (shared) | `GET /list -H "Authorization: Bearer ..."` |

