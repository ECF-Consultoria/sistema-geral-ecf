<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChecklistAdministrativoItem — Fase 152 Plano 02 (D-10). Um item do
 * checklist administrativo obrigatório, ancorado direto em `company_id`.
 *
 * D-10 — copia o SHAPE do motor de Onboarding (`OnboardingPasso`), nunca a
 * HOSPEDAGEM: esta tabela não vive dentro de um `Onboarding` nem de um
 * `ContratoServico` — hospedar ali exigiria inventar um serviço fantasma e
 * furar a `unique(contrato_servico_id)` de `onboardings`.
 *
 * Catálogo fechado de `status`: apenas DOIS valores. Os quatro estados
 * extras de `OnboardingPasso` (`bloqueado`, `aguardando_coleta`,
 * `indeterminado` e o estado "não aplicável" — ver D-02) NÃO existem aqui —
 * os 4 resolvers automáticos desta fase são síncronos (leitura de coluna
 * local, sem rede), e D-02 proíbe o estado "não aplicável": a isenção de
 * contrato (empresa com `Servico::exigeContrato() === false`) é resolvida
 * por MONTAGEM CONDICIONAL do grupo (D-07) — o item simplesmente não é
 * instanciado para aquela empresa — nunca por marcação manual.
 */
class ChecklistAdministrativoItem extends Model
{
    // Pluralização automática do Eloquent para ChecklistAdministrativoItem
    // não produz `checklist_administrativo_itens` — declarar explicitamente.
    protected $table = 'checklist_administrativo_itens';

    // $fillable explícito — nunca $guarded = [] (mass assignment, T-152-02-01).
    protected $fillable = [
        'company_id',
        'chave',
        'status',
        'valor',
        'feito_por',
        'feito_em',
        'auto_em',
    ];

    protected $casts = [
        'valor'    => 'array',
        'feito_em' => 'datetime',
        'auto_em'  => 'datetime',
    ];

    // ─── Catálogo fechado de `status` (D-02 — dois estados, não seis) ───────
    public const STATUS_ABERTO = 'aberto';
    public const STATUS_CONCLUIDO = 'concluido';

    public const STATUS_TODOS = [
        self::STATUS_ABERTO,
        self::STATUS_CONCLUIDO,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * D-11: autoria sobrevive a soft delete. Usuário desligado (soft delete
     * em `users`) não pode apagar o rastro de quem marcou o item — mesma
     * disciplina de `Pendencia::abertaPor()`/`corrigidaPor()`, diferente de
     * `OnboardingPasso::feitoPor()`, que NÃO usa `->withTrashed()`.
     */
    public function feitoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feito_por')->withTrashed();
    }

    public function estaConcluido(): bool
    {
        return $this->status === self::STATUS_CONCLUIDO;
    }
}
