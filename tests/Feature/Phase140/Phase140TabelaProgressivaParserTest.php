<?php

namespace Tests\Feature\Phase140;

use App\Services\Contratos\TabelaProgressivaContratoParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 02 — Tarefa 2: TabelaProgressivaContratoParser.
 *
 * ⚠️ Fixtures com nome de empresa, CNPJ e razão social FICTÍCIOS (T-140-08 do threat_model) — os
 * FORMATOS e os valores das faixas vêm do CONTEXT (D-04, medidos contra contratos reais em
 * 2026-09-08): 7 faixas da notação nova batem exatamente com a tabela padrão semeada na Fase 137
 * (migration `2026_09_02_100003_seed_faixas_faturamento_iniciais`); as 4 faixas medidas da notação
 * antiga (100 mil→R$2.250, 100 mil→R$3.000, 500 mil→R$4.500, 1 milhão→R$6.000) e a última
 * (15 milhões→R$25.000) estão em `fixtures/tabela-notacao-antiga.txt` com os valores reais
 * medidos. ⚠️ As faixas INTERMEDIÁRIAS (2 a 13 milhões, ordens 5 a 11) NÃO foram medidas no
 * CONTEXT — são progressão sintética só para completar 12 linhas de teste, e os testes abaixo não
 * afirmam esses valores como corretos, só a FORMA do reconhecimento (contagem, ordem, faixa
 * aberta no fim).
 */
class Phase140TabelaProgressivaParserTest extends TestCase
{
    private function fixture(string $nome): string
    {
        return file_get_contents(__DIR__ . '/fixtures/' . $nome);
    }

    private function parser(): TabelaProgressivaContratoParser
    {
        return new TabelaProgressivaContratoParser();
    }

    // ═══ NOTAÇÃO NOVA ═══

    #[Test]
    public function le_a_notacao_nova_com_sete_faixas_batendo_com_a_tabela_padrao_da_fase_137(): void
    {
        config(['services.clicksign.cnpj_ecf' => '99.888.777/0001-11']);

        $resultado = $this->parser()->analisar($this->fixture('tabela-notacao-nova.txt'));

        $this->assertSame('tabela', $resultado['tipo']);
        $this->assertNull($resultado['valor_fixo']);
        $this->assertCount(7, $resultado['faixas']);

        $faixas = $resultado['faixas'];

        // Mesmos números da migration 2026_09_02_100003_seed_faixas_faturamento_iniciais.
        $this->assertSame(1, $faixas[0]['ordem']);
        $this->assertSame(500_000.0, $faixas[0]['limite_superior']);
        $this->assertSame(3_000.0, $faixas[0]['valor']);
        $this->assertFalse($faixas[0]['valor_e_piso']);

        $this->assertSame(1_000_000.0, $faixas[1]['limite_superior']);
        $this->assertSame(4_500.0, $faixas[1]['valor']);

        $this->assertSame(2_000_000.0, $faixas[2]['limite_superior']);
        $this->assertSame(6_000.0, $faixas[2]['valor']);

        $this->assertSame(3_000_000.0, $faixas[3]['limite_superior']);
        $this->assertSame(7_500.0, $faixas[3]['valor']);

        $this->assertSame(4_000_000.0, $faixas[4]['limite_superior']);
        $this->assertSame(9_000.0, $faixas[4]['valor']);

        $this->assertSame(5_000_000.0, $faixas[5]['limite_superior']);
        $this->assertSame(10_500.0, $faixas[5]['valor']);

        // Última faixa: aberta e o valor é PISO ("a partir de R$ 12.000,00").
        $this->assertNull($faixas[6]['limite_superior']);
        $this->assertSame(12_000.0, $faixas[6]['valor']);
        $this->assertTrue($faixas[6]['valor_e_piso']);

        // CNPJ da ECF (configurado) é ignorado; sobra o do cliente.
        $this->assertSame('12345678000190', $resultado['cnpj']);
        $this->assertSame('EMPRESA FICTICIA MODELO NOVO LTDA', $resultado['razao_social']);
    }

    // ═══ NOTAÇÃO ANTIGA — o caso que é o argumento da fase inteira ═══

    #[Test]
    public function le_a_notacao_antiga_com_doze_faixas_a_primeira_valendo_2250_nao_3000(): void
    {
        config(['services.clicksign.cnpj_ecf' => '99.888.777/0001-11']);

        $resultado = $this->parser()->analisar($this->fixture('tabela-notacao-antiga.txt'));

        $this->assertSame('tabela', $resultado['tipo']);
        $this->assertCount(12, $resultado['faixas']);

        $faixas = $resultado['faixas'];

        // D-04 / D-03: é EXATAMENTE aqui que o sistema hoje cobra R$ 3.000 de quem deveria
        // pagar R$ 2.250 — a tabela padrão não tem faixa "até 100 mil" com esse valor.
        $this->assertSame(100_000.0, $faixas[0]['limite_superior']);
        $this->assertSame(2_250.0, $faixas[0]['valor']);

        // ⚠️ Trava M=mil / MM=milhão: se o parser lesse "100M" como 100 milhões, este limite
        // viria 500_000_000.0 em vez de 500_000.0 — erro de mil vezes, em dinheiro de cobrança.
        $this->assertSame(500_000.0, $faixas[1]['limite_superior']);
        $this->assertSame(3_000.0, $faixas[1]['valor']);

        $this->assertSame(1_000_000.0, $faixas[2]['limite_superior']);
        $this->assertSame(4_500.0, $faixas[2]['valor']);

        // "+1MM" abre a faixa que vai até o próximo marco (+2MM, sintético) — aqui o "MM" de novo
        // precisa valer milhão, não mil.
        $this->assertSame(2_000_000.0, $faixas[3]['limite_superior']);
        $this->assertSame(6_000.0, $faixas[3]['valor']);

        // Última faixa (12ª): "+15MM" — aberta, valendo R$ 25.000 (medido; o padrão para em
        // R$ 12.000 — D-04).
        $this->assertNull($faixas[11]['limite_superior']);
        $this->assertSame(25_000.0, $faixas[11]['valor']);

        $this->assertSame('22333444000155', $resultado['cnpj']);
        $this->assertSame('EMPRESA FICTICIA MODELO ANTIGO LTDA', $resultado['razao_social']);
    }

    #[Test]
    public function le_a_notacao_antiga_por_extenso_com_os_mesmos_numeros_da_abreviada(): void
    {
        // Mesma tabela do CONTEXT (100 mil / 1 milhão / 15 milhões), só que por extenso em vez de
        // M/MM — precisa produzir os MESMOS números.
        $texto = <<<'TXT'
        Tabela de investimento:
        Até 100 mil de faturamento: R$ 2.250,00
        A partir de 100 mil de faturamento: R$ 3.000,00
        A partir de 1 milhão de faturamento: R$ 6.000,00
        Acima de 15 milhões de faturamento: R$ 25.000,00
        TXT;

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('tabela', $resultado['tipo']);
        $this->assertCount(4, $resultado['faixas']);

        $faixas = $resultado['faixas'];

        $this->assertSame(100_000.0, $faixas[0]['limite_superior']);
        $this->assertSame(2_250.0, $faixas[0]['valor']);

        // "100 mil" não pode virar 100 milhões, nem "1 milhão" virar 1 mil.
        $this->assertSame(1_000_000.0, $faixas[1]['limite_superior']);
        $this->assertSame(3_000.0, $faixas[1]['valor']);

        $this->assertSame(15_000_000.0, $faixas[2]['limite_superior']);
        $this->assertSame(6_000.0, $faixas[2]['valor']);

        $this->assertNull($faixas[3]['limite_superior']);
        $this->assertSame(25_000.0, $faixas[3]['valor']);
    }

    // ═══ VALOR FIXO — D-03: o erro que o sistema comete hoje ═══

    #[Test]
    public function valor_fixo_nao_vira_tabela_e_devolve_zero_faixas(): void
    {
        config(['services.clicksign.cnpj_ecf' => '99.888.777/0001-11']);

        $resultado = $this->parser()->analisar($this->fixture('valor-fixo.txt'));

        $this->assertSame('valor_fixo', $resultado['tipo']);
        $this->assertSame(3_000.0, $resultado['valor_fixo']);
        $this->assertSame([], $resultado['faixas']);

        $this->assertSame('33444555000166', $resultado['cnpj']);
        $this->assertSame('EMPRESA FICTICIA VALOR FIXO LTDA', $resultado['razao_social']);
    }

    #[Test]
    public function valor_fixo_tem_prioridade_mesmo_se_a_palavra_faturamento_aparecer_solta(): void
    {
        // "faturamento" sozinho (sem "faturamento mensal") não deve blindar contra a
        // classificação de valor fixo — só as 3 palavras específicas do plano descartam.
        $texto = 'O faturamento da contratante não altera o valor: parcelas mensais e iguais de R$ 3.000,00 (três mil reais).';

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('valor_fixo', $resultado['tipo']);
        $this->assertSame(3_000.0, $resultado['valor_fixo']);
    }

    // ═══ INDEFINIDO — nunca chutar faixa ═══

    #[Test]
    public function texto_sem_nenhum_padrao_reconhecido_vira_indefinido(): void
    {
        $resultado = $this->parser()->analisar('Este é um contrato de locação de sala comercial, sem relação com gestão de ADS.');

        $this->assertSame('indefinido', $resultado['tipo']);
        $this->assertSame([], $resultado['faixas']);
        $this->assertNull($resultado['valor_fixo']);
    }

    #[Test]
    public function texto_com_menos_de_tres_marcos_vira_indefinido_com_aviso_em_vez_de_tabela_pela_metade(): void
    {
        $texto = <<<'TXT'
        Investimento inicial:
        Até R$500.000,00 de faturamento: R$ 3.000,00
        A partir de R$ 500.000,00 de faturamento: R$ 4.500,00
        TXT;

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('indefinido', $resultado['tipo']);
        $this->assertSame([], $resultado['faixas']);
        $this->assertNotEmpty($resultado['avisos']);
    }

    // ═══ AVISOS — faixa fora de ordem ou valor decrescente não é descartada em silêncio ═══

    #[Test]
    public function faixa_com_valor_decrescente_entra_em_avisos_mas_nao_e_descartada(): void
    {
        $texto = <<<'TXT'
        Até R$100.000,00 de faturamento: R$ 1.000,00
        A partir de R$ 100.000,00 de faturamento: R$ 500,00
        A partir de R$ 200.000,00 de faturamento: R$ 2.000,00
        Acima de R$ 300.000,00 de faturamento: a partir de R$ 3.000,00
        TXT;

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('tabela', $resultado['tipo']);
        $this->assertCount(4, $resultado['faixas']);
        // Nenhuma faixa foi descartada por causa do valor decrescente.
        $this->assertSame(500.0, $resultado['faixas'][1]['valor']);
        $this->assertNotEmpty($resultado['avisos']);
        $this->assertStringContainsString('valor menor que a faixa anterior', implode(' ', $resultado['avisos']));
    }

    // ═══ CNPJ E RAZÃO SOCIAL ═══

    #[Test]
    public function texto_sem_cnpj_nenhum_devolve_null_sem_quebrar(): void
    {
        $resultado = $this->parser()->analisar('Contrato sem nenhuma referência a CNPJ neste texto de teste.');

        $this->assertNull($resultado['cnpj']);
        $this->assertNull($resultado['razao_social']);
    }

    #[Test]
    public function um_unico_cnpj_no_texto_gera_aviso_de_confirmacao(): void
    {
        $texto = 'CONTRATANTE: EMPRESA FICTICIA UNICA LTDA, inscrita no CNPJ sob o nº 44.555.666/0001-77.';

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('44555666000177', $resultado['cnpj']);
        $this->assertSame('EMPRESA FICTICIA UNICA LTDA', $resultado['razao_social']);
        $this->assertStringContainsString('só um CNPJ', implode(' ', $resultado['avisos']));
    }

    #[Test]
    public function razao_social_sem_sufixo_de_pessoa_juridica_por_perto_devolve_null_em_vez_de_palpite(): void
    {
        $texto = 'Contratante: João da Silva Reformas, CNPJ 55.666.777/0001-88, residente em endereço fictício.';

        $resultado = $this->parser()->analisar($texto);

        $this->assertSame('55666777000188', $resultado['cnpj']);
        $this->assertNull($resultado['razao_social']);
    }
}
