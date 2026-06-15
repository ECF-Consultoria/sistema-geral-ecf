<?php

namespace Tests\Unit;

use App\Models\MlbImplementacao;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Testes de regressão unitária para MlbImplementacao::progresso() e infoPrazo().
 *
 * Valida que progresso()['pct'] retorna int (não float) quando o checklist
 * está 100% concluído — garantindo que a comparação estrita === 100 no
 * controller funcione corretamente (bug ONB-11).
 *
 * Valida também o cálculo de prazo de 7 dias (ONB-09): fora_do_prazo,
 * dias_restantes, fallback created_at e empresa concluída tardia.
 *
 * @group phase35
 */
class MlbImplementacaoProgressoTest extends TestCase
{
    // Sem RefreshDatabase — não precisa de banco; progresso() e infoPrazo() operam só em memória.

    /**
     * Empresa com todos os 15 itens marcados como feito=true:
     * progresso()['pct'] deve ser int 100 (não float 100.0).
     */
    public function test_pct_e_int_100_quando_todos_itens_feitos(): void
    {
        $impl = new MlbImplementacao();

        // Carrega os 15 itens do padrão e marca todos como feitos
        $dados = MlbImplementacao::dadosPadrao();
        foreach ($dados['itens'] as $id => &$item) {
            $item['feito'] = true;
        }
        unset($item);

        $impl->dados = $dados;

        $progresso = $impl->progresso();
        $pct = $progresso['pct'];

        // Deve ser exatamente int 100 — não float 100.0
        $this->assertIsInt($pct, 'progresso()[\'pct\'] deve ser int, não float');
        $this->assertSame(100, $pct, 'progresso()[\'pct\'] deve ser exatamente 100 (int)');
    }

    /**
     * A comparação estrita $pct === 100 (usada no controller indicadores())
     * deve retornar true quando todos os itens estão feitos.
     */
    public function test_comparacao_estrita_pct_igual_100_retorna_true(): void
    {
        $impl = new MlbImplementacao();

        $dados = MlbImplementacao::dadosPadrao();
        foreach ($dados['itens'] as $id => &$item) {
            $item['feito'] = true;
        }
        unset($item);

        $impl->dados = $dados;

        $pct = $impl->progresso()['pct'];

        // Espelha exatamente o if ($pct === 100) do MlbImplementacaoController::indicadores()
        $this->assertTrue($pct === 100, 'A comparação estrita $pct === 100 deve ser true (necessário para status concluida)');
    }

    /**
     * Empresa sem dados (null) ou com dados sem itens:
     * progresso()['pct'] deve ser int 0 (não float).
     * Verifica que o branch `: 0` do operador ternário já estava correto.
     */
    public function test_pct_e_int_0_quando_dados_nulos(): void
    {
        $impl = new MlbImplementacao();
        $impl->dados = null;

        $progresso = $impl->progresso();
        $pct = $progresso['pct'];

        $this->assertIsInt($pct, 'progresso()[\'pct\'] deve ser int quando dados são null');
        $this->assertSame(0, $pct, 'progresso()[\'pct\'] deve ser 0 quando não há itens');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ONB-09 — Testes de prazo de 7 dias (infoPrazo())
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Teste A: empresa NÃO concluída com data_solicitacao há 8 dias.
     * Deve retornar fora_do_prazo=true e dias_restantes negativo.
     */
    public function test_empresa_nao_concluida_apos_8_dias_esta_fora_do_prazo(): void
    {
        $impl = new MlbImplementacao();
        $impl->dados           = MlbImplementacao::dadosPadrao(); // 0% — nenhum item feito
        $impl->data_solicitacao = Carbon::now()->subDays(8)->startOfDay();
        $impl->created_at      = Carbon::now()->subDays(8)->startOfDay();

        $prazo = $impl->infoPrazo();

        $this->assertTrue($prazo['fora_do_prazo'], 'Empresa não concluída com 8 dias deve estar fora do prazo');
        $this->assertLessThan(0, $prazo['dias_restantes'], 'dias_restantes deve ser negativo quando vencido');
        $this->assertFalse($prazo['concluido'], 'Empresa sem itens feitos não está concluída');
    }

    /**
     * Teste B: empresa NÃO concluída com data_solicitacao há 3 dias.
     * Deve retornar fora_do_prazo=false e dias_restantes positivo (ex.: 4).
     */
    public function test_empresa_nao_concluida_dentro_do_prazo_nao_esta_fora(): void
    {
        $impl = new MlbImplementacao();
        $impl->dados           = MlbImplementacao::dadosPadrao(); // 0% — nenhum item feito
        $impl->data_solicitacao = Carbon::now()->subDays(3)->startOfDay();
        $impl->created_at      = Carbon::now()->subDays(3)->startOfDay();

        $prazo = $impl->infoPrazo();

        $this->assertFalse($prazo['fora_do_prazo'], 'Empresa não concluída com apenas 3 dias não deve estar fora do prazo');
        $this->assertGreaterThan(0, $prazo['dias_restantes'], 'dias_restantes deve ser positivo quando dentro do prazo');
        $this->assertFalse($prazo['concluido'], 'Empresa sem itens feitos não está concluída');
    }

    /**
     * Teste C: empresa CONCLUÍDA com data_solicitacao há 30 dias.
     * Concluído tardio NÃO deve aparecer como fora do prazo — regra de negócio central.
     */
    public function test_empresa_concluida_tardiamente_nao_esta_fora_do_prazo(): void
    {
        $impl = new MlbImplementacao();

        // Marca todos os 15 itens como feitos (100%)
        $dados = MlbImplementacao::dadosPadrao();
        foreach ($dados['itens'] as $id => &$item) {
            $item['feito'] = true;
        }
        unset($item);

        $impl->dados           = $dados;
        $impl->data_solicitacao = Carbon::now()->subDays(30)->startOfDay();
        $impl->created_at      = Carbon::now()->subDays(30)->startOfDay();

        $prazo = $impl->infoPrazo();

        $this->assertTrue($prazo['concluido'], 'Empresa com 100% deve estar marcada como concluída');
        $this->assertFalse($prazo['fora_do_prazo'], 'Empresa concluída nunca deve estar fora do prazo, mesmo passados 30 dias');
    }

    /**
     * Teste D (fallback): empresa com data_solicitacao=null usa created_at como início do prazo.
     * Não deve lançar erro de null e deve calcular corretamente.
     */
    public function test_fallback_para_created_at_quando_data_solicitacao_nula(): void
    {
        $impl = new MlbImplementacao();
        $impl->dados           = MlbImplementacao::dadosPadrao(); // 0% — nenhum item feito
        $impl->data_solicitacao = null; // campo nulo — deve usar created_at
        $impl->created_at      = Carbon::now()->subDays(10)->startOfDay();

        $prazo = $impl->infoPrazo();

        // Não deve lançar exceção; deve usar created_at (10 dias atrás → fora do prazo)
        $this->assertArrayHasKey('fora_do_prazo', $prazo, 'infoPrazo() deve retornar chave fora_do_prazo');
        $this->assertArrayHasKey('dias_restantes', $prazo, 'infoPrazo() deve retornar chave dias_restantes');
        $this->assertArrayHasKey('dias_decorridos', $prazo, 'infoPrazo() deve retornar chave dias_decorridos');
        $this->assertArrayHasKey('data_inicio', $prazo, 'infoPrazo() deve retornar chave data_inicio');
        $this->assertTrue($prazo['fora_do_prazo'], 'Empresa com created_at há 10 dias e não concluída deve estar fora do prazo');
    }
}
