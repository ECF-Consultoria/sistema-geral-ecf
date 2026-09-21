<?php

namespace App\Models;

use App\Contracts\ContaMercadoLivre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma geração de análise de anúncio por IA (metodologia MAG T8, Parte 1).
 *
 * @see \App\Services\Ia\AnaliseAnuncioService
 */
class MlAnuncioIaAnalise extends Model
{
    protected $table = 'ml_anuncio_ia_analises';

    public const STATUS_PENDENTE  = 'pendente';
    public const STATUS_RODANDO   = 'rodando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_ERRO      = 'erro';

    /** Estados em que ainda vale a pena o front continuar perguntando. */
    public const STATUS_EM_ANDAMENTO = [self::STATUS_PENDENTE, self::STATUS_RODANDO];

    protected $fillable = [
        'company_id', 'mlb_empresa_id', 'user_id',
        'produto', 'loja', 'specs',
        'status', 'erro_mensagem', 'modelo',
        'tokens_entrada', 'tokens_saida', 'duracao_ms', 'tentativas',
        'resultado', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'resultado'   => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function emAndamento(): bool
    {
        return in_array($this->status, self::STATUS_EM_ANDAMENTO, true);
    }

    /** Títulos sugeridos, já com a contagem real de caracteres conferida aqui. */
    public function titulos(): array
    {
        return collect($this->resultado['titulos'] ?? [])
            ->map(fn ($t) => [
                'texto'      => (string) ($t['texto'] ?? ''),
                // NUNCA confiar na contagem que o modelo declara: ele erra.
                // Medimos aqui, e é esta medida que a tela mostra.
                'caracteres' => mb_strlen((string) ($t['texto'] ?? '')),
            ])
            ->filter(fn ($t) => $t['texto'] !== '')
            ->values()
            ->all();
    }

    public function descricao(): ?string
    {
        return $this->resultado['descricao'] ?? null;
    }

    public function analise(): array
    {
        return $this->resultado['analise'] ?? [];
    }
}
