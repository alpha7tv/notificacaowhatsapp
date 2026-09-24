#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — VERIFICAR / APLICAR ATUALIZAÇÃO
#
#  Uso:  sudo ./deploy/update.sh            # verifica, mostra e (se confirmar) atualiza via deploy.sh
#        sudo ./deploy/update.sh --check    # só verifica e grava o status exibido no painel
#        sudo ./deploy/update.sh --system   # aplica atualizações de segurança do Ubuntu
#        --yes  não pede confirmação
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

CHECK_ONLY=0
SYSTEM=0
QUIET=0
ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    --check) CHECK_ONLY=1 ;;
    --system) SYSTEM=1 ;;
    --quiet) QUIET=1 ;;
    --yes|-y) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $arg" ;;
  esac
done
require_root
enable_error_trap
[[ -f "$ENV_FILE" ]] || die "Sistema não instalado."

if (( SYSTEM )); then
  setup_logging update-system
  step "Atualizações do Ubuntu"
  apt-get -q -o DPkg::Lock::Timeout=600 update
  UPG=$(apt-get -s -o Debug::NoLocking=1 upgrade | grep -c '^Inst ' || true)
  info "${UPG} pacote(s) com atualização disponível."
  (( UPG > 0 )) || { ok "Sistema já atualizado."; exit 0; }
  confirm "Aplicar agora? (os serviços podem reiniciar por alguns segundos)" "N" || exit 0
  apt-get -y -q -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade
  [[ -f /var/run/reboot-required ]] && warn "O Ubuntu pede REINICIALIZAÇÃO (sudo reboot). Os serviços voltam sozinhos."
  bash "${DEPLOY_DIR}/health-check.sh" --write-status || true
  exit 0
fi

CUR=$(current_release)
INSTALLED_VERSION=$(release_version "$CUR")
INSTALLED_REV=$(cat "${CUR}/REVISION" 2>/dev/null || true)
REMOTE=false
AVAILABLE_VERSION=""
TARGET_REF=""
TARGET_REV=""
ERROR=""

if repo_has_remote; then
  REMOTE=true
  if g -C "$REPO_DIR" fetch --quiet --tags --prune --force origin '+refs/heads/*:refs/remotes/origin/*' 2>/tmp/fiberlink-fetch.err; then
    g -C "$REPO_DIR" remote set-head origin --auto >/dev/null 2>&1 || true
    TARGET_REF=$(resolve_target_ref || true)
    if [[ -n "$TARGET_REF" ]]; then
      TARGET_REV=$(g -C "$REPO_DIR" rev-parse "${TARGET_REF}^{commit}")
      AVAILABLE_VERSION=$(g -C "$REPO_DIR" show "${TARGET_REV}:VERSION" 2>/dev/null | tr -d '[:space:]')
    else
      ERROR="Nenhuma tag v* ou branch padrão encontrada"
    fi
  else
    ERROR="Falha ao acessar o repositório remoto: $(head -c 200 /tmp/fiberlink-fetch.err | sed -E 's#//[^/@]+@#//***@#g')"
  fi
  rm -f /tmp/fiberlink-fetch.err
else
  ERROR="Instalação sem repositório Git remoto"
fi

AVAILABLE=false
if [[ -n "$TARGET_REV" && "$TARGET_REV" != "$INSTALLED_REV" ]]; then
  # Não oferece "atualização" para uma versão menor que a instalada
  if [[ "$(printf '%s\n%s\n' "$INSTALLED_VERSION" "$AVAILABLE_VERSION" | sort -V | tail -n1)" == "$AVAILABLE_VERSION" ]]; then
    AVAILABLE=true
  fi
fi

write_status update "{\"checked_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"installed_version\":\"${INSTALLED_VERSION}\",\"installed_revision\":\"${INSTALLED_REV:0:12}\",\"available_version\":\"${AVAILABLE_VERSION}\",\"target_ref\":\"$(json_escape "$TARGET_REF")\",\"target_revision\":\"${TARGET_REV:0:12}\",\"remote_configured\":${REMOTE},\"update_available\":${AVAILABLE},\"error\":\"$(json_escape "$ERROR")\"}"

if (( QUIET == 0 )); then
  dotline "Versão instalada" "v${INSTALLED_VERSION} (${INSTALLED_REV:0:12})"
  if [[ -n "$ERROR" ]]; then dotline "Versão disponível" "$ERROR" "$C_YELLOW"
  else dotline "Versão disponível" "v${AVAILABLE_VERSION} (${TARGET_REF}, ${TARGET_REV:0:12})"; fi
  if [[ "$AVAILABLE" == true ]]; then dotline "Situação" "ATUALIZAÇÃO DISPONÍVEL" "$C_YELLOW"; else dotline "Situação" "EM DIA" "$C_GREEN"; fi
fi
app_log info "Verificação de atualização: instalada v${INSTALLED_VERSION}, disponível ${AVAILABLE_VERSION:-?}${ERROR:+ ($ERROR)}"

if (( CHECK_ONLY )) || [[ "$AVAILABLE" != true ]]; then
  [[ -z "$ERROR" ]] || exit 4
  exit 0
fi
confirm "Atualizar para v${AVAILABLE_VERSION}? (backup automático antes)" "N" || exit 0
extra=()
(( ASSUME_YES )) && extra+=(--yes)
exec bash "${DEPLOY_DIR}/deploy.sh" --ref "$TARGET_REF" "${extra[@]}"
