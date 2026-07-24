<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\HubspotWebhookController;
use App\Models\HubspotEvento;
use App\Services\HubspotApiClient;
use Illuminate\Console\Command;

/**
 * Fase 114 Plan 03 (HUB-REPLAY-01) — comando de replay do handoff HubSpot.
 *
 * Reprocessa um `HubspotEvento` já recebido: refaz o fetch (deal/company/
 * contatos/line items) e reusa o MESMO fluxo de dedup/handoff do webhook
 * (`HubspotWebhookController::reprocessarEvento`) — sem reaplicar o filtro
 * de dealstage nem o early-return de idempotência de INGESTÃO (essas são
 * regras do webhook, não do replay intencional do admin).
 *
 * Uso típico: um line item chegou sem `HubspotLineItemMapping` cadastrado
 * (o contrato correspondente não foi criado — pendência "servico não
 * reconhecido" no Comercial). O admin cadastra o mapping faltante e roda
 * este comando pelo id do evento para materializar o contrato, sem esperar
 * um novo webhook do HubSpot.
 *
 * Idempotente: reusa `HubspotCompanyMatcher` (não duplica company) + o
 * guard de `hubspot_line_item_id` em `persistirContratos` (não duplica
 * contrato). Rodar 2x é seguro.
 *
 * Uso:
 *   php artisan hubspot:reprocess-event {id}
 */
class ReprocessHubspotEvent extends Command
{
    protected $signature = 'hubspot:reprocess-event {id : ID do HubspotEvento a reprocessar}';

    protected $description = 'Reprocessa um HubspotEvento (replay idempotente do handoff HubSpot — HUB-REPLAY-01)';

    public function handle(HubspotWebhookController $controller, HubspotApiClient $api): int
    {
        $id     = (int) $this->argument('id');
        $evento = HubspotEvento::find($id);

        if (!$evento) {
            $this->error("HubspotEvento #{$id} não encontrado.");

            return self::FAILURE;
        }

        $this->info("[Hubspot] Replay: reprocessando evento #{$evento->id} (deal {$evento->object_id})...");

        $resumo = $controller->reprocessarEvento($evento, $api);

        $this->table(
            ['Campo', 'Valor'],
            [
                ['evento_id', $resumo['evento_id']],
                ['deal_id', $resumo['deal_id'] ?? '—'],
                ['company_id', $resumo['company_id'] ?? '—'],
                ['contratos_criados', $resumo['contratos_criados']],
                ['contratos_ignorados', $resumo['contratos_ignorados']],
                ['empresas_enriquecidas', $resumo['empresas_enriquecidas']],
                ['warnings', empty($resumo['warnings']) ? '—' : implode('; ', $resumo['warnings'])],
            ]
        );

        if ($resumo['company_id'] === null) {
            $this->error('[Hubspot] Replay: reprocessamento falhou — veja hubspot_eventos.erro_msg.');

            return self::FAILURE;
        }

        $this->info('[Hubspot] Replay: reprocessamento concluído.');

        return self::SUCCESS;
    }
}
