# Especificação do Ticket #61: Delegar Deploys ao Kombinat e Limpar `composer.json`

## 1. Contexto e Motivação
1. O `composer.json` ainda continha `"version"` injetada pela versão anterior do `cccp`, quebrando `composer validate`. Com o `cccp` atualizado (#188), a remoção torna-se definitiva.
2. O workflow `.forgejo/workflows/ci.yml` executava `npx wrangler deploy`, push para Codeberg Pages e SSH direto no `obitwo`. Tudo isso é orquestrado pelo Kombinat.

## 2. Requisitos
1. Remover `"version"` de `composer.json` e sincronizar `composer.lock` (`composer update --lock`).
2. Substituir deploy de site (Wrangler + Codeberg Pages) por `cccp package upload site` em `.forgejo/workflows/ci.yml`.
3. Remover step `Deploy Remotely to obitwo (Tailscale / SSH)` mantendo build/push da imagem Docker.
4. Alinhar `[services]` (`stack = "fuxico"`, `node = "obitwo"`) e `[sites]` (`dir = "site"`) em `.gost/delivery.toml`.
5. Atualizar `tests/audit_cd_workflow.sh`.
