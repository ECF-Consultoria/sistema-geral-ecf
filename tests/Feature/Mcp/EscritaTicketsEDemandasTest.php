<?php

namespace Tests\Feature\Mcp;

use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use App\Models\DevDemanda;
use App\Models\McpAcesso;
use App\Models\User;
use App\Notifications\ChamadoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DemandasDev\LiberaModulosDev;
use Tests\TestCase;

/**
 * Gravação pelo MCP (decisão de 06/10/2026): ticket e demanda dev pelas
 * ferramentas próprias. Toda gravação passa pelo formulário da tela — por isso
 * as regras conferidas aqui são as da tela (quem pode, campos obrigatórios,
 * aviso ao responsável), chegando pelo MCP.
 */
class EscritaTicketsEDemandasTest extends TestCase
{
    use ChamaMcp, LiberaModulosDev, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Notification::fake();
        $this->withoutVite();
        $this->liberarModulosDev();
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dev(string $nome): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    private function colaborador(string $nome = 'Erlon Gestor'): User
    {
        return User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
    }

    private function abrirTicket(User $quem, array $extra = []): array
    {
        return $this->ferramenta($quem, 'abrir_ticket', $extra + [
            'titulo'    => 'Relatório de NPS por polo',
            'descricao' => 'Preciso ver o NPS separado por polo no painel.',
        ]);
    }

    // ═══ abrir_ticket ═══

    public function test_gestor_abre_ticket_para_o_dev_pelo_nome_e_o_dev_e_avisado(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $this->dev('Lucas Barreto');
        $gestor = $this->colaborador();

        $r = $this->abrirTicket($gestor, ['responsavel' => 'maycon', 'tipo' => 'melhoria']);

        $chamado = Chamado::sole();
        $this->assertSame($gestor->id, $chamado->solicitante_id, 'quem conectou é quem abre');
        $this->assertSame($maycon->id, $chamado->responsavel_id);
        $this->assertSame('melhoria', $chamado->tipo);
        $this->assertSame('normal', $chamado->impacto, 'impacto padrão quando a conversa não diz');

        $this->assertSame($chamado->codigo, $r['ticket']['codigo']);
        $this->assertSame('Maycon Gomes', $r['ticket']['responsavel']);
        $this->assertSame('Aberto', $r['ticket']['status']);
        $this->assertStringEndsWith('/tickets/'.$chamado->id, $r['ticket']['link']);
        $this->assertStringContainsString($chamado->codigo, (string) $r['mensagem']);

        Notification::assertSentTo($maycon, ChamadoNotification::class);
        $this->assertTrue(McpAcesso::where('ferramenta', 'abrir_ticket')->where('sucesso', true)->exists());
    }

    public function test_sem_responsavel_o_ticket_vai_para_a_fila(): void
    {
        $this->dev('Maycon Gomes');

        $r = $this->abrirTicket($this->colaborador());

        $this->assertNull(Chamado::sole()->responsavel_id);
        $this->assertStringContainsString('Fila', $r['ticket']['responsavel']);
    }

    public function test_nome_que_bate_com_dois_devs_pede_para_escolher_e_nao_abre(): void
    {
        $this->dev('Maycon Gomes');
        $this->dev('Maycon Silva');

        $erro = $this->erroDaFerramenta($this->colaborador(), 'abrir_ticket', [
            'titulo' => 'X', 'descricao' => 'Y', 'responsavel' => 'Maycon',
        ]);

        $this->assertStringContainsString('mais de um', $erro);
        $this->assertStringContainsString('Maycon Gomes', $erro);
        $this->assertStringContainsString('Maycon Silva', $erro);
        $this->assertSame(0, Chamado::count());
    }

    public function test_campo_obrigatorio_faltando_volta_o_nome_do_campo(): void
    {
        $erro = $this->erroDaFerramenta($this->colaborador(), 'abrir_ticket', ['titulo' => 'Sem descrição', 'descricao' => '']);

        $this->assertStringContainsString('descricao', $erro);
        $this->assertSame(0, Chamado::count());
    }

    public function test_reenvio_no_mesmo_minuto_nao_duplica(): void
    {
        $gestor = $this->colaborador();

        $primeiro = $this->abrirTicket($gestor);
        $segundo  = $this->abrirTicket($gestor);

        $this->assertSame(1, Chamado::count());
        $this->assertSame($primeiro['ticket']['codigo'], $segundo['ticket']['codigo']);
        $this->assertStringContainsString('já foi aberto', (string) $segundo['mensagem']);
    }

    // ═══ atuar_no_ticket ═══

    public function test_dev_responde_muda_status_e_resolve_pelo_mcp(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $gestor = $this->colaborador();
        $codigo = $this->abrirTicket($gestor, ['responsavel' => 'Maycon'])['ticket']['codigo'];

        $this->ferramenta($maycon, 'atuar_no_ticket', ['ticket' => $codigo, 'acao' => 'responder', 'texto' => 'Olhando a consulta.', 'interna' => true]);
        $this->assertTrue(ChamadoMensagem::where('texto', 'Olhando a consulta.')->sole()->ehInterna());

        $r = $this->ferramenta($maycon, 'atuar_no_ticket', ['ticket' => $codigo, 'acao' => 'mudar_status', 'status' => 'em_atendimento']);
        $this->assertSame('Em atendimento', $r['ticket']['status']);

        $r = $this->ferramenta($maycon, 'atuar_no_ticket', ['ticket' => $codigo, 'acao' => 'resolver', 'texto' => 'Coluna de polo no painel do NPS.']);
        $this->assertSame('Resolvido', $r['ticket']['status']);
        $this->assertSame('Coluna de polo no painel do NPS.', Chamado::sole()->resolucao);
    }

    public function test_quem_abriu_responde_mas_nao_age_como_equipe(): void
    {
        $this->dev('Maycon Gomes');
        $gestor = $this->colaborador();
        $id     = $this->abrirTicket($gestor)['ticket']['id'];

        // Mensagem pública: pode (e o id serve no lugar do código).
        $this->ferramenta($gestor, 'atuar_no_ticket', ['ticket' => (string) $id, 'acao' => 'responder', 'texto' => 'Urgente para a reunião de sexta.']);

        // Status é da equipe dev: o controller recusa com 403.
        $erro = $this->erroDaFerramenta($gestor, 'atuar_no_ticket', ['ticket' => (string) $id, 'acao' => 'mudar_status', 'status' => 'em_atendimento']);
        $this->assertStringContainsString('não pode', $erro);
        $this->assertSame(Chamado::STATUS_ABERTO, Chamado::sole()->status);
    }

    public function test_transferir_pelo_nome_e_recusa_da_tela_vira_erro(): void
    {
        $maycon  = $this->dev('Maycon Gomes');
        $barreto = $this->dev('Lucas Barreto');
        $codigo  = $this->abrirTicket($this->colaborador(), ['responsavel' => 'Maycon'])['ticket']['codigo'];

        $r = $this->ferramenta($maycon, 'atuar_no_ticket', ['ticket' => $codigo, 'acao' => 'transferir', 'para' => 'Barreto', 'texto' => 'É da área dele.']);
        $this->assertSame('Lucas Barreto', $r['ticket']['responsavel']);
        $this->assertSame($barreto->id, Chamado::sole()->responsavel_id);

        // Reabrir ticket que está aberto: o serviço recusa e a tela mostra em
        // flash `error` — pelo MCP isso tem de chegar como erro, não como OK.
        $erro = $this->erroDaFerramenta($barreto, 'atuar_no_ticket', ['ticket' => $codigo, 'acao' => 'reabrir']);
        $this->assertStringContainsString('A tela recusou', $erro);
    }

    // ═══ salvar_demanda ═══

    public function test_admin_cadastra_e_edita_demanda_pelo_mcp(): void
    {
        $maycon = $this->dev('Maycon Gomes');
        $admin  = $this->admin();

        $r = $this->ferramenta($admin, 'salvar_demanda', [
            'titulo'      => 'MCP grava no sistema',
            'responsavel' => 'Maycon',
            'prioridade'  => 1,
            'prazo'       => '2026-10-20',
        ]);

        $demanda = DevDemanda::sole();
        $this->assertSame('DEV-01', $demanda->codigo);
        $this->assertSame($maycon->id, $demanda->responsavel_id);
        $this->assertSame(1, $demanda->prioridade);
        $this->assertSame('2026-10-06', $demanda->data_entrada->toDateString(), 'entrada padrão = hoje');
        $this->assertSame($admin->id, $demanda->criado_por);
        $this->assertSame('DEV-01', $r['demanda']['codigo']);

        // Edição manda só o que muda; o resto continua.
        $r = $this->ferramenta($admin, 'salvar_demanda', ['demanda' => 'dev-01', 'prioridade' => 0]);
        $demanda->refresh();
        $this->assertSame(0, $demanda->prioridade);
        $this->assertSame('MCP grava no sistema', $demanda->titulo);
        $this->assertSame($maycon->id, $demanda->responsavel_id);
        $this->assertSame('P0 - Crítica', $r['demanda']['prioridade']);
    }

    public function test_so_admin_cadastra_demanda(): void
    {
        $this->assertNotContains('salvar_demanda', $this->ferramentasVisiveis($this->dev('Maycon Gomes')));
        $this->assertContains('salvar_demanda', $this->ferramentasVisiveis($this->admin()));
    }

    // ═══ registrar_atualizacao_demanda ═══

    public function test_responsavel_registra_andamento_e_comecar_exige_prazo(): void
    {
        $maycon  = $this->dev('Maycon Gomes');
        $demanda = DevDemanda::create([
            'codigo' => 'DEV-07', 'titulo' => 'Tela nova', 'prioridade' => 2,
            'data_entrada' => '2026-10-01', 'responsavel_id' => $maycon->id,
        ]);

        // Começar sem prazo: a tela exige — e o MCP devolve o campo.
        $erro = $this->erroDaFerramenta($maycon, 'registrar_atualizacao_demanda', ['demanda' => 'DEV-07', 'status' => 'em_desenvolvimento']);
        $this->assertStringContainsString('previsao_revisada', $erro);

        $r = $this->ferramenta($maycon, 'registrar_atualizacao_demanda', [
            'demanda' => 'DEV-07', 'status' => 'em_desenvolvimento', 'prazo' => '2026-10-16', 'proxima_acao' => 'Testes',
        ]);

        $this->assertSame('Em desenvolvimento', $r['demanda']['status']);
        $this->assertSame('2026-10-16', $demanda->fresh()->prazo->toDateString());
        $linha = $demanda->atualizacoes()->sole();
        $this->assertSame($maycon->id, $linha->user_id);
        $this->assertSame('2026-10-06', $linha->data->toDateString());
    }

    public function test_quem_nao_e_responsavel_nao_registra_andamento(): void
    {
        $maycon  = $this->dev('Maycon Gomes');
        $outro   = $this->dev('Lucas Barreto');
        DevDemanda::create([
            'codigo' => 'DEV-08', 'titulo' => 'Do Maycon', 'prioridade' => 2,
            'data_entrada' => '2026-10-01', 'responsavel_id' => $maycon->id,
        ]);

        $erro = $this->erroDaFerramenta($outro, 'registrar_atualizacao_demanda', ['demanda' => 'DEV-08', 'status' => 'a_fazer']);

        $this->assertStringContainsString('Só o responsável', $erro);
        $this->assertSame(0, DevDemanda::where('codigo', 'DEV-08')->sole()->atualizacoes()->count());
    }

    // ═══ Chave ═══

    public function test_escrita_desligada_tira_as_ferramentas_de_gravacao_e_mantem_a_leitura(): void
    {
        config(['mcp.ecf_escrita_habilitada' => false]);

        $visiveis = $this->ferramentasVisiveis($this->admin());

        foreach (['abrir_ticket', 'atuar_no_ticket', 'salvar_demanda', 'registrar_atualizacao_demanda', 'listar_acoes', 'enviar_formulario'] as $escrita) {
            $this->assertNotContains($escrita, $visiveis);
        }
        $this->assertContains('ler_tela', $visiveis);
        $this->assertContains('demandas_dev', $visiveis);
    }
}
