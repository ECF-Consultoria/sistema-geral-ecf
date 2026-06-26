<?php

// Fase 38 (Wave 0) — Suíte unitária RED do PublicadorScoreService.
// Descreve o contrato observável do Service (implementado na Wave 1, plano 38-02).
// Os testes FALHAM agora porque App\Services\PublicadorScoreService ainda não existe.
//
// Contrato (RESEARCH §"PublicadorScoreService — Assinatura e Retorno"):
//   compute(int $userId, string $mesRef, int $feito, int $vendas, int $meta): array
//   → ['score', 'classificacao', 'pontos_categoria', 'metricas']
//   feito/vendas/meta vêm do controller (calcularKpis/metaParaMes) — o Service não os recalcula.
// Cobre: PUB-01, PUB-02, PUB-03.

namespace Tests\Unit;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\PublicadorScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicadorScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /**
     * Cria uma publicação para o publicador. Usa forceFill porque net_billing
     * não está no $fillable de Publicacao (é populado pelo sync de vendas).
     */
    private function pub(int $userId, array $attrs = []): Publicacao
    {
        $p = new Publicacao();
        $p->forceFill(array_merge([
            'data'                 => now()->format('Y-m-d'),
            'user_id'              => $userId,
            'empresa'              => 'Empresa Teste',
            'mlb_code'             => 'MLB' . str_pad((string) (++self::$seq), 6, '0', STR_PAD_LEFT),
            'tipo'                 => 'anuncio',
            'vendido'              => false,
            'vendas_qty'           => 0,
            'net_billing'          => null,
            'problema'             => false,
            'comentario'           => null,
            'comentario_resolvido' => false,
        ], $attrs));
        $p->save();

        return $p;
    }

    // ─── PUB-01: score completo entre 0 e 100 + 5 eixos ─────────────────────────
    public function test_score_completo_retorna_float_entre_0_e_100(): void
    {
        $user = User::factory()->create();

        // Mix de publicações do mês: vendidas/não, com/sem problema, com net_billing.
        $this->pub($user->id, ['vendido' => true, 'vendas_qty' => 3, 'net_billing' => 200.0]);
        $this->pub($user->id, ['vendido' => false, 'problema' => true, 'comentario' => 'rever foto', 'comentario_resolvido' => true]);
        $this->pub($user->id, ['vendido' => true, 'vendas_qty' => 1, 'net_billing' => 90.0]);

        // Empresa sob responsabilidade do publicador, com SKUs no prazo e atrasado.
        MlbEmpresa::create([
            'nome'           => 'Empresa Resp',
            'fase'           => 'M2',
            'projeto'        => 'POLOS',
            'responsavel_id' => $user->id,
            'estagio'        => 'Não Listado',
            'problema'       => false,
            'skus_estagio1'  => [
                ['sku' => 'SKU1', 'ok' => true, 'concluido_em' => null, 'atrasado' => false],
                ['sku' => 'SKU2', 'ok' => true, 'concluido_em' => null, 'atrasado' => true],
            ],
        ]);

        $result = (new PublicadorScoreService())->compute($user->id, now()->format('Y-m'), 100, 30, 220);

        $this->assertIsFloat($result['score']);
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(100.0, $result['score']);
        $this->assertEqualsCanonicalizing(
            ['meta', 'produtividade', 'pontualidade', 'conversao', 'qualidade'],
            array_keys($result['pontos_categoria'])
        );
        $this->assertContains($result['classificacao'], ['excelente', 'bom', 'atencao', 'critico']);
    }

    // ─── PUB-02: eixo sem dado vira null e peso é redistribuído (sem NaN) ────────
    public function test_eixo_null_redistribui_peso(): void
    {
        $user = User::factory()->create();

        // Sem publicações no mês + feito=0 → conversão (0/0) e qualidade (sem pub) ficam null.
        $result = (new PublicadorScoreService())->compute($user->id, now()->format('Y-m'), 0, 0, 220);

        $this->assertNull($result['pontos_categoria']['conversao']['valor']);
        $this->assertIsFloat($result['score']);
        $this->assertFalse(is_nan($result['score']));
    }

    // ─── PUB-03: Pontualidade null quando publicador não é responsável de empresa ─
    public function test_pontualidade_sem_empresas(): void
    {
        $user = User::factory()->create();

        // Nenhuma MlbEmpresa com responsavel_id = user.
        $result = (new PublicadorScoreService())->compute($user->id, now()->format('Y-m'), 50, 10, 220);

        $this->assertNull($result['pontos_categoria']['pontualidade']['valor']);
        $this->assertSame(0, $result['metricas']['pontualidade']['total_skus']);
    }
}
