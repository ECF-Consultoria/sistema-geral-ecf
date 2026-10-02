<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Portal\PortalEquipeService;
use Illuminate\Console\Command;

/**
 * Imprime o link ABERTO de equipe do portal de uma empresa.
 *
 * Só funciona para empresa listada em `config('portal.link_equipe')` — loja
 * de teste da ECF. Roda na produção porque a assinatura usa a `APP_KEY` de lá:
 * link gerado em outra máquina não abre.
 */
class PortalLinkEquipe extends Command
{
    protected $signature = 'portal:link-equipe {empresa : companies.id}';

    protected $description = 'Imprime o link aberto (sem login) do portal de uma empresa de teste listada em portal.link_equipe';

    public function handle(PortalEquipeService $equipe): int
    {
        $empresa = Company::find((int) $this->argument('empresa'));

        if (! $empresa) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        $dono = $equipe->donoDoLink($empresa);

        if (! $dono) {
            $this->error("A empresa {$empresa->id} ({$empresa->name}) não tem link aberto — não está em portal.link_equipe, ou o dono configurado está inativo/sem acesso.");

            return self::FAILURE;
        }

        $this->line("Empresa: {$empresa->id} ({$empresa->name}) — em nome de {$dono->name} (#{$dono->id})");
        $this->line($equipe->urlDoLink($empresa));

        return self::SUCCESS;
    }
}
