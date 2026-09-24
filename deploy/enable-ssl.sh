#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — ativa SSL (Let's Encrypt) para painel e API
#
#  Uso:  sudo ./deploy/enable-ssl.sh [--email voce@empresa.com] [--force]
#
#  - Verifica se o DNS de cada domínio aponta para esta VPS.
#  - Emite um certificado SEPARADO por domínio (um certbot por domínio).
#  - Só força HTTPS (redirecionamento 301) depois que o certificado existe de fato.
#  - Configura e testa a renovação automática.
#  Pode ser executado quantas vezes quiser.
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

EMAIL=""
FROM_INSTALL=0
FORCE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --email) EMAIL="${2:-}"; shift ;;
    --from-install) FROM_INSTALL=1 ;;
    --force) FORCE=1 ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done

require_root
(( FROM_INSTALL )) || setup_logging enable-ssl
enable_error_trap
acquire_lock ssl

[[ -f "$ENV_FILE" ]] || die "Sistema não instalado. Rode primeiro: sudo ./deploy/install.sh"
command -v certbot >/dev/null 2>&1 || die "certbot não encontrado (rode o install.sh)."
[[ -n "$EMAIL" ]] || EMAIL=$(env_get SSL_EMAIL)
if [[ -z "$EMAIL" ]]; then
  ask EMAIL "E-mail para o Let's Encrypt"
fi
[[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "E-mail inválido para o SSL."
grep -q '^SSL_EMAIL=' "$ENV_FILE" || env_set SSL_EMAIL "$EMAIL"

step "Verificando DNS"
PUBLIC_IP=$(public_ipv4 || true)
[[ -n "$PUBLIC_IP" ]] || warn "Não foi possível descobrir o IP público desta VPS."
READY=()
for d in "$PAINEL_DOMAIN" "$API_DOMAIN"; do
  label="DNS PAINEL"; [[ "$d" == "$API_DOMAIN" ]] && label="DNS API"
  if dns_points_here "$d" "$PUBLIC_IP" || (( FORCE )); then
    dotline "$label" "OK" "$C_GREEN"
    READY+=("$d")
  else
    dotline "$label" "PENDENTE" "$C_YELLOW"
    printf '%s\n%s\n%s\n%s\n' "ATENÇÃO:" "${d} ainda não aponta para esta VPS." "IP esperado:" "${PUBLIC_IP:-?}"
    other=$(dns_other_ips "$d" "$PUBLIC_IP")
    [[ -n "$other" ]] && printf '%s\n' "Remova no DNS o(s) registro(s) A que apontam para: ${other}"
  fi
done

# Garante que o Nginx esteja servindo o desafio ACME em HTTP
nginx_apply || die "Nginx inválido — SSL não configurado."

mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat >/etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh <<'EOF'
#!/bin/sh
# Recarrega o Nginx após a renovação automática do certificado
nginx -t -q && systemctl reload nginx
EOF
if [[ -d /etc/letsencrypt/renewal-hooks/deploy ]]; then
  chmod 750 /etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh
fi

ISSUED=0
FAILED=0
for d in "${READY[@]}"; do
  step "Certificado para ${d}"
  if cert_exists "$d" && openssl x509 -checkend 2592000 -noout -in "/etc/letsencrypt/live/${d}/fullchain.pem" >/dev/null 2>&1; then
    ok "Certificado válido já existe (mais de 30 dias restantes)"
    ISSUED=$((ISSUED + 1))
    continue
  fi
  # Um certbot por domínio: cada domínio recebe o SEU certificado (cert-name = domínio).
  if (umask 022; certbot certonly --webroot -w "$ACME_ROOT" -d "$d" --cert-name "$d" \
        --email "$EMAIL" --agree-tos --no-eff-email --non-interactive --keep-until-expiring); then
    if cert_exists "$d"; then
      ok "Certificado emitido para ${d}"
      ISSUED=$((ISSUED + 1))
    else
      fail_msg "certbot terminou mas o certificado de ${d} não foi encontrado"
      FAILED=$((FAILED + 1))
    fi
  else
    fail_msg "Falha ao emitir certificado para ${d} (DNS, firewall do provedor ou limite do Let's Encrypt)"
    FAILED=$((FAILED + 1))
  fi
done

# Pré-cria o hook caso o diretório só exista após o primeiro certbot
if [[ -d /etc/letsencrypt/renewal-hooks/deploy && ! -f /etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh ]]; then
  printf '#!/bin/sh\nnginx -t -q && systemctl reload nginx\n' >/etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh
  chmod 750 /etc/letsencrypt/renewal-hooks/deploy/fiberlink-reload-nginx.sh
fi

step "Aplicando HTTPS no Nginx (somente para domínios com certificado)"
nginx_apply || die "Configuração HTTPS inválida — mantida a configuração anterior."
for d in "$PAINEL_DOMAIN" "$API_DOMAIN"; do
  if cert_exists "$d"; then ok "${d}: HTTPS ativo (HTTP redireciona para HTTPS)"; else warn "${d}: continua em HTTP"; fi
done

if (( ISSUED > 0 )); then
  step "Renovação automática"
  systemctl enable --now certbot.timer >/dev/null 2>&1 || true
  if systemctl is-enabled --quiet certbot.timer 2>/dev/null; then ok "certbot.timer ativo (renovação 2x ao dia)"; else warn "certbot.timer não encontrado"; fi
  for d in "$PAINEL_DOMAIN" "$API_DOMAIN"; do
    cert_exists "$d" || continue
    if timeout 180 certbot renew --dry-run --cert-name "$d" --quiet; then ok "Teste de renovação OK: ${d}"
    else warn "Teste de renovação não concluiu para ${d} (servidor de testes do Let's Encrypt lento?). A renovação real segue agendada."; fi
  done
fi

# Atualiza o status exibido no painel
bash "${DEPLOY_DIR}/health-check.sh" --write-status --quiet || true

if (( ${#READY[@]} < 2 || FAILED > 0 )); then
  warn "SSL incompleto. Corrija o DNS (registro A dos dois domínios -> ${PUBLIC_IP:-IP da VPS}) e rode novamente:"
  warn "  sudo ./deploy/enable-ssl.sh"
  exit 3
fi
ok "SSL ativo para ${PAINEL_DOMAIN} e ${API_DOMAIN}"
