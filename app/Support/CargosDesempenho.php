<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CargosDesempenho — fonte ÚNICA de "quais cargos de Desempenho
 * (analista/estrategista) uma pessoa tem", para EXIBIÇÃO/FILTRO nas quatro
 * telas de desempenho e bônus (D-05, Fase 159: PerformanceController,
 * RelatorioBonificacaoController, BonusAuditoriaController, PortfolioController).
 *
 * NUNCA usar para o CÁLCULO da nota — isso continua em
 * `User::dimensaoNpsDesempenho()` / `DesempenhoScoreService`, que esta classe
 * não toca (D-09 é medição, não código; fora deste plano).
 *
 * Antes desta classe havia pelo menos 6 implementações ad-hoc da mesma
 * pergunta (`User::cargoDesempenhoSlug()`, `$cargosPorUser` repetido em 3
 * controllers, `$cargoSlug` em `PortfolioController` 2×), cada uma resolvendo
 * o empate de "pessoa com dois cargos" de um jeito ligeiramente diferente —
 * a pessoa podia sumir da aba "errada" do ranking (pesquisa §6 da Fase 159).
 */
final class CargosDesempenho
{
    /** Ordem canônica de exibição — também a ordem de `slugs` no retorno de porUsuario(). */
    public const SLUGS = ['analista', 'estrategista'];

    /** Rótulos pt-BR por slug. */
    public const ROTULOS = [
        'analista'     => 'Analista',
        'estrategista' => 'Estrategista',
    ];

    /**
     * Cargos de Desempenho de vários usuários, numa ÚNICA query.
     *
     * Desempate do cargo PRINCIPAL: `is_principal` primeiro, depois a linha
     * de MENOR id (a mais antiga) — nunca a ordem de retorno do banco, que o
     * MariaDB não garante estável entre execuções sem ORDER BY explícito.
     *
     * @param array<int>|null $userIds Restringe a estes usuários; null = todos com cargo de Desempenho.
     * @return Collection<int, array{slugs: list<string>, principal: string|null}>
     */
    public static function porUsuario(?array $userIds = null): Collection
    {
        $linhas = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->whereIn('c.slug', self::SLUGS)
            ->when($userIds !== null, fn ($q) => $q->whereIn('us.user_id', $userIds))
            ->orderByDesc('us.is_principal')
            ->orderBy('us.id')
            ->select('us.user_id', 'c.slug')
            ->get();

        return $linhas
            ->groupBy('user_id')
            ->map(function (Collection $grupo) {
                $slugsDoUsuario = $grupo->pluck('slug')->unique()->all();

                // Ordem CANÔNICA de exibição (self::SLUGS), nunca a de inserção.
                $slugsEmOrdemCanonica = collect(self::SLUGS)
                    ->filter(fn ($slug) => in_array($slug, $slugsDoUsuario, true))
                    ->values()
                    ->all();

                // Grupo já vem ordenado por is_principal DESC, id ASC (da query
                // acima) — o primeiro elemento é sempre o cargo principal.
                return [
                    'slugs'     => $slugsEmOrdemCanonica,
                    'principal' => $grupo->first()->slug,
                ];
            });
    }

    /** Cargos de Desempenho de UM usuário. Nunca lança — devolve slugs vazio quando não há cargo. */
    public static function doUsuario(int $userId): array
    {
        return self::porUsuario([$userId])->get($userId) ?? ['slugs' => [], 'principal' => null];
    }

    /**
     * Rótulo de exibição para uma lista de slugs.
     *
     * Com `$filtro` presente em `$slugs`, devolve só o rótulo do filtro (a
     * aba do ranking/relatório mostra o cargo DA ABA, não todos os cargos da
     * pessoa). Sem filtro, devolve os rótulos em ordem canônica unidos por
     * " · " (ponto médio U+00B7, com espaços). Lista vazia → null.
     */
    public static function rotulo(array $slugs, ?string $filtro = null): ?string
    {
        if ($filtro !== null && in_array($filtro, $slugs, true)) {
            return self::ROTULOS[$filtro] ?? null;
        }

        if (empty($slugs)) {
            return null;
        }

        return collect(self::SLUGS)
            ->filter(fn ($slug) => in_array($slug, $slugs, true))
            ->map(fn ($slug) => self::ROTULOS[$slug])
            ->implode(' · ');
    }
}
