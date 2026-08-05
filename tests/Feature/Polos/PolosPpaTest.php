<?php

namespace Tests\Feature\Polos;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\Ppa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->where('ppas.data.0.company_name', $empresa->nome)
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

    // ─── Acesso ──────────────────────────────────────────────────────────────

    public function test_sem_permissao_mlb_projetos_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('mlb.polos-ppa.index'))
            ->assertStatus(403);
    }
}
