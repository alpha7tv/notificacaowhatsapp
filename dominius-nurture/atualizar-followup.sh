#!/usr/bin/env bash
# Dominius Play — acompanhamento por WhatsApp quando o teste acaba. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/089aa96b6ea3476e5209ca31b794b4cbf648e0c9/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["config/nurture_whatsapp.php"]="b0defb2be3f3c5ec854bd3b7074981d9e3f6d6b65f81eb2413b9fee5b271de9e" ["database/migrations/2026_10_08_000002_nurture_followup.sql"]="07d23c1604180ae0a30fe9bccbc147a5abeeabc253b460fa70891a9e629ddda4" ["app/Services/NurtureService.php"]="623b7c5bca5c4df28c2fee7c402ddd8bf1d0b97a1b106339f48e4ea2d9b231e6" ["bin/nurture.php"]="22b3512432f7366f9d1c2b873e704b97e613bc4d7a3f02f0cd13f5cbc9e5f889" )

echo "== 1/5 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  case "$f" in *.php) sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; };; esac
done

echo "== 2/5 Backup =="
BK=/root/backup-followup-$STAMP; mkdir -p "$BK"
mysqldump "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" > "$BK/banco.sql"
for f in app/Services/NurtureService.php bin/nurture.php; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/5 Instalando =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done

echo "== 4/5 Banco de dados =="
sudo -u $OWNER php bin/console.php migrate

echo "== 5/5 Recarregando =="
systemctl reload php8.1-fpm
rm -rf "$TMP"
sudo -u $OWNER php bin/nurture.php status
echo
echo "PRONTO. Para ver o texto no seu WhatsApp (variações 1, 2 ou 3), sem gravar nada:"
echo "  sudo -u dominius php bin/nurture.php followup SEU-NUMERO-COM-55 1"
