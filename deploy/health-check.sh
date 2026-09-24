#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — HEALTH CHECK COMPLETO
#
#  Uso:  sudo ./deploy/health-check.sh [--json] [--write-status] [--quiet] [--wait=SEGUNDOS]
#
#  Código de saída: 0 = itens críticos OK | 1 = algum item crítico falhou
#  SGP/WhatsApp "NÃO CONFIGURADO" e SSL ainda não emitido NÃO são falha.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"
set -uo pipefail

JSON=0
WRITE=0
QUIET=0
WAIT=0
for arg in "$@"; do
  case "$arg" in
    --json) JSON=1 ;;
    --write-status) WRITE=1 ;;
    --quiet) QUIET=1 ;;
    --wait=*) WAIT="${arg#--wait=}" ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
  esac
done
require_root
[[ -f "$ENV_FILE" ]] || { echo "Sistema não instalado ($ENV_FILE ausente)."; exit 1; }

PHP_VER=$(detect_php_version)
CRITICAL_FAIL=0
declare -a KEYS=() LABELS=() STATUSES=() DETAILS=()

# add chave "RÓTULO" status(ok|warn|fail|not_configured) "detalhe" critico(1|0)
add() {
  KEYS+=("$1"); LABELS+=("$2"); STATUSES+=("$3"); DETAILS+=("${4:-}")
  if [[ "$3" == "fail" && "${5:-0}" == "1" ]]; then CRITICAL_FAIL=1; fi
}

url_for() { # url_for dominio caminho -> "esquema porta url"
  local d="$1" p="$2"
  if cert_exists "$d"; then printf 'https 443 https://%s%s' "$d" "$p"; else printf 'http 80 http://%s%s' "$d" "$p"; fi
}

http_code() { # http_code dominio caminho [arquivo_header]
  local port url hdr=()
  read -r _ port url <<<"$(url_for "$1" "$2")"
  [[ -n "${3:-}" ]] && hdr=(-H "@${3}")
  curl -s -o /tmp/fiberlink-hc-body -w '%{http_code}' --max-time 15 --resolve "${1}:${port}:127.0.0.1" "${hdr[@]}" "$url" 2>/dev/null || printf '000'
}

# Espera opcional (pós-deploy: dá tempo para worker/scheduler registrarem sinal de vida)
if (( WAIT > 0 )); then
  for ((i = 0; i < WAIT; i += 5)); do
    if console health:heartbeat worker --max-age=60 >/dev/null 2>&1; then break; fi
    sleep 5
  done
fi

# ---------------------------------------------------------------- PAINEL / API
code=$(http_code "$PAINEL_DOMAIN" /login)
if [[ "$code" == "200" ]]; then add painel "PAINEL" ok "HTTP ${code}" 1; else add painel "PAINEL" fail "HTTP ${code}" 1; fi

code=$(http_code "$API_DOMAIN" /api/v1/health)
if [[ "$code" == "200" ]] && grep -q '"status":"ok"' /tmp/fiberlink-hc-body; then add api "API" ok "HTTP ${code}" 1
else add api "API" fail "HTTP ${code} $(head -c 200 /tmp/fiberlink-hc-body 2>/dev/null | tr -d '\n')" 1; fi

HDR=$(mktemp); chmod 600 "$HDR"
printf 'X-Internal-Secret: %s\n' "$(env_get INTERNAL_API_SECRET)" >"$HDR"
code=$(http_code "$API_DOMAIN" /api/v1/internal/health "$HDR")
rm -f "$HDR"
if [[ "$code" == "200" ]]; then add internal "ENDPOINT INTERNO" ok "HTTP ${code}" 1; else add internal "ENDPOINT INTERNO" fail "HTTP ${code}" 1; fi
rm -f /tmp/fiberlink-hc-body

# ---------------------------------------------------------------- NGINX / PHP
if systemctl is-active --quiet nginx && nginx -t -q >/dev/null 2>&1; then add nginx "NGINX" ok "" 1; else add nginx "NGINX" fail "inativo ou configuração inválida" 1; fi
if systemctl is-active --quiet "php${PHP_VER}-fpm"; then add php "PHP-FPM" ok "php${PHP_VER}-fpm" 1; else add php "PHP-FPM" fail "php${PHP_VER}-fpm inativo" 1; fi

# ---------------------------------------------------------------- SSL
ssl_check() {
  local key="$1" label="$2" d="$3" end days
  local f="/etc/letsencrypt/live/${d}/fullchain.pem"
  if [[ ! -s "$f" ]]; then add "$key" "$label" not_configured "sem certificado (rode enable-ssl.sh)" 0; return; fi
  end=$(openssl x509 -enddate -noout -in "$f" | cut -d= -f2)
  days=$(( ( $(date -d "$end" +%s) - $(date +%s) ) / 86400 ))
  if (( days >= 14 )); then add "$key" "$label" ok "expira em ${days} dias" 0
  elif (( days > 0 )); then add "$key" "$label" warn "expira em ${days} dias" 0
  else add "$key" "$label" fail "EXPIRADO" 1; fi
}
ssl_check ssl_painel "SSL PAINEL" "$PAINEL_DOMAIN"
ssl_check ssl_api "SSL API" "$API_DOMAIN"

# ---------------------------------------------------------------- BANCO / REDIS / MIGRATIONS
if console health:db >/dev/null 2>&1; then add banco "BANCO" ok "" 1; else add banco "BANCO" fail "conexão com o usuário da aplicação falhou" 1; fi
if [[ "$(REDISCLI_AUTH="$(env_get REDIS_PASSWORD)" redis-cli -h 127.0.0.1 --no-auth-warning ping 2>/dev/null)" == "PONG" ]]; then add redis "REDIS" ok "" 1
else add redis "REDIS" fail "sem resposta" 1; fi
if console health:migrations >/dev/null 2>&1; then add migrations "MIGRATIONS" ok "todas aplicadas" 1; else add migrations "MIGRATIONS" fail "há migrations pendentes" 1; fi

# ---------------------------------------------------------------- WORKER / SCHEDULER
if systemctl is-active --quiet fiberlink-worker.service; then
  age=$(console health:heartbeat worker --max-age=120 2>/dev/null) && add worker "WORKER" ok "sinal há ${age}s" 1 \
    || add worker "WORKER" fail "serviço ativo mas sem sinal de vida (${age:-?})" 1
else add worker "WORKER" fail "serviço fiberlink-worker parado" 1; fi
if systemctl is-active --quiet fiberlink-scheduler.timer; then
  age=$(console health:heartbeat scheduler --max-age=180 2>/dev/null) && add scheduler "SCHEDULER" ok "sinal há ${age}s" 1 \
    || add scheduler "SCHEDULER" fail "timer ativo mas sem execução recente (${age:-?})" 1
else add scheduler "SCHEDULER" fail "timer fiberlink-scheduler parado" 1; fi

# ---------------------------------------------------------------- INTEGRAÇÕES
INTEG=$(console health:integrations 2>/dev/null || true)
for pair in "sgp:SGP" "whatsapp:WHATSAPP"; do
  k="${pair%%:*}"; label="${pair#*:}"
  v=$(printf '%s\n' "$INTEG" | awk -F= -v k="$k" '$1==k{print $2}')
  case "$v" in
    ok) add "$k" "$label" ok "" 0 ;;
    not_configured) add "$k" "$label" not_configured "configure pelo painel" 0 ;;
    *) add "$k" "$label" fail "sem comunicação" 0 ;;
  esac
done

# ---------------------------------------------------------------- DISCO / PERMISSÕES / BACKUP
used=$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')
if (( used < 85 )); then add disco "DISCO" ok "${used}% usado" 0
elif (( used < 95 )); then add disco "DISCO" warn "${used}% usado" 0
else add disco "DISCO" fail "${used}% usado" 1; fi

perm_ok=1
[[ "$(stat -c '%U:%G %a' "$ENV_FILE")" == "root:${APP_GROUP} 640" ]] || perm_ok=0
for d in logs sessions tmp; do runuser -u "$APP_USER" -- test -w "${STORAGE_DIR}/${d}" || perm_ok=0; done
runuser -u "$APP_USER" -- test -w "$(current_release)/bin/console" && perm_ok=0   # código NÃO pode ser gravável pela aplicação
if (( perm_ok )); then add permissoes "PERMISSÕES" ok ".env 640, storage gravável, código somente leitura" 1
else add permissoes "PERMISSÕES" fail "verifique .env/storage/código" 1; fi

BACKUP_JSON="${STATUS_DIR}/backup.json"
if [[ -f "$BACKUP_JSON" ]] && grep -q '"status":"ok"' "$BACKUP_JSON"; then
  bage=$(( ( $(date +%s) - $(stat -c %Y "$BACKUP_JSON") ) / 3600 ))
  if (( bage <= 26 )); then add backup "BACKUP" ok "último há ${bage}h" 0; else add backup "BACKUP" warn "último há ${bage}h" 0; fi
elif systemctl is-enabled --quiet fiberlink-backup.timer 2>/dev/null; then add backup "BACKUP" warn "agendado, nenhum executado ainda" 0
else add backup "BACKUP" fail "timer de backup desativado" 0; fi

# ---------------------------------------------------------------- saída
label_of() {
  case "$1" in ok) printf 'OK' ;; warn) printf 'ATENÇÃO' ;; not_configured) printf 'NÃO CONFIGURADO' ;; *) printf 'FALHA' ;; esac
}
color_of() {
  case "$1" in ok) printf '%s' "$C_GREEN" ;; warn|not_configured) printf '%s' "$C_YELLOW" ;; *) printf '%s' "$C_RED" ;; esac
}

json='{"generated_at":"'"$(date '+%Y-%m-%d %H:%M:%S')"'","critical_ok":'"$([[ $CRITICAL_FAIL == 0 ]] && echo true || echo false)"',"checks":{'
for i in "${!KEYS[@]}"; do
  (( i > 0 )) && json+=','
  json+="\"${KEYS[$i]}\":{\"label\":\"$(json_escape "${LABELS[$i]}")\",\"status\":\"${STATUSES[$i]}\",\"detail\":\"$(json_escape "${DETAILS[$i]}")\"}"
done
json+='}}'

if (( WRITE )); then write_status health "$json"; fi
if (( JSON )); then printf '%s\n' "$json"; exit "$CRITICAL_FAIL"; fi
if (( QUIET == 0 )); then
  printf '\n'
  for i in "${!KEYS[@]}"; do
    line_detail=""
    [[ -n "${DETAILS[$i]}" && "${STATUSES[$i]}" != "ok" ]] && line_detail="  ${C_DIM}(${DETAILS[$i]})${C_RESET}"
    printf '%s%s\n' "$(dotline "${LABELS[$i]}" "$(label_of "${STATUSES[$i]}")" "$(color_of "${STATUSES[$i]}")")" "$line_detail"
  done
  printf '%s\n' "========================================"
  if (( CRITICAL_FAIL )); then printf '%s\n' "${C_RED}RESULTADO: há itens críticos com FALHA${C_RESET}"
  else printf '%s\n' "${C_GREEN}RESULTADO: sistema operacional${C_RESET}"; fi
fi
exit "$CRITICAL_FAIL"
