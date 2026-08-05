<?php

namespace Tests\Feature\Phase123;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prova de UIEM-04/D-08 (123-05-PLAN.md Task 1) — o Relatório de Bonificação
 * passa a entregar, por profissional contemplado, o detalhe por empresa lido
 * da MESMA fonte que a tela individual (`CompanyScoreSnapshotReader`), numa
 * única query fora do `map()` de profissionais. E prova de D-09: o `pdf()` e
 * a blade do relatório continuam intocados — o documento que circula para
 * gestão/RH continua resumo, uma linha por profissional.
 */
class RelatorioBonificacaoEmpresasTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);
    }

    /** breakdown_json de um profissional que atingiu o bônus na competência. */
    private function breakdownContemplado(float $nota, string $faixa): array
    {
        return [
            'nota_final'  => $nota,
            'faixa_bonus' => $faixa,
            'componentes' => [
                'nps_medio'           => 4.0,
                'var_faturamento_pct' => 3.0,
                'var_margem_pct'      => 2.0,
            ],
        ];
    }

    #[Test]
    public function profissional_contemplado_recebe_detalhe_por_empresa_da_tabela_dedicada(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Profissional Contemplado');

        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => $this->breakdownContemplado(4.5, 'maximo'),
        ]);

        $c1 = $this->darCarteira($prof);
        $c2 = $this->darCarteira($prof);
        $c3 = $this->darCarteira($prof);

        $this->seedLinha($prof->id, $c1, '2026-06-01', ['status' => 'complete']);
        $this->seedLinha($prof->id, $c2, '2026-06-01', ['status' => 'complete']);
        $this->seedLinha($prof->id, $c3, '2026-06-01', ['status' => 'partial', 'nota_empresa' => null]);

        $this->get(route('desempenho.relatorio-bonificacao') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/RelatorioBonificacao')
                ->has('profissionais', 1)
                ->has('profissionais.0.empresas_score', 3)
                ->where('profissionais.0.tem_detalhe_empresas', true)
                ->where('profissionais.0.empresas_score_resumo.entraram', 2)
                ->where('profissionais.0.empresas_score_resumo.nao_entraram', 1)
            );
    }

    #[Test]
    public function mesma_fonte_nunca_o_blob_json_do_snapshot_mensal(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Mesma Fonte Relatorio');

        // breakdown_json['empresas_score'] = [] DE PROPÓSITO — se o
        // controller lesse o blob por engano, a lista viria vazia mesmo
        // com 3 linhas gravadas na tabela dedicada.
        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => array_merge(
                $this->breakdownContemplado(4.2, 'intermediario'),
                ['empresas_score' => []],
            ),
        ]);

        $c1 = $this->darCarteira($prof);
        $c2 = $this->darCarteira($prof);
        $c3 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');
        $this->seedLinha($prof->id, $c2, '2026-06-01');
        $this->seedLinha($prof->id, $c3, '2026-06-01');

        $this->get(route('desempenho.relatorio-bonificacao') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/RelatorioBonificacao')
                ->has('profissionais.0.empresas_score', 3)
            );
    }

    #[Test]
    public function profissional_contemplado_sem_detalhe_gravado_devolve_vazio_e_avisa(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Sem Detalhe Relatorio');

        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => $this->breakdownContemplado(4.1, 'basico'),
        ]);
        // Sem nenhum seedLinha() para este profissional — competência sem
        // detalhe por empresa gravado (D-03).

        $this->get(route('desempenho.relatorio-bonificacao') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/RelatorioBonificacao')
                ->where('profissionais.0.empresas_score', [])
                ->where('profissionais.0.tem_detalhe_empresas', false)
                ->where('profissionais.0.empresas_score_resumo.entraram', 0)
            );
    }

    #[Test]
    public function pdf_continua_resumo_sem_empresas_score_d09(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('PDF Resumo');

        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => $this->breakdownContemplado(4.3, 'intermediario'),
        ]);
        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');

        $response = $this->get(route('desempenho.relatorio-bonificacao.pdf') . '?mes=2026-06');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));

        // O documento que circula para gestão/RH continua resumo — nunca
        // pode citar o detalhe por empresa desta fase.
        $blade = file_get_contents(resource_path('views/pdf/relatorio-bonificacao.blade.php'));
        $this->assertStringNotContainsString('empresas_score', $blade);
        $this->assertStringNotContainsString('nota_empresa', $blade);
    }

    #[Test]
    public function leitura_do_detalhe_por_empresa_e_uma_query_so_com_tres_profissionais(): void
    {
        $this->adminLogado();

        foreach (range(1, 3) as $i) {
            $prof = $this->criarUserElegivel("Profissional Relatorio {$i}");
            $this->seedMensal($prof->id, '2026-06-01', [
                'breakdown_json' => $this->breakdownContemplado(4.1, 'basico'),
            ]);
            $c = $this->darCarteira($prof);
            $this->seedLinha($prof->id, $c, '2026-06-01');
        }

        DB::enableQueryLog();
        $this->get(route('desempenho.relatorio-bonificacao') . '?mes=2026-06')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $queriesDaTabela = collect($queries)
            ->filter(fn (array $q) => str_contains($q['query'], 'desempenho_company_score_snapshots'))
            ->count();

        $this->assertSame(
            1,
            $queriesDaTabela,
            'A leitura de detalhe por empresa deveria ser 1 única query, independente do número de profissionais (paraUsuarios() fora do map()).'
        );
    }

    #[Test]
    public function nao_contemplado_nao_aparece_mesmo_com_detalhe_gravado(): void
    {
        $this->adminLogado();
        $prof = $this->criarUserElegivel('Sem Bonus Relatorio');

        $this->seedMensal($prof->id, '2026-06-01', [
            'breakdown_json' => $this->breakdownContemplado(3.2, 'sem_bonus'),
        ]);
        $c1 = $this->darCarteira($prof);
        $this->seedLinha($prof->id, $c1, '2026-06-01');

        $this->get(route('desempenho.relatorio-bonificacao') . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Desempenho/RelatorioBonificacao')
                ->has('profissionais', 0)
            );
    }
}
