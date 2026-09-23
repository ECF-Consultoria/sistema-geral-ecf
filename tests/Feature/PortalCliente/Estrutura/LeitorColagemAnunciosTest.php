<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Services\Portal\Estrutura\LeitorColagemAnuncios;
use Tests\TestCase;

/**
 * O mapeamento de colunas da colagem — o ponto que mais vai precisar de ajuste
 * quando a primeira exportação REAL de anúncios do ML aparecer. Ao calibrar,
 * acrescente o cabeçalho real como caso aqui.
 *
 * Fontes dos nomes aceitos hoje: a aba "Anúncios" da planilha e o contrato de
 * `ml_acervo_itens` (`ml_item_id`, `title`, `listing_type_id`, `status`,
 * `catalog_listing`). NÃO os `Anunciar-*.xlsx`, que são template de
 * publicação em massa, não exportação.
 */
class LeitorColagemAnunciosTest extends TestCase
{
    private function ler(string $texto): array
    {
        return (new LeitorColagemAnuncios())->ler($texto);
    }

    public function test_cabecalho_da_planilha_e_reconhecido(): void
    {
        $r = $this->ler("SKU\tCÓDIGO MLB\tTÍTULO DO ANÚNCIO\tTIPO\tCATÁLOGO?\tSTATUS\n"
            ."MSA-MR\tMLB0000000004\tMesa\tPremium\tSim\tAtivo");

        $this->assertTrue($r['cabecalho']);
        $this->assertSame([], $r['erros']);
        $this->assertSame([
            'numero' => 2, 'sku' => 'MSA-MR', 'codigo_mlb' => 'MLB0000000004', 'titulo' => 'Mesa',
            'tipo' => 'premium', 'status' => 'ativo', 'catalogo' => true, 'kit_virtual' => false,
        ], $r['linhas'][0]);
    }

    /** Os nomes do contrato de `ml_acervo_itens`, em qualquer ordem, e o vocabulário do ML. */
    public function test_cabecalho_com_os_nomes_do_ml_e_vocabulario_gold(): void
    {
        $r = $this->ler("ml_item_id\tstatus\tlisting_type_id\ttitle\tcatalog_listing\tseller_sku\n"
            ."MLB123\tactive\tgold_special\tCadeira\tfalse\tCAD-01\n"
            ."MLB124\tpaused\tgold_pro\tCadeira P\ttrue\tCAD-01\n"
            ."MLB125\tclosed\tgold_pro\tVelho\tfalse\tCAD-01\n"
            ."MLB126\tunder_review\tgold_special\tRevisão\tfalse\t");

        $this->assertSame([], $r['erros']);
        $this->assertSame(
            [['classico', 'ativo', false, 'CAD-01'], ['premium', 'pausado', true, 'CAD-01'], ['premium', 'inativo', false, 'CAD-01'], ['classico', 'pausado', false, null]],
            array_map(fn ($l) => [$l['tipo'], $l['status'], $l['catalogo'], $l['sku']], $r['linhas'])
        );
    }

    /** Sem cabeçalho: vale a ordem da aba Anúncios (SKU, MLB, Título, Tipo, Catálogo, Status). */
    public function test_sem_cabecalho_vale_a_ordem_da_planilha(): void
    {
        $r = $this->ler("CAD-01\tMLB1\tCadeira\tClassico\tnão\tpausado");

        $this->assertFalse($r['cabecalho']);
        $this->assertSame(['CAD-01', 'MLB1', 'classico', 'pausado'], [
            $r['linhas'][0]['sku'], $r['linhas'][0]['codigo_mlb'], $r['linhas'][0]['tipo'], $r['linhas'][0]['status'],
        ]);
    }

    /**
     * O mínimo que a aula documenta: "Cole aqui o SKU e o tipo". Linha com só
     * SKU e tipo passa — MLB não é exigido na colagem.
     */
    public function test_so_sku_e_tipo_basta_como_diz_a_aula(): void
    {
        $r = $this->ler("SKU\tTIPO\nCAD-01\tClássico\nCAD-01\tPremium");

        $this->assertSame([], $r['erros']);
        $this->assertCount(2, $r['linhas']);
        $this->assertNull($r['linhas'][0]['codigo_mlb']);
        // Status em branco = ativo: na planilha, `"<>Inativo"` contava a célula vazia.
        $this->assertSame('ativo', $r['linhas'][0]['status']);
    }

    public function test_cada_recusa_diz_a_linha_e_o_motivo(): void
    {
        $r = $this->ler(implode("\n", [
            "SKU\tCÓDIGO MLB\tTIPO\tSTATUS",
            "A\tMLB1\tGold\tAtivo",          // tipo irreconhecível
            "B\tXYZ\tPremium\tAtivo",         // MLB irreconhecível
            "\t\tPremium\tAtivo",             // nem SKU nem MLB
            "C\tMLB2\t\tAtivo",               // sem tipo
            "D\tMLB3\tPremium\tEsgotado",     // status irreconhecível
            "E\tMLB4\tPremium\tAtivo",        // ok
            "F\tmlb4\tClássico\tAtivo",       // MLB repetido (normalizado)
            "G\t\tPremium\tAtivo",            // ok
            "g \t\tPremium\tAtivo",           // mesmo SKU+tipo sem MLB
        ]));

        $this->assertSame([2, 3, 4, 5, 6, 8, 10], array_column($r['erros'], 'numero'));
        $this->assertSame(['E', 'G'], array_column($r['linhas'], 'sku'));
        $this->assertStringContainsString('linha 7', $r['erros'][5]['motivo']);
    }

    public function test_cabecalho_sem_tipo_e_recusado_inteiro(): void
    {
        $r = $this->ler("SKU\tCÓDIGO MLB\nA\tMLB1");

        $this->assertNotNull($r['erro_geral']);
        $this->assertSame([], $r['linhas']);
    }

    /**
     * Uma linha de DADOS com um só valor que coincide com nome de coluna (um
     * anúncio intitulado "Status") não vira cabeçalho: são necessárias duas
     * colunas reconhecidas.
     */
    public function test_uma_palavra_conhecida_nao_transforma_dado_em_cabecalho(): void
    {
        $r = $this->ler("CAD-01\tMLB1\tStatus\tPremium\tNão\tAtivo");

        $this->assertFalse($r['cabecalho']);
        $this->assertCount(1, $r['linhas']);
    }

    public function test_nada_colado(): void
    {
        $this->assertSame('Nada foi colado.', $this->ler("  \n \n")['erro_geral']);
    }
}
