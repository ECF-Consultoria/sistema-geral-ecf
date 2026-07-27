<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\HubspotWebhookController;
use App\Models\Company;
use App\Services\HubspotApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Debug session hubspot-handoff-sem-contatos (2026-07-27).
 *
 * NOTA (correção de diagnóstico): a causa raiz REAL foi um bug de field-name no
 * HubspotApiClient (lia `toObjectId` num endpoint v3 que retorna `id`) — não uma
 * race condition. O core fix está no HubspotApiClient. Esta varredura é a REDE DE
 * SEGURANÇA / BACKFILL: reprocessa handoffs que ficaram incompletos (os já
 * quebrados pelo bug + qualquer miss futuro), reusando o replay idempotente.
 *
 * (Texto histórico abaixo, mantido por contexto — a hipótese de race foi superada.)
 * Fix sistêmico pro race condition/eventual consistency do HubSpot.
 *
 * Causa raiz: o webhook `deal.propertyChange` (dealstage=closedwon) chega e
 * é processado de forma SÍNCRONA quase instantaneamente após o fechamento
 * do deal, mas as associações deal→company e deal→contacts (e a própria
 * atualização dos objetos company/contact no HubSpot) só ficam consultáveis
 * via API segundos depois (7s–32s observado no caso real Hollyfield). O
 * resultado: a Company nasce em `criarEmpresa()` com hubspot_company_id e/ou
 * hubspot_contact_id vazios — e, por consequência, sem email/telefone/
 * nome_contato.
 *
 * Este comando varre companies de origem HubSpot (via a relação
 * `hubspotEventoOrigem` — Fase 37 Plan 37-05) criadas nas últimas ~24h que
 * ainda estão com hubspot_company_id OU hubspot_contact_id vazios, e chama
 * `HubspotWebhookController::reprocessarEvento()` (o MESMO mecanismo de
 * replay testado do `hubspot:reprocess-event`) pra cada uma — reaproveitando
 * 100% da lógica de fetch/match/enriquecimento, sem duplicar código.
 *
 * Guarda contra sobrescrever edição manual do Comercial: `criarEmpresa()`
 * roteia pra `enriquecerEmpresaExistente()` quando o match é FORTE — esse
 * método só preenche colunas AINDA vazias (nunca sobrescreve dado já
 * preenchido, seja por humano ou pela 1ª tentativa do webhook). O match
 * FORTE aqui é garantido pelo critério `existing_company_id` adicionado no
 * `HubspotCompanyMatcher` (ver docblock da classe) — o replay de um evento
 * já vinculado (`company_id_criada`) SEMPRE volta pra essa mesma company,
 * mesmo que hubspot_company_id/cnpj/email/domain/nome ainda não batam.
 *
 * Guarda contra reprocessamento excessivo/loop: só elegíveis companies
 * criadas há mais de 2 minutos (dá tempo ao HubSpot assentar as associações)
 * e dentro da janela de 24h. Um reprocessamento bem-sucedido preenche
 * hubspot_company_id/hubspot_contact_id — a company sai do filtro de
 * seleção na próxima varredura (idempotente).
 *
 * Agendado em routes/console.php a cada 3min com withoutOverlapping().
 *
 * Uso manual (dry-run pra inspecionar candidatas sem reprocessar):
 *   php artisan hubspot:reenriquecer-handoff --dry-run
 */
class HubspotReenriquecerHandoff extends Command
{
    protected $signature = 'hubspot:reenriquecer-handoff
        {--dry-run : Apenas lista as companies candidatas, sem reprocessar}';

    protected $description = 'Varredura agendada: reprocessa companies de origem HubSpot com hubspot_company_id/contact_id vazios (fix do race condition de associações — debug hubspot-handoff-sem-contatos)';

    /** Tempo mínimo desde a criação — dá margem ao HubSpot assentar as associações. */
    private const JANELA_MINIMA_MINUTOS = 2;

    /** Janela máxima de varredura — não revisita companies antigas indefinidamente. */
    private const JANELA_MAXIMA_HORAS = 24;

    public function handle(HubspotWebhookController $controller, HubspotApiClient $api): int
    {
        $candidatas = Company::query()
            ->whereHas('hubspotEventoOrigem')
            ->with('hubspotEventoOrigem')
            ->where(function ($q) {
                $q->whereNull('hubspot_company_id')
                    ->orWhereNull('hubspot_contact_id');
            })
            ->where('created_at', '<=', now()->subMinutes(self::JANELA_MINIMA_MINUTOS))
            ->where('created_at', '>=', now()->subHours(self::JANELA_MAXIMA_HORAS))
            ->get();

        if ($candidatas->isEmpty()) {
            $this->info('[Hubspot] Reenriquecimento: nenhuma company candidata nesta varredura.');
            return self::SUCCESS;
        }

        $this->info("[Hubspot] Reenriquecimento: {$candidatas->count()} company(ies) candidata(s).");

        if ($this->option('dry-run')) {
            $this->table(
                ['company_id', 'nome', 'evento_id', 'hubspot_company_id', 'hubspot_contact_id', 'criada_em'],
                $candidatas->map(fn (Company $c) => [
                    $c->id,
                    $c->name,
                    $c->hubspotEventoOrigem?->id,
                    $c->hubspot_company_id ?? '—',
                    $c->hubspot_contact_id ?? '—',
                    (string) $c->created_at,
                ])->all()
            );
            return self::SUCCESS;
        }

        $enriquecidas = 0;
        $falhas       = 0;

        foreach ($candidatas as $company) {
            $evento = $company->hubspotEventoOrigem;
            if (!$evento) {
                continue;
            }

            $resumo = $controller->reprocessarEvento($evento, $api);

            if ($resumo['company_id'] === null) {
                $falhas++;
                Log::channel('ecf-webhooks')->warning('[Hubspot] Reenriquecimento: falha ao reprocessar', [
                    'company_id' => $company->id,
                    'evento_id'  => $evento->id,
                    'warnings'   => $resumo['warnings'],
                ]);
                continue;
            }

            $company->refresh();
            $preenchida = $company->hubspot_company_id !== null && $company->hubspot_contact_id !== null;
            if ($preenchida) {
                $enriquecidas++;
            }

            Log::channel('ecf-webhooks')->info('[Hubspot] Reenriquecimento: company reprocessada', [
                'company_id'  => $company->id,
                'evento_id'   => $evento->id,
                'preenchida'  => $preenchida,
            ]);
        }

        $this->info("[Hubspot] Reenriquecimento concluído: {$enriquecidas} enriquecida(s), {$falhas} falha(s), " . ($candidatas->count() - $enriquecidas - $falhas) . ' ainda incompleta(s).');

        return self::SUCCESS;
    }
}
