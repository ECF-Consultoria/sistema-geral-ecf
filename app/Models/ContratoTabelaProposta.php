<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ContratoTabelaProposta — o que a leitura automática do Clicksign (Fase 140, planos 01-03)
 * descobriu sobre UM contrato de gestão de ADS, guardado para conferência humana (TAB-07).
 *
 * ⚠️ **Proposta ≠ cobrança.** `company_id` aqui é o PALPITE de `EmpresaPalpiteService`, nunca uma
 * empresa confirmada — a varredura real (85 contratos) teve ZERO casamentos com segurança
 * (140-CONTEXT.md D-05). O vínculo só vira dado de cobrança quando uma pessoa confirma (plano
 * 140-05); até lá, `situacao` fica `pendente` e nenhuma tabela de faturamento é tocada.
 *
 * `tipo_cobranca` cobre os dois formatos medidos: `valor_fixo` (29 de 85 contratos — sem faixa
 * nenhuma) e `tabela` (49 de 85 — `faixas` no mesmo shape de `EmpresaFaixaFaturamento`). Pagamento
 * escalonado (parcelas de valores diferentes) grava `valor_fixo` nulo — o(s) valor(es) lidos ficam
 * só no `motivo`, nunca uma média inventada. `indefinido`/`ilegivel` cobrem o que não deu para
 * entender ou não deu para ler.
 *
 * `LogsActivity` (log name `tabela_proposta`) porque este dado vira cobrança se confirmado —
 * precisa de trilha de quem mexeu, mesma disciplina de `EmpresaFaixaFaturamento`.
 *
 * @property int $id
 * @property string $clicksign_envelope_id
 * @property string $nome_envelope
 * @property string $envelope_situacao
 * @property \Illuminate\Support\Carbon|null $envelope_data
 * @property int|null $company_id
 * @property string $confianca
 * @property string|null $pontuacao (decimal:2)
 * @property bool $ambiguo
 * @property array|null $candidatos
 * @property string $tipo_cobranca
 * @property string|null $valor_fixo (decimal:2)
 * @property array|null $faixas
 * @property string|null $cnpj_lido
 * @property string|null $razao_social_lida
 * @property string|null $motivo
 * @property string $situacao
 * @property int|null $confirmado_por
 * @property \Illuminate\Support\Carbon|null $confirmado_em
 */
class ContratoTabelaProposta extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'contrato_tabela_propostas';

    public const SITUACAO_PENDENTE  = 'pendente';
    public const SITUACAO_CONFIRMADA = 'confirmada';
    public const SITUACAO_DESCARTADA = 'descartada';

    public const CONFIANCA_CERTO    = 'certo';
    public const CONFIANCA_PROVAVEL = 'provavel';
    public const CONFIANCA_INCERTO  = 'incerto';

    public const TIPO_TABELA     = 'tabela';
    public const TIPO_VALOR_FIXO = 'valor_fixo';
    public const TIPO_INDEFINIDO = 'indefinido';
    public const TIPO_ILEGIVEL   = 'ilegivel';

    protected $fillable = [
        'clicksign_envelope_id',
        'nome_envelope',
        'envelope_situacao',
        'envelope_data',
        'company_id',
        'confianca',
        'pontuacao',
        'ambiguo',
        'candidatos',
        'tipo_cobranca',
        'valor_fixo',
        'faixas',
        'cnpj_lido',
        'razao_social_lida',
        'motivo',
        'situacao',
        'confirmado_por',
        'confirmado_em',
    ];

    protected $casts = [
        'envelope_data'  => 'date',
        'pontuacao'      => 'decimal:2',
        'ambiguo'        => 'bool',
        'candidatos'     => 'array',
        'valor_fixo'     => 'decimal:2',
        'faixas'         => 'array',
        'confirmado_em'  => 'datetime',
    ];

    protected $attributes = [
        'situacao' => self::SITUACAO_PENDENTE,
    ];

    /**
     * Auditoria de mudanças na proposta — o dado vira cobrança se confirmado, precisa de trilha
     * de quem mexeu (mesma disciplina de `EmpresaFaixaFaturamento`).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('tabela_proposta')
            ->setDescriptionForEvent(
                fn (string $event) => "Proposta de tabela de cobrança (contrato {$this->clicksign_envelope_id}) foi {$event}"
            );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    /**
     * Scope: propostas ainda sem conferência humana — o que a tela do plano 140-05 lista.
     */
    public function scopePendentes($query)
    {
        return $query->where('situacao', self::SITUACAO_PENDENTE);
    }
}
