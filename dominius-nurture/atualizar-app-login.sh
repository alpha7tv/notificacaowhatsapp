#!/usr/bin/env bash
# Dominius Play App — habilita entrada por usuário e senha (servidor/DNS cadastrado uma vez no painel). Execute como root.
set -euo pipefail
APP=/var/www/dominius-play
OWNER=dominius
BASE=https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/ac496c70edcd929d9642a8b7d662f23081a7b1af/dominius-nurture
[ "$(id -u)" -eq 0 ] || { echo "Execute como root."; exit 1; }
cd "$APP"
STAMP=$(date +%F-%H%M%S)
TMP=$(mktemp -d); chmod 755 "$TMP"
declare -A SUM=( ["app/Services/AppService.php"]="0a3d93c154de27caa1df06a986ba2694d8a7766783bc2c8434254aa9d5b7c0a4" ["app/Controllers/AppApiController.php"]="daa9d961ae9dccb95b518eaa2821dfc59ccafe7a48b629e72fda5884bc869832" )
echo "== 1/4 Baixando e conferindo =="
for f in "${!SUM[@]}"; do
  mkdir -p "$TMP/$(dirname "$f")"
  curl -fsSL "$BASE/$f" -o "$TMP/$f"
  [ "$(sha256sum "$TMP/$f" | cut -d' ' -f1)" = "${SUM[$f]}" ] || { echo "ERRO: $f veio diferente do esperado. Nada foi alterado."; exit 1; }
  chmod 644 "$TMP/$f"
  sudo -u $OWNER php -l "$TMP/$f" >/dev/null || { echo "ERRO de sintaxe em $f. Nada foi alterado."; exit 1; }
done
echo "== 2/4 Backup =="
BK=/root/backup-app-login-$STAMP; mkdir -p "$BK"; chmod 700 "$BK"
for f in "${!SUM[@]}" routes/web.php; do cp -a --parents "$f" "$BK"/; done
echo "Backup em $BK"
echo "== 3/4 Instalando =="
for f in "${!SUM[@]}"; do install -D -o $OWNER -g $OWNER -m 644 "$TMP/$f" "$APP/$f"; done
python3 - <<'PY'
import sys
p = '/var/www/dominius-play/routes/web.php'
s = open(p, encoding='utf-8').read()
if "'/api/app/entrar'" in s:
    print('[ok, já aplicado] routes/web.php'); sys.exit(0)
find = "$router->post('/api/app/ativar', [\\App\\Controllers\\AppApiController::class, 'activate']);"
if s.count(find) != 1:
    sys.exit('ERRO: não achei o ponto de encaixe em routes/web.php')
new = find + "\n$router->post('/api/app/entrar', [\\App\\Controllers\\AppApiController::class, 'login']);"
open(p, 'w', encoding='utf-8').write(s.replace(find, new, 1))
print('[alterado] routes/web.php')
PY
chown : routes/web.php 2>/dev/null || true
sudo -u $OWNER php -l routes/web.php >/dev/null || { echo "ERRO: restaurando routes/web.php"; cp -a "$BK/routes/web.php" routes/web.php; exit 1; }
echo "== 4/4 Recarregando =="
systemctl reload php8.1-fpm
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' https://dominiusplay.top/api/app/entrar || true)
echo "Teste da rota /api/app/entrar (esperado 422): HTTP $code"
rm -rf "$TMP"
echo "Pronto! Agora cadastre o servidor em App Dominius → Configurações."
