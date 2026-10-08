#!/usr/bin/env bash
# Dominius Play — atualiza o visual e o conteúdo dos e-mails da sequência (3 arquivos). Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/claude/blissful-planck-n771c5/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["config/nurture.php"]="ed815e80f726e73721304b6d5fbb3c04c6b24c486ec8ddae8125ee7f2ffc729a" ["app/Services/NurtureService.php"]="9ac81ac0b6b459721e0a1b9f193a1cbff30423a4290c705874e8223451310196" ["app/Views/emails/nurture.php"]="03e4a190c61cdffa4ae959567f3b7180e30f8d809f6c675f488d9b3650edafcd" )

echo "== 1/4 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f?nc=$(date +%s)" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: arquivo $f veio diferente do esperado (cache do GitHub?). Espere 2 minutos e rode de novo. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done

echo "== 2/4 Backup =="
mkdir -p /root/backup-emails-$STAMP
for f in "${!SUM[@]}"; do cp -a --parents "$f" /root/backup-emails-$STAMP/; done
echo "Backup em /root/backup-emails-$STAMP"

echo "== 3/4 Instalando =="
for f in "${!SUM[@]}"; do install -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
systemctl reload php8.1-fpm
rm -rf "$TMP"

echo "== 4/4 Conferindo =="
sudo -u $OWNER php bin/nurture.php status
echo
echo "PRONTO. Para ver os novos e-mails:  sudo -u dominius php bin/nurture.php preview 1 SEU-EMAIL"
