---
phase: 143-grupo-de-cobranca-acima-dos-subgrupos
plan: "03"
subsystem: fullstack
tags: [fechamento, cobranca, company-groups, tabela-progressiva, auditoria, ui]

requires:
  - phase: 143-01
    provides: "company_groups.parent_id, raizId()/raiz(), precedência raiz → subgrupo → empresa"
  - phase: 143-02
    provides: "agregação pela raiz em todo lugar, SimuladorGrupoCobrancaService, rotas de hierarquia"
  - phase: 142-01
    provides: "GravarTabelaEmpresaService — o molde da porta única com trilha explícita"
provides:
  - "GravarTabelaGrupoService — porta única de escrita em grupo_faixas_faturamento, com trilha antes/depois"
  - "GET admin.contratos.tabela.grupo.show + Pages/Admin/TabelaGrupo.jsx — a tabela do grupo virou alcançável"
  - "A trilha registra quantas empresas a tabela alcança (grupo + grupos que fazem parte dele)"
affects: [143-04]

tech-stack:
  added: []
  patterns:
    - "Trilha de auditoria explícita no serviço, não delegada ao LogsActivity: delete de query builder não dispara evento de model"
    - "UMA entrada de activity_log por gravação, com a tabela INTEIRA antes e depois — nunca uma entrada por linha"
    - "A trilha guarda o TAMANHO da decisão (empresas alcançadas), não só o conteúdo"
    - "O bloco que conta e lista as empresas alcançadas vem ANTES do formulário na tela"

key-files:
  created:
    - app/Services/Fechamento/GravarTabelaGrupoService.php
    - resources/js/Pages/Admin/TabelaGrupo.jsx
    - tests/Feature/Phase143/Phase143GravarTabelaGrupoTest.php
    - tests/Feature/Phase143/Phase143TabelaGrupoPaginaTest.php
    - tests/Feature/Phase143/Phase143TabelaGrupoUiTest.php
  modified:
    - app/Http/Controllers/TabelaEmpresaContratoController.php
    - app/Http/Controllers/FechamentoController.php
    - resources/js/Pages/Admin/TabelaEmpresa.jsx
    - routes/web.php
    - .planning/phases/143-grupo-de-cobranca-acima-dos-subgrupos/deferred-items.md

key-decisions:
  - "O gêmeo em FechamentoController entrou junto (Regra 2). O plano nomeava só TabelaEmpresaContratoController, mas a rota antiga /financeiro/faixas/grupo tinha o MESMO delete() sem trilha, na mesma tabela, governando as mesmas empresas — e o docblock de lá já mandava as duas cópias morrerem juntas."
  - "A trilha grava `empresas_governadas` (grupo + grupos que fazem parte dele). Quem audita uma cobrança errada precisa do tamanho da decisão, não só do conteúdo: 10 mensalidades ou 2 é a diferença que a Fase 143 inteira existe para tornar visível."
  - "FormularioFaixas NÃO foi extraído para componente compartilhado: Phase142FichaTabelaUiTest trava o TEXTO de TabelaEmpresa.jsx asserção por asserção, e a extração quebraria aquele gate. Segunda definição local, com o porquê no docblock e no deferred-items (item 8)."
  - "paraGrupo($grupo, null) — sem âncora de propósito na página do grupo. A pergunta ali é 'existe tabela cadastrada neste grupo ou no grupo em que ele está?', não 'o que a empresa que mais faturou emprestaria'."
  - "A lista de empresas inclui as inativas, marcadas — esconder cadastro inativo faria a contagem da tela divergir da contagem da trilha."

metrics:
  duration: "~3h"
  completed: 2026-09-14
  tarefas: 3
  testes_novos: 36
---

# Fase 143 Plano 03: a tabela do grupo ganhou porta de escrita e página própria — Summary

Duas coisas que faltavam ao caminho de GRUPO: **trilha de auditoria** (a tabela anterior evaporava sem registro nenhum) e **uma página onde ela possa ser cadastrada** (o grupo que fica por cima dos outros pode não ter empresa nenhuma pendurada direto nele — a tabela que governa a cobrança de dez empresas era literalmente inalcançável pela tela). Nada mudou de cobrança: `grupo_faixas_faturamento` continua zerada em produção e os 15 grupos seguem com `parent_id` nulo.

## T1 — O buraco de auditoria, fechado

`TabelaEmpresaContratoController::gravarFaixasGrupo()` fazia `GrupoFaixaFaturamento::where(...)->delete()` seguido de `create()` em laço, e o arquivo inteiro tinha **zero** `activity()`. Delete de query builder **não dispara evento de model**, então o `LogsActivity` do `GrupoFaixaFaturamento` nunca via as linhas apagadas: sobrava rastro só das linhas novas, e **a tabela anterior sumia**.

`GravarTabelaGrupoService` é o gêmeo de `GravarTabelaEmpresaService` (Fase 142), com a mesma forma e o mesmo docblock explicando *por que* a trilha é explícita em vez de delegada ao model:

| | |
|---|---|
| transação, all-or-nothing | array vazio recusado com `RuntimeException` — quem apaga usa `remover()` |
| trilha | **uma** entrada por gravação, tabela INTEIRA `antes` e `depois` |
| `log_name` | `faixa_faturamento_tabela_grupo` (irmão de `faixa_faturamento_tabela`) |
| sujeito | o `CompanyGroup` |
| a mais que o gêmeo | `empresas_governadas` — quantas empresas a tabela alcança |

**Diferença deliberada em relação ao gêmeo:** `grupo_faixas_faturamento` não tem coluna `origem` (tabela de grupo é sempre cadastro humano), então não há trava de precedência das três origens a aplicar aqui — e a trilha não carrega `origem_anterior`/`origem_nova`.

### `empresas_governadas`: por que a trilha guarda o tamanho da decisão

Com a árvore da Fase 143, a tabela da raiz governa todas as empresas abaixo — no caso MPozenato, **10 de uma vez**. Uma trilha que diz "a tabela mudou de R$ 21.000 para R$ 12.000" sem dizer *para quantos clientes* deixa quem audita sem a informação que importa. A conta é uma query (grupo + grupos pendurados nele), fora de qualquer laço, e entra também na descrição legível da entrada.

### O teste que mais importa

`substituir_tabela_existente_registra_a_tabela_antiga_no_antes` grava uma tabela de três faixas (R$ 21.000 / R$ 27.000 / R$ 33.000), substitui por outra de duas, e exige que `properties['antes']` traga **as três faixas antigas com os valores e os tetos exatos**. É precisamente o registro que evaporava.

## T2 — A página própria

`GET /administrativo/contratos/grupo/{grupo}/tabela` → `Admin/TabelaGrupo.jsx`, no molde de `Admin/TabelaEmpresa.jsx`, **no mesmo grupo de permissão das rotas de escrita** (`admin.contratos`) — quem pode salvar precisa poder abrir; rota fora daquele grupo reabriria o 403 no botão Salvar que a Fase 142 pagou para fechar.

A ordem dos blocos é a decisão de desenho:

1. **Este grupo faz parte de X** (só quando tem pai) — quem manda é a tabela de lá, com botão que leva para a página dela.
2. **O que está cobrando estas empresas hoje** — `FechamentoFaixaResolver::paraGrupo()`, dizendo de **qual** grupo a tabela é quando não é deste.
3. **Empresas que esta tabela vai cobrar** — ⚠️ vem **antes** do formulário, contadas e **listadas uma a uma**, cada uma com o nome do grupo de onde veio, mais os grupos que fazem parte deste com a contagem de cada um.
4. **Tabela deste grupo** — o formulário, com "começar a partir de" reusando `modelosDePartida()`.

O bloco 3 é a razão de a página ter este desenho: uma frase ("vale para o grupo inteiro") não faz ninguém perceber que está mexendo em 10 mensalidades. A lista faz. E a confirmação de salvar repete o número: *"Esta tabela passa a valer para 8 empresas. Salvar agora?"*.

**Reuso, não reimplementação:** a grade (`TabelaProgressivaFaixas`), a máscara (`CampoDinheiro`) e a conversão de borda (`lib/faixasFaturamento.js` — teto `,99` ↔ valor redondo, com a regra do `indiceDeGravacao`) vêm todas do compartilhado. Há teste proibindo uma segunda definição de qualquer uma delas neste arquivo.

**Copy sem jargão,** com teste travando **11** termos: os 8 herdados das Fases 139/142 (`snapshot`, `reconsolidação`, `rollup`, `âncora`, `competência`, `origem`, `faixa piso`, `presumida`) mais os **3** desta fase — `raiz`, `árvore`, `precedência`. A tela fala de "grupo" e "grupos que fazem parte dele".

## T3 — O caminho de entrada

Na ficha da empresa, dentro do bloco do grupo que já existia: botão **"Abrir a página do grupo X"**. Foi a única alteração em `TabelaEmpresa.jsx` — a trava do plano foi respeitada à risca, e `Phase142FichaTabelaUiTest` continua passando inteiro. A página do grupo também linka para os grupos de dentro e para a ficha de cada empresa, então a navegação fecha nos dois sentidos.

## Desvios do plano

### [Regra 2 — funcionalidade crítica ausente] O gêmeo em `FechamentoController` tinha o mesmo buraco

- **Achado em:** T1, lendo o docblock de `salvarGrupo()`, que nomeia a cópia e diz "as duas cópias precisam morrer juntas se um dia essa rota antiga sair".
- **Problema:** `FechamentoController::salvarFaixasGrupo()`/`removerFaixasGrupo()` (rota `admin.financeiro.faixas.grupo`, viva e admin-only) faziam **exatamente** o mesmo `delete()` de query builder sem `activity()`, na **mesma tabela**, governando **as mesmas empresas**. Fechar o buraco só de um lado deixaria a auditoria dependente de qual tela a pessoa usou — e é a tela do fechamento que o Administrativo abre todo mês.
- **Correção:** os dois métodos passaram a delegar para a mesma porta, com `feitoDe: 'fechamento'` (o irmão `removerFaixasEmpresa` já fazia isso desde a Fase 142). O import órfão de `GrupoFaixaFaturamento` saiu junto.
- **Coberto por:** `Phase138|Phase139` — 107 testes, todos passando depois da mudança.
- **Commit:** `e073bf8d`.

Fora isso, o plano foi executado como escrito.

## Decisões que tomei sozinho

1. **`FormularioFaixas` não foi extraído para componente compartilhado.** Era o reflexo óbvio (e o docblock da Fase 142 diz que duas cópias do formulário é "o mesmo erro da grade duplicada"), mas `Phase142FichaTabelaUiTest` trava o **texto** de `TabelaEmpresa.jsx` asserção por asserção — conta `type="number"`, exige `<CampoDinheiro` e o import da grade *naquele arquivo*. Extrair quebraria o gate que eu não posso regredir. O que importa não está duplicado: a conversão de borda e a grade continuam com uma definição só; o que se repete é a fiação. Registrado como item 8 do `deferred-items.md`, para ser feito de propósito com o teste atualizado no mesmo commit.
2. **`empresas_governadas` na trilha.** O plano pedia `antes`/`depois`; acrescentei a contagem porque a própria razão da Fase 143 é que ninguém percebia estar mexendo em dez clientes de uma vez. Sem esse número, a trilha registra o conteúdo da decisão e perde a escala dela.
3. **`paraGrupo($grupo, null)` — sem âncora.** A pergunta da página do grupo é "existe tabela cadastrada aqui ou no grupo em que este está?". Passar uma âncora traria a herança da empresa que mais faturou, que é assunto da ficha de empresa e confundiria o cadastro do grupo.
4. **A lista mostra empresas inativas, marcadas como tal.** Filtrar faria a contagem da tela divergir da contagem gravada na trilha (que não filtra) — duas verdades sobre o mesmo conjunto.
5. **Aviso de substituição informando o tamanho:** ao salvar por cima de uma tabela existente, a mensagem diz quantas empresas aquela tabela vale. Neutro, não é erro — a pessoa pode ter substituído de propósito.
6. **Nomes de rota e componente no padrão da casa:** `admin.contratos.tabela.grupo.show` (irmão de `.salvar`/`.remover`) e `Admin/TabelaGrupo` (irmão de `Admin/TabelaEmpresa`).

## O que decidi NÃO fazer

Tudo em `deferred-items.md` (itens 8 e 9, novos), com o porquê:

1. **Extrair o formulário compartilhado** — item 8, pelo motivo acima.
2. **Mostrar faturamento e mensalidade resultante na página do grupo** — item 9. O dado existe pronto no `SimuladorGrupoCobrancaService` (143-02), mas o 143-03 é a página de **cadastro** da tabela; o simulador é a peça da tela de montagem da hierarquia (143-04). Plugá-lo aqui misturaria as duas telas antes de a segunda ser desenhada.
3. **Não toquei em `classificar()`**, nem em `NpsGrupoCoberturaService`, `NpsGroupSurvey` ou a migration de `nps_group_surveys` — proibição explícita, nem para leitura de edição.
4. **Não mexi em `TabelaEmpresa.jsx` além do link de entrada.**
5. **Não pendurei ninguém, não cadastrei tabela nenhuma.** Sem deploy, sem `.env`, sem tocar em `fechamento_faturamento_da_api_ativo`, e **`state.advance-plan` não foi executado**.

## O NPS não sentiu nada

Nenhuma empresa é remanejada de grupo — há teste explícito comparando `companies.company_group_id` de todas as empresas antes e depois de cadastrar a tabela, por igualdade de coleção.

⚠️ **A suíte de NPS mudou de números desde o 143-02, e a causa não é este plano — está provada por execução, não por dedução.**

| | 143-02 (baseline documentado) | agora |
|---|---|---|
| testes | 614 | 614 |
| errors (`UNIQUE constraint failed: setores.nome`) | 47 | **0** |
| failures | 4 | **8** |
| passando | 563 | **606** |

A outra sessão corrigiu a migration que causava os 47 errors; 43 daqueles testes passaram a passar e 4 passaram a **falhar** (estavam errorando antes, não passando). **Nenhum teste de NPS foi de passando para falhando.**

**A prova:** restaurei os três arquivos PHP alterados (`FechamentoController`, `TabelaEmpresaContratoController`, `routes/web.php`) ao commit anterior ao meu primeiro commit (`40b53589`) via `git checkout <base> -- <arquivos>` — sem `git stash` — e rodei as seis classes que falham:

```
--filter="NpsMaterializarNaoRespondidosCommandTest|CompanyScoreServiceStatusTest|Phase31NpsSubmitTest|NpsPhase69IntegrationTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest"
SEM as minhas mudanças: 11 failed, 29 passed  (as mesmas 8, mais 3 do Phase119 que oscilam)
```

Os arquivos foram devolvidos com `git checkout HEAD -- ...` e `git diff HEAD -- app/ routes/` voltou **vazio**. Reforço por grep: as seis classes têm **zero** referências a `GrupoFaixaFaturamento`, `GravarTabelaGrupo`, `TabelaEmpresaContrato`, `FechamentoController`, `admin.contratos` ou `company_group`, e os únicos consumidores do serviço novo são os dois controllers que elas não exercitam.

As 8 falhas são de janela de NPS no `desempenho:consolidar-mes`, política de `expires_at` e hash de token — as quatro já documentadas no 143-01 mais quatro que estavam mascaradas pelos errors alheios. **Não consertei nenhuma.**

## Gate

Exit code capturado **antes** de qualquer pipe.

**Gate 1** — `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911"`

| | baseline (antes de tocar em nada) | depois |
|---|---|---|
| resultado | `Tests: 736 passed (3337 assertions)` | `Tests: 772 passed (3479 assertions)` |
| exit code | `EXIT=0` | `EXIT=0` |
| failures / errors | **0 / 0** | **0 / 0** |

772 = 736 + **36 testes novos** (9 do serviço + 17 da página/rotas + 10 das travas de arquivo). **Zero falhas, zero errors, nenhuma regressão.**

⚠️ O baseline medido nesta sessão é **736 passando / 0 falhas**, e não os "708 passando + 28 errors" do 143-02: os 28 errors de `setores.nome` sumiram (correção da outra sessão). O número de referência do prompt (736 passando) bate exatamente.

**Gate 2** — `--filter="Phase74|Phase110"`

| | baseline | depois |
|---|---|---|
| resultado | `Tests: 39 passed (170 assertions)` | `Tests: 39 passed (170 assertions)` |
| exit code | `EXIT=0` | `EXIT=0` |

**Build:** `npm run build` — `✓ built in 46.54s`, sem aviso. `Admin/TabelaGrupo.jsx` está no `manifest.json` (a armadilha da página que some do manifest, registrada em `painel-polos-status-e-meta.md`). Conferência do CSS compilado com `grep -F` sobre o seletor completo, como o prompt manda — as seis classes menos triviais que usei (`.px-\[18px\]`, `.divide-white\/\[0\.06\]`, `.gap-1\.5`, `.h-7`, `.py-2\.5`, `.text-\[11px\]`) têm **1 ocorrência cada** no `app-*.css`. Nenhuma classe fora da escala do Tailwind (há teste regex travando isso).

## Commits

| hash | assunto |
|---|---|
| `e073bf8d` | `feat(143-03): porta unica de escrita da tabela do grupo, com trilha de auditoria` |
| `6d1058d8` | `feat(143-03): pagina propria da tabela de cobranca do grupo` |

Todos com `git add` por caminho (árvore compartilhada com a outra sessão ativa na v23.0) — nunca `git add -A`/`git add .`, nunca `git commit -a`, nunca `git stash`. `git status --porcelain app/ tests/ resources/ routes/` conferido antes de cada commit; o único item alheio na área (`tests/Feature/CompanyPortfolioAccessTest.php`, não rastreado, da outra sessão) ficou intocado do começo ao fim.

## Próximo passo

A tela de montagem da hierarquia (143-04) já tem as três rotas do 143-02 e agora também a página onde a tabela do grupo de cobrança é cadastrada — a rota `admin.contratos.tabela.grupo.show` está nomeada e pronta para ela linkar. O item 9 do `deferred-items.md` (mostrar faturamento e mensalidade resultante) é a ponte natural entre as duas telas.

## Self-Check: PASSED

Os 9 arquivos declarados existem em disco e os 2 commits existem no histórico, conferidos um a um.
