<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que a configuração do agente valia numa versão (AG-01). Só é escrita, nunca atualizada. */
class PubAgenteConfiguracaoVersao extends Model
{
    protected $table = 'pub_agente_configuracao_versoes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'versao' => 'integer',
        'dados' => 'array',
        'criada_em' => 'datetime',
    ];

    public function configuracao(): BelongsTo
    {
        return $this->belongsTo(PubAgenteConfiguracao::class, 'configuracao_id');
    }
}
