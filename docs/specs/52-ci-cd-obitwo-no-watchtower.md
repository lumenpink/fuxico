# Especificação Normativa — Ticket #52
## Continuous Deployment Remoto no Nó de Borda (obitwo) via Tailscale e Descomissionamento do Watchtower

**Norma de Referência:** GOST v1.5.0 (Perfil Lumen — Seção 9)  
**Status:** Aprovado para Implementação  
**Data:** 2026-10-20  

---

### 1. Escopo e Objetivos

Esta especificação define o pipeline soberano de entrega contínua (Continuous Deployment - CD) da aplicação **Fuxico (`fuxico`)** no nó de borda **obitwo** através da malha segura Tailscale/SSH, eliminando de forma definitiva a dependência do Watchtower:

1. **Construção Hermética e Publicação:**
   - Imagem Docker `lumenpink/fuxico:latest` gerada pelo Forgejo Runner soberano.
   - Publicação no Docker Hub com credenciais do repositório (`DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN`).
2. **Descomissionamento Compulsório do Watchtower:**
   - Veto irrestrito a `com.centurylinklabs.watchtower.enable`.
   - Remoção de qualquer webhook HTTP ou gatilho legado do Watchtower.
3. **Deploy Remoto Soberano no obitwo:**
   - Conexão via SSH (`ubuntu@obitwo` ou `ubuntu@100.90.43.39`) através de chave ED25519 (`OBITWO_SSH_KEY`).
   - Execução remota no diretório `~/stacks/fuxico`:
     ```bash
     cd ~/stacks/fuxico
     docker compose pull fuxico
     docker compose up -d --no-build fuxico
     ```
4. **Sondagem de Healthcheck Ativo & Rollback Automático:**
   - Sondagem de estabilidade com loop de até 15 segundos no nó `obitwo`:
     ```bash
     for i in $(seq 1 15); do
       STATUS=$(docker inspect --format='{{.State.Status}}' fuxico 2>/dev/null || echo "unknown")
       if [ "$STATUS" = "running" ]; then
         echo "✔ Container fuxico saudável e ativo."
         exit 0
       fi
       sleep 1
     done
     ```
   - Em caso de falha de inicialização, executa rollback para a imagem estável anterior e aborta o workflow com status de erro.
5. **Purga Automática de Camadas Órfãs:**
   - Execução de `docker image prune -f` no nó `obitwo` após o deploy concluído.
