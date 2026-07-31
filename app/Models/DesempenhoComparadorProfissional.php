<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha insert-only da comparação nota antiga × nota nova, por (run,
 * profissional, competência) — Fase 121 (comando do Plano 03). Nunca UPDATE,
 * nunca DELETE fora de cascata de FK; conferência oficial é sempre por
 * reconsulta ao banco, nunca por stdout (mesma disciplina do probe da
 * Fase 117). Ver migration `create_desempenho_comparador_tables`.
 *
 * Sem `LogsActivity`: tabela de auditoria gerada por comando, não dado
 * editado por humano — mesmo raciocínio das tabelas do probe.
 */
class DesempenhoComparadorProfissional extends Model
{
    protected $table = 'desempenho_comparador_profissionais';

    protected $fillable = [
        'run_id',
        'user_id',
        'periodo_key',
        'competencia_alvo',
        'gerado_em',
        'nota_antiga',
        'nota_nova',
        'delta',
        'status_antigo',
        'status_novo',
        'faixa_antiga_oficial',
        'faixa_antiga_inicial',
        'faixa_nova_inicial',
        'mudou_faixa',
        'empresas_total',
        'empresas_complete',
        'empresas_partial',
        'empresas_sem_fonte',
        'empresas_sem_dados',
        'decomposicao',
        'maior_causa_delta',
        'falhou',
        'erro',
    ];

    protected $casts = [
        'competencia_alvo'      => 'boolean',
        'gerado_em'             => 'datetime',
        'nota_antiga'           => 'float',
        'nota_nova'             => 'float',
        'delta'                 => 'float',
        'mudou_faixa'           => 'boolean',
        'empresas_total'        => 'integer',
        'empresas_complete'     => 'integer',
        'empresas_partial'      => 'integer',
        'empresas_sem_fonte'    => 'integer',
        'empresas_sem_dados'    => 'integer',
        'decomposicao'          => 'array',
        'falhou'                => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
