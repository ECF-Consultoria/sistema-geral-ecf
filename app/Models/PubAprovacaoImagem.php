<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O estado de uma foto **NA REVISÃO** do portão (Fase 186, DADO-05).
 *
 * ⚠️⚠️ **ISTO NÃO É `PubImagem::$upload_status`.** Aquele eixo é de **UPLOAD**
 * (`PubImagem::PENDENTE = 'pending'` / `ENVIADA = 'uploaded'` / `FALHOU = 'failed'`) e diz se o
 * byte chegou ao Mercado Livre; este é de **REVISÃO** e diz se uma pessoa conferiu a foto. Uma
 * foto pode estar `uploaded` no upload e `rejected` na revisão ao mesmo tempo, e isso é
 * correto. **DADO-05 proíbe reusar ou estender o eixo de upload** — os dois nunca se misturam,
 * nas duas direções.
 *
 * ⚠️ **Não há coluna de "vínculo com variação" aqui, e não é lacuna:** a variação JÁ É o
 * conjunto (`PubAprovacaoConjunto::$grupo_hash`). Uma coluna de variação na imagem poderia
 * divergir do conjunto que a contém.
 *
 * ⚠️ **Não há coluna de "original de referência" aqui:** essa relação é N:N (uma gerada pode
 * referenciar várias originais) e vive em `pub_imagem_referencias` — **plano 186-06, DADO-15**,
 * e só é ESCRITA nas Fases 192/194.
 *
 * ⚠️ **`posicao` NULL = fora da publicação**; a principal é sempre a `posicao` 0 (IMG-07).
 * `fora = true` tira a foto da publicação explicitamente — as duas condições são conferidas
 * por `noConjuntoFinal()`, nunca só uma.
 */
class PubAprovacaoImagem extends Model
{
    protected $table = 'pub_aprovacao_imagens';

    // ─── Os 5 estados de REVISÃO (coluna `estado`, string(14), sem enum por MIG-04) ───
    public const PENDENTE = 'pending';
    public const APROVADA = 'approved';
    public const REPROVADA = 'rejected';
    public const REGENERANDO = 'regenerating';
    public const SUBSTITUIDA = 'replaced';

    /** Os estados que continuam disputando lugar na publicação (reprovada/substituída saem). */
    public const NO_CONJUNTO_FINAL = [self::PENDENTE, self::APROVADA];

    // ─── De onde a foto veio (coluna `tipo`, string(10)) ───
    public const TIPO_ORIGINAL = 'original';
    public const TIPO_IA = 'ia';

    protected $guarded = ['id'];

    protected $casts = [
        'conjunto_id' => 'integer',
        'imagem_id' => 'integer',
        'posicao' => 'integer',
        'fora' => 'boolean',
        'nova' => 'boolean',
        'quem_id' => 'integer',
        'quando' => 'datetime',
    ];

    public function conjunto(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoConjunto::class, 'conjunto_id');
    }

    /** A `pub_imagens` crua — é ali que vive o eixo de UPLOAD, que este model não toca. */
    public function imagem(): BelongsTo
    {
        return $this->belongsTo(PubImagem::class, 'imagem_id');
    }

    public function quem(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quem_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(PubAprovacaoImagemAlerta::class, 'aprovacao_imagem_id');
    }

    public function fidelidade(): HasMany
    {
        return $this->hasMany(PubAprovacaoFidelidade::class, 'aprovacao_imagem_id');
    }

    public function defeitos(): HasMany
    {
        return $this->hasMany(PubAprovacaoDefeito::class, 'aprovacao_imagem_id');
    }

    /**
     * IMG-09 — enquanto a foto está sendo regerada, a tela não aceita ação sobre ela: aprovar
     * uma imagem que vai ser substituída em segundos aprovaria a foto errada.
     */
    public function aceitaAcao(): bool
    {
        return $this->estado !== self::REGENERANDO;
    }

    /**
     * Entra na publicação? Estado vivo, `posicao` preenchida (NULL = fora) e `fora` falso — as
     * três, nunca uma só.
     */
    public function noConjuntoFinal(): bool
    {
        return in_array($this->estado, self::NO_CONJUNTO_FINAL, true)
            && $this->posicao !== null
            && ! $this->fora;
    }

    public function ehPrincipal(): bool
    {
        return $this->posicao === 0 && ! $this->fora;
    }

    public function ehDeIa(): bool
    {
        return $this->tipo === self::TIPO_IA;
    }

    /** Tem verificação automática acima de `ok`? */
    public function temAlerta(): bool
    {
        return $this->alertas()->where('severidade', '!=', PubAprovacaoImagemAlerta::OK)->exists();
    }
}
