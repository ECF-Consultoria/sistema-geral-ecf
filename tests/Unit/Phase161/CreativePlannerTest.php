<?php

namespace Tests\Unit\Phase161;

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
 * `CreativePlanner` — prova de PLAN-01 (mínimo 7), PLAN-02 (slot 1 = hero,
 * escolha dinâmica dos demais) e PLAN-03 (nenhum slot sem fato sustentado,
 * mesmo que o LLM o proponha). Monta `CreativeContext`/`ProductTruth` à mão,
 * sem banco — nenhum destes testes precisa de `RefreshDatabase`.
 *
 * O caso "Truth sem dimensão e LLM propondo dimensions" é o teste MAIS
 * IMPORTANTE deste plano: é a prova direta de PLAN-03.
 */
class CreativePlannerTest extends TestCase
{
    private function contexto(array $atributos = [], string $produto = 'Produto de teste'): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 1,
            produto: $produto,
            marca: $atributos['BRAND'] ?? null,
            modelo: $atributos['MODEL'] ?? null,
            categoriaId: 'MLB1574',
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

    /** Dublê de `ImageGenerationProvider` — devolve um texto fixo em `gerarTexto()`; `gerarImagem()` nunca é chamado pelo planner. */
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

    private const SEM_FATO = [
        'hero', 'white_background', 'angles', 'detail',
        'lifestyle', 'lifestyle_uso', 'detail_textura', 'composicao',
    ];

    // ═══ PLAN-01/PLAN-03 — piso visual quando não há fato nenhum ═══════════

    public function test_truth_sem_atributo_sai_com_7_slots_todos_visuais_e_slot1_hero(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            // Propositalmente NÃO inclui "hero" e inclui dois tipos COM_FATO
            // que não são elegíveis sem atributo nenhum — devem ser descartados.
            'slots' => [
                ['tipo' => 'white_background', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'benefits', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'angles', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'detail', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'lifestyle', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'composicao', 'objetivo' => 'x', 'cena' => 'y'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(), $this->truth(), 7);

        $tipos = collect($plano->slots)->pluck('tipo');

        $this->assertCount(7, $plano->slots);
        $this->assertSame('hero', $plano->slots[0]->tipo);
        $this->assertTrue($tipos->every(fn ($t) => in_array($t, self::SEM_FATO, true)));
        $this->assertFalse($tipos->contains('benefits'));
        $this->assertFalse($tipos->contains('dimensions'));
    }

    // ═══ PLAN-03 — o teste mais importante do plano ═════════════════════

    public function test_dimensions_nao_aparece_sem_atributo_de_dimensao_mesmo_proposto_pelo_llm(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'régua', 'headline' => '45 cm'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(['MATERIAL' => 'MDF']), $this->truth(['MATERIAL' => 'MDF']), 7);

        $this->assertFalse(collect($plano->slots)->pluck('tipo')->contains('dimensions'));
    }

    public function test_dimensions_aparece_quando_ha_atributo_de_dimensao_e_o_llm_propoe(): void
    {
        // Medida do PRODUTO, não da embalagem (quick 261007-ifa corrigiu
        // SELLER_PACKAGE_* para nunca satisfazer `dimensions` — usar aqui o
        // atributo de embalagem voltaria a encobrir o bug).
        $atributos = ['WIDTH' => '45 cm'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'dimensions', 'objetivo' => 'medidas', 'cena' => 'régua com guia de medidas'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 7);

        $this->assertTrue(collect($plano->slots)->pluck('tipo')->contains('dimensions'));
    }

    // ═══ Reconciliação — tipo inexistente, duplicado, completude ═══════════

    public function test_tipo_inexistente_e_duplicado_sao_descartados_e_o_plano_completa_7(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'voo_de_drone_inexistente', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'angles', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'angles', 'objetivo' => 'duplicado', 'cena' => 'y'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(), $this->truth(), 7);

        $tipos = collect($plano->slots)->pluck('tipo');

        $this->assertCount(7, $plano->slots);
        $this->assertSame('hero', $plano->slots[0]->tipo);
        $this->assertSame(1, $tipos->filter(fn ($t) => $t === 'angles')->count());
        $this->assertFalse($tipos->contains('voo_de_drone_inexistente'));
    }

    // ═══ JSON cercado por crases ════════════════════════════════════════

    public function test_json_cercado_por_crases_e_lido_igual(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'angles', 'objetivo' => 'x', 'cena' => 'y'],
            ],
        ]);
        $resposta = "```json\n{$json}\n```";

        $plano = $this->planner($this->providerComResposta($resposta))
            ->planejar($this->contexto(), $this->truth(), 7);

        $this->assertSame('llm', $plano->origem);
        $this->assertCount(7, $plano->slots);
    }

    // ═══ Falha do provedor — plano determinístico, nunca propaga exceção ═══

    public function test_falha_do_provedor_produz_plano_deterministico_completo(): void
    {
        $plano = $this->planner($this->providerQueFalha())
            ->planejar($this->contexto(), $this->truth(), 7);

        $this->assertSame('deterministico', $plano->origem);
        $this->assertCount(7, $plano->slots);
        $this->assertSame('hero', $plano->slots[0]->tipo);
    }

    // ═══ Badge — só sobrevive quando casa com o Truth, e sai ROTULADA ═════
    // (quick 261008-txt, Correção 2: o SISTEMA remonta "rótulo: valor",
    // nunca aceita o literal do modelo — nem quando ele já bate certo.)

    public function test_badge_que_nao_casa_e_descartada_e_a_que_casa_sai_rotulada_pelo_truth(): void
    {
        $atributos = ['MATERIAL' => 'Aço carbono'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo'     => 'feature_highlight',
                    'objetivo' => 'destacar material',
                    'cena'     => 'close no material',
                    'badges'   => ['Aço carbono', 'Garantia vitalícia inventada'],
                ],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'feature_highlight');

        $this->assertNotNull($slot, 'feature_highlight deveria ser elegível com 1 fato verificado.');
        $this->assertSame(['Material: Aço carbono'], $slot->badges);
    }

    /**
     * A correção central da quick 261008-txt: o modelo propõe a badge JÁ
     * com um rótulo colado (como de fato propôs em produção, criativo 40) —
     * antes, isso era descartado por não casar byte a byte com o valor NU
     * do cadastro. Agora casa (pela parte depois do ":") e sai remontada
     * com o rótulo OFICIAL do Truth — que pode até ser diferente do que o
     * modelo colou, prova de que o texto nunca é aceito como veio.
     */
    public function test_badge_com_rotulo_proprio_do_modelo_casa_pelo_valor_e_sai_com_o_rotulo_do_truth(): void
    {
        $atributos = ['WIDTH' => '120 cm'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo'     => 'dimensions',
                    'objetivo' => 'medidas',
                    'cena'     => 'ignorada (forçada pelo catálogo)',
                    'badges'   => ['Tamanho: 120 cm'], // rótulo ERRADO proposto pelo modelo
                ],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot);
        // "Largura", não "Tamanho" — o rótulo vem do Truth, nunca do modelo.
        $this->assertSame(['Largura: 120 cm'], $slot->badges);
    }

    /**
     * TRUTH-02/03: o número SÓ pode vir do cadastro. Uma badge com o valor
     * certo mas o rótulo errado ainda casa (Correção 2); uma badge com
     * VALOR que não existe no cadastro é descartada, mesmo citando um
     * rótulo real — nunca aceitamos o número que o modelo inventou.
     */
    public function test_badge_com_valor_que_nao_existe_no_cadastro_e_descartada_mesmo_citando_rotulo_real(): void
    {
        $atributos = ['WIDTH' => '120 cm'];
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo'     => 'dimensions',
                    'objetivo' => 'medidas',
                    'cena'     => 'ignorada (forçada pelo catálogo)',
                    'badges'   => ['Largura: 999 cm'], // número INVENTADO, cadastro diz 120 cm
                ],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto($atributos), $this->truth($atributos), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot);
        $this->assertSame([], $slot->badges);
    }

    // ═══ Slot 1 é sempre hero, nunca em outra posição ═══════════════════

    public function test_slot_1_e_sempre_hero_mesmo_quando_llm_propoe_outra_coisa_primeiro(): void
    {
        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'angles', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'detail', 'objetivo' => 'x', 'cena' => 'y'],
                ['tipo' => 'hero', 'objetivo' => 'deveria ser ignorado', 'cena' => 'y'],
            ],
        ]);

        $plano = $this->planner($this->providerComResposta($json))
            ->planejar($this->contexto(), $this->truth(), 7);

        $tipos = collect($plano->slots)->pluck('tipo');

        $this->assertSame('hero', $plano->slots[0]->tipo);
        $this->assertSame(1, $tipos->filter(fn ($t) => $t === 'hero')->count());
    }
}
