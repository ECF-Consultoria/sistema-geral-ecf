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
     * Itens de ação pura (link, gmail, instruções, checkbox) sempre liberam o
     * check, mesmo com dado vazio — não há o que preencher.
     */
    public function test_itens_de_acao_pura_sempre_liberam(): void
    {
        // 'observacao' (Observações sobre publicação) entra aqui por ser OPCIONAL: escrever
        // marca o item sozinho, mas quem não tem observação precisa poder concluir na mão.
        foreach (['link_fixo', 'link_admin', 'gmail', 'instrucoes', 'instrucoes_link', 'checkbox', 'select_opcoes', 'observacao'] as $tipo) {
            $this->assertTrue(
                MlbImplementacao::itemTemConteudo($tipo, []),
                "Tipo de ação pura '{$tipo}' deveria liberar o check mesmo vazio"
            );
        }
    }

    // ─── select (ERP / Integrador) ──────────────────────────────────────────

    /**
     * O select nasce em '---' (sentinela "não escolhido") → TRAVADO. Escolher
     * qualquer opção real — inclusive "Em Contratação" ou "Outro" — libera o check.
     */
    public function test_select_sentinela_trava_e_opcao_real_libera(): void
    {
        // '---' (default) e vazio = não escolheu → travado
        $this->assertFalse(MlbImplementacao::itemTemConteudo('select', ['valor' => '---']));
        $this->assertFalse(MlbImplementacao::itemTemConteudo('select', ['valor' => '']));
        $this->assertFalse(MlbImplementacao::itemTemConteudo('select', []));

        // qualquer opção real libera
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Em Contratação']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Bling']));
        $this->assertTrue(MlbImplementacao::itemTemConteudo('select', ['valor' => 'Outro']));
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
     * Fio-terra do fluxo real: o item ERP com os dados padrão (valor '---')
     * NÃO libera o check — cliente precisa escolher um sistema (ou "Em Contratação").
     */
    public function test_erp_padrao_nao_libera_check(): void
    {
        $dados = MlbImplementacao::dadosPadrao();
        $tipo  = MlbImplementacao::tipoDoItem('erp');

        $this->assertSame('---', $dados['itens']['erp']['valor'], 'ERP nasce em --- (não escolhido)');
        $this->assertFalse(MlbImplementacao::itemTemConteudo($tipo, $dados['itens']['erp']));
    }
}
