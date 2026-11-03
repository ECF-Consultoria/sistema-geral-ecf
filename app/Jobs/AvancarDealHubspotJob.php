<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Services\HubspotApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AvancarDealHubspotJob — Quick 261103-hsf (DEV-55).
 *
 * Move o deal da empresa no Pipeline Consultoria do HubSpot quando o
 * contrato anda no ECF Admin:
 *  - DESTINO_CONTRATO_ENVIADO → contrato enviado ao cliente (aguardando assinaturas)
 *  - DESTINO_GANHO            → contrato assinado
 *
 * Regras (todas com saída silenciosa + log, nunca exceção — não há o que
 * tentar de novo):
 *  - chave `services.hubspot.avancar_deal_ativo` desligada → não faz nada;
 *  - empresa sem deal conhecido → não faz nada;
 *  - deal fora do Pipeline Consultoria (Polos, Infoprodutos, Imersão) → não
 *    mexe: as colunas novas só existem lá;
 *  - só AVANÇA. Deal já no destino, já em Ganho ou em Perdido fica onde está
 *    — Perdido é decisão humana do Comercial, o sistema não desfaz.
 *
 * Qual deal: o do ÚLTIMO `HubspotEvento` processado que criou/enriqueceu a
 * empresa. `companies.hubspot_deal_id` é só fallback, porque o enriquecimento
 * de empresa já existente segue "só preenche se vazio" — um cliente antigo
 * que fecha um deal novo continua com o deal ANTIGO nessa coluna, e mover o
 * deal antigo seria mexer no negócio errado.
 *
 * Erro de API (5xx, rede, 429) lança e cai no retry da fila.
 */
class AvancarDealHubspotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DESTINO_CONTRATO_ENVIADO = 'contrato_enviado';
    public const DESTINO_GANHO            = 'ganho';

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly int $companyId,
        public readonly string $destino,
        public readonly ?int $contratoAssinaturaId = null,
    ) {
        // Fila `high`: na `default` jobs longos de sync já seguraram webhook
        // por horas (16/09). O Comercial está olhando o pipeline esperando a
        // coluna mudar.
        $this->onQueue('high');
        // O status do contrato é salvo dentro de transação no processamento
        // do webhook da Clicksign — só despacha depois do commit.
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(HubspotApiClient $api): void
    {
        $ctx = [
            'company_id'  => $this->companyId,
            'destino'     => $this->destino,
            'contrato_id' => $this->contratoAssinaturaId,
        ];

        if (! config('services.hubspot.avancar_deal_ativo')) {
            Log::channel('ecf-webhooks')->info('[HubSpot Avançar Deal] Desligado por config — nada feito', $ctx);
            return;
        }

        $stageDestino = match ($this->destino) {
            self::DESTINO_CONTRATO_ENVIADO => (string) config('services.hubspot.stage_contrato_enviado_id'),
            self::DESTINO_GANHO            => (string) config('services.hubspot.stage_ganho_consultoria_id'),
            default                        => null,
        };

        if ($stageDestino === null || $stageDestino === '') {
            Log::channel('ecf-webhooks')->error('[HubSpot Avançar Deal] Destino desconhecido', $ctx);
            return;
        }

        $dealId = $this->resolverDealId();
        if ($dealId === null) {
            Log::channel('ecf-webhooks')->info('[HubSpot Avançar Deal] Empresa sem deal do HubSpot — nada feito', $ctx);
            return;
        }
        $ctx['deal_id'] = $dealId;

        $deal     = $api->fetchDeal($dealId, ['dealstage', 'pipeline']);
        $pipeline = (string) ($deal['properties']['pipeline'] ?? '');
        $atual    = (string) ($deal['properties']['dealstage'] ?? '');
        $ctx['pipeline'] = $pipeline;
        $ctx['stage_atual'] = $atual;

        if ($pipeline !== (string) config('services.hubspot.pipeline_consultoria_id')) {
            Log::channel('ecf-webhooks')->info('[HubSpot Avançar Deal] Deal fora do Pipeline Consultoria — não mexido', $ctx);
            return;
        }

        if (! $this->podeAvancar($atual, $stageDestino)) {
            Log::channel('ecf-webhooks')->info('[HubSpot Avançar Deal] Deal já no destino, adiante ou perdido — não mexido', $ctx);
            return;
        }

        $api->atualizarEtapaDoDeal($dealId, $stageDestino);

        Log::channel('ecf-webhooks')->info('[HubSpot Avançar Deal] Deal movido', $ctx + ['stage_novo' => $stageDestino]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('ecf-webhooks')->error('[HubSpot Avançar Deal] Falhou de vez — mover o deal à mão no HubSpot', [
            'company_id'  => $this->companyId,
            'destino'     => $this->destino,
            'contrato_id' => $this->contratoAssinaturaId,
            'erro'        => mb_substr($e->getMessage(), 0, 500),
        ]);
    }

    /**
     * Deal do último evento processado da empresa; fallback na coluna.
     */
    private function resolverDealId(): ?string
    {
        $doEvento = HubspotEvento::where('company_id_criada', $this->companyId)
            ->where('status', 'processado')
            ->whereNotNull('object_id')
            ->orderByDesc('id')
            ->value('object_id');

        if (filled($doEvento)) {
            return (string) $doEvento;
        }

        $daColuna = Company::whereKey($this->companyId)->value('hubspot_deal_id');

        return filled($daColuna) ? (string) $daColuna : null;
    }

    /**
     * Só avança: nunca reescreve o destino, nunca tira de Ganho, nunca tira
     * de Perdido. Enviado não volta Ganho para Enviado (assinatura que chegou
     * antes do evento de envio, reprocessamento fora de ordem).
     */
    private function podeAvancar(string $atual, string $destino): bool
    {
        $ganho   = (string) config('services.hubspot.stage_ganho_consultoria_id');
        $perdido = (string) config('services.hubspot.stage_perdido_consultoria_id');

        return ! in_array($atual, [$destino, $ganho, $perdido], true);
    }
}
