<?php

namespace Tests\Feature\PpaQuadro;

use App\Models\Company;
use App\Models\Ppa;
use App\Models\PpaColuna;
use App\Models\PpaTask;
use App\Models\User;
use App\Services\Portal\PortalPpaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O quadro do PPA depois do redesign de 21/08/2026 — arraste, colunas extras e
 * os campos do card.
 *
 * ### O que estes testes protegem
 * 1. **Que o `status` continua sendo a verdade.** Coluna extra é um refinamento
 *    por cima dele. Se um dia uma tarefa passar a viver só na coluna extra, o
 *    Portal do Cliente (que desenha três colunas) e todos os contadores de
 *    progresso param de enxergá-la — e o sintoma seria silencioso.
 * 2. **Que as três colunas fixas são intocáveis.** Não existe rota capaz de
 *    renomear, mover ou apagar `todo`/`doing`/`done`.
 * 3. **Que apagar uma coluna não apaga trabalho.** As tarefas voltam à coluna
 *    base.
 */
class PpaQuadroTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa PPA '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    /** @param array<int, array{0:string,1:string}> $tarefas */
    private function ppa(array $tarefas = [], string $status = 'sent'): Ppa
    {
        $ppa = Ppa::create([
            'escopo' => Ppa::ESCOPO_GERAL, 'company_id' => $this->empresa()->id,
            'mentor_id' => $this->admin()->id, 'title' => 'Plano '.uniqid(), 'status' => $status,
        ]);

        foreach ($tarefas as $i => [$t, $s]) {
            PpaTask::create(['ppa_id' => $ppa->id, 'title' => $t, 'status' => $s, 'order' => $i]);
        }

        return $ppa->fresh();
    }

    // ─── O payload do quadro ────────────────────────────────────────────────

    #[Test]
    public function quadro_traz_as_tres_colunas_fixas_na_ordem(): void
    {
        $ppa = $this->ppa();

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $ppa))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ppa/Kanban')
                ->has('colunas', 3)
                ->where('colunas.0.key', 'todo')
                ->where('colunas.1.key', 'doing')
                ->where('colunas.2.key', 'done')
                // Fixa = sem menu de editar/remover na tela.
                ->where('colunas.0.fixa', true)
                ->where('colunas.2.fixa', true)
            );
    }

    /**
     * A coluna extra entra logo DEPOIS da base a que pertence — nunca no fim do
     * quadro nem antes de `todo`.
     */
    #[Test]
    public function coluna_extra_encaixa_apos_a_base_dela(): void
    {
        $ppa = $this->ppa();
        PpaColuna::create(['ppa_id' => $ppa->id, 'nome' => 'Aguardando Cliente', 'status_base' => 'doing', 'cor' => 'sky']);

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $ppa))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('colunas', 4)
                ->where('colunas.1.key', 'doing')
                ->where('colunas.2.nome', 'Aguardando Cliente')
                ->where('colunas.2.fixa', false)
                ->where('colunas.3.key', 'done')
            );
    }

    #[Test]
    public function resumo_traz_progresso_prazo_e_visibilidade(): void
    {
        $ppa = $this->ppa([['A', 'done'], ['B', 'todo'], ['C', 'doing'], ['D', 'todo']]);
        $ppa->update(['due_date' => now()->addDays(10)]);

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $ppa))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resumo.progresso.feitas', 1)
                ->where('resumo.progresso.total', 4)
                ->where('resumo.progresso.pct', 25)
                ->where('resumo.prazo.definido', true)
                ->where('resumo.prazo.dias', 10)
                ->where('resumo.visibilidade.compartilhado', true)
            );
    }

    /**
     * A régua de visibilidade é IMPORTADA do Portal do Cliente. A tela interna
     * não pode dizer "o cliente vê" sobre um plano que o portal esconde.
     */
    #[Test]
    public function visibilidade_segue_a_regua_do_portal(): void
    {
        $rascunho = $this->ppa([], 'draft');

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $rascunho))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resumo.visibilidade.compartilhado', false)
                ->where('resumo.visibilidade.rotulo', 'Somente interno')
                ->where('resumo.visibilidade.portal_url', null)
            );

        $this->assertNotContains('draft', PortalPpaService::STATUS_VISIVEIS);
    }

    // ─── Arraste ────────────────────────────────────────────────────────────

    #[Test]
    public function arrastar_para_outra_coluna_persiste_o_status(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $task = $ppa->tasks()->first();

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'doing'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'doing']);

        $this->assertDatabaseHas('ppa_tasks', ['id' => $task->id, 'status' => 'doing']);
    }

    /**
     * O que faz o Portal do Cliente e os contadores continuarem funcionando:
     * soltar numa coluna extra grava o `status_base` dela em `status`.
     */
    #[Test]
    public function arrastar_para_coluna_extra_grava_o_status_base(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $task = $ppa->tasks()->first();
        $extra = PpaColuna::create(['ppa_id' => $ppa->id, 'nome' => 'Em Revisão', 'status_base' => 'doing']);

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'doing', 'coluna_id' => $extra->id])
            ->assertOk();

        $this->assertDatabaseHas('ppa_tasks', [
            'id' => $task->id, 'status' => 'doing', 'coluna_id' => $extra->id,
        ]);
    }

    /**
     * Coluna extra de OUTRO plano não pode capturar a tarefa — o card iria para
     * um quadro que não é o dele.
     */
    #[Test]
    public function coluna_extra_de_outro_ppa_e_ignorada(): void
    {
        $meu    = $this->ppa([['Tarefa', 'todo']]);
        $alheio = $this->ppa();
        $task = $meu->tasks()->first();
        $extraAlheia = PpaColuna::create(['ppa_id' => $alheio->id, 'nome' => 'Alheia', 'status_base' => 'todo']);

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'todo', 'coluna_id' => $extraAlheia->id])
            ->assertOk();

        $this->assertDatabaseHas('ppa_tasks', ['id' => $task->id, 'coluna_id' => null]);
    }

    /** Coluna cujo `status_base` não bate com o destino também é recusada. */
    #[Test]
    public function coluna_extra_de_outra_etapa_e_ignorada(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $task = $ppa->tasks()->first();
        $extra = PpaColuna::create(['ppa_id' => $ppa->id, 'nome' => 'Revisão', 'status_base' => 'doing']);

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'done', 'coluna_id' => $extra->id])
            ->assertOk();

        $this->assertDatabaseHas('ppa_tasks', ['id' => $task->id, 'status' => 'done', 'coluna_id' => null]);
    }

    #[Test]
    public function arrastar_persiste_a_ordem_dentro_da_coluna(): void
    {
        $ppa = $this->ppa([['A', 'todo'], ['B', 'todo'], ['C', 'todo']]);
        [$a, $b, $c] = $ppa->tasks()->orderBy('order')->get()->all();

        // C vai para o topo.
        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $c), [
                'status' => 'todo',
                'ordem'  => [$c->id, $a->id, $b->id],
            ])
            ->assertOk();

        $this->assertSame(0, $c->fresh()->order);
        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $b->fresh()->order);
    }

    /**
     * A trava da reordenação: um id de outro plano na lista não reescreve nada.
     * Sem ela a rota aceitaria reordenar tarefas de qualquer PPA do sistema.
     */
    #[Test]
    public function reordenar_nao_alcanca_tarefa_de_outro_ppa(): void
    {
        $meu    = $this->ppa([['Minha', 'todo']]);
        $alheio = $this->ppa([['Alheia', 'todo']]);

        $minha  = $meu->tasks()->first();
        $alheia = $alheio->tasks()->first();
        $alheia->update(['order' => 7]);

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $minha), [
                'status' => 'todo',
                'ordem'  => [$alheia->id, $minha->id],
            ])
            ->assertOk();

        $this->assertSame(7, $alheia->fresh()->order, 'A ordem de uma tarefa de outro PPA foi alterada.');
    }

    // ─── `concluida_em` ─────────────────────────────────────────────────────

    /**
     * `updated_at` não serve para "Concluída em": ele anda a cada correção de
     * vírgula. O carimbo é próprio e só muda na transição.
     */
    #[Test]
    public function concluir_carimba_a_data_e_reabrir_a_limpa(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $task = $ppa->tasks()->first();

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'done'])
            ->assertOk();

        $this->assertNotNull($task->fresh()->concluida_em);

        $this->actingAs($this->admin())
            ->patchJson(route('ppa.tasks.mover', $task), ['status' => 'doing'])
            ->assertOk();

        $this->assertNull($task->fresh()->concluida_em, 'Tarefa reaberta continuou com data de conclusão.');
    }

    /** O caminho do cliente usa o MESMO `moverPara()` — o carimbo vale dos dois lados. */
    #[Test]
    public function conclusao_pelo_cliente_tambem_carimba(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $ppa->update(['workspace_token' => 'token-de-teste-do-quadro']);
        $task = $ppa->tasks()->first();

        $this->patchJson(route('ppa.workspace.task.update', [$ppa->workspace_token, $task->id]), [
            'status' => 'done',
        ])->assertOk();

        $this->assertNotNull($task->fresh()->concluida_em);
    }

    // ─── Campos do card ─────────────────────────────────────────────────────

    #[Test]
    public function campos_do_card_sao_salvos_e_voltam_no_payload(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);
        $task = $ppa->tasks()->first();

        $this->actingAs($this->admin())
            ->put(route('ppa.tasks.update', $task), [
                'title'            => 'Revisar preços',
                'area'             => 'Estratégia',
                'prioridade'       => 'alta',
                'prazo'            => now()->addDays(5)->toDateString(),
                'responsavel_lado' => 'cliente',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $ppa))
            ->assertInertia(fn ($page) => $page
                ->where('tasks.0.area', 'Estratégia')
                ->where('tasks.0.prioridade', 'alta')
                ->where('tasks.0.responsavel_lado', 'cliente')
                ->where('tasks.0.prazo_dias', 5)
            );
    }

    /**
     * Tarefa antiga não tem nenhum campo novo, e isso precisa continuar válido:
     * exigir qualquer um deles impediria corrigir o título de uma tarefa criada
     * antes de eles existirem.
     */
    #[Test]
    public function tarefa_sem_os_campos_novos_continua_editavel(): void
    {
        $ppa = $this->ppa([['Tarefa antiga', 'todo']]);
        $task = $ppa->tasks()->first();

        $this->actingAs($this->admin())
            ->put(route('ppa.tasks.update', $task), ['title' => 'Título corrigido'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Título corrigido', $task->fresh()->title);
        $this->assertNull($task->fresh()->area);
    }

    #[Test]
    public function prioridade_invalida_e_recusada(): void
    {
        $ppa = $this->ppa([['Tarefa', 'todo']]);

        $this->actingAs($this->admin())
            ->put(route('ppa.tasks.update', $ppa->tasks()->first()), ['prioridade' => 'urgentissima'])
            ->assertSessionHasErrors('prioridade');
    }

    // ─── Colunas extras ─────────────────────────────────────────────────────

    #[Test]
    public function admin_cria_coluna_extra(): void
    {
        $ppa = $this->ppa();

        $this->actingAs($this->admin())
            ->post(route('ppa.colunas.store', $ppa), [
                'nome' => 'Aguardando Cliente', 'status_base' => 'doing', 'cor' => 'sky',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ppa_colunas', [
            'ppa_id' => $ppa->id, 'nome' => 'Aguardando Cliente', 'status_base' => 'doing',
        ]);
    }

    /**
     * `status_base` não é editável: trocá-lo moveria de etapa, de uma vez e sem
     * aviso, todas as tarefas da coluna — uma coluna de revisão virando
     * "Concluído" marcaria como feito trabalho que ninguém terminou, e isso
     * apareceria na hora no portal do cliente.
     */
    #[Test]
    public function editar_coluna_nao_muda_o_status_base(): void
    {
        $ppa = $this->ppa();
        $coluna = PpaColuna::create(['ppa_id' => $ppa->id, 'nome' => 'Em Revisão', 'status_base' => 'doing']);

        $this->actingAs($this->admin())
            ->put(route('ppa.colunas.update', $coluna), [
                'nome' => 'Revisão final', 'cor' => 'rose', 'status_base' => 'done',
            ])
            ->assertRedirect();

        $coluna->refresh();
        $this->assertSame('Revisão final', $coluna->nome);
        $this->assertSame('rose', $coluna->cor);
        $this->assertSame('doing', $coluna->status_base, 'O status_base foi alterado por uma edição de nome.');
    }

    /** Apagar coluna devolve as tarefas à base — nunca apaga trabalho. */
    #[Test]
    public function remover_coluna_devolve_as_tarefas_a_coluna_base(): void
    {
        $ppa = $this->ppa([['Tarefa', 'doing']]);
        $task = $ppa->tasks()->first();
        $coluna = PpaColuna::create(['ppa_id' => $ppa->id, 'nome' => 'Em Revisão', 'status_base' => 'doing']);
        $task->moverPara('doing', $coluna->id);

        $this->assertSame($coluna->id, $task->fresh()->coluna_id);

        $this->actingAs($this->admin())
            ->delete(route('ppa.colunas.destroy', $coluna))
            ->assertRedirect();

        $task->refresh();
        $this->assertNotNull($task, 'A tarefa foi apagada junto com a coluna.');
        $this->assertNull($task->coluna_id);
        $this->assertSame('doing', $task->status, 'A tarefa mudou de etapa ao perder a coluna.');
    }

    /**
     * As três colunas fixas não têm linha em `ppa_colunas` e não há rota capaz
     * de alterá-las — a proteção é estrutural, não uma verificação que alguém
     * possa esquecer.
     */
    #[Test]
    public function nao_existe_rota_que_altere_as_colunas_fixas(): void
    {
        $ppa = $this->ppa();

        $this->assertSame(0, PpaColuna::where('ppa_id', $ppa->id)->count());

        // Criar uma "coluna" chamada A Fazer não substitui a fixa: ela entra
        // como EXTRA, e a fixa continua lá.
        $this->actingAs($this->admin())
            ->post(route('ppa.colunas.store', $ppa), ['nome' => 'A Fazer', 'status_base' => 'todo']);

        $this->actingAs($this->admin())
            ->get(route('ppa.kanban', $ppa))
            ->assertInertia(fn ($page) => $page
                ->has('colunas', 4)
                ->where('colunas.0.key', 'todo')
                ->where('colunas.0.fixa', true)
            );
    }

    #[Test]
    public function status_base_invalido_e_recusado(): void
    {
        $ppa = $this->ppa();

        $this->actingAs($this->admin())
            ->post(route('ppa.colunas.store', $ppa), ['nome' => 'Qualquer', 'status_base' => 'arquivado'])
            ->assertSessionHasErrors('status_base');
    }
}
