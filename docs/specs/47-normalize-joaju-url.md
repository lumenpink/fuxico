# Especificação Técnica: Normalização de FUXICO_JOAJU_URL para Diagramas

- **Ticket**: [#47](https://codeberg.org/lumenpink/fuxico/issues/47)
- **Status**: Concluído / Validado
- **Data**: 2026-10-15
- **Autor**: Lumen Pink & Antigravity

---

## 1. Contexto e Motivação

O Fuxico integra-se com a API do **Joaju** para duas finalidades centrais:
1. **Compilação de PDFs (`App::compileDocToPdf`)**: rota `/<slug>/pdf` que dispara POST para `/compile`. O método `App::getJoajuUrl()` utiliza a variável de ambiente `FUXICO_JOAJU_URL` como URL base do serviço (padrão: `http://joaju:3000`), concatenando `/compile`.
2. **Renderização de Diagramas Vetoriais Inline (`MarkdownRenderer::renderJoajuDiagram`)**: rotina de baking que transforma blocos `d2`, `mermaid`, `kinship2`, `pedtools`, etc., em SVG inline. Atualmente, o método `renderJoajuDiagram()` utiliza a variável `FUXICO_JOAJU_URL` diretamente na chamada cURL, possuindo como fallback a URL completa `'https://joaju.lumen.pink/render'`.

Ao configurar `FUXICO_JOAJU_URL` com a URL base da infraestrutura interna (ex: `http://100.69.210.39:3000` ou `http://joaju:3000`), o `App::getJoajuUrl()` funciona corretamente, mas o `MarkdownRenderer::renderJoajuDiagram()` dispara a requisição POST contra a raiz (`/`), recebendo HTTP 404 do Joaju (que atende apenas em `/render`, `/compile` e `/unfurl`).

---

## 2. Contrato Técnico da Correção

1. **Normalização da URL no `MarkdownRenderer`**:
   - O método `MarkdownRenderer::buildJoajuRenderUrl(string $rawUrl): string` (ou lógica inline equivalente) deve normalizar qualquer string fornecida:
     - Remover barras à direita (`rtrim($url, '/')`).
     - Se a URL já terminar com `/render`, utilizá-la diretamente.
     - Caso contrário, anexar `/render`.
   - Se nenhuma URL for fornecida nem em `$options['joaju_url']` nem em `getenv('FUXICO_JOAJU_URL')`, adotar a URL base canônica default (`https://joaju.lumen.pink`) e anexar `/render`.
2. **Compatibilidade com URL Base e URL Completa**:
   - `http://100.69.210.39:3000` -> `http://100.69.210.39:3000/render`
   - `http://100.69.210.39:3000/` -> `http://100.69.210.39:3000/render`
   - `https://joaju.lumen.pink/render` -> `https://joaju.lumen.pink/render`
   - `http://joaju:3000` -> `http://joaju:3000/render`
3. **Recompilação dos Monólitos**:
   - Executar `php compile.php` para atualizar `fuxico.php` e `dist/fuxico.php`.
4. **Garantia de Qualidade**:
   - Testes com Pest (`vendor/bin/pest`) 100% GREEN.
   - Lint com Pint (`vendor/bin/pint --test`) limpo.
   - Verificação de tipos com Psalm (`vendor/bin/psalm`) limpo.
