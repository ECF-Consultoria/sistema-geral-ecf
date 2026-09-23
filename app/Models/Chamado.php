<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Chamado — pedido de ajuda de um colaborador ao time de desenvolvimento.
 *
 * Não é demanda: só vira trabalho técnico (DevDemanda) quando a equipe decide
 * ("Criar demanda"), e aí fica ligado a ela por `dev_demanda_id` (único).
 * O chamado continua sendo a conversa com quem pediu; a demanda, o trabalho.
 */
class Chamado extends Model
{
    protected $table = 'chamados';

    protected $fillable = [
        'codigo', 'solicitante_id', 'solicitante_nome', 'solicitante_email', 'responsavel_id',
        'area', 'tipo', 'impacto', 'titulo', 'descricao', 'contexto_tentando', 'contexto_aconteceu',
        'contexto_esperado', 'status', 'dev_demanda_id', 'resolucao', 'resolvido_em', 'ultima_interacao_em',
    ];

    protected $casts = [
        'resolvido_em'        => 'datetime',
        'ultima_interacao_em' => 'datetime',
    ];

    // ─── Status ──────────────────────────────────────────────────────────────
    public const STATUS_ABERTO                 = 'aberto';
    public const STATUS_EM_TRIAGEM             = 'em_triagem';
    public const STATUS_EM_ATENDIMENTO         = 'em_atendimento';
    public const STATUS_AGUARDANDO_SOLICITANTE = 'aguardando_solicitante';
    public const STATUS_RESOLVIDO              = 'resolvido';
    public const STATUS_CANCELADO              = 'cancelado';

    public const STATUS_LABELS = [
        self::STATUS_ABERTO                 => 'Aberto',
        self::STATUS_EM_TRIAGEM             => 'Em triagem',
        self::STATUS_EM_ATENDIMENTO         => 'Em atendimento',
        self::STATUS_AGUARDANDO_SOLICITANTE => 'Aguardando solicitante',
        self::STATUS_RESOLVIDO              => 'Resolvido',
        self::STATUS_CANCELADO              => 'Cancelado',
    ];

    public const STATUS_ENCERRADOS = [self::STATUS_RESOLVIDO, self::STATUS_CANCELADO];

    // ─── Tipo e impacto (linguagem de quem pede, não do time dev) ────────────
    public const TIPO_LABELS = [
        'problema'         => 'Problema / Erro',
        'duvida'           => 'Dúvida / Suporte',
        'melhoria'         => 'Melhoria',
        'acesso'           => 'Acesso / Configuração',
        'nova_solicitacao' => 'Nova solicitação',
        'outro'            => 'Outro',
    ];

    public const IMPACTO_LABELS = [
        'normal'         => 'Consigo continuar trabalhando normalmente',
        'atrapalha'      => 'Está atrapalhando meu trabalho',
        'impedido'       => 'Estou impedido de continuar',
        'varias_pessoas' => 'Está afetando várias pessoas',
    ];

    /**
     * Prioridade SUGERIDA para a equipe (P0–P3) a partir do impacto relatado.
     * É só uma dica na triagem: a prioridade da demanda é sempre decidida pela equipe.
     */
    public const PRIORIDADE_SUGERIDA = [
        'varias_pessoas' => 0,
        'impedido'       => 1,
        'atrapalha'      => 2,
        'normal'         => 3,
    ];

    public const VISIBILIDADE_PUBLICA = 'publica';
    public const VISIBILIDADE_INTERNA = 'interna';

    // ─── Relações ────────────────────────────────────────────────────────────
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(DevDemanda::class, 'dev_demanda_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(ChamadoMensagem::class, 'chamado_id');
    }

    /** Última mensagem pública (para a lista) — uma consulta, sem carregar a conversa inteira. */
    public function ultimaMensagemPublica(): HasOne
    {
        return $this->hasOne(ChamadoMensagem::class, 'chamado_id')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('visibilidade', self::VISIBILIDADE_PUBLICA));
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(ChamadoAnexo::class, 'chamado_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ChamadoEvento::class, 'chamado_id');
    }

    // ─── Regras ──────────────────────────────────────────────────────────────
    public function estaEncerrado(): bool
    {
        return in_array($this->status, self::STATUS_ENCERRADOS, true);
    }

    /** Equipe de desenvolvimento: admin ou quem tem o cargo Dev (`users.is_dev`). */
    public static function ehEquipe(User $user): bool
    {
        return $user->isAdmin() || $user->isAdminDev();
    }

    /** Quem pode ser responsável por um chamado: usuários ativos com o cargo Dev. */
    public static function devsDisponiveis()
    {
        return User::query()->where('active', true)->where('is_dev', true)->orderBy('name')->get(['id', 'name']);
    }
}
