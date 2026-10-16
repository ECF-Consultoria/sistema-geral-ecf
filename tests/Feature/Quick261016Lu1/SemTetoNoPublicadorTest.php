<?php

namespace Tests\Feature\Quick261016Lu1;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\ValidarCriativoIaJob;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\CreativeJuiz;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Quick 261016-lu1 — dois pedidos do usuário testando o fluxo de publicação:
 *
 * 1. Sem teto de geração no Publicador. Kit 17 (produto 53, produção): as 2 imagens reprovadas pelo
 *    juiz, a regeneração AUTOMÁTICA de cada uma consumiu a cota inteira (`imagens_geradas=4`,
 *    `regeneracoes=2`, `regeneracoes_automaticas=2`) e a pessoa ficou sem poder refazer a imagem
 *    ruim sem ter clicado nada. O assistente antigo (Creative Engine) mantém os tetos.
 * 2. A imagem de medidas não tem mais a miniatura do produto no canto — era o próprio prompt
 *    (`LAYOUT_MEDIDAS`) que a pedia.
 */
class SemTetoNoPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    /** O retrato do kit 17 de produção: tudo o que a cota antiga permitia, já gasto pelo juiz. */
    private function kitComACotaAntigaEsgotada(): MlAnuncioCriativoKit
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $kit->update([
            'imagens_geradas' => MlAnuncioCriativoKit::MAX_IMAGENS,
            'regeneracoes' => MlAnuncioCriativoKit::MAX_REGENERACOES_KIT,
            'regeneracoes_automaticas' => MlAnuncioCriativoKit::MAX_REGENERACOES_KIT,
        ]);

        return $kit->fresh();
    }

    public function test_kit_do_publicador_nao_tem_teto_nem_contador_de_restantes(): void
    {
        $kit = $this->kitComACotaAntigaEsgotada();
        $slot = $kit->slots()->first();
        $slot->update(['regeneracoes' => 50]);

        $this->assertTrue($kit->semTeto());
        $this->assertFalse($kit->tetoDeImagensAtingido());
        $this->assertTrue($kit->podeRegenerarAsset($slot->fresh()));
        $this->assertNull($kit->regeneracoesRestantesAsset($slot->fresh()));
        $this->assertNull($kit->motivoDoTeto());
        $this->assertNull($kit->motivoDoTetoAsset($slot->fresh()));
    }

    public function test_o_assistente_antigo_mantem_os_tetos(): void
    {
        // Kit sem `pub_rascunho_id` — o do `AnunciarML` (Creative Engine).
        $antigo = (new MlAnuncioCriativoKit)->forceFill([
            'pub_rascunho_id' => null,
            'imagens_geradas' => MlAnuncioCriativoKit::MAX_IMAGENS,
            'regeneracoes' => 0,
        ]);
        $asset = (new MlAnuncioCriativo)->forceFill(['regeneracoes' => 0]);

        $this->assertFalse($antigo->semTeto());
        $this->assertTrue($antigo->tetoDeImagensAtingido());
        $this->assertFalse($antigo->podeRegenerarAsset($asset));
        $this->assertSame(
            'Este kit já gerou o máximo de ' . MlAnuncioCriativoKit::MAX_IMAGENS . ' imagens permitido.',
            $antigo->motivoDoTeto(),
        );
        $this->assertSame(MlAnuncioCriativoKit::MAX_REGENERACOES_ASSET, $antigo->regeneracoesRestantesAsset($asset));
    }

    public function test_status_do_kit_manda_regeneracoes_restantes_null_para_a_tela(): void
    {
        $kit = $this->kitComACotaAntigaEsgotada();

        $resp = $this->actingAs($this->admin())->getJson(route('mlb.anuncios.publicador.criativos.kit.status', [
            'produto' => $this->produto->id, 'kit' => $kit->id,
        ]));

        $resp->assertOk();
        $resp->assertJsonPath('slots.0.regeneracoes_restantes', null);
    }

    public function test_gerar_agora_nao_e_barrado_pelo_teto_antigo_de_imagens(): void
    {
        Queue::fake();
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $kit->slots()->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE, 'imagem_path' => null, 'imagem_mime' => null]);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJADO, 'imagens_geradas' => MlAnuncioCriativoKit::MAX_IMAGENS]);

        $this->actingAs($this->admin())
            ->postJson(route('mlb.anuncios.publicador.criativos.kit.gerar', ['produto' => $this->produto->id, 'kit' => $kit->id]))
            ->assertStatus(202)
            ->assertJsonPath('enfileirados', 2);

        Queue::assertPushed(GerarCriativoIaJob::class, 2);
    }

    /**
     * O que segura o custo sem o teto: a trava anti-laço do juiz é PRÓPRIA (`regeneracao_automatica`,
     * uma vez por imagem, para sempre) — não dependia do teto. Reprovada duas vezes, regenera uma só.
     */
    public function test_sem_teto_o_juiz_continua_regenerando_uma_unica_vez_por_imagem(): void
    {
        Queue::fake();
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $this->app->instance(ImageJudgementProvider::class, $this->juizQueReprova());

        foreach ([1, 2] as $rodada) {
            $slot->update([
                'status' => MlAnuncioCriativo::STATUS_PRONTO,
                'validacao_status' => MlAnuncioCriativo::VALIDACAO_PENDENTE,
                'validacao_pedida_em' => now(),
            ]);
            (new ValidarCriativoIaJob($slot->id))->handle(app(CreativeJuiz::class));
            $slot->refresh();
        }

        $this->assertTrue($kit->fresh()->semTeto());
        $this->assertSame(1, $slot->regeneracoes);
        $this->assertSame(1, $kit->fresh()->regeneracoes_automaticas);
        Queue::assertPushed(GerarCriativoIaJob::class, 1);
    }

    public function test_layout_de_medidas_nao_pede_mais_miniatura_e_manda_mostrar_o_produto_uma_vez(): void
    {
        $cena = (new CreativeSlotCatalog)->padraoDe('dimensions')['cena_padrao'];

        $this->assertStringNotContainsString('Miniatura do produto', $cena);
        $this->assertStringNotContainsString('no canto superior', $cena);
        $this->assertStringContainsString('O produto aparece UMA única vez na imagem: sem miniatura', $cena);
        // O resto do layout pedido pelo usuário (261007-amb) continua.
        $this->assertStringContainsString('TAMANHO DO PRODUTO', $cena);
        $this->assertStringContainsString('linhas de cota', $cena);
    }

    private function juizQueReprova(): ImageJudgementProvider
    {
        $texto = json_encode([
            'fidelidade' => 'falha',
            'veredito' => 'reprovada',
            'motivo_curto' => 'o produto saiu diferente das fotos de referência',
            'problemas' => [['tipo' => 'produto_alterado', 'gravidade' => 'alta', 'explicacao' => 'peça a mais']],
        ]);

        return new class($texto) implements ImageJudgementProvider
        {
            public function __construct(private string $texto) {}

            public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
            {
                return new CreativeJudgementResult($this->texto, 'juiz-dublê', 10);
            }
        };
    }
}
