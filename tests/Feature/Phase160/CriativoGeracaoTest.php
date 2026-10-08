<?php

namespace Tests\Feature\Phase160;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativeIdentidadeService;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GerarCriativoIaJob` + `CreativePromptBuilder` — geração assíncrona na
 * fila `high`, com trava de tempo e unicidade (Fase 160, Plano 02, Task 2).
 *
 * NENHUM teste aqui chama a API real da Gemini: `Http::preventStrayRequests()`
 * garante isso. O fake usa a forma MEDIDA da resposta (`steps[] →
 * model_output → content[]`), nunca a forma que a documentação resume —
 * mesmo cuidado de `tests/Unit/GeminiImageProviderTest.php`.
 */
class CriativoGeracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.creative.gemini.base_url'      => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'            => 'chave-de-teste',
            'services.creative.gemini.text_model'     => 'modelo-texto-teste',
            'services.creative.gemini.image_model'    => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks' => '',
            'services.creative.gemini.text_fallbacks'  => '',
            'services.creative.gemini.aspect_ratio'    => '1:1',
            'services.creative.gemini.image_size'      => '2K',
            'services.creative.gemini.mime'             => 'image/jpeg',
            'services.creative.gemini.timeout'          => 30,
            'services.creative.gemini.connect_timeout'  => 10,
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
     * @param  array<int, array{id: string, value_name: mixed}>  $attrs
     */
    private function criativoComReferencia(array $attrs, string $titulo = 'Gabinete de cozinha'): MlAnuncioCriativo
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => $titulo,
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => $attrs,
            ],
            'status'  => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);

        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $referencias = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('gabinete.jpg')]);
        $criativo->update(['referencias' => $referencias]);

        return $criativo->fresh();
    }

    private function rodar(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
            app(CreativeIdentidadeService::class),
        );
    }

    // ═══ Sucesso: imagem gerada e gravada ═══════════════════════════════

    public function test_gera_imagem_com_sucesso_e_grava_no_disco(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $criativo = $this->criativoComReferencia([
            ['id' => 'MATERIAL', 'value_name' => 'MDF'],
        ]);

        $this->rodar($criativo);

        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertNull($criativo->etapa);
        $this->assertNotNull($criativo->finished_at);
        $this->assertSame('image/jpeg', $criativo->imagem_mime);
        $this->assertSame('modelo-imagem-teste', $criativo->modelo);
        $this->assertGreaterThanOrEqual(0, $criativo->latencia_ms);
        $this->assertNotNull($criativo->imagem_path);

        Storage::disk('local')->assertExists($criativo->imagem_path);
        $this->assertSame('bytes-falsos-da-imagem-gerada', Storage::disk('local')->get($criativo->imagem_path));
    }

    // ═══ O prompt gravado reflete o Product Truth ═══════════════════════

    public function test_prompt_gravado_contem_claims_e_fatos_mas_nenhuma_contagem_quando_vazia(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $criativo = $this->criativoComReferencia([
            ['id' => 'COLOR', 'value_name' => 'Branco'],
        ], titulo: 'Gabinete de cozinha 2 portas 3 gavetas');

        $this->rodar($criativo);
        $criativo->refresh();

        $this->assertNotEmpty($criativo->prompt);
        $this->assertStringContainsString('Branco', $criativo->prompt);
        $this->assertStringContainsString('CLAIMS PROIBIDAS', $criativo->prompt);
        $this->assertStringContainsString('não declare número algum', $criativo->prompt);

        // TRUTH-02: nenhum dígito do título ("2 portas 3 gavetas") vazou pro prompt.
        $this->assertDoesNotMatchRegularExpression('/\b[0-9]+\s*(porta|gaveta)/ui', $criativo->prompt);
    }

    // ═══ GEN-02 / GEN-06 — fila creative e unicidade ═════════════════════
    // (migração de fila decidida em 2026-10-02, Fase 161 Plano 02 — a fila
    // ERA `high`; ver docblock de GerarCriativoIaJob)

    public function test_job_e_despachado_na_fila_creative(): void
    {
        Queue::fake();

        $criativo = $this->criativoComReferencia([]);

        GerarCriativoIaJob::dispatch($criativo->id);

        Queue::assertPushedOn('creative', GerarCriativoIaJob::class);
    }

    public function test_dois_dispatches_do_mesmo_rascunho_geram_um_unico_job(): void
    {
        Queue::fake();

        $criativo = $this->criativoComReferencia([]);

        GerarCriativoIaJob::dispatch($criativo->id);
        GerarCriativoIaJob::dispatch($criativo->id);

        Queue::assertPushed(GerarCriativoIaJob::class, 1);
    }

    // ═══ Falha definitiva — 503 em todos os modelos ═════════════════════

    public function test_falha_definitiva_marca_erro_e_nao_apaga_a_referencia(): void
    {
        Storage::fake('local');

        $criativo = $this->criativoComReferencia([]);

        (new GerarCriativoIaJob($criativo->id))->failed(new \RuntimeException(
            'A Gemini está sobrecarregada neste momento. Tente novamente em alguns minutos.'
        ));

        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $criativo->status);
        $this->assertNull($criativo->etapa);
        $this->assertNotNull($criativo->finished_at);
        $this->assertStringContainsString('sobrecarregada', $criativo->erro_mensagem);

        // A referência fica — o operador vai tentar de novo.
        $this->assertNotEmpty($criativo->referenciasVivas());
        $this->assertNull($criativo->referencias_apagadas_em);
    }

    public function test_503_em_todos_os_modelos_lanca_e_failed_grava_o_erro(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response(['error' => 'overloaded'], 503)]);

        $criativo = $this->criativoComReferencia([]);

        $job = new GerarCriativoIaJob($criativo->id);

        try {
            $job->handle(
                app(ImageGenerationProvider::class),
                app(CreativeContextBuilder::class),
                app(ProductTruthBuilder::class),
                app(CreativePromptBuilder::class),
                app(CreativeIdentidadeService::class),
            );
            $this->fail('Deveria ter lançado RuntimeException.');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $criativo->status);
        $this->assertNotEmpty($criativo->erro_mensagem);
        $this->assertNotEmpty($criativo->referenciasVivas());
    }

    // ═══ Trava de tempo — nunca chama o provedor por um criativo morto ══

    public function test_criativo_travado_encerra_sem_chamar_o_provedor(): void
    {
        Http::preventStrayRequests(); // qualquer chamada HTTP aqui estoura

        $criativo = $this->criativoComReferencia([]);
        $criativo->forceFill(['status' => MlAnuncioCriativo::STATUS_RODANDO, 'created_at' => now()->subMinutes(20)])->save();

        $this->rodar($criativo);

        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_ERRO, $criativo->status);
        $this->assertStringContainsString('minutos', $criativo->erro_mensagem);
        Http::assertNothingSent();
    }

    // ═══ GEN-05 — nada sensível em log ═══════════════════════════════════

    public function test_log_nunca_contem_chave_prompt_inteiro_ou_base64(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        Log::spy();

        $criativo = $this->criativoComReferencia([
            ['id' => 'COLOR', 'value_name' => 'Branco'],
        ]);

        $this->rodar($criativo);

        $criativo->refresh();
        $base64Resposta = base64_encode('bytes-falsos-da-imagem-gerada');

        Log::shouldHaveReceived('info')->withArgs(function (string $mensagem, array $contexto = []) use ($base64Resposta, $criativo) {
            $tudo = $mensagem.json_encode($contexto);

            $this->assertStringNotContainsString('chave-de-teste', $tudo);
            $this->assertStringNotContainsString($base64Resposta, $tudo);
            $this->assertStringNotContainsString((string) $criativo->prompt, $tudo);

            return true;
        })->atLeast()->once();
    }
}
