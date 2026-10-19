<?php

namespace App\Services\Publicador\Aprovacao\Painel;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\GaleriaDaOfertaService;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A COLUNA DE FOTOS DO PAINEL DE REVISÃO SIMPLES (E2) — **resolvida NO SERVIDOR**, Fase 188, 03.
 *
 * Progresso, a imagem selecionada com posição/principal/tipo/estado, **os marcadores numerados nas
 * coordenadas x/y reais**, a grade de miniaturas com a borda por estado, as verificações
 * automáticas como DADO e a contagem de "Aprovar N sem alerta".
 *
 * ═══ ⚠️⚠️ ESTE SERVIÇO SÓ LÊ. NÃO ESCREVE NADA ════════════════════════════════════════════════
 *
 * Nem estado de imagem, nem andamento, nem posição. A porta de escrita de foto é
 * {@see \App\Services\Publicador\Aprovacao\RevisaoDeImagemService} (Fase 186) e a escrita no
 * Mercado Livre é da **Fase 193**. O painel **APROVA** (e aprovar ENFILEIRA); ele não publica.
 *
 * ═══ ⚠️⚠️ O BLOQUEIO DE FOTOS COBRE **TODOS OS GRUPOS** — decisão do usuário, 14/10/2026 ═══════
 *
 * `ResolvedorGruposImagem::chaveDoGrupo()` dá um conjunto por valor de eixo que define foto **mais
 * o `GENERAL`** (3 cores = 4 grupos), e a oferta só é aprovável com **todas** as variações **e** as
 * gerais conferidas. **Aprovação cor por cor foi OFERECIDA e RECUSADA** (aprovar já publica e não
 * há desfazer: foto que ninguém olhou não pode ir ao ar).
 *
 * ➡️ **Por isso o progresso é LIDO de {@see BloqueiosDaOfertaService::contagemDeFotos()}, nunca
 * recontado aqui.** Duas contagens da mesma verdade divergiriam, e a divergência apareceria como a
 * tela dizendo *"5 de 5 aprovadas"* **com um bloqueio de fotos de pé** — o pior tipo de bug de
 * dado, porque **parece completo**. ⚠️ `com_alerta_restantes` e `sem_alerta.quantidade` saem da
 * **MESMA** leitura (`pendentes - com_alerta`), não de uma segunda varredura.
 *
 * ═══ ⚠️⚠️ B1.2 — A URL DA MINIATURA: A ROTA JÁ EXISTE, E **CARREGÁ-LA MARCA O EDITOR EM USO** ══
 *
 * `GaleriaDaOfertaService::doRascunho()` devolve `id`, `estado`, `de_ia`, `com_alerta`, `rotulo` e
 * `titulo` — e **nenhuma URL**; e `PubAprovacaoImagem` **não tem coluna de arquivo**. A saída não
 * exige território novo: o byte é servido pela rota que já está no ar,
 * **`mlb.anuncios.publicador.fotos.arquivo`**
 * (`routes/mlb_anuncios.php:158` o prefixo, `:178` a rota → `MlbPublicadorController::arquivoFoto`
 * em `:212`), com `{produto} = pub_produtos.id` (`PubAprovacaoOferta::$produto_id`) e
 * `{imagem} = pub_imagens.id` (`PubAprovacaoImagem::$imagem_id`).
 *
 * ⚠️ **REUSAR NÃO É EDITAR.** `App\Support\Publicador\Imagem\ArquivosDeImagem`, `Acervo*` e
 * `ImagemAssetService` continuam **intocados**: a v28 virou o acervo para o **R2 privado** em
 * produção (16/10/2026) e **o bucket não serve URL pública** — a rota serve **BYTES**, que é o que
 * o `<img src>` precisa. **Nenhuma coluna nova, nenhum caminho de arquivo montado à mão.**
 *
 * ⚠️ **`caminho` nulo = veio do Anunciar antigo** (`PubImagem:10`), e `arquivoFoto` faz
 * **`abort(404)`** nesse caso (`MlbPublicadorController:215`-`:217`). ➡️ Aqui a `url_arquivo` sai
 * **`null`** — ⚠️ **nunca string vazia**, porque `<img src="">` faz o navegador **recarregar a
 * própria página** — e a tela mostra o **estado declarado**, na disciplina do IMG-13: nunca painel
 * vazio, nunca ícone quebrado, nunca zero.
 *
 * ⚠️⚠️ **PERGUNTA-188-C — O EFEITO COLATERAL DO REUSO, ACEITO PELO USUÁRIO EM 19/10/2026.**
 *
 * O caminho da rota é `arquivoFoto` → `rascunho($produto)` → `produto($id)` →
 * **`EditorEmUso::marcar()`** (`MlbPublicadorController:405`), que marca o produto como *"com o
 * editor aberto"* por **3 minutos** (`publicador.preparo_ia.editor_em_uso_min`,
 * **`config/publicador.php:148`**).
 *
 * ➡️ **CONSEQUÊNCIA, por extenso: carregar a miniatura no painel SUSPENDE o agendamento e o preparo
 * por IA daquele produto enquanto alguém revisa.** Os **CINCO leitores** do sinal, um por um — e
 * eles estão nomeados aqui para que quem vier achar a causa **pelo `grep`, não por bisseção**:
 *
 *   1. **`AgendadorDaFila:337`** — `EditorEmUso::emUso()` ⇒ `['tipo' => 'esperar']`: o produto não
 *      é agendado nesta passada;
 *   2. **`PreparoIaDoRascunhoService:131`** — o preparo por IA do lote pula o produto;
 *   3. **`PreparoIaDoRascunhoService:180`** — idem, no segundo laço;
 *   4. **`PreparoIaDoRascunhoService:366`** — idem, na escrita do rascunho;
 *   5. **`GarantiaPadrao:192`** — a garantia padrão não é escrita por baixo de quem edita;
 *   6. **`ExcluirProdutoService:195`** — a exclusão do produto espera.
 *
 * ⚠️ **A RAZÃO DO USUÁRIO, e ela é o ponto: *é o que o portão quer*.** `AgendadorDaFila:336` diz
 * textualmente *"Gente com o editor aberto: não publica por baixo dela"* — e **revisar é trabalhar
 * no produto**. ⚠️ **"Servir a foto sem marcar" foi OFERECIDO e RECUSADO**, porque exigiria **rota
 * nova sobre `App\Support\Publicador`** — território de outra sessão — e **coordenação que o ganho
 * não paga**. ➡️ **Não há nada a consertar, e não se abre rota nova.**
 * ⚠️ Isto é **mudança de comportamento que ninguém pediu**: fica **declarada**, não escondida.
 *
 * ═══ ⚠️ x/y SÃO PERCENTUAL (0,00–100,00), NÃO PIXEL ═══════════════════════════════════════════
 *
 * É o que permite desenhar o marcador sobre a foto em **qualquer tamanho de tela**. ⚠️ A coluna é
 * `decimal(5,2)`, teto **999,99**: estourar dá **erro 1264 no MariaDB** e **grava truncado em
 * silêncio no SQLite dos testes** (a suíte não pega). ➡️ **Este serviço não grava nada**, mas
 * **saneia na leitura**: valor fora de 0–100 **não vira marcador fantasma** sobre a imagem.
 *
 * ═══ ⚠️⚠️ TRÊS REGRAS QUE É FÁCIL UNIFICAR POR ENGANO — E SÃO TRÊS ════════════════════════════
 *
 *   · **marcadores** em **DOIS** estados: `pending` **ou** `rejected` (`:1109`);
 *   · **badge `!`** em **UM** estado: `al.length && st === 'pending'` (`:1105`);
 *   · **borda** em **CINCO** ramos (`:1113`), nesta precedência: selecionada → alerta → aprovada →
 *     pendente → **neutra**.
 *
 * ⚠️ Foto **aprovada com alerta** não tem marcador **e** não tem badge; foto **reprovada** tem
 * marcador **e** não tem badge. **São regras diferentes de propósito. Não unifique.**
 *
 * ⚠️⚠️ **AS DUAS OMISSÕES DO REVS-09, DECLARADAS:** o requisito (`.planning/REQUIREMENTS-v27.md:596`)
 * lista **quatro** bordas e **não** lista (a) o **5º ramo** `#3F3F46` nem (b) o **anel**
 * `'0 0 0 2px #FACC15'`, que é um box-shadow **ADICIONAL** à borda (`:1113`). **Os dois são
 * reproduzidos** — `borda` tem cinco valores e `anel` é um booleano próprio.
 *
 * ═══ ⚠️ TOK-15 — NENHUM HEX SAI DO PHP ════════════════════════════════════════════════════════
 *
 * O `content` do Tailwind **não varre `.php`**, então uma classe cunhada aqui não geraria CSS. As
 * cores viajam como **CHAVE SEMÂNTICA** (`verde`/`ambar`/`vermelho`/`neutra`/`neutra_fraca`), do
 * mesmo jeito que {@see PainelDeRevisaoService::COR_VERDE}. O mapa chave → token é da tela (plano
 * 188-08), e os valores medidos estão na `188-FID01.md` §C (selo `:1104`, marcador `:1109`,
 * borda/anel `:1113`, item de verificação `:1108`).
 *
 * ═══ ⚠️⚠️ O QUE ESTE SERVIÇO **NÃO** ENTREGA ══════════════════════════════════════════════════
 *
 *   · o cabeçalho **`VERIFICAÇÃO AUTOMÁTICA · APOIO`** — ele é da **tela** (plano 188-08);
 *   · a frase montada `"Verificação automática2 · …"` de `:633` — ⚠️ aqui o ordinal é um **DADO
 *     separado** (`ordinal: 2`), **nunca** a string `"automática2"`;
 *   · os botões **"⤢ Ampliar"** (Fase 189) e **"✦ Gerar novamente com IA"** (Fase 192).
 *
 * ⚠️⚠️ **PERGUNTA-188-A, RESPONDIDA PELO USUÁRIO em 18/10/2026 — opção (c), HÍBRIDO:** no painel o
 * cabeçalho aparece **uma vez** **e** cada item mantém **o número que o liga ao marcador x/y**. A
 * razão dele: *o cabeçalho é o que diz **APOIO** — sem ele a verificação automática pode ser lida
 * como **VEREDICTO**, que é o que REVS-10 e a D7 querem impedir; e o ordinal é **funcional** (liga
 * o item ao marcador), não enfeite.* ➡️ É a **TERCEIRA derrota do protótipo** na milestone, e as
 * três foram conflito entre **duas regras do projeto** resolvido **pelo usuário**, nunca erro dele.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FotosDoPainelService
{
    // ─── As cores, como CHAVE SEMÂNTICA (TOK-15: nenhum hex sai do PHP) ─────────────────────

    public const COR_VERDE = 'verde';

    public const COR_AMBAR = 'ambar';

    public const COR_VERMELHA = 'vermelho';

    public const COR_NEUTRA = 'neutra';

    /** O cinza mais escuro do dot de `replaced`/`removed` (`:1104`), que não é o de `regenerating`. */
    public const COR_NEUTRA_FRACA = 'neutra_fraca';

    // ─── Os CINCO ramos da borda (`:1113`), nesta precedência ───────────────────────────────

    public const BORDA_SELECIONADA = 'selecionada';

    public const BORDA_ALERTA = 'alerta';

    public const BORDA_APROVADA = 'aprovada';

    public const BORDA_PENDENTE = 'pendente';

    /** ⚠️ O 5º ramo, que o REVS-09 **OMITE** (`#3F3F46` no protótipo). */
    public const BORDA_NEUTRA = 'neutra';

    /**
     * ⚠️ O 6º estado do selo do protótipo (`:1104`), que **o nosso schema não tem**.
     *
     * `PubAprovacaoImagem` tem CINCO estados; `removed` é só do protótipo. Ele é mapeado aqui por
     * **fidelidade** ao `:1104` — e **nunca chega** por `doRascunho()`, exatamente como o
     * `regenerating` de `GaleriaDaOfertaService` (divergência D7, já declarada e no ar).
     */
    public const ESTADO_REMOVIDA = 'removed';

    /**
     * Os **6 estados do selo** (`:1104`): `estado => [rótulo, cor do texto, cor do dot]`.
     *
     * ⚠️ As cores viajam como chave semântica (TOK-15). O mapeamento medido, para a tela:
     * `approved ['Aprovada', #4ADE80, #22C55E]` · `pending ['A conferir', #FBBF24, #F59E0B]` ·
     * `rejected ['Reprovada', #F87171, #EF4444]` · `regenerating ['Regenerando', #A1A1AA, #71717A]`
     * · `replaced ['Substituída', #A1A1AA, #52525B]` · `removed ['Removida', #A1A1AA, #52525B]`.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const SELOS = [
        PubAprovacaoImagem::APROVADA => ['Aprovada', self::COR_VERDE, self::COR_VERDE],
        PubAprovacaoImagem::PENDENTE => ['A conferir', self::COR_AMBAR, self::COR_AMBAR],
        PubAprovacaoImagem::REPROVADA => ['Reprovada', self::COR_VERMELHA, self::COR_VERMELHA],
        PubAprovacaoImagem::REGENERANDO => ['Regenerando', self::COR_NEUTRA, self::COR_NEUTRA],
        PubAprovacaoImagem::SUBSTITUIDA => ['Substituída', self::COR_NEUTRA, self::COR_NEUTRA_FRACA],
        self::ESTADO_REMOVIDA => ['Removida', self::COR_NEUTRA, self::COR_NEUTRA_FRACA],
    ];

    /**
     * ⚠️⚠️ O NEUTRO de chave desconhecida — e o **rótulo NUNCA é vazio**.
     *
     * No protótipo `C[r.st]` com chave desconhecida é `undefined` e a linha **EXPLODE** (`:1104`).
     * Aqui ela cai aqui. ⚠️ **Guarda que devolvesse `null`/`''` produziria elemento VAZIO, que
     * passa em render e DESAPARECE na tela** — foi exatamente o conserto do anel de progresso na
     * 187-08. E o texto **não mente**: não diz "A conferir" sobre um estado que ninguém mapeou.
     */
    public const SELO_NEUTRO = ['Estado não reconhecido', self::COR_NEUTRA, self::COR_NEUTRA_FRACA];

    // ─── Os glifos do item de verificação (`:1108`) ─────────────────────────────────────────

    public const GLIFO_OK = '✓';

    public const GLIFO_ALERTA = '!';

    // ─── Os literais de texto, um lugar só ─────────────────────────────────────────────────

    /** `photoProg` (`:1303`) — `ap + ' de ' + inf.ord.length + ' aprovadas'`. */
    public const PROGRESSO_MEIO = ' de ';

    public const PROGRESSO_FIM = ' aprovadas';

    /** `sharedNote` (`:1304`), byte a byte. */
    public const NOTA_PREFIXO = 'Mesmas fotos de ';

    public const NOTA_SUFIXO = ' · a aprovação vale para as duas ofertas';

    /** `safeLabel` (`:1305`), byte a byte. */
    public const SEM_ALERTA_PREFIXO = 'Aprovar ';

    public const SEM_ALERTA_SUFIXO = ' sem alerta';

    /** `kindLabel` (`:1106`) — C9 e C10 da `188-FID01.md` §C. */
    public const TIPO_IA_ROTULO = 'Gerada por IA';

    public const TIPO_ORIGINAL_ROTULO = 'Original';

    /** `posLabel` (`:1106`) — o selo `#n` de C7. */
    public const POSICAO_PREFIXO = '#';

    /** A faixa válida do percentual de `x`/`y` — ver o docblock da classe. */
    public const PONTO_MINIMO = 0.0;

    public const PONTO_MAXIMO = 100.0;

    public function __construct(
        private BloqueiosDaOfertaService $bloqueios,
        private GaleriaDaOfertaService $galeria,
    ) {}

    /**
     * A coluna de fotos desta oferta.
     *
     * ⚠️ `$selecionado` é o índice **na galeria final**, e ele é **clampado nas DUAS pontas**.
     * ⚠️⚠️ **DIVERGÊNCIA DECLARADA do `:1293`**, e ela é de VERDADE: o protótipo faz
     * `imgs[Math.min(selIdx, imgs.length - 1)] || null` — clampa só o **teto**, então índice
     * **negativo** devolve `null`. Aqui `null` é **reservado** para *"galeria vazia"*, que é um
     * estado que a tela DECLARA; usá-lo também para um índice inválido faria a coluna dizer o vazio
     * **com fotos na grade** — a tela afirmando o que não é.
     *
     * @param  int  $selecionado  o índice (0-based) da miniatura selecionada
     * @return array{
     *     progresso: string,
     *     progresso_cor: string,
     *     total: int,
     *     aprovadas: int,
     *     pendentes: int,
     *     com_alerta: int,
     *     com_alerta_restantes: int,
     *     nota_de_conjunto: ?string,
     *     imagens: list<array<string, mixed>>,
     *     selecionada: ?array<string, mixed>,
     *     selecionada_indice: ?int,
     *     sem_alerta: array{quantidade: int, rotulo: string}|null
     * }
     */
    public function para(PubAprovacaoOferta $oferta, int $selecionado = 0): array
    {
        // ⚠️⚠️ **CHAMADO, nunca recontado** — a definição de "galeria final", de "aprovada" e de
        // "com alerta" tem UMA casa, e ela cobre TODOS os grupos do rascunho.
        $contagem = $this->bloqueios->contagemDeFotos($oferta);

        $total = (int) ($contagem['total'] ?? 0);
        $aprovadas = (int) ($contagem['aprovadas'] ?? 0);
        $pendentes = (int) ($contagem['pendentes'] ?? 0);
        $comAlerta = (int) ($contagem['com_alerta'] ?? 0);

        // ⚠️ A lista ORDENADA vem da galeria da 187 — **recebe o id do RASCUNHO**, de propósito: o
        // conjunto é do rascunho e as duas ofertas leem o MESMO, sem coluna onde divergir.
        $galeria = $this->galeria->doRascunho((int) $oferta->rascunho_id);

        $revisoes = $this->revisoesDaGaleria($galeria['imagens']);

        $indice = $this->indiceClampado($selecionado, count($galeria['imagens']));

        $imagens = [];
        foreach ($galeria['imagens'] as $posicao => $daGaleria) {
            // ⚠️ Flag DENTRO do laço.
            $revisao = $revisoes->get((int) $daGaleria['id']);

            $imagens[] = $this->imagem($oferta, $daGaleria, $revisao, $posicao, $posicao === $indice);
        }

        // ⚠️ **UMA fonte** para as duas: a contagem acima. Sem isto, "Aprovar 4" e "2 continuam
        // para conferência" poderiam somar diferente do total de pendentes na mesma tela.
        $semAlerta = max(0, $pendentes - $comAlerta);

        return [
            'progresso' => $aprovadas.self::PROGRESSO_MEIO.$total.self::PROGRESSO_FIM,
            'progresso_cor' => $this->corDoProgresso($pendentes, $comAlerta),
            'total' => $total,
            'aprovadas' => $aprovadas,
            'pendentes' => $pendentes,
            'com_alerta' => $comAlerta,
            // ⚠️ Decisão 8 do usuário: *"a tela diz quem ficou de fora e por quê"* — aprovar
            // silenciosamente só as que passam foi RECUSADO. Isto não é decoração: é o que impede
            // o entendimento errado de que "Aprovar N sem alerta" **fecha** a coluna.
            'com_alerta_restantes' => $comAlerta,
            'nota_de_conjunto' => $this->notaDeConjunto($oferta),
            'imagens' => $imagens,
            'selecionada' => $indice === null ? null : ($imagens[$indice] ?? null),
            'selecionada_indice' => $indice,
            'sem_alerta' => $this->semAlerta($oferta, $semAlerta),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS REGRAS PÚBLICAS E PURAS — os estados que a galeria final NUNCA emite
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O selo de estado: `[rótulo, cor do texto, cor do dot]` (`:1104`).
     *
     * ⚠️⚠️ **PÚBLICO DE PROPÓSITO, e a razão é MEDIDA, não estilo.** `noConjuntoFinal()` admite só
     * `pending` ∪ `approved`, então `rejected`, `regenerating`, `replaced` e `removed` **nunca
     * chegam** pela galeria final — e sem um ponto de entrada público as regras deles **não
     * poderiam ser medidas por teste nenhum**, seriam fidelidade afirmada sem prova. É exatamente
     * a razão pela qual `GaleriaDaOfertaService::tituloDoQuadrado()` é público.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function selo(?string $estado): array
    {
        if (! is_string($estado) || ! array_key_exists($estado, self::SELOS)) {
            return self::SELO_NEUTRO;
        }

        return self::SELOS[$estado];
    }

    /**
     * A borda da miniatura — os **CINCO** ramos de `:1113`, nesta precedência.
     *
     * ⚠️ `selecionada` ganha **tanto** a borda **quanto** o anel; as duas são decisões separadas.
     */
    public function borda(?string $estado, bool $selecionada, bool $comAlerta): string
    {
        if ($selecionada) {
            return self::BORDA_SELECIONADA;
        }

        if ($comAlerta) {
            return self::BORDA_ALERTA;
        }

        if ($estado === PubAprovacaoImagem::APROVADA) {
            return self::BORDA_APROVADA;
        }

        if ($estado === PubAprovacaoImagem::PENDENTE) {
            return self::BORDA_PENDENTE;
        }

        // ⚠️ O 5º ramo, que o REVS-09 OMITE.
        return self::BORDA_NEUTRA;
    }

    /**
     * Os **alertas** da imagem (severidade acima de `ok`), **na ordem em que vieram** — a lista
     * `al` de `:1103`.
     *
     * ⚠️⚠️ **ESTA É A ÚNICA NUMERAÇÃO.** O ordinal do item de texto (`:1108`,
     * `String(al.indexOf(a) + 1)`) e o número do marcador (`:1109`, `n + 1`) saem **do MESMO
     * array**. Duas numerações da mesma verdade divergiriam, e a divergência apareceria como o
     * texto dizendo *"ponto 2"* com o marcador 2 em **outro defeito** — o pior erro possível aqui,
     * porque **a pessoa reprova a foto errada**.
     *
     * @param  iterable<PubAprovacaoImagemAlerta>  $alertas
     * @return list<PubAprovacaoImagemAlerta>
     */
    public function alertasOrdenados(iterable $alertas): array
    {
        $lista = [];
        foreach ($alertas as $alerta) {
            // ⚠️ Flag DENTRO do laço.
            if ($alerta->ehAlerta()) {
                $lista[] = $alerta;
            }
        }

        return $lista;
    }

    /**
     * Os marcadores x/y — **só** em `pending` e `rejected` (`:1109`), numerados 1..N.
     *
     * ⚠️ Alerta `ok` **não** gera marcador (ele não tem ponto: é veredicto sobre a foto inteira).
     * ⚠️ Alerta com `x`/`y` **nulos** não gera marcador, **e isso não derruba a lista** — e o
     * ordinal dos demais **não é renumerado**, porque ele é o número que o ITEM DE TEXTO mostra.
     * ⚠️ Valor fora de **0–100** é descartado: percentual impossível viraria marcador fantasma
     * fora da imagem (o teto `decimal(5,2)` é 999,99, e o SQLite dos testes trunca em silêncio).
     *
     * @param  iterable<PubAprovacaoImagemAlerta>  $alertas
     * @return list<array{ordinal: int, x: float, y: float, cor: string}>
     */
    public function marcadores(?string $estado, iterable $alertas): array
    {
        if (! in_array($estado, [PubAprovacaoImagem::PENDENTE, PubAprovacaoImagem::REPROVADA], true)) {
            return [];
        }

        $marcadores = [];
        foreach ($this->alertasOrdenados($alertas) as $ordem => $alerta) {
            // ⚠️ Flag DENTRO do laço.
            if (! $alerta->temPonto() || ! $this->noQuadro($alerta->x) || ! $this->noQuadro($alerta->y)) {
                continue;
            }

            $marcadores[] = [
                'ordinal' => $ordem + 1,
                'x' => (float) $alerta->x,
                'y' => (float) $alerta->y,
                'cor' => $alerta->severidade === PubAprovacaoImagemAlerta::ALTO ? self::COR_VERMELHA : self::COR_AMBAR,
            ];
        }

        return $marcadores;
    }

    /**
     * As verificações automáticas como **DADO** (`:1108`) — com o **ordinal do marcador**.
     *
     * ⚠️⚠️ **O `ordinal` do `ok` é `null`, e isso é DIVERGÊNCIA DECLARADA do protótipo**, que usa
     * `''` (`:1108`: `n: a.sev === 'ok' ? '' : …`). String vazia num campo que é número é mentira
     * de tipo; e **`0` seria pior**, porque `0` é um número de marcador **plausível** para quem lê.
     * ⚠️ A numeração é `1..N` **sobre os alertas**, nunca sobre a lista inteira (que inclui os `ok`).
     *
     * @param  iterable<PubAprovacaoImagemAlerta>  $alertas
     * @return list<array{severidade: string, texto: string, glifo: string, cor: string, ordinal: ?int}>
     */
    public function verificacoes(iterable $alertas): array
    {
        // ⚠️⚠️ **A MESMA LISTA que `marcadores()` numera** — e o ordinal é a POSIÇÃO NELA, achada
        // por identidade (`array_search` estrito). Não há segundo contador: um `++` paralelo aqui
        // seria uma segunda implementação da mesma numeração, livre para divergir.
        $somenteAlertas = $this->alertasOrdenados($alertas);

        $lista = [];
        foreach ($alertas as $alerta) {
            // ⚠️ Flag DENTRO do laço.
            $posicao = array_search($alerta, $somenteAlertas, true);

            $lista[] = [
                'severidade' => (string) $alerta->severidade,
                // ⚠️ pt-BR **pronto para a tela**, de `pub_aprovacao_imagem_alertas.texto`. O
                // serviço não monta frase: o cabeçalho e o prefixo são da tela (plano 188-08).
                'texto' => (string) $alerta->texto,
                'glifo' => $alerta->ehAlerta() ? self::GLIFO_ALERTA : self::GLIFO_OK,
                'cor' => $this->corDaSeveridade((string) $alerta->severidade),
                // ⚠️ `ok` não está na lista de alertas ⇒ `null`, **nunca `0`** nem `''`.
                'ordinal' => $posicao === false ? null : $posicao + 1,
            ];
        }

        return $lista;
    }

    /**
     * O percentual está dentro do quadro da imagem?
     *
     * ⚠️ `decimal(5,2)` aceita até **999,99** — dez vezes a faixa esperada. O MariaDB recusa acima
     * disso com **1264**; o SQLite dos testes **grava truncado em silêncio**.
     */
    public function noQuadro(mixed $valor): bool
    {
        if (! is_numeric($valor)) {
            return false;
        }

        return (float) $valor >= self::PONTO_MINIMO && (float) $valor <= self::PONTO_MAXIMO;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O PRIVADO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A entrada de UMA imagem da galeria final.
     *
     * ⚠️⚠️ **DUAS DIVERGÊNCIAS ESTRUTURAIS DECLARADAS, e as duas vêm de o protótipo ter UM conjunto
     * (`o.set`) e o rascunho ter UM POR GRUPO:**
     *   · **`posicao`** é o índice **global** na galeria final (o `#n` de `:1106`), enquanto
     *     `rotulo` vem da galeria e deriva do `posicao` **do banco**, que `reposicionar()` renumera
     *     **por conjunto** (`RevisaoDeImagemService:609`-`:611`). Com mais de um grupo o selo `#4`
     *     pode acompanhar o rótulo `Capa` — **e está certo**: são duas coisas diferentes.
     *   · **`principal`** é `ehPrincipal()` (`posicao === 0 && ! fora`, IMG-07), que é a capa **do
     *     grupo**. Com 4 grupos há **4** principais; o protótipo tem `D.main[set]`, uma só. A capa
     *     mora em `pub_aprovacao_conjuntos.principal_imagem_id` — **por conjunto**, por desenho.
     *
     * ⚠️ `grupo`/`grupo_geral` viajam como **DADO CRU**: não existe coluna com a DESCRIÇÃO humana
     * da variação (medido no schema), e inventá-la seria a tela afirmando o que não sabe — a mesma
     * decisão que `GaleriaDaOfertaService::ROTULO_CAPA` já declarou.
     *
     * @param  array{id: int, estado: string, de_ia: bool, com_alerta: bool, rotulo: string, titulo: string}  $daGaleria
     * @return array<string, mixed>
     */
    private function imagem(
        PubAprovacaoOferta $oferta,
        array $daGaleria,
        ?PubAprovacaoImagem $revisao,
        int $posicao,
        bool $selecionada,
    ): array {
        $estado = (string) $daGaleria['estado'];
        $comAlerta = ($daGaleria['com_alerta'] ?? false) === true;
        $alertas = $revisao === null ? [] : $revisao->alertas->all();

        [$rotulo, $cor, $dot] = $this->selo($estado);

        return [
            'id' => (int) $daGaleria['id'],
            'imagem_id' => $revisao === null ? null : (int) $revisao->imagem_id,
            'posicao' => $posicao + 1,
            'posicao_rotulo' => self::POSICAO_PREFIXO.($posicao + 1),
            'rotulo' => (string) $daGaleria['rotulo'],
            'titulo' => (string) $daGaleria['titulo'],
            'de_ia' => ($daGaleria['de_ia'] ?? false) === true,
            'tipo_rotulo' => ($daGaleria['de_ia'] ?? false) === true ? self::TIPO_IA_ROTULO : self::TIPO_ORIGINAL_ROTULO,
            'principal' => $revisao !== null && $revisao->ehPrincipal(),
            'grupo' => $revisao?->conjunto?->grupo_chave === null ? null : (string) $revisao->conjunto->grupo_chave,
            'grupo_geral' => $revisao?->conjunto?->ehGeral() === true,
            'estado' => $estado,
            'estado_rotulo' => $rotulo,
            'estado_cor' => $cor,
            'estado_dot' => $dot,
            // ⚠️ O badge `!` da miniatura: `al.length && st === 'pending'` (`:1105`) — UM estado.
            // Ele é LIDO da galeria, que já o resolve pela definição de `comAlerta()` do modelo.
            'com_alerta' => $comAlerta,
            'aprovada' => $estado === PubAprovacaoImagem::APROVADA,
            'aceita_acao' => $revisao === null || $revisao->aceitaAcao(),
            'borda' => $this->borda($estado, $selecionada, $comAlerta),
            // ⚠️ O anel é box-shadow **ADICIONAL** à borda (`:1113`) — a 2ª omissão do REVS-09.
            'anel' => $selecionada,
            'url_arquivo' => $this->urlDoArquivo($oferta, $revisao),
            'marcadores' => $this->marcadores($estado, $alertas),
            'verificacoes' => $this->verificacoes($alertas),
        ];
    }

    /**
     * A URL que serve os **bytes** da foto — ou `null` quando não há arquivo.
     *
     * ⚠️⚠️ **CARREGÁ-LA MARCA O PRODUTO COMO "EDITOR EM USO" POR 3 MINUTOS.** A cadeia é
     * `arquivoFoto` → `rascunho()` → `produto()` → `EditorEmUso::marcar()`
     * (`MlbPublicadorController:405`; o tempo é `config/publicador.php:148`), e o sinal é lido por
     * **`AgendadorDaFila:337`**, **`PreparoIaDoRascunhoService:131`**, **`:180`**, **`:366`**,
     * **`GarantiaPadrao:192`** e **`ExcluirProdutoService:195`**. ➡️ **Enquanto alguém revisa, o
     * agendamento e o preparo por IA daquele produto esperam.** Aceito pelo usuário em 19/10/2026
     * (PERGUNTA-188-C): *é o que o portão quer* — `AgendadorDaFila:336` diz *"Gente com o editor
     * aberto: não publica por baixo dela"*, e revisar é trabalhar no produto. **"Servir sem marcar"
     * foi oferecido e RECUSADO** (exigiria rota nova em `App\Support\Publicador`).
     *
     * ⚠️ **`null`, nunca `''`:** `<img src="">` faz o navegador recarregar a própria página, e o
     * `caminho` nulo é real (`PubImagem:10`, `arquivoFoto:215`-`:217` → `abort(404)`).
     * ⚠️ **A MESMA URL** serve a imagem grande, a miniatura e a do modal de reprovar — uma fonte só.
     */
    private function urlDoArquivo(PubAprovacaoOferta $oferta, ?PubAprovacaoImagem $revisao): ?string
    {
        $produtoId = $oferta->produto_id === null ? null : (int) $oferta->produto_id;
        $imagemId = $revisao?->imagem_id === null ? null : (int) $revisao->imagem_id;

        if ($produtoId === null || $imagemId === null) {
            return null;
        }

        // ⚠️ `caminho` nulo = veio do Anunciar antigo: a rota daria 404, então a tela mostra o
        // ESTADO declarado em vez de um ícone quebrado (disciplina do IMG-13).
        if ($revisao?->imagem?->caminho === null) {
            return null;
        }

        return route('mlb.anuncios.publicador.fotos.arquivo', [
            'produto' => $produtoId,
            'imagem' => $imagemId,
        ]);
    }

    /**
     * As linhas de revisão da galeria, com alertas, conjunto e imagem crua **já carregados**.
     *
     * ⚠️ `temAlerta()` e `$revisao->imagem` lidos um por um fariam **uma consulta POR IMAGEM** — o
     * N+1 clássico, e no painel de um rascunho com 4 grupos isso é a coluna inteira. O custo aqui é
     * **FIXO** e não cresce com o número de imagens nem de grupos.
     *
     * @param  list<array{id: int}>  $daGaleria
     * @return Collection<int, PubAprovacaoImagem>
     */
    private function revisoesDaGaleria(array $daGaleria): Collection
    {
        $ids = array_map(static fn (array $i): int => (int) $i['id'], $daGaleria);

        if ($ids === []) {
            return new Collection;
        }

        return PubAprovacaoImagem::query()
            ->with(['alertas', 'conjunto', 'imagem'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (PubAprovacaoImagem $i): int => (int) $i->id);
    }

    /**
     * A cor do progresso (`:1303`): verde sem pendente, **vermelho se há pendente COM alerta**,
     * âmbar nas outras pendentes.
     */
    private function corDoProgresso(int $pendentes, int $comAlerta): string
    {
        if ($pendentes === 0) {
            return self::COR_VERDE;
        }

        return $comAlerta > 0 ? self::COR_VERMELHA : self::COR_AMBAR;
    }

    /** A cor do item de verificação (`:1108`) — **três** valores distintos. */
    private function corDaSeveridade(string $severidade): string
    {
        return match ($severidade) {
            PubAprovacaoImagemAlerta::OK => self::COR_VERDE,
            PubAprovacaoImagemAlerta::ALTO => self::COR_VERMELHA,
            default => self::COR_AMBAR,
        };
    }

    /**
     * O índice da selecionada, clampado nas DUAS pontas — `null` só com a galeria **vazia**.
     *
     * ⚠️ Galeria vazia é o caso `sem_fotos` (IMG-12): a coluna **diz o vazio**, nunca desenha um
     * bloco com buracos.
     */
    private function indiceClampado(int $pedido, int $quantas): ?int
    {
        if ($quantas <= 0) {
            return null;
        }

        return max(0, min($pedido, $quantas - 1));
    }

    /**
     * A nota de conjunto compartilhado (`:1304`) — **DADO-04**.
     *
     * ⚠️ Ela existe **só** quando o conjunto é compartilhado por mais de uma oferta, e **nomeia a
     * irmã**. O compartilhamento é **ESTRUTURAL**: as ofertas se ligam ao conjunto por
     * `rascunho_id` nos dois lados, e **não existe coluna onde elas pudessem divergir**
     * (`PubAprovacaoConjunto`, Fase 186). ➡️ Aprovar uma foto no Clássico **vale** para o Premium.
     */
    private function notaDeConjunto(PubAprovacaoOferta $oferta): ?string
    {
        $irma = PubAprovacaoOferta::query()
            ->with('alvo')
            ->where('rascunho_id', $oferta->rascunho_id)
            ->whereKeyNot($oferta->getKey())
            ->first();

        if ($irma === null) {
            return null;
        }

        $rotulo = $this->modalidade($irma);

        // ⚠️ Sem o rótulo da modalidade a frase diria "Mesmas fotos de  · a aprovação…" — frase
        // com buraco é pior que frase ausente.
        return $rotulo === null ? null : self::NOTA_PREFIXO.$rotulo.self::NOTA_SUFIXO;
    }

    /**
     * "Clássico"/"Premium" — **LIDOS** de `EstruturaAnuncio::TIPOS` pela via de
     * `EstruturaPublicacao::LISTING_TYPES`.
     *
     * ⚠️ Escrever `'gold_pro'` à mão aqui seria a **terceira** cópia daquele literal (a segunda é
     * {@see PainelDeRevisaoService::modalidade()}, que faz a mesma travessia e já declarou isso).
     */
    private function modalidade(PubAprovacaoOferta $oferta): ?string
    {
        $listingType = $oferta->alvo?->listing_type_id;

        if (! is_string($listingType) || $listingType === '') {
            return null;
        }

        $tipo = array_search($listingType, EstruturaPublicacao::LISTING_TYPES, true);

        return $tipo === false ? null : (EstruturaAnuncio::TIPOS[$tipo] ?? null);
    }

    /**
     * "Aprovar N sem alerta" — a **contagem**, nunca a ação (IMG-05, critério 5).
     *
     * ⚠️⚠️ **A AÇÃO É DO PLANO 188-05**, e é
     * {@see \App\Services\Publicador\Aprovacao\RevisaoDeImagemService::aprovarSemAlerta()}, que já
     * existe e devolve N. ➡️ **A contagem daqui TEM de ser igual ao que a ação vai aprovar.** Se
     * divergirem, a pessoa clica *"Aprovar 4"* e 2 são aprovadas — o sintoma que o usuário recusou
     * duas vezes (*"conta e lista TÊM de bater"*). Há teste exigindo a igualdade na MESMA fixture,
     * com isca nas duas direções.
     *
     * ⚠️⚠️ **AVISO AO PLANO 188-05, MEDIDO:** `aprovarSemAlerta()` recebe **UM conjunto**, e esta
     * contagem cobre **TODOS os grupos do rascunho** (decisão do usuário, 14/10/2026). ➡️ A ação
     * tem de **percorrer todos os conjuntos** de `PubAprovacaoConjunto::doRascunho()`; chamá-la
     * para um conjunto só aprovaria menos do que o botão promete.
     *
     * ⚠️ **A FRONTEIRA das duas definições, declarada:** esta contagem sai da **galeria final**
     * (`noConjuntoFinal()`: estado vivo **E** `posicao` não nula **E** `fora` falso), e
     * `aprovarSemAlerta()` filtra só por `estado = pending`. Hoje as duas coincidem **por
     * construção dos escritores**: os três lugares que gravam `fora = true` gravam, no MESMO
     * `update()`, um estado que **não** é `pending` (`RevisaoDeImagemService:217`, `:303`, `:512`).
     *
     * ⚠️ `null` quando **N = 0** ou **fora do `live`** (`hasSafe`, `:1305`): **botão sem destino é
     * D4 violado**, e o usuário já exerceu essa régua três vezes.
     *
     * @return array{quantidade: int, rotulo: string}|null
     */
    private function semAlerta(PubAprovacaoOferta $oferta, int $quantidade): ?array
    {
        if ($quantidade <= 0 || ! $oferta->ehLive()) {
            return null;
        }

        return [
            'quantidade' => $quantidade,
            'rotulo' => self::SEM_ALERTA_PREFIXO.$quantidade.self::SEM_ALERTA_SUFIXO,
        ];
    }
}
