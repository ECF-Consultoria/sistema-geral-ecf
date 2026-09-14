<?php

namespace Tests\Feature\Phase143;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\GravarTabelaGrupoService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 143 Plano 03 — Tarefas 2 e 3: a página própria da tabela de cobrança de um GRUPO e as
 * rotas que a servem.
 *
 * ## Por que a página precisou existir
 * Até aqui a tabela de um grupo só era editável de dentro da ficha de uma empresa-membro
 * (`Admin/TabelaEmpresa.jsx`). O grupo que fica POR CIMA dos outros pode não ter empresa nenhuma
 * pendurada direto nele — e é a tabela DELE que governa a cobrança de todas as empresas abaixo.
 * Sem página própria, essa tabela era **inalcançável** pela tela.
 *
 * ## O que estes testes protegem
 * 1. A página diz QUANTAS e QUAIS empresas a tabela alcança — as do grupo e as dos grupos que
 *    fazem parte dele. Quem cadastra precisa ver 10 empresas, não 2.
 * 2. Grupo que faz parte de outro avisa que quem manda é a tabela de lá.
 * 3. As escritas passam pela porta única e deixam trilha (o buraco que o T1 fechou).
 * 4. A validação de faixas continua valendo no caminho de grupo.
 * 5. Tabela gravada no grupo de fato passa a governar as empresas — conferido pelo
 *    `FechamentoFaixaResolver`, nunca por uma régua reimplementada no teste.
 *
 * `show` é lido via header `X-Inertia` (JSON puro) em vez de `assertInertia()` — mesmo motivo de
 * `Phase142FichaTabelaControllerTest`: o helper exigiria renderizar o Blade `@vite` e resolver o
 * manifest do componente.
 */
class Phase143TabelaGrupoPaginaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada. */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Tabela Grupo '.uniqid(),
            'slug'   => 'tabela-grupo-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    /**
     * @return array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>
     */
    private function faixas(float $valor = 21_000.00): array
    {
        return [
            ['ordem' => 1, 'limite_superior' => 5_000_000.00, 'valor' => $valor, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => $valor * 2, 'valor_e_piso' => true],
        ];
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function abrir(User $user, CompanyGroup $grupo): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('admin.contratos.tabela.grupo.show', $grupo));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        return json_decode($response->getContent(), true)['props'];
    }

    /**
     * O caso do 143-CONTEXT (D-02) em miniatura: um grupo de cobrança com dois grupos dentro
     * dele, empresas espalhadas pelos três.
     *
     * @return array{0: CompanyGroup, 1: CompanyGroup, 2: CompanyGroup}
     */
    private function montarConjunto(): array
    {
        $pai  = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#ffe600']);
        $sub1 = CompanyGroup::create(['name' => 'DRossi', 'color' => '#ffffff', 'parent_id' => $pai->id]);
        $sub2 = CompanyGroup::create(['name' => 'Gran Belo', 'color' => '#cccccc', 'parent_id' => $pai->id]);

        Company::factory()->count(2)->create(['company_group_id' => $pai->id]);
        Company::factory()->count(4)->create(['company_group_id' => $sub1->id]);
        Company::factory()->count(2)->create(['company_group_id' => $sub2->id]);

        return [$pai, $sub1, $sub2];
    }

    // ── (a) a página lista as empresas do grupo E dos grupos que fazem parte dele ──

    #[Test]
    public function a_pagina_lista_as_empresas_do_grupo_e_dos_grupos_que_fazem_parte_dele(): void
    {
        [$pai, $sub1, $sub2] = $this->montarConjunto();
        // Empresa de outro cliente — nunca pode aparecer.
        $deOutroCliente = Company::factory()->create([
            'company_group_id' => CompanyGroup::create(['name' => 'Outro Cliente', 'color' => '#111111'])->id,
        ]);

        $response = $this->abrir($this->admin(), $pai);

        $response->assertOk();
        $response->assertJson(['component' => 'Admin/TabelaGrupo']);

        $props = $this->props($response);

        $this->assertCount(8, $props['empresas'], 'A página precisa somar as empresas do grupo e as dos grupos que fazem parte dele.');
        $this->assertNotContains($deOutroCliente->id, array_column($props['empresas'], 'id'));

        // Cada empresa diz de qual grupo veio — é o que deixa visível que 8 não é 2.
        $porGrupo = array_count_values(array_column($props['empresas'], 'grupo_nome'));
        $this->assertSame(2, $porGrupo['MPozenato']);
        $this->assertSame(4, $porGrupo['DRossi']);
        $this->assertSame(2, $porGrupo['Gran Belo']);

        $subgrupos = collect($props['subgrupos'])->keyBy('name');
        $this->assertCount(2, $props['subgrupos']);
        $this->assertSame(4, $subgrupos['DRossi']['empresas_count']);
        $this->assertSame(2, $subgrupos['Gran Belo']['empresas_count']);
        $this->assertSame($sub1->id, $subgrupos['DRossi']['id']);
        $this->assertSame($sub2->id, $subgrupos['Gran Belo']['id']);
    }

    #[Test]
    public function a_pagina_de_um_grupo_sozinho_lista_so_as_empresas_dele(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Lyam', 'color' => '#ffe600']);
        Company::factory()->count(2)->create(['company_group_id' => $grupo->id]);

        $props = $this->props($this->abrir($this->admin(), $grupo));

        $this->assertCount(2, $props['empresas']);
        $this->assertSame([], $props['subgrupos']);
    }

    // ── (b) grupo que faz parte de outro avisa de onde vem a tabela ───────

    #[Test]
    public function grupo_que_faz_parte_de_outro_expoe_o_grupo_maior(): void
    {
        [$pai, $sub1] = $this->montarConjunto();

        $props = $this->props($this->abrir($this->admin(), $sub1));

        $this->assertNotNull($props['grupo']['pai']);
        $this->assertSame($pai->id, $props['grupo']['pai']['id']);
        $this->assertSame('MPozenato', $props['grupo']['pai']['name']);
    }

    #[Test]
    public function grupo_sem_pai_nao_expoe_grupo_maior_nenhum(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Lyam', 'color' => '#ffe600']);

        $props = $this->props($this->abrir($this->admin(), $grupo));

        $this->assertNull($props['grupo']['pai']);
    }

    #[Test]
    public function com_tabela_no_grupo_maior_a_pagina_do_subgrupo_diz_que_a_de_la_e_a_que_vale(): void
    {
        [$pai, $sub1] = $this->montarConjunto();

        app(GravarTabelaGrupoService::class)->gravar($pai, $this->faixas(21_000.00));
        app(GravarTabelaGrupoService::class)->gravar($sub1, $this->faixas(6_000.00));

        $props = $this->props($this->abrir($this->admin(), $sub1));

        // A tabela cadastrada AQUI continua visível (é ela que o formulário edita)...
        $this->assertCount(2, $props['tabela_grupo']);
        $this->assertEqualsWithDelta(6_000.00, $props['tabela_grupo'][0]['valor'], 0.01);

        // ...mas quem vale é a do grupo maior, e a página diz de quem ela é.
        $this->assertSame($pai->id, $props['tabela_que_vale']['grupo_id']);
        $this->assertSame('MPozenato', $props['tabela_que_vale']['grupo_nome']);
        $this->assertEqualsWithDelta(21_000.00, $props['tabela_que_vale']['faixas'][0]['valor'], 0.01);
    }

    #[Test]
    public function grupo_sem_tabela_nenhuma_devolve_tabela_que_vale_nula(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Lyam', 'color' => '#ffe600']);

        $props = $this->props($this->abrir($this->admin(), $grupo));

        $this->assertSame([], $props['tabela_grupo']);
        $this->assertNull($props['tabela_que_vale']);
    }

    // ── (c) escrita pela rota: delega à porta única e deixa trilha ────────

    #[Test]
    public function salvar_pela_rota_grava_a_tabela_e_deixa_uma_entrada_de_trilha(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas()]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertCount(2, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->get());

        $activity = Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->latest('id')->first();
        $this->assertNotNull($activity, 'Salvar pela rota precisa deixar trilha — era exatamente isto que não existia.');
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(8, $activity->properties['empresas_governadas']);
    }

    #[Test]
    public function substituir_pela_rota_registra_a_tabela_antiga_e_avisa_o_tamanho(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        app(GravarTabelaGrupoService::class)->gravar($pai, $this->faixas(21_000.00));

        $response = $this->actingAs($admin)
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas(12_000.00)]);

        $response->assertSessionHas('aviso');
        $this->assertStringContainsString('8 empresas', session('aviso'));

        $props = Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->latest('id')->first()->properties->toArray();
        $this->assertEqualsWithDelta(21_000.00, (float) $props['antes'][0]['valor'], 0.01);
        $this->assertEqualsWithDelta(12_000.00, (float) $props['depois'][0]['valor'], 0.01);
    }

    #[Test]
    public function remover_pela_rota_apaga_e_registra_depois_vazio(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();
        app(GravarTabelaGrupoService::class)->gravar($pai, $this->faixas());

        $response = $this->actingAs($admin)
            ->delete(route('admin.contratos.tabela.grupo.remover', $pai));

        $response->assertRedirect();
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->count());

        $props = Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->latest('id')->first()->properties->toArray();
        $this->assertSame([], $props['depois']);
        $this->assertCount(2, $props['antes']);
    }

    // ── (d) a validação de faixas continua valendo no caminho de grupo ────

    #[Test]
    public function faixas_com_teto_fora_de_ordem_sao_recusadas(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        $response = $this->actingAs($admin)->from('/')->post(route('admin.contratos.tabela.grupo.salvar', $pai), [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 5_000_000.00, 'valor' => 21_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 1_000_000.00, 'valor' => 27_000.00, 'valor_e_piso' => false],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->count());
    }

    #[Test]
    public function duas_faixas_sem_teto_sao_recusadas(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        $response = $this->actingAs($admin)->from('/')->post(route('admin.contratos.tabela.grupo.salvar', $pai), [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 21_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 27_000.00, 'valor_e_piso' => false],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->count());
    }

    #[Test]
    public function faixa_com_teto_marcada_como_piso_e_recusada(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        $response = $this->actingAs($admin)->from('/')->post(route('admin.contratos.tabela.grupo.salvar', $pai), [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 5_000_000.00, 'valor' => 21_000.00, 'valor_e_piso' => true],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 27_000.00, 'valor_e_piso' => false],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->count());
    }

    #[Test]
    public function lista_de_faixas_vazia_e_recusada(): void
    {
        $admin = $this->admin();
        [$pai] = $this->montarConjunto();

        $response = $this->actingAs($admin)->from('/')
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => []]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Activity::where('log_name', GravarTabelaGrupoService::LOG_NAME)->count());
    }

    // ── (e) a tabela gravada passa a governar as empresas ────────────────

    #[Test]
    public function tabela_gravada_no_grupo_maior_passa_a_governar_as_empresas_dos_grupos_de_dentro(): void
    {
        [$pai, $sub1] = $this->montarConjunto();

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas(21_000.00)])
            ->assertRedirect();

        $resolver = app(FechamentoFaixaResolver::class);

        // Uma empresa que pertence ao grupo de DENTRO — quem manda é a tabela de cima.
        $empresaDoSubgrupo = Company::where('company_group_id', $sub1->id)->firstOrFail();
        $empresaDoSubgrupo->loadMissing('grupo.pai');

        $resolvido = $resolver->paraEmpresa($empresaDoSubgrupo);

        $this->assertNotNull($resolvido);
        $this->assertSame('grupo', $resolvido['origem']);
        $this->assertSame($pai->id, $resolvido['grupo_id']);
        $this->assertEqualsWithDelta(21_000.00, (float) $resolvido['faixas']->first()->valor, 0.01);
    }

    // ── (f) permissão: quem abre é quem salva ────────────────────────────

    #[Test]
    public function usuario_com_admin_contratos_via_setor_abre_e_salva(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        [$pai] = $this->montarConjunto();

        $this->abrir($user, $pai)->assertOk();

        $this->actingAs($user)
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas()])
            ->assertRedirect();

        $this->assertCount(2, GrupoFaixaFaturamento::where('company_group_id', $pai->id)->get());
    }

    #[Test]
    public function usuario_sem_a_permissao_leva_403_nas_tres_rotas(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);
        [$pai] = $this->montarConjunto();

        $this->abrir($user, $pai)->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas()])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('admin.contratos.tabela.grupo.remover', $pai))
            ->assertForbidden();
    }

    // ── (g) o NPS não sente nada ─────────────────────────────────────────

    #[Test]
    public function nenhuma_empresa_muda_de_grupo_ao_cadastrar_a_tabela(): void
    {
        [$pai, $sub1] = $this->montarConjunto();

        $antes = Company::orderBy('id')->pluck('company_group_id', 'id');

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.tabela.grupo.salvar', $pai), ['faixas' => $this->faixas()])
            ->assertRedirect();

        $depois = Company::orderBy('id')->pluck('company_group_id', 'id');

        // `companies.company_group_id` é o que o link de NPS de grupo usa — ele não pode se mexer.
        $this->assertEquals($antes, $depois);
        $this->assertSame(4, Company::where('company_group_id', $sub1->id)->count());
    }
}
