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
    public const TIPO_ESCALA = 'escala';
    public const TIPO_OPCOES = 'opcoes';

    /**
     * Lista canônica dos tipos válidos — usada pelo `Rule::in()` do
     * controller e pela UI admin para popular o select.
     */
    public const TIPOS = [
        self::TIPO_ESCALA,
        self::TIPO_OPCOES,
    ];

    // ─── Dimensões semânticas (research §1) ─────────────────────────────
    public const DIMENSAO_ESTRATEGISTA = 'estrategista';
    public const DIMENSAO_ANALISTA     = 'analista';
    public const DIMENSAO_EMPRESA      = 'empresa';
    public const DIMENSAO_GERAL        = 'geral';

    /**
     * Lista canônica das dimensões — usada pelo `Rule::in()` do controller,
     * pelo `NpsScoreCalculator` (WHERE + GROUP BY) e pela UI admin.
     */
    public const DIMENSOES = [
        self::DIMENSAO_ESTRATEGISTA,
        self::DIMENSAO_ANALISTA,
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
            self::DIMENSAO_EMPRESA      => 'Empresa',
            self::DIMENSAO_GERAL        => 'Geral',
        ];
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
