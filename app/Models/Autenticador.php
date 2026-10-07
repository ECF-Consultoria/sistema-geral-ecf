<?php

namespace App\Models;

use App\Casts\TotpSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Autenticador 2FA de uma conta operada pela ECF (cofre interno de códigos TOTP).
 *
 * O `secret` é cifrado em repouso (cast TotpSecret, chave dedicada) e está em
 * $hidden junto com `secret_hash`: nunca vão para o payload do Inertia nem para
 * o activity log. O código de 6 dígitos é gerado sob demanda no servidor
 * (TotpService) — o secret não sai do backend.
 */
class Autenticador extends Model
{
    use LogsActivity;

    protected $table = 'autenticadores';

    protected $fillable = [
        'cliente',
        'conta',
        'servico',
        'issuer',
        'secret',
        'secret_hash',
        'algoritmo',
        'digitos',
        'periodo',
        'status',
        'criado_por',
    ];

    protected $casts = [
        'secret'  => TotpSecret::class,
        'digitos' => 'integer',
        'periodo' => 'integer',
    ];

    // Blindagem: nem o secret nem o hash são serializados para o frontend.
    protected $hidden = [
        'secret',
        'secret_hash',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // IMPORTANTE: 'secret'/'secret_hash' NUNCA entram no log.
        return LogOptions::defaults()
            ->logOnly(['cliente', 'conta', 'servico', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Autenticador cadastrado',
                'updated' => 'Autenticador atualizado',
                'deleted' => 'Autenticador removido',
                default   => $event,
            });
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
