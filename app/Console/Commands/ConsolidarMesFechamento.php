<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\FechamentoRollupService;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use App\Support\CobrancaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fase 137 (D-08/D-09/D-10/D-11/D-12 revisado) — fecha uma competência do
 * fechamento mensal: grava faturamento, grupo, faixa aplicada e valor por
 * EMPRESA e por GRUPO, e congela (`FechamentoSnapshotWriter`).
 *
 * Molde: `App\Console\Commands\ConsolidarMesDesempenho`. Divergências
 * DELIBERADAS em relação a ele:
 *
 * 1. Grupo aqui é `CompanyGroup` (`company_group_id`, Comercial) — NUNCA
 *    `parent_company_id`. D-08 revoga o mecanismo antigo (5 empresas / 2
 *    pais) em favor do grupo do Comercial (46 empresas / 15 grupos).
 *    Consequência aceita (D-09): `MOVELOVEOFICIAL` deixa de somar com as 3
 *    filhas por não ter grupo no Comercial — conserto é no Comercial, não
 *    aqui.
 * 2. Reconsolidar competência já fechada EXIGE `--motivo=` explícito — o
 *    writer (`FechamentoSnapshotWriter`) lança `RuntimeException` sem ele.
 *    O Desempenho deixa a origem oficial ignorar a trava em silêncio; aqui
 *    não, porque o valor gravado entra em cobrança (D-12 revisado).
 * 3. Faturamento vem de `FechamentoRollupService::porEmpresa()` (mês-
 *    calendário fechado, D-06) — nunca `company_monthly_revenues` (fica com
 *    valor rolling-30-dias obsoleto após o mês virar).
 *
 * Fase 138 (D-01) — precedência de tabela do GRUPO: o Passo 5 classifica a
 * soma do grupo pela tabela resolvida por `FechamentoFaixaResolver::paraGrupo()`
 * (grupo → empresa-âncora → serviço), não mais só pela tabela da âncora.
 * `tabela_origem` da linha de grupo passa a poder valer `'grupo'` — nesse
 * caso não houve herança; em qualquer outro valor a tabela veio da empresa
 * `empresa_ancora_id`, que CONTINUA sempre preenchida (é a identidade da
 * linha, usada por `AdminController::fechamentoAgregarGruposCongelados` para
 * reencontrá-la no ramo congelado). "Herdada de quem" é derivado de
 * `tabela_origem`, nunca uma coluna nova.
 *
 * Gate de qualidade (Passo 6, mesmo espírito do FIXMARG-03 do Desempenho):
 * cobertura de faturamento abaixo de `COBERTURA_MINIMA_FATURAMENTO` entre as
 * empresas com integração financeira NÃO grava nada e retorna exit code 1 —
 * nunca sobrescreve um snapshot bom por um degradado.
 *
 * O exit code reflete a falha real — qualquer empresa que estoure exceção
 * individual conta como falha e o comando termina com 1, mesmo tendo
 * gravado as demais. O texto impresso (`$this->info()`/`$this->error()`) é
 * conveniência operacional, NUNCA critério de verificação — a conferência
 * oficial é `fechamento:verificar-consolidacao --json` (plano 05, tarefa 3)
 * e a reconsulta direta às tabelas de snapshot
 * (.planning/learnings/desempenho-bonificacao.md §4).
 */
class ConsolidarMesFechamento extends Command
{
    protected $signature = 'fechamento:consolidar-mes
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--motivo= : obrigatório quando a competência já está fechada (D-12)}
        {--por= : id do usuário que acionou}
        {--se-ausente : sai com sucesso e sem escrever se a competência já estiver fechada}';

    protected $description = 'Fecha uma competência do fechamento mensal (empresa + grupo), gravando faturamento, faixa aplicada e valor. Congela — refazer exige --motivo= (D-12).';

    /**
     * Cobertura mínima (fração de empresas com integração financeira que
     * têm faturamento_total não nulo) exigida para o congelamento ACEITAR
     * a amostra — mesmo espírito do FIXMARG-03 do Desempenho.
     */
    private const COBERTURA_MINIMA_FATURAMENTO = 0.7;

    public function __construct(
        private FechamentoRollupService $rollupService,
        private FechamentoFaixaResolver $faixaResolver,
        private FechamentoSnapshotWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // NUNCA ancorar sem o dia explícito (formato 'Y-m' puro) —
                // sem o dia, o PHP preenche com o dia de hoje e estoura para
                // o mês seguinte quando o mês alvo tem menos dias.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[Fechamento] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        }

        if ($mes->gt(Carbon::now()->startOfMonth())) {
            $this->error('[Fechamento] Não é possível consolidar competência futura: '.$mes->format('Y-m'));

            return self::FAILURE;
        }

        $mesStr   = $mes->toDateString();
        $mesLabel = $mes->format('Y-m');

        // Passo 7 (checado cedo, por eficiência — a decisão não depende de
        // nenhum cálculo abaixo): --se-ausente numa competência já
        // congelada sai com sucesso e SEM escrever nada.
        $jaCongelado = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->where('origem', FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES)
            ->exists();

        if ($jaCongelado && $this->option('se-ausente')) {
            $this->info("[Fechamento] Competência {$mesLabel} já está fechada — nada a fazer (--se-ausente).");

            return self::SUCCESS;
        }

        // ── Passo 1 — conjunto de empresas ativas, com eager loading ──────
        $companies = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo',
            ])
            ->get();

        // ── Passo 2 — faturamento da competência e do mês anterior (D-06),
        //    UMA passada cada — nunca uma query por empresa (T-137-19).
        $mesAnterior     = $mes->copy()->subMonthNoOverflow()->startOfMonth();
        $rollupAtual     = $this->rollupService->porEmpresa($mesLabel, $companies);
        $rollupAnterior  = $this->rollupService->porEmpresa($mesAnterior->format('Y-m'), $companies);

        // Empresas com pelo menos uma linha em shopee_metrics (qualquer
        // data) — usado só para decidir "tem integração", não para o
        // faturamento em si.
        $companyIdsComShopee = ShopeeMetric::query()->distinct()->pluck('company_id')->flip();

        $snapshotsAnterioresPorEmpresa = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesAnterior->toDateString())
            ->get()
            ->keyBy('company_id');

        $linhasEmpresa   = [];
        $faixaPorEmpresa = []; // company_id => resultado de paraEmpresa() (reusado nos grupos)
        $houveExcecao    = false;

        foreach ($companies as $company) {
            try {
                $fatAtual = $rollupAtual[$company->id] ?? ['faturamento_ml' => null, 'faturamento_shopee' => null, 'faturamento_total' => null];

                $temIntegracao = $company->cust_id !== null || $companyIdsComShopee->has($company->id);

                $faixaData = $this->faixaResolver->paraEmpresa($company);
                $faixaPorEmpresa[$company->id] = $faixaData;

                $classificacao = ($faixaData !== null && $fatAtual['faturamento_total'] !== null)
                    ? $this->faixaResolver->classificar((float) $fatAtual['faturamento_total'], $faixaData['faixas'])
                    : null;

                // Precedência de estado (D-01/D-05/D-06/D-07 aplicados):
                if (! $temIntegracao) {
                    $estado = FechamentoSnapshot::ESTADO_SEM_INTEGRACAO;
                } elseif ($fatAtual['faturamento_total'] === null) {
                    $estado = FechamentoSnapshot::ESTADO_SEM_FATURAMENTO;
                } elseif ($faixaData === null || $classificacao === null) {
                    $estado = FechamentoSnapshot::ESTADO_SEM_TABELA;
                } else {
                    $estado = FechamentoSnapshot::ESTADO_OK;
                }

                // ── Passo 4 — evolução ────────────────────────────────────
                $ordemAnterior = null;
                $snapAnterior  = $snapshotsAnterioresPorEmpresa->get($company->id);

                if ($snapAnterior !== null && $snapAnterior->faixa_ordem !== null) {
                    $ordemAnterior = (int) $snapAnterior->faixa_ordem;
                } elseif ($faixaData !== null) {
                    // Sem snapshot anterior gravado: calcula o faturamento do
                    // mês anterior pelo MESMO rollup (já computado acima, sem
                    // 2ª query) e classifica com a MESMA tabela resolvida
                    // desta empresa — nunca comparar réguas diferentes.
                    $fatAnterior = $rollupAnterior[$company->id]['faturamento_total'] ?? null;

                    if ($fatAnterior !== null) {
                        $classifAnterior = $this->faixaResolver->classificar((float) $fatAnterior, $faixaData['faixas']);
                        $ordemAnterior   = $classifAnterior['ordem'] ?? null;
                    }
                }

                $evolucao = null;
                if ($ordemAnterior !== null && $classificacao !== null) {
                    $ordemAtual = $classificacao['ordem'];
                    if ($ordemAtual > $ordemAnterior) {
                        $evolucao = 'subiu';
                    } elseif ($ordemAtual < $ordemAnterior) {
                        $evolucao = 'desceu';
                    } else {
                        $evolucao = 'manteve';
                    }
                }

                // ── Cobrança mensal (faixa + contratos ativos mensais) ────
                $temContratoMensal = $company->contratosServico->contains(
                    fn ($c) => $c->ativo === true && $c->servico !== null && $c->servico->tipo_cobranca === Servico::TIPO_MENSAL
                );

                $cobrancaMensal = ($classificacao !== null || $temContratoMensal)
                    ? (CobrancaCalculator::novo($classificacao, $company->contratosServico) ?: null)
                    : null;

                $linhasEmpresa[] = [
                    'company_id'             => $company->id,
                    'company_name'           => $company->name,
                    'faturamento_ml'         => $fatAtual['faturamento_ml'],
                    'faturamento_shopee'     => $fatAtual['faturamento_shopee'],
                    'faturamento_total'      => $fatAtual['faturamento_total'],
                    'company_group_id'       => $company->company_group_id,
                    'servico_id'             => $faixaData['servico_id'] ?? null,
                    'tabela_origem'          => $faixaData['origem'] ?? null,
                    'faixa_ordem'            => $classificacao['ordem'] ?? null,
                    'faixa_aplicada'         => $classificacao['label'] ?? null,
                    'valor_faixa'            => $classificacao['valor'] ?? null,
                    'valor_faixa_e_piso'     => $classificacao['valor_e_piso'] ?? false,
                    'faixa_limite_inferior'  => $classificacao['limite_inferior'] ?? null,
                    'faixa_limite_superior'  => $classificacao['limite_superior'] ?? null,
                    'cobranca_mensal'        => $cobrancaMensal,
                    'evolucao'               => $evolucao,
                    'estado'                 => $estado,
                ];
            } catch (\Throwable $e) {
                Log::error("[Fechamento] Falha ao processar empresa {$company->id} ({$company->name}) na competência {$mesLabel}: {$e->getMessage()}");
                $houveExcecao = true;

                continue;
            }
        }

        // ── Passo 5 — grupos (D-08/D-09/D-10). parent_company_id NUNCA
        //    entra aqui — é a fonte antiga que D-08 revoga.
        $linhasPorEmpresaId = collect($linhasEmpresa)->keyBy('company_id');

        $gruposMembros = $companies
            ->filter(fn (Company $c) => $c->company_group_id !== null && $linhasPorEmpresaId->has($c->id))
            ->groupBy('company_group_id');

        $linhasGrupo = [];

        foreach ($gruposMembros as $groupId => $membros) {
            /** @var Collection<int, Company> $membros */
            $linhasMembros = $membros->map(fn (Company $c) => $linhasPorEmpresaId->get($c->id));

            $faturamentoMl     = null;
            $faturamentoShopee = null;
            $faturamentoTotal  = null;

            foreach ($linhasMembros as $linhaMembro) {
                if ($linhaMembro['faturamento_ml'] !== null) {
                    $faturamentoMl = ($faturamentoMl ?? 0.0) + (float) $linhaMembro['faturamento_ml'];
                }
                if ($linhaMembro['faturamento_shopee'] !== null) {
                    $faturamentoShopee = ($faturamentoShopee ?? 0.0) + (float) $linhaMembro['faturamento_shopee'];
                }
                if ($linhaMembro['faturamento_total'] !== null) {
                    $faturamentoTotal = ($faturamentoTotal ?? 0.0) + (float) $linhaMembro['faturamento_total'];
                }
            }

            // Âncora: empresa-membro de maior faturamento_total (empate
            // pelo menor id) — é a tabela DELA que classifica a soma.
            $ancora = $membros->sort(function (Company $a, Company $b) use ($linhasPorEmpresaId) {
                $fatA = (float) ($linhasPorEmpresaId->get($a->id)['faturamento_total'] ?? -INF);
                $fatB = (float) ($linhasPorEmpresaId->get($b->id)['faturamento_total'] ?? -INF);

                if ($fatA === $fatB) {
                    return $a->id <=> $b->id;
                }

                return $fatB <=> $fatA;
            })->first();

            // Fase 138 (D-01): resolve a tabela do GRUPO explicitamente via
            // paraGrupo(), em vez de reaproveitar $faixaPorEmpresa[$ancora->id].
            // Decisão registrada no SUMMARY do plano 138-03: como o degrau de
            // grupo já vive dentro de paraEmpresa(), o valor em
            // $faixaPorEmpresa[$ancora->id] JÁ traz origem='grupo' quando a
            // tabela do grupo existe — os dois caminhos PRECISAM concordar.
            // Mantemos a chamada explícita a paraGrupo() por legibilidade (o
            // Passo 5 lê "resolvo a tabela do grupo", não "reaproveito um
            // efeito colateral do Passo 3"), e um teste trava a concordância
            // entre os dois caminhos (Phase138ConsolidarGrupoTabelaTest) para
            // que uma mudança de precedência num dos dois nunca divirja em
            // silêncio do outro — divergência silenciosa em tabela de
            // cobrança só aparece na fatura do cliente.
            $faixaGrupo = $ancora->grupo !== null
                ? $this->faixaResolver->paraGrupo($ancora->grupo, $ancora)
                : ($faixaPorEmpresa[$ancora->id] ?? null); // defensivo: nunca deixar de gravar a linha

            $classificacaoGrupo = ($faixaGrupo !== null && $faturamentoTotal !== null)
                ? $this->faixaResolver->classificar($faturamentoTotal, $faixaGrupo['faixas'])
                : null;

            if ($faturamentoTotal === null) {
                $estadoGrupo = FechamentoSnapshot::ESTADO_SEM_FATURAMENTO;
            } elseif ($faixaGrupo === null || $classificacaoGrupo === null) {
                $estadoGrupo = FechamentoSnapshot::ESTADO_SEM_TABELA;
            } else {
                $estadoGrupo = FechamentoSnapshot::ESTADO_OK;
            }

            // tabelas_divergentes: pares (origem, servico_id) diferentes
            // entre as empresas-membro.
            $paresTabela = $membros
                ->map(fn (Company $c) => $faixaPorEmpresa[$c->id] ?? null)
                ->map(fn ($f) => $f === null ? 'null' : ($f['origem'].'|'.($f['servico_id'] ?? 'null')))
                ->unique();

            $tabelasDivergentes = $paresTabela->count() > 1;

            // Evolução do grupo — mesma lógica de empresa, mas comparando
            // contra o snapshot de grupo do mês anterior (se existir).
            $snapGrupoAnterior = \App\Models\FechamentoGrupoSnapshot::query()
                ->where('company_group_id', $groupId)
                ->whereDate('mes_referencia', $mesAnterior->toDateString())
                ->first();

            $evolucaoGrupo = null;
            if ($snapGrupoAnterior !== null && $snapGrupoAnterior->faixa_ordem !== null && $classificacaoGrupo !== null) {
                $ordemAtualGrupo = $classificacaoGrupo['ordem'];
                $ordemAnteriorGrupo = (int) $snapGrupoAnterior->faixa_ordem;

                if ($ordemAtualGrupo > $ordemAnteriorGrupo) {
                    $evolucaoGrupo = 'subiu';
                } elseif ($ordemAtualGrupo < $ordemAnteriorGrupo) {
                    $evolucaoGrupo = 'desceu';
                } else {
                    $evolucaoGrupo = 'manteve';
                }
            }

            // Cobrança do grupo: faixa da soma + SUM dos contratos mensais
            // de TODAS as empresas-membro (nunca só a âncora).
            $todosContratosDoGrupo = $membros->flatMap(fn (Company $c) => $c->contratosServico);
            $temContratoMensalGrupo = $todosContratosDoGrupo->contains(
                fn ($c) => $c->ativo === true && $c->servico !== null && $c->servico->tipo_cobranca === Servico::TIPO_MENSAL
            );

            $cobrancaMensalGrupo = ($classificacaoGrupo !== null || $temContratoMensalGrupo)
                ? (CobrancaCalculator::novo($classificacaoGrupo, $todosContratosDoGrupo) ?: null)
                : null;

            $linhasGrupo[] = [
                'company_group_id'      => $groupId,
                'grupo_name'            => $ancora->grupo?->name,
                'faturamento_ml'        => $faturamentoMl,
                'faturamento_shopee'    => $faturamentoShopee,
                'faturamento_total'     => $faturamentoTotal,
                'servico_id'            => $faixaGrupo['servico_id'] ?? null,
                'tabela_origem'         => $faixaGrupo['origem'] ?? null,
                'faixa_ordem'           => $classificacaoGrupo['ordem'] ?? null,
                'faixa_aplicada'        => $classificacaoGrupo['label'] ?? null,
                'valor_faixa'           => $classificacaoGrupo['valor'] ?? null,
                'valor_faixa_e_piso'    => $classificacaoGrupo['valor_e_piso'] ?? false,
                'faixa_limite_inferior' => $classificacaoGrupo['limite_inferior'] ?? null,
                'faixa_limite_superior' => $classificacaoGrupo['limite_superior'] ?? null,
                'cobranca_mensal'       => $cobrancaMensalGrupo,
                'evolucao'              => $evolucaoGrupo,
                'estado'                => $estadoGrupo,
                'empresas_count'        => $membros->count(),
                'empresa_ancora_id'     => $ancora->id,
                'tabelas_divergentes'   => $tabelasDivergentes,
            ];
        }

        // ── Passo 6 — gate de qualidade ANTES de persistir ────────────────
        $comIntegracao = collect($linhasEmpresa)->filter(fn ($l) => $l['estado'] !== FechamentoSnapshot::ESTADO_SEM_INTEGRACAO);
        $denominador   = $comIntegracao->count();
        $numerador     = $comIntegracao->filter(fn ($l) => $l['faturamento_total'] !== null)->count();
        $cobertura     = $denominador > 0 ? ($numerador / $denominador) : 1.0;

        if ($denominador > 0 && $cobertura < self::COBERTURA_MINIMA_FATURAMENTO) {
            $empresasAfetadas = $comIntegracao
                ->filter(fn ($l) => $l['faturamento_total'] === null)
                ->pluck('company_name')
                ->values()
                ->all();

            Log::error("[Fechamento] Cobertura de faturamento abaixo do mínimo na competência {$mesLabel} — congelamento RECUSADO.", [
                'mes_referencia'   => $mesStr,
                'cobertura'        => $cobertura,
                'minimo'           => self::COBERTURA_MINIMA_FATURAMENTO,
                'denominador'      => $denominador,
                'numerador'        => $numerador,
                'empresas_afetadas' => $empresasAfetadas,
            ]);

            $this->error(sprintf(
                '[Fechamento] Cobertura de faturamento %.1f%% abaixo do mínimo de %.0f%% — %d de %d empresas com integração sem faturamento. Congelamento RECUSADO, nada foi gravado.',
                $cobertura * 100,
                self::COBERTURA_MINIMA_FATURAMENTO * 100,
                $denominador - $numerador,
                $denominador
            ));

            return self::FAILURE;
        }

        // ── Passo 7 — congela via writer ──────────────────────────────────
        $motivo = $this->option('motivo');
        $por    = $this->option('por') !== null ? (int) $this->option('por') : null;

        try {
            $resultado = $this->writer->sync(
                $mes,
                $linhasEmpresa,
                $linhasGrupo,
                FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
                $por,
                $motivo,
            );
        } catch (\RuntimeException $e) {
            $this->error('[Fechamento] '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[Fechamento] Competência %s — empresas: %d linhas (podadas: %d) · grupos: %d linhas (podados: %d)%s',
            $mesLabel,
            $resultado['empresas_upserted'],
            $resultado['empresas_pruned'],
            $resultado['grupos_upserted'],
            $resultado['grupos_pruned'],
            $resultado['reconsolidado'] ? ' · RECONSOLIDADO' : ''
        ));

        // O exit code precisa refletir a falha real: qualquer empresa que
        // estourou exceção individual conta como falha, mesmo tendo
        // gravado as demais.
        return $houveExcecao ? self::FAILURE : self::SUCCESS;
    }
}
