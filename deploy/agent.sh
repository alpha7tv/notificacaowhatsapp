#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — AGENTE DE MANUTENÇÃO (root, via fiberlink-agent.timer)
#
#  O painel web NUNCA executa comandos. Ele apenas registra pedidos na tabela
#  system_jobs. Este agente (root) lê o próximo pedido e executa SOMENTE um dos
#  scripts fixos abaixo, sem repassar nenhum parâmetro vindo da web:
#     check_update -> update.sh --check
#     update       -> deploy.sh --yes
#     backup       -> backup.sh --label manual
#     health       -> health-check.sh --write-status
#  Também atualiza o status do servidor (health.json) a cada 5 minutos.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"
set -uo pipefail

require_root
exec 9>/run/fiberlink-agent.lock
flock -n 9 || exit 0
[[ -f "$ENV_FILE" && -L "$CURRENT_LINK" ]] || exit 0

# 1) Status periódico para SISTEMA > SERVIDOR
HEALTH_JSON="${STATUS_DIR}/health.json"
if [[ ! -f "$HEALTH_JSON" ]] || (( $(date +%s) - $(stat -c %Y "$HEALTH_JSON") > 300 )); then
  bash "${DEPLOY_DIR}/health-check.sh" --write-status --quiet >/dev/null 2>&1 || true
fi
UPDATE_JSON="${STATUS_DIR}/update.json"
if [[ ! -f "$UPDATE_JSON" ]] || (( $(date +%s) - $(stat -c %Y "$UPDATE_JSON") > 21600 )); then
  bash "${DEPLOY_DIR}/update.sh" --check --quiet >/dev/null 2>&1 || true
fi

# 2) Próxima tarefa solicitada pelo painel
JOB=$(console jobs:claim 2>/dev/null | tail -n1 || true)
[[ -n "$JOB" ]] || exit 0
JOB_ID="${JOB%% *}"
JOB_TYPE="${JOB#* }"
[[ "$JOB_ID" =~ ^[0-9]+$ ]] || exit 1
case "$JOB_TYPE" in
  check_update|update|backup|health) ;;
  *) console jobs:finish "$JOB_ID" failed "Tipo de tarefa não permitido" >/dev/null 2>&1; exit 1 ;;
esac

JOB_LOG="${STORAGE_DIR}/logs/deploy/job-${JOB_ID}.log"
: >"$JOB_LOG"
chown root:"$APP_GROUP" "$JOB_LOG"
chmod 640 "$JOB_LOG"
printf '[%s] Tarefa #%s (%s) iniciada pelo agente\n' "$(date '+%F %T')" "$JOB_ID" "$JOB_TYPE" >>"$JOB_LOG"

# Os scripts são executados a partir da release atual (código somente leitura, pertencente ao root).
set +e
case "$JOB_TYPE" in
  check_update) bash "${DEPLOY_DIR}/update.sh" --check >>"$JOB_LOG" 2>&1 ;;
  update)       bash "${DEPLOY_DIR}/deploy.sh" --yes >>"$JOB_LOG" 2>&1 ;;
  backup)       bash "${DEPLOY_DIR}/backup.sh" --label manual >>"$JOB_LOG" 2>&1 ;;
  health)       bash "${DEPLOY_DIR}/health-check.sh" --write-status >>"$JOB_LOG" 2>&1 ;;
esac
RC=$?
set -e

# Remove códigos de cor do log exibido no painel
sed -i 's/\x1b\[[0-9;]*m//g' "$JOB_LOG" 2>/dev/null || true
if (( RC == 0 )); then
  RESULT="Concluída com sucesso"
  [[ "$JOB_TYPE" == "check_update" ]] && RESULT=$(grep -E 'Versão disponível|Situação' "$JOB_LOG" | tr -s '. ' ' ' | paste -sd'; ' - | cut -c1-900)
  console jobs:finish "$JOB_ID" success "$RESULT" >/dev/null 2>&1
else
  console jobs:finish "$JOB_ID" failed "Falhou (código ${RC}). Veja o log da tarefa." >/dev/null 2>&1
fi
printf '[%s] Tarefa #%s finalizada com código %s\n' "$(date '+%F %T')" "$JOB_ID" "$RC" >>"$JOB_LOG"
exit 0
