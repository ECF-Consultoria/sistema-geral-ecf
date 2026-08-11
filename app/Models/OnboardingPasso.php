<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingPasso — instância de um TemplatePasso dentro de um Onboarding.
 *
 * D-11 — o estado tem SEIS valores, nunca dois. `aguardando_coleta` e
 * `indeterminado` existem para que "tabela vazia" nunca seja lida como "zero
 * real" — mesma armadilha que já custou caro no Shopee (conta nova fica vazia
 * até o backfill; o cron só cobre quem já existe). Um resolver automático que
 * devolva um `bool` está errado por construção: ele precisa distinguir
 * "concluído" de "ainda não coletado" de "429/timeout, indeterminado — não
 * conclua, retente depois".
 *
 * `chave` é denormalizada de `template_passos.chave` (D-10) — sobrevive à
 * troca de versão do template e é a chave de agregação usada pelo link único
 * por empresa (D-06).
 */
class OnboardingPasso extends Model
{
    protected $table = 'onboarding_passos';

    protected $fillable = [
        'onboarding_id',
        'template_passo_id',
        'chave',
        'status',
        'valor',
        'feito_por',
        'feito_em',
        'auto_em',
        'disponivel_em',
        'coleta_iniciada_em',
        'tentativas',
        'ultimo_erro',
    ];

    protected $casts = [
        'valor'              => 'array',
        'feito_em'           => 'datetime',
        'auto_em'            => 'datetime',
        'disponivel_em'      => 'datetime',
        'coleta_iniciada_em' => 'datetime',
    ];

    // ─── Catálogo fechado de `status` (D-11 — seis estados) ──────────────────
    /** Depende de passo não concluído — ainda não pode ser trabalhado. */
    public const STATUS_BLOQUEADO = 'bloqueado';
    /** Acionável, esperando o dono agir (ou o resolver automático rodar). */
    public const STATUS_ABERTO = 'aberto';
    /** O resolver disparou uma coleta assíncrona (ex.: mlb:sync-acervo). */
    public const STATUS_AGUARDANDO_COLETA = 'aguardando_coleta';
    /** A sonda devolveu erro sem resposta definitiva (429/timeout) — retenta. */
    public const STATUS_INDETERMINADO = 'indeterminado';
    public const STATUS_CONCLUIDO = 'concluido';
    /** A `condicao` do template_passo foi avaliada como falsa (D-12). */
    public const STATUS_NAO_APLICAVEL = 'nao_aplicavel';

    public const STATUSES = [
        self::STATUS_BLOQUEADO,
        self::STATUS_ABERTO,
        self::STATUS_AGUARDANDO_COLETA,
        self::STATUS_INDETERMINADO,
        self::STATUS_CONCLUIDO,
        self::STATUS_NAO_APLICAVEL,
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function templatePasso(): BelongsTo
    {
        return $this->belongsTo(TemplatePasso::class, 'template_passo_id');
    }

    public function feitoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feito_por');
    }

    /**
     * Scope: passos que o comando de reavaliação periódica (Plano 12) precisa
     * revisitar — coleta em curso ou sonda indeterminada.
     */
    public function scopePendentesDeReavaliacao($query)
    {
        return $query->whereIn('status', [
            self::STATUS_AGUARDANDO_COLETA,
            self::STATUS_INDETERMINADO,
        ]);
    }
}
