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

];
