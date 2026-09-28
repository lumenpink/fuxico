# Especificação dos Protocolos do Fuxico

> [!TIP]
> O Fuxico oferece compatibilidade estrita "drop-in" com as duas ferramentas de linha de comando mais populares do ecossistema de pastebins efêmeros: **0x0.st (The Null Pointer)** e **rustypaste**. Além disso, ele estende o ecossistema com protocolos nativos para **Markdown Docs**, **Gists Multi-Arquivo** e **Repositórios Git Efêmeros via HTTP Smart Protocol**.

---

## 1. Protocolo 0x0.st (The Null Pointer)

Todas as requisições 0x0.st utilizam codificação `multipart/form-data` contra a raiz do servidor (`/`).

### 1.1 Upload de Arquivo Padrão
```bash
curl -F 'file=@relatorio.pdf' https://fuxico.2lp.in/
```
**Resposta do Servidor:**
- **Status:** `HTTP 200 OK`
- **Cabeçalho:** `X-Token: 6f9e6c0880d206442a9692482f1c7cc4`
- **Corpo:** `https://fuxico.2lp.in/k9xpt.pdf\n`

### 1.2 Queima-de-Arquivo (Oneshot)
O arquivo é incinerado fisicamente imediatamente após o primeiro download:
```bash
curl -F 'file=@segredo.gpg' -F 'oneshot=true' https://fuxico.2lp.in/
```

### 1.3 Slug Secreto (Alta Entropia)
Gera uma chave aleatória de 16 caracteres alfanuméricos criptograficamente seguros em vez do slug curto de 5 caracteres:
```bash
curl -F 'file=@documento.txt' -F 'secret=' https://fuxico.2lp.in/
```

### 1.4 Tempo de Retenção Definido (`expires`)
Aceita durações amigáveis (ex: `10m`, `2h`, `7d`) ou inteiros em horas:
```bash
curl -F 'file=@backup.tar.gz' -F 'expires=4h' https://fuxico.2lp.in/
```

### 1.5 Encurtamento de URL
```bash
curl -F 'shorten=https://codeberg.org/lumenpink/fuxico' https://fuxico.2lp.in/
```

### 1.6 Gestão e Deleção via X-Token (0x0.st Style)
```bash
# Apagar fofoca usando o token retornado no cabeçalho X-Token:
curl -F 'token=6f9e6c0880d206442a9692482f1c7cc4' -F 'delete=' https://fuxico.2lp.in/k9xpt.pdf

# Atualizar tempo de expiração:
curl -F 'token=6f9e6c0880d206442a9692482f1c7cc4' -F 'expires=48' https://fuxico.2lp.in/k9xpt.pdf
```

---

## 2. Protocolo rustypaste

O dialeto `rustypaste` utiliza parâmetros passados tanto em campos de formulário quanto em cabeçalhos HTTP dedicados.

### 2.1 Upload com Cabeçalhos rustypaste
```bash
curl -H 'expire: 2h' -H 'oneshot: true' -F 'file=@dump.sql' https://fuxico.2lp.in/
```

### 2.2 Listagem de Arquivos (`GET /list`)
Retorna um array JSON com todos os arquivos ativos pertencentes à instância:
```bash
curl -H 'Authorization: Bearer <master_token>' https://fuxico.2lp.in/list
```
**Exemplo de Resposta:**
```json
[
  {
    "file_name": "foto.png",
    "file_size": 204850,
    "expires_at": 1790620078,
    "url": "https://fuxico.2lp.in/foto.png"
  }
]
```

### 2.3 Deleção Direta (`DELETE /<slug>`)
Exclui diretamente pelo método HTTP `DELETE` informando o `X-Token` ou o token mestre:
```bash
curl -X DELETE -H 'Authorization: Bearer <token>' https://fuxico.2lp.in/foto.png
```

---

## 3. Protocolo de Documentos Markdown (Dual-Skin & Obsidian)

O Fuxico renderiza documentos Markdown transformando-os em páginas web com suporte nativo a temas Zine e Acadêmico.

### 3.1 Publicação de Documento
Para que um arquivo `.md` seja tratado como página rica de documentação, basta enviar `doc=true` (ou `doc=1`):
```bash
curl -F 'file=@manual.md' -F 'doc=true' https://fuxico.2lp.in/
```

### 3.2 Visualização e Negociação de Conteúdo
- **Navegador Web:** Recebe o HTML estático pré-assado (dual-skin) completo com menu de alternância, callouts estilizados e syntax highlighting.
- **Terminal `curl`:** Ao detectar `curl` no `User-Agent` (ou passando `?raw=1`), o Fuxico entrega o texto puro original do Markdown.

### 3.3 Recursos Sintáticos Suportados
- **Callouts Obsidian:** Blocos `> [!NOTE]`, `> [!TIP]`, `> [!IMPORTANT]`, `> [!WARNING]` e `> [!CAUTION]`.
- **Wikilinks:** Links no formato `[[Outro Documento]]`.
- **Destaques:** Texto com marca-texto `==amarelo neon==`.
- **Tabelas Pipe:** Tabelas no formato Markdown GFM.
- **Assets Anexados:** Imagens e anexos referenciados no documento ficam disponíveis sob `/<slug>/assets/<arquivo>`.

---

## 4. Protocolo de Gists Multi-Arquivo (`/api/gist`)

Permite empacotar múltiplos arquivos e snippets de código em um único link compartilhado com abas visuais e download em arquivo comprimido.

### 4.1 Criação de Gist via JSON
```bash
curl -X POST https://fuxico.2lp.in/api/gist \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <token>' \
  -d '{
    "title": "Script de Manutenção",
    "expires": "24h",
    "files": {
      "main.py": { "content": "print(\"iniciando backup...\")" },
      "README.md": { "content": "# Documentação do Script\nExecute com python3." }
    }
  }'
```

### 4.2 Criação de Gist via Multipart
```bash
curl -F 'files[]=@main.py' -F 'files[]=@README.md' -F 'type=gist' https://fuxico.2lp.in/
```

### 4.3 Endpoints de Download do Gist
- **Visualizador Web:** `https://fuxico.2lp.in/<slug>` (Interface tabulada com temas Zine e Acadêmico).
- **Download Zip:** `https://fuxico.2lp.in/<slug>/zip` (Gera arquivo ZIP on-the-fly contendo todos os arquivos).
- **Raw Individual:** `https://fuxico.2lp.in/<slug>/raw/<filename>` (Ex: `https://fuxico.2lp.in/<slug>/raw/main.py`).

---

## 5. Repositórios Git Efêmeros (Git HTTP Smart Protocol)

O Fuxico atua como um servidor remoto de Git compatível com a especificação oficial do `Git HTTP Smart Protocol` (RFC 0001 / Git Transfer Protocol).

### 5.1 Inicialização de Repositório (`POST /api/git`)
```bash
curl -X POST https://fuxico.2lp.in/api/git \
  -H 'Authorization: Bearer <token>' \
  -F 'name=meu-projeto' \
  -F 'expires=48h'
```
**Resposta:**
```json
{
  "slug": "meu-projeto",
  "clone_url": "https://fuxico.2lp.in/meu-projeto.git",
  "x_token": "9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c4d",
  "expires_at": 1790800000
}
```

### 5.2 Operações Nativas do Git
```bash
# Adicionar o Fuxico como remote:
git remote add fuxico https://fuxico.2lp.in/meu-projeto.git

# Enviar commits:
git push -u fuxico main

# Clonar em outra máquina:
git clone https://fuxico.2lp.in/meu-projeto.git
```

### 5.3 Endpoints do Smart Protocol Implementados
- `GET /<slug>.git/info/refs?service=git-upload-pack` (Descoberta de referências para clone e fetch)
- `POST /<slug>.git/git-upload-pack` (Negociação de packfile para download de commits)
- `GET /<slug>.git/info/refs?service=git-receive-pack` (Descoberta de referências para push)
- `POST /<slug>.git/git-receive-pack` (Envio e validação de packfile de commits)
- `GET /<slug>.git` ou `GET /<slug>` no navegador (Visualizador web do repositório com visualização de README, histórico de commits, árvore de arquivos e download de tarball).
