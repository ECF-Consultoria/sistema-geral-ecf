<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Servico — entrada no catálogo de serviços oferecidos pela ECF.
 *
 * Frente A (Módulo Serviços): catálogo mestre que alimenta o modal de
 * contrato em Companies/Show. Activity log via spatie/laravel-activitylog
 * (consistente com User/Company/Goal).
 *
 * Phase 37 / Plan 37-01 (REQ-37-03) — coluna `setor` permite categorização
 * Performance / Publicação / Outros usada por:
 *  - /companies (filtro Performance via whereHas contratos_servico.servico.setor='performance')
 *  - /comercial/empresas/listagem (categorização visual por setor)
 *
 * Sem coluna paralela em `companies`: setor sempre derivado do catálogo.
 */
class Servico extends Model
{
    use LogsActivity;

    protected $table = 'servicos';

    protected $fillable = [
        'nome',
        'valor_padrao',
        'tipo_cobranca',
        'ativo',
        'setor',
    ];

    protected $casts = [
        'valor_padrao' => 'decimal:2',
        'ativo'        => 'boolean',
    ];

    // ─── Constants de tipo de cobrança ──────────────────────────────────────
    public const TIPO_MENSAL = 'mensal';
    public const TIPO_UNICA  = 'unica';

    // ─── Constants de setor (Phase 37 Plan 37-01 — REQ-37-03) ───────────────
    // 2026-07-03 quick fix: adicionado 'polos' após operador criar o setor Polos
    // na tabela `setores` (id=7 slug=polos). Servico #2 "Polos" ficava com
    // setor='outros' porque o enum não aceitava 'polos' — corrigido via migration
    // add_polos_to_servicos_setor_enum + update Servico#2.
    public const SETOR_PERFORMANCE = 'performance';
    public const SETOR_PUBLICACAO  = 'publicacao';
    public const SETOR_POLOS       = 'polos';
    public const SETOR_OUTROS      = 'outros';

    /**
     * Lista plana dos setores válidos (espelha o enum do schema).
     */
    public const SETORES = [
        self::SETOR_PERFORMANCE,
        self::SETOR_PUBLICACAO,
        self::SETOR_POLOS,
        self::SETOR_OUTROS,
    ];

    /**
     * Mapeamento tipo → label para UI/relatórios.
     */
    public static function tiposCobranca(): array
    {
        return [
            self::TIPO_MENSAL => 'Mensal',
            self::TIPO_UNICA  => 'Única',
        ];
    }

    /**
     * Mapeamento setor → label pt-BR para UI/relatórios (Phase 37).
     */
    public static function setoresLabels(): array
    {
        return [
            self::SETOR_PERFORMANCE => 'Performance',
            self::SETOR_PUBLICACAO  => 'Publicação',
            self::SETOR_POLOS       => 'Polos',
            self::SETOR_OUTROS      => 'Outros',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'valor_padrao', 'tipo_cobranca', 'ativo', 'setor'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match ($eventName) {
                'created' => 'Serviço criado',
                'updated' => 'Serviço atualizado',
                'deleted' => 'Serviço excluído',
                default   => $eventName,
            });
    }

    /**
     * Contratos vinculados a este serviço (ativos + inativos).
     */
    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoServico::class);
    }

    /**
     * Scope: apenas serviços ativos.
     */
    public function scopeActive($query)
    {
        return $query->where('ativo', true);
    }

    /**
     * Scope: filtra serviços por setor (Phase 37).
     *
     * Exemplo: Servico::porSetor('performance')->get()
     */
    public function scopePorSetor($query, string $setor)
    {
        return $query->where('setor', $setor);
    }

    /**
     * Helper: este serviço pertence ao setor Performance? (Phase 37)
     */
    public function isPerformance(): bool
    {
        return $this->setor === self::SETOR_PERFORMANCE;
    }

    /**
     * Helper: este serviço pertence ao setor Publicação? (Phase 37)
     */
    public function isPublicacao(): bool
    {
        return $this->setor === self::SETOR_PUBLICACAO;
    }
}
