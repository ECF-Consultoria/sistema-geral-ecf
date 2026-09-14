<?php

namespace Tests\Feature\Phase143;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase143TabelaGrupoUiTest — travas de arquivo da página própria da tabela de cobrança de um
 * GRUPO (`Admin/TabelaGrupo.jsx`, Fase 143 Plano 03, T2/T3).
 *
 * O projeto não tem test runner de JS, então a trava é a mesma receita de
 * `Phase142FichaTabelaUiTest`: ler o `.jsx` como texto puro e afirmar presença/ausência de
 * trechos-chave.
 *
 * ⚠️ O risco que esta página existe para cobrir é **mexer na mensalidade de dez clientes achando
 * que se está mexendo em dois** (143-CONTEXT, D-02). Por isso as travas abaixo cobrem, além da
 * máscara de dinheiro e da grade única: o bloco que conta e LISTA as empresas alcançadas, o aviso
 * de que um grupo faz parte de outro, e a copy sem jargão.
 */
class Phase143TabelaGrupoUiTest extends TestCase
{
    private const ARQUIVO_GRUPO = 'js/Pages/Admin/TabelaGrupo.jsx';

    private const ARQUIVO_EMPRESA = 'js/Pages/Admin/TabelaEmpresa.jsx';

    private const ARQUIVO_CAMPO_DINHEIRO = 'js/Components/ui/campo-dinheiro.jsx';

    private const ARQUIVO_GRADE = 'js/Components/Fechamento/TabelaProgressivaFaixas.jsx';

    /**
     * As oito palavras banidas herdadas de `139-CONTEXT.md`/`142-CONTEXT.md`, mais as TRÊS desta
     * fase: a árvore de grupos tem vocabulário próprio no código ("raiz", "árvore",
     * "precedência") que não pode vazar para a tela — quem cadastra a tabela fala de "grupo" e
     * "grupos que fazem parte dele", não de estrutura de dados.
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
        'raiz',
        'árvore',
        'precedência',
    ];

    private function lerArquivo(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    /** Remove blocos `/* ... *\/` (o que cobre `{/* ... *\/}` do JSX) e linhas `//`. */
    private function removerComentarios(string $conteudo): string
    {
        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);

        return preg_replace('/^[ \t]*\/\/.*$/m', '', $semBlocos);
    }

    private function assertPalavraAusenteComoTextoVisivel(string $palavra, string $conteudoFiltrado, string $mensagem): void
    {
        $padrao = '/(?<![\p{L}\p{N}_.])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

        $this->assertDoesNotMatchRegularExpression($padrao, $conteudoFiltrado, $mensagem);
    }

    // ─── (a) máscara de dinheiro, nunca type="number" cru ────────────────

    #[Test]
    public function usa_campo_dinheiro_nos_campos_de_valor(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        $this->assertStringContainsString("import { CampoDinheiro } from '@/Components/ui/campo-dinheiro';", $conteudo);
        $this->assertGreaterThanOrEqual(2, substr_count($conteudo, '<CampoDinheiro'));
    }

    #[Test]
    public function so_o_campo_ordem_usa_type_number(): void
    {
        $conteudo = $this->removerComentarios($this->lerArquivo(self::ARQUIVO_GRUPO));

        $this->assertSame(1, substr_count($conteudo, 'type="number"'), 'Só o campo "Ordem" pode usar type="number" — os campos de valor precisam estar em CampoDinheiro.');
    }

    // ─── (b) a conversão de borda e a grade vêm do compartilhado ─────────

    #[Test]
    public function reusa_a_conversao_de_borda_e_a_grade_compartilhadas(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        $this->assertStringContainsString("from '@/lib/faixasFaturamento';", $conteudo, 'A conversão do teto ",99" para valor redondo mora na lib — nunca reimplementada na página.');
        $this->assertStringContainsString("import TabelaProgressivaFaixas from '@/Components/Fechamento/TabelaProgressivaFaixas';", $conteudo);
        $this->assertStringNotContainsString('function TabelaProgressivaFaixas(', $conteudo, 'A página não pode definir uma segunda grade — a definição única mora em Components/Fechamento.');
        $this->assertStringNotContainsString('function tetoGravado(', $conteudo, 'A conversão de borda não pode ter uma segunda cópia aqui.');
        $this->assertStringNotContainsString('function indiceDeGravacao(', $conteudo, 'A regra de em qual linha o valor é gravado não pode ter uma segunda cópia aqui.');
    }

    // ─── (c) ⚠️ a página diz o TAMANHO da decisão ────────────────────────

    #[Test]
    public function mostra_quantas_e_quais_empresas_a_tabela_alcanca(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        $this->assertStringContainsString('Empresas que esta tabela vai cobrar', $conteudo, 'O bloco que conta as empresas alcançadas é a razão de ser desta página — quem cadastra precisa ver 10 empresas, não 2.');
        $this->assertStringContainsString('empresas.map(', $conteudo, 'As empresas precisam ser LISTADAS uma a uma, não só contadas numa frase.');
        $this->assertStringContainsString('subgrupos.map(', $conteudo, 'Os grupos que fazem parte deste precisam aparecer, com a contagem de cada um.');
        $this->assertStringContainsString('Grupos que fazem parte de', $conteudo);
    }

    #[Test]
    public function avisa_quando_o_grupo_faz_parte_de_outro_e_leva_para_la(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        $this->assertStringContainsString('Este grupo faz parte de', $conteudo, 'Grupo que está dentro de outro precisa avisar que a cobrança segue a tabela de lá.');
        $this->assertStringContainsString('Abrir a tabela de', $conteudo, 'O aviso precisa levar para a página do grupo que manda — senão a pessoa edita a tabela errada.');
    }

    #[Test]
    public function confirma_antes_de_salvar_dizendo_quantas_empresas_mudam(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        $this->assertStringContainsString('avisoAntesDeSalvar', $conteudo);
        $this->assertStringContainsString('Esta tabela passa a valer para', $conteudo, 'A confirmação precisa dizer quantas empresas mudam de mensalidade.');
    }

    // ─── (d) as rotas certas ─────────────────────────────────────────────

    #[Test]
    public function aponta_para_as_rotas_do_modulo_de_contratos_e_nunca_para_as_antigas(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO);

        foreach ([
            'admin.contratos.tabela.grupo.show',
            'admin.contratos.tabela.grupo.salvar',
            'admin.contratos.tabela.grupo.remover',
            'admin.contratos.tabela.show',
        ] as $rota) {
            $this->assertStringContainsString($rota, $conteudo, "A página precisa apontar para `{$rota}`.");
        }

        $this->assertStringNotContainsString('admin.financeiro.faixas', $conteudo, 'Nunca as rotas antigas do fechamento — elas existem só para rollback, sem UI apontando para lá.');
    }

    // ─── (e) T3 — o caminho de entrada existe ────────────────────────────

    #[Test]
    public function a_ficha_da_empresa_leva_para_a_pagina_do_grupo(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_EMPRESA);

        $this->assertStringContainsString('admin.contratos.tabela.grupo.show', $conteudo, 'Sem caminho de entrada a página nova não existe na prática.');
        $this->assertStringContainsString('Abrir a página do grupo', $conteudo);
    }

    // ─── (f) copy sem jargão ─────────────────────────────────────────────

    #[Test]
    public function nenhuma_palavra_banida_aparece_como_texto_visivel(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivo(self::ARQUIVO_GRUPO));

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $this->assertPalavraAusenteComoTextoVisivel(
                $palavra,
                $conteudoFiltrado,
                "\"{$palavra}\" é jargão banido e não pode aparecer como texto visível em Admin/TabelaGrupo.jsx."
            );
        }
    }

    // ─── (g) escala do Tailwind ──────────────────────────────────────────

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_GRUPO)
            .$this->lerArquivo(self::ARQUIVO_CAMPO_DINHEIRO)
            .$this->lerArquivo(self::ARQUIVO_GRADE);

        // A escala do Tailwind pula de 3.5 para 4 — px-4.5/gap-4.5/py-5.5 (e qualquer classe -N.5
        // fora de 0.5/1.5/2.5/3.5) não existem, o build passa e nenhum CSS é gerado, sem aviso.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:p|px|py|pt|pb|pl|pr|gap|m|mx|my|mt|mb|ml|mr)-(?!0\.5\b|1\.5\b|2\.5\b|3\.5\b)\d+\.5\b/',
            $conteudo,
            'Classe de espaçamento com decimal fora da escala real do Tailwind (ex.: px-4.5, gap-4.5, py-5.5) — não existe, não gera CSS e não avisa nada.'
        );
    }
}
