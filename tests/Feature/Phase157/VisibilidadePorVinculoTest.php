<?php

namespace Tests\Feature\Phase157;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 157 (D-A) — `/companies` passa a mostrar só as empresas do próprio
 * usuário para quem tem cargo analista ou estrategista.
 *
 * ⚠️ É MUDANÇA de comportamento: antes todo mundo com acesso à tela via todas
 * as empresas de Performance. Os testes aqui fixam quem perde a visão ampla e —
 * igualmente importante — **quem NÃO perde**.
 */
class VisibilidadePorVinculoTest extends TestCase
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
        // Criado pela migration `2026_09_10_140000_seed_setor_performance`.
        return Setor::where('slug', 'performance')->firstOrFail();
    }

    private function cargo(string $slug): Cargo
    {
        return Cargo::firstOrCreate(
            ['setor_id' => $this->setorPerformance()->id, 'slug' => $slug],
            ['nome' => ucfirst($slug)]
        );
    }

    /**
     * `/companies` é gateado por `core.empresas` (não por role) — sem a
     * permissão o teste bate 403 antes de chegar na régua que ele mede.
     */
    private function comAcessoAEmpresas(User $u): User
    {
        $setor = Setor::firstOrCreate(
            ['slug' => 'acesso-empresas-157'],
            ['nome' => 'Acesso Empresas 157', 'active' => true]
        );
        \App\Models\SetorPermissao::firstOrCreate([
            'setor_id' => $setor->id, 'permission_key' => 'core.empresas',
        ]);
        if (! $setor->membros()->where('users.id', $u->id)->exists()) {
            $setor->membros()->attach($u->id, ['is_principal' => false, 'assigned_at' => now()]);
        }

        return $u;
    }

    private function comCargo(string $slug): User
    {
        $u = $this->comAcessoAEmpresas(User::factory()->create(['role' => 'consultor', 'active' => true]));
        $cargo = $this->cargo($slug);

        DB::table('user_setores')->insert([
            'user_id' => $u->id, 'setor_id' => $cargo->setor_id, 'cargo_id' => $cargo->id,
            'is_principal' => true, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    private function lider(): User
    {
        $u = $this->comCargo('estrategista');

        DB::table('setor_lideres')->insert([
            'setor_id' => $this->setorPerformance()->id,
            'user_id'  => $u->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    /** Empresa de Performance — o universo que `/companies` mostra. */
    private function empresa(): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Vis '.$n,
            'cnpj'   => "21.821.821/{$n}-81",
        ]);

        $servico = Servico::where('setor', Servico::SETOR_PERFORMANCE)
            ->where('ativo', true)
            ->firstOrFail();

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'valor_contratado' => 100, 'data_contratacao' => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento' => 10, 'ativo' => true,
        ]));

        return $empresa->fresh();
    }

    private function vincular(Company $c, User $u, string $role): void
    {
        $servicoId = ContratoServico::where('company_id', $c->id)->value('servico_id');

        DB::table('company_users')->insert([
            'company_id' => $c->id, 'user_id' => $u->id, 'role' => $role,
            'servico_id' => $servicoId, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int, string> nomes das empresas que a tela devolveu */
    private function empresasVisiveis(User $u): array
    {
        $props = $this->actingAs($u)->get(route('companies.index'))->assertOk()->viewData('page')['props'];

        $lista = $props['companies']['data'] ?? $props['companies'] ?? [];

        return collect($lista)->pluck('name')->all();
    }

    // ─── Quem PASSA a ver só as suas ────────────────────────────────────────

    public function test_analista_ve_apenas_as_empresas_em_que_esta_vinculado(): void
    {
        $minha  = $this->empresa();
        $alheia = $this->empresa();

        $analista = $this->comCargo('analista');
        $this->vincular($minha, $analista, 'analista');

        $visiveis = $this->empresasVisiveis($analista);

        $this->assertContains($minha->name, $visiveis);
        $this->assertNotContains($alheia->name, $visiveis, 'empresa de outro analista não pode aparecer.');
    }

    public function test_estrategista_ve_apenas_as_empresas_em_que_esta_vinculado(): void
    {
        $minha  = $this->empresa();
        $alheia = $this->empresa();

        $estrat = $this->comCargo('estrategista');
        $this->vincular($minha, $estrat, 'estrategista');

        $visiveis = $this->empresasVisiveis($estrat);

        $this->assertContains($minha->name, $visiveis);
        $this->assertNotContains($alheia->name, $visiveis);
    }

    public function test_qualquer_papel_no_vinculo_conta_nao_so_o_do_cargo(): void
    {
        // Um analista que está na empresa como `consultor` continua vendo — o
        // filtro é sobre ESTAR vinculado, não sobre o papel do vínculo.
        $empresa  = $this->empresa();
        $analista = $this->comCargo('analista');
        $this->vincular($empresa, $analista, 'consultor');

        $this->assertContains($empresa->name, $this->empresasVisiveis($analista));
    }

    // ─── Quem NÃO perde a visão ampla ───────────────────────────────────────

    /**
     * O líder precisa ver tudo para distribuir. O Luiz é líder E estrategista;
     * a regra de líder vence.
     */
    public function test_lider_do_setor_performance_continua_vendo_todas(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();

        $lider = $this->lider();

        $visiveis = $this->empresasVisiveis($lider);

        $this->assertContains($a->name, $visiveis);
        $this->assertContains($b->name, $visiveis, 'o líder precisa enxergar empresa sem responsável para distribuí-la.');
    }

    public function test_admin_continua_vendo_todas(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();

        $visiveis = $this->empresasVisiveis(User::factory()->create(['role' => 'admin']));

        $this->assertContains($a->name, $visiveis);
        $this->assertContains($b->name, $visiveis);
    }

    /**
     * A régua é sobre CARGO, não sobre "não-admin". Quem não tem cargo de
     * analista nem de estrategista mantém o comportamento de antes — restringir
     * por exclusão tiraria acesso de gente que o pedido não menciona.
     */
    public function test_usuario_sem_cargo_de_analista_ou_estrategista_nao_e_filtrado(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();

        $semCargo = $this->comAcessoAEmpresas(User::factory()->create(['role' => 'consultor', 'active' => true]));

        $visiveis = $this->empresasVisiveis($semCargo);

        $this->assertContains($a->name, $visiveis);
        $this->assertContains($b->name, $visiveis);
    }

    // ─── D-B — o setor Performance existe e casa com o catálogo ─────────────

    /**
     * Guarda da migration: sem esta linha, "líder do setor" não tem como ser
     * expresso, e o seletor de analista/estrategista da Fase 154 cai sempre no
     * fallback "mostrando todos" justamente para as empresas de Performance,
     * que são a maioria.
     */
    public function test_o_setor_performance_existe_e_casa_com_o_catalogo_de_servicos(): void
    {
        $setor = Setor::where('slug', 'performance')->first();

        $this->assertNotNull($setor, 'a migration da Fase 157 precisa ter criado o setor.');
        $this->assertTrue((bool) $setor->active);
        $this->assertSame(
            Servico::SETOR_PERFORMANCE,
            $setor->slug,
            'o slug do setor casa com o valor que servicos.setor já usa — é isso que faz os dois vocabulários se encontrarem.'
        );
    }
}
