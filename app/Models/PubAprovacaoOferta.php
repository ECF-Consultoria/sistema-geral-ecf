<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A OFERTA DO PORTÃO — uma linha por `pub_rascunho_alvos` (Clássico ou Premium).
 *
 * É a extensão **1:1** da projeção `rascunho × listing_type_id` que já existe
 * (`alvo_id` UNIQUE): Clássico e Premium têm andamento, preço, resultado e versão SEPARADOS,
 * porque é por oferta que se aprova e se publica. O raciocínio completo de granularidade (o
 * critério 12 da Fase 186) está registrado no docblock da migration
 * `2026_10_15_100200_create_pub_aprovacao_ofertas_table.php` — aqui ficam as regras de estado.
 *
 * ⚠️⚠️ **APROVAR JÁ PUBLICA** (decisão do usuário, 14/10/2026): sem segundo clique e **sem
 * desfazer**. Toda a reversibilidade mora ANTES do clique — nos bloqueios e na `versao`. As 8
 * mudanças relevantes incrementam a `versao` e **reabrem** a aprovação; mudança de foto reabre
 * TODAS as ofertas do conjunto. `versao_aprovada` guarda em que versão a aprovação foi dada, e é
 * a única coisa entre "alguém mexeu na foto" e "foi ao ar errado".
 *
 * ═══ AS CHAVES DE DADO SÃO pt-BR, E ISSO É DECISÃO (plano 186-02 / VOC-06) ═════════════════════
 *
 * As 8 chaves abaixo são AS MESMAS no banco, no servidor e em
 * `resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx`. As chaves INGLESAS do
 * protótipo **não são chave de dado, prop, valor de URL nem texto de tela** — "evitar jargão" é
 * regra permanente deste projeto, e chave de dado em inglês com rótulo em português vira **duas
 * verdades para manter**.
 *
 * O MAPA protótipo → nosso, para a conferência contra `STM`/`ORD`/`ST`/`cta` de
 * `design_handoff_publicador/Aprovacao.dc.html` ser possível (é o `DO_PROTOTIPO` do módulo `.jsx`,
 * repetido aqui só como LEITURA — o módulo é a fonte):
 *
 *     preparing  → preparando            approved   → aprovado
 *     awaiting   → aguardando_aprovacao  publishing → publicando
 *     ecf_review → em_revisao_ecf        failed     → falha
 *     changes    → ajustes_solicitados   published  → publicado
 *
 * ⚠️ **NUNCA aceitar "as duas formas" num `||`.** Isso é o bug dos dois idiomas com outra roupa,
 * e foi achado como defeito grave na verificação da wave 1. Se algum dia for preciso inverter, a
 * inversão fica num lugar só.
 *
 * ⚠️ "Em revisão pela ECF" diz DE QUEM é a revisão, e é por isso que ele é legítimo: o literal
 * CRU "Em revisão" foi aposentado pelo plano 186-01, que deixou este rótulo na lista de
 * QUALIFICADOS do gate de jargão. **Não encurtar** — e qualificado novo se DECLARA lá.
 *
 * ═══ ESTE EIXO NÃO É NENHUM DOS OUTROS DOIS (DADO-02 / critério 3) ═════════════════════════════
 *
 * - **`pub_rascunhos` tem 6 estados, de PUBLICAÇÃO** (`DRAFT`, `VALIDATED`, `PUBLISHING`,
 *   `PUBLISHED`, `PARTIALLY_PUBLISHED`, `FAILED`): dizem onde o rascunho está no caminho do ML.
 * - **`pub_imagens.upload_status` é de UPLOAD** (`pending`/`uploaded`/`failed`): diz se o byte da
 *   foto chegou ao Mercado Livre.
 * - **Estes 8 são de ANDAMENTO INTERNO da aprovação:** dizem onde a conferência HUMANA está.
 *
 * **Não reusar, não estender.** O README é explícito: *"o andamento interno nunca aparece na lista
 * de Publicadas, e o status do ML nunca aparece na fila"* — e por isso a asserção do teste é nas
 * **duas direções**, com os 17 literais provados distintos.
 *
 * ═══ ⚠️ O QUE A OFERTA **NÃO TEM**, E POR QUÊ ══════════════════════════════════════════════════
 *
 * - **`validacao_id`, `plano_hash`, `revisao`, `ciente`** — são as **4 colunas** de conferência de
 *   `pub_fila_publicacao_itens` e **não são copiadas** (DADO-03): são lidas por `fila_item_id`, e a
 *   semântica delas não muda. Duplicar criaria duas verdades livres para divergir (T-186-15).
 * - **`precisa_revisar`** — ⚠️ **não é coluna em lugar nenhum**: é um **VALOR** do `status`
 *   `varchar(20)` da fila (`PubFilaPublicacaoItem::PRECISA_REVISAR`), conferido no schema vivo por
 *   `information_schema` em 14/10/2026.
 * - **`conjunto_id`** — a relação oferta→conjunto é **1:N** (achado S2). O bloqueio de fotos
 *   considera **TODOS** os grupos do rascunho (decisão do usuário de 14/10: aprovação cor por cor
 *   foi oferecida e RECUSADA, porque aprovar já publica e não há desfazer). Com uma FK única, as
 *   fotos dos outros grupos e as `GENERAL` ficariam FORA do bloqueio. Por isso a relação daqui é
 *   `conjuntos()` (HasMany por `rascunho_id`), e não `conjunto()`.
 * - **o preço** — é LIDO (`DadosEfetivosService` / `pub_variante_precos`); aqui há só o **aviso**
 *   (`preco_alterado`) e `preco_conferido_em`. Qual preço manda está no plano 186-07: o **pior
 *   caso**, o MENOR preço ativo do alvo.
 * - **contadores de foto** — são DERIVADOS, contados do conjunto
 *   (`PubAprovacaoConjunto::aprovadas()/pendentes()`).
 * - ⚠️ **nada de "confirmar publicação", "desfazer", "segundo passo" ou "cancelar aprovação"** —
 *   nem coluna, nem estado, nem método, nem aviso. A decisão do usuário de 14/10 recusou o segundo
 *   clique, e o rótulo "Aprovar para publicação" fica inalterado. O único ponto de extensão é o
 *   `aoAprovar()` do `AndamentoDaOfertaService`, que a **Fase 193** preenche.
 * - ⚠️ **nenhuma coluna, estado ou flag de "exceção aguardando autorização"** — aquele estado NÃO
 *   EXISTE nesta milestone (BLOQ-09: o controle é auditoria DEPOIS, não barreira ANTES).
 *
 * ═══ AS RELAÇÕES QUE AINDA NÃO EXISTEM, E EM QUE PLANO ELAS CHEGAM ═════════════════════════════
 *
 * Declaradas aqui: `alvo`, `rascunho`, `produto`, `conjuntos`, `responsavel`, `aprovadoPor`,
 * `filaItem`. **Faltam de propósito** `eventos()` (histórico — plano **186-05**), `ajustes()`
 * (**186-05**), `excecoes()` e `fretes()` (**186-06**): as tabelas delas são criadas naqueles
 * planos, e declarar relação para tabela que não existe quebraria o teste sem provar nada. **Não é
 * omissão deste plano** — está escrito aqui para ninguém achar que faltou.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PubAprovacaoOferta extends Model
{
    protected $table = 'pub_aprovacao_ofertas';

    // ─── Os 8 estados de andamento INTERNO (VOC-02 / AND-02) ────────────────────────────────

    public const PREPARANDO = 'preparando';
    public const AGUARDANDO_APROVACAO = 'aguardando_aprovacao';
    public const EM_REVISAO_ECF = 'em_revisao_ecf';
    public const AJUSTES_SOLICITADOS = 'ajustes_solicitados';
    public const APROVADO = 'aprovado';
    public const PUBLICANDO = 'publicando';
    public const FALHA = 'falha';
    public const PUBLICADO = 'publicado';

    /**
     * Os 8 estados, chave de dado => rótulo pt-BR da tela.
     *
     * ⚠️ A ORDEM é a do `STM` do protótipo, de propósito: a conferência FID-01 é lida linha a
     * linha contra ele, e reordenar faria a medição comparar pares trocados.
     *
     * ⚠️ Os rótulos são **espelho byte a byte** do `ANDAMENTOS` de `andamentoDoPortao.jsx`, e um
     * teste LÊ aquele arquivo para comparar — em vez de confiar em duas cópias à mão.
     */
    public const ANDAMENTOS = [
        self::PREPARANDO => 'Preparando',
        self::AGUARDANDO_APROVACAO => 'Aguardando aprovação',
        self::EM_REVISAO_ECF => 'Em revisão pela ECF',
        self::AJUSTES_SOLICITADOS => 'Ajustes solicitados',
        self::APROVADO => 'Aprovado',
        self::PUBLICANDO => 'Publicando',
        self::FALHA => 'Falha na publicação',
        self::PUBLICADO => 'Publicado',
    ];

    /**
     * ⚠️⚠️ AS TRANSIÇÕES DECLARADAS — **LISTA FECHADA** (T-186-11).
     *
     * origem => destinos permitidos. São **10 regras / 12 pares**, e a diferença é que
     * "qualquer `live` → `aprovado`" vale para os três estados ao vivo.
     *
     * **Por que é lista fechada e não um laço de proibições:** `aprovado` é o portão que libera a
     * publicação, e aprovar JÁ PUBLICA. Uma lista de proibições deixa tudo que ninguém lembrou de
     * proibir PERMITIDO — numa máquina de estado cujo passo errado publica anúncio não conferido,
     * o default tem de ser "recusa".
     *
     * ⚠️ `aprovado → publicado` **não existe**: tem de passar por `publicando`, porque é ali que o
     * `ml_item_id` chega. `publicado` é **terminal por estrutura** (lista vazia), não por exceção.
     * ⚠️ `falha → publicando` é o "Tentar de novo" (AND-10), e ele **não** incrementa a `versao`
     * nem mexe em `versao_aprovada`/`aprovado_por`/`aprovado_em` (BLOQ-11): a revisão é
     * PRESERVADA.
     */
    public const TRANSICOES = [
        self::PREPARANDO => [self::AGUARDANDO_APROVACAO],
        self::AGUARDANDO_APROVACAO => [self::EM_REVISAO_ECF, self::AJUSTES_SOLICITADOS, self::APROVADO],
        self::EM_REVISAO_ECF => [self::AJUSTES_SOLICITADOS, self::APROVADO],
        self::AJUSTES_SOLICITADOS => [self::AGUARDANDO_APROVACAO, self::APROVADO],
        self::APROVADO => [self::PUBLICANDO],
        self::PUBLICANDO => [self::PUBLICADO, self::FALHA],
        self::FALHA => [self::PUBLICANDO],
        self::PUBLICADO => [],
    ];

    /**
     * Os três estados "ao vivo" (AND-05): a oferta ainda está sendo decidida, e é o ÚNICO conjunto
     * **editável e aprovável**. É o `inf.live` do protótipo (`awaiting ∪ ecf_review ∪ changes`),
     * em pt-BR.
     */
    public const LIVE = [self::AGUARDANDO_APROVACAO, self::EM_REVISAO_ECF, self::AJUSTES_SOLICITADOS];

    /**
     * A ordenação da fila (AND-08) — o `ORD` do protótipo.
     *
     * ⚠️ 8 chaves, **7 valores** (0 a 6): `aguardando_aprovacao` e `em_revisao_ecf` EMPATAM em 1,
     * de propósito — o protótipo os trata como o mesmo degrau de urgência ("ninguém terminou de
     * conferir"). "Arrumar" o empate muda a ordem da fila sem nenhum teste de render acusar.
     * `preparando` vem por ÚLTIMO (6): não há nada a fazer nele.
     */
    public const ORDEM = [
        self::FALHA => 0,
        self::AGUARDANDO_APROVACAO => 1,
        self::EM_REVISAO_ECF => 1,
        self::AJUSTES_SOLICITADOS => 2,
        self::APROVADO => 3,
        self::PUBLICANDO => 4,
        self::PUBLICADO => 5,
        self::PREPARANDO => 6,
    ];

    /**
     * O rótulo da ação da linha (AND-10) — o `cta` do protótipo.
     *
     * `falha` → "Tentar de novo" · `preparando` → "Aguarde" ·
     * `aprovado`/`publicando`/`publicado` → "Ver" · os três `live` → "Revisar".
     */
    public const CTA = [
        self::PREPARANDO => 'Aguarde',
        self::AGUARDANDO_APROVACAO => 'Revisar',
        self::EM_REVISAO_ECF => 'Revisar',
        self::AJUSTES_SOLICITADOS => 'Revisar',
        self::APROVADO => 'Ver',
        self::PUBLICANDO => 'Ver',
        self::FALHA => 'Tentar de novo',
        self::PUBLICADO => 'Ver',
    ];

    /** O cta de quem chegou com chave que não é chave — mesmo ramo `senão` do protótipo. */
    public const CTA_NEUTRO = 'Revisar';

    /** `origem` da oferta (AUTO-03): nasceu da automação ou foi aberta à mão. */
    public const ORIGEM_AUTO = 'auto';

    public const ORIGEM_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected $casts = [
        'preco_alterado' => 'array',
        'entrou_na_fila_em' => 'datetime',
        'aprovado_em' => 'datetime',
        'preco_conferido_em' => 'datetime',
        'versao' => 'integer',
        'versao_aprovada' => 'integer',
        'tocado' => 'boolean',
    ];

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Relações
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** O alvo do editor de que esta oferta é a extensão 1:1 (`alvo_id` UNIQUE). */
    public function alvo(): BelongsTo
    {
        return $this->belongsTo(PubRascunhoAlvo::class, 'alvo_id');
    }

    public function rascunho(): BelongsTo
    {
        return $this->belongsTo(PubRascunho::class, 'rascunho_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(PubProduto::class, 'produto_id');
    }

    /**
     * ⚠️ **HasMany, e NÃO `conjunto()` BelongsTo** — o achado S2.
     *
     * `ResolvedorGruposImagem::chaveDoGrupo()` dá um grupo por valor de eixo que define foto MAIS
     * o `GENERAL` (3 cores = 4 grupos), então a relação é **1:N**. Com `conjunto_id` único, as
     * fotos dos outros grupos e as gerais ficariam FORA do bloqueio e a oferta poderia ser
     * aprovada com foto que ninguém olhou — e não há desfazer.
     *
     * ⚠️ A ligação é por `rascunho_id` nos DOIS lados: é por isso que o compartilhamento
     * Clássico↔Premium é **estrutural** e não combinado — não existe coluna onde as duas ofertas
     * pudessem divergir.
     */
    public function conjuntos(): HasMany
    {
        return $this->hasMany(PubAprovacaoConjunto::class, 'rascunho_id', 'rascunho_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    /**
     * O item de fila que está publicando o PRODUTO desta oferta.
     *
     * ⚠️ É por aqui que `validacao_id`, `plano_hash`, `revisao` e `ciente` são **LIDOS** em vez de
     * copiados (DADO-03). Um produto está em UMA fila por vez (o unique
     * `pubfilai_produto_ativo_uq`), então as duas ofertas do produto apontam para o MESMO item — e
     * isso é correto: a fila publica o produto inteiro.
     */
    public function filaItem(): BelongsTo
    {
        return $this->belongsTo(PubFilaPublicacaoItem::class, 'fila_item_id');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Os acessores de vocabulário.
    //
    // ⚠️ REND-03 vale no PHP pelo motivo GÊMEO do JS: acessor que indexa um mapa com chave vinda
    // do banco faz **guarda de tipo antes**, nunca `$MAPA[$chave]` cru. Em PHP, chave que não é
    // string nem int **lança TypeError**, e string desconhecida emite warning.
    //
    // ⚠️ Desconhecido devolve **`null`** aqui, e isso é deliberado — e é DIFERENTE do módulo
    // `.jsx`, onde `andamentoDe()` nunca é `null`. Não é divergência de vocabulário (as 8 chaves e
    // os 8 rótulos são espelho byte a byte): é diferença de RESPONSABILIDADE. No servidor `null`
    // significa "não conheço esta chave", e quem precisa decidir trata. Quem RENDERIZA é o `.jsx`,
    // e lá `null` viraria um selo com cor e sem texto — invisível na tela, verde no teste. Os dois
    // lados estão certos para o que cada um faz.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** Lookup seguro num dos mapas deste modelo. `null` = a chave não é chave. */
    private static function entradaDe(array $mapa, mixed $chave): mixed
    {
        if (! is_string($chave) || trim($chave) === '') {
            return null;
        }

        return array_key_exists($chave, $mapa) ? $mapa[$chave] : null;
    }

    /** O rótulo pt-BR de um andamento, ou `null` se a chave não é chave. */
    public static function rotuloDe(mixed $chave): ?string
    {
        return self::entradaDe(self::ANDAMENTOS, $chave);
    }

    /** A posição na ordenação da fila, ou `null` se a chave não é chave. */
    public static function ordemDe(mixed $chave): ?int
    {
        return self::entradaDe(self::ORDEM, $chave);
    }

    /** O rótulo da ação, ou `null` se a chave não é chave. */
    public static function ctaDe(mixed $chave): ?string
    {
        return self::entradaDe(self::CTA, $chave);
    }

    /** `true` só para os 12 pares declarados em `TRANSICOES`. Default = recusa. */
    public static function transicaoPermitida(mixed $de, mixed $para): bool
    {
        $destinos = self::entradaDe(self::TRANSICOES, $de);

        if (! is_array($destinos) || ! is_string($para)) {
            return false;
        }

        return in_array($para, $destinos, true);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // As regras de estado da própria oferta
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** O rótulo pt-BR do andamento desta oferta. */
    public function rotulo(): ?string
    {
        return self::rotuloDe($this->andamento);
    }

    /**
     * ⚠️ O **ÚNICO** lugar que decide se a oferta é editável e aprovável (AND-05).
     *
     * Duplicar esta regra deixaria as duas cópias livres para divergir, e a divergência aqui
     * liberaria edição de oferta já publicada.
     */
    public function ehLive(): bool
    {
        return is_string($this->andamento) && in_array($this->andamento, self::LIVE, true);
    }

    /** Clicar na linha leva à revisão EDITÁVEL? É exatamente `ehLive()`, num lugar só. */
    public function abreRevisao(): bool
    {
        return $this->ehLive();
    }

    /**
     * A oferta pode ser mexida? (PUB-04)
     *
     * ⚠️ `publicando` é **false**: edição bloqueada enquanto sobe ao ML. `preparando` também, por
     * outro motivo (a oferta ainda está sendo montada — AND-03, "não abre revisão").
     */
    public function podeEditar(): bool
    {
        return $this->ehLive();
    }

    /** O rótulo da ação da linha desta oferta. Chave desconhecida cai em "Revisar". */
    public function cta(): string
    {
        return self::ctaDe($this->andamento) ?? self::CTA_NEUTRO;
    }

    /** A posição desta oferta na ordenação da fila. Chave desconhecida vai para o fim. */
    public function ordem(): int
    {
        return self::ordemDe($this->andamento) ?? (max(self::ORDEM) + 1);
    }

    /**
     * ⚠️ AND-04 — chegar a `publicado` exige `ml_item_id` **confirmado pelo ML** (T-186-12).
     *
     * Sem isto a tela diria "Publicado e confirmado no Mercado Livre · MLB…" sem anúncio nenhum —
     * o anúncio existiria só na nossa cabeça. Quem RECUSA a transição é o
     * `AndamentoDaOfertaService`; este método é a pergunta que ele faz.
     */
    public function podeSerPublicado(): bool
    {
        if (! self::transicaoPermitida($this->andamento, self::PUBLICADO)) {
            return false;
        }

        return is_string($this->ml_item_id) && trim($this->ml_item_id) !== '';
    }

    /** Já foi aprovada alguma vez? (`versao_aprovada` NULL = nunca) */
    public function foiAprovada(): bool
    {
        return $this->versao_aprovada !== null;
    }

    /**
     * ⚠️ BLOQ-05 — a aprovação está **aberta** (precisa de nova conferência)?
     *
     * A `versao` incrementa a cada mudança relevante; se a oferta foi aprovada numa versão
     * ANTERIOR à atual, a aprovação foi **reaberta** e não vale mais. É a única coisa entre
     * "alguém mexeu na foto" e "foi ao ar errado". A rotina que reabre é do plano **186-08**.
     */
    public function aprovacaoReaberta(): bool
    {
        return $this->foiAprovada() && (int) $this->versao_aprovada < (int) $this->versao;
    }
}
