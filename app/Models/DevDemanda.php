<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Demanda do time de desenvolvimento — a "fotografia atual" (1 demanda = 1 linha).
 *
 * Status, próxima ação, última atualização e bloqueio NÃO são colunas: vêm da
 * atualização mais recente (`ultimaAtualizacao`). Sem nenhuma atualização, a
 * demanda está em Backlog — o mesmo default da planilha.
 */
class DevDemanda extends Model
{
    use LogsActivity;

    protected $table = 'dev_demandas';

    protected $fillable = [
        'codigo', 'titulo', 'area', 'escopo', 'responsavel_id', 'prioridade',
        'data_entrada', 'prazo', 'observacoes', 'criado_por',
    ];

    protected $casts = [
        'prioridade'   => 'integer',
        'data_entrada' => 'date',
        'prazo'        => 'date',
    ];

    // ─── Status (vêm da atualização) ─────────────────────────────────────────
    public const STATUS_BACKLOG            = 'backlog';
    public const STATUS_A_FAZER            = 'a_fazer';
    public const STATUS_EM_DESENVOLVIMENTO = 'em_desenvolvimento';
    public const STATUS_EM_VALIDACAO       = 'em_validacao';
    public const STATUS_BLOQUEADO          = 'bloqueado';
    public const STATUS_CONCLUIDO          = 'concluido';
    public const STATUS_CANCELADO          = 'cancelado';

    public const STATUS_LABELS = [
        self::STATUS_BACKLOG            => 'Backlog',
        self::STATUS_A_FAZER            => 'A fazer',
        self::STATUS_EM_DESENVOLVIMENTO => 'Em desenvolvimento',
        self::STATUS_EM_VALIDACAO       => 'Em validação',
        self::STATUS_BLOQUEADO          => 'Bloqueado',
        self::STATUS_CONCLUIDO          => 'Concluído',
        self::STATUS_CANCELADO          => 'Cancelado',
    ];

    /** Status que encerram a demanda — saem da fila e das contagens de "abertas". */
    public const STATUS_ENCERRADOS = [self::STATUS_CONCLUIDO, self::STATUS_CANCELADO];

    public const PRIORIDADE_LABELS = [
        0 => 'P0 - Crítica',
        1 => 'P1 - Alta',
        2 => 'P2 - Normal',
        3 => 'P3 - Backlog',
    ];

    // ─── Situação (calculada, nunca digitada) ────────────────────────────────
    public const SITUACAO_CONCLUIDO     = 'concluido';
    public const SITUACAO_CANCELADO     = 'cancelado';
    public const SITUACAO_BLOQUEADO     = 'bloqueado';
    public const SITUACAO_ATRASADA      = 'atrasada';
    public const SITUACAO_PRAZO_PROXIMO = 'prazo_proximo';
    public const SITUACAO_NO_PRAZO      = 'no_prazo';
    public const SITUACAO_SEM_PRAZO     = 'sem_prazo';

    /** "Prazo próximo" = vence em até N dias (regra da planilha). */
    public const DIAS_PRAZO_PROXIMO = 2;

    /** Prefixos sugeridos para o código. */
    public const PREFIXOS = ['DEV', 'ADM', 'MKT', 'MGT'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'titulo', 'area', 'responsavel_id', 'prioridade', 'prazo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $e) => match ($e) {
                'created' => "Demanda dev {$this->codigo} criada",
                'updated' => "Demanda dev {$this->codigo} alterada",
                'deleted' => "Demanda dev {$this->codigo} removida",
                default   => $e,
            });
    }

    // ─── Relações ────────────────────────────────────────────────────────────
    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function atualizacoes(): HasMany
    {
        return $this->hasMany(DevDemandaAtualizacao::class, 'dev_demanda_id');
    }

    /** A mais recente por ORDEM DE REGISTRO (maior id), como o XLOOKUP(...,-1) da planilha. */
    public function ultimaAtualizacao(): HasOne
    {
        return $this->hasOne(DevDemandaAtualizacao::class, 'dev_demanda_id')->latestOfMany('id');
    }

    public function reunioes(): BelongsToMany
    {
        return $this->belongsToMany(DevReuniao::class, 'dev_demanda_reuniao', 'dev_demanda_id', 'dev_reuniao_id');
    }

    // ─── Campos derivados ────────────────────────────────────────────────────
    public function statusAtual(): string
    {
        return $this->ultimaAtualizacao?->status ?? self::STATUS_BACKLOG;
    }

    public function estaEncerrada(): bool
    {
        return in_array($this->statusAtual(), self::STATUS_ENCERRADOS, true);
    }

    public function estaBloqueada(): bool
    {
        return (bool) $this->ultimaAtualizacao?->bloqueado;
    }

    /** Dias além do prazo. Zero quando encerrada, sem prazo ou dentro do prazo. */
    public function diasEmAtraso(CarbonInterface $hoje): int
    {
        if ($this->estaEncerrada() || ! $this->prazo) {
            return 0;
        }

        return max(0, (int) $this->prazo->copy()->startOfDay()->diffInDays($hoje->copy()->startOfDay(), false));
    }

    /**
     * Situação — mesma cascata da coluna "Situação" da planilha:
     * encerrada → bloqueada → sem prazo → atrasada → prazo próximo → no prazo.
     */
    public function situacao(CarbonInterface $hoje): string
    {
        $status = $this->statusAtual();

        if ($status === self::STATUS_CONCLUIDO) {
            return self::SITUACAO_CONCLUIDO;
        }
        if ($status === self::STATUS_CANCELADO) {
            return self::SITUACAO_CANCELADO;
        }
        if ($this->estaBloqueada() || $status === self::STATUS_BLOQUEADO) {
            return self::SITUACAO_BLOQUEADO;
        }
        if (! $this->prazo) {
            return self::SITUACAO_SEM_PRAZO;
        }
        if ($this->diasEmAtraso($hoje) > 0) {
            return self::SITUACAO_ATRASADA;
        }
        // `diffInDays` do Carbon 3 é sinalizado: positivo = prazo no futuro.
        $diasAtePrazo = (int) $hoje->copy()->startOfDay()->diffInDays($this->prazo->copy()->startOfDay(), false);

        return $diasAtePrazo <= self::DIAS_PRAZO_PROXIMO
            ? self::SITUACAO_PRAZO_PROXIMO
            : self::SITUACAO_NO_PRAZO;
    }

    /**
     * Faixa de ordenação da fila ("Minha Semana"): quanto menor, mais urgente.
     * 1 bloqueada · 2 P0 · 3 P1 · 4 atrasada · 5 em validação · 6 em desenvolvimento · 7 resto.
     * Bloqueio vem primeiro porque destravar é a primeira tarefa do dia.
     */
    public function faixaDaFila(CarbonInterface $hoje): int
    {
        $situacao = $this->situacao($hoje);
        $status   = $this->statusAtual();

        return match (true) {
            $situacao === self::SITUACAO_BLOQUEADO          => 1,
            $this->prioridade === 0                         => 2,
            $this->prioridade === 1                         => 3,
            $situacao === self::SITUACAO_ATRASADA           => 4,
            $status === self::STATUS_EM_VALIDACAO           => 5,
            $status === self::STATUS_EM_DESENVOLVIMENTO     => 6,
            default                                         => 7,
        };
    }

    /**
     * Próximo código livre de um prefixo: maior sufixo numérico + 1, com 2 dígitos.
     * Ex.: DEV-01..DEV-24 existentes → DEV-25.
     */
    public static function proximoCodigo(string $prefixo): string
    {
        $prefixo = strtoupper(trim($prefixo));

        $maior = static::query()
            ->where('codigo', 'like', $prefixo . '-%')
            ->pluck('codigo')
            ->map(fn (string $c) => preg_match('/^' . preg_quote($prefixo, '/') . '-(\d+)$/', $c, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('%s-%02d', $prefixo, $maior + 1);
    }
}
