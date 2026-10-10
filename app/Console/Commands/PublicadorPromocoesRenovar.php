<?php

namespace App\Console\Commands;

use App\Services\Publicador\Alavancas\PromocaoAutomaticaService;
use Illuminate\Console\Command;

/**
 * Renovação diária da promoção automática pós-publicação (10/10/2026), agendada às 00:05 de São Paulo
 * em `routes/console.php`: o ciclo ativo cujo fim já passou vira o ciclo seguinte (de hoje a hoje+13)
 * quando o anúncio continua ativo e com o mesmo preço; preço mudado ou anúncio encerrado encerram e nada
 * mais. Também reenvia o Job agendado que se perdeu e fecha o envio preso (nunca reenvia uma escrita).
 * Seguro de rodar à mão a qualquer hora: tudo é idempotente (unique por anúncio e ciclo, trava por anúncio).
 */
class PublicadorPromocoesRenovar extends Command
{
    protected $signature = 'publicador:promocoes-renovar';

    protected $description = 'Renova as promoções automáticas do Publicador cujo ciclo de 14 dias terminou';

    public function handle(PromocaoAutomaticaService $servico): int
    {
        $r = $servico->renovar();

        $this->info("Renovadas: {$r['renovadas']} · encerradas: {$r['encerradas']} · não renovadas (tarefa avisada): {$r['nao_renovadas']}"
            ." · Jobs reenviados: {$r['reenviadas']} · envios interrompidos: {$r['interrompidas']}");

        return self::SUCCESS;
    }
}
