<?php

namespace App\Console\Commands;

use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use Illuminate\Console\Command;

/**
 * Imagens por IA automáticas que o limite diário barrou (22/10/2026): cada rascunho marcado num dia anterior volta para
 * a avaliação (`CriativosAutomaticosService::retomarAdiados`), agendado às 06:00 de São Paulo em `routes/console.php`.
 * O limite do dia continua valendo — quem passar dele fica para amanhã. Seguro de rodar à mão: a avaliação confere
 * tudo de novo e não gera duas vezes para os mesmos fatos.
 */
class PublicadorImagensAdiadas extends Command
{
    protected $signature = 'publicador:imagens-adiadas';

    protected $description = 'Pede de novo as imagens por IA automáticas que o limite diário barrou';

    public function handle(CriativosAutomaticosService $criativos): int
    {
        $r = $criativos->retomarAdiados();

        $this->info("Pedidas de novo: {$r['pedidos']} · barradas hoje (ficam para amanhã): {$r['de_hoje']}"
            ." · kits prontos aplicados: {$r['reaplicados']} · kits parados encerrados: {$r['encerrados']}");

        return self::SUCCESS;
    }
}
