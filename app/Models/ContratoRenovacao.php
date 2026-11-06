<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 202 (REN-02) — ciclo de renovação de um contrato (empresa × serviço dono).
 *
 * O ciclo é identificado pela chave (`chave_ciclo` / empresa+serviço+número),
 * NÃO pela data de término: corrigir o término de um contrato não cria ciclo
 * novo (plano-fonte §4.2). O negócio original (Ganho) nunca é alterado; o
 * negócio de renovação é um deal novo, guardado em `negocio_renovacao_id`.
 */
class ContratoRenovacao extends Model
{
    protected $table = 'contrato_renovacoes';

    public const STATUS_COMERCIAL_IDENTIFICADO  = 'identificado';
    public const STATUS_COMERCIAL_EM_NEGOCIACAO = 'em_negociacao';
    public const STATUS_COMERCIAL_RENOVADO      = 'renovado';
    public const STATUS_COMERCIAL_NAO_RENOVADO  = 'nao_renovado';
    public const STATUS_COMERCIAL_DISPENSADO    = 'dispensado';

    public const STATUS_COMERCIAIS_FINAIS = [
        self::STATUS_COMERCIAL_RENOVADO,
        self::STATUS_COMERCIAL_NAO_RENOVADO,
        self::STATUS_COMERCIAL_DISPENSADO,
    ];

    public const STATUS_INTEGRACAO_PENDENTE          = 'pendente_sincronizacao';
    public const STATUS_INTEGRACAO_CRIANDO           = 'criando';
    public const STATUS_INTEGRACAO_SINCRONIZADO      = 'sincronizado';
    public const STATUS_INTEGRACAO_ERRO              = 'erro';
    public const STATUS_INTEGRACAO_REVISAO_MANUAL    = 'revisao_manual';
    public const STATUS_INTEGRACAO_RESULTADO_INCERTO = 'resultado_incerto';

    protected $fillable = [
        'company_id', 'servico_id', 'numero_ciclo', 'ciclo_anterior_id',
        'chave_ciclo', 'chave_correlacao',
        'termino_capturado', 'termino_novo', 'inicio_vigencia', 'vigencia_meses',
        'status_comercial', 'status_integracao',
        'negocio_original_id', 'negocio_renovacao_id', 'hubspot_company_id',
        'pipeline_observado', 'etapa_observada',
        'tentativas', 'ultimo_erro', 'ultima_decisao', 'ultima_decisao_simulada',
        'detectado_em', 'ultima_sincronizacao_em', 'criacao_iniciada_em', 'renovado_em',
        'dispensado_por', 'dispensado_em', 'dispensa_justificativa',
    ];

    protected $casts = [
        'numero_ciclo'            => 'integer',
        'vigencia_meses'          => 'integer',
        'tentativas'              => 'integer',
        'termino_capturado'       => 'date:Y-m-d',
        'termino_novo'            => 'date:Y-m-d',
        'inicio_vigencia'         => 'date:Y-m-d',
        'ultima_decisao_simulada' => 'boolean',
        'detectado_em'            => 'datetime',
        'ultima_sincronizacao_em' => 'datetime',
        'criacao_iniciada_em'     => 'datetime',
        'renovado_em'             => 'datetime',
        'dispensado_em'           => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function cicloAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ciclo_anterior_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ContratoRenovacaoEvento::class, 'contrato_renovacao_id')->orderByDesc('id');
    }

    public function dispensadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensado_por');
    }

    public function registrarEvento(
        string $tipo,
        string $origem,
        ?string $decisao = null,
        array $dados = [],
        bool $simulacao = false,
        ?int $userId = null,
    ): ContratoRenovacaoEvento {
        return ContratoRenovacaoEvento::create([
            'contrato_renovacao_id' => $this->id,
            'tipo'                  => $tipo,
            'origem'                => $origem,
            'decisao'               => $decisao,
            'simulacao'             => $simulacao,
            'user_id'               => $userId,
            'dados'                 => $dados === [] ? null : $dados,
        ]);
    }
}
