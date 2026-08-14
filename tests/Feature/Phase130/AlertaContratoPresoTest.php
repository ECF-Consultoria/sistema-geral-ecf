<?php

namespace Tests\Feature\Phase130;

use App\Models\ContratoAssinatura;
use App\Models\Setor;
use App\Models\User;
use App\Notifications\Categoria;
use App\Notifications\ContratoPresoNotification;
use App\Services\Contratos\ContratosPresosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 130 Plano 05 (REDE-02, D-01, D-02, D-04, D-05) — alerta de contrato
 * preso.
 *
 * Task 1: testes de PAYLOAD da notificação (via/toArray/meta/mensagem/
 * rótulos). Task 2 estende esta suíte com os testes de comportamento do
 * comando `clicksign:alertar-presos` (audiência, cooldown, os 7 estados).
 */
class AlertaContratoPresoTest extends TestCase
{
    use RefreshDatabase;

    private function contrato(string $status = ContratoAssinatura::STATUS_RECUSADO): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->create(['status' => $status]);
    }

    // ─── Task 1: payload da notificação ─────────────────────────────────

    public function test_via_devolve_apenas_database(): void
    {
        $contrato = $this->contrato();
        $user     = User::factory()->make();

        $notification = new ContratoPresoNotification($contrato, ContratosPresosService::CAUSA_RECUSADO, 9);

        $this->assertSame(['database'], $notification->via($user));
    }

    public function test_toarray_tem_as_6_chaves_canonicas_categoria_manual_e_url_correta(): void
    {
        $contrato = $this->contrato();
        $user     = User::factory()->make();

        $notification = new ContratoPresoNotification($contrato, ContratosPresosService::CAUSA_RECUSADO, 9);
        $dados         = $notification->toArray($user);

        $this->assertSame(
            ['titulo', 'mensagem', 'categoria', 'autor_user_id', 'url', 'meta'],
            array_keys($dados)
        );
        $this->assertSame(Categoria::MANUAL->value, $dados['categoria']);
        $this->assertSame(route('contratos.liberacao-manual.index'), $dados['url']);
    }

    public function test_meta_contem_ids_status_causa_dias_e_nunca_pii(): void
    {
        $contrato = $this->contrato();
        $user     = User::factory()->make();

        $notification = new ContratoPresoNotification($contrato, ContratosPresosService::CAUSA_RECUSADO, 9);
        $meta          = $notification->toArray($user)['meta'];

        $this->assertSame($contrato->id, $meta['contrato_assinatura_id']);
        $this->assertSame($contrato->company_id, $meta['company_id']);
        $this->assertSame($contrato->status, $meta['status']);
        $this->assertSame(ContratosPresosService::CAUSA_RECUSADO, $meta['causa']);
        $this->assertSame(9, $meta['dias_parado']);

        foreach (array_keys($meta) as $chave) {
            $this->assertStringNotContainsStringIgnoringCase('email', $chave);
            $this->assertStringNotContainsStringIgnoringCase('cpf', $chave);
            $this->assertStringNotContainsStringIgnoringCase('documento', $chave);
        }
    }

    public function test_mensagem_de_recusado_e_diferente_de_erro_e_nenhuma_usa_jargao(): void
    {
        $contratoRecusado = $this->contrato(ContratoAssinatura::STATUS_RECUSADO);
        $contratoErro     = $this->contrato(ContratoAssinatura::STATUS_ERRO);
        $user             = User::factory()->make();

        $mensagemRecusado = (new ContratoPresoNotification($contratoRecusado, ContratosPresosService::CAUSA_RECUSADO, 9))
            ->toArray($user)['mensagem'];
        $mensagemErro = (new ContratoPresoNotification($contratoErro, ContratosPresosService::CAUSA_ERRO, 9))
            ->toArray($user)['mensagem'];

        $this->assertNotSame($mensagemRecusado, $mensagemErro);

        foreach ([$mensagemRecusado, $mensagemErro] as $mensagem) {
            $this->assertStringNotContainsStringIgnoringCase('webhook', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('envelope', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('payload', $mensagem);
        }
    }

    public function test_todas_as_7_causas_tem_rotulo_definido(): void
    {
        $reflectionService = new \ReflectionClass(ContratosPresosService::class);
        $causas = collect($reflectionService->getConstants())
            ->filter(fn ($valor, $nome) => str_starts_with($nome, 'CAUSA_'))
            ->values();

        $reflectionNotification = new \ReflectionClass(ContratoPresoNotification::class);
        $labels                 = $reflectionNotification->getConstant('LABELS_CAUSA');

        $this->assertCount(7, $causas);

        foreach ($causas as $causa) {
            $this->assertArrayHasKey($causa, $labels, "Falta rótulo para a causa '{$causa}'.");
            $this->assertNotSame('', trim($labels[$causa]));
        }
    }

    // ─── Task 2: comportamento do comando clicksign:alertar-presos ─────

    private function admin(bool $ativo = true): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => $ativo]);
    }

    private function comercial(bool $ativo = true): User
    {
        $setor = Setor::firstOrCreate(
            ['slug' => 'comercial'],
            ['nome' => 'Comercial', 'active' => true, 'is_system' => false],
        );

        $user = User::factory()->create(['role' => 'consultor', 'active' => $ativo]);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    private function usuarioDeOutroSetor(): User
    {
        $setor = Setor::firstOrCreate(
            ['slug' => 'desenvolvimento'],
            ['nome' => 'Desenvolvimento', 'active' => true, 'is_system' => false],
        );

        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($user->id, ['is_principal' => true, 'assigned_at' => now()]);

        return $user;
    }

    /**
     * Contrato preso o suficiente para disparar o alerta. `enviado_em` é a
     * data base para todos os estados usados aqui (correção 260814-cro:
     * `dataBase()` nunca usa `updated_at` — `enviado_em ?? created_at`).
     */
    private function contratoPresoAntigo(string $status = ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->create([
            'status'     => $status,
            'enviado_em' => now()->subDays(10),
        ]);
    }

    public function test_contrato_preso_notifica_admin_e_comercial_mas_nao_inativo_nem_outro_setor(): void
    {
        Notification::fake();

        $admin            = $this->admin();
        $comercialAtivo   = $this->comercial();
        $comercialInativo = $this->comercial(false);
        $outroSetor       = $this->usuarioDeOutroSetor();

        $this->contratoPresoAntigo();

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertSentTo($admin, ContratoPresoNotification::class);
        Notification::assertSentTo($comercialAtivo, ContratoPresoNotification::class);
        Notification::assertNotSentTo($comercialInativo, ContratoPresoNotification::class);
        Notification::assertNotSentTo($outroSetor, ContratoPresoNotification::class);
    }

    public function test_contrato_dentro_do_prazo_nao_gera_alerta(): void
    {
        Notification::fake();
        $this->admin();

        ContratoAssinatura::factory()->emAndamento()->create([
            'enviado_em' => now()->subDay(),
        ]);

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_contrato_liberado_mesmo_velho_nao_gera_alerta(): void
    {
        Notification::fake();
        $this->admin();

        ContratoAssinatura::factory()->assinado()->create([
            'assinado_em' => now()->subDays(30),
            'liberado_em' => now()->subDays(20),
        ]);

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_contratos_recusado_expirado_e_erro_velhos_geram_alerta(): void
    {
        // D-05: o alerta cobre TUDO que não terminou bem, não só o que a
        // reconciliação corrige.
        Notification::fake();
        $admin = $this->admin();

        $this->contratoPresoAntigo(ContratoAssinatura::STATUS_RECUSADO);
        $this->contratoPresoAntigo(ContratoAssinatura::STATUS_EXPIRADO);
        $this->contratoPresoAntigo(ContratoAssinatura::STATUS_ERRO);

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertSentToTimes($admin, ContratoPresoNotification::class, 3);
    }

    public function test_audiencia_vazia_nao_envia_e_nao_grava_ultimo_alerta_em(): void
    {
        Notification::fake();

        $contrato = $this->contratoPresoAntigo();

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull(ContratoAssinatura::find($contrato->id)->ultimo_alerta_em);
    }
}
