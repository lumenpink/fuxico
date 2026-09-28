# Arquitetura do Fuxico

> [!NOTE]
> **Fuxico: A malícia popular do boca a boca no terminal e na web.**
> Compartilhador efêmero de arquivos, encurtador de links, visualizador de documentos Markdown, publicador de gists e servidor de repositórios Git temporários, 100% compatível com os protocolos `0x0.st` e `rustypaste`.

---

## 1. Princípios Fundamentais & Filosofia

O Fuxico foi concebido a partir de três pilares inegociáveis de engenharia e cultura:

1. **Zero dependências externas em runtime:**
   Uma única instância do Fuxico não exige Redis, PostgreSQL, Node.js ou daemons pesados. Tudo o que ele precisa é do interpretador PHP (>= 8.2) com a extensão `pdo_sqlite` habilitada.
2. **Distribuição Standalone Single-File (`fuxico.php`):**
   Todo o código-fonte (classes, visualizadores HTML, CSS zine e acadêmico, banco de dados e utilitários) é compilado pelo script [`compile.php`](file:///home/lumen/src/fuxico/compile.php) em um único arquivo portátil auto-contido. Esse arquivo pode ser servido diretamente pelo PHP built-in server (`php -S`), FrankenPHP, Apache ou Nginx.
3. **Ergonomia e Identidade Visual Zine / Travesti Latina:**
   A tecnologia do Fuxico celebra a resistência e o deboche popular travesti latino-americano, incorporando o léxico pajubá na interface e separando estritamente a saída de telemetria (`stderr`) das URLs limpas (`stdout`).

---

## 2. Visão Geral dos Componentes

```
┌────────────────────────────────────────────────────────┐
│               Clientes (Terminal & Web)                │
│  fux (Rust) │ fux (Bash) │ curl │ rpaste │ 0x0 │ Web   │
└───────────────────────────┬────────────────────────────┘
                            │ HTTP / HTTPS
┌───────────────────────────▼────────────────────────────┐
│              Roteador HTTP (App.php)                   │
├────────────────────────────────────────────────────────┤
│ • /           → Upload, Encurtamento, Servir Arquivos   │
│ • /list       → Listagem rustypaste (JSON autenticado)  │
│ • /painel     → Painel administrativo web segregado    │
│ • /api/gist   → Criação de Gists multi-arquivo          │
│ • /api/git    → Inicialização de repositórios Git      │
│ • /*.git/*    → Git HTTP Smart Protocol (clone/push)   │
│ • /<slug>     → Download, renderização Markdown/Gist   │
└──────────────┬──────────────────────────┬──────────────┘
               │                          │
┌──────────────▼─────────────┐ ┌──────────▼──────────────┐
│    SQLite (Database.php)   │ │  Armazenamento Físico   │
│   Tab: items, stats        │ │  /data/files/<slug>.*   │
└────────────────────────────┘ └─────────────────────────┘
```

---

## 3. Banco de Dados SQLite Embutido

O Fuxico utiliza o SQLite local configurado em modo WAL (`Write-Ahead Logging`) e transações seguras.

### Schema da Tabela `items`
Cada arquivo, URL encurtada, documento Markdown, gist ou repositório Git é armazenado como um registro na tabela `items`:

| Coluna | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | `TEXT PRIMARY KEY` | Hash único de identificação interna (16 chars hex). |
| `slug` | `TEXT UNIQUE` | Slug público curto (5 chars) ou segredo (16 chars). |
| `original_name`| `TEXT` | Nome original do arquivo ou título do gist/documento. |
| `type` | `TEXT` | Tipo: `'file'`, `'url'`, `'doc'`, `'gist'`, `'git'`. |
| `target_url` | `TEXT NULL` | URL de destino quando o tipo é `'url'`. |
| `file_path` | `TEXT NULL` | Caminho do arquivo relativo ao diretório `/data/files`. |
| `mime_type` | `TEXT NULL` | MIME type validado e saneado. |
| `size` | `INTEGER` | Tamanho em bytes. |
| `x_token` | `TEXT` | Token secreto de 32 chars para deleção ou gestão do item. |
| `is_oneshot` | `INTEGER` | `1` se for queima-de-arquivo (evapora no 1º acesso), senão `0`. |
| `expires_at` | `INTEGER NULL`| Timestamp Unix de expiração (ou `NULL` se permanente). |
| `created_at` | `INTEGER` | Timestamp Unix de criação. |
| `views` | `INTEGER` | Contador cumulativo de visualizações/downloads. |

---

## 4. Ciclo de Vida dos Dados & Purgação Automática

1. **Purgação Ativa em Toda Requisição:**
   A cada requisição HTTP que chega ao [`App::handleRequest()`](file:///home/lumen/src/fuxico/src/App.php), o método `purgeExpired()` consulta registros onde `expires_at <= strftime('%s', 'now')`, apaga fisicamente os arquivos do disco em `/data/files` e remove os metadados do SQLite.
2. **Queima-de-Arquivo (Oneshot):**
   Itens marcados com `oneshot=true` (ou flag `-q`) servem seu payload uma única vez. Imediatamente após a entrega do stream para o cliente, o arquivo físico e o registro do banco de dados são incinerados.
3. **Slugs de Alta Entropia:**
   Slugs padrão possuem 5 caracteres alfanuméricos em base62 (mais de 900 milhões de combinações). Slugs secretos (`-s` ou `secret=true`) possuem 16 caracteres alfanuméricos criptograficamente seguros gerados via `random_int`.

---

## 5. Motor de Baking Estático (Static Pre-Rendering)

Para maximizar o desempenho e permitir respostas instantâneas sem overhead de processamento:
- **Markdown Docs (`type='doc'`):** No momento do upload, o documento é processado pelo [`MarkdownRenderer`](file:///home/lumen/src/fuxico/src/MarkdownRenderer.php) e um arquivo estático `.html` correspondente é gravado em disco ao lado do `.md` original. Requisições de navegadores web recebem o HTML assado diretamente com zero re-processamento. Requisições via `curl` recebem o texto puro em Markdown.
- **Gists Multi-Arquivo (`type='gist'`):** O payload JSON multi-arquivo é transformado em um visualizador estático com abas interativas, syntax highlighting e botões de cópia.
- **Repositórios Git (`type='git'`):** Quando um repositório Git efêmero recebe commits via `git push`, o Fuxico inspeciona o repositório bare, extrai a árvore de arquivos, histórico de commits e renderiza o `README.md` estaticamente.

---

## 6. Arquitetura Dual-Skin (Zine vs. Acadêmico Depressivo)

Todos os visualizadores HTML embutidos compartilham o motor de dupla identidade visual com alternância dinâmica:
- **Tema Zine (Padrão):** Risografia independente, estética cyberpunk travesti latino-americana, rosa neon (#ff2a85), amarelo ácido (#f7e018), ciano vibrante (#00e5ff), fita adesiva (duct-tape) e tipografia typewriter/mono irregular.
- **Tema Acadêmico Depressivo:** Estética brutalista, papel envelhecido, preto e branco com tipografia serifada solene (`Noto Serif`), minimalismo austero inspirado em teses acadêmicas e relatórios datilografados.

O tema pode ser alternado dinamicamente no cabeçalho do documento, via parâmetro de query string (`?theme=acad` ou `?acad=1`) ou persistido no `localStorage` do navegador.
