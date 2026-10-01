<?php

namespace Tests\Feature\PpaQuadro;

use App\Models\Company;
use App\Models\Ppa;
use App\Models\PpaTask;
use App\Models\User;
use App\Services\Portal\PortalPpaService;
use App\Services\Ppa\PpaListaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editar e criar card direto na lista interna de PPA (23/09/2026).
 *
 * Pedido: "no PPA não tem como editar os cards, os títulos, adicionar novos
 * cards". Clicar no card abre `DialogTarefa`, e cada coluna ganhou o seu
 * "Adicionar tarefa".
 *
 * ### O que estes testes protegem
 * 1. **Que o diálogo abre com o que está gravado.** A lista fala o payload do
 *    PORTAL, que não traz área, prioridade nem prazo em ISO. Sem eles o diálogo
 *    abriria em branco — e salvar apagaria os três, calado.
 * 2. **Que esses campos NÃO vazam para o cliente.** Entram em
 *    `PpaListaService`, nunca em `PortalPpaService::visao()`.
 * 3. **Que título vazio é recusado com 422, não com 500.** O diálogo deixa
 *    apagar o campo, e `ppa_tasks.title` é NOT NULL.
 */
class PpaEditarNaListaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function ppa(): Ppa
    {
        $empresa = Company::create([
            'name' => 'Empresa PPA '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);

        return Ppa::create([
            'escopo'     => Ppa::ESCOPO_GERAL,
            'company_id' => $empresa->id,
            'mentor_id'  => $this->admin()->id,
            'title'      => 'Plano',
            'status'     => 'sent',
        ]);
    }

    #[Test]
    public function a_lista_interna_manda_o_que_o_dialogo_de_edicao_precisa(): void
    {
        $ppa = $this->ppa();
        PpaTask::create([
            'ppa_id' => $ppa->id, 'title' => 'Revisar preços', 'status' => 'done', 'order' => 0,
            'area' => 'Estratégia', 'prioridade' => 'alta', 'prazo' => '2026-10-05',
            'concluida_em' => '2026-09-20 10:00:00',
        ]);
        PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Tarefa antiga', 'status' => 'todo', 'order' => 1]);

        $linha = app(PpaListaService::class)->linha($ppa->fresh()->load('tasks'));

        $completa = $linha['tarefas'][0];
        $this->assertSame('Revisar preços', $completa['titulo']);
        $this->assertSame('Estratégia', $completa['area']);
        $this->assertSame('alta', $completa['prioridade']);
        $this->assertSame('2026-10-05', $completa['prazo_iso']);
        $this->assertSame('20/09/2026', $completa['concluida_em']);

        // Tarefa de antes dos campos novos: tudo nulo, nada inventado.
        $antiga = $linha['tarefas'][1];
        $this->assertSame('Tarefa antiga', $antiga['titulo']);
        $this->assertNull($antiga['area']);
        $this->assertNull($antiga['prioridade']);
        $this->assertNull($antiga['prazo_iso']);
    }

    #[Test]
    public function area_e_prioridade_continuam_fora_do_payload_do_cliente(): void
    {
        $ppa = $this->ppa();
        PpaTask::create([
            'ppa_id' => $ppa->id, 'title' => 'Com área', 'status' => 'todo', 'order' => 0,
            'area' => 'Estratégia', 'prioridade' => 'alta',
        ]);

        $tarefa = app(PortalPpaService::class)->visao($ppa->fresh()->load('tasks'))['tarefas'][0];

        foreach (['area', 'prioridade', 'concluida_em'] as $interno) {
            $this->assertArrayNotHasKey($interno, $tarefa);
        }
    }

    #[Test]
    public function editar_com_titulo_vazio_e_recusado_sem_apagar_o_titulo(): void
    {
        $ppa = $this->ppa();
        $task = PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Título bom', 'status' => 'todo', 'order' => 0]);

        $this->actingAs($this->admin())
            ->put(route('ppa.tasks.update', $task), ['title' => '', 'area' => 'Conteúdo'])
            ->assertSessionHasErrors('title');

        $this->assertSame('Título bom', $task->fresh()->title);
        $this->assertNull($task->fresh()->area);
    }

    #[Test]
    public function a_tarefa_nasce_na_coluna_em_que_foi_escrita(): void
    {
        $ppa = $this->ppa();

        $this->actingAs($this->admin())
            ->post(route('ppa.tasks.store', $ppa), ['title' => 'Direto em andamento', 'status' => 'doing'])
            ->assertRedirect();

        $this->assertSame('doing', $ppa->tasks()->where('title', 'Direto em andamento')->value('status'));
    }
}
