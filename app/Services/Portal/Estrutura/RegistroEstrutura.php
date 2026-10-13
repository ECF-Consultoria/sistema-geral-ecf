<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\Eloquent\Model;

/**
 * A trilha do Mapeamento Estrutural no `activity_log`, canal `portal`.
 *
 * Cliente e equipe escrevem nas mesmas telas. Quem distingue os dois é a
 * propriedade `origem`, gravada aqui na mão: o `causer_id` automático do
 * Spatie NÃO distingue — uma sessão interna aberta em outra aba carimba um
 * funcionário numa ação do cliente (`portal-do-cliente.md` §12).
 *
 * `origem = 'bling'` (Fase 184, BLING-21) é ação automática de uma varredura
 * agendada: nasce SEM causer de propósito — carimbar um funcionário aqui seria
 * um `causer_id` apontando para alguém que não fez nada. `->causedBy()` só é
 * chamado quando `$ator->modelo` não é nulo.
 */
final class RegistroEstrutura
{
    public static function registrar(AtorDoPortal $ator, Company $empresa, ?Model $alvo, string $evento, string $descricao, array $extra = []): void
    {
        $log = activity('portal');

        if ($ator->modelo !== null) {
            $log->causedBy($ator->modelo);
        }

        $log->withProperties([
            'modulo'     => 'estrutura',
            'evento'     => $evento,
            'origem'     => $ator->origem,
            'company_id' => $empresa->id,
            ...$extra,
        ]);

        if ($alvo) {
            $log->performedOn($alvo);
        }

        $log->log($descricao);
    }
}
