<?php

namespace Tests\Unit\Quick261007Amb;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\Dto\ProductTruth;
use App\Services\Creative\ProductTruthBuilder;
use Tests\TestCase;

/**
 * Quick 261007-amb — `CreativePlanner::planejar($contexto, $truth, $quantidade,
 * $categoriaMoveis)`. Sem banco, sem HTTP: `$categoriaMoveis` é um boolean
 * que o CHAMADOR já decidiu fora daqui (`PlanejarKitCriativosJob`, via
 * `CreativeCategoriaMobiliarioService`) — o Planner nunca consulta a API.
 *
 * Prova as duas garantias do briefing:
 *  (a) com fato disponível e categoria de móvel, saem ambientada + slot com
 *      texto;
 *  (b) sem fato e categoria de móvel, saem ambientada + um visual.
 * E a não-regressão: sem `$categoriaMoveis` (ou `false`), o comportamento é
 * byte a byte o de antes (slot 1 = hero).
 */
class CreativePlannerAmbientadaNaCapaTest extends TestCase
{
    private function contexto(array $atributos = [], string $produto = 'Produto de teste'): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 1,
            produto: $produto,
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: 'MLB193945',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [['indice' => 0, 'mime' => 'image/jpeg', 'bytes' => 12345, 'nome' => 'foto.jpg']],
        );
    }

    private function truth(array $atributos = [], string $produto = 'Produto de teste'): ProductTruth
    {
        return (new ProductTruthBuilder)->paraContexto($this->contexto($atributos, $produto));
    }

    private function providerComResposta(string $resposta): ImageGenerationProvider
    {
        return new class($resposta) implements ImageGenerationProvider {
            public function __construct(private string $resposta) {}

            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo CreativePlanner.');
            }

            public function gerarTexto(string $prompt): string
            {
                return $this->resposta;
            }
        };
    }

    private function providerQueFalha(): ImageGenerationProvider
    {
        return new class implements ImageGenerationProvider {
            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('gerarImagem() não é usado pelo CreativePlanner.');
            }

            public function gerarTexto(string $prompt): string
            {
                throw new \RuntimeException('Provedor fora do ar.');
            }
        };
    }

    private function planner(ImageGenerationProvider $provider): CreativePlanner
    {
        return new CreativePlanner($provider, new CreativeSlotCatalog);
    }

    // ═══ Não-regressão — default continua hero ══════════════════════════

    public function test_sem_passar_categoria_moveis_slot_1_continua_hero(): void
    {
        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto(), $this->truth(), 2);

        $this->assertSame('hero', $plano->slots[0]->tipo);
    }

    public function test_categoria_moveis_falso_explicito_slot_1_continua_hero(): void
    {
        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto(), $this->truth(), 2, false);

        $this->assertSame('hero', $plano->slots[0]->tipo);
    }

    // ═══ (b) Categoria de móvel, SEM fato — ambientada + 1 visual ════════

    public function test_categoria_moveis_sem_fato_sai_ambientada_mais_um_visual(): void
    {
        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto(), $this->truth(), 2, true);

        $this->assertCount(2, $plano->slots);
        $this->assertSame('lifestyle', $plano->slots[0]->tipo);
        $this->assertFalse((new CreativeSlotCatalog)->aceitaTexto($plano->slots[1]->tipo), 'sem fato nenhum, o 2º slot tem que ser visual (sem texto).');
    }

    // ═══ (a) Categoria de móvel, COM fato — ambientada + slot com texto ═

    public function test_categoria_moveis_com_fato_sai_ambientada_mais_slot_com_texto(): void
    {
        $atributos = ['WIDTH' => '45 cm'];

        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto($atributos), $this->truth($atributos), 2, true);

        $this->assertCount(2, $plano->slots);
        $this->assertSame('lifestyle', $plano->slots[0]->tipo);
        $this->assertSame('dimensions', $plano->slots[1]->tipo);
        $this->assertTrue((new CreativeSlotCatalog)->aceitaTexto($plano->slots[1]->tipo));
    }

    // ═══ Lifestyle forçado mesmo quando o LLM propõe outra coisa primeiro ═

    public function test_lifestyle_e_forcado_na_posicao_1_mesmo_quando_llm_propoe_outra_coisa_primeiro(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'white_background', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'lifestyle', 'objetivo' => 'deveria ser ignorado na ordem', 'cena' => 'y'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(), $this->truth(), 2, true);

        $this->assertSame('lifestyle', $plano->slots[0]->tipo);
    }

    // ═══ Falha do provedor — plano determinístico, ainda assim ambientada ═

    public function test_falha_do_provedor_com_categoria_moveis_produz_plano_deterministico_com_lifestyle_primeiro(): void
    {
        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto(), $this->truth(), 2, true);

        $this->assertSame('deterministico', $plano->origem);
        $this->assertSame('lifestyle', $plano->slots[0]->tipo);
    }
}
