---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 04
subsystem: publicador-aprovacao-painel
tags: [painel-de-revisao, coluna-de-preco, kpis, composicao-financeira, origem-por-linha, simulacao-nunca-salva, equilibrio, preco-alvo, oferta-irma, pior-caso-por-variacao, servidor-antes-do-render]
requires:
  - ComposicaoFinanceiraService::para / ::simular / ::comparacaoComAIrma / ::precoDoAlvo / ::margemMinimaComOrigem (Fase 186)
  - ComposicaoDaOferta (DTO) e LinhaFinanceira (DTO) (Fase 186)
  - BloqueiosDaOfertaService::para()['composicao'] (Fase 186)
  - VocabularioDoPortao::INCOMPLETO / ::CORRECOES (Fase 186)
  - PubAprovacaoComposicao::LINHAS / ::ANULAM_O_RESULTADO / ::ORIGENS_DA_LINHA / ::ROTULO_RESULTADO / ::ROTULO_MARGEM (Fase 186)
  - PubAprovacaoExcecao::LIBERA / ::libera() / ::fraseDeExibicao() / ::vigentesDa() (Fase 186)
  - LinhaDaOfertaService::percentualDaBarra / ::MARGEM_SEM_NUMERO / ::TETO_DA_BARRA / ::PIOR_CASO_* / ::FONTE_DA_MARGEM (Fase 187)
  - EstruturaPublicacao::LISTING_TYPES + EstruturaAnuncio::TIPOS
  - PainelDeRevisaoService (Fase 188, plano 02)
provides:
  - PrecoDoPainelService::para — a coluna de preço e resultado do painel E2, resolvida no servidor
  - PrecoDoPainelService::simular — a simulação PURA (nunca persistida, nunca enviada ao ML)
  - PrecoDoPainelService::sanear / ::digitavel — as regras puras e PÚBLICAS do `num()` e do `fmtIn()`
  - a chave `preco` do view-model do PainelDeRevisaoService (deixou de nascer `null`)
affects:
  - 188-06 (a ESCRITA: `POST …/preco/simular` + `registrarPrecoSalvo`) — as 9 chaves que ele lê daqui já existem
  - 188-09 (a tela da coluna de preço: o mapa chave→token é dele, inclusive a chave NOVA `clara`)
  - 188-12 (os modais de frete e de exceção) — aqui só sai o DADO, nunca o destino
  - 193 (a escrita no ML) — NÃO antecipada; o painel APROVA e ENFILEIRA
tech-stack:
  added: []
  patterns:
    - a composição é RECEBIDA pelo serviço da coluna, nunca recarregada (UMA leitura por oferta)
    - cor como CHAVE SEMÂNTICA no PHP (TOK-15), nunca hex
    - ordem de lista reordenada por CONSTANTE, com a premissa de entrada TRAVADA por asserção
    - chave de saída com nome específico (`andamento_da_irma`) para não casar a sonda larga da camada B
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php
    - tests/Feature/Publicador/Aprovacao/PrecoDoPainelServiceTest.php
    - tests/gates/contagens-188-04.txt
  modified:
    - app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
    - tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php
    - tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php
    - tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FID01.md
decisions:
  - "Decisão 9 (usuário, 14/10/2026): a frase `· com a tarifa real do Mercado Livre` é LIDA de `ComposicaoDaOferta:271`, nunca remontada. As DUAS margens continuam separadas de propósito — a outra é o parâmetro da Precificação, em `app/Services/Publicador/Fila/ResumoRapidoService.php:384`-`:391` (e NÃO em `.../Aprovacao/Fila/`)."
  - "Decisão 3 (usuário, 14/10/2026): a Embalagem nasce `Pendente` e NÃO anula; o resultado sai `estimadoPorBaixo` e prejuízo/margem DISPARAM sobre ele. O FRETE ausente, no mesmo cenário, ANULA — e é esse par que separa as duas regras."
  - "Decisão 2 (usuário, 15/10/2026): o preço manda o PIOR CASO e a tela NOMEIA a variação; com um preço só, SEM nota. `precoDeVariante`/`notaDoPreco` são LIDOS do DTO."
  - "`PrecoDoPainelService` é 5º parâmetro OBRIGATÓRIO do PainelDeRevisaoService: com default o container devolveria o DEFAULT (`Container.php:1327`-`:1331`) e a injeção seria uma ilusão. As DUAS construções manuais da suíte foram atualizadas."
  - "DIVERGÊNCIA (1): a 2ª frase de `:665` NÃO é reproduzida — ela é disclaimer da ficção do próprio protótipo e seria FALSA no produto. Governada pela `188-FID01.md` §3 + o precedente da 186."
  - "DIVERGÊNCIA (2): `frete_ausente` é PRECISO; o `freightMissing` de `:1143` é o `missing` INTEIRO. O destino NÃO é cravado — ele tem DOIS, e é do 188-02/09."
  - "DIVERGÊNCIA (3): o sinal de menos RECUSA a entrada do simulador. O `num()` de `:1048` o ENGOLE (`'-10,00'` volta `10.0`, MEDIDO); o `<behavior>` do plano e o T-188-30 pedem negativo no neutro, e a razão é de segurança."
  - "DEFEITO REAL consertado (Regra 1): `minimo_valor`/`teto` saíam como `float`, e `float` inteiro NÃO sobrevive ao `json_encode` (`12.0` volta `12`). O gate do 188-02 reprovou com razão; as duas chaves SAÍRAM e nenhum gate alheio foi afrouxado."
  - "CIRURGIA num gate alheio que reprovava pelo PRÓPRIO CONSERTO: `FotosDoPainelServiceTest` cravava `assertCount(4, $parametros)` + `$parametros[3]`. Reancorado em NOME + TIPO, com a contagem de asserções INALTERADA e REMEDIDA."
metrics:
  duration: "~3h"
  completed: 2026-10-19
---

# Fase 188 Plano 04: o SERVIDOR da coluna de preço e resultado Summary

A coluna de preço do painel E2 passou a existir no servidor: os **3 KPIs** com os rótulos **lidos**
do DTO, a composição das **7 linhas com origem e nota por linha** em ordem **explícita**, a
**simulação que nunca é salva**, equilíbrio e preço-alvo **só com todos os dados**, a **exceção** que
só registra, e a comparação **clicável** com a oferta irmã na ordem Clássico → Premium.

---

## PARTIDA e commits

**PARTIDA: `9becc6e7`** — capturada na **primeira ação** (`git rev-parse --short HEAD`), e usada
**só como referência de leitura**, nunca como extremo de range de território.

| commit | o que entregou |
|---|---|
| `f454a2e3` | **tarefa 1** — `PrecoDoPainelService`, os 3 KPIs, a composição com origem por linha, a ordem explícita das 7 linhas, e a chave `preco` ligada ao painel |
| `14fa6b32` | **tarefa 2** — os 12 casos (simulação sem gravar, atalhos, exceção, irmã, território, bijeção) + `contagens-188-04.txt` com as 4 contagens **medidas** |
| `9a3901b8` | a **§L do `188-FID01.md`** — 3 ponteiros deslocados, 20 confirmados, e 3 achados que não são de ponteiro |

⚠️ **A régua do território se provou AO VIVO, e mais forte que na wave 3:** o range
`9becc6e7..HEAD` tem **SEIS** commits e **TRÊS NÃO SÃO MEUS** — `44d67a46`, `2f7fd02c` e `68b8cc05`,
da **sessão paralela que planejou a Fase 189**, todos com o **MESMO `user.name`
(`MB.ECF-100376`)**. ➡️ **Metade do range é alheia**, e o filtro por **escopo do assunto**
(`/\(188-04\)/`) pegou **exatamente** os meus 3, **sem olhar autor**. Os três alheios tocam só
`.planning/coordenacao/` e `.planning/phases/189-…/` — nada do meu território.

---

## As 4 baselines RE-MEDIDAS (antes de qualquer edição, no HEAD `9becc6e7`)

| baseline | wave 3 mediu no fim | **esta wave, no INÍCIO** | veredicto | comando |
|---|---|---|---|---|
| **PHP** | 2522 / 22568 / **0** | **2522 / 22568 / 0** | **IGUAL** | `sh tests/gates/verificar-testes.sh --suite php` |
| **JS** | 5498 / 5497 pass / 1 fail / 0 skipped | **5498 / 5497 pass / 1 fail / 0 skipped** | **IGUAL** | `sh tests/gates/verificar-testes.sh --suite js` |
| **BUILD** | exit 0 · 62 s | **exit 0 · 63 s** | **IGUAL** (medido no FIM, após a mutação) | `npm run build` |
| **MANIFEST** | 495 / 202 · isca ausente | **495 entradas / 202 páginas · isca ausente · controle presente** | **IGUAL** | lido **pelo JSON** |

⚠️ **A régua do PHP é `0`, e `0` é o que foi medido.** As **duas falhas alheias** nomeadas por
SEMÂNTICA — `tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318` (**Fase 179**, em
produção, ⚠️ **FORA** de `Aprovacao/`) — estão **VERDES hoje**. **Não foram consertadas, não contam
como regressão, e `REF_PHP_FALHAS` NÃO foi criado.**
⚠️ **A única falha JS é a pré-existente** `tests/js/estrutura-grade-glide.test.js` (*"Características
secundárias nasce recolhido"*, Fase 87), aceita por `REF_JS_FALHAS=1`. **Não corrigida.**
⚠️ **A contagem de ASSERÇÕES do PHP não é invariante** (o 188-01 mediu **−5** com testes idênticos):
a comparação feita aqui é de **TESTES e FALHAS**.
⚠️ **O MANIFEST é lido pelo JSON, e a razão é medida:** o hash deste build saiu **com hífen** —
`assets/app--5yuaubs.js` — e `grep -E "app-[A-Za-z0-9]*\.js"` **não casaria**.
⚠️ **O protótipo não se moveu:** `design_handoff_publicador/Aprovacao.dc.html`, **1432** linhas,
`sha256 68624883ce35547e…`.

### As suítes **FINAIS** (depois da mutação e do build)

| suíte | início | **fim** | delta |
|---|---|---|---|
| **PHP** | 2522 / 22568 / 0 | **2534 / 23020 / 0** | **+12 testes** (os 12 métodos deste plano) · +452 asserções |
| **JS** | 5498 / 5497 / 1 fail / 0 skipped | **5498 / 5497 / 1 fail / 0 skipped** | **IGUAL** — e ⚠️ **`0 skipped`**, a camada de CSS **não pulou** |

⚠️ `2534 − 2522 = **+12**`, exatamente os 12 métodos novos — **nada foi perdido**.
⚠️ **`npm run build` foi rodado DEPOIS da mutação e ANTES de medir a suíte JS**, como a régua manda
(o `cp` da restauração atualiza o `mtime`, e com a fonte mais nova que o CSS construído a camada de
CSS **pula** e a suíte aparece com `skipped`). ➡️ **`0 skipped` confirma que não pulou.**

### ⚠️⚠️ UM ACHADO NOVO: **duas falhas que oscilam SEM mudança de código** — e NÃO são as da Fase 179

A **primeira** execução final da suíte PHP deu **2534 / 23015 / 2**, e as duas falhas **NÃO** eram as
duas alheias conhecidas (`CockpitDaContaTest:966`/`:1318`). Eram, nomeadas por **SEMÂNTICA**:

```
Tests\Feature\Publicador\SincronizaPortalCompletoTest > empresa fora do piloto e preenchida igual d10
    Failed asserting that 0 is identical to 3.   (tests/.../SincronizaPortalCompletoTest.php:257 — PubImagem::count())
Tests\Feature\Publicador\SincronizaPortalCompletoTest > sincronizar duas vezes e idempotente
    Failed asserting that 1 is identical to 3.   (tests/.../SincronizaPortalCompletoTest.php:293 — PubImagem::count())
```

⚠️ **A régua manda investigar** (*"QUALQUER OUTRO teste, ou mais de 2, é DA FASE"*), e foi
investigado — **CINCO experimentos, e os cinco apontam para fora deste plano**:

| experimento | resultado |
|---|---|
| `SincronizaPortalCompletoTest.php` **sozinho** | **14 passed / 77 assertions — VERDE** |
| `PrecoDoPainelServiceTest.php` **+** `SincronizaPortalCompletoTest.php` | **26 passed / 529 — VERDE** (o meu arquivo **não** o polui) |
| `tests/Feature/Phase196` **+** `SincronizaPortalCompletoTest.php` (o suspeito do `PubImagem::creating`) | **21 passed / 101 — VERDE** |
| **todo** `tests/Feature/Publicador` (1862 testes, inclui os meus 12 **e** o arquivo que falhou) | **1862 passed / 18626 — VERDE** |
| ⚠️⚠️ **a suíte COMPLETA de novo, na MESMA árvore, sem UMA linha de mudança** | **2534 / 23020 / `0` — VERDE** |

➡️ **Veredicto: é OSCILAÇÃO de composição/ordem da suíte, da MESMA família das duas da Fase 179 —
mas em OUTRO arquivo, e por isso fica DECLARADA aqui em vez de silenciada.**
⚠️ **E a sensibilidade de ordem está MEDIDA nas duas direções:** `tests/Unit` + aquele único arquivo
de Feature produziu **14 falhas** (faixa de faturamento, `service type`) que a suíte **completa não
tem**. ➡️ **Execução parcial não é comparável com a completa nesta árvore** — a composição muda o
resultado para os dois lados.
⚠️ **NADA foi consertado, NADA foi silenciado, `REF_PHP_FALHAS` NÃO foi criado.**
⚠️ **E repare na asserção:** `23015` na 1ª execução × `23020` na 2ª, **com os mesmos 2534 testes** —
é exatamente a régua de que **a contagem de asserções do PHP não é invariante** (os 2 testes que
abortaram contribuíram 5 asserções a menos). ➡️ **Compare TESTES e FALHAS.**

➡️ **Item para o 188-13 / fechamento:** `tests/Feature/Publicador/SincronizaPortalCompletoTest.php:257`
e `:293` entram na lista de falhas ambientais **conhecidas** da fase, ao lado das duas da Fase 179 —
com a ressalva de que, diferente daquelas, **estas não foram vistas antes** e merecem uma quick task
própria se voltarem (o suspeito mais provável é a vizinhança de `PubImagem::creating` registrado em
teste: há **3** arquivos que registram listener de modelo e **só um** deles chama
`flushEventListeners()` — `tests/Feature/Phase196/DiscoDasImagensNoPublicadorTest.php:150`, num
`finally`; `SincronizaPortalCompletoTest.php:331`, `SincronizaPortalTest.php:139` e
`SincronizaPortalAgrupamentoTest.php:411` **não chamam**). ⚠️ **Isto é HIPÓTESE, não conclusão** — os
dois experimentos que a testariam diretamente saíram VERDES.

---

## Os 3 KPIs, com o literal de cada (`:642`-`:646`)

| # | chave | rótulo | de onde vem o rótulo | valor | cores |
|---|---|---|---|---|---|
| 1 | `preco_de_venda` | **`Preço de venda`** | constante própria (`:642`; o MESMO texto de `:655`, **uma** constante) | `brl(preço)`, ou **`—`** com preço `<= 0` | `texto` |
| 2 | `resultado` | **`Resultado estimado por venda`** | ⚠️ **LIDO** de `ComposicaoDaOferta::rotuloDoResultado()` | `Incompleto` (LIDO de `VocabularioDoPortao::INCOMPLETO`) ou `brl(resultado)` | **TRÊS**: `ambar` (incompleto) · `vermelho` (`< 0`) · `texto` |
| 3 | `margem` | **`Margem sobre o preço`** (`:644`) + `rotulo_do_conceito` = **`Margem de contribuição estimada`** (LIDO de `rotuloDaMargem()`) | os dois viajam, são textos distintos de propósito | `pct(margem)` ou **`—`** (LIDO de `LinhaDaOfertaService::MARGEM_SEM_NUMERO`) | `neutro`/`vermelho`/`ambar`/`texto` |

⚠️ **As três cores do resultado são TRÊS**, provado por `array_unique` de 3 em três fixtures que
**discriminam** (positivo, prejuízo, incompleto) — e **cor nunca é o único sinal**: há asserção
de que há **texto** ao lado nos três.
⚠️ **`<= 0` é AUSENTE, não zero:** `R$ 0,00` afirmaria que o produto é de graça. É a MESMA correção
já declarada em produção por `LinhaDaOfertaService::preco()`, e a mesma condição de
`ComposicaoFinanceiraService:228`.

### A barra da margem — provada por **FÓRMULA**, com **DOIS** mínimos

`LinhaDaOfertaService::percentualDaBarra()` é **CHAMADA**, nunca copiada — ela é `public static`
exatamente para isto (*"duas cópias divergiriam, e a barra passaria a discordar do próprio traço"*).

- mínimo **12** ⇒ traço em **`30%`** (`12/40`);
- mínimo **20** ⇒ traço em **`50%`** — ⚠️ **com o `30%` cravado, esta linha reprova** (e a mutação
  (iv) provou que reprova);
- clamp nas **DUAS** pontas: margem **negativa** ⇒ `0%` (fixture com margem `< 0`); margem **> 40%**
  ⇒ `100%` (fixture com margem acima do teto).

⚠️ O `left:30%` de `:645` é **CONSEQUÊNCIA** de `MIN = 12` (`:914`, confirmado por `sed`), nunca
literal.

---

## A composição com **origem por linha** — e a prova de ORDEM EXPLÍCITA das 7 linhas

As 7 linhas saem com `chave`, `rotulo`, `detalhe`, `valor`, `valor_cor`, `origem`, `origem_rotulo`,
`origem_cor`, `origem_borda`, `nota`, `pendente`, `informativa`.

### ⚠️⚠️ A ORDEM É EXPLÍCITA — a lição da wave 188-03 aplicada

`PrecoDoPainelService::ORDEM_DAS_LINHAS` **é** `array_keys(PubAprovacaoComposicao::LINHAS)`, e há
asserção disso (**uma lista só**: duas divergiriam, e a divergente seria a que a pessoa lê).

⚠️ **A lição é literal:** a wave 3 mediu que `PubAprovacaoImagem::alertas()` não tinha `orderBy` e a
ordem vinha do **ÍNDICE** — MariaDB e SQLite resolvem diferente e **nenhuma sonda pega**, porque
texto e número **concordam entre si** apontando para o lugar errado.

➡️ **O que foi MEDIDO aqui:** a composição vem de um **array literal**
(`ComposicaoFinanceiraService:239`-`:247`), **não** de consulta — então a reordenação do painel é
**inobservável hoje**. ⚠️ **Inobservável não se descarta, se TRAVA:** há asserção de que
`$composicao->linhasDaConta()` **chega** exatamente na ordem da constante, com a mensagem *"a ordem
de ENTRADA deixou de ser a da constante: a reordenação do painel passou a ser OBSERVÁVEL e tem de ser
medida por caso próprio"*. O dia em que a 186 mudar a ordem, o gate reprova **no lugar certo**.
⚠️ E linha que chegue **fora** da ordem conhecida **não desaparece**: vai para o fim (sumir com um
custo da conta é pior que mostrá-lo fora de ordem — a soma do rodapé continuaria contando-o).
⚠️ A mutação **(vii)** (inverter a ordem) **MORREU**.

### As três origens, com **seis** cores distintas aos pares (`:1125`)

| origem | rótulo (LIDO) | cor do texto | cor da borda |
|---|---|---|---|
| `confirmado` | `Confirmado` | `verde` (#4ADE80) | `verde_fraca` (rgba(34,197,94,0.35)) |
| `estimado` | `Estimado` | `clara` (#D4D4D8) | `trilha` (#3F3F46) |
| `pendente` | `Pendente` | `vermelho` (#F87171) | `vermelha_fraca` (rgba(239,68,68,0.45)) |

⚠️ **Duas iguais passariam num teste de existência** — há asserção de que os 3 textos são distintos,
as 3 bordas são distintas, **e** texto ≠ borda em cada origem.

### O valor com o **SINAL INVERTIDO** (`:1126`), nas quatro formas

- custo **positivo** ⇒ exibido **negativo**: `"− R$ 200,00"`, com o sinal medido em **BYTES**
  (`e28892` = **U+2212 MINUS SIGN**, e a isca prova que o hífen-menos é `2d`);
- **zero medido** ⇒ `"R$ 0,00"`, **sem sinal**, em `neutro` — e a fixture REAL do ML traz
  `fixed_fee: 0`, `Confirmado`;
- **`null`** ⇒ o **TEXTO** `Pendente` em `vermelho`, **LIDO** de `rotuloDaOrigem()` (é o MESMO que o
  selo mostra; duas redações divergiriam);
- **milhar** ⇒ `"− R$ 1.234,56"`, com o ponto de pt-BR.

⚠️ **A nota NOMEIA a fonte e NUNCA é vazia** (DADO-13 / VOC-04), provado sobre as 7 linhas **mais** a
informativa, com a nota do custo citando *"Precificação do Portal"*. `LinhaFinanceira::REGRA_NOTA_VAZIA`
= `DADO-13-NOTA-VAZIA` prova que vazia é **erro**, e a asserção coleta os infratores.
⚠️ A nota do **combo** (FIN-14) é a da 186: `3 un. × R$ 50,00 = R$ 150,00` — e com 1 unidade o texto
é outro (`1 un. × R$ 200,00`), de propósito.
⚠️ A **informativa** de FIN-08 (`Frete pago pelo comprador`) aparece **separada** e há asserção de
que ela **não** está nas linhas da conta.

---

## A Embalagem `Pendente` que **NÃO anula**, e o resultado **estimado por baixo**

Provado nos **DOIS sentidos, na MESMA fixture**:

| cenário | `incompleto` | `estimadoPorBaixo` | KPI 2 | `frete_ausente` |
|---|---|---|---|---|
| embalagem pendente + frete presente | **`false`** | **`true`** | o número (**existe**) | `false` |
| **frete ausente** (a isca que separa) | **`true`** | — | **`Incompleto`** | **`true`** |
| **custo ausente** (a 2ª isca) | **`true`** | — | **`Incompleto`** | **`false`** ⚠️ |

⚠️ **Sem a exceção, 100% das ofertas diriam "Incompleto" e nada seria aprovável.** E
**prejuízo/margem DISPARAM sobre o estimado**: há fixture com `estimadoPorBaixo = true` **e**
resultado negativo, com a nota dizendo `prejuízo por venda` **e** `sem o custo de embalagem` — é
segurança, não detalhe (o estimado é **otimista**, logo a margem **real** é menor que a mostrada, e
aprovar já publica **sem desfazer**).
⚠️ **A lista das CINCO que anulam está travada por asserção** (`['custo','comissao','tarifa_fixa','frete','imposto']`),
com `assertNotContains` de `embalagem` e de `outros`.
⚠️ **Um serviço que tratasse "qualquer pendência" como `Incompleto` passaria em METADE dos casos** —
é por isso que o par é medido nos dois sentidos. ⚠️ As mutações **(i)** (embalagem anula) e **(ii)**
(frete não anula) **MORRERAM**.

### "Outros custos" nasce **R$ 0,00 `Confirmado`**

Provado: `ehPendente()` é `false`, `valor` é `0.0`, `origem` é `confirmado`, o valor exibido é
`R$ 0,00` **sem sinal** e em `neutro`. ⚠️ `0` **MEDIDO** ≠ ausente.

### Todo OUTRO custo ausente **NÃO vira zero**

`custo`, `comissao`, `tarifa_fixa`, `frete` e `imposto` ausentes ⇒ `incompleto`, resultado `null`,
KPI `Incompleto`, margem `—`, **nenhum atalho** e a frase do indisponível. ⚠️ **Zero afirmaria que é
de graça.**

---

## O **PIOR CASO** nomeando a variação — e **sem nota** com um preço só

⚠️ **LIDOS do DTO, nunca recalculados:** `$precoDeVariante` e `$notaDoPreco`.

- **um preço só** (o caso comum) ⇒ `nota` **`null`**, `variante` **`null`**, e há asserção de que a
  coluna **não inventa** nota onde o DTO não tem;
- **4 preços ativos** ⇒ a nota **NOMEIA** quantos (`pior caso entre 4`) **e** a variação
  (`cor:preto|tamanho:G`);
- `precoDoAlvo()` devolve o **MENOR** preço ativo — e há asserção da forma
  (`['preco','variante','ativos']`).

⚠️⚠️ **DIVERGÊNCIA DE TEXTO DECLARADA, e NÃO consertada aqui:**
`ComposicaoDaOferta.php:286` escreve **`'pior caso entre '.$precosAtivos.' variantes'`** e
`LinhaDaOfertaService.php:195` escreve **`PIOR_CASO_SUFIXO = ' variações · '`** — as **DUAS** estão
em **produção** e as duas são território **SOMENTE LEITURA**. A `188-FID01.md` §D e a prosa dos
planos escrevem *"pior caso entre N **variações**"*. ➡️ O plano manda **LER o DTO**, e é o que foi
feito; **inventar uma terceira redação seria o defeito**. Há asserção travando as duas formas, para
quem decidir unificar (não é decisão desta wave).

---

## A frase da **tarifa real do ML** — LIDA de `ComposicaoDaOferta:271`, não remontada

⚠️ **CONFIRMADO por `sed`:** `ComposicaoDaOferta.php:271` é
`$frase .= ' · com a tarifa real do Mercado Livre';`.

`kpis[2].nota_da_margem` é **`$composicao->notaDaMargem`**, e há asserção de **igualdade com o DTO**
(*"a nota da margem tem de ser LIDA do DTO"*) **mais** a asserção da substring. ⚠️ **Deixar a margem
sem texto foi OFERECIDO e RECUSADO** pelo usuário — sem o texto a divergência entre as duas margens
chega à pessoa como **bug de dado**.

### As **DUAS** margens, NÃO unificadas — com o caminho certo

| onde | o que manda | arquivo |
|---|---|---|
| **portão / editor** (aqui) | a **tarifa REAL do ML** (`sale_fee_amount`) | `ComposicaoFinanceiraService` → `ComposicaoDaOferta:271` |
| **visão rápida do lote** | o **parâmetro da Precificação** | ⚠️ **`app/Services/Publicador/Fila/ResumoRapidoService.php:384`-`:391`** |

⚠️⚠️ **O caminho NÃO é `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php`** — e
confundi-lo com `FilaDeAprovacaoService` **APAGARIA** a decisão 9. Há **asserção de que o caminho
certo está escrito no docblock**, `assertFileExists` nele (**ANTI-FANTASMA**), e
`assertStringNotContainsString` do caminho fantasma. ⚠️ E há asserção de que `margem_contribuicao`
(o parâmetro) **não** aparece neste serviço — são perguntas **diferentes**.
⚠️ O docblock de `EditorRascunhoService:370`-`:372` diz **"diferente DE PROPÓSITO… não unificar"**.

---

## A SIMULAÇÃO — o selo literal, nunca persistida, nunca enviada, só salvável em `live`

**O selo é literal:** `PrecoDoPainelService::SIMULACAO_SELO` = **`SIMULAÇÃO · NÃO SALVA`** (`:672`),
e há asserção byte a byte.

| o que foi provado | como |
|---|---|
| **recalcula** resultado, margem e **delta vs. o salvo** | `+ R$ … vs. salvo` (verde) e `− R$ … vs. salvo` (vermelho, com **U+2212**) |
| o selo **só** existe com o digitado **≠** salvo | tolerância **literal** `0,001` (`:1129`): igual ⇒ neutro · 1 **décimo** de centavo ⇒ ainda neutro · 1 centavo ⇒ simulação. **Isca nas duas direções** |
| **NUNCA persistida** | `PubAprovacaoComposicao::count()` **inalterado** (nada congelado) + `versao` **inalterada** + gate de ausência de **toda** forma de escrita |
| **NUNCA enviada ao ML** | **ausência de requisição**: as ÚNICAS chamadas HTTP são **leituras de tarifa**; `assertSame([], $escritas)`. ⚠️ **COM ISCA**: `assertGreaterThan(0, requisicoesDeTarifa())` prova que o espião **não está cego** |
| **só salvável em `live`** | `pode_salvar` nos **DOIS** eixos: sem simulação ativa ⇒ `false`; nos **três** andamentos fora do `live` (`aprovado`, `publicando`, `preparando`) ⇒ `false`, com os infratores **coletados** |
| **salvar incrementa a versão** | ⚠️ **é do plano 188-06** — `registrarPrecoSalvo()` + `registrarMudanca(PRECO_SALVO)`. A **fronteira está DECLARADA no docblock** e há asserção de que `188-06`, `registrarPrecoSalvo`, `Fase 193` e `ResumoRapidoService` **estão lá** |
| **nenhum `congelar()`** | `assertStringNotContainsString('congelar', ...)` ⚠️ **com ISCA que prova o SUJEITO** (`public function congelar(` existe em `ComposicaoFinanceiraService`) |

⚠️ **A sonda de escrita ancora no OPERADOR DE CHAMADA, não na palavra** — `salvar` é legítimo em
`pode_salvar` e em `SIMULACAO_SALVAR`. **9 iscas que ela DEVE pegar** (incluindo o `updateOrCreate`
de **dois arrays**, cujo limite é o `;` e **não** o `]`) e **5 que ela NÃO pode pegar**.
⚠️ **T-188-33:** no máximo **UMA** requisição por trio `(categoria, tipo, preço em centavos)` —
provado contando por trio e coletando os repetidos (o cache de
`ComposicaoFinanceiraService::tarifaDoMl()` é por esse trio).
⚠️ **14 preços inválidos** caem no neutro **sem exceção**: `0`, `'0'`, `'R$ 0,00'`, `-10.0`,
`'-10,00'`, `''`, `'   '`, `'de graça'`, `[]`, `['100']`, `null`, `true`, `stdClass`, `'NaN'` — com
**guarda de tipo ANTES de qualquer cast** (`(float) []` é a família do `(int) []` que já foi **500**
nesta tela) e **isca do que o saneador TEM de aceitar**.

### ⚠️⚠️ De quem é o **TRANSPORTE** da simulação: **do plano 188-06** — CONFERIDO

Era BLOCKER do verificador. **Conferido nos `files_modified`:** o `188-06` toca
`routes/mlb_anuncios.php`, `MlbPublicadorAprovacaoController.php`, `PainelDeRevisaoService.php` **e
`PrecoDoPainelService.php`**, e a seção **B1.3** dele já prescreve
**`POST …/aprovacao/{oferta}/preco/simular`** (⚠️ **POST e não GET**, porque o valor digitado não é
bookmarkável), com `throttle:120,1` e o id próprio `simular-sem-gravar`.

➡️ **O contrato entre as waves está PROVADO por asserção:** as **9 chaves** que o `188-06:159`-`:161`
declarou ler daqui — `resultado`, `resultado_cor`, `margem`, `margem_cor`, `delta`, `delta_cor`,
`simulando`, `pode_salvar`, `versao` — existem **no estado simulado E no estado neutro** (forma
diferente quebraria o cliente em metade das respostas). **Não há lacuna de transporte; nada a
devolver.**

---

## A oferta **IRMÃ** — clicável, na ordem Clássico → Premium

`ComposicaoFinanceiraService::comparacaoComAIrma()` é **CHAMADO** (ele parte do **mesmo
`rascunho_id`**, e é isso que impede o `href` de apontar para outra conta; o `?oferta=` ainda passa
pelo recorte de conta do 188-02).

- a grade de `:688`-`:692` traz as **DUAS** células, com a atual marcada (`atual: true`) e **sem
  `href`** (`:1294`: `x.id !== o.id`);
- a **ordem** vem de `array_keys(EstruturaPublicacao::LISTING_TYPES)` — a **constante**, nunca
  `'gold_pro'` à mão nem `orderBy('id')`;
- ⚠️ **FIXTURE QUE DISCRIMINA:** o **Premium é criado PRIMEIRO** (id **MENOR**, com asserção
  provando-o), então `orderBy('id')` ou a ordem de criação **passariam por acidente**. A mutação
  **(ix)** (ordenar por id) **MORREU**;
- a irmã traz `rotulo`, `preco`, `resultado`/`resultado_cor`, `margem`/`margem_cor`,
  `andamento_da_irma`, `rotulo_do_andamento` e `href` = `?oferta=<id>`;
- **sem irmã** ⇒ `irma` é **`null`** e a grade tem **UMA** célula (nunca zeros, que a tela leria como
  verdade);
- ⚠️ `irma` é **DERIVADA** de `comparacao` (há asserção de identidade) — **as duas chaves nunca podem
  discordar**.

⚠️ **O clique é da tela**; aqui sai o `href`.

---

## `PrecoDoPainelService` virou argumento **OBRIGATÓRIO** — e as construções manuais atualizadas

**É o 5º parâmetro, sem default, não anulável.** ⚠️ Injeção com default é **ILUSÃO**:
`Container::resolveClass()` (`vendor/laravel/framework/src/Illuminate/Container/Container.php:1327`-`:1331`)
devolve o **DEFAULT** quando ele existe e a classe não está explicitamente ligada.

**As DUAS construções manuais que existem na suíte, atualizadas:**

| arquivo:linha | o que é |
|---|---|
| `tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php:1269` (o arg novo em **`:1274`**) | o espião de custo do 188-02 |
| `tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php:203` (o arg novo em **`:208`**) | o espião da rota do 188-02 |

⚠️ **Conferido por `grep` em `app/`, `tests/` e `routes/`: são EXATAMENTE essas duas.**
⚠️ **Nenhuma contagem de asserção de id alheio mudou** — `contagens-188-02.txt` segue valendo, e isso
foi **remedido**, não presumido. Há asserção própria (`ReflectionMethod`) de que o construtor tem **5
parâmetros obrigatórios**, de que o **5º** é `PrecoDoPainelService`, e de que o container injeta a
instância **real**.

---

## Os gates: ids, bijeção, homônimo

| id | métodos | tests | assertions |
|---|---|---|---|
| `kpis-e-incompleto` | 3 | 3 | **101** |
| `composicao-e-origem` | 3 | 3 | **89** |
| `simulacao-sem-salvar` | 3 | 3 | **77** |
| `atalhos-excecao-irma` | 3 | 3 | **185** |

⚠️ **4 ids, 12 métodos, NENHUM com mais de TRÊS.** ⚠️ **ZERO id órfão** e **zero método sem id** —
bijeção **por script, nos DOIS sentidos**, com uma asserção **coletando** os infratores em cada
direção. ⚠️ **A terceira bijeção (id ↔ `<verify>`)**: os 4 ids aparecem, todos, nos `<verify>` do
plano (2 na tarefa 1, 2 na tarefa 2) — está escrito no cabeçalho do manifesto.
⚠️ **Todas as contagens foram MEDIDAS**, uma invocação por id, com o ANSI tirado **antes** de ler a
linha `Tests:`. ⚠️ **Nenhuma foi chutada** — e duas que eu havia escrito por estimativa (`103` e
`193`) foram **corrigidas pela medição** para `101` e `185`.

### HOMÔNIMO — por script, nas DUAS direções

```
ARQUIVOS=1213 METODOS=7757 MEUS=12 HOMONIMOS=0
  direcao 1 (alheio que CONTEM o meu nome): D1=0
  direcao 2 (o meu nome CONTEM um alheio): D2=0
  isca: 'test_territorio_e_ausencias_do_plano' aparece em 7 metodos REAIS
        'test_bijecao_do_manifesto'            aparece em 9 metodos REAIS
  ISCA_TOTAL=16 (0 seria ACHADO: a sonda estaria cega)
```

⚠️ **A isca acusa 16 métodos REAIS sem o sufixo** — prova de que `_188_04` **não é zelo**: o
`--filter` do PHPUnit é regex **NÃO ANCORADO** e as Fases 186 **e** 187 estão **em produção**. Há
ainda asserções de que os **4** filtros não casam os homônimos `_188_02`, `_188_03` e `_187_08`
(3 × 4 = 12 asserções), e de que o homônimo da wave 3 **existe no disco** (a isca tem sujeito).

### Os ids das waves 1-3 RE-RODADOS — **17**, todos verdes

| manifesto | ids | resultado |
|---|---|---|
| `contagens-188-01.txt` | `baselines-medidas` (1/7) · `fid01-remedida` (1/13) · `tokens-e-tamanhos` (js 9) · `vocabulario-tamanhos` (js 548) | **4 verdes, contagens INALTERADAS** |
| `contagens-188-02.txt` | os **9** ids | **9 verdes, contagens INALTERADAS** (⚠️ `navegacao-e-payload` reprovou primeiro — ver abaixo) |
| `contagens-188-03.txt` | os **4** ids | **4 verdes, contagens INALTERADAS** (⚠️ `sem-alerta-e-contagem` reprovou primeiro — ver abaixo) |

⚠️ **`--autoteste`: 20 casos, 0 divergência** — colado abaixo.

---

## `--autoteste` — a saída COLADA

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

---

## DOIS gates alheios reprovaram — e nos DOIS o conserto certo era do lado certo

### (A) `navegacao-e-payload` (188-02) — **DEFEITO REAL MEU** (Regra 1)

```
o payload não sobrevive ao `json_encode` — algo nele não é dado
-    'minimo_valor' => 12.0,   'teto' => 40.0,
+    'minimo_valor' => 12,     'teto' => 40,
```

⚠️ **`float` de valor inteiro NÃO sobrevive ao `json_encode`/`json_decode`.** O payload do painel
**atravessa o Inertia**, então o gate do 188-02 está **CERTO** e a primeira redação da minha barra
estava **ERRADA**.
➡️ **As duas chaves SAÍRAM** (a tela não precisa delas: o traço é `minimo`, a largura é `largura`, e
o percentual escrito já vem em `nota_da_margem` e no rótulo do atalho). ⚠️ **O gate alheio NÃO foi
afrouxado e a contagem dele NÃO mudou** (2 / 50). E o lado de cá ganhou asserção própria do
round-trip, **com isca** (`assertNotSame(['x' => 12.0], json_decode(json_encode(...)))`).

### (B) `sem-alerta-e-contagem` (188-03) — **gate que reprovava pelo PRÓPRIO CONSERTO**

```
o construtor do painel ganhou ou perdeu dependência
Failed asserting that actual size 5 matches expected size 4.
```

`FotosDoPainelServiceTest` cravava **`assertCount(4, $parametros)`** + **`$parametros[3]`** — ou seja,
uma âncora de **CONTAGEM e de POSIÇÃO**. Mas o invariante **não é** *"o construtor tem exatamente 4
dependências"*: **cada wave do painel acrescenta a sua coluna como argumento obrigatório** — é o
conserto que a própria wave 3 mediu e declarou.
➡️ **Número que muda quando o trabalho certo é feito** faz o executor seguinte *"ajustá-lo para
passar"* (`feedback_gate_que_reprova_pelo_proprio_conserto`). ⚠️ **O conserto certo era DESTE lado
(o do gate), não do meu** — o briefing desta wave **PRESCREVE** o argumento obrigatório.
➡️ Reancorado em **NOME + TIPO** (`assertArrayHasKey('fotos', $porNome)` + `getType()?->getName()`),
mantendo *sem default* e *não anulável*. ⚠️ **As duas asserções viraram duas**, a contagem do id
**NÃO mudou** (3 / 48) e isso foi **REMEDIDO**, não presumido.

⚠️ **O que NÃO foi feito em nenhum dos dois casos:** afrouxar o gate alheio, renomear chave para
fugir da sonda, ou levantar contagem de manifesto alheio.

---

## A MUTAÇÃO — `mutar-188-04.sh`, **10 mutações, 10 MORTAS**

⚠️ **O nome carrega o plano, e NÃO é zelo** (learnings §4c.3): o scratchpad é **COMPARTILHADO** entre
subagentes paralelos, e na Fase 187 um `mutar.sh` genérico foi **SOBRESCRITO** pela outra wave,
obrigando a **refazer a campanha inteira**. `sha256` do script, colado antes do veredicto:
`38e227ed14d112dd…`.

```
PRISTINO sha256=3c56e6235b9bcfc252142176e12ef0c564340fb7bf488cf46868f537738a5325 (== HEAD)
MUT [i-embalagem-anula]        linha 359 -> MORREU (reprovou em kpis-e-incompleto)
MUT [ii-frete-nao-anula]       linha 752 -> MORREU (reprovou em kpis-e-incompleto)
MUT [iii-sem-clamp-inferior]   linha 601 -> MORREU (reprovou em kpis-e-incompleto)
MUT [iv-30-cravado]            linha 617 -> MORREU (reprovou em kpis-e-incompleto)
MUT [v-pode-salvar-sem-live]   linha 469 -> MORREU (reprovou em simulacao-sem-salvar)
MUT [vi-tolerancia-zero]       linha 428 -> MORREU (reprovou em simulacao-sem-salvar)
MUT [vii-sem-ordem-explicita]  linha 680 -> MORREU (reprovou em composicao-e-origem)
MUT [viii-menos-passa]         linha 521 -> MORREU (reprovou em simulacao-sem-salvar)
MUT [ix-irma-por-id]           linha 970 -> MORREU (reprovou em atalhos-excecao-irma)
MUT [x-sem-ceil-menos-01]      linha 878 -> MORREU (reprovou em atalhos-excecao-irma)
RESTAURADO sha256=3c56e6235b9bcfc252142176e12ef0c564340fb7bf488cf46868f537738a5325
OK: identico ao pristino
```

As **6** pedidas pelo `<verification>` são (i)…(vi); as **4** próprias são (vii) a ordem explícita,
(viii) o menos passando no saneador, (ix) a irmã por id e (x) o `ceil − 0,1`.
⚠️ **Receita seguida:** trabalho **commitado ANTES**; `cp` para
`copia-188-04-PrecoDoPainelService.php`; ocorrência **travada em 1** (âncoras todas de **uma linha**
— âncora multi-linha **não é travável por `grep -c`**, que conta linhas); **linha conferida depois**;
restauração **DA CÓPIA** em `trap EXIT`; `sha256` contra `git show HEAD:` **com `tr -d '\r'` nos dois
lados**; e a prova de mutação por **DIVERGÊNCIA de `sha256`** (a guarda *"a âncora antiga sumiu"*
abortaria numa réplica que contém a âncora). ⚠️ **`git checkout` NUNCA.**

### ⚠️⚠️ A SOBREVIVENTE da primeira rodada — **INVESTIGADA, e era o SCRIPT**

Na primeira rodada **(ii) `ii-frete-nao-anula` SOBREVIVEU**. A investigação achou que a mutação era
um **NO-OP**: ela **acrescentava** um método morto (`fretePendenteDesligada`) e deixava o
`fretePendente()` **real INTACTO** — ou seja, o `sha256` divergiu (o arquivo mudou) mas o
**comportamento não**.
⚠️ **"Mutação que NÃO reprova quando deveria também é informação"** — e aqui a informação era sobre
**o script**, não sobre a asserção. Corrigida para trocar o **CORPO** do método
(`return … ehPendente() === true;` ⇒ `return false;`), ela **MORREU**. ⚠️ **A sobrevivente não foi
presumida equivalente em nenhum momento.**

---

## Os ponteiros `:1NNN` RECONFIRMADOS — **3 deslocados**, e o número certo

| o que a prosa do plano / da §D dizia | **real (medido por `sed`)** | o que está DE FATO na linha citada |
|---|---|---|
| `onSimTarget` com `ceil(target) − 0.1` em **`:1139`** | **`:1140`** | `:1139` é o **`onSimBE`** — o atalho de **EQUILÍBRIO**, que **não** leva o ajuste de 10 centavos |
| `canApply = simOn && inf.live` em **`:1144`** | **`:1142`** | `:1144` é um **`}` sozinho** (o fecho do `finVm`) |
| a ordem das irmãs (`sort` por modalidade) em **`:1292`** | **`:1294`** | `:1292` é o **`.map()` das IMAGENS** — assunto de **outra** coluna |

⚠️ **É o MESMO padrão que a §J descreveu:** *"o ponteiro errado cai em cima de outro assunto real, e
quem o ler sem medir acha que está no lugar certo"*. É a **terceira** confirmação da lição (§J pela
wave 2, §K pela wave 3, **§L** por esta).

**E 20 ponteiros CONFIRMADOS por `sed`:** `:914` · `:1044` · `:1045` · `:1048` · `:1049` · `:1125` ·
`:1126` · `:1127` · `:1128` · `:1129` · `:1130` · `:1132` · `:1133` · `:1134` · `:1135` · `:1136` ·
`:1143` · `:1169` · `:1310`, mais todo o HTML do §D (`:640`-`:646`, `:649`-`:651`, `:654`-`:667`,
`:669`-`:685`, `:687`-`:692`). ⚠️ `ComposicaoDaOferta.php:271` também **confirmado por `sed`**.

➡️ **Tudo isto está escrito na `188-FID01.md` §L** (commit `9a3901b8`), e o gate `fid01-remedida`
segue **verde com a contagem original** (1 / 13).

---

## As DIVERGÊNCIAS, com `arquivo:linha`

| # | o que o protótipo faz | o que o produto faz | por quê, e quem já decidiu |
|---|---|---|---|
| **(1)** | `:665` tem **duas** frases: *"Frete pago pelo comprador não entra no resultado. **Taxas de comissão são exemplos configuráveis.**"* | **só a primeira**, byte a byte | a 2ª é **disclaimer da ficção do próprio protótipo** (`:1064` *"taxa de exemplo"*, `src:'est'`); no produto a comissão é a **tarifa REAL** (decisão 9), logo seria a tela **afirmando o que não é**. ⚠️ **Não é decisão nova:** `188-FID01.md` §3 governa, e a **186 já fez a mesma tradução** em `ComposicaoFinanceiraService::linhaInformativaDoFreteDoComprador()`, **em produção** |
| **(2)** | `freightMissing: f.missing` (`:1143`) — o `missing` **INTEIRO** | `incompleto` **e** `frete_ausente`, **separados** | mostrar *"Informar frete confirmado"* quando falta o **custo** manda a pessoa ao lugar errado — **a 186 já consertou essa classe** em `BloqueiosDaOfertaService::freteOuCusto()` (*"dizer 'frete' quando o que falta é o custo do produto seria a tela afirmando o que não é"*). ⚠️ **O destino NÃO é cravado:** `frete_ausente` tem **DOIS**, resolvidos por `PainelDeRevisaoService::destino()`. Há asserção de ausência de `MODAL_FRETE`/`MODAL_AJUSTES`/`MODAL_EXCECAO`/`DESTINO_MODAL` no serviço |
| **(3)** | `num()` (`:1048`) tira o `-` **ANTES** do `> 0`, então `'-10,00'` volta **`10.0`** | o menos **RECUSA** a entrada (`-` **e** U+2212) | **MEDIDO:** o caso reprovou com *"preço inválido virou simulação: negativo_texto"*. O `<behavior>` do plano **e** o **T-188-30** pedem *negativo no neutro*, e a razão é de **segurança**: trocar `-10,00` por `10,00` em silêncio simularia um preço **que a pessoa não digitou** e ofereceria *"Salvar no anúncio"* sobre ele — e salvar troca o preço de verdade, **sem desfazer**. ⚠️ **Validação de entrada numa fronteira de confiança**, não fidelidade de rótulo (§3 dá ao protótipo a FÓRMULA, os RÓTULOS e a ORDEM). ⚠️ O **`+` continua sendo tirado** — ele não muda o valor |
| **(4)** | — | `clara` é **chave de cor NOVA** | **MEDIDO nos dois pontos:** a barra do **PAINEL** é `#D4D4D8` (`:1132`) e a da **FILA** é `#A1A1AA` (`:1246`) — o protótipo os escreve **diferentes de propósito**, e `LinhaDaOfertaService::corDaBarra()` devolve `COR_SUAVE`, **certo para a fila**. ⚠️ Há asserção de que as duas chaves **não colapsaram**. ⚠️ **Um gate que exigisse a MESMA chave nos dois reprovaria código certo** |
| **(5)** | — | `ComposicaoDaOferta.php:286` diz **"variantes"**, `LinhaDaOfertaService.php:195` diz **" variações · "** | as **duas** estão em **produção** e as duas são **SOMENTE LEITURA**; a §D e a prosa dos planos escrevem *"variações"*. ➡️ O plano manda **LER o DTO** e é o que foi feito — **inventar uma terceira redação seria o defeito**. ⚠️ **Decisão de unificar NÃO é desta wave**; há asserção travando as duas formas |
| **(6)** | — | `brl()`/`pct()` são a **QUINTA cópia privada** no módulo | as outras quatro (`BloqueiosDaOfertaService:476`, `ComposicaoFinanceiraService:701`, `AprovacaoDaOfertaService:600`, `LinhaDaOfertaService:776`) são **todas privadas**: extrair uma única exigiria **alterar arquivos da Fase 186**, território **INTOCÁVEL**. ⚠️ **A 187 declarou essa MESMA escolha** no docblock dela. Há gate conferindo esta cópia contra os literais de `:1044`/`:1045` nas **quatro** formas, com o **U+2212 medido em BYTES** |

---

## Território — por **ESCOPO DO ASSUNTO**, duas passadas, isca nas duas direções

⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore**, e esta wave é a prova mais forte até
agora: `9becc6e7..HEAD` tem **6 commits** e **3 são da sessão paralela** (Fase 189). O filtro
`/\(188-04\)/` pegou **exatamente** os meus 3.
⚠️ **O AUTOR não foi conferido** — as três máquinas usam o MESMO `user.name` (`MB.ECF-100376`), e
`%an` é vazio por construção.

- **isca do escopo, nas duas direções:** casa `feat(188-04)`, **não** casa `feat(188-03)`,
  `feat(187-04)` nem `docs(coordenacao)`;
- **52 alvos VETADOS**, com os infratores **COLETADOS numa asserção só** → `[]`;
- **ANTI-FANTASMA** (`test -e`) em **cada** um dos 52 → nenhum fantasma;
- **prova de que o range não está vazio por construção**, coletada, com isca nas duas direções;
- **T-188-SC:** nenhum manifesto de dependência no escopo — **este plano não instala nada**;
- **NENHUMA migration** (esta fase não tem, e `artisan migrate` **não foi rodado**).

**Somente leitura, confirmado:** os serviços da 186/187, `DadosEfetivosService`,
`SimuladorVoceRecebe`, `AnaliseAlavancasService`, `EstruturaPrecificacaoParametros`,
`EditorRascunhoService`, `ResumoRapidoService`, os 21 `.jsx` de `aprovacao/`, `routes/`, os
controllers, `app/Support/Publicador`, `Acervo*`/`ImagemAssetService`, `app/Mcp/`,
`Services/Portal/`, `MlPublicacaoService`, `AppLayout.jsx`, o editor, `Bling*`, e os 4 testes JS
intocáveis. ⚠️ **`.planning/ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` NÃO foram tocados**
(GATE-07).

### ⚠️ A camada B do gate de andamento — **intacta, sem remedir `contagens-186-09.txt`**

As chaves são **`andamento_da_irma`** e **`rotulo_do_andamento`** — **as MESMAS** que a 186 já
escolheu em `ComposicaoFinanceiraService::comparacaoComAIrma()`, **pela mesma razão** (a sonda larga
casa `'andamento' =>` em **qualquer** array, inclusive de saída).
➡️ `preg_match("/'andamento'\s*=>|->andamento\s*=(?!=)/", ...)` sobre o serviço dá **0**, com **isca
nas duas direções** (ela casa `'andamento' =>` e **não** casa `'andamento_da_irma' =>`).
⚠️ **Este serviço NÃO entra na lista de EXPOSITORES e `contagens-186-09.txt` NÃO foi tocado** — a
decisão sobre a forma daquele gate é **ADIADA para o 188-13**, como o briefing determina.

### VOC-03 / FIN-02 / FIN-03 — recortados à lista CONSTANTE, com isca

- **`lucro líquido`**: recorte aos **2** arquivos do plano. ⚠️ **Isca nas DUAS direções:** o gate
  acusa a palavra numa frase de teste **e** há asserção de que `lucro_liquido` **existe** em
  `app/Models/EstruturaPrecificacaoParametros.php` (campo **legítimo** do Portal) **e** de que esse
  arquivo **não** está no recorte — varrer `app/` inteiro **BARROU UM DEPLOY** na Fase 186;
- **taxas fictícias** (T-188-35): nenhum `0.12`, `0.17`, `6.75`, `* 0.12`, `* 0.17`, `/ (1 -` como
  literal, com isca;
- **TOK-15**: `assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/')` — **nenhum hex sai do
  PHP**, com isca que prova que a sonda **pega** hex e **não** pega a chave semântica;
- **aprovar já publica**: ausência de `desfazer`, `confirmar publica`, `cancelar aprova`,
  `segundo passo`, com isca;
- **as Fases 189/192 não antecipadas**: ausência de `Ampliar`, `Gerar novamente`, `⤢`, `✦`, com isca;
- **decisão 6**: ausência de `aguardando autoriza`/`aguardando aprova`/`pendente de autoriza`,
  ancorada no **sujeito** e com acentos.

---

## O que este plano **NÃO** fez, e por quê

- **NÃO** deployou, **NÃO** deu push, **NÃO** tocou no VPS, **NÃO** chamou a API real do ML
  (`Http::fake` + `preventStrayRequests()` em todos os casos);
- **NÃO** rodou `artisan migrate` (esta fase não tem migration);
- **NÃO** rodou `pint`, **NÃO** criou worktree, **NÃO** usou `Write` em arquivo existente,
  **NÃO** rodou `state.advance-plan`;
- **NÃO** rodou **nenhuma** forma de `git stash`. ⚠️ **A árvore tem 27 stashes**, não três — o
  briefing diz *"TRÊS"*, e o `git stash list` mostra `stash@{0}`…`stash@{26}`. **Apenas LISTADOS**,
  jamais tocados (a lista de stash é **compartilhada** entre a checkout principal e todo worktree);
- **NÃO** usou `git add -A`, `git reset`, `git checkout`, `git commit --amend` nem `git clean`;
- **NÃO** desenhou botão nenhum — "Informar frete confirmado", "Descartar", "Salvar no anúncio" e o
  clique da irmã são **rótulos/dados**; os **botões** são das waves 09/12;
- ⚠️ **NÃO afirma ter visto tela renderizada.** O banco local não tem empresa com `ml_token` (a rota
  dá **404**), `pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia até a Fase 194. ⚠️ **A
  conferência visual da Fase 187 pelo usuário segue ABERTA** — nada daqui a substitui nem a presume
  feita.

---

## Roteiro da conta 459 — o trecho desta wave (para o 188-13 consolidar)

Com a fila povoada (Fase 194), abrindo o painel de uma oferta:

1. **a coluna de preço mostra 3 KPIs**, e o do meio diz **"Resultado estimado por venda"** —
   **nunca** "lucro líquido";
2. **a margem tem texto ao lado**, e o texto termina em **"com a tarifa real do Mercado Livre"**;
3. se a oferta tem **mais de um preço de variação**, a nota diz **"pior caso entre N variantes"** e
   **nomeia** a variação; com **um** preço só, **não há nota**;
4. **a composição lista 7 linhas**, cada uma com **selo de origem** (`Confirmado`/`Estimado`/
   `Pendente`) e um `title` que **nomeia a fonte**; a de **Embalagem** diz `Pendente` **e o resultado
   continua existindo**, com a nota dizendo **"sem o custo de embalagem"**;
5. se o **frete** não está confirmado, o resultado diz **"Incompleto"**, a margem some (`—`) e
   aparece a frase **"Preço de equilíbrio indisponível até o frete ser confirmado."**;
6. digitando outro preço, aparece o selo **"SIMULAÇÃO · NÃO SALVA"** e o delta **"… vs. salvo"** —
   ⚠️ **e nada é gravado até clicar em "Salvar no anúncio"**, que só existe com a oferta ao vivo;
7. digitando **o mesmo preço que está salvo**, o selo **não** aparece e **não** há como salvar;
8. o atalho **"Margem mínima N%"** mostra o mínimo **da empresa** (não um `12%` fixo) e preenche o
   campo com o inteiro de cima **menos 10 centavos**;
9. a comparação de baixo mostra **Clássico antes de Premium**, com a oferta aberta marcada, e
   **clicar na outra abre o painel dela**.

---

## Self-check

| o que | resultado |
|---|---|
| `app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php` | **existe** · 1061 linhas (o `min_lines` do plano era 180) |
| `tests/Feature/Publicador/Aprovacao/PrecoDoPainelServiceTest.php` | **existe** · 1796 linhas · 12 métodos `_188_04` |
| `tests/gates/contagens-188-04.txt` | **existe** · 4 ids, todos medidos |
| `.planning/.../188-FID01.md` §L | **existe** · gate `fid01-remedida` verde (1 / 13) |
| commits `f454a2e3`, `14fa6b32`, `9a3901b8` | **existem** no `git log` |
| `git diff HEAD` nos meus arquivos | **vazio** — tudo commitado |
| os 4 ids deste plano | **4 verdes** pelo harness, com `188-04` como 1º argumento |
| os 17 ids das waves 1-3 | **17 verdes**, contagens **inalteradas** |
| suíte PHP final | **2534 / 23020 / 0** (`+12` testes, o exato número de métodos novos) |
| suíte JS final | **5498 / 5497 / 1 fail / 0 skipped** — igual à baseline |
| mutação | **10 de 10 MORTAS**, restauração conferida por `sha256` |

**Self-Check: PASSED**

---

## Aviso recebido da sessão paralela (repassado ao orquestrador, não é desta wave)

`.planning/coordenacao/261019-para-quem-executa-a-188-depois-execute-a-189.md` (commit `2f7fd02c`,
da sessão que **planejou** a Fase 189) traz **três avisos** que valem para as waves **188-08** e
**188-12**, e **nenhum** deles incide nesta wave:

1. a **borda de seleção** das miniaturas vem fixa na foto 0 (`FotosDoPainelService::para()` é chamado
   **sem índice**) — a grade do **188-08** não pode usar a `borda` crua para a seleção;
2. **não existe rótulo legível de variação** no payload (só `grupo`/`grupo_geral`); o humano só existe
   em `ResolvedorGruposImagem::rotuloDoGrupo`, **privado** e em território vetado — o **188-08** precisa
   dele para *"tipo · variação"* (`:623`), e se a saída for devolver a pergunta ao usuário, **melhor
   agora**;
3. o `ModalDeReprovarImagem` do **188-12** deve ser montado **como irmão do `<aside>`**, não dentro
   dele — senão o modal aberto a partir do lightbox (`z-[41]`) fica **escondido atrás** do painel.

⚠️ E o pedido: **quando a 188 fechar, rodar `/gsd-execute-phase 189`** na mesma sessão (o `189-01`
tem gate de entrada que confere os 13 SUMMARY da 188 e o `188-FECHAMENTO.md`, e **PARA** se faltar
algum).
