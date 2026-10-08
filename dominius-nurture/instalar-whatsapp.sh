#!/usr/bin/env bash
# Dominius Play — página "WhatsApp" no painel (status, QR code, desconectar, teste) + instância própria na Evolution. Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
INSTANCE=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/claude/blissful-planck-n771c5/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Services/EvolutionClient.php"]="045708110430c304efc94e4632c8537b7a85e7e7795014e97a85312e176e438a" ["app/Controllers/Admin/WhatsappController.php"]="46924c2819e8e748bc6e9fb59fb02d30c011c551a7ed2bbcaa898262ccf9aba4" ["app/Views/admin/settings/whatsapp.php"]="11a3d2473d7a556a417556b1eed7daca9ed0b9632c931eeb16468881f94ef214" )

echo "== 1/6 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f?nc=$(date +%s)" -o "$TMP/$f"
  got=$(sha256sum "$TMP/$f" | cut -d' ' -f1)
  [ "$got" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado (cache do GitHub?). Espere 2 minutos e rode de novo. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done

echo "== 2/6 Backup =="
BK=/root/backup-whatsapp-$STAMP; mkdir -p "$BK"; chmod 700 "$BK"
for f in routes/admin.php app/Views/layouts/admin.php .env; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"

echo "== 3/6 Instalando arquivos novos =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done

echo "== 4/6 Ligando a página no painel =="
python3 - <<'PY'
import sys
ROOT = '/var/www/dominius-play/'
def patch(rel, marker, find, repl):
    p = ROOT + rel
    s = open(p, encoding='utf-8').read()
    if marker in s:
        print('[ok, já aplicado] ' + rel); return
    if s.count(find) != 1:
        sys.exit('ERRO: em %s o trecho esperado apareceu %d vez(es): %s' % (rel, s.count(find), find[:60]))
    open(p, 'w', encoding='utf-8').write(s.replace(find, repl, 1))
    print('[alterado] ' + rel)
patch('routes/admin.php', 'WhatsappController',
      "$r->get('/logs', [LogController::class, 'index']);",
      "$r->get('/configuracoes/whatsapp', [\\App\\Controllers\\Admin\\WhatsappController::class, 'index']);\n"
      "    $r->get('/configuracoes/whatsapp/qr', [\\App\\Controllers\\Admin\\WhatsappController::class, 'qr']);\n"
      "    $r->post('/configuracoes/whatsapp/desconectar', [\\App\\Controllers\\Admin\\WhatsappController::class, 'disconnect']);\n"
      "    $r->post('/configuracoes/whatsapp/testar', [\\App\\Controllers\\Admin\\WhatsappController::class, 'test']);\n"
      "    $r->get('/logs', [LogController::class, 'index']);")
patch('app/Views/layouts/admin.php', '/admin/configuracoes/whatsapp',
      "<?= $link('/admin/configuracoes', 'settings', 'Configurações') ?>",
      "<?= $link('/admin/configuracoes', 'settings', 'Configurações') ?>\n        <?= $link('/admin/configuracoes/whatsapp', 'send', 'WhatsApp') ?>")
PY
for f in routes/admin.php app/Views/layouts/admin.php app/Services/EvolutionClient.php app/Controllers/Admin/WhatsappController.php app/Views/admin/settings/whatsapp.php; do
  if ! sudo -u $OWNER php -l "$f" >/dev/null; then
    echo "ERRO de sintaxe em $f — restaurando os originais."
    cp -a "$BK/routes/admin.php" routes/admin.php; cp -a "$BK/app/Views/layouts/admin.php" app/Views/layouts/admin.php
    exit 1
  fi
done

echo "== 5/6 Instância do WhatsApp na Evolution =="
export EVO_GLOBAL_KEY="$(docker exec evolution-api printenv AUTHENTICATION_API_KEY)"
export INSTANCE
python3 - <<'PY'
import json, os, secrets, sys, urllib.request, urllib.error
BASE = 'http://127.0.0.1:8080'
K = os.environ['EVO_GLOBAL_KEY']; NAME = os.environ['INSTANCE']; ENV = '/var/www/dominius-play/.env'
def call(method, path, body=None, key=K):
    req = urllib.request.Request(BASE + path, method=method, data=json.dumps(body).encode() if body is not None else None,
                                 headers={'apikey': key, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=40) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b'{}')
        except Exception: return e.code, {}
def find():
    c, d = call('GET', '/instance/fetchInstances')
    if c != 200 or not isinstance(d, list):
        sys.exit('ERRO: não consegui listar as instâncias (HTTP %s).' % c)
    for i in d:
        n = i.get('name') or i.get('instanceName') or (i.get('instance') or {}).get('instanceName')
        if n == NAME: return i
    return None
inst = find()
if inst is None:
    c, d = call('POST', '/instance/create', {'instanceName': NAME, 'integration': 'WHATSAPP-BAILEYS', 'qrcode': False, 'token': secrets.token_hex(24)})
    if c not in (200, 201):
        sys.exit('ERRO ao criar a instância (HTTP %s): %s' % (c, json.dumps(d)[:300]))
    print('Instância "%s" criada.' % NAME)
    inst = find()
else:
    print('Instância "%s" já existia.' % NAME)
tok = (inst or {}).get('token') or (inst or {}).get('apikey') or ((inst or {}).get('instance') or {}).get('token')
if not tok:
    sys.exit('ERRO: não achei a chave da instância. Campos: ' + ', '.join(sorted((inst or {}).keys())))
c, d = call('GET', '/instance/connectionState/' + NAME, key=tok)
if c != 200:
    sys.exit('ERRO: a chave da instância foi recusada (HTTP %s).' % c)
state = (d.get('instance') or {}).get('state') or d.get('state')
lines = open(ENV, encoding='utf-8').read().split('\n')
def setv(k, v):
    for n, l in enumerate(lines):
        if l.startswith(k + '='):
            lines[n] = k + '=' + v; return
    lines.append(k + '=' + v)
setv('EVOLUTION_INSTANCE', NAME); setv('EVOLUTION_API_KEY', tok)
open(ENV, 'w', encoding='utf-8').write('\n'.join(lines))
print('Configuração gravada no .env (a chave não é exibida). Estado atual: %s' % state)
PY
unset EVO_GLOBAL_KEY

echo "== 6/6 Recarregando o PHP =="
systemctl reload php8.1-fpm
rm -rf "$TMP"
sudo -u $OWNER php bin/nurture.php status
echo
echo "PRONTO. Abra no navegador (logado no painel):  https://dominiusplay.top/admin/configuracoes/whatsapp"
echo "Se algo falhar, os originais estão em $BK"
