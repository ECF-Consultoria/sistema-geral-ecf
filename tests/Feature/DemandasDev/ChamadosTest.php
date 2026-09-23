<?php

namespace Tests\Feature\DemandasDev;

use App\Models\Chamado;
use App\Models\ChamadoEvento;
use App\Models\DevDemanda;
use App\Models\User;
use App\Notifications\ChamadoNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Chamados — os 12 fluxos críticos do pedido, contra o servidor:
 * abertura, fila da equipe, IDOR, transferência com histórico, conversa pública ×
 * nota interna (inclusive por acesso direto), conversão idempotente em demanda,
 * isolamento da demanda, anexos e filtros da caixa da equipe.
 */
class ChamadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-09-23 10:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Dev = cargo Dev (`is_dev`, guarded → forceFill), sem ser admin. */
    private function dev(string $nome): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    private function colaborador(string $nome = 'Karen Souza'): User
    {
        return User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
    }

    private function abrir(User $quem, array $extra = [])
    {
        return $this->actingAs($quem)->post('/chamados', $extra + [
            'tipo' => 'problema', 'area' => 'Entrada', 'titulo' => 'Erro ao cadastrar cliente',
            'descricao' => 'Clico em salvar e aparece erro 500.', 'impacto' => 'impedido',
        ]);
    }

    // ── Fluxo 1 ──────────────────────────────────────────────────────────────
    public function test_colaborador_abre_chamado_e_o_dev_escolhido_recebe(): void
    {
        Notification::fake();
        $maycon = $this->dev('Maycon Gomes');
        $karen = $this->colaborador();

        $this->abrir($karen, [
            'responsavel_id' => $maycon->id,
            // Tentativa de se passar por outra pessoa: ignorada, vale a sessão.
            'solicitante_id' => $maycon->id, 'solicitante_nome' => 'Outro',
        ])->assertRedirect()->assertSessionHas('success');

        $c = Chamado::sole();
        $this->assertSame('TKT-0001', $c->codigo);
        $this->assertSame($karen->id, $c->solicitante_id);
        $this->assertSame('Karen Souza', $c->solicitante_nome);
        $this->assertSame($maycon->id, $c->responsavel_id);
        $this->assertSame(Chamado::STATUS_ABERTO, $c->status);
        Notification::assertSentTo($maycon, ChamadoNotification::class, fn ($n) => str_contains($n->titulo, 'TKT-0001') && $n->url === "/dev/demandas?aba=chamados&chamado={$c->id}");
        Notification::assertNotSentTo($karen, ChamadoNotification::class);

        $this->actingAs($maycon)->get('/dev/demandas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('chamados', 1)
            ->where('chamados.0.codigo', 'TKT-0001')
            ->where('chamados.0.precisa_atencao', true)
            ->where('chamados.0.prioridade_sugerida', 1));
    }

    public function test_responsavel_precisa_ser_dev_ativo(): void
    {
        $karen = $this->colaborador();
        $outro = $this->colaborador('Não Dev');

        $this->abrir($karen, ['responsavel_id' => $outro->id])->assertSessionHasErrors('responsavel_id');
        $this->assertSame(0, Chamado::count());
    }

    public function test_codigo_sequencial_e_reenvio_nao_duplica(): void
    {
        $karen = $this->colaborador();
        $this->abrir($karen);
        $this->abrir($karen); // duplo clique: mesmo título e descrição no mesmo minuto
        $this->abrir($karen, ['titulo' => 'Outro assunto']);

        $this->assertSame(['TKT-0001', 'TKT-0002'], Chamado::orderBy('id')->pluck('codigo')->all());
    }

    // ── Fluxo 2 ──────────────────────────────────────────────────────────────
    public function test_nao_sei_quem_atende_vai_para_a_fila_e_toda_a_equipe_e_avisada(): void
    {
        Notification::fake();
        $maycon = $this->dev('Maycon Gomes');
        $joao = $this->dev('João Dev');
        $karen = $this->colaborador();

        $this->abrir($karen); // sem responsavel_id

        $c = Chamado::sole();
        $this->assertNull($c->responsavel_id);
        Notification::assertSentTo([$maycon, $joao], ChamadoNotification::class);
        // Qualquer dev vê a fila.
        $this->actingAs($joao)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p->has('chamados', 1));
    }

    // ── Fluxo 3 ──────────────────────────────────────────────────────────────
    public function test_colaborador_nao_abre_chamado_de_outro_nem_mexe_nele(): void
    {
        $a = $this->colaborador('Ana');
        $b = $this->colaborador('Bruno');
        $this->abrir($a);
        $c = Chamado::sole();

        $this->actingAs($b)->get("/chamados/{$c->id}")->assertNotFound();
        $this->actingAs($b)->post("/chamados/{$c->id}/mensagens", ['texto' => 'invadi'])->assertNotFound();
        $this->actingAs($b)->post("/chamados/{$c->id}/cancelar")->assertNotFound();
        $this->actingAs($b)->post("/dev/demandas/chamados/{$c->id}/status", ['status' => 'em_triagem'])->assertForbidden();
        $this->actingAs($b)->get('/chamados')->assertInertia(fn (Assert $p) => $p->has('chamados', 0));
        $this->assertSame(0, $c->mensagens()->count());
    }

    public function test_dev_nao_ve_chamado_de_outro_dev(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $joao = $this->dev('João Dev');
        $this->abrir($this->colaborador(), ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->actingAs($joao)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p->has('chamados', 0));
        $this->actingAs($joao)->get("/dev/demandas?chamado={$c->id}")->assertInertia(fn (Assert $p) => $p->where('chamado_detalhe', null));
        $this->actingAs($joao)->post("/chamados/{$c->id}/mensagens", ['texto' => 'x', 'interna' => true])->assertNotFound();
    }

    // ── Fluxos 4 e 5 ─────────────────────────────────────────────────────────
    public function test_transferencias_preservam_todo_o_historico_e_avisam_quem_recebe(): void
    {
        Notification::fake();
        $a = $this->dev('Maycon Gomes');
        $b = $this->dev('João Dev');
        $this->abrir($this->colaborador(), ['responsavel_id' => $a->id]);
        $c = Chamado::sole();

        // Tirar de alguém sem motivo: recusado.
        $this->actingAs($a)->post("/dev/demandas/chamados/{$c->id}/transferir", ['responsavel_id' => $b->id])->assertSessionHas('error');
        $this->assertSame($a->id, $c->fresh()->responsavel_id);

        $this->actingAs($a)->post("/dev/demandas/chamados/{$c->id}/transferir", ['responsavel_id' => $b->id, 'motivo' => 'Essa área é do João.'])->assertSessionHas('success');
        Notification::assertSentTo($b, ChamadoNotification::class, fn ($n) => str_contains($n->titulo, 'transferido'));

        // Fluxo 5: B recebe (vê na caixa), A deixa de ver.
        $this->actingAs($b)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p->has('chamados', 1));
        $this->actingAs($a)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p->has('chamados', 0));

        $this->actingAs($b)->post("/dev/demandas/chamados/{$c->id}/transferir", ['responsavel_id' => $a->id, 'motivo' => 'Volta, precisa do Maycon.']);

        $transferencias = ChamadoEvento::where('chamado_id', $c->id)->where('tipo', ChamadoEvento::TRANSFERIDO)->orderBy('id')->get();
        $this->assertCount(2, $transferencias);
        $this->assertSame(['Maycon Gomes', 'João Dev', $a->id, 'Essa área é do João.'],
            [$transferencias[0]->de, $transferencias[0]->para, $transferencias[0]->ator_id, $transferencias[0]->meta['motivo']]);
        $this->assertSame(['João Dev', 'Maycon Gomes', $b->id], [$transferencias[1]->de, $transferencias[1]->para, $transferencias[1]->ator_id]);
        $this->assertSame($a->id, $c->fresh()->responsavel_id);
    }

    public function test_so_dev_ativo_recebe_transferencia(): void
    {
        $a = $this->dev('Maycon Gomes');
        $this->abrir($this->colaborador(), ['responsavel_id' => $a->id]);
        $c = Chamado::sole();

        $this->actingAs($a)->post("/dev/demandas/chamados/{$c->id}/transferir", ['responsavel_id' => $this->colaborador('X')->id, 'motivo' => 'x'])
            ->assertSessionHas('error', 'Escolha um dev válido.');
        $this->assertSame($a->id, $c->fresh()->responsavel_id);
    }

    // ── Fluxos 6 e 7 ─────────────────────────────────────────────────────────
    public function test_resposta_publica_chega_e_nota_interna_nunca_sai_para_quem_abriu(): void
    {
        Notification::fake();
        $maycon = $this->dev('Maycon Gomes');
        $karen = $this->colaborador();
        $this->abrir($karen, ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->actingAs($maycon)->post("/chamados/{$c->id}/mensagens", ['texto' => 'Consegue mandar um print?', 'interna' => false]);
        $this->actingAs($maycon)->post("/chamados/{$c->id}/mensagens", [
            'texto' => 'SEGREDO: endpoint X', 'interna' => true,
            'anexos' => [UploadedFile::fake()->image('log-interno.png')],
        ]);

        Notification::assertSentTo($karen, ChamadoNotification::class, fn ($n) => str_contains($n->titulo, 'Resposta'));
        $this->assertSame(Chamado::STATUS_EM_ATENDIMENTO, $c->fresh()->status); // resposta da equipe tira de "Aberto"

        // A tela de quem abriu: a pública está lá; a interna, não — nem o anexo dela.
        $resposta = $this->actingAs($karen)->get("/chamados/{$c->id}")->assertOk();
        $json = json_encode($resposta->viewData('page')['props']['chamado']);
        $this->assertStringContainsString('Consegue mandar um print?', $json);
        $this->assertStringNotContainsString('SEGREDO', $json);
        $this->assertStringNotContainsString('log-interno', $json);

        // Acesso direto ao anexo da nota interna: 404 para quem abriu.
        $anexoInterno = $c->anexos()->whereNotNull('mensagem_id')->sole();
        $this->actingAs($karen)->get("/chamados/{$c->id}/anexos/{$anexoInterno->id}")->assertNotFound();
        $this->actingAs($maycon)->get("/chamados/{$c->id}/anexos/{$anexoInterno->id}")->assertOk();

        // Tentar escrever nota interna como solicitante vira mensagem pública (nunca interna).
        $this->actingAs($karen)->post("/chamados/{$c->id}/mensagens", ['texto' => 'segue print', 'interna' => true]);
        $this->assertSame('publica', $c->mensagens()->latest('id')->first()->visibilidade);
    }

    public function test_equipe_ve_a_nota_interna(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $this->abrir($this->colaborador(), ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();
        $this->actingAs($maycon)->post("/chamados/{$c->id}/mensagens", ['texto' => 'SEGREDO', 'interna' => true]);

        $this->actingAs($maycon)->get("/dev/demandas?chamado={$c->id}")->assertInertia(fn (Assert $p) => $p
            ->where('chamado_detalhe.codigo', 'TKT-0001')
            ->where('chamado_detalhe.linha_do_tempo', fn ($l) => collect($l)->contains(fn ($i) => ($i['texto'] ?? null) === 'SEGREDO' && $i['interna'])));
    }

    // ── Fluxos 8, 9 e 10 ─────────────────────────────────────────────────────
    private function converter(User $quem, Chamado $c, array $extra = [])
    {
        return $this->actingAs($quem)->post("/dev/demandas/chamados/{$c->id}/converter", $extra + [
            'prefixo' => 'DEV', 'titulo' => $c->titulo, 'area' => 'Entrada', 'escopo' => $c->descricao,
            'responsavel_id' => $c->responsavel_id, 'prioridade' => 1, 'data_entrada' => '2026-09-23',
        ]);
    }

    public function test_criar_demanda_a_partir_do_chamado_liga_os_dois_e_nao_duplica(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $maycon = $this->dev('Maycon Gomes');
        DevDemanda::create(['codigo' => 'DEV-31', 'titulo' => 'existente', 'prioridade' => 2, 'data_entrada' => '2026-09-19']);
        $this->abrir($this->colaborador(), ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->converter($admin, $c)->assertSessionHas('success', 'Demanda DEV-32 criada a partir do TKT-0001.');
        $this->converter($admin, $c)->assertSessionHas('success', 'O TKT-0001 já tinha virado a demanda DEV-32.');

        $this->assertSame(2, DevDemanda::count());
        $demanda = DevDemanda::where('codigo', 'DEV-32')->sole();
        $this->assertSame($demanda->id, $c->fresh()->dev_demanda_id);
        $this->assertSame(1, $demanda->prioridade);
        $this->assertStringContainsString('Origem: chamado TKT-0001', $demanda->observacoes);
        $this->assertSame(1, ChamadoEvento::where('tipo', ChamadoEvento::CONVERTIDO)->count());

        // A demanda aponta de volta para o chamado.
        $this->actingAs($admin)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p
            ->where('demandas', fn ($ds) => collect($ds)->firstWhere('codigo', 'DEV-32')['chamado']['codigo'] === 'TKT-0001'));
    }

    public function test_banco_impede_duas_demandas_no_mesmo_chamado(): void
    {
        $this->abrir($this->colaborador());
        $c = Chamado::sole();
        $d1 = DevDemanda::create(['codigo' => 'DEV-01', 'titulo' => 'a', 'prioridade' => 2, 'data_entrada' => '2026-09-19']);
        $c->update(['dev_demanda_id' => $d1->id]);

        $this->abrir($this->colaborador('Outra'), ['titulo' => 'outro']);
        $outro = Chamado::where('id', '!=', $c->id)->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $outro->update(['dev_demanda_id' => $d1->id]);
    }

    public function test_dev_sem_permissao_de_criar_demanda_nao_converte(): void
    {
        $maycon = $this->dev('Maycon Gomes'); // cargo Dev, mas não admin
        $this->abrir($this->colaborador(), ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->converter($maycon, $c)->assertForbidden();
        $this->assertSame(0, DevDemanda::count());
    }

    public function test_quem_abriu_nao_ve_a_demanda_ligada(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $karen = $this->colaborador();
        $this->abrir($karen);
        $c = Chamado::sole();
        $this->converter($admin, $c);

        $json = json_encode($this->actingAs($karen)->get("/chamados/{$c->id}")->viewData('page')['props']['chamado']);
        $this->assertStringNotContainsString('DEV-', $json);
        $this->assertStringNotContainsString('convertido', $json);
        // E a tela de demandas continua fechada para ela.
        $this->actingAs($karen)->get('/dev/demandas')->assertForbidden();
    }

    public function test_admin_que_abriu_o_proprio_chamado_ve_a_tela_de_solicitante_sem_nada_interno(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->abrir($admin);
        $c = Chamado::sole();
        $this->actingAs($admin)->post("/chamados/{$c->id}/mensagens", ['texto' => 'SEGREDO', 'interna' => true]);
        $this->converter($admin, $c);

        $json = json_encode($this->actingAs($admin)->get("/chamados/{$c->id}")->assertOk()->viewData('page')['props']['chamado']);
        $this->assertStringNotContainsString('SEGREDO', $json);
        $this->assertStringNotContainsString('DEV-', $json);
    }

    // ── Resolução e reabertura ───────────────────────────────────────────────
    public function test_resolver_avisa_quem_abriu_e_ele_pode_reabrir(): void
    {
        Notification::fake();
        $maycon = $this->dev('Maycon Gomes');
        $karen = $this->colaborador();
        $this->abrir($karen, ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->actingAs($maycon)->post("/dev/demandas/chamados/{$c->id}/resolver", ['resolucao' => ''])->assertSessionHasErrors('resolucao');
        $this->actingAs($maycon)->post("/dev/demandas/chamados/{$c->id}/resolver", ['resolucao' => 'Corrigido, pode testar.'])->assertSessionHas('success');
        $c->refresh();
        $this->assertSame(Chamado::STATUS_RESOLVIDO, $c->status);
        $this->assertNotNull($c->resolvido_em);
        Notification::assertSentTo($karen, ChamadoNotification::class, fn ($n) => str_contains($n->titulo, 'resolvido') && $n->url === "/chamados/{$c->id}");

        // Encerrado: mensagem nova exige reabrir.
        $this->actingAs($karen)->post("/chamados/{$c->id}/mensagens", ['texto' => 'ainda dá erro'])->assertSessionHas('error');
        $this->actingAs($karen)->post("/chamados/{$c->id}/reabrir", ['motivo' => 'Ainda dá erro.'])->assertSessionHas('success');
        $this->assertSame(Chamado::STATUS_EM_ATENDIMENTO, $c->fresh()->status);
        $this->assertSame(1, ChamadoEvento::where('tipo', ChamadoEvento::REABERTO)->count());
    }

    public function test_status_manual_gera_evento_e_resposta_do_solicitante_retoma_o_atendimento(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $karen = $this->colaborador();
        $this->abrir($karen, ['responsavel_id' => $maycon->id]);
        $c = Chamado::sole();

        $this->actingAs($maycon)->post("/dev/demandas/chamados/{$c->id}/status", ['status' => 'aguardando_solicitante']);
        $this->actingAs($maycon)->post("/dev/demandas/chamados/{$c->id}/status", ['status' => 'resolvido'])->assertSessionHasErrors('status');
        $this->actingAs($karen)->post("/chamados/{$c->id}/mensagens", ['texto' => 'Aqui está o print.']);

        $this->assertSame(Chamado::STATUS_EM_ATENDIMENTO, $c->fresh()->status);
        $this->assertSame(
            [['aberto', 'aguardando_solicitante'], ['aguardando_solicitante', 'em_atendimento']],
            ChamadoEvento::where('tipo', ChamadoEvento::STATUS)->orderBy('id')->get()->map(fn ($e) => [$e->de, $e->para])->all(),
        );
    }

    // ── Fluxo 11 ─────────────────────────────────────────────────────────────
    public function test_anexos_validam_tipo_e_tamanho_e_ficam_privados(): void
    {
        $karen = $this->colaborador();
        $intrusa = $this->colaborador('Intrusa');

        $this->abrir($karen, ['anexos' => [UploadedFile::fake()->create('pagina.html', 5, 'text/html')]])->assertSessionHasErrors('anexos.0');
        $this->abrir($karen, ['anexos' => [UploadedFile::fake()->image('grande.png')->size(11000)]])->assertSessionHasErrors('anexos.0');
        $this->assertSame(0, Chamado::count());

        $this->abrir($karen, ['anexos' => [UploadedFile::fake()->image('erro.png'), UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')]]);
        $c = Chamado::sole();
        $anexos = $c->anexos()->orderBy('id')->get();
        $this->assertCount(2, $anexos);
        $this->assertSame('image/png', $anexos[0]->mime);
        Storage::disk('local')->assertExists($anexos[0]->caminho);
        $this->assertStringStartsWith("chamados/{$c->id}/", $anexos[0]->caminho);

        $this->actingAs($karen)->get("/chamados/{$c->id}/anexos/{$anexos[0]->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($intrusa)->get("/chamados/{$c->id}/anexos/{$anexos[0]->id}")->assertNotFound();
    }

    public function test_anexo_de_outro_chamado_nao_sai_pela_url_de_um_chamado_meu(): void
    {
        $karen = $this->colaborador();
        $bruno = $this->colaborador('Bruno');
        $this->abrir($karen, ['anexos' => [UploadedFile::fake()->image('dela.png')]]);
        $this->abrir($bruno, ['titulo' => 'meu', 'descricao' => 'meu']);
        $dela = Chamado::where('solicitante_id', $karen->id)->sole();
        $meu = Chamado::where('solicitante_id', $bruno->id)->sole();
        $anexo = $dela->anexos()->sole();

        $this->actingAs($bruno)->get("/chamados/{$meu->id}/anexos/{$anexo->id}")->assertNotFound();
    }

    // ── Fluxo 12 ─────────────────────────────────────────────────────────────
    public function test_caixa_da_equipe_traz_os_campos_dos_filtros_e_meus_chamados_so_os_meus(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $maycon = $this->dev('Maycon Gomes');
        $karen = $this->colaborador();
        $bruno = $this->colaborador('Bruno Lima');
        $this->abrir($karen, ['responsavel_id' => $maycon->id, 'tipo' => 'duvida', 'area' => 'PPA', 'titulo' => 'Como filtro o PPA?']);
        $this->abrir($bruno, ['titulo' => 'Tela branca', 'descricao' => 'x']);

        $this->actingAs($admin)->get('/dev/demandas')->assertInertia(fn (Assert $p) => $p
            ->has('chamados', 2)
            ->where('chamados', fn ($cs) => collect($cs)->pluck('codigo')->sort()->values()->all() === ['TKT-0001', 'TKT-0002']
                && collect($cs)->firstWhere('codigo', 'TKT-0001')['area'] === 'PPA'
                && collect($cs)->firstWhere('codigo', 'TKT-0001')['tipo'] === 'duvida'
                && collect($cs)->firstWhere('codigo', 'TKT-0001')['responsavel']['name'] === 'Maycon Gomes'
                && collect($cs)->firstWhere('codigo', 'TKT-0002')['solicitante'] === 'Bruno Lima'));

        $this->actingAs($karen)->get('/chamados')->assertInertia(fn (Assert $p) => $p
            ->has('chamados', 1)
            ->where('chamados.0.codigo', 'TKT-0001')
            ->missing('chamados.0.demanda')
            ->missing('chamados.0.prioridade_sugerida'));
    }

    // ── Não quebra /dev/demandas ─────────────────────────────────────────────
    public function test_quem_nao_e_equipe_nao_recebe_a_caixa_de_chamados(): void
    {
        $responsavel = $this->colaborador('Resp');
        DevDemanda::create(['codigo' => 'DEV-01', 'titulo' => 'a', 'prioridade' => 2, 'data_entrada' => '2026-09-19', 'responsavel_id' => $responsavel->id]);
        $this->abrir($this->colaborador());

        $this->actingAs($responsavel)->get('/dev/demandas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('equipe', false)
            ->has('chamados', 0)
            ->has('demandas', 1));
    }
}
