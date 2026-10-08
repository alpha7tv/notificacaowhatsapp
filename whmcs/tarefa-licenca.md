# Tarefa: licença Mídia Rádio Studio não liberada automaticamente

Contexto: esta VPS (Contabo, vmi3607460) roda o WHMCS em /var/www/whmcs (área do cliente: cliente-area.midiaserver.com.br).

Problema: o cliente pedrohslz.2018@gmail.com comprou "Mídia Rádio Studio — Licença 1 PC" (produto id 20, módulo midiaradiostudio, autosetup=payment). O serviço id 15 ficou "Pending" e a licença não foi criada automaticamente. O Module Log está vazio (só grava com Module Debug Mode ligado). O cron do WHMCS está rodando normalmente.

Tarefa:
1. SOMENTE LEITURA primeiro: ver a fatura e o pedido desse cliente (tabelas tblorders, tblinvoices, tblhosting; tblorders não tem coluna paymentstatus, use transstatus), ler o módulo em /var/www/whmcs/modules/servers/midiaradiostudio (função midiaradiostudio_CreateAccount e o que ela chama) e os hooks relacionados em /var/www/whmcs/includes/hooks (inclusive radioweb.php). Me explique a causa.
2. Antes de alterar qualquer arquivo ou o banco, faça backup (cópia com data em /root/backup-whmcs-AAAA-MM-DD/ e mysqldump do banco) e me mostre exatamente o que vai mudar.
3. Corrija a causa para as próximas compras saírem automáticas e libere a licença do serviço 15.

Regras: nunca mostre senhas, chaves ou o conteúdo de configuration.php; para acessar o banco leia as credenciais por variável (sem imprimir); não apague nada; não reinicie serviços sem me avisar; confirme comigo antes de qualquer alteração.
