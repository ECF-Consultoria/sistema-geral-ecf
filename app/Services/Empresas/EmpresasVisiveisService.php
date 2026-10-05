<?php

namespace App\Services\Empresas;

use App\Models\Company;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * O universo de empresas da tela `/companies` para um usuário — QUAIS empresas
 * entram e QUEM vê o quê.
 *
 * Mora aqui, e não dentro de `CompanyController::index()`, porque duas
 * entradas leem a mesma lista: a tela e a ferramenta `listar_empresas` do MCP
 * (`App\Mcp\Tools\ListarEmpresasTool`). Uma cópia da régua em cada lado
 * divergiria no primeiro ajuste — e o MCP passaria a mostrar empresa que a
 * tela esconde (ou o contrário).
 *
 * A consulta devolvida NÃO tem eager load, ordenação nem os filtros opcionais
 * da tela: cada chamador acrescenta o que precisa.
 */
class EmpresasVisiveisService
{
    /**
     * Slug do setor cujo LÍDER enxerga todas as empresas de `/companies`
     * (Fase 157, D-B).
     *
     * Casa com `servicos.setor = 'performance'`, que é o valor que o catálogo
     * de serviços já usava — a linha em `setores` foi criada pela migration
     * `2026_09_10_140000_seed_setor_performance` justamente para os dois
     * vocabulários passarem a se encontrar.
     */
    public const SETOR_DA_LIDERANCA = 'performance';

    /**
     * Empresas que `/companies` lista para este usuário.
     *
     *  - Phase 35 (D-03): exclui empresas com MlbEmpresa associada, para não
     *    contar em dobro com /mlb/empresas (Polos/Publicação).
     *  - Phase 37 (REQ-37-07): só quem tem contrato ATIVO de serviço do setor
     *    Performance (Gestão + Mentoria). Publicação/Outros vivem em
     *    /comercial/empresas/listagem.
     *  - Fase 157 (D-A): analista e estrategista veem só as empresas em que
     *    estão vinculados — ver {@see deveFiltrarPelaPropriaCarteira()}.
     */
    public function consulta(?User $usuario): Builder
    {
        return Company::query()
            ->whereDoesntHave('mlbEmpresa')
            ->whereHas('contratosServico', fn ($q) =>
                $q->where('contratos_servico.ativo', true)
                  ->whereHas('servico', fn ($qs) =>
                      $qs->where('setor', Servico::SETOR_PERFORMANCE)
                  )
            )
            // ─── Fase 157 (D-A) — VISIBILIDADE POR VÍNCULO ─────────────────
            //
            // Até a Fase 157 `/companies` mostrava TODAS as empresas de
            // Performance para qualquer um com acesso à tela. Passou a mostrar
            // só as do próprio usuário para quem tem cargo `analista` ou
            // `estrategista`.
            //
            // ⚠️ Isto MUDOU o que usuários enxergam — quem via ~180 empresas
            // passou a ver só as suas. Foi pedido na letra ("vai aparecer
            // apenas as empresas destinadas pra ele"), e está anotado aqui
            // porque alguém vai estranhar antes de lembrar que foi pedido.
            //
            // Quem NÃO é filtrado, e por quê:
            //  - admin: vê tudo, como sempre;
            //  - líder do setor Performance: precisa ver tudo para distribuir;
            //  - quem não tem nenhum dos dois cargos: comportamento inalterado
            //    — a regra é sobre analista/estrategista, não sobre "não-admin".
            ->when(
                $this->deveFiltrarPelaPropriaCarteira($usuario),
                fn ($q) => $q->whereHas(
                    'users',
                    fn ($qu) => $qu->where('users.id', $usuario->id)
                )
            );
    }

    /**
     * O usuário deve ver apenas as empresas em que está vinculado? (D-A)
     *
     * `true` só para quem tem cargo `analista` ou `estrategista` e **não** é
     * admin nem líder do setor Performance.
     *
     * A régua é sobre CARGO, não sobre "não-admin": quem não tem nenhum dos
     * dois cargos (ex.: um consultor de outro setor, um financeiro) continua
     * vendo o que via. Restringir por exclusão em vez de por cargo tiraria
     * acesso de gente que o pedido não menciona.
     *
     * O líder é a exceção explícita — ele precisa ver tudo para distribuir. O
     * Luiz é líder E estrategista; a regra de líder vence, e a visão "só as
     * minhas" fica disponível para ele pelo filtro da tela.
     */
    public function deveFiltrarPelaPropriaCarteira(?User $usuario): bool
    {
        if ($usuario === null || $usuario->isAdmin()) {
            return false;
        }

        if ($this->ehLiderDaPerformance($usuario)) {
            return false;
        }

        return $usuario->cargoDesempenhoSlug() !== null;
    }

    /** Líder do setor Performance — quem distribui (D-B). */
    public function ehLiderDaPerformance(?User $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        $setorId = Setor::where('slug', self::SETOR_DA_LIDERANCA)->value('id');

        return $setorId !== null && $usuario->isLiderDe($setorId);
    }
}
