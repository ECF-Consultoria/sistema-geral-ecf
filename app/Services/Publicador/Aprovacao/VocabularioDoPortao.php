<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O VOCABULÁRIO DE BLOQUEIO DO PORTÃO — **a fonte PHP**, que o módulo `.jsx` espelha.
 *
 * ═══ ⚠️⚠️ POR QUE ESTE ARQUIVO EXISTE (era LACUNA, achado da verificação) ══════════════════════
 *
 * `BLOQ-12` manda o rodapé mostrar **"Bloqueado: {abreviação} +N"**, e as **9** abreviações
 * (`SHORT` do protótipo, `Aprovacao.dc.html:974`) estavam **somente** em
 * `resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx` — um módulo **JSX**.
 * Mas quem monta o rodapé é o SERVIDOR ({@see BloqueiosDaOfertaService::resumoDoRodape()}). Sem um
 * lado PHP, o gate (g) do plano 186-09 compararia uma **sincronia sem um dos lados** — gate vazio
 * por construção, que é a armadilha medida nesta fase mais de uma vez.
 *
 * ➡️ **Decisão do plano 186-08: o PHP é A FONTE, o JSX espelha.** Não há enum compartilhado entre
 * PHP e JS neste projeto (a convenção do repositório é explícita: *"No shared enum type between PHP
 * and JS — kept in sync manually"*), então a sincronia é **medida por teste que LÊ o `.jsx`**
 * ({@see \Tests\Unit\Publicador\Aprovacao\BloqueiosDaOfertaServiceTest}) em vez de confiada a duas
 * cópias feitas à mão. ⚠️ Aquele arquivo `.jsx` é **SOMENTE LEITURA** para este plano.
 *
 * ═══ ⚠️⚠️ AS 11 CHAVES SÃO pt-BR, E NUNCA SE ACEITA "AS DUAS FORMAS" ═══════════════════════════
 *
 * As chaves INGLESAS do protótipo (`photos`, `nophoto`, `freight`, `loss`, `margin`, `change`,
 * `adjust`, `title`, `prep`, `alert`, `failed`) **não são chave de dado em lugar nenhum**: elas
 * foram traduzidas pelo plano 186-02 e vivem aqui **só** em {@see self::BLOQUEIO_DO_PROTOTIPO},
 * que é o mapa de conferência. ⚠️ **Nunca um `||` aceitando as duas formas** — é o bug dos dois
 * idiomas com outra roupa, achado como **defeito grave** na verificação da wave 1 e já evitado da
 * mesma maneira em {@see \App\Models\PubAprovacaoExcecao::libera()} e em
 * {@see \App\Models\PubAprovacaoAjuste::escopoValido()}.
 *
 * ═══ UM NAMESPACE DE CHAVE, DUAS RENDERIZAÇÕES (a mesma razão do módulo `.jsx`) ════════════════
 *
 * `SHORT` (9) e `BLK` (8) do protótipo descrevem os **MESMOS** conceitos com palavras diferentes:
 * `SHORT` é a abreviação que entra na linha da fila depois de "Bloqueado: "; `BLK` é o rótulo do
 * filtro "Motivo de bloqueio". **Seis** conceitos aparecem nos dois, **três** só em `SHORT`
 * (`sem_fotos`, `titulo_longo`, `em_preparacao`) e **dois** só em `BLK`
 * (`alerta_em_foto_de_ia`, `falha_na_publicacao`) — união de **ONZE**. Dar nome diferente ao mesmo
 * conceito nos dois mapas recriaria duas chaves para a mesma coisa, sem tradutor.
 *
 * ⚠️ **Os 8 BLOQUEIOS são os 8 que `info()` empilha em `B`** (`:1089-:1097`):
 * `em_preparacao`, `fotos_a_conferir`, `sem_fotos`, a cadeia exclusiva
 * (`frete_ausente` → `resultado_negativo` → `margem_abaixo_do_minimo` — **UM** bloqueio por
 * oferta), `alteracao_nao_conferida`, `ajuste_em_aberto` e `titulo_longo`. As outras duas chaves
 * (`alerta_em_foto_de_ia` e `falha_na_publicacao`) **não são bloqueio**: são `kinds` acrescentados
 * depois (`:1098`) e usados pelo filtro. Confundi-las faria o filtro de bloqueio esconder linhas
 * pelo ESTADO da oferta.
 *
 * ═══ ⚠️ VOC-05 / VOC-06 — ÍCONE + TEXTO, NUNCA SÓ COR; CHAVE NUNCA VAI À TELA ══════════════════
 *
 * Todo bloqueio carrega o glifo `!` ({@see self::GLIFO}) junto da cor, porque cor sozinha não é
 * sinal. E a **chave** (`frete_ausente`…) é dado: o que vai à tela é a abreviação ou o rótulo.
 *
 * ⚠️ As cores vêm de `:1295` e `:1238`: `resultado_negativo`, `frete_ausente`, `sem_fotos` — e
 * `fotos_a_conferir` **quando há alerta** — são **vermelhos**; os outros, **âmbar**. A cor da
 * `fotos_a_conferir` depende do CONTEXTO (ter ou não alerta), e é por isso que
 * {@see self::severidade()} recebe esse segundo argumento em vez de ler um mapa fixo.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
final class VocabularioDoPortao
{
    // ─── As 11 chaves de bloqueio, em pt-BR (plano 186-02) ──────────────────────────────────

    public const FOTOS_A_CONFERIR = 'fotos_a_conferir';

    public const SEM_FOTOS = 'sem_fotos';

    public const FRETE_AUSENTE = 'frete_ausente';

    public const RESULTADO_NEGATIVO = 'resultado_negativo';

    public const MARGEM_ABAIXO_DO_MINIMO = 'margem_abaixo_do_minimo';

    public const ALTERACAO_NAO_CONFERIDA = 'alteracao_nao_conferida';

    public const AJUSTE_EM_ABERTO = 'ajuste_em_aberto';

    public const TITULO_LONGO = 'titulo_longo';

    public const EM_PREPARACAO = 'em_preparacao';

    /** ⚠️ **NÃO é bloqueio** — é `kind` (`:1098`) e rótulo de filtro. */
    public const ALERTA_EM_FOTO_DE_IA = 'alerta_em_foto_de_ia';

    /** ⚠️ **NÃO é bloqueio** — é `kind` (`:1098`). Namespace diferente do andamento `falha`. */
    public const FALHA_NA_PUBLICACAO = 'falha_na_publicacao';

    /**
     * ⚠️ Os **8** que `info()` empilha em `B`, **NA ORDEM EM QUE NASCEM** (`:1089-:1097`).
     *
     * A ordem é parte da especificação, não arrumação: o rodapé abrevia **o PRIMEIRO**
     * (`:1238`), e a ação de correção que a tela oferece primeiro é a dele. Reordenar aqui muda o
     * que a pessoa é mandada fazer, sem nenhum teste de render acusar.
     */
    public const BLOQUEIOS_EM_ORDEM = [
        self::EM_PREPARACAO,
        self::FOTOS_A_CONFERIR,
        self::SEM_FOTOS,
        self::FRETE_AUSENTE,
        self::RESULTADO_NEGATIVO,
        self::MARGEM_ABAIXO_DO_MINIMO,
        self::ALTERACAO_NAO_CONFERIDA,
        self::AJUSTE_EM_ABERTO,
        self::TITULO_LONGO,
    ];

    /**
     * ⚠️ **OS 8 BLOQUEIOS da fase** — {@see self::BLOQUEIOS_EM_ORDEM} **sem** `em_preparacao`.
     *
     * A diferença não é contagem solta: `em_preparacao` é **aviso de preparo**, com correção
     * **vazia** (`:1089`) porque não há nada que a pessoa possa fazer; os outros **oito** são
     * coisas a consertar, cada um com a sua ação. É por isso que a fase fala em "os 8 bloqueios"
     * e `SHORT` tem **nove** entradas — e deixar isso implícito já custou uma contagem errada
     * neste plano.
     */
    public const OS_OITO = [
        self::FOTOS_A_CONFERIR,
        self::SEM_FOTOS,
        self::FRETE_AUSENTE,
        self::RESULTADO_NEGATIVO,
        self::MARGEM_ABAIXO_DO_MINIMO,
        self::ALTERACAO_NAO_CONFERIDA,
        self::AJUSTE_EM_ABERTO,
        self::TITULO_LONGO,
    ];

    /**
     * ⚠️⚠️ **A CADEIA EXCLUSIVA, declarada como DADO** (`:1092-:1094`).
     *
     * `frete_ausente` → `resultado_negativo` → `margem_abaixo_do_minimo` é
     * `if / elseif / elseif`: **no máximo UM dos três** nasce por oferta, e nesta ordem. Faltando
     * dado **não existe** prejuízo nem margem, **porque não há resultado** para julgar.
     */
    public const CADEIA_EXCLUSIVA = [
        self::FRETE_AUSENTE,
        self::RESULTADO_NEGATIVO,
        self::MARGEM_ABAIXO_DO_MINIMO,
    ];

    /**
     * As **9** abreviações da linha da fila — `SHORT` (`Aprovacao.dc.html:974`).
     *
     * ⚠️ Minúscula no começo **de propósito**: ela é emendada depois de
     * {@see self::BLOQUEADO_PREFIXO}, formando "Bloqueado: fotos a conferir". Capitalizar aqui
     * produziria "Bloqueado: Fotos a conferir" na tela.
     */
    public const ABREVIACOES = [
        self::FOTOS_A_CONFERIR => 'fotos a conferir',
        self::SEM_FOTOS => 'sem fotos',
        self::FRETE_AUSENTE => 'frete pendente',
        self::RESULTADO_NEGATIVO => 'resultado negativo',
        self::MARGEM_ABAIXO_DO_MINIMO => 'margem abaixo do mínimo',
        self::ALTERACAO_NAO_CONFERIDA => 'alteração de preço não conferida',
        self::AJUSTE_EM_ABERTO => 'ajuste em aberto',
        self::TITULO_LONGO => 'título longo',
        self::EM_PREPARACAO => 'em preparação',
    ];

    /**
     * Os **8** rótulos do filtro "Motivo de bloqueio" — `BLK` (`Aprovacao.dc.html:973`).
     *
     * ⚠️ "Frete ou custo ausente" é **mais largo** que a abreviação "frete pendente", e isso é do
     * desenho: o filtro agrupa os dois casos do README §D. É também por isso que a chave
     * `frete_ausente` cobre **qualquer** das cinco linhas que anulam o resultado
     * ({@see PubAprovacaoComposicao::ANULAM_O_RESULTADO}) — ver o texto montado em
     * {@see BloqueiosDaOfertaService}.
     */
    public const ROTULOS = [
        self::FOTOS_A_CONFERIR => 'Fotos a conferir',
        self::ALERTA_EM_FOTO_DE_IA => 'Alerta em foto de IA',
        self::RESULTADO_NEGATIVO => 'Resultado negativo',
        self::MARGEM_ABAIXO_DO_MINIMO => 'Margem abaixo do mínimo',
        self::FRETE_AUSENTE => 'Frete ou custo ausente',
        self::AJUSTE_EM_ABERTO => 'Ajuste solicitado',
        self::ALTERACAO_NAO_CONFERIDA => 'Alteração não conferida',
        self::FALHA_NA_PUBLICACAO => 'Falha na publicação',
    ];

    /**
     * ⚠️ MAPA DE CONFERÊNCIA — as **11** chaves do protótipo (`SHORT` ∪ `BLK`) para as nossas.
     *
     * **BIJETIVO** com a união das chaves dos dois mapas acima, e há teste provando nos dois
     * sentidos: mapa incompleto deixaria um bloqueio sem medição contra o protótipo, que é a
     * definição de gate que passa por omissão.
     */
    public const BLOQUEIO_DO_PROTOTIPO = [
        'photos' => self::FOTOS_A_CONFERIR,
        'nophoto' => self::SEM_FOTOS,
        'freight' => self::FRETE_AUSENTE,
        'loss' => self::RESULTADO_NEGATIVO,
        'margin' => self::MARGEM_ABAIXO_DO_MINIMO,
        'change' => self::ALTERACAO_NAO_CONFERIDA,
        'adjust' => self::AJUSTE_EM_ABERTO,
        'title' => self::TITULO_LONGO,
        'prep' => self::EM_PREPARACAO,
        'alert' => self::ALERTA_EM_FOTO_DE_IA,
        'failed' => self::FALHA_NA_PUBLICACAO,
    ];

    /**
     * A ação de correção de cada bloqueio — o `fix` de `info()`, com o destino de `fix()`
     * (`:1171-:1178`) no comentário.
     *
     * ⚠️ `em_preparacao` tem correção **VAZIA** de propósito (`:1089`): não há nada que a pessoa
     * possa fazer enquanto o sistema monta a oferta, e um botão ali seria botão que não resolve.
     * ⚠️ `hasFix = !!b.fix && inf.live` (`:1295`): a ação só aparece com a oferta **editável**.
     */
    public const CORRECOES = [
        self::EM_PREPARACAO => '',
        self::FOTOS_A_CONFERIR => 'Conferir fotos',      // aba Fotos, na primeira com alerta
        self::SEM_FOTOS => 'Adicionar fotos',            // aba Fotos
        self::FRETE_AUSENTE => 'Informar frete',         // modal de frete do vendedor
        self::RESULTADO_NEGATIVO => 'Solicitar exceção',  // modal de exceção autorizada
        self::MARGEM_ABAIXO_DO_MINIMO => 'Solicitar exceção',
        self::ALTERACAO_NAO_CONFERIDA => 'Conferir alteração',
        self::AJUSTE_EM_ABERTO => 'Ver ajustes',
        self::TITULO_LONGO => 'Ver conteúdo',
    ];

    /**
     * ⚠️ A correção de `frete_ausente` quando o que falta **não é o frete**.
     *
     * **Divergência DECLARADA do protótipo, e ela é de VERDADE, não de forma.** No protótipo só o
     * frete podia faltar (`fin()` `:1067`: `o.freight == null`), então o `fix` 'Informar frete'
     * sempre servia. Aqui **cinco** linhas anulam o resultado
     * ({@see PubAprovacaoComposicao::ANULAM_O_RESULTADO}), e mandar "Informar frete" quando o que
     * falta é o **custo do produto** seria mandar a pessoa ao lugar errado — a tela diria uma
     * coisa e o dado seria outra, que é exatamente o que VOC-04 existe para impedir.
     */
    public const CORRECAO_SEM_FRETE = 'Solicitar ajuste';

    // ─── VOC-05 — a severidade e o glifo ────────────────────────────────────────────────────

    public const SEVERIDADE_VERMELHA = 'vermelho';

    public const SEVERIDADE_AMBAR = 'ambar';

    /**
     * Os bloqueios **sempre** vermelhos (`:1295` / `:1238`).
     *
     * ⚠️ `fotos_a_conferir` **não** está aqui porque a cor dela depende de haver alerta — ver
     * {@see self::severidade()}.
     */
    public const VERMELHOS = [self::RESULTADO_NEGATIVO, self::FRETE_AUSENTE, self::SEM_FOTOS];

    /** ⚠️ VOC-05 — **ícone + texto, nunca só cor**. O glifo do bloqueio é `!` (`:1295`). */
    public const GLIFO = '!';

    // ─── Os literais fixos do rodapé (BLOQ-12) ──────────────────────────────────────────────

    /** O ramo "nada bloqueia" (`:1297`), literal byte a byte — inclusive a vírgula de Oxford. */
    public const TUDO_CONFERIDO = 'Fotos, preço e conteúdo conferidos';

    /** ⚠️ Carrega o espaço no fim de propósito: é emendado com a abreviação em minúscula. */
    public const BLOQUEADO_PREFIXO = 'Bloqueado: ';

    /** README §D: "Custo ou frete ausente não vira zero." Zero afirmaria que é de graça. */
    public const INCOMPLETO = 'Incompleto';

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Os acessores. ⚠️ REND-03 (guarda de tipo ANTES de indexar o mapa): em PHP, chave que não é
    // string nem int **lança TypeError**, e string desconhecida emite warning. Desconhecido
    // devolve `null` — no servidor `null` quer dizer "não conheço esta chave", e quem decide
    // trata.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** Lookup seguro num dos mapas desta classe. `null` = a chave não é chave. */
    private static function entradaDe(array $mapa, mixed $chave): ?string
    {
        if (! is_string($chave) || trim($chave) === '') {
            return null;
        }

        return array_key_exists($chave, $mapa) ? $mapa[$chave] : null;
    }

    /** A abreviação da linha da fila, ou `null`. */
    public static function abreviacaoDe(mixed $chave): ?string
    {
        return self::entradaDe(self::ABREVIACOES, $chave);
    }

    /** O rótulo do filtro "Motivo de bloqueio", ou `null`. */
    public static function rotuloDe(mixed $chave): ?string
    {
        return self::entradaDe(self::ROTULOS, $chave);
    }

    /** A ação de correção, ou `null` se a chave não é chave de bloqueio. */
    public static function correcaoDe(mixed $chave): ?string
    {
        return self::entradaDe(self::CORRECOES, $chave);
    }

    /** A chave é uma das 11? Lista fechada, guarda de tipo antes. */
    public static function chaveValida(mixed $chave): bool
    {
        if (! is_string($chave) || trim($chave) === '') {
            return false;
        }

        return in_array($chave, array_values(self::BLOQUEIO_DO_PROTOTIPO), true);
    }

    /** É um dos 8 que `info()` empilha em `B`? (`alerta…` e `falha…` são `kinds`, não bloqueios.) */
    public static function ehBloqueio(mixed $chave): bool
    {
        if (! is_string($chave)) {
            return false;
        }

        return in_array($chave, self::BLOQUEIOS_EM_ORDEM, true);
    }

    /**
     * A cor do bloqueio (`:1295`), **com o contexto**: `fotos_a_conferir` é vermelha **só** quando
     * há alerta automático numa das pendentes.
     */
    public static function severidade(mixed $chave, bool $comAlerta = false): string
    {
        if (is_string($chave) && in_array($chave, self::VERMELHOS, true)) {
            return self::SEVERIDADE_VERMELHA;
        }

        if ($chave === self::FOTOS_A_CONFERIR && $comAlerta) {
            return self::SEVERIDADE_VERMELHA;
        }

        return self::SEVERIDADE_AMBAR;
    }

    /**
     * Os **3** selos de origem financeira (VOC-04), **LIDOS** de
     * {@see PubAprovacaoComposicao::ORIGENS_DA_LINHA} e **não recopiados**.
     *
     * ⚠️ Repetir os três rótulos aqui criaria duas verdades livres para divergir, e a que
     * divergisse seria a que a pessoa lê. Esta classe é a fonte das **chaves de bloqueio**; a dos
     * selos de origem já existia desde a wave 4, e a sincronia com o `.jsx` dela é medida pelo
     * mesmo teste.
     *
     * @return array<string, string>
     */
    public static function selosDeOrigem(): array
    {
        return PubAprovacaoComposicao::ORIGENS_DA_LINHA;
    }
}
