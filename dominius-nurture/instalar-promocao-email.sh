#!/usr/bin/env bash
# Dominius Play — painel "Sequência" + promoção manual por WhatsApp e e-mail + acompanhamento de 4h. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/ca1c2738b8223aaed78158cde57d20fe367923ea/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["config/nurture_whatsapp.php"]="b0defb2be3f3c5ec854bd3b7074981d9e3f6d6b65f81eb2413b9fee5b271de9e" ["database/migrations/2026_10_08_000002_nurture_followup.sql"]="07d23c1604180ae0a30fe9bccbc147a5abeeabc253b460fa70891a9e629ddda4" ["database/migrations/2026_10_08_000003_nurture_broadcasts.sql"]="21d7ef702b0054a1e7486470ea912ddd0fbb28856f316240e6fc1537e8b8ef6e" ["database/migrations/2026_10_08_000004_nurture_broadcast_items.sql"]="f487c4453e5f2cd5ceeaf60348a52bf67eb85aaf414f1035848ed528106d8fdf" ["database/migrations/2026_10_08_000005_broadcast_email_cols.sql"]="55e3a81867fa1f5501504992d7ee4c0fd9f39c6d7c3dd39118ce107e95c8c06d" ["database/migrations/2026_10_08_000006_broadcast_item_channel.sql"]="f0791e9843a79fac9562d0ea23a3b19764b64ed01b200e61810b48fd817abadd" ["app/Services/NurtureService.php"]="f4219e66189a1855d34a79a0a2fb636677e6a464a41d11f8a58fc374661e2aa1" ["app/Controllers/Admin/NurtureAdminController.php"]="52c7affde2fa9f74dcd5892e2c98181df118d5da9468871dd677035121354727" ["app/Views/admin/nurture/index.php"]="e67ad404178d7599e3a1504c87cb04e99e6eb84c268a41c17f6acb3f1b9b6977" ["app/Views/emails/promo.php"]="93790516ca097c0965aa63bf00bc5504b1502d921fe2607a8cf8bc557a07add0" ["bin/nurture.php"]="22b3512432f7366f9d1c2b873e704b97e613bc4d7a3f02f0cd13f5cbc9e5f889" )

echo "== 1/6 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  case "$f" in *.php) sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; };; esac
done

echo "== 2/6 Backup (código e banco) =="
BK=/root/backup-promocao-$STAMP; mkdir -p "$BK"; chmod 700 "$BK"
mysqldump "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" > "$BK/banco.sql"
for f in routes/admin.php app/Views/layouts/admin.php app/Services/NurtureService.php bin/nurture.php; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/6 Instalando arquivos =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done

echo "== 4/6 Banco de dados =="
sudo -u $OWNER php bin/console.php migrate

echo "== 5/6 Ligando no painel =="
python3 - <<'PY'
import sys
ROOT = '/var/www/dominius-play/'
def patch(rel, marker, find, repl):
    p = ROOT + rel
    s = open(p, encoding='utf-8').read()
    if marker in s:
        print('[ok, já aplicado] ' + rel + ' :: ' + marker); return
    if s.count(find) != 1:
        sys.exit('ERRO: em %s o trecho esperado apareceu %d vez(es): %s' % (rel, s.count(find), find[:60]))
    open(p, 'w', encoding='utf-8').write(s.replace(find, repl, 1))
    print('[alterado] ' + rel + ' :: ' + marker)
LOGS = "$r->get('/logs', [LogController::class, 'index']);"
N = '\\App\\Controllers\\Admin\\NurtureAdminController::class'
patch('routes/admin.php', "'/sequencia/{id:", LOGS,
      "$r->get('/sequencia', [" + N + ", 'index']);\n"
      "    $r->post('/sequencia/{id:\\d+}/parar', [" + N + ", 'stop']);\n"
      "    $r->post('/sequencia/{id:\\d+}/cliente', [" + N + ", 'converted']);\n"
      "    $r->post('/sequencia/{id:\\d+}/excluir', [" + N + ", 'delete']);\n"
      "    " + LOGS)
patch('routes/admin.php', "'/sequencia/promocao'", LOGS,
      "$r->post('/sequencia/promocao', [" + N + ", 'broadcast']);\n    " + LOGS)
patch('app/Views/layouts/admin.php', "/admin/sequencia",
      "<?= $link('/admin/configuracoes/whatsapp', 'send', 'WhatsApp') ?>",
      "<?= $link('/admin/configuracoes/whatsapp', 'send', 'WhatsApp') ?>\n        <?= $link('/admin/sequencia', 'mail-check', 'Sequência') ?>")
PY
for f in routes/admin.php app/Views/layouts/admin.php app/Services/NurtureService.php app/Controllers/Admin/NurtureAdminController.php app/Views/admin/nurture/index.php bin/nurture.php; do
  if ! sudo -u $OWNER php -l "$f" >/dev/null; then
    echo "ERRO de sintaxe em $f — restaurando os originais."
    for g in routes/admin.php app/Views/layouts/admin.php app/Services/NurtureService.php bin/nurture.php; do cp -a "$BK/$g" "$g"; done
    exit 1
  fi
done

echo "== 6/6 Recarregando =="
systemctl reload php8.1-fpm
rm -rf "$TMP"
sudo -u $OWNER php bin/nurture.php status
echo
echo "PRONTO. Abra no painel (logado como admin): https://dominiusplay.top/admin/sequencia"
echo "Se algo falhar, os originais estão em $BK"
