<?php

namespace App\Console\Commands;

use App\Services\Contratos\TerminoContratoService;
use Illuminate\Console\Command;

/**
 * Fase 202 (REN-01, D-03) — backfill do término dos contratos ativos.
 *
 * Dry-run por padrão: só grava com --aplicar. Nunca sobrescreve data digitada
 * por pessoa (origem manual). A conferência é por reconsulta ao banco, não
 * pela saída deste comando.
 */
class CalcularTerminoContratos extends Command
{
    protected $signature = 'contratos:calcular-termino {--aplicar : Grava as datas (sem isto é só simulação)} {--empresa= : Restringe a uma empresa (id)}';

    protected $description = 'Calcula e grava o término dos contratos ativos sem data (dry-run por padrão)';

    public function handle(TerminoContratoService $service): int
    {
        $aplicar   = (bool) $this->option('aplicar');
        $empresaId = $this->option('empresa') !== null && $this->option('empresa') !== ''
            ? (int) $this->option('empresa')
            : null;

        if (! $aplicar) {
            $this->warn('SIMULAÇÃO — nada foi gravado');
        }

        $linhas = collect($service->preencherTodos($aplicar, $empresaId));

        $this->table(
            ['Empresa', 'Serviço', 'Início (fonte)', 'Meses', 'Término antes → depois', 'Origem', 'Ação'],
            $linhas->map(fn (array $l) => [
                $l['company_nome'] ?? $l['company_id'],
                $l['servico_nome'] ?? '-',
                isset($l['inicio']) ? "{$l['inicio']} ({$l['inicio_fonte']})" : '-',
                $l['meses'] ?? '-',
                isset($l['termino_depois']) ? (($l['termino_antes'] ?? 'vazio').' → '.$l['termino_depois']) : '-',
                $l['origem_depois'] ?? '-',
                $l['acao'],
            ])->all()
        );

        $conta = fn (string $acao) => $linhas->where('acao', $acao)->count();

        $this->info('Total de linhas: '.$linhas->count());
        $this->line('  manter: '.$conta('manter'));
        $this->line('  marcar_manual: '.$conta('marcar_manual'));
        $this->line('  gravar_hubspot: '.$conta('gravar_hubspot'));
        $this->line('  gravar_calculada: '.$conta('gravar_calculada'));
        $this->line('  com data de contratação futura: '.$linhas->where('data_contratacao_futura', true)->count());
        $this->line('  com início vindo de assinatura: '.$linhas->where('inicio_fonte', 'assinatura')->count());

        $falhas = $conta('falha');
        if ($falhas > 0) {
            $this->error("  falhas: {$falhas} (ver storage/logs/ecf-webhooks.log)");
        }

        if ($aplicar) {
            $this->line('Conferir por reconsulta ao banco: SELECT data_vencimento_origem, COUNT(*) FROM contratos_servico WHERE ativo=1 GROUP BY 1');
        } else {
            $this->warn('SIMULAÇÃO — nada foi gravado');
        }

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
