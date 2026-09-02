<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Hubspot\HubspotDealHandoffService;
use App\Services\Hubspot\HubspotOwnerResolver;
use App\Services\HubspotApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * hubspot:backfill-owner-venda — retroativo manual (Fase 138 Plano 04,
 * COMERC-02, D-10) das colunas `hubspot_owner_id`/`hubspot_owner_nome`/
 * `data_venda` (Fase 138 plano 03) para o acervo de empresas que já vieram
 * do HubSpot ANTES desta fase existir.
 *
 * Por que `hubspot:reenriquecer-handoff` / `hubspot:reprocess-event` NÃO
 * servem de retroativo do acervo:
 *  - `HubspotReenriquecerHandoff` só varre companies criadas nas ÚLTIMAS
 *    24 HORAS (`JANELA_MAXIMA_HORAS`) e com `hubspot_company_id`/
 *    `hubspot_contact_id` VAZIOS. Nenhuma empresa do acervo antigo bate
 *    nos dois filtros ao mesmo tempo — a janela de 24h por si só já exclui
 *    tudo que não nasceu hoje.
 *  - `ReprocessHubspotEvent` recebe `{id}` de UM `HubspotEvento` por
 *    execução — não é uma varredura, é reprocessamento pontual.
 *  D-10 pede "retroativo por comando manual" na letra — este comando é o
 *  que cumpre isso.
 *
 * Duas passagens INDEPENDENTES, sempre nesta ordem:
 *
 *  1. `data_venda` — SEM custo de API. `closedate` já está persistido
 *     dentro de `hubspot_snapshot.deal` para toda empresa que veio do
 *     HubSpot (o webhook sempre grava o snapshot completo do deal, mesmo
 *     antes desta fase existir). Lê a chave certa via
 *     `config('services.hubspot.props.deal.closedate')` e converte pela
 *     MESMA rotina do webhook — `HubspotDealHandoffService::parseDataHubspot()` —
 *     nunca um `Carbon::parse` novo e paralelo.
 *
 *  2. `hubspot_owner_id`/`hubspot_owner_nome` — COM custo de API. A
 *     property de owner é NOVA (Fase 138 plano 03) — nenhum snapshot
 *     antigo a contém. Exige um `fetchDeal()` de verdade por empresa com
 *     `hubspot_deal_id`; o nome é resolvido pelo `HubspotOwnerResolver`
 *     (cache de 7 dias — a ECF tem poucos vendedores, então N empresas
 *     custam poucos GETs de owner distintos). Falha de fetch de UM deal é
 *     logada e o laço CONTINUA — nunca aborta a varredura inteira.
 *
 * Empresa sem `hubspot_deal_id` (cadastro manual do Comercial) NUNCA entra
 * na passagem 2 — ela nunca teve deal no HubSpot, logo nunca terá owner
 * (138-RESEARCH.md, Pitfall 2). Contada à parte em `sem_deal_id`, para
 * ninguém reportar isso como bug depois.
 *
 * Este comando não escreve no campo de estágio da máquina de estados da
 * Fase 137 (D-14 desta fase / D-05 da 137): ele lê um dado que sempre
 * existiu no HubSpot e não afirma histórico nenhum — diferente do comando
 * de migração de estágio da Fase 137 (que carimba estágio), aqui não há
 * carimbo de espécie alguma.
 *
 * Seguro por construção:
 *  - **Dry-run é o padrão.** Sem `--apply` o comando só mostra o que faria
 *    (mesmo molde do comando de migração de estágio da Fase 137).
 *  - `chunkById(100)` nas DUAS passagens — nunca `get()` na tabela inteira.
 *
 * ⚠️ O STDOUT desta execução NÃO é prova
 * (`.planning/learnings/desempenho-bonificacao.md` §6/§10.1). A
 * conferência é por RECONSULTA direta ao banco — ver
 * `138-BACKFILL-OWNER-CONTAGENS.md`.
 */
class HubspotBackfillOwnerVenda extends Command
{
    protected $signature = 'hubspot:backfill-owner-venda
        {--apply : Grava de verdade. Sem esta flag o comando só mostra o que faria}';

    protected $description = 'Retroativo da Fase 138 das colunas hubspot_owner_id/hubspot_owner_nome/data_venda para o acervo já vindo do HubSpot (dry-run por padrão)';

    /** Tamanho do lote nas duas passagens — nunca `get()` na tabela inteira. */
    private const CHUNK = 100;

    public function handle(
        HubspotApiClient $api,
        HubspotOwnerResolver $resolver,
        HubspotDealHandoffService $handoffService,
    ): int {
        $apply = (bool) $this->option('apply');

        $propsDeal    = config('services.hubspot.props.deal');
        $closedateKey = $propsDeal['closedate'] ?? 'closedate';
        $ownerIdKey   = $propsDeal['owner_id'] ?? 'hubspot_owner_id';

        $this->info($apply
            ? 'Modo APPLY — gravando de verdade.'
            : 'MODO DRY-RUN — nada será gravado. Rode de novo com --apply para gravar.');

        $stats1 = $this->passagemDataVenda($apply, $closedateKey, $handoffService);
        $stats2 = $this->passagemOwner($apply, $ownerIdKey, $closedateKey, $api, $resolver, $handoffService);

        $this->info(sprintf(
            'Passagem 1 (data_venda, sem custo de API): %d candidata(s), %d gravada(s), %d sem closedate no snapshot.',
            $stats1['candidatas'],
            $stats1['gravadas'],
            $stats1['sem_closedate'],
        ));

        $this->info(sprintf(
            'Passagem 2 (owner, com custo de API): %d candidata(s) com hubspot_deal_id, %d gravada(s), %d falha(s) de fetch, %d sem_deal_id (fora da varredura — nunca terão owner).',
            $stats2['candidatas'],
            $stats2['gravadas'],
            $stats2['falhas'],
            $stats2['sem_deal_id'],
        ));

        $this->line('Este stdout NÃO é prova — confira por reconsulta direta ao banco (SELECT COUNT(*) ...).');

        return self::SUCCESS;
    }

    /**
     * Passagem 1 — `data_venda` a partir do `closedate` já persistido em
     * `hubspot_snapshot.deal`. Zero requisição HTTP.
     *
     * @return array{candidatas: int, gravadas: int, sem_closedate: int}
     */
    private function passagemDataVenda(bool $apply, string $closedateKey, HubspotDealHandoffService $handoffService): array
    {
        $candidatas   = 0;
        $gravadas     = 0;
        $semClosedate = 0;

        Company::query()
            ->whereNotNull('hubspot_snapshot')
            ->whereNull('data_venda')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($empresas) use (&$candidatas, &$gravadas, &$semClosedate, $apply, $closedateKey, $handoffService) {
                foreach ($empresas as $company) {
                    $candidatas++;

                    $closedateRaw = $company->hubspot_snapshot['deal'][$closedateKey] ?? null;
                    $dataVenda    = $handoffService->parseDataHubspot($closedateRaw);

                    if ($dataVenda === null) {
                        $semClosedate++;
                        continue;
                    }

                    if ($apply) {
                        $company->update(['data_venda' => $dataVenda]);
                    }

                    $gravadas++;
                }
            });

        return ['candidatas' => $candidatas, 'gravadas' => $gravadas, 'sem_closedate' => $semClosedate];
    }

    /**
     * Passagem 2 — `hubspot_owner_id`/`hubspot_owner_nome` via `fetchDeal()`
     * real por empresa com `hubspot_deal_id`. Falha de fetch de UM deal é
     * logada e o laço CONTINUA (nunca aborta a varredura inteira).
     *
     * O fetch pede TAMBÉM `closedate` (além da property de owner) como
     * rede de segurança para `data_venda`: uma empresa com `hubspot_deal_id`
     * mas sem `hubspot_snapshot` (registro anterior à Fase 111, quando o
     * snapshot passou a existir) nunca é candidata da Passagem 1 e ficaria
     * sem `data_venda` para sempre — aqui, se `data_venda` ainda está
     * `null`, o valor lido deste fetch entra pela MESMA rotina de conversão
     * (`HubspotDealHandoffService::parseDataHubspot()`).
     *
     * Em DRY-RUN esta passagem NÃO dispara nenhum `fetchDeal()` — diferente
     * da Passagem 1, que é sempre zero-custo, esta tem custo de API por
     * natureza, e um dry-run que ainda assim gastasse a cota de requisições
     * deixaria de ser uma prévia segura. Sem `--apply`, o sumário mostra só
     * a contagem de candidatas (COUNT puro, sem tocar HTTP); "gravadas" só
     * é conhecido rodando de verdade.
     *
     * @return array{candidatas: int, gravadas: int, falhas: int, sem_deal_id: int}
     */
    private function passagemOwner(
        bool $apply,
        string $ownerIdKey,
        string $closedateKey,
        HubspotApiClient $api,
        HubspotOwnerResolver $resolver,
        HubspotDealHandoffService $handoffService,
    ): array {
        $baseQuery = fn () => Company::query()
            ->whereNotNull('hubspot_deal_id')
            ->whereNull('hubspot_owner_id');

        $semDealId = Company::query()
            ->whereNull('hubspot_deal_id')
            ->whereNull('hubspot_owner_id')
            ->count();

        if (! $apply) {
            return [
                'candidatas'  => $baseQuery()->count(),
                'gravadas'    => 0,
                'falhas'      => 0,
                'sem_deal_id' => $semDealId,
            ];
        }

        $candidatas = 0;
        $gravadas   = 0;
        $falhas     = 0;

        $baseQuery()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($empresas) use (&$candidatas, &$gravadas, &$falhas, $ownerIdKey, $closedateKey, $api, $resolver, $handoffService) {
                foreach ($empresas as $company) {
                    $candidatas++;

                    try {
                        $deal = $api->fetchDeal((string) $company->hubspot_deal_id, [$ownerIdKey, $closedateKey]);
                    } catch (\Throwable $e) {
                        $falhas++;
                        // T-138-14 — só company_id/hubspot_deal_id/mensagem de
                        // erro (sem token, sem corpo de resposta cru).
                        Log::channel('ecf-webhooks')->warning('[HubspotBackfillOwnerVenda] falha ao buscar deal — empresa pulada, varredura continua', [
                            'company_id'      => $company->id,
                            'hubspot_deal_id' => $company->hubspot_deal_id,
                            'erro'            => $e->getMessage(),
                        ]);
                        continue;
                    }

                    $ownerIdRaw = $deal['properties'][$ownerIdKey] ?? null;
                    $ownerId    = ($ownerIdRaw !== null && (string) $ownerIdRaw !== '') ? (string) $ownerIdRaw : null;

                    // Rede de segurança de data_venda (ver docblock do método) —
                    // só entra se a Passagem 1 não conseguiu (sem snapshot).
                    $dataVendaFallback = null;
                    if ($company->data_venda === null) {
                        $closedateRaw      = $deal['properties'][$closedateKey] ?? null;
                        $dataVendaFallback = $handoffService->parseDataHubspot($closedateRaw);
                    }

                    if ($ownerId === null && $dataVendaFallback === null) {
                        // Deal sem owner atribuído e sem closedate novo — nada a
                        // gravar, não é falha.
                        continue;
                    }

                    $ownerNome = $ownerId !== null ? $resolver->resolverNome($ownerId) : null;

                    $updates = [];
                    if ($ownerId !== null) {
                        $updates['hubspot_owner_id']   = $ownerId;
                        $updates['hubspot_owner_nome'] = $ownerNome;
                    }
                    if ($dataVendaFallback !== null) {
                        $updates['data_venda'] = $dataVendaFallback;
                    }
                    $company->update($updates);

                    $gravadas++;
                }
            });

        return ['candidatas' => $candidatas, 'gravadas' => $gravadas, 'falhas' => $falhas, 'sem_deal_id' => $semDealId];
    }
}
