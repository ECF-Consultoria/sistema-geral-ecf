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

];
