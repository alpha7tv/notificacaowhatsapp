# Rádio Player (PWA)

Player de web rádios responsivo em formato de aplicativo. É só HTML, CSS e JavaScript — sem
build, sem dependências e independente do painel de notificações.

## Recursos

- Lista de rádios com busca, filtro por gênero e abas **Todas / Favoritas / Recentes**
- Mini player fixo no rodapé e tela cheia "Tocando agora" (arrastar para baixo ou botão voltar fecha)
- Instalável no celular e no computador (PWA), com ícone na tela inicial
- Controles na tela de bloqueio, fone Bluetooth e central de mídia (Media Session API)
- Reconexão automática quando o sinal cai (até 5 tentativas) e ao voltar a internet
- Título da música tocando (servidores Icecast ou endpoint JSON, se tiverem CORS liberado)
- Timer para desligar, volume/mudo, compartilhar rádio por link (`?r=id`)
- Tema claro/escuro automático; atalhos de teclado (espaço, ← →, Esc)
- Favoritos, recentes, volume e última rádio ficam salvos no aparelho

## Cadastrar as rádios

Edite `stations.json`:

```json
{
  "title": "Minha Rádio",
  "accent": "#e5484d",
  "stations": [
    {
      "id": "minha-fm",
      "name": "Minha FM 98.1",
      "stream": "https://stream.exemplo.com.br:8000/live",
      "logo": "logos/minha-fm.png",
      "genre": "Sertanejo",
      "city": "Goiânia - GO",
      "slogan": "A rádio que toca você",
      "metadata": "https://stream.exemplo.com.br:8000/status-json.xsl"
    }
  ]
}
```

| Campo      | Obrigatório | Descrição |
|------------|:-----------:|-----------|
| `id`       | recomendado | Identificador único (usado nos favoritos e no link compartilhado) |
| `name`     | sim | Nome da rádio |
| `stream`   | sim | URL do áudio (MP3/AAC). **Precisa ser HTTPS** se o site estiver em HTTPS |
| `logo`     | não | Imagem quadrada. Sem logo, o app gera uma capa com as iniciais |
| `genre`    | não | Gera os filtros por gênero |
| `city`, `slogan` | não | Textos de apoio |
| `metadata` | não | URL do `status-json.xsl` do Icecast ou de um JSON com `title`/`artist` |

Com só uma rádio cadastrada, o app já abre com ela selecionada (bom para app de uma única rádio).
As rádios do arquivo atual são apenas exemplos públicos — troque pelas suas.

## Publicar

Qualquer hospedagem estática serve (Nginx, Apache, Netlify, GitHub Pages…). Para instalar como
aplicativo é obrigatório **HTTPS** (ou `localhost`). Teste local:

```bash
cd radio-player
python3 -m http.server 8080   # abra http://localhost:8080
```

Ao mudar CSS/JS, troque `CACHE` em `sw.js` (ex.: `radio-player-v2`) para forçar a atualização.

## Observações

- Navegadores bloqueiam autoplay: a rádio só começa depois que a pessoa toca no ▶.
- No iPhone o volume é controlado apenas pelos botões do aparelho.
- Streams HLS (`.m3u8`) tocam nativamente no Safari; nos outros navegadores seria preciso
  adicionar a biblioteca hls.js.
