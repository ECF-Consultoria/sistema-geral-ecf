<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * OS **8 BLOQUEIOS** DA OFERTA DO PORTÃO, CALCULADOS **NO SERVIDOR, POR OFERTA** — cada um com
 * texto, ação de correção, severidade e glifo.
 *
 * ═══ ⚠️⚠️ POR QUE ESTE ARQUIVO É A PEÇA DE SEGURANÇA DA MILESTONE ══════════════════════════════
 *
 * O usuário decidiu em **14/10/2026** que **APROVAR JÁ PUBLICA**: sem segundo clique, e **dois
 * atos e janela de desfazer foram OFERECIDOS e RECUSADOS**. ➡️ Como **não existe desfazer**, a
 * reversibilidade mora **toda ANTES do clique**: estes bloqueios são a **ÚNICA trava** contra
 * publicar errado. Eles não são enfeite de UI.
 *
 * ⚠️ O rótulo do botão **NÃO muda**: continua o literal do protótipo, **"Aprovar para
 * publicação"**. A decisão mudou o **comportamento**, não o texto. ⚠️ E **não** existe aqui (nem
 * em lugar nenhum desta área) "confirmar publicação", "segundo passo", "desfazer", "undo" ou
 * "cancelar aprovação" — nem bloqueio, nem chave, nem aviso. O único ponto de extensão é o
 * `aoAprovar()` de {@see AndamentoDaOfertaService}, que a **Fase 193** preenche.
 *
 * ═══ ⚠️⚠️ A CADEIA `frete_ausente → resultado_negativo → margem_abaixo_do_minimo` É EXCLUSIVA ══
 *
 * Medido por leitura de `design_handoff_publicador/Aprovacao.dc.html:1092-1094`: ali há **um
 * `if` e dois `else if`**, não três `if`. ➡️ **No máximo UM dos três nasce por oferta, e nesta
 * ordem.**
 *
 * **O POR QUÊ, em uma frase:** *faltando dado não há RESULTADO, então prejuízo e margem não
 * existem para ser julgados.* Três `if` independentes produziriam **três** bloqueios onde o
 * protótipo produz **um**, e a tela diria "Resultado negativo: R$ …" sobre um número que **não
 * existe** — afirmação falsa sobre o dinheiro do cliente, com a oferta travada por três motivos
 * dos quais dois são inventados.
 *
 * ═══ ⚠️⚠️ `loss`/`margin` DISPARAM SOBRE O VALOR **ESTIMADO POR BAIXO** ════════════════════════
 *
 * Decisão (7) do usuário, 14/10/2026: "Embalagem" **não tem fonte no repositório** e nasce
 * `Pendente` **sem anular** o resultado — a composição sai com `estimadoPorBaixo = true` e a nota
 * diz *"sem o custo de embalagem"* (ver {@see ComposicaoDaOferta}). ⚠️ **Quem anula o resultado
 * são só as CINCO** de {@see PubAprovacaoComposicao::ANULAM_O_RESULTADO}: custo, comissão, tarifa
 * fixa, frete e imposto.
 *
 * ➡️ **Os bloqueios de prejuízo e de margem disparam sobre esse valor estimado**, e isso é
 * segurança, não detalhe: o resultado estimado é **OTIMISTA** (falta um custo real para
 * descontar), então a margem **de verdade é MENOR** que a mostrada. Com aprovar-já-publica e sem
 * desfazer, **não bloquear seria o erro grave**.
 *
 * ═══ ⚠️⚠️ O BLOQUEIO DE FOTOS CONSIDERA **TODOS OS GRUPOS DO RASCUNHO** (achado S2) ════════════
 *
 * No protótipo a oferta tem **um** conjunto (`o.set`). Aqui um rascunho tem **um conjunto por
 * grupo de fotos** (`ResolvedorGruposImagem::chaveDoGrupo()`: um por valor de eixo que define
 * foto, **mais o `GENERAL`** — 3 cores = **4** grupos).
 *
 * ➡️ **Decisão do usuário, 14/10/2026: o pendente é a UNIÃO das imagens não aprovadas de TODOS os
 * conjuntos do rascunho** — variações **e** gerais. A oferta só fica aprovável com **todas**
 * conferidas. **Aprovação cor por cor foi OFERECIDA e RECUSADA**, porque aprovar já publica e não
 * há desfazer: foto que ninguém olhou não pode ir ao ar.
 *
 * ⚠️ A leitura é `PubAprovacaoConjunto::doRascunho($oferta->rascunho_id)` — **a oferta não tem
 * `conjunto_id`**, e isso é de propósito (plano 186-04). Com uma FK só, as fotos dos outros grupos
 * e as `GENERAL` ficariam **FORA** do bloqueio.
 * ⚠️ **POUCAS CONSULTAS, E NENHUMA QUE CRESÇA COM O NÚMERO DE GRUPOS:** uma para os conjuntos,
 * uma `whereIn` para as imagens de todos eles, e os alertas vêm por eager loading. **Nunca uma
 * consulta por grupo** — o N+1 de `resolverAtor()` é dívida conhecida deste projeto justamente por
 * isso, e no lote (BLOQ-10) uma consulta por grupo multiplicaria por N a página inteira.
 *
 * ⚠️ **A ORDEM DOS GRUPOS É DECISÃO DESTE PLANO, declarada:** `GENERAL` primeiro, depois os demais
 * por `id` (ordem de criação, estável). O plano falava em ordenar por `posicao`, mas
 * **`pub_aprovacao_conjuntos` NÃO TEM coluna `posicao`** — medido em
 * `database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php:185-191`. O
 * protótipo não tinha como ter essa ordem (ele tem um conjunto só), então não há literal a
 * obedecer; o que importa é ser **determinística**, senão a foto que a tela abre muda entre duas
 * chamadas.
 *
 * ═══ ⚠️ VOC-06 — AS CHAVES SÃO DADO, O TEXTO É O QUE VAI À TELA ════════════════════════════════
 *
 * `fotos_a_conferir`, `sem_fotos`, `frete_ausente`, `resultado_negativo`,
 * `margem_abaixo_do_minimo`, `alteracao_nao_conferida`, `ajuste_em_aberto` e `titulo_longo` são
 * **chaves** ({@see VocabularioDoPortao}); o que a pessoa lê é `texto` / `abreviacao` / `rotulo`.
 * ⚠️ E **nunca as chaves inglesas do protótipo** — a tradução mora num lugar só
 * ({@see VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO}) e não há `||` aceitando as duas formas.
 *
 * ⚠️ **VOC-05 — ícone + texto, nunca só cor:** todo bloqueio sai com `glifo` `!` ao lado da
 * `severidade`.
 *
 * ═══ ⚠️ O QUE ESTE SERVIÇO **NÃO** FAZ ═════════════════════════════════════════════════════════
 *
 * - **Não escreve nada.** Nem `andamento` (a única porta é {@see AndamentoDaOfertaService}), nem
 *   `versao`, nem composição congelada. Ele responde uma pergunta.
 * - **Não monta botão.** Ele entrega o dado `aprovavel` e o motivo; o botão `disabled=` nasce na
 *   **Fase 188**.
 * - **Não publica.** A oferta aprovada vai à publicação pela **Fase 193**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class BloqueiosDaOfertaService
{
    /** O teto do título do Mercado Livre (`:1097`: `o.title.length > 60`). 60 **não** bloqueia. */
    public const TITULO_MAXIMO = 60;

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    public function __construct(
        private ComposicaoFinanceiraService $composicao,
        private AjustesDaOfertaService $ajustes,
    ) {}

    /**
     * Os bloqueios desta oferta, na ordem em que `info()` os empilha.
     *
     * ⚠️ **UMA leitura de composição por oferta**, fora de qualquer laço (T-186-38): no lote, uma
     * leitura por linha chamaria a API do ML por linha.
     *
     * @return array{
     *     bloqueios: list<array{chave: string, texto: string, correcao: string, severidade: string, glifo: string}>,
     *     chaves: list<string>,
     *     kinds: list<string>,
     *     aprovavel: bool,
     *     ao_vivo: bool,
     *     resumo: string,
     *     na_galeria: int,
     *     pendentes: int,
     *     com_alerta: int,
     *     foco_imagem_id: int|null,
     *     composicao: ComposicaoDaOferta
     * }
     */
    public function para(PubAprovacaoOferta $oferta): array
    {
        $aoVivo = $oferta->ehLive();

        // ─── As fotos de TODOS os grupos do rascunho, em poucas consultas fixas ──────────────
        $galeria = $this->galeriaDoRascunho($oferta);
        $naGaleria = $galeria['na_galeria'];
        $pendentes = $galeria['pendentes'];
        $comAlerta = $galeria['com_alerta'];

        // ─── A composição financeira: UMA leitura ───────────────────────────────────────────
        $composicao = $this->composicao->para($oferta);

        // ─── As exceções EM VIGOR: UMA consulta. `ativa` é o único filtro de vigência. ───────
        $liberados = PubAprovacaoExcecao::vigentesDa((int) $oferta->id)->pluck('tipo')->all();

        // ─── Os ajustes abertos que afetam a oferta: UMA consulta cobrindo os 3 escopos ──────
        $ajustesAbertos = $this->ajustes->abertosQueAfetam($oferta)->count();

        $bloqueios = [];

        // (1) `prep` — `:1089`. A frase REAL do que falta (AUTO-01), não um rótulo genérico.
        if ($oferta->andamento === PubAprovacaoOferta::PREPARANDO) {
            $bloqueios[] = $this->bloqueio(
                VocabularioDoPortao::EM_PREPARACAO,
                'Sistema ainda '.trim((string) $oferta->preparo_frase)
            );
        }

        // (2) `photos` — `:1090`. A UNIÃO de todos os grupos (ver o docblock da classe).
        if ($pendentes->isNotEmpty()) {
            $bloqueios[] = $this->bloqueio(
                VocabularioDoPortao::FOTOS_A_CONFERIR,
                $this->textoDasFotos($pendentes->count(), $comAlerta->count()),
                $comAlerta->isNotEmpty()
            );
        }

        // (3) `nophoto` — `:1091`. IMG-12: galeria vazia **diz** que está vazia; nunca tela muda.
        if ($naGaleria->isEmpty()) {
            $bloqueios[] = $this->bloqueio(VocabularioDoPortao::SEM_FOTOS, 'Nenhuma foto na publicação');
        }

        // ═══ (4) ⚠️⚠️ A CADEIA EXCLUSIVA — `if / elseif / elseif`, NUNCA três `if` ═══════════
        //
        // `:1092-1094`. Faltando dado não há resultado, então prejuízo e margem não existem para
        // ser julgados. Ver o docblock da classe.
        // ⚠️⚠️ E REPARE ONDE A EXCEÇÃO ENTRA: ela decide **se o bloqueio é EMPILHADO**, nunca a
        // condição da cadeia. É assim no protótipo (`!o.exc` está dentro do `if`, junto do
        // `push`, e o `else if` seguinte só é avaliado quando o FATO anterior é falso) e a
        // diferença foi **medida como defeito REAL em 15/10/2026**, na primeira redação deste
        // arquivo: com o `!exc` dentro da condição, autorizar o **prejuízo** fazia nascer
        // `margem_abaixo_do_minimo` no lugar dele — porque resultado negativo é SEMPRE margem
        // abaixo do mínimo. A pessoa precisaria de **DUAS** exceções para o MESMO fato, e o caso
        // pior (prejuízo) pediria o dobro do trabalho do caso mais leve. ➡️ O fato escolhe o elo;
        // a exceção só apaga o bloqueio daquele elo.
        if ($composicao->incompleto) {
            [$texto, $correcao] = $this->freteOuCusto($composicao);
            $bloqueios[] = $this->bloqueio(VocabularioDoPortao::FRETE_AUSENTE, $texto, false, $correcao);
        } elseif ($composicao->resultado !== null && $composicao->resultado < 0) {
            if (! in_array(PubAprovacaoExcecao::RESULTADO_NEGATIVO, $liberados, true)) {
                $bloqueios[] = $this->bloqueio(
                    VocabularioDoPortao::RESULTADO_NEGATIVO,
                    'Resultado negativo: '.self::brl($composicao->resultado).' por venda'
                );
            }
        } elseif ($composicao->margem !== null && $composicao->margem < $composicao->margemMinima) {
            if (! in_array(PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO, $liberados, true)) {
                $bloqueios[] = $this->bloqueio(
                    VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO,
                    'Margem de '.self::pct($composicao->margem).' abaixo do mínimo de '
                        .self::percentual($composicao->margemMinima)
                );
            }
        }

        // (5) `change` — `:1095`. O aviso de preço alterado depois da última conferência.
        $alterado = $this->precoAlterado($oferta);
        if ($alterado !== null) {
            $bloqueios[] = $this->bloqueio(
                VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
                'Preço alterado após a última conferência: '.self::brl($alterado['de']).' → '.self::brl($alterado['para'])
            );
        }

        // (6) `adjust` — `:1096`.
        if ($ajustesAbertos > 0) {
            $bloqueios[] = $this->bloqueio(
                VocabularioDoPortao::AJUSTE_EM_ABERTO,
                $ajustesAbertos === 1
                    ? '1 ajuste solicitado em aberto'
                    : $ajustesAbertos.' ajustes solicitados em aberto'
            );
        }

        // (7) `title` — `:1097`. ⚠️ **ESTRITAMENTE maior**: 60 passa, 61 bloqueia.
        $titulo = (string) ($oferta->alvo?->titulo ?? '');
        $caracteres = mb_strlen($titulo);
        if ($caracteres > self::TITULO_MAXIMO) {
            $bloqueios[] = $this->bloqueio(
                VocabularioDoPortao::TITULO_LONGO,
                sprintf('Título com %d caracteres (máx. %d)', $caracteres, self::TITULO_MAXIMO)
            );
        }

        $chaves = array_map(fn (array $b): string => $b['chave'], $bloqueios);

        // `kinds` — `:1098`. ⚠️ As duas últimas **não são bloqueio**: não entram em `B` e não
        // impedem a aprovação. `alerta_em_foto_de_ia` e `falha_na_publicacao` existem para o
        // filtro "Motivo de bloqueio" (BLOQ-03).
        $kinds = $chaves;
        if ($comAlerta->isNotEmpty()) {
            $kinds[] = VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA;
        }
        if ($oferta->andamento === PubAprovacaoOferta::FALHA) {
            $kinds[] = VocabularioDoPortao::FALHA_NA_PUBLICACAO;
        }

        return [
            'bloqueios' => $bloqueios,
            'chaves' => array_values($chaves),
            'kinds' => array_values($kinds),
            // ⚠️ BLOQ-04 — `approvable = live && B.length === 0` (`:1099`), conferido NO SERVIDOR.
            'aprovavel' => $aoVivo && $bloqueios === [],
            'ao_vivo' => $aoVivo,
            'resumo' => $this->resumoDoRodape($bloqueios),
            'na_galeria' => $naGaleria->count(),
            'pendentes' => $pendentes->count(),
            'com_alerta' => $comAlerta->count(),
            // `fix()` (`:1171`) usa `inf.alerts[0] || inf.pend[0]`: a primeira COM ALERTA ou, não
            // havendo, a primeira pendente. O id é o da linha de REVISÃO (`pub_aprovacao_imagens`).
            'foco_imagem_id' => ($comAlerta->first() ?? $pendentes->first())?->id,
            'composicao' => $composicao,
        ];
    }

    /**
     * BLOQ-12 — a linha do rodapé: o literal verde sem bloqueio, ou o motivo com o **primeiro**
     * abreviado e `+N` para o resto (`:1238`).
     *
     * ⚠️ O `+N` conta os **demais**, não o total: com 2 bloqueios a linha é "… +1".
     *
     * @param  list<array{chave: string, texto: string, correcao: string, severidade: string, glifo: string}>  $bloqueios
     */
    public function resumoDoRodape(array $bloqueios): string
    {
        if ($bloqueios === []) {
            return VocabularioDoPortao::TUDO_CONFERIDO;
        }

        $primeiro = $bloqueios[0];
        $abreviacao = VocabularioDoPortao::abreviacaoDe($primeiro['chave']) ?? $primeiro['chave'];
        $resto = count($bloqueios) - 1;

        return VocabularioDoPortao::BLOQUEADO_PREFIXO.$abreviacao.($resto > 0 ? ' +'.$resto : '');
    }

    /**
     * **x de y fotos aprovadas** — a contagem da galeria final de TODOS os grupos do rascunho.
     *
     * ⚠️ Existe para que a frase de `AND-06` (*"Salvou revisão · x de y fotos aprovadas"*) e o
     * bloqueio `fotos_a_conferir` leiam **A MESMA** definição de "galeria final" e de "aprovada".
     * Duas contagens da mesma verdade divergiriam, e a divergência apareceria como a tela dizendo
     * "5 de 5 fotos aprovadas" **com um bloqueio de fotos de pé** — que é o pior tipo de bug de
     * dado, porque parece completo.
     *
     * @return array{total: int, aprovadas: int, pendentes: int, com_alerta: int}
     */
    public function contagemDeFotos(PubAprovacaoOferta $oferta): array
    {
        $galeria = $this->galeriaDoRascunho($oferta);

        return [
            'total' => $galeria['na_galeria']->count(),
            'aprovadas' => $galeria['na_galeria']->count() - $galeria['pendentes']->count(),
            'pendentes' => $galeria['pendentes']->count(),
            'com_alerta' => $galeria['com_alerta']->count(),
        ];
    }

    /**
     * A galeria final de **TODOS** os grupos do rascunho, e o que nela está pendente / com alerta.
     *
     * ⚠️ Três consultas FIXAS (conjuntos · imagens por `whereIn` · alertas por eager loading), e
     * nenhuma delas cresce com o número de grupos. Ver o docblock da classe.
     *
     * @return array{na_galeria: Collection<int, PubAprovacaoImagem>, pendentes: Collection<int, PubAprovacaoImagem>, com_alerta: Collection<int, PubAprovacaoImagem>}
     */
    private function galeriaDoRascunho(PubAprovacaoOferta $oferta): array
    {
        $conjuntos = PubAprovacaoConjunto::doRascunho((int) $oferta->rascunho_id)
            ->get(['id', 'grupo_chave']);

        if ($conjuntos->isEmpty()) {
            // ⚠️ Rascunho sem conjunto nenhum cai em `sem_fotos` (IMG-12), nunca em tela muda.
            return ['na_galeria' => new Collection, 'pendentes' => new Collection, 'com_alerta' => new Collection];
        }

        // ⚠️ A ORDEM DOS GRUPOS: `GENERAL` primeiro, depois por `id`. Ver o docblock da classe —
        // não há coluna `posicao` em `pub_aprovacao_conjuntos` (medido).
        $ordemDoGrupo = [];
        foreach ($conjuntos as $c) {
            $ordemDoGrupo[(int) $c->id] = [$c->grupo_chave === PubAprovacaoConjunto::GERAL ? 0 : 1, (int) $c->id];
        }

        $imagens = PubAprovacaoImagem::query()
            ->with('alertas')
            ->whereIn('conjunto_id', $conjuntos->pluck('id')->all())
            ->orderBy('conjunto_id')
            ->orderBy('posicao')
            ->orderBy('id')
            ->get();

        $naGaleria = $imagens
            ->filter(fn (PubAprovacaoImagem $i) => $i->noConjuntoFinal())
            ->sortBy(fn (PubAprovacaoImagem $i) => [
                $ordemDoGrupo[(int) $i->conjunto_id][0] ?? 2,
                $ordemDoGrupo[(int) $i->conjunto_id][1] ?? 0,
                $i->posicao ?? 0,
                (int) $i->id,
            ])
            ->values();

        $pendentes = $naGaleria
            ->filter(fn (PubAprovacaoImagem $i) => $i->estado !== PubAprovacaoImagem::APROVADA)
            ->values();

        // ⚠️ Os alertas já vieram com `with('alertas')`: `temAlerta()` faria uma consulta POR
        // IMAGEM (`->alertas()->where(...)->exists()`), e no lote isso é o N+1 clássico.
        $comAlerta = $pendentes
            ->filter(fn (PubAprovacaoImagem $i) => $i->alertas
                ->contains(fn (PubAprovacaoImagemAlerta $a) => $a->severidade !== PubAprovacaoImagemAlerta::OK))
            ->values();

        return ['na_galeria' => $naGaleria, 'pendentes' => $pendentes, 'com_alerta' => $comAlerta];
    }

    /**
     * O texto e a correção do bloqueio da cadeia quando falta dado.
     *
     * ⚠️ **DIVERGÊNCIA DECLARADA, e é de VERDADE, não de forma.** No protótipo só o **frete**
     * podia faltar (`fin()`, `:1067`), então o texto *"Frete do vendedor não confirmado"* e o
     * `fix` *"Informar frete"* sempre serviam. Aqui **cinco** linhas anulam o resultado
     * ({@see PubAprovacaoComposicao::ANULAM_O_RESULTADO}) — e dizer "frete" quando o que falta é o
     * **custo do produto** seria a tela afirmando o que não é, e mandando a pessoa ao lugar
     * errado. ➡️ **A FORMA do protótipo é preservada** (`"… não confirmado · resultado
     * indisponível"`); o que muda é **qual fonte é nomeada**. O rótulo do filtro já previa isso:
     * ele é "Frete **ou custo** ausente" ({@see VocabularioDoPortao::ROTULOS}).
     *
     * @return array{0: string, 1: string} texto, correção
     */
    private function freteOuCusto(ComposicaoDaOferta $composicao): array
    {
        $faltando = [];
        foreach ($composicao->linhasDaConta() as $linha) {
            if ($linha->ehPendente() && in_array($linha->chave, PubAprovacaoComposicao::ANULAM_O_RESULTADO, true)) {
                $faltando[$linha->chave] = $linha->rotulo;
            }
        }

        if (array_key_exists('frete', $faltando)) {
            // O literal do protótipo, byte a byte (`:1092`).
            return ['Frete do vendedor não confirmado · resultado indisponível', VocabularioDoPortao::CORRECOES[VocabularioDoPortao::FRETE_AUSENTE]];
        }

        if ($faltando === []) {
            // Ramo DEFENSIVO: a composição se diz incompleta e nenhuma das cinco está pendente.
            // Não deveria acontecer; se acontecer, a tela diz a verdade ("não se sabe") em vez de
            // mostrar um número.
            Log::warning(self::TAG.' composição incompleta sem linha pendente entre as cinco que anulam o resultado');

            return ['Composição de preço incompleta · resultado indisponível', VocabularioDoPortao::CORRECAO_SEM_FRETE];
        }

        return [
            implode(' · ', array_values($faltando)).' não confirmado · resultado indisponível',
            VocabularioDoPortao::CORRECAO_SEM_FRETE,
        ];
    }

    /**
     * O aviso de preço alterado, ou `null`.
     *
     * ⚠️ `preco_alterado` é `json` com `de`/`para`/`quem`/`quando`, e **o que manda é a presença
     * do aviso**: `preco_conferido_em` não entra na decisão, porque é
     * {@see AprovacaoDaOfertaService} quem limpa o aviso ao conferir (BLOQ-07). Conferir a
     * alteração **não altera o preço**.
     *
     * @return array{de: float, para: float}|null
     */
    private function precoAlterado(PubAprovacaoOferta $oferta): ?array
    {
        $aviso = $oferta->preco_alterado;

        if (! is_array($aviso) || ! isset($aviso['de'], $aviso['para'])) {
            return null;
        }

        return ['de' => (float) $aviso['de'], 'para' => (float) $aviso['para']];
    }

    private function textoDasFotos(int $pendentes, int $comAlerta): string
    {
        // `:1090` — a pluralização é a do protótipo, em pt-BR.
        $texto = $pendentes === 1
            ? '1 foto aguardando conferência'
            : $pendentes.' fotos aguardando conferência';

        return $comAlerta > 0 ? $texto.' · '.$comAlerta.' com alerta automático' : $texto;
    }

    /**
     * Monta uma linha de bloqueio: chave + texto + correção + severidade + **glifo** (VOC-05).
     *
     * @return array{chave: string, texto: string, correcao: string, severidade: string, glifo: string}
     */
    private function bloqueio(string $chave, string $texto, bool $comAlerta = false, ?string $correcao = null): array
    {
        return [
            'chave' => $chave,
            'texto' => $texto,
            'correcao' => $correcao ?? (VocabularioDoPortao::correcaoDe($chave) ?? ''),
            'severidade' => VocabularioDoPortao::severidade($chave, $comAlerta),
            // ⚠️ VOC-05 — ícone + texto, NUNCA só cor.
            'glifo' => VocabularioDoPortao::GLIFO,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A formatação. ⚠️ Mesma FORMA do protótipo (`:1044-1045`), medida por leitura:
    //   · `brl(-12.03)` = "− R$ 12,03" — o sinal é **U+2212 MINUS SIGN seguido de espaço**
    //     (bytes `E2 88 92 20`, conferidos por `od -c` no `.dc.html`), **não** o hífen-menos.
    //     Trocar por `-` passa desapercebido na tela e reprova o gate de literal — de propósito.
    //   · `pct(2.46)` = "2,5%" — SEMPRE uma casa decimal.
    // ⚠️ `percentual()` é a forma do MÍNIMO (0 ou 1 casa), igual à de {@see ComposicaoDaOferta}:
    //    no protótipo o mínimo entra como `this.MIN + '%'` (número cru, `:1094`), e com o mínimo
    //    resolvido de uma empresa (que pode ser 12,5) o número cru sairia "12.5%".
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private static function brl(float $n): string
    {
        return ($n < 0 ? "\u{2212} " : '').'R$ '.number_format(abs($n), 2, ',', '.');
    }

    private static function pct(float $n): string
    {
        return number_format(round($n * 10) / 10, 1, ',', '.').'%';
    }

    private static function percentual(float $n): string
    {
        $casas = abs($n - round($n)) < 0.0005 ? 0 : 1;

        return number_format($n, $casas, ',', '.').'%';
    }
}
