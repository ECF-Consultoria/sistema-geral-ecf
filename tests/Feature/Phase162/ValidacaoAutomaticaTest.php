<?php

namespace Tests\Feature\Phase162;

use App\Jobs\GerarCriativoIaJob;
use App\Jobs\ValidarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
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
 * `ValidarCriativoIaJob` + dispatch no fim de `GerarCriativoIaJob` (Fase 162,
 * Plano 02, Task 1, VAL-01) — toda imagem gerada entra em validação
 * automática sem ninguém clicar em nada, num job SEPARADO com chave de
 * unicidade PRÓPRIA (Decisão 1 do 162-02-PLAN.md).
 *
 * NENHUM teste aqui chama a API real: `Http::preventStrayRequests()` cobre a
 * geração, e o juiz é sempre um `ImageJudgementProvider` anônimo.
 */
class ValidacaoAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        config([
            'services.creative.gemini.base_url'        => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'              => 'chave-de-teste',
            'services.creative.gemini.text_model'       => 'modelo-texto-teste',
            'services.creative.gemini.image_model'      => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks'  => '',
            'services.creative.gemini.text_fallbacks'   => '',
            'services.creative.gemini.aspect_ratio'     => '1:1',
            'services.creative.gemini.image_size'       => '2K',
            'services.creative.gemini.mime'             => 'image/jpeg',
            'services.creative.gemini.timeout'          => 30,
            'services.creative.gemini.connect_timeout'  => 10,
            'services.creative.validacao.ativa'                => true,
            'services.creative.validacao.max_validacoes_asset' => 3,
            'services.creative.validacao.max_problemas'        => 5,
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

    private function criativoComReferencia(): MlAnuncioCriativo
    {
        $company = Company::factory()->create(['name' => 'Empresa Teste']);

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

        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $referencias = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('gabinete.jpg')]);
        $criativo->update(['referencias' => $referencias, 'truth' => ['marca' => null, 'modelo' => null, 'fatos_verificados' => [], 'contagens' => []]]);

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

    /** Provider anônimo com texto fixo e contador de chamadas (nunca a API real). */
    private function providerComTexto(string $texto): ImageJudgementProvider
    {
        return new class($texto) implements ImageJudgementProvider
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

    private function rodarValidacao(MlAnuncioCriativo $criativo): void
    {
        (new ValidarCriativoIaJob($criativo->id))->handle(app(CreativeJuiz::class));
    }

    // ═══ (a) Geração pede validação + enfileira na fila `creative` ═════

    public function test_geracao_marca_validacao_pendente_e_enfileira_o_job_na_fila_creative(): void
    {
        Queue::fake();
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        $criativo = $this->criativoComReferencia();

        $this->rodarGeracao($criativo);
        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_PENDENTE, $criativo->validacao_status);
        $this->assertNotNull($criativo->validacao_pedida_em);

        Queue::assertPushedOn('creative', ValidarCriativoIaJob::class);
    }

    // ═══ (b) Chave de validação desligada — nada enfileirado ════════════

    public function test_com_chave_de_validacao_desligada_nada_e_enfileirado_e_fica_indisponivel(): void
    {
        Queue::fake();
        config(['services.creative.validacao.ativa' => false]);
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);
        $criativo = $this->criativoComReferencia();

        $this->rodarGeracao($criativo);
        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $criativo->status);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $criativo->validacao_status);
        $this->assertNull($criativo->validacao_pedida_em);

        Queue::assertNotPushed(ValidarCriativoIaJob::class);
    }

    // ═══ (c) Job de validação grava o veredito na coluna ════════════════

    public function test_job_de_validacao_grava_o_veredito_na_coluna(): void
    {
        Storage::fake('local');
        $criativo = $this->criativoComReferencia();
        $caminho = "creative-geradas/{$criativo->token}/hero.jpg";
        Storage::disk('local')->put($caminho, 'bytes-da-imagem-gerada');
        $criativo->update([
            'status'              => MlAnuncioCriativo::STATUS_PRONTO,
            'imagem_path'         => $caminho,
            'imagem_mime'         => 'image/jpeg',
            'validacao_status'    => MlAnuncioCriativo::VALIDACAO_PENDENTE,
            'validacao_pedida_em' => now(),
        ]);

        $fake = $this->providerComTexto(json_encode([
            'fidelidade'   => 'ok',
            'veredito'     => 'aprovada',
            'motivo_curto' => '',
            'problemas'    => [],
        ]));
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($criativo);
        $criativo->refresh();

        $this->assertSame(1, $fake->chamadas);
        $this->assertSame(MlAnuncioCriativo::VALIDACAO_APROVADA, $criativo->validacao_status);
        $this->assertNotNull($criativo->validacao_em);
        $this->assertSame('ok', $criativo->validacao['fidelidade']);
        $this->assertNotEmpty($criativo->validacao['mensagem']);
    }

    // ═══ (d) failed() nunca deixa em pendente ═══════════════════════════

    public function test_failed_do_job_de_validacao_deixa_indisponivel_nunca_pendente(): void
    {
        $criativo = $this->criativoComReferencia();
        $criativo->update([
            'status'              => MlAnuncioCriativo::STATUS_PRONTO,
            'validacao_status'    => MlAnuncioCriativo::VALIDACAO_PENDENTE,
            'validacao_pedida_em' => now(),
        ]);

        (new ValidarCriativoIaJob($criativo->id))->failed(new \RuntimeException('provedor fora do ar'));
        $criativo->refresh();

        $this->assertSame(MlAnuncioCriativo::VALIDACAO_INDISPONIVEL, $criativo->validacao_status);
        $this->assertNotSame(MlAnuncioCriativo::VALIDACAO_PENDENTE, $criativo->validacao_status);
        $this->assertNotEmpty($criativo->validacao['mensagem']);
    }

    // ═══ (e) Criativo já aprovado não consome chamada ═══════════════════

    public function test_criativo_ja_aprovado_nao_consome_chamada_do_juiz(): void
    {
        $criativo = $this->criativoComReferencia();
        $criativo->update([
            'status'           => MlAnuncioCriativo::STATUS_APROVADO,
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_PENDENTE,
        ]);

        $fake = $this->providerComTexto(json_encode(['fidelidade' => 'ok', 'veredito' => 'aprovada', 'problemas' => []]));
        $this->app->instance(ImageJudgementProvider::class, $fake);

        $this->rodarValidacao($criativo);

        $this->assertSame(0, $fake->chamadas);
    }

    // ═══ Gates de grep (frontmatter must_haves) ═════════════════════════

    public function test_job_declara_fila_creative_no_construtor_e_nunca_redeclara_queue(): void
    {
        $conteudo = file_get_contents(app_path('Jobs/ValidarCriativoIaJob.php'));
        $semComentarios = preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $conteudo);

        $this->assertSame(0, preg_match_all('/public \$queue|protected \$queue/', $semComentarios));
        $this->assertSame(1, preg_match_all("/onQueue\('creative'\)/", $semComentarios));
    }
}
