<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A **exceção autorizada** que libera a publicação de uma oferta com prejuízo ou margem abaixo do
 * mínimo (Fase 186, DADO-11 / MOD-02 / BLOQ-08 / BLOQ-09).
 *
 * ═══ ⚠️⚠️ DECISÃO TRAVADA, NÃO REABRIR (BLOQ-09, usuário em 14/10/2026) ════════════════════════
 *
 * A exceção é aplicada **DIRETO** por quem revisa: marca, **escreve o motivo (obrigatório)**, e
 * fica gravado **quem foi e quando**. **Aprovação em dois passos foi OFERECIDA e RECUSADA.**
 *
 * ➡️ **O estado "exceção aguardando autorização" NÃO EXISTE nesta milestone.** Não há coluna
 * `status`, `aprovada_por`, `solicitada_em`, `aguardando` nem `pendente`; não há método de
 * solicitar/autorizar; não há aviso nem caixa de pendência. A linha **nasce valendo**
 * (`ativa = true`), e o efeito é **imediato**.
 *
 * **A RAZÃO:** como **aprovar JÁ PUBLICA e não há desfazer**, o controle é **auditoria DEPOIS,
 * não barreira ANTES**. Uma barreira antes só atrasaria quem já tem a decisão na mão; o que
 * precisa existir é o REGISTRO — e é por isso que `liberada_por`, `liberada_em`, `justificativa` e
 * `versao` são gravados na MESMA escrita, e que o evento `exc` do histórico é append-only
 * (T-186-16, repúdio).
 *
 * ⚠️ O README do protótipo pede "fluxo de autorização do gestor". **Vale a decisão do usuário.**
 * ⚠️ **ESTE PARÁGRAFO EXISTE PARA QUE A PRÓXIMA SESSÃO NÃO "COMPLETE" O FLUXO ACHANDO QUE
 * FALTOU.** Se um dia a espera for pedida, o lugar de reabrir é aqui — **com o usuário**, não por
 * dedução de quem lê o protótipo. Há gate de ausência ancorado em FORMA (não em lista de palavras)
 * nos testes deste plano, e o gate da área inteira é do plano 186-09.
 *
 * ═══ ⚠️⚠️ POR QUE `tipo` É pt-BR (`resultado_negativo`), E NÃO `loss` ══════════════════════════
 *
 * `loss` e `margin` **são 2 das 11 chaves de BLOQUEIO**, e aquelas 11 foram traduzidas para pt-BR
 * pela decisão do plano 186-02
 * (`resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:155-168`). O `tipo`
 * da exceção **nomeia qual bloqueio ela libera** — é chave estrangeira POR VALOR naquele
 * namespace. Gravar `loss` aqui recriaria o defeito que a wave 1 consertou: a mesma verdade em
 * dois idiomas, com um `||` aceitando as duas formas mais à frente.
 * ⚠️ A tradução mora em **UM lugar só** (`TIPO_DO_PROTOTIPO`), e há teste que **LÊ o `.jsx`** para
 * provar que as duas pontas batem.
 * ⚠️ É **divergência declarada do plano 186-05**, que escrevia `tipo ∈ {loss, margin}`.
 *
 * ═══ ⚠️ DIVERGÊNCIA DECLARADA DO PROTÓTIPO — A EXCEÇÃO DELE NÃO TEM TIPO (achado S7) ═══════════
 *
 * Medido em `Aprovacao.dc.html:1093-1094`: há **um único `!o.exc`**, e ele suprime `loss` **e**
 * `margin` de uma vez. No protótipo a exceção é um **flag**; o `kind` de `submitException`
 * (`:1203`) só monta a frase do histórico e não decide o que é liberado.
 * ➡️ **Aqui ela TEM tipo, e uma não libera a outra.** Motivos: **(a) BLOQ-08** — tipada, o
 * conjunto liberado é exatamente o autorizado; **(b) BLOQ-09** — liberar "resultado negativo" e
 * liberar "margem abaixo do mínimo" são **dois atos diferentes, com justificativas diferentes**, e
 * **não há desfazer**. Com o flag único, autorizar margem liberaria prejuízo de graça.
 * ⚠️ O nosso comportamento é **mais restrito** que o do protótipo, nunca mais permissivo. A regra
 * "o protótipo ganha" continua valendo para a **FORMA** e os **RÓTULOS** (a frase exibida é a
 * dele, `:1142`, com o glifo `§` de `HK.exc`, `:1386`); o que divergiu é a **granularidade de um
 * registro de auditoria**, que não é forma nem rótulo.
 */
class PubAprovacaoExcecao extends Model
{
    protected $table = 'pub_aprovacao_excecoes';

    /** O mínimo do protótipo (`submitException`, `:1203`: `(F.reason||'').trim().length < 10`). */
    public const MINIMO_JUSTIFICATIVA = 10;

    /** O bloqueio `loss` do protótipo, em pt-BR (`BLOQUEIO_DO_PROTOTIPO`). */
    public const RESULTADO_NEGATIVO = 'resultado_negativo';

    /** O bloqueio `margin` do protótipo, em pt-BR. */
    public const MARGEM_ABAIXO_DO_MINIMO = 'margem_abaixo_do_minimo';

    /**
     * ⚠️⚠️ **BLOQ-08 — A LISTA FECHADA DO QUE A EXCEÇÃO LIBERA. São DOIS, e só dois.**
     *
     * **Nunca** fotos (`fotos_a_conferir`, `sem_fotos`, `alerta_em_foto_de_ia`), **nunca** frete
     * (`frete_ausente`), **nunca** alteração de preço não conferida (`alteracao_nao_conferida`),
     * **nunca** ajuste em aberto (`ajuste_em_aberto`), **nunca** título (`titulo_longo`), **nunca**
     * preparação (`em_preparacao`) nem falha (`falha_na_publicacao`).
     *
     * **Por que lista fechada e não lista de proibições:** uma lista de proibições deixa permitido
     * tudo que ninguém lembrou de proibir — e aqui "permitido a mais" significa **publicar um
     * anúncio que ninguém conferiu** (T-186-17, elevação de privilégio), sem desfazer.
     */
    public const LIBERA = [self::RESULTADO_NEGATIVO, self::MARGEM_ABAIXO_DO_MINIMO];

    /** ⚠️ MAPA DE CONFERÊNCIA com o protótipo — a tradução mora aqui e em nenhum outro lugar. */
    public const TIPO_DO_PROTOTIPO = [
        'loss' => self::RESULTADO_NEGATIVO,
        'margin' => self::MARGEM_ABAIXO_DO_MINIMO,
    ];

    /**
     * Os rótulos pt-BR dos dois tipos — **espelho** de `ABREVIACAO_DO_BLOQUEIO`/
     * `ROTULO_DO_BLOQUEIO` do módulo do plano 186-02 (VOC-05: nunca só chave).
     */
    public const ROTULOS = [
        self::RESULTADO_NEGATIVO => 'Resultado negativo',
        self::MARGEM_ABAIXO_DO_MINIMO => 'Margem abaixo do mínimo',
    ];

    /** COL-06 — a data sai FORMATADA do servidor; o cliente nunca formata data aqui. */
    public const FORMATO_DATA = 'd/m/Y H:i';

    protected $guarded = ['id'];

    /**
     * ⚠️ **A exceção NASCE VALENDO** (não há estado de espera — ver o docblock da classe), e o
     * default repete o da coluna porque **o Eloquent não lê o default do banco** (defeito real do
     * plano 186-04). Sem isto, quem acabasse de aplicar a exceção veria o bloqueio continuar de pé
     * em memória — e, como aprovar já publica, isso é visível em produção. Gate compara com o
     * SCHEMA VIVO.
     */
    protected $attributes = [
        'ativa' => true,
    ];

    protected $casts = [
        'oferta_id' => 'integer',
        'liberada_por' => 'integer',
        'liberada_em' => 'datetime',
        'versao' => 'integer',
        'ativa' => 'boolean',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PubAprovacaoOferta::class, 'oferta_id');
    }

    /** ⚠️ **QUEM LIBEROU** — e não há um segundo ator: não existe "quem autorizou" separado. */
    public function liberadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberada_por');
    }

    /**
     * ⚠️ **BLOQ-09 — o motivo escrito é OBRIGATÓRIO**, com `trim()` antes de contar (é o que o
     * protótipo faz): "só espaço" não é motivo escrito.
     *
     * Guarda de tipo antes (REND-03): valor que não é string é `false`, não `TypeError`.
     */
    public static function justificativaValida(mixed $justificativa): bool
    {
        if (! is_string($justificativa)) {
            return false;
        }

        return mb_strlen(trim($justificativa)) >= self::MINIMO_JUSTIFICATIVA;
    }

    /**
     * ⚠️ **BLOQ-08 — esta exceção pode liberar o bloqueio `$bloqueio`?**
     *
     * Lista fechada (`LIBERA`), com guarda de tipo antes. ⚠️ **As chaves INGLESAS do protótipo
     * (`loss`, `margin`) são `false`**: não existe `||` aceitando as duas formas — é o defeito
     * grave achado na verificação da wave 1.
     */
    public static function libera(mixed $bloqueio): bool
    {
        if (! is_string($bloqueio) || trim($bloqueio) === '') {
            return false;
        }

        return in_array($bloqueio, self::LIBERA, true);
    }

    /** O tipo desta exceção é um dos dois? Mesma lista fechada de `libera()`. */
    public static function tipoValido(mixed $tipo): bool
    {
        return self::libera($tipo);
    }

    /** O rótulo pt-BR do tipo, ou `null` se a chave não é chave (REND-03). */
    public function rotulo(): ?string
    {
        return self::ROTULOS[$this->tipo] ?? null;
    }

    /**
     * A frase de exibição — **a FORMA do protótipo** (`:1142`: `'Exceção autorizada por ' + nome +
     * ' · ' + at`), com o glifo `§` de `HK.exc` (`:1386`) na frente.
     *
     * ⚠️ Com `liberada_por` nulo (usuário apagado pelo `SET NULL`) a frase **não quebra e não
     * mente**: ela diz que o responsável saiu, nunca "por " seguido de nada. Nome em branco no
     * registro que prova a auditoria-depois é pior que nenhum registro, porque parece completo.
     */
    public function fraseDeExibicao(): string
    {
        $glifo = PubAprovacaoEvento::TIPOS[PubAprovacaoEvento::EXCECAO]['icone'];
        $nome = trim((string) $this->liberadaPor?->name);
        $nome = $nome === '' ? 'responsável removido' : $nome;
        $quando = $this->liberada_em?->format(self::FORMATO_DATA) ?? '';

        return sprintf('%s Exceção autorizada por %s · %s · %s', $glifo, $nome, $quando, $this->justificativa);
    }

    /**
     * As exceções **em vigor** de uma oferta.
     *
     * ⚠️ `ativa` é o único filtro de vigência — **não há `status`** a conferir (não existe estado
     * de espera, ver o docblock da classe).
     *
     * @return Builder<self>
     */
    public static function vigentesDa(int $ofertaId): Builder
    {
        return static::query()->where('oferta_id', $ofertaId)->where('ativa', true);
    }
}
