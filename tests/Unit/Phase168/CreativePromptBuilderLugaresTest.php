<?php

namespace Tests\Unit\Phase168;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\ProductTruth;
use App\Services\Creative\ProductTruthBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 22/10/2026 — a mesa de jantar Aurora "4 Lugares" (`MAX_PEOPLE_CAPACITY = 4`) saiu ambientada com SEIS cadeiras, e a
 * imagem ainda entrou no combit Mesa + 4 Cadeiras. O usuário: "além da capacidade o kit é de 4, não deveria gerar para
 * 6 — em outros produtos também não pode acontecer". A capacidade do CADASTRO limita os assentos da cena.
 */
class CreativePromptBuilderLugaresTest extends TestCase
{
    use RefreshDatabase;

    private function truth(?int $lugares): ProductTruth
    {
        return new ProductTruth(
            marca: null, modelo: null, fatosVerificados: [], contagens: [], beneficiosVerificados: [],
            claimsProibidas: ['Não escreva texto na imagem.'], referenciasMeta: [], lugares: $lugares,
        );
    }

    private function prompt(?int $lugares, string $tipo): string
    {
        return (new CreativePromptBuilder(new CreativeSlotCatalog()))->paraSlot($this->truth($lugares), [
            'indice' => 1, 'tipo' => $tipo, 'objetivo' => 'x', 'cena' => 'Mesa numa sala de jantar.', 'headline' => null,
            'badges' => [], 'fatos_usados' => [], 'proibicoes' => [],
        ]);
    }

    public function test_a_cena_ambientada_nunca_tem_mais_assentos_que_a_capacidade(): void
    {
        foreach (['lifestyle', 'lifestyle_uso', 'composicao'] as $tipo) {
            $p = $this->prompt(4, $tipo);
            $this->assertStringContainsString('LUGARES: o produto acomoda no máximo 4 pessoas', $p, $tipo);
            $this->assertStringContainsString('use NO MÁXIMO 4', $p, $tipo);
        }
    }

    public function test_sem_capacidade_ou_fora_da_cena_nada_muda(): void
    {
        $this->assertStringNotContainsString('LUGARES:', $this->prompt(null, 'lifestyle'));
        $this->assertStringNotContainsString('LUGARES:', $this->prompt(4, 'hero'), 'o fundo branco não monta cena');
        $this->assertStringNotContainsString('LUGARES:', $this->prompt(4, 'detail_textura'));
    }

    public function test_a_capacidade_vem_so_do_atributo_do_cadastro(): void
    {
        $truth = fn (array $atributos) => (new ProductTruthBuilder())->paraContexto(new CreativeContext(
            ...array_merge(self::contextoBase(), ['atributos' => $atributos]),
        ));

        $this->assertSame(4, $truth(['MAX_PEOPLE_CAPACITY' => '4'])->lugares);
        $this->assertSame(6, $truth(['MAX_PEOPLE_CAPACITY' => '6 pessoas'])->lugares);
        $this->assertNull($truth(['PACKAGING_BOXES_NUMBER' => '2'])->lugares, 'outro número do cadastro não é capacidade');
        $this->assertNull($truth(['MAX_PEOPLE_CAPACITY' => 'até quatro'])->lugares, 'só número');
        $this->assertSame(4, $truth(['MAX_PEOPLE_CAPACITY' => '4'])->paraPrompt()['lugares']);
        $this->assertArrayNotHasKey('lugares', $truth([])->paraPrompt(), 'sem capacidade, a forma de antes');
    }

    /** Os argumentos mínimos do `CreativeContext` (o resto não importa aqui). */
    private static function contextoBase(): array
    {
        $r = new \ReflectionClass(CreativeContext::class);
        $args = [];
        foreach ($r->getConstructor()->getParameters() as $p) {
            if ($p->isDefaultValueAvailable()) {
                continue;
            }
            $tipo = (string) $p->getType();
            $args[$p->getName()] = match (true) {
                str_contains($tipo, 'array') => [],
                str_contains($tipo, 'int') => 0,
                str_contains($tipo, 'bool') => false,
                default => $p->allowsNull() ? null : '',
            };
        }

        return $args;
    }
}
