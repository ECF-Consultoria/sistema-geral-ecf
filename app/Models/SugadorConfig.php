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
        'gasto_minimo_logic',
        'cpc_maximo',
        'cpc_maximo_logic',
        'cpc_minimo_cliques',
        'acos_maximo_pct',
        'acos_maximo_logic',
        'cliques_minimos_sem_venda',
        'cliques_minimos_logic',
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
        'cpc_minimo_cliques'              => 'integer',
        'acos_maximo_pct'                 => 'decimal:2',
        'cliques_minimos_sem_venda'       => 'integer',
        'pct_anuncios_para_flag_campanha' => 'integer',
        'incluir_campanhas'               => 'boolean',
        'incluir_anuncios'                => 'boolean',
        'ativo'                           => 'boolean',
    ];

    /** Modos do operador por critério. Ver SugadorAnalysisService::evaluateMetrics. */
    public const LOGIC_REQUIRED = 'required'; // AND — TEM que atender pra item ser flagado
    public const LOGIC_OPTIONAL = 'optional'; // OR  — atender já contribui, mas não obriga

    /** Defaults usados quando uma empresa ainda não tem config criada. */
    public const DEFAULTS = [
        'dias_analise'                    => 30,
        'gasto_minimo_sem_venda'          => 20.00,
        'gasto_minimo_logic'              => self::LOGIC_OPTIONAL,
        'cpc_maximo'                      => null,
        'cpc_maximo_logic'                => self::LOGIC_OPTIONAL,
        'cpc_minimo_cliques'              => null,
        'acos_maximo_pct'                 => null,
        'acos_maximo_logic'               => self::LOGIC_OPTIONAL,
        'cliques_minimos_sem_venda'       => null,
        'cliques_minimos_logic'           => self::LOGIC_OPTIONAL,
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
