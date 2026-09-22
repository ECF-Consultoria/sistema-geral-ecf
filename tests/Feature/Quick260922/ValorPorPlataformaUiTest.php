<?php

namespace Tests\Feature\Quick260922;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260922-j4l — o fechamento mostra o faturamento separado por plataforma.
 *
 * O projeto não tem test runner de JS (mesma constatação de
 * `Quick260922\ComposicaoFaturamentoUiTest` e `Phase143ComposicaoUiTest`), então
 * a trava de regressão da tela é ler o `.jsx` como texto puro.
 *
 * O que não pode voltar:
 *
 * 1. **Empresa das duas plataformas mostrando só a soma.** Era o pedido: quem
 *    atende no Mercado Livre e na Shopee precisa ver de onde veio o dinheiro
 *    sem abrir outra tela.
 * 2. **Três desenhos diferentes da mesma informação.** Os três lugares
 *    (listagem, composição do grupo, empresa expandida) passam pelo mesmo
 *    componente.
 * 3. **Cor como única pista.** Âmbar e laranja são quase iguais para quem
 *    enxerga pouco: o nome da plataforma acompanha sempre que houver duas
 *    linhas.
 * 4. **A tela somando plataformas.** O total é o que o backend mandou. Se um
 *    dia ML + Shopee divergir dele, quem precisa aparecer é a divergência.
 * 5. **`ecf-yellow` num valor.** É a cor de ação do sistema — número em amarelo
 *    de marca parece clicável.
 */
class ValorPorPlataformaUiTest extends TestCase
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

    private function blocoComponente(): string
    {
        return $this->semComentarios($this->bloco('function ValorPorPlataforma'));
    }

    // ─── (a) os três estados do valor ─────────────────────────────────────

    #[Test]
    public function duas_plataformas_viram_dois_valores_empilhados(): void
    {
        $bloco = $this->blocoComponente();

        $this->assertStringContainsString(
            'flex flex-col',
            $bloco,
            'Empilhado foi o formato pedido: um valor em cima do outro, não os dois na mesma linha corrida.'
        );

        $this->assertStringContainsString(
            'presentes.map(',
            $bloco,
            'Cada plataforma com dado vira uma linha — nenhuma pode ficar de fora.'
        );
    }

    #[Test]
    public function uma_plataforma_so_nao_empilha_nem_rotula(): void
    {
        $bloco = $this->blocoComponente();

        $this->assertStringContainsString(
            'presentes.length === 1',
            $bloco,
            'Sem comparação, repetir o nome da plataforma é ruído e a cor não informa nada.'
        );

        $this->assertStringContainsString(
            'return <span className={base}>{fmtBRL(linha[presentes[0].chave])}</span>;',
            $bloco,
            'Uma plataforma só é o valor seco, no estilo que quem chama já usava.'
        );
    }

    #[Test]
    public function sem_dado_nenhum_mostra_travessao_e_nunca_zero(): void
    {
        $bloco = $this->blocoComponente();

        $this->assertStringContainsString(
            'presentes.length === 0',
            $bloco,
            '"Não temos o dado" precisa de um ramo próprio.'
        );

        $this->assertStringContainsString('—', $bloco);

        foreach (['?? 0', 'fmtBRL(0)', "'R$ 0"] as $invencao) {
            $this->assertStringNotContainsString(
                $invencao,
                $bloco,
                'Trocar a ausência por zero é inventar um faturamento que ninguém mediu (D-05).'
            );
        }
    }

    // ─── (b) cor e rótulo ─────────────────────────────────────────────────

    #[Test]
    public function cada_plataforma_tem_a_sua_cor_e_nenhuma_usa_o_amarelo_de_acao(): void
    {
        $conteudo = $this->lerArquivo();

        $inicio = strpos($conteudo, 'const PLATAFORMAS_FATURAMENTO');
        $this->assertNotFalse($inicio, 'O mapa de plataformas precisa continuar existindo.');

        $mapa = substr($conteudo, $inicio, strpos($conteudo, '];', $inicio) - $inicio);

        $this->assertStringContainsString('text-amber-300', $mapa, 'Mercado Livre em âmbar.');
        $this->assertStringContainsString('text-orange-400', $mapa, 'Shopee em laranja.');

        $this->assertStringNotContainsString(
            'ecf-yellow',
            $this->blocoComponente(),
            'O amarelo de marca é a cor de ação (botão, link, foco) — número pintado com ela parece clicável.'
        );

        $this->assertStringNotContainsString(
            'ecf-yellow',
            $mapa,
            'Vale também para o mapa de cores das plataformas.'
        );
    }

    #[Test]
    public function o_nome_da_plataforma_acompanha_sempre_que_houver_duas_linhas(): void
    {
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString(
            "rotulo: 'Mercado Livre'",
            $conteudo,
            'Escrito por extenso: "ML" é vocabulário de quem já sabe.'
        );

        $this->assertStringContainsString(
            "rotulo: 'Shopee'",
            $conteudo
        );

        $this->assertStringContainsString(
            '{p.rotulo}',
            $this->blocoComponente(),
            'Cor não pode ser a única pista: âmbar e laranja são quase iguais para quem enxerga pouco.'
        );
    }

    // ─── (c) a tela não soma ──────────────────────────────────────────────

    #[Test]
    public function o_componente_nao_soma_plataformas(): void
    {
        $bloco = $this->blocoComponente();

        foreach (['reduce', 'faturamento_ml +', '+ linha.faturamento_shopee', 'faturamentoMl +'] as $soma) {
            $this->assertStringNotContainsString(
                $soma,
                $bloco,
                'O total é o que o backend mandou. Somar aqui esconderia justamente a divergência que precisa aparecer.'
            );
        }
    }

    #[Test]
    public function o_numero_grande_da_empresa_expandida_continua_sendo_o_total(): void
    {
        $bloco = $this->bloco('function FechamentoAccordion');

        $this->assertStringContainsString(
            'text-[24px] font-semibold font-mono tabular-nums text-white">{fmtBRL(empresa.faturamento)}',
            $bloco,
            'A quebra por plataforma entra DEBAIXO do total — ela não substitui o número que o backend apurou.'
        );
    }

    // ─── (d) os três lugares usam o mesmo componente ──────────────────────

    #[Test]
    public function a_listagem_usa_o_componente(): void
    {
        $this->assertStringContainsString(
            '<ValorPorPlataforma',
            $this->bloco('function ColunaFaturamento'),
            'A listagem é onde a pessoa passa o olho primeiro — mostrar só a soma ali era o problema relatado.'
        );
    }

    #[Test]
    public function a_composicao_do_grupo_usa_o_componente_nas_empresas_e_no_total(): void
    {
        $bloco = $this->semComentarios($this->bloco('function FechamentoAccordion'));

        $this->assertStringContainsString('linha={e}', $bloco, 'Cada empresa do grupo.');
        $this->assertStringContainsString('linha={empresa}', $bloco, 'E o total do grupo, pelo mesmo critério.');

        $this->assertSame(
            2,
            substr_count($bloco, '<ValorPorPlataforma'),
            'São dois usos na composição: as empresas e o total. Um terceiro desenho da mesma informação é exatamente o que este componente existe para evitar.'
        );
    }

    #[Test]
    public function a_empresa_expandida_usa_o_componente_no_lugar_da_frase_somada(): void
    {
        $bloco    = $this->semComentarios($this->bloco('function FaturamentoCombinadoBreakdown'));
        $conteudo = $this->lerArquivo();

        $this->assertStringContainsString('<ValorPorPlataforma', $bloco);

        $this->assertStringNotContainsString(
            '= {fmtBRL(faturamentoTotal)}',
            $conteudo,
            'A frase "Mercado Livre X + Shopee Y = total" repetia, em 12px, o número que está logo acima em 24px.'
        );
    }

    #[Test]
    public function o_aviso_de_plataforma_excluida_continua_de_pe(): void
    {
        $bloco = $this->bloco('function FaturamentoCombinadoBreakdown');

        $this->assertStringContainsString(
            'não entra nesta conta porque não há serviço contratado nela',
            $bloco,
            'Este aviso explica uma exclusão de dinheiro — é a única frase da tela que justifica um faturamento real ficar de fora da faixa.'
        );
    }

    // ─── (e) copy e Tailwind ──────────────────────────────────────────────

    #[Test]
    public function a_copy_nao_traz_jargao(): void
    {
        // O único texto que este componente põe na tela é o nome da
        // plataforma, e ele vem do mapa — é lá que a trava olha. Sigla
        // ("ML", "MLB", "Shp") é vocabulário de quem já sabe.
        $conteudo = $this->lerArquivo();

        $inicio = strpos($conteudo, 'const PLATAFORMAS_FATURAMENTO');
        $this->assertNotFalse($inicio);

        $mapa = substr($conteudo, $inicio, strpos($conteudo, '];', $inicio) - $inicio);

        foreach (["rotulo: 'ML'", "rotulo: 'MLB'", "rotulo: 'Shp'", "rotulo: 'Mercado Livre (ML)'"] as $sigla) {
            $this->assertStringNotContainsString(
                $sigla,
                $mapa,
                'O nome da plataforma vai por extenso — quem lê esta tela é o time Administrativo.'
            );
        }
    }

    #[Test]
    public function nao_usa_degrau_de_tailwind_que_nao_existe(): void
    {
        $bloco = $this->lerArquivo();

        foreach (['gap-0.75', 'text-[11.5px]', 'gap-1.75', 'text-[13.5px]'] as $classe) {
            $this->assertStringNotContainsString(
                $classe,
                $bloco,
                "A escala do Tailwind não tem {$classe} — a classe sai do CSS em silêncio."
            );
        }

        foreach (['gap-0.5', 'gap-1.5', 'text-[11px]', 'text-[14px]'] as $classe) {
            $this->assertStringContainsString($classe, $bloco);
        }
    }
}
