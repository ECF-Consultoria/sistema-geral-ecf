<?php

namespace App\Services\Mlb\Publicacao\Builders;

/**
 * Modelo USER PRODUCTS (preço por variação) — o novo paradigma do ML.
 *
 * Diferença central: o TÍTULO não é enviado na publicação (o ML o deriva do
 * produto/atributos). Os demais campos seguem iguais ao Clássico. Ajustes finos
 * de família/variação (family_id etc.) ficam para quando houver uma conta
 * migrada disponível para testar — hoje detectamos o modelo pela tag
 * `user_product_seller`, mas o spike só teve conta Clássica.
 */
class UserProductItemBuilder extends ItemBuilderBase
{
    public function montar(array $dados): array
    {
        // Sem 'title' — é a única diferença conhecida e testável hoje.
        return $this->montarComum($dados);
    }

    public function modelo(): string
    {
        return 'user_product';
    }
}
