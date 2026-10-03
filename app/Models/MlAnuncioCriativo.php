<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um criativo de imagem por IA do publicador ML (Fase 160, Creative Engine).
 *
 * UMA tabela para o par referência→imagem: ver docblock da migration
 * `2026_10_02_120000_create_ml_anuncio_criativos_table.php` para a decisão
 * de nomenclatura e de cardinalidade (esta fase gera uma imagem por pedido).
 *
 * @see \App\Services\Creative\ReferenciaEfemeraService
 */
class MlAnuncioCriativo extends Model
{
    protected $table = 'ml_anuncio_criativos';

    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_RODANDO  = 'rodando';
    public const STATUS_PRONTO   = 'pronto';
    public const STATUS_APROVADO = 'aprovado';
    public const STATUS_ERRO     = 'erro';

    /** Estados em que ainda vale a pena o front continuar perguntando. */
    public const STATUS_EM_ANDAMENTO = [self::STATUS_PENDENTE, self::STATUS_RODANDO];

    /**
     * Passou disto em andamento, não vai terminar: vira erro.
     *
     * O job de geração (160-02) tem prazo de ~4 min por tentativa e 2
     * tentativas; 12 minutos cobre fila lenta sem deixar a tela perguntando
     * "e aí?" para sempre (mesmo raciocínio de MlAnuncioIaAnalise::LIMITE_MINUTOS,
     * só que com um job mais curto).
     */
    public const LIMITE_MINUTOS = 12;

    protected $fillable = [
        'token', 'company_id', 'mlb_empresa_id', 'rascunho_id', 'user_id',
        'kit_id', 'slot_indice', 'slot_plano',
        'slot', 'status', 'etapa', 'erro_mensagem', 'render_mode',
        'provider', 'modelo', 'tentativas', 'regeneracoes', 'latencia_ms',
        'contexto', 'truth', 'prompt',
        'regenerar_motivos',
        'referencias', 'referencias_apagadas_em',
        'imagem_path', 'imagem_mime', 'imagem_bytes',
        'aprovado_por', 'aprovado_em',
        'ml_picture_id', 'ml_picture_url',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'contexto'                 => 'array',
        'truth'                    => 'array',
        'slot_plano'               => 'array',
        // Quick 261003-l8o — histórico de auditoria das regenerações (quem
        // pediu, quando, texto opcional) — NUNCA entra na whitelist de
        // `criativoKitStatus()` (T-L8O-02).
        'regenerar_motivos'        => 'array',
        'referencias'              => 'array',
        'referencias_apagadas_em'  => 'datetime',
        'aprovado_em'              => 'datetime',
        'started_at'               => 'datetime',
        'finished_at'              => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function mlbEmpresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(MlAnuncioRascunho::class, 'rascunho_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    /** Kit de 7 (Fase 161) ao qual este criativo pertence — nulo na forma da Fase 160. */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(MlAnuncioCriativoKit::class, 'kit_id');
    }

    /**
     * O criativo que PORTA a foto de referência em disco (Decisão 1b do
     * 161-01-PLAN.md): quando este criativo é um dos 7 slots de um kit, o
     * portador é outro registro (`slot = 'referencia'`); quando não há kit
     * (forma da Fase 160, ou o próprio portador), o portador é ele mesmo.
     */
    public function portadorDeReferencia(): self
    {
        return $this->kit?->criativoReferencia ?? $this;
    }

    public function emAndamento(): bool
    {
        return in_array($this->status, self::STATUS_EM_ANDAMENTO, true);
    }

    /**
     * Em andamento há mais de LIMITE_MINUTOS? Então nunca vai terminar.
     *
     * Conta do `created_at`, não do `started_at`: criativo que nunca saiu
     * da fila não tem `started_at` (mesmo raciocínio de MlAnuncioIaAnalise).
     */
    public function travada(): bool
    {
        return $this->emAndamento()
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::LIMITE_MINUTOS));
    }

    /**
     * Encerra como erro o criativo travado, com mensagem que diz ONDE travou
     * (fila x geração) — mesma disciplina de MlAnuncioIaAnalise::encerrarSeTravada().
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
                : 'A geração passou de ' . self::LIMITE_MINUTOS . ' minutos sem terminar e foi encerrada. Tente novamente.',
            'finished_at'   => now(),
        ]);
    }

    /**
     * Referências ainda vivas no disco (array de metadados) — `[]` quando já
     * foram apagadas (FOTO-03, varredura/aprovação em 160-04). Nunca devolve
     * bytes: só o que `ReferenciaEfemeraService::guardar()` grava (path/mime/
     * bytes/nome/hash).
     */
    public function referenciasVivas(): array
    {
        if ($this->referencias_apagadas_em !== null) {
            return [];
        }

        return $this->referencias ?? [];
    }
}
