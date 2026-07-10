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
        $payload = $this->montarComum($dados);

        // Sem 'title'. O texto digitado pelo usuário vira o `family_name` — o ML
        // deriva o título exibido a partir dele. Confirmado em conta User Products:
        // sem family_name o validate retorna body.required_fields [family_name].
        if (! empty($dados['title'])) {
            $payload['family_name'] = $dados['title'];
        }

        return $payload;
    }

    public function modelo(): string
    {
        return 'user_product';
    }
}
