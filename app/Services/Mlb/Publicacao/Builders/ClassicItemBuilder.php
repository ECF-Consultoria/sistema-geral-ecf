<?php

namespace App\Services\Mlb\Publicacao\Builders;

/**
 * Modelo CLÁSSICO de publicação — envia o título normalmente.
 * É o modelo da maioria das contas (e da conta de teste do spike).
 */
class ClassicItemBuilder extends ItemBuilderBase
{
    public function montar(array $dados): array
    {
        $payload = $this->montarComum($dados);

        if (! empty($dados['title'])) {
            $payload['title'] = $dados['title'];
        }

        return $payload;
    }

    public function modelo(): string
    {
        return 'classic';
    }
}
