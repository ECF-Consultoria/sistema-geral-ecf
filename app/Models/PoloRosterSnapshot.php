<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Roster congelado dos polos por mês — quem estava ativo e em que fase NAQUELE mês.
 *
 * Complementa o PoloFaturamentoSnapshot: aquele congela quanto cada empresa faturou,
 * este congela quem contava e sob qual régua. Sem os dois, um mês fechado é reinterpretado
 * toda vez que o cadastro muda — e o time avança todas as fases na virada do mês.
 *
 * Gravado por `polos:congelar-roster`; lido por PolosController::montarAtivosDoMes() nos
 * meses fechados (com fallback para a reconstrução do CSV quando o mês não tem snapshot).
 *
 * Tabela técnica de snapshot, não entidade de domínio → sem LogsActivity.
 */
class PoloRosterSnapshot extends Model
{
    protected $table = 'polos_roster_snapshots';

    /** Fases que contam como "ativo" na meta de faturamento (D-16). */
    public const FASES_ATIVAS = ['M2', 'M3', 'M4', 'Fechamento'];

    /** Congelado a partir do cadastro ao vivo (mês corrente). */
    public const ORIGEM_VIVO = 'vivo';

    /** Reconstruído desfazendo o activity_log (mês passado, backfill). */
    public const ORIGEM_LOG = 'log';

    protected $fillable = [
        'mes',
        'cust_id',
        'mlb_empresa_id',
        'nome',
        'fase',
        'polo',
        'problema',
        'problema_desconsidera_meta',
        'ads_desligado',
        'congelado_em',
        'origem',
    ];

    protected $casts = [
        'problema'                   => 'boolean',
        'problema_desconsidera_meta' => 'boolean',
        'ads_desligado'              => 'boolean',
        'congelado_em'               => 'datetime',
    ];

    /**
     * Mesma forma que `montarAtivosDoMes()` devolve para os demais caminhos — o controller
     * consome os três (ao vivo, CSV e snapshot) sem saber de qual vieram.
     *
     * @return array<string,mixed>
     */
    public function paraAtivo(): array
    {
        return [
            'id'            => $this->mlb_empresa_id,
            'cust_id'       => (string) $this->cust_id,
            'nome'          => (string) $this->nome,
            'polo'          => (string) ($this->polo ?? ''),
            'fase'          => (string) $this->fase,
            'problema'      => (bool) $this->problema,
            'problema_nota' => null, // texto livre de hoje; não descreve o mês congelado
            'problema_desconsidera_meta' => (bool) $this->problema_desconsidera_meta,
            'ads_desligado' => $this->ads_desligado,
        ];
    }
}
