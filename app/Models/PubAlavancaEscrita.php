<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma escrita das Alavancas na conta do Mercado Livre (D-05): quem, quando, em qual
 * conta, o que foi enviado e a resposta crua — guardada mesmo em erro.
 */
class PubAlavancaEscrita extends Model
{
    protected $table = 'pub_alavanca_escritas';

    public const PENDENTE = 'PENDENTE';
    public const OK = 'OK';
    public const ERRO = 'ERRO';
    public const INCERTO = 'INCERTO';
    public const RECUSADA = 'RECUSADA';

    public const ALAVANCAS = ['promocao', 'cupom', 'atacado', 'exclusao'];
    public const RESULTADOS = [self::PENDENTE, self::OK, self::ERRO, self::INCERTO, self::RECUSADA];

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'resumo' => 'array',
        'resposta' => 'array',
        'enviado_em' => 'datetime',
        'concluido_em' => 'datetime',
        'http_status' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Linhas da empresa por qualquer das âncoras; sem nenhuma, não devolve nada (nunca tudo). */
    public function scopeDaEmpresa(Builder $q, ?MlbEmpresa $e, ?Company $c): Builder
    {
        if (! $e && ! $c) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($e, $c) {
            if ($e) {
                $w->orWhere('mlb_empresa_id', $e->id);
            }
            if ($c) {
                $w->orWhere('company_id', $c->id);
            }
        });
    }

    /** Fecha a linha com o resultado e a hora de conclusão. */
    public function marcar(string $resultado, array $campos = []): self
    {
        $this->update(['resultado' => $resultado, 'concluido_em' => now(), ...$campos]);

        return $this;
    }
}
