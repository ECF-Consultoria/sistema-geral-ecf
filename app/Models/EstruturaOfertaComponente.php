<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item da composição de combo/kit/combit: "Cadeira 01 ×4".
 *
 * As unidades da oferta não são guardadas — saem da soma destas quantidades
 * (`ReguaEstrutura::unidades()`), e reproduzem a coluna "Unid. no anúncio" da
 * planilha: CB2 → 2, kit mesa + cadeira → 2, combit mesa + 4 cadeiras → 5.
 */
class EstruturaOfertaComponente extends Model
{
    protected $table = 'estrutura_oferta_componentes';

    public $timestamps = false;

    protected $fillable = ['oferta_id', 'componente_id', 'quantidade'];

    protected $casts = ['quantidade' => 'integer'];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'componente_id');
    }
}
