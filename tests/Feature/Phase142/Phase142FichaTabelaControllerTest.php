<?php

namespace Tests\Feature\Phase142;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 142 Plano 02 — Tarefa 2: `TabelaEmpresaContratoController`, a ficha da tabela de cobrança
 * de uma empresa dentro do módulo de contratos.
 *
 * A permissão (armadilha central do plano) é travada à parte em
 * `Phase142FichaTabelaPermissaoTest`; aqui todos os cenários rodam como admin.
 *
 * `show()` é lido via header `X-Inertia` (JSON puro, mesmo formato da navegação client-side real)
 * em vez de `assertInertia()` — o helper da lib exige um response HTML full-page (View com dado
 * 'page'), o que forçaria o Blade `@vite` a resolver o manifest do componente
 * `Admin/TabelaEmpresa.jsx`, que só vai existir no plano 03 (mesmo padrão de
 * `tests/Feature/Phase58/DashboardShellsBackendTest.php`).
 */
class Phase142FichaTabelaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function faixas(float $valor = 1_500.00): array
    {
        return [
            ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => $valor, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => $valor * 2, 'valor_e_piso' => true],
        ];
    }

    private function criarLinha(Company $company, string $origem, float $valor = 999.00, int $ordem = 1): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => $ordem,
            'limite_superior' => null,
            'valor'           => $valor,
            'valor_e_piso'    => false,
            'origem'          => $origem,
        ]);
    }

    /**
     * X-Inertia simula navegação client-side (Inertia devolve JSON puro, sem renderizar o Blade
     * @vite) — evita depender do manifest Vite do componente `Admin/TabelaEmpresa.jsx`, que só vai
     * existir no plano 03 (esta fase é backend puro). Version precisa bater com o hash do
     * manifest, senão 409 — mesmo padrão de `tests/Feature/Phase58/DashboardRoutesTest.php`.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function showFicha(User $user, Company $company): TestResponse
    {
        return $this->actingAs($user)->withHeaders($this->inertiaHeaders())->get(route('admin.contratos.tabela.show', $company));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        return json_decode($response->getContent(), true)['props'];
    }

    // ── show: linhas exatas gravadas ──────────────────────────────────────

    #[Test]
    public function show_devolve_a_ficha_com_as_linhas_exatas_gravadas(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $this->criarLinha($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 1_500.00, 1);
        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 2, 'limite_superior' => null,
            'valor' => 3_000.00, 'valor_e_piso' => true, 'origem' => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        $response = $this->showFicha($admin, $company);

        $response->assertOk();
        $response->assertJson(['component' => 'Admin/TabelaEmpresa']);

        $props = $this->props($response);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $props['procedencia_empresa']);
        $this->assertCount(2, $props['tabela_empresa']);
        $this->assertSame(1, $props['tabela_empresa'][0]['ordem']);
        $this->assertEqualsWithDelta(1500.0, $props['tabela_empresa'][0]['valor'], 0.01);
        $this->assertTrue($props['tabela_empresa'][1]['valor_e_piso']);
    }

    #[Test]
    public function show_de_empresa_sem_tabela_devolve_array_vazio_e_procedencia_nula(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $response = $this->showFicha($admin, $company);

        $response->assertOk();
        $props = $this->props($response);
        $this->assertSame([], $props['tabela_empresa']);
        $this->assertNull($props['procedencia_empresa']);
    }

    #[Test]
    public function show_de_empresa_em_grupo_com_tabela_de_grupo_traz_origem_grupo(): void
    {
        $admin   = $this->admin();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Ficha Teste '.uniqid()]);
        $company = Company::factory()->create(['company_group_id' => $grupo->id]);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 5_000.00, 'valor_e_piso' => true,
        ]);

        $response = $this->showFicha($admin, $company);

        $response->assertOk();
        $props = $this->props($response);
        $this->assertSame('grupo', $props['tabela_aplicada']['origem']);
        $this->assertCount(1, $props['tabela_grupo']);
    }

    #[Test]
    public function show_traz_leitura_pendente_quando_existe_proposta_pendente_da_empresa(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $proposta = ContratoTabelaProposta::factory()->create([
            'company_id' => $company->id,
            'nome_envelope' => 'Contrato Gestão de ADS teste',
            'situacao' => ContratoTabelaProposta::SITUACAO_PENDENTE,
        ]);

        $response = $this->showFicha($admin, $company);

        $response->assertOk();
        $props = $this->props($response);
        $this->assertSame($proposta->id, $props['leitura_pendente']['id']);
        $this->assertTrue($props['leitura_pendente']['tem_tabela']);
    }

    #[Test]
    public function show_nao_traz_leitura_pendente_quando_a_proposta_ja_foi_confirmada(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        ContratoTabelaProposta::factory()->create([
            'company_id' => $company->id,
            'situacao'   => ContratoTabelaProposta::SITUACAO_CONFIRMADA,
        ]);

        $response = $this->showFicha($admin, $company);

        $response->assertOk();
        $props = $this->props($response);
        $this->assertNull($props['leitura_pendente']);
    }

    // ── salvar ─────────────────────────────────────────────────────────────

    #[Test]
    public function salvar_grava_com_origem_manual_e_redireciona(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => $this->faixas(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->ordenadas()->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => $l->origem === EmpresaFaixaFaturamento::ORIGEM_MANUAL));
    }

    #[Test]
    public function salvar_sobre_tabela_vinda_de_contrato_traz_aviso_na_sessao(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $this->criarLinha($company, EmpresaFaixaFaturamento::ORIGEM_CONTRATO);

        $response = $this->actingAs($admin)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => $this->faixas(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('aviso');
    }

    #[Test]
    public function salvar_com_faixas_sobrepostas_devolve_erro_e_nao_altera_nenhuma_linha(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $this->criarLinha($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 321.00);

        $response = $this->actingAs($admin)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => 1_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 200_000.00, 'valor' => 2_000.00, 'valor_e_piso' => false],
            ],
        ]);

        $response->assertSessionHasErrors();

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertSame(321.00, (float) $linhas[0]->valor);
    }

    // ── remover ────────────────────────────────────────────────────────────

    #[Test]
    public function remover_apaga_a_tabela_da_empresa(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $this->criarLinha($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        $response = $this->actingAs($admin)->delete(route('admin.contratos.tabela.remover', $company));

        $response->assertRedirect();
        $this->assertSame(0, EmpresaFaixaFaturamento::where('company_id', $company->id)->count());
    }
}
