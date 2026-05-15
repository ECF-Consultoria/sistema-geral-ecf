<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SugadorConfig extends Model
{
    protected $table = 'sugador_configs';

    protected $fillable = [
        'company_id',
        'dias_analise',
        'gasto_minimo_sem_venda',
        'cpc_maximo',
        'acos_maximo_pct',
        'cliques_minimos_sem_venda',
        'pct_anuncios_para_flag_campanha',
        'incluir_campanhas',
        'incluir_anuncios',
        'ativo',
        'updated_by',
    ];

    protected $casts = [
        'dias_analise'                    => 'integer',
        'gasto_minimo_sem_venda'          => 'decimal:2',
        'cpc_maximo'                      => 'decimal:2',
        'acos_maximo_pct'                 => 'decimal:2',
        'cliques_minimos_sem_venda'       => 'integer',
        'pct_anuncios_para_flag_campanha' => 'integer',
        'incluir_campanhas'               => 'boolean',
        'incluir_anuncios'                => 'boolean',
        'ativo'                           => 'boolean',
    ];

    /** Defaults usados quando uma empresa ainda não tem config criada. */
    public const DEFAULTS = [
        'dias_analise'                    => 30,
        'gasto_minimo_sem_venda'          => 20.00,
        'cpc_maximo'                      => null,
        'acos_maximo_pct'                 => null,
        'cliques_minimos_sem_venda'       => null,
        'pct_anuncios_para_flag_campanha' => 50,
        'incluir_campanhas'               => false,
        'incluir_anuncios'                => true,
        'ativo'                           => true,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Retorna a config da empresa, criando uma com defaults se não existir.
     */
    public static function forCompany(Company $company): self
    {
        return self::firstOrCreate(
            ['company_id' => $company->id],
            self::DEFAULTS
        );
    }
}
