<?php

namespace App\Support\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\Company;
use App\Models\MlbEmpresa;

/**
 * D21 — contas do Mercado Livre liberadas para publicar pelo Publicador.
 *
 * As listas são SEPARADAS por âncora (`companies` e `mlb_empresas`): Company 5
 * liberada não libera MlbEmpresa 5. Lista vazia = NINGUÉM (fail-closed), ao
 * contrário do antigo `empresas_piloto`, onde vazio significava todas.
 * A checagem é sobre a âncora que PUBLICA (a que tem token).
 */
final class ContasLiberadas
{
    public static function libera(?ContaMercadoLivre $conta): bool
    {
        if ($conta instanceof Company) {
            $lista = config('publicador.contas_liberadas.companies', []);
        } elseif ($conta instanceof MlbEmpresa) {
            $lista = config('publicador.contas_liberadas.mlb_empresas', []);
        } else {
            return false;
        }

        return in_array((int) $conta->id, array_map('intval', $lista), true);
    }

    /** @throws RegraViolada quando a conta não está liberada */
    public static function exigir(ContaMercadoLivre $conta): void
    {
        if (! self::libera($conta)) {
            throw new RegraViolada('CONTA-LIB', 'A publicação ainda não foi liberada para esta conta do Mercado Livre. Você pode preparar o rascunho e conferir os dados aqui; a validação no Mercado Livre também espera a liberação.');
        }
    }
}
