<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ComercialController;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Notifications\EmpresaHubspotPendenteNotification;
use App\Services\HubspotApiClient;
use App\Services\MlbImplementacaoFactory;
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
 *    cria ContratoServico. Caso contrario grava o nome em notes.
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
            $propsDeal    = config('services.hubspot.props.deal');
            $propsCompany = config('services.hubspot.props.company');
            $propsContact = config('services.hubspot.props.contact');

            $deal       = $api->fetchDeal((string) $evento->object_id, array_merge(
                ['dealname', 'amount', 'dealstage'],
                array_values($propsDeal),
            ));
            $companyId  = $api->fetchAssociatedCompanyId((string) $evento->object_id);
            $hubCompany = $companyId ? $api->fetchCompany($companyId, array_values($propsCompany)) : null;

            // Phase 35 Plan 35-02 D-04 — fetch do contato vinculado (fallback
            // p/ email_cliente/telefone se Company veio sem). Falha do GET ou
            // ausencia de contato: segue silencioso (warning), nao bloqueia
            // o cadastro porque deal+company sao o minimo viavel.
            $contactId  = $api->fetchAssociatedContactId((string) $evento->object_id);
            $hubContact = null;
            if ($contactId) {
                try {
                    $hubContact = $api->fetchContact($contactId, array_values($propsContact));
                } catch (\Throwable $e) {
                    Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Falha ao buscar contato vinculado', [
                        'evento_id'  => $evento->id,
                        'contact_id' => $contactId,
                        'msg'        => $e->getMessage(),
                    ]);
                }
            }

            $company = $this->criarEmpresa($deal, $hubCompany, $hubContact);

            $evento->update([
                'status'            => 'processado',
                'company_id_criada' => $company->id,
                'processado_em'     => now(),
            ]);

            Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Empresa criada', [
                'evento_id'  => $evento->id,
                'company_id' => $company->id,
                'object_id'  => $evento->object_id,
            ]);

            // ── Phase 35 Plan 35-03 (D-06) — notifica Comercial se tem pendencias ─
            // Disparado APOS o commit da criarEmpresa (transaction ja fechou) e
            // APOS o evento ser marcado processado — falha no dispatch nao desfaz
            // o estado consistente. Idempotencia natural: 2o webhook do mesmo deal
            // cai como ignorado na guarda acima (linha ~148), nao reentra aqui.
            $this->notificarComercialSePendente($company, $evento);
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
     * Cria a Company + (opcionalmente) ContratoServico + MlbEmpresa em transacao.
     *
     * Phase 35 Plan 35-02 evoluiu o metodo para aceitar `$hubContact` (D-04)
     * e criar `MlbEmpresa`/`MlbImplementacao` quando o servico do deal dispara
     * implementacao (D-05). Comportamento default (Publicidade/Gestao/Publicacao):
     * apenas Company — sem mlb_empresas — preservando COM-06 do fluxo Comercial.
     *
     * @param  array       $deal        payload HubSpot do deal (chave 'properties')
     * @param  array|null  $hubCompany  payload HubSpot do company associado
     * @param  array|null  $hubContact  payload HubSpot do contato associado (fallback)
     */
    private function criarEmpresa(array $deal, ?array $hubCompany, ?array $hubContact = null): Company
    {
        $propsDeal    = config('services.hubspot.props.deal');
        $propsCompany = config('services.hubspot.props.company');
        $propsContact = config('services.hubspot.props.contact');
        $dprops       = $deal['properties'] ?? [];
        $cprops       = $hubCompany['properties'] ?? [];
        $ctprops      = $hubContact['properties'] ?? [];

        return DB::transaction(function () use ($deal, $dprops, $cprops, $ctprops, $propsDeal, $propsCompany, $propsContact) {
            $venderMlRaw = $dprops[$propsDeal['vende_ml']] ?? null;
            $vendeMl     = $venderMlRaw === null || $venderMlRaw === ''
                ? null
                : filter_var($venderMlRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            $faturamentoRaw = $dprops[$propsDeal['faturamento_mensal']] ?? null;
            $faturamento    = is_numeric($faturamentoRaw) ? (float) $faturamentoRaw : null;

            // ── Phase 35 D-04 — fallback contato p/ email/telefone ────────────
            // Prioridade: Company > Contato. Strings vazias sao tratadas como
            // ausencia (a Company HubSpot pode mandar "" em vez de omitir).
            $companyEmail = $cprops[$propsCompany['email']] ?? null;
            $companyPhone = $cprops[$propsCompany['phone']] ?? null;
            $contactEmail = $ctprops[$propsContact['email']] ?? null;
            $contactPhone = $ctprops[$propsContact['phone']] ?? null;

            $emailFinal = ($companyEmail !== null && $companyEmail !== '') ? $companyEmail : $contactEmail;
            $foneFinal  = ($companyPhone !== null && $companyPhone !== '') ? $companyPhone : $contactPhone;

            $company = Company::create([
                'name'               => $cprops[$propsCompany['name']]
                    ?? $deal['properties']['dealname']
                    ?? 'Empresa HubSpot',
                'cnpj'               => $cprops[$propsCompany['cnpj']] ?? null,
                'email_cliente'      => ($emailFinal !== null && $emailFinal !== '') ? $emailFinal : null,
                'telefone'           => ($foneFinal !== null && $foneFinal !== '') ? $foneFinal : null,
                'nicho'              => $dprops[$propsDeal['nicho']] ?? null,
                'dor'                => $dprops[$propsDeal['dor']] ?? null,
                'vende_ml'           => $vendeMl,
                'faturamento_mensal' => $faturamento,
                'empresa_nova'       => true,
                'status'             => 'pendente',
                'active'             => true,
            ]);

            // ── Phase 35 D-04 — anexa nome do contato em notes ────────────────
            // Concatena firstname + lastname (trim). Gancho semantico p/ coluna
            // futura `contato_nome`. Linha "Contato (HubSpot): {nome}".
            $firstname = trim((string) ($ctprops[$propsContact['firstname']] ?? ''));
            $lastname  = trim((string) ($ctprops[$propsContact['lastname']] ?? ''));
            $nomeContato = trim($firstname . ' ' . $lastname);
            if ($nomeContato !== '') {
                $notesAtuais = (string) ($company->notes ?? '');
                $linhaContato = "Contato (HubSpot): {$nomeContato}";
                $notes = trim($notesAtuais === '' ? $linhaContato : $notesAtuais . "\n" . $linhaContato);
                $company->update(['notes' => $notes]);
            }

            // ── Tenta vincular ContratoServico se nome do servico bate ───────
            $servicoNome = $dprops[$propsDeal['servico']] ?? null;
            $servico     = $servicoNome
                ? Servico::where('nome', $servicoNome)->where('ativo', true)->first()
                : null;

            // amount do HubSpot eh o valor do contrato (campo nativo, sem mapeamento)
            $valor = isset($dprops['amount']) && is_numeric($dprops['amount'])
                ? (float) $dprops['amount']
                : ((float) ($servico?->valor_padrao ?? 0));

            if ($servico) {
                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $servico->id,
                    'valor_contratado' => $valor,
                    'data_contratacao' => now()->toDateString(),
                    'ativo'            => true,
                ]);
            } elseif ($servicoNome) {
                // Servico nao encontrado no catalogo — grava em notes para admin completar.
                $notesAtuais = $company->notes ?? '';
                $linhaNova   = "Serviço (HubSpot): {$servicoNome}";
                $notes       = trim($notesAtuais === '' ? $linhaNova : $notesAtuais . "\n" . $linhaNova);
                $company->update(['notes' => $notes]);
            }

            // ── Phase 35 D-05 — roteamento MlbEmpresa por servico ─────────────
            // Reaproveita helper estatico do ComercialController (fonte unica
            // de verdade da regra "Polos" / "Assessoria" / "Incubadora").
            // Publicidade/Gestao/Publicacao retornam null e nao criam mlb_empresas.
            $tipoImpl = $servicoNome
                ? ComercialController::servicoDisparaImplementacao($servicoNome)
                : null;

            if ($tipoImpl === 'polos') {
                $mlbEmp = MlbEmpresa::create([
                    'nome'       => $company->name,
                    'tipo'       => 'POLO',
                    'projeto'    => 'POLOS',
                    'company_id' => $company->id,
                ]);
                MlbImplementacaoFactory::criarParaPolo($mlbEmp);
            } elseif ($tipoImpl === 'assessoria') {
                MlbEmpresa::create([
                    'nome'       => $company->name,
                    'tipo'       => 'ASSESSORIA',
                    'company_id' => $company->id,
                ]);
            } elseif ($tipoImpl === 'incubadora') {
                MlbEmpresa::create([
                    'nome'       => $company->name,
                    'tipo'       => 'INCUBADORA',
                    'company_id' => $company->id,
                ]);
            }

            return $company;
        });
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
