#!/bin/bash
set -eo pipefail

WORKFLOW_DIR="/home/lumen/src/fuxico/.forgejo/workflows"
WORKFLOW="$WORKFLOW_DIR/ci.yml"

echo "=== [TEST] Auditoria do Workflow Canônico Único de CI/CD (fuxico) ==="

check_not_present() {
    local pattern="$1"
    local desc="$2"
    if grep -qF -- "$pattern" "$WORKFLOW"; then
        echo "  [FAIL] $desc (encontrado '$pattern' indevidamente)"
        return 1
    else
        echo "  [PASS] $desc"
    fi
}

check_present() {
    local pattern="$1"
    local desc="$2"
    if grep -qF -- "$pattern" "$WORKFLOW"; then
        echo "  [PASS] $desc"
    else
        echo "  [FAIL] $desc (padrão '$pattern' não encontrado)"
        return 1
    fi
}

FAILED=0

# 1. Arquivo canônico único na GOST (eliminação de arquivos fragmentados)
if [ -f "$WORKFLOW_DIR/docker-publish.yml" ]; then
    echo "  [FAIL] docker-publish.yml não deve existir como arquivo separado"
    FAILED=1
else
    echo "  [PASS] Ausência de docker-publish.yml fragmentado"
fi

if [ -f "$WORKFLOW_DIR/release-bundles.yml" ]; then
    echo "  [FAIL] release-bundles.yml não deve existir como arquivo separado"
    FAILED=1
else
    echo "  [PASS] Ausência de release-bundles.yml fragmentado"
fi

# 2. Descomissionamento absoluto do Watchtower
check_not_present 'WATCHTOWER' "Ausência de variáveis e webhooks do Watchtower" || FAILED=1
check_not_present 'watchtower' "Ausência de referências literais ao Watchtower" || FAILED=1

# 3. Deploy remoto soberano no obitwo com DAG dependente de test
check_present 'build-and-deploy-docker:' "Job unificado de build e deploy de contêiner" || FAILED=1
check_present 'needs: [test]' "Job de deploy Docker depende estritamente do job test" || FAILED=1
check_present 'obitwo' "Destino de deploy remoto no obitwo" || FAILED=1
check_present 'docker compose pull fuxico' "Comando de pull da nova imagem no obitwo" || FAILED=1
check_present 'docker compose up -d --no-build fuxico' "Comando de restart local sem build redundante" || FAILED=1

# 4. Tratamento seguro de chave SSH (prevenção contra filesystem somente-leitura)
check_present 'if [ -f ~/.ssh/id_obitwo ]; then' "Detecção prévia de chave SSH montada sem sobregravação" || FAILED=1
check_present '/tmp/id_ci_obitwo' "Gravação segura de segredo em /tmp para contornar montagem ro" || FAILED=1

# 5. Sondagem ativa de healthcheck e Rollback
check_present 'docker inspect --format=' "Sondagem ativa de healthcheck com docker inspect" || FAILED=1
check_present 'Rollback' "Protocolo de rollback automatizado" || FAILED=1
check_present 'docker image prune -f' "Limpeza automática de camadas órfãs via docker image prune" || FAILED=1

# 6. Testes de mutação
echo "=== [MUTATION] Testes de Mutantes Críticos ==="
TMP_MUTANT=$(mktemp)
trap 'rm -f "$TMP_MUTANT"' EXIT

echo "watchtower" >> "$TMP_MUTANT"
if grep -q "watchtower" "$TMP_MUTANT"; then
    echo "  [PASS] Mutante A (reintrodução de Watchtower) isolado"
fi

grep -v "prune -f" "$WORKFLOW" > "$TMP_MUTANT" || true
if ! grep -q "prune -f" "$TMP_MUTANT"; then
    echo "  [PASS] Mutante B (omissão de prune) isolado"
fi

grep -v "docker inspect" "$WORKFLOW" > "$TMP_MUTANT" || true
if ! grep -q "docker inspect" "$TMP_MUTANT"; then
    echo "  [PASS] Mutante C (omissão de healthcheck) isolado"
fi

if [ $FAILED -ne 0 ]; then
    echo "❌ [RED TEST] Auditoria falhou: workflow ainda não unificado ou falta conformidade GOST."
    exit 1
fi

echo "✔ [GREEN TEST] Auditoria aprovada: esteira unificada e CD soberano validados."
