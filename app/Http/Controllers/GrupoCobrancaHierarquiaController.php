<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\GrupoFaixaFaturamento;
use App\Services\Fechamento\SimuladorGrupoCobrancaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * GrupoCobrancaHierarquiaController — pendura e despendura grupos na árvore
 * de COBRANÇA (`company_groups.parent_id`, Fase 143), e serve a prévia do
 * impacto antes da decisão.
 *
 * ## O que está em jogo
 * Pendurar um grupo em outro muda **quanto um cliente paga**, e para baixo:
 * no caso que abriu a fase (143-CONTEXT, D-02), juntar quatro grupos derruba
 * a mensalidade de R$ 33.500 para R$ 21.000 — R$ 150 mil por ano num cliente
 * só. Por isso:
 *
 * - a **prévia** (`previa()`) roda o `SimuladorGrupoCobrancaService`, que é
 *   puro, e mostra antes×depois com a procedência da tabela que governa o
 *   resultado;
 * - a **gravação** registra em `activity_log` quem pendurou, quais grupos,
 *   qual pai **e a prévia do impacto no momento da decisão** — molde:
 *   `GravarTabelaEmpresaService::registrarAuditoria()`.
 *
 * ## ⛔ A regra da árvore NÃO é duplicada aqui
 * A trava de um nível e a de ciclo vivem no `saving()` de `CompanyGroup`
 * (143-01) e lançam `\InvalidArgumentException` com mensagem já em pt-BR,
 * feita para a tela exibir sem tradução. Este controller só a converte em
 * erro de validação — nunca reimplementa a regra. Uma segunda cópia da
 * validação é o jeito clássico de a tela aceitar o que a gravação recusa (ou
 * pior: o contrário).
 *
 * ## ⛔ O NPS não sente nada
 * `companies.company_group_id` continua apontando para o subgrupo — nenhuma
 * empresa é remanejada de grupo aqui. É `company_group_id` que
 * `nps_group_surveys` e `NpsGrupoCoberturaService` usam, e nada neste
 * controller o toca.
 *
 * Permissão: `admin.contratos`, a mesma do módulo administrativo de
 * contratos — nenhuma permissão nova (D-09 da Fase 131).
 */
class GrupoCobrancaHierarquiaController extends Controller
{
    /** `log_name` próprio desta trilha, no molde de `faixa_faturamento_tabela`. */
    public const LOG_NAME = 'grupo_cobranca_hierarquia';

    public function __construct(private SimuladorGrupoCobrancaService $simulador)
    {
    }

    /**
     * GET /administrativo/contratos/grupos — a tela onde o grupo de cobrança
     * é montado (Fase 143, plano 04, T1).
     *
     * ## Por que esta tela é a razão de ser da fase
     * O usuário foi explícito (143-CONTEXT, D-06): *"vamos desenvolver a
     * correção para que, caso existam outros casos, seja possível resolver
     * pela UI"*. Só conhecemos o caso MPozenato, e **não dá para achar os
     * outros por dado** — 145 das 203 empresas estão sem CNPJ cadastrado.
     * A montagem é curadoria humana: sem esta tela, a fase inteira vira uma
     * migration manual disfarçada.
     *
     * ## O que as props precisam entregar
     * Cada grupo vem com **quantas empresas** e **quanto de cobrança** — é
     * o que dá noção de escala antes de mexer. Os números vêm de
     * `SimuladorGrupoCobrancaService::estadoAtual()`, que é a MESMA máquina
     * da prévia e do fechamento; nenhuma soma própria mora aqui.
     *
     * `tem_tabela_propria` é o que permite à tela avisar, ANTES de juntar,
     * que o grupo de cobrança ainda não tem tabela: montar sem ela faz a
     * cobrança seguir uma tabela copiada do serviço e cair mais do que
     * deveria (R$ 12.000 em vez de R$ 21.000, no caso real).
     */
    public function index(Request $request): \Inertia\Response
    {
        $dados = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);

        $mes = $dados['mes'] ?? $this->competenciaPadrao();

        $grupos = CompanyGroup::query()->orderBy('name')->get();

        // Uma query para as contagens — nunca uma consulta por linha dentro
        // do laço. Mesmo recorte de empresa do simulador (ativas), para a
        // contagem da tela não divergir da contagem da cobrança.
        $empresasPorGrupo = Company::where('active', true)
            ->whereNotNull('company_group_id')
            ->selectRaw('company_group_id, COUNT(*) as total')
            ->groupBy('company_group_id')
            ->pluck('total', 'company_group_id');

        // Quem tem tabela cadastrada em si mesmo — uma query.
        $comTabelaPropria = GrupoFaixaFaturamento::query()
            ->select('company_group_id')
            ->distinct()
            ->pluck('company_group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $estado = $this->simulador->estadoAtual($mes);
        $linhas = collect($estado['linhas'])->keyBy('company_group_id');

        $filhosPorPai = $grupos->groupBy('parent_id');

        $lista = $grupos
            ->whereNull('parent_id')
            ->map(function (CompanyGroup $grupo) use ($filhosPorPai, $empresasPorGrupo, $comTabelaPropria, $linhas) {
                $dentro = ($filhosPorPai[$grupo->id] ?? collect())
                    ->sortBy('name')
                    ->map(fn (CompanyGroup $filho) => [
                        'id'                 => $filho->id,
                        'nome'               => $filho->name,
                        'empresas_count'     => (int) ($empresasPorGrupo[$filho->id] ?? 0),
                        'tem_tabela_propria' => in_array($filho->id, $comTabelaPropria, true),
                    ])
                    ->values()
                    ->all();

                $empresasDoConjunto = (int) ($empresasPorGrupo[$grupo->id] ?? 0)
                    + collect($dentro)->sum('empresas_count');

                $linha = $linhas[$grupo->id] ?? null;

                return [
                    'id'                 => $grupo->id,
                    'nome'               => $grupo->name,
                    'cor'                => $grupo->color,
                    'empresas_count'     => $empresasDoConjunto,
                    'tem_tabela_propria' => in_array($grupo->id, $comTabelaPropria, true),
                    'dentro'             => $dentro,
                    // Os números da cobrança de hoje — ausentes quando o
                    // grupo não tem nenhuma empresa ativa (e por isso
                    // nenhuma linha de cobrança).
                    'faturamento_total'      => $linha['faturamento_total'] ?? null,
                    'cobranca_mensal'        => $linha['cobranca_mensal'] ?? null,
                    'faixa_label'            => $linha['faixa_label'] ?? null,
                    'procedencia'            => $linha['procedencia'] ?? null,
                    'tabela_origem'          => $linha['tabela_origem'] ?? null,
                    'tabela_grupo_nome'      => $linha['tabela_grupo_nome'] ?? null,
                    'tabela_servico_nome'    => $linha['tabela_servico_nome'] ?? null,
                    'tabela_herdada_de_nome' => $linha['tabela_herdada_de_nome'] ?? null,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => count($b['dentro']) <=> count($a['dentro']),
                fn (array $a, array $b) => strcasecmp($a['nome'], $b['nome']),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/GruposCobranca', [
            'mes'            => $mes,
            'grupos'         => $lista,
            'total_cobranca' => $estado['total_cobranca'],
        ]);
    }

    /**
     * POST /administrativo/contratos/grupos — cria um grupo de cobrança
     * VAZIO (Fase 143, plano 04, T1).
     *
     * ## Por que criar vem antes de juntar, e por que isso não fere a regra
     * da prévia obrigatória
     * A regra da fase é "nada que mude cobrança é gravado sem a prévia na
     * frente". Um grupo recém-criado não tem empresa nenhuma e não tem
     * nenhum grupo dentro dele: ele não produz linha de cobrança, não entra
     * em `fechamento:consolidar-mes` e não muda a fatura de ninguém. O que
     * muda cobrança é pendurar grupos nele — e *isso* continua exigindo a
     * prévia.
     *
     * A ordem é obrigatória por outro motivo, aliás: a tela precisa oferecer
     * o cadastro da tabela do grupo de cobrança ANTES de juntar (senão a
     * cobrança cai mais do que deveria), e não é possível cadastrar tabela
     * para um grupo que ainda não existe.
     *
     * ⚠️ Rota PRÓPRIA, dentro de `admin.contratos`, e não a
     * `company-groups.store` já existente: aquela está sob `role:admin`, e
     * quem recebeu `admin.contratos` por setor levaria 403 no meio do fluxo
     * — a mesma armadilha que a Fase 142 pagou para fechar.
     */
    public function criar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);

        $grupo = CompanyGroup::create([
            'name'  => $dados['name'],
            'color' => ($dados['color'] ?? null) ?: '#ffe600',
        ]);

        activity(self::LOG_NAME)
            ->causedBy($request->user())
            ->performedOn($grupo)
            ->withProperties(['depois' => [['id' => $grupo->id, 'nome' => $grupo->name, 'parent_id' => null]]])
            ->log("Grupo de cobrança \"{$grupo->name}\" criado, ainda sem nenhum grupo dentro dele.");

        return back()->with(
            'success',
            "Grupo de cobrança \"{$grupo->name}\" criado. Cadastre a tabela dele antes de juntar os grupos."
        );
    }

    /**
     * GET /administrativo/contratos/grupos/hierarquia/previa
     *
     * ⚠️ Leitura PURA — não grava nada. Responde JSON porque é uma consulta
     * feita de dentro da tela (o formulário só é submetido depois que a
     * pessoa leu o número).
     */
    public function previa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'grupo_ids'   => ['required', 'array', 'min:1'],
            'grupo_ids.*' => ['integer', 'exists:company_groups,id'],
            'pai_id'      => ['nullable', 'integer', 'exists:company_groups,id'],
            'mes'         => ['nullable', 'date_format:Y-m'],
        ]);

        try {
            return response()->json(
                $this->simulador->simular(
                    $dados['grupo_ids'],
                    isset($dados['pai_id']) ? (int) $dados['pai_id'] : null,
                    $dados['mes'] ?? $this->competenciaPadrao(),
                )
            );
        } catch (\InvalidArgumentException $e) {
            // A mesma mensagem que a gravação recusaria — a prévia de um
            // arranjo impossível seria pior que nenhuma prévia.
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /administrativo/contratos/grupos/hierarquia — pendura um ou mais
     * grupos num pai.
     */
    public function pendurar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'grupo_ids'   => ['required', 'array', 'min:1'],
            'grupo_ids.*' => ['integer', 'exists:company_groups,id'],
            'pai_id'      => ['required', 'integer', 'exists:company_groups,id'],
            'mes'         => ['nullable', 'date_format:Y-m'],
        ]);

        $grupoIds = collect($dados['grupo_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $paiId    = (int) $dados['pai_id'];
        $mes      = $dados['mes'] ?? $this->competenciaPadrao();

        $pai = CompanyGroup::findOrFail($paiId);

        $this->aplicar($grupoIds, $paiId, $mes, $pai);

        return back()->with(
            'success',
            $grupoIds->count() === 1
                ? "Grupo passou a fazer parte de \"{$pai->name}\" para efeito de cobrança."
                : "{$grupoIds->count()} grupos passaram a fazer parte de \"{$pai->name}\" para efeito de cobrança."
        );
    }

    /**
     * DELETE /administrativo/contratos/grupos/hierarquia — despendura (volta
     * `parent_id` para `null`), fazendo cada grupo voltar a ser cobrado
     * sozinho.
     */
    public function despendurar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'grupo_ids'   => ['required', 'array', 'min:1'],
            'grupo_ids.*' => ['integer', 'exists:company_groups,id'],
            'mes'         => ['nullable', 'date_format:Y-m'],
        ]);

        $grupoIds = collect($dados['grupo_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $mes      = $dados['mes'] ?? $this->competenciaPadrao();

        // Sujeito da trilha: a raiz de onde os grupos estão saindo (quando
        // todos vêm da mesma). É lá que a cobrança vai mudar.
        $raizDeOrigem = CompanyGroup::whereIn('id', $grupoIds)->pluck('parent_id')->filter()->unique();
        $sujeito      = $raizDeOrigem->count() === 1 ? CompanyGroup::find($raizDeOrigem->first()) : null;

        $this->aplicar($grupoIds, null, $mes, $sujeito);

        return back()->with(
            'success',
            $grupoIds->count() === 1
                ? 'Grupo voltou a ser cobrado sozinho.'
                : "{$grupoIds->count()} grupos voltaram a ser cobrados sozinhos."
        );
    }

    /**
     * Grava `parent_id` e registra a trilha — numa transação só, para uma
     * recusa no meio do caminho não deixar metade dos grupos pendurados.
     *
     * ⚠️ A prévia é calculada ANTES da escrita: é o número que a pessoa viu
     * quando decidiu, e é ele que precisa ficar registrado. Calcular depois
     * daria o retrato do mundo já mudado, que não serve para auditar a
     * decisão.
     *
     * @param  Collection<int, int>  $grupoIds
     */
    private function aplicar(Collection $grupoIds, ?int $paiId, string $mes, ?CompanyGroup $sujeito): void
    {
        DB::transaction(function () use ($grupoIds, $paiId, $mes, $sujeito) {
            $grupos = CompanyGroup::whereIn('id', $grupoIds)->get();

            $antes = $grupos->map(fn (CompanyGroup $g) => [
                'id'        => $g->id,
                'nome'      => $g->name,
                'parent_id' => $g->parent_id,
            ])->values()->all();

            try {
                $previa = $this->simulador->simular($grupoIds->all(), $paiId, $mes);
            } catch (\InvalidArgumentException $e) {
                // Mensagem do model (143-01), já em pt-BR e sem jargão.
                throw ValidationException::withMessages(['grupo_ids' => $e->getMessage()]);
            }

            foreach ($grupos as $grupo) {
                $grupo->parent_id = $paiId;

                try {
                    // ⚠️ A trava de um nível e a de ciclo rodam no `saving()`
                    // do model — não há validação duplicada aqui de propósito.
                    $grupo->save();
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['grupo_ids' => $e->getMessage()]);
                }
            }

            $depois = $grupos->map(fn (CompanyGroup $g) => [
                'id'        => $g->id,
                'nome'      => $g->name,
                'parent_id' => $g->parent_id,
            ])->values()->all();

            $this->registrarAuditoria($antes, $depois, $paiId, $mes, $previa, $sujeito);
        });
    }

    /**
     * UMA entrada de `activity_log` por operação, com o antes/depois inteiro
     * e a prévia do impacto — mesma disciplina de
     * `GravarTabelaEmpresaService::registrarAuditoria()` (uma entrada com o
     * quadro completo vale mais, para quem audita uma cobrança errada, que N
     * entradas soltas por linha).
     *
     * @param  array<int, array>  $antes
     * @param  array<int, array>  $depois
     * @param  array<string, mixed>  $previa
     */
    private function registrarAuditoria(
        array $antes,
        array $depois,
        ?int $paiId,
        string $mes,
        array $previa,
        ?CompanyGroup $sujeito,
    ): void {
        $nomes = collect($depois)->pluck('nome')->filter()->implode(', ');

        $descricao = $paiId === null
            ? "Grupo(s) \"{$nomes}\" voltaram a ser cobrados sozinhos — a cobrança deixa de ser agregada."
            : "Grupo(s) \"{$nomes}\" passaram a ser cobrados dentro de \"{$previa['pai_nome']}\".";

        $log = activity(self::LOG_NAME)->causedBy(auth()->user());

        if ($sujeito !== null) {
            $log->performedOn($sujeito);
        }

        $log->withProperties([
            'antes'  => $antes,
            'depois' => $depois,
            'pai_id' => $paiId,
            'mes'    => $mes,
            // A prévia do impacto NO MOMENTO DA DECISÃO — o número que a
            // pessoa viu antes de aprovar. Só os totais e as linhas, sem os
            // models: a trilha precisa ser legível daqui a um ano.
            'previa' => [
                'total_cobranca_antes'  => $previa['antes']['total_cobranca'],
                'total_cobranca_depois' => $previa['depois']['total_cobranca'],
                'delta'                 => $previa['delta'],
                'linhas_antes'          => $previa['antes']['linhas'],
                'linhas_depois'         => $previa['depois']['linhas'],
            ],
        ])->log($descricao);
    }

    /**
     * Competência padrão da prévia: o **mês anterior**, que é o último
     * mês-calendário COMPLETO. O mês corrente ainda está somando faturamento
     * e daria um número menor que o real — quem decide sobre mensalidade
     * precisa do último mês fechado, não de um mês pela metade.
     */
    private function competenciaPadrao(): string
    {
        return Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }
}
