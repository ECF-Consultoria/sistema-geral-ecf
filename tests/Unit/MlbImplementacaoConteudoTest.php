<?php

namespace Tests\Unit;

use App\Models\MlbImplementacao;
use Tests\TestCase;

/**
 * Testes da trava anti-check-vazio (Onboarding /implementacao).
 *
 * MlbImplementacao::itemTemConteudo() decide se o cliente já preencheu o mínimo
 * para poder marcar um item como "feito". Itens de ação pura (acessar link, dar
 * acesso, declarar) NUNCA travam; itens de preenchimento (ERP, planilha de
 * produtos, precificação…) exigem conteúdo.
 *
 * @group onboarding
 */
class MlbImplementacaoConteudoTest extends TestCase
{
    // Sem RefreshDatabase — itemTemConteudo() é estática e opera só em memória.

    /**
     * Itens de ação pura (link, gmail, instruções, checkbox) e o select
     * (ERP/Integrador — sempre tem opção válida) sempre liberam o check, mesmo
     * com dado vazio — não há o que preencher.
     */
    public function test_itens_de_acao_pura_sempre_liberam(): void
    {
        foreach (['select', 'link_fixo', 'link_admin', 'gmail', 'instrucoes', 'instrucoes_link', 'checkbox', 'select_opcoes'] as $tipo) {
            $this->assertTrue(
                MlbImplementacao::itemTemConteudo($tipo, []),
                "Tipo de ação pura '{$tipo}' deveria liberar o check mesmo vazio"
            );
        }
    }

    // ─── select (ERP / Integrador) ──────────────────────────────────────────

    /**
     * "Em Contratação" é uma opção legítima do cliente — e é o valor padrão do
     * select, indistinguível de "não mexeu". Logo, o select NUNCA trava: qualquer
     * valor (inclusive "Em Contratação" ou vazio) libera o check.
     */
    public function test_select_sempre_libera_incluindo_em_contratacao(): void
    {
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Em Contratação']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Bling']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Outro', 'outro' => '']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', []));
    }

    // ─── texto (HUB) / link ─────────────────────────────────────────────────

    public function test_texto_exige_acesso(): void
    {
        $this->assertFalse(MlbImplementacao::itemTemConteudo('texto', ['acesso' => '   ']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('texto',  ['acesso' => 'hub.exemplo.com']));
    }

    public function test_link_exige_url(): void
    {
        $this->assertFalse(MlbImplementacao::itemTemConteudo('link', ['link' => '']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('link',  ['link' => 'https://exemplo.com']));
    }

    // ─── produtos / precificação ────────────────────────────────────────────

    public function test_produtos_exige_ao_menos_um_com_sku_ou_nome(): void
    {
        $this->assertFalse(MlbImplementacao::itemTemConteudo('produtos', ['produtos' => []]));
        $this->assertFalse(MlbImplementacao::itemTemConteudo('produtos', ['produtos' => [['sku' => '', 'produto' => '']]]));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('produtos',  ['produtos' => [['sku' => 'CAD-001', 'produto' => '']]]));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('produtos',  ['produtos' => [['sku' => '', 'produto' => 'Cadeira']]]));
    }

    public function test_precificacao_exige_ao_menos_um_custo(): void
    {
        $this->assertFalse(MlbImplementacao::itemTemConteudo('precificacao', ['produtos' => []]));
        $this->assertFalse(MlbImplementacao::itemTemConteudo('precificacao', ['produtos' => [['sku' => 'X', 'custo' => '']]]));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('precificacao',  ['produtos' => [['sku' => 'X', 'custo' => '49.90']]]));
    }

    // ─── tipoDoItem() ───────────────────────────────────────────────────────

    public function test_tipo_do_item_resolve_pelo_id(): void
    {
        $this->assertSame('select',       MlbImplementacao::tipoDoItem('erp'));
        $this->assertSame('produtos',     MlbImplementacao::tipoDoItem('planilha_produtos'));
        $this->assertSame('precificacao', MlbImplementacao::tipoDoItem('precificacao'));
        $this->assertNull(MlbImplementacao::tipoDoItem('id_inexistente'));
    }

    /**
     * Fio-terra do fluxo real: o item ERP com os dados padrão (valor "Em
     * Contratação") LIBERA o check — "Em Contratação" é resposta válida do cliente.
     */
    public function test_erp_padrao_libera_check(): void
    {
        $dados = MlbImplementacao::dadosPadrao();
        $tipo  = MlbImplementacao::tipoDoItem('erp');

        $this->assertTrue(MlbImplementacao::itemTemConteudo($tipo, $dados['itens']['erp']));
    }
}
