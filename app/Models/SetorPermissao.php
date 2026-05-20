<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission key habilitada num setor.
 * Cada linha = 1 permission_key (do catálogo App\Support\Permissions) liberada
 * pra membros do setor. Sem hierarquia, sem grupos — checagem é flat e rápida.
 */
class SetorPermissao extends Model
{
    protected $table = 'setor_permissoes';

    protected $fillable = ['setor_id', 'permission_key'];

    public function setor(): BelongsTo
    {
        return $this->belongsTo(Setor::class);
    }
}
