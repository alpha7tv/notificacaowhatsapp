#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — BACKUP
#
#  Uso:  sudo ./deploy/backup.sh [--label NOME] [--quiet]
#
#  Salva em /var/backups/fiberlink/fiberlink-AAAA-MM-DD-HHMMSS.tar.gz :
#    banco (mysqldump), .env, configurações (Nginx, systemd, PHP-FPM, Redis, Fail2Ban),
#    arquivos de status e uploads. Dependências reconstruíveis (vendor, releases) não entram.
#  Backups com --label (pre-deploy, pre-restore, manual...) recebem o sufixo no nome.
#
#  Retenção (configurável no .env): BACKUP_KEEP_DAILY=7, BACKUP_KEEP_WEEKLY=4,
#  BACKUP_KEEP_MONTHLY=3 para os backups regulares; BACKUP_KEEP_SAFETY=5 para os com rótulo.
#  A última linha da saída é: BACKUP_FILE=<caminho>
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

LABEL=""
QUIET=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --label) LABEL="${2:-}"; shift ;;
    --quiet) QUIET=1 ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done
[[ -z "$LABEL" || "$LABEL" =~ ^[a-z][a-z-]{0,30}$ ]] || die "Rótulo inválido (use letras minúsculas e hífen)."
[[ "$LABEL" == "daily" ]] && LABEL=""

require_root
(( QUIET )) || setup_logging backup
enable_error_trap
acquire_lock backup
[[ -f "$ENV_FILE" ]] || die "Sistema não instalado ($ENV_FILE ausente)."

BACKUP_DIR=$(env_get BACKUP_DIR); BACKUP_DIR="${BACKUP_DIR:-$DEFAULT_BACKUP_DIR}"
KEEP_DAILY=$(env_get BACKUP_KEEP_DAILY); KEEP_DAILY="${KEEP_DAILY:-7}"
KEEP_WEEKLY=$(env_get BACKUP_KEEP_WEEKLY); KEEP_WEEKLY="${KEEP_WEEKLY:-4}"
KEEP_MONTHLY=$(env_get BACKUP_KEEP_MONTHLY); KEEP_MONTHLY="${KEEP_MONTHLY:-3}"
KEEP_SAFETY=$(env_get BACKUP_KEEP_SAFETY); KEEP_SAFETY="${KEEP_SAFETY:-5}"
[[ "$BACKUP_DIR" == /* && "$BACKUP_DIR" != "/" ]] || die "BACKUP_DIR inválido: ${BACKUP_DIR}"

install -d -m 700 -o root -g root "$BACKUP_DIR"
STAMP=$(date +%Y-%m-%d-%H%M%S)
NAME="fiberlink-${STAMP}${LABEL:+-$LABEL}"
TARGET="${BACKUP_DIR}/${NAME}.tar.gz"
WORK=$(mktemp -d "${BACKUP_DIR}/.tmp-XXXXXX")
cleanup_work() { rm -rf "$WORK" "${TARGET}.part"; }
backup_failed() {
  cleanup_work
  write_status backup "{\"status\":\"error\",\"file\":\"\",\"size\":0,\"finished_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"error\":\"$(json_escape "$CURRENT_STEP")\"}"
  app_log error "Backup FALHOU na etapa: ${CURRENT_STEP}"
}
ERR_HOOK=backup_failed
trap cleanup_work EXIT

step "Verificando espaço em disco"
DB_MB=$(mysql --protocol=socket -uroot -N -e "SELECT COALESCE(CEIL(SUM(data_length+index_length)/1048576),0) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
FREE_MB=$(df -Pm "$BACKUP_DIR" | awk 'NR==2{print $4}')
(( FREE_MB > DB_MB * 2 + 200 )) || die "Espaço insuficiente em ${BACKUP_DIR}: ${FREE_MB} MB livres, banco com ${DB_MB} MB."
ok "${FREE_MB} MB livres, banco com ~${DB_MB} MB"

step "Exportando banco de dados"
mysqldump --protocol=socket -uroot --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 --databases "$DB_NAME" | gzip -6 >"${WORK}/database.sql.gz"
gzip -t "${WORK}/database.sql.gz"
ok "database.sql.gz ($(du -h "${WORK}/database.sql.gz" | cut -f1))"

step "Copiando configurações"
mkdir -p "${WORK}/env" "${WORK}/config" "${WORK}/storage"
cp -a "$ENV_FILE" "${WORK}/env/.env"
PHP_VER=$(detect_php_version)
for f in /etc/nginx/sites-available/fiberlink-*.conf /etc/nginx/snippets/fiberlink-*.conf /etc/nginx/conf.d/fiberlink.conf \
         "${SYSTEMD_DIR}"/fiberlink-* "/etc/php/${PHP_VER}/fpm/pool.d/fiberlink.conf" /etc/redis/fiberlink.conf \
         /etc/mysql/mariadb.conf.d/60-fiberlink.cnf /etc/fail2ban/jail.d/fiberlink.local /etc/fail2ban/filter.d/fiberlink-auth.conf \
         /etc/logrotate.d/fiberlink /opt/evolution/.env /opt/evolution/docker-compose.yml; do
  [[ -e "$f" ]] && cp -a --parents "$f" "${WORK}/config/"
done
for d in status uploads; do
  [[ -d "${STORAGE_DIR}/${d}" ]] && cp -a "${STORAGE_DIR}/${d}" "${WORK}/storage/"
done
MIGRATIONS=$(mysql --protocol=socket -uroot -N -e "SELECT version FROM \`${DB_NAME}\`.schema_migrations ORDER BY version" 2>/dev/null | paste -sd, - || true)
CUR=$(current_release)
cat >"${WORK}/manifest.json" <<EOF
{"name":"${NAME}","created_at":"$(date '+%Y-%m-%d %H:%M:%S')","hostname":"$(hostname)","label":"${LABEL:-regular}",
 "version":"$(release_version "$CUR")","release":"$(basename "$CUR")","revision":"$(cat "${CUR}/REVISION" 2>/dev/null || true)",
 "database":"${DB_NAME}","migrations":"${MIGRATIONS}","painel":"${PAINEL_DOMAIN}","api":"${API_DOMAIN}"}
EOF
ok "Configurações e manifesto"

step "Compactando"
tar -czf "${TARGET}.part" -C "$WORK" .
tar -tzf "${TARGET}.part" ./database.sql.gz ./manifest.json ./env/.env >/dev/null
mv -f "${TARGET}.part" "$TARGET"
chmod 600 "$TARGET"
sha256sum "$TARGET" | sed "s#  .*/#  #" >"${TARGET}.sha256"
chmod 600 "${TARGET}.sha256"
SIZE=$(stat -c %s "$TARGET")
ok "Backup criado: ${TARGET} ($(du -h "$TARGET" | cut -f1))"

step "Aplicando política de retenção"
REMOVED=0
remove_backup() {
  local f="$1"
  [[ "$f" == "$TARGET" ]] && return 0                       # nunca remove o que acabou de ser criado
  [[ "$(basename "$f")" =~ ^fiberlink-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}(-[a-z-]+)?\.tar\.gz$ ]] || return 0
  rm -f -- "$f" "${f}.sha256"
  REMOVED=$((REMOVED + 1))
  info "removido: $(basename "$f")"
}
declare -A SEEN_D=() SEEN_W=() SEEN_M=()
n_d=0; n_w=0; n_m=0
# Regulares (sem rótulo): mais recente primeiro
while IFS= read -r f; do
  base=$(basename "$f")
  day="${base:10:10}"
  week=$(date -d "$day" +%G-W%V)
  month="${day:0:7}"
  keep=0
  if [[ -z "${SEEN_D[$day]:-}" ]] && (( n_d < KEEP_DAILY )); then SEEN_D[$day]=1; n_d=$((n_d + 1)); keep=1; fi
  if [[ -z "${SEEN_W[$week]:-}" ]] && (( n_w < KEEP_WEEKLY )); then SEEN_W[$week]=1; n_w=$((n_w + 1)); keep=1; fi
  if [[ -z "${SEEN_M[$month]:-}" ]] && (( n_m < KEEP_MONTHLY )); then SEEN_M[$month]=1; n_m=$((n_m + 1)); keep=1; fi
  (( keep )) || remove_backup "$f"
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -regextype posix-extended -regex '.*/fiberlink-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}\.tar\.gz' | sort -r)
# Com rótulo (segurança): mantém os N mais recentes
n=0
while IFS= read -r f; do
  n=$((n + 1))
  (( n > KEEP_SAFETY )) && remove_backup "$f"
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -regextype posix-extended -regex '.*/fiberlink-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}-[a-z-]+\.tar\.gz' | sort -r)
TOTAL=$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'fiberlink-*.tar.gz' | wc -l)
ok "Política: ${KEEP_DAILY} diários, ${KEEP_WEEKLY} semanais, ${KEEP_MONTHLY} mensais, ${KEEP_SAFETY} de segurança — ${REMOVED} removido(s), ${TOTAL} mantido(s)"

write_status backup "{\"status\":\"ok\",\"file\":\"$(basename "$TARGET")\",\"size\":${SIZE},\"label\":\"${LABEL:-regular}\",\"finished_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\",\"total\":${TOTAL},\"removed\":${REMOVED}}"
app_log info "Backup criado: $(basename "$TARGET") ($(du -h "$TARGET" | cut -f1))"
printf 'BACKUP_FILE=%s\n' "$TARGET"
