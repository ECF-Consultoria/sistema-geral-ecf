<?php

namespace App\Services\Publicador\Aprovacao\Painel;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\GrupoDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use Illuminate\Database\Eloquent\Builder;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O VIEW-MODEL DO PAINEL DE REVISÃO SIMPLES (E2) — **montado NO SERVIDOR**, Fase 188, plano 02.
 *
 * ═══ ⚠️⚠️ D9 — "SERVIDOR ANTES DO RENDER", E AQUI ISSO É SEGURANÇA ══════════════════════════════
 *
 * Quem decide se uma oferta pode ser aprovada é {@see BloqueiosDaOfertaService::para()}, com a
 * composição financeira e a **tarifa real do Mercado Livre**. ➡️ **A tela LÊ o veredicto; ela não o
 * calcula.** Calcular no cliente daria duas verdades, e a divergente seria descoberta por alguém
 * clicando "Aprovar para publicação" e sendo recusado — num fluxo em que **aprovar JÁ PUBLICA e não
 * há desfazer** (decisão do usuário, 14/10/2026).
 *
 * ═══ ⚠️⚠️ ESTE SERVIÇO **SÓ LÊ**. NÃO ESCREVE NADA ═════════════════════════════════════════════
 *
 * Nem `andamento`, nem `versao`, nem composição congelada. A **ÚNICA PORTA** de escrita do
 * andamento é {@see AndamentoDaOfertaService::mover()} — este arquivo **expõe** o andamento (no
 * chip de identificação e no motivo do rodapé fora do `live`) e **nada mais**.
 *
 * ⚠️ Por isso ele entra na lista de **EXPOSITORES** da camada B do gate `andamento-um-lugar-so`
 * (`tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php`), e a entrada é **PROVADA, não
 * afirmada**: aquele caso exige que o expositor (a) **case a sonda larga** — senão a exceção é linha
 * morta — e (b) **não contenha NENHUMA forma de escrita**. `tests/gates/contagens-186-09.txt` foi
 * **REMEDIDO** por causa disso, nos ids `andamento-um-lugar-so` e `transversais`.
 * ⚠️ **A chave de saída NÃO foi renomeada para fugir do gate** (`'estado_interno' =>` etc.): a régua
 * do projeto é **um vocabulário só**, com chaves pt-BR iguais nos dois lados, e o `.jsx` lê
 * `andamento.chave`. Dobrar a tela para o gate seria o conserto errado.
 *
 * ═══ ⚠️⚠️ CHAMAR, NUNCA REIMPLEMENTAR (as Fases 186 e 187 estão EM PRODUÇÃO) ════════════════════
 *
 * - o veredicto, os bloqueios, as fotos e a composição: {@see BloqueiosDaOfertaService::para()};
 * - o resumo do rodapé: {@see BloqueiosDaOfertaService::resumoDoRodape()} — **BLOQ-12**;
 * - o rótulo da espera: {@see ProximoARevisarService::rotuloDaEspera()} — e o prefixo sai de
 *   {@see ProximoARevisarService::PREFIXO_ESPERA};
 * - a ordem da fila: {@see AndamentoDaOfertaService::ordenarFila()}, a **ÚNICA PORTA** da ordenação;
 * - os rótulos de andamento: {@see PubAprovacaoOferta::ANDAMENTOS}, lidos do modelo;
 * - as palavras "Unidade" e "Combo ×": {@see GrupoDaFilaService::UNIDADE} /
 *   {@see GrupoDaFilaService::PREFIXO_COMBO}.
 *
 * ═══ ⚠️⚠️ A DIVERGÊNCIA DO MOTIVO DO RODAPÉ — DECLARADA, e o PRODUTO GANHA ══════════════════════
 *
 * | fonte | o que produz |
 * |---|---|
 * | protótipo `Aprovacao.dc.html:1297` | `'Bloqueado: ' + inf.B.map(b => SHORT[b.k]).join(' · ')` — **TODAS** as abreviações |
 * | produto (186, em produção) | `resumoDoRodape()` — o **PRIMEIRO** abreviado + `' +N'` dos DEMAIS |
 *
 * ➡️ O **critério 10 do ROADMAP** diz textualmente *"com bloqueio, o motivo em vermelho com o
 * **primeiro abreviado**"*, e `resumoDoRodape()` cita BLOQ-12. **Recopiar o `join` criaria a SEGUNDA
 * VERDADE da mesma frase na mesma tela** (o rodapé da fila e o do painel divergiriam), e uma frase
 * fora do vocabulário com gate. ➡️ **O método é CHAMADO; a frase não é remontada.**
 * ⚠️ O ponteiro `:1307` que circulava na prosa herdada está **DESLOCADO 10 linhas**: o `reason` mora
 * em **`:1297`**, e `:1307` é o `canApprove`/`approveBg`/`approveCursor` (o E12 do critério 8). Os
 * ponteiros deste arquivo foram **remedidos por `sed`** em 19/10/2026 — ver a SUMMARY do 188-02.
 *
 * ═══ ⚠️⚠️ OS NOVE DESTINOS DE CORREÇÃO, E POR QUE DOIS SÃO `null` ══════════════════════════════
 *
 * O `destino` é **DADO resolvido no servidor**, nunca um `switch` no `.jsx`: a tela recebe *para onde
 * ir* e só obedece. Isso importa porque **dois dos nove ainda não têm rota**.
 *
 * ⚠️ **PERGUNTA-188-B, RESPONDIDA (dono: usuário / Fases 190-191):** enquanto a rota da revisão
 * detalhada não existir — `routes/mlb_anuncios.php:491` diz que ela é da Fase 190/191 —, os destinos
 * de `ajuste_em_aberto` e `titulo_longo` ficam **`null`**, a `correcao` deles sai **`null`** e o
 * **botão NÃO É DESENHADO**. ⚠️ O **TEXTO do bloqueio continua aparecendo**: a pessoa precisa saber
 * o que trava a oferta, mesmo sem um lugar para onde ir. ⚠️ **Botão sem destino é D4 violado** —
 * zero `onClick` vazio, zero `disabled` decorativo. É a régua que o usuário já exerceu QUATRO vezes.
 *
 * ⚠️ `em_preparacao` também é `null` nos dois campos, e por outro motivo: o protótipo já dá
 * `fix: ''` (`:1089`) porque **não há nada que a pessoa possa fazer** enquanto o sistema monta a
 * oferta.
 *
 * ⚠️⚠️ **E `frete_ausente` tem DOIS ramos — DESVIO DECLARADO (Regra 1), e ele conserta um defeito
 * real.** A Fase 186 já diverge do protótipo de propósito: quando o que falta **não é o frete** (são
 * **CINCO** as linhas que anulam o resultado), a correção deixa de ser *"Informar frete"* e passa a
 * ser {@see VocabularioDoPortao::CORRECAO_SEM_FRETE} (*"Solicitar ajuste"*). ➡️ Cravar o modal de
 * **frete** nos dois casos faria o botão dizer *"Solicitar ajuste"* e abrir o modal de **frete** —
 * o rótulo apontando para um lugar e o destino para outro, que é exatamente o que VOC-04 existe para
 * impedir. ➡️ **O destino é derivado da correção que a 186 JÁ resolveu**, num lugar só.
 *
 * ═══ ⚠️ COL-06 — TODA DATA SAI FORMATADA DO SERVIDOR ═══════════════════════════════════════════
 *
 * Nenhum `Carbon` viaja no payload, e nenhuma data é montada no cliente. `Invalid Date` e o
 * deslocamento de fuso de data-only em BRT já morderam este projeto. A espera sai **string pronta**,
 * pela fórmula pública da 187-05.
 *
 * ═══ ⚠️ A FRONTEIRA: O PAINEL APROVA, NÃO PUBLICA (critério 18) ════════════════════════════════
 *
 * Aprovar **ENFILEIRA**; a escrita no Mercado Livre é da **Fase 193** (o gancho
 * `AndamentoDaOfertaService::aoAprovar()` está vazio). Este serviço não aprova nada — ele só
 * **descreve** o que a tela mostra. ⚠️ E não existe aqui (nem em lugar nenhum desta área)
 * "confirmar publicação", "segundo passo", "desfazer" ou "cancelar aprovação".
 *
 * ═══ ⚠️ O QUE NASCE AQUI COMO `null`, COM O PLANO DONO ESCRITO ══════════════════════════════════
 *
 * - **`fotos`** → preenchida pelo plano **188-03** (a coluna de fotos e a URL da miniatura);
 * - **`preco`** → preenchida pelo plano **188-04** (a coluna de preço e resultado).
 *
 * ⚠️ As duas chaves **EXISTEM desde já, com valor `null`** — **nunca ausentes**. Chave ausente e
 * chave nula são casos **diferentes** no cliente, e foi chave ausente que derrubou a página em
 * 07/10/2026.
 *
 * ═══ ⚠️ CUSTO: UMA leitura de bloqueios por oferta, e NENHUMA fora do `?oferta=` ════════════════
 *
 * {@see BloqueiosDaOfertaService::para()} lê a composição, que consulta `/sites/MLB/listing_prices`.
 * Ele é chamado **UMA** vez por `para()` deste serviço — há gate com espião contando, com isca nas
 * duas direções. Varrer a fila "para já deixar pronto" é a classe do incidente de **30/07/2026**,
 * quando requisições lentas ocuparam os workers do php-fpm e **até o login parou**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PainelDeRevisaoService
{
    // ─── Os literais do painel (`:1301`), que são DIFERENTES dos da linha da fila (`:1242`) ──
    //
    // ⚠️ Não é duplicação: o protótipo escreve "Automática"/"Manual" e `'v' + version` na LINHA, e
    // "Preparado automaticamente"/"Cadastro manual" e `'Versão ' + version` no PAINEL. São dois
    // lugares com dois textos, de propósito — a linha é estreita, o painel tem 1140px.

    /** `:1301` — `o.origin === 'auto' ? 'Preparado automaticamente' : 'Cadastro manual'`. */
    public const ORIGEM_AUTOMATICA = 'Preparado automaticamente';

    public const ORIGEM_MANUAL = 'Cadastro manual';

    /** `:1301` — `version: 'Versão ' + o.version`. Carrega o espaço no fim de propósito. */
    public const VERSAO_PREFIXO = 'Versão ';

    /** `:1301` — o sufixo do formato no PAINEL. Na LINHA é `' · 1 un.'`, e são textos distintos. */
    public const FORMATO_UNIDADE_SUFIXO = ' · 1 un. por oferta';

    public const FORMATO_COMBO_SUFIXO = ' un. por oferta';

    /** `:1309`/`:1318` — `(i + 1) + ' de ' + _nav.length + ' na fila'`. */
    public const POSICAO_SEPARADOR = ' de ';

    public const POSICAO_SUFIXO = ' na fila';

    /** `:582` — o rótulo do botão da caixa de falha, literal. */
    public const FALHA_ROTULO_ACAO = 'Tentar publicar novamente';

    // ─── Os 5 textos do motivo fora do `live` (`:1297`), na ordem do ternário ────────────────

    public const MOTIVO_APROVADO_PREFIXO = 'Aprovado na versão ';

    public const MOTIVO_APROVADO_POR = ' por ';

    public const MOTIVO_APROVADO_SUFIXO = ' · aguardando publicação';

    public const MOTIVO_PUBLICANDO = 'Publicando no Mercado Livre · edição bloqueada';

    public const MOTIVO_PUBLICADO_PREFIXO = 'Publicado e confirmado · ';

    public const MOTIVO_FALHA_PREFIXO = 'Falha na publicação · versão aprovada ';

    public const MOTIVO_FALHA_SUFIXO = ' preservada';

    public const MOTIVO_EM_PREPARACAO = 'Em preparação';

    // ─── As cores do motivo (`:1308`), como CHAVE SEMÂNTICA ─────────────────────────────────
    //
    // ⚠️ TOK-15 — **nenhum HEX sai do PHP**: o `content` do Tailwind NÃO varre `.php`, então uma
    // classe cunhada aqui não geraria CSS. A cor é chave; o token mora no `.jsx`.

    public const COR_VERMELHA = 'vermelho';

    public const COR_VERDE = 'verde';

    /**
     * ⚠️ Fora do `live` o protótipo usa `this.STM[o.status][1]` (`:1308`) — **a cor do próprio
     * andamento**. Recopiar as 8 cores aqui criaria uma segunda verdade ao lado de
     * `andamentoDoPortao.jsx`, que já as tem e já é a fonte do selo. ➡️ O servidor diz
     * **"pinte com a cor do andamento"**, e o módulo resolve pela chave que já viaja em
     * `identificacao.chips.andamento.chave`.
     */
    public const COR_DO_ANDAMENTO = 'andamento';

    // ─── Os tipos de destino de correção (critério 9) ───────────────────────────────────────

    public const DESTINO_FOTO = 'foto';

    public const DESTINO_MODAL = 'modal';

    public const DESTINO_ACAO = 'acao';

    public const MODAL_FRETE = 'frete';

    public const MODAL_EXCECAO = 'excecao';

    public const MODAL_AJUSTES = 'ajustes';

    public const ACAO_CONFERIR_ALTERACAO = 'conferir_alteracao';

    /**
     * ⚠️⚠️ **PERGUNTA-188-B** — os bloqueios cuja correção levaria à **revisão detalhada**, que é da
     * Fase 190/191 e **não existe** (`routes/mlb_anuncios.php:491`).
     *
     * Enquanto a rota não existir, `destino` e `correcao` dos dois saem **`null`** e a tela **não
     * desenha botão**; o **texto** do bloqueio continua aparecendo. ⚠️ **Lista CONSTANTE, com dono
     * declarado** — e é ela que a Fase 190/191 esvazia, num lugar só.
     */
    public const SEM_ROTA_ATE_A_FASE_190 = [
        VocabularioDoPortao::AJUSTE_EM_ABERTO,
        VocabularioDoPortao::TITULO_LONGO,
    ];

    /**
     * ⚠️ Os andamentos que NÃO entram na navegação ↑/↓ (`_nav`, `:1263`).
     *
     * ⚠️⚠️ **`preparando` sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE** — a divergência **D9** da
     * Fase 187, reproduzida de propósito e **já no ar**. **São listas diferentes; não unifique.**
     */
    public const FORA_DA_NAVEGACAO = [PubAprovacaoOferta::PREPARANDO];

    public function __construct(
        private BloqueiosDaOfertaService $bloqueios,
        private ProximoARevisarService $proximo,
        private AndamentoDaOfertaService $andamento,
    ) {}

    /**
     * O view-model do painel desta oferta.
     *
     * ⚠️ `$base` é o recorte que a FILA já usa — a conta (as duas âncoras) **mais** os filtros,
     * **menos a etapa** —, construído pelo controller com
     * {@see \App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::paraAConta()}, que
     * delega a {@see AndamentoDaOfertaService::filaDaConta()}. ➡️ **Receber o Builder, em vez de
     * remontá-lo, é o que impede o ↑/↓ de pular para fora do recorte em que a pessoa está** — e o
     * que evita uma segunda consulta divergente.
     *
     * @param  Builder  $base  a fila da conta com os filtros, MENOS a etapa
     * @return array{
     *     oferta_id: int,
     *     versao: int,
     *     identificacao: array{produto: ?string, sku: ?string, titulo: ?string, chips: array, responsavel: ?string, espera: ?string},
     *     falha: array{mensagem: ?string, rotulo_acao: string}|null,
     *     navegacao: array{ids: list<int>, anterior_id: ?int, proxima_id: ?int, posicao: ?int, total: int, pos: ?string},
     *     pendencias: list<array{chave: string, texto: string, correcao: ?string, severidade: string, glifo: string, destino: ?array}>,
     *     rodape: array{aprovavel: bool, motivo: string, motivo_cor: string, botoes: array{salvar: bool, ajustes: bool, aprovar: bool, proximo: bool}},
     *     fotos: null,
     *     preco: null
     * }
     */
    public function para(PubAprovacaoOferta $oferta, Builder $base): array
    {
        // ⚠️⚠️ **UMA leitura, e é a ÚNICA deste método.** Ela consulta o Mercado Livre pela
        // composição; duas chamadas dobrariam as requisições. Há gate com espião, com isca.
        $leitura = $this->bloqueios->para($oferta);

        $aoVivo = ($leitura['ao_vivo'] ?? false) === true;
        $bloqueios = is_array($leitura['bloqueios'] ?? null) ? $leitura['bloqueios'] : [];

        $navegacao = $this->navegacao($oferta, $base);

        return [
            'oferta_id' => (int) $oferta->id,
            // ⚠️ COL-04 depende dela: é a versão **no instante da leitura**, e é com ela que a tela
            // detecta que a oferta mudou debaixo de quem revisa. Quem incrementa é a rotina das 8
            // mudanças relevantes (plano 186-08) — este serviço só LÊ.
            'versao' => max(1, (int) $oferta->versao),
            'identificacao' => $this->identificacao($oferta),
            'falha' => $this->falha($oferta),
            'navegacao' => $navegacao,
            'pendencias' => $this->pendencias($bloqueios, $aoVivo, $leitura['foco_imagem_id'] ?? null),
            'rodape' => $this->rodape($oferta, $leitura, $aoVivo, $bloqueios),
            // ⚠️ NASCEM `null`, DECLARADAS, com o plano dono — nunca ausentes. Ver o docblock.
            'fotos' => null,   // plano 188-03
            'preco' => null,   // plano 188-04
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A IDENTIFICAÇÃO (`:1301`-`:1302`) — produto · SKU, título, os 5 chips, responsável, espera
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ Os literais de `sku`, `formato` e `origem` são `:1301` — **remedidos por `sed`**: a prosa
     * herdada apontava `:1309`, que é o `onApprove`/`onSave`/`onAdjust`/`onRetry`. **Deslocamento de
     * 8 linhas**, e o conserto é do ponteiro, não do valor.
     *
     * @return array{produto: ?string, sku: ?string, titulo: ?string, chips: array<string, mixed>, responsavel: ?string, espera: ?string}
     */
    private function identificacao(PubAprovacaoOferta $oferta): array
    {
        $unidades = $this->unidades($oferta);
        $minutos = $this->esperaEmMinutos($oferta);

        return [
            'produto' => $this->texto($oferta->produto?->nome),
            // `:1301` — `set.units > 1 ? pr.sku + '-K' + set.units : pr.sku`. ⚠️ Sem SKU, nada:
            // nunca um `-K2` solto.
            'sku' => $this->sku($oferta, $unidades),
            'titulo' => $this->texto($oferta->alvo?->titulo),
            // ⚠️⚠️ Os CINCO chips de `:568`-`:572`, nesta ordem.
            'chips' => [
                'modalidade' => $this->modalidade($oferta),
                // `:1301` — `'Combo ×N · N un. por oferta'` ou `'Unidade · 1 un. por oferta'`.
                'formato' => $unidades > 1
                    ? GrupoDaFilaService::PREFIXO_COMBO.$unidades.' · '.$unidades.self::FORMATO_COMBO_SUFIXO
                    : GrupoDaFilaService::UNIDADE.self::FORMATO_UNIDADE_SUFIXO,
                'origem' => $oferta->origem === PubAprovacaoOferta::ORIGEM_AUTO
                    ? self::ORIGEM_AUTOMATICA
                    : self::ORIGEM_MANUAL,
                'versao' => self::VERSAO_PREFIXO.max(1, (int) $oferta->versao),
                // ⚠️⚠️ **AQUI ESTE ARQUIVO EXPÕE O ANDAMENTO** — e só EXPÕE. O rótulo é LIDO de
                // `PubAprovacaoOferta::ANDAMENTOS` pelo método do próprio modelo; a cor o `.jsx`
                // resolve pela chave, por `andamentoDe()`. Ver o docblock da classe (camada B).
                'andamento' => [
                    'chave' => (string) $oferta->andamento,
                    'rotulo' => $oferta->rotulo(),
                ],
            ],
            // ⚠️ Sem nome não se inventa responsável.
            'responsavel' => $this->texto($oferta->responsavel?->name),
            // ⚠️ `:1302` — `'aguardando há ' + wait(o.waitMin)`. O prefixo e a fórmula são **LIDOS**
            // da 187-05: copiá-los criaria duas verdades para o mesmo número na mesma tela.
            // ⚠️ COL-06 — sai **string pronta**; nenhum `Carbon` viaja. Espera desconhecida é
            // `null`, e a tela não desenha a linha — nunca "aguardando há 0 min" inventado.
            'espera' => $minutos === null
                ? null
                : ProximoARevisarService::PREFIXO_ESPERA.$this->proximo->rotuloDaEspera($minutos),
        ];
    }

    /**
     * A modalidade do chip de `:568` — "Clássico"/"Premium", com o losângo do Premium.
     *
     * ⚠️ Os dois rótulos têm **UMA** casa, e não é esta: eles são **LIDOS** de
     * `EstruturaAnuncio::TIPOS` pela via de `EstruturaPublicacao::LISTING_TYPES`. Escrever
     * `'gold_pro'` à mão aqui seria uma terceira cópia daquele literal.
     *
     * @return array{rotulo: ?string, premium: bool}
     */
    private function modalidade(PubAprovacaoOferta $oferta): array
    {
        $listingType = $oferta->alvo?->listing_type_id;
        $rotulo = null;

        if (is_string($listingType) && $listingType !== '') {
            $tipo = array_search($listingType, EstruturaPublicacao::LISTING_TYPES, true);
            $rotulo = $tipo === false ? null : (EstruturaAnuncio::TIPOS[$tipo] ?? null);
        }

        return [
            'rotulo' => $rotulo,
            'premium' => $listingType === (EstruturaPublicacao::LISTING_TYPES[EstruturaAnuncio::TIPO_PREMIUM] ?? null),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A CAIXA DE FALHA (`:579`-`:582`)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A caixa de falha, ou `null` fora do andamento de falha.
     *
     * ⚠️ **PUB-05 — a mensagem é o erro REAL do Mercado Livre**, gravado por
     * {@see AndamentoDaOfertaService::mover()} em `erro_publicacao`. Nunca um "algo deu errado":
     * sem a frase real, a pessoa não tem como saber se tenta de novo ou conserta o cadastro.
     * ⚠️ Sem erro gravado, `mensagem` é `null` e a caixa **continua aparecendo** com o rótulo da
     * ação — a falha É o fato, e esconder a caixa esconderia trabalho.
     *
     * @return array{mensagem: ?string, rotulo_acao: string}|null
     */
    private function falha(PubAprovacaoOferta $oferta): ?array
    {
        if ($oferta->andamento !== PubAprovacaoOferta::FALHA) {
            return null;
        }

        return [
            'mensagem' => $this->texto($oferta->erro_publicacao),
            'rotulo_acao' => self::FALHA_ROTULO_ACAO,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A NAVEGAÇÃO ↑/↓ — **DADO**, não string
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A navegação ↑/↓: os **ids**, as pontas, a posição e a frase.
     *
     * ⚠️⚠️ **POR QUE OS IDS VIAJAM, e não só a frase:** o critério 2 pede ↑/↓ **navegando pela
     * fila, pulando `preparando`**. **String não é navegável** — entregar só `'3 de 12 na fila'`
     * obrigaria a tela a **recalcular a fila no cliente**, que é a segunda verdade que este plano
     * existe para impedir.
     *
     * ⚠️ A ordem vem de {@see AndamentoDaOfertaService::ordenarFila()}, a **ÚNICA PORTA**, e o
     * filtro de `preparando` é aplicado **sobre o resultado dela** — nunca com uma segunda consulta,
     * que poderia ordenar diferente.
     *
     * ⚠️ A oferta aberta estando em `preparando`: `posicao`, `anterior_id`, `proxima_id` e `pos` são
     * todos **`null`**. ⚠️ **O protótipo diverge aqui e PERDE**: o `indexOf` dele devolve `-1` e
     * `:1318` produz **"0 de N na fila"** — a tela afirmando uma posição que não existe. ➡️ **A tela
     * não inventa posição.**
     *
     * @return array{ids: list<int>, anterior_id: ?int, proxima_id: ?int, posicao: ?int, total: int, pos: ?string}
     */
    private function navegacao(PubAprovacaoOferta $oferta, Builder $base): array
    {
        $ordenadas = $this->andamento->ordenarFila(
            (clone $base)->get(['id', 'andamento', 'entrou_na_fila_em'])
        );

        $ids = [];
        foreach ($ordenadas as $linha) {
            // ⚠️ Flag DENTRO do laço (o Rollup elide variável de escopo usada em callback, e aqui a
            // disciplina é a mesma por coerência): nada é pré-computado fora.
            if (! in_array((string) $linha->andamento, self::FORA_DA_NAVEGACAO, true)) {
                $ids[] = (int) $linha->id;
            }
        }

        $total = count($ids);
        $indice = array_search((int) $oferta->id, $ids, true);

        if ($indice === false) {
            return [
                'ids' => $ids,
                'anterior_id' => null,
                'proxima_id' => null,
                'posicao' => null,
                'total' => $total,
                'pos' => null,
            ];
        }

        return [
            'ids' => $ids,
            // ⚠️ Na PRIMEIRA, `anterior_id` é `null`; na ÚLTIMA, `proxima_id` é `null`. Sem isso o
            // ↑/↓ daria a volta, e dar a volta numa fila de aprovação faz a pessoa revisar de novo
            // o que já revisou sem perceber que voltou ao começo.
            'anterior_id' => $indice > 0 ? $ids[$indice - 1] : null,
            'proxima_id' => $indice < $total - 1 ? $ids[$indice + 1] : null,
            'posicao' => $indice + 1,
            'total' => $total,
            // `:1318` — a frase, 1-based.
            'pos' => ($indice + 1).self::POSICAO_SEPARADOR.$total.self::POSICAO_SUFIXO,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS PENDÊNCIAS E OS **NOVE DESTINOS** (critério 9)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * As pendências, na ordem de {@see VocabularioDoPortao::BLOQUEIOS_EM_ORDEM}, **cada uma com o
     * seu destino de correção**.
     *
     * ⚠️ A ordem **não é arrumação**: ela já vem de `BloqueiosDaOfertaService::para()`, que empilha
     * na ordem literal de `info()` (`:1089`-`:1097`), e é **o PRIMEIRO** que o rodapé abrevia
     * (BLOQ-12). Reordenar mudaria o que a pessoa é mandada fazer primeiro.
     *
     * ⚠️⚠️ **`correcao` é `null` em TODAS fora do `live`** — `hasFix = !!b.fix && inf.live`
     * (**`:1295`**; a prosa herdada dizia `:1304`, que é o `shared`/`safeCount` — deslocamento de 9
     * linhas, remedido). Sem o `&& live`, oferta já publicada ofereceria botão de conserto.
     *
     * @param  list<array{chave: string, texto: string, correcao: string, severidade: string, glifo: string}>  $bloqueios
     * @return list<array{chave: string, texto: string, correcao: ?string, severidade: string, glifo: string, destino: ?array}>
     */
    private function pendencias(array $bloqueios, bool $aoVivo, mixed $focoImagemId): array
    {
        $pendencias = [];

        foreach ($bloqueios as $bloqueio) {
            $chave = (string) ($bloqueio['chave'] ?? '');
            $correcaoCrua = (string) ($bloqueio['correcao'] ?? '');
            $destino = $this->destino($chave, $correcaoCrua, $focoImagemId);

            // ⚠️ Flag DENTRO do laço. Três coisas zeram a correção, e cada uma por sua razão:
            //   (1) fora do `live` (`:1295`);
            //   (2) `fix` vazio — é o `em_preparacao` (`:1089`), que não tem o que consertar;
            //   (3) **destino `null`** — PERGUNTA-188-B: sem rota não há botão, e botão sem
            //       destino é D4 violado. O TEXTO continua aparecendo.
            $temCorrecao = $aoVivo && $correcaoCrua !== '' && $destino !== null;

            $pendencias[] = [
                'chave' => $chave,
                'texto' => (string) ($bloqueio['texto'] ?? ''),
                'correcao' => $temCorrecao ? $correcaoCrua : null,
                // ⚠️ A severidade vem de `VocabularioDoPortao::severidade()` pela 186, já com o
                // ramo de `fotos_a_conferir` **vermelha QUANDO há alerta** (`:1295`) — o ramo mais
                // fácil de omitir. **LIDA, nunca recalculada.**
                'severidade' => (string) ($bloqueio['severidade'] ?? ''),
                // ⚠️ VOC-05 — ícone + texto, **nunca só cor**.
                'glifo' => (string) ($bloqueio['glifo'] ?? ''),
                // ⚠️ O destino é dado mesmo sem correção visível: o `.jsx` só desenha botão quando
                // os DOIS existem, e manter os campos independentes deixa o invariante testável.
                'destino' => $temCorrecao ? $destino : null,
            ];
        }

        return $pendencias;
    }

    /**
     * O **destino** de uma pendência — os NOVE de `fix()` (**`:1172`**-`:1178`).
     *
     * ⚠️ O ponteiro `:1171` que circulava é a **abertura do método** `fix(o, k) {`; a expressão
     * `inf.alerts[0] || inf.pend[0]` mora em **`:1172`**. Remedido por `sed`.
     *
     * @return array<string, mixed>|null `null` = **SEM BOTÃO** (ver PERGUNTA-188-B no docblock)
     */
    private function destino(string $chave, string $correcao, mixed $focoImagemId): ?array
    {
        // (1) PERGUNTA-188-B — a revisão detalhada é da Fase 190/191 e a rota não existe.
        if (in_array($chave, self::SEM_ROTA_ATE_A_FASE_190, true)) {
            return null;
        }

        return match ($chave) {
            // (2) e (3) `photos`/`nophoto` — a primeira COM ALERTA ou, não havendo, a primeira
            // pendente (`:1172`). ⚠️ O id é o da linha de REVISÃO (`pub_aprovacao_imagens`), e vem
            // **LIDO** de `foco_imagem_id`, que a 186 já resolve. ⚠️ Em `sem_fotos` não há imagem
            // nenhuma: o destino continua sendo a coluna de fotos, com `imagem_id` **null** — a
            // tela abre a coluna e **diz que está vazia** (IMG-12), nunca painel vazio.
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::SEM_FOTOS => [
                'tipo' => self::DESTINO_FOTO,
                'imagem_id' => is_numeric($focoImagemId) ? (int) $focoImagemId : null,
            ],

            // (4) `freight` — ⚠️ **DOIS ramos, e é DESVIO DECLARADO (Regra 1).** Ver o docblock da
            // classe: quando o que falta não é o frete, a 186 já troca a correção para
            // "Solicitar ajuste", e cravar o modal de frete faria o rótulo e o destino apontarem
            // para lugares diferentes. ➡️ O destino **segue a correção que a 186 resolveu**.
            VocabularioDoPortao::FRETE_AUSENTE => [
                'tipo' => self::DESTINO_MODAL,
                'modal' => $correcao === VocabularioDoPortao::CORRECAO_SEM_FRETE
                    ? self::MODAL_AJUSTES
                    : self::MODAL_FRETE,
            ],

            // (5) e (6) `loss`/`margin` — o modal de exceção, com a CHAVE do que se autoriza.
            // ⚠️ A exceção libera **SÓ** estes dois (critério 11): nunca fotos, frete, alteração,
            // ajuste ou título.
            VocabularioDoPortao::RESULTADO_NEGATIVO,
            VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO => [
                'tipo' => self::DESTINO_MODAL,
                'modal' => self::MODAL_EXCECAO,
                'excecao' => $chave,
            ],

            // (7) `change` — ⚠️ **confere SEM alterar o preço** (`:1176`: `checkChange(o.id)`).
            // Quem limpa o aviso é `AprovacaoDaOfertaService::conferirAlteracao()` (BLOQ-07).
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA => [
                'tipo' => self::DESTINO_ACAO,
                'acao' => self::ACAO_CONFERIR_ALTERACAO,
            ],

            // (9) `prep` — o protótipo já dá `fix: ''` (`:1089`): não há nada a fazer.
            // ⚠️ `default` cobre `em_preparacao` **e** chave desconhecida: sem destino conhecido,
            // **sem botão**. Nunca um botão para um lugar que ninguém mapeou.
            default => null,
        };
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O RODAPÉ DE 66px (`:712`-`:721`)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O rodapé: `aprovavel`, o motivo, a cor do motivo e **quais botões existem**.
     *
     * ⚠️⚠️ `aprovavel` é **LIDO** de `BloqueiosDaOfertaService::para()['aprovavel']`, nunca
     * recalculado. É `live && B.length === 0` (`:1099`), e é a **ÚNICA trava** contra publicar
     * errado — porque aprovar já publica e não há desfazer.
     *
     * ⚠️ `botoes.aprovar` e `aprovavel` são coisas **DIFERENTES**: o primeiro é *"o botão existe"*
     * (só com `live`, `:713`/`:718`), o segundo é *"ele está habilitado"* (o `disabled=` de E12, que
     * o plano 188-10 desenha). Fundi-los faria oferta publicada mostrar um botão amarelo.
     *
     * @param  array<string, mixed>  $leitura
     * @param  list<array{chave: string, texto: string, correcao: string, severidade: string, glifo: string}>  $bloqueios
     * @return array{aprovavel: bool, motivo: string, motivo_cor: string, botoes: array{salvar: bool, ajustes: bool, aprovar: bool, proximo: bool}}
     */
    private function rodape(PubAprovacaoOferta $oferta, array $leitura, bool $aoVivo, array $bloqueios): array
    {
        return [
            'aprovavel' => ($leitura['aprovavel'] ?? false) === true,
            'motivo' => $aoVivo
                // ⚠️⚠️ **CHAMADO, nunca remontado.** Ele devolve o literal verde
                // (`VocabularioDoPortao::TUDO_CONFERIDO`) sem bloqueio e
                // `'Bloqueado: ' + 1º abreviado + ' +N'` com bloqueio, com o `+N` contando os
                // **DEMAIS**. Ver a divergência contra o `join` de `:1297` no docblock da classe.
                ? $this->bloqueios->resumoDoRodape($bloqueios)
                : $this->motivoForaDoLive($oferta),
            // `:1308` — vermelho com bloqueio, verde sem, e a cor do ANDAMENTO fora do `live`.
            'motivo_cor' => $aoVivo
                ? ($bloqueios === [] ? self::COR_VERDE : self::COR_VERMELHA)
                : self::COR_DO_ANDAMENTO,
            // `:713`/**`:718`** — os dois `<sc-if value="{{ P.isLive }}">` do rodapé. ⚠️ A prosa
            // herdada dizia `:713`/`:716`, e `:716` é o `</sc-if>` de FECHAMENTO. Remedido.
            'botoes' => [
                'salvar' => $aoVivo,
                'ajustes' => $aoVivo,
                'aprovar' => $aoVivo,
                // ⚠️ "Próximo ↓" existe **SEMPRE** (`:721`, fora dos `sc-if`): a pessoa tem de
                // poder seguir a fila mesmo numa oferta que não dá para mexer.
                'proximo' => true,
            ],
        ];
    }

    /**
     * Os **5** textos do motivo fora do `live` (**`:1297`**), na ordem do ternário.
     *
     * ⚠️ Este é o SEGUNDO lugar em que este arquivo **expõe** o andamento — e, como o chip, só
     * expõe: a frase é escolhida PELO andamento lido, e nada é escrito. Ver a camada B no docblock.
     *
     * ⚠️ Sem nome de aprovador, o segmento `' por {nome}'` é **omitido** — não se inventa
     * responsável, e é a mesma disciplina de `LinhaDaOfertaService::subDoAndamento()`.
     * ⚠️ Sem `ml_item_id`, o ramo `publicado` sai só com o prefixo aparado: AND-04 impede chegar a
     * `publicado` sem MLB confirmado, então o ramo é defensivo — mas um `·` solto na tela seria a
     * tela mostrando pontuação em vez de informação.
     */
    private function motivoForaDoLive(PubAprovacaoOferta $oferta): string
    {
        $andamento = (string) $oferta->andamento;

        if ($andamento === PubAprovacaoOferta::APROVADO) {
            $quem = $this->texto($oferta->aprovadoPor?->name);

            return self::MOTIVO_APROVADO_PREFIXO
                .(int) ($oferta->versao_aprovada ?? $oferta->versao)
                .($quem === null ? '' : self::MOTIVO_APROVADO_POR.$quem)
                .self::MOTIVO_APROVADO_SUFIXO;
        }

        if ($andamento === PubAprovacaoOferta::PUBLICANDO) {
            return self::MOTIVO_PUBLICANDO;
        }

        if ($andamento === PubAprovacaoOferta::PUBLICADO) {
            $mlb = $this->texto($oferta->ml_item_id);

            return $mlb === null
                ? rtrim(self::MOTIVO_PUBLICADO_PREFIXO, ' ·')
                : self::MOTIVO_PUBLICADO_PREFIXO.$mlb;
        }

        if ($andamento === PubAprovacaoOferta::FALHA) {
            return self::MOTIVO_FALHA_PREFIXO
                .(int) ($oferta->versao_aprovada ?? $oferta->versao)
                .self::MOTIVO_FALHA_SUFIXO;
        }

        // ⚠️ O ramo `senão` do ternário — `preparando` e qualquer chave desconhecida.
        return self::MOTIVO_EM_PREPARACAO;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Os auxiliares
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Quantas unidades a oferta leva.
     *
     * ⚠️ **Mesma divergência declarada** de `GrupoDaFilaService::rotuloDoConjunto()`,
     * `ProximoARevisarService::formato()` e `LinhaDaOfertaService::unidades()`: é `quantidade_kit` e
     * só, **não** `ehKit()`. Quatro lugares com a mesma leitura é a definição que o projeto já
     * fechou — mudá-la aqui abriria a quinta, divergente.
     */
    private function unidades(PubAprovacaoOferta $oferta): int
    {
        return max(1, (int) ($oferta->produto?->quantidade_kit ?? 1));
    }

    /** `:1301` — o SKU do combo ganha `-K{N}`. Sem SKU, nada (nunca um `-K2` solto). */
    private function sku(PubAprovacaoOferta $oferta, int $unidades): ?string
    {
        $sku = $this->texto($oferta->produto?->sku);

        if ($sku === null) {
            return null;
        }

        return $unidades > 1 ? $sku.'-K'.$unidades : $sku;
    }

    /**
     * Os minutos de espera, ou `null`.
     *
     * ⚠️ **A MESMA fórmula** de `ProximoARevisarService` e `LinhaDaOfertaService`: carimbo no futuro
     * é clampado em 0, e espera desconhecida é `null` — nunca zero, que afirmaria "entrou agora".
     */
    private function esperaEmMinutos(PubAprovacaoOferta $oferta): ?int
    {
        $entrou = $oferta->entrou_na_fila_em;

        if ($entrou === null) {
            return null;
        }

        return intdiv(max(0, now()->getTimestamp() - $entrou->getTimestamp()), 60);
    }

    /**
     * Texto não-vazio, ou `null`.
     *
     * ⚠️ **REND-03 / a TELA PRETA:** nunca `(string) $valor`, que **LANÇA** com array e com objeto
     * sem `__toString`. Guarda de tipo ANTES de qualquer coisa.
     */
    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = trim($valor);

        return $limpo === '' ? null : $limpo;
    }
}
