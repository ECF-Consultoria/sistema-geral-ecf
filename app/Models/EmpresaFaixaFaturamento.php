<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * EmpresaFaixaFaturamento — exceção de tabela progressiva de faturamento
 * PARA UMA EMPRESA específica (Fase 137, D-01, D-04, D-13).
 *
 * D-13 — a exceção é ALL-OR-NOTHING: a existência de QUALQUER linha aqui
 * para a empresa substitui a tabela inteira do serviço, nunca linha a
 * linha. Exceção parcial criaria a pergunta "qual faixa vale para o valor
 * X?" sem resposta óbvia. Quem aplica essa regra é o resolver do plano 03 —
 * este model só expõe o dado cru.
 *
 * Cadastro manual (D-04): "um jeito de cadastrar a tabela progressiva pelo
 * sistema, como se estivesse fazendo contrato, mas só para o sistema saber
 * as faixas" — cobre tabelas antigas ou fora do padrão. O valor da faixa
 * entra em contrato — precisa ser auditável, daí `LogsActivity`.
 *
 * `valor_e_piso` marca a faixa cujo `valor` é "a partir de R$ X", nunca um
 * preço fechado — mesma semântica de `ServicoFaixaFaturamento::valor_e_piso`.
 *
 * @property int $id
 * @property int $company_id
 * @property int $ordem
 * @property string|null $limite_superior (decimal:2 — null = faixa aberta)
 * @property string $valor (decimal:2)
 * @property bool $valor_e_piso
 */
class EmpresaFaixaFaturamento extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'empresa_faixas_faturamento';

    protected $fillable = [
        'company_id',
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
     * Auditoria de mudanças na tabela progressiva de exceção da empresa — o
     * valor da faixa entra em contrato e em cobrança (D-01, D-04).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('faixa_faturamento')
            ->setDescriptionForEvent(
                fn (string $event) => "Faixa de faturamento (empresa {$this->company_id}, ordem {$this->ordem}) foi {$event}"
            );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
