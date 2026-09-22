<?php

namespace App\Services\DevDemandas;

use App\Models\DevDemanda;
use App\Models\DevDemandaAtualizacao;
use App\Models\DevReuniao;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Demandas Dev — regras de acesso e montagem das visões (fila, lista, painel).
 *
 * Acesso: admin vê e gerencia tudo; quem não é admin só entra se tiver ao
 * menos uma demanda atribuída, vê apenas as próprias e registra atualização
 * apenas nelas.
 */
class DemandasDevService
{
    // ═══ Acesso ═══

    public function podeAcessar(User $user): bool
    {
        return $user->isAdmin()
            || DevDemanda::query()->where('responsavel_id', $user->id)->exists();
    }

    public function podeGerenciar(User $user): bool
    {
        return $user->isAdmin();
    }

    public function podeAtualizar(User $user, DevDemanda $demanda): bool
    {
        return $user->isAdmin() || $demanda->responsavel_id === $user->id;
    }

    // ═══ Leitura ═══

    /** Demandas que o usuário enxerga, já com o que a serialização precisa. */
    public function demandasVisiveis(User $user): Collection
    {
        return DevDemanda::query()
            ->with(['responsavel:id,name', 'ultimaAtualizacao'])
            ->withCount('atualizacoes')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('responsavel_id', $user->id))
            ->orderBy('codigo')
            ->get();
    }

    /**
     * Linha da demanda para a tela — campos digitados + derivados da última atualização.
     *
     * @return array{id:int, codigo:string, titulo:string, area:?string, escopo:?string,
     *   responsavel:?array, prioridade:int, data_entrada:?string, prazo:?string,
     *   observacoes:?string, status:string, proxima_acao:?string, ultima_atualizacao:?string,
     *   bloqueado:bool, motivo_bloqueio:?string, dias_atraso:int, situacao:string,
     *   faixa_fila:int, total_atualizacoes:int, encerrada:bool}
     */
    public function serializar(DevDemanda $d, CarbonInterface $hoje): array
    {
        $ultima = $d->ultimaAtualizacao;

        return [
            'id'                 => $d->id,
            'codigo'             => $d->codigo,
            'titulo'             => $d->titulo,
            'area'               => $d->area,
            'escopo'             => $d->escopo,
            'responsavel'        => $d->responsavel ? ['id' => $d->responsavel->id, 'name' => $d->responsavel->name] : null,
            'prioridade'         => $d->prioridade,
            'data_entrada'       => $d->data_entrada?->toDateString(),
            'prazo'              => $d->prazo?->toDateString(),
            'observacoes'        => $d->observacoes,
            'status'             => $d->statusAtual(),
            // Sem atualização nenhuma, a planilha pedia "Registrar 1ª atualização" — a tela mostra o mesmo.
            'proxima_acao'       => $ultima?->proxima_acao,
            'ultima_atualizacao' => $ultima?->data?->toDateString(),
            'bloqueado'          => $d->estaBloqueada(),
            'motivo_bloqueio'    => $ultima?->bloqueado ? $ultima->motivo_bloqueio : null,
            'dias_atraso'        => $d->diasEmAtraso($hoje),
            'situacao'           => $d->situacao($hoje),
            'faixa_fila'         => $d->faixaDaFila($hoje),
            'total_atualizacoes' => (int) ($d->atualizacoes_count ?? 0),
            'encerrada'          => $d->estaEncerrada(),
        ];
    }

    /**
     * Fila de trabalho ("Minha Semana"): só abertas, do responsável escolhido
     * (null = todo mundo), ordenadas por faixa → prazo (sem prazo por último) → código.
     *
     * @param  array<int, array>  $linhas  saída de serializar()
     */
    public function fila(array $linhas, ?int $responsavelId): array
    {
        $fila = array_values(array_filter($linhas, fn (array $l) => ! $l['encerrada']
            && ($responsavelId === null || ($l['responsavel']['id'] ?? null) === $responsavelId)));

        usort($fila, fn (array $a, array $b) => [$a['faixa_fila'], $a['prazo'] ?? '9999-12-31', $a['codigo']]
            <=> [$b['faixa_fila'], $b['prazo'] ?? '9999-12-31', $b['codigo']]);

        return $fila;
    }

    /**
     * Números do painel — tudo contado a partir das linhas, nada digitado.
     *
     * @param  array<int, array>  $linhas  saída de serializar()
     * @return array{total:int, abertas:int, por_status:array, por_situacao:array,
     *   abertas_por_prioridade:array, abertas_por_responsavel:array, abertas_por_area:array}
     */
    public function painel(array $linhas): array
    {
        $abertas = array_filter($linhas, fn (array $l) => ! $l['encerrada']);

        $porStatus = array_fill_keys(array_keys(DevDemanda::STATUS_LABELS), 0);
        foreach ($linhas as $l) {
            $porStatus[$l['status']] = ($porStatus[$l['status']] ?? 0) + 1;
        }

        $porSituacao = [];
        foreach ($linhas as $l) {
            $porSituacao[$l['situacao']] = ($porSituacao[$l['situacao']] ?? 0) + 1;
        }

        $porPrioridade = array_fill_keys(array_keys(DevDemanda::PRIORIDADE_LABELS), 0);
        $porResponsavel = [];
        $porArea = [];
        foreach ($abertas as $l) {
            $porPrioridade[$l['prioridade']] = ($porPrioridade[$l['prioridade']] ?? 0) + 1;

            $nome = $l['responsavel']['name'] ?? 'Sem responsável';
            $porResponsavel[$nome] = ($porResponsavel[$nome] ?? 0) + 1;

            $area = $l['area'] ?: 'Sem área';
            $porArea[$area] = ($porArea[$area] ?? 0) + 1;
        }
        arsort($porResponsavel);
        arsort($porArea);

        return [
            'total'                   => count($linhas),
            'abertas'                 => count($abertas),
            'por_status'              => $porStatus,
            'por_situacao'            => $porSituacao,
            'abertas_por_prioridade'  => $porPrioridade,
            'abertas_por_responsavel' => $porResponsavel,
            'abertas_por_area'        => $porArea,
        ];
    }

    /** Histórico completo de uma demanda para o painel lateral (mais recente primeiro). */
    public function detalhe(DevDemanda $d): array
    {
        $atualizacoes = $d->atualizacoes()
            ->with('autor:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DevDemandaAtualizacao $a) => [
                'id'                => $a->id,
                'data'              => $a->data?->toDateString(),
                'autor'             => $a->nomeDoAutor(),
                'status'            => $a->status,
                'feito'             => $a->feito,
                'proxima_acao'      => $a->proxima_acao,
                'bloqueado'         => $a->bloqueado,
                'motivo_bloqueio'   => $a->motivo_bloqueio,
                'previsao_revisada' => $a->previsao_revisada?->toDateString(),
                'registrado_em'     => $a->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'id'           => $d->id,
            'atualizacoes' => $atualizacoes,
            'reunioes'     => $d->reunioes()->orderByDesc('data')->get(['dev_reunioes.id', 'data', 'titulo'])
                ->map(fn (DevReuniao $r) => ['id' => $r->id, 'data' => $r->data?->toDateString(), 'titulo' => $r->titulo])
                ->all(),
        ];
    }

    /** Biblioteca de reuniões. Quem não é admin vê só as ligadas às próprias demandas. */
    public function reunioes(User $user): array
    {
        return DevReuniao::query()
            ->with('demandas:dev_demandas.id,codigo,titulo,responsavel_id')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('demandas', fn ($d) => $d->where('responsavel_id', $user->id)))
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DevReuniao $r) => [
                'id'               => $r->id,
                'data'             => $r->data?->toDateString(),
                'titulo'           => $r->titulo,
                'participantes'    => $r->participantes,
                'link_gravacao'    => $r->link_gravacao,
                'link_transcricao' => $r->link_transcricao,
                'decisoes'         => $r->decisoes,
                'duracao'          => $r->duracao,
                'demandas'         => $r->demandas
                    ->when(! $user->isAdmin(), fn ($c) => $c->where('responsavel_id', $user->id))
                    ->sortBy('codigo')
                    ->map(fn (DevDemanda $d) => ['id' => $d->id, 'codigo' => $d->codigo, 'titulo' => $d->titulo])
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
