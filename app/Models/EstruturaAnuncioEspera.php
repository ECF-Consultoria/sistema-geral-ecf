<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anúncio colado que não achou oferta — a área de espera da colagem.
 *
 * A aula manda colar "o SKU e o tipo" e avisa que "o SKU precisa ser igual ao
 * da Lista SKUs", porque divergir é o normal. A linha que não casa não pode ir
 * para `estrutura_anuncios` (sem oferta) nem sumir: o painel passaria a cobrar
 * publicação do que já está no ar.
 *
 * Sai daqui por vínculo manual, por "criar oferta", por descarte — ou sozinha,
 * quando uma ESCRITA faz o SKU dela casar com uma única oferta
 * (`EstruturaOfertaService::varrerEspera()`). Nunca num GET.
 */
class EstruturaAnuncioEspera extends Model
{
    protected $table = 'estrutura_anuncios_espera';

    public const MOTIVO_SEM_OFERTA   = 'sem_oferta';
    public const MOTIVO_SKU_REPETIDO = 'sku_repetido';
    public const MOTIVO_SEM_SKU      = 'sem_sku';

    public const MOTIVOS = [
        self::MOTIVO_SEM_OFERTA   => 'Nenhuma oferta com este SKU',
        self::MOTIVO_SKU_REPETIDO => 'Mais de uma oferta com este SKU',
        self::MOTIVO_SEM_SKU      => 'Veio sem SKU',
    ];

    protected $fillable = [
        'company_id', 'sku_colado', 'motivo', 'tipo', 'catalogo', 'kit_virtual',
        'status', 'codigo_mlb', 'titulo',
    ];

    protected $casts = [
        'catalogo'    => 'boolean',
        'kit_virtual' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Os campos do anúncio, prontos para virar `EstruturaAnuncio`. */
    public function dadosDoAnuncio(): array
    {
        return $this->only(['tipo', 'catalogo', 'kit_virtual', 'status', 'codigo_mlb', 'titulo']);
    }
}
