#!/usr/bin/env bash
# Dominius Play App — publicação automática de versões novas (a cada 10 min) + textos sem "suporte". Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/1c8a2a08e6e42ace28e4be73d29440acc5d15bb9/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Services/AppService.php"]="aa884b13a4d19427ec8f3d6df647c3864cf2fc79243775d4ec2d5eb18d23f19d" ["bin/app.php"]="6b841bd965125e6ffc32e45883234c2b2df1fe5bcb4e31e871b3986803218190" )
echo "== 1/4 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  [ "$(sha256sum "$TMP/$f" | cut -d' ' -f1)" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done
echo "== 2/4 Backup =="
BK=/root/backup-app-auto-$STAMP; mkdir -p "$BK"; chmod 700 "$BK"
for f in app/Services/AppService.php bin/app.php; do [ -f "$f" ] && cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"
echo "== 3/4 Instalando =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
cat > /etc/cron.d/dominius-app-sync <<CRON
# Publica sozinho no painel a versão nova do app compilada no GitHub
*/10 * * * * $OWNER cd $APP && /usr/bin/php bin/app.php sync >> /var/log/dominius-app-sync.log 2>&1
CRON
chmod 644 /etc/cron.d/dominius-app-sync
touch /var/log/dominius-app-sync.log; chown $OWNER:$OWNER /var/log/dominius-app-sync.log
systemctl reload php8.1-fpm
echo "== 4/4 Verificando agora =="
sudo -u $OWNER php bin/app.php sync || true
sudo -u $OWNER php bin/app.php status
rm -rf "$TMP"
echo "Pronto! Toda versão nova do app passa a aparecer sozinha no painel e nos aparelhos em até 10 minutos."
