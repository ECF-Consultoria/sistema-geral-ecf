<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Lançamento manual de métrica financeira por (empresa, canal, mês, métrica) —
 * Fase 136 Plano 02 (D-01/D-02/D-07/D-09/D-12).
 *
 * O CANAL (`fonte`) entra na identidade porque a mesma empresa pode ser
 * atendida por profissionais DIFERENTES em marketplaces diferentes — um
 * estrategista no Mercado Livre, outro na Shopee, cada um com sua própria nota.
 * O motor já separa isso (a fonte sai dos vínculos da carteira de cada
 * usuário); sem `fonte` aqui, um CMV digitado para a Shopee entraria também na
 * nota de quem cuida do Mercado Livre da mesma conta.
 *
 * Uma linha representa UMA célula `(company_id, fonte, mes_referencia, metrica)`.
 * Faturamento e margem (CMV) alternam `auto`/`manual` de forma INDEPENDENTE
 * (D-07) — por isso a granularidade é por métrica, nunca um toggle único por
 * empresa/mês.
 *
 * `ativo = false` significa "revertido para auto": a linha NUNCA é deletada
 * (D-02/D-12) — o rastro de quem lançou, quando e com qual valor é o
 * produto, não um efeito colateral. `valor_anterior` guarda o valor
 * substituído na última edição, para o histórico ficar legível sem depender
 * só de `activity_log`.
 *
 * Molde: `App\Models\BonusInvalidacao` (model pequeno, `LogsActivity`).
 *
 * @see .planning/phases/136-.../136-02-PLAN.md
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter::competenciaConsolidada()
 */
class DesempenhoMetricaManual extends Model
{
    use LogsActivity;

    protected $table = 'desempenho_metricas_manuais';

    /**
     * Whitelist canônica de métricas lançáveis manualmente. Consumida pelo
     * `StoreMetricaManualRequest` (Task 2), pelo serviço de override (Plano
     * 03) e pela tela (Plano 05). Nenhum outro arquivo pode redigitar essas
     * strings soltas.
     */
    public const METRICA_FATURAMENTO = 'faturamento';
    public const METRICA_MARGEM_CMV  = 'margem_cmv';

    public const METRICAS = [
        self::METRICA_FATURAMENTO,
        self::METRICA_MARGEM_CMV,
    ];

    /**
     * Canais lançáveis. Os MESMOS identificadores que o
     * `FinancialSourceResolver` devolve — 'adman' é Mercado Livre, 'shopee' é
     * Shopee. Não inventar rótulo novo aqui: o override casa o lançamento com
     * a fonte que o motor está computando comparando estas strings.
     */
    public const FONTE_ADMAN  = 'adman';
    public const FONTE_SHOPEE = 'shopee';

    public const FONTES = [
        self::FONTE_ADMAN,
        self::FONTE_SHOPEE,
    ];

    protected $fillable = [
        'company_id',
        'fonte',
        'mes_referencia',
        'metrica',
        'valor',
        'valor_anterior',
        'ativo',
        'lancado_por',
        'lancado_em',
    ];

    protected $casts = [
        'mes_referencia' => 'date',
        'lancado_em'     => 'datetime',
        'valor'          => 'float',
        'valor_anterior' => 'float',
        'ativo'          => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'fonte', 'mes_referencia', 'metrica', 'valor', 'valor_anterior', 'ativo', 'lancado_por'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'lançou valor manual de desempenho',
                'updated' => 'editou valor manual de desempenho',
                default   => "métrica manual {$event}",
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lancadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lancado_por');
    }

    /**
     * Linhas ATIVAS (`ativo = true`) da competência informada, opcionalmente
     * filtradas por um conjunto de empresas. Pré-carrega em lote para o
     * Plano 03 não cair em N+1 ao montar o override por empresa.
     *
     * @param  array<int, int>|null  $companyIds
     * @return Collection<int, self>
     */
    public static function ativasDaCompetencia(Carbon $mes, ?array $companyIds = null, ?string $fonte = null): Collection
    {
        $query = static::query()
            ->where('ativo', true)
            ->whereDate('mes_referencia', $mes->copy()->startOfMonth()->toDateString());

        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }

        if ($fonte !== null) {
            $query->where('fonte', $fonte);
        }

        return $query->get();
    }
}
