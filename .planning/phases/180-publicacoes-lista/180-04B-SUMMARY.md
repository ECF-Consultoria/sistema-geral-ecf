---
phase: 180-publicacoes-lista
plan: 04B
subsystem: api
tags: [publicador, publicacoes, contrato-de-props, lacuna-de-prop, lista-fechada, custo-de-consulta, mutacao]

requires:
  - phase: 180-publicacoes-lista
    plan: "04"
    provides: "o item de `anuncios.data` com 34 chaves, o helper privado `idsPorMetodologia()` como precedente de 'o serviço é território de outro plano', o gate `PublicacoesDaTelaTest` (40 casos) e o custo travado em 33 consultas"
  - phase: 180-publicacoes-lista
    plan: "08"
    provides: "a MEDIÇÃO das três lacunas (§6 da SUMMARY dele), a confirmação de que `numeroMedido()` aceita string numérica de forma fechada, e a recusa explícita de derivar 'Com erro' de `severidade`"
  - phase: 180-publicacoes-lista
    plan: "03"
    provides: "`AcervoTriagemService::SUB_STATUS_COM_ERRO` (5 valores, forma confirmada pelo usuário em 13/10/2026) e o eixo `com_erro` da aba"
  - phase: 180-publicacoes-lista
    plan: "02"
    provides: "`metodologiaPorMlItemId()` com `publicada_em` já no formato ISO-8601 (`isoOuNulo(concluida_em)`)"
provides:
  - "`preco` no item — `ml_acervo_itens.price` cru (cast `decimal:2`), `null` preservado; a célula Preço para de mostrar `—` em todas as linhas"
  - "`nova` no item — publicada no DIA CIVIL de hoje, régua MEDIDA no protótipo e não escolhida; o `<BadgeNova>` e o `bg-pub-row-new` do plano 180-08 ficaram alcançáveis"
  - "`com_erro` no item — bool derivado de `sub_status` pela MESMA constante que a aba usa, com gate de consistência nos dois sentidos"
  - "os helpers privados `publicadaHoje()` e `temSubStatusComErro()` em `MlbAnuncioController`"
  - "6 casos novos / 70 asserções em `PublicacoesDaTelaTest`, sem alterar nenhuma asserção dos 40 anteriores"
affects: [180-09, 180-10, 180-11, 181-produtos]

tech-stack:
  added: []
  patterns:
    - "⚠️ Lista fechada que a TELA precisa consultar fica em PHP e sai do servidor como BOOL derivado, nunca como o eixo cru. Mandar `sub_status` cru teria empurrado a régua para o JS, e aí a LINHA e a ABA passariam a ter duas cópias da mesma lista — a divergência que este plano existe para impedir. O bool custa o mesmo e tem fonte única"
    - "Régua de UI derivada por TRIANGULAÇÃO no protótipo, não por leitura: `isNew` é dado da publicação (`:433`/`:434`), o 'hoje' do mock é um literal (`:491`), e os 2 de 24 itens que caem nesse dia são exatamente os 2 marcados. Três concordâncias independentes (o `days(d)===0` do protótipo, o `periodo=hoje` do controller e o `hojeEmSaoPaulo()` da tela) substituem o chute"
    - "Derivar a flag da MESMA string que a tela exibe (`publicada_em`), não de um segundo carimbo: é o que torna impossível a linha dizer 'NOVA' e a data dizer outro dia — e há asserção percorrendo as 4 linhas comparando as duas"
    - "⚠️ Nesta árvore compartilhada `git log --format=%an` NÃO discrimina sessões (as duas rodam com o mesmo `user.name`). O discriminador é o ESCOPO do assunto do commit (`(184-*)` × `(180-*)`)"

key-files:
  created:
    - .planning/phases/180-publicacoes-lista/180-04B-PLAN.md
    - .planning/phases/180-publicacoes-lista/180-04B-SUMMARY.md
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/Publicador/PublicacoesDaTelaTest.php

key-decisions:
  - "⚠️⚠️ **DIVERGÊNCIA DECLARADA DO BRIEFING: a prop é `com_erro` (bool), NÃO `sub_status`.** O briefing nomeia a terceira prop ausente como `sub_status`; recusei o NOME e entreguei o SINAL. Três razões medidas, qualquer uma basta: (1) `sub_status` é cast `'array'` (`MlAcervoItem.php:86`) e mandá-lo cru acrescentaria um campo ARRAY ao item, o que o próprio briefing proíbe (herança da tela preta de 07/10); (2) a lista fechada tem de viver em UM lugar — mandar o eixo cru empurra a régua para o JS e a LINHA passa a poder discordar da ABA, que o briefing classifica como *requisito de consistência, não preferência*; (3) é literalmente o que o plano 180-08 prescreveu (§6c: `'com_erro' => $item->temSubStatusComErro()`), e ele é o dono do componente consumidor. ⚠️ `com_erro` é a CHAVE (`AcervoTriagemService::STATUS_COM_ERRO`), a mesma do `?status=` e da aba — o rótulo 'Com erro' continua morando na tela"
  - "⚠️ `temSubStatusComErro()` nasceu como helper PRIVADO do controller e não como método do modelo, apesar de o 180-08 ter escrito `$item->temSubStatusComErro()`: o método **não existe** em `MlAcervoItem` (medido) e criá-lo espalharia a referência à constante do serviço de triagem para dentro do modelo. O precedente é o `idsPorMetodologia()` do plano 04 — a mudança fica em UM arquivo"
  - "⚠️ A régua de `nova` é **publicada no DIA CIVIL de hoje**, e NÃO 'criada nesta sessão' nem janela de 24 h. Medida por triangulação no protótipo (ver a §3). `ml_acervo_itens.created_at`/`coletado_em` foi CONSIDERADO E RECUSADO — é quando a COLETA viu o anúncio, e usá-lo faria a tela chamar de 'nova' uma publicação antiga recém-coletada; há asserção negativa com um item publicado há 40 dias e coletado hoje"
  - "⚠️ `nova` NÃO é a faixa de sucesso. `faixaSucesso` segue `null` SEMPRE (plano 04 §2: nenhuma chave de flash de publicação na lista branca do `HandleInertiaRequests` e a publicação termina assíncrona). São dois eixos diferentes e este plano não tocou a faixa"
  - "`preco` sai CRU (`$item->price`), string de 2 casas pelo cast `decimal:2`. Não converti para `float`: a forma string já foi MEDIDA como aceita por `numeroMedido()` de forma fechada, e o cast preserva as casas do banco. `null` continua `null` porque `'decimal'` está em `HasAttributes::$primitiveCastTypes`"
  - "⚠️ `margem`, `lucro`, `repasse` e `tarifa` CONTINUAM não existindo. Este plano entrega a 1ª linha da célula Preço (o valor); a 2ª ('Margem x%' / '▲ Prejuízo') é a camada assíncrona da Fase 181 e segue em `—`. A negativa foi RERRODADA no caso novo, com o item cheio"
  - "`sub_status` cru NÃO é enviado nem como array achatado: hoje ninguém o consome, e 'nada nasce decorativo'. Mostrar QUAL erro exige antes um mapa de rótulos em pt-BR (`waiting_for_patch` na tela é jargão) — pendência nomeada para a Fase 181"
  - "ZERO migration, ZERO índice, ZERO pacote, ZERO rota, ZERO linha de frontend, ZERO deleção, ZERO consulta nova"

patterns-established:
  - "Fechamento aritmético que EXPLICA o resto: 15.887 + 70 (novos) + **3** = 15.960. Os 3 extras não são mistério — `test_chaves_do_item_sao_aditivas` tem um `foreach ($item as ...)` e o item passou de 34 para **37** chaves. Aceitar '+73 ≈ +70' teria escondido o fato de que uma asserção existente passou a cobrir as chaves novas"
  - "Contador de chaves do payload com ISCA POSITIVA (as 3 novas têm de aparecer) e NEGATIVA (`margem` não pode): um contador que só conta não prova que está lendo o bloco certo"

requirements-completed: [LIST-10, LIST-11, LIST-12]

metrics:
  duration: "~75min (2026-10-13)"
  completed: 2026-10-13
  tasks: 3
  commits: 4
  testes-php-novos: 6
  assercoes-php-novas: 70
  mutacoes: "7/7 pegas, 0 sobreviventes, 1 controle negativo recusado"
---

# Fase 180 Plano 04B: as três props que não chegavam do servidor — Resumo

**A tabela de Publicações estava construída e correta, mas três props nunca saíam de `meus()`: a
célula Preço mostrava `—` em TODAS as linhas, o badge NOVA era inalcançável e a linha nunca podia
dizer "Com erro". As três passaram a chegar — de forma aditiva, num arquivo só, sem uma consulta
nova e com a lista fechada do "Com erro" morando em UM lugar, para a LINHA e a ABA nunca
discordarem.**

## Desempenho

| Task | O que | Commit | Linhas |
|---|---|---|---|
| — | o `180-04B-PLAN.md` | `e86fd52f` | +303 / **-0** |
| 1 | as 3 chaves + os 2 helpers no controller | `4d358a01` | +163 / **-0** |
| 2 | o gate (6 casos / 70 asserções) | `37467b01` | +365 / **-0** |
| 3 | mutações + esta SUMMARY + `STATE.md` | (o commit desta SUMMARY) | — |

**2 arquivos de código, +528 / -0.** Zero PHP de terceiros, zero frontend, zero migration, zero
pacote, zero deleção — provado **commit por commit** (não por range: o range pega a sessão paralela
da Fase 184, ver §8).

---

## (1) ⚠️ O PREÇO — e por que a forma STRING está certa

| medida | resultado |
|---|---|
| a coluna | `ml_acervo_itens.price` — `$fillable:51`, `$casts:101` = `'decimal:2'` |
| nulo | `'decimal'` está em `HasAttributes::$primitiveCastTypes` (`:113`) ⇒ **`null` continua `null`**, nunca `"0.00"` |
| preenchido | o cast devolve **string** (`"1099.90"`) e o JSON a manda como string |
| o consumidor aceita? | **sim, de forma fechada** — `numeroMedido()` (`CelulasDaLinha.jsx:132`-`:143`) trata `typeof 'string'` com `RE_NUMERO = /^-?\d+(?:[.,]\d+)?$/` (`:117`) |
| custo | **zero** — `AcervoTriagemService::escopo()` **não tem `->select()` nenhum** (medido: `grep -n select` no arquivo não acha nenhuma restrição de coluna), logo a linha inteira do acervo já vinha do banco |

➡️ `'preco' => $item->price`, **cru**. ⚠️ **Não converti para `float`** — a forma já foi medida como
aceita, e o cast preserva as 2 casas do banco. A asserção do gate confere **a forma pela régua do
consumidor** (o mesmo `RE_NUMERO`), não o tipo PHP: se o valor deixar de casá-la, `numeroMedido()`
devolve `null` e a célula volta para o `—` **sem nenhum outro gate acusar**.

⚠️ **`preco: null` → `—` e `preco: 0` → `R$ 0,00`.** O caso
`test_preco_zero_e_medida_e_nulo_e_ausencia` prova que os dois chegam **diferentes** — é a distinção
de que LIST-12 inteira depende ("nunca medimos" ≠ "medimos e é zero").

⚠️ **A 2ª linha da célula continua sem fonte, de propósito.** `margem`/`lucro`/`repasse`/`tarifa`
seguem **ausentes**, e a negativa foi **rerrodada com o item cheio** dentro do caso novo. Este plano
entrega o VALOR; "Margem x%" / "▲ Prejuízo" é a camada assíncrona da Fase 181 e segue em `—`.

---

## (2) ⚠️ `nova`: a régua foi MEDIDA por triangulação, não escolhida

O briefing deixou a régua como minha decisão e exigiu medir. **O que foi medido em
`new_layout_publicador/Publicacoes v2.dc.html`:**

| medida | linha | resultado |
|---|---|---|
| `isNew` é dado da PUBLICAÇÃO ou estado da sessão? | **`:433`/`:434`** | está **hardcoded dentro de `PUBS`**, item por item. A faixa de sucesso, ao contrário, é a prop `showSuccess` (`:506`) — ⚠️ **é esse o fato que discrimina as duas hipóteses**: se `isNew` significasse "criada nesta sessão", viria do estado da faixa, não da linha de dado |
| quais itens têm `isNew: true` | `:433`-`:434` | **exatamente os dois** com `d: '2026-10-10T14:31'` e `'…14:32'` |
| qual é o "hoje" do protótipo | **`:491`** | o literal `new Date('2026-10-10T00:00')` dentro de `days(d)` |
| quantos dos 24 itens caem em 2026-10-10 | varredura de `:433`-`:456` | **2 — os mesmos dois.** Zero falso positivo, zero falso negativo |
| a régua já existe no protótipo? | `:498`, `:523` | sim: `days(d) === 0` é o que escreve "Hoje, hh:mm" **e** o que move o filtro `period: 'today'` |

➡️ **`nova` = `publicada_em` cai no DIA CIVIL de hoje.** Três concordâncias independentes:
o `days(d) === 0` do protótipo, o `periodo=hoje` que este mesmo controller já implementa
(`:1176`, `now()->startOfDay()`) e o `hojeEmSaoPaulo()` que o plano 180-08 exportou para a tela.
⚠️ **O fuso é DECLARADO, não herdado:** `config/app.php:68` + `.env` dão
`APP_TIMEZONE=America/Sao_Paulo`, o MESMO fuso do JS.

⚠️ **`ml_acervo_itens.created_at`/`coletado_em` foi CONSIDERADO E RECUSADO.** É quando a **coleta**
viu o anúncio, não quando ele foi publicado — usá-lo faria a tela chamar de "nova" uma publicação
antiga recém-coletada. **Há asserção negativa** com um item publicado há 40 dias e `coletado_em`
agora, e a mutação (iii) prova que ela reprova.

⚠️ **A fonte é a MESMA string que a coluna "Publicada" mostra** (`publicada_em`). Isso torna
impossível a linha dizer "NOVA" e a data dizer outro dia — e o gate percorre as 4 linhas comparando
as duas. ➡️ **Custo zero**: o mapa de metodologia já estava na mão, fora do `through()`.

⚠️ **Legado (`publicada_em = null`) é `false`.** Ausência de dado não é novidade.

⚠️ **O que esta régua NÃO é:** ela **não** é "criada nesta sessão", e **`faixaSucesso` segue `null`
SEMPRE** (plano 04 §2 — nenhuma chave de flash de publicação na lista branca do
`HandleInertiaRequests` e a publicação termina assíncrona em 202 + `PublicarRascunhoJob`). São dois
eixos diferentes; a faixa não foi tocada.

### A decisão que NÃO tomei: `false` sempre

O briefing autorizava `nova = false` com pendência declarada se nenhuma régua fosse honesta. **Não
precisei:** a régua acima é medida, não arbitrária, e deixa de acender exatamente onde deveria
(ontem 23:59, 40 dias atrás, Legado). ⚠️ **Se o usuário quiser o sentido estrito do protótipo**
("criadas agora, nesta publicação"), o caminho é a chave de flash que o plano 04 nomeou — e aí
`nova` passa a sair dela, não desta régua. **É decisão de produto e não a tomei sozinho.**

---

## (3) ⚠️⚠️ "Com erro": a divergência de NOME, declarada e justificada

**O briefing nomeia a prop ausente como `sub_status`. Recusei o NOME e entreguei o SINAL**, como
`com_erro` (bool). **Três razões medidas, qualquer uma basta:**

1. ⚠️ **`sub_status` é cast `'array'`** (`MlAcervoItem.php:86`). Mandá-lo cru acrescentaria um campo
   **array** ao item — exatamente o que o próprio briefing proíbe ("só string, int, float, bool ou
   null"), herança da tela preta de 07/10/2026.
2. ⚠️ **A lista fechada tem de viver em UM lugar.** Mandar o eixo cru empurra a régua para o JS, e
   então a **LINHA** e a **ABA** passam a ter duas cópias — a divergência que o próprio briefing
   classifica como *"requisito de consistência, não preferência"* (nesta milestone dois números que
   discordavam na mesma tela já custaram uma decisão do usuário, 12/10/2026).
3. **É literalmente o que o plano 180-08 prescreveu** (§6c: *"o caminho é aditivo:
   `'com_erro' => $item->temSubStatusComErro()` no item"*), e ele é o dono do componente consumidor.

⚠️ **`com_erro` é a CHAVE, não o rótulo:** é o literal de `AcervoTriagemService::STATUS_COM_ERRO`
(`:34`), o mesmo que o `?status=` e a aba já usam. O rótulo "Com erro" continua morando na tela.

⚠️ **O método `temSubStatusComErro()` que o 180-08 citou NÃO EXISTE no modelo** (medido por `grep`
em `MlAcervoItem.php`). Em vez de criá-lo lá — o que espalharia a referência à constante do serviço
de triagem para dentro do modelo — virou **helper privado do controller**, pelo precedente que o
plano 04 abriu com `idsPorMetodologia()`. **A mudança inteira fica em UM arquivo.**

⚠️ **NÃO derivado de `severidade` nem de `motivos`.** O docblock da constante proíbe em três linhas
(`AcervoTriagemService.php:67`-`:72`) e o plano 04 mediu que **anúncio moderado pode ter
`severidade = 0`**. O caso `test_com_erro_nao_vem_de_severidade_nem_de_motivos` prova nos **dois**
sentidos: severidade crítica + motivos cheios + `under_review` + **o título cheio dos termos**
("forbidden held warning") ⇒ `com_erro = false`; e `active` com `severidade = 0` e
`sub_status = ['waiting_for_patch']` ⇒ `com_erro = true`.

⚠️ **`sub_status` cru não é enviado nem como array achatado:** hoje **ninguém o consome**, e "nada
nasce decorativo". Mostrar QUAL erro exige antes um mapa de rótulos em pt-BR (`waiting_for_patch` na
tela é jargão, regra permanente) — **pendência nomeada** para a Fase 181.

### A equivalência PHP × SQL, com o limite escrito

A aba faz `orWhere('sub_status', 'like', '%"<valor>"%')` por valor da constante
(`AcervoTriagemService.php:292`-`:299`); a linha faz `array_intersect` dos valores **string** do
array com a MESMA constante. Para o json que o ML emite (lista achatada de strings) os dois
coincidem, **inclusive na borda**: `%"warning"%` **não** casa dentro de `"pre_warning"`, porque as
aspas ancoram os dois lados.
⚠️ **Limite declarado:** se `sub_status` chegasse como **objeto** com uma chave chamada `warning`, o
`LIKE` casaria e o `array_intersect` não. O ML emite array; a divergência fica **escrita, não
escondida**.

---

## (4) O que precisaria estar errado no código para cada gate falhar

| caso (asserções) | falha se… |
|---|---|
| `preco_chega_do_acervo_e_nulo_continua_nulo` (11) | `preco` ausente do item (o estado medido pelo 180-08 — célula inteira em `—`); o valor deixando de casar o **`RE_NUMERO` do próprio consumidor** (e aí `numeroMedido()` devolve `null` e a tela volta ao `—` sem outro gate acusar); o valor arredondado/alterado; `null` virando `"0.00"`; ou `margem`/`lucro`/`repasse`/`tarifa` entrando — ⚠️ **a negativa roda com o item CHEIO** (preço, 61 vendas, estoque, visitas, produto, autor, nota), e a premissa é asserida no próprio caso |
| `preco_zero_e_medida_e_nulo_e_ausencia` (5) | um `?? 0` no preço; um cast que transformasse `null` em `0.0`; o inverso (zero virando nulo e a tela escrevendo `—` para um anúncio que custa R$ 0,00); ou os dois chegando IGUAIS |
| `nova_e_publicada_hoje_e_nunca_a_data_da_coleta` (12) | `nova` ausente (badge e `bg-pub-row-new` inalcançáveis); a régua virando `created_at`/`coletado_em` do acervo (caso com publicação de **40 dias atrás coletada hoje**); a régua virando janela de **24 h** (caso com **ontem 23:59**, 9 h e 6 min antes do `now()` ancorado); o Legado ganhando badge; ou **`nova` e `publicada_em` discordando** (laço pelas 4 linhas). ⚠️ Ancorado com `Carbon::setTestNow()` — teste dependente de `now()` deste projeto já reprovou por HORÁRIO |
| `com_erro_da_linha_usa_a_mesma_lista_fechada_da_aba` (22) | ⚠️ **o caso de consistência.** Falha se a linha usar lista PRÓPRIA diferente da constante; se faltar ou sobrar um valor; se o eixo da aba mudar sem a linha mudar. Percorre os **5** valores de `SUB_STATUS_COM_ERRO` exigindo `com_erro === true` **E** presença em `?status=com_erro`, e os **3** sub-status REAIS que ficam de fora (`cart_eligible`, `paused_by_seller`, `invalid_kit`, medidos nos fixtures de produção da Fase 134) exigindo as duas coisas falsas. ⚠️ **Com ISCA**: o caso assere que `?status=com_erro` **não está vazio**, senão as negativas seriam vazias por construção |
| `com_erro_nao_vem_de_severidade_nem_de_motivos` (6) | `com_erro` derivado de `severidade > 0`, de `motivos !== []`, de `status === 'under_review'` ou **do título** (o fixture tem "forbidden held warning" no `title`). E o inverso: moderado com `severidade = 0` deixando de ser erro |
| `tres_chaves_novas_sao_escalares_e_nao_vazam_segredo` (14) | qualquer das 3 chegando **array/objeto** (`'com_erro' => $item->sub_status` é a mutação vii); `nova` ou `com_erro` deixando de ser bool (`1`/`"1"` não acenderiam `item.nova === true`); **`sub_status` aparecendo como chave do item**; ou segredo do `MlToken` no payload (a empresa TEM token no fixture) |
| **custo** (`CONSULTAS_DA_ACTION = 33`, **intocado**) | qualquer consulta nova. ⚠️ **As 3 chaves custam ZERO**: `price` e `sub_status` vêm da mesma linha do acervo já carregada e `publicada_em` já estava no mapa resolvido fora do `through()`. Os dois casos de custo do plano 04 rodaram **sem edição** e passaram ⇒ o número **continua 33** |

### E o que NÃO tem gate — declarado, não maquiado

- ⚠️ **A LINHA ainda não DESENHA "Com erro".** O sinal chega, mas o mapa
  `STATUS_DA_PUBLICACAO` de `CelulasDaLinha.jsx:388` tem **quatro** entradas e é território de outro
  plano. **A ligação está escrita na §7** — e sem ela a prop existe e não aparece.
- ⚠️ **Nenhuma tela renderizada foi vista.** Não abri navegador, não rodei build, não deployei, não
  toquei no VPS e **não li produção** (bloqueada). O que foi medido é o **payload** de
  `Inertia::render`, por requisição HTTP real.
- ⚠️ **`ml_acervo_itens` tem 0 linhas no banco local** — todo cenário é sintético (SQLite em
  memória). O custo de 33 consultas é do SQLite dos testes: a CONTAGEM é a mesma (é o mesmo código),
  o TEMPO não foi medido.
- **A régua estrita do protótipo para `nova`** ("criadas agora") — depende da chave de flash que não
  existe. Decisão de produto, nomeada e não tomada.

---

## (5) As 7 mutações — 7/7 PEGAS, 0 sobreviventes, controle negativo RECUSADO

Script em **ARQUIVO** (`mutar.php`), contagem do alvo **travada em 1** (recusa com exit 1 se ≠ 1),
**QUAL linha muda impressa antes de aplicar** com guarda explícita de "é comentário? ABORTAR",
restauração sempre por **cópia pristina** e `sha256` conferido em cada rodada.
⚠️ **ANSI removido com `sed 's/\x1b\[[0-9;]*m//g'` ANTES do grep do veredicto** — a armadilha que
fez a campanha inteira do plano 180-03 parecer verde sem provar nada.

> ⚠️ **A trava de linha não é zelo aqui:** o arquivo tem **4.639 linhas** e comentários longos que
> citam `preco`, `margem`, `com_erro`, `sub_status`, `severidade` e `publicada_em` em prosa. Um
> replace cego pegaria o comentário. **As 7 linhas alvo foram conferidas uma a uma e todas são linha
> de CÓDIGO.**

| # | mutação | linha | falhas | reprovou em |
|---|---|---|---|---|
| i | `'preco' => $item->price` → `null` | 837 | 1 | `preco_chega_do_acervo…` (`'' não casa o RE_NUMERO`) |
| ii | `$item->price` → `$item->price ?? 0` (**o zero inventado**) | 837 | 1 | `preco_zero_e_medida…` (`0 is null`) |
| iii | `nova` saindo de `$item->coletado_em` (**a régua recusada**) | 873 | 1 | `nova_e_publicada_hoje…` (`true is false`) |
| iv | `->isSameDay(now())` → `->greaterThanOrEqualTo(now()->subDay())` (dia civil → 24 h) | 1320 | 1 | `nova_e_publicada_hoje…` (ontem 23:59 acendeu) |
| **v** | ⚠️ **a lista da LINHA trocada por `['forbidden','held']`** — diferente da da ABA | 1359 | 1 | `com_erro_da_linha_usa_a_mesma_lista_fechada_da_aba` (`false is true`) |
| vi | `com_erro` derivado de `$item->severidade > 0` | 894 | 1 | `com_erro_nao_vem_de_severidade…` (`true is false`) |
| vii | `'com_erro' => $item->sub_status` (**campo array**) | 894 | 1 | `tres_chaves_novas_sao_escalares…` (`false is true`) |

**`sha256` de volta ao pristino `d8e489297872c12071152ff6b559862bd154abd6a437506781690084b263be55`
nas 7 restaurações, e o ATUAL é o mesmo.**

⚠️ **CONTROLE NEGATIVO da trava:** rodei o mutador com um alvo que aparece **9** vezes
(`$metodologia[`) e ele **RECUSOU** com exit 1, deixando o arquivo com `sha256` idêntico. Sem esse
controle, "nenhuma recusada" poderia ser "a trava não funciona".

⚠️ **A mutação (v) é a obrigatória do briefing** — é ela que prova que a linha e a aba não podem
divergir. Ela reprova porque `waiting_for_patch`/`pending_documentation`/`warning` continuam
entrando na **ABA** (que lê a constante) e deixam de entrar na **LINHA**.

---

## (6) ⚠️ O fechamento aritmético — e os 3 que sobravam

| medição | resultado |
|---|---|
| baseline `tests/{Unit,Feature}/Publicador` **ANTES** de qualquer edição | **2079 passed / 15.887 assertions / 0 falhando** (408,83 s) — bate **exatamente** com o plano 04 |
| suíte **DEPOIS** | **2085 passed / 15.960 assertions / 0 falhando** (385,83 s) |
| os 6 casos novos medidos **em isolamento** (por método) | 11 + 5 + 12 + 22 + 6 + 14 = **70 asserções** |
| `tests/Feature/Phase134/MeusAnunciosTest.php` (a régua), **sem edição** | **25 passed / 110 assertions** — idêntico ao medido pelos planos 180-03 e 180-04 |

⚠️⚠️ **2079 + 6 = 2085 ✓, mas 15.887 + 70 = 15.957 e a medida foi 15.960.** **Três asserções de
diferença, investigadas em vez de arredondadas:**

`test_chaves_do_item_sao_aditivas` (plano 04) termina com
`foreach ($item as $chave => $valor) { assertTrue(…) }` — a guarda de "nenhum campo do item é
objeto". **O item passou de 34 para 37 chaves**, então aquele laço ganhou **+3 asserções**.
Medido: contei as chaves do bloco do `through()` por script, com **isca positiva** (as 3 novas têm de
aparecer) e **negativa** (`margem` não pode) ⇒ **37 chaves**. E o caso, rodado em isolamento depois
da minha edição, reporta **79 asserções** (eram 76).

➡️ **15.887 + 70 + 3 = 15.960 ✓ exato.** ⚠️ E a leitura importa: **é `+assert`, não `-assert`** — a
guarda contra campo objeto passou a cobrir as 3 chaves novas **de graça**. Nenhuma linha foi removida
de `tests/` (`git diff | grep -c "^-.*assert"` = **0**).

---

## (7) ⚠️ PARA O 180-10: o que ligar, e o que NÃO fazer

### As três props da linha continuam sendo suas (180-08 §6d)

`rotulosDeMotivo`, `hoje` e `urlDoRascunho` — ⚠️ **esquecer a terceira quebra LIST-23 em silêncio.**
Nada disso mudou.

### ⚠️ `nova` e `preco` já funcionam SEM você fazer nada

Os dois saem do **ITEM**, e o componente já os lê (`CelulasDaLinha.jsx:688` e `:540`,
`LinhaDePublicacao.jsx:126`). ⚠️ **Não passe `nova` como prop da linha** — é do item, como no
protótipo.

### ⚠️ `com_erro` CHEGA, mas a linha ainda não o DESENHA

O mapa `STATUS_DA_PUBLICACAO` (`CelulasDaLinha.jsx:388`) tem **quatro** entradas e não conhece
`com_erro` — é território do 180-08 e eu não podia editá-lo. **A ligação, para você não ter de
deduzir:**

```js
// em CelulasDaLinha.jsx, dentro da CelulaStatus — o eixo vem ANTES do mapa de status
const chaveDoStatus = dados.com_erro === true
    ? 'com_erro'                                   // ⚠️ `=== true`, nunca truthy
    : valorDoMapa(STATUS_DA_PUBLICACAO, dados.status);
```

⚠️ **Três avisos sobre essa ligação:**
1. ⚠️ **`com_erro` é EIXO, não status.** Um anúncio pode estar "Em revisão" **E** "Com erro" ao mesmo
   tempo (é o que `AcervoTriagemService` declara em `:275`-`:278`). Decidir que "Com erro" **ganha**
   da precedência do status é **decisão de UI** — eu não a tomei, só entreguei o dado.
2. `SeloDeStatusDaPublicacao` **já conhece** a chave `com_erro` (é uma das quatro), então não precisa
   tocar `identidade/`. ⚠️ Mas o ícone dele é o `AlertTriangle` de CONTORNO e o protótipo (`:249`)
   quer um triângulo **PREENCHIDO** — divergência que o 180-08 mediu e deixou registrada.
3. ⚠️ **Não derive "Com erro" de `severidade`** nem reconstrua a lista de `sub_status` em JS. A
   constante é do servidor, e há mutação provando que divergir reprova.

### E o que o PAINEL da Fase 181 pode querer

Mostrar **QUAL** erro (`waiting_for_patch` → "Aguardando correção") exige: `sub_status` achatado no
payload **+ um mapa de rótulos em pt-BR** (slug na tela é jargão, regra permanente). ⚠️ **Não mandei
`sub_status` hoje porque ninguém o consome** — "nada nasce decorativo". Quando mandar, **mande
achatado e só de strings**, nunca o cast cru.

---

## (8) Territórios e a árvore compartilhada

| gate | resultado |
|---|---|
| `test -e` nos **23** alvos | **23/23 existem** — zero caminho fantasma. Usei os caminhos CERTOS registrados pelos planos anteriores: `resources/js/Components/Publicador/Mesa` (**sem** o `Mlb`), `resources/js/Pages/Mlb/AnunciosHistorico.jsx` (**fora** de `Publicador/`), `app/Services/Mlb/Publicacao/MlPublicacaoService.php`, `app/Services/Publicador/`, `.planning/ROADMAP.md` |
| RANGE A (`f23f610d..HEAD`) | **0** em `app/Support/Publicador`, `MlPublicacaoService.php`, `Components/Publicador/Mesa`, `AnunciosHistorico.jsx`, `Editor.jsx`, `AppLayout.jsx`, `identidade/`, `header/PublicadorHeader.jsx`, `MeusAnunciosTest.php`, `composer.*`, `package*.json`. ⚠️ **DUAS migrations do Bling aparecem — não são minhas** (ver abaixo) |
| RANGE B (`2b627c6c..HEAD`) | **0** em `app/Services/Publicador/`, `MlAcervoItem.php`, `tailwind.config.js`, `resources/js`, `resources/css`, `tests/js`, `routes/`, `REQUIREMENTS-v26.md`. ⚠️ `.planning/ROADMAP.md` (+1/-1) e `app/Services/Bling/LimitadorDoBling.php` (+133) aparecem — **não são meus** |
| **CONTROLE** do gate | o MESMO comando vê `163/0` e `365/0` nos meus 2 arquivos ⇒ não é vazio por construção |
| frontend / migration / pacote / deleção, **commit por commit** | **vazio nos 3** (`resources/js`, `tests/js`, `resources/css`, `tailwind.config.js`, `database/migrations`, `composer.*`, `package*.json`, `--diff-filter=D`) |
| arquivos tocados por commit | exatamente **1** em cada |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| árvore nos meus alvos depois da campanha | limpa (`git status --short -- app/ tests/` só mostra arquivos **da sessão paralela**) |

### ⚠️ 8º plano seguido em que o range acusa quem não é o executor — e uma armadilha NOVA

Conferi **autor e hash antes de acusar**, como o learnings manda:

| o que apareceu | de onde vem |
|---|---|
| `database/migrations/…bling_conexoes…` e `…bling_vinculos…` (RANGE A) | `19f873b0` e `8f1dfc67` — `feat(184-01)` |
| `app/Services/Bling/LimitadorDoBling.php` (+133) (RANGE B) | **`2cbbbb27` — `feat(184-06)`, commitado ENTRE o fim do meu Task 2 e a minha medição**, enquanto a suíte rodava |
| `.planning/ROADMAP.md` (+1/-1) | `ece476ba` — `docs(184-05)` |

> ⚠️⚠️ **ARMADILHA NOVA, não está no learnings: nesta árvore `git log --format='%an'` NÃO
> DISCRIMINA as sessões.** As duas rodam com o **mesmo `user.name`** (`MB.ECF-100376`), então o
> "conferi o autor" de todos os planos anteriores estava, na prática, conferindo o **assunto** do
> commit. ➡️ **O discriminador real é o ESCOPO do assunto** (`(184-*)` × `(180-*)`), e um gate que
> compare `%an` com um nome esperado seria **vazio por construção** nesta árvore.

⚠️ **Por isso a prova de "zero PHP de terceiros" é commit por commit**, e por isso a suíte foi
restrita a `tests/{Unit,Feature}/Publicador`: `tests/Feature/Bling/LimitadorDoBlingTest.php` nasceu
da outra sessão e **fica fora do caminho que medi** — se eu tivesse rodado a suíte inteira, a
contagem "depois" mediria o trabalho dela e o fechamento aritmético não fecharia.

---

## O que NÃO foi feito (e por quê)

- **Nenhuma linha de frontend.** Os 11 componentes de `publicacoes/`, `MeusAnuncios.jsx`,
  `identidade/`, o editor, a Visão Geral e `tailwind.config.js` seguem intocados. **A suíte JS não
  foi rodada** porque nada de JS mudou (diff vazio commit por commit); a baseline citada e **não
  remedida** é **4226 / 4225 pass / 1 fail** — a falha é a **pré-existente**
  `tests/js/estrutura-grade-glide.test.js:122`.
- **`npm run build` não foi rodado** — sem mudança de frontend não há bundle a refazer.
- **Nenhuma migration, nenhum índice, nenhum pacote, nenhuma rota.** Os dados já existiam
  (`price` e `sub_status` são colunas coletadas desde a Fase 134; `concluida_em` desde o Publicador).
  ⚠️ **Em nenhum momento a execução concluiu que precisava de migration** — o que confirma a medição
  do 180-08.
- **`AcervoTriagemService`, `PublicacoesDaListaService` e `MlAcervoItem` não foram tocados.** A
  constante da lista fechada é **referenciada**, nunca copiada.
- **`SubAbasDePublicacoes.jsx`, a rota `historico`, o `historico()` e os testes deles** seguem
  inteiros — insumo da Fase 183.
- **`ROADMAP.md` e `REQUIREMENTS-v26.md` NÃO tocados**; `requirements.mark-complete` devolve
  `not_found` para IDs de `REQUIREMENTS-vNN.md` e a marcação é do fechamento da fase.
- **`state.advance-plan` NÃO executado** (já corrompeu o `STATE.md` seis vezes); bloco aditivo à mão.
- **Nada deployado, nada empurrado, nenhum comando no VPS, nenhuma leitura de produção.**

### Pendências nomeadas

1. **A linha desenhar "Com erro"** — ligação escrita na §7; exige um plano que declare
   `publicacoes/` em `files_modified`. ⚠️ **A precedência entre o eixo `com_erro` e o status é
   decisão de UI**, não de servidor.
2. **`nova` no sentido estrito do protótipo** ("criadas agora") — exige a chave de flash declarada
   no `HandleInertiaRequests` (pendência que o plano 04 já nomeou). **Decisão de produto.**
3. **`sub_status` achatado + mapa de rótulos em pt-BR**, se a Fase 181 quiser mostrar QUAL erro.
4. **A margem da célula Preço** — camada assíncrona da Fase 181 (`MlbAlavancasController::analise`),
   intocada aqui.

---

## Self-Check: PASSED

| verificação | resultado |
|---|---|
| `app/Http/Controllers/MlbAnuncioController.php` existe | ENCONTRADO (4.639 linhas, eram 4.476) |
| `tests/Feature/Publicador/PublicacoesDaTelaTest.php` existe | ENCONTRADO (1.723 linhas, 46 métodos) |
| `.planning/phases/180-publicacoes-lista/180-04B-PLAN.md` existe | ENCONTRADO (303 linhas) |
| `.planning/phases/180-publicacoes-lista/180-04B-SUMMARY.md` existe | ENCONTRADO |
| commit `e86fd52f` (plano) existe | ENCONTRADO |
| commit `4d358a01` (Task 1) existe | ENCONTRADO |
| commit `37467b01` (Task 2) existe | ENCONTRADO |
| `php -l` nos 2 arquivos | sem erro de sintaxe |
| as 3 chaves no payload, por script com isca positiva e negativa | **37 chaves**; `preco`/`nova`/`com_erro` presentes; `margem` ausente |
| suíte `tests/{Unit,Feature}/Publicador` | **2085 passed / 15.960 assertions / 0 falhando** |
| fechamento aritmético | 2079 + 6 = 2085 ✓ e 15.887 + 70 + **3** = 15.960 ✓ (os 3 explicados na §6) |
| asserções REMOVIDAS em `tests/` | **0** (`+365 / -0`) |
| custo | `CONSULTAS_DA_ACTION` **intocado em 33**, com os dois casos de custo verdes |
| árvore depois das 7 mutações | `sha256` de volta ao pristino e `git status` sem os meus arquivos |
| scripts de campanha deixados no repositório | **nenhum** — todos vivem no scratchpad da sessão |
| `STATE.md` aditivo | ver `git diff --numstat` no commit desta SUMMARY — **0 remoções** |
