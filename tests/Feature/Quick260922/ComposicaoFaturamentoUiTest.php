<?php

namespace Tests\Feature\Quick260922;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260922-gn1 — ao expandir um grupo, o fechamento mostra o faturamento
 * de cada empresa.
 *
 * O projeto não tem test runner de JS (mesma constatação de
 * `Phase143ComposicaoUiTest` e `Quick260916\TelaAcompanhaRefazerTest`), então a
 * trava de regressão da tela é ler o `.jsx` como texto puro.
 *
 * O que não pode voltar:
 *
 * 1. **A composição sem o faturamento por empresa.** É o número que a pessoa
 *    abre o grupo justamente para somar e conferir contra o total.
 * 2. **Empresa sem faturamento exibindo R$ 0.** "Não temos o dado" e "vendeu
 *    zero" são coisas diferentes e não podem ficar iguais na tela.
 * 3. **A tela somando as empresas para montar o total.** O total é o que o
 *    backend mandou; se um dia divergir, quem precisa aparecer é a divergência,
 *    não uma soma inventada pelo cliente.
 * 4. **A composição repetindo o grupo.** `filhas` já traz TODAS as empresas,
 *    inclusive a que ancora a cobrança — juntar a linha-mãe listava a mesma
 *    empresa duas vezes e inflava o selo em uma unidade.
 */
class ComposicaoFaturamentoUiTest extends TestCase
{
    private const ARQUIVO = 'js/Pages/Admin/Financeiro.jsx';

    private function lerArquivo(): string
    {
        return file_get_contents(resource_path(self::ARQUIVO));
    }

    /** Recorta um bloco de função do arquivo, do cabeçalho até a próxima função. */
    private function bloco(string $assinatura): string
    {
        $conteudo = $this->lerArquivo();

        $inicio = strpos($conteudo, $assinatura);
        $this->assertNotFalse($inicio, "{$assinatura} precisa continuar existindo.");

        $fim = strpos($conteudo, "\nfunction ", $inicio + 1);

        return substr($conteudo, $inicio, ($fim !== false ? $fim : strlen($conteudo)) - $inicio);
    }

    /** Remove blocos de comentário e linhas `//` — só sobra o que vira tela. */
    private function semComentarios(string $conteudo): string
    {
        return preg_replace('/^[ \t]*\/\/.*$/m', '', preg_replace('/\/\*.*?\*\//s', '', $conteudo));
    }

    // ─── (a) o faturamento por empresa ────────────────────────────────────

    #[Test]
    public function a_composicao_mostra_o_faturamento_de_cada_empresa(): void
    {
        $bloco = $this->bloco('function FechamentoAccordion');

        $this->assertStringContainsString(
            'fmtBRL(e.faturamento)',
            $bloco,
            'É o número que a pessoa abre o grupo para somar — sem ele a conferência não acontece.'
        );
    }

    #[Test]
    public function empresa_sem_faturamento_mostra_travessao_e_nunca_zero(): void
    {
        $bloco = $this->semComentarios($this->bloco('function FechamentoAccordion'));

        $this->assertStringContainsString(
            "e.faturamento != null ? fmtBRL(e.faturamento) : '—'",
            $bloco,
            '"Não temos o dado" e "vendeu zero" não podem ficar iguais na tela.'
        );

        $this->assertStringNotContainsString(
            'fmtBRL(e.faturamento ?? 0)',
            $bloco,
            'Trocar a ausência por zero é inventar um faturamento que ninguém mediu.'
        );
    }

    #[Test]
    public function a_quebra_por_plataforma_fica_no_tooltip_e_nao_na_linha(): void
    {
        $conteudo = $this->lerArquivo();
        $bloco    = $this->bloco('function FechamentoAccordion');

        $this->assertStringContainsString(
            'const detalhePlataformas =',
            $conteudo,
            'A quebra Mercado Livre/Shopee existe e é montada num lugar só.'
        );

        $this->assertStringContainsString(
            'title={detalhePlataformas(e)}',
            $bloco,
            'A coluna mostra o total; ML e Shopee separados vão no tooltip para não poluir a linha.'
        );
    }

    // ─── (b) o total do grupo ─────────────────────────────────────────────

    #[Test]
    public function o_total_do_grupo_mostra_o_faturamento_que_o_backend_mandou(): void
    {
        $bloco = $this->semComentarios($this->bloco('function FechamentoAccordion'));

        $this->assertStringContainsString(
            "empresa.faturamento != null ? fmtBRL(empresa.faturamento) : '—'",
            $bloco,
            'Sem o total na mesma coluna a soma não fecha à vista — é o ponto todo do pedido.'
        );

        $this->assertStringContainsString(
            'Total do grupo',
            $bloco,
            'A linha de total continua rotulada.'
        );
    }

    #[Test]
    public function a_tela_nao_soma_as_empresas_para_montar_o_total(): void
    {
        $bloco = $this->semComentarios($this->bloco('function FechamentoAccordion'));

        $this->assertStringNotContainsString(
            'reduce',
            $bloco,
            'O total é o que o backend mandou. Se um dia divergir da soma das empresas, quem precisa aparecer é a divergência, não uma soma inventada pela tela.'
        );

        $this->assertStringNotContainsString(
            'filhas.reduce',
            $this->lerArquivo(),
            'Somar `filhas` em qualquer lugar da tela recria o mesmo risco.'
        );
    }

    // ─── (c) a composição para de repetir o grupo ─────────────────────────

    #[Test]
    public function a_composicao_lista_so_as_empresas_do_grupo(): void
    {
        $conteudo = $this->lerArquivo();
        $bloco    = $this->bloco('function FechamentoAccordion');

        $this->assertStringContainsString(
            'empresa.filhas.map(',
            $bloco,
            'A lista é sobre as empresas do grupo e só.'
        );

        $this->assertStringNotContainsString(
            '[empresa, ...empresa.filhas]',
            $conteudo,
            '`filhas` já inclui a empresa que ancora a cobrança: juntar a linha-mãe faz a mesma empresa aparecer duas vezes, uma delas com o nome do grupo.'
        );

        $this->assertStringNotContainsString(
            '(este)',
            $conteudo,
            'Sem a linha-mãe na lista não existe mais "(este)" para marcar.'
        );
    }

    #[Test]
    public function o_selo_da_listagem_conta_so_as_empresas(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            'Grupo · {empresa.filhas.length}',
            $conteudo,
            'O selo conta empresas.'
        );

        $this->assertStringNotContainsString(
            'empresa.filhas.length + 1',
            $conteudo,
            '"Grupo · 11" para 10 empresas era a linha do grupo sendo contada como se fosse mais uma empresa.'
        );
    }

    #[Test]
    public function o_aviso_de_tabelas_diferentes_nao_conta_o_grupo(): void
    {
        $bloco = $this->semComentarios($this->bloco('function GrupoServicosDivergentesBanner'));

        $this->assertStringContainsString(
            'const membros = empresa.filhas || [];',
            $bloco,
            'O aviso fazia a mesma junção da composição e listava o grupo como se fosse uma empresa com tabela divergente.'
        );

        $this->assertStringNotContainsString(
            '[empresa, ...(empresa.filhas || [])]',
            $bloco,
            'A correção precisa valer para o aviso também, senão ele volta a contar o grupo.'
        );
    }

    // ─── (d) o que a Fase 143 já garantia continua de pé ──────────────────

    #[Test]
    public function mes_fechado_continua_avisando_que_a_divisao_e_a_de_hoje(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString('empresa.subgrupos_sao_de_hoje &&', $conteudo);
        $this->assertStringContainsString('Esta é a divisão de hoje.', $conteudo);
    }

    // ─── (e) copy e Tailwind ──────────────────────────────────────────────

    #[Test]
    public function a_copy_da_composicao_nao_traz_jargao(): void
    {
        $bloco = $this->semComentarios($this->bloco('function FechamentoAccordion'));

        foreach (['snapshot', 'rollup', 'âncora', 'raiz', 'competência', 'parent'] as $termo) {
            $padrao = '/(?<![\p{L}\p{N}_.])'.preg_quote($termo, '/').'(?![\p{L}\p{N}_])/ui';

            $this->assertDoesNotMatchRegularExpression(
                $padrao,
                $bloco,
                "O termo \"{$termo}\" é vocabulário de código — quem lê esta tela é o time Administrativo."
            );
        }
    }

    #[Test]
    public function nao_usa_degrau_de_tailwind_que_nao_existe(): void
    {
        $conteudo = $this->lerArquivo();

        foreach (['px-4.5', 'gap-4.5', 'py-5.5', 'mt-4.5', 'min-w-[110]'] as $classe) {
            $this->assertStringNotContainsString(
                $classe,
                $conteudo,
                "A escala do Tailwind não tem {$classe} — a classe sai do CSS em silêncio."
            );
        }

        $this->assertStringContainsString(
            'min-w-[110px]',
            $conteudo,
            'As colunas de dinheiro precisam de largura mínima para os valores alinharem entre as linhas.'
        );
    }
}
