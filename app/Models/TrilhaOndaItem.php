<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qual item da trilha está em qual onda. A chave do item vai em colunas tipadas: `produto_id` + `quantidade`
 * (1 = a Simples do produto, `s-{p}`; N ≥ 2 = o Combo N, `c-{p}-{N}`) ou `oferta_id` (`o-{oferta}`: Kit, Combit,
 * combo fora da régua). Um item fica numa onda só (os dois UNIQUE da tabela).
 *
 * `na_onda_desde` é quando ele entrou na onda de agora; `pedido_em`, quando a soltura pediu o robô; `repescado_em`, a
 * única repescagem (um item que nunca começou é pedido de novo no máximo uma vez).
 */
class TrilhaOndaItem extends Model
{
    protected $table = 'trilha_onda_itens';

    protected $fillable = ['onda_id', 'company_id', 'produto_id', 'quantidade', 'oferta_id', 'na_onda_desde', 'pedido_em', 'repescado_em', 'incluido_por'];

    protected $casts = [
        'produto_id'    => 'integer',
        'quantidade'    => 'integer',
        'oferta_id'     => 'integer',
        'na_onda_desde' => 'datetime',
        'pedido_em'     => 'datetime',
        'repescado_em'  => 'datetime',
    ];

    /**
     * Desde quando o robô vale para este item: o começo do dia da onda (São Paulo) ou, se ele entrou depois, a
     * entrada. É daqui que contam o aviso "ainda não começou" e a janela da repescagem.
     */
    public function comecoEfetivo(): ?\Illuminate\Support\Carbon
    {
        $onda = $this->onda;
        if ($onda === null) {
            return null;
        }
        $inicio = \Illuminate\Support\Carbon::parse($onda->dia(), TrilhaOnda::FUSO)->startOfDay();
        $entrou = $this->na_onda_desde?->copy()->setTimezone(TrilhaOnda::FUSO);

        return $entrou !== null && $entrou->gt($inicio) ? $entrou : $inicio;
    }

    public function onda(): BelongsTo
    {
        return $this->belongsTo(TrilhaOnda::class, 'onda_id');
    }

    /** A chave do item na trilha (`s-12`, `c-12-2`, `o-55`). */
    public function chave(): string
    {
        if ($this->oferta_id !== null) {
            return 'o-'.$this->oferta_id;
        }

        return (int) $this->quantidade <= 1 ? 's-'.$this->produto_id : 'c-'.$this->produto_id.'-'.$this->quantidade;
    }

    /**
     * As colunas que identificam o item pela chave, ou null se a chave não tem o formato da trilha.
     *
     * @return array{produto_id: ?int, quantidade: ?int, oferta_id: ?int}|null
     */
    public static function colunasDaChave(string $chave): ?array
    {
        if (preg_match('/^s-(\d{1,18})$/', $chave, $m)) {
            return ['produto_id' => (int) $m[1], 'quantidade' => 1, 'oferta_id' => null];
        }
        if (preg_match('/^c-(\d{1,18})-(\d{1,3})$/', $chave, $m) && (int) $m[2] >= 2) {
            return ['produto_id' => (int) $m[1], 'quantidade' => (int) $m[2], 'oferta_id' => null];
        }
        if (preg_match('/^o-(\d{1,18})$/', $chave, $m)) {
            return ['produto_id' => null, 'quantidade' => null, 'oferta_id' => (int) $m[1]];
        }

        return null;
    }
}
