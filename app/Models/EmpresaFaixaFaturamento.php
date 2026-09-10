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
 * ### Procedência (`origem`, Fase 141 Plano 03, D-04/D-05)
 * `ORIGEM_MANUAL` — cadastro humano pelo sistema (default; toda linha criada antes desta fase é
 * disto). `ORIGEM_CONTRATO` — confirmação humana da leitura de um contrato do Clicksign (Fase 140).
 * `ORIGEM_PRESUMIDA_SERVICO` — **ninguém confirmou esta tabela**; ela foi copiada da tabela do
 * serviço na virada da Fase 141, porque a tabela por serviço deixou de ser régua aplicável (D-04) e
 * sem essa cópia a empresa ficaria sem tabela nenhuma. `servico_origem_id` guarda de qual serviço.
 * A tela precisa dizer isso ao usuário — não é o mesmo grau de confiança que uma tabela confirmada
 * por humano, e `presumida_servico` NUNCA deve contar como `tabela_confirmada = true`.
 *
 * @property int $id
 * @property int $company_id
 * @property int $ordem
 * @property string|null $limite_superior (decimal:2 — null = faixa aberta)
 * @property string $valor (decimal:2)
 * @property bool $valor_e_piso
 * @property string $origem
 * @property int|null $servico_origem_id
 */
class EmpresaFaixaFaturamento extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'empresa_faixas_faturamento';

    public const ORIGEM_MANUAL             = 'manual';

    public const ORIGEM_CONTRATO           = 'contrato';

    public const ORIGEM_PRESUMIDA_SERVICO  = 'presumida_servico';

    protected $fillable = [
        'company_id',
        'ordem',
        'limite_superior',
        'valor',
        'valor_e_piso',
        'origem',
        'servico_origem_id',
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
            ->logFillable()
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
