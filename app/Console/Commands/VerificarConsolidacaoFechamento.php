<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fase 137 (Plano 05, Tarefa 3) — conferência READ-ONLY de uma competência
 * do fechamento mensal, por RECONSULTA direta às tabelas de snapshot.
 *
 * POR QUE ESTE COMANDO EXISTE
 * (.planning/learnings/desempenho-bonificacao.md §4 e §10.1): o gate de
 * cobertura de `fechamento:consolidar-mes` recusa gravar amostra degradada
 * e reporta apenas uma CONTAGEM no stdout — os nomes só vão para
 * `Log::error`. A disciplina que este projeto já pagou caro para aprender é
 * que "o comando disse que deu certo" NUNCA é o critério de verificação.
 * NENHUMA linha do texto que este comando mesmo imprime é critério de
 * nada — o contrato real é a saída `--json` e o EXIT CODE. SUCCESS
 * (exit code 0) só acontece com ZERO inconsistências.
 *
 * READ-ONLY: nenhuma escrita, nenhum dispatch de job, nenhuma chamada HTTP.
 * Um verificador que corrige o que encontra esconderia a inconsistência em
 * vez de expô-la.
 *
 * ── As 5 classes de inconsistência ─────────────────────────────────────
 *
 *  SEM_SNAPSHOT — empresa ATIVA com integração financeira (`cust_id` ou
 *  pelo menos uma linha em `shopee_metrics`, qualquer data) sem linha em
 *  `fechamento_snapshots` na competência. Ação: re-rodar
 *  `fechamento:consolidar-mes --mes=` (ou investigar o `Log::error` do gate
 *  de cobertura, se o comando recusou o lote inteiro).
 *
 *  LINHAS_ORFAS — existe linha em `fechamento_grupo_snapshots` para um
 *  grupo, mas NENHUMA linha em `fechamento_snapshots` cujo
 *  `company_group_id` aponte para ele na mesma competência. Ação:
 *  reconsolidar — o grupo não tem detalhe por empresa que o sustente.
 *
 *  DIVERGENCIA_SOMA_GRUPO — `faturamento_total` do grupo diverge (tolerância
 *  0,01) da SOMA de `faturamento_total` das linhas de empresa daquele grupo
 *  na competência. D-10 exige que sejam a MESMA fonte — nunca recalculada
 *  em paralelo. Ação: investigar antes de confiar no número — pode ser
 *  reconsolidação parcial ou escrita fora do writer.
 *
 *  DIVERGENCIA_CONTAGEM — `empresas_count` do grupo diverge do número real
 *  de linhas de empresa daquele grupo na competência. Mesma ação acima.
 *
 *  ORIGEM_NAO_CONGELADA — competência FECHADA (mês anterior ao corrente)
 *  com pelo menos uma linha (empresa ou grupo) cuja `origem` não é
 *  `consolidar_mes` — só essa origem representa o fechamento oficial nesta
 *  fase. Ação: reconsolidar a competência.
 *
 * @see App\Console\Commands\ConsolidarMesFechamento
 * @see App\Services\Fechamento\FechamentoSnapshotWriter
 */
class VerificarConsolidacaoFechamento extends Command
{
    protected $signature = 'fechamento:verificar-consolidacao
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--json : saída em JSON, parseável, sem nenhum outro texto}';

    protected $description = 'Confere uma competência do fechamento por RECONSULTA (read-only) às tabelas de snapshot. O exit code é o veredito, nunca o texto impresso.';

    private const TOLERANCIA_SOMA_GRUPO = 0.01;

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // Mesma regra ancorada no dia 1 explícito de
                // ConsolidarMesFechamento — nunca formato sem o dia.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[VerificarConsolidacao] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        }

        $mesStr     = $mes->toDateString();
        $mesLabel   = $mes->format('Y-m');
        $mesFechado = $mes->lt(Carbon::now()->startOfMonth());

        $relatorio = $this->montarRelatorio($mesStr, $mesLabel, $mesFechado);

        if ($this->option('json')) {
            $this->line(json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprimirRelatorioHumano($relatorio);
        }

        return $relatorio['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Monta o relatório inteiro por RECONSULTA — nenhuma escrita.
     */
    private function montarRelatorio(string $mesStr, string $mesLabel, bool $mesFechado): array
    {
        // Empresas ativas com integração financeira — mesma definição de
        // "tem_integracao" usada por ConsolidarMesFechamento::handle().
        $companyIdsComShopee = ShopeeMetric::query()->distinct()->pluck('company_id')->flip();

        $empresasElegiveis = Company::query()
            ->where('active', true)
            ->get()
            ->filter(fn (Company $c) => $c->cust_id !== null || $companyIdsComShopee->has($c->id));

        $snapshotsEmpresa      = FechamentoSnapshot::query()->whereDate('mes_referencia', $mesStr)->get();
        $snapshotsEmpresaPorId = $snapshotsEmpresa->keyBy('company_id');

        $snapshotsGrupo = FechamentoGrupoSnapshot::query()->whereDate('mes_referencia', $mesStr)->get();

        $achados = [
            'SEM_SNAPSHOT'            => [],
            'LINHAS_ORFAS'            => [],
            'DIVERGENCIA_SOMA_GRUPO'  => [],
            'DIVERGENCIA_CONTAGEM'    => [],
            'ORIGEM_NAO_CONGELADA'    => [],
        ];

        // ── SEM_SNAPSHOT ──────────────────────────────────────────────
        foreach ($empresasElegiveis as $company) {
            if (! $snapshotsEmpresaPorId->has($company->id)) {
                $achados['SEM_SNAPSHOT'][] = [
                    'company_id'   => $company->id,
                    'company_name' => $company->name,
                ];
            }
        }

        // ── LINHAS_ORFAS / DIVERGENCIA_SOMA_GRUPO / DIVERGENCIA_CONTAGEM ─
        foreach ($snapshotsGrupo as $grupoSnap) {
            $membros = $snapshotsEmpresa->where('company_group_id', $grupoSnap->company_group_id);

            if ($membros->isEmpty()) {
                $achados['LINHAS_ORFAS'][] = [
                    'company_group_id' => $grupoSnap->company_group_id,
                    'grupo_name'       => $grupoSnap->grupo_name,
                ];

                // Sem membro nenhum, soma/contagem não têm o que comparar —
                // a linha órfã já cobre o problema real.
                continue;
            }

            $somaMembros = (float) $membros->sum(fn ($m) => $m->faturamento_total !== null ? (float) $m->faturamento_total : 0.0);
            $totalGrupo  = $grupoSnap->faturamento_total !== null ? (float) $grupoSnap->faturamento_total : 0.0;

            if (abs($totalGrupo - $somaMembros) > self::TOLERANCIA_SOMA_GRUPO) {
                $achados['DIVERGENCIA_SOMA_GRUPO'][] = [
                    'company_group_id'  => $grupoSnap->company_group_id,
                    'grupo_name'        => $grupoSnap->grupo_name,
                    'faturamento_grupo' => $totalGrupo,
                    'soma_membros'      => $somaMembros,
                ];
            }

            if ((int) $grupoSnap->empresas_count !== $membros->count()) {
                $achados['DIVERGENCIA_CONTAGEM'][] = [
                    'company_group_id' => $grupoSnap->company_group_id,
                    'grupo_name'       => $grupoSnap->grupo_name,
                    'empresas_count'   => (int) $grupoSnap->empresas_count,
                    'membros_reais'    => $membros->count(),
                ];
            }
        }

        // ── ORIGEM_NAO_CONGELADA — só se aplica a competência FECHADA ────
        if ($mesFechado) {
            foreach ($snapshotsEmpresa as $snap) {
                if ($snap->origem !== FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                    $achados['ORIGEM_NAO_CONGELADA'][] = [
                        'tipo'         => 'empresa',
                        'company_id'   => $snap->company_id,
                        'company_name' => $snap->company_name,
                        'origem'       => $snap->origem,
                    ];
                }
            }

            foreach ($snapshotsGrupo as $grupoSnap) {
                if ($grupoSnap->origem !== FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                    $achados['ORIGEM_NAO_CONGELADA'][] = [
                        'tipo'              => 'grupo',
                        'company_group_id'  => $grupoSnap->company_group_id,
                        'grupo_name'        => $grupoSnap->grupo_name,
                        'origem'            => $grupoSnap->origem,
                    ];
                }
            }
        }

        $inconsistencias = [];
        foreach ($achados as $classe => $entidades) {
            if ($entidades !== []) {
                $inconsistencias[] = [
                    'classe'     => $classe,
                    'quantidade' => count($entidades),
                    'entidades'  => $entidades,
                ];
            }
        }

        return [
            'mes'             => $mesLabel,
            'mes_referencia'  => $mesStr,
            'mes_fechado'     => $mesFechado,
            'gerado_em'       => now()->toIso8601String(),
            'total_empresas'  => $empresasElegiveis->count(),
            'total_grupos'    => $snapshotsGrupo->count(),
            'inconsistencias' => $inconsistencias,
            'ok'              => $inconsistencias === [],
        ];
    }

    /**
     * Saída CONVENIÊNCIA HUMANA — nenhum teste desta suíte pode depender
     * dela. O contrato real é `--json` + exit code.
     */
    private function imprimirRelatorioHumano(array $relatorio): void
    {
        $this->info(sprintf(
            '[VerificarConsolidacao] Competência %s (%s) — %d empresa(s) elegível(is), %d grupo(s).',
            $relatorio['mes'],
            $relatorio['mes_fechado'] ? 'fechada' : 'em curso',
            $relatorio['total_empresas'],
            $relatorio['total_grupos']
        ));

        if ($relatorio['ok']) {
            $this->info('[VerificarConsolidacao] Nenhuma inconsistência encontrada.');

            return;
        }

        $rows = [];
        foreach ($relatorio['inconsistencias'] as $inc) {
            $rows[] = [$inc['classe'], $inc['quantidade']];
        }

        $this->table(['Classe', 'Quantidade'], $rows);

        $this->warn('[VerificarConsolidacao] AVISO: esta tabela é CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = sem inconsistências) ou a saída --json — nunca este texto.');
    }
}
