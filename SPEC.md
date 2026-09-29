# Fuxico Technical Specification (SPEC-01)

> **Document Version:** 1.0.0  
> **Status:** Standard  
> **Standard Body:** Gosplan / Fuxico Engineering Working Group  
> **Conformance Standard:** GOST v1.1.0 (`GOSTVER`)

---

## 1. Scope & Goals

**Fuxico** é um servidor e ecossistema de compartilhamento de arquivos, encurtador de URLs, publicador de zines em Markdown, hospedagem de gists multifiles e servidor Git bare HTTP multiprotocolo.

Projetado como uma aplicação auto-contida em PHP 8.2+ (distribuível como arquivo único compilado `fuxico.php`), acompanhado por clientes nativos em Rust (`cli/`) e Bash (`bin/fux`).

### Propriedades Fundamentais:
1. **Zero Dependências Externas em Produção:** Funciona sobre o servidor embutido do PHP (`php -S`), FrankenPHP/Caddy ou Nginx, utilizando apenas extensões nativas (`pdo_sqlite`, `curl`).
2. **Multiprotocolo Estrito:** Paridade binária e comportamental com o protocolo **The Null Pointer (`0x0.st`)** e **rustypaste**, aceitando ferramentas clientes existentes (`curl`, `rpaste`, scripts de linha de comando).
3. **Multi-usuário e RBAC (Fuxiqueiras & Fuxiqueira-mor):** Controle de acesso baseado em papéis (`admin` e `member`), com fichas/senhas arbitrárias ou aleatórias e isolamento estrito de acervo.
4. **Publicação Zine em Markdown:** Conversão estática e dinâmica de notas Markdown (com sintaxe Obsidian, callouts, realces e tabelas) em páginas web com alternador de temas Zine/Acadêmico.
5. **Gists e Git Bare HTTP:** Servidor Git Smart Protocol nativo embutido e gists com múltiplos arquivos e exportação em `.zip`.

---

## 2. Arquitetura do Sistema e Armazenamento

```
/data (ou root)
├── fuxico.db          # Banco de dados relacional SQLite (WAL mode)
├── uploads/           # Arquivos físicos indexados por slug e hash
├── git/               # Repositórios bare do Git HTTP Smart Protocol
└── dist/fuxico.php    # Artefato único compilado para produção
```

### 2.1 Esquema de Dados SQLite
- **`items`**: Registro de arquivos, URLs, gists, documentos e repositórios git com metadados (`slug`, `original_name`, `mime_type`, `size_bytes`, `x_token`, `user_id`, `created_at`, `expires_at`, `view_count`, `is_oneshot`).
- **`users`**: Cadastro de fuxiqueiras com `id`, `username`, `token`, `role` (`admin` ou `member`) e `created_at`.

### 2.2 Precedência de Configuração
A configuração segue a ordem estrita:
1. Variáveis de ambiente (`FUXICO_AUTH_TOKENS`, `FUXICO_BASE_URL`, `FUXICO_DATA_DIR`, `FUXICO_PUBLIC_MODE`, `FUXICO_MAX_UPLOAD_SIZE`).
2. Arquivo de configuração local `.env` ou `config.php`.
3. Valores padrão embutidos no código.

---

## 3. Especificação de Protocolos e Rotas

### 3.1 The Null Pointer (0x0.st)
- `POST /`: Envio de arquivo multipart (`file=@...`), encurtamento (`url=...` ou `shorten=...`), retenção (`expires=...`), link descartável (`oneshot=true`), custom slug (`customName=...`) e modo documento (`doc=true`). Retorna o cabeçalho `X-Token` com o token de gerenciamento.
- `GET /<slug>` ou `GET /<slug>.<ext>`: Download ou redirecionamento 302. Suporte a HTTP Range Requests (RFC 7233).
- `POST /<slug>`: Gerenciamento via formulário com `token=...` e ações `delete=1` ou `expires=...`.

### 3.2 Rustypaste
- `POST /`: Upload via headers HTTP (`expire: ...`, `oneshot: true`, `filename: ...`).
- `GET /list`: Listagem estruturada em JSON de itens ativos (requer `Authorization: Bearer <token>`).
- `DELETE /<slug>`: Exclusão direta de arquivos via token mestre ou `X-Token`.

### 3.3 Publicação Markdown & Gists Multifiles
- `POST /api/gist`: Envio de múltiplos arquivos com metadados para visualização tabulada web, download em `.zip` e acesso bruto em `/<slug>/raw/<filename>`.
- `POST /` (com `doc=true`): Renderização estática com suporte a Obsidian callouts, syntax highlighting e skin switcher.

### 3.4 Git HTTP Smart Protocol
- `POST /api/git`: Inicialização de repositório bare.
- `GET /<slug>.git/info/refs`: Descoberta de referências com suporte a `service=git-upload-pack` e `git-receive-pack`.
- `POST /<slug>.git/git-upload-pack` e `POST /<slug>.git/git-receive-pack`: Transferência de packfiles com suporte a push e clone padrão do Git.
- `GET /<slug>.git`: Visualizador web nativo com histórico de commits, árvore de arquivos e download de tarball.

---

## 4. Governança de Autenticação e RBAC

1. **Tokens Mestres (`FUXICO_AUTH_TOKENS`):**
   - Configurados na inicialização do serviço.
   - Sempre identificados como `username: "mestre"` e `role: "admin"`.
2. **Usuárias Cadastradas (`users`):**
   - Papéis suportados: `admin` (fuxiqueira-mor) e `member` (fuxiqueira comum).
   - Senhas/Fichas: Podem ser geradas aleatoriamente (`fux_<32-hex>`) ou fornecidas como chaves arbitrárias personalizadas (4 a 128 caracteres).
3. **Isolamento de Acervo (Ownership Isolation):**
   - Fuxiqueiras comuns (`member`) possuem visão estritamente restrita aos seus próprios envios.
   - Listagens (`GET /api/items`) e estatísticas (`GET /api/stats`) são filtradas automaticamente por `user_id`.
   - Tentativas de excluir ou alterar itens de terceiros são bloqueadas com `403 Forbidden`.

---

## 5. Interface de Linha de Comando (CLI)

O ecossistema disponibiliza binários unificados com suporte a dialetos e localização bilíngue (PT/EN):
- `fux` (Bash nativo) e `fux` (Rust nativo).
- Parâmetros: `--servidor / -s`, `--ficha / -t`, `--caduca / -e`, `--queima / -o`, `--calada / -q`, `--dialeto / -D`.
