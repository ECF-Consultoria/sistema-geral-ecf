<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendência de revisão de um anúncio.
 *
 * Unifica o que antes eram duas coisas com o mesmo comportamento: "problema"
 * (vermelho, trava) e "comentário" (azul, orientação). Os dois sempre tiveram
 * texto, autor, data de abertura, estado resolvido, resolvedor e data — a
 * única diferença real era severidade, que agora é um campo.
 */
class Pendencia extends Model
{
    protected $table = 'mlb_pendencias';

    // ─── Severidade ───
    public const SEV_BLOQUEIO   = 'bloqueio';   // trava o anúncio (era "problema")
    public const SEV_AJUSTE     = 'ajuste';     // precisa corrigir
    public const SEV_OBSERVACAO = 'observacao'; // orientação (era "comentário")

    public const SEVERIDADES = [
        self::SEV_BLOQUEIO   => 'Bloqueio',
        self::SEV_AJUSTE     => 'Ajuste',
        self::SEV_OBSERVACAO => 'Observação',
    ];

    // ─── Ciclo de vida ───
    public const ST_ABERTA    = 'aberta';    // bola com o publicador
    public const ST_CORRIGIDA = 'corrigida'; // publicador agiu, aguarda o líder
    public const ST_RESOLVIDA = 'resolvida'; // líder confirmou

    /**
     * Categorias — permitem ao gestor ver ONDE o time erra mais.
     * Antes isso ficava enterrado em texto livre.
     */
    public const CATEGORIAS = [
        'foto'      => 'Foto',
        'titulo'    => 'Título',
        'ficha'     => 'Ficha técnica',
        'descricao' => 'Descrição',
        'preco'     => 'Preço',
        'frete'     => 'Frete / envio',
        'variacao'  => 'Variação',
        'categoria' => 'Categoria errada',
        'outro'     => 'Outro',
    ];

    protected $fillable = [
        'publicacao_id',
        'revisao_id',
        'severidade',
        'categoria',
        'texto',
        'aberta_por',
        'aberta_em',
        'status',
        'corrigida_por',
        'corrigida_em',
        'resolvida_por',
        'resolvida_em',
    ];

    protected $casts = [
        'aberta_em'    => 'datetime',
        'corrigida_em' => 'datetime',
        'resolvida_em' => 'datetime',
    ];

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(Publicacao::class, 'publicacao_id');
    }

    public function revisao(): BelongsTo
    {
        return $this->belongsTo(Revisao::class, 'revisao_id');
    }

    public function abertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberta_por')->withTrashed();
    }

    public function corrigidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrigida_por')->withTrashed();
    }

    public function resolvidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por')->withTrashed();
    }

    /** Ainda não resolvida — conta como pendente para o publicador ou para o líder. */
    public function scopePendente(Builder $q): Builder
    {
        return $q->whereIn('status', [self::ST_ABERTA, self::ST_CORRIGIDA]);
    }

    /** Aguardando ação do publicador. */
    public function scopeAberta(Builder $q): Builder
    {
        return $q->where('status', self::ST_ABERTA);
    }

    /** Corrigida pelo publicador, esperando o líder reconferir. */
    public function scopeAguardandoReconferencia(Builder $q): Builder
    {
        return $q->where('status', self::ST_CORRIGIDA);
    }

    public function scopeBloqueio(Builder $q): Builder
    {
        return $q->where('severidade', self::SEV_BLOQUEIO);
    }

    /**
     * Dias corridos desde a abertura — usado no aging da Supervisão.
     * diffInDays devolve float com sinal; truncar direto emitiria deprecation
     * a cada linha da listagem.
     */
    public function getIdadeDiasAttribute(): ?int
    {
        if (!$this->aberta_em) return null;

        return (int) abs($this->aberta_em->diffInDays(now()));
    }
}
