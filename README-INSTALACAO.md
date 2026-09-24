# Instalação do Fiber Link Notificações

Este guia é para quem **tem pouca experiência com Linux**. Siga os passos na ordem.
Tudo o que é técnico (Nginx, banco de dados, senhas, SSL, firewall, serviços) é feito
**automaticamente** pelo instalador.

Ao final você terá:

- Painel: **https://painel.minhafiberlink.com.br**
- API: **https://api.minhafiberlink.com.br/api/v1/health**

---

## PASSO 1 — Criar a VPS

Contrate uma VPS com:

| Item | Mínimo | Recomendado |
|---|---|---|
| Sistema | **Ubuntu Server 22.04 LTS** (64 bits) | Ubuntu Server 22.04 LTS |
| Memória | 1 GB | 2 GB |
| Disco | 10 GB | 25 GB ou mais |
| Acesso | usuário `root` ou com `sudo` | — |

Anote o **IP público** da VPS (o provedor mostra no painel dele, algo como `203.0.113.10`).

> Importante: se o provedor tiver um "firewall" próprio no painel dele (ex.: *Security Group*),
> libere as portas **22, 80 e 443**.

---

## PASSO 2 — Apontar o DNS

No painel onde o domínio `minhafiberlink.com.br` é administrado (Registro.br, Cloudflare,
Hostinger etc.), crie **dois registros do tipo A**:

| Tipo | Nome | Valor |
|---|---|---|
| A | `painel` | IP DA VPS |
| A | `api` | IP DA VPS |

- **Não crie** registros AAAA (IPv6) para esses nomes, a menos que saiba o IPv6 da VPS.
- Se usar Cloudflare, deixe a nuvem **cinza** (DNS only) pelo menos até o SSL ser emitido.
- A propagação costuma levar de 5 minutos a algumas horas.

> O DNS ainda não propagou? **Pode instalar assim mesmo.** O sistema funciona em HTTP e
> depois você ativa o SSL com um único comando (veja o final do Passo 4).

---

## PASSO 3 — Acessar a VPS por SSH

**Windows:** abra o *PowerShell* (ou o PuTTY) e digite:

```
ssh root@IP_DA_VPS
```

**Mac/Linux:** abra o *Terminal* e digite o mesmo comando.

Na primeira vez ele pergunta se confia no servidor: digite `yes` e Enter. Depois digite a
senha do root (a senha não aparece enquanto você digita — é normal).

Se o seu usuário não for o root, use `sudo` antes dos comandos dos próximos passos.

---

## PASSO 4 — Executar a instalação

Copie e cole os comandos abaixo, um de cada vez (troque `URL_DO_REPOSITORIO` pelo endereço
do repositório Git do projeto):

```bash
apt update && apt install -y git
git clone URL_DO_REPOSITORIO
cd fiberlink-notificacoes
chmod +x deploy/*.sh
sudo ./deploy/install.sh
```

O instalador mostra a verificação do servidor:

```
Verificando servidor...
Ubuntu 22.04.......... OK
Internet.............. OK
Memória............... OK (1987 MB)
Disco................. OK (22340 MB livres)
Porta 80.............. OK
Porta 443............. OK
DNS: verificando...
DNS PAINEL............ OK
DNS API............... OK

Domínio Painel:
painel.minhafiberlink.com.br
Domínio API:
api.minhafiberlink.com.br

  Continuar instalação? [S/n]
```

Digite `S` e Enter. Ele pergunta **somente**:

1. **E-mail para SSL** — recebe os avisos do Let's Encrypt (certificado gratuito).
2. **Criar administrador** — nome, e-mail e senha (mínimo 10 caracteres) para entrar no painel.

Pronto. O resto é automático e leva de 3 a 10 minutos: pacotes, banco de dados com senha
aleatória, Redis, PHP, Nginx, SSL, firewall, Fail2Ban, worker, scheduler, backup diário e
testes. No final aparece o resultado do **health check**:

```
PAINEL................ OK
API................... OK
NGINX................. OK
SSL PAINEL............ OK
SSL API............... OK
BANCO................. OK
REDIS................. OK
WORKER................ OK
SCHEDULER............. OK
SGP................... NÃO CONFIGURADO
WHATSAPP.............. NÃO CONFIGURADO
DISCO................. OK
========================================
```

`SGP` e `WHATSAPP` aparecem como **NÃO CONFIGURADO** logo após a instalação. Isso é normal:
eles são configurados pelo painel nos passos 6 e 7.

### Se o DNS ainda não estava pronto

O instalador avisa, por exemplo:

```
ATENÇÃO:
painel.minhafiberlink.com.br ainda não aponta para esta VPS.
IP esperado:
203.0.113.10
```

A instalação termina normalmente (em HTTP). Quando o DNS estiver correto, rode:

```bash
cd ~/fiberlink-notificacoes
sudo ./deploy/enable-ssl.sh
```

### Se algo der errado

O instalador para e mostra:

```
==================== FALHA ====================
ETAPA: Configurando Nginx (painel e API)
ERRO:  comando '...' terminou com código 1 (linha 123)
LOG:   /var/log/fiberlink/install-20260923-160000.log
===============================================
```

Corrija o problema indicado e **rode o instalador de novo**: ele pode ser executado várias
vezes com segurança (não apaga dados e mantém as senhas já geradas). Se precisar de ajuda,
envie o arquivo de LOG indicado.

---

## PASSO 5 — Acessar o painel

Abra no navegador: **https://painel.minhafiberlink.com.br**

Entre com o e-mail e a senha de administrador criados no Passo 4.

No topo aparece a faixa **⚠ SISTEMA EM MODO HOMOLOGAÇÃO**: enquanto ela estiver lá,
**nenhuma mensagem é enviada para clientes reais**.

---

## PASSO 6 — Configurar a integração com o SGP

Menu **Integrações → SGP**:

1. **URL do SGP** — ex.: `https://fiberlink.sgp.tsmx.com.br`
2. **App** e **Token** — criados no SGP em *Sistema → API/Integrações* (token da API URA).
3. Clique em **Salvar SGP** e depois em **Testar conexão** informando o CPF de um cliente
   real. O painel mostra como os dados (contratos, telefones, faturas) foram interpretados —
   confira se vencimento, valor e situação estão corretos.
4. Cadastre os clientes em **Clientes → + Adicionar cliente** (por CPF/CNPJ) ou importe um
   CSV com `documento;nome;telefone`. A sincronização com o SGP roda sozinha a cada 30 minutos.
5. (Opcional) Configure no SGP o webhook com o endereço mostrado em
   **Integrações → Endereços de webhook** para receber pagamentos em tempo real.

> O sistema **nunca** chama o endpoint `fatura2via` do SGP (ele abre protocolo de atendimento).

---

## PASSO 7 — Configurar o WhatsApp

O envio usa a **Evolution API** (conexão por QR Code). Você precisa de uma instância da
Evolution API já instalada e conectada ao número da empresa.

Menu **Integrações → WhatsApp**:

1. **URL da Evolution API**, **nome da instância** e **API key**.
2. **Salvar WhatsApp** → **Testar conexão** (deve aparecer *conectado*).
3. **Configurar webhook** — aponta a Evolution API para este sistema (status de entrega/leitura).
4. **Validar número**.

> A Evolution API não é oficial da Meta. Para reduzir o risco de bloqueio do número, mantenha
> o intervalo entre mensagens (SISTEMA → CONFIGURAÇÕES) em 10 segundos ou mais.

---

## PASSO 8 — Executar os testes

Menu **Homologação**:

1. Informe o **número de WhatsApp de teste** (o seu celular, por exemplo) e salve.
2. Clique em cada botão **Enviar teste** (vencimento, pagamento, suspensão, cancelamento).
   As mensagens chegam no número de teste em até 1 minuto.
3. Enquanto estiver em homologação, as mensagens automáticas dos clientes também vão
   **somente** para o número de teste, com o destino real mascarado, e com limite diário.

---

## PASSO 9 — Liberar produção

Ainda em **Homologação**, o checklist precisa estar todo com ✔:

SGP conectado · WhatsApp conectado · Número WhatsApp validado · Templates configurados ·
Timezone correto · Scheduler funcionando · Worker funcionando · Webhook configurado ·
SSL válido · Backup configurado · Teste de vencimento · Teste de pagamento ·
Teste de suspensão · Teste de cancelamento

Quando estiver completo, o botão **LIBERAR PRODUÇÃO** fica disponível. Digite `LIBERAR`,
sua senha e confirme. A partir daí as mensagens vão para os clientes reais.
Mensagens pendentes criadas durante a homologação são canceladas (não são disparadas em massa).

Você pode **voltar para homologação** a qualquer momento pelo mesmo menu.

---

## Referência rápida

| Tarefa | Comando (dentro da pasta `fiberlink-notificacoes`) |
|---|---|
| Verificar tudo | `sudo ./deploy/health-check.sh` |
| Ativar SSL depois | `sudo ./deploy/enable-ssl.sh` |
| Atualizar o sistema | `sudo ./deploy/update.sh` — veja `README-ATUALIZACAO.md` |
| Voltar versão | `sudo ./deploy/rollback.sh` |
| Backup manual | `sudo ./deploy/backup.sh` — veja `README-BACKUP.md` |
| Restaurar backup | `sudo ./deploy/restore.sh` |
| Desinstalar | `sudo ./deploy/uninstall.sh` |
| Ver logs do worker | `journalctl -u fiberlink-worker -f` |
| Esqueci a senha do painel | `printf 'NovaSenha123' \| sudo -u fiberlink php /var/www/fiberlink-notificacoes/current/bin/console admin:create --name="Admin" --email=seu@email --update` |

### Onde fica cada coisa

| Caminho | Conteúdo |
|---|---|
| `/var/www/fiberlink-notificacoes/current` | versão em uso (link para `releases/...`) |
| `/var/www/fiberlink-notificacoes/releases/` | versões instaladas (para rollback) |
| `/var/www/fiberlink-notificacoes/shared/.env` | configuração e segredos (permissão 640) |
| `/var/www/fiberlink-notificacoes/shared/storage/` | logs, sessões, status |
| `/var/www/fiberlink-notificacoes/logs` | atalho para os logs da aplicação |
| `/var/log/fiberlink/` | logs de instalação, deploy, backup |
| `/var/backups/fiberlink/` | backups |

### Serviços criados

| Serviço | Função |
|---|---|
| `fiberlink-worker` | envia a fila de WhatsApp (reinicia sozinho se cair e após reboot) |
| `fiberlink-scheduler.timer` | a cada minuto: sincronização SGP, vencimentos, reconciliação, limpeza |
| `fiberlink-agent.timer` | executa atualizações/backups pedidos pelo painel (de forma segura) |
| `fiberlink-backup.timer` | backup diário às 03:15 |
| `certbot.timer` | renovação automática do SSL |

### Segurança aplicada automaticamente

- Aplicação roda como o usuário de sistema `fiberlink` (sem login, sem root).
- Código da aplicação pertence ao root e não pode ser alterado pelo usuário da aplicação.
- Banco e Redis escutam **somente** em `127.0.0.1`, com usuário/senha exclusivos e aleatórios.
- Firewall UFW: somente SSH, 80 e 443 (a porta do SSH é detectada antes de ativar).
- Fail2Ban para SSH e para tentativas de login no painel.
- Tokens do SGP/Evolution guardados **criptografados** no banco (AES-256-GCM com a APP_KEY).
- Logs mascaram senhas, tokens, `Authorization`, `APP_KEY`, `DB_PASSWORD` e códigos PIX.
