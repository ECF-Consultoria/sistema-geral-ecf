<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingMapeamento — o complemento HUMANO do Mapeamento Inicial: o campo
 * que a API não entrega e o registro de quem conferiu o apurado.
 *
 * O apurado em si (faturamento, marketplace, Full, reputação, medalhas,
 * acervo) NÃO vive aqui — ele fica em `onboarding_passos.valor`, com uma
 * fonte só. Ver o docblock da migration.
 */
class OnboardingMapeamento extends Model
{
    protected $table = 'onboarding_mapeamentos';

    protected $fillable = [
        'onboarding_id',
        'full_pontuacao',
        'confirmado_em',
        'confirmado_por',
        'confirmado_canal',
        'observacoes',
    ];

    protected $casts = [
        'confirmado_em'  => 'datetime',
        'full_pontuacao' => 'integer',
    ];

    // ─── Catálogo fechado de `confirmado_canal` ─────────────────────────────
    /** O próprio cliente conferiu, sozinho, pelo portal público. */
    public const CANAL_CLIENTE_PORTAL = 'cliente_portal';
    /** Alguém da equipe conferiu junto com o cliente, numa call. */
    public const CANAL_INTERNO_CALL = 'interno_call';

    public const CANAIS = [
        self::CANAL_CLIENTE_PORTAL,
        self::CANAL_INTERNO_CALL,
    ];

    public const CANAL_LABELS = [
        self::CANAL_CLIENTE_PORTAL => 'confirmado pelo cliente no portal',
        self::CANAL_INTERNO_CALL   => 'confirmado pela equipe em call',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function confirmado(): bool
    {
        return $this->confirmado_em !== null;
    }
}
