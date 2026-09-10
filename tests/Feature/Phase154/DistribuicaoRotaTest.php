<?php

namespace Tests\Feature\Phase154;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 154 — a fila e a distribuição pela porta HTTP.
 *
 * O `DistribuicaoServiceTest` prova a régua no nível de service; este prova a
 * rota, a permissão nova e que o gate da DISTRIB-02 vale no SERVIDOR, não só no
 * select da tela.
 */
class DistribuicaoRotaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function setor(string $slug, string $nome): Setor
    {
        return Setor::firstOrCreate(['slug' => $slug], ['nome' => $nome, 'active' => true]);
    }

    private function cargo(Setor $setor, string $slug): Cargo
    {
        return Cargo::firstOrCreate(['setor_id' => $setor->id, 'slug' => $slug], ['nome' => ucfirst($slug)]);
    }

    private function colaborador(Cargo $cargo, string $nome): User
    {
        $u = User::factory()->create(['role' => 'consultor', 'name' => $nome, 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id' => $u->id, 'setor_id' => $cargo->setor_id, 'cargo_id' => $cargo->id,
            'is_principal' => true, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $u;
    }

    private function coordenadorComPermissao(): User
    {
        $setor = Setor::firstOrCreate(['slug' => 'coordenacao-154'], ['nome' => 'Coordenação 154', 'active' => true]);
        SetorPermissao::firstOrCreate([
            'setor_id' => $setor->id, 'permission_key' => Permissions::COORDENACAO_DISTRIBUIR,
        ]);
        $u = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($u->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $u;
    }

    /** @return array{empresa: Company, analista: User, estrategista: User} */
    private function cenario(): array
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);
        $setor = $this->setor('publicacao', 'Publicação');

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa Rota '.$n,
            'cnpj'   => "18.518.518/{$n}-51",
            'etapa'  => Company::ETAPA_AGUARDANDO_DISTRIBUICAO,
        ]);

        $servico = Servico::create([
            'nome' => 'Publicação Rota '.$n, 'valor_padrao' => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
            'setor' => 'publicacao', 'exige_contrato' => true,
        ]);

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'valor_contratado' => 100, 'data_contratacao' => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento' => 10, 'ativo' => true,
        ]));

        return [
            'empresa'      => $empresa->fresh(),
            'analista'     => $this->colaborador($this->cargo($setor, 'analista'), 'Analista Rota '.$n),
            'estrategista' => $this->colaborador($this->cargo($setor, 'estrategista'), 'Estrategista Rota '.$n),
        ];
    }

    // ─── Permissão (D-G) ────────────────────────────────────────────────────

    public function test_as_duas_rotas_usam_a_chave_propria_e_nunca_role_admin(): void
    {
        foreach (['coordenacao.distribuicao.index', 'coordenacao.distribuicao.distribuir'] as $nome) {
            $rota = Route::getRoutes()->getByName($nome);
            $this->assertNotNull($rota, "A rota {$nome} precisa existir.");

            $mw = $rota->gatherMiddleware();
            $this->assertContains('permission:'.Permissions::COORDENACAO_DISTRIBUIR, $mw);
            $this->assertNotContains('role:admin', $mw, 'a chave precisa ser liberável por setor, sem deploy.');
        }
    }

    public function test_quem_nao_tem_a_chave_recebe_403(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($user)->get(route('coordenacao.distribuicao.index'))->assertStatus(403);
    }

    public function test_o_catalogo_expoe_a_chave_nova(): void
    {
        $chaves = collect(Permissions::catalog())->flatten(1)->pluck('key')->all();

        $this->assertContains(Permissions::COORDENACAO_DISTRIBUIR, $chaves);
    }

    // ─── DISTRIB-01 — a fila na tela ────────────────────────────────────────

    public function test_a_fila_chega_no_payload_com_os_elegiveis_por_empresa(): void
    {
        $c = $this->cenario();

        $props = $this->actingAs($this->coordenadorComPermissao())
            ->get(route('coordenacao.distribuicao.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $linha = $props['empresas'][0];

        $this->assertSame($c['empresa']->id, $linha['id']);
        $this->assertContains($c['analista']->id, array_column($linha['analistas'], 'id'));
        $this->assertContains($c['estrategista']->id, array_column($linha['estrategistas'], 'id'));
        $this->assertNotEmpty($linha['servicos']);
    }

    // ─── DISTRIB-03/04 — distribuir pela rota ───────────────────────────────

    public function test_distribuir_pela_rota_vincula_move_a_etapa_e_registra_o_coordenador(): void
    {
        $c = $this->cenario();
        $coord = $this->coordenadorComPermissao();

        $this->actingAs($coord)
            ->post(route('coordenacao.distribuicao.distribuir', $c['empresa']), [
                'analista_id'     => $c['analista']->id,
                'estrategista_id' => $c['estrategista']->id,
            ])
            ->assertStatus(302)
            ->assertSessionHas('success');

        // Reconsulta ao banco.
        $this->assertSame(Company::ETAPA_AGUARDANDO_ONBOARDING, Company::findOrFail($c['empresa']->id)->etapa);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $c['empresa']->id, 'role' => 'analista', 'user_id' => $c['analista']->id,
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $c['empresa']->id, 'role' => 'estrategista', 'user_id' => $c['estrategista']->id,
        ]);

        // DISTRIB-03 — o ator é o coordenador da SESSÃO.
        $linha = CompanyEtapaTransicao::where('company_id', $c['empresa']->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->firstOrFail();

        $this->assertSame($coord->id, $linha->user_id);
    }

    /**
     * DISTRIB-02 vale no SERVIDOR, não só no select. Um POST direto com usuário
     * inativo é recusado pela validação.
     */
    public function test_post_direto_com_usuario_inativo_e_recusado(): void
    {
        $c = $this->cenario();
        $c['analista']->update(['active' => false]);

        $this->actingAs($this->coordenadorComPermissao())
            ->post(route('coordenacao.distribuicao.distribuir', $c['empresa']), [
                'analista_id'     => $c['analista']->id,
                'estrategista_id' => $c['estrategista']->id,
            ])
            ->assertSessionHasErrors('analista_id');

        $this->assertSame(Company::ETAPA_AGUARDANDO_DISTRIBUICAO, Company::findOrFail($c['empresa']->id)->etapa);
        $this->assertSame(0, DB::table('company_users')->where('company_id', $c['empresa']->id)->count());
    }

    public function test_coordenador_id_no_corpo_e_ignorado(): void
    {
        $c = $this->cenario();
        $coord    = $this->coordenadorComPermissao();
        $terceiro = User::factory()->create(['role' => 'admin']);

        $this->actingAs($coord)
            ->post(route('coordenacao.distribuicao.distribuir', $c['empresa']), [
                'analista_id'     => $c['analista']->id,
                'estrategista_id' => $c['estrategista']->id,
                'coordenador_id'  => $terceiro->id,
            ])
            ->assertStatus(302);

        $linha = CompanyEtapaTransicao::where('company_id', $c['empresa']->id)
            ->where('etapa_nova', Company::ETAPA_AGUARDANDO_ONBOARDING)
            ->firstOrFail();

        $this->assertSame($coord->id, $linha->user_id, 'autoria vinda do corpo é forjável e tem de ser ignorada.');
        $this->assertNotSame($terceiro->id, $linha->user_id);
    }

    /** Depois de distribuída, a empresa sai da fila — sem ação de ninguém. */
    public function test_empresa_distribuida_sai_da_fila(): void
    {
        $c = $this->cenario();
        $coord = $this->coordenadorComPermissao();

        $this->actingAs($coord)->post(route('coordenacao.distribuicao.distribuir', $c['empresa']), [
            'analista_id'     => $c['analista']->id,
            'estrategista_id' => $c['estrategista']->id,
        ])->assertStatus(302);

        $props = $this->actingAs($coord)
            ->get(route('coordenacao.distribuicao.index'))
            ->viewData('page')['props'];

        $this->assertCount(0, $props['empresas']);
    }
}
