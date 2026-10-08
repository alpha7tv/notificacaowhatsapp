#!/usr/bin/env bash
# Diagnóstico SOMENTE LEITURA: por que a licença não foi liberada automaticamente no WHMCS.
# Uso: bash diagnostico-licenca.sh email-do-cliente@exemplo.com
set -u
EMAIL="${1:-}"
[ -n "$EMAIL" ] || { echo "Uso: bash $0 email-do-cliente"; exit 1; }
[[ "$EMAIL" =~ ^[A-Za-z0-9._%+@-]+$ ]] || { echo "E-mail inválido (use só letras, números e . _ % + - @)."; exit 1; }

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
echo; echo "Fim. Copie esta saída (já sem senhas) e me envie."
