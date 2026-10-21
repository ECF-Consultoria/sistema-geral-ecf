<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O PORTÃO → A FILA DE PUBLICAÇÃO. **Aprovar enfileira na hora** (Fase 193, plano 02).
 *
 * ⚠️⚠️ **APROVAR JÁ PUBLICA** — decisão do usuário de 14/10/2026, com as alternativas oferecidas e
 * RECUSADAS: publicar em dois atos, publicar com janela para voltar atrás, botão separado de
 * publicar. ➡️ Por isso este serviço é chamado pelo gancho `AndamentoDaOfertaService::aoAprovar()`,
 * **dentro da mesma transação** que gravou `aprovado_por`/`aprovado_em`/`versao_aprovada`, e **não**
 * por uma rota, uma action, um estado intermediário ou um rótulo de confirmação.
 *
 * ⚠️⚠️ **AS NOVE FORMAS PROIBIDAS, CITADAS AQUI DE PROPÓSITO — É A ISCA DO LEITOR.** Nenhuma delas
 * existe no código deste arquivo, e elas estão escritas NESTE COMENTÁRIO para que o gate de ausência
 * tenha de provar que lê o código **sem comentário** (`token_get_all`, nunca regex sobre o texto
 * cru): `confirmar_publicacao`, `confirmarPublicacao`, `segundo_passo`, `segundoPasso`,
 * `desfazerAprovacao`, `JANELA_DE_UNDO`, `publicar_aprovado`, `publicarAprovado`,
 * `cancelar_aprovacao`, `cancelarAprovacao`, mais as formas de prosa *"confirmar publicação"*,
 * *"segundo passo"*, *"desfazer aprovação"* e *"cancelar aprovação"*. ⚠️ Um gate que lesse os
 * comentários **reprovaria pelo próprio conserto** — foi o que abortou TRÊS mutações da wave 187-04.
 *
 * ⚠️ **E A FORMA DA SONDA FOI MEDIDA, não copiada:** a `sondaPub09` do gate 186-09 pega as formas
 * `snake_case`/`camelCase` e o `undo` com borda à esquerda, mas **NÃO** pega a forma pt-BR acentuada
 * com espaço (*"confirmar publicação"*); a sonda do 188-05 pega a acentuada e **não** pega `undo`
 * nem `publicar_aprovado`. ➡️ O gate desta fase usa a **UNIÃO** das duas, que é estritamente mais
 * forte que qualquer uma — e por isso as duas famílias de forma estão citadas acima.
 *
 * ═══ ⚠️ O QUE ESTE SERVIÇO **NÃO** FAZ, E POR QUÊ ══════════════════════════════════════════════
 *
 * - **Nunca escreve `andamento`.** Quem move o estado é a ÚNICA PORTA
 *   ({@see AndamentoDaOfertaService::mover()}), e escrita de estado espalhada aqui seria elevação
 *   de privilégio (T-193-11): aprovar publica, e não há volta. ⚠️ Este arquivo também **não contém
 *   a chave literal de andamento em array nenhum**, nem de saída — a sonda do gate
 *   `andamento-um-lugar-so` (186-09) casa aquela chave em **qualquer** array, e dois expositores
 *   legítimos já precisaram de exceção PROVADA por causa disso. A saída certa aqui não é entrar na
 *   lista de expositores nem renomear chave para fugir do gate (a régua do projeto é **um
 *   vocabulário só**): é simplesmente **não expor andamento**.
 * - **Nenhum `dispatch()`.** Ele roda **dentro de transação**, e job despachado antes do commit
 *   roda sem ver os dados (T-193-16). Quem publica é o `AgendadorDaFila`, na passada do minuto —
 *   pela fila `high`, que já é assim e **não muda**.
 * - **Não reimplementa a fila.** PUB-02 manda REUSAR, e o caminho reusado é
 *   `FilaPublicacaoService::agendar()` → `publicador:fila-publicacao` → `PublicacaoService` →
 *   `PublicarRascunhoJob`. ⚠️ `MlPublicacaoService` **não está neste caminho** (ele serve o
 *   assistente antigo, sobre `MlAnuncioRascunho`) e sai desta fase com diff ZERO.
 * - **Não copia as 4 colunas de conferência** (`validacao_id`, `plano_hash`, `revisao`, `ciente`).
 *   Elas moram no ITEM da fila e são LIDAS por `fila_item_id` (DADO-03). Copiar criaria duas
 *   verdades livres para divergir (T-193-14). ⚠️ `precisa_revisar` não é coluna em lugar nenhum —
 *   é **valor** do `status` do item.
 * - **Não cria feature-flag de "ligar publicar".** A trava é `ContasLiberadas` (fail-closed, lista
 *   vazia = ninguém), exigida por `PublicacaoService::iniciar()` e já conferida pela prontidão da
 *   fila. Um segundo interruptor faria **duas verdades** sobre quem pode publicar.
 *
 * ═══ ⚠️⚠️ A GRANULARIDADE: N OFERTAS → **UM** ITEM DE FILA POR PRODUTO ═════════════════════════
 *
 * A fila publica o **produto inteiro** (Clássico + Premium, todas as cores, numa publicação só), e
 * um produto está em UMA fila por vez — o unique `pubfilai_produto_ativo_uq` sobre `produto_ativo`.
 * ➡️ Quando a segunda oferta do mesmo produto é aprovada, este serviço **ANEXA** ao item que já
 * vive em vez de agendar de novo: as duas ofertas apontam para o MESMO `fila_item_id`, e isso é
 * correto (é o que o docblock de `PubAprovacaoOferta::filaItem()` já declarava).
 *
 * ⚠️ **E não é só elegância: agendar de novo seria RECUSADO.** Medido em
 * `ResumoRapidoService::prontidaoDoLote()`: a PRIMEIRA recusa é *"Já está na fila de publicação."*
 * ➡️ Sem o anexo, a segunda oferta aprovada ficaria para sempre sem ligação com a fila que está
 * publicando o produto dela.
 *
 * ═══ ⚠️⚠️ A OFERTA QUE A FILA RECUSA FICA EM `aprovado`, COM O MOTIVO REAL ══════════════════════
 *
 * `agendar()` só aceita produto **pronto**, e `prontidaoDoLote()` tem **11** motivos de recusa, cada
 * um com frase pt-BR própria. ⚠️ **Nenhum dos 8 bloqueios do portão cobre a conferência L3 com o
 * Mercado Livre**, então existe uma janela real: aprovar pode suceder e o enfileiramento ser
 * recusado por *"O produto mudou depois da conferência: confira de novo."*
 *
 * ➡️ **A saída escolhida (decisão 21 do usuário, opção (a)):** a oferta **continua em `aprovado`**,
 * cujo rótulo do protótipo é *"aguardando publicação"* — que é **a verdade** —, o **motivo REAL** da
 * fila vai para o histórico (DADO-10, já visível no painel), e o reconciliador da 193-03/04 tenta
 * de novo a cada minuto. ⚠️ **Nada de estado novo, nada de coluna nova, nada de "algo deu errado".**
 * A alternativa (c), `aprovado → publicando → falha` imediato, foi recusada no planejamento: ela
 * passaria por `publicando` **sem nada em voo** — um estado que mente, numa máquina em que
 * `publicando` bloqueia edição. A alternativa (b), um 9º bloqueio no portão, foi **oferecida ao
 * usuário e recusada**: ela exigiria editar `VocabularioDoPortao` e `BloqueiosDaOfertaService`,
 * serviços da Fase 186 **em produção**.
 *
 * ⚠️ O evento da recusa entra como **ator Sistema** (`autor_id` nulo + `ator_sistema`), e não como
 * ato da pessoa: quem recusou foi a fila, não quem aprovou. Quem aprovou já está registrado no
 * evento de aprovação imediatamente anterior. E o tipo é `sys`, **não** `error`: o rótulo de
 * `error` na tela é *"Falha na publicação"*, e nada falhou ao publicar — a oferta nem foi para a
 * fila ainda. Usar `error` aqui faria o filtro por tipo da linha do tempo mentir.
 *
 * ═══ ⚠️⚠️⚠️ A GUARDA DA MODALIDADE IRMÃ — **DECISÃO 23 DO USUÁRIO** (`PERGUNTA-193-F`) ═════════
 *
 * **NÃO SE PUBLICA O QUE NINGUÉM REVISOU.** Sem **todos** os alvos ATIVOS aprovados, a oferta
 * **não entra na fila**.
 *
 * ⚠️ **O DEFEITO QUE ELA FECHA, MEDIDO EM TRÊS PONTOS (não deduzido):** a fila publica o
 * **PRODUTO**, e `app/Support/Publicador/Payload/PayloadBuilderUserProducts.php:60` monta um
 * anúncio para **cada alvo ATIVO** × cada variante ativa (`alvosAtivos()` × `variantesAtivas()`);
 * `alvosAtivos()` filtra por `Alvo::$ativo`; e `pub_rascunho_alvos.ativo` é boolean **default
 * true** (`2026_10_01_200000_create_publicador_tables.php:67`). ➡️ Com Clássico e Premium os dois
 * ativos, aprovar **só o Clássico** e enfileirar o produto levaria o **Premium ao ar sem revisão**.
 *
 * ⚠️ É a MESMA régua da **decisão 5** do usuário, um nível acima: lá o bloqueio de fotos cobre
 * **TODOS** os grupos do rascunho e aprovação cor por cor foi **oferecida e RECUSADA**, porque
 * aprovar publica e não há volta. Aqui é a modalidade, e a conclusão é a mesma.
 *
 * ⚠️ **O COMPORTAMENTO É O MESMO MECANISMO DA DECISÃO 21 — nada de novo é inventado:** a oferta
 * fica em **`aprovado`**, **SEM `fila_item_id`**; o **motivo REAL** vai ao histórico **nomeando a
 * modalidade que falta**; o **reconciliador reavalia**, e quando a irmã for aprovada as duas saem
 * juntas. ⚠️ **Nenhum estado novo, nenhuma coluna nova, nenhum 7º toast** (os 6 de VOC-08 são
 * fechados) e **nenhum** dos quatro literais proibidos: o rótulo do protótipo para `aprovado` é
 * *"aguardando publicação"*, que **já é a verdade** deste caso.
 *
 * ⚠️ **A ALTERNATIVA DE PUBLICAR SÓ A MODALIDADE APROVADA FOI OFERECIDA E RECUSADA:** ela exigiria
 * mexer em `PayloadBuilder`/`PublicacaoService`, que esta milestone reusa **INTOCADOS** de propósito
 * e que são **compartilhados com o assistente antigo** — risco de regressão fora do portão.
 *
 * ⚠️ **A ÂNCORA É `pub_rascunho_alvos.ativo`, nunca uma contagem fixa de alvos** (o número de alvos
 * muda por produto): ver {@see self::modalidadesAtivasNaoAprovadas()}. ➡️ Produto com **UM** alvo
 * ativo — o único que o agente encaminha hoje, por `ElegibilidadeDoAgente` — **continua enfileirando
 * normalmente**, porque a lista de pendentes vem vazia. Há caso de teste nas **duas** direções.
 *
 * ⚠️ **O risco NÃO vem do agente** (`PreparadorDaAprovacao` só encaminha produto com **UM** alvo
 * ativo): ele vem de alguém **ativar a outra modalidade no editor depois** que a primeira foi
 * aprovada.
 *
 * ⚠️⚠️ **O QUE ESTA GUARDA NÃO FECHA, DECLARADO:** ela protege o instante de **ENTRAR** na fila.
 * Se a segunda modalidade for ativada no editor **depois** de o item já estar vivo, o item em voo
 * publica os dois — e aí não há o que recusar, porque a publicação já começou. Fechar essa janela é
 * assunto da rotina de mudanças relevantes (plano **186-08**), que incrementa a `versao` e **reabre**
 * a aprovação, e **não** desta fase.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PublicacaoDoPortaoService
{
    /** O produto da oferta não está mais no banco (apagado entre o clique e o gancho). */
    public const MOTIVO_SEM_PRODUTO = 'O produto desta oferta não foi encontrado.';

    /**
     * A âncora de conta do produto não resolve: sem token ativo, empresa arquivada ou fora do
     * Publicador. ⚠️ É a MESMA pergunta que `PubProduto::contaOuNula()` responde — não há segunda
     * régua de "de que conta é este produto" nesta fase.
     */
    public const MOTIVO_SEM_CONTA = 'A conta do Mercado Livre deste produto precisa ser reconectada.';

    /**
     * Não há a quem atribuir o agendamento. ⚠️ `FilaPublicacaoService::agendar()` exige um `User`
     * (é ele que vira `criada_por` da fila, e é em nome dele que as publicações saem — a fila não
     * anda sem autor válido), então sem autor não há o que enfileirar.
     */
    public const MOTIVO_SEM_AUTOR = 'A aprovação não registrou quem aprovou, e a fila publica em nome de alguém.';

    /** O prefixo do evento de histórico da recusa — o MOTIVO REAL vem concatenado depois dele. */
    public const PREFIXO_NAO_ENFILEIRADA = 'Ainda não foi para a fila de publicação: ';

    /**
     * ⚠️ O que vai no `ciente` do item de fila, e a razão é MEDIDA, não preferência.
     *
     * Em `agendar()`, `ciente` significa *"quem agendou está ciente dos AVISOS que o Mercado Livre
     * deu na conferência"*: sem ele, produto com `avisos_ml` é **recusado** com *"marque Estou
     * ciente para agendar"*, e o item guarda `ciente = $ciente && $l['avisos_ml']` — ou seja, a
     * coluna só fica verdadeira quando houve aviso de verdade.
     *
     * ⚠️⚠️ **Passar `false` aqui criaria um laço sem saída, e isso é o custo medido:** no portão
     * **não existe** caixa "Estou ciente" (ela é da tela de lote), então a oferta aprovada de um
     * produto com avisos ficaria parada para sempre, com um motivo que **ninguém pode satisfazer
     * pela tela do portão** — e o reconciliador tentaria de novo a cada minuto, para sempre. É
     * exatamente a "pilha de aprovados que ninguém publicou" que T-193-15 existe para evitar.
     *
     * ➡️ Fica `true`, e o significado é honesto: **o clique humano em "Aprovar para publicação"
     * É a ciência**. Quem aprovou leu a oferta inteira, sabe que aprovar publica e que não há
     * volta — é a conferência mais forte do fluxo, não a mais fraca. O registro no item continua
     * dizendo a verdade (`ciente` verdadeiro **apenas** onde houve aviso).
     *
     * ⚠️ **DEVOLVIDO AO USUÁRIO como PERGUNTA-193-E**, porque é semântica de tela e não de código:
     * se ele quiser que aviso do ML barre a aprovação, isso é um **9º bloqueio no portão** — a
     * opção (b) da PERGUNTA-193-A, que ele já recusou uma vez, e que mexe em serviço da Fase 186.
     */
    public const CIENTE_DO_CLIQUE_HUMANO = true;

    /**
     * O motivo da guarda da **decisão 23** (`PERGUNTA-193-F`) — o prefixo. A **modalidade que
     * falta** é nomeada depois dele, por {@see self::motivoDaIrmaNaoAprovada()}.
     *
     * ⚠️ Em pt-BR e **sem jargão**: o nome da modalidade sai de `PubAprovacaoAjuste::MODALIDADES`,
     * o mapa que o **próprio painel do portão já usa** (`PainelDeRevisaoService`), e nunca o
     * `listing_type_id` cru — `gold_pro` na tela seria jargão sem explicação.
     */
    public const MOTIVO_IRMA_NAO_APROVADA = 'falta aprovar a outra modalidade deste produto, que está ativa e iria ao ar junto — ';

    /**
     * Os andamentos em que a oferta **já passou pelo portão**: irmã nestes estados **não** barra o
     * enfileiramento, porque alguém já a conferiu.
     *
     * ⚠️ Lista **por significado, não por exclusão**. `falha` fica **FORA de propósito**: ela já foi
     * aprovada uma vez (`mover(FALHA)` preserva `versao_aprovada`/`aprovado_por`/`aprovado_em`), mas
     * quem está em `falha` **não está indo ao ar agora** — e "Tentar de novo" é o caminho próprio
     * dela (193-04). Incluí-la aqui faria uma oferta que falhou arrastar a irmã para a fila sem a
     * irmã ter sido reconferida. ⚠️ `aguardando_aprovacao`, `em_revisao_ecf`, `ajustes_solicitados`
     * e `preparando` ficam fora pelo motivo direto: **ninguém terminou de revisar**.
     */
    public const JA_PASSARAM_PELO_PORTAO = [
        PubAprovacaoOferta::APROVADO,
        PubAprovacaoOferta::PUBLICANDO,
        PubAprovacaoOferta::PUBLICADO,
    ];

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    public function __construct(
        private FilaPublicacaoService $fila,
        private ProgramasPublicadorService $programas,
        private HistoricoDaOfertaService $historico,
    ) {}

    /**
     * Põe o PRODUTO desta oferta na fila de publicação — **anexando** ao item que já vive, se
     * houver — e grava `fila_item_id`.
     *
     * ⚠️ **Só banco.** Nenhuma requisição ao Mercado Livre sai daqui, e nenhum job é despachado.
     * ⚠️ **Não lança por recusa de NEGÓCIO:** recusa da fila volta pelo retorno, com o motivo real,
     * e é registrada no histórico. Quem chama decide — e o gancho da aprovação **não desfaz** a
     * aprovação por causa dela.
     *
     * @param  User|null  $quem  quem aprovou; `null` cai em `aprovadoPor` da própria oferta (é o
     *                           caminho do reconciliador, que roda sem pessoa na frente)
     * @return array{enfileirada: bool, fila_item_id: int|null, anexada: bool, motivo: string|null, irmas_ativas_nao_aprovadas: list<array{oferta_id: int, listing_type_id: string, andamento_rotulo: string|null}>}
     */
    public function enfileirar(PubAprovacaoOferta $oferta, ?User $quem = null): array
    {
        $produto = $oferta->produto;

        if ($produto === null) {
            return $this->recusar($oferta, self::MOTIVO_SEM_PRODUTO);
        }

        // ⚠️ A âncora de conta sai do PRODUTO, nunca de uma segunda régua: `contaOuNula()` é quem
        // responde "qual conta publica este produto", e o precedente de usá-la assim é
        // `AgendadorDaFila::checar()`. Sem token ativo ela é nula, e aí não há conta.
        $chave = $produto->contaOuNula()?->chaveContaMl();
        $alvo = $chave === null ? null : $this->programas->resolver($chave);

        if ($alvo === null) {
            return $this->recusar($oferta, self::MOTIVO_SEM_CONTA);
        }

        // ⚠️⚠️ O ANEXO, ANTES DE QUALQUER AGENDAMENTO. Ver o docblock da classe: a fila é por
        // PRODUTO, e a segunda oferta do mesmo produto tem de apontar para o MESMO item. Agendar
        // de novo seria recusado por "Já está na fila de publicação." — e a oferta ficaria sem
        // ligação com a fila que está publicando o produto dela.
        $vivo = $this->itemVivoDoProduto((int) $produto->id);

        if ($vivo !== null) {
            // ⚠️⚠️ A GUARDA DA DECISÃO 23 **NÃO** VALE AQUI, E A ORDEM É DELIBERADA: o item já
            // existe e já está em voo. Recusar o anexo não impediria nada — só deixaria esta
            // oferta **sem** a ligação que o painel usa para ler as 4 colunas de conferência.
            // A guarda protege o instante de ENTRAR na fila, que é o único em que ela decide algo.
            return $this->ligar($oferta, $vivo, anexada: true);
        }

        // ═══ ⚠️⚠️ A GUARDA DA MODALIDADE IRMÃ — DECISÃO 23 DO USUÁRIO (`PERGUNTA-193-F`) ════════
        //
        // Sem **todos** os alvos ATIVOS aprovados, a oferta NÃO entra na fila. Ela fica em
        // `aprovado` (rótulo do protótipo: *"aguardando publicação"* — que já é a verdade deste
        // caso, e por isso **nenhum** estado, coluna, toast ou rótulo novo nasce aqui), o motivo
        // REAL vai ao histórico nomeando a modalidade que falta, e o reconciliador reavalia: quando
        // a irmã for aprovada, as duas saem juntas.
        //
        // ⚠️ **POR QUE NÃO PUBLICAR SÓ A MODALIDADE APROVADA:** a fila publica o PRODUTO, e
        // `PayloadBuilderUserProducts` monta um anúncio para **cada alvo ATIVO** (`alvosAtivos()` ×
        // `variantesAtivas()`). Publicar só uma exigiria mexer em `PayloadBuilder`/
        // `PublicacaoService` — que esta milestone reusa **INTOCADOS** de propósito e que são
        // **compartilhados com o assistente antigo**. ➡️ **Foi oferecido ao usuário e RECUSADO**,
        // por risco de regressão fora do portão.
        //
        // ⚠️ É a MESMA régua da decisão 5, um nível acima: lá o bloqueio de fotos cobre **TODOS**
        // os grupos e aprovação cor por cor foi **RECUSADA**; aqui não se publica a modalidade que
        // ninguém revisou. **Não se publica o que ninguém revisou.**
        //
        // ⚠️ **ANCORADA EM `pub_rascunho_alvos.ativo`, nunca em contagem fixa de alvos** — o número
        // de alvos muda por produto, e produto com **UM** alvo ativo (o único que o agente
        // encaminha hoje) tem de continuar enfileirando normalmente. Com um alvo só a lista de
        // pendentes vem **VAZIA** por construção, e há caso de teste nas duas direções.
        $pendentes = $this->modalidadesAtivasNaoAprovadas($oferta);

        if ($pendentes !== []) {
            return $this->recusar($oferta, self::motivoDaIrmaNaoAprovada($pendentes), $pendentes);
        }

        $autor = $quem ?? $oferta->aprovadoPor;

        if ($autor === null) {
            return $this->recusar($oferta, self::MOTIVO_SEM_AUTOR);
        }

        $resultado = $this->fila->agendar(
            $alvo,
            [(int) $produto->id],
            $autor,
            ['ciente' => self::CIENTE_DO_CLIQUE_HUMANO],
        );

        if (! in_array((int) $produto->id, array_map('intval', $resultado['agendados']), true)) {
            // ⚠️ O MOTIVO REAL da fila — uma das 11 frases de `prontidaoDoLote()` —, nunca um
            // "algo deu errado". O fallback existe só para o caso em que a fila recusa sem frase.
            $motivo = (string) ($resultado['recusados'][(int) $produto->id] ?? 'a fila de publicação não aceitou este produto agora.');

            return $this->recusar($oferta, $motivo);
        }

        // ⚠️ O item é RELIDO do banco em vez de adivinhado pelo retorno: a fila devolve os ids de
        // PRODUTO agendados, e a verdade de qual linha ocupa o produto é o unique `produto_ativo`.
        $item = $this->itemVivoDoProduto((int) $produto->id);

        if ($item === null) {
            return $this->recusar($oferta, 'a fila aceitou o produto, mas o item não foi encontrado.');
        }

        return $this->ligar($oferta, $item, anexada: false);
    }

    /**
     * O item de fila VIVO (`agendado` ou `publicando`) deste produto, se houver.
     *
     * ⚠️ Lido por `produto_ativo` **e** pelo `status`, que é o par que a própria fila usa
     * (`ResumoRapidoService::itensVivos()`): `produto_ativo` é anulado quando o item sai de cena, e
     * é esse par que o unique `pubfilai_produto_ativo_uq` protege.
     */
    public function itemVivoDoProduto(int $produtoId): ?PubFilaPublicacaoItem
    {
        return PubFilaPublicacaoItem::query()
            ->where('produto_ativo', $produtoId)
            ->whereIn('status', PubFilaPublicacaoItem::VIVOS)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ⚠️⚠️ **A GUARDA DA DECISÃO 23, ISOLADA E NOMEADA:** quais modalidades **ATIVAS** deste
     * rascunho **ainda não passaram pelo portão**.
     *
     * ⚠️ **A fonte da verdade é `pub_rascunho_alvos.ativo`** — a MESMA coluna que `alvosAtivos()`
     * filtra e que decide quantos anúncios o payload monta. Não é contagem fixa de alvos (o número
     * muda por produto), não é "tem Premium?" e não é lista de modalidades conhecidas.
     *
     * ⚠️ **Alvo ativo SEM oferta nenhuma também conta como pendente**, e isso é o caso mais grave,
     * não o mais leve: sem oferta, ninguém sequer abriu aquela modalidade para revisão.
     *
     * ⚠️ A própria oferta é descartada da lista explicitamente, em vez de por efeito do estado:
     * assim o predicado serve igual ao gancho da aprovação (onde ela já está em `aprovado`) e ao
     * reconciliador, e a frase do histórico nunca diz "falta aprovar" a modalidade que está sendo
     * aprovada.
     *
     * @return list<array{alvo_id: int, oferta_id: int|null, listing_type_id: string, modalidade: string, andamento_rotulo: string|null}>
     */
    public function modalidadesAtivasNaoAprovadas(PubAprovacaoOferta $oferta): array
    {
        $ativos = PubRascunhoAlvo::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->where('ativo', true)
            ->orderBy('posicao')
            ->orderBy('id')
            ->get(['id', 'listing_type_id']);

        $porAlvo = PubAprovacaoOferta::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->get()
            ->keyBy('alvo_id');

        $pendentes = [];

        foreach ($ativos as $alvoAtivo) {
            if ((int) $alvoAtivo->id === (int) $oferta->alvo_id) {
                continue;
            }

            $irma = $porAlvo->get($alvoAtivo->id);

            if ($irma !== null && in_array($irma->andamento, self::JA_PASSARAM_PELO_PORTAO, true)) {
                continue;
            }

            $tipo = (string) $alvoAtivo->listing_type_id;

            $pendentes[] = [
                'alvo_id' => (int) $alvoAtivo->id,
                'oferta_id' => $irma === null ? null : (int) $irma->id,
                'listing_type_id' => $tipo,
                // ⚠️ O nome pt-BR sai do mapa que o painel do portão já usa — nunca o id cru.
                'modalidade' => PubAprovacaoAjuste::MODALIDADES[$tipo] ?? $tipo,
                'andamento_rotulo' => $irma?->rotulo(),
            ];
        }

        return $pendentes;
    }

    /**
     * A frase da recusa da decisão 23, **nomeando a modalidade que falta** — nunca "algo deu
     * errado", nunca o `listing_type_id` cru.
     *
     * @param  list<array{modalidade: string, andamento_rotulo: string|null}>  $pendentes
     */
    private static function motivoDaIrmaNaoAprovada(array $pendentes): string
    {
        $partes = [];

        foreach ($pendentes as $p) {
            $rotulo = $p['andamento_rotulo'];
            $partes[] = $rotulo === null || trim($rotulo) === ''
                ? sprintf('%s (ainda sem revisão aberta)', $p['modalidade'])
                : sprintf('%s (%s)', $p['modalidade'], $rotulo);
        }

        return self::MOTIVO_IRMA_NAO_APROVADA . implode(', ', $partes) . '.';
    }

    /**
     * Grava a ligação oferta → item de fila. ⚠️ **É a ÚNICA coluna que este serviço escreve na
     * oferta** — nada de andamento, nada de versão, nada de `aprovado_*`.
     *
     * ⚠️ **E é a ÚNICA oferta que ele escreve: a que recebeu.** A irmã já aprovada ganha a ligação
     * pelo caminho do **anexo**, na passada do reconciliador (193-03) — porque com a guarda da
     * decisão 23 quem agenda é sempre a **última** aprovação do produto, e as anteriores ficam
     * esperando o anexo. Escrever `fila_item_id` na irmã daqui seria este serviço mexendo em linha
     * que não lhe foi passada.
     *
     * @return array{enfileirada: bool, fila_item_id: int|null, anexada: bool, motivo: string|null, irmas_ativas_nao_aprovadas: list<array<string, mixed>>}
     */
    private function ligar(PubAprovacaoOferta $oferta, PubFilaPublicacaoItem $item, bool $anexada): array
    {
        $oferta->update(['fila_item_id' => (int) $item->id]);

        // Rastreio com id + NOME da entidade (convenção do projeto).
        Log::info(self::TAG . ' oferta ligada à fila de publicação', [
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'alvo_id' => $oferta->alvo_id,
            'fila_item_id' => (int) $item->id,
            'fila_id' => (int) $item->fila_id,
            'anexada' => $anexada,
        ]);

        return [
            'enfileirada' => true,
            'fila_item_id' => (int) $item->id,
            'anexada' => $anexada,
            'motivo' => null,
            'irmas_ativas_nao_aprovadas' => [],
        ];
    }

    /**
     * A recusa: **não** mexe na oferta e **não** lança. Registra o motivo REAL no histórico e
     * devolve. A oferta fica onde está — em `aprovado`, cujo rótulo é *"aguardando publicação"* —, e
     * o reconciliador tenta de novo.
     *
     * @param  list<array<string, mixed>>  $pendentes  as modalidades da decisão 23, quando é esse o caso
     * @return array{enfileirada: bool, fila_item_id: int|null, anexada: bool, motivo: string|null, irmas_ativas_nao_aprovadas: list<array<string, mixed>>}
     */
    private function recusar(PubAprovacaoOferta $oferta, string $motivo, array $pendentes = []): array
    {
        // ⚠️ Ator **Sistema** (`$quem = null`): quem recusou foi a fila, não quem aprovou — e quem
        // aprovou já está no evento de aprovação imediatamente anterior (COL-05).
        $this->historico->registrar(
            $oferta,
            null,
            PubAprovacaoEvento::SISTEMA,
            self::PREFIXO_NAO_ENFILEIRADA . $motivo,
            $oferta->versao === null ? null : (int) $oferta->versao,
        );

        Log::warning(self::TAG . ' oferta aprovada NÃO foi enfileirada', [
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'alvo_id' => $oferta->alvo_id,
            'motivo' => $motivo,
        ]);

        return [
            'enfileirada' => false,
            'fila_item_id' => null,
            'anexada' => false,
            'motivo' => $motivo,
            'irmas_ativas_nao_aprovadas' => $pendentes,
        ];
    }
}
