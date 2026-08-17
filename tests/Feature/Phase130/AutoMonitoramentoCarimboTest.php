<?php

namespace Tests\Feature\Phase130;

use App\Console\Commands\ClicksignVerificarVarredura;
use App\Models\Configuracao;
use App\Models\User;
use App\Notifications\VarreduraParadaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 130 Plano 06 (REDE-04, D-09) — a rede de segurança vigiando a si
 * mesma: `clicksign:reconciliar` grava o carimbo, `clicksign:verificar-varredura`
 * lê e acusa quando ele está velho, ausente ou marca erro. Toda asserção
 * reconsulta o banco fresco, nunca stdout.
 */
class AutoMonitoramentoCarimboTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_STATUS = 'clicksign_reconciliacao_status';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    public function test_clicksign_reconciliar_grava_o_carimbo_com_as_chaves_esperadas(): void
    {
        $this->artisan('clicksign:reconciliar')->assertExitCode(0);

        $status = json_decode(Configuracao::get(self::CHAVE_STATUS), true);

        $this->assertArrayHasKey('executado_em', $status);
        $this->assertArrayHasKey('vistos', $status);
        $this->assertArrayHasKey('corrigidos', $status);
        $this->assertArrayHasKey('pdfs_redisparados', $status);
        $this->assertNull($status['erro']);
    }

    public function test_sem_carimbo_a_verificacao_envia_alerta_para_o_admin(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertSentTo($admin, VarreduraParadaNotification::class);
    }

    public function test_carimbo_de_2h_atras_nao_gera_alerta(): void
    {
        Notification::fake();
        $this->admin();

        Configuracao::set(self::CHAVE_STATUS, json_encode([
            'executado_em'      => now()->subHours(2)->toIso8601String(),
            'vistos'            => 3,
            'corrigidos'        => 1,
            'pdfs_redisparados' => 0,
            'erro'              => null,
        ]));

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_carimbo_de_30h_atras_gera_alerta(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Configuracao::set(self::CHAVE_STATUS, json_encode([
            'executado_em'      => now()->subHours(30)->toIso8601String(),
            'vistos'            => 3,
            'corrigidos'        => 1,
            'pdfs_redisparados' => 0,
            'erro'              => null,
        ]));

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertSentTo($admin, VarreduraParadaNotification::class);
    }

    public function test_carimbo_recente_mas_com_erro_gera_alerta(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Configuracao::set(self::CHAVE_STATUS, json_encode([
            'executado_em'      => now()->subMinutes(30)->toIso8601String(),
            'vistos'            => 3,
            'corrigidos'        => 0,
            'pdfs_redisparados' => 0,
            'erro'              => 'Falha ao consultar a Clicksign',
        ]));

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertSentTo($admin, VarreduraParadaNotification::class);
    }

    public function test_carimbo_com_json_corrompido_gera_alerta_e_nao_explode(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Configuracao::set(self::CHAVE_STATUS, '{isto nao e json valido');

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertSentTo($admin, VarreduraParadaNotification::class);
    }

    public function test_duas_execucoes_seguidas_enviam_apenas_um_alerta_por_causa_do_cooldown(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);
        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertSentToTimes($admin, VarreduraParadaNotification::class, 1);
    }

    public function test_audiencia_vazia_nao_envia_e_nao_lanca_excecao(): void
    {
        Notification::fake();

        $this->artisan('clicksign:verificar-varredura')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull(Configuracao::get(ClicksignVerificarVarredura::CHAVE_ULTIMO_ALERTA));
    }
}
