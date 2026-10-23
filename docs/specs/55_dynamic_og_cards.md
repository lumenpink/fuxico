# Spec: Gerador Dinâmico de Open Graph Cards no Fuxico (#55)

## Contexto
Ao compartilhar links do Fuxico (arquivos, documentos markdown, gists de código, pastas ou repositórios git) em plataformas como Bluesky, Mastodon, WhatsApp, Telegram, Discord ou Twitter/X, as páginas não disponibilizavam cartões visuais ricos nem metadados dinâmicos de Open Graph (`og:image`, `og:title`, `og:description`), resultando em previews genéricos ou sem imagem.

## Objetivo
Implementar um gerador dinâmico de Open Graph cards (`1200x630` px, formato PNG/JPEG) na rota `/<slug>/og.png` (ou `/<slug>/og` e `?og=1`) e injetar as tags `<meta property="og:*">` e `<meta name="twitter:*">` em todas as visualizações HTML (pré-download, gists, documentos e repositórios).

## Critérios de Aceitação
1. **Endpoint de Imagem Dinâmica:**
   - Acesso a `/<slug>/og.png` ou `/<slug>/og` retorna HTTP 200 com `Content-Type: image/png` e dimensões exatas de 1200x630 pixels.
   - O card visual renderiza:
     - Título do arquivo/publicação com quebra de linha inteligente.
     - Metadados: tamanho formatado, tipo do item (`file`, `doc`, `gist`, `folder`, `git`), data de envio.
     - Identidade visual do Fuxico (paleta `#121016`, `#ff2a85`, `#e2f835`, listra trans `#5BCEFA`/`#F5A9B8`/`#FFFFFF`, tipografia e moldura brutalista).
2. **Meta Tags Open Graph e Twitter Cards:**
   - As visualizações HTML (`Views::renderPreDownload`, `Views::renderFolderView`, `MarkdownRenderer`, `GistRenderer`, `RepoRenderer`) incluem as tags:
     - `og:type`, `og:title`, `og:description`, `og:image`, `og:image:width="1200"`, `og:image:height="630"`.
     - `twitter:card="summary_large_image"`, `twitter:title`, `twitter:description`, `twitter:image`.
     - URL absoluta apontando para a rota `/<slug>/og.png`.
3. **Casos Limite e Segurança:**
   - Slugs inexistentes retornam 404.
   - Itens expirados retornam 404.
   - O gerador é resiliente a títulos longos, caracteres especiais e ausência de metadados opcionais.
4. **Testes Automatizados:**
   - Teste unitário e de integração Pest/PHPUnit cobrindo geração de imagem, cabeçalhos e injeção de meta tags.
