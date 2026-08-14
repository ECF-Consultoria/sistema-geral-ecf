<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Item 4 da spec de 2026-08-14 — cada colaborador só enxerga as lojas
 * vinculadas a ele, e a restrição vale no DADO, não só na tela.
 *
 * O grosso já existia e foi reforçado no mesmo dia (ver
 * `NpsVisibilidadeLinkGeradoTest`): o escopo do não-admin é aplicado no
 * servidor por `$filtroPorPessoa`, e o `<select>` de empresas já sai de
 * `$user->companies()`. O que faltava era a NOMINATA: as listas de
 * estrategistas/analistas iam no payload para todo mundo, inclusive para quem
 * nem vê o seletor de pessoa.
 *
 * Estes testes travam as três pontas: empresa fora da carteira não aparece,
 * forçar o filtro por URL não revela nada, e a nominata não é servida a quem
 * não pode filtrar.
 */
class NpsEscopoColaboradorTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    private function vincular(Company $company, User $user, string $role, ?int $servicoId = null): void
    {
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'role'       => $role,
            'servico_id' => $servicoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function props(User $user, array $query = []): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('nps.index', $query + ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — o seletor de empresas só lista a carteira do colaborador
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_seletor_de_empresas_nao_lista_loja_de_outro_colaborador(): void
    {
        $minha  = Company::factory()->create(['active' => true, 'name' => 'Loja Minha']);
        $alheia = Company::factory()->create(['active' => true, 'name' => 'Loja Alheia']);

        $eu    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $outro = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->vincular($minha, $eu, 'estrategista');
        $this->vincular($alheia, $outro, 'estrategista');

        $props = $this->props($eu);
        $nomes = collect($props['companies'])->pluck('name');

        $this->assertContains('Loja Minha', $nomes);
        $this->assertNotContains('Loja Alheia', $nomes, 'loja de outro colaborador não pode aparecer no seletor.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — forçar empresa_id de outro por URL não revela nada
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_forcar_empresa_de_outro_colaborador_pela_url_nao_revela_survey(): void
    {
        $alheia = Company::factory()->create(['active' => true]);
        $eu     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $outro  = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $minha = Company::factory()->create(['active' => true]);
        $this->vincular($minha, $eu, 'estrategista');
        $this->vincular($alheia, $outro, 'estrategista');

        $servico = $this->contratarServicoNpsCoberto($alheia);
        NpsSurvey::factory()->create([
            'company_id'      => $alheia->id,
            'template_id'     => null,
            'status'          => 'pending',
            'month_reference' => now()->startOfMonth(),
            'generated_by'    => $outro->id,
            'completed_at'    => null,
        ]);

        // Passa o company_id da loja alheia direto na query string.
        $props = $this->props($eu, ['empresa_id' => $alheia->id]);

        $this->assertCount(0, $props['surveys']['data'], 'o escopo do servidor tem de vencer o parâmetro da URL.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — a nominata não é servida a quem não pode filtrar por pessoa
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_colaborador_comum_nao_recebe_a_lista_de_estrategistas_e_analistas(): void
    {
        $empresa = Company::factory()->create(['active' => true]);
        $eu      = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->vincular($empresa, $eu, 'estrategista');

        // Colegas existem e estão vinculados a outras empresas.
        $colega = User::factory()->create(['name' => 'Colega Secreto', 'active' => true]);
        $this->vincular(Company::factory()->create(['active' => true]), $colega, 'estrategista');

        $props = $this->props($eu);

        $this->assertFalse($props['pode_filtrar_por_pessoa']);
        $this->assertArrayNotHasKey('estrategistas', $props, 'a nominata não pode ir no payload de quem não filtra.');
        $this->assertArrayNotHasKey('analistas', $props);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — admin segue com acesso global (a restrição não pode vazar pra cima)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_continua_vendo_tudo_e_recebe_a_nominata(): void
    {
        Company::factory()->create(['active' => true, 'name' => 'Loja A']);
        Company::factory()->create(['active' => true, 'name' => 'Loja B']);

        $empresa = Company::factory()->create(['active' => true]);
        $este    = User::factory()->create(['active' => true]);
        $this->vincular($empresa, $este, 'estrategista');

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $props = $this->props($admin);

        $this->assertTrue($props['pode_filtrar_por_pessoa']);
        $this->assertArrayHasKey('estrategistas', $props);
        $this->assertGreaterThanOrEqual(3, count($props['companies']));
    }
}
