---
phase: 143-grupo-de-cobranca-acima-dos-subgrupos
plan: 02
subsystem: backend
tags: [fechamento, cobranca, company-groups, previa, auditoria, rotas]

requires:
  - phase: 143-01
    provides: "company_groups.parent_id, CompanyGroup::raizId()/raiz()/validarPaiOuFalhar(), precedência raiz → subgrupo → empresa"
  - phase: 141
    provides: "flag fechamento_tabela_por_empresa_ativa (LIGADA em produção), CobrancaCalculator::mensalidade()"
  - phase: 142
    provides: "GravarTabelaEmpresaService — molde da trilha de auditoria com antes/depois"
provides:
  - "AdminController — os dois ramos do fechamento (ao vivo e congelado) agregam pela RAIZ"
  - "CompararMensalidadeFechamento — a conferência antes×depois agrupa pela RAIZ"
  - "SimuladorGrupoCobrancaService — a prévia PURA do impacto na cobrança, com a procedência da tabela"
  - "admin.contratos.grupos.hierarquia.previa/pendurar/despendurar + trilha em activity_log"
affects: [143-03]

tech-stack:
  added: []
  patterns:
    - "Simulação de árvore em MEMÓRIA (clone do model + setRelation('pai')) — o resolver enxerga a hierarquia hipotética sem uma única escrita"
    - "`parent_id` explícito na lista de colunas do eager loading: `grupo:id,name,color` sem ele faria `raizId()` devolver o subgrupo em silêncio"
    - "A trilha de auditoria grava a PRÉVIA do momento da decisão, não um recálculo posterior"
    - "Recusa vem do `saving()` do model e é convertida em ValidationException — nenhuma cópia da regra na rota"

key-files:
  created:
    - app/Services/Fechamento/SimuladorGrupoCobrancaService.php
    - app/Http/Controllers/GrupoCobrancaHierarquiaController.php
    - tests/Feature/Phase143/Phase143AgregacaoCompletaPelaRaizTest.php
    - tests/Feature/Phase143/Phase143SimuladorGrupoCobrancaTest.php
    - tests/Feature/Phase143/Phase143HierarquiaRotasTest.php
  modified:
    - app/Console/Commands/CompararMensalidadeFechamento.php
    - app/Http/Controllers/AdminController.php
    - routes/web.php
    - .planning/phases/143-grupo-de-cobranca-acima-dos-subgrupos/deferred-items.md

key-decisions:
  - "O ramo CONGELADO de `fechamentoAgregarGruposCongelados()` entrou junto (Regra 2). O plano nomeava só o ao vivo, mas o congelado indexa `fechamento_grupo_snapshots` por `company_group_id`, que desde o 143-01 guarda a RAIZ — agrupando por subgrupo, `$s` viria nulo e a tela exibiria linhas de grupo em BRANCO numa competência fechada. É o ramo que a tela usa depois de consolidar, ou seja, o que a pessoa mais olha."
  - "`parent_id` teve de entrar na LISTA DE COLUNAS do eager loading (`grupo:id,name,color,parent_id`). Sem isso o Eloquent não traz a coluna, `raizId()` cai no `?? id` e devolve o subgrupo — silenciosamente, sem erro nenhum. Era a armadilha mais barata de cair e a mais cara de achar."
  - "O simulador clona os models e injeta `setRelation('pai')` em vez de mutar os originais: models sujos circulando pela requisição transformariam qualquer `save()` posterior numa gravação de hierarquia que ninguém aprovou."
  - "A prévia da trilha é calculada ANTES da escrita. Calcular depois daria o retrato do mundo já mudado, que não serve para auditar a decisão."
  - "Competência padrão da prévia é o MÊS ANTERIOR (último mês-calendário completo), não o corrente — o mês em curso ainda está somando faturamento e daria um número menor que o real."
  - "`relatorioVinculadasDoGrupo()` (PDF individual) passou a listar a árvore inteira — era a terceira leitura de grupo por `company_group_id` cru, não nomeada pelo plano."

metrics:
  duration: "~3h"
  completed: 2026-09-14
  tarefas: 3
  testes_novos: 29
---

# Fase 143 Plano 02: a agregação pela raiz em todo lugar, e a prévia do impacto — Summary

Os dois pontos que o 143-01 deixou agrupando por `company_group_id` cru passaram a agregar pela **raiz** — mais um terceiro que ninguém tinha visto. E nasceu o `SimuladorGrupoCobrancaService`: a prévia **pura** que responde, antes de qualquer gravação, quanto o cliente passa a pagar se os grupos forem pendurados — com a procedência da tabela que governa o número. As rotas de pendurar/despendurar existem, auditadas. **Nada visível ainda** (a tela é o 143-03) e, com `parent_id` nulo nos 15 grupos, o comportamento é idêntico ao de hoje.

## T1 — A agregação pela raiz nos lugares que faltavam

Comecei pelo `CompararMensalidadeFechamento`, como o prompt pediu, e a razão é a que o plano dá: **é a ferramenta de conferência antes×depois que protege o usuário na hora de aprovar a montagem em produção.** Se ela agrupa errado, a conferência mostra o número errado — e é justamente o número em que a decisão se apoia.

| arquivo | o que mudou |
|---|---|
| `CompararMensalidadeFechamento` | `groupBy(raizId())`, `grupo.pai` no eager loading, nome da linha = nome da raiz |
| `AdminController::fechamentoAgregarGruposAoVivo()` | idem, mais `name`/`grupo` (id, nome, cor) da linha vindos da raiz |
| `AdminController::fechamentoAgregarGruposCongelados()` | idem — **não estava no plano**, ver desvios |
| `AdminController::relatorioVinculadasDoGrupo()` | o PDF individual lista a árvore inteira — **não estava no plano**, ver desvios |

**`raizId()` foi reusado, não reimplementado**, em todos eles — e `paraGrupo()` continua recebendo o grupo **direto** da âncora (que pode ser um subgrupo), porque ele resolve a árvore por dentro; passar a raiz perderia o 2º degrau quando só o subgrupo tem tabela. Nenhuma query nova dentro de laço: `grupo.pai` entrou no eager loading dos quatro pontos de carregamento.

### A armadilha que quase passou: `grupo:id,name,color`

Os eager loadings do `AdminController` selecionam colunas explícitas. `parent_id` **não estava na lista** — e o Eloquent simplesmente não traz a coluna. `raizId()` é `parent_id ?? id`: sem a coluna, ele devolveria o **subgrupo**, em silêncio, sem erro, sem exceção, sem nada. A tela voltaria a mostrar quatro linhas onde o comando congela uma, e o teste de regressão-zero (sem pai) passaria feliz, porque sem pai os dois resultados coincidem.

`grupo:id,name,color,parent_id` + `grupo.pai:id,name,color,parent_id` nos quatro pontos, com comentário explicando por quê.

## T2 — A prévia que não pode mentir

`SimuladorGrupoCobrancaService::simular(array $grupoIds, ?int $paiId, string $mes)`.

**A árvore hipotética é montada em memória.** Os `CompanyGroup` são **clonados**, recebem o `parent_id` hipotético como atributo e a relação `pai` é injetada com `setRelation()`. As `Company` também são clonadas, com a relação `grupo` trocada pelo clone. A partir daí `FechamentoFaixaResolver` e `CompanyGroup::raiz()` enxergam a hierarquia simulada **sem uma única escrita** — e sem nenhuma cópia da lógica de resolução.

⛔ **A matemática da faixa não foi reimplementada.** `paraGrupo()` e `classificar()` são usados como estão, e a precedência de cobrança (`CobrancaCalculator::mensalidade()` / `novo()`) é a mesma de `ConsolidarMesFechamento` Passo 5. Há teste travando que a chave de agregação e o faturamento da tela batem, campo a campo, com o que o comando de fato congela.

**Cada linha diz de onde vem a tabela:** `tabela_origem`, `procedencia` (`manual`/`contrato`/`presumida_servico`), `tabela_grupo_nome`, `tabela_herdada_de_nome`, `empresa_ancora_id`, e a composição (`subgrupos[]` com nome e contagem de empresas de cada um). Dois testes cobrem exatamente a diferença que o CONTEXT nomeia:

| cenário | mensalidade | procedência |
|---|---|---|
| a maior empresa é a da raiz, tabela lida do contrato | **R$ 21.000** | `contrato` |
| a maior empresa é a do subgrupo, tabela presumida do serviço | **R$ 12.000** | `presumida_servico` |

São os R$ 9.000/mês do caso real. Sem a procedência na tela, é uma decisão no escuro.

**A trava de um nível é reusada, não duplicada:** `simular()` chama `CompanyGroup::validarPaiOuFalhar()` antes de calcular. A prévia de um arranjo que a gravação recusaria seria pior que nenhuma prévia — mostraria um número que nunca vai valer.

### O caso MPozenato, medido no teste

Fixture com os números de produção (10 empresas, R$ 12.679.411,83 em ago/2026) e tabelas de grupo que reproduzem a cobrança de hoje:

| | linhas | total |
|---|---|---|
| **antes** (como está) | 4 | **R$ 33.500** |
| **depois** (pendurados) | 1 | **R$ 21.000** |
| **delta** | | **−R$ 12.500** |

E o inverso também: despendurar uma árvore já montada devolve `+R$ 12.500`.

## T3 — As rotas, e a trilha

Três rotas dentro do grupo `admin.contratos` existente — **nenhuma permissão nova** (D-09 da Fase 131), e o teste prova que quem recebe `admin.contratos` por setor consegue pendurar, enquanto quem não tem leva 403 nas três:

```
GET    /administrativo/contratos/grupos/hierarquia/previa   (JSON, leitura pura)
POST   /administrativo/contratos/grupos/hierarquia          (pendurar)
DELETE /administrativo/contratos/grupos/hierarquia          (despendurar)
```

A recusa vem do `saving()` do model e é convertida em `ValidationException` — a mensagem pt-BR do 143-01 chega à tela sem tradução. **Nenhuma cópia da regra na rota**, e quatro testes cobrem os quatro jeitos de violá-la (si mesmo, pai que já tem pai, grupo que já é pai, ciclo `A→B→A`).

Tudo numa transação: há teste provando que uma recusa no meio de um lote de dois **não deixa metade pendurada** e não escreve trilha nenhuma.

**A trilha** (`activity_log`, `log_name = 'grupo_cobranca_hierarquia'`, molde `GravarTabelaEmpresaService`) grava uma entrada por operação com: quem fez, os grupos antes/depois com `parent_id`, o pai, a competência — **e a prévia do impacto calculada ANTES da escrita**. Calcular depois daria o retrato do mundo já mudado, que não serve para auditar a decisão. O sujeito (`performedOn`) é a raiz onde a cobrança muda.

## Regressão zero sem pai — a prova que permite deployar

É o item 1 do prompt, e está travado em **três caminhos independentes**, todos com o mesmo fixture de quatro grupos e 10 empresas rodado dos dois lados:

1. **A tela ao vivo** — sem pai, quatro linhas, cada subgrupo com a sua soma (3,81 mi / 5,98 mi / 1,24 mi / 1,65 mi), e o que não alcança tabela de grupo continua caindo na régua do serviço.
2. **O comparativo** — sem pai, quatro linhas comparadas, os mesmos valores.
3. **O simulador** — simular uma mudança que não muda nada devolve `antes === depois` e `delta = 0.0`, por identidade de array.

Mais o gate inteiro: as 6 suítes de Fase 137/138/139/141/142 que cobrem fechamento passaram sem uma única falha nova.

## O NPS não sentiu nada

`NpsGrupoCoberturaService`, `NpsGroupSurvey` e a migration de `nps_group_surveys` **não foram abertos** — nem para leitura de edição. Nenhuma empresa é remanejada de grupo: `companies.company_group_id` continua apontando para o subgrupo, que é o que o link de NPS de grupo usa, e há teste explícito disso na rota de pendurar.

**Suíte de NPS rodada por garantia** (`--filter="Nps|NPS"`): **614 testes, 47 errors, 4 failures** — **número por número idêntico ao baseline do 143-01**. Os 47 errors são todos `setores.nome`; as 4 failures são as mesmas quatro já documentadas lá (`Phase119\CompanyScoreServiceStatusTest` ×2, `Phase31NpsSubmitTest`, `Phase69\NpsPhase69IntegrationTest`), alheias a esta fase. **Nenhum teste de NPS mudou de resultado** — não houve o que parar e reportar.

## Desvios do plano

### [Regra 2 — funcionalidade crítica ausente] O ramo CONGELADO também precisava agregar pela raiz

- **Achado em:** T1, lendo `fechamentoAgregarGruposCongelados()` ao editar o irmão dele.
- **Problema:** o plano nomeia `fechamentoAgregarGruposAoVivo()`. Mas o ramo congelado indexa `$snapshotsGrupo` por `fechamento_grupo_snapshots.company_group_id`, que **desde o 143-01 guarda a RAIZ**. Agrupando por `company_group_id` cru, cada subgrupo procuraria um snapshot que não existe: `$s` viria nulo e a tela exibiria linhas de grupo com faturamento, faixa e mensalidade **em branco** — numa competência já fechada, que é exatamente quando as pessoas olham a tela.
- **Correção:** mesma chave do ao vivo, mais `$grupoModel` passando a ser a raiz.
- **Coberto por:** `a_tela_congelada_reencontra_o_snapshot_da_raiz`.
- **Commit:** `d22ae486`.

### [Regra 2 — funcionalidade crítica ausente] O PDF individual listava só o subgrupo

- **Achado em:** T1, procurando outros pontos que leem grupo por `company_group_id` cru.
- **Problema:** `relatorioVinculadasDoGrupo()` monta as "vinculadas" do PDF por empresa com `where('company_group_id', $company->company_group_id)`, e o título é `$company->grupo->name`. Com a árvore montada, o PDF diria "DRossi, 4 empresas" no mesmo mês em que a tela e a cobrança falam de "MPozenato, 10 empresas" — a terceira boca contando uma história diferente.
- **Correção:** a lista passou a ser a árvore inteira (`where id = raiz OR parent_id = raiz`, uma query), e o título, o nome da raiz. Sem pai o resultado é literalmente o de antes.
- **Commit:** `d22ae486`.

Fora esses dois, o plano foi executado como escrito.

## Decisões que tomei sozinho

1. **`parent_id` explícito na lista de colunas do eager loading.** Não era óbvio no plano e é a falha mais silenciosa possível: o teste de regressão-zero passaria mesmo com o bug, porque sem pai raiz e subgrupo coincidem. Comentário no código explicando, para ninguém "limpar" a coluna de volta.
2. **Clonar os models na simulação em vez de mutar os originais.** Mutar seria mais curto, mas deixaria `CompanyGroup` sujos circulando pela requisição — e um `save()` acidental em qualquer outro ponto gravaria a hierarquia que ninguém aprovou. O serviço é a prévia; ele não pode deixar munição para uma escrita não intencional.
3. **A competência padrão da prévia é o mês ANTERIOR**, não o corrente. O mês em curso está somando faturamento e daria uma faixa mais baixa que a real — numa decisão sobre mensalidade, isso é o erro que faz cobrar a menos.
4. **A prévia da trilha é calculada antes da escrita**, e só os totais e as linhas vão para `properties` (sem models), para a trilha continuar legível daqui a um ano.
5. **`simular()` valida com `validarPaiOuFalhar()` antes de calcular**, e a rota `previa` devolve **422 com a mensagem do model** em vez de um resultado. Uma prévia de algo impossível é pior que erro.
6. **A prévia é JSON e as escritas são `back()->with('success')`.** A consulta é feita de dentro da tela antes do submit; as escritas seguem o padrão Inertia do projeto.
7. **Ordem estável das linhas da prévia** (maior cobrança primeiro, empate pelo id) — quem lê procura o número que mais muda, não a ordem de inserção.
8. **A composição (`subgrupos[]`) entrou em cada linha da prévia.** Sem ela, a tela do 143-03 mostraria "10 empresas" sem dizer de onde vieram, e a pessoa aprovaria uma junção sem ver o que está juntando.

## O que decidi NÃO fazer

Tudo registrado em `deferred-items.md`, com o porquê. Em resumo:

1. **Nenhum `.jsx`.** A tela é o 143-03, e o plano é explícito.
2. **Não expus `subgrupos[]` nas props do fechamento.** O dado já existe no simulador; chave sem consumidor é peso morto, e o 143-03 é quem decide a forma.
3. **Prévia multi-competência.** O serviço aceita chamada repetida sem efeito colateral — quem quiser ver 3 meses, itera. Não embuti porque nenhuma tela pede, e a assinatura do plano é de um mês.
4. **Coluna `subgrupo_id` no snapshot.** Continua sendo a única forma de saber a composição de um mês já fechado; nenhuma tela pede hoje.
5. **Aviso de "a faixa mudou por composição, não por desempenho"** em `fechamento:consolidar-mes` — item novo no `deferred-items.md` (nº 7), descoberto aqui e fora do escopo.
6. **Não pendurei ninguém.** Em produção os 15 grupos seguem com `parent_id` nulo depois deste plano. Também não rodei deploy, não toquei em `.env`, nem na flag `fechamento_faturamento_da_api_ativo`, nem em `classificar()`, nem em `NpsGrupoCoberturaService`/`NpsGroupSurvey`/a migration de `nps_group_surveys` (proibição explícita), e **não rodei `state.advance-plan`**.

## Gate

Comando (exit code capturado **antes** de qualquer pipe — `EXIT=2`):

```
--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911"
Tests: 736, Assertions: 3205, Errors: 28, PHPUnit Deprecations: 465.
```

**Separação exigida:**

| | quantidade | origem |
|---|---|---|
| passando | **708** | 679 do baseline + **29 testes novos** desta entrega |
| errors por `UNIQUE constraint failed: setores.nome` | **28** | migration `seed_setor_performance` da OUTRA sessão — **alheios, não consertados** |
| errors por qualquer outro motivo | **0** | — |
| **failures** | **0** | — |
| falhas novas atribuíveis a este plano | **0** | — |

Conferência: `grep -cE "^[0-9]+\) Tests"` = **28** blocos de erro; desses 28 blocos, os que casam `setores.nome` = **28**. Os dois números batem, então **todos** os errors são a colisão alheia. Nenhum teste `Phase143` aparece na lista de erros (`grep` por `Phase143` nos cabeçalhos de bloco = 0). 736 − 28 = 708 = 679 + 29. ✔

**Suíte de NPS (conferência extra, fora do gate):** 614 testes, 47 errors, 4 failures — **idêntico ao baseline do 143-01**, teste por teste. Nenhum resultado de NPS mudou.

## Commits

| hash | assunto |
|---|---|
| `d22ae486` | `feat(143-02): tela e comparativo passam a agregar a cobranca pela raiz do grupo` |
| `ef8cb50e` | `feat(143-02): previa do impacto na cobranca antes de pendurar grupo nenhum` |
| `f66214cf` | `feat(143-02): rotas para pendurar, despendurar e consultar a previa do grupo` |

Todos com `git add` por caminho (árvore compartilhada com outra sessão ativa na v23.0) — nunca `git add -A`/`.`, nunca `git commit -a`, nunca `git stash`. `git status --porcelain app/ tests/ database/` conferido antes de cada commit; o único item alheio na área (`tests/Feature/CompanyPortfolioAccessTest.php`, não rastreado, da outra sessão) ficou intocado.

## Próximo passo

O 143-03 (a tela) já tem tudo de que precisa: as três rotas, a prévia com procedência e composição, e a agregação pela raiz consistente nos três lugares que a pessoa pode olhar (tela ao vivo, tela congelada, PDF). O que falta decidir lá é como apresentar a **queda** de cobrança — o número que a tela vai mostrar é, no caso real, `−R$ 12.500/mês`.

## Self-Check: PASSED

Os 9 arquivos declarados existem em disco e os 3 commits existem no histórico (conferidos um a um).
