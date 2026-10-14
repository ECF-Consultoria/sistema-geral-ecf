<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um **defeito marcado** numa foto de IA (Fase 186, DADO-08): os 6 chips que a pessoa liga
 * quando a imagem gerada tem um problema concreto.
 *
 * ⚠️ **A presença da linha É a marcação.** Não há coluna `marcado` booleana de propósito: ela
 * permitiria duas verdades para o mesmo fato (linha ausente × linha com `marcado = false`).
 * Desmarcar o chip APAGA a linha. E é por isso que, ao contrário da fidelidade, não há coluna
 * `resultado`: o critério de fidelidade tem dois veredictos (`confere`/`diverge`), o defeito
 * tem um (está presente).
 *
 * ⚠️⚠️ **NÃO HÁ ENUM COMPARTILHADO ENTRE PHP E JS NESTE PROJETO** — a sincronia é MANUAL e é
 * a convenção declarada no `CLAUDE.md`. As 6 chaves e os 6 rótulos abaixo são o espelho de
 * `DEFEITOS` em `resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:193-200`,
 * copiados byte a byte em 14/10/2026. **O teste do plano 186-09 confere as duas listas uma
 * contra a outra** — é ele que pega a divergência se alguém mexer num lado só.
 *
 * ⚠️ As chaves `deform` e `dup` são abreviações de palavra pt-BR (deformação, duplicada), não
 * de inglês — a mesma convenção do `vocabularioDoPortao.jsx`. Chave de dado não vai para a
 * tela: o que a tela mostra é o rótulo.
 */
class PubAprovacaoDefeito extends Model
{
    protected $table = 'pub_aprovacao_defeitos';

    /**
     * Os 6 defeitos: chave de dado → rótulo pt-BR da tela.
     *
     * Espelho manual de `DEFEITOS` do `vocabularioDoPortao.jsx` (ver docblock da classe).
     */
    public const DEFEITOS = [
        'deform' => 'Deformação',
        'dup' => 'Peça duplicada',
        'falta' => 'Peça ausente',
        'texto' => 'Texto ilegível',
        'recorte' => 'Recorte ruim',
        'inventado' => 'Elemento inventado pela IA',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'aprovacao_imagem_id' => 'integer',
        'quem_id' => 'integer',
        'quando' => 'datetime',
    ];

    public function aprovacaoImagem(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoImagem::class, 'aprovacao_imagem_id');
    }

    public function quem(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quem_id');
    }

    /** O rótulo em pt-BR da tela; chave desconhecida devolve a própria chave, nunca vazio. */
    public function rotulo(): string
    {
        return self::DEFEITOS[$this->defeito] ?? (string) $this->defeito;
    }
}
