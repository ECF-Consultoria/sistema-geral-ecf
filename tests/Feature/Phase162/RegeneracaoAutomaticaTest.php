<?php

namespace Tests\Feature\Phase162;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\ValidarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Contracts\ImageJudgementProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativeJuiz;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\Dto\CreativeJudgementRequest;
use App\Services\Creative\Dto\CreativeJudgementResult;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regeneração automática (Fase 162, Plano 03, VAL-05/VAL-06) — o asset
 * reprovado pelo juiz se conserta sozinho, UMA vez, dentro do MESMO
 * orçamento da regeneração manual (`MlAnuncioCriativoKit::podeRegenerarAsset()`,
 * nenhum teto paralelo). Molde de
 * `tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest.php` (geração
 * real com `Http::fake()`) + `tests/Feature/Phase162/ValidacaoAutomaticaTest.php`
 * (provider anônimo de julgamento, nunca a API real).
 */
class RegeneracaoAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        config([
            'services.creative.gemini.base_url'           => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'                 => 'chave-de-teste',
            'services.creative.gemini.text_model'          => 'modelo-texto-teste',
            'services.creative.gemini.image_model'         => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks'     => '',
            'services.creative.gemini.text_fallbacks'      => '',
            'services.creative.gemini.aspect_ratio'        => '1:1',
            'services.creative.gemini.image_size'          => '2K',
            'services.creative.gemini.mime'                => 'image/jpeg',
            'services.creative.gemini.timeout'             => 30,
            'services.creative.gemini.connect_timeout'     => 10,
            'services.creative.kit.paralelo'                => 3,
            'services.creative.kit.intervalo_s'             => 15,
            'services.creative.kit.max_imagens'              => 14,
            'services.creative.kit.max_regeneracoes_asset'  => 3,
            'services.creative.kit.max_regeneracoes_kit'    => 7,
            'services.creative.validacao.ativa'                => true,
            'services.creative.validacao.max_validacoes_asset' => 3,
            'services.creative.validacao.max_problemas'        => 5,
            'services.creative.validacao.regenerar_automatico' => true,
        ]);
    }

    /** Forma MEDIDA da Interactions API — ver docblock de GeminiImageProvider. */
    private function respostaImagemOk(string $modelo = 'modelo-imagem-teste'): array
    {
        return [
            'status' => 'completed',
            'model'  => $modelo,
            'steps'  => [
                ['type' => 'thought', 'signature' => 'abc123'],
                ['type' => 'model_output', 'content' => [[
                    'type'      => 'image',
                    'mime_type' => 'image/jpeg',
                    'data'      => base64_encode('bytes-falsos-da-imagem-gerada'),
                ]]],
            ],
        ];
    }

    /**
     * Monta um kit com portador (fotos de referência) + 7 slots `pendente`,
     * cada um com `slot_plano` preenchido — mesmo molde de
     * `RegeneracaoComVariacaoTest::kitComPortadorE7Slots()`.
     *
     * @return array{0: MlAnuncioCriativoKit, 1: MlAnuncioCriativo}
     */
    private function kitComPortadorE7Slots(): array
    {
        Storage::fake('local');

        $company  = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [['id' => 'MATERIAL', 'value_name' => 'MDF']],
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);

        $portador = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($portador, [UploadedFile::fake()->image('gabinete.jpg')]);
        $portador->update(['referencias' => $refs]);

        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $company->id,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $rascunho->user_id,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'total_slots'            => 7,
            'minimo_aprovadas'       => 3,
        ]);
        $portador->update(['kit_id' => $kit->id]);

        $tipos = ['hero', 'white_background', 'angles', 'detail', 'lifestyle', 'benefits', 'specifications'];
        foreach ($tipos as $i => $tipo) {
            MlAnuncioCriativo::create([
                'token'       => Str::random(32),
                'company_id'  => $company->id,
                'rascunho_id' => $rascunho->id,
                'user_id'     => $rascunho->user_id,
                'kit_id'      => $kit->id,
                'slot'        => $tipo,
                'slot_indice' => $i + 1,
                'slot_plano'  => [
                    'indice'       => $i + 1,
                    'tipo'         => $tipo,
                    'objetivo'     => "Objetivo do slot {$tipo}.",
                    'cena'         => "Cena do slot {$tipo}.",
                    'headline'     => null,
                    'badges'       => [],
                    'fatos_usados' => [],
                    'proibicoes'   => [],
                ],
                'status' => MlAnuncioCriativo::STATUS_PENDENTE,
            ]);
        }

        return [$kit->fresh(), $portador->fresh()];
    }

    /** Criativo `pronto` SEM kit (fluxo de 1 imagem, Fase 160) — regenerar não se aplica. */
    private function criativoProntoSemKit(): MlAnuncioCriativo
    {
        Storage::fake('local');

        $company  = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id'     => User::factory()->create()->id,
        ]);

        $token = Str::random(32);
        Storage::disk('local')->put("creative-geradas/{$token}/hero.jpg", 'bytes-da-imagem-gerada');

        $criativo = MlAnuncioCriativo::create([
            'token'               => $token,
            'company_id'          => $company->id,
            'rascunho_id'         => $rascunho->id,
            'user_id'             => $rascunho->user_id,
            'slot'                => 'hero',
            'status'              => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path'         => "creative-geradas/{$token}/hero.jpg",
            'imagem_mime'         => 'image/jpeg',
            'validacao_status'    => MlAnuncioCriativo::VALIDACAO_PENDENTE,
            'validacao_pedida_em' => now(),
        ]);

        // Sem kit, o próprio criativo é o portador da referência
        // (`portadorDeReferencia()`) — precisa de fotos originais vivas em
        // disco para o juiz não cair em `indisponivel` ANTES de chamar o
        // provedor (o que mascararia o teste: queremos provar que mesmo com
        // REPROVADA de verdade, sem kit não regenera).
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('produto.jpg')]);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    private function rodarGeracao(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
        );
    }

    private function rodarValidacao(MlAnuncioCriativo $criativo): void
    {
        (new ValidarCriativoIaJob($criativo->id))->handle(app(CreativeJuiz::class));
    }

    /**
     * Gera o slot `benefits` de um kit novo e devolve ele `pronto`, com
     * `validacao_status = pendente` — estado em que o `ValidarCriativoIaJob`
     * de fato roda o juiz.
     *
     * @return array{0: MlAnuncioCriativoKit, 1: MlAnuncioCriativo}
     */
    private function kitComSlotProntoAguardandoValidacao(): array
    {
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->where('slot', 'benefits')->first();

        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        $this->rodarGeracao($slot);

        return [$kit->fresh(), $slot->fresh()];
    }

    /** Provider anônimo de julgamento — devolve SEMPRE o mesmo veredito (nunca a API real). */
    private function providerDeVeredito(string $status, string $motivoCurto = 'o produto saiu com peça a mais em relação às fotos de referência'): ImageJudgementProvider
    {
        $textoJuiz = $status === MlAnuncioCriativo::VALIDACAO_REPROVADA
            ? json_encode([
                'fidelidade'   => 'falha',
                'veredito'     => 'reprovada',
                'motivo_curto' => $motivoCurto,
                'problemas'    => [['tipo' => 'produto_alterado', 'gravidade' => 'alta', 'explicacao' => $motivoCurto]],
            ])
            : json_encode([
                'fidelidade'   => 'ok',
                'veredito'     => 'aprovada',
                'motivo_curto' => '',
                'problemas'    => [],
            ]);

        return new class($textoJuiz) implements ImageJudgementProvider
        {
            public int $chamadas = 0;

            public function __construct(private string $texto) {}

            public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
            {
                $this->chamadas++;

                return new CreativeJudgementResult($this->texto, 'modelo-fake-juiz', 10);
            }
        };
    }

    /** Provider que devolve texto que não é JSON — vira `indisponivel` na reconciliação. */
    private function providerIndisponivel(): ImageJudgementProvider
    {
        return new class implements ImageJudgementProvider
        {
            public function julgar(CreativeJudgementRequest $pedido): CreativeJudgementResult
            {
                return new CreativeJudgementResult('isto não é json', 'modelo-fake-juiz', 10);
            }
        };
    }

    // ═══ (a) + (b) — veredito reprovado regenera sozinho, uma vez ═══════

    public function test_veredito_reprovado_regenera_sozinho_e_grava_o_motivo_aditivo_com_user_id_nulo(): void
    {
        Queue::fake();
        [$kit, $slot] = $this->kitComSlotProntoAguardandoValidacao();

        $fake = $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA);
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();

        // (a) o slot volta a pendente, regeneracoes sobe no criativo e no
        // kit, regeneracoes_automaticas sobe, a flag é marcada, e o job de
        // geração é enfileirado na fila `creative`.
        $this->assertSame(MlAnuncioCriativo::STATUS_PENDENTE, $slot->status);
        $this->assertSame(1, $slot->regeneracoes);
        $this->assertSame(1, $kit->regeneracoes);
        $this->assertSame(1, $kit->regeneracoes_automaticas);
        $this->assertTrue((bool) $slot->regeneracao_automatica);
        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
        Queue::assertPushed(GerarCriativoIaJob::class, 1);
        Queue::assertPushed(GerarCriativoIaJob::class, fn (GerarCriativoIaJob $job) => $job->criativoId === $slot->id);

        // (b) a entrada em regenerar_motivos é ADITIVA (a única até aqui),
        // tem user_id NULL, origem automatica e cita o motivo do juiz — e
        // as entradas anteriores (nenhuma, aqui) continuam intactas.
        $this->assertCount(1, $slot->regenerar_motivos);
        $entrada = $slot->regenerar_motivos[0];
        $this->assertNull($entrada['user_id']);
        $this->assertSame('automatica', $entrada['origem']);
        $this->assertStringContainsString('peça a mais em relação às fotos de referência', $entrada['texto']);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $entrada['validacao']['status']);
    }

    public function test_regenerar_motivos_e_aditivo_entradas_anteriores_continuam_depois_da_automatica(): void
    {
        Queue::fake();
        [, $slot] = $this->kitComSlotProntoAguardandoValidacao();

        // Uma entrada MANUAL já existia antes da validação automática rodar.
        $slot->update(['regenerar_motivos' => [
            ['em' => now()->toDateTimeString(), 'user_id' => 42, 'texto' => 'ficou escuro demais'],
        ]]);

        $fake = $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA);
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($slot);
        $slot->refresh();

        $this->assertCount(2, $slot->regenerar_motivos);
        $this->assertSame('ficou escuro demais', $slot->regenerar_motivos[0]['texto']);
        $this->assertSame(42, $slot->regenerar_motivos[0]['user_id']);
        $this->assertSame('automatica', $slot->regenerar_motivos[1]['origem']);
        $this->assertNull($slot->regenerar_motivos[1]['user_id']);
    }

    // ═══ (c) a 2ª geração usa o caminho JÁ EXISTENTE (paraSlot) — sem caminho paralelo ═══

    public function test_a_regeneracao_automatica_usa_o_caminho_existente_de_paraslot_sem_caminho_paralelo(): void
    {
        Queue::fake();
        [, $slot] = $this->kitComSlotProntoAguardandoValidacao();
        $promptPrimeiraGeracao = $slot->prompt;
        $this->assertStringNotContainsString('VARIAÇÃO OBRIGATÓRIA', $promptPrimeiraGeracao);

        $fake = $this->providerDeVeredito(
            MlAnuncioCriativo::VALIDACAO_REPROVADA,
            'o produto saiu com peça a mais em relação às fotos de referência',
        );
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($slot);
        $slot->refresh();
        Queue::assertPushed(GerarCriativoIaJob::class, 1);

        // Roda a 2ª geração de verdade (Queue::fake() impediu o despacho
        // automático) — com UM ÚNICO Http::fake() com contador (dois
        // Http::fake() no mesmo teste não substituem o primeiro).
        $chamadas = 0;
        Http::fake(function () use (&$chamadas) {
            $chamadas++;

            return Http::response($this->respostaImagemOk());
        });
        $this->rodarGeracao($slot);
        $slot->refresh();
        $promptSegundaGeracao = $slot->prompt;

        $this->assertSame(1, $chamadas);
        $this->assertNotSame($promptPrimeiraGeracao, $promptSegundaGeracao);
        $this->assertStringContainsString('AJUSTE PEDIDO PELO OPERADOR', $promptSegundaGeracao);
        $this->assertStringContainsString('peça a mais em relação às fotos de referência', $promptSegundaGeracao);
        $this->assertStringContainsString('VARIAÇÃO OBRIGATÓRIA', $promptSegundaGeracao);
    }

    // ═══ (d) VAL-06 — segunda reprovação NÃO regenera de novo (não-loop) ═

    public function test_segunda_reprovacao_nao_regenera_de_novo_nao_loop(): void
    {
        Queue::fake();
        [$kit, $slot] = $this->kitComSlotProntoAguardandoValidacao();

        $fake = $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA);
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();
        $this->assertSame(1, $slot->regeneracoes);
        Queue::assertPushed(GerarCriativoIaJob::class, 1);

        // Simula a 2ª geração terminando e pedindo validação de novo — o
        // juiz reprova OUTRA VEZ.
        $slot->update([
            'status'              => MlAnuncioCriativo::STATUS_PRONTO,
            'validacao_status'    => MlAnuncioCriativo::VALIDACAO_PENDENTE,
            'validacao_pedida_em' => now(),
        ]);

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();

        // Nada novo foi gasto: nem contador de job, nem regeneracoes.
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status);
        $this->assertSame(1, $slot->regeneracoes);
        $this->assertSame(1, $kit->regeneracoes);
        $this->assertSame(1, $kit->regeneracoes_automaticas);
        $this->assertCount(1, $slot->regenerar_motivos);
        Queue::assertPushed(GerarCriativoIaJob::class, 1);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $slot->validacao_status);
    }

    // ═══ (e) indisponivel NUNCA regenera ═════════════════════════════════

    public function test_validacao_indisponivel_nao_regenera(): void
    {
        Queue::fake();
        [$kit, $slot] = $this->kitComSlotProntoAguardandoValidacao();

        $this->app->instance(ImageJudgementProvider::class, $this->providerIndisponivel());

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $slot->validacao_status);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status);
        $this->assertSame(0, $slot->regeneracoes);
        $this->assertSame(0, $kit->regeneracoes);
        $this->assertSame(0, $kit->regeneracoes_automaticas);
        $this->assertNull($slot->regenerar_motivos);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    // ═══ (f) teto de imagens do kit atingido — nada é gasto ═════════════

    public function test_com_teto_de_imagens_do_kit_atingido_nao_regenera_e_nada_e_gasto(): void
    {
        Queue::fake();
        [$kit, $slot] = $this->kitComSlotProntoAguardandoValidacao();
        $kit->update(['imagens_geradas' => $kit->maxImagens()]);

        $this->app->instance(ImageJudgementProvider::class, $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA));

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $slot->validacao_status);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status);
        $this->assertSame(0, $slot->regeneracoes);
        $this->assertSame(0, $kit->regeneracoes_automaticas);
        $this->assertNull($slot->regenerar_motivos);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    // ═══ (g) chave regenerar_automatico desligada — não regenera ════════

    public function test_com_chave_regenerar_automatico_desligada_nao_regenera(): void
    {
        config(['services.creative.validacao.regenerar_automatico' => false]);
        Queue::fake();
        [$kit, $slot] = $this->kitComSlotProntoAguardandoValidacao();

        $this->app->instance(ImageJudgementProvider::class, $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA));

        $this->rodarValidacao($slot);
        $slot->refresh();
        $kit->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $slot->validacao_status);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status);
        $this->assertSame(0, $slot->regeneracoes);
        $this->assertSame(0, $kit->regeneracoes_automaticas);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    // ═══ (h) slot sem kit (fluxo Fase 160) nunca regenera ═══════════════

    public function test_criativo_sem_kit_fluxo_fase_160_nao_regenera(): void
    {
        Queue::fake();
        $criativo = $this->criativoProntoSemKit();

        $this->app->instance(ImageJudgementProvider::class, $this->providerDeVeredito(MlAnuncioCriativo::VALIDACAO_REPROVADA));

        $this->rodarValidacao($criativo);
        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_REPROVADA, $criativo->validacao_status);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertSame(0, $criativo->regeneracoes);
        $this->assertNull($criativo->regenerar_motivos);
        Queue::assertNotPushed(GerarCriativoIaJob::class);
    }

    // ═══ Gate de grep — zero linha no CreativePromptBuilder (trava de coordenação) ═

    public function test_apenas_um_dispatch_de_gerarcriativoiajob_no_job_de_validacao(): void
    {
        $conteudo = file_get_contents(app_path('Jobs/ValidarCriativoIaJob.php'));
        $semComentarios = preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $conteudo);

        $this->assertSame(1, preg_match_all('/GerarCriativoIaJob::dispatch/', $semComentarios));
    }
}
