<?php
declare(strict_types=1);

/**
 * Mensagens de WhatsApp da sequência.
 * Marcadores: {first} {sender} {site} {hours} {prepago_min}. Formatação do WhatsApp: *negrito*.
 * 'followups': enviadas quando o teste acaba (NURTURE_FOLLOWUP_HOURS depois da geração). A variação é
 * escolhida pelo id da inscrição, para que as mensagens não sejam todas idênticas.
 */
return [
    'followups' => [
        "Oi, {first}! Aqui é o {sender}, da {site}. 👋\n\n"
        . "O seu teste de {hours}h acabou de terminar. Queria saber: *você conseguiu testar direitinho?* 📺\n\n"
        . "Me responde só com o número:\n"
        . "1️⃣ Funcionou perfeito! Quero saber os valores\n"
        . "2️⃣ Tive dificuldade para instalar ou entrar\n"
        . "3️⃣ Quero ter o meu próprio negócio com revenda\n\n"
        . "Se travou em algum passo, me chama que eu te ajudo agora mesmo. 💬",

        "{first}, tudo bem? É o {sender}, da {site}. 😊\n\n"
        . "Seu teste terminou. Se a qualidade te agradou, tenho uma pergunta direta: *você já pensou em ter uma renda própria com isso?*\n\n"
        . "Quem revende com a gente começa a partir de {prepago_min}, usa os créditos para ativar os próprios clientes e *fica com a diferença* de cada um. 💰\n\n"
        . "Quer que eu te explique como funciona, com os números na mão? Responde *SIM* que eu te mostro. 🚀",

        "Oi, {first}! {sender} aqui, da {site}. 👋\n\n"
        . "Passando para saber como foi o seu teste. 📺 *Deu tudo certo para assistir?*\n\n"
        . "Se teve qualquer dificuldade (aplicativo, lista, login), me diz *qual aparelho você usou* (TV, TV Box, celular…) que eu te passo o passo a passo certinho. 🛠️\n\n"
        . "E se já estiver curtindo, me chama que eu te conto como funcionam os planos e a revenda. 😉",
    ],
    'optout' => "\n\n_Se não quiser mais receber mensagens, é só me avisar._",
];
