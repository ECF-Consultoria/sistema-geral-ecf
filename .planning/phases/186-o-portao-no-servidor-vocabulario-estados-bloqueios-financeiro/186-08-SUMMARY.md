---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 08
subsystem: publicador-aprovacao
status: COMPLETO — as 3 tarefas feitas. ⚠️ 1 gate de OUTRO plano (186-05) reprovou como colateral da mudança autorizada; conserto escrito em deferred-items.md, NÃO aplicado (fora do autorizado)
tags: [bloqueios, vocabulario, cadeia-exclusiva, reabertura, transacao-unica, imagens, gates, mutacao]
requires:
  - 186-02 (as 11 chaves de bloqueio em pt-BR, no módulo `.jsx`)
  - 186-03 (os conjuntos e as imagens da revisão)
  - 186-04 (a oferta do portão e a única porta de andamento)
  - 186-05 (histórico, ajustes e exceção autorizada)
  - 186-06 (o frete informado à mão)
  - 186-07 (a composição financeira: `incompleto`, `resultado`, `margem`, mínimo resolvido)
provides:
  - "app/Services/Publicador/Aprovacao/VocabularioDoPortao.php — o lado PHP das 9 abreviações, dos 8 rótulos de filtro e do mapa de conferência com o protótipo"
  - "app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php — os 8 bloqueios por oferta, com a cadeia EXCLUSIVA, o rodapé de BLOQ-12, `aprovavel` e `contagemDeFotos()`"
  - "app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php — aprovar (transação única), reabrir, conferir alteração, lote, COL-04, salvar revisão, tentar de novo"
  - "app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php — IMG-01 a IMG-10, com a galeria consistente por construção"
  - "PubAprovacaoOferta::TRANSICOES — o 13º par `aprovado → aguardando_aprovacao` (a REABERTURA)"
affects:
  - 186-09 (o gate (g) da sincronia PHP × JS agora tem os DOIS lados)
  - 188 (o botão "Aprovar para publicação" consome `aprovavel` + o motivo)
  - 192 (chama `concluirRegeneracao()` com a imagem que o Creative Engine produzir)
  - 193 (a publicação sai do gancho `aoAprovar()` da única porta — que é UM só)
tech-stack:
  added: []
  patterns:
    - "o PHP é a FONTE do vocabulário de bloqueio; o `.jsx` é o espelho, e a prova LÊ o arquivo"
    - "a exceção decide se o bloqueio é EMPILHADO, nunca a condição da cadeia"
    - "gate de máquina de estado afirma o CONJUNTO EXATO de pares e os separa por CLASSE (frente/trás), não só a contagem"
    - "a principal da galeria é a primeira da ordem POR CONSTRUÇÃO (recompactação depois de toda mudança)"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/VocabularioDoPortao.php
    - app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php
    - app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php
    - app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php
    - tests/Unit/Publicador/Aprovacao/BloqueiosDaOfertaServiceTest.php
    - tests/Feature/Publicador/Aprovacao/BloqueiosContraOPrototipoTest.php
    - tests/Feature/Publicador/Aprovacao/AprovarEReabrirTest.php
    - tests/Feature/Publicador/Aprovacao/RevisaoDeImagemTest.php
    - tests/gates/contagens-186-08.txt
  modified:
    - app/Models/PubAprovacaoOferta.php
    - tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php
    - tests/gates/contagens-186-04.txt
decisions:
  - "O 13º par `aprovado → aguardando_aprovacao` entra por decisão do usuário de 15/10/2026 — mudança relevante em oferta JÁ APROVADA faz ela VOLTAR para Aguardando aprovação"
  - "O gate de transições passa a afirmar o CONJUNTO EXATO e a separar par para FRENTE (classe perigosa) de par para TRÁS (seguro) — a contagem sozinha não distingue troca"
  - "A cadeia `frete → prejuízo → margem` é um `if/elseif/elseif` único, e a EXCEÇÃO entra no `push`, nunca na condição"
  - "O ponto de extensão da Fase 193 é UM SÓ (o da única porta): dois ganchos homônimos publicariam duas vezes"
  - "`publicado` continua TERMINAL — mexer na foto de anúncio no ar é a Fase 193 (decisão 13 do ROADMAP, EM ABERTO)"
metrics:
  tasks_completed: 3
  commits: 8
  tests_added: 65
  assertions_added: 1252
  mutations: 42
  mutations_killed: 41
  mutations_survived: 1
completed: 2026-10-15
---

# Fase 186 Plano 08: os 8 bloqueios, a reabertura e as regras de imagem — Summary

Os **8 bloqueios** nascem no servidor com a cadeia `frete → prejuízo → margem` **exclusiva**, o
bloqueio de fotos cobre **todos os grupos do rascunho**, a aprovação grava as **cinco** coisas numa
**transação só**, e as **8 mudanças relevantes** reabrem a aprovação — com mudança de foto
alcançando **todas** as ofertas do conjunto.

---

## ⚠️⚠️ O 13º PAR: a pergunta devolvida, a decisão, e o gate que ela exigiu

Na primeira passada este plano **parou e devolveu a pergunta**: `BLOQ-06` exige
`aprovado → aguardando_aprovacao`, e esse par **não estava** na lista fechada de
`PubAprovacaoOferta::TRANSICOES` (12 pares, pinados pelo gate `transicoes` do 186-04).
Acrescentá-lo era mexer na **única porta** e **reprovar gate de outro plano** — duas condições de
parada ao mesmo tempo.

**O usuário decidiu em 15/10/2026: saída (A), acrescentar.** *Mudança relevante em oferta JÁ
APROVADA faz ela VOLTAR para "Aguardando aprovação".*

### Por que o par é SEGURO, e por que a mensagem antiga do gate não servia

A frase que guardava a lista era *"transição a mais aqui publica sem ninguém ter conferido"* — e ela
descreve **par para FRENTE**, par que **aproxima a oferta do ar**. O par novo é **par para TRÁS**:
ele **afasta**, tirando a oferta de `aprovado` e devolvendo-a à fila de conferência — e, com isso,
deixando-a **`live` outra vez, logo EDITÁVEL** (`podeEditar()`). **Sem ele a oferta ficaria presa em
`aprovado`, não editável, com uma foto que ninguém conferiu** — e aprovar já publicou.

### ⚠️ Elevar 12 → 13 NÃO BASTOU, e é isso que o gate novo mede

**Treze pares podem ser alcançados por um par PERIGOSO substituindo um seguro** — a contagem não
distingue; o conjunto distingue. O gate `transicoes` passou a ter, em ordem:

1. **a CONTAGEM (13)** — primeira linha de defesa, mantida;
2. **o CONJUNTO EXATO** dos 13 pares, achatado como `"origem -> destino"`, **ordenado**, numa
   asserção, com mensagem reescrita que **distingue as duas classes**;
3. **a separação por CLASSE**, com um eixo de **avanço** (distância do ar) declarado e conferido
   contra os 8 andamentos:
   - **FRENTE (a classe perigosa), 7 pares pinados item a item:** `preparando → aguardando`,
     `aguardando/em_revisao/ajustes → aprovado`, `aprovado → publicando`, `falha → publicando`
     ("Tentar de novo" **aproxima** do ar — por isso `falha` fica num degrau **abaixo** de
     `publicando`), `publicando → publicado`;
   - **TRÁS (segura), 2:** `aprovado → aguardando_aprovacao` e `publicando → falha`;
   - **LADO, 4:** os quatro pares entre os três estados ao vivo;
4. ⚠️ **a afirmação precisa sobre o 13º par:** ele é o **único** par que sai de um estado
   **não-`live`** e **devolve a oferta a `live`** — isto é, o único que reabre a conferência e volta
   a deixar a oferta editável (`publicando → falha` também é para trás, mas `falha` não é `live`);
5. ⚠️⚠️ **a ISCA, em duas partes**, com o par forjado `aguardando_aprovacao → publicando`:
   - ele é **classificado como FRENTE** (senão a separação por classe não mediria nada) e **não
     está** na lista;
   - **par a MAIS** faz o conjunto reprovar; e **a prova de que a contagem não basta**: trocar o
     par seguro pelo forjado **MANTÉM 13** (`assertCount` explícito) e o conjunto **reprova**;
6. **o fora de escopo declarado:** nenhuma transição sai de `publicado`.

**Validado por mutação:** **M41** (remover o 13º par) morre em `decimo-terceiro-par` e em
`as-oito-mudancas`; **M42** (trocar por um par forjado para frente, mantendo o total) morre em
`transicoes` — ⚠️ **medido explicitamente depois de o M42 sobreviver ao gate errado** (ver a seção
de mutação).

### ⚠️ As linhas EXATAS que toquei no arquivo alheio

`tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php`, **um** método:
`test_as_transicoes_aceitas_e_as_recusadas_estao_declaradas`.

| o que | onde (antes) |
|-------|--------------|
| cabeçalho da seção: `12 pares` → `13 pares` | `:230` |
| o 13º par acrescentado a `$aceitos`, com comentário | depois de `:249` |
| o bloco da contagem + a mensagem reescrita | `:256-262` |
| **novo:** conjunto exato, eixo de avanço, classes, iscas, `publicado` terminal | depois de `:262` |

⚠️ **As camadas A e B** sobre quem escreve `andamento` (`:860-996`, a B acrescentada pelo
orquestrador em `0ba69bf3`) **não foram tocadas — nem enfraquecidas**. Os `$recusados`, a isca da
sonda de transição e a asserção das 8 chaves de origem também ficaram como estavam.
Contagem remedida no manifesto: **27 → 39 asserções**.

⚠️ **E eu NÃO precisei entrar na LISTA da camada A:** nenhum dos quatro serviços deste plano menciona
`'andamento' =>` nem atribui `->andamento =` (conferido por `grep` nos quatro). As chaves de saída
são `ao_vivo`, `aprovavel`, `chaves`, `kinds`, `bloqueios`, `resumo`, `composicao`.

---

## ⚠️⚠️ FORA DE ESCOPO DECLARADO: `publicado` é TERMINAL, e isso é da Fase 193

A decisão de 15/10 cobre a janela **entre aprovar e publicar**, que é o que `BLOQ-06` pede.
**Mexer na foto de anúncio que JÁ ESTÁ NO AR é outro fluxo**, e quem decide é a **Fase 193**
(decisão 13 do ROADMAP, **EM ABERTO**).

Como o código se comporta, declarado em três lugares (docblock de `TRANSICOES`, docblock de
`AprovacaoDaOfertaService` e um teste):

- `PubAprovacaoOferta::TRANSICOES['publicado'] === []` — **nenhuma** transição sai;
- mudança relevante em oferta `publicado` **incrementa a `versao`** e **não move o andamento** — o
  fato aconteceu e fica **registrado**;
- `aprovacaoReaberta()` passa a `true`, e **é isso que a Fase 193 vai ler** para saber que a foto
  mudou depois do ar.

Provado em `test_o_decimo_terceiro_par_e_o_unico_caminho_de_volta_e_a_reabertura_o_usa`: a publicada
continua `publicado`, com `versao` 2 e `aprovacaoReaberta() === true`.

---

## ⚠️⚠️ A TRANSAÇÃO ÚNICA, provada FORÇANDO a falha no meio

`aprovar()` grava, dentro de **UMA** `DB::transaction`:

1. o **andamento** (`live → aprovado`), pela **única porta**;
2. os **três campos** de aprovação (`aprovado_por`, `aprovado_em`, `versao_aprovada`);
3. o **evento `ok`** *"Aprovou para publicação (versão N)"*;
4. **`tocado = true`**;
5. o **congelamento da composição** (`congelar($o, 'aprovacao')`), com o mínimo **da época** e **de
   qual lado do fallback** ele veio.

⚠️ A `DB::transaction` de `mover()` roda **aninhada** (savepoint): a atômica é a de fora.

**O gate não confia em leitura de código.** `test_t186_34_a_falha_no_meio_da_aprovacao_nao_deixa_
oferta_aprovada_sem_prova` injeta um **dublê de `ComposicaoFinanceiraService` que lança em
`congelar()`** — o último passo, exatamente o cenário temido (andamento e `aprovado_*` já gravados,
prova ainda não). Depois da exceção, afirma que **nada ficou**: andamento de volta em
`aguardando_aprovacao`, `aprovado_por`/`aprovado_em`/`versao_aprovada` nulos, `tocado` falso, o
evento `ok` **ausente** e zero linhas em `pub_aprovacao_composicoes`. **Com ISCA:** com a composição
real, o mesmo cenário aprova e grava as cinco coisas.

**Validado por mutação:** **M25** (remover o congelamento) morre em `aprovar-e-transacao`.

---

## ⚠️⚠️ BLOQ-06 — as 8 mudanças, e a REABERTURA EM LOTE

| # | mudança | escopo | frase do registro (do `reopen()`, `:1149-1163`) |
|---|---------|--------|--------------------------------------------------|
| 1 | `foto_reprovada` | **conjunto** | `Aprovação reaberta: foto reprovada` |
| 2 | `foto_substituida` | **conjunto** | `… foto substituída por original` |
| 3 | `foto_regenerada` | **conjunto** | `… imagem regenerada` |
| 4 | `foto_adicionada` | **conjunto** | `… foto adicionada` |
| 5 | `fotos_reordenadas` | **conjunto** | `… ordem da galeria alterada` |
| 6 | `principal_alterada` | **conjunto** | `… foto principal alterada` |
| 7 | `preco_salvo` | oferta | `… preço alterado na revisão · de R$ … para R$ …` |
| 8 | `frete_confirmado` | oferta | `… frete do vendedor confirmado · {origem}` |

Cada uma, provada num laço com a cobertura afirmada (`assertCount(8, MUDANCAS)`): **versão +1**
(nunca +2), volta para `aguardando_aprovacao`, `ehLive()` de novo (**editável**),
`aprovacaoReaberta() === true`, e o **registro** com a frase e `(versão N)`. **ISCA:** chave fora
das 8 é **recusada** e nada muda.

### A reabertura em lote por mudança de foto

`test_bloq06_mudanca_de_foto_reabre_as_duas_ofertas_do_conjunto`: Clássico **e** Premium aprovados,
**UMA** reprovação de foto entrando pelo Clássico →
`registrarMudanca()` devolve **2 ofertas alcançadas**, as **duas** voltam para
`aguardando_aprovacao` com `versao` 2 e **cada linha do tempo** ganha o *"Aprovação reaberta: foto
reprovada"*. **ISCA:** entrando pela **irmã**, alcança as duas do mesmo jeito — a propagação não
depende de por onde a ação entrou.

E `test_bloq06_preco_e_frete_reabrem_so_a_oferta_deles`: o preço do Clássico **não** mexe no Premium
(eles têm preço separado) e o frete do Premium **não** mexe no Clássico.

⚠️ **O incremento duplo do frete, e por que o parâmetro feio existe.**
`PubAprovacaoFrete::informar()` (plano 186-06) **já incrementa a `versao`** na própria transação.
Somar outro aqui faria a confirmação de frete **pular duas versões**, e `versao_aprovada` ficaria
duas atrás — a tela diria "reaberta" duas vezes pelo mesmo ato. ➡️ `confirmarFrete()` passa
`versaoJaIncrementada: true`, e **as 8 mudanças continuam atravessando `registrarMudanca()`**, que é
o que mantém a propagação **num lugar só**. Provado com `assertSame(2, versao)` e validado pela
mutação **M29** (trocar para `false`), que morre.

---

## Os 8 bloqueios, com texto e ação de correção

| # | chave (pt-BR) | texto (do protótipo, `:1089-:1097`) | correção | cor |
|---|---------------|--------------------------------------|----------|-----|
| — | `em_preparacao` | `Sistema ainda {frase REAL do que falta}` | **vazia** (AUTO-01) | âmbar |
| 1 | `fotos_a_conferir` | `N foto(s) aguardando conferência[ · N com alerta automático]` | `Conferir fotos` | âmbar / **vermelho com alerta** |
| 2 | `sem_fotos` | `Nenhuma foto na publicação` | `Adicionar fotos` | vermelho |
| 3 | `frete_ausente` | `Frete do vendedor não confirmado · resultado indisponível` **ou** `{Rótulo} não confirmado · resultado indisponível` | `Informar frete` **ou** `Solicitar ajuste` | vermelho |
| 4 | `resultado_negativo` | `Resultado negativo: − R$ 103,57 por venda` | `Solicitar exceção` | vermelho |
| 5 | `margem_abaixo_do_minimo` | `Margem de 4,1% abaixo do mínimo de 12%` | `Solicitar exceção` | âmbar |
| 6 | `alteracao_nao_conferida` | `Preço alterado após a última conferência: R$ 799,90 → R$ 749,90` | `Conferir alteração` | âmbar |
| 7 | `ajuste_em_aberto` | `1 ajuste solicitado em aberto` / `2 ajustes solicitados em aberto` | `Ver ajustes` | âmbar |
| 8 | `titulo_longo` | `Título com 61 caracteres (máx. 60)` | `Ver conteúdo` | âmbar |

**A conta dos "8":** `info()` empilha **9** chaves; **oito** são coisa a consertar, e
`em_preparacao` é aviso de preparo com correção vazia. Está em `VocabularioDoPortao::OS_OITO`, com
asserção própria — deixar implícito **já me custou uma contagem errada** neste plano.

⚠️ **VOC-05:** toda linha sai com `glifo` `!` **e** `severidade`. ⚠️ **BLOQ-04 provado em CADA um
dos 8**: um cenário por bloqueio, com o bloqueio de fato presente antes da recusa, `RegraViolada`
com o código próprio, e **nada gravado** (nem andamento, nem `aprovado_*`, nem composição, nem
evento). **ISCA:** o cenário limpo aprova.

### A prova da cadeia EXCLUSIVA nos 3 casos

| caso | cenário (preço 100, comissão 11% real, imposto 19%) | obtido |
|------|------------------------------------------------------|--------|
| **frete ausente** | custo 80, **frete `null`** — se o frete existisse, daria **prejuízo** | **`[frete_ausente]`** · nem `resultado_negativo`, nem `margem_abaixo_do_minimo` |
| **prejuízo** | custo 80, frete 10 ⇒ resultado **−11,00** | **`[resultado_negativo]`** · `margem` **não nasce**, apesar de −11% < 12% |
| **margem baixa** | custo 65, frete 10 ⇒ resultado **+4,00**, margem **4,0%** | **`[margem_abaixo_do_minimo]`** · `loss` não nasce |

No caso 1 a asserção é `assertNull(resultado)` e `assertNull(margem)` — **não é zero, é ausência**.
**ISCA:** o MESMO custo com o frete informado produz `resultado_negativo`.

### ⚠️ O defeito REAL que a cadeia escondeu, e onde a exceção entra

Primeira redação: o `! in_array(...)` da exceção estava **dentro da condição** do `elseif`. O teste
**reprovou na hora**: autorizar o **prejuízo** fazia nascer `margem_abaixo_do_minimo` **no lugar
dele**, porque resultado negativo é **sempre** margem abaixo do mínimo. ➡️ A pessoa precisaria de
**DUAS** exceções para o **MESMO** fato, e o caso pior pediria o dobro do trabalho do caso leve.
**Conserto:** o **FATO** escolhe o elo; a exceção só apaga o bloqueio **daquele** elo — como no
protótipo (`!o.exc` junto do `push`, `:1093-1094`).

### `loss`/`margin` disparam sobre o valor ESTIMADO POR BAIXO

Em quatro passos observáveis: a embalagem **está** `Pendente`; **não** há `frete_ausente` (ela não
está nas **cinco** de `ANULAM_O_RESULTADO`); `estimadoPorBaixo === true` com a nota *"sem o custo de
embalagem"*; **e o bloqueio dispara sobre ele**. O valor é **otimista**, então a margem real é
**menor** — com aprovar-já-publica e sem desfazer, **não bloquear seria o erro grave**.

### O bloqueio de fotos cobre TODOS os grupos — com isca

Dois testes com **4 conjuntos** (3 variações + `GENERAL`), todas aprovadas **menos uma**: a pendente
no **3º grupo** e a pendente no **`GENERAL`** bloqueiam **as duas ofertas**, com `foco_imagem_id`
apontando para ela. **ISCA:** aprovando aquela, o bloqueio **desaparece** nas duas e o rodapé vira
`Fotos, preço e conteúdo conferidos`. ⚠️ `conjunto_id` **não** foi reintroduzido, e
`test_o_bloqueio_de_fotos_nao_faz_uma_consulta_por_grupo` exige **o mesmo número de consultas** com
**1** e com **12** grupos.

### BLOQ-08 — a exceção libera só prejuízo e margem, com fixture PREENCHIDA

Os **outros cinco** bloqueios montados de verdade antes da negativa (foto pendente numa variação,
frete presente, aviso de preço, ajuste aberto e título com 61) — **negativa com fixture vazia é
vazia por construção**. Depois da exceção de prejuízo os cinco ficam de pé. No espelho (margem baixa
com resultado positivo), a exceção de **prejuízo não serve** e a de **margem** libera.

---

## As regras de imagem (IMG-01 a IMG-10)

| REQ | o que ficou provado |
|-----|---------------------|
| **IMG-01** | reprovar tira a foto da galeria **na hora**, com as **três** marcas (`estado`, `posicao` nula, `fora`), e **a próxima vira principal** |
| **IMG-02** | imagem nova, **regenerada** ou original **recolocada** entra `pending` |
| **IMG-03** | substituir coloca a original **na MESMA posição**; **se já estava aprovada, MANTÉM** a aprovação; original de outro rascunho é recusada |
| **IMG-04** | aprovar grava **quem/quando**, marca `tocado` no conjunto **e nas duas ofertas**, registra — e **não reabre nada** |
| **IMG-05** | "Aprovar N sem alerta" aprova **só** as `pending` **sem** alerta; a **com** alerta fica, com as duas no mesmo cenário; ISCA tirando o alerta |
| **IMG-06** | as **6** ações de foto reabrem **todas** as ofertas do conjunto — laço com a cobertura afirmada contra `MUDANCAS_DE_FOTO` |
| **IMG-07** | reordenar e definir principal mantêm a galeria consistente; **ordem incompleta (ou com foto de fora) é RECUSADA**; ISCA com a ordem certa |
| **IMG-08** | recolocar volta para `pending`, sai de "Fora da publicação" e entra **no fim** |
| **IMG-09** | **as QUATRO** recusas em `regenerating`: aprovar, reprovar, substituir, regenerar de novo — e nada muda na foto nem na galeria; ISCA fora do estado |
| **IMG-10** | reprovar **exige motivo** (`''` e `'   '` recusados), e **nada é gravado** na recusa |

⚠️ **"A principal é sempre a primeira da ordem" é INVARIANTE DA ESCRITA, não regra a lembrar:**
`reposicionar()` reescreve as `posicao` como `0..n-1` e carimba `principal_imagem_id` com a imagem
da posição 0 **depois de toda mudança**. Galeria vazia ⇒ capa **`null`**, nunca a última conhecida.

⚠️ **MEDIDO e declarado:** imagem em `regenerating` **SAI do conjunto final** —
`PubAprovacaoImagem::NO_CONJUNTO_FINAL` são **só** `pending` e `approved` (regra do modelo do
186-03, somente leitura aqui). ➡️ A galeria cai de 2 para 1 enquanto ela é refeita, e o **slot é
MANTIDO** (`posicao` não é zerada) — é isso que faz a nova versão voltar ao **mesmo lugar**.
Recompactar ali perderia a posição e a foto voltaria no fim. Tem asserção própria.

⚠️ **A reabertura é DELEGADA**, e há gate lendo o código: `RevisaoDeImagemService` **não**
incrementa `versao`, **não** chama `mover()`, **não** escreve `andamento`, e tem **exatamente uma**
chamada a `registrarMudanca()` — com **isca de cada sonda** e a prova de que o **outro lado**
(`AprovacaoDaOfertaService`) tem o incremento e a transição de verdade, senão o gate estaria vazio
por construção.

---

## ⚠️ PUB-09 — o ponto de extensão é UM SÓ

> **O ponto de extensão `aoAprovar()` é preenchido pela Fase 193; nenhum segundo clique, nenhuma
> janela de desfazer — oferecidos e recusados em 14/10/2026.**

⚠️ **DIVERGÊNCIA DECLARADA, e é de segurança.** O plano pedia um `aoAprovar()` **neste** serviço.
`AndamentoDaOfertaService::aoAprovar()` **já existe**, reservado desde o 186-04, **dentro da
transação** que grava os três campos de aprovação e **impossível de contornar** (ela é a única que
escreve `andamento`). Criar um segundo gancho com o mesmo nome e o mesmo propósito é convite para a
Fase 193 preencher **os dois** — e o resultado seria **publicar duas vezes** o mesmo anúncio, sem
desfazer.

`test_pub09_existe_exatamente_um_aoaprovar_no_portao_e_ele_esta_vazio` varre o diretório e afirma
`['AndamentoDaOfertaService.php' => 1]`, com isca da sonda; e que o corpo do gancho **não** contém
`dispatch`, `Queue`, `MlPublicacaoService`, `FilaPublicacao` nem `Http::`. Os dois serviços novos
também não falam com o ML nem com o Creative Engine.

---

## O vocabulário PHP, e o gate de ausência transversal

`VocabularioDoPortao` é a **fonte**; o `.jsx` é o **espelho**, e a prova **LÊ o arquivo**
(`vocabularioDoPortao.jsx`, **não tocado**): as **9** abreviações, os **8** rótulos de filtro, o mapa
de conferência de **11** chaves, os **3** selos e os três literais, **byte a byte**, com isca do
parser. A bijeção vale nos **dois sentidos**, com os 3 só em `SHORT` e os 2 só em `BLK` nomeados, e
as chaves **inglesas** recusadas uma a uma — ⚠️ **nenhum `||` com as duas formas**. Os selos **não
foram recopiados**: `selosDeOrigem()` devolve `PubAprovacaoComposicao::ORIGENS_DA_LINHA`.

**Gate de ausência** de segundo clique / desfazer / estado de espera, sobre os **quatro** arquivos
de produção lidos **sem comentários**, com **isca de cada sonda** e ⚠️ **ISCA INVERTIDA** (o rótulo
`Aprovar para publicação`, o gancho `aoAprovar()`, a exceção `ativa => true` e
`cancelarPedidoDoCliente()` **não** podem casar). Sondas ancoradas no **SUJEITO**, não em palavra
solta. Contagem fixa (zero infratores) com a lista nomeada e isca de caminho fantasma.

---

## A tabela dos 10 estados do protótipo

⚠️ **"O protótipo ganha" vale para a FÓRMULA, os RÓTULOS e a ORDEM — NUNCA para os NÚMEROS.** As
taxas de `fin()` são ficção declarada por ele (`:1064-1066`). Valores das **fontes reais**:
`percentage_fee` **11** (Clássico) / **14** (Premium) das fixtures de 2026-10-01, imposto **19,0**
de `EstruturaPrecificacaoParametros::PADROES`. **Um `test()` por estado.**

| estado | andamento | pend. | resultado · margem | bloqueios **esperados = obtidos** (na ordem) |
|--------|-----------|-------|--------------------|----------------------------------------------|
| `o1` | aguardando | 0 | **+20,03 · 4,09%** | `[margem_abaixo_do_minimo]` |
| `o2` | aguardando | 0 | **+32,13 · 6,06%** | `[margem_abaixo_do_minimo]` |
| `o3` | aguardando | 3 (2 c/ alerta) | **−103,57 · −13,81%** | `[fotos_a_conferir, resultado_negativo, alteracao_nao_conferida]` |
| `o4` | aguardando | 3 (2 c/ alerta) | **`null` · `null`** | `[fotos_a_conferir, frete_ausente, ajuste_em_aberto]` |
| `o5` | em revisão ECF | 2 (1 c/ alerta) | +57,03 · 21,94% | `[fotos_a_conferir]` |
| `o6` | ajustes solicitados | 2 (1 c/ alerta) | +62,63 · 22,38% | `[fotos_a_conferir, ajuste_em_aberto]` |
| `o7` | falha | 0 | +108,03 · 22,05% | `[]` + `kind` `falha_na_publicacao` |
| `o8` | aprovado | 0 | +113,43 · 21,82% | `[]` (não `live`) |
| `o9` | publicando | 0 | +78,03 · 23,65% | `[]` (edição bloqueada) |
| `o10` | preparando | 0 | +88,23 · 24,52% | `[em_preparacao]` |

**As duas divergências medidas, com `arquivo:linha`:**

1. ⚠️ **`o1` INVERTE de aprovável para bloqueado.** `Aprovacao.dc.html:1064-1066` dá +R$ 70,82 /
   14,46% com as taxas de ficção; com as fontes reais são **+R$ 20,03 / 4,09%**. **Ganham as fontes
   reais.** (A diferença contra os +R$ 12,03 do 186-07 é a **embalagem**, que aqui nasce `Pendente`
   e não entra na soma.)
2. ⚠️⚠️ **A seção F do `README-v2.md` é PROSA.** Ela chama `o3` de *"margem baixa"*; pela cadeia
   exclusiva (`Aprovacao.dc.html:1092-1094`) ele dá **PREJUÍZO** (−R$ 103,57 / −13,81%), e
   `margem_abaixo_do_minimo` **não nasce**. **Só `info()` decide bloqueio.**

**ISCA obrigatória:** com as fontes reais **nenhum dos dez** é aprovável; a isca conserta o `o5` e
exige `aprovavel === true` + o rodapé verde, e prova que a **irmã** também perde o bloqueio de
fotos. **Degradação para `skip` EXPLÍCITO** sem o `.dc.html`, com **isca do predicado**.

---

## Os gates (23 ids no manifesto do plano, todos MEDIDOS)

`sh tests/gates/verificar-testes.sh 08 <id>`. O `--autoteste` do helper: **14 casos, 0 divergência**.

| id | testes / asserções | | id | testes / asserções |
|----|--------------------|-|----|--------------------|
| `vocabulario-php-e-jsx` | 3 / 140 | | `aprovar-recusas` | 2 / 79 |
| `cadeia-exclusiva` | 4 / 65 | | `aprovar-e-transacao` | 3 / 39 |
| `estimado-por-baixo` | 1 / 14 | | `as-oito-mudancas` | 3 / 99 |
| `excecao-libera-so-dois` | 2 / 30 | | `decimo-terceiro-par` | 1 / 9 |
| `achado-s2-todos-os-grupos` | 2 / 32 | | `lote-conferir-e-retry` | 4 / 53 |
| `galeria-e-foco` | 3 / 35 | | `salvar-revisao-e-gancho` | 3 / 32 |
| `titulo-change-adjust-prep` | 3 / 36 | | `img-aprovar` | 2 / 19 |
| `kinds-aprovavel-rodape` | 4 / 53 | | `img-reprovar-e-substituir` | 3 / 49 |
| `estados-o1-a-o5` | 5 / 104 | | `img-ordem-e-regenerando` | 3 / 41 |
| `estados-o6-a-o10` | 5 / 64 | | `img06-reabre-o-conjunto` | 2 / 70 |
| `tabela-isca-e-cobertura` | 2 / 44 | | `ausencia-e-territorio` | 4 / 115 |
| | | | `bijecao-manifesto` | 1 / 36 |

⚠️ **Nenhum gate chama `artisan test` ou `node --test` direto.** ⚠️ **Alternância grande = filtro de
classe:** os **65** métodos estão em **23 ids**, nenhum com mais de 5 métodos.

**A bijeção ids ↔ métodos** (sufixo `_do_plano_08` de propósito — 186-06 e 186-07 têm homônimos e o
`--filter` SOMA contagens) falha nos **dois sentidos**, com isca do casador e da sonda.
**65 métodos, 23 ids, zero órfãos, zero sem id.**

### ⚠️ Três contagens remedidas, duas delas de OUTRO plano

| manifesto | id | antes → agora | causa |
|-----------|----|---------------|-------|
| `contagens-186-04.txt` | `transicoes` | 27 → **39** | **autorizado**: o 13º par + o conjunto exato + as classes + as iscas |
| `contagens-186-04.txt` | `unica-porta` | 33 → **41** | ⚠️ **colateral dos meus 4 arquivos novos**: a **camada B** percorre `glob(app/Services/Publicador/Aprovacao/*.php)` e faz **2 asserções POR ARQUIVO** |
| `contagens-186-04.txt` | `oferta-do-portao` | 57 → **65** | o mesmo, porque o filtro dele **inclui** `unica-porta` |
| `contagens-186-08.txt` | `ausencia-e-territorio` | 83 → **115** | os gates transversais passaram a cobrir **4** arquivos de produção |
| `contagens-186-08.txt` | `bijecao-manifesto` | 20 → 24 → **36** | uma asserção por **linha** do manifesto |

⚠️ **Contagem que depende de QUANTOS ARQUIVOS EXISTEM é frágil por construção** — e as três estão
agora **declaradas como tal dentro do manifesto**, com a causa escrita. Nenhuma asserção foi
removida ou enfraquecida: só o **número** foi remedido. ⚠️ **Eu não vi essas duas na primeira
passada** (rodei só os gates do 186-08 e a suíte, que não confere contagem por gate) — a lição é
**reexecutar o manifesto dos planos vizinhos quando o plano cria arquivo em diretório que eles
varrem**.

---

## ⚠️⚠️ BLOQUEANTE para o fechamento: um gate do 186-05 reprovou (e eu NÃO toquei)

```
tests/Feature/Publicador/Aprovacao/AjustesEHistoricoTest.php:419  (gate `ligacao-e-so-ligacao`)
  ISCA DO DIFF: o modelo do plano 186-04 aparece como tocado — este plano não o altera
  Expected: ''   Actual: 'app/Models/PubAprovacaoOferta.php'
```

**NÃO é regressão de comportamento.** É uma **isca cuja premissa deixou de ser verdadeira**: o gate
mede com **range ABERTO** (`1c16d9e9..HEAD`) e usa como isca *"este plano não toca
`PubAprovacaoOferta.php`"*. `..HEAD` significa *"tudo desde o começo do 186-05"* — então a isca passa
a medir **os planos seguintes também**, e a mudança **autorizada** do 13º par a derrubou.
Conferido: `git log 1c16d9e9..HEAD -- app/Models/PubAprovacaoOferta.php` dá **um** commit, o
`1370046f`. **As outras 45 asserções daquele teste continuam passando** — inclusive as que importam
(`TRANSICOES` não removido do serviço de andamento, `DB::transaction` intacta, `mover()` não
reescrito, os dois ganchos presentes).

⚠️ **Não consertei porque está fora do autorizado:** o usuário autorizou, nominalmente, **só** o
gate `transicoes` e a linha dele no `contagens-186-04.txt`. Mexer num **terceiro** gate, de **outro
plano**, é outra coisa — e trocar o **sujeito de uma isca** é mudar o **conteúdo** do gate, não
remedir um número.

➡️ **O conserto está escrito, pronto para aprovação em um passo, em
`.planning/phases/186-.../deferred-items.md`**: trocar o sujeito da isca por um arquivo **intocado
no range** (medidos agora: `app/Support/Publicador/RegraViolada.php`, `app/Models/PubRascunho.php`,
`app/Models/PubImagem.php`). A isca existe para provar *"o `git diff` não devolve vazio para tudo"* —
**qualquer** arquivo intocado serve igual, **sem perder força**. A contagem do manifesto **não muda**
(`tests=1 assertions=46`), mas **remedir é obrigatório**.

⚠️ **Lição de classe NOVA (não é nenhuma das nove):** *gate com range ABERTO (`<partida>..HEAD`)
cuja isca é "ninguém mais toca neste arquivo" tem validade de UM plano.* Ele não mede o plano dele —
mede **tudo o que vier depois**.

### E um segundo achado, PRÉ-EXISTENTE e não meu

`guarda-sem-enum-sem-try` (também do `contagens-186-04.txt`) reprova por **colisão de homônimo**: o
filtro casa `…_idempotente` **e** `…_idempotente_por_tabela`, em arquivos de planos diferentes, e
**soma** as contagens (medido: `2 passed (44 assertions)` contra `1 / 20` no manifesto). Os dois
testes passam; o que está errado é a contagem. **Não toquei** — é manifesto e arquivo de outro
plano, e não foi este plano que quebrou. Registrado em `deferred-items.md`.

---

## Campanha de mutação — 42 mutações, 41 mortas, 1 sobrevivente INVESTIGADA

**Protocolo (as duas rodadas):** commit **antes** de mutar · cópia guardada **fora do repositório**
· ocorrência **travada em 1 ANTES** (por `perl` em modo slurp, porque âncora multi-linha daria 0 no
`grep` e eu leria como "no-op") · **linha conferida depois** · restauração **da cópia**, em
`trap … EXIT`, com **`sha256sum` conferido contra `git show HEAD:<arquivo>`**.
⚠️ **`git checkout` NÃO foi usado em momento nenhum.**

**Rodada 1 (bloqueios e vocabulário) — 22 mutações, 21 mortas.** M2 a M22 morreram: cadeia virando
`if` independente no elo da margem, título `>` → `>=`, fotos lendo só o primeiro conjunto (o achado
S2), exceção de margem liberando prejuízo (o achado S7), `aprovavel` sem o `live`, `+N` contando o
total, reprovada voltando a contar como pendente, severidade sem contexto, embalagem anulando o
resultado, galeria ignorando `fora`/`posicao`, foco ignorando o alerta, o texto do frete mentindo,
alerta em foto aprovada voltando a contar, abreviação parafraseada, chave inglesa virando chave de
dado, `VERMELHOS` perdendo `sem_fotos`, `TUDO_CONFERIDO` parafraseado, o `kind` de falha olhando o
estado errado, ajuste deixando de bloquear, a frase de preparação parafraseada e o aviso de preço
deixando de bloquear.

**Rodada 2 (aprovação, reabertura e imagens) — 20 mutações, 20 mortas:**

| # | mutação | morreu em |
|---|---------|-----------|
| M23 | aprovar deixa de conferir os bloqueios | `aprovar-recusas` |
| M24 | aprovar deixa de conferir o `live` | `aprovar-recusas` |
| M25 | a transação perde o congelamento | `aprovar-e-transacao` |
| M26 | COL-04 deixa de recusar versão velha | `lote-conferir-e-retry` |
| M27 | a reabertura alcança só a oferta de entrada | `as-oito-mudancas` **e** `img06-reabre-o-conjunto` |
| M28 | o preço passa a alcançar o conjunto | `as-oito-mudancas` |
| M29 | o frete passa a incrementar duas vezes | `as-oito-mudancas` |
| M30 | conferir alteração deixa de limpar o aviso | `lote-conferir-e-retry` |
| M31 | salvar revisão conta `x` como se fosse `y` | `salvar-revisao-e-gancho` |
| M32 | "Tentar de novo" passa a incrementar a versão | `lote-conferir-e-retry` |
| M33 | reprovar aceita motivo vazio | `img-reprovar-e-substituir` |
| M34 | a guarda de `regenerating` deixa de recusar | `img-ordem-e-regenerando` |
| M35 | a capa deixa de seguir o topo da ordem | `img-reprovar-e-substituir` **e** `img-ordem-e-regenerando` |
| M36 | substituir zera a aprovação da original | `img-reprovar-e-substituir` |
| M37 | "Aprovar N sem alerta" aprova as com alerta | `img-aprovar` |
| M38 | reordenar aceita ordem incompleta | `img-ordem-e-regenerando` |
| M39 | reprovar não tira a foto da galeria | `img-reprovar-e-substituir` |
| M40 | a delegação da reabertura é removida | `img06-reabre-o-conjunto` **e** `img-reprovar-e-substituir` |
| M41 | o 13º par é removido | `decimo-terceiro-par` **e** `as-oito-mudancas` |
| M42 | o 13º par é trocado pelo par **FORJADO para frente** | ⚠️ **`transicoes`** — ver abaixo |

### ⚠️ M42: sobreviveu ao gate ERRADO, e foi investigada em vez de presumida

M42 acrescenta `aguardando_aprovacao → publicando` (o par forjado **para frente**) e **sobreviveu**
ao gate `decimo-terceiro-par`. **Investigado:** aquele gate afirma a linha de `aprovado` e o
terminal `publicado` — um par forjado em **outra** linha não é assunto dele. Quem protege contra
isso é o gate `transicoes` (plano 04), que foi **reescrito exatamente para isso**. ➡️ Remutei
apontando para lá: **`Tests: 1 failed (14 assertions)` — MORREU.** Não era lacuna do sistema; era
**mutação apontada para o gate errado**.

### ⚠️ M1 (rodada 1): o único sobrevivente de verdade — mutante EQUIVALENTE

Trocar o `elseif` do elo do prejuízo por um `if` independente sobreviveu a tudo.
**Investigado e não presumido:** `ComposicaoDaOferta::montar()` devolve `resultado`/`margem`
**`null`** sempre que `incompleto`, e `null < 0` é `false` em PHP ⇒ comportamento idêntico **para o
primeiro elo**. ➡️ O que dá para medir é o **INVARIANTE**, e ele está preso em asserção
(`assertSame(incompleto, resultado === null)` + a existência do código de regra
`FIN-05-INCOMPLETO-COM-RESULTADO`). ⚠️ O `elseif` **FICA**: ele é a intenção, e a redundância é a
rede. ⚠️ O elo **prejuízo → margem**, esse **é** observável — M2 morreu nele.

---

## Dois falsos negativos MEUS, achados pelos próprios gates

1. ⚠️ **O dublê de `DadosEfetivosService` era global por classe.** A primeira redação guardava um
   conjunto único de custos, e o **último** `cenario()` montado sobrescrevia os anteriores. No teste
   do **lote** (que monta três cenários e só então avalia os três) a oferta "com bloqueio" era
   avaliada com a conta da oferta **limpa** e **virava apta**. O gate ficou vermelho e **estava
   certo**: era o teste medindo o cenário errado. ➡️ O dublê passou a ser **por produto**.
2. ⚠️ **Duas asserções de reabertura mediam evento que nunca sai.** A reabertura **só REGISTRA** quem
   estava `aprovado` (é o `if (o.status === 'approved')` do `reopen()`, `:1149`); depois da primeira
   ação a oferta já está `aguardando_aprovacao`, e o registro do passo seguinte não existe. ➡️ Os
   testes passaram a **reaprovar entre as ações**, de propósito e com o motivo escrito.

---

## Divergências do plano, declaradas

| # | o plano dizia | o que está no código, e por quê |
|---|---------------|---------------------------------|
| 1 | a ordem dos grupos segue `posicao` | **`pub_aprovacao_conjuntos` NÃO tem `posicao`** (medido em `2026_10_15_100000_…:185-191`). Ordem: `GENERAL` primeiro, depois por **`id`** — determinística, que é o que importa |
| 2 | o texto de `freight` é sempre "Frete do vendedor não confirmado" | Aqui **cinco** linhas anulam o resultado. A **FORMA** do protótipo é preservada; muda **qual fonte é nomeada**, e a correção passa a `Solicitar ajuste`. O rótulo do filtro já previa: *"Frete **ou custo** ausente"* |
| 3 | um `aoAprovar()` **neste** serviço | **UM só**, o da única porta — dois ganchos homônimos fariam a Fase 193 **publicar duas vezes**. Com gate contando |
| 4 | o evento da aprovação ganha *" com exceção autorizada"* | A frase é montada por `HistoricoDaOfertaService::registrarTransicao()` (**wave 3, somente leitura**) e `mover()` sempre a registra. As alternativas eram **reabrir serviço de outra wave** ou **gravar um SEGUNDO evento para o mesmo fato** — o defeito que o 186-05 consertou. ➡️ A informação fica **MAIOR**: o evento `exc` (quem/quando/justificativa/versão) vem **imediatamente antes** do `ok`, e há teste provando a **ordem** e que a versão da exceção casa `versao_aprovada` |
| 5 | "salvar preço" | `registrarPrecoSalvo()` **não grava o preço** (é do editor, que esta fase não toca): registra a **consequência** — versão, reabertura, histórico e a limpeza do aviso |
| 6 | as regras de imagem em `BloqueiosContraOPrototipoTest` | Arquivo **próprio** (`RevisaoDeImagemTest`), porque aquele arquivo é o **teste tabelado dos 10 estados** pela `<action>` da tarefa 3; juntar faria o `--filter` medir uma mistura |
| 7 | `VocabularioDoPortao` traz "os 3 selos" | Traz como **método** que **LÊ** `PubAprovacaoComposicao::ORIGENS_DA_LINHA` — recopiar criaria duas verdades |
| 8 | expor `andamento` na saída (⇒ camada A) | **Não expõe.** A camada A **não foi tocada**, e o `contagens-186-04.txt` não precisou mudar por isso |

---

## Baselines

| suíte | antes | agora | delta |
|-------|-------|-------|-------|
| **PHP** (`--suite php`) | 2269 / 18.717 / **0** (186-07) | **2334 / 19.969 / 1** | +65 testes, +1.252 asserções |
| **JS** (`--suite js`) | 4777 / 4776 pass / 1 fail | **4777 / 4776 pass / 1 fail** | inalterado — nenhum arquivo JS tocado |

⚠️ **A única falha de PHP é a ISCA do gate `ligacao-e-so-ligacao` do plano 186-05**, descrita na
seção BLOQUEANTE acima: **colateral da mudança autorizada**, **não** regressão de comportamento, com
o conserto escrito e **não aplicado** por estar fora do autorizado. Sem ela a suíte está em
**2334 / 20.013 / 0** (medido no meio do plano, antes de o 13º par ser commitado).

A única falha de JS é a **pré-existente** `tests/js/estrutura-grade-glide.test.js` (*"Características
secundárias nasce recolhido"*, Fase 87) — ⚠️ **não corrigida de propósito**.

---

## Território

- `git diff abe4ac34..HEAD --name-only` dá **exatamente** os arquivos deste plano: os 4 serviços, os
  4 testes, os 2 manifestos, o modelo `PubAprovacaoOferta.php` (autorizado), o
  `AndamentoDaOfertaServiceTest.php` (autorizado, **um** método) e os artefatos de planejamento.
  **Zero deleções.**
- `AndamentoDaOfertaService`, `HistoricoDaOfertaService`, `AjustesDaOfertaService`,
  `ComposicaoFinanceiraService`, `PubAprovacaoFrete`, `DadosEfetivosService` e `SimuladorVoceRecebe`
  foram **CHAMADOS**, nunca alterados.
- `app/Support/Publicador/`, `app/Services/Creative/`, `PublicadorCriativoAprovacaoService`, a
  retenção de 48 h, `MlPublicacaoService`, o editor, `AppLayout.jsx`, `Bling*`, `Portal/`,
  `estrutura-meus-anuncios.test.js` (D-11/D-22) e o módulo `vocabularioDoPortao.jsx`: **diff vazio**.
- ⚠️ **A fronteira do D-11, declarada:** ele vale para a **LISTA de Publicações**, que nunca escreve
  no ML; a **FILA** escreve, mas **não neste plano** — a escrita é da **Fase 193**. Conferido por
  **SEMÂNTICA**, não por número de linha. **Não estendido nem afrouxado.**
- **Nenhuma** migration criada ou rodada. **Nenhum** pacote instalado. **Nenhuma** chamada à API real
  do ML (`Http::preventStrayRequests()` + fixtures). **Nenhuma leitura de produção. Nenhum deploy,
  push ou comando no VPS.**
- `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md` e `.planning/STATE.md` **não** tocados;
  `state.advance-plan` **não** rodado.
- `vendor/bin/pint` não rodado. Nenhum worktree. `git add -A`, `git stash`, `git reset`,
  `git checkout`, `git clean` e `--amend` **não** usados; todo commit por **pathspec**, com `-F`.
- ⚠️ **Não vi tela renderizada** — a conferência visual é do usuário, em produção, conta 459.

---

## TDD Gate Compliance

Os commits ficaram em pares `feat` → `test` que deixam a árvore **verde em cada ponto**, ou seja
**RED-antes-de-GREEN não aparece no `git log`**. ⚠️ **Mas os momentos RED existiram e três deles
eram defeito REAL**, todos registrados no código com a data:

1. a exceção **dentro da condição** do `elseif`, que promovia `margem_abaixo_do_minimo` ao autorizar
   o prejuízo (conserto no `BloqueiosDaOfertaService`);
2. o **dublê global** de `DadosEfetivosService`, que fazia o lote avaliar a oferta errada;
3. as duas asserções de reabertura medindo **evento que nunca sai**.

---

## Known Stubs

Nenhum. Os quatro serviços são completos para o que fazem.

## Threat Flags

Nenhuma superfície nova além do `<threat_model>` do plano. Os mitigadores:

| Threat | Como ficou |
|--------|-----------|
| **T-186-32** (aprovar com bloqueio) | `aprovavel = live && zero bloqueios` **no servidor**, com `RegraViolada` própria, provado nos **8** |
| **T-186-33** (aprovar numa versão velha) | COL-04 com código de regra **próprio** e contexto (`versao_vista`/`versao_atual`); as 8 mudanças reabrem; foto reabre **todo** o conjunto |
| **T-186-34** (aprovação sem prova) | `DB::transaction` única, provada por **falha forçada** no congelamento |
| **T-186-35** (exceção liberando demais) | BLOQ-08 com negativa nos cinco, **fixture preenchida**, nos dois sentidos |
| **T-186-36** (`publicado` sem MLB) | AND-04: este serviço só leva a `aprovado`; `ml_item_id` nulo asseverado |
| **T-186-37** (XSS no motivo) | string simples; nenhum `dangerouslySetInnerHTML` (área nova é do 186-09) |
| **T-186-38** (composição por linha no lote) | uma leitura por oferta, fora de laço aninhado; **3 consultas fixas** de foto, com gate de contagem com 1 e 12 grupos |
| **T-186-SC** (composer) | **nenhum** pacote instalado |

## Self-Check: PASSADO (com a ressalva do gate alheio)

Os 9 arquivos criados e os 3 modificados conferidos no disco e no `git`; os 8 commits conferidos por
`git log`; os **23** gates do plano reexecutados pelo helper **depois** da última restauração; os
**33** gates do `contagens-186-04.txt` reexecutados (passam, menos o `guarda-sem-enum-sem-try`
**pré-existente**); os cinco arquivos de produção com `sha256` **idêntico** ao de `git show HEAD:`
(nenhum resíduo de mutação).
⚠️ **Ressalva declarada:** a suíte PHP fica com **1 falha** — a ISCA do gate `ligacao-e-so-ligacao`
do plano 186-05, colateral da mudança autorizada, com o conserto escrito e **não aplicado**.
