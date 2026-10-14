<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O AJUSTE COM ESCOPO, A SUA RESOLUÇÃO, E **A EXCEÇÃO AUTORIZADA**.
 *
 * ═══ AND-07 — O ESCOPO DECIDE QUANTAS OFERTAS SAEM DA FILA ═════════════════════════════════════
 *
 * | escopo    | alcança                                                                     |
 * |-----------|-----------------------------------------------------------------------------|
 * | `offer`   | **só aquela oferta**                                                        |
 * | `set`     | **todas as ofertas do rascunho do conjunto** (o Clássico E o Premium)       |
 * | `product` | **todas as ofertas do produto**                                             |
 *
 * ⚠️⚠️ O caso `set` é o que importa para a segurança: o conjunto de fotos é **compartilhado**
 * entre Clássico e Premium. Se o pedido movesse só o Clássico, **o Premium seguiria aprovável e
 * iria ao ar com a foto reprovada** — e o usuário decidiu em 14/10/2026 que **aprovar JÁ PUBLICA**,
 * sem desfazer. Há teste com a oferta irmã PREENCHIDA.
 *
 * ═══ ⚠️ A MOVIMENTAÇÃO PASSA **SEMPRE** PELO `AndamentoDaOfertaService` ════════════════════════
 *
 * Nunca `update(['andamento' => …])`. Aquele serviço é a **única porta** (plano 186-04), e escrita
 * de estado espalhada é, aqui, elevação de privilégio (T-186-11): uma escrita direta para
 * `aprovado` publicaria um anúncio que ninguém conferiu. Há gate de `grep` com isca.
 *
 * ⚠️ **SÓ AS OFERTAS "AO VIVO" SÃO MOVIDAS, e isso é DIVERGÊNCIA DECLARADA DO PROTÓTIPO.** Em
 * `design_handoff_publicador/Aprovacao.dc.html:1198` o protótipo move também as de status
 * `'approved'`. Aqui `aprovado → ajustes_solicitados` **não existe** na lista fechada de
 * `PubAprovacaoOferta::TRANSICOES` — e não deve existir: como aprovar já publica, `aprovado` é
 * passagem para `publicando`, não um estado em que a oferta fica parada esperando. Oferta que já
 * foi aprovada está publicando ou publicada, e o caminho de volta dela é a `versao`
 * (`aprovacaoReaberta()`, plano 186-08), não um ajuste. ➡️ As ofertas fora de `LIVE` recebem o
 * **evento no histórico** (o fato aconteceu) e **não** mudam de andamento.
 *
 * ═══ ⚠️ AND-06 NÃO É DESTE PLANO ═══════════════════════════════════════════════════════════════
 *
 * `aguardando_aprovacao → em_revisao_ecf` é parte do ato de **salvar revisão** (`saveReview`), e
 * fica no plano **186-08**. Aqui não há nada disso: pedir ajuste não é salvar revisão.
 *
 * ═══ ⚠️⚠️ A EXCEÇÃO — DECISÃO TRAVADA, NÃO REABRIR (BLOQ-09, usuário em 14/10/2026) ════════════
 *
 * `aplicarExcecao()` grava **direto**: tipo, motivo escrito (**obrigatório, ≥ 10**), **quem
 * liberou**, **quando** e **em que versão** — tudo na MESMA transação, junto do evento `exc` do
 * histórico. **O efeito é IMEDIATO.**
 *
 * ➡️ **NÃO EXISTE** `solicitarExcecao()`, nem estado de espera, nem segundo passo, nem caixa de
 * pendência. **Aprovação em dois passos foi OFERECIDA e RECUSADA.** O controle é **auditoria
 * DEPOIS, não barreira ANTES**, porque aprovar já publica e não há desfazer.
 * ⚠️ **ESTE PARÁGRAFO EXISTE PARA QUE A PRÓXIMA SESSÃO NÃO "COMPLETE" O FLUXO ACHANDO QUE
 * FALTOU.** Se um dia a espera for pedida, reabrir **com o usuário**. Há gate de ausência em três
 * camadas em `ExcecaoAutorizadaTest`, e o da área inteira é do plano **186-09** — nenhum dos dois
 * substitui o outro.
 *
 * ⚠️ **BLOQ-08 — a exceção libera SÓ `resultado_negativo` e `margem_abaixo_do_minimo`**
 * (`PubAprovacaoExcecao::LIBERA`, lista fechada). Nunca fotos, frete, alteração, ajuste ou título.
 * E uma **não** libera a outra: ver a divergência declarada no docblock de `PubAprovacaoExcecao`
 * (o protótipo usa um `!o.exc` único, `:1093-1094`).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AjustesDaOfertaService
{
    /** MOD-01 — ajuste sem texto não envia. */
    public const REGRA_TEXTO_VAZIO = 'MOD-01-AJUSTE-SEM-TEXTO';

    /** DADO-09 — o escopo não é um dos 3 (`prod` do protótipo cai aqui). */
    public const REGRA_ESCOPO_INVALIDO = 'DADO-09-ESCOPO-INVALIDO';

    /** DADO-09 — a âncora não corresponde ao escopo (linha que não entraria no bloqueio). */
    public const REGRA_ANCORA_INVALIDA = 'DADO-09-ANCORA-INCOERENTE';

    /** ⚠️ BLOQ-09 — exceção sem motivo escrito (≥ 10 caracteres) é RECUSADA. */
    public const REGRA_JUSTIFICATIVA_CURTA = 'BLOQ-09-EXCECAO-SEM-MOTIVO';

    /** ⚠️ BLOQ-08 — a exceção não libera esse bloqueio. */
    public const REGRA_EXCECAO_NAO_LIBERA = 'BLOQ-08-EXCECAO-NAO-LIBERA-ESSE-BLOQUEIO';

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    public function __construct(
        private AndamentoDaOfertaService $andamento,
        private HistoricoDaOfertaService $historico,
    ) {}

    /**
     * Pede um ajuste, move as ofertas afetadas e registra o pedido no histórico de cada uma.
     *
     * @param  PubAprovacaoOferta  $oferta  a oferta em revisão (é dela que saem as âncoras)
     * @param  string  $escopo  um dos 3 de `PubAprovacaoAjuste::ESCOPOS`
     * @param  string  $item  o alvo nomeado ("Imagem #3 · Ambientada", "Preço Premium", "Título")
     * @param  string  $texto  o pedido em palavras — **não pode ser vazio** (MOD-01)
     *
     * @throws RegraViolada escopo fora dos 3, texto vazio, ou âncora que o escopo exige e não existe
     */
    public function pedir(PubAprovacaoOferta $oferta, User $quem, string $escopo, string $item, string $texto): PubAprovacaoAjuste
    {
        if (! PubAprovacaoAjuste::escopoValido($escopo)) {
            throw new RegraViolada(
                self::REGRA_ESCOPO_INVALIDO,
                sprintf('"%s" não é um escopo de ajuste.', $escopo),
                ['oferta_id' => $oferta->id, 'escopo' => $escopo]
            );
        }

        // ⚠️ MOD-01 — texto vazio **não envia**. Ajuste sem frase contaria como bloqueio e ninguém
        // saberia o que fazer para resolvê-lo: a oferta travaria para sempre.
        if (trim($texto) === '') {
            throw new RegraViolada(
                self::REGRA_TEXTO_VAZIO,
                'Escreva o que precisa ser ajustado.',
                ['oferta_id' => $oferta->id, 'escopo' => $escopo]
            );
        }

        $ancoras = $this->ancorasPara($oferta, $escopo);

        $ajuste = DB::transaction(function () use ($oferta, $quem, $escopo, $item, $texto, $ancoras) {
            $ajuste = PubAprovacaoAjuste::create([
                'escopo' => $escopo,
                'oferta_id' => $ancoras['oferta_id'],
                'conjunto_id' => $ancoras['conjunto_id'],
                'produto_id' => $ancoras['produto_id'],
                'item' => $item,
                // ⚠️ VOC-06 — o destino LEGÍVEL, montado no SERVIDOR. A chave nunca vai à tela.
                'destino' => PubAprovacaoAjuste::destinoLegivel($escopo, $oferta->alvo?->listing_type_id),
                'texto' => trim($texto),
                'autor_id' => $quem->id,
                'aberto' => true,
            ]);

            // ⚠️ A frase nomeia o ITEM: "Solicitou ajuste em …" é a forma do protótipo (`:1198`).
            $frase = sprintf('Solicitou ajuste em “%s”: %s', $item, trim($texto));

            foreach ($this->ofertasAfetadas($ajuste) as $afetada) {
                // ⚠️ O evento vai para TODAS as afetadas — inclusive as que não mudam de
                // andamento. Sem ele, o histórico do Premium não explicaria por que ele saiu da
                // fila (ou por que um ajuste de conjunto o alcança).
                $this->historico->registrar($afetada, $quem, PubAprovacaoEvento::AJUSTE, $frase, (int) $afetada->versao);

                // ⚠️ A transição passa pela ÚNICA PORTA, e só para quem está ao vivo (ver o
                // docblock da classe).
                if (in_array($afetada->andamento, PubAprovacaoOferta::LIVE, true) && $afetada->andamento !== PubAprovacaoOferta::AJUSTES_SOLICITADOS) {
                    $this->andamento->mover($afetada, PubAprovacaoOferta::AJUSTES_SOLICITADOS, $quem, $item);
                }
            }

            return $ajuste;
        });

        Log::info(self::TAG . ' ajuste pedido', [
            'ajuste_id' => $ajuste->id,
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'escopo' => $escopo,
            'item' => $item,
            'quem_id' => $quem->id,
        ]);

        return $ajuste;
    }

    /**
     * Marca um ajuste como resolvido e, **se ele era o último em aberto**, devolve cada oferta
     * afetada para `aguardando_aprovacao`.
     *
     * ⚠️ "O último" é por OFERTA, não por ajuste: resolver um de dois **não** devolve ninguém. A
     * oferta voltaria a ser aprovável com um ajuste ainda em aberto — e aprovar já publica.
     *
     * @throws RegraViolada quando a transição de volta não é permitida pela única porta
     */
    public function resolver(PubAprovacaoAjuste $ajuste, User $quem): PubAprovacaoAjuste
    {
        $afetadas = $this->ofertasAfetadas($ajuste);

        DB::transaction(function () use ($ajuste, $quem, $afetadas) {
            $ajuste->update([
                'aberto' => false,
                'resolvido_por' => $quem->id,
                'resolvido_em' => now(),
            ]);

            // ⚠️ A forma do protótipo (`:1202`: "Marcou como resolvido"), com o item nomeado.
            // ⚠️ DIVERGÊNCIA DECLARADA: lá a resolução entra como `ok`; aqui ela entra como
            // `adjust`, porque é o mesmo assunto do pedido e o filtro por tipo tem de mostrar a
            // ida E a volta juntas. `ok` tornaria "resolveu um ajuste" indistinguível de "aprovou
            // uma foto".
            $frase = sprintf('Marcou como resolvido: “%s”', $ajuste->item);

            foreach ($afetadas as $afetada) {
                $this->historico->registrar($afetada, $quem, PubAprovacaoEvento::AJUSTE, $frase, (int) $afetada->versao);

                $restam = $this->abertosQueAfetam($afetada)->count();

                if ($restam === 0 && $afetada->andamento === PubAprovacaoOferta::AJUSTES_SOLICITADOS) {
                    $this->andamento->mover($afetada, PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem, $ajuste->item);
                }
            }
        });

        Log::info(self::TAG . ' ajuste resolvido', [
            'ajuste_id' => $ajuste->id,
            'escopo' => $ajuste->escopo,
            'item' => $ajuste->item,
            'quem_id' => $quem->id,
            'ofertas_afetadas' => $afetadas->pluck('id')->all(),
        ]);

        return $ajuste;
    }

    /**
     * Os ajustes **em aberto que afetam esta oferta**, juntando os 3 escopos em **UMA leitura**.
     *
     * ⚠️ É o `openC` do protótipo (`:1086`), e é a base do bloqueio `ajuste_em_aberto` (BLOQ-01).
     * Os três ramos do `OR` lideram cada um com a sua coluna indexada (`pubapaj_oferta_ix`,
     * `pubapaj_conjunto_ix`, `pubapaj_produto_ix`), e é por isso que as âncoras são três colunas
     * nulas em vez de uma polimórfica.
     *
     * ⚠️⚠️ **DADO-14 / T-186-20 — o recorte por conta é ESTRUTURAL, não um `where` a mais.** A
     * consulta é sempre ancorada nos ids DESTA oferta (`oferta_id`, os conjuntos do `rascunho_id`
     * dela, e o `produto_id` dela), então não há como alcançar ajuste de outra empresa: não existe
     * ramo sem âncora. Há teste com a outra empresa PREENCHIDA nos três escopos, nas duas
     * direções.
     *
     * ⚠️ O conjunto é lido por `rascunho_id` (achado S2): a oferta vê **TODOS** os grupos do
     * rascunho, inclusive o `GENERAL` — com um `conjunto_id` só, ajuste nas fotos de outro grupo
     * ficaria fora do bloqueio.
     *
     * ⚠️ `produto_id` nulo (produto apagado pelo `SET NULL`) **não** vira "todos os produtos": o
     * ramo só entra quando a oferta tem produto. Sem isso, um `where('produto_id', null)` casaria
     * com todo ajuste órfão do banco — vazamento ao contrário.
     *
     * @return Collection<int, PubAprovacaoAjuste>
     */
    public function abertosQueAfetam(PubAprovacaoOferta $oferta): Collection
    {
        $conjuntos = PubAprovacaoConjunto::doRascunho((int) $oferta->rascunho_id)->pluck('id')->all();

        return PubAprovacaoAjuste::query()
            ->where('aberto', true)
            ->where(function ($q) use ($oferta, $conjuntos) {
                $q->where('oferta_id', $oferta->id);

                if ($conjuntos !== []) {
                    $q->orWhereIn('conjunto_id', $conjuntos);
                }

                if ($oferta->produto_id !== null) {
                    $q->orWhere('produto_id', $oferta->produto_id);
                }
            })
            ->get();
    }

    /**
     * ⚠️⚠️ **BLOQ-09 — APLICA A EXCEÇÃO. DIRETO, COM MOTIVO ESCRITO, E O EFEITO É IMEDIATO.**
     *
     * Grava numa transação só: a exceção (tipo, justificativa, **quem liberou**, **quando**, **em
     * que versão**) e o evento `exc` do histórico. É esse par que torna "auditoria DEPOIS"
     * verdade — sem ele, não haveria como saber que alguém liberou a publicação de um prejuízo
     * (T-186-16, repúdio).
     *
     * ⚠️ **Não existe segundo passo**: nenhum estado de espera, nada a confirmar, nada a
     * autorizar depois. O usuário recusou a aprovação em dois passos em 14/10/2026.
     *
     * @param  string  $tipo  um de `PubAprovacaoExcecao::LIBERA` (**pt-BR**, não `loss`/`margin`)
     * @param  string  $justificativa  o motivo escrito, **≥ 10 caracteres** depois do `trim()`
     *
     * @throws RegraViolada tipo que a exceção não libera (BLOQ-08), ou motivo curto (BLOQ-09)
     */
    public function aplicarExcecao(PubAprovacaoOferta $oferta, User $quem, string $tipo, string $justificativa): PubAprovacaoExcecao
    {
        // ⚠️ BLOQ-08 — lista fechada. As chaves INGLESAS do protótipo também caem aqui: não existe
        // `||` aceitando as duas formas.
        if (! PubAprovacaoExcecao::libera($tipo)) {
            throw new RegraViolada(
                self::REGRA_EXCECAO_NAO_LIBERA,
                'A exceção autorizada só vale para resultado negativo e margem abaixo do mínimo.',
                ['oferta_id' => $oferta->id, 'tipo' => $tipo]
            );
        }

        // ⚠️ BLOQ-09 — o motivo escrito é a ÚNICA barreira, e ela não é negociável.
        if (! PubAprovacaoExcecao::justificativaValida($justificativa)) {
            throw new RegraViolada(
                self::REGRA_JUSTIFICATIVA_CURTA,
                sprintf('Escreva o motivo da exceção com pelo menos %d caracteres.', PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA),
                ['oferta_id' => $oferta->id, 'tipo' => $tipo]
            );
        }

        $excecao = DB::transaction(function () use ($oferta, $quem, $tipo, $justificativa) {
            $excecao = PubAprovacaoExcecao::create([
                'oferta_id' => $oferta->id,
                'tipo' => $tipo,
                'justificativa' => trim($justificativa),
                // T-186-16 — QUEM e QUANDO, na mesma escrita. Não há um segundo ator.
                'liberada_por' => $quem->id,
                'liberada_em' => now(),
                // BLOQ-05 — em QUE versão. A aprovação reabre quando a versão sobe.
                'versao' => (int) $oferta->versao,
                'ativa' => true,
            ]);

            $frase = sprintf(
                'Autorizou exceção (%s): %s',
                PubAprovacaoExcecao::ROTULOS[$tipo],
                trim($justificativa)
            );

            $this->historico->registrar($oferta, $quem, PubAprovacaoEvento::EXCECAO, $frase, (int) $oferta->versao);

            return $excecao;
        });

        Log::info(self::TAG . ' exceção autorizada', [
            'excecao_id' => $excecao->id,
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'tipo' => $tipo,
            'liberada_por' => $quem->id,
            'versao' => $oferta->versao,
        ]);

        return $excecao;
    }

    /**
     * As ofertas que um ajuste alcança (AND-07).
     *
     * @return Collection<int, PubAprovacaoOferta>
     */
    public function ofertasAfetadas(PubAprovacaoAjuste $ajuste): Collection
    {
        if ($ajuste->escopo === PubAprovacaoAjuste::ESCOPO_OFERTA) {
            return PubAprovacaoOferta::query()->where('id', $ajuste->oferta_id)->get();
        }

        if ($ajuste->escopo === PubAprovacaoAjuste::ESCOPO_CONJUNTO) {
            // ⚠️ Por `rascunho_id` do conjunto: é o que alcança a oferta IRMÃ. O conjunto é do
            // rascunho, e o rascunho tem os dois alvos — o compartilhamento é ESTRUTURAL.
            $rascunhoId = $ajuste->conjunto?->rascunho_id;

            return $rascunhoId === null
                ? PubAprovacaoOferta::query()->whereRaw('1 = 0')->get()
                : PubAprovacaoOferta::query()->where('rascunho_id', $rascunhoId)->get();
        }

        return $ajuste->produto_id === null
            ? PubAprovacaoOferta::query()->whereRaw('1 = 0')->get()
            : PubAprovacaoOferta::query()->where('produto_id', $ajuste->produto_id)->get();
    }

    /**
     * As âncoras que o escopo exige, lidas DA OFERTA.
     *
     * ⚠️ Exatamente UMA é preenchida — a regra que `PubAprovacaoAjuste::ancoraValida()` confere.
     *
     * @return array{oferta_id: int|null, conjunto_id: int|null, produto_id: int|null}
     *
     * @throws RegraViolada quando a âncora que o escopo exige não existe na oferta
     */
    private function ancorasPara(PubAprovacaoOferta $oferta, string $escopo): array
    {
        $vazias = ['oferta_id' => null, 'conjunto_id' => null, 'produto_id' => null];

        if ($escopo === PubAprovacaoAjuste::ESCOPO_OFERTA) {
            return array_merge($vazias, ['oferta_id' => (int) $oferta->id]);
        }

        if ($escopo === PubAprovacaoAjuste::ESCOPO_CONJUNTO) {
            // ⚠️ O conjunto GERAL do rascunho é a âncora quando o pedido é do conjunto como um
            // todo. Quem pede ajuste numa IMAGEM específica passa o conjunto daquela imagem — e
            // isso chega pelo `item`, que nomeia a imagem.
            $conjunto = PubAprovacaoConjunto::doRascunho((int) $oferta->rascunho_id)->orderBy('id')->first();

            if ($conjunto === null) {
                throw new RegraViolada(
                    self::REGRA_ANCORA_INVALIDA,
                    'Este anúncio ainda não tem conjunto de fotos para receber o ajuste.',
                    ['oferta_id' => $oferta->id, 'escopo' => $escopo]
                );
            }

            return array_merge($vazias, ['conjunto_id' => (int) $conjunto->id]);
        }

        if ($oferta->produto_id === null) {
            throw new RegraViolada(
                self::REGRA_ANCORA_INVALIDA,
                'Este anúncio não está ligado a um cadastro de produto.',
                ['oferta_id' => $oferta->id, 'escopo' => $escopo]
            );
        }

        return array_merge($vazias, ['produto_id' => (int) $oferta->produto_id]);
    }
}
