<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContratoAssinaturaEvento — Fase 129 (DADOS-03). Grava TODO corpo de
 * webhook que chega da Clicksign, bruto, mesmo quando a assinatura não
 * confere. É o instrumento de medição do gate A1 (129-CONTEXT.md) e a
 * evidência que `clicksign:verificar-assinatura` (D-09) e o receiver de
 * produção (plano 129-02/03) vão consultar depois.
 *
 * `payload_hash` UNIQUE é a idempotência de verdade (CLICK-04): duas
 * requisições HTTP concorrentes (retry real da Clicksign) passam juntas
 * por qualquer verificação `exists()`; só a constraint única do banco
 * sobrevive à corrida — ver migration.
 *
 * `payload` é JSON GENÉRICO — nenhum campo do corpo real do webhook foi
 * promovido a coluna própria, porque a forma real nunca foi medida contra
 * este projeto (Pitfall C do 129-RESEARCH.md).
 *
 * ⚠️ Este model NÃO usa `LogsActivity` (spatie), de propósito — mesma
 * justificativa de `ContratoAssinaturaSignatario` (T-125-10): o
 * `payload`/`raw_body` carrega nome e e-mail de signatário (PII). Duplicar
 * isso no `activity_log` criaria uma segunda cópia desses dados, com
 * retenção própria e sem necessidade.
 */
class ContratoAssinaturaEvento extends Model
{
    use HasFactory;

    protected $table = 'contrato_assinatura_eventos';

    /** Truncamento defensivo do raw body ao gravar (mesmo valor de HubspotWebhookController). */
    public const RAW_BODY_MAX_BYTES = 65_000;

    // $fillable explícito — nunca $guarded = [] (mass assignment).
    protected $fillable = [
        'contrato_assinatura_id',
        'clicksign_envelope_id',
        'name',
        'signature_valid',
        'payload',
        'payload_hash',
        'raw_body',
        'raw_truncado',
        'origem',
        'status',
        'erro_msg',
        'ip_address',
        'processado_em',
    ];

    protected $casts = [
        'payload'         => 'array',
        'signature_valid' => 'boolean',
        'raw_truncado'    => 'boolean',
        'processado_em'   => 'datetime',
    ];

    public const STATUS_RECEBIDO  = 'recebido';
    public const STATUS_PROCESSADO = 'processado';
    public const STATUS_IGNORADO  = 'ignorado';
    public const STATUS_ERRO      = 'erro';

    /** Os 4 status possíveis, STRING + constantes — nunca `enum` de banco. */
    public const STATUS_TODOS = [
        self::STATUS_RECEBIDO,
        self::STATUS_PROCESSADO,
        self::STATUS_IGNORADO,
        self::STATUS_ERRO,
    ];

    public const ORIGEM_WEBHOOK = 'webhook';
    public const ORIGEM_SONDA   = 'sonda';

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoAssinatura::class, 'contrato_assinatura_id');
    }
}
