<?php

namespace App\Console\Commands;

use App\Models\DevReuniao;
use App\Services\DevDemandas\ReuniaoDevGoogleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Puxa gravação, transcrição e anotações do Gemini das reuniões dev que já
 * terminaram. O Meet anexa esses arquivos ao evento alguns minutos depois do
 * fim — por isso tenta de novo a cada rodada, por até 3 dias, e só preenche
 * link vazio (nunca sobrescreve o que foi colado à mão).
 */
class BuscarGravacoesReunioesDev extends Command
{
    protected $signature = 'demandas-dev:buscar-gravacoes';

    protected $description = '[Demandas Dev] Busca nos anexos do Google Agenda a gravação/transcrição das reuniões dev encerradas';

    public function handle(ReuniaoDevGoogleService $google): int
    {
        $reunioes = DevReuniao::query()
            ->whereNotNull('google_event_id')
            ->whereNull('cancelada_em')
            ->whereBetween('fim', [now()->subDays(3), now()->subMinutes(5)])
            ->where(fn ($q) => $q->whereNull('link_gravacao')->orWhereNull('link_transcricao')->orWhereNull('link_resumo'))
            ->get();

        $resumo = ['conferidas' => 0, 'com_novidade' => 0, 'falhas' => 0];

        foreach ($reunioes as $reuniao) {
            $resumo['conferidas']++;
            try {
                if ($google->buscarAnexos($reuniao)) {
                    $resumo['com_novidade']++;
                }
            } catch (\Throwable $e) {
                $resumo['falhas']++;
                Log::warning("[Demandas Dev] anexos da reunião {$reuniao->id} ({$reuniao->titulo}): {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%d reuniões conferidas, %d com link novo, %d falhas.', ...array_values($resumo)));

        return self::SUCCESS;
    }
}
