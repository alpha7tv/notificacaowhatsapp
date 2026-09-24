#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — DEPLOY DE NOVA VERSÃO (com rollback automático)
#
#  Uso:  sudo ./deploy/deploy.sh                 # última tag v* (ou branch padrão) do repositório
#        sudo ./deploy/deploy.sh --ref v1.2.4    # versão específica (tag, branch ou commit)
#        sudo ./deploy/deploy.sh --source /caminho/do/codigo   # sem Git (ex.: ZIP extraído)
#        --yes  não pede confirmação
#
#  Fluxo: backup → registra versão atual → baixa nova versão → dependências → (frontend) →
#  migrations (em modo manutenção, se houver) → troca atômica da release → reinicia worker/
#  scheduler → valida Nginx → health check → confirma. Qualquer falha crítica => ROLLBACK
#  automático do código. O banco NUNCA é revertido automaticamente (veja rollback.sh/restore.sh).
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

REF=""
SOURCE=""
ASSUME_YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) REF="${2:-}"; shift ;;
    --source) SOURCE="${2:-}"; shift ;;
    --yes|-y) ASSUME_YES=1 ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done

require_root
setup_logging deploy
enable_error_trap
acquire_lock deploy
START=$(date +%s)
[[ -f "$ENV_FILE" && -L "$CURRENT_LINK" ]] || die "Sistema não instalado. Use primeiro: sudo ./deploy/install.sh"

PREV_RELEASE=$(current_release)
PREV_VERSION=$(release_version "$PREV_RELEASE")
NEW_RELEASE=""
SWITCHED=0
BACKUP_FILE=""

record() { # record status mensagem
  APP_DIR="$(current_release)" console deploy:record --version="$(release_version "${NEW_RELEASE:-$PREV_RELEASE}")" \
    --release="$(basename "${NEW_RELEASE:-?}")" --previous="$(basename "$PREV_RELEASE")" --kind=deploy --status="$1" \
    --backup="$BACKUP_FILE" --ref="${REF:-${SOURCE:-}}" --duration="$(( $(date +%s) - START ))" --message="$2" >/dev/null 2>&1 || true
  write_status deploy "{\"status\":\"$1\",\"kind\":\"deploy\",\"release\":\"$(basename "$(current_release)")\",\"version\":\"$(release_version "$(current_release)")\",\"attempted\":\"$(basename "${NEW_RELEASE:-}")\",\"finished_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"message\":\"$(json_escape "$2")\"}"
}

auto_rollback() {
  set +e
  printf '\n%s\n' "${C_RED}${C_BOLD}>>> ROLLBACK AUTOMÁTICO${C_RESET}"
  if (( SWITCHED )) && [[ -d "$PREV_RELEASE" ]]; then
    switch_release "$PREV_RELEASE"
    services_restart_app
    nginx_apply >/dev/null 2>&1
    info "Código restaurado para $(basename "$PREV_RELEASE") (v${PREV_VERSION})"
  fi
  maintenance_off
  systemctl start fiberlink-worker.service fiberlink-scheduler.timer 2>/dev/null
  record rolled_back "Falha na etapa: ${CURRENT_STEP}"
  if [[ -n "$NEW_RELEASE" && -d "$NEW_RELEASE" && "$NEW_RELEASE" != "$PREV_RELEASE" ]]; then
    rm -rf "${NEW_RELEASE:?}"
    info "Release com falha removida"
  fi
  bash "$(current_release)/deploy/health-check.sh" --write-status --quiet >/dev/null 2>&1
  app_log error "Deploy falhou na etapa '${CURRENT_STEP}' — código revertido para v${PREV_VERSION}"
  if [[ -n "$BACKUP_FILE" ]]; then
    warn "O banco NÃO foi revertido. Se as migrations desta versão causaram problema, restaure o backup:"
    warn "  sudo ./deploy/restore.sh --file ${BACKUP_FILE}"
  fi
}
ERR_HOOK=auto_rollback

title "Deploy — versão atual v${PREV_VERSION} ($(basename "$PREV_RELEASE"))"

# 1-2) -------------------------------------------------------------------------
step "1/13 Backup antes da atualização"
BACKUP_OUT=$(bash "${DEPLOY_DIR}/backup.sh" --label pre-deploy --quiet)
BACKUP_FILE=$(printf '%s\n' "$BACKUP_OUT" | awk -F= '/^BACKUP_FILE=/{print $2}' | tail -n1)
[[ -f "$BACKUP_FILE" ]] || die "Backup não foi criado — deploy cancelado por segurança."
ok "Backup: ${BACKUP_FILE}"

step "2/13 Registrando versão atual"
info "Release atual: $(basename "$PREV_RELEASE") (v${PREV_VERSION}, revisão $(cat "${PREV_RELEASE}/REVISION" 2>/dev/null | cut -c1-12))"

# 3) ---------------------------------------------------------------------------
step "3/13 Baixando nova versão"
if [[ -n "$SOURCE" ]]; then
  [[ -f "${SOURCE}/VERSION" && -f "${SOURCE}/bin/console" ]] || die "Diretório ${SOURCE} não parece conter o sistema."
  MODE=dir; SRC="$SOURCE"
else
  [[ -d "${REPO_DIR}/.git" ]] || die "Instalação sem repositório Git. Use: sudo ./deploy/deploy.sh --source /caminho/do/codigo"
  g -C "$REPO_DIR" fetch --quiet --tags --prune --force origin '+refs/heads/*:refs/remotes/origin/*' \
    || die "Não foi possível baixar do repositório remoto ($(g -C "$REPO_DIR" remote get-url origin | sed -E 's#//[^/@]+@#//***@#'))."
  g -C "$REPO_DIR" remote set-head origin --auto >/dev/null 2>&1 || true
  if [[ -z "$REF" ]]; then REF=$(resolve_target_ref) || die "Não foi possível determinar a versão a implantar."; fi
  if ! g -C "$REPO_DIR" rev-parse --verify --quiet "${REF}^{commit}" >/dev/null; then
    if g -C "$REPO_DIR" rev-parse --verify --quiet "origin/${REF}^{commit}" >/dev/null; then REF="origin/${REF}"
    else die "Referência '${REF}' não encontrada no repositório."; fi
  fi
  NEW_REV=$(g -C "$REPO_DIR" rev-parse "${REF}^{commit}")
  NEW_VERSION=$(g -C "$REPO_DIR" show "${NEW_REV}:VERSION" | tr -d '[:space:]')
  info "Implantando ${REF} → v${NEW_VERSION} (revisão ${NEW_REV:0:12})"
  if [[ "$NEW_REV" == "$(cat "${PREV_RELEASE}/REVISION" 2>/dev/null)" ]]; then
    ok "Esta revisão já está instalada. Nada a fazer."
    exit 0
  fi
  MODE=git; SRC="$NEW_REV"
fi
if ! confirm "Implantar a nova versão agora?" "S"; then info "Cancelado."; exit 0; fi

# 4-5) -------------------------------------------------------------------------
step "4/13 Montando release e instalando dependências"
# Pacotes do sistema exigidos por versões novas (instala só o que faltar)
NEEDED_PKGS=(qrencode)
for pkg in "${NEEDED_PKGS[@]}"; do
  dpkg -s "$pkg" >/dev/null 2>&1 || apt-get -y -q -o DPkg::Lock::Timeout=600 install "$pkg" >/dev/null
done
NEW_RELEASE=$(build_release "$MODE" "$SRC" | tail -n1)
[[ -d "$NEW_RELEASE" && -f "${NEW_RELEASE}/bin/console" ]] || die "Release inválida."
ok "Nova release: $(basename "$NEW_RELEASE")"
step "5/13 Compilando frontend"
ok "Não aplicável: painel renderizado no servidor (sem Node/npm)."
APP_DIR="$NEW_RELEASE" console help >/dev/null   # valida que o código novo carrega

# 6) ---------------------------------------------------------------------------
step "6/13 Migrations"
if APP_DIR="$NEW_RELEASE" console migrate:status --check >/dev/null; then
  ok "Nenhuma migration pendente"
else
  APP_DIR="$NEW_RELEASE" console migrate:status
  maintenance_on
  systemctl stop fiberlink-worker.service
  APP_DIR="$NEW_RELEASE" console migrate
  ok "Migrations aplicadas (backup pré-deploy: $(basename "$BACKUP_FILE"))"
fi

# 7-9) ------------------------------------------------------------------------
step "7/13 Ativando nova release (troca atômica) e limpando caches"
switch_release "$NEW_RELEASE"
SWITCHED=1
ok "current → $(basename "$NEW_RELEASE") (PHP-FPM recarregado, OPcache limpo)"

step "8/13 Reiniciando worker"
write_systemd_units
systemctl restart fiberlink-worker.service
ok "fiberlink-worker reiniciado"
step "9/13 Reiniciando scheduler"
systemctl restart fiberlink-scheduler.timer
systemctl start fiberlink-scheduler.service || warn "Execução imediata do scheduler falhou (será tentado a cada minuto)"
ok "fiberlink-scheduler.timer ativo"

# 10) --------------------------------------------------------------------------
step "10/13 Validando Nginx"
nginx_apply || die "nginx -t falhou"
maintenance_off

# 11) --------------------------------------------------------------------------
step "11/13 Health check pós-deploy"
bash "${NEW_RELEASE}/deploy/health-check.sh" --wait=30 --write-status || die "Health check falhou"
APP_DIR="$NEW_RELEASE" console selftest || die "Testes funcionais falharam"

# 12-13) -----------------------------------------------------------------------
step "12/13 Confirmando nova versão"
record success "Deploy de v${PREV_VERSION} para v$(release_version "$NEW_RELEASE")"
app_log info "Deploy concluído: v${PREV_VERSION} → v$(release_version "$NEW_RELEASE") ($(basename "$NEW_RELEASE"))"
ERR_HOOK=""

step "13/13 Removendo releases antigas"
KEEP=$(env_get KEEP_RELEASES); KEEP="${KEEP:-5}"
cleanup_old_releases "$KEEP" "$(basename "$PREV_RELEASE")"
bash "${NEW_RELEASE}/deploy/update.sh" --check --quiet >/dev/null 2>&1 || true

title "Deploy concluído em $(( $(date +%s) - START ))s: v${PREV_VERSION} → v$(release_version "$NEW_RELEASE")"
info "Para voltar à versão anterior: sudo ./deploy/rollback.sh"
