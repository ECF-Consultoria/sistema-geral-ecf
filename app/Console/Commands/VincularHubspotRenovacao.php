<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\Renovacao\VinculoHubspotResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renovação (09/11/2026) — preenche empresa/contato do HubSpot que faltam no
 * cadastro das empresas com ciclo de renovação aberto (ou de uma empresa só).
 *
 * Sem `--aplicar` só mostra o que acharia. Só lê do HubSpot e só escreve em
 * `companies.hubspot_company_id` / `hubspot_contact_id` quando estão vazios.
 * Ver `VinculoHubspotResolver` para a ordem de busca.
 */
class VincularHubspotRenovacao extends Command
{
    protected $signature = 'renovacao:vincular-hubspot {--aplicar : Grava os vínculos achados} {--empresa= : Só esta empresa (id)} {--pausa-ms=300 : Pausa entre empresas}';

    protected $description = 'Procura no HubSpot a empresa e o contato das empresas com renovação aberta e preenche o vínculo que falta';

    public function handle(VinculoHubspotResolver $resolver): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $pausa   = max(0, (int) $this->option('pausa-ms'));

        $query = Company::query();
        if ($this->option('empresa')) {
            $query->whereKey((int) $this->option('empresa'));
        } else {
            $query->whereIn('id', ContratoRenovacao::query()
                ->whereNotIn('status_comercial', ContratoRenovacao::STATUS_COMERCIAIS_FINAIS)
                ->select('company_id'));
        }

        $this->info($aplicar ? 'APLICANDO — vínculos vazios serão preenchidos.' : 'SIMULAÇÃO — nada é gravado (use --aplicar).');

        $linhas = [];
        $resumo = ['empresa' => 0, 'so_contato' => 0, 'nenhum' => 0, 'erro' => 0, 'gravadas' => 0];

        foreach ($query->orderBy('id')->get() as $company) {
            try {
                $r = $resolver->resolver($company, $aplicar);
            } catch (HubspotErroApi $e) {
                $resumo['erro']++;
                Log::error("[Renovacao] vincular-hubspot: empresa {$company->id} ({$company->name}): " . mb_substr($e->getMessage(), 0, 300));
                $linhas[] = [$company->id, mb_substr($company->name, 0, 32), 'ERRO', '', mb_substr($e->getMessage(), 0, 60)];
                continue;
            }

            $situacao = $r['company_id'] ? 'empresa' : ($r['contact_id'] ? 'so_contato' : 'nenhum');
            $resumo[$situacao]++;
            if ($r['gravou'] !== []) {
                $resumo['gravadas']++;
            }

            $linhas[] = [
                $company->id,
                mb_substr($company->name, 0, 32),
                $r['company_id'] ? "{$r['company_id']} ({$r['fonte_empresa']})" : '-',
                $r['contact_id'] ? "{$r['contact_id']} ({$r['fonte_contato']})" : '-',
                trim(($r['gravou'] ? 'gravou ' . implode('+', $r['gravou']) . ' ' : '') . ($r['aviso'] ?? '')),
            ];

            if ($pausa > 0) {
                usleep($pausa * 1000);
            }
        }

        $this->table(['id', 'empresa', 'empresa HubSpot', 'contato HubSpot', 'obs'], $linhas);
        $this->line(sprintf(
            'Com empresa: %d | só contato: %d | sem nada (negócio sai sem associação): %d | erro: %d | gravadas: %d',
            $resumo['empresa'], $resumo['so_contato'], $resumo['nenhum'], $resumo['erro'], $resumo['gravadas']
        ));

        return $resumo['erro'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
