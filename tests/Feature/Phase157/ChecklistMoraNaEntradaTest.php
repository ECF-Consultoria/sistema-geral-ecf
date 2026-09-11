<?php

namespace Tests\Feature\Phase157;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 11/09 — o checklist administrativo mora na ficha da ENTRADA, e a ficha de
 * Contrato voltou a ser só o contrato.
 *
 * A ficha "única" da Fase 152 (D-08) juntava as duas coisas. Dos 9 itens do
 * checklist só 3 são contratuais; os outros 6 são o que o PDF do fluxo chama
 * de "Estrutura e Comunicação". Ler tudo sob o título "Contrato" confundia.
 *
 * Este teste existe para a separação não voltar sozinha num refactor.
 */
class ChecklistMoraNaEntradaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function empresa(): Company
    {
        $n = str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active' => true,
            'name'   => 'Empresa 157C '.$n,
            'cnpj'   => "15.715.715/{$n}-71",
        ]);

        $servico = Servico::create([
            'nome'           => 'Serviço 157C '.$n,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => true,
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

    private function userCom(string $permissao): User
    {
        $n = ++self::$seq;
        $setor = Setor::firstOrCreate(
            ['slug' => 'setor-157c-'.$n],
            ['nome' => 'Setor 157C '.$n, 'active' => true]
        );
        SetorPermissao::firstOrCreate(['setor_id' => $setor->id, 'permission_key' => $permissao]);

        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    // ─── A separação ────────────────────────────────────────────────────────

    public function test_a_ficha_de_contrato_nao_entrega_mais_o_checklist(): void
    {
        $props = $this->actingAs($this->userCom(Permissions::ADMIN_CONTRATOS))
            ->get(route('admin.contratos.show', $this->empresa()))
            ->assertOk()
            ->viewData('page')['props'];

        foreach (['checklist', 'pode_finalizar', 'adman_register_url', 'portal_cliente_url', 'mensagem_boas_vindas'] as $chave) {
            $this->assertArrayNotHasKey(
                $chave,
                $props,
                "'{$chave}' é do checklist e não pode voltar para a ficha de Contrato."
            );
        }

        // O que a ficha de Contrato continua sendo: o contrato.
        $this->assertArrayHasKey('contratos', $props);
        $this->assertArrayHasKey('pode_gerar_contrato', $props);
        $this->assertArrayHasKey('contratos_servico', $props);
    }

    public function test_a_ficha_de_entrada_entrega_o_checklist_completo(): void
    {
        $props = $this->actingAs($this->userCom(Permissions::ADMIN_CONTRATOS))
            ->get(route('comercial.entrada.show', $this->empresa()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Comercial/EntradaFicha'))
            ->viewData('page')['props'];

        $this->assertArrayHasKey('grupos', $props['checklist']);
        $this->assertArrayHasKey('permitido', $props['pode_finalizar']);
        $this->assertNotEmpty($props['adman_register_url']);
        $this->assertSame(9, $props['checklist']['progresso']['total']);
    }

    /** As duas fichas se alcançam — quem marca "Contrato enviado" precisa do documento. */
    public function test_as_duas_fichas_apontam_uma_para_a_outra(): void
    {
        $empresa = $this->empresa();
        $user    = $this->userCom(Permissions::ADMIN_CONTRATOS);

        $daEntrada = $this->actingAs($user)->get(route('comercial.entrada.show', $empresa))
            ->viewData('page')['props'];
        $this->assertSame(route('admin.contratos.show', $empresa->id), $daEntrada['ficha_contrato_url']);

        $doContrato = $this->actingAs($user)->get(route('admin.contratos.show', $empresa))
            ->viewData('page')['props'];
        $this->assertSame(route('comercial.entrada.show', $empresa->id), $doContrato['ficha_entrada_url']);
    }

    // ─── Permissão: a régua não mudou de lugar ──────────────────────────────

    public function test_a_rota_nova_aceita_as_duas_permissoes_em_or(): void
    {
        $rota = Route::getRoutes()->getByName('comercial.entrada.show');

        $this->assertNotNull($rota);
        $this->assertContains(
            'permission:'.Permissions::COMERCIAL_ENTRADA.','.Permissions::ADMIN_CONTRATOS,
            $rota->gatherMiddleware(),
            'quem tem só admin.contratos precisa alcançar o checklist, e vice-versa (D-17).'
        );
        $this->assertNotContains('role:admin', $rota->gatherMiddleware(), 'a chave tem de ser liberável por setor.');
    }

    public function test_quem_nao_tem_nenhuma_das_duas_chaves_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor', 'active' => true]))
            ->get(route('comercial.entrada.show', $this->empresa()))
            ->assertStatus(403);
    }

    /**
     * O recorte de módulo acompanhou a mudança de casa: perfil de Entrada não
     * vê o grupo Contrato do checklist nem o acesso ao documento.
     */
    public function test_perfil_de_entrada_nao_recebe_dado_contratual(): void
    {
        $props = $this->actingAs($this->userCom(Permissions::COMERCIAL_ENTRADA))
            ->get(route('comercial.entrada.show', $this->empresa()))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertFalse($props['pode_ver_contrato']);
        $this->assertNull($props['contrato_acesso']);
        $this->assertNull($props['ficha_contrato_url']);
        $this->assertArrayNotHasKey('contrato', $props['checklist']['grupos']);
        $this->assertArrayHasKey('entrada', $props['checklist']['grupos']);

        // O progresso segue sendo o da empresa INTEIRA — é a régua do FINALIZAR.
        $this->assertSame(9, $props['checklist']['progresso']['total']);
    }
}
