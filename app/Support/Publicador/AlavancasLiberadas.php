<?php

namespace App\Support\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\Company;
use App\Models\MlbEmpresa;

/**
 * D-03 (Fase 166) — contas liberadas para ESCREVER pelas Alavancas, separada de
 * ContasLiberadas (D21 da 164): liberar uma não libera a outra.
 *
 * Âncora checada = a que tem token. As listas são separadas por âncora
 * (`companies` e `mlb_empresas`): Company 5 não libera MlbEmpresa 5.
 * Lista vazia = ninguém (fail-closed).
 */
final class AlavancasLiberadas
{
    public const REGRA = 'ALAV-LIB';

    public const MOTIVO = 'As Alavancas ainda não foram liberadas para escrever nesta conta do Mercado Livre. Você pode ver e analisar tudo aqui; criar e alterar espera a liberação, que é feita conta a conta pelo time de desenvolvimento.';

    public static function libera(?ContaMercadoLivre $conta): bool
    {
        if ($conta instanceof Company) {
            $lista = config('publicador.alavancas.contas_liberadas.companies', []);
        } elseif ($conta instanceof MlbEmpresa) {
            $lista = config('publicador.alavancas.contas_liberadas.mlb_empresas', []);
        } else {
            return false;
        }

        return in_array((int) $conta->id, array_map('intval', $lista), true);
    }

    /**
     * A mesma regra pela chave da conta (`company-N` / `empresa-N`), para listas que só guardam a chave
     * (a fila das tarefas pós-publicação). Chave em outro formato = não liberada.
     */
    public static function liberaChave(?string $chave): bool
    {
        if (preg_match('/^(company|empresa)-(\d+)$/D', (string) $chave, $m) !== 1) {
            return false;
        }
        $lista = config($m[1] === 'company'
            ? 'publicador.alavancas.contas_liberadas.companies'
            : 'publicador.alavancas.contas_liberadas.mlb_empresas', []);

        return in_array((int) $m[2], array_map('intval', (array) $lista), true);
    }

    /** @throws RegraViolada quando a conta não está liberada para as Alavancas */
    public static function exigir(ContaMercadoLivre $conta): void
    {
        if (! self::libera($conta)) {
            throw new RegraViolada(self::REGRA, self::MOTIVO);
        }
    }
}
