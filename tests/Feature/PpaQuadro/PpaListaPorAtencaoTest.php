<?php

namespace Tests\Feature\PpaQuadro;

use App\Models\Company;
use App\Models\Ppa;
use App\Models\PpaTask;
use App\Models\User;
use App\Services\Portal\PortalPpaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A reorganização das listas de PPA (21/09/2026) — interna e do Portal.
 *
 * ### O que estes testes protegem
 * 1. **Que a ordem do banco concorda com o agrupamento da tela.** A lista
 *    interna pagina de 20 em 20 e a tela agrupa o que recebe. Se o banco
 *    mandasse os planos em outra ordem, a página 1 traria concluídos enquanto
 *    um plano em andamento esperaria na página 2 — e a seção "Em andamento"
 *    apareceria VAZIA numa lista que tem planos andando. O sintoma seria
 *    silencioso: nada quebra, só some.
 * 2. **Que as contagens que decidem o grupo existem no payload.** Sem
 *    `tasks_doing` (interno) e `fazendo` (portal), todo plano cai em "A fazer"
 *    e a hierarquia inteira vira decoração.
 * 3. **Que o prazo chega calculado do servidor.** É o que o JS não pode
 *    refazer sem o fuso de quem olha virar um dia de diferença.
 *
 * A régua equivalente do lado do JS tem teste próprio em
 * `tests/js/ppaAgrupamento.test.js` — os dois precisam concordar.
 */
class PpaListaPorAtencaoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `withoutVite()` de propósito: sem ele o teste passa a depender de um
     * `npm run build` recente, e quebra com "Vite manifest not found" — falha
     * que não diz nada sobre a lista de PPA.
     */
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

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa PPA '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    /**
     * @param array<int, string> $statusDasTarefas
     */
    private function ppa(
        string $titulo,
        array $statusDasTarefas = [],
        string $status = 'sent',
        ?string $prazo = null,
        ?Company $empresa = null,
        ?User $mentor = null,
    ): Ppa {
        $ppa = Ppa::create([
            'escopo'     => Ppa::ESCOPO_GERAL,
            'company_id' => ($empresa ?? $this->empresa())->id,
            'mentor_id'  => ($mentor ?? $this->admin())->id,
            'title'      => $titulo,
            'status'     => $status,
            'due_date'   => $prazo,
        ]);

        foreach ($statusDasTarefas as $i => $s) {
            PpaTask::create(['ppa_id' => $ppa->id, 'title' => "Tarefa $i", 'status' => $s, 'order' => $i]);
        }

        return $ppa->fresh();
    }

    // ─── A ordem que o banco entrega ────────────────────────────────────────

    #[Test]
    public function em_andamento_vem_antes_de_a_fazer_e_concluido_vai_por_ultimo(): void
    {
        $this->ppa('Encerrado pela equipe', ['todo', 'doing'], status: 'completed');
        $this->ppa('Tudo feito sem encerrar', ['done', 'done']);
        $this->ppa('Só a fazer', ['todo', 'todo']);
        $this->ppa('Tem tarefa andando', ['todo', 'doing']);

        $ordem = Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->comContagemDeTarefas()
            ->ordenadoPorAtencao()
            ->pluck('title')
            ->all();

        $this->assertSame([
            'Tem tarefa andando',
            'Só a fazer',
            // Os dois grupos de "acabou" descem juntos: o que a equipe
            // encerrou e o que ficou 100% feito sem ninguém voltar para
            // marcar. O segundo é o caso comum, e era ele que ocupava o topo
            // da lista antes desta reforma.
            'Encerrado pela equipe',
            'Tudo feito sem encerrar',
        ], $ordem);
    }

    #[Test]
    public function dentro_do_grupo_o_prazo_mais_apertado_vem_primeiro_e_sem_prazo_vai_por_ultimo(): void
    {
        $this->ppa('Sem prazo', ['doing']);
        $this->ppa('Vence em um mês', ['doing'], prazo: now()->addDays(30)->toDateString());
        $this->ppa('Atrasado', ['doing'], prazo: now()->subDays(5)->toDateString());
        $this->ppa('Vence hoje', ['doing'], prazo: now()->toDateString());

        $ordem = Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->comContagemDeTarefas()
            ->ordenadoPorAtencao()
            ->pluck('title')
            ->all();

        $this->assertSame(['Atrasado', 'Vence hoje', 'Vence em um mês', 'Sem prazo'], $ordem);
    }

    #[Test]
    public function plano_sem_nenhuma_tarefa_fica_com_o_que_ainda_nao_comecou(): void
    {
        $this->ppa('Ainda vazio');
        $this->ppa('Andando', ['doing']);
        $this->ppa('Fechado', ['done']);

        $ordem = Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->comContagemDeTarefas()
            ->ordenadoPorAtencao()
            ->pluck('title')
            ->all();

        // Divisão por zero jogaria o plano vazio em "100% concluído". Ele fica
        // no meio, junto do que não começou — que é onde há trabalho a fazer.
        $this->assertSame(['Andando', 'Ainda vazio', 'Fechado'], $ordem);
    }

    // ─── O payload da lista interna ─────────────────────────────────────────

    #[Test]
    public function a_lista_interna_manda_as_contagens_que_decidem_a_secao(): void
    {
        $admin = $this->admin();
        $this->ppa('Plano medido', ['todo', 'doing', 'doing', 'done'], prazo: now()->subDays(3)->toDateString(), mentor: $admin);

        $resposta = $this->actingAs($admin)->get(route('ppa.index'));

        $resposta->assertOk();
        $linha = $resposta->viewData('page')['props']['ppas']['data'][0];

        $this->assertSame(4, $linha['tasks_count']);
        $this->assertSame(1, $linha['tasks_done']);
        $this->assertSame(2, $linha['tasks_doing']);
        // Negativo = o prazo já passou. É o sinal do selo vermelho na linha.
        $this->assertSame(-3, $linha['due_date_dias']);
    }

    #[Test]
    public function prazo_de_plano_encerrado_nao_viaja_como_atraso(): void
    {
        $admin = $this->admin();
        $this->ppa('Fechado com prazo vencido', ['done'], status: 'completed', prazo: now()->subDays(40)->toDateString(), mentor: $admin);

        $resposta = $this->actingAs($admin)->get(route('ppa.index'));
        $linha = $resposta->viewData('page')['props']['ppas']['data'][0];

        // A data continua lá para consulta; o que some é a contagem de atraso,
        // que é o que pinta o selo. Plano fechado não cobra ninguém.
        $this->assertNotNull($linha['due_date']);
        $this->assertNull($linha['due_date_dias']);
    }

    // ─── O payload do Portal do Cliente ─────────────────────────────────────

    #[Test]
    public function a_visao_do_cliente_traz_as_contagens_o_prazo_e_o_lado_responsavel(): void
    {
        $empresa = $this->empresa();
        $ppa = $this->ppa('Plano do cliente', [], prazo: now()->addDays(2)->toDateString(), empresa: $empresa);

        PpaTask::create([
            'ppa_id' => $ppa->id, 'title' => 'Com o cliente', 'status' => 'doing', 'order' => 0,
            'responsavel_lado' => PpaTask::LADO_CLIENTE, 'prazo' => now()->subDay()->toDateString(),
        ]);
        PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Na fila', 'status' => 'todo', 'order' => 1]);
        PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Pronta', 'status' => 'done', 'order' => 2]);

        $visao = app(PortalPpaService::class)->visao($ppa->fresh()->load('tasks'));

        $this->assertSame(3, $visao['total']);
        $this->assertSame(1, $visao['feitas']);
        $this->assertSame(1, $visao['fazendo']);
        $this->assertSame(1, $visao['a_fazer']);
        $this->assertSame(33, $visao['pct']);
        $this->assertSame(2, $visao['prazo_dias']);

        $tarefa = $visao['tarefas'][0];
        $this->assertSame('Com o cliente', $tarefa['titulo']);
        $this->assertSame(PpaTask::LADO_CLIENTE, $tarefa['responsavel_lado']);
        $this->assertSame(-1, $tarefa['prazo_dias']);

        // Tarefa concluída não carrega atraso: o selo vermelho no card de algo
        // já feito seria alarme sobre trabalho que acabou.
        $this->assertNull($visao['tarefas'][2]['prazo_dias']);
    }

    #[Test]
    public function o_que_o_cliente_nao_recebe_continua_fora_do_payload(): void
    {
        $ppa = $this->ppa('Plano com dado interno', ['todo']);
        $ppa->update(['trello_board_url' => 'https://trello.com/b/interno', 'workspace_token' => 'tok-123']);

        $visao = app(PortalPpaService::class)->visao($ppa->fresh()->load('tasks'));

        // A reforma da tela acrescentou campos ao payload do cliente (prazo,
        // lado, contagens). Nenhum deles pode ter arrastado junto o que sempre
        // foi interno.
        foreach (['trello_board_url', 'workspace_token', 'mentor_id', 'escopo', 'completed_at'] as $interno) {
            $this->assertArrayNotHasKey($interno, $visao);
        }
    }
}
