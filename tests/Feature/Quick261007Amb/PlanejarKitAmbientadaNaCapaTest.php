<?php

namespace Tests\Feature\Quick261007Amb;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ReferenciaEfemeraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quick 261007-amb — ponta a ponta de `PlanejarKitCriativosJob` com a
 * detecção de categoria de móvel (`CreativeCategoriaMobiliarioService`)
 * de verdade, via `GET /categories/{id}` fakeado (nunca a API real).
 *
 * `QUEUE_CONNECTION=sync` no ambiente de teste: a rota de planejar roda o
 * job na mesma request (mesma disciplina de `CriativoKitPlanejamentoTest`).
 */
class PlanejarKitAmbientadaNaCapaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');
        // Mesmo padrão de AnuncioIaRascunhoTest/GradeMassaTest: nunca bater
        // no endpoint real de oauth/token do Mercado Livre.
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        $this->app->instance(ImageGenerationProvider::class, new class implements ImageGenerationProvider
        {
            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo planejamento do kit (só 161-02).');
            }

            public function gerarTexto(string $prompt): string
            {
                // Propositalmente NÃO propõe "lifestyle" nem "hero" na
                // frente — prova que o Planner força o primeiro slot
                // correto independentemente do que o LLM devolve.
                return json_encode([
                    'estrategia' => ['publico' => 'quem compra o produto', 'proposta_de_valor' => 'fiel ao cadastro', 'direcao_visual' => 'fundo neutro'],
                    'slots' => [
                        ['tipo' => 'white_background', 'objetivo' => 'ângulo', 'cena' => 'fundo branco'],
                        ['tipo' => 'angles', 'objetivo' => 'ângulos', 'cena' => 'três quartos'],
                    ],
                ]);
            }
        });
    }

    private function criativo(string $categoryId, array $attrs = []): MlAnuncioCriativo
    {
        Storage::fake('local');

        $company = Company::factory()->create(['name' => 'Empresa Teste']);
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => $categoryId,
            // `CreativeContextBuilder::paraCriativo()` lê `categoriaId` do
            // `payload['category_id']` (o corpo que vai pro ML), nunca da
            // coluna `category_id` do rascunho — tem que estar nos dois.
            'payload'     => ['title' => 'Produto de teste', 'category_id' => $categoryId, 'attributes' => $attrs],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id'     => User::factory()->create()->id,
        ]);
        $criativo = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'hero',
            'status'      => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);
        $refs = app(ReferenciaEfemeraService::class)->guardar($criativo, [UploadedFile::fake()->image('a.jpg')]);
        $criativo->update(['referencias' => $refs]);

        return $criativo->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function fakeCategoria(string $id, array $pathFromRoot): void
    {
        Http::fake([
            "https://api.mercadolibre.com/categories/{$id}" => Http::response([
                'id'             => $id,
                'name'           => end($pathFromRoot)['name'] ?? $id,
                'path_from_root' => $pathFromRoot,
                'settings'       => ['max_pictures_per_item' => 12, 'max_pictures_per_item_var' => 10],
            ]),
        ]);
    }

    // ═══ Categoria de móvel — slot 1 do kit sai lifestyle (ambientação) ══

    public function test_categoria_de_cadeira_de_escritorio_planeja_kit_com_ambientada_na_capa(): void
    {
        $this->fakeCategoria('MLB193945', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB10252', 'name' => 'Móveis para Casa'],
            ['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório'],
        ]);

        $criativo = $this->criativo('MLB193945');

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        )->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->status);

        $slot1 = MlAnuncioCriativo::where('kit_id', $kit->id)->where('slot_indice', 1)->first();
        $this->assertSame('lifestyle', $slot1->slot);
    }

    // ═══ Categoria NÃO-móvel (panela/quadro) — capa continua hero ═══════

    public function test_categoria_de_panela_de_pressao_nao_e_tratada_como_moveis_capa_continua_hero(): void
    {
        $this->fakeCategoria('MLB31578', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB1234', 'name' => 'Cozinha'],
            ['id' => 'MLB31578', 'name' => 'Panelas de Pressão'],
        ]);

        $criativo = $this->criativo('MLB31578');

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        )->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $slot1 = MlAnuncioCriativo::where('kit_id', $kit->id)->where('slot_indice', 1)->first();

        $this->assertSame('hero', $slot1->slot);
    }

    public function test_categoria_de_quadro_decorativo_nao_e_tratada_como_moveis_capa_continua_hero(): void
    {
        $this->fakeCategoria('MLB456789', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB5678', 'name' => 'Enfeites e Decoração da Casa'],
            ['id' => 'MLB456789', 'name' => 'Quadros Decorativos'],
        ]);

        $criativo = $this->criativo('MLB456789');

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        )->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $slot1 = MlAnuncioCriativo::where('kit_id', $kit->id)->where('slot_indice', 1)->first();

        $this->assertSame('hero', $slot1->slot);
    }

    // ═══ Falha ao consultar a categoria — degrada para hero, nunca quebra ═

    public function test_falha_ao_consultar_categoria_degrada_para_hero_e_nao_quebra_o_planejamento(): void
    {
        // Nenhum fake para esta categoria: a chamada estoura dentro de
        // CreativeCategoriaMobiliarioService (preventStrayRequests), que
        // deve capturar e seguir com hero — o planejamento não pode falhar
        // por isso.
        $criativo = $this->criativo('MLB_SEM_FAKE_CATEGORIA');

        $this->actingAs($this->admin())->postJson(
            route('mlb.anuncios.criativo.kit.planejar', ['token' => $criativo->token])
        )->assertStatus(202);

        $kit = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->first();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->status);

        $slot1 = MlAnuncioCriativo::where('kit_id', $kit->id)->where('slot_indice', 1)->first();
        $this->assertSame('hero', $slot1->slot);
    }
}
