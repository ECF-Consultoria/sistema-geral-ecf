<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ServicoFaixaFaturamento — uma faixa da tabela progressiva de faturamento
 * de um serviço (Fase 137, D-01).
 *
 * A tabela progressiva deixa de ser a constante `AdminController::FAIXAS` e
 * texto solto nos modelos .docx da Clicksign e vira dado estruturado, uma
 * linha por faixa, ordenada por `ordem`. O valor da faixa entra em
 * contrato — precisa ser auditável (D-01), daí `LogsActivity`.
 *
 * `valor_e_piso` marca a faixa cujo `valor` é "a partir de R$ X" (ex.: a
 * última faixa de Gestão/Brigada, "acima de R$ 5.000.000 → a partir de
 * R$ 12.000"), nunca um preço fechado.
 *
 * Não há lógica de classificação de faturamento neste model — ela mora no
 * resolver do plano 03, para existir num lugar só.
 *
 * @property int $id
 * @property int $servico_id
 * @property int $ordem
 * @property string|null $limite_superior (decimal:2 — null = faixa aberta)
 * @property string $valor (decimal:2)
 * @property bool $valor_e_piso
 */
class ServicoFaixaFaturamento extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'servico_faixas_faturamento';

    protected $fillable = [
        'servico_id',
        'ordem',
        'limite_superior',
        'valor',
        'valor_e_piso',
    ];

    protected $casts = [
        'ordem'           => 'int',
        'limite_superior' => 'decimal:2',
        'valor'           => 'decimal:2',
        'valor_e_piso'    => 'bool',
    ];

    /**
     * Auditoria de mudanças na tabela progressiva do serviço — o valor da
     * faixa entra em contrato e em cobrança (D-01).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('faixa_faturamento')
            ->setDescriptionForEvent(
                fn (string $event) => "Faixa de faturamento (serviço {$this->servico_id}, ordem {$this->ordem}) foi {$event}"
            );
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    /**
     * Scope: ordena pela coluna `ordem` ascendente — ordem de leitura da
     * tabela progressiva.
     */
    public function scopeOrdenadas($query)
    {
        return $query->orderBy('ordem');
    }
}
