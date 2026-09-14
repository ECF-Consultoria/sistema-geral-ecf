---
phase: 143-grupo-de-cobranca-acima-dos-subgrupos
plan: "04"
subsystem: fullstack
tags: [fechamento, cobranca, company-groups, previa, ui, auditoria]

requires:
  - phase: 143-01
    provides: "company_groups.parent_id, raizId(), trava de um nível com mensagem pt-BR pronta"
  - phase: 143-02
    provides: "SimuladorGrupoCobrancaService::simular(), rotas previa/pendurar/despendurar, trilha em activity_log"
  - phase: 143-03
    provides: "página da tabela do grupo (admin.contratos.tabela.grupo.show) — o destino do aviso 'sem tabela'"
provides:
  - "GET admin.contratos.grupos.index + Pages/Admin/GruposCobranca.jsx — a tela onde o grupo de cobrança é montado"
  - "POST admin.contratos.grupos.criar — cria um grupo de cobrança vazio dentro da permissão do módulo"
  - "SimuladorGrupoCobrancaService::estadoAtual() — as linhas de cobrança de hoje para todos os grupos"
  - "Prévia obrigatória na frente de toda gravação de hierarquia, com procedência da tabela e o aviso de grupo sem tabela"
affects: []

tech-stack:
  added: []
  patterns:
    - "A listagem lê os mesmos números da prévia e do fechamento (estadoAtual reusa calcularLado) — nenhuma segunda conta na tela"
    - "Prévia apagada por useEffect quando a seleção ou o destino mudam: impossível confirmar um arranjo lendo o número de outro"
    - "Botão de gravar existe apenas DENTRO do bloco `{previa && (` — a trava é estrutural, não um disabled"
    - "Rota de criação própria dentro de admin.contratos (a company-groups.store é role:admin e daria 403 por setor)"

key-files:
  created:
    - resources/js/Pages/Admin/GruposCobranca.jsx
    - tests/Feature/Phase143/Phase143GruposCobrancaPaginaTest.php
    - tests/Feature/Phase143/Phase143GruposCobrancaUiTest.php
  modified:
    - app/Services/Fechamento/SimuladorGrupoCobrancaService.php
    - app/Http/Controllers/GrupoCobrancaHierarquiaController.php
    - resources/js/Pages/Admin/Contratos.jsx
    - routes/web.php

key-decisions:
  - "Criar o grupo de cobrança e juntar os grupos viraram DOIS passos, não um. Não é preguiça de implementar o passo único: a tela tem de oferecer o cadastro da tabela ANTES de juntar (senão a cobrança herda uma tabela copiada do serviço e cai mais do que deveria), e não existe cadastrar tabela de um grupo que ainda não existe. A ordem criar → cadastrar a tabela → juntar é a única coerente. Criar um grupo vazio não muda cobrança nenhuma, e há teste provando isso número por número."
  - "Rota de criação PRÓPRIA (admin.contratos.grupos.criar) em vez da company-groups.store já existente: aquela vive sob role:admin, e quem recebeu admin.contratos por setor levaria 403 no meio do fluxo — a mesma armadilha que a Fase 142 pagou para fechar."
  - "estadoAtual() entrou no simulador em vez de uma consulta própria no controller: a listagem precisa mostrar escala (quantas empresas, quanto de cobrança) e esse número tem de ser o mesmo que a prévia mostra depois e que o fechamento congela. Há teste comparando a prop da tela com o retorno do simulador, grupo a grupo."
  - "Tirar um grupo de dentro de outro também exige prévia. O plano só fala da prévia antes de juntar, mas desfazer SOBE a cobrança do cliente — é a mesma decisão de dinheiro ao contrário."
  - "Queda de cobrança é pintada com o amarelo da marca e explicada como correção ('Cair é o resultado esperado'), nunca em vermelho. Há teste proibindo `text-red` e `variant=\"destructive\"` no arquivo inteiro."
  - "A tela não filtra quem pode ser juntado por regra própria: ela só oferece caixa de seleção para quem é cobrado sozinho. O que é permitido continua sendo decidido pelo saving() do model, e a mensagem dele é exibida como está."

metrics:
  duration: "~3h"
  completed: 2026-09-14
  tarefas: 3
  testes_novos: 25
---

# Fase 143 Plano 04: a tela que monta o grupo de cobrança — Summary

A última peça de código da fase. O backend estava pronto desde o 143-02; o que faltava era o lugar
onde uma pessoa **vê** que quatro cobranças de R$ 33.500 viram uma de R$ 21.000 e decide se é isso
mesmo. `GET /administrativo/contratos/grupos` agora lista os grupos com a escala de cada um, deixa
criar um grupo de cobrança, juntar, desfazer — e **nada é gravado sem a prévia na frente**.

⚠️ **Em produção continua tudo como estava:** os 15 grupos seguem com `parent_id` nulo,
`grupo_faixas_faturamento` segue zerada, nenhuma migration nova, nenhum deploy. Esta entrega dá a
ferramenta; quem monta é o usuário, na tela.

## T1 — A página, e a escala de cada grupo

`GruposCobrancaHierarquiaController::index()` serve `Admin/GruposCobranca`, no mesmo grupo de
permissão das rotas de escrita (`admin.contratos`) — quem pode juntar precisa poder abrir.

Cada grupo aparece com **quantas empresas**, **quanto faturou** e **quanto cobra por mês**, mais o
selo de **de onde vem a tabela** que produziu aquele valor. Esses números **não são calculados na
tela nem no controller**: vêm de `SimuladorGrupoCobrancaService::estadoAtual()`, método novo que
reusa o mesmíssimo `calcularLado()` que serve os dois lados de `simular()`, chamado com o
`parent_id` que está no banco.

> Há teste (`os_numeros_da_listagem_sao_os_mesmos_que_a_previa_usa`) comparando, grupo a grupo, a
> prop que a tela recebe com o retorno do simulador. Uma segunda conta na listagem seria o jeito
> mais rápido de a tela dizer R$ 33.500 e a fatura sair outra coisa.

A tela separa **grupos que já são cobrados juntos** (com os grupos de dentro listados, cada um com
a sua contagem e um botão para tirar) de **grupos cobrados sozinhos** (com caixa de seleção). Cada
cartão leva para a tabela daquele grupo (a página do 143-03).

### Criar o grupo de cobrança: dois passos, de propósito

O plano pede que criar um grupo novo seja o caminho primário — e é: é o primeiro botão da tela. Mas
criar e juntar ficaram em **dois passos**, e a razão não é comodidade:

1. A tela precisa oferecer o cadastro da tabela **antes** de juntar (item 3 do plano).
2. Não existe cadastrar a tabela de um grupo que ainda não existe.

Logo, a única ordem coerente é **criar → cadastrar a tabela → juntar**. E criar um grupo vazio não
muda cobrança nenhuma: ele não tem empresa, não tem grupo dentro, não produz linha. Isso está
provado por teste, comparando `estadoAtual()` antes e depois da criação (total e número de linhas)
e conferindo que nenhuma empresa trocou de `company_group_id`.

⚠️ A criação passa por uma **rota própria** (`admin.contratos.grupos.criar`) e não pela
`company-groups.store` que já existia: aquela vive sob `role:admin`, e quem recebeu
`admin.contratos` por setor levaria 403 no meio do fluxo. É a armadilha que a Fase 142 já pagou
para fechar. A criação também deixa entrada em `activity_log`.

## T2 — A prévia, e ela é obrigatória

O botão que grava **existe apenas dentro do bloco `{previa && (`**. Não é um `disabled` que alguém
remove por engano: sem prévia carregada, o botão não está na árvore de renderização. E há uma
segunda trava, na função: `if (!previa) return;`.

A trava que importa mais, porém, é a outra:

```jsx
useEffect(() => {
    setPrevia(null);
    setErroPrevia(null);
    setCienteSemTabela(false);
}, [selecionados, destinoId]);
```

Mexeu na seleção ou no destino, **a prévia some**. Sem isso, a pessoa pediria a prévia de um
arranjo, marcaria mais um grupo e confirmaria lendo o número do arranjo anterior — o erro mais caro
que esta tela poderia cometer, e invisível.

A prévia mostra os três números que o plano exige, lado a lado:

| | |
|---|---|
| **Como está sendo cobrado hoje** | quantas cobranças separadas e quanto somam |
| **Como passaria a ser cobrado** | quantas cobranças e quanto |
| **Diferença na cobrança** | em reais, **com sinal**, em `text-3xl`, mais o valor anualizado |

No caso real: R$ 33.500 → R$ 21.000, **−R$ 12.500 por mês** (R$ 150.000 por ano). Cada linha dos
dois lados traz nome, empresas, faturamento, faixa, o que junta e o selo da tabela.

### De onde vem a tabela

Cada linha da prévia carrega `procedencia` e renderiza o mesmo vocabulário das outras telas
(`Admin/TabelaEmpresa.jsx`, `Admin/ContratoDetalhe.jsx`), para as três não contarem histórias
diferentes do mesmo dado:

| procedência | o que a tela diz |
|---|---|
| `contrato` | "Tabela conferida pelo contrato assinado" (verde) |
| `manual` | "Tabela cadastrada à mão no sistema" |
| `presumida_servico` | "Tabela copiada do serviço contratado — ninguém conferiu contra o contrato ainda" (âmbar) |

Quando a tabela é de um grupo, o selo diz **de qual grupo**. É exatamente a informação que separa
R$ 21.000 de R$ 12.000 no caso do CONTEXT: sem ela, o número final é um palpite de R$ 9.000/mês.

### Grupo de cobrança sem tabela própria

`tem_tabela_propria` vem nas props, e quando o destino escolhido não tem tabela a tela mostra, **antes
de qualquer confirmação**:

> ⚠️ *"{Grupo} ainda não tem tabela de cobrança própria. Sem ela, a cobrança do conjunto vai seguir
> a tabela de uma das empresas — que na maioria dos casos foi copiada do serviço contratado e nunca
> conferida contra o contrato. O valor pode cair bem mais do que deveria. Cadastre a tabela antes de
> juntar os grupos."*

Com o botão **"Cadastrar a tabela de {Grupo}"** levando à página do 143-03. E, para confirmar
mesmo assim, é preciso marcar um reconhecimento explícito ("Li o aviso acima e quero juntar mesmo
sem tabela cadastrada em X") — enquanto ele não é marcado, o botão de confirmar fica travado.

### Queda é a correção, não o alarme

A redução aparece grande e no amarelo da marca, nunca em vermelho, com o texto:

> *"A cobrança cai R$ 12.500,00 por mês, ou R$ 150.000,00 por ano. Cair é o resultado esperado:
> cobrado em pedaços, o cliente paga como se fosse vários clientes médios e perde o desconto por
> volume da tabela."*

Há teste proibindo `text-red` e `variant="destructive"` no arquivo inteiro.

## T3 — O que a tela impede (sem reimplementar nada)

A tela só oferece caixa de seleção para quem é cobrado sozinho e tira o destino escolhido da lista
de candidatos — o que já elimina os arranjos inválidos do fluxo normal. Mas **a regra continua
morando no `saving()` do model** (143-01): quando a gravação é recusada, a mensagem em pt-BR do
model chega à tela e é exibida como está, sem tradução e sem uma segunda cópia da regra em lugar
nenhum. Teste:

```
"DRossi" já está dentro de outro grupo — a hierarquia tem um nível só.
```

Desfazer (tirar um grupo de dentro de outro) segue a **mesma** disciplina: prévia primeiro
(`if (!saida?.previa) return;`), confirmação depois. O plano só exige a prévia antes de juntar, mas
desfazer **sobe** a cobrança do cliente — é a mesma decisão de dinheiro ao contrário.

## Desvios do plano

Nenhum desvio de Regra 1/2/3 a registrar: o plano foi executado como escrito. As duas decisões que
saem da letra do plano estão declaradas como decisões, abaixo, e nenhuma delas acrescenta escopo
fora do plano — uma é a ordem do fluxo, a outra é simetria da prévia no caminho inverso.

Um único ajuste de implementação apareceu durante os testes: `criar()` quebrava com
`Undefined array key "color"` quando o campo não vinha na requisição (a tela não manda cor).
Corrigido para `($dados['color'] ?? null) ?: '#ffe600'` antes do primeiro commit.

## Decisões que tomei sozinho

1. **Criar e juntar em dois passos** (ver T1). Não dá para cumprir "ofereça o cadastro da tabela
   antes de pendurar" com um passo só, porque a tabela precisa de um grupo existente.
2. **`estadoAtual()` no simulador**, não uma consulta no controller — para a listagem falar a mesma
   língua da prévia e do fechamento, com teste comparando um com o outro.
3. **Prévia também para desfazer.** Simetria de risco.
4. **Reconhecimento explícito quando falta tabela no destino** em vez de bloquear de vez: pode
   haver caso legítimo (o grupo herda a tabela certa de uma empresa conferida pelo contrato), e
   bloquear empurraria a pessoa para fora da tela. O que não pode é passar batido.
5. **A tela não lista as empresas uma a uma** (a página da tabela do grupo, 143-03, já faz isso e
   linka daqui). Aqui a unidade da decisão é o grupo; listar 46 empresas afogaria o número que
   importa.
6. **Nomes de rota e componente no padrão da casa:** `admin.contratos.grupos.index`/`.criar`,
   `Admin/GruposCobranca`.
7. **Entrada pela tela de Contratos** (botão "Grupos de cobrança" ao lado do título). Sem caminho de
   entrada a tela nova não existe na prática — lição do 143-03.

## O que decidi NÃO fazer

1. **Não pendurei ninguém.** Em produção os 15 grupos seguem com `parent_id` nulo depois desta
   entrega, como o plano manda.
2. **Não toquei em `classificar()`**, nem em `NpsGrupoCoberturaService`, `NpsGroupSurvey` ou a
   migration de `nps_group_surveys` — proibição explícita, nem para leitura de edição.
3. **Não plugei prévia multi-mês** (item 6 do `deferred-items.md`). A tela aceita `?mes=YYYY-MM` e o
   serviço aceita chamada repetida; uma comparação de 2-3 meses lado a lado é desenho próprio e
   nenhuma decisão desta entrega depende dela.
4. **Não expus a composição por grupo na tela do fechamento** (item 5 do `deferred-items.md`) — é
   outra tela, e o plano 04 é sobre a montagem.
5. **Não extraí `FormularioFaixas`** (item 8) — não encostei nele.
6. **Sem deploy, sem `.env`, sem migration, sem `state.advance-plan`.**

## O NPS não sentiu nada

Nenhuma empresa é remanejada de grupo: há teste comparando `companies.company_group_id` de todas as
empresas antes e depois de juntar os quatro grupos do caso real, por igualdade de coleção — é essa
coluna que `nps_group_surveys` e `NpsGrupoCoberturaService` usam, e nada nesta entrega a toca.
Nenhum dos três arquivos proibidos foi aberto.

**Suíte de NPS (conferência extra, fora do gate):** `--filter="Nps|NPS"` → **614 testes, 0 errors,
8 failures, 606 passando** — **número por número idêntico ao baseline medido no 143-03**. As 8
failures são as mesmas já documentadas lá (janela de NPS no `desempenho:consolidar-mes`, política de
`expires_at` e hash de token), alheias a esta fase. **Nenhum teste de NPS mudou de resultado** — não
houve o que parar e reportar. Nenhuma foi consertada.

## Gates

Exit code capturado **antes** de qualquer pipe (saída redirecionada para arquivo).

**Gate 1** — `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911"`

| | baseline (143-03) | depois |
|---|---|---|
| resultado | `Tests: 772 passed (3479 assertions)` | `Tests: 797, Assertions: 3617` |
| failures / errors | **0 / 0** | **0 / 0** |
| exit code | `EXIT=0` | `EXIT=0` |

797 = 772 + **25 testes novos** (13 da página/rotas + 12 das travas de arquivo). Conferência:
`grep -cE "^[0-9]+\) "` na saída = **0** blocos de falha/erro.

**Gate 2** — `--filter="Phase74|Phase110"`

| | baseline | depois |
|---|---|---|
| resultado | `Tests: 39 passed (170 assertions)` | `Tests: 39, Assertions: 170` |
| exit code | `EXIT=0` | `EXIT=0` |

**Build:** `npm run build` — `✓ built in 43.49s`, sem aviso. `GruposCobranca` presente no
`manifest.json` (a armadilha da página que some do manifest). CSS compilado conferido com `grep -F`
sobre o seletor completo, como o prompt manda — `.accent-ecf-yellow`, `.text-3xl`,
`.border-ecf-yellow\/25`, `.bg-ecf-yellow\/\[0\.03\]`, `.px-\[18px\]`, `.gap-1\.5` têm 1 ocorrência
cada (`.h-7`, 2). Teste regex travando classes fora da escala do Tailwind.

## Commits

| hash | assunto |
|---|---|
| `e73c2706` | `feat(143-04): tela de grupos de cobranca, com a escala de cada grupo` |
| `b8ea57d8` | `feat(143-04): a previa do impacto na frente de qualquer gravacao` |

Todos com `git add` por caminho (árvore compartilhada com a outra sessão ativa) — nunca
`git add -A`/`git add .`, nunca `git commit -a`, nunca `git stash`.
`git status --porcelain app/ tests/ resources/ routes/` conferido antes de cada commit; o único item
alheio na área (`tests/Feature/CompanyPortfolioAccessTest.php`, não rastreado, da outra sessão)
ficou intocado do começo ao fim.

## Próximo passo

A fase está completa em código. O que falta é **operação, com gente**, na ordem que o CONTEXT
exige: rodar a migration do `parent_id` em produção, montar o caso MPozenato **pela tela** (criar o
grupo de cobrança → cadastrar a tabela dele → conferir a prévia → juntar) e consolidar a competência.
Os itens 3, 5, 6, 7, 8 e 9 do `deferred-items.md` continuam abertos; o de maior risco operacional é
o **7** — `fechamento:consolidar-mes` avisa mudança de faixa como se fosse desempenho quando a
composição do grupo mudou entre duas competências.

## Self-Check: PASSED

Os 7 arquivos declarados existem em disco e os 2 commits existem no histórico, conferidos um a um.
