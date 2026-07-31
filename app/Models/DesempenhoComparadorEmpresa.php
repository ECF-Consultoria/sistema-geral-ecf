<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha insert-only da comparação nota antiga × nota nova, por (run,
 * profissional, empresa, competência) — Fase 121 (comando do Plano 03).
 * Detalhe por empresa do que `DesempenhoComparadorProfissional` agrega. Nunca
 * UPDATE, nunca DELETE fora de cascata de FK; conferência oficial é sempre
 * por reconsulta ao banco, nunca por stdout. Ver migration
 * `create_desempenho_comparador_tables`.
 *
 * Sem `LogsActivity`: tabela de auditoria gerada por comando, não dado
 * editado por humano — mesmo raciocínio das tabelas do probe.
 */
class DesempenhoComparadorEmpresa extends Model
{
    protected $table = 'desempenho_comparador_empresas';

    protected $fillable = [
        'run_id',
        'user_id',
        'company_id',
        'periodo_key',
        'competencia_alvo',
        'gerado_em',
        'fonte_financeira',
        'status',
        'nps_pontos',
        'faturamento_var_pct',
        'faturamento_pontos',
        'margem_var_pp',
        'margem_diff_pct',
        'margem_pontos',
        'nota_empresa',
        'nota_empresa_parcial',
        'quality_motivos',
    ];

    protected $casts = [
        'competencia_alvo'     => 'boolean',
        'gerado_em'            => 'datetime',
        'nps_pontos'           => 'float',
        'faturamento_var_pct'  => 'float',
        'faturamento_pontos'   => 'float',
        'margem_var_pp'        => 'float',
        'margem_diff_pct'      => 'float',
        'margem_pontos'        => 'float',
        'nota_empresa'         => 'float',
        'nota_empresa_parcial' => 'float',
        'quality_motivos'      => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
