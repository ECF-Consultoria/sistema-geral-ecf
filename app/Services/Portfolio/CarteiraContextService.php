<?php

namespace App\Services\Portfolio;

use App\Models\Servico;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CarteiraContextService — fonte única e confiável de vínculos de carteira
 * por serviço da milestone v17.0 (Fase 88, fundação).
 *
 * Problema que resolve: `User::companies()` consolida por empresa e faz o
 * responsável Shopee "herdar" faturamento/margem de ML (ex.: Felipe avaliado
 * sobre 29 empresas gerenciando 4; Matheus sobre 15 gerenciando 0 — medido em
 * prod). Este service resolve setor, papel e elegibilidade financeira POR
 * VÍNCULO (não por empresa), sem tocar em nenhum consumidor existente
 * (reapontamentos são trabalho das Fases 89-92).
 *
 * IMPORTANTE — contrato de uso: consumidores devem SEMPRE usar `forUser()`,
 * nunca fazer join direto em `company_users.servico_id`. Um join direto
 * ignora o ramo legado (linhas `servico_id NULL` resolvidas via contrato
 * performance ativo — CTX-05) e deixaria esses vínculos invisíveis, mesmo
 * sendo vínculos válidos de Performance.
 *
 * Decisões travadas (documentadas aqui para o próximo dev que mexer nisto):
 *
 * 1. SEM CACHE. Diferente do `DesempenhoScoreService::computeCached()` (que
 *    existe porque `compute()` faz até 4 chamadas HTTP síncronas por empresa
 *    à API do Mercado Livre — 70s cold em prod), este service só roda
 *    queries locais (`company_users` JOIN `companies`/`servicos`/
 *    `contratos_servico`) sobre volume trivial (268 linhas de pivot em prod,
 *    medido 2026-07-16). Zero HTTP, zero motivo para Redis aqui.
 *
 * 2. NÃO chama o factory de provider técnico de métricas (`App\Services\
 *    Metrics`, ver 88-RESEARCH.md §Elegibilidade financeira). Dois
 *    vocabulários parecidos, NÃO intercambiáveis: aquele factory decide qual
 *    PROVIDER TÉCNICO lê revenue/margem de uma empresa ('ambos'|'so-ml'|
 *    'so-adman'|'none', setor-agnóstico). O `financial_source` deste service
 *    é sobre qual SETOR DE SERVIÇO tem qualquer fonte financeira associável
 *    — hoje só `performance` (valor constante `'adman'`), nunca `'ml'`/
 *    `'unified'`. Consumidores futuros (Fase 89+) combinam os dois: primeiro
 *    filtram por `financial_metrics_eligible=true` (este service), DEPOIS
 *    decidem ML vs Adman via aquele factory para ler o dado de fato.
 *
 * 3. CTX-05 (ramos legado `servico_id NULL`) é DEFESA contra escrita futura
 *    sem `servico_id`, NÃO migração. Contagem real em prod (2026-07-16, via
 *    VPS): dos 268 vínculos, 7 têm `servico_id NULL` e NENHUM deles pertence
 *    a empresa com contrato performance OU Shopee ativo — o backfill da
 *    Fase 76 já cobriu tudo que tinha contrato ativo. Por isso NÃO existe
 *    (nem deve existir) comando de backfill nesta fase: não há o que corrigir,
 *    só proteger contra o dia em que alguém gravar `company_users` sem
 *    `servico_id` de novo.
 *
 * 4. Setores `polos`/`publicacao`/`outros` caem no branch `default` de
 *    `flagsFinanceirasPorSetor()` — sem fonte financeira até segunda ordem.
 *    Nenhum vínculo real aponta pra esses setores hoje (nenhum writer grava
 *    isso); o branch é defensivo/futuro-proof para quando um desses setores
 *    ganhar fonte financeira própria.
 *
 * 5. `User::companies()` permanece INTOCADO como carteira consolidada legada
 *    (critério global da v17.0) — este service é uma alternativa nova, não
 *    um substituto imediato.
 *
 * @see plano-carteira-desempenho-multi-servico.md §Camada tecnica proposta
 * @see .planning/phases/88-.../88-RESEARCH.md (queries de referência + adendo com números reais de prod)
 */
class CarteiraContextService
{
    /**
     * Roles válidos na pivot `company_users` — papel NA EMPRESA, NUNCA
     * confundir com `users.role` (admin/consultor/mentor, papel no SISTEMA).
     * A pivot não tem 'mentor' desde a renomeação de 2026-05-22 (Pitfall 1
     * do 88-RESEARCH.md).
     */
    private const ROLES_VALIDOS = ['consultor', 'estrategista'];

    /**
     * Mapa role (pivot) → label pt-BR de UI, já consagrado no codebase
     * (CompanyController.php:756, ShopeeEmpresasController.php:250).
     */
    private const ROLE_LABELS = [
        'consultor'    => 'Analista',
        'estrategista' => 'Estrategista',
    ];

    /**
     * Retorna os vínculos de carteira×serviço ativos do profissional.
     *
     * Monta o resultado a partir de DUAS fontes, normalizadas no mesmo shape:
     *  1. `company_users.servico_id` PREENCHIDO — prioridade (CTX-05).
     *  2. `company_users.servico_id` NULL, resolvido como Performance legado
     *     SE (e só se) a empresa tiver contrato performance ativo — nunca
     *     promovido a Shopee automaticamente. Linhas cujo (company_id, role)
     *     já apareça na fonte 1 são excluídas daqui (o preenchido vence).
     *
     * @param  array{setor?: string|null, role?: string|null, active?: bool}  $filters
     * @return Collection<int, array{
     *   user_id: int, company_id: int, company_name: string,
     *   servico_id: ?int, servico_nome: ?string, setor: string,
     *   role: string, role_label: string,
     *   has_financial_source: bool, financial_source: ?string, financial_metrics_eligible: bool,
     * }>
     */
    public function forUser(User $user, array $filters = []): Collection
    {
        $active = $filters['active'] ?? true;

        $preenchidos = $this->vinculosComServicoPreenchido($user->id, $active);
        $legado      = $this->vinculosLegadoNull($user->id, $active, $preenchidos);

        $vinculos = $preenchidos->concat($legado)
            ->map(fn (array $linha) => $this->normalizar((int) $user->id, $linha));

        // Filtro de setor SÓ pode ser aplicado após a resolução — o setor de
        // uma linha servico_id NULL só é conhecido depois de cruzar com
        // contratos_servico (Pitfall 2 do 88-RESEARCH.md).
        if (! empty($filters['setor'])) {
            $vinculos = $vinculos->where('setor', $filters['setor']);
        }

        if (! empty($filters['role'])) {
            $vinculos = $vinculos->where('role', $filters['role']);
        }

        return $vinculos->values();
    }

    /**
     * Contadores de dedup (CTX-04) sobre uma Collection já resolvida por
     * `forUser()`. Deliberadamente NÃO colapsa vínculos — só computa
     * metadados. Nunca copiar o `distinct('companies.id')` de
     * `User::companies()` aqui (Pitfall 3 do 88-RESEARCH.md): isso
     * colapsaria os 2 vínculos de um profissional com Performance+Shopee na
     * mesma empresa em 1 única linha, exatamente o bug que a Fase 88 existe
     * para corrigir.
     *
     * @return array{empresas_unicas: int, vinculos_servico: int, vinculos_financeiros: int, vinculos_sem_fonte_financeira: int}
     */
    public function contadores(Collection $vinculos): array
    {
        return [
            'empresas_unicas'               => $vinculos->pluck('company_id')->unique()->count(),
            'vinculos_servico'               => $vinculos->count(),
            'vinculos_financeiros'           => $vinculos->where('financial_metrics_eligible', true)->count(),
            'vinculos_sem_fonte_financeira'  => $vinculos->where('financial_metrics_eligible', false)->count(),
        ];
    }

    /**
     * Fonte 1 — vínculos com `servico_id` PREENCHIDO (prioridade, CTX-05).
     * Espelha o padrão de `Company::consultorDoServico()`/`estrategistaDoServico()`
     * (`app/Models/Company.php:197-209`).
     */
    private function vinculosComServicoPreenchido(int $userId, bool $active): Collection
    {
        return DB::table('company_users as cu')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
            ->where('cu.user_id', $userId)
            ->whereNotNull('cu.servico_id')
            ->whereIn('cu.role', self::ROLES_VALIDOS)
            ->where('c.active', $active)
            ->select(
                'cu.company_id',
                'c.name as company_name',
                'cu.servico_id',
                's.nome as servico_nome',
                's.setor',
                'cu.role'
            )
            ->get()
            ->map(fn ($row) => (array) $row);
    }

    /**
     * Fonte 2 — vínculos com `servico_id` NULL, resolvidos como Performance
     * legado SE a empresa tiver contrato performance ativo (CTX-05). Shopee
     * NUNCA resolve por este caminho — regra explícita do plano canônico:
     * `servico_id null` + contrato Shopee ativo NÃO assume responsável
     * Shopee automaticamente.
     *
     * Exclui linhas cujo (company_id, role) já tenha vínculo na fonte 1 —
     * o preenchido vence, o vínculo nunca é duplicado.
     */
    private function vinculosLegadoNull(int $userId, bool $active, Collection $preenchidos): Collection
    {
        $chavesPreenchidas = $preenchidos
            ->map(fn (array $linha) => $linha['company_id'] . ':' . $linha['role'])
            ->flip();

        return DB::table('company_users as cu')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->whereNull('cu.servico_id')
            ->where('cu.user_id', $userId)
            ->whereIn('cu.role', self::ROLES_VALIDOS)
            ->where('c.active', $active)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('contratos_servico as ct')
                    ->join('servicos as s', 's.id', '=', 'ct.servico_id')
                    ->whereColumn('ct.company_id', 'cu.company_id')
                    ->where('ct.ativo', true)
                    ->where('s.setor', Servico::SETOR_PERFORMANCE);
            })
            ->select('cu.company_id', 'c.name as company_name', 'cu.role')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->reject(fn (array $linha) => $chavesPreenchidas->has($linha['company_id'] . ':' . $linha['role']))
            // Vínculo legado sai com servico_id/servico_nome null — é a
            // verdade da pivot, não inventamos o id do contrato.
            ->map(fn (array $linha) => $linha + [
                'servico_id'   => null,
                'servico_nome' => null,
                'setor'        => Servico::SETOR_PERFORMANCE,
            ]);
    }

    /**
     * Normaliza uma linha bruta (de qualquer uma das 2 fontes) no shape
     * público documentado em `forUser()`.
     */
    private function normalizar(int $userId, array $linha): array
    {
        return [
            'user_id'       => $userId,
            'company_id'    => (int) $linha['company_id'],
            'company_name'  => $linha['company_name'],
            'servico_id'    => isset($linha['servico_id']) ? (int) $linha['servico_id'] : null,
            'servico_nome'  => $linha['servico_nome'],
            'setor'         => $linha['setor'],
            'role'          => $linha['role'],
            'role_label'    => self::ROLE_LABELS[$linha['role']] ?? $linha['role'],
            ...$this->flagsFinanceirasPorSetor($linha['setor']),
        ];
    }

    /**
     * Elegibilidade financeira derivada de `servicos.setor` (CTX-02/CTX-03)
     * — sem hardcode de `servico_id`. Cobre Gestão (id 6) E Mentoria (id 7)
     * automaticamente porque ambos compartilham `setor='performance'` no
     * catálogo (migration `2026_06_18_100002_seed_servicos_setor.php`).
     *
     * @return array{has_financial_source: bool, financial_source: ?string, financial_metrics_eligible: bool}
     */
    private function flagsFinanceirasPorSetor(string $setor): array
    {
        return match ($setor) {
            Servico::SETOR_PERFORMANCE => [
                'has_financial_source'       => true,
                'financial_source'           => 'adman',
                'financial_metrics_eligible' => true,
            ],
            // Shopee e os demais setores (polos/publicacao/outros) caem
            // aqui — sem fonte financeira até segunda ordem (decisão 4).
            default => [
                'has_financial_source'       => false,
                'financial_source'           => null,
                'financial_metrics_eligible' => false,
            ],
        };
    }
}
