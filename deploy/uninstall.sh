#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — DESINSTALAR
#
#  Uso:  sudo ./deploy/uninstall.sh [--no-backup]
#
#  Remove serviços, configurações do Nginx/PHP-FPM/Fail2Ban/Redis e a pasta da aplicação.
#  Pergunta separadamente sobre: banco de dados, certificados SSL e backups.
#  NÃO remove pacotes (nginx, mariadb, php...) nem regras do firewall, pois podem
#  estar em uso por outros sistemas.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

NO_BACKUP=0
for arg in "$@"; do
  case "$arg" in
    --no-backup) NO_BACKUP=1 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $arg" ;;
  esac
done
require_root
is_interactive || die "A desinstalação exige confirmação digitada: execute em um terminal."
setup_logging uninstall
enable_error_trap
acquire_lock deploy

BACKUP_DIR=$(env_get BACKUP_DIR); BACKUP_DIR="${BACKUP_DIR:-$DEFAULT_BACKUP_DIR}"
printf '\n%s\n' "${C_RED}${C_BOLD}ATENÇÃO: o Fiber Link Notificações será removido deste servidor.${C_RESET}"
read -r -p "  Digite DESINSTALAR para confirmar: " typed || true
[[ "$typed" == "DESINSTALAR" ]] || { info "Cancelado."; exit 0; }

if (( NO_BACKUP == 0 )) && [[ -f "$ENV_FILE" ]]; then
  step "Backup final"
  bash "${DEPLOY_DIR}/backup.sh" --label pre-uninstall --quiet | tail -n1 || warn "Backup final falhou"
fi

# Copia os scripts para /tmp: esta própria pasta será apagada
SELF_TMP=$(mktemp -d)
cp -a "${DEPLOY_DIR}/." "$SELF_TMP/"

step "Parando e removendo serviços"
for u in fiberlink-worker.service fiberlink-scheduler.timer fiberlink-scheduler.service fiberlink-agent.timer \
         fiberlink-agent.service fiberlink-backup.timer fiberlink-backup.service; do
  systemctl disable --now "$u" >/dev/null 2>&1 || true
  rm -f "${SYSTEMD_DIR}/${u}"
done
systemctl daemon-reload
ok "Serviços removidos"

step "Removendo configuração do Nginx"
rm -f /etc/nginx/sites-enabled/fiberlink-*.conf /etc/nginx/sites-available/fiberlink-*.conf \
      /etc/nginx/snippets/fiberlink-*.conf /etc/nginx/conf.d/fiberlink.conf
if [[ -f /etc/nginx/sites-available/default && ! -e /etc/nginx/sites-enabled/default && -z "$(ls -A /etc/nginx/sites-enabled 2>/dev/null)" ]]; then
  ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
fi
if nginx -t >/dev/null 2>&1; then systemctl reload nginx 2>/dev/null || true; else warn "nginx -t falhou após remoção; verifique /etc/nginx"; fi
ok "Nginx"

step "Removendo PHP-FPM, Fail2Ban, Redis, logrotate e hooks"
PHP_VER=$(detect_php_version)
rm -f "/etc/php/${PHP_VER}/fpm/pool.d/fiberlink.conf" "/etc/php/${PHP_VER}/fpm/conf.d/90-fiberlink-opcache.ini" "/etc/php/${PHP_VER}/mods-available/fiberlink-opcache.ini"
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
rm -f /etc/fail2ban/jail.d/fiberlink.local /etc/fail2ban/filter.d/fiberlink-auth.conf
systemctl restart fail2ban 2>/dev/null || true
if [[ -f /etc/redis/redis.conf ]]; then
  sed -i '\#^include /etc/redis/fiberlink.conf$#d' /etc/redis/redis.conf
  rm -f /etc/redis/fiberlink.conf
  systemctl restart redis-server 2>/dev/null || true
fi
rm -f /etc/logrotate.d/fiberlink /etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh /etc/mysql/mariadb.conf.d/60-fiberlink.cnf
ok "Configurações removidas"

if confirm "Apagar o BANCO DE DADOS '${DB_NAME}' e o usuário '${DB_USER}'? (irreversível)" "N"; then
  mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;"
  ok "Banco removido"
else
  info "Banco mantido."
fi

if [[ -d /etc/letsencrypt/live ]] && confirm "Remover os certificados SSL de ${PAINEL_DOMAIN} e ${API_DOMAIN}?" "N"; then
  certbot delete --cert-name "$PAINEL_DOMAIN" --non-interactive >/dev/null 2>&1 || true
  certbot delete --cert-name "$API_DOMAIN" --non-interactive >/dev/null 2>&1 || true
  ok "Certificados removidos"
fi

step "Removendo arquivos da aplicação"
rm -f "${APP_ROOT}/current" "${APP_ROOT}/logs" "${APP_ROOT}/storage"
rm -rf "${APP_ROOT:?}"
ok "${APP_ROOT} removido"
if id "$APP_USER" >/dev/null 2>&1; then userdel "$APP_USER" 2>/dev/null || true; ok "Usuário ${APP_USER} removido"; fi

if [[ -d "$BACKUP_DIR" ]] && confirm "Apagar também os BACKUPS em ${BACKUP_DIR}?" "N"; then
  rm -rf "${BACKUP_DIR:?}"
  ok "Backups removidos"
else
  info "Backups mantidos em ${BACKUP_DIR}"
fi
rm -rf "$SELF_TMP"

title "Desinstalação concluída"
info "Pacotes (nginx, mariadb, redis, php) e regras do UFW foram mantidos."
info "Log: ${LOG_FILE}"
