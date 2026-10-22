<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faturamento lançado à mão para uma empresa dos Polos num mês (pedido de 22/10/2026).
 *
 * Só vale quando a Adman não tem dado da empresa naquele mês — a regra mora em
 * PolosController::faturamentoVigenteDoMes(). Chaveado por cust_id normalizado + mes
 * ('YYYYMM'); ver o docblock da migration 2026_10_22_150000_create_polos_faturamento_manual_table
 * para o porquê de cada decisão de schema.
 */
class PoloFaturamentoManual extends Model
{
    protected $table = 'polos_faturamento_manual';

    protected $fillable = [
        'cust_id', 'mes', 'valor', 'observacao', 'user_id', 'lancado_por_nome',
    ];

    protected $casts = [
        'valor' => 'float',
    ];

    /** Quem fez o último lançamento. Pode ser null se o usuário foi removido. */
    public function lancadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Nome de exibição: usuário vivo primeiro, snapshot como rede de segurança. */
    public function lancadoPorNome(): string
    {
        return $this->lancadoPor?->name ?? ($this->lancado_por_nome ?: 'Usuário removido');
    }
}
