<?php

namespace App\Services\Creative;

use App\Models\CreativeIdentidade;
use App\Models\MlAnuncioCriativo;

/**
 * Resolve o texto de identidade visual da conta de um criativo (Fase 170,
 * D2, IDENT-02..05) — devolve `null` quando a conta não tem identidade
 * cadastrada (IDENT-03: nada muda no prompt) e NUNCA lança: é chamado dentro
 * do `handle()` de `GerarCriativoIaJob`, que já tem `failed()` próprio — uma
 * excepção aqui queimaria a geração sem necessidade nenhuma.
 */
class CreativeIdentidadeService
{
    /**
     * `null` quando a conta não tem registro de identidade, ou quando o
     * registro existe mas o `texto` está vazio/em branco — os dois casos
     * têm o mesmo efeito (nenhuma linha IDENTIDADE no prompt).
     */
    public function paraCriativo(MlAnuncioCriativo $criativo): ?string
    {
        $registro = CreativeIdentidade::paraAncora($criativo->company_id, $criativo->mlb_empresa_id);

        if ($registro === null) {
            return null;
        }

        $texto = trim((string) $registro->texto);

        return $texto !== '' ? $texto : null;
    }
}
