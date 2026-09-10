<?php

namespace App\Services\Fechamento;

use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoReconsolidacao;
use App\Models\FechamentoSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FechamentoSnapshotWriter — ÚNICO ponto de escrita das tabelas
 * `fechamento_snapshots` e `fechamento_grupo_snapshots` (Fase 137, D-11 +
 * D-12 revisado). Nenhum controller ou comando pode gravar direto nessas
 * duas tabelas — sempre por aqui.
 *
 * Só PERSISTE o que recebe — não calcula faturamento, faixa nem cobrança.
 * Quem calcula é `App\Console\Commands\ConsolidarMesFechamento` (usando
 * `FechamentoRollupService` e `FechamentoFaixaResolver`).
 *
 * Nenhum controller ou comando deve chamar o helper genérico de
 * "atualiza-se-existe-senão-cria" do Eloquent diretamente nessas duas
 * tabelas — sempre por `sync()`, que faz a busca manual com `whereDate()`
 * (ver o motivo no comentário de `syncEmpresas()` abaixo).
 *
 * ⚠️ Divergência DELIBERADA do molde do Desempenho
 * (`App\Services\Desempenho\CompanyScoreSnapshotWriter`): lá a origem
 * oficial ('consolidar_mes') ignora a trava de congelamento em silêncio e
 * só registra em `Log`. Aqui, D-12 revisado exige que reconsolidar uma
 * competência já fechada EXIJA `$motivo` explícito — sem ele, `sync()`
 * lança `RuntimeException` e NENHUMA linha muda. Com motivo, o payload
 * anterior (empresas + grupos) é preservado em `fechamento_reconsolidacoes`
 * ANTES da sobrescrita — porque o valor gravado aqui entra em cobrança e
 * precisa ser auditável por quem, quando e por quê.
 */
class FechamentoSnapshotWriter
{
    public const ORIGEM_CONSOLIDAR_MES = 'consolidar_mes';

    /**
     * @param  array<int, array<string, mixed>>  $linhasEmpresa  cada item com as chaves de `fechamento_snapshots`, incluindo `company_id`.
     * @param  array<int, array<string, mixed>>  $linhasGrupo    cada item com as chaves de `fechamento_grupo_snapshots`, incluindo `company_group_id`.
     * @return array{empresas_upserted: int, empresas_pruned: int, grupos_upserted: int, grupos_pruned: int, reconsolidado: bool}
     *
     * @throws \RuntimeException quando a competência já está congelada e `$motivo` não foi informado.
     */
    public function sync(
        Carbon $mes,
        array $linhasEmpresa,
        array $linhasGrupo,
        string $origem,
        ?int $reconsolidadoPor = null,
        ?string $motivo = null
    ): array {
        $mesStr = $mes->copy()->startOfMonth()->toDateString();

        return DB::transaction(function () use ($mesStr, $linhasEmpresa, $linhasGrupo, $origem, $reconsolidadoPor, $motivo) {
            // Trava de congelamento — SEMPRE checada contra
            // ORIGEM_CONSOLIDAR_MES, independente do $origem recebido (é a
            // única origem que existe nesta fase). lockForUpdate() dentro
            // da transação serializa contra outra chamada concorrente de
            // sync() sobre a mesma competência.
            $jaCongelado = FechamentoSnapshot::query()
                ->whereDate('mes_referencia', $mesStr)
                ->where('origem', self::ORIGEM_CONSOLIDAR_MES)
                ->lockForUpdate()
                ->exists();

            $motivoPreenchido = $motivo !== null && trim($motivo) !== '';

            if ($jaCongelado && ! $motivoPreenchido) {
                throw new \RuntimeException('Competência já fechada — informe o motivo da reconsolidação.');
            }

            $reconsolidado = false;

            if ($jaCongelado && $motivoPreenchido) {
                // Payload anterior COMPLETO, lido ANTES de qualquer
                // sobrescrita — é a prova do que estava congelado até aqui.
                $snapshotAnterior = [
                    'empresas' => FechamentoSnapshot::query()
                        ->whereDate('mes_referencia', $mesStr)
                        ->get()
                        ->map(fn ($linha) => $linha->toArray())
                        ->all(),
                    'grupos' => FechamentoGrupoSnapshot::query()
                        ->whereDate('mes_referencia', $mesStr)
                        ->get()
                        ->map(fn ($linha) => $linha->toArray())
                        ->all(),
                ];

                FechamentoReconsolidacao::create([
                    'mes_referencia'    => $mesStr,
                    'reconsolidado_por' => $reconsolidadoPor,
                    'motivo'            => $motivo,
                    'snapshot_anterior' => $snapshotAnterior,
                    'origem'            => $origem,
                ]);

                $reconsolidado = true;
            }

            [$empresasUpserted, $empresasPruned] = $this->syncEmpresas($mesStr, $linhasEmpresa, $origem);
            [$gruposUpserted, $gruposPruned]     = $this->syncGrupos($mesStr, $linhasGrupo, $origem);

            return [
                'empresas_upserted' => $empresasUpserted,
                'empresas_pruned'   => $empresasPruned,
                'grupos_upserted'   => $gruposUpserted,
                'grupos_pruned'     => $gruposPruned,
                'reconsolidado'     => $reconsolidado,
            ];
        });
    }

    /**
     * Upsert + prune de `fechamento_snapshots` para a competência `$mesStr`.
     *
     * @return array{0: int, 1: int} [upserted, pruned]
     */
    private function syncEmpresas(string $mesStr, array $linhasEmpresa, string $origem): array
    {
        $idsAtuais = [];
        $upserted  = 0;

        foreach ($linhasEmpresa as $linha) {
            $companyId = $linha['company_id'] ?? null;
            if ($companyId === null) {
                continue;
            }

            $companyId   = (int) $companyId;
            $idsAtuais[] = $companyId;

            $dados               = $linha;
            $dados['origem']     = $origem;
            $dados['gerado_em']  = now();

            // NUNCA buscar/gravar passando `mes_referencia` cru no array de
            // condição: o cast `date` do model grava datetime completo e a
            // comparação com a string crua ('Y-m-d') nunca casa (armadilha
            // documentada no writer do Desempenho, CompanyScoreSnapshotWriter.php
            // linhas ~112-119). Por isso a busca manual com `whereDate()`.
            $existente = FechamentoSnapshot::query()
                ->where('company_id', $companyId)
                ->whereDate('mes_referencia', $mesStr)
                ->first();

            if ($existente) {
                $existente->fill($dados)->save();
            } else {
                FechamentoSnapshot::create($dados + [
                    'company_id'     => $companyId,
                    'mes_referencia' => $mesStr,
                ]);
            }

            $upserted++;
        }

        // Prune: converge para o conjunto atual — quem saiu do conjunto
        // desta rodada é removido, nunca deixado obsoleto.
        $pruneQuery = FechamentoSnapshot::query()->whereDate('mes_referencia', $mesStr);
        if ($idsAtuais !== []) {
            $pruneQuery->whereNotIn('company_id', $idsAtuais);
        }
        $pruned = (clone $pruneQuery)->count();
        $pruneQuery->delete();

        return [$upserted, $pruned];
    }

    /**
     * Upsert + prune de `fechamento_grupo_snapshots` para a competência
     * `$mesStr`. Mesma disciplina de `syncEmpresas()`.
     *
     * @return array{0: int, 1: int} [upserted, pruned]
     */
    private function syncGrupos(string $mesStr, array $linhasGrupo, string $origem): array
    {
        $idsAtuais = [];
        $upserted  = 0;

        foreach ($linhasGrupo as $linha) {
            $groupId = $linha['company_group_id'] ?? null;
            if ($groupId === null) {
                continue;
            }

            $groupId     = (int) $groupId;
            $idsAtuais[] = $groupId;

            $dados               = $linha;
            $dados['origem']     = $origem;
            $dados['gerado_em']  = now();

            $existente = FechamentoGrupoSnapshot::query()
                ->where('company_group_id', $groupId)
                ->whereDate('mes_referencia', $mesStr)
                ->first();

            if ($existente) {
                $existente->fill($dados)->save();
            } else {
                FechamentoGrupoSnapshot::create($dados + [
                    'company_group_id' => $groupId,
                    'mes_referencia'   => $mesStr,
                ]);
            }

            $upserted++;
        }

        $pruneQuery = FechamentoGrupoSnapshot::query()->whereDate('mes_referencia', $mesStr);
        if ($idsAtuais !== []) {
            $pruneQuery->whereNotIn('company_group_id', $idsAtuais);
        }
        $pruned = (clone $pruneQuery)->count();
        $pruneQuery->delete();

        return [$upserted, $pruned];
    }
}
