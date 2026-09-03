<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Congelamento do fechamento mensal POR GRUPO (Fase 137, D-08 + D-10 +
 * D-11) — 1 row por `(company_group_id, mes_referencia)`. Mesmo molde de
 * `FechamentoSnapshot`, granularidade de grupo do Comercial em vez de
 * empresa. Sem lógica de cálculo — quem calcula é o writer do plano 05.
 *
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-02-PLAN.md
 */
class FechamentoGrupoSnapshot extends Model
{
    public const ORIGEM_CONSOLIDAR_MES = 'consolidar_mes';

    protected $fillable = [
        'company_group_id',
        'mes_referencia',
        'grupo_name',
        'faturamento_ml',
        'faturamento_shopee',
        'faturamento_total',
        'servico_id',
        'tabela_origem',
        'faixa_ordem',
        'faixa_aplicada',
        'valor_faixa',
        'valor_faixa_e_piso',
        'faixa_limite_inferior',
        'faixa_limite_superior',
        'cobranca_mensal',
        'evolucao',
        'estado',
        'empresas_count',
        'empresa_ancora_id',
        'tabelas_divergentes',
        'origem',
        'gerado_em',
        // Fase 138 (D-02 + D-03) — escritas SÓ pelo notificador do plano 05,
        // nunca por `ConsolidarMesFechamento`/`FechamentoSnapshotWriter`. É
        // por NÃO estarem no `$dados` montado pelo comando que sobrevivem ao
        // `fill()` de uma reconsolidação — essa é a trava de "já avisei".
        'notificado_em',
        'notificado_faixa_ordem',
    ];

    protected $casts = [
        'mes_referencia'        => 'date',
        'gerado_em'             => 'datetime',
        'notificado_em'         => 'datetime',
        'faturamento_ml'        => 'decimal:2',
        'faturamento_shopee'    => 'decimal:2',
        'faturamento_total'     => 'decimal:2',
        'valor_faixa'           => 'decimal:2',
        'faixa_limite_inferior' => 'decimal:2',
        'faixa_limite_superior' => 'decimal:2',
        'cobranca_mensal'       => 'decimal:2',
        'faixa_ordem'           => 'int',
        'valor_faixa_e_piso'    => 'bool',
        'empresas_count'        => 'int',
        'tabelas_divergentes'   => 'bool',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    public function empresaAncora(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'empresa_ancora_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }
}
