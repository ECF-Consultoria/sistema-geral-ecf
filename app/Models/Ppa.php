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
     * As três contagens de tarefas que a LISTA de planos precisa, numa
     * subquery cada — e não uma consulta por linha.
     *
     * Antes, a lista fazia `$p->tasks()->count()` dentro do `through()`: duas
     * idas ao banco por plano, 40 numa página de 20. Como a ordenação passou a
     * depender dessas contagens, elas precisavam estar no SELECT de qualquer
     * forma.
     */
    public function scopeComContagemDeTarefas($query)
    {
        return $query->withCount([
            'tasks',
            'tasks as tasks_done_count'  => fn ($q) => $q->where('status', 'done'),
            'tasks as tasks_doing_count' => fn ($q) => $q->where('status', 'doing'),
        ]);
    }

    /**
     * A ordem em que os planos pedem atenção — o espelho, em SQL, da régua que
     * `resources/js/lib/ppaAgrupamento.js` aplica na tela.
     *
     * Os dois PRECISAM concordar: a tela agrupa o que recebe, e a lista é
     * paginada. Se o banco mandasse os planos em outra ordem, a página 1 traria
     * concluídos enquanto um plano em andamento esperaria na página 2 — e a
     * seção "Em andamento" apareceria vazia numa lista que tem planos andando.
     *
     * 1. grupo: em andamento (0) · a fazer (1) · concluído (2);
     * 2. dentro do grupo, prazo mais apertado primeiro, sem prazo por último;
     * 3. empate: o mais recente primeiro, como sempre foi.
     *
     * Os nomes usados no CASE são os aliases de {@see scopeComContagemDeTarefas},
     * e por isso ele tem de ser aplicado antes. Alias do SELECT em ORDER BY
     * funciona em MySQL/MariaDB e em SQLite — o ORDER BY é avaliado depois da
     * projeção, ao contrário do WHERE.
     */
    public function scopeOrdenadoPorAtencao($query)
    {
        return $query
            ->orderByRaw("CASE
                WHEN status = 'completed' THEN 2
                WHEN tasks_count > 0 AND tasks_done_count = tasks_count THEN 2
                WHEN tasks_doing_count > 0 THEN 0
                ELSE 1
            END")
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderByDesc('created_at');
    }

    /**
     * Dias entre hoje e `due_date` (negativo = passou). `null` quando não há
     * prazo ou quando o plano já foi encerrado.
     *
     * Calculado no servidor pela mesma razão de `PpaQuadroService::prazo()`: o
     * mesmo cálculo no navegador usaria o fuso de quem olha, e o plano ficaria
     * "atrasado" um dia antes para uns e não para outros. Plano encerrado
     * devolve `null` porque atraso de trabalho fechado não é atraso — é o que
     * apaga o selo vermelho nas duas telas.
     */
    public function diasAteOPrazo(): ?int
    {
        if (! $this->due_date || $this->status === 'completed') {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
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
