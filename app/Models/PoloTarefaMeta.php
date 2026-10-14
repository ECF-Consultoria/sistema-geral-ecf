<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tarefa do time dos Polos com meta editável (TKT-0017, DEV-44): um cartão da tela "Tarefas"
 * do Modo TV do Painel Polos. Decisões de schema na migration 2026_10_14_110000.
 *
 * O "feito" de cada cartão é calculado no front, a partir das empresas do Painel, pela régua
 * de `resources/js/lib/polosTarefas.js` — as chaves e os estágios abaixo são espelhados lá
 * (sem enum compartilhado entre PHP e JS: mudar um exige mudar o outro).
 */
class PoloTarefaMeta extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'polos_tarefas_metas';

    /**
     * Indicadores que um cartão pode acompanhar → rótulo da tela. A régua de "feito" de cada um
     * mora em polosTarefas.js (INDICADORES_TAREFA); a ordem aqui é a do select do formulário.
     */
    public const INDICADORES = [
        'places'           => 'Places',
        'me1'              => 'ME1',
        'decola'           => 'Decola',
        'central_promocao' => 'Central de Promoções',
        'colaborador'      => 'Acesso colaborador',
        'campanha'         => 'Campanha',
        'adman'            => 'Adman',
        'ppa'              => 'PPA',
        'publicacao'       => 'Publicação',
    ];

    /** Estágio-alvo da Publicação (strings EXATAS de mlb_empresas.estagio; 'Concluido' sem acento). */
    public const ESTAGIOS_ALVO = ['Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];

    /** Sem estágio escolhido, Publicação = saiu de "Não Listado" (régua aprovada no ticket). */
    public const ESTAGIO_ALVO_PADRAO = 'Estágio 1';

    protected $fillable = [
        'indicador', 'estagio_alvo', 'titulo', 'descricao', 'meta',
        'responsavel_id', 'prazo', 'criado_por', 'atualizado_por',
    ];

    protected $casts = [
        'meta'  => 'integer',
        'prazo' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['indicador', 'estagio_alvo', 'titulo', 'descricao', 'meta', 'responsavel_id', 'prazo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('polos')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Tarefa do time (Polos) criada',
                'updated' => 'Tarefa do time (Polos) atualizada',
                'deleted' => 'Tarefa do time (Polos) removida',
                default   => $eventName,
            });
    }

    public function responsavel()
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    /** Forma que a tela recebe (prop `tarefasMeta` do Painel Polos). */
    public function paraTela(): array
    {
        return [
            'id'               => $this->id,
            'indicador'        => $this->indicador,
            'estagio_alvo'     => $this->estagio_alvo,
            'titulo'           => $this->titulo,
            'descricao'        => $this->descricao,
            'meta'             => $this->meta,
            'responsavel_id'   => $this->responsavel_id,
            'responsavel_nome' => $this->responsavel?->name,
            'prazo'            => $this->prazo?->format('Y-m-d'),
        ];
    }
}
