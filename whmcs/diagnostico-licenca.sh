#!/usr/bin/env bash
# Diagnóstico SOMENTE LEITURA: por que a licença não foi liberada automaticamente no WHMCS.
# Uso: bash diagnostico-licenca.sh email-do-cliente@exemplo.com
set -u
EMAIL="${1:-}"
EMAIL="${EMAIL:-ultimos}"
[[ "$EMAIL" == "ultimos" || "$EMAIL" =~ ^[A-Za-z0-9._%+@-]+$ ]] || { echo "E-mail inválido (use só letras, números e . _ % + - @)."; exit 1; }

echo "== 1. Localizando o WHMCS =="
CFG=""
for d in /var/www /home /usr/share/nginx /opt /srv /var/www/html; do
  [ -d "$d" ] || continue
  while IFS= read -r f; do
    if grep -q '\$db_name' "$f" 2>/dev/null; then CFG="$f"; break 2; fi
  done < <(find "$d" -maxdepth 5 -name configuration.php 2>/dev/null)
done
[ -n "$CFG" ] || { echo "Não achei o configuration.php do WHMCS neste servidor. Rode este script na VPS onde o WHMCS está instalado."; exit 1; }
ROOT=$(dirname "$CFG"); echo "WHMCS em: $ROOT"
command -v php >/dev/null || { echo "PHP não encontrado."; exit 1; }
command -v mysql >/dev/null || { echo "Cliente mysql não encontrado."; exit 1; }

# credenciais lidas pelo PHP e usadas só por variável de ambiente (não são exibidas)
eval "$(php -r 'include $argv[1]; echo "DBH=".escapeshellarg($db_host)."; DBN=".escapeshellarg($db_name)."; DBU=".escapeshellarg($db_username)."; export MYSQL_PWD=".escapeshellarg($db_password).";";' "$CFG")"
q() { mysql -h "$DBH" -u "$DBU" "$DBN" -t -e "$1" 2>&1 | sed -E 's/(pass(word)?|secret|token|api[_-]?key|licensekey|license_key)("?[=:]"? ?)[^,&" ]+/\1\3***/Ig'; }

echo; echo "== 2. Módulos instalados relacionados (nome contém midia/media/studio/licen) =="
ls "$ROOT/modules/servers" "$ROOT/modules/addons" "$ROOT/modules/provisioning" 2>/dev/null | grep -i -E 'midia|media|studio|licen' || echo "(nenhum com esses nomes)"
echo "Hooks relacionados:"; grep -ril -E 'midia|studio' "$ROOT/includes/hooks" 2>/dev/null | head || true

if [ "$EMAIL" = "ultimos" ]; then
  echo; echo "== Últimos 10 pedidos (com o e-mail do cliente) =="
  q "SELECT o.id AS pedido, o.date, o.status, o.transstatus AS pagto, o.invoiceid AS fatura, c.email FROM tblorders o JOIN tblclients c ON c.id=o.userid ORDER BY o.id DESC LIMIT 10"
  echo; echo "== Últimos 10 serviços =="
  q "SELECT h.id, h.regdate, p.name AS produto, p.servertype AS modulo, p.autosetup, h.domainstatus AS status, c.email FROM tblhosting h JOIN tblproducts p ON p.id=h.packageid JOIN tblclients c ON c.id=h.userid ORDER BY h.id DESC LIMIT 10"
  echo; echo "== Produtos com módulo Mídia Studio/licença =="
  q "SELECT id, name, servertype AS modulo, autosetup FROM tblproducts WHERE servertype LIKE '%midia%' OR servertype LIKE '%licens%' OR name LIKE '%Studio%' OR name LIKE '%Licen%'"
  echo; echo "== Log do módulo (últimas 12 chamadas) =="
  q "SELECT date, module, action, LEFT(request,200) AS request, LEFT(response,300) AS response FROM tblmodulelog ORDER BY id DESC LIMIT 12"
  echo; echo "== Cron do WHMCS =="
  q "SELECT setting, value FROM tblconfiguration WHERE setting LIKE '%Cron%' LIMIT 10"
  date -u '+Agora (UTC): %F %T'
  echo; echo "Fim. Copie esta saída e me envie."
  exit 0
fi

echo; echo "== 3. Cliente =="
q "SELECT id, firstname, lastname, email, status FROM tblclients WHERE email='$EMAIL'"
UID_=$(mysql -h "$DBH" -u "$DBU" "$DBN" -N -e "SELECT id FROM tblclients WHERE email='$EMAIL' LIMIT 1" 2>/dev/null)
[ -n "$UID_" ] || { echo "Cliente não encontrado com esse e-mail."; exit 0; }

echo; echo "== 4. Últimos pedidos do cliente =="
q "SELECT id, date, status, paymentstatus, paymentmethod, invoiceid, amount FROM tblorders WHERE userid=$UID_ ORDER BY id DESC LIMIT 5"

echo; echo "== 5. Últimas faturas =="
q "SELECT id, date, datepaid, status, paymentmethod, total FROM tblinvoices WHERE userid=$UID_ ORDER BY id DESC LIMIT 5"

echo; echo "== 6. Serviços (produtos contratados) =="
q "SELECT h.id, p.name AS produto, p.servertype AS modulo, p.autosetup, h.domainstatus AS status, h.regdate, h.nextduedate, h.username FROM tblhosting h JOIN tblproducts p ON p.id=h.packageid WHERE h.userid=$UID_ ORDER BY h.id DESC LIMIT 5"

echo; echo "== 7. Log do módulo (últimas 12 chamadas) =="
q "SELECT date, module, action, LEFT(request,200) AS request, LEFT(response,300) AS response FROM tblmodulelog ORDER BY id DESC LIMIT 12"

echo; echo "== 8. Log de atividade deste cliente (últimas 12) =="
q "SELECT date, LEFT(description,220) AS descricao FROM tblactivitylog WHERE userid=$UID_ ORDER BY id DESC LIMIT 12"

echo; echo "== 9. Cron do WHMCS =="
q "SELECT setting, value FROM tblconfiguration WHERE setting IN ('LastCronInvocationTime','CronLastRun','CronRunning') OR setting LIKE '%Cron%' LIMIT 10"
date -u '+Agora (UTC): %F %T'
crontab -l 2>/dev/null | grep -i whmcs || echo "(sem cron whmcs no crontab do usuário atual; pode estar em /etc/cron.d)"; grep -ril whmcs /etc/cron.d 2>/dev/null | head -3
echo; echo "== 10. Módulo midiaradiostudio (arquivos e funções) =="
M="$ROOT/modules/servers/midiaradiostudio"
ls -la "$M" 2>&1 | head -20
grep -n "function " "$M"/*.php 2>/dev/null | cut -c1-160 | head -40
echo; echo "== 11. Pedidos/serviços/produtos: detalhes do serviço pendente =="
q "SELECT h.id, h.orderid, h.domainstatus, h.paymentmethod, h.packageid, h.server, h.username, LEFT(h.notes,200) AS notas FROM tblhosting h WHERE h.userid=$UID_ ORDER BY h.id DESC LIMIT 3"
q "SELECT id, ordernum, status, transstatus, invoiceid, fraudmodule, LEFT(fraudoutput,120) AS fraude FROM tblorders WHERE userid=$UID_ ORDER BY id DESC LIMIT 3"
echo; echo "Fim. Copie esta saída (já sem senhas) e me envie."
