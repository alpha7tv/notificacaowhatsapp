# Atualização do Fiber Link Notificações

## Como as versões funcionam

- Cada versão fica em uma pasta própria: `/var/www/fiberlink-notificacoes/releases/AAAAMMDDHHMMSS-vX.Y.Z`.
- O link `/var/www/fiberlink-notificacoes/current` aponta para a versão em uso. A troca é
  **atômica** (o painel não fica "meio atualizado").
- O número da versão vem do arquivo `VERSION` do repositório.
- O sistema implanta a **última tag `v*`** do repositório (ex.: `v1.2.4`). Se o repositório não
  usar tags, implanta o último commit da branch padrão (`main`/`master`).

Para publicar uma nova versão (no seu computador de desenvolvimento):

```bash
# altere o arquivo VERSION para 1.2.4, faça commit e:
git tag v1.2.4
git push && git push --tags
```

## Opção 1 — Pelo painel

**SISTEMA → ATUALIZAÇÕES**

1. **VERIFICAR ATUALIZAÇÃO** — em até 1 minuto aparece a *Versão disponível*.
2. **ATUALIZAR SISTEMA** → aparece o aviso *"Será realizado um backup antes da atualização."* →
   confirme sua senha → **ATUALIZAR** (ou **CANCELAR**).
3. Acompanhe o log da tarefa na mesma tela (atualiza sozinha).

Segurança: o painel **não executa comandos**. Ele só registra o pedido; um agente do servidor
(`fiberlink-agent.timer`, root) executa exclusivamente os scripts fixos do `/deploy`, sem receber
nenhum parâmetro vindo da web. Tudo fica registrado em **SISTEMA → AUDITORIA** e **LOGS → Deploy**.

## Opção 2 — Pelo terminal

```bash
cd ~/fiberlink-notificacoes
sudo ./deploy/update.sh            # mostra instalada x disponível e pergunta se quer atualizar
sudo ./deploy/deploy.sh            # atualiza direto para a última versão
sudo ./deploy/deploy.sh --ref v1.2.4   # versão específica
```

Sem Git (código enviado em ZIP):

```bash
unzip fiberlink-notificacoes-1.2.4.zip -d /root/nova-versao
sudo ./deploy/deploy.sh --source /root/nova-versao/fiberlink-notificacoes
```

## O que o deploy faz (e o rollback automático)

1. Backup completo (`fiberlink-...-pre-deploy.tar.gz`)
2. Registra a versão atual
3. Baixa a nova versão
4. Instala as dependências (composer)
5. Frontend: não há etapa de build (o painel é renderizado no servidor, sem Node/npm)
6. Migrations — se houver, ativa o **modo manutenção** e pausa o worker durante a execução
7. Troca a versão e limpa caches (PHP-FPM/OPcache)
8. Reinicia o worker
9. Reinicia o scheduler
10. Valida o Nginx (`nginx -t`)
11. Health check completo + testes funcionais
12. Confirma a nova versão
13. Remove versões antigas (mantém as 5 últimas — `KEEP_RELEASES` no `.env`)

**Se qualquer etapa crítica falhar, o código volta sozinho para a versão anterior.**
O banco **não** é revertido automaticamente. Se a falha envolver migrations, o próprio deploy
mostra o comando para restaurar o backup pré-deploy.

## Rollback manual

```bash
sudo ./deploy/rollback.sh
```

```
Versão atual:
  v1.2.4  (20260923160000-v1.2.4)

Anteriores:
  1) v1.2.3  (20260915100000-v1.2.3)
  2) v1.2.2  (20260901090000-v1.2.2)
```

Escolha o número. Se a versão atual criou tabelas/colunas novas, o script avisa e oferece:

1. **Voltar só o código** (recomendado — as migrations do projeto são aditivas, a versão antiga
   continua funcionando com o banco atual);
2. **Voltar o código e restaurar um backup do banco** — destrutivo, pede confirmação digitada;
3. Cancelar.

Nada no banco é desfeito sem a sua confirmação explícita.

## Atualizações do Ubuntu

```bash
sudo ./deploy/update.sh --system
```

Se o Ubuntu pedir reinicialização, rode `sudo reboot`: todos os serviços voltam sozinhos.

## Regra para desenvolvedores: migrations aditivas

Novas migrations (`database/migrations/NNNN_descricao.sql`) devem ser **compatíveis com a versão
anterior do código**: criar tabelas/colunas com valor padrão, nunca renomear ou apagar no mesmo
deploy. Remoções só em uma versão posterior, quando o código antigo já não estiver em uso.
Isso garante que o rollback de código seja sempre seguro.
