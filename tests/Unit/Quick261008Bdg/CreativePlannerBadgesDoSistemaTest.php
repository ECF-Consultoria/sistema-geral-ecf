<?php

namespace Tests\Unit\Quick261008Bdg;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\Dto\ProductTruth;
use App\Services\Creative\ProductTruthBuilder;
use Tests\TestCase;

/**
 * `CreativePlanner` + `CreativePromptBuilder` ponta a ponta — quick
 * 261008-bdg ("o sistema monta o texto do cadastro, sem depender do
 * modelo propor"). Cobre os DOIS caminhos que produzem `slot_plano` sem
 * nenhuma proposta do LLM, que é exatamente a forma gravada em produção
 * nos criativos 34 e 40 (rascunho "mesa escritório", conta 459):
 *   - `montarSlotAceito()` — o LLM propôs o TIPO do slot, mas sem
 *     headline/badge aproveitável.
 *   - `montarSlotPadrao()` — o LLM nem propôs o tipo; o Planner completa
 *     pela prioridade do catálogo.
 */
class CreativePlannerBadgesDoSistemaTest extends TestCase
{
    private function contexto(array $atributos, string $produto = 'Mesa de escritório'): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 40,
            produto: $produto,
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: 'MLB1574',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [['indice' => 0, 'mime' => 'image/jpeg', 'bytes' => 12345, 'nome' => 'mesa.jpg']],
        );
    }

    private function truth(array $atributos, string $produto = 'Mesa de escritório'): ProductTruth
    {
        return (new ProductTruthBuilder)->paraContexto($this->contexto($atributos, $produto));
    }

    private function providerComResposta(string $resposta): ImageGenerationProvider
    {
        return new class($resposta) implements ImageGenerationProvider {
            public function __construct(private string $resposta) {}

            public function gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult
            {
                throw new \RuntimeException('não usado neste teste.');
            }

            public function gerarTexto(string $prompt): string
            {
                return $this->resposta;
            }
        };
    }

    private function planner(ImageGenerationProvider $provider): CreativePlanner
    {
        return new CreativePlanner($provider, new CreativeSlotCatalog());
    }

    /**
     * Atributos reais do caso de produção (simplificados): as três medidas
     * do produto, mais MODEL com lista de palavras-chave de SEO (achado em
     * produção — "parece fato e não é") e BRAND, que nunca devem virar
     * badge.
     */
    private const ATRIBUTOS_MESA_PRODUCAO = [
        'WIDTH'  => '120 cm',
        'HEIGHT' => '75 cm',
        'DEPTH'  => '50 cm',
        'BRAND'  => 'Genérica',
        'MODEL'  => 'mesa escritorio gaveta, escrivaninha com gavetas, escrivaninha, mesa de estudos',
        'MAIN_MATERIAL' => 'MDF',
        'COLOR'  => 'Branco',
    ];

    // ═══ montarSlotAceito() — tipo proposto, sem headline/badge do modelo ═══

    public function test_montar_slot_aceito_sem_proposta_de_texto_monta_badges_do_sistema(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                // Forma exata de produção: tipo proposto, badges/headline AUSENTES.
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'ignorada'],
            ],
        ]);

        $truth  = $this->truth(self::ATRIBUTOS_MESA_PRODUCAO);
        $plano  = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(self::ATRIBUTOS_MESA_PRODUCAO), $truth, 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot);
        $this->assertSame(['Largura: 120 cm', 'Altura: 75 cm', 'Profundidade: 50 cm'], $slot->badges);
        $this->assertNull($slot->headline);
        // MODEL (SEO) e BRAND nunca aparecem como badge.
        $this->assertStringNotContainsString('escritorio', implode(' ', $slot->badges));
        $this->assertStringNotContainsString('Genérica', implode(' ', $slot->badges));
    }

    public function test_montar_slot_aceito_quando_badge_do_modelo_e_descartada_tambem_cai_no_sistema(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo'     => 'dimensions',
                    'objetivo' => 'medidas',
                    'cena'     => 'ignorada',
                    'badges'   => ['Largura: 999 cm'], // inventado, descartado por validarTexto()
                ],
            ],
        ]);

        $truth = $this->truth(self::ATRIBUTOS_MESA_PRODUCAO);
        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(self::ATRIBUTOS_MESA_PRODUCAO), $truth, 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot);
        $this->assertSame(['Largura: 120 cm', 'Altura: 75 cm', 'Profundidade: 50 cm'], $slot->badges);
    }

    // ═══ montarSlotPadrao() — LLM nem propôs o tipo ══════════════════════════

    public function test_montar_slot_padrao_preenche_badges_do_sistema_quando_llm_nao_propoe_o_tipo(): void
    {
        // O LLM só propõe "hero" — "dimensions" entra via prioridade do
        // catálogo (montarSlotPadrao()), nunca passando por validarTexto().
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
            ],
        ]);

        $truth = $this->truth(self::ATRIBUTOS_MESA_PRODUCAO);
        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(self::ATRIBUTOS_MESA_PRODUCAO), $truth, 2);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot);
        $this->assertSame(['Largura: 120 cm', 'Altura: 75 cm', 'Profundidade: 50 cm'], $slot->badges);
    }

    // ═══ Ramo honesto (261008-txt) continua valendo quando o Truth não sustenta nada ═══

    public function test_sem_fato_nenhum_o_slot_continua_sem_texto_e_o_prompt_nao_se_contradiz(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
            ],
        ]);

        // Só um fato genérico (>= 1), sem nenhuma medida — feature_highlight
        // fica elegível (count(fatosVerificados) >= 1) mas o único fato
        // (WIDTH) já é coberto pelo slot dedicado de dimensions, então
        // fatosGenericosParaTopicos() não tem nada a oferecer para ele.
        $atributos = ['WIDTH' => '120 cm'];
        $truth     = $this->truth($atributos);
        $plano     = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $truth, 3);

        $slotDimensoes = collect($plano->slots)->firstWhere('tipo', 'dimensions');
        $this->assertNotNull($slotDimensoes);
        $this->assertNotEmpty($slotDimensoes->badges, 'dimensions tem fato (WIDTH) — deveria ter badge.');

        $slotFeature = collect($plano->slots)->firstWhere('tipo', 'feature_highlight');
        $this->assertNotNull($slotFeature);
        $this->assertSame([], $slotFeature->badges, 'feature_highlight não tem fato próprio — deve ficar sem texto.');
        $this->assertNull($slotFeature->headline);

        $prompt = (new CreativePromptBuilder(new CreativeSlotCatalog()))->paraSlot($truth, $slotFeature->paraPrompt());

        $this->assertStringNotContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('TEXTO: NENHUM valor foi confirmado', $prompt);
    }

    public function test_truth_sem_nenhum_atributo_nunca_produz_badge_em_tipo_nenhum(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y']],
        ]);

        $truth = $this->truth([]);
        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto([]), $truth, 7);

        foreach ($plano->slots as $slot) {
            $this->assertSame([], $slot->badges, "tipo={$slot->tipo} não deveria ter badge sem fato nenhum no cadastro.");
            $this->assertNull($slot->headline);
        }
    }
}
