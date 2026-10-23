# Especificação: Desengessar Comunicação do Fuxico (Ticket #56)

- **Ticket**: [#56](https://git.2lp.in/lumen/fuxico/issues/56)
- **Branch**: `feat/56-copy-desengessada-fuxico`
- **Status**: Em implementação (DD-TDD)

## 1. Objetivo
Substituir termos excessivamente técnicos e jargões desnecessários (tais como `musl`, `binário estático`, `soberania na borda`, `zero dependências`) por explicações diretas, acessíveis e acolhedoras sobre como subir e compartilhar arquivos e links sem rastreadores, com rapidez e sem complicação.

## 2. Princípios de Redação
1. **Comunicação Direta e Descomplicada**: O foco é na utilidade prática: enviar arquivos efêmeros, queimar após o download, encurtar links e criar gists sem cadastro e sem burocracia.
2. **Identidade Cultural Viva**: Preservar e valorizar as gírias da rua e o vocabulário autêntico do pajubá (*fuxico*, *aquendar*, *desaquendar*, *bafão*, *babado*, *queima de arquivo*, *caladinho*).
3. **Eliminação de Jargões**:
   - `musl` / `binário estático musl` → `executável standalone`, `executável pronto`, `sem complicação com bibliotecas`
   - `zero dependências` / `zero dependências externas` → `sem complicação`, `funciona direto`, `sem precisar instalar pacotes extras`
   - `soberania na borda` → `autonomia e controle nas suas mãos`, `circulação livre de informação sem intermediários`
   - `soberano` → `independente`, `livre`, `completo`

## 3. Arquivos Afetados
- `site/index.html` e `site/pt/index.html`
- `site/downloads.html` e `site/pt/downloads.html`
- `site/installation.html` e `site/pt/installation.html`
- `site/protocol.html` e `site/pt/protocol.html`
- `site/pajuba.html` e `site/pt/pajuba.html`
- `site/preview.html`
- `src/I18n.php`
- `README.md`
- `docs/CLI.md` e `docs/ARCHITECTURE.md` (e compilação via `scripts/build_docs.php`)
