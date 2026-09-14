<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O retrato de faturamento da conta do Mercado Livre, num instante.
 *
 * Histórico de propósito: a janela de 90 dias anda, e o retrato tirado na
 * entrada da empresa é o "antes da ECF" que nenhuma janela futura alcança.
 * Ver a decisão de schema na migration.
 */
class OnboardingFotografiaConta extends Model
{
    protected $table = 'onboarding_fotografias_conta';

    protected $fillable = [
        'company_id',
        'coletado_por',
        'coletado_em',
        'corte_em',
        'serie',
        'janelas',
        'erro',
    ];

    protected $casts = [
        'coletado_em' => 'datetime',
        'corte_em'    => 'date',
        'serie'       => 'array',
        'janelas'     => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function coletadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coletado_por');
    }

    /** A coleta que vale para a tela: a mais recente desta empresa. */
    public static function maisRecenteDe(int $companyId): ?self
    {
        return static::where('company_id', $companyId)
            ->orderByDesc('coletado_em')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A PRIMEIRA coleta — a linha de base, tirada quando a empresa entrou.
     *
     * É contra ela que o "depois" se compara quando a janela de 90 dias já não
     * alcança mais o período anterior à ECF.
     */
    public static function linhaDeBaseDe(int $companyId): ?self
    {
        return static::where('company_id', $companyId)
            ->whereNull('erro')
            ->orderBy('coletado_em')
            ->orderBy('id')
            ->first();
    }
}
