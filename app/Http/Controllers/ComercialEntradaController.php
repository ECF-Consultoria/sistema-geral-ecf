<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\OnboardingLink;
use App\Services\BoasVindas\MensagemBoasVindasService;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use App\Services\ChecklistAdministrativo\ChecklistEtapaSincronizadorService;
use App\Services\ChecklistAdministrativo\FinalizarEntradaAdministrativaService;
use App\Services\Comercial\PendenciasComerciaisService;
use App\Services\Contratos\ContratosPresosService;
use App\Services\FluxoEntrada\TimelineEntradaService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

/**
 * ComercialEntradaController — Fase 151 (COMERC-01/02/03, D-01/D-02/D-06/
 * D-07/D-11/D-12/D-14).
 *
 * Módulo Entrada dentro da Área Comercial: lista as empresas em fluxo de
 * entrada com os 8 campos mínimos do §2. É a CASCA (D-06) — o checklist dos
 * 8 itens do módulo (grupo de WhatsApp, e-mail colaborador, links, mensagem
 * de boas-vindas etc.) chega na Fase 152.
 *
 * A separação entre esta lista e a lista Contrato (`ContratoAdminController`)
 * é por PROCESSO PENDENTE, nunca por `companies.etapa` (D-05) — a mesma
 * empresa pode e deve aparecer nas duas ao mesmo tempo (o Administrativo
 * revisa contrato E cria grupo de WhatsApp na mesma janela). A etapa entra
 * AQUI só como limite EXTERNO de "quem está em fluxo de entrada" (COMERC-03),
 * o mesmo corte que a D-07 exige.
 */
class ComercialEntradaController extends Controller
{
    public function index(Request $request, PendenciasComerciaisService $pendencias, ContratosPresosService $presos)
    {
        // (1) Sanitização snake_case com whitelist em PHP — mesmo padrão de
        // ComercialController::listagem()/ContratoAdminController::index().
        // Filtro por etapa NÃO entra nesta fase (Deferred no CONTEXT).
        $filters = [
            'q'     => $request->input('q'),
            'ordem' => in_array($request->input('ordem'), ['recentes', 'antigas'], true)
                ? $request->input('ordem')
                : 'recentes',
        ];

        // (2) Universo (COMERC-03 + D-07 + D-14): empresas ATIVAS nas
        // etapas 1 a 4 do fluxo de entrada (§10). A etapa aqui é o limite
        // EXTERNO de quem está em fluxo de entrada — JAMAIS o critério que
        // diferencia esta lista da lista Contrato (D-05 proíbe isso; a
        // lista Contrato mantém o critério dela — o estado do envelope
        // Clicksign, em `ContratoAdminController::index()`, que não muda
        // neste plano). A mesma empresa pode e deve aparecer nas duas ao
        // mesmo tempo — ver `ComercVisibilidadeAteEtapa5Test`.
        //
        // A 5ª etapa do vocabulário (aguardando_distribuicao — de propósito
        // fora da lista acima) fica DE FORA do universo — é o corte de
        // saída da D-07: a partir dela a Coordenação assume e o Comercial
        // deixa de precisar agir.
        //
        // `whereIn()` já exclui `etapa` NULL por padrão em SQL — satisfaz a
        // D-14 (empresa legada, nunca carimbada, nunca entra aqui) sem
        // precisar de nenhum `where` extra. Comentário aqui de propósito
        // para ninguém "consertar" isso depois achando que é bug.
        $query = Company::with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'hubspotEventos'   => fn ($q) => $q->orderByDesc('id')->limit(3),
            ])
            ->where('active', true)
            ->whereIn('etapa', [
                Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
                Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
                Company::ETAPA_AGUARDANDO_ASSINATURA,
                Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
            ])
            ->withExists(['hubspotEventoOrigem']);

        // (3) Busca por nome/CNPJ — SQL, com binding (nunca concatenado).
        if (filled($filters['q'])) {
            $qLike = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($w) use ($qLike) {
                $w->where('name', 'like', $qLike)->orWhere('cnpj', 'like', $qLike);
            });
        }
        $query->orderBy('created_at', $filters['ordem'] === 'antigas' ? 'asc' : 'desc');

        // (4) Materializa e anota is_origem_hubspot + pendências ANTES da
        // paginação (mesmo desenho de ComercialController::listagem()).
        $todasEmpresas = $query->get();

        // Duas pendências, NUNCA somadas (D-11):
        // - pendencia_fluxo (a da Fase 150, lida só por pendenciaAberta());
        // - pendencias_cadastro (as comerciais, D-13 cobre as DUAS portas).
        //
        // Atenção medida: calcular() devolve [] para empresa que não é de
        // origem HubSpot (REQ-37-10, guarda no topo do próprio serviço). A
        // lista Entrada contém as duas portas (D-13), incluindo cadastro
        // manual — sem este `if`, cadastro manual apareceria com ZERO
        // pendências de cadastro pra sempre, por desenho do serviço PendenciasComerciaisService,
        // não porque está tudo certo. calcularUniversais() cobre o mesmo
        // universo de qualquer origem (Fase 128, D-01).
        $todasEmpresas->each(function (Company $c) use ($pendencias) {
            $c->is_origem_hubspot   = (bool) ($c->hubspot_evento_origem_exists ?? false);
            $c->pendencias_cadastro = $c->is_origem_hubspot
                ? $pendencias->calcular($c)
                : $pendencias->calcularUniversais($c);
        });

        // (5) Paginação manual via LengthAwarePaginator (preserva
        // queryString) — mesmo padrão de ComercialController::listagem() /
        // ContratoAdminController::index().
        $perPage = 50;
        $page    = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $todasEmpresas->forPage($page, $perPage)->values(),
            $todasEmpresas->count(),
            $perPage,
            $page,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ],
        );

        // (6) Badge de contrato (D-08, Fase 131) — query ÚNICA para os
        // contratos da PÁGINA ATUAL, indexada por company_id. Mesma
        // disciplina de ComercialController::listagem(): NUNCA usar o
        // método "preso" do serviço de contratos presos aqui, ele
        // esconderia contrato saudável.
        $idsDaPagina = $paginator->getCollection()->pluck('id');
        $contratosPorEmpresa = ContratoAssinatura::whereIn('company_id', $idsDaPagina)
            ->whereHas('servico', fn ($q) => $q->where('exige_contrato', true))
            ->orderByDesc('id')
            ->get()
            ->groupBy('company_id')
            ->map(fn ($grupo) => $grupo->first());

        // (7) Payload — os 8 campos mínimos do §2, achatados por linha
        // (nunca o model inteiro, nunca dado de signatário).
        $companiesPaginadas = $paginator->getCollection()->map(function (Company $c) use ($contratosPorEmpresa, $presos) {
            $contratosAtivos = $c->contratosServico->where('ativo', true);
            $setorDominante  = $contratosAtivos->map(fn ($ct) => optional($ct->servico)->setor)->filter()->first();

            $contratoDaEmpresa = $contratosPorEmpresa->get($c->id);
            if ($contratoDaEmpresa) {
                // Caso 1: contrato encontrado.
                $contratoBadge = [
                    'status' => $contratoDaEmpresa->status,
                    'dias'   => $presos->diasParado($contratoDaEmpresa),
                ];
            } elseif ($contratosAtivos->contains(fn ($ct) => $ct->servico?->exigeContrato() === true)) {
                // Caso 2: sem ContratoAssinatura ainda, mas há serviço ativo
                // que exige contrato. `Company::ETAPAS[0]` devolve o mesmo
                // valor que `ComercialController::CONTRATO_BADGE_SEM_CONTRATO`
                // e `ContratoAdminController::SEM_CONTRATO` já usam — lido
                // pelo índice do vocabulário travado do §10 em vez de
                // duplicar a string à mão em mais um lugar do código.
                $contratoBadge = [
                    'status' => Company::ETAPAS[0],
                    'dias'   => (int) $c->created_at->diffInDays(now()),
                ];
            } else {
                // Caso 3: nenhum serviço ativo exige contrato (ex.: só Polos).
                $contratoBadge = null;
            }

            return [
                'id'              => $c->id,
                'name'            => $c->name,
                'cnpj'            => $c->cnpj,
                'servicos'        => $contratosAtivos->map(fn ($ct) => optional($ct->servico)->nome)->filter()->values()->all(),
                // Setor ECF (D-12) — mesma derivação de ComercialController::listagem().
                // O `industry` do HubSpot fica dentro de hubspot_snapshot_resumo.
                'setor_dominante' => $setorDominante,
                'origem'          => $c->is_origem_hubspot ? 'hubspot' : 'manual',
                // Responsável comercial (D-08) — null é NORMAL para
                // cadastro manual: nunca teve deal, nunca terá owner.
                'hubspot_owner_nome' => $c->hubspot_owner_nome,
                'data_venda'         => optional($c->data_venda)->format('Y-m-d'),
                'email_cliente'      => $c->email_cliente,
                'telefone'           => $c->telefone,
                'nome_contato'       => $c->nome_contato,
                'contrato_badge'     => $contratoBadge,
                // D-11 — duas pendências, chaves separadas e NUNCA somadas.
                // pendencia_fluxo é lida SÓ pelo ponto único pendenciaAberta()
                // (D-19 da Fase 150) — nenhum controller lê a coluna bruta direto.
                'pendencia_fluxo' => [
                    'aberta' => $c->pendenciaAberta(),
                    'motivo' => $c->pendencia_motivo,
                    'em'     => optional($c->pendencia_em)->toIso8601String(),
                ],
                'pendencias_cadastro' => $c->pendencias_cadastro,
                // Demais dados comerciais do HubSpot já expostos hoje pelo
                // modal Detalhes HubSpot (D-06/§2) — nunca o snapshot bruto
                // inteiro, nunca dado de signatário.
                'hubspot_snapshot_resumo' => [
                    'observacao'         => $c->hubspot_observacao,
                    'notas'              => $c->hubspot_notas ?? [],
                    'origem_lead'        => $c->origem_lead,
                    'spin'               => $c->hubspot_spin,
                    'hubspot_deal_id'    => $c->hubspot_deal_id,
                    'hubspot_company_id' => $c->hubspot_company_id,
                ],
                'etapa' => $c->etapa,
            ];
        })->values();

        $paginator->setCollection($companiesPaginadas);

        return Inertia::render('Comercial/Entrada', [
            'companies' => $paginator,
            'filters'   => $filters,
        ]);
    }

    /**
     * A ficha da ENTRADA de uma empresa — onde o checklist administrativo é
     * trabalhado e de onde a entrada é finalizada.
     *
     * ### Por que ela existe (11/09)
     * O checklist nasceu dentro da ficha de Contrato (Fase 152, D-08: "ficha
     * ÚNICA" aberta pelas duas listagens). Na prática confundiu: dos 9 itens,
     * só 3 são de contrato — os outros 6 são o que o PDF do fluxo chama de
     * "Estrutura e Comunicação" (grupo de WhatsApp, e-mail colaborador, link
     * Adman, grant do ML, Portal do Cliente). Ler tudo isso sob o título
     * "Contrato" fez o usuário pedir a separação; a ficha de Contrato voltou a
     * ser só o contrato.
     *
     * ### O que NÃO mudou
     * Os endpoints de AÇÃO continuam em `ContratoAdminController`, sob os nomes
     * `admin.contratos.checklist.*` e `admin.contratos.finalizar-entrada`.
     * Renomeá-los seria churn sem ganho para quem usa, e uma classe inteira de
     * 404 silencioso; as rotas já aceitam as DUAS permissões em OR desde a Fase
     * 152, que era o que de fato importava. O recorte fino segue em
     * `guardaChecklist()`: item do grupo `contrato` exige `admin.contratos`.
     *
     * ### Ordem obrigatória (a mesma da ficha antiga)
     * Montar o checklist PRIMEIRO — é a chamada que roda e persiste os 4
     * resolvers automáticos —, sincronizar a etapa DEPOIS, e só então perguntar
     * `podeFinalizar()`, que precisa enxergar a empresa já na etapa certa.
     *
     * Por que uma LEITURA sincroniza: o degrau 2→3 depende de um evento
     * EXTERNO (o webhook do Clicksign gravando `enviado_em`) que não passa por
     * ação nenhuma do checklist. Abrir a ficha é o único momento em que o
     * sistema observa esse fato.
     */
    public function show(
        Request $request,
        Company $company,
        ChecklistAdministrativoService $checklist,
        FinalizarEntradaAdministrativaService $finalizar,
        MensagemBoasVindasService $boasVindas,
        TimelineEntradaService $timeline,
    ) {
        $podeVerContrato = $request->user()->hasPermission(Permissions::ADMIN_CONTRATOS);

        $checklistPayload = $checklist->paraEmpresa($company);
        $this->sincronizarEtapa($request, $company);

        // Sem `admin.contratos`, o grupo Contrato inteiro sai do payload. O
        // `progresso` continua sendo o da empresa INTEIRA de propósito: é a
        // régua do FINALIZAR, não uma métrica da seção visível.
        if (! $podeVerContrato) {
            unset($checklistPayload['grupos'][ChecklistAdministrativoDefinicao::GRUPO_CONTRATO]);
        }

        return \Inertia\Inertia::render('Comercial/EntradaFicha', [
            'company' => [
                'id'   => $company->id,
                'name' => $company->name,
                'cnpj' => $company->cnpj,
            ],
            'checklist'         => $checklistPayload,
            'pode_ver_contrato' => $podeVerContrato,
            // Array inteiro: um campo desabilita o botão, o outro explica por
            // quê. Mesma régua que recusa o POST (ADMIN-05) — o botão nunca
            // decide sozinho.
            'pode_finalizar'    => $finalizar->podeFinalizar($company),
            'adman_register_url' => config('services.adman.register_url'),
            'portal_cliente_url' => ($token = OnboardingLink::where('company_id', $company->id)->value('token'))
                ? route('portal.inicio', $token)
                : null,
            'mensagem_boas_vindas' => $boasVindas->paraEmpresa($company),
            // Pré-computado no servidor, ao contrário da ficha antiga que
            // mandava a lista inteira de envelopes e decidia no JSX. Aqui só
            // atravessa {url, rotulo}, e só para quem pode ver contrato.
            'contrato_acesso'   => $podeVerContrato ? $this->contratoAcesso($company) : null,
            // É da ficha de Contrato que o contrato é GERADO — o checklist não
            // gera nada. Link só para quem tem a permissão.
            'ficha_contrato_url' => $podeVerContrato ? route('admin.contratos.show', $company->id) : null,
            // Fase 156 (HIST-01/02/03) — a história do fluxo de entrada, que
            // mudou de ficha junto com o checklist: é ESTA a ficha da entrada.
            //
            // ⚠️ Hoje NÃO é renderizada: o bloco "Histórico do fluxo de entrada"
            // foi removido da tela a pedido do usuário, e `TimelineEntrada.jsx`
            // está sem uso. O payload fica porque a fonte é durável de propósito
            // (`company_etapa_transicoes`, criada justamente porque o
            // activity_log é podado em 365 dias) e religar a seção é uma linha.
            // Se a decisão virar "não queremos mesmo", some daqui e o componente
            // vai junto.
            //
            // NÃO é recortada por permissão de módulo, ao contrário do grupo
            // Contrato: são datas, nomes de etapa e quem agiu — nenhum envelope,
            // signatário ou valor.
            'timeline'          => $timeline->paraEmpresa($company),
            'duracao_por_etapa' => $timeline->duracaoPorEtapa($company),
        ]);
    }

    /**
     * Onde "Ver contrato" leva: o PDF assinado quando existe em disco, senão o
     * painel da Clicksign, senão nada.
     *
     * `pdf_assinado_path` preenchido é a condição porque a rota do PDF devolve
     * 404 quando o arquivo não está lá, e botão que leva a 404 é pior que botão
     * nenhum.
     *
     * @return array{url: string, rotulo: string}|null
     */
    private function contratoAcesso(Company $company): ?array
    {
        $assinado = ContratoAssinatura::where('company_id', $company->id)
            ->whereNotNull('pdf_assinado_path')
            ->orderByDesc('id')
            ->first();

        if ($assinado !== null) {
            return ['url' => route('contratos.pdf-assinado', $assinado->id), 'rotulo' => 'Ver contrato assinado'];
        }

        $painel = config('services.clicksign.painel_url');

        return $painel ? ['url' => $painel, 'rotulo' => 'Abrir na Clicksign'] : null;
    }

    /**
     * Espelha `ContratoAdminController::sincronizarEtapaChecklist()` no caminho
     * de LEITURA. `adotarSeLegado = false`: abrir uma ficha NUNCA carimba
     * empresa legada — só agir sobre o checklist faz isso (D-05 da Fase 150).
     *
     * Nunca lança: a ficha tem de abrir mesmo que a reconciliação falhe. Trocar
     * um desalinhamento de etapa por uma tela inacessível seria pior.
     */
    private function sincronizarEtapa(Request $request, Company $company): void
    {
        try {
            app(ChecklistEtapaSincronizadorService::class)
                ->sincronizar($company->refresh(), $request->user(), false);
        } catch (\Throwable $e) {
            Log::warning('[Entrada] reconciliação de etapa na abertura da ficha falhou', [
                'company_id' => $company->id,
                'erro'       => $e->getMessage(),
            ]);
        }
    }
}
