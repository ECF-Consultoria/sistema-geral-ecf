<?php

namespace App\Console\Commands;

use App\Jobs\EcfWebhook\HandleRelatorioGeradoJob;
use App\Models\WebhookDelivery;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Comando para disparar manualmente o pipeline do Relatório Mensal Executivo (Phase 28).
 *
 * Útil para:
 *  - Testar em prod sem esperar o webhook real do parceiro (dia 5 às 09:00 UTC)
 *  - Re-emitir um mês específico se algo der errado
 *  - Smoke W4 do plano de execução
 *
 * Cria WebhookDelivery sintético (event_id='manual-{periodo}-{uuid}') + dispatch HandleRelatorioGeradoJob.
 * NÃO usa dispatchSync — vai para a queue normal (Decisão D-I: worker já roda em prod via supervisor).
 */
class RelatorioMensalDisparar extends Command
{
    protected $signature   = 'relatorios:disparar-mensal {periodo? : Período YYYYMM (default = mês anterior fechado)}';
    protected $description = 'Dispara manualmente HandleRelatorioGeradoJob para um período específico (útil pra teste sem esperar webhook real do parceiro).';

    public function handle(): int
    {
        // Período: argumento explícito ou mês anterior fechado via Carbon
        $periodo = $this->argument('periodo')
            ?? Carbon::now()->subMonthNoOverflow()->format('Ym');

        // Validação básica: 6 dígitos numéricos (ex: 202605)
        if (! preg_match('/^\d{6}$/', (string) $periodo)) {
            $this->error("Período inválido: '{$periodo}'. Use formato YYYYMM (ex: 202605).");
            return self::FAILURE;
        }

        $mes = (int) substr((string) $periodo, 4, 2);
        if ($mes < 1 || $mes > 12) {
            $this->error("Mês inválido em '{$periodo}': {$mes}. Use 01-12.");
            return self::FAILURE;
        }

        $mesLabel = $this->mesLabelPt((string) $periodo);

        $this->info("Disparando HandleRelatorioGeradoJob para período {$periodo} ({$mesLabel})...");

        try {
            // Cria WebhookDelivery sintético — event_id único para evitar colisão UNIQUE (D-H CONTEXT)
            // Prefixo 'manual-' sinaliza no audit log que foi disparo manual (admin pode filtrar)
            $delivery = WebhookDelivery::create([
                'event_id'        => 'manual-' . $periodo . '-' . Str::uuid()->toString(),
                'event_type'      => 'relatorio.gerado',
                'payload'         => [
                    'data' => [
                        'periodo'      => $periodo,
                        'relatorio_id' => null,
                        'url'          => null,
                    ],
                ],
                'signature_valid' => true,
                'received_at'     => now(),
                'status'          => 'received',
                'ip_address'      => '127.0.0.1',
            ]);

            // Dispatch normal — vai para a queue 'default' (mesmo fluxo do webhook real)
            HandleRelatorioGeradoJob::dispatch($delivery->id);

            $this->info("✓ WebhookDelivery #{$delivery->id} criado.");
            $this->info('✓ HandleRelatorioGeradoJob dispatched para a queue.');
            $this->newLine();
            $this->line('Acompanhe via:');
            $this->line('  - php artisan queue:work --once   (processa o job se worker não estiver rodando)');
            $this->line('  - tail -f storage/logs/laravel.log');
            $this->line("  - SELECT * FROM webhook_deliveries WHERE id = {$delivery->id};");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("Falha ao disparar: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Converte período YYYYMM para label pt-BR legível.
     * Duplicação consciente — helper de 5 linhas sem necessidade de service dedicado (Decisão D-D).
     * Exemplo: '202605' → 'Maio/2026'
     */
    private function mesLabelPt(string $periodo): string
    {
        if (strlen($periodo) !== 6) {
            return $periodo;
        }

        $meses = [
            'Janeiro', 'Fevereiro', 'Março', 'Abril',
            'Maio', 'Junho', 'Julho', 'Agosto',
            'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];

        $ano = substr($periodo, 0, 4);
        $mes = (int) substr($periodo, 4, 2);

        if ($mes < 1 || $mes > 12) {
            return $periodo;
        }

        return $meses[$mes - 1] . '/' . $ano;
    }
}
