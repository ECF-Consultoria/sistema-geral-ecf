<?php

namespace Tests\Feature\Quick260915;

use App\Support\Cnpj;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260915-mtj — o painel de conferência mostra a comparação de CNPJ antes do clique.
 *
 * A regra vive em `App\Support\Cnpj::comparar()`; a tela espelha a mesma regra em JS para mostrar o
 * resultado para cada empresa escolhível. Este arquivo trava que o espelho não se descola da fonte
 * (mesmos cinco estados) e que a copy nova não traz jargão.
 */
class ConferenciaCnpjTelaTest extends TestCase
{
    private function jsx(): string
    {
        return file_get_contents(resource_path('js/Pages/Admin/TabelasContrato.jsx'));
    }

    private function trecho(string $conteudo, string $de, string $ate): string
    {
        $inicio = strpos($conteudo, $de);
        $this->assertNotFalse($inicio, "Trecho não encontrado: {$de}");
        $fim = strpos($conteudo, $ate, $inicio + strlen($de));
        $this->assertNotFalse($fim, "Fim do trecho não encontrado: {$ate}");

        return substr($conteudo, $inicio, $fim - $inicio);
    }

    private function semComentarios(string $bloco): string
    {
        return preg_replace('/^[ \t]*\/\/.*$/m', '', preg_replace('/\/\*.*?\*\//s', '', $bloco));
    }

    #[Test]
    public function os_estados_da_tela_sao_os_mesmos_do_helper(): void
    {
        $mapa = $this->trecho($this->jsx(), 'const CONFERENCIA_CNPJ = {', "\n};");
        preg_match_all('/^    (\w+): \{/m', $mapa, $m);

        $naTela = $m[1];
        $noHelper = Cnpj::COMPARACOES;
        sort($naTela);
        sort($noHelper);

        $this->assertSame($noHelper, $naTela, 'Os estados visuais da tela precisam ser exatamente os de Cnpj::COMPARACOES.');
    }

    #[Test]
    public function o_espelho_devolve_os_cinco_estados_com_a_mesma_regra(): void
    {
        $funcao = $this->trecho($this->jsx(), 'function compararCnpj(', "\n}");

        foreach (Cnpj::COMPARACOES as $estado) {
            $this->assertStringContainsString("return '{$estado}'", $funcao, "O espelho não devolve o estado {$estado}.");
        }

        // Mesma régua do helper: só dígitos, 14 dígitos inteiros, 8 primeiros identificam a empresa.
        $conteudo = $this->jsx();
        $this->assertStringContainsString(".replace(/\\D/g, '')", $conteudo);
        $this->assertStringContainsString('d.length === 14', $conteudo);
        $this->assertStringContainsString('d.slice(0, 8)', $conteudo);
    }

    #[Test]
    public function o_painel_envia_a_marcacao_e_desmarca_ao_trocar_de_empresa(): void
    {
        $conteudo = $this->jsx();

        $this->assertStringContainsString('confirmo_cnpj_diferente:', $conteudo);
        $this->assertStringContainsString('Conferi: este contrato é mesmo desta empresa', $conteudo);
        $this->assertStringContainsString('Conferência do CNPJ', $conteudo);
        $this->assertStringContainsString('disabled={enviando || !companyId || faltaConferirCnpj}', $conteudo);

        $escolher = $this->trecho($conteudo, 'function escolherEmpresa(', "\n    }");
        $this->assertStringContainsString('setConfirmoDiferente(false)', $escolher);

        // As duas formas de escolher empresa (atalhos e busca) passam pela função que desmarca.
        $this->assertSame(2, substr_count($conteudo, 'escolherEmpresa(c.company_id)') + substr_count($conteudo, 'escolherEmpresa(e.id)'));
        $this->assertStringNotContainsString('setCompanyId(c.company_id)', $conteudo);
        $this->assertStringNotContainsString('setCompanyId(e.id)', $conteudo);
    }

    #[Test]
    public function a_copy_da_conferencia_do_cnpj_nao_tem_jargao(): void
    {
        $bloco = $this->semComentarios(
            $this->trecho($this->jsx(), 'const CONFERENCIA_CNPJ = {', '// ─── Painel de conferência (Dialog)')
        );

        $this->assertStringContainsString('Mesmo CNPJ — o contrato é desta empresa.', $bloco);
        $this->assertStringContainsString('Mesma empresa, outra unidade (matriz e filial).', $bloco);

        foreach (['proposta', 'parser', 'envelope', 'score', 'palpite', 'raiz', 'carona'] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase($termo, $bloco, "Termo técnico na tela: {$termo}");
        }
    }
}
