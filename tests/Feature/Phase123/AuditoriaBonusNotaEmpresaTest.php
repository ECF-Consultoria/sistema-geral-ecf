<?php

namespace Tests\Feature\Phase123;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova de UIEM-04/D-10 (123-03-PLAN.md Task 1) — a Auditoria de Bônus
 * recebe `nota_empresa` e os componentes de cada empresa da MESMA fonte que
 * o detalhe do profissional lê (`CompanyScoreSnapshotReader`), numa única
 * query por página, distinguindo os dois níveis de ausência da D-03:
 * "competência sem detalhe nenhum" (banner de página) de "este profissional
 * sem linha" ("—" silencioso, resolvido no front pela Task 2).
 *
 * `BonusAuditoriaController::index()` passou a ser snapshot-first para
 * `nota_final` (CR-02/gap 2 do 123-VERIFICATION.md, fechado pelo
 * 123-08-PLAN.md Task 1) — mesmo padrão de
 * `RelatorioBonificacaoController::montarLinhas()`: lê `breakdown_json` do
 * fechamento mensal quando existe e tem a chave `componentes`, e só cai em
 * `computeCached()` ao vivo quando a competência não foi consolidada para
 * aquele profissional. Os 3 cenários de safra (congelada / sem snapshot /
 * snapshot truncado) estão na classe abaixo. `Http::fake()` continua no
 * `setUp()` para impedir rede real no caminho de fallback; esta suíte NÃO
 * usa `Http::assertNothingSent()` (companies de teste não têm
 * `adman_account_id`/`ml_store_id`, então `AdmanMetricDiffService::compute()`
 * já devolve `emptyMetrics()` sem HTTP — mas essa suíte não faz essa prova).
 */
class AuditoriaBonusNotaEmpresaTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);
    }

    #[Test]
    public function empresas_da_carteira_vem_com_nota_e_componentes(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Profissional Nota');

        $c1 = $this->darCarteira($prof);
        $c2 = $this->darCarteira($prof);

        $this->seedLinha($prof->id, $c1, '2026-06-01', ['nota_empresa' => 4.2, 'margem_pontos' => 4.0]);
        $this->seedLinha($prof->id, $c2, '2026-06-01', ['nota_empresa' => 3.8, 'margem_pontos' => 3.0]);

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('tem_detalhe_empresas', true)
                ->has('profissionais', 1)
                ->has('profissionais.0.empresas', 2)
                ->where('profissionais.0.empresas', function ($empresas) use ($c1, $c2) {
                    $porId = collect($empresas)->keyBy('company_id');

                    // Round-trip por JSON (AssertableInertia::fromTestResponse) faz
                    // 4.0 virar int(4) — cast explícito pra comparar pelo VALOR, não
                    // pelo tipo que o round-trip de teste perturba.
                    return (float) $porId->get($c1)['nota_empresa'] === 4.2
                        && (float) $porId->get($c1)['margem_pontos'] === 4.0
                        && (float) $porId->get($c2)['nota_empresa'] === 3.8;
                })
                ->has('profissionais.0.empresas.0.status')
                ->has('profissionais.0.empresas.0.nps_pontos')
                ->has('profissionais.0.empresas.0.faturamento_var_pct')
                ->has('profissionais.0.empresas.0.faturamento_pontos')
                ->has('profissionais.0.empresas.0.margem_pct_atual')
                ->has('profissionais.0.empresas.0.margem_pct_anterior')
                ->has('profissionais.0.empresas.0.margem_var_pp')
                ->has('profissionais.0.empresas.0.quality')
            );
    }

    #[Test]
    public function mesma_fonte_nunca_o_blob_json_do_snapshot_mensal(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Mesma Fonte');

        // breakdown_json['empresas_score'] = [] (default de seedMensal()) —
        // se o controller lesse o blob por engano, a lista viria vazia.
        $this->seedMensal($prof->id, '2026-06-01');

        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01', ['nota_empresa' => 4.5]);

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('profissionais.0.empresas', function ($empresas) use ($c1) {
                    $linha = collect($empresas)->firstWhere('company_id', $c1);

                    return $linha !== null && $linha['nota_empresa'] === 4.5;
                })
            );
    }

    #[Test]
    public function empresa_sem_linha_correspondente_vem_com_nota_nula(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Sem Linha');

        $comLinha = $this->darCarteira($prof);
        $semLinha = $this->darCarteira($prof);

        $this->seedLinha($prof->id, $comLinha, '2026-06-01', ['nota_empresa' => 4.1]);
        // $semLinha propositalmente sem seedLinha().

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->has('profissionais.0.empresas', 2)
                ->where('profissionais.0.empresas', function ($empresas) use ($comLinha, $semLinha) {
                    $porId = collect($empresas)->keyBy('company_id');

                    // Cast explícito — round-trip por JSON perturba float
                    // "redondo" (não é o caso aqui, mas mantém o padrão).
                    return (float) $porId->get($comLinha)['nota_empresa'] === 4.1
                        && $porId->get($semLinha)['nota_empresa'] === null
                        && array_key_exists('nota_empresa', $porId->get($semLinha));
                })
            );
    }

    #[Test]
    public function competencia_sem_nenhuma_linha_nao_quebra_e_avisa_no_topo(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Competencia Vazia');

        $c1 = $this->darCarteira($prof);
        $c2 = $this->darCarteira($prof);
        // Nenhum seedLinha() para 2026-05 — competência inteira sem detalhe.

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('tem_detalhe_empresas', false)
                ->has('profissionais', 1)
                ->has('profissionais.0.empresas', 2)
                ->where('profissionais.0.empresas', fn ($empresas) => collect($empresas)
                    ->every(fn (array $e) => $e['nota_empresa'] === null))
            );

        // Sanity: as empresas continuam existindo (não sumiram por não ter linha).
        $this->assertIsInt($c1);
        $this->assertIsInt($c2);
    }

    #[Test]
    public function empresa_shopee_traz_placeholder_e_nota_preenchida(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Shopee Auditoria');

        $c1 = $this->darCarteira($prof);
        $this->seedLinhaShopee($prof->id, $c1, '2026-06-01', ['nota_empresa' => 3.5]);

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('profissionais.0.empresas', function ($empresas) use ($c1) {
                    $linha = collect($empresas)->firstWhere('company_id', $c1);

                    return $linha !== null
                        && $linha['nota_empresa'] === 3.5
                        && ($linha['quality']['margin_source'] ?? null) === 'placeholder_shopee';
                })
            );
    }

    #[Test]
    public function profissional_com_snapshot_mensal_usa_nota_congelada_do_fechamento(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Profissional Congelado');
        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');

        // Valor fracionário distinguível — não coincide com o que
        // computeCached() produziria a partir desta fixture (sem
        // adman_metrics/nps seedados).
        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'   => 4.97,
                'score_status' => 'complete',
                'componentes'  => [
                    'nps_medio'           => 4.5,
                    'var_faturamento_pct' => 3.0,
                    'var_margem_pct'      => 1.0,
                    'var_margem_pp'       => 1.0,
                ],
                'empresas_score' => [],
            ],
        ]);

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('profissionais.0.nota_congelada', true)
                ->where('profissionais.0.nota_final', fn ($nota) => (float) $nota === 4.97)
            );
    }

    #[Test]
    public function profissional_sem_snapshot_mensal_cai_no_fallback_ao_vivo(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Profissional Sem Snapshot');
        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');
        // Sem seedMensal() — competência não foi consolidada para este
        // profissional; cai no fallback computeCached() ao vivo.

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('profissionais.0.nota_congelada', false)
            );
    }

    #[Test]
    public function snapshot_mensal_sem_chave_componentes_tambem_cai_no_fallback(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Profissional Snapshot Truncado');
        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');

        // Simula snapshot truncado / de safra anterior: sem 'componentes'.
        // Mesma guarda de RelatorioBonificacaoController::montarLinhas().
        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => [
                'nota_final'   => 4.97,
                'score_status' => 'complete',
            ],
        ]);

        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/Auditoria')
                ->where('profissionais.0.nota_congelada', false)
            );
    }

    #[Test]
    public function leitura_do_detalhe_por_empresa_e_uma_query_so_com_tres_profissionais(): void
    {
        $this->adminLogado();

        foreach (range(1, 3) as $i) {
            $prof = $this->criarUserElegivel("Profissional {$i}");
            $c = $this->darCarteira($prof);
            $this->seedLinha($prof->id, $c, '2026-06-01');
        }

        DB::enableQueryLog();
        $this->get(route('desempenho.auditoria-bonus') . '?mes=2026-06')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $queriesDaTabela = collect($queries)
            ->filter(fn (array $q) => str_contains($q['query'], 'desempenho_company_score_snapshots'))
            ->count();

        $this->assertSame(
            1,
            $queriesDaTabela,
            'A leitura de nota por empresa deveria ser 1 única query, independente do número de profissionais (paraUsuarios() fora do map()).'
        );
    }
}
