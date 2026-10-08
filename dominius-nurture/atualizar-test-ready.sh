#!/usr/bin/env bash
# Dominius Play — atualiza só o e-mail do teste (dados de acesso + listas M3U/HLS + aplicativos). Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
F=app/Views/emails/test_ready.php
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/c9ac5c51a5e3ce32f787502d6fd3145ae56e4a33/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
TMP=$(mktemp -d); chmod 755 "$TMP"
curl -fsSL "$BASE/$F" -o "$TMP/t.php"
[ "$(sha256sum "$TMP/t.php" | cut -d' ' -f1)" = "dcd6770a9600ea1597ad6da5c1e8e1a90117e3e62dd35ef394a562d2da8a2590" ] || { echo "ERRO: arquivo veio diferente do esperado. Nada foi alterado."; exit 1; }
chmod 644 "$TMP/t.php"
sudo -u $OWNER php -l "$TMP/t.php" >/dev/null || { echo "ERRO de sintaxe. Nada foi alterado."; exit 1; }
BK=/root/backup-test-ready-$(date +%F-%H%M%S).php; cp -a "$F" "$BK"
install -o $OWNER -g $OWNER -m 644 "$TMP/t.php" "$APP/$F"
systemctl reload php8.1-fpm
rm -rf "$TMP"
echo "PRONTO. Backup do arquivo antigo em $BK"
