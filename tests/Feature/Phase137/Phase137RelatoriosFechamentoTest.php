<?php

namespace Tests\Feature\Phase137;

use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Mail\RelatorioFechamentoMail;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 08 — trava de convergência dos QUATRO consumidores do
 * fechamento (`/financeiro`, `gerarRelatorio()`, `gerarRelatorioGeral()`,
 * `EnviarRelatorioFechamentoJob`).
 *
 * Garante que:
 *  (a)/(b) os quatro consumidores devolvem o MESMO faturamento e a MESMA
 *      cobrança para a mesma empresa/competência — trava contra a
 *      duplicação de tabela voltar a divergir;
 *  (c)  empresa com `parent_company_id` e SEM grupo aparece como entidade
 *      própria (D-08/D-09) — a hierarquia legada nunca agrupa;
 *  (d)  o e-mail passa a incluir Shopee (D-05) — gap pré-existente do job;
 *  (e)  competência fechada devolve o valor CONGELADO mesmo depois de
 *      alterar `adman_metrics` (D-11);
 *  (f)  a string `FAIXAS` não existe mais em nenhum dos dois arquivos —
 *      trava de regressão contra a duplicação voltar;
 *  (g)/(h) o prefixo "a partir de" (D-02b, faixa-piso) aparece nas TRÊS
 *      superfícies pra empresa em faixa-piso e NÃO aparece pra empresa em
 *      faixa normal.
 */
class Phase137RelatoriosFechamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" — mesmo padrão de
     * `Phase137FinanceiroPropsTest`/`Phase137ConsolidarMesTest`.
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    private function configurarDestinatarios(): void
    {
        Configuracao::set('email_destinatarios_fechamento', json_encode(['financeiro@ecfconsultoria.com.br']));
    }

    /**
     * Dispara o job com `Mail::fake()` e captura o payload
     * (`RelatorioFechamentoMail->dados`) sem tocar Browsershot/Chrome —
     * `Mail::fake()` intercepta ANTES de `attachments()` ser avaliado.
     */
    private function capturarPayloadDoJob(string $mes): array
    {
        Mail::fake();
        (new EnviarRelatorioFechamentoJob($mes))->handle();

        $dados = null;
        Mail::assertSent(RelatorioFechamentoMail::class, function ($mail) use (&$dados) {
            $dados = $mail->dados;

            return true;
        });

        $this->assertNotNull($dados, 'Job precisa enviar o RelatorioFechamentoMail com destinatários configurados.');

        return $dados;
    }

    /**
     * Reproduz exatamente `RelatorioFechamentoMail::gerarPdf()` (mesma view,
     * mesmos parâmetros) sem precisar de Chrome/Browsershot — é o PDF
     * anexado ao e-mail, a superfície real que sai do sistema.
     */
    private function renderizarPdfDoJob(array $dados): string
    {
        return view('admin.relatorio-geral', [
            'relatorios'      => $dados['relatorios'],
            'mes_label'       => $dados['mesLabel'] ?? '',
            'mes_selecionado' => '',
            'gerado_em'       => now()->format('d/m/Y'),
        ])->render();
    }

    // ─── (a)/(b) — convergência numérica entre os quatro consumidores ──────

    #[Test]
    public function os_quatro_consumidores_devolvem_o_mesmo_faturamento_e_cobranca_para_a_mesma_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $solo   = $this->criarEmpresaComContrato($gestao, ['name' => 'Solo Fechada']);

        AdmanMetric::create(['company_id' => $solo->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->configurarDestinatarios();

        // 1) /financeiro (tela) — ground truth em JSON (props Inertia).
        $tela = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $tela->assertOk();
        $linhaTela = collect($tela->viewData('page')['props']['companies'])->firstWhere('id', $solo->id);
        $this->assertNotNull($linhaTela);
        $this->assertNotNull($linhaTela['faturamento']);
        $this->assertNotNull($linhaTela['cobranca_mensal']);

        $faturamentoFmt = 'R$ '.number_format((float) $linhaTela['faturamento'], 0, ',', '.');
        $cobrancaFmt    = number_format((float) $linhaTela['cobranca_mensal'], 0, ',', '.');

        // 2) gerarRelatorio() — PDF por empresa.
        $htmlPdf = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $solo).'?mes=2026-08');
        $htmlPdf->assertOk();
        $this->assertStringContainsString($faturamentoFmt, $htmlPdf->getContent());
        $this->assertStringContainsString($cobrancaFmt, $htmlPdf->getContent());

        // 3) gerarRelatorioGeral() — relatório geral.
        $htmlGeral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral').'?mes=2026-08');
        $htmlGeral->assertOk();
        $this->assertStringContainsString($faturamentoFmt, $htmlGeral->getContent());
        $this->assertStringContainsString($cobrancaFmt, $htmlGeral->getContent());

        // 4) EnviarRelatorioFechamentoJob — payload em array (precisão total).
        $dadosJob = $this->capturarPayloadDoJob('2026-08');
        $rJob     = collect($dadosJob['relatorios'])->first(fn ($r) => $r['company']->id === $solo->id);
        $this->assertNotNull($rJob, 'A empresa precisa aparecer no payload do job.');

        $this->assertEqualsWithDelta((float) $linhaTela['faturamento'], (float) $rJob['faturamento'], 0.01, 'faturamento do job precisa bater com o de /financeiro.');
        $this->assertEqualsWithDelta((float) $linhaTela['cobranca_mensal'], (float) $rJob['cobranca_mensal'], 0.01, 'cobranca_mensal do job precisa bater com o de /financeiro.');

        // PDF do job (anexo real do e-mail) também bate.
        $htmlJobPdf = $this->renderizarPdfDoJob($dadosJob);
        $this->assertStringContainsString($faturamentoFmt, $htmlJobPdf);
        $this->assertStringContainsString($cobrancaFmt, $htmlJobPdf);
    }

    // ─── (c) — empresa com parent_company_id e SEM grupo é entidade própria ─

    #[Test]
    public function empresa_com_parent_company_id_e_sem_grupo_e_entidade_propria_nos_tres_consumidores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        $pai = $this->criarEmpresaComContrato($gestao, ['name' => 'Pai Sem Grupo']);
        // D-08/D-09 — parent_company_id aponta pro pai, mas SEM company_group_id:
        // não pode agrupar em nenhum dos três consumidores.
        $avulsa = $this->criarEmpresaComContrato($gestao, [
            'name'              => 'Avulsa Parent Legado',
            'parent_company_id' => $pai->id,
        ]);

        AdmanMetric::create(['company_id' => $pai->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $avulsa->id, 'reference_date' => '2026-09-05', 'revenue' => 200_000.00]);

        $this->configurarDestinatarios();

        // gerarRelatorio($pai) — a avulsa NUNCA aparece como vinculada do pai.
        $htmlPdf = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $pai));
        $htmlPdf->assertOk();
        $this->assertStringNotContainsString($avulsa->name, $htmlPdf->getContent(), 'parent_company_id não pode virar vinculada no PDF por empresa (D-08/D-09).');

        // gerarRelatorioGeral() — pai e avulsa são 2 blocos de empresa distintos.
        $htmlGeral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral'));
        $htmlGeral->assertOk();
        $this->assertStringContainsString($avulsa->name, $htmlGeral->getContent());
        $this->assertGreaterThanOrEqual(2, substr_count($htmlGeral->getContent(), 'Dados da empresa'), 'Pai e avulsa precisam renderizar como 2 blocos de "Dados da empresa" distintos — nunca 1 grupo.');

        // Job — 2 entradas distintas no payload, cada uma com o próprio id.
        $dadosJob = $this->capturarPayloadDoJob('2026-09');
        $idsJob   = collect($dadosJob['relatorios'])->pluck('company')->map(fn ($c) => $c->id);
        $this->assertTrue($idsJob->contains($pai->id));
        $this->assertTrue($idsJob->contains($avulsa->id));
    }

    // ─── (d) — o e-mail passa a incluir Shopee (D-05, gap pré-existente) ──

    #[Test]
    public function empresa_com_shopee_tem_faturamento_maior_que_o_valor_so_ml_no_payload_do_email(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao, ['name' => 'ML mais Shopee']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000.00, 'ad_expense' => 0]);

        $this->configurarDestinatarios();

        $dadosJob = $this->capturarPayloadDoJob('2026-09');
        $rJob     = collect($dadosJob['relatorios'])->first(fn ($r) => $r['company']->id === $company->id);

        $this->assertNotNull($rJob);
        $this->assertNotNull($rJob['faturamento'], 'O gap pré-existente fazia o job nunca somar Shopee — não pode mais ficar nulo.');
        $this->assertEqualsWithDelta(350_000.00, (float) $rJob['faturamento'], 0.01, 'faturamento do e-mail precisa ser ML+Shopee somados (D-05), igual ao rollup central.');
        $this->assertGreaterThan(300_000.00, (float) $rJob['faturamento'], 'faturamento com Shopee precisa ser MAIOR que o valor só-ML (300k) — prova que Shopee entrou.');
    }

    // ─── (e) — competência fechada devolve o CONGELADO, nunca recalcula ────

    #[Test]
    public function competencia_fechada_devolve_valor_congelado_nos_quatro_consumidores_mesmo_apos_alterar_adman_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao, ['name' => 'Fechada Contra Correção']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->configurarDestinatarios();

        $faturamentoFmt = 'R$ '.number_format(600_000.00, 0, ',', '.');

        // Adman "corrige" o faturamento depois do fechamento — nunca deve
        // vazar pra nenhum dos quatro consumidores de uma competência já
        // congelada (D-11, mesmo precedente do módulo de Desempenho).
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-20', 'revenue' => 999_999.00]);

        $tela = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $tela->assertOk();
        $linhaTela = collect($tela->viewData('page')['props']['companies'])->firstWhere('id', $company->id);
        $this->assertEqualsWithDelta(600_000.00, (float) $linhaTela['faturamento'], 0.01);

        $htmlPdf = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $company).'?mes=2026-08');
        $htmlPdf->assertOk();
        $this->assertStringContainsString($faturamentoFmt, $htmlPdf->getContent());
        $this->assertStringNotContainsString('R$ 1.599.999', $htmlPdf->getContent());

        $htmlGeral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral').'?mes=2026-08');
        $htmlGeral->assertOk();
        $this->assertStringContainsString($faturamentoFmt, $htmlGeral->getContent());

        $dadosJob = $this->capturarPayloadDoJob('2026-08');
        $rJob     = collect($dadosJob['relatorios'])->first(fn ($r) => $r['company']->id === $company->id);
        $this->assertNotNull($rJob);
        $this->assertEqualsWithDelta(600_000.00, (float) $rJob['faturamento'], 0.01, 'Job também precisa ler o congelado, nunca recalcular ao vivo.');
    }

    // ─── (f) — a constante FAIXAS não volta em nenhum dos dois arquivos ────

    #[Test]
    public function nem_admin_controller_nem_o_job_contem_a_constante_faixas(): void
    {
        $htmlController = file_get_contents(app_path('Http/Controllers/AdminController.php'));
        $htmlJob        = file_get_contents(app_path('Jobs/EnviarRelatorioFechamentoJob.php'));

        $this->assertStringNotContainsString('FAIXAS', $htmlController, 'A tabela progressiva duplicada não pode voltar ao controller.');
        $this->assertStringNotContainsString('FAIXAS', $htmlJob, 'A tabela progressiva duplicada não pode voltar ao job.');
    }

    // ─── (g)/(h) — prefixo "a partir de" da faixa-piso (D-02b) ─────────────

    #[Test]
    public function empresa_na_faixa_piso_mostra_a_partir_de_nas_tres_superficies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $piso   = $this->criarEmpresaComContrato($gestao, ['name' => 'Empresa Faixa Piso']);

        // Acima de R$ 5.000.000 → última faixa de Gestão, que é PISO
        // ("a partir de R$ 12.000") — não valor fechado (D-02b).
        AdmanMetric::create(['company_id' => $piso->id, 'reference_date' => '2026-09-05', 'revenue' => 6_000_000.00]);

        $this->configurarDestinatarios();

        $htmlPdf = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $piso));
        $htmlPdf->assertOk();
        $this->assertStringContainsString('a partir de', $htmlPdf->getContent());

        $htmlGeral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral'));
        $htmlGeral->assertOk();
        $this->assertStringContainsString('a partir de', $htmlGeral->getContent());

        $dadosJob   = $this->capturarPayloadDoJob('2026-09');
        $htmlJobPdf = $this->renderizarPdfDoJob($dadosJob);
        $this->assertStringContainsString('a partir de', $htmlJobPdf, 'O PDF anexado ao e-mail (mesma view do relatório geral) também precisa mostrar o prefixo de piso.');
    }

    #[Test]
    public function empresa_em_faixa_normal_nao_mostra_a_partir_de_em_nenhuma_superficie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $normal  = $this->criarEmpresaComContrato($gestao, ['name' => 'Empresa Faixa Normal']);

        // R$ 600.000 cai na faixa 2 (fechada, com teto) — NUNCA piso.
        AdmanMetric::create(['company_id' => $normal->id, 'reference_date' => '2026-09-05', 'revenue' => 600_000.00]);

        $this->configurarDestinatarios();

        $htmlPdf = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $normal));
        $htmlPdf->assertOk();
        $this->assertStringNotContainsString('a partir de', $htmlPdf->getContent(), 'O prefixo de piso não pode vazar pra empresa em faixa fechada.');

        $htmlGeral = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral'));
        $htmlGeral->assertOk();
        $this->assertStringNotContainsString('a partir de', $htmlGeral->getContent());

        $dadosJob   = $this->capturarPayloadDoJob('2026-09');
        $htmlJobPdf = $this->renderizarPdfDoJob($dadosJob);
        $this->assertStringNotContainsString('a partir de', $htmlJobPdf);
    }
}
