<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 01 — schema das duas tabelas de faixas de faturamento
 * (`servico_faixas_faturamento`, `empresa_faixas_faturamento`) e o seed
 * idempotente das três tabelas medidas nos modelos publicados na Clicksign
 * (D-02b).
 *
 * RefreshDatabase roda todas as migrations em SQLite (:memory:) no setUp,
 * incluindo o seed 100003 — mas naquele momento só o serviço "Gestão" existe
 * (semeado por `seed_servicos_catalog`, Fase 14). "Brigada" e "Gestão de ADS
 * Shopee" são particularidades desta fase (D-02b, catálogo medido em
 * produção) e não existem no seed automático — este teste as cria como
 * fixture e RE-INVOCA `up()` do seed (idempotente, mesmo padrão de
 * `SeedNpsShopeeTest`) antes de cada asserção.
 *
 * Conferência sempre por RECONSULTA ao banco via `DB::table`, nunca pelo
 * retorno do seed (.planning/learnings/desempenho-bonificacao.md §4).
 */
class Phase137FaixasSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // "Gestão" já existe via seed_servicos_catalog (Fase 14). "Brigada" e
        // "Gestão de ADS Shopee" são exclusivas do catálogo medido em
        // produção (137-CONTEXT.md interfaces) — criadas aqui como fixture.
        if (! Servico::where('nome', 'Brigada')->exists()) {
            Servico::create([
                'nome'          => 'Brigada',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
            ]);
        }

        if (! Servico::where('nome', 'Gestão de ADS Shopee')->exists()) {
            Servico::create([
                'nome'          => 'Gestão de ADS Shopee',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_SHOPEE,
            ]);
        }

        $this->rodarSeed();
    }

    /**
     * Invoca up() das duas migrations de seed manualmente (idempotente).
     * A 100000 (checkpoint 2026-09-03) ajusta as faixas de Shopee — precisa
     * rodar DEPOIS da 100003 porque atualiza linhas que ela cria.
     */
    private function rodarSeed(): void
    {
        $m = require database_path('migrations/2026_09_02_100003_seed_faixas_faturamento_iniciais.php');
        $m->up();

        $m2 = require database_path('migrations/2026_09_03_100000_ajustar_faixas_shopee.php');
        $m2->up();
    }

    private function servicoId(string $nome): int
    {
        return (int) Servico::where('nome', $nome)->value('id');
    }

    // ─── (a) as duas tabelas existem, com as colunas declaradas ─────────────

    #[Test]
    public function as_duas_tabelas_existem_com_as_colunas_declaradas(): void
    {
        $this->assertTrue(Schema::hasTable('servico_faixas_faturamento'));
        $this->assertTrue(Schema::hasTable('empresa_faixas_faturamento'));

        foreach (['id', 'servico_id', 'ordem', 'limite_superior', 'valor', 'valor_e_piso', 'created_at', 'updated_at'] as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('servico_faixas_faturamento', $coluna),
                "servico_faixas_faturamento deve ter a coluna {$coluna}."
            );
        }

        foreach (['id', 'company_id', 'ordem', 'limite_superior', 'valor', 'valor_e_piso', 'created_at', 'updated_at'] as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('empresa_faixas_faturamento', $coluna),
                "empresa_faixas_faturamento deve ter a coluna {$coluna}."
            );
        }
    }

    // ─── (b) 7 faixas de Gestão — ordem 1 = 3000,00; ordem 7 = piso ─────────

    #[Test]
    public function gestao_tem_7_faixas_com_a_ultima_como_piso(): void
    {
        $faixas = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $this->servicoId('Gestão'))
            ->orderBy('ordem')
            ->get();

        $this->assertCount(7, $faixas);

        $this->assertSame(1, (int) $faixas[0]->ordem);
        $this->assertEqualsWithDelta(3000.00, (float) $faixas[0]->valor, 0.001);
        $this->assertSame(0, (int) $faixas[0]->valor_e_piso);

        $ultima = $faixas[6];
        $this->assertSame(7, (int) $ultima->ordem);
        $this->assertNull($ultima->limite_superior, 'A última faixa de Gestão deve ser aberta (sem teto).');
        $this->assertEqualsWithDelta(12000.00, (float) $ultima->valor, 0.001);
        $this->assertSame(1, (int) $ultima->valor_e_piso, 'Ordem 7 de Gestão deve ser marcada como piso.');
    }

    // ─── (c) Brigada é IDÊNTICA a Gestão, linha a linha ─────────────────────

    #[Test]
    public function brigada_e_identica_a_gestao_linha_a_linha(): void
    {
        $faixasGestao = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $this->servicoId('Gestão'))
            ->orderBy('ordem')
            ->get(['ordem', 'limite_superior', 'valor', 'valor_e_piso']);

        $faixasBrigada = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $this->servicoId('Brigada'))
            ->orderBy('ordem')
            ->get(['ordem', 'limite_superior', 'valor', 'valor_e_piso']);

        $this->assertCount(7, $faixasBrigada);

        foreach ($faixasGestao->values() as $i => $faixaGestao) {
            $faixaBrigada = $faixasBrigada->values()[$i];

            $this->assertSame((int) $faixaGestao->ordem, (int) $faixaBrigada->ordem);

            $limiteGestao  = $faixaGestao->limite_superior === null ? null : (float) $faixaGestao->limite_superior;
            $limiteBrigada = $faixaBrigada->limite_superior === null ? null : (float) $faixaBrigada->limite_superior;
            $this->assertSame($limiteGestao, $limiteBrigada, "limite_superior da ordem {$faixaGestao->ordem} deve bater entre Gestão e Brigada.");

            $this->assertEqualsWithDelta((float) $faixaGestao->valor, (float) $faixaBrigada->valor, 0.001);
            $this->assertSame((int) $faixaGestao->valor_e_piso, (int) $faixaBrigada->valor_e_piso);
        }
    }

    // ─── (d) Shopee com 11 faixas, a última ABERTA (checkpoint 2026-09-03) ──

    #[Test]
    public function shopee_tem_11_faixas_com_a_ultima_aberta(): void
    {
        $faixas = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $this->servicoId('Gestão de ADS Shopee'))
            ->orderBy('ordem')
            ->get();

        $this->assertCount(11, $faixas);

        $this->assertSame(1, (int) $faixas[0]->ordem);
        $this->assertEqualsWithDelta(1500.00, (float) $faixas[0]->valor, 0.001);
        $this->assertEqualsWithDelta(49_999.99, (float) $faixas[0]->limite_superior, 0.001, 'Ordem 1 de Shopee usa a convenção ",99" desde o checkpoint 2026-09-03.');

        $ultima = $faixas[10];
        $this->assertSame(11, (int) $ultima->ordem);
        $this->assertNull($ultima->limite_superior, 'A última faixa de Shopee passou a ser aberta (checkpoint 2026-09-03) — antes o dado real não tinha faixa aberta.');
        $this->assertEqualsWithDelta(6500.00, (float) $ultima->valor, 0.001);
        $this->assertSame(1, (int) $ultima->valor_e_piso, 'Ordem 11 de Shopee deve ser marcada como piso.');

        foreach ($faixas as $faixa) {
            if ((int) $faixa->ordem === 11) {
                continue;
            }
            $this->assertNotNull($faixa->limite_superior, "Ordem {$faixa->ordem} de Shopee só a 11 deve ser aberta.");
            $this->assertSame(0, (int) $faixa->valor_e_piso, "Ordem {$faixa->ordem} de Shopee não deve ser marcada como piso.");
        }
    }

    // ─── (e) unique (servico_id, ordem) estoura QueryException ──────────────

    #[Test]
    public function duplicar_servico_id_e_ordem_estoura_erro(): void
    {
        $this->expectException(QueryException::class);

        DB::table('servico_faixas_faturamento')->insert([
            'servico_id'      => $this->servicoId('Gestão'),
            'ordem'           => 1,
            'limite_superior' => 499_999.99,
            'valor'           => 3_000.00,
            'valor_e_piso'    => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ─── (f) relações Company::faixasFaturamento / Servico::faixasFaturamento ─

    #[Test]
    public function relacoes_devolvem_as_linhas_criadas(): void
    {
        $servico = Servico::find($this->servicoId('Gestão'));

        $this->assertCount(7, $servico->faixasFaturamento);
        $this->assertInstanceOf(ServicoFaixaFaturamento::class, $servico->faixasFaturamento->first());

        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => 100_000.00,
            'valor'           => 2_000.00,
            'valor_e_piso'    => false,
        ]);

        $company->refresh();
        $this->assertCount(1, $company->faixasFaturamento);
        $this->assertInstanceOf(EmpresaFaixaFaturamento::class, $company->faixasFaturamento->first());
    }

    // ─── auditoria: criar e alterar uma faixa grava em activity_log ────────

    #[Test]
    public function criar_e_alterar_faixa_de_servico_grava_activity_log(): void
    {
        $servico = Servico::create([
            'nome'          => 'Serviço de teste auditoria',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $faixa = ServicoFaixaFaturamento::create([
            'servico_id'      => $servico->id,
            'ordem'           => 1,
            'limite_superior' => 100_000.00,
            'valor'           => 1_000.00,
            'valor_e_piso'    => false,
        ]);

        $faixa->update(['valor' => 1_200.00]);

        // Reconsulta direta à tabela — nunca confiar no retorno do save/update.
        $logs = DB::table('activity_log')
            ->where('log_name', 'faixa_faturamento')
            ->where('subject_type', ServicoFaixaFaturamento::class)
            ->where('subject_id', $faixa->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count(), 'Deve haver pelo menos 1 log de created e 1 de updated.');
        $this->assertContains('created', $logs->pluck('event')->all());
        $this->assertContains('updated', $logs->pluck('event')->all());
    }

    // ─── (g) rodar o seed duas vezes mantém as contagens em 7, 7 e 11 ───────

    #[Test]
    public function rodar_o_seed_duas_vezes_mantem_as_contagens(): void
    {
        $contagens = fn () => [
            'gestao'  => DB::table('servico_faixas_faturamento')->where('servico_id', $this->servicoId('Gestão'))->count(),
            'brigada' => DB::table('servico_faixas_faturamento')->where('servico_id', $this->servicoId('Brigada'))->count(),
            'shopee'  => DB::table('servico_faixas_faturamento')->where('servico_id', $this->servicoId('Gestão de ADS Shopee'))->count(),
            'total'   => DB::table('servico_faixas_faturamento')->count(),
        ];

        $antes = $contagens();

        $this->assertSame(7, $antes['gestao']);
        $this->assertSame(7, $antes['brigada']);
        $this->assertSame(11, $antes['shopee']);
        $this->assertSame(25, $antes['total']);

        $this->rodarSeed();

        $this->assertSame($antes, $contagens(), 'Rodar o seed duas vezes não pode alterar contagens.');
    }

    // ─── (h) checkpoint 2026-09-03: faixa aberta resolve empresa acima do antigo teto ─

    #[Test]
    public function shopee_acima_de_3_milhoes_resolve_para_a_faixa_aberta_em_vez_de_null(): void
    {
        $servicoId = $this->servicoId('Gestão de ADS Shopee');

        $faixas = ServicoFaixaFaturamento::where('servico_id', $servicoId)->ordenadas()->get();

        $resolver = app(\App\Services\Fechamento\FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(9_000_000.00, $faixas);

        $this->assertNotNull($classificacao, 'Desde o checkpoint 2026-09-03 a Shopee tem faixa aberta — não pode mais devolver null acima de R$ 3.000.000.');
        $this->assertSame(11, $classificacao['ordem']);
        $this->assertEqualsWithDelta(6500.00, $classificacao['valor'], 0.001);
        $this->assertNull($classificacao['limite_superior']);
        $this->assertTrue($classificacao['valor_e_piso']);

        // Reconsulta direta ao banco — a faixa 11 tem que existir e ser a única aberta.
        $faixaAberta = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $servicoId)
            ->whereNull('limite_superior')
            ->get();
        $this->assertCount(1, $faixaAberta, 'Só a ordem 11 pode ter limite_superior nulo.');
        $this->assertSame(11, (int) $faixaAberta->first()->ordem);
    }

    // ─── (i) checkpoint 2026-09-03: teto ",99" — R$ 50.000,00 exatos caem na faixa 2 ─

    #[Test]
    public function shopee_50_mil_exatos_cai_na_faixa_2_pela_convencao_99(): void
    {
        $servicoId = $this->servicoId('Gestão de ADS Shopee');

        // Reconsulta ao banco: a ordem 1 tem que ter virado 49.999,99.
        $ordem1 = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $servicoId)
            ->where('ordem', 1)
            ->first();
        $this->assertEqualsWithDelta(49_999.99, (float) $ordem1->limite_superior, 0.001, 'Ordem 1 de Shopee tem que estar na convenção ",99" desde o checkpoint 2026-09-03.');

        $faixas = ServicoFaixaFaturamento::where('servico_id', $servicoId)->ordenadas()->get();

        $resolver = app(\App\Services\Fechamento\FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(50_000.00, $faixas);

        $this->assertNotNull($classificacao);
        $this->assertSame(2, $classificacao['ordem'], 'R$ 50.000,00 exatos é MAIOR que o novo teto 49.999,99 da faixa 1 — cai na faixa 2, mudança de cobrança consciente.');
        $this->assertEqualsWithDelta(2000.00, $classificacao['valor'], 0.001);
    }
}
