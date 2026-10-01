<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mensagem de um chamado. `publica` o solicitante vê; `interna` é só da equipe —
 * o filtro é feito no servidor (ChamadoService::serializar), nunca na tela.
 */
class ChamadoMensagem extends Model
{
    protected $table = 'chamado_mensagens';

    protected $fillable = ['chamado_id', 'autor_id', 'visibilidade', 'texto'];

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class, 'chamado_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(ChamadoAnexo::class, 'mensagem_id');
    }

    public function ehInterna(): bool
    {
        return $this->visibilidade === Chamado::VISIBILIDADE_INTERNA;
    }
}
