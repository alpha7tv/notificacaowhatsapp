#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — RESTAURAR BACKUP
#
#  Uso:  sudo ./deploy/restore.sh                 # lista os backups e pergunta qual restaurar
#        sudo ./deploy/restore.sh --file /var/backups/fiberlink/fiberlink-....tar.gz
#        --db-only    restaura apenas o banco (mantém .env e configurações atuais)
#
#  Sempre: valida o arquivo, cria um backup de segurança do estado atual, pede
#  confirmação DIGITADA, restaura, roda migrations, reinicia serviços e faz health check.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

FILE=""
DB_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --file) FILE="${2:-}"; shift ;;
    --db-only) DB_ONLY=1 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done

require_root
setup_logging restore
enable_error_trap
acquire_lock restore
is_interactive || die "A restauração exige confirmação digitada: execute em um terminal."
[[ -f "$ENV_FILE" ]] || die "Sistema não instalado. Instale primeiro (install.sh) e depois restaure o backup."
START=$(date +%s)
BACKUP_DIR=$(env_get BACKUP_DIR); BACKUP_DIR="${BACKUP_DIR:-$DEFAULT_BACKUP_DIR}"

if [[ -z "$FILE" ]]; then
  mapfile -t LIST < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fiberlink-*.tar.gz' -printf '%f\n' 2>/dev/null | sort -r)
  (( ${#LIST[@]} > 0 )) || die "Nenhum backup encontrado em ${BACKUP_DIR}."
  printf '\n%s\n' "Backups disponíveis em ${BACKUP_DIR}:"
  for i in "${!LIST[@]}"; do
    printf '  %2d) %s  (%s)\n' "$((i + 1))" "${LIST[$i]}" "$(du -h "${BACKUP_DIR}/${LIST[$i]}" | cut -f1)"
  done
  read -r -p $'\n  Número do backup a restaurar: ' choice || true
  [[ "${choice:-}" =~ ^[0-9]+$ ]] && (( choice >= 1 && choice <= ${#LIST[@]} )) || die "Opção inválida."
  FILE="${BACKUP_DIR}/${LIST[$((choice - 1))]}"
fi
[[ -f "$FILE" ]] || die "Arquivo não encontrado: ${FILE}"

step "Validando backup"
if [[ -f "${FILE}.sha256" ]]; then
  (cd "$(dirname "$FILE")" && sha256sum -c --quiet "$(basename "$FILE").sha256") || die "Checksum SHA-256 não confere: arquivo corrompido."
  ok "Checksum SHA-256 confere"
fi
WORK=$(mktemp -d /tmp/fiberlink-restore-XXXXXX)
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
tar -xzf "$FILE" -C "$WORK"
[[ -f "${WORK}/database.sql.gz" && -f "${WORK}/manifest.json" ]] || die "Backup incompleto (database.sql.gz/manifest.json ausentes)."
gzip -t "${WORK}/database.sql.gz"
ok "Arquivo íntegro"
info "Manifesto: $(tr -d '\n' <"${WORK}/manifest.json" | sed -E 's/"migrations":"[^"]*",?//')"

printf '\n%s\n' "${C_RED}${C_BOLD}AVISO:${C_RESET}"
printf '%s\n' "Esta operação irá substituir dados atuais."
(( DB_ONLY )) && printf '%s\n' "(somente o banco de dados será substituído)"
printf '%s\n' "Um backup de segurança do estado atual será criado antes."
read -r -p "  Digite RESTAURAR para confirmar: " typed || true
[[ "$typed" == "RESTAURAR" ]] || { info "Cancelado. Nada foi alterado."; exit 0; }

step "Backup de segurança do estado atual"
SAFETY=$(bash "${DEPLOY_DIR}/backup.sh" --label pre-restore --quiet | awk -F= '/^BACKUP_FILE=/{print $2}' | tail -n1)
[[ -f "$SAFETY" ]] || die "Não foi possível criar o backup de segurança — restauração cancelada."
ok "Backup de segurança: ${SAFETY}"

reactivate() {
  set +e
  maintenance_off
  systemctl start fiberlink-worker.service fiberlink-scheduler.timer fiberlink-agent.timer 2>/dev/null
  warn "Restauração interrompida. O estado anterior está em: ${SAFETY}"
  warn "Para voltar a ele: sudo ./deploy/restore.sh --file ${SAFETY}"
}
ERR_HOOK=reactivate

step "Parando serviços"
maintenance_on
systemctl stop fiberlink-worker.service fiberlink-scheduler.timer fiberlink-agent.timer
ok "worker, scheduler e agente parados"

step "Restaurando banco de dados"
mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`"
gunzip -c "${WORK}/database.sql.gz" | mysql --protocol=socket -uroot
ok "Banco restaurado"

if (( DB_ONLY == 0 )); then
  step "Restaurando .env e dados"
  if confirm "Restaurar também o .env do backup (chaves APP_KEY/senhas)? Necessário ao migrar de servidor." "S"; then
    cp -a "$ENV_FILE" "${ENV_FILE}.antes-restore-$(date +%Y%m%d%H%M%S)"
    cp "${WORK}/env/.env" "$ENV_FILE"
    chown root:"$APP_GROUP" "$ENV_FILE"; chmod 640 "$ENV_FILE"
    chmod 600 "${ENV_FILE}".antes-restore-* 2>/dev/null || true
    # Sincroniza as senhas do banco e do Redis com o .env restaurado
    DBP=$(env_get DB_PASSWORD)
    mysql --protocol=socket -uroot <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DBP}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DBP}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DBP}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DBP}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    RP=$(env_get REDIS_PASSWORD)
    if [[ -f /etc/redis/fiberlink.conf && -n "$RP" ]]; then
      sed -i "s/^requirepass .*/requirepass ${RP}/" /etc/redis/fiberlink.conf
      systemctl restart redis-server
    fi
    ok ".env restaurado e senhas sincronizadas"
  fi
  if [[ -d "${WORK}/storage/uploads" ]]; then
    rsync -a "${WORK}/storage/uploads/" "${STORAGE_DIR}/uploads/"
    chown -R "$APP_USER":"$APP_GROUP" "${STORAGE_DIR}/uploads"
  fi
fi

step "Aplicando migrations da versão atual do código"
console migrate
console migrate:status --check
ok "Esquema do banco compatível com a versão instalada"

step "Reiniciando serviços"
PHP_VER=$(detect_php_version)
systemctl reload "php${PHP_VER}-fpm"
maintenance_off
systemctl start fiberlink-agent.timer
services_restart_app
ERR_HOOK=""
ok "Serviços reiniciados"

step "Health check"
HC=0
bash "${DEPLOY_DIR}/health-check.sh" --wait=30 --write-status || HC=1
console deploy:record --version="$(release_version "$(current_release)")" --release="$(basename "$(current_release)")" --kind=restore \
  --status="$([[ $HC == 0 ]] && echo success || echo failed)" --backup="$FILE" --duration="$(( $(date +%s) - START ))" \
  --message="Restore de $(basename "$FILE")" || true
app_log warning "Backup restaurado: $(basename "$FILE") (segurança: $(basename "$SAFETY"))"
(( HC == 0 )) || { warn "Restaurado, mas o health check apontou falhas (veja acima)."; exit 1; }
title "Restauração concluída"
info "Estado anterior guardado em: ${SAFETY}"
