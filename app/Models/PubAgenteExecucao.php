<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma execução de PREPARO do agente para um produto (AG-01, 20/10/2026).
 *
 * Os estados descrevem o trabalho de preparo; o andamento da oferta continua em `PubAprovacaoOferta`.
 * Ao chegar à revisão a execução pode terminar enquanto a oferta segue aguardando a pessoa.
 *
 * Viva = em `VIVAS`. Só existe UMA viva por produto da conta: a coluna-sombra `chave_ativa` (unique) vale
 * "conta:produto" enquanto ela vive e NULL depois — é o banco que garante, não o código.
 */
class PubAgenteExecucao extends Model
{
    protected $table = 'pub_agente_execucoes';

    public const ADMITIDA = 'admitida';
    public const PREPARANDO = 'preparando';
    public const AGUARDANDO_APROVACAO = 'aguardando_aprovacao';
    public const CONCLUIDA = 'concluida';
    public const BLOQUEADA = 'bloqueada';
    public const FALHOU = 'falhou';
    public const CANCELADA = 'cancelada';

    public const VIVAS = [self::ADMITIDA, self::PREPARANDO];

    /** Terminadas que, com os mesmos fatos, NÃO devem ser refeitas (devolvem a mesma execução). */
    public const TERMINADAS_COM_RESULTADO = [self::AGUARDANDO_APROVACAO, self::CONCLUIDA];

    public const ORIGEM_AUTOMATICA = 'automatica';
    public const ORIGEM_MANUAL = 'manual';
    public const ORIGEM_AO_SALVAR = 'ao_salvar';

    public const ORIGENS = [self::ORIGEM_AUTOMATICA, self::ORIGEM_MANUAL, self::ORIGEM_AO_SALVAR];

    protected $guarded = ['id'];

    protected $casts = [
        'configuracao_versao' => 'integer',
        'bloqueios' => 'array',
        'heartbeat_em' => 'datetime',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function configuracao(): BelongsTo
    {
        return $this->belongsTo(PubAgenteConfiguracao::class, 'configuracao_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'rascunho_id');
    }

    public function viva(): bool
    {
        return in_array($this->estado, self::VIVAS, true);
    }

    public static function chaveAtiva(string $contaChave, int $produtoId): string
    {
        return "{$contaChave}:{$produtoId}";
    }
}
