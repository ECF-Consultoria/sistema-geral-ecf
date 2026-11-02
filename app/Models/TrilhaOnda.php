<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Uma onda da Trilha de produtos (2ª entrega, 02/11/2026): um grupo de ofertas com o DIA em que o robô (o agente de
 * IA) começa a prepará-las. A data não é prazo de publicação.
 *
 * `data` fica SEM cast de propósito: é o texto `Y-m-d`, gravado assim pelo serviço e comparado em PHP. Com cast
 * `date` o Eloquent gravaria `Y-m-d 00:00:00`, que no SQLite dos testes compara diferente do MySQL (decisão de
 * schema na migration `2026_11_02_100000_create_trilha_tables`). Sem `LogsActivity`: o rastro é o
 * `RegistroEstrutura`, como no Portal.
 */
class TrilhaOnda extends Model
{
    public const FUSO = 'America/Sao_Paulo';

    protected $table = 'trilha_ondas';

    protected $fillable = ['company_id', 'nome', 'data', 'liberada_em', 'criada_por'];

    protected $casts = [
        'liberada_em' => 'datetime',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(TrilhaOndaItem::class, 'onda_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    /** O dia de hoje no fuso de São Paulo, em `Y-m-d` — a régua de todas as perguntas sobre a data. */
    public static function hoje(): string
    {
        return Carbon::now(self::FUSO)->toDateString();
    }

    /** A data gravada, só o dia (o MySQL devolve `Y-m-d`; um valor com hora, de qualquer origem, é cortado). */
    public function dia(): string
    {
        return substr((string) $this->getAttribute('data'), 0, 10);
    }

    /** O robô já começou (ou começa hoje)? */
    public function comecou(?string $hoje = null): bool
    {
        return $this->dia() <= ($hoje ?? self::hoje());
    }
}
