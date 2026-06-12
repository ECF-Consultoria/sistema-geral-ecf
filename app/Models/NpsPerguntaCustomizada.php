<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pergunta customizada (extra) NPS — Phase 33 D-01/D-02.
 *
 * Admin cria/edita estas perguntas em /nps/configuracao (aba "Perguntas extras")
 * e elas aparecem na survey publica /nps/{token} junto com as 3 perguntas fixas
 * da Phase 31 (estrategista/analista/empresa).
 *
 * Tipos suportados (D-02 LOCKED):
 *  - escala_1_5 — RatingPicker 1..5 (reutiliza componente das fixas)
 *  - texto      — Textarea max 2000 chars
 *  - sim_nao    — 2 botoes "Sim"/"Nao"
 *  - multipla   — Radio group, opcoes definidas em $opcoes (array JSON)
 *
 * Para tipo=multipla, $opcoes carrega array de strings: `["WhatsApp","Email","Telefone"]`.
 * Para os demais tipos, $opcoes deve ser null.
 *
 * @property string $texto
 * @property string $tipo
 * @property array|null $opcoes
 * @property bool $obrigatorio
 * @property int $ordem
 * @property bool $ativa
 */
class NpsPerguntaCustomizada extends Model
{
    protected $table = 'nps_perguntas_customizadas';

    protected $fillable = [
        'texto',
        'tipo',
        'opcoes',
        'obrigatorio',
        'ordem',
        'ativa',
    ];

    protected $casts = [
        'opcoes'      => 'array',
        'obrigatorio' => 'bool',
        'ativa'       => 'bool',
        'ordem'       => 'int',
    ];

    /**
     * Lista canonica de tipos validos — usada pelo Rule::in() do controller
     * e pela UI admin para popular o select.
     */
    public const TIPOS = ['escala_1_5', 'texto', 'sim_nao', 'multipla'];

    /**
     * Respostas dadas a esta pergunta ao longo do tempo.
     *
     * Note: FK em respostas usa pergunta_id (nullable + set null on delete),
     * portanto hard-delete da pergunta nao deleta as respostas — o snapshot
     * de texto/tipo na resposta sustenta o display historico.
     */
    public function respostas(): HasMany
    {
        return $this->hasMany(NpsRespostaCustomizada::class, 'pergunta_id');
    }
}
