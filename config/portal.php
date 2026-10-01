<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domínio do Portal do Cliente
    |--------------------------------------------------------------------------
    |
    | O Portal roda na MESMA aplicação do sistema interno, servida num segundo
    | domínio pelo Nginx (`cliente.ecfconsultoria.com.br`). Esta chave diz qual
    | é esse domínio, e é o que liga o `RestringeDominioDoPortal`: nele, só as
    | rotas do Portal existem; todo o resto do sistema responde 404.
    |
    | Vazio (o padrão) desliga a restrição — ambiente local e qualquer
    | instalação sem subdomínio seguem servindo tudo em um endereço só, como
    | sempre foi. É também o botão de emergência: esvaziar esta variável e
    | limpar o cache de config devolve o comportamento antigo sem deploy.
    |
    */

    'dominio_cliente' => env('PORTAL_CLIENTE_DOMINIO'),

    /*
    |--------------------------------------------------------------------------
    | Código de acesso
    |--------------------------------------------------------------------------
    |
    | Seis dígitos só são seguros pela SOMA de quatro limites: vida curta, uso
    | único, teto de tentativas e amarração à sessão que pediu. Afrouxar
    | qualquer um muda a conta — ver o docblock da migration
    | `create_portal_codigos_acesso_table`.
    |
    | Dez minutos é o equilíbrio entre o e-mail demorar a chegar e a janela em
    | que um código encaminhado ainda valeria para quem estivesse na mesma
    | sessão.
    |
    */

    'codigo' => [
        'digitos' => 6,
        'minutos' => (int) env('PORTAL_CODIGO_MINUTOS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duração da sessão do cliente
    |--------------------------------------------------------------------------
    |
    | Em minutos. 30 dias: o cliente entra uma vez por mês e no resto do tempo
    | só abre o site — que era o incômodo original (ter de achar o link toda
    | vez). Independente de SESSION_LIFETIME, que vale para o time da ECF e é
    | curto de propósito.
    |
    */

    'sessao_minutos' => (int) env('PORTAL_SESSAO_MINUTOS', 43200),

    /*
    |--------------------------------------------------------------------------
    | Link aberto de equipe — EXCEÇÃO, não regra
    |--------------------------------------------------------------------------
    |
    | `companies.id => users.id`. A empresa listada aqui ganha um link fixo e
    | assinado (`php artisan portal:link-equipe {id}`) que abre o portal dela
    | SEM login, como sessão de equipe em nome do usuário ao lado.
    |
    | É a porta que o login de 15/09/2026 fechou para todo mundo, reaberta só
    | para loja de TESTE da própria ECF — nunca para cliente real: quem tiver o
    | link opera, e não há como saber quem clicou. Por isso o dono é uma conta
    | genérica, e não o nome de um dev que pode não ter sido quem agiu.
    |
    | Revogar = tirar a linha daqui e publicar. A lista é relida a cada
    | requisição, então derruba também quem já estava dentro.
    |
    */

    'link_equipe' => [
        // 01/10/2026 — "Dev 02 Testes API", loja onde começa o teste do
        // Anunciar pelo portal. Dono: "Admin" (#1), a conta genérica.
        459 => 1,
    ],

];
