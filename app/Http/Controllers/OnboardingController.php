<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingContato;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingLink;
use App\Models\OnboardingMapeamento;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Onboarding\OnboardingMapeamentoService;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingAcessosService;
use App\Services\Onboarding\OnboardingSituacaoService;
use App\Services\Onboarding\RelatorioInicialService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
            'responsavel:id,name,avatar_url',
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
            // `hubspot_observacao` e `hubspot_snapshot` entram pelo mesmo
            // motivo: o payload expõe contexto e SPIN (PDF §3, "não perguntar
            // de novo o que o Comercial já coletou") e o accessor
            // `hubspot_spin` lê o snapshot. Fora da projeção, os dois voltam
            // null sem erro nenhum — foi o que aconteceu na primeira versão.
            'company:id,name,marketplace,hubspot_observacao,hubspot_snapshot,email_colaborador,app_ecf_link',
            'servico:id,nome',
            'responsavel:id,name,avatar_url',
            'reuniaoAgendadaPor:id,name',
            // O cabecalho do cockpit mostra "Analista responsavel" (R-01) —
            // sem estes dois ele cairia sempre no `responsavel` generico.
            'responsavelEstrategista:id,name,avatar_url',
            'responsavelAnalista:id,name,avatar_url',
            'passos.setor:id,nome',
            // Quem fechou cada passo, para o feed de atividade. Sem o eager
            // load isto seria uma consulta por passo dentro do laco.
            'passos.feitoPor:id,name',
        ]);

        $passos = $onboarding->passos;
        $titulosPorChave = $passos->mapWithKeys(
            fn (OnboardingPasso $p) => [$p->chave => $p->titulo]
        );

        $situacao = $this->situacaoService->situacao($onboarding, $passos);

        // Calculado ANTES do render: o feed de atividade tambem le o
        // `ultimo_acesso` daqui, e montar o payload duas vezes seria uma
        // consulta a mais por abertura de tela.
        $link = $this->linkPayload($onboarding);

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
                    'id'         => $onboarding->responsavel->id,
                    'name'       => $onboarding->responsavel->name,
                    'avatar_url' => $onboarding->responsavel->avatar_url,
                ] : null,
                // Os dois papeis (R-01). O cabecalho mostra o ANALISTA como
                // "Analista responsavel"; `responsavel` acima continua sendo o
                // principal, que e o nome que o portal do cliente mostra.
                'responsavel_estrategista' => $onboarding->responsavelEstrategista ? [
                    'id'         => $onboarding->responsavelEstrategista->id,
                    'name'       => $onboarding->responsavelEstrategista->name,
                    'avatar_url' => $onboarding->responsavelEstrategista->avatar_url,
                ] : null,
                'responsavel_analista' => $onboarding->responsavelAnalista ? [
                    'id'         => $onboarding->responsavelAnalista->id,
                    'name'       => $onboarding->responsavelAnalista->name,
                    'avatar_url' => $onboarding->responsavelAnalista->avatar_url,
                ] : null,
                // Mesma fração que a listagem mostra — vem do service, não de
                // uma contagem local, senão as duas telas divergem.
                'progresso'        => $this->situacaoService->progresso($passos),
                'definicao_versao' => $onboarding->definicao_versao,
                'chegou_em'        => $onboarding->created_at?->toISOString(),
                // O que o Comercial já coletou, para não ser perguntado de novo
                // (PDF §3: "não deverá existir necessidade de preencher
                // novamente informações que já foram coletadas durante a
                // venda"). Vem do `hubspot_snapshot` por accessor pronto — sem
                // consulta nova e sem coluna nova.
                'spin'             => $onboarding->company->hubspot_spin,
                'contexto'         => $onboarding->company->hubspot_observacao,
            ],
            'passos' => $passosOrdenados,
            'relatorio'   => $this->relatorioPayload($onboarding),
            // Respostas do checklist do fluxo de 19/08. Cada bloco é a
            // tabela própria do assunto — nunca `onboarding_passos.valor`,
            // que só é gravado quando o passo fecha e some ao desmarcar.
            'respostas'   => $this->respostasPayload($onboarding),
            'reuniao'     => [
                'status'        => $onboarding->reuniao_status,
                'solicitada_em' => $onboarding->reuniao_solicitada_em?->toISOString(),
                'agendada_para' => $onboarding->reuniao_agendada_para?->toISOString(),
                'agendada_por'  => $onboarding->reuniaoAgendadaPor?->name,
                'realizada'     => $passos
                    ->firstWhere('chave', 'reuniao_realizada')?->status === OnboardingPasso::STATUS_CONCLUIDO,
            ],
            'link'        => $link,
            'mapeamento'  => app(OnboardingMapeamentoService::class)->visao($onboarding),

            // ─── Cockpit (20/08) ────────────────────────────────────────────
            // Quatro leituras que a tela ja tinha os dados para dar e nao dava:
            // o que travar agora, de quem e a bola, em que ponto da vida o
            // onboarding esta e o que aconteceu por ultimo. Nenhuma delas cria
            // regra — todas leem o que ja estava persistido.
            // Link do App ECF e e-mail do colaborador desta empresa. Nao ha
            // padrao global: vazio quer dizer "ainda nao configurado".
            'acessos'           => app(OnboardingAcessosService::class)->paraEmpresa($onboarding->company),
            'proxima_acao'      => $this->proximaAcaoPayload($onboarding, $passos),
            'responsabilidades' => $this->responsabilidadesPayload($passos),
            'linha_do_tempo'    => $this->linhaDoTempoPayload($onboarding),
            'atividade'         => $this->atividadePayload($onboarding, $passos, $link['ultimo_acesso']),
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
            // `UrlDoPortal`, não `route()`: este link é COPIADO e mandado ao
            // cliente, e `route()` o montaria com o host de quem está
            // olhando — o do admin.
            'url'           => $link ? \App\Support\Portal\UrlDoPortal::para('portal.inicio', $link->token) : null,
            'ultimo_acesso' => $link?->ultimo_acesso?->toISOString(),

            // ── Quem entra COM LOGIN ─────────────────────────────────
            // O link e o login convivem: o link é de quem tem o endereço,
            // o login é de uma pessoa. A tela mostra os dois porque a
            // pergunta "esse cliente consegue entrar?" hoje tem duas
            // respostas possíveis, e elas valem coisas diferentes na hora
            // de cobrar: link aberto não diz QUEM abriu.
            'acessos'       => \App\Models\PortalUsuario::whereHas(
                'empresas',
                fn ($q) => $q->where('companies.id', $onboarding->company_id)
            )->orderBy('nome')->get()->map(fn ($u) => [
                'id'            => $u->id,
                'nome'          => $u->nome,
                'email'         => $u->email,
                'ativo'         => $u->ativo,
                'nunca_entrou'  => $u->primeiro_acesso_em === null,
                'ultimo_acesso' => $u->ultimo_acesso_em?->format('d/m/Y H:i'),
            ])->values(),

            // "Ver o portal do cliente" só para quem de fato pode entrar —
            // a régua é a da carteira, não a de ver esta tela.
            'pode_entrar'   => app(\App\Services\Portal\PortalEquipeService::class)
                ->podeEntrar(request()->user(), $onboarding->company),
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
     * POST /onboarding/{onboarding}/responsaveis — define estrategista e/ou
     * analista (R-01).
     *
     * É o "iniciar onboarding" da listagem de `/companies`: qualquer um dos
     * dois já liga o SLA (R-02), e é por esta mesma rota que se volta depois
     * para preencher o papel que faltava — por isso ela aceita onboarding já
     * em andamento, ao contrário de `confirmarResponsavel()`.
     */
    public function definirResponsaveis(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'responsavel_estrategista_id' => ['nullable', 'integer', 'exists:users,id'],
            'responsavel_analista_id'     => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $estrategista = ($data['responsavel_estrategista_id'] ?? null)
            ? User::find($data['responsavel_estrategista_id'])
            : null;

        $analista = ($data['responsavel_analista_id'] ?? null)
            ? User::find($data['responsavel_analista_id'])
            : null;

        try {
            $this->engine->definirResponsaveis($onboarding, $estrategista, $analista);
        } catch (\DomainException $e) {
            // A mensagem cai no campo do analista porque é o papel operacional
            // e o primeiro que a tela oferece — sem isso o erro fica órfão de
            // campo e o Inertia não o mostra em lugar nenhum.
            throw ValidationException::withMessages([
                'responsavel_analista_id' => $e->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            $onboarding->wasChanged('status')
                ? 'Onboarding iniciado — responsáveis definidos.'
                : 'Responsáveis atualizados.'
        );
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
            'Link do portal do cliente: ' . \App\Support\Portal\UrlDoPortal::para('portal.inicio', $link->token)
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
    /**
     * Roda AGORA o resolver dos passos afetados por uma resposta que acabou de
     * ser gravada.
     *
     * Sem isto o item continuaria pendente por até 10 minutos: `reavaliar()`
     * apenas destrava passo bloqueado cuja dependência resolveu — quem executa
     * resolver é o comando `onboarding:reavaliar-passos`, agendado no cron.
     * A pessoa responde "Sim", a página recarrega e nada muda; ela responde de
     * novo. Mesmo caminho que o fluxo do relatório inicial já usa logo abaixo.
     *
     * Só resolver SÍNCRONO: assíncrono depende de job/rede e tem o cron como
     * dono legítimo — chamá-lo aqui seguraria a request numa chamada externa.
     *
     * @param  array<int, string>  $autoFontes
     * @param  ?string  $apenasChave  limita a UM item (o que a pessoa respondeu)
     */
    private function resolverAgora(Onboarding $onboarding, array $autoFontes, ?string $apenasChave = null): void
    {
        $factory = app(OnboardingResolverFactory::class);

        $passos = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->whereIn('auto_fonte', $autoFontes)
            ->when($apenasChave !== null, fn ($q) => $q->where('chave', $apenasChave))
            ->get();

        foreach ($passos as $passo) {
            $resolver = $factory->for($passo->auto_fonte);

            if ($resolver->assincrono()) {
                continue;
            }

            $this->engine->aplicarResultado($passo, $resolver->resolver($onboarding, $passo));
        }

        // Depois de aplicar: destrava quem dependia desses passos.
        $this->engine->reavaliar($onboarding->fresh());
    }

    /**
     * As respostas já dadas, por assunto. Shape consumido por
     * `Onboarding/Detalhe` para pré-preencher os formulários — a tela nunca
     * remonta isto a partir dos passos.
     */
    private function respostasPayload(Onboarding $onboarding): array
    {
        $investimento = OnboardingInvestimento::where('onboarding_id', $onboarding->id)->first();
        $agenda = OnboardingAgenda::where('onboarding_id', $onboarding->id)->first();

        return [
            // Indexado por `chave` do passo: é assim que o item do checklist
            // encontra a própria resposta sem varrer a lista.
            'confirmacoes' => OnboardingConfirmacao::where('onboarding_id', $onboarding->id)
                ->get()
                ->keyBy('chave')
                ->map(fn (OnboardingConfirmacao $r) => [
                    'resposta'      => $r->resposta,
                    'observacoes'   => $r->observacoes,
                    'respondido_em' => $r->respondido_em?->toISOString(),
                ]),

            'investimento' => $investimento ? [
                'investimento_disponivel'      => $investimento->investimento_disponivel,
                'investimento_mensal_previsto' => $investimento->investimento_mensal_previsto,
                'investimento_publicidade'     => $investimento->investimento_publicidade,
                'observacoes'                  => $investimento->observacoes,
            ] : null,

            'contatos' => OnboardingContato::where('onboarding_id', $onboarding->id)
                ->orderBy('id')
                ->get()
                ->map(fn (OnboardingContato $ct) => [
                    'id'        => $ct->id,
                    'papel'     => $ct->papel,
                    'nome'      => $ct->nome,
                    'email'     => $ct->email,
                    'funcao'    => $ct->funcao,
                    'telefone'  => $ct->telefone,
                    'principal' => (bool) $ct->principal,
                ])
                ->values(),

            'agenda' => $agenda ? [
                'dia_semana'    => $agenda->dia_semana,
                'horario'       => $agenda->horario,
                'periodicidade' => $agenda->periodicidade,
                'observacoes'   => $agenda->observacoes,
            ] : null,
        ];
    }

    /**
     * POST /onboarding/{onboarding}/confirmacao — responde Sim ou Não a um
     * item de confirmação (§17/§18).
     *
     * "Não" é resposta gravada, não ausência de resposta: o passo continua
     * `aberto` e o painel mostra que houve uma negativa. Reusar
     * `nao_aplicavel` do passo faria o onboarding se dar por concluído com a
     * publicidade nunca explicada — aquele status conta como resolvido.
     */
    public function responderConfirmacao(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'chave'       => ['required', 'string', 'max:60'],
            'resposta'    => ['required', 'string', Rule::in(OnboardingConfirmacao::RESPOSTAS)],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);

        // A chave tem de ser de um passo DESTE onboarding — sem isso a rota
        // aceitaria gravar resposta para qualquer texto.
        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $data['chave'])
            ->first();

        abort_unless($passo !== null, 422, 'Este item não existe neste onboarding.');

        OnboardingConfirmacao::updateOrCreate(
            ['onboarding_id' => $onboarding->id, 'chave' => $data['chave']],
            [
                'resposta'       => $data['resposta'],
                'observacoes'    => $data['observacoes'] ?? null,
                'respondido_em'  => now(),
                'respondido_por' => $request->user()->id,
            ]
        );

        $this->resolverAgora($onboarding, [OnboardingPasso::AUTO_FONTE_CONFIRMACAO], $data['chave']);

        return back()->with('success', 'Resposta registrada.');
    }

    /** PUT /onboarding/{onboarding}/investimento — §13.1. */
    public function salvarInvestimento(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            // `nullable` + `min:0`: zero é um valor INFORMADO ("não vai
            // investir agora"), diferente de não ter respondido.
            'investimento_disponivel'      => ['nullable', 'numeric', 'min:0'],
            'investimento_mensal_previsto' => ['nullable', 'numeric', 'min:0'],
            'investimento_publicidade'     => ['nullable', 'numeric', 'min:0'],
            'observacoes'                  => ['nullable', 'string', 'max:2000'],
        ]);

        OnboardingInvestimento::updateOrCreate(
            ['onboarding_id' => $onboarding->id],
            $data + [
                'informado_em'  => now(),
                'informado_por' => $request->user()->id,
                'informado_canal' => 'interno_call',
            ]
        );

        $this->resolverAgora($onboarding, [
            OnboardingPasso::AUTO_FONTE_INVESTIMENTO,
            OnboardingPasso::AUTO_FONTE_INVESTIMENTO_PUBLICIDADE,
        ]);

        return back()->with('success', 'Investimento registrado.');
    }

    /** PUT /onboarding/{onboarding}/agenda — §14. */
    public function salvarAgenda(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'dia_semana'    => ['nullable', 'integer', 'between:1,7'],
            'horario'       => ['nullable', 'date_format:H:i'],
            'periodicidade' => ['nullable', 'string', Rule::in(OnboardingAgenda::PERIODICIDADES)],
            'observacoes'   => ['nullable', 'string', 'max:2000'],
        ]);

        OnboardingAgenda::updateOrCreate(
            ['onboarding_id' => $onboarding->id],
            $data + [
                'definida_em'  => now(),
                'definida_por' => $request->user()->id,
            ]
        );

        $this->resolverAgora($onboarding, [OnboardingPasso::AUTO_FONTE_AGENDA_QUINZENAL]);

        return back()->with('success', 'Agenda registrada.');
    }

    /**
     * POST /onboarding/{onboarding}/contatos — §13.2 e §16.
     *
     * Uma linha por contato, sempre. A lista NUNCA é reconstruída inteira a
     * cada save: foi exatamente assim que, noutro módulo deste sistema, N
     * produtos colapsaram num só e o custo do cliente sumiu sem volta.
     */
    public function salvarContato(Request $request, Onboarding $onboarding)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'papel'     => ['required', 'string', Rule::in(OnboardingContato::PAPEIS)],
            'nome'      => ['required', 'string', 'max:120'],
            'email'     => ['nullable', 'email', 'max:190'],
            'funcao'    => ['nullable', 'string', 'max:80'],
            'telefone'  => ['nullable', 'string', 'max:30'],
        ]);

        OnboardingContato::create($data + [
            'onboarding_id' => $onboarding->id,
            'criado_por'    => $request->user()->id,
        ]);

        $this->resolverAgora($onboarding, [
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
        ]);

        return back()->with('success', 'Contato adicionado.');
    }

    /** PUT /onboarding/contatos/{contato} — edita UMA linha, nunca a lista. */
    public function atualizarContato(Request $request, OnboardingContato $contato)
    {
        $onboarding = $contato->onboarding;
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'nome'     => ['required', 'string', 'max:120'],
            'email'    => ['nullable', 'email', 'max:190'],
            'funcao'   => ['nullable', 'string', 'max:80'],
            'telefone' => ['nullable', 'string', 'max:30'],
        ]);

        $contato->update($data);
        $this->resolverAgora($onboarding, [
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
        ]);

        return back()->with('success', 'Contato atualizado.');
    }

    /** DELETE /onboarding/contatos/{contato}. */
    public function removerContato(Request $request, OnboardingContato $contato)
    {
        $onboarding = $contato->onboarding;
        $this->autorizarEscopo($request->user(), $onboarding);

        $contato->delete();
        $this->resolverAgora($onboarding, [
            OnboardingPasso::AUTO_FONTE_PONTO_CONTATO,
            OnboardingPasso::AUTO_FONTE_PARTICIPANTES,
        ]);

        return back()->with('success', 'Contato removido.');
    }

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
     * PUT /onboarding/{onboarding}/acessos — o override DESTA empresa.
     *
     * Campo apagado grava `null`, que significa "volta a seguir o padrão" — é
     * o caminho de volta. Sem isso, quem preenchesse por engano ficaria preso
     * ao valor próprio para sempre.
     */
    public function salvarAcessosDaEmpresa(Request $request, Onboarding $onboarding, OnboardingAcessosService $acessos)
    {
        $this->autorizarEscopo($request->user(), $onboarding);

        $data = $request->validate([
            'app_ecf_link'      => ['nullable', 'url', 'max:500'],
            'email_colaborador' => ['nullable', 'email', 'max:255'],
        ]);

        $acessos->salvarDaEmpresa(
            $onboarding->company,
            $data['app_ecf_link'] ?? null,
            $data['email_colaborador'] ?? null,
        );

        return back()->with('success', 'Acessos desta empresa atualizados.');
    }

    /**
     * "Onde eu preciso agir agora?" — a MESMA leitura da listagem, vinda do
     * mesmo serviço.
     *
     * O detalhe já mostrava o passo que trava, mas diluído: era só mais uma
     * linha dentro da etapa corrente. Quem abria a tela tinha de varrer o
     * fluxo inteiro para achá-lo. Aqui ele sobe para o topo — sem virar uma
     * segunda régua: `passoQueTrava()` é o mesmo método que a linha da tabela
     * usa, então lista e detalhe nunca apontam para passos diferentes.
     */
    private function proximaAcaoPayload(Onboarding $onboarding, Collection $passos): ?array
    {
        if ($onboarding->status === Onboarding::STATUS_CONCLUIDO) {
            return null;
        }

        $trava = $this->situacaoService->passoQueTrava($passos);

        if (! $trava) {
            return null;
        }

        $payload = $this->situacaoService->passoTravaPayload($trava);

        // O MOTIVO REAL, não "não enviado". Passo bloqueado sabe de quem
        // depende; passo condicional sabe a condição. Sem isto o destaque
        // repete o título do passo e não acrescenta nada a quem já o leu.
        $payload['id'] = $trava->id;
        $payload['etapa'] = $trava->etapa;
        $payload['natureza'] = $trava->natureza ?? OnboardingPasso::NATUREZA_ACAO;
        $payload['depende_de'] = collect($trava->depende_de ?? [])
            ->map(fn (string $chave) => $passos->firstWhere('chave', $chave)?->titulo ?? $chave)
            ->values();
        $payload['condicao'] = $this->condicaoLegivel($trava->condicao);

        return $payload;
    }

    /**
     * Pendências por RESPONSÁVEL, para responder "de quem é a bola" sem abrir
     * etapa nenhuma.
     *
     * Os três primeiros (`cliente`/`interno`/`sistema`) são o eixo `dono`, que
     * é EXCLUSIVO: todo passo aberto cai em exatamente um deles, e os três
     * somam o total de pendências. É por isso que "reunião" NÃO é um quarto
     * item lado a lado — `reuniao` é `natureza` (COMO o item se preenche), um
     * eixo independente, e um passo "na reunião" já está contado em `interno`
     * ou `cliente`. Exibi-lo como irmão faria quatro números que não somam o
     * total, e ninguém descobriria por quê. Ele vai como SUBCONJUNTO.
     *
     * `automaticos` é outra métrica: o que o sistema fechou sozinho. Não é
     * pendência — é o contrário disso.
     */
    private function responsabilidadesPayload(Collection $passos): array
    {
        $abertos = $passos->where('status', OnboardingPasso::STATUS_ABERTO);

        return [
            'cliente' => $abertos->where('dono', 'cliente')->count(),
            'interno' => $abertos->where('dono', 'interno')->count(),
            'sistema' => $abertos->where('dono', 'sistema')->count(),
            // Subconjunto dos acima — nunca somar com eles.
            'na_reuniao' => $abertos
                ->where('natureza', OnboardingPasso::NATUREZA_REUNIAO)
                ->count(),
            // Fechados pelo resolver, sem ninguém clicar.
            'automaticos' => $passos
                ->filter(fn (OnboardingPasso $p) => $p->auto_em !== null
                    && $p->status === OnboardingPasso::STATUS_CONCLUIDO)
                ->count(),
        ];
    }

    /**
     * A vida do onboarding em marcos REAIS — os três que o banco de fato
     * registra (`created_at`, `iniciado_em`, `concluido_em`), nunca uma régua
     * decorativa de cinco caixinhas que não corresponde a coluna nenhuma.
     *
     * "Em operação" não entra: não existe como estado de onboarding (o
     * catálogo é rascunho/andamento/concluído). Concluir É o sinal de que a
     * empresa está pronta para operar, e inventar um marco a mais criaria uma
     * etapa que nada no sistema consegue preencher — ela ficaria cinza para
     * sempre, em todo onboarding, inclusive nos que terminaram bem.
     */
    private function linhaDoTempoPayload(Onboarding $onboarding): array
    {
        $emRascunho = $onboarding->status === Onboarding::STATUS_RASCUNHO;
        $concluido = $onboarding->status === Onboarding::STATUS_CONCLUIDO;

        return [
            [
                'chave'  => 'chegou',
                'titulo' => 'Chegou',
                'ajuda'  => 'Contrato criado — o onboarding nasceu junto.',
                'data'   => $onboarding->created_at?->toISOString(),
                'estado' => 'feito',
            ],
            [
                'chave'  => 'iniciado',
                'titulo' => 'Em andamento',
                'ajuda'  => 'Responsável definido — o prazo corre e o cliente ganha o portal.',
                'data'   => $onboarding->iniciado_em?->toISOString(),
                'estado' => $emRascunho ? 'atual' : 'feito',
            ],
            [
                'chave'  => 'concluido',
                'titulo' => 'Concluído',
                'ajuda'  => 'Todos os passos fechados — a empresa está pronta para operar.',
                'data'   => $onboarding->concluido_em?->toISOString(),
                'estado' => $concluido ? 'feito' : ($emRascunho ? 'futuro' : 'atual'),
            ],
        ];
    }

    /**
     * O que aconteceu de fato, mais recente primeiro.
     *
     * Fonte: `onboarding_passos.feito_em/feito_por/auto_em` — colunas que já
     * existiam e nunca tinham sido lidas por tela nenhuma. Nenhuma tabela
     * nova: um feed de auditoria próprio seria uma segunda verdade sobre o
     * mesmo fato e divergiria do checklist no primeiro passo desmarcado.
     *
     * `auto_em` preenchido distingue "o sistema fechou" de "alguém fechou",
     * que é a diferença que muda a confiança na informação.
     */
    private function atividadePayload(
        Onboarding $onboarding,
        Collection $passos,
        ?string $ultimoAcessoCliente,
    ): array {
        // `feito_em` OU `auto_em`: passo fechado por pessoa grava o primeiro,
        // passo fechado por resolver grava o segundo — e nunca os dois. Filtrar
        // só por `feito_em` (como esta primeira versão fazia) escondia do feed
        // exatamente o que o sistema resolve sozinho, inclusive a confirmação
        // que o cliente acabou de dar no portal.
        $eventos = $passos
            ->filter(fn (OnboardingPasso $p) => $p->feito_em !== null || $p->auto_em !== null)
            ->map(fn (OnboardingPasso $p) => [
                'tipo'       => 'passo',
                'titulo'     => $p->titulo,
                'quem'       => $p->auto_em !== null
                    ? 'Sistema'
                    : ($p->feitoPor?->name ?? ucfirst((string) $p->dono)),
                'automatico' => $p->auto_em !== null,
                'dono'       => $p->dono,
                'quando'     => ($p->feito_em ?? $p->auto_em)->toISOString(),
            ])
            ->values()
            ->all();

        if ($onboarding->iniciado_em) {
            $eventos[] = [
                'tipo'       => 'marco',
                'titulo'     => 'Onboarding iniciado',
                'quem'       => $onboarding->responsavel?->name ?? 'ECF',
                'automatico' => false,
                'dono'       => 'interno',
                'quando'     => $onboarding->iniciado_em->toISOString(),
            ];
        }

        if ($onboarding->reuniao_agendada_para) {
            $eventos[] = [
                'tipo'       => 'marco',
                'titulo'     => 'Reunião de onboarding agendada',
                'quem'       => $onboarding->reuniaoAgendadaPor?->name ?? 'ECF',
                'automatico' => false,
                'dono'       => 'interno',
                'quando'     => $onboarding->reuniao_agendada_para->toISOString(),
            ];
        }

        // "O cliente já viu o que pedimos?" — a pergunta que o time faz antes
        // de cobrar. Sem isto, cobrar quem nunca recebeu o link é rotina.
        if ($ultimoAcessoCliente) {
            $eventos[] = [
                'tipo'       => 'acesso',
                'titulo'     => 'Cliente abriu o portal',
                'quem'       => 'Cliente',
                'automatico' => false,
                'dono'       => 'cliente',
                'quando'     => $ultimoAcessoCliente,
            ];
        }

        // Comparação de string em ISO-8601 UTC ordena igual a comparação de
        // data — todas as datas saem de `toISOString()`, mesmo fuso e mesmo
        // número de casas.
        usort($eventos, fn (array $a, array $b) => strcmp($b['quando'], $a['quando']));

        return array_slice($eventos, 0, 12);
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
            // `null` vira `acao`: linha antiga, anterior ao eixo, se comporta
            // como o que sempre foi.
            'natureza'       => $passo->natureza ?? OnboardingPasso::NATUREZA_ACAO,
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
            // Flag explícito em vez de expor a chave crua de `auto_fonte`:
            // a tela precisa saber se ESTE item aceita resposta Sim/Não, não
            // qual resolver o fecha.
            'aceita_confirmacao' => $passo->auto_fonte === OnboardingPasso::AUTO_FONTE_CONFIRMACAO,
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
