<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PolosAgendaRevisao;
use App\Models\PolosPendenciaConta;
use App\Models\PolosPendenciaContaEvento;
use App\Models\PolosRevisao;
use App\Models\User;
use App\Notifications\PendenciaContaNotification;
use App\Services\Polos\FilaRevisaoPolos;
use App\Services\Polos\PendenciasContaPolos;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TKT-0035 (DEV-58), fatia 1 — pendências do Analista de Conta dos Polos.
 *
 * O analista de Ads encaminha, pela fila de revisão, uma pendência que o Analista de Conta
 * resolve na conta do seller. O que os testes protegem (critérios de aceite do ticket):
 *   - a empresa encaminhada entra na fila do Analista de Conta e CONTINUA na carteira e na fila
 *     do responsável, com o mesmo responsável;
 *   - a fila de revisão e a carteira dão o mesmo resultado antes e depois de criar e concluir;
 *   - o Analista de Conta muda status e conclui com o que foi feito; quem pediu é avisado;
 *   - toda ação vai para o histórico com quem e quando;
 *   - empresa com duas pendências só sai da fila quando as duas fecham.
 *
 * Hoje fixado em quarta, 11/11/2026 = Qua A (dia 2 do ciclo).
 *
 * @group polos
 */
class PendenciasContaTest extends TestCase
{
    use RefreshDatabase;

    private User $jhonatan;
    private User $elvis;
    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00', 'America/Sao_Paulo'));

        config(['services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1', 'services.ecf.key' => 'test-key']);
        Cache::flush();
        // ECF Drive sem o arquivo: a fila abre sem os números do mês.
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0], 200)]);
        Storage::fake('local');
        $this->withoutVite();

        $this->jhonatan = $this->userCom('Jhonatan', [Permissions::MLB_FATURAMENTO_POLOS]);
        $this->elvis    = $this->userCom('Elvis', [Permissions::MLB_FATURAMENTO_POLOS]);
        // O Analista de Conta não herda a carteira nem o Faturamento Polos: só a chave própria.
        $this->bruno    = $this->userCom('Bruno', [Permissions::MLB_ANALISTA_CONTA]);
    }

    // ─── Apoio ──────────────────────────────────────────────────────────────

    private function userCom(string $nome, array $chaves): User
    {
        $slug    = 'setor-' . Str::random(6);
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor ' . $slug, 'slug' => $slug, 'active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($chaves as $chave) {
            DB::table('setor_permissoes')->insert([
                'setor_id' => $setorId, 'permission_key' => $chave, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $user = User::factory()->create(['role' => 'consultor', 'name' => $nome]);
        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId, 'cargo_id' => null, 'is_principal' => true,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    /** Empresa POLOS com ficha (o responsável do Painel mora na ficha), na agenda de hoje. */
    private function empresa(string $nome, ?User $resp, int $dia = 2): MlbEmpresa
    {
        $e = MlbEmpresa::create([
            'nome' => $nome, 'tipo' => 'POLO', 'projeto' => 'POLOS', 'fase' => 'M2',
            'cust_id' => (string) random_int(100000000, 999999999), 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ]);
        MlbImplementacao::create(['empresa_id' => $e->id, 'token' => 'tok-' . Str::random(12), 'responsavel_id' => $resp?->id, 'places' => 'Ativo']);
        PolosAgendaRevisao::create(['mlb_empresa_id' => $e->id, 'dia_ciclo' => $dia]);

        return $e->fresh();
    }

    private function encaminhar(User $quem, MlbEmpresa $e, array $extra = [])
    {
        return $this->actingAs($quem)->post(route('mlb.polos-painel.pendencias-conta.store'), array_merge([
            'empresa_id' => $e->id,
            'tipo'       => 'publicacao',
            'descricao'  => 'Publicar os 3 kits novos',
            'mlbs'       => 'mlb-123456789, MLB987654321',
            'prioridade' => 'alta',
            'prazo'      => '2026-11-13',
        ], $extra));
    }

    /** Pendência direto no banco, para testar ordem e atraso sem passar pelo formulário. */
    private function pendencia(MlbEmpresa $e, array $attrs = []): PolosPendenciaConta
    {
        return PolosPendenciaConta::create(array_merge([
            'mlb_empresa_id' => $e->id, 'tipo' => 'ajuste', 'descricao' => 'Ajustar', 'prioridade' => 'media',
            'prazo' => '2026-11-20', 'status' => 'aberta', 'solicitante_id' => $this->jhonatan->id,
        ], $attrs));
    }

    /** O que a fila de revisão e a carteira mostram — tem de ser igual com ou sem pendência. */
    private function retratoDaRevisao(): array
    {
        $fila = app(FilaRevisaoPolos::class);

        return [
            'jhonatan'    => $fila->montar($this->jhonatan->id, now()),
            'elvis'       => $fila->montar($this->elvis->id, now()),
            'analistas'   => $fila->analistas(),
            'responsavel' => DB::table('mlb_implementacoes')->orderBy('id')->pluck('responsavel_id', 'empresa_id')->all(),
            'empresas'    => DB::table('mlb_empresas')->orderBy('id')->get(['id', 'responsavel_id', 'fase'])->toArray(),
            'revisoes'    => PolosRevisao::count(),
            'agenda'      => PolosAgendaRevisao::orderBy('mlb_empresa_id')->pluck('dia_ciclo', 'mlb_empresa_id')->all(),
        ];
    }

    // ─── Critério 1: entra na fila do Analista de Conta e fica na do responsável ─

    public function test_encaminhar_poe_a_empresa_na_fila_do_analista_de_conta_e_ela_continua_na_do_responsavel(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);

        $this->encaminhar($this->jhonatan, $x, ['anexos' => [UploadedFile::fake()->image('print.png')]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $p = PolosPendenciaConta::sole();
        $this->assertSame('aberta', $p->status);
        $this->assertSame($this->jhonatan->id, (int) $p->solicitante_id);
        $this->assertSame(['MLB123456789', 'MLB987654321'], $p->mlbs);
        $this->assertSame('2026-11-13', $p->prazo->toDateString());
        Storage::disk('local')->assertExists($p->anexos()->sole()->caminho);

        // Na fila do Bruno, com quem pediu, tipo, prioridade, prazo e status.
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta'))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg->component('Polos/AnalistaConta/Index', false)
                ->where('podeOperar', true)
                ->where('fila.resumo.em_aberto', 1)
                ->where('fila.abertas.0.nome', 'Empresa X')
                ->where('fila.abertas.0.cust_id', $x->cust_id)
                ->where('fila.abertas.0.fase', 'M2')
                ->where('fila.abertas.0.responsavel', 'Jhonatan')
                ->where('fila.abertas.0.pendencias.0.solicitante.nome', 'Jhonatan')
                ->where('fila.abertas.0.pendencias.0.tipo_label', 'Publicação')
                ->where('fila.abertas.0.pendencias.0.prioridade_label', 'Alta')
                ->where('fila.abertas.0.pendencias.0.prazo', '13/11/2026')
                ->where('fila.abertas.0.pendencias.0.status_label', 'Aberta')
                ->where('fila.abertas.0.pendencias.0.historico.0.resumo', 'encaminhou ao Analista de Conta')
                ->where('fila.abertas.0.pendencias.0.historico.1.resumo', 'anexou print.png'));

        // E continua na fila de revisão do Jhonatan, com ele de responsável e o selo da pendência.
        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.fila'))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg
                ->where('fila.pendentes.0.nome', 'Empresa X')
                ->where("pendenciasConta.{$x->id}.0.status", 'aberta')
                ->where('analistaConta.minhas_abertas', 1));
        $this->assertSame($this->jhonatan->id, FilaRevisaoPolos::responsavelId($x->fresh('implementacao')));
    }

    // ─── Critério 2: revisão e carteira não mudam ───────────────────────────

    public function test_revisao_e_carteira_dao_o_mesmo_resultado_antes_e_depois_de_criar_e_concluir(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $this->empresa('Empresa Y', $this->jhonatan, 5);
        $z = $this->empresa('Empresa Z', $this->elvis);
        PolosRevisao::create(['mlb_empresa_id' => $z->id, 'user_id' => $this->elvis->id, 'data' => '2026-11-11', 'revisado_em' => now()]);

        $antes = $this->retratoDaRevisao();

        $this->encaminhar($this->jhonatan, $x)->assertSessionHasNoErrors();
        $this->encaminhar($this->elvis, $z, ['tipo' => 'central_promocao'])->assertSessionHasNoErrors();
        $this->assertEquals($antes, $this->retratoDaRevisao(), 'Criar pendência mexeu na revisão ou na carteira.');

        $p = PolosPendenciaConta::where('mlb_empresa_id', $x->id)->sole();
        // Agenda e prioridade (fatia 2) também não podem mexer na revisão.
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.planejar'), ['ids' => [$p->id], 'data' => '2026-11-16'])->assertSessionHasNoErrors();
        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.prioridade', $p), ['prioridade' => 'urgente'])->assertSessionHasNoErrors();
        $this->assertEquals($antes, $this->retratoDaRevisao(), 'Planejar ou mudar prioridade mexeu na revisão ou na carteira.');
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.status', $p), ['status' => 'em_andamento'])->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.concluir', $p), ['texto' => 'Publicado'])->assertSessionHasNoErrors();
        $this->assertEquals($antes, $this->retratoDaRevisao(), 'Concluir pendência mexeu na revisão ou na carteira.');
    }

    // ─── Quem pode ──────────────────────────────────────────────────────────

    public function test_so_o_responsavel_ou_admin_encaminha(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);

        $this->encaminhar($this->elvis, $x)->assertForbidden();
        // O Analista de Conta vê a fila, mas não encaminha em nome da carteira de ninguém.
        $this->encaminhar($this->bruno, $x)->assertForbidden();
        $this->encaminhar(User::factory()->create(['role' => 'consultor']), $x)->assertForbidden();
        $this->assertSame(0, PolosPendenciaConta::count());

        $this->encaminhar(User::factory()->create(['role' => 'admin']), $x)->assertSessionHasNoErrors();
        $this->assertSame(1, PolosPendenciaConta::count());
    }

    public function test_formulario_exige_descricao_tipo_prioridade_e_prazo_de_hoje_em_diante(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);

        $this->encaminhar($this->jhonatan, $x, ['descricao' => '  ', 'tipo' => 'xpto', 'prioridade' => 'altissima', 'prazo' => '2026-11-10'])
            ->assertSessionHasErrors(['descricao', 'tipo', 'prioridade', 'prazo']);
        $this->encaminhar($this->jhonatan, $x, ['mlbs' => null, 'prazo' => '2026-11-11'])->assertSessionHasNoErrors();

        $this->assertNull(PolosPendenciaConta::sole()->mlbs);
    }

    public function test_responsavel_nao_muda_status_nem_conclui_mas_comenta(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $p = $this->pendencia($x);

        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.status', $p), ['status' => 'em_andamento'])->assertForbidden();
        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.concluir', $p), ['texto' => 'x'])->assertForbidden();
        $this->actingAs($this->elvis)->post(route('mlb.polos-painel.pendencias-conta.comentar', $p), ['texto' => 'x'])->assertForbidden();

        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.comentar', $p), ['texto' => 'Pode antecipar?'])->assertSessionHasNoErrors();
        $this->assertSame('aberta', $p->fresh()->status);

        // O responsável vê a fila do Analista de Conta (só leitura).
        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.analista-conta'))
            ->assertOk()->assertInertia(fn (Assert $pg) => $pg->where('podeOperar', false));
        $this->actingAs(User::factory()->create(['role' => 'consultor']))->get(route('mlb.polos-painel.analista-conta'))->assertForbidden();
    }

    // ─── Critérios 3, 4 e 5: status, conclusão, histórico, aviso ─────────────

    public function test_analista_de_conta_muda_status_e_conclui_e_quem_pediu_e_avisado(): void
    {
        Notification::fake();
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $this->encaminhar($this->jhonatan, $x)->assertSessionHasNoErrors();
        $p = PolosPendenciaConta::sole();

        $this->travelTo(Carbon::parse('2026-11-11 11:15:00', 'America/Sao_Paulo'));
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.status', $p), ['status' => 'em_andamento'])->assertSessionHasNoErrors();
        $this->assertSame($this->bruno->id, (int) $p->fresh()->analista_id);

        $this->travelTo(Carbon::parse('2026-11-11 15:40:00', 'America/Sao_Paulo'));
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.concluir', $p), [
            'texto' => 'Publiquei os 3 kits', 'mlbs_alterados' => 'MLB111111111 mlb-222222222',
        ])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('concluida', $p->status);
        $this->assertSame('Publiquei os 3 kits', $p->conclusao);
        $this->assertSame(['MLB111111111', 'MLB222222222'], $p->mlbs_alterados);
        $this->assertSame('2026-11-11 15:40', $p->concluida_em->format('Y-m-d H:i'));

        // Histórico: quem, quando, o quê — em ordem.
        $eventos = PolosPendenciaContaEvento::where('pendencia_id', $p->id)->orderBy('id')->get();
        $this->assertSame(['criada', 'status', 'concluida'], $eventos->pluck('tipo')->all());
        $this->assertSame([$this->jhonatan->id, $this->bruno->id, $this->bruno->id], $eventos->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['aberta', 'em_andamento'], [$eventos[1]->de, $eventos[1]->para]);
        $this->assertSame('2026-11-11 11:15', $eventos[1]->created_at->format('Y-m-d H:i'));
        $this->assertSame(['mlbs_alterados' => ['MLB111111111', 'MLB222222222']], $eventos[2]->meta);

        Notification::assertSentTo($this->jhonatan, PendenciaContaNotification::class, function ($n) use ($p) {
            $d = $n->toArray($this->jhonatan);

            return $d['categoria'] === 'pendencia_conta'
                && str_contains($d['titulo'], 'concluída')
                && $d['mensagem'] === 'Publiquei os 3 kits'
                && $d['url'] === '/mlb/polos-painel/analista-conta?pendencia=' . $p->id;
        });
        // Ninguém é avisado da própria ação.
        Notification::assertNotSentTo($this->bruno, PendenciaContaNotification::class);

        // Concluída sai da fila e vai para as encerradas; na fila de revisão, mostra o que foi feito.
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta'))
            ->assertInertia(fn (Assert $pg) => $pg->where('fila.resumo.em_aberto', 0)->has('fila.abertas', 0)
                ->where('fila.resumo.concluidas_hoje', 1)
                ->where('fila.encerradas.0.pendencias.0.conclusao', 'Publiquei os 3 kits'));
        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.fila'))
            ->assertInertia(fn (Assert $pg) => $pg->where("pendenciasConta.{$x->id}.0.status", 'concluida')
                ->where("pendenciasConta.{$x->id}.0.conclusao", 'Publiquei os 3 kits')
                ->where('analistaConta.minhas_abertas', 0));

        // Concluir de novo não duplica.
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.concluir', $p), ['texto' => 'de novo'])
            ->assertSessionHasErrors('texto');
    }

    // ─── Critério 6: duas pendências ────────────────────────────────────────

    public function test_empresa_com_duas_pendencias_so_sai_da_fila_quando_as_duas_fecham(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $um   = $this->pendencia($x, ['prazo' => '2026-11-12']);
        $dois = $this->pendencia($x, ['prazo' => '2026-11-14', 'solicitante_id' => $this->elvis->id]);
        $servico = app(PendenciasContaPolos::class);

        $servico->concluir($um, $this->bruno, 'Feito');
        $fila = $servico->fila(now());
        $this->assertCount(1, $fila['abertas']);
        $this->assertSame('Empresa X', $fila['abertas'][0]['nome']);
        $this->assertSame([$dois->id], array_column($fila['abertas'][0]['pendencias'], 'id'));

        $servico->mudarStatus($dois, $this->bruno, 'cancelada', 'Não precisa mais');
        $this->assertSame([], $servico->fila(now())['abertas']);
    }

    // ─── Devolvida ──────────────────────────────────────────────────────────

    public function test_devolvida_pede_o_que_falta_avisa_e_volta_para_aberta_quando_quem_pediu_responde(): void
    {
        Notification::fake();
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $p = $this->pendencia($x);
        $p->update(['analista_id' => $this->bruno->id]);

        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.status', $p), ['status' => 'devolvida'])
            ->assertSessionHasErrors('texto');
        $this->actingAs($this->bruno)->post(route('mlb.polos-painel.pendencias-conta.status', $p), ['status' => 'devolvida', 'texto' => 'Qual o MLB?'])
            ->assertSessionHasNoErrors();
        $this->assertSame('devolvida', $p->fresh()->status);
        Notification::assertSentTo($this->jhonatan, PendenciaContaNotification::class, fn ($n) => str_contains($n->titulo, 'devolvida') && str_contains($n->mensagem, 'Qual o MLB?'));

        // Devolvida segue na fila (só sai concluída ou cancelada).
        $this->assertCount(1, app(PendenciasContaPolos::class)->fila(now())['abertas']);

        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.comentar', $p), ['texto' => 'MLB123456789'])
            ->assertSessionHasNoErrors();
        $this->assertSame('aberta', $p->fresh()->status);
        Notification::assertSentTo($this->bruno, PendenciaContaNotification::class, fn ($n) => str_contains($n->titulo, 'Jhonatan comentou'));

        $ultimo = PolosPendenciaContaEvento::where('pendencia_id', $p->id)->orderByDesc('id')->first();
        $this->assertSame(['status', 'devolvida', 'aberta', ['automatico' => true]], [$ultimo->tipo, $ultimo->de, $ultimo->para, $ultimo->meta]);
    }

    // ─── Fila: ordem e atraso ───────────────────────────────────────────────

    public function test_fila_ordena_por_prazo_e_prioridade_e_destaca_atrasadas(): void
    {
        $a = $this->empresa('A Depois', $this->jhonatan);
        $b = $this->empresa('B Atrasada', $this->elvis);
        $c = $this->empresa('C Mesmo prazo, urgente', $this->jhonatan);
        $this->pendencia($a, ['prazo' => '2026-11-18', 'prioridade' => 'urgente']);
        $this->pendencia($b, ['prazo' => '2026-11-09', 'prioridade' => 'baixa']);
        $this->pendencia($c, ['prazo' => '2026-11-18', 'prioridade' => 'alta']);
        $this->pendencia($a, ['prazo' => null, 'prioridade' => 'urgente']);

        $fila = app(PendenciasContaPolos::class)->fila(now());

        $this->assertSame(['B Atrasada', 'A Depois', 'C Mesmo prazo, urgente'], array_column($fila['abertas'], 'nome'));
        $this->assertTrue($fila['abertas'][0]['atrasada']);
        $this->assertSame(2, $fila['abertas'][0]['pendencias'][0]['dias_atraso']);
        $this->assertFalse($fila['abertas'][1]['atrasada']);
        // Sem prazo vai por último dentro da empresa.
        $this->assertSame([null, null], [$fila['abertas'][1]['pendencias'][1]['prazo'], $fila['abertas'][1]['pendencias'][1]['prazo_iso']]);
        $this->assertSame(1, $fila['resumo']['atrasadas']);
        $this->assertSame(3, $fila['resumo']['empresas']);
    }

    // ─── Histórico na ficha e anexos ────────────────────────────────────────

    public function test_historico_da_empresa_aparece_na_ficha_para_quem_abre_o_painel(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $this->encaminhar($this->jhonatan, $x, ['anexos' => [UploadedFile::fake()->create('lista.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();
        $p = PolosPendenciaConta::sole();
        app(PendenciasContaPolos::class)->concluir($p, $this->bruno, 'Feito');

        $publicacao = $this->userCom('Publicador', [Permissions::MLB_PROJETOS]);
        $this->actingAs($publicacao)->getJson(route('mlb.polos-painel.pendencias-conta.empresa', $x))
            ->assertOk()
            ->assertJsonPath('pendencias.0.status', 'concluida')
            ->assertJsonPath('pendencias.0.historico.0.por', 'Jhonatan')
            ->assertJsonPath('pendencias.0.historico.2.resumo', 'concluiu')
            ->assertJsonPath('pendencias.0.historico.2.por', 'Bruno');

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson(route('mlb.polos-painel.pendencias-conta.empresa', $x))->assertForbidden();

        // Anexo: sai para quem vê, com o tipo detectado; para os demais, 404.
        $anexo = $p->anexos()->sole();
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.pendencias-conta.anexo', [$p, $anexo]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('mlb.polos-painel.pendencias-conta.anexo', [$p, $anexo]))->assertNotFound();
    }

    public function test_papel_analista_de_conta_e_uma_permissao_do_catalogo(): void
    {
        $this->assertTrue(Permissions::isValid('mlb.analista_conta'));
        $this->assertTrue(PendenciasContaPolos::podeOperar($this->bruno));
        $this->assertFalse(PendenciasContaPolos::podeOperar($this->jhonatan));
        // O papel não dá a fila de revisão nem o Faturamento Polos.
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.fila'))->assertForbidden();
    }

    public function test_mlbs_aceita_lista_livre_e_guarda_o_que_nao_parece_mlb(): void
    {
        $this->assertSame(['MLB123', 'MLBU456', 'MLB789', 'ABC'], PendenciasContaPolos::mlbs("mlb-123,  MLBU-456\nMLB789;abc mlb123"));
        $this->assertSame([], PendenciasContaPolos::mlbs(null));
    }

    // ═══ Fatia 2 — agenda semanal ═══════════════════════════════════════════

    private function planejar(array $ids, ?string $data, ?User $quem = null)
    {
        return $this->actingAs($quem ?? $this->bruno)->post(route('mlb.polos-painel.pendencias-conta.planejar'), ['ids' => $ids, 'data' => $data]);
    }

    public function test_com_50_pendencias_o_analista_distribui_a_semana_e_as_sem_data_ficam_na_coluna_sem_data(): void
    {
        $ids = [];
        foreach (range(1, 10) as $n) {
            $e = $this->empresa("Empresa {$n}", $n % 2 ? $this->jhonatan : $this->elvis);
            foreach (range(1, 5) as $_) {
                $ids[] = $this->pendencia($e, ['prazo' => '2026-11-30'])->id;
            }
        }

        // 45 distribuídas de segunda a sexta da semana que vem (9 por dia), 5 sem data.
        $dias = ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'];
        foreach (array_chunk(array_slice($ids, 0, 45), 9) as $i => $lote) {
            $this->planejar($lote, $dias[$i])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta.agenda', ['semana' => '2026-11-18']))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg->component('Polos/AnalistaConta/Agenda', false)
                ->where('podeOperar', true)
                ->where('agenda.semana.inicio', '2026-11-16')
                ->where('agenda.total', 50)
                ->has('agenda.dias', 5)
                ->has('agenda.dias.0.cartoes', 9)
                ->has('agenda.dias.4.cartoes', 9)
                ->where('agenda.dias.0.rotulo', 'Seg')
                ->where('agenda.dias.0.dia', '16/11')
                ->where('agenda.dias.0.empresas', 2)
                ->has('agenda.sem_data', 5)
                ->has('agenda.para_tras', 0)
                ->where('agenda.dias.0.cartoes.0.empresa.nome', 'Empresa 1'));

        // Sem ?semana, abre na semana atual (11/11 é quarta): as da semana que vem só contam.
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta.agenda'))
            ->assertInertia(fn (Assert $pg) => $pg->where('agenda.semana.inicio', '2026-11-09')
                ->where('agenda.semana.atual', true)
                ->where('agenda.outras_semanas', 45)
                ->where('agenda.dias.2.hoje', true)
                ->where('agenda.dias.0.passado', true));
    }

    public function test_planejar_depois_do_prazo_destaca_o_cartao_e_avisa_quem_pediu_uma_vez_por_pessoa(): void
    {
        Notification::fake();
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $z = $this->empresa('Empresa Z', $this->elvis);
        $doJhonatan = collect(range(1, 3))->map(fn () => $this->pendencia($x, ['prazo' => '2026-11-12'])->id)->all();
        $doElvis    = $this->pendencia($z, ['prazo' => '2026-11-20', 'solicitante_id' => $this->elvis->id])->id;

        $this->planejar([...$doJhonatan, $doElvis], '2026-11-16')->assertSessionHasNoErrors();

        // Um aviso só para o Jhonatan (3 pendências), nenhum para o Elvis (16/11 está antes do prazo dele).
        Notification::assertSentToTimes($this->jhonatan, PendenciaContaNotification::class, 1);
        Notification::assertSentTo($this->jhonatan, PendenciaContaNotification::class,
            fn ($n) => $n->titulo === '3 pendências planejadas depois do prazo' && str_contains($n->mensagem, 'Empresa X (16/11)'));
        Notification::assertNotSentTo($this->elvis, PendenciaContaNotification::class);

        $agenda = app(PendenciasContaPolos::class)->agenda(Carbon::parse('2026-11-16'), now());
        $cartoes = collect($agenda['dias'][0]['cartoes'])->keyBy('id');
        $this->assertTrue($cartoes[$doJhonatan[0]]['depois_do_prazo']);
        $this->assertFalse($cartoes[$doElvis]['depois_do_prazo']);
        // Quem planejou vira o Analista de Conta da pendência.
        $this->assertSame($this->bruno->id, (int) PolosPendenciaConta::find($doElvis)->analista_id);

        // Uma só, depois do prazo: o aviso diz o dia e o prazo.
        $outra = $this->pendencia($x, ['prazo' => '2026-11-13']);
        $this->planejar([$outra->id], '2026-11-17')->assertSessionHasNoErrors();
        Notification::assertSentTo($this->jhonatan, PendenciaContaNotification::class,
            fn ($n) => $n->titulo === 'Pendência planejada depois do prazo: Empresa X' && str_contains($n->mensagem, 'Bruno vai fazer em 17/11; o prazo pedido era 13/11'));
    }

    public function test_planejar_e_do_analista_de_conta_em_dia_de_semana_de_hoje_em_diante(): void
    {
        $p = $this->pendencia($this->empresa('Empresa X', $this->jhonatan));

        $this->planejar([$p->id], '2026-11-16', $this->jhonatan)->assertForbidden();
        $this->planejar([$p->id], '2026-11-10')->assertSessionHasErrors('data');
        $this->planejar([$p->id], '2026-11-14')->assertSessionHasErrors('data'); // sábado
        $this->planejar([$p->id], '2026-11-11')->assertSessionHasNoErrors();      // hoje vale
        $this->assertSame('2026-11-11', $p->fresh()->data_planejada->toDateString());

        // Encerrada não entra no planejamento.
        $p->update(['status' => 'concluida', 'concluida_em' => now()]);
        $this->planejar([$p->id], '2026-11-12')->assertSessionHasNoErrors();
        $this->assertSame('2026-11-11', $p->fresh()->data_planejada->toDateString());
    }

    public function test_toda_mudanca_de_data_planejada_fica_no_historico(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $p = $this->pendencia($x, ['prazo' => '2026-11-30']);

        $this->planejar([$p->id], '2026-11-16')->assertSessionHasNoErrors();
        $this->planejar([$p->id], '2026-11-16')->assertSessionHasNoErrors(); // mesmo dia: não registra de novo
        $this->planejar([$p->id], '2026-11-18')->assertSessionHasNoErrors();
        $this->planejar([$p->id], null)->assertSessionHasNoErrors();

        $historico = collect(app(PendenciasContaPolos::class)->historico($x, now())[0]['historico']);
        $this->assertSame(
            ['planejou para 16/11', 'replanejou de 16/11 para 18/11', 'tirou a data planejada (era 18/11)'],
            $historico->where('tipo', 'data_planejada')->pluck('resumo')->values()->all(),
        );
        $this->assertSame(['Bruno'], $historico->where('tipo', 'data_planejada')->pluck('por')->unique()->values()->all());
        $this->assertNull($p->fresh()->data_planejada);
    }

    public function test_lista_do_dia_traz_as_de_hoje_as_que_ficaram_para_tras_e_as_atrasadas_sem_dia(): void
    {
        $e = $this->empresa('Empresa X', $this->jhonatan);
        // Dia planejado no passado não passa pelo formulário: grava direto.
        $hoje        = $this->pendencia($e, ['data_planejada' => '2026-11-11']);
        $ficou       = $this->pendencia($e, ['data_planejada' => '2026-11-10']);
        $amanha      = $this->pendencia($e, ['data_planejada' => '2026-11-12']);
        $semDiaVenc  = $this->pendencia($e, ['prazo' => '2026-11-09']);
        $semDia      = $this->pendencia($e, ['prazo' => '2026-11-20']);
        $planVencida = $this->pendencia($e, ['prazo' => '2026-11-09', 'data_planejada' => '2026-11-13']);

        $fila   = app(PendenciasContaPolos::class)->fila(now());
        $linhas = collect($fila['abertas'][0]['pendencias'])->keyBy('id');

        $this->assertEqualsCanonicalizing([$hoje->id, $ficou->id, $semDiaVenc->id], $linhas->filter(fn ($l) => $l['para_hoje'])->keys()->all());
        $this->assertTrue($linhas[$ficou->id]['para_tras']);
        $this->assertFalse($linhas[$amanha->id]['para_hoje']);
        $this->assertFalse($linhas[$semDia->id]['para_hoje']);
        // Prazo vencido mas planejada para sexta: o Analista de Conta já decidiu, não volta para hoje.
        $this->assertFalse($linhas[$planVencida->id]['para_hoje']);
        $this->assertSame(3, $fila['resumo']['para_hoje']);
        $this->assertSame(1, $fila['resumo']['para_tras']);
        $this->assertSame(2, $fila['resumo']['sem_data']);

        // Na agenda da semana atual, a de ontem fica na coluna do dia (passado), não em "para trás".
        $agenda = app(PendenciasContaPolos::class)->agenda(now(), now());
        $this->assertContains($ficou->id, array_column($agenda['dias'][1]['cartoes'], 'id'));
        $this->assertSame([], $agenda['para_tras']);
        // Vista da semana que vem, a de ontem vira "ficou para trás".
        $this->assertSame([$ficou->id], array_column(app(PendenciasContaPolos::class)->agenda(Carbon::parse('2026-11-16'), now())['para_tras'], 'id'));
    }

    public function test_responsavel_ve_o_dia_planejado_na_fila_de_revisao_e_a_agenda_sem_poder_mover(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $p = $this->pendencia($x, ['prazo' => '2026-11-30']);
        $this->planejar([$p->id], '2026-11-16')->assertSessionHasNoErrors();

        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.fila'))
            ->assertInertia(fn (Assert $pg) => $pg->where("pendenciasConta.{$x->id}.0.data_planejada", '16/11')
                ->where("pendenciasConta.{$x->id}.0.analista", 'Bruno'));
        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.analista-conta.agenda', ['semana' => '2026-11-16']))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg->where('podeOperar', false)->has('agenda.dias.0.cartoes', 1));
        $this->planejar([$p->id], '2026-11-18', $this->jhonatan)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('mlb.polos-painel.analista-conta.agenda'))->assertForbidden();
    }

    public function test_quem_pediu_muda_a_prioridade_e_o_analista_de_conta_e_avisado(): void
    {
        Notification::fake();
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $p = $this->pendencia($x, ['prioridade' => 'media', 'analista_id' => $this->bruno->id]);

        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.prioridade', $p), ['prioridade' => 'urgente'])
            ->assertSessionHasNoErrors();
        $this->assertSame('urgente', $p->fresh()->prioridade);
        Notification::assertSentTo($this->bruno, PendenciaContaNotification::class,
            fn ($n) => $n->titulo === 'Prioridade mudou: Empresa X' && str_contains($n->mensagem, 'Agora Urgente (era Média)'));

        $ultimo = PolosPendenciaContaEvento::where('pendencia_id', $p->id)->orderByDesc('id')->first();
        $this->assertSame(['prioridade', 'media', 'urgente', $this->jhonatan->id], [$ultimo->tipo, $ultimo->de, $ultimo->para, (int) $ultimo->user_id]);

        $this->actingAs($this->elvis)->post(route('mlb.polos-painel.pendencias-conta.prioridade', $p), ['prioridade' => 'baixa'])->assertForbidden();
        $this->actingAs($this->jhonatan)->post(route('mlb.polos-painel.pendencias-conta.prioridade', $p), ['prioridade' => 'maxima'])->assertSessionHasErrors('prioridade');
    }

    // ═══ Fatia 3 — acompanhamento da coordenação ════════════════════════════

    public function test_acompanhamento_conta_status_quem_pediu_atrasadas_tempo_medio_e_volume(): void
    {
        $x = $this->empresa('Empresa X', $this->jhonatan);
        $z = $this->empresa('Empresa Z', $this->elvis);
        $quando = function (PolosPendenciaConta $p, string $criada, ?string $concluida = null, ?string $atualizada = null) {
            DB::table('polos_pendencias_conta')->where('id', $p->id)->update(array_filter([
                'created_at' => $criada, 'concluida_em' => $concluida, 'updated_at' => $atualizada ?? $concluida ?? $criada,
            ]));
        };

        // Jhonatan: duas concluídas no período (24 h e 2 h → média 13 h) e uma antiga, fora dele.
        $quando($this->pendencia($x, ['status' => 'concluida']), '2026-11-09 10:00:00', '2026-11-10 10:00:00');
        $quando($this->pendencia($x, ['status' => 'concluida']), '2026-11-11 08:00:00', '2026-11-11 10:00:00');
        $quando($this->pendencia($x, ['status' => 'concluida']), '2026-08-01 08:00:00', '2026-08-02 08:00:00');
        // Elvis: uma em aberto atrasada, uma cancelada hoje.
        $quando($this->pendencia($z, ['solicitante_id' => $this->elvis->id, 'prazo' => '2026-11-09', 'status' => 'em_andamento']), '2026-11-05 09:00:00');
        $quando($this->pendencia($z, ['solicitante_id' => $this->elvis->id, 'status' => 'cancelada']), '2026-11-10 09:00:00', null, '2026-11-11 09:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('mlb.polos-painel.analista-conta.acompanhamento'))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg->component('Polos/AnalistaConta/Acompanhamento', false)
                ->where('dados.periodo.dias', 30)
                ->where('dados.resumo.em_aberto', 1)
                ->where('dados.resumo.atrasadas', 1)
                ->where('dados.resumo.concluidas', 2)
                ->where('dados.resumo.canceladas', 1)
                ->where('dados.resumo.tempo_medio_horas', 13)
                ->where('dados.por_status.1.status', 'em_andamento')
                ->where('dados.por_status.1.total', 1)
                ->where('dados.por_status.4.status', 'concluida')
                ->where('dados.por_status.4.total', 2)
                ->where('dados.por_solicitante.0.nome', 'Elvis')
                ->where('dados.por_solicitante.0.atrasadas', 1)
                ->where('dados.por_solicitante.1.nome', 'Jhonatan')
                ->where('dados.por_solicitante.1.concluidas', 2)
                ->where('dados.por_solicitante.1.tempo_medio_horas', 13)
                ->where('dados.atrasadas.0.empresa.nome', 'Empresa Z')
                ->has('dados.por_dia', 30)
                ->where('dados.por_dia.29.data', '2026-11-11')
                ->where('dados.por_dia.29.concluidas', 1)
                ->where('dados.por_dia.28.concluidas', 1)
                ->has('dados.por_semana', 8)
                ->where('dados.por_semana.7.inicio', '2026-11-09')
                ->where('dados.por_semana.7.concluidas', 2));

        // Período de 7 dias: só muda a janela.
        $this->actingAs($admin)->get(route('mlb.polos-painel.analista-conta.acompanhamento', ['dias' => 7]))
            ->assertInertia(fn (Assert $pg) => $pg->where('dados.periodo.dias', 7)->has('dados.por_dia', 7));
    }

    public function test_acompanhamento_e_da_coordenacao_e_da_lideranca(): void
    {
        $lider = $this->userCom('Líder', [Permissions::MLB_FATURAMENTO_POLOS]);
        DB::table('setor_lideres')->insert([
            'setor_id' => DB::table('user_setores')->where('user_id', $lider->id)->value('setor_id'),
            'user_id' => $lider->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($lider->fresh())->get(route('mlb.polos-painel.analista-conta.acompanhamento'))->assertOk();
        $this->actingAs($this->jhonatan)->get(route('mlb.polos-painel.analista-conta.acompanhamento'))->assertForbidden();
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta.acompanhamento'))->assertForbidden();
        $this->actingAs($this->bruno)->get(route('mlb.polos-painel.analista-conta'))
            ->assertInertia(fn (Assert $pg) => $pg->where('podeAcompanhar', false));
    }
}
