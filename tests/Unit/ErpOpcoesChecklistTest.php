<?php

namespace Tests\Unit;

use App\Models\MlbImplementacao;
use PHPUnit\Framework\TestCase;

/**
 * Trava a lista de ERP do checklist público (revisão do comercial em 02/09/2026).
 * O item 'erp' lê ERP_OPCOES direto do CHECKLIST, e o <select> do link do cliente
 * é nativo: opção removida sem migrar o dado gravado vira campo em branco na tela.
 */
class ErpOpcoesChecklistTest extends TestCase
{
    public function test_lista_de_erp_e_a_revisada_pelo_comercial(): void
    {
        $this->assertSame([
            'Bling',
            'Tiny',
            'Anymarket',
            'Tray',
            'LojaHub',
            'Shopping de Preços',
            'Em Contratação',
            'Outro',
        ], MlbImplementacao::ERP_OPCOES);
    }

    public function test_texto_antigo_tiny_erp_nao_volta_para_a_lista(): void
    {
        // Havia 10 fichas com 'Tiny ERP'; a migração as reescreveu para 'Tiny'.
        // Reintroduzir o texto antigo criaria duas opções para o mesmo ERP.
        $this->assertNotContains('Tiny ERP', MlbImplementacao::ERP_OPCOES);
    }

    public function test_outro_continua_disponivel_pois_abre_o_campo_livre(): void
    {
        $this->assertContains('Outro', MlbImplementacao::ERP_OPCOES);
    }

    public function test_item_erp_do_checklist_usa_a_lista(): void
    {
        $erp = collect(MlbImplementacao::CHECKLIST)->firstWhere('id', 'erp');

        $this->assertNotNull($erp);
        $this->assertSame('select', $erp['tipo']);
        $this->assertSame(MlbImplementacao::ERP_OPCOES, $erp['opcoes']);
    }
}
