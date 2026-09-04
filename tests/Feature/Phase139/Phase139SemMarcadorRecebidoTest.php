<?php

namespace Tests\Feature\Phase139;

use App\Http\Controllers\AdminController;
use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Mail\RelatorioFechamentoMail;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\FechamentoRecebido;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 139 Plano 07 — trava contra o marcador de "recebido" voltar em
 * qualquer um dos seis pontos onde ele vivia: tela, controller, rota,
 * e-mail mensal e os dois PDFs (por empresa e geral).
 *
 * Decisão do usuário (2026-09-04, com dado medido): o marcador foi usado
 * uma única vez em produção — uma empresa, competência de abril/2026,
 * marcada em 20/05/2026 — e nunca mais. Ninguém o alimentava. Removê-lo só
 * da tela deixaria o e-mail mensal e os PDFs lendo uma tabela vazia e
 * informando "tudo pendente" para sempre — o mesmo defeito do widget
 * zerado que motivou a Fase 139, só que enviado por e-mail. Por isso saiu
 * de tudo.
 *
 * ⚠️ A tabela `fechamento_recebidos` e o model `FechamentoRecebido`
 * CONTINUAM existindo de propósito — a linha histórica de abril/2026 não
 * foi apagada (não foi pedido, e uma migration de drop não tem volta). Por
 * isso as asserções aqui são sobre o que é VISÍVEL ou EMITIDO — rotas
 * registradas, chaves de prop, métodos de controller, HTML renderizado —
 * NUNCA sobre a string "recebido" solta em todo o projeto, que reprovaria
 * por causa do model/tabela que devem continuar existindo.
 */
class Phase139SemMarcadorRecebidoTest extends TestCase
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
     * semeia as 7 faixas de "Gestão" — mesmo padrão dos demais testes da
     * fase (ver Phase139TotaisFechamentoTest).
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

    // ─── Ponto 3 — a rota ───────────────────────────────────────────────

    #[Test]
    public function a_rota_admin_financeiro_recebido_nao_esta_mais_registrada(): void
    {
        $nomesDeRotas = collect(Route::getRoutes())
            ->map(fn ($rota) => $rota->getName())
            ->filter()
            ->values()
            ->all();

        $this->assertNotContains(
            'admin.financeiro.recebido',
            $nomesDeRotas,
            'A rota POST /financeiro/{company}/recebido precisa ter sido removida (Fase 139 Plano 07).'
        );
    }

    // ─── Ponto 2 — o controller ─────────────────────────────────────────

    #[Test]
    public function o_metodo_togglerecebido_nao_existe_mais_no_admincontroller(): void
    {
        $this->assertFalse(
            method_exists(AdminController::class, 'toggleRecebido'),
            'toggleRecebido() precisa ter sido removido do AdminController.'
        );
    }

    // ─── Ponto 1 — a tela ───────────────────────────────────────────────

    #[Test]
    public function tela_financeiro_responde_200_sem_a_chave_recebido_nas_props_das_empresas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $linha = collect($props['companies'])->firstWhere('id', $company->id);

        $this->assertNotNull($linha, 'Pré-condição do teste: a empresa criada precisa aparecer na listagem.');
        $this->assertArrayNotHasKey('recebido', $linha, 'A linha da empresa não pode mais trazer a chave "recebido" — o controle saiu da tela.');
    }

    #[Test]
    public function tela_financeiro_nao_emite_nenhuma_chamada_para_a_rota_removida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin = $this->criarAdmin();

        // A resposta HTTP da carga inicial do Inertia embute as props como
        // JSON no HTML — se a tela ainda tentasse montar `route('admin.
        // financeiro.recebido', ...)` no lado do servidor, o Ziggy quebraria
        // a resposta inteira antes de chegar aqui. Uma resposta 200 sem o
        // nome da rota no corpo é evidência de que não sobrou chamada órfã.
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertDontSee('admin.financeiro.recebido', false);
    }

    // ─── Ponto 5 — PDF por empresa (relatorio-fechamento.blade.php) ─────

    #[Test]
    public function pdf_por_empresa_nao_afirma_se_o_cliente_pagou(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $company).'?mes=2026-09');

        $response->assertOk();
        $response->assertDontSee('Pagamento recebido');
        $response->assertDontSee('Pagamento pendente');
        $this->assertStringNotContainsStringIgnoringCase('recebido', $response->getContent(), 'O PDF por empresa não pode mais mencionar "recebido" em nenhuma forma visível.');
    }

    // ─── Ponto 6 — PDF geral (relatorio-geral.blade.php) ─────────────────
    // É a view REAL usada tanto pelo dropdown "Gerar relatórios" no
    // navegador quanto pelo anexo PDF do e-mail (RelatorioFechamentoMail::
    // gerarPdf() reaproveita a mesma view via Browsershot).

    #[Test]
    public function pdf_geral_nao_afirma_se_os_clientes_pagaram(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral', ['mes' => '2026-09']));

        $response->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('recebido', $response->getContent(), 'O PDF geral não pode mais mencionar "recebido" em nenhuma forma visível.');
        $this->assertStringNotContainsStringIgnoringCase('pendente', $response->getContent(), 'O PDF geral não pode mais falar em empresas "pendentes" de pagamento.');
    }

    #[Test]
    public function pdf_geral_ignora_o_parametro_recebido_na_query_string_sem_quebrar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $this->criarEmpresaComContrato($gestao);

        // Um link antigo (favorito de navegador, e-mail já enviado) ainda
        // pode chegar com ?recebido=sim — a rota precisa responder 200 e
        // ignorar o parâmetro morto, nunca quebrar.
        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral', ['mes' => '2026-09', 'recebido' => 'sim']));

        $response->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('recebido', $response->getContent());
    }

    // ─── Ponto 4 — o e-mail mensal (job + corpo do e-mail) ───────────────

    #[Test]
    public function o_corpo_do_email_mensal_nao_afirma_se_os_clientes_pagaram(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        Configuracao::set('email_destinatarios_fechamento', json_encode(['financeiro@ecfconsultoria.com.br']));

        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        Mail::fake();
        (new EnviarRelatorioFechamentoJob('2026-09'))->handle();

        $dados = null;
        Mail::assertSent(RelatorioFechamentoMail::class, function ($mail) use (&$dados) {
            $dados = $mail->dados;

            return true;
        });

        $this->assertNotNull($dados, 'O job precisa enviar o RelatorioFechamentoMail quando há destinatários configurados.');
        $this->assertArrayNotHasKey('total_recebido', $dados['totais'] ?? [], 'Os totais do e-mail não podem mais trazer "total_recebido".');
        $this->assertArrayNotHasKey('total_pendente', $dados['totais'] ?? [], 'Os totais do e-mail não podem mais trazer "total_pendente".');

        foreach ($dados['relatorios'] as $linha) {
            $this->assertArrayNotHasKey('recebido', $linha, 'Nenhuma linha do relatório do e-mail pode trazer a chave "recebido".');
        }

        // Corpo real do e-mail — emails.relatorio-fechamento.blade.php,
        // renderizado com os MESMOS dados que o job monta.
        $htmlDoCorpo = view('emails.relatorio-fechamento', ['dados' => $dados])->render();
        $this->assertStringNotContainsStringIgnoringCase('recebido', $htmlDoCorpo, 'O corpo do e-mail mensal não pode mais mencionar "recebido".');
        $this->assertStringNotContainsStringIgnoringCase('pendente', $htmlDoCorpo, 'O corpo do e-mail mensal não pode mais falar em "pendente" de pagamento.');
    }

    #[Test]
    public function o_pdf_anexado_ao_email_reproduz_a_mesma_view_sem_falar_de_pagamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        Configuracao::set('email_destinatarios_fechamento', json_encode(['financeiro@ecfconsultoria.com.br']));

        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        Mail::fake();
        (new EnviarRelatorioFechamentoJob('2026-09'))->handle();

        $dados = null;
        Mail::assertSent(RelatorioFechamentoMail::class, function ($mail) use (&$dados) {
            $dados = $mail->dados;

            return true;
        });

        // Reproduz `RelatorioFechamentoMail::gerarPdf()` (mesma view, mesmos
        // parâmetros) sem depender de Chrome/Browsershot — é o PDF anexado
        // ao e-mail, a superfície real que sai do sistema (mesmo padrão de
        // Phase137RelatoriosFechamentoTest::renderizarPdfDoJob()).
        $htmlDoPdf = view('admin.relatorio-geral', [
            'relatorios'      => $dados['relatorios'],
            'mes_label'       => $dados['mesLabel'] ?? '',
            'mes_selecionado' => '',
            'gerado_em'       => now()->format('d/m/Y'),
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('recebido', $htmlDoPdf, 'O PDF anexado ao e-mail não pode mais mencionar "recebido".');
    }

    // ─── A tabela e o model continuam existindo de propósito ─────────────

    #[Test]
    public function a_tabela_fechamento_recebidos_continua_existindo_e_nao_vaza_pra_nenhuma_superficie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        // Simula a linha histórica real de produção (uma empresa, abril/2026,
        // marcada em 20/05/2026) — a tabela e o model continuam de pé, e
        // gravar nela precisa continuar funcionando (não foi pedido apagar).
        $registroHistorico = FechamentoRecebido::create([
            'company_id'  => $company->id,
            'mes'         => '2026-09',
            'recebido_em' => Carbon::parse('2026-05-20'),
        ]);

        $this->assertDatabaseHas('fechamento_recebidos', ['id' => $registroHistorico->id]);

        // Mesmo com uma linha marcada, nenhuma superfície pode voltar a
        // afirmar que a empresa pagou.
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $linha = collect($props['companies'])->firstWhere('id', $company->id);
        $this->assertArrayNotHasKey('recebido', $linha);

        $pdfPorEmpresa = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $company).'?mes=2026-09');
        $this->assertStringNotContainsStringIgnoringCase('recebido', $pdfPorEmpresa->getContent());

        // A linha histórica continua no banco depois de tudo isso.
        $this->assertDatabaseHas('fechamento_recebidos', ['id' => $registroHistorico->id]);
    }
}
