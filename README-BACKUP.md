# Backup e restauração

## O que é salvo

Cada backup é um único arquivo em `/var/backups/fiberlink/`:

```
fiberlink-2026-09-23-160000.tar.gz          (backup diário)
fiberlink-2026-09-23-160000.tar.gz.sha256   (checksum para validação)
fiberlink-2026-09-23-153000-pre-deploy.tar.gz (backup de segurança)
```

Conteúdo:

| Item | Arquivo no backup |
|---|---|
| Banco completo (clientes, faturas, mensagens, templates, regras, configurações, logs) | `database.sql.gz` |
| `.env` (chaves e senhas — necessário para descriptografar os tokens do SGP/WhatsApp) | `env/.env` |
| Configurações do Nginx, systemd, PHP-FPM, Redis, MariaDB, Fail2Ban, logrotate | `config/` |
| Status e uploads da aplicação | `storage/` |
| Versão, revisão e migrations aplicadas | `manifest.json` |

Não entram (são reconstruídos automaticamente): código das versões, `vendor/`, pacotes do sistema.

> Os backups contêm o `.env` com as senhas: ficam com permissão 600 (somente root).
> Guarde cópias **fora da VPS** (veja abaixo).

## Quando acontece

| Tipo | Quando | Nome |
|---|---|---|
| Diário | automático às 03:15 (`fiberlink-backup.timer`) | `fiberlink-DATA.tar.gz` |
| Antes de atualizar | automático no deploy | `...-pre-deploy.tar.gz` |
| Antes de restaurar | automático no restore | `...-pre-restore.tar.gz` |
| Manual | `sudo ./deploy/backup.sh` ou botão no painel | `fiberlink-DATA.tar.gz` / `...-manual.tar.gz` |

## Retenção (limpeza automática)

Configurável em `/var/www/fiberlink-notificacoes/shared/.env`:

```
BACKUP_KEEP_DAILY=7      # o mais recente de cada um dos últimos 7 dias
BACKUP_KEEP_WEEKLY=4     # o mais recente de cada uma das últimas 4 semanas
BACKUP_KEEP_MONTHLY=3    # o mais recente de cada um dos últimos 3 meses
BACKUP_KEEP_SAFETY=5     # backups com rótulo (pre-deploy, pre-restore, manual...)
```

A limpeza só apaga arquivos com o padrão exato `fiberlink-AAAA-MM-DD-HHMMSS*.tar.gz` dentro da
pasta de backup e nunca apaga o backup que acabou de ser criado.

## Fazer um backup agora

```bash
cd ~/fiberlink-notificacoes
sudo ./deploy/backup.sh
```

Ou no painel: **SISTEMA → ATUALIZAÇÕES → Fazer backup agora**.

## Restaurar

```bash
sudo ./deploy/restore.sh
```

1. Lista os backups disponíveis — escolha o número.
2. Valida o checksum e a integridade do arquivo.
3. Mostra o aviso **"Esta operação irá substituir dados atuais."**
4. Você digita `RESTAURAR` para confirmar.
5. Cria um backup de segurança do estado atual (`...-pre-restore.tar.gz`).
6. Para worker/scheduler, ativa o modo manutenção, restaura o banco (e, se você confirmar, o `.env`).
7. Aplica as migrations da versão instalada, reinicia os serviços e roda o health check.

Opções:

```bash
sudo ./deploy/restore.sh --file /var/backups/fiberlink/fiberlink-2026-09-23-160000.tar.gz
sudo ./deploy/restore.sh --db-only     # somente o banco, mantém o .env atual
```

Se algo falhar no meio, o script informa como voltar ao estado anterior usando o backup de
segurança criado no passo 5.

## Levar o sistema para uma nova VPS

1. Na VPS nova: siga o `README-INSTALACAO.md` normalmente.
2. Copie o backup da VPS antiga para a nova:
   ```bash
   scp root@IP_ANTIGO:/var/backups/fiberlink/fiberlink-AAAA-MM-DD-HHMMSS.tar.gz* /var/backups/fiberlink/
   ```
3. Na VPS nova: `sudo ./deploy/restore.sh` e responda **S** para restaurar o `.env`
   (ele contém a `APP_KEY` que descriptografa os tokens do SGP e do WhatsApp).

## Cópia fora do servidor (recomendado)

Um backup que fica só na VPS não protege contra a perda da VPS. Copie periodicamente para outro
lugar, por exemplo do seu computador:

```bash
scp root@IP_DA_VPS:/var/backups/fiberlink/fiberlink-*.tar.gz* ./backups-fiberlink/
```

ou use o recurso de snapshot/backup do seu provedor de VPS.
