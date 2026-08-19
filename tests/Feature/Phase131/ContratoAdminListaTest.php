<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 03 (UI-01/D-04) — ContratoAdminController::index().
 *
 * Nasce na Task 1 (200 + componente, resumo com 7 chaves, sem_contrato_count
 * fora dele — o núcleo que a Task 1 entrega) e é COMPLETADO na Task 3
 * (filtros, busca, ordenação, ausência de dado de signatário), no MESMO
 * arquivo — regra do "teste nasce na mesma task do código que ele prova"
 * (armadilha do `--filter` sem match que sai 0 e varre a suíte).
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA às props
 * Inertia + banco, nunca por stdout.
 */
class ContratoAdminListaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (lista admin)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ─── Task 1: o núcleo — 200 + componente, resumo de 7 chaves ──────────

    public function test_admin_recebe_200_e_o_componente_admin_contratos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Contratos'));
    }

    public function test_resumo_tem_exatamente_7_chaves_iguais_a_status_todos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertIsArray($props['resumo']);
        $this->assertCount(7, $props['resumo']);
        $this->assertSame(
            ContratoAssinatura::STATUS_TODOS,
            array_keys($props['resumo']),
            'O resumo deve ter EXATAMENTE as 7 chaves de STATUS_TODOS, na mesma ordem.'
        );
    }

    public function test_sem_contrato_count_existe_e_fica_fora_do_resumo(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Contrato Ainda']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('sem_contrato_count', $props);
        $this->assertIsInt($props['sem_contrato_count']);
        $this->assertGreaterThanOrEqual(1, $props['sem_contrato_count']);
        $this->assertArrayNotHasKey('aguardando_administrativo', $props['resumo']);
    }

    // ─── Task 3: contagens, filtros, busca, ordenação, ausência de dado ───
    // de signatário — completa este arquivo sem reescrever os casos acima.

    public function test_as_contagens_do_resumo_batem_com_os_contratos_criados(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Resumo Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(2),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Resumo Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $empresaRecusado = $this->empresa(['name' => 'Empresa Resumo Recusado']);
        $this->vincularServico($empresaRecusado, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaRecusado->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // Reconsulta ao banco — nunca confia só na resposta HTTP.
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS]
        );
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_ASSINADO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_ASSINADO]
        );
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_RECUSADO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_RECUSADO]
        );
    }

    public function test_as_contagens_do_resumo_nao_mudam_quando_o_filtro_de_situacao_e_aplicado(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Resumo Fixo Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Resumo Fixo Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $semFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $comFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => ContratoAssinatura::STATUS_ASSINADO,
        ]));

        $resumoSemFiltro = $semFiltro->viewData('page')['props']['resumo'];
        $resumoComFiltro = $comFiltro->viewData('page')['props']['resumo'];

        $this->assertSame(
            $resumoSemFiltro,
            $resumoComFiltro,
            'O resumo é contagem ABSOLUTA — não pode mudar quando o filtro de situação é aplicado.'
        );
    }

    public function test_filtro_de_situacao_devolve_apenas_as_linhas_daquele_estado(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Filtro Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Filtro Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]));

        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertNotEmpty($linhas);
        foreach ($linhas as $linha) {
            $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $linha['status']);
        }
    }

    public function test_situacao_fora_da_whitelist_e_ignorada_e_devolve_a_lista_completa(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Whitelist']);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $semFiltro    = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $filtroInvalido = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => 'valor_invalido_fora_da_lista',
        ]));

        $semFiltro->assertOk();
        $filtroInvalido->assertOk();

        $this->assertSame(
            $semFiltro->viewData('page')['props']['linhas']['total'],
            $filtroInvalido->viewData('page')['props']['linhas']['total'],
            'situacao fora da whitelist deve virar null e devolver a lista completa, igual a nenhum filtro.'
        );
    }

    public function test_busca_por_q_filtra_por_nome_da_empresa(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAlvo = $this->empresa(['name' => 'Empresa Busca Alvo Único']);
        $this->vincularServico($empresaAlvo, $servico);

        $empresaOutra = $this->empresa(['name' => 'Empresa Totalmente Diferente']);
        $this->vincularServico($empresaOutra, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'q' => 'Busca Alvo Único',
        ]));

        $response->assertOk();
        $nomes = collect($response->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->unique()->values()->all();

        $this->assertSame(['Empresa Busca Alvo Único'], $nomes);
    }

    /**
     * Ordenação padrão: EMPRESA MAIS RECENTE PRIMEIRO.
     *
     * ⚠️ Histórico — até 2026-08-19 a ordenação padrão era "mais tempo parado
     * primeiro" (`sortByDesc('dias_parado')`, decisão do 131-UI-SPEC), e este
     * teste provava exatamente o contrário do que prova agora. Superada por
     * pedido do usuário. A coluna "Parado há" continua na tela e o filtro de
     * situação continua funcionando — mudou só quem aparece no topo.
     *
     * O cenário é montado de propósito com o antigo critério em CONFLITO com o
     * novo: a empresa mais recente é a que está parada há MENOS tempo. Se
     * alguém reverter a ordenação sem querer, este teste reprova.
     */
    public function test_ordenacao_padrao_traz_primeiro_a_empresa_mais_recente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();

        $empresaAntiga = $this->empresa([
            'name'       => 'Empresa Antiga Parada Há Muito Tempo',
            'created_at' => Carbon::parse('2026-05-25 10:00:00'),
        ]);
        $this->vincularServico($empresaAntiga, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAntiga->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(30),
        ]);

        $empresaRecente = $this->empresa([
            'name'       => 'Empresa Recente Parada Ontem',
            'created_at' => Carbon::parse('2026-08-13 10:00:00'),
        ]);
        $this->vincularServico($empresaRecente, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaRecente->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertSame('Empresa Recente Parada Ontem', $linhas[0]['company_nome']);
        $this->assertSame('Empresa Antiga Parada Há Muito Tempo', $linhas[1]['company_nome']);
    }

    /**
     * Desempate determinístico entre empresas com o MESMO `created_at`.
     *
     * Não é caso de laboratório: `companies.created_at` tem um bloco grande de
     * empresas empatadas em 2026-05-25 por causa de um reimport em massa (a
     * coluna é artefato de importação, não data real de entrada da empresa).
     * Como a paginação de `index()` é MANUAL, sobre a coleção já ordenada, um
     * empate sem desempate faz linha trocar de página entre requisições — a
     * pessoa pagina e vê a mesma empresa duas vezes, ou nenhuma.
     *
     * O desempate é `company_id` DESC: entre empatadas, a cadastrada por último.
     */
    public function test_empresas_com_mesmo_created_at_desempatam_por_id_decrescente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();
        $mesmaData = Carbon::parse('2026-05-25 10:00:00');

        $primeira = $this->empresa(['name' => 'Empresa Empatada A', 'created_at' => $mesmaData]);
        $this->vincularServico($primeira, $servico);

        $segunda = $this->empresa(['name' => 'Empresa Empatada B', 'created_at' => $mesmaData]);
        $this->vincularServico($segunda, $servico);

        $this->assertGreaterThan($primeira->id, $segunda->id, 'fixture inválida: B precisa ter id maior que A');

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertSame('Empresa Empatada B', $linhas[0]['company_nome']);
        $this->assertSame('Empresa Empatada A', $linhas[1]['company_nome']);
    }

    /**
     * Uma empresa com DOIS serviços que exigem contrato gera duas linhas — e
     * elas precisam continuar adjacentes depois da ordenação, senão a leitura
     * da tela fica picotada (empresa A, empresa B, empresa A de novo).
     */
    public function test_linhas_da_mesma_empresa_ficam_adjacentes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servicoUm   = $this->servicoComContrato('Gestão de Tráfego (adjacência)');
        $servicoDois = $this->servicoComContrato('Assessoria (adjacência)');

        $doisServicos = $this->empresa([
            'name'       => 'Empresa Com Dois Servicos',
            'created_at' => Carbon::parse('2026-08-13 10:00:00'),
        ]);
        $this->vincularServico($doisServicos, $servicoUm);
        $this->vincularServico($doisServicos, $servicoDois);

        $outra = $this->empresa([
            'name'       => 'Empresa Do Meio',
            'created_at' => Carbon::parse('2026-08-12 10:00:00'),
        ]);
        $this->vincularServico($outra, $servicoUm);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $nomes = collect($response->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->all();

        $this->assertSame(
            ['Empresa Com Dois Servicos', 'Empresa Com Dois Servicos', 'Empresa Do Meio'],
            $nomes
        );
    }

    public function test_nenhuma_linha_carrega_nome_email_ou_cpf_de_signatario(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Dado De Signatário']);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertNotEmpty($linhas);
        foreach ($linhas as $linha) {
            $this->assertArrayNotHasKey('nome', $linha);
            $this->assertArrayNotHasKey('email', $linha);
            $this->assertArrayNotHasKey('cpf', $linha);
            $this->assertArrayNotHasKey('signatarios', $linha);
        }
    }
}
