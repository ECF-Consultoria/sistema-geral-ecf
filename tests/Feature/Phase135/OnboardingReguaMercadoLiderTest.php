<?php

namespace Tests\Feature\Phase135;

use App\Support\Onboarding\ReguaMercadoLider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A régua traduz `seller_reputation` em "onde a conta está e o que falta".
 *
 * O que estes testes protegem, antes de qualquer detalhe: a régua **não pode
 * inventar veredicto**. Campo que a API não entregou vale `null` — nunca
 * `false` (que diria "reprovou") nem `0`. E volume nunca ganha veredicto: as
 * fontes públicas divergem nos patamares e o número seria levado a sério pelo
 * cliente.
 */
class OnboardingReguaMercadoLiderTest extends TestCase
{
    /** Reputação completa e saudável, no formato que a API devolve. */
    private function reputacaoSaudavel(array $override = []): array
    {
        return array_replace_recursive([
            'level_id'            => '5_green',
            'power_seller_status' => 'gold',
            'metrics' => [
                'claims'                => ['period' => '60 days', 'rate' => 0.004, 'value' => 2],
                'cancellations'         => ['period' => '60 days', 'rate' => 0.001, 'value' => 1],
                'delayed_handling_time' => ['period' => '60 days', 'rate' => 0.02,  'value' => 9],
                'sales'                 => ['period' => '60 days', 'completed' => 480],
            ],
            'transactions' => ['completed' => 480, 'canceled' => 3, 'total' => 483],
        ], $override);
    }

    // ─── Progressão da medalha ──────────────────────────────────────────────

    #[Test]
    public function progressao_vai_de_sem_medalha_ate_platinum_e_para(): void
    {
        $this->assertSame('silver', ReguaMercadoLider::proxima(null));
        $this->assertSame('gold', ReguaMercadoLider::proxima('silver'));
        $this->assertSame('platinum', ReguaMercadoLider::proxima('gold'));
        $this->assertNull(ReguaMercadoLider::proxima('platinum'), 'Platinum é o topo — não há próxima');
    }

    #[Test]
    public function medalha_desconhecida_e_tratada_como_sem_medalha(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'power_seller_status' => 'diamond_inexistente',
        ]));

        $this->assertNull($diag['medalha_atual'], 'Valor fora do catálogo não vira medalha');
        $this->assertSame('silver', $diag['proxima_medalha']);
    }

    #[Test]
    public function conta_saudavel_nao_tem_bloqueio_e_aponta_a_proxima(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel());

        $this->assertSame('gold', $diag['medalha_atual']);
        $this->assertSame('MercadoLíder Gold', $diag['medalha_atual_nome']);
        $this->assertSame('platinum', $diag['proxima_medalha']);
        $this->assertSame('MercadoLíder Platinum', $diag['proxima_medalha_nome']);
        $this->assertTrue($diag['reputacao_verde']);
        $this->assertSame([], $diag['bloqueios']);
    }

    // ─── Bloqueios que a API prova ──────────────────────────────────────────

    #[Test]
    public function metrica_acima_do_teto_vira_bloqueio_com_numero_por_extenso(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'metrics' => ['claims' => ['rate' => 0.025]],
        ]));

        $this->assertCount(1, $diag['bloqueios']);
        $this->assertSame('Reclamações em 2,5% — o limite é 1%', $diag['bloqueios'][0]);

        $claims = collect($diag['metricas'])->firstWhere('chave', 'claims');
        $this->assertFalse($claims['dentro']);
        $this->assertSame(0.025, $claims['taxa']);
        $this->assertSame(0.01, $claims['teto']);
    }

    #[Test]
    public function taxa_exatamente_no_teto_passa(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'metrics' => ['claims' => ['rate' => 0.01]],
        ]));

        $this->assertSame([], $diag['bloqueios'], 'O limite é "até", não "abaixo de"');
    }

    #[Test]
    public function reputacao_fora_do_verde_escuro_vira_bloqueio(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'level_id' => '4_light_green',
        ]));

        $this->assertFalse($diag['reputacao_verde']);
        $this->assertContains('Reputação não está em verde escuro', $diag['bloqueios']);
    }

    #[Test]
    public function as_tres_metricas_de_qualidade_sao_avaliadas(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'metrics' => [
                'claims'                => ['rate' => 0.05],
                'cancellations'         => ['rate' => 0.02],
                'delayed_handling_time' => ['rate' => 0.11],
            ],
        ]));

        $this->assertCount(3, $diag['bloqueios']);
        $this->assertSame(
            ['claims', 'cancellations', 'delayed_handling_time'],
            collect($diag['metricas'])->pluck('chave')->all()
        );
    }

    // ─── O que a régua se recusa a afirmar ──────────────────────────────────

    /**
     * Métrica ausente é "não sabemos", nunca "reprovou". Um `false` aqui
     * viraria bloqueio na tela e mandaria o cliente corrigir algo que talvez
     * esteja certo.
     */
    #[Test]
    public function metrica_ausente_e_null_e_nunca_vira_bloqueio(): void
    {
        $diag = ReguaMercadoLider::diagnosticar([
            'level_id'            => '5_green',
            'power_seller_status' => 'silver',
        ]);

        foreach ($diag['metricas'] as $metrica) {
            $this->assertNull($metrica['taxa'], "{$metrica['chave']} sem dado precisa ser null");
            $this->assertNull($metrica['dentro'], "{$metrica['chave']} sem dado não pode ter veredicto");
        }

        $this->assertSame([], $diag['bloqueios'], 'Ausência de dado não é reprovação');
    }

    #[Test]
    public function reputacao_ausente_por_inteiro_nao_quebra_nem_afirma_nada(): void
    {
        $diag = ReguaMercadoLider::diagnosticar(null);

        $this->assertNull($diag['medalha_atual']);
        $this->assertNull($diag['reputacao_verde'], 'Sem level_id não se afirma que a reputação está ruim');
        $this->assertSame([], $diag['bloqueios']);
        $this->assertNull($diag['volume']['vendas']);
    }

    /**
     * Volume é REPORTADO, nunca julgado — as fontes públicas divergem nos
     * patamares (230 vendas/R$ 37.000 × "60+") e nenhuma é a página oficial.
     * "Faltam 47 vendas" seria um número inventado que o cliente levaria a
     * sério.
     */
    #[Test]
    public function volume_e_reportado_sem_veredicto(): void
    {
        $diag = ReguaMercadoLider::diagnosticar($this->reputacaoSaudavel([
            'metrics' => ['sales' => ['completed' => 12, 'period' => '365 days']],
        ]));

        $this->assertSame(12, $diag['volume']['vendas']);
        $this->assertSame('365 days', $diag['volume']['periodo']);

        $this->assertArrayNotHasKey('dentro', $diag['volume'], 'Volume não tem veredicto');
        $this->assertSame(
            [],
            $diag['bloqueios'],
            '12 vendas é pouco para qualquer medalha, mas a régua não afirma limiar que não pôde verificar'
        );
    }
}
