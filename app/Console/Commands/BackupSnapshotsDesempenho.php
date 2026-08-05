<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Backup das tabelas de snapshot do módulo Desempenho antes de reconsolidar
 * uma competência — 2026-08-05.
 *
 * Existe por causa de um risco concreto: `desempenho:consolidar-mes --mes=`
 * SOBRESCREVE competência já congelada (é o caminho oficial de
 * reconsolidação, ver docblock daquele comando), e a nota congelada é o que
 * justifica bônus já pago. Uma releitura da mesma competência pode mudar
 * faixa de bonificação sem nenhuma mudança de código — já aconteceu nesta
 * casa: um profissional a 0,24 p.p. da fronteira de margem perdeu o bônus
 * numa releitura 14 horas depois (ver
 * `.planning/learnings/desempenho-bonificacao.md` §2).
 *
 * Sem um "antes" gravado, esse tipo de mudança é indetectável depois do fato.
 *
 * O backup é um JSON por competência em `storage/app/backups/desempenho/`,
 * com as linhas CRUAS das duas tabelas:
 *  - `desempenho_score_snapshots` (resumo por profissional, com breakdown)
 *  - `desempenho_company_score_snapshots` (detalhe por empresa)
 *
 * Só LEITURA na origem — nunca altera schema nem cria tabela. Restaurar é
 * reinserir o JSON, e é operação manual deliberada (não há comando de
 * restore automático: reverter competência é decisão de negócio, não de
 * rotina).
 *
 * Uso típico, antes de reconsolidar junho:
 *   php artisan desempenho:backup-snapshots --mes=2026-06
 *   php artisan desempenho:consolidar-mes  --mes=2026-06
 *   php artisan desempenho:verificar-consolidacao --mes=2026-06 --json
 */
class BackupSnapshotsDesempenho extends Command
{
    protected $signature = 'desempenho:backup-snapshots
        {--mes= : YYYY-MM da competência (default = todas)}
        {--dir=backups/desempenho : diretório no disco local}';

    protected $description = 'Salva em JSON os snapshots de desempenho de uma competência, antes de reconsolidar (só leitura).';

    public function handle(): int
    {
        $mes = $this->option('mes');

        if ($mes !== null && ! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $this->error('--mes precisa estar no formato YYYY-MM.');

            return self::FAILURE;
        }

        $competencia = $mes !== null ? $mes . '-01' : null;

        $resumo = DB::table('desempenho_score_snapshots')
            ->when($competencia, fn ($q) => $q->whereDate('mes_referencia', $competencia))
            ->get();

        $empresas = DB::table('desempenho_company_score_snapshots')
            ->when($competencia, fn ($q) => $q->whereDate('mes_referencia', $competencia))
            ->get();

        if ($resumo->isEmpty() && $empresas->isEmpty()) {
            $this->warn('Nada a salvar' . ($mes ? " para a competência {$mes}" : '') . '.');

            return self::SUCCESS;
        }

        // Timestamp no nome do arquivo — reconsolidar duas vezes no mesmo dia
        // não pode sobrescrever o backup da primeira, que é justamente o
        // estado mais antigo (e mais valioso) a preservar.
        $carimbo = now()->format('Ymd-His');
        $arquivo = rtrim($this->option('dir'), '/') . '/'
            . 'desempenho-' . ($mes ?? 'todas') . '-' . $carimbo . '.json';

        $payload = [
            'gerado_em'   => now()->toIso8601String(),
            'competencia' => $mes,
            'contagem'    => [
                'resumo_por_profissional' => $resumo->count(),
                'detalhe_por_empresa'     => $empresas->count(),
            ],
            'desempenho_score_snapshots'         => $resumo,
            'desempenho_company_score_snapshots' => $empresas,
        ];

        Storage::disk('local')->put(
            $arquivo,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $caminho = Storage::disk('local')->path($arquivo);

        $this->info('Backup gravado.');
        $this->line('  arquivo: ' . $caminho);
        $this->line('  resumo por profissional: ' . $resumo->count() . ' linhas');
        $this->line('  detalhe por empresa:     ' . $empresas->count() . ' linhas');

        // O tamanho serve de sanidade grosseira: arquivo minúsculo com
        // contagem alta indica escrita truncada.
        $this->line('  tamanho: ' . number_format(Storage::disk('local')->size($arquivo) / 1024, 1) . ' KB');

        return self::SUCCESS;
    }
}
