<?php

namespace Tests\Feature\Phase170;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\CreativeIdentidade;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integração ponta a ponta: `GerarCriativoIaJob` resolve a identidade da
 * conta do criativo e repassa para `CreativePromptBuilder` (Fase 170, Plano
 * 01, Task 2, IDENT-02/03). Molde de `tests/Feature/Phase160/CriativoGeracaoTest.php`
 * — nenhum teste aqui chama a API real da Gemini (`Http::preventStrayRequests()`).
 */
class GerarCriativoIaJobIdentidadeTest extends TestCase
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
            // Desligado neste teste: o foco é o prompt, não o veredito do juiz
            // (que exigiria outro fake de Http para o endpoint de validação).
            'services.creative.validacao.ativa'          => false,
        ]);
    }

    /** Forma MEDIDA da Interactions API — ver docblock de GeminiImageProvider. */
    private function respostaImagemOk(): array
    {
        return [
            'status' => 'completed',
            'model'  => 'modelo-imagem-teste',
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

    private function criativoComReferencia(Company $company): MlAnuncioCriativo
    {
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => [
                'title'       => 'Gabinete de cozinha',
                'category_id' => 'MLB1574',
                'description' => 'Descrição qualquer.',
                'attributes'  => [],
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

    public function test_conta_com_identidade_cadastrada_grava_prompt_com_bloco_identidade(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $company = Company::factory()->create(['name' => 'Empresa Com Identidade']);
        CreativeIdentidade::create([
            'company_id' => $company->id,
            'texto'      => 'Cor principal #0A2342, fonte Montserrat, acabamento fosco.',
        ]);

        $criativo = $this->criativoComReferencia($company);

        $this->rodar($criativo);
        $criativo->refresh();

        $this->assertNotEmpty($criativo->prompt);
        $this->assertStringContainsString('IDENTIDADE', $criativo->prompt);
        $this->assertStringContainsString('Cor principal #0A2342, fonte Montserrat, acabamento fosco.', $criativo->prompt);
    }

    public function test_conta_sem_identidade_cadastrada_grava_prompt_sem_bloco_identidade(): void
    {
        Storage::fake('local');
        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $company  = Company::factory()->create(['name' => 'Empresa Sem Identidade']);
        $criativo = $this->criativoComReferencia($company);

        $this->rodar($criativo);
        $criativo->refresh();

        $this->assertNotEmpty($criativo->prompt);
        $this->assertStringNotContainsString('IDENTIDADE', $criativo->prompt);
    }
}
