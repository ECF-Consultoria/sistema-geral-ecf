<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma pendência encaminhada ao Analista de Conta dos Polos (TKT-0035, DEV-58): o analista de Ads
 * pede, pela fila de revisão, algo que se resolve na conta do seller.
 *
 * Trabalho em paralelo à carteira: nada aqui muda o responsável da empresa nem a revisão
 * quinzenal. Decisões de schema no docblock da migration
 * 2026_11_11_100000_create_polos_pendencias_conta_tables.
 */
class PolosPendenciaConta extends Model
{
    protected $table = 'polos_pendencias_conta';

    public const TIPOS = [
        'publicacao'       => 'Publicação',
        'central_promocao' => 'Central de Promoção',
        'ajuste'           => 'Ajuste de produto/anúncio',
        'outro'            => 'Outro',
    ];

    public const PRIORIDADES = [
        'urgente' => 'Urgente',
        'alta'    => 'Alta',
        'media'   => 'Média',
        'baixa'   => 'Baixa',
    ];

    /** Peso na ordenação da fila: menor = mais urgente. */
    public const PESO_PRIORIDADE = ['urgente' => 0, 'alta' => 1, 'media' => 2, 'baixa' => 3];

    public const STATUS_ABERTA            = 'aberta';
    public const STATUS_EM_ANDAMENTO      = 'em_andamento';
    public const STATUS_AGUARDANDO_SELLER = 'aguardando_seller';
    public const STATUS_CONCLUIDA         = 'concluida';
    public const STATUS_DEVOLVIDA         = 'devolvida';
    public const STATUS_CANCELADA         = 'cancelada';

    public const STATUS = [
        self::STATUS_ABERTA            => 'Aberta',
        self::STATUS_EM_ANDAMENTO      => 'Em andamento',
        self::STATUS_AGUARDANDO_SELLER => 'Aguardando seller ou informação',
        self::STATUS_DEVOLVIDA         => 'Devolvida',
        self::STATUS_CONCLUIDA         => 'Concluída',
        self::STATUS_CANCELADA         => 'Cancelada',
    ];

    /** Encerrada = saiu da fila. A empresa só sai da fila quando TODAS as dela estão aqui. */
    public const ENCERRADAS = [self::STATUS_CONCLUIDA, self::STATUS_CANCELADA];

    protected $fillable = [
        'mlb_empresa_id', 'tipo', 'descricao', 'mlbs', 'prioridade', 'prazo', 'data_planejada', 'status',
        'solicitante_id', 'analista_id', 'conclusao', 'mlbs_alterados', 'concluida_em',
    ];

    protected $casts = [
        'mlbs'           => 'array',
        'mlbs_alterados' => 'array',
        'prazo'          => 'date',
        'data_planejada' => 'date',
        'concluida_em'   => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function analista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analista_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(PolosPendenciaContaEvento::class, 'pendencia_id')->orderBy('id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(PolosPendenciaContaAnexo::class, 'pendencia_id')->orderBy('id');
    }

    /** As que ainda estão na fila do Analista de Conta. */
    public function scopeAbertas(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::ENCERRADAS);
    }

    public function encerrada(): bool
    {
        return in_array($this->status, self::ENCERRADAS, true);
    }

    /** Prazo pedido já passou e a pendência segue na fila. */
    public function atrasada(CarbonInterface $hoje): bool
    {
        return ! $this->encerrada() && $this->prazo !== null && $this->prazo->toDateString() < $hoje->toDateString();
    }
}
