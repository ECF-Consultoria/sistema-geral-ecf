<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do diário de uma demanda dev ("status no fim do dia").
 *
 * Só cresce: não há edição nem exclusão pela aplicação — é o histórico que dá
 * valor ao registro. Correção = nova atualização.
 */
class DevDemandaAtualizacao extends Model
{
    protected $table = 'dev_demanda_atualizacoes';

    protected $fillable = [
        'dev_demanda_id', 'user_id', 'autor_nome', 'data', 'status', 'feito',
        'proxima_acao', 'bloqueado', 'motivo_bloqueio', 'previsao_revisada',
    ];

    protected $casts = [
        'data'              => 'date',
        'bloqueado'         => 'boolean',
        'previsao_revisada' => 'date',
    ];

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(DevDemanda::class, 'dev_demanda_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Nome exibido: o usuário, ou o nome gravado na importação quando não há usuário. */
    public function nomeDoAutor(): ?string
    {
        return $this->autor?->name ?? $this->autor_nome;
    }
}
