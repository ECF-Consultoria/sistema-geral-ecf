<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMetricaManualRequest;
use App\Jobs\AtualizarNotaAposMetricaManualJob;
use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\FinancialSourceResolver;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Lançamento manual de métricas financeiras por (empresa, mês) — Fase 136
 * Plano 04 (D-01/D-02/D-07/D-09/D-12).
 *
 * Backend da grade admin: `index()` monta a matriz empresa × métrica da
 * competência selecionada; `lancar()` grava a célula. A tela em si é do
 * Plano 05 — aqui não há nenhum arquivo de front.
 *
 * ### A ferramenta é admin-GLOBAL por desenho (decisão registrada, não descuido)
 * Não existe escopo de carteira em lugar nenhum deste controller: o goal da
 * fase diz "o admin decide, por empresa e por mês", e as duas rotas são
 * `role:admin` (T-136-01, dupla defesa com `StoreMetricaManualRequest::
 * authorize()`). O universo da grade é toda empresa `active = true` com pelo
 * menos um vínculo em setor financeiramente elegível — os mesmos dois setores
 * que `CarteiraContextService::flagsFinanceirasPorSetor()` marca como
 * `financial_metrics_eligible = true` (performance → Adman, shopee → Shopee).
 * Empresa fora disso nunca produz linha de Desempenho e não teria por que
 * aparecer aqui. A guarda contra `company_id` arbitrário é `active = true`
 * (T-136-06), aplicada no FormRequest, não um filtro por vínculo.
 *
 * ### Filtros de marketplace e de colaborador (2026-09-02) são RECORTE DE TELA
 * `index()` aceita `fonte` e `colaborador` só para esconder linha de quem está
 * olhando — nenhum dos dois é escopo de permissão, e por isso NÃO existem no
 * `StoreMetricaManualRequest`: o admin continua podendo lançar em qualquer
 * empresa ativa do universo, filtrada na tela ou não. O filtro por colaborador
 * recorta o par `(empresa, canal)`, não a empresa inteira — conta atendida no
 * Mercado Livre por um profissional e na Shopee por outro mostra, na carteira
 * de cada um, só a linha do canal dele. `fontes`/`multi_canal` seguem contando
 * TODOS os vínculos da empresa, para o selo "2 canais" continuar avisando que
 * existe outra linha atendida por outra pessoa.
 *
 * ### D-09 REVOGADO em 2026-08-31 — competência consolidada TAMBÉM é editável
 * A trava original deixava o mês read-only assim que existisse pelo menos uma
 * linha `origem='consolidar_mes'` naquele `mes_referencia`. O negócio pediu o
 * contrário: o admin edita métrica manual em qualquer competência, sem
 * bloqueio. As três camadas da trava caíram juntas (front, FormRequest e a
 * revalidação com lock de `lancar()`) — não sobrou guarda parcial que
 * recusasse depois de a tela ter deixado digitar.
 * `CompanyScoreSnapshotWriter::competenciaConsolidada()` continua sendo
 * consultada em `mesesDoSeletor()`, agora só como RÓTULO informativo: o mês
 * consolidado aparece marcado no seletor e num aviso na tela, e o lançamento
 * segue permitido — e desde 2026-08-31 lançar aqui REESCREVE a nota congelada
 * de quem atende a empresa, via `AtualizarNotaAposMetricaManualJob`.
 *
 * ### Custo de API contido: quem não tem lançamento não paga HTTP (T-136-17)
 * `AdmanMetricDiffService::compute()` faz HTTP síncrono à Adman no cache miss
 * (25 empresas frias custaram 157s medidos em produção em 2026-08-07). Por
 * isso a grade só resolve o valor da API para as células que JÁ TÊM
 * lançamento, e para fonte `adman` só quando `isCached()` responde `true`;
 * caso contrário a célula devolve `api_valor = null` com
 * `api_aquecida = false` e a tela diz "ainda não aquecido" em vez de pendurar
 * a resposta. Fonte `shopee` lê `shopee_metrics` do banco e é sempre barata.
 * Empresa sem nenhum lançamento não dispara sequer
 * `MetricPeriodResolver::resolve()`.
 *
 * @see .planning/phases/136-.../136-04-PLAN.md
 * @see App\Http\Requests\StoreMetricaManualRequest
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter::competenciaConsolidada()
 */
class DesempenhoMetricasManuaisController extends Controller
{
    /** Quantos meses o seletor de competência oferece (o corrente + os 11 anteriores). */
    private const MESES_NO_SELETOR = 12;

    /**
     * Rótulo humano do canal. 'adman' é o identificador técnico da fonte que
     * lê a API Adman — para quem usa a tela, isso é "Mercado Livre".
     */
    private const FONTE_LABELS = [
        DesempenhoMetricaManual::FONTE_ADMAN  => 'Mercado Livre',
        DesempenhoMetricaManual::FONTE_SHOPEE => 'Shopee',
    ];

    public function __construct(
        private MetricPeriodResolver $periodResolver,
        private MetricDiffDispatcher $diffDispatcher,
        private AdmanMetricDiffService $admanMetricDiffService,
        private DesempenhoScoreService $scoreService,
        private FinancialSourceResolver $financialSourceResolver,
    ) {}

    /**
     * Grade empresa × métrica da competência selecionada.
     *
     * A grade abre no MÊS CORRENTE; os demais meses são alcançáveis pelo
     * parâmetro `mes` — e TODOS são editáveis, consolidados ou não (ver a nota
     * "D-09 REVOGADO" no docblock da classe).
     */
    public function index(Request $request)
    {
        $dados = $request->validate([
            'mes'         => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'busca'       => ['nullable', 'string', 'max:100'],
            // Recortes da grade (2026-09-02). Nenhum dos dois muda o UNIVERSO
            // — só escondem linha. O que a tela deixa lançar continua sendo
            // decidido pelo FormRequest, que não conhece filtro nenhum.
            'fonte'       => ['nullable', Rule::in(array_keys(self::FONTE_LABELS))],
            'colaborador' => ['nullable', 'integer', 'min:1'],
        ]);

        $mes = ! empty($dados['mes'])
            ? Carbon::createFromFormat('Y-m-d', $dados['mes'] . '-01')->startOfMonth()
            : Carbon::now()->startOfMonth();

        $busca = trim((string) ($dados['busca'] ?? ''));

        // String vazia já chega como null (middleware `ConvertEmptyStringsToNull`),
        // mas o cast explícito evita que '0' ou ' ' virem filtro fantasma.
        $fonteFiltro = $dados['fonte'] ?? null;
        $colaborador = isset($dados['colaborador']) ? (int) $dados['colaborador'] : null;

        $meses       = $this->mesesDoSeletor($mes);
        $consolidada = (bool) ($meses->firstWhere('valor', $mes->format('Y-m'))['consolidada'] ?? false);

        $empresas = $this->montarEmpresas($mes, $busca, $fonteFiltro, $colaborador);

        return Inertia::render('Desempenho/MetricasManuais', [
            'mes'           => $mes->format('Y-m'),
            'mes_label'     => $this->mesExtenso($mes),
            'meses'         => $meses->values(),
            'consolidada'   => $consolidada,
            'busca'         => $busca,
            // Filtros ativos + as opções de cada um. `colaboradores` é
            // derivado do MESMO universo da grade: quem aparece no seletor
            // sempre tem pelo menos uma linha para mostrar.
            'fonte'         => $fonteFiltro,
            'colaborador'   => $colaborador,
            'fontes'        => $this->fontesDoSeletor(),
            'colaboradores' => $this->colaboradoresDoSeletor(),
            'empresas'      => $empresas,
            // Espelho JS das whitelists canônicas — a tela não redigita as
            // strings de métrica nem de tipo (o model é a única fonte).
            'metricas'      => DesempenhoMetricaManual::METRICAS,
            'tipos'         => DesempenhoMetricaManual::TIPOS,
        ]);
    }

    /**
     * Grava a célula `(empresa, mês, métrica)`.
     *
     * Autorização já resolvida em duas camadas antes daqui: middleware
     * `role:admin` da rota + `StoreMetricaManualRequest::authorize()`.
     *
     * Semântica de escrita (D-01/D-02/D-12):
     *  - ligar/editar → `ativo = true`, `valor` novo, `valor_anterior` recebe
     *    o valor que estava na linha (ou `null` se ela não existia);
     *  - reverter para auto → `ativo = false`, `valor` PRESERVADO e
     *    `valor_anterior` com o valor corrente.
     * A linha NUNCA é apagada — ela é o rastro (D-12).
     */
    public function lancar(StoreMetricaManualRequest $request)
    {
        $mes       = $request->mesReferencia();
        $mesStr    = $mes->toDateString();
        $companyId = (int) $request->input('company_id');
        $fonte     = (string) $request->input('fonte');
        $metrica   = (string) $request->input('metrica');
        $ativo     = $request->boolean('ativo');
        $valor     = $request->input('valor');
        $tipo      = $request->tipoLancamento();

        $acao = DB::transaction(function () use ($mes, $mesStr, $companyId, $fonte, $metrica, $tipo, $ativo, $valor, $request) {
            // A trava de "competência consolidada" foi REMOVIDA a pedido do
            // negócio (2026-08-31): o admin lança métrica manual em QUALQUER
            // competência, congelada ou não — e a nota congelada é reescrita
            // logo depois pelo `AtualizarNotaAposMetricaManualJob`, despachado
            // FORA desta transação (ver o dispatch no fim do método).

            // Busca por `whereDate` (nunca igualdade crua em `mes_referencia`):
            // o cast `date` do model grava 'YYYY-MM-DD 00:00:00' no SQLite dos
            // testes e 'YYYY-MM-DD' no MariaDB de produção — um
            // `updateOrCreate` chaveado por igualdade não casaria no SQLite e
            // criaria linha duplicada. `lockForUpdate()` mantém a leitura e a
            // escrita da célula dentro da mesma transação.
            // `fonte` faz parte da identidade da célula: a mesma empresa pode
            // ter lançamento nos dois canais, atendidos por times diferentes.
            // Sem este filtro, lançar Shopee sobrescreveria a linha do
            // Mercado Livre da mesma conta.
            $linha = DesempenhoMetricaManual::query()
                ->where('company_id', $companyId)
                ->where('fonte', $fonte)
                ->where('metrica', $metrica)
                ->whereDate('mes_referencia', $mesStr)
                ->lockForUpdate()
                ->first();

            $valorAnterior = $linha?->valor;

            if ($ativo) {
                $acao       = $linha === null ? 'lancado' : 'editado';
                $atributos  = [
                    'tipo'           => $tipo,
                    'valor'          => (float) $valor,
                    'valor_anterior' => $valorAnterior,
                    'ativo'          => true,
                ];
            } else {
                $acao      = 'revertido';
                $atributos = [
                    // D-02/D-12: o valor lançado sobrevive à reversão — quem
                    // religar a célula vê o que estava lá antes. O `tipo` vai
                    // junto: valor preservado sem o tipo que o interpreta
                    // seria lido como R$ ao religar, e 4,5 pontos virariam
                    // R$ 4,50 de faturamento.
                    'valor'          => $valorAnterior,
                    'valor_anterior' => $valorAnterior,
                    'ativo'          => false,
                ];
            }

            $atributos['lancado_por'] = $request->user()->id;
            $atributos['lancado_em']  = now();

            if ($linha === null) {
                DesempenhoMetricaManual::create($atributos + [
                    'company_id'     => $companyId,
                    'fonte'          => $fonte,
                    'mes_referencia' => $mesStr,
                    'metrica'        => $metrica,
                    'tipo'           => $tipo,
                ]);
            } else {
                $linha->fill($atributos)->save();
            }

            $this->bustarCacheDaEmpresa($companyId, $mes);

            return $acao;
        });

        // FORA da transação, de propósito: enfileirar dentro dela faria o
        // worker pegar o job antes do COMMIT e recomputar com o valor VELHO.
        //
        // Competência sem nota congelada não precisa disto — ela é calculada ao
        // vivo e o busting de cache acima já basta. O job sai vazio nesse caso;
        // quem decide é ele, não este controller, para a regra viver num lugar
        // só. Ver o docblock do job.
        AtualizarNotaAposMetricaManualJob::dispatch($companyId, $mesStr);

        $mensagens = [
            'lancado'   => 'Valor manual lançado. A nota de Desempenho será atualizada em instantes.',
            'editado'   => 'Valor manual atualizado. A nota de Desempenho será atualizada em instantes.',
            'revertido' => 'Métrica revertida para o valor automático — o lançamento anterior foi preservado no histórico.',
        ];

        return back()->with('success', $mensagens[$acao]);
    }

    // ═══ Montagem da grade ═════════════════════════════════════════════════

    /**
     * Os meses oferecidos no seletor: o corrente e os 11 anteriores, cada um
     * com o booleano `consolidada`. O mês selecionado SEMPRE entra na lista,
     * mesmo fora da janela. `consolidada` é hoje só um RÓTULO ("· consolidada"
     * no seletor + aviso amarelo na tela); não trava mais nada.
     *
     * @return \Illuminate\Support\Collection<int, array{valor: string, label: string, consolidada: bool}>
     */
    private function mesesDoSeletor(Carbon $mes): \Illuminate\Support\Collection
    {
        $corrente = Carbon::now()->startOfMonth();

        $valores = collect(range(0, self::MESES_NO_SELETOR - 1))
            ->map(fn (int $i) => $corrente->copy()->subMonthsNoOverflow($i))
            ->push($mes->copy())
            ->unique(fn (Carbon $m) => $m->format('Y-m'))
            ->sortByDesc(fn (Carbon $m) => $m->format('Y-m'));

        return $valores->map(fn (Carbon $m) => [
            'valor'       => $m->format('Y-m'),
            'label'       => $this->mesExtenso($m),
            // Rótulo informativo (não trava a edição desde 2026-08-31).
            'consolidada' => CompanyScoreSnapshotWriter::competenciaConsolidada($m),
        ])->values();
    }

    /**
     * Universo da grade + estado de cada célula.
     *
     * Empresa entra quando está `active = true` E tem pelo menos um vínculo
     * em `company_users` cujo serviço está em setor financeiramente elegível.
     * `distinct` é obrigatório: `company_users` tem VÁRIAS linhas por
     * (empresa, papel) desde a Fase 76 — uma por serviço — e sem ele a mesma
     * empresa apareceria duplicada na grade.
     *
     * `$fonteFiltro` e `$colaboradorId` são recorte de exibição sobre esse
     * universo (ver o docblock da classe), aplicados em dois pontos
     * diferentes de propósito: a EMPRESA sai da consulta quando não é da
     * carteira do colaborador (não vale carregar model nem lançamento de quem
     * não vai aparecer), mas o CANAL é filtrado só na hora de montar a linha
     * — `fontes`/`multi_canal` precisam continuar enxergando o canal
     * escondido.
     *
     * @return array<int, array<string, mixed>>
     */
    private function montarEmpresas(
        Carbon $mes,
        string $busca,
        ?string $fonteFiltro = null,
        ?int $colaboradorId = null
    ): array {
        // Mesma lista consumida pelo `StoreMetricaManualRequest` ao validar se
        // a empresa atende o canal escolhido — uma constante só, para grade e
        // validação nunca divergirem sobre o que conta como canal.
        $setoresElegiveis = Servico::SETORES_FINANCEIROS;

        // Carteira do colaborador filtrado: os pares `(empresa, canal)` que
        // ELE atende. `null` = sem filtro; `ids` vazio = filtraram alguém sem
        // carteira financeira nenhuma, e aí a grade é vazia mesmo.
        $carteira = $colaboradorId !== null ? $this->carteiraDoColaborador($colaboradorId) : null;

        if ($carteira !== null && $carteira['ids'] === []) {
            return [];
        }

        $vinculos = DB::table('company_users as cu')
            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->where('c.active', true)
            ->whereIn('s.setor', $setoresElegiveis)
            ->when($busca !== '', fn ($q) => $q->where('c.name', 'like', '%' . $busca . '%'))
            // Recorte por carteira só na EMPRESA. Os vínculos dos OUTROS
            // profissionais da mesma empresa continuam vindo — é deles que
            // saem `fontes` e `multi_canal`.
            ->when($carteira !== null, fn ($q) => $q->whereIn('cu.company_id', $carteira['ids']))
            ->distinct()
            ->select('c.id as company_id', 'c.name as company_name', 's.setor as setor')
            ->get();

        if ($vinculos->isEmpty()) {
            return [];
        }

        $companyIds = $vinculos->pluck('company_id')->unique()->map(fn ($id) => (int) $id)->values();

        // Modelos carregados com projeção explícita — nunca `->get()` cru
        // (o incidente de OOM de 2026-07-27 nasceu de carregar coluna
        // longtext que ninguém lia).
        $companies = Company::query()
            ->whereIn('id', $companyIds->all())
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace', 'active'])
            ->keyBy('id');

        // Fonte financeira vencedora por empresa — SEMPRE pelo resolvedor
        // único de D-10. Redigitar aqui o desempate ('adman' vence) criaria o
        // quarto call-site duplicado que a fase acabou de eliminar.
        $vinculosElegiveis = $vinculos->map(fn ($v) => [
            'company_id'       => (int) $v->company_id,
            'financial_source' => $v->setor === Servico::SETOR_SHOPEE ? 'shopee' : 'adman',
        ]);

        $fontePorEmpresa = $this->financialSourceResolver->resolverPorEmpresa($vinculosElegiveis, $companies);

        // Fontes elegíveis por empresa (para a tela saber se é loja Shopee,
        // Adman ou as duas). Ordenadas para o payload ser determinístico.
        $fontesPorEmpresa = $vinculosElegiveis
            ->groupBy('company_id')
            ->map(fn ($grupo) => $grupo->pluck('financial_source')->unique()->sort()->values()->all());

        // TODAS as linhas da competência (inclusive `ativo = false`): a célula
        // revertida para auto precisa continuar exibindo o valor preservado.
        $lancamentos = DesempenhoMetricaManual::query()
            ->whereIn('company_id', $companyIds->all())
            ->whereDate('mes_referencia', $mes->toDateString())
            ->get()
            ->keyBy(fn (DesempenhoMetricaManual $l) => "{$l->company_id}:{$l->fonte}:{$l->metrica}");

        // Resolvido na PRIMEIRA empresa que tiver lançamento — empresa sem
        // lançamento nenhum não dispara nem `resolve()` (T-136-17).
        $periodo = null;

        // UMA LINHA POR (empresa, canal) — não por empresa. A mesma conta pode
        // ser atendida por profissionais diferentes em marketplaces
        // diferentes, cada um com sua própria nota; a grade precisa deixar
        // lançar num canal sem tocar o outro. Empresa de canal único continua
        // rendendo exatamente uma linha, como antes.
        $linhas = $companies
            ->sortBy(fn (Company $company) => mb_strtolower((string) $company->name))
            ->flatMap(function (Company $company) use ($mes, $lancamentos, $fontesPorEmpresa, $fonteFiltro, $carteira, &$periodo) {
                // Fallback para o vencedor do desempate só quando a empresa não
                // tem nenhuma fonte elegível mapeada — caso de borda que não
                // deveria chegar aqui, mas não vale devolver linha sem canal.
                $fontesDaEmpresa = $fontesPorEmpresa->get($company->id, []);

                if ($fontesDaEmpresa === []) {
                    return [];
                }

                // Quais canais viram LINHA. A lista completa continua indo
                // para `linhaDaCelula()` em `$fontesDaEmpresa`: o canal
                // escondido pelo filtro ainda existe, e o selo "2 canais" tem
                // de seguir dizendo isso.
                $fontesVisiveis = collect($fontesDaEmpresa)
                    ->when(
                        $fonteFiltro !== null,
                        fn (Collection $fontes) => $fontes->filter(fn (string $f) => $f === $fonteFiltro)
                    )
                    ->when(
                        $carteira !== null,
                        fn (Collection $fontes) => $fontes->filter(
                            fn (string $f) => isset($carteira['pares']["{$company->id}:{$f}"])
                        )
                    );

                return $fontesVisiveis->map(fn (string $fonte) => $this->linhaDaCelula(
                    $company, $fonte, $fontesDaEmpresa, $mes, $lancamentos, $periodo
                ))->all();
            });

        return $linhas->values()->all();
    }

    // === Opcoes e recorte dos filtros ======================================

    /**
     * Opções do seletor de marketplace — os mesmos dois canais que a grade
     * sabe montar, com o rótulo humano de `FONTE_LABELS`.
     *
     * @return array<int, array{valor: string, label: string}>
     */
    private function fontesDoSeletor(): array
    {
        return collect(self::FONTE_LABELS)
            ->map(fn (string $label, string $valor) => ['valor' => $valor, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Quem pode ser filtrado como colaborador.
     *
     * A consulta é a MESMA que define o universo da grade (empresa ativa +
     * vínculo em setor financeiramente elegível), só que agrupada por
     * usuário: quem aparece no seletor tem, por construção, pelo menos uma
     * linha para mostrar — filtro que devolve grade vazia seria defeito de
     * tela, não resposta.
     *
     * Sem filtro por `users.active`: profissional desligado continua dono dos
     * vínculos na pivot, e escondê-lo do seletor tornaria a carteira dele
     * inalcançável. Ele vem marcado `ativo = false` para a tela poder avisar.
     *
     * `role` também não entra: a grade não filtra por papel (consultor,
     * estrategista e analista entram igual), e filtrar aqui esconderia
     * empresa que a grade mostra.
     *
     * @return array<int, array{id: int, nome: string, ativo: bool, total_empresas: int}>
     */
    private function colaboradoresDoSeletor(): array
    {
        return DB::table('company_users as cu')
            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->where('c.active', true)
            ->whereIn('s.setor', Servico::SETORES_FINANCEIROS)
            ->groupBy('u.id', 'u.name', 'u.active')
            ->orderBy('u.name')
            // `COUNT(DISTINCT company_id)`: a pivot tem uma linha por serviço
            // desde a Fase 76, e um `COUNT(*)` diria "12 empresas" onde há 7.
            ->select('u.id', 'u.name', 'u.active', DB::raw('COUNT(DISTINCT cu.company_id) as total_empresas'))
            ->get()
            ->map(fn ($linha) => [
                'id'             => (int) $linha->id,
                'nome'           => (string) $linha->name,
                'ativo'          => (bool) $linha->active,
                'total_empresas' => (int) $linha->total_empresas,
            ])
            ->all();
    }

    /**
     * Carteira financeira de UM colaborador, no formato que o recorte da
     * grade consome.
     *
     * `pares` é indexado por `"{company_id}:{fonte}"` porque o recorte é por
     * PAR, não por empresa: quem atende a Shopee de uma conta que também tem
     * Mercado Livre não deve ver a linha do outro canal como se fosse dele.
     * `ids` existe para a consulta de vínculos poder cortar empresa fora da
     * carteira antes de carregar model e lançamento.
     *
     * @return array{ids: array<int, int>, pares: array<string, bool>}
     */
    private function carteiraDoColaborador(int $userId): array
    {
        $linhas = DB::table('company_users as cu')
            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->where('c.active', true)
            ->whereIn('s.setor', Servico::SETORES_FINANCEIROS)
            ->where('cu.user_id', $userId)
            ->distinct()
            ->select('cu.company_id', 's.setor')
            ->get();

        $ids   = [];
        $pares = [];

        foreach ($linhas as $linha) {
            $companyId       = (int) $linha->company_id;
            $ids[$companyId] = $companyId;

            // Tradução setor → canal pelo método único do model — redigitar o
            // ternário aqui era exatamente o defeito que D-10 corrigiu.
            $pares[$companyId . ':' . Servico::fonteFinanceiraDoSetor($linha->setor)] = true;
        }

        return ['ids' => array_values($ids), 'pares' => $pares];
    }

    /**
     * Monta a linha da grade para UMA célula `(empresa, canal)`.
     *
     * `$periodo` é passado por referência de propósito: ele é resolvido na
     * primeira célula que tiver lançamento e reaproveitado por todas as
     * demais — empresa sem lançamento nenhum não dispara `resolve()`
     * (T-136-17).
     *
     * @param  array<int, string>  $fontesDaEmpresa
     * @param  Collection<string, DesempenhoMetricaManual>  $lancamentos
     */
    private function linhaDaCelula(
        Company $company,
        string $fonte,
        array $fontesDaEmpresa,
        Carbon $mes,
        Collection $lancamentos,
        ?array &$periodo
    ): array {
        $celulas = collect(DesempenhoMetricaManual::METRICAS)
            ->mapWithKeys(fn (string $metrica) => [
                $metrica => $lancamentos->get("{$company->id}:{$fonte}:{$metrica}"),
            ]);

        $temLancamento = $celulas->contains(
            fn (?DesempenhoMetricaManual $l) => $l !== null && ($l->ativo || $l->valor !== null)
        );

        $apiAquecida = false;
        $apiValores  = [DesempenhoMetricaManual::METRICA_FATURAMENTO => null, DesempenhoMetricaManual::METRICA_MARGEM_CMV => null];

        if ($temLancamento) {
            $periodo ??= $this->periodResolver->resolve(['period_key' => $mes->format('Y-m')]);

            // Fonte 'adman' só é consultada quando a janela já está em
            // cache; 'shopee' é leitura local e sempre barata.
            $apiAquecida = $fonte === 'shopee'
                || $this->admanMetricDiffService->isCached($company, $periodo);

            if ($apiAquecida) {
                $apiValores = $this->valoresDaApi($company, $periodo, $fonte);
            }
        }

        $porMetrica = $celulas->map(function (?DesempenhoMetricaManual $linha, string $metrica) use ($apiValores, $apiAquecida) {
            $celulaTemLancamento = $linha !== null && ($linha->ativo || $linha->valor !== null);

            $tipo = $linha?->tipo ?? DesempenhoMetricaManual::TIPO_VALOR;

            return [
                'ativo'          => (bool) ($linha?->ativo ?? false),
                'tipo'           => $tipo,
                'valor'          => $linha?->valor,
                'valor_anterior' => $linha?->valor_anterior,
                // D-02: o valor da API aparece ao lado do manual só
                // para célula COM lançamento — nunca para a base
                // inteira (T-136-17).
                //
                // Só faz sentido comparar com a API o que está na MESMA
                // grandeza: R$ contra R$. Célula lançada em % ou em ponto não
                // tem contraparte na API (a API não devolve "o ponto desta
                // loja"), e exibir o faturamento em reais embaixo de um "4,5
                // pontos" leria como divergência onde não há comparação.
                'api_valor'      => $celulaTemLancamento && $tipo === DesempenhoMetricaManual::TIPO_VALOR
                    ? $apiValores[$metrica]
                    : null,
                'api_aquecida'   => $celulaTemLancamento && $apiAquecida && $tipo === DesempenhoMetricaManual::TIPO_VALOR,
            ];
        });

        return [
            'company_id'    => $company->id,
            'company_name'  => $company->name,
            // O canal DESTA linha — é o que a tela exibe e o que volta no
            // POST. `fontes` continua listando todos os canais da empresa
            // para a tela poder sinalizar "esta conta tem mais de um time".
            'fonte'         => $fonte,
            'fonte_label'   => self::FONTE_LABELS[$fonte] ?? $fonte,
            'fontes'        => $fontesDaEmpresa,
            'multi_canal'   => count($fontesDaEmpresa) > 1,
            // Nomeados um a um: o payload da grade NÃO carrega
            // `lancado_por` nem nome de usuário (T-136-08) — o autor
            // existe no banco e no activity_log para auditoria
            // (D-12), não para exibição (D-04).
            'faturamento'   => $porMetrica->get(DesempenhoMetricaManual::METRICA_FATURAMENTO),
            'margem_cmv'    => $porMetrica->get(DesempenhoMetricaManual::METRICA_MARGEM_CMV),
        ];
    }

    /**
     * Valores da API para a célula, SEMPRE na janela de mês cheio resolvida
     * pelo `MetricPeriodResolver` — nunca cálculo de dias à mão (D-05).
     *
     * Faturamento é `metrics.revenue.value`. Para `margem_cmv` a API não expõe
     * um CMV: o comparável é o CMV IMPLÍCITO, `faturamento − margem de
     * contribuição em R$`. Loja Shopee devolve `null` aqui por construção — a
     * Shopee não fornece margem, que é justamente a razão de o CMV manual
     * existir nesta fase.
     *
     * @return array<string, ?float>
     */
    private function valoresDaApi(Company $company, array $periodo, string $fonte): array
    {
        $resultado = $this->diffDispatcher->compute($company, $periodo, $fonte);

        $faturamento = $resultado['metrics']['revenue']['value'] ?? null;
        $margemValor = $resultado['metrics']['contribution_margin_value']['value'] ?? null;

        return [
            DesempenhoMetricaManual::METRICA_FATURAMENTO => $faturamento,
            DesempenhoMetricaManual::METRICA_MARGEM_CMV  => ($faturamento !== null && $margemValor !== null)
                ? round((float) $faturamento - (float) $margemValor, 2)
                : null,
        ];
    }

    // ═══ Invalidação de cache ══════════════════════════════════════════════

    /**
     * Invalida o payload de Desempenho dos profissionais vinculados à empresa
     * naquela competência — a nota deles muda quando o valor manual entra ou
     * sai. Molde: `BonusAuditoriaController::bustarCacheDaEmpresa()`.
     *
     * Diferenças deliberadas em relação ao molde:
     *  - o escopo é `(empresa, competência)`, sem o ramo por `user_id`: aqui
     *    nada foi invalidado para a carteira inteira, só uma célula mudou;
     *  - só as linhas de origem `snapshot_diario`/`warm_cache` são apagadas.
     *    Linha de `consolidar_mes` NUNCA é tocada. Desde que a trava de D-09
     *    caiu (2026-08-31) esta competência PODE ter uma — é exatamente o
     *    caso de julho/2026 — e o filtro por `origem` virou a única coisa que
     *    segura a nota já fechada. Não trocar por um `delete()` sem `whereIn`:
     *    isso apagaria o snapshot pago junto com o cache.
     *
     * **Limpeza global de cache pelo Artisan é PROIBIDA aqui** (o comando de
     * limpar cache derrubou o site inteiro em 30/07/2026: apaga o cache
     * aquecido da Adman, o dashboard passa a esperar a API e as requisições
     * lentas ocupam os workers do php-fpm até o login parar). O `forget`
     * pontual por chave é o mecanismo — T-136-13.
     */
    private function bustarCacheDaEmpresa(int $companyId, Carbon $mes): void
    {
        $userIds = DB::table('company_users')
            ->where('company_id', $companyId)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            Cache::forget($this->scoreService->cacheKey((int) $userId, $mes));
        }

        DesempenhoCompanyScoreSnapshot::query()
            ->whereDate('mes_referencia', $mes->copy()->startOfMonth()->toDateString())
            ->where('company_id', $companyId)
            ->whereIn('origem', [
                CompanyScoreSnapshotWriter::ORIGEM_SNAPSHOT_DIARIO,
                CompanyScoreSnapshotWriter::ORIGEM_WARM_CACHE,
            ])
            ->delete();
    }

    /** Rótulo pt-BR da competência ("agosto/2026"). Molde: `BonusAuditoriaController`. */
    private function mesExtenso(Carbon $mes): string
    {
        $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $meses[$mes->month] . '/' . $mes->year;
    }
}
