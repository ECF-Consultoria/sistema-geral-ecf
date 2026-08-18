<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingLink — um token por EMPRESA (D-06), agregando os passos
 * `dono=cliente` de TODOS os onboardings da empresa (Gestão, e no futuro
 * outros serviços — D-08) numa única URL pública. Diferente do onboarding de
 * Polos (1 token por onboarding), este token vive na empresa, não no
 * onboarding — motor novo, sem referência ao módulo de Polos (D-02).
 */
class OnboardingLink extends Model
{
    protected $table = 'onboarding_links';

    protected $fillable = [
        'company_id',
        'token',
        'ultimo_acesso',
    ];

    protected $casts = [
        'ultimo_acesso' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
