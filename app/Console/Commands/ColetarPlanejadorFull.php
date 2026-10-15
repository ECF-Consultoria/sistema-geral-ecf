<?php

namespace App\Console\Commands;

use App\Jobs\ColetarPlanejadorFullJob;
use App\Models\Company;
use App\Services\PlanejadorFull\FullColetaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out diário do Planejador FULL: enfileira a coleta de cada empresa com token ML
 * ativo. Quem não tem anúncio no FULL sai na primeira chamada do job (eram 47 de 137
 * contas com FULL em 15/10/2026) — não precisa de lista mantida à mão.
 *
 * Mesmo filtro de empresas do ml:sync e do mlb:sync-acervo (active + token active).
 */
class ColetarPlanejadorFull extends Command
{
    protected $signature = 'full:coletar
        {--company= : Coleta só esta empresa (ID da Company)}
        {--agora    : Roda no próprio processo, sem fila (diagnóstico)}';

    protected $description = 'Coleta estoque FULL, vendas e chegadas do Mercado Livre para o Planejador FULL';

    public function handle(FullColetaService $coleta): int
    {
        $query = Company::query()
            ->where('active', true)
            ->whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
            ->with('mlToken');

        if ($this->option('company')) {
            $query->whereKey($this->option('company'));
        }

        $empresas = $query->get();

        if ($empresas->isEmpty()) {
            $this->warn('Nenhuma empresa com token ML ativo' . ($this->option('company') ? ' com esse ID' : '') . '.');

            return $this->option('company') ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('agora')) {
            foreach ($empresas as $empresa) {
                $r = $coleta->coletar($empresa, 'manual');
                $this->line("  {$empresa->id} ({$empresa->name}): " . ($r ? "{$r->status}, {$r->produtos} Códigos ML, {$r->pedidos} pedidos" : 'sem anúncio no FULL'));
            }

            return self::SUCCESS;
        }

        foreach ($empresas->values() as $i => $empresa) {
            // 2s entre empresas, mesmo escalonamento do ml:sync — espalha a pressão de rate limit.
            ColetarPlanejadorFullJob::dispatch($empresa, 'agendada')->delay(now()->addSeconds($i * 2));
        }

        $this->info("Coleta do Planejador FULL enfileirada para {$empresas->count()} empresa(s).");
        Log::info("[PlanejadorFull] fan-out full:coletar: {$empresas->count()} empresa(s)");

        return self::SUCCESS;
    }
}
