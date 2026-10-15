import test from 'node:test';
import assert from 'node:assert/strict';
// ⚠️ `existsSync`/`statSync` entraram na Fase 186, plano 02: o gate dos DOIS
// EIXOS precisa distinguir "diretório varrido e limpo" de "diretório que não
// existe" — o segundo vira `skip` NOMEADO, nunca silêncio.
import { readdirSync, existsSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — GATE-03: o vocabulário NOVO, fechado.
//
// POR QUE EXISTE: a instrução do usuário (11/10/2026) foi "reproduza fielmente
// o layout, já tentei fazer um redesign e não saiu como eu esperava PORQUE VOCÊ
// ADAPTAVA MUITO O NOVO LAYOUT AO SISTEMA ATUAL". Este gate é a trava mecânica
// contra essa adaptação: nos arquivos desenhados nesta milestone, 13,5px tem de
// ser `text-[13.5px]` e não `text-sm`; a cor vem por NOME de token `pub-*` e
// não por hex; `ecf-bg`, `ecf-card`, `ecf-yellow`, `font-display` e
// `border-white/[0.08]` estão vetados (FID-04).
//
// É o irmão novo de `publicador-entrada.test.js`: aquele trava o que já existe,
// este trava o que nasce agora.
//
// ⚠️ A lista é uma CONSTANTE, não um glob. Glob pegaria arquivo que ainda não
// nasceu e o gate passaria por omissão — os planos 177-04/05/06 ACRESCENTAM os
// arquivos do header a esta lista quando eles existirem.
//
// ⚠️ Toda leitura passa por `lerSemComentarios`: a prosa em pt-BR deste projeto
// cita os próprios identificadores e os próprios hex do protótipo ("o da frente
// preenchido #FACC15"), e um gate cru passaria/reprovaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Mlb/Publicador/identidade';
const DIR_HEADER = 'resources/js/Components/Mlb/Publicador/header';
const DIR_VG = 'resources/js/Components/Mlb/Publicador/visaogeral';
// Fase 180 — o diretório da tela de Publicações, aberto pelo plano 180-05.
const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
// ⚠️⚠️ Fase 186, plano 02 — o diretório do PORTÃO de aprovação, aberto por este
// plano. Ele entra em `DIRETORIOS_FECHADOS` NO PLANO QUE CRIA O PRIMEIRO
// ARQUIVO DELE, e não no fechamento da fase: se esperasse, a área nova inteira
// ficaria FORA da conferência de vocabulário e este gate passaria CALADO, sem
// sinal nenhum. É a definição de gate que passa POR OMISSÃO (VOC-07).
const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';

const ARQUIVOS_NOVOS = [
    `${DIR}/identidade.js`,
    `${DIR}/GlyphDaFase.jsx`,
    `${DIR}/RotuloDaFase.jsx`,
    `${DIR}/SeloDoTipo.jsx`,
    `${DIR}/SeloDeStatusDaPublicacao.jsx`,
    `${DIR}/MapaDeCobertura.jsx`,
    `${DIR}/BadgeNova.jsx`,
    `${DIR}/TileDoProduto.jsx`,
    // Fase 177, plano 04 — a casca do header global e os atalhos de teclado.
    `${DIR_HEADER}/PublicadorHeader.jsx`,
    `${DIR_HEADER}/atalhosDoHeader.js`,
    // Fase 177, plano 05 — as abas-pílula da linha 2.
    `${DIR_HEADER}/AbasDoHeader.jsx`,
    // Fase 177, plano 06 — a identidade da conta na linha 1: o seletor de
    // empresa (botão + dropdown de 360px) e a pill de conexão (+ popover de
    // 320px), mais o módulo que virou a fonte única das contas recentes.
    `${DIR_HEADER}/SeletorDeEmpresaDoHeader.jsx`,
    `${DIR_HEADER}/ConexaoDoHeader.jsx`,
    'resources/js/Components/Mlb/Publicador/recentesDoPublicador.js',
    // Fase 178, plano 02 — o corpo do modal "Nova publicação": os literais de
    // tela (com a busca local) e o painel medida por medida.
    `${DIR_HEADER}/novaPublicacao.js`,
    `${DIR_HEADER}/PainelNovaPublicacao.jsx`,
    // Fase 178, plano 03 — a casca portada (620px a `top:110px`, o backdrop, o
    // fetch lazy e o foco no input).
    `${DIR_HEADER}/ModalNovaPublicacao.jsx`,
    // ─── Fase 179 — os 12 arquivos da Visão geral (cockpit) ───
    //
    // ⚠️ A lista REAL foi conferida com
    // `git diff --name-only --diff-filter=A 898d68c2..HEAD -- resources/js`
    // (11 arquivos já commitados) **mais** o `CockpitDaConta.jsx` deste plano
    // = 12. Não é o número do plano repetido: ele falava em 13, e o 13º
    // (`BlocosPreservados.jsx`) NÃO nasceu depois da decisão do usuário de
    // 12/10/2026 de tirar "Produtos por fase" e "Quem publicou" da tela.
    //
    // Plano 179-06 — a Jornada dos produtos:
    `${DIR_VG}/jornada.js`,
    `${DIR_VG}/JornadaDosProdutos.jsx`,
    // Plano 179-07 — "O que fazer agora", "Saúde da conta" e "Publicações no ML":
    `${DIR_VG}/acoes.js`,
    `${DIR_VG}/OQueFazerAgora.jsx`,
    `${DIR_VG}/SaudeDaConta.jsx`,
    `${DIR_VG}/PublicacoesNoMercadoLivre.jsx`,
    // Plano 179-08 — banner, faixa de Desempenho, recentes e atividade:
    `${DIR_VG}/recentes.js`,
    `${DIR_VG}/BannerDeIntegracao.jsx`,
    `${DIR_VG}/FaixaDeDesempenho.jsx`,
    `${DIR_VG}/PublicacoesRecentes.jsx`,
    `${DIR_VG}/AtividadeRecente.jsx`,
    // Plano 179-09 — a composição:
    `${DIR_VG}/CockpitDaConta.jsx`,
    // ─── Fase 180 — a tela de Publicações (`publicacoes/`) ───
    //
    // ⚠️ A CONTAGEM desta fase está no sub-teste "os N arquivos da Fase 180
    // estão na lista", logo abaixo, e hoje ela é 8 — os 2 do plano 180-05, que
    // é quem ABRE o diretório, mais os 3 do plano 180-06 e os 3 do plano
    // 180-07. Os planos seguintes ELEVAM o número explicitamente ao acrescentar
    // os seus, e o `deepEqual` de `DIRETORIOS_FECHADOS` é o que impede arquivo
    // novo de entrar calado.
    //
    // Plano 180-05 — o cabeçalho da página e a faixa de sucesso pós-publicação:
    `${DIR_PUB}/CabecalhoDePublicacoes.jsx`,
    `${DIR_PUB}/FaixaDeSucesso.jsx`,
    // Plano 180-06 — a busca central de 54px, o trilho de status com
    // "Rascunhos" integrada (LIST-25) e a linha de controles com o toggle de
    // visão. ⚠️ `text-[15.5px]` (o `<input>` da busca) é o PRIMEIRO uso do
    // valor que o plano 180-01 liberou em `TAMANHOS_PERMITIDOS` — conferido na
    // lista abaixo, não presumido.
    `${DIR_PUB}/BuscaDePublicacoes.jsx`,
    `${DIR_PUB}/AbasDeStatus.jsx`,
    `${DIR_PUB}/LinhaDeControles.jsx`,
    // Plano 180-07 — a linha de chips de FILTRO (as 6 famílias do protótipo +
    // "Limpar filtros"), a casca dos dois popovers e o bloco de TRIAGEM
    // (LIST-22) traduzido para o vocabulário novo.
    `${DIR_PUB}/MenuDeFiltro.jsx`,
    `${DIR_PUB}/ChipsDeFiltro.jsx`,
    `${DIR_PUB}/ChipsDeTriagem.jsx`,
    // Plano 180-08 — a TABELA: o cabeçalho do grid das 9 colunas, a linha com
    // os CINCO fundos distintos e as SETE células especiais.
    `${DIR_PUB}/CabecalhoDaTabela.jsx`,
    `${DIR_PUB}/LinhaDePublicacao.jsx`,
    `${DIR_PUB}/CelulasDaLinha.jsx`,
    // Plano 180-09 — a LINHA DE GRUPO da visão "Por produto" (58px, `flex`, que
    // NÃO herda o grid das 9 colunas), o RODAPÉ de 44px com a paginação de 50
    // preservada e os ESTADOS VAZIOS (os três honestos de hoje + o do
    // protótipo).
    `${DIR_PUB}/LinhaDeGrupoDeProduto.jsx`,
    `${DIR_PUB}/RodapeDaTabela.jsx`,
    `${DIR_PUB}/VaziosDaLista.jsx`,
    // ⚠️⚠️ Plano 180-11 — o PRIMEIRO `.js` deste diretório: a máquina de
    // teclado da lista (LIST-18). Ele entra aqui para o `deepEqual` de
    // `DIRETORIOS_FECHADOS` continuar fechando — arquivo no disco fora da lista
    // passa SEM conferência de vocabulário e o gate passaria POR OMISSÃO.
    //
    // ⚠️ E o vocabulário que este arquivo tem de respeitar é o mais severo de
    // todos: ZERO classe. O `content` do `tailwind.config.js` varre `*.jsx` e
    // **não** `*.js`, então classe escrita nele não gera CSS e NÃO EXISTE PARA
    // A TELA — com todos os testes de render passando. A proibição com nome e
    // isca vive em `publicador-publicacoes-cabecalho.test.js` (bloco TAILWIND)
    // e em `publicador-publicacoes-atalhos.test.js` (caso 7); aqui ele só
    // precisa estar DECLARADO.
    `${DIR_PUB}/atalhosDaLista.js`,
    // ⚠️⚠️ Plano 180-10 — A PÁGINA. Ela não é um componente de `publicacoes/`:
    // é `resources/js/Pages/Mlb/MeusAnuncios.jsx`, a tela de Publicações, cujo
    // CORPO passou a ser o layout novo. Entrar aqui é o REAPONTE que fecha a
    // cirurgia do plano 180-10: o arquivo SAIU do
    // `travaVocabularioVisual()` de `estrutura-meus-anuncios.test.js` (que
    // EXIGIA `text-sm`/`text-base` e PROIBIA 10/12/13px e `font-medium` — o
    // oposto do protótipo) e entrou neste gate, que é mais duro. Sem esta
    // linha a página ficaria SEM conferência de vocabulário nos DOIS gates, e
    // os dois passariam por omissão — há asserção em
    // `estrutura-meus-anuncios.test.js` cobrando exatamente a presença deste
    // caminho aqui.
    //
    // ⚠️ Ela NÃO entra em `DIRETORIOS_FECHADOS`: `Pages/Mlb/` tem página de
    // outras fases (`AnunciosHistorico`, `AnunciarMassa`, `AnunciarML`…) e
    // varrer a pasta inteira reprovaria trabalho que não é desta milestone.
    'resources/js/Pages/Mlb/MeusAnuncios.jsx',
    // ─── Fase 187, plano 03 — AS DUAS ABAS da seção A1 ───
    //
    // ⚠️ DECLARADO NO MESMO PLANO QUE CRIA O ARQUIVO, e as duas pontas disso são
    // medidas: `DIR_PUB` está em `DIRETORIOS_FECHADOS` e o GATE-03 faz
    // `assert.deepEqual(noDisco, declarados)` — esquecer a declaração reprova na
    // hora (é o comportamento certo), e declarar ANTES de o arquivo existir
    // **LANÇA** no carregamento do módulo, porque `FONTES` lê todos os caminhos.
    // É por isso que as 11 waves desta fase são sequenciais: quem escreve aqui
    // tem de ser o plano que acabou de criar o arquivo, nunca o anterior.
    //
    // ⚠️ `.jsx`, como todo o diretório: o `content` do `tailwind.config.js` varre
    // `*.jsx` e **NÃO** `*.js`. Este arquivo é o único lugar onde as classes das
    // abas existem, e num `.js` elas não gerariam CSS nenhum.
    `${DIR_PUB}/AbasDaPagina.jsx`,
    // ─── Fase 186, plano 02 — os 3 arquivos do PORTÃO de aprovação ───
    //
    // POR QUE ELES ENTRAM AGORA, e não no fim da fase: `aprovacao/` nasce neste
    // plano, e `DIRETORIOS_FECHADOS` só fecha o que está declarado aqui. Um
    // arquivo no disco fora desta lista passa SEM conferência contra `text-sm`,
    // hex cru, `ecf-*`, `font-display` e breakpoint — e o gate não acusa nada.
    //
    // ⚠️ TODOS OS TRÊS SÃO `.jsx`, sem exceção, e não é preferência de estilo:
    // `tailwind.config.js` varre `./resources/js/**/*.jsx` e **NÃO** `*.js`.
    // Classe escrita num `.js` não gera CSS e NÃO EXISTE PARA A TELA, com todo
    // teste de render passando. Os dois módulos de vocabulário carregam classe
    // (as cores dos 8 estados e dos 3 selos de origem), então um `.js` aqui
    // apagaria a cor da fila em silêncio.
    //
    // Plano 186-02 — os 8 estados de andamento interno, o resto do vocabulário
    // do portão e a pill que os desenha:
    `${DIR_APROV}/andamentoDoPortao.jsx`,
    `${DIR_APROV}/vocabularioDoPortao.jsx`,
    `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
    // ─── Fase 187, plano 04 — o CABEÇALHO da fila ───
    //
    // ⚠️ DECLARADO NO MESMO PLANO QUE CRIA O ARQUIVO, e as duas pontas disso são
    // medidas: `DIR_APROV` está em `DIRETORIOS_FECHADOS` e o GATE-03 faz
    // `assert.deepEqual(noDisco, declarados)` — esquecer a declaração reprova na
    // hora (é o comportamento certo), e declarar ANTES de o arquivo existir
    // **LANÇA** no carregamento do módulo, porque `FONTES` lê todos os caminhos.
    //
    // ⚠️ `.jsx`, como todo o diretório: o `content` do `tailwind.config.js` varre
    // `*.jsx` e **NÃO** `*.js`. Este arquivo é a única casa das classes do
    // cabeçalho da fila — num `.js` elas não gerariam CSS nenhum.
    `${DIR_APROV}/CabecalhoDaFila.jsx`,
];

/**
 * Os corpos de texto do README (§Design Tokens e as telas). FID-03 é o motivo
 * de 13.5 e 14.5 estarem aqui: o passo do Tailwind não tem meio pixel, então
 * quem arredondasse para `text-sm` (14px) mudaria o layout.
 *
 * ⚠️ Fase 179, plano 09 — `8.5` entrou, e é uma AMPLIAÇÃO da lista permitida,
 * não um endurecimento. Por isso a medida fica escrita ao lado, para a próxima
 * pessoa saber que não é arredondamento nem descuido:
 *
 *   · `new_layout_publicador/Visao Geral.dc.html` declara `font-size:8.5px`
 *     **3 vezes** — as iniciais do avatar de 24px (`:104`), as do thumb de
 *     26px (`:197`) e o ícone de 22px do sub-item (`:210`).
 *     ⚠️ As linhas do plano (`:97`/`:192`/`:204`) estão deslocadas; estas
 *     foram medidas com `grep -n`.
 *   · E `text-[8.5px]` aparece **3 vezes** nos arquivos novos:
 *     `JornadaDosProdutos.jsx` (1) e `OQueFazerAgora.jsx` (2). Nenhum dos 5
 *     arquivos do plano 179-08 o usa — medido com `grep`, não presumido.
 *
 * Três declarações no protótipo, três usos no código. Nenhum valor foi
 * REMOVIDO desta lista.
 *
 * ⚠️ Fase 180, plano 01 — `15.5` entrou, e também é AMPLIAÇÃO, não
 * endurecimento. A medida, escrita ao lado no mesmo formato do `8.5`:
 *
 *   · `new_layout_publicador/Publicacoes v2.dc.html` declara `font-size:15.5px`
 *     **1 vez só** — o `<input>` da busca central de 54px (`:70`). Medido com
 *     varredura de `font-size:` sobre o arquivo, não presumido.
 *   · Ainda não há nenhum uso de `text-[15.5px]` no código: este plano só
 *     LIBERA o valor, e o 180-06 é o primeiro que o escreve. Sem a liberação
 *     aqui, aquele plano teria de arredondar para `text-base` (16px) e mudar o
 *     layout — exatamente o que FID-03 proíbe.
 *
 * ⚠️ `18px` NÃO entrou, de propósito: as 3 declarações dele
 * (`Publicacoes v2.dc.html:374`-`:376`, os números de Vendas/Visitas/Conversão)
 * estão DENTRO do `<aside>` de 460px (`:310`-`:402`), que é a Fase 181. Quem
 * desenhar o painel acrescenta o 18 com a medida dele.
 *
 * ⚠️ `11px` segue na lista e NÃO tem uso no protótipo de Publicações — nada é
 * removido desta lista por falta de uso numa tela.
 *
 * Varredura completa de `font-size:` em `Publicacoes v2.dc.html` (13/10/2026):
 * 10, 10.5, 11.5, 12, 12.5, 13, 13.5, 14, 15, 15.5, 17, 18, 20, 28. Só `15.5` e
 * `18` faltavam na lista; só `15.5` é desta fase.
 *
 * ⚠️⚠️ Fase 187, plano 01 — `9.5` e `22` entraram, e também é AMPLIAÇÃO, não
 * endurecimento: **nenhum valor saiu**. A medida, no mesmo formato do `8.5` e
 * do `15.5`:
 *
 *   Varredura de `font-size:` no E1 de `design_handoff_publicador/Aprovacao.dc.html`
 *   (linhas `:26`-`:205`, POR SCRIPT, 15/10/2026):
 *   9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 22, 28.
 *   Faltavam na lista: **9.5** e **22**. Os outros onze já estavam.
 *
 *   · `9.5px` — declarado **3 vezes dentro do E1**, todas em glifo dentro de
 *     círculo pequeno: as iniciais do avatar de 22px do popover de Responsável
 *     (`:86`), o glifo do anel de progresso de 16px (`:135`) e as iniciais do
 *     avatar de 24px da coluna Responsável (`:166`).
 *     ⚠️ As linhas do plano (`:91`/`:135`/`:168`) estão DESLOCADAS em duas das
 *     três; estas foram medidas com `grep -n`.
 *     (No arquivo INTEIRO são 12 declarações, as outras 9 fora do E1.)
 *   · `22px` — declarado **1 vez só**: o NÚMERO de cada um dos 9 cards da faixa
 *     de andamento (`:48`, com `line-height:1`, `font-weight:600`,
 *     `letter-spacing:-0.02em` e `font-variant-numeric:tabular-nums`).
 *     ⚠️ O plano cita `:49`, que é o `</button>`.
 *
 * ⚠️ Sem a liberação aqui, o plano da faixa teria de arredondar para
 * `text-[24px]` ou `text-xl` (20px) — exatamente o que FID-03 proíbe. E nada
 * foi acrescentado "por precaução": os onze valores restantes da varredura já
 * estavam na lista, e tamanho liberado sem uso medido é o que torna este gate
 * inútil.
 *
 * ⚠️ Os dois valores do E1 que NÃO precisaram de nada: `28px` (o `<h1>`
 * "Publicações", `:33`) e `11px` (a pílula de contagem da aba ativa, `:35`) já
 * estavam na lista desde a Fase 177.
 *
 * ⚠️ A prova de que esta ampliação não removeu nada é MECÂNICA e mora em
 * `tests/js/publicador-fila-tokens-187.test.js` (`TAM-187`): contagem FIXA
 * (21 → 23) **mais** a inclusão do conjunto antigo no novo — porque
 * `contagem += 2` sozinha passaria se alguém trocasse dois valores por dois.
 */
const TAMANHOS_PERMITIDOS = new Set([
    8.5, 9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 17, 20, 22, 24, 28, 30, 32, 34,
]);

/**
 * Os 15 arquivos que a Fase 180 criou em `publicacoes/`, POR NOME.
 *
 * ⚠️⚠️ REAPONTE DA FASE 187, PLANO 03 — e com o veto escrito. Antes, as duas
 * asserções abaixo contavam `ARQUIVOS_NOVOS.filter(startsWith(DIR_PUB)).length`
 * e exigiam 15 e 16. Isso media o DIRETÓRIO, não a Fase 180 — e o diretório
 * deixou de ser 100% daquela fase quando a 187 pôs `AbasDaPagina.jsx` lá.
 *
 * Com a contagem de diretório, **todo plano da Fase 187 que criasse componente
 * aqui teria de subir estes dois números** — e é exatamente a régua que sobe a
 * cada plano que "treina o executor seguinte a ajustar números em vez de
 * consertar código" (learnings §4b.2). Pior: subir o número aceitaria a REMOÇÃO
 * de um arquivo da 180 compensada pela criação de um da 187.
 *
 * ⚠️ O que as asserções provam continua IGUAL e ficou mais forte: nenhum dos 15
 * arquivos da Fase 180 saiu de `ARQUIVOS_NOVOS` (antes, só a contagem; agora, os
 * nomes, com os infratores coletados). Quem fecha o outro lado — arquivo NOVO no
 * disco fora da lista — é o `deepEqual` de `DIRETORIOS_FECHADOS`, que não mudou.
 */
const DA_FASE_180 = [
    // 180-05 — o cabeçalho da página e a faixa de sucesso
    `${DIR_PUB}/CabecalhoDePublicacoes.jsx`,
    `${DIR_PUB}/FaixaDeSucesso.jsx`,
    // 180-06 — a busca de 54px, o trilho de status e a linha de controles
    `${DIR_PUB}/BuscaDePublicacoes.jsx`,
    `${DIR_PUB}/AbasDeStatus.jsx`,
    `${DIR_PUB}/LinhaDeControles.jsx`,
    // 180-07 — os chips de filtro, a casca dos popovers e a triagem
    `${DIR_PUB}/MenuDeFiltro.jsx`,
    `${DIR_PUB}/ChipsDeFiltro.jsx`,
    `${DIR_PUB}/ChipsDeTriagem.jsx`,
    // 180-08 — a tabela
    `${DIR_PUB}/CabecalhoDaTabela.jsx`,
    `${DIR_PUB}/LinhaDePublicacao.jsx`,
    `${DIR_PUB}/CelulasDaLinha.jsx`,
    // 180-09 — a linha de grupo, o rodapé e os estados vazios
    `${DIR_PUB}/LinhaDeGrupoDeProduto.jsx`,
    `${DIR_PUB}/RodapeDaTabela.jsx`,
    `${DIR_PUB}/VaziosDaLista.jsx`,
    // 180-11 — o único `.js` do diretório: a máquina de teclado da lista
    `${DIR_PUB}/atalhosDaLista.js`,
];

const FONTES = new Map(ARQUIVOS_NOVOS.map((caminho) => [caminho, lerSemComentarios(caminho)]));

/**
 * ⚠️ O gate que impede a OMISSÃO — Fase 179, plano 09.
 *
 * `ARQUIVOS_NOVOS` é uma constante de propósito (ver o comentário do topo:
 * glob pegaria arquivo que ainda não nasceu). Mas constante tem o defeito
 * simétrico: **esquecer um arquivo faz este gate passar sem acusar nada**, e
 * aquele arquivo fica sem conferência contra `text-sm`, `ecf-*`,
 * `font-display`, hex literal e breakpoint.
 *
 * Este teste fecha o furo para os dois diretórios que são 100% desta
 * milestone: `identidade/` (Fase 177) e `visaogeral/` (Fase 179). Arquivo novo
 * ali dentro reprova até alguém decidir, à mão, que ele entra na lista.
 *
 * ⚠️ `header/` fica FORA desta checagem de propósito: aquele diretório tem
 * arquivos que NÃO são desta milestone, e varrê-lo inteiro reprovaria trabalho
 * de outra fase.
 *
 * ⚠️ Fase 180, plano 05 — `publicacoes/` entra aqui, e é ADIÇÃO: o diretório
 * nasceu nesta fase e é 100% dela, como `identidade/` e `visaogeral/` foram das
 * suas. Nada saiu da lista.
 *
 * ⚠️⚠️ Fase 186, plano 02 — `aprovacao/` entra aqui, e TAMBÉM É ADIÇÃO PURA:
 * NADA saiu da lista. O diretório nasceu naquele plano e é 100% da Fase 186.
 *
 * ⚠️ E ele entra NO PLANO QUE CRIA O PRIMEIRO ARQUIVO DELE, de propósito. Se a
 * entrada esperasse o fechamento da fase, cada arquivo criado no meio ficaria
 * FORA da conferência de vocabulário e este gate passaria CALADO — nenhum
 * número se moveria, nenhuma asserção falharia. Resposta de GATE-01 (*o que
 * precisaria estar errado para este gate falhar?*): **um arquivo novo em
 * `aprovacao/` usar `text-sm`, hex cru ou `ecf-*` sem ninguém ver.**
 */
const DIRETORIOS_FECHADOS = [DIR, DIR_VG, DIR_PUB, DIR_APROV];

test('GATE-03 — nenhum arquivo de `identidade/`, `visaogeral/`, `publicacoes/` ou `aprovacao/` fica FORA de ARQUIVOS_NOVOS', async (contexto) => {
    for (const diretorio of DIRETORIOS_FECHADOS) {
        await contexto.test(`${diretorio} — a lista cobre exatamente os .js/.jsx da pasta`, () => {
            const noDisco = readdirSync(resolve(import.meta.dirname, '../..', diretorio))
                .filter((nome) => nome.endsWith('.js') || nome.endsWith('.jsx'))
                .map((nome) => `${diretorio}/${nome}`)
                .sort();
            const declarados = ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${diretorio}/`)).sort();

            // Sem isto, um diretório vazio (ou renomeado) passaria calado.
            assert.ok(noDisco.length > 0, `${diretorio} não tem nenhum .js/.jsx — o caminho está errado?`);
            assert.deepEqual(
                noDisco,
                declarados,
                `${diretorio}: arquivo no disco fora de ARQUIVOS_NOVOS (ou o contrário). Arquivo fora da lista passa SEM conferência de vocabulário — e o gate passaria POR OMISSÃO.`,
            );
        });
    }

    await contexto.test('os 12 arquivos da Fase 179 estão na lista', () => {
        assert.equal(
            ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${DIR_VG}/`)).length,
            12,
            'a Fase 179 criou 12 arquivos em `visaogeral/` (11 nas waves 1-2 + CockpitDaConta)',
        );
    });

    await contexto.test('os 15 arquivos da Fase 180 estão na lista', () => {
        // ⚠️ Número ELEVADO de 14 para 15 pelo plano 180-11: 2 do 180-05, que
        // abre o diretório, + 3 do 180-06 + 3 do 180-07 + 3 do 180-08 + 3 do
        // 180-09 + 1 deste (`atalhosDaLista.js`). Os planos seguintes
        // da fase ELEVAM este valor junto com os arquivos deles — deixar o
        // número parado é o jeito de descobrir que alguém esqueceu de declarar
        // o arquivo em `ARQUIVOS_NOVOS` (e aí ele ficaria sem conferência
        // contra `text-sm`, `ecf-*`, hex e breakpoint), e o gate passaria POR
        // OMISSÃO.
        // ⚠️ REAPONTADO pela Fase 187, plano 03: a contagem é sobre os 15 NOMES
        // da Fase 180, não sobre o tamanho do diretório. Ver o veto escrito em
        // `DA_FASE_180`. A contagem FIXA continua, e o que ela prova ficou mais
        // forte: além do número, os nomes, com os infratores COLETADOS.
        assert.equal(
            DA_FASE_180.length,
            15,
            'a Fase 180 tem 15 arquivos em `publicacoes/`: 2 do 180-05 (CabecalhoDePublicacoes + FaixaDeSucesso),'
            + ' 3 do 180-06 (BuscaDePublicacoes + AbasDeStatus + LinhaDeControles),'
            + ' 3 do 180-07 (MenuDeFiltro + ChipsDeFiltro + ChipsDeTriagem),'
            + ' 3 do 180-08 (CabecalhoDaTabela + LinhaDePublicacao + CelulasDaLinha),'
            + ' 3 do 180-09 (LinhaDeGrupoDeProduto + RodapeDaTabela + VaziosDaLista)'
            + ' e 1 do 180-11 (atalhosDaLista.js, o único .js do diretório)',
        );
        const faltando = DA_FASE_180.filter((c) => !ARQUIVOS_NOVOS.includes(c));
        assert.deepEqual(
            faltando,
            [],
            'arquivo da Fase 180 fora de ARQUIVOS_NOVOS: ele fica SEM conferência contra `text-sm`,'
            + ' `ecf-*`, hex cru e breakpoint, e o gate passa POR OMISSÃO',
        );
        // ⚠️ E os 15 estão todos NO DISCO: lista de nomes que aponta para
        // caminho fantasma "prova" cobertura medindo NADA.
        const fantasmas = DA_FASE_180.filter(
            (c) => !existsSync(resolve(import.meta.dirname, '../..', c)),
        );
        assert.deepEqual(fantasmas, [], 'caminho fantasma em DA_FASE_180 — o gate mediria NADA');

        // ⚠️ E o ÚNICO `.js` é ele: o diretório inteiro é `.jsx` de propósito,
        // porque é só no `.jsx` que o `content` do Tailwind varre classe. Um
        // `.js` novo aqui tem de ser decisão consciente, não descuido.
        const MODULO_DE_TECLADO = `${DIR_PUB}/atalhosDaLista.js`;
        assert.ok(
            ARQUIVOS_NOVOS.includes(MODULO_DE_TECLADO),
            `${MODULO_DE_TECLADO} saiu de ARQUIVOS_NOVOS: ficaria sem conferência de vocabulário`,
        );
        assert.deepEqual(
            ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${DIR_PUB}/`) && c.endsWith('.js')),
            [MODULO_DE_TECLADO],
            'só `atalhosDaLista.js` é .js em `publicacoes/` — classe num .js não gera CSS',
        );
    });

    await contexto.test('e a PÁGINA de Publicações entrou na lista — a Fase 180 contribui 16 arquivos (planos 180-10 e 180-11)', () => {
        // ⚠️ POR QUE O NÚMERO ACIMA É 15 E ESTE É 16: o plano 180-10
        // NÃO cria componente em `publicacoes/` — ele COMPÕE a página. Elevar
        // o número do DIRETÓRIO por causa dele seria mentir sobre o diretório
        // (e o gate de território daquele plano prova diff VAZIO em
        // `publicacoes/`). O que cresce é a lista INTEIRA da fase: os 15
        // arquivos de `publicacoes/` + a página.
        //
        // ⚠️ E este sub-teste é o que impede a entrada CALADA da página: sem
        // ele, acrescentar o caminho em `ARQUIVOS_NOVOS` não mexeria em nenhum
        // número, e tirá-lo de lá também não.
        const PAGINA = 'resources/js/Pages/Mlb/MeusAnuncios.jsx';

        assert.ok(
            ARQUIVOS_NOVOS.includes(PAGINA),
            `${PAGINA} saiu de ARQUIVOS_NOVOS: a tela de Publicações ficaria sem conferência de vocabulário`,
        );
        assert.equal(
            ARQUIVOS_NOVOS.filter((c) => c === PAGINA).length,
            1,
            // ⚠️ Duplicata PASSARIA CALADA de outro jeito: o `deepEqual` de
            // `DIRETORIOS_FECHADOS` não cobre `Pages/`, e a soma abaixo com
            // o caminho repetido daria 17 em vez de 16 — número errado, mas
            // sem dizer POR QUE. Esta asserção nomeia a causa.
            `${PAGINA} está DUAS vezes em ARQUIVOS_NOVOS: o gate rodaria o mesmo arquivo duas vezes`,
        );
        // ⚠️ REAPONTADO pela Fase 187, plano 03: o conjunto é `DA_FASE_180` + a
        // página, e não "tudo que está em `publicacoes/`" + a página. Com a
        // contagem antiga, `AbasDaPagina.jsx` (Fase 187) entraria neste 16 e
        // obrigaria a subir o número de uma fase FECHADA — régua que sobe a cada
        // plano, que é o que o learnings §4b.2 chama de frágil por construção.
        assert.equal(
            ARQUIVOS_NOVOS.filter((c) => DA_FASE_180.includes(c) || c === PAGINA).length,
            16,
            'a Fase 180 contribui 16 arquivos: os 15 de `publicacoes/` (planos 05 a 09 e 11) mais a'
            + ' página `Pages/Mlb/MeusAnuncios.jsx` (plano 10), que é quem os compõe',
        );
        // ⚠️ E a prova de que o conjunto NÃO é o diretório: `AbasDaPagina.jsx`
        // está em `ARQUIVOS_NOVOS` (o GATE-03 o confere) e fora de `DA_FASE_180`.
        // Sem esta asserção, trocar `DA_FASE_180` de volta por `startsWith` não
        // mexeria em nenhum número aqui e o reaponte seria revogado em silêncio.
        assert.ok(
            ARQUIVOS_NOVOS.includes(`${DIR_PUB}/AbasDaPagina.jsx`),
            'AbasDaPagina.jsx saiu de ARQUIVOS_NOVOS: as abas ficariam sem conferência de vocabulário',
        );
        assert.ok(
            !DA_FASE_180.includes(`${DIR_PUB}/AbasDaPagina.jsx`),
            'AbasDaPagina.jsx é da Fase 187, não da 180 — a lista nomeia a fase, não a pasta',
        );
    });

    await contexto.test('os 3 arquivos da Fase 186 estão na lista', () => {
        // ⚠️ O NÚMERO PARADO É O SINAL. É assim que se descobre que alguém
        // esqueceu de declarar um arquivo novo em `ARQUIVOS_NOVOS` — e um
        // arquivo fora da lista fica sem conferência contra `text-sm`, hex cru,
        // `ecf-*`, `font-display` e breakpoint, com o gate passando POR
        // OMISSÃO.
        //
        // ⚠️⚠️ REAPONTE DA FASE 187, PLANO 04 — e com o veto escrito. Antes esta
        // asserção contava `ARQUIVOS_NOVOS.filter(startsWith(DIR_APROV)).length`
        // e exigia 3. Isso media o DIRETÓRIO, não a Fase 186 — e o diretório
        // deixou de ser 100% daquela fase quando a 187 pôs os componentes da
        // fila lá.
        //
        // É a MESMA cirurgia que o plano 187-03 fez em `DA_FASE_180`, pelo mesmo
        // motivo medido: com a contagem de diretório, **todo plano da Fase 187
        // que criasse componente aqui teria de subir este número** — a régua que
        // sobe a cada plano e "treina o executor seguinte a ajustar números em
        // vez de consertar código" (learnings §4b.2). Pior: subir o número
        // aceitaria a REMOÇÃO de um arquivo da 186 compensada pela criação de um
        // da 187.
        //
        // ⚠️ O que a asserção prova continua IGUAL e ficou mais forte: nenhum dos
        // 3 arquivos da Fase 186 saiu de `ARQUIVOS_NOVOS` — antes só a contagem,
        // agora os NOMES, com os infratores coletados. Quem fecha o outro lado
        // (arquivo novo no disco fora da lista) é o `deepEqual` de
        // `DIRETORIOS_FECHADOS`, que não mudou.
        const DA_FASE_186 = [
            // Plano 186-02 — os 8 estados de andamento interno, o resto do
            // vocabulário do portão e a pill que os desenha:
            `${DIR_APROV}/andamentoDoPortao.jsx`,
            `${DIR_APROV}/vocabularioDoPortao.jsx`,
            `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        ];

        assert.equal(
            DA_FASE_186.length,
            3,
            'a Fase 186 tem 3 arquivos em `aprovacao/` (plano 186-02: andamentoDoPortao +'
            + ' vocabularioDoPortao + SeloDeAndamentoInterno)',
        );
        const faltando186 = DA_FASE_186.filter((c) => !ARQUIVOS_NOVOS.includes(c));
        assert.deepEqual(
            faltando186,
            [],
            'arquivo da Fase 186 fora de ARQUIVOS_NOVOS: ele fica SEM conferência contra `text-sm`,'
            + ' `ecf-*`, hex cru e breakpoint, e o gate passa POR OMISSÃO',
        );
        // ⚠️ E os 3 estão todos NO DISCO: lista de nomes que aponta para caminho
        // fantasma "prova" cobertura medindo NADA.
        const fantasmas186 = DA_FASE_186.filter(
            (c) => !existsSync(resolve(import.meta.dirname, '../..', c)),
        );
        assert.deepEqual(fantasmas186, [], 'caminho fantasma em DA_FASE_186 — o gate mediria NADA');

        // O diretório INTEIRO, que é o que as duas asserções abaixo medem (e
        // elas medem o diretório de propósito: a regra do `.jsx` vale para
        // qualquer fase que escreva aqui, não só para a 186).
        const daFase186 = ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${DIR_APROV}/`));

        // ⚠️ E NENHUM arquivo de `aprovacao/` é `.js`. O diretório é 100% `.jsx`
        // de propósito, porque é só no `.jsx` que o `content` do Tailwind varre
        // classe. Um `.js` aqui tem de ser decisão consciente e declarada, como
        // o `atalhosDaLista.js` de `publicacoes/` foi.
        assert.deepEqual(
            daFase186.filter((c) => c.endsWith('.js')),
            [],
            'há um `.js` em `aprovacao/` — classe escrita nele NÃO GERA CSS e não existe para a tela',
        );
        assert.equal(daFase186.filter((c) => c.endsWith('.jsx')).length, daFase186.length);

        // ⚠️ E a prova de que o conjunto NÃO é o diretório: os componentes da
        // fila (Fase 187) estão em `ARQUIVOS_NOVOS` e FORA de `DA_FASE_186`. Sem
        // esta asserção, trocar `DA_FASE_186` de volta por `startsWith` não
        // mexeria em nenhum número aqui e o reaponte seria revogado em silêncio.
        assert.ok(
            ARQUIVOS_NOVOS.includes(`${DIR_APROV}/CabecalhoDaFila.jsx`),
            'CabecalhoDaFila.jsx saiu de ARQUIVOS_NOVOS: o cabeçalho da fila ficaria sem conferência',
        );
        assert.ok(
            !DA_FASE_186.includes(`${DIR_APROV}/CabecalhoDaFila.jsx`),
            'CabecalhoDaFila.jsx é da Fase 187, não da 186 — a lista nomeia a fase, não a pasta',
        );

        // ⚠️ ISCA DA CONTAGEM: um arquivo forjado FORA da lista tem de ser
        // detectado pela mesma comparação que o `deepEqual` acima usa. Sem esta
        // asserção, a prova de que a lista "cobre" o diretório dependeria de
        // ninguém nunca esquecer nada — que é exatamente a hipótese que o gate
        // existe para não precisar fazer.
        const FORJADO = `${DIR_APROV}/ArquivoQueNinguemDeclarou.jsx`;
        assert.ok(
            !ARQUIVOS_NOVOS.includes(FORJADO),
            'a isca virou arquivo declarado de verdade — troque a isca',
        );
        const noDiscoForjado = [...daFase186, FORJADO].sort();
        assert.notDeepEqual(
            noDiscoForjado,
            [...daFase186].sort(),
            'a comparação do GATE-03 NÃO distingue um arquivo a mais no disco — ela está cega',
        );
    });
});

test('GATE-03 — vocabulário novo fechado, arquivo por arquivo', async (contexto) => {
    for (const caminho of ARQUIVOS_NOVOS) {
        const fonte = FONTES.get(caminho);

        // ─── 1. tamanhos de texto: só os do README, e nunca a escala nomeada ───
        await contexto.test(`${caminho} — todo text-[Xpx] está na lista do README e nenhuma escala nomeada`, () => {
            const encontrados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => Number(m[1]));
            for (const tamanho of encontrados) {
                assert.ok(
                    TAMANHOS_PERMITIDOS.has(tamanho),
                    `${caminho}: text-[${tamanho}px] não está na lista de tamanhos do README`,
                );
            }
            // FID-03: `text-sm` NÃO é 13,5px. A escala nomeada arredonda.
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        });

        // ─── 2. pesos: só 400/500/600/700 ───
        await contexto.test(`${caminho} — só os 4 pesos do README (400/500/600/700)`, () => {
            assert.doesNotMatch(fonte, /font-(thin|extralight|light|extrabold|black)\b/);
        });

        // ─── 3. FID-04: nada da camada antiga ───
        await contexto.test(`${caminho} — FID-04: nenhum token ecf-*, nenhum font-display, nenhum border-white/[0.08]`, () => {
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/);
            assert.doesNotMatch(fonte, /\bfont-display\b/);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/);
        });

        // ─── 4. TOK-15: a cor vem por nome de token, nunca por hex ───
        await contexto.test(`${caminho} — TOK-15: nenhum hex literal`, () => {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/);
        });

        // ─── 5. FID-05: nenhum breakpoint novo ───
        await contexto.test(`${caminho} — FID-05: nenhum breakpoint`, () => {
            assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/);
        });

        // ─── 6. comportamento que FICA ───
        await contexto.test(`${caminho} — sem o select do shadcn e sem dangerouslySetInnerHTML`, () => {
            assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        });
    }
});

test('GATE-03 — IDENT-08: a identidade é compartilhada, não copiada', async (contexto) => {
    await contexto.test('oklch( aparece SÓ em identidade.js', () => {
        for (const [caminho, fonte] of FONTES) {
            const esperado = caminho.endsWith('identidade.js');
            assert.equal(
                fonte.includes('oklch('),
                esperado,
                esperado
                    ? 'identidade.js deixou de calcular o tom do tile'
                    : `${caminho} recalcula oklch — o tom do produto tem UMA casa (identidade.js)`,
            );
        }
    });

    await contexto.test('a geometria do retângulo do glyph aparece SÓ em GlyphDaFase.jsx', () => {
        // ⚠️ Fase 177, plano 05 — a asserção passou a ser sobre o PAR de
        // medidas, e isso é um ENDURECIMENTO, não um relaxamento.
        //
        // Antes conferia `w-[15px]` e `h-[18px]` cada um por conta própria, e
        // `h-[18px]` solto NÃO é "o glyph": a pílula de contador das abas tem
        // 18px de altura por medida do protótipo
        // (`PublicadorHeader.dc.html:175`) e não redesenha glyph nenhum. O que
        // identifica o retângulo da marca é a geometria INTEIRA —
        // `h-[18px] w-[15px] rounded-[4px] border-[1.6px]` — mais a presença
        // das duas medidas juntas em qualquer ordem. É a mesma lição dos 4
        // furos do gate do plano 177-04: substring compartilhada não prova
        // nada; o que prova é o conjunto no elemento certo.
        const GEOMETRIA = 'h-[18px] w-[15px] rounded-[4px] border-[1.6px]';

        for (const [caminho, fonte] of FONTES) {
            const esperado = caminho.endsWith('GlyphDaFase.jsx');
            assert.equal(
                fonte.includes(GEOMETRIA),
                esperado,
                esperado
                    ? 'GlyphDaFase.jsx perdeu a geometria do retângulo da marca'
                    : `${caminho} redesenha o glyph — o glyph tem UMA casa`,
            );
            if (!esperado) {
                assert.ok(
                    !(fonte.includes('w-[15px]') && fonte.includes('h-[18px]')),
                    `${caminho} combina as duas medidas do retângulo do glyph — o glyph tem UMA casa`,
                );
            }
        }
    });

    await contexto.test('quem precisa do glyph IMPORTA o componente, não reimplementa', () => {
        const rotulo = FONTES.get(`${DIR}/RotuloDaFase.jsx`);
        assert.match(rotulo, /from '\.\/GlyphDaFase'/);
        // E o rótulo é quem dá o nome acessível: o glyph é decorativo.
        // ⚠️ GATE-05: com o `=` no fim. `/aria-hidden/` casaria a palavra dentro
        // de um comentário ou de uma classe e seria asserção vazia.
        assert.match(FONTES.get(`${DIR}/GlyphDaFase.jsx`), /aria-hidden=/);
    });

    await contexto.test('o tile IMPORTA iniciais/tomDoProduto de identidade.js', () => {
        const tile = FONTES.get(`${DIR}/TileDoProduto.jsx`);
        assert.match(tile, /from '\.\/identidade'/);
        assert.match(tile, /iniciais/);
        assert.match(tile, /tomDoProduto/);
    });

    await contexto.test('o texto do servidor passa por textoSeguro nos componentes que o renderizam', () => {
        for (const arquivo of ['SeloDoTipo.jsx', 'SeloDeStatusDaPublicacao.jsx']) {
            const fonte = FONTES.get(`${DIR}/${arquivo}`);
            assert.match(fonte, /from '\.\.\/BarraDaConta'/, `${arquivo} recriou o textoSeguro`);
            assert.match(fonte, /textoSeguro\(/);
        }
    });
});

test('GATE-03 — a camada de tokens é a única casa dos hex', async (contexto) => {
    const config = lerSemComentarios('tailwind.config.js');

    await contexto.test('tailwind.config.js continua guardando o amarelo do Publicador', () => {
        assert.match(config, /#FACC15/);
    });

    await contexto.test('nenhum componente de identidade repete esse hex', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /#FACC15/i, `${caminho} escreveu o hex em vez de usar pub-accent`);
        }
    });
});

test('GATE-03 — os literais de IDENT-02 e IDENT-05 estão byte a byte', async (contexto) => {
    const rotulo = FONTES.get(`${DIR}/RotuloDaFase.jsx`);
    const mapa = FONTES.get(`${DIR}/MapaDeCobertura.jsx`);

    await contexto.test('RotuloDaFase: "Simples · 1 un." / "Combo · N un." / "Fora do Publicador"', () => {
        assert.ok(rotulo.includes('Simples · 1 un.'), 'literal da Fase 1 parafraseado');
        assert.ok(rotulo.includes('Fora do Publicador'), 'literal do Legado parafraseado');
        assert.match(rotulo, /Combo · \$\{unidades\} un\./, 'literal da Fase 2 parafraseado');
    });

    await contexto.test('MapaDeCobertura: "publicada" / "não criada" no nome acessível', () => {
        assert.ok(mapa.includes("'publicada'"), mapa);
        assert.ok(mapa.includes("'não criada'"));
        // E o título é o MESMO texto do aria-label (IDENT-07).
        assert.match(mapa, /title=\{descricao\}/);
        assert.match(mapa, /aria-label=\{descricao\}/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 07 — o header antigo não volta por engano.
//
// ⚠️ AQUI A LISTA É UM GLOB, de propósito, e é o OPOSTO da constante
// `ARQUIVOS_NOVOS` acima: lá a constante existe para que arquivo novo não entre
// no gate sem alguém olhar; aqui o glob existe para que PÁGINA nova não escape
// dele. Uma página criada amanhã dentro de `Pages/Mlb/Publicador/` que montasse
// `BarraDaConta`/`AbasDaConta` passaria calada por uma lista fixa.
//
// `import { textoSeguro } from '.../BarraDaConta'` continua PERMITIDO: a função
// mora naquele arquivo desde a Fase 172 e o header novo também a importa de lá
// (uma regra, uma casa). O que está vetado é o COMPONENTE: o `default` de
// `BarraDaConta` e qualquer import de `AbasDaConta`.
//
// ⚠️ ESCOPO: só `Pages/Mlb/Publicador/`. As três páginas de `/mlb/anuncios`
// (`AnunciarMassa`, `AnunciosHistorico`, `MeusAnuncios`) são do plano 177-08 e
// ainda montam o header antigo — incluí-las aqui reprovaria trabalho alheio em
// andamento.
// ═══════════════════════════════════════════════════════════════════════════

const DIR_PAGINAS_PUBLICADOR = 'resources/js/Pages/Mlb/Publicador';

const PAGINAS_DO_PUBLICADOR = readdirSync(resolve(import.meta.dirname, '../..', DIR_PAGINAS_PUBLICADOR))
    .filter((nome) => nome.endsWith('.jsx'))
    .map((nome) => `${DIR_PAGINAS_PUBLICADOR}/${nome}`);

test('GATE-01 — nenhuma página do Publicador monta BarraDaConta nem AbasDaConta (o header é o PublicadorHeader)', async (contexto) => {
    // Sem isto, um glob que não achasse nada passaria vazio e por omissão.
    assert.ok(PAGINAS_DO_PUBLICADOR.length >= 7, `esperava ao menos 7 páginas, achei ${PAGINAS_DO_PUBLICADOR.length}`);

    for (const caminho of PAGINAS_DO_PUBLICADOR) {
        const fonte = lerSemComentarios(caminho);

        await contexto.test(`${caminho} — zero import de AbasDaConta`, () => {
            // Qualquer forma de import daquele módulo, inclusive com o default
            // renomeado (`import Abas from '.../AbasDaConta'`).
            assert.doesNotMatch(fonte, /import[^;]*from\s*'[^']*\/AbasDaConta'/);
        });

        await contexto.test(`${caminho} — de BarraDaConta só o named textoSeguro, nunca o default`, () => {
            for (const m of fonte.matchAll(/import\s+([^;]*?)\s+from\s*'[^']*\/BarraDaConta'/g)) {
                // `{ textoSeguro }` passa; `BarraDaConta`, `Barra` ou
                // `BarraDaConta, { textoSeguro }` reprovam.
                assert.ok(
                    m[1].trimStart().startsWith('{'),
                    `${caminho} importou o COMPONENTE de BarraDaConta: ${m[0]}`,
                );
            }
        });

        await contexto.test(`${caminho} — zero <BarraDaConta> e zero <AbasDaConta> no JSX`, () => {
            assert.doesNotMatch(fonte, /<BarraDaConta[\s/>]/);
            assert.doesNotMatch(fonte, /<AbasDaConta[\s/>]/);
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 07 — HEAD-14: o header existe e nasce no lugar certo.
//
// POR QUE ESTE GATE PRECISOU EXISTIR: o teste acima (GATE-01) prova que o
// header ANTIGO não volta, e isso não é a mesma coisa que provar que o NOVO
// está lá. Medido: apagar `<PublicadorHeader …>` de `Produto.jsx` não reprovava
// NADA na suíte inteira — aquela página não tinha gate nenhum. E o
// `PublicacaoEmLote` só era lido por testes que não olhavam o topo da tela.
//
// E não basta "está no arquivo": o header TEM de vir ANTES do container do
// corpo. É ele que carrega o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`
// (HEAD-14); dentro de um container com `px-8` ele não encostaria nas bordas e
// FID-05 (conteúdo de 1600px com 36px de padding) ficaria impossível.
//
// ⚠️ Fase 179, plano 09 — REAPONTE DE VALOR, não remoção nem afrouxamento.
// Antes o container do corpo era procurado por UM literal fixo
// (`mx-auto max-w-[1240px]`) em todas as 6 páginas. A Visão geral virou o
// cockpit e o container dela passou a ser o do protótipo
// (`mx-auto flex max-w-[1600px] …`, FID-05: 1600px de conteúdo com 36px de
// padding — exatamente o que este comentário sempre disse que era o objetivo).
// O que a asserção garante continua sendo o MESMO: o header nasce ANTES do
// container do corpo. O que mudou é o literal com que cada página é
// localizada, e ele virou uma tabela por página para que nenhuma delas seja
// medida pelo container de outra.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As 6 páginas por conta que montam o header global (plano 177-07), e a aba
 * que CADA uma marca como ativa.
 *
 * ⚠️ O valor esperado está aqui porque "tem header" não bastava: a prova por
 * mutação achou um FURO — trocar `aba="configuracoes"` por `aba="produtos"` na
 * tela de Configurações passava calado, e a tela marcaria a aba Produtos como
 * ativa estando em outro lugar.
 *
 * ⚠️ `Produto.jsx` é `produtos` de propósito: é o DETALHE de um produto, não
 * uma aba nova. `PublicacaoEmLote.jsx` também. E `Configuracoes.jsx` é
 * `configuracoes`, que NÃO é uma das quatro abas — Configurações virou o ícone
 * de engrenagem da linha 1 (HEAD-08), e o valor serve para nenhuma das quatro
 * ficar ativa.
 */
/**
 * O literal que localiza o container do corpo de CADA página.
 *
 * ⚠️ É uma tabela e não um literal único porque a Visão geral (Fase 179) mede
 * 1600px e as outras cinco seguem em 1240px. Um literal único voltaria a
 * reprovar/passar pelo motivo errado na primeira página que mudasse de medida.
 */
const CONTAINER_POR_PAGINA = new Map([
    ['VisaoGeral.jsx', 'mx-auto flex max-w-[1600px]'],
    ['Produtos.jsx', 'mx-auto max-w-[1240px]'],
    ['Produto.jsx', 'mx-auto max-w-[1240px]'],
    ['Alavancas.jsx', 'mx-auto max-w-[1240px]'],
    ['Configuracoes.jsx', 'mx-auto max-w-[1240px]'],
    ['PublicacaoEmLote.jsx', 'mx-auto max-w-[1240px]'],
]);

const ABA_POR_PAGINA = new Map([
    ['VisaoGeral.jsx', 'visao-geral'],
    ['Produtos.jsx', 'produtos'],
    ['Produto.jsx', 'produtos'],
    ['Alavancas.jsx', 'alavancas'],
    ['Configuracoes.jsx', 'configuracoes'],
    ['PublicacaoEmLote.jsx', 'produtos'],
]);

const PAGINAS_COM_HEADER = [...ABA_POR_PAGINA.keys()];

/**
 * As que NÃO têm header de conta, com o motivo escrito:
 *  · `Editor.jsx` tem a própria casca de etapas (e é território de outro dev —
 *    REG-03 proíbe tocá-la nesta fase);
 *  · `Tarefas.jsx` é a fila de alavancas de TODAS as contas numa lista só, sem
 *    conta aberta (está escrito no topo do arquivo desde a Fase 172).
 */
const PAGINAS_SEM_HEADER = ['Editor.jsx', 'Tarefas.jsx'];

test('HEAD-14 — as 6 páginas por conta montam o PublicadorHeader antes do container do corpo', async (contexto) => {
    // ⚠️ Esta primeira asserção é o que impede o gate de passar POR OMISSÃO:
    // página nova em `Pages/Mlb/Publicador/` não entra calada em nenhuma das
    // duas listas — alguém tem de decidir se ela tem header de conta ou não.
    await contexto.test('as duas listas cobrem exatamente os .jsx da pasta', () => {
        const declaradas = [...PAGINAS_COM_HEADER, ...PAGINAS_SEM_HEADER].sort();
        const naPasta = PAGINAS_DO_PUBLICADOR.map((c) => c.split('/').pop()).sort();
        assert.deepEqual(naPasta, declaradas);
    });

    await contexto.test('e toda página com header tem container declarado (nenhuma medida por omissão)', () => {
        assert.deepEqual([...CONTAINER_POR_PAGINA.keys()].sort(), [...PAGINAS_COM_HEADER].sort());
    });

    for (const nome of PAGINAS_COM_HEADER) {
        const fonte = lerSemComentarios(`${DIR_PAGINAS_PUBLICADOR}/${nome}`);

        await contexto.test(`${nome} — importa e monta <PublicadorHeader>, uma vez só`, () => {
            assert.match(fonte, /import PublicadorHeader from '@\/Components\/Mlb\/Publicador\/header\/PublicadorHeader'/);
            assert.equal((fonte.match(/<PublicadorHeader\b/g) ?? []).length, 1);
        });

        await contexto.test(`${nome} — marca a aba ativa certa (aba="${ABA_POR_PAGINA.get(nome)}")`, () => {
            // ⚠️ A prop `aba` é lida DA TAG, não procurada no arquivo inteiro:
            // `aba="produtos"` solto apareceria em qualquer outro lugar da
            // página e a asserção passaria pelo motivo errado.
            const tag = fonte.match(/<PublicadorHeader\b[\s\S]*?(?:\/>|>)/);
            assert.ok(tag, 'tag de <PublicadorHeader> não encontrada');
            assert.match(tag[0], new RegExp(`aba="${ABA_POR_PAGINA.get(nome)}"`));
            // E só UMA prop `aba` na tag (nunca duas, nunca a de outra tela).
            assert.equal((tag[0].match(/\baba="/g) ?? []).length, 1);
        });

        await contexto.test(`${nome} — o header vem ANTES do container do corpo (HEAD-14)`, () => {
            const marca = CONTAINER_POR_PAGINA.get(nome);
            assert.ok(marca, `${nome} não tem container declarado em CONTAINER_POR_PAGINA`);
            const header = fonte.indexOf('<PublicadorHeader');
            const corpo = fonte.indexOf(marca);
            assert.ok(header !== -1, 'header não encontrado');
            assert.ok(corpo !== -1, `container do corpo ("${marca}") não encontrado em ${nome}`);
            assert.ok(header < corpo, `${nome}: o header nasceu DENTRO do container do corpo — com o padding dele o -mx-6 não encosta nas bordas`);
        });

        await contexto.test(`${nome} — passa cabecalho com o ?? null explícito`, () => {
            // O default de parâmetro só cobre `undefined`; prop NULA numa
            // recarga parcial do Inertia já derrubou tela neste projeto.
            assert.match(fonte, /cabecalho=\{cabecalho \?\? null\}/);
        });
    }

    for (const nome of PAGINAS_SEM_HEADER) {
        await contexto.test(`${nome} — segue SEM header de conta, de propósito`, () => {
            const fonte = lerSemComentarios(`${DIR_PAGINAS_PUBLICADOR}/${nome}`);
            assert.doesNotMatch(fonte, /<PublicadorHeader\b/);
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 186, plano 02 — D2 / AND-02 / critério 3: OS DOIS EIXOS SÃO SEPARADOS.
//
// README §C, literal: "São dois eixos separados. **O andamento interno nunca
// aparece na lista de Publicadas, e o status do ML nunca aparece na fila.**"
//
// POR QUE ISSO PRECISA DE GATE: os dois eixos têm rótulos parecidos e usam as
// mesmas cores. "Aprovado" (andamento interno) e "Ativa" (status no Mercado
// Livre) são os dois verdes; "Publicado" e "Ativa" descrevem momentos
// diferentes da mesma oferta. Misturá-los numa tela faz o usuário achar que
// aprovou algo que o ML ainda não aceitou — e nenhum teste de render pega isso,
// porque as duas telas renderizam perfeitamente com o rótulo errado.
//
// RESPOSTA DE GATE-01 (*o que precisaria estar errado para este gate falhar?*):
// alguém escrever "Aguardando aprovação" numa célula de `publicacoes/` (a lista
// de Publicadas), ou "Ativa"/"Pausada"/"Com erro" numa célula de `aprovacao/`
// (a fila do portão) — as duas coisas sendo ERROS DE EIXO, não de digitação.
//
// ⚠️ "Em revisão pela ECF" CONTÉM "Em revisão": a sonda daqui procura o literal
// COMPLETO, com borda nos dois lados. A negativa CURTA (o literal "Em revisão"
// cru, aposentado pelo plano 186-01) é cuidada por
// `publicador-rename-em-analise-ml.test.js`, que tem a lista de QUALIFICADOS e
// o auto-teste dela. ⚠️ As duas regras NÃO são duplicadas numa sonda só: juntas
// elas ficariam ambíguas, e nenhuma das duas diria por que reprovou.
//
// ⚠️ Leitura por `lerSemComentarios()`: a prosa pt-BR deste projeto cita os
// próprios rótulos — este bloco de comentário é a prova viva, porque ele mesmo
// escreve "Aguardando aprovação" e "Ativa". Um gate cru reprovaria o arquivo
// pelo COMENTÁRIO que explica o gate. MEDIDO: as 6 ocorrências de
// `Ativa`/`Com erro`/`Em análise pelo ML` em `publicacoes/` estão TODAS em
// comentário (`AbasDeStatus.jsx`:16/:19/:25, `CelulasDaLinha.jsx`:383/:386/:736).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Os 8 rótulos do andamento INTERNO (VOC-02), medidos em `STM`
 * (`design_handoff_publicador/Aprovacao.dc.html`:963-971).
 */
const ROTULOS_INTERNOS = [
    'Preparando',
    'Aguardando aprovação',
    'Em revisão pela ECF',
    'Ajustes solicitados',
    'Aprovado',
    'Publicando',
    'Falha na publicação',
    'Publicado',
];

/**
 * Os rótulos de status no MERCADO LIVRE, medidos em
 * `identidade/SeloDeStatusDaPublicacao.jsx` (as 4 primeiras) e em
 * `MlbAnuncioController::meus()` (a 5ª, a forma curta da aba).
 */
const ROTULOS_DO_ML = [
    'Ativa',
    'Pausada',
    'Com erro',
    'Em análise pelo Mercado Livre',
    'Em análise pelo ML',
];

/** Escapa TODOS os metacaracteres de regex, parênteses inclusive. */
function escaparParaRegex(bruto) {
    return bruto.replace(/[.*+?^${}()|[\]\\]/g, String.raw`\$&`);
}

/**
 * A SONDA DOS EIXOS: conta as ocorrências do literal COMPLETO num corpo.
 *
 * ⚠️ Borda nos DOIS lados, e a borda é de LETRA/DÍGITO Unicode — não `\b`, que
 * não entende acento. Sem isso:
 *   · "Ativa" casaria dentro de `chaveAtiva`, `ehAtiva` e `estaAtiva`, que são
 *     IDENTIFICADORES de `publicacoes/` e não rótulo nenhum (MEDIDO: as três
 *     existem em `AbasDeStatus.jsx` e `ChipsDeFiltro.jsx`);
 *   · "Publicado" casaria dentro de "Publicados" e "Publicadas".
 *
 * ⚠️ `String.raw` em todo padrão montado por string: `\b` dentro de template
 * literal comum vira BACKSPACE, e isso já esvaziou 4 asserções deste projeto.
 */
function contarLiteralCompleto(corpo, literal) {
    const padrao = new RegExp(
        String.raw`(?<![\p{L}\p{N}_])` + escaparParaRegex(literal) + String.raw`(?![\p{L}\p{N}_])`,
        'gu',
    );
    return (corpo.match(padrao) ?? []).length;
}

/** Lê os `.js`/`.jsx` de um diretório, ou devolve `null` quando ele não existe. */
function corposDoDiretorio(diretorio) {
    const absoluto = resolve(import.meta.dirname, '../..', diretorio);
    if (!existsSync(absoluto) || !statSync(absoluto).isDirectory()) return null;
    return readdirSync(absoluto)
        .filter((nome) => nome.endsWith('.js') || nome.endsWith('.jsx'))
        .map((nome) => ({ relativo: `${diretorio}/${nome}`, corpo: lerSemComentarios(`${diretorio}/${nome}`) }));
}

const CORPOS_DE_PUBLICACOES = corposDoDiretorio(DIR_PUB);
const CORPOS_DE_APROVACAO = corposDoDiretorio(DIR_APROV);

// ⚠️ ISCA DE CAMINHO: este diretório NÃO EXISTE e nunca vai existir. Ele prova
// que `corposDoDiretorio` devolve `null` (e o gate vira `skip` NOMEADO) em vez
// de uma lista vazia que pareceria "varrido e limpo". Este projeto acumulou
// SETE caminhos fantasma em 5 fases.
const DIR_ISCA = 'resources/js/Components/Mlb/Publicador/aprovacaoZZ9';
const CORPOS_DA_ISCA = corposDoDiretorio(DIR_ISCA);

test('AUTO-TESTE DA SONDA DOS EIXOS — ela conta o literal completo e nada mais', async (contexto) => {
    await contexto.test('acha o literal inteiro', () => {
        assert.equal(contarLiteralCompleto('o estado é Aprovado agora', 'Aprovado'), 1);
        assert.equal(contarLiteralCompleto('Aprovado e Aprovado', 'Aprovado'), 2);
    });

    await contexto.test('⚠️ NÃO conta substring: "Ativa" dentro de `chaveAtiva`', () => {
        assert.equal(contarLiteralCompleto('const chaveAtiva = 1; if (ehAtiva) {}', 'Ativa'), 0);
        assert.equal(contarLiteralCompleto("rotulo: 'Ativa'", 'Ativa'), 1);
    });

    await contexto.test('⚠️ NÃO conta "Publicado" dentro de "Publicados"/"Publicadas"', () => {
        assert.equal(contarLiteralCompleto('a lista de Publicados', 'Publicado'), 0);
        assert.equal(contarLiteralCompleto('as Publicadas de hoje', 'Publicado'), 0);
        assert.equal(contarLiteralCompleto("st: 'Publicado'", 'Publicado'), 1);
    });

    await contexto.test('⚠️ "Em revisão pela ECF" NÃO é achada pela busca de "Em revisão pelo Mercado Livre"', () => {
        assert.equal(
            contarLiteralCompleto('Em revisão pela ECF', 'Em revisão pelo Mercado Livre'),
            0,
        );
        // E o contrário: o literal completo de cada um é achado só por ele mesmo.
        assert.equal(contarLiteralCompleto('Em revisão pela ECF', 'Em revisão pela ECF'), 1);
    });

    await contexto.test('⚠️ literal com acento tem borda: "Falha na publicação" vs "...publicações"', () => {
        assert.equal(contarLiteralCompleto('Falha na publicações', 'Falha na publicação'), 0);
        assert.equal(contarLiteralCompleto('Falha na publicação.', 'Falha na publicação'), 1);
    });
});

test('D2 — a leitura dos dois diretórios não é vazia por construção', async (contexto) => {
    await contexto.test(`${DIR_PUB} existe e tem arquivos`, () => {
        assert.notEqual(CORPOS_DE_PUBLICACOES, null, `${DIR_PUB} não existe — o gate mediria NADA`);
        assert.ok(CORPOS_DE_PUBLICACOES.length >= 15, `varridos ${CORPOS_DE_PUBLICACOES.length}, esperava ao menos 15`);
    });

    await contexto.test(`${DIR_APROV} existe e tem arquivos`, () => {
        assert.notEqual(CORPOS_DE_APROVACAO, null, `${DIR_APROV} não existe — o gate mediria NADA`);
        assert.ok(CORPOS_DE_APROVACAO.length >= 3, `varridos ${CORPOS_DE_APROVACAO.length}, esperava ao menos 3`);
    });

    await contexto.test('⚠️ CONTROLE: a leitura de `publicacoes/` enxerga literal de verdade', () => {
        // Sem isto, a negativa do bloco seguinte poderia estar passando porque
        // `lerSemComentarios` devolveu vazio, e não porque os rótulos não estão
        // lá. "Rascunhos" é da 6ª opção do trilho (LIST-25) e está no CORPO de
        // `AbasDeStatus.jsx` (`ARIA_DA_FONTE`), fora de comentário.
        const achados = CORPOS_DE_PUBLICACOES
            .filter(({ corpo }) => contarLiteralCompleto(corpo, 'Rascunhos') > 0);
        assert.ok(achados.length >= 1, 'a leitura de `publicacoes/` está cega: nem "Rascunhos" ela acha');
    });

    await contexto.test('⚠️ ISCA DE CAMINHO: diretório ausente vira `null`, nunca lista vazia', () => {
        assert.equal(CORPOS_DA_ISCA, null, `a isca ${DIR_ISCA} passou a existir — troque a isca`);
    });
});

test('D2 — NENHUM rótulo de andamento INTERNO aparece em `publicacoes/`', async (contexto) => {
    if (CORPOS_DE_PUBLICACOES === null) {
        contexto.skip(`${DIR_PUB} não existe — gate pulado com nome, não em silêncio`);
        return;
    }

    for (const rotulo of ROTULOS_INTERNOS) {
        await contexto.test(`"${rotulo}" não está em nenhum arquivo de publicacoes/`, () => {
            for (const { relativo, corpo } of CORPOS_DE_PUBLICACOES) {
                // ⚠️⚠️ A ÚNICA EXCEÇÃO, com o veto escrito — Fase 187, plano 03.
                //
                // `AbasDaPagina.jsx` é a NAVEGAÇÃO para o outro eixo, não um
                // renderizador de andamento: "Aguardando aprovação" aparece lá
                // como RÓTULO DE ABA no cabeçalho, exigido literalmente por
                // ABA-01 e ABA-02 (`REQUIREMENTS-v27.md:310`-`:317`).
                //
                // E o D2 NÃO proíbe isso. O texto dele é *"o andamento interno
                // nunca aparece **na lista de Publicadas**"* e termina com *"Isto
                // é **regra de dado, não de tela**"* (`REQUIREMENTS-v27.md:51`-
                // `:53`). O gate implementou a regra por um PROXY de tela — *"em
                // nenhum arquivo de `publicacoes/`"* — e o proxy ficou MAIS LARGO
                // que o invariante: ele reprova o cabeçalho que leva à fila, que
                // é justamente o que separa os dois eixos em vez de misturá-los.
                //
                // ⚠️ A exceção é de UM arquivo e de UM rótulo, e NÃO afrouxa o
                // gate: os outros 7 rótulos internos continuam proibidos em
                // `AbasDaPagina.jsx` (sub-teste próprio, abaixo) e "Aguardando
                // aprovação" continua proibida nos outros 15 arquivos do
                // diretório (outro sub-teste próprio, também abaixo, coletando os
                // infratores). Onde havia UMA asserção agora há TRÊS.
                const excecaoDaAba = relativo === `${DIR_PUB}/AbasDaPagina.jsx`
                    && rotulo === 'Aguardando aprovação';
                if (excecaoDaAba) continue;

                assert.equal(
                    contarLiteralCompleto(corpo, rotulo),
                    0,
                    `${relativo} usa o rótulo de ANDAMENTO INTERNO "${rotulo}" — erro de EIXO:`
                    + ' o andamento interno nunca aparece na lista de Publicadas (README §C)',
                );
            }
        });
    }

    await contexto.test('⚠️ a exceção da aba é NAVEGAÇÃO: os outros 7 rótulos internos NÃO estão nela', () => {
        // O que precisaria estar errado para este gate falhar: `AbasDaPagina.jsx`
        // virar um renderizador de andamento (um selo, uma célula, um mapa de
        // estados) em vez de duas abas. A exceção acima cobre UM rótulo; este
        // sub-teste é o que impede que ela se transforme numa porta larga.
        const aba = CORPOS_DE_PUBLICACOES.find(
            ({ relativo }) => relativo === `${DIR_PUB}/AbasDaPagina.jsx`,
        );
        assert.notEqual(aba, undefined, 'AbasDaPagina.jsx não foi varrido — a exceção acima mediria NADA');

        // CONTROLE POSITIVO: o rótulo da exceção ESTÁ lá, fora de comentário.
        // Sem ele, a exceção poderia estar protegendo um arquivo que nem usa o
        // literal, e as negativas abaixo seriam vazias por construção.
        assert.ok(
            contarLiteralCompleto(aba.corpo, 'Aguardando aprovação') > 0,
            'o rótulo da aba saiu de AbasDaPagina.jsx — então a exceção do D2 não tem mais razão de existir',
        );

        const intrusos = ROTULOS_INTERNOS
            .filter((rotulo) => rotulo !== 'Aguardando aprovação')
            .filter((rotulo) => contarLiteralCompleto(aba.corpo, rotulo) > 0);
        assert.deepEqual(
            intrusos,
            [],
            'AbasDaPagina.jsx passou a desenhar andamento interno — a exceção do D2 vale SÓ para o rótulo da aba',
        );
    });

    await contexto.test('⚠️ e "Aguardando aprovação" continua PROIBIDA nos outros arquivos de publicacoes/', () => {
        // Esta é a asserção que PRESERVA o que o D2 provava antes do reaponte —
        // com os infratores COLETADOS, em vez de reprovar no primeiro.
        const infratores = CORPOS_DE_PUBLICACOES
            .filter(({ relativo }) => relativo !== `${DIR_PUB}/AbasDaPagina.jsx`)
            .filter(({ corpo }) => contarLiteralCompleto(corpo, 'Aguardando aprovação') > 0)
            .map(({ relativo }) => relativo);
        assert.deepEqual(
            infratores,
            [],
            'o rótulo de ANDAMENTO INTERNO "Aguardando aprovação" vazou para a lista de Publicadas — erro de EIXO',
        );
        // ⚠️ E a varredura NÃO é de um arquivo só: sem isto, o `filter` acima
        // poderia estar sobrando zero corpos e o gate seria vazio.
        assert.ok(
            CORPOS_DE_PUBLICACOES.length - 1 >= 15,
            `sobraram ${CORPOS_DE_PUBLICACOES.length - 1} arquivos para varrer, esperava ao menos 15`,
        );
    });

    await contexto.test('⚠️ CONTROLE POSITIVO: a MESMA sonda acha os 8 em `aprovacao/`', () => {
        // Sem este controle a negativa acima seria vazia por construção: uma
        // sonda quebrada reportaria 0 em tudo e o gate pareceria verde.
        //
        // ⚠️ O controle positivo da direção INTERNA tem de vir de `aprovacao/`,
        // porque por definição não há rótulo interno em `publicacoes/`.
        const corpoDoAndamento = lerSemComentarios(`${DIR_APROV}/andamentoDoPortao.jsx`);
        for (const rotulo of ROTULOS_INTERNOS) {
            assert.ok(
                contarLiteralCompleto(corpoDoAndamento, rotulo) > 0,
                `a sonda não acha "${rotulo}" nem em andamentoDoPortao.jsx — ela está cega`,
            );
        }
    });
});

test('D2 — NENHUM rótulo de status do Mercado Livre aparece em `aprovacao/`', async (contexto) => {
    if (CORPOS_DE_APROVACAO === null) {
        contexto.skip(`${DIR_APROV} não existe — gate pulado com nome, não em silêncio`);
        return;
    }

    for (const rotulo of ROTULOS_DO_ML) {
        await contexto.test(`"${rotulo}" não está em nenhum arquivo de aprovacao/`, () => {
            for (const { relativo, corpo } of CORPOS_DE_APROVACAO) {
                assert.equal(
                    contarLiteralCompleto(corpo, rotulo),
                    0,
                    `${relativo} usa o rótulo de STATUS DO MERCADO LIVRE "${rotulo}" — erro de EIXO:`
                    + ' o status do ML nunca aparece na fila do portão (README §C)',
                );
            }
        });
    }

    await contexto.test('⚠️ CONTROLE POSITIVO: a MESMA sonda acha os 4 rótulos do ML onde eles moram', () => {
        // ⚠️ O 5º ("Em análise pelo ML", a forma curta da aba) NÃO está aqui de
        // propósito: ele vive no PHP (`MlbAnuncioController::meus()`), medido
        // pelo plano 186-01. Afirmar que ele estaria no `.jsx` seria um controle
        // que passa pelo motivo errado.
        const corpoDoSelo = lerSemComentarios(`${DIR}/SeloDeStatusDaPublicacao.jsx`);
        for (const rotulo of ['Ativa', 'Pausada', 'Com erro', 'Em análise pelo Mercado Livre']) {
            assert.ok(
                contarLiteralCompleto(corpoDoSelo, rotulo) > 0,
                `a sonda não acha "${rotulo}" nem em SeloDeStatusDaPublicacao.jsx — ela está cega`,
            );
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Fase 186, plano 02 — VOC-03: "LUCRO LÍQUIDO" É PROIBIDO.
//
// POR QUE: o número que a tela mostra NÃO desconta imposto, taxa de cartão,
// despesa fixa nem devolução. Chamá-lo de lucro líquido seria uma afirmação
// FALSA sobre o dinheiro do cliente — e é o tipo de erro que ninguém reporta
// como bug, porque a tela "funciona". O vocabulário travado é "Resultado
// estimado por venda" ou "Margem de contribuição estimada".
//
// RESPOSTA DE GATE-01: alguém rotular a coluna de resultado como "Lucro
// líquido" porque é o termo que o cliente usa na conversa.
//
// ⚠️⚠️ A SONDA É RECORTADA À LISTA CONSTANTE DOS 3 ARQUIVOS NOVOS, e isto é
// MEDIÇÃO, não cautela: existe um campo REAL chamado `lucro_liquido` em
// `EstruturaPrecificacaoParametros` — legítimo, do Portal, de outra fase. Uma
// sonda que varresse `resources/` ou `app/` reprovaria aquele parâmetro antigo
// e o executor seguinte "consertaria" renomeando coisa que não é dele.
//
// ⚠️ E ela pega AS DUAS FORMAS: `lucro líquido` (com acento e espaço, o rótulo
// de tela) e `lucro_liquido` (sem acento, a forma de identificador). Procurar
// só uma delas deixaria a outra passar.
// ═══════════════════════════════════════════════════════════════════════════

const ARQUIVOS_DO_PORTAO = ARQUIVOS_NOVOS.filter((c) => c.startsWith(`${DIR_APROV}/`));

/** As duas formas proibidas, insensíveis a maiúscula. */
const FORMAS_DE_LUCRO = [/lucro\s+l[ií]quido/i, /lucro_l[ií]quido/i];

/**
 * Os 3 arquivos do portão que a Fase 186 criou, POR NOME.
 *
 * ⚠️⚠️ REAPONTE DA FASE 187, PLANO 04, com o veto escrito. Antes a asserção
 * abaixo era `assert.equal(ARQUIVOS_DO_PORTAO.length, 3)` — um número do
 * DIRETÓRIO fazendo as vezes de prova sobre uma FASE. Com ele, **todo plano da
 * Fase 187 que criasse componente em `aprovacao/` teria de subir o número**, que
 * é a régua que sobe a cada plano (learnings §4b.2); e subir o número aceitaria
 * a REMOÇÃO de um arquivo da 186 compensada pela criação de um da 187.
 *
 * ⚠️ O que ficou no lugar é ESTRITAMENTE MAIS FORTE: os três NOMES têm de estar
 * na lista varrida (infratores coletados) **e** a lista não pode encolher. É a
 * mesma cirurgia que o plano 187-03 fez em `DA_FASE_180`.
 */
const DO_PORTAO_DA_FASE_186 = [
    `${DIR_APROV}/andamentoDoPortao.jsx`,
    `${DIR_APROV}/vocabularioDoPortao.jsx`,
    `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
];

test('VOC-03 — "lucro líquido" não existe em nenhum arquivo novo do portão', async (contexto) => {
    await contexto.test('a lista varrida não está vazia e não encolheu', () => {
        // ⚠️ Os três da Fase 186, por NOME — e não "o tamanho do diretório".
        const faltando = DO_PORTAO_DA_FASE_186.filter((c) => !ARQUIVOS_DO_PORTAO.includes(c));
        assert.deepEqual(
            faltando,
            [],
            'arquivo do portão da Fase 186 saiu da lista varrida — a sonda de VOC-03 mediria MENOS',
        );
        assert.ok(
            ARQUIVOS_DO_PORTAO.length >= DO_PORTAO_DA_FASE_186.length,
            `a lista do portão tem ${ARQUIVOS_DO_PORTAO.length} arquivo(s) e encolheu abaixo dos 3 da Fase 186`,
        );
    });

    for (const caminho of ARQUIVOS_DO_PORTAO) {
        await contexto.test(`${caminho} — nenhuma das duas formas de "lucro líquido"`, () => {
            const fonte = FONTES.get(caminho);
            assert.ok(fonte, `${caminho} não está em FONTES — não foi lido`);
            for (const forma of FORMAS_DE_LUCRO) {
                assert.doesNotMatch(fonte, forma, `${caminho} diz "lucro líquido" — VOC-03 proíbe`);
            }
        });
    }

    await contexto.test('⚠️ ISCA / CONTROLE POSITIVO: a MESMA sonda ACHA "Resultado estimado por venda"', () => {
        // Negativa sozinha é metade do gate: sem este controle, um `FONTES.get`
        // devolvendo vazio faria as 3 asserções acima passarem sem ler nada.
        const vocabulario = FONTES.get(`${DIR_APROV}/vocabularioDoPortao.jsx`);
        assert.ok(vocabulario, 'vocabularioDoPortao.jsx não foi lido');
        assert.match(
            vocabulario,
            /Resultado estimado por venda/,
            'o literal travado de VOC-03 não está no corpo — a sonda está lendo o arquivo errado',
        );
        assert.match(vocabulario, /Margem de contribuição estimada/);
    });

    await contexto.test('⚠️ e a sonda REPROVA as duas formas quando elas existem (auto-teste)', () => {
        // Prova que as duas regexes não são decorativas, sem precisar sujar
        // nenhum arquivo do repositório.
        for (const forma of FORMAS_DE_LUCRO) {
            const exemplos = ['o lucro líquido da oferta', 'campo lucro_liquido do parâmetro', 'LUCRO LIQUIDO'];
            assert.ok(
                exemplos.some((exemplo) => forma.test(exemplo)),
                'uma das formas de "lucro líquido" não casa com nenhum exemplo — regex decorativa',
            );
        }
        // E o controle negativo: o vocabulário travado NÃO casa com elas.
        for (const forma of FORMAS_DE_LUCRO) {
            assert.ok(!forma.test('Resultado estimado por venda'), 'a sonda reprovaria o vocabulário CERTO');
            assert.ok(!forma.test('Margem de contribuição estimada'), 'a sonda reprovaria o vocabulário CERTO');
        }
    });
});
