<?php

namespace App\Http\Controllers;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\TemplatePasso;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * OnboardingController — painel operacional do onboarding geral por serviço
 * (Fase 135, Plano 09). Responde "o que está travando, há quantos dias e de
 * quem é a bola" (SC-11) — nunca `feitos/total` — e expõe as duas ações que
 * a Coordenação precisa: confirmar responsável (liga o SLA, D-05/SC-04) e
 * concluir manualmente um passo (nunca um passo com `auto_fonte`, D-19).
 *
 * Gate: `permission:core.onboarding` na rota (`routes/web.php`), distinto do
 * `role:admin` do CRUD de template (D-04) — admin passa por short-circuit em
 * `User::hasPermission()`.
 *
 * Nenhuma chamada de rede aqui: todo o cálculo é sobre dados já persistidos
 * (T-135-09-06); reavaliar() do engine só toca o banco local.
 */
class OnboardingController extends Controller
{
    /** Chave (denormalizada, D-10) do único passo administrativo do template de Gestão (D-15). */
    private const CHAVE_PASSO_ADMINISTRATIVO = 'confirmacao_pagamento';

    /**
     * Rótulo pt-BR para cada um dos 6 valores de `situacao` (SC-11 — nenhum
     * deles é porcentagem). Índice pela string devolvida por
     * {@see self::situacaoDe()}.
     */
    private const SITUACAO_LABELS = [
        'rascunho'             => 'Rascunho — aguardando responsável',
        'vencido'              => 'Vencido',
        'aguardando_cliente'   => 'Aguardando cliente',
        'aguardando_interno'   => 'Aguardando interno',
        'aguardando_sistema'   => 'Aguardando sistema',
        'coletando'            => 'Coletando dados',
        'pronto_para_concluir' => 'Pronto para concluir',
        'concluido'            => 'Concluído',
    ];

    public function __construct(private OnboardingEngineService $engine)
    {
    }

    /**
     * GET /onboarding — lista agregada por empresa (D-01 — uma empresa pode
     * ter mais de um serviço com onboarding). Escopo: admin vê tudo;
     * não-admin vê só as empresas da própria carteira (`company_users`),
     * mesmo espírito de `SugadorController`/`DashboardController::userDashboard()`.
     *
     * Carregamento único com `with([...])` — nenhuma consulta dentro de laço
     * (T-135-09-06); a situação de cada onboarding é calculada em memória a
     * partir dos passos já carregados.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Onboarding::query()->with([
            'company:id,name',
            'servico:id,nome',
            'responsavel:id,name',
            'template:id,versao',
            'passos.templatePasso.setor:id,nome',
        ]);

        if (! $user->isAdmin()) {
            $query->whereIn('company_id', $user->companies()->pluck('companies.id'));
        }

        $empresas = $query->get()
            ->groupBy('company_id')
            ->map(function (Collection $onboardingsDaEmpresa) {
                $empresa = $onboardingsDaEmpresa->first()->company;

                return [
                    'empresa' => [
                        'id'   => $empresa->id,
                        'nome' => $empresa->name,
                    ],
                    'onboardings' => $onboardingsDaEmpresa
                        ->map(fn (Onboarding $onboarding) => $this->resumoOnboarding($onboarding))
                        ->values(),
                ];
            })
            ->sortBy('empresa.nome')
            ->values();

        return Inertia::render('Onboarding/Painel', [
            'empresas' => $empresas,
        ]);
    }

    /**
     * GET /onboarding/{onboarding} — detalhe com os passos do template:
     * título, dono, setor, estado, dias parado (contados de `disponivel_em`,
     * D-11), SLA, dependências resolvidas para títulos legíveis, condição
     * traduzida pra pt-BR (nunca a expressão crua) e o selo de automação.
     *
     * Nenhum campo de porcentagem (SC-11).
     */
    public function show(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $onboarding->load([
            'company:id,name',
            'servico:id,nome',
            'responsavel:id,name',
            'template:id,versao',
            'passos.templatePasso.setor:id,nome',
        ]);

        $passos = $onboarding->passos;
        $titulosPorChave = $passos->mapWithKeys(
            fn (OnboardingPasso $p) => [$p->chave => $p->templatePasso->titulo]
        );

        $situacao = $this->situacaoDe($onboarding, $passos);

        $passosOrdenados = $passos
            ->sortBy(fn (OnboardingPasso $p) => $p->templatePasso->ordem)
            ->values()
            ->map(fn (OnboardingPasso $p) => $this->detalhePasso($p, $titulosPorChave));

        return Inertia::render('Onboarding/Detalhe', [
            'onboarding' => [
                'id'              => $onboarding->id,
                'empresa'         => ['id' => $onboarding->company->id, 'nome' => $onboarding->company->name],
                'servico'         => ['id' => $onboarding->servico->id, 'nome' => $onboarding->servico->nome],
                'status'          => $onboarding->status,
                'situacao'        => $situacao,
                'situacao_label'  => self::SITUACAO_LABELS[$situacao],
                'responsavel'     => $onboarding->responsavel ? [
                    'id'   => $onboarding->responsavel->id,
                    'name' => $onboarding->responsavel->name,
                ] : null,
                'template_versao' => $onboarding->template?->versao,
            ],
            'passos' => $passosOrdenados,
        ]);
    }

    /**
     * POST /onboarding/{onboarding}/responsavel — confirma o responsável e
     * transiciona rascunho→andamento (D-05/SC-04).
     */
    public function confirmarResponsavel(Request $request, Onboarding $onboarding)
    {
        return back();
    }

    /**
     * POST /onboarding/passos/{passo}/concluir — conclui manualmente um
     * passo (nunca um passo com `auto_fonte`, D-19).
     */
    public function concluirPasso(Request $request, OnboardingPasso $passo)
    {
        return back();
    }

    // ─── Escopo de leitura/escrita (T-135-09-02) ─────────────────────────────

    /**
     * Admin vê/age sobre qualquer onboarding. Não-admin só sobre onboardings
     * de empresas da própria carteira (`company_users`) — mesmo recorte do
     * `index()`, aplicado aqui pra `show()`/`confirmarResponsavel()`/
     * `concluirPasso()`, que chegam por id direto (sem passar pelo filtro da
     * listagem).
     */
    private function autorizarEscopo(User $user, Onboarding $onboarding): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $temAcesso = $user->companies()->where('companies.id', $onboarding->company_id)->exists();

        abort_unless($temAcesso, 403, 'Você não tem acesso a este onboarding.');
    }

    // ─── Montagem do payload (Tela 1) ─────────────────────────────────────

    /**
     * Shape de 1 item de `onboardings[]` no payload agrupado por empresa —
     * contrato travado no 135-09-PLAN.md.
     */
    private function resumoOnboarding(Onboarding $onboarding): array
    {
        $passos = $onboarding->passos;
        $situacao = $this->situacaoDe($onboarding, $passos);
        $trava = $this->passoQueTrava($passos);

        $item = [
            'id'      => $onboarding->id,
            'servico' => ['id' => $onboarding->servico->id, 'nome' => $onboarding->servico->nome],
            'status'  => $onboarding->status,
            'situacao'       => $situacao,
            'situacao_label' => self::SITUACAO_LABELS[$situacao],
            'responsavel'    => $onboarding->responsavel ? [
                'id'   => $onboarding->responsavel->id,
                'name' => $onboarding->responsavel->name,
            ] : null,
            'passo_que_trava' => $trava ? $this->passoTravaPayload($trava) : null,
            'contadores'      => $this->contadores($passos),
            'template_versao' => $onboarding->template?->versao,
        ];

        // D-17: a sugestão só faz sentido enquanto o onboarding ainda não
        // tem responsável CONFIRMADO — é o CTA "Confirmar responsável" do
        // rascunho, nunca uma reatribuição de onboarding já em andamento.
        if ($onboarding->status === Onboarding::STATUS_RASCUNHO) {
            $sugerido = $this->engine->sugerirResponsavel($onboarding);
            $item['responsavel_sugerido'] = $sugerido ? ['id' => $sugerido->id, 'name' => $sugerido->name] : null;
        }

        return $item;
    }

    /**
     * Shape de 1 passo no detalhe (`show()`). D-11 em ação: `aguardando_coleta`
     * nunca expõe `valor['ativos']`/`['inativos']` — filtro EXPLÍCITO, não
     * "acontece de estar vazio" (mesmo quando a coluna já está null por
     * construção do engine, ver `OnboardingEngineService::aplicarResultado()`).
     */
    private function detalhePasso(OnboardingPasso $passo, Collection $titulosPorChave): array
    {
        $templatePasso = $passo->templatePasso;
        $diasParado = $this->diasParado($passo);
        $slaDias = $templatePasso->sla_dias;
        $vencido = $passo->status === OnboardingPasso::STATUS_ABERTO
            && $diasParado !== null
            && $slaDias !== null
            && $diasParado > $slaDias;

        $item = [
            'chave'          => $passo->chave,
            'titulo'         => $templatePasso->titulo,
            'dono'           => $templatePasso->dono,
            'setor'          => $templatePasso->setor?->nome,
            'status'         => $passo->status,
            'dias_parado'    => $diasParado,
            'sla_dias'       => $slaDias,
            'vencido'        => $vencido,
            'obrigatorio'    => (bool) $templatePasso->obrigatorio,
            'depende_de'     => collect($templatePasso->depende_de ?? [])
                ->map(fn (string $chave) => $titulosPorChave->get($chave, $chave))
                ->values(),
            'condicao'       => $this->condicaoLegivel($templatePasso->condicao),
            'tem_auto_fonte' => $templatePasso->auto_fonte !== null,
        ];

        if ($passo->status === OnboardingPasso::STATUS_AGUARDANDO_COLETA) {
            $item['coleta_iniciada_em'] = $passo->coleta_iniciada_em;
            $item['coleta_demorando'] = $passo->coleta_iniciada_em !== null
                && $passo->coleta_iniciada_em->diffInMinutes(now()) > 30;
        } elseif ($passo->status === OnboardingPasso::STATUS_CONCLUIDO) {
            // Único ramo que grava valor numérico definitivo (D-11) — nunca
            // em aguardando_coleta/indeterminado/aberto/bloqueado.
            $item['valor'] = $passo->valor;
        }

        return $item;
    }

    /**
     * Classificação de situação — função pura sobre os passos já carregados
     * (nenhuma consulta aqui). Precedência de 6 valores (UI-SPEC Tela 1):
     *
     *  1. rascunho             — onboarding.status = rascunho, não corre SLA (D-05/SC-04)
     *  2. vencido               — algum passo aberto-e-parado fora do SLA
     *  3. aguardando_{dono}     — algum passo aberto-e-parado dentro do SLA
     *  4. coletando             — só resta pendência em aguardando_coleta/indeterminado
     *  5. pronto_para_concluir  — só falta o passo administrativo (D-15)
     *  6. concluido             — onboarding.status = concluido
     *
     * A checagem de `pronto_para_concluir` roda ANTES da checagem geral de
     * vencido/aguardando (que olha `passoQueTrava()` sobre TODOS os abertos,
     * administrativo incluso) — senão um passo "Confirmação de pagamento"
     * vencido sozinho classificaria o onboarding como "vencido"/"aguardando
     * interno", confundindo questão administrativa com mapeamento parado
     * (D-15). Isso não muda nenhum outro caso: se ainda há pendência
     * NÃO-administrativa, `prontoParaConcluir()` é falso e o fluxo cai nos
     * ramos 2/3/4 normalmente.
     */
    private function situacaoDe(Onboarding $onboarding, Collection $passos): string
    {
        if ($onboarding->status === Onboarding::STATUS_RASCUNHO) {
            return 'rascunho';
        }

        if ($onboarding->status === Onboarding::STATUS_CONCLUIDO) {
            return 'concluido';
        }

        if ($this->prontoParaConcluir($passos)) {
            return 'pronto_para_concluir';
        }

        $trava = $this->passoQueTrava($passos);

        if ($trava) {
            $diasParado = $this->diasParado($trava);
            $slaDias = $trava->templatePasso->sla_dias;

            if ($diasParado !== null && $slaDias !== null && $diasParado > $slaDias) {
                return 'vencido';
            }

            return 'aguardando_'.$trava->templatePasso->dono;
        }

        if ($passos->contains(fn (OnboardingPasso $p) => in_array(
            $p->status,
            [OnboardingPasso::STATUS_AGUARDANDO_COLETA, OnboardingPasso::STATUS_INDETERMINADO],
            true
        ))) {
            return 'coletando';
        }

        // Sem passo aberto, sem coleta pendente e ainda não concluído — não
        // deveria acontecer com dados consistentes (só se restar bloqueado
        // preso por dependência cíclica, guarda já existente no
        // StoreOnboardingTemplateRequest do Plano 08). 'coletando' é o
        // estado neutro mais seguro pra nunca devolver um valor fora do
        // catálogo de 6 pro frontend.
        return 'coletando';
    }

    /**
     * D-15: `true` só quando TODOS os passos não-administrativos estão
     * `concluido`/`nao_aplicavel` e o passo administrativo
     * (`confirmacao_pagamento`) ainda não está — a pendência que resta é
     * administrativa, não mapeamento parado.
     */
    private function prontoParaConcluir(Collection $passos): bool
    {
        $administrativo = $passos->firstWhere('chave', self::CHAVE_PASSO_ADMINISTRATIVO);

        if (! $administrativo) {
            return false;
        }

        $administrativoPendente = ! in_array(
            $administrativo->status,
            [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
            true
        );

        if (! $administrativoPendente) {
            return false;
        }

        return $passos
            ->reject(fn (OnboardingPasso $p) => $p->chave === self::CHAVE_PASSO_ADMINISTRATIVO)
            ->every(fn (OnboardingPasso $p) => in_array(
                $p->status,
                [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
                true
            ));
    }

    /**
     * O passo que mais trava: entre os `aberto`, o de maior `dias_parado`
     * (empate → menor `ordem`). Nenhum aberto → `null`. `aguardando_coleta`
     * nunca é escolhido — não é pendência humana (D-11).
     */
    private function passoQueTrava(Collection $passos): ?OnboardingPasso
    {
        $abertos = $passos->filter(fn (OnboardingPasso $p) => $p->status === OnboardingPasso::STATUS_ABERTO);

        if ($abertos->isEmpty()) {
            return null;
        }

        return $abertos->reduce(function (?OnboardingPasso $atual, OnboardingPasso $candidato) {
            if ($atual === null) {
                return $candidato;
            }

            $diasAtual = $this->diasParado($atual) ?? 0;
            $diasCandidato = $this->diasParado($candidato) ?? 0;

            if ($diasCandidato > $diasAtual) {
                return $candidato;
            }

            if ($diasCandidato === $diasAtual && $candidato->templatePasso->ordem < $atual->templatePasso->ordem) {
                return $candidato;
            }

            return $atual;
        });
    }

    private function passoTravaPayload(OnboardingPasso $passo): array
    {
        $diasParado = $this->diasParado($passo);
        $slaDias = $passo->templatePasso->sla_dias;

        return [
            'chave'       => $passo->chave,
            'titulo'      => $passo->templatePasso->titulo,
            'dono'        => $passo->templatePasso->dono,
            'setor'       => $passo->templatePasso->setor?->nome,
            'dias_parado' => $diasParado,
            'sla_dias'    => $slaDias,
            'vencido'     => $diasParado !== null && $slaDias !== null && $diasParado > $slaDias,
        ];
    }

    /** @return array{abertos:int,bloqueados:int,aguardando_coleta:int,indeterminados:int,concluidos:int,nao_aplicaveis:int} */
    private function contadores(Collection $passos): array
    {
        return [
            'abertos'           => $passos->where('status', OnboardingPasso::STATUS_ABERTO)->count(),
            'bloqueados'        => $passos->where('status', OnboardingPasso::STATUS_BLOQUEADO)->count(),
            'aguardando_coleta' => $passos->where('status', OnboardingPasso::STATUS_AGUARDANDO_COLETA)->count(),
            'indeterminados'    => $passos->where('status', OnboardingPasso::STATUS_INDETERMINADO)->count(),
            'concluidos'        => $passos->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count(),
            'nao_aplicaveis'    => $passos->where('status', OnboardingPasso::STATUS_NAO_APLICAVEL)->count(),
        ];
    }

    /**
     * Dias inteiros desde `disponivel_em` até agora. `null` (nunca `0`)
     * quando o passo ainda não abriu — contar do `created_at` do onboarding
     * mentiria sobre um passo que ficou bloqueado (D-11, comentário do
     * 135-09-PLAN.md).
     */
    private function diasParado(OnboardingPasso $passo): ?int
    {
        if ($passo->disponivel_em === null) {
            return null;
        }

        return (int) $passo->disponivel_em->diffInDays(now());
    }

    /**
     * Traduz `template_passos.condicao` (catálogo fechado, D-09/D-12) pra
     * texto legível pt-BR — nunca a expressão crua no payload.
     */
    private function condicaoLegivel(?array $condicao): ?string
    {
        if (! $condicao) {
            return null;
        }

        return match ($condicao['tipo'] ?? null) {
            TemplatePasso::CONDICAO_ANUNCIOS_INATIVOS => 'Só se aplica quando há anúncios inativos',
            default => 'Condição não reconhecida',
        };
    }
}
