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

## 3. Web Dashboard & Multi-User REST API

O Fuxico inclui uma API REST robusta para o painel de controle (`/painel`) e automações administrativas, com suporte a múltiplos usuários (fuxiqueiras) e controle de acesso baseado em papéis (**RBAC**).

### 3.1 Autenticação e Resolução de Identidade (`GET /api/me`)

Resolve a ficha de acesso fornecida e retorna o contexto do usuário autenticado.

- **Método:** `GET`
- **Cabeçalho:** `Authorization: Bearer <token>` (ou parâmetro `?token=<token>`)
- **Resposta de Sucesso (`200 OK`):**
  ```json
  {
    "authenticated": true,
    "role": "admin",
    "user_id": "a1b2c3d4e5f6",
    "username": "mona_lisa",
    "is_master": false
  }
  ```
- **Resposta para Token Mestre de Configuração:**
  Retorna `role: "admin"`, `username: "mestre"`, `user_id: null` e `is_master: true`.
- **Resposta de Falha (`401 Unauthorized`):** Retornado quando o token é inválido ou ausente.

---

### 3.2 Gestão de Usuárias / Fuxiqueiras (Restrito a `admin`)

Somente a **fuxiqueira-mor** (`role: admin` ou token mestre) possui autorização para consultar, criar, alterar ou remover usuárias. Tentativas por usuárias `member` retornam `403 Forbidden`.

#### Listar Usuárias (`GET /api/users`)
- **Método:** `GET`
- **Resposta (`200 OK`):**
  ```json
  {
    "users": [
      {
        "id": "7f8b1a2c3d4e",
        "username": "sylvia",
        "role": "admin",
        "created_at": 1726839600,
        "item_count": 14,
        "total_bytes": 10485760
      }
    ],
    "total": 1
  }
  ```

#### Cadastrar Nova Fuxiqueira (`POST /api/users`)
Cria uma nova usuária no sistema. Permite opcionalmente definir uma **ficha/senha arbitrária personalizada**.

- **Método:** `POST`
- **Headers:** `Content-Type: application/json`
- **Payload:**
  | Campo | Tipo | Obrigatório | Descrição |
  | :--- | :--- | :--- | :--- |
  | `username` | String | Sim | Identificador único de 2 a 32 caracteres (`[a-zA-Z0-9_\-\.]`). |
  | `role` | String | Não | `admin` (fuxiqueira-mor) ou `member` (fuxiqueira comum, padrão). |
  | `token` | String | Não | Chave/senha personalizada arbitrária (4 a 128 caracteres). Se omitido ou em branco, um token aleatório `fux_<32-hex>` é gerado. |

- **Respostas:**
  - `201 Created`: Usuária cadastrada com sucesso. Retorna o objeto com a ficha gerada/atribuída.
  - `400 Bad Request`: Formato de username ou comprimento de senha inválido.
  - `409 Conflict`: Username já cadastrado ou ficha/senha já em uso por outra usuária ou token mestre.

#### Atualizar ou Regenerar Ficha (`POST /api/users/{id}/token`)
Atualiza a chave de acesso de uma fuxiqueira. Permite informar uma nova chave arbitrária ou sortear uma aleatória.

- **Método:** `POST /api/users/{id}/token`
- **Payload (JSON opcional):**
  ```json
  {
    "token": "minha-nova-senha-arbitraria-2026"
  }
  ```
  *(Se `{ "token": "" }` ou corpo vazio for enviado, uma nova ficha aleatória `fux_<hex>` é gerada).*
- **Respostas:**
  - `200 OK`: Ficha atualizada. O token antigo é imediatamente revogado.
  - `404 Not Found`: Usuária não encontrada.
  - `409 Conflict`: Ficha arbitrária já em uso por outro usuário ou token mestre.

#### Excluir Fuxiqueira (`DELETE /api/users/{id}`)
Remove a conta da usuária do banco de dados.

- **Método:** `DELETE /api/users/{id}`
- **Respostas:** `200 OK` (removida) ou `404 Not Found`.

---

### 3.3 Itens e Isolamento de Propriedade (Ownership Isolation)

O Fuxico implementa isolamento estrito de dados entre usuárias:

- **Estatísticas (`GET /api/stats`):**
  - Para `admin`: Retorna os totais globais da instância.
  - Para `member`: Retorna estritamente os contadores e bytes pertencentes à usuária conectada.

- **Listagem de Itens (`GET /api/items`):**
  - Parâmetros: `search` (termo de busca), `type` (`file`, `url`, `gist`, `git`, `doc`), `limit` (padrão 50, máx 200), `offset`, `date_from` / `from` (data inicial `YYYY-MM-DD` ou timestamp), `date_to` / `to` (data final `YYYY-MM-DD` ou timestamp).
  - **Filtro de Membro:** Para usuárias com perfil `member`, a busca é forçada estritamente ao seu próprio `user_id`. Itens de outras usuárias não são retornados.
  - **Filtro de Admin:** Administradoras podem visualizar itens globais, filtrar por `user_id` (`?user_id=<id>` para uma fuxiqueira específica ou `?user_id=none` para itens anônimos sem dona), além de filtrar por tipo e intervalo de datas de criação.

- **Exclusão de Itens (`DELETE /api/items/{slug}`):**
  - Administradoras podem apagar qualquer item da instância.
  - Membros só podem excluir itens criados sob a sua própria ficha. Tentativas de apagar itens alheios retornam `403 Forbidden: Você só tem permissão para excluir as suas próprias fofocas.`

- **Edição de Parâmetros e Formato (`PATCH /api/items/{slug}`):**
  Permite atualizar configurações, ciclo de vida e metadados de um fuxico existente.
  - **Headers:** `Authorization: Bearer <token>`, `Content-Type: application/json`
  - **Parâmetros do Payload (JSON):**
    | Campo | Tipo | Descrição |
    | :--- | :--- | :--- |
    | `expires` | String/Int/Null | Novo prazo de expiração (ex: `"24h"`, `"7d"`, timestamp unix ou `null`/`"never"` para remover retenção). |
    | `max_downloads` | Int/Null | Quantidade máxima de downloads permitidos antes de evaporar/deletar o item (ou `null` para ilimitado). |
    | `is_oneshot` | Boolean | Se `true`, o item evapora no 1º download. Se `false`, desativa modo queima. |
    | `original_name` | String | Nome amigável de exibição e download do arquivo. |
    | `target_url` | String | Nova URL de destino (válido para itens do tipo `url`). |
    | `recalculate_hash`| Boolean | Se `true`, recalcula o checksum SHA-256 do arquivo físico em disco. |
    | `type` | String | Alterna o modo entre arquivo puro (`"file"`) e documento renderizado (`"doc"`). |
    | `content` | String | Atualiza o conteúdo textual do arquivo em disco e regera HTML se for documento. |
  - Sujeito às regras de isolamento de propriedade (`403 Forbidden` caso um membro tente editar item de outra fuxiqueira). Retorna `200 OK` com o objeto atualizado.

---

### 3.4 Interface Web Embutida (`GET /painel`)

O painel administrativo do Fuxico é servido no endpoint `/painel`. Possui estética zine/pajubá responsiva, suporte a temas (Zine e Acadêmico), armazenamento de token em `localStorage`, visualizador de arquivos em modal, terminal de upload e gaveta de gestão de fuxiqueiras com cadastro e regeneração de fichas.

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

---

## 5. Markdown Document Baking API

Uploads with `doc=true` or `doc=1` are pre-baked into standalone dual-skin HTML documents.

- **Endpoint:** `POST /` (with multipart `file=@doc.md` and `doc=true`).
- **Browsing:** Browsers receive static HTML with responsive layout, Obsidian callouts and Zine/Acad switcher.
- **Raw Access:** `curl https://fuxico.2lp.in/<slug>` or `?raw=1` streams original Markdown text.
- **Associated Assets:** Assets uploaded under the document's namespace are served at `/<slug>/assets/<filename>`.
- **PDF Compilation via Joaju:** `GET /<slug>/pdf` ou `GET /<slug>.pdf` compila o documento para PDF vetorial via Joaju e Typst, com cache em disco em `data/files/<slug>.pdf` e invalidação automática em caso de atualizações de conteúdo ou exclusão.

---

## 6. Multi-File Gist API

- **Endpoint:** `POST /api/gist` (Accepts JSON or multipart `files[]=@...` with `type=gist`).
- **Web UI:** `GET /<slug>` renders tabbed file browser with line numbers and dual-skin themes.
- **Zip Download:** `GET /<slug>/zip` generates dynamic zip archive with all gist files.
- **Raw File Access:** `GET /<slug>/raw/<filename>` delivers individual raw file content.

---

## 7. Git HTTP Smart Protocol

Fuxico embeds a native bare Git server compatible with the Git HTTP Smart Transfer Protocol:

- **Repository Creation:** `POST /api/git` (fields: `name`, `expires`).
- **Reference Discovery:** `GET /<slug>.git/info/refs?service=git-upload-pack` or `service=git-receive-pack`.
- **Packfile Upload / Download:** `POST /<slug>.git/git-upload-pack` and `POST /<slug>.git/git-receive-pack`.
- **Web Repository Viewer:** `GET /<slug>.git` provides commit history, file tree, syntax view, and archive download.

