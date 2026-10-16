<?php

namespace Tests\Unit\Quick261016Mwe;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * Quick 261016-mwe — testando o fluxo de publicação, o usuário relatou:
 *  - a imagem de medidas AINDA vinha com o produto em miniatura no canto (mesmo depois do 261016-lu1);
 *  - na imagem de medidas/pontos fortes, "as escritas frequentemente saem bugadas — uma palavra ou um número".
 *
 * Medido no prompt gravado do kit 17 / slot 2 (produção): a CENA vinha do `slot_plano` gravado no
 * PLANEJAMENTO (15:19, antes do 261016-lu1) — toda regeneração repetia "Miniatura do produto em outro
 * ângulo no canto superior". E o prompt se contradizia: a CENA pedia cabeçalho + LISTA das medidas +
 * rótulo nas cotas (cada medida duas vezes), enquanto o TEXTO dizia "só estes 3 textos, nenhum outro".
 */
class PromptDeTextoELayoutAtualTest extends TestCase
{
    /** A cena literal que estava gravada no `slot_plano` do kit 17 em produção. */
    private const CENA_VELHA = 'Fundo claro e liso. Cabeçalho curto em caixa alta com ícone simples de régua (ex.: "TAMANHO DO PRODUTO"). '
        .'Ao lado do cabeçalho, a lista das medidas com marcadores quadrados. Produto centralizado, em ângulo que mostre bem '
        .'suas dimensões, com linhas de cota finas sobre ele — seta nas duas pontas de cada linha, valor da medida rotulado ao '
        .'lado. Miniatura do produto em outro ângulo no canto superior. Tipografia sans-serif, texto escuro sobre fundo claro.';

    private function builder(): CreativePromptBuilder
    {
        return new CreativePromptBuilder(new CreativeSlotCatalog);
    }

    private function truth(): ProductTruth
    {
        return new ProductTruth(
            marca: 'Cinamomo',
            modelo: 'guarda roupa 8 gavetas',
            fatosVerificados: ['Largura' => '160 cm', 'Altura' => '230 cm', 'Profundidade' => '55 cm'],
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: ['Não altere a cor do produto.'],
            referenciasMeta: [],
        );
    }

    private function slotMedidas(array $extra = []): array
    {
        return $extra + [
            'indice' => 2,
            'tipo' => 'dimensions',
            'objetivo' => 'Apresentar visualmente o porte e as medidas reais do guarda-roupa.',
            'cena' => self::CENA_VELHA,
            'headline' => null,
            'badges' => ['Largura: 160 cm', 'Altura: 230 cm', 'Profundidade: 55 cm'],
            'fatos_usados' => [],
            'proibicoes' => [],
        ];
    }

    private function linhaDaCena(string $prompt): string
    {
        preg_match('/^CENA: .*$/m', $prompt, $m);

        return $m[0] ?? '';
    }

    public function test_kit_planejado_antes_usa_o_layout_atual_e_nao_pede_miniatura(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotMedidas());
        $cena = $this->linhaDaCena($prompt);

        $this->assertStringNotContainsString('Miniatura do produto', $prompt);
        $this->assertStringNotContainsString('marcadores quadrados', $cena);
        $this->assertStringContainsString('O produto aparece UMA única vez na imagem: sem miniatura', $cena);
        $this->assertStringContainsString('Cada medida aparece UMA única vez na imagem', $cena);
    }

    public function test_cabecalho_entra_na_lista_texto_e_as_frases_de_antes_continuam(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotMedidas());

        $this->assertStringContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('- Cabeçalho: TAMANHO DO PRODUTO', $prompt);
        $this->assertStringContainsString('- Badge: Largura: 160 cm', $prompt);
        $this->assertStringContainsString('- Badge: Altura: 230 cm', $prompt);
        $this->assertStringContainsString('- Badge: Profundidade: 55 cm', $prompt);
        // O cabeçalho vem ANTES das medidas, logo depois da instrução.
        $this->assertLessThan(strpos($prompt, '- Badge: Largura'), strpos($prompt, '- Cabeçalho:'));
    }

    public function test_regras_da_escrita_vem_logo_depois_da_lista(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotMedidas());

        $this->assertStringContainsString('Copie cada texto acima letra por letra e número por número', $prompt);
        $this->assertStringContainsString('Cada texto aparece UMA única vez na imagem.', $prompt);
        $this->assertStringContainsString('sem legenda, sem slogan, sem linha de apoio', $prompt);
        $this->assertLessThan(strpos($prompt, 'FATOS PERMITIDOS'), strpos($prompt, 'Regras da escrita'));
    }

    public function test_sem_texto_confirmado_nao_ganha_cabecalho_nem_regras_de_escrita(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotMedidas(['badges' => []]));

        $this->assertStringContainsString('TEXTO: NENHUM valor foi confirmado', $prompt);
        $this->assertStringNotContainsString('- Cabeçalho:', $prompt);
        $this->assertStringNotContainsString('Regras da escrita', $prompt);
    }

    public function test_tipo_sem_texto_continua_com_a_cena_proposta_no_planejamento(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), [
            'indice' => 1, 'tipo' => 'white_background', 'objetivo' => 'x',
            'cena' => 'produto em ângulo de três quartos sobre fundo branco', 'headline' => null, 'badges' => [],
        ]);

        $this->assertStringContainsString('CENA: produto em ângulo de três quartos sobre fundo branco', $prompt);
        $this->assertStringNotContainsString('- Cabeçalho:', $prompt);
    }

    public function test_layout_de_topicos_nao_pede_linha_de_apoio_inventada(): void
    {
        $cena = (new CreativeSlotCatalog)->padraoDe('benefits')['cena_padrao'];

        $this->assertStringNotContainsString('linha de apoio menor abaixo', $cena);
        $this->assertStringContainsString('sem nenhuma linha de texto extra abaixo dele', $cena);
    }
}
