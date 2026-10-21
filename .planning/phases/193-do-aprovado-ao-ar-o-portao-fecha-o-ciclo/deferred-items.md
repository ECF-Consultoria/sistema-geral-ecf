# Dívidas e itens diferidos — Fase 193

> **Escrito pelo plano 193-05 (o fechamento), em 21/10/2026.**
> ⚠️⚠️ **TODA dívida aqui tem DONO.** `a definir` **não é dono** — há gate sobre isso no
> `FechamentoDaFase193Test::test_as_perguntas_193_tem_dono_e_o_roteiro_da_conta_459_e_roteiro_193_05`,
> que **ACUSA** a forma `dono: a definir`.
> ⚠️ **Nenhum item desta lista é "trabalho faltando dentro da Fase 193".** São fronteiras do
> desenho, decisões do usuário, limites do ambiente e dívidas de **outras** fases que esta fase
> **mediu** e registra para não serem descobertas por acidente.
> ⚠️ **E nenhum dono foi MUDADO:** onde o `deferred-items.md` da Fase 188 já atribuiu um dono, ele é
> **repetido**, não reatribuído.

---

## 1. ⚠️ PERGUNTA-193-A — a oferta aprovada que a fila RECUSA — **FECHADA pelo usuário**

**Dono: usuário. DECIDIDA em 20/10/2026 (decisão 21).**

**O que foi medido:** `FilaPublicacaoService::agendar()` só aceita produto **`pronto`**, e
`ResumoRapidoService::prontidaoDoLote()` tem **11 motivos** de recusa. ⚠️ **Nenhum dos 8 bloqueios do
portão cobre a conferência L3 com o Mercado Livre** — logo existe uma janela em que aprovar sucede e
o enfileiramento é recusado.

**A decisão:** a oferta fica em **`aprovado`** (cujo rótulo do protótipo é *"aguardando publicação"* —
**é a verdade**), o **motivo real** vai ao histórico, e o reconciliador **tenta de novo a cada
minuto**. ⚠️ **O 9º bloqueio no portão foi OFERECIDO e RECUSADO** — ele exigiria editar
`VocabularioDoPortao` e `BloqueiosDaOfertaService`, serviços da **Fase 186 em produção**, com gates de
total fixo.

➡️ **Nada a fazer.** Registrado para ninguém reabrir. ⚠️ **A alternativa `aprovado → publicando →
falha` imediato também foi recusada:** passaria por `publicando` **sem nada em voo** — um estado que
mente, numa máquina em que `publicando` **bloqueia edição**.

---

## 2. ⚠️ PERGUNTA-193-B — editar a FOTO de um anúncio JÁ NO AR — **FECHADA pelo usuário**

**Dono: usuário. DECIDIDA em 20/10/2026 (decisão 22): DIFERIDA para fase própria.**

**O que foi medido, e é o que sustenta a decisão:**
- `PubAprovacaoOferta::TRANSICOES` tem **`PUBLICADO => []`** — terminal por **ESTRUTURA**, não por
  convenção: nenhuma transição sai dele (provado pelo gate `193-03 bloqueios-e-terminal`, com os **8**
  destinos recusados por igualdade de conjunto);
- ⚠️⚠️ **não existe NENHUM `PUT` cujo caminho COMEÇA em `/items`** em todo o Publicador. ⚠️ **E o
  invariante tem de ser escrito assim:** existem **TRÊS** `PUT` (`AlterarCampanha:211`,
  `AlterarCupom:146`, `AlterarNoConvite:155`), e o terceiro usa **`PUT /seller-promotions/items/`**,
  que **CONTÉM** `/items/`. Sonda por **substring** acusa **trabalho legítimo** — medido nesta wave e
  asseverado nas duas pontas no gate `193-05 territorio-e-d11`.

**O que a fase própria vai precisar decidir** (escrito agora, para não ser redescoberto):
*(a)* uma transição nova **saindo de um estado terminal**; *(b)* a capacidade de escrita `PUT` que o
sistema **não tem**; *(c)* **quem é o dono da foto no ar** — reconciliação entre o acervo e o anúncio
publicado.

---

## 3. PERGUNTA-193-C — o toast *"Publicado e confirmado"* não dispara sozinho

**Dono: Fase 194 ou fase futura de tela.**

**O que foi medido:** a confirmação do MLB chega **minutos depois**, pelo scheduler. O toast existe
(`vocabularioDoPortao.jsx`, chave `publicado:`) e **só dispararia** se a tela recarregasse ou houvesse
polling — e **não há polling**.

⚠️ **ISTO NÃO É DEFEITO:** o **critério 4** exige justamente que ele **NÃO dispare sem MLB**, e isso é
**asseverado como AUSÊNCIA** (gate `193-03 bloqueios-e-terminal`: mover para `publicado` sem MLB é
**no-op completo e sem toast**). ➡️ O que fica para a fase de tela é a decisão de **a fila atualizar
sozinha** (recarga, polling ou evento).

---

## 4. ⚠️ PERGUNTA-17 — `mlb_empresa_id` anulável pode esconder a oferta da fila

**Dono: Fase 194.**

É ela que **cria** oferta. ⚠️ **Nenhum plano da 193 a conserta, e nenhum a agrava** — porque nenhum
plano da 193 cria oferta. Registrada aqui só para não se perder entre as fases.

---

## 5. ⚠️⚠️ A família *"gate mede WIP, não território"* — 24 pontos em 7 arquivos

**Dono: usuário / orquestrador.**

**O que foi medido:** há **24** chamadas a `numstatDe(rel)` **sem o 2º argumento** em **7** arquivos de
gate, mais dois `git status --porcelain`. Sem a base, o helper compara a **ÁRVORE** — logo o gate
**reprova ANTES do commit e fica VERDE depois**. ➡️ Ele **não protege nada** e **acusa trabalho certo
no meio de uma wave**.

⚠️ **O conserto de raiz JÁ EXISTE no código:** o helper **aceita** a base, e
`publicador-painel-casca-188.test.js:549` já usa o range `HEAD~1 HEAD`. ➡️ É mecânico: passar a base
nos 24 pontos. ⚠️ **Não é território desta fase** (são gates de fases **em produção**), e por isso
**não foi tocado**.

---

## 6. ⚠️ O gate alheio `186-09 sem-segundo-passo` não pega a forma pt-BR acentuada

**Dono: usuário / orquestrador.**

**O que foi medido:** a `sondaPub09` do `186-09` casa `confirmar_publicacao`/`confirmarPublicacao` e
**NÃO** casa *"Confirmar publicação"* (prosa acentuada, com espaço). A sonda do `188-05` faz o
**INVERSO**. ⚠️ **Nenhuma das duas, isolada, cobre as nove formas.**

➡️ **O que as waves 02/03/04 e o `193-05` fizeram:** usar a **UNIÃO** das duas, na **sua própria**
lista constante, com isca nas duas direções. ⚠️ **Os dois gates alheios NÃO foram alterados** — eles
estão em produção, e alargá-los é decisão de quem os mantém. ⚠️ **E alargar o do `186-09` é delicado:**
ele varre **`app/` inteiro**, e foi um gate dessa natureza que **BARROU UM DEPLOY** nesta milestone (o
MCP da Shopee usa `'andamento'` como status de promoção).

---

## 7. ⚠️ Os ponteiros do `189-FID01.md` §G apodreceram TRÊS vezes — e um aponta para fase CANCELADA

**Dono: usuário / orquestrador.**

Um dos ponteiros **atribui MOD-06 à Fase 190**, que foi **CANCELADA** pela **decisão 20** (20/10/2026).
⚠️ **NÃO é artefato desta fase** e não foi tocado.

---

## 8. ⚠️⚠️ O id `guarda-modalidade-irma` está ÓRFÃO de `<verify>` — ele NUNCA é rodado pelo plano

**Dono: usuário / orquestrador.**

**O que foi medido** (gate `193-05 bijecao-dos-cinco-manifestos`): o id está declarado em
`tests/gates/contagens-193-02.txt` com contagem **medida** (`tests=2 assertions=25`) e aponta para
**dois métodos que EXISTEM** — mas **nenhum `<verify>` do `193-02-PLAN.md` o invoca**.
⚠️⚠️ **Ele não falha: ele simplesmente NUNCA RODA**, e isso é **indistinguível de um gate verde** (a
revisão da Fase 188 achou **NOVE** assim).

**A causa, medida:** o id **nasceu no meio da wave 02**, quando a **decisão 23** do usuário chegou — e
o `193-02-PLAN.md` é artefato do **PLANEJADOR**, que nenhum executor edita.

⚠️ **O id FUNCIONA:** a wave 02 e a quick `261021-fix` colaram a saída dele (`2 passed / 25
assertions`), e eu o considerei na bijeção. **O que falta é o `<verify>`.**

**O conserto, para quem o fizer:** acrescentar
`sh tests/gates/verificar-testes.sh 193-02 guarda-modalidade-irma` ao `<verify>` da tarefa 1 do
`193-02-PLAN.md`. ⚠️ **Enquanto não for feito, o conjunto de órfãos está CONGELADO e DECLARADO no
gate** — órfão **novo** reprova. ⚠️ **Apagar um id da lista congelada para "passar" é exatamente o
conserto que o learnings proíbe.**

⚠️ **E o mesmo vale para os outros três** (`bijecao-manifesto-193-02/03/04`), com uma nuance **medida**:
**não é "auto-referência por natureza"** — o `193-01-PLAN.md` **DECLARA o dele**, provando que é
declarável. As waves 02/03/04 **simplesmente não o declararam**, e o gate da wave 04 os excluiu **por
prefixo** em vez de declará-los.

---

## 9. ⚠️ Dois números de artefato do planejamento que ficaram ERRADOS

**Dono: usuário / orquestrador.** ⚠️ **DEVOLVIDOS em vez de editados** — `193-REGUA.md`,
`193-COBERTURA.md` e os `193-NN-PLAN.md` não pertencem a plano nenhum.

1. **`193-COBERTURA.md` §1, critério 9:** diz *"as **6+5** da 188 mais nenhuma"* (= **11**).
   ⚠️ **O ROUTER mede 16** rotas `mlb.anuncios.meus.aprovacao*`. O conjunto correto está **congelado
   por NOME** no gate `193-05 nada-sem-destino` e no `193-04 nenhuma-rota-nova`.
2. **`193-REGUA.md` §2 e §7:** dizem que **o MariaDB local está PARADO**. ⚠️ **Está DE PÉ** (PID
   **30496**, ligado por outra sessão em 20/10 às 21:45) — **e não afeta nada**, porque
   `phpunit.xml:27-28` fixa `sqlite` `:memory:`. ⚠️ **O importante é não escrever "mysqld parado"** e
   não concluir nada do estado dele.

⚠️ **Já foram corrigidos QUATRO números desses artefatos pelo orquestrador** (`e341d3ef`, `05c26309`,
`b222df47`): a régua do `contagens-186-09.txt`, o invariante do manifest, a linha de *"nada em `app/`
cria oferta"* e o mapa das decisões. **Estes dois são os que sobraram.**

---

## 10. ⚠️ `lerSemComentarios` destrói 76% de um arquivo de teste

**Dono: fase futura ou quick dedicada** (dono atribuído pelo `deferred-items.md` da Fase 188 —
**repetido, não reatribuído**).

Medido na Fase 188 e **não piorado** por esta fase. ⚠️ Os leitores desta fase são **o tokenizador do
PHP** (`token_get_all`), que não tem esse problema — e por isso a 193 **não** usa o leitor por forma.

---

## 11. ⚠️ `recusaDoPainel()` × `back()->withErrors()` — as **18** chamadas

**Dono: fase futura ou quick dedicada** (dono da Fase 188 — **repetido**).

⚠️ **Esta fase MEDIU e NÃO piorou:** a action `tentarDeNovo` usa `recusaDoPainel()` como as outras
**por consistência deliberada**, e a decisão **26** acrescentou a gravação do motivo **fora** da
transação **sem** mudar o formato da resposta (o `422` com `regra` estável continua saindo).
➡️ Trocar o formato aqui criaria **duas convenções na mesma tela**.

---

## 12. ⚠️ Dívidas herdadas que esta fase mediu e NÃO piorou

| item | dono (repetido da 188, **não** reatribuído) |
|---|---|
| a fixture do gate `casca-do-painel` não reflete o servidor (passa pelo FALLBACK por substring) | fase futura |
| o rótulo legível de variação não existe no payload (`rotuloDoGrupo()` é `private static` em território VETADO) | a fase que puder coordenar com o outro dev |
| a **escrituração dupla** do diretório `aprovacao/`, deliberadamente não unificada | fase futura |
| a `188-FID01.md` com **duas** seções `§P` e o mesmo ordinal | orquestrador |
| a **DIV-01 da Fase 186** segue aberta | Fase 186 / orquestrador |
| `STATE.md:4513` diz *"189-FECHAMENTO.md (1407 linhas)"* e o arquivo tem **1432** | orquestrador |
| o `ROADMAP.md` tem **duas** tabelas de requisitos que **discordam** (`:5129` diz `AMPL-01..07 Pending`, `:5201` declara entregue com o placar) | orquestrador |

---

## 13. ⚠️ As falhas que OSCILAM, e por que nenhuma virou limiar

**Dono: fase futura ou quick dedicada.**

Os dois casos de `tests/Feature/Publicador/CockpitDaContaTest.php` **oscilam com a HORA DO DIA**
(fronteira de dia), e `SincronizaPortalCompletoTest`/`PortalFotosTest` têm falhas de **corrida** em
`storage/framework/testing` que **passam em isolamento**.

⚠️⚠️ **NENHUM `REF_PHP_FALHAS` FOI CRIADO, em nenhuma das cinco waves** — um limiar de falhas aceito
seria **a porta dos fundos que o harness existe para fechar**. ⚠️ **A hipótese do listener continua
HIPÓTESE:** os dois experimentos que a testariam saíram **verdes**. ⚠️ E **`$this->setUp()` chamado no
meio de um caso derruba o `RefreshDatabase`**: falha de ordem **nem sempre** é sensibilidade de
listener.

---

## 14. ⚠️ O que esta fase NÃO antecipou — e a razão de cada um

| item | razão |
|---|---|
| o produto com **DOIS alvos ativos** em produção | o agente encaminha produto com **UM** alvo ativo, então a guarda da decisão 23 e a decisão 25 **nunca foram exercitadas em produção**. ⚠️ **Dono: Fase 194 / piloto do agente** — *vale medir, não supor* |
| o **AG-05b** da outra sessão (patch em `PublicacaoService::iniciar()` e `abrirTarefaPosPublicacao()`) | ⚠️ ela **esperou** o fechamento desta fase porque o gate de `numstat` do PUB-03 reprovaria. ➡️ **Com este fechamento ela está liberada**, e o `numstat` do `PublicacaoService` deixa de ser invariante desta fase. **Dono: outra sessão** |
| o **+1 entrada não-página** do manifest (295 → 296) | ⚠️ **não isolável sem `git checkout`** (o `manifest.json` não é rastreado), que é **proibido**. ⚠️ **Provado que NÃO é da 193.** **Dono: orquestrador**, se quiser isolar |
