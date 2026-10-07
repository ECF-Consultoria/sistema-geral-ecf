<?php

namespace Tests\Unit\Phase169;

use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativePlanner;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\CreativeGenerationRequest;
use App\Services\Creative\Dto\CreativeGenerationResult;
use App\Services\Creative\ProductTruthBuilder;
use Tests\TestCase;

/**
 * `CreativePlanner::comTextoHumanoReforcado()` (Fase 169, TXT-03) — o
 * reforço DETERMINÍSTICO que preenche `badges` com o texto EXATO confirmado
 * pelo operador, SEM nunca mostrar o fato ao modelo de texto (LLM). O dublê
 * de `ImageGenerationProvider` aqui simula exatamente isso: devolve slots
 * SEM headline/badges preenchidos, provando que o reforço (não o LLM) é
 * quem produz o texto final.
 */
class CreativePlannerFatosHumanosTest extends TestCase
{
    private function contexto(
        array $atributos = [],
        array $beneficios = [],
        array $medidas = [],
        string $produto = 'Produto de teste',
    ): CreativeContext {
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
            fatosHumanosBeneficios: $beneficios,
            fatosHumanosMedidas: $medidas,
        );
    }

    private function truth(array $atributos = [], array $beneficios = [], array $medidas = [], string $produto = 'Produto de teste')
    {
        return (new ProductTruthBuilder)->paraContexto($this->contexto($atributos, $beneficios, $medidas, $produto));
    }

    /** Dublê que NUNCA preenche headline/badges — simula o LLM que nunca viu os fatos humanos. */
    private function providerSemTextoNosSlots(array $tipos): ImageGenerationProvider
    {
        $slots = array_map(fn ($tipo) => ['tipo' => $tipo, 'objetivo' => 'x', 'cena' => 'y'], $tipos);
        $resposta = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => $slots,
        ]);

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

    // ═══ benefits elegível só por fato humano sai com badges = texto confirmado ═══

    public function test_slot_benefits_elegivel_so_por_fato_humano_sai_com_badges_igual_ao_texto_confirmado(): void
    {
        $beneficios = ['Resistente à água', 'Fácil de montar', 'Garantia de 1 ano'];

        $plano = $this->planner($this->providerSemTextoNosSlots(['hero', 'benefits']))
            ->planejar($this->contexto(beneficios: $beneficios), $this->truth(beneficios: $beneficios), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'benefits');

        $this->assertNotNull($slot, 'benefits deveria ser elegível só com 3 benefícios confirmados pelo operador.');
        $this->assertSame($beneficios, $slot->badges);
        $this->assertNull($slot->headline, 'o reforço nunca preenche headline — só badges.');
    }

    // ═══ dimensions elegível só por medida humana sai com badges = texto confirmado ═══

    public function test_slot_dimensions_elegivel_so_por_medida_confirmada_sai_com_badges_igual_a_medida(): void
    {
        $medidas = ['Largura: 45 cm', 'Altura: 80 cm'];

        $plano = $this->planner($this->providerSemTextoNosSlots(['hero', 'dimensions']))
            ->planejar($this->contexto(medidas: $medidas), $this->truth(medidas: $medidas), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'dimensions');

        $this->assertNotNull($slot, 'dimensions deveria ser elegível só com medida confirmada pelo operador.');
        $this->assertSame($medidas, $slot->badges);
    }

    // ═══ badges acima de 3 são cortados (array_slice) ═══════════════════════

    public function test_reforco_corta_em_3_badges_mesmo_com_mais_beneficios_confirmados(): void
    {
        $beneficios = ['A', 'B', 'C', 'D', 'E'];

        $plano = $this->planner($this->providerSemTextoNosSlots(['hero', 'benefits']))
            ->planejar($this->contexto(beneficios: $beneficios), $this->truth(beneficios: $beneficios), 7);

        $slot = collect($plano->slots)->firstWhere('tipo', 'benefits');

        $this->assertSame(['A', 'B', 'C'], $slot->badges);
    }

    // ═══ Nunca sobrescreve texto que já sobreviveu à reconciliação normal (fato de CADASTRO) ═══

    public function test_reforco_nao_sobrescreve_badge_que_ja_sobreviveu_a_reconciliacao_normal_via_cadastro(): void
    {
        // 3 atributos de CADASTRO (caminho que já existia antes da Fase 169)
        // tornam benefits elegível por si só, independente de fato humano.
        $atributos = ['MATERIAL' => 'Aço carbono', 'COLOR' => 'Preto', 'BRAND' => 'Marca X'];
        // Fato humano DIFERENTE do que o LLM vai propor — se o reforço tocasse
        // o slot, o badge seria substituído por isto. Prova que não é tocado.
        $beneficiosHumanos = ['Super benefício combinado'];

        $json = json_encode([
            'estrategia' => ['publico' => 'a', 'proposta_de_valor' => 'b', 'direcao_visual' => 'c'],
            'slots' => [
                ['tipo' => 'hero', 'objetivo' => 'x', 'cena' => 'y'],
                [
                    'tipo' => 'benefits',
                    'objetivo' => 'x',
                    'cena' => 'y',
                    // Badge que casa literalmente com um valor de fatosVerificados (cadastro)
                    // — sobrevive à validarTexto() normal, SEM depender do reforço humano.
                    'badges' => ['Aço carbono'],
                ],
            ],
        ]);

        $provider = new class($json) implements ImageGenerationProvider {
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

        $plano = $this->planner($provider)
            ->planejar(
                $this->contexto($atributos, $beneficiosHumanos),
                $this->truth($atributos, $beneficiosHumanos),
                7,
            );

        $slot = collect($plano->slots)->firstWhere('tipo', 'benefits');

        $this->assertNotNull($slot);
        $this->assertSame(['Aço carbono'], $slot->badges, 'o reforço não deveria tocar um badge que já sobreviveu à reconciliação normal.');
    }

    // ═══ Sem fato humano nenhum, nada muda (regressão) ═════════════════════

    public function test_sem_fato_humano_nenhum_comportamento_de_hoje_nao_muda(): void
    {
        $plano = $this->planner($this->providerSemTextoNosSlots(['hero', 'angles']))
            ->planejar($this->contexto(), $this->truth(), 7);

        $tipos = collect($plano->slots)->pluck('tipo');

        $this->assertCount(7, $plano->slots);
        $this->assertSame('hero', $plano->slots[0]->tipo);
        $this->assertFalse($tipos->contains('benefits'));
        $this->assertFalse($tipos->contains('dimensions'));
    }
}
