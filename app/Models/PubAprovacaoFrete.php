<?php

namespace App\Models;

use App\Support\Publicador\RegraViolada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O FRETE DO VENDEDOR **INFORMADO À MÃO NA REVISÃO** (MOD-03 / DADO-12).
 *
 * Uma linha por informe. A decisão de schema e as razões completas estão no docblock da migration
 * `2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php`; aqui ficam as regras.
 *
 * ═══ ⚠️⚠️ HISTÓRICO, NUNCA SOBRESCRITA (T-186-21) ══════════════════════════════════════════════
 *
 * Informar o frete de novo **cria linha nova** e marca a anterior `vigente = false`. **Nunca** um
 * `update` na mesma linha.
 *
 * **Por quê:** o usuário decidiu em 14/10/2026 que **aprovar JÁ PUBLICA**, sem segundo clique e
 * **sem desfazer**. Saber **qual** frete valia quando a oferta foi aprovada é a única forma de
 * auditar depois — e a sobrescrita destruiria exatamente essa informação, no caso em que ela
 * importa. O frete que valeu no ato também fica congelado em `pub_aprovacao_composicoes`.
 *
 * ═══ ⚠️ ELE **VENCE** O DA PRECIFICAÇÃO E NASCE **Confirmado** ═════════════════════════════════
 *
 * A linha de frete da composição tem duas fontes possíveis, e a ordem é: **o informado à mão
 * vence**; faltando ele, vale o da Precificação (`DadosEfetivosService::fretesDoProduto()`), com a
 * origem viva traduzida pelo mapa de `PubAprovacaoComposicao::ORIGEM_VIVA_DO_FRETE`.
 *
 * ⚠️ **FIN-07** — a linha **não** nasce "Pendente" quando há fonte, e **há fonte** (medido em
 * 14/10/2026). Só `valor = null` na Precificação **e** nenhum informe produz `Pendente`. A
 * montagem dessa linha é `PubAprovacaoComposicao::linhaDeFrete()`.
 *
 * ═══ ⚠️ A NOTA **NOMEIA A FONTE** (DADO-13 / VOC-04) ═══════════════════════════════════════════
 *
 * `nota` é a frase legível **com a data** — "Tabela do cliente · 15/10/2026 10:00" —, exatamente o
 * `fNote = origem + ' · ' + agora` do protótipo (`Aprovacao.dc.html:1204`). ⚠️ **Não é texto
 * decorativo:** é ela que torna a composição auditável. Nota genérica ("origem automática") prova
 * nada e foi recusada.
 *
 * ═══ ⚠️ A VERSÃO: CONFIRMAR O FRETE **REABRE** A APROVAÇÃO (BLOQ-05) ═══════════════════════════
 *
 * Confirmar o frete **É uma das 8 mudanças relevantes**, então `informar()` **incrementa a
 * `versao` da oferta** na MESMA transação, e a linha guarda a versão **DEPOIS** do incremento. O
 * protótipo faz isso (`x.version++`, e o evento "Confirmou o frete do vendedor: R$ … (origem) ·
 * versão N").
 *
 * ⚠️ **`AndamentoDaOfertaService` não incrementa a versão** (docblock dele, `:59-63`) e **este
 * modelo não o chama**: mover o andamento é daquele serviço, e esta operação não move andamento
 * nenhum. ⚠️ **A rotina GERAL das 8 mudanças é do plano 186-08** — quando ela chegar, o caminho
 * certo é `informar()` **DELEGAR** a ela, nunca uma segunda cópia da regra. Está escrito aqui para
 * a próxima pessoa não duplicar.
 *
 * ⚠️ **`vigente` também mora no `$attributes`**, e isso não é redundância: **o Eloquent não lê o
 * default do banco**. Sem o bloco, a linha nasceria `vigente = 1` no banco e **null em memória** —
 * foi defeito REAL medido no plano 186-04, e um teste compara os dois contra o schema vivo.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PubAprovacaoFrete extends Model
{
    protected $table = 'pub_aprovacao_fretes';

    // ─── As 3 origens do modal "Informar frete do vendedor" (MOD-03) ────────────────────────

    public const COTACAO_TRANSPORTADORA = 'cotacao_transportadora';

    public const TABELA_CLIENTE = 'tabela_cliente';

    public const SIMULADOR = 'simulador';

    /**
     * Chave de dado => rótulo pt-BR da tela.
     *
     * ⚠️ VOC-06 — a **chave** é identificador e **nunca chega à tela**; o rótulo é o que a pessoa
     * lê. As três frases são as medidas no protótipo (`Aprovacao.dc.html:1401`).
     */
    public const ORIGENS = [
        self::COTACAO_TRANSPORTADORA => 'Cotação da transportadora',
        self::TABELA_CLIENTE => 'Tabela do cliente',
        self::SIMULADOR => 'Simulador de frete',
    ];

    /** Origem fora das três declaradas: a nota deixaria de nomear a fonte. */
    public const REGRA_ORIGEM_DESCONHECIDA = 'MOD-03-ORIGEM-DESCONHECIDA';

    protected $guarded = ['id'];

    /** ⚠️ O Eloquent não lê o default do banco — ver o docblock da classe. */
    protected $attributes = ['vigente' => true];

    protected $casts = [
        'valor' => 'float',
        'versao' => 'integer',
        'vigente' => 'boolean',
        'informado_em' => 'datetime',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoOferta::class, 'oferta_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'informado_por');
    }

    /**
     * O frete informado que VALE para esta oferta, ou `null`.
     *
     * ⚠️ **`null`, nunca `0.0`** (FIN-05/D4): zero é um número, e a tela o mostraria como se fosse
     * verdade — "frete R$ 0,00" num kit de duas poltronas. A ausência tem de ser a ausência.
     */
    public static function vigenteDe(PubAprovacaoOferta|int $oferta): ?self
    {
        return self::query()
            ->where('oferta_id', $oferta instanceof PubAprovacaoOferta ? $oferta->id : $oferta)
            ->where('vigente', true)
            ->latest('id')
            ->first();
    }

    /**
     * Confirma o frete do vendedor: **linha nova**, a anterior derrubada e a `versao` da oferta
     * incrementada — tudo numa transação só.
     *
     * ⚠️ Transação única porque as três coisas são **um ato**: deixar duas linhas vigentes (ou
     * incrementar a versão sem gravar o frete) produz um estado que a tela não sabe ler, e
     * **aprovar já publica, sem desfazer**.
     */
    public static function informar(PubAprovacaoOferta $oferta, float $valor, string $origem, ?User $quem = null): self
    {
        if (! array_key_exists($origem, self::ORIGENS)) {
            throw new RegraViolada(
                self::REGRA_ORIGEM_DESCONHECIDA,
                'Escolha de onde veio este frete: cotação da transportadora, tabela do cliente ou simulador de frete.',
                ['origem' => $origem]
            );
        }

        return DB::transaction(function () use ($oferta, $valor, $origem, $quem) {
            // Histórico: a anterior deixa de valer, mas FICA.
            self::query()->where('oferta_id', $oferta->id)->where('vigente', true)->update(['vigente' => false]);

            // ⚠️ A mudança é relevante, então REABRE a aprovação (BLOQ-05). A linha guarda a
            // versão DEPOIS do incremento.
            $oferta->increment('versao');
            $versao = (int) $oferta->refresh()->versao;

            return self::create([
                'oferta_id' => $oferta->id,
                'valor' => round($valor, 2),
                'origem' => $origem,
                'nota' => self::nota($origem),
                'informado_por' => $quem?->id,
                'informado_em' => now(),
                'versao' => $versao,
                'vigente' => true,
            ]);
        });
    }

    /**
     * A nota de fonte legível, **com a data**: "Tabela do cliente · 15/10/2026 10:00".
     *
     * ⚠️ Ela **nomeia a fonte** (DADO-13/VOC-04). O formato é o `origem · agora` do protótipo.
     */
    public static function nota(string $origem, ?\Carbon\CarbonInterface $quando = null): string
    {
        return (self::ORIGENS[$origem] ?? $origem).' · '.($quando ?? now())->format('d/m/Y H:i');
    }
}
