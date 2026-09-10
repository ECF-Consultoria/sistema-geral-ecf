<?php

namespace Tests\Feature\Phase142;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase142FechamentoSomenteLeituraTest — trava de que o fechamento MOSTRA a
 * tabela progressiva e a faixa de cada empresa, mas PAROU DE ESCREVER
 * (Fase 142 Plano 04, D-04).
 *
 * O usuário pediu, nestas palavras (`142-CONTEXT.md`):
 * "No fechamento deve continuar mostrando a tabela progressiva de cada
 * empresa e a faixa que a empresa está, só não deve ser possível cadastrar
 * ou editar as tabelas por ali."
 *
 * ⚠️ As duas metades importam igual. "Continuar mostrando" não é comentário
 * de rodapé — é a metade do pedido que uma correção apressada (apagar o
 * bloco inteiro) quebraria silenciosamente. Por isso esta suíte trava as
 * DUAS coisas: (a)/(d) que a escrita sumiu, (b)/(c)/(e) que a exibição
 * continua — inclusive a tabela própria da empresa, que ganhou grade de
 * verdade nesta mesma fase (D-01, dívida do 137-09).
 *
 * O projeto não tem test runner de JS, então a trava é a mesma receita já
 * usada em `Phase139FechamentoUiContratoTest`/`Phase142FichaTabelaUiTest`:
 * ler o `.jsx` como texto puro e afirmar presença/ausência de trechos-chave.
 */
class Phase142FechamentoSomenteLeituraTest extends TestCase
{
    private const ARQUIVO_TABELA_FAIXAS = 'js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx';

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    /**
     * As oito palavras banidas — as sete de `139-CONTEXT.md` mais
     * "presumida", acrescentada pela restrição própria do `142-CONTEXT.md`.
     * Mesma lista de `Phase142FichaTabelaUiTest`.
     */
    private const PALAVRAS_BANIDAS = [
        'snapshot',
        'reconsolidação',
        'rollup',
        'âncora',
        'competência',
        'origem',
        'faixa piso',
        'presumida',
    ];

    private function lerArquivo(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    /**
     * Mesma receita de `Phase139FechamentoUiContratoTest::removerComentarios()`
     * — remove blocos de comentário (`/* ... *\/`, o que também cobre
     * `{/* ... *\/}` do JSX) e linhas `//`, deixando só o que o React
     * efetivamente renderiza como marcação/string.
     */
    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    /**
     * Casamento por fronteira de palavra (case-insensitive, unicode), com o
     * `_` também tratado como caractere de palavra — não confunde "origem"
     * (jargão banido) com o miolo de `tabela_origem` (identificador
     * legítimo de código).
     */
    private function assertPalavraAusenteComoTextoVisivel(string $palavra, string $conteudoFiltrado, string $mensagem): void
    {
        $padrao = '/(?<![\p{L}\p{N}_])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

        $this->assertDoesNotMatchRegularExpression($padrao, $conteudoFiltrado, $mensagem);
    }

    // ─── (a) nenhuma escrita sai do fechamento ────────────────────────────

    #[Test]
    public function nao_contem_nenhum_vestigio_de_escrita(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS);

        foreach (['router.post', 'router.delete', 'FaixaFormDialog', 'useForm'] as $vestigio) {
            $this->assertStringNotContainsString($vestigio, $conteudo, "\"{$vestigio}\" não pode sobrar em TabelaFaixasSection.jsx — o fechamento parou de gravar tabela (D-04).");
        }

        $this->assertStringNotContainsString('admin.financeiro.faixas.', $conteudo, 'Nenhuma rota de gravação do fechamento pode ser alcançada a partir de TabelaFaixasSection.jsx.');
    }

    // ─── (b) existe caminho para quem quer cadastrar ──────────────────────

    #[Test]
    public function aponta_para_a_ficha_exclusiva_do_contrato(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('admin.contratos.tabela.show', $conteudo, 'Quem quer cadastrar precisa ser levado para a ficha exclusiva no contrato, com um clique.');
    }

    // ─── (c) a tabela própria da empresa mostra as faixas (D-01) ──────────

    #[Test]
    public function bloco_de_tabela_propria_usa_as_linhas_gravadas_na_grade_compartilhada(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('tabela_faixas', $conteudo, 'A dívida do 137-09 está paga: a tela precisa consumir as LINHAS da tabela própria, não só a origem.');
        $this->assertStringContainsString('<TabelaProgressivaFaixas ', $conteudo, 'O bloco de tabela própria precisa renderizar a grade compartilhada, não uma frase solta.');
    }

    // ─── (d) os rótulos de escrita sumiram ─────────────────────────────────

    #[Test]
    public function nenhum_rotulo_de_escrita_sobra_na_tela(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS);

        foreach ([
            'Substituir tabela própria',
            'Criar tabela própria',
            'Editar tabela do serviço',
            'Voltar a usar a tabela',
        ] as $rotulo) {
            $this->assertStringNotContainsString($rotulo, $conteudo, "O rótulo \"{$rotulo}\" era de um botão de escrita — não pode sobrar depois do corte do D-04.");
        }
    }

    // ─── (e) as frases de herança do grupo continuam presentes ───────────
    // Redundante com a Fase 138 de propósito — são as frases que mais vezes
    // quase sumiram em refactor.

    #[Test]
    public function frases_de_heranca_do_grupo_continuam_presentes(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('Este grupo está usando a tabela da empresa', $conteudo, 'A frase que nomeia a empresa dona da tabela herdada não pode sumir.');
        $this->assertStringContainsString('Quem manda é a empresa do grupo que mais faturou no mês', $conteudo, 'A frase de desempate do grupo não pode sumir.');
        $this->assertStringContainsString('tabela_herdada_de_nome', $conteudo);
    }

    // ─── (f) copy sem jargão ────────────────────────────────────────────────

    #[Test]
    public function nenhuma_das_oito_palavras_banidas_aparece_como_texto_visivel(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS));

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $this->assertPalavraAusenteComoTextoVisivel(
                $palavra,
                $conteudoFiltrado,
                "\"{$palavra}\" é jargão banido (139-CONTEXT.md + 142-CONTEXT.md) e não pode aparecer como texto visível em TabelaFaixasSection.jsx."
            );
        }
    }

    // ─── (g) escala do Tailwind ─────────────────────────────────────────────

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_TABELA_FAIXAS).$this->lerArquivo(self::ARQUIVO_FINANCEIRO);

        // A escala do Tailwind pula de 3.5 para 4 — px-4.5/gap-4.5/py-5.5 (e
        // qualquer classe -N.5 fora de 0.5/1.5/2.5/3.5) não existem, o build
        // passa e nenhum CSS é gerado, sem aviso algum.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:p|px|py|pt|pb|pl|pr|gap|m|mx|my|mt|mb|ml|mr)-(?!0\.5\b|1\.5\b|2\.5\b|3\.5\b)\d+\.5\b/',
            $conteudo,
            'Classe de espaçamento com decimal fora da escala real do Tailwind (ex.: px-4.5, gap-4.5, py-5.5) — não existe, não gera CSS e não avisa nada.'
        );
    }
}
