<?php

namespace Tests\Feature\Quick260916;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Observers\CompanyGatilhoContratoObserver;
use App\Services\Fechamento\FechamentoEmpresasDoMes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260916-onn — "não participa do fechamento": a REGRA
 * (`FechamentoEmpresasDoMes::separar()`) e o comando que grava.
 *
 * Toda asserção de gravação é por RECONSULTA às tabelas de snapshot — nunca
 * pela saída de texto do comando. A saída só é conferida no teste que trava o
 * próprio resumo.
 */
class ForaDoFechamentoRegraTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresa(Servico $servico, string $nome, string $inicio = '2026-01-10', float $faturamento = 100_000.00, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'name'             => $nome,
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'data_contratacao' => $inicio,
            'valor_contratado' => 0,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => $faturamento,
        ]);

        return $company;
    }

    /** Tabela de faixa única — o caso Wenus (valor fixo de R$ 4.000). */
    private function criarTabelaFixa(CompanyGroup $grupo): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1,
            'limite_superior'  => null, 'valor' => 4_000.00, 'valor_e_piso' => true,
        ]);
    }

    private function marcar(Company|CompanyGroup $alvo, string $motivo): void
    {
        $alvo->update([
            'fora_do_fechamento'        => true,
            'fora_do_fechamento_motivo' => $motivo,
            'fora_do_fechamento_em'     => now(),
        ]);
    }

    private function consolidar(string $mes, array $extra = []): string
    {
        $exit  = Artisan::call('fechamento:consolidar-mes', array_merge(['--mes' => $mes], $extra));
        $saida = Artisan::output();

        $this->assertSame(0, $exit, "fechamento:consolidar-mes deveria sair com 0. Saída:\n{$saida}");

        return $saida;
    }

    private function idsGravados(string $mes): array
    {
        return DB::table('fechamento_snapshots')
            ->whereDate('mes_referencia', $mes.'-01')
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    // ─── Migration ────────────────────────────────────────────────────────

    #[Test]
    public function a_migration_cria_as_colunas_nas_duas_tabelas_com_default_falso(): void
    {
        foreach (['companies', 'company_groups'] as $tabela) {
            $this->assertTrue(
                Schema::hasColumns($tabela, ['fora_do_fechamento', 'fora_do_fechamento_motivo', 'fora_do_fechamento_por', 'fora_do_fechamento_em']),
                "Colunas ausentes em {$tabela}"
            );
        }

        // Regressão zero: ninguém nasce marcado.
        $empresa = Company::factory()->create();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Novo', 'color' => '#000']);

        $this->assertFalse($empresa->refresh()->fora_do_fechamento);
        $this->assertFalse($grupo->refresh()->fora_do_fechamento);
    }

    #[Test]
    public function a_marcacao_nao_e_campo_gatilho_de_contrato(): void
    {
        $this->assertNotContains('fora_do_fechamento', CompanyGatilhoContratoObserver::CAMPOS_GATILHO);
        $this->assertNotContains('fora_do_fechamento_motivo', CompanyGatilhoContratoObserver::CAMPOS_GATILHO);
    }

    // ─── Regra ────────────────────────────────────────────────────────────

    #[Test]
    public function separar_tira_empresa_marcada_grupo_marcado_e_grupo_de_cobranca_marcado(): void
    {
        $gestao = $this->criarServicoGestao();

        $cobranca = CompanyGroup::create(['name' => 'Cobrança Mãe', 'color' => '#000']);
        $sub      = CompanyGroup::create(['name' => 'Subgrupo', 'color' => '#000', 'parent_id' => $cobranca->id]);
        $wenus    = CompanyGroup::create(['name' => 'Wenus', 'color' => '#000']);
        $livre    = CompanyGroup::create(['name' => 'Grupo Normal', 'color' => '#000']);

        $normal    = $this->criarEmpresa($gestao, 'Normal');
        $soldera   = $this->criarEmpresa($gestao, 'Rações Soldera');
        $wenusA    = $this->criarEmpresa($gestao, 'Wenus A', overrides: ['company_group_id' => $wenus->id]);
        $wenusB    = $this->criarEmpresa($gestao, 'Wenus B', overrides: ['company_group_id' => $wenus->id]);
        $doSub     = $this->criarEmpresa($gestao, 'Do Subgrupo', overrides: ['company_group_id' => $sub->id]);
        $doLivre   = $this->criarEmpresa($gestao, 'Do Grupo Normal', overrides: ['company_group_id' => $livre->id]);

        $this->marcar($soldera, 'Contrato de Brigada sem tabela progressiva');
        $this->marcar($wenus, 'Valor fixo de R$ 4.000 por mês');
        $this->marcar($cobranca, 'Cliente com valor fixo no grupo de cobrança');

        $r = app(FechamentoEmpresasDoMes::class)->separar(
            Company::where('active', true)->orderBy('id')->get(),
            '2026-08'
        );

        $this->assertSame([$normal->id, $doLivre->id], $r['entram']->pluck('id')->all(), 'Só quem não foi marcado entra.');
        $this->assertTrue($r['fora']->isEmpty(), 'Ninguém saiu pela data.');

        $porId = $r['fora_por_decisao']->keyBy(fn ($i) => $i['company']->id);
        $this->assertSame(['empresa', null, 'Contrato de Brigada sem tabela progressiva'], [$porId[$soldera->id]['origem'], $porId[$soldera->id]['grupo_id'], $porId[$soldera->id]['motivo']]);
        $this->assertSame(['grupo', $wenus->id], [$porId[$wenusA->id]['origem'], $porId[$wenusA->id]['grupo_id']]);
        $this->assertSame(['grupo', $wenus->id], [$porId[$wenusB->id]['origem'], $porId[$wenusB->id]['grupo_id']]);
        $this->assertSame(['grupo', $cobranca->id, 'Cobrança Mãe'], [$porId[$doSub->id]['origem'], $porId[$doSub->id]['grupo_id'], $porId[$doSub->id]['grupo_nome']], 'Grupo de cobrança marcado tira o subgrupo.');
    }

    #[Test]
    public function empresa_marcada_e_com_inicio_depois_do_mes_aparece_uma_vez_so_por_decisao(): void
    {
        $gestao  = $this->criarServicoGestao();
        $empresa = $this->criarEmpresa($gestao, 'Marcada E Nova', '2026-09-01');
        $this->marcar($empresa, 'Não tem contrato progressivo');

        $r = app(FechamentoEmpresasDoMes::class)->separar(Company::all(), '2026-08');

        $this->assertTrue($r['entram']->isEmpty());
        $this->assertTrue($r['fora']->isEmpty(), 'Não aparece duas vezes.');
        $this->assertTrue($r['pendentes']->isEmpty());
        $this->assertSame([$empresa->id], $r['fora_por_decisao']->map(fn ($i) => $i['company']->id)->all());
    }

    #[Test]
    public function quem_esta_gravado_em_mes_fechado_continua_ate_ser_refeito(): void
    {
        $gestao  = $this->criarServicoGestao();
        $empresa = $this->criarEmpresa($gestao, 'Gravada');
        $this->marcar($empresa, 'Marcada depois do fechamento');

        $r = app(FechamentoEmpresasDoMes::class)->separar(Company::all(), '2026-08', [$empresa->id]);

        $this->assertSame([$empresa->id], $r['entram']->pluck('id')->all());
        $this->assertTrue($r['fora_por_decisao']->isEmpty());
    }

    #[Test]
    public function sem_marcacao_nenhuma_nada_muda(): void
    {
        $gestao = $this->criarServicoGestao();
        $a      = $this->criarEmpresa($gestao, 'A');
        $b      = $this->criarEmpresa($gestao, 'B', '2026-09-01');

        $r = app(FechamentoEmpresasDoMes::class)->separar(Company::orderBy('id')->get(), '2026-08');

        $this->assertSame([$a->id], $r['entram']->pluck('id')->all());
        $this->assertSame([$b->id], $r['fora']->pluck('id')->all());
        $this->assertTrue($r['fora_por_decisao']->isEmpty());
    }

    // ─── Comando ──────────────────────────────────────────────────────────

    #[Test]
    public function o_comando_tira_quem_foi_marcado_e_mantem_os_demais(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        $normal  = $this->criarEmpresa($gestao, 'Cliente Normal');
        $soldera = $this->criarEmpresa($gestao, 'Rações Soldera');
        $this->marcar($soldera, 'Contrato de Brigada sem tabela progressiva');

        $this->consolidar('2026-08');

        $ids = $this->idsGravados('2026-08');
        $this->assertContains($normal->id, $ids, 'Empresa não marcada entra como hoje.');
        $this->assertNotContains($soldera->id, $ids, 'Empresa marcada não participa.');
    }

    #[Test]
    public function grupo_marcado_some_do_fechamento_ao_refazer_e_a_tabela_continua_gravada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        $wenus = CompanyGroup::create(['name' => 'Wenus', 'color' => '#000']);
        $this->criarTabelaFixa($wenus);
        $a = $this->criarEmpresa($gestao, 'Wenus A', overrides: ['company_group_id' => $wenus->id]);
        $b = $this->criarEmpresa($gestao, 'Wenus B', overrides: ['company_group_id' => $wenus->id]);

        // Subgrupo pendurado em outro grupo de cobrança, que será marcado.
        $mae  = CompanyGroup::create(['name' => 'Mãe', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'Filho', 'color' => '#000', 'parent_id' => $mae->id]);
        $filh = $this->criarEmpresa($gestao, 'Empresa Do Filho', overrides: ['company_group_id' => $sub->id]);

        $fica = $this->criarEmpresa($gestao, 'Fica');

        $this->consolidar('2026-08');
        $this->assertSame(1, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $wenus->id)->count());
        $this->assertSame(1, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $mae->id)->count());

        $this->marcar($wenus, 'Valor fixo de R$ 4.000 por mês');
        $this->marcar($mae, 'Valor fixo combinado com o cliente');

        $this->consolidar('2026-08', ['--motivo' => 'teste: grupos marcados como não participantes']);

        $ids = $this->idsGravados('2026-08');
        $this->assertSame([$fica->id], $ids);
        $this->assertNotContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
        $this->assertNotContains($filh->id, $ids, 'Grupo de cobrança marcado tira o subgrupo também.');
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $wenus->id)->count(), 'A linha do grupo some ao refazer.');
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $mae->id)->count());

        // A tabela de R$ 4.000 continua gravada.
        $this->assertSame(1, GrupoFaixaFaturamento::where('company_group_id', $wenus->id)->count());
        $this->assertEqualsWithDelta(4_000.00, (float) GrupoFaixaFaturamento::where('company_group_id', $wenus->id)->value('valor'), 0.01);
    }

    #[Test]
    public function desmarcar_faz_voltar_a_entrar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao  = $this->criarServicoGestao();
        $empresa = $this->criarEmpresa($gestao, 'Vai E Volta');
        $this->marcar($empresa, 'Marcada por engano no teste');

        $this->consolidar('2026-08');
        $this->assertNotContains($empresa->id, $this->idsGravados('2026-08'));

        $empresa->update(['fora_do_fechamento' => false, 'fora_do_fechamento_motivo' => null]);

        $this->consolidar('2026-08', ['--motivo' => 'teste: desmarcada']);
        $this->assertContains($empresa->id, $this->idsGravados('2026-08'));
    }

    #[Test]
    public function o_resumo_separa_quem_saiu_por_decisao_de_quem_saiu_pela_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        $this->criarEmpresa($gestao, 'Cliente Antigo');
        $this->criarEmpresa($gestao, 'Nova Em Setembro', '2026-09-01');
        $soldera = $this->criarEmpresa($gestao, 'Rações Soldera');
        $this->marcar($soldera, 'Contrato de Brigada sem tabela progressiva');

        $wenus = CompanyGroup::create(['name' => 'Wenus', 'color' => '#000']);
        $this->criarEmpresa($gestao, 'Wenus Loja', overrides: ['company_group_id' => $wenus->id]);
        $this->marcar($wenus, 'Valor fixo de R$ 4.000 por mês');

        $saida = $this->consolidar('2026-08');

        $this->assertStringContainsString('1 empresa(s) de fora por começarem depois', $saida, 'A linha da data conta só quem saiu pela data.');
        $this->assertStringContainsString('Nova Em Setembro', $saida);
        $this->assertStringContainsString('Fora do fechamento por decisão: 2 empresa(s)', $saida);
        $this->assertStringContainsString('Rações Soldera (#'.$soldera->id.', Contrato de Brigada sem tabela progressiva)', $saida);
        $this->assertStringContainsString('grupo Wenus: Valor fixo de R$ 4.000 por mês', $saida);
    }

    #[Test]
    public function sem_ninguem_marcado_o_resumo_diz_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->criarEmpresa($this->criarServicoGestao(), 'Cliente Antigo');

        $saida = $this->consolidar('2026-08');

        $this->assertStringContainsString('Fora do fechamento por decisão: 0 empresa(s).', $saida);
    }
}
