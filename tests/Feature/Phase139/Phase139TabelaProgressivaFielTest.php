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
 * ⚠️ Decisão do usuário já tomada, não reaberta por este teste: a fonte
 * continua a do projeto (font-mono do Tailwind) — JetBrains Mono foi
 * oferecida e recusada.
 */
class Phase139TabelaProgressivaFielTest extends TestCase
{
    private const ARQUIVO_TABELA_FAIXAS = 'js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx';

    private const ARQUIVO_FINANCEIRO = 'js/Pages/Admin/Financeiro.jsx';

    private function lerArquivoJsx(string $caminhoRelativo): string
    {
        return file_get_contents(resource_path($caminhoRelativo));
    }

    // ─── Uma subcomponente, não três cópias ──────────────────────────────

    #[Test]
    public function existe_exatamente_uma_definicao_da_subcomponente_de_tabela_progressiva(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $ocorrencias = substr_count($conteudo, 'function TabelaProgressivaFaixas(');

        $this->assertSame(1, $ocorrencias, 'A tabela progressiva precisa existir como UMA subcomponente — três cópias já divergiram entre si antes desta correção.');
    }

    #[Test]
    public function a_subcomponente_e_reaproveitada_nos_dois_blocos_grupo_e_servico(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $usos = substr_count($conteudo, '<TabelaProgressivaFaixas ');

        $this->assertGreaterThanOrEqual(2, $usos, 'A subcomponente precisa ser usada no bloco de grupo (Fase 138) e no bloco de serviço (Fase 137) — senão a extração não eliminou a duplicação.');
    }

    #[Test]
    public function nao_sobra_nenhum_table_html_no_arquivo(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringNotContainsString('<table', $conteudo, 'A referência usa grid, não `<table>` de larguras automáticas — sobrar um `<table>` é sinal de cópia não migrada.');
    }

    #[Test]
    public function faturamento_ate_aparece_so_duas_vezes_label_do_form_e_cabecalho_da_grade(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $ocorrencias = substr_count($conteudo, 'Faturamento até');

        $this->assertSame(2, $ocorrencias, 'Antes da correção eram 3 ocorrências (duas tabelas divergentes + o label do form). Depois da unificação devem sobrar 2: o label do form de cadastro e o cabeçalho único da grade.');
    }

    // ─── Densidade da referência (design_handoff_fechamento/Fechamento.dc.html) ──

    #[Test]
    public function grade_usa_as_tres_colunas_da_referencia_com_gap_de_16px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('grid-cols-[80px_1fr_160px]', $conteudo, 'A referência usa grid-template-columns: 80px 1fr 160px.');
        $this->assertStringContainsString('gap-4', $conteudo, 'Gap de 16px entre colunas (gap-4 do Tailwind já bate com a escala real, sem precisar de valor arbitrário).');
    }

    #[Test]
    public function linhas_tem_padding_12px_18px_e_texto_13px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('px-[18px] py-3', $conteudo, 'Padding das linhas precisa ser 12px 18px (py-3 = 12px, px-[18px] = 18px).');
        $this->assertStringContainsString('text-[13px]', $conteudo, 'Texto das linhas precisa ser 13px — a versão anterior estava em 11px, quase metade do tamanho da referência.');
    }

    #[Test]
    public function cabecalho_tem_padding_10px_18px_sobre_a_superficie_interna(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('px-[18px] py-2.5', $conteudo, 'Padding do cabeçalho precisa ser 10px 18px (py-2.5 = 10px).');
        $this->assertStringContainsString('bg-ecf-card-2', $conteudo, 'Cabeçalho fica sobre a "superfície interna" da referência (#0F0F11) — traduzida para o token ecf-card-2 do projeto, nunca o hex do handoff.');
    }

    #[Test]
    public function caixa_da_tabela_tem_raio_12px(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('rounded-xl border border-white/[0.06] overflow-hidden', $conteudo, 'A caixa da tabela progressiva precisa ter raio 12px (rounded-xl) — a referência não usa o raio de 8-10px do rounded-lg.');
    }

    #[Test]
    public function faixa_e_faturamento_ate_ficam_a_esquerda_e_mensalidade_a_direita(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

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
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        foreach (['Instrument Sans', 'JetBrains', 'fonts.googleapis'] as $trecho) {
            $this->assertStringNotContainsString($trecho, $conteudo, "Decisão do usuário já tomada e não reaberta: a fonte continua a do projeto — \"{$trecho}\" não pode aparecer.");
        }

        $this->assertStringContainsString('font-mono', $conteudo, 'A tabela continua usando font-mono do Tailwind (não JetBrains Mono) para os valores numéricos.');
    }

    #[Test]
    public function nao_escala_valores_com_a_notacao_quebrada_de_decimais_do_tailwind(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS).$this->lerArquivoJsx(self::ARQUIVO_FINANCEIRO);

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
    public function frase_de_heranca_da_tabela_do_grupo_continua_intacta(): void
    {
        $conteudo = $this->lerArquivoJsx(self::ARQUIVO_TABELA_FAIXAS);

        $this->assertStringContainsString('Este grupo está usando a tabela da empresa', $conteudo, 'A frase que nomeia a empresa dona da tabela herdada não pode sumir no refactor.');
        $this->assertStringContainsString('Quem manda é a empresa do grupo que mais faturou no mês', $conteudo, 'A frase de desempate do grupo não pode sumir no refactor.');
        $this->assertStringContainsString('tabela_herdada_de_nome', $conteudo);
        $this->assertStringContainsString('admin.financeiro.faixas.grupo', $conteudo);
    }
}
