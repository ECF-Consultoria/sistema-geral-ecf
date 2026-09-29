<?php

namespace App\Services\DevDemandas;

use App\Models\DevDemanda;
use App\Models\DevDemandaAtualizacao;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Linha do tempo das demandas dev — a lógica do Gantt (o prometido contra o
 * realizado) reconstruída do diário de atualizações. Nenhuma coluna nova.
 *
 *  - Fase: o intervalo entre uma atualização e a seguinte leva o tipo da que o
 *    abriu (fila, desenvolvimento, bloqueada, validação). Antes da primeira
 *    atualização a demanda está na fila, desde a data de entrada.
 *  - Prazo dado: a previsão gravada na PRIMEIRA atualização de trabalho (o início).
 *    Quem faz dá a data ao começar e, como o diário não se edita nem se apaga, esse
 *    prazo fica congelado. Previsões posteriores são revisões — feitas antes ou
 *    depois de vencer.
 *  - Demanda que começou sem prazo dado (as importadas da planilha, ou concluídas
 *    direto do backlog) fica fora da pontualidade: não se inventa prazo retroativo.
 *
 * Dias são corridos, como no resto do módulo (`DevDemanda::diasEmAtraso`).
 */
class LinhaDoTempoService
{
    /** Janela das métricas por dev: entregas concluídas nos últimos N dias. */
    public const JANELA_DIAS = 90;

    public const FASE_FILA            = 'fila';
    public const FASE_DESENVOLVIMENTO = 'desenvolvimento';
    public const FASE_BLOQUEADA       = 'bloqueada';
    public const FASE_VALIDACAO       = 'validacao';

    public const ESTADO_ABERTA    = 'aberta';
    public const ESTADO_CONCLUIDA = 'concluida';
    public const ESTADO_CANCELADA = 'cancelada';

    /**
     * Linha do tempo de várias demandas com uma consulta só ao diário.
     *
     * @param  Collection<int, DevDemanda>  $demandas
     * @return array<int, array> por id da demanda, no formato de `montar()`
     */
    public function paraDemandas(Collection $demandas, CarbonInterface $hoje): array
    {
        $diario = DevDemandaAtualizacao::query()
            ->whereIn('dev_demanda_id', $demandas->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'dev_demanda_id', 'data', 'status', 'bloqueado', 'previsao_revisada', 'created_at'])
            ->groupBy('dev_demanda_id');

        return $demandas->mapWithKeys(fn (DevDemanda $d) => [
            $d->id => $this->montar($d, $diario->get($d->id, collect()), $hoje),
        ])->all();
    }

    /**
     * @param  Collection<int, DevDemandaAtualizacao>  $atualizacoes  em ordem de registro (id)
     * @return array{entrada:string, fases:array<int, array{tipo:string, de:string, ate:string, dias:int}>,
     *   inicio_id:?int, iniciada_em:?string, prazo_dado:?string, revisoes:array<int, array{em:string, de:string,
     *   para:string, antes_de_vencer:bool}>, revisou_antes:bool, estado:string, concluida_em:?string,
     *   desvio:?int, retrabalho:int, dias:array{desenvolvimento:int, bloqueada:int, validacao:int, fila:int}}
     *   `desvio` = dias entre o prazo dado e a entrega (aberta: até hoje). Positivo = depois do prazo.
     */
    public function montar(DevDemanda $d, Collection $atualizacoes, CarbonInterface $hoje): array
    {
        $hoje    = Carbon::parse($hoje)->startOfDay();
        $entrada = Carbon::parse($d->data_entrada)->startOfDay();
        if ($entrada->gt($hoje)) {
            $entrada = $hoje->copy();
        }

        // Marcos: cada atualização abre uma fase (tipo) ou encerra a demanda (tipo null).
        // A data informada é respeitada, mas nunca volta antes do marco anterior nem passa de hoje.
        $marcos    = [['data' => $entrada, 'tipo' => self::FASE_FILA, 'status' => null]];
        $t         = $entrada;
        $inicio    = null;
        $prazoDado = null;
        $prometido = null;
        $revisoes  = [];

        foreach ($atualizacoes as $u) {
            $data = Carbon::parse($u->data)->startOfDay();
            $data = $data->lt($t) ? $t->copy() : ($data->gt($hoje) ? $hoje->copy() : $data);
            $t    = $data;

            $encerra  = in_array($u->status, DevDemanda::STATUS_ENCERRADOS, true);
            $marcos[] = ['data' => $data, 'tipo' => $encerra ? null : $this->tipoDaFase($u), 'status' => $u->status];

            $previsao = $u->previsao_revisada ? Carbon::parse($u->previsao_revisada)->startOfDay() : null;

            if ($inicio === null && in_array($u->status, [...DevDemanda::STATUS_TRABALHO, DevDemanda::STATUS_CONCLUIDO], true)) {
                $inicio    = ['id' => $u->id, 'data' => $data];
                $prazoDado = $previsao;
                $prometido = $previsao;

                continue;
            }

            if ($prazoDado && $previsao && ! $previsao->eq($prometido)) {
                // Quando o aviso foi dado: o REGISTRO, não a data digitada — senão dava para avisar "no passado".
                $em = $u->created_at ? Carbon::parse($u->created_at)->startOfDay() : $data;
                $revisoes[] = [
                    'em'              => $em->toDateString(),
                    'de'              => $prometido->toDateString(),
                    'para'            => $previsao->toDateString(),
                    // No próprio dia do prazo ainda é "antes de vencer".
                    'antes_de_vencer' => $em->lte($prometido),
                ];
                $prometido = $previsao;
            }
        }

        $fases = $this->fases($marcos, $hoje);

        $estado = match ($atualizacoes->last()?->status) {
            DevDemanda::STATUS_CONCLUIDO => self::ESTADO_CONCLUIDA,
            DevDemanda::STATUS_CANCELADO => self::ESTADO_CANCELADA,
            default                      => self::ESTADO_ABERTA,
        };
        $fim = $estado === self::ESTADO_ABERTA ? null : end($marcos)['data'];

        $desvio = null;
        if ($prazoDado && $estado !== self::ESTADO_CANCELADA) {
            $desvio = $this->dias($prazoDado, $fim ?? $hoje);
        }

        $dias = array_fill_keys([self::FASE_DESENVOLVIMENTO, self::FASE_BLOQUEADA, self::FASE_VALIDACAO, self::FASE_FILA], 0);
        foreach ($fases as $f) {
            $dias[$f['tipo']] += $f['dias'];
        }

        return [
            'entrada'       => $entrada->toDateString(),
            'fases'         => $fases,
            'inicio_id'     => $inicio['id'] ?? null,
            'iniciada_em'   => isset($inicio) ? $inicio['data']->toDateString() : null,
            'prazo_dado'    => $prazoDado?->toDateString(),
            'revisoes'      => $revisoes,
            // "Revisou antes de estourar": a PRIMEIRA revisão saiu antes do prazo dado vencer.
            'revisou_antes' => isset($revisoes[0]) && $revisoes[0]['antes_de_vencer'],
            'estado'        => $estado,
            'concluida_em'  => $estado === self::ESTADO_CONCLUIDA ? $fim->toDateString() : null,
            'desvio'        => $desvio,
            'retrabalho'    => $this->retrabalho($marcos),
            'dias'          => $dias,
        ];
    }

    /**
     * Números por responsável, sobre as entregas concluídas na janela. Por enquanto
     * é só observação — nenhuma nota nem meta sai daqui.
     *
     * @param  array<int, array>  $linhas  serializar() + ['tempo' => montar()]
     * @return array<int, array{id:int, nome:string, entregas:int, com_prazo:int, sem_prazo:int, no_prazo:int,
     *   atrasos:int, revisou_antes:int, sobra_mediana:int|float|null, retrabalho:int, ativo_mediano:int|float|null,
     *   espera_bloqueio:int, espera_validacao:int, pontos:array}>
     */
    public function metricasPorDev(array $linhas, CarbonInterface $hoje): array
    {
        $corte  = Carbon::parse($hoje)->startOfDay()->subDays(self::JANELA_DIAS);
        $porDev = [];

        foreach ($linhas as $l) {
            $r = $l['responsavel'] ?? null;
            if (! $r) {
                continue;
            }
            $porDev[$r['id']] ??= ['id' => $r['id'], 'nome' => $r['name'], 'entregues' => []];

            $t = $l['tempo'];
            if ($t['estado'] === self::ESTADO_CONCLUIDA && Carbon::parse($t['concluida_em'])->gt($corte)) {
                $porDev[$r['id']]['entregues'][] = $l;
            }
        }

        $saida = array_map(function (array $dev) {
            $entregues = $dev['entregues'];
            $comPrazo  = array_values(array_filter($entregues, fn ($l) => $l['tempo']['prazo_dado'] !== null));
            $atrasos   = array_filter($comPrazo, fn ($l) => $l['tempo']['desvio'] > 0);

            return [
                'id'               => $dev['id'],
                'nome'             => $dev['nome'],
                'entregas'         => count($entregues),
                'com_prazo'        => count($comPrazo),
                'sem_prazo'        => count($entregues) - count($comPrazo),
                'no_prazo'         => count($comPrazo) - count($atrasos),
                'atrasos'          => count($atrasos),
                'revisou_antes'    => count(array_filter($atrasos, fn ($l) => $l['tempo']['revisou_antes'])),
                // Sobra = quanto antes do prazo dado a entrega saiu. Perto de zero = prazo bem dado.
                'sobra_mediana'    => $this->mediana(array_map(fn ($l) => -$l['tempo']['desvio'], $comPrazo)),
                'retrabalho'       => count(array_filter($entregues, fn ($l) => $l['tempo']['retrabalho'] > 0)),
                'ativo_mediano'    => $this->mediana(array_map(fn ($l) => $l['tempo']['dias'][self::FASE_DESENVOLVIMENTO], $entregues)),
                'espera_bloqueio'  => array_sum(array_map(fn ($l) => $l['tempo']['dias'][self::FASE_BLOQUEADA], $entregues)),
                'espera_validacao' => array_sum(array_map(fn ($l) => $l['tempo']['dias'][self::FASE_VALIDACAO], $entregues)),
                'pontos'           => array_map(fn ($l) => [
                    'id'            => $l['id'],
                    'codigo'        => $l['codigo'],
                    'titulo'        => $l['titulo'],
                    'prazo_dado'    => $l['tempo']['prazo_dado'],
                    'concluida_em'  => $l['tempo']['concluida_em'],
                    'desvio'        => $l['tempo']['desvio'],
                    'revisou_antes' => $l['tempo']['revisou_antes'],
                ], $comPrazo),
            ];
        }, array_values($porDev));

        usort($saida, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        return $saida;
    }

    // ─── Montagem ────────────────────────────────────────────────────────────

    /** Bloqueio vale como fase mesmo marcado só na caixinha, com outro status. */
    private function tipoDaFase(DevDemandaAtualizacao $u): string
    {
        if ($u->bloqueado || $u->status === DevDemanda::STATUS_BLOQUEADO) {
            return self::FASE_BLOQUEADA;
        }

        return match ($u->status) {
            DevDemanda::STATUS_EM_DESENVOLVIMENTO => self::FASE_DESENVOLVIMENTO,
            DevDemanda::STATUS_EM_VALIDACAO       => self::FASE_VALIDACAO,
            default                               => self::FASE_FILA, // backlog, a fazer
        };
    }

    /** Marcos → fases contíguas; marcos seguidos do mesmo tipo viram uma fase só. Fase de 0 dia some. */
    private function fases(array $marcos, Carbon $hoje): array
    {
        $fases = [];
        foreach ($marcos as $i => $m) {
            if ($m['tipo'] === null) {
                continue;
            }
            $ate = $marcos[$i + 1]['data'] ?? $hoje;

            if ($i > 0 && $marcos[$i - 1]['tipo'] === $m['tipo'] && $fases) {
                $fases[count($fases) - 1]['ate'] = $ate;
            } else {
                $fases[] = ['tipo' => $m['tipo'], 'de' => $m['data'], 'ate' => $ate];
            }
        }

        return array_values(array_filter(array_map(fn (array $f) => [
            'tipo' => $f['tipo'],
            'de'   => $f['de']->toDateString(),
            'ate'  => $f['ate']->toDateString(),
            'dias' => $this->dias($f['de'], $f['ate']),
        ], $fases), fn (array $f) => $f['dias'] > 0));
    }

    /**
     * Voltas ao desenvolvimento depois de enviada para validação ou depois de concluída.
     * Conta pela sequência de status, não pela duração — voltar no mesmo dia também é retrabalho.
     */
    private function retrabalho(array $marcos): int
    {
        $voltas   = 0;
        $anterior = null;
        foreach (array_slice($marcos, 1) as $m) {
            $atual = $m['tipo'] ?? $m['status']; // encerrada: 'concluido' / 'cancelado'
            if ($atual === self::FASE_DESENVOLVIMENTO && in_array($anterior, [self::FASE_VALIDACAO, DevDemanda::STATUS_CONCLUIDO], true)) {
                $voltas++;
            }
            $anterior = $atual;
        }

        return $voltas;
    }

    /** Dias corridos de `a` até `b` — positivo quando `b` é depois. */
    private function dias(CarbonInterface $a, CarbonInterface $b): int
    {
        // Carbon 3: diffInDays é sinalizado e fracionário; as datas já estão no início do dia.
        return (int) round($a->diffInDays($b, false));
    }

    private function mediana(array $valores): int|float|null
    {
        if (! $valores) {
            return null;
        }
        sort($valores);
        $n = count($valores);
        $m = intdiv($n, 2);

        return $n % 2 ? $valores[$m] : ($valores[$m - 1] + $valores[$m]) / 2;
    }
}
