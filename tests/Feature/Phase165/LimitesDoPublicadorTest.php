<?php

namespace Tests\Feature\Phase165;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 05, Task 2 (CE165-11) — as rotas novas do Publicador usam
 * os MESMOS limitadores nomeados do Creative Engine (`creative-kit-planejar`/
 * `creative-kit-gerar`/`creative-regenerar`, calibrados no Quick 261003-l8o)
 * — nunca um throttle novo, nunca sem throttle — e o 429 sai em pt-BR, com
 * `Retry-After`. O balde é o MESMO do fluxo antigo: o custo é por USUÁRIO,
 * não por rota.
 */
class LimitesDoPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function rotaPlanejar(): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.planejar', ['produto' => $this->produto->id]);
    }

    // ═══ As 3 rotas que gastam cota usam os limitadores nomeados ═══════

    public function test_rotas_novas_usam_os_limitadores_nomeados_do_creative_engine(): void
    {
        $mapa = [
            'mlb.anuncios.publicador.criativos.kit.planejar' => 'throttle:creative-kit-planejar',
            'mlb.anuncios.publicador.criativos.kit.gerar' => 'throttle:creative-kit-gerar',
            'mlb.anuncios.publicador.criativos.slot.regenerar' => 'throttle:creative-regenerar',
        ];

        foreach ($mapa as $nomeRota => $throttleEsperado) {
            $rota = app('router')->getRoutes()->getByName($nomeRota);
            $this->assertNotNull($rota, "rota '{$nomeRota}' não existe");

            $this->assertContains(
                $throttleEsperado,
                $rota->gatherMiddleware(),
                "'{$nomeRota}' deveria usar '{$throttleEsperado}'",
            );
        }
    }

    // ═══ 13ª chamada de planejar no mesmo minuto → 429 em pt-BR ═════════

    public function test_decima_terceira_chamada_de_planejar_no_mesmo_minuto_devolve_429_em_pt_br(): void
    {
        $admin = $this->admin();

        $ultima = null;
        for ($i = 1; $i <= 13; $i++) {
            $ultima = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL']);
        }

        $ultima->assertStatus(429);

        $retryAfter = (int) $ultima->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);

        $mensagem = $ultima->json('erros.0.mensagem');
        $this->assertStringContainsString('Tente de novo em', $mensagem);
        $this->assertStringContainsString('planejar o kit', $mensagem);
        $this->assertStringNotContainsString('Too many', $mensagem);
    }

    // ═══ O balde é o MESMO do fluxo antigo (chave `creative-planejar:{user}`) ═══

    public function test_balde_do_planejar_e_compartilhado_com_a_rota_antiga(): void
    {
        $admin = $this->admin();

        // Nenhum criativo precisa existir de verdade: o throttle conta a
        // requisição ANTES do controller rodar (mesmo raciocínio das demais
        // suítes de limite do Creative Engine) — um token qualquer basta
        // para a rota antiga resolver e a chave de throttle ser a mesma.
        $rotaAntiga = route('mlb.anuncios.criativo.kit.planejar', ['token' => Str::random(32)]);

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($admin)->postJson($rotaAntiga);
        }

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL']);

        $resp->assertStatus(429);
        $this->assertStringContainsString('Tente de novo em', $resp->json('erros.0.mensagem'));
    }

    // ═══ Limite é por usuário, não por IP — mesma disciplina do Quick 261003-l8o ═══

    public function test_limite_de_planejar_e_por_usuario_nao_por_ip(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $usuarioA = $this->admin();
        $usuarioB = $this->admin();

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($usuarioA)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
        }

        $estourouA = $this->actingAs($usuarioA)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
        $estourouA->assertStatus(429);

        $respostaB = $this->actingAs($usuarioB)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
        $respostaB->assertStatus(202);
    }
}
