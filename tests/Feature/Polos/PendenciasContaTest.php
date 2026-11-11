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
}
