<?php

namespace App\Http\Controllers;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\NpsEmailEnvio;
use App\Models\NpsImputedAssignment;
use App\Models\NpsPerguntaCustomizada;
use App\Models\NpsRespostaCustomizada;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Services\Desempenho\NpsSemLinkService;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsGrupoCoberturaService;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsJanelaResolver;
use App\Services\Nps\NpsTemplateService;
use App\Support\NpsTextRenderer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Controller das pesquisas NPS.
 *
 * Phase 31 (Plan 02 + Plan 05) — Reescrito para a escala 1-5 com 3 dimensões
 * (estrategista / analista / empresa) e payload do form público com
 * `tem_analista` para a UI decidir mostrar/ocultar o campo de analista
 * (caso mentoria pura). O endpoint `nps.generate` (manual) preserva
 * `auto_generated=false` para back-compat (REQ-31-08).
 *
 * index() em Plan 05 passou a entregar: filtro por mês (default = mês corrente,
 * usando `month_reference` quando auto e `created_at` como fallback para
 * surveys manuais), 3 cards de média do mês (estrategista / analista / empresa),
 * série de 12 meses para LineChart e lista paginada das respostas.
 */
class NpsController extends Controller
{
    // Fase 116 · injeta o serviço único de leitura da regra "NPS não
    // respondido conta como nota mínima (1)" (Plan 01). Nenhuma lógica de
    // resolução de responsável/competência/invalidação é reimplementada
    // aqui — só consumida via surveyIdsComNotaDefinitiva()/vigentes().
    // Fase 119.1 Plan 02 · guard de duplicidade do disparo manual — reusa a
    // mesma fonte de competência/duplicidade que o disparo mensal já usa,
    // sem reimplementar a regra aqui (NpsElegibilidadeService, Plan 01).
    // Fase 119.1 Plan 04 · D1 na área NPS — empresa elegível sem link conta
    // nota 1 nos cards/série (NpsSemLinkService, mesma régua do bônus) e o
    // "mês fechou?" da janela de coleta é lido de NpsJanelaResolver (Fase
    // 118), nunca reimplementado inline.
    // Quick task 260730-jzx (ajuste 3) · colapso de grupo em Faltantes reusa
    // a régua de "mesma dupla" já implementada em NpsGrupoCoberturaService —
    // a comparação de responsáveis não é reimplementada aqui (DQ-05).
    public function __construct(
        private NpsImputationService $imputationService,
        private NpsElegibilidadeService $elegibilidadeService,
        private NpsSemLinkService $npsSemLinkService,
        private NpsJanelaResolver $npsJanelaResolver,
        private NpsGrupoCoberturaService $grupoCoberturaService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // ─── Filtro de mês (default = mês corrente) ──────────────────────────
        // Aceita ?mes=YYYY-MM via query string. Inválido cai no mês atual.
        $mesFiltro = $request->input('mes', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $mesFiltro)) {
            $mesFiltro = now()->format('Y-m');
        }
        $mesInicio = \Carbon\Carbon::parse($mesFiltro . '-01')->startOfMonth();
        $mesFim    = $mesInicio->copy()->endOfMonth();

        // ─── Fase 95 (AB-95-3) — filtro tri-estado ?confianca= ──────────────
        // Mesmo molde do $mesFiltro acima: whitelist estrita + fallback
        // silencioso. Valor inválido (ou qualquer coisa fora da whitelist)
        // cai em 'todos' sem erro — nunca 422/403, que denunciaria a feature
        // para quem não pode usá-la (Pitfall 4 do RESEARCH).
        $confiancaValidos = ['todos', 'confiavel', 'atencao', 'suspeita'];
        $confiancaParam   = $request->input('confianca', 'todos');
        $confiancaFiltro  = in_array($confiancaParam, $confiancaValidos, true) ? $confiancaParam : 'todos';

        // ─── Quick task 260612-flt — filtros adicionais ─────────────────────
        // Empresa: filtra por company_id direto. Estrategista/Analista: filtra
        // por surveys cuja empresa tem o user atribuído no pivot company_users
        // com role correspondente (estrategista | consultor para analista).
        // Aplicam tanto na lista paginada quanto nos cards de média e na serie
        // 12 meses — coerencia visual entre todos os blocos da pagina.
        $empresaId      = $request->integer('empresa_id') ?: null;
        $estrategistaId = $request->integer('estrategista_id') ?: null;
        $analistaId     = $request->integer('analista_id') ?: null;
        // Ajuste 2026-07-20 · o dashboard NPS agora abre em TODOS os modelos por
        // padrão. Antes (13/07) o default caía no modelo PRINCIPAL, mas com só 1
        // modelo ativo o <select> de modelo nem é renderizado (Index.jsx:1208) —
        // então o default-principal escondia respondidos de OUTROS modelos (ex.:
        // 12 respostas do modelo #3) SEM o usuário ter como revelá-los. Agora:
        // id numérico → filtra aquele modelo; '__todos__' ou ausência do
        // parâmetro → todos os modelos (lista, cards e série 12m).
        $templateParam = $request->input('template_id');
        if (is_numeric($templateParam)) {
            $templateId    = (int) $templateParam;   // modelo específico
            $templateTodos = false;
        } else {
            $templateId    = null;                   // sem filtro — todos os modelos
            $templateTodos = true;
        }

        // Filtro por pessoa (estrategista/analista) — Bugfix 2026-07-20.
        //
        // Atribui cada SURVEY à pessoa responsável POR SERVIÇO, não pela empresa
        // inteira. Empresas com 2 serviços (ex.: ML/Performance + Shopee) têm 1
        // NPS por modelo, cada um do responsável do SEU serviço. O filtro antigo
        // (whereHas('company.users')) casava a empresa toda e vazava o NPS do
        // outro serviço/pessoa — bug reportado: na aba "Respondidos", filtrando
        // por uma pessoa, empresas multi-serviço apareciam 2x (uma resposta da
        // pessoa, a outra de outra pessoa).
        //
        // (a) Respondidas: usa a atribuição CONGELADA (nps_score_assignments) —
        //     a MESMA fonte que o modal mostra como "responsável", então filtro
        //     e exibição nunca divergem.
        // (b) Sem resposta (pendente/expirada, logo sem atribuição congelada):
        //     atribui pelo serviço coberto pelo MODELO do survey — a pessoa é
        //     responsável (role) da empresa naquele serviço. servico_id NULL na
        //     pivot = responsável CONSOLIDADO (cobre qualquer serviço).
        // $role NULL = QUALQUER papel (usado no escopo do não-admin: mostra o NPS
        // de que a pessoa é responsável, seja como estrategista ou analista).
        $filtroPorPessoa = function ($query, int $personId, ?string $role = null) {
            $query->where(function ($outer) use ($personId, $role) {
                $outer->whereHas('response.scoreAssignments', function ($q) use ($personId, $role) {
                    $q->where('user_id', $personId)
                      ->when($role !== null, fn ($qq) => $qq->where('role', $role));
                })
                // Bugfix 2026-08-14 — quem GEROU o link sempre enxerga o link
                // que gerou. Sem este ramo, a autorização de escrita era mais
                // ampla que a de leitura: `generate()` (linha ~1243) aceita
                // QUALQUER papel no pivot `company_users`, enquanto a leitura
                // abaixo exige ser responsável PELO SERVIÇO que o modelo cobre.
                // Resultado relatado em produção: a pessoa gerava o link, via a
                // mensagem de sucesso, e o link nunca mais aparecia na lista
                // dela — nem em "Pendentes", nem em "Todos".
                //
                // Só vale quando `$role` é NULL, que é o escopo do não-admin
                // (`$filtroPorPessoa($baseQuery, $user->id, null)`). Nos filtros
                // de estrategista/analista o papel É informado, e ali "gerou"
                // não pode virar "é o estrategista": incluir `generated_by`
                // naquele caso faria o filtro "Estrategista = Fulano" devolver
                // survey que Fulano apenas disparou para a carteira de outra
                // pessoa.
                ->when($role === null, fn ($q) => $q->orWhere('nps_surveys.generated_by', $personId))
                ->orWhere(function ($sub) use ($personId, $role) {
                    // Bugfix 2026-07-22 (Prensar/Nathalia) — antes era
                    // whereDoesntHave('response'), que só cobria surveys SEM
                    // resposta (pendente/expirada). Um survey RESPONDIDO mas SEM
                    // atribuição congelada (nps_score_assignments vazio — ex.:
                    // submit sem responsável elegível no momento) caía fora de
                    // AMBOS os ramos e sumia para TODO não-admin, mesmo o
                    // responsável atual da empresa. Agora o fallback por serviço
                    // vale para QUALQUER survey sem atribuição congelada,
                    // respondido ou não — o ramo de cima (atribuição congelada)
                    // continua sendo a fonte primária quando ela existe.
                    $sub->whereDoesntHave('response.scoreAssignments')
                        ->whereExists(function ($ex) use ($personId, $role) {
                            $ex->selectRaw('1')
                               ->from('company_users as cu')
                               ->whereColumn('cu.company_id', 'nps_surveys.company_id')
                               ->where('cu.user_id', $personId)
                               ->when($role !== null, fn ($qq) => $qq->where('cu.role', $role))
                               ->where(function ($w) {
                                   // Consolidado (servico_id NULL) cobre qualquer
                                   // serviço; senão, o serviço da pivot precisa
                                   // estar coberto pelo modelo do survey.
                                   $w->whereNull('cu.servico_id')
                                     ->orWhereExists(function ($sc) {
                                         $sc->selectRaw('1')
                                            ->from('nps_template_service_scopes as scp')
                                            ->whereColumn('scp.template_id', 'nps_surveys.template_id')
                                            ->whereColumn('scp.servico_id', 'cu.servico_id');
                                     })
                                     // Bugfix 2026-08-14 — modelo SEM nenhum
                                     // serviço coberto (pivot vazio, ex.: "NPS
                                     // Padrão", e surveys legados com
                                     // `template_id` NULL) não delimita
                                     // serviço nenhum: o vínculo na empresa
                                     // basta, em qualquer serviço.
                                     //
                                     // Sem isto, o `orWhereExists` acima NUNCA
                                     // casava para esses modelos — quem tem
                                     // vínculo com `servico_id` preenchido só
                                     // enxergava survey de modelo escopado, e o
                                     // modelo padrão (o mais usado) ficava
                                     // invisível para toda a equipe. É a mesma
                                     // regra que `generate()` já pratica na
                                     // escrita ("modelo SEM serviços cobertos →
                                     // aceito para qualquer empresa"), que até
                                     // aqui não tinha contraparte na leitura.
                                     //
                                     // NÃO afeta modelo escopado: o NPS Shopee
                                     // continua restrito ao responsável do
                                     // Shopee — que é exatamente o vazamento
                                     // fechado pelo bugfix de 2026-07-22.
                                     ->orWhereNotExists(function ($sc) {
                                         $sc->selectRaw('1')
                                            ->from('nps_template_service_scopes as scp')
                                            ->whereColumn('scp.template_id', 'nps_surveys.template_id');
                                     });
                               });
                        });
                });
            });
        };

        $aplicarFiltrosSurveys = function ($query) use ($empresaId, $estrategistaId, $analistaId, $templateId, $filtroPorPessoa) {
            if ($empresaId) {
                $query->where('company_id', $empresaId);
            }
            if ($templateId) {
                $query->where('template_id', $templateId);
            }
            if ($estrategistaId) {
                $filtroPorPessoa($query, $estrategistaId, 'estrategista');
            }
            if ($analistaId) {
                $filtroPorPessoa($query, $analistaId, 'consultor');
            }
        };

        // ─── Audiência: surveys do mês selecionado ───────────────────────────
        // Surveys auto-geradas usam month_reference (semântica D-specifics).
        // Surveys manuais (month_reference=null) caem no mês via created_at.
        //
        // Bugfix 2026-07-08 — eager load expandido para `response.answers` +
        // `response.template.questions` (dual-path v15/legacy). Respostas v15
        // gravam apenas em `nps_response_answers` (snapshot per-row); as
        // colunas legadas `score_*` de `nps_responses` ficam null. Sem carregar
        // answers, os cards mostram 0 e a lista mostra só o nome do respondente.
        // Quick task 260715-pu0 — eager load das atribuições congeladas
        // (Fase 79, `nps_score_assignments`) + o `user` de cada uma, para o
        // modal de detalhe mostrar QUEM recebeu a nota. 2 queries a mais no
        // total da página (assignments por whereIn dos response_ids + users
        // por whereIn dos user_ids), não N+1 — a lista é paginada em 20.
        // Fase 95 (AB-95-1/AB-95-2) — eager-load da trilha de eventos (Fase 94)
        // SOMENTE para admin: não-admin nunca paga essa query extra, já que
        // 'events' só é usado para montar `auditoria`, que é admin-only.
        $eagerLoads = [
            'company',
            'company.users', // 2026-07-22 — fallback de responsável no modal (sem N+1)
            'generatedBy',
            'template', // 2026-07-20 — nome do modelo na coluna "Modelo" da listagem (evita N+1)
            'template.serviceScopes', // 2026-07-22 — serviços cobertos p/ o fallback de responsável
            'response.respostasCustomizadas',
            'response.answers',
            'response.survey.template',
            'response.scoreAssignments.user',
        ];
        if ($user->isAdmin()) {
            $eagerLoads[] = 'events';
        }

        $baseQuery = NpsSurvey::with($eagerLoads)
            ->where(function ($q) use ($mesInicio, $mesFim) {
                $q->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                  ->orWhere(function ($qq) use ($mesInicio, $mesFim) {
                      $qq->whereNull('month_reference')
                         ->whereBetween('created_at', [$mesInicio, $mesFim]);
                  });
            })
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            // Bugfix 2026-07-22 — escopo do não-admin por SERVIÇO, não por empresa.
            // Antes: whereIn('company_id', $user->companies()) → o profissional via
            // TODOS os NPS das empresas dele, inclusive o de OUTRO serviço/pessoa
            // (ex.: analista de ML via também o NPS Shopee da empresa, respondido
            // por outra pessoa). Agora só aparece o NPS de que ELE é o responsável
            // (atribuição congelada nas respondidas; serviço coberto nas pendentes),
            // em qualquer papel.
            $filtroPorPessoa($baseQuery, $user->id, null);
        }

        // Quick task 260612-flt — filtros empresa/estrategista/analista.
        $aplicarFiltrosSurveys($baseQuery);

        // Fase 95 (AB-95-3) — filtro de confiança, aplicado DEPOIS do escopo
        // de carteira e dos filtros acima (defesa em profundidade: soma-se ao
        // escopo, nunca o substitui). Só existe para admin — para os demais
        // roles o parâmetro é simplesmente ignorado, mesmo que venha na URL
        // (o filtro não pode ser "descoberto" por tentativa/erro).
        //
        // 'confiavel' usa whereNull porque resposta limpa persiste
        // suspicion_reasons=NULL (não existe severity='nenhuma' gravado no
        // banco — fato confirmado em capturarRastroEAvaliarSuspeita). Usa o
        // operador JSON nativo do Eloquent (coluna->chave), que o Laravel
        // traduz por driver (MySQL/MariaDB e SQLite dos testes) — PROIBIDO
        // usar JSON_EXTRACT cru via query raw aqui.
        if ($user->isAdmin() && $confiancaFiltro !== 'todos') {
            $baseQuery->whereHas('response', function ($q) use ($confiancaFiltro) {
                if ($confiancaFiltro === 'confiavel') {
                    $q->whereNull('suspicion_reasons');
                } elseif ($confiancaFiltro === 'atencao') {
                    $q->where('suspicion_reasons->severity', 'media');
                } elseif ($confiancaFiltro === 'suspeita') {
                    $q->where('suspicion_reasons->severity', 'alta');
                }
            });
        }

        // Bugfix 2026-07-08 — helper de leitura dual-path com arredondamento.
        // Para surveys v15 (survey.template_id != null): usa NpsScoreCalculator
        // sobre os answers snapshot (Phase 69-02). Sempre arredonda pra 2 casas
        // ANTES de mandar pro front (evita "3.6666666666666665" na UI).
        // Para surveys legacy: lê a coluna score_$dim direto do NpsResponse.
        $calculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        $notaDe = function ($response, string $dimensao) use ($calculator) {
            if (! $response) {
                return null;
            }
            if ($response->survey && $response->survey->template_id !== null) {
                $v = $calculator->compute($response, $dimensao);
                return $v === null ? null : round((float) $v, 2);
            }
            $col = 'score_' . $dimensao;
            $v = $response->$col;
            return $v === null ? null : round((float) $v, 2);
        };

        // Bugfix 2026-07-08 — modal "Ver respostas" agora mostra TODAS as
        // answers do template v15 (as 16 perguntas, não só as de dimensão
        // 'geral'). O admin queixou: "não veio com todos os campos, na tela de
        // configura mostra que tem 16 campos existentes". Mantém ordem original
        // (por template_question_id) + concatena as respostas_customizadas v13
        // para compat com surveys legacy pré-Phase 68.
        $extrasDe = function ($response) {
            if (! $response) {
                return collect();
            }
            $legacy = $response->respostasCustomizadas->map(fn($r) => [
                'id'             => 'legacy_' . $r->id,
                'pergunta_id'    => $r->pergunta_id,
                'pergunta_texto' => $r->pergunta_texto_snapshot,
                'dimensao'       => 'geral',
                'tipo'           => $r->tipo_snapshot,
                'valor'          => $r->valor,
            ]);
            // v15: TODAS as answers do template (ordena por id do
            // template_question para preservar sequência da configuração).
            // 2026-07-08: incluir `peso` para o modal exibir "Label = peso".
            // 2026-07-13: answers texto_livre (option_peso_snapshot NULL)
            // reportam `tipo=texto_livre` + `valor` do `comentario` — o
            // modal renderiza como texto puro (Nps/Index RespostaExtraValor
            // cai no fallback).
            $v15 = $response->answers
                ->sortBy('template_question_id')
                ->map(function ($a) {
                    $ehTextoLivre = $a->option_peso_snapshot === null;
                    return [
                        'id'             => 'v15_' . $a->id,
                        'pergunta_id'    => $a->template_question_id,
                        'pergunta_texto' => $a->question_texto_snapshot,
                        'dimensao'       => $a->question_dimensao_snapshot,
                        'tipo'           => $ehTextoLivre ? 'texto_livre' : 'opcoes',
                        'valor'          => $ehTextoLivre
                            ? (string) ($a->comentario ?? '')
                            : $a->option_label_snapshot,
                        'peso'           => $a->option_peso_snapshot,
                    ];
                });
            return $legacy->concat($v15)->values();
        };

        // Nomes dos responsáveis (analista/estrategista) exibidos no modal.
        //
        // FONTE 1 (autoritária): `nps_score_assignments` (Fase 79, congelado no
        // submit) — MESMA base do bônus. Dedup por user_id.
        //
        // FONTE 2 (fallback DISPLAY, Bugfix 2026-07-22): quando NÃO há atribuição
        // congelada para uma dimensão (survey legado pré-Fase 79 ou "responsável
        // faltante" no submit), o modal ficava SEM nome. Agora cai no responsável
        // ATUAL do SERVIÇO coberto pelo modelo — resolvido dos dados JÁ
        // eager-loaded (`company.users` com pivot servico_id ∩ `template.serviceScopes`),
        // sem N+1. NÃO usa as relações consolidadas `consultor()`/`estrategista()`
        // (pegam a linha errada em empresa multi-serviço). É SÓ exibição — não
        // toca no bônus/atribuição congelada.
        $mapaDimensaoRole = [
            'consultor'    => 'analista',
            'estrategista' => 'estrategista',
        ];
        $responsaveisDe = function ($s) use ($mapaDimensaoRole) {
            $resultado = ['analista' => [], 'estrategista' => []];
            $response = $s->response;
            if (! $response) {
                return $resultado;
            }

            // Fonte 1 — atribuição congelada.
            $vistos = ['analista' => [], 'estrategista' => []];
            foreach ($response->scoreAssignments as $a) {
                $chave = $mapaDimensaoRole[$a->role] ?? null;
                if ($chave === null || ! $a->user) {
                    continue; // role fora do mapa, ou usuário deletado
                }
                if (in_array($a->user_id, $vistos[$chave], true)) {
                    continue; // dedup por user_id
                }
                $vistos[$chave][] = $a->user_id;
                $resultado[$chave][] = $a->user->name;
            }

            // Fonte 2 — fallback por serviço, SÓ nas dimensões que ficaram vazias.
            if ($s->template_id && $s->company && $s->relationLoaded('template') && $s->template) {
                $servicoIds = $s->template->serviceScopes->pluck('id')->all();
                foreach ($mapaDimensaoRole as $role => $chave) {
                    if (! empty($resultado[$chave])) {
                        continue; // já resolvido pela atribuição congelada
                    }
                    foreach ($s->company->users as $u) {
                        if ($u->pivot->role !== $role) {
                            continue;
                        }
                        $sid = $u->pivot->servico_id;
                        // Consolidado (NULL) cobre qualquer serviço; senão o
                        // serviço da pivot precisa ser coberto pelo modelo.
                        if ($sid !== null && ! in_array((int) $sid, $servicoIds, true)) {
                            continue;
                        }
                        if (! in_array($u->name, $resultado[$chave], true)) {
                            $resultado[$chave][] = $u->name;
                        }
                    }
                }
            }

            return $resultado;
        };

        // ─── Contadores agregados (Bugfix 2026-07-16) ────────────────────────
        // Antes o front recalculava as contagens client-side sobre surveys.data
        // (só os 20 da página), então os chips Todos/Respondidos/Pendentes/
        // Expirados — e o "resp · pend" dos StatCards — mudavam a cada página.
        // Agora contamos sobre o CONJUNTO FILTRADO INTEIRO (mesmos filtros de
        // mês/empresa/estrategista/analista/modelo já aplicados a $baseQuery)
        // via COUNT no banco. A paginação de 20 na tabela é preservada.
        //
        // "Expirado" é status EFETIVO de apresentação: survey `pending` com
        // `expires_at` vencido (a coluna `status` só grava pending|completed).
        // Não altera cálculo de nota, escopo por carteira nem fluxo de resposta.
        // Clona $baseQuery ANTES do paginate (que aplica limit/offset ao builder).
        $contagem     = fn() => (clone $baseQuery)->reorder();
        $totalGeral   = $contagem()->count();
        $respondidos  = $contagem()->where('status', 'completed')->count();
        $expirados    = $contagem()->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
        $contadores = [
            'todos'       => $totalGeral,
            'respondidos' => $respondidos,
            'expirados'   => $expirados,
            'pendentes'   => max(0, $totalGeral - $respondidos - $expirados),
        ];

        // ─── Faltantes por (empresa, SETOR) — Bugfix 2026-07-21 (v2) ─────────
        // NPS é conceitualmente por SETOR de serviço (Mercado Livre/performance
        // e Shopee), não por modelo. Em produção há 2 modelos automáticos que
        // cobrem os MESMOS serviços de performance ("NPS Performance" is_default
        // + "NPS Mentoria"), então o cálculo por MODELO contava cada empresa de
        // ML duas vezes (132 empresas × 2 modelos + 28 Shopee = 292). Bug
        // reportado: "Todos" mostrava 292 e no Faltantes toda empresa aparecia
        // 2×. Agrupando por SETOR a empresa aparece 1× por setor que participa
        // (132 performance + 28 shopee = 160), com o modelo REPRESENTANTE do
        // setor (is_default vence). Uma empresa só aparece 2× se estiver nos
        // dois setores (ML + Shopee).
        //
        // Universo do setor = empresa ativa com contrato ATIVO em serviço
        // daquele setor coberto por um modelo automático (mesma régua do
        // nps:disparar-mensal). Respeita carteira (não-admin) + filtro de
        // empresa + filtro de pessoa POR SERVIÇO do setor (ou consolidada,
        // servico_id NULL). "Tem survey" no setor = survey de QUALQUER modelo
        // que cobre o setor.
        $autoModelos = \App\Models\NpsTemplate::query()
            ->where('active', true)
            ->where('envio_automatico_mensal', true)
            ->with('servicos')
            ->get();

        // Agrupa por setor: serviços cobertos, modelos que cobrem o setor e o
        // modelo representante (is_default vence; senão o primeiro visto).
        $setores = [];
        foreach ($autoModelos as $m) {
            foreach ($m->servicos as $s) {
                $setores[$s->setor]['servicoIds'][]  = $s->id;
                $setores[$s->setor]['templateIds'][] = $m->id;
                if (!isset($setores[$s->setor]['modeloNome']) || $m->is_default) {
                    $setores[$s->setor]['modeloNome'] = $m->nome;
                    $setores[$s->setor]['templateId'] = $m->id;
                }
            }
        }

        // Filtro de pessoa por serviço (reaproveitado por setor). $role NULL =
        // qualquer papel (usado no escopo do não-admin).
        $filtroPessoaFaltante = function ($q, int $personId, ?string $role, array $servicoIds) {
            $q->whereHas('users', function ($u) use ($personId, $role, $servicoIds) {
                $u->where('users.id', $personId)
                  ->when($role !== null, fn ($qq) => $qq->where('company_users.role', $role))
                  ->where(function ($w) use ($servicoIds) {
                      $w->whereNull('company_users.servico_id')
                        ->orWhereIn('company_users.servico_id', $servicoIds);
                  });
            });
        };

        // Quick task 260730-jzx (ajuste 4) — empresa sem estrategista atribuído
        // não entra em Faltantes: reusa a MESMA régua que o cálculo da nota já
        // usa (`empresasElegiveis()` exige estrategista, D-07 Fase 31), então
        // a lista de trabalho fica alinhada com o que a nota já ignorava
        // (DQ-02). Calculado ANTES do laço dos setores para servir de FILTRO
        // aqui embaixo, e reaproveitado mais adiante para `conta_nota_1`.
        $elegiveisPorEmpresa = [];
        foreach ($this->elegibilidadeService->empresasElegiveis($mesInicio) as $item) {
            $elegiveisPorEmpresa[$item->company_id] = true;
        }

        $faltantes = [];
        foreach ($setores as $setor => $info) {
            $servicoIds  = array_values(array_unique($info['servicoIds']));
            $templateIds = array_values(array_unique($info['templateIds']));

            $q = Company::query()
                ->where('active', true)
                ->whereHas('contratosServico', fn ($c) => $c->active()->whereIn('servico_id', $servicoIds));

            if (!$user->isAdmin()) {
                // Bugfix 2026-07-22 — não-admin vê faltantes só dos serviços de
                // que ELE é responsável (não company-level). Ex.: analista de ML
                // não vê o faltante Shopee da mesma empresa.
                $filtroPessoaFaltante($q, $user->id, null, $servicoIds);
            }
            if ($empresaId) {
                $q->where('id', $empresaId);
            }
            if ($estrategistaId) {
                $filtroPessoaFaltante($q, $estrategistaId, 'estrategista', $servicoIds);
            }
            if ($analistaId) {
                $filtroPessoaFaltante($q, $analistaId, 'consultor', $servicoIds);
            }

            // Empresas que JÁ têm survey de QUALQUER modelo deste setor no mês.
            $comSurvey = NpsSurvey::query()
                ->whereIn('template_id', $templateIds)
                ->where(function ($s) use ($mesInicio, $mesFim) {
                    $s->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                      ->orWhere(function ($ss) use ($mesInicio, $mesFim) {
                          $ss->whereNull('month_reference')
                             ->whereBetween('created_at', [$mesInicio, $mesFim]);
                      });
                })
                ->distinct()
                ->pluck('company_id');

            $q->whereNotIn('id', $comSurvey);

            foreach ($q->get(['id', 'name', 'company_group_id']) as $c) {
                // Quick task 260730-jzx (ajuste 4) — sem estrategista atribuído,
                // a empresa ainda não entrou na operação: não aparece na lista
                // de trabalho (mesmo corte de `empresasElegiveis()` acima).
                if (!isset($elegiveisPorEmpresa[$c->id])) {
                    continue;
                }

                $faltantes[] = [
                    'company_id'       => $c->id,
                    'name'             => $c->name,
                    'modelo'           => $info['modeloNome'],
                    'template_id'      => $info['templateId'],
                    'setor'            => $setor,
                    'company_group_id' => $c->company_group_id,
                    // Quick task 260730-jzx (ajuste 1) — o "motivo" (sem
                    // contato/sem link) saiu da tela: o envio é manual (gera o
                    // link e manda por fora), então falta de e-mail/Digisac
                    // não impede nada. A regra de nota 1 (D5) não mudou.
                    'tipo'             => 'empresa',
                    'empresas_count'   => 1,
                ];
            }
        }

        // Fase 119.1 (D1) — cada faltante diz se está PESANDO na média (mês
        // de coleta já fechado + par empresa/modelo elegível + não invalidada
        // na competência), na MESMA régua que os cards somam logo abaixo via
        // NpsSemLinkService — os dois nunca podem divergir (T-119.1-15).
        $mesFechadoAtual = $this->npsJanelaResolver->fechada($mesInicio);
        $invalidadasCompetenciaAtual = BonusInvalidacao::companyIdsInvalidadas(
            $mesInicio->copy()->subMonthNoOverflow()->startOfMonth()
        );
        // Fase 119.1 (D1) — checagem por EMPRESA (não por par empresa/modelo):
        // o `template_id` de cada faltante é só o representante do SETOR
        // (is_default vence — Bugfix 2026-07-21 v2, comentário acima), que
        // pode divergir do modelo específico que `NpsElegibilidadeService`
        // resolve para a empresa quando o setor tem mais de 1 modelo
        // automático. Como `$faltantes` já garante "nenhum survey de NENHUM
        // modelo do setor este mês" (o `comSurvey` acima varre todos os
        // `$templateIds`), basta saber se a empresa é elegível para ALGUM
        // modelo aplicável — a mesma pergunta que `NpsSemLinkService`
        // responde por (empresa, modelo) somada abaixo. `$elegiveisPorEmpresa`
        // já foi calculado ANTES do laço dos setores (ajuste 4) — não
        // recalcular aqui, é o mesmo mapa.
        foreach ($faltantes as &$faltante) {
            $faltante['conta_nota_1'] = $mesFechadoAtual
                && isset($elegiveisPorEmpresa[$faltante['company_id']])
                && !$invalidadasCompetenciaAtual->contains($faltante['company_id']);
            // Quick task 260730-jzx (DQ-03 — preparo do colapso de grupo) —
            // conta_nota_1_count/empresas_count são os campos que os
            // contadores somam abaixo; hoje 1 linha = 1 empresa, mas a linha
            // de grupo (Task 2) vai agregar N empresas nestes mesmos campos.
            $faltante['conta_nota_1_count'] = (int) $faltante['conta_nota_1'];
        }
        unset($faltante);

        // Quick task 260730-jzx (ajuste 3) — grupo cuidado pelas MESMAS
        // pessoas colapsa em UMA linha (DQ-03/DQ-04/DQ-05). Roda DEPOIS do
        // laço que grava conta_nota_1/conta_nota_1_count (agrega valores já
        // calculados) e ANTES do usort/contadores (a ordenação e as somas
        // abaixo já enxergam a lista colapsada).
        $faltantes = $this->colapsarFaltantesPorGrupo($faltantes, $mesInicio);

        // Ordena por empresa/grupo e depois modelo (leitura da lista).
        usort($faltantes, fn ($a, $b) => [$a['name'], $a['modelo']] <=> [$b['name'], $b['modelo']]);

        // Quick task 260730-jzx (DQ-03) — os contadores somam EMPRESAS
        // (campo `empresas_count`), nunca LINHAS. Colapsar um grupo de N
        // empresas em 1 linha (Task 2) não pode mudar nenhum destes números.
        $contadores['faltantes'] = array_sum(array_column($faltantes, 'empresas_count'));
        // Fase 119.1 (D1) — quantos faltantes estão pesando na média (o
        // plano 07/UI consome esta chave, ver 119.1-04-PLAN.md).
        $contadores['contam_nota_1'] = array_sum(array_column($faltantes, 'conta_nota_1_count'));

        // "Todos" = TODAS as empresas da carteira filtrada (2026-07-21):
        // respondidas + pendentes + expiradas (os surveys do mês) + faltantes
        // (empresas sem link no mês). Antes contava só os surveys e escondia os
        // faltantes do total. Respeita o filtro de pessoa aplicado — filtrando
        // por um estrategista/analista, "Todos" reflete a carteira dele.
        $contadores['todos'] = $totalGeral + array_sum(array_column($faltantes, 'empresas_count'));

        // Status efetivo por linha — coerente com os contadores acima (mesma
        // regra de "expirado"). Apresentação pura; a coluna `status` do banco
        // permanece intacta.
        $statusEfetivo = function ($s) {
            if ($s->status === 'completed') {
                return 'completed';
            }
            if ($s->expires_at && $s->expires_at->isPast()) {
                return 'expired';
            }
            return 'pending';
        };

        // Merge 2026-07-17 — mantém a closure da Fase 95 (payload admin
        // confianca/auditoria) e injeta o $statusEfetivo do bugfix de contagens.
        // 2026-07-17 · sem paginação: traz TODAS as pesquisas do mês filtrado
        // numa página só (a listagem rola internamente no card, no front). O
        // filtro de mês (default = mês corrente) mantém o volume limitado;
        // $totalGeral já é o COUNT do conjunto filtrado calculado acima.
        $surveys = $baseQuery->paginate(max(20, $totalGeral))->withQueryString()->through(function ($s) use ($user, $notaDe, $extrasDe, $responsaveisDe, $statusEfetivo) {
            $item = [
                'id'                 => $s->id,
                'token'              => $s->token,
                'company_name'       => $s->company->name,
                'company_id'         => $s->company_id,
                'status'             => $statusEfetivo($s),
                // 2026-07-20 — modelo (template) do NPS, pra distinguir na
                // listagem qual foi respondido em empresas multi-serviço
                // (ML/Performance vs Shopee). Legado sem template → null.
                'modelo'             => $s->template?->nome,
                'auto_generated'     => (bool) $s->auto_generated,
                'generated_by'       => $s->generatedBy?->name,
                'created_at'         => $s->created_at->format('d/m/Y H:i'),
                'expires_at'         => $s->expires_at?->format('d/m/Y'),
                'completed_at'       => $s->completed_at?->format('d/m/Y H:i'),
                'score_estrategista' => $notaDe($s->response, 'estrategista'),
                'score_analista'     => $notaDe($s->response, 'analista'),
                'score_empresa'      => $notaDe($s->response, 'empresa'),
                // Quick task 260715-pu0 — nomes de quem recebeu a nota (Fase 79).
                'responsaveis'       => $responsaveisDe($s),
                'respondent'         => $s->response?->respondent_name,
                'comment'            => $s->response?->comment,
                'link'               => route('nps.respond', $s->token),
                // Quick task 260730-jzx (ajuste 2) — detalhe do link pendente
                // precisa dizer se é de UMA empresa ou de um GRUPO. Leitura de
                // coluna já carregada (group_survey_id), sem eager load novo.
                'de_grupo'           => $s->group_survey_id !== null,

                // Phase 33 Plan 33-04 + Bugfix 2026-07-08 — dual-path para o modal.
                'respostas_customizadas' => $extrasDe($s->response)->all(),
            ];

            // Fase 95 (AB-95-1/AB-95-2/AB-95-4) — `confianca` e `auditoria` só
            // existem no array para admin. Para os demais roles as chaves
            // simplesmente NÃO SÃO CRIADAS aqui (nunca null, nunca filtradas
            // depois) — a blindagem nasce no servidor, nunca na renderização.
            if ($user->isAdmin()) {
                $item['confianca'] = $this->confiancaDe($s->response);
                $item['auditoria'] = $this->auditoriaDe($s);
                // Phase 96 Plan 03 (AB-96-3) — estado da flag de invalidação,
                // admin-only (mesma blindagem de confianca/auditoria acima).
                // A UI usa para alternar o botão Invalidar/Revalidar e mostrar
                // a tag "Invalidada" no modal de detalhe.
                $item['invalidada'] = (bool) $s->response?->invalidated_at;
            }

            return $item;
        });

        // ─── 3 cards de média (somente respostas do mês filtrado) ────────────
        // Bugfix 2026-07-08 — dual-path: como AVG(score_*) do SQL ignora
        // respostas v15 (colunas null), calculamos em PHP iterando os responses
        // e usando NpsScoreCalculator quando template_id != null.
        // Trade-off perf: ~150 responses/mês = O(150) em memória — aceitável.
        $responsesFilter = function ($q) use ($mesInicio, $mesFim, $user, $aplicarFiltrosSurveys, $filtroPorPessoa) {
            $q->where(function ($qq) use ($mesInicio, $mesFim) {
                $qq->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                   ->orWhere(function ($qqq) use ($mesInicio, $mesFim) {
                       $qqq->whereNull('month_reference')
                           ->whereBetween('created_at', [$mesInicio, $mesFim]);
                   });
            });
            if (!$user->isAdmin()) {
                // Bugfix 2026-07-22 — escopo por serviço (ver comentário no $baseQuery).
                $filtroPorPessoa($q, $user->id, null);
            }
            // Quick task 260612-flt — propaga filtros para os cards.
            $aplicarFiltrosSurveys($q);
        };

        $responsesMes = NpsResponse::query()
            ->with(['survey', 'answers'])
            ->whereHas('survey', $responsesFilter)
            // Phase 96 (AB-96-3) — resposta invalidada pelo admin sai dos
            // cards de média. A LISTAGEM paginada ($surveys, abaixo) NÃO usa
            // este filtro — o admin precisa continuar vendo a resposta para
            // gerenciá-la/revalidar.
            ->whereNull('invalidated_at')
            ->get();

        // Fase 116 D2 — a nota DEFINITIVA ganha da resposta tardia: se o
        // survey já tem linha `definitivo` nesta janela (competência já
        // fechada), a resposta real que chegou depois não pode reescrever a
        // nota daquela competência. Blindagem de invariante — na prática
        // quase nunca dispara, pois o link expira em expires_at=endOfMonth.
        $surveyIdsDefinitivosMes = $this->imputationService->surveyIdsComNotaDefinitiva($mesInicio, $mesFim);
        if ($surveyIdsDefinitivosMes->isNotEmpty()) {
            $responsesMes = $responsesMes->reject(fn($r) => $surveyIdsDefinitivosMes->contains($r->survey_id));
        }

        // Fase 116 — notas imputadas (não respondido = 1) do mês filtrado,
        // por dimensão, reusando o MESMO $responsesFilter das respostas reais.
        $notasImputadasMes = $this->notasImputadasPorDimensao($mesInicio, $mesFim, $responsesFilter);

        // Fase 119.1 (D1) — universo de empresas em escopo desta tela, para a
        // leitura "elegível sem link conta 1" (NpsSemLinkService). Espelha o
        // mesmo escopo de carteira do <select> de empresas (abaixo) e dos
        // filtros de faltantes acima — coarse por carteira (não por serviço);
        // o próprio NpsSemLinkService resolve elegibilidade por
        // (empresa, modelo) internamente via NpsElegibilidadeService.
        $companyIdsEscopoQuery = Company::query()->where('active', true);
        if (!$user->isAdmin()) {
            $companyIdsEscopoQuery->whereHas('users', fn ($u) => $u->where('users.id', $user->id));
        }
        if ($empresaId) {
            $companyIdsEscopoQuery->where('id', $empresaId);
        }
        if ($estrategistaId) {
            $companyIdsEscopoQuery->whereHas('users', fn ($u) => $u->where('users.id', $estrategistaId)->where('company_users.role', 'estrategista'));
        }
        if ($analistaId) {
            $companyIdsEscopoQuery->whereHas('users', fn ($u) => $u->where('users.id', $analistaId)->where('company_users.role', 'consultor'));
        }
        $companyIdsEscopo = $companyIdsEscopoQuery->pluck('id');
        $templateIdsFiltro = $templateId ? collect([$templateId]) : null;

        // Fase 119.1 (D1) — empresa ELEGÍVEL sem NENHUM link no mês também
        // conta nota 1, na MESMA régua do bônus (NpsSemLinkService). Ramo
        // ADITIVO e DISJUNTO dos ramos acima: `notasDaEmpresaSemLink()`
        // rejeita internamente qualquer empresa que já tenha survey na
        // competência (mesmo pendente) — nunca duplica com as respondidas
        // reais nem com as imputadas ($notasImputadasMes).
        foreach (['estrategista', 'analista', 'empresa'] as $dimensao) {
            $notasImputadasMes[$dimensao] = $notasImputadasMes[$dimensao]->concat(
                $this->npsSemLinkService->notasDaEmpresaSemLink(
                    $companyIdsEscopo,
                    $dimensao,
                    $mesInicio,
                    $mesFim,
                    $invalidadasCompetenciaAtual,
                    $templateIdsFiltro,
                )
            );
        }

        $agregarMedia = function ($responses, string $dimensao, ?\Illuminate\Support\Collection $notasImputadas = null) use ($notaDe) {
            // Fase 116 — `collect()` explícito: $responses é uma Eloquent
            // Collection e o `->map()` dela preserva o tipo Eloquent mesmo
            // depois de virar uma lista de floats/null. O `merge()` da
            // Eloquent Collection assume itens com `getKey()` (modelos) —
            // sem este cast, mesclar as notas sintéticas (floats puros)
            // quebra com "Call to a member function getKey() on float".
            $notas = collect($responses->map(fn($r) => $notaDe($r, $dimensao))->all())
                ->filter(fn($n) => $n !== null);

            // Cada linha imputada vale nota 1.0 (D4, piso da escala real
            // 1-5). `nao_respondidos` é a contagem exposta ao payload para a
            // UI (Plan 05) explicar a regra sem jargão.
            $totalImputadas = $notasImputadas ? $notasImputadas->count() : 0;
            if ($totalImputadas > 0) {
                $notas = $notas->merge(array_fill(0, $totalImputadas, 1.0));
            }

            return [
                'media'           => $notas->isEmpty() ? 0 : round((float) $notas->avg(), 2),
                'total'           => $notas->count(),
                'nao_respondidos' => $totalImputadas,
            ];
        };

        $cards = [
            'estrategista' => $agregarMedia($responsesMes, 'estrategista', $notasImputadasMes['estrategista']),
            'analista'     => $agregarMedia($responsesMes, 'analista', $notasImputadasMes['analista']),
            'empresa'      => $agregarMedia($responsesMes, 'empresa', $notasImputadasMes['empresa']),
        ];

        // ─── Série 12 meses para o LineChart ─────────────────────────────────
        // Bugfix 2026-07-08 — 1 query por mês carregando responses com answers,
        // agregação em PHP via helper dual-path. ~150 responses/mês × 12 = 1.8k
        // objetos em memória durante o request — dentro do budget.
        $serieMeses = [];
        $inicio12m  = now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $m    = $inicio12m->copy()->addMonths($i);
            $mFim = $m->copy()->endOfMonth();

            // Fase 116 — mesma closure de filtro do mês corrente ($responsesFilter),
            // reaproveitada para as respostas reais E para as notas imputadas
            // desta iteração, garantindo que a série honre a MESMA composição
            // dos cards (mesmo escopo de carteira/pessoa/modelo).
            $responsesFilterMes = function ($qq) use ($m, $mFim, $user, $aplicarFiltrosSurveys, $filtroPorPessoa) {
                $qq->where(function ($qqq) use ($m, $mFim) {
                    $qqq->whereBetween('month_reference', [$m->toDateString(), $mFim->toDateString()])
                        ->orWhere(function ($qqqq) use ($m, $mFim) {
                            $qqqq->whereNull('month_reference')
                                 ->whereBetween('created_at', [$m, $mFim]);
                        });
                });
                if (!$user->isAdmin()) {
                    // Bugfix 2026-07-22 — escopo por serviço (ver $baseQuery).
                    $filtroPorPessoa($qq, $user->id, null);
                }
                // Quick task 260612-flt — propaga filtros na serie 12m.
                $aplicarFiltrosSurveys($qq);
            };

            $responsesM = NpsResponse::query()
                ->with(['survey', 'answers'])
                ->whereHas('survey', $responsesFilterMes)
                // Phase 96 (AB-96-3) — mesmo filtro dos cards acima.
                ->whereNull('invalidated_at')
                ->get();

            // Fase 116 D2 — mesma blindagem dos cards, mês a mês.
            $surveyIdsDefinitivosM = $this->imputationService->surveyIdsComNotaDefinitiva($m, $mFim);
            if ($surveyIdsDefinitivosM->isNotEmpty()) {
                $responsesM = $responsesM->reject(fn($r) => $surveyIdsDefinitivosM->contains($r->survey_id));
            }

            $notasImputadasM = $this->notasImputadasPorDimensao($m, $mFim, $responsesFilterMes);

            // Fase 119.1 (D1) — mesmo ramo aditivo/disjunto dos cards do mês
            // filtrado, mês a mês na série de 12 meses (competência de
            // invalidação também desloca junto: mês de coleta M+1 → mês
            // financeiro M, mesma régua de $invalidadasCompetenciaAtual acima).
            $invalidadasM = BonusInvalidacao::companyIdsInvalidadas($m->copy()->subMonthNoOverflow()->startOfMonth());
            foreach (['estrategista', 'analista', 'empresa'] as $dimensao) {
                $notasImputadasM[$dimensao] = $notasImputadasM[$dimensao]->concat(
                    $this->npsSemLinkService->notasDaEmpresaSemLink(
                        $companyIdsEscopo,
                        $dimensao,
                        $m,
                        $mFim,
                        $invalidadasM,
                        $templateIdsFiltro,
                    )
                );
            }

            // 2026-08-14 — o mês exibido continua sendo o de COLETA (`mes`), que
            // é o que a pessoa reconhece e o que o `?mes=` seleciona. O que
            // faltava era dizer A QUE mês aquela coleta se refere: o NPS
            // coletado em agosto avalia julho (régua M/M+1 do bônus,
            // `NpsJanelaResolver::mesDeColeta()` lida ao contrário). Por isso os
            // dois campos novos — a UI monta "ago/26 · ref. jul/26" em vez de
            // trocar o nome do bucket, que só transferiria a confusão de lado.
            $competencia = $m->copy()->subMonthNoOverflow();

            $serieMeses[] = [
                'mes'                => $m->locale('pt_BR')->isoFormat('MMM/YY'), // ex: 'ago./26' (COLETA)
                'mes_iso'            => $m->format('Y-m'),                        // chave do filtro (coleta)
                'competencia'        => $competencia->format('Y-m'),              // mês AVALIADO
                'competencia_label'  => $competencia->locale('pt_BR')->isoFormat('MMM/YY'),
                'estrategista' => $agregarMedia($responsesM, 'estrategista', $notasImputadasM['estrategista'])['media'],
                'analista'     => $agregarMedia($responsesM, 'analista', $notasImputadasM['analista'])['media'],
                'empresa'      => $agregarMedia($responsesM, 'empresa', $notasImputadasM['empresa'])['media'],
            ];
        }

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->companies()->get(['companies.id', 'companies.name']);

        // Quick task 260612-flt — listas para os Selects de filtro.
        // Estrategistas/analistas: users active que TÊM ao menos 1 empresa atribuída
        // pelo pivot role correspondente (não pega quem nunca foi vinculado).
        $estrategistas = \App\Models\User::where('active', true)
            ->whereHas('companies', fn($q) => $q->where('company_users.role', 'estrategista'))
            ->orderBy('name')
            ->get(['id', 'name']);
        $analistas = \App\Models\User::where('active', true)
            ->whereHas('companies', fn($q) => $q->where('company_users.role', 'consultor'))
            ->orderBy('name')
            ->get(['id', 'name']);

        // UAT 2026-07-07: só admin ou líder pode filtrar por estrategista/
        // analista específicos — analista/estrategista comum já vê apenas
        // NPS da sua carteira (linha 92-95), sem necessidade do filtro.
        $podeFiltrarPorPessoa = $user->isAdmin() || $user->isLider();

        // Ajuste 2026-07-13 · lista de modelos NPS pro <select> de filtro.
        // Só templates ATIVOS entram na lista de filtro (arquivados/desativados
        // aparecem só se estiverem sendo filtrados no momento — a UI lida com
        // isso via seleção controlada). Order alfabético pra facilitar busca.
        $templates = \App\Models\NpsTemplate::query()
            ->where('active', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        // Fase 119.1 Plan 07 (deviation Rule 2/3 — sem isto o seletor "Um
        // grupo de empresas" do modal "Gerar link" não tem o que listar).
        // Admin vê todos os grupos; não-admin só vê grupo em que TODAS as
        // empresas estão na carteira dele — mesma regra de
        // NpsGrupoController::autorizarAcessoAoGrupo() (nunca parcial, para
        // não vazar nem a existência de um grupo com empresa fora do escopo).
        $companyIdsUsuario = $user->isAdmin() ? null : $user->companies()->pluck('companies.id');
        $grupos = \App\Models\CompanyGroup::query()
            ->when(!$user->isAdmin(), function ($q) use ($companyIdsUsuario) {
                $q->whereHas('companies')
                    ->whereDoesntHave('companies', function ($qq) use ($companyIdsUsuario) {
                        $qq->whereNotIn('companies.id', $companyIdsUsuario);
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $props = [
            'surveys'                => $surveys,
            'companies'              => $companies,
            'estrategistas'          => $estrategistas,
            'analistas'              => $analistas,
            'templates'              => $templates,
            'grupos'                 => $grupos,
            'pode_filtrar_por_pessoa' => $podeFiltrarPorPessoa,
            'cards'          => $cards,
            'contadores'     => $contadores,
            'faltantes'      => $faltantes,
            'serie_12m'      => $serieMeses,
            'mes_filtro'     => $mesFiltro,
            // 2026-08-14 — `mes_filtro` é o mês de COLETA (contrato do `?mes=`,
            // inalterado): `?mes=2026-08` traz o NPS COLETADO em agosto, que
            // avalia julho. Estes dois existem para a UI conseguir escrever
            // isso na tela — "ago/26 · ref. jul/26" — em vez de deixar a pessoa
            // adivinhar de que mês é a nota que está vendo.
            'competencia_filtro' => $mesInicio->copy()->subMonthNoOverflow()->locale('pt_BR')->isoFormat('MMM/YY'),
            'coleta_filtro'      => $mesInicio->copy()->locale('pt_BR')->isoFormat('MMM/YY'),
            // Fase 116 — sinaliza para a UI (Plan 05) que a regra "não
            // respondido conta como nota 1" está ativa nesta tela. Cada card
            // já expõe `nao_respondidos` (ver $agregarMedia acima).
            'regra_nao_respondido' => true,
            'filtros'        => [
                'empresa_id'      => $empresaId,
                'estrategista_id' => $estrategistaId,
                'analista_id'     => $analistaId,
                'template_id'     => $templateId,
                // 2026-07-13 · estado do filtro de modelo pro <select> refletir
                // "Todos os modelos" vs. principal (default) vs. específico.
                'template_todos'  => $templateTodos,
            ],
            'principal_template_id' => \App\Models\NpsTemplate::principalId(),
        ];

        // Fase 95 (AB-95-4) — `pode_ver_confianca` só existe no payload para
        // admin. Não-admin não recebe nem SINAL de que a camada de confiança
        // existe — a chave simplesmente não é criada (nunca `false`).
        if ($user->isAdmin()) {
            $props['pode_ver_confianca']       = true;
            // Fase 95 (AB-95-3) — reflete o filtro aplicado; ausente para
            // não-admin (nem a CHAVE existe dentro de `filtros`).
            $props['filtros']['confianca'] = $confiancaFiltro;
        }

        return Inertia::render('Nps/Index', $props);
    }

    /**
     * Quick task 260730-jzx (ajuste 3) — colapsa em UMA linha as empresas
     * FALTANTES de um mesmo (setor, grupo) quando `NpsGrupoCoberturaService`
     * confirma que todas são cuidadas pelas MESMAS pessoas (DQ-05: a régua de
     * "mesma dupla" vive no serviço, nunca reimplementada aqui). Balde com
     * menos de 2 empresas na interseção real da cobertura mantém as linhas
     * individuais (DQ-04). O serviço é chamado UMA vez por (grupo, setor),
     * nunca dentro de um laço por empresa.
     *
     * Roda DEPOIS do laço que grava `conta_nota_1`/`conta_nota_1_count` (só
     * agrega valores já calculados) e ANTES do `usort`/contadores (DQ-03: os
     * contadores somam `empresas_count`, então o colapso não muda nenhum
     * número, só encurta a lista).
     *
     * @param array<int, array> $faltantes
     * @return array<int, array>
     */
    private function colapsarFaltantesPorGrupo(array $faltantes, Carbon $mesInicio): array
    {
        // Baldes por (setor, company_group_id) — ignora empresa sem grupo.
        $baldes = [];
        foreach ($faltantes as $idx => $f) {
            if (($f['tipo'] ?? null) !== 'empresa' || empty($f['company_group_id'])) {
                continue;
            }
            $chave = $f['setor'] . '|' . $f['company_group_id'];
            $baldes[$chave][] = $idx;
        }

        // DQ-04 — só vale consultar o serviço para baldes com 2+ linhas.
        $baldesElegiveis = array_filter($baldes, fn ($idxs) => count($idxs) >= 2);
        if (empty($baldesElegiveis)) {
            return $faltantes;
        }

        // Carrega TODOS os grupos e modelos envolvidos em 2 queries (nunca
        // dentro do laço por balde/empresa — armadilha 4).
        $grupoIds = collect($baldesElegiveis)
            ->map(fn ($idxs) => (int) $faltantes[$idxs[0]]['company_group_id'])
            ->unique()
            ->values();
        $grupos = CompanyGroup::with('companies')->whereIn('id', $grupoIds)->get()->keyBy('id');

        $templateIds = collect($baldesElegiveis)
            ->map(fn ($idxs) => $faltantes[$idxs[0]]['template_id'])
            ->unique()
            ->values();
        $templates = \App\Models\NpsTemplate::whereIn('id', $templateIds)->get()->keyBy('id');

        $indicesParaRemover = [];
        $linhasDeGrupo = [];

        foreach ($baldesElegiveis as $idxs) {
            $representante = $faltantes[$idxs[0]];
            $grupo = $grupos->get((int) $representante['company_group_id']);
            $template = $templates->get($representante['template_id']);

            if (!$grupo || !$template) {
                continue; // defesa — não deveria acontecer com dados consistentes
            }

            // UMA chamada por (grupo, setor) — nunca por empresa.
            $cobertura = $this->grupoCoberturaService->calcular($grupo, $template, $mesInicio);
            $incluidasIds = collect($cobertura['incluidas'])->pluck('company_id');

            $idxsCobertos = collect($idxs)->filter(
                fn ($idx) => $incluidasIds->contains($faltantes[$idx]['company_id'])
            )->values();

            if ($idxsCobertos->count() < 2) {
                continue; // DQ-04 — cobertura real não chega a 2 empresas
            }

            $linhas = $idxsCobertos->map(fn ($idx) => $faltantes[$idx]);

            $linhasDeGrupo[] = [
                'tipo'               => 'grupo',
                'group_id'           => $grupo->id,
                'name'               => $grupo->name,
                'empresas_count'     => $linhas->count(),
                'empresas_nomes'     => $linhas->pluck('name')->values()->all(),
                'modelo'             => $representante['modelo'],
                'template_id'        => $representante['template_id'],
                'setor'              => $representante['setor'],
                'conta_nota_1'       => $linhas->contains(fn ($l) => $l['conta_nota_1']),
                'conta_nota_1_count' => $linhas->sum('conta_nota_1_count'),
            ];

            foreach ($idxsCobertos as $idx) {
                $indicesParaRemover[$idx] = true;
            }
        }

        if (empty($indicesParaRemover)) {
            return $faltantes;
        }

        $resultado = [];
        foreach ($faltantes as $idx => $f) {
            if (!isset($indicesParaRemover[$idx])) {
                $resultado[] = $f;
            }
        }

        return array_merge($resultado, $linhasDeGrupo);
    }

    /**
     * Fase 116 · notas imputadas (NPS não respondido = nota 1, Plan 01) da
     * janela [$mesInicio, $mesFim], por dimensão (estrategista/analista/
     * empresa — D7 inclui a dimensão empresa). Reusa LITERALMENTE a mesma
     * closure `$responsesFilter` aplicada às respostas REAIS (T-116-03-01) —
     * carteira/pessoa/modelo/empresa nunca podem divergir entre imputadas e
     * respondidas nesta tela.
     *
     * Dedupe por survey_id (regra DESTA tela — diferente do bônus, que
     * dedupe por survey+role/pessoa): um survey multi-serviço pode gerar 2
     * linhas na MESMA dimensão (2 responsáveis de serviços diferentes), mas
     * aqui cada survey vale 1 nota por dimensão — senão um survey de empresa
     * com 2 serviços pesaria o dobro de um survey de 1 serviço só.
     *
     * @return array<string, \Illuminate\Support\Collection> chave = dimensão
     */
    private function notasImputadasPorDimensao(Carbon $mesInicio, Carbon $mesFim, callable $responsesFilter): array
    {
        // Fase 116 D5 — a área NPS passa a respeitar bonus_invalidacoes
        // (capacidade NOVA: hoje esta tela só respeita a invalidação manual
        // de RESPOSTA, NpsResponse::invalidated_at, que é outro conceito).
        // Competência da invalidação = mês do survey MENOS 1 mês (NPSWIN-03,
        // mesma régua já usada em bustarCacheDoBonus/DesempenhoScoreService):
        // bonus_invalidacoes.competencia é a competência financeira M, e o
        // NPS de M é coletado em M+1.
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas(
            $mesInicio->copy()->subMonthNoOverflow()->startOfMonth()
        );

        $porDimensao = [];
        foreach (['estrategista', 'analista', 'empresa'] as $dimensao) {
            $query = NpsImputedAssignment::vigentes()
                ->where('dimensao', $dimensao)
                ->whereBetween('competencia_nps', [$mesInicio->toDateString(), $mesFim->toDateString()])
                ->whereHas('survey', $responsesFilter);

            if ($invalidadas->isNotEmpty()) {
                $query->whereNotIn('company_id', $invalidadas->all());
            }

            $porDimensao[$dimensao] = $query->get()->unique('survey_id')->values();
        }

        return $porDimensao;
    }

    /**
     * Fase 95 (AB-95-1) — mapeia o veredito já persistido pela Fase 94 para
     * o tri-estado exigido pelo CONTEXT. Leitura pura: NUNCA recalcula
     * suspeita (isso é responsabilidade exclusiva de NpsSuspicionService).
     *
     * Resposta limpa persiste `suspicion_reasons=null` (capturarRastroEAvalia
     * rSuspeita, linha 749+) — cai automaticamente em 'confiavel', o mesmo
     * comportamento correto para respostas legadas (pré-Fase 94).
     *
     * PROIBIDO usar `is_suspicious` para decidir a cor: é só
     * `severity !== 'nenhuma'`, perderia o estado intermediário 'atencao'.
     *
     * @return array{status: string, motivos: array<int, string>}|null
     */
    private function confiancaDe(?NpsResponse $response): ?array
    {
        if (!$response) {
            return null; // survey ainda pendente — sem resposta, sem veredito
        }

        $severity = $response->suspicion_reasons['severity'] ?? 'nenhuma';
        $status = match ($severity) {
            'alta'  => 'suspeita',
            'media' => 'atencao',
            default => 'confiavel',
        };

        return [
            'status'  => $status,
            'motivos' => $response->suspicion_reasons['reasons'] ?? [],
        ];
    }

    /**
     * Fase 95 (AB-95-2) — seção "Auditoria" do detalhe, admin-only. Todos os
     * campos já existem em nps_surveys/nps_responses/nps_survey_events (Fase
     * 94) — leitura pura, nada é recalculado (ex.: `tempo_ate_resposta` NUNCA
     * recalcula via diffInSeconds — pitfall Carbon 3 documentado no SUMMARY
     * 94-02, reusa `response_duration_seconds` já gravado).
     *
     * `canal` deriva de `$s->events` (eager-loaded só para admin, ver
     * `$eagerLoads` acima) — evita 2 queries extras contra
     * nps_email_envios/nps_digisac_envios.
     *
     * @return array{
     *   gerado_em: string, gerado_por: ?string,
     *   aberto_primeira: ?string, aberto_ultima: ?string, aberto_contagem: int,
     *   respondido_em: ?string, tempo_ate_resposta: ?int,
     *   ip_abertura: ?string, ip_resposta: ?string, user_agent: ?string,
     *   canal: string, motivos: array<int, string>
     * }
     */
    private function auditoriaDe(NpsSurvey $s): array
    {
        $eventos      = $s->events;
        $temEmail     = $eventos->contains('event_type', NpsSurveyEvent::TYPE_SENT_EMAIL);
        $temDigisac   = $eventos->contains('event_type', NpsSurveyEvent::TYPE_SENT_DIGISAC);
        $origemGerado = $eventos->firstWhere('event_type', NpsSurveyEvent::TYPE_GENERATED)?->metadata['origem'] ?? null;

        $canal = match (true) {
            $temEmail && $temDigisac    => 'Email + Digisac',
            $temEmail                   => 'Email',
            $temDigisac                 => 'Digisac',
            $origemGerado === 'manual'  => 'Manual (link gerado por admin)',
            default                     => 'Não confirmado',
        };

        return [
            'gerado_em'          => $s->created_at->format('d/m/Y H:i'),
            'gerado_por'         => $s->generatedBy?->name, // null = disparo mensal automático
            'aberto_primeira'    => $s->first_opened_at?->format('d/m/Y H:i'),
            'aberto_ultima'      => $s->last_opened_at?->format('d/m/Y H:i'),
            'aberto_contagem'    => $s->open_count,
            'respondido_em'      => $s->completed_at?->format('d/m/Y H:i'),
            'tempo_ate_resposta' => $s->response?->response_duration_seconds,
            'ip_abertura'        => $s->open_ip_address,
            'ip_resposta'        => $s->response?->response_ip_address,
            'user_agent'         => $s->response?->response_user_agent ?? $s->open_user_agent,
            'canal'              => $canal,
            'motivos'            => $s->response?->suspicion_reasons['reasons'] ?? [],
        ];
    }

    /**
     * Geração manual de link NPS (fluxo legacy preservado — REQ-31-08).
     *
     * Surveys criadas aqui ficam com `auto_generated=false` e
     * `month_reference=null`, distinguindo-as das surveys mensais
     * automatizadas (Plan 02 / Plan 04).
     *
     * `expires_at` = FIM DO MÊS CORRENTE (2026-07-20: o link vale apenas dentro
     * do mês em que foi gerado; ao virar o mês ele expira). Antes eram 14 dias
     * fixos (e 7 antes disso). Combinado com o desligamento do prune de
     * pendentes, o link nunca é apagado — só passa a exibir status "expirado"
     * na tela quando o mês vira.
     */
    public function generate(Request $request, NpsTemplateService $templateService)
    {
        $user = $request->user();
        $data = $request->validate([
            'company_id'  => 'required|exists:companies,id',
            // Ajuste 2026-07-13 · admin pode escolher qual modelo NPS o link
            // vai usar. Nullable — se ausente, cai no resolveForCompany
            // (default por priority/serviço) preservando o comportamento
            // original.
            'template_id' => 'nullable|integer|exists:nps_templates,id',
        ]);

        // Auth: admin OR user com a empresa em qualquer role no pivot
        // company_users (inclui estrategista, consultor, mentor). Padrão
        // consolidado da Phase 62 — superset seguro que preserva REQ-31-08
        // (compat com generate manual atual) E admite estrategista da
        // carteira sem restringir os demais roles historicamente autorizados.
        if (!$user->isAdmin()) {
            $allowed = $user->companies()->pluck('companies.id');
            if (!$allowed->contains($data['company_id'])) {
                abort(403);
            }
        }

        // Phase 69 NPS-B-01: resolve o template NPS aplicável à empresa
        // (priority DESC → is_default fallback). O survey nasce já com
        // template_id populado — sem este bind, a dedup unique parcial
        // (Plan 68-04) e o snapshot per-row (Phase 68) ficam degradados.
        //
        // Quick task 260715-ndo (Bug A, ativo em produção): o modal
        // "Gerar link" (Fase 81) é modelo-first e já oferece o seletor para
        // QUALQUER usuário autorizado (não só admin) — o gate isAdmin() que
        // existia aqui era mentiroso: a UI dizia "escolha o modelo" e o
        // servidor, para não-admin, IGNORAVA silenciosamente a escolha e
        // caía no auto-resolve por priority. Resultado medido em produção:
        // 15 links do modelo errado gerados por não-admin (2 já respondidos,
        // notas atribuídas ao responsável do setor errado).
        //
        // Decisão de produto: empresa com múltiplos serviços (ex.: ML +
        // Shopee) deve poder gerar um NPS por serviço, cada um endereçado ao
        // responsável do seu setor — espelhando o disparo mensal multi-modelo
        // (nps:disparar-mensal). Por isso o override agora vale para
        // qualquer usuário que já passou pela autorização de empresa acima
        // (linhas anteriores, inalteradas) — só falta validar que o modelo
        // pedido REALMENTE se aplica a esta empresa (defesa em profundidade:
        // o modal já filtra por `empresasElegiveis`, mas se um dia divergir,
        // sem esta validação o servidor geraria um NPS Shopee para empresa
        // sem Shopee e a nota viraria órfã — NpsSnapshotService só loga
        // "responsável faltante" e segue).
        $company = Company::findOrFail($data['company_id']);

        if (!empty($data['template_id'])) {
            // Busca sem filtrar por `active` na query — precisamos distinguir
            // "não existe" (já coberto por exists:nps_templates,id do
            // validate acima) de "existe mas está inativo", para devolver a
            // mensagem certa em cada caso.
            $template = \App\Models\NpsTemplate::findOrFail($data['template_id']);

            if (!$template->active) {
                return back()->with('error', 'Este modelo de NPS está desativado e não pode gerar novos links.');
            }

            // Espelha os 2 ramos de `NpsTemplateController::empresasElegiveis`
            // (NÃO 3 — não existe rejeição por `active` da empresa aqui):
            //   (a) modelo COM serviços cobertos → exige contrato ATIVO da
            //       empresa em pelo menos um deles;
            //   (b) modelo SEM serviços cobertos (pivot vazio, ex.: NPS
            //       Padrão) → aceito para qualquer empresa (fallback).
            $servicoIds = $template->servicos()->pluck('servicos.id');
            if ($servicoIds->isNotEmpty()) {
                $temContratoAtivo = $company->contratosServico()
                    ->active()
                    ->whereIn('servico_id', $servicoIds)
                    ->exists();

                if (!$temContratoAtivo) {
                    return back()->with('error', 'Este modelo de NPS não se aplica a esta empresa — ele cobre serviços que a empresa não tem contratados no momento.');
                }
            }
        } else {
            // Sem template_id (ex.: consumidor antigo/API) → auto-resolve
            // por empresa, comportamento original preservado.
            $template = $templateService->resolveForCompany($company);
        }

        // Fase 119.1 Plan 02 — guard de duplicidade: impede gerar um SEGUNDO
        // link para (mesma empresa, mesmo modelo, mesmo mês).
        //
        // Por que o índice único do banco (Fase 68 Plan 04) NÃO resolve isto:
        // ele só trava DUAS respostas `completed` — links `pending` coexistem
        // de propósito (o operador pode reabrir/reenviar o mesmo link várias
        // vezes antes do cliente responder). Nada ali impede um SEGUNDO
        // survey pendente de nascer.
        //
        // Por que a competência é resolvida com o fallback
        // `month_reference ?? created_at` (via `competenciaDoSurvey()` /
        // `surveyExistenteNaCompetencia()`): surveys manuais SEMPRE nascem
        // com `month_reference = NULL` (D-12, comentário abaixo) — comparar
        // a coluna crua não pegaria nenhum link manual, e é exatamente essa
        // brecha que o usuário reportou.
        $jaExiste = $this->elegibilidadeService->surveyExistenteNaCompetencia(
            (int) $data['company_id'],
            (int) $template->id,
            now()->startOfMonth(),
        );

        if ($jaExiste !== null) {
            return back()->with([
                'nps_link_existente' => route('nps.respond', $jaExiste->token),
                'error'              => 'Esta empresa já tem um link deste modelo de pesquisa neste mês. '
                    .'Enviar um segundo link pode fazer o cliente responder duas vezes. Use o link que já existe.',
            ]);
        }

        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $data['company_id'],
            'generated_by'   => $user->id,
            'expires_at'     => now()->endOfMonth(), // 2026-07-20: link vale só no mês corrente; expira ao virar o mês (sem ser apagado)
            'status'         => 'pending',
            // REQ-31-08: explicita auto_generated=false em surveys manuais
            // para o admin filtrar "manual vs automatico" na UI (Plan 31-04).
            'auto_generated' => false,
            // Phase 69 NPS-B-01 — template resolvido via NpsTemplateService.
            'template_id'    => $template->id,
            // month_reference fica null para manuais (D-12) — só surveys
            // mensais automatizadas carregam o mês de referência semântico.
        ]);

        // Phase 94 AB-94-3 — trilha de auditoria: link manual gerado.
        // metadata.origem='manual' é o discriminador contra 'disparo_mensal'
        // (plano 94-03) — manter os dois literais exatos.
        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_GENERATED,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id'    => $user->id,
            'metadata'   => ['origem' => 'manual'],
        ]);

        // Fase 116 (D2/D6) — materializa a nota 1 (provisória) desde o
        // disparo manual, sem esperar o cron diário. Surveys manuais nascem
        // com `month_reference=NULL` — o serviço já cobre esse caso via
        // fallback `created_at` (D6). Falha aqui NUNCA pode abortar o
        // disparo do NPS: o cron diário (`nps:materializar-nao-respondidos`)
        // corrige depois.
        try {
            $this->imputationService->materializar($survey);
        } catch (\Throwable $e) {
            Log::warning('[NPS Imputação] falha ao materializar no disparo manual', [
                'survey_id' => $survey->id,
                'erro'      => $e->getMessage(),
            ]);
        }

        return back()->with([
            'success'  => 'Link NPS gerado com sucesso.',
            'nps_link' => route('nps.respond', $survey->token),
        ]);
    }

    /**
     * Form público de resposta — recebe o token e renderiza a UI Nps/Respond.jsx.
     *
     * Payload Inertia em Phase 31 (D-07): expõe `estrategista_name`,
     * `analista_name` (nullable) e `tem_analista` (bool). A UI usa
     * `tem_analista` para decidir se mostra o campo de analista (mentoria
     * pura omite). Chaves legacy `mentor_name`/`consultant_name` foram
     * removidas — Plan 31-03 reescreve Respond.jsx para consumir as
     * chaves novas.
     */
    public function respond(Request $request, string $token)
    {
        // Phase 71 Plan 01 — eager-load `template.questions.options` na MESMA
        // query do survey para o form dinâmico v15.0 (REQ NPS-D-01). Relations
        // `NpsTemplate::questions()` e `NpsTemplateQuestion::options()` (Phase 68)
        // já vêm ordenadas por (ordem ASC, id ASC) — nenhuma reordenação extra
        // no controller. Surveys legacy (template_id NULL) ficam com
        // $survey->template = null e caem no fluxo Phase 33 abaixo.
        $survey = NpsSurvey::with([
                'company',
                'generatedBy',
                'template.questions.options',
            ])
            ->where('token', $token)
            ->firstOrFail();

        // Phase 94 AB-94-1 — rastro de abertura (roda em TODO GET, mesmo
        // completed/expired: reaberturas de link vencido/já respondido são
        // sinal técnico relevante para a Fase 95). first_opened_at NUNCA é
        // sobrescrito (decisão travada do CONTEXT) — sempre `??` contra o
        // valor já persistido.
        $survey->update([
            'first_opened_at' => $survey->first_opened_at ?? now(),
            'last_opened_at'  => now(),
            'open_count'      => $survey->open_count + 1,
            'open_ip_address' => $request->ip(),
            'open_user_agent' => $request->userAgent(),
        ]);

        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_OPENED,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id'    => auth()->id(), // nullable — sessão interna coexistente (Regra 4 / Fase 95)
            'metadata'   => ['first_open' => $survey->open_count === 1],
        ]);

        if ($survey->status === 'completed') {
            return Inertia::render('Nps/AlreadyCompleted');
        }

        if ($survey->isExpired()) {
            $survey->update(['status' => 'expired']);

            // Phase 94 AB-94-3 — único lugar do codebase que transiciona o
            // status para 'expired' (expiração é lazy, não há job agendado).
            NpsSurveyEvent::create([
                'survey_id'  => $survey->id,
                'event_type' => NpsSurveyEvent::TYPE_EXPIRED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id'    => auth()->id(),
                'metadata'   => null,
            ]);

            return Inertia::render('Nps/Expired');
        }

        // Phase 96 AB-96-1 (hotfix 2026-07-17) — bloqueio já na ABERTURA:
        // usuário interno autenticado que abre um survey respondível vê a tela
        // de bloqueio no lugar das perguntas. Antes o bloqueio existia só no
        // submit (POST), o que deixava o interno abrir e preencher o formulário
        // inteiro para só então ser barrado — UX confusa e lida como "não
        // funcionou". Clientes não têm login neste painel, então auth()->check()
        // só é verdadeiro para colaborador interno. O bloqueio do submit
        // permanece como defesa em profundidade (POST direto/API). O evento
        // OPENED já foi registrado acima; aqui somamos o 'blocked' para auditar.
        if (auth()->check()) {
            NpsSurveyEvent::create([
                'survey_id'  => $survey->id,
                'event_type' => NpsSurveyEvent::TYPE_BLOCKED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id'    => auth()->id(),
                'metadata'   => ['fase' => 'abertura'],
            ]);

            return Inertia::render('Nps/Blocked');
        }

        $estrategista = $survey->company->users()->wherePivot('role', 'estrategista')->first();
        $analista     = $survey->company->users()->wherePivot('role', 'consultor')->first();

        $estrategistaNome = $estrategista?->name;
        $analistaNome     = $analista?->name;
        $temAnalista      = $analista !== null;

        // ─── Phase 32 Plan 03: textos dinâmicos da página ────────────────────
        // Carrega os 6 textos das perguntas/labels editáveis em /nps/configuracao
        // e substitui os placeholders ANTES de mandar pro front (mesmo padrão do
        // NpsDispararMensal/NpsMonthlyMail — Mailable burro, render no backend).
        //
        // `mes_referencia` e `bloco_analista` não fazem sentido na página de
        // resposta (só no email), mas passamos string vazia para não deixar o
        // placeholder cru caso o admin tenha colocado algum por engano.
        $textosBrutos = NpsTextRenderer::getTextos();
        $varsPagina   = [
            'nome_estrategista' => $estrategistaNome ?? '',
            'nome_analista'     => $analistaNome ?? '',
            'nome_empresa'      => $survey->company->name,
            'mes_referencia'    => '',
            'bloco_analista'    => '',
        ];

        $textosRender = [
            'perg_estrategista'           => NpsTextRenderer::render($textosBrutos['perg_estrategista'], $varsPagina),
            'perg_analista'               => NpsTextRenderer::render($textosBrutos['perg_analista'], $varsPagina),
            'perg_empresa'                => NpsTextRenderer::render($textosBrutos['perg_empresa'], $varsPagina),
            'perg_comentario_label'       => NpsTextRenderer::render($textosBrutos['perg_comentario_label'], $varsPagina),
            'perg_comentario_placeholder' => NpsTextRenderer::render($textosBrutos['perg_comentario_placeholder'], $varsPagina),
            'perg_nome_label'             => NpsTextRenderer::render($textosBrutos['perg_nome_label'], $varsPagina),
        ];

        // ─── Phase 33 D-07 — perguntas customizadas ativas ───────────────────
        // Carrega as perguntas extras ativas para o front renderizar APOS as 3
        // perguntas fixas e ANTES do comentario (ordem D-03). Ordenacao por
        // `ordem` ASC com fallback em `id` ASC (criadas mais cedo primeiro).
        $perguntasExtras = NpsPerguntaCustomizada::where('ativa', true)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get(['id', 'texto', 'tipo', 'opcoes', 'obrigatorio']);

        // ─── Phase 71 Plan 01 — Payload dinâmico v15.0 ────────────────────────
        // Se o survey tem template associado (Phase 68/69), monta o shape que o
        // `PreviewFormulario.jsx` do Phase 70-05 consome — `Respond.jsx` (Plan
        // 71-02) reaproveita esse mesmo componente puro e envolve-o com o hook
        // de submit.
        //
        // Surveys sem template_id (legacy pre-migration Phase 68 ou dispatch
        // manual antigo) recebem `$templatePayload = null` — `Respond.jsx` cai
        // no fluxo Phase 33 (form fixo com perguntas legadas + perguntas_extras).
        //
        // IMPLICAÇÃO CRÍTICA (research §5 + Phase 69-03): cada option DEVE
        // conter `id` — `submitResponseV15` valida `answers.<qid>` via
        // `Rule::in($optionIds)`. O `peso` viaja junto só para render local
        // (Phase 71-02 mapeia ID → peso no client apenas para UI).
        $templatePayload = null;
        if ($survey->template_id !== null && $survey->template) {
            // Bugfix 2026-07-08 — placeholders {nome_estrategista}/{nome_analista}/
            // {nome_empresa} nos textos das perguntas do template precisam ser
            // resolvidos antes de mandar pro front. O seed "NPS Padrão" grava textos
            // com placeholders literais para admitir renomeação de estrategista/
            // analista por empresa (research §1 seed 100004). $varsPagina já contém
            // os nomes reais (linha 348+); reusar mesmo pattern do NpsTextRenderer
            // aplicado aos textosRender legados.
            $templatePayload = [
                'id'        => $survey->template->id,
                'nome'      => $survey->template->nome,
                'descricao' => $survey->template->descricao,
                'perguntas' => $survey->template->questions->map(function ($q) use ($varsPagina) {
                    return [
                        'id'          => $q->id,
                        'ordem'       => $q->ordem,
                        'texto'       => NpsTextRenderer::render($q->texto, $varsPagina),
                        'tipo'        => $q->tipo,
                        'dimensao'    => $q->dimensao,
                        'obrigatoria' => $q->obrigatoria,
                        'options'     => $q->options->map(fn ($o) => [
                            'id'    => $o->id,
                            'ordem' => $o->ordem,
                            'label' => $o->label,
                            'peso'  => $o->peso,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        }

        return Inertia::render('Nps/Respond', [
            'survey' => [
                'token'              => $survey->token,
                'company_name'       => $survey->company->name,
                'estrategista_name'  => $estrategistaNome,
                'analista_name'      => $analistaNome,
                'tem_analista'       => $temAnalista,
                // 2026-08-11 — foto do responsável no card "Quem cuida da sua
                // conta". `users.avatar_url` já guarda a URL pronta para uso
                // (upload local vira `/storage/avatars/...` via Storage::url;
                // foto do Google/externa vem absoluta), então vai crua, sem
                // prefixo — mesmo contrato do `foto` do PerformanceController.
                // Null quando o responsável não subiu foto: a UI cai nas
                // iniciais, que continuam sendo o comportamento padrão.
                'estrategista_foto'  => $estrategista?->avatar_url,
                'analista_foto'      => $analista?->avatar_url,
                'textos'             => $textosRender,
            ],
            'perguntas_extras' => $perguntasExtras,
            // Phase 71 Plan 01 — payload dinâmico v15.0 (null em surveys legacy).
            'template'         => $templatePayload,
        ]);
    }

    /**
     * Persiste a resposta NPS.
     *
     * Discriminacao por template_id (Phase 69 Plan 03):
     *
     *   - `template_id !== null` -> fluxo v15.0 dinamico via `submitResponseV15()`:
     *     rules derivadas do template snapshot (Rule::in nas options), gravacao
     *     em `nps_response_answers` com snapshot congelado
     *     (question_texto/dimensao/label/peso_snapshot — research §1) + guard
     *     QueryException 23000 do dedup unique parcial (research §2 + Plan 68-04)
     *     -> render `Nps/AlreadyCompleted` em colisao de duplicata mensal.
     *
     *   - `template_id === null` -> fluxo legacy Phase 31/33 preservado 100%
     *     via `submitResponseLegacy()`: score_estrategista/analista/empresa
     *     hardcoded + NpsRespostaCustomizada extras. Coexiste ate Phase 73
     *     (rows Phase 31/33 sem template_id continuam funcionando).
     *
     * Referencias:
     *   - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
     *   - .planning/research/v15-nps-templates-schema.md §2 (dedup 23000)
     *   - REQ NPS-B-03 (guard 23000) + REQ NPS-B-05 (validacao dinamica)
     */
    public function submitResponse(Request $request, string $token)
    {
        $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

        if ($survey->isExpired()) {
            return response()->json(['error' => 'Pesquisa expirada.'], 422);
        }

        // Phase 96 AB-96-1 — endurecimento da Regra 4 da Fase 94 (que hoje só
        // MARCA como suspeita): submit (POST) em sessão autenticada de
        // usuário interno é BLOQUEADO ANTES de qualquer NpsResponse::create()
        // nos dois paths abaixo. A ABERTURA (GET em respond()) permanece
        // inalterada — só o SUBMIT é afetado. Evento 'blocked' audita
        // quem tentou, mas a mensagem ao usuário não revela o gatilho.
        if (auth()->check()) {
            NpsSurveyEvent::create([
                'survey_id'  => $survey->id,
                'event_type' => NpsSurveyEvent::TYPE_BLOCKED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id'    => auth()->id(),
                'metadata'   => null,
            ]);

            return Inertia::render('Nps/Blocked');
        }

        // Discriminador Phase 69 Plan 03: template_id populado -> fluxo v15.0
        // com validacao dinamica + snapshot per-row. NULL -> legacy Phase 31/33.
        if ($survey->template_id !== null) {
            return $this->submitResponseV15($request, $survey);
        }

        return $this->submitResponseLegacy($request, $survey);
    }

    /**
     * Phase 94 AB-94-2 + AB-94-4 — captura o rastro técnico da resposta
     * (IP/user-agent/duração) e avalia suspeita via NpsSuspicionService.
     *
     * Chamado por submitResponseV15() E submitResponseLegacy() — NÃO
     * duplicar esta lógica nos dois métodos (Pitfall real de produção já
     * aconteceu por divergência entre paths — comentário na linha 437).
     *
     * @return array{
     *   response_ip_address: ?string,
     *   response_user_agent: ?string,
     *   response_duration_seconds: int,
     *   is_suspicious: bool,
     *   suspicion_reasons: ?array
     * }
     */
    private function capturarRastroEAvaliarSuspeita(Request $request, NpsSurvey $survey): array
    {
        // Carbon 3 (Laravel 12): o cálculo de diferença de tempo abaixo é
        // SIGNED. A ordem "criado->para(agora)" garante valor positivo
        // (passado → agora). NÃO inverter a chamada (agora->para(criado))
        // retornaria negativo. "generated_at" = created_at do survey (CONTEXT).
        $duracao = (int) $survey->created_at->diffInSeconds(now());

        $veredito = app(\App\Services\Nps\NpsSuspicionService::class)->evaluate(
            ip: $request->ip(),
            durationSeconds: $duracao,
            isAuthenticatedSession: auth()->check(),
        );

        return [
            'response_ip_address'       => $request->ip(),
            'response_user_agent'       => $request->userAgent(),
            'response_duration_seconds' => $duracao,
            'is_suspicious'             => $veredito['is_suspicious'],
            // Shape objeto travado (CONTEXT/RESEARCH): pronto para a Fase 95
            // consumir reasons + severity sem precisar de migration nova.
            'suspicion_reasons'         => $veredito['is_suspicious']
                ? ['reasons' => $veredito['reasons'], 'severity' => $veredito['severity']]
                : null,
        ];
    }

    /**
     * Phase 94 AB-94-3 — emite o evento 'submitted' na trilha de auditoria.
     * Chamado DENTRO da mesma DB::transaction() do submit (v15 e legado),
     * logo antes do update de status='completed' — se o guard 23000
     * estourar, o evento reverte junto (mesmo motivo do NpsSnapshotService).
     */
    private function registrarEventoSubmitted(Request $request, NpsSurvey $survey, NpsResponse $response): void
    {
        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_SUBMITTED,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id'    => auth()->id(),
            'metadata'   => ['response_id' => $response->id],
        ]);
    }

    /**
     * Fluxo v15.0 (Phase 69 Plan 03) — validacao dinamica derivada do template
     * snapshot associado ao survey + gravacao 1 NpsResponseAnswer por pergunta
     * respondida com snapshot congelado + guard QueryException 23000.
     *
     * Fonte de verdade das notas migra para `nps_response_answers.option_peso_snapshot`
     * — as colunas legacy `score_estrategista/analista/empresa` de NpsResponse
     * ficam NULL neste fluxo (Phase 68 Plan 01 tornou nullable justamente pra isso).
     * `NpsScoreCalculator::compute()` (Phase 69 Plan 02) le sempre das answers.
     */
    private function submitResponseV15(Request $request, NpsSurvey $survey)
    {
        // Eager load do template snapshot: perguntas + opcoes ordenadas.
        // Fonte da validacao dinamica (Rule::in) e do snapshot per-row.
        $survey->load('template.questions.options');

        if (!$survey->template) {
            // Consistencia quebrada: template_id != null mas relacionamento vazio
            // (FK apontando pra template deletado com nullOnDelete NAO deveria
            // acontecer porque nesse caso o proprio template_id ja teria virado
            // NULL — mas defesa em profundidade). Fallback 422 claro.
            return response()->json(['error' => 'Template do NPS nao encontrado.'], 422);
        }

        // ─── Constroi rules dinamicamente do template ────────────────────────
        // Cada pergunta gera 1 regra em answers.<qid>:
        //   - obrigatoria=true  -> required
        //   - obrigatoria=false -> nullable
        //   - Rule::in(<option_ids da question>) barra option_id de outro template
        $rules = [
            'respondent_name' => 'nullable|string|max:255',
            'comment'         => 'nullable|string|max:2000',
            'answers'         => 'nullable|array',
        ];

        $questionsById     = [];
        $optionsByQuestion = [];

        foreach ($survey->template->questions as $q) {
            $questionsById[$q->id]     = $q;
            $optionsByQuestion[$q->id] = $q->options->keyBy('id');

            $req = $q->obrigatoria ? 'required' : 'nullable';

            // Ajuste 2026-07-13 · tipo=texto_livre: aceita STRING em vez de
            // option_id. Não tem `Rule::in()` porque não existe conjunto
            // fechado de valores válidos (é campo aberto). Limite de 2000
            // char pra evitar payload gigante e alinha com `comment`.
            if ($q->tipo === \App\Models\NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                $rules["answers.{$q->id}"] = [$req, 'string', 'max:2000'];
            } else {
                $optionIds = $q->options->pluck('id')->all();
                $rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($optionIds)];
            }
        }

        $validated = $request->validate($rules);

        // ─── Persiste dentro de transacao — inclui update status='completed'
        // que pode disparar QueryException 23000 se o dedup unique parcial
        // (Plan 68-04) detectar duplicata (company_id, month_reference, template_id).
        try {
            DB::transaction(function () use ($request, $survey, $validated, $questionsById, $optionsByQuestion) {
                // NpsResponse SEM score_* legados — fonte de verdade v15.0 e
                // nps_response_answers. Colunas legacy nullable desde Phase 68 Plan 01.
                // Phase 94 AB-94-2/AB-94-4: spread do rastro + veredito de suspeita
                // via helper compartilhado (mesma linha do create — sem UPDATE extra).
                $response = NpsResponse::create([
                    'survey_id'          => $survey->id,
                    'respondent_name'    => $validated['respondent_name'] ?? null,
                    'score_estrategista' => null,
                    'score_analista'     => null,
                    'score_empresa'      => null,
                    'comment'            => $validated['comment'] ?? null,
                    ...$this->capturarRastroEAvaliarSuspeita($request, $survey),
                ]);

                // 1 NpsResponseAnswer por pergunta respondida — snapshot congelado.
                foreach (($validated['answers'] ?? []) as $qid => $answerValue) {
                    if ($answerValue === null || $answerValue === '') {
                        continue; // pergunta opcional sem resposta
                    }

                    // Defensivo: Rule::in ja barrou tampering; se por race chegou
                    // aqui com id fora do map, pula silenciosamente.
                    $question = $questionsById[$qid] ?? null;
                    if (!$question) {
                        continue;
                    }

                    // Ajuste 2026-07-13 · tipo texto_livre grava o texto em
                    // `comentario` e deixa option_label/peso NULL. Não entra
                    // em AVG de score (peso NULL não conta em AVG do MySQL).
                    if ($question->tipo === \App\Models\NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                        NpsResponseAnswer::create([
                            'response_id'                => $response->id,
                            'template_question_id'       => $question->id,
                            'template_option_id'         => null,
                            'question_texto_snapshot'    => $question->texto,
                            'question_dimensao_snapshot' => $question->dimensao,
                            'option_label_snapshot'      => null,
                            'option_peso_snapshot'       => null,
                            'comentario'                 => (string) $answerValue,
                        ]);
                        continue;
                    }

                    $option = $optionsByQuestion[$qid][$answerValue] ?? null;
                    if (!$option) {
                        continue;
                    }

                    NpsResponseAnswer::create([
                        'response_id'                => $response->id,
                        'template_question_id'       => $question->id,
                        'template_option_id'         => $option->id,
                        'question_texto_snapshot'    => $question->texto,
                        'question_dimensao_snapshot' => $question->dimensao,
                        'option_label_snapshot'      => $option->label,
                        'option_peso_snapshot'       => $option->peso,
                        'comentario'                 => null,
                    ]);
                }

                // Phase 79 v16.0 (DEC-79-D): congela o SNAPSHOT imutável — médias
                // por dimensão + serviços cobertos + atribuições por serviço. DEVE
                // rodar AQUI: depois do foreach das answers (senão o calculator leria
                // zero — Pitfall 3) e DENTRO desta transação (para reverter junto se
                // o dedup 23000 estourar no update abaixo). O service NÃO abre
                // transação própria. Bônus/legacy intactos (DEC-79-E).
                app(\App\Services\Nps\NpsSnapshotService::class)->registrar($response);

                // Phase 94 AB-94-3 — evento 'submitted' DENTRO da transação:
                // se o guard 23000 abaixo estourar, reverte junto (Pitfall 3).
                $this->registrarEventoSubmitted($request, $survey, $response);

                // Marca survey como completed — pode disparar 23000 aqui pelo
                // partial unique index de dedup mensal (Plan 68-04).
                $survey->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                // Phase 68 Plan 04: dedup unique parcial
                // (company_id, month_reference, template_id) bloqueou 2a
                // completacao no mesmo mes com o mesmo template. UX: renderiza
                // a mesma tela ja usada pelo GET quando survey.status=completed.
                return Inertia::render('Nps/AlreadyCompleted');
            }
            throw $e;
        }

        return Inertia::render('Nps/ThankYou');
    }

    /**
     * Fluxo legacy Phase 31/33 preservado 100% — usado quando o survey nao
     * tem template_id (rows criadas antes da Phase 69 seed retro do Plan 68-03,
     * ou fluxos que ainda nao migraram). Coexiste ate Phase 73.
     *
     * Comportamento identico ao NpsController::submitResponse pre-Phase 69:
     *   - Rules hardcoded: score_estrategista/analista/empresa 1..5
     *   - Perguntas customizadas Phase 33 (NpsPerguntaCustomizada) ativas
     *     geram rules dinamicas em respostas_extras.<pid> por tipo
     *   - Persiste NpsResponse com scores legados + NpsRespostaCustomizada
     *     por pergunta extra respondida
     */
    private function submitResponseLegacy(Request $request, NpsSurvey $survey)
    {
        // ─── Phase 33 D-07 — validacao dinamica de perguntas customizadas ────
        // Carrega TODAS as perguntas ativas no momento da submissao para montar
        // as rules dinamicamente conforme tipo (D-02). Perguntas inativas nao
        // sao validadas (mesmo que enviadas) — apenas as ativas atuais contam.
        $perguntas = NpsPerguntaCustomizada::where('ativa', true)->get()->keyBy('id');

        $rules = [
            'respondent_name'    => 'nullable|string|max:255',
            'score_estrategista' => 'required|integer|min:1|max:5',
            'score_analista'     => 'nullable|integer|min:1|max:5',
            'score_empresa'      => 'required|integer|min:1|max:5',
            'comment'            => 'nullable|string|max:2000',
            'respostas_extras'   => 'nullable|array',
        ];

        foreach ($perguntas as $p) {
            $base = "respostas_extras.{$p->id}";
            $req  = $p->obrigatorio ? 'required' : 'nullable';

            switch ($p->tipo) {
                case 'escala_1_5':
                    $rules[$base] = "{$req}|integer|min:1|max:5";
                    break;

                case 'texto':
                    $rules[$base] = "{$req}|string|max:2000";
                    break;

                case 'sim_nao':
                    $rules[$base] = [$req, Rule::in(['sim', 'nao'])];
                    break;

                case 'multipla':
                    $rules[$base] = [$req, Rule::in($p->opcoes ?? [])];
                    break;
            }
        }

        $validated = $request->validate($rules);

        // Atomicidade: NpsResponse + N respostas customizadas dentro da mesma
        // transacao. Se qualquer insert falhar, a survey nao e marcada completa.
        DB::transaction(function () use ($request, $survey, $validated, $perguntas) {
            // Phase 94 AB-94-2/AB-94-4 — MESMO helper compartilhado do path v15
            // (Pitfall 2 do RESEARCH: nunca duplicar a lógica entre os 2 paths).
            $response = NpsResponse::create([
                'survey_id'          => $survey->id,
                'respondent_name'    => $validated['respondent_name'] ?? null,
                'score_estrategista' => $validated['score_estrategista'],
                'score_analista'     => $validated['score_analista'] ?? null,
                'score_empresa'      => $validated['score_empresa'],
                'comment'            => $validated['comment'] ?? null,
                ...$this->capturarRastroEAvaliarSuspeita($request, $survey),
            ]);

            // Persiste 1 NpsRespostaCustomizada por pergunta respondida, com
            // snapshot do texto/tipo da pergunta no momento da resposta.
            foreach (($validated['respostas_extras'] ?? []) as $perguntaId => $valor) {
                // Pula respostas vazias de perguntas opcionais.
                if ($valor === null || $valor === '') {
                    continue;
                }

                // Defensivo: ignora ids que nao casam com perguntas ativas
                // (poderiam vir de cliente desatualizado / tampering).
                $p = $perguntas[$perguntaId] ?? null;
                if (!$p) {
                    continue;
                }

                NpsRespostaCustomizada::create([
                    'response_id'             => $response->id,
                    'pergunta_id'             => $p->id,
                    'pergunta_texto_snapshot' => $p->texto,
                    'tipo_snapshot'           => $p->tipo,
                    'valor'                   => (string) $valor,
                ]);
            }

            // Phase 94 AB-94-3 — evento 'submitted' dentro da mesma transação
            // (o legado não tem guard 23000, mas mantém o mesmo posicionamento
            // por consistência com o path v15).
            $this->registrarEventoSubmitted($request, $survey, $response);

            $survey->update(['status' => 'completed', 'completed_at' => now()]);
        });

        return Inertia::render('Nps/ThankYou');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Customização de textos (Phase 32, Plan 02) — admin only via middleware
    // role:admin nas rotas. Toda a UI de edição vive em /nps/configuracao.
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Página admin de customização dos 5 textos do email NPS mensal.
     *
     * v15.5 (2026-07-08) — esta página passou a ser EXCLUSIVA de customização
     * do email. Toda a UI de edição do formulário NPS (perguntas fixas +
     * perguntas extras) foi removida daqui — o admin agora usa a nova UI
     * multi-template em `/nps/configuracao` para editar as perguntas.
     *
     * Carrega os 5 textos atuais + os defaults + a doc dos placeholders para
     * exibir no painel lateral. Endpoints irmãos: `atualizarConfiguracao`
     * (PUT) salva e `previewEmail` (POST) renderiza o template Blade.
     */
    public function configuracao()
    {
        // Documentação dos placeholders aceitos pelos textos do email.
        // `nome_analista` fica visível no corpo via `bloco_analista` (que vira
        // string vazia em mentoria pura). Mês/empresa/estrategista funcionam
        // em qualquer campo do email.
        $placeholdersDoc = [
            ['chave' => '{nome_estrategista}', 'descricao' => 'Nome do estrategista da empresa.'],
            ['chave' => '{nome_analista}',     'descricao' => 'Nome do analista (omitido quando mentoria pura).'],
            ['chave' => '{nome_empresa}',      'descricao' => 'Nome da empresa que está respondendo.'],
            ['chave' => '{mes_referencia}',    'descricao' => 'Mês AVALIADO pela pesquisa, em pt-BR — é o mês ANTERIOR ao do disparo (a pesquisa que sai em agosto pergunta sobre "julho/2026").'],
            ['chave' => '{bloco_analista}',    'descricao' => 'Bloco condicional " e o analista é **Igor**" no corpo do email (usar apenas em email_corpo); vira string vazia em mentoria pura.'],
        ];

        // Só os 5 textos do email chegam ao JSX — o resto do defaults()
        // (perg_*) segue existindo em NpsTextRenderer pra retro-compat do
        // RespondLegado.jsx (surveys legacy sem template_id).
        $textosCompletos = NpsTextRenderer::getTextos();
        $defaultsCompletos = NpsTextRenderer::defaults();

        $chavesEmail = ['email_assunto', 'email_saudacao', 'email_corpo', 'email_cta', 'email_assinatura'];

        return Inertia::render('Nps/ConfiguracaoLegado', [
            'textos'           => collect($textosCompletos)->only($chavesEmail)->toArray(),
            'defaults'         => collect($defaultsCompletos)->only($chavesEmail)->toArray(),
            'placeholders_doc' => $placeholdersDoc,
        ]);
    }

    /**
     * PUT /nps/configuracao — persiste os 5 textos do email em
     * `configuracoes.nps_textos` como JSON.
     *
     * v15.5 (2026-07-08) — só valida os 5 campos do email. Os campos legados
     * `perg_*` que ainda podem existir no JSON são PRESERVADOS via merge
     * (defesa para o RespondLegado.jsx que ainda serve surveys sem template_id).
     * Não há restrição de placeholders — o NpsTextRenderer aplica str_replace
     * silencioso, placeholders desconhecidos ficam no texto sem erro.
     */
    public function atualizarConfiguracao(Request $request)
    {
        $validated = $request->validate([
            'email_assunto'    => 'required|string|max:5000',
            'email_saudacao'   => 'required|string|max:5000',
            'email_corpo'      => 'required|string|max:5000',
            'email_cta'        => 'required|string|max:5000',
            'email_assinatura' => 'required|string|max:5000',
        ]);

        // Merge com o JSON existente — preserva `perg_*` legados usados pelo
        // RespondLegado.jsx quando a survey vem sem template_id (raro pós-Phase 71,
        // mas mantém back-compat até Phase 73 limpar de vez).
        $atual = NpsTextRenderer::getTextos();
        $novo  = array_merge($atual, $validated);

        Configuracao::set('nps_textos', json_encode($novo, JSON_UNESCAPED_UNICODE));

        return back()->with('success', 'Textos do email NPS atualizados.');
    }

    /**
     * PATCH /nps/configuracao/dia-cobranca — persiste config global "dia de
     * cobrança mensal" (Phase 72 Plan 01, NPS-E-01).
     *
     * Aceita int 1..31; qualquer valor fora do range é rejeitado com 422 com
     * mensagem pt-BR. Persistido em Configuracao::set('nps_dia_cobranca', ...)
     * como string (padrão do key-value store). O NpsPendingService::diaCobranca
     * cast de volta para int + clamp defensivo 1..31.
     *
     * Consumido pelo widget DiaCobrancaWidget em Nps/Configuracao.jsx
     * (Phase 72 Plan 01 T3).
     */
    public function atualizarDiaCobranca(Request $request)
    {
        $validated = $request->validate([
            'dia' => 'required|integer|min:1|max:31',
        ], [
            'dia.required' => 'Informe o dia de cobrança.',
            'dia.integer'  => 'O dia deve ser um número inteiro.',
            'dia.min'      => 'O dia deve ser entre 1 e 31.',
            'dia.max'      => 'O dia deve ser entre 1 e 31.',
        ]);

        // Persiste como string (padrão do key-value store Configuracao);
        // NpsPendingService::diaCobranca faz cast + clamp na leitura.
        Configuracao::set('nps_dia_cobranca', (string) $validated['dia']);

        return back()->with('success', "Dia de cobrança do NPS atualizado para {$validated['dia']}.");
    }

    /**
     * PATCH /nps/configuracao/ips-internos — persiste a lista de IPs/CIDRs
     * internos da ECF configurável pela UI (Phase 96 Plan 02, AB-96-2).
     *
     * O `.env` (ECF_INTERNAL_IPS/ECF_INTERNAL_CIDRS, lido em config/nps.php)
     * continua valendo como fallback — a lista efetiva usada por
     * `NpsSuspicionService::isInternalIp()` é a UNIÃO (.env ∪ UI), nunca
     * substituição. Persistido em Configuracao (mesmo padrão key/valor de
     * nps_dia_cobranca/nps_textos) como JSON array de strings.
     *
     * abort_unless é defesa em profundidade — a rota já vive no grupo
     * `role:admin` (routes/web.php), mas o guard explícito documenta a
     * intenção e protege contra reordenação futura do middleware.
     *
     * Consumido pelo widget IpsInternosWidget em Nps/Configuracao.jsx
     * (Phase 96 Plan 02 Task 2).
     */
    public function atualizarIpsInternos(Request $request)
    {
        abort_unless((bool) $request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'ips'     => 'nullable|array',
            'ips.*'   => ['string', function (string $attribute, mixed $value, \Closure $fail) {
                if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                    $fail('Informe um IP válido (ex.: 203.0.113.5).');
                }
            }],
            'cidrs'   => 'nullable|array',
            'cidrs.*' => ['string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
        ], [
            'cidrs.*.regex' => 'Informe um CIDR válido (ex.: 10.0.0.0/8).',
        ]);

        Configuracao::set(
            'nps_internal_ips',
            json_encode(array_values($validated['ips'] ?? []), JSON_UNESCAPED_UNICODE)
        );
        Configuracao::set(
            'nps_internal_cidrs',
            json_encode(array_values($validated['cidrs'] ?? []), JSON_UNESCAPED_UNICODE)
        );

        return back()->with('success', 'IPs internos atualizados.');
    }

    /**
     * POST /nps/configuracao/preview — renderiza o template Blade do email
     * com os textos NÃO PERSISTIDOS vindos do form (permite preview sem salvar).
     *
     * Usa as mesmas vars de exemplo do CONTEXT D-05 para preservar consistência
     * com o que o admin verá ao receber o disparo real. O HTML retornado é
     * injetado num <iframe srcdoc> no frontend para isolar o CSS do email do
     * Tailwind do app.
     */
    public function previewEmail(Request $request)
    {
        // Valida só o que vai ser usado pra renderizar — mesma whitelist do PUT,
        // mas tudo opcional (preview funciona mesmo com campos vazios).
        $textos = $request->validate([
            'email_assunto'    => 'nullable|string|max:5000',
            'email_saudacao'   => 'nullable|string|max:5000',
            'email_corpo'      => 'nullable|string|max:5000',
            'email_cta'        => 'nullable|string|max:5000',
            'email_assinatura' => 'nullable|string|max:5000',
        ]);

        // Vars de exemplo fixas (D-05) — combinam com o tom usado no email real.
        // mes_referencia em minúsculo para casar com o default "satisfação ECF — junho/2026"
        // (decisão herdada do Plan 32-01).
        $varsExemplo = [
            'nome_estrategista' => 'Nathália',
            'nome_analista'     => 'Igor',
            'nome_empresa'      => 'Empresa Exemplo Ltda',
            'mes_referencia'    => 'junho/2026',
            'bloco_analista'    => ' e o analista é **Igor**',
        ];

        // Renderiza cada texto com os placeholders substituídos.
        // - render() para campos texto-puro (assunto, CTA)
        // - renderHtml() para campos que vão como HTML no template (saudação, corpo, assinatura)
        $assuntoRender    = NpsTextRenderer::render($textos['email_assunto']    ?? '', $varsExemplo);
        $saudacaoRender   = NpsTextRenderer::renderHtml($textos['email_saudacao']   ?? '', $varsExemplo);
        $corpoRender      = NpsTextRenderer::renderHtml($textos['email_corpo']      ?? '', $varsExemplo);
        $ctaRender        = NpsTextRenderer::render($textos['email_cta']        ?? '', $varsExemplo);
        $assinaturaRender = NpsTextRenderer::renderHtml($textos['email_assinatura'] ?? '', $varsExemplo);

        // URL falsa (não persistida) — só pra o botão CTA do preview ter um href.
        $linkPesquisa = url('/nps/preview-token-exemplo');

        $html = view('emails.nps.mensal', [
            'saudacaoRender'   => $saudacaoRender,
            'corpoRender'      => $corpoRender,
            'ctaRender'        => $ctaRender,
            'assinaturaRender' => $assinaturaRender,
            'linkPesquisa'     => $linkPesquisa,
            'mesReferencia'    => $varsExemplo['mes_referencia'],
        ])->render();

        return response()->json([
            'html'    => $html,
            'assunto' => $assuntoRender,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Página de envios (Phase 32, Plan 04) — admin only via middleware role:admin.
    // Lista paginada dos disparos do comando `nps:disparar-mensal` (Plan 32-01
    // grava `NpsEmailEnvio` por empresa elegível em cada execução).
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Página admin /nps/emails-enviados — lista paginada de envios NPS.
     *
     * Filtros (D-06 LOCKED — mínimos):
     *  - ?mes=YYYY-MM   (default = mês corrente; formato inválido → mês atual)
     *  - ?q=texto       (busca em company.name OR destinatario via LIKE)
     *
     * Paginação 25/pg + preserva query string (?mes e ?q) entre páginas.
     *
     * O front mostra status como badge (verde "Enviado" / vermelho "Falha"),
     * link pro survey gerado (`/nps/{token}`) em status=enviado e expande o
     * `erro_msg` quando status=falha.
     */
    /**
     * Quick task 260612-flt — DELETE /nps/{survey}/response.
     *
     * Admin exclusivo (route middleware role:admin). Apaga a resposta do cliente
     * (NpsResponse) e reverte o survey para `pending` — cliente pode responder
     * de novo se ainda estiver dentro do prazo. cascade onDelete em
     * nps_respostas_customizadas apaga as respostas extras automaticamente.
     */
    public function excluirResposta(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (!$survey->response) {
            return back()->with('error', 'Esta pesquisa ainda não foi respondida.');
        }

        $survey->response->delete();  // cascade apaga respostas_customizadas
        $survey->update(['status' => 'pending', 'completed_at' => null]);

        return back()->with('success', 'Resposta excluída. A pesquisa voltou para pendente.');
    }

    /**
     * Phase 96 Plan 03 (AB-96-3) — admin invalida uma resposta suspeita SEM
     * apagar nada. Diferente de `excluirResposta()` acima: NÃO reverte o
     * survey para pending (evita ambiguidade no `hasOne` — 96-RESEARCH
     * Pitfall 2) e NÃO toca em `nps_response_scores`/`nps_score_assignments`
     * (o congelamento da Fase 79/DEC-79-C é preservado, reversível via
     * `revalidarResposta()`).
     *
     * `motivo` é texto livre opcional, gravado só na trilha de auditoria
     * (`activity_log`), nunca em `nps_responses` — `NpsResponse` não recebe
     * `LogsActivity` para não poluir a auditoria com o `created` de toda
     * resposta legítima (96-RESEARCH: trilha via activity() explícito).
     */
    public function invalidarResposta(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (!$survey->response) {
            return back()->with('error', 'Esta pesquisa ainda não foi respondida.');
        }
        if ($survey->response->invalidated_at) {
            return back()->with('error', 'Esta resposta já está invalidada.');
        }

        $validated = $request->validate([
            'motivo' => 'nullable|string|max:500',
        ]);

        $survey->response->update([
            'invalidated_at' => now(),
            'invalidated_by' => $request->user()->id,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($survey->response)
            ->withProperties([
                'survey_id'  => $survey->id,
                'company_id' => $survey->company_id,
                'motivo'     => $validated['motivo'] ?? null,
            ])
            ->log('Resposta NPS invalidada');

        $this->bustarCacheDoBonus($survey->response, $survey);

        return back()->with('success', 'Resposta invalidada — não conta mais em dashboards nem no bônus.');
    }

    /**
     * Phase 96 Plan 03 (AB-96-3) — reverte a invalidação (`invalidated_at =
     * null`). Simétrico a `invalidarResposta()`: mesma trilha de auditoria,
     * mesmo cache-busting (o bônus precisa refletir a resposta voltando a
     * contar). Nunca re-roda o `NpsSnapshotService` — o snapshot congelado
     * já existe intacto desde o submit original.
     */
    public function revalidarResposta(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (!$survey->response || !$survey->response->invalidated_at) {
            return back()->with('error', 'Esta resposta não está invalidada.');
        }

        $survey->response->update(['invalidated_at' => null, 'invalidated_by' => null]);

        activity()
            ->causedBy($request->user())
            ->performedOn($survey->response)
            ->withProperties([
                'survey_id'  => $survey->id,
                'company_id' => $survey->company_id,
            ])
            ->log('Resposta NPS revalidada');

        $this->bustarCacheDoBonus($survey->response, $survey);

        return back()->with('success', 'Resposta revalidada — volta a contar normalmente.');
    }

    /**
     * Phase 96 Plan 03 (AB-96-3) — achado crítico do RESEARCH: o bônus de um
     * mês FECHADO fica cacheado por até 7 dias (`DesempenhoScoreService::
     * computeCached()`). Sem este `Cache::forget()` explícito, invalidar (ou
     * revalidar) uma resposta pareceria "não ter feito nada" no /performance
     * por até uma semana. Fase 102 (BON-04/T-102-05): a chave passou a ter
     * `period_key` embutido (operacional×oficial não colidem mais) — a
     * montagem NUNCA é feita à mão aqui, sempre via
     * `DesempenhoScoreService::cacheKey()` (helper público único), pra este
     * método continuar apagando a chave certa sem precisar saber o formato
     * exato nem o dígito de versão atual.
     *
     * Respostas LEGADAS (sem `template_id`/sem `NpsScoreAssignment`) não têm
     * cache de bônus a bustar — `NpsSnapshotService::registrar()` retorna
     * cedo para elas (Pitfall 5 do RESEARCH) — isso é CORRETO, não um bug:
     * o loop simplesmente não encontra nenhum user_id e não faz nada.
     *
     * Fase 105 · v18.0 (NPSWIN-03) — a competência bustada é `$mesCompletado`
     * MENOS 1 MÊS, não `$mesCompletado`. Desde a 105-01, `DesempenhoScoreService
     * ::computeNpsWindow()` desloca a leitura do NPS +1 mês: a competência M
     * (fechada) lê o NPS coletado em M+1. Logo uma resposta com
     * `completed_at` em X (=M+1 de alguma competência) alimenta o bônus da
     * competência X−1, não o de X. Bustar a chave de X faria `Cache::forget`
     * numa chave que ninguém lê — o `computeCached()` de X−1 continuaria
     * servindo o valor stale por até 7 dias. `subMonthNoOverflow()` evita
     * overflow de dia-do-mês (ex.: 31/03 − 1 mês não deve virar 03/04).
     */
    private function bustarCacheDoBonus(NpsResponse $response, NpsSurvey $survey): void
    {
        $mesCompletado = $survey->completed_at?->copy()->startOfMonth();
        if (!$mesCompletado) {
            return;
        }

        $mesCompetencia = $mesCompletado->copy()->subMonthNoOverflow()->startOfMonth();

        $userIds = \App\Models\NpsScoreAssignment::where('nps_response_id', $response->id)
            ->pluck('user_id')
            ->unique();

        $scoreService = app(\App\Services\DesempenhoScoreService::class);

        foreach ($userIds as $userId) {
            \Illuminate\Support\Facades\Cache::forget(
                $scoreService->cacheKey($userId, $mesCompetencia)
            );
        }
    }

    /**
     * 2026-07-13 — DELETE /nps/{survey}. Admin exclusivo (route middleware
     * role:admin + guard redundante). Apaga a pesquisa NPS inteira, de
     * QUALQUER status (inclusive `pending`, que a UI antiga não deixava
     * excluir). O cascade de FK no banco apaga em sequência:
     *   nps_responses → nps_respostas_customizadas / nps_response_answers
     *   nps_email_envios (survey_id cascadeOnDelete)
     * Usa $survey->delete() (não mass-delete) para disparar o activitylog
     * ('NPS excluído') e os cascades a nível de banco.
     */
    public function destroy(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $nome = $survey->company?->name ?? 'empresa';
        $survey->delete();

        return back()->with('success', "Pesquisa NPS de \"{$nome}\" excluída.");
    }

    /**
     * 2026-07-13 — DELETE /nps/surveys/bulk. Exclusão em massa a partir dos
     * checkboxes da listagem. Admin exclusivo. Itera com get()->each->delete()
     * (em vez de whereIn->delete()) para preservar activitylog + cascades por
     * row. Volumes esperados são baixos (página da listagem), então o custo é
     * aceitável.
     */
    public function bulkDestroy(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $surveys = NpsSurvey::whereIn('id', $validated['ids'])->get();
        $count   = $surveys->count();
        $surveys->each->delete();

        return back()->with('success', $count === 1
            ? '1 pesquisa NPS excluída.'
            : "{$count} pesquisas NPS excluídas.");
    }

    public function emailsEnviados(Request $request)
    {
        // ─── Validação leve do parâmetro mes ─────────────────────────────────
        // Formato esperado YYYY-MM; qualquer coisa fora disso cai no mês atual.
        $mes = $request->input('mes') ?: now()->format('Y-m');
        try {
            $inicio = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();
        } catch (\Exception $e) {
            $mes    = now()->format('Y-m');
            $inicio = now()->startOfMonth();
        }
        $fim = $inicio->copy()->endOfMonth();

        // ─── Query base com eager loading dos relacionamentos exibidos ──────
        // Campos enxutos pra reduzir payload Inertia (id+name da empresa, token
        // pro botão "Ver", status/company_id pro guard do botão).
        $query = NpsEmailEnvio::with([
                'company:id,name',
                'survey:id,token,status,company_id',
            ])
            ->whereBetween('created_at', [$inicio, $fim])
            ->orderByDesc('created_at');

        // Filtro de busca: nome da empresa OU destinatário. Usa `whereHas` no
        // relacionamento company para LIKE no nome, sem JOIN explícito.
        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($w) use ($q) {
                $w->whereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                  ->orWhere('destinatario', 'like', "%{$q}%");
            });
        }

        $envios = $query->paginate(25)->withQueryString();

        // Total do mês (ignora filtro de busca quando contamos o "total do mês"
        // no header — usuário quer saber quantos envios houve no mês selecionado,
        // não apenas os que casam com a busca atual).
        $totalMes = NpsEmailEnvio::whereBetween('created_at', [$inicio, $fim])->count();

        // ─── Últimos 12 meses para popular o Select de filtro ───────────────
        // Formato pt-BR ("Jun/26"). `translatedFormat` usa o locale do app
        // (config/app.php => 'locale' => 'pt_BR' nesta base).
        $mesesDisponiveis = collect(range(0, 11))->map(fn($i) => [
            'value' => now()->subMonths($i)->format('Y-m'),
            'label' => now()->subMonths($i)->translatedFormat('M/y'),
        ])->values();

        return Inertia::render('Nps/EmailsEnviados', [
            'envios'            => $envios,
            'mes'               => $mes,
            'q'                 => $request->input('q', ''),
            'meses_disponiveis' => $mesesDisponiveis,
            'total_mes'         => $totalMes,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Perguntas customizadas (Phase 33, Plan 33-01) — admin only via middleware
    // role:admin nas rotas. Toda a UI vive na 3a tab de /nps/configuracao
    // ("Perguntas extras") implementada no Plan 33-02.
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * POST /nps/configuracao/perguntas — cria nova pergunta extra (D-06).
     *
     * Ordem inicial = max(ordem) + 1 → pergunta nova vai pro final da lista
     * automaticamente. Admin pode reordenar depois via moverPerguntaExtra().
     *
     * `opcoes` so tem semantica quando tipo=multipla; em outros tipos for_a-se null
     * mesmo se o cliente enviar algo (defesa contra payload sujo).
     */
    public function criarPerguntaExtra(Request $request)
    {
        // Quick fix 260612: o frontend manda `opcoes: []` mesmo quando tipo!=multipla
        // (defaults do useForm). Sem este merge, `min:2` falhava validacao em todos
        // os tipos que nao usam opcoes — request voltava com errors silenciosos e
        // a UI nao mostrava nada acontecendo.
        if ($request->input('tipo') !== 'multipla') {
            $request->merge(['opcoes' => null]);
        }

        $validated = $request->validate([
            'texto'       => 'required|string|max:500',
            'tipo'        => ['required', Rule::in(NpsPerguntaCustomizada::TIPOS)],
            'opcoes'      => ['nullable', 'array', 'required_if:tipo,multipla', 'min:2'],
            'opcoes.*'    => 'string|max:255',
            'obrigatorio' => 'boolean',
            'ativa'       => 'boolean',
        ]);

        // Forca opcoes=null quando tipo nao e multipla (defesa redundante apos o merge).
        $validated['opcoes'] = $validated['tipo'] === 'multipla'
            ? ($validated['opcoes'] ?? null)
            : null;

        // Posiciona a nova pergunta no final da lista — admin reordena depois.
        $validated['ordem'] = (NpsPerguntaCustomizada::max('ordem') ?? 0) + 1;

        NpsPerguntaCustomizada::create($validated);

        return back()->with('success', 'Pergunta extra criada.');
    }

    /**
     * PUT /nps/configuracao/perguntas/{pergunta} — edita pergunta existente.
     *
     * Todos os campos sao `sometimes` — admin pode mandar parcial. Se mandou
     * `tipo` mas o tipo novo nao e `multipla`, zera opcoes; se tipo continua
     * `multipla` mas nao mandou opcoes, preserva as atuais.
     */
    public function atualizarPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        // Quick fix 260612: idem criarPerguntaExtra — neutraliza opcoes=[] quando
        // tipo efetivo nao e multipla, pra nao tropecar no min:2.
        $tipoEfetivoAntes = $request->input('tipo', $pergunta->tipo);
        if ($tipoEfetivoAntes !== 'multipla' && $request->has('opcoes')) {
            $request->merge(['opcoes' => null]);
        }

        $validated = $request->validate([
            'texto'       => 'sometimes|required|string|max:500',
            'tipo'        => ['sometimes', 'required', Rule::in(NpsPerguntaCustomizada::TIPOS)],
            'opcoes'      => ['sometimes', 'nullable', 'array', 'min:2'],
            'opcoes.*'    => 'string|max:255',
            'obrigatorio' => 'sometimes|boolean',
            'ativa'       => 'sometimes|boolean',
        ]);

        // Calcula tipo efetivo (novo ou atual) para decidir o que fazer com opcoes.
        $tipoEfetivo = $validated['tipo'] ?? $pergunta->tipo;
        if ($tipoEfetivo !== 'multipla') {
            $validated['opcoes'] = null;
        }

        $pergunta->update($validated);

        return back()->with('success', 'Pergunta atualizada.');
    }

    /**
     * DELETE /nps/configuracao/perguntas/{pergunta} — exclui ou desativa.
     *
     * Comportamento padrao (D-06):
     *  - Se a pergunta TEM respostas associadas e a query string `?force=1` NAO
     *    foi enviada, faz SOFT delete (ativa=false) — preserva historico.
     *  - Se `?force=1` OU a pergunta nao tem respostas, HARD delete. As respostas
     *    historicas perdem o vinculo (FK set null), mas o snapshot do texto/tipo
     *    sustenta o display no modal de detalhes.
     */
    public function excluirPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        $temRespostas = NpsRespostaCustomizada::where('pergunta_id', $pergunta->id)->exists();

        if ($temRespostas && !$request->boolean('force')) {
            $pergunta->update(['ativa' => false]);

            return back()->with(
                'success',
                'Pergunta desativada (tem respostas associadas — use ?force=1 para deletar definitivamente).'
            );
        }

        $pergunta->delete();

        return back()->with('success', 'Pergunta removida.');
    }

    /**
     * POST /nps/configuracao/perguntas/{pergunta}/mover — troca ordem com vizinha.
     *
     * Estrategia: encontra a vizinha mais proxima na direcao desejada e SWAP de
     * valores de `ordem`. Se ja e a primeira (up) ou ultima (down), no-op.
     *
     * Vantagens vs. "shift all rows": O(1) updates, sem race condition complexa
     * em casos simples. Como nao temos concorrencia real (admin sozinho na UI),
     * suficiente.
     */
    public function moverPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        $direcao = $request->validate([
            'direcao' => 'required|in:up,down',
        ])['direcao'];

        if ($direcao === 'up') {
            // Vizinha = maior ordem que ainda e menor que a minha (ASC desc).
            $vizinha = NpsPerguntaCustomizada::where('ordem', '<', $pergunta->ordem)
                ->orderByDesc('ordem')
                ->orderByDesc('id')
                ->first();
        } else {
            // Vizinha = menor ordem que e maior que a minha.
            $vizinha = NpsPerguntaCustomizada::where('ordem', '>', $pergunta->ordem)
                ->orderBy('ordem')
                ->orderBy('id')
                ->first();
        }

        // No-op se ja esta no extremo (primeira/ultima da lista).
        if (!$vizinha) {
            return back();
        }

        // Swap atomico das ordens entre as duas perguntas.
        DB::transaction(function () use ($pergunta, $vizinha) {
            $ordemTmp = $pergunta->ordem;
            $pergunta->update(['ordem' => $vizinha->ordem]);
            $vizinha->update(['ordem' => $ordemTmp]);
        });

        return back();
    }
}
