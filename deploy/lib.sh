#!/usr/bin/env bash
# shellcheck shell=bash
# =============================================================================
#  Fiber Link Notificações — biblioteca comum dos scripts de implantação
#  (carregada com "source" por install.sh, deploy.sh, backup.sh, etc.)
# =============================================================================

# ----------------------------------------------------------------- constantes
# shellcheck disable=SC2034  # usadas pelos scripts que carregam esta biblioteca
APP_SLUG="fiberlink-notificacoes"
APP_USER="fiberlink"
APP_GROUP="fiberlink"
APP_ROOT="/var/www/${APP_SLUG}"
RELEASES_DIR="${APP_ROOT}/releases"
SHARED_DIR="${APP_ROOT}/shared"
CURRENT_LINK="${APP_ROOT}/current"
REPO_DIR="${APP_ROOT}/repo"
ENV_FILE="${SHARED_DIR}/.env"
STORAGE_DIR="${SHARED_DIR}/storage"
STATUS_DIR="${STORAGE_DIR}/status"
MAINTENANCE_FLAG="${STORAGE_DIR}/maintenance.flag"
LOG_DIR="/var/log/fiberlink"
ACME_ROOT="/var/www/letsencrypt"
DB_NAME="fiberlink_notifications"
DB_USER="fiberlink"
PAINEL_DOMAIN="${PAINEL_DOMAIN:-painel.minhafiberlink.com.br}"
API_DOMAIN="${API_DOMAIN:-api.minhafiberlink.com.br}"
DEFAULT_BACKUP_DIR="/var/backups/fiberlink"
FPM_SOCK="/run/php/fiberlink-fpm.sock"
SYSTEMD_DIR="/etc/systemd/system"

DEPLOY_DIR="${DEPLOY_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
# shellcheck disable=SC2034
SRC_DIR="$(cd "${DEPLOY_DIR}/.." && pwd)"

CURRENT_STEP="inicialização"
LOG_FILE=""
ERR_HOOK=""          # função chamada em caso de erro (ex.: rollback automático)

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=l     # needrestart só lista; não reinicia serviços no meio da instalação
export NEEDRESTART_SUSPEND=1
export LC_ALL=C.UTF-8
umask 027

# ----------------------------------------------------------------- saída
if [[ -t 1 ]]; then
  C_RESET=$'\e[0m'; C_BOLD=$'\e[1m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_RED=$'\e[31m'; C_BLUE=$'\e[34m'; C_DIM=$'\e[2m'
else
  C_RESET=""; C_BOLD=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_BLUE=""; C_DIM=""
fi

info()  { printf '%s\n' "  $*"; }
ok()    { printf '%s\n' "  ${C_GREEN}✔${C_RESET} $*"; }
warn()  { printf '%s\n' "  ${C_YELLOW}⚠ $*${C_RESET}"; }
fail_msg() { printf '%s\n' "  ${C_RED}✖ $*${C_RESET}"; }
title() { printf '\n%s\n' "${C_BOLD}${C_BLUE}== $* ==${C_RESET}"; }
step()  { CURRENT_STEP="$*"; printf '\n%s\n' "${C_BOLD}▶ $*${C_RESET}"; }

# Linha no formato "PAINEL................ OK"
dotline() {
  local label="$1" value="$2" color="${3:-}"
  local dots
  dots=$(printf '%*s' $((22 - ${#label})) '' | tr ' ' '.')
  printf '%s%s %s%s%s\n' "$label" "$dots" "$color" "$value" "$C_RESET"
}

# Encerra com falha. Passa pelo MESMO tratamento de erro do trap (bloco ETAPA/ERRO/LOG e
# ERR_HOOK, ex.: rollback automático) — um "exit" simples não dispara o trap ERR.
die() {
  fail_msg "$*"
  report_failure 1 "${BASH_LINENO[0]:-?}" "$*"
}

# ----------------------------------------------------------------- erros/log
report_failure() {
  local code="$1" line="$2" cmd="$3"
  trap - ERR
  set +e
  if (( BASH_SUBSHELL > 0 )); then
    # Erro dentro de um subprocesso: registra o detalhe e deixa o processo principal tratar.
    printf '%s\n' "  ERRO (subprocesso): '${cmd}' terminou com código ${code} (linha ${line})" >&2
    exit "$code"
  fi
  printf '\n%s\n' "${C_RED}${C_BOLD}==================== FALHA ====================${C_RESET}" >&2
  printf '%s\n' "${C_BOLD}ETAPA:${C_RESET} ${CURRENT_STEP}" >&2
  printf '%s\n' "${C_BOLD}ERRO:${C_RESET}  ${cmd} (código ${code}, linha ${line})" >&2
  printf '%s\n' "${C_BOLD}LOG:${C_RESET}   ${LOG_FILE:-(sem arquivo de log)}" >&2
  printf '%s\n' "${C_RED}===============================================${C_RESET}" >&2
  if [[ -n "$ERR_HOOK" ]] && declare -F "$ERR_HOOK" >/dev/null; then
    local hook="$ERR_HOOK"
    ERR_HOOK=""          # evita executar o hook duas vezes
    "$hook" || true
  fi
  exit "$code"
}

on_error() {
  local code=$?
  report_failure "$code" "${1:-?}" "comando '${2:-?}' falhou"
}

enable_error_trap() {
  set -Eeuo pipefail
  trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR
}

# Envia toda a saída também para um arquivo de log em /var/log/fiberlink
setup_logging() {
  local name="$1"
  mkdir -p "$LOG_DIR"
  chmod 750 "$LOG_DIR"
  LOG_FILE="${LOG_DIR}/${name}-$(date +%Y%m%d-%H%M%S).log"
  : >"$LOG_FILE"
  chmod 640 "$LOG_FILE"
  exec > >(tee -a "$LOG_FILE") 2>&1
  info "${C_DIM}Log desta execução: ${LOG_FILE}${C_RESET}"
}

require_root() {
  [[ ${EUID:-$(id -u)} -eq 0 ]] || die "Execute como root: sudo $0"
}

# Lock para impedir duas execuções simultâneas de scripts críticos
acquire_lock() {
  local name="$1"
  exec 8>"/run/fiberlink-${name}.lock"
  flock -n 8 || die "Outra operação (${name}) já está em execução. Aguarde e tente novamente."
}

# ----------------------------------------------------------------- interação
is_interactive() { [[ -t 0 ]]; }

# confirm "Pergunta" [S|N]  -> retorna 0 para sim
confirm() {
  local prompt="$1" default="${2:-N}" ans
  if [[ "${ASSUME_YES:-0}" == "1" ]]; then return 0; fi
  if ! is_interactive; then [[ "$default" == "S" ]]; return; fi
  local hint="[s/N]"; [[ "$default" == "S" ]] && hint="[S/n]"
  read -r -p "  ${prompt} ${hint} " ans || true
  ans="${ans:-$default}"
  [[ "$ans" =~ ^[SsYy] ]]
}

# ask VAR "Rótulo" [padrão] [secret]
ask() {
  local __var="$1" label="$2" default="${3:-}" secret="${4:-}" value=""
  if ! is_interactive; then
    printf -v "$__var" '%s' "$default"
    return
  fi
  if [[ -n "$secret" ]]; then
    read -r -s -p "  ${label}: " value || true
    printf '\n'
  else
    if [[ -n "$default" ]]; then
      read -r -p "  ${label} [${default}]: " value || true
    else
      read -r -p "  ${label}: " value || true
    fi
  fi
  printf -v "$__var" '%s' "${value:-$default}"
}

# ----------------------------------------------------------------- .env
env_get() {
  local key="$1" file="${2:-$ENV_FILE}" line
  [[ -f "$file" ]] || return 0
  line=$(grep -E "^${key}=" "$file" | tail -n1 || true)
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

env_set() {
  local key="$1" value="$2" file="${3:-$ENV_FILE}" tmp
  tmp=$(mktemp)
  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$file" >"$tmp"
  else
    cat "$file" >"$tmp" 2>/dev/null || true
    printf '%s=%s\n' "$key" "$value" >>"$tmp"
  fi
  cat "$tmp" >"$file"
  rm -f "$tmp"
}

gen_hex() { openssl rand -hex "${1:-32}"; }

# ----------------------------------------------------------------- git
g() { git -c safe.directory='*' "$@"; }

repo_has_remote() {
  [[ -d "${REPO_DIR}/.git" ]] && g -C "$REPO_DIR" remote get-url origin >/dev/null 2>&1
}

# Resolve a referência a implantar: última tag v* se existir, senão a branch padrão do origin.
resolve_target_ref() {
  local tag branch
  tag=$(g -C "$REPO_DIR" tag -l 'v*' --sort=-v:refname | head -n1 || true)
  if [[ -n "$tag" ]]; then
    printf 'refs/tags/%s' "$tag"
    return
  fi
  branch=$(g -C "$REPO_DIR" symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null || true)
  if [[ -z "$branch" ]]; then
    for b in origin/main origin/master; do
      if g -C "$REPO_DIR" rev-parse --verify --quiet "$b" >/dev/null; then branch="$b"; break; fi
    done
  fi
  [[ -n "$branch" ]] || return 1
  printf '%s' "$branch"
}

# ----------------------------------------------------------------- PHP / app
detect_php_version() {
  if command -v php >/dev/null 2>&1; then
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'
    return
  fi
  local v
  v=$(apt-cache depends php-fpm 2>/dev/null | awk '/Depends: php[0-9.]+-fpm/{sub(/Depends: php/,""); sub(/-fpm/,""); print; exit}' | tr -d ' ')
  [[ -n "$v" ]] || v="8.1"
  printf '%s' "$v"
}

# Executa o console da aplicação como usuário sem privilégios.
# APP_DIR permite apontar para uma release específica (padrão: current).
console() {
  local dir="${APP_DIR:-$CURRENT_LINK}"
  (cd / && runuser -u "$APP_USER" -- php "${dir}/bin/console" "$@")
}

app_log() { # app_log nivel "mensagem" — grava em SISTEMA > LOGS > Deploy
  console log:write deploy "$1" "$2" >/dev/null 2>&1 || true
}

current_release() { readlink -f "$CURRENT_LINK" 2>/dev/null || true; }

release_version() {
  local dir="$1"
  [[ -f "${dir}/VERSION" ]] && tr -d '[:space:]' <"${dir}/VERSION" || printf '?'
}

list_releases() { # mais recente primeiro
  [[ -d "$RELEASES_DIR" ]] || return 0
  find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r
}

maintenance_on()  { : >"$MAINTENANCE_FLAG"; chmod 644 "$MAINTENANCE_FLAG"; info "Modo manutenção ATIVADO"; }
maintenance_off() { rm -f "$MAINTENANCE_FLAG"; info "Modo manutenção desativado"; }

# Grava um JSON de status lido pelo painel (SISTEMA > SERVIDOR / ATUALIZAÇÕES)
write_status() {
  local name="$1" content="$2"
  mkdir -p "$STATUS_DIR"
  printf '%s\n' "$content" >"${STATUS_DIR}/${name}.json.tmp"
  chown root:"$APP_GROUP" "${STATUS_DIR}/${name}.json.tmp"
  chmod 640 "${STATUS_DIR}/${name}.json.tmp"
  mv -f "${STATUS_DIR}/${name}.json.tmp" "${STATUS_DIR}/${name}.json"
}

json_escape() {
  local s="${1//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/ }"
  s="${s//$'\r'/ }"
  s="${s//$'\t'/ }"
  printf '%s' "$s"
}

# Permissões de uma release: código pertence ao root (o usuário da aplicação NÃO pode alterá-lo,
# pois scripts desta pasta são executados como root pelo agente), leitura para o grupo fiberlink.
secure_release_permissions() {
  local rel="$1"
  chown -R root:"$APP_GROUP" "$rel"
  find "$rel" -type d -exec chmod 751 {} +
  find "$rel" -type f -exec chmod 640 {} +
  chmod 750 "${rel}/bin/console"
  find "${rel}/deploy" -type f -name '*.sh' -exec chmod 750 {} +
  # O Nginx (www-data) só precisa ler os arquivos estáticos públicos
  if [[ -d "${rel}/public/assets" ]]; then
    find "${rel}/public/assets" -type d -exec chmod 755 {} +
    find "${rel}/public/assets" -type f -exec chmod 644 {} +
  fi
  [[ -f "${rel}/public/robots.txt" ]] && chmod 644 "${rel}/public/robots.txt"
  chmod 755 "${rel}/public"
}

# Monta uma nova release a partir de um commit do repositório (ou de um diretório local).
# Uso: build_release git <ref>   |   build_release dir <caminho>
# Imprime o caminho da release criada na última linha.
build_release() {
  local mode="$1" src="$2" ts version rel revision=""
  ts=$(date +%Y%m%d%H%M%S)
  if [[ "$mode" == "git" ]]; then
    version=$(g -C "$REPO_DIR" show "${src}:VERSION" 2>/dev/null | tr -d '[:space:]')
    revision=$(g -C "$REPO_DIR" rev-parse "${src}^{commit}")
  else
    version=$(tr -d '[:space:]' <"${src}/VERSION")
    revision="local-$(date +%s)"
  fi
  [[ -n "$version" ]] || die "Arquivo VERSION não encontrado na origem ${src}"
  rel="${RELEASES_DIR}/${ts}-v${version}"
  mkdir -p "$rel"
  if [[ "$mode" == "git" ]]; then
    g -C "$REPO_DIR" archive --format=tar "$revision" | tar -x -C "$rel"
  else
    rsync -a --delete --exclude '.git' --exclude 'vendor' --exclude 'storage' --exclude '.env' --exclude 'node_modules' "${src}/" "${rel}/"
  fi
  printf '%s\n' "$revision" >"${rel}/REVISION"
  rm -rf "${rel}/storage" "${rel}/.env"
  ln -s "$STORAGE_DIR" "${rel}/storage"
  ln -s "$ENV_FILE" "${rel}/.env"

  info "Instalando dependências PHP (composer)..." >&2
  if command -v composer >/dev/null 2>&1; then
    (cd "$rel" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/root/.composer COMPOSER_NO_INTERACTION=1 \
      composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet) >&2 \
      || die "composer install falhou na release ${rel}"
  else
    warn "composer não encontrado: usando o autoloader interno da aplicação" >&2
  fi
  info "Frontend: o painel é renderizado no servidor (PHP) — não há etapa de npm/build." >&2
  secure_release_permissions "$rel"
  printf '%s\n' "$rel"
}

switch_release() {
  local rel="$1"
  ln -sfn "$rel" "${APP_ROOT}/current.tmp"
  mv -Tf "${APP_ROOT}/current.tmp" "$CURRENT_LINK"
  local v; v=$(detect_php_version)
  systemctl reload "php${v}-fpm" 2>/dev/null || systemctl restart "php${v}-fpm"
}

cleanup_old_releases() {
  local keep="${1:-5}" cur prev n=0
  cur=$(basename "$(current_release)")
  prev="${2:-}"
  while IFS= read -r r; do
    n=$((n + 1))
    if (( n <= keep )) || [[ "$r" == "$cur" ]] || [[ "$r" == "$prev" ]]; then continue; fi
    rm -rf "${RELEASES_DIR:?}/${r}"
    info "Release antiga removida: ${r}"
  done < <(list_releases)
}

# ----------------------------------------------------------------- DNS / rede
public_ipv4() {
  local ip u
  for u in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
    ip=$(curl -4 -fsS --max-time 8 "$u" 2>/dev/null | tr -d '[:space:]' || true)
    if [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then printf '%s' "$ip"; return 0; fi
  done
  return 1
}

resolve_a() {
  local d="$1" out=""
  if command -v dig >/dev/null 2>&1; then
    out=$(dig +short +time=3 +tries=2 A "$d" @1.1.1.1 2>/dev/null | grep -E '^[0-9.]+$' || true)
    [[ -z "$out" ]] && out=$(dig +short +time=3 +tries=2 A "$d" @8.8.8.8 2>/dev/null | grep -E '^[0-9.]+$' || true)
  fi
  [[ -z "$out" ]] && out=$(getent ahostsv4 "$d" 2>/dev/null | awk '{print $1}' | sort -u || true)
  printf '%s' "$out"
}

resolve_aaaa() {
  command -v dig >/dev/null 2>&1 || return 0
  dig +short +time=3 +tries=2 AAAA "$1" @1.1.1.1 2>/dev/null | grep -E ':' || true
}

# dns_points_here dominio ip -> 0 somente se TODOS os registros A do domínio forem o IP da VPS.
# (Um registro A antigo esquecido faz o Let's Encrypt validar no servidor errado.)
dns_points_here() {
  local d="$1" ip="$2" ips
  [[ -n "$ip" ]] || return 1
  ips=$(resolve_a "$d" | sort -u)
  [[ -n "$ips" ]] && [[ "$ips" == "$ip" ]]
}

# Registros A que apontam para outro lugar (para a mensagem de ajuda)
dns_other_ips() {
  resolve_a "$1" | sort -u | grep -vx "$2" | tr '\n' ' ' || true
}

# ----------------------------------------------------------------- Nginx
cert_exists() {
  [[ -s "/etc/letsencrypt/live/$1/fullchain.pem" && -s "/etc/letsencrypt/live/$1/privkey.pem" ]]
}

write_nginx_common() {
  mkdir -p /etc/nginx/snippets "$ACME_ROOT/.well-known/acme-challenge"
  chmod 755 "$ACME_ROOT" "$ACME_ROOT/.well-known" "$ACME_ROOT/.well-known/acme-challenge"

  cat >/etc/nginx/conf.d/fiberlink.conf <<'EOF'
# Fiber Link Notificações — configurações globais (contexto http)
# Log SEM query string: tokens de webhook (?token=) nunca vão para o access.log
log_format fiberlink_safe '$remote_addr - [$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent "$http_user_agent" $request_time';
limit_req_zone $binary_remote_addr zone=fl_login:10m rate=10r/m;
limit_req_zone $binary_remote_addr zone=fl_api:10m rate=20r/s;
limit_req_zone $binary_remote_addr zone=fl_webhooks:10m rate=30r/s;
EOF

  cat >/etc/nginx/snippets/fiberlink-acme.conf <<EOF
location ^~ /.well-known/acme-challenge/ {
    root ${ACME_ROOT};
    default_type text/plain;
    try_files \$uri =404;
}
EOF

  cat >/etc/nginx/snippets/fiberlink-ssl.conf <<'EOF'
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
ssl_prefer_server_ciphers off;
ssl_session_timeout 1d;
ssl_session_cache shared:FiberlinkSSL:10m;
ssl_session_tickets off;
add_header Strict-Transport-Security "max-age=31536000" always;
EOF

  cat >/etc/nginx/snippets/fiberlink-php-panel.conf <<EOF
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
fastcgi_param SCRIPT_NAME /index.php;
fastcgi_param DOCUMENT_ROOT \$realpath_root;
fastcgi_param HTTP_PROXY "";
fastcgi_pass unix:${FPM_SOCK};
fastcgi_read_timeout 120s;
EOF

  cat >/etc/nginx/snippets/fiberlink-php-api.conf <<EOF
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME \$realpath_root/api.php;
fastcgi_param SCRIPT_NAME /api.php;
fastcgi_param DOCUMENT_ROOT \$realpath_root;
fastcgi_param HTTP_PROXY "";
fastcgi_pass unix:${FPM_SOCK};
fastcgi_read_timeout 60s;
EOF

  cat >/etc/nginx/snippets/fiberlink-painel-app.conf <<EOF
root ${CURRENT_LINK}/public;
server_tokens off;
client_max_body_size 10m;
access_log /var/log/nginx/fiberlink-painel.access.log fiberlink_safe;
error_log /var/log/nginx/fiberlink-painel.error.log warn;

location ^~ /assets/ { expires 7d; access_log off; try_files \$uri =404; }
location = /robots.txt { access_log off; try_files \$uri =404; }
location ^~ /api/ { return 404; }
location ~ /\\. { deny all; }
location ~ \\.php\$ { return 404; }
location = /login { limit_req zone=fl_login burst=10 nodelay; limit_req_status 429; include snippets/fiberlink-php-panel.conf; }
location / { try_files \$uri @panel; }
location @panel { include snippets/fiberlink-php-panel.conf; }
EOF

  cat >/etc/nginx/snippets/fiberlink-api-app.conf <<EOF
root ${CURRENT_LINK}/public;
server_tokens off;
client_max_body_size 2m;
access_log /var/log/nginx/fiberlink-api.access.log fiberlink_safe;
error_log /var/log/nginx/fiberlink-api.error.log warn;

location ^~ /api/v1/internal/ {
    allow 127.0.0.1;
    allow ::1;
    deny all;
    include snippets/fiberlink-php-api.conf;
}
location ^~ /api/v1/webhooks/ {
    limit_req zone=fl_webhooks burst=60 nodelay;
    limit_req_status 429;
    include snippets/fiberlink-php-api.conf;
}
location = /api/v1/auth/login {
    limit_req zone=fl_login burst=10 nodelay;
    limit_req_status 429;
    include snippets/fiberlink-php-api.conf;
}
location ^~ /api/ {
    limit_req zone=fl_api burst=40 nodelay;
    limit_req_status 429;
    include snippets/fiberlink-php-api.conf;
}
location / {
    default_type application/json;
    return 404 '{"error":"not_found"}';
}
EOF
}

# Gera o server block de um domínio: HTTPS (com redirecionamento) somente se o certificado existir.
write_nginx_site() {
  local name="$1" domain="$2" app_snippet="$3" file="/etc/nginx/sites-available/fiberlink-${1}.conf"
  if cert_exists "$domain"; then
    cat >"$file" <<EOF
# Gerado por deploy/lib.sh — ${domain} (HTTPS)
server {
    listen 80;
    listen [::]:80;
    server_name ${domain};
    include snippets/fiberlink-acme.conf;
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${domain};
    ssl_certificate /etc/letsencrypt/live/${domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${domain}/privkey.pem;
    include snippets/fiberlink-ssl.conf;
    include snippets/fiberlink-acme.conf;
    include snippets/${app_snippet};
}
EOF
  else
    cat >"$file" <<EOF
# Gerado por deploy/lib.sh — ${domain} (HTTP; execute deploy/enable-ssl.sh quando o DNS estiver correto)
server {
    listen 80;
    listen [::]:80;
    server_name ${domain};
    include snippets/fiberlink-acme.conf;
    include snippets/${app_snippet};
}
EOF
  fi
  ln -sfn "$file" "/etc/nginx/sites-enabled/fiberlink-${name}.conf"
}

write_nginx_default() {
  cat >/etc/nginx/sites-available/fiberlink-default.conf <<'EOF'
# Recusa requisições para hosts desconhecidos (ex.: acesso direto pelo IP)
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; try_files $uri =404; }
    location / { return 444; }
}
EOF
  ln -sfn /etc/nginx/sites-available/fiberlink-default.conf /etc/nginx/sites-enabled/fiberlink-default.conf
  # Remove somente o link do site padrão do pacote (o arquivo original é mantido)
  if [[ -L /etc/nginx/sites-enabled/default ]]; then rm -f /etc/nginx/sites-enabled/default; fi
}

# Aplica a configuração do Nginx com segurança: só recarrega se "nginx -t" passar;
# caso contrário restaura os arquivos anteriores.
nginx_apply() {
  local bk; bk=$(mktemp -d)
  cp -a /etc/nginx/sites-available/fiberlink-*.conf "$bk"/ 2>/dev/null || true
  cp -a /etc/nginx/snippets/fiberlink-*.conf "$bk"/ 2>/dev/null || true
  [[ -f /etc/nginx/conf.d/fiberlink.conf ]] && cp -a /etc/nginx/conf.d/fiberlink.conf "$bk"/conf.d-fiberlink.conf
  write_nginx_common
  write_nginx_default
  write_nginx_site painel "$PAINEL_DOMAIN" fiberlink-painel-app.conf
  write_nginx_site api "$API_DOMAIN" fiberlink-api-app.conf
  if nginx -t >/tmp/fiberlink-nginx-test.log 2>&1; then
    if systemctl is-active --quiet nginx; then systemctl reload nginx; else systemctl start nginx; fi
    ok "Nginx validado (nginx -t) e recarregado"
    rm -rf "$bk"
    return 0
  fi
  fail_msg "nginx -t falhou — restaurando configuração anterior:"
  cat /tmp/fiberlink-nginx-test.log
  cp -a "$bk"/fiberlink-*.conf /etc/nginx/sites-available/ 2>/dev/null || true
  for f in "$bk"/fiberlink-*.conf; do
    [[ -e "$f" ]] || continue
    case "$(basename "$f")" in
      fiberlink-acme.conf|fiberlink-ssl.conf|fiberlink-php-*.conf|fiberlink-*-app.conf) cp -a "$f" /etc/nginx/snippets/ ;;
    esac
  done
  [[ -f "$bk/conf.d-fiberlink.conf" ]] && cp -a "$bk/conf.d-fiberlink.conf" /etc/nginx/conf.d/fiberlink.conf
  rm -rf "$bk"
  return 1
}

# ----------------------------------------------------------------- systemd
write_systemd_units() {
  local php_bin; php_bin=$(command -v php)
  cat >"${SYSTEMD_DIR}/fiberlink-worker.service" <<EOF
[Unit]
Description=Fiber Link Notificações - worker da fila de WhatsApp
After=network-online.target mariadb.service redis-server.service
Wants=network-online.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_GROUP}
WorkingDirectory=${CURRENT_LINK}
ExecStart=${php_bin} ${CURRENT_LINK}/bin/console worker:run --max-seconds=3600 --max-jobs=1000
Restart=always
RestartSec=5
TimeoutStopSec=60
KillSignal=SIGTERM
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=${STORAGE_DIR}
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-scheduler.service" <<EOF
[Unit]
Description=Fiber Link Notificações - rotinas programadas (SGP, vencimentos, reconciliação, limpeza)
After=network-online.target mariadb.service redis-server.service

[Service]
Type=oneshot
User=${APP_USER}
Group=${APP_GROUP}
WorkingDirectory=${CURRENT_LINK}
ExecStart=${php_bin} ${CURRENT_LINK}/bin/console scheduler:run
TimeoutStartSec=55min
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=${STORAGE_DIR}
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-scheduler.timer" <<'EOF'
[Unit]
Description=Fiber Link Notificações - executa o scheduler a cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=fiberlink-scheduler.service

[Install]
WantedBy=timers.target
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-agent.service" <<EOF
[Unit]
Description=Fiber Link Notificações - agente de manutenção (atualizações/backup pedidos pelo painel)
After=network-online.target mariadb.service

[Service]
Type=oneshot
ExecStart=/bin/bash ${CURRENT_LINK}/deploy/agent.sh
TimeoutStartSec=45min
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-agent.timer" <<'EOF'
[Unit]
Description=Fiber Link Notificações - verifica tarefas do painel a cada 30 segundos

[Timer]
OnBootSec=1min
OnUnitInactiveSec=30s
AccuracySec=5s
Unit=fiberlink-agent.service

[Install]
WantedBy=timers.target
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-backup.service" <<EOF
[Unit]
Description=Fiber Link Notificações - backup diário
After=mariadb.service

[Service]
Type=oneshot
ExecStart=/bin/bash ${CURRENT_LINK}/deploy/backup.sh --label daily --quiet
TimeoutStartSec=2h
EOF

  cat >"${SYSTEMD_DIR}/fiberlink-backup.timer" <<'EOF'
[Unit]
Description=Fiber Link Notificações - backup diário às 03:15

[Timer]
OnCalendar=*-*-* 03:15:00
RandomizedDelaySec=10min
Persistent=true
Unit=fiberlink-backup.service

[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
}

write_php_fpm_pool() {
  local v="$1" mem_mb children
  mem_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
  children=$(( mem_mb / 80 ))
  (( children < 5 )) && children=5
  (( children > 30 )) && children=30
  cat >"/etc/php/${v}/fpm/pool.d/fiberlink.conf" <<EOF
; Fiber Link Notificações — pool dedicado, executa como usuário sem privilégios
[fiberlink]
user = ${APP_USER}
group = ${APP_GROUP}
listen = ${FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = ${children}
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500
request_terminate_timeout = 120s
catch_workers_output = yes
clear_env = yes
php_admin_flag[log_errors] = on
php_admin_value[error_log] = ${STORAGE_DIR}/logs/php-error.log
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 120
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 10M
php_admin_value[session.save_path] = ${STORAGE_DIR}/sessions
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
php_admin_value[date.timezone] = America/Sao_Paulo
EOF
}

# Diretórios persistentes compartilhados entre releases
ensure_shared_dirs() {
  install -d -m 751 -o root -g root "$APP_ROOT"
  install -d -m 751 -o root -g root "$RELEASES_DIR"
  install -d -m 750 -o root -g "$APP_GROUP" "$SHARED_DIR"
  install -d -m 770 -o "$APP_USER" -g "$APP_GROUP" "$STORAGE_DIR"
  local d
  for d in logs logs/deploy sessions tmp cache status uploads; do
    install -d -m 770 -o "$APP_USER" -g "$APP_GROUP" "${STORAGE_DIR}/${d}"
  done
  # status/ e logs/deploy/ recebem arquivos gravados pelo root (agente) e lidos pelo painel
  chown root:"$APP_GROUP" "${STATUS_DIR}" "${STORAGE_DIR}/logs/deploy"
  chmod 750 "${STATUS_DIR}" "${STORAGE_DIR}/logs/deploy"
  touch "${STORAGE_DIR}/logs/auth-fail.log"
  chown "$APP_USER":"$APP_GROUP" "${STORAGE_DIR}/logs/auth-fail.log"
  chmod 660 "${STORAGE_DIR}/logs/auth-fail.log"
  # Atalhos na raiz, conforme a estrutura documentada
  ln -sfn "${STORAGE_DIR}/logs" "${APP_ROOT}/logs"
  ln -sfn "$STORAGE_DIR" "${APP_ROOT}/storage"
}

services_restart_app() {
  systemctl restart fiberlink-worker.service
  systemctl restart fiberlink-scheduler.timer
}
