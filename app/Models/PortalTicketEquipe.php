<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passagem de uso único do sistema interno para o portal de um cliente.
 *
 * Ver o docblock da migration para o porquê de cada limite.
 */
class PortalTicketEquipe extends Model
{
    protected $table = 'portal_tickets_equipe';

    protected $fillable = ['user_id', 'company_id', 'token_hash', 'expira_em', 'usado_em', 'ip'];

    protected $casts = [
        'expira_em' => 'datetime',
        'usado_em'  => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
