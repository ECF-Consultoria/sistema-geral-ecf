<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Publicacao extends Model
{
    use LogsActivity;

    protected $table = 'mlb_publicacoes';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'empresa', 'mlb_empresa_id', 'tipo', 'mlb_code', 'sku_stage', 'vendido', 'status_revisao', 'revisado', 'desconsiderado', 'problema', 'problema_nota', 'comentario'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Publicação MLB cadastrada',
                'updated' => 'Publicação MLB atualizada',
                'deleted' => 'Publicação MLB excluída',
                default   => $eventName,
            });
    }


    protected $fillable = [
        'data',
        'prazo',
        'user_id',
        'empresa',
        'cust_id',
        'mlb_code',
        'mlb_empresa_id',
        'tipo',
        'sku_stage',
        'sku_position',
        'vendido',
        'vendas_qty',
        // ─── Estado de revisão (modelo atual) ───
        'status_revisao',
        'revisado_por',
        'revisado_em',
        'pendencias_abertas',
        // ─── Fora da metodologia ───
        // Ortogonal à revisão: o anúncio segue existindo e revisável, só para
        // de contar em vendas, meta, conversão e score.
        'desconsiderado',
        'desconsiderado_por',
        'desconsiderado_em',
        // ─── Colunas legadas ───
        // Mantidas em dual-write pelo RevisaoService enquanto Histórico,
        // Empresas e relatórios antigos ainda leem daqui. Não escrever
        // diretamente: passar sempre pelo serviço.
        'revisado',
        'problema',
        'problema_nota',
        'problema_em',
        'problema_resolvido_por',
        'problema_resolvido_em',
        'comentario',
        'comentario_autor_id',
        'comentario_em',
        'comentario_resolvido',
        'comentario_resolvido_por',
        'comentario_resolvido_em',
    ];

    protected $casts = [
        'data'                 => 'date',
        'prazo'                => 'date',
        'vendido'              => 'boolean',
        'vendas_qty'           => 'integer',
        'revisado_em'          => 'datetime',
        'pendencias_abertas'   => 'integer',
        'desconsiderado'       => 'boolean',
        'desconsiderado_em'    => 'datetime',
        'revisado'             => 'boolean',
        'problema'                 => 'boolean',
        'problema_em'              => 'datetime',
        'problema_resolvido_em'    => 'datetime',
        'comentario_em'            => 'datetime',
        'comentario_resolvido'     => 'boolean',
        'comentario_resolvido_em'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function comentarioAutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comentario_autor_id')->withTrashed();
    }

    /** Quem marcou o problema como resolvido (publicador, líder ou gestor). */
    public function problemaResolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'problema_resolvido_por')->withTrashed();
    }

    /** Quem marcou o comentário como resolvido. */
    public function comentarioResolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comentario_resolvido_por')->withTrashed();
    }

    /** Quem deu o último veredicto de revisão. */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por')->withTrashed();
    }

    /** Quem tirou o anúncio da apuração por estar fora da metodologia. */
    public function desconsideradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desconsiderado_por')->withTrashed();
    }

    /**
     * Histórico completo de revisões — alimenta a aba Supervisão.
     * Ordena por id, não por created_at: várias transições podem cair no mesmo
     * segundo e a trilha sairia embaralhada.
     */
    public function revisoes(): HasMany
    {
        return $this->hasMany(Revisao::class, 'publicacao_id')->latest('id');
    }

    public function pendencias(): HasMany
    {
        return $this->hasMany(Pendencia::class, 'publicacao_id')->orderByDesc('aberta_em');
    }

    /** Só o que ainda não foi resolvido (aberta ou corrigida). */
    public function pendenciasPendentes(): HasMany
    {
        return $this->pendencias()->pendente();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Scopes de fila
    // ═══════════════════════════════════════════════════════════════════

    /** O que espera ação do líder: nunca revisado ou corrigido aguardando reconferência. */
    public function scopeNaFilaDoLider(Builder $q): Builder
    {
        return $q->whereIn('status_revisao', Revisao::NA_FILA_DO_LIDER);
    }

    public function scopeComStatusRevisao(Builder $q, string $status): Builder
    {
        return $q->where('status_revisao', $status);
    }

    /**
     * O que entra em apuração — vendas, meta, conversão, faturamento e score.
     *
     * Anúncio fora da metodologia continua no banco e nas telas de registro
     * (Publicações, Histórico, Revisão), mas some de qualquer número que meça
     * o trabalho do time. Toda query que CONTA precisa deste scope; toda query
     * que LISTA, não.
     */
    public function scopeConsiderado(Builder $q): Builder
    {
        return $q->where('desconsiderado', false);
    }

    /**
     * Só publicações de quem de fato publica (publicador/líder), pelo cargo REAL
     * no pivot `user_setores.cargo_id`.
     *
     * CUIDADO: `whereHas('user.setores', fn($q) => $q->whereHas('cargos', ...))`
     * consulta o CATÁLOGO de cargos do setor (Setor::cargos() é hasMany e sempre
     * contém publicador/líder/gestor), então a condição era verdadeira para
     * QUALQUER membro do setor Publicação — o filtro não filtrava nada.
     */
    public function scopeDePublicadores(Builder $q): Builder
    {
        return $q->whereExists(function ($sub) {
            $sub->from('user_setores')
                ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
                ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
                ->whereColumn('user_setores.user_id', 'mlb_publicacoes.user_id')
                ->where('setores.slug', 'publicacao')
                ->whereIn('cargos.slug', ['publicador', 'lider-de-publicacao']);
        });
    }

    /** Filtro por competência (YYYY-MM) sem quebrar o índice de `data`. */
    public function scopeDoMes(Builder $q, string $mesRef): Builder
    {
        [$ano, $mes] = array_pad(explode('-', $mesRef), 2, null);
        if (!$ano || !$mes) return $q;

        $inicio = sprintf('%04d-%02d-01', (int) $ano, (int) $mes);
        $fim    = date('Y-m-t', strtotime($inicio));

        return $q->whereBetween('data', [$inicio, $fim]);
    }
}
