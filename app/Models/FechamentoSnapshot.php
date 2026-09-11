<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Congelamento do fechamento mensal POR EMPRESA (Fase 137, D-11) — 1 row
 * por `(company_id, mes_referencia)`. Mesmo molde de
 * `DesempenhoCompanyScoreSnapshot`: só dado, sem lógica de cálculo. Quem
 * calcula e grava é o writer do plano 05
 * (`App\Services\Fechamento\FechamentoSnapshotWriter`, ainda não criado
 * nesta fase) — nunca um controller direto, nunca `updateOrCreate` fora
 * dele.
 *
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-02-PLAN.md
 */
class FechamentoSnapshot extends Model
{
    /**
     * Origem oficial de consolidação mensal — única que sobrescreve
     * competência já congelada (mesma trava D-122-02 do módulo de
     * Desempenho).
     */
    public const ORIGEM_CONSOLIDAR_MES = 'consolidar_mes';

    public const ESTADO_OK = 'ok';

    public const ESTADO_SEM_FATURAMENTO = 'sem_faturamento';

    public const ESTADO_SEM_TABELA = 'sem_tabela';

    public const ESTADO_SEM_INTEGRACAO = 'sem_integracao';

    /**
     * Fase 141 (D-03) — empresa SEM tabela progressiva cadastrada (nem
     * própria, nem herdada de grupo/serviço): cobra o valor fixo do
     * contrato (`CobrancaCalculator::mensalidade()` com `$classificacao =
     * null`). É resultado NORMAL desse tipo de empresa — o caso de
     * Mentoria — e não pendência a resolver, diferente de
     * `ESTADO_SEM_TABELA` (que hoje significa "tinha serviço candidato mas
     * a tabela dele veio vazia"). A coluna `estado` é `string(20)` nas
     * duas tabelas de snapshot; 'valor_fixo' tem 10 caracteres, cabe sem
     * migration.
     */
    public const ESTADO_VALOR_FIXO = 'valor_fixo';

    /**
     * Quick 260911-eph — de onde veio o faturamento congelado nesta linha
     * (coluna `faturamento_fonte`, `string(32)` nullable).
     *
     * `FONTE_API`: o `/performance` da Adman (grossBilling do intervalo) —
     * o mesmo número que a dashboard da Adman mostra, já com os ajustes
     * retroativos que nunca voltam para `adman_metrics`.
     *
     * `FONTE_SOMA_DIARIA`: SUM(adman_metrics.revenue) dos dias do mês — o
     * comportamento de sempre. É o valor de empresas ML-driven (token ML
     * ativo, conta Adman abandonada — o `/performance` delas NÃO é régua),
     * de empresas sem `cust_id`, e de qualquer competência consolidada com
     * a fonte nova desligada.
     *
     * `FONTE_SOMA_DIARIA_FALLBACK`: a API foi tentada e não respondeu —
     * o número é o da soma diária, mas sabendo-se que o certo era outro.
     * Existe separado de `FONTE_SOMA_DIARIA` justamente porque o fallback
     * continua produzindo número: sem essa distinção, um mês inteiro em
     * fallback teria exatamente a cara de um mês normal.
     *
     * `null` (linhas anteriores a esta coluna) significa "não sei" — nunca
     * "foi soma diária".
     */
    public const FONTE_API = 'api';

    public const FONTE_SOMA_DIARIA = 'soma_diaria';

    public const FONTE_SOMA_DIARIA_FALLBACK = 'soma_diaria_fallback';

    protected $fillable = [
        'company_id',
        'mes_referencia',
        'company_name',
        'faturamento_ml',
        'faturamento_shopee',
        'faturamento_total',
        'faturamento_fonte',
        'company_group_id',
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
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }
}
