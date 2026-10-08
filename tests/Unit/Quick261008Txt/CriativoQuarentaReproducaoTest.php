<?php

namespace Tests\Unit\Quick261008Txt;

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
 * Reprodução ponta a ponta do criativo 40 (kit 7, rascunho "mesa
 * escritório", conta 459) — o caso real que motivou a quick 261008-txt.
 * `CreativePlanner::planejar()` + `CreativePromptBuilder::paraSlot()`,
 * exatamente como `GerarCriativoIaJob` encadeia os dois, sem banco.
 */
class CriativoQuarentaReproducaoTest extends TestCase
{
    private function contexto(array $atributos): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 40,
            produto: 'Mesa de escritório',
            marca: null,
            modelo: null,
            categoriaId: 'MLB1574',
            descricao: null,
            atributos: $atributos,
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [['indice' => 0, 'mime' => 'image/jpeg', 'bytes' => 12345, 'nome' => 'mesa.jpg']],
        );
    }

    private function truth(array $atributos): ProductTruth
    {
        return (new ProductTruthBuilder)->paraContexto($this->contexto($atributos));
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

    private const ATRIBUTOS_MESA = ['WIDTH' => '120 cm', 'HEIGHT' => '75 cm', 'DEPTH' => '50 cm'];

    /**
     * Forma exata gravada em produção no criativo 40: o slot `dimensions`
     * foi aceito, mas SEM nenhum headline/badge — `fatos_usados: []`. Prova
     * (a): sem fato nenhum validado, o prompt final não se contradiz mais.
     */
    public function test_sem_proposta_de_texto_do_modelo_o_prompt_final_nao_se_contradiz(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'ignorada'],
            ],
        ]);

        $truth  = $this->truth(self::ATRIBUTOS_MESA);
        $planer = new CreativePlanner($this->providerComResposta($json), new CreativeSlotCatalog());
        $plano  = $planer->planejar($this->contexto(self::ATRIBUTOS_MESA), $truth, 7);

        $slotDimensoes = collect($plano->slots)->firstWhere('tipo', 'dimensions');
        $this->assertNotNull($slotDimensoes);
        $this->assertSame([], $slotDimensoes->badges);
        $this->assertNull($slotDimensoes->headline);

        $prompt = (new CreativePromptBuilder(new CreativeSlotCatalog()))->paraSlot($truth, $slotDimensoes->paraPrompt());

        $this->assertStringNotContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('TEXTO: NENHUM valor foi confirmado', $prompt);

        $posClaims   = strpos($prompt, 'CLAIMS PROIBIDAS:');
        $blocoClaims = substr($prompt, $posClaims);
        $this->assertSame(1, substr_count($blocoClaims, 'Não altere a cor do produto.'));
    }

    /**
     * O modelo PROPÕE as três medidas (como de fato propôs em produção,
     * segundo o relato do usuário) — prova (b): o valor exibido vem só do
     * cadastro (o sistema remonta com o rótulo pt-BR do Truth, nunca aceita
     * a frase do modelo como veio).
     */
    public function test_modelo_propondo_as_tres_medidas_produz_badges_rotuladas_em_pt_br(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo'     => 'dimensions',
                    'objetivo' => 'medidas',
                    'cena'     => 'ignorada',
                    'badges'   => ['120 cm', '75 cm', '50 cm'],
                ],
            ],
        ]);

        $truth  = $this->truth(self::ATRIBUTOS_MESA);
        $planer = new CreativePlanner($this->providerComResposta($json), new CreativeSlotCatalog());
        $plano  = $planer->planejar($this->contexto(self::ATRIBUTOS_MESA), $truth, 7);

        $slotDimensoes = collect($plano->slots)->firstWhere('tipo', 'dimensions');
        $this->assertNotNull($slotDimensoes);
        $this->assertSame(['Largura: 120 cm', 'Altura: 75 cm', 'Profundidade: 50 cm'], $slotDimensoes->badges);

        $prompt = (new CreativePromptBuilder(new CreativeSlotCatalog()))->paraSlot($truth, $slotDimensoes->paraPrompt());

        $this->assertStringContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('- Badge: Largura: 120 cm', $prompt);
        $this->assertStringContainsString('- Badge: Altura: 75 cm', $prompt);
        $this->assertStringContainsString('- Badge: Profundidade: 50 cm', $prompt);

        // Nenhum vestígio do id cru em inglês chega ao prompt.
        $this->assertStringNotContainsString('WIDTH', $prompt);
        $this->assertStringNotContainsString('HEIGHT', $prompt);
        $this->assertStringNotContainsString('DEPTH', $prompt);
    }

    /**
     * Prova (c): medida de EMBALAGEM continua fora da imagem de medidas —
     * `dimensions` nem fica elegível quando o único atributo de dimensão é
     * de embalagem (quick 261007-ifa), então não há como uma medida de
     * caixa aparecer rotulada na imagem do produto.
     */
    public function test_medida_de_embalagem_no_cadastro_nao_torna_dimensions_elegivel_nem_aparece_rotulada(): void
    {
        $atributosComEmbalagem = ['SELLER_PACKAGE_WIDTH' => '12 cm', 'SELLER_PACKAGE_HEIGHT' => '12 cm'];

        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'ignorada', 'badges' => ['12 cm']],
            ],
        ]);

        $truth  = $this->truth($atributosComEmbalagem);
        $planer = new CreativePlanner($this->providerComResposta($json), new CreativeSlotCatalog());
        $plano  = $planer->planejar($this->contexto($atributosComEmbalagem), $truth, 7);

        $this->assertFalse(collect($plano->slots)->pluck('tipo')->contains('dimensions'));
    }
}
