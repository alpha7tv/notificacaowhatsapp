# Rádio Player (app + painel administrativo)

Aplicativo de web rádios **multiplataforma** com **painel administrativo**. O ouvinte pode:

- **ouvir online** direto no navegador, sem instalar nada;
- **instalar no celular** (Android e iPhone) com ícone na tela inicial;
- **instalar no computador** (Windows, Mac, Linux) pelo Chrome/Edge (ou "Adicionar ao Dock" no Safari).

Tudo funciona com o mesmo endereço (é um PWA), sem precisar publicar em loja.

Usa PHP 8.1+ com GD e fileinfo. **Não precisa de banco de dados**: os dados ficam em `data/`, e as imagens em `uploads/`.

## Painel (`/admin/`)

| Aba | O que faz |
|-----|-----------|
| **Início** | Rádios e anúncios no ar, cliques/exibições e link do app para divulgar |
| **Rádios** | Nome, **URL do streaming**, estilo, logo, slogan, cidade, metadados; ordem, no ar/fora do ar, botão "Testar áudio" |
| **Estilos** | Criar, renomear, ordenar e excluir. Já vem com: Gospel, Sertanejo, Pop Rock, Sertanejo Raiz e Forró |
| **Publicidade** | Anúncios com imagem ou texto, link (site ou WhatsApp), período de exibição, pausar e estatísticas |
| **Aparência** | **Tema visual** (Gospel, Sertanejo, Pop Rock, Sertanejo Raiz, Forró, Clássico), imagem de fundo, nome do app, cor própria (opcional), logo (gera os ícones do app), texto "Sobre", WhatsApp/Instagram/Facebook/YouTube/site |
| **Conta** | Trocar usuário e senha |

No **primeiro acesso** ao `/admin/` o painel pede para criar o usuário e a senha, por isso **faça isso logo
depois de publicar**.

### Espaços de publicidade

| Espaço | Onde aparece | Tamanho sugerido |
|--------|--------------|------------------|
| Banner no topo | Acima da lista de rádios (gira entre os anúncios) | 1200×300 (4:1) |
| Banner no player | Na tela "tocando agora", enquanto a pessoa ouve | 1200×200 (6:1) |
| Popup | Ao abrir o app, uma vez por visita | 1080×1080 ou 1080×1350 |

Cada anúncio conta **exibições** e **cliques** (com taxa de clique), útil para prestar contas ao anunciante.
O tempo de troca dos banners e o atraso do popup são definidos em *Aparência*.

## Recursos do app

- Lista com busca, filtro por estilo e abas Todas / Favoritas / Recentes
- Mini player no rodapé e tela cheia "tocando agora"
- Controles na tela de bloqueio, fone Bluetooth e central de mídia
- Reconexão automática quando o sinal cai
- Música tocando (Icecast `status-json.xsl`, se o servidor liberar CORS)
- Timer para desligar, volume, compartilhar rádio por link (`?r=id`)
- Tema claro/escuro automático
- O botão **Instalar app** usa o instalador do navegador; no iPhone e onde ele não existe, mostra o passo a passo
- Mudanças feitas no painel aparecem no app ao abrir (ou ao voltar para ele depois de 5 min)

## Instalação automática na VPS (recomendado)

Numa VPS **Ubuntu 22.04+ ou Debian 12+**, com o domínio já apontado (registro DNS tipo A) para o IP dela, rode:

```bash
curl -fsSL https://raw.githubusercontent.com/alpha7tv/notificacaowhatsapp/refs/heads/claude/adoring-ramanujan-nqorro/radio-player/deploy/install.sh \
  | sudo bash -s -- --domain radio.seudominio.com.br --email voce@email.com
```

O script instala Nginx + PHP, baixa o app, configura o site com HTTPS (Let's Encrypt) e mostra os
endereços no final. Sem `--domain`, o app fica no IP da VPS (sem HTTPS). **Rodar de novo atualiza o app**
sem apagar rádios, anúncios e imagens.

## Instalação manual

1. Copie a pasta `radio-player/` para o servidor (pode ser a raiz de um domínio, ex.: `radio.seudominio.com.br`).
2. Dê permissão de escrita ao PHP nas pastas de dados:
   ```bash
   chown -R www-data:www-data data uploads
   ```
3. Ative **HTTPS** (obrigatório para instalar o app; ex.: `certbot --nginx`).
4. Acesse `https://seu-endereco/admin/` e crie o administrador.

**Apache:** os arquivos `.htaccess` já bloqueiam `data/` e `lib/` e impedem execução de scripts em `uploads/`.

**Nginx:** adicione ao `server { }` (ajuste o caminho se não estiver na raiz):

```nginx
index index.html index.php;
location ~ ^/(data|lib)/ { deny all; }
location ~ ^/uploads/.*\.(php|phtml|phar)$ { deny all; }
client_max_body_size 5m;
```

Teste local: `php -S localhost:8080` dentro da pasta e abra `http://localhost:8080`.

## Observações

- As URLs de streaming precisam ser **HTTPS** quando o site está em HTTPS (o navegador bloqueia áudio `http` em página segura).
- Navegadores bloqueiam autoplay: a rádio começa quando a pessoa toca no ▶.
- No iPhone o volume é controlado pelos botões do aparelho.
- Ao alterar CSS/JS do app, troque `CACHE` em `sw.js` (ex.: `radio-player-v3`) para os aparelhos atualizarem.
- Backup: basta copiar as pastas `data/` e `uploads/`.
