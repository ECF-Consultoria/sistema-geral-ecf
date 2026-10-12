---
phase: 179-visao-geral-cockpit
plan: 02B
subsystem: api
tags: [laravel, publicador, prontos-fase2, fonte-unica, decisao-do-usuario, mutacao]

requires:
  - phase: 175-fases-do-produto
    provides: "A lista ciente de fase (eh_kit, kits) e a regra prontosParaFase2()"
  - phase: 178-modal-nova-publicacao
    provides: "NovaPublicacaoService::grupos() e a Exclusão 2, agora SUPERADA"
  - phase: 179-visao-geral-cockpit
    plan: 01
    provides: "JornadaDosProdutosService — o estágio `ready` e a medição da divergência"
  - phase: 179-visao-geral-cockpit
    plan: 02
    provides: "AcoesDaContaService e o raio de impacto medido da divergência"
provides:
  - "ProgramasPublicadorService::prontoParaFase2(array $p): bool — a FONTE ÚNICA da regra"
  - "ProgramasPublicadorService::noAr(array $p): bool — a definição única de 'no ar'"
  - "tests/Feature/Publicador/ProntoParaFase2Test.php — o gate que reprova se as definições divergirem"
affects: [179-03, 179-04-controller-visaogeral, 179-06-acoes-no-cliente, 179-10-fechamento, 180-publicacoes, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "Predicado por produto consumido por várias telas vive como `public static` no serviço que já é dono do vocabulário (STATUS_NO_AR, ehComposto) — não numa classe nova que só compõe constantes de outra"
    - "Gate de paridade compara CONJUNTO DE SKU, não contagem: dois consumidores podem acertar o número errando o produto"
    - "Gate de paridade exige um produto da CLASSE DE FRONTEIRA no cenário — sem ele o gate passa com as duas definições que existe para comparar"
    - "Teste que reimplementa a regra ANTIGA ao lado da nova, provando que o cenário distingue as duas (anti-vazio-por-construção)"
    - "Reaponte com VETO ao valor antigo na mensagem: a asserção nomeia o número anterior e manda reler a decisão"
    - "Mutação que sobrevive é investigada como LACUNA DE COBERTURA antes de ser declarada equivalente — M12 não era equivalente"

key-files:
  created:
    - tests/Feature/Publicador/ProntoParaFase2Test.php
  modified:
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Services/Publicador/PainelVisaoGeralService.php
    - app/Services/Publicador/JornadaDosProdutosService.php
    - app/Services/Publicador/NovaPublicacaoService.php
    - tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php
    - tests/Feature/Publicador/JornadaDosProdutosTest.php
    - tests/Feature/Publicador/AcoesDaContaTest.php

key-decisions:
  - "DECISÃO DO USUÁRIO 12/10/2026: alinhar os três lugares. A base cuja publicação FALHOU mas que tem anúncio CREATED vivo, sem kit e não composta, CONTA como pronta para a Fase 2 nos três"
  - "Isso SUPERA a 'Exclusão 2' do 178-01 ('erro de publicação é triagem'). A exclusão sobrevive só para `erro` SEM anúncio vivo"
  - "A fonte única mora em ProgramasPublicadorService, ao lado de ehComposto(), pelo precedente declarado no docblock dele — e porque os dois ingredientes da regra (STATUS_NO_AR, ehComposto) já moram lá"
  - "O estágio `ready` da Jornada passa a SER a regra por construção, não uma reimplementação dela"
  - "O fallback de shape antigo de prontosParaFase2() FICA — matá-lo faria a linha desaparecer por falta de dado em vez de contar como antes"
  - "A `nota` do item do modal NÃO ganha marcador de erro: o erro segue visível na MESMA tela, na ação crítica 'Produtos com problema na publicação'"
  - "O gate cru do 179-10 ('asserções removidas = ZERO' por grep '^-') é vazio-ao-contrário para reaponte de valor — refinamento declarado abaixo"

metrics:
  duration: "~2h30"
  completed: 2026-10-12
  tasks: 4
  tests_added: 6
  mutations: 13
  mutations_caught: 12
  mutations_coverage_gaps_found: 1
  migrations: 0
  packages: 0
---

# Fase 179 Plano 02B: "Pronto para a Fase 2" com UMA fonte de verdade

A regra que existia em **três cópias** — e foi exatamente a cópia que divergiu — virou **uma**:
`ProgramasPublicadorService::prontoParaFase2()`. O anel, a ação da Visão geral e o grupo do modal da
Fase 178 passaram a concordar **sobre quais produtos**, não só sobre quantos.

---

# ⚠️⚠️ A DECISÃO DO USUÁRIO — 12/10/2026 (literal, para nenhuma sessão futura desfazer)

**Pergunta levada a ele:** *"Um produto cuja publicação falhou, mas que JÁ TEM anúncio vivo no ar e
sem kit, conta como 'Pronto para Fase 2'?"*

**As três opções apresentadas:**

| # | Opção |
|---|---|
| **1** | **Sim — alinhar os três lugares.** |
| 2 | Não — erro é erro e fica fora de todo lugar. |
| 3 | Ver o número real de produção primeiro. |

Apresentada **com o aviso explícito** de que alinhar **muda o modal que ele já aprovou e está em
produção**.

➡️ **Escolha dele: a opção 1 — "Sim, alinhar os três lugares."**

## ⚠️ A consequência aceita por ele, e a contradição que ela resolve

O grupo `PRONTOS PARA FASE 2` do modal da Fase 178 **passa a incluir** o produto com rascunho
`FAILED` + anúncio `CREATED` vivo + sem kit + não composto.

⚠️ **Isso SUPERA a "Exclusão 2" declarada no `178-01-SUMMARY.md`** e no docblock de
`NovaPublicacaoService::grupos()`:

> *"`status.chave = 'erro'` → erro de publicação é **triagem**, e a casa dela é o bloco 'O que fazer
> agora' da Visão geral. Este modal é a ENTRADA de uma publicação nova, não a fila de conserto."*

A contradição é resolvida **pela decisão do usuário**, que é **posterior e mais informada**. A
exclusão **sobrevive só para `erro` SEM anúncio vivo** — e o docblock dela foi **reescrito nesse
sentido, não apagado**.

⚠️ **NENHUMA SESSÃO FUTURA DEVE "RESTAURAR" A EXCLUSÃO 2 ACHANDO QUE FOI REGRESSÃO.** O registro
está em quatro lugares de código, não só aqui: no docblock de `prontoParaFase2()`, no da Exclusão 2
reescrita, e nas mensagens de asserção dos três gates (que nomeiam o valor antigo e mandam reler
esta SUMMARY).

⚠️ **A definição que VENCE é a do `bucketDaFase()`**: status em `STATUS_NO_AR` **OU** anúncio
`CREATED` vivo. Motivo já aceito na wave 01: é a que a tela de Produtos usa, e dizer "rascunho"
sobre um produto que está vendendo seria pior. **Não reabrir.**

⚠️ **O número de produção NÃO foi medido.** Leitura de produção (plink, tinker remoto, MCP) está
bloqueada nesta sessão — o classificador barra. A classe é estreita (base cujo rascunho está `FAILED`
**e** que ainda tem item `CREATED` vivo **e** sem kit **e** não composta), mas quantos produtos ela
tem na conta 459 **segue desconhecido**. O usuário escolheu a opção 1 sem esse número, informado de
que ele não existia.

---

## Onde a fonte única mora — e por quê

**Dois métodos `public static` em `ProgramasPublicadorService`, imediatamente ao lado de
`ehComposto()`:**

```php
public static function noAr(array $p): bool;            // STATUS_NO_AR ou anúncio CREATED vivo
public static function prontoParaFase2(array $p): bool; // base, não composta, noAr, kits === []
```

Justificativa, em ordem de peso:

1. **É o precedente declarado do próprio projeto.** O docblock de `ehComposto()` diz, literalmente:
   *"Pública e estática para a Visão geral (`PainelVisaoGeralService`) usar a MESMA regra."* A
   resposta deste projeto para "um predicado por produto consumido por várias telas" já existia.
   Inventar um segundo lugar seria criar um segundo padrão para o mesmo problema.
2. **Os dois ingredientes já moram ali:** `STATUS_NO_AR` (`:43`) e `ehComposto()` (`:468`). Uma
   classe nova seria um arquivo cuja única função é compor constantes de outra — e um lugar a mais
   onde ninguém olha.
3. **Menor área de diff:** nenhum arquivo novo em `app/`.
4. ⚠️ **`App\Support\Publicador` é território proibido** nesta milestone (gate de diff vazio). Nunca
   foi opção.

**O que torna "impossível" haver duas definições** (até onde PHP permite): a conjunção existe em
**um único lugar do `app/`**, provado por `grep` com isca (gate E6), e o gate central compara os
**conjuntos de SKU** dos três consumidores — não as contagens.

## ⚠️ A QUARTA cópia, descoberta ao medir: `noAr`

O briefing falava de três cópias de "pronto para a Fase 2". Medindo, a expressão de **"no ar"**
estava em **quatro** lugares:

| # | Onde | Virou |
|---|---|---|
| 1 | `ProgramasPublicadorService::bucketDaFase():452` | `self::noAr($p)` |
| 2 | `PainelVisaoGeralService::noArPorFase():348` | `ProgramasPublicadorService::noAr($p)` |
| 3 | `JornadaDosProdutosService::noAr()` (`private`) | **removido** — chama a fonte direto |
| 4 | a fonte nova | **a definição** |

As três delegações são **comportamento idêntico** (mesma expressão, byte a byte) e matam a cópia que
causou esta fase. ⚠️ A #1 revelou uma lacuna de cobertura real — ver a mutação **M12** abaixo.

## Os quatro consumidores, depois

| # | Consumidor | O que o usuário vê | Antes | Depois |
|---|---|---|---|---|
| 1 | `JornadaDosProdutosService::estagioDe()` | o estágio `ready` do anel de 176px | regra própria (2 ramos) | `prontoParaFase2()` — o estágio **É** a regra |
| 2 | `PainelVisaoGeralService::prontosParaFase2()` | a linha/ação "Prontos para a Fase 2" | cópia (1 ramo) | `prontoParaFase2()` |
| 3 | `NovaPublicacaoService::grupos()` | o grupo `PRONTOS PARA FASE 2` do modal | cópia (1 ramo) | `prontoParaFase2()` |
| 4 | `ProgramasPublicadorService::bucketDaFase()` | o "N Fase 1 · N kits" da tela de Produtos | cópia do `noAr` | `self::noAr()` |

⚠️ **O fallback de shape antigo de `prontosParaFase2()` FICA.** O ramo
`$cienteDeFase ? $total : contagemProdutos['publicados']` existe porque o método recebe listas sem as
chaves de fase; matá-lo ao delegar faria a linha **desaparecer** por falta de dado em vez de contar
como antes. Tem teste dedicado, e a mutação **M09** reprova nele.

---

## Os quatro gates que mudaram — e como

### 1. `NovaPublicacaoDoHeaderTest:335` — ⚠️ a ARMADILHA, consertada

A 179-02 mediu que este gate de paridade **NÃO quebraria** com o alinhamento. O motivo: **o cenário
dele não tinha nenhum produto em `erro`**, então ele passava com a regra antiga (só `STATUS_NO_AR`)
**E** com a nova. Um gate de paridade que não distingue as duas definições que existe para comparar
**não prova nada** — **sem isso o alinhamento do modal não teria gate nenhum.**

Consertado: o cenário ganhou `ERRO-COM-ANUNCIO` (rascunho `FAILED` + 1 anúncio `CREATED`), e o teste
prova que ele **ENTRA** no grupo do modal.

| Asserção | Antes | Depois |
|---|---|---|
| `assertSame(?, $daVisaoGeral)` | `2` | **`3`**, com veto: *"ERA 2 antes do alinhamento de 12/10/2026 … se voltou a 2, alguém desfez a decisão do usuário"* |
| `assertEqualsCanonicalizing(…)` | `['PUB','PARC']` | **`['PUB','PARC','ERRO-COM-ANUNCIO']`** |
| `assertCount($daVisaoGeral, …)` | intacta | intacta |
| `assertContains('ERRO-COM-ANUNCIO', …)` | — | **NOVA**, citando a decisão |

### 2. `JornadaDosProdutosTest:560-578` — REAPONTE, não remoção

Renomeado de `test_divergencia_com_prontos_para_fase_2_e_medida_e_declarada` para
`test_prontos_para_fase_2_tem_uma_fonte_so_e_o_anel_concorda`.

| Asserção | Antes | Depois |
|---|---|---|
| `assertSame(?, $daVisaoGeral)` | `1` (*"prontosParaFase2() não vê o anúncio vivo"*) | **`2`**, com veto ao `1` |
| `assertSame(?, $ready − $daVisaoGeral)` | `1` (o tamanho da divergência) | **`0`**, com veto: *"A DIVERGÊNCIA ERA 1 e foi RESOLVIDA em 12/10/2026 por decisão do usuário"* |
| `assertEqualsCanonicalizing(['PUB','ERRO-COM-ANUNCIO'], skus(ready))` | — | **NOVA** — conjunto, não contagem |

⚠️ A asserção nova usa `assertEqualsCanonicalizing` e não `assertSame`: a ordem vem de
`produtosParaTela()` (alfabética por nome) e **não é contrato deste plano**. Cravá-la quebraria o
teste pelo motivo errado — medido: a ordem real é `ERRO-COM-ANUNCIO, PUB`.

### 3. `AcoesDaContaTest::test_a_relacao_entre_o_anel_…` — ⚠️ o reaponte que exigiu uma ISCA

Renomeado para `test_o_numero_da_acao_de_prontos_fase_2_bate_com_a_sub_lista` — o nome não pode mais
prometer "diferença medida".

⚠️ **Este teste é SINTÉTICO** (`$linhas` e `$jornada` montados à mão), e por isso **passou no estado
RED**: os números divergentes estavam **cravados na entrada**. Reapontar só os valores deixaria o
gate vazio por construção. Entregue com **dois casos**:

- **Caso A (alinhado):** linha = 2, `ready` = 2. `assertSame(count($itens), $acao['numero'])` — **o
  gate do cabeçalho**: o número do cabeçalho é igual à contagem da sub-lista. (Antes do alinhamento
  abrir a ação mostrava DOIS produtos sob um cabeçalho que dizia UM.)
- **Caso B (ISCA):** a divergência antiga cravada (linha = 1, `ready` = 2). O serviço **não alinha
  nada por conta própria** — repassa o `numero` da linha e os `itens` do estágio, cada um da sua
  fonte. O `assertNotSame` original **fica vivo**, aplicado ao caso certo. É isto que prova que a
  igualdade do caso A vem das **REGRAS em `app/`**, e não de um alinhamento escondido no serviço.
  **A mutação M10 reprova exatamente aqui.**

| Asserção | Antes | Depois |
|---|---|---|
| `assertSame(?, $daAcao)` | `1` | **`2`**, com veto ao `1` |
| `assertSame(?, $doAnel − $daAcao)` | `1` | **`0`**, com veto |
| `assertNotSame($doAnel, $daAcao)` | no caso único | **movida para o Caso B** (a isca) |
| `assertCount(2, $acao['itens'])` | intacta | intacta (mensagem encurtada) |
| `assertSame(count($itens), $numero)` | — | **NOVA** — o gate do cabeçalho |

### 4. O gate do cabeçalho × sub-lista

É o `assertSame(count($acao['itens']), $acao['numero'])` do Caso A acima, **mais** o gate central de
`ProntoParaFase2Test`, que prova com produtos **reais** que as duas fontes (linha e estágio) são
agora a mesma regra. O dado em que eles divergiriam é o Caso B — que existe e está travado.

---

## O arquivo novo: `tests/Feature/Publicador/ProntoParaFase2Test.php`

**6 testes, 29 asserções.** É ele que reprova se as definições divergirem de novo.

| Teste | O que precisaria estar errado para falhar |
|---|---|
| `a_classe_de_fronteira_entra_nos_tres_consumidores` | ⚠️ **o gate central** — qualquer um dos três deixar de chamar a fonte. Compara **conjuntos de SKU** (`['PUB','PARC','ERRO-COM-ANUNCIO']`) do anel, do modal e a contagem da ação |
| `a_classe_de_fronteira_estava_fora_antes_do_alinhamento` | ⚠️ **a prova de que o cenário não é vazio por construção**: a regra ANTIGA é reimplementada no teste e devolve **2** onde a nova devolve **3**; a diferença é exatamente `['ERRO-COM-ANUNCIO']` |
| `prontoParaFase2_caso_a_caso` | cada condição da regra, isolada: 3 `assertTrue` + 6 `assertFalse`. O alinhamento abriu **um** caso, não a porteira |
| `no_ar_tem_uma_definicao_so` | os **quatro** consumidores de `noAr`, cada um com **isca** (`erro` **sem** anúncio é `false` em todos) |
| `o_fallback_de_shape_antigo_de_prontosParaFase2_sobreviveu` | o fallback morrer no alinhamento (a linha desapareceria por falta de dado) |
| `a_regra_nao_consulta_o_banco` | `assertSame(0, …)` **com isca** (`produtosParaTela()` numa conta real devolve `> 0`) — sem a isca, um `enableQueryLog()` mal posicionado daria zero por construção |

---

## A prova por mutação — 13 aplicadas, 12 reprovaram, **1 virou lacuna de cobertura**

Processo por mutação: **contagem de ocorrências travada ANTES** (recusa se ≠ 1), **recusa de alvo que
casa em linha de comentário** (a armadilha da Fase 178), aplicação, **conferência de QUAL linha
mudou** (impressa antes/depois), execução, restauração de cópia pristina e **conferência por
`sha256`** com aborto se divergir. Shas pristinos:
`ProgramasPublicadorService 6b17552f12628a90` · `PainelVisaoGeralService d0cf35b2c617bd54` ·
`JornadaDosProdutosService 77a897bbed54d2a7` · `NovaPublicacaoService 6a1b809399d105dd` ·
`AcoesDaContaService ea2ef8ded43473fe` — todos bateram em todas as rodadas.

| # | Mutação | Arquivo : linha | Reprovou em |
|---|---|---|---|
| **M01** | ⚠️ **a obrigatória**: `prontoParaFase2()` volta à definição ANTIGA (só `STATUS_NO_AR`) | `Programas:545` | **os TRÊS consumidores** + 4 casos do gate central |
| M02 | `noAr()` perde o 2º ramo (`anuncios !== []`) | `Programas:492` | os três + `no_ar_tem_uma_definicao_so` |
| M03 | deixa de exigir `kits === []` | `Programas:546` | `COM-KIT` entra nos três |
| M04 | deixa de excluir composto | `Programas:544` | `CB-PUB` entra nos três |
| M05 | deixa de exigir `eh_kit === false` | `Programas:543` | o kit entra nos três |
| **M06** | ⚠️ `NovaPublicacaoService` volta à conjunção INLINE antiga | `NovaPublicacao:147` | `NovaPublicacaoDoHeaderTest:335` + gate central |
| **M07** | ⚠️ `prontosParaFase2()` volta à conjunção INLINE antiga | `Painel:661` | `JornadaDosProdutosTest` + o modal + gate central |
| **M08** | ⚠️ `estagioDe()` ramo `ready` volta a usar só `STATUS_NO_AR` | `Jornada:232` | `JornadaDosProdutosTest` + gate central |
| M09 | `prontosParaFase2()` perde o fallback de shape antigo | `Painel:666` | o teste dedicado + 4 outros |
| **M10** | `AcoesDaContaService` reconta `prontos_fase2` pelos itens do estágio | `Acoes:200` | **o Caso B (ISCA)** de `AcoesDaContaTest` |
| M11 | `noArPorFase()` volta à expressão inline | `Painel:349` | `no_ar_tem_uma_definicao_so` |
| **M12** | `bucketDaFase()` volta à expressão inline | `Programas:449` | ⚠️ **SOBREVIVEU — lacuna de cobertura** |
| **M12b** | a mesma, depois de fechar a lacuna | `Programas:449` | `no_ar_tem_uma_definicao_so` |

⚠️ M06, M07 e M08 são **a prova de que unificar funcionou**: cada consumidor, devolvido à sua cópia,
reprova — era exatamente isto que o briefing exigia.

### ⚠️ M12 não era equivalência por construção — era LACUNA DE COBERTURA

Devolver o `noAr` de `bucketDaFase()` à expressão inline antiga **não reprovava em nenhum teste**. A
tentação era declará-la equivalente (como a M09 da wave 02, que de fato era). **Não é:** a base em
`erro` com anúncio vivo passaria a contar como `sem_oferta` em vez de `fase1_publicada`, mudando o
"N Fase 1 · N kits" do topo da **tela de Produtos** — **em silêncio**.

A pergunta *"o que precisaria estar errado para este gate falhar?"* foi feita da mutação, não só do
gate, e foi ela que separou os dois casos. `test_no_ar_tem_uma_definicao_so` passou a medir os
**quatro** consumidores (a fonte, a Jornada, `noArPorFase()` e `bucketDaFase()` via
`contagemProdutos()['por_fase']`), cada um com isca. Reaplicada como **M12b**, reprova.
Commit do fecho: `1dcca108`.

---

## Deviations from Plan

### [Rule 1 - Bug de gate] M12 sobreviveu: `bucketDaFase()` sem cobertura do 2º ramo de "no ar"

Ver o bloco acima. **Commit:** `1dcca108`.

### [Desvio de plano] O helper `linha()` do arquivo novo precisou de `oferta_id`

`contagemProdutos()` lê `$p['oferta_id']` sem `??` (`ProgramasPublicadorService:422`) e estourava
`Undefined array key` no shape mínimo. Acrescentada `'oferta_id' => null` ao helper — é o shape real
de `produtosParaTela()`, não um remendo.

### [Desvio de plano] A ordem dos SKUs em `ready` não é contrato — asserção canonicalizing

A primeira versão do gate novo de `JornadaDosProdutosTest` cravou `['PUB','ERRO-COM-ANUNCIO']` com
`assertSame` e reprovou: a ordem real é `['ERRO-COM-ANUNCIO','PUB']`, porque `produtosParaTela()`
ordena por nome (`Cadeira ERRO-…` < `Cadeira PUB`). Trocada por `assertEqualsCanonicalizing`, com o
motivo em comentário: a ordem é de outro serviço e cravá-la quebraria este teste pelo motivo errado.

### [Decisão declarada — e RECUSADA de propósito] a `nota` do item do modal NÃO ganhou marca de erro

`notaDeProntoParaFase2()` devolve *"Fase 1 publicada · 1 no ar"* para o produto em `erro`. É
**factualmente verdadeiro** (existe anúncio vivo) e **não menciona a falha**. Pesado e recusado:

- o **mesmo produto** aparece na **MESMA tela**, na ação crítica *"Produtos com problema na
  publicação"* de `oQueFazerAgora()` — a informação do erro **não se perde**, ela está no bloco cuja
  casa ela é (foi exatamente o argumento da Exclusão 2, e ele sobrevive à decisão);
- pôr um marcador ampliaria o diff para **texto de tela** que o usuário não pediu, nos dois serviços
  (o modal e a Jornada), e mexeria em notas que já estão aprovadas em produção.

⚠️ **Fica como pergunta ao usuário** na seção final. Mudar o texto muda só os dois serviços.

### [Desvio de processo] Task 2 e o ajuste do gate da Jornada num commit só

O reaponte de `assertSame` → `assertEqualsCanonicalizing` em `JornadaDosProdutosTest` só pôde ser
medido **depois** do GREEN (a ordem real não é dedutível do código sem rodar), então entrou no commit
do GREEN. Ordem de gate do TDD respeitada:

| Commit | O que | Gate |
|---|---|---|
| `806013d3` | `179-02B-PLAN.md` | docs |
| `ab0470e7` | os quatro arquivos de teste | **RED** — 5+2 falhas conferidas uma a uma |
| `d26b1e1c` | a fonte única + as 5 delegações | **GREEN** — 180 testes / 1489 asserções |
| `1dcca108` | o fecho da lacuna da M12 | Rule 1 |

### Nenhuma outra: nada fora do escopo foi tocado

Ver a tabela de gates. **Exatamente 9 arquivos** mudaram desde `eff3d3b5` (HEAD da wave 02), todos
deste plano, e **toda linha removida de `app/`** é uma das cópias substituída por delegação —
enumeradas e conferidas uma a uma (26 linhas, nenhuma de texto de negócio, nenhuma ordem de chave,
nenhum literal de status).

---

## Verification Results

⚠️ **Todo gate de diff com range `898d68c2..HEAD`** (a fase) **e** `eff3d3b5..HEAD` (este plano).
Nunca da árvore — `git diff --stat -- <dir>` depois de commitar compara a ÁRVORE e sai vazio sempre.

| # | Gate | Resultado |
|---|---|---|
| E1 | **ISCA** `git diff --stat 898d68c2..HEAD -- app tests` | ✅ **11 arquivos / 4251 inserções** — o vazio dos outros não é por construção |
| E1b | `git diff --stat eff3d3b5..HEAD` (só este plano) | ✅ **9 arquivos / 1174 inserções / 123 remoções** |
| E2 | diff **vazio** nos 15 caminhos proibidos, nos **dois** ranges | ✅ |
| E3 | ⚠️ os caminhos **existem no disco** (`test -e`) | ⚠️ **PEGOU UM GATE VAZIO POR CONSTRUÇÃO** — ver abaixo |
| E4 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | ✅ vazio (nenhuma deleção de arquivo) |
| E5 | asserções: 8 linhas `-assert`, **todas reaponte**, contra **39** `+assert` | ✅ — enumeradas abaixo |
| E6 | `grep` por segunda definição solta em `app/` | ✅ **1 ocorrência** de cada (a própria fonte), **com isca** confirmando que o grep acha as chamadas |
| E7 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1931 testes / 12308 asserções / 0 falhas** (baseline medida **ANTES** de editar, em 5m18s: **1925 / 12275 / 0**) |
| E8 | `resources/js` diff vazio nos dois ranges → JS não regrediu, não remedido | ✅ |

**Deltas de E7:** +6 testes (os 6 do arquivo novo) e **+33 asserções** — a contagem **subiu**, que é
o gate honesto de "nenhuma asserção perdida". **Deprecations 51 → 52**: a nova é o `@group phase179`
no doc-comment da classe nova, a convenção que **75 arquivos de teste** deste projeto já usam e
exatamente a origem das 47 pré-existentes. Fora de escopo, igual às waves 01 e 02.

⚠️ **JS não foi medido de novo** porque `resources/js` tem **diff vazio** nos dois ranges — nada de
JS pode ter regredido. A falha pré-existente `estrutura-grade-glide:123` segue **intocada**.

### ⚠️ E3 pegou um gate meu vazio por construção

A primeira rodada conferiu `app/Services/Mlb/MlPublicacaoService.php` e ele devolveu `diff=0`
**porque o arquivo NÃO EXISTE nesse caminho** — o real é
`app/Services/Mlb/Publicacao/MlPublicacaoService.php`. Diff vazio de caminho inexistente é vazio por
construção, e sem o `test -e` eu teria reportado "território do outro dev intacto" sem ter medido
nada. ⚠️ **As duas waves anteriores desta fase citam este caminho errado nas tabelas de gate
delas** — vale reconferir no 179-10. Refeito no caminho real: **diff=0 nos dois ranges**, com o
`Mesa/` confirmado por `ls` (tem arquivos, então o vazio é por intocado).

### ⚠️ E5 — o refinamento que o 179-10 precisa

O gate cru declarado para o fechamento (*"removidas = ZERO"* por
`git diff … | grep '^-' | grep assert`) é **vazio-ao-contrário** para este plano: **reaponte de valor
produz `-assert` por construção** — mudar `assertSame(1, …)` para `assertSame(2, …)` remove e
acrescenta a linha. Medido assim, o gate acusaria 8 "remoções" que não são remoções.

➡️ **Refinamento proposto ao 179-10:** enumerar cada `-assert` e exigir (a) um `+assert` correspondente
**no mesmo teste** e (b) `+assert > -assert` no total. Medido aqui:

| # | Linha `-assert` | Virou |
|---|---|---|
| 1 | `assertSame(1, $daAcao, 'a ação conta pelo status…')` | `assertSame(2, …)` com veto ao `1` |
| 2 | `assertNotSame($doAnel, $daAcao, …)` | `assertSame(0, $doAnel − $daAcao)` no Caso A **+** `assertNotSame` revivida no Caso B |
| 3 | `assertCount(2, $acao['itens'], '…não do número da linha')` | `assertCount(2, …)` com mensagem encurtada |
| 4-5 | `assertNotSame(` (2 linhas do bloco multilinha) | revivida no Caso B (a isca) |
| 6 | `assertSame(1, $daVisaoGeral, 'prontosParaFase2() não vê…')` | `assertSame(2, …)` com veto ao `1` |
| 7 | `assertSame(2, $daVisaoGeral, 'a regra original mudou…')` | `assertSame(3, …)` com veto ao `2` |
| 8 | `assertEqualsCanonicalizing(['PUB','PARC'], …)` | `['PUB','PARC','ERRO-COM-ANUNCIO']` |

**8 removidas · 39 acrescentadas · nenhuma asserção perdida · nenhum teste afrouxado.** O único
`assertNotSame` que "virou" `assertSame` é a própria divergência que a decisão do usuário mandou
fechar — e ele não morreu: foi para a isca do Caso B, onde continua provando que o serviço não alinha
nada por conta própria.

---

## O custo medido

| O que | Medido |
|---|---|
| Consultas dentro da fonte única | **0** (`assertSame(0, …)`, com isca `> 0` em `produtosParaTela()`) |
| Migrations | **0** (diff vazio em `database` e `database/migrations`, caminhos conferidos por `test -e`) |
| Pacotes instalados | **0** (diff vazio em `composer.json/lock` e `package.json/lock`) |
| Arquivos de `resources/js` tocados | **0** — este plano é 100% servidor e teste |
| Linhas removidas de `app/` | **26**, todas cópias substituídas por delegação, enumeradas |

---

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-04 Repudiation (número que não fecha) | **mitigado — era o alvo deste plano** | o anel, a ação e o modal concordam **sobre quais produtos**; o cabeçalho da ação bate com a sub-lista |
| T-179-01 Information Disclosure | mitigado | a fonte única **não consulta** (0 consultas medidas); recebe a linha já escopada |
| T-179-02 Tampering (tela preta de 07/10) | mitigado | o predicado devolve `bool`; nenhum campo novo no payload, nenhum objeto. Os gates de `array_keys` das waves 01/02 seguem verdes |
| T-179-SC | mitigado | nenhum pacote, diff vazio nos 4 manifests |

Nenhuma superfície nova: nenhum endpoint, nenhuma rota, nenhuma migration, nenhuma mudança de
autorização. **Nada de `## Threat Flags`.**

## Known Stubs

Nenhum. A regra é um predicado puro sobre a linha que `produtosParaTela()` já devolve — nenhum
valor constante, placeholder ou de exemplo foi introduzido.

---

## Perguntas em aberto para o usuário

1. ⚠️ **A `nota` do produto em `erro` dentro de "PRONTOS PARA FASE 2".** Ela diz *"Fase 1 publicada ·
   1 no ar"* — verdadeiro, mas **sem dizer que a última tentativa de publicação falhou**. Recusei pôr
   a marca de propósito (o erro já aparece na ação crítica da mesma tela, e mexer no texto amplia o
   diff para algo que você não pediu). Se quiser *"Fase 1 publicada · 1 no ar · última publicação
   falhou"*, é uma linha em cada um dos dois serviços.
2. ⚠️ **O número de produção segue não medido.** A classe é estreita, mas eu não sei se ela tem 1, 10
   ou 100 produtos na conta 459 — a leitura de produção está bloqueada nesta sessão. Se você quiser o
   número antes de deployar, é uma consulta que **você** precisa rodar (ou me liberar).
3. **O caminho errado de `MlPublicacaoService` nas tabelas de gate das waves 01 e 02.** As duas
   reportaram "território do outro dev intacto" conferindo
   `app/Services/Mlb/MlPublicacaoService.php`, que não existe. O arquivo real **está** intacto (eu
   conferi no caminho certo), mas o **gate** delas era decorativo. Vale uma nota no 179-10.
4. ⚠️ **Nada foi visto renderizado** — não há navegador neste ambiente e nenhuma tela foi aberta.
   Toda a conferência é de teste automatizado; a validação visual é sua, em produção, na conta 459.
   **Nada deployado, nada empurrado, nada no VPS.**

---

## Self-Check: PASSED

- `tests/Feature/Publicador/ProntoParaFase2Test.php` — FOUND (novo)
- `app/Services/Publicador/ProgramasPublicadorService.php` — FOUND (aditivo: 2 métodos + 1 delegação)
- `app/Services/Publicador/PainelVisaoGeralService.php` — FOUND (2 delegações)
- `app/Services/Publicador/JornadaDosProdutosService.php` — FOUND (2 delegações, `noAr()` privado removido)
- `app/Services/Publicador/NovaPublicacaoService.php` — FOUND (1 delegação + Exclusão 2 reescrita)
- `.planning/phases/179-visao-geral-cockpit/179-02B-PLAN.md` — FOUND
- `.planning/phases/179-visao-geral-cockpit/179-02B-SUMMARY.md` — FOUND
- commits `806013d3`, `ab0470e7`, `d26b1e1c`, `1dcca108` — FOUND em `git log`
- `grep -c` confirma **2** métodos novos (`prontoParaFase2`, `noAr`) na fonte única
- os 5 serviços idênticos ao commit depois das 13 mutações (`sha256` batendo, com o harness
  abortando a rodada se divergisse — e ele abortaria também se a árvore não estivesse pristina ANTES)
