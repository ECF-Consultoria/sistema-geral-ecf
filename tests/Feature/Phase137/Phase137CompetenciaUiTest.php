<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 10 (Tarefas 1/2) — trava de contrato do cabeçalho de
 * estado da competência (badge + fechar/refazer) e da remoção definitiva
 * da coluna "Acumulado" do `ProgressaoModal` (D-06/D-11/D-12).
 *
 * Três frentes:
 *
 *  (a) PROPS — `/administrativo/financeiro` continua trazendo
 *      `competencia_fechada`/`competencia_fechada_em`, e nenhum item de
 *      `progressao` carrega a chave `acumulado` (já garantido pelo plano
 *      07 no backend — aqui é a trava do lado da tela que consome).
 *
 *  (b) ARQUIVO — o projeto não roda test runner de JS (nenhum `*.test.jsx`
 *      em `resources/js/`, ver Phase137FinanceiroUiContratoTest), então a
 *      trava de regressão de copy/UI é ler o `.jsx` como texto.
 *
 *  (c) REAPROVEITAMENTO — `ProgressaoModal` continua existindo como
 *      função no arquivo; o UI-SPEC manda evoluir o componente, não
 *      recriá-lo do zero.
 */
class Phase137CompetenciaUiTest extends TestCase
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
     * semeia as faixas de "Gestão" — mesmo padrão dos demais testes da fase.
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

    // ─── (a) PROPS ────────────────────────────────────────────────────────

    #[Test]
    public function props_trazem_competencia_fechada_e_fechada_em_sem_acumulado_na_progressao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-07-10', 'revenue' => 300_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-07'])->assertExitCode(0);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('competencia_fechada', $props);
        $this->assertArrayHasKey('competencia_fechada_em', $props);
        $this->assertTrue($props['competencia_fechada'], 'Agosto foi fechado por fechamento:consolidar-mes — a tela precisa saber disso para desenhar o badge Fechado.');
        $this->assertNotNull($props['competencia_fechada_em']);

        $linha = collect($props['companies'])->firstWhere('id', $company->id);
        $this->assertNotNull($linha);
        $this->assertGreaterThanOrEqual(2, count($linha['progressao']), 'Precisa ter histórico de pelo menos 2 competências fechadas (julho + agosto).');

        foreach ($linha['progressao'] as $item) {
            $this->assertArrayNotHasKey('acumulado', $item, 'D-06 é explícito: não deve haver coluna acumulada em lugar nenhum, nem no payload que alimenta o ProgressaoModal.');
        }
    }

    // ─── (b) ARQUIVO ──────────────────────────────────────────────────────

    #[Test]
    public function financeiro_jsx_nao_contem_mais_a_palavra_acumulado_nem_a_abreviacao_fat_do_mes(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringNotContainsStringIgnoringCase('acumulado', $conteudo, 'A coluna Acumulado do ProgressaoModal era o último lugar da UI onde a palavra vivia (D-06) — não pode voltar.');
        $this->assertStringNotContainsString('Fat. do mês', $conteudo, 'A abreviação só fazia sentido ao lado da coluna Acumulado — sem ela, o cabeçalho vira "Faturamento do mês".');
    }

    #[Test]
    public function financeiro_jsx_contem_a_copy_literal_do_cabecalho_de_competencia(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('Faturamento do mês', $conteudo);
        $this->assertStringContainsString('Refazer fechamento', $conteudo);
        $this->assertStringContainsString('Em aberto', $conteudo);
        $this->assertStringContainsString('Motivo do reprocessamento', $conteudo);
    }

    // ─── (d) INCIDENTE 260903-la4 — refazer/fechar sem aviso ───────────────
    // Produção: "Refazer fechamento" funcionou 3x seguidas (3x HTTP 200, 3
    // linhas em fechamento_reconsolidacoes) mas a tela não deu sinal nenhum
    // — a pessoa clicou de novo achando que tinha falhado. Trava de texto
    // (mesmo motivo do bloco (b): não há test runner de JS no projeto).

    #[Test]
    public function refazer_fechamento_fecha_o_dialogo_limpa_o_motivo_e_guarda_confirmacao_no_sucesso(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $inicio = strpos($conteudo, 'function RefazerFechamentoDialog');
        $this->assertNotFalse($inicio, 'RefazerFechamentoDialog precisa continuar existindo — UI-SPEC marca como reaproveitar.');

        $fimDaFuncao = strpos($conteudo, "\nfunction ", $inicio + 1);
        $bloco = substr($conteudo, $inicio, ($fimDaFuncao !== false ? $fimDaFuncao : strlen($conteudo)) - $inicio);

        $this->assertStringContainsString('setOpen(false)', $bloco, 'O sucesso do refazer precisa fechar o diálogo — clique repetido sem sinal foi o que gravou 3 linhas de auditoria em produção (260903-la4).');
        $this->assertStringContainsString("setMotivo('')", $bloco, 'Reabrir o diálogo depois com o motivo antigo preenchido é confuso — precisa limpar no sucesso.');
        $this->assertMatchesRegularExpression('/setConfirmacao\(/', $bloco, 'O handler de sucesso precisa guardar a mensagem do backend em algum estado visível — sem isso o usuário continua sem sinal (260903-la4).');
    }

    #[Test]
    public function fechar_competencia_tambem_guarda_confirmacao_no_sucesso(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $inicio = strpos($conteudo, 'function FecharCompetenciaButton');
        $this->assertNotFalse($inicio, 'FecharCompetenciaButton precisa continuar existindo.');

        $fimDaFuncao = strpos($conteudo, "\nfunction ", $inicio + 1);
        $bloco = substr($conteudo, $inicio, ($fimDaFuncao !== false ? $fimDaFuncao : strlen($conteudo)) - $inicio);

        $this->assertMatchesRegularExpression('/setConfirmacao\(/', $bloco, 'Fechar competência tinha o mesmo formato sem feedback do refazer — precisa do mesmo tratamento (260903-la4).');
    }

    // ─── (c) REAPROVEITAMENTO ─────────────────────────────────────────────

    #[Test]
    public function progressao_modal_continua_existindo_como_funcao_no_arquivo(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('function ProgressaoModal', $conteudo, 'UI-SPEC manda reaproveitar o componente (perder só a coluna Acumulado), não recriá-lo.');
    }
}
