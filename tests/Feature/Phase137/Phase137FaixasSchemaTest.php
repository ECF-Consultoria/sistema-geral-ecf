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

    /** Invoca up() da migration de seed manualmente (idempotente). */
    private function rodarSeed(): void
    {
        $m = require database_path('migrations/2026_09_02_100003_seed_faixas_faturamento_iniciais.php');
        $m->up();
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

    // ─── (d) Shopee com 8 faixas, todas FECHADAS — sem faixa aberta ─────────

    #[Test]
    public function shopee_tem_8_faixas_todas_fechadas(): void
    {
        $faixas = DB::table('servico_faixas_faturamento')
            ->where('servico_id', $this->servicoId('Gestão de ADS Shopee'))
            ->orderBy('ordem')
            ->get();

        $this->assertCount(8, $faixas);

        $this->assertSame(1, (int) $faixas[0]->ordem);
        $this->assertEqualsWithDelta(1500.00, (float) $faixas[0]->valor, 0.001);

        $ultima = $faixas[7];
        $this->assertSame(8, (int) $ultima->ordem);
        $this->assertEqualsWithDelta(5000.00, (float) $ultima->valor, 0.001);

        foreach ($faixas as $faixa) {
            $this->assertNotNull($faixa->limite_superior, 'Nenhuma faixa de Shopee deve ter limite_superior nulo — o dado real não tem faixa aberta.');
            $this->assertSame(0, (int) $faixa->valor_e_piso, 'Nenhuma faixa de Shopee deve ser marcada como piso.');
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

    // ─── (g) rodar o seed duas vezes mantém as contagens em 7, 7 e 8 ────────

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
        $this->assertSame(8, $antes['shopee']);
        $this->assertSame(22, $antes['total']);

        $this->rodarSeed();

        $this->assertSame($antes, $contagens(), 'Rodar o seed duas vezes não pode alterar contagens.');
    }
}
