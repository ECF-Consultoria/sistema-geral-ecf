<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * GrupoFaixaFaturamento — tabela progressiva de faturamento própria de um
 * GRUPO de empresas (`CompanyGroup`), Fase 138 (D-01).
 *
 * D-01 — precedência de três níveis: a existência de QUALQUER linha aqui
 * para o grupo substitui a tabela INTEIRA que valeria abaixo dela (a
 * exceção da empresa membro, `EmpresaFaixaFaturamento`, OU a tabela do
 * serviço, `ServicoFaixaFaturamento`) — nunca linha a linha. Mesma
 * convenção ALL-OR-NOTHING de `EmpresaFaixaFaturamento` (D-13). Quem aplica
 * essa regra é `FechamentoFaixaResolver::paraGrupo()`/`paraEmpresa()` — este
 * model só expõe o dado cru.
 *
 * Cadastro manual pela tela (plano 06): cobre grupos que negociaram tabela
 * própria fora do padrão do serviço. O valor da faixa entra em cobrança —
 * precisa ser auditável, daí `LogsActivity`.
 *
 * `valor_e_piso` marca a faixa cujo `valor` é "a partir de R$ X", nunca um
 * preço fechado — mesma semântica de `EmpresaFaixaFaturamento::valor_e_piso`.
 *
 * @property int $id
 * @property int $company_group_id
 * @property int $ordem
 * @property string|null $limite_superior (decimal:2 — null = faixa aberta)
 * @property string $valor (decimal:2)
 * @property bool $valor_e_piso
 */
class GrupoFaixaFaturamento extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'grupo_faixas_faturamento';

    protected $fillable = [
        'company_group_id',
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
     * Auditoria de mudanças na tabela progressiva do grupo — o valor da
     * faixa entra em cobrança para todas as empresas-membro (D-01).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('faixa_faturamento')
            ->setDescriptionForEvent(
                fn (string $event) => "Faixa de faturamento (grupo {$this->company_group_id}, ordem {$this->ordem}) foi {$event}"
            );
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
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
