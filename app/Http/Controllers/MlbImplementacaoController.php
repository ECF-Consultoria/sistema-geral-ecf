<?php

namespace App\Http\Controllers;

use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MlbImplementacaoController extends Controller
{
    /**
     * Injeção do EcfDriveService via construtor (PHP 8 promoted property).
     * Mesmo padrão do EmpresaAnaliseEcfController (Phase 25).
     */
    public function __construct(private EcfDriveService $ecf) {}

    private function checkAccess(Request $request): void
    {
        $user   = $request->user();
        $perms  = $user->publication_permissions ?? [];
        $role   = $user->publication_role;

        abort_unless(
            $user->role === 'admin'
            || in_array('empresas', $perms)
            || in_array($role, ['gestor', 'analista', 'lider'])
            // Setor-based (novo): membros do setor Polos ganham mlb.implementacao e
            // acessam o Onboarding sem depender dos campos legados publication_*.
            || $user->hasPermission('mlb.implementacao'),
            403
        );
    }

    /**
     * Exibe a ficha de onboarding de uma implementação (Frente 3 — ONB-02/05).
     *
     * Props retornadas:
     *  - `impl`    : 13 campos operacionais + id, progresso, dados, token
     *  - `empresa` : id, nome (Loja), cust_id, polo, fase, data_solicitacao
     *  - `opcoes`  : todas as constantes ONB_* para alimentar os selects dos modais
     */
    public function ficha(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);
        $impl->load('empresa');

        $e = $impl->empresa;

        return Inertia::render('Mlb/OnboardingFicha', [
            'impl' => [
                'id'                  => $impl->id,
                'token'               => $impl->token,
                'progresso'           => $impl->progresso(),
                'dados'               => $impl->dados,
                // Campos operacionais do bloco Acessos
                'acesso_colaborador'  => $impl->acesso_colaborador,
                'gmail_colaborador'   => $impl->gmail_colaborador,
                'grupo_whatsapp'      => $impl->grupo_whatsapp,
                // Campos operacionais do bloco Produtos
                'planilha_produtos'   => $impl->planilha_produtos,
                'listagem'            => $impl->listagem,
                'publicacao'          => $impl->publicacao,
                'decola'              => $impl->decola,
                'central_promocao'    => $impl->central_promocao,
                // Campos operacionais do bloco Logística
                'contextos_logistica' => $impl->contextos_logistica,
                'me1'                 => $impl->me1,
                'integradora'         => $impl->integradora,
                'places'              => $impl->places,
                'erp'                 => $impl->erp,
                // Data do bloco Identificação (vive na implementação)
                'data_solicitacao'    => $impl->data_solicitacao?->format('Y-m-d'),
            ],
            'empresa' => [
                'id'               => $e->id,
                'nome'             => $e->nome,
                'cust_id'          => $e->cust_id,
                'polo'             => $e->polo,
                'fase'             => $e->fase,
                // data_solicitacao do bloco Identificação (lida da implementação)
                'data_solicitacao' => $impl->data_solicitacao?->format('Y-m-d'),
            ],
            'opcoes' => [
                'polo'                => MlbImplementacao::ONB_POLO_OPCOES,
                'fase'                => MlbImplementacao::ONB_FASE_OPCOES,
                'acesso_colaborador'  => MlbImplementacao::ONB_ACESSO_COLABORADOR_OPCOES,
                'planilha_produtos'   => MlbImplementacao::ONB_PLANILHA_PRODUTOS_OPCOES,
                'listagem'            => MlbImplementacao::ONB_LISTAGEM_OPCOES,
                'publicacao'          => MlbImplementacao::ONB_PUBLICACAO_OPCOES,
                'decola'              => MlbImplementacao::ONB_DECOLA_OPCOES,
                'central_promocao'    => MlbImplementacao::ONB_CENTRAL_PROMOCAO_OPCOES,
                'me1'                 => MlbImplementacao::ONB_ME1_OPCOES,
                'integradora'         => MlbImplementacao::ONB_INTEGRADORA_OPCOES,
                'places'              => MlbImplementacao::ONB_PLACES_OPCOES,
                'erp'                 => MlbImplementacao::ONB_ERP_OPCOES,
            ],
        ]);
    }

    /**
     * Retorna dados do seller no ECF Drive para o card "Dados do ML" da ficha.
     *
     * Chamado assincronamente pelo front após a ficha carregar — não bloqueia a
     * abertura da ficha (ONB-14). Retorna JSON puro (não Inertia).
     *
     * Consome: seller(), sellerMetricasMensal(programa=POLOS, fields=*) e
     * sellerMedalhas() via EcfDriveService por $impl->empresa->cust_id.
     *
     * Respostas de erro sempre retornam HTTP 200 com campo descritivo para não
     * quebrar a ficha no front (ONB-14):
     *  - semCustId=true  : empresa sem Cust ID no cadastro MLB
     *  - naoEncontrada=true: cust_id não encontrado no ECF Drive (404)
     *  - erro=string     : ECF Drive offline ou erro genérico (sem stacktrace ao cliente)
     *
     * @see EcfDriveService::seller()
     * @see EcfDriveService::sellerMetricasMensal()
     * @see EcfDriveService::sellerMedalhas()
     */
    public function dadosMl(Request $request, MlbImplementacao $impl): JsonResponse
    {
        // Gate 403 — mesma lógica da ficha (admin / perms.empresas / role pub)
        $this->checkAccess($request);

        $impl->load('empresa');

        // cust_id é coluna direta em mlb_empresas — NÃO é o accessor Company::cust_id
        $custId = $impl->empresa->cust_id;

        // Estado vazio: empresa sem Cust ID no cadastro ML (sem chamar o ECF Drive)
        if (empty($custId)) {
            return response()->json([
                'semCustId'     => true,
                'naoEncontrada' => false,
                'erro'          => null,
            ]);
        }

        try {
            // Snapshot do seller (sem cache) — medalha atual e programa
            $sellerResp = $this->ecf->seller($custId);

            // Série histórica filtrada por POLOS com todos os campos (fields=* é OBRIGATÓRIO
            // para tgmv_lc_me2, tgmv_lc_flex, total_livelistings — Pitfall 1 do RESEARCH)
            $metricasResp = $this->ecf->sellerMetricasMensal($custId, [
                'programa' => 'POLOS',
                'fields'   => '*',
            ]);

            // Histórico de medalhas (cache 6h) — confirma nível atual
            $this->ecf->sellerMedalhas($custId);

            // A API retorna vários meses, e o mês CORRENTE costuma vir com GMV/anúncios
            // nulos (em curso). A ordem do payload não é garantida. Ordena por timMonthId
            // desc e escolhe o mês mais recente que JÁ tenha GMV ou anúncios; se nenhum
            // tiver, cai no mais recente (ainda serve para nível/medalha). [] = seller M0.
            $metricas = $metricasResp['data'] ?? [];
            usort($metricas, fn ($a, $b) => ($b['timMonthId'] ?? '') <=> ($a['timMonthId'] ?? ''));
            $comDados = array_values(array_filter(
                $metricas,
                fn ($m) => ($m['tgmvLc'] ?? null) !== null || ($m['totalLivelistings'] ?? null) !== null
            ));
            $ultimo = $comDados[0] ?? ($metricas[0] ?? null);

            // Campos do ECF Drive vêm em camelCase (NÃO no snake_case do glossário SFTP).
            // Places não tem GMV atual no payload de /metricas/mensal — só o forecast
            // fTgmvLcPlaces; usamos ele como sinal de atividade do canal. Acesso defensivo.
            $gmvMe2    = $ultimo ? (float) ($ultimo['tgmvLcMe2']     ?? 0) : 0.0;
            $gmvFlex   = $ultimo ? (float) ($ultimo['tgmvLcFlex']    ?? 0) : 0.0;
            $gmvPlaces = $ultimo ? (float) ($ultimo['fTgmvLcPlaces'] ?? 0) : 0.0;

            // Nível/medalha: medalhaAtual do snapshot pode vir nula (ex.: seller ONBOARDING) —
            // cai no nivelSolucion do mês mais recente das métricas POLOS.
            $medalha  = $sellerResp['medalhaAtual']['nivelSolucion'] ?? ($ultimo['nivelSolucion'] ?? null);
            $programa = $sellerResp['medalhaAtual']['programa']      ?? ($ultimo['programa']      ?? null);

            // ─── Reputação (ONB-15) ─────────────────────────────────────────────────
            // As taxas chegam como string numérica do ECF Drive (ex: '0', '2.5').
            // Alerta dispara apenas quando alguma taxa, convertida para float, for > 0:
            // '0' e '0.0' não disparam; '2' e '0.5' disparam. Acesso defensivo ?? '0'.
            // Nenhuma nova chamada de API — tudo lido de $ultimo já obtido acima.
            if ($ultimo !== null) {
                $taxaClaims      = $ultimo['repClaimsRate']                ?? '0';
                $taxaDisputas    = $ultimo['repDisputesRate']               ?? '0';
                $taxaCancelamentos = $ultimo['repSellerCancellationsRate']  ?? '0';
                $taxaAtraso      = $ultimo['repDelayedHtRate']              ?? '0';

                $reputacao = [
                    'nivel'  => $ultimo['repCurrentLevel'] ?? null,
                    'taxas'  => [
                        'claims'        => $taxaClaims,
                        'disputas'      => $taxaDisputas,
                        'cancelamentos' => $taxaCancelamentos,
                        'atraso'        => $taxaAtraso,
                    ],
                    // Alerta ativo quando qualquer taxa convertida para float for > 0
                    'alerta' => (float) $taxaClaims       > 0
                             || (float) $taxaDisputas     > 0
                             || (float) $taxaCancelamentos > 0
                             || (float) $taxaAtraso        > 0,
                ];
            } else {
                $reputacao = null;
            }

            // ─── Maturidade (ONB-16) ────────────────────────────────────────────────
            // Nenhuma nova chamada de API — campos lidos de $ultimo já disponível.
            $maturidade = $ultimo !== null ? [
                'meses'      => (int) ($ultimo['mesesNoPrograma']   ?? 0),
                'cluster'    => $ultimo['clusterSeller']            ?? null,
                'subCluster' => $ultimo['subClusterSeller']         ?? null,
            ] : null;

            // ─── Série (ONB-17) ─────────────────────────────────────────────────────
            // Todos os meses de $metricas (já obtidos pelo sellerMetricasMensal) mapeados
            // para {mes, gmv, visitas}. O GMV nulo do mês corrente (em curso) é PRESERVADO
            // como null — NÃO convertido para 0, pois ausência de dado difere de zero vendas.
            // $metricas está ordenado DESC (usort acima); array_reverse reverte para ASC,
            // expondo a tendência cronológica mês a mês ao frontend. Sem nova chamada de API.
            $serie = array_values(array_map(
                fn ($m) => [
                    'mes'     => $m['timMonthId'] ?? null,
                    'gmv'     => isset($m['tgmvLc']) ? $m['tgmvLc'] : null,
                    'visitas' => isset($m['visitas']) ? (int) $m['visitas'] : null,
                ],
                array_reverse($metricas)
            ));

            // ─── Vendas & Forecast (novos blocos) ──────────────────────────────
            // tsi = itens vendidos (string→int), fTgmvLc = GMV previsto (string→float).
            // Preservar null quando a fonte for null — não forçar 0 (seller ONBOARDING).
            $vendasItens      = isset($ultimo['tsi'])      ? (int)   $ultimo['tsi']      : null;
            $vendasForecastGmv = isset($ultimo['fTgmvLc']) ? (float) $ultimo['fTgmvLc'] : null;

            // ─── Tráfego ────────────────────────────────────────────────────────
            // visitas e visitasClips em int; null quando ausentes.
            $trafegoVisitas = isset($ultimo['visitas'])      ? (int) $ultimo['visitas']      : null;
            $trafegoClips   = isset($ultimo['visitasClips']) ? (int) $ultimo['visitasClips'] : null;

            // ─── Contexto ───────────────────────────────────────────────────────
            // custState (ex: "BR-RS"), subPolo (ex: "MOVEIS"), hL (ex: "HIGH TOUCH").
            $contextEstado     = $ultimo['custState'] ?? null;
            $contextSubPolo    = $ultimo['subPolo']   ?? null;
            $contextAtendimento = $ultimo['hL']       ?? null;

            // ─── Anúncios & Qualidade (sellers maduros — pode vir nulo p/ ONBOARDING) ──
            // newListers = novos listadores (int), itensFull (int),
            // scoreQualidadeFinal (número). Preservar null quando ausente.
            $qualNovosListadores = isset($ultimo['newListers'])          ? (int)   $ultimo['newListers']          : null;
            $qualItensFull       = isset($ultimo['itensFull'])           ? (int)   $ultimo['itensFull']           : null;
            $qualScore           = isset($ultimo['scoreQualidadeFinal']) ? (float) $ultimo['scoreQualidadeFinal'] : null;

            return response()->json([
                'semCustId'      => false,
                'naoEncontrada'  => false,
                'erro'           => null,
                'medalha'        => $medalha,
                'programa'       => $programa,
                'anunciosAtivos' => $ultimo ? (int) ($ultimo['totalLivelistings'] ?? 0) : null,
                'gmvPolos'       => $ultimo ? (float) ($ultimo['tgmvLc'] ?? 0)           : null,
                'canais'         => [
                    'me2'    => ['ativo' => $gmvMe2    > 0, 'gmv' => $gmvMe2],
                    'flex'   => ['ativo' => $gmvFlex   > 0, 'gmv' => $gmvFlex],
                    'places' => ['ativo' => $gmvPlaces > 0, 'gmv' => $gmvPlaces],
                ],
                'mesRef'     => $ultimo ? ($ultimo['timMonthId'] ?? null) : null,
                'reputacao'  => $reputacao,
                'maturidade' => $maturidade,
                'serie'      => $serie,
                // ─── Novos blocos (Phase 37 expansão) ────────────────────────
                'vendas'    => $ultimo !== null ? [
                    'itens'       => $vendasItens,
                    'forecastGmv' => $vendasForecastGmv,
                ] : null,
                'trafego'   => $ultimo !== null ? [
                    'visitas' => $trafegoVisitas,
                    'clips'   => $trafegoClips,
                ] : null,
                'contexto'  => $ultimo !== null ? [
                    'estado'      => $contextEstado,
                    'subPolo'     => $contextSubPolo,
                    'atendimento' => $contextAtendimento,
                ] : null,
                'qualidade' => $ultimo !== null ? [
                    'novosListadores' => $qualNovosListadores,
                    'itensFull'       => $qualItensFull,
                    'scoreQualidade'  => $qualScore,
                ] : null,
            ]);

        } catch (\Throwable $e) {
            // 404 no ECF Drive: seller não cadastrado (cust_id inexistente)
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return response()->json([
                    'semCustId'     => false,
                    'naoEncontrada' => true,
                    'erro'          => null,
                ]);
            }

            // Qualquer outro erro (500, timeout, etc.) — registra no log, não vaza stacktrace
            report($e);
            return response()->json([
                'semCustId'     => false,
                'naoEncontrada' => false,
                'erro'          => 'Não foi possível buscar dados do ML agora. Tente recarregar.',
            ]);
        }
    }

    public function indicadores(Request $request)
    {
        $this->checkAccess($request);

        $impls = MlbImplementacao::with('empresa.responsavel')
            ->orderBy('created_at', 'desc')
            ->get();

        $checklist   = MlbImplementacao::CHECKLIST;
        $checkIds    = array_column($checklist, 'id');
        $checkTitles = array_column($checklist, 'titulo', 'id');
        $agora       = now();

        $itemStats    = array_fill_keys($checkIds, ['feitos' => 0, 'pendentes' => 0, 'total' => 0]);
        $statusCounts = ['concluida' => 0, 'em_andamento' => 0, 'parada' => 0, 'nao_iniciada' => 0];
        $somaProgresso = 0;

        $empresasList = $impls->map(function ($impl) use ($checkIds, $checkTitles, $agora, &$itemStats, &$statusCounts, &$somaProgresso) {
            $progresso    = $impl->progresso();
            $itens        = $impl->dados['itens'] ?? [];
            $empresa      = $impl->empresa;
            $ultimoAcesso = $impl->ultimo_acesso;
            $diasSem      = $ultimoAcesso ? (int) $agora->diffInDays($ultimoAcesso) : null;

            foreach ($checkIds as $id) {
                $feito = $itens[$id]['feito'] ?? false;
                $itemStats[$id]['total']++;
                $feito ? $itemStats[$id]['feitos']++ : $itemStats[$id]['pendentes']++;
            }

            $pct = $progresso['pct'];

            if ($pct === 100) {
                $status = 'concluida';
            } elseif ($progresso['feitos'] === 0) {
                $status = 'nao_iniciada';
            } elseif ($diasSem === null || $diasSem > 7) {
                $status = 'parada';
            } else {
                $status = 'em_andamento';
            }

            $statusCounts[$status]++;
            $somaProgresso += $pct;

            return [
                'id'            => $empresa->id,
                'nome'          => $empresa->nome,
                'estagio'       => $empresa->estagio,
                // Fase ("M": M0–M4, além de ASSESSORIA/Incubadora/Implantação) — alimenta o filtro por M nos indicadores.
                'fase'          => $empresa->fase,
                'responsavel'   => $empresa->responsavel?->name ?? '—',
                'criado_em'     => $impl->created_at->format('d/m/Y'),
                'ultimo_acesso' => $ultimoAcesso?->format('d/m/Y H:i'),
                'dias_sem'      => $diasSem,
                'status'        => $status,
                'progresso'     => $progresso,
                'itens'         => collect($checkIds)->map(fn($id) => [
                    'id'     => $id,
                    'titulo' => $checkTitles[$id] ?? $id,
                    'feito'  => $itens[$id]['feito'] ?? false,
                ])->values()->all(),
            ];
        })->values()->all();

        $total = count($empresasList);

        $dificuldades = array_values(array_map(function ($id) use ($itemStats, $checkTitles) {
            $s = $itemStats[$id];
            return [
                'id'            => $id,
                'titulo'        => $checkTitles[$id] ?? $id,
                'feitos'        => $s['feitos'],
                'pendentes'     => $s['pendentes'],
                'total'         => $s['total'],
                'pct_concluido' => $s['total'] > 0 ? round($s['feitos']    / $s['total'] * 100) : 0,
                'pct_pendente'  => $s['total'] > 0 ? round($s['pendentes'] / $s['total'] * 100) : 0,
            ];
        }, $checkIds));

        usort($dificuldades, fn($a, $b) => $b['pct_pendente'] - $a['pct_pendente']);

        return Inertia::render('Mlb/ImplementacaoIndicadores', [
            'total'           => $total,
            'media_progresso' => $total > 0 ? round($somaProgresso / $total) : 0,
            'status_counts'   => $statusCounts,
            'dificuldades'    => $dificuldades,
            'empresas'        => $empresasList,
        ]);
    }

    public function index(Request $request)
    {
        $this->checkAccess($request);

        $globalPadroes = MlbConfiguracao::implementacaoPadroes();

        // Filtros opcionais por Polo e Fase (ONB-01) — filtram em mlb_empresas via whereHas
        $filtroPolo        = $request->query('polo');
        $filtroFase        = $request->query('fase');
        // Filtro de prazo (ONB-10): boolean — '1'/'true'/'on' → true; ausente/'' → false
        $filtroForaDoPrazo = $request->boolean('fora_do_prazo');
        // Filtro de envio do link (ONB-ENVIO-LINK): boolean — mostra só quem ainda falta enviar
        $filtroFaltaEnviar = $request->boolean('falta_enviar');

        $query = MlbImplementacao::with(['empresa', 'responsavel', 'linkEnviadoPor'])
            ->orderBy('created_at', 'desc');

        if ($filtroPolo) {
            $query->whereHas('empresa', fn($q) => $q->where('polo', $filtroPolo));
        }

        if ($filtroFase) {
            $query->whereHas('empresa', fn($q) => $q->where('fase', $filtroFase));
        }

        // Nota: fora_do_prazo e falta_enviar NÃO podem ser filtrados via whereHas
        // (cálculos dependem de PHP/JSON, não de colunas SQL simples).
        // Ambos os filtros são aplicados na Collection após o get().
        $empresas = $query->get()
            ->map(function ($impl) {
                $e     = $impl->empresa;
                $prazo = $impl->infoPrazo();
                return [
                    'id'             => $e->id,
                    'nome'           => $e->nome,
                    // Cust ID inline na listagem (copiar/criar/editar) — mesma célula do Painel Polos
                    'cust_id'        => $e->cust_id,
                    'estagio'        => $e->estagio,
                    'polo'           => $e->polo,
                    'fase'           => $e->fase,
                    'impl_id'        => $impl->id,
                    'token'          => $impl->token,
                    'dados'          => $impl->dados,
                    'progresso'      => $impl->progresso(),
                    'ultimo_acesso'  => $impl->ultimo_acesso?->diffForHumans(),
                    // Dados de prazo (ONB-09)
                    'fora_do_prazo'  => $prazo['fora_do_prazo'],
                    'dias_restantes' => $prazo['dias_restantes'],
                    'dias_decorridos'=> $prazo['dias_decorridos'],
                    // Rastreio de envio do link (ONB-ENVIO-LINK)
                    'status_envio'     => $impl->statusEnvio(),
                    'link_enviado_em'  => $impl->link_enviado_em?->format('d/m/Y'),
                    'link_enviado_por' => $impl->linkEnviadoPor?->name,
                    // Responsável pelo onboarding (ONB-RESPONSAVEL)
                    'responsavel_id'   => $impl->responsavel_id,
                    'responsavel_nome' => $impl->responsavel?->name,
                ];
            })
            // Filtro de prazo em Collection — aplicado após Polo/Fase (ONB-10)
            ->when($filtroForaDoPrazo, fn($col) => $col->filter(fn($e) => $e['fora_do_prazo']))
            // Filtro "falta enviar link" em Collection — depende de statusEnvio() calculado acima
            ->when($filtroFaltaEnviar, fn($col) => $col->filter(fn($e) => $e['status_envio'] === 'falta_enviar'))
            ->values();

        return Inertia::render('Mlb/Implementacao', [
            'empresas'          => $empresas,
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
            'global_padroes'    => $globalPadroes,
            // Filtros ativos (para os selects da listagem)
            'filtros'           => [
                'polo'          => $filtroPolo,
                'fase'          => $filtroFase,
                'fora_do_prazo' => $filtroForaDoPrazo,
                'falta_enviar'  => $filtroFaltaEnviar,
            ],
            'polo_opcoes'       => MlbImplementacao::ONB_POLO_OPCOES,
            'fase_opcoes'       => MlbImplementacao::ONB_FASE_OPCOES,
            // Usuários ativos para o select de responsável (ONB-RESPONSAVEL)
            'usuarios'          => User::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function criar(Request $request)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'nome' => 'required|string|max:200',
        ]);

        $existing = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$validated['nome']])->first();

        if ($existing) {
            if ($existing->implementacao) {
                throw ValidationException::withMessages([
                    'nome' => "A empresa \"{$existing->nome}\" já existe e já possui uma implementação.",
                ]);
            }
            if (!$request->boolean('confirmar')) {
                throw ValidationException::withMessages([
                    'empresa_existente' => "A empresa \"{$existing->nome}\" já está cadastrada em Empresas. Deseja vincular a implementação a ela?",
                ]);
            }
        }

        $msg = '';

        DB::transaction(function () use ($validated, $request, $existing, &$msg) {
            if ($existing) {
                $empresa = $existing;
                $msg = "Onboarding vinculado à empresa existente \"{$empresa->nome}\".";
            } else {
                $empresa = MlbEmpresa::create([
                    'nome'       => $validated['nome'],
                    'tipo'       => 'POLO',
                    'projeto'    => 'POLOS',
                    'fase'       => 'M0',
                    'estagio'    => 'Não Listado',
                    'criado_por' => $request->user()->id,
                ]);
                $msg = 'Onboarding criado com sucesso.';
            }

            $impl = $this->criarImplementacaoPolo($empresa);

            // Preenche gmail do colaborador se vinculando empresa existente com gmail configurado
            if ($existing && !empty($empresa->gmail)) {
                $dados                                   = $impl->dados;
                $dados['links_admin']['gmail_colaborador'] = $empresa->gmail;
                $impl->update(['dados' => $dados]);
            }

            activity('implementacao')
                ->causedBy($request->user())
                ->withProperties(['empresa' => $empresa->nome, 'vinculada' => (bool) $existing])
                ->log('Implementação MLB criada para "' . $empresa->nome . '"');
        });

        return back()->with('success', $msg);
    }

    public function salvarPadroes(Request $request)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'tutorial_intro'           => 'nullable|string|max:500',
            'tutoriais'                => 'nullable|array',
            'tutoriais.*'              => 'nullable|string|max:500',
            'links_admin_extra'        => 'nullable|array',
            'links_admin_extra.*'      => 'nullable|string|max:500',
            // Mensagem de boas-vindas padrão do Onboarding
            'mensagem_boas_vindas'     => 'nullable|string|max:5000',
            // Grant por polo: mapa polo => {url, nome}
            'grants_por_polo'          => 'nullable|array',
            'grants_por_polo.*.url'    => 'nullable|string|max:500',
            'grants_por_polo.*.nome'   => 'nullable|string|max:200',
        ]);

        MlbConfiguracao::get()->update([
            'implementacao_defaults' => $validated,
        ]);

        return back()->with('success', 'Padrões globais salvos. Serão aplicados em novas implementações.');
    }

    public function gerarLink(Request $request, MlbEmpresa $empresa)
    {
        $this->checkAccess($request);

        $impl = MlbImplementacao::firstOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'token' => Str::random(48),
                'dados' => MlbImplementacao::dadosPadrao(),
            ]
        );

        $msg = $impl->wasRecentlyCreated ? 'Link de implementação gerado.' : 'Empresa já possui link de implementação.';
        return back()->with('success', $msg);
    }

    public function atualizarTutoriais(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'tutorial_intro'                    => 'nullable|string|max:500',
            'prazo_data'                        => 'nullable|date_format:Y-m-d',
            'tutoriais'                         => 'required|array',
            'tutoriais.*'                       => 'nullable|string|max:500',
            'links_admin'                       => 'nullable|array',
            'links_admin.*'                     => 'nullable|string|max:500',
            'precificacao_config'               => 'nullable|array',
            'precificacao_config.classico'      => 'nullable|array',
            'precificacao_config.classico.*'    => 'nullable|numeric',
            'precificacao_config.premium'       => 'nullable|array',
            'precificacao_config.premium.*'     => 'nullable|numeric',
        ]);

        $dados    = $impl->dados ?? MlbImplementacao::dadosPadrao();
        $defaults = MlbImplementacao::dadosPadrao()['itens']['precificacao'];

        $dados['tutorial_intro'] = $validated['tutorial_intro'] ?? '';
        $dados['prazo_data']     = $validated['prazo_data']     ?? '';
        $dados['tutoriais']      = array_merge($dados['tutoriais'] ?? [], $validated['tutoriais']);
        if (!empty($validated['links_admin'])) {
            $dados['links_admin'] = array_merge($dados['links_admin'] ?? [], $validated['links_admin']);
        }
        if (!empty($validated['precificacao_config'])) {
            $dados['itens']['precificacao']['classico'] = array_merge(
                $dados['itens']['precificacao']['classico'] ?? $defaults['classico'],
                $validated['precificacao_config']['classico'] ?? []
            );
            $dados['itens']['precificacao']['premium'] = array_merge(
                $dados['itens']['precificacao']['premium'] ?? $defaults['premium'],
                $validated['precificacao_config']['premium'] ?? []
            );
        }
        $impl->update(['dados' => $dados]);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Configurações de implementação atualizadas para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Configurações atualizadas.');
    }

    public function sincronizarSkus(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $produtos = $impl->dados['itens']['planilha_produtos']['produtos'] ?? [];

        $skus = array_values(array_filter(
            array_map(fn($p) => trim($p['sku'] ?? ''), $produtos),
            fn($s) => $s !== ''
        ));

        $toSkuArray = fn($list) => array_values(array_map(
            fn($s) => ['sku' => $s, 'ok' => false, 'concluido_em' => null, 'atrasado' => false],
            $list
        ));

        $impl->empresa->update([
            'skus_estagio1' => $toSkuArray(array_slice($skus, 0, 3)),
            'skus_estagio2' => $toSkuArray(array_slice($skus, 3, 4)),
            'skus_estagio3' => $toSkuArray(array_slice($skus, 7, 3)),
        ]);

        return back()->with('success', count($skus) . ' SKU(s) sincronizado(s) para os estágios da empresa.');
    }

    public function destroy(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);
        $impl->delete();
        return back()->with('success', 'Onboarding removido. A empresa permanece em Empresas.');
    }

    // ─── Saves por bloco da ficha de Onboarding (ONB-06) ────────────────────

    /**
     * Salva o bloco Identificação da ficha.
     *
     * Polo e Fase vivem em mlb_empresas (ONB-03 + Pitfall 1):
     * NUNCA chamar $impl->update(['fase' => ...]) — coluna não existe em mlb_implementacoes.
     * Data de solicitação fica em mlb_implementacoes (campo operacional do onboarding).
     */
    public function salvarBlocoIdentificacao(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        // "Criar novo valor" (Painel Polos): os selects inline deixam o operador cadastrar um
        // valor fora do catálogo, e ele passa a aparecer para as demais empresas via
        // `valoresPresentes`. Por isso os campos criáveis são TEXTO LIVRE com limite de
        // tamanho — as ONB_*_OPCOES são só as sugestões iniciais do dropdown.
        //
        // EXCEÇÃO: `fase` continua fechada. Ela alimenta MlbEmpresa::FASE_PARA_PROJETO, que
        // decide se a empresa pertence ao projeto POLOS; uma fase inventada faria a empresa
        // sumir do painel sem aviso. O front não oferece "criar novo valor" para fase.
        $validated = $request->validate([
            'polo'             => ['nullable', 'string', 'max:100'],
            'fase'             => ['nullable', 'string', Rule::in(MlbImplementacao::ONB_FASE_OPCOES)],
            'data_solicitacao' => ['nullable', 'date'],
            'status_entrada'   => ['nullable', 'string', 'max:150'],
            'chance_entrada'   => ['nullable', 'string', 'max:60'],
        ]);

        // Polo e Fase → mlb_empresas (NÃO em mlb_implementacoes)
        $dadosEmpresa = array_filter([
            'polo' => $validated['polo'] ?? null,
            'fase' => $validated['fase'] ?? null,
        ], fn($v) => !is_null($v));

        if (!empty($dadosEmpresa)) {
            $impl->empresa->update($dadosEmpresa);
        }

        // Data de solicitação + entrada → mlb_implementacoes (só o que veio no request)
        $dadosImpl = [];
        foreach (['data_solicitacao', 'status_entrada', 'chance_entrada'] as $campo) {
            if (array_key_exists($campo, $validated)) {
                $dadosImpl[$campo] = $validated[$campo];
            }
        }
        if (!empty($dadosImpl)) {
            $impl->update($dadosImpl);
        }

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Bloco Identificação atualizado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Identificação atualizada.');
    }

    /**
     * Salva o bloco Acessos & Setup da ficha.
     */
    public function salvarBlocoAcessos(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            // Texto livre (ver nota "Criar novo valor" em salvarBlocoIdentificacao).
            'acesso_colaborador' => ['nullable', 'string', 'max:150'],
            'gmail_colaborador'  => ['nullable', 'string', 'max:150'],
            // Reunião de onboarding (planilha V2) — Sim/Não/Agendada/Não compareceu (texto livre).
            'reuniao_onboarding' => ['nullable', 'string', 'max:60'],
            // Link do grupo de WhatsApp (quick 260810-dv6). Não valida como 'url': o time
            // cola convite (chat.whatsapp.com/...), wa.me e às vezes sem o https:// — a UI
            // normaliza o href na hora de abrir. Guardar o que foi digitado.
            'link_whatsapp'      => ['nullable', 'string', 'max:255'],
            // grupo_whatsapp: aceita boolean, '0'/'1' e também strings 'true'/'false'
            // validado separadamente via $request->boolean() para maior compatibilidade
        ]);

        // Extrair campo boolean com $request->boolean() que aceita 'true', '1', true, etc.
        if ($request->has('grupo_whatsapp')) {
            $validated['grupo_whatsapp'] = $request->boolean('grupo_whatsapp');
        }

        $impl->update($validated);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Bloco Acessos atualizado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Acessos & Setup atualizados.');
    }

    /**
     * Salva o bloco Produtos & Publicação da ficha.
     */
    public function salvarBlocoProdutos(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            // Texto livre (ver nota "Criar novo valor" em salvarBlocoIdentificacao):
            // ONB_*_OPCOES são SUGESTÕES no select, não um enum fechado.
            'planilha_produtos' => ['nullable', 'string', 'max:150'],
            'listagem'          => ['nullable', 'string', 'max:150'],
            'publicacao'        => ['nullable', 'string', 'max:150'],
            // decola: era boolean até 2026-08-03; hoje é texto (Sim/Não/Mensagem Enviada/…)
            'decola'            => ['nullable', 'string', 'max:60'],
            // central_promocao: adesão à Central de Promoções do ML (planilha V2, 2026-08-26).
            'central_promocao'  => ['nullable', 'string', 'max:150'],
        ]);

        // Extrair campos boolean com $request->boolean() que aceita 'true', '1', true, etc.
        if ($request->has('campanha_criada')) {
            $validated['campanha_criada'] = $request->boolean('campanha_criada');
        }

        $impl->update($validated);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Bloco Produtos & Publicação atualizado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Produtos & Publicação atualizados.');
    }

    /**
     * Salva o bloco Logística & Integrações da ficha.
     */
    public function salvarBlocoLogistica(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'contextos_logistica' => ['nullable', 'string'],
            // Texto livre (ver nota "Criar novo valor" em salvarBlocoIdentificacao).
            'me1'                 => ['nullable', 'string', 'max:150'],
            'integradora'         => ['nullable', 'string', 'max:150'],
            'places'              => ['nullable', 'string', 'max:150'],
            'erp'                 => ['nullable', 'string', 'max:150'],
        ]);

        // Trava manual do ME1 (quick 260722-nwc): ao MUDAR o me1 na mão para um valor
        // concreto, trava para a regra do Mercado Envios não sobrescrever mais; limpar destrava.
        if (array_key_exists('me1', $validated) && $validated['me1'] !== $impl->me1) {
            $validated['me1_manual'] = ($validated['me1'] !== null && $validated['me1'] !== '');
        }

        $impl->update($validated);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('Bloco Logística & Integrações atualizado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Logística & Integrações atualizados.');
    }

    // ─── Rastreio de envio do link e responsável (ONB-ENVIO-LINK / ONB-RESPONSAVEL) ──

    /**
     * Marca manualmente que o link do cliente foi enviado à empresa.
     * Grava quem enviou (usuário logado) e quando (now()).
     */
    public function marcarLinkEnviado(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $impl->update([
            'link_enviado_em'  => now(),
            'link_enviado_por' => $request->user()->id,
        ]);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('[Onboarding] Link marcado como enviado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Link marcado como enviado.');
    }

    /**
     * Desfaz o envio do link, limpando quem/quando (permite re-registro correto).
     */
    public function desfazerEnvio(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $impl->update([
            'link_enviado_em'  => null,
            'link_enviado_por' => null,
        ]);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('[Onboarding] Envio do link desfeito para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Envio desfeito.');
    }

    /**
     * Atribui (ou remove) o responsável pelo onboarding.
     */
    public function atribuirResponsavel(Request $request, MlbImplementacao $impl)
    {
        $this->checkAccess($request);

        $validated = $request->validate([
            'responsavel_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $impl->update(['responsavel_id' => $validated['responsavel_id'] ?? null]);

        activity('implementacao')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $impl->empresa->nome])
            ->log('[Onboarding] Responsável atualizado para "' . $impl->empresa->nome . '"');

        return back()->with('success', 'Responsável atualizado.');
    }

    // ─── Público (sem autenticação) ──────────────────────────────────────────

    public function publicador(string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();

        $dados = $impl->dados ?? MlbImplementacao::dadosPadrao();

        // Coluna por-empresa (cadastro/ficha) tem precedência sobre o padrão global
        // da ECF no passo "Acesso Colaborador" (lê dados.links_admin.gmail_colaborador).
        if (!empty($impl->gmail_colaborador)) {
            $dados['links_admin']['gmail_colaborador'] = $impl->gmail_colaborador;
        }

        // Link do App ECF agora é GLOBAL (configurado nos Padrões Globais) — serve
        // todas as contas. Injetado em tempo de render para que empresas existentes
        // peguem o link sem migração.
        $dados['links_admin']['app_ecf'] = MlbConfiguracao::implementacaoPadroes()['links_admin_extra']['app_ecf'] ?? '';

        return Inertia::render('Mlb/ImplementacaoPublicador', [
            'impl' => [
                'token'        => $impl->token,
                'empresa_nome' => $impl->empresa->nome,
                'dados'        => $dados,
                'criado_em'    => $impl->created_at->format('d/m/Y'),
                // Mapa {sku => true} do check-in do publicador (implementações
                // antigas não têm a chave — default vazio).
                'checkin'      => (object) ($dados['publicador_checkin'] ?? []),
            ],
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
        ]);
    }

    /**
     * Marca/desmarca o check-in de um SKU na visão do Publicador.
     *
     * Rota pública por token (mesmo modelo do resto de /implementacao/*). Grava em
     * `dados.publicador_checkin` — chave top-level, FORA de itens.planilha_produtos,
     * para que o salvamento da planilha pelo cliente (que reescreve o array inteiro
     * de produtos) não apague o que o publicador já marcou.
     *
     * @return JsonResponse {ok: bool, total: int} — total de SKUs marcados
     */
    public function checkinPublicador(Request $request, string $token): JsonResponse
    {
        $impl = MlbImplementacao::where('token', $token)->firstOrFail();

        $request->validate([
            'sku'   => ['required', 'string', 'max:120'],
            'feito' => ['required', 'boolean'],
        ]);

        $sku   = trim($request->string('sku')->toString());
        $feito = $request->boolean('feito');

        abort_if($sku === '', 422);

        $dados   = $impl->dados ?? MlbImplementacao::dadosPadrao();
        $checkin = $dados['publicador_checkin'] ?? [];

        if ($feito) {
            $checkin[$sku] = true;
        } else {
            unset($checkin[$sku]);
        }

        $dados['publicador_checkin'] = $checkin;
        $impl->update(['dados' => $dados]);

        return response()->json(['ok' => true, 'total' => count($checkin)]);
    }

    /**
     * GET /implementacao/{token}/conectar/ml — porta pública do OAuth do Mercado
     * Livre para o cliente de Polos, aberta pelo link da mensagem de boas-vindas.
     *
     * POR QUE O LINK NÃO EXPIRA: o que vai no WhatsApp é ESTA rota, com o token
     * da implementação (`Str::random(48)`, unique, sem validade). A URL do ML —
     * cujo `state` vive 7 dias no cache — só é gerada no clique. Mesmo padrão de
     * `onboarding.publico.conectar-ml` (Gestão), que resolveu isso primeiro.
     *
     * Não confundir com o `{link_grant}` da mesma mensagem: aquele é o programa
     * de Partners do Mercado Livre, um por polo, igual para a região inteira —
     * por isso não diz quem autorizou. Este identifica a empresa e captura o
     * Cust ID. Os dois convivem.
     */
    public function conectarMercadoLivre(string $token, \App\Services\MercadoLivreService $ml)
    {
        $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();

        // Implementação órfã (empresa removida) não tem a quem vincular a conta.
        abort_if(! $impl->empresa, 404);

        $url = $ml->buildAuthUrlPolos($impl->empresa);

        activity('mlb')
            ->performedOn($impl->empresa)
            ->log('Cliente iniciou a autorização do Mercado Livre pelo link do Onboarding');

        return redirect()->away($url);
    }

    public function workspace(string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();
        $impl->update(['ultimo_acesso' => now()]);

        $dados = $impl->dados ?? MlbImplementacao::dadosPadrao();

        // O gmail capturado no cadastro (Comercial) ou na ficha de Onboarding fica na
        // COLUNA gmail_colaborador. O passo "Acesso Colaborador" do Onboarding lê de
        // dados.links_admin.gmail_colaborador — então a coluna por-empresa tem
        // precedência sobre o padrão global da ECF quando preenchida.
        if (!empty($impl->gmail_colaborador)) {
            $dados['links_admin']['gmail_colaborador'] = $impl->gmail_colaborador;
        }

        // Link do App ECF agora é GLOBAL (configurado nos Padrões Globais) — serve
        // todas as contas. Injetado em tempo de render para que empresas existentes
        // peguem o link sem migração.
        $dados['links_admin']['app_ecf'] = MlbConfiguracao::implementacaoPadroes()['links_admin_extra']['app_ecf'] ?? '';

        // Prazo automático de 5 dias (Frente 5 / ONB-09): data-limite = início + 5 dias.
        $prazo       = $impl->infoPrazo();
        $prazoLimite = \Carbon\Carbon::parse($prazo['data_inicio'])->addDays(5)->format('Y-m-d');

        // Link global da Tabela de Frete (configurado nos Padrões Globais)
        $tabelaFreteUrl = MlbConfiguracao::implementacaoPadroes()['links_admin_extra']['tabela_frete'] ?? '';

        return Inertia::render('Mlb/ImplementacaoPublica', [
            'impl' => [
                'token'        => $impl->token,
                'empresa_nome' => $impl->empresa->nome,
                'dados'        => $dados,
                'progresso'    => $impl->progresso(),
            ],
            'checklist'         => MlbImplementacao::CHECKLIST,
            'erp_opcoes'        => MlbImplementacao::ERP_OPCOES,
            'integrador_opcoes' => MlbImplementacao::INTEGRADOR_OPCOES,
            'prazo_data'        => $dados['prazo_data'] ?? '',
            'prazo_limite'      => $prazoLimite,
            'tabela_frete_url'  => $tabelaFreteUrl,
        ]);
    }

    public function salvarItem(Request $request, string $token)
    {
        $impl = MlbImplementacao::where('token', $token)->firstOrFail();

        $request->validate([
            'id'    => 'required|string',
            'campo' => 'required|string',
            'valor' => 'nullable',
        ]);

        $id    = $request->string('id')->toString();
        $campo = $request->string('campo')->toString();
        $valor = $request->input('valor');

        $dados = $impl->dados ?? MlbImplementacao::dadosPadrao();
        abort_unless(isset($dados['itens'][$id]), 422);

        $dados['itens'][$id][$campo] = $valor;

        // Trava anti-check-vazio: o cliente não pode marcar "feito" sem ter
        // preenchido o mínimo do item (ERP, planilha de produtos, precificação…).
        // Itens de ação pura (acessar link, dar acesso, declarar) passam direto.
        if ($campo === 'feito' && $valor) {
            $tipo = MlbImplementacao::tipoDoItem($id);
            if ($tipo !== null && !MlbImplementacao::itemTemConteudo($tipo, $dados['itens'][$id])) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Preencha as informações deste item antes de marcar como feito.',
                ], 422);
            }
        }

        $impl->update(['dados' => $dados]);

        // ME-ONBOARDING: ao salvar a Planilha de Produtos, o ME1 da empresa reflete
        // (de forma REATIVA) se as medidas da embalagem cabem no Mercado Envios:
        //   - alguma embalagem excede (maior lado > 200cm, soma > 300cm ou peso > 50kg)
        //     → marca "Precisa de ME1";
        //   - todas voltaram a caber E o valor atual é o automático "Precisa de ME1"
        //     → limpa (null).
        // Trava manual (me1_manual): a partir do momento em que o consultor edita o ME1
        // na mão (Painel Polos / ficha), o valor fica travado e a regra automática NÃO o
        // toca mais (nem marca nem limpa) — o consultor controla o status. Por isso o
        // "limpar" só age sobre o valor automático "Precisa de ME1", nunca sobre override.
        if ($id === 'planilha_produtos' && $campo === 'produtos') {
            $produtos = $dados['itens']['planilha_produtos']['produtos'] ?? [];
            if (is_array($produtos) && !$impl->me1_manual) {
                $excede = MlbImplementacao::planilhaExcedeMercadoEnvios($produtos);

                if ($excede && $impl->me1 !== 'Precisa de ME1') {
                    $impl->update(['me1' => 'Precisa de ME1']);
                    activity('implementacao')
                        ->withProperties(['empresa' => $impl->empresa->nome])
                        ->log('[Onboarding] ME1 definido como "Precisa de ME1" automaticamente — medidas da embalagem excedem o Mercado Envios na implementação de "' . $impl->empresa->nome . '" (cliente)');
                } elseif (!$excede && $impl->me1 === 'Precisa de ME1') {
                    $impl->update(['me1' => null]);
                    activity('implementacao')
                        ->withProperties(['empresa' => $impl->empresa->nome])
                        ->log('[Onboarding] ME1 automático limpo — medidas da embalagem voltaram a caber no Mercado Envios na implementação de "' . $impl->empresa->nome . '" (cliente)');
                }
            }
        }

        // Log público (cliente preenchendo o checklist) — sem usuário autenticado
        if ($campo === 'feito') {
            activity('implementacao')
                ->withProperties(['empresa' => $impl->empresa->nome, 'item' => $id, 'feito' => (bool) $valor])
                ->log('Item "' . $id . '" marcado como ' . ($valor ? 'concluído' : 'pendente') . ' na implementação de "' . $impl->empresa->nome . '" (cliente)');
        }

        return response()->json(['ok' => true, 'progresso' => $impl->progresso()]);
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Cria uma MlbImplementacao para uma empresa POLO com os dados padrão
     * configurados em MlbConfiguracao::implementacaoPadroes().
     *
     * Extraído de criar() para reutilização em ComercialController (D-20 do CONTEXT.md).
     * O caller fica responsável por atualizar campos extras (ex: gmail_colaborador)
     * se necessário após a criação.
     *
     * @param MlbEmpresa $empresa Empresa POLO já persistida.
     * @return MlbImplementacao
     */
    private function criarImplementacaoPolo(MlbEmpresa $empresa): MlbImplementacao
    {
        $dados = MlbImplementacao::dadosPadrao();
        $p     = MlbConfiguracao::implementacaoPadroes();

        if ($p['tutorial_intro']) {
            $dados['tutorial_intro'] = $p['tutorial_intro'];
        }
        if (!empty($p['tutoriais'])) {
            $dados['tutoriais'] = array_merge($dados['tutoriais'], $p['tutoriais']);
        }
        if (!empty($p['links_admin_extra'])) {
            $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
        }

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }
}
