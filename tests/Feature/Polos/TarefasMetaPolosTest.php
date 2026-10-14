<?php

namespace Tests\Feature\Polos;

use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloAdsStatus;
use App\Models\PoloTarefaMeta;
use App\Models\Ppa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * TKT-0017 — tarefas do time dos Polos com meta editável (tela "Tarefas" do Modo TV).
 *
 * O que estes testes protegem:
 *   - o Painel entrega os cartões e os dois dados novos que a régua de "feito" usa
 *     (situação da campanha e "tem PPA"), sem chamada nova à Adman;
 *   - criar, editar e remover passam pelo mesmo gate operacional do Painel;
 *   - estágio-alvo só existe na Publicação (e cai em "Estágio 1" quando vem vazio);
 *   - remover é soft delete com histórico: o cartão sai da tela, a meta fica registrada;
 *   - o feito digitado só existe na contagem manual;
 *   - os "Próximos passos" do rodapé da TV gravam pelo mesmo gate e chegam ao Painel.
 *
 * A régua de "feito" por indicador está em tests/js/polosTarefas.test.js.
 *
 * @group polos
 */
class TarefasMetaPolosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A página Inertia não precisa do bundle para o teste ler as props.
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $nome, ?string $cust = null, array $extra = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'cust_id' => $cust,
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $extra));
    }

    private function propsDoPainel(User $user): array
    {
        $resposta = $this->actingAs($user)->get(route('mlb.polos-painel'));
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'indicador'      => 'places',
            'titulo'         => 'Places',
            'descricao'      => 'Ativar 39 empresas até sexta',
            'meta'           => 39,
            'responsavel_id' => null,
            'prazo'          => '2026-10-17',
        ], $extra);
    }

    // ─── Props do Painel ─────────────────────────────────────────────────────

    public function test_painel_entrega_as_tarefas_ativas_com_o_responsavel(): void
    {
        $admin = $this->admin();
        $carol = User::factory()->create(['name' => 'Carol']);

        $places = PoloTarefaMeta::create(['indicador' => 'places', 'titulo' => 'Places', 'meta' => 39, 'responsavel_id' => $carol->id, 'prazo' => '2026-10-17']);
        $removida = PoloTarefaMeta::create(['indicador' => 'me1', 'titulo' => 'ME1', 'meta' => 5]);
        $removida->delete();

        $tarefas = $this->propsDoPainel($admin)['tarefasMeta'];

        $this->assertCount(1, $tarefas);
        $this->assertSame($places->id, $tarefas[0]['id']);
        $this->assertSame('Carol', $tarefas[0]['responsavel_nome']);
        $this->assertSame('2026-10-17', $tarefas[0]['prazo']);
        $this->assertSame(39, $tarefas[0]['meta']);
    }

    public function test_cada_empresa_traz_a_situacao_da_campanha_e_se_tem_ppa(): void
    {
        $admin = $this->admin();

        $ativa    = $this->empresa('Com campanha ativa', '111');
        $parada   = $this->empresa('Marcada desligada à mão', '222', ['ads_desligado' => true]);
        $semNada  = $this->empresa('Sem leitura nem marcação', '333');
        PoloAdsStatus::create(['cust_id' => '111', 'campanhas_ativas' => 2, 'campanhas_total' => 3, 'verificado_em' => now()->subHour()]);
        Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $ativa->id,
            'mentor_id' => $admin->id, 'title' => 'Plano', 'status' => 'draft',
        ]);

        $linhas = collect($this->propsDoPainel($admin)['empresas'])->keyBy('id');

        $this->assertSame('ativa', $linhas[$ativa->id]['ads_campanha']);
        $this->assertSame('desligada', $linhas[$parada->id]['ads_campanha']);
        $this->assertNull($linhas[$semNada->id]['ads_campanha']);

        $this->assertTrue($linhas[$ativa->id]['tem_ppa']);
        $this->assertFalse($linhas[$parada->id]['tem_ppa']);
    }

    // ─── Criar / editar / remover ────────────────────────────────────────────

    public function test_admin_cria_tarefa_e_publicacao_cai_no_estagio_1_sem_alvo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados())
            ->assertOk()
            ->assertJsonPath('tarefa.indicador', 'places')
            ->assertJsonPath('tarefa.estagio_alvo', null);

        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['indicador' => 'publicacao', 'titulo' => 'Publicação', 'prazo' => null]))
            ->assertOk()
            ->assertJsonPath('tarefa.estagio_alvo', 'Estágio 1')
            ->assertJsonPath('tarefa.prazo', null);

        $this->assertSame(2, PoloTarefaMeta::count());
        $this->assertSame($admin->id, PoloTarefaMeta::first()->criado_por);
    }

    public function test_estagio_alvo_e_limpo_fora_da_publicacao(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['estagio_alvo' => 'Estágio 2']))
            ->assertOk()
            ->assertJsonPath('tarefa.estagio_alvo', null);
    }

    public function test_validacao_recusa_indicador_desconhecido_meta_negativa_e_estagio_fora_da_lista(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['indicador' => 'faturamento']))
            ->assertStatus(422)->assertJsonValidationErrors('indicador');
        $this->actingAs($admin)->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['meta' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors('meta');
        $this->actingAs($admin)->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['indicador' => 'publicacao', 'estagio_alvo' => 'Não Listado']))
            ->assertStatus(422)->assertJsonValidationErrors('estagio_alvo');

        $this->assertSame(0, PoloTarefaMeta::count());
    }

    public function test_editar_a_meta_registra_quem_mudou_e_o_historico(): void
    {
        $admin  = $this->admin();
        $tarefa = PoloTarefaMeta::create(['indicador' => 'decola', 'titulo' => 'Cobrar Decola', 'meta' => 120]);

        $this->actingAs($admin)
            ->putJson(route('mlb.polos-painel.tarefas.update', $tarefa), $this->dados(['indicador' => 'decola', 'titulo' => 'Cobrar Decola', 'meta' => 180]))
            ->assertOk()
            ->assertJsonPath('tarefa.meta', 180);

        $tarefa->refresh();
        $this->assertSame(180, $tarefa->meta);
        $this->assertSame($admin->id, $tarefa->atualizado_por);

        $log = Activity::where('subject_type', PoloTarefaMeta::class)->where('subject_id', $tarefa->id)
            ->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(120, $log->properties['old']['meta']);
        $this->assertSame(180, $log->properties['attributes']['meta']);
    }

    public function test_remover_tira_da_tela_sem_apagar(): void
    {
        $admin  = $this->admin();
        $tarefa = PoloTarefaMeta::create(['indicador' => 'ppa', 'titulo' => 'PPA', 'meta' => 10]);

        $this->actingAs($admin)
            ->deleteJson(route('mlb.polos-painel.tarefas.destroy', $tarefa))
            ->assertOk();

        $this->assertSoftDeleted('polos_tarefas_metas', ['id' => $tarefa->id]);
        $this->assertSame([], $this->propsDoPainel($admin)['tarefasMeta']);
    }

    public function test_sem_acesso_ao_modulo_de_polos_nao_cria_edita_nem_remove(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $tarefa    = PoloTarefaMeta::create(['indicador' => 'me1', 'titulo' => 'ME1', 'meta' => 5]);

        $this->actingAs($consultor)->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados())->assertForbidden();
        $this->actingAs($consultor)->putJson(route('mlb.polos-painel.tarefas.update', $tarefa), $this->dados())->assertForbidden();
        $this->actingAs($consultor)->deleteJson(route('mlb.polos-painel.tarefas.destroy', $tarefa))->assertForbidden();

        $this->assertSame(1, PoloTarefaMeta::count());
        $this->assertSame(5, $tarefa->fresh()->meta);
    }

    // ─── Contagem manual ─────────────────────────────────────────────────────

    public function test_feito_digitado_so_vale_na_contagem_manual(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados([
                'indicador' => 'manual', 'titulo' => 'Integração de anúncios ME1',
                'descricao' => 'Artri, Móveis Podenski, Nobre Comercial, TELASUL', 'meta' => 4, 'feito_manual' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('tarefa.feito_manual', 1);

        // Num indicador automático o número digitado é descartado: o feito sai das empresas.
        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['feito_manual' => 30]))
            ->assertOk()
            ->assertJsonPath('tarefa.feito_manual', null);

        $this->actingAs($admin)->postJson(route('mlb.polos-painel.tarefas.store'), $this->dados(['indicador' => 'manual', 'feito_manual' => -1]))
            ->assertStatus(422)->assertJsonValidationErrors('feito_manual');

        $manual = collect($this->propsDoPainel($admin)['tarefasMeta'])->firstWhere('indicador', 'manual');
        $this->assertSame(1, $manual['feito_manual']);
    }

    // ─── Próximos passos (rodapé da TV) ──────────────────────────────────────

    public function test_proximos_passos_gravam_sem_linha_vazia_e_chegam_ao_painel(): void
    {
        $admin = $this->admin();

        $this->assertSame([], $this->propsDoPainel($admin)['proximosPassos']);

        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.proximos-passos'), [
                'itens' => ['  Acompanhar evolução diária das tarefas ', '', 'Reforçar prazos com o time', null],
            ])
            ->assertOk()
            ->assertJsonPath('itens', ['Acompanhar evolução diária das tarefas', 'Reforçar prazos com o time']);

        $this->assertSame(
            ['Acompanhar evolução diária das tarefas', 'Reforçar prazos com o time'],
            $this->propsDoPainel($admin)['proximosPassos']
        );

        // Lista vazia apaga o bloco.
        $this->actingAs($admin)->postJson(route('mlb.polos-painel.tarefas.proximos-passos'), ['itens' => []])->assertOk();
        $this->assertSame([], $this->propsDoPainel($admin)['proximosPassos']);
    }

    public function test_proximos_passos_recusam_lista_longa_e_quem_nao_tem_acesso(): void
    {
        $admin = $this->admin();
        Configuracao::set(PoloTarefaMeta::CHAVE_PROXIMOS_PASSOS, json_encode(['Antes']));

        $this->actingAs($admin)
            ->postJson(route('mlb.polos-painel.tarefas.proximos-passos'), ['itens' => array_fill(0, 7, 'Passo')])
            ->assertStatus(422)->assertJsonValidationErrors('itens');

        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)
            ->postJson(route('mlb.polos-painel.tarefas.proximos-passos'), ['itens' => ['Outro']])
            ->assertForbidden();

        $this->assertSame(['Antes'], PoloTarefaMeta::proximosPassos());
    }
}
