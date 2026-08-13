<?php

namespace Tests\Feature\Phase130;

use App\Console\Commands\ClicksignAlertarPresos;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\User;
use App\Notifications\ContratoPresoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 130 Plano 05 (D-04) — cooldown de repetição do alerta.
 *
 * O alerta REPETE em intervalo até alguém resolver: não dispara mais de uma
 * vez dentro da janela, e volta a disparar quando a janela expira. Toda
 * asserção de gravação reconsulta o banco fresco, nunca stdout.
 */
class AlertaCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function contratoPreso(): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->emAndamento()->create([
            'enviado_em' => now()->subDays(10),
        ]);
    }

    public function test_primeira_execucao_envia_e_grava_ultimo_alerta_em(): void
    {
        Notification::fake();
        $admin    = $this->admin();
        $contrato = $this->contratoPreso();

        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertSentTo($admin, ContratoPresoNotification::class);
        $this->assertNotNull(ContratoAssinatura::find($contrato->id)->ultimo_alerta_em);
    }

    public function test_segunda_execucao_imediata_nao_envia_nada(): void
    {
        $this->admin();
        $this->contratoPreso();

        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        // Reseta o spy: a asserção abaixo cobre SÓ a segunda execução.
        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_apos_intervalo_configurado_repete_o_alerta_e_atualiza_o_carimbo(): void
    {
        $admin    = $this->admin();
        $contrato = $this->contratoPreso();

        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);
        Notification::assertSentTo($admin, ContratoPresoNotification::class);

        $primeiroCarimbo = ContratoAssinatura::find($contrato->id)->ultimo_alerta_em;
        $this->assertNotNull($primeiroCarimbo);

        // Recua o carimbo além do intervalo default (3 dias) — D-04.
        $carimboRecuado = now()->subDays(4);
        ContratoAssinatura::find($contrato->id)->update(['ultimo_alerta_em' => $carimboRecuado]);

        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertSentTo($admin, ContratoPresoNotification::class);
        // O carimbo novo precisa ser recente (recém-atualizado pelo comando),
        // não o valor recuado — comparar contra $carimboRecuado em vez de
        // $primeiroCarimbo evita falso-negativo por os dois carimbos caírem
        // no mesmo segundo (precisão do cast `datetime`).
        $segundoCarimbo = ContratoAssinatura::find($contrato->id)->ultimo_alerta_em;
        $this->assertTrue($segundoCarimbo->gt($carimboRecuado));
    }

    public function test_intervalo_configuravel_via_configuracao_muda_o_comportamento(): void
    {
        $admin    = $this->admin();
        $contrato = $this->contratoPreso();

        // Intervalo customizado de 10 dias, sem deploy.
        Configuracao::set(ClicksignAlertarPresos::CHAVE_REPETICAO_DIAS, 10);

        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);
        Notification::assertSentTo($admin, ContratoPresoNotification::class);

        // Recua o carimbo 5 dias — dentro do default (3d) já dispararia de
        // novo, mas com o intervalo custom de 10d ainda NÃO deveria.
        ContratoAssinatura::find($contrato->id)->update(['ultimo_alerta_em' => now()->subDays(5)]);

        Notification::fake();
        $this->artisan('clicksign:alertar-presos')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
