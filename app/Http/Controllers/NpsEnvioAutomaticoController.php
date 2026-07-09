<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsDigisacEnvio;
use App\Models\NpsEmailEnvio;
use App\Services\Digisac\DigisacClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;

/**
 * Controller da página `/nps/envio-automatico` — v15.5.
 *
 * Concentra:
 *  - Configurações gerais dos canais (email + WhatsApp/Digisac)
 *  - Mapeamento empresa → grupo Digisac (contactId como fonte de verdade)
 *  - Lista de empresas pendentes (sem grupo mapeado)
 *  - Auditoria unificada (nps_email_envios + nps_digisac_envios)
 *
 * Todos os endpoints exigem `role:admin` (aplicado no grupo de rotas).
 *
 * @see routes/web.php (grupo admin NPS)
 * @see resources/js/Pages/Nps/EnvioAutomatico.jsx
 */
class NpsEnvioAutomaticoController extends Controller
{
    /**
     * GET /nps/envio-automatico — página principal.
     *
     * Props Inertia:
     *   - config: valores atuais das Configuracao keys do módulo
     *   - stats: contadores do mês corrente (email/digisac por status)
     *   - empresas_sem_mapeamento_total: empresas ativas ainda sem grupo Digisac
     *   - mapeamentos: paginação de mapeamentos existentes
     *   - filtros: query params (só `q` para busca de mapeamento; auditoria
     *     detalhada mora em `/nps/emails-enviados`)
     */
    public function index(Request $request)
    {
        $config = $this->carregarConfig();

        // Stats sempre calculadas sobre o mês corrente — auditoria detalhada
        // (com filtros por canal/status/mês) fica em /nps/emails-enviados.
        $inicioMes = now()->startOfMonth();
        $fimMes    = now()->endOfMonth();

        $q = trim((string) $request->input('q', ''));

        // ─── Stats do mês corrente ─────────────────────────────────────────
        $stats = [
            'email' => [
                'enviados' => NpsEmailEnvio::whereBetween('created_at', [$inicioMes, $fimMes])->where('status', 'enviado')->count(),
                'falhas'   => NpsEmailEnvio::whereBetween('created_at', [$inicioMes, $fimMes])->where('status', 'falha')->count(),
            ],
            'digisac' => [
                'enviados' => NpsDigisacEnvio::whereBetween('created_at', [$inicioMes, $fimMes])->where('status', 'enviado')->count(),
                'falhas'   => NpsDigisacEnvio::whereBetween('created_at', [$inicioMes, $fimMes])->where('status', 'falha')->count(),
                'skipped'  => NpsDigisacEnvio::whereBetween('created_at', [$inicioMes, $fimMes])->where('status', 'skipped')->count(),
            ],
        ];

        // ─── Empresas sem mapeamento (pendência do canal Digisac) ──────────
        $empresasSemMapeamentoTotal = Company::where('active', true)
            ->where(function ($sub) {
                $sub->whereNull('digisac_group_contact_id')
                    ->orWhere('digisac_group_contact_id', '')
                    ->orWhere('digisac_group_mapping_status', '!=', 'mapped');
            })
            ->count();

        // ─── Tabela de mapeamento (paginada + busca) ───────────────────────
        $mapeamentosQuery = Company::query()
            ->select([
                'id', 'name', 'email_cliente',
                'digisac_service_id', 'digisac_group_contact_id',
                'digisac_group_name_snapshot', 'digisac_group_mapped_at',
                'digisac_group_verified_at', 'digisac_group_mapping_status',
            ])
            ->where('active', true)
            ->orderBy('name');

        if ($q !== '') {
            $mapeamentosQuery->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('digisac_group_name_snapshot', 'like', "%{$q}%")
                    ->orWhere('digisac_group_contact_id', $q);
            });
        }

        $mapeamentos = $mapeamentosQuery->paginate(25)->withQueryString();

        return Inertia::render('Nps/EnvioAutomatico', [
            'config'                        => $config,
            'stats'                         => $stats,
            'empresas_sem_mapeamento_total' => $empresasSemMapeamentoTotal,
            'mapeamentos'                   => $mapeamentos,
            'filtros'                       => [
                'q' => $q,
            ],
            'digisac_configurado'           => $this->digisacConfigurado(),
        ]);
    }

    /**
     * PATCH /nps/envio-automatico/config — persiste toggles + mensagem default + IDs.
     */
    public function atualizarConfig(Request $request)
    {
        $validated = $request->validate([
            'nps_envio_email_ativo'        => 'required|boolean',
            'nps_envio_digisac_ativo'      => 'required|boolean',
            'nps_digisac_service_id'       => 'nullable|string|max:100',
            'nps_digisac_user_id'          => 'nullable|string|max:100',
            'nps_digisac_mensagem_default' => 'nullable|string|max:4000',
        ], [
            'nps_envio_email_ativo.required'   => 'Informe se o envio por email está ativo.',
            'nps_envio_digisac_ativo.required' => 'Informe se o envio por WhatsApp está ativo.',
        ]);

        Configuracao::set('nps_envio_email_ativo',        $validated['nps_envio_email_ativo']   ? '1' : '0');
        Configuracao::set('nps_envio_digisac_ativo',      $validated['nps_envio_digisac_ativo'] ? '1' : '0');
        Configuracao::set('nps_digisac_service_id',       (string) ($validated['nps_digisac_service_id']  ?? ''));
        Configuracao::set('nps_digisac_user_id',          (string) ($validated['nps_digisac_user_id']     ?? ''));
        Configuracao::set('nps_digisac_mensagem_default', (string) ($validated['nps_digisac_mensagem_default'] ?? ''));

        return back()->with('success', 'Configurações de envio automático atualizadas.');
    }

    /**
     * GET /nps/envio-automatico/digisac/grupos?service_id=…
     * Proxy autenticado para a API Digisac. Retorna JSON com a lista de grupos.
     */
    public function listarGrupos(Request $request, DigisacClient $client)
    {
        $serviceId = (string) $request->query(
            'service_id',
            (string) Configuracao::get('nps_digisac_service_id', config('digisac.default_service_id', '')),
        );

        if (trim($serviceId) === '') {
            return response()->json([
                'message' => 'Informe um serviceId (via query ?service_id ou em Configurações).',
            ], 422);
        }

        try {
            $groups = $client->listGroups($serviceId);
        } catch (RuntimeException $e) {
            Log::error('[NPS Digisac] listarGrupos falhou: ' . $e->getMessage());
            return response()->json([
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'service_id' => $serviceId,
            'groups'     => $groups,
        ]);
    }

    /**
     * PUT /nps/envio-automatico/empresas/{company}/mapeamento
     * Salva o vínculo empresa → grupo Digisac (contactId + snapshot do nome).
     */
    public function mapearGrupo(Request $request, Company $company)
    {
        $validated = $request->validate([
            'digisac_service_id'          => 'nullable|string|max:100',
            'digisac_group_contact_id'    => 'required|string|max:100',
            'digisac_group_name_snapshot' => 'nullable|string|max:255',
        ], [
            'digisac_group_contact_id.required' => 'Selecione um grupo do Digisac.',
        ]);

        $company->fill([
            'digisac_service_id'          => $validated['digisac_service_id'] ?? $company->digisac_service_id,
            'digisac_group_contact_id'    => $validated['digisac_group_contact_id'],
            'digisac_group_name_snapshot' => $validated['digisac_group_name_snapshot'] ?? null,
            'digisac_group_mapped_at'     => now(),
            'digisac_group_verified_at'   => now(),
            'digisac_group_mapping_status' => 'mapped',
        ])->save();

        return back()->with('success', "Grupo Digisac vinculado a {$company->name}.");
    }

    /**
     * DELETE /nps/envio-automatico/empresas/{company}/mapeamento
     * Remove o vínculo — preserva history dos envios anteriores (não faz cascade).
     */
    public function desmapearGrupo(Company $company)
    {
        $company->fill([
            'digisac_group_contact_id'    => null,
            'digisac_group_name_snapshot' => null,
            'digisac_group_mapped_at'     => null,
            'digisac_group_verified_at'   => null,
            'digisac_group_mapping_status' => 'not_mapped',
        ])->save();

        return back()->with('success', "Vínculo Digisac removido de {$company->name}.");
    }

    /**
     * Snapshot atual de todas as chaves de config do módulo, com defaults
     * defensivos vindos do config('digisac.*') quando a chave está vazia.
     *
     * @return array<string, mixed>
     */
    private function carregarConfig(): array
    {
        return [
            'nps_envio_email_ativo'        => Configuracao::get('nps_envio_email_ativo', '1') === '1',
            'nps_envio_digisac_ativo'      => Configuracao::get('nps_envio_digisac_ativo', '0') === '1',
            'nps_digisac_service_id'       => (string) Configuracao::get('nps_digisac_service_id', ''),
            'nps_digisac_user_id'          => (string) Configuracao::get('nps_digisac_user_id', ''),
            'nps_digisac_mensagem_default' => (string) Configuracao::get('nps_digisac_mensagem_default', ''),
            // Defaults de env (só leitura — servem de placeholder na UI):
            'digisac_env_default_service_id' => (string) config('digisac.default_service_id', ''),
            'digisac_env_default_user_id'    => (string) config('digisac.default_user_id', ''),
        ];
    }

    /**
     * Confirma se a integração Digisac tem o mínimo pra operar
     * (BASE_URL + TOKEN configurados). A UI usa isso pra mostrar aviso
     * quando o admin ativa o toggle mas o env não foi preenchido.
     */
    private function digisacConfigurado(): bool
    {
        return trim((string) config('digisac.base_url', '')) !== ''
            && trim((string) config('digisac.token', '')) !== '';
    }

}
