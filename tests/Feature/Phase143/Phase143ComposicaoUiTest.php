<?php

namespace Tests\Feature\Phase143;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase143ComposicaoUiTest — travas de arquivo da composição do grupo na tela
 * do fechamento (`Admin/Financeiro.jsx`, Fase 143 Plano 05, Tarefa 2).
 *
 * O projeto não tem test runner de JS, então a trava é a mesma receita de
 * `Phase142FichaTabelaUiTest`, `Phase143TabelaGrupoUiTest` e
 * `Phase143GruposCobrancaUiTest`: ler o `.jsx` como texto puro e afirmar
 * presença/ausência de trechos-chave.
 *
 * As três garantias que não podem sumir numa refatoração:
 *
 * 1. **A linha diz quais grupos ela junta.** É o que separa conferir de
 *    adivinhar, na hora em que o usuário abre o fechamento para ver se a
 *    junção do MPozenato saiu certa.
 * 2. **Mês já fechado avisa que a divisão exibida é a de hoje.** O fechamento
 *    congelado não guarda quem estava em qual grupo naquela época; mostrar a
 *    divisão de hoje como se fosse a daquele mês é o único desfecho proibido.
 * 3. **A linha da listagem continua sendo `<div role="button">`** — foi
 *    convertida de propósito, porque o nome da empresa é âncora, e reverter
 *    para `<button>` reintroduz link dentro de botão.
 */
class Phase143ComposicaoUiTest extends TestCase
{
    private const ARQUIVO = 'js/Pages/Admin/Financeiro.jsx';

    /**
     * Vocabulário de código que não pode virar rótulo. Os termos herdados das
     * Fases 139/142/143 mais os três desta linha de trabalho (`raiz`,
     * `árvore`, `nó`) — quem lê a tela fala de "grupo" e "grupos que fazem
     * parte dele", nunca de estrutura de dados.
     */
    private const PALAVRAS_BANIDAS = [
        'raiz',
        'árvore',
        'nó',
        'parent',
        'subgrupo',
        'precedência',
        'snapshot',
        'rollup',
        'reconsolidação',
        'âncora',
        'faixa piso',
    ];

    private function lerArquivo(): string
    {
        return file_get_contents(resource_path(self::ARQUIVO));
    }

    /** Remove blocos de comentário e linhas `//` — só sobra o que vira tela. */
    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    // ─── (a) a linha diz o que está somando ───────────────────────────────

    #[Test]
    public function a_composicao_do_grupo_lista_os_grupos_que_a_cobranca_junta(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'Esta cobrança junta',
            $conteudo,
            '"MPozenato, 10 empresas" sem dizer que DRossi, Gran Belo e Lyam estão dentro é conferência às cegas.'
        );

        $this->assertStringContainsString(
            'empresa.subgrupos.map',
            $conteudo,
            'Os grupos precisam ser listados um a um, com nome — uma contagem sozinha não diz o que foi juntado.'
        );

        $this->assertStringContainsString(
            's.empresas === 1',
            $conteudo,
            'Cada grupo aparece com quantas empresas leva, no singular ou plural.'
        );
    }

    #[Test]
    public function a_listagem_avisa_quando_a_linha_junta_mais_de_um_grupo(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'Junta {empresa.subgrupos.length} grupos',
            $conteudo,
            'A pessoa vê a listagem antes de abrir a linha — é ali que a junção precisa aparecer primeiro.'
        );
    }

    #[Test]
    public function o_bloco_so_aparece_quando_existe_mais_de_um_grupo(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'empresa.subgrupos?.length > 0 &&',
            $conteudo,
            'Grupo sem nenhum outro grupo dentro recebe lista vazia do backend: a tela não pode desenhar bloco nenhum — é a regressão zero de quem nunca juntou nada.'
        );
    }

    // ─── (b) ⚠️ mês fechado não mente sobre a divisão ─────────────────────

    #[Test]
    public function mes_fechado_avisa_que_a_divisao_exibida_e_a_de_hoje(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'empresa.subgrupos_sao_de_hoje &&',
            $conteudo,
            'A prop existe justamente para a tela poder avisar — ignorá-la é mostrar a divisão de hoje como se fosse a daquele mês.'
        );

        $this->assertStringContainsString(
            'Esta é a divisão de hoje.',
            $conteudo,
            'O aviso tem de estar escrito, não subentendido.'
        );
    }

    // ─── (c) copy sem jargão ──────────────────────────────────────────────

    #[Test]
    public function a_copy_nova_nao_traz_jargao(): void
    {
        $conteudo = $this->removerComentarios($this->lerArquivo());

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $padrao = '/(?<![\p{L}\p{N}_.])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

            $this->assertDoesNotMatchRegularExpression(
                $padrao,
                $conteudo,
                "O termo \"{$palavra}\" é vocabulário de código — quem lê esta tela é o time Administrativo."
            );
        }
    }

    // ─── (d) a linha da listagem não pode regredir ────────────────────────

    #[Test]
    public function a_linha_da_listagem_continua_sendo_div_com_papel_de_botao(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'role="button"',
            $conteudo,
            'A linha foi convertida de <button> para <div role="button"> de propósito: o nome da empresa é um link, e link dentro de botão é HTML inválido.'
        );
    }
}
