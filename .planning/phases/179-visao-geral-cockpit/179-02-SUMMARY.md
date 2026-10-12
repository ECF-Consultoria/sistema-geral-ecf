---
phase: 179-visao-geral-cockpit
plan: 02
subsystem: api
tags: [laravel, publicador, visao-geral, acoes, severidade, adiamento, cache, mutacao]

requires:
  - phase: 173-visao-geral-do-publicador
    provides: "PainelVisaoGeralService::oQueFazerAgora() com a ordem fixa do handoff e teste de ordem"
  - phase: 175-fases-do-produto
    provides: "A regra prontosParaFase2() e a lista ciente de fase (eh_kit, kits, rotulo_fase)"
  - phase: 178-modal-nova-publicacao
    provides: "O precedente do contrato sem rótulo de tela e o descarte de item sem destino"
  - phase: 179-visao-geral-cockpit
    plan: 01
    provides: "JornadaDosProdutosService::jornada() — os 4 estágios, de onde nascem `sem_publicacao` e os sub-itens"
provides:
  - "AcoesDaContaService::acoes($alvo, $linhas, $jornada, $produtos, $userId) — severidade, ordem, contagens, sub-itens, adiadas"
  - "AcoesDaContaService::SEVERIDADE — a lista FECHADA de 12 chaves válidas (a que o 179-04 valida na borda)"
  - "AdiamentoDeAcoes::adiar/adiadas/expiraEm — por usuário e conta, no cache, sem tabela"
  - "PainelVisaoGeralService: `chave` em cada linha de oQueFazerAgora() e produto_id/produto_nome em ultimasPublicacoes()"
  - "tests/Feature/Publicador/AcoesDaContaTest.php — 43 casos, provados por 20 mutações"
affects: [179-03, 179-04-controller-visaogeral, 179-06-acoes-no-cliente, 179-07-ultimas-publicacoes, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "Serviço que CONSOME o serviço antigo em vez de reescrevê-lo: a linha chega pronta e é enriquecida, provado por contagem EXATA (count($linhas) + 1), não por assertContains"
    - "Persistência efêmera no cache com UM saco por escopo (usuário+conta), nunca uma chave por item: Redis não se varre por prefixo em request de tela"
    - "Escopo de autorização na CHAVE do cache, nunca no valor — isolamento por construção, não por filtro"
    - "Filtro por ENTRADA além do TTL do contêiner: TTL de saco é o do item mais distante e esconderia item já vencido"
    - "Chave desconhecida cai em severidade padrão e APARECE — fase futura não pode fazer item desaparecer em silêncio"
    - "Mutação prescrita que é equivalente por construção é DECLARADA como tal e substituída, nunca contada como reprovação"

key-files:
  created:
    - app/Services/Publicador/AcoesDaContaService.php
    - app/Services/Publicador/AdiamentoDeAcoes.php
    - tests/Feature/Publicador/AcoesDaContaTest.php
  modified:
    - app/Services/Publicador/PainelVisaoGeralService.php
    - tests/Unit/Publicador/PainelVisaoGeralServiceTest.php

key-decisions:
  - "O adiamento vive no CACHE, não em tabela: a milestone declarou nenhuma migration e perder um adiamento só faz a ação REAPARECER, que é o lado benigno da falha"
  - "UM saco por usuário+conta, nunca uma chave de cache por ação: varrer o Redis por prefixo em request de tela não se faz"
  - "`$userId` e `$contaChave` entram na CHAVE do cache, não no valor — o isolamento de T-179-06 é por construção"
  - "`tomorrow()` e não `now()->addDay()`: 'até amanhã' é o dia seguinte do calendário no fuso da aplicação (decisão de leitura, não de correção — ver a mutação M09)"
  - "O filtro por ENTRADA em adiadas() é obrigatório: o TTL do saco é o da entrada mais distante"
  - "Chave desconhecida cai em `atencao` e aparece; o que ela perde é só poder ser adiada"
  - "RULE 2: a ação nova `sem_publicacao` NÃO nasce quando a conta precisa de reconexão — sem a guarda ela furava a regra do handoff de UMA linha só"
  - "`descricao` não prefixa o número (o `numero` já é campo próprio e '1 produtos' é erro de concordância do protótipo); onde há um SEGUNDO número medido ele entra com singular/plural certos"
  - "A divergência `prontosParaFase2()` × estágio `ready` NÃO foi resolvida: as duas saídas mexem em comportamento aprovado em produção. Devolvida como decisão, com o raio de impacto medido"

metrics:
  duration: "~3h"
  completed: 2026-10-12
  tasks: 3
  tests_added: 45
  assertions_added: 509
  mutations: 20
  mutations_caught: 19
  mutations_equivalent: 1
  queries_in_service: 0
  migrations: 0
---

# Fase 179 Plano 02: "O que fazer agora" com severidade e adiamento Summary

O contrato de servidor do bloco central da Visão geral: cada linha que a tela já entrega hoje virou
uma ação com severidade, sub-lista de até 4 produtos reais e um adiamento que **sobrevive à
recarga** — guardado por usuário e conta no cache, expirando amanhã às 8h, **sem uma migration e sem
uma consulta nova**.

## O shape EXATO do retorno (é isto que o 179-04 liga e o 179-06 renderiza)

```php
[
  'acoes' => [                       // ordenadas: crítico → atenção → oportunidade
    [
      'chave'       => string,       // uma das 12 de SEVERIDADE (ou uma desconhecida, que aparece)
      'severidade'  => 'critico'|'atencao'|'oportunidade',
      'titulo'      => string,       // o `texto` da linha de hoje, BYTE A BYTE
      'descricao'   => string,       // frase medida; '' só para chave desconhecida
      'numero'      => ?int,         // o da linha; null em `reconectar`
      'recomendada' => bool,         // true SÓ na primeira da lista completa
      'itens'       => [ /* até 4 */ ['nome' => string, 'meta' => string, 'motivo' => string] ],
      'destino'     => array,        // o da linha, INTACTO: {rota,params} / {acao} / {acao,url}
    ],
  ],
  'contagens'    => ['todas' => int, 'critico' => int, 'atencao' => int, 'oportunidade' => int],
  'adiadas'      => [ /* chaves adiadas por este usuário nesta conta */ ],
  'volta_em_iso' => string,          // amanhã às 08:00 no fuso da aplicação
]
```

**A ordem das chaves é travada por `assertSame(array_keys(...))`** nos dois níveis (ação e item).
Campo a mais aqui é convite a inventar tela na wave 3.

## O mapa `chave → severidade` COMPLETO (a lista FECHADA de 12)

| `chave` | severidade | nasce de |
|---|---|---|
| `reconectar` | `critico` | linha única de `oQueFazerAgora()` com token fora de `'ativo'` |
| `com_problema` | `critico` | `contagemProdutos['com_problema']` |
| `pausados` | `atencao` | chips `pausado` + `sem_estoque` da triagem do acervo |
| `rascunhos_pendencias` | `atencao` | produtos `status.chave='conferir'` com `faltam > 0` |
| `ficha_incompleta` | `atencao` | chip do acervo (`sinais['ficha_obrigatoria']['ok'] === false`) |
| `perdendo_catalogo` | `atencao` | chip do acervo (buybox `losing` ou `sharing_first_place`) |
| `foto_insuficiente` | `atencao` | chip do acervo (`sinalFoto()` — nenhuma foto) |
| `prontos_fase2` | `oportunidade` | `prontosParaFase2()` |
| `conferidos` | `oportunidade` | `contagemProdutos['conferidos']` |
| `sem_publicacao` | `oportunidade` | **NOVA** — estágio `none` de `jornada()` |
| `portal_novas` | `oportunidade` | `situacaoPortal['situacao'] === 'novas'` |
| `alavancas` | `oportunidade` | `PubTarefa::abertasDaConta()` |

⚠️ **Chave desconhecida cai em `atencao` e APARECE.** Uma fase futura que acrescente linha em
`oQueFazerAgora()` sem mexer neste mapa não pode fazer a ação desaparecer da tela em silêncio —
regressão por omissão é a pior porque nada acusa. O que ela perde é só poder ser **adiada**.

### ⚠️ Para o 179-04 — a lista FECHADA de chaves válidas é SUA para validar

A mitigação de **T-179-05** (envenenamento da chave do cache) é **na borda**, e a borda é a sua
action. `AdiamentoDeAcoes::adiar()` já se recusa a gravar chave fora do mapa, mas isso é **defesa em
profundidade**, não a validação: ele falha em **silêncio**, e um request com chave inválida tem de
receber **422**, não 200 com nada acontecido. O `in_array` contra
`AcoesDaContaService::SEVERIDADE` (as 12 chaves acima) é seu.

Assinatura a ligar: `acoes(array $alvo, array $linhas, array $jornada, array $produtos, ?int $userId)`.
O `$userId` é `auth()->id()`; `$alvo['chave']` é o escopo de conta. Passar `null` em `$userId`
**desliga o adiamento** — nunca faça isso na tela.

### ⚠️ Para o 179-06 — o que é SEU e não sai do servidor

| Do servidor | Do cliente (por `chave`/`severidade`) |
|---|---|
| `chave`, `severidade`, `titulo`, `descricao`, `numero`, `recomendada`, `itens`, `destino`, `contagens`, `adiadas`, `volta_em_iso` | rótulo do CTA ("Criar combos", "Revisar", "Publicar"), rótulo da severidade ("CRÍTICO"), cores/`tint`, "RECOMENDADO AGORA", "Adiar até amanhã", "Nada pendente neste filtro" |

Mesmo precedente do `GRUPO_RECENTES` do 177-06, das chaves de grupo do 178-01 e dos estágios do
179-01. **Não existe chave `cta` no payload** e há asserção negativa contra os cinco literais de
tela do protótipo.

⚠️ **`volta_em_iso` existe exatamente para o rodapé do vazio.** O literal do protótipo é *"Ações
adiadas voltam amanhã às 8h."* (`new_layout_publicador/Visao Geral.dc.html:229`). Com o ISO você
formata a hora de verdade em vez de cravar "8h" no JSX — e se a `HORA_DA_VOLTA` do servidor mudar, a
tela acompanha sozinha.

⚠️ **O filtro por severidade é SEU.** O protótipo filtra no render e as contagens são sobre a lista
inteira (`:511-514`). O servidor manda tudo; não existe parâmetro de severidade (um teste trava a
lista de parâmetros de `acoes()`). E a `recomendada` já sai decidida sobre a lista **completa**:
filtrar não promove ninguém.

## A tabela "linha de hoje → ação de amanhã": nada regrediu

Gate central do plano, `test_toda_linha_de_hoje_virou_acao_com_contagem_exata()`. A saída **REAL** de
`oQueFazerAgora()` no cenário das dez linhas do ramo ativo entra em `acoes()`:

| Linha de hoje (`texto`) | `chave` | vira ação com |
|---|---|---|
| Anúncios pausados ou sem estoque | `pausados` | mesmo número, mesmo destino, mesmo texto no `titulo` |
| Produtos com problema na publicação | `com_problema` | idem + até 4 itens dos produtos em `erro` |
| Prontos para a Fase 2 | `prontos_fase2` | idem + até 4 itens do estágio `ready` |
| Conferidos, prontos para publicar | `conferidos` | idem + até 4 itens dos produtos `pronto` |
| Rascunhos com pendências | `rascunhos_pendencias` | idem + até 4 itens do estágio `draft` |
| Ofertas novas no Portal | `portal_novas` | idem |
| Ficha incompleta | `ficha_incompleta` | idem |
| Perdendo catálogo | `perdendo_catalogo` | idem |
| Foto insuficiente | `foto_insuficiente` | idem |
| Publicados aguardando alavancas | `alavancas` | idem |
| Conta precisa de reconexão | `reconectar` | linha ÚNICA → ação ÚNICA crítica |
| — (não existe hoje) | `sem_publicacao` | **NOVA**, do estágio `none` da Jornada |

⚠️ **Contagem EXATA** (`assertCount(count($linhas) + 1, $acoes)`), não `assertContains`: uma ação
engolida passaria por "contém". E um segundo gate cobra que **nenhuma** chave da entrada caiu no
default de severidade por acidente.

## As chaves do cache e a regra de expiração

| O que | Valor |
|---|---|
| Chave | `publicador:adiar:{userId}:{contaChave}` |
| Valor | mapa `chave_da_acao => ISO-8601 da expiração` |
| TTL do saco | o instante da entrada **mais distante** |
| Expiração da entrada | `Carbon::tomorrow(config('app.timezone'))->setTime(8, 0)` |
| Store | Redis em produção · `database` em dev · `array` nos testes |
| Migration | **nenhuma** |

⚠️ **`tomorrow()`, não `now()->addDay()`:** "Adiar até amanhã" é o **dia seguinte do calendário** no
fuso da aplicação, não 24 horas. (Honestamente: as duas expressões dão o **mesmo instante** porque o
`setTime()` normaliza a hora — ver a mutação M09. A escolha é de **leitura**, para quem lê o código
não precisar provar a equivalência.)

⚠️ **O filtro por ENTRADA em `adiadas()` não é redundante com o TTL do saco.** O TTL é o da entrada
mais distante, então uma entrada individual pode vencer com o saco ainda vivo: adiar `pausados` no
dia 12 às 23h (vence dia 13 às 08:00) e `com_problema` no dia 13 às 07h (vence dia 14 às 08:00) faz
o saco viver até o dia 14 carregando a entrada vencida de `pausados`. Sem o filtro, `pausados`
ficaria escondida depois das 8h do dia 13 — exatamente quando deveria voltar. Teste dedicado, e é a
armadilha do desenho de saco único.

### O custo honesto do cache

Um `cache:clear` no VPS apagaria **todos** os adiamentos. É tolerável por dois motivos. Primeiro,
eles duram **horas**, e perder um só faz a ação **reaparecer** — o lado benigno da falha: o usuário
vê trabalho que ainda existe, nunca deixa de ver trabalho que existe. Segundo, `cache:clear` **já é
operação proibida neste projeto** por um motivo muito pior: em **30/07/2026 ela derrubou o site
inteiro** — apagou o cache aquecido da Adman, o dashboard passou a esperar a API, as requisições
lentas ocuparam os workers do php-fpm e até o login parou. O procedimento correto é
`systemctl reload php8.2-fpm` (mais `adman:warm-diff`), que não toca no cache.

## As duas adições ADITIVAS em `PainelVisaoGeralService`

**Este arquivo tem UM dono na Fase 179: o plano 02.** A proibição das Fases 177/178 está suspensa
aqui, mas os planos 179-01/03/04 criam serviços novos e não encostam nele. Está escrito no docblock
de `oQueFazerAgora()` para a próxima sessão não duplicar.

**1. `chave` em cada linha, no FIM do array.** Por que ela existe, também no docblock: a `chave` é a
**identidade do adiamento**. Texto muda com o número ("2 publicações com erro" → "3 publicações com
erro"); a chave não. Um adiamento casado por texto se perderia na primeira publicação corrigida —
exatamente quando ainda deveria valer. Nas três linhas do laço de motivos a `chave` **é** o próprio
`$motivo`: uma segunda nomenclatura faria o adiamento e a triagem do acervo falarem nomes diferentes
do mesmo problema.

**2. `produto_id` (int) e `produto_nome` (?string) no fim do item de `ultimasPublicacoes()`.**
Colhidos do `join('pub_produtos')` que já existia — nenhuma consulta nova. O tile de 40px do layout
novo precisa dos dois: o `id` define o tom por hash e o `nome` as iniciais, e **nenhum dos dois sai
do `titulo`** (que vem do payload do ML, não do cadastro). Ordem final das chaves do item:
`titulo, ml_item_id, tipo, quem, quando, vendas, situacao, fase, rotulo_fase, produto_id, produto_nome`.

⚠️ **Nada de `capa` aqui** (plano 179-07): a foto já existe em `produtosParaTela()['capa']` e o plano
179-03 a encontra **em memória** por `produto_id`. Uma consulta nova de imagem por publicação
recente seria pagar duas vezes pelo mesmo dado.

Prova de que foi aditivo: no range `898d68c2..HEAD`, as **únicas 5 linhas removidas** do arquivo são
os 3 chamadores de `linhaDeProdutos()`, a assinatura dela e a linha do docblock dela. Nenhum literal
`'texto' =>` removido, nenhuma ordem mexida, nenhuma chave antiga alterada, **zero** asserções
removidas em `tests/` (com isca: 295 linhas com `assert` **acrescentadas** no mesmo range).

---

# ⚠️⚠️ DECISÃO PENDENTE DO USUÁRIO: os dois números de "Prontos para a Fase 2"

**A divergência que a wave 01 mediu NÃO foi resolvida. Não por omissão — por medição.**

## O que divergindo

`PainelVisaoGeralService::prontosParaFase2()` (`:625`) olha **só o status** (`STATUS_NO_AR` =
`publicado`/`parcial`). O estágio `ready` de `JornadaDosProdutosService` usa os **dois** ramos de
`bucketDaFase()`: status **ou** anúncio CREATED vivo. Logo, **base em `erro` com anúncio vivo e sem
kit** entra em `ready` e **não** entra na linha "Prontos para a Fase 2".

Na mesma tela, o anel dirá um número e a ação dirá outro.

## O raio de impacto, MEDIDO

1. **`prontosParaFase2()` é `private`.** O único chamador de produção é `oQueFazerAgora()` ela mesma
   (`PainelVisaoGeralService.php:496`). Grep em `app/`: nenhum outro.
2. **`NovaPublicacaoService` NÃO a chama — tem uma CÓPIA da regra** (`:118-133`, com a citação no
   docblock, porque lá o arquivo era proibido). É ela que monta o grupo "PRONTOS PARA FASE 2" do
   modal **que está em produção e foi aprovado pelo usuário**.
3. **Dois testes existentes dependem do número, por reflexão:**
   - `NovaPublicacaoDoHeaderTest::test_regra_de_prontos_fase2_bate_com_a_da_visao_geral()` (`:335`) —
     `assertSame(2, $daVisaoGeral)` + `assertCount($daVisaoGeral, itens('prontos_fase2'))`.
     ⚠️ **O cenário dele não tem produto em `erro`**, então alinhar `prontosParaFase2()` **não** o
     faria falhar — este gate de paridade é **vazio por construção para justamente este caso**.
   - `JornadaDosProdutosTest::test_divergencia_com_prontos_para_fase_2_e_medida_e_declarada()`
     (`:560-578`) — `assertSame(1, $daVisaoGeral, 'prontosParaFase2() não vê o anúncio vivo')`.
     **Este falharia**, e é arquivo da wave 01.

## Por que PARAR e não decidir

| Caminho | O que acontece | Veredito |
|---|---|---|
| **(a) Alinhar só `prontosParaFase2()`** | O modal da 178 continua com a regra antiga. A Visão geral passa a contar o produto em `erro`, o modal não. **Divergência REAL em produção entre duas telas sobre o MESMO produto** — exatamente o que o docblock da 178 existe para evitar. E quebra o teste da wave 01. | ❌ |
| **(b) Alinhar os dois** | Produto em `erro` com anúncio vivo entra no grupo "PRONTOS PARA FASE 2" do modal, **contra a Exclusão 2 declarada dele** ("erro de publicação é **triagem**, e a casa dela é o bloco 'O que fazer agora' da Visão geral"). Muda comportamento aprovado em produção e contradiz uma decisão escrita. | ❌ sem o usuário |
| **(c) Alinhar a Jornada para trás** | O produto que está **vendendo** com republicação falhada apareceria como "rascunho" no anel. A wave 01 já pesou e recusou. | ❌ |
| **(d) Deixar medido e perguntar** | Os dois números convivem, **travados por teste**, e a decisão vai ao usuário. | ✅ feito |

**Quantos produtos entram/saem?** No cenário sintético montado: **exatamente 1** (de 2 produtos).
Em produção, **não medido** — leitura de produção está bloqueada nesta sessão (plink/tinker/MCP). A
classe é estreita: base cujo rascunho está `FAILED` **e** que ainda tem item `CREATED` vivo **e** sem
kit **e** não composta. Se o usuário quiser o número real, é uma consulta na conta 459.

## A medida A MAIS, descoberta nesta execução

⚠️ A divergência **não fica só entre dois blocos da tela — ela aparece DENTRO da mesma ação.** A
sub-lista de `prontos_fase2` vem do estágio `ready` da Jornada (2 itens no cenário) enquanto o
`numero` vem da linha (1). **Abrir a ação mostra DOIS produtos sob um cabeçalho que diz UM.**

Não foi "consertado" de propósito: cortar os itens no número da linha **esconderia** da tela um
produto que está de fato no ar sem kit, e trocar a fonte do número mudaria o que `oQueFazerAgora()`
entrega hoje. As duas saídas mexem em comportamento aprovado.

## O teste que fica, em qualquer caminho

`AcoesDaContaTest::test_a_relacao_entre_o_anel_e_a_acao_de_prontos_para_fase_2_e_medida()` cobra:

- `assertSame(1, $daAcao)` — a ação conta pelo status
- `assertSame(1, $doAnel - $daAcao)` — **o tamanho exato da diferença**
- `assertNotSame(count($itens), $acao['numero'])` — a contradição dentro da ação

Se qualquer um desses mudar, o teste reprova com a mensagem mandando reler este bloco. **A próxima
sessão não vai achar que é bug novo.**

---

## Deviations from Plan

### [Rule 2 - Correção] A ação nova `sem_publicacao` furava a regra do handoff de UMA linha só

- **Encontrado durante:** o GREEN da Task 3, no teste `test_a_linha_unica_de_reconexao_virou_a_acao_critica`.
- **Problema:** token fora de `'ativo'` faz `oQueFazerAgora()` devolver **uma** linha (regra do
  handoff, com teste desde a Fase 173: *sem conta ativa, nada mais funciona*). A `sem_publicacao`
  nasce da **Jornada**, não das linhas — então ela aparecia **ao lado** de "Conta precisa de
  reconexão", oferecendo "publicar N produtos" numa conta cujo token o Mercado Livre vai recusar. Uma
  regra com teste desde 2026 furada por uma ação nova.
- **Correção:** guarda `temReconexaoPendente()`. A presença da chave `reconectar` na lista **é** o
  sinal — `oQueFazerAgora()` só a devolve nesse caso e sozinha. Nada de reler o token, que seria uma
  segunda fonte para o mesmo fato.
- **Verificado:** com a jornada **POVOADA** (`none.total = 5`) e uma asserção provando que ela
  **produziria** a ação se a conta estivesse ativa — asserção de ausência com dado vazio seria vazia
  por construção. A mutação M16 (remover a guarda) reprova exatamente nesse teste.
- **Commit:** `7fdd0a46`

### [Rule 1 - Bug de gate] A fronteira das 08:00 era provada pelo cache store, não pelo filtro

- **Encontrado durante:** a 1ª rodada de mutação — `isAfter($agora)` → `! isBefore($agora)`
  (manter a ação adiada **no instante exato** em que deveria voltar) **SOBREVIVEU**.
- **Causa:** `test_as_oito_de_amanha_a_acao_voltou` usa um saco de **uma** entrada, e o
  `ArrayStore::get()` do Laravel compara com `>=`
  (`vendor/laravel/framework/src/Illuminate/Cache/ArrayStore.php:93`) — às 08:00 em ponto o próprio
  store já esqueceu a chave. O teste passava por mérito do **store** e não provava nada sobre a
  comparação do filtro.
- **Correção:** a asserção da fronteira foi para o teste do saco de **duas** entradas, onde o saco
  vive até o dia 14 e a entrada de `pausados` vence no dia 13 às 08:00. Ali o store não ajuda.
- **Verificado:** com a asserção, M13 reprova.
- **Commit:** `1d72d707`

### [Desvio de plano] `$chave` entra em `linhaDeProdutos()` ANTES de `$fase`, não "no fim"

O plano prescreveu `string $chave` **no fim** da assinatura. `$fase` já era `?string $fase = null`, e
parâmetro obrigatório declarado **depois** de opcional é **deprecado no PHP 8** ("implicitly treated
as a required parameter") — somaria uma 49ª deprecation à suíte por um cheiro de código. Dar à
`$chave` um default `''` para empurrá-la ao fim resolveria a deprecation e abriria um buraco pior:
chamador novo que esquecesse a chave produziria linha com **chave vazia**, calada, que não endereça
adiamento nenhum. Obrigatória e sem default: esquecer não compila. Os 3 chamadores são internos e
foram atualizados no mesmo commit.

### [Desvio de plano] A mutação prescrita `tomorrow()` → `now()->addDay()` é EQUIVALENTE por construção

- **O plano a listou como uma das 12.** Medida: **sobreviveu**, inclusive com um teste que põe o
  `Carbon::setTestNow` em **UTC num dia de calendário diferente de São Paulo**.
- **Por quê:** o `->setTime(8, 0)` normaliza a hora, e o `Carbon::now()` do Laravel já está no fuso
  da aplicação (`date_default_timezone_set(config('app.timezone'))` no bootstrap). As duas expressões
  produzem o **mesmo instante** — não há defeito para o teste pegar.
- ⚠️ **Não contada como reprovação.** Substituída por duas mutações com valor real, as duas
  reprovam: **M09b** (as 8h → meia-noite, que quebra o contrato com o literal da tela) e **M09c**
  (`HORA_DA_VOLTA` 8 → 9).
- A escolha de `tomorrow()` fica, por **leitura**: quem lê o código não precisa provar a equivalência.

### [Desvio de plano] A 1ª versão da mutação da `recomendada` sobreviveu por erro do MEDIDOR

Ela apenas **prependia** o laço de marcação antes do filtro, sem remover o laço original — que
remarcava certo depois. Refeita como **duas** substituições (captura da primeira chave da lista
completa + marcação por comparação), e aí reprova em
`test_adiar_a_recomendada_promove_a_proxima`. Mesma classe das três mutações recusadas na Fase 178
por casarem em comentário: **a sonda errada mente a favor do código**.

### [Desvio de plano] Duas mutações prescritas não eram aplicáveis ao desenho entregue

| Prescrita | Por que não se aplica | Substituta (reprova) |
|---|---|---|
| "marcar `recomendada` na lista já filtrada **por severidade**" | O serviço **não tem** filtro por severidade — ele é do cliente, por decisão do próprio plano. | **M04**: marcar na lista completa, **antes do filtro de adiadas** |
| "recontar `sem_publicacao` a partir de `contagemProdutos`" | O serviço **não recebe** `contagemProdutos`, de propósito (`sem_oferta` é outra coisa). | **M06**: recontar pela **amostra** de `itens` em vez do `total` do estágio — o mesmo erro de fonte |

### [Desvio de plano] `descricao` não prefixa o número na frase

O plano pediu que `titulo`/`descricao` "interpolem número e nome de produto reais". O **nome** e os
números **secundários** (`legado`, `exemplo.faltam`) interpolam. O número principal **não**: ele já é
o campo `numero`, e prefixá-lo produz "1 produtos" — o erro de concordância que o protótipo comete
(`cnt.ready + ' produtos prontos para a Fase 2'`) e que não precisa chegar à tela. Onde há segundo
número, há singular e plural certos ("falta 1 campo" × "faltam 3 campos").

Cada `descricao` só afirma o que foi **medido**, conferido contra a fonte:
`ficha_incompleta` ← `sinais['ficha_obrigatoria']['ok'] === false`; `perdendo_catalogo` ← buybox
`losing` **ou** `sharing_first_place` (por isso "perderam **ou dividem**"); `foto_insuficiente` ←
`sinalFoto()`, que reprova quando **não há nenhuma foto** (o rótulo "Foto insuficiente" é mais suave
que a medição, e a descrição conta o que a medição diz); `alavancas` ← o checklist real de
`PubTarefa::CHECKLIST_ALAVANCAS`. Chave desconhecida devolve `''`: não há nada medido a dizer, e
inventar frase genérica seria enfeite (VG-18 — "não sabemos" não é "é zero").

### [Desvio de processo] As Tasks 2 e 3 num commit só, com o teste commitado ANTES

As duas são donas do mesmo arquivo de teste, e o `<verify>` da Task 2 é `--filter=AcoesDaContaTest` —
que inclui os casos da Task 3 e não pode ficar verde sem ela. Além disso `AdiamentoDeAcoes` lê
`AcoesDaContaService::SEVERIDADE` (a lista fechada de T-179-05). Mesma situação da wave 01. Ordem de
gate do TDD respeitada:

| Commit | O que | Gate |
|---|---|---|
| `32bfa4ae` | asserções da `chave` e do produto em `PainelVisaoGeralServiceTest` | **RED** — 5 falhas, conferidas uma a uma |
| `5e646b26` | `PainelVisaoGeralService` aditivo (Task 1) | **GREEN** — 18/18, 152 asserções |
| `cc422ba6` | `tests/Feature/Publicador/AcoesDaContaTest.php` | **RED** — 43 falhas por `class does not exist` |
| `7fdd0a46` | `AdiamentoDeAcoes` + `AcoesDaContaService` (Tasks 2 e 3) | **GREEN** — 43/43, 427 asserções |
| `1d72d707` | o fix do gate vazio por construção (fronteira das 08:00) | Rule 1 |
| `c8e0f983` | tirar as 43 `@test` redundantes (deprecations 94 → 51) | higiene |

### [Higiene] 43 anotações `@test` redundantes removidas

A suíte do Publicador saltou de 48 para **94** deprecations — +46, quando a wave 01 inteira custou
+1. Causa: 43 `/** @test */` em doc-comment neste arquivo. Todas **redundantes** (os 43 métodos já se
chamam `test_*` e o PHPUnit os descobre pelo prefixo), e o arquivo irmão da mesma fase
(`JornadaDosProdutosTest`) não usa nenhuma. Removidas, 61 testes dos dois arquivos seguem rodando.
As 3 deprecations que restaram acima da baseline são o `@group phase179` desta classe e os dois
`/** @test */` dos testes novos em `PainelVisaoGeralServiceTest` — que seguem a convenção do
**próprio** arquivo (ele já tem 19), e misturar dois estilos num arquivo só seria pior.

### Nenhuma outra: nada fora do escopo foi tocado

Desde `cd5add94` (HEAD da wave 01), **exatamente 5 arquivos** mudaram, todos deste plano.
`JornadaDosProdutosService.php` e `JornadaDosProdutosTest.php` (wave 01), `NovaPublicacaoService.php`,
`app/Support/Publicador`, `MlPublicacaoService`, `resources/js`, `routes`, `Editor.jsx`,
`Components/Publicador/Mesa`, `AppLayout.jsx`, `database`, `database/migrations`, `composer.*`,
`package*.json`, `ROADMAP.md` e `REQUIREMENTS-v26.md`: **diff vazio no range `898d68c2..HEAD`**, com
os 17 caminhos conferidos por `test -e` e a **isca** `git diff --stat 898d68c2..HEAD -- app tests`
devolvendo 7 arquivos / 3609 inserções — o vazio não é por construção.

## O custo medido

| O que | Medido |
|---|---|
| Consultas dentro de `acoes()` | **0** (`assertSame(0, …)`, não "menos que N") |
| Isca do mesmo teste | `ultimasPublicacoes()` numa Company real: `assertGreaterThan(0, …)` |
| Leituras de cache por chamada | **1**, travada por `->once()` com `Mockery` mesmo com as 12 ações |
| Chamadas HTTP ao Mercado Livre | **0** (`Http::preventStrayRequests()` em toda a classe) |
| Migrations | **0** |
| Pacotes instalados | **0** |

## A prova por mutação — 20 nominais, 19 reprovações, 1 equivalente

Processo: **contagem de ocorrências travada ANTES** (recusa se ≠ 1), **recusa de alvo que só casa em
comentário** (a armadilha da Fase 178), aplicação, **conferência de QUAL linha mudou** (impressa
antes/depois), execução da suíte, restauração de cópia pristina e **conferência por `sha256`** —
`ea2ef8de…cee9e` (`AcoesDaContaService`) e `cd6b60b9…d1eea` (`AdiamentoDeAcoes`) em todas as rodadas.

| # | Mutação | Linha | Reprovou em |
|---|---|---|---|
| M01 | inverter a ordem das severidades | 96 | `as_acoes_saem_ordenadas…` + 3 |
| M02 | `com_problema` → `atencao` | 77 | `cada_chave_tem_a_severidade_do_mapa` + 2 |
| M03 | `contagens` calculadas ANTES do filtro de adiadas | 174 | `acao_adiada_nao_aparece_e_nao_conta` |
| M04 | `recomendada` marcada na lista completa, antes do filtro | 174 | `adiar_a_recomendada_promove_a_proxima` |
| M05 | chave desconhecida DESCARTADA | 133 | `chave_desconhecida_cai_em_atencao_e_aparece` |
| M06 | `sem_publicacao` recontada pela AMOSTRA | 256 | `sem_publicacao_nasce_do_estagio_none_da_jornada` |
| M07 | `sem_publicacao` aparece com total 0 | 240 | `sem_publicacao_nao_aparece_com_estagio_none_zerado` + 2 |
| M08 | teto de itens 4 → 10 | 106 | `com_problema_traz_ate_quatro_itens…` |
| M09 | `tomorrow()` → `now()->addDay()` | 123 | ⚠️ **SOBREVIVEU — equivalente por construção** |
| M09b | as 8h → meia-noite | 123 | `expira_em_e_amanha_as_oito…` + 1 |
| M09c | `HORA_DA_VOLTA` 8 → 9 | 65 | `expira_em_e_amanha_as_oito…` + 1 |
| M10 | tirar o filtro por ENTRADA de `adiadas()` | 158 | `entrada_vencida_dentro_de_saco_ainda_vivo…` |
| M11 | tirar `$userId` da chave do cache | 128 | `adiar_guarda_por_usuario` + ponta a ponta |
| M12 | tirar `$contaChave` da chave do cache | 128 | `adiar_guarda_por_conta` + ponta a ponta |
| M13 | adiamento sobrevive AO instante 08:00 | 158 | `entrada_vencida_dentro_de_saco_ainda_vivo…` |
| M14 | remover a validação da lista fechada (T-179-05) | 79 | `chave_fora_da_lista_fechada_nao_e_gravada` |
| M15 | desempatar a ordenação por nome de chave | 161 | `a_ordem_dentro_da_severidade_e_a_de_entrada` + 2 |
| M16 | remover a guarda de reconexão | 149 | `a_linha_unica_de_reconexao_virou_a_acao_critica` |
| M17 | item sem nome/sku/motivo ENTRA na sub-lista | 415 | `item_sem_nome_ou_sem_sku_nao_entra_na_sub_lista` |
| M18 | remover a guarda `is_array` do saco corrompido | 146 | 15 testes (`ErrorException`) |

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `artisan test --filter=AcoesDaContaTest` | **43 passed, 428 assertions** |
| 2 | `artisan test --filter=PainelVisaoGeralServiceTest` | **18 passed, 152 assertions** |
| 3 | diff do `PainelVisaoGeralService` (range) — só o que a `chave` exigiu | ✅ 5 linhas, nenhuma de texto/ordem/chave antiga |
| 4 | `git diff 898d68c2..HEAD -- tests/ \| grep '^-' \| grep assert` | ✅ **vazio** (isca: 295 acrescentadas) |
| 5 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1925 / 12275 asserções / 0 falhas** (baseline medida ANTES de editar: 1880 / 11766 / 0) |
| 6 | `ListaPorFase`, `VisaoGeralSemComposto`, `TarefasNaConta`, `VisaoGeralTest`, `NovaPublicacaoDoHeader`, `JornadaDosProdutos` | ✅ verdes (113 testes / 878 asserções na rodada dedicada; e dentro do gate 5) |
| 7 | diff vazio em `database` / `database/migrations` | ✅ **nenhuma migration** |
| 8 | diff vazio em `resources/js` + `routes` | ✅ 100% servidor (a rota do adiamento é do 179-04) |
| 9 | diff vazio em `app/Support/Publicador`, `MlPublicacaoService`, `AppLayout.jsx`, `Editor.jsx`, `Mesa` | ✅ território do outro dev intacto |
| 10 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | ✅ vazio (nenhuma deleção) |
| 11 | os 17 caminhos proibidos existem no disco (`test -e`) + isca do range | ✅ (7 arquivos / 3609 inserções em `app tests`) |
| 12 | T-179-SC: `composer.json/lock`, `package.json/lock` | ✅ vazio (nenhum pacote instalado) |
| 13 | `JornadaDosProdutosService/Test` (wave 01) e `NovaPublicacaoService` | ✅ diff vazio desde `cd5add94` |
| 14 | testes removidos ou afrouxados | **ZERO** |
| 15 | `STATE.md` aditivo | ✅ `git diff --numstat` = **56 / 0** |

**Deprecations: 48 → 51.** As 3 são o `@group phase179` desta classe e os dois `/** @test */` dos
testes novos em `PainelVisaoGeralServiceTest`, que seguem a convenção daquele arquivo (19 iguais).
**JS não foi medido de novo porque `resources/js` tem diff vazio no range** — nada de JS pode ter
regredido. A falha pré-existente `estrutura-grade-glide:123` segue intocada.

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-05 Tampering (envenenamento de chave de cache) | mitigado | `adiar()` só grava chave do mapa `SEVERIDADE` (lista fechada, 12). ⚠️ A validação **dura** (422) é do 179-04 — aqui é defesa em profundidade e falha em silêncio. M14 reprova quem a remover. |
| T-179-06 Elevation of Privilege | mitigado | `$userId` e `$contaChave` na **chave** do cache, não no valor. Três testes de isolamento; M11 e M12 reprovam. |
| T-179-07 Denial of Service (cache inflado) | mitigado | saco só aceita as 12 chaves, expira, e as entradas vencidas saem a cada gravação |
| T-179-08 Repudiation (ação desaparecida) | mitigado | chave desconhecida cai em `atencao` e **aparece**; M05 reprova quem a descartar |
| T-179-09 Information Disclosure | aceito | nome, SKU e motivo de produto da própria conta; nada novo é exposto |
| T-179-SC | mitigado | nenhum pacote, diff vazio nos 4 manifests |
| **extra** (não estava no registro) | mitigado | `test_o_payload_nao_vaza_segredo_da_conta`: nem `access_token`/`refresh_token`, nem `APP_USR`, `TG-`, `client_secret` |
| **extra** (tela preta de 07/10) | mitigado | `array_keys` da ação travado; varredura `is_array`/`is_object` em todo campo exceto `itens`/`destino`; dentro de `itens`, tudo string |

## Known Stubs

Nenhum. Nenhum campo do payload é constante, placeholder ou valor de exemplo — todos saem das linhas
de `oQueFazerAgora()`, dos estágios da Jornada, de `produtosParaTela()` ou do mapa `SEVERIDADE`.
A `descricao` de chave desconhecida é `''` **por decisão declarada** (não há nada medido a dizer),
não por falta de implementação.

## Perguntas em aberto para o usuário

1. ⚠️ **A decisão de "Prontos para a Fase 2"** — o bloco grande acima. É a única que **bloqueia**
   algo: enquanto não houver resposta, a tela mostrará dois números defensáveis e diferentes para o
   mesmo conceito, e a sub-lista de uma ação mostrará 2 itens sob um cabeçalho que diz 1.
2. **A ação `sem_publicacao` entra no fim das oportunidades**, depois de "Prontos para a Fase 2",
   "Conferidos" e "Ofertas novas no Portal". É o que preserva a ordem fixa do handoff, mas no
   protótipo ela vem **logo depois** de "prontos para a Fase 2" (`:506-507`). Trocar a posição é uma
   linha — mas mexe na ordem que tem teste desde a Fase 173.
3. **`descricao` de `reconectar`** diz *"A conta do Mercado Livre perdeu o acesso. Nada mais desta
   tela é confiável até reconectar."* É honesto e é o motivo real do truncamento da lista, mas é a
   frase mais dura do bloco. Mudar o texto muda só este serviço.
4. ⚠️ **Nada foi visto renderizado** — não há navegador neste ambiente e nenhuma tela foi aberta.
   Toda a conferência é de teste automatizado; a validação visual é do usuário, em produção, na
   conta 459. **Nada deployado, nada empurrado, nada no VPS.**

## Self-Check: PASSED

- `app/Services/Publicador/AcoesDaContaService.php` — FOUND
- `app/Services/Publicador/AdiamentoDeAcoes.php` — FOUND
- `tests/Feature/Publicador/AcoesDaContaTest.php` — FOUND
- `app/Services/Publicador/PainelVisaoGeralService.php` — FOUND (modificado, aditivo)
- `tests/Unit/Publicador/PainelVisaoGeralServiceTest.php` — FOUND (modificado, aditivo)
- commits `32bfa4ae`, `5e646b26`, `cc422ba6`, `7fdd0a46`, `1d72d707`, `c8e0f983` — FOUND em `git log`
- os dois serviços idênticos ao commit depois das 20 mutações (`sha256` batendo, com a conferência
  abortando a rodada se divergisse)
