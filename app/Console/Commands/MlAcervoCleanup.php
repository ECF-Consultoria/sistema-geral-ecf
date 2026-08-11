<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use Illuminate\Console\Command;

/**
 * Retenção do acervo Mercado Livre "Meus Anúncios" (Fase 134, D-07) — remove
 * linhas antigas da série diária enxuta (`ml_acervo_metricas_diarias`) e,
 * atrás de flag, linhas órfãs de `ml_acervo_itens` (empresa sem token ML
 * ativo). Molde: SyncVendasLogsCleanup (mlb:sync-vendas-logs-cleanup) —
 * options nomeadas, `delete()` direto, sem soft delete.
 */
class MlAcervoCleanup extends Command
{
    protected $signature = 'mlb:acervo-cleanup
        {--keep-days=90 : Remove linhas da série diária mais antigas que N dias}
        {--orfaos       : Remove linhas de ml_acervo_itens de empresas sem token ML ativo}';

    protected $description = 'Retenção da série diária do acervo Mercado Livre (Fase 134) e limpeza opcional de itens órfãos';

    /**
     * Exclusão em blocos, nunca um DELETE único sobre dezenas de milhões de
     * linhas — a tabela cresce na ordem de dezenas de milhões em regime
     * (T-134-15 do threat model).
     */
    private const TAMANHO_BLOCO = 10000;

    public function handle(): int
    {
        $keepDays = max(1, (int) ($this->option('keep-days') ?? config('mlb_acervo.retencao_dias')));

        $removidosSerie = $this->removerSerieAntiga($keepDays);
        $this->info("Linhas da série diária removidas (> {$keepDays} dias): {$removidosSerie}");

        if ($this->option('orfaos')) {
            $removidosOrfaos = $this->removerItensOrfaos();
            $this->info("Itens órfãos removidos (empresa sem token ML ativo): {$removidosOrfaos}");
        } else {
            $this->line('Limpeza de órfãos NÃO executada (use --orfaos para ativar).');
        }

        return self::SUCCESS;
    }

    /**
     * Remove em blocos de 10.000 linhas em laço, até não sobrar nada — um
     * DELETE único sobre dezenas de milhões de linhas prenderia a tabela.
     * `mamd_data_idx` sustenta o filtro por `data`.
     *
     * `startOfDay()` é obrigatório aqui: a coluna `data` tem cast `date`, e
     * o setter do Eloquent grava o formato completo do grammar
     * (`Y-m-d 00:00:00`) mesmo assim (mesmo achado do 134-05, ver
     * `MlAcervoDetalheService::gravarVisitasSerieDiaria()`). Sem
     * `startOfDay()`, `now()->subDays($keepDays)` carrega a hora atual da
     * execução — a linha de exatamente `$keepDays` dias atrás (gravada à
     * meia-noite) compararia como "menor que" o limite e seria apagada,
     * quebrando a fronteira exata que o D-07 exige (90 preservado, 91
     * removido).
     */
    private function removerSerieAntiga(int $keepDays): int
    {
        $limite = now()->subDays($keepDays)->startOfDay();
        $total = 0;

        do {
            $removidos = MlAcervoMetricaDiaria::where('data', '<', $limite)
                ->limit(self::TAMANHO_BLOCO)
                ->delete();

            $total += $removidos;
        } while ($removidos > 0);

        return $total;
    }

    /**
     * Remove linhas de `ml_acervo_itens` de empresas cujo `MlToken` não está
     * mais `active`. Atrás de flag DESLIGADA por padrão: token pode ficar
     * temporariamente inativo por falha de refresh, e apagar o acervo
     * inteiro de uma empresa por causa disso destruiria o último snapshot
     * que o D-08 promete continuar exibindo com selo de defasagem
     * (T-134-16 do threat model).
     */
    private function removerItensOrfaos(): int
    {
        $empresasSemTokenAtivo = Company::query()
            ->whereDoesntHave('mlToken', fn ($q) => $q->where('status', 'active'))
            ->pluck('id');

        if ($empresasSemTokenAtivo->isEmpty()) {
            return 0;
        }

        $total = 0;

        do {
            $removidos = MlAcervoItem::whereIn('company_id', $empresasSemTokenAtivo)
                ->limit(self::TAMANHO_BLOCO)
                ->delete();

            $total += $removidos;
        } while ($removidos > 0);

        return $total;
    }
}
