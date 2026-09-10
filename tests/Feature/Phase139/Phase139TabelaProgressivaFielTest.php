<?php

namespace Tests\Feature\Phase139;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Quick task 260904-jpn — trava de que a tabela progressiva do accordion de
 * Fechamento (área expandida) é fiel a `design_handoff_fechamento/Fechamento.dc.html`
 * e deixou de ser três cópias divergentes.
 *
 * Por que existe: o usuário conferiu a tela em produção e relatou "fontes
 * pequenas" e "tabela progressiva não está igual" à referência. A causa raiz
 * era estrutural — `TabelaFaixasSection.jsx` tinha duas cópias de `<table>`
 * (bloco do grupo e bloco do serviço) com metade da densidade da referência,
 * mais o label do form de cadastro contando como um terceiro "Faturamento
 * até" no grep original.
 *
 * O projeto não tem test runner de JS, então a trava segue a mesma receita
 * de `Phase139FechamentoUiContratoTest`: ler o `.jsx` como texto puro.
 *
 * ⚠️ RETARGET (Fase 142 Plano 03): a subcomponente `TabelaProgressivaFaixas`
 * saiu de dentro de `TabelaFaixasSection.jsx` e virou componente
 * compartilhado em `Components/Fechamento/TabelaProgressivaFaixas.jsx`, usado
 * agora também pela ficha nova da empresa (`Pages/Admin/TabelaEmpresa.jsx`).
 * As travas abaixo foram reapontadas para o endereço novo e FORTALECIDAS —
 * o risco que este teste sempre perseguiu foi GRADE DUPLICADA, não o nome de
 * um arquivo: "uma definição no arquivo" virou "uma definição em todo o
 * projeto", e "duas ocorrências no arquivo" virou "duas ocorrências no
 * projeto". Mais forte, não mais fraco.
 *
 * ⚠️ Decisão do usuário já tomada, não reaberta por este teste: a fonte
 * continua a do projeto (font-mono do Tailwind) — JetBrains Mono foi
 * oferecida e recusada.
 */
class Phase139TabelaProgressivaFielTest extends TestCase
{
    private const ARQUIVO_TABELA_FAIXAS = 'js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx';

    private const ARQUIVO_GRADE = 'js/Components/Fechamento/TabelaProgressivaFaixas.jsx';

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    private function lerArquivoJsx(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    /**
     * Varre todo `resources/js` (recursivo) e devolve os arquivos `.jsx`
     * como um único texto concatenado — usado pelas travas que precisam
     * medir "no projeto inteiro", não "num arquivo só".
     */
    private function lerTodosOsJsxDoProjeto(): string
    {
        $conteudo = '';
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $arquivo) {
            if ($arquivo->getExtension() === 'jsx') {
                $conteudo .= file_get_contents($arquivo->getPathname())."\n";
            }
        }

        return $conteudo;
    }

    // ─── Uma subcomponente, não três (nem quatro) cópias ─────────────────

    #[Test]
    public function existe_exatamente_uma_definicao_da_subcomponente_de_tabela_progressiva(): void
    {
        $conteudo = $this->lerTodosOsJsxDoProjeto();

        $ocorrencias = substr_count($conteudo, 'function TabelaProgressivaFaixas(');

        $this->assertSame(1, $ocorrencias, 'A tabela progressiva precisa existir como UMA subcomponente em todo o projeto — três cópias já divergiram entre si antes da correção 260904-jpn, e uma quarta cópia dentro da ficha nova (Fase 142) repetiria o erro.');
    }

    #[Test]
    public function a_subcomponente_e_reaproveitada_nos_dois_blocos_grupo_e_servico(): void
    {
        $conteudo = $this->lerTodosOsJsxDoProjeto();

        $usos = substr_count($conteudo, '<TabelaProgressivaFaixas ');

        $this->assertGreaterThanOrEqual(2, $usos, 'A subcomponente precisa ser usada no bloco de grupo (Fase 138) e no bloco de serviço (Fase 137), no projeto inteiro — senão a extração não eliminou a duplicação.');
    }

    #[Test]
    public function cabecalho_da_grade_existe_uma_vez_so_no_projeto(): void
    {
        $conteudo = $this->lerTodosOsJsxDoProjeto();

        // O risco de verdade é GRADE DUPLICADA, não a palavra "Faturamento
        // até" (que é label legítimo de formulário em mais de um lugar) —
        // por isso a asserção é sobre o CABEÇALHO inteiro da grade, não uma
        // palavra solta. `\s*` tolera a indentação, não o conteúdo.
        $ocorrencias = preg_match_all(
            '/<span>Faixa<\/span>\s*<span>Faturamento até<\/span>\s*<span className="text-right">Mensalidade<\/span>/',
            $conteudo
        );

        $this->assertSame(1, $ocorrencias, 'O cabeçalho da grade da tabela progressiva precisa existir uma vez só em todo o projeto — mais de uma ocorrência é sinal de grade duplicada.');
    }

    #[Test]
    public function nao_sobra_nenhum_table_html_no_arquivo(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringNotContainsString('<table', $conteudo, 'A referência usa grid, não `<table>` de larguras automáticas — sobrar um `<table>` é sinal de cópia não migrada.');
    }

    // ─── Densidade da referência (design_handoff_fechamento/Fechamento.dc.html) ──
    // Fase 142 Plano 03 — a grade mudou de endereço; a densidade é medida no
    // arquivo novo, onde o markup mora agora.

    #[Test]
    public function grade_usa_as_tres_colunas_da_referencia_com_gap_de_16px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_GRADE);

        $this->assertStringContainsString('grid-cols-[80px_1fr_160px]', $conteudo, 'A referência usa grid-template-columns: 80px 1fr 160px.');
        $this->assertStringContainsString('gap-4', $conteudo, 'Gap de 16px entre colunas (gap-4 do Tailwind já bate com a escala real, sem precisar de valor arbitrário).');
    }

    #[Test]
    public function linhas_tem_padding_12px_18px_e_texto_13px(): void
    {
        $conteudoGrade = $this->lerArquivoJsx(self::ARQUIVO_GRADE);

        $this->assertStringContainsString('px-[18px] py-3', $conteudoGrade, 'Padding das linhas precisa ser 12px 18px (py-3 = 12px, px-[18px] = 18px).');

        // "text-[13px]" não é da GRADE em si (linhas usam text-[14px], mais
        // legível ainda que a densidade da referência) — é do título "Tabela
        // progressiva" que continua em `TabelaFaixasSection.jsx`, por cima da
        // grade. A extração não moveu esse título; o teste continua medindo
        // os dois arquivos juntos, como já fazia antes desta fase.
        $conteudoSecao = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);
        $this->assertStringContainsString('text-[13px]', $conteudoGrade.$conteudoSecao, 'Texto do bloco da tabela progressiva precisa ter 13px em algum ponto (título da seção) — a versão anterior estava em 11px, quase metade do tamanho da referência.');
    }

    #[Test]
    public function cabecalho_tem_padding_10px_18px_sobre_a_superficie_interna(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_GRADE);

        $this->assertStringContainsString('px-[18px] py-2.5', $conteudo, 'Padding do cabeçalho precisa ser 10px 18px (py-2.5 = 10px).');
        $this->assertStringContainsString('bg-ecf-card-2', $conteudo, 'Cabeçalho fica sobre a "superfície interna" da referência (#0F0F11) — traduzida para o token ecf-card-2 do projeto, nunca o hex do handoff.');
    }

    #[Test]
    public function caixa_da_tabela_tem_raio_12px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_GRADE);

        $this->assertStringContainsString('rounded-xl border border-white/[0.06] overflow-hidden', $conteudo, 'A caixa da tabela progressiva precisa ter raio 12px (rounded-xl) — a referência não usa o raio de 8-10px do rounded-lg.');
    }

    #[Test]
    public function faixa_e_faturamento_ate_ficam_a_esquerda_e_mensalidade_a_direita(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_GRADE);

        // O cabeçalho da grade: os dois primeiros spans não têm text-right;
        // só o terceiro (Mensalidade) tem.
        $this->assertMatchesRegularExpression(
            '/<span>Faixa<\/span>\s*<span>Faturamento até<\/span>\s*<span className="text-right">Mensalidade<\/span>/',
            $conteudo,
            '"Faixa" e "Faturamento até" precisam ficar à esquerda (sem text-right); só "Mensalidade" fica à direita — antes disso "Faturamento até" estava indevidamente alinhada à direita.'
        );
    }

    // ─── Resto da área expandida (Financeiro.jsx) ────────────────────────

    #[Test]
    public function area_expandida_tem_padding_lateral_de_22px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        $this->assertStringContainsString('px-[22px] pt-1 pb-6', $conteudo, 'A área expandida precisa ter padding 4px 22px 24px — o padding lateral estava em 20px (px-5) em vez de 22px.');
    }

    // ─── Guardas que já existiam e não podem regredir ────────────────────

    #[Test]
    public function nao_introduz_jetbrains_mono_nem_instrument_sans_na_tabela_faixas_section(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS).$this->lerArquivoJsx(self::ARQUIVO_GRADE);

        foreach (['Instrument Sans', 'JetBrains', 'fonts.googleapis'] as $trecho) {
            $this->assertStringNotContainsString($trecho, $conteudo, "Decisão do usuário já tomada e não reaberta: a fonte continua a do projeto — \"{$trecho}\" não pode aparecer.");
        }

        $this->assertStringContainsString('font-mono', $conteudo, 'A tabela continua usando font-mono do Tailwind (não JetBrains Mono) para os valores numéricos.');
    }

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS)
            .$this->lerArquivoJsx(self::ARQUIVO_GRADE)
            .$this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

        // A escala do Tailwind pula de 3.5 para 4 — px-4.5/gap-4.5/py-5.5 (e
        // qualquer classe -N.5 fora de 0.5/1.5/2.5/3.5) não existem, o build
        // passa e nenhum CSS é gerado, sem aviso algum.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:p|px|py|pt|pb|pl|pr|gap|m|mx|my|mt|mb|ml|mr)-(?!0\.5\b|1\.5\b|2\.5\b|3\.5\b)\d+\.5\b/',
            $conteudo,
            'Classe de espaçamento com decimal fora da escala real do Tailwind (ex.: px-4.5, gap-4.5, py-5.5) — não existe, não gera CSS e não avisa nada.'
        );
    }

    #[Test]
    // ⚠️ RETARGET (Fase 142 Plano 04, D-04): mesma troca da suíte da Fase
    // 138 — o fechamento parou de gravar tabela, então a exigência de rota
    // migrou de "onde o bloco de grupo salva" (`admin.financeiro.faixas.grupo`)
    // para "para onde o bloco de grupo leva quem quer cadastrar"
    // (`admin.contratos.tabela.show`). As frases de herança, que são o
    // motivo original desta trava, continuam intactas — só o caminho de
    // cadastro mudou de endereço.
    public function frase_de_heranca_da_tabela_do_grupo_continua_intacta(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('Este grupo está usando a tabela da empresa', $conteudo, 'A frase que nomeia a empresa dona da tabela herdada não pode sumir no refactor.');
        $this->assertStringContainsString('Quem manda é a empresa do grupo que mais faturou no mês', $conteudo, 'A frase de desempate do grupo não pode sumir no refactor.');
        $this->assertStringContainsString('tabela_herdada_de_nome', $conteudo);
        $this->assertStringContainsString('admin.contratos.tabela.show', $conteudo);
    }
}
