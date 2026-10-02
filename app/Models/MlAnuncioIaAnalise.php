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

    /**
     * Passou disto em andamento, não vai terminar: vira erro.
     *
     * O job tem prazo de 9 min por tentativa e só 2 tentativas; uma análise
     * viva além de 15 min é worker que morreu no meio ou fila que nunca pegou.
     * Sem este teto a tela perguntava "e aí?" para sempre — foi o "loop
     * infinito" relatado em 29/09/2026.
     */
    public const LIMITE_MINUTOS = 15;

    protected $fillable = [
        'company_id', 'mlb_empresa_id', 'user_id',
        'produto', 'loja', 'specs',
        'status', 'etapa', 'erro_mensagem', 'modelo',
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

    /**
     * Em andamento há mais de LIMITE_MINUTOS? Então nunca vai terminar.
     *
     * Conta do `created_at`, não do `started_at`: análise que ficou em
     * "pendente" é justamente a que a fila não pegou, e ela nem tem started_at.
     */
    public function travada(): bool
    {
        return $this->emAndamento()
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::LIMITE_MINUTOS));
    }

    /**
     * Encerra como erro a análise travada, com uma mensagem que diz ONDE
     * travou. O que já foi gerado fica — meia análise vale mais que nenhuma.
     */
    public function encerrarSeTravada(): void
    {
        if (! $this->travada()) {
            return;
        }

        $this->update([
            'status'        => self::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $this->status === self::STATUS_PENDENTE
                ? 'A geração não saiu da fila em ' . self::LIMITE_MINUTOS . ' minutos (worker parado?). Tente novamente.'
                : 'A geração passou de ' . self::LIMITE_MINUTOS . ' minutos sem terminar e foi encerrada. Tente novamente — o que já ficou pronto foi mantido.',
            'finished_at'   => now(),
        ]);
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
                // Conferidos no servidor pelo AnaliseAnuncioService. Antes
                // eram descartados aqui e a tela marcava TODO título como
                // fora da regra.
                'dentro_da_regra' => (bool) ($t['dentro_da_regra'] ?? false),
                'tem_loja'        => (bool) ($t['tem_loja'] ?? false),
            ])
            ->filter(fn ($t) => $t['texto'] !== '')
            ->values()
            ->all();
    }

    /**
     * Resumo da ficha que a IA preencheu, para a tela dizer o que ficou
     * pronto e o que falta. `null` enquanto a etapa não rodou.
     */
    public function resumoFicha(): ?array
    {
        $f = $this->resultado['ficha'] ?? null;

        if (! is_array($f)) {
            return null;
        }

        return [
            'category_id'           => $f['category_id'] ?? null,
            'caminho'               => $f['caminho'] ?? ($f['categoria_nome'] ?? null),
            'titulo'                => $f['titulo'] ?? null,
            'atributos'             => count($f['atributos'] ?? []),
            'obrigatorios_total'    => (int) ($f['obrigatorios_total'] ?? 0),
            'obrigatorios_faltando' => array_values($f['obrigatorios_faltando'] ?? []),
            'variacoes'             => count($f['variacoes'] ?? []),
            'pacote_completo'       => ! empty($f['pacote']['peso_g']) && ! empty($f['pacote']['altura_cm'])
                && ! empty($f['pacote']['largura_cm']) && ! empty($f['pacote']['comprimento_cm']),
            'aviso'                 => $f['aviso'] ?? null,
        ];
    }

    /** Id do rascunho gravado pela etapa final (null enquanto não gravou). */
    public function rascunhoId(): ?int
    {
        $id = $this->resultado['rascunho_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * D14: destino quando a geração foi pedida de dentro do Publicador —
     * `{tipo: 'publicador', produto_id, rascunho_id, revisao_base, substituir}`.
     * Null no caminho antigo por empresa (wizard escondido, D22).
     */
    public function destinoPublicador(): ?array
    {
        $d = $this->resultado['destino'] ?? null;

        return is_array($d) && ($d['tipo'] ?? null) === 'publicador' ? $d : null;
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
