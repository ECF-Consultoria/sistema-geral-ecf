<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fila de publicação em lote de UMA conta do Mercado Livre (10/10/2026, learnings publicador-ml §20).
 *
 * Anda em RODADAS: até `produtos_por_rodada` produtos (Clássico + Premium, todas as cores) começam juntos,
 * e a rodada seguinte vem `intervalo_minutos` depois do início desta — nunca antes de ela terminar —,
 * dentro da janela opcional. `rodada_iniciada_em`/`rodada_inicios` guardam a rodada em curso. Quem anda a
 * fila é o `publicador:fila-publicacao` (todo minuto). Viva = `ativa` ou `pausada`, e só existe UMA viva
 * por conta: a coluna-sombra `conta_ativa` (unique) vale `conta_chave` enquanto ela vive e NULL depois —
 * é o banco que garante, não o código.
 *
 * `criada_por` é quem agendou e é o ATOR das publicações (`AtorDoPortal::daEquipe`): a tarefa
 * pós-publicação mostra essa pessoa como quem publicou.
 */
class PubFilaPublicacao extends Model
{
    protected $table = 'pub_filas_publicacao';

    public const ATIVA = 'ativa';
    public const PAUSADA = 'pausada';
    public const CONCLUIDA = 'concluida';
    public const CANCELADA = 'cancelada';

    /** As que ainda andam ou podem voltar a andar (uma por conta). */
    public const VIVAS = [self::ATIVA, self::PAUSADA];

    protected $guarded = ['id'];

    protected $casts = [
        'intervalo_minutos' => 'integer',
        'produtos_por_rodada' => 'integer',
        'rodada_inicios' => 'integer',
        'proximo_em' => 'datetime',
        'rodada_iniciada_em' => 'datetime',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    /** Quantos produtos começam juntos numa rodada (nunca menos de 1). */
    public function porRodada(): int
    {
        return max(1, (int) ($this->produtos_por_rodada ?? 1));
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PubFilaPublicacaoItem::class, 'fila_id')->orderBy('posicao')->orderBy('id');
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function viva(): bool
    {
        return in_array($this->status, self::VIVAS, true);
    }

    /** A janela em `HH:MM` (o MariaDB devolve `HH:MM:SS`); null = sem janela. */
    public function janela(): ?array
    {
        $inicio = self::horaCurta($this->janela_inicio);
        $fim = self::horaCurta($this->janela_fim);

        return $inicio !== null && $fim !== null && $inicio !== $fim ? ['inicio' => $inicio, 'fim' => $fim] : null;
    }

    public static function horaCurta(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return preg_match('/^(\d{2}):(\d{2})/', $texto, $m) ? "{$m[1]}:{$m[2]}" : null;
    }
}
