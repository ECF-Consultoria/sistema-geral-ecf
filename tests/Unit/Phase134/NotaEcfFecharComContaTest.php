<?php

namespace Tests\Unit\Phase134;

use App\Models\MlAcervoItem;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 134 (Plano 03) — guarda de regressão da nota ECF (D-10/D-22).
 *
 * Roda tests/fixtures/phase134/nota-ecf-casos.json — o MESMO fixture que
 * tests/js/notaEcfConcordancia.test.js consome do lado JS — e trava dois
 * invariantes:
 *   (a) a soma dos pesos dos sinais verdadeiros fecha EXATAMENTE com a nota
 *       devolvida — é a pegadinha travada pelo D-10: se a tela lista os
 *       sinais, a soma deles É a nota (o caso `nps_medio` ≠
 *       `pontos_componentes.nps`, .planning/learnings/desempenho-bonificacao.md);
 *   (b) score_wizard_100 − 14×descricao_ok === nota_ecf_86 — o invariante de
 *       concordância entre os dois scorers, aritmeticamente garantido porque
 *       calcularScore() é soma aditiva pura de 8 sinais independentes.
 *
 * Sem RefreshDatabase: AnuncioSaudeService é serviço puro, sem Eloquent.
 *
 * @group phase134
 */
class NotaEcfFecharComContaTest extends TestCase
{
    private AnuncioSaudeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnuncioSaudeService();
    }

    /**
     * Data provider ESTÁTICO — o PHPUnit resolve os providers antes de
     * `createApplication()` rodar, então o helper `base_path()` ainda não
     * existe aqui. Caminho calculado a partir de `__DIR__`
     * (tests/Unit/Phase134/ → tests/) em vez do container.
     *
     * @return array<string,array{0:array}>
     */
    public static function casosProvider(): array
    {
        $caminho = dirname(__DIR__, 2) . '/fixtures/phase134/nota-ecf-casos.json';
        $fixture = json_decode(file_get_contents($caminho), true);

        $casos = [];
        foreach ($fixture['casos'] as $caso) {
            $casos[$caso['nome']] = [$caso];
        }

        return $casos;
    }

    #[Test]
    #[DataProvider('casosProvider')]
    public function a_nota_fecha_com_a_propria_conta(array $caso): void
    {
        $resultado = $this->service->avaliar($caso['item_ml'], $caso['atributos_categoria']);

        // 1. A nota bate com o valor esperado do fixture.
        $this->assertSame(
            $caso['esperado']['nota_ecf_86'],
            $resultado['nota'],
            "nota divergiu no caso \"{$caso['nome']}\""
        );

        // 2. A soma dos pesos dos sinais verdadeiros FECHA com a nota — é
        //    exatamente o breakdown renderizado no checklist do modal de
        //    detalhe (D-10): a conta mostrada na tela tem que bater.
        $somaSinaisOk = 0;
        foreach ($resultado['sinais'] as $sinal) {
            if ($sinal['ok']) {
                $somaSinaisOk += $sinal['peso'];
            }
        }
        $this->assertSame(
            $resultado['nota'],
            $somaSinaisOk,
            "a soma do breakdown de sinais não fecha com a nota no caso \"{$caso['nome']}\""
        );

        // 3. Invariante de concordância entre os dois scorers, do lado PHP.
        $descricaoOk = $caso['esperado']['descricao_ok'] ? 1 : 0;
        $this->assertSame(
            $caso['esperado']['nota_ecf_86'],
            $caso['esperado']['score_wizard_100'] - (AnuncioSaudeService::PESO_DESCRICAO_FORA * $descricaoOk),
            "invariante score_wizard - 14*descricao != nota_ecf_86 no caso \"{$caso['nome']}\""
        );

        // 4. Faixa válida — nunca acima da base, nunca abaixo de zero.
        $this->assertGreaterThanOrEqual(0, $resultado['nota'], "nota negativa no caso \"{$caso['nome']}\"");
        $this->assertLessThanOrEqual(
            AnuncioSaudeService::BASE,
            $resultado['nota'],
            "nota acima da base 86 no caso \"{$caso['nome']}\""
        );
    }

    #[Test]
    public function pesos_somam_a_base_declarada(): void
    {
        $this->assertSame(86, AnuncioSaudeService::BASE);
        $this->assertSame(AnuncioSaudeService::BASE, array_sum(AnuncioSaudeService::PESOS));
    }

    /**
     * D-18: item de catálogo cujo buy box ainda não entrou na rotação da
     * camada cara não pode virar "perdendo catálogo" por omissão — ausência
     * de avaliação não é "sem problema" nem "com problema".
     */
    #[Test]
    public function buybox_nao_avaliado_nunca_vira_motivo(): void
    {
        $item = [
            'status'             => 'active',
            'available_quantity' => 10,
            'catalog_listing'    => true,
        ];

        $sinais = [
            'ficha_obrigatoria' => ['peso' => 20, 'ok' => true],
            'foto'              => ['peso' => 16, 'ok' => true],
        ];

        $resultado = $this->service->triagem($item, $sinais, null);

        $this->assertNotContains(MlAcervoItem::MOTIVO_PERDENDO_CATALOGO, $resultado['motivos']);
        $this->assertSame(MlAcervoItem::SEVERIDADE_SAUDAVEL, $resultado['severidade']);
    }
}
