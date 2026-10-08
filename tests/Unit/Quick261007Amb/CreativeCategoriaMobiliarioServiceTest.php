<?php

namespace Tests\Unit\Quick261007Amb;

use App\Services\Creative\CreativeCategoriaMobiliarioService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quick 261007-amb — `CreativeCategoriaMobiliarioService::ehMoveis()`.
 *
 * Os caminhos (`path_from_root`) usados aqui reproduzem a medição real
 * contra a API do Mercado Livre (2026-10-07, ver PLAN.md da quick task):
 * móveis passam por "Móveis para Casa" ABAIXO da raiz; panela e quadro NÃO,
 * mesmo com a raiz "Casa, Móveis e Decoração" contendo a palavra "Móveis".
 *
 * `Http::preventStrayRequests()` garante que nenhum teste aqui chama a API
 * real — o app token é pré-semeado no cache (mesmo padrão de
 * `AnuncioIaRascunhoTest`/`GradeMassaTest`) para nunca precisar do endpoint
 * de oauth/token.
 */
class CreativeCategoriaMobiliarioServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
    }

    private function servico(): CreativeCategoriaMobiliarioService
    {
        return app(CreativeCategoriaMobiliarioService::class);
    }

    private function fakeCategoria(string $id, array $pathFromRoot): void
    {
        Http::fake([
            "https://api.mercadolibre.com/categories/{$id}" => Http::response([
                'id'              => $id,
                'name'            => end($pathFromRoot)['name'] ?? $id,
                'path_from_root'  => $pathFromRoot,
            ]),
        ]);
    }

    // ═══ Móvel — nó "Móveis para Casa" ABAIXO da raiz ═══════════════════

    public function test_cadeira_de_escritorio_e_moveis(): void
    {
        $this->fakeCategoria('MLB193945', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB10252', 'name' => 'Móveis para Casa'],
            ['id' => 'MLB193945', 'name' => 'Cadeiras de Escritório'],
        ]);

        $this->assertTrue($this->servico()->ehMoveis('MLB193945'));
    }

    public function test_mesa_de_centro_e_moveis(): void
    {
        $this->fakeCategoria('MLB999001', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB10252', 'name' => 'Móveis para Casa'],
            ['id' => 'MLB999001', 'name' => 'Mesas de Centro'],
        ]);

        $this->assertTrue($this->servico()->ehMoveis('MLB999001'));
    }

    // ═══ Não-móvel — raiz tem "Móveis" mas o nó abaixo não ══════════════

    public function test_panela_de_pressao_nao_e_moveis_mesmo_com_moveis_na_raiz(): void
    {
        $this->fakeCategoria('MLB31578', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB1234', 'name' => 'Cozinha'],
            ['id' => 'MLB31578', 'name' => 'Panelas de Pressão'],
        ]);

        $this->assertFalse($this->servico()->ehMoveis('MLB31578'));
    }

    public function test_quadro_decorativo_nao_e_moveis_mesmo_com_moveis_na_raiz(): void
    {
        $this->fakeCategoria('MLB456789', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
            ['id' => 'MLB5678', 'name' => 'Enfeites e Decoração da Casa'],
            ['id' => 'MLB456789', 'name' => 'Quadros Decorativos'],
        ]);

        $this->assertFalse($this->servico()->ehMoveis('MLB456789'));
    }

    // ═══ Degradação graciosa ═════════════════════════════════════════════

    public function test_categoria_nula_e_vazia_resolvem_false_sem_chamar_http(): void
    {
        $this->assertFalse($this->servico()->ehMoveis(null));
        $this->assertFalse($this->servico()->ehMoveis(''));
        $this->assertFalse($this->servico()->ehMoveis('   '));
    }

    public function test_falha_de_rede_resolve_false_nunca_lanca(): void
    {
        // Nenhum fake registrado para esta categoria + preventStrayRequests
        // ativo: a chamada estoura dentro do serviço, que deve capturar e
        // devolver false — nunca propagar a exceção.
        $this->assertFalse($this->servico()->ehMoveis('MLB_SEM_FAKE_NENHUM'));
    }

    public function test_categoria_sem_path_from_root_resolve_false(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/categories/MLB000' => Http::response([
                'id' => 'MLB000', 'name' => 'Categoria sem caminho',
            ]),
        ]);

        $this->assertFalse($this->servico()->ehMoveis('MLB000'));
    }

    public function test_categoria_com_um_unico_nivel_raiz_resolve_false(): void
    {
        // path_from_root com só a raiz: array_slice(..., 1) fica vazio —
        // não há nó abaixo da raiz para examinar.
        $this->fakeCategoria('MLB1574', [
            ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
        ]);

        $this->assertFalse($this->servico()->ehMoveis('MLB1574'));
    }
}
