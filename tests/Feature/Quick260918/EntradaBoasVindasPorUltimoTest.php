<?php

namespace Tests\Feature\Quick260918;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\PortalUsuario;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 2026-09-18 — "Boas-vindas enviada" virou o ÚLTIMO item do checklist
 * administrativo, e "E-mail colaborador criado" passou a gravar o endereço na
 * própria linha.
 *
 * Pedido do usuário, com o motivo dito por ele: a mensagem de boas-vindas é
 * montada com o que foi preenchido antes (o e-mail colaborador, o endereço do
 * Portal do Cliente), então mandá-la em 4º lugar era mandar texto com bloco
 * vazio.
 *
 * O que estes casos prendem:
 *
 * 1. a ORDEM do catálogo (boas-vindas por último);
 * 2. a TRAVA de ordem — `depende_de`, recusada no servidor, não só no botão;
 * 3. quais itens NÃO travam as boas-vindas, e por quê (link do Adman e grant
 *    do Mercado Livre são consequência da mensagem — exigi-los fecharia um
 *    ciclo sem saída);
 * 4. a TRAVA de evidência — `exige_valor` no e-mail colaborador;
 * 5. o endpoint que grava o endereço: salvar conclui, limpar reabre.
 */
class EntradaBoasVindasPorUltimoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        config(['services.clicksign.signatarios_ecf' => []]);

        // A asserção é sobre o PAYLOAD da ficha, nunca sobre o bundle: sem
        // isto o teste passa a depender de `public/build/manifest.json`
        // existir, e quebra em worktree recém-criado por motivo nenhum.
        $this->withoutVite();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private static int $sequenciaCnpj = 0;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(array $overrides = []): Company
    {
        $n = str_pad((string) (++self::$sequenciaCnpj), 4, '0', STR_PAD_LEFT);

        return Company::factory()->create(array_merge([
            'active'        => true,
            'etapa'         => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            'cnpj'          => "44.555.666/{$n}-09",
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
        ], $overrides));
    }

    /** Serviço ISENTO de contrato — só os 6 itens do grupo Entrada (D-07). */
    private function empresaIsenta(array $overrides = []): Company
    {
        $empresa = $this->empresa($overrides);

        $servico = Servico::create([
            'nome'           => 'Serviço Isento '.uniqid(),
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'setor'          => Servico::SETOR_POLOS,
            'exige_contrato' => false,
            'ativo'          => true,
        ]);

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));

        return $empresa->fresh();
    }

    private function service(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    /** Fecha o item automático "Portal do Cliente" pelo ESTADO REAL. */
    private function criarAcessoPortal(Company $empresa): void
    {
        $acesso = PortalUsuario::create([
            'nome'  => 'Cliente',
            'email' => 'portal-260918.'.$empresa->id.'@example.test',
            'ativo' => true,
        ]);

        $acesso->empresas()->attach($empresa->id, ['principal' => true]);
    }

    /** O item achatado, procurado por chave nos grupos já montados. */
    private function item(Company $empresa, string $chave): array
    {
        foreach ($this->service()->paraEmpresa($empresa->fresh())['grupos'] as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if ($item['chave'] === $chave) {
                    return $item;
                }
            }
        }

        $this->fail("Item \"{$chave}\" não apareceu no checklist.");
    }

    // ─── Caso 1 — a ordem do catálogo ───────────────────────────────────────

    public function test_boas_vindas_e_o_ultimo_item_do_catalogo(): void
    {
        $comContrato = ChecklistAdministrativoDefinicao::chaves(true);
        $isento      = ChecklistAdministrativoDefinicao::chaves(false);

        $this->assertSame('boas_vindas_enviada', end($comContrato));
        $this->assertSame('boas_vindas_enviada', end($isento));

        // E continua sendo o 9º/6º — nada foi acrescentado nem removido do
        // catálogo fechado, só reordenado.
        $this->assertCount(9, $comContrato);
        $this->assertCount(6, $isento);
    }

    // ─── Caso 2 — a trava de ordem recusa no SERVIDOR ───────────────────────

    public function test_marcar_boas_vindas_antes_das_dependencias_e_recusado(): void
    {
        $empresa = $this->empresaIsenta();
        $usuario = $this->admin();

        $this->expectException(\DomainException::class);

        $this->service()->concluirManualmente($empresa, 'boas_vindas_enviada', $usuario);
    }

    public function test_o_payload_diz_o_que_falta_antes_das_boas_vindas(): void
    {
        $empresa = $this->empresaIsenta();

        $bloqueio = $this->item($empresa, 'boas_vindas_enviada')['bloqueio'];

        $this->assertNotNull($bloqueio, 'Boas-vindas deveria nascer travada.');
        $this->assertStringContainsString('Grupo de WhatsApp criado', $bloqueio);
        $this->assertStringContainsString('E-mail colaborador criado', $bloqueio);
        $this->assertStringContainsString('Portal do Cliente', $bloqueio);
    }

    // ─── Caso 3 — com as dependências fechadas, as boas-vindas fecham ───────

    public function test_com_as_dependencias_fechadas_as_boas_vindas_podem_ser_marcadas(): void
    {
        $empresa = $this->empresaIsenta();
        $usuario = $this->admin();

        $empresa->update(['email_colaborador' => 'colab@ecf.test']);
        $this->service()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);
        $this->service()->concluirManualmente($empresa, 'email_colaborador_criado', $usuario);
        $this->criarAcessoPortal($empresa);

        $this->assertNull(
            $this->item($empresa, 'boas_vindas_enviada')['bloqueio'],
            'Com WhatsApp, e-mail e portal fechados, nada deveria travar as boas-vindas.'
        );

        $this->service()->concluirManualmente($empresa->fresh(), 'boas_vindas_enviada', $usuario);

        $this->assertSame(
            ChecklistAdministrativoItem::STATUS_CONCLUIDO,
            $this->item($empresa, 'boas_vindas_enviada')['status']
        );
    }

    /**
     * ⚠️ Este caso é a guarda contra um ciclo sem saída, não um detalhe.
     *
     * O link do Adman e o grant do Mercado Livre são ENTREGUES PELA mensagem de
     * boas-vindas. Se algum deles entrasse em `depende_de`, o cliente nunca
     * receberia a mensagem (porque não autorizou) e nunca autorizaria (porque
     * não recebeu a mensagem). Se alguém acrescentar um dos dois à lista de
     * dependências, é aqui que vai quebrar.
     */
    public function test_link_adman_e_grant_ml_nao_travam_as_boas_vindas(): void
    {
        $empresa = $this->empresaIsenta();
        $usuario = $this->admin();

        $empresa->update(['email_colaborador' => 'colab@ecf.test']);
        $this->service()->concluirManualmente($empresa, 'grupo_whatsapp_criado', $usuario);
        $this->service()->concluirManualmente($empresa, 'email_colaborador_criado', $usuario);
        $this->criarAcessoPortal($empresa);

        // Os dois seguem ABERTOS de propósito.
        $this->assertSame(ChecklistAdministrativoItem::STATUS_ABERTO, $this->item($empresa, 'link_adman_entregue')['status']);
        $this->assertSame(ChecklistAdministrativoItem::STATUS_ABERTO, $this->item($empresa, 'grant_consultoria_ml')['status']);

        $this->assertNull($this->item($empresa, 'boas_vindas_enviada')['bloqueio']);
    }

    // ─── Caso 4 — a trava de evidência do e-mail colaborador ────────────────

    public function test_marcar_email_colaborador_sem_endereco_gravado_e_recusado(): void
    {
        $empresa = $this->empresaIsenta(['email_colaborador' => null]);
        $usuario = $this->admin();

        $this->assertNotNull($this->item($empresa, 'email_colaborador_criado')['bloqueio']);

        $this->expectException(\DomainException::class);

        $this->service()->concluirManualmente($empresa, 'email_colaborador_criado', $usuario);
    }

    // ─── Caso 5 — o endpoint que grava o endereço ───────────────────────────

    public function test_salvar_o_email_grava_na_empresa_e_conclui_o_item(): void
    {
        $empresa = $this->empresaIsenta(['email_colaborador' => null]);
        $usuario = $this->admin();

        $this->actingAs($usuario)
            ->post(route('admin.contratos.checklist.email-colaborador', $empresa), [
                'email_colaborador' => 'operacao@clienteteste.com.br',
            ])
            ->assertStatus(302)
            ->assertSessionHas('success');

        // Reconsulta ao banco — nunca a instância em memória.
        $this->assertSame('operacao@clienteteste.com.br', $empresa->fresh()->email_colaborador);

        $item = $this->item($empresa, 'email_colaborador_criado');
        $this->assertSame(ChecklistAdministrativoItem::STATUS_CONCLUIDO, $item['status']);
        $this->assertSame($usuario->name, $item['feito_por_nome'], 'A autoria precisa sobreviver ao atalho.');
    }

    public function test_limpar_o_email_reabre_o_item(): void
    {
        $empresa = $this->empresaIsenta(['email_colaborador' => null]);
        $usuario = $this->admin();

        $this->actingAs($usuario)->post(
            route('admin.contratos.checklist.email-colaborador', $empresa),
            ['email_colaborador' => 'operacao@clienteteste.com.br']
        )->assertStatus(302);

        $this->actingAs($usuario)->post(
            route('admin.contratos.checklist.email-colaborador', $empresa),
            ['email_colaborador' => '']
        )->assertStatus(302)->assertSessionHas('success');

        $this->assertNull($empresa->fresh()->email_colaborador);
        $this->assertSame(
            ChecklistAdministrativoItem::STATUS_ABERTO,
            $this->item($empresa, 'email_colaborador_criado')['status']
        );
    }

    public function test_endereco_invalido_e_recusado_pela_validacao(): void
    {
        $empresa = $this->empresaIsenta(['email_colaborador' => null]);

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.checklist.email-colaborador', $empresa), [
                'email_colaborador' => 'nao-e-um-email',
            ])
            ->assertSessionHasErrors('email_colaborador');

        $this->assertNull($empresa->fresh()->email_colaborador);
    }

    // ─── Caso 6 — a ficha entrega o que a tela precisa ──────────────────────

    public function test_a_ficha_entrega_o_email_gravado_e_a_trava_de_cada_item(): void
    {
        $empresa = $this->empresaIsenta(['email_colaborador' => 'ja@gravado.test']);

        $props = $this->actingAs($this->admin())
            ->get(route('comercial.entrada.show', $empresa))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('ja@gravado.test', $props['company']['email_colaborador']);

        $chaves = collect($props['checklist']['grupos'])
            ->flatMap(fn ($grupo) => $grupo['itens'])
            ->pluck('chave')
            ->all();

        $this->assertSame('boas_vindas_enviada', end($chaves), 'A tela precisa receber as boas-vindas por último.');

        $boasVindas = collect($props['checklist']['grupos'])
            ->flatMap(fn ($grupo) => $grupo['itens'])
            ->firstWhere('chave', 'boas_vindas_enviada');

        $this->assertArrayHasKey('bloqueio', $boasVindas);
        $this->assertNotNull($boasVindas['bloqueio']);
    }
}
