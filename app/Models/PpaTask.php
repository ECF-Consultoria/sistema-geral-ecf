<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PpaTask extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['ppa_id', 'title', 'status', 'coluna_id', 'area', 'prioridade', 'prazo', 'responsavel_lado'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Tarefa PPA criada',
                'updated' => 'Tarefa PPA atualizada',
                'deleted' => 'Tarefa PPA excluída',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'ppa_id', 'title', 'description', 'status', 'order', 'created_by',
        // Campos do card rico (21/08/2026) — todos opcionais. Tarefa antiga
        // segue válida com todos eles nulos; a tela trata ausência como
        // ausência, sem desenhar espaço vazio.
        'area', 'prioridade', 'prazo', 'responsavel_lado', 'concluida_em', 'coluna_id',
    ];

    protected $casts = [
        'prazo'        => 'date',
        'concluida_em' => 'datetime',
    ];

    /** De quem é a bola. Não é FK para `users`: o lado do cliente não tem usuário. */
    public const LADO_ECF     = 'ecf';
    public const LADO_CLIENTE = 'cliente';
    public const LADOS        = [self::LADO_ECF, self::LADO_CLIENTE];

    public const PRIORIDADES = ['baixa', 'media', 'alta'];

    public function ppa()  { return $this->belongsTo(Ppa::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** A coluna EXTRA em que a tarefa está, quando há uma. `null` = coluna base do `status`. */
    public function coluna() { return $this->belongsTo(PpaColuna::class, 'coluna_id'); }

    /**
     * Move a tarefa para um status (e, quando houver, uma coluna extra),
     * cuidando de `concluida_em`.
     *
     * Ponto único de movimentação: o quadro interno (`PpaTaskController`) e o
     * Portal do Cliente (`PortalPpaController`) chamam este mesmo método. Antes
     * de existir, cada lado fazia `update(['status' => ...])` por conta própria
     * — e o carimbo de conclusão, que nasceu depois, teria ficado só num deles.
     *
     * `concluida_em` é limpo quando a tarefa SAI de `done`: a data precisa
     * dizer "foi concluída neste dia", e uma tarefa reaberta não foi concluída
     * dia nenhum. Reconcluir carimba a data nova.
     *
     * Coluna extra cujo `status_base` não bate com o status pedido é ignorada —
     * o status é a verdade, e o card volta à coluna base em vez de ficar num
     * lugar que contradiz a própria etapa.
     */
    public function moverPara(string $status, ?int $colunaId = null): void
    {
        $entrouEmDone = $status === 'done' && $this->status !== 'done';
        $saiuDeDone   = $status !== 'done' && $this->status === 'done';

        if ($colunaId !== null) {
            $coluna = PpaColuna::find($colunaId);
            if (! $coluna || $coluna->ppa_id !== $this->ppa_id || $coluna->status_base !== $status) {
                $colunaId = null;
            }
        }

        $this->status    = $status;
        $this->coluna_id = $colunaId;

        if ($entrouEmDone) {
            $this->concluida_em = now();
        } elseif ($saiuDeDone) {
            $this->concluida_em = null;
        }

        $this->save();
    }
}
