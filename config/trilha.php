<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Empresas ligadas à Trilha de produtos
    |--------------------------------------------------------------------------
    |
    | Os `companies.id` que a trilha abre, nas DUAS portas: a da equipe
    | (`/trilha/{empresa}`, que além disso exige o módulo `trilha.produtos`
    | liberado — enquanto ele está oculto, só o Dev) e a do cliente
    | (`/portal/{empresa}/trilha`, que não tem outra trava além desta).
    |
    | Por isso o padrão é só a #459, a loja de TESTE: a trilha está na 1ª
    | entrega, e ligar empresa de cliente aqui é mostrar a tela para ele.
    | Ligar/desligar = a lista no `.env` (`TRILHA_EMPRESAS=459,12`) +
    | `php artisan config:cache`, sem deploy de código (em produção a config
    | fica em cache: sem o `config:cache`, o `.env` novo não vale). Vazio
    | (`TRILHA_EMPRESAS=`) desliga a trilha para todo mundo.
    |
    | Só inteiros positivos entram: "459, 12" vira [459, 12]; "abc", "12abc" e
    | "0" são ignorados — um id torto não pode virar 0 e casar com algo. A
    | conferência de verdade é `App\Support\Trilha\TrilhaLigada`.
    |
    */

    'empresas' => array_values(array_unique(array_map('intval', array_filter(
        array_map('trim', explode(',', (string) env('TRILHA_EMPRESAS', '459'))),
        fn (string $id) => ctype_digit($id) && (int) $id > 0,
    )))),

    /*
    |--------------------------------------------------------------------------
    | A trilha como LUGAR DE TRABALHO (2ª entrega, 02/11/2026)
    |--------------------------------------------------------------------------
    |
    | Os `companies.id` em que a equipe mapeia e planeja DENTRO da trilha e em
    | que o robô (o agente de IA do Publicador) só prepara uma oferta quando
    | chega o dia da onda dela. Vale só para empresa que também está em
    | `trilha.empresas` (`App\Support\Trilha\TrilhaDeTrabalho`).
    |
    | Nasce VAZIA de propósito, como os interruptores do Publicador: muda o
    | comportamento do robô em produção. Ligar = `TRILHA_TRABALHO_EMPRESAS=459`
    | no `.env` + `php artisan config:cache` + `php artisan queue:restart` (o
    | worker do preparo carrega a configuração ao subir). Desligar é o mesmo
    | com a lista vazia; ondas e marcas ficam no banco.
    |
    */

    'trabalho' => [
        'empresas' => array_values(array_unique(array_map('intval', array_filter(
            array_map('trim', explode(',', (string) env('TRILHA_TRABALHO_EMPRESAS', ''))),
            fn (string $id) => ctype_digit($id) && (int) $id > 0,
        )))),
    ],

    /*
    |--------------------------------------------------------------------------
    | A aprovação do CLIENTE por loja (4ª entrega, fatia 4e, 04/11/2026)
    |--------------------------------------------------------------------------
    |
    | Os `companies.id` em que o anúncio preparado pela trilha só vai para a
    | fila de publicação DEPOIS de o cliente aprovar a prévia (desenho §19.16,
    | D53). Com a loja aqui, o primário da revisão da equipe vira [Mandar ao
    | cliente] e o "Aprovar e publicar" direto da equipe responde 409 (a
    | aprovação do cliente não valeria nada); o cliente aprova (o anúncio
    | entra na fila com quem mandou) ou pede ajuste (texto para a equipe).
    |
    | Vale só para empresa que também está na trilha de trabalho
    | (`App\Support\Trilha\AprovacaoDoCliente`, cruzada com `TrilhaDeTrabalho`).
    | Nasce VAZIA: muda quem publica. Ligar = `TRILHA_APROVACAO_CLIENTE_EMPRESAS=459`
    | no `.env` + `php artisan config:cache`. Desligar é o mesmo com a lista
    | vazia: tudo volta a ser como na 4d, e as linhas de `trilha_aprovacoes`
    | ficam no banco sem efeito.
    |
    */

    'aprovacao_cliente' => [
        'empresas' => array_values(array_unique(array_map('intval', array_filter(
            array_map('trim', explode(',', (string) env('TRILHA_APROVACAO_CLIENTE_EMPRESAS', ''))),
            fn (string $id) => ctype_digit($id) && (int) $id > 0,
        )))),
    ],

    /*
    | A soltura das ondas (`trilha:soltar-ondas`): quantos produtos cada passada
    | pede ao robô por empresa (o resto vai na passada seguinte, 15 minutos
    | depois), por quantos dias depois do início a passada repete o pedido de
    | quem nunca começou, e depois de quantas horas sem começar a trilha avisa.
    */
    'ondas' => [
        'lote'            => max(1, (int) env('TRILHA_ONDAS_LOTE', 30)),
        'repescagem_dias' => 7,
        'parado_horas'    => 24,
    ],

];
