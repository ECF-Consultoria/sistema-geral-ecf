<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O **conjunto de fotos compartilhado** de um grupo de variação do rascunho (Fase 186).
 *
 * Um conjunto por (rascunho × grupo de foto): um por valor de eixo que define foto
 * (`definesPicture`), mais o `GENERAL` da galeria geral — a conta de
 * `ResolvedorGruposImagem::chaveDoGrupo()`. **3 cores que definem foto = 4 conjuntos.**
 *
 * ═══ POR QUE O COMPARTILHAMENTO CLÁSSICO↔PREMIUM É ESTRUTURAL ══════════════════════════════
 *
 * A aprovação da foto mora em `pub_aprovacao_imagens.conjunto_id`, e o conjunto é do
 * **rascunho** (unique `rascunho_id` + `grupo_hash`). O rascunho tem os dois alvos
 * (`gold_special` = Clássico, `gold_pro` = Premium), então as duas ofertas leem o MESMO
 * conjunto — **não existe coluna onde elas pudessem divergir**. Aprovar uma foto uma vez vale
 * para as duas, sem rotina de propagação.
 *
 * ⚠️ **Decisão do usuário, 14/10/2026: o bloqueio de fotos considera TODOS os grupos do
 * rascunho.** A oferta só fica aprovável com as fotos de todas as variações **e** as gerais
 * conferidas. Aprovação cor por cor foi oferecida e RECUSADA: aprovar já publica e não há
 * desfazer, então foto não conferida não pode ir ao ar. ➡️ A relação oferta→conjunto é
 * **1:N** e lê-se por UMA consulta (`doRascunho()`), nunca uma por grupo.
 *
 * ⚠️ **Nenhum contador denormalizado.** `aprovadas()`, `pendentes()` e `comAlerta()` CONTAM;
 * `fotos_aprovadas`/`fotos_no_conjunto` não existem como coluna de propósito — contador
 * denormalizado é a classe de bug que faz a tela dizer "3 de 4" com 5 fotos no banco.
 *
 * ⚠️ `ofertas()` **não existe aqui**: `pub_aprovacao_ofertas` é do plano 186-04, e a oferta
 * não escolhe conjunto (ver acima) — a travessia certa é por `rascunho_id`.
 */
class PubAprovacaoConjunto extends Model
{
    protected $table = 'pub_aprovacao_conjuntos';

    /** O grupo da galeria geral. Espelha `ResolvedorGruposImagem::GERAL` e o default da coluna. */
    public const GERAL = 'GENERAL';

    protected $guarded = ['id'];

    protected $casts = [
        'rascunho_id' => 'integer',
        'principal_imagem_id' => 'integer',
        'tocado' => 'boolean',
    ];

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'rascunho_id');
    }

    /** As imagens deste grupo NA REVISÃO (não são as `pub_imagens` cruas). */
    public function imagens(): HasMany
    {
        return $this->hasMany(PubAprovacaoImagem::class, 'conjunto_id');
    }

    /** A capa do grupo — a `pub_imagens` crua apontada por `principal_imagem_id`. */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(PubImagem::class, 'principal_imagem_id');
    }

    /**
     * TODOS os conjuntos de um rascunho, em UMA consulta — é por aqui que o bloqueio de fotos
     * de QUALQUER oferta do rascunho é calculado (decisão do usuário de 14/10).
     *
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public static function doRascunho(int $rascunhoId)
    {
        return static::query()->where('rascunho_id', $rascunhoId);
    }

    public function ehGeral(): bool
    {
        return $this->grupo_chave === self::GERAL;
    }

    /** CONTA, não lê coluna. */
    public function aprovadas(): int
    {
        return $this->imagens()->where('estado', PubAprovacaoImagem::APROVADA)->count();
    }

    /** CONTA, não lê coluna. */
    public function pendentes(): int
    {
        return $this->imagens()->where('estado', PubAprovacaoImagem::PENDENTE)->count();
    }

    /**
     * Quantas imagens deste conjunto **ainda pedem atenção**: verificação automática acima de
     * `ok` numa imagem que ninguém decidiu ainda. CONTA, não lê coluna.
     *
     * ⚠️ O filtro por `PENDENTE` é parte da definição, não otimização: o MESMO alerta `high`
     * numa imagem já APROVADA **não** conta. Quem olhou já decidiu com o alerta na tela, e
     * manter o contador aceso depois disso faria o painel pedir para sempre uma revisão que já
     * aconteceu. Alerta de imagem decidida continua GRAVADO (é histórico) — só deixa de contar.
     */
    public function comAlerta(): int
    {
        return $this->imagens()
            ->where('estado', PubAprovacaoImagem::PENDENTE)
            ->whereHas('alertas', fn ($q) => $q->where('severidade', '!=', PubAprovacaoImagemAlerta::OK))
            ->count();
    }
}
