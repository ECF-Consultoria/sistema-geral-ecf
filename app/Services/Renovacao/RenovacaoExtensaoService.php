<?php

namespace App\Services\Renovacao;

use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Services\Contratos\TerminoContratoService;
use App\Services\Contratos\VigenciaContrato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (D-05) — estende o contrato quando a renovação é ganha.
 *
 * O ganho comercial é o gatilho decidido pelo usuário; a formalização no
 * Clicksign segue pelo fluxo normal e divergências ficam visíveis na tela.
 * Idempotente: repetir o ganho não estende de novo (lockForUpdate + status).
 */
class RenovacaoExtensaoService
{
    public function __construct(
        private RenovacaoDetector $detector,
        private TerminoContratoService $termino,
    ) {}

    /**
     * @return bool true quando estendeu; false quando ignorou ou foi para revisão manual
     */
    public function registrarGanho(ContratoRenovacao $ciclo, string $origem, array $dados = [], ?int $userId = null): bool
    {
        return DB::transaction(function () use ($ciclo, $origem, $dados, $userId) {
            $ciclo = ContratoRenovacao::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();

            if ($ciclo->status_comercial === ContratoRenovacao::STATUS_COMERCIAL_RENOVADO) {
                $ciclo->registrarEvento('ganho_repetido_ignorado', $origem, null, $dados, false, $userId);

                return false;
            }

            if (in_array($ciclo->status_comercial, [
                ContratoRenovacao::STATUS_COMERCIAL_DISPENSADO,
                ContratoRenovacao::STATUS_COMERCIAL_NAO_RENOVADO,
            ], true)) {
                $this->revisaoManual($ciclo, $origem, 'Negócio de renovação ganho, mas o ciclo está dispensado/não renovado — conferir à mão.', $dados, $userId);

                return false;
            }

            $membros = $this->detector->gruposDaEmpresa($ciclo->company)->get((int) $ciclo->servico_id);

            if ($membros === null || $membros->isEmpty()) {
                $this->revisaoManual($ciclo, $origem, 'Negócio de renovação ganho, mas o contrato está desativado — conferir à mão.', $dados, $userId);

                return false;
            }

            // Fase 202-08: prazo do contrato assinado (Brigada P4M no HubSpot = 12 no contrato).
            $meses = $this->termino->mesesDeVigencia($ciclo->company, (int) $ciclo->servico_id, $membros);
            $antes = $ciclo->termino_capturado;
            $novo  = VigenciaContrato::terminoPorMeses($antes->copy(), $meses);

            // Caminho explícito: na 2ª renovação a origem já é 'renovacao' e não pode virar 'manual'.
            foreach ($membros as $cs) {
                $cs->gravarTermino($novo, ContratoServico::ORIGEM_TERMINO_RENOVACAO);
            }

            $ciclo->update([
                'status_comercial'  => ContratoRenovacao::STATUS_COMERCIAL_RENOVADO,
                'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO,
                'termino_novo'      => $novo->toDateString(),
                'renovado_em'       => now(),
                'ultimo_erro'       => null,
            ]);

            $numero = $ciclo->numero_ciclo + 1;
            ContratoRenovacao::firstOrCreate(
                ['company_id' => $ciclo->company_id, 'servico_id' => $ciclo->servico_id, 'numero_ciclo' => $numero],
                [
                    'ciclo_anterior_id'   => $ciclo->id,
                    'chave_ciclo'         => RenovacaoDetector::chaveCiclo($ciclo->company_id, $ciclo->servico_id, $numero),
                    'chave_correlacao'    => RenovacaoDetector::chaveCorrelacao($ciclo->company_id, $ciclo->servico_id, $numero),
                    'termino_capturado'   => $novo->toDateString(),
                    'inicio_vigencia'     => $antes->toDateString(),
                    'vigencia_meses'      => $meses,
                    // Cadeia A → B → próximo: o original do novo ciclo é a renovação ganha.
                    'negocio_original_id' => $ciclo->negocio_renovacao_id,
                    'hubspot_company_id'  => $ciclo->hubspot_company_id,
                    'detectado_em'        => now(),
                ]
            );

            $ciclo->registrarEvento('contrato_estendido', $origem, null, [
                'termino_antes'  => $antes->toDateString(),
                'termino_depois' => $novo->toDateString(),
                'meses'          => $meses,
                'origem'         => $origem,
                'dados'          => $dados,
            ], false, $userId);

            return true;
        });
    }

    private function revisaoManual(ContratoRenovacao $ciclo, string $origem, string $mensagem, array $dados, ?int $userId): void
    {
        $ciclo->update([
            'status_integracao' => ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL,
            'ultimo_erro'       => $mensagem,
        ]);
        $ciclo->registrarEvento('ganho_sem_extensao', $origem, 'revisao_manual', ['motivo' => $mensagem] + $dados, false, $userId);

        Log::channel('ecf-webhooks')->error("[Renovacao] ciclo {$ciclo->id} (empresa {$ciclo->company_id}): {$mensagem}");
    }
}
