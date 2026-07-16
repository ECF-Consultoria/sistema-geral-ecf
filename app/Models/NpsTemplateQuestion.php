<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pergunta de um NpsTemplate — Phase 68 v15.0.
 *
 * Modelo do enunciado renderizado no form público. Tipo `escala` é açúcar
 * sintático da UI admin: backend uniforme via `options` (research §5) — na
 * hora de criar a pergunta o admin escolhe `escala` e o CRUD auto-gera 5
 * `NpsTemplateOption` com labels `"1"…"5"` e pesos 1..5 (editáveis depois).
 *
 * A `dimensao` é a chave de agregação do `NpsScoreCalculator` (Phase 69,
 * NPS-B-02): dashboard NPS-E-05 filtra por essa coluna para calcular médias
 * por eixo. `geral` cobre perguntas transversais (ex: comentário final).
 *
 * Referências:
 *  - .planning/research/v15-nps-templates-schema.md §5 (tipo escala|opcoes)
 *  - .planning/research/v15-nps-templates-schema.md §1 (dimensao usada no snapshot)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 *
 * @property int $template_id
 * @property string $texto
 * @property string $tipo
 * @property string $dimensao
 * @property bool $obrigatoria
 * @property int $ordem
 */
class NpsTemplateQuestion extends Model
{
    use HasFactory;

    protected $table = 'nps_template_questions';

    protected $fillable = [
        'template_id',
        'texto',
        'tipo',
        'dimensao',
        'obrigatoria',
        'ordem',
    ];

    protected $casts = [
        'obrigatoria' => 'bool',
        'ordem'       => 'int',
    ];

    // ─── Tipos de pergunta (research §5) ────────────────────────────────
    public const TIPO_ESCALA      = 'escala';
    public const TIPO_OPCOES      = 'opcoes';
    // Ajuste 2026-07-13: reintrodução do tipo texto livre (caixa de texto).
    // Não tem `NpsTemplateOption` associada — o valor digitado pelo respondente
    // é gravado direto em `nps_response_answers.comentario` com
    // `template_option_id=null`, `option_label_snapshot=null` e
    // `option_peso_snapshot=null` (nullable desde a migration
    // 2026_07_13_094007). Não entra em AVG de score porque peso é NULL.
    public const TIPO_TEXTO_LIVRE = 'texto_livre';

    /**
     * Lista canônica dos tipos válidos — usada pelo `Rule::in()` do
     * controller e pela UI admin para popular o select.
     */
    public const TIPOS = [
        self::TIPO_ESCALA,
        self::TIPO_OPCOES,
        self::TIPO_TEXTO_LIVRE,
    ];

    // ─── Dimensões semânticas (research §1) ─────────────────────────────
    public const DIMENSAO_ESTRATEGISTA = 'estrategista';
    public const DIMENSAO_ANALISTA     = 'analista';
    public const DIMENSAO_EMPRESA      = 'empresa';
    public const DIMENSAO_GERAL        = 'geral';
    // Quick task 260716-jps: dimensão "compartilhada". A pergunta salva UMA
    // única dimensão (`ambos`), mas o peso da resposta conta para as DUAS notas
    // de pessoa — Analista E Estrategista. NÃO é uma dimensão de score própria
    // (nunca gera linha em nps_response_scores nem role em assignments): é uma
    // dimensão-FONTE que `dimensoesFonte()` injeta nas notas de estrategista e
    // analista. Ver NpsScoreCalculator + NpsSnapshotService.
    public const DIMENSAO_AMBOS = 'ambos';

    /**
     * Lista canônica das dimensões — usada pelo `Rule::in()` do controller,
     * pelo `NpsScoreCalculator` (WHERE + GROUP BY) e pela UI admin.
     */
    public const DIMENSOES = [
        self::DIMENSAO_ESTRATEGISTA,
        self::DIMENSAO_ANALISTA,
        self::DIMENSAO_AMBOS,
        self::DIMENSAO_EMPRESA,
        self::DIMENSAO_GERAL,
    ];

    /**
     * Mapeamento dimensão → label pt-BR para UI admin (Phase 70) e
     * dashboards (Phase 71). Segue padrão de `Servico::setoresLabels()`.
     */
    public static function dimensoesLabels(): array
    {
        return [
            self::DIMENSAO_ESTRATEGISTA => 'Estrategista',
            self::DIMENSAO_ANALISTA     => 'Analista',
            self::DIMENSAO_AMBOS        => 'Ambos (Analista e Estrategista)',
            self::DIMENSAO_EMPRESA      => 'Empresa',
            self::DIMENSAO_GERAL        => 'Geral',
        ];
    }

    /**
     * Dimensões-FONTE que alimentam a nota de uma dimensão de SCORE (quick task
     * 260716-jps). A nota do Estrategista soma perguntas `estrategista` + `ambos`;
     * a do Analista soma `analista` + `ambos`. Qualquer outra dimensão de score
     * (empresa/geral) só considera ela mesma — "ambos" é exclusivo das duas
     * pessoas, então NUNCA entra em `empresa` (meritocracia da empresa intacta).
     *
     * Fonte ÚNICA da regra "ambos conta pros dois": consumida por
     * `NpsScoreCalculator::compute()`/`contarPerguntasComPeso()` (numerador +
     * divisor) e por `NpsSnapshotService::registrar()` (score_sum congelado).
     * Trocar o filtro cru `= $dimensao` por `whereIn(dimensoesFonte($dimensao))`
     * é o que faz o peso "ambos" pesar nas duas notas sem duplicar a resposta.
     *
     * @param  string  $scoreDimensao  Dimensão de score (estrategista/analista/empresa/...).
     * @return array<int, string>  Dimensões de pergunta que compõem essa nota.
     */
    public static function dimensoesFonte(string $scoreDimensao): array
    {
        return match ($scoreDimensao) {
            self::DIMENSAO_ESTRATEGISTA => [self::DIMENSAO_ESTRATEGISTA, self::DIMENSAO_AMBOS],
            self::DIMENSAO_ANALISTA     => [self::DIMENSAO_ANALISTA, self::DIMENSAO_AMBOS],
            default                     => [$scoreDimensao],
        };
    }

    /**
     * Template dono desta pergunta. Cascade on delete no schema — apagar
     * o template apaga toda a árvore (pergunta → options).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NpsTemplate::class, 'template_id');
    }

    /**
     * Opções de resposta ordenadas por `ordem` ASC. FK viva em
     * `nps_template_options.question_id` cascade — apagar a pergunta
     * apaga suas opções.
     */
    public function options(): HasMany
    {
        return $this->hasMany(NpsTemplateOption::class, 'question_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    /**
     * Alias de `options()` — nome plural pt-BR (deliberadamente na forma
     * `opcaos` que o `Str::plural('opcao')` do Laravel gera; grafia correta
     * em pt-BR seria "opcoes", mas a resolução do `scopeBindings()` usa
     * exatamente `Str::plural(Str::camel($param))` e o parâmetro da rota
     * é `{opcao}` — se renomearmos aqui pra "opcoes", o binding quebra).
     *
     * Usado pelo `scopeBindings()` das rotas triplo-aninhadas de opções
     * (Plan 70-03): Laravel resolve `{opcao}` chamando
     * `$pergunta->opcaos()`. Ambos apontam para a mesma relação; expor
     * os dois nomes evita re-refatoração posterior.
     *
     * @see routes/web.php — Route::put('.../opcoes/{opcao}')->scopeBindings()
     */
    public function opcaos(): HasMany
    {
        return $this->options();
    }
}
