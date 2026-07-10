<?php

// Plano de Metas do Time de Publicação — suíte do PlanoMetasPublicacaoService.
// Cobre as réguas 1-5, a nota final ponderada com redistribuição de peso, as
// faixas de bônus e as travas de elegibilidade (Seção 6 do plano).

namespace Tests\Unit;

use App\Models\Publicacao;
use App\Models\PublicacaoAbsenteismo;
use App\Models\User;
use App\Services\PlanoMetasPublicacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanoMetasPublicacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function pub(int $userId, array $attrs = []): Publicacao
    {
        $p = new Publicacao();
        $p->forceFill(array_merge([
            'data'     => now()->format('Y-m-d'),
            'prazo'    => null,
            'user_id'  => $userId,
            'empresa'  => 'Empresa Teste',
            'mlb_code' => 'MLB' . str_pad((string) (++self::$seq), 6, '0', STR_PAD_LEFT),
            'tipo'     => 'anuncio',
            'vendido'  => false,
            'vendas_qty' => 0,
        ], $attrs));
        $p->save();

        return $p;
    }

    private function svc(): PlanoMetasPublicacaoService
    {
        return new PlanoMetasPublicacaoService();
    }

    // ─── Régua de publicações: 320→3, 370→5, <280→1 ────────────────────────────
    public function test_regua_publicacoes(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');

        $this->assertSame(1, $this->svc()->compute($u->id, $mes, 200, 0, 20)['indicadores']['publicacoes']['nota']);
        $this->assertSame(2, $this->svc()->compute($u->id, $mes, 300, 0, 20)['indicadores']['publicacoes']['nota']);
        $this->assertSame(3, $this->svc()->compute($u->id, $mes, 320, 0, 20)['indicadores']['publicacoes']['nota']);
        $this->assertSame(4, $this->svc()->compute($u->id, $mes, 350, 0, 20)['indicadores']['publicacoes']['nota']);
        $this->assertSame(5, $this->svc()->compute($u->id, $mes, 380, 0, 20)['indicadores']['publicacoes']['nota']);
    }

    // ─── Régua de conversão: <30→1, 30-39,99→3, ≥40→5 ─────────────────────────
    public function test_regua_conversao(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');

        // 100 feitos, 20 vendas = 20% → nota 1
        $this->assertSame(1, $this->svc()->compute($u->id, $mes, 100, 20, 20)['indicadores']['conversao']['nota']);
        // 100 feitos, 35 vendas = 35% → nota 3
        $this->assertSame(3, $this->svc()->compute($u->id, $mes, 100, 35, 20)['indicadores']['conversao']['nota']);
        // 100 feitos, 45 vendas = 45% → nota 5
        $this->assertSame(5, $this->svc()->compute($u->id, $mes, 100, 45, 20)['indicadores']['conversao']['nota']);
        // Borda: 39,99% real (3999/10000) NÃO pode virar nota 5 por arredondamento → nota 3
        $this->assertSame(3, $this->svc()->compute($u->id, $mes, 10000, 3999, 20)['indicadores']['conversao']['nota']);
        // 40,00% exato → nota 5
        $this->assertSame(5, $this->svc()->compute($u->id, $mes, 10000, 4000, 20)['indicadores']['conversao']['nota']);
        // sem publicações → conversão sem dado (null)
        $this->assertNull($this->svc()->compute($u->id, $mes, 0, 0, 20)['indicadores']['conversao']['nota']);
    }

    // ─── Redistribuição: sem prazos e sem registro → nota final só dos 3 c/ dado ─
    public function test_redistribuicao_pontualidade_e_absenteismo_sem_dado(): void
    {
        $u = User::factory()->create();
        // feito 325 (nota pub 4), conv 43% (nota 5), média 16,25/dia (nota 3),
        // pontualidade e absenteísmo sem dado → nota = (30*4+30*5+15*3)/75 = 4.20
        $r = $this->svc()->compute($u->id, now()->format('Y-m'), 325, 140, 20);

        $this->assertNull($r['indicadores']['pontualidade']['nota']);
        $this->assertNull($r['indicadores']['absenteismo']['nota']);
        $this->assertSame(4.20, $r['nota']);
        $this->assertSame('intermediario', $r['faixa']);
    }

    // ─── Trava de volume: <320 publicações → sem bônus, independente da nota ────
    public function test_trava_volume_minimo(): void
    {
        $u = User::factory()->create();
        // 310 pub (nota 2), conv 45% (nota 5), média 20,67/dia (nota 5) → nota 3,80
        // (faixa base pela nota), mas <320 publicações trava tudo em "sem bônus".
        $r = $this->svc()->compute($u->id, now()->format('Y-m'), 310, 140, 15);

        $this->assertSame('base', $r['faixa_por_nota']);
        $this->assertSame('sem_bonus', $r['faixa']);
        $this->assertFalse($r['elegivel_bonus']);
        $this->assertNotEmpty($r['travas']);
    }

    // ─── Trava do bônus máximo: nota ≥4,50 mas <370 pub → rebaixa p/ intermediário ─
    public function test_trava_bonus_maximo_exige_370_e_40pct(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');

        // Pontualidade 100% (2 anúncios no prazo) e absenteísmo nota 5.
        $this->pub($u->id, ['prazo' => now()->addDay()->format('Y-m-d')]);
        $this->pub($u->id, ['prazo' => now()->addDay()->format('Y-m-d')]);
        PublicacaoAbsenteismo::create(['user_id' => $u->id, 'mes' => $mes, 'faltas_nao_justificadas' => 0, 'atrasos_pontuais' => false]);

        // feito 350 (<370), conv 45%, média 17,5 (nota 4) → nota_por_nota daria máximo.
        $r = $this->svc()->compute($u->id, $mes, 350, 158, 20);

        $this->assertSame('maximo', $r['faixa_por_nota']);
        $this->assertSame('intermediario', $r['faixa']);
        $this->assertNotEmpty($r['travas']);
    }

    // ─── Bônus máximo completo: ≥370, ≥40% conv, pontualidade e absenteísmo 5 ──
    public function test_bonus_maximo_completo(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');

        $this->pub($u->id, ['prazo' => now()->addDay()->format('Y-m-d')]);
        $this->pub($u->id, ['prazo' => now()->addDay()->format('Y-m-d')]);
        PublicacaoAbsenteismo::create(['user_id' => $u->id, 'mes' => $mes, 'faltas_nao_justificadas' => 0, 'atrasos_pontuais' => false]);

        // feito 380 (nota 5), conv 45% (nota 5), média 20 (nota 5) → nota 5,00.
        $r = $this->svc()->compute($u->id, $mes, 380, 171, 19);

        $this->assertSame(5.0, $r['nota']);
        $this->assertSame('maximo', $r['faixa']);
        $this->assertEmpty($r['travas']);
    }

    // ─── Pontualidade apurada dos prazos: 2 de 3 no prazo → 66,7% (nota 1) ─────
    public function test_pontualidade_a_partir_dos_prazos(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');
        $ini = now()->startOfMonth(); // datas mid-mês evitam a borda do whereBetween

        // 2 no prazo (data <= prazo), 1 atrasado (data > prazo), 1 SEM prazo (ignorado).
        $this->pub($u->id, ['data' => $ini->copy()->addDays(2)->format('Y-m-d'), 'prazo' => $ini->copy()->addDays(9)->format('Y-m-d')]);
        $this->pub($u->id, ['data' => $ini->copy()->addDays(3)->format('Y-m-d'), 'prazo' => $ini->copy()->addDays(9)->format('Y-m-d')]);
        $this->pub($u->id, ['data' => $ini->copy()->addDays(9)->format('Y-m-d'), 'prazo' => $ini->copy()->addDays(2)->format('Y-m-d')]);
        $this->pub($u->id, ['prazo' => null]);

        $ind = $this->svc()->compute($u->id, $mes, 4, 0, 20)['indicadores']['pontualidade'];

        $this->assertSame(3, $ind['total']);
        $this->assertSame(2, $ind['no_prazo']);
        $this->assertSame(66.7, $ind['valor']);
        $this->assertSame(1, $ind['nota']); // <70% → 1
    }

    // ─── Régua de absenteísmo: 2 faltas → nota 2; só atrasos → nota 4 ─────────
    public function test_regua_absenteismo(): void
    {
        $u = User::factory()->create();
        $mes = now()->format('Y-m');

        PublicacaoAbsenteismo::create(['user_id' => $u->id, 'mes' => $mes, 'faltas_nao_justificadas' => 2, 'atrasos_pontuais' => false]);
        $this->assertSame(2, $this->svc()->compute($u->id, $mes, 100, 0, 20)['indicadores']['absenteismo']['nota']);

        PublicacaoAbsenteismo::where('user_id', $u->id)->update(['faltas_nao_justificadas' => 0, 'atrasos_pontuais' => true]);
        $this->assertSame(4, $this->svc()->compute($u->id, $mes, 100, 0, 20)['indicadores']['absenteismo']['nota']);
    }
}
