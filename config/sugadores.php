<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shadow companies (Phase 40)
    |--------------------------------------------------------------------------
    |
    | CSV de company_ids elegíveis para shadow mode ML.
    | Usado por `sugadores:shadow-ml --company=all` e pelo scheduler diário.
    | Vazio = scheduler diário não roda shadow pra ninguém (use --company={id} manual).
    |
    */
    'ml_shadow_companies' => array_values(array_filter(
        array_map('intval', array_filter(
            array_map('trim', explode(',', (string) env('SUGADORES_ML_SHADOW_COMPANIES', '')))
        ))
    )),
];
