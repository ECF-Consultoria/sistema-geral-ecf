<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarRenovacaoJob;
use App\Models\ContratoRenovacao;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\HubspotApiClient;
use App\Services\Renovacao\RenovacaoConfig;
use App\Services\Renovacao\RenovacaoDecisao;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoJanela;
use App\Services\Renovacao\RenovacaoOrquestrador;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (REN-04/REN-07) — verificação diária de contratos na janela de renovação.
 *
 * Sem `--simular`, só roda com a chave `renovacao_automatica_ativa` ligada
 * (nasce desligada). Cada ciclo vira um SincronizarRenovacaoJob (fila high),
 * ou roda direto com `--sync` para o humano ver as decisões na hora.
 */
class VerificarRenovacoes extends Command
{
    protected $signature = 'renovacao:verificar {--simular : Só registra decisões, nada é escrito no HubSpot} {--sync : Roda direto, sem fila} {--empresa= : Só esta empresa (id)}';

    protected $description = 'Detecta contratos na janela de renovação e sincroniza com o HubSpot (respeita a chave de liga/desliga)';

    public function handle(RenovacaoDetector $detector, RenovacaoOrquestrador $orquestrador, HubspotApiClient $hubspot): int
    {
        $simular = (bool) $this->option('simular');

        if (! $simular && ! RenovacaoConfig::ativa()) {
            $this->info('Renovação automática DESLIGADA (configuracoes.' . RenovacaoConfig::CHAVE_ATIVA . ')');

            return self::SUCCESS;
        }

        $simulacao = $simular || RenovacaoConfig::simulacao();

        if ($simulacao) {
            $this->warn('SIMULAÇÃO — nada será escrito no HubSpot');
        } else {
            // Modo real: a property de correlação precisa existir antes de qualquer criação.
            $propriedade = (string) config('services.hubspot.renovacao.propriedade_chave');

            try {
                $existe = $hubspot->buscarPropriedadeDeDeal($propriedade) !== null;
            } catch (HubspotErroApi $e) {
                $existe = false;
                Log::channel('ecf-webhooks')->error('[Renovacao] não foi possível conferir a property de correlação: ' . $e->getMessage());
            }

            if (! $existe) {
                Log::channel('ecf-webhooks')->error("[Renovacao] property {$propriedade} ausente no HubSpot — nada foi criado");
                $this->error("A property {$propriedade} não existe no HubSpot. Rode renovacao:hubspot-preparar --aplicar.");

                return self::FAILURE;
            }
        }

        $empresa = $this->option('empresa');
        $hoje    = Carbon::today();
        $ciclos  = $detector->detectar($hoje, $empresa !== null ? (int) $empresa : null);

        $linhas  = [];
        $revisao = 0;
        $erros   = 0;

        foreach ($ciclos as $ciclo) {
            $decisao = 'enfileirado';
            $motivo  = '';

            if ($this->option('sync')) {
                try {
                    $d       = $orquestrador->sincronizar($ciclo, 'comando', null, $simulacao);
                    $decisao = $d->acao;
                    $motivo  = (string) ($d->motivo ?? $d->explicacao);
                    $revisao += $d->acao === RenovacaoDecisao::REVISAO_MANUAL ? 1 : 0;
                    $erros   += $d->acao === RenovacaoDecisao::ERRO_LEITURA ? 1 : 0;
                } catch (HubspotErroApi $e) {
                    $decisao = 'erro';
                    $motivo  = mb_substr($e->getMessage(), 0, 80);
                    $erros++;
                }
            } else {
                SincronizarRenovacaoJob::dispatch($ciclo->id, 'agendador', null, $simulacao);
            }

            $linhas[] = [
                $ciclo->company->name,
                $ciclo->servico?->nome,
                $ciclo->numero_ciclo,
                $ciclo->termino_capturado->format('d/m/Y'),
                RenovacaoJanela::diasRestantes($ciclo->termino_capturado, $hoje),
                $decisao,
                $motivo,
            ];
        }

        if ($linhas !== []) {
            $this->table(['Empresa', 'Serviço', 'Ciclo', 'Término', 'Dias', 'Decisão', 'Motivo'], $linhas);
        }

        // D-07: vencidos sem ação só aparecem, não geram negócio sozinhos.
        $semAcao = ContratoRenovacao::query()
            ->whereNotIn('status_comercial', ContratoRenovacao::STATUS_COMERCIAIS_FINAIS)
            ->whereNull('negocio_renovacao_id')
            ->whereDate('termino_capturado', '<', $hoje->toDateString())
            ->count();

        $this->info(sprintf(
            'Resumo: %d na janela, %d vencidos sem ação, %d em revisão manual, %d com erro.',
            $ciclos->count(), $semAcao, $revisao, $erros
        ));

        return self::SUCCESS;
    }
}
