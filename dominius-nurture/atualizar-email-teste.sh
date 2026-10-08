#!/usr/bin/env bash
# Dominius Play — e-mail do teste com listas M3U/HLS/SSIPTV e instruções dos aplicativos. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/2f75c0f7752221d3901e9e88088a3273bfe09f65/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Views/emails/test_ready.php"]="dcd6770a9600ea1597ad6da5c1e8e1a90117e3e62dd35ef394a562d2da8a2590" ["bin/nurture.php"]="9f27741bd95114868b977b13bae9a025ae52eb97d8732eb4df93538e60b7d5e0" )

echo "== 1/3 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done

echo "== 2/3 Backup =="
BK=/root/backup-email-teste-$STAMP; mkdir -p "$BK"
for f in "${!SUM[@]}"; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/3 Instalando =="
for f in "${!SUM[@]}"; do install -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
systemctl reload php8.1-fpm
rm -rf "$TMP"
echo
echo "PRONTO. Para receber um exemplo do novo e-mail (dados fictícios):"
echo "  sudo -u dominius php bin/nurture.php testmail SEU-EMAIL"
