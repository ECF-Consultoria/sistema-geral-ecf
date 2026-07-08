<?php

namespace App\Console\Commands;

use App\Http\Controllers\PolosController;
use Illuminate\Console\Command;

/**
 * Reconcilia (READ-ONLY) o faturamento que o Painel Polos mostra (Adman gross_billing)
 * contra o TGMV_LC do CSV POLOS MENSAL (a métrica da planilha), empresa-a-empresa.
 *
 * Objetivo: medir o gap exato e apontar a causa DOMINANTE da divergência —
 *   (a) métrica: gross_billing (Adman) >> TGMV_LC (CSV) por empresa;
 *   (b) roster: inclusão de "Fechamento" nos ativos (só no mês corrente);
 *   (c) empresas R$0 no CSV mas com venda real na Adman.
 *
 * Uso (rodar na VPS, onde vivem o cache/snapshot Adman e o roster de produção):
 *   php artisan polos:audit-faturamento
 *   php artisan polos:audit-faturamento --mes=202607 --top=40
 */
class AuditPolosFaturamento extends Command
{
    protected $signature = 'polos:audit-faturamento
        {--mes= : Mês YYYYMM (default: mais recente/corrente)}
        {--top=25 : Nº de empresas na tabela de maiores divergências}';

    protected $description = 'Reconcilia o faturamento do painel (Adman) x TGMV_LC do CSV/planilha, empresa-a-empresa (read-only).';

    public function handle(PolosController $polos): int
    {
        $mes = $this->option('mes');
        $top = max(1, (int) $this->option('top'));

        $this->info('Lendo CSV POLOS MENSAL + Adman (cache/snapshot)… pode levar alguns segundos.');
        try {
            $r = $polos->auditarFaturamento($mes ? (string) $mes : null);
        } catch (\Throwable $e) {
            $this->error('Falha: ' . $e->getMessage());
            return self::FAILURE;
        }

        $brl = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $pct = static fn ($n, $d) => ((float) $d) != 0.0 ? number_format($n / $d * 100, 1, ',', '.') . '%' : '—';

        $this->newLine();
        $this->line("<options=bold>Mês:</> {$r['mesLabel']} ({$r['mes']}) · " . ($r['parcial'] ? 'PARCIAL (corrente, Adman ao vivo)' : 'fechado'));
        $this->line("<options=bold>Ativos considerados:</> {$r['nAtivos']}  ·  Fechamento no roster: {$r['nFechamento']}");
        $this->newLine();

        $this->table(
            ['Métrica', 'Valor', '% do painel'],
            [
                ['Faturamento PAINEL (Adman gross_billing)', $brl($r['sumAdman']), '100%'],
                ['Faturamento PLANILHA (CSV TGMV_LC)',       $brl($r['sumCsv']),   $pct($r['sumCsv'], $r['sumAdman'])],
                ['DIFERENÇA (painel − planilha)',            $brl($r['diffTotal']), $pct($r['diffTotal'], $r['sumAdman'])],
                ['↳ contribuição de FECHAMENTO (Adman)',     $brl($r['sumFechamentoAdman']), $pct($r['sumFechamentoAdman'], $r['sumAdman'])],
                ["↳ {$r['nZeradasCsvComGross']} empresas R\$0 no CSV, mas c/ venda Adman", $brl($r['sumZeradasCsvComGross']), $pct($r['sumZeradasCsvComGross'], $r['sumAdman'])],
            ]
        );

        $this->newLine();
        $this->line('<options=bold>Distribuição de status</> — Painel (deriva do Adman) x Planilha (derivaria do CSV):');
        $this->table(
            ['Status', 'Painel (Adman)', 'Planilha (CSV)'],
            collect(['Sim', 'Em progresso', 'Não', 'Problema'])
                ->map(fn ($s) => [$s, $r['distAdman'][$s] ?? 0, $r['distCsv'][$s] ?? 0])
                ->push(['TOTAL', $r['nAtivos'], $r['nAtivos']])
                ->all()
        );

        $linhas = collect($r['linhas'])->sortByDesc(fn ($l) => abs($l['diff']))->take($top);
        $this->newLine();
        $this->line("<options=bold>Top {$top} maiores divergências (|Adman − CSV|):</>");
        $this->table(
            ['cust_id', 'Empresa', 'Fase', 'Painel (Adman)', 'Planilha (CSV)', 'Diff', 'No CSV?'],
            $linhas->map(fn ($l) => [
                $l['cust_id'],
                mb_strimwidth((string) $l['nome'], 0, 28, '…'),
                $l['fase'],
                $brl($l['adman']),
                $brl($l['csv']),
                $brl($l['diff']),
                $l['no_csv'] ? 'sim' : 'NÃO',
            ])->all()
        );

        $this->newLine();
        $this->line('<fg=gray>Leitura: se a DIFERENÇA vier concentrada em "Fechamento" → é o roster; se vier das empresas</>');
        $this->line('<fg=gray>"R$0 no CSV c/ venda Adman" ou de diffs positivos por toda a lista → é a métrica (gross ≠ TGMV).</>');
        $this->line('<fg=gray>Read-only: nada foi alterado. Compare "Faturamento PAINEL" com o total da sua planilha.</>');

        return self::SUCCESS;
    }
}
