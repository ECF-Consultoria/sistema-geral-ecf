<?php

namespace Tests\Feature\Quick261016Mgv;

use App\Jobs\ValidarCriativoIaJob;
use App\Models\MlAnuncioCriativo;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\CreativeJuiz;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Quick 261016-mgv — "depois de regenerar, fica em 'Validando automaticamente…' e só muda com F5".
 *
 * Produção, kit 17 / slot 1 (16/10/2026): o slot já tinha 4 imagens; na 4ª o `ValidarCriativoIaJob`
 * rodou em 4,9 ms — `CreativeJuiz::julgarEGravar()` bateu no teto `max_validacoes_asset` (3, contado
 * no SLOT inteiro) e devolveu `indisponivel` SEM GRAVAR: o criativo ficou `pendente` para sempre.
 * A tela, além disso, parava de consultar o servidor assim que o kit virava `pronto` — antes do juiz.
 */
class ValidacaoNaoTravaTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    private int $chamadasAoJuiz = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
        $this->app->instance(ImageJudgementProvider::class, $this->juizQueAprova());
    }

    private function aguardandoValidacao(MlAnuncioCriativo $criativo, int $validacoesAnteriores, int $minutosAtras = 0): MlAnuncioCriativo
    {
        $criativo->update([
            'status' => MlAnuncioCriativo::STATUS_PRONTO,
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_PENDENTE,
            'validacao_pedida_em' => now()->subMinutes($minutosAtras),
            'validacoes' => $validacoesAnteriores,
        ]);

        return $criativo->fresh();
    }

    public function test_no_teto_de_validacoes_grava_indisponivel_e_nunca_deixa_pendente(): void
    {
        // Criativo SEM kit (fluxo de 1 imagem) — o teto continua valendo fora do Publicador.
        $criativo = $this->portadorDoPublicador('GENERAL');
        $this->assertNull($criativo->kit);
        $criativo = $this->aguardandoValidacao($criativo, 3);

        (new ValidarCriativoIaJob($criativo->id))->handle(app(CreativeJuiz::class));

        $criativo->refresh();
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $criativo->validacao_status);
        $this->assertSame('Esta imagem já passou pelo número máximo de validações automáticas.', $criativo->validacaoMensagem());
        $this->assertNotNull($criativo->validacao_em);
        $this->assertSame(0, $this->chamadasAoJuiz);
    }

    public function test_no_publicador_imagem_nova_e_julgada_mesmo_depois_de_muitas_validacoes_do_slot(): void
    {
        Queue::fake();
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $slot = $this->aguardandoValidacao($kit->slots()->where('slot_indice', 1)->first(), 5);

        (new ValidarCriativoIaJob($slot->id))->handle(app(CreativeJuiz::class));

        $slot->refresh();
        $this->assertSame(1, $this->chamadasAoJuiz);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_APROVADA, $slot->validacao_status);
        $this->assertSame(6, $slot->validacoes);
    }

    public function test_status_encerra_validacao_presa_ha_mais_de_dez_minutos_e_nao_toca_na_recente(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 2);
        $presa = $this->aguardandoValidacao($kit->slots()->where('slot_indice', 1)->first(), 1, MlAnuncioCriativo::LIMITE_VALIDACAO_MINUTOS + 1);
        $recente = $this->aguardandoValidacao($kit->slots()->where('slot_indice', 2)->first(), 1, 1);

        $resp = $this->actingAs($this->admin())->getJson(route('mlb.anuncios.publicador.criativos.kit.status', [
            'produto' => $this->produto->id, 'kit' => $kit->id,
        ]));

        $resp->assertOk();
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $presa->fresh()->validacao_status);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_PENDENTE, $recente->fresh()->validacao_status);
        $resp->assertJsonPath('slots.0.validacao_status', MlAnuncioCriativo::VALIDACAO_INDISPONIVEL);
        $resp->assertJsonPath('slots.1.validacao_status', MlAnuncioCriativo::VALIDACAO_PENDENTE);
    }

    private function juizQueAprova(): ImageJudgementProvider
    {
        $teste = $this;

        return new class($teste) implements ImageJudgementProvider
        {
            public function __construct(private $teste) {}

            public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
            {
                $this->teste->contarChamada();

                return new CreativeJudgementResult(json_encode([
                    'fidelidade' => 'ok', 'veredito' => 'aprovada', 'motivo_curto' => '', 'problemas' => [],
                ]), 'juiz-dublê', 10);
            }
        };
    }

    public function contarChamada(): void
    {
        $this->chamadasAoJuiz++;
    }
}
