<?php

namespace App\Console\Commands;

use App\Services\Publicador\Agente\PreparadorDaAprovacao;
use Illuminate\Console\Command;

/**
 * Agente ECF Publicador (AG-03, 20/10/2026): leva à fila de Aprovação as execuções de preparo cuja cadeia de
 * texto já terminou. Agendado todo minuto em `routes/console.php`. Não aprova, não publica e não fala com o ML
 * nem com a IA — só lê o banco e cria a oferta `aguardando_aprovacao`. Sem conta com o agente ligado, não faz nada.
 */
class PublicadorAgenteEncaminhar extends Command
{
    protected $signature = 'publicador:agente-encaminhar {--conta= : só esta conta (empresa-N / company-N)}';

    protected $description = 'Encaminha à Aprovação os produtos que o agente terminou de preparar';

    public function handle(PreparadorDaAprovacao $preparador): int
    {
        $r = $preparador->encaminharProntas($this->option('conta') ?: null);
        if ($r['encaminhada'] + $r['bloqueada'] + $r['erro'] > 0) {
            $this->info("Agente: {$r['encaminhada']} encaminhada(s), {$r['aguardando_texto']} aguardando texto, {$r['bloqueada']} bloqueada(s), {$r['erro']} erro(s).");
        }

        return $r['erro'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
