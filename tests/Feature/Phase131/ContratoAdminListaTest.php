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
    /**
     * `ordenar=vencimento` — término mais PRÓXIMO primeiro.
     *
     * ⚠️ A parte que importa é o NULO. Término vazio não é dado faltando: é
     * contrato por prazo indeterminado, caso que a regra 5 do
     * `ContratoDadosMinimosService` registra explicitamente. Como `null`
     * ordena antes de qualquer data em PHP, uma ordenação ingênua colocaria
     * no TOPO justamente quem não tem prazo, apresentado como o mais urgente.
     * Aqui a empresa sem prazo é criada por ÚLTIMO de propósito: se o
     * desempate por id vazar para cima da regra de nulo, ela sobe e o teste
     * reprova.
     */
    public function test_ordenacao_por_vencimento_traz_termino_mais_proximo_e_joga_sem_prazo_para_o_fim(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();

        $distante = $this->empresa(['name' => 'Empresa Termino Distante']);
        $this->vincularServico($distante, $servico)->update(['data_vencimento' => '2027-12-31']);

        $proxima = $this->empresa(['name' => 'Empresa Termino Proximo']);
        $this->vincularServico($proxima, $servico)->update(['data_vencimento' => '2026-09-01']);

        $semPrazo = $this->empresa(['name' => 'Empresa Sem Prazo']);
        $this->vincularServico($semPrazo, $servico)->update(['data_vencimento' => null]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', ['ordenar' => 'vencimento']));
        $response->assertOk();
        $nomes = collect($response->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->all();

        $this->assertSame(
            ['Empresa Termino Proximo', 'Empresa Termino Distante', 'Empresa Sem Prazo'],
            $nomes
        );
    }

    /**
     * Filtro por serviço adquirido.
     *
     * ⚠️ O caso que define a implementação é a empresa com DOIS serviços: ela
     * gera duas linhas, e filtrar precisa manter só a linha do serviço
     * escolhido — não sumir com a empresa inteira nem trazê-la inteira. Por
     * isso o filtro é aplicado sobre as LINHAS, em memória, e nunca na query
     * de companies.
     */
    public function test_filtro_por_servico_mantem_apenas_a_linha_do_servico_escolhido(): void
    {
        $servicoAlvo  = $this->servicoComContrato('Gestão de Tráfego (filtro)');
        $servicoOutro = $this->servicoComContrato('Assessoria (filtro)');

        $doisServicos = $this->empresa(['name' => 'Empresa Com Dois Servicos']);
        $this->vincularServico($doisServicos, $servicoAlvo);
        $this->vincularServico($doisServicos, $servicoOutro);

        $soOutro = $this->empresa(['name' => 'Empresa So Do Outro Servico']);
        $this->vincularServico($soOutro, $servicoOutro);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', ['servico' => $servicoAlvo->id]));
        $response->assertOk();
        $linhas = collect($response->viewData('page')['props']['linhas']['data']);

        // A empresa dos dois serviços continua, com UMA linha só; a que não
        // tem o serviço alvo sai.
        $this->assertSame(['Empresa Com Dois Servicos'], $linhas->pluck('company_nome')->all());
        $this->assertSame([$servicoAlvo->id], $linhas->pluck('servico_id')->unique()->values()->all());
    }

    /**
     * O resumo de 7 contagens é ABSOLUTO — não encolhe com o filtro de
     * serviço, mesma disciplina do filtro de situação. É a régua fixa contra
     * a qual se compara o recorte escolhido; se encolhesse junto, os cartões
     * marcariam sempre 100% do que está na tela e deixariam de informar.
     */
    public function test_filtro_por_servico_nao_encolhe_o_resumo_de_sete_contagens(): void
    {
        $servicoAlvo  = $this->servicoComContrato('Gestão de Tráfego (resumo)');
        $servicoOutro = $this->servicoComContrato('Assessoria (resumo)');

        $umaEmpresa = $this->empresa(['name' => 'Empresa Alvo Resumo']);
        $this->vincularServico($umaEmpresa, $servicoAlvo);

        $outraEmpresa = $this->empresa(['name' => 'Empresa Fora Do Filtro']);
        $this->vincularServico($outraEmpresa, $servicoOutro);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', ['servico' => $servicoAlvo->id]));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['linhas']['data'], 'o filtro precisa recortar a lista');
        $this->assertCount(7, $props['resumo'], 'o resumo trava em 7 contagens (D-04)');
        $this->assertSame(2, $props['sem_contrato_count'], 'a contagem absoluta ignora o filtro de serviço');
    }

    /**
     * Whitelist: valor inválido degrada para o default e NUNCA chega a
     * ordenar ou filtrar nada — mesma disciplina do `situacao` (T-131-03-03).
     * `filters` volta saneado, senão o select da tela mostraria selecionado
     * um critério que o backend não usou.
     */
    public function test_valores_invalidos_de_ordenar_e_servico_caem_no_default(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Whitelist']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'ordenar' => 'coluna_inexistente',
            'servico' => 99999,
        ]));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('recente', $props['filters']['ordenar']);
        $this->assertNull($props['filters']['servico']);
        $this->assertCount(1, $props['linhas']['data'], 'servico invalido nao pode filtrar nada');
    }

    /** Os filtros combinam entre si — serviço + situação + ordenação juntos. */
    public function test_servico_situacao_e_ordenar_combinam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servicoAlvo  = $this->servicoComContrato('Gestão de Tráfego (combina)');
        $servicoOutro = $this->servicoComContrato('Assessoria (combina)');

        // Alvo: serviço certo E situação certa.
        $alvo = $this->empresa(['name' => 'Empresa Alvo Combinado']);
        $this->vincularServico($alvo, $servicoAlvo)->update(['data_vencimento' => '2026-09-01']);
        ContratoAssinatura::factory()->create([
            'company_id' => $alvo->id,
            'servico_id' => $servicoAlvo->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        // Serviço certo, situação errada.
        $situacaoErrada = $this->empresa(['name' => 'Empresa Situacao Errada']);
        $this->vincularServico($situacaoErrada, $servicoAlvo);
        ContratoAssinatura::factory()->create([
            'company_id' => $situacaoErrada->id,
            'servico_id' => $servicoAlvo->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
        ]);

        // Situação certa, serviço errado.
        $servicoErrado = $this->empresa(['name' => 'Empresa Servico Errado']);
        $this->vincularServico($servicoErrado, $servicoOutro);
        ContratoAssinatura::factory()->create([
            'company_id' => $servicoErrado->id,
            'servico_id' => $servicoOutro->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'servico'  => $servicoAlvo->id,
            'situacao' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'ordenar'  => 'vencimento',
        ]));

        $response->assertOk();
        $nomes = collect($response->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->all();

        $this->assertSame(['Empresa Alvo Combinado'], $nomes);
    }

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
