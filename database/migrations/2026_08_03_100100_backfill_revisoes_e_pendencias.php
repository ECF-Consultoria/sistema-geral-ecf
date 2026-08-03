<?php

use App\Models\Pendencia;
use App\Models\Revisao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migra o estado de revisão que hoje vive em colunas planas de `mlb_publicacoes`
 * para o modelo de eventos (`mlb_revisoes` + `mlb_pendencias`).
 *
 * A parte interessante é a autoria: `problema_por` nunca existiu como coluna —
 * nunca se soube quem abriu um problema. Mas cada `marcarProblema()` gravou
 * `activity('mlb')->causedBy($user)`, então dá para recuperar a autoria
 * histórica do `activity_log` em vez de nascer com o campo vazio.
 *
 * As colunas antigas NÃO são apagadas: seguem em dual-write pelo RevisaoService
 * até a reforma ser validada em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Autoria histórica a partir do activity log ───
        // O activity('mlb') customizado não tem subject_id — a publicação é
        // identificada pelo mlb_code dentro de `properties`.
        $autoriaProblema  = $this->autoriaPorMlbCode('Problema marcado%');
        $autoriaRevisao   = $this->autoriaPorMlbCode('%marcada como revisada%');

        // Colunas que só existem se o WIP anterior chegou a rodar localmente.
        $temResolucaoProblema   = Schema::hasColumn('mlb_publicacoes', 'problema_resolvido_em');
        $temResolucaoComentario = Schema::hasColumn('mlb_publicacoes', 'comentario_resolvido_em');

        $agora = now();

        DB::table('mlb_publicacoes')
            ->orderBy('id')
            ->chunkById(500, function ($pubs) use (
                $autoriaProblema, $autoriaRevisao,
                $temResolucaoProblema, $temResolucaoComentario, $agora
            ) {
                $pendencias = [];
                $revisoes   = [];

                foreach ($pubs as $p) {
                    $autorProb = $autoriaProblema[$p->mlb_code] ?? null;
                    $autorRev  = $autoriaRevisao[$p->mlb_code] ?? null;

                    $temPendenciaAberta = false;

                    // ── Problema → pendência de severidade "bloqueio" ──
                    // Cobre tanto o problema em aberto quanto o já resolvido:
                    // um problema resolvido deixou nota e data de abertura.
                    $problemaResolvidoEm = $temResolucaoProblema ? ($p->problema_resolvido_em ?? null) : null;
                    $houveProblema = $p->problema || $p->problema_nota || $p->problema_em || $problemaResolvidoEm;

                    if ($houveProblema) {
                        $resolvida = !$p->problema;
                        if (!$resolvida) $temPendenciaAberta = true;

                        $pendencias[] = [
                            'publicacao_id' => $p->id,
                            'revisao_id'    => null,
                            'severidade'    => Pendencia::SEV_BLOQUEIO,
                            'categoria'     => null,
                            'texto'         => $p->problema_nota,
                            'aberta_por'    => $autorProb['causer_id'] ?? null,
                            'aberta_em'     => $p->problema_em ?? $autorProb['created_at'] ?? $p->created_at,
                            'status'        => $resolvida ? Pendencia::ST_RESOLVIDA : Pendencia::ST_ABERTA,
                            'corrigida_por' => null,
                            'corrigida_em'  => null,
                            'resolvida_por' => $temResolucaoProblema ? ($p->problema_resolvido_por ?? null) : null,
                            'resolvida_em'  => $resolvida ? ($problemaResolvidoEm ?? $p->updated_at) : null,
                            'created_at'    => $agora,
                            'updated_at'    => $agora,
                        ];
                    }

                    // ── Comentário → pendência de severidade "observação" ──
                    if (!empty($p->comentario)) {
                        $resolvida = (bool) $p->comentario_resolvido;
                        if (!$resolvida) $temPendenciaAberta = true;

                        $pendencias[] = [
                            'publicacao_id' => $p->id,
                            'revisao_id'    => null,
                            'severidade'    => Pendencia::SEV_OBSERVACAO,
                            'categoria'     => null,
                            'texto'         => $p->comentario,
                            'aberta_por'    => $p->comentario_autor_id,
                            'aberta_em'     => $p->comentario_em ?? $p->created_at,
                            'status'        => $resolvida ? Pendencia::ST_RESOLVIDA : Pendencia::ST_ABERTA,
                            'corrigida_por' => null,
                            'corrigida_em'  => null,
                            'resolvida_por' => $temResolucaoComentario ? ($p->comentario_resolvido_por ?? null) : null,
                            'resolvida_em'  => $resolvida
                                ? ($temResolucaoComentario ? ($p->comentario_resolvido_em ?? $p->updated_at) : $p->updated_at)
                                : null,
                            'created_at'    => $agora,
                            'updated_at'    => $agora,
                        ];
                    }

                    // ── Estado derivado ──
                    // Pendência aberta vence: um anúncio com pendência não está
                    // aprovado, mesmo que alguém tenha marcado "revisado".
                    if ($temPendenciaAberta) {
                        $status = Revisao::ST_EM_AJUSTE;
                    } elseif ($p->revisado) {
                        $status = Revisao::ST_APROVADO;
                    } else {
                        $status = Revisao::ST_NAO_REVISADO;
                    }

                    $revisadoPor = $autorRev['causer_id'] ?? null;
                    $revisadoEm  = $p->revisado ? ($autorRev['created_at'] ?? $p->updated_at) : null;

                    DB::table('mlb_publicacoes')->where('id', $p->id)->update([
                        'status_revisao'     => $status,
                        'revisado_por'       => $p->revisado ? $revisadoPor : null,
                        'revisado_em'        => $revisadoEm,
                        'pendencias_abertas' => 0, // recalculado no passo 3
                    ]);

                    // ── Revisão sintética ──
                    // Dá histórico à aba Supervisão desde o primeiro dia, em vez
                    // de a tela nascer vazia.
                    if ($p->revisado && $revisadoPor) {
                        $revisoes[] = [
                            'publicacao_id' => $p->id,
                            'revisor_id'    => $revisadoPor,
                            'de_status'     => Revisao::ST_NAO_REVISADO,
                            'para_status'   => $status === Revisao::ST_EM_AJUSTE
                                ? Revisao::ST_EM_AJUSTE
                                : Revisao::ST_APROVADO,
                            'observacao'    => 'Importado do histórico anterior',
                            'created_at'    => $revisadoEm ?? $p->updated_at,
                            'updated_at'    => $revisadoEm ?? $p->updated_at,
                        ];
                    }
                }

                foreach (array_chunk($pendencias, 200) as $lote) {
                    DB::table('mlb_pendencias')->insert($lote);
                }
                foreach (array_chunk($revisoes, 200) as $lote) {
                    DB::table('mlb_revisoes')->insert($lote);
                }
            });

        // ─── 3. Recalcula o cache de pendências abertas ───
        DB::statement("
            UPDATE mlb_publicacoes p
            SET pendencias_abertas = (
                SELECT COUNT(*) FROM mlb_pendencias d
                WHERE d.publicacao_id = p.id AND d.status IN ('aberta', 'corrigida')
            )
        ");
    }

    public function down(): void
    {
        DB::table('mlb_pendencias')->delete();
        DB::table('mlb_revisoes')->delete();
        DB::table('mlb_publicacoes')->update([
            'status_revisao'     => Revisao::ST_NAO_REVISADO,
            'revisado_por'       => null,
            'revisado_em'        => null,
            'pendencias_abertas' => 0,
        ]);
    }

    /**
     * Extrai do activity_log quem executou uma ação e quando, indexado por
     * mlb_code. Mantém a ocorrência MAIS RECENTE de cada anúncio.
     *
     * @return array<string, array{causer_id:int|null, created_at:string}>
     */
    private function autoriaPorMlbCode(string $descriptionLike): array
    {
        if (!Schema::hasTable('activity_log')) return [];

        $mapa = [];

        DB::table('activity_log')
            ->where('log_name', 'mlb')
            ->where('description', 'like', $descriptionLike)
            ->orderBy('id')
            ->select(['causer_id', 'created_at', 'properties'])
            ->chunk(1000, function ($linhas) use (&$mapa) {
                foreach ($linhas as $l) {
                    $props = json_decode($l->properties ?? '{}', true);
                    $code  = $props['mlb_code'] ?? null;
                    if (!$code) continue;

                    // Ordenado por id crescente: a última escrita vence.
                    $mapa[$code] = [
                        'causer_id'  => $l->causer_id,
                        'created_at' => $l->created_at,
                    ];
                }
            });

        return $mapa;
    }
};
