<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Sugador extends Model
{
    use LogsActivity;

    protected $table = 'sugadores';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'status', 'acao_tomada', 'observacao', 'resolvido_por', 'campanha_destino_id', 'movido_por_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Sugador detectado',
                'updated' => 'Sugador atualizado',
                'deleted' => 'Sugador excluído',
                default   => $eventName,
            });
    }


    protected $fillable = [
        'company_id',
        'reference_date',
        'tipo',
        'campaign_id',
        'campaign_name',
        'campaign_status',
        'campanha_destino_id',
        'campanha_destino_nome',
        'adgroup_id',
        'adgroup_name',
        'thumbnail',
        'adgroup_type',
        'catalog_listing',
        'mlb_id',
        'mlb_titulo',
        'periodo_inicio',
        'periodo_fim',
        'investimento_periodo',
        'faturamento_periodo',
        'vendas_periodo',
        'cliques',
        'impressoes',
        'cpc_medio',
        'ctr',
        'acos',
        'roas',
        'organic_amount',
        'organic_units',
        'motivos',
        'status',
        'acao_tomada',
        'observacao',
        'resolvido_em',
        'resolvido_por',
        'movido_em',
        'movido_por_id',
        'raw_data',
    ];

    protected $casts = [
        'reference_date'       => 'date',
        'periodo_inicio'       => 'date',
        'periodo_fim'          => 'date',
        'investimento_periodo' => 'decimal:2',
        'faturamento_periodo'  => 'decimal:2',
        'vendas_periodo'       => 'integer',
        'cliques'              => 'integer',
        'impressoes'           => 'integer',
        'cpc_medio'            => 'decimal:4',
        'ctr'                  => 'decimal:4',
        'acos'                 => 'decimal:4',
        'roas'                 => 'decimal:4',
        'catalog_listing'      => 'boolean',
        'organic_amount'       => 'decimal:2',
        'organic_units'        => 'integer',
        'motivos'              => 'array',
        'raw_data'             => 'array',
        'resolvido_em'         => 'datetime',
        'movido_em'            => 'datetime',
    ];

    public const TIPO_CAMPANHA = 'campanha';
    public const TIPO_ADGROUP  = 'adgroup';

    public const STATUS_PENDENTE       = 'pendente';
    public const STATUS_EM_ACAO        = 'em_acao';
    public const STATUS_RESOLVIDO      = 'resolvido';
    public const STATUS_IGNORADO       = 'ignorado';
    public const STATUS_MOVIDO         = 'movido';
    // Phase 15: baixa automática quando a análise diária não re-detecta o item.
    public const STATUS_AUTO_RESOLVIDO = 'auto_resolvido';

    /** Status que NÃO devem ser sobrescritos por uma re-análise (idempotência). */
    public const STATUS_TRAVADOS = [
        self::STATUS_EM_ACAO,
        self::STATUS_RESOLVIDO,
        self::STATUS_IGNORADO,
        self::STATUS_MOVIDO,
        self::STATUS_AUTO_RESOLVIDO,
    ];

    public const ACAO_PAUSADO         = 'pausado';
    public const ACAO_REMOVIDO        = 'removido';
    public const ACAO_REDUZIDO_LANCE  = 'reduzido_lance';
    public const ACAO_REATIVADO       = 'reativado';
    public const ACAO_OUTRO           = 'outro';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function movidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'movido_por_id');
    }

    public function acoes(): HasMany
    {
        return $this->hasMany(SugadorAcao::class)->orderBy('created_at', 'desc');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────
    public function scopePendentes(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDENTE);
    }

    /** Filtra pela carteira do usuário (via company_users). Admin não usa este scope. */
    public function scopeDaCarteira(Builder $q, User $user): Builder
    {
        return $q->whereIn('company_id', function ($sub) use ($user) {
            $sub->select('company_id')
                ->from('company_users')
                ->where('user_id', $user->id);
        });
    }

    public function scopeNoPeriodo(Builder $q, $from, $to): Builder
    {
        return $q->whereBetween('reference_date', [$from, $to]);
    }

    // ─── Helpers de URL ─────────────────────────────────────────────────────

    /**
     * URL do anúncio no Mercado Livre. Retorna null se não tiver mlb_id.
     * Aceita formato MLB1234567890 ou apenas 1234567890.
     */
    public function urlAnuncioML(): ?string
    {
        if (!$this->mlb_id) return null;

        $code = strtoupper(trim($this->mlb_id));
        if (!str_starts_with($code, 'MLB')) {
            $code = 'MLB' . $code;
        }
        // Formato canônico do ML: MLB-1234567890 (com hífen)
        $canonical = preg_replace('/^MLB(\d+)$/', 'MLB-$1', $code);

        return "https://produto.mercadolivre.com.br/{$canonical}";
    }

    /**
     * Link profundo para o painel de Ads do Mercado Livre.
     * Ainda sem certeza do formato exato — ajustar quando confirmado com a Adman.
     */
    public function linkAdsML(): string
    {
        $base = 'https://www.mercadolivre.com.br/anuncios';
        if ($this->campaign_id) {
            return $base . '/campanhas/' . $this->campaign_id;
        }
        return $base;
    }
}
