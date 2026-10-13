---
phase: 179-visao-geral-cockpit
plan: 05
subsystem: api
tags: [laravel, publicador, visao-geral, controller, inertia, rota, adiamento, mutacao, n-mais-1]

requires:
  - phase: 179-visao-geral-cockpit
    plan: 01
    provides: "JornadaDosProdutosService::jornada() — o anel de 4 estágios"
  - phase: 179-visao-geral-cockpit
    plan: 02
    provides: "AcoesDaContaService::acoes() + SEVERIDADE (a lista FECHADA de 12) e AdiamentoDeAcoes"
  - phase: 179-visao-geral-cockpit
    plan: 03
    provides: "CockpitDaContaService — banner, saude, resumoDePublicacoes, acervoDeImagens, desempenho, recentes, atividade"
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "CabecalhoPublicadorService::para() com contadores e integracoes.coleta (a ausência de coleta EXPLÍCITA para D23)"
  - phase: 178-modal-nova-publicacao
    provides: "O padrão de rota/endpoint do módulo: where('conta'), resolver()+404, role:admin, throttle nomeado, chave canônica"
provides:
  - "MlbPublicadorEntradaController::visaoGeral() com as 23 props (14 de hoje + 9 novas do cockpit)"
  - "MlbPublicadorEntradaController::adiarAcao() — lista FECHADA na borda (422), escopo por usuário, chave canônica"
  - "Rota POST mlb.anuncios.publicador.acoes.adiar"
  - "tests/Feature/Publicador/VisaoGeralCockpitTest.php — 26 casos, 251 asserções, provados por 11 mutações"
affects: [179-06-jornada-e-acoes-no-cliente, 179-07-saude-e-publicacoes-no-cliente, 179-08-recentes-e-atividade, 179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "Prop ADITIVA: a tela nova compõe sobre o contrato antigo, e tirar prop é decisão de OUTRO plano (o do render) — payload não é tela"
    - "Saída reusada sobe para variável antes do Inertia::render: cada uma serve uma prop antiga E uma nova, e chamar duas vezes paga duas vezes"
    - "Lista fechada validada na BORDA com 422, com o serviço falhando em silêncio por trás (defesa em profundidade declarada, não validação duplicada)"
    - "Gate de N+1 com a OUTRA dimensão congelada: variar produtos e publicações juntos mistura dívida alheia com a própria e o gate não diz de quem é a culpa"
    - "Fonte única entre chrome e corpo da página: a coleta da Saúde vem do cabeçalho, não de um stub local que diz o contrário"

key-files:
  created:
    - tests/Feature/Publicador/VisaoGeralCockpitTest.php
    - .planning/phases/179-visao-geral-cockpit/deferred-items.md
  modified:
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "As props de hoje são 14, não 13: o <interfaces> do plano diz 13 e em seguida lista 14. A lista do gate é a do Inertia::render real"
  - "produtosPorFase e quemPublicou FICAM no payload, com os blocos de tela removidos em 12/10 — a decisão do usuário é de TELA e o render é do 179-09"
  - "A coleta da `saude` vem de cabecalho.integracoes.coleta, não do $defasagem cru: o stub D23 da action diz nunca_coletado=false e o header diz true (Rule 2)"
  - "atividade.disponivel é TRUE em conta sem Company que tenha produto recebido — a 4ª fonte não depende de Company; o <action> previu false"
  - "O gate de N+1 por produto congela as publicações em 2: o crescimento por publicação é dívida pré-existente e misturar as duas dimensões cegaria o gate"
  - "`chave` com espaço à direita é ACEITA (TrimStrings do framework a apara antes da action) — normalização de borda, não furo da lista fechada"
  - "A mutação 'mover o abort_unless para depois do adiar()' é EQUIVALENTE por construção, porque o serviço também recusa em silêncio. Declarada, não contada"

metrics:
  duration: "~5h"
  completed: 2026-10-13
  tasks: 3
  tests_added: 26
  assertions_added: 251
  mutations: 11
  mutations_caught: 10
  mutations_equivalent: 1
  queries_na_pagina: 65
  migrations: 0
---

# Fase 179 Plano 05: o controller da Visão geral e a rota de adiar Summary

A ponte entre a wave 1 e a tela: a action `visaoGeral()` passou a entregar **23 props** — as **14**
que já entregava, intactas e com o mesmo valor, **mais as 9 do cockpit** — e o adiamento de uma ação
virou uma rota que **sobrevive à recarga**, com a lista fechada de chaves conferida **antes** de
qualquer escrita. **Sem migration, sem pacote, sem tocar em tela.**

---

## ⚠️ A TABELA DAS 23 PROPS — é este o contrato que os planos 06/07/08/09 leem

### As 14 de hoje: **todas ficam**, mesmo nome, mesmo valor

| prop | mudou algo? |
|---|---|
| `empresa` | não |
| `liberada` | não |
| `indicadores` | só a origem no código (variável `$indicadores` em vez da chamada inline) — **mesmo valor** |
| `oQueFazerAgora` | idem (`$linhas`) |
| `alertas` | não |
| `situacaoProdutos` | não — **cobrada por `assertSame` contra o serviço** |
| `produtosPorFase` | não — **cobrada por `assertSame`**. ⚠️ **FICA de propósito** (ver abaixo) |
| `ultimasPublicacoes` | só a origem (`$ultimasPublicacoes`) — mesmo valor |
| `atividadeEquipe` | idem (`$atividadeEquipe`) |
| `integracoes` | idem (`$integracoes`) |
| `identidadeResumo` | não |
| `quemPublicou` | não — **cobrada por `assertSame`**. ⚠️ **FICA de propósito** |
| `abas` | não |
| `cabecalho` | só a origem (`$cabecalho`) — mesmo valor |

⚠️ **São 14, não 13.** O `<interfaces>` do `179-05-PLAN.md:75-77` diz *"passa **13 props**"* e na mesma
frase **lista 14**. A contagem do gate é a do `Inertia::render` real
(`MlbPublicadorEntradaController.php:553-601`). Logo o total é **23**, não 22.

### As 9 novas, com o shape de topo

| prop | shape de topo | fonte |
|---|---|---|
| `jornada` | `{total, publicados, pct, tem_produtos, proximo_passo, estagios[4]}` | `JornadaDosProdutosService::jornada($alvo, $produtos)` |
| `acoes` | `{acoes[], contagens{todas,critico,atencao,oportunidade}, adiadas[], volta_em_iso}` | `AcoesDaContaService::acoes($alvo, $linhas, $jornada, $produtos, $userId)` |
| `banner` | `null` **ou** `{chave, link_reconexao}` | `CockpitDaContaService::banner($empresa)` |
| `saude` | `{geral, linhas[3]}` | `saude($empresa, $integracoes, $cabecalho['integracoes']['coleta'], $companyId)` |
| `publicacoesMl` | `{disponivel, total, faixas[4], outras, ativas_pct, pedem_acao?}` | `resumoDePublicacoes($company, $cabecalho['contadores'])` |
| `acervoImagens` | `{imagens, packs}` | `acervoDeImagens($alvo, $indicadores)` |
| `desempenho` | `{kpis[3], dias, url}` | `desempenho($alvo, $indicadores)` |
| `recentes` | `{disponivel, itens[≤5]}` | `recentes($ultimasPublicacoes, $produtos)` |
| `atividade` | `{disponivel, grupos[]}` | `atividade($alvo, $atividadeEquipe)` |

⚠️ **`pedem_acao` é a ÚNICA chave opcional do contrato** — ela existe quando, e só quando, o número
foi medido (chave **ausente**, não nula). O gate trava isso em `OPCIONAIS_DAS_NOVAS` e cobra os dois
lados: com acervo coletado a chave **existe**; sem Company ela **não vem**.

⚠️ **Nenhuma transformação no caminho.** O controller não reformata nada: cada prop é o retorno do
serviço, byte a byte. Quem desenha lê o contrato da wave 1 (`179-01/02/03-SUMMARY.md`), não este.

---

## ⚠️ Por que `produtosPorFase` e `quemPublicou` continuam no payload (e o custo REAL de mantê-las)

A decisão arquitetural veio do orquestrador e está honrada: **a decisão do usuário de 12/10/2026 é de
TELA**, e quem tira o render é o plano **179-09**. Nenhuma prop foi apagada e nenhum método de serviço
ficou órfão.

⚠️ **Mas um dos quatro motivos do plano NÃO se sustentou na medição, e o 179-09 precisa saber:**

> *Motivo 2 do plano:* *"`quemPublicou` reusa o `$publicados` que a action já computa para os
> `indicadores`"* — **falso**. `$publicados = $painel->publicadosRecentes($alvo)`
> (`MlbPublicadorEntradaController.php:525`) é usado **somente** por `quemPublicou`;
> `indicadores()` recebe `$alvo`, `$contagemProdutos` e `$produtos`, e não o toca.

E `publicadosRecentes()` **não** é de graça: ele varre **todas** as publicações da conta e paga um
`User::find()` **por linha** (ver a dívida 1 do `deferred-items.md`). Medido: a página custa **65**
consultas com 2 publicações e **91** com 12.

➡️ **`produtosPorFase` segue custando 0** (passthrough sobre `$contagemProdutos`). **`quemPublicou`
custa 1 consulta + 1 `User::find()` por publicação da conta.** Quando o 179-09 tirar o render, tirar
a prop junto passa a ter benefício **medido** — e aí a cirurgia nos testes é decisão informada, não
limpeza. **Este plano não a tirou, e está certo em não ter tirado.**

---

## ⚠️ O inventário REG-01 — bloco de hoje → onde ele mora na tela nova

Travado em constante no gate (`INVENTARIO_REG01`) e conferido a cada rodada: a prop da esquerda tem
de continuar chegando **e** a da direita tem de ter chegado.

| Bloco de hoje (prop) | Onde ele mora depois desta fase |
|---|---|
| `indicadores` | `desempenho` (3 KPIs reais) **+** `acervoImagens` |
| `oQueFazerAgora` | `acoes` (a MESMA linha, com severidade, sub-itens e adiamento) |
| `ultimasPublicacoes` | `recentes` (os 5 itens enriquecidos com capa) |
| `atividadeEquipe` | `atividade` (as 3 fontes + a 4ª, agrupadas por dia) |
| `integracoes` | `saude` (ML · ERP declarado · sincronização) **+** `banner` |
| `situacaoProdutos` | `jornada` (o anel de 4 estágios) |
| `alertas` | **continua sendo ele mesmo** (a coluna lateral) |
| `identidadeResumo` | **continua sendo ele mesmo** |
| `produtosPorFase` | **sem destino na tela nova** — a prop fica, o render sai no 179-09 |
| `quemPublicou` | **sem destino na tela nova** — idem |

⚠️ **Nenhum bloco perdeu a fonte.** É o que permite ao 179-09 compor sem regredir — e é a prova de
"melhorar sem regredir" no nível do servidor.

---

## ⚠️ Para o 179-09 — o que você NÃO precisa fazer

**`PainelVisaoGeral.jsx` continua no disco e os 2 gates de JS que o renderizam continuam
funcionando sem uma linha de mudança**, porque as 14 props antigas continuam chegando com o mesmo
nome e o mesmo valor. A composição nova **não precisa apagar nada**: ela acrescenta.

E as duas provas de que manter as props custou **ZERO cirurgia de gate**:

- `git diff --stat 898d68c2..HEAD -- tests/Feature/Publicador/ListaPorFaseTest.php tests/Feature/Publicador/VisaoGeralTest.php` → **VAZIO**. As 14 asserções que asseguram `produtosPorFase`/`quemPublicou` seguem intactas, inclusive o `test_visao_geral_entrega_produtos_por_fase_sem_perder_nenhuma_prop`.
- `git diff 898d68c2..HEAD -- tests/ | grep '^-' | grep assert` → **0** removidas (isca: **112** acrescentadas no mesmo range).

---

## A rota de adiar — o padrão do módulo, seguido, não inventado

`routes/mlb_anuncios.php:304-306`, dentro do grupo `['auth','verified','role:admin']`:

```php
Route::post('publicador/empresas/{conta}/acoes/adiar', [MlbPublicadorEntradaController::class, 'adiarAcao'])
    ->where('conta', '(empresa|company)-[0-9]+')
    ->middleware('throttle:60,1,publicador.acoes.adiar')->name('publicador.acoes.adiar');
```

| Camada | O que faz | Prova |
|---|---|---|
| `where('conta', …)` | `{conta}` fora do padrão morre **na rota** | `shouldNotReceive('resolver')` — o status 404 sozinho seria **vazio por construção** (sem o `where`, o `resolver()` devolveria null e o 404 viria igual). M10 reprova |
| `resolver()` + `abort_if(404)` | conta inexistente/arquivada | teste do `empresa-999999` |
| `role:admin` | não-admin → 403 | teste com `role=consultor`, e o cache **continua vazio** |
| `validate(['chave' => required\|string\|max:60])` | corpo malformado | 302 + erro de sessão |
| ⚠️ `abort_unless(array_key_exists(…SEVERIDADE), 422)` | **a lista FECHADA, antes de QUALQUER escrita** | 422 **+** `adiadas()` devolve `[]`. M06 e M07b reprovam |
| `adiar($userId, $alvo['chave'], …)` | escopo por usuário + **chave canônica** | adia por `company-N`, confere por `empresa-N`. M05 e M08 reprovam |
| `back()` | o servidor continua a fonte da verdade | a ação some na visita seguinte, não no cliente |

⚠️ **`array_key_exists` sobre a CONSTANTE do serviço, nunca um literal reescrito na action.** M07b
prova: trocar por `in_array($chave, ['reconectar','com_problema','pausados'], true)` faz 9 das 12
chaves do mapa receberem 422 e o gate reprova. **A borda não pode ser mais estreita que o contrato.**

**`route:list --name=publicador`: 94 nomes, 103 linhas, e `0` linhas removidas de `routes/` no range
da fase** (REG-05).

---

## As divergências DECLARADAS, cada uma com a medição

| # | O plano / o `<action>` diz | O que foi entregue | Medição |
|---|---|---|---|
| D-1 | "passa **13 props**" | **14** props antigas + 9 = **23** | `<interfaces>` do `179-05-PLAN.md:75-77` diz 13 e lista 14. A fonte é o `Inertia::render` |
| D-2 | `saude(..., $defasagem, ...)` com o `$defasagem` da action | `saude(..., $cabecalho['integracoes']['coleta'], ...)` | **[Rule 2]** Sem Company o stub local da action (`:523`) diz `nunca_coletado: false` e `CabecalhoPublicadorService::para()` (`:96`) diz `true`. Ler o cru faria o **chrome** dizer "nunca coletado" e a **Saúde** dizer "tudo conectado" sobre a MESMA conta, na MESMA tela. Não dá para corrigir o stub: `indicadores()` o lê (`PainelVisaoGeralService.php:286`) e isso é comportamento aprovado. **Fonte única: o header.** M09 reprova |
| D-3 | D23 ⇒ `atividade.disponivel = false` | **`true`** quando a conta tem produto recebido | A **4ª fonte** (`produtosRecebidos()`, `CockpitDaContaService.php:558`) é escopada por `mlb_empresa_id` **OU** `company_id` e **não precisa de Company**. Afirmar "indisponível" esconderia evento que existe (VG-18). O caso vazio tem teste próprio e aí é `false` |
| D-4 | "`in_array` com 422" | `array_key_exists` sobre a constante | A `SEVERIDADE` é um mapa `chave => severidade`; `in_array` leria os **valores**. Mesma lista, acesso certo |
| D-5 | `$integracoes` não estava na lista de variáveis a subir | subiu também | `saude()` precisa dela, e `integracoes()` lê `$alvo['mlb_empresa']?->implementacao` (relação preguiçosa). Sem subir, duas chamadas |
| D-6 | teto de **80** consultas na página | 80 com publicações FIXAS; **120** no gate que varia publicações | Medido: **65** com 3 produtos, **65** com 12 (acréscimo **zero**), **91** com 12 publicações. O crescimento é por **publicação**, por dívida pré-existente — ver abaixo |
| D-7 | mutação "mover o `abort_unless` para depois do `adiar()`" | **equivalente por construção** | `AdiamentoDeAcoes::adiar()` **também** recusa chave fora do mapa, em silêncio (defesa em profundidade da wave 1). Para chave inválida, nenhuma das duas ordens escreve, e o 422 vem nas duas. Substituída por **M07b**, que reprova |
| D-8 | — | `chave` com espaço à direita é **aceita** | O middleware global `TrimStrings` apara a entrada **antes** da action: `'sem_publicacao '` é literalmente a MESMA chave. Normalização de borda do framework, não furo. Documentado no gate porque a intuição diz o contrário |

---

## O custo medido — e a dívida que NÃO é desta fase

| O que | Medido (SQLite, 2ª visita) |
|---|---|
| Página inteira, 3 produtos · 2 publicações | **65** consultas |
| Página inteira, **12** produtos · 2 publicações | **65** — `assertLessThanOrEqual` com folga 4; **acréscimo real ZERO** |
| Página inteira, 12 produtos · **12** publicações | **91** |
| `produtosParaTela()` | **1x**, travado por `->once()` |
| `CabecalhoPublicadorService::para()` | **1x**, travado por `->once()` |
| Chamadas ao Mercado Livre | **0** (`Http::fake()` sem handler) |
| Migrations · pacotes | **0** · **0** |

⚠️ **A página NÃO cresce com produto — cresce com PUBLICAÇÃO, e isso é anterior a este plano.**
`PainelVisaoGeralService::resolverAtor()` (`:136`) faz um `User::find()` **por linha**, e é chamado
por `publicadosRecentes()` e `ultimasPublicacoes()`, as duas da Fase 173 e as duas chamadas **uma vez
cada** antes e depois deste plano. `recentes()` e `atividade()` recebem os arrays **já calculados** e
não fazem nenhuma consulta de usuário. Registrado em
`.planning/phases/179-visao-geral-cockpit/deferred-items.md` com a receita de correção (o próprio
arquivo já tem o padrão certo em `atividadeDaEquipe()`, `:976`, um `whereIn`).

⚠️ **O gate de N+1 por produto congela as publicações em 2 DE PROPÓSITO.** Variar as duas dimensões
juntas misturaria a dívida alheia com a minha e o gate não diria de quem é a culpa — era exatamente
o que estava acontecendo na primeira versão, que reprovava com 91 sem apontar nada.

---

## A prova por mutação — 11 nominais, 10 reprovações, 1 equivalente

Processo: **contagem de ocorrências travada antes** (recusa se ≠ 1), recusa de alvo que só casa em
comentário, aplicação, **impressão da linha alterada (antes/depois)**, execução do filtro,
restauração de cópia pristina e **conferência por `sha256`** —
`07662fcb…ea42d` (controller) e `bc14469d…7dc1` (rotas), batendo no fim das duas rodadas, com o
harness abortando se divergisse.

| # | Mutação | Linha | Resultado / reprovou em |
|---|---|---|---|
| M01 | tirar a prop antiga `produtosPorFase` do `Inertia::render` | 565 | **REPROVOU** — `as_quatorze_props…` + `inventario_reg01…` |
| M02 | chamar `produtosParaTela()` uma 2ª vez em vez de reusar a lista | 549 | **REPROVOU** — `produtos_para_tela_e_o_cabecalho…` |
| M03 | remontar o `$cabecalho` dentro do `Inertia::render` | 580 | **REPROVOU** — idem |
| M04 | passar `null` como `$userId` ao `acoes()` | 550 | **REPROVOU** — `adiar_esconde_a_acao…` |
| M05 | escopar o adiamento por um usuário **fixo** | 644 | ⚠️ 1ª rodada **SOBREVIVEU** (gate furado, ver abaixo) · 2ª rodada **REPROVOU** |
| M06 | remover o `abort_unless` da lista fechada (T-179-16) | 638 | **REPROVOU** — `chave_fora…` + `as_doze_chaves…` |
| M07 | mover o `abort_unless` para **depois** do `adiar()` | 638 | ⚠️ **SOBREVIVEU — equivalente por construção** (D-7) |
| M07b | trocar a constante por uma lista literal local **incompleta** | 639 | **REPROVOU** — `as_doze_chaves…` |
| M08 | gravar o `{conta}` do request em vez da chave canônica | 647 | **REPROVOU** — `o_adiamento_e_gravado_com_a_chave_canonica…` |
| M09 | ler o `$defasagem` cru em vez da coleta do cabeçalho | 593 | **REPROVOU** — `conta_sem_company_responde…` |
| M10 | tirar o `->where('conta', …)` da rota de adiar | 304 | **REPROVOU** — `conta_fora_do_padrao…` + `a_rota_de_adiar_existe…` |

---

## Deviations from Plan

### [Rule 1 - Bug de gate] O gate do adiamento por usuário passava por COINCIDÊNCIA DE ID

- **Encontrado durante:** a 1ª rodada de mutação — **M05 SOBREVIVEU**.
- **Causa:** em `test_o_adiamento_e_de_quem_adiou_e_nao_vaza_para_outro_admin`, quem adiava era o
  **primeiro** usuário criado, cujo `id` é exatamente **1**. A mutação `$userId = 1` coincidia com o
  id do adiador e o teste não notava nada. ⚠️ **Não era equivalência, era coincidência** — a lição 4
  do briefing ("mutação que sobrevive não é equivalente até você provar que é") pegou um furo real.
- **Correção:** quem adia passou a ser o **segundo** admin, com `assertNotSame` dos dois ids, e o
  saco de cache dos **dois** usuários é conferido direto (`adiadas()` do adiador tem a chave, o do
  espectador está vazio).
- **Verificado:** com a correção, M05 **reprova**.
- **Commit:** `4df1883f`

### [Rule 2 - Correção] A Saúde e o header discordavam sobre a coleta da MESMA conta

Descrito em **D-2** acima. Em resumo: o `$defasagem` que a action monta para D23 (`:523`) diz
`nunca_coletado: false`, e o `CabecalhoPublicadorService::para()` (`:96`) diz `true` — ele carimba a
ausência explicitamente justamente para a tela aplicar *"disponível só para empresas cadastradas"*.
Ligar o cru faria o chrome dizer uma coisa e o corpo da página dizer o contrário, na mesma tela,
sobre a mesma conta. A coleta passou a vir do **cabeçalho**, que já é fonte única do número de
publicações da mesma prop. Corrigir o stub da action **não** era opção: `indicadores()` o lê
(`PainelVisaoGeralService.php:286`) e isso é comportamento aprovado em produção, em arquivo de outro
plano. M09 reprova quem reverter.

### [Desvio de plano] A rota ficou ao lado de `visao-geral`, não de `sincronizar`

O `<action>` pediu *"ao lado de `publicador.sincronizar`"*. Ela está no **mesmo grupo** e com o
**mesmo padrão** (o que o plano de fato cobra), mas posicionada junto da `publicador.visao-geral` /
`publicador.configuracoes` — é a única tela que a dispara, e a ordem das rotas no Laravel só importa
quando há colisão de padrão, que aqui **não existe** (o comentário de não-colisão enumera os três
candidatos). Nenhuma rota existente mudou de nome, verbo, URI ou ordem.

### [Desvio de plano] `partialMock()` não serve para `CabecalhoPublicadorService`

O `->once()` do cabeçalho exigiu **delegação explícita** em vez de `passthru()`: a classe tem dois
serviços **promovidos** no construtor e o mock parcial do Mockery não roda construtor nenhum — o
`passthru()` estoura em *"Typed property … must not be accessed before initialization"*, e o mock
**proxiado** (`Mockery::mock($instancia)`) estoura igual, porque o `passthru` chama o método na
subclasse do mock, não no objeto proxiado. A forma que funciona é
`shouldReceive('para')->once()->andReturnUsing(fn (...$a) => $real->para(...$a))`. ⚠️ **Receita para
as waves 3 e para as Fases 180/181:** `partialMock` só vale para serviço sem dependência promovida
(`ProgramasPublicadorService` aceita e foi usado assim).

### [Desvio de processo] Tasks 1 e 2 num commit só

As duas são donas do **mesmo arquivo** (`MlbPublicadorEntradaController.php`) e o `<verify>` de cada
uma é `--filter=VisaoGeralCockpitTest`, que inclui os casos da outra e não pode ficar verde sem ela.
Mesma situação das waves 1 e 2. Ordem de gate do TDD respeitada:

| Commit | O que | Gate |
|---|---|---|
| `c914a92d` | `tests/Feature/Publicador/VisaoGeralCockpitTest.php` | **RED** — 20 falhas, 5 passando (as que só cobram as props de hoje) |
| `e5ccb690` | as 9 props na action + a rota e a `adiarAcao()` | **GREEN** — 26/26 |
| `82a51f57` | `deferred-items.md` (o N+1 pré-existente) | documentação |
| `4df1883f` | o fix do gate furado da M05 | Rule 1 |
| `a este commit` | SUMMARY + bloco aditivo no STATE | fechamento |

⚠️ **O commit `c914a92d` saiu SEM a linha `Co-Authored-By`** — esquecimento meu na primeira
mensagem. `git commit --amend` é operação proibida nesta árvore (compartilhada com outro dev ativo),
então o commit fica como está e os três seguintes têm a linha. Registrado para não parecer omissão
deliberada numa auditoria.

### [Fora de escopo, registrado] Duas dívidas pré-existentes descobertas

As duas estão em `.planning/phases/179-visao-geral-cockpit/deferred-items.md`, com medição e receita:

1. **N+1 por publicação** em `PainelVisaoGeralService::resolverAtor()` (`:136`) — 65 → 91 consultas
   quando as publicações vão de 2 para 12. Pré-existente à fase; travada por teto de 120 com a prova
   de que o crescimento é por publicação e não por produto.
2. ⚠️ **`CockpitDaContaTest:1277` reprova se a suíte rodar antes das 09:00 locais.** O cenário monta
   três eventos "de hoje" com `subHours(2|5|9)`; antes das 09:00 o de 9 horas cai no dia anterior e
   as contagens viram `[2,3,1]` em vez de `[3,2,1]`. **Medido duas vezes no mesmo dia:** falhou às
   ~08:06 na suíte completa e **passou** às 09:01 isolado. Teste do plano **179-03**, arquivo que
   este plano não tocou (diff vazio), e `CockpitDaContaTest` chama o serviço **direto**, sem
   controller. **Correção é uma linha de `Carbon::setTestNow()`** — quem rodar a suíte de madrugada
   deve olhar a hora antes de procurar bug no próprio trabalho.

---

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `artisan test --filter=VisaoGeralCockpitTest` | **26 passed, 251 assertions** |
| 2 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **2007 testes / 14953 asserções / 0 falhas** (baseline medida ANTES de editar: **1981 / 14702 / 0**, em 5m30s) |
| 2b | a mesma suíte às ~08:06 | **1 falha**, o flake de horário do `CockpitDaContaTest` — remedida às 09:0x: **0 falhas** |
| 3 | `git diff 898d68c2..HEAD -- MlbPublicadorEntradaController.php \| grep '^-'` | 6 linhas, **todas** com o `+` correspondente **da mesma chave** (enumeradas abaixo) — nenhuma das 14 chaves removida |
| 4 | `git diff 898d68c2..HEAD -- routes/ \| grep '^-'` | **0 linhas removidas** (REG-05) |
| 5 | `artisan route:list --name=publicador` | **94 nomes / 103 linhas**, mais `mlb.anuncios.publicador.acoes.adiar` · `--name=acoes.adiar` devolve **1** |
| 6 | `CabecalhoDoPublicador`, `VisaoGeralTest`, `VisaoGeralSemComposto`, `ListaPorFase` | ✅ verdes (dentro do gate 2) |
| 6b | `git diff --stat 898d68c2..HEAD -- ListaPorFaseTest.php VisaoGeralTest.php` | ✅ **VAZIO nos dois** — manter as props custou **ZERO** cirurgia de gate |
| 7 | diff vazio em `database` / `database/migrations` | ✅ **nenhuma migration** |
| 8 | diff vazio em `resources/js` | ✅ nenhuma tela tocada — provado por diff, não por afirmação |
| 9 | diff vazio em `app/Support/Publicador`, `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `AppLayout.jsx`, `Editor.jsx`, `Components/Publicador/Mesa` | ✅ território do outro dev intacto |
| 10 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | ✅ **vazio** (nenhuma deleção) |
| 11 | os 22 caminhos de gate conferidos por `test -e` **antes** de acreditar no vazio | ✅ nenhum ausente |
| 11b | **ISCA** do range deste plano (`af614ba3..HEAD -- app routes tests`) | ✅ 3 arquivos / 1146 inserções — o vazio dos outros **não é por construção** |
| 12 | diff vazio nos 4 manifests (`composer.*`, `package*.json`) | ✅ **nenhum pacote** (T-179-SC) |
| 13 | diff vazio nos 4 serviços da wave 1 + `PainelVisaoGeralService` + `ProgramasPublicadorService` + `tailwind.config.js` + `NovaPublicacaoDoHeaderTest.php`, no range **deste plano** | ✅ |
| 14 | asserções removidas em `tests/` | **ZERO** (isca: **112** acrescentadas no mesmo range) |
| 15 | `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v26.md` | ✅ diff vazio — **não tocados** |
| 16 | `STATE.md` aditivo | ✅ `git diff --numstat` = **N / 0** |

⚠️ **O gate 3 é o que o briefing avisou que é vazio ao contrário.** Reaponte de valor gera `-assert`
e `-'chave'` **por construção**. As 6 remoções, cada uma com o seu `+`:

| removida | acrescentada |
|---|---|
| `'indicadores' => $painel->indicadores($alvo, $contagemProdutos, $produtos),` | `'indicadores' => $indicadores,` |
| `'oQueFazerAgora' => $painel->oQueFazerAgora(…),` | `'oQueFazerAgora' => $linhas,` |
| `'ultimasPublicacoes' => $painel->ultimasPublicacoes($alvo),` | `'ultimasPublicacoes' => $ultimasPublicacoes,` |
| `'atividadeEquipe' => $painel->atividadeDaEquipe($alvo),` | `'atividadeEquipe' => $atividadeEquipe,` |
| `'integracoes' => $painel->integracoes($alvo, $empresa),` | `'integracoes' => $integracoes,` |
| `'cabecalho' => $cabecalhos->para(…),` | `'cabecalho' => $cabecalho,` |

**A chave nunca desaparece — só o lado direito virou a variável içada.** As outras 8 chaves não
aparecem em nenhum lado do diff: **0 removidas**.

**Deprecations: 53 → 54.** A única é o `@group phase179` do doc-comment da classe nova, convenção que
**75 arquivos** deste projeto já usam. **JS não foi medido de novo porque `resources/js` tem diff
vazio no range** — nada de JS pode ter regredido, e a falha pré-existente
`estrutura-grade-glide:123` segue intocada.

---

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-16 Tampering (chave de cache arbitrária) | **mitigado** | `array_key_exists` contra `AcoesDaContaService::SEVERIDADE` **antes** de qualquer escrita; 422 **e** cache vazio conferidos; M06 e M07b reprovam. ⚠️ A ordem em si é inobservável (D-7) porque o serviço também recusa — a mitigação é **em duas camadas**, de propósito |
| T-179-17 Elevation of Privilege (IDOR por conta) | **mitigado** | `->where('conta','(empresa\|company)-[0-9]+')` na **rota** (provado por `shouldNotReceive('resolver')`, não pelo status) + `resolver()`+404 + grupo `auth/verified/role:admin`; gravação com a chave **canônica** (M08) |
| T-179-18 Information Disclosure | **mitigado** | varredura por `APP_USR`, `TG-`, `access_token`, `refresh_token`, `client_secret` no **array de props** e no **HTML renderizado**, com isca de tamanho (>2000 bytes) |
| T-179-19 Denial of Service (N+1) | **mitigado no que é deste plano** | `produtosParaTela()` e `para()` `->once()`; 5 saídas içadas; **0 consultas a mais** de 3 para 12 produtos. ⚠️ O crescimento **por publicação** é dívida anterior, medida e registrada em `deferred-items.md` |
| T-179-20 Repudiation (prop removida em silêncio) | **mitigado** | as 14 chaves em constante + `assertSame` de **valor** em três delas + o inventário REG-01; M01 reprova |
| T-179-21 Denial of Service (flood de adiamento) | **mitigado** | `throttle:60,1,publicador.acoes.adiar` nomeado, conferido por `gatherMiddleware()`; o saco aceita ≤ 12 chaves |
| T-179-SC Tampering (pacote) | **mitigado** | **nenhum pacote**; diff vazio nos 4 manifests |
| **extra** (tela preta de 07/10) | **mitigado** | todo campo de `recentes.itens` conferido como escalar, com `quem` (`{tipo,nome}`) como **única** exceção declarada, e `array_keys` travado |

---

## Known Stubs

**Nenhum.** Nenhuma das 9 props novas é constante, placeholder ou valor de exemplo — todas são o
retorno dos serviços da wave 1, sem transformação no caminho. `banner` nulo com token ativo e
`desempenho.url` nulo são **decisões declaradas** da wave 1 (a tela Desempenho é a Fase 182), não
falta de implementação.

---

## Perguntas em aberto para o usuário

1. ⚠️ **A decisão de "Prontos para a Fase 2" continua pendente** (aberta pelo plano 179-02, não
   resolvida aqui): o anel e a ação mostram números defensáveis e **diferentes** para o mesmo
   conceito, e a sub-lista de uma ação mostra 2 itens sob um cabeçalho que diz 1. Nada neste plano
   mudou isso — ele só liga as duas fontes na mesma tela, o que torna a divergência **visível**.
2. **`quemPublicou` custa 1 consulta + 1 `User::find()` por publicação da conta**, e o bloco dela
   saiu da tela. A prop fica (decisão de arquitetura, honrada), mas o 179-09 agora tem o número para
   decidir se vale tirá-la junto com o render.
3. **O N+1 por publicação** (dívida 1 do `deferred-items.md`) tem correção de baixo risco e sem
   mudança de contrato. Vale um plano próprio nesta fase ou fica para depois?
4. **O flake de horário do `CockpitDaContaTest`** (dívida 2) é uma linha. Corrigir agora evita que
   toda rodada noturna da suíte reporte uma falha que não é regressão.
5. ⚠️ **Nenhuma tela foi vista renderizada** — não há navegador neste ambiente. Toda a conferência é
   de teste automatizado. **Nenhum número de produção foi medido:** a leitura de produção está
   bloqueada nesta sessão (plink/tinker/MCP). **Nada deployado, nada empurrado, nada no VPS.**

---

## Self-Check: PASSED

- `app/Http/Controllers/MlbPublicadorEntradaController.php` — FOUND (modificado, aditivo)
- `routes/mlb_anuncios.php` — FOUND (modificado, +19 / −0)
- `tests/Feature/Publicador/VisaoGeralCockpitTest.php` — FOUND
- `.planning/phases/179-visao-geral-cockpit/deferred-items.md` — FOUND
- commits `c914a92d`, `e5ccb690`, `82a51f57`, `4df1883f` — FOUND em `git log`
- `artisan route:list --name=acoes.adiar` — **1 rota**
- controller e rotas **idênticos ao commit** depois das 11 mutações (`sha256`
  `07662fcb…ea42d` e `bc14469d…7dc1`, com o harness abortando se divergisse)
