<?php

namespace App\Services;

use App\Models\Pendencia;
use App\Models\Publicacao;
use App\Models\Revisao;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Máquina de estados da revisão de anúncios MLB.
 *
 *   nao_revisado ──┬── líder aprova ─────────────→ aprovado
 *                  └── líder abre pendência ─────→ em_ajuste   (bola: publicador)
 *                                                      │
 *                            publicador marca corrigida ↓
 *                                                  reconferir  (bola: líder)
 *                                                      │
 *                              ┌───────────────────────┴──────────────┐
 *                         líder confirma                        líder reabre
 *                              ↓                                     ↓
 *                          aprovado                              em_ajuste
 *
 * "Revisado" deixou de ser estado de propósito: revisar não quer dizer que o
 * anúncio está correto — era exatamente essa a leitura errada que o gestor fazia.
 *
 * Toda escrita passa por aqui para garantir três coisas:
 *  1. o evento fica registrado em mlb_revisoes (auditoria para a Supervisão);
 *  2. o cache de mlb_publicacoes é recalculado a partir das pendências reais;
 *  3. as colunas legadas seguem sincronizadas (dual-write) enquanto Histórico,
 *     Empresas e relatórios antigos ainda lerem delas.
 */
class RevisaoService
{
    /** Aprova: conferido E correto. Fecha o que estiver pendente. */
    public function aprovar(Publicacao $pub, User $revisor, ?string $observacao = null): Publicacao
    {
        return DB::transaction(function () use ($pub, $revisor, $observacao) {
            $de = $pub->status_revisao;

            $pub->pendencias()->pendente()->update([
                'status'        => Pendencia::ST_RESOLVIDA,
                'resolvida_por' => $revisor->id,
                'resolvida_em'  => now(),
                'updated_at'    => now(),
            ]);

            $revisao = $this->registrarEvento($pub, $revisor, $de, Revisao::ST_APROVADO, $observacao);
            $this->sincronizar($pub, $revisor);

            $this->log($revisor, $pub, 'aprovada na revisão', ['de' => $de, 'revisao_id' => $revisao->id]);

            return $pub->refresh();
        });
    }

    /**
     * Abre uma pendência — o que antes era "marcar problema" (bloqueio) ou
     * "deixar comentário" (observação). Move o anúncio para "em ajuste".
     */
    public function abrirPendencia(Publicacao $pub, User $revisor, array $dados): Pendencia
    {
        return DB::transaction(function () use ($pub, $revisor, $dados) {
            $de = $pub->status_revisao;

            $revisao = $this->registrarEvento(
                $pub, $revisor, $de, Revisao::ST_EM_AJUSTE, $dados['observacao'] ?? null
            );

            $pendencia = $pub->pendencias()->create([
                'revisao_id' => $revisao->id,
                'severidade' => $dados['severidade'] ?? Pendencia::SEV_AJUSTE,
                'categoria'  => $dados['categoria'] ?? null,
                'texto'      => $dados['texto'] ?? null,
                'aberta_por' => $revisor->id,
                'aberta_em'  => now(),
                'status'     => Pendencia::ST_ABERTA,
            ]);

            $this->sincronizar($pub, $revisor);

            $this->log($revisor, $pub, 'pendência aberta', [
                'severidade' => $pendencia->severidade,
                'categoria'  => $pendencia->categoria,
                'texto'      => $pendencia->texto,
            ]);

            return $pendencia;
        });
    }

    /**
     * Publicador informa que corrigiu. A pendência não é dada como resolvida
     * aqui — quem confirma é o líder. Antes esse passo não existia: o publicador
     * "resolvia" e ninguém reconferia.
     */
    public function marcarCorrigida(Pendencia $pendencia, User $autor): Pendencia
    {
        return DB::transaction(function () use ($pendencia, $autor) {
            $pendencia->update([
                'status'        => Pendencia::ST_CORRIGIDA,
                'corrigida_por' => $autor->id,
                'corrigida_em'  => now(),
            ]);

            $pub = $pendencia->publicacao;
            $this->sincronizar($pub, null);

            $this->log($autor, $pub, 'correção informada pelo publicador', [
                'pendencia_id' => $pendencia->id,
                'severidade'   => $pendencia->severidade,
            ]);

            return $pendencia->refresh();
        });
    }

    /** Líder confirma que a correção ficou boa. */
    public function resolverPendencia(Pendencia $pendencia, User $revisor): Pendencia
    {
        return DB::transaction(function () use ($pendencia, $revisor) {
            $pendencia->update([
                'status'        => Pendencia::ST_RESOLVIDA,
                'resolvida_por' => $revisor->id,
                'resolvida_em'  => now(),
            ]);

            $pub = $pendencia->publicacao;
            $de  = $pub->status_revisao;

            // Se era a última pendente, o anúncio passa a aprovado.
            if (!$pub->pendencias()->pendente()->exists()) {
                $this->registrarEvento($pub, $revisor, $de, Revisao::ST_APROVADO);
            }

            $this->sincronizar($pub, $revisor);

            $this->log($revisor, $pub, 'pendência resolvida', ['pendencia_id' => $pendencia->id]);

            return $pendencia->refresh();
        });
    }

    /** Líder reabre: a correção não resolveu. Volta a bola para o publicador. */
    public function reabrirPendencia(Pendencia $pendencia, User $revisor, ?string $motivo = null): Pendencia
    {
        return DB::transaction(function () use ($pendencia, $revisor, $motivo) {
            $pendencia->update([
                'status'        => Pendencia::ST_ABERTA,
                'corrigida_por' => null,
                'corrigida_em'  => null,
                'resolvida_por' => null,
                'resolvida_em'  => null,
                'texto'         => $motivo
                    ? trim($pendencia->texto . "\n↻ " . $motivo)
                    : $pendencia->texto,
            ]);

            $pub = $pendencia->publicacao;
            $this->registrarEvento($pub, $revisor, $pub->status_revisao, Revisao::ST_EM_AJUSTE, $motivo);
            $this->sincronizar($pub, $revisor);

            $this->log($revisor, $pub, 'pendência reaberta', [
                'pendencia_id' => $pendencia->id,
                'motivo'       => $motivo,
            ]);

            return $pendencia->refresh();
        });
    }

    /** Desfaz o veredicto — volta o anúncio para a fila do líder. */
    public function reverter(Publicacao $pub, User $revisor): Publicacao
    {
        return DB::transaction(function () use ($pub, $revisor) {
            $de = $pub->status_revisao;
            $this->registrarEvento($pub, $revisor, $de, Revisao::ST_NAO_REVISADO);
            $this->sincronizar($pub, null);

            $this->log($revisor, $pub, 'revisão desfeita', ['de' => $de]);

            return $pub->refresh();
        });
    }

    /**
     * Tira (ou devolve) o anúncio da apuração por estar fora da metodologia.
     *
     * NÃO é um estado de revisão — por isso não passa por `registrarEvento()`.
     * Um anúncio fora da metodologia ainda pode ter pendência e ser aprovado;
     * o que muda é só se ele conta em vendas, meta, conversão e score. Misturar
     * os dois eixos faria "desconsiderar" apagar o veredicto do líder.
     */
    public function definirDesconsiderado(Publicacao $pub, User $autor, bool $desconsiderado): Publicacao
    {
        $pub->update([
            'desconsiderado'     => $desconsiderado,
            'desconsiderado_por' => $desconsiderado ? $autor->id : null,
            'desconsiderado_em'  => $desconsiderado ? now() : null,
        ]);

        $this->log(
            $autor,
            $pub,
            $desconsiderado
                ? 'desconsiderado da apuração (fora da metodologia)'
                : 'devolvido à apuração',
        );

        return $pub->refresh();
    }

    /** Aprovação em lote — o líder revisa dezenas de anúncios por vez. */
    public function aprovarEmLote(array $publicacaoIds, User $revisor): int
    {
        $pubs = Publicacao::whereIn('id', $publicacaoIds)->get();

        foreach ($pubs as $pub) {
            $this->aprovar($pub, $revisor);
        }

        return $pubs->count();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Internos
    // ═══════════════════════════════════════════════════════════════════

    private function registrarEvento(
        Publicacao $pub,
        User $revisor,
        ?string $de,
        string $para,
        ?string $observacao = null
    ): Revisao {
        return Revisao::create([
            'publicacao_id' => $pub->id,
            'revisor_id'    => $revisor->id,
            'de_status'     => $de,
            'para_status'   => $para,
            'observacao'    => $observacao,
        ]);
    }

    /**
     * Recalcula o estado a partir das pendências reais — nunca confia no valor
     * que veio da tela. Também mantém as colunas legadas sincronizadas.
     *
     * @param User|null $revisor quem deu o veredicto; null preserva o anterior
     */
    public function sincronizar(Publicacao $pub, ?User $revisor = null): Publicacao
    {
        $pendencias = $pub->pendencias()->get();

        $abertas    = $pendencias->where('status', Pendencia::ST_ABERTA);
        $corrigidas = $pendencias->where('status', Pendencia::ST_CORRIGIDA);
        $pendentes  = $abertas->merge($corrigidas);

        if ($abertas->isNotEmpty()) {
            // Ainda há coisa para o publicador fazer.
            $status = Revisao::ST_EM_AJUSTE;
        } elseif ($corrigidas->isNotEmpty()) {
            // Tudo corrigido, esperando o líder reconferir.
            $status = Revisao::ST_RECONFERIR;
        } else {
            // Sem pendências: vale o ÚLTIMO evento, não a existência de algum.
            // Checar `exists()` de qualquer veredicto fazia "desfazer aprovação"
            // não sair do lugar — a aprovação antiga ressuscitava o estado.
            $status = $pub->revisoes()->first()?->para_status ?? Revisao::ST_NAO_REVISADO;

            // Estados que dependem de pendência não sobrevivem sem ela.
            if (in_array($status, [Revisao::ST_EM_AJUSTE, Revisao::ST_RECONFERIR], true)) {
                $status = Revisao::ST_APROVADO;
            }
        }

        $bloqueio = $pendentes->firstWhere('severidade', Pendencia::SEV_BLOQUEIO);
        $ultimaObs = $pendencias
            ->where('severidade', Pendencia::SEV_OBSERVACAO)
            ->sortByDesc('aberta_em')
            ->first();

        $dados = [
            'status_revisao'     => $status,
            'pendencias_abertas' => $pendentes->count(),

            // ─── Dual-write das colunas legadas ───
            // Histórico, Empresas e relatórios antigos ainda leem daqui.
            'revisado'             => $status === Revisao::ST_APROVADO,
            'problema'             => (bool) $bloqueio,
            'problema_nota'        => $bloqueio?->texto,
            'problema_em'          => $bloqueio?->aberta_em,
            'comentario'           => $ultimaObs?->texto,
            'comentario_autor_id'  => $ultimaObs?->aberta_por,
            'comentario_em'        => $ultimaObs?->aberta_em,
            'comentario_resolvido' => $ultimaObs ? $ultimaObs->status === Pendencia::ST_RESOLVIDA : false,
        ];

        if ($status === Revisao::ST_APROVADO && $revisor) {
            $dados['revisado_por'] = $revisor->id;
            $dados['revisado_em']  = now();
        } elseif ($status === Revisao::ST_NAO_REVISADO) {
            $dados['revisado_por'] = null;
            $dados['revisado_em']  = null;
        }

        $pub->update($dados);

        return $pub;
    }

    private function log(User $user, Publicacao $pub, string $acao, array $props = []): void
    {
        activity('mlb')
            ->causedBy($user)
            ->withProperties(array_merge([
                'mlb_code' => $pub->mlb_code,
                'empresa'  => $pub->empresa,
            ], $props))
            ->log("Publicação {$pub->mlb_code} ({$pub->empresa}): {$acao}");
    }
}
