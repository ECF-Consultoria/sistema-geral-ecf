<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Models\User;
use App\Services\Metrics\FinancialSourceResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 136, Plano 06 (D-11) — relatório READ-ONLY do impacto da correção do
 * desempate de fonte financeira, mais a contagem de células manuais da
 * competência.
 *
 * POR QUE ESTE COMANDO EXISTE: D-11 é explícito — esta fase **não**
 * reconsolida competência fechada. A régua de bonificação tem fronteira dura
 * em 4,00 e qualquer recompute mexe em pagamento de quem está perto dela
 * (`.planning/learnings/desempenho-bonificacao.md` §2: junho/2026 já foi
 * reconsolidado uma vez, saindo de 5 contemplados para 1). A reconsolidação
 * precisa ser ato SEPARADO e deliberado do usuário, com backup prévio em
 * `storage/app/private/backups/desempenho/`. Este comando é só o que coloca
 * o número na mesa para essa decisão — ele nunca a executa.
 *
 * É também a reconciliação contra produção que o RESEARCH desta fase exige
 * antes de considerar D-10 encerrado: o banco local já mentiu sobre o
 * `cust_id` de pelo menos uma empresa conhecida, e por isso o relatório
 * carrega os três booleanos de configuração de conta por empresa — sem eles
 * não dá para distinguir "empresa sem conta" de "conta configurada e nunca
 * sincronizada".
 *
 * READ-ONLY (mesmo molde de `desempenho:verificar-consolidacao`, D-122-10):
 * este comando não grava nada — nenhuma escrita em nenhuma tabela, nenhum
 * cache aquecido, nenhuma chamada ao serviço que monta o payload de nota
 * (isso dispararia HTTP síncrono à Adman, por empresa). Um verificador que
 * corrige o que encontra esconderia a inconsistência em vez de expô-la.
 *
 * PRIVACIDADE (learnings §11) — restrição dura: o relatório versiona apenas
 * CONTADORES por profissional (quantas empresas mudam de fonte). Ele não
 * calcula nem imprime a nota do profissional, a faixa de bonificação nem o
 * valor pago, e jamais pareia nome com qualquer um desses. Trazer dado de
 * compensação para o banco local não é autorização para versioná-lo. Além do
 * risco de privacidade, recalcular a nota chamaria o roteador de métricas
 * financeiras e quebraria a disciplina read-only acima.
 *
 * SEMÂNTICA DO EXIT CODE (o veredito é o exit code, nunca o texto impresso):
 *   0 (`SUCCESS`) — nenhuma empresa muda de fonte E nenhuma célula manual
 *                   ativa na competência: não há nada a decidir.
 *   1 (`FAILURE`) — existe impacto listado; a decisão de reconsolidar (ou
 *                   não) é do usuário, fora deste comando.
 *
 * O QUE ESTE COMANDO **NÃO** FAZ: o gate FIXMARG-03 (cobertura mínima de
 * margem que recusa congelar abaixo de 0,7) é movido por D-10 através de
 * `margem_amostra.legado.n_elegivel`, mas a conferência desse gate é feita
 * por `desempenho:verificar-consolidacao --mes=YYYY-MM --json`, cujo veredito
 * também é o EXIT CODE. Não é papel deste comando duplicá-la.
 * Armadilha de shell já registrada (learnings §4): `comando | tail -20; echo $?`
 * devolve o exit code do `tail`. Capture antes do pipe ou redirecione para
 * arquivo.
 *
 * @see App\Console\Commands\VerificarConsolidacaoDesempenho
 * @see App\Services\Metrics\FinancialSourceResolver
 * @see .planning/phases/136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-/136-06-PLAN.md
 */
class RelatorioImpactoFonteDesempenho extends Command
{
    protected $signature = 'desempenho:relatorio-impacto-fonte
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--json : saída em JSON, parseável, sem nenhum outro texto}';

    protected $description = 'Relatorio READ-ONLY do impacto da correcao do desempate de fonte financeira (D-10) e da contagem de celulas manuais da competencia. Exit code e o veredito, nunca o texto impresso.';

    /**
     * Papéis válidos na pivot `company_users` — mesma lista de
     * `CarteiraContextService::ROLES_VALIDOS`. A pivot nunca grava 'analista'
     * (o cargo vive em `user_setores`), então filtrar por estes dois é o que
     * reproduz o universo de carteira que o Desempenho enxerga.
     */
    private const ROLES_VALIDOS = ['consultor', 'estrategista'];

    public function __construct(private FinancialSourceResolver $financialSourceResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // Mesma regra de `ConsolidarMesDesempenho`/`VerificarConsolidacaoDesempenho`:
                // NUNCA `createFromFormat` sem o dia explícito — sem ele o PHP
                // preenche com o dia de hoje e estoura para o mês seguinte
                // quando o mês alvo é mais curto.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[RelatorioImpactoFonte] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        }

        $relatorio = $this->montarRelatorio($mes);

        if ($this->option('json')) {
            $this->line(json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprimirRelatorioHumano($relatorio);
        }

        $semImpacto = $relatorio['resumo']['total_empresas_divergentes'] === 0
            && $relatorio['resumo']['total_celulas_manuais'] === 0;

        return $semImpacto ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Monta o relatório inteiro por RECONSULTA ao banco — nenhuma escrita,
     * nenhum cache, nenhuma chamada ao motor de nota.
     */
    private function montarRelatorio(Carbon $mes): array
    {
        $vinculos        = $this->vinculosElegiveis();
        $fonteDivergente = $this->detectarDivergencias($vinculos);
        $profissionais   = $this->profissionaisAfetados($vinculos, $fonteDivergente);
        $celulasManuais  = $this->contarCelulasManuais($mes);

        return [
            'mes'                => $mes->format('Y-m'),
            'mes_referencia'     => $mes->toDateString(),
            'gerado_em'          => now()->toIso8601String(),
            'fonte_divergente'   => $fonteDivergente,
            'profissionais_afetados' => $profissionais,
            'celulas_manuais'    => $celulasManuais,
            'resumo'             => [
                'mes'                        => $mes->format('Y-m'),
                'total_empresas_divergentes' => count($fonteDivergente),
                'total_profissionais_afetados' => count($profissionais),
                'total_celulas_manuais'      => $celulasManuais['total'],
            ],
        ];
    }

    /**
     * Universo: vínculos de `company_users` cujo serviço pertence a um setor
     * financeiramente elegível (`performance` → 'adman', `shopee` → 'shopee',
     * exatamente o mapa de `CarteiraContextService::flagsFinanceirasPorSetor()`),
     * restrito a empresas ativas.
     *
     * `distinct` é obrigatório: `company_users` tem VÁRIAS linhas por
     * (empresa, papel) desde a Fase 76 — uma por serviço (learnings §7). Sem
     * ele a mesma empresa é contada em duas carteiras.
     *
     * @return Collection<int, array{company_id: int, user_id: int, financial_source: string}>
     */
    private function vinculosElegiveis(): Collection
    {
        $mapaFonte = [
            Servico::SETOR_PERFORMANCE => 'adman',
            Servico::SETOR_SHOPEE      => 'shopee',
        ];

        return DB::table('company_users as cu')
            ->join('companies as c', 'c.id', '=', 'cu.company_id')
            ->join('servicos as s', 's.id', '=', 'cu.servico_id')
            ->whereNotNull('cu.servico_id')
            ->whereIn('cu.role', self::ROLES_VALIDOS)
            ->whereIn('s.setor', array_keys($mapaFonte))
            ->where('c.active', true)
            ->select('cu.company_id', 'cu.user_id', 's.setor')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'company_id'       => (int) $row->company_id,
                'user_id'          => (int) $row->user_id,
                'financial_source' => $mapaFonte[$row->setor],
            ]);
    }

    /**
     * Seção 1 do relatório — a reconciliação: quais empresas mudam de fonte
     * financeira pela correção de D-10.
     *
     * Só empresas com MAIS DE UMA fonte concorrente entre os vínculos
     * elegíveis podem mudar de resultado; as demais nem entram na comparação.
     *
     * @param  Collection<int, array{company_id: int, user_id: int, financial_source: string}>  $vinculos
     * @return array<int, array<string, mixed>>
     */
    private function detectarDivergencias(Collection $vinculos): array
    {
        $candidatas = $vinculos
            ->groupBy('company_id')
            ->filter(fn (Collection $grupo) => $grupo->pluck('financial_source')->unique()->count() > 1);

        if ($candidatas->isEmpty()) {
            return [];
        }

        $companies = Company::query()
            ->whereIn('id', $candidatas->keys()->map(fn ($id) => (int) $id)->all())
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id'])
            ->keyBy('id');

        // A regra NOVA vem do resolvedor único do Plano 01 — nunca reescrita
        // aqui. Se ela mudar, este relatório muda junto, por construção.
        $fontesNovas = $this->financialSourceResolver->resolverPorEmpresa(
            $candidatas->values()->collapse(),
            $companies
        );

        $divergentes = [];

        foreach ($candidatas as $companyId => $grupo) {
            $companyId = (int) $companyId;
            $sources   = $grupo->pluck('financial_source');

            // ── REGRA REVOGADA (não copiar daqui) ─────────────────────────
            // Este é literalmente o desempate anterior a D-10: 'adman' vencia
            // sem nenhuma verificação de que a empresa tivesse conta Adman de
            // fato. Ele sobrevive EXCLUSIVAMENTE dentro deste comando, para
            // produzir a coluna "antes" do antes/depois — mesmo padrão do
            // toggle exclusivo de relatório de impacto em
            // `DesempenhoScoreService::setIncluirImputadas(false)`.
            // Nenhum outro arquivo do projeto pode reintroduzir esta linha.
            $fonteAntiga = $sources->contains('adman') ? 'adman' : $sources->first();

            $fonteNova = $fontesNovas->get($companyId);

            if ($fonteAntiga === $fonteNova) {
                continue;
            }

            /** @var Company|null $company */
            $company = $companies->get($companyId);

            $divergentes[] = [
                'company_id'   => $companyId,
                'company_name' => $company?->name,
                'fonte_antiga' => $fonteAntiga,
                'fonte_nova'   => $fonteNova,
                // Os três booleanos são o que torna o relatório utilizável
                // como reconciliação contra produção: sem eles, "empresa sem
                // conta" e "conta configurada e nunca sincronizada" ficam
                // indistinguíveis.
                'tem_adman_account_id' => filled($company?->adman_account_id),
                'tem_ml_store_id'      => filled($company?->ml_store_id),
                'cust_id_presente'     => $company?->cust_id !== null,
            ];
        }

        usort($divergentes, fn ($a, $b) => strcmp((string) $a['company_name'], (string) $b['company_name']));

        return $divergentes;
    }

    /**
     * Seção 2 do relatório — quem tem alguma das empresas divergentes na
     * carteira, apenas por CONTADOR.
     *
     * Deliberadamente NÃO calcula e NÃO imprime a nota do profissional, a
     * faixa de bonificação nem o valor pago (learnings §11: nome pareado com
     * resultado individual de bonificação não pode ser versionado). Recalcular
     * qualquer um desses números exigiria chamar o motor de nota, que dispara
     * HTTP síncrono à Adman por empresa — violando também o contrato
     * read-only declarado no docblock da classe.
     *
     * @param  Collection<int, array{company_id: int, user_id: int, financial_source: string}>  $vinculos
     * @param  array<int, array<string, mixed>>  $divergentes
     * @return array<int, array<string, mixed>>
     */
    private function profissionaisAfetados(Collection $vinculos, array $divergentes): array
    {
        if ($divergentes === []) {
            return [];
        }

        $idsDivergentes = collect($divergentes)->pluck('company_id')->flip();

        $porUsuario = $vinculos
            ->filter(fn (array $v) => $idsDivergentes->has($v['company_id']))
            ->groupBy('user_id');

        if ($porUsuario->isEmpty()) {
            return [];
        }

        $nomes = User::query()
            ->whereIn('id', $porUsuario->keys()->map(fn ($id) => (int) $id)->all())
            ->pluck('name', 'id');

        $afetados = [];

        foreach ($porUsuario as $userId => $grupo) {
            $userId     = (int) $userId;
            $companyIds = $grupo->pluck('company_id')->unique()->sort()->values()->all();

            $afetados[] = [
                'user_id'           => $userId,
                'user_name'         => $nomes->get($userId) ?? "user #{$userId}",
                'empresas_afetadas' => count($companyIds),
                'company_ids'       => $companyIds,
            ];
        }

        usort($afetados, fn ($a, $b) => $b['empresas_afetadas'] <=> $a['empresas_afetadas']
            ?: strcmp((string) $a['user_name'], (string) $b['user_name']));

        return $afetados;
    }

    /**
     * Seção 3 do relatório — quantas células manuais ativas existem na
     * competência, agrupadas por métrica.
     *
     * A contagem sai da TABELA PRÓPRIA (`desempenho_metricas_manuais`, fonte
     * de verdade indexada), nunca de agregação sobre o JSON do snapshot — foi
     * por isso que esta fase decidiu não criar coluna dedicada no snapshot.
     *
     * Ambiente sem a tabela (o Plano 02 pode não ter rodado) devolve zeros em
     * vez de explodir: um relatório de diagnóstico que quebra por dependência
     * ausente deixa de ser diagnóstico.
     *
     * @return array{total: int, por_metrica: array<string, int>, empresas_distintas: int, tabela_ausente: bool}
     */
    private function contarCelulasManuais(Carbon $mes): array
    {
        if (! Schema::hasTable('desempenho_metricas_manuais')) {
            return [
                'total'              => 0,
                'por_metrica'        => [],
                'empresas_distintas' => 0,
                'tabela_ausente'     => true,
            ];
        }

        $linhas = DesempenhoMetricaManual::ativasDaCompetencia($mes);

        return [
            'total'              => $linhas->count(),
            'por_metrica'        => $linhas->countBy('metrica')->all(),
            'empresas_distintas' => $linhas->pluck('company_id')->unique()->count(),
            'tabela_ausente'     => false,
        ];
    }

    /**
     * Saída padrão (sem `--json`). Esta saída é CONVENIÊNCIA HUMANA — nenhuma
     * conferência pode depender dela; o contrato é o `--json` e o exit code.
     */
    private function imprimirRelatorioHumano(array $relatorio): void
    {
        $this->info(sprintf(
            '[RelatorioImpactoFonte] Competência %s — %d empresa(s) mudam de fonte financeira, %d profissional(is) impactado(s), %d célula(s) manual(is) ativa(s).',
            $relatorio['mes'],
            $relatorio['resumo']['total_empresas_divergentes'],
            $relatorio['resumo']['total_profissionais_afetados'],
            $relatorio['resumo']['total_celulas_manuais'],
        ));

        if ($relatorio['fonte_divergente'] === []) {
            $this->line('  Nenhuma empresa muda de fonte financeira pela correção de D-10.');
        } else {
            $this->table(
                ['Empresa', 'ID', 'Antes', 'Depois', 'adman_account_id', 'ml_store_id', 'cust_id'],
                array_map(fn (array $e) => [
                    $e['company_name'] ?? '—',
                    $e['company_id'],
                    $e['fonte_antiga'],
                    $e['fonte_nova'],
                    $e['tem_adman_account_id'] ? 'sim' : 'não',
                    $e['tem_ml_store_id'] ? 'sim' : 'não',
                    $e['cust_id_presente'] ? 'sim' : 'não',
                ], $relatorio['fonte_divergente'])
            );
        }

        if ($relatorio['profissionais_afetados'] !== []) {
            $this->table(
                ['Profissional', 'user_id', 'Empresas afetadas'],
                array_map(fn (array $p) => [
                    $p['user_name'],
                    $p['user_id'],
                    $p['empresas_afetadas'],
                ], $relatorio['profissionais_afetados'])
            );
        }

        $celulas = $relatorio['celulas_manuais'];

        if ($celulas['tabela_ausente']) {
            $this->line('  Tabela de lançamentos manuais ausente neste ambiente — contagem reportada como zero.');
        } else {
            $linhasMetrica = [];
            foreach ($celulas['por_metrica'] as $metrica => $qtd) {
                $linhasMetrica[] = [$metrica, $qtd];
            }
            $linhasMetrica[] = ['(empresas distintas)', $celulas['empresas_distintas']];

            $this->table(['Métrica', 'Células ativas'], $linhasMetrica);
        }

        $this->warn('[RelatorioImpactoFonte] AVISO: estas tabelas são CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = nada a decidir) ou a saída --json — nunca este texto.');
        $this->warn('[RelatorioImpactoFonte] Este comando NÃO reconsolida nada (D-11). Reconsolidar competência fechada é ato separado e deliberado, com backup prévio em storage/app/private/backups/desempenho/.');
    }
}
