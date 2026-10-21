<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Illuminate\Support\Facades\Route as Rotas;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 193, plano 05 — OS GATES DO PRÓPRIO FECHAMENTO.
 *
 * ⚠️⚠️ **O `193-FECHAMENTO.md` é MEDIÇÃO DATADA, não gate perene.** Cada número dele vale para o
 * commit escrito ao lado e **não deve ser "atualizado" por sessão futura** — quem quiser o número
 * de hoje, **mede hoje**. Por isso **nenhum método deste arquivo confere NÚMERO de teste, de
 * asserção, de build ou de manifest do documento**: eles conferem a **FORMA** dele. Régua que
 * acompanha o documento é régua que o executor seguinte ajusta.
 *
 * ⚠️⚠️ **E ESTE ARQUIVO NÃO CHAMA GIT — NEM UMA VEZ.** A decisão é medida, não estilística, e ela
 * **diverge de um `key_link` do `193-05-PLAN.md`**, que pedia o `git diff --numstat` DENTRO do
 * teste:
 *
 *   · um gate `PARTIDA..HEAD` sobre um arquivo é **auto-invalidante**: a base é um commit FIXO e o
 *     range **cresce para sempre**. No dia em que outra sessão legitimamente editar
 *     `MlPublicacaoService`, este teste — que roda na suíte **toda vez**, para sempre — fica
 *     vermelho por **trabalho alheio correto**. É a família *"âncora que se invalida sozinha"*,
 *     consertada **dezessete** vezes nesta milestone **na raiz** e **zero** por elevação de número.
 *     O 17º caso foi o `publicador-painel-casca-188.test.js` (`git log -80`, commit da fase na
 *     posição 81) e, pior, o vermelho dele **escondia** que as duas passadas de território
 *     asseveravam `[] === []`;
 *   · os fechamentos das Fases **188 e 189** já tomaram esta decisão, com a medição escrita:
 *     *"NÃO usa range de git — nem git nenhum … a prova de território é por ESCOPO DO ASSUNTO e
 *     mora no FECHAMENTO como medição datada"*. Copiar a disciplina deles é mais forte que repetir
 *     o `key_link`.
 *
 * ➡️ **O `numstat` do `MlPublicacaoService`, do `PublicacaoService` e dos dois `.js` intocáveis foi
 * MEDIDO em linha de comando e está COLADO no `193-FECHAMENTO.md`**, com `test -e` de cada caminho
 * **antes** de acreditar no vazio. O que fica aqui é o que é **durável**:
 *   *(a)* o **ANTI-FANTASMA** (`file_exists` em cada `ALVO-INTOCADO:`), que não depende de git e por
 *         isso **não acusa o orquestrador nem a sessão paralela**;
 *   *(b)* a versão **COMPORTAMENTAL** do diff vazio: nenhum arquivo desta fase **menciona**
 *         `MlPublicacaoService` em código, e o único `POST /items` do sistema continua no serviço
 *         reusado;
 *   *(c)* a **igualdade de CONJUNTO** dos dois arquivos de teste intocáveis, por **SEMÂNTICA**.
 *
 * ⚠️ **A pergunta do GATE-01, respondida para o arquivo inteiro** — *o que precisaria estar errado
 * no mundo para estes gates falharem?*
 *   · alguém **apagar** o fechamento, ou deixá-lo como esqueleto (método 7);
 *   · alguém afirmar que **viu a tela** ou que houve **publicação real** (método 9);
 *   · alguém citar, na lista de território, um **caminho que não existe** — e ler o silêncio do
 *     `git diff` como *"intocado"*. São **DEZOITO** fantasmas medidos nesta milestone, e vários
 *     vieram de **briefing do orquestrador** (método 1);
 *   · alguém **estender** o gate do D-11 à área nova, ou **afrouxar** a lista dele (método 2);
 *   · nascer escrita direta no Mercado Livre dentro dos arquivos desta fase, fora do caminho da
 *     fila (método 3);
 *   · nascer (ou morrer) **rota** de aprovação, ou a fase ganhar `.jsx`/migration (métodos 4 e 5);
 *   · reaparecer o **segundo passo** da aprovação em código (método 6);
 *   · um **id de manifesto** nascer órfão, repetido, com `tests=0` ou com mais de três métodos — e
 *     ⚠️ **id órfão NÃO falha: ele nunca roda**, e é indistinguível de um gate verde (método 8);
 *   · um **critério** ser reescrito para caber, um dos dez desaparecer, ou uma **ressalva perder o
 *     dono** (métodos 8 e 9).
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ════════════════════════════════════════
 *
 * **1. NÃO entra em `PRODUCAO_PHP_DO_PORTAO` do `FechamentoDaFase186Test`.** O docblock daquela
 * constante **já decidiu**: *"Arquivo novo no portão NÃO entra aqui sozinho … o gate `unica-porta`
 * do plano 186-04 (que **é** por glob, de propósito) continua pegando o arquivo novo pela camada
 * dele."* Mexer ali mudaria `assertSame(25, …)`/`assertSame(22, …)` de um gate **em produção**.
 *
 * **2. NÃO confere o AUTOR.** As sessões desta árvore commitam com o **mesmo** `user.name`. Medido
 * agora: **CINCO** identidades no repositório (`MB.ECF-100376` 3727, `ECF Dev` 2008, `Dev ECF` 57,
 * `root` 5, `ecfmaycon` 4) e **os 13 commits do escopo `(193-0N)` são TODOS da primeira** — a mesma
 * das waves alheias. `%an` é **vazio por construção** como discriminador.
 *
 * **3. NÃO varre `app/` inteiro à procura de vocabulário.** O gate transversal da Fase 186 fazia
 * isso e **BARROU UM DEPLOY** (o MCP da Shopee usa `'andamento'` como status de promoção). Aqui o
 * recorte é a **lista CONSTANTE** dos arquivos desta fase, com **isca do recorte**.
 *
 * **4. NÃO toca o banco.** Tudo é leitura de arquivo e leitura do ROUTER. Nenhuma asserção daqui
 * pode ser satisfeita por tabela vazia — e `pub_aprovacao_ofertas` está **vazia em produção**.
 *
 * **5. NENHUMA asserção dentro de laço.** Toda contagem é de **lista CONSTANTE** ou de infratores
 * **COLETADOS** numa asserção única. ➡️ **A contagem de asserções deste arquivo é a MESMA com 5 ou
 * com 500 ids nos manifestos, e a mesma com zero ou com N commits no escopo.**
 *
 * ⚠️⚠️ **E O RECORTE DE SEÇÃO É `[^\n]*` NO CABEÇALHO E `[\s\S]*?` NO CORPO, NUNCA `.` COM `/s`.**
 * Com `/s` o `.` casa `\n`, o `.*$` do cabeçalho **engole o documento inteiro**, o corpo sai
 * **VAZIO** e o gate mede **NADA** — medido no fechamento da Fase 187. Há isca das **duas**
 * direções: o recorte não pode estar vazio **e** tem de ser **menor** que o documento.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDaFase193Test extends TestCase
{
    /** O diretório da fase. Constante, para o caminho não ser remontado em cada método. */
    private const DIR_FASE = '.planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo';

    /**
     * ⚠️ **São 10, CONTADOS por mim no `ROADMAP.md`** — e a contagem foi **iscada contra as
     * vizinhas**, porque um `awk` errado traz a fase errada **em silêncio**:
     * `186 → 17`, `187 → 15`, `188 → 20`, `189 → 8`, `192 → 12`, `194 → 9`. As três primeiras
     * batem com o que as próprias fases declararam, logo o recorte está certo. ⚠️ **E a isca de
     * SUB-captura também rodou:** não existe, no bloco da 193, linha numerada com indentação
     * diferente de dois espaços — se existisse, o `grep -cE "^  [0-9]+\. "` perderia critério.
     */
    private const CRITERIOS = 10;

    /** Os 5 planos da fase — e os 5 manifestos que lhes correspondem, um a um. */
    private const PLANOS = 5;

    /**
     * ⚠️ **LISTA CONSTANTE, NÃO `glob()`** — os **5** arquivos de **produção** que esta fase tocou.
     * `glob()` aqui seria a armadilha da contagem frágil: a Fase 194 **edita** esta mesma pasta.
     * ⚠️ Dois são NOVOS (`PublicacaoDoPortaoService`, `PublicadorPortaoPublicacao`); nos outros três
     * a fase mexeu **só** no gancho / no bloco novo / na action — e isso é prova de diff, que mora
     * no `193-FECHAMENTO.md`.
     */
    private const PHP_DA_FASE = [
        'app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php',
        'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
        'app/Console/Commands/PublicadorPortaoPublicacao.php',
        'routes/console.php',
        'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
    ];

    /** Os arquivos de **teste** desta fase. Lista constante, pela mesma razão. */
    private const TESTES_DA_FASE = [
        'tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php',
        'tests/Feature/Publicador/Aprovacao/EnfileirarAoAprovarTest.php',
        'tests/Feature/Publicador/Aprovacao/ReconciliarPublicacaoDoPortaoTest.php',
        'tests/Feature/Publicador/Aprovacao/ComandoDoPortaoDaPublicacaoTest.php',
        'tests/Feature/Publicador/Aprovacao/FechamentoDaFase193Test.php',
    ];

    /** O serviço que PUB-03 manda **não** alterar, e o que de fato publica, no caminho reusado. */
    private const MLPUBLICACAOSERVICE = 'app/Services/Mlb/Publicacao/MlPublicacaoService.php';

    private const PUBLICACAOSERVICE = 'app/Services/Publicador/PublicacaoService.php';

    /**
     * ⚠️ **O FANTASMA MEDIDO:** `app/Services/Mlb/MlPublicacaoService.php` **não existe**. Ele é um
     * caminho que já apareceu em briefing deste projeto, e `git diff -- <fantasma>` sai **VAZIO**,
     * indistinguível de *"intocado"*. ➡️ A isca do anti-fantasma usa este caminho de verdade.
     */
    private const FANTASMA_DO_MLPUBLICACAO = 'app/Services/Mlb/MlPublicacaoService.php';

    /**
     * ⚠️⚠️ **OS ALVOS QUE NÃO PODEM SAIR DA LISTA `ALVO-INTOCADO:` — E ISTO É O CONSERTO DE UMA
     * SOBREVIVENTE DA CAMPANHA DE MUTAÇÃO DESTA WAVE.**
     *
     * A primeira redação do gate media a lista por **PISO**
     * (`assertGreaterThanOrEqual(20, count($alvos))`) mais a varredura de fantasmas. ⚠️ A mutação
     * **m9** — que **REMOVEU** uma linha `ALVO-INTOCADO:` — **SOBREVIVEU**: com 32 alvos, apagar um
     * deixa 31, **acima do piso**. ➡️ É a família ***"piso `>= N` NÃO prova PRESENÇA"***, a mesma
     * da sobrevivente **M5** do fechamento da Fase 187 (lá, com 13 perguntas, perder **uma**
     * deixava 12 e todos os pisos passavam — e a que se perdeu era justamente a do bug latente).
     *
     * ⚠️ **E o risco é concreto, não teórico:** *"tirar alvo da lista"* é exatamente **o conserto
     * barato** que o learnings manda evitar — quem for incomodado por um alvo o apaga, e o gate
     * continua verde.
     *
     * ➡️ **A lista abaixo é o SUBCONJUNTO CRÍTICO, congelado:** cada um destes carrega um critério
     * desta fase (PUB-03, o D-11/D-22, o GATE-07, a trava de conta, a fila `high`, a decisão 22).
     * ⚠️ **Subconjunto, e não a lista inteira, de propósito:** recopiar os 32 criaria **duas
     * verdades livres para divergir**, e manter o par sincronizado viraria trabalho sem prova. O
     * **tamanho exato** cuida da remoção de qualquer um; esta lista cuida de **quais** nunca podem
     * sair.
     */
    private const ALVOS_CRITICOS = [
        'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
        'app/Services/Publicador/PublicacaoService.php',
        'app/Jobs/Publicador/PublicarRascunhoJob.php',
        'app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php',
        'tests/js/estrutura-meus-anuncios.test.js',
        'tests/js/publicador-header-zindex-ordem.test.js',
        '.planning/ROADMAP.md',
        '.planning/STATE.md',
        '.planning/REQUIREMENTS-v27.md',
    ];

    /**
     * O tamanho **EXATO** da lista `ALVO-INTOCADO:`, **MEDIDO**.
     *
     * ⚠️ **Contagem frágil por construção, DECLARADA** — igual ao que o `contagens-186-04.txt` faz
     * com os ids por glob. É assim de propósito: **remoção** é a ameaça que este número pega, e
     * **acrescentar** alvo é ato deliberado de quem o acrescenta, que então **remede o número com a
     * razão escrita**. ⚠️ **Baixar este número para "passar" é o conserto proibido.**
     */
    private const ALVOS_INTOCADOS = 32;

    /** Os dois arquivos de teste **INTOCÁVEIS** por decisão das Fases 187/188/189. */
    private const INTOCAVEL_D11 = 'tests/js/estrutura-meus-anuncios.test.js';

    private const INTOCAVEL_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';

    /**
     * ⚠️⚠️ **IGUALDADE DE CONJUNTO, NUNCA PISO** — os **24** nomes de teste declarados em
     * `estrutura-meus-anuncios.test.js`, identificados por **SEMÂNTICA** (o nome), nunca por número
     * de linha (um gate em `'Servico:131'` já quebrou quando outro dev deslocou a linha).
     *
     * ⚠️ **24 declarações rodam 30 testes**: as seis primeiras estão num laço sobre dois caminhos
     * (`${caminho}`), e é por isso que a contagem de execução é **30** e a de declaração é **24**.
     * Congelar a de execução exigiria rodar `node` de dentro do PHPUnit; congelar a de **declaração**
     * mede o mesmo invariante e é durável.
     *
     * ⚠️ **Piso `>=` não serviria:** com 24 nomes, perder **um** deixaria 23 e qualquer piso
     * razoável passaria — foi a sobrevivente **M5** do fechamento da Fase 187.
     */
    private const NOMES_DO_D11 = [
        '${caminho} — tipografia: sem text-xs (12px fora do vocabulário — Label é 11px nesta fase)',
        '${caminho} — tipografia: sem text-[10px]/[12px]/[13px] — os sétimos tamanhos que a Revisão 2 eliminou',
        '${caminho} — tipografia: Label/Body/Heading do vocabulário aprovado estão em uso (Display é assunto da página, não do helper)',
        '${caminho} — peso: sem font-medium (500) — terceiro peso silencioso que a Revisão 3 eliminou',
        '${caminho} — peso: sem font-bold (700) — o painel do wizard usa, esta fase não herda',
        '${caminho} — espaçamento: nenhuma classe utilitária termina em meio-passo (.5)',
        '180: MeusAnuncios.jsx saiu do vocabulário ANTIGO — os valores de hoje estão VETADOS (FID-03/FID-04)',
        '180: a migração de MeusAnuncios.jsx para o gate NOVO é REAL — não pode ser revogada em silêncio',
        '180: o "Atualizar agora" mudou de ARQUIVO sem mudar uma classe — é movimento, não redesenho (FID-02)',
        'D-11: nenhum router.put/delete/patch — a tela nunca escreve no ML',
        'D-11: o único router.post existente aponta para meus.atualizar (enfileira, nunca escreve)',
        '180: D-11 endurecido — nenhum verbo de escrita entra por outra porta',
        'D-22: a nota aparece como "de 86", nunca convertida para 100',
        'abas: as 4 rotas do módulo estão presentes em ModoAnuncioTabs.jsx',
        'abas: mlb.anuncios.meus vem ANTES de mlb.anuncios.publicador.produtos — Meus Anúncios é a aba inicial (D-13)',
        'abas: ModoAnuncioTabs não aponta mais para mlb.anuncios.wizard (D22) e desabilita sem Company (D23)',
        '177-08: a página monta o PublicadorHeader e não monta mais BarraDaConta/AbasDaConta',
        '177-08: "Atualizar agora" continua no slot de ações do header, com o MESMO estado de antes',
        '180: "No ar | Histórico" NÃO está mais em MeusAnuncios — e o Histórico inteiro continua existindo (LIST-25)',
        '180: o que SÓ o Histórico alcança continua no servidor — a prova é SEMÂNTICA, não diff vazio',
        '177-08: SubAbasDePublicacoes mantém as DUAS rotas de hoje — nenhuma foi removida (REG-05)',
        '177-08: SubAbasDePublicacoes continua com a APARÊNCIA DE HOJE — é movimento, não redesenho (FID-02)',
        '180: Publicados | Rascunhos — o segmentado separado SAIU, a query e o contador FICARAM (D-14 + LIST-25)',
        '180-10B: o "Atualizar" do cabeçalho DA PÁGINA saiu — e o contrato do plano 180-05 continua INTEIRO',
    ];

    /** Os **7** nomes de `publicador-header-zindex-ordem.test.js` (**7/7/0**, INTOCÁVEL). */
    private const NOMES_DO_ZINDEX = [
        'o header fica ABAIXO do menu lateral do sistema (AppLayout)',
        'o header fica ABAIXO do painel lateral do produto',
        'o header continua ACIMA do corpo da página (senão some atrás dos filtros ao rolar)',
        'a ordem completa: filtros < header < menu lateral = painel do produto',
        'a extração não casa por PREFIXO (z-3 não casa dentro de z-30, z-30 não casa dentro de z-300)',
        'ISCA: âncora que eu sei que NÃO existe tem de dar 0 ocorrências (senão a sonda mente "presente" para tudo)',
        'ISCA: os comentários são removidos antes de ler (a prosa cita os próprios valores)',
    ];

    /**
     * A contagem de chamadas `assert.` dos dois intocáveis, **MEDIDA** neste HEAD.
     *
     * ⚠️ Aqui a igualdade **é** o invariante certo, e não a armadilha do número que cresce: os dois
     * arquivos são declarados **INTOCÁVEIS** por três fases. Qualquer mudança neles **é** a violação
     * que este gate existe para pegar — o número não "cresce legitimamente".
     */
    private const ASSERTS_DO_D11 = 97;

    private const ASSERTS_DO_ZINDEX = 15;

    /**
     * ⚠️⚠️ **AS 16 ROTAS DE APROVAÇÃO, PELO ROUTER — e são DEZESSEIS, não onze.**
     *
     * A `193-COBERTURA.md` §1 (critério 9) escreve *"as 6+5 da 188 mais nenhuma"*, o que soma
     * **11**. ⚠️ **MEDIDO pelo ROUTER: são 16.** O número do planejamento está **errado**; o do
     * router é o que vale, e é por isso que o conjunto é **congelado por NOME**, nunca por
     * contagem citada de artefato.
     */
    private const ROTAS_DA_APROVACAO = [
        'mlb.anuncios.meus.aprovacao',
        'mlb.anuncios.meus.aprovacao.lote',
        'mlb.anuncios.meus.aprovacao.painel.ajuste',
        'mlb.anuncios.meus.aprovacao.painel.ajuste.resolver',
        'mlb.anuncios.meus.aprovacao.painel.aprovar',
        'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao',
        'mlb.anuncios.meus.aprovacao.painel.excecao',
        'mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta',
        'mlb.anuncios.meus.aprovacao.painel.frete',
        'mlb.anuncios.meus.aprovacao.painel.frete.previa',
        'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar',
        'mlb.anuncios.meus.aprovacao.painel.imagem.reprovar',
        'mlb.anuncios.meus.aprovacao.painel.preco',
        'mlb.anuncios.meus.aprovacao.painel.preco.simular',
        'mlb.anuncios.meus.aprovacao.painel.salvar-revisao',
        'mlb.anuncios.meus.aprovacao.painel.tentar-de-novo',
    ];

    /**
     * ⚠️⚠️ **OS ÓRFÃOS DE `<verify>` QUE JÁ EXISTIAM — CONGELADOS E DECLARADOS, NÃO CONSERTADOS.**
     *
     * Um id pode estar **no manifesto, com contagem MEDIDA, apontando para métodos que EXISTEM — e
     * não ser rodado por `<verify>` nenhum**. ⚠️ **Ele não falha: ele NUNCA RODA**, e é
     * indistinguível de um gate verde (a revisão da Fase 188 achou **nove** assim).
     *
     * Medidos nesta fase, com a razão de cada um — ⚠️⚠️ **e a MEDIÇÃO CORRIGIU A MINHA PRIMEIRA
     * REDAÇÃO:** eu escrevi *"os **quatro** `bijecao-manifesto-193-0N`, por auto-referência"* e o
     * gate **REPROVOU nomeando a divergência**. São **TRÊS**, não quatro:
     *   · `bijecao-manifesto-193-02`, `-03` e `-04` — ⚠️ **e NÃO é "auto-referência por natureza":**
     *     o `193-01-PLAN.md` **declara o dele** no `<verify>`, provando que é perfeitamente
     *     declarável. As waves 02, 03 e 04 **simplesmente não o declararam**, e o gate da wave 04
     *     os excluiu **por prefixo** (`! str_starts_with($id, 'bijecao-')`) em vez de declará-los.
     *     **O harness funcionando é parte da prova**;
     *   · ⚠️ **`guarda-modalidade-irma`** — este é órfão **de verdade**: ele nasceu **no meio da
     *     wave 02**, quando a **decisão 23** do usuário chegou, e o `193-02-PLAN.md` (artefato do
     *     **planejador**, que nenhum executor edita) nunca ganhou o `<verify>` dele. ⚠️ Ele **roda**
     *     — a wave 02 e a quick `261021-fix` colaram a saída dele (`2/25`) —, mas **não é rodado
     *     pelo plano**. **Dono: usuário / orquestrador.**
     *
     * ➡️ **Congelar o conjunto é "remedir, não ajustar":** órfão NOVO reprova; estes quatro estão
     * **escritos**, com dono. ⚠️ **Apagar um id desta lista para "passar" é exatamente o conserto
     * que o learnings proíbe.**
     */
    private const ORFAOS_DECLARADOS = [
        'bijecao-manifesto-193-02',
        'bijecao-manifesto-193-03',
        'bijecao-manifesto-193-04',
        'guarda-modalidade-irma',
    ];

    /**
     * As **6** linhas de forma fixa das baselines. ⚠️ Conferida a **FORMA**, nunca o número: o
     * documento é medição datada.
     */
    private const LINHAS_DE_BASELINE = ['COMMIT:', 'BUILD:', 'MANIFEST:', 'JS:', 'PHP:', 'PROTOTIPO'];

    /** Os veredictos possíveis de um critério. **Lista FECHADA** — fora dela é erro. */
    private const VEREDICTOS = ['OK', 'RESSALVA', 'REPROVA'];

    /**
     * A sonda de *"esta linha nomeia um DONO"*, **com borda à ESQUERDA**.
     *
     * ⚠️⚠️ **A BORDA NÃO É ZELO: ELA É O CONSERTO DE UMA SOBREVIVENTE DA MINHA PRÓPRIA CAMPANHA DE
     * MUTAÇÃO.** A primeira redação era `/dono:\s*\S/u`, e a mutação **m3** — que trocou
     * `· dono: **usuário**` por `· prova-no-dono: **usuário**` na linha do `CRIT-10` —
     * **SOBREVIVEU**: a sonda casava `dono:` **no meio** de outra palavra. ➡️ Família *"gate mais
     * largo que o invariante"*, e o efeito prático era grave: uma **RESSALVA sem dono** passaria
     * se a linha contivesse qualquer palavra terminada em `dono:` (`sem-dono:`, `prova-no-dono:`,
     * `codono:`).
     *
     * ⚠️ **Borda à ESQUERDA só**, e isso também é medido: à direita vem obrigatoriamente `:`, e
     * exigir borda **dos dois lados** não acrescenta nada — mas incluir o hífen na classe de
     * exclusão **é** necessário, porque foi exatamente um hífen (`prova-no-dono`) que a mutação
     * usou.
     */
    private const SONDA_DO_DONO = '/(?<![\p{L}\p{N}\-])dono:\s*\S/u';

    /**
     * ⚠️ **LISTA CONSTANTE das perguntas obrigatórias, nunca piso** — piso **NÃO prova PRESENÇA**
     * (sobrevivente M5 da Fase 187: com 13 perguntas, perder **uma** deixava 12, acima de todos os
     * pisos, e a que se perdeu era justamente a do bug latente).
     */
    private const PERGUNTAS_OBRIGATORIAS = [
        '193-A', '193-B', '193-C', '17',
        'VISUAL-193', 'VISUAL-189', 'VISUAL-188', 'VISUAL-187',
        'ORFAO-GUARDA-MODALIDADE-IRMA', 'GATE-MEDE-WIP', 'FID01-189', 'BECO-DO-RETRY',
    ];

    /**
     * ⚠️ As **7** decisões do usuário (20 a 26) que incidem nesta fase. Cada uma tem de aparecer em
     * **forma fixa**, com **onde ela vive no código** — decisão registrada sem endereço é decisão
     * que a próxima fase reabre.
     */
    private const DECISOES = [20, 21, 22, 23, 24, 25, 26];

    /**
     * ⚠️ Os itens **devolvidos pelas waves**, cada um com **destino**. Nenhum fica reaberto: todos
     * foram resolvidos por decisão do usuário, por outra wave ou por asseveração.
     */
    private const DEVOLVIDOS = ['193-E', '193-G', '193-H', '193-I', '193-04-B', 'PERGUNTA-193-B', 'PERGUNTA-193-C'];

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // Leitura, recorte e os dois leitores
    // ───────────────────────────────────────────────────────────────────────────────────────────

    private function ler(string $relativo): string
    {
        $abs = base_path($relativo);
        $this->assertFileExists($abs, "ERRO DO GATE, não território intocado: {$relativo} não existe. Caminho fantasma já produziu 'vazios' falsos neste projeto.");

        return (string) file_get_contents($abs);
    }

    private function fechamento(): string
    {
        return $this->ler(self::DIR_FASE . '/193-FECHAMENTO.md');
    }

    /**
     * O código PHP **sem comentário**, pelo tokenizador — nunca por regex.
     *
     * ⚠️ A prosa pt-BR deste projeto cita os próprios literais proibidos para explicar que **não**
     * os usa. Um `grep` cru reprova pelo comentário que EXPLICA o acerto — foi o que aconteceu em
     * **5 dos 12 planos** da Fase 184.
     */
    private static function semComentarios(string $conteudo): string
    {
        $fora = '';
        foreach (token_get_all($conteudo) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $fora .= $t[1];

                continue;
            }
            $fora .= $t;
        }

        return $fora;
    }

    /**
     * Os nomes de `test('…', …)` / ``test(`…`, …)`` de um arquivo `.test.js`, por **SEMÂNTICA**.
     *
     * ⚠️ O delimitador pode ser aspa simples **ou** acento grave (as seis primeiras do D-11 são
     * template literal, com `${caminho}` dentro) — a sonda aceita os dois, e a isca do método 2
     * prova que ela **vê** um nome injetado e **não** inventa nenhum.
     */
    private static function nomesDeTeste(string $conteudo): array
    {
        preg_match_all('/^\s*test\((?:`|\')([\s\S]*?)(?:`|\')\s*,/m', $conteudo, $m);

        return $m[1];
    }

    /**
     * Recorta a seção cujo cabeçalho de nível 2 contém `$marca`.
     *
     * ⚠️⚠️ **`[^\n]*` no cabeçalho e `[\s\S]*?` no corpo — NUNCA `.` com `/s`.**
     */
    private function secao(string $texto, string $marca): string
    {
        $padrao = '/^##[^\n]*' . preg_quote($marca, '/') . '[^\n]*$([\s\S]*?)(?=^## |\z)/m';

        return preg_match($padrao, $texto, $m) === 1 ? $m[1] : '';
    }

    /** @return list<array{id:string,tipo:string,filtro:string,tests:string}> */
    private function entradasDoManifesto(int $n): array
    {
        $caminho = base_path('tests/gates/contagens-193-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '.txt');
        if (! file_exists($caminho)) {
            return [];
        }
        $saida = [];
        foreach (file($caminho, FILE_IGNORE_NEW_LINES) as $linha) {
            if (trim($linha) === '' || str_starts_with(ltrim($linha), '#')) {
                continue;
            }
            $partes = preg_split('/\s+::\s+/', trim($linha));
            $cabeca = preg_split('/\s+/', trim((string) $partes[0]));
            if (count($cabeca) < 3) {
                continue;
            }
            preg_match('/tests=([^\s]+)/', (string) ($partes[1] ?? ''), $t);
            $saida[] = [
                'id' => $cabeca[0],
                'tipo' => $cabeca[1],
                'filtro' => $cabeca[2],
                'tests' => $t[1] ?? '',
            ];
        }

        return $saida;
    }

    /** Os ids rodados por algum `<verify>` do plano `193-$nn`, com o **NN ANCORADO**. */
    private static function idsDoVerify(string $plano, string $nn): array
    {
        preg_match_all('/verificar-testes\.sh\s+193-' . preg_quote($nn, '/') . '\s+([a-z0-9][a-z0-9-]*)/', $plano, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Os nomes de método **REALMENTE DECLARADOS** num arquivo PHP, **por TOKEN**.
     *
     * ⚠️⚠️ **ESTE HELPER É UM CONSERTO DE RAIZ, E A RAZÃO FOI MEDIDA NESTA WAVE.** A primeira
     * redação do varredor de homônimo usava
     * `/function\s+([A-Za-z0-9_]*<meu>[A-Za-z0-9_]+)\s*\(/` sobre o **texto cru** do arquivo, e ela
     * **ACUSOU TRABALHO CERTO**: a **isca (+) da própria wave 03**
     * (`ReconciliarPublicacaoDoPortaoTest.php:1569`) monta, **dentro de uma string**, a forma de
     * declaração do nome `…_193_03_extra` — e **não existe método com esse nome** (medido por
     * token: os métodos reais `_193_03` são exatamente **15**, os do manifesto).
     *
     * ⚠️⚠️ **E ESTE DOCBLOCK NÃO ESCREVE A FORMA DE UMA DECLARAÇÃO — DE PROPÓSITO, E EU PAGUEI PARA
     * APRENDER NESTA PRÓPRIA WAVE.** A primeira redação dele **citava o literal inteiro**, com a
     * palavra-chave de declaração colada ao nome e o parêntese em seguida. Resultado: a suíte
     * completa acusou **1 regressão MINHA** — o gate `193-03` da bijeção, cujo varredor lê o
     * **texto CRU** (comentário incluído), leu o meu comentário como *"este arquivo declara um
     * método que já existe em `ReconciliarPublicacaoDoPortaoTest`"*. ⚠️ É **o furo 1 da wave 04 ao
     * contrário**: lá o nome alheio estava numa asserção; aqui, na **prosa**. ➡️ A regra vale para
     * **comentário também**: nome alheio **nunca** ao lado da palavra-chave de declaração.
     *
     * ➡️ Família ***"gate mais largo que o invariante"*** (quatorze ocorrências nesta milestone, uma
     * delas **barrou um deploy**). ⚠️ **O conserto errado seria pôr o arquivo da wave 03 numa lista
     * de exceção** — o certo é não confundir **declaração** com **texto**: um literal de string
     * **nunca** produz um token `T_FUNCTION`, e tirar comentário **não** bastaria (a isca é string,
     * não comentário).
     *
     * ⚠️ É estritamente mais forte que a regex do gate alheio `186-09 bijecao-e-homonimo`, que é
     * `/public function (test_[A-Za-z0-9_]+)\s*\(/` sobre o código sem comentário — e foi
     * **exatamente ela** que reprovou a wave 04 quando a isca entrou como literal (o furo 1 dela).
     * ⚠️ **Eu não toco naquele gate** (fase em produção): só não repito o erro dele aqui.
     *
     * @return list<string>
     */
    private static function declaracoesDeMetodo(string $conteudo): array
    {
        $tokens = token_get_all($conteudo);
        $total = count($tokens);
        $nomes = [];
        for ($i = 0; $i < $total; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $total; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nomes[] = $tokens[$j][1];
                    break;
                }
                // Função anônima / arrow fn: o `(` vem antes de qualquer nome.
                if (! is_array($tokens[$j]) && $tokens[$j] === '(') {
                    break;
                }
            }
        }

        return $nomes;
    }

    /** @return list<string> todo `*Test.php` da suíte */
    private static function arquivosDeTeste(): array
    {
        $saida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), 'Test.php')) {
                $saida[] = $arquivo->getPathname();
            }
        }

        return $saida;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // id de gate `territorio-e-d11`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O ANTI-FANTASMA — e é o gate PERENE deste arquivo.**
     *
     * `git diff -- <caminho que não existe>` sai **VAZIO**, e vazio é **indistinguível de
     * "intocado"**. São **DEZOITO** caminhos fantasmas medidos nesta milestone, e o fechamento da
     * Fase 189 pegou **6 de 32 (19%)**, **todos de NOMES** — *"um NOME não é um CAMINHO"*. ➡️ Cada
     * linha `ALVO-INTOCADO:` do fechamento é conferida **no disco**.
     *
     * ⚠️ E a versão **COMPORTAMENTAL** do *"diff vazio do `MlPublicacaoService`"*: nenhum arquivo
     * desta fase o **menciona em código**. A prosa deles **cita** o nome de propósito (para explicar
     * que ele **não** está no caminho do Publicador) — e é justamente isso que torna a **isca do
     * leitor** mensurável: no texto **CRU** a sonda acha, no código **limpo** acha zero.
     */
    public function test_os_alvos_da_fase_existem_e_o_mlpublicacaoservice_fica_fora_do_caminho_193_05(): void
    {
        $texto = $this->fechamento();

        // ─── Os alvos vetados, conferidos no disco, com os fantasmas COLETADOS ──────────────
        preg_match_all('/^ALVO-INTOCADO: (.+)$/m', $texto, $m);
        $alvos = array_map('trim', $m[1]);

        // ⚠️⚠️ TAMANHO EXATO, nunca piso — ver o docblock de `ALVOS_INTOCADOS`: a mutação **m9**
        // (remover UMA linha `ALVO-INTOCADO:`) **SOBREVIVEU** ao piso, porque 31 ≥ 20.
        $this->assertCount(
            self::ALVOS_INTOCADOS,
            $alvos,
            'a lista de alvos vetados mudou de tamanho — tirar alvo da lista é o conserto barato que o learnings manda evitar, e ACRESCENTAR exige remedir este número COM A RAZÃO ESCRITA'
        );

        // ⚠️ E o SUBCONJUNTO CRÍTICO, por presença explícita: cada um destes carrega um critério
        // desta fase, e nenhum pode sair nem que o tamanho total seja "consertado" por troca.
        $criticosFora = array_values(array_diff(self::ALVOS_CRITICOS, $alvos));
        $this->assertSame([], $criticosFora, 'alvo CRÍTICO saiu da lista de intocados (PUB-03 / D-11 / GATE-07 / trava de conta / fila high / decisão 22)');

        $fantasmas = array_values(array_filter($alvos, static fn (string $a) => ! file_exists(base_path($a))));
        $this->assertSame([], $fantasmas, 'alvos citados que NÃO existem no disco — "diff vazio" neles não prova nada');

        // ⚠️ ISCA do anti-fantasma: um caminho REAL deste projeto que **não existe** tem de ser
        // visto como ausente. Sem esta ponta, `FANTASMAS=0` é indistinguível de sonda morta.
        $this->assertFileDoesNotExist(
            base_path(self::FANTASMA_DO_MLPUBLICACAO),
            'o caminho usado como isca do anti-fantasma passou a existir: escolha outro'
        );

        // ─── Os arquivos da fase, por lista CONSTANTE, conferidos no disco ─────────────────
        $daFase = array_merge(self::PHP_DA_FASE, self::TESTES_DA_FASE);
        $this->assertNotSame([], $daFase, 'a lista dos arquivos da fase está VAZIA — isca invertida: um laço sobre nada fica verde sem ler uma linha');
        $semDisco = array_values(array_filter($daFase, static fn (string $a) => ! file_exists(base_path($a))));
        $this->assertSame([], $semDisco, 'arquivo declarado como da Fase 193 que não está no disco');

        // ⚠️ E os cinco manifestos, que o harness ABORTA sem.
        $manifestosAusentes = [];
        for ($n = 1; $n <= self::PLANOS; $n++) {
            $rel = 'tests/gates/contagens-193-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '.txt';
            if (! file_exists(base_path($rel))) {
                $manifestosAusentes[] = $rel;
            }
        }
        $this->assertSame([], $manifestosAusentes, 'manifesto de plano da Fase 193 ausente — manifesto inexistente ABORTA o harness');

        // ─── PUB-03, pela versão COMPORTAMENTAL (o `numstat` está COLADO no documento) ─────
        $this->assertFileExists(base_path(self::MLPUBLICACAOSERVICE), 'o caminho real do MlPublicacaoService sumiu — sem ele, "diff vazio" é vazio por construção');
        $this->assertFileExists(base_path(self::PUBLICACAOSERVICE), 'o PublicacaoService sumiu — é ele quem publica no caminho do Publicador');

        $mencoesLimpas = 0;
        $mencoesCruas = 0;
        $linhasLidas = 0;
        foreach (self::PHP_DA_FASE as $rel) {
            $cru = (string) file_get_contents(base_path($rel));
            $limpo = self::semComentarios($cru);
            $linhasLidas += substr_count($limpo, "\n");
            $mencoesLimpas += substr_count($limpo, 'MlPublicacaoService');
            $mencoesCruas += substr_count($cru, 'MlPublicacaoService');
        }
        $this->assertGreaterThan(2000, $linhasLidas, 'gate sobre quase nada: o leitor não leu os cinco arquivos da fase');
        $this->assertSame(0, $mencoesLimpas, 'PUB-03: um arquivo desta fase passou a MENCIONAR o MlPublicacaoService em código — ele serve o assistente antigo (MlAnuncioRascunho) e não está no caminho do Publicador');
        $this->assertGreaterThan(0, $mencoesCruas, 'a prosa da fase deixou de citar o MlPublicacaoService — a isca do leitor perdeu o sujeito e o gate não prova mais que lê SEM comentário');

        // ⚠️ E o CONTROLE POSITIVO: o serviço reusado continua sendo o dono do `POST /items`. Sem
        // ele, este gate seria *"gate sobre zero casos"* — e o próprio `MlPublicacaoService` existe
        // (175 linhas), logo o alvo é real.
        $publicacao = self::semComentarios($this->ler(self::PUBLICACAOSERVICE));
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($publicacao, "'POST', '/items'"),
            'o único POST /items do Publicador saiu do PublicacaoService — o caminho reusado por PUB-02 mudou de dono'
        );
    }

    /**
     * ⚠️⚠️ **A FRONTEIRA DO D-11: INTACTO, NÃO ESTENDIDO — E POR IGUALDADE DE CONJUNTO.**
     *
     * O D-11 diz que a **lista de Publicações nunca escreve** no Mercado Livre. Esta fase **leva
     * oferta ao ar**, logo a fronteira tem de ficar explícita nos **dois** sentidos:
     *   *(a)* o gate daquela tela continua **intacto** — conjunto de nomes **IDÊNTICO** (nunca piso)
     *         e contagem de `assert.` igual;
     *   *(b)* ele **NÃO foi estendido** à área nova: nenhum arquivo, comando ou action desta fase é
     *         mencionado lá. ⚠️ Afrouxar a lista e estender o gate são **erros opostos**, e os dois
     *         quebram a fronteira declarada.
     *
     * ⚠️⚠️ **O HOMÔNIMO MEDIDO:** o arquivo tem *"(D22)"* num teste de **abas** (`ModoAnuncioTabs`
     * … `mlb.anuncios.wizard`), que **não** é o D-22 da nota. Uma sonda `/D-?22/` casaria os dois.
     * ➡️ O D-22 é identificado pelo **prefixo do nome** (`D-22:`), e há asserção provando que o
     * teste de abas **não** entra no conjunto.
     */
    public function test_o_d11_e_o_d22_estao_intactos_e_o_gate_nao_foi_estendido_193_05(): void
    {
        $d11 = $this->ler(self::INTOCAVEL_D11);
        $zindex = $this->ler(self::INTOCAVEL_ZINDEX);

        // ─── ⚠️ A SONDA DOS NOMES, ISCADA NAS DUAS DIREÇÕES **ANTES** DE VALER ─────────────
        $this->assertSame(
            ['injetado 9z7'],
            self::nomesDeTeste("test('injetado 9z7', () => {\n});\n"),
            'ISCA (+): a sonda de nomes não vê um teste injetado — ela está cega, e o deepEqual seguinte seria vazio por construção'
        );
        $this->assertSame(
            [],
            self::nomesDeTeste("// test('comentado 9z7', () => {});\nconst x = 1;\n"),
            'ISCA (−): a sonda de nomes inventou um nome onde não há declaração de teste em início de linha'
        );

        // ─── (a) IGUALDADE DE CONJUNTO, nos dois arquivos ──────────────────────────────────
        $this->assertSame(self::NOMES_DO_D11, self::nomesDeTeste($d11), 'o conjunto de testes de estrutura-meus-anuncios.test.js MUDOU — ele é INTOCÁVEL (D-11/D-22) e qualquer mexida é a violação que este gate pega');
        $this->assertSame(self::NOMES_DO_ZINDEX, self::nomesDeTeste($zindex), 'o conjunto de testes de publicador-header-zindex-ordem.test.js MUDOU — ele é INTOCÁVEL (7/7/0)');
        $this->assertSame(self::ASSERTS_DO_D11, substr_count($d11, 'assert.'), 'a contagem de asserções do arquivo do D-11 mudou — afrouxar a lista é a metade "intacto" da fronteira');
        $this->assertSame(self::ASSERTS_DO_ZINDEX, substr_count($zindex, 'assert.'), 'a contagem de asserções do arquivo do z-index mudou');

        // ─── O D-11 e o D-22 por SEMÂNTICA, nunca por linha ───────────────────────────────
        $familiaD11 = array_values(array_filter(self::nomesDeTeste($d11), static fn (string $n) => str_contains($n, 'D-11')));
        $this->assertCount(3, $familiaD11, 'a família D-11 (os dois do prefixo + o "endurecido") mudou de tamanho');

        $familiaD22 = array_values(array_filter(self::nomesDeTeste($d11), static fn (string $n) => str_starts_with($n, 'D-22:')));
        $this->assertCount(1, $familiaD22, 'o D-22 (a nota "de 86") não foi achado por SEMÂNTICA, ou virou dois');

        // ⚠️ O HOMÔNIMO: o teste de abas cita `(D22)` e **não** é o D-22. Se ele entrasse no
        // conjunto, a sonda estaria casando a sigla no meio da frase.
        $abasComD22 = array_values(array_filter(self::nomesDeTeste($d11), static fn (string $n) => str_starts_with($n, 'abas:') && str_contains($n, 'D22')));
        $this->assertCount(1, $abasComD22, 'o teste de abas que cita (D22) desapareceu — a isca do homônimo perdeu o sujeito');
        $this->assertSame([], array_values(array_intersect($familiaD22, $abasComD22)), 'a sonda do D-22 engoliu o teste de ABAS que só cita a sigla: ela está casando no meio da frase');

        // ─── (b) O GATE NÃO FOI ESTENDIDO À ÁREA NOVA ─────────────────────────────────────
        $marcasDaFase = [
            'PublicacaoDoPortaoService',
            'PublicadorPortaoPublicacao',
            'portao-publicacao',
            'tentarDeNovo',
            'aoAprovar',
            '193',
        ];
        $estendido = array_values(array_filter($marcasDaFase, static fn (string $marca) => str_contains($d11, $marca)));
        $this->assertSame([], $estendido, 'o gate do D-11 foi ESTENDIDO à área nova — ele é da tela de Publicações, que nunca escreve; a área nova tem gate PRÓPRIO');

        // ⚠️ ISCA (+) da não-extensão: a sonda TEM de acusar um texto sintético que mencione a área
        // nova. Sem esta ponta, `ESTENDIDO=[]` é indistinguível de sonda que não lê nada.
        $sintetico = "test('x', () => { assert.ok(fonte.includes('PublicacaoDoPortaoService')); });";
        $acusados = array_values(array_filter($marcasDaFase, static fn (string $marca) => str_contains($sintetico, $marca)));
        $this->assertSame(['PublicacaoDoPortaoService'], $acusados, 'ISCA (+): a sonda da não-extensão não acusa um texto que menciona a área nova');
    }

    /**
     * ⚠️⚠️ **O GATE DE ESCRITA PRÓPRIO DA ÁREA NOVA** — a outra metade do critério 7.
     *
     * A fase **leva ao ar**, e por isso ela não pode se esconder atrás do gate do D-11: ela tem de
     * ter o **seu**. O invariante é **comportamental**, não lexical: *"o único `POST /items` do
     * sistema continua em `PublicacaoService::executarFatia()`, e nenhum arquivo desta fase fala com
     * o Mercado Livre"*.
     *
     * ⚠️⚠️ **E A SONDA É A ESTREITA, POR MEDIÇÃO — não a larga.** O briefing afirmou que *"não
     * existe NENHUM `PUT` no Publicador"*. **Falso: existem TRÊS**, e um deles
     * (`AlterarNoConvite.php:155`) usa `PUT /seller-promotions/items/`, que **CONTÉM** `/items/`.
     * ➡️ Sonda por **substring** `/items` **acusa trabalho certo**. O invariante verdadeiro — e o
     * que sustenta a **decisão 22** (editar foto de anúncio no ar fica diferida) — é
     * ***"nenhum `PUT` cujo caminho COMEÇA em `/items`"***, e ele é **VERDADEIRO**. As duas pontas
     * estão asseveradas: a estreita dá zero, e a larga **acusaria** o arquivo legítimo.
     */
    public function test_a_area_nova_nao_escreve_no_mercado_livre_fora_do_caminho_da_fila_193_05(): void
    {
        $proibidas = ['Http::', 'curl_', 'Guzzle', "'POST', '/items'", 'file_get_contents(\'http'];

        $infratores = [];
        $iscaDoLeitor = 0;
        foreach (self::PHP_DA_FASE as $rel) {
            $cru = (string) file_get_contents(base_path($rel));
            $limpo = self::semComentarios($cru);
            foreach ($proibidas as $forma) {
                // ⚠️ CONTAGEM DE OCORRÊNCIAS (`substr_count`), nunca `grep -c` (conta LINHAS: já deu
                // 12 e 724 para uma âncora de UMA ocorrência) nem `preg_match(...) === 1` (passa com
                // a linha de código apagada quando o texto também existe em comentário).
                if (substr_count($limpo, $forma) > 0) {
                    $infratores[] = $rel . ' / ' . $forma;
                }
                $iscaDoLeitor += substr_count($cru, $forma);
            }
            // ⚠️ Também o `/items` cru, que é a forma que um POST novo teria.
            if (substr_count($limpo, '/items') > 0) {
                $infratores[] = $rel . ' / /items';
            }
            $iscaDoLeitor += substr_count($cru, '/items');
        }
        $this->assertSame([], $infratores, 'PUB-03 / critério 7: nasceu escrita (ou requisição) direta ao Mercado Livre dentro dos arquivos desta fase — quem escreve é o PublicarRascunhoJob, na fila `high`, pelo PublicacaoService');
        $this->assertGreaterThan(0, $iscaDoLeitor, 'a prosa da fase deixou de citar as formas de rede — a isca do leitor perdeu o sujeito e "zero infratores" passa a ser indistinguível de leitor morto');

        // ─── ⚠️ A DECISÃO 22, pela sonda ESTREITA, com a larga medida ao lado ──────────────
        $comConvite = 'return new RequisicaoMl(\'PUT\', \'/seller-promotions/items/\' . $id);';
        $comItems = 'return new RequisicaoMl(\'PUT\', \'/items/\' . $id);';
        $estreita = '/([\'"])PUT\1\s*,\s*([\'"])\/items/';
        $larga = '/([\'"])PUT\1[\s\S]{0,40}items/';

        $this->assertSame(1, preg_match($estreita, $comItems), 'ISCA (+): a sonda estreita não pega um PUT que COMEÇA em /items — a forma que a decisão 22 proíbe');
        $this->assertSame(0, preg_match($estreita, $comConvite), 'ISCA (−): a sonda estreita acusa o PUT /seller-promotions/items/, que é trabalho LEGÍTIMO (AlterarNoConvite)');
        $this->assertSame(1, preg_match($larga, $comConvite), 'a sonda LARGA deixou de acusar o caso legítimo — a razão de preferir a estreita era exatamente essa, e ela foi medida');

        $alavancas = 'app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php';
        $this->assertFileExists(base_path($alavancas), "caminho fantasma: {$alavancas} — sem ele a isca (−) acima perde o sujeito real");

        // ─── ⚠️ A FILA É `high`, LIDA DO CÓDIGO — não afirmada na prosa ───────────────────
        $job = self::semComentarios($this->ler('app/Jobs/Publicador/PublicarRascunhoJob.php'));
        $this->assertGreaterThanOrEqual(1, substr_count($job, "onQueue('high')"), 'a publicação saiu da fila `high`: ela não espera atrás do sync do acervo (179 jobs na `default` seguraram um webhook por HORAS em 16/09/2026, e a tabela `jobs` dá 0 porque a fila de produção é REDIS)');
        $this->assertSame(0, substr_count($job, "onQueue('default')") + substr_count($job, "onQueue('creative')"), 'a publicação foi para `default` ou para `creative` (a fila do outro dev, onde roda geração paga)');

        // ─── ⚠️ A TRAVA OPERACIONAL é `ContasLiberadas`, fail-closed — e é a MESMA de PUB-06 ─
        $iniciar = self::semComentarios($this->ler(self::PUBLICACAOSERVICE));
        $this->assertGreaterThanOrEqual(1, substr_count($iniciar, 'ContasLiberadas::exigir('), 'a trava de conta liberada saiu do PublicacaoService — ela é a única chave de freio desta fase, e um segundo interruptor faria DUAS verdades sobre quem pode publicar');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // id de gate `nada-sem-destino`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **PUB-10 / critério 9 — NENHUMA ROTA SEM DESTINO, POR IGUALDADE DE CONJUNTO NO ROUTER.**
     *
     * ⚠️ **Piso não serve** e **contagem citada de artefato também não:** a `193-COBERTURA.md`
     * escreve *"as 6+5 da 188"* (= **11**) e o router mede **16**. ➡️ O conjunto é congelado por
     * **NOME**, lido do **ROUTER** — a única fonte que não pode discordar de si mesma.
     */
    public function test_o_conjunto_de_rotas_de_aprovacao_nao_cresceu_nem_encolheu_193_05(): void
    {
        $medidas = [];
        foreach (Rotas::getRoutes() as $rota) {
            $nome = (string) $rota->getName();
            if ($nome !== '' && str_starts_with($nome, 'mlb.anuncios.meus.aprovacao')) {
                $medidas[] = $nome;
            }
        }
        $medidas = array_values(array_unique($medidas));
        sort($medidas);

        // ⚠️ ISCA de não-cegueira ANTES do conjunto: o prefixo casa alguma coisa. Um `assertSame`
        // de duas listas vazias seria verde e mediria NADA — foi o furo estrutural que o conserto
        // do `casca-188` (`d1cd7713`) expôs.
        $this->assertNotSame([], $medidas, 'o ROUTER não devolveu nenhuma rota de aprovação — a sonda está cega e a igualdade de conjunto seria vazia por construção');

        $esperadas = self::ROTAS_DA_APROVACAO;
        sort($esperadas);
        $this->assertSame($esperadas, $medidas, 'PUB-10 / critério 9: nasceu (ou morreu) rota de aprovação — esta fase NÃO cria rota, e o retry é da Fase 188');

        $comNumeroDaFase = array_values(array_filter($medidas, static fn (string $n) => str_contains($n, '193')));
        $this->assertSame([], $comNumeroDaFase, 'apareceu rota batizada com o número desta fase');
    }

    /**
     * ⚠️ **NENHUMA COLUNA NOVA, NENHUM `.jsx` TOCADO — e os três do `.planning/` fora do território.**
     *
     * Esta fase **não tem migration** (as três colunas de ligação já existiam desde a Fase 186, com
     * índice e FK `nullOnDelete` nullable — provado por asserção sobre o **schema vivo** no
     * `193-01`) e **não edita `.jsx` nenhum** (a caixa de falha, o botão de retry, os 8 estados e os
     * **6 toasts** já existiam) — ⚠️ o *"UI hint: yes"* do ROADMAP é uma **superestimativa MEDIDA**.
     *
     * ⚠️ **GATE-07:** `ROADMAP.md`, `STATE.md` e `REQUIREMENTS-v27.md` **não pertencem a plano
     * nenhum** — o orquestrador os escreve à mão. Eles têm de ficar **fora** da lista do território.
     */
    public function test_nenhuma_coluna_nova_nenhum_jsx_e_o_gate07_fora_do_territorio_193_05(): void
    {
        $daFase = array_merge(self::PHP_DA_FASE, self::TESTES_DA_FASE);

        $migrations = array_values(array_filter($daFase, static fn (string $a) => str_starts_with($a, 'database/migrations/')));
        $this->assertSame([], $migrations, 'apareceu migration no território desta fase — MEDIDO no schema VIVO: as três colunas de ligação já existem desde a Fase 186');

        $jsx = array_values(array_filter($daFase, static fn (string $a) => str_starts_with($a, 'resources/')));
        $this->assertSame([], $jsx, 'apareceu arquivo sob resources/ no território desta fase — ela é SÓ SERVIDOR');

        // ⚠️ CONTROLE POSITIVO: a lista não é vazia **e** cobre as três naturezas que ela deve
        // cobrir. Sem isto, os dois `assertSame([], …)` acima seriam vazios por construção.
        $this->assertCount(10, $daFase, 'a lista do território mudou de tamanho — ela é CONSTANTE de propósito');
        $naturezas = [
            'servico' => static fn (string $a) => str_starts_with($a, 'app/Services/'),
            'comando' => static fn (string $a) => str_starts_with($a, 'app/Console/Commands/'),
            'rota' => static fn (string $a) => str_starts_with($a, 'routes/'),
            'controller' => static fn (string $a) => str_starts_with($a, 'app/Http/Controllers/'),
            'teste' => static fn (string $a) => str_starts_with($a, 'tests/'),
        ];
        $semCobertura = [];
        foreach ($naturezas as $nome => $filtro) {
            if (count(array_filter($daFase, $filtro)) === 0) {
                $semCobertura[] = $nome;
            }
        }
        $this->assertSame([], $semCobertura, 'a lista do território perdeu uma das cinco naturezas que a fase entregou');

        // ─── ⚠️ GATE-07: os três do `.planning/` NÃO são território de plano ──────────────
        $gate07 = ['.planning/ROADMAP.md', '.planning/STATE.md', '.planning/REQUIREMENTS-v27.md'];
        $invadidos = array_values(array_intersect($gate07, $daFase));
        $this->assertSame([], $invadidos, 'GATE-07: um dos três artefatos do orquestrador entrou no território de um plano');
        // ⚠️ E eles EXISTEM — senão este gate seria sobre três caminhos fantasmas.
        $gate07Ausentes = array_values(array_filter($gate07, static fn (string $a) => ! file_exists(base_path($a))));
        $this->assertSame([], $gate07Ausentes, 'um dos três artefatos do GATE-07 não existe no disco — o gate estaria sobre caminho fantasma');
    }

    /**
     * ⚠️⚠️ **PUB-09 / decisão 1 — O SEGUNDO PASSO NÃO REAPARECEU, EM NENHUM DOS CINCO ARQUIVOS.**
     *
     * As waves 02, 03 e 04 rodaram esta sonda cada uma sobre a **sua** lista. Este é o único lugar
     * que a roda sobre a **UNIÃO** dos cinco arquivos de produção da fase.
     *
     * ⚠️ **A SONDA É A UNIÃO DAS DUAS FORMAS QUE JÁ EXISTEM, e isso foi MEDIDO:** a do gate alheio
     * `186-09 sem-segundo-passo` **não** casa a forma pt-BR acentuada com espaço (*"Confirmar
     * publicação"*) — só `confirmar_publicacao`/`confirmarPublicacao` —, e a do `188-05` faz o
     * **inverso**. ⚠️ **Nenhuma das duas, isolada, cobre as nove formas.**
     *
     * ⚠️ **BORDA À ESQUERDA SÓ** em `desfazer`/`undo`, e é medição, não gosto: `undo` é substring de
     * *"segundo"*, *"fundo"* e *"profundo"*, e borda nos **DOIS** lados cegaria a sonda para
     * `desfazerAprovacao`, que é a forma que o defeito teria.
     *
     * ⚠️ O gate lê o código **SEM COMENTÁRIO**: a prosa desta fase cita as nove formas **de
     * propósito**, e um gate que lesse os comentários **reprovaria pelo próprio conserto**.
     */
    public function test_nao_existe_segundo_passo_em_nenhum_arquivo_da_fase_193_05(): void
    {
        $sonda = '/(confirmar[\s_\-]*publica(?:ção|cao|tion)|confirmarPublicacao'
            . '|segundo[\s_\-]*passo|segundoPasso|second[\s_\-]*step'
            . '|(?<![\p{L}\p{N}])desfazer|(?<![\p{L}\p{N}])undo'
            . '|publicar[\s_\-]*aprovado|publicarAprovado'
            . '|cancelar[\s_\-]*(?:a[\s_\-]*)?aprova(?:ção|cao)|cancelarAprovacao)/ui';

        // ⚠️⚠️ ISCA (+), COLETORA e **antes** da varredura: sonda furada torna o `assertSame([], …)`
        // seguinte vazio por construção.
        $devemCasar = [
            'confirmar publicação (prosa acentuada)' => "'rotulo' => 'Confirmar publicação'",
            'confirmarPublicacao (camelCase)' => 'public function confirmarPublicacao() {}',
            'segundo_passo (snake_case)' => "const SEGUNDO_PASSO = 'x';",
            'segundoPasso (camelCase)' => 'private bool $segundoPasso = true;',
            'second step (en)' => "'flow' => 'second step'",
            'a forma de voltar atrás (camelCase)' => 'function desfazerAprovacao() {}',
            'undo com _ em volta' => 'const JANELA_DE_UNDO = 5;',
            'publicar_aprovado' => "Route::post('/publicar_aprovado');",
            'publicarAprovado' => 'public function publicarAprovado() {}',
            'cancelar aprovação (prosa acentuada)' => "'rotulo' => 'Cancelar aprovação'",
            'cancelarAprovacao (camelCase)' => 'public function cancelarAprovacao() {}',
        ];
        $naoCasaram = array_keys(array_filter($devemCasar, static fn (string $t) => preg_match($sonda, $t) !== 1));
        $this->assertSame([], $naoCasaram, 'ISCA (+): a sonda não casa formas que ela existe para pegar');

        // ⚠️⚠️ ISCA (−): o rótulo LEGÍTIMO (inalterado por decisão do usuário) e as palavras pt-BR
        // comuns que CONTÊM `undo` não podem ser acusadas.
        $legitimos = ['Aprovar para publicação', 'o fundo do card', 'de forma mais profunda', 'o segundo lugar da fila'];
        $acusadosAToa = array_values(array_filter($legitimos, static fn (string $t) => preg_match($sonda, $t) === 1));
        $this->assertSame([], $acusadosAToa, 'ISCA (−): a sonda reprovaria texto legítimo — ela está mais larga que o invariante');

        // ─── A VARREDURA, no código SEM COMENTÁRIO, sobre a UNIÃO dos cinco ───────────────
        $infratores = [];
        $iscaDoLeitor = 0;
        foreach (self::PHP_DA_FASE as $rel) {
            $cru = (string) file_get_contents(base_path($rel));
            if (preg_match_all($sonda, self::semComentarios($cru)) > 0) {
                $infratores[] = $rel;
            }
            $iscaDoLeitor += preg_match_all($sonda, $cru);
        }
        $this->assertSame([], $infratores, 'PUB-09 / decisão 1: o segundo passo da aprovação reapareceu em código — APROVAR JÁ PUBLICA, sem segundo clique e sem desfazer');
        $this->assertGreaterThan(0, $iscaDoLeitor, 'a prosa dos cinco arquivos deixou de citar as formas proibidas — a isca do leitor perdeu o sujeito e o gate não prova mais que lê SEM comentário');

        // ─── ⚠️ UM CAMINHO SÓ (PUB-09): nunca os dois ao mesmo tempo ─────────────────────
        $porta = self::semComentarios($this->ler('app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php'));
        $this->assertSame(1, substr_count($porta, '->enfileirar('), 'a única porta chama o enfileiramento um número de vezes diferente de 1 — "nunca os dois caminhos ao mesmo tempo"');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // id de gate `bijecao-dos-cinco-manifestos`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A BIJEÇÃO DOS CINCO MANIFESTOS, NOS DOIS SENTIDOS — e este é o único lugar que vê os
     * cinco pares.**
     *
     * Três modos de "passar por omissão", todos medidos nesta milestone:
     *   · **método sem id** — ele **nunca roda** (foi o sobrevivente da mutação do plano 186-03);
     *   · **id órfão de `<verify>`** — ele **não falha: nunca roda**, e é indistinguível de verde
     *     (a revisão da Fase 188 achou **nove**);
     *   · **homônimo** — o `--filter` do PHPUnit é regex **NÃO ANCORADO** e as Fases 186/187/188
     *     estão **em produção**: método de outra fase que seja **superstring** de um meu faria o id
     *     **somar** e reprovar.
     *
     * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, ISCADO NAS DUAS DIREÇÕES ANTES DE VALER:** a regex ingênua
     * `/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b|c)` — o furo que deu `SEM_ID=14`
     * **acusando trabalho correto**.
     */
    public function test_a_bijecao_dos_cinco_manifestos_nos_dois_sentidos_com_homonimo_193_05(): void
    {
        // ─── O EXPANSOR, iscado NAS DUAS DIREÇÕES antes de valer ──────────────────────────
        $expandir = static function (string $filtro): array {
            if (preg_match('/^test_\((.+)\)$/', $filtro, $m) === 1) {
                return array_map(static fn (string $p) => 'test_' . $p, explode('|', $m[1]));
            }

            return [$filtro];
        };
        $this->assertSame(['test_a', 'test_b', 'test_c'], $expandir('test_(a|b|c)'), 'ISCA (+): o expansor não abre a alternância');
        $this->assertSame(['test_abc'], $expandir('test_abc'), 'ISCA (−): o expansor inventou nomes num filtro sem alternância');
        $this->assertSame(0, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a|b|c)'), 'a regex ingênua passou a casar alternância: a razão do expansor mudou');
        $this->assertSame(1, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_abc'), 'a regex ingênua deixou de casar nome simples: a isca perdeu o sujeito');

        // ─── A varredura dos cinco manifestos × cinco planos ─────────────────────────────
        $todosOsIds = [];
        $doManifesto = [];
        $comZero = [];
        $semTests = [];
        $repetidos = [];
        $acimaDeTres = [];
        $contagemErrada = [];
        $orfaos = [];
        $naoDeclarados = [];
        $planosAusentes = [];

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $plano = base_path(self::DIR_FASE . '/193-' . $nn . '-PLAN.md');
            if (! file_exists($plano)) {
                $planosAusentes[] = '193-' . $nn . '-PLAN.md';

                continue;
            }
            $entradas = $this->entradasDoManifesto($n);
            $ids = array_column($entradas, 'id');
            $vistos = [];
            foreach ($entradas as $e) {
                $todosOsIds[] = '193-' . $nn . ' / ' . $e['id'];
                if ($e['tests'] === '') {
                    $semTests[] = '193-' . $nn . ' / ' . $e['id'];
                } elseif ($e['tests'] === '0') {
                    $comZero[] = '193-' . $nn . ' / ' . $e['id'];
                }
                if (isset($vistos[$e['id']])) {
                    $repetidos[] = '193-' . $nn . ' / ' . $e['id'];
                }
                $vistos[$e['id']] = true;

                if ($e['tipo'] !== 'php') {
                    continue;
                }
                $metodos = $expandir($e['filtro']);
                if (count($metodos) > 3) {
                    $acimaDeTres[] = '193-' . $nn . ' / ' . $e['id'] . ' (' . count($metodos) . ' métodos)';
                }
                if ((string) count($metodos) !== $e['tests']) {
                    $contagemErrada[] = '193-' . $nn . ' / ' . $e['id'] . ' declara tests=' . $e['tests'] . ' com ' . count($metodos) . ' método(s)';
                }
                foreach ($metodos as $metodo) {
                    $doManifesto[$metodo] = '193-' . $nn;
                }
            }

            $doVerify = self::idsDoVerify((string) file_get_contents($plano), $nn);
            foreach (array_diff($ids, $doVerify) as $id) {
                $orfaos[] = $id;
            }
            foreach (array_diff($doVerify, $ids) as $id) {
                $naoDeclarados[] = '193-' . $nn . ' / ' . $id;
            }
        }

        $this->assertSame([], $planosAusentes, 'plano da Fase 193 ausente — não há como medir o par plano × manifesto');
        $this->assertSame([], $semTests, 'id sem `tests=` — o harness ABORTA, e é de propósito');
        $this->assertSame([], $comZero, 'id com `tests=0` — não é contagem, é número de fora');
        $this->assertSame([], $repetidos, 'id repetido no mesmo manifesto — o harness pega o PRIMEIRO e o segundo NUNCA roda');
        $this->assertSame([], $acimaDeTres, 'id com mais de TRÊS métodos — alternância grande equivale a filtro de classe, passa dos 120 s e soma coisas sem relação');
        $this->assertSame([], $contagemErrada, 'id cujo `tests=` não bate com a quantidade de métodos do filtro');
        $this->assertSame([], $naoDeclarados, 'id usado num `<verify>` e NÃO declarado no manifesto: o harness ABORTA');
        $this->assertGreaterThanOrEqual(20, count($todosOsIds), 'os cinco manifestos juntos declaram quase nenhum id — gate sobre ZERO casos não prova nada');

        // ⚠️⚠️ ÓRFÃOS: o conjunto é CONGELADO e DECLARADO (ver o docblock da constante). Órfão NOVO
        // reprova; os cinco conhecidos estão escritos, com dono. **Apagar um da lista para passar é
        // o conserto que o learnings proíbe.**
        $orfaos = array_values(array_unique($orfaos));
        sort($orfaos);
        $esperados = self::ORFAOS_DECLARADOS;
        sort($esperados);
        $this->assertSame($esperados, $orfaos, 'o conjunto de ids ÓRFÃOS de `<verify>` mudou: id órfão NÃO falha — ele simplesmente nunca roda, e parece verde');

        // ─── ⚠️ MÉTODO SEM id, nos DOIS sentidos, sobre toda a suíte ─────────────────────
        // ⚠️ Por TOKEN, nunca por regex sobre o texto cru — ver o docblock de
        // `declaracoesDeMetodo()`: há isca de outra wave que, como TEXTO, parece declaração.
        $declarados = [];
        $todasAsDeclaracoes = [];
        foreach (self::arquivosDeTeste() as $arquivo) {
            $nomes = self::declaracoesDeMetodo((string) file_get_contents($arquivo));
            foreach ($nomes as $metodo) {
                $todasAsDeclaracoes[$metodo] = basename($arquivo);
                if (preg_match('/^test_[A-Za-z0-9_]*_193_0[1-5]$/', $metodo) === 1) {
                    $declarados[$metodo] = basename($arquivo);
                }
            }
        }
        $naSuite = array_keys($declarados);
        sort($naSuite);
        $nosManifestos = array_keys($doManifesto);
        sort($nosManifestos);
        $this->assertSame($naSuite, $nosManifestos, 'bijeção quebrada: há método `_193_0N` fora de todo manifesto (ele NUNCA roda) ou id apontando para método que não existe (ele também nunca roda)');

        // ⚠️ SUFIXO: asserção COLETORA, nunca `grep -c` (conta LINHAS).
        $semSufixo = array_values(array_filter($naSuite, static fn (string $m) => preg_match('/_193_0[1-5]$/', $m) !== 1));
        $this->assertSame([], $semSufixo, 'método da fase sem o sufixo `_193_0N`: o `--filter` do PHPUnit é regex NÃO ANCORADO e as Fases 186/187/188 estão em produção');

        // ─── ⚠️ HOMÔNIMO, incluindo PREFIXO/SUPERSTRING, nas duas direções ───────────────
        // O `--filter` do PHPUnit é regex NÃO ANCORADO: método de outra suíte que CONTENHA um nome
        // meu faria o id SOMAR e reprovar. ⚠️ Medido sobre DECLARAÇÕES REAIS (por token).
        $meus = array_flip($naSuite);
        $homonimos = [];
        foreach ($todasAsDeclaracoes as $nome => $arquivo) {
            if (isset($meus[$nome])) {
                continue;
            }
            foreach ($naSuite as $meu) {
                if ($nome !== $meu && str_contains($nome, $meu)) {
                    $homonimos[] = $arquivo . '::' . $nome;
                }
            }
        }
        $this->assertSame([], $homonimos, 'há método REALMENTE DECLARADO que o `--filter` NÃO ANCORADO de um id desta fase também casaria (superstring/prefixo)');

        // ⚠️ ISCA (+) do extrator: uma declaração REAL é vista.
        $umMeu = 'test_a_bijecao_dos_cinco_manifestos_nos_dois_sentidos_com_homonimo_193_05';
        $this->assertContains($umMeu, $naSuite, 'o nome usado na isca do homônimo deixou de existir — a isca perdeu o sujeito');
        $this->assertContains(
            $umMeu . '_extra',
            self::declaracoesDeMetodo('<?php class X { public function ' . $umMeu . '_extra() {} }'),
            'ISCA (+): o extrator por token não vê uma declaração real de superstring'
        );
        // ⚠️⚠️ ISCA (−) DO EXTRATOR, E ELA É A QUE CONSERTOU UM FURO MEU NESTA WAVE: o MESMO nome,
        // **dentro de uma string**, NÃO é declaração. A primeira redação lia o texto cru e acusou a
        // isca (+) legítima da wave 03 (`ReconciliarPublicacaoDoPortaoTest.php:1569`).
        $this->assertSame(
            [],
            array_values(array_filter(
                self::declaracoesDeMetodo('<?php $isca = \'public function ' . $umMeu . '_extra() {}\';'),
                static fn (string $n) => str_contains($n, '_193_05')
            )),
            'ISCA (−): o extrator conta um nome que está DENTRO DE UMA STRING como declaração — é a família "gate mais largo que o invariante"'
        );
        // ⚠️⚠️ E O NOME ALHEIO ENTRA POR **VARIÁVEL**, nunca como literal: escrito como literal, o
        // texto deste arquivo ganharia a **FORMA DE UMA DECLARAÇÃO** e o gate alheio
        // `186-09 bijecao-e-homonimo` — cuja sonda é `/public function (test_[A-Za-z0-9_]+)\s*\(/`
        // sobre o código SEM COMENTÁRIO — o leria como *"este arquivo declara um método que já
        // existe em outra suíte"* e REPROVARIA. Foi o furo 1 da wave 04, e aqui ele é evitado
        // **preventivamente** (medido: aquele gate seguiu em 1/31).
        $alheio = 'test_tentar_de_novo_nao_publica_no_mercado_livre_com_isca_188_05';
        $this->assertSame(
            [],
            array_values(array_filter(
                self::declaracoesDeMetodo('<?php class X { public function ' . $alheio . '() {} }'),
                static fn (string $n) => in_array($n, $naSuite, true)
            )),
            'ISCA (−): o varredor acusa nome legítimo de outra fase como se fosse meu'
        );

        // ─── ⚠️⚠️ O GATE QUE EU PAGUEI PARA APRENDER: ESTE ARQUIVO NÃO ESCREVE A FORMA DE UMA
        //           DECLARAÇÃO DE NOME ALHEIO — NEM EM COMENTÁRIO ───────────────────────────
        //
        // Os varredores de homônimo das waves 02/03/04 e o gate alheio `186-09 bijecao-e-homonimo`
        // leem o **TEXTO CRU** do arquivo com `/function\s+([A-Za-z0-9_]*<nome>[A-Za-z0-9_]*)\s*\(/`.
        // ⚠️ MEDIDO nesta wave: a primeira redação do docblock de `declaracoesDeMetodo()` **citava o
        // literal inteiro** da isca da wave 03, e a suíte completa acusou **1 regressão MINHA** —
        // aquele gate leu o meu COMENTÁRIO como declaração. ⚠️ É o **furo 1 da wave 04 ao
        // contrário** (lá, numa asserção; aqui, na prosa). ➡️ Gate PRÓPRIO, para o próximo executor
        // não repetir: **nome alheio nunca ao lado da palavra-chave de declaração, nem em
        // comentário** — ele entra por VARIÁVEL.
        // ⚠️⚠️ SÓ NOMES DE **TESTE** (`test_…`), e isto é conserto de um furo que eu cometi e medi
        // no mesmo minuto: a primeira redação varria **todas** as declarações da suíte, inclusive
        // helpers de nome comum em pt-BR (`linha`, `gate`, `rota`, `conta`, `ler`, `passo`…). Como
        // eles são **substring dos MEUS PRÓPRIOS nomes de método**, o gate acusou **14 nomes** — e
        // todos eram as minhas próprias declarações. ➡️ É a família *"gate mais largo que o
        // invariante"* (quatorze ocorrências nesta milestone, uma delas **barrou um deploy**), e o
        // invariante de verdade é estreito: o `--filter` do PHPUnit só colide com **métodos de
        // teste**, que são os únicos que ele roda.
        $meuTextoCru = (string) file_get_contents(__FILE__);
        $formaDeDeclaracao = [];
        foreach (array_keys($todasAsDeclaracoes) as $nome) {
            if (! str_starts_with($nome, 'test_') || isset($meus[$nome]) || ! str_contains($meuTextoCru, $nome)) {
                continue;
            }
            if (preg_match('/function\s+[A-Za-z0-9_]*' . preg_quote($nome, '/') . '[A-Za-z0-9_]*\s*\(/', $meuTextoCru) === 1) {
                $formaDeDeclaracao[] = $nome;
            }
        }
        $this->assertSame([], $formaDeDeclaracao, 'este arquivo escreve a FORMA DE UMA DECLARAÇÃO de um método de outra suíte (mesmo em comentário ou string): os varredores de homônimo das outras waves leem o TEXTO CRU e vão acusar isto como declaração duplicada');

        // ⚠️ ISCA (+) do gate acima, nas duas pontas: ele PEGA a forma proibida e NÃO pega a citação
        // do nome sozinho (que é legítima e necessária — a prosa precisa poder nomear o caso).
        $sondaAlheia = '/function\s+[A-Za-z0-9_]*' . preg_quote($alheio, '/') . '[A-Za-z0-9_]*\s*\(/';
        $this->assertSame(1, preg_match($sondaAlheia, 'public ' . 'function ' . $alheio . '_x() {}'), 'ISCA (+): a sonda da forma de declaração está cega');
        $this->assertSame(0, preg_match($sondaAlheia, 'o caso ' . $alheio . ' da Fase 188 mede a ausência de escrita'), 'ISCA (−): a sonda proíbe CITAR o nome, e citar é legítimo — ela está mais larga que o invariante');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // id de gate `fechamento-de-forma-fixa`
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O documento existe, não é esqueleto, e traz as **6** linhas de forma fixa.
     *
     * ⚠️ `file_exists` sozinho seria satisfeito por **arquivo vazio** — e esqueleto vazio é
     * exatamente o modo de falhar deste plano (**três agentes caíram na Fase 187**, um deixando 2/3
     * de um plano sem commit). Por isso há piso de tamanho **e** a exigência das 6 linhas.
     */
    public function test_o_fechamento_193_existe_e_tem_as_seis_linhas_de_baseline_193_05(): void
    {
        $texto = $this->fechamento();

        $this->assertGreaterThan(
            300,
            substr_count($texto, "\n"),
            'o 193-FECHAMENTO.md está curto demais para ser o fechamento medido de 10 critérios — esqueleto passa calado'
        );

        $ausentes = array_values(array_filter(
            self::LINHAS_DE_BASELINE,
            static fn (string $linha) => preg_match('/^' . preg_quote($linha, '/') . '/m', $texto) !== 1
        ));
        $this->assertSame([], $ausentes, 'faltam linhas de baseline em forma fixa (ancoradas em INÍCIO DE LINHA)');

        // ⚠️ ISCA do ancoramento: uma linha de baseline INVENTADA não pode ser encontrada — senão a
        // sonda está casando o meio de uma frase em vez do começo da linha.
        $this->assertSame(0, preg_match('/^NAOEXISTE9Z7:/m', $texto), 'a sonda das baselines aceitou uma linha inventada: ela não está ancorada');

        // ⚠️ O `sha256` ao lado do protótipo é o que prova que o documento de referência não se
        // moveu; a palavra `PROTOTIPO` também aparece no meio de frases, e a sonda tem de distinguir.
        $this->assertGreaterThanOrEqual(1, preg_match_all('/^PROTOTIPO[^\n]*sha256/m', $texto), 'a linha PROTOTIPO existe, mas sem o sha256 ao lado ela não prova que o protótipo não se moveu');

        // ⚠️ E a declaração que o leitor futuro precisa para não supor o que não houve: a suíte PHP
        // **inteira** não é medível nesta máquina.
        //
        // ⚠️⚠️ A sonda é `/iu` e com `[^\n]{0,40}` no meio, e as duas coisas foram MEDIDAS: a frase
        // real do documento é *"A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL"* — **maiúscula**, com
        // `**COMPLETA**` (13 chars de markdown) entre `PHP` e `NÃO`. A primeira redação exigia
        // minúscula e vizinhança imediata, e **reprovou o documento correto**: é o lado ESTREITO da
        // mesma família (*"a frase quebra no markdown e a sonda não"*).
        $semMedivel = '/su[\x{00ed}i]te\s+PHP[^\n]{0,40}n[\x{00e3}a]o\s+[\x{00e9}e]\s+med[\x{00ed}i]vel/iu';
        $this->assertMatchesRegularExpression($semMedivel, $texto, 'falta a declaração de que a suíte PHP COMPLETA não é medível nesta máquina — sem ela o leitor supõe que a suíte inteira passou');
        // ⚠️ ISCA (−): a sonda NÃO pode casar a afirmação contrária.
        $this->assertSame(0, preg_match($semMedivel, 'a suite PHP completa e medivel nesta maquina'), 'ISCA (−): a sonda casa a afirmação CONTRÁRIA — ela está medindo a palavra, não a negativa');
        $this->assertMatchesRegularExpression('/RECORTE/u', $texto, 'o documento não diz que o número de PHP é o RECORTE');
    }

    /**
     * ⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** São **10** critérios, numerados
     * `01`..`10` **sem buraco e sem repetição** — e `20` é a Fase 188, `17` a 186, `15` a 187, `8` a
     * 189. As entradas são vizinhas no `ROADMAP.md`, e um `awk` errado traz a fase errada **em
     * silêncio**.
     *
     * ⚠️ **E o critério NUNCA é reescrito para caber.** Se algo não passa, ele recebe `RESSALVA` ou
     * `REPROVA` e o fechamento **devolve** — não conserta por conta própria.
     */
    public function test_os_dez_criterios_193_estao_em_forma_fixa_com_placar_que_fecha_193_05(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): ([A-Z]+) — ([^\n]*)$/m', $texto, $m);
        $numeros = array_map('intval', $m[1]);

        $this->assertSame(range(1, self::CRITERIOS), $numeros, 'os veredictos não formam 01..10 em ordem, sem buraco e sem repetição (20 é a Fase 188, 17 é a 186, 15 é a 187)');

        $foraDaLista = array_values(array_diff(array_unique($m[2]), self::VEREDICTOS));
        $this->assertSame([], $foraDaLista, 'veredicto fora da lista FECHADA OK/RESSALVA/REPROVA — e "OK com ressalva" é exatamente o que a forma fixa existe para impedir');

        // ⚠️ Cada linha tem de nomear a PROVA. Veredicto sem prova é opinião.
        $semProva = [];
        $ressalvaSemDono = [];
        $reprovados = [];
        foreach ($m[1] as $i => $numero) {
            if (preg_match('/prova:\s*\S/u', $m[3][$i]) !== 1) {
                $semProva[] = $numero;
            }
            if ($m[2][$i] === 'RESSALVA' && preg_match(self::SONDA_DO_DONO, $m[3][$i]) !== 1) {
                $ressalvaSemDono[] = $numero;
            }
            if ($m[2][$i] === 'REPROVA') {
                $reprovados[] = $numero;
            }
        }
        $this->assertSame([], $semProva, 'critério cujo veredicto não nomeia a prova (`prova:`) que o sustenta');
        $this->assertSame([], $ressalvaSemDono, 'RESSALVA sem dono nomeado na PRÓPRIA linha — ressalva sem dono é dívida que ninguém herda');
        $this->assertSame([], $reprovados, 'há critério REPROVADO — o fechamento PARA com REPROVA, não o registra e segue');

        // ⚠️ O critério 10 tem **ressalva obrigatória**, e a razão é MEDIDA: ele é checkpoint humano
        // em produção, e `pub_aprovacao_ofertas` está **vazia**. Escrevê-lo como `OK` seria afirmar
        // entrega que não houve.
        $this->assertMatchesRegularExpression('/^CRIT-10: RESSALVA — /m', $texto, 'o CRIT-10 tem de sair como RESSALVA: a validação de ponta a ponta é impossível nesta máquina');

        // ⚠️ ISCA do ancoramento: `CRIT-99` ancorado tem de ser recusado pela igualdade acima, e um
        // `CRIT-` no meio de frase não pode entrar na contagem.
        $this->assertSame(0, preg_match('/^CRIT-99: /m', $texto), 'a sonda dos critérios aceitou um número fora de 01..10');

        // A regra escrita, com `\s+` porque a frase QUEBRA no markdown de 100 colunas (procurá-la
        // com espaço literal foi o lado ESTREITO do mesmo erro, medido no fechamento da 187).
        $this->assertMatchesRegularExpression('/nunca\s+(é|e)\s+reescrito/iu', $texto, 'falta a regra escrita de que critério que não passa recebe RESSALVA/REPROVA e NUNCA é reescrito para caber');

        // O placar, por forma fixa — e ele tem de FECHAR em 10.
        $this->assertMatchesRegularExpression('/PLACAR-193:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u', $texto, 'falta a linha de forma fixa PLACAR-193: OK=N RESSALVA=N REPROVA=N');
        preg_match('/PLACAR-193:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u', $texto, $p);
        $this->assertSame(self::CRITERIOS, (int) $p[1] + (int) $p[2] + (int) $p[3], 'o placar não fecha em 10 — placar que não soma é placar que não foi contado');
        $this->assertSame(count(array_filter($m[2], static fn (string $v) => $v === 'OK')), (int) $p[1], 'o OK= do placar não bate com os veredictos OK em forma fixa');
        $this->assertSame(count(array_filter($m[2], static fn (string $v) => $v === 'RESSALVA')), (int) $p[2], 'o RESSALVA= do placar não bate com os veredictos em forma fixa');
    }

    /**
     * As perguntas **todas com DONO**; as **7** decisões do usuário com **endereço**; os itens
     * devolvidos com **destino**; o **roteiro da conta 459** como roteiro, não como menção; e a
     * declaração de que **ninguém viu tela**.
     *
     * ⚠️⚠️ **`a definir` NÃO é dono.** E a sobrevivente **M6** da Fase 187 provou que uma **MENÇÃO**
     * a *"conta 459"* **não prova o ROTEIRO**: menção é lista de nomes, roteiro é comportamento.
     * ➡️ O roteiro é recortado à **sua seção** (contar itens numerados no documento inteiro mediu
     * **23** em vez de 8 na 187 e **reprovou trabalho certo**), com itens **sem buraco**.
     */
    public function test_as_perguntas_193_tem_dono_e_o_roteiro_da_conta_459_e_roteiro_193_05(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^PERGUNTA-([A-Z0-9-]+): ([A-Z]+)([^\n]*)$/m', $texto, $m);

        $semDono = [];
        foreach ($m[1] as $i => $id) {
            if (preg_match(self::SONDA_DO_DONO, $m[3][$i]) !== 1) {
                $semDono[] = $id;
            } elseif (preg_match('/dono:\s*a\s+definir/iu', $m[3][$i]) === 1) {
                $semDono[] = $id . ' (a definir NÃO é dono)';
            }
        }
        $this->assertSame([], $semDono, 'perguntas sem dono — ou com "a definir", que NÃO é dono');

        // ⚠️⚠️ ISCA (+) E (−) DA SONDA DO DONO, nas duas direções — ver o docblock de
        // `SONDA_DO_DONO`: a primeira redação sem borda deixou a mutação **m3 SOBREVIVER**.
        $this->assertSame(1, preg_match(self::SONDA_DO_DONO, 'texto · dono: **usuário**'), 'ISCA (+): a sonda do dono não vê um dono legítimo');
        $this->assertSame(0, preg_match(self::SONDA_DO_DONO, 'texto · prova-no-dono: **usuário**'), 'ISCA (−): a sonda do dono casa `dono:` no MEIO de outra palavra — foi a sobrevivente m3 da campanha de mutação desta wave');

        // ⚠️ LISTA CONSTANTE, nunca piso: piso NÃO prova PRESENÇA.
        $faltando = array_values(array_diff(self::PERGUNTAS_OBRIGATORIAS, $m[1]));
        $this->assertSame([], $faltando, 'perguntas obrigatórias ausentes do fechamento');
        $this->assertNotContains('NAOEXISTE-9Z7', $m[1], 'a sonda das perguntas aceitou um id inventado');

        // ─── ⚠️ AS 7 DECISÕES DO USUÁRIO (20 a 26), com ENDEREÇO ─────────────────────────
        preg_match_all('/^DECISAO-(\d{2}): ([^\n]*)$/m', $texto, $d);
        $this->assertSame(self::DECISOES, array_map('intval', $d[1]), 'as linhas DECISAO-NN não cobrem 20..26 em ordem, sem buraco e sem repetição');
        $semEndereco = [];
        foreach ($d[1] as $i => $numero) {
            if (preg_match('/onde:\s*\S/u', $d[2][$i]) !== 1) {
                $semEndereco[] = $numero;
            }
        }
        $this->assertSame([], $semEndereco, 'decisão do usuário registrada SEM endereço (`onde:`) — decisão sem endereço é decisão que a próxima fase reabre');

        // ─── ⚠️ OS ITENS DEVOLVIDOS, com DESTINO ────────────────────────────────────────
        preg_match_all('/^DEVOLVIDO-([A-Z0-9-]+): ([^\n]*)$/m', $texto, $v);
        $faltandoDevolvido = array_values(array_diff(self::DEVOLVIDOS, $v[1]));
        $this->assertSame([], $faltandoDevolvido, 'item devolvido por uma wave que o fechamento não registra');
        $semDestino = array_values(array_filter($v[2], static fn (string $l) => preg_match('/destino:\s*\S/u', $l) !== 1));
        $this->assertSame([], $semDestino, 'item devolvido sem `destino:` — item sem destino volta a ser trabalho perdido');

        // ─── ⚠️ NINGUÉM VIU TELA, E NÃO HOUVE PUBLICAÇÃO REAL ───────────────────────────
        $this->assertMatchesRegularExpression('/nenhum\s+executor/iu', $texto, 'o documento não declara que NENHUM EXECUTOR desta fase viu tela renderizada');
        $this->assertMatchesRegularExpression('/viu\s+tela\s+renderizada/iu', $texto, 'falta a frase "viu tela renderizada" — ela é o que impede ler as SUMMARYs como relato de tela');
        $this->assertMatchesRegularExpression('/Http::fake/u', $texto, 'o documento não diz COMO a prova foi feita (Http::fake + preventStrayRequests), e sem isso a declaração é slogan');

        // ⚠️ ISCA (−) do quase-igual, na direção que importa: o documento NÃO pode AFIRMAR que a
        // tela foi vista ou que houve publicação real. A sonda é da AFIRMAÇÃO, não da palavra
        // "tela" (que aparece legitimamente na negativa e no roteiro).
        $this->assertSame(0, preg_match('/a\s+tela\s+foi\s+(vista|conferida)/iu', $texto), 'o documento afirma que a tela foi vista — nenhum executor pode afirmar isso');
        $this->assertSame(0, preg_match('/houve\s+publica(ção|cao)\s+real/iu', $texto), 'o documento afirma que houve publicação real');
        // ⚠️ E a prova de que a sonda acima NÃO é cega.
        $this->assertSame(1, preg_match('/a\s+tela\s+foi\s+(vista|conferida)/iu', 'declaro que a tela foi vista'), 'ISCA (+): a sonda da afirmação proibida está cega');

        // ⚠️ As conferências visuais anteriores continuam ABERTAS e SEPARADAS — presumi-las feitas é
        // o mesmo erro com outra roupa.
        foreach (['187', '188', '189'] as $fase) {
            $this->assertMatchesRegularExpression('/PERGUNTA-VISUAL-' . $fase . ':\s*ABERTA/u', $texto, "a conferência visual da Fase {$fase} tem de continuar ABERTA — esta fase não a substitui");
        }

        // ─── O ROTEIRO, recortado à SUA seção ──────────────────────────────────────────
        $roteiro = $this->secao($texto, 'ROTEIRO DA CONFERÊNCIA VISUAL');

        // ⚠️ ISCA do recorte, nas DUAS direções: não está vazio E é menor que o documento.
        $this->assertNotSame('', $roteiro, 'o recorte da seção do roteiro saiu VAZIO — recorte que não recorta mede NADA');
        $this->assertLessThan(strlen($texto), strlen($roteiro), 'o recorte engoliu o documento inteiro — é o sintoma do `.` sob a flag /s');

        preg_match_all('/^\s*(\d+)\. /m', $roteiro, $r);
        $itens = array_map('intval', $r[1]);
        $this->assertSame(range(1, count($itens)), $itens, 'os itens do roteiro não são numerados de 1 a N sem buraco e sem repetição');
        $this->assertGreaterThanOrEqual(8, count($itens), 'o roteiro é curto demais para cobrir o ciclo que ele diz cobrir');

        $this->assertMatchesRegularExpression('/conta\s+459/u', $roteiro, 'o roteiro não nomeia a conta 459');
        // ⚠️ `\s+` entre as palavras: a frase QUEBRA no markdown de 100 colunas.
        $this->assertMatchesRegularExpression('/sem\s+o?\s*MLB[^\n]*REPROVA|REPROVA[^\n]*sem\s+o?\s*MLB/iu', $roteiro, 'o roteiro não diz com todas as letras que "Publicado" SEM o MLB é REPROVA — é o defeito central que a fase existe para evitar');
        $this->assertMatchesRegularExpression('/n[ãa]o\s+h[áa]\s+desfazer|sem\s+desfazer/iu', $roteiro, 'o roteiro não avisa que aprovar NÃO TEM DESFAZER — é o aviso que a pessoa precisa ANTES de clicar');
        $this->assertMatchesRegularExpression('/Fase\s+194|piloto\s+do\s+agente/iu', $roteiro, 'o roteiro não declara de que ele DEPENDE para ser executável (a primeira oferta)');

        // ⚠️ A marca de linha única da pergunta visual desta fase — e aqui `-c` contando LINHAS é
        // exatamente o que se quer.
        $this->assertSame(1, preg_match_all('/^PERGUNTA-VISUAL-193: ABERTA — dono: usuário$/mu', $texto), 'a marca de forma fixa `PERGUNTA-VISUAL-193: ABERTA — dono: usuário` tem de aparecer em UMA linha própria, exatamente uma vez');
    }
}
