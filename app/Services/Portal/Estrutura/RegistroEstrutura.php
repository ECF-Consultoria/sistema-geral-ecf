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
 */
final class RegistroEstrutura
{
    public static function registrar(AtorDoPortal $ator, Company $empresa, ?Model $alvo, string $evento, string $descricao, array $extra = []): void
    {
        $log = activity('portal')
            ->causedBy($ator->modelo)
            ->withProperties([
                'modulo'     => 'estrutura',
                'evento'     => $evento,
                'origem'     => $ator->equipe ? 'interno' : 'cliente',
                'company_id' => $empresa->id,
                ...$extra,
            ]);

        if ($alvo) {
            $log->performedOn($alvo);
        }

        $log->log($descricao);
    }
}
