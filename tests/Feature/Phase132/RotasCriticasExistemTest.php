<?php

namespace Tests\Feature\Phase132;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guarda contra o apagão de 2026-08-18.
 *
 * O deploy de uma milestone paralela removeu 56 linhas de `routes/web.php` num
 * merge SEM CONFLITO — entre elas o receiver de webhook da Clicksign inteiro.
 * `POST /api/webhooks/clicksign` passou a devolver 404 e todo evento foi
 * descartado sem registro, das 09:44 às ~11:00. Os controllers e as telas
 * continuavam versionados, então nada quebrou de forma visível.
 *
 * Git não avisa: "o outro lado deletou" é resolução válida para ele. Um teste
 * que AFIRMA a existência das rotas de integração pega o que o merge não pega.
 */
class RotasCriticasExistemTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function rotasCriticas(): array
    {
        return [
            'receiver de webhook da Clicksign' => ['webhooks.clicksign'],
            'receiver de webhook do HubSpot'   => ['webhooks.hubspot'],
            'lista de contratos do Admin'      => ['admin.contratos.index'],
            'detalhe da empresa no Admin'      => ['admin.contratos.show'],
            'geração de contrato'              => ['admin.contratos.gerar'],
            'liberação manual (rede de seg.)'  => ['admin.contratos.liberacao-manual'],
            'download do PDF assinado'         => ['contratos.pdf-assinado'],
        ];
    }

    /**
     * @dataProvider rotasCriticas
     */
    public function test_rota_critica_existe(string $nome): void
    {
        $this->assertTrue(
            Route::has($nome),
            "A rota `{$nome}` sumiu. Isto costuma acontecer em merge de branches "
            . 'longas: o arquivo de rotas é resolvido a favor de um lado e a rota '
            . 'da outra milestone cai junto, sem conflito. Restaure antes de deployar.'
        );
    }
}
