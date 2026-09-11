<?php

namespace Tests\Feature\Phase153;

use App\Models\BoasVindasTemplate;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 153 (COMUNIC-01) — a mensagem chega MONTADA no payload da ficha.
 *
 * O teste de `MensagemBoasVindasTest` prova a régua no nível de service; este
 * prova que ela atravessa a rota real e chega à tela, para os dois perfis que a
 * ficha aceita (D-17 da Fase 152).
 */
class MensagemNaFichaTest extends TestCase
{
    use RefreshDatabase;

    private static int $seqCnpj = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        config([
            'services.clicksign.signatarios_ecf' => [],
            'services.adman.register_url'        => 'https://app.ad-man.io/register?ref=TESTE',
        ]);
    }

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Msg 153',
            'slug'   => 'msg-153-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $permissionKey]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    private function empresaPronta(): Company
    {
        $n = str_pad((string) (++self::$seqCnpj), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create([
            'active'            => true,
            'name'              => 'Empresa Msg '.$n,
            'cnpj'              => "16.316.316/{$n}-31",
            'email_cliente'     => 'cliente@example.com',
            'email_colaborador' => 'colaborador@example.com',
            'nome_contato'      => 'Contato Msg',
            'razao_social'      => 'Empresa Msg LTDA',
            'endereco'          => 'Rua Msg, 153',
            'bairro'            => 'Centro',
            'cidade'            => 'Cascavel',
            'estado'            => 'PR',
            'cep'               => '85800-000',
            'etapa'             => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        ]);

        $servico = Servico::create([
            'nome'           => 'Publicação Msg 153',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
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

        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'tok-ficha-153-'.$empresa->id]);

        return $empresa->fresh();
    }

    /** @return array<string, mixed> */
    private function props(User $user, Company $empresa): array
    {
        $r = $this->actingAs($user)->get(route('admin.contratos.show', $empresa));
        $r->assertOk();

        return $r->viewData('page')['props'];
    }

    // ─── COMUNIC-01 — a mensagem chega pronta no payload ────────────────────

    public function test_a_ficha_entrega_a_mensagem_ja_preenchida(): void
    {
        $empresa = $this->empresaPronta();

        $props = $this->props($this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS), $empresa);

        $this->assertArrayHasKey('mensagem_boas_vindas', $props);
        $msg = $props['mensagem_boas_vindas'];

        $this->assertTrue($msg['pronta'], 'Pendências: '.implode(' | ', $msg['pendencias']));
        $this->assertStringContainsString($empresa->name, $msg['texto']);
        $this->assertStringContainsString('colaborador@example.com', $msg['texto']);

        // Nenhum placeholder sobrando no que a tela vai mostrar.
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $msg['texto']);
    }

    // ─── O perfil de Entrada também recebe a mensagem ───────────────────────

    /**
     * A D-17 da Fase 152 abriu a ficha para `comercial.entrada`, e o gating de
     * payload tirou de lá a seção Contrato. A mensagem de boas-vindas é do grupo
     * ENTRADA — quem opera a Entrada tem de recebê-la.
     */
    public function test_usuario_de_entrada_tambem_recebe_a_mensagem(): void
    {
        $empresa = $this->empresaPronta();

        $props = $this->props($this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA), $empresa);

        $this->assertFalse($props['pode_ver_contrato'], 'a fixture precisa ser do perfil de Entrada.');
        $this->assertTrue($props['mensagem_boas_vindas']['pronta']);
        $this->assertNotEmpty($props['mensagem_boas_vindas']['texto']);
    }

    // ─── O template do serviço aparece identificado ─────────────────────────

    public function test_payload_identifica_de_qual_servico_veio_o_texto(): void
    {
        $empresa  = $this->empresaPronta();
        $servicoId = ContratoServico::where('company_id', $empresa->id)->value('servico_id');

        BoasVindasTemplate::salvarParaServico($servicoId, 'TEXTO DEDICADO para {empresa}');

        $msg = $this->props($this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS), $empresa)['mensagem_boas_vindas'];

        $this->assertSame($servicoId, $msg['template_servico_id']);
        $this->assertSame('Publicação Msg 153', $msg['template_servico_nome']);
        $this->assertStringContainsString('TEXTO DEDICADO', $msg['texto']);
    }

    // ─── Pendência atravessa a rota ─────────────────────────────────────────

    public function test_empresa_sem_conexao_ecf_chega_com_pendencia_na_ficha(): void
    {
        $empresa = $this->empresaPronta();
        OnboardingLink::where('company_id', $empresa->id)->delete();

        $msg = $this->props($this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS), $empresa)['mensagem_boas_vindas'];

        $this->assertFalse($msg['pronta']);
        $this->assertNotEmpty($msg['pendencias']);
    }
}
