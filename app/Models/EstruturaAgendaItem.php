<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da agenda — a aba "Planejamento" da planilha.
 *
 * ### Publicação não guarda estado
 * Está feita quando a oferta tem Clássico e Premium que contam. Concluir pela
 * agenda É cadastrar o anúncio, pelo mesmo caminho do cadastro na oferta — por
 * isso agenda e painel não conseguem discordar (na planilha discordavam: CB3
 * "OK" no Planejamento e "Publicar" no Mapeamento).
 *
 * ### Jardinagem guarda
 * É olhar métricas e ajustar, sem anúncio de onde derivar. Um "feito / não
 * feito" por linha, em `concluida_em`.
 */
class EstruturaAgendaItem extends Model
{
    protected $table = 'estrutura_agenda';

    public const ACAO_PUBLICACAO = 'publicacao';
    public const ACAO_JARDINAGEM = 'jardinagem';

    public const ACOES = [
        self::ACAO_PUBLICACAO => 'Publicação',
        self::ACAO_JARDINAGEM => 'Jardinagem',
    ];

    /** "7 dias depois, agende a Jardinagem" — regra de ouro da aula. */
    public const DIAS_ATE_JARDINAGEM = 7;

    protected $fillable = ['oferta_id', 'data', 'acao', 'concluida_em'];

    protected $casts = [
        'data'         => 'date',
        'concluida_em' => 'datetime',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }
}
