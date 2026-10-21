<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Validacao\Problema;
use Illuminate\Support\Collection;
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

    /**
     * ⚠️⚠️ **A CLÁUSULA DO PROTÓTIPO, LITERAL** (`design_handoff_publicador/Aprovacao.dc.html:956`,
     * a oferta `o7`, onde ela é a **segunda frase do próprio `error`** — não um rótulo separado).
     *
     * ⚠️ Ela **só** entra quando **nenhum** `PubPublicacaoItem` do `listing_type_id` DESTA oferta
     * ficou `CREATED`. Concatená-la sempre faria a tela mentir num PARCIAL (*"nenhuma oferta foi
     * criada"* com o Clássico no ar), e nunca concatená-la tiraria da pessoa a única informação que
     * responde *"perdi a revisão?"* — ver {@see self::motivoComClausula()}.
     */
    public const CLAUSULA_NADA_CRIADO = 'Nenhuma oferta foi criada; a revisão foi preservada.';

    /**
     * O último recurso do motivo da falha. ⚠️ **PUB-05 manda o erro REAL**, e por isso esta frase é
     * o **quinto** degrau de {@see self::motivoReal()}, não o primeiro: ela só aparece quando a
     * publicação, os itens dela e a própria fila **não escreveram causa nenhuma** — e então dizer
     * *"o Mercado Livre não informou a causa"* é a verdade, enquanto *"algo deu errado"* seria
     * ruído.
     */
    public const MOTIVO_SEM_CAUSA = 'O Mercado Livre não informou a causa da recusa.';

    /** O prefixo do evento do item que saiu da fila **sem publicar** — o motivo REAL vem depois. */
    public const PREFIXO_SAIU_DA_FILA = 'Saiu da fila de publicação sem publicar: ';

    /**
     * ⚠️⚠️ **DECISÃO 24 DO USUÁRIO (21/10/2026) — O PREFIXO DOS AVISOS DO ML ACEITOS.**
     *
     * **O problema, medido:** `PublicacaoService::iniciar()` (`:95`-`:96`) recusa publicar quando a
     * conferência L3 volta com **avisos do Mercado Livre** e `ciente` é falso (RN-90, *"marque
     * 'Estou ciente' para publicar"*). ⚠️ **Essa caixa NÃO EXISTE no portão** — ela é da tela de
     * **lote** (`Components/Mlb/Publicador/Lote/DialogoAgendar.jsx`) e não existe em nenhum
     * componente de `aprovacao/`. ➡️ Por isso {@see self::CIENTE_DO_CLIQUE_HUMANO} é `true`: o
     * clique humano em *"Aprovar para publicação"* **É** a ciência (`PERGUNTA-193-E`, 193-02).
     *
     * ⚠️⚠️ **MAS O ORQUESTRADOR MEDIU O QUE TORNAVA ISSO UMA FICÇÃO:** nada em
     * `app/Services/Publicador/Aprovacao/` lê a `ConferenciaService`, e as únicas menções a "aviso"
     * nos serviços do painel são do aviso **INTERNO** de preço alterado. ➡️ Os avisos do ML **não
     * aparecem no portão**: o clique valia como ciência de algo que o revisor **nunca viu**.
     *
     * ➡️ **A decisão do usuário:** manter `ciente = true` **E gravar no histórico QUAIS avisos o
     * Mercado Livre deu e foram aceitos**. O aviso deixa de ser invisível e passa a ser
     * **auditável**, pelo **mesmo mecanismo** das decisões 21 e 23 (o histórico que esta fase já
     * escreve) — zero tela nova, zero estado novo, zero bloqueio novo.
     *
     * ⚠️ **As duas alternativas foram OFERECIDAS e RECUSADAS:** *"mostrar os avisos no painel antes
     * de aprovar"* (o portão teria de passar a ler a `ConferenciaService` — trabalho novo) e
     * *"manter como está"* (o aviso seguiria publicando sem ninguém ver, nem na hora nem depois).
     * ⚠️ **E deixar o aviso BARRAR a aprovação é o 9º BLOQUEIO, que o usuário já recusou na
     * decisão 21.**
     *
     * ⚠️ **O QUE VAI NO TEXTO É SÓ A `mensagem` pt-BR DO AVISO** — nunca o `regra` (`V-FRT-01`…)
     * nem o `ml_causa` cru: o histórico é texto de TELA (DADO-10, a linha do tempo do painel), e
     * jargão sem explicação na tela é regra sistêmica do projeto.
     */
    public const PREFIXO_AVISOS_ACEITOS = 'Avisos do Mercado Livre aceitos ao aprovar para publicação: ';

    /**
     * O que entra quando a conferência carimbou `ciente` mas **nenhuma mensagem de aviso é
     * legível** (validação apagada, `issues` não-array, aviso sem texto).
     *
     * ⚠️ **Registrar isto é melhor que o silêncio:** a ciência aconteceu e tem de ficar auditável;
     * omitir o evento apagaria o fato de que alguém aceitou avisos. ⚠️ E é melhor que inventar
     * texto: dizer que a conferência não detalhou é exatamente o que foi medido.
     */
    public const AVISOS_SEM_DETALHE = 'a conferência não detalhou os avisos.';

    /** O separador entre avisos no evento — vários avisos numa linha só do histórico. */
    public const SEPARADOR_DE_AVISOS = ' · ';

    /**
     * ⚠️ O teto de `pub_aprovacao_ofertas.erro_publicacao` — `string(1000)`, medido no schema vivo
     * pelo plano 193-01. O corte é por **caracteres** (`mb_substr`), e a cláusula é **reservada
     * antes** do corte: truncar o motivo e perder a cláusula faria a mensagem deixar de responder
     * *"perdi a revisão?"* justamente no caso em que o ML devolveu um erro enorme.
     */
    public const LIMITE_ERRO_PUBLICACAO = 1000;

    /**
     * Os andamentos que o reconciliador olha: os dois em que a oferta **espera** algo da fila.
     *
     * ⚠️ `falha` fica **FORA**, e é decisão: sair de `falha` é *"Tentar de novo"*, um ato de PESSOA
     * (plano 193-04). Um reconciliador que ressuscitasse oferta em `falha` publicaria sozinho o que
     * já falhou uma vez, sem ninguém pedir. ⚠️ `publicado` é **terminal por estrutura**
     * (`TRANSICOES[PUBLICADO] === []`) e os quatro `live` não têm nada na fila.
     */
    public const RECONCILIAVEIS = [
        PubAprovacaoOferta::APROVADO,
        PubAprovacaoOferta::PUBLICANDO,
    ];

    /**
     * ⚠️⚠️ **RN-93 — OS TRÊS ESTADOS EM QUE O ITEM AINDA ESTÁ EM VOO NO MERCADO LIVRE.**
     *
     * `PENDING` (nem saiu), `SENT` (saiu e a resposta não chegou), `UNKNOWN` (timeout **depois** do
     * envio: o anúncio **pode** existir). ➡️ Com qualquer um deles aberto, o reconciliador **NÃO
     * FAZ NADA**: mover a oferta para `falha` diria que nada foi criado quando talvez tenha sido, e
     * mover para `publicado` carimbaria um MLB que ninguém confirmou. Quem resolve `UNKNOWN` é a
     * reconciliação **por SKU** do `PublicacaoService`, depois de `reconciliar_apos_segundos` — e
     * **este serviço não reenvia nada, nunca**.
     */
    public const EM_VOO_NO_ML = [
        PubPublicacaoItem::PENDING,
        PubPublicacaoItem::SENT,
        PubPublicacaoItem::UNKNOWN,
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

        // ⚠️⚠️ DECISÃO 24 DO USUÁRIO — ver {@see self::PREFIXO_AVISOS_ACEITOS}. É aqui, e não no
        // agendamento, porque este é o ÚNICO ponto por onde a oferta entra na fila pelos DOIS
        // caminhos (agendou ou anexou ao item da irmã): a oferta anexada vai ao ar com os MESMOS
        // avisos, e a auditoria é POR OFERTA — cada linha do tempo tem de dizer o que ela aceitou.
        $this->registrarAvisosAceitos($oferta, $item);

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
        // ⚠️⚠️ **E UMA VEZ SÓ POR MOTIVO CORRIDO** — ver {@see self::registrarUmaVez()}: o
        // reconciliador do plano 193-03 chama o enfileiramento **a cada minuto**, e sem isso uma
        // oferta parada por conferência velha escreveria **1.440 linhas iguais por dia** na linha do
        // tempo que existe para ser auditada.
        $this->registrarUmaVez($oferta, self::PREFIXO_NAO_ENFILEIRADA . $motivo);

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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ ESPELHAR O QUE A FILA FEZ — `reconciliar()` (plano 193-03)
    //
    // ═══ O QUE ESTE PASSO É, E O QUE ELE NÃO É ══════════════════════════════════════════════
    //
    // Ele é um **passo de minuto** (`publicador:portao-publicacao`, plano 193-04), logo depois do
    // `publicador:fila-publicacao`: ele **só LÊ** a fila e **move andamento pela única porta**.
    //
    // ⚠️ **NÃO é fila** e **não despacha job**: quem publica é o `PublicarRascunhoJob`, na fila
    // **`high`**, e isso **já é assim e não muda**. A razão está medida no projeto, não é
    // preferência: a fila de produção é **Redis** (a tabela `jobs` dá **0** e engana), e em
    // **16/09/2026** 179 jobs de acervo do ML na `default` seguraram um webhook da Clicksign **por
    // horas**. Publicação disparada por gente que aprovou **não espera atrás do sync do acervo**.
    // ⚠️ E não é `creative` (fila do outro dev).
    //
    // ⚠️⚠️ **ELE NUNCA ESCREVE `andamento` E NUNCA FALA COM O MERCADO LIVRE.** Toda transição é
    // {@see AndamentoDaOfertaService::mover()}, e nenhuma requisição sai daqui — nem leitura.
    // ⚠️⚠️ **E ELE NUNCA REENVIA NADA:** item em voo (`PENDING`/`SENT`/`UNKNOWN`) é intocado
    // (RN-93, {@see self::EM_VOO_NO_ML}). Reenviar duplicaria anúncio, que é o defeito que a
    // disciplina do PUB-06 — **já implementada em `PublicacaoService`, e aqui só REUSADA** —
    // existe para impedir.
    //
    // ⚠️ **IDEMPOTÊNCIA É REQUISITO, NÃO ZELO:** ele roda todo minuto. Rodar duas vezes não move
    // duas vezes (a lista fechada `TRANSICOES` recusa a 2ª), não duplica evento de histórico
    // ({@see self::registrarUmaVez()}) e não reenvia nada.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Espelha na oferta o que a fila de publicação **efetivamente fez**.
     *
     * Os cinco desfechos, e nenhum deles inventa estado:
     *
     *  1. `aprovado` **sem** item ligado → {@see self::enfileirar()} (a 2ª chance das decisões 21 e
     *     23 — e é por este caminho que a **irmã já aprovada** ganha a ligação, pelo **ANEXO**);
     *  2. item **`publicando`** → `mover(PUBLICANDO)`, que **bloqueia edição** (`publicando` ∉
     *     `LIVE`, e `podeEditar()` é `ehLive()`);
     *  3. item terminado **com** `CREATED` do `listing_type_id` DESTA oferta → `ml_item_id`
     *     gravado **ANTES** e só então `mover(PUBLICADO)`;
     *  4. item terminado **sem** `CREATED` do tipo desta oferta → `mover(FALHA, <erro REAL>)`,
     *     preservando a revisão aprovada;
     *  5. item que saiu da fila **sem publicar** com a oferta ainda em `aprovado` → o motivo REAL
     *     vai ao histórico, a oferta **não sai** de `aprovado` e volta a ser enfileirável.
     *
     * @return array{publicando: int, publicado: int, falha: int, enfileiradas: int, esperando: int, erros: int}
     */
    public function reconciliar(): array
    {
        $resumo = [
            'publicando' => 0,
            'publicado' => 0,
            'falha' => 0,
            'enfileiradas' => 0,
            'esperando' => 0,
            'erros' => 0,
        ];

        $ofertas = PubAprovacaoOferta::query()
            ->whereIn('andamento', self::RECONCILIAVEIS)
            ->orderBy('id')
            ->get();

        foreach ($ofertas as $oferta) {
            try {
                $this->reconciliarUma($oferta, $resumo);
            } catch (\Throwable $e) {
                // ⚠️ Uma oferta quebrada **não** para a passada das outras — é o precedente do
                // `AgendadorDaFila::rodar()`, e aqui vale pelo mesmo motivo: o passo roda sem
                // ninguém na frente, e perder o minuto inteiro por causa de uma linha torta
                // deixaria todas as publicações sem espelho.
                $resumo['erros']++;
                // ⚠️⚠️ **O ESTADO DA OFERTA NÃO ENTRA NESTE CONTEXTO, E ISSO É DELIBERADO.** A sonda
                // larga do gate `andamento-um-lugar-so` (186-09) casa a chave literal de andamento em
                // **qualquer** array — inclusive de LOG, que não escreve nada. ➡️ As duas saídas
                // erradas seriam entrar na lista de expositores daquele gate (ele é da Fase 186, em
                // produção, e a decisão dela foi *"arquivo novo no portão NÃO entra aqui sozinho"*) ou
                // renomear a chave para fugir do gate (o projeto tem **um vocabulário só**). A saída
                // certa é **não expor andamento**: o `oferta_id` basta para ler a linha.
                // ⚠️ Medido: eu havia posto a chave aqui, e a minha própria sonda a pegou antes do gate.
                Log::error(self::TAG . ' a reconciliação desta oferta quebrou (as outras seguiram)', [
                    'oferta_id' => $oferta->id,
                    'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
                    'alvo_id' => $oferta->alvo_id,
                    'fila_item_id' => $oferta->fila_item_id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        Log::info(self::TAG . ' reconciliação do portão concluída', $resumo);

        return $resumo;
    }

    /**
     * Uma oferta, um desfecho. ⚠️ **Nenhuma escrita de `andamento` aqui** — só `mover()`.
     *
     * @param  array<string, int>  $resumo
     */
    private function reconciliarUma(PubAprovacaoOferta $oferta, array &$resumo): void
    {
        $item = $oferta->fila_item_id === null
            ? null
            : PubFilaPublicacaoItem::find((int) $oferta->fila_item_id);

        if ($oferta->andamento === PubAprovacaoOferta::PUBLICANDO) {
            if ($item === null) {
                // ⚠️⚠️ **ESTADO IMPOSSÍVEL POR CONSTRUÇÃO, e por isso só OBSERVABILIDADE.** Os dois
                // únicos caminhos para `publicando` são este reconciliador (que exige item ligado) e
                // *"Tentar de novo"* (193-04), que **enfileira ANTES** de mover — e é exatamente por
                // essa ordem que o estado ambíguo *"publicando com item terminado/ausente"* não
                // existe. ➡️ Tratar isto como ramo de produto (mover para `falha`, re-enfileirar)
                // seria desenhar comportamento para um estado que o desenho proíbe: a saída certa é
                // **gritar no log** e não tocar em nada.
                Log::warning(self::TAG . ' oferta em publicando SEM item de fila (estado impossível)', [
                    'oferta_id' => $oferta->id,
                    'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
                    'alvo_id' => $oferta->alvo_id,
                    'fila_item_id' => $oferta->fila_item_id,
                ]);
                $resumo['esperando']++;

                return;
            }

            if ($item->vivo()) {
                // Ainda em voo: nada a espelhar nesta passada.
                $resumo['esperando']++;

                return;
            }

            $this->desfecho($oferta, $item, $resumo);

            return;
        }

        // ─── `aprovado` ─────────────────────────────────────────────────────────────────────
        if ($item === null) {
            // ⚠️⚠️ A 2ª CHANCE (decisões 21 e 23) **E O ITEM `193-G`**: com a guarda da modalidade
            // irmã, quem agenda é a **última** aprovação do produto, e a irmã aprovada antes fica
            // **sem `fila_item_id`**. É aqui que ela o ganha — `enfileirar()` acha o item VIVO do
            // produto e **ANEXA**, então as duas ofertas passam a apontar para o **MESMO** item
            // (o unique `pubfilai_produto_ativo_uq` garante que é um por produto).
            $r = $this->enfileirar($oferta);
            $resumo[$r['enfileirada'] ? 'enfileiradas' : 'esperando']++;

            return;
        }

        if ($item->status === PubFilaPublicacaoItem::AGENDADO) {
            // Na fila, esperando a vez: `aprovado` é *"aguardando publicação"*, que é a verdade.
            $resumo['esperando']++;

            return;
        }

        if ($item->status === PubFilaPublicacaoItem::PUBLICANDO || in_array($item->status, PubFilaPublicacaoItem::NO_AR, true)) {
            // ⚠️ **A LISTA FECHADA NÃO DEIXA PULAR:** `APROVADO => [PUBLICANDO, …]` e
            // `PUBLICANDO => [PUBLICADO, FALHA]`. Quando a publicação começou **e terminou** dentro
            // do mesmo minuto, o passo atravessa os dois degraus na mesma passada — e os dois
            // eventos do histórico são a verdade (ela começou e terminou), não enfeite.
            $oferta = $this->porta()->mover($oferta, PubAprovacaoOferta::PUBLICANDO);
            $resumo['publicando']++;

            if ($item->vivo()) {
                return;
            }

            $this->desfecho($oferta, $item, $resumo);

            return;
        }

        // ─── O item saiu da fila SEM publicar (`falhou`/`precisa_revisar`/`cancelado`/`pulado`) ──
        //
        // ⚠️ A oferta **NÃO** sai de `aprovado`: ninguém publicou nada, e `aprovado` é *"aguardando
        // publicação"*. Passar por `publicando` aqui seria um estado que mente (a alternativa (c),
        // recusada no planejamento), numa máquina em que `publicando` bloqueia edição.
        //
        // ⚠️ E a ligação é **desfeita**, por invariante medido no plano 193-02: oferta que ainda
        // espera publicação **nunca** aponta para item fora de `VIVOS` — senão o painel leria as 4
        // colunas de conferência de um item morto. Desfeita a ligação, a próxima passada cai no
        // caminho do enfileiramento e o produto volta para a fila.
        $this->registrarUmaVez($oferta, self::PREFIXO_SAIU_DA_FILA . self::motivoDoItem($item));
        $oferta->update(['fila_item_id' => null]);
        $resumo['esperando']++;
    }

    /**
     * O desfecho de um item que **terminou**: `publicado` com MLB confirmado, ou `falha` com o erro
     * REAL.
     *
     * ⚠️⚠️ **A ORDEM `ml_item_id` → `mover(PUBLICADO)` NÃO É DETALHE, E A TRAVA ESTÁ NO CÓDIGO.**
     * {@see AndamentoDaOfertaService::mover()} **RECUSA** o destino `publicado` sem MLB
     * (`REGRA_SEM_MLB`, via `PubAprovacaoOferta::podeSerPublicado()`). ➡️ Quem invertesse a ordem
     * aqui **não** publicaria errado: a própria porta reprovaria. O teste prova; **a porta impede**.
     *
     * ⚠️⚠️ **UMA REGRA SÓ PARA "QUAL MLB É O DESTA MODALIDADE"** — a MESMA de
     * `PublicacaoService::cadastrarNaRegua()` (`:691`-`:716`): `sortBy('indice')` →
     * `groupBy('listing_type_id')` → `first()`. Uma segunda regra aqui faria **duas verdades** sobre
     * o mesmo fato, e a divergência apareceria como *"o MLB da aba Anúncios é diferente do MLB do
     * portão"*. ⚠️ A conferência de MLB **legível** vem depois da escolha, e não a substitui: trocar
     * o `first()` por *"o primeiro com MLB"* seria a segunda régua.
     *
     * @param  array<string, int>  $resumo
     */
    private function desfecho(PubAprovacaoOferta $oferta, PubFilaPublicacaoItem $item, array &$resumo): void
    {
        $tipo = self::modalidadeDaOferta($oferta);

        if ($tipo === null) {
            // Sem o alvo não há como saber de que modalidade é esta oferta — e carimbar o MLB de
            // outra seria pior que esperar. Observabilidade, não ramo de produto.
            Log::warning(self::TAG . ' oferta sem modalidade legível: desfecho adiado', [
                'oferta_id' => $oferta->id,
                'alvo_id' => $oferta->alvo_id,
                'fila_item_id' => $item->id,
            ]);
            $resumo['esperando']++;

            return;
        }

        $publicacao = $item->publicacao_id === null
            ? null
            : PubPublicacao::find((int) $item->publicacao_id);

        if ($publicacao === null) {
            // A publicação não existe (nunca começou, ou a linha sumiu): nada foi criado.
            $this->falhar($oferta, self::motivoReal(null, $tipo, $item), [], $resumo);

            return;
        }

        // ⚠️⚠️ RN-93 — ITEM EM VOO: **NADA SE MOVE E NADA É REENVIADO.** Ver {@see self::EM_VOO_NO_ML}.
        if ($publicacao->itens()->whereIn('status', self::EM_VOO_NO_ML)->exists()) {
            $resumo['esperando']++;

            return;
        }

        $doMeuTipo = self::criadosDaModalidade($publicacao, $tipo);
        $mlb = $doMeuTipo->first()?->ml_item_id;

        if (is_string($mlb) && trim($mlb) !== '') {
            // ⚠️ O MLB **PRIMEIRO**. Ver o docblock: a porta recusa `publicado` sem ele.
            $oferta->update(['ml_item_id' => trim($mlb)]);
            $this->porta()->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO);
            $resumo['publicado']++;

            return;
        }

        // ⚠️ Sem MLB legível a oferta **não pode** ir a `publicado` (AND-04) — mas a cláusula
        // *"Nenhuma oferta foi criada"* depende de `$doMeuTipo`, não disto: item `CREATED` do meu
        // tipo **sem** MLB legível significa que o anúncio **foi** criado e nós não conseguimos ler
        // o código. Dizer que nada foi criado ali seria mentir.
        $this->falhar($oferta, self::motivoReal($publicacao, $tipo, $item), $doMeuTipo->all(), $resumo);
    }

    /**
     * `mover(FALHA, <erro REAL + cláusula quando é verdade>)`.
     *
     * ⚠️ **A REVISÃO APROVADA É PRESERVADA PELA PRÓPRIA PORTA:** `mover(FALHA)` grava **só**
     * `andamento` e `erro_publicacao` — `versao`, `versao_aprovada`, `aprovado_por` e `aprovado_em`
     * não entram em `$mudancas`. Este serviço **não** os toca, e é por isso que *"a revisão foi
     * preservada"* pode ser dito sem mentir.
     *
     * @param  array<int, mixed>  $criadosDoMeuTipo
     * @param  array<string, int>  $resumo
     */
    private function falhar(PubAprovacaoOferta $oferta, string $motivo, array $criadosDoMeuTipo, array &$resumo): void
    {
        $this->porta()->mover(
            $oferta,
            PubAprovacaoOferta::FALHA,
            null,
            self::motivoComClausula($motivo, $criadosDoMeuTipo),
        );
        $resumo['falha']++;
    }

    /**
     * ⚠️⚠️ **A CLÁUSULA CONDICIONAL, ISOLADA E PÚBLICA — E A RAZÃO DE SER PÚBLICA É MEDIDA.**
     *
     * Dentro do caminho da falha, *"nenhum `CREATED` do meu tipo"* é **quase sempre** verdade (é
     * justamente por isso que a oferta não foi a `publicado`), então um teste que exercitasse só
     * aquele caminho deixaria a mutação *"concatenar a cláusula SEMPRE"* **VIVA**: ela não teria
     * caso em que a cláusula precisa estar **ausente**. ➡️ O predicado é público para ser medido
     * **nas duas direções**, e ainda há **um** caso real em que a lista vem **não-vazia** aqui: item
     * `CREATED` do meu tipo com `ml_item_id` ilegível (o anúncio existe, o código não foi lido).
     *
     * ⚠️ O corte para `string(1000)` **reserva a cláusula antes**: perder a cláusula no truncamento
     * tiraria a resposta de *"perdi a revisão?"* exatamente quando o ML devolve um erro gigante.
     *
     * @param  array<int, mixed>  $criadosDoMeuTipo  os `PubPublicacaoItem` CREATED do `listing_type_id` DESTA oferta
     */
    public static function motivoComClausula(string $motivo, array $criadosDoMeuTipo): string
    {
        $motivo = trim($motivo) === '' ? self::MOTIVO_SEM_CAUSA : trim($motivo);

        if ($criadosDoMeuTipo !== []) {
            return mb_substr($motivo, 0, self::LIMITE_ERRO_PUBLICACAO);
        }

        $reserva = self::LIMITE_ERRO_PUBLICACAO - mb_strlen(self::CLAUSULA_NADA_CRIADO) - 1;

        return mb_substr($motivo, 0, max(0, $reserva)) . ' ' . self::CLAUSULA_NADA_CRIADO;
    }

    /**
     * O **erro REAL** (PUB-05), pelos cinco degraus — o primeiro deles é o **precedente literal** de
     * `AgendadorDaFila::fechar()` (`conta_snapshot['motivo']` antes de `avisos['mensagem']`).
     *
     * ⚠️ **O único refinamento sobre o precedente, e ele é declarado:** entre os itens `FAILED`, o
     * do **meu** `listing_type_id` vem primeiro. `fechar()` fecha o PRODUTO (um motivo para os dois
     * anúncios); aqui a oferta **é** uma modalidade, e mostrar na tela do Premium o erro do Clássico
     * seria o erro certo da coisa errada. A ordem dos degraus (conta → aviso → fila) **não** muda.
     *
     * ⚠️ **Guarda de tipo ANTES de todo acesso por chave:** `conta_snapshot` e `avisos` são colunas
     * JSON, e valor adverso vindo do banco (string, nulo, lista) **lançaria TypeError** num
     * `$x['k']` cru.
     */
    private static function motivoReal(?PubPublicacao $publicacao, string $tipo, PubFilaPublicacaoItem $item): string
    {
        // 1) O motivo da própria publicação (conta, schema ou plano que mudou).
        $snapshot = $publicacao?->conta_snapshot;
        $daConta = is_array($snapshot) ? ($snapshot['motivo'] ?? null) : null;

        if (is_string($daConta) && trim($daConta) !== '') {
            return trim($daConta);
        }

        if ($publicacao !== null) {
            $falhados = $publicacao->itens()
                ->where('status', PubPublicacaoItem::FAILED)
                ->get()
                ->sortBy('indice');

            // 2) O aviso do item FAILED **do meu tipo**; 3) de qualquer outro.
            foreach ([true, false] as $soDoMeuTipo) {
                foreach ($falhados as $falhado) {
                    if ($soDoMeuTipo && (string) $falhado->listing_type_id !== $tipo) {
                        continue;
                    }

                    $avisos = $falhado->avisos;
                    $mensagem = is_array($avisos) ? ($avisos['mensagem'] ?? null) : null;

                    if (is_string($mensagem) && trim($mensagem) !== '') {
                        return trim($mensagem);
                    }
                }
            }
        }

        // 4) O que a FILA já escreveu no item (ela usa o mesmo precedente) e 5) o último recurso.
        return self::motivoDoItem($item);
    }

    /** O motivo que a fila escreveu no item, ou o último recurso. */
    private static function motivoDoItem(PubFilaPublicacaoItem $item): string
    {
        $motivo = $item->motivo;

        return is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : self::MOTIVO_SEM_CAUSA;
    }

    /**
     * Os `PubPublicacaoItem` **CREATED** do `listing_type_id` desta oferta, **na ordem de `indice`**
     * — a MESMA regra de `PublicacaoService::cadastrarNaRegua()`, nunca uma segunda.
     *
     * @return Collection<int, PubPublicacaoItem>
     */
    private static function criadosDaModalidade(PubPublicacao $publicacao, string $tipo): Collection
    {
        /** @var Collection<int, PubPublicacaoItem> $criados */
        $criados = $publicacao->itens()
            ->where('status', PubPublicacaoItem::CREATED)
            ->get()
            ->sortBy('indice')
            ->values();

        /** @var Collection<int, PubPublicacaoItem> $doTipo */
        $doTipo = $criados->groupBy('listing_type_id')->get($tipo) ?? collect();

        return $doTipo->values();
    }

    /**
     * A modalidade (`listing_type_id`) desta oferta, lida do **alvo** — a extensão 1:1 da oferta.
     *
     * ⚠️ Guarda de tipo: alvo apagado, ou `listing_type_id` vazio, devolve **`null`**, e quem chama
     * **espera** em vez de adivinhar.
     */
    private static function modalidadeDaOferta(PubAprovacaoOferta $oferta): ?string
    {
        $tipo = $oferta->alvo?->listing_type_id;

        return is_string($tipo) && trim($tipo) !== '' ? trim($tipo) : null;
    }

    /**
     * ⚠️⚠️ **DECISÃO 24 DO USUÁRIO: QUAIS AVISOS DO MERCADO LIVRE FORAM ACEITOS.**
     * Ver {@see self::PREFIXO_AVISOS_ACEITOS} para o problema medido e as alternativas recusadas.
     *
     * ⚠️ **A FONTE É O PRÓPRIO ITEM DA FILA, e isso não é detalhe:** `FilaPublicacaoService::agendar()`
     * grava `ciente = $ciente && $l['avisos_ml']` e `validacao_id = <a conferência que ele usou>`.
     * ➡️ `ciente === true` significa, **medido**, *"houve aviso do ML e o clique o aceitou"*, e a
     * `validacao_id` é **a mesma** conferência que o `PublicacaoService::iniciar()` vai reler. Nada
     * aqui reconsulta a `ConferenciaService` nem aplica uma segunda régua de *"qual conferência
     * vale"* — e é por isso que o portão **continua sem ler aquele serviço** (o que o usuário
     * recusou foi justamente passar a mostrar os avisos no painel).
     */
    private function registrarAvisosAceitos(PubAprovacaoOferta $oferta, PubFilaPublicacaoItem $item): void
    {
        if ($item->ciente !== true) {
            return;
        }

        $avisos = self::avisosAceitos($item);

        $this->registrarUmaVez($oferta, self::PREFIXO_AVISOS_ACEITOS . ($avisos === []
            ? self::AVISOS_SEM_DETALHE
            : implode(self::SEPARADOR_DE_AVISOS, $avisos) . '.'));
    }

    /**
     * As **mensagens pt-BR** dos avisos da conferência que o item carrega — e **só** elas.
     *
     * ⚠️ **Nada de `regra` (`V-FRT-01`…) nem de `ml_causa` cru:** o histórico é texto de TELA
     * (DADO-10) e jargão sem explicação na tela é regra sistêmica do projeto.
     * ⚠️ **Avisos repetidos entram UMA vez** — é o mesmo cuidado do `freteContraAPrecificacao`
     * (*"12 cores com o mesmo frete não são 12 avisos"*).
     * ⚠️ Guarda de tipo em cada degrau: `issues` é coluna JSON e pode vir não-array.
     *
     * @return list<string>
     */
    public static function avisosAceitos(PubFilaPublicacaoItem $item): array
    {
        if ($item->validacao_id === null) {
            return [];
        }

        $issues = PubValidacao::find((int) $item->validacao_id)?->issues;

        if (! is_array($issues)) {
            return [];
        }

        $frases = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $mensagem = $issue['mensagem'] ?? null;

            if (($issue['severidade'] ?? null) !== Problema::AVISO || ! is_string($mensagem) || trim($mensagem) === '') {
                continue;
            }

            $frase = trim($mensagem);

            if (! in_array($frase, $frases, true)) {
                $frases[] = $frase;
            }
        }

        return $frases;
    }

    /**
     * Registra no histórico **uma vez por motivo corrido**: se a **última** linha da oferta já é
     * exatamente este texto, não grava de novo.
     *
     * ⚠️⚠️ **POR QUE ISSO É NECESSÁRIO, MEDIDO:** o reconciliador roda **todo minuto** e chama o
     * enfileiramento da oferta parada em **cada** passada. Sem esta guarda, uma oferta cuja
     * conferência venceu escreveria **1.440 linhas idênticas por dia** — e a linha do tempo existe
     * para ser **auditada**, não para ser rolada.
     *
     * ⚠️ **É "a ÚLTIMA linha", não "existe em qualquer lugar", e isso é decisão:** o mesmo motivo
     * voltando **depois** de outro fato é informação nova (a oferta foi recusada, alguém mexeu, e
     * ela foi recusada de novo). Deduplicar contra todo o histórico apagaria essa diferença.
     *
     * ⚠️ **A tabela continua APPEND-ONLY:** esta guarda **lê antes de inserir** — ela não apaga,
     * não edita e não oculta nada (T-186-18).
     */
    private function registrarUmaVez(PubAprovacaoOferta $oferta, string $texto): ?PubAprovacaoEvento
    {
        $ultimo = PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->value('texto');

        if (is_string($ultimo) && $ultimo === trim($texto)) {
            return null;
        }

        return $this->historico->registrar(
            $oferta,
            null,
            PubAprovacaoEvento::SISTEMA,
            $texto,
            $oferta->versao === null ? null : (int) $oferta->versao,
        );
    }

    /**
     * A ÚNICA PORTA de transição, resolvida **sob demanda**.
     *
     * ⚠️⚠️ **NÃO É PREGUIÇA DE CONSTRUTOR: É CICLO, E ELE É MEDIDO.**
     * `AndamentoDaOfertaService::__construct()` já recebe **este** serviço (é a ligação do gancho
     * `aoAprovar()`, plano 193-02). ➡️ Declarar `AndamentoDaOfertaService` no construtor **daqui**
     * fecharia o ciclo `A → B → A`, e o container do Laravel **não detecta** esse ciclo: ele recursa
     * até estourar a pilha/memória. Há asserção no teste deste plano exigindo que o construtor
     * **não** o declare, com controle positivo de que a porta declara este.
     */
    private function porta(): AndamentoDaOfertaService
    {
        return app(AndamentoDaOfertaService::class);
    }
}
