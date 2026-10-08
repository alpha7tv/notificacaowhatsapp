#!/usr/bin/env bash
# Dominius Play App — remove o limite de aparelhos por cliente. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/7a997637bd3e75b55c442b5c18b7458f008e74a7/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Services/AppService.php"]="fb3797bd2cc9b44822d43de7880a4506e52a31d829ec3ff8a6036454a2bf38c8" ["app/Views/admin/app/client_form.php"]="35ef9392f450d6bce6434ed284e2b10599f2f0b603360243d48d6997a2971cf3" ["app/Views/admin/app/clients.php"]="196efeda5ffc8fab14247e73f7642700b64df1a49b0e408a7d124eeee9465c46" )
echo "== 1/3 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  [ "$(sha256sum "$TMP/$f" | cut -d' ' -f1)" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done
echo "== 2/3 Backup =="
BK=/root/backup-sem-limite-$(date +%F-%H%M%S); mkdir -p "$BK"; chmod 700 "$BK"
for f in "${!SUM[@]}"; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"
echo "== 3/3 Instalando =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
systemctl reload php8.1-fpm
rm -rf "$TMP"
echo "Pronto! Não existe mais limite de aparelhos."
