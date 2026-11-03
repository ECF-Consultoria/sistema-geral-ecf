<?php

namespace App\Console\Commands;

use App\Services\AdmanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Diagnóstico das duas chaves de integrador da Adman. Desde 03/11/2026 as
 * contas dos Polos ficam numa chave própria (ADMAN_POLOS_API_KEY) e as de
 * Performance na principal (ADMAN_API_KEY). Relê a lista de contas da chave
 * dos Polos e diz por qual chave cada conta pedida vai. Nunca imprime chave.
 */
class AdmanChaves extends Command
{
    protected $signature = 'adman:chaves
        {--conta=* : custId para conferir por qual chave a conta vai (pode repetir)}
        {--sem-atualizar : só mostra a lista em cache, sem reler na Adman}';

    protected $description = 'Relê as contas da chave dos Polos na Adman e mostra por qual chave cada conta vai';

    public function handle(AdmanService $adman): int
    {
        if (!$adman->temChavePolos()) {
            $this->warn('ADMAN_POLOS_API_KEY não configurada: todas as contas vão pela ADMAN_API_KEY.');
            return self::SUCCESS;
        }

        $releuOk = true;
        if (!$this->option('sem-atualizar')) {
            $releuOk = $adman->atualizarContasDaChavePolos() !== null;
            if (!$releuOk) {
                $this->error('Não consegui reler a lista na Adman (detalhe no laravel.log). Vale a lista anterior, se houver.');
            }
        }

        $guardado = Cache::get(AdmanService::CACHE_CONTAS_CHAVE_POLOS);
        if (!is_array($guardado) || empty($guardado['contas'])) {
            $this->error('Sem lista de contas da chave dos Polos: as contas dos Polos vão pela chave principal e a Adman responde "Customer not found".');
            return self::FAILURE;
        }

        $this->info('Chave dos Polos: ' . count($guardado['contas']) . " contas (lista lida em {$guardado['lido_em']}).");

        foreach ($this->option('conta') as $conta) {
            $this->line("  {$conta}: " . ($adman->contaEstaNaChavePolos($conta) ? 'chave dos Polos' : 'chave principal'));
        }

        return $releuOk ? self::SUCCESS : self::FAILURE;
    }
}
