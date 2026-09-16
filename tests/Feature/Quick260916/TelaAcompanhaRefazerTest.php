<?php

namespace Tests\Feature\Quick260916;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260916-ejt — a tela acompanha o refazer até o fim.
 *
 * O projeto não roda test runner de JS (mesma constatação de
 * `Phase137CompetenciaUiTest`), então a trava de regressão da tela é ler o
 * `.jsx` como texto.
 *
 * O que não pode voltar: declarar "refeito com sucesso" no clique (nada
 * terminou ainda), perder o aviso quando a pessoa recarrega a página no meio
 * (foi o que gerou o clique repetido do incidente 260903-la4), e seguir
 * consultando o servidor depois de trocar de página.
 */
class TelaAcompanhaRefazerTest extends TestCase
{
    private function blocoDoRefazer(): string
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $inicio = strpos($conteudo, 'function RefazerFechamentoDialog');
        $this->assertNotFalse($inicio, 'RefazerFechamentoDialog precisa continuar existindo.');

        $fim = strpos($conteudo, "\nfunction ", $inicio + 1);

        return substr($conteudo, $inicio, ($fim !== false ? $fim : strlen($conteudo)) - $inicio);
    }

    private function semComentarios(string $bloco): string
    {
        return preg_replace('/^[ \t]*\/\/.*$/m', '', preg_replace('/\/\*.*?\*\//s', '', $bloco));
    }

    #[Test]
    public function a_tela_consulta_o_andamento_pela_rota_nova(): void
    {
        $bloco = $this->blocoDoRefazer();

        $this->assertStringContainsString(
            "route('admin.financeiro.competencia.refazer.status')",
            $bloco,
            'Sem consultar o andamento a tela nunca fica sabendo que terminou.',
        );
    }

    #[Test]
    public function o_sucesso_do_clique_nao_declara_mais_vitoria(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        $this->assertStringNotContainsStringIgnoringCase(
            'refeito com sucesso',
            $bloco,
            'A resposta do clique é só "aceitei" (202) — dizer que terminou é a mentira que faz a pessoa confiar em número velho.',
        );
        $this->assertStringContainsString('setRefazendo(true)', $bloco, 'O clique precisa deixar a tela em estado de "refazendo".');
    }

    #[Test]
    public function continua_fechando_o_dialogo_limpando_o_motivo_e_avisando_o_fim(): void
    {
        // Mesmas travas do incidente 260903-la4 — o quick não pode tê-las
        // desfeito ao trocar o fluxo.
        $bloco = $this->blocoDoRefazer();

        $this->assertStringContainsString('setOpen(false)', $bloco);
        $this->assertStringContainsString("setMotivo('')", $bloco);
        $this->assertMatchesRegularExpression('/setConfirmacao\(/', $bloco);
    }

    #[Test]
    public function volta_a_acompanhar_sozinha_depois_de_um_recarregamento(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        $this->assertStringContainsString(
            'consultarAndamento();',
            $bloco,
            'Uma consulta ao montar é o que faz o aviso voltar para quem recarregou a página no meio.',
        );
        $this->assertStringContainsString('useEffect', $bloco);
    }

    #[Test]
    public function para_de_consultar_ao_sair_da_tela(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        $this->assertStringContainsString('setInterval(consultarAndamento', $bloco);
        $this->assertStringContainsString(
            'clearInterval',
            $bloco,
            'Sem limpar o intervalo a tela segue batendo no servidor depois de trocar de página.',
        );
    }

    #[Test]
    public function o_botao_fica_desabilitado_e_diz_que_esta_em_andamento(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        $this->assertStringContainsString('disabled={refazendo}', $bloco);
        $this->assertStringContainsString('Refazendo...', $bloco);
        $this->assertStringContainsString('Refazer fechamento', $bloco);
    }

    #[Test]
    public function a_falha_diz_que_o_registro_anterior_continua_valendo(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        $this->assertStringContainsString('O registro anterior continua valendo', $bloco);
    }

    #[Test]
    public function a_copy_do_refazer_nao_tem_jargao(): void
    {
        $bloco = $this->semComentarios($this->blocoDoRefazer());

        foreach (['job', 'fila', 'cache', 'competência', 'snapshot', 'dispatch'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $bloco, "Termo técnico na tela: {$termo}");
        }
    }

    #[Test]
    public function nao_usa_degrau_de_tailwind_que_nao_existe(): void
    {
        $conteudo = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        foreach (['px-4.5', 'gap-4.5', 'py-5.5', 'mt-4.5'] as $classe) {
            $this->assertStringNotContainsString($classe, $conteudo, "A escala do Tailwind não tem {$classe} — a classe sai do CSS em silêncio.");
        }
    }
}
