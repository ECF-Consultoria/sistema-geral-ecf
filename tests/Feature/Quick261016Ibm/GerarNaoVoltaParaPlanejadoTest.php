<?php

namespace Tests\Feature\Quick261016Ibm;

use App\Jobs\GerarCriativoIaJob;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Quick 261016-ibm — o "clicar duas vezes" em "Gerar agora".
 *
 * Reproduzido com playwright-cli (`relatorio-ux-gerar-imagens-ia/RELATORIO.md`): o POST de gerar
 * respondia `gerando`, a tela relia o status na mesma hora e, como nenhum job tinha rodado ainda
 * (todos os slots `pendente`), `recalcularStatus()` gravava `planejado` por cima. A tela recebia
 * `em_andamento=false`, não ligava o polling e mostrava o botão de novo.
 *
 * O intervalo "despachado, worker ainda não pegou" é exatamente o que os testes antigos não viam:
 * neles a fila roda na hora. Aqui `Queue::fake()` congela esse intervalo.
 */
class GerarNaoVoltaParaPlanejadoTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function rotaGerar(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.gerar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    /** Kit como o `PlanejarKitCriativosJob` o deixa: `planejado`, N slots `pendente`, sem imagem. */
    private function kitPlanejado(int $slots = 3): MlAnuncioCriativoKit
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', $slots);
        $kit->slots()->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE, 'imagem_path' => null, 'imagem_mime' => null]);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJADO]);

        return $kit->fresh();
    }

    public function test_status_lido_logo_depois_de_gerar_continua_gerando_antes_do_worker_pegar(): void
    {
        Queue::fake();
        $kit = $this->kitPlanejado(3);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->rotaGerar($kit->id))
            ->assertStatus(202)
            ->assertJsonPath('status', 'gerando');
        Queue::assertPushed(GerarCriativoIaJob::class, 3);

        // A releitura que a tela faz na mesma hora — nenhum job rodou, os 3 slots seguem `pendente`.
        $this->assertSame(3, $kit->slots()->where('status', MlAnuncioCriativo::STATUS_PENDENTE)->count());

        $this->actingAs($admin)->getJson($this->rotaStatus($kit->id))
            ->assertOk()
            ->assertJsonPath('status', 'gerando')
            ->assertJsonPath('em_andamento', true);

        // E não só na resposta: o banco não regrediu (era a gravação de `planejado` que ficava).
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $kit->fresh()->status);
    }

    public function test_segundo_clique_durante_a_espera_nao_despacha_de_novo(): void
    {
        Queue::fake();
        $kit = $this->kitPlanejado(3);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson($this->rotaGerar($kit->id))->assertStatus(202);
        $this->actingAs($admin)->getJson($this->rotaStatus($kit->id))->assertJsonPath('status', 'gerando');
        $this->actingAs($admin)->postJson($this->rotaGerar($kit->id))
            ->assertStatus(202)
            ->assertJsonPath('enfileirados', 0);

        Queue::assertPushed(GerarCriativoIaJob::class, 3);
    }

    public function test_kit_planejado_que_nunca_foi_mandado_gerar_continua_planejado(): void
    {
        Queue::fake();
        $kit = $this->kitPlanejado(3);

        $this->actingAs($this->admin())->getJson($this->rotaStatus($kit->id))
            ->assertOk()
            ->assertJsonPath('status', 'planejado')
            ->assertJsonPath('em_andamento', false);

        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_despachado_que_o_worker_nunca_pega_ainda_e_encerrado_por_tempo(): void
    {
        Queue::fake();
        $kit = $this->kitPlanejado(3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $kit->forceFill(['created_at' => now()->subMinutes(MlAnuncioCriativoKit::LIMITE_MINUTOS + 1)])->save();

        $this->actingAs($this->admin())->getJson($this->rotaStatus($kit->id))
            ->assertOk()
            ->assertJsonPath('status', 'erro');

        $this->assertSame(MlAnuncioCriativoKit::STATUS_ERRO, $kit->fresh()->status);
        $this->assertSame(0, $kit->slots()->where('status', MlAnuncioCriativo::STATUS_PENDENTE)->count());
    }

    public function test_recalcular_status_nos_dois_lados_da_regra(): void
    {
        $gerando = $this->kitPlanejado(2);
        $gerando->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $gerando->recalcularStatus());

        $planejado = $this->kitPlanejado(2);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $planejado->recalcularStatus());

        // O resto da régua não mudou: um slot rodando é gerando, todos prontos é pronto.
        $planejado->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_RODANDO]);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $planejado->fresh()->recalcularStatus());
        $planejado->slots()->update(['status' => MlAnuncioCriativo::STATUS_PRONTO]);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PRONTO, $planejado->fresh()->recalcularStatus());
    }
}
