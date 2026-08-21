<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use App\Notifications\EmpresaHubspotPendenteNotification;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use App\Services\Hubspot\HubspotCompanyMatcher;
use App\Services\Hubspot\HubspotContactSelector;
use App\Services\Hubspot\HubspotDealHandoffService;
use App\Services\Hubspot\HubspotHandoffData;
use App\Services\Hubspot\HubspotNameNormalizer;
use App\Services\HubspotApiClient;
use App\Services\Operacional\EmpresaOperacionalRouter;
use App\Support\AudienciaComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 34 Plan 34-04 — Receiver POST /api/webhooks/hubspot.
 *
 * Fluxo (D-03 + D-04):
 *  1. Le raw body com $request->getContent() — bytes precisam bater com HMAC
 *  2. Valida X-HubSpot-Request-Timestamp (rejeita > 5min de diferenca — replay)
 *  3. Valida X-HubSpot-Signature-v3: base64(hmac_sha256(secret, METHOD+URI+body+ts))
 *     com hash_equals timing-safe
 *  4. Qualquer falha de validacao: grava HubspotEvento(signature_valid=false,
 *     status=erro, payload contendo raw truncado em 65KB) e retorna 401 sem
 *     vazar detalhes — para nao expor secret/cipher info ao caller
 *  5. Sucesso: decodifica payload (HubSpot manda array de eventos) e processa
 *     1 evento por vez, gerando 1 HubspotEvento por evento
 *
 * Processamento (por evento):
 *  - Filtra: subscription_type='deal.propertyChange' && property_name='dealstage'
 *    && property_value=config('services.hubspot.stage_fechado_ganho_id')
 *    Demais sao marcados status=ignorado.
 *  - Idempotencia: pula se HubspotEvento ja existe com mesmo object_id +
 *    company_id_criada NOT NULL (D-04).
 *  - fetchDeal + fetchAssociatedCompanyId + fetchCompany via HubspotApiClient.
 *  - Cria Company em DB::transaction com empresa_nova=true, status='pendente'.
 *    Se nome do servico bate com Servico::where('nome', X)->first() ativo →
 *    cria ContratoServico. Caso contrario grava o nome como pendencia no
 *    payload do evento (quick task 260805-ohs; antes ia pra notes da Company).
 *  - Erro inesperado: status='erro' + erro_msg, retorna 200 (HubSpot nao retenta).
 *
 * Seguranca:
 *  - secret NUNCA logado (so o ip e tamanho do payload)
 *  - raw body truncado em 65KB ao gravar evento invalido (evita estourar disco)
 *  - Throttle 60/min na rota (vide routes/web.php)
 *  - CSRF isento via bootstrap/app.php (api/webhooks/*) + withoutMiddleware
 */
class HubspotWebhookController extends Controller
{
    /** Tolerancia de replay: 5 minutos em milissegundos. */
    private const REPLAY_WINDOW_MS = 5 * 60 * 1000;

    /** Truncamento defensivo do raw body ao gravar evento invalido. */
    private const RAW_BODY_MAX_BYTES = 65_000;

    public function receive(Request $request, HubspotApiClient $api): JsonResponse
    {
        $rawBody = $request->getContent();
        $secret  = (string) config('services.hubspot.client_secret');

        $sigHdr = (string) $request->header('X-HubSpot-Signature-v3', '');
        $tsHdr  = (string) $request->header('X-HubSpot-Request-Timestamp', '');

        // ── 1. Valida timestamp (replay window 5min) ─────────────────────────
        $ts = (int) $tsHdr;
        if ($ts < 1 || abs((int) (microtime(true) * 1000) - $ts) > self::REPLAY_WINDOW_MS) {
            $this->gravarInvalido($rawBody, 'timestamp invalido ou ausente', $request);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // ── 2. Calcula HMAC esperado: base64(hmac_sha256(secret, METHOD+URI+body+ts)) ─
        $methodUriBody = $request->method() . $request->fullUrl() . $rawBody . $tsHdr;
        $expected      = base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));

        if ($sigHdr === '' || !hash_equals($expected, $sigHdr)) {
            $this->gravarInvalido($rawBody, 'signature invalida ou ausente', $request);
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // ── 3. Decodifica payload (HubSpot manda array de eventos) ───────────
        $eventos = json_decode($rawBody, true);
        if (!is_array($eventos)) {
            $this->gravarInvalido($rawBody, 'json invalido', $request);
            return response()->json(['error' => 'bad payload'], 400);
        }

        // HubSpot legitimo manda sempre array; tolera objeto unico por seguranca.
        if (isset($eventos['objectId']) || isset($eventos['subscriptionType'])) {
            $eventos = [$eventos];
        }

        // ── 4. Cria 1 HubspotEvento por evento e processa ─────────────────────
        foreach ($eventos as $evt) {
            if (!is_array($evt)) {
                continue;
            }
            $evento = HubspotEvento::create([
                'signature_valid'   => true,
                'portal_id'         => isset($evt['portalId']) ? (string) $evt['portalId'] : null,
                'object_type'       => $evt['objectType'] ?? null,
                'object_id'         => isset($evt['objectId']) ? (string) $evt['objectId'] : null,
                'subscription_type' => $evt['subscriptionType'] ?? null,
                'property_name'     => $evt['propertyName'] ?? null,
                'property_value'    => isset($evt['propertyValue']) ? (string) $evt['propertyValue'] : null,
                'payload'           => $evt,
                'status'            => 'recebido',
            ]);

            $this->processar($evento, $api);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Filtra + idempotencia + fetch HubSpot + cria Company.
     * Em qualquer erro grava status=erro e retorna (controller responde 200).
     */
    private function processar(HubspotEvento $evento, HubspotApiClient $api): void
    {
        // Phase 34 hotfix — aceita CSV de stage IDs porque a conta HubSpot tem
        // multiplos pipelines (Polos / Infoprodutos / Sales default) e cada um
        // tem seu proprio dealstage id de "Fechado Ganho".
        $stagesGatilho = collect(explode(',', (string) config('services.hubspot.stage_fechado_ganho_id')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->all();

        // ── Filtro: so processa deal.propertyChange + dealstage ∈ stagesGatilho ─
        if (
            $evento->subscription_type !== 'deal.propertyChange'
            || $evento->property_name !== 'dealstage'
            || !in_array((string) $evento->property_value, $stagesGatilho, true)
        ) {
            $evento->update(['status' => 'ignorado', 'processado_em' => now()]);
            return;
        }

        // ── Idempotencia: deal ja processado em evento anterior? ────────────
        $jaProcessado = HubspotEvento::where('object_id', $evento->object_id)
            ->where('id', '!=', $evento->id)
            ->whereNotNull('company_id_criada')
            ->exists();

        if ($jaProcessado) {
            $evento->update([
                'status'         => 'ignorado',
                'erro_msg'       => 'Deal ja processado em evento anterior (idempotencia D-04)',
                'processado_em'  => now(),
            ]);
            return;
        }

        try {
            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Iniciando processamento', [
                'evento_id'         => $evento->id,
                'deal_id'           => $evento->object_id,
                'subscription_type' => $evento->subscription_type,
                'property_value'    => $evento->property_value,
            ]);

            $propsDeal    = config('services.hubspot.props.deal');
            $propsCompany = config('services.hubspot.props.company');
            $propsContact = config('services.hubspot.props.contact');

            $deal       = $api->fetchDeal((string) $evento->object_id, array_merge(
                ['dealname', 'amount', 'dealstage'],
                array_values($propsDeal),
            ));
            $companyId  = $api->fetchAssociatedCompanyId((string) $evento->object_id);
            $hubCompany = $companyId ? $api->fetchCompany($companyId, array_values($propsCompany)) : null;

            // Fase 113 Plan 113-02 (HUB-CONTATO-01) — fetch BATCH de TODOS os
            // contatos vinculados ao deal (troca do fetch singular
            // fetchAssociatedContactId/fetchContact da Fase 35). Falha do GET
            // ou ausencia de contatos: segue silencioso (warning), nao bloqueia
            // o cadastro porque deal+company sao o minimo viavel. Cada contato
            // e normalizado com chaves LOGICAS (id/firstname/lastname/email/
            // phone/mobilephone/jobtitle) via $propsContact, e
            // HubspotContactSelector (Fase 113 plano 01) escolhe o principal
            // de forma deterministica entre todos.
            $contatos = [];
            try {
                $contactIds = $api->fetchAssociatedContactIds((string) $evento->object_id);
                if (!empty($contactIds)) {
                    $mapaContatos = $api->fetchContacts($contactIds, array_values($propsContact));
                    foreach ($mapaContatos as $contactId => $props) {
                        $contatos[] = [
                            'id'             => (string) $contactId,
                            'firstname'      => $props[$propsContact['firstname'] ?? 'firstname'] ?? null,
                            'lastname'       => $props[$propsContact['lastname'] ?? 'lastname'] ?? null,
                            'email'          => $props[$propsContact['email'] ?? 'email'] ?? null,
                            'phone'          => $props[$propsContact['phone'] ?? 'phone'] ?? null,
                            'mobilephone'    => $props[$propsContact['mobilephone'] ?? 'mobilephone'] ?? null,
                            'jobtitle'       => $props[$propsContact['jobtitle'] ?? 'jobtitle'] ?? null,
                            // Quick task 260805-eqk — origem do lead vive no CONTATO.
                            'origem_do_lead' => $props[$propsContact['origem_do_lead'] ?? 'origem_do_lead'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Falha ao buscar contatos vinculados (batch)', [
                    'evento_id' => $evento->id,
                    'msg'       => $e->getMessage(),
                ]);
            }
            $contatoPrincipal = HubspotContactSelector::selecionar($contatos);

            // ── Quick task 260805-eqk — Notes (observacoes) do deal ────────────
            // A property `observacao` do deal nao existe na conta da ECF; as
            // observacoes reais sao Notes associadas ao DEAL. Mesmo padrao
            // resiliente do bloco de contatos: falha so gera warning e segue
            // com [] — nunca quebra o webhook.
            $notes = [];
            try {
                $notes = $api->fetchNotes('deals', (string) $evento->object_id);
            } catch (\Throwable $e) {
                Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Falha ao buscar notes do deal', [
                    'evento_id' => $evento->id,
                    'msg'       => $e->getMessage(),
                ]);
            }

            // Phase 37 Plan 37-04 (REQ-37-04) — busca line items do deal.
            // Quando vazio, criarEmpresa cai no fluxo legado (Servico::where nome
            // do deal + amount). Quando >=1 line item retornado, processarLineItems
            // tem prioridade total sobre o servico_ecf do deal.
            $lineItems = $api->fetchDealLineItems((string) $evento->object_id);

            $company = $this->criarEmpresa($deal, $hubCompany, $contatoPrincipal, $contatos, $companyId, $lineItems, $evento, notes: $notes);

            $evento->update([
                'status'            => 'processado',
                'company_id_criada' => $company->id,
                'processado_em'     => now(),
            ]);

            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Empresa criada', [
                'evento_id'        => $evento->id,
                'company_id'       => $company->id,
                'object_id'        => $evento->object_id,
                'line_items_total' => count($lineItems),
            ]);

            // ── Phase 35 Plan 35-03 (D-06) — notifica Comercial se tem pendencias ─
            // Disparado APOS o commit da criarEmpresa (transaction ja fechou) e
            // APOS o evento ser marcado processado — falha no dispatch nao desfaz
            // o estado consistente. Idempotencia natural: 2o webhook do mesmo deal
            // cai como ignorado na guarda acima (linha ~148), nao reentra aqui.
            $this->notificarComercialSePendente($company, $evento);

            // ── Fase 128 (REDE-06) — gate administrativo de contrato ───────────
            // FORA da transaction acima, de proposito: uma excecao aqui (ex.:
            // QueryException da trava composta em corrida) nao pode desfazer a
            // Company/ContratoServico ja commitados — a empresa TEM que chegar
            // ao operacional independente do desfecho do gate. dispararSeElegivel()
            // ja engole \Throwable internamente (plano 03), entao nenhum try/catch
            // redundante aqui — isso esconderia regressao no proprio service.
            // refresh() garante que contratosServico lido pelo gate reflete os
            // registros criados dentro da transaction (a colecao em memoria de
            // $company pode nao contar com eles).
            $company->refresh();
            app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);
        } catch (\Throwable $e) {
            $evento->update([
                'status'        => 'erro',
                'erro_msg'      => mb_substr($e->getMessage(), 0, 1000),
                'processado_em' => now(),
            ]);

            Log::channel('ecf-webhooks')->error('[HubSpot Webhook] Falha no processamento', [
                'evento_id' => $evento->id,
                'object_id' => $evento->object_id,
                'erro'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fase 114 Plan 03 (HUB-REPLAY-01) — reprocessa um HubspotEvento ja
     * recebido, para o comando `hubspot:reprocess-event`.
     *
     * Refaz o MESMO bloco de fetch de processar() (deal + company + contatos
     * batch + line items) e chama criarEmpresa() — reusando 100% o dedup
     * (HubspotCompanyMatcher) e o guard anti-duplicidade de contrato
     * (persistirContratos por hubspot_line_item_id). Difere de processar()
     * em 2 pontos DE PROPOSITO:
     *  - NAO aplica o filtro de dealstage (o evento ja passou por isso, ou o
     *    admin quer forcar o reprocessamento independente do estado atual);
     *  - NAO aplica o early-return de idempotencia de INGESTAO (jaProcessado)
     *    — essa guarda existe para nao reprocessar o MESMO webhook 2x, mas o
     *    replay e sempre um reprocessamento INTENCIONAL disparado pelo admin.
     * A idempotencia de DADOS (nao duplicar company/contrato) continua
     * garantida pelo dedup/guard dentro de criarEmpresa/persistirContratos.
     *
     * @return array{
     *     evento_id: int,
     *     deal_id: string|null,
     *     company_id: int|null,
     *     contratos_criados: int,
     *     contratos_ignorados: int,
     *     empresas_enriquecidas: int,
     *     warnings: array<int, string>,
     * }
     */
    public function reprocessarEvento(HubspotEvento $evento, HubspotApiClient $api): array
    {
        $warnings = [];

        Log::channel('ecf-webhooks')->info('[Hubspot] Replay: iniciando reprocessamento', [
            'evento_id' => $evento->id,
            'deal_id'   => $evento->object_id,
        ]);

        try {
            $propsDeal    = config('services.hubspot.props.deal');
            $propsCompany = config('services.hubspot.props.company');
            $propsContact = config('services.hubspot.props.contact');

            $deal       = $api->fetchDeal((string) $evento->object_id, array_merge(
                ['dealname', 'amount', 'dealstage'],
                array_values($propsDeal),
            ));
            $companyId  = $api->fetchAssociatedCompanyId((string) $evento->object_id);
            $hubCompany = $companyId ? $api->fetchCompany($companyId, array_values($propsCompany)) : null;

            // ── Fetch batch de contatos — mesmo padrao de processar() ──────────
            $contatos = [];
            try {
                $contactIds = $api->fetchAssociatedContactIds((string) $evento->object_id);
                if (!empty($contactIds)) {
                    $mapaContatos = $api->fetchContacts($contactIds, array_values($propsContact));
                    foreach ($mapaContatos as $contactId => $props) {
                        $contatos[] = [
                            'id'             => (string) $contactId,
                            'firstname'      => $props[$propsContact['firstname'] ?? 'firstname'] ?? null,
                            'lastname'       => $props[$propsContact['lastname'] ?? 'lastname'] ?? null,
                            'email'          => $props[$propsContact['email'] ?? 'email'] ?? null,
                            'phone'          => $props[$propsContact['phone'] ?? 'phone'] ?? null,
                            'mobilephone'    => $props[$propsContact['mobilephone'] ?? 'mobilephone'] ?? null,
                            'jobtitle'       => $props[$propsContact['jobtitle'] ?? 'jobtitle'] ?? null,
                            // Quick task 260805-eqk — origem do lead vive no CONTATO.
                            'origem_do_lead' => $props[$propsContact['origem_do_lead'] ?? 'origem_do_lead'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Falha ao buscar contatos vinculados: ' . $e->getMessage();
                Log::channel('ecf-webhooks')->warning('[Hubspot] Replay: falha ao buscar contatos vinculados', [
                    'evento_id' => $evento->id,
                    'msg'       => $e->getMessage(),
                ]);
            }
            $contatoPrincipal = HubspotContactSelector::selecionar($contatos);

            // ── Quick task 260805-eqk — Notes do deal (mesmo padrao resiliente) ──
            // No replay a falha tambem entra em $warnings (visivel no resumo do
            // comando), mas nunca aborta o reprocessamento.
            $notes = [];
            try {
                $notes = $api->fetchNotes('deals', (string) $evento->object_id);
            } catch (\Throwable $e) {
                $warnings[] = 'Falha ao buscar notes do deal: ' . $e->getMessage();
                Log::channel('ecf-webhooks')->warning('[Hubspot] Replay: falha ao buscar notes do deal', [
                    'evento_id' => $evento->id,
                    'msg'       => $e->getMessage(),
                ]);
            }

            $lineItems = $api->fetchDealLineItems((string) $evento->object_id);

            // ── Baseline ANTES de criarEmpresa — mede o efeito real do replay ──
            // Se o evento ja tinha uma company associada (reprocessamento de um
            // evento ja processado antes), conta os contratos dela; senao 0.
            $contratosAntes = $evento->company_id_criada
                ? ContratoServico::where('company_id', $evento->company_id_criada)->count()
                : 0;

            $company = $this->criarEmpresa($deal, $hubCompany, $contatoPrincipal, $contatos, $companyId, $lineItems, $evento, notes: $notes);

            $contratosDepois  = ContratoServico::where('company_id', $company->id)->count();
            $contratosCriados = max(0, $contratosDepois - $contratosAntes);

            // wasRecentlyCreated: true so quando Company::create() rodou nesta
            // chamada (empresa NOVA). Quando o match forte cai em
            // enriquecerEmpresaExistente(), o model veio de um find() — fica
            // false mesmo apos update()/refresh() — sinaliza enriquecimento.
            $empresaJaExistia = !$company->wasRecentlyCreated;

            $tentativasContrato = $this->contarTentativasContrato($lineItems, $deal['properties'] ?? [], $propsDeal);
            $contratosIgnorados = max(0, $tentativasContrato - $contratosCriados);

            $evento->update([
                'status'            => 'processado',
                'company_id_criada' => $company->id,
                'processado_em'     => now(),
            ]);

            $resumo = [
                'evento_id'             => $evento->id,
                'deal_id'               => $evento->object_id,
                'company_id'            => $company->id,
                'contratos_criados'     => $contratosCriados,
                'contratos_ignorados'   => $contratosIgnorados,
                'empresas_enriquecidas' => $empresaJaExistia ? 1 : 0,
                'warnings'              => $warnings,
            ];

            Log::channel('ecf-webhooks')->info('[Hubspot] Replay: reprocessamento concluido', $resumo);

            return $resumo;
        } catch (\Throwable $e) {
            $evento->update([
                'status'        => 'erro',
                'erro_msg'      => mb_substr($e->getMessage(), 0, 1000),
                'processado_em' => now(),
            ]);

            Log::channel('ecf-webhooks')->error('[Hubspot] Replay: falha no reprocessamento', [
                'evento_id' => $evento->id,
                'object_id' => $evento->object_id,
                'erro'      => $e->getMessage(),
            ]);

            return [
                'evento_id'             => $evento->id,
                'deal_id'               => $evento->object_id,
                'company_id'            => null,
                'contratos_criados'     => 0,
                'contratos_ignorados'   => 0,
                'empresas_enriquecidas' => 0,
                'warnings'              => array_merge($warnings, [$e->getMessage()]),
            ];
        }
    }

    /**
     * Fase 114 Plan 03 — conta quantos contratos SERIAM tentados a partir dos
     * line items (ou do fluxo legado servico+amount), SEM persistir nada.
     * Usado so para compor o resumo do replay (contratos_ignorados = tentativas
     * - criados nesta execucao). Espelha a mesma resolucao de mapping de
     * HubspotDealHandoffService::build(), mas em modo leitura.
     */
    private function contarTentativasContrato(array $lineItems, array $dprops, array $propsDeal): int
    {
        if (!empty($lineItems)) {
            $tentativas = 0;
            foreach ($lineItems as $item) {
                $nome = (string) ($item['name'] ?? '');
                if ($nome === '') {
                    continue;
                }
                $mapping = HubspotLineItemMapping::paraNome($nome);
                if ($mapping && $mapping->servico && $mapping->servico->ativo) {
                    $tentativas++;
                }
            }
            return $tentativas;
        }

        $servicoNome = $dprops[$propsDeal['servico'] ?? 'servico'] ?? null;
        if (!$servicoNome) {
            return 0;
        }

        return Servico::where('nome', $servicoNome)->where('ativo', true)->exists() ? 1 : 0;
    }

    /**
     * Cria a Company + (opcionalmente) ContratoServico + MlbEmpresa em transacao.
     *
     * Phase 35 Plan 35-02 evoluiu o metodo para aceitar `$hubContact` (D-04)
     * e criar `MlbEmpresa`/`MlbImplementacao` quando o servico do deal dispara
     * implementacao (D-05). Comportamento default (Publicidade/Gestao/Publicacao):
     * apenas Company — sem mlb_empresas — preservando COM-06 do fluxo Comercial.
     *
     * Phase 37 Plan 37-04 (REQ-37-04) — aceita `$lineItems` para criar
     * ContratoServico baseado em HubspotLineItemMapping. Quando `$lineItems` vazio,
     * cai no fluxo legado (Servico nome do deal + deal.amount). MlbEmpresa via
     * servicoDisparaImplementacao eh avaliada por CADA servico identificado
     * (line items mapeados OU servico do deal no fallback).
     *
     * Fase 113 Plan 113-02 (HUB-CONTATO-01/02) — troca `$hubContact` (payload
     * bruto do UNICO contato) por `$contatoPrincipal` (contato ja escolhido
     * pelo HubspotContactSelector, com chaves LOGICAS) + `$contatos` (lista
     * completa normalizada, usada no snapshot) + `$companyId` (id HubSpot da
     * company associada, gravado estruturado).
     *
     * @param  array              $deal              payload HubSpot do deal (chave 'properties')
     * @param  array|null         $hubCompany        payload HubSpot do company associado
     * @param  array|null         $contatoPrincipal  contato principal (chaves logicas id/firstname/lastname/email/phone/mobilephone/jobtitle) ou null
     * @param  array              $contatos          lista completa de contatos normalizados (chaves logicas) — alimenta o snapshot
     * @param  string|null        $companyId         id HubSpot da company associada (gravado em hubspot_company_id)
     * @param  array              $lineItems         line items normalizados (Plan 37-03); array vazio cai no legado
     * @param  HubspotEvento|null $evento            necessario para gravar warnings (line_items_nao_mapeados)
     * @param  array              $notes             Quick task 260805-eqk — Notes do deal [{id, body, timestamp}], ordem cronologica
     */
    private function criarEmpresa(
        array $deal,
        ?array $hubCompany,
        ?array $contatoPrincipal = null,
        array $contatos = [],
        ?string $companyId = null,
        array $lineItems = [],
        ?HubspotEvento $evento = null,
        array $notes = [],
    ): Company {
        $propsDeal    = config('services.hubspot.props.deal');
        $propsCompany = config('services.hubspot.props.company');
        $dprops       = $deal['properties'] ?? [];
        $cprops       = $hubCompany['properties'] ?? [];

        return DB::transaction(function () use ($deal, $dprops, $cprops, $propsDeal, $propsCompany, $lineItems, $evento, $contatoPrincipal, $contatos, $companyId, $hubCompany, $notes) {
            // ── Phase 35 D-04 / Fase 113 — fallback contato p/ email/telefone ──
            // Prioridade do E-MAIL desde 2026-08-20: property `email_envio_contrato`
            // do DEAL > Company > Contato PRINCIPAL (ver bloco logo abaixo). A do
            // TELEFONE não mudou: Company > Contato PRINCIPAL (Fase 113 plano 01 escolhe
            // entre TODOS os contatos do deal, nao so o primeiro). Strings vazias
            // sao tratadas como ausencia (a Company HubSpot pode mandar "" em vez
            // de omitir). Fase 113 plano 02 (HUB-CONTATO-02) — adiciona coalesce
            // p/ mobilephone quando o contato principal nao tem phone.
            // ── 2026-08-20 — `email_para_envio_do_contrato` entra NA FRENTE ────
            // Property do DEAL, criada pelo Comercial justamente porque o e-mail
            // da company/contato não servia (genérico, `contato@`, endereço
            // antigo). É o endereço para onde o contrato É DE FATO ENVIADO, então
            // ganha dos dois: errar aqui manda contrato assinado para a caixa
            // errada de alguém.
            //
            // ⚠️ Isto decide apenas o PRIMEIRO preenchimento. A regra "só preenche
            // se vazio" (ver `$candidatos` mais abaixo) continua valendo, então
            // empresa que JÁ tem `email_cliente` não é sobrescrita por esta
            // precedência — mudar o campo no HubSpot depois não reescreve o que
            // já está no banco.
            $emailContrato = $dprops[$propsDeal['email_envio_contrato'] ?? 'email_para_envio_do_contrato'] ?? null;

            $companyEmail  = $cprops[$propsCompany['email']] ?? null;
            $companyPhone  = $cprops[$propsCompany['phone']] ?? null;
            $contactEmail  = $contatoPrincipal['email'] ?? null;
            $contactPhone  = $contatoPrincipal['phone'] ?? null;
            $contactMobile = $contatoPrincipal['mobilephone'] ?? null;

            // Precedência: e-mail de envio do contrato (deal) > Company > Contato.
            $emailFinal = ($emailContrato !== null && $emailContrato !== '')
                ? $emailContrato
                : (($companyEmail !== null && $companyEmail !== '') ? $companyEmail : $contactEmail);
            $foneFinal  = ($companyPhone !== null && $companyPhone !== '')
                ? $companyPhone
                : (($contactPhone !== null && $contactPhone !== '') ? $contactPhone : $contactMobile);

            // ── Fase 113 plano 02 (HUB-CONTATO-02) — campos estruturados ────────
            $firstname    = trim((string) ($contatoPrincipal['firstname'] ?? ''));
            $lastname     = trim((string) ($contatoPrincipal['lastname'] ?? ''));
            $nomeContato  = trim($firstname . ' ' . $lastname);
            $cargoContatoRaw = trim((string) ($contatoPrincipal['jobtitle'] ?? ''));
            $domainRaw       = trim((string) ($cprops[$propsCompany['domain'] ?? 'domain'] ?? ''));
            $cnpjRaw         = $cprops[$propsCompany['cnpj']] ?? null;
            $nameRaw         = $cprops[$propsCompany['name']] ?? ($deal['properties']['dealname'] ?? null);
            $dealIdStr       = $evento?->object_id !== null ? (string) $evento->object_id : null;

            // ── Fase 112 Plan 112-03 (HUB-VAL-05) — controller fino delega a ────
            // montagem de valor/contratos ao HubspotDealHandoffService. Line items
            // HubSpot tem PRIORIDADE (avaliado internamente pelo build()); quando
            // vazio, o proprio service resolve o fluxo legado (servico_ecf + amount).
            // Fase 113 plano 02 — parametros extras OPCIONAIS preenchem
            // company_data/contact_data do DTO (nao alteram valor/contratos).
            //
            // Quick task 260820-jc8 — montado AQUI (antes do match/criacao da
            // empresa, nao mais depois) porque deal_contract_data (razao
            // social/CNPJ/endereco do DEAL) precisa alimentar tanto o fallback
            // de cnpjRaw abaixo quanto enriquecerEmpresaExistente()/Company::create().
            // Nao depende de $company nem do resultado do match — so de deal/
            // lineItems/hubCompany/contatos, todos ja disponiveis aqui.
            $handoff = app(HubspotDealHandoffService::class)->build(
                $deal,
                $lineItems,
                $propsDeal,
                $hubCompany,
                $contatos,
                $contatoPrincipal,
                $propsCompany,
            );

            // Quick task 260820-jc8 — cnpj_da_empresa do DEAL como FALLBACK do
            // CNPJ (que hoje vem da HubSpot COMPANY, `$cprops[cnpj]`). NUNCA
            // substitui um CNPJ ja resolvido pela company — so entra quando ela
            // nao trouxe nada.
            $cnpjDealRaw = $handoff->deal_contract_data['cnpj'] ?? null;
            if (($cnpjRaw === null || $cnpjRaw === '') && $cnpjDealRaw !== null && $cnpjDealRaw !== '') {
                $cnpjRaw = $cnpjDealRaw;
            }

            // Quick task 260820-jc8 — razao_social/endereco vem SO do DEAL (nao
            // existe fonte alternativa como o cnpj); string vazia normalizada
            // pra null pra nao acionar a regra "so preenche se vazio" com lixo.
            //
            // Quick 260821-cq0 — endereco volta a ser so o LOGRADOURO cru
            // (rua e numero); bairro/cidade/estado/cep entram como campos
            // PROPRIOS, mesma fonte (deal_contract_data), mesma disciplina
            // "so preenche se vazio" das colunas ja existentes.
            $razaoSocialRaw = trim((string) ($handoff->deal_contract_data['razao_social'] ?? ''));
            $razaoSocialRaw = $razaoSocialRaw !== '' ? $razaoSocialRaw : null;
            $enderecoRaw    = $handoff->deal_contract_data['endereco'] ?? null;
            $bairroRaw      = $handoff->deal_contract_data['bairro'] ?? null;
            $cidadeRaw      = $handoff->deal_contract_data['cidade'] ?? null;
            $estadoRaw      = $handoff->deal_contract_data['estado'] ?? null;
            $cepRaw         = $handoff->deal_contract_data['cep'] ?? null;

            // ── Quick task 260805-eqk — observacoes vindas das Notes do deal ────
            // Texto consolidado = bodies unidos por linha em branco, na ordem
            // cronologica recebida (mais antigo primeiro, mais recente por
            // ultimo). Sem nota => null.
            $bodiesNotes        = array_values(array_filter(array_map(
                static fn ($n) => trim((string) ($n['body'] ?? '')),
                $notes,
            ), static fn ($b) => $b !== ''));
            $observacaoNotes    = !empty($bodiesNotes) ? implode("\n\n", $bodiesNotes) : null;

            // Origem do lead do CONTATO principal. Ao contrario das notas, esse
            // campo respeita valor preenchido a mao pelo Comercial.
            $origemLeadRaw = trim((string) ($contatoPrincipal['origem_do_lead'] ?? ''));
            $origemLead    = $origemLeadRaw !== '' ? $origemLeadRaw : null;

            // ── Fase 113 plano 03 (HUB-DEDUP-01/02) — resolve empresa existente
            // ANTES de decidir criar/enriquecer. Ordem de precedencia dentro do
            // Matcher: hubspot_company_id > cnpj > email > domain > nome normalizado.
            $resultadoMatch = app(HubspotCompanyMatcher::class)->encontrar([
                // Debug hubspot-handoff-sem-contatos (2026-07-27) — replay de
                // um evento JA vinculado a uma Company sempre volta pra ela
                // mesma, mesmo que hubspot_company_id/cnpj/email/domain/nome
                // ainda estejam vazios ou tenham mudado (ver docblock do
                // HubspotCompanyMatcher). Em processar() (webhook original)
                // $evento->company_id_criada ainda e null, entao este criterio
                // fica inerte — zero mudanca de comportamento no fluxo normal.
                'existing_company_id' => $evento?->company_id_criada,
                'hubspot_company_id'  => $companyId,
                'cnpj'                => $cnpjRaw,
                'email'               => $emailFinal,
                'domain'              => $domainRaw !== '' ? $domainRaw : null,
                'name'                => $nameRaw,
            ]);

            if ($resultadoMatch['match'] === 'forte') {
                // ── Match FORTE (hubspot_company_id ou cnpj) — NAO duplica. ─────
                // Enriquece SOMENTE colunas vazias da empresa ja existente; dado
                // manual do Comercial e soberano (T-113-03-02). Nao remarca
                // empresa_nova (a empresa ja existia) nem mexe em notes (quick
                // task 260805-ohs: o webhook nao escreve mais em notes em
                // caminho nenhum).
                $company = $this->enriquecerEmpresaExistente(
                    company: $resultadoMatch['company'],
                    cnpjRaw: $cnpjRaw,
                    emailFinal: $emailFinal,
                    foneFinal: $foneFinal,
                    nomeContato: $nomeContato,
                    cargoContatoRaw: $cargoContatoRaw,
                    domainRaw: $domainRaw,
                    companyId: $companyId,
                    contactId: $contatoPrincipal['id'] ?? null,
                    dealIdStr: $dealIdStr,
                    origemLead: $origemLead,
                    razaoSocialRaw: $razaoSocialRaw,
                    enderecoRaw: $enderecoRaw,
                    bairroRaw: $bairroRaw,
                    cidadeRaw: $cidadeRaw,
                    estadoRaw: $estadoRaw,
                    cepRaw: $cepRaw,
                );
            } else {
                // ── SEM match forte (null ou fraco) — cria empresa nova. ────────
                // Caminho identico ao fluxo 113-02 (regressao zero DB fresco).
                $company = Company::create([
                    'name'               => $nameRaw ?? 'Empresa HubSpot',
                    'cnpj'               => $cnpjRaw,
                    'email_cliente'      => ($emailFinal !== null && $emailFinal !== '') ? $emailFinal : null,
                    'telefone'           => ($foneFinal !== null && $foneFinal !== '') ? $foneFinal : null,
                    'empresa_nova'       => true,
                    'status'             => 'pendente',
                    'active'             => true,
                    // Fase 111 (colunas) / Fase 113 plano 02 (gravacao real) —
                    // HUB-CONTATO-02: contato principal estruturado + IDs HubSpot.
                    'nome_contato'       => $nomeContato !== '' ? $nomeContato : null,
                    'cargo_contato'      => $cargoContatoRaw !== '' ? $cargoContatoRaw : null,
                    'hubspot_deal_id'    => $dealIdStr,
                    'hubspot_company_id' => $companyId,
                    'hubspot_contact_id' => $contatoPrincipal['id'] ?? null,
                    'hubspot_domain'     => $domainRaw !== '' ? $domainRaw : null,
                    // Quick task 260805-eqk — origem_lead segue a regra normal
                    // (dado manual e soberano). hubspot_notas/hubspot_observacao
                    // sao gravados no update final (espelho, sempre reescrito).
                    'origem_lead'        => $origemLead,
                    // Quick task 260820-jc8 — razao_social/endereco vem SO do
                    // DEAL (empresa recem-criada, sem valor previo pra proteger).
                    // Quick 260821-cq0 — bairro/cidade/estado/cep, mesma fonte.
                    'razao_social'       => $razaoSocialRaw,
                    'endereco'           => $enderecoRaw,
                    'bairro'             => $bairroRaw,
                    'cidade'             => $cidadeRaw,
                    'estado'             => $estadoRaw,
                    'cep'                => $cepRaw,
                ]);

                // Quick task 260805-ohs — REMOVIDA a linha legada
                // "Contato (HubSpot): {nome}" que era anexada em `notes`
                // (Phase 35 D-04). `companies.notes` e campo de texto livre do
                // time (Comercial/NovaEmpresa + Companies/Index) e o webhook
                // nao escreve nele em caminho nenhum. O nome do contato ja vive
                // na coluna estruturada `nome_contato` (Fase 113) e aparece no
                // modal "Detalhes HubSpot".
            }

            $servicosCriados = $this->persistirContratos($company, $handoff, $evento);

            // ── Roteamento MlbEmpresa por CADA servico criado ───────────────────────────
            // Fase 124 (FLUXO-04): a mecanica migrou para o EmpresaOperacionalRouter,
            // lugar unico compartilhado com o caminho Comercial. O laco por servico
            // continua igual — e ele que faz o guard anti-duplicidade (D-06/D-07,
            // FLUXO-05) rodar ENTRE servicos, backstop contra 2 line items que mapeiam
            // para servicos Polo/Assessoria/Incubadora na mesma empresa.
            $router = app(EmpresaOperacionalRouter::class);
            foreach ($servicosCriados as $nomeServico) {
                $router->rotearServico($company, $nomeServico);
            }

            $warnings = $handoff->warnings;

            // ── Fase 113 plano 03 (HUB-DEDUP-02) — match FRACO: nao mescla
            // campos criticos na candidata, so grava um warning de possivel
            // duplicidade (payload do evento + snapshot da empresa NOVA
            // recem-criada). O humano decide o merge depois (Fase 114).
            if ($resultadoMatch['match'] === 'fraco') {
                $candidata = $resultadoMatch['company'];
                $possivelDuplicidade = [
                    'candidate_company_id' => $candidata->id,
                    'via'                  => $resultadoMatch['via'],
                    'nome_normalizado'     => HubspotNameNormalizer::normalizar($nameRaw),
                ];

                if ($evento) {
                    $payload = $evento->payload ?? [];
                    $payload['possivel_duplicidade'] = $possivelDuplicidade;
                    $evento->payload = $payload;
                    $evento->save();
                }

                Log::channel('ecf-webhooks')->warning(
                    '[HubSpot Webhook] Possivel duplicidade — match fraco via ' . $resultadoMatch['via'],
                    [
                        'evento_id'            => $evento?->id,
                        'company_id_nova'      => $company->id,
                        'candidate_company_id' => $candidata->id,
                        'via'                  => $resultadoMatch['via'],
                    ]
                );

                $warnings[] = array_merge(['tipo' => 'possivel_duplicidade'], $possivelDuplicidade);
            }

            // ── Fase 113 plano 02/03 (HUB-DEDUP-03) — snapshot SEMPRE reescrito
            // com o payload do evento novo (deal + company + TODOS os contatos +
            // line_items + warnings + captured_at). Regra desta fase: mesmo no
            // caminho de match forte, o snapshot NAO faz deep-merge do antigo —
            // e sempre o retrato do ULTIMO evento processado; o historico do
            // evento anterior fica preservado em hubspot_eventos.payload.
            //
            // Quick task 260805-eqk — `hubspot_notas` e `hubspot_observacao`
            // entram AQUI de proposito: sao ESPELHO do HubSpot, nao input
            // humano, e por isso ficam FORA da regra "so preenche se vazio" do
            // enriquecerEmpresaExistente(). Caso concreto que motivou a regra:
            // a Metalform ganhou uma nota em 03/08 DEPOIS de a empresa ja
            // existir — sob a regra antiga essa nota nunca apareceria no ECF.
            $company->update([
                'hubspot_notas'      => $notes,
                'hubspot_observacao' => $observacaoNotes,
                'hubspot_snapshot' => [
                    'deal'               => $dprops,
                    'company'            => $hubCompany['properties'] ?? null,
                    'contacts'           => $contatos,
                    'primary_contact_id' => $contatoPrincipal['id'] ?? null,
                    'line_items'         => $lineItems,
                    'notes'              => $notes,
                    'warnings'           => $warnings,
                    'captured_at'        => now()->toIso8601String(),
                ],
            ]);

            return $company;
        });
    }

    /**
     * Fase 113 plano 03 (HUB-DEDUP-01) — enriquece SOMENTE colunas vazias
     * (null/'') de uma Company ja existente (match FORTE por hubspot_company_id
     * ou cnpj). Dado ja preenchido manualmente pelo Comercial e soberano —
     * NUNCA sobrescrito (T-113-03-02). Nao remarca `empresa_nova` (a empresa
     * ja existia) nem mexe em `notes` (quick task 260805-ohs: `notes` e campo
     * humano; o webhook nao escreve nele em caminho nenhum).
     *
     * Quick task 260805-eqk — `hubspot_observacao` SAIU daqui de proposito:
     * junto com `hubspot_notas` ela e espelho do HubSpot e e sempre reescrita
     * no update final de criarEmpresa(). Se ficasse nesta regra, nota criada
     * depois que a empresa ja existe nunca apareceria no ECF.
     *
     * Quick task 260820-jc8 — `razaoSocialRaw`/`enderecoRaw` (props do DEAL,
     * ja compostas/normalizadas por HubspotDealHandoffService) entram na
     * MESMA regra "so preenche se vazio": dado ja preenchido a mao pelo
     * Administrativo nunca e sobrescrito.
     *
     * Quick 260821-cq0 — `bairroRaw`/`cidadeRaw`/`estadoRaw`/`cepRaw` entram
     * pela mesma porta, mesma disciplina "so preenche se vazio".
     */
    private function enriquecerEmpresaExistente(
        Company $company,
        ?string $cnpjRaw,
        ?string $emailFinal,
        ?string $foneFinal,
        string $nomeContato,
        string $cargoContatoRaw,
        string $domainRaw,
        ?string $companyId,
        ?string $contactId,
        ?string $dealIdStr,
        ?string $origemLead = null,
        ?string $razaoSocialRaw = null,
        ?string $enderecoRaw = null,
        ?string $bairroRaw = null,
        ?string $cidadeRaw = null,
        ?string $estadoRaw = null,
        ?string $cepRaw = null,
    ): Company {
        $candidatos = [
            'cnpj'               => $cnpjRaw,
            'email_cliente'      => ($emailFinal !== null && $emailFinal !== '') ? $emailFinal : null,
            'telefone'           => ($foneFinal !== null && $foneFinal !== '') ? $foneFinal : null,
            'nome_contato'       => $nomeContato !== '' ? $nomeContato : null,
            'cargo_contato'      => $cargoContatoRaw !== '' ? $cargoContatoRaw : null,
            'hubspot_deal_id'    => $dealIdStr,
            'hubspot_company_id' => $companyId,
            'hubspot_contact_id' => $contactId,
            'hubspot_domain'     => $domainRaw !== '' ? $domainRaw : null,
            // Quick task 260805-eqk — origem do lead pode ser corrigida a mao
            // pelo Comercial; entra na regra "so preenche se vazio".
            'origem_lead'        => $origemLead,
            // Quick task 260820-jc8 — razao_social/endereco do deal HubSpot.
            // Quick 260821-cq0 — bairro/cidade/estado/cep, mesma fonte.
            'razao_social'       => $razaoSocialRaw,
            'endereco'           => $enderecoRaw,
            'bairro'             => $bairroRaw,
            'cidade'             => $cidadeRaw,
            'estado'             => $estadoRaw,
            'cep'                => $cepRaw,
        ];

        $updates = [];
        foreach ($candidatos as $campo => $valorNovo) {
            if ($valorNovo === null) {
                continue;
            }
            $valorAtual = $company->{$campo};
            $vazio      = $valorAtual === null || $valorAtual === '';
            if ($vazio) {
                $updates[$campo] = $valorNovo;
            }
        }

        if (!empty($updates)) {
            $company->update($updates);
        }

        return $company->refresh();
    }

    /**
     * Fase 112 Plan 112-03 (HUB-VAL-05 + HUB-VAL-03 + HUB-VAL-02) — persiste os
     * `contracts_to_create` do `HubspotHandoffData` como `ContratoServico`, gravando
     * o valor operacional + as 11 colunas de auditoria HubSpot (Fase 111), e trata
     * os `warnings` do handoff (quick task 260805-ohs: line item sem mapping E
     * servico legado sem match no catalogo vao AMBOS para o payload do evento,
     * em `line_items_nao_mapeados`; o segundo caso gravava em `notes` da Company
     * e nunca se auto-limpava).
     *
     * A linha legada `observacoes = "tipo_cobranca: {mensal|unica} (HubSpot
     * line_item: {nome})"` (Phase 37) e preservada quando o contrato vem de um
     * line item; no fluxo legado (sem line item) observacoes fica null, como antes.
     *
     * @param  Company            $company  empresa recem-criada (mesma transaction)
     * @param  HubspotHandoffData $handoff  DTO produzido por HubspotDealHandoffService::build()
     * @param  HubspotEvento|null $evento   necessario para gravar payload de warnings
     * @return array<int, string>           nomes dos servicos criados (alimenta rotearImplementacao)
     */
    private function persistirContratos(Company $company, HubspotHandoffData $handoff, ?HubspotEvento $evento): array
    {
        $servicosCriados = [];

        foreach ($handoff->contracts_to_create as $c) {
            $temLineItem = $c['hubspot_line_item_id'] !== null;

            // ── Fase 113 plano 03 (HUB-DEDUP-01) — guard anti-duplicidade de
            // contrato quando a empresa JA EXISTIA (match forte reprocessando
            // ou recebendo um novo deal). Com line item: nunca duplica o mesmo
            // hubspot_line_item_id. Sem line item (fluxo legado): nao duplica
            // um contrato ATIVO do mesmo servico_id. Em empresa recem-criada
            // nesta transaction isto nunca dispara (sem contrato previo) —
            // zero impacto no fluxo de criacao (113-02, regressao zero).
            $duplicado = $temLineItem
                ? ContratoServico::where('company_id', $company->id)
                    ->where('hubspot_line_item_id', $c['hubspot_line_item_id'])
                    ->exists()
                : ContratoServico::where('company_id', $company->id)
                    ->where('servico_id', $c['servico_id'])
                    ->where('ativo', true)
                    ->exists();

            if ($duplicado) {
                Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Contrato duplicado ignorado (guard dedup)', [
                    'evento_id'            => $evento?->id,
                    'company_id'           => $company->id,
                    'servico_id'           => $c['servico_id'],
                    'hubspot_line_item_id' => $c['hubspot_line_item_id'],
                ]);
                continue;
            }

            // Linha legada Phase 37: so anotada quando o contrato veio de um line
            // item (fluxo legado deal.amount nunca teve essa observacao).
            $observacoes = null;
            if ($temLineItem) {
                $freq = $c['hubspot_billing_frequency'] ?? null;
                $tipoCobranca = in_array($freq, ['monthly', 'annually'], true)
                    ? Servico::TIPO_MENSAL
                    : Servico::TIPO_UNICA;
                $nomeLineItem = $c['hubspot_snapshot']['line_item']['name'] ?? $c['servico_nome'];
                $observacoes = "tipo_cobranca: {$tipoCobranca} (HubSpot line_item: {$nomeLineItem})";
            }

            $contrato = ContratoServico::create([
                'company_id'                       => $company->id,
                'servico_id'                       => $c['servico_id'],
                'valor_contratado'                 => $c['valor_contratado'],
                'data_contratacao'                 => now()->toDateString(),
                'data_vencimento'                  => null,
                'ativo'                            => true,
                'observacoes'                      => $observacoes,
                'hubspot_line_item_id'             => $c['hubspot_line_item_id'],
                'hubspot_product_id'               => $c['hubspot_product_id'],
                'hubspot_billing_frequency'        => $c['hubspot_billing_frequency'],
                'hubspot_billing_period'           => $c['hubspot_billing_period'],
                'hubspot_currency'                 => $c['hubspot_currency'],
                'hubspot_valor_original'           => $c['hubspot_valor_original'],
                'hubspot_valor_original_tipo'      => $c['hubspot_valor_original_tipo'],
                'hubspot_valor_normalizado_mensal' => $c['hubspot_valor_normalizado_mensal'],
                'hubspot_valor_confidence'         => $c['hubspot_valor_confidence'],
                'hubspot_valor_warning'            => $c['hubspot_valor_warning'],
                'hubspot_snapshot'                 => $c['hubspot_snapshot'],
                // Quick task 260820-jc8 (Tarefa 3) — mesma data/dia em TODOS os
                // contratos deste deal (data_do_1_pagamento e property do DEAL,
                // resolvida uma vez em HubspotDealHandoffService::build()).
                'data_primeira_parcela'            => $c['data_primeira_parcela'] ?? null,
                'dia_vencimento'                   => $c['dia_vencimento'] ?? null,
            ]);

            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] ContratoServico criado', [
                'evento_id'        => $evento?->id,
                'company_id'       => $company->id,
                'contrato_id'      => $contrato->id,
                'servico_id'       => $c['servico_id'],
                'servico_nome'     => $c['servico_nome'],
                'valor_contratado' => $c['valor_contratado'],
                'hubspot_confidence' => $c['hubspot_valor_confidence'],
                'hubspot_warning'  => $c['hubspot_valor_warning'],
            ]);

            $servicosCriados[] = $c['servico_nome'];
        }

        // ── Warnings do handoff: 2 shapes distintos (Plan 112-02) ───────────────
        // {name, motivo:'servico_nao_encontrado'} → fluxo legado;
        // {name, price, recurringbillingfrequency} → line item sem mapping.
        //
        // Quick task 260805-ohs — os DOIS shapes agora caem no MESMO lugar: o
        // payload do evento (`line_items_nao_mapeados`). O shape legado gravava
        // "Serviço (HubSpot): {nome}" em `companies.notes`, o que era pior que
        // a linha do contato: `notes` so ACUMULA e nunca limpa, entao bastava
        // mapear o servico e reprocessar o evento para a pendencia JA RESOLVIDA
        // ficar la para sempre. O bloco do payload logo abaixo tem auto-limpeza
        // (o `elseif` remove a chave quando a pendencia some). Ambos os shapes
        // tem a chave `name`, entao o array_column do log segue valido.
        $naoMapeados = array_values($handoff->warnings);

        // Fase 115 Plano 02 (Rule 1 — bug corrigido durante auditoria SC4): o
        // payload precisa refletir o estado ATUAL, não só acumular. Sem este
        // else, um replay que materializa o contrato faltante (mapping
        // cadastrado depois) nunca limpava a pendência antiga — o line item
        // continuava aparecendo em line_items_nao_mapeados mesmo já resolvido.
        if ($evento) {
            $payload = $evento->payload ?? [];
            if (!empty($naoMapeados)) {
                $payload['line_items_nao_mapeados'] = $naoMapeados;
                $evento->payload = $payload;
                $evento->save();

                Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Line items sem mapeamento', [
                    'evento_id'  => $evento->id,
                    'company_id' => $company->id,
                    'itens'      => array_column($naoMapeados, 'name'),
                ]);
            } elseif (array_key_exists('line_items_nao_mapeados', $payload)) {
                unset($payload['line_items_nao_mapeados']);
                $evento->payload = $payload;
                $evento->save();
            }
        }

        return $servicosCriados;
    }

    /**
     * Phase 35 Plan 35-03 — calcula as pendencias da empresa para fins de
     * notificacao Comercial. Espelha exatamente a logica de
     * `CompanyController::index` (linhas 134-139), mas EXCLUI a pendencia
     * `empresa_nova` — toda empresa criada por webhook nasce empresa_nova=true,
     * entao notificar nela seria ruido. As demais pendencias indicam dados
     * faltantes que o Comercial precisa preencher.
     *
     * @return array<int, string>  Lista de slugs (ex: ['sem_responsavel'])
     */
    private function calcularPendencias(Company $company): array
    {
        // refresh garante dados frescos depois do commit do criarEmpresa.
        $company->refresh()->load(['consultor', 'estrategista', 'contratosServico']);

        $pendencias = [];

        if ($company->consultor->isEmpty() && $company->estrategista->isEmpty()) {
            $pendencias[] = 'sem_responsavel';
        }
        if (!$company->adman_account_id && !$company->ml_store_id) {
            $pendencias[] = 'sem_cust_id';
        }
        if (!$company->email_colaborador) {
            $pendencias[] = 'sem_email_colaborador';
        }
        // ContratoServico::ativo eh boolean — only filter por ativo=true.
        if ($company->contratosServico->where('ativo', true)->isEmpty()) {
            $pendencias[] = 'sem_servico';
        }

        return $pendencias;
    }

    /**
     * Phase 35 Plan 35-03 — dispatch da notificacao Comercial.
     *
     * Defensivo: erros aqui (audiencia vazia, falha de DB no insert da
     * notification) NAO devem reverter o status do evento processado.
     * Captura `\Throwable` e loga warning — webhook ja respondeu 200 e a
     * empresa ja existe no banco.
     */
    private function notificarComercialSePendente(Company $company, HubspotEvento $evento): void
    {
        try {
            $pendencias = $this->calcularPendencias($company);
            if (empty($pendencias)) {
                return;
            }

            $audiencia = AudienciaComercial::lideresEPermissionados();
            if ($audiencia->isEmpty()) {
                Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Audiencia Comercial vazia — notificacao nao enviada', [
                    'evento_id'  => $evento->id,
                    'company_id' => $company->id,
                    'pendencias' => $pendencias,
                ]);
                return;
            }

            Notification::send(
                $audiencia,
                new EmpresaHubspotPendenteNotification($company, $pendencias),
            );

            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Notificacao Comercial enviada', [
                'evento_id'           => $evento->id,
                'company_id'          => $company->id,
                'pendencias'          => $pendencias,
                'destinatarios_count' => $audiencia->count(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Falha ao notificar Comercial (nao bloqueia o webhook)', [
                'evento_id'  => $evento->id,
                'company_id' => $company->id,
                'erro'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Grava HubspotEvento(signature_valid=false) com motivo + raw truncado.
     * Usado por falhas pre-validacao (timestamp/signature/json).
     */
    private function gravarInvalido(string $rawBody, string $motivo, Request $request): void
    {
        HubspotEvento::create([
            'signature_valid' => false,
            'payload'         => [
                'raw'    => mb_strcut($rawBody, 0, self::RAW_BODY_MAX_BYTES),
                'motivo' => $motivo,
                'ip'     => $request->ip(),
            ],
            'status'   => 'erro',
            'erro_msg' => $motivo,
        ]);

        Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Requisicao invalida', [
            'motivo'    => $motivo,
            'ip'        => $request->ip(),
            'body_size' => strlen($rawBody),
        ]);
    }
}
