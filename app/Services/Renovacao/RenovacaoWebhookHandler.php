<?php

namespace App\Services\Renovacao;

use App\Models\ContratoRenovacao;
use App\Models\HubspotEvento;
use App\Services\HubspotApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (REN-05) — eventos de negócio do pipeline "Renovações contrato".
 *
 * Este caminho NUNCA cria empresa nem contrato: é a proteção contra o loop
 * apontado na auditoria (CONTEXT, "RISCO DE LOOP") — um ganho de renovação
 * entrando no fluxo de "venda ganha" duplicaria empresa/contrato.
 *
 * O ganho estende o contrato mesmo com a automação desligada, mas só para
 * ciclo já vinculado (negocio_renovacao_id) ou achado pela chave de correlação:
 * o webhook nunca adivinha empresa por nome.
 */
class RenovacaoWebhookHandler
{
    public function __construct(private RenovacaoExtensaoService $extensao, private RenovacaoDetector $detector) {}

    /** IDs de etapa do HubSpot são únicos na conta: a etapa identifica o pipeline. */
    public function ehEtapaDeRenovacao(?string $stage): bool
    {
        $stage = trim((string) $stage);

        if ($stage === '') {
            return false;
        }

        return in_array($stage, $this->etapasConhecidas(), true);
    }

    /** Negócio com a property de correlação preenchida é negócio de renovação. */
    public function ehNegocioDeRenovacao(array $dealProps): bool
    {
        $chave = (string) config('services.hubspot.renovacao.propriedade_chave');

        return $chave !== '' && trim((string) ($dealProps[$chave] ?? '')) !== '';
    }

    public function tratar(HubspotEvento $evento, HubspotApiClient $api): void
    {
        try {
            $ciclo = ContratoRenovacao::where('negocio_renovacao_id', (string) $evento->object_id)->first();

            if (! $ciclo) {
                $ciclo = $this->acharPelaCorrelacao($evento, $api);
            }

            if (! $ciclo) {
                $this->encerrar($evento, 'ignorado', 'Negócio de renovação sem ciclo no ECF');
                Log::channel('ecf-webhooks')->error("[Renovacao] evento {$evento->id}: negócio {$evento->object_id} sem ciclo no ECF");

                return;
            }

            $etapa = trim((string) $evento->property_value);

            if (! $this->ehEtapaDeRenovacao($etapa)) {
                // Negócio de renovação arrastado para etapa de outro pipeline: nada a fazer aqui.
                $ciclo->registrarEvento('etapa_fora_do_pipeline', 'webhook', null, ['etapa' => $etapa, 'evento_id' => $evento->id]);
                $this->encerrar($evento, 'ignorado', 'Negócio de renovação em etapa fora do pipeline de renovação');

                return;
            }

            $ciclo->update([
                'etapa_observada'    => $etapa,
                'pipeline_observado' => (string) config('services.hubspot.renovacao.pipeline_id'),
            ]);

            $final = in_array($ciclo->status_comercial, ContratoRenovacao::STATUS_COMERCIAIS_FINAIS, true);
            $cfg   = config('services.hubspot.renovacao');

            if ($etapa === (string) $cfg['stage_fechado_id']) {
                // Término pode ter sido corrigido depois da detecção.
                $this->detector->sincronizarTermino($ciclo->fresh(), 'webhook');
                $this->extensao->registrarGanho($ciclo->fresh(), 'webhook', ['evento_id' => $evento->id]);
            } elseif ($etapa === (string) $cfg['stage_negociando_id']) {
                if (! $final) {
                    $ciclo->update(['status_comercial' => ContratoRenovacao::STATUS_COMERCIAL_EM_NEGOCIACAO]);
                    $ciclo->registrarEvento('em_negociacao', 'webhook', null, ['evento_id' => $evento->id]);
                }
            } elseif (in_array($etapa, (array) $cfg['stages_perdido_ids'], true)) {
                if (! $final) {
                    $ciclo->update(['status_comercial' => ContratoRenovacao::STATUS_COMERCIAL_NAO_RENOVADO]);
                    $ciclo->registrarEvento('nao_renovado', 'webhook', null, ['evento_id' => $evento->id]);
                }
            }

            $this->encerrar($evento, 'processado');
        } catch (\Throwable $e) {
            Log::channel('ecf-webhooks')->error("[Renovacao] evento {$evento->id} (negócio {$evento->object_id}): {$e->getMessage()}");
            $this->encerrar($evento, 'erro', mb_substr($e->getMessage(), 0, 500));
        }
    }

    private function acharPelaCorrelacao(HubspotEvento $evento, HubspotApiClient $api): ?ContratoRenovacao
    {
        $prop = (string) config('services.hubspot.renovacao.propriedade_chave');
        $deal = $api->fetchDeal((string) $evento->object_id, ['pipeline', 'dealstage', $prop]);
        $chave = trim((string) ($deal['properties'][$prop] ?? ''));

        if ($chave === '') {
            return null;
        }

        $ciclo = ContratoRenovacao::where('chave_correlacao', $chave)->first();

        if (! $ciclo) {
            return null;
        }

        try {
            $ciclo->update(['negocio_renovacao_id' => (string) $evento->object_id]);
        } catch (QueryException $e) {
            // Unicidade violada: outro ciclo já guarda este negócio.
            throw new \RuntimeException('Negócio de renovação já vinculado a outro ciclo');
        }

        $ciclo->registrarEvento('vinculado_por_correlacao', 'webhook', null, ['negocio' => (string) $evento->object_id]);

        return $ciclo;
    }

    private function etapasConhecidas(): array
    {
        $cfg = (array) config('services.hubspot.renovacao');

        return array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            array_merge(
                [$cfg['stage_inicial_id'] ?? '', $cfg['stage_negociando_id'] ?? '', $cfg['stage_fechado_id'] ?? ''],
                (array) ($cfg['stages_perdido_ids'] ?? []),
            ),
        )));
    }

    private function encerrar(HubspotEvento $evento, string $status, ?string $msg = null): void
    {
        $evento->update(['status' => $status, 'erro_msg' => $msg, 'processado_em' => now()]);
    }
}
