<?php

namespace Tests\Feature\Phase158;

use App\Models\OnboardingPasso;
use App\Support\Onboarding\DefinicaoOnboarding;
use App\Models\Servico;
use Tests\TestCase;

/**
 * O espelho manual entre PHP e JS, com cadeado.
 *
 * `ETAPAS_ORDEM`, em `Pages/Onboarding/Publico.jsx`, decide quais passos são
 * RENDERIZADOS. Ele é cópia à mão de `OnboardingPasso::ETAPAS` — não existe
 * tipo compartilhado entre os dois lados.
 *
 * Em 14/09 isso cobrou: `publicidade` e `adman` entraram na régua do portal e
 * ninguém as acrescentou ao JS. Os cinco itens dessas etapas sumiram da tela
 * sem erro nenhum — o filtro era igualdade exata e descartava calado o que não
 * casava. O sintoma foi o progresso dizer "0/10" com quatro cards na tela: a
 * contagem vinha do backend, o desenho vinha do espelho furado.
 *
 * O arquivo JS AVISAVA sobre isso num comentário. Aviso não é trava; este teste
 * é.
 */
class EtapasDoPortalNaoSomemTest extends TestCase
{
    private function etapasDoJsx(): array
    {
        $jsx = file_get_contents(resource_path('js/Pages/Onboarding/Publico.jsx'));

        $this->assertIsString($jsx, 'não consegui ler Publico.jsx');

        // Só a declaração de ETAPAS_ORDEM, do `[` ao `]`.
        $ok = preg_match('/const ETAPAS_ORDEM = \[(.*?)\];/s', $jsx, $m);

        $this->assertSame(1, $ok, 'ETAPAS_ORDEM sumiu ou mudou de forma em Publico.jsx');

        preg_match_all("/'([a-z_]+)'/", $m[1], $chaves);

        return $chaves[1];
    }

    /**
     * Toda etapa que a régua do portal pode emitir tem de existir no JS. Se
     * faltar, o passo não é desenhado — e ninguém percebe até alguém conferir
     * contagem contra tela.
     */
    public function test_toda_etapa_dos_passos_do_portal_existe_no_jsx(): void
    {
        $servico = Servico::make(['nome' => 'Gestão', 'setor' => Servico::SETOR_PERFORMANCE]);

        $etapasNaRegua = collect(DefinicaoOnboarding::paraServico($servico) ?? [])
            ->filter(fn (array $p) => DefinicaoOnboarding::apareceNoPortal($p['chave']))
            ->pluck('etapa')
            ->unique()
            ->values();

        $this->assertNotEmpty($etapasNaRegua, 'nenhum passo do portal — o teste ficaria vazio');

        $noJsx = $this->etapasDoJsx();

        foreach ($etapasNaRegua as $etapa) {
            $this->assertContains(
                $etapa,
                $noJsx,
                "A etapa \"{$etapa}\" existe em passos do portal mas falta em ETAPAS_ORDEM "
                . '(Publico.jsx) — os passos dela SOMEM da tela do cliente, sem erro nenhum.'
            );
        }
    }

    /**
     * A rede de segurança: mesmo que alguém acrescente uma etapa nova no
     * backend e esqueça o JS, o passo precisa cair em algum bloco. `outros` é
     * esse destino, então ele não pode desaparecer da lista.
     */
    public function test_o_bloco_de_sobra_continua_existindo(): void
    {
        $this->assertContains(
            'outros',
            $this->etapasDoJsx(),
            'sem o bloco "outros" a rede contra etapa desconhecida deixa de existir.'
        );
    }

    /** Nenhuma etapa do JS pode ser invenção — isso denunciaria cópia errada. */
    public function test_o_jsx_nao_inventa_etapa_que_o_backend_desconhece(): void
    {
        // `outros` é sentinela do JS, não etapa do backend: é o bloco de sobra
        // onde cai passo sem etapa, ou com etapa que o JS ainda não conhece.
        $doBackend = [...OnboardingPasso::ETAPAS, 'outros'];

        foreach ($this->etapasDoJsx() as $etapa) {
            $this->assertContains(
                $etapa,
                $doBackend,
                "ETAPAS_ORDEM tem \"{$etapa}\", que não existe em OnboardingPasso::ETAPAS."
            );
        }
    }
}
