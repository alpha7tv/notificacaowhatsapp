#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — ROLLBACK para uma versão anterior
#
#  Uso:  sudo ./deploy/rollback.sh                      # menu interativo
#        sudo ./deploy/rollback.sh --to NOME_DA_RELEASE --yes   # somente código, sem perguntas
#
#  Por padrão volta SOMENTE o código. As migrations do projeto são aditivas, então a versão
#  anterior normalmente funciona com o banco atual. Restaurar o banco (destrutivo) só acontece
#  com confirmação explícita digitada — nunca automaticamente.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

TARGET=""
ASSUME_YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --to) TARGET="${2:-}"; shift ;;
    --yes|-y) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done

require_root
setup_logging rollback
enable_error_trap
acquire_lock deploy
START=$(date +%s)
[[ -L "$CURRENT_LINK" ]] || die "Sistema não instalado."

CUR=$(current_release)
CUR_NAME=$(basename "$CUR")
mapfile -t OTHERS < <(list_releases | grep -vx "$CUR_NAME" || true)

printf '\n%s\n  %s  (%s)\n' "Versão atual:" "v$(release_version "$CUR")" "$CUR_NAME"
if (( ${#OTHERS[@]} == 0 )); then die "Não há releases anteriores disponíveis para rollback."; fi
printf '\n%s\n' "Anteriores:"
for i in "${!OTHERS[@]}"; do
  printf '  %d) v%s  (%s)\n' "$((i + 1))" "$(release_version "${RELEASES_DIR}/${OTHERS[$i]}")" "${OTHERS[$i]}"
done

if [[ -z "$TARGET" ]]; then
  is_interactive || die "Informe --to NOME_DA_RELEASE no modo não interativo."
  read -r -p $'\n  Número da versão para retornar (Enter = 1): ' choice || true
  choice="${choice:-1}"
  [[ "$choice" =~ ^[0-9]+$ ]] && (( choice >= 1 && choice <= ${#OTHERS[@]} )) || die "Opção inválida."
  TARGET="${OTHERS[$((choice - 1))]}"
fi
TARGET_DIR="${RELEASES_DIR}/$(basename "$TARGET")"
[[ -d "$TARGET_DIR" && -f "${TARGET_DIR}/bin/console" ]] || die "Release não encontrada: ${TARGET}"
[[ "$TARGET_DIR" != "$CUR" ]] || die "Esta já é a versão atual."

step "Verificando compatibilidade do banco"
UNKNOWN=$(console migrate:status --against="$TARGET_DIR" | awk '/desconhecida:/{print $2}')
RESTORE_DB=0
if [[ -n "$UNKNOWN" ]]; then
  warn "O banco possui migrations que a versão alvo não conhece:"
  printf '%s\n' "$UNKNOWN" | sed 's/^/      - /'
  cat <<'EOF'

  Opções:
    1) Voltar SOMENTE o código (recomendado; as migrations do projeto são aditivas)
    2) Voltar o código E restaurar um backup do banco (DESTRUTIVO: perde dados gravados depois do backup)
    3) Cancelar
EOF
  if (( ASSUME_YES )); then
    opt=1
    info "Modo --yes: somente o código será revertido."
  else
    read -r -p "  Escolha [1/2/3]: " opt || true
  fi
  case "${opt:-3}" in
    1) ;;
    2) RESTORE_DB=1 ;;
    *) info "Cancelado."; exit 0 ;;
  esac
else
  ok "Banco compatível com a versão alvo"
fi

confirm "Retornar para v$(release_version "$TARGET_DIR") ($(basename "$TARGET_DIR"))?" "N" || { info "Cancelado."; exit 0; }

step "Trocando release"
switch_release "$TARGET_DIR"
write_systemd_units
services_restart_app
nginx_apply
ok "current → $(basename "$TARGET_DIR")"

if (( RESTORE_DB )); then
  step "Restaurando banco (confirmação explícita será solicitada)"
  bash "${TARGET_DIR}/deploy/restore.sh" --db-only
fi

step "Health check"
HC_OK=1
bash "${TARGET_DIR}/deploy/health-check.sh" --wait=30 --write-status || HC_OK=0
STATUS=success; (( HC_OK )) || STATUS=failed
console deploy:record --version="$(release_version "$TARGET_DIR")" --release="$(basename "$TARGET_DIR")" --previous="$CUR_NAME" \
  --kind=rollback --status="$STATUS" --duration="$(( $(date +%s) - START ))" --message="Rollback manual de ${CUR_NAME}" || true
write_status deploy "{\"status\":\"${STATUS}\",\"kind\":\"rollback\",\"release\":\"$(basename "$TARGET_DIR")\",\"version\":\"$(release_version "$TARGET_DIR")\",\"finished_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\"}"
app_log warning "Rollback executado: ${CUR_NAME} → $(basename "$TARGET_DIR") (${STATUS})"
if (( HC_OK )); then
  title "Rollback concluído: agora em v$(release_version "$TARGET_DIR")"
else
  warn "Rollback aplicado, mas o health check apontou falhas. Para voltar: sudo ./deploy/rollback.sh --to ${CUR_NAME}"
  exit 1
fi
