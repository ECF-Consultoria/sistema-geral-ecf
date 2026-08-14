<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo de NPS encerrado manualmente (spec 2026-08-14, item 2).
 *
 * A linha só existe quando alguém FECHA o ciclo — ausência significa ciclo
 * aberto. `mes_coleta` é o mês em que a pesquisa é respondida (mesma grandeza
 * de `nps_surveys.month_reference`); a competência avaliada é sempre
 * `mes_coleta − 1 mês` e é DERIVADA, nunca gravada.
 *
 * Quem responde "este mês está fechado?" é o `NpsJanelaResolver` — nunca
 * consultar esta tabela direto nas telas, senão a régua volta a ter duas
 * versões.
 *
 * @property int         $id
 * @property Carbon      $mes_coleta   sempre dia 1º
 * @property Carbon      $fechado_em
 * @property int|null    $fechado_por
 *
 * @see app/Services/Nps/NpsJanelaResolver.php
 * @see database/migrations/2026_08_14_170000_create_nps_ciclos_table.php (decisão de schema)
 */
class NpsCiclo extends Model
{
    use HasFactory;

    protected $table = 'nps_ciclos';

    protected $fillable = [
        'mes_coleta',
        'fechado_em',
        'fechado_por',
    ];

    protected $casts = [
        'mes_coleta' => 'date',
        'fechado_em' => 'datetime',
    ];

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por');
    }

    /**
     * Competência avaliada por este ciclo — o mês ANTERIOR ao da coleta.
     * `subMonthNoOverflow` evita o edge do dia 31 (mesmo cuidado do
     * `NpsJanelaResolver::mesDeColeta()`, na direção inversa).
     */
    public function competencia(): Carbon
    {
        return $this->mes_coleta->copy()->subMonthNoOverflow()->startOfMonth();
    }

    /** Normaliza qualquer entrada de mês para a chave canônica (dia 1º). */
    public static function chaveDoMes(Carbon $mes): string
    {
        return $mes->copy()->startOfMonth()->toDateString();
    }
}
