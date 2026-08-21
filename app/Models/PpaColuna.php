<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * PpaColuna — uma coluna EXTRA do quadro de um PPA.
 *
 * As três colunas fixas (`todo`, `doing`, `done`) NÃO são linhas desta tabela:
 * elas são o ENUM `ppa_tasks.status` e continuam sendo a verdade sobre a etapa
 * da tarefa. Ver o docblock da migration `create_ppa_colunas_table` para o
 * porquê.
 *
 * Cada coluna extra se ancora num `status_base`, e é isso que mantém o Portal
 * do Cliente, o progresso e os contadores funcionando sem saber que colunas
 * extras existem.
 */
class PpaColuna extends Model
{
    use LogsActivity;

    protected $table = 'ppa_colunas';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['ppa_id', 'nome', 'status_base', 'cor', 'posicao'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Coluna do quadro criada',
                'updated' => 'Coluna do quadro atualizada',
                'deleted' => 'Coluna do quadro excluída',
                default   => $eventName,
            });
    }

    protected $fillable = ['ppa_id', 'nome', 'status_base', 'cor', 'posicao'];

    /**
     * A paleta que uma coluna extra pode usar. Fechada de propósito: hex livre
     * deixaria o quadro sair da identidade ECF no primeiro roxo neon. Os tokens
     * viram classes Tailwind em `CORES`, em `Pages/Ppa/Kanban.jsx`.
     */
    public const CORES = ['slate', 'amber', 'sky', 'violet', 'rose', 'emerald'];

    /** Os três status fixos, na ordem em que o quadro os desenha. */
    public const STATUS_BASE = ['todo', 'doing', 'done'];

    public function ppa()
    {
        return $this->belongsTo(Ppa::class);
    }

    public function tasks()
    {
        return $this->hasMany(PpaTask::class, 'coluna_id');
    }
}
