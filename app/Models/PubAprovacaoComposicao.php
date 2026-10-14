<?php

namespace App\Models;

use App\Support\Publicador\ConferenciaDeFrete;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A COMPOSIÇÃO FINANCEIRA **CONGELADA NO ATO** — a face auditável da origem por linha (DADO-13).
 *
 * A decisão de schema completa está no docblock da migration
 * `2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php`; aqui ficam as regras.
 *
 * ═══ ⚠️⚠️ DERIVADA VIVA **E** CONGELADA NO ATO — A DECISÃO DE DADO-13 ══════════════════════════
 *
 * A origem por linha é **DERIVADA da fonte viva** no serviço de composição (plano **186-07**) —
 * ela **não** é persistida de forma contínua, porque custo e frete mudam todo dia e uma cópia
 * contínua ficaria velha e **mentiria**. O que é persistido é o **congelamento no momento do ato**
 * (`momento` = `aprovacao` ou `excecao`, com a `versao` da época).
 *
 * Isso satisfaz *"persistida **ou** derivada de forma auditável"* pelos **dois** lados, e é a
 * única coisa que torna a auditoria-depois de BLOQ-09 possível, já que **não existe desfazer**
 * (PUB-09).
 *
 * ═══ AS 7 LINHAS, E ⚠️ QUEM ANULA O RESULTADO ══════════════════════════════════════════════════
 *
 * As 7 linhas foram medidas no `fin()` do protótipo (`Aprovacao.dc.html:1061-1079`). Cada entrada
 * de `linhas` leva **valor, origem e nota** — a nota **nomeia a fonte** (VOC-04).
 *
 * ⚠️ `incompleto = true` ⇒ `total`, `resultado` e `margem` são **`null`, nunca zero** (FIN-05/D4).
 * Zero é um número e a tela o mostraria como se fosse verdade; `null` é a ausência, e a tela diz
 * "Incompleto". O modelo **recusa** a combinação `incompleto = true` com `resultado` preenchido.
 *
 * ⚠️ **QUEM ANULA O RESULTADO são só CINCO:** custo, comissão, tarifa fixa, frete e imposto.
 *
 * ⚠️⚠️ **E A ÚNICA EXCEÇÃO DELIBERADA — decisão do usuário de 14/10/2026:** **"Embalagem" sem
 * fonte nasce `Pendente` VISÍVEL e NÃO anula o resultado**; o resultado existe e vem com
 * **`estimado_por_baixo = true`** (o real é MENOR, porque falta um custo). E **"Outros custos"
 * nasce R$ 0,00 `Confirmado`** — é o que o protótipo faz (`other: {v: 0, src: 'conf', note:
 * 'Cadastro do produto'}`), porque "nenhum outro custo informado" é uma AFIRMAÇÃO do cadastro, não
 * uma lacuna.
 *
 * ⚠️ **A razão, escrita para ninguém "uniformizar" depois:** sem a exceção o painel diria
 * "Incompleto" em **100%** das ofertas (a embalagem raramente está cadastrada) e a fase ficaria
 * inerte. A regra completa — quem calcula e quando — é do plano **186-07**.
 *
 * ═══ ⚠️ A MARGEM MÍNIMA É **LIDA DA LINHA CONGELADA**, NUNCA DE CONFIG ═════════════════════════
 *
 * `margem_minima` guarda o mínimo que valia **no ato**, e `margem_minima_origem` diz **de qual
 * lado do fallback** ele veio (`parametro_da_empresa` quando o parâmetro da empresa é > 0, senão
 * `config`). A regra do fallback é do plano **186-07**; aqui guarda-se a ORIGEM.
 *
 * ⚠️ Sem a segunda coluna a auditoria-depois **não reconstrói a decisão**: a config pode ter mudado
 * depois, e ninguém saberia se o 12% da época veio do parâmetro ou do padrão. ⚠️ Por isso
 * `margemMinimaCongelada()` **nunca** relê `config()` — é essa a diferença entre auditoria e
 * recálculo.
 *
 * ═══ ⚠️ O VOCABULÁRIO, E UM CAMPO HOMÔNIMO QUE **NÃO** SE EXPÕE (VOC-03) ═══════════════════════
 *
 * Os dois nomes travados: **"Resultado estimado por venda"** e **"Margem de contribuição
 * estimada"**. ⚠️ **Nunca** o outro nome, aquele que promete descontar despesa fixa e devolução —
 * nada aqui as desconta, e usá-lo seria afirmação falsa.
 *
 * ⚠️⚠️ E a armadilha concreta: **existe um campo REAL com aquele nome** em
 * `EstruturaPrecificacaoParametros` (`:26`/`:30`/`:37`), legítimo, do Portal. Ele **não é exposto
 * por esta estrutura**, e o `daEmpresa()` daquele modelo **não deve ser repassado inteiro** — ele
 * devolve `array_map('floatval', ...)` de **todas** as `PADROES`, e repassar o array completo
 * vazaria o campo homônimo para dentro de `linhas`. ➡️ **Selecionar as chaves** (`comissao_classico`,
 * `comissao_premium`, `imposto`, `margem_contribuicao`), nunca o array.
 *
 * ═══ ⚠️ A `margem` TEM FOLGA DE PROPÓSITO ═════════════════════════════════════════════════════
 *
 * `decimal(12,3)`, não `decimal(7,3)`: o teto de ±9999,999 **estoura**, e este projeto já mediu
 * **+20738%**. O modo de falhar é **assimétrico** — no MySQL 8.0.46 é **erro 1264** e a gravação
 * cai; no SQLite dos testes **grava CALADO**, truncado. A suíte passa verde e o deploy quebra.
 *
 * ⚠️ `incompleto` e `estimado_por_baixo` também moram no `$attributes`, porque **o Eloquent não lê
 * o default do banco** (defeito REAL medido no plano 186-04: a linha nascia com o default no banco
 * e **null em memória**).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PubAprovacaoComposicao extends Model
{
    protected $table = 'pub_aprovacao_composicoes';

    // ─── Os momentos do congelamento ────────────────────────────────────────────────────────

    public const APROVACAO = 'aprovacao';

    public const EXCECAO = 'excecao';

    public const MOMENTOS = [
        self::APROVACAO => 'Aprovação',
        self::EXCECAO => 'Exceção autorizada',
    ];

    // ─── A origem de CADA LINHA (DADO-13 / VOC-04) ──────────────────────────────────────────

    public const CONFIRMADO = 'confirmado';

    public const ESTIMADO = 'estimado';

    public const PENDENTE = 'pendente';

    public const ORIGENS_DA_LINHA = [
        self::CONFIRMADO => 'Confirmado',
        self::ESTIMADO => 'Estimado',
        self::PENDENTE => 'Pendente',
    ];

    // ─── As 7 linhas da composição (medidas no `fin()` do protótipo) ────────────────────────

    public const LINHAS = [
        'custo' => 'Custo do produto',
        'comissao' => 'Comissão',
        'tarifa_fixa' => 'Tarifa fixa por venda',
        'frete' => 'Frete pago pelo vendedor',
        'imposto' => 'Impostos',
        'embalagem' => 'Embalagem',
        'outros' => 'Outros custos',
    ];

    /**
     * ⚠️ As CINCO que anulam o resultado. **Embalagem e outros custos NÃO estão aqui** — é a
     * exceção deliberada do usuário de 14/10/2026 (ver o docblock da classe). Mexer nesta lista
     * "uniformiza" a regra e faz o painel dizer "Incompleto" em 100% das ofertas.
     */
    public const ANULAM_O_RESULTADO = ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto'];

    // ─── De qual lado do fallback veio a margem mínima ──────────────────────────────────────

    public const MINIMO_PARAMETRO = 'parametro_da_empresa';

    public const MINIMO_CONFIG = 'config';

    public const ORIGENS_DO_MINIMO = [
        self::MINIMO_PARAMETRO => 'Parâmetro da empresa',
        self::MINIMO_CONFIG => 'Padrão do sistema',
    ];

    // ─── ⚠️ VOC-03 — os dois nomes travados ─────────────────────────────────────────────────

    public const ROTULO_RESULTADO = 'Resultado estimado por venda';

    public const ROTULO_MARGEM = 'Margem de contribuição estimada';

    /**
     * ⚠️ FIN-07 — o mapa `origem viva da Precificação` => `origem da linha`.
     *
     * As chaves são as de `DadosEfetivosService::fretesDaLinha()`, e as frases legíveis vêm de
     * `ConferenciaDeFrete::ORIGENS` — **LIDO sem alteração** (`App\Support\Publicador` é
     * território que esta milestone não toca). Repetir aquelas frases aqui criaria duas redações
     * da mesma verdade, livres para divergir.
     *
     * ⚠️ **Só a AUSÊNCIA de valor produz `Pendente`.** Havendo fonte, a linha nasce `Confirmado`
     * ou `Estimado` com a nota real — é exatamente o que FIN-07 exige.
     */
    public const ORIGEM_VIVA_DO_FRETE = [
        'digitado' => self::CONFIRMADO,   // alguém digitou no Portal: número de gente
        'conta' => self::CONFIRMADO,      // cotado na conta do ML: número que o ML respondeu
        'tabela' => self::ESTIMADO,       // estimado pela tabela: aproximação declarada
        'outro_tipo' => self::ESTIMADO,   // emprestado do outro tipo: aproximação declarada
    ];

    /** `incompleto = true` com `resultado` preenchido: a tela leria um resultado que ninguém calculou. */
    public const REGRA_INCOMPLETO_COM_RESULTADO = 'FIN-05-INCOMPLETO-COM-RESULTADO';

    protected $guarded = ['id'];

    /** ⚠️ O Eloquent não lê o default do banco — ver o docblock da classe. */
    protected $attributes = ['incompleto' => false, 'estimado_por_baixo' => false];

    protected $casts = [
        'versao' => 'integer',
        'preco' => 'float',
        'linhas' => 'array',
        'total' => 'float',
        'resultado' => 'float',
        'margem' => 'float',
        'incompleto' => 'boolean',
        'estimado_por_baixo' => 'boolean',
        'margem_minima' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $linha) {
            if (! $linha->incompleto) {
                return;
            }

            foreach (['total', 'resultado', 'margem'] as $campo) {
                if ($linha->$campo !== null) {
                    throw new RegraViolada(
                        self::REGRA_INCOMPLETO_COM_RESULTADO,
                        'Falta informação para calcular o resultado desta oferta.',
                        ['campo' => $campo]
                    );
                }
            }
        });
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoOferta::class, 'oferta_id');
    }

    /**
     * A margem mínima que valia **no ato**, lida da linha congelada.
     *
     * ⚠️ **Nunca** relê `config()`: a config pode ter mudado depois do ato, e a auditoria-depois
     * reconstruiria a decisão errada.
     */
    public function margemMinimaCongelada(): float
    {
        return (float) $this->margem_minima;
    }

    /**
     * ⚠️ FIN-07 — a LINHA de frete da composição, montada da fonte viva e do informe à mão.
     *
     * `$vivo` é uma entrada de `DadosEfetivosService::fretesDaLinha()`
     * (`['valor' => ?float, 'origem' => ?string, 'oferta_id' => ?int]`). `$informado`, quando
     * existe, **VENCE** e nasce `Confirmado` (MOD-03).
     *
     * ⚠️ A linha só é `Pendente` quando **não há fonte nenhuma** — e esse é o único caso em que o
     * `valor` é `null`, nunca zero (D4).
     *
     * ⚠️ A `nota` **NOMEIA a fonte**, e a frase da Precificação sai de `ConferenciaDeFrete::ORIGENS`
     * (lido, nunca recopiado).
     *
     * @param  array{valor: ?float, origem: ?string, oferta_id?: ?int}  $vivo
     * @return array{valor: ?float, origem: string, nota: string}
     */
    public static function linhaDeFrete(array $vivo, ?PubAprovacaoFrete $informado = null): array
    {
        if ($informado !== null) {
            return [
                'valor' => (float) $informado->valor,
                'origem' => self::CONFIRMADO,
                'nota' => $informado->nota,
            ];
        }

        $valor = $vivo['valor'] ?? null;
        $origemViva = $vivo['origem'] ?? null;

        if ($valor === null) {
            return [
                'valor' => null,
                'origem' => self::PENDENTE,
                'nota' => 'A Precificação está sem frete para este anúncio.',
            ];
        }

        return [
            'valor' => (float) $valor,
            'origem' => self::ORIGEM_VIVA_DO_FRETE[$origemViva] ?? self::ESTIMADO,
            'nota' => 'Precificação do Portal: '.(ConferenciaDeFrete::ORIGENS[$origemViva] ?? 'origem não registrada'),
        ];
    }
}
