<?php

namespace Tests\Feature\Phase127;

use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260824-ot1 (Tarefa 1) — a coluna `clicksign_assinatura_posicionada`
 * em `servicos`: default `false`, `fillable`, cast booleano e presente no
 * `logOnly()` do activity log — mesma bateria que `ExigeContratoTest.php`
 * (Phase128) fez para `exige_contrato`.
 *
 * ⚠️ Este é o teste MAIS IMPORTANTE do plano: um serviço criado sem
 * informar a coluna nova precisa continuar exatamente como hoje (flag
 * desligada) — é a trava que protege os 8 serviços cujo `.docx` NÃO tem as
 * tags `{{~position_sign_ID}}`.
 */
class ServicoAssinaturaPosicionadaTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeTeste(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'          => 'Serviço de teste — assinatura posicionada',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ], $overrides));
    }

    #[Test]
    public function servico_novo_sem_informar_a_flag_nasce_com_assinatura_posicionada_desligada(): void
    {
        $servico = $this->servicoDeTeste();

        $this->assertFalse($servico->fresh()->assinaturaPosicionada());
        $this->assertFalse((bool) $servico->fresh()->clicksign_assinatura_posicionada);
    }

    #[Test]
    public function flag_e_mass_assignable(): void
    {
        $servico = $this->servicoDeTeste(['clicksign_assinatura_posicionada' => true]);

        $this->assertTrue($servico->fresh()->assinaturaPosicionada());
    }

    #[Test]
    public function flag_gravada_a_mao_como_true_liga_assinatura_posicionada(): void
    {
        $servico = $this->servicoDeTeste();
        $servico->update(['clicksign_assinatura_posicionada' => true]);

        $this->assertTrue($servico->fresh()->assinaturaPosicionada());
    }

    #[Test]
    public function assinatura_posicionada_e_bool_php_nunca_inteiro_cru(): void
    {
        $servico = $this->servicoDeTeste(['clicksign_assinatura_posicionada' => true]);

        $this->assertIsBool($servico->fresh()->assinaturaPosicionada());
        $this->assertIsBool($servico->fresh()->clicksign_assinatura_posicionada);
    }

    #[Test]
    public function trocar_a_flag_fica_registrado_no_activity_log(): void
    {
        $servico = $this->servicoDeTeste();

        $servico->update(['clicksign_assinatura_posicionada' => true]);

        $ultimaAtividade = $servico->activities()->latest()->first();

        $this->assertNotNull($ultimaAtividade);
        $this->assertArrayHasKey('clicksign_assinatura_posicionada', $ultimaAtividade->properties['attributes'] ?? []);
    }
}
