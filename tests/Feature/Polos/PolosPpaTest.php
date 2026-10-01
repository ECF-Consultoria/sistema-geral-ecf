<?php

namespace Tests\Feature\Polos;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\Ppa;
use App\Models\PpaTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PPA Polos (quick 260805-dzu): réplica do módulo PPA recortada nas empresas do
 * projeto POLOS. Divide a tabela `ppas` com o PPA de carteira via coluna `escopo`
 * — o que este teste garante é que os dois não se misturam e que o alvo é
 * MlbEmpresa (não Company).
 *
 * @group polos
 */
class PolosPpaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresaPolos(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Polo ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $opts));
    }

    // ─── Listagem ────────────────────────────────────────────────────────────

    public function test_index_lista_apenas_ppas_do_escopo_polos(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();

        $doPolo = Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $admin->id, 'title' => 'Plano do polo', 'status' => 'draft',
        ]);
        Ppa::create([
            'escopo' => Ppa::ESCOPO_GERAL, 'company_id' => Company::factory()->create()->id,
            'mentor_id' => $admin->id, 'title' => 'Plano da carteira', 'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->get(route('mlb.polos-ppa.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Ppa/Index')
                ->where('escopo', Ppa::ESCOPO_POLOS)
                ->has('ppas.data', 1)
                ->where('ppas.data.0.id', $doPolo->id)
                ->where('ppas.data.0.empresa', $empresa->nome)
            );
    }

    public function test_ppa_de_carteira_nao_mostra_os_de_polos(): void
    {
        $admin = $this->admin();

        Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $this->empresaPolos()->id,
            'mentor_id' => $admin->id, 'title' => 'Plano do polo', 'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->get(route('ppa.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p->component('Ppa/Index')->has('ppas.data', 0));
    }

    public function test_select_traz_so_empresas_polos_ativas(): void
    {
        $ativa = $this->empresaPolos(['nome' => 'Polo Ativo']);
        $this->empresaPolos(['nome' => 'Polo Arquivado', 'arquivado_em' => now()]);
        $this->empresaPolos(['nome' => 'Assessoria', 'projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']);

        $this->actingAs($this->admin())
            ->get(route('mlb.polos-ppa.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->has('companies', 1)
                ->where('companies.0.id', $ativa->id)
                ->where('companies.0.name', 'Polo Ativo')
            );
    }

    /**
     * A tela do PPA Polos é o MESMO componente da lista de carteira
     * (`Pages/Ppa/Index.jsx`, re-exportado). Os filtros desenham lá de
     * qualquer jeito — se o controller de Polos não os aplicasse, clicar em
     * "Vencidos" aqui recarregaria a lista inteira e pareceria um botão morto.
     */
    public function test_index_filtra_por_situacao_e_devolve_os_filtros_aplicados(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();

        $base = [
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $admin->id, 'status' => 'sent',
        ];

        $vencido = Ppa::create([...$base, 'title' => 'Atrasado', 'due_date' => now()->subDays(3)->toDateString()]);
        Ppa::create([...$base, 'title' => 'No prazo', 'due_date' => now()->addDays(3)->toDateString()]);

        $this->actingAs($admin)
            ->get(route('mlb.polos-ppa.index', ['situacao' => Ppa::SITUACAO_VENCIDO]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Ppa/Index')
                ->has('ppas.data', 1)
                ->where('ppas.data.0.id', $vencido->id)
                ->where('filtros.situacao', Ppa::SITUACAO_VENCIDO)
            );
    }

    // ─── Criação ─────────────────────────────────────────────────────────────

    public function test_store_cria_ppa_ligado_a_empresa_polo(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();

        $this->actingAs($admin)
            ->post(route('mlb.polos-ppa.store'), [
                'company_id' => $empresa->id,
                'title'      => 'Plano de agosto',
            ])
            ->assertRedirect();

        $ppa = Ppa::firstOrFail();
        $this->assertSame(Ppa::ESCOPO_POLOS, $ppa->escopo);
        $this->assertSame($empresa->id, $ppa->mlb_empresa_id);
        $this->assertNull($ppa->company_id);
        $this->assertSame($empresa->nome, $ppa->nomeEmpresa());
    }

    public function test_store_recusa_empresa_fora_do_projeto_polos(): void
    {
        $fora = $this->empresaPolos(['projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']);

        $this->actingAs($this->admin())
            ->post(route('mlb.polos-ppa.store'), ['company_id' => $fora->id, 'title' => 'X'])
            ->assertStatus(422);

        $this->assertSame(0, Ppa::count());
    }

    // ─── Escopo cruzado ──────────────────────────────────────────────────────

    public function test_rotas_de_polos_nao_alcancam_ppa_de_carteira(): void
    {
        $admin = $this->admin();
        $geral = Ppa::create([
            'escopo' => Ppa::ESCOPO_GERAL, 'company_id' => Company::factory()->create()->id,
            'mentor_id' => $admin->id, 'title' => 'Plano da carteira', 'status' => 'draft',
        ]);

        $this->actingAs($admin)->get(route('mlb.polos-ppa.kanban', $geral->id))->assertStatus(404);
        $this->actingAs($admin)->delete(route('mlb.polos-ppa.destroy', $geral->id))->assertStatus(404);
        $this->assertNotNull($geral->fresh());
    }

    public function test_kanban_abre_com_as_tarefas_do_plano(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();
        $ppa     = Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $admin->id, 'title' => 'Plano do polo', 'status' => 'draft',
        ]);
        $ppa->tasks()->create(['title' => 'Subir anúncios', 'status' => 'todo', 'order' => 0, 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('mlb.polos-ppa.kanban', $ppa->id))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Polos/Ppa/Kanban')
                ->where('ppa.company_name', $empresa->nome)
                ->has('tasks', 1)
                ->where('tasks.0.title', 'Subir anúncios')
            );
    }

    // ─── Gaveta do Painel Polos ──────────────────────────────────────────────

    public function test_gaveta_traz_so_os_ppas_da_empresa_com_progresso_e_link_do_quadro(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();
        $outra   = $this->empresaPolos();

        $plano = Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $admin->id, 'title' => 'Plano de publicação', 'status' => 'sent',
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        foreach (['done', 'doing', 'todo'] as $i => $status) {
            PpaTask::create(['ppa_id' => $plano->id, 'title' => "T{$i}", 'status' => $status, 'order' => $i]);
        }
        Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $outra->id,
            'mentor_id' => $admin->id, 'title' => 'Plano de outra empresa', 'status' => 'sent',
        ]);

        $this->actingAs($admin)
            ->getJson(route('mlb.polos-ppa.empresa', $empresa))
            ->assertOk()
            ->assertJsonCount(1, 'ppas')
            ->assertJsonPath('ppas.0.id', $plano->id)
            ->assertJsonPath('ppas.0.titulo', 'Plano de publicação')
            ->assertJsonPath('ppas.0.escopo', Ppa::ESCOPO_POLOS)
            ->assertJsonPath('ppas.0.total', 3)
            ->assertJsonPath('ppas.0.feitas', 1)
            ->assertJsonPath('ppas.0.fazendo', 1)
            ->assertJsonPath('ppas.0.prazo_dias', -2)
            ->assertJsonPath('ppas.0.responsavel', $admin->name)
            ->assertJsonPath('ppas.0.url', route('mlb.polos-ppa.kanban', $plano));
    }

    /**
     * Rascunho entra (a gaveta é da equipe) e a ordem é a régua de atenção da
     * lista: plano com tarefa andando antes do que não começou, concluído por
     * último.
     */
    public function test_gaveta_inclui_rascunho_e_ordena_pela_regua_de_atencao(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresaPolos();
        $base    = ['escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id, 'mentor_id' => $admin->id];

        $concluido = Ppa::create([...$base, 'title' => 'Encerrado', 'status' => 'completed']);
        $rascunho  = Ppa::create([...$base, 'title' => 'Rascunho', 'status' => 'draft']);
        $andando   = Ppa::create([...$base, 'title' => 'Andando', 'status' => 'sent']);
        PpaTask::create(['ppa_id' => $andando->id, 'title' => 'Em curso', 'status' => 'doing', 'order' => 0]);

        $this->actingAs($admin)
            ->getJson(route('mlb.polos-ppa.empresa', $empresa))
            ->assertOk()
            ->assertJsonPath('ppas.*.id', [$andando->id, $rascunho->id, $concluido->id]);
    }

    public function test_gaveta_traz_o_ppa_de_carteira_quando_a_empresa_tem_company(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $empresa = $this->empresaPolos(['company_id' => $company->id]);

        $carteira = Ppa::create([
            'escopo' => Ppa::ESCOPO_GERAL, 'company_id' => $company->id,
            'mentor_id' => $admin->id, 'title' => 'Plano da carteira', 'status' => 'sent',
        ]);

        $this->actingAs($admin)
            ->getJson(route('mlb.polos-ppa.empresa', $empresa))
            ->assertOk()
            ->assertJsonCount(1, 'ppas')
            ->assertJsonPath('ppas.0.escopo', Ppa::ESCOPO_GERAL)
            // Abre no quadro de carteira: o de Polos daria 404 (garantirEscopo).
            ->assertJsonPath('ppas.0.url', route('ppa.kanban', $carteira));
    }

    /**
     * A gaveta mostra os planos da EMPRESA, não só os de quem olha — ao
     * contrário da lista, que recorta por `mentor_id` para o não-admin.
     */
    public function test_gaveta_mostra_planos_de_outros_responsaveis_para_quem_tem_permissao(): void
    {
        $empresa = $this->empresaPolos();
        Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $this->admin()->id, 'title' => 'Plano de outro', 'status' => 'sent',
        ]);

        $this->actingAs($this->userComPermissao('mlb.projetos'))
            ->getJson(route('mlb.polos-ppa.empresa', $empresa))
            ->assertOk()
            ->assertJsonCount(1, 'ppas');
    }

    // ─── Acesso ──────────────────────────────────────────────────────────────

    public function test_sem_permissao_mlb_projetos_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('mlb.polos-ppa.index'))
            ->assertStatus(403);
    }

    public function test_gaveta_sem_permissao_mlb_projetos_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson(route('mlb.polos-ppa.empresa', $this->empresaPolos()))
            ->assertStatus(403);
    }

    private function userComPermissao(string $permission): User
    {
        $slug    = 'setor-' . Str::random(6);
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor ' . $slug, 'slug' => $slug, 'active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('setor_permissoes')->insert([
            'setor_id' => $setorId, 'permission_key' => $permission,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);
        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId, 'cargo_id' => null, 'is_principal' => true,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }
}
