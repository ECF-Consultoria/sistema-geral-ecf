<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 09 (Tarefa 3) — trava de contrato da UI de fechamento
 * depois de `Financeiro.jsx` ganhar os estados de ausência, o breakdown
 * ML+Shopee e `TabelaFaixasSection.jsx` (D-04/D-05/D-13).
 *
 * Duas frentes, per plano:
 *
 *  (1) Frente de PROPS — a resposta de `/administrativo/financeiro` traz,
 *      em toda linha de `companies`, as chaves que os componentes novos
 *      consomem. Sem essas chaves os componentes da Tarefa 1/2 renderizam
 *      vazio em silêncio (nenhum erro no console, só "sumiço" de dado —
 *      exatamente o tipo de regressão que este projeto já pagou caro por
 *      não pegar em teste, ver `.planning/learnings/`).
 *
 *  (2) Frente de ARQUIVO — o projeto não tem framework de teste JS
 *      (nenhum `*.test.jsx` em `resources/js/`), então a única trava
 *      barata contra regressão de UI é ler o `.jsx` como texto e afirmar
 *      presença/ausência de trechos-chave. Cobre especificamente o que o
 *      UI-SPEC proíbe reintroduzir (as constantes hardcoded apagadas) e o
 *      que ele proíbe recriar do zero (os componentes marcados
 *      "reaproveitar").
 */
class Phase137FinanceiroUiContratoTest extends TestCase
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
     * semeia as faixas de "Gestão" — mesmo padrão de
     * `Phase137FinanceiroPropsTest`/`Phase137FaixaResolverTest`.
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

    // ─── Frente 1: PROPS ──────────────────────────────────────────────────

    #[Test]
    public function toda_linha_de_companies_traz_as_chaves_que_os_componentes_novos_consomem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Financeiro')
            ->has('companies', 1, fn (Assert $linha) => $linha
                ->has('tipo')
                ->has('estado')
                ->has('faturamento_ml')
                ->has('faturamento_shopee')
                ->has('faixa_label')
                ->has('faixa_limite_inferior')
                ->has('faixa_limite_superior')
                ->has('tabela_origem')
                ->has('tabela_servico_nome')
                ->has('valor_faixa_e_piso')
                ->etc()
            )
            ->has('faixas_por_servico')
            ->has('competencia_fechada')
        );
    }

    #[Test]
    public function linha_de_empresa_sem_tabela_devolve_estado_sem_tabela_e_tabela_origem_nula(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin            = $this->criarAdmin();
        $servicoSemFaixas = Servico::create([
            'nome'          => 'Serviço Sem Tabela UI '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
        $company = $this->criarEmpresaComContrato($servicoSemFaixas);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('sem_tabela', $linha['estado']);
        $this->assertNull($linha['tabela_origem'], 'Sem tabela nenhuma (nem própria, nem serviço) — TabelaFaixasSection precisa desse null para desenhar o estado A DEFINIR.');
    }

    #[Test]
    public function faixas_por_servico_traz_id_nome_e_faixas_para_o_form_de_edicao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $this->criarServicoGestao();

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $faixasPorServico = $response->viewData('page')['props']['faixas_por_servico'];
        $gestao           = collect($faixasPorServico)->firstWhere('nome', 'Gestão');

        $this->assertNotNull($gestao, 'TabelaFaixasSection precisa achar a tabela de "Gestão" por nome para renderizar a lista somente leitura e alimentar o form de "Editar tabela do serviço".');
        $this->assertArrayHasKey('id', $gestao, 'Sem o id do serviço, o POST para admin.financeiro.faixas.servico não tem para onde ir.');
        $this->assertArrayHasKey('faixas', $gestao);
        $this->assertNotEmpty($gestao['faixas'], 'A migration já semeia as faixas de Gestão — lista vazia aqui indicaria regressão no seed ou na query.');
    }

    // ─── Frente 2: ARQUIVO (trava de regressão sem framework de teste JS) ──

    /**
     * Por que esta frente existe: o projeto não roda nenhum test runner de
     * JS (`vitest`/`jest`) — ver `package.json`. Sem essa checagem, alguém
     * poderia reintroduzir o mapa hardcoded de faixas ou recriar do zero um
     * componente que o UI-SPEC marcou "reaproveitar" (regressão silenciosa,
     * só visível olhando o JSX renderizado no navegador).
     */
    #[Test]
    public function financeiro_jsx_nao_reintroduz_o_mapa_hardcoded_de_faixas(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringNotContainsString('FAIXAS_LIMITES', $conteudo, 'A tabela de faixas agora é dinâmica por serviço/empresa — um mapa fixo de 6 faixas voltaria a ficar errado (ver 137-09).');
        $this->assertStringNotContainsString('FAIXA_NOMES', $conteudo);
    }

    #[Test]
    public function financeiro_jsx_nomeia_os_estados_de_ausencia_em_vez_de_r_0_ou_traco_mudo(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('A DEFINIR', $conteudo);
        $this->assertStringContainsString('Sem faturamento neste mês', $conteudo);
    }

    #[Test]
    public function financeiro_jsx_continua_reaproveitando_fechamentorow_progressaomodal_e_evolucaobadge(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('function FechamentoRow', $conteudo, 'UI-SPEC marca FechamentoRow como "reaproveitar" — recriar do zero é regressão de escopo.');
        $this->assertStringContainsString('function ProgressaoModal', $conteudo);
        $this->assertStringContainsString('function EvolucaoBadge', $conteudo);
    }

    #[Test]
    public function tabela_faixas_section_jsx_explica_que_a_tabela_propria_substitui_a_do_servico(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx'));

        $this->assertStringContainsString('Substitui completamente a tabela do serviço', $conteudo, 'D-13 exige o aviso sempre visível quando há exceção própria, não só na hora de salvar.');
    }
}
