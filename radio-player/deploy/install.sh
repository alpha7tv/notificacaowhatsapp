#!/usr/bin/env bash
#
# Instalador do Rádio Player (app + painel) para VPS Ubuntu/Debian.
# Instala Nginx + PHP-FPM, baixa o app do GitHub, configura o site e o HTTPS.
# Rodar de novo ATUALIZA o app, mantendo rádios, anúncios e imagens (data/ e uploads/).
#
# Uso:
#   curl -fsSL https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/refs/heads/claude/adoring-ramanujan-nqorro/radio-player/deploy/install.sh \
#     | sudo bash -s -- --domain radio.seudominio.com.br --email voce@email.com
#
# Opções:
#   --domain  Domínio do app (já apontado para o IP da VPS). Sem domínio, usa o IP e fica sem HTTPS.
#   --email   E-mail para o certificado HTTPS (Let's Encrypt).
#   --ref     Branch ou tag do GitHub (padrão: claude/adoring-ramanujan-nqorro).
#   --dir     Pasta de instalação (padrão: /var/www/radio-player).

set -euo pipefail

REPO="alpha7tv/notificacaowhatsapp"
REF="claude/adoring-ramanujan-nqorro"
DIR="/var/www/radio-player"
DOMAIN=""
EMAIL=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --email) EMAIL="${2:-}"; shift 2 ;;
    --ref) REF="${2:-}"; shift 2 ;;
    --dir) DIR="${2:-}"; shift 2 ;;
    *) echo "Opção desconhecida: $1" >&2; exit 1 ;;
  esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[aviso] %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m[erro] %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Rode como root (use sudo)."
command -v apt-get >/dev/null || die "Este instalador é para Ubuntu/Debian."
DOMAIN="${DOMAIN,,}"
if [[ -n "$DOMAIN" && ! "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$ ]]; then
  die "Domínio inválido: $DOMAIN (use só o nome, ex.: radio.seudominio.com.br)"
fi
[[ "$DIR" == /* && "$DIR" != "/" ]] || die "--dir precisa ser um caminho absoluto."

# ---------------------------------------------------------------------------
say "Instalando pacotes (Nginx, PHP, Certbot)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-gd php-mbstring php-curl curl ca-certificates tar rsync >/dev/null
if [[ -n "$DOMAIN" ]]; then
  apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
fi

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' \
  || die "É preciso PHP 8.1 ou mais novo (encontrado $PHP_VER). Use Ubuntu 22.04+ ou Debian 12+."
FPM_SERVICE="php${PHP_VER}-fpm"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# Limite de upload das imagens do painel (padrão do PHP é só 2 MB).
cat > "/etc/php/${PHP_VER}/fpm/conf.d/99-radio-player.ini" <<'INI'
upload_max_filesize = 5M
post_max_size = 8M
expose_php = Off
INI

# ---------------------------------------------------------------------------
say "Baixando o app ($REPO @ $REF)…"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "https://codeload.github.com/${REPO}/tar.gz/refs/heads/${REF}" -o "$TMP/app.tar.gz" \
  || curl -fsSL "https://codeload.github.com/${REPO}/tar.gz/refs/tags/${REF}" -o "$TMP/app.tar.gz" \
  || die "Não foi possível baixar o código do GitHub."
tar -xzf "$TMP/app.tar.gz" -C "$TMP"
SRC="$(find "$TMP" -mindepth 2 -maxdepth 2 -type d -name radio-player | head -n1)"
[[ -n "$SRC" && -f "$SRC/index.html" ]] || die "Pasta radio-player não encontrada no download."

FIRST_INSTALL=1
[[ -f "$DIR/data/admin.php" ]] && FIRST_INSTALL=0

mkdir -p "$DIR"
# data/ e uploads/ guardam o que foi cadastrado no painel: nunca são sobrescritos.
rsync -a --delete \
  --exclude '/data/***' --exclude '/uploads/***' --exclude '/deploy/***' \
  "$SRC/" "$DIR/"
mkdir -p "$DIR/data" "$DIR/uploads"
# Versão nos endereços do CSS/JS: força os navegadores a baixar a versão nova.
BUILD="$(date +%Y%m%d%H%M%S)"
sed -i "s/__BUILD__/${BUILD}/g" "$DIR/index.html" "$DIR/sw.js"

for d in data uploads; do
  cp -n "$SRC/$d/.htaccess" "$DIR/$d/" 2>/dev/null || true
done

chown -R root:root "$DIR"
chown -R www-data:www-data "$DIR/data" "$DIR/uploads"
chmod 0775 "$DIR/data" "$DIR/uploads"
find "$DIR" -path "$DIR/data" -prune -o -path "$DIR/uploads" -prune -o -type f -exec chmod 0644 {} +

# ---------------------------------------------------------------------------
say "Configurando o Nginx…"
SITE="radio-player"
SERVER_NAME="${DOMAIN:-_}"
LISTEN="listen 80;"
LISTEN6="listen [::]:80;"
if [[ -z "$DOMAIN" ]]; then
  # Sem domínio: o app responde pelo IP da VPS.
  LISTEN="listen 80 default_server;"
  LISTEN6="listen [::]:80 default_server;"
  if [[ -L /etc/nginx/sites-enabled/default ]]; then
    rm -f /etc/nginx/sites-enabled/default
  fi
fi

cat > "/etc/nginx/sites-available/${SITE}" <<NGINX
# Rádio Player — gerado por radio-player/deploy/install.sh
server {
    ${LISTEN}
    ${LISTEN6}
    server_name ${SERVER_NAME};

    root ${DIR};
    index index.html index.php;
    client_max_body_size 8m;
    server_tokens off;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;

    # Dados do painel e código interno nunca ficam acessíveis pela web.
    location ~ ^/(data|lib|deploy)(/|\$) { deny all; return 404; }
    location ~ /\. { deny all; return 404; }

    # Uploads: só imagens, nada é executado.
    location ^~ /uploads/ {
        location ~ \.(php|phtml|phar|pl|py|cgi|sh)\$ { deny all; return 404; }
        expires 30d;
        try_files \$uri =404;
    }

    # Página, service worker, CSS e JS: o navegador sempre confere se há versão nova.
    location = /sw.js { add_header Cache-Control "no-cache"; try_files \$uri =404; }
    location ~* \.(css|js)\$ { add_header Cache-Control "no-cache"; try_files \$uri =404; }
    location ~* \.(svg|png|jpg|jpeg|webp|gif|ico)\$ { expires 7d; try_files \$uri =404; }

    # App de cada cliente: /r/<endereço>/ usa os mesmos arquivos, com o cliente indicado à API.
    location ^~ /r/ {
        rewrite ^/r/([a-z0-9-]+)\$ /r/\$1/ permanent;
        rewrite ^/r/([a-z0-9-]+)/(api|manifest)\.php\$ /\$2.php?t=\$1 last;
        rewrite ^/r/([a-z0-9-]+)/\$ /index.html last;
        rewrite ^/r/([a-z0-9-]+)/(.+)\$ /\$2 last;
        return 404;
    }

    location / {
        add_header Cache-Control "no-cache";
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php\$ {
        # O snippet do Debian/Ubuntu já inclui "try_files \$fastcgi_script_name =404".
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
    }
}
NGINX

ln -sf "/etc/nginx/sites-available/${SITE}" "/etc/nginx/sites-enabled/${SITE}"
if ! nginx -t; then
  rm -f "/etc/nginx/sites-enabled/${SITE}"
  die "Configuração do Nginx inválida; o site foi desativado para não afetar o servidor. Envie esta saída para o suporte."
fi
systemctl enable --now "$FPM_SERVICE" nginx >/dev/null 2>&1 || true
systemctl restart "$FPM_SERVICE"
systemctl reload nginx

if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 'Nginx Full' >/dev/null || true
fi

# ---------------------------------------------------------------------------
URL=""
if [[ -n "$DOMAIN" ]]; then
  say "Ativando HTTPS para ${DOMAIN}…"
  PUBLIC_IP="$(curl -fsS4 --max-time 5 https://api.ipify.org || true)"
  DNS_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)"
  if [[ -n "$PUBLIC_IP" && "$DNS_IP" != "$PUBLIC_IP" ]]; then
    warn "O domínio ${DOMAIN} aponta para '${DNS_IP:-nada}', mas esta VPS é ${PUBLIC_IP}."
    warn "Crie no DNS um registro A: ${DOMAIN} → ${PUBLIC_IP}, aguarde propagar e rode este instalador de novo."
    URL="http://${DOMAIN}"
  else
    CERT_ARGS=(--nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect --keep-until-expiring)
    if [[ -n "$EMAIL" ]]; then CERT_ARGS+=(-m "$EMAIL"); else CERT_ARGS+=(--register-unsafely-without-email); fi
    if certbot "${CERT_ARGS[@]}"; then
      URL="https://${DOMAIN}"
    else
      warn "Não foi possível emitir o certificado agora. O app funciona em http://${DOMAIN}; rode o instalador de novo depois."
      URL="http://${DOMAIN}"
    fi
  fi
else
  IP="$(curl -fsS4 --max-time 5 https://api.ipify.org || hostname -I | awk '{print $1}')"
  URL="http://${IP}"
fi

# ---------------------------------------------------------------------------
say "Conferindo…"
HOST_HDR="${DOMAIN:-localhost}"
# Com HTTPS ativo, a porta 80 só redireciona: confere pela 443 no próprio servidor.
if [[ "$URL" == https://* ]]; then
  API_CODE="$(curl -ksS --resolve "${DOMAIN}:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://${DOMAIN}/api.php" || true)"
else
  API_CODE="$(curl -sS -H "Host: ${HOST_HDR}" -o /dev/null -w '%{http_code}' http://127.0.0.1/api.php || true)"
fi
if [[ "$API_CODE" == "200" || "$API_CODE" == "404" ]]; then
  echo "API respondendo."
else
  warn "A API não respondeu localmente. Veja: journalctl -u ${FPM_SERVICE} -n 50"
fi
if curl -ksS -L -H "Host: ${HOST_HDR}" --resolve "${HOST_HDR}:443:127.0.0.1" -o /dev/null -w '%{http_code}' "http://127.0.0.1/data/platform.json" 2>/dev/null | grep -q 200; then
  warn "data/ está acessível pela web — verifique a configuração do Nginx!"
fi

cat <<EOF

========================================================
  Rádio Player instalado!

  App (ouvintes):   ${URL}/
  Painel:           ${URL}/admin/
EOF
if [[ $FIRST_INSTALL -eq 1 ]]; then
  cat <<EOF

  >>> Abra o painel AGORA e crie o usuário e a senha
      do administrador (o primeiro acesso define o login).
EOF
fi
if [[ "$URL" == http://* ]]; then
  cat <<EOF

  Sem HTTPS o app toca normalmente no navegador, mas os
  ouvintes só conseguem INSTALAR com HTTPS (precisa de domínio).
EOF
fi
cat <<EOF

  Atualizar no futuro: rode o mesmo comando de instalação.
  Backup: copie ${DIR}/data e ${DIR}/uploads
========================================================
EOF
