<?php

namespace Tests\Feature\Quick260930;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260930-njd (T1, item 3) — a tela tem de DIZER de onde veio o número.
 *
 * O motivo é de conferência, não de estética: os dois caminhos possíveis dão
 * valores diferentes (a Adman revisa dias já passados depois da nossa coleta),
 * e quem abre a tela ao lado do painel da Adman precisa saber qual dos dois
 * está na frente dele. Sem isso, "deu diferente" parece erro do sistema quando
 * é só o nosso número tendo envelhecido.
 *
 * Teste de ARQUIVO (mesmo padrão de `Quick260922\ValorPorPlataformaUiTest`):
 * não há runner de JS no projeto, e o que precisa de trava aqui é a COPY e a
 * ligação da prop — não o comportamento do React.
 */
class ProcedenciaFaturamentoUiTest extends TestCase
{
    private function jsx(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));
    }

    #[Test]
    public function o_componente_de_procedencia_existe_e_esta_ligado_na_prop(): void
    {
        $jsx = $this->jsx();

        $this->assertStringContainsString('function ProcedenciaFaturamentoNota(', $jsx);
        $this->assertStringContainsString('<ProcedenciaFaturamentoNota fonte={empresa.faturamento_fonte} />', $jsx);
    }

    #[Test]
    public function os_dois_estados_que_falam_estao_cobertos(): void
    {
        $jsx = $this->jsx();
        $bloco = $this->blocoDoComponente($jsx);

        // O caminho bom: o número bate com o painel da Adman.
        $this->assertStringContainsString("fonte === 'api'", $bloco);
        $this->assertStringContainsString('Total conferido com a Adman', $bloco);

        // O caminho honesto: a Adman era a régua e não respondeu.
        $this->assertStringContainsString("fonte === 'soma_diaria_fallback'", $bloco);
        $this->assertStringContainsString('não chegou hoje', $bloco);
        $this->assertStringContainsString('somado dia a dia', $bloco);
    }

    /**
     * A soma diária normal não mostra nada. Duas razões, e as duas importam:
     * é o estado de quem simplesmente não tem conta Adman conferível (um selo
     * em toda linha viraria ruído), e com a chave desligada TODA linha é
     * `soma_diaria` — mostrar algo ali seria mudar a tela com a chave
     * desligada, que é exatamente o que não pode acontecer.
     */
    #[Test]
    public function a_soma_diaria_normal_nao_mostra_nada(): void
    {
        $bloco = $this->blocoDoComponente($this->jsx());

        // Nenhum ramo para `soma_diaria` puro (o `_fallback` é outro valor, e
        // o teste acima já cobre ele) — o componente cai no `return null`.
        $this->assertDoesNotMatchRegularExpression("/fonte === 'soma_diaria'[^_]/", $bloco);
        $this->assertStringContainsString('return null;', $bloco);
    }

    /**
     * Sem jargão — regra sistêmica do projeto. A pessoa que confere o
     * fechamento não tem que saber o que é aquecimento, chave de configuração
     * nem endereço de API; ela quer saber se o número é o da Adman ou o nosso.
     */
    #[Test]
    public function a_copy_nao_traz_jargao(): void
    {
        $bloco = $this->blocoDoComponente($this->jsx());

        // Só o TEXTO visível (o que está entre as tags), nunca os nomes de
        // valor da prop — 'api' e 'soma_diaria_fallback' são identificadores
        // do backend e precisam aparecer no código.
        $textoVisivel = $this->textoVisivel($bloco);

        foreach (['cache', 'rollup', 'API', 'api', 'fonte', 'endpoint', 'fallback', 'aquec'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase(
                $jargao,
                $textoVisivel,
                "A copy da procedência do faturamento não pode usar \"{$jargao}\"."
            );
        }
    }

    #[Test]
    public function nao_usa_degrau_de_tailwind_que_nao_existe(): void
    {
        $bloco = $this->blocoDoComponente($this->jsx());

        // O projeto usa a escala padrão + os tokens ecf-*; degrau inventado
        // (ex.: text-white/45) sai como classe morta e o texto fica invisível.
        preg_match_all('/text-white\/(\d+)/', $bloco, $m);

        foreach ($m[1] as $degrau) {
            $this->assertContains(
                (int) $degrau,
                [5, 10, 20, 25, 30, 40, 50, 60, 70, 75, 80, 90, 95, 100],
                "text-white/{$degrau} não é um degrau da escala padrão do Tailwind."
            );
        }
    }

    /** Só o corpo de `ProcedenciaFaturamentoNota`, nada do resto do arquivo. */
    private function blocoDoComponente(string $jsx): string
    {
        $inicio = strpos($jsx, 'function ProcedenciaFaturamentoNota(');
        $this->assertNotFalse($inicio, 'O componente ProcedenciaFaturamentoNota desapareceu do arquivo.');

        // Até a próxima declaração de função no nível do módulo.
        $fim = strpos($jsx, "\nfunction ", $inicio + 10);

        return $fim === false ? substr($jsx, $inicio) : substr($jsx, $inicio, $fim - $inicio);
    }

    /** O texto que o usuário lê: fora de tags, de atributos e de comentários. */
    private function textoVisivel(string $bloco): string
    {
        // Fora os comentários de bloco e de linha.
        $bloco = preg_replace('#/\*.*?\*/#s', ' ', $bloco);
        $bloco = preg_replace('#//[^\n]*#', ' ', $bloco);

        // Pega só o conteúdo dos <p>...</p> — é onde a copy vive.
        preg_match_all('#<p[^>]*>(.*?)</p>#s', $bloco, $m);

        return implode(' ', $m[1]);
    }
}
