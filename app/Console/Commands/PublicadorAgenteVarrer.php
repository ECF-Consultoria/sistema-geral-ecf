<?php

namespace App\Console\Commands;

use App\Services\Publicador\Agente\VarreduraDoAgente;
use Illuminate\Console\Command;

/**
 * Agente ECF Publicador (AG-06, 20/10/2026): a varredura limitada — descobre, nas lojas com o agente ligado e
 * ativo, produto que ainda não passou pelo agente e pede o preparo (dentro da janela, do limite diário e do teto
 * da fila da loja). Agendado em `routes/console.php`. Sem loja com o agente ligado, não faz nada.
 */
class PublicadorAgenteVarrer extends Command
{
    protected $signature = 'publicador:agente-varrer';

    protected $description = 'Descobre produtos novos nas lojas com o agente ligado e pede o preparo';

    public function handle(VarreduraDoAgente $varredura): int
    {
        $r = $varredura->rodar();
        if ($r['pedidos'] + $r['recuperadas'] > 0) {
            $this->info("Agente: {$r['lojas']} loja(s), {$r['pedidos']} pedido(s), {$r['novas']} execução(ões) nova(s), {$r['recusas']} recusa(s), {$r['recuperadas']} recuperada(s).");
        }

        return self::SUCCESS;
    }
}
