<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ContratoAssinatura — Fase 125 (DADOS-01/DADOS-04). O continente onde o
 * processo de assinatura de cada empresa passa a viver: estado atual e as
 * datas de envio, assinatura e liberação.
 *
 * Os 7 estados possíveis (D-04/D-06), STRING + constantes públicas — nunca
 * `enum` de banco:
 *  - rascunho               — contrato gerado, ainda não enviado.
 *  - aguardando_assinaturas — enviado à Clicksign, esperando signatários
 *                              (a Clicksign colapsa "enviado" e "em
 *                              andamento" num único status `running`).
 *  - assinado                — todas as partes assinaram. D-05: `liberado`
 *                              NÃO é estado — a soltura da empresa pro
 *                              operacional é a data `liberado_em` (Fase
 *                              130); um contrato `assinado` com
 *                              `liberado_em` nulo é caso legítimo, é o que
 *                              o alerta da REDE-02 precisa enxergar.
 *  - recusado                 — algum signatário recusou. Próprio, nunca
 *                              colapsa em `cancelado`/`erro`.
 *  - expirado                 — prazo esgotado sem todas as assinaturas.
 *                              Próprio, nunca colapsa em
 *                              `cancelado`/`erro`. D-03: nada nesta fase
 *                              CALCULA expiração — falta a coluna de prazo,
 *                              que é da Fase 127.
 *  - cancelado                — cancelado manualmente (admin ou fluxo).
 *  - erro                     — falha TÉCNICA (integração Clicksign),
 *                              separada de recusa do cliente; detalhe em
 *                              `erro_mensagem`.
 */
class ContratoAssinatura extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'contrato_assinaturas';

    // $fillable explícito — nunca $guarded = [] (mass assignment, T-125-01).
    protected $fillable = [
        'company_id',
        'status',
        'company_id_em_andamento',
        'clicksign_envelope_id',
        'clicksign_document_id',
        'enviado_em',
        'assinado_em',
        'liberado_em',
        'erro_mensagem',
        'servicos_snapshot',
    ];

    protected $casts = [
        'enviado_em'        => 'datetime',
        'assinado_em'       => 'datetime',
        'liberado_em'       => 'datetime',
        // D-10 — mesmo cast de ContratoServico::$casts['hubspot_snapshot'].
        'servicos_snapshot' => 'array',
    ];

    public const STATUS_RASCUNHO               = 'rascunho';
    public const STATUS_AGUARDANDO_ASSINATURAS = 'aguardando_assinaturas';
    public const STATUS_ASSINADO               = 'assinado';
    public const STATUS_RECUSADO               = 'recusado';
    public const STATUS_EXPIRADO               = 'expirado';
    public const STATUS_CANCELADO              = 'cancelado';
    public const STATUS_ERRO                   = 'erro';

    /** Os 7 estados, na ordem da D-06. */
    public const STATUS_TODOS = [
        self::STATUS_RASCUNHO,
        self::STATUS_AGUARDANDO_ASSINATURAS,
        self::STATUS_ASSINADO,
        self::STATUS_RECUSADO,
        self::STATUS_EXPIRADO,
        self::STATUS_CANCELADO,
        self::STATUS_ERRO,
    ];

    /**
     * Estados que ocupam o slot do índice único `ca_company_andamento_uniq`
     * (D-01) — e SÓ estes. Um contrato nestes estados é "em andamento":
     * a empresa não pode ter um segundo.
     */
    public const STATUS_EM_ANDAMENTO = [
        self::STATUS_RASCUNHO,
        self::STATUS_AGUARDANDO_ASSINATURAS,
    ];

    /**
     * Sincroniza a coluna auxiliar `company_id_em_andamento` com o
     * `status` a cada save: espelha `company_id` enquanto o contrato está
     * em andamento, e zera fora disso. Isso torna impossível a coluna
     * auxiliar desincronizar do `status` — mas a garantia FINAL da D-01
     * continua sendo o índice único do banco (`ca_company_andamento_uniq`);
     * este hook é conveniência, não a trava.
     */
    protected static function booted(): void
    {
        static::saving(function (self $contrato) {
            $contrato->company_id_em_andamento = in_array($contrato->status, self::STATUS_EM_ANDAMENTO, true)
                ? $contrato->company_id
                : null;
        });
    }

    public function estaEmAndamento(): bool
    {
        return in_array($this->status, self::STATUS_EM_ANDAMENTO, true);
    }

    /**
     * Guard de código da D-01: devolve o contrato em andamento da empresa,
     * ou `null` se não houver nenhum. Quem for criar contrato (Fase 127)
     * deve chamar isto ANTES, para o usuário ver "esta empresa já tem
     * contrato em andamento" em vez de um 500 de constraint do banco.
     */
    public static function emAndamentoDaEmpresa(int $companyId): ?self
    {
        return self::where('company_id_em_andamento', $companyId)->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // ⚠️ Não incluir 'servicos_snapshot' aqui: são valores de
            // contrato, o activity_log viraria cópia paralela do
            // congelamento numa tabela com retenção própria (T-125-03).
            ->logOnly(['status', 'enviado_em', 'assinado_em', 'liberado_em'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Contrato de assinatura criado',
                'updated' => 'Contrato de assinatura atualizado',
                'deleted' => 'Contrato de assinatura excluído',
                default   => $eventName,
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
