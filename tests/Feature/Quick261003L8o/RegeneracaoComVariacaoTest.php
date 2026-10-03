<?php

namespace Tests\Feature\Quick261003L8o;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePromptBuilder;
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
 * Regeneração com variação obrigatória + ajuste do operador (Quick
 * 261003-l8o, Task 1, correção 2) — a PROVA de ponta a ponta de que o
 * `prompt` GRAVADO depois de `POST criativo.regenerar` + 2ª execução do job
 * é diferente do `prompt` gravado na 1ª geração.
 *
 * Helpers (`respostaImagemOk()`, `kitComPortadorE7Slots()`, `admin()`,
 * `rodar()`) copiados de `tests/Feature/Phase161/CriativoKitGeracaoTest.php`
 * — nunca reescritos do zero.
 *
 * UM único `Http::fake()` com contador — dois `Http::fake()` no mesmo teste
 * NÃO substituem o primeiro (o matching é por ordem de registro).
 */
class RegeneracaoComVariacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');

        config([
            'services.creative.gemini.base_url'            => 'https://gemini.teste/v1beta',
            'services.creative.gemini.key'                  => 'chave-de-teste',
            'services.creative.gemini.text_model'           => 'modelo-texto-teste',
            'services.creative.gemini.image_model'          => 'modelo-imagem-teste',
            'services.creative.gemini.image_fallbacks'      => '',
            'services.creative.gemini.text_fallbacks'       => '',
            'services.creative.gemini.aspect_ratio'         => '1:1',
            'services.creative.gemini.image_size'           => '2K',
            'services.creative.gemini.mime'                 => 'image/jpeg',
            'services.creative.gemini.timeout'              => 30,
            'services.creative.gemini.connect_timeout'      => 10,
            'services.creative.kit.paralelo'                => 3,
            'services.creative.kit.intervalo_s'             => 15,
            'services.creative.kit.max_imagens'              => 14,
            'services.creative.kit.max_regeneracoes_asset'  => 3,
            'services.creative.kit.max_regeneracoes_kit'    => 7,
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Monta um kit com portador (fotos de referência) + 7 slots `pendente`,
     * cada um com `slot_plano` preenchido — mesmo molde de
     * `CriativoKitGeracaoTest::kitComPortadorE7Slots()`.
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

    private function rodar(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
        );
    }

    // ═══ O TESTE QUE O PLANO EXISTE PARA TER ════════════════════════════

    public function test_prompt_da_regeneracao_difere_do_da_primeira_geracao_e_ajuste_so_aparece_na_segunda(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->where('slot', 'benefits')->first();

        $chamadas = 0;
        Http::fake(function () use (&$chamadas) {
            $chamadas++;

            return Http::response($this->respostaImagemOk());
        });

        // 1ª geração.
        $this->rodar($slot);
        $slot->refresh();
        $promptPrimeiraGeracao = $slot->prompt;
        $this->assertNotNull($promptPrimeiraGeracao);
        $this->assertStringNotContainsString('VARIAÇÃO OBRIGATÓRIA', $promptPrimeiraGeracao);

        // Clique em "Gerar de novo esta imagem" com o campo "o que não ficou bom?".
        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slot->token]),
            ['motivo' => 'o produto ficou pequeno demais no quadro'],
        );
        $resposta->assertStatus(202);

        // 2ª execução do job (Queue::fake() impediu o despacho automático).
        $slot->refresh();
        $this->rodar($slot);
        $slot->refresh();
        $promptRegeneracao = $slot->prompt;

        $this->assertNotSame($promptPrimeiraGeracao, $promptRegeneracao);
        $this->assertStringContainsString('VARIAÇÃO OBRIGATÓRIA', $promptRegeneracao);
        $this->assertStringContainsString('o produto ficou pequeno demais no quadro', $promptRegeneracao);
        $this->assertStringNotContainsString('o produto ficou pequeno demais no quadro', $promptPrimeiraGeracao);

        $this->assertSame(2, $chamadas);
    }

    // ═══ regenerar_motivos — histórico, nunca sobrescrita ═══════════════

    public function test_regenerar_motivos_acrescenta_entrada_por_clique_e_texto_nao_vaza_para_a_proxima(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->where('slot', 'benefits')->first();

        Http::fake(['gemini.teste/*' => Http::response($this->respostaImagemOk())]);

        $this->rodar($slot);

        $primeiraRegen = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slot->token]),
            ['motivo' => 'ficou escuro demais'],
        );
        $primeiraRegen->assertStatus(202);

        $slot->refresh();
        $this->assertCount(1, $slot->regenerar_motivos);
        $this->assertSame('ficou escuro demais', $slot->regenerar_motivos[0]['texto']);
        $this->assertArrayHasKey('user_id', $slot->regenerar_motivos[0]);
        $this->assertArrayHasKey('em', $slot->regenerar_motivos[0]);

        $this->rodar($slot);
        $slot->refresh();
        $this->assertStringContainsString('ficou escuro demais', $slot->prompt);

        // 2ª regeneração SEM motivo — acrescenta entrada com texto nulo e o
        // prompt resultante NÃO contém o texto da regeneração anterior.
        $slot->update(['status' => MlAnuncioCriativo::STATUS_PRONTO]);
        $segundaRegen = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slot->token]),
        );
        $segundaRegen->assertStatus(202);

        $slot->refresh();
        $this->assertCount(2, $slot->regenerar_motivos);
        $this->assertNull($slot->regenerar_motivos[1]['texto']);

        $this->rodar($slot);
        $slot->refresh();
        $this->assertStringNotContainsString('ficou escuro demais', $slot->prompt);
        $this->assertStringContainsString('VARIAÇÃO OBRIGATÓRIA', $slot->prompt);
    }

    // ═══ motivo > 300 caracteres → 422 em pt-BR ══════════════════════════

    public function test_motivo_acima_de_300_caracteres_devolve_422_em_pt_br(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->where('slot', 'benefits')->first();
        $slot->update(['status' => MlAnuncioCriativo::STATUS_PRONTO]);

        $resposta = $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slot->token]),
            ['motivo' => str_repeat('a', 400)],
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString(
            'no máximo 300 caracteres',
            $resposta->json('errors.motivo.0'),
        );
        Queue::assertNothingPushed();
        $this->assertNull($slot->fresh()->regenerar_motivos);
    }

    // ═══ T-L8O-02 — regenerar_motivos nunca aparece no status do kit ════

    public function test_status_do_kit_nao_expoe_regenerar_motivos_nem_o_texto(): void
    {
        Queue::fake();
        [$kit] = $this->kitComPortadorE7Slots();
        $slot = $kit->slots()->where('slot', 'benefits')->first();
        $slot->update(['status' => MlAnuncioCriativo::STATUS_PRONTO]);

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.regenerar', ['token' => $slot->token]),
            ['motivo' => 'texto secreto que nao pode voltar ao navegador'],
        );

        $resposta = $this->actingAs($this->admin())->getJson(
            route('mlb.anuncios.criativo.kit.status', ['kit' => $kit->token]),
        );

        $resposta->assertOk();
        $json = json_encode($resposta->json());
        $this->assertStringNotContainsString('regenerar_motivos', $json);
        $this->assertStringNotContainsString('texto secreto que nao pode voltar', $json);
    }
}
