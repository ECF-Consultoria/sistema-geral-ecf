<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * APROVAR · REABRIR · CONFERIR A ALTERAÇÃO · APROVAR EM LOTE — a escrita mais privilegiada da
 * milestone.
 *
 * ═══ ⚠️⚠️ APROVAR JÁ PUBLICA, E NÃO HÁ DESFAZER (decisão do usuário, 14/10/2026) ════════════════
 *
 * **Dois atos e janela de desfazer foram OFERECIDOS e RECUSADOS.** ➡️ Toda a reversibilidade mora
 * **ANTES** do clique: os bloqueios ({@see BloqueiosDaOfertaService}) e a `versao`. O rótulo do
 * botão **não muda** — continua o literal do protótipo, **"Aprovar para publicação"** (o botão em
 * si nasce na **Fase 188**; aqui só existe o dado).
 *
 * ⚠️ **NÃO existe nesta área** — nem método, nem estado, nem coluna, nem aviso — "confirmar
 * publicação", "segundo passo", "desfazer", "undo" ou "cancelar aprovação". Quem quiser mais trava
 * usa **bloqueio** ou **exceção registrada**. Há gate de ausência com isca (e isca invertida) em
 * `tests/Unit/Publicador/Aprovacao/BloqueiosDaOfertaServiceTest`.
 *
 * ═══ ⚠️⚠️ UMA TRANSAÇÃO SÓ, E A RAZÃO É "NÃO HÁ DESFAZER" (T-186-34) ════════════════════════════
 *
 * `aprovar()` grava, dentro de **UMA** `DB::transaction`:
 *
 *   1. o **andamento** (`live → aprovado`), pela **ÚNICA PORTA** ({@see AndamentoDaOfertaService});
 *   2. os **três campos de aprovação** (`aprovado_por`, `aprovado_em`, `versao_aprovada`) — que a
 *      própria porta grava na mesma escrita;
 *   3. o **evento `ok`** no histórico ({@see HistoricoDaOfertaService}, pelo gancho da porta);
 *   4. `tocado = true`;
 *   5. o **congelamento da composição** ({@see ComposicaoFinanceiraService::congelar()}, momento
 *      `aprovacao`).
 *
 * Falhar no meio deixaria uma oferta **aprovada sem prova do que foi aprovado** — e, como aprovar
 * publica, a publicação sairia sem rastro da versão conferida. ⚠️ A `DB::transaction` de
 * `mover()` roda **aninhada** nesta (savepoint): a atômica é a de fora, e é ela que vale.
 *
 * ═══ ⚠️⚠️ O PONTO DE EXTENSÃO DA FASE 193 É **UM SÓ**, E NÃO É AQUI ════════════════════════════
 *
 * O plano pedia um `aoAprovar()` **neste** serviço. ⚠️ **DIVERGÊNCIA DECLARADA, e é de
 * segurança:** `AndamentoDaOfertaService::aoAprovar()` **já existe**, reservado desde o plano
 * 186-04, e roda **dentro da transação** que gravou os três campos de aprovação. Criar um segundo
 * gancho com o mesmo nome e o mesmo propósito é convite para a Fase 193 preencher **os dois** — e
 * o resultado seria **publicar duas vezes** o mesmo anúncio, sem desfazer.
 *
 * ➡️ **O gancho é o da porta**, que nenhum caminho pode contornar (ela é a única que escreve
 * `andamento`). Há gate afirmando que existe **exatamente UM** `aoAprovar` em todo o diretório do
 * portão. ⚠️ E ele segue **vazio**: este plano **não enfileira nada, não publica e não fala com o
 * Mercado Livre**.
 *
 * ═══ ⚠️ BLOQ-06 — AS **8** MUDANÇAS RELEVANTES, E A REABERTURA ══════════════════════════════════
 *
 * Cada uma incrementa a `versao` em **1** e, se a oferta estava `aprovado`, **devolve a oferta para
 * `aguardando_aprovacao`** com o evento *"Aprovação reaberta: …"*. ⚠️ A transição
 * `aprovado → aguardando_aprovacao` é o **13º par** da lista fechada, acrescentado por
 * **decisão do usuário de 15/10/2026** — ver o docblock de {@see PubAprovacaoOferta::TRANSICOES}.
 *
 * ⚠️⚠️ **Quando a mudança é de FOTO, a reabertura vale para TODAS as ofertas do conjunto**
 * (`reopen(D, set, …)` do protótipo, `Aprovacao.dc.html:1149`): o conjunto é do **rascunho**, e o
 * rascunho tem os dois alvos — o compartilhamento Clássico↔Premium é **estrutural**. Preço e frete
 * reabrem **só** a oferta deles.
 *
 * ⚠️ **A propagação mora num lugar só** ({@see self::registrarMudanca()}): duplicá-la deixaria as
 * duas cópias livres para divergir, e a que divergisse deixaria uma oferta aprovada com foto
 * trocada.
 *
 * ═══ ⚠️ O QUE ESTE SERVIÇO **NÃO** FAZ ═════════════════════════════════════════════════════════
 *
 * - **Não leva a oferta a `publicado`** (AND-04): só a `aprovado`. `publicado` exige `ml_item_id`
 *   confirmado pelo ML, e quem recusa é a porta.
 * - **Não escreve `andamento`** direto: toda transição passa por `AndamentoDaOfertaService`.
 * - **Não escreve o PREÇO.** O preço efetivo é LIDO (`DadosEfetivosService` /
 *   `pub_variante_precos`), e quem o grava é o editor. {@see self::registrarPrecoSalvo()} registra
 *   a **consequência** do preço ter sido salvo (versão + reabertura + histórico), que é a parte
 *   que pertence ao portão.
 * - **Não toca** `app/Services/Mlb/Publicacao/MlPublicacaoService.php` (PUB-03) nem
 *   `App\Support\Publicador` (TERR-03).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AprovacaoDaOfertaService
{
    /** BLOQ-04 — aprovar com bloqueio em aberto. Nada é gravado. */
    public const REGRA_COM_BLOQUEIO = 'BLOQ-04-APROVAR-COM-BLOQUEIO';

    /** BLOQ-04 / AND-05 — aprovar fora dos três estados ao vivo. */
    public const REGRA_FORA_DO_AO_VIVO = 'BLOQ-04-APROVAR-FORA-DO-AO-VIVO';

    /** COL-04 — a `versao` que o cliente mandou não é a atual. ⚠️ **Nunca sobrescreve em silêncio.** */
    public const REGRA_VERSAO_DIVERGENTE = 'COL-04-VERSAO-DIVERGENTE';

    /** BLOQ-06 — a chave de mudança não é uma das 8 declaradas. Lista fechada: default = recusa. */
    public const REGRA_MUDANCA_DESCONHECIDA = 'BLOQ-06-MUDANCA-DESCONHECIDA';

    // ─── BLOQ-06: as 8 mudanças relevantes (README §D, na ordem dele) ────────────────────────

    public const FOTO_REPROVADA = 'foto_reprovada';

    public const FOTO_SUBSTITUIDA = 'foto_substituida';

    public const FOTO_REGENERADA = 'foto_regenerada';

    public const FOTO_ADICIONADA = 'foto_adicionada';

    public const FOTOS_REORDENADAS = 'fotos_reordenadas';

    public const PRINCIPAL_ALTERADA = 'principal_alterada';

    public const PRECO_SALVO = 'preco_salvo';

    public const FRETE_CONFIRMADO = 'frete_confirmado';

    /** A mudança alcança o conjunto de fotos (⇒ **todas** as ofertas do rascunho). */
    public const ESCOPO_CONJUNTO = 'conjunto';

    /** A mudança é só desta oferta (preço e frete são por anúncio). */
    public const ESCOPO_OFERTA = 'oferta';

    /**
     * ⚠️⚠️ **AS 8 MUDANÇAS, LISTA FECHADA** — chave => escopo + a frase que entra em
     * *"Aprovação reaberta: {frase}"*.
     *
     * As **seis** primeiras são de FOTO e reabrem **todas** as ofertas do conjunto; as **duas**
     * últimas são da oferta. As frases são as do `reopen()` do protótipo
     * (`Aprovacao.dc.html:1152`, `:1153`, `:1154`, `:1155`, `:1156`, `:1163`), medidas por leitura.
     *
     * **Por que lista fechada e não um laço de proibições:** o que entra aqui **reabre a
     * aprovação**; o que *não* entra deixa uma oferta aprovada com a coisa trocada. Numa máquina
     * cujo esquecimento publica anúncio não conferido, o default tem de ser "recusa".
     */
    public const MUDANCAS = [
        self::FOTO_REPROVADA => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'foto reprovada'],
        self::FOTO_SUBSTITUIDA => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'foto substituída por original'],
        self::FOTO_REGENERADA => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'imagem regenerada'],
        self::FOTO_ADICIONADA => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'foto adicionada'],
        self::FOTOS_REORDENADAS => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'ordem da galeria alterada'],
        self::PRINCIPAL_ALTERADA => ['escopo' => self::ESCOPO_CONJUNTO, 'frase' => 'foto principal alterada'],
        self::PRECO_SALVO => ['escopo' => self::ESCOPO_OFERTA, 'frase' => 'preço alterado na revisão'],
        self::FRETE_CONFIRMADO => ['escopo' => self::ESCOPO_OFERTA, 'frase' => 'frete do vendedor confirmado'],
    ];

    /** As **seis** de foto — as que `IMG-06` manda reabrir no conjunto inteiro. */
    public const MUDANCAS_DE_FOTO = [
        self::FOTO_REPROVADA,
        self::FOTO_SUBSTITUIDA,
        self::FOTO_REGENERADA,
        self::FOTO_ADICIONADA,
        self::FOTOS_REORDENADAS,
        self::PRINCIPAL_ALTERADA,
    ];

    /** O prefixo do evento de reabertura (`:1149`), com os dois-pontos e o espaço. */
    public const REABERTA_PREFIXO = 'Aprovação reaberta: ';

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    public function __construct(
        private AndamentoDaOfertaService $andamento,
        private HistoricoDaOfertaService $historico,
        private BloqueiosDaOfertaService $bloqueios,
        private ComposicaoFinanceiraService $composicao,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // APROVAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **APROVA PARA PUBLICAÇÃO — e isto JÁ PUBLICA.** Sem segundo clique e sem desfazer.
     *
     * @param  int|null  $versaoEsperada  COL-04: a `versao` que a tela tinha na mão. ⚠️ Divergente
     *                                    = **RECUSA**, nunca sobrescrita em silêncio.
     *
     * @throws RegraViolada versão divergente (COL-04), fora de `live` ou com bloqueio (BLOQ-04)
     */
    public function aprovar(PubAprovacaoOferta $oferta, User $quem, ?int $versaoEsperada = null): PubAprovacaoOferta
    {
        $this->exigirVersao($oferta, $versaoEsperada);

        // ⚠️ RECUSA 1 — AND-05. Fora dos três ao vivo não há o que aprovar: `aprovado` já foi,
        // `publicando`/`publicado` já saíram, `preparando` ainda não existe e `falha` é retry.
        if (! $oferta->ehLive()) {
            throw new RegraViolada(
                self::REGRA_FORA_DO_AO_VIVO,
                'Esta oferta não está aberta para aprovação.',
                ['oferta_id' => $oferta->id, 'andamento_atual' => $oferta->andamento]
            );
        }

        // ⚠️ RECUSA 2 — BLOQ-04, conferida **NO SERVIDOR**. O botão `disabled=` da Fase 188 é
        // conveniência de tela; a trava é esta. Com aprovar-já-publica e sem desfazer, confiar no
        // cliente seria elevação de privilégio (T-186-32).
        $info = $this->bloqueios->para($oferta);
        if (! $info['aprovavel']) {
            throw new RegraViolada(
                self::REGRA_COM_BLOQUEIO,
                'Esta oferta tem pendência em aberto: '.$info['resumo'],
                ['oferta_id' => $oferta->id, 'bloqueios' => $info['chaves']]
            );
        }

        $comExcecao = PubAprovacaoExcecao::vigentesDa((int) $oferta->id)->exists();

        // ⚠️⚠️ A TRANSAÇÃO ÚNICA (T-186-34). Ver o docblock da classe.
        DB::transaction(function () use ($oferta, $quem) {
            // 1-3: andamento + os três campos de aprovação + o evento `ok`, pela ÚNICA PORTA.
            $this->andamento->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);

            // 4: o `touched` do protótipo (`:1166`), que alimenta o "PRÓXIMO A REVISAR".
            $oferta->update(['tocado' => true]);

            // 5: ⚠️ O CONGELAMENTO. É ele que torna "auditoria DEPOIS" verdade: sem a composição
            // da época (com a margem mínima e de qual lado do fallback ela veio), ninguém
            // reconstrói a decisão — e a config pode ter mudado (DADO-13 / T-186-29).
            $this->composicao->congelar($oferta->refresh(), PubAprovacaoComposicao::APROVACAO);
        });

        Log::info(self::TAG.' oferta aprovada para publicação', [
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku.' ('.$oferta->produto?->nome.')',
            'alvo_id' => $oferta->alvo_id,
            'quem_id' => $quem->id,
            'versao_aprovada' => $oferta->refresh()->versao_aprovada,
            'com_excecao' => $comExcecao,
        ]);

        return $oferta->refresh();
    }

    /**
     * **BLOQ-10** — o lote: devolve o veredicto de **todas** as selecionadas e aprova **só as
     * aptas, cada uma na SUA versão**.
     *
     * ⚠️ As inaptas saem com o **motivo**, porque o modal lista `✓` nas aptas e `×` nas de fora
     * **com a razão**: um lote que esconde o motivo manda a pessoa clicar de novo sem saber o que
     * consertar.
     * ⚠️ **Uma leitura de composição por oferta, fora de laço aninhado** (T-186-38): o
     * `BloqueiosDaOfertaService` faz a dela por oferta e a tarifa tem cache de 1 h.
     * ⚠️ Cada aprovação é sua própria transação: uma oferta que falhe **não** desfaz as outras já
     * aprovadas — elas já publicaram.
     *
     * @param  iterable<PubAprovacaoOferta>  $ofertas
     * @return list<array{oferta_id: int, apta: bool, versao: int, aprovada: bool, motivo: string|null, bloqueios: list<string>}>
     */
    public function aprovarEmLote(iterable $ofertas, User $quem): array
    {
        $veredicto = [];

        foreach ($ofertas as $oferta) {
            $versao = (int) $oferta->versao;

            if (! $oferta->ehLive()) {
                $veredicto[] = [
                    'oferta_id' => (int) $oferta->id,
                    'apta' => false,
                    'versao' => $versao,
                    'aprovada' => false,
                    'motivo' => 'Esta oferta não está aberta para aprovação ('.($oferta->rotulo() ?? '—').').',
                    'bloqueios' => [],
                ];

                continue;
            }

            $info = $this->bloqueios->para($oferta);

            if (! $info['aprovavel']) {
                $veredicto[] = [
                    'oferta_id' => (int) $oferta->id,
                    'apta' => false,
                    'versao' => $versao,
                    'aprovada' => false,
                    'motivo' => $info['resumo'],
                    'bloqueios' => $info['chaves'],
                ];

                continue;
            }

            // ⚠️ Aprovada **na versão indicada**: se alguém mexeu entre a montagem do modal e o
            // clique, a `versao` mudou e `aprovar()` recusa (COL-04).
            $this->aprovar($oferta, $quem, $versao);

            $veredicto[] = [
                'oferta_id' => (int) $oferta->id,
                'apta' => true,
                'versao' => $versao,
                'aprovada' => true,
                'motivo' => null,
                'bloqueios' => [],
            ];
        }

        Log::info(self::TAG.' lote de aprovação', [
            'quem_id' => $quem->id,
            'selecionadas' => count($veredicto),
            'aprovadas' => count(array_filter($veredicto, fn (array $v) => $v['aprovada'])),
        ]);

        return $veredicto;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // BLOQ-07 — CONFERIR A ALTERAÇÃO DE PREÇO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **BLOQ-07 — "Conferir alteração" NÃO ALTERA PREÇO.** Ela limpa o aviso e registra quem
     * conferiu, em que versão (`checkChange`, `Aprovacao.dc.html:1170`).
     *
     * ⚠️ O nome engana se não estiver escrito: a ação **não aplica** o preço novo nem volta ao
     * antigo — o preço é o que está salvo. Ela só diz *"eu vi que mudou"*, e é isso que faz o
     * bloqueio `alteracao_nao_conferida` sair.
     */
    public function conferirAlteracao(PubAprovacaoOferta $oferta, User $quem): PubAprovacaoOferta
    {
        $versao = (int) $oferta->versao;

        DB::transaction(function () use ($oferta, $quem, $versao) {
            // ⚠️ Só o AVISO e a data da conferência. Nada de preço — ele não é coluna daqui.
            $oferta->update(['preco_alterado' => null, 'preco_conferido_em' => now()]);

            $this->historico->registrar(
                $oferta,
                $quem,
                PubAprovacaoEvento::APROVACAO,
                sprintf('Conferiu a alteração de preço da versão %d', $versao),
                $versao
            );
        });

        Log::info(self::TAG.' alteração de preço conferida', [
            'oferta_id' => $oferta->id,
            'quem_id' => $quem->id,
            'versao' => $versao,
        ]);

        return $oferta->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AND-06 — SALVAR REVISÃO PARCIAL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **AND-06 / COL-01** — salva a revisão parcial: move `aguardando_aprovacao → em_revisao_ecf`
     * e registra *"Salvou revisão · x de y fotos aprovadas"* (`saveReview`, `:1165`).
     *
     * ⚠️ **A revisão parcial está no BANCO**, não em `localStorage` (COL-02): outra pessoa
     * continua de onde esta parou, e reler a oferta em outra instância devolve o mesmo estado.
     * ⚠️ A contagem *x de y* vem de {@see BloqueiosDaOfertaService::contagemDeFotos()}, a MESMA
     * definição de "galeria final" do bloqueio de fotos — duas contagens divergiriam e a tela
     * diria "5 de 5" com bloqueio de pé.
     * ⚠️ Em `em_revisao_ecf` ou `ajustes_solicitados` a oferta **não se move** (o protótipo só
     * promove a partir de `awaiting`), mas o evento é registrado: salvar de novo é fato novo.
     */
    public function salvarRevisao(PubAprovacaoOferta $oferta, User $quem): PubAprovacaoOferta
    {
        $contagem = $this->bloqueios->contagemDeFotos($oferta);
        $versao = (int) $oferta->versao;

        DB::transaction(function () use ($oferta, $quem, $contagem, $versao) {
            $oferta->update(['tocado' => true]);

            if ($oferta->andamento === PubAprovacaoOferta::AGUARDANDO_APROVACAO) {
                $this->andamento->mover($oferta, PubAprovacaoOferta::EM_REVISAO_ECF, $quem);
            }

            $this->historico->registrar(
                $oferta,
                $quem,
                PubAprovacaoEvento::REVISAO_PARCIAL,
                sprintf('Salvou revisão · %d de %d fotos aprovadas', $contagem['aprovadas'], $contagem['total']),
                $versao
            );
        });

        return $oferta->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // BLOQ-11 — "TENTAR DE NOVO"
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **BLOQ-11 / AND-10** — `falha → publicando`, **sem mexer na `versao`** e **preservando**
     * `versao_aprovada`, `aprovado_por` e `aprovado_em`.
     *
     * ⚠️ Incrementar a versão aqui **reabriria uma aprovação em que ninguém mexeu**: a revisão
     * aprovada é exatamente a que vai ser retentada. A tela do protótipo diz isso com todas as
     * letras (*"versão aprovada N preservada"*, `:1297`).
     */
    public function tentarDeNovo(PubAprovacaoOferta $oferta, ?User $quem = null): PubAprovacaoOferta
    {
        return $this->andamento->mover($oferta, PubAprovacaoOferta::PUBLICANDO, $quem);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ BLOQ-06 — AS 8 MUDANÇAS RELEVANTES E A REABERTURA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A ÚNICA ROTINA DE REABERTURA.** Incrementa a `versao` e, se a oferta estava
     * `aprovado`, devolve-a para `aguardando_aprovacao` com o evento *"Aprovação reaberta: …"*.
     *
     * **Mudança de FOTO alcança TODAS as ofertas do conjunto** (as 6 primeiras de
     * {@see self::MUDANCAS}); preço e frete alcançam **só** a oferta.
     *
     * ⚠️ `$versaoJaIncrementada` existe por um motivo MEDIDO, não por estilo:
     * {@see PubAprovacaoFrete::informar()} (plano 186-06) **já incrementa a `versao`** dentro da
     * própria transação, junto de derrubar o frete anterior. Incrementar de novo aqui faria a
     * confirmação de frete pular **duas** versões, e `versao_aprovada` passaria a ficar duas atrás
     * — a tela diria "reaberta" duas vezes pelo mesmo ato. ➡️ `confirmarFrete()` passa `true`, e
     * **todas as 8 mudanças continuam atravessando este método**, que é o que mantém a propagação
     * num lugar só.
     *
     * @param  string  $mudanca  uma das 8 chaves de {@see self::MUDANCAS}
     * @param  string  $detalhe  o complemento da frase (ex.: o rótulo da foto), opcional
     * @return Collection<int, PubAprovacaoOferta> as ofertas alcançadas, já recarregadas
     *
     * @throws RegraViolada chave fora das 8 declaradas
     */
    public function registrarMudanca(
        PubAprovacaoOferta $oferta,
        string $mudanca,
        ?User $quem = null,
        string $detalhe = '',
        bool $versaoJaIncrementada = false,
    ): Collection {
        if (! array_key_exists($mudanca, self::MUDANCAS)) {
            throw new RegraViolada(
                self::REGRA_MUDANCA_DESCONHECIDA,
                sprintf('"%s" não é uma mudança relevante declarada.', $mudanca),
                ['oferta_id' => $oferta->id, 'mudanca' => $mudanca]
            );
        }

        $spec = self::MUDANCAS[$mudanca];
        $frase = self::REABERTA_PREFIXO.$spec['frase'].($detalhe === '' ? '' : ' · '.$detalhe);

        $alvos = $spec['escopo'] === self::ESCOPO_CONJUNTO
            ? $this->ofertasDoConjunto($oferta)
            : new Collection([$oferta]);

        DB::transaction(function () use ($alvos, $frase, $quem, $versaoJaIncrementada) {
            foreach ($alvos as $alvo) {
                if (! $versaoJaIncrementada) {
                    $alvo->increment('versao');
                }

                $alvo->refresh();
                $versao = (int) $alvo->versao;

                // ⚠️ A reabertura só acontece em quem ESTAVA aprovado. Oferta ao vivo já está na
                // fila de conferência: mover de `aguardando_aprovacao` para ele mesmo não é
                // transição declarada, e não haveria o que reabrir.
                if ($alvo->andamento !== PubAprovacaoOferta::APROVADO) {
                    continue;
                }

                // ⚠️ Pela ÚNICA PORTA, no 13º par (`aprovado → aguardando_aprovacao`), que existe
                // por decisão do usuário de 15/10/2026. A oferta volta a ser `live` — logo
                // EDITÁVEL —, que é o ponto: a foto que acabou de mudar precisa poder ser
                // conferida.
                $this->andamento->mover($alvo, PubAprovacaoOferta::AGUARDANDO_APROVACAO, $quem);

                // ⚠️ E o registro com a FRASE do protótipo. Sem ele, o histórico não explicaria
                // por que a oferta saiu de "Aprovado" — e é esse registro que torna a reabertura
                // auditável depois.
                $this->historico->registrar(
                    $alvo,
                    $quem,
                    PubAprovacaoEvento::ALTERACAO,
                    sprintf('%s (versão %d)', $frase, $versao),
                    $versao
                );
            }
        });

        Log::info(self::TAG.' mudança relevante registrada', [
            'oferta_id' => $oferta->id,
            'mudanca' => $mudanca,
            'escopo' => $spec['escopo'],
            'alcancou' => $alvos->pluck('id')->all(),
            'quem_id' => $quem?->id,
        ]);

        return $alvos->map(fn (PubAprovacaoOferta $o) => $o->refresh());
    }

    /**
     * **MOD-03** — confirma o frete do vendedor e reabre **só esta oferta**.
     *
     * ⚠️ O incremento da `versao` é de {@see PubAprovacaoFrete::informar()} (plano 186-06), que o
     * faz na mesma transação em que derruba o frete anterior. Ver `$versaoJaIncrementada` em
     * {@see self::registrarMudanca()}.
     */
    public function confirmarFrete(PubAprovacaoOferta $oferta, float $valor, string $origem, ?User $quem = null): PubAprovacaoOferta
    {
        return DB::transaction(function () use ($oferta, $valor, $origem, $quem) {
            PubAprovacaoFrete::informar($oferta, $valor, $origem, $quem);

            $this->registrarMudanca(
                $oferta->refresh(),
                self::FRETE_CONFIRMADO,
                $quem,
                PubAprovacaoFrete::ORIGENS[$origem] ?? $origem,
                versaoJaIncrementada: true,
            );

            return $oferta->refresh();
        });
    }

    /**
     * O preço foi salvo na revisão: **registra a consequência** (versão + reabertura + histórico)
     * e limpa o aviso de alteração.
     *
     * ⚠️ **Não grava o preço**, e isso é território: o preço efetivo é LIDO
     * (`DadosEfetivosService` / `pub_variante_precos`) e quem o escreve é o editor, que esta fase
     * **não toca**. O que pertence ao portão é a consequência — e é ela que está aqui.
     */
    public function registrarPrecoSalvo(PubAprovacaoOferta $oferta, float $de, float $para, ?User $quem = null): PubAprovacaoOferta
    {
        DB::transaction(function () use ($oferta, $de, $para, $quem) {
            // O aviso de "preço alterado" deixa de existir: a mudança foi deliberada, feita aqui.
            $oferta->update(['preco_alterado' => null]);

            $this->registrarMudanca(
                $oferta->refresh(),
                self::PRECO_SALVO,
                $quem,
                sprintf('de %s para %s', self::brl($de), self::brl($para)),
            );
        });

        return $oferta->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **COL-04** — a `versao` informada tem de ser a atual.
     *
     * Sem esta recusa, o fluxo seria: alguém abre a revisão, outra pessoa troca uma foto (a versão
     * sobe e a aprovação reabre), e o primeiro clica em "Aprovar para publicação" com a tela
     * velha — **aprovando o que não está mais lá**. E aprovar publica, sem desfazer (T-186-33).
     *
     * ⚠️ `null` = quem chamou não informou versão (uso interno/console). A tela **informa**.
     *
     * @throws RegraViolada quando a versão informada não é a atual
     */
    private function exigirVersao(PubAprovacaoOferta $oferta, ?int $versaoEsperada): void
    {
        if ($versaoEsperada === null || $versaoEsperada === (int) $oferta->versao) {
            return;
        }

        throw new RegraViolada(
            self::REGRA_VERSAO_DIVERGENTE,
            sprintf(
                'Esta oferta mudou enquanto você revisava (você viu a versão %d, a atual é %d). Confira de novo antes de aprovar.',
                $versaoEsperada,
                (int) $oferta->versao
            ),
            ['oferta_id' => $oferta->id, 'versao_vista' => $versaoEsperada, 'versao_atual' => (int) $oferta->versao]
        );
    }

    /**
     * **TODAS** as ofertas do conjunto de fotos — isto é, as do **rascunho**.
     *
     * ⚠️ A travessia é por `rascunho_id`, e não por uma FK de conjunto na oferta (que **não
     * existe**, plano 186-04): o conjunto é do rascunho, e o rascunho tem os dois alvos. É o que
     * torna o compartilhamento Clássico↔Premium **estrutural** — não há coluna onde as duas
     * pudessem divergir.
     *
     * @return Collection<int, PubAprovacaoOferta>
     */
    private function ofertasDoConjunto(PubAprovacaoOferta $oferta): Collection
    {
        return PubAprovacaoOferta::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->orderBy('id')
            ->get();
    }

    /** `R$ 749,90`, na forma do `brl()` do protótipo (`:1044`) para valores positivos. */
    private static function brl(float $n): string
    {
        return 'R$ '.number_format($n, 2, ',', '.');
    }
}
