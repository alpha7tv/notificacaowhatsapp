#!/usr/bin/env bash
# Dominius Play — corrige o teste de envio do painel (completa o DDI 55) e mostra o motivo real das recusas. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/claude/blissful-planck-n771c5/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Services/NurtureService.php"]="b04e813fa675063a2d10fce81a32cef8bdf9baab01f06b39d5f112b0798b038e" ["app/Controllers/Admin/WhatsappController.php"]="8501b321296abad763d07b53f8113bde73383f55188b88ddac85c67f06302f63" )

echo "== 1/3 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f?nc=$(date +%s)" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado (cache do GitHub?). Espere 2 minutos e rode de novo. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done

echo "== 2/3 Backup =="
BK=/root/backup-whatsapp2-$STAMP; mkdir -p "$BK"
for f in "${!SUM[@]}"; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/3 Instalando =="
for f in "${!SUM[@]}"; do install -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
systemctl reload php8.1-fpm
rm -rf "$TMP"
echo
echo "PRONTO. Volte ao painel e envie o teste de novo (pode digitar só DDD + número)."
