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

    // ─── Os filtros da lista ──────────────────────────────────────

    /**
     * O filtro é o QUARTO lugar em que a régua de agrupamento do PPA existe
     * (os outros: `lib/ppaAgrupamento.js`, `scopeOrdenadoPorAtencao` e
     * `PortalPpaService::visao`). Se ele discordar dos outros, a tela recebe
     * planos que a seção escolhida não desenha: lista vazia com o contador
     * dizendo que há sete. Estes três casos são os mesmos do teste de ordem
     * lá em cima, de propósito — é a mesma pergunta, feita no WHERE.
     */
    #[Test]
    public function o_filtro_de_situacao_parte_os_planos_como_a_regua_de_agrupamento(): void
    {
        $this->ppa('Encerrado pela equipe', ['todo', 'doing'], status: 'completed');
        $this->ppa('Tudo feito sem encerrar', ['done', 'done']);
        $this->ppa('Tem tarefa andando', ['todo', 'doing']);
        $this->ppa('Só a fazer', ['todo', 'todo']);
        $this->ppa('Ainda vazio');

        $porSituacao = fn (string $situacao) => Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->daSituacao($situacao)
            ->orderBy('title')
            ->pluck('title')
            ->all();

        $this->assertSame(['Tem tarefa andando'], $porSituacao(Ppa::GRUPO_ANDAMENTO));

        // Plano SEM tarefa nenhuma fica com o que não começou, nunca em
        // concluído — "nenhuma tarefa pendente" é verdade por vacuidade nele.
        $this->assertSame(['Ainda vazio', 'Só a fazer'], $porSituacao(Ppa::GRUPO_FAZER));

        // Os dois jeitos de acabar: o encerrado pela equipe e o 100% feito que
        // ninguém voltou para marcar.
        $this->assertSame(['Encerrado pela equipe', 'Tudo feito sem encerrar'], $porSituacao(Ppa::GRUPO_CONCLUIDO));
    }

    #[Test]
    public function os_tres_grupos_cobrem_todos_os_planos_e_nenhum_cai_em_dois(): void
    {
        foreach ([
            ['Encerrado', ['doing'], 'completed'],
            ['Cem por cento', ['done'], 'sent'],
            ['Andando', ['doing'], 'sent'],
            ['Parado', ['todo'], 'sent'],
            ['Vazio', [], 'sent'],
            ['Meio a meio', ['done', 'todo'], 'sent'],
        ] as [$titulo, $tarefas, $status]) {
            $this->ppa($titulo, $tarefas, status: $status);
        }

        $ids = [];
        foreach ([Ppa::GRUPO_ANDAMENTO, Ppa::GRUPO_FAZER, Ppa::GRUPO_CONCLUIDO] as $grupo) {
            $ids = array_merge($ids, Ppa::query()->doEscopo(Ppa::ESCOPO_GERAL)->daSituacao($grupo)->pluck('id')->all());
        }

        $todos = Ppa::query()->doEscopo(Ppa::ESCOPO_GERAL)->pluck('id')->all();

        // Partição: soma igual ao total prova que ninguém caiu em dois grupos;
        // conjuntos iguais provam que ninguém ficou de fora dos três. Um filtro
        // que perdesse um plano sumiria com ele da tela sem erro nenhum.
        $this->assertCount(count($todos), $ids);
        $this->assertEqualsCanonicalizing($todos, $ids);
    }

    #[Test]
    public function vencido_atravessa_os_grupos_e_deixa_de_fora_o_que_a_equipe_encerrou(): void
    {
        $this->ppa('Andando e atrasado', ['doing'], prazo: now()->subDays(2)->toDateString());
        $this->ppa('Nem começou e atrasado', ['todo'], prazo: now()->subDay()->toDateString());
        $this->ppa('Vence hoje', ['doing'], prazo: now()->toDateString());
        $this->ppa('Vence amanhã', ['doing'], prazo: now()->addDay()->toDateString());
        $this->ppa('Sem prazo', ['doing']);
        $this->ppa('Fechado com prazo velho', ['done'], status: 'completed', prazo: now()->subDays(40)->toDateString());

        $vencidos = Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->daSituacao(Ppa::SITUACAO_VENCIDO)
            ->orderBy('title')
            ->pluck('title')
            ->all();

        // Um andando e um sem começar: "vencido" recorta os dois grupos, e por
        // isso não pode ser um grupo. "Vence hoje" fica de fora — a mesma
        // fronteira do selo da tela, onde dia 0 é "Vence hoje" e não "Atrasado".
        // O encerrado também fica: atraso de trabalho fechado não cobra ninguém,
        // que é o que `diasAteOPrazo()` já dizia devolvendo null.
        $this->assertSame(['Andando e atrasado', 'Nem começou e atrasado'], $vencidos);
    }

    #[Test]
    public function o_filtro_por_data_de_criacao_inclui_os_dois_dias_das_pontas(): void
    {
        $em = function (string $titulo, string $quando) {
            $ppa = $this->ppa($titulo);
            $ppa->forceFill(['created_at' => $quando])->saveQuietly();
        };

        $em('Primeiro', '2026-09-01 08:00:00');
        $em('Do meio', '2026-09-10 23:45:00');
        $em('Último', '2026-09-20 00:05:00');

        $entre = fn (?string $de, ?string $ate) => Ppa::query()
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->criadoEntre($de, $ate)
            ->orderBy('created_at')
            ->pluck('title')
            ->all();

        // `created_at` é timestamp e os extremos são DIA. Sem `whereDate`, o
        // plano criado às 23:45 do dia 10 ficaria de fora de um intervalo que
        // termina no dia 10 — o filtro perderia o último dia inteiro, calado.
        $this->assertSame(['Primeiro', 'Do meio'], $entre('2026-09-01', '2026-09-10'));
        $this->assertSame(['Do meio', 'Último'], $entre('2026-09-10', null));
        $this->assertSame(['Primeiro'], $entre(null, '2026-09-09'));
    }

    #[Test]
    public function a_lista_mostra_quando_o_plano_nasceu_e_quando_mexeram_nele_pela_ultima_vez(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppa('Plano datado', ['todo'], mentor: $admin);
        $ppa->forceFill(['created_at' => '2026-09-15 10:30:00', 'updated_at' => '2026-09-16 09:00:00'])->saveQuietly();

        // A TAREFA foi mexida depois do plano — e é ela quem manda. Mover um
        // card é trabalho no plano, mas grava em `ppa_tasks`: sem isso, o plano
        // com o quadro andando todo dia apareceria parado desde a última vez
        // que alguém trocou o título.
        $ppa->tasks()->first()->forceFill(['updated_at' => '2026-09-21 18:40:00'])->saveQuietly();

        $resposta = $this->actingAs($admin)->get(route('ppa.index'));
        $linha = $resposta->viewData('page')['props']['ppas']['data'][0];

        // Formatadas no servidor, como o prazo: data crua no JSON viraria
        // `new Date()` no navegador, e aí o fuso de quem olha decide o dia.
        $this->assertSame('15/09/2026', $linha['created_at']);
        $this->assertSame('21/09/2026', $linha['updated_at']);
    }

    #[Test]
    public function plano_mexido_depois_da_ultima_tarefa_usa_a_data_dele(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppa('Titulo trocado ontem', ['todo'], mentor: $admin);
        $ppa->tasks()->first()->forceFill(['updated_at' => '2026-09-10 08:00:00'])->saveQuietly();
        $ppa->forceFill(['updated_at' => '2026-09-19 15:00:00'])->saveQuietly();

        $resposta = $this->actingAs($admin)->get(route('ppa.index'));

        // A regra é "a mais recente das duas", não "a da tarefa sempre".
        $this->assertSame('19/09/2026', $resposta->viewData('page')['props']['ppas']['data'][0]['updated_at']);
    }

    #[Test]
    public function plano_sem_tarefa_nenhuma_cai_na_propria_data_em_vez_de_ficar_vazio(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppa('Ainda sem tarefa', [], mentor: $admin);
        $ppa->forceFill(['updated_at' => '2026-09-12 11:00:00'])->saveQuietly();

        $resposta = $this->actingAs($admin)->get(route('ppa.index'));

        // A subconsulta devolve NULL quando não há tarefa. Sem o fallback, a
        // coluna "Atualizado" apareceria em branco justamente nos planos recém
        // criados — que são os que mais se olha.
        $this->assertSame('12/09/2026', $resposta->viewData('page')['props']['ppas']['data'][0]['updated_at']);
    }

    #[Test]
    public function a_lista_interna_aplica_o_filtro_da_url_e_devolve_o_que_aplicou(): void
    {
        $admin = $this->admin();
        $this->ppa('Andando', ['doing'], mentor: $admin);
        $this->ppa('Parado', ['todo'], mentor: $admin);

        $resposta = $this->actingAs($admin)->get(route('ppa.index', ['situacao' => Ppa::GRUPO_ANDAMENTO]));
        $props = $resposta->viewData('page')['props'];

        // O total tem de ser o do RECORTE: ele é o que a tela mostra como
        // "N PPA(s)" e o que decide quantas páginas existem.
        $this->assertSame(1, $props['ppas']['total']);
        $this->assertSame('Andando', $props['ppas']['data'][0]['title']);
        $this->assertSame(Ppa::GRUPO_ANDAMENTO, $props['filtros']['situacao']);
    }

    #[Test]
    public function filtro_estragado_na_url_abre_a_lista_inteira_em_vez_de_erro(): void
    {
        $admin = $this->admin();
        $this->ppa('Único', ['doing'], mentor: $admin);

        $resposta = $this->actingAs($admin)
            ->get(route('ppa.index', ['situacao' => 'xpto', 'de' => 'ontem', 'ate' => '15/09/2026']));

        // Link colado pela metade, filtro renomeado, bookmark velho: nada disso
        // pode virar tela de erro — vira lista sem filtro.
        $resposta->assertOk();
        $this->assertSame(1, $resposta->viewData('page')['props']['ppas']['total']);

        $filtros = $resposta->viewData('page')['props']['filtros'];
        $this->assertNull($filtros['situacao']);
        $this->assertNull($filtros['de']);
        $this->assertNull($filtros['ate']);
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
