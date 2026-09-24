# Fiber Link Notificações

Sistema de notificações de cobrança via WhatsApp integrado ao SGP (TSMX).

- **Painel:** https://painel.minhafiberlink.com.br
- **API:** https://api.minhafiberlink.com.br/api/v1/health
- **Webhooks:** `/api/v1/webhooks/sgp` e `/api/v1/webhooks/whatsapp`

## Documentação

| Arquivo | Assunto |
|---|---|
| [README-INSTALACAO.md](README-INSTALACAO.md) | instalação em VPS Ubuntu 22.04 (passo a passo) |
| [README-ATUALIZACAO.md](README-ATUALIZACAO.md) | deploy, atualização pelo painel, rollback |
| [README-BACKUP.md](README-BACKUP.md) | backup, retenção, restauração, migração de servidor |

Instalação em uma VPS nova:

```bash
git clone URL_DO_REPOSITORIO
cd fiberlink-notificacoes
chmod +x deploy/*.sh
sudo ./deploy/install.sh
```

## Arquitetura

| Camada | Tecnologia |
|---|---|
| Painel + API | PHP 8.1 (sem framework), PHP-FPM, Nginx |
| Banco | MariaDB 10.6 (localhost) |
| Locks distribuídos | Redis (localhost, com senha) |
| Fila de WhatsApp | tabela `messages` + serviço `fiberlink-worker` (systemd, Restart=always) |
| Rotinas | `fiberlink-scheduler.timer` (1/min) com lock por rotina |
| WhatsApp | Evolution API (v1 ou v2) |
| SGP | API URA (`consultacliente`, `titulos`) + webhook |

Fluxo: o scheduler sincroniza clientes/contratos/faturas com o SGP e detecta **transições**
(fatura paga, contrato suspenso/cancelado/reativado). As regras de envio geram mensagens na fila
com **chave de idempotência única** (a mesma notificação nunca é criada duas vezes). O worker
revalida cada mensagem antes de enviar (fatura já paga? contrato mudou?), respeita a janela de
horário, o intervalo anti-bloqueio e o **modo homologação** (todos os envios vão para o número de
teste até a liberação de produção pelo checklist).

## Estrutura

```
bin/console              comandos administrativos (migrate, worker:run, scheduler:run, selftest...)
bootstrap.php            carregamento da aplicação
database/migrations/     SQL versionado (aditivo)
deploy/                  install, deploy, update, rollback, backup, restore, uninstall,
                         health-check, enable-ssl, agent (+ lib.sh com funções comuns)
public/index.php         painel
public/api.php           API e webhooks
src/Core/                infraestrutura (banco, sessão, CSRF, JWT, criptografia, logs, locks)
src/Integrations/        clientes SGP e Evolution API
src/Services/            sincronização, planejamento, worker, scheduler, webhooks, health
src/Http/                controllers do painel e da API
views/                   telas do painel
tests/router.php         roteador para testes locais com o servidor embutido do PHP
```

## Desenvolvimento local

```bash
cp .env.example .env         # preencha DB_* e gere APP_KEY/JWT_SECRET/WEBHOOK_SECRET/INTERNAL_API_SECRET
php bin/console migrate
printf 'SenhaForte123' | php bin/console admin:create --name="Admin" --email=admin@local
php -S 127.0.0.1:8060 -t public tests/router.php
php bin/console selftest
```
