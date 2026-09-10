<?php

namespace Tests\Feature\Phase142;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase142FichaTabelaUiTest — travas de arquivo da ficha exclusiva da tabela
 * de cobrança (`Admin/TabelaEmpresa.jsx`, Fase 142 Plano 03, D-01/D-02/D-03).
 *
 * O projeto não tem test runner de JS, então a trava é a mesma receita já
 * usada em `Phase139TabelaProgressivaFielTest`/`Phase139FechamentoUiContratoTest`:
 * ler o `.jsx` como texto puro e afirmar presença/ausência de trechos-chave.
 *
 * ⚠️ O risco real que esta fase existe para fechar é editar cobrança viva de
 * 169 empresas com um zero a mais invisível (142-CONTEXT.md). As travas
 * abaixo cobrem: máscara de dinheiro em vez de `type="number"` cru (D-02),
 * o formulário abrindo preenchido com o que está GRAVADO — nunca uma
 * reconstrução (dívida do 137-09), a grade em UMA definição só (não uma
 * quarta cópia), as quatro rotas certas (nunca as antigas de
 * `/financeiro/faixas`), o link cruzado para a caixa de entrada da Fase 140,
 * e copy sem jargão.
 */
class Phase142FichaTabelaUiTest extends TestCase
{
    private const ARQUIVO_FICHA = 'js/Pages/Admin/TabelaEmpresa.jsx';

    private const ARQUIVO_CAMPO_DINHEIRO = 'js/Components/ui/campo-dinheiro.jsx';

    private const ARQUIVO_DINHEIRO_LIB = 'js/lib/dinheiro.js';

    private const ARQUIVO_GRADE = 'js/Components/Fechamento/TabelaProgressivaFaixas.jsx';

    /**
     * As oito palavras banidas — as sete de `139-CONTEXT.md` mais "presumida",
     * acrescentada pela restrição própria do `142-CONTEXT.md` ("a palavra
     * 'presumida' não pode aparecer como rótulo na tela").
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
     * Mesma receita de `Phase139FechamentoUiContratoTest::assertPalavraAusenteComoTextoVisivel()`,
     * com UM ajuste: o lookbehind também exclui `.` como caractere anterior.
     * Sem isso, `tabela_aplicada.origem` — acesso de propriedade ao dado que
     * o próprio `142-02-PLAN.md` define como chave `origem` (sem sufixo,
     * diferente do `tabela_resumo.origem_aplicada` de `ContratoDetalhe.jsx`)
     * — seria falso positivo: é código, não copy, e "origem" ali nunca chega
     * a ser lido pela pessoa que usa a tela.
     */
    private function assertPalavraAusenteComoTextoVisivel(string $palavra, string $conteudoFiltrado, string $mensagem): void
    {
        $padrao = '/(?<![\p{L}\p{N}_.])'.preg_quote($palavra, '/').'(?![\p{L}\p{N}_])/ui';

        $this->assertDoesNotMatchRegularExpression($padrao, $conteudoFiltrado, $mensagem);
    }

    // ─── (a) máscara de dinheiro, nunca type="number" cru ────────────────

    #[Test]
    public function usa_campo_dinheiro_nos_campos_de_valor(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringContainsString("import { CampoDinheiro } from '@/Components/ui/campo-dinheiro';", $conteudo);

        $usos = substr_count($conteudo, '<CampoDinheiro');
        $this->assertGreaterThanOrEqual(2, $usos, 'A ficha precisa usar CampoDinheiro nos dois campos de valor por linha ("Faturamento até" e "Valor da mensalidade").');
    }

    #[Test]
    public function nenhum_campo_de_dinheiro_usa_type_number_cru(): void
    {
        // O componente CampoDinheiro em si nunca pode declarar type="number"
        // — é exatamente o campo cru que D-02 manda tirar.
        $conteudoCampo = $this->lerArquivo(self::ARQUIVO_CAMPO_DINHEIRO);
        $this->assertStringNotContainsString('type="number"', $conteudoCampo, 'CampoDinheiro não pode renderizar um input type="number" cru — a máscara do imask é quem formata e desmascara.');

        // Na ficha, o único type="number" legítimo é o campo "Ordem" (não é
        // dinheiro) — qualquer outro seria um campo de valor escapando da
        // máscara.
        $conteudoFicha = $this->removerComentarios($this->lerArquivo(self::ARQUIVO_FICHA));
        $ocorrencias = substr_count($conteudoFicha, 'type="number"');
        $this->assertSame(1, $ocorrencias, 'Só o campo "Ordem" pode usar type="number" — os campos de valor precisam estar em CampoDinheiro.');
    }

    #[Test]
    public function campo_dinheiro_devolve_numero_nunca_string_mascarada(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_CAMPO_DINHEIRO);

        // A trava central do risco de dado: quem desmascara é o imask
        // (`typedValue`), nunca um `parseFloat` da string exibida no campo.
        // O CÓDIGO (fora de comentário) é o que importa — o docblock da
        // classe CITA "parseFloat" de propósito, para explicar por que não
        // fazer isso; filtrar comentário é o que distingue "proibido no
        // código" de "mencionado na explicação".
        $this->assertStringContainsString('mask.typedValue', $conteudo, 'CampoDinheiro precisa devolver o valor TIPADO pelo imask — nunca fazer parseFloat da string mascarada.');

        $conteudoSemComentarios = $this->removerComentarios($conteudo);
        $this->assertStringNotContainsString('parseFloat', $conteudoSemComentarios, 'parseFloat de uma string mascarada é o caminho onde o separador de milhar vira parte do número — proibido no CÓDIGO (fora do docblock que explica a proibição).');
    }

    // ─── (b) formulário abre preenchido com o que está gravado ───────────

    #[Test]
    public function formulario_usa_tabela_empresa_como_estado_inicial(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringContainsString('tabela_empresa', $conteudo, 'O estado inicial do formulário precisa vir de `tabela_empresa` — as linhas GRAVADAS, nunca uma reconstrução.');
        $this->assertStringContainsString('linhasIniciais={tabela_empresa}', $conteudo, 'O bloco da empresa precisa alimentar o formulário com `tabela_empresa` diretamente.');
    }

    #[Test]
    public function nao_contem_a_frase_do_aviso_antigo_da_divida_137_09(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringNotContainsString('Os valores atuais não são carregados aqui', $conteudo, 'A dívida do 137-09 está paga — o backend manda `tabela_empresa` agora, esse aviso não pode voltar.');
    }

    #[Test]
    public function tabela_do_servico_so_entra_por_escolha_explicita_sem_salvar(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringContainsString('modelos_de_partida', $conteudo, 'O catálogo de modelos de partida precisa chegar na tela.');
        $this->assertStringContainsString('Começar a partir da tabela de', $conteudo, 'O botão de partida precisa nomear a escolha explícita — nunca preencher sozinho.');
        $this->assertStringContainsString('ponto de partida', $conteudo, 'A tela precisa avisar que aquilo é só ponto de partida, a conferir contra o contrato antes de salvar.');
    }

    // ─── (c) uma definição só da grade ────────────────────────────────────

    #[Test]
    public function importa_a_grade_compartilhada_e_nao_define_uma_segunda(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringContainsString("import TabelaProgressivaFaixas from '@/Components/Fechamento/TabelaProgressivaFaixas';", $conteudo);
        $this->assertStringNotContainsString('function TabelaProgressivaFaixas(', $conteudo, 'A ficha não pode definir uma segunda TabelaProgressivaFaixas — a definição única mora em Components/Fechamento.');
    }

    // ─── (d) copy sem jargão ───────────────────────────────────────────────

    #[Test]
    public function nenhuma_das_oito_palavras_banidas_aparece_como_texto_visivel(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivo(self::ARQUIVO_FICHA));

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $this->assertPalavraAusenteComoTextoVisivel(
                $palavra,
                $conteudoFiltrado,
                "\"{$palavra}\" é jargão banido (139-CONTEXT.md + 142-CONTEXT.md) e não pode aparecer como texto visível em Admin/TabelaEmpresa.jsx."
            );
        }
    }

    // ─── (e) as quatro rotas certas, nunca as antigas ─────────────────────

    #[Test]
    public function aponta_para_as_quatro_rotas_da_ficha_e_nunca_para_as_antigas(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        foreach ([
            'admin.contratos.tabela.salvar',
            'admin.contratos.tabela.remover',
            'admin.contratos.tabela.grupo.salvar',
            'admin.contratos.tabela.grupo.remover',
        ] as $rota) {
            $this->assertStringContainsString($rota, $conteudo, "A ficha precisa apontar para `{$rota}`.");
        }

        $this->assertStringNotContainsString('admin.financeiro.faixas', $conteudo, 'A ficha nunca pode apontar para as rotas antigas do fechamento — elas continuam existindo só para rollback, sem UI apontando para lá.');
    }

    // ─── (f) link cruzado para a caixa de entrada da Fase 140 ─────────────

    #[Test]
    public function tem_o_link_cruzado_para_a_caixa_de_entrada_com_a_copy_de_palpite(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA);

        $this->assertStringContainsString('admin.contratos.tabelas.index', $conteudo, 'A ficha precisa linkar para a caixa de entrada da Fase 140.');
        $this->assertStringContainsString('parece ser desta empresa', $conteudo, 'A copy precisa dizer "parece ser desta empresa" — o vínculo da leitura automática é palpite (D-05 da Fase 140), nunca confirmado pela ficha.');
        $this->assertStringNotContainsString('é desta empresa', $conteudo, 'Nunca afirmar que É desta empresa — só que parece ser.');
    }

    // ─── (g) escala do Tailwind — só os arquivos novos deste plano ────────

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_FICHA)
            .$this->lerArquivo(self::ARQUIVO_CAMPO_DINHEIRO)
            .$this->lerArquivo(self::ARQUIVO_GRADE)
            .$this->lerArquivo(self::ARQUIVO_DINHEIRO_LIB);

        // A escala do Tailwind pula de 3.5 para 4 — px-4.5/gap-4.5/py-5.5 (e
        // qualquer classe -N.5 fora de 0.5/1.5/2.5/3.5) não existem, o build
        // passa e nenhum CSS é gerado, sem aviso algum. Já mordeu três
        // executores nesta linha de trabalho (aviso do prompt de execução).
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:p|px|py|pt|pb|pl|pr|gap|m|mx|my|mt|mb|ml|mr)-(?!0\.5\b|1\.5\b|2\.5\b|3\.5\b)\d+\.5\b/',
            $conteudo,
            'Classe de espaçamento com decimal fora da escala real do Tailwind (ex.: px-4.5, gap-4.5, py-5.5) — não existe, não gera CSS e não avisa nada.'
        );
    }
}
