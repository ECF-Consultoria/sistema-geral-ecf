<?php

namespace Tests\Feature\Phase157;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 157 (D-C/D-D) — a aba Distribuição de `/companies`, do LÍDER.
 *
 * A régua de distribuir não mudou: `DistribuicaoService` é reusado inteiro. O
 * que estes testes fixam é **quem vê a fila** e que distribuir dali produz
 * exatamente o mesmo efeito da tela anterior.
 */
class AbaDistribuicaoTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function setorPerformance(): Setor
    {
        return Setor::where('slug', 'performance')->firstOrFail();
    }

    private function comAcessoAEmpresas(User $u): User
    {
        $setor = Setor::firstOrCreate(
            ['slug' => 'acesso-empresas-157b'],
            ['nome' => 'Acesso Empresas 157b', 'active' => true]
        );
        SetorPermissao::firstOrCreate(['setor_id' => $setor->id, 'permission_key' => 'core.empresas']);
        if (! $setor->membros()->where('users.id', $u->id)->exists()) {
            $setor->membros()->attach($u->id, ['is_principal' => false, 'assigned_at' => now()]);
        }

        return $u;
    }

    private function comCargo(string $slug): User
    {
        $u = $this->comAcessoAEmpresas(User::factory()->create(['role' => 'consultor', 'active' => true]));
        $cargo = Cargo::firstOrCreate(
            ['setor_id' => $this->setorPerformance()->id, 'slug' => $slug],
            ['nome' => ucfirst($slug)]
        );

        DB::table('user_setores')->insert([
            'user_id' => $u->id, 'setor_id' => $cargo->setor_id, 'cargo_id' => $cargo->id,
            'is_principal' => true, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** O Luiz: líder do setor Performance E estrategista. */
    private function lider(): User
    {
        $u = $this->comCargo('estrategista');

        DB::table('setor_lideres')->insert([
            'setor_id' => $this->setorPerformance()->id, 'user_id' => $u->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** Empresa de Performance na etapa 5 — pronta para distribuir. */
    private function empresaNaFila(): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Fila '.$n,
            'cnpj'   => "22.922.922/{$n}-91",
            'etapa'  => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ]);

        $servico = Servico::where('setor', Servico::SETOR_PERFORMANCE)->where('ativo', true)->firstOrFail();

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'valor_contratado' => 100, 'data_contratacao' => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento' => 10, 'ativo' => true,
        ]));

        return $empresa->fresh();
    }

    /** @return array<string, mixed> */
    private function props(User $u): array
    {
        return $this->actingAs($u)->get(route('companies.index'))->assertOk()->viewData('page')['props'];
    }

    // ─── Quem vê a fila ─────────────────────────────────────────────────────

    public function test_o_lider_recebe_a_fila_com_os_elegiveis(): void
    {
        $empresa = $this->empresaNaFila();
        $this->comCargo('analista');   // gente elegível existindo
        $lider = $this->lider();

        $props = $this->props($lider);

        $this->assertTrue($props['pode_distribuir']);
        $this->assertNotEmpty($props['fila_distribuicao']);

        $linha = collect($props['fila_distribuicao'])->firstWhere('id', $empresa->id);
        $this->assertNotNull($linha, 'a empresa na etapa 5 precisa aparecer na fila do líder.');
        $this->assertArrayHasKey('analistas', $linha);
        $this->assertArrayHasKey('estrategistas', $linha);
    }

    public function test_admin_tambem_recebe_a_fila(): void
    {
        $this->empresaNaFila();

        $props = $this->props(User::factory()->create(['role' => 'admin']));

        $this->assertTrue($props['pode_distribuir']);
        $this->assertNotEmpty($props['fila_distribuicao']);
    }

    /**
     * Analista comum não distribui. Esconder a aba no front é cosmético — o que
     * protege é a fila NÃO ser montada no servidor.
     */
    public function test_analista_comum_nao_recebe_a_fila(): void
    {
        $this->empresaNaFila();

        $props = $this->props($this->comCargo('analista'));

        $this->assertFalse($props['pode_distribuir']);
        $this->assertSame([], $props['fila_distribuicao']);
    }

    public function test_estrategista_que_nao_e_lider_nao_recebe_a_fila(): void
    {
        $this->empresaNaFila();

        $props = $this->props($this->comCargo('estrategista'));

        $this->assertFalse($props['pode_distribuir']);
        $this->assertSame([], $props['fila_distribuicao']);
    }

    // ─── Distribuir dali produz o MESMO efeito ──────────────────────────────

    /**
     * A régua não mudou de lugar junto com a tela: é o mesmo
     * `DistribuicaoService`, o mesmo endpoint e a mesma linha de histórico.
     */
    public function test_o_lider_distribui_e_a_empresa_sai_da_fila(): void
    {
        $empresa  = $this->empresaNaFila();
        $analista = $this->comCargo('analista');
        $lider    = $this->lider();

        $this->actingAs($lider)
            ->post(route('coordenacao.distribuicao.distribuir', $empresa), [
                'analista_id'     => $analista->id,
                'estrategista_id' => $lider->id,
            ])
            ->assertStatus(302)
            ->assertSessionHas('success');

        // Reconsulta ao banco.
        $this->assertSame(Company::ETAPA_AGUARDANDO_ONBOARDING, Company::findOrFail($empresa->id)->etapa);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $empresa->id, 'role' => 'analista', 'user_id' => $analista->id,
        ]);

        // O ATOR da transição é o líder — é ele quem distribuiu.
        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->firstOrFail();
        $this->assertSame($lider->id, $linha->user_id);

        // E some da fila.
        $props = $this->props($lider);
        $this->assertNull(collect($props['fila_distribuicao'])->firstWhere('id', $empresa->id));
    }

    /**
     * O cenário que o usuário descreveu: o Luiz é líder E estrategista. Ao se
     * colocar como responsável, a empresa passa a ser dele — e é isso que ele
     * vê quando filtra pelas próprias.
     */
    public function test_lider_que_se_coloca_como_estrategista_fica_vinculado(): void
    {
        $empresa  = $this->empresaNaFila();
        $analista = $this->comCargo('analista');
        $lider    = $this->lider();

        $this->actingAs($lider)->post(route('coordenacao.distribuicao.distribuir', $empresa), [
            'analista_id'     => $analista->id,
            'estrategista_id' => $lider->id,
        ])->assertStatus(302);

        $this->assertTrue(
            $lider->companies()->where('companies.id', $empresa->id)->exists(),
            'o líder que se coloca como estrategista precisa passar a ter a empresa na carteira.'
        );
    }
}
