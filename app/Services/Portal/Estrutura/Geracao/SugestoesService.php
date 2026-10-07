<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;

/**
 * Uma geração de sugestões por requisição (Fase 168): a mesma alimenta a lista, o
 * aceite (que regera e confere a chave) e o descarte. A empresa vem do contexto do
 * portal, nunca do request.
 */
class SugestoesService
{
    public function __construct(private RetratoDoCatalogo $retrato) {}

    /**
     * @return array{sugestoes: list<array<string,mixed>>, retrato: array<string,mixed>}
     */
    public function gerar(Company $empresa): array
    {
        $retrato = $this->retrato->daEmpresa($empresa);

        return [
            'sugestoes' => GeradorDeSugestoes::gerar($retrato),
            'retrato'   => $retrato,
        ];
    }
}
