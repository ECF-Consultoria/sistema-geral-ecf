<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarRelatorioFechamentoJob;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Models\ShopeeMetric;
use App\Services\AdmanService;
use App\Services\Fechamento\FechamentoComparativoService;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\FechamentoRegraTabela;
use App\Services\Fechamento\FechamentoRollupService;
use App\Support\CobrancaCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private FechamentoRollupService $rollupService,
        private FechamentoFaixaResolver $faixaResolver,
        private FechamentoComparativoService $comparativoService,
        private FechamentoRegraTabela $regra,
    ) {}

    public function empresas()
    {
        // Phase 14 (Frente B): eager loading de contratosServico + servico para
        // popular a chave nova `servicos_contratados` sem N+1. As chaves legacy
        // estratégia de COEXISTÊNCIA — serão removidas no Plan 14-06 junto com
        // o drop das colunas.
        $companies = Company::orderBy('name')
            ->with([
                'filhas:id,name,parent_company_id',
                'pai:id,name',
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            ->get()
            ->map(fn (Company $c) => [
                'id'                       => $c->id,
                'name'                     => $c->name,
                'active'                   => $c->active,
                'parent_company_id'        => $c->parent_company_id,
                'nome_pai'                 => $c->pai?->name,
                'filhas'                   => $c->filhas->map(fn($f) => ['id' => $f->id, 'name' => $f->name])->values(),
                // cust id (para busca) + grupo nomeado (mesmo sistema de /companies)
                'adman_account_id'         => $c->adman_account_id,
                'ml_store_id'              => $c->ml_store_id,
                'company_group_id'         => $c->company_group_id,
                'grupo'                    => $c->grupo ? ['id' => $c->grupo->id, 'name' => $c->grupo->name, 'color' => $c->grupo->color] : null,
                // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                // ─── Chave nova (modelo N:N de contratos) ────────────────
                'servicos_contratados'     => $c->contratosServico->where('ativo', true)->map(fn($ct) => [
                    'id'               => $ct->id,
                    'servico_id'       => $ct->servico_id,
                    'servico_nome'     => $ct->servico?->nome,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'tipo_cobranca'    => $ct->servico?->tipo_cobranca,
                    'data_contratacao' => $ct->data_contratacao?->toDateString(),
                    'data_vencimento'  => $ct->data_vencimento?->toDateString(),
                    'ativo'            => true,
                ])->values()->toArray(),
            ]);

        $servicosDisponiveis = \App\Models\Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        // Grupos nomeados (company_groups) — mesmo sistema de /companies.
        $grupos = \App\Models\CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('Admin/Empresas', [
            'companies' => $companies,
            'servicos_disponiveis' => $servicosDisponiveis,
            'grupos' => $grupos,
        ]);
    }

    public function updateEmpresa(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'parent_company_id'        => ['nullable', 'exists:companies,id', Rule::notIn([$company->id])],
            'filha_ids'                => 'nullable|array',
            'filha_ids.*'              => ['integer', 'exists:companies,id', Rule::notIn([$company->id])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $dados = $validator->validated();

        // Atualiza vínculos de filhas se enviado
        if (array_key_exists('filha_ids', $dados)) {
            $filhaIds = $dados['filha_ids'] ?? [];
            // Remove vínculo das filhas anteriores desta empresa que não estão na nova lista
            Company::where('parent_company_id', $company->id)
                ->whereNotIn('id', $filhaIds)
                ->update(['parent_company_id' => null]);
            // Vincula as novas filhas
            if (!empty($filhaIds)) {
                Company::whereIn('id', $filhaIds)->update(['parent_company_id' => $company->id]);
            }
            unset($dados['filha_ids']);
        }

        $company->update($dados);

        return back()->with('success', 'Empresa atualizada.');
    }

    public function relatorio()
    {
        return Inertia::render('Admin/Relatorio');
    }

    public function fechamento(Request $request)
    {
        // Fase 141 (D-01/D-02/D-03/T-141-19) — lida UMA vez, nunca dentro
        // de laço: passada adiante para os cinco montadores de linha, o
        // mesmo padrão de `ConsolidarMesFechamento::handle()`. `grep -n
        // "regraNova"` deste arquivo precisa alcançar os cinco métodos —
        // esquecer um é exatamente o defeito recorrente desta linha de
        // trabalho (o quinto ramo ficando pra trás).
        $regraNova = $this->regra->ativa();

        // Determina o mês de referência — padrão: mês corrente (mesma
        // validação de sempre: mês futuro é recusado e cai no corrente).
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }

        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado   = $ref->format('Y-m');
        $mesReferenciaStr = $ref->copy()->startOfMonth()->toDateString();

        // D-06 — acaba o acumulativo E a janela móvel de 30 dias: toda
        // competência é mês-calendário fechado, via a ÚNICA implementação
        // (FechamentoRollupService::janela(), que delega ao MetricPeriodResolver).
        $janela = $this->rollupService->janela($mesSelecionado);
        $inicio = $janela['inicio'];
        $fim    = $janela['fim'];

        // D-11 — bifurcação fechada x aberta. Competência já congelada por
        // `fechamento:consolidar-mes` NUNCA recalcula ao vivo — mesma
        // disciplina do módulo de Desempenho.
        $competenciaFechada = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->exists();

        $competenciaFechadaEm = null;
        if ($competenciaFechada) {
            $maxGeradoEm = FechamentoSnapshot::query()
                ->whereDate('mes_referencia', $mesReferenciaStr)
                ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
                ->max('gerado_em');
            $competenciaFechadaEm = $maxGeradoEm ? Carbon::parse($maxGeradoEm)->toIso8601String() : null;
        }

        // Passo 1 — carrega empresas ativas com relações de grupo + contratos ativos
        // Phase 14 (Frente B): eager loading de contratosServico.servico evita N+1
        // ao calcular cobrança_mensal via CobrancaCalculator::novo (Pitfall 2 RESEARCH).
        $rawCompanies = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            ->orderBy('name')
            ->get();

        // Quick 260904-kwz — quais empresas têm contrato assinado no
        // sistema, uma consulta em massa ANTES do laço (mesmo padrão de
        // `FechamentoComparativoService`, T-138-11): é o segundo caminho de
        // confirmação da tabela aplicada (o primeiro é o cadastro manual,
        // já visível em `tabela_origem`). Sem contrato assinado NEM cadastro
        // manual, a tabela do serviço foi só presumida.
        $companyIdsComContratoAssinado = $this->fechamentoCompanyIdsComContratoAssinado();

        // Fase 141 (T-141-18) — a procedência ATUAL da tabela própria de cada
        // empresa é lida DENTRO de `fechamentoDadosPorEmpresaCongelados()`
        // (Fase 142, 142-01) — a mesma consulta em lote que também traz as
        // LINHAS da tabela (`tabela_faixas`), então uma query só cobre as
        // duas necessidades em vez de duas consultas separadas na mesma
        // tabela. Os ramos AO VIVO (1/4) já recebem a procedência pronta no
        // shape do resolver (`$faixaData['procedencia']`/`$faixaAncora['procedencia']`),
        // sem precisar de nenhuma consulta aqui.
        //
        // Passo 2 — números por empresa: congelado (snapshot) ou ao vivo (rollup + resolver).
        $dadosPorId = $competenciaFechada
            ? $this->fechamentoDadosPorEmpresaCongelados($rawCompanies, $mesReferenciaStr, $inicio, $fim, $companyIdsComContratoAssinado)
            : $this->fechamentoDadosPorEmpresaAoVivo($rawCompanies, $mesSelecionado, $inicio, $fim, $companyIdsComContratoAssinado, $regraNova);

        // Quick 260909-e8n — corta quem não é cobrado por este fechamento
        // (pendente / sem serviço / só Polos), com as travas do helper.
        // ANTES da agregação: assim a soma de nenhum grupo muda.
        $dadosPorId = $this->fechamentoRemoverForaDeEscopo($dadosPorId, $rawCompanies);

        // Passo 3 — agregação por CompanyGroup (D-08/D-09/D-10). parent_company_id
        // NUNCA participa da soma, faixa ou conta_no_total a partir daqui.
        $dadosPorId = $competenciaFechada
            ? $this->fechamentoAgregarGruposCongelados($dadosPorId, $rawCompanies, $mesReferenciaStr, $companyIdsComContratoAssinado)
            : $this->fechamentoAgregarGruposAoVivo($dadosPorId, $rawCompanies, $mesReferenciaStr, $companyIdsComContratoAssinado, $regraNova);

        // Fase 139 (D-01/D-04, item 3) — números do topo da tela ("Total a
        // receber" + widget de upgrades). Somado sobre as MESMAS linhas que
        // a tela lista ($dadosPorId já agregado por grupo), nunca por uma
        // consulta paralela — T-139-05 do threat model desta fase.
        $totais = $this->fechamentoTotais($dadosPorId, $mesReferenciaStr);

        // Passo 4 — progressão SEM acumulado (D-06), sempre a partir do
        // histórico congelado (fechamento_snapshots/fechamento_grupo_snapshots),
        // nunca de company_monthly_revenues.
        $progressaoPorEmpresa = FechamentoSnapshot::query()
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->whereDate('mes_referencia', '<=', $mesReferenciaStr)
            ->orderBy('mes_referencia')
            ->get()
            ->groupBy('company_id');

        $progressaoPorGrupo = FechamentoGrupoSnapshot::query()
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->whereDate('mes_referencia', '<=', $mesReferenciaStr)
            ->orderBy('mes_referencia')
            ->get()
            ->groupBy('company_group_id');

        foreach ($dadosPorId as &$linha) {
            $historico = (($linha['tipo'] ?? 'empresa') === 'grupo')
                ? $progressaoPorGrupo->get($linha['company_group_id'] ?? null, collect())
                : $progressaoPorEmpresa->get($linha['id'], collect());

            $linha['progressao'] = $historico->map(fn ($s) => [
                'mes'         => Carbon::parse($s->mes_referencia)->format('Y-m'),
                'mensal'      => $s->faturamento_total !== null ? (float) $s->faturamento_total : null,
                'faixa'       => $s->faixa_aplicada,
                'faixa_label' => $s->faixa_aplicada,
                'valor_faixa' => $s->valor_faixa !== null ? (float) $s->valor_faixa : null,
                'evolucao'    => $s->evolucao,
            ])->values()->all();
        }
        unset($linha);

        // Phase 14 (Frente B): catálogo de serviços ativos para popular o select
        // do modal "Adicionar contrato" na UI do Fechamento — mesmo padrão de
        // CompanyController::show().
        $servicosDisponiveis = Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Admin/Financeiro', [
            'companies'              => array_values($dadosPorId),
            'mes_selecionado'        => $mesSelecionado,
            'servicos_disponiveis'   => $servicosDisponiveis,
            'competencia_fechada'    => $competenciaFechada,
            'competencia_fechada_em' => $competenciaFechadaEm,
            // Quick 260909-e8n — o período APURADO, ao lado da data em que o
            // fechamento foi executado. Sem isto a tela mostrava só
            // "Fechado em 03/09" numa competência de agosto e parecia que o
            // período apurado ia até setembro.
            'periodo'                => [
                'inicio' => $inicio->format('d/m/Y'),
                'fim'    => $fim->format('d/m/Y'),
            ],
            'faixas_por_servico'     => $this->fechamentoFaixasPorServico(),
            'faixas_por_grupo'       => $this->fechamentoFaixasPorGrupo(),
            // Fase 139 (D-01/D-04, item 3): números do topo da tela.
            'totais'                 => $totais,
            // Fase 141 (D-01/D-02/D-03) — a tela usa esta prop só para
            // decidir COPY (ex.: "Valor fixo do contrato" em vez de "A
            // DEFINIR"), nunca para recalcular nada no frontend.
            'regra_nova_ativa'       => $regraNova,
        ]);
    }

    /**
     * `servicos_contratados` no shape que a tela consome — extraído para
     * reuso entre o ramo ao vivo e o congelado do fechamento (Fase 137).
     */
    private function fechamentoServicosContratados(Company $c): array
    {
        return $c->contratosServico->where('ativo', true)->map(fn ($ct) => [
            'id'               => $ct->id,
            'servico_id'       => $ct->servico_id,
            'servico_nome'     => $ct->servico?->nome,
            'valor_contratado' => (float) $ct->valor_contratado,
            'tipo_cobranca'    => $ct->servico?->tipo_cobranca,
            'data_contratacao' => $ct->data_contratacao?->toDateString(),
            'data_vencimento'  => $ct->data_vencimento?->toDateString(),
            'ativo'            => true,
        ])->values()->toArray();
    }

    /**
     * Fase 139 (D-04) — deriva `subiu_de_faixa` e `ganho_faixa` a partir da
     * ordem/valor ATUAL e ANTERIOR. Extraído para os cinco array literais de
     * linha (empresa e grupo, ao vivo e congelado) nunca divergirem entre si
     * — é exatamente esse tipo de divergência que já fez um dado morrer no
     * último trecho três vezes nesta linha de trabalho.
     *
     * `subiu_de_faixa` só é `true` quando as duas ordens existem e a atual é
     * maior — nunca inferido de `evolucao` (string, pode existir sem ordem).
     * `ganho_faixa` só é calculado quando subiu E os dois valores existem;
     * nos demais casos fica `null` — nunca `0.0` (zero é "subiu e a
     * mensalidade não mudou", informação diferente de "não sabemos").
     *
     * @return array{subiu_de_faixa: bool, ganho_faixa: float|null}
     */
    private function fechamentoDerivarUpgrade(?int $faixaOrdemAtual, ?float $valorMensalAtual, ?int $faixaOrdemAnterior, ?float $valorFaixaAnterior): array
    {
        $subiu = $faixaOrdemAtual !== null && $faixaOrdemAnterior !== null && $faixaOrdemAtual > $faixaOrdemAnterior;

        $ganho = ($subiu && $valorMensalAtual !== null && $valorFaixaAnterior !== null)
            ? $valorMensalAtual - $valorFaixaAnterior
            : null;

        return ['subiu_de_faixa' => $subiu, 'ganho_faixa' => $ganho];
    }

    /**
     * Quick 260904-kwz — a tabela aplicada tem confirmação (cadastro manual
     * OU contrato assinado no sistema) ou foi só presumida a partir do
     * serviço? Extraído para os CINCO literais de linha (empresa e grupo,
     * ao vivo e congelado) nunca divergirem entre si — é a mesma classe de
     * defeito que `fechamentoDerivarUpgrade()` já existe para evitar.
     *
     * Dois caminhos de confirmação (medido em produção em 2026-09-04: 127
     * de 167 empresas com tabela vinda do serviço não tinham nenhum dos
     * dois, R$ 460.500/mês sem confirmação):
     *  1. Cadastro manual — `$tabelaOrigem` é 'propria' (exceção da própria
     *     empresa) ou 'grupo' (tabela do grupo, Fase 138). Confirmado sem
     *     precisar olhar contrato nenhum.
     *  2. `$tabelaOrigem` é 'servico' (herdada do contrato de serviço,
     *     nunca cadastrada à mão) — só confirmado se a empresa DONA da
     *     tabela (a própria empresa nas linhas de empresa; a âncora nas
     *     linhas de grupo sem tabela própria) tiver um `ContratoAssinatura`
     *     com `status = 'assinado'` no sistema — é a tabela escrita nesse
     *     contrato que legitima a do serviço.
     *
     * `null` quando `$tabelaOrigem` é null — não existe tabela nenhuma
     * (estado "A DEFINIR"), a pergunta "está confirmada?" não se aplica e
     * não pode virar `false` (isso pintaria uma pendência que já é outra,
     * distinta, mais grave).
     */
    /**
     * Quick 260904-kwz — consulta em massa (uma só, nunca uma por linha,
     * T-138-11) de quais empresas têm `ContratoAssinatura` com
     * `status = 'assinado'` no sistema. Compartilhada pelos três endpoints
     * que montam linhas de fechamento (`fechamento()`, `gerarRelatorio()`,
     * `gerarRelatorioGeral()`) para os três nunca divergirem na mesma
     * pergunta.
     */
    private function fechamentoCompanyIdsComContratoAssinado(): Collection
    {
        return ContratoAssinatura::query()
            ->where('status', ContratoAssinatura::STATUS_ASSINADO)
            ->distinct()
            ->pluck('company_id')
            ->flip();
    }

    /**
     * Fase 141 (D-04/D-05, T-141-18) — `$procedenciaTabela` é a coluna
     * `origem` da tabela PRÓPRIA da empresa dona (`EmpresaFaixaFaturamento`),
     * só relevante quando `$tabelaOrigem === 'propria'`. Uma tabela copiada
     * da tabela do serviço na virada (`ORIGEM_PRESUMIDA_SERVICO`, comando
     * `fechamento:materializar-tabelas`) é exatamente a presunção que esta
     * fase existe para tornar visível — deixá-la passar por confirmada só
     * porque a origem virou 'propria' reintroduziria em silêncio o mesmo
     * problema que a Quick 260904-kwz resolveu para a tabela do serviço.
     */
    private function fechamentoTabelaConfirmada(?string $tabelaOrigem, ?int $companyIdDaTabela, Collection $companyIdsComContratoAssinado, ?string $procedenciaTabela = null): ?bool
    {
        if ($tabelaOrigem === null) {
            return null;
        }

        if ($tabelaOrigem === 'propria' && $procedenciaTabela === EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO) {
            return false;
        }

        if (in_array($tabelaOrigem, ['propria', 'grupo'], true)) {
            return true;
        }

        return $companyIdDaTabela !== null && $companyIdsComContratoAssinado->has($companyIdDaTabela);
    }

    /**
     * Fase 139 (D-01/D-04, item 3) — números do topo da tela ("Total a
     * receber" + widget "Subiram de faixa"), somados sobre as MESMAS linhas
     * de `$dadosPorId` já agregadas por grupo (Passo 3 de `fechamento()`) —
     * nunca uma consulta paralela ao banco. Divergência entre o topo e a
     * lista abaixo seria invisível e minaria a confiança no fechamento
     * (T-139-05 do threat model desta fase).
     *
     * ⚠️ A chave morta (mensalidade "de grupo" que a tela antiga esperava e
     * o backend nunca emitiu) não é ressuscitada aqui — a linha de grupo já
     * carrega o valor somado em `cobranca_mensal` (ver `<interfaces>` do
     * plano 02).
     *
     * @param  array<int|string, array<string, mixed>>  $dadosPorId  linhas já agregadas por grupo, ANTES da progressão (Passo 4)
     * @return array{total_a_receber: float, total_e_piso: bool, empresas_com_cobranca: int, empresas_sem_valor_definido: int, faturamento_gerado: float|null, mes_anterior_fechado: bool, mes_anterior_total: float|null, variacao: float|null, upgrades_quantidade: int, upgrades_ganho_total: float, upgrades_ganho_parcial: bool, tabelas_assumidas: int}
     */
    private function fechamentoTotais(array $dadosPorId, string $mesReferenciaStr): array
    {
        $totalAReceber            = 0.0;
        $totalEPiso               = false;
        $empresasComCobranca      = 0;
        $empresasSemValorDefinido = 0;
        $faturamentoGerado        = null;
        $upgradesQuantidade       = 0;
        $upgradesGanhoTotal       = 0.0;
        $upgradesGanhoParcial     = false;
        // Quick 260904-kwz — contador do topo da tela: quantas linhas estão
        // com tabela presumida do serviço, sem cadastro manual nem contrato
        // assinado. Mesma disciplina de `conta_no_total` das outras somas
        // desta função — empresa-membro de grupo já está contada na linha
        // do grupo.
        $tabelasAssumidas         = 0;

        foreach ($dadosPorId as $linha) {
            // Empresa-membro de um grupo já está contada na linha do grupo
            // — somar as duas contaria a mesma cobrança duas vezes (mesma
            // disciplina de `conta_no_total` que a tela usa pra listar).
            if (($linha['conta_no_total'] ?? true) === false) {
                continue;
            }

            // `cobranca_mensal` null NÃO entra na soma como zero — a linha
            // some da soma silenciosamente, deixando o total menor que a
            // realidade sem ninguém perceber (a mesma classe de erro que o
            // D-05 proíbe). Ela é nomeada em `empresas_sem_valor_definido`
            // quando o estado é `sem_tabela`.
            if ($linha['cobranca_mensal'] !== null) {
                $cobrancaLinha = (float) $linha['cobranca_mensal'];
                $totalAReceber += $cobrancaLinha;

                if ($cobrancaLinha > 0) {
                    $empresasComCobranca++;
                }
            }

            // Uma única linha em faixa-piso torna o TOTAL inteiro um piso —
            // a tela precisa escrever "a partir de R$ X", nunca o valor
            // seco (mesma disciplina de `fmtValorFaixa` já vigente).
            if (($linha['valor_faixa_e_piso'] ?? false) === true) {
                $totalEPiso = true;
            }

            // Fase 141 (D-03) — `ESTADO_VALOR_FIXO` é resultado normal (a
            // empresa tem cobrança, só não vem de tabela), nunca pendência.
            // Não entra aqui de propósito: é uma constante DISTINTA de
            // `ESTADO_SEM_TABELA`, então esta soma já a exclui sem precisar
            // de bifurcação por `$regraNova` — o `cobranca_mensal` dela
            // ainda soma normalmente em `total_a_receber` acima.
            if (($linha['estado'] ?? null) === FechamentoSnapshot::ESTADO_SEM_TABELA) {
                $empresasSemValorDefinido++;
            }

            if (($linha['tabela_confirmada'] ?? null) === false) {
                $tabelasAssumidas++;
            }

            // Faturamento gerado é a soma do FATURAMENTO das empresas — não
            // se confunde com a soma das mensalidades (`total_a_receber`).
            if (($linha['faturamento'] ?? null) !== null) {
                $faturamentoGerado = ($faturamentoGerado ?? 0.0) + (float) $linha['faturamento'];
            }

            if (($linha['subiu_de_faixa'] ?? false) === true) {
                $upgradesQuantidade++;

                if (($linha['ganho_faixa'] ?? null) !== null) {
                    $upgradesGanhoTotal += (float) $linha['ganho_faixa'];
                } else {
                    // Subiu de faixa mas não temos o valor da faixa anterior
                    // — o "+R$ X/mês" do widget está incompleto; a tela
                    // precisa mostrar "no mínimo", nunca esconder a lacuna.
                    $upgradesGanhoParcial = true;
                }
            }
        }

        $comparativo = $this->comparativoService->totalCobrancaDoMesAnterior($mesReferenciaStr);

        // Mês anterior não fechado → variação null. A tela diz isso com
        // palavra, nunca com R$ 0 (D-05).
        $variacao = $comparativo['total'] !== null
            ? $totalAReceber - $comparativo['total']
            : null;

        return [
            'total_a_receber'             => $totalAReceber,
            'total_e_piso'                => $totalEPiso,
            'empresas_com_cobranca'       => $empresasComCobranca,
            'empresas_sem_valor_definido' => $empresasSemValorDefinido,
            'faturamento_gerado'          => $faturamentoGerado,
            'mes_anterior_fechado'        => $comparativo['fechado'],
            'mes_anterior_total'          => $comparativo['total'],
            'variacao'                    => $variacao,
            'upgrades_quantidade'         => $upgradesQuantidade,
            'upgrades_ganho_total'        => $upgradesGanhoTotal,
            'upgrades_ganho_parcial'      => $upgradesGanhoParcial,
            'tabelas_assumidas'           => $tabelasAssumidas,
        ];
    }

    /**
     * Quick 260909-e8n (ampliado no Quick 260909-lge) — quem NÃO deve
     * aparecer na tela de fechamento.
     *
     * O fechamento existe para responder "quem a ECF vai cobrar neste mês".
     * O escopo é setor `performance` (Gestão, Mentoria, Brigada) OU `shopee`
     * (Gestão de ADS Shopee) — `Servico::SETORES_FINANCEIROS`. Perfis que
     * poluíam a lista sem pertencer a essa pergunta (medido em produção
     * 2026-09-09, competência 2026-08: 72 de 201 empresas):
     *  1. `status = 'pendente'` — cadastro de onboarding ainda não ativado,
     *     incluindo os registros de teste; 61 das 69 linhas "sem dados" eram
     *     desta categoria.
     *  2. Sem nenhum contrato de serviço ativo — nunca há o que cobrar.
     *  3. Só serviço fora de `SETORES_FINANCEIROS` — inicialmente só
     *     `polos` era excluído; o Quick 260909-lge ampliou para qualquer
     *     setor fora de performance/shopee (`publicacao`, `polos`,
     *     `outros`), porque só estes dois setores geram cobrança NESTE
     *     fechamento.
     *
     * ⚠️ TRAVA DE SEGURANÇA (decisão do usuário, 2026-09-09): esconder linha
     * que representa dinheiro é pior do que a poluição que este filtro
     * resolve. A empresa PERMANECE na lista, mesmo se cair numa das três
     * regras acima, quando:
     *  - tem faturamento apurado no mês (> 0);
     *  - tem cobrança calculada (`cobranca_mensal` ou `valor_mensal` > 0);
     *  - é membro de `CompanyGroup` — tirá-la mudaria a SOMA do grupo e
     *    poderia derrubar a faixa cobrada do grupo inteiro (o caso real:
     *    Interior Magazine, contratos inativos, R$ 204.427 dentro do grupo
     *    Utilar);
     *  - tem hierarquia de empresa (`parent_company_id`).
     *
     * Os três clientes Shopee com `status = 'pendente'` e cobrança ativa
     * (Ale Peças, Tuki Pet, RAVENA RESKALLA HOME) sobrevivem pela primeira
     * trava — o status é que está desatualizado no cadastro, não a cobrança.
     *
     * ⚠️ QUICK 260909-lge — O SETOR MANDA sobre "tem faturamento" quando a
     * empresa TEM contrato ativo, mas de um setor fora do escopo (ex.:
     * Publicação com faturamento apurado). Faturamento de um setor que não é
     * cobrado neste fechamento é irrelevante aqui, então a válvula de
     * dinheiro NÃO se aplica nesse caso — só grupo/hierarquia continuam
     * travando. Isso é diferente do caso "sem NENHUM contrato ativo": aí a
     * válvula de dinheiro completa (a de sempre) continua valendo, porque
     * não há setor nenhum pra mandar (protegido pelo teste
     * `test_trava_faturamento_mantem_empresa_sem_servico_que_esta_faturando`,
     * Quick 260909-e8n).
     *
     * Roda ANTES da agregação por grupo nos dois endpoints que montam linhas
     * de fechamento (`fechamento()` e `gerarRelatorioGeral()`), para o PDF
     * nunca divergir da tela.
     *
     * @param  array<int|string, array<string, mixed>>  $dadosPorId  linhas por empresa, ANTES da agregação de grupo
     * @return array<int|string, array<string, mixed>>
     */
    private function fechamentoRemoverForaDeEscopo(array $dadosPorId, Collection $rawCompanies): array
    {
        $porId = $rawCompanies->keyBy('id');

        return array_filter($dadosPorId, function (array $linha, $id) use ($porId) {
            $c = $porId->get($id);

            // Fail-safe: sem a empresa em mãos não dá para julgar escopo —
            // manter a linha (nunca sumir com dado por dúvida).
            if ($c === null) {
                return true;
            }

            $temAlgumServicoAtivo = $c->contratosServico->isNotEmpty();

            $temServicoCobravel = $c->contratosServico->contains(
                fn ($ct) => $ct->ativo === true
                    && $ct->servico !== null
                    && in_array($ct->servico->setor, Servico::SETORES_FINANCEIROS, true)
            );

            $foraDeEscopo = $c->status === 'pendente' || ! $temServicoCobravel;

            if (! $foraDeEscopo) {
                return true;
            }

            // Setor manda: tem contrato ativo, mas de setor fora do escopo
            // (Publicação/Polos/Outros...) — o faturamento dela não conta
            // pra esta pergunta, só grupo/hierarquia seguram a linha.
            if ($temAlgumServicoAtivo && ! $temServicoCobravel) {
                return $c->company_group_id !== null
                    || $c->parent_company_id !== null;
            }

            $temDinheiro = (float) ($linha['faturamento'] ?? 0) > 0
                || (float) ($linha['cobranca_mensal'] ?? 0) > 0
                || (float) ($linha['valor_mensal'] ?? 0) > 0;

            return $temDinheiro
                || $c->company_group_id !== null
                || $c->parent_company_id !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Fase 137 (D-01/D-02b/D-05/D-06/D-07) — números por empresa AO VIVO,
     * quando a competência ainda está aberta. Mesma precedência de estado e
     * mesma lógica de evolução do comando `fechamento:consolidar-mes`
     * (App\Console\Commands\ConsolidarMesFechamento), só que sem gravar
     * nada.
     */
    private function fechamentoDadosPorEmpresaAoVivo(Collection $rawCompanies, string $mesSelecionado, Carbon $inicio, Carbon $fim, Collection $companyIdsComContratoAssinado, bool $regraNova): array
    {
        // Fase 141 (D-02): `somenteContratadas: $regraNova` vai NAS DUAS
        // chamadas — competência atual e mês anterior. Recortar só a atual
        // compararia réguas diferentes entre os dois meses e inventaria
        // evolução de faixa falsa (mesma disciplina de
        // `ConsolidarMesFechamento::handle()`).
        $rollupAtual = $this->rollupService->porEmpresa($mesSelecionado, $rawCompanies, somenteContratadas: $regraNova);

        $mesAnterior    = Carbon::createFromFormat('Y-m-d', $mesSelecionado.'-01')->startOfMonth()->subMonthNoOverflow();
        $rollupAnterior = $this->rollupService->porEmpresa($mesAnterior->format('Y-m'), $rawCompanies, somenteContratadas: $regraNova);

        // Fase 141 (D-02) — só quando a regra nova filtra plataforma: o
        // faturamento BRUTO (sem filtro) alimenta SÓ a explicação da tela
        // de qual plataforma ficou de fora da conta — nunca a classificação
        // nem a cobrança, que usam sempre o rollup filtrado acima. Uma
        // chamada extra (2 queries agregadas), fora do laço de empresas.
        $rollupBrutoAtual = $regraNova
            ? $this->rollupService->porEmpresa($mesSelecionado, $rawCompanies)
            : [];

        $companyIdsComShopee = ShopeeMetric::query()->distinct()->pluck('company_id')->flip();

        // Fase 139 (D-04): leitura única do fechamento congelado do mês
        // anterior — substitui a consulta local antiga (que não filtrava por
        // origem) e é a fonte primária de faixa_ordem_anterior/valor_faixa_anterior.
        $anterioresPorEmpresa = $this->comparativoService->anterioresPorEmpresa($mesSelecionado.'-01');

        $dadosPorId = [];

        foreach ($rawCompanies as $c) {
            $fatAtual  = $rollupAtual[$c->id] ?? ['faturamento_ml' => null, 'faturamento_shopee' => null, 'faturamento_total' => null];
            $fatBruto  = $rollupBrutoAtual[$c->id] ?? null;

            $hasAdman      = $c->cust_id !== null;
            $temIntegracao = $hasAdman || $companyIdsComShopee->has($c->id);

            $faixaData     = $this->faixaResolver->paraEmpresa($c);
            $classificacao = ($faixaData !== null && $fatAtual['faturamento_total'] !== null)
                ? $this->faixaResolver->classificar((float) $fatAtual['faturamento_total'], $faixaData['faixas'])
                : null;

            // Precisa estar disponível ANTES da precedência de estado — com
            // a regra nova, a existência de contrato mensal decide
            // ESTADO_VALOR_FIXO (Fase 141, D-03).
            $temContratoMensal = $c->contratosServico->contains(
                fn ($ct) => $ct->ativo === true && $ct->servico !== null && $ct->servico->tipo_cobranca === Servico::TIPO_MENSAL
            );

            // Precedência de estado. Fase 141: com a regra nova, a tabela do
            // serviço nunca mais aparece — empresa sem tabela de
            // grupo/própria mas com contrato mensal ativo cobra o valor
            // FIXO do contrato (estado visível, nunca pendência). Com a
            // flag desligada a precedência de sempre fica intocada, byte a
            // byte (mesmo bloco de `ConsolidarMesFechamento::handle()`).
            if ($regraNova) {
                $estado = match (true) {
                    ! $temIntegracao                                => FechamentoSnapshot::ESTADO_SEM_INTEGRACAO,
                    $faixaData === null && $temContratoMensal       => FechamentoSnapshot::ESTADO_VALOR_FIXO,
                    $fatAtual['faturamento_total'] === null         => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                    $faixaData === null || $classificacao === null  => FechamentoSnapshot::ESTADO_SEM_TABELA,
                    default                                         => FechamentoSnapshot::ESTADO_OK,
                };
            } else {
                $estado = match (true) {
                    ! $temIntegracao                                 => FechamentoSnapshot::ESTADO_SEM_INTEGRACAO,
                    $fatAtual['faturamento_total'] === null          => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                    $faixaData === null || $classificacao === null   => FechamentoSnapshot::ESTADO_SEM_TABELA,
                    default                                          => FechamentoSnapshot::ESTADO_OK,
                };
            }

            // Evolução: compara com o snapshot congelado do mês anterior
            // quando existe; senão calcula o rollup do mês anterior ao vivo
            // e classifica com a MESMA tabela desta empresa — nunca comparar
            // réguas diferentes.
            //
            // Fase 139 (D-04): a mesma variável $ordemAnterior agora também
            // alimenta faixa_ordem_anterior/valor_faixa_anterior — só no
            // ramo AO VIVO existe o fallback pelo rollup; quando o valor vem
            // do fallback (não havia snapshot), o valor da faixa é buscado
            // na MESMA tabela desta empresa, nunca comparando réguas
            // diferentes.
            $ordemAnterior      = null;
            $valorFaixaAnterior = null;
            $anteriorInfo       = $anterioresPorEmpresa[$c->id] ?? null;

            if ($anteriorInfo !== null && $anteriorInfo['faixa_ordem'] !== null) {
                $ordemAnterior      = $anteriorInfo['faixa_ordem'];
                $valorFaixaAnterior = $anteriorInfo['valor_faixa'];
            } elseif ($faixaData !== null) {
                $fatAnterior = $rollupAnterior[$c->id]['faturamento_total'] ?? null;

                if ($fatAnterior !== null) {
                    $classifAnterior = $this->faixaResolver->classificar((float) $fatAnterior, $faixaData['faixas']);
                    $ordemAnterior   = $classifAnterior['ordem'] ?? null;

                    if ($ordemAnterior !== null) {
                        $faixaAnteriorNaTabela = $faixaData['faixas']->firstWhere('ordem', $ordemAnterior);
                        $valorFaixaAnterior    = $faixaAnteriorNaTabela?->valor !== null ? (float) $faixaAnteriorNaTabela->valor : null;
                    }
                }
            }

            $evolucao = null;
            if ($ordemAnterior !== null && $classificacao !== null) {
                $evolucao = match (true) {
                    $classificacao['ordem'] > $ordemAnterior => 'subiu',
                    $classificacao['ordem'] < $ordemAnterior => 'desceu',
                    default                                   => 'manteve',
                };
            }

            $upgrade = $this->fechamentoDerivarUpgrade($classificacao['ordem'] ?? null, $classificacao['valor'] ?? null, $ordemAnterior, $valorFaixaAnterior);

            // Fase 141 (D-03): com a regra nova, a mensalidade é o valor da
            // FAIXA e só isso — `mensalidade()` já devolve null quando não
            // há nem faixa nem contrato mensal. Com a flag desligada, a
            // fórmula antiga (faixa + soma dos contratos mensais) continua
            // exatamente como era.
            $cobrancaMensal = $regraNova
                ? CobrancaCalculator::mensalidade($classificacao, $c->contratosServico)
                : (($classificacao !== null || $temContratoMensal)
                    ? (CobrancaCalculator::novo($classificacao, $c->contratosServico) ?: null)
                    : null);

            $dadosPorId[$c->id] = [
                'id'                    => $c->id,
                'name'                  => $c->name,
                'parent_company_id'     => $c->parent_company_id,
                'company_group_id'      => $c->company_group_id,
                'servicos_contratados'  => $this->fechamentoServicosContratados($c),
                'has_adman'             => $hasAdman,
                'estado'                => $estado,
                'faturamento'           => $fatAtual['faturamento_total'],
                'faturamento_ml'        => $fatAtual['faturamento_ml'],
                'faturamento_shopee'    => $fatAtual['faturamento_shopee'],
                'periodo_inicio'        => $estado === FechamentoSnapshot::ESTADO_OK ? $inicio->format('d/m') : null,
                'periodo_fim'           => $estado === FechamentoSnapshot::ESTADO_OK ? $fim->format('d/m')    : null,
                'faixa'                 => $classificacao['label'] ?? null,
                'faixa_label'           => $classificacao['label'] ?? null,
                'faixa_ordem'           => $classificacao['ordem'] ?? null,
                'faixa_limite_inferior' => $classificacao['limite_inferior'] ?? null,
                'faixa_limite_superior' => $classificacao['limite_superior'] ?? null,
                'valor_mensal'          => $classificacao['valor'] ?? null,
                'valor_faixa_e_piso'    => $classificacao['valor_e_piso'] ?? false,
                'tabela_origem'         => $faixaData['origem'] ?? null,
                'tabela_servico_nome'   => $faixaData['servico_nome'] ?? null,
                // Fase 141 (D-04/D-05) — procedência da tabela PRÓPRIA desta
                // empresa (manual/contrato/presumida do serviço); `null` nos
                // demais casos (tabela do grupo ou do serviço). Vem pronta
                // do resolver, sem consulta extra.
                'procedencia_tabela'    => $faixaData['procedencia'] ?? null,
                // Fase 142 (142-01, D-01) — as LINHAS da tabela própria desta
                // empresa, só quando a origem é 'propria' (quando é 'servico'
                // ou 'grupo' a tela já tem as linhas em
                // faixas_por_servico/faixas_por_grupo — duplicar criaria uma
                // segunda fonte da mesma informação). Sai de $faixaData,
                // que o resolver já carregou — zero query nova.
                'tabela_faixas'         => ($faixaData['origem'] ?? null) === 'propria'
                    ? $this->fechamentoMapFaixasParaProps($faixaData['faixas'])
                    : null,
                // Ramo AO VIVO: a tabela mostrada é sempre a de hoje.
                'tabela_faixas_e_de_hoje' => false,
                // Quick 260904-kwz — confirmada (cadastro manual ou contrato
                // assinado) ou só presumida a partir do serviço. Fase 141
                // (T-141-18): tabela própria com procedência
                // 'presumida_servico' nunca é confirmada, mesmo sendo
                // origem 'propria'.
                'tabela_confirmada'     => $this->fechamentoTabelaConfirmada($faixaData['origem'] ?? null, $c->id, $companyIdsComContratoAssinado, $faixaData['procedencia'] ?? null),
                // Fase 141 (D-02) — quais plataformas entraram na soma que
                // define a faixa; sempre presente (rollup devolve
                // ['ml','shopee'] quando a regra nova está desligada).
                'plataformas_consideradas' => $fatAtual['plataformas_consideradas'] ?? null,
                // Fase 141 (D-02) — faturamento BRUTO (sem filtro), só para
                // a tela explicar quando uma plataforma com faturamento
                // real ficou de fora da soma que definiu a faixa. `null`
                // quando a regra nova está desligada (nunca há recorte pra
                // explicar).
                'faturamento_ml_bruto'     => $fatBruto['faturamento_ml'] ?? null,
                'faturamento_shopee_bruto' => $fatBruto['faturamento_shopee'] ?? null,
                'cobranca_mensal'       => $cobrancaMensal,
                'evolucao'              => $evolucao,
                // Fase 139 (D-04): de qual faixa a empresa veio e quanto
                // aquela faixa cobrava — base do widget "Subiram de faixa".
                'faixa_ordem_anterior'  => $ordemAnterior,
                'valor_faixa_anterior'  => $valorFaixaAnterior,
                'subiu_de_faixa'        => $upgrade['subiu_de_faixa'],
                'ganho_faixa'           => $upgrade['ganho_faixa'],
            ];
        }

        return $dadosPorId;
    }

    /**
     * Fase 137 (D-11) — números por empresa lidos do CONGELADO
     * (`fechamento_snapshots`), quando a competência já foi fechada por
     * `fechamento:consolidar-mes`. Nunca recalcula — corrigir `adman_metrics`
     * depois do fechamento não muda o que já foi cobrado.
     */
    private function fechamentoDadosPorEmpresaCongelados(Collection $rawCompanies, string $mesReferenciaStr, Carbon $inicio, Carbon $fim, Collection $companyIdsComContratoAssinado): array
    {
        $snapshots = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->with('servico:id,nome')
            ->get()
            ->keyBy('company_id');

        // Fase 139 (D-04): ramo CONGELADO não tem fallback ao vivo — sem
        // linha no mês anterior, os dois ficam null (D-11, nunca recalcula).
        $anterioresPorEmpresa = $this->comparativoService->anterioresPorEmpresa($mesReferenciaStr);

        // Fase 142 (142-01, D-01) — o snapshot NÃO guarda a tabela (só a
        // faixa aplicada); "qual é a tabela cadastrada HOJE" é pergunta
        // sobre HOJE, nunca sobre o mês congelado (D-11 segue intocado:
        // faturamento, faixa e mensalidade continuam vindo só do snapshot).
        // UMA consulta agrupada para todo o lote (nunca uma query por
        // empresa) alimenta as DUAS necessidades: as linhas completas
        // (`tabela_faixas`) e a procedência ATUAL (`procedencia_tabela`,
        // antes uma segunda consulta MIN(origem) separada — Fase 141,
        // T-141-18 — agora derivada da mesma leitura, sem duplicar query na
        // mesma tabela).
        $idsComTabelaPropriaHoje = $snapshots
            ->filter(fn (FechamentoSnapshot $s) => $s->tabela_origem === 'propria')
            ->pluck('company_id');

        $faixasPropriasPorCompanyId = EmpresaFaixaFaturamento::whereIn('company_id', $idsComTabelaPropriaHoje)
            ->ordenadas()
            ->get()
            ->groupBy('company_id');

        $procedenciaPorEmpresa = $faixasPropriasPorCompanyId->map(
            fn (Collection $linhas) => $linhas->first()->origem ?? null
        );

        $dadosPorId = [];

        foreach ($rawCompanies as $c) {
            $s = $snapshots->get($c->id);

            // Empresa ativa sem linha nesta competência (ex.: entrou na
            // carteira depois do fechamento) — nunca inventa número, fica
            // visivelmente "sem faturamento" nesta competência congelada.
            if ($s === null) {
                $dadosPorId[$c->id] = [
                    'id'                    => $c->id,
                    'name'                  => $c->name,
                    'parent_company_id'     => $c->parent_company_id,
                    'company_group_id'      => $c->company_group_id,
                    'servicos_contratados'  => $this->fechamentoServicosContratados($c),
                    'has_adman'             => $c->cust_id !== null,
                    'estado'                => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                    'faturamento'           => null,
                    'faturamento_ml'        => null,
                    'faturamento_shopee'    => null,
                    'periodo_inicio'        => null,
                    'periodo_fim'           => null,
                    'faixa'                 => null,
                    'faixa_label'           => null,
                    'faixa_ordem'           => null,
                    'faixa_limite_inferior' => null,
                    'faixa_limite_superior' => null,
                    'valor_mensal'          => null,
                    'valor_faixa_e_piso'    => false,
                    'tabela_origem'         => null,
                    'tabela_servico_nome'   => null,
                    // Fase 141 (D-04) — sem linha nesta competência, também
                    // não há procedência nem recorte de plataforma pra
                    // mostrar (nunca inventar um palpite, D-11).
                    'procedencia_tabela'       => null,
                    'plataformas_consideradas' => null,
                    'faturamento_ml_bruto'     => null,
                    'faturamento_shopee_bruto' => null,
                    // Quick 260904-kwz — sem linha nesta competência, não há
                    // tabela nenhuma pra perguntar se está confirmada.
                    'tabela_confirmada'     => null,
                    // Fase 142 (142-01) — sem linha nesta competência, também
                    // não há tabela nenhuma pra mostrar.
                    'tabela_faixas'           => null,
                    'tabela_faixas_e_de_hoje' => false,
                    'cobranca_mensal'       => null,
                    'evolucao'              => null,
                    // Fase 139 (D-04): sem linha nesta competência, também
                    // não há como comparar com o mês anterior.
                    'faixa_ordem_anterior'  => null,
                    'valor_faixa_anterior'  => null,
                    'subiu_de_faixa'        => false,
                    'ganho_faixa'           => null,
                ];

                continue;
            }

            $ordemAnterior      = $anterioresPorEmpresa[$c->id]['faixa_ordem'] ?? null;
            $valorFaixaAnterior = $anterioresPorEmpresa[$c->id]['valor_faixa'] ?? null;
            $valorMensalAtual   = $s->valor_faixa !== null ? (float) $s->valor_faixa : null;
            $upgrade            = $this->fechamentoDerivarUpgrade($s->faixa_ordem, $valorMensalAtual, $ordemAnterior, $valorFaixaAnterior);

            $dadosPorId[$c->id] = [
                'id'                    => $c->id,
                'name'                  => $s->company_name,
                'parent_company_id'     => $c->parent_company_id,
                'company_group_id'      => $s->company_group_id,
                'servicos_contratados'  => $this->fechamentoServicosContratados($c),
                'has_adman'             => $c->cust_id !== null,
                'estado'                => $s->estado,
                'faturamento'           => $s->faturamento_total !== null ? (float) $s->faturamento_total : null,
                'faturamento_ml'        => $s->faturamento_ml !== null ? (float) $s->faturamento_ml : null,
                'faturamento_shopee'    => $s->faturamento_shopee !== null ? (float) $s->faturamento_shopee : null,
                'periodo_inicio'        => $s->estado === FechamentoSnapshot::ESTADO_OK ? $inicio->format('d/m') : null,
                'periodo_fim'           => $s->estado === FechamentoSnapshot::ESTADO_OK ? $fim->format('d/m')    : null,
                'faixa'                 => $s->faixa_aplicada,
                'faixa_label'           => $s->faixa_aplicada,
                'faixa_ordem'           => $s->faixa_ordem,
                'faixa_limite_inferior' => $s->faixa_limite_inferior !== null ? (float) $s->faixa_limite_inferior : null,
                'faixa_limite_superior' => $s->faixa_limite_superior !== null ? (float) $s->faixa_limite_superior : null,
                'valor_mensal'          => $valorMensalAtual,
                'valor_faixa_e_piso'    => (bool) $s->valor_faixa_e_piso,
                'tabela_origem'         => $s->tabela_origem,
                'tabela_servico_nome'   => $s->servico?->nome,
                // Fase 141 (D-04/D-05) — procedência ATUAL da tabela própria
                // desta empresa (o snapshot não guarda essa coluna — D-11
                // proíbe recalcular, mas "quem é dono da tabela hoje" é uma
                // pergunta sobre HOJE, mesma disciplina do contrato assinado
                // logo abaixo, nunca sobre "como era no mês fechado").
                'procedencia_tabela'    => $s->tabela_origem === 'propria' ? ($procedenciaPorEmpresa[$c->id] ?? null) : null,
                // Fase 142 (142-01, D-01) — linhas da tabela cadastrada HOJE
                // (nunca a que valia no mês congelado — `tabela_faixas_e_de_hoje`
                // marca isso pra tela nunca confundir as duas). Vem da consulta
                // em lote montada antes do laço, nunca uma query por empresa.
                'tabela_faixas'           => $s->tabela_origem === 'propria'
                    ? $this->fechamentoMapFaixasParaProps($faixasPropriasPorCompanyId->get($c->id, collect()))
                    : null,
                'tabela_faixas_e_de_hoje' => true,
                // Fase 141 (D-02) — a competência congelada não guarda quais
                // plataformas entraram na soma daquele mês; mandar `null` (a
                // tela não mostra a explicação) em vez de um palpite.
                'plataformas_consideradas' => null,
                'faturamento_ml_bruto'     => null,
                'faturamento_shopee_bruto' => null,
                // Quick 260904-kwz — confirmada (cadastro manual ou contrato
                // assinado) ou só presumida a partir do serviço. Lê o
                // contrato assinado ATUAL (não congela junto do snapshot) —
                // um contrato assinado depois do fechamento passa a
                // confirmar a tabela de competências passadas também,
                // porque a pergunta é "existe confirmação HOJE", não
                // "existia confirmação naquele mês" (D-11 é sobre números,
                // nunca recalcula faturamento/faixa/mensalidade). Fase 141
                // (T-141-18): mesma correção do ramo ao vivo — procedência
                // 'presumida_servico' nunca é confirmada.
                'tabela_confirmada'     => $this->fechamentoTabelaConfirmada($s->tabela_origem, $c->id, $companyIdsComContratoAssinado, $procedenciaPorEmpresa[$c->id] ?? null),
                'cobranca_mensal'       => $s->cobranca_mensal !== null ? (float) $s->cobranca_mensal : null,
                'evolucao'              => $s->evolucao,
                // Fase 139 (D-04): reconstruído do snapshot do mês anterior
                // — nunca recalcula (D-11).
                'faixa_ordem_anterior'  => $ordemAnterior,
                'valor_faixa_anterior'  => $valorFaixaAnterior,
                'subiu_de_faixa'        => $upgrade['subiu_de_faixa'],
                'ganho_faixa'           => $upgrade['ganho_faixa'],
            ];
        }

        return $dadosPorId;
    }

    /**
     * Fase 137 (D-08/D-09/D-10) — agrega `$dadosPorId` por `CompanyGroup` AO
     * VIVO: soma das empresas-membro, faixa classificada sobre a soma
     * (tabela da empresa-âncora), `tabelas_divergentes` quando as membros
     * resolvem tabelas diferentes. `parent_company_id` NUNCA participa.
     */
    private function fechamentoAgregarGruposAoVivo(array $dadosPorId, Collection $rawCompanies, string $mesReferenciaStr, Collection $companyIdsComContratoAssinado, bool $regraNova): array
    {
        $linhasFinais = [];

        foreach ($dadosPorId as $id => $linha) {
            $c = $rawCompanies->firstWhere('id', $id);
            if ($c === null || $c->company_group_id === null) {
                $linha['tipo']           = 'empresa';
                $linha['conta_no_total'] = true;
                $linha['filhas']         = [];
                $linhasFinais[$id]       = $linha;
            }
        }

        $porGrupo = $rawCompanies
            ->filter(fn (Company $c) => $c->company_group_id !== null && isset($dadosPorId[$c->id]))
            ->groupBy('company_group_id');

        // Fase 139 (D-04): leitura única ANTES do laço — substitui a consulta
        // de `FechamentoGrupoSnapshot::query()` que hoje roda uma vez por
        // grupo dentro do laço (o N+1 que este serviço elimina). A evolução
        // do grupo passa a ler do mesmo array, sem mudar o resultado.
        $anterioresPorGrupo = $this->comparativoService->anterioresPorGrupo($mesReferenciaStr);

        foreach ($porGrupo as $groupId => $membros) {
            $linhasMembros = $membros->map(function (Company $c) use ($dadosPorId) {
                $lm                    = $dadosPorId[$c->id];
                $lm['conta_no_total']  = false;

                return $lm;
            })->values();

            $faturamentoMl = null;
            $faturamentoShopee = null;
            $faturamentoTotal = null;

            foreach ($linhasMembros as $lm) {
                if ($lm['faturamento_ml'] !== null) {
                    $faturamentoMl = ($faturamentoMl ?? 0.0) + (float) $lm['faturamento_ml'];
                }
                if ($lm['faturamento_shopee'] !== null) {
                    $faturamentoShopee = ($faturamentoShopee ?? 0.0) + (float) $lm['faturamento_shopee'];
                }
                if ($lm['faturamento'] !== null) {
                    $faturamentoTotal = ($faturamentoTotal ?? 0.0) + (float) $lm['faturamento'];
                }
            }

            // Âncora: empresa-membro de maior faturamento_total (empate pelo
            // menor id) — é a tabela DELA que classifica a soma.
            $ancora = $membros->sort(function (Company $a, Company $b) use ($dadosPorId) {
                $fatA = (float) ($dadosPorId[$a->id]['faturamento'] ?? -INF);
                $fatB = (float) ($dadosPorId[$b->id]['faturamento'] ?? -INF);

                if ($fatA === $fatB) {
                    return $a->id <=> $b->id;
                }

                return $fatB <=> $fatA;
            })->first();

            // Fase 138 (D-01): tabela do GRUPO tem precedência sobre a
            // tabela da âncora. `paraGrupo()` já devolve a herança marcada
            // (`herdada_de_company_name`) quando o grupo não tem tabela
            // própria — fallback defensivo pra `paraEmpresa()` só se o
            // relacionamento de grupo vier nulo (não deveria acontecer aqui,
            // já estamos dentro do laço `$porGrupo`).
            $grupo = $ancora->grupo;
            $faixaAncora = $grupo !== null
                ? $this->faixaResolver->paraGrupo($grupo, $ancora)
                : $this->faixaResolver->paraEmpresa($ancora);

            $classificacaoGrupo = ($faixaAncora !== null && $faturamentoTotal !== null)
                ? $this->faixaResolver->classificar($faturamentoTotal, $faixaAncora['faixas'])
                : null;

            // Precisa estar disponível ANTES da precedência de estado —
            // mesma razão da linha de empresa (Fase 141, D-03).
            $todosContratosDoGrupo  = $membros->flatMap(fn (Company $c) => $c->contratosServico);
            $temContratoMensalGrupo = $todosContratosDoGrupo->contains(
                fn ($ct) => $ct->ativo === true && $ct->servico !== null && $ct->servico->tipo_cobranca === Servico::TIPO_MENSAL
            );

            // Mesma precedência da linha de empresa (Fase 141, D-03): com a
            // regra nova, grupo sem régua e com contrato mensal em qualquer
            // empresa-membro cobra o valor FIXO. Flag desligada mantém a
            // precedência de sempre, intocada.
            if ($regraNova) {
                $estadoGrupo = match (true) {
                    $faixaAncora === null && $temContratoMensalGrupo      => FechamentoSnapshot::ESTADO_VALOR_FIXO,
                    $faturamentoTotal === null                            => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                    $faixaAncora === null || $classificacaoGrupo === null => FechamentoSnapshot::ESTADO_SEM_TABELA,
                    default                                                => FechamentoSnapshot::ESTADO_OK,
                };
            } else {
                $estadoGrupo = match (true) {
                    $faturamentoTotal === null                            => FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                    $faixaAncora === null || $classificacaoGrupo === null => FechamentoSnapshot::ESTADO_SEM_TABELA,
                    default                                                => FechamentoSnapshot::ESTADO_OK,
                };
            }

            // Fase 138: nenhum caso especial aqui pra tabela de grupo — desde
            // que `paraEmpresa()` (Plano 01) passou a devolver origem
            // 'grupo' pra TODA empresa-membro de um grupo com tabela
            // própria, o conjunto de pares já colapsa sozinho (todo membro
            // aponta pra 'grupo|null'), sem precisar tratar o degrau novo
            // separadamente.
            $paresTabela = $membros
                ->map(fn (Company $c) => $this->faixaResolver->paraEmpresa($c))
                ->map(fn ($f) => $f === null ? 'null' : ($f['origem'].'|'.($f['servico_id'] ?? 'null')))
                ->unique();
            $tabelasDivergentes = $paresTabela->count() > 1;

            // Evolução do grupo: só compara contra o snapshot de grupo do
            // mês anterior (sem fallback ao vivo) — mesma régua do comando.
            $ordemAnteriorGrupo = $anterioresPorGrupo[$groupId]['faixa_ordem'] ?? null;
            $valorFaixaAnteriorGrupo = $anterioresPorGrupo[$groupId]['valor_faixa'] ?? null;

            $evolucaoGrupo = null;
            if ($ordemAnteriorGrupo !== null && $classificacaoGrupo !== null) {
                $evolucaoGrupo = match (true) {
                    $classificacaoGrupo['ordem'] > $ordemAnteriorGrupo => 'subiu',
                    $classificacaoGrupo['ordem'] < $ordemAnteriorGrupo => 'desceu',
                    default                                             => 'manteve',
                };
            }

            $upgradeGrupo = $this->fechamentoDerivarUpgrade($classificacaoGrupo['ordem'] ?? null, $classificacaoGrupo['valor'] ?? null, $ordemAnteriorGrupo, $valorFaixaAnteriorGrupo);

            // Fase 141 (D-03): mesma bifurcação da linha de empresa — com a
            // regra nova, a mensalidade do grupo é só o valor da faixa da
            // SOMA, nunca faixa + contratos-membro somados por cima.
            $cobrancaMensalGrupo = $regraNova
                ? CobrancaCalculator::mensalidade($classificacaoGrupo, $todosContratosDoGrupo)
                : (($classificacaoGrupo !== null || $temContratoMensalGrupo)
                    ? (CobrancaCalculator::novo($classificacaoGrupo, $todosContratosDoGrupo) ?: null)
                    : null);

            $servicosContratadosUniao = $membros
                ->flatMap(fn (Company $c) => $dadosPorId[$c->id]['servicos_contratados'])
                ->unique('id')
                ->values()
                ->all();

            $linhasFinais[$ancora->id] = [
                'id'                    => $ancora->id,
                'tipo'                  => 'grupo',
                'name'                  => $grupo?->name,
                'company_group_id'      => $groupId,
                'grupo'                 => $grupo ? ['id' => $grupo->id, 'name' => $grupo->name, 'color' => $grupo->color] : null,
                'filhas'                => $linhasMembros->all(),
                'servicos_contratados'  => $servicosContratadosUniao,
                'has_adman'             => $membros->contains(fn (Company $c) => $c->cust_id !== null),
                'estado'                => $estadoGrupo,
                'faturamento'           => $faturamentoTotal,
                'faturamento_ml'        => $faturamentoMl,
                'faturamento_shopee'    => $faturamentoShopee,
                'periodo_inicio'        => $dadosPorId[$ancora->id]['periodo_inicio'] ?? null,
                'periodo_fim'           => $dadosPorId[$ancora->id]['periodo_fim'] ?? null,
                'faixa'                 => $classificacaoGrupo['label'] ?? null,
                'faixa_label'           => $classificacaoGrupo['label'] ?? null,
                'faixa_ordem'           => $classificacaoGrupo['ordem'] ?? null,
                'faixa_limite_inferior' => $classificacaoGrupo['limite_inferior'] ?? null,
                'faixa_limite_superior' => $classificacaoGrupo['limite_superior'] ?? null,
                'valor_mensal'          => $classificacaoGrupo['valor'] ?? null,
                'valor_faixa_e_piso'    => $classificacaoGrupo['valor_e_piso'] ?? false,
                'tabela_origem'         => $faixaAncora['origem'] ?? null,
                'tabela_servico_nome'   => $faixaAncora['servico_nome'] ?? null,
                // Fase 138 (D-01): as duas chaves que matam a herança
                // invisível. `tabela_grupo_nome` só quando a tabela é do
                // PRÓPRIO grupo ("Tabela deste grupo"); `tabela_herdada_de_nome`
                // só quando não é — nome da empresa de quem a tabela foi
                // herdada.
                'tabela_grupo_nome'       => ($faixaAncora['origem'] ?? null) === 'grupo' ? ($faixaAncora['grupo_nome'] ?? null) : null,
                'tabela_herdada_de_nome'  => ($faixaAncora !== null && $faixaAncora['origem'] !== 'grupo') ? ($faixaAncora['herdada_de_company_name'] ?? null) : null,
                // Fase 141 (D-04/D-05) — procedência da tabela PRÓPRIA da
                // âncora; `null` quando a origem é 'grupo' ou 'servico'
                // (mesma semântica de `procedencia_tabela` na linha de
                // empresa). Vem pronta do resolver, sem consulta extra.
                'procedencia_tabela'    => $faixaAncora['procedencia'] ?? null,
                // Fase 142 (142-01, D-01) — mesma disciplina da linha de
                // empresa: linhas da tabela própria só quando a origem
                // resolvida para a âncora é 'propria' (nunca quando é
                // 'grupo' — a tela já tem o catálogo em faixas_por_grupo).
                'tabela_faixas'         => ($faixaAncora['origem'] ?? null) === 'propria'
                    ? $this->fechamentoMapFaixasParaProps($faixaAncora['faixas'])
                    : null,
                'tabela_faixas_e_de_hoje' => false,
                // Fase 141 (D-02) — união das plataformas consideradas pelos
                // membros (cada linha-membro já carrega a sua, calculada no
                // ramo ao vivo de empresa).
                'plataformas_consideradas' => $linhasMembros
                    ->flatMap(fn ($lm) => $lm['plataformas_consideradas'] ?? [])
                    ->unique()
                    ->values()
                    ->all(),
                // Fase 141 (D-02) — a explicação de plataforma excluída é só
                // por empresa (cada membro já carrega a sua em `filhas`);
                // a linha de grupo não duplica a conta.
                'faturamento_ml_bruto'     => null,
                'faturamento_shopee_bruto' => null,
                // Quick 260904-kwz — confirmada (cadastro manual do GRUPO ou
                // da empresa-âncora, ou contrato assinado da âncora) ou só
                // presumida a partir do serviço. `origem` 'grupo' já é
                // manual por si só; 'propria'/'servico' vêm da âncora
                // (`paraGrupo()` delega em `paraEmpresa($ancora)`), então o
                // contrato a checar é o DELA. Fase 141 (T-141-18): mesma
                // correção — procedência 'presumida_servico' nunca confirma.
                'tabela_confirmada'     => $this->fechamentoTabelaConfirmada($faixaAncora['origem'] ?? null, $ancora->id, $companyIdsComContratoAssinado, $faixaAncora['procedencia'] ?? null),
                'tabelas_divergentes'   => $tabelasDivergentes,
                'cobranca_mensal'       => $cobrancaMensalGrupo,
                'evolucao'              => $evolucaoGrupo,
                'conta_no_total'        => true,
                // Fase 139 (D-04): grupo continua sem fallback ao vivo —
                // vem só do fechamento congelado do grupo no mês anterior.
                'faixa_ordem_anterior'  => $ordemAnteriorGrupo,
                'valor_faixa_anterior'  => $valorFaixaAnteriorGrupo,
                'subiu_de_faixa'        => $upgradeGrupo['subiu_de_faixa'],
                'ganho_faixa'           => $upgradeGrupo['ganho_faixa'],
            ];
        }

        return $linhasFinais;
    }

    /**
     * Fase 137 (D-08/D-09/D-10/D-11) — agrega `$dadosPorId` por
     * `CompanyGroup` lendo `fechamento_grupo_snapshots`, quando a
     * competência já foi fechada. Nunca recalcula a soma.
     */
    private function fechamentoAgregarGruposCongelados(array $dadosPorId, Collection $rawCompanies, string $mesReferenciaStr, Collection $companyIdsComContratoAssinado): array
    {
        $linhasFinais = [];

        foreach ($dadosPorId as $id => $linha) {
            $c = $rawCompanies->firstWhere('id', $id);
            if ($c === null || $c->company_group_id === null) {
                $linha['tipo']           = 'empresa';
                $linha['conta_no_total'] = true;
                $linha['filhas']         = [];
                $linhasFinais[$id]       = $linha;
            }
        }

        $porGrupo = $rawCompanies
            ->filter(fn (Company $c) => $c->company_group_id !== null && isset($dadosPorId[$c->id]))
            ->groupBy('company_group_id');

        $snapshotsGrupo = FechamentoGrupoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->with('servico:id,nome')
            ->get()
            ->keyBy('company_group_id');

        // Fase 139 (D-04): ramo CONGELADO não tem fallback ao vivo — sem
        // linha no mês anterior, os dois ficam null (D-11, nunca recalcula).
        $anterioresPorGrupo = $this->comparativoService->anterioresPorGrupo($mesReferenciaStr);

        foreach ($porGrupo as $groupId => $membros) {
            $s = $snapshotsGrupo->get($groupId);

            $linhasMembros = $membros->map(function (Company $c) use ($dadosPorId) {
                $lm                   = $dadosPorId[$c->id];
                $lm['conta_no_total'] = false;

                return $lm;
            })->values()->all();

            $ancoraId   = $s?->empresa_ancora_id ?? $membros->first()->id;
            $grupoModel = $membros->first()->grupo;

            // Fase 138 (D-01): as duas chaves derivadas do snapshot, sem
            // recálculo (D-11) — `tabela_origem` já diz se a tabela é do
            // próprio grupo ou herdada; `empresa_ancora_id` continua sempre
            // preenchido (usado por `fechamentoAgregarGruposCongelados` pra
            // reencontrar a linha), a herança nasce de `tabela_origem`.
            $tabelaGrupoNome = ($s?->tabela_origem === 'grupo')
                ? ($s?->grupo_name ?? $grupoModel?->name)
                : null;
            $tabelaHerdadaDeNome = ($s !== null && $s->tabela_origem !== 'grupo' && $s->empresa_ancora_id !== null)
                ? $membros->firstWhere('id', $s->empresa_ancora_id)?->name
                : null;

            $servicosContratadosUniao = $membros
                ->flatMap(fn (Company $c) => $dadosPorId[$c->id]['servicos_contratados'])
                ->unique('id')
                ->values()
                ->all();

            $ordemAnteriorGrupo      = $anterioresPorGrupo[$groupId]['faixa_ordem'] ?? null;
            $valorFaixaAnteriorGrupo = $anterioresPorGrupo[$groupId]['valor_faixa'] ?? null;
            $valorMensalAtualGrupo   = $s?->valor_faixa !== null ? (float) $s->valor_faixa : null;
            $upgradeGrupo            = $this->fechamentoDerivarUpgrade($s?->faixa_ordem, $valorMensalAtualGrupo, $ordemAnteriorGrupo, $valorFaixaAnteriorGrupo);

            $linhasFinais[$ancoraId] = [
                'id'                    => $ancoraId,
                'tipo'                  => 'grupo',
                'name'                  => $s?->grupo_name ?? $grupoModel?->name,
                'company_group_id'      => $groupId,
                'grupo'                 => $grupoModel ? ['id' => $grupoModel->id, 'name' => $grupoModel->name, 'color' => $grupoModel->color] : null,
                'filhas'                => $linhasMembros,
                'servicos_contratados'  => $servicosContratadosUniao,
                'has_adman'             => $membros->contains(fn (Company $c) => $c->cust_id !== null),
                'estado'                => $s?->estado ?? FechamentoSnapshot::ESTADO_SEM_FATURAMENTO,
                'faturamento'           => $s?->faturamento_total !== null ? (float) $s->faturamento_total : null,
                'faturamento_ml'        => $s?->faturamento_ml !== null ? (float) $s->faturamento_ml : null,
                'faturamento_shopee'    => $s?->faturamento_shopee !== null ? (float) $s->faturamento_shopee : null,
                'periodo_inicio'        => $dadosPorId[$ancoraId]['periodo_inicio'] ?? null,
                'periodo_fim'           => $dadosPorId[$ancoraId]['periodo_fim'] ?? null,
                'faixa'                 => $s?->faixa_aplicada,
                'faixa_label'           => $s?->faixa_aplicada,
                'faixa_ordem'           => $s?->faixa_ordem,
                'faixa_limite_inferior' => $s?->faixa_limite_inferior !== null ? (float) $s->faixa_limite_inferior : null,
                'faixa_limite_superior' => $s?->faixa_limite_superior !== null ? (float) $s->faixa_limite_superior : null,
                'valor_mensal'          => $valorMensalAtualGrupo,
                'valor_faixa_e_piso'    => (bool) ($s?->valor_faixa_e_piso ?? false),
                'tabela_origem'         => $s?->tabela_origem,
                'tabela_servico_nome'   => $s?->servico?->nome,
                'tabela_grupo_nome'      => $tabelaGrupoNome,
                'tabela_herdada_de_nome' => $tabelaHerdadaDeNome,
                // Fase 141 (D-04/D-05) — procedência ATUAL da tabela própria
                // da empresa-âncora deste snapshot (mesma disciplina do
                // ramo 3 de empresa: "quem é dono hoje" é pergunta sobre
                // HOJE, não sobre o mês congelado).
                // Fase 142 (142-01, D-01) — reaproveita `procedencia_tabela`/
                // `tabela_faixas` já montados em `$dadosPorId[$ancoraId]`
                // pela empresa-linha da âncora (mesma consulta em lote de
                // `fechamentoDadosPorEmpresaCongelados()`, nenhuma query nova
                // aqui — antes esta linha usava um mapa `$procedenciaPorEmpresa`
                // passado à parte, de uma segunda consulta MIN(origem); T-141-18):
                // quando o snapshot de GRUPO tem `tabela_origem = 'propria'`,
                // o snapshot de EMPRESA da âncora, calculado no mesmo
                // instante, também tem.
                'procedencia_tabela'    => $s?->tabela_origem === 'propria' ? ($dadosPorId[$ancoraId]['procedencia_tabela'] ?? null) : null,
                'tabela_faixas'           => $s?->tabela_origem === 'propria'
                    ? ($dadosPorId[$ancoraId]['tabela_faixas'] ?? null)
                    : null,
                'tabela_faixas_e_de_hoje' => true,
                // Fase 141 (D-02) — o snapshot de grupo não guarda quais
                // plataformas entraram na soma daquele mês; `null` em vez de
                // palpite (mesma disciplina do ramo 3 de empresa).
                'plataformas_consideradas' => null,
                'faturamento_ml_bruto'     => null,
                'faturamento_shopee_bruto' => null,
                // Quick 260904-kwz — mesma regra do ramo ao vivo: origem
                // 'grupo' é manual por si só; 'propria'/'servico' checam o
                // contrato assinado da empresa-âncora deste snapshot. Fase
                // 141 (T-141-18): mesma correção — procedência
                // 'presumida_servico' nunca confirma.
                'tabela_confirmada'     => $this->fechamentoTabelaConfirmada($s?->tabela_origem, $s?->empresa_ancora_id, $companyIdsComContratoAssinado, $dadosPorId[$ancoraId]['procedencia_tabela'] ?? null),
                'tabelas_divergentes'   => (bool) ($s?->tabelas_divergentes ?? false),
                'cobranca_mensal'       => $s?->cobranca_mensal !== null ? (float) $s->cobranca_mensal : null,
                'evolucao'              => $s?->evolucao,
                'conta_no_total'        => true,
                // Fase 139 (D-04): reconstruído do snapshot de grupo do mês
                // anterior — nunca recalcula (D-11).
                'faixa_ordem_anterior'  => $ordemAnteriorGrupo,
                'valor_faixa_anterior'  => $valorFaixaAnteriorGrupo,
                'subiu_de_faixa'        => $upgradeGrupo['subiu_de_faixa'],
                'ganho_faixa'           => $upgradeGrupo['ganho_faixa'],
            ];
        }

        return $linhasFinais;
    }

    /**
     * Fase 142 (142-01, D-01) — mapeia uma coleção de faixas (linhas de
     * `EmpresaFaixaFaturamento`, já carregadas pelo resolver ou por consulta
     * em lote) para o shape que a prop `tabela_faixas` expõe à tela:
     * `ordem`/`limite_superior`/`valor`/`valor_e_piso`, ordenadas.
     */
    private function fechamentoMapFaixasParaProps(Collection $faixas): array
    {
        return $faixas->sortBy('ordem')->values()
            ->map(fn (EmpresaFaixaFaturamento $f) => [
                'ordem'           => (int) $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])->all();
    }

    /**
     * Fase 137 — catálogo de serviços candidatos a "dono de tabela" (D-01)
     * com suas faixas, para a seção de cadastro de tabela do plano 09.
     */
    private function fechamentoFaixasPorServico(): array
    {
        return Servico::query()
            ->where('ativo', true)
            ->where(function ($q) {
                $q->whereIn('setor', Servico::SETORES_FINANCEIROS)
                    ->orWhereNotNull('plataforma');
            })
            ->orderBy('nome')
            ->get(['id', 'nome', 'plataforma', 'setor'])
            ->map(fn (Servico $servico) => [
                'id'         => $servico->id,
                'nome'       => $servico->nome,
                'plataforma' => $servico->plataforma,
                'faixas'     => ServicoFaixaFaturamento::where('servico_id', $servico->id)
                    ->ordenadas()
                    ->get(['ordem', 'limite_superior', 'valor', 'valor_e_piso'])
                    ->map(fn (ServicoFaixaFaturamento $f) => [
                        'ordem'           => $f->ordem,
                        'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                        'valor'           => (float) $f->valor,
                        'valor_e_piso'    => (bool) $f->valor_e_piso,
                    ])->values()->all(),
            ])->values()->all();
    }

    /**
     * Fase 138 (D-01) — catálogo dos grupos que têm tabela própria
     * cadastrada, com suas faixas, para a seção de cadastro de tabela do
     * grupo do plano 06. Uma consulta pra faixas + uma pra nomes de grupo —
     * nunca uma query por grupo (T-138-11).
     */
    private function fechamentoFaixasPorGrupo(): array
    {
        $faixasPorGrupoId = GrupoFaixaFaturamento::query()
            ->ordenadas()
            ->get(['company_group_id', 'ordem', 'limite_superior', 'valor', 'valor_e_piso'])
            ->groupBy('company_group_id');

        return CompanyGroup::query()
            ->whereIn('id', $faixasPorGrupoId->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CompanyGroup $grupo) => [
                'id'     => $grupo->id,
                'nome'   => $grupo->name,
                'faixas' => $faixasPorGrupoId->get($grupo->id, collect())
                    ->sortBy('ordem')
                    ->map(fn (GrupoFaixaFaturamento $f) => [
                        'ordem'           => $f->ordem,
                        'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                        'valor'           => (float) $f->valor,
                        'valor_e_piso'    => (bool) $f->valor_e_piso,
                    ])->values()->all(),
            ])->values()->all();
    }

    /**
     * Fase 137 (D-11) — a competência já foi fechada por
     * `fechamento:consolidar-mes`? Mesmo teste usado por `fechamento()`.
     * `gerarRelatorio()`/`gerarRelatorioGeral()` precisam do mesmo teste pra
     * bifurcar entre ao-vivo e congelado.
     */
    private function relatorioCompetenciaFechada(string $mesReferenciaStr): bool
    {
        return FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->exists();
    }

    /**
     * Fase 137 (D-08/D-09) — empresas-irmãs do mesmo `CompanyGroup` de
     * `$company`, excluindo ela própria. `parent_company_id` NUNCA participa
     * (D-08) — quem manda é `company_group_id`, mantido pelo Comercial.
     */
    private function relatorioVinculadasDoGrupo(Company $company): Collection
    {
        if ($company->company_group_id === null) {
            return collect();
        }

        return Company::where('company_group_id', $company->company_group_id)
            ->where('id', '!=', $company->id)
            ->where('active', true)
            ->with(['contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Fase 137 (D-02b) — rótulo legível de faixa a partir dos campos que
     * `fechamentoDadosPorEmpresaAoVivo()`/`fechamentoDadosPorEmpresaCongelados()`/
     * `fechamentoAgregarGrupos*()` já calculam (`faixa_ordem`,
     * `faixa_limite_inferior`, `faixa_limite_superior`) — nunca reclassifica.
     * Usado pelos três relatórios (PDF por empresa, relatório geral, e-mail).
     */
    private function relatorioFaixaLabel(?int $ordem, ?float $limiteInferior, ?float $limiteSuperior): ?string
    {
        if ($ordem === null) {
            return null;
        }

        if ($limiteSuperior === null) {
            return 'Faixa máxima (acima de R$ ' . number_format($limiteInferior ?? 0, 0, ',', '.') . ')';
        }

        return 'Faixa ' . $ordem . ' (R$ ' . number_format($limiteInferior ?? 0, 0, ',', '.')
            . ' – R$ ' . number_format($limiteSuperior, 0, ',', '.') . ')';
    }

    /**
     * Fase 137 — payload de uma linha (empresa própria, âncora de grupo ou
     * vinculada) para os relatórios PDF/e-mail, a partir do array já
     * calculado por `fechamentoDadosPorEmpresaAoVivo()`/`Congelados()`/
     * `fechamentoAgregarGrupos*()` — NUNCA recalcula faixa ou cobrança, só
     * reformata pro Blade (D-01/D-02b/D-05/D-06/D-08 já resolvidos lá).
     *
     * @param  array<string, mixed>  $d  entrada de `$dadosPorId` para esta empresa/grupo
     */
    private function relatorioLinhaEmpresa(Company $c, array $d, string $periodoInicioFmt, string $periodoFimFmt): array
    {
        $faturamento = $d['faturamento'] ?? null;

        return [
            'id'                   => $c->id,
            'name'                 => $c->name,
            'cnpj'                 => $c->cnpj,
            'adman_account_id'     => $c->cust_id,
            'adman_store_id'       => $c->adman_store_id ?? null,
            'ml_store_id'          => $c->ml_store_id,
            'segment'              => $c->segment,
            'servicos_contratados' => $c->contratosServico->where('ativo', true)->pluck('servico.nome')->filter()->implode(', '),
            'faturamento'          => $faturamento,
            'periodo_inicio'       => $faturamento !== null ? $periodoInicioFmt : null,
            'periodo_fim'          => $faturamento !== null ? $periodoFimFmt    : null,
            'faixa_label'          => $this->relatorioFaixaLabel($d['faixa_ordem'] ?? null, $d['faixa_limite_inferior'] ?? null, $d['faixa_limite_superior'] ?? null),
            'valor_mensal'         => $d['valor_mensal'] ?? null,
            'valor_e_piso'         => (bool) ($d['valor_faixa_e_piso'] ?? false),
            'cobranca_mensal'      => $d['cobranca_mensal'] ?? null,
        ];
    }

    public function syncFaturamento(Request $request, AdmanService $adman)
    {
        $mes = $request->filled('mes')
            ? Carbon::createFromFormat('Y-m', $request->input('mes'))->format('Y-m')
            : Carbon::now()->format('Y-m');

        $companies = Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->get();

        // Operação longa — remove o limite de 120s do PHP para este request
        set_time_limit(0);

        $results = ['success' => 0, 'failed' => 0];

        foreach ($companies as $company) {
            try {
                $adman->syncMonthRevenue($company, $mes);
                $results['success']++;
                usleep(500_000);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("[Faturamento] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
                $results['failed']++;
            }
        }

        return response()->json([
            'message'   => "Faturamento sincronizado: {$results['success']} empresa(s) atualizadas.",
            'synced_at' => now()->format('H:i:s'),
            'results'   => $results,
        ]);
    }

    public function updateFechamento(Request $request, Company $company)
    {
        // Phase 14 Plan 14-06: a gestao de servicos saiu deste endpoint e
        // passou a usar exclusivamente as rotas de contratos de servico.
        return back();
    }

    public function gerarRelatorio(Request $request, Company $company)
    {
        // Determina mês de referência
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }
        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado   = $ref->format('Y-m');
        $mesReferenciaStr = $ref->copy()->startOfMonth()->toDateString();

        // D-06 — acaba a janela móvel de 30 dias: toda competência é
        // mês-calendário fechado, via a ÚNICA implementação (mesma de
        // fechamento()/gerarRelatorioGeral()).
        $janela   = $this->rollupService->janela($mesSelecionado);
        $inicio   = $janela['inicio'];
        $fim      = $janela['fim'];
        $mesLabel = ucfirst($ref->translatedFormat('F Y'));

        // Phase 14 (Frente B): contratosServico.servico eager-loaded para evitar
        // N+1 ao calcular cobrança_mensal via CobrancaCalculator::novo.
        $company->load([
            'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
            'grupo:id,name,color',
        ]);

        // D-08 — o "grupo" do relatório é o CompanyGroup, nunca a
        // hierarquia legada de pai/filha (D-09).
        $vinculadasCompanies = $this->relatorioVinculadasDoGrupo($company);
        $titulo              = $company->grupo->name ?? $company->name;

        $todasEmpresas = collect([$company])->merge($vinculadasCompanies);

        // Quick 260904-kwz — mesma consulta em massa de fechamento().
        $companyIdsComContratoAssinado = $this->fechamentoCompanyIdsComContratoAssinado();

        // Fase 141 — mesma flag de fechamento(), para o PDF individual nunca
        // divergir da tela (T-141-19). Fase 142 (142-01): a procedência da
        // tabela própria agora é lida DENTRO de
        // `fechamentoDadosPorEmpresaCongelados()`, junto da mesma consulta
        // em lote das linhas — nenhum mapa à parte precisa ser montado aqui.
        $regraNova = $this->regra->ativa();

        // D-11 — mesma bifurcação de fechamento(): competência congelada lê
        // fechamento_snapshots, nunca recalcula (D-05: ML+Shopee já somados
        // e classificados pelas mesmas fontes centrais).
        $dadosPorId = $this->relatorioCompetenciaFechada($mesReferenciaStr)
            ? $this->fechamentoDadosPorEmpresaCongelados($todasEmpresas, $mesReferenciaStr, $inicio, $fim, $companyIdsComContratoAssinado)
            : $this->fechamentoDadosPorEmpresaAoVivo($todasEmpresas, $mesSelecionado, $inicio, $fim, $companyIdsComContratoAssinado, $regraNova);

        $periodoInicioFmt = $inicio->format('d/m/Y');
        $periodoFimFmt    = $fim->format('d/m/Y');

        $linhaPai = $this->relatorioLinhaEmpresa($company, $dadosPorId[$company->id], $periodoInicioFmt, $periodoFimFmt);

        $vinculadas = $vinculadasCompanies
            ->map(fn (Company $f) => $this->relatorioLinhaEmpresa($f, $dadosPorId[$f->id], $periodoInicioFmt, $periodoFimFmt))
            ->values()
            ->toArray();

        // Totais do grupo
        $totalFaturamento = ($linhaPai['faturamento'] ?? 0) + collect($vinculadas)->sum('faturamento');
        $totalMensalidade = ($linhaPai['cobranca_mensal'] ?? 0) + collect($vinculadas)->sum('cobranca_mensal');

        return view('admin.relatorio-fechamento', [
            'company'          => $company,
            'titulo'           => $titulo,
            'mes_label'        => $mesLabel,
            'mes_selecionado'  => $mesSelecionado,
            'metrica'          => null, // legacy — preservado vazio pra compat com a view
            'faturamento'      => $linhaPai['faturamento'],
            'periodo_inicio'   => $linhaPai['periodo_inicio'],
            'periodo_fim'      => $linhaPai['periodo_fim'],
            'faixa_label'      => $linhaPai['faixa_label'],
            'valor_mensal'     => $linhaPai['valor_mensal'],
            'valor_e_piso'     => $linhaPai['valor_e_piso'],
            'cobranca_mensal'  => $linhaPai['cobranca_mensal'],
            // Phase 14 (Frente B): label de serviços derivado do modelo N:N para
            // a Blade view consumir gradualmente. O label legacy
            'servicos_contratados_pai' => $company->contratosServico->where('ativo', true)->pluck('servico.nome')->filter()->implode(', '),
            'vinculadas'       => $vinculadas,
            'total_faturamento'=> $totalFaturamento,
            'total_mensalidade'=> $totalMensalidade,
            'gerado_em'        => Carbon::now()->format('d/m/Y \à\s H:i'),
        ]);
    }

    public function gerarRelatorioGeral(Request $request)
    {
        // Determina mês de referência
        try {
            $ref = $request->filled('mes')
                ? Carbon::createFromFormat('Y-m', $request->input('mes'))->startOfMonth()
                : Carbon::now();
        } catch (\Exception) {
            $ref = Carbon::now();
        }
        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado   = $ref->format('Y-m');
        $mesReferenciaStr = $ref->copy()->startOfMonth()->toDateString();

        // D-06 — mesma implementação única de janela/label de
        // fechamento()/gerarRelatorio() (sem 30d rolling).
        $janela   = $this->rollupService->janela($mesSelecionado);
        $inicio   = $janela['inicio'];
        $fim      = $janela['fim'];
        $mesLabel = ucfirst($ref->translatedFormat('F Y'));

        // D-08 — carrega TODAS as empresas ativas (não mais só as sem
        // hierarquia de pai, filtro antigo); a agregação por CompanyGroup
        // logo abaixo decide quem vira âncora de cada linha do relatório.
        $query = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            ->orderBy('name');

        if ($request->filled('servico_nome')) {
            $nomeServico = $request->input('servico_nome');
            $query->whereHas('contratosServico', fn ($q) =>
                $q->where('ativo', true)
                  ->whereHas('servico', fn ($qs) => $qs->where('nome', $nomeServico))
            );
        }

        $rawCompanies = $query->get();

        $competenciaFechada = $this->relatorioCompetenciaFechada($mesReferenciaStr);

        // Quick 260904-kwz — mesma consulta em massa de fechamento().
        $companyIdsComContratoAssinado = $this->fechamentoCompanyIdsComContratoAssinado();

        // Fase 141 — mesma flag de fechamento(), para o PDF geral nunca
        // divergir da tela (T-141-19). Fase 142 (142-01): a procedência da
        // tabela própria agora é lida DENTRO das duas funções congeladas
        // abaixo, junto da mesma consulta em lote das linhas — nenhum mapa
        // à parte precisa ser montado aqui.
        $regraNova = $this->regra->ativa();

        // Mesmo pipeline de fechamento(): números por empresa (congelado ou
        // ao vivo, D-05/D-11) e depois agregação por CompanyGroup
        // (D-08/D-09/D-10) — nunca a hierarquia legada de pai/filha.
        $dadosPorId = $competenciaFechada
            ? $this->fechamentoDadosPorEmpresaCongelados($rawCompanies, $mesReferenciaStr, $inicio, $fim, $companyIdsComContratoAssinado)
            : $this->fechamentoDadosPorEmpresaAoVivo($rawCompanies, $mesSelecionado, $inicio, $fim, $companyIdsComContratoAssinado, $regraNova);

        // Quick 260909-e8n — mesmo corte de escopo da tela (pendente / sem
        // serviço / só Polos, com as travas). O PDF do relatório geral tem
        // que listar exatamente quem a tela lista.
        $dadosPorId = $this->fechamentoRemoverForaDeEscopo($dadosPorId, $rawCompanies);

        $dadosPorId = $competenciaFechada
            ? $this->fechamentoAgregarGruposCongelados($dadosPorId, $rawCompanies, $mesReferenciaStr, $companyIdsComContratoAssinado)
            : $this->fechamentoAgregarGruposAoVivo($dadosPorId, $rawCompanies, $mesReferenciaStr, $companyIdsComContratoAssinado, $regraNova);

        $periodoInicioFmt = $inicio->format('d/m/Y');
        $periodoFimFmt    = $fim->format('d/m/Y');

        $relatorios = [];
        foreach ($dadosPorId as $ancoraId => $linha) {
            $ancora = $rawCompanies->firstWhere('id', $ancoraId);
            if ($ancora === null) {
                continue;
            }

            $linhaPrincipal = $this->relatorioLinhaEmpresa($ancora, $linha, $periodoInicioFmt, $periodoFimFmt);

            // "Vinculadas" = demais membros do grupo (D-08). Empresa
            // standalone vem com 'filhas' => [] de fechamentoAgregarGrupos*().
            $vinculadas = collect($linha['filhas'] ?? [])
                ->map(function (array $lm) use ($rawCompanies, $periodoInicioFmt, $periodoFimFmt) {
                    $c = $rawCompanies->firstWhere('id', $lm['id']);
                    return $c !== null ? $this->relatorioLinhaEmpresa($c, $lm, $periodoInicioFmt, $periodoFimFmt) : null;
                })
                ->filter()
                ->values()
                ->toArray();

            $titulo = ($linha['tipo'] ?? 'empresa') === 'grupo'
                ? ($linha['grupo']['name'] ?? $ancora->name)
                : $ancora->name;

            $relatorios[] = [
                'company'           => $ancora,
                'titulo'            => $titulo,
                'faturamento'       => $linhaPrincipal['faturamento'],
                'periodo_inicio'    => $linhaPrincipal['periodo_inicio'],
                'periodo_fim'       => $linhaPrincipal['periodo_fim'],
                'faixa_label'       => $linhaPrincipal['faixa_label'],
                'valor_mensal'      => $linhaPrincipal['valor_mensal'],
                'valor_e_piso'      => $linhaPrincipal['valor_e_piso'],
                'cobranca_mensal'   => $linhaPrincipal['cobranca_mensal'],
                'vinculadas'        => $vinculadas,
                'total_mensalidade' => ($linhaPrincipal['cobranca_mensal'] ?? 0) + collect($vinculadas)->sum('cobranca_mensal'),
            ];
        }

        // A chave de agregação por grupo (id da âncora) não preserva a ordem
        // alfabética da query original — reordena por título pra manter a
        // apresentação estável.
        usort($relatorios, fn ($a, $b) => strcmp($a['titulo'], $b['titulo']));

        return view('admin.relatorio-geral', [
            'relatorios'      => $relatorios,
            'mes_label'       => $mesLabel,
            'mes_selecionado' => $mesSelecionado,
            'gerado_em'       => Carbon::now()->format('d/m/Y \à\s H:i'),
        ]);
    }

    // ── Configurações do módulo financeiro ────────────────────────────────────

    /**
     * Exibe a página de configuração de destinatários e agendamento do relatório mensal.
     */
    public function configuracoesFinanceiro()
    {
        $json          = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];
        $ultimoEnvio   = Configuracao::get('email_ultimo_envio_fechamento');

        return Inertia::render('Admin/ConfiguracoesFinanceiro', [
            'destinatarios'        => $destinatarios,
            'ultimo_envio'         => $ultimoEnvio,
            'envio_auto_ativo'     => Configuracao::get('email_envio_auto_ativo', '0') === '1',
            'envio_auto_dia'       => (int) Configuracao::get('email_envio_auto_dia', '5'),
            'envio_auto_hora'      => Configuracao::get('email_envio_auto_hora', '09:00'),
        ]);
    }

    /**
     * Persiste destinatários e configurações de agendamento do relatório mensal.
     */
    public function salvarConfiguracoesFinanceiro(Request $request)
    {
        $validated = $request->validate([
            'destinatarios'   => 'array',
            'destinatarios.*' => 'email',
            'envio_auto_ativo' => 'required|boolean',
            'envio_auto_dia'   => 'required|integer|min:1|max:28',
            'envio_auto_hora'  => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        Configuracao::set('email_destinatarios_fechamento', json_encode($validated['destinatarios'] ?? []));
        Configuracao::set('email_envio_auto_ativo', $validated['envio_auto_ativo'] ? '1' : '0');
        Configuracao::set('email_envio_auto_dia',   (string) $validated['envio_auto_dia']);
        Configuracao::set('email_envio_auto_hora',  $validated['envio_auto_hora']);

        return back()->with('success', 'Configurações salvas com sucesso.');
    }

    /**
     * Despacha o job de envio do relatório geral de fechamento por email.
     * Retorna JSON para consumo via axios no frontend.
     */
    public function enviarRelatorioGeral(Request $request)
    {
        $request->validate(['mes' => 'required|string|regex:/^\d{4}-\d{2}$/']);

        // Verifica se existem destinatários configurados antes de despachar
        $json         = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];

        if (empty($destinatarios)) {
            return response()->json(['message' => 'Nenhum destinatário configurado.'], 422);
        }

        // dispatchSync: executa imediatamente (sem depender de queue worker)
        EnviarRelatorioFechamentoJob::dispatchSync($request->input('mes'), auth()->id());

        return response()->json(['message' => 'Relatório enviado para ' . count($destinatarios) . ' email(s).']);
    }

    public function inventario()
    {
        return Inertia::render('Admin/Inventario');
    }
}
