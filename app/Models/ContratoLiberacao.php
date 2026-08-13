<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContratoLiberacao — Fase 129 Plano 04 (D-05 do CONTEXT). O FATO gravado
 * de que uma empresa foi liberada para o operacional, POR SERVIÇO (D-01),
 * com quem/quando/por quê/por qual via.
 *
 * "Liberada" é estado PRÓPRIO (D-03) — gravado mesmo quando o serviço não
 * gera nenhuma `MlbEmpresa` (ex.: "Gestão" sozinho, ver
 * `EmpresaOperacionalRouter::criarFicha()`). `gerou_ficha` é a prova
 * material dessa distinção.
 *
 * A garantia real de unicidade contra corrida (webhook × liberação manual
 * da Fase 130 simultâneos) é o índice `cl_empresa_servico_uniq` do banco,
 * não este model nem `EmpresaOperacionalRouter::liberarEmpresa()` — os dois
 * só fazem o guard de LEITURA, que é UX.
 *
 * ⚠️ Este model NÃO usa `LogsActivity` (spatie), de propósito: a própria
 * tabela já é o registro de auditoria (quem/quando/por quê/por qual via).
 * Logar de novo no `activity_log` criaria uma segunda cópia com retenção
 * própria e sem necessidade.
 */
class ContratoLiberacao extends Model
{
    protected $table = 'contrato_liberacoes';

    // $fillable explícito — nunca $guarded = [] (mass assignment).
    protected $fillable = [
        'company_id',
        'servico_id',
        'contrato_assinatura_id',
        'via',
        'liberado_por_user_id',
        'motivo',
        'gerou_ficha',
        'liberado_em',
    ];

    protected $casts = [
        'liberado_em' => 'datetime',
        'gerou_ficha' => 'boolean',
    ];

    public const VIA_WEBHOOK = 'webhook';
    public const VIA_MANUAL  = 'manual';

    /** As 2 vias possíveis (D-05). */
    public const VIA_TODAS = [
        self::VIA_WEBHOOK,
        self::VIA_MANUAL,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoAssinatura::class, 'contrato_assinatura_id');
    }

    public function liberadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberado_por_user_id');
    }

    /**
     * Conveniência de UX para o guard de leitura de `liberarEmpresa()` e
     * para as telas da Fase 130/131 perguntarem "esta empresa já está
     * liberada para este serviço?".
     *
     * ⚠️ NÃO é a garantia real contra corrida: entre esta consulta e o
     * INSERT de quem chama cabe outra transação (webhook e liberação
     * manual simultâneos, SC4 da Fase 130). A garantia real é o índice
     * único `cl_empresa_servico_uniq` do banco — este método só evita
     * trabalho redundante no caminho feliz.
     */
    public static function existeParaServico(int $companyId, int $servicoId): ?self
    {
        return self::where('company_id', $companyId)
            ->where('servico_id', $servicoId)
            ->first();
    }
}
