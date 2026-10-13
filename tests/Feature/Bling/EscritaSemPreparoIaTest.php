<?php

namespace Tests\Feature\Bling;

use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 184-03 (BLING-21, BLING-22): o terceiro nascimento do ator
 * (`AtorDoPortal::sistema()`) e os DOIS caminhos até `PreparoIaAgenda::aoSalvar`
 * fechados por `agendarPreparo: false` — provados aqui porque uma varredura do
 * Bling não é nem cliente nem equipe, e não pode enfileirar centenas de cadeias
 * de IA na fila que publica anúncio e manda o código de acesso do Portal
 * (`184-RESEARCH.md` §3.4/3.5; `publicador-ml.md` §22).
 */
class EscritaSemPreparoIaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    // ═══ Task 1 — o terceiro nascimento do ator ═════════════════════════════

    public function test_ator_sistema_nasce_sem_causer_e_com_origem_bling(): void
    {
        $ator = AtorDoPortal::sistema();

        $this->assertSame('bling', $ator->origem);
        $this->assertFalse($ator->equipe);
        $this->assertNull($ator->modelo);
        $this->assertNotSame('', trim($ator->nome));
    }

    public function test_ator_cliente_continua_igual(): void
    {
        $empresa = $this->empresaDoGabarito();
        $usuario = $this->clienteDoPortal($empresa);

        $ator = AtorDoPortal::cliente($usuario);

        $this->assertSame('cliente', $ator->origem);
        $this->assertFalse($ator->equipe);
        $this->assertNotNull($ator->modelo);
    }

    public function test_ator_da_equipe_continua_igual(): void
    {
        $membro = User::factory()->create();

        $ator = AtorDoPortal::daEquipe($membro);

        $this->assertSame('interno', $ator->origem);
        $this->assertTrue($ator->equipe);
        $this->assertNotNull($ator->modelo);
    }

    public function test_registro_com_ator_sistema_grava_sem_causer(): void
    {
        $empresa = $this->empresaDoGabarito();

        RegistroEstrutura::registrar(AtorDoPortal::sistema(), $empresa, null, 'produtos_gravados', 'teste do ator de sistema');

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('bling', $log->getExtraProperty('origem'));
        // Asserção negativa: carimbar um funcionário aqui seria uma ação que nenhum humano fez.
        $this->assertNull($log->causer_id);
    }

    public function test_registro_com_ator_de_equipe_continua_com_causer(): void
    {
        $empresa = $this->empresaDoGabarito();
        $membro = User::factory()->create();

        RegistroEstrutura::registrar(AtorDoPortal::daEquipe($membro), $empresa, null, 'produtos_gravados', 'teste do ator de equipe');

        $log = Activity::where('log_name', 'portal')->where('properties->evento', 'produtos_gravados')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('interno', $log->getExtraProperty('origem'));
        $this->assertSame($membro->id, $log->causer_id);
    }
}
