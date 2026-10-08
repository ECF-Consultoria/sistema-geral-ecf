<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Check "reunião do mês feita" de uma empresa dos Polos — só aparece em /polos/empresas
 * (TKT-0004).
 *
 * Chaveado por cust_id normalizado + mes ('YYYYMM'), uma linha por par; ver o docblock da
 * migration 2026_10_08_150000_create_polos_reunioes_mes_table para o porquê de cada decisão.
 */
class PolosReuniaoMes extends Model
{
    protected $table = 'polos_reunioes_mes';

    protected $fillable = [
        'cust_id', 'mes', 'feita', 'user_id', 'marcado_por_nome', 'marcado_em',
    ];

    protected $casts = [
        'feita'      => 'boolean',
        'marcado_em' => 'datetime',
    ];

    /** Quem fez a última marcação. Pode ser null se o usuário foi removido. */
    public function marcadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Nome de exibição: usuário vivo primeiro, snapshot como rede de segurança. */
    public function marcadoPorNome(): string
    {
        return $this->marcadoPor?->name ?? ($this->marcado_por_nome ?: 'Usuário removido');
    }
}
