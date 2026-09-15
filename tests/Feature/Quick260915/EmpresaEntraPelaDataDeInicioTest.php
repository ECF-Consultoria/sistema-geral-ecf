<?php

namespace Tests\Feature\Quick260915;

use App\Mail\RelatorioFechamentoMail;
use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoEmpresasDoMes;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260915-jpr — a empresa só entra no fechamento de um mês se o contrato
 * já tinha começado (`contratos_servico.data_contratacao`), NUNCA pela data de
 * cadastro. Na dúvida (contrato sem data), entra como pendência.
 *
 * Toda asserção de gravação é por RECONSULTA às tabelas de snapshot — nunca
 * pela saída de texto do comando (.planning/learnings/desempenho-bonificacao.md §4).
 * A saída só é conferida no teste que trava o próprio resumo.
 *
 * ⚠️ `contratos_servico.data_contratacao` é NOT NULL no schema — os casos
 * "sem data" da regra pura são montados em memória; os de tela/comando
 * substituem a situação no container.
 */
class EmpresaEntraPelaDataDeInicioTest extends TestCase
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

    /** Empresa com integração Adman, contrato ativo com a data de início dada e faturamento de ago/2026. */
    private function criarEmpresa(Servico $servico, string $nome, string $inicio, float $faturamento = 100_000.00, array $overrides = []): Company
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

    /** Até R$ 500 mil cobra R$ 3.000; acima, R$ 4.500 (piso). */
    private function criarTabelaDoGrupo(CompanyGroup $grupo): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1,
            'limite_superior'  => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 4_500.00, 'valor_e_piso' => true,
        ]);
    }

    /** Company em memória, com contratos em memória (permite data nula). */
    private function empresaEmMemoria(array $contratos): Company
    {
        $company     = new Company();
        $company->id = random_int(1, 1_000_000);

        $company->setRelation('contratosServico', collect(array_map(
            fn (array $c) => new ContratoServico($c),
            $contratos
        )));

        return $company;
    }

    /** Substitui a regra no container: as empresas dadas ficam PENDENTES (sem data), o resto segue a regra real. */
    private function forcarPendentes(array $ids): void
    {
        $this->app->instance(FechamentoEmpresasDoMes::class, new class($ids) extends FechamentoEmpresasDoMes
        {
            public function __construct(private array $idsPendentes) {}

            public function situacao(Company $company, CarbonInterface $fim): string
            {
                return in_array($company->id, $this->idsPendentes, true)
                    ? self::PENDENTE
                    : parent::situacao($company, $fim);
            }
        });
    }

    private function consolidar(string $mes, array $extra = []): string
    {
        $exit = Artisan::call('fechamento:consolidar-mes', array_merge(['--mes' => $mes], $extra));
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

    // ─── Regra pura ───────────────────────────────────────────────────────

    #[Test]
    public function a_regra_da_tabela_do_plano(): void
    {
        $regra = new FechamentoEmpresasDoMes();
        $fim   = FechamentoEmpresasDoMes::fimDoMes('2026-07');

        $this->assertSame('2026-07-31', $fim->toDateString());

        $casos = [
            'início antes do mês'                  => [[['ativo' => true, 'data_contratacao' => '2026-03-01']], FechamentoEmpresasDoMes::ENTRA],
            'início no meio do mês'                => [[['ativo' => true, 'data_contratacao' => '2026-07-20']], FechamentoEmpresasDoMes::ENTRA],
            'início no último dia do mês'          => [[['ativo' => true, 'data_contratacao' => '2026-07-31']], FechamentoEmpresasDoMes::ENTRA],
            'início depois do mês'                 => [[['ativo' => true, 'data_contratacao' => '2026-08-01']], FechamentoEmpresasDoMes::FORA],
            'sem data em nenhum contrato'          => [[['ativo' => true, 'data_contratacao' => null]], FechamentoEmpresasDoMes::PENDENTE],
            'uma data depois + um sem data'        => [[['ativo' => true, 'data_contratacao' => '2026-09-01'], ['ativo' => true, 'data_contratacao' => null]], FechamentoEmpresasDoMes::PENDENTE],
            'uma antes e uma depois'               => [[['ativo' => true, 'data_contratacao' => '2026-09-01'], ['ativo' => true, 'data_contratacao' => '2026-01-01']], FechamentoEmpresasDoMes::ENTRA],
            'uma antes + um sem data'              => [[['ativo' => true, 'data_contratacao' => null], ['ativo' => true, 'data_contratacao' => '2026-01-01']], FechamentoEmpresasDoMes::ENTRA],
            'sem contrato'                         => [[], FechamentoEmpresasDoMes::ENTRA],
            'só contrato inativo, com data depois' => [[['ativo' => false, 'data_contratacao' => '2026-09-01']], FechamentoEmpresasDoMes::ENTRA],
            'data zerada do MySQL'                 => [[['ativo' => true, 'data_contratacao' => '0000-00-00']], FechamentoEmpresasDoMes::PENDENTE],
        ];

        foreach ($casos as $descricao => [$contratos, $esperado]) {
            $this->assertSame($esperado, $regra->situacao($this->empresaEmMemoria($contratos), $fim), "Caso: {$descricao}");
        }
    }

    #[Test]
    public function mes_corrente_usa_o_ultimo_dia_do_mes_e_nao_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $regra   = new FechamentoEmpresasDoMes();
        $empresa = $this->empresaEmMemoria([['ativo' => true, 'data_contratacao' => '2026-09-28']]);

        $this->assertSame(
            FechamentoEmpresasDoMes::ENTRA,
            $regra->situacao($empresa, FechamentoEmpresasDoMes::fimDoMes('2026-09')),
            'Contrato que começa no fim deste mês já entra neste mês.'
        );
    }

    #[Test]
    public function separar_devolve_pendentes_dentro_da_lista_e_respeita_quem_esta_sempre_dentro(): void
    {
        $regra = new FechamentoEmpresasDoMes();

        $antiga   = $this->empresaEmMemoria([['ativo' => true, 'data_contratacao' => '2026-01-01']]);
        $nova     = $this->empresaEmMemoria([['ativo' => true, 'data_contratacao' => '2026-09-01']]);
        $semData  = $this->empresaEmMemoria([['ativo' => true, 'data_contratacao' => null]]);
        $gravada  = $this->empresaEmMemoria([['ativo' => true, 'data_contratacao' => '2026-09-01']]);

        $r = $regra->separar(collect([$antiga, $nova, $semData, $gravada]), '2026-08', [$gravada->id]);

        $this->assertSame([$antiga->id, $semData->id, $gravada->id], $r['entram']->pluck('id')->all(), 'A ordem de entrada é preservada.');
        $this->assertSame([$semData->id], $r['pendentes']->pluck('id')->all());
        $this->assertSame([$nova->id], $r['fora']->pluck('id')->all());
    }

    // ─── Comando ──────────────────────────────────────────────────────────

    #[Test]
    public function consolidar_tira_quem_so_comecou_depois_do_mes_e_nunca_olha_a_data_de_cadastro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        $antes      = $this->criarEmpresa($gestao, 'Cliente Antigo', '2026-01-10');
        $meio       = $this->criarEmpresa($gestao, 'Começou Dia 20', '2026-08-20');
        $depois     = $this->criarEmpresa($gestao, 'Só Virou Cliente Em Setembro', '2026-09-01');
        $cadastro   = $this->criarEmpresa($gestao, 'Cadastrada Ontem Cliente Antigo', '2025-05-01', overrides: ['created_at' => '2026-09-01 10:00:00']);

        // Sem contrato ativo: entra como sempre entrou (regressão zero).
        $semContrato = Company::factory()->create(['name' => 'Sem Contrato Ativo', 'adman_account_id' => 'cust-'.uniqid()]);
        ContratoServico::factory()->paraServico($gestao)->create([
            'company_id' => $semContrato->id, 'ativo' => false, 'data_contratacao' => '2026-09-01',
        ]);

        $this->consolidar('2026-08');

        $ids = $this->idsGravados('2026-08');

        $this->assertContains($antes->id, $ids);
        $this->assertContains($meio->id, $ids, 'Começo no meio do mês entra — o fechamento cobra o mês.');
        $this->assertNotContains($depois->id, $ids, 'Todos os contratos começam depois do mês: sai.');
        $this->assertContains($cadastro->id, $ids, 'Data de cadastro recente com contrato antigo entra — created_at não pode influenciar.');
        $this->assertContains($semContrato->id, $ids, 'Sem contrato ativo: como hoje.');
    }

    #[Test]
    public function refazer_poda_a_linha_de_quem_deixou_de_entrar_e_reconta_os_grupos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        // Grupo que perde UMA empresa: soma, contagem e faixa mudam.
        $grupo = CompanyGroup::create(['name' => 'Grupo Que Encolhe', 'color' => '#000']);
        $this->criarTabelaDoGrupo($grupo);
        $fica = $this->criarEmpresa($gestao, 'Membro Que Fica', '2026-01-01', 300_000.00, ['company_group_id' => $grupo->id]);
        $sai  = $this->criarEmpresa($gestao, 'Membro Que Sai', '2026-07-01', 300_000.00, ['company_group_id' => $grupo->id]);

        // Grupo que perde TODAS as empresas: some.
        $grupoSome = CompanyGroup::create(['name' => 'Grupo Que Some', 'color' => '#000']);
        $unica     = $this->criarEmpresa($gestao, 'Única Do Grupo', '2026-07-01', 50_000.00, ['company_group_id' => $grupoSome->id]);

        $this->consolidar('2026-08');

        $linhaAntes = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();
        $this->assertSame(2, (int) $linhaAntes->empresas_count);
        $this->assertEqualsWithDelta(600_000.00, (float) $linhaAntes->faturamento_total, 0.01);
        $this->assertSame(2, (int) $linhaAntes->faixa_ordem);
        $this->assertSame(1, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupoSome->id)->count());

        // O usuário corrige a data de início na tela: as duas só começaram em setembro.
        ContratoServico::where('company_id', $sai->id)->update(['data_contratacao' => '2026-09-01']);
        ContratoServico::where('company_id', $unica->id)->update(['data_contratacao' => '2026-09-05']);

        $this->consolidar('2026-08', ['--motivo' => 'teste: data de início corrigida']);

        $ids = $this->idsGravados('2026-08');
        $this->assertContains($fica->id, $ids);
        $this->assertNotContains($sai->id, $ids, 'A linha de quem deixou de entrar some ao refazer.');
        $this->assertNotContains($unica->id, $ids);

        $linhaDepois = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();
        $this->assertNotNull($linhaDepois);
        $this->assertSame(1, (int) $linhaDepois->empresas_count, 'Contagem recalculada.');
        $this->assertEqualsWithDelta(300_000.00, (float) $linhaDepois->faturamento_total, 0.01, 'Soma recalculada.');
        $this->assertSame(1, (int) $linhaDepois->faixa_ordem, 'Faixa recalculada: 300 mil cai na faixa 1.');
        $this->assertEqualsWithDelta(3_000.00, (float) $linhaDepois->valor_faixa, 0.01);

        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupoSome->id)->count(), 'Grupo que perde todas as empresas some.');
    }

    #[Test]
    public function o_resumo_do_comando_diz_quem_saiu_e_quantas_entraram_sem_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $gestao = $this->criarServicoGestao();

        $this->criarEmpresa($gestao, 'Cliente Antigo', '2026-01-10');
        $this->criarEmpresa($gestao, 'Nova Em Setembro', '2026-09-01');
        $pendente = $this->criarEmpresa($gestao, 'Contrato Sem Data', '2026-09-03');

        $this->forcarPendentes([$pendente->id]);

        $saida = $this->consolidar('2026-08');

        $this->assertStringContainsString('1 empresa(s) de fora', $saida);
        $this->assertStringContainsString('Nova Em Setembro', $saida, 'Nada some em silêncio: o nome de quem saiu vai no resumo.');
        $this->assertStringContainsString('1 empresa(s) entraram sem data de início', $saida);
        $this->assertStringContainsString('Contrato Sem Data', $saida);

        $this->assertContains($pendente->id, $this->idsGravados('2026-08'), 'Pendência entra no fechamento.');
    }

    // ─── Tela ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_tela_lista_as_empresas_sem_data_de_inicio_com_link_para_a_ficha(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $admin  = User::factory()->create(['role' => 'admin']);
        $gestao = $this->criarServicoGestao();

        $normal   = $this->criarEmpresa($gestao, 'Cliente Normal', '2026-01-10');
        $pendente = $this->criarEmpresa($gestao, 'Contrato Sem Data', '2026-12-01');
        $futura   = $this->criarEmpresa($gestao, 'Começa Em Outubro', '2026-10-01');

        $this->forcarPendentes([$pendente->id]);

        $props = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08')
            ->assertOk()
            ->viewData('page')['props'];

        $idsNaTela = collect($props['companies'])->pluck('id')->all();
        $this->assertContains($normal->id, $idsNaTela);
        $this->assertContains($pendente->id, $idsNaTela, 'Pendência entra na tela.');
        $this->assertNotContains($futura->id, $idsNaTela);

        $this->assertSame([[
            'id'   => $pendente->id,
            'name' => 'Contrato Sem Data',
            'url'  => '/administrativo/contratos/empresa/'.$pendente->id,
        ]], $props['empresas_sem_data_inicio']);

        // A pendência NÃO vira chave nova nas linhas.
        foreach ($props['companies'] as $linha) {
            $this->assertArrayNotHasKey('empresas_sem_data_inicio', $linha);
            $this->assertArrayNotHasKey('sem_data_inicio', $linha);
        }
    }

    #[Test]
    public function mes_fechado_nao_muda_sozinho_e_nao_ganha_quem_so_chegou_depois(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $admin  = User::factory()->create(['role' => 'admin']);
        $gestao = $this->criarServicoGestao();

        // Gravada no fechamento com uma data que, depois, é corrigida para setembro.
        $gravada = $this->criarEmpresa($gestao, 'Gravada Antes Da Correção', '2026-07-01');
        $this->consolidar('2026-08');
        ContratoServico::where('company_id', $gravada->id)->update(['data_contratacao' => '2026-09-01']);

        // Chegou depois do fechamento, sem linha gravada.
        $chegou = $this->criarEmpresa($gestao, 'Chegou Depois', '2026-09-10');

        $idsNaTela = collect(
            $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08')->assertOk()->viewData('page')['props']['companies']
        )->pluck('id')->all();

        $this->assertContains($gravada->id, $idsNaTela, 'Mês fechado não muda sozinho: quem está gravado segue na tela até o mês ser refeito.');
        $this->assertNotContains($chegou->id, $idsNaTela, 'Quem só virou cliente depois não aparece no mês fechado.');
    }

    // ─── Os quatro lugares ────────────────────────────────────────────────

    #[Test]
    public function os_quatro_lugares_montam_a_mesma_lista_para_o_mesmo_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $admin  = User::factory()->create(['role' => 'admin']);
        $gestao = $this->criarServicoGestao();

        $a = $this->criarEmpresa($gestao, 'Empresa Alfa', '2026-01-10');
        $b = $this->criarEmpresa($gestao, 'Empresa Beta', '2026-08-31');
        $c = $this->criarEmpresa($gestao, 'Empresa Gama Setembro', '2026-09-01');

        $esperado = collect([$a->id, $b->id])->sort()->values()->all();

        // 1) Tela (mês ainda aberto: ao vivo).
        $tela = collect(
            $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08')->assertOk()->viewData('page')['props']['companies']
        )->pluck('id')->sort()->values()->all();
        $this->assertSame($esperado, $tela, 'Tela');

        // 2) Relatório geral (mesmo pipeline da tela).
        $geral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral').'?mes=2026-08');
        $geral->assertOk();
        $this->assertStringContainsString('Empresa Alfa', $geral->getContent());
        $this->assertStringNotContainsString('Empresa Gama Setembro', $geral->getContent(), 'Relatório geral');

        // 3) Comparativo.
        $this->assertSame(0, Artisan::call('fechamento:comparar-mensalidade', ['--mes' => '2026-08', '--json' => true]));
        $comparativo = collect(json_decode(Artisan::output(), true)['empresas'])->pluck('id')->sort()->values()->all();
        $this->assertSame($esperado, $comparativo, 'Comparativo');

        // 4) Job de e-mail.
        Configuracao::set('email_destinatarios_fechamento', json_encode(['financeiro@ecfconsultoria.com.br']));
        Mail::fake();
        (new EnviarRelatorioFechamentoJob('2026-08'))->handle();
        $idsJob = null;
        Mail::assertSent(RelatorioFechamentoMail::class, function ($mail) use (&$idsJob) {
            $idsJob = collect($mail->dados['relatorios'])->map(fn ($r) => $r['company']->id)->sort()->values()->all();

            return true;
        });
        $this->assertSame($esperado, $idsJob, 'Job de e-mail');

        // 5) Comando (grava).
        $this->consolidar('2026-08');
        $this->assertSame($esperado, $this->idsGravados('2026-08'), 'Comando');
    }

    // ─── Copy ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_copy_nova_da_tela_nao_tem_jargao(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $inicio = strpos($conteudo, 'function SemDataInicioAviso');
        $this->assertNotFalse($inicio, 'O aviso de empresas sem data de início precisa existir na tela.');
        $fim    = strpos($conteudo, 'function FiltroBarra', $inicio);
        $bloco  = substr($conteudo, $inicio, $fim - $inicio);

        $semComentarios = preg_replace('/^[ \t]*\/\/.*$/m', '', preg_replace('/\/\*.*?\*\//s', '', $bloco));

        $this->assertStringContainsString('sem data de início do contrato', $semComentarios);
        $this->assertStringContainsString('<SemDataInicioAviso empresas={empresas_sem_data_inicio} />', $conteudo);

        foreach (['data_contratacao', 'competência', 'snapshot', 'universo', 'pendência'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $semComentarios, "Termo técnico na tela: {$termo}");
        }
    }
}
