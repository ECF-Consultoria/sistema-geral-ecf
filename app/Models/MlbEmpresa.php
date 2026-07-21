<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MlbEmpresa extends Model
{
    use LogsActivity;

    protected $table = 'mlb_empresas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Excluindo skus_estagio* e estagio: são gerenciados automaticamente por marcarSku()
            // e logados com contexto rico diretamente no controller.
            ->logOnly(['nome', 'tipo', 'cust_id', 'polo', 'fase', 'projeto', 'prioridade', 'responsavel_id', 'encerramento', 'gmail'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Empresa MLB cadastrada',
                'updated' => 'Empresa MLB atualizada',
                'deleted' => 'Empresa MLB excluída',
                default   => $eventName,
            });
    }


    protected $fillable = [
        'nome', 'tipo', 'cust_id', 'polo', 'gmail', 'estagio', 'fase', 'projeto', 'prioridade',
        'responsavel_id', 'contexto',
        'skus_estagio1', 'skus_estagio2', 'skus_estagio3',
        'prazo_estagio1', 'prazo_estagio2', 'prazo_estagio3',
        'encerramento', 'criado_por',
        'problema', 'problema_nota', 'problema_em', 'ads_desligado',
        'company_id',
        'arquivado_em', 'arquivado_por', 'arquivado_motivo',
    ];

    protected $casts = [
        'skus_estagio1'  => 'array',
        'skus_estagio2'  => 'array',
        'skus_estagio3'  => 'array',
        'prazo_estagio1' => 'date',
        'prazo_estagio2' => 'date',
        'prazo_estagio3' => 'date',
        'encerramento'   => 'date',
        'problema'       => 'boolean',
        'problema_em'    => 'datetime',
        'ads_desligado'  => 'boolean',
        'arquivado_em'   => 'datetime',
    ];

    /** Empresa arquivada = fora do projeto Polos (não conta em metas/faturamento/painel). */
    public function arquivada(): bool
    {
        return $this->arquivado_em !== null;
    }

    /** Escopo: só empresas ATIVAS (não arquivadas). Aplicar em toda listagem/agregação de Polos. */
    public function scopeAtivas(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('arquivado_em');
    }

    /** Escopo: só empresas ARQUIVADAS (aba "Arquivados"). */
    public function scopeArquivadas(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('arquivado_em');
    }

    public function arquivadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arquivado_por');
    }

    /**
     * Mapeamento fase → projeto. Mantido para compatibilidade com código antigo
     * (em métodos que ainda derivam projeto da fase) e como referência da
     * regra histórica. Após a migration 000010, o campo `projeto` é a fonte
     * canônica — esta constante é fallback apenas.
     */
    public const FASE_PARA_PROJETO = [
        'Aceite no Projeto' => 'POLOS',
        'M0' => 'POLOS', 'M1' => 'POLOS', 'M2' => 'POLOS', 'M3' => 'POLOS', 'M4' => 'POLOS',
        'ASSESSORIA' => 'Assessoria',
        'Incubadora' => 'Incubadora',
        'Implantação' => 'Implantação',
    ];

    /** Projeto: campo canônico no banco, com fallback pra mapeamento por fase. */
    public function projeto(): ?string
    {
        return $this->projeto ?: (self::FASE_PARA_PROJETO[$this->fase ?? ''] ?? null);
    }

    /** Empresa cliente associada (pode ser NULL para POLOS sem Company cadastrada). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /**
     * Escopo de visibilidade por publicador (SEL-02).
     *
     * Admin vê todas; publicador vê apenas as empresas onde `responsavel_id`
     * é o seu id. O filtro é aplicado NA QUERY DO BANCO (não em PHP pós-busca).
     *
     * Reutilizável e testável isoladamente — sob o gate atual `role:admin` o
     * filtro fica dormant (todo acessante é admin), mas já valida quando o gate
     * abrir para `permission:mlb.anunciar`.
     */
    public function scopeVisiveisPara(\Illuminate\Database\Eloquent\Builder $query, User $user): \Illuminate\Database\Eloquent\Builder
    {
        return $query->when(
            !$user->isAdmin(),
            fn ($q) => $q->where('responsavel_id', $user->id),
        );
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function publicacoes(): HasMany
    {
        return $this->hasMany(Publicacao::class, 'mlb_empresa_id');
    }

    public function implementacao(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MlbImplementacao::class, 'empresa_id');
    }

    /** Normaliza um array de SKUs — mantém apenas itens com sku preenchido. */
    public static function normalizaSkus(?array $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        return array_values(array_map(
            fn($item) => [
                'sku'          => trim($item['sku'] ?? ''),
                'ok'           => (bool) ($item['ok'] ?? false),
                'concluido_em' => $item['concluido_em'] ?? null,
                'atrasado'     => (bool) ($item['atrasado'] ?? false),
            ],
            array_filter($items, fn($item) => trim($item['sku'] ?? '') !== '')
        ));
    }

    /** Calcula o progresso total de SKUs (concluídos / total preenchidos). */
    public function progresso(): array
    {
        $ok = $total = 0;
        foreach ([1, 2, 3] as $e) {
            foreach (($this->{"skus_estagio{$e}"} ?? []) as $sku) {
                if (trim($sku['sku'] ?? '') === '') continue;
                $total++;
                if ($sku['ok'] ?? false) $ok++;
            }
        }
        return ['ok' => $ok, 'total' => $total, 'pct' => $total > 0 ? round($ok / $total * 100) : 0];
    }

    // Estágios controlados automaticamente pelo sistema (baseado em SKUs).
    // Qualquer outro valor indica controle manual — não deve ser sobrescrito.
    private const ESTAGIOS_AUTO = ['Não Listado', 'Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];

    /** Avança o estágio automaticamente quando todos os SKUs de um estágio estão concluídos.
     *  Só age se o estágio atual for um dos gerenciados pelo sistema. */
    public function atualizaEstagio(): void
    {
        if (!in_array($this->estagio, self::ESTAGIOS_AUTO)) {
            return;
        }

        $todosFeitos = function (array $lista): bool {
            $preenchidos = array_filter($lista, fn($s) => trim($s['sku'] ?? '') !== '');
            return count($preenchidos) > 0 && array_reduce(
                $preenchidos, fn($carry, $s) => $carry && ($s['ok'] ?? false), true
            );
        };

        $s1 = $this->skus_estagio1 ?? [];
        $s2 = $this->skus_estagio2 ?? [];
        $s3 = $this->skus_estagio3 ?? [];

        if ($todosFeitos($s1) && $todosFeitos($s2) && $todosFeitos($s3)) {
            $novo = 'Concluido';
        } elseif ($todosFeitos($s1) && $todosFeitos($s2)) {
            $novo = 'Estágio 3';
        } elseif ($todosFeitos($s1)) {
            $novo = 'Estágio 2';
        } elseif (array_filter($s1, fn($s) => trim($s['sku'] ?? '') !== '')) {
            $novo = 'Estágio 1';
        } else {
            $novo = 'Não Listado';
        }

        if ($novo !== $this->estagio) {
            $this->update(['estagio' => $novo]);
        }
    }
}
