<?php
declare(strict_types=1);

/**
 * Sequência de nutrição: 1 e-mail a cada 2 dias, por 20 dias (10 e-mails).
 * Texto: **destaque**. Marcadores: {first} {min} {max} {days} {per} {prepago_min} {mensal_min} {rule} {disclaimer}
 * Blocos: p · note · callout · list · steps (numerado) · stats (credit_min|prepago_min|mensal_min|days|range)
 *         plans (prepago|mensalista) · sim (nº de clientes)
 */
return [
    [
        'eyebrow' => 'Revenda IPTV · valores reais',
        'subject' => '{first}, quanto custa começar a revender IPTV?',
        'pre' => 'Os valores reais dos créditos, sem enrolação.',
        'title' => 'Quanto custa **começar a revender**, {first}?',
        'blocks' => [
            ['p', 'Você pediu um teste e já viu a plataforma funcionando. Agora vem a pergunta que importa para quem quer montar uma renda própria: **quanto custa começar?**'],
            ['stats', ['prepago_min', 'credit_prepago', 'days']],
            ['p', 'O modelo é simples: você compra **créditos**, usa créditos para ativar ou renovar cada cliente e cobra o valor que quiser. **A diferença fica com você.**'],
            ['plans', 'prepago'],
            ['callout', '**Sem mensalidade no pré-pago.** Você compra o pacote só quando precisar de mais créditos.'],
            ['p', 'Quer ajuda para escolher o pacote certo para o seu momento? Chame a gente no WhatsApp.'],
        ],
        'cta' => 'Tirar dúvidas no WhatsApp',
        'wa' => 'Olá! Recebi o e-mail da Dominius Play e quero entender os valores da revenda.',
        'link' => ['Ver pacotes pré-pagos', 'pre-pago'],
    ],
    [
        'eyebrow' => 'Como funciona',
        'subject' => 'Como funcionam os créditos (explicado em 2 minutos)',
        'pre' => 'Crédito é a moeda da sua operação. Veja como ele é usado.',
        'title' => 'Créditos **sem mistério**',
        'blocks' => [
            ['p', 'Todo o negócio gira em torno de um conceito: o **crédito**. Entender isso é entender a sua margem.'],
            ['steps', [
                'Você compra créditos (pré-pago ou mensalista).',
                'Cada ativação ou renovação de cliente usa **{per}** do seu saldo.',
                'O seu cliente ganha **{days} dias** de acesso.',
                'Na renovação você usa novos créditos, e **fica com a diferença** entre o que cobra e o que paga.',
            ]],
            ['list', [
                'Nos planos mensalistas, os créditos são **acumulativos**.',
                'No pré-pago você só compra quando precisar de mais.',
            ]],
            ['callout', '**Exemplo:** com 50 créditos você ativa 50 clientes por {days} dias. Se cobra {min} de cada um, é essa conta que vamos mostrar nos próximos e-mails.'],
            ['note', '{rule}'],
        ],
        'cta' => 'Quero entender melhor',
        'wa' => 'Olá! Li o e-mail sobre créditos da Dominius Play e tenho uma dúvida.',
    ],
    [
        'eyebrow' => 'Escolha seu plano',
        'subject' => 'Pré-pago ou mensalista: qual combina com você?',
        'pre' => 'Dois jeitos de comprar créditos. Veja qual encaixa no seu momento.',
        'title' => 'Pré-pago ou **mensalista**?',
        'blocks' => [
            ['p', 'Existem dois jeitos de comprar créditos. No **pré-pago** você compra pacotes quando precisar, sem mensalidade. No **mensalista** você paga um valor por mês e recebe os créditos do plano, com melhor custo por crédito.'],
            ['stats', ['prepago_min', 'mensal_min', 'credit_min']],
            ['plans', 'mensalista'],
            ['callout', '**Regra prática:** está começando e quer testar o mercado? Comece pelo pré-pago. Já tem clientes ou vai crescer rápido? O mensalista costuma sair mais barato por crédito.'],
            ['note', 'O plano Mensalista 100 também permite criar revendas e montar a sua própria equipe.'],
        ],
        'cta' => 'Qual plano é o meu?',
        'wa' => 'Olá! Quero ajuda para escolher entre pré-pago e mensalista na Dominius Play.',
        'link' => ['Ver planos mensalistas', 'mensalista'],
    ],
    [
        'eyebrow' => 'Preço e margem',
        'subject' => 'Quanto você pode cobrar do seu cliente final',
        'pre' => 'O preço final é sempre decisão sua. Veja como pensar nisso.',
        'title' => 'Seu preço, **sua margem**',
        'blocks' => [
            ['p', 'Quem revende define o próprio preço. Para as suas contas, vamos usar como referência **{min} a {max} por cliente ao mês**. O valor real depende da sua região, do seu público e da concorrência.'],
            ['stats', ['range', 'credit_min', 'days']],
            ['list', [
                '**Pesquise** quanto cobram na sua cidade antes de definir seu preço.',
                '**Use o teste** para o cliente conhecer antes de decidir.',
                '**Ofereça opções** (mensal, trimestral) para fidelizar.',
                '**Calcule o seu custo por crédito:** é ele que manda na sua margem.',
            ]],
            ['p', 'No site há uma **calculadora de margem** para você simular o seu cenário com os seus próprios números.'],
        ],
        'cta' => 'Falar sobre minha margem',
        'wa' => 'Olá! Quero conversar sobre preço e margem para revender com a Dominius Play.',
        'link' => ['Abrir a calculadora', 'calculadora'],
    ],
    [
        'eyebrow' => 'Simulação de resultado',
        'subject' => 'Simulação: quanto sobra com 30, 100 e 300 clientes',
        'pre' => 'Conta feita com os planos reais e a sua faixa de preço.',
        'title' => 'Vamos às **contas**',
        'blocks' => [
            ['p', 'Usando o plano mais barato que atende cada quantidade de clientes e cobrando **{min} por cliente**, é isto que sobra por mês:'],
            ['sim', [30, 100, 300]],
            ['note', '{disclaimer}'],
            ['callout', '**Quanto maior a carteira, menor o custo por crédito.** Por isso muita gente começa pequeno e vai migrando de plano conforme cresce.'],
        ],
        'cta' => 'Montar minha simulação',
        'wa' => 'Olá! Vi a simulação de lucro da Dominius Play e quero montar a minha.',
    ],
    [
        'eyebrow' => 'O que você recebe',
        'subject' => 'O que você tem à disposição para começar',
        'pre' => 'Ferramentas e apoio para sair do zero.',
        'title' => 'Você não começa **sozinho**',
        'blocks' => [
            ['p', 'Quem entra na revenda encontra um conjunto de recursos para a operação funcionar desde o primeiro dia:'],
            ['list', [
                '**Loja de aplicativos** no site, para o seu cliente instalar e usar.',
                '**Calculadora de margem** para planejar preços e metas.',
                '**Suporte e grupo de informações** nos planos mensalistas (veja os detalhes de cada plano).',
                '**Atendimento no WhatsApp** para tirar dúvidas antes de você comprar.',
            ]],
            ['p', 'Você pode conferir tudo isso no site antes de decidir qualquer coisa.'],
        ],
        'cta' => 'Pedir uma conversa',
        'wa' => 'Olá! Quero saber o que a Dominius Play oferece para quem está começando na revenda.',
        'link' => ['Ver aplicativos', 'aplicativos'],
    ],
    [
        'eyebrow' => 'Primeiros clientes',
        'subject' => 'Como conquistar seus 10 primeiros clientes',
        'pre' => 'Um roteiro simples para começar a vender.',
        'title' => 'Os **10 primeiros** clientes',
        'blocks' => [
            ['p', 'O começo é a parte que mais assusta. Este é um roteiro que costuma funcionar para quem está começando:'],
            ['steps', [
                '**Comece pelo seu círculo:** família, amigos, colegas e vizinhos. A confiança vem primeiro.',
                '**Ofereça um teste:** quem experimenta compra com mais segurança.',
                '**Responda rápido:** quem atende depressa vende mais.',
                '**Peça indicação:** cliente satisfeito é a sua melhor propaganda.',
                '**Anote tudo:** controle os vencimentos para renovar na data certa.',
            ]],
            ['callout', 'Com 10 clientes pagando {min} você já cobre o custo de um pacote pequeno e começa a ver o negócio andar.'],
        ],
        'cta' => 'Quero começar com 10 clientes',
        'wa' => 'Olá! Quero começar a revender com a Dominius Play. Por onde começo?',
    ],
    [
        'eyebrow' => 'Tire suas dúvidas',
        'subject' => 'As dúvidas que todo futuro revendedor tem',
        'pre' => 'Respostas diretas sobre custo, mensalidade e suporte.',
        'title' => 'Perguntas que **todo mundo** faz',
        'blocks' => [
            ['p', '**Preciso pagar mensalidade?** No pré-pago, não: você compra pacotes quando precisar, a partir de {prepago_min}. No mensalista, sim, a partir de {mensal_min}/mês.'],
            ['p', '**Posso começar pequeno?** Pode. O menor pacote pré-pago é o ponto de partida mais leve para testar o mercado.'],
            ['p', '**E se meu cliente não renovar?** Os créditos só são consumidos quando você ativa ou renova um cliente.'],
            ['p', '**Tenho suporte?** Os planos mensalistas incluem benefícios como suporte e grupo de informações, e você sempre pode falar com a gente no WhatsApp.'],
            ['callout', 'Alguma dúvida que não está aqui? **É só perguntar.** Respondemos pelo WhatsApp.'],
        ],
        'cta' => 'Perguntar no WhatsApp',
        'wa' => 'Olá! Tenho uma dúvida sobre a revenda da Dominius Play.',
    ],
    [
        'eyebrow' => 'Para crescer mais',
        'subject' => 'Franquia e apontamento: para quem pensa grande',
        'pre' => 'Modelos personalizados para operações maiores.',
        'title' => 'Para quem quer **ir além**',
        'blocks' => [
            ['p', 'Além dos planos pré-pago e mensalista, a Dominius Play tem modelos para operações maiores:'],
            ['list', [
                '**Franquia personalizada:** modelo sob medida para quem já tem estrutura e quer escalar.',
                '**Apontamento para servidores:** para quem já opera o próprio servidor.',
            ]],
            ['callout', 'Esses modelos são montados conforme a sua operação. O melhor caminho é **conversar com a gente** para entender o que faz sentido para você.'],
        ],
        'cta' => 'Conversar sobre franquia',
        'wa' => 'Olá! Tenho interesse no modelo de franquia ou apontamento da Dominius Play.',
        'link' => ['Ver franquias', 'franquias'],
    ],
    [
        'eyebrow' => 'Último e-mail da sequência',
        'subject' => '{first}, vamos montar o seu plano?',
        'pre' => 'Último e-mail da sequência. Resumo e próximos passos.',
        'title' => 'Vamos **montar o seu plano**?',
        'blocks' => [
            ['p', 'Este é o último e-mail desta sequência. Resumindo o que vimos nos últimos dias:'],
            ['stats', ['prepago_min', 'mensal_min', 'credit_min']],
            ['list', [
                'Você compra créditos e ativa ou renova cada cliente por **{days} dias**.',
                'Você define o preço do seu cliente final (usamos **{min} a {max}** como referência).',
                'Quanto maior a carteira, **menor o custo por crédito**.',
            ]],
            ['callout', 'Se quiser seguir em frente, **me chame no WhatsApp**. Eu te ajudo a escolher o plano e a montar os primeiros passos da operação.'],
            ['note', 'Se não for o momento, tudo bem: o seu teste e o site continuam à disposição.'],
        ],
        'cta' => 'Montar meu plano agora',
        'wa' => 'Olá! Quero montar meu plano de revenda na Dominius Play.',
        'link' => ['Ver todos os planos', 'pre-pago'],
    ],
];
