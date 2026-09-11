<?php

namespace Tests\Feature\Quick260911;

use Tests\TestCase;

/**
 * Quick 260911-kio (T1) — Mentoria deixa de parecer defeito.
 *
 * Empresa com `estado === 'valor_fixo'` é cobrada pelo valor combinado em
 * contrato: o faturamento dela não entra na conta que define a mensalidade
 * (regra D-02 da Fase 141). A tela, porém, não tinha ramo para esse estado
 * na coluna "Faturamento do mês" — caía no genérico e imprimia traço mudo.
 * BOX LISBOA faturou R$ 115.965 em agosto e a coluna aparecia vazia; quem
 * confere lia como erro.
 *
 * Projeto sem test runner de JS: mesma receita de
 * `Phase141FechamentoUiTest` — ler o `.jsx` como texto puro e afirmar
 * presença/ausência de trechos-chave, mais a trava de copy sem jargão.
 */
class ValorFixoNaColunaFaturamentoTest extends TestCase
{
    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    /**
     * Jargão banido na copy NOVA deste quick. A lista é mais dura que a das
     * Fases 139/141 de propósito: "estado", "valor fixo" e "tabela
     * progressiva" são vocabulário do código (e, no caso das duas últimas,
     * copy antiga que este quick não reescreve) — mas não podem entrar no
     * texto que este quick acrescenta. Por isso a asserção é sobre o CORPO
     * do componente novo, nunca sobre o arquivo inteiro.
     */
    private const PALAVRAS_BANIDAS = [
        'snapshot',
        'rollup',
        'competência',
        'procedência',
        'flag',
        'estado',
        'valor fixo',
        'tabela progressiva',
    ];

    private function lerArquivoJsx(): string
    {
        return file_get_contents(resource_path(self::ARQUIVO_FINANCEIRO));
    }

    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    public function test_coluna_faturamento_tem_ramo_proprio_para_valor_fixo(): void
    {
        $conteudo = $this->lerArquivoJsx();

        // O ramo precisa estar DENTRO de ColunaFaturamento, antes do
        // genérico — não basta o literal existir em qualquer lugar do
        // arquivo (ele já existia na coluna da faixa desde a Fase 141).
        $this->assertMatchesRegularExpression(
            '/function ColunaFaturamento\(\{ empresa \}\) \{.*?estado === \'valor_fixo\'.*?\n\}/s',
            $conteudo,
            'ColunaFaturamento precisa de um ramo próprio para valor_fixo — sem ele a coluna imprime traço mudo numa empresa que faturou.'
        );
    }

    public function test_ramo_de_valor_fixo_explica_que_o_faturamento_nao_define_a_mensalidade(): void
    {
        $conteudo = $this->lerArquivoJsx();

        $this->assertStringContainsString('function FaturamentoNaoDefineMensalidade', $conteudo);
        $this->assertStringContainsString('Não define a mensalidade', $conteudo);
        $this->assertStringContainsString('paga o valor combinado em contrato', $conteudo);
    }

    public function test_area_expandida_nao_chama_de_sem_faturamento_quem_paga_valor_combinado(): void
    {
        $conteudo = $this->lerArquivoJsx();

        // O passo "1 · Faturou no mês" testa valor_fixo ANTES de cair em
        // AusenciaFaturamentoBadge — dizer "Sem faturamento neste mês" para
        // quem faturou R$ 115.965 é simplesmente falso.
        $this->assertMatchesRegularExpression(
            "/1 · Faturou no mês.*?estado === 'valor_fixo' && empresa\.faturamento == null.*?AusenciaFaturamentoBadge/s",
            $conteudo,
            'O ramo de valor_fixo precisa vir ANTES do ramo genérico de ausência de faturamento na área expandida.'
        );
    }

    public function test_coluna_de_estado_ok_continua_imprimindo_o_valor_apurado(): void
    {
        $conteudo = $this->lerArquivoJsx();

        // Regressão: o caminho genérico (estado `ok`) não pode ter sido
        // trocado por nenhum dos ramos nomeados.
        $this->assertMatchesRegularExpression(
            '/function ColunaFaturamento\(\{ empresa \}\) \{.*?return \(\s*<span className="font-mono tabular-nums text-\[16px\] text-white\/75">\s*\{fmtBRL\(empresa\.faturamento\)\}/s',
            $conteudo,
            'O ramo genérico (estado ok) precisa continuar imprimindo fmtBRL(empresa.faturamento).'
        );
    }

    public function test_os_tres_estados_anteriores_continuam_nomeados_e_distintos(): void
    {
        $conteudo = $this->lerArquivoJsx();

        foreach (["estado === 'sem_faturamento'", "estado === 'sem_integracao'", 'Sem faturamento neste mês', 'sem dados'] as $trecho) {
            $this->assertStringContainsString(
                $trecho,
                $conteudo,
                "O ramo novo não pode ter engolido \"{$trecho}\" — os estados são distintos entre si (D-05)."
            );
        }
    }

    public function test_a_copy_nova_nao_traz_jargao(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivoJsx());

        // Só o corpo do componente novo — o resto do arquivo já tem as suas
        // próprias travas de copy (Fases 139/141) e usa "estado"/"valor
        // fixo" como vocabulário de código e como copy antiga.
        preg_match(
            '/function FaturamentoNaoDefineMensalidade\(.*?\n\}/s',
            $conteudoFiltrado,
            $m
        );

        $this->assertNotEmpty($m, 'O componente FaturamentoNaoDefineMensalidade precisa existir para a copy nova ser conferida.');
        $conteudoFiltrado = $m[0];

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $padrao = '/(?<![\p{L}\p{N}_])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

            $this->assertDoesNotMatchRegularExpression(
                $padrao,
                $conteudoFiltrado,
                "A palavra \"{$palavra}\" não pode aparecer como texto visível na tela de Fechamento."
            );
        }
    }
}
