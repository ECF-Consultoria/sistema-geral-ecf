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
 * Quick 261007-amb, Tarefa 2 — `CreativePlanner::montarSlotAceito()` força a
 * `cena` do catálogo (layout de medidas/tópicos) para qualquer tipo que
 * aceite texto, mesmo que o LLM proponha outra — o layout é pedido do
 * usuário (baseado em prints reais de referência), nunca espaço de
 * criatividade do modelo.
 */
class CreativePlannerLayoutForcadoTest extends TestCase
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

    private function planner(ImageGenerationProvider $provider): CreativePlanner
    {
        return new CreativePlanner($provider, new CreativeSlotCatalog);
    }

    public function test_cena_de_dimensions_e_sempre_o_layout_de_medidas_mesmo_se_o_llm_propuser_outra(): void
    {
        $atributos = ['WIDTH' => '45 cm'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'régua qualquer, livre do modelo'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 2);

        $slotDimensoes = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slotDimensoes);
        $this->assertStringContainsString('TAMANHO DO PRODUTO', $slotDimensoes->cena);
        $this->assertStringNotContainsString('régua qualquer, livre do modelo', $slotDimensoes->cena);
    }

    public function test_cena_de_feature_highlight_e_sempre_o_layout_de_topicos_mesmo_se_o_llm_propuser_outra(): void
    {
        $atributos = ['MATERIAL' => 'Aço carbono'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'feature_highlight', 'objetivo' => 'destacar material', 'cena' => 'cena livre do modelo'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 2);

        $slot = collect($plano->slots)->firstWhere('tipo', 'feature_highlight');

        $this->assertNotNull($slot);
        $this->assertStringContainsString('recortes circulares', $slot->cena);
        $this->assertStringNotContainsString('cena livre do modelo', $slot->cena);
    }

    public function test_cena_de_tipo_visual_sem_texto_continua_vindo_do_llm_quando_proposta(): void
    {
        // Não regressão: tipos SEM_FATO (sem texto) continuam com a cena
        // do LLM quando proposta — só os tipos com texto são forçados.
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'angles', 'objetivo' => 'x', 'cena' => 'três ângulos propostos pelo modelo'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(), $this->truth(), 2);

        $slot = collect($plano->slots)->firstWhere('tipo', 'angles');

        $this->assertNotNull($slot);
        $this->assertSame('três ângulos propostos pelo modelo', $slot->cena);
    }
}
