<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 137 (plano 03, D-16 — Opção B) — registro imutável de cada transição
 * de `companies.etapa`. Gravado exclusivamente por
 * `App\Services\FluxoEntrada\EtapaTransicaoService::transicionar()`, dentro
 * da mesma transação que grava a coluna — não existe etapa mudada sem linha
 * de histórico (exceção deliberada: `carimbarBackfill()` não gera histórico,
 * ver docblock do serviço).
 *
 * Append-only, sem `updated_at`. É o insumo bruto que a Fase 143 (HIST-01/02/03)
 * consome para montar a timeline e medir SLA por etapa — o índice composto
 * `(company_id, created_at)` é exatamente a consulta que aquela fase precisa.
 */
class CompanyEtapaTransicao extends Model
{
    protected $table = 'company_etapa_transicoes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'etapa_anterior',
        'etapa_nova',
        'user_id',
        'motivo',
        'retrocesso',
    ];

    protected $casts = [
        'retrocesso' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Fase 137 (plano 09, gap closure G2) — pode devolver `null`. A FK de
     * `user_id` é `nullOnDelete()`: quando o ator é removido permanentemente
     * (`UserController::forceDestroy()`), a linha de histórico sobrevive e só
     * a referência ao ator se perde. `null` aqui é estado ESPERADO, não
     * corrupção de dado — quem consumir (Fase 143) deve renderizar algo como
     * "usuário removido", nunca assumir objeto.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
