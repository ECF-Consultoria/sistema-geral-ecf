<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A aprovação do CLIENTE de um anúncio da Trilha de produtos (4ª entrega, fatia 4e, 04/11/2026 — desenho §19.6, D54):
 * UMA linha viva por anúncio (`pub_produto_id` único). Cada "Mandar ao cliente" reescreve a linha com `rodada` + 1; o
 * histórico (quem mandou, quem aprovou, o que pediu) fica no `activity_log`, não aqui.
 *
 * Estados (string, nunca enum): {@see COM_CLIENTE} → o cliente {@see APROVADO} (o anúncio segue para a fila de
 * publicação) ou pede {@see AJUSTE} (texto para a equipe). Também volta a `ajuste`, com `pedido` nulo, quando a equipe
 * mudou o anúncio depois de enviar e o cliente tentou aprovar (D55).
 *
 * A regra mora em {@see \App\Services\Trilha\AprovacaoDoClienteNaTrilha}; este model só lê e grava a linha.
 */
class TrilhaAprovacao extends Model
{
    public const COM_CLIENTE = 'com_cliente';

    public const AJUSTE = 'ajuste';

    public const APROVADO = 'aprovado';

    public const ESTADOS = [self::COM_CLIENTE, self::AJUSTE, self::APROVADO];

    /** O teto do texto do "Pedir ajuste" do cliente (a coluna é `text`; o serviço confere). */
    public const PEDIDO_MAX = 1000;

    protected $table = 'trilha_aprovacoes';

    protected $fillable = [
        'company_id', 'pub_produto_id', 'item_chave', 'estado', 'rodada', 'revisao_enviada', 'enviado_em', 'enviado_por',
        'decidido_em', 'decidido_por_cliente', 'decidido_por_equipe', 'pedido',
    ];

    protected $casts = [
        'company_id'           => 'integer',
        'pub_produto_id'       => 'integer',
        'rodada'               => 'integer',
        'revisao_enviada'      => 'integer',
        'enviado_em'           => 'datetime',
        'enviado_por'          => 'integer',
        'decidido_em'          => 'datetime',
        'decidido_por_cliente' => 'integer',
        'decidido_por_equipe'  => 'integer',
    ];

    public function pubProduto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'pub_produto_id');
    }

    /** Quem da equipe mandou ao cliente (o `User` com que a aprovação publica — D56). */
    public function remetente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function clienteQueDecidiu(): BelongsTo
    {
        return $this->belongsTo(PortalUsuario::class, 'decidido_por_cliente');
    }

    public function equipeQueDecidiu(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por_equipe');
    }
}
