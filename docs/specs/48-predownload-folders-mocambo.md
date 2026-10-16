# Especificação Técnica: Página de Pré-Download, Pastas Compartilhadas e Integração com Mocambo

- **Ticket**: [#48](https://codeberg.org/lumenpink/fuxico/issues/48)
- **Status**: Especificado / Fase 3
- **Data**: 2026-10-16
- **Autor**: Lumen Pink & Antigravity

---

## 1. Contexto e Motivação

O **Fuxico** atua como gateway público de compartilhamento com domínio curto (`2lp.in` / `fuxico.lohn.in`). Para atender à nova arquitetura do **Mocambo** (Cloud Storage POSIX), o Fuxico precisa evoluir para atuar como frontend público seguro para arquivos e diretórios do storage, garantindo que o Mocambo permaneça estritamente inacessível da internet pública.

Além disso, compartilhamentos na web moderna exigem uma experiência refinada: em vez de forçar o download cego imediato de um binário no navegador, os usuários devem visualizar uma **página de pré-download** (estilo Google Drive/Dropbox) com metadados, pré-visualização multimídia e opção de leitura de Markdown em modo Zine.

---

## 2. Contratos e Requisitos Técnicos

### 2.1 Página de Pré-Download Inteligente
1. **Detecção do Cliente:**
   - Se o cliente for navegador web (`Accept: text/html`) e **não** solicitar explicitamente o arquivo bruto (`?raw=1` ou `?download=1` ausentes):
     - O Fuxico renderiza uma view HTML elegante de pré-download.
   - Se o cliente for terminal (`curl`, `wget`, `rpaste`), API, ou possuir `?raw=1`:
     - O Fuxico entrega diretamente os bytes crus do arquivo (mantendo compatibilidade estrita com 0x0.st e rustypaste).
2. **Elementos da View de Pré-Download:**
   - Metadados do arquivo: nome original, tamanho formatado (KB/MB/GB), tipo MIME, data de envio, prazo de expiração (se configurado) e downloads restantes (se houver cota).
   - **Área de Pré-visualização (Preview):**
     - Imagens: exibição responsiva inline.
     - Áudio / Vídeo: players HTML5 nativos embutidos.
     - PDFs: visualizador embutido via `<iframe>` ou `<embed>`.
     - Código / Texto: bloco de código com destaque de sintaxe.
     - Markdown (`.md`): botão de destaque "Ler em Modo Zine" alternando para o renderizador de documentos do Fuxico.
   - **Ações:**
     - Botão primário "Baixar Arquivo" (apontando para `/<slug>?raw=1`).
     - Botão "Copiar Link Direto".

### 2.2 Compartilhamento de Pastas (`type = 'folder'`)
1. **Representação no Banco SQLite:**
   - Itens de diretório recebem `type = 'folder'`.
   - O campo `data` ou manifesto armazena a estrutura de arquivos da pasta (caminhos relativos, tamanhos, hashes).
2. **Experiência do Usuário:**
   - A rota `/<slug>` para pastas renderiza um navegador de diretórios limpo com ícones e subpastas.
   - Permite baixar qualquer arquivo individual: `/<slug>/file?path=subpasta/arquivo.ext`.
   - Botão de ação "Baixar Pasta Completa (.zip)": rota `/<slug>/zip` que empacota e transmite os arquivos em streaming via `SimpleZip.php` sem criar arquivos temporários gigantescos em disco.

### 2.3 Rastreabilidade de Origem (`source`)
1. **Esquema de Dados:**
   - Coluna `source TEXT NOT NULL DEFAULT 'native'` adicionada à tabela `items`.
   - Valores suportados: `'native'` (uploads diretos no Fuxico) e `'mocambo'` (compartilhamentos originados do Mocambo).
2. **Metadados do Mocambo:**
   - Coluna `mocambo_meta TEXT` (JSON) armazenando o token interno do Mocambo, caminho original e configurações de webhook.
3. **Painel Administrativo:**
   - A listagem em `/painel` exibe badges distintas para itens locais e itens vinculados ao Mocambo.

### 2.4 Proxy de Storage Reverso (Zero Duplicação)
1. **Fluxo de Streaming:**
   - Para itens com `source = 'mocambo'`, o Fuxico não armazena os bytes no disco local do `obitwo`.
   - Ao atender uma requisição de download (`/<slug>?raw=1`) ou streaming de zip:
     - O Fuxico dispara uma requisição HTTP interna autenticada (via token PSK `FUXICO_MOCAMBO_SECRET`) para o endpoint do Mocambo:
       `GET http://mocambo:8080/api/internal/stream/{token}`
     - O stream de bytes é repassado diretamente para a conexão do cliente com suporte a Range Requests.

### 2.5 API de Integração e Webhooks Bidirecionais
1. **Endpoints da API no Fuxico:**
   - `POST /api/mocambo/share`: recebe payload JSON do Mocambo (`path`, `is_dir`, `file_name`, `file_size`, `mime_type`, `token`, `ttl`, `max_downloads`, `webhook_url`). Cria o registro e retorna o link público (`url: "https://2lp.in/s/<slug>"`).
   - `DELETE /api/mocambo/share/{slug}`: revoga imediatamente o link público quando o arquivo for excluído no Mocambo.
2. **Webhooks de Retorno (Fuxico -> Mocambo):**
   - Se o link expirar por tempo ou atingir a cota `max_downloads`:
     - O Fuxico dispara POST no `webhook_url` do Mocambo:
       ```json
       {
         "slug": "xyz",
         "event": "expired",
         "token": "..."
       }
       ```

---

## 3. Critérios de Aceite e Verificação Automatizada

1. **Pest Tests:**
   - Teste de negociação de conteúdo na rota `/<slug>`: navegador recebe HTML de pré-download; `curl` recebe bytes crus.
   - Teste de preview de mídias e link para modo Zine de arquivos `.md`.
   - Teste de listagem de pasta e download de zip em streaming.
   - Teste de endpoints `/api/mocambo/share` e webhook de expiração.
2. **Qualidade e Estilo:**
   - `vendor/bin/pint --test` limpo.
   - `vendor/bin/pest` 100% de testes verdes.
   - `cccp check --strict` sem falhas bloqueantes.
