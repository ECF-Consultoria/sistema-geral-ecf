<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Ppa extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['escopo', 'company_id', 'mlb_empresa_id', 'title', 'status', 'due_date', 'sent_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'PPA criado',
                'updated' => 'PPA atualizado',
                'deleted' => 'PPA excluído',
                default   => $eventName,
            });
    }

    /** PPA de carteira (módulo PPA original, alvo = Company). */
    public const ESCOPO_GERAL = 'geral';

    /** PPA Polos (quick 260805-dzu; alvo = MlbEmpresa do projeto POLOS). */
    public const ESCOPO_POLOS = 'polos';

    protected $fillable = [
        'escopo', 'company_id', 'mlb_empresa_id', 'mentor_id', 'title', 'description', 'actions',
        'status', 'trello_board_url', 'workspace_token', 'due_date', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function mlbEmpresa() { return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id'); }
    public function mentor() { return $this->belongsTo(User::class, 'mentor_id'); }
    public function tasks() { return $this->hasMany(PpaTask::class)->orderBy('order'); }

    /** Colunas EXTRAS do quadro. As três fixas são o ENUM `ppa_tasks.status`. */
    public function colunas() { return $this->hasMany(PpaColuna::class)->orderBy('posicao'); }

    /** Filtra por escopo ('geral' ou 'polos'); PPA antigo sem escopo conta como geral. */
    public function scopeDoEscopo($query, string $escopo)
    {
        return $escopo === self::ESCOPO_GERAL
            ? $query->where(fn ($q) => $q->where('escopo', self::ESCOPO_GERAL)->orWhereNull('escopo'))
            : $query->where('escopo', $escopo);
    }

    /**
     * Nome da empresa dona do plano, seja ela Company (escopo geral) ou
     * MlbEmpresa (escopo polos). Evita espalhar o ?? pelas telas.
     */
    public function nomeEmpresa(): string
    {
        return $this->company?->name ?? $this->mlbEmpresa?->nome ?? '—';
    }
}
