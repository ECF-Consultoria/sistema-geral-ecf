<?php

namespace App\Services\Contratos;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fase 202 (REN-01, D-03) — término calculado dos contratos ativos.
 *
 * Por grupo de contrato (serviço dono): início = assinatura Clicksign do dono
 * (quando houver) senão a menor `data_contratacao`; vigência = meses do
 * `hubspot_billing_period` (regra de VigenciaContrato) ou 12. Data expressa no
 * HubSpot (`hs_recurring_billing_end_date`) vence o cálculo. Data digitada por
 * pessoa nunca é sobrescrita. Toda gravação passa por `gravarTermino()`.
 *
 * ⚠️ `data_contratacao` é a data do webhook/reimport, não da assinatura — por
 * isso a assinatura é preferida quando existe, e datas futuras são sinalizadas.
 */
class TerminoContratoService
{
    public function __construct(private ContratoGrupos $grupos) {}

    /**
     * @return array<int, array<string, mixed>> linhas de relatório
     */
    public function preencherEmpresa(Company $company, bool $aplicar = true): array
    {
        if (! $company->active) {
            return [];
        }

        $ativos = ContratoServico::query()
            ->where('company_id', $company->id)
            ->where('ativo', true)
            ->with('servico')
            ->orderBy('id')
            ->get();

        if ($ativos->isEmpty()) {
            return [];
        }

        $relatorio = [];

        foreach ($this->grupos->agrupar($ativos) as $donoId => $grupo) {
            $donoId = (int) $donoId;

            if (! $this->grupos->exigeContrato($grupo, $donoId)) {
                continue;
            }

            [$inicio, $fonte] = $this->inicioDoGrupo($company, $donoId, $grupo);
            $meses = VigenciaContrato::mesesDoGrupo(
                $grupo->map(fn (ContratoServico $cs) => ['servico' => $cs->servico_id, 'parcelas' => $cs->parcelas()])
            );
            $calculado = VigenciaContrato::terminoPorMeses($inicio, $meses);

            foreach ($grupo as $cs) {
                $antes = $cs->data_vencimento?->toDateString();
                $dataHubspot = $this->dataHubspot($cs);

                if ($antes !== null) {
                    $acao   = $cs->data_vencimento_origem === null ? 'marcar_manual' : 'manter';
                    $depois = $antes;
                    $origem = $cs->data_vencimento_origem ?? ContratoServico::ORIGEM_TERMINO_MANUAL;
                } elseif ($dataHubspot !== null) {
                    $acao   = 'gravar_hubspot';
                    $depois = $dataHubspot;
                    $origem = ContratoServico::ORIGEM_TERMINO_HUBSPOT;
                } else {
                    $acao   = 'gravar_calculada';
                    $depois = $calculado->toDateString();
                    $origem = ContratoServico::ORIGEM_TERMINO_CALCULADA;
                }

                if ($aplicar && $acao !== 'manter') {
                    $cs->gravarTermino($depois, $origem);
                }

                $relatorio[] = [
                    'contrato_servico_id'     => $cs->id,
                    'company_id'              => $company->id,
                    'company_nome'            => $company->name,
                    'servico_id'              => $cs->servico_id,
                    'servico_nome'            => $cs->servico?->nome,
                    'inicio'                  => $inicio->toDateString(),
                    'inicio_fonte'            => $fonte,
                    'meses'                   => $meses,
                    'termino_antes'           => $antes,
                    'termino_depois'          => $depois,
                    'origem_depois'           => $origem,
                    'acao'                    => $acao,
                    'data_contratacao_futura' => $cs->data_contratacao !== null && $cs->data_contratacao->gt(Carbon::today()),
                ];
            }
        }

        return $relatorio;
    }

    /**
     * Universo: empresas ativas com contrato ativo de serviço que exige contrato.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preencherTodos(bool $aplicar, ?int $companyId = null): array
    {
        $relatorio = [];

        $query = Company::query()
            ->where('active', true)
            ->whereHas('contratosServico', function ($q) {
                $q->where('ativo', true)->whereHas('servico', fn ($s) => $s->where('exige_contrato', true));
            })
            ->orderBy('id');

        if ($companyId !== null) {
            $query->where('id', $companyId);
        }

        $query->chunkById(100, function ($empresas) use (&$relatorio, $aplicar) {
            foreach ($empresas as $empresa) {
                try {
                    foreach ($this->preencherEmpresa($empresa, $aplicar) as $linha) {
                        $relatorio[] = $linha;
                    }
                } catch (\Throwable $e) {
                    Log::channel('ecf-webhooks')->error("[Renovacao] falha ao calcular término da empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");
                    $relatorio[] = [
                        'contrato_servico_id' => null,
                        'company_id'          => $empresa->id,
                        'company_nome'        => $empresa->name,
                        'acao'                => 'falha',
                        'erro'                => $e->getMessage(),
                    ];
                }
            }
        });

        return $relatorio;
    }

    /**
     * @return array{0: Carbon, 1: string}
     */
    private function inicioDoGrupo(Company $company, int $donoId, $grupo): array
    {
        $assinadoEm = ContratoAssinatura::query()
            ->where('company_id', $company->id)
            ->where('servico_id', $donoId)
            ->where('status', ContratoAssinatura::STATUS_ASSINADO)
            ->whereNotNull('assinado_em')
            ->orderByDesc('assinado_em')
            ->value('assinado_em');

        if ($assinadoEm !== null) {
            return [Carbon::parse($assinadoEm)->startOfDay(), 'assinatura'];
        }

        $menor = $grupo->pluck('data_contratacao')->filter()->min();

        return [Carbon::parse($menor)->startOfDay(), 'data_contratacao'];
    }

    private function dataHubspot(ContratoServico $cs): ?string
    {
        $valor = $cs->hubspot_snapshot['line_item']['hs_recurring_billing_end_date'] ?? null;

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
