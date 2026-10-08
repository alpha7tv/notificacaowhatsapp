#!/usr/bin/env bash
# Dominius Play App — fase 1: painel (clientes, avisos e publicidade, versões), API de ativação e código no e-mail do teste. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/8b0b422097a8d3b011b4ef50f584865b5c3c0854/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["database/migrations/2026_10_09_000001_app_clients.sql"]="b5d9747157994a41c44aee37d148de4f0f3315f42696e15a56ff7b6f1d137f5a" ["database/migrations/2026_10_09_000002_app_devices.sql"]="d8f440b5cea2c37bd2f6420f4477fd083bea289ab523ae15fb6adbbf8faf71f9" ["database/migrations/2026_10_09_000003_app_notices.sql"]="efecf265ae92cbaef4e93d5f8636301f07423c133f4b3cf2f0865584a5e977d0" ["database/migrations/2026_10_09_000004_app_config.sql"]="8cb3b4854108dbf94ff4743f38baf9bf47aaa8a643b44d67b5c2c9c63f8eeb88" ["database/migrations/2026_10_09_000005_app_versions.sql"]="7a2e74790ec98b2e574c802e37bdf2c2b4d2944c73d7ffbf4b90582340b1eefd" ["app/Services/AppService.php"]="1cec891c4f977e06bcee5e8f316946a39ee3af14f0ca3013a41d557f73c781ba" ["app/Controllers/AppApiController.php"]="996243519e633bb194b503f8c535e8cd2b5b6d057ea48a35d5320b96e8be13ba" ["app/Controllers/Admin/AppAdminController.php"]="b0b94c736a5f6c14a7daa22509b36acd1c1b123829d7517ea073971cb7d14fa0" ["app/Views/admin/app/_nav.php"]="fb8a43893386b0a2a4682b9cd6e4b15867183c960afe07022ab70ae3c47d7121" ["app/Views/admin/app/index.php"]="2f04affe99293b06a6a04a1d76d7ccbee52f49d94ac2484b752f7f8bc8121719" ["app/Views/admin/app/clients.php"]="0505d28ffc8571f76c7d67dc8cea2b49155c622a5e1c0050a1ddc856633a837f" ["app/Views/admin/app/client_form.php"]="3da7f6f74b509290c49288c18a9f7e014480df38bc3b2c1c7eaeafe786fdc86f" ["app/Views/admin/app/notices.php"]="56c497b631f4fe46e8534f582b94896c14d2b1df5f548fcc6ec7ce6ad7f20249" ["app/Views/admin/app/notice_form.php"]="5bde44ebb6057fd02e50fbfdc0428dc0732fb715a0e1738eee5b07b97cb2b18d" ["app/Views/admin/app/config.php"]="bb9faafb622b36c93279b9a3c25a96ebca6b19d7b4a92fc34da2fe0c2411af86" ["app/Views/admin/app/versions.php"]="f8abb9a53363513b48cef688fb2a9dfec8b6ce811a7b20886e82483b67277ed3" ["app/Views/emails/test_ready.php"]="e7ba5a5f8a190f9a566e1a0112bbd47f7ec88432684038a661922fa177e665ac" )

echo "== 1/7 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  case "$f" in *.php) sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; };; esac
done

echo "== 2/7 Backup (código e banco) =="
BK=/root/backup-app-fase1-$STAMP; mkdir -p "$BK"; chmod 700 "$BK"
mysqldump "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" > "$BK/banco.sql"
for f in routes/web.php routes/admin.php app/Views/layouts/admin.php app/Services/TestGenerationService.php app/Views/emails/test_ready.php; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/7 Instalando arquivos =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
mkdir -p public/uploads/app && chown -R $OWNER:$OWNER public/uploads/app && chmod 755 public/uploads/app

echo "== 4/7 Banco de dados =="
sudo -u $OWNER php bin/console.php migrate

echo "== 5/7 Ligando no site e no painel =="
python3 - <<'PY'
import sys
ROOT = '/var/www/dominius-play/'
def patch(rel, marker, finds, repl_fn):
    p = ROOT + rel
    s = open(p, encoding='utf-8').read()
    if marker in s:
        print('[ok, já aplicado] ' + rel + ' :: ' + marker); return
    for find in finds:
        if s.count(find) == 1:
            open(p, 'w', encoding='utf-8').write(s.replace(find, repl_fn(find), 1))
            print('[alterado] ' + rel + ' :: ' + marker); return
    sys.exit('ERRO: em %s não achei o ponto de encaixe (%s)' % (rel, finds[0][:60]))
A = '\\App\\Controllers\\AppApiController::class'
N = '\\App\\Controllers\\Admin\\AppAdminController::class'
patch('app/Services/TestGenerationService.php', 'AppService::forTest', ["'result' => $result,"],
      lambda f: f + "\n                'app' => \\App\\Services\\AppService::forTest($requestId, $result),")
patch('routes/web.php', 'AppApiController', ["$router->get('/obrigado', [TestController::class, 'thanks']);"],
      lambda f: f + "\n$router->get('/app', [" + A + ", 'download']);"
                  "\n$router->get('/api/app/info', [" + A + ", 'info']);"
                  "\n$router->post('/api/app/ativar', [" + A + ", 'activate']);"
                  "\n$router->post('/api/app/atualizar', [" + A + ", 'refresh']);"
                  "\n$router->post('/api/app/aviso', [" + A + ", 'notice']);")
LOGS = "$r->get('/logs', [LogController::class, 'index']);"
def admin_routes(f):
    g = lambda m, p, h: "    $r->" + m + "('/meu-app" + p + "', [" + N + ", '" + h + "']);\n"
    return (g('get','','index') + g('get','/clientes','clients') + g('get','/clientes/novo','clientForm') + g('post','/clientes','clientSave')
        + g('get','/clientes/{id:\\d+}','clientForm') + g('post','/clientes/{id:\\d+}','clientSave') + g('post','/clientes/{id:\\d+}/codigo','clientCode')
        + g('post','/clientes/{id:\\d+}/aparelhos/limpar','clientDevicesClear') + g('post','/clientes/{id:\\d+}/excluir','clientDelete')
        + g('get','/avisos','notices') + g('get','/avisos/novo','noticeForm') + g('post','/avisos','noticeSave')
        + g('get','/avisos/{id:\\d+}','noticeForm') + g('post','/avisos/{id:\\d+}','noticeSave') + g('post','/avisos/{id:\\d+}/alternar','noticeToggle') + g('post','/avisos/{id:\\d+}/excluir','noticeDelete')
        + g('get','/configuracoes','config') + g('post','/configuracoes','configSave')
        + g('get','/versoes','versions') + g('post','/versoes','versionSave') + g('post','/versoes/{id:\\d+}/excluir','versionDelete')
        + "    " + f)
patch('routes/admin.php', 'AppAdminController', [LOGS], admin_routes)
patch('app/Views/layouts/admin.php', "$link('/admin/meu-app'",
      ["<?= $link('/admin/sequencia', 'mail-check', 'Sequência') ?>", "<?= $link('/admin/configuracoes/whatsapp', 'send', 'WhatsApp') ?>", "<?= $link('/admin/configuracoes', 'settings', 'Configurações') ?>"],
      lambda f: f + "\n        <?= $link('/admin/meu-app', 'zap', 'App Dominius') ?>")
PY
for f in routes/web.php routes/admin.php app/Views/layouts/admin.php app/Services/TestGenerationService.php app/Services/AppService.php app/Controllers/AppApiController.php app/Controllers/Admin/AppAdminController.php app/Views/emails/test_ready.php; do
  if ! sudo -u $OWNER php -l "$f" >/dev/null; then
    echo "ERRO de sintaxe em $f — restaurando os originais."
    for g in routes/web.php routes/admin.php app/Views/layouts/admin.php app/Services/TestGenerationService.php app/Views/emails/test_ready.php; do cp -a "$BK/$g" "$g"; done
    exit 1
  fi
done

echo "== 6/7 Recarregando =="
systemctl reload php8.1-fpm
rm -rf "$TMP"

echo "== 7/7 Conferindo a API =="
code=$(curl -s -o /tmp/app_info.json -w '%{http_code}' https://dominiusplay.top/api/app/info || true)
echo "API /api/app/info: HTTP $code"; head -c 300 /tmp/app_info.json; echo; rm -f /tmp/app_info.json
echo
echo "PRONTO. Painel: https://dominiusplay.top/admin/meu-app   (menu: App Dominius)"
echo "Se algo falhar, os originais estão em $BK"
