<?php

namespace App\Services\Contratos;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoClicksignAssinado;
use App\Models\ContratoServico;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 202 (REN-01, D-03) — término calculado dos contratos ativos.
 *
 * Fase 202-08 — FONTE DA VERDADE é o contrato ASSINADO na Clicksign (decisão do
 * usuário, 09/11/2026): término = data da assinatura + prazo escrito no
 * contrato, origem `contrato`. O período de cobrança do HubSpot (`P4M` da
 * Brigada) é plano de pagamento, não vigência — só vale quando não há contrato
 * lido. Origens do sistema (`calculada`, `hubspot`, `contrato`) são
 * RECALCULADAS a cada rodada (ação `recalcular`); `manual` e `renovacao` nunca.
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

        $relatorio   = [];
        $grupos      = $this->grupos->agrupar($ativos);
        $totalGrupos = $grupos->filter(fn ($g, $dono) => $this->grupos->exigeContrato($g, (int) $dono))->count();

        foreach ($grupos as $donoId => $grupo) {
            $donoId = (int) $donoId;

            if (! $this->grupos->exigeContrato($grupo, $donoId)) {
                continue;
            }

            $assinado      = $this->contratoAssinadoDoGrupo($company, $donoId, $grupo, $totalGrupos);
            $termoContrato = $this->terminoPeloContrato($assinado);

            [$inicio, $fonte] = $this->inicioDoGrupo($company, $donoId, $grupo, $assinado);
            $meses = $termoContrato !== null
                ? (int) $assinado->vigencia_meses
                : VigenciaContrato::mesesDoGrupo(
                    $grupo->map(fn (ContratoServico $cs) => ['servico' => $cs->servico_id, 'parcelas' => $cs->parcelas()])
                );
            $calculado = VigenciaContrato::terminoPorMeses($inicio, $meses);

            foreach ($grupo as $cs) {
                $antes       = $cs->data_vencimento?->toDateString();
                $origemAntes = $cs->data_vencimento_origem;

                // Alvo do sistema para esta linha: contrato assinado > data expressa
                // do HubSpot > início + período (HubSpot) ou 12.
                if ($termoContrato !== null) {
                    [$alvo, $origemAlvo, $tipoAlvo] = [$termoContrato, ContratoServico::ORIGEM_TERMINO_CONTRATO, 'contrato'];
                } elseif (($dataHubspot = $this->dataHubspot($cs)) !== null) {
                    [$alvo, $origemAlvo, $tipoAlvo] = [$dataHubspot, ContratoServico::ORIGEM_TERMINO_HUBSPOT, 'hubspot'];
                } else {
                    [$alvo, $origemAlvo, $tipoAlvo] = [$calculado->toDateString(), ContratoServico::ORIGEM_TERMINO_CALCULADA, 'calculada'];
                }

                if ($antes === null) {
                    $acao   = 'gravar_'.$tipoAlvo;
                    $depois = $alvo;
                    $origem = $origemAlvo;
                } elseif ($origemAntes === null) {
                    // Legado: data sem origem = digitada por pessoa.
                    $acao   = 'marcar_manual';
                    $depois = $antes;
                    $origem = ContratoServico::ORIGEM_TERMINO_MANUAL;
                } elseif (! in_array($origemAntes, ContratoServico::ORIGENS_TERMINO_RECALCULAVEIS, true)) {
                    // `manual` e `renovacao` NUNCA mudam por cálculo.
                    $acao   = 'manter';
                    $depois = $antes;
                    $origem = $origemAntes;
                } elseif ($antes !== $alvo || $origemAntes !== $origemAlvo) {
                    // Origem do sistema com valor/fonte novos (contrato achado, data do
                    // HubSpot corrigida, assinatura conhecida...). Absorve o antigo
                    // `corrigir_hubspot` (epoch em ms lido como 1796).
                    $acao   = 'recalcular';
                    $depois = $alvo;
                    $origem = $origemAlvo;
                } else {
                    $acao   = 'manter';
                    $depois = $antes;
                    $origem = $origemAntes;
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
                    'inicio'                  => $termoContrato !== null ? $assinado->assinado_em->toDateString() : $inicio->toDateString(),
                    'inicio_fonte'            => $termoContrato !== null ? 'contrato_assinado' : $fonte,
                    'meses'                   => $meses,
                    'termino_antes'           => $antes,
                    'origem_antes'            => $origemAntes,
                    'termino_depois'          => $depois,
                    'origem_depois'           => $origem,
                    'acao'                    => $acao,
                    'envelope_contrato'       => $termoContrato !== null ? $assinado->envelope_nome : null,
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
     * Contrato assinado (acervo da Clicksign) que vale para este grupo: empresa
     * casada, com data de assinatura, tipo compatível (Mentoria ↔ serviço cujo
     * nome contém "Mentoria"; demais ↔ gestão/brigada). Empresa com um grupo só
     * aceita qualquer tipo. Vários assinados: o de assinatura mais recente
     * (se ele não tiver prazo lido, serve só como início — não cai num mais velho).
     */
    public function contratoAssinadoDoGrupo(Company $company, int $donoId, Collection $grupo, ?int $totalGrupos = null): ?ContratoClicksignAssinado
    {
        $candidatos = ContratoClicksignAssinado::query()
            ->where('company_id', $company->id)
            ->whereIn('situacao', [ContratoClicksignAssinado::SITUACAO_CASADO, ContratoClicksignAssinado::SITUACAO_SEM_PRAZO])
            ->whereNotNull('assinado_em')
            ->orderByDesc('assinado_em')
            ->orderByDesc('id')
            ->get();

        if ($candidatos->isEmpty()) {
            return null;
        }

        if ($totalGrupos === null) {
            $ativos = ContratoServico::query()->where('company_id', $company->id)->where('ativo', true)->with('servico')->get();
            $totalGrupos = $this->grupos->agrupar($ativos)
                ->filter(fn ($g, $dono) => $this->grupos->exigeContrato($g, (int) $dono))
                ->count();
        }

        if ($totalGrupos <= 1) {
            return $candidatos->first();
        }

        $nomeDono = (string) ($grupo->first(fn ($cs) => (int) $cs->servico_id === $donoId)?->servico?->nome ?? '');
        $tipo     = str_contains(mb_strtolower(Str::ascii($nomeDono)), 'mentoria')
            ? ContratoClicksignAssinado::TIPO_MENTORIA
            : ContratoClicksignAssinado::TIPO_GESTAO;

        return $candidatos->firstWhere('tipo', $tipo);
    }

    /**
     * Vigência (meses) do grupo para a EXTENSÃO na renovação e para o ciclo:
     * prazo do contrato assinado quando existe; senão a regra de
     * `VigenciaContrato` (período do HubSpot ou 12).
     */
    public function mesesDeVigencia(Company $company, int $donoId, Collection $grupo): int
    {
        $assinado = $this->contratoAssinadoDoGrupo($company, $donoId, $grupo);

        if ($this->terminoPeloContrato($assinado) !== null) {
            return (int) $assinado->vigencia_meses;
        }

        return VigenciaContrato::mesesDoGrupo(
            $grupo->map(fn (ContratoServico $cs) => ['servico' => $cs->servico_id, 'parcelas' => $cs->parcelas()])
        );
    }

    /** Término pelo contrato: assinatura + prazo escrito (sem −1 dia, mesma `terminoPorMeses`). */
    private function terminoPeloContrato(?ContratoClicksignAssinado $assinado): ?string
    {
        if ($assinado === null
            || $assinado->situacao !== ContratoClicksignAssinado::SITUACAO_CASADO
            || $assinado->assinado_em === null
            || ! ((int) $assinado->vigencia_meses > 0)) {
            return null;
        }

        return VigenciaContrato::terminoPorMeses(
            Carbon::parse($assinado->assinado_em->toDateString())->startOfDay(),
            (int) $assinado->vigencia_meses
        )->toDateString();
    }

    /**
     * Início sem contrato lido: assinatura do sistema (`contrato_assinaturas`) >
     * assinatura do acervo Clicksign (mesmo sem prazo lido) > menor `data_contratacao`.
     *
     * @return array{0: Carbon, 1: string}
     */
    private function inicioDoGrupo(Company $company, int $donoId, $grupo, ?ContratoClicksignAssinado $assinado = null): array
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

        if ($assinado?->assinado_em !== null) {
            return [Carbon::parse($assinado->assinado_em->toDateString())->startOfDay(), 'assinatura_clicksign'];
        }

        $menor = $grupo->pluck('data_contratacao')->filter()->min();

        return [Carbon::parse($menor)->startOfDay(), 'data_contratacao'];
    }

    private function dataHubspot(ContratoServico $cs): ?string
    {
        $valor = $cs->hubspot_snapshot['line_item']['hs_recurring_billing_end_date'] ?? null;

        if ((! is_string($valor) && ! is_int($valor)) || trim((string) $valor) === '') {
            return null;
        }

        try {
            // O HubSpot devolve propriedade do tipo data como epoch em MILISSEGUNDOS,
            // à meia-noite UTC (medido em produção em 06/11/2026: '1796256000000' =
            // 03/12/2026, início 03/11 + 1 mês). `Carbon::parse()` desse número dava o ano de 1796 — por isso
            // número é lido como ms e em UTC (em BRT a meia-noite UTC cairia na véspera).
            if (is_numeric($valor)) {
                return Carbon::createFromTimestampMs((int) $valor, 'UTC')->toDateString();
            }

            return Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
