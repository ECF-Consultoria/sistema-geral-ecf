<?php

namespace Tests\Feature\Phase143;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase143GruposCobrancaUiTest — travas de arquivo da tela onde o grupo de
 * cobrança é montado (`Admin/GruposCobranca.jsx`, Fase 143 Plano 04).
 *
 * O projeto não tem test runner de JS, então a trava é a mesma receita de
 * `Phase142FichaTabelaUiTest` e `Phase143TabelaGrupoUiTest`: ler o `.jsx`
 * como texto puro e afirmar presença/ausência de trechos-chave.
 *
 * ⚠️ **O que estas travas protegem é dinheiro.** Juntar grupos muda o que um
 * cliente paga — no caso que abriu a fase, de R$ 33.500 para R$ 21.000 por
 * mês. As três garantias que não podem sumir da tela numa refatoração:
 *
 * 1. **Nada é gravado sem a prévia na frente** — e a prévia é apagada quando
 *    a seleção ou o destino mudam, para ninguém confirmar um arranjo lendo o
 *    número de outro.
 * 2. **A prévia diz de onde vem a tabela** — é o que separa R$ 21.000 de
 *    R$ 12.000 no caso real.
 * 3. **Grupo de cobrança sem tabela própria é avisado ANTES de juntar**, com
 *    o caminho para cadastrar.
 */
class Phase143GruposCobrancaUiTest extends TestCase
{
    private const ARQUIVO = 'js/Pages/Admin/GruposCobranca.jsx';

    private const ARQUIVO_CONTRATOS = 'js/Pages/Admin/Contratos.jsx';

    /**
     * Os oito termos que o 143-04-PLAN bane como rótulo, mais os de
     * 139/142 que continuam valendo. O vocabulário do código
     * (hierarquia, estrutura de dados) não pode vazar para a tela: quem monta
     * o grupo fala de "grupo", "grupos que fazem parte dele" e "cobrança".
     */
    private const PALAVRAS_BANIDAS = [
        'raiz',
        'árvore',
        'nó',
        'parent',
        'precedência',
        'snapshot',
        'competência',
        'rollup',
        'reconsolidação',
        'âncora',
        'faixa piso',
    ];

    private function lerArquivo(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    /** Remove blocos de comentário e linhas `//` — só sobra o que vira tela. */
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

    // ─── (a) ⚠️ nada grava sem a prévia na frente ─────────────────────────

    #[Test]
    public function o_botao_de_confirmar_so_existe_depois_da_previa(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString(
            'Ver o que muda na cobrança',
            $conteudo,
            'A tela precisa de um passo explícito para pedir a prévia antes de gravar.'
        );
        $this->assertStringContainsString(
            '{previa && (',
            $conteudo,
            'O bloco de confirmação tem de estar condicionado à prévia carregada.'
        );
        $this->assertStringContainsString(
            'Confirmar e juntar os grupos',
            $conteudo,
            'O botão que grava é o último passo, dentro do bloco da prévia.'
        );
        $this->assertStringContainsString(
            'if (!previa) return;',
            $conteudo,
            'Guarda na função que grava: sem prévia calculada, nada é enviado.'
        );
        $this->assertStringContainsString(
            'if (!saida?.previa) return;',
            $conteudo,
            'Tirar um grupo de dentro de outro também muda cobrança — e também exige a prévia.'
        );
    }

    #[Test]
    public function mudar_a_selecao_ou_o_destino_apaga_a_previa(): void
    {
        $conteudo = $this->removerComentarios($this->lerArquivo(self::ARQUIVO));

        $this->assertMatchesRegularExpression(
            '/useEffect\(\s*\(\)\s*=>\s*\{\s*setPrevia\(null\);/',
            $conteudo,
            'Sem isto, alguém confirma um arranjo lendo o número calculado para outro — o erro mais caro que esta tela pode cometer.'
        );
        $this->assertMatchesRegularExpression(
            '/\}, \[selecionados, destinoId\]\);/',
            $conteudo,
            'A prévia precisa ser apagada quando a seleção OU o destino mudam.'
        );
    }

    // ─── (b) a prévia mostra hoje, depois e a diferença com sinal ─────────

    #[Test]
    public function a_previa_mostra_o_antes_o_depois_e_a_diferenca_com_sinal(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString('Como está sendo cobrado hoje', $conteudo);
        $this->assertStringContainsString('Como passaria a ser cobrado', $conteudo);
        $this->assertStringContainsString('Diferença na cobrança', $conteudo);
        $this->assertStringContainsString(
            "delta > 0 ? '+' : '−'",
            $conteudo,
            'A diferença precisa dizer para que LADO — sem sinal, R$ 12.500 pode ser lido como aumento.'
        );
        $this->assertStringContainsString(
            'cobranças separadas',
            $conteudo,
            'Quantas cobranças existem hoje e quantas passam a existir é metade da leitura.'
        );
        $this->assertStringContainsString(
            'text-3xl',
            $conteudo,
            'É decisão de dinheiro: a diferença tem de estar grande e legível.'
        );
    }

    #[Test]
    public function a_queda_de_cobranca_e_explicada_como_correcao_e_nao_como_erro(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString(
            'Cair é o resultado esperado',
            $conteudo,
            'Queda é o caso normal aqui — a tela não pode sugerir que é problema.'
        );
        $this->assertStringNotContainsString(
            'text-red',
            $conteudo,
            'Nada nesta tela pode pintar a redução de cobrança como erro.'
        );
        $this->assertStringNotContainsString(
            'variant="destructive"',
            $conteudo,
            'Nenhuma ação desta tela é destrutiva — juntar e desfazer são reversíveis e auditados.'
        );
    }

    // ─── (c) de onde vem a tabela ─────────────────────────────────────────

    #[Test]
    public function cada_linha_da_previa_diz_de_onde_vem_a_tabela(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString(
            'procedencia={linha.procedencia}',
            $conteudo,
            'Sem a procedência da tabela, a diferença entre R$ 21.000 e R$ 12.000 vira palpite.'
        );
        $this->assertStringContainsString('Tabela conferida pelo contrato assinado', $conteudo);
        $this->assertStringContainsString('Tabela cadastrada à mão no sistema', $conteudo);
        $this->assertStringContainsString('Tabela copiada do serviço contratado', $conteudo);
        $this->assertStringContainsString(
            'tabelaGrupoNome',
            $conteudo,
            'Quando a tabela é de um grupo, a tela tem de dizer de QUAL grupo.'
        );
    }

    // ─── (d) ⚠️ avisar antes de juntar sem tabela ─────────────────────────

    #[Test]
    public function avisa_e_oferece_o_cadastro_quando_o_grupo_de_cobranca_nao_tem_tabela(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString('tem_tabela_propria', $conteudo);
        $this->assertStringContainsString(
            'ainda não tem tabela de cobrança própria',
            $conteudo,
            'Juntar sem tabela no grupo de cobrança faz o valor cair mais do que deveria — a tela tem de dizer isso com todas as letras.'
        );
        $this->assertStringContainsString(
            'Cadastrar a tabela de',
            $conteudo,
            'Avisar sem oferecer o caminho do cadastro deixa a pessoa sem saída.'
        );
        $this->assertStringContainsString(
            'admin.contratos.tabela.grupo.show',
            $conteudo,
            'O caminho do cadastro é a página da tabela do grupo (Plano 143-03).'
        );
        $this->assertStringContainsString(
            'cienteSemTabela',
            $conteudo,
            'Sem tabela cadastrada, confirmar exige um reconhecimento explícito do aviso.'
        );
    }

    // ─── (e) criar um grupo de cobrança é o caminho primário ──────────────

    #[Test]
    public function a_tela_permite_criar_um_grupo_de_cobranca(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        $this->assertStringContainsString('Criar um grupo de cobrança', $conteudo);
        $this->assertStringContainsString('admin.contratos.grupos.criar', $conteudo);
        $this->assertStringContainsString(
            'cadastre a tabela de cobrança dele',
            $conteudo,
            'A ordem importa: criar, cadastrar a tabela e só então juntar.'
        );
    }

    // ─── (f) as rotas certas ──────────────────────────────────────────────

    #[Test]
    public function aponta_para_as_rotas_do_modulo_de_contratos_e_nunca_para_as_antigas(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        foreach ([
            'admin.contratos.grupos.hierarquia.previa',
            'admin.contratos.grupos.hierarquia.pendurar',
            'admin.contratos.grupos.hierarquia.despendurar',
            'admin.contratos.grupos.criar',
            'admin.contratos.tabela.grupo.show',
            'admin.contratos.index',
        ] as $rota) {
            $this->assertStringContainsString($rota, $conteudo, "A tela precisa apontar para `{$rota}`.");
        }

        $this->assertStringNotContainsString('admin.financeiro.faixas', $conteudo);
        $this->assertStringNotContainsString(
            'company-groups.store',
            $conteudo,
            'A criação passa pela rota do módulo de contratos — a antiga é role:admin e daria 403 em quem tem a permissão por setor.'
        );
    }

    #[Test]
    public function a_tela_de_contratos_leva_para_os_grupos_de_cobranca(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO_CONTRATOS);

        $this->assertStringContainsString(
            'admin.contratos.grupos.index',
            $conteudo,
            'Sem caminho de entrada, a tela nova não existe na prática.'
        );
        $this->assertStringContainsString('Grupos de cobrança', $conteudo);
    }

    // ─── (g) ⛔ nenhuma conta de faixa mora na tela ───────────────────────

    #[Test]
    public function a_tela_nao_reimplementa_a_matematica_da_cobranca(): void
    {
        $conteudo = $this->removerComentarios($this->lerArquivo(self::ARQUIVO));

        foreach (['limite_superior', 'valor_e_piso', 'classificar', 'faixas.find', 'faixas.filter'] as $trecho) {
            $this->assertStringNotContainsString(
                $trecho,
                $conteudo,
                "A tela não pode calcular faixa nem cobrança (`{$trecho}`) — todo número vem pronto do backend, da mesma máquina que o fechamento usa."
            );
        }
    }

    // ─── (h) copy sem jargão ──────────────────────────────────────────────

    #[Test]
    public function nenhuma_palavra_banida_aparece_como_texto_visivel(): void
    {
        $conteudoFiltrado = $this->removerComentarios($this->lerArquivo(self::ARQUIVO));

        foreach (self::PALAVRAS_BANIDAS as $palavra) {
            $this->assertPalavraAusenteComoTextoVisivel(
                $palavra,
                $conteudoFiltrado,
                "\"{$palavra}\" é jargão banido e não pode aparecer em Admin/GruposCobranca.jsx."
            );
        }
    }

    // ─── (i) escala do Tailwind ───────────────────────────────────────────

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivo(self::ARQUIVO);

        // A escala do Tailwind pula de 3.5 para 4 — px-4.5/gap-4.5/py-5.5 (e qualquer classe -N.5
        // fora de 0.5/1.5/2.5/3.5) não existem, o build passa e nenhum CSS é gerado, sem aviso.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:p|px|py|pt|pb|pl|pr|gap|m|mx|my|mt|mb|ml|mr)-(?!0\.5\b|1\.5\b|2\.5\b|3\.5\b)\d+\.5\b/',
            $conteudo,
            'Classe de espaçamento com decimal fora da escala real do Tailwind (ex.: px-4.5, gap-4.5, py-5.5) — não existe, não gera CSS e não avisa nada.'
        );
    }
}
