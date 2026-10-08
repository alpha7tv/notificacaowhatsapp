#!/usr/bin/env bash
# Dominius Play App — publica no painel o APK compilado no GitHub (versão 3 / 1.0.3). Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/b1b06c63b7004ade7f744e993eab8131e23add57/dominius-nurture
APK_URL=https://github.com/alpha7tv/dominiusplay-app/releases/download/build-3/dominiusplay.apk
APK_SHA=8cd31e58c9b8952fbb294fd4b13bf345f819ba66e5ce35432ff58e20b5e2dd69
CLI_SHA=83e00a55401859e9076ac7c99b9ca58c45985b80d84a3212bf61ec3ef28faa13
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
[ -f app/Services/AppService.php ] || { echo "ERRO: painel do app não instalado (rode antes o instalar-app-painel.sh)."; exit 1; }
TMP=$(mktemp -d); chmod 755 "$TMP"

echo "== 1/3 Baixando e conferindo =="
curl -fsSL "$BASE/bin/app.php" -o "$TMP/app.php"
[ "$(sha256sum "$TMP/app.php" | cut -d' ' -f1)" = "$CLI_SHA" ] || { echo "ERRO: ferramenta veio diferente. Nada foi alterado."; exit 1; }
sudo -u $OWNER php -l "$TMP/app.php" >/dev/null || { echo "ERRO de sintaxe. Nada foi alterado."; exit 1; }
curl -fsSL "$APK_URL" -o "$TMP/dominiusplay.apk"
[ "$(sha256sum "$TMP/dominiusplay.apk" | cut -d' ' -f1)" = "$APK_SHA" ] || { echo "ERRO: o APK baixado não confere. Nada foi alterado."; exit 1; }
chmod 644 "$TMP/dominiusplay.apk"

echo "== 2/3 Instalando a ferramenta =="
install -D -o $OWNER -g $OWNER -m 644 "$TMP/app.php" "$APP/bin/app.php"

echo "== 3/3 Publicando no painel =="
sudo -u $OWNER php bin/app.php publish "$TMP/dominiusplay.apk" 3 1.0.3 "--notas=Primeira versão do aplicativo Dominius Play."
sudo -u $OWNER php bin/app.php status
rm -f "$TMP/app.php" "$TMP/dominiusplay.apk"; rmdir "$TMP"
echo "Pronto! Teste o link: https://dominiusplay.top/app"
