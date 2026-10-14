<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do **histórico da oferta do portão** (Fase 186, DADO-10).
 *
 * ═══ ⚠️⚠️ POR QUE ESTA TABELA EXISTE — E POR QUE ELA É **APPEND-ONLY** ═════════════════════════
 *
 * O usuário decidiu em 14/10/2026 que **aprovar JÁ PUBLICA**, sem segundo clique e **sem
 * desfazer**; e que a exceção de prejuízo/margem é aplicada **direto** por quem revisa (BLOQ-09).
 * As duas decisões juntas trocam "barreira ANTES" por **"auditoria DEPOIS"** — e auditoria depois
 * só é verdade se o registro existir de fato. **Este modelo É o registro.** Sem ele, "auditoria
 * depois" é uma frase.
 *
 * ➡️ Daí: **nenhuma linha é apagada por nada.** Não há rotina de limpeza, não há retenção, não há
 * coluna de "apagado"/"oculto" que permitisse esconder um evento sem apagá-lo (T-186-18). A única
 * porta de escrita é `HistoricoDaOfertaService::registrar()`, e ela só INSERE.
 * ⚠️ **Não confundir com a retenção de 48 h dos criativos** (`LimparReferenciasCriativos`), que é
 * de arquivo de imagem e é território de outro dev. Aqui não há nada parecido, e não deve haver.
 *
 * ═══ COL-05 — O AUTOR "SISTEMA" É **ATOR PRÓPRIO**, NÃO UM USUÁRIO INVENTADO ═══════════════════
 *
 * `autor_id = NULL` + `ator_sistema = true`. **Duas colunas e não uma**, de propósito: `autor_id`
 * nulo sozinho é ambíguo (o `SET NULL` de usuário apagado também deixa nulo), e criar um usuário
 * de banco chamado "Sistema" seria pior — apareceria em listas de gente, poderia logar, e alguém
 * acabaria atribuindo a ele uma aprovação de verdade. É o `EMP.SYS = ['Sistema', 0]` do protótipo
 * (`design_handoff_publicador/Aprovacao.dc.html:915`) como ator, não como pessoa.
 * ⚠️ `autorExibido()` **nunca devolve vazio**: nome em branco na linha do tempo é exatamente o
 * buraco que a auditoria-depois não pode ter.
 *
 * ═══ ⚠️ `ocorrido_em` × `created_at` — NÃO SÃO A MESMA PERGUNTA ════════════════════════════════
 *
 * `ocorrido_em` é **quando o FATO aconteceu**; `created_at` é quando a LINHA nasceu. Hoje são
 * iguais (o evento é gravado no instante do ato), mas um backfill ou uma importação separaria os
 * dois — e a linha do tempo tem de ordenar pelo FATO. ⚠️ Os dois vêm do **servidor** (`now()`),
 * nunca do cliente (COL-05).
 */
class PubAprovacaoEvento extends Model
{
    protected $table = 'pub_aprovacao_eventos';

    // ─── Os 8 tipos de evento (DADO-10) ──────────────────────────────────────────────────────
    //
    // ⚠️ As chaves são os literais do `HK` do protótipo (`Aprovacao.dc.html:1386`) e FICAM COMO
    // ESTÃO. Não é descuido de idioma: a decisão pt-BR do plano 186-02 valeu para **as 11 chaves
    // de BLOQUEIO**, que existiam em DOIS lados (PHP e `.jsx`) com a mesma verdade em idiomas
    // diferentes — e o selo renderizava VAZIO. O mesmo módulo registra a regra complementar
    // (`vocabularioDoPortao.jsx:175-180`): as 13 chaves de `CRIT`/`DEF` ficaram como estão porque
    // "inventar chave nova aqui seria churn sem nenhum bug para resolver". Estes 8 tipos são um
    // namespace **NOVO**, sem contraparte em lugar nenhum. ➡️ Ficam.
    // ⚠️ O caso OPOSTO é `PubAprovacaoExcecao::$tipo`, que É uma das 11 chaves de bloqueio e por
    // isso é pt-BR — ver o docblock daquele modelo.

    /** Ato da automação, sem pessoa por trás. O autor é `Sistema` (COL-05). */
    public const SISTEMA = 'sys';

    /** Conferência positiva: aprovou foto, aprovou para publicação, conferiu alteração, resolveu. */
    public const APROVACAO = 'ok';

    /** Mudança que REABRE a aprovação (BLOQ-05): preço, frete confirmado, foto principal. */
    public const ALTERACAO = 'change';

    /** Pedido de ajuste e sua resolução (DADO-09). */
    public const AJUSTE = 'adjust';

    /** Reprovação de foto, com o motivo escrito. */
    public const REPROVACAO = 'reject';

    /** Falha na publicação, com **o erro REAL do ML** (PUB-05). */
    public const ERRO = 'error';

    /** ⚠️ A exceção autorizada (BLOQ-09) — o evento que torna a auditoria-depois possível. */
    public const EXCECAO = 'exc';

    /** Revisão parcial salva, para outra pessoa continuar de onde parou. */
    public const REVISAO_PARCIAL = 'save';

    /**
     * Os 8 tipos: chave de dado => **ícone + rótulo pt-BR + cor**.
     *
     * ⚠️ **VOC-05 — nunca só cor/ícone.** O protótipo tem SÓ cor e glifo (`HK`, `:1386`); o
     * **rótulo pt-BR é o que este plano acrescenta**, e é ele que torna a linha do tempo legível
     * para quem não decorou os glifos. Um pontinho colorido que ninguém sabe ler, no único
     * registro que prova a auditoria-depois, não serve.
     *
     * ⚠️ A ORDEM é a do `HK` do protótipo, de propósito: a conferência é lida linha a linha
     * contra ele, e reordenar faria a medição comparar pares trocados.
     *
     * ⚠️ `error` e `adjust` dividem o glifo `!`, e `save` e `sys` dividem o `•` — isso é do
     * protótipo e **é exatamente por isso que o rótulo é obrigatório**: o glifo sozinho não
     * distingue "falha na publicação" de "ajuste solicitado".
     */
    public const TIPOS = [
        self::APROVACAO => ['icone' => '✓', 'rotulo' => 'Aprovação', 'cor' => '#22C55E'],
        self::ALTERACAO => ['icone' => '↻', 'rotulo' => 'Alteração', 'cor' => '#FACC15'],
        self::AJUSTE => ['icone' => '!', 'rotulo' => 'Ajuste solicitado', 'cor' => '#F59E0B'],
        self::REPROVACAO => ['icone' => '×', 'rotulo' => 'Reprovação', 'cor' => '#EF4444'],
        self::ERRO => ['icone' => '!', 'rotulo' => 'Falha na publicação', 'cor' => '#EF4444'],
        self::EXCECAO => ['icone' => '§', 'rotulo' => 'Exceção autorizada', 'cor' => '#D4D4D8'],
        self::REVISAO_PARCIAL => ['icone' => '•', 'rotulo' => 'Revisão parcial', 'cor' => '#A1A1AA'],
        self::SISTEMA => ['icone' => '•', 'rotulo' => 'Sistema', 'cor' => '#52525B'],
    ];

    /** O nome exibido do ator da automação — `EMP.SYS` do protótipo (`:915`). */
    public const AUTOR_SISTEMA = 'Sistema';

    /** COL-06 — o formato com que a data SAI do servidor. O cliente nunca formata data aqui. */
    public const FORMATO_DATA = 'd/m/Y H:i';

    protected $guarded = ['id'];

    /**
     * ⚠️ O default do modelo repete o da coluna porque **o Eloquent não lê o default do banco** —
     * defeito REAL medido no plano 186-04 (o objeto vinha com `null` em memória enquanto a linha
     * nascia com o default, e a primeira decisão tomada sobre ele saía errada). Há gate comparando
     * com o default lido do **SCHEMA VIVO**, nunca com outra constante do PHP.
     */
    protected $attributes = [
        'ator_sistema' => false,
    ];

    protected $casts = [
        'oferta_id' => 'integer',
        'autor_id' => 'integer',
        'ator_sistema' => 'boolean',
        'versao' => 'integer',
        'ocorrido_em' => 'datetime',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoOferta::class, 'oferta_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    /** O tipo é um dos 8? Guarda de tipo ANTES do lookup (REND-03). */
    public static function tipoValido(mixed $tipo): bool
    {
        if (! is_string($tipo) || trim($tipo) === '') {
            return false;
        }

        return array_key_exists($tipo, self::TIPOS);
    }

    /** A entrada de um tipo, ou `null` se a chave não é chave (REND-03). */
    public static function entradaDe(mixed $tipo): ?array
    {
        return self::tipoValido($tipo) ? self::TIPOS[$tipo] : null;
    }

    /**
     * ⚠️ **O autor exibido, NUNCA vazio** (COL-05).
     *
     * `Sistema` quando o ator é a automação; o nome da pessoa quando há pessoa; e "Responsável
     * removido" quando o usuário saiu pelo `SET NULL` — porque "houve um ato e não sei de quem"
     * é uma informação diferente de "foi o sistema", e esconder a diferença é o que a
     * auditoria-depois não pode fazer.
     */
    public function autorExibido(): string
    {
        if ($this->ator_sistema) {
            return self::AUTOR_SISTEMA;
        }

        $nome = trim((string) $this->autor?->name);

        return $nome === '' ? 'Responsável removido' : $nome;
    }

    /** COL-06 — a data já FORMATADA pelo servidor. Nenhuma data crua sai para o cliente. */
    public function ocorridoFormatado(): string
    {
        return $this->ocorrido_em?->format(self::FORMATO_DATA) ?? '';
    }
}
