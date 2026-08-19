<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingMapeamento;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Onboarding\OnboardingMapeamentoService;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingSituacaoService;
use App\Services\Onboarding\RelatorioInicialService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * OnboardingController — painel operacional do onboarding geral por serviço
 * (Fase 135, Plano 09). Responde "o que está travando, há quantos dias e de
 * quem é a bola" (SC-11) — nunca `feitos/total` — e expõe as duas ações que
 * a Coordenação precisa: confirmar responsável (liga o SLA, D-05/SC-04) e
 * concluir manualmente um passo (nunca um passo com `auto_fonte`, D-19).
 *
 * Gate: `permission:core.onboarding` na rota (`routes/web.php`), distinto do
 * admin passa por short-circuit em
 * `User::hasPermission()`.
 *
 * Nenhuma chamada de rede aqui: todo o cálculo é sobre dados já persistidos
 * (T-135-09-06); reavaliar() do engine só toca o banco local.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private OnboardingEngineService $engine,
        private OnboardingLinkService $linkService,
        private OnboardingSituacaoService $situacaoService,
    ) {
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
            'passos.setor:id,nome',
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
            // Plano 12 (frontend): lista de usuários para o Select de fallback do CTA
            // "Confirmar responsável" quando `sugerirResponsavel()` não encontrou
            // ninguém (sem vínculo na carteira, D-17) — sem isso o operador fica sem
            // como confirmar um onboarding em rascunho órfão de sugestão.
            'usuarios' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET /onboarding/{onboarding} — detalhe com os passos do onboarding:
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
            // `marketplace` é OBRIGATÓRIO na projeção: a visão do mapeamento
            // lê `company->marketplace`, e sem ele o campo chegava vazio na
            // tela mesmo com a empresa tendo o valor gravado ("meli").
            // Projeção que esconde coluna usada mais adiante é silenciosa —
            // não dá erro, só mostra "—".
            'company:id,name,marketplace',
            'servico:id,nome',
            'responsavel:id,name',
            'reuniaoAgendadaPor:id,name',
            'passos.setor:id,nome',
        ]);

        $passos = $onboarding->passos;
        $titulosPorChave = $passos->mapWithKeys(
            fn (OnboardingPasso $p) => [$p->chave => $p->titulo]
        );

        $situacao = $this->situacaoService->situacao($onboarding, $passos);

        $passosOrdenados = $passos
            ->sortBy(fn (OnboardingPasso $p) => $p->ordem)
            ->values()
            ->map(fn (OnboardingPasso $p) => $this->detalhePasso($p, $titulosPorChave));

        return Inertia::render('Onboarding/Detalhe', [
            'onboarding' => [
                'id'              => $onboarding->id,
                'empresa'         => ['id' => $onboarding->company->id, 'nome' => $onboarding->company->name],
                'servico'         => ['id' => $onboarding->servico->id, 'nome' => $onboarding->servico->nome],
                'status'          => $onboarding->status,
                'situacao'        => $situacao,
                'situacao_label'  => $this->situacaoService->label($situacao),
                'responsavel'     => $onboarding->responsavel ? [
                    'id'   => $onboarding->responsavel->id,
                    'name' => $onboarding->responsavel->name,
                ] : null,
                'definicao_versao' => $onboarding->definicao_versao,
                'chegou_em'        => $onboarding->created_at?->toISOString(),
            ],
            'passos' => $passosOrdenados,
            'relatorio'   => $this->relatorioPayload($onboarding),
            'reuniao'     => [
                'status'        => $onboarding->reuniao_status,
                'solicitada_em' => $onboarding->reuniao_solicitada_em?->toISOString(),
                'agendada_para' => $onboarding->reuniao_agendada_para?->toISOString(),
                'agendada_por'  => $onboarding->reuniaoAgendadaPor?->name,
                'realizada'     => $passos
                    ->firstWhere('chave', 'reuniao_realizada')?->status === OnboardingPasso::STATUS_CONCLUIDO,
            ],
            'link'        => $this->linkPayload($onboarding),
            'mapeamento'  => app(OnboardingMapeamentoService::class)->visao($onboarding),
        ]);
    }

    /**
     * POST /onboarding/{onboarding}/mapeamento/sincronizar — "Sincronizar
     * agora", em vez de esperar a passada do cron a cada 10 minutos.
     */
    public function sincronizarMapeamento(Request $request, Onboarding $onboarding, OnboardingMapeamentoService $service)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $despachados = $service->sincronizar($onboarding);

        return back()->with(
            'success',
            $despachados > 0
                ? "Buscando dados da conta ({$despachados} passo(s) em fila)."
                : 'Nada a sincronizar agora — os passos já concluíram ou foram consultados nos últimos minutos.'
        );
    }

    /**
     * POST /onboarding/{onboarding}/mapeamento/confirmar — a equipe confere o
     * apurado junto com o cliente numa call. Mesma ficha do portal, outro
     * canal — e é o canal que registra a diferença de confiabilidade.
     */
    public function confirmarMapeamento(Request $request, Onboarding $onboarding, OnboardingMapeamentoService $service)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'full_pontuacao' => ['nullable', 'integer', 'min:0', 'max:100'],
            'observacoes'    => ['nullable', 'string', 'max:2000'],
        ]);

        $service->confirmar(
            onboarding: $onboarding,
            canal: OnboardingMapeamento::CANAL_INTERNO_CALL,
            por: $request->user(),
            fullPontuacao: $data['full_pontuacao'] ?? null,
            observacoes: $data['observacoes'] ?? null,
        );

        return back()->with('success', 'Mapeamento confirmado.');
    }

    /**
     * Link do portal do cliente — e, principalmente, QUANDO ele foi aberto
     * pela última vez. É a diferença entre "o cliente não fez" e "o cliente
     * nem viu", que muda completamente a cobrança. `ultimo_acesso` já era
     * gravado a cada visita e não era exibido em lugar nenhum.
     *
     * Não cria o link: quem cria é o botão "Gerar link" (`gerarLink()`).
     * Abrir a tela de detalhe não deve ter efeito colateral de criar token.
     */
    private function linkPayload(Onboarding $onboarding): array
    {
        $link = OnboardingLink::where('company_id', $onboarding->company_id)->first();

        return [
            'existe'        => (bool) $link,
            'url'           => $link ? route('onboarding.publico.workspace', $link->token) : null,
            'ultimo_acesso' => $link?->ultimo_acesso?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relatorioPayload(Onboarding $onboarding): array
    {
        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->first();

        return [
            'existe'           => (bool) $relatorio,
            'dados'            => $relatorio?->dados,
            'pontos_atencao'   => $relatorio?->pontos_atencao,
            'oportunidades'    => $relatorio?->oportunidades,
            'proximos_passos'  => $relatorio?->proximos_passos,
            'gerado_em'        => $relatorio?->gerado_em?->toISOString(),
            'completo'         => (bool) $relatorio?->completo(),
            'secoes_pendentes' => $relatorio?->secoesPendentes() ?? OnboardingRelatorio::SECOES_ANALISTA,
        ];
    }

    /**
     * POST /onboarding/{onboarding}/responsavel — confirma o responsável e
     * transiciona rascunho→andamento (D-05/SC-04). O engine já registra
     * `activity('onboarding')` nessa transição — o controller não duplica o
     * log.
     */
    public function confirmarResponsavel(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'responsavel_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $responsavel = User::findOrFail($data['responsavel_id']);

        try {
            $this->engine->confirmarResponsavel($onboarding, $responsavel);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'responsavel_id' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Responsável confirmado — onboarding em andamento.');
    }

    /**
     * POST /onboarding/passos/{passo}/reabrir — desmarca um passo concluído.
     *
     * Faltava caminho de volta: um clique errado era definitivo e só se
     * desfazia mexendo no banco. Vale para passo automático também — reabrir
     * não nega o dado, devolve o passo à apuração do resolver.
     */
    public function reabrirPasso(Request $request, OnboardingPasso $passo)
    {
        $onboarding = $passo->onboarding;
        $this->autorizarEscopo($request->user(), $onboarding);

        try {
            $this->engine->reabrirPasso($passo, $request->user());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['passo' => $e->getMessage()]);
        }

        return back()->with('success', 'Passo desmarcado.');
    }

    /**
     * POST /onboarding/{onboarding}/reuniao — o responsável marca data e hora.
     * É a volta da informação que o cliente pediu: a partir daqui a data
     * aparece no portal dele.
     *
     * Remarcar é chamar de novo com outra data — o `activity` guarda a data
     * anterior e a nova, o que reconstrói o histórico sem tabela de
     * remarcação.
     */
    public function agendarReuniao(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'reuniao_agendada_para' => ['required', 'date'],
        ]);

        try {
            $this->engine->agendarReuniao(
                $onboarding,
                \Illuminate\Support\Carbon::parse($data['reuniao_agendada_para']),
                $request->user()
            );
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'reuniao_agendada_para' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Reunião agendada — o cliente já vê a data no portal.');
    }

    /**
     * POST /onboarding/passos/{passo}/concluir — conclui manualmente um
     * passo (nunca um passo com `auto_fonte`, D-19). Diferente da confirmação
     * de responsável, `concluirManualmente()` não registra activity própria
     * — o controller registra aqui (sem duplicar: o engine só loga a
     * transição de status do ONBOARDING, não a conclusão de um passo).
     *
     * Nenhuma chamada de rede: concluir um passo pode destravar um passo
     * automático (via `reavaliar()`, chamado dentro do engine), mas quem de
     * fato resolve o resolver automático é o comando do Plano 07 ou o Job —
     * este controller só toca banco local.
     */
    public function concluirPasso(Request $request, OnboardingPasso $passo)
    {
        $onboarding = $passo->onboarding;
        $this->autorizarEscopo($request->user(), $onboarding);

        try {
            // Override permitido a quem opera por dentro: o portal do
            // cliente continua barrado por D-19 (ele usa outra rota).
            $this->engine->concluirManualmente($passo, $request->user(), forcar: true);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                // Mensagem fixa (D-19) — não repassa $e->getMessage(): o
                // texto da exceção de domínio é para log/depuração, este é
                // para o usuário final entender por que o botão não fez nada.
                'passo' => 'Este passo é verificado automaticamente pelo sistema e não pode ser concluído manualmente.',
            ]);
        }

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties(['passo_id' => $passo->id, 'chave' => $passo->chave, 'feito_por' => $request->user()->id])
            ->log("Passo \"{$passo->titulo}\" concluído manualmente");

        return back()->with('success', 'Passo concluído.');
    }

    /**
     * POST /onboarding/empresas/{company}/link — gera (ou devolve, se já
     * existir) o token único do portal público da empresa (D-06, Plano 11).
     * Ação INTERNA, atrás do mesmo gate `permission:core.onboarding` do
     * resto do painel — o cliente nunca chega a esta rota, ela só existe
     * para a Coordenação obter/copiar o link a entregar. `paraEmpresa()` é
     * idempotente (`firstOrCreate` em `OnboardingLinkService`): chamar duas
     * vezes nunca cria um segundo token.
     */
    public function gerarLink(Request $request, Company $company)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            $temAcesso = $user->companies()->where('companies.id', $company->id)->exists();
            abort_unless($temAcesso, 403, 'Você não tem acesso a esta empresa.');
        }

        $link = $this->linkService->paraEmpresa($company);

        return back()->with(
            'success',
            'Link do portal do cliente: ' . route('onboarding.publico.workspace', $link->token)
        );
    }

    /**
     * POST /onboarding/{onboarding}/relatorio — gera (ou regera) o retrato
     * factual do relatório inicial.
     *
     * Regerar preserva o texto do analista: o que ele escreveu não some porque
     * o acervo foi recontado.
     */
    public function gerarRelatorio(Request $request, Onboarding $onboarding, RelatorioInicialService $service)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $service->gerar($onboarding, $request->user());
        $this->reavaliarPassoDoRelatorio($onboarding);

        return back()->with('success', 'Relatório inicial gerado. Escreva as três seções de análise para concluir o passo.');
    }

    /**
     * PUT /onboarding/{onboarding}/relatorio — salva as três seções que só uma
     * pessoa escreve. O passo do relatório só fecha quando as três têm
     * conteúdo — quem decide isso é o resolver, não este controller.
     */
    public function salvarRelatorio(Request $request, Onboarding $onboarding, RelatorioInicialService $service)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'pontos_atencao'  => ['nullable', 'string', 'max:5000'],
            'oportunidades'   => ['nullable', 'string', 'max:5000'],
            'proximos_passos' => ['nullable', 'string', 'max:5000'],
        ]);

        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->first();

        if (! $relatorio) {
            // Salvar antes de gerar não deve dar erro pro usuário — gera e segue.
            $relatorio = $service->gerar($onboarding, $request->user());
        }

        $relatorio->fill($data);
        $relatorio->atualizado_por = $request->user()->id;
        $relatorio->save();

        $this->reavaliarPassoDoRelatorio($onboarding);

        return back()->with('success', 'Relatório atualizado.');
    }

    /**
     * O passo do relatório tem `auto_fonte` — quem o fecha é o resolver, nunca
     * uma escrita direta de status. Resolver local, sem rede: roda inline.
     */
    private function reavaliarPassoDoRelatorio(Onboarding $onboarding): void
    {
        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('auto_fonte', OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL)
            ->first();

        if (! $passo) {
            return;
        }

        $resolver = app(OnboardingResolverFactory::class)->for(OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL);
        $this->engine->aplicarResultado($passo, $resolver->resolver($onboarding, $passo));
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
        // O shape vem do service, que é a MESMA fonte usada pela listagem de
        // /companies — duas telas calculando "quem está travado" por conta
        // própria seriam duas verdades.
        $item = $this->situacaoService->resumo($onboarding);

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
        $passo = $passo;
        $diasParado = $this->situacaoService->diasParado($passo);
        $slaDias = $passo->sla_dias;
        $vencido = $passo->status === OnboardingPasso::STATUS_ABERTO
            && $diasParado !== null
            && $slaDias !== null
            && $diasParado > $slaDias;

        $item = [
            // Plano 12 (frontend): `id` é necessário pro botão "Marcar como
            // concluído" montar `route('onboarding.passos.concluir', passo.id)`
            // — sem isso o passo detalhado não tem como ser referenciado na ação.
            'id'             => $passo->id,
            'chave'          => $passo->chave,
            'etapa'          => $passo->etapa,
            'titulo'         => $passo->titulo,
            'dono'           => $passo->dono,
            'setor'          => $passo->setor?->nome,
            'status'         => $passo->status,
            'dias_parado'    => $diasParado,
            'sla_dias'       => $slaDias,
            'vencido'        => $vencido,
            'depende_de'     => collect($passo->depende_de ?? [])
                ->map(fn (string $chave) => $titulosPorChave->get($chave, $chave))
                ->values(),
            'condicao'       => $this->condicaoLegivel($passo->condicao),
            'tem_auto_fonte' => $passo->auto_fonte !== null,
        ];

        if ($passo->status === OnboardingPasso::STATUS_AGUARDANDO_COLETA) {
            $item['coleta_iniciada_em'] = $passo->coleta_iniciada_em;
            $item['coleta_demorando'] = $passo->coleta_iniciada_em !== null
                && $passo->coleta_iniciada_em->diffInMinutes(now()) > 30;
        } elseif ($passo->status === OnboardingPasso::STATUS_CONCLUIDO) {
            // A tela precisa saber que ESTE concluído veio de decisão humana
            // sobre um passo automático — e oferecer o desmarcar.
            $item['concluido_manualmente'] = (bool) ($passo->valor['concluido_manualmente'] ?? false);
            // Único ramo que grava valor numérico definitivo (D-11) — nunca
            // em aguardando_coleta/indeterminado/aberto/bloqueado.
            $item['valor'] = $passo->valor;
        }

        return $item;
    }

    /**
     * Traduz `onboarding_passos.condicao` (catálogo fechado, D-09/D-12) pra
     * texto legível pt-BR — nunca a expressão crua no payload.
     */
    private function condicaoLegivel(?array $condicao): ?string
    {
        if (! $condicao) {
            return null;
        }

        return match ($condicao['tipo'] ?? null) {
            OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS => 'Só se aplica quando há anúncios inativos',
            default => 'Condição não reconhecida',
        };
    }
}
