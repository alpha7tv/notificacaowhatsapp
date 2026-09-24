#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — INSTALAÇÃO AUTOMÁTICA (Ubuntu 22.04 LTS)
#
#  Uso:   sudo ./deploy/install.sh
#
#  Modo não interativo (opcional):
#    sudo SSL_EMAIL=voce@empresa.com ADMIN_NAME="Fulano" ADMIN_EMAIL=adm@empresa.com \
#         ADMIN_PASSWORD='senha-forte-123' ./deploy/install.sh --yes
#
#  Opções:  --yes        não pergunta confirmações
#           --skip-ssl   não tenta emitir certificados (use deploy/enable-ssl.sh depois)
#           --force-os   permite versões do Ubuntu diferentes da 22.04 (não recomendado)
#
#  Pode ser executado novamente com segurança (reparo): chaves e senhas existentes
#  são preservadas e nada é apagado.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

ASSUME_YES=0
SKIP_SSL=0
FORCE_OS=0
for arg in "$@"; do
  case "$arg" in
    --yes|-y) ASSUME_YES=1 ;;
    --skip-ssl) SKIP_SSL=1 ;;
    --force-os) FORCE_OS=1 ;;
    -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $arg" ;;
  esac
done

require_root
setup_logging install
enable_error_trap
acquire_lock deploy
INSTALL_START=$(date +%s)

title "Fiber Link Notificações — instalação"

# =============================================================================
step "Verificando servidor..."
# =============================================================================
# shellcheck disable=SC1091
. /etc/os-release
if [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "22.04" ]]; then
  dotline "Ubuntu 22.04" "OK" "$C_GREEN"
elif [[ "${ID:-}" == "ubuntu" && "$FORCE_OS" == "1" ]]; then
  dotline "Ubuntu ${VERSION_ID}" "NÃO TESTADO (--force-os)" "$C_YELLOW"
else
  dotline "Ubuntu 22.04" "FALHOU (${PRETTY_NAME:-desconhecido})" "$C_RED"
  die "Este instalador foi feito para Ubuntu Server 22.04 LTS. Use --force-os por sua conta e risco."
fi

if curl -fsS --max-time 10 -o /dev/null https://archive.ubuntu.com/ubuntu/ 2>/dev/null \
   || curl -fsS --max-time 10 -o /dev/null https://api.ipify.org 2>/dev/null; then
  dotline "Internet" "OK" "$C_GREEN"
else
  dotline "Internet" "FALHOU" "$C_RED"
  die "Sem acesso à internet (necessário para instalar pacotes)."
fi

MEM_MB=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
if (( MEM_MB >= 1800 )); then
  dotline "Memória" "OK (${MEM_MB} MB)" "$C_GREEN"
elif (( MEM_MB >= 900 )); then
  dotline "Memória" "OK (${MEM_MB} MB — será criado swap)" "$C_YELLOW"
else
  dotline "Memória" "INSUFICIENTE (${MEM_MB} MB)" "$C_RED"
  die "São necessários pelo menos 1 GB de RAM."
fi

DISK_FREE_MB=$(df -Pm / | awk 'NR==2{print $4}')
if (( DISK_FREE_MB >= 4000 )); then
  dotline "Disco" "OK (${DISK_FREE_MB} MB livres)" "$C_GREEN"
else
  dotline "Disco" "INSUFICIENTE (${DISK_FREE_MB} MB livres)" "$C_RED"
  die "São necessários pelo menos 4 GB livres em /."
fi

check_port() {
  local port="$1" owner
  owner=$(ss -Hltnp "sport = :${port}" 2>/dev/null | grep -oE 'users:\(\("[^"]+"' | head -n1 | sed -e 's/users:(("//' -e 's/"$//' || true)
  if [[ -z "$owner" || "$owner" == "nginx" ]]; then
    dotline "Porta ${port}" "OK" "$C_GREEN"
  else
    dotline "Porta ${port}" "EM USO por ${owner}" "$C_RED"
    die "A porta ${port} está em uso pelo programa '${owner}'. Pare/remova esse serviço (ex.: apache2) e rode novamente."
  fi
}
check_port 80
check_port 443

printf '%s\n' "DNS: verificando..."
PUBLIC_IP=$(public_ipv4 || true)
command -v dig >/dev/null 2>&1 || apt-get install -y -qq dnsutils >/dev/null 2>&1 || true
DNS_PAINEL_OK=0
DNS_API_OK=0
if dns_points_here "$PAINEL_DOMAIN" "$PUBLIC_IP"; then DNS_PAINEL_OK=1; dotline "DNS PAINEL" "OK" "$C_GREEN"; else dotline "DNS PAINEL" "PENDENTE" "$C_YELLOW"; fi
if dns_points_here "$API_DOMAIN" "$PUBLIC_IP"; then DNS_API_OK=1; dotline "DNS API" "OK" "$C_GREEN"; else dotline "DNS API" "PENDENTE" "$C_YELLOW"; fi
for d in "$PAINEL_DOMAIN" "$API_DOMAIN"; do
  if ! dns_points_here "$d" "$PUBLIC_IP"; then
    printf '\n%s\n' "${C_YELLOW}ATENÇÃO:${C_RESET}"
    printf '%s\n' "${d} ainda não aponta para esta VPS."
    printf '%s\n' "IP esperado:"
    printf '%s\n' "${PUBLIC_IP:-(não foi possível descobrir o IP público)}"
    found=$(resolve_a "$d" | tr '\n' ' ')
    printf '%s\n' "IP atual do DNS: ${found:-nenhum}"
    other=$(dns_other_ips "$d" "$PUBLIC_IP")
    [[ -n "$other" ]] && printf '%s\n' "Remova no DNS o(s) registro(s) A que apontam para: ${other}"
  fi
  if [[ -n "$(resolve_aaaa "$d")" ]]; then
    warn "${d} possui registro AAAA (IPv6). Se esse IPv6 não for desta VPS, o SSL falhará: remova o AAAA no DNS."
  fi
done
printf '\n%s\n%s\n%s\n%s\n\n' "Domínio Painel:" "$PAINEL_DOMAIN" "Domínio API:" "$API_DOMAIN"
if (( DNS_PAINEL_OK == 0 || DNS_API_OK == 0 )); then
  info "O DNS pendente NÃO impede a instalação: o sistema funcionará em HTTP e você poderá"
  info "ativar o SSL depois com: sudo ./deploy/enable-ssl.sh"
fi

REINSTALL=0
[[ -f "$ENV_FILE" ]] && REINSTALL=1
if (( REINSTALL )); then
  warn "Instalação existente detectada em ${APP_ROOT}: será executado um REPARO (dados, chaves e senhas preservados)."
fi

if ! confirm "Continuar instalação?" "S"; then
  info "Instalação cancelada. Nada foi alterado."
  exit 0
fi

# =============================================================================
step "Dados necessários"
# =============================================================================
valid_email() { [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }

SSL_EMAIL="${SSL_EMAIL:-}"
while ! valid_email "$SSL_EMAIL"; do
  is_interactive || die "Defina SSL_EMAIL para o modo não interativo."
  printf '\n%s\n' "E-mail para SSL (avisos de expiração do Let's Encrypt):"
  ask SSL_EMAIL "E-mail"
  valid_email "$SSL_EMAIL" || warn "E-mail inválido."
done

CREATE_ADMIN=1
if (( REINSTALL )) && [[ -z "${ADMIN_EMAIL:-}" ]]; then
  if ! confirm "Criar (ou redefinir) um administrador agora?" "N"; then CREATE_ADMIN=0; fi
fi
if (( CREATE_ADMIN )); then
  printf '\n%s\n' "Criar administrador:"
  ADMIN_NAME="${ADMIN_NAME:-}"
  while [[ -z "$ADMIN_NAME" ]]; do
    is_interactive || die "Defina ADMIN_NAME para o modo não interativo."
    ask ADMIN_NAME "Nome"
  done
  ADMIN_EMAIL="${ADMIN_EMAIL:-}"
  while ! valid_email "$ADMIN_EMAIL"; do
    is_interactive || die "Defina ADMIN_EMAIL para o modo não interativo."
    ask ADMIN_EMAIL "E-mail"
    valid_email "$ADMIN_EMAIL" || warn "E-mail inválido."
  done
  ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
  while (( ${#ADMIN_PASSWORD} < 10 )); do
    is_interactive || die "Defina ADMIN_PASSWORD (mín. 10 caracteres) para o modo não interativo."
    ask ADMIN_PASSWORD "Senha (mín. 10 caracteres)" "" secret
    ask ADMIN_PASSWORD2 "Confirme a senha" "" secret
    if (( ${#ADMIN_PASSWORD} < 10 )); then warn "A senha precisa ter pelo menos 10 caracteres."; ADMIN_PASSWORD=""; continue; fi
    if [[ "$ADMIN_PASSWORD" != "${ADMIN_PASSWORD2:-}" ]]; then warn "As senhas não conferem."; ADMIN_PASSWORD=""; fi
  done
fi
ok "Dados coletados. O restante é configurado automaticamente."

# =============================================================================
step "Atualizando lista de pacotes (apt update)"
# =============================================================================
APT_OPTS=(-y -q -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
if ! grep -rqsE '^deb .* universe' /etc/apt/sources.list /etc/apt/sources.list.d/ \
   && ! grep -rqs 'universe' /etc/apt/sources.list.d/*.sources 2>/dev/null; then
  apt-get "${APT_OPTS[@]}" install software-properties-common
  add-apt-repository -y universe
fi
apt-get -q -o DPkg::Lock::Timeout=600 update

# =============================================================================
step "Instalando dependências (Nginx, MariaDB, Redis, PHP, Certbot, Fail2Ban, UFW...)"
# =============================================================================
PHP_VER=$(detect_php_version)
info "Versão do PHP: ${PHP_VER}"
PACKAGES=(
  nginx mariadb-server mariadb-client redis-server
  "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-curl" "php${PHP_VER}-mbstring"
  "php${PHP_VER}-xml" "php${PHP_VER}-zip" "php${PHP_VER}-intl" "php${PHP_VER}-redis" "php${PHP_VER}-opcache"
  composer git curl unzip rsync certbot fail2ban ufw dnsutils cron logrotate openssl ca-certificates tar gzip acl qrencode
)
apt-get "${APT_OPTS[@]}" install --no-install-recommends "${PACKAGES[@]}"
ok "Pacotes instalados"
PHP_VER=$(detect_php_version)

# =============================================================================
step "Ajustando sistema (fuso horário, NTP, swap)"
# =============================================================================
timedatectl set-timezone America/Sao_Paulo || warn "Não foi possível ajustar o fuso horário"
timedatectl set-ntp true 2>/dev/null || true
ok "Fuso horário: $(timedatectl show -p Timezone --value 2>/dev/null || echo America/Sao_Paulo)"
if (( MEM_MB < 2000 )) && [[ -z "$(swapon --noheadings 2>/dev/null)" ]] && [[ ! -e /swapfile ]]; then
  fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
  ok "Swap de 2 GB criado"
fi

# =============================================================================
step "Criando usuário de sistema '${APP_USER}' (sem login, sem privilégios)"
# =============================================================================
if ! id "$APP_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$APP_ROOT" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
  ok "Usuário ${APP_USER} criado"
else
  ok "Usuário ${APP_USER} já existe"
fi
ensure_shared_dirs
ok "Estrutura de diretórios em ${APP_ROOT}"

# =============================================================================
step "Configurando MariaDB (somente localhost)"
# =============================================================================
INNODB_MB=$(( MEM_MB / 4 )); (( INNODB_MB < 128 )) && INNODB_MB=128; (( INNODB_MB > 1024 )) && INNODB_MB=1024
cat >/etc/mysql/mariadb.conf.d/60-fiberlink.cnf <<EOF
# Fiber Link Notificações
[mysqld]
bind-address = 127.0.0.1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = ${INNODB_MB}M
max_connections = 100
EOF
chmod 644 /etc/mysql/mariadb.conf.d/60-fiberlink.cnf
systemctl enable --now mariadb >/dev/null 2>&1
systemctl restart mariadb
ok "MariaDB ativo em 127.0.0.1:3306"

# =============================================================================
step "Gerando configuração (.env) e segredos"
# =============================================================================
keep_or_new() { local v; v=$(env_get "$1"); [[ -n "$v" ]] && printf '%s' "$v" || printf '%s' "$2"; }
APP_KEY=$(keep_or_new APP_KEY "base64:$(openssl rand -base64 32)")
JWT_SECRET=$(keep_or_new JWT_SECRET "$(gen_hex 48)")
WEBHOOK_SECRET=$(keep_or_new WEBHOOK_SECRET "$(gen_hex 32)")
INTERNAL_API_SECRET=$(keep_or_new INTERNAL_API_SECRET "$(gen_hex 32)")
DB_PASSWORD=$(keep_or_new DB_PASSWORD "$(gen_hex 24)")
REDIS_PASSWORD=$(keep_or_new REDIS_PASSWORD "$(gen_hex 24)")
if [[ -f "$ENV_FILE" ]]; then
  cp -a "$ENV_FILE" "${ENV_FILE}.bak-$(date +%Y%m%d%H%M%S)"
fi
cat >"${ENV_FILE}.new" <<EOF
# Gerado automaticamente por deploy/install.sh em $(date '+%Y-%m-%d %H:%M:%S').
# NÃO versionar. Permissões: root:${APP_GROUP} 640.
APP_NAME="Fiber Link Notificações"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${PAINEL_DOMAIN}
API_URL=https://${API_DOMAIN}
APP_TIMEZONE=America/Sao_Paulo

APP_KEY=${APP_KEY}
JWT_SECRET=${JWT_SECRET}
WEBHOOK_SECRET=${WEBHOOK_SECRET}
INTERNAL_API_SECRET=${INTERNAL_API_SECRET}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PREFIX=fiberlink:

STORAGE_PATH=${STORAGE_DIR}
SESSION_LIFETIME_MINUTES=$(keep_or_new SESSION_LIFETIME_MINUTES 120)

BACKUP_DIR=$(keep_or_new BACKUP_DIR "$DEFAULT_BACKUP_DIR")
BACKUP_KEEP_DAILY=$(keep_or_new BACKUP_KEEP_DAILY 7)
BACKUP_KEEP_WEEKLY=$(keep_or_new BACKUP_KEEP_WEEKLY 4)
BACKUP_KEEP_MONTHLY=$(keep_or_new BACKUP_KEEP_MONTHLY 3)
BACKUP_KEEP_SAFETY=$(keep_or_new BACKUP_KEEP_SAFETY 5)
KEEP_RELEASES=$(keep_or_new KEEP_RELEASES 5)
SSL_EMAIL=${SSL_EMAIL}
EOF
mv -f "${ENV_FILE}.new" "$ENV_FILE"
chown root:"$APP_GROUP" "$ENV_FILE"
chmod 640 "$ENV_FILE"
chmod 600 "${ENV_FILE}".bak-* 2>/dev/null || true
ok ".env criado em ${ENV_FILE} (permissão 640, segredos aleatórios)"

# =============================================================================
step "Criando banco '${DB_NAME}' e usuário exclusivo"
# =============================================================================
mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "Banco e usuário '${DB_USER}' prontos (a aplicação não usa o root do banco)"

# =============================================================================
step "Configurando Redis (somente localhost, com senha)"
# =============================================================================
cat >/etc/redis/fiberlink.conf <<EOF
# Fiber Link Notificações
bind 127.0.0.1
protected-mode yes
port 6379
requirepass ${REDIS_PASSWORD}
maxmemory 128mb
maxmemory-policy noeviction
EOF
chown redis:redis /etc/redis/fiberlink.conf
chmod 640 /etc/redis/fiberlink.conf
grep -qxF 'include /etc/redis/fiberlink.conf' /etc/redis/redis.conf || printf '\ninclude /etc/redis/fiberlink.conf\n' >>/etc/redis/redis.conf
systemctl enable redis-server >/dev/null 2>&1
systemctl restart redis-server
REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli -h 127.0.0.1 ping | grep -q PONG
ok "Redis ativo em 127.0.0.1:6379 (com senha)"

# =============================================================================
step "Obtendo o código da aplicação"
# =============================================================================
RELEASE_REF=""
if g -C "$SRC_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  if [[ -n "$(g -C "$SRC_DIR" status --porcelain 2>/dev/null)" ]]; then
    warn "Há alterações não commitadas em ${SRC_DIR}; elas NÃO serão instaladas (somente o último commit)."
  fi
  ORIGIN_URL=$(g -C "$SRC_DIR" config --get remote.origin.url || true)
  if [[ ! -d "${REPO_DIR}/.git" ]]; then
    umask 077
    g clone --quiet --no-hardlinks "$SRC_DIR" "$REPO_DIR"
    umask 027
  fi
  chmod 700 "$REPO_DIR"
  if [[ -n "$ORIGIN_URL" ]]; then
    g -C "$REPO_DIR" remote set-url origin "$ORIGIN_URL"
    info "Repositório remoto de atualizações: $(printf '%s' "$ORIGIN_URL" | sed -E 's#//[^/@]+@#//***@#')"
  else
    g -C "$REPO_DIR" remote set-url origin "$SRC_DIR"
  fi
  RELEASE_REF=$(g -C "$SRC_DIR" rev-parse HEAD)
  # Traz tudo do clone local (garante que o commit instalado exista no repositório interno)
  g -C "$REPO_DIR" fetch --quiet --tags "$SRC_DIR" '+refs/heads/*:refs/remotes/local/*'
  g -C "$REPO_DIR" cat-file -e "${RELEASE_REF}^{commit}" || die "Commit ${RELEASE_REF} não encontrado no repositório interno."
  NEW_RELEASE=$(build_release git "$RELEASE_REF" | tail -n1)
else
  warn "O diretório ${SRC_DIR} não é um repositório Git: atualizações pelo painel ficarão indisponíveis"
  warn "(use 'sudo ./deploy/deploy.sh --source DIRETORIO' para novas versões)."
  NEW_RELEASE=$(build_release dir "$SRC_DIR" | tail -n1)
fi
[[ -d "$NEW_RELEASE" ]] || die "Falha ao montar a release."
ok "Release: $(basename "$NEW_RELEASE")"

# =============================================================================
step "Configurando PHP-FPM (pool dedicado como '${APP_USER}')"
# =============================================================================
write_php_fpm_pool "$PHP_VER"
cat >"/etc/php/${PHP_VER}/mods-available/fiberlink-opcache.ini" <<'EOF'
; Fiber Link Notificações
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF
chmod 644 "/etc/php/${PHP_VER}/mods-available/fiberlink-opcache.ini"
ln -sfn "/etc/php/${PHP_VER}/mods-available/fiberlink-opcache.ini" "/etc/php/${PHP_VER}/fpm/conf.d/90-fiberlink-opcache.ini"
"php-fpm${PHP_VER}" -t >/dev/null 2>&1 || { "php-fpm${PHP_VER}" -t; die "Configuração do PHP-FPM inválida"; }
systemctl enable "php${PHP_VER}-fpm" >/dev/null 2>&1
switch_release "$NEW_RELEASE"
systemctl restart "php${PHP_VER}-fpm"
ok "PHP-FPM ${PHP_VER} ativo (socket ${FPM_SOCK})"

# =============================================================================
step "Executando migrations (tabelas, índices, constraints, chaves de idempotência)"
# =============================================================================
console migrate
console migrate:status --check
ok "Banco de dados atualizado"

if (( CREATE_ADMIN )); then
  step "Criando administrador"
  printf '%s' "$ADMIN_PASSWORD" | console admin:create --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --update
  unset ADMIN_PASSWORD ADMIN_PASSWORD2
fi
if [[ "$(console admin:count)" == "0" ]]; then
  die "Nenhum administrador ativo existe. Rode novamente o instalador e crie um administrador."
fi

# =============================================================================
step "Configurando Nginx (painel e API)"
# =============================================================================
systemctl enable nginx >/dev/null 2>&1
nginx_apply || die "Configuração do Nginx inválida"

# =============================================================================
step "Criando serviços (worker, scheduler, agente, backup)"
# =============================================================================
write_systemd_units
systemctl enable --now fiberlink-worker.service >/dev/null 2>&1
systemctl enable --now fiberlink-scheduler.timer >/dev/null 2>&1
systemctl enable --now fiberlink-agent.timer >/dev/null 2>&1
systemctl enable --now fiberlink-backup.timer >/dev/null 2>&1
systemctl restart fiberlink-worker.service
systemctl start fiberlink-scheduler.service || warn "Primeira execução do scheduler falhou (veja: journalctl -u fiberlink-scheduler)"
ok "fiberlink-worker (Restart=always), fiberlink-scheduler.timer (1/min), fiberlink-agent.timer, fiberlink-backup.timer (03:15)"

cat >/etc/logrotate.d/fiberlink <<EOF
${STORAGE_DIR}/logs/*.log ${STORAGE_DIR}/logs/deploy/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su root ${APP_GROUP}
}
${LOG_DIR}/*.log {
    monthly
    rotate 12
    compress
    missingok
    notifempty
}
EOF
chmod 644 /etc/logrotate.d/fiberlink

# =============================================================================
step "Configurando firewall (UFW) sem bloquear o SSH"
# =============================================================================
SSH_PORTS=$( { sshd -T 2>/dev/null | awk '/^port /{print $2}'; ss -Hltnp 2>/dev/null | awk '/"sshd"/{n=split($4,a,":"); print a[n]}'; } | sort -un | tr '\n' ' ')
[[ -z "${SSH_PORTS// }" ]] && SSH_PORTS="22"
for p in $SSH_PORTS; do
  ufw allow "${p}/tcp" comment 'SSH' >/dev/null
done
ufw allow 80/tcp comment 'HTTP' >/dev/null
ufw allow 443/tcp comment 'HTTPS' >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
for p in $SSH_PORTS; do
  ufw show added 2>/dev/null | grep -q "allow ${p}/tcp" || die "Regra do SSH (${p}) não foi registrada — firewall NÃO ativado por segurança."
done
if ufw status | grep -q "Status: active"; then
  ufw reload >/dev/null
else
  ufw --force enable >/dev/null
fi
ok "UFW ativo: SSH (${SSH_PORTS% }), 80 e 443 liberados. MariaDB e Redis só escutam em localhost."

# =============================================================================
step "Configurando Fail2Ban"
# =============================================================================
cat >/etc/fail2ban/filter.d/fiberlink-auth.conf <<'EOF'
[Definition]
failregex = ^\S+ \S+ FIBERLINK_AUTH_FAIL ip=<HOST>
ignoreregex =
datepattern = ^%%Y-%%m-%%d %%H:%%M:%%S
EOF
cat >/etc/fail2ban/jail.d/fiberlink.local <<EOF
[sshd]
enabled = true
backend = systemd
maxretry = 6
findtime = 10m
bantime = 1h

[fiberlink-auth]
enabled = true
port = http,https
filter = fiberlink-auth
logpath = ${STORAGE_DIR}/logs/auth-fail.log
backend = polling
maxretry = 10
findtime = 10m
bantime = 30m
EOF
chmod 644 /etc/fail2ban/filter.d/fiberlink-auth.conf /etc/fail2ban/jail.d/fiberlink.local
systemctl enable fail2ban >/dev/null 2>&1
systemctl restart fail2ban
ok "Fail2Ban ativo (SSH e login do painel)"

# =============================================================================
step "SSL (Let's Encrypt)"
# =============================================================================
if (( SKIP_SSL )); then
  warn "SSL ignorado (--skip-ssl). Ative depois com: sudo ./deploy/enable-ssl.sh"
else
  bash "${NEW_RELEASE}/deploy/enable-ssl.sh" --from-install --email "$SSL_EMAIL" \
    || warn "SSL não concluído. O sistema segue funcionando em HTTP. Rode depois: sudo ./deploy/enable-ssl.sh"
fi

# =============================================================================
step "Backup inicial"
# =============================================================================
bash "${NEW_RELEASE}/deploy/backup.sh" --label install --quiet || warn "Backup inicial falhou (veja o log)"

# =============================================================================
step "Teste pós-instalação"
# =============================================================================
sleep 3
console selftest || warn "Algum teste funcional falhou (veja acima)"
DURATION=$(( $(date +%s) - INSTALL_START ))
APP_DIR="$NEW_RELEASE" console deploy:record --version="$(release_version "$NEW_RELEASE")" --release="$(basename "$NEW_RELEASE")" \
  --kind=install --status=success --ref="${RELEASE_REF:-local}" --duration="$DURATION" --message="Instalação inicial" || true
write_status deploy "{\"status\":\"success\",\"kind\":\"install\",\"release\":\"$(basename "$NEW_RELEASE")\",\"version\":\"$(release_version "$NEW_RELEASE")\",\"finished_at\":\"$(date '+%Y-%m-%d %H:%M:%S')\"}"
app_log info "Instalação concluída ($(basename "$NEW_RELEASE"))"
HEALTH_OK=1
bash "${NEW_RELEASE}/deploy/health-check.sh" --write-status || HEALTH_OK=0

SCHEME_P="http"; cert_exists "$PAINEL_DOMAIN" && SCHEME_P="https"
SCHEME_A="http"; cert_exists "$API_DOMAIN" && SCHEME_A="https"
title "Instalação concluída em ${DURATION}s"
cat <<EOF

  Painel:  ${SCHEME_P}://${PAINEL_DOMAIN}
  API:     ${SCHEME_A}://${API_DOMAIN}/api/v1/health

  O sistema começa em MODO HOMOLOGAÇÃO (nenhuma mensagem vai para clientes reais).
  Próximos passos no painel:
    1. Integrações > SGP        (URL, app e token)
    2. Integrações > WhatsApp   (Evolution API)
    3. Homologação              (número de teste, testes e checklist)
    4. LIBERAR PRODUÇÃO

  Comandos úteis:
    sudo ./deploy/health-check.sh     verificar tudo
    sudo ./deploy/enable-ssl.sh       ativar SSL depois de corrigir o DNS
    sudo ./deploy/backup.sh           backup manual
  Log desta instalação: ${LOG_FILE}
EOF
if (( HEALTH_OK == 0 )); then
  warn "O health check apontou itens críticos com falha. Veja a lista acima e o log."
  exit 2
fi
