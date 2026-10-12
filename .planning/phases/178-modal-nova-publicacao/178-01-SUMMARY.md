---
phase: 178-modal-nova-publicacao
plan: 01
subsystem: api
tags: [laravel, publicador, modal, contrato-de-endpoint, fase-2, throttle, mutacao]

requires:
  - phase: 164-publicador-interno
    provides: "ProgramasPublicadorService (resolver, produtosParaTela, contagemProdutos, ehComposto, STATUS_NO_AR, rotuloFase) e as rotas de destino publicador.produto / publicador.editor"
  - phase: 175-fases-do-produto
    provides: "A lista ciente de fase (eh_kit, kits, rotulo_fase, url_produto) e a regra prontosParaFase2() da Visão geral"
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "O padrão de endpoint lazy do header (publicador.empresas-header) e o PublicadorHeader global nas 9 páginas"
provides:
  - "NovaPublicacaoService::grupos() — a classificação por próximo passo, nos três grupos do modal"
  - "GET mlb/anuncios/publicador/empresas/{conta}/nova-publicacao — {total, grupos[3]}"
  - "Rota nomeada mlb.anuncios.publicador.nova-publicacao (throttle:120,1 nomeado, role:admin)"
  - "tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php — 32 casos, provados por 14 mutações"
affects: [178-02-corpo-do-modal, 178-03-casca-do-modal, 178-04, 179-visao-geral]

tech-stack:
  added: []
  patterns:
    - "Endpoint lazy em vez de prop quando o dado é caro e só o modal consome (herdado de 177-03)"
    - "Regra de negócio `private` em outro serviço é COPIADA com citação arquivo:linha e gate de consistência por reflexão — nunca movida nem tornada pública"
    - "Gate que não pode ser o gate óbvio: rota provada por `resolver()` nunca chamado, custo provado por `->once()`"

key-files:
  created:
    - app/Services/Publicador/NovaPublicacaoService.php
    - tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php
  modified:
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "O payload manda a `chave` do grupo e NENHUM rótulo de UI: 'PRONTOS PARA FASE 2', o hint 'reaproveita a Fase 1' e o CTA 'Criar combo' são literais de tela e moram na wave 2 (mesmo padrão de GRUPO_RECENTES/GRUPO_TODAS do 177-06)."
  - "As três chaves de grupo vêm SEMPRE, inclusive vazias. 'Grupo sem item não aparece' (NOVA-03) é regra de RENDER, não de payload — e há 2 testes que reprovam quem 'consertar' isso no servidor."
  - "A regra de prontos_fase2 é copiada de PainelVisaoGeralService.php:592 (private) com citação no docblock, SEM o fallback por contagemProdutos: ali o fallback devolve um NÚMERO, e aqui o modal precisa de ITENS."
  - "Item com 6 campos fechados e todos escalares (produto_id int; nome/sku/nota/url string; capa string|null) — lição da tela preta de 07/10."
  - "Sem redirect de normalização de chave no endpoint: é JSON para axios, um 302 viraria resposta estranha. Segue com $alvo['chave'], a canônica."
  - "As notas do protótipo são dados MOCK; a divergência de CONTEÚDO está declarada (o dado não existe nessa forma). A geometria da nota é da wave 2 e fica intacta."
  - "Nenhum parâmetro de entrada além de {conta} — a busca é local no cliente (mesma disciplina de T-177-08)."

metrics:
  duration: ~75min
  completed: 2026-10-12
  tasks: 3
  arquivos_criados: 2
  arquivos_modificados: 2
  testes_novos: 32
  asercoes_novas: 178
  mutacoes: 14
  consultas_do_endpoint: 18

requirements-completed: [NOVA-07, NOVA-08, NOVA-10]
---

# Fase 178 Plano 01: o contrato do servidor do modal "Nova publicação" Summary

**Os três grupos do "próximo passo" de cada produto da conta — com nota de contexto e destino real
por item — vindos de UMA chamada à consulta que já existia, mais o endpoint lazy que o modal consome
na abertura.**

## Performance

- **Duração:** ~75 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 2 criados, 2 modificados
- **Commits:** 4 (RED, GREEN, endpoint, registro da mutação)

---

## ⚠️ O CONTRATO — é isto que a wave 2 lê para não adivinhar

**As chaves são exatas. A ordem dos grupos é contrato.**

```
GET /mlb/anuncios/publicador/empresas/{conta}/nova-publicacao
    {conta} = empresa-N | company-N        (trava na rota: (empresa|company)-[0-9]+)
    middleware: auth, verified, role:admin, throttle:120,1,publicador.nova-publicacao
    rota nomeada: mlb.anuncios.publicador.nova-publicacao
    NENHUM parâmetro de query. Nenhum corpo.

→ 200
{
  "total": int,                  // soma dos itens dos TRÊS grupos
  "grupos": [                    // SEMPRE 3, SEMPRE nesta ordem
    { "chave": "prontos_fase2",  "itens": [ <item>, … ] },
    { "chave": "sem_publicacao", "itens": [ <item>, … ] },
    { "chave": "rascunhos",      "itens": [ <item>, … ] }
  ]
}

<item> = {
  "produto_id": int,
  "nome":       string,
  "sku":        string,
  "nota":       string,          // nunca "", nunca "—"
  "capa":       string | null,   // já em -I.jpg (miniaturaDaFoto); NÃO mexer no sufixo
  "url":        string           // destino REAL, montado no servidor
}
```

### Três coisas que a wave 2 precisa saber antes de escrever JSX

1. **Os seis campos do item são TODOS escalares e são exatamente esses seis.** Nenhum array, nenhum
   objeto, nenhum campo a mais. A ordem das chaves é travada por asserção literal
   (`assertSame(self::CAMPOS_DO_ITEM, array_keys($item))`), e todo valor é varrido por
   `is_array`/`is_object`. É a lição de 07/10 ("Objects are not valid as a React child").
2. **Os três grupos vêm sempre, mesmo vazios.** "Grupo sem item não aparece" (NOVA-03) é **regra de
   render, da wave 2** — e o cliente vai precisar filtrar de qualquer jeito depois da busca local.
   Dois testes reprovam quem filtrar no servidor.
3. **Nenhum rótulo de UI está no payload.** O servidor manda `chave`. `"PRONTOS PARA FASE 2"`, o hint
   `"reaproveita a Fase 1"` e os CTAs `"Criar combo"` / `"Publicar Fase 1"` / `"Continuar"` são
   literais de tela e moram no cliente — exatamente como `GRUPO_RECENTES`/`GRUPO_TODAS` no 177-06.
   `test_payload_nao_carrega_rotulo_de_tela()` nega as chaves `rotulo`/`hint`/`cta` **e** varre o JSON
   pelos literais.

### O destino de cada grupo (NOVA-07)

| grupo | rota do `url` | o que o usuário vai fazer lá |
|---|---|---|
| `prontos_fase2` | `mlb.anuncios.publicador.produto` (`$p['url_produto']`) | o painel "Criar Fase N" da tela do Produto |
| `sem_publicacao` | `mlb.anuncios.publicador.editor` | preencher o rascunho da Fase 1 |
| `rascunhos` | `mlb.anuncios.publicador.editor` | retomar o rascunho |

⚠️ **Item sem destino usável NÃO entra no grupo.** `url_produto` só vem `null` quando o `$alvo` chega
sem a chave da conta; nesse caso o item de `prontos_fase2` é descartado em vez de ir para a tela sem
destino. É literalmente o que NOVA-07 proíbe, e tem teste próprio
(`test_item_de_prontos_fase2_sem_destino_fica_fora_do_grupo`).

---

## A classificação: quem entra em qual grupo

A fonte é **UMA** chamada a `ProgramasPublicadorService::produtosParaTela($e, $c, $chave)` — a
consulta mais pesada do módulo. Nunca duas, nunca `produtosQuery()` à mão. A `chave` é passada de
propósito: é ela que faz cada linha vir com `url_produto` pronto.

| condição medida | grupo |
|---|---|
| `eh_kit === false` **e** não composto **e** `status.chave ∈ {publicado, parcial}` **e** `kits === []` | `prontos_fase2` |
| `status.chave === 'rascunho'` (ou seja: **não existe** rascunho) | `sem_publicacao` |
| `status.chave === 'conferir'` | `rascunhos` |
| `status.chave === 'pronto'` | `rascunhos` |

**A ordem dentro de cada grupo é a que `produtosParaTela()` já devolve** (`atualizado_em` desc). O
serviço **não reordena** — reordenar faria o modal e a lista de Produtos discordarem sobre "o que
está no topo". `test_ordem_dentro_do_grupo_e_a_de_atualizado_em_desc` trava isso.

### ⚠️ As cinco EXCLUSÕES, declaradas no arquivo com o motivo

Estão escritas no docblock de `grupos()` para ninguém "consertar" depois. Cada uma tem teste.

| # | fica fora porque |
|---|---|
| 1 | `status.chave = 'publicando'` → **está em voo**; o próximo passo não é do usuário. Mostrar no modal convidaria a mexer no que está publicando. |
| 2 | `status.chave = 'erro'` (e o `parcial` que não entrou em `prontos_fase2`) → erro de publicação é **triagem**, e a casa dela é "O que fazer agora" da Visão geral (**Fase 179**). Este modal é a ENTRADA de uma publicação nova, não a fila de conserto. |
| 3 | no ar **com `kits` não vazio** → a base já tem a Fase 2 criada; **não espera mais nada**. |
| 4 | composto do Planejamento do Portal (`ehComposto`) → **KIT-06**: não é base de Fase 1 nem espera Fase 2. Vale em qualquer status. |
| 5 | produto **sem a chave `eh_kit`** → lista de shape antigo, **não classificável**. Melhor sair do modal do que entrar no grupo errado. |

### A regra de `prontos_fase2` é a MESMA da Visão geral — e isso é provado, não prometido

`PainelVisaoGeralService::prontosParaFase2()` está em
`app/Services/Publicador/PainelVisaoGeralService.php:592` e é **`private`**. O plano proíbe tocar
naquele arquivo, então a condição foi **copiada** condição por condição, com a citação
`arquivo:linha` no docblock de `grupos()`.

Duas definições diferentes de "pronto para a Fase 2" fariam a Visão geral e o modal discordarem sobre
o MESMO produto. Por isso existe `test_regra_de_prontos_fase2_bate_com_a_da_visao_geral()`: monta os
cinco casos de fronteira juntos (publicado, parcial, base-com-kit, composto publicado, draft), chama
a regra original **por reflexão** e compara o número com a contagem de itens do grupo. Se divergirem,
reprova.

⚠️ **O fallback por `contagemProdutos` ficou FORA, de propósito.** Aquele método tem o ramo
`$cienteDeFase ? … : contagemProdutos['publicados']` porque recebe listas de shape antigo. Aqui a
lista é sempre a de `produtosParaTela()` (ciente de fase), e o fallback devolveria um **número sem
itens correspondentes** — o modal não teria o que mostrar. No lugar dele: produto sem `eh_kit` é
**não classificável** (exclusão 5).

---

## As notas — e a divergência de CONTEÚDO declarada

| grupo | nota | de onde vem o dado |
|---|---|---|
| `prontos_fase2` | `"Fase 1 publicada · {n} no ar"`; `n === 0` → `"Fase 1 publicada"` | `count($p['anuncios'])` (itens CREATED com `ml_item_id`) |
| `sem_publicacao` | `origem='portal'` → `"Recebido do Portal do Cliente"` · `origem='publicador'` → `"Cadastro manual no Publicador"` · qualquer outra → `"Origem não informada"` | `pub_produtos.origem` |
| `rascunhos` | `pronto` → `"Conferido, pronto para publicar"` · `conferir` com `faltam > 0` → `"Rascunho em preenchimento · faltam {faltam}"` · `faltam === 0` → `"Rascunho em preenchimento"` | `EditorRascunhoService::prontidao()` |

Quando o produto é kit (`eh_kit === true`), a nota recebe `" · {rotulo_fase}"` no fim — e
`rotulo_fase` vem de `ProgramasPublicadorService::rotuloFase()`, **fonte única** do texto da fase
("Kit 2", "Kit 3"), para o mesmo produto nunca aparecer com dois rótulos na mesma tela.

### ⚠️ Divergência de CONTEÚDO declarada (restrição concreta, não preferência)

As notas do protótipo do layout — `"Fase 1 publicada · Clássico e Premium"`, `"Recebido hoje · Portal
do Cliente"`, `"Rascunho parado em Imagens"` — são **dados MOCK, não design**:

- **o sistema não sabe em que etapa o rascunho "parou"** (não existe coluna nem derivação de "última
  etapa tocada"); o que existe é a contagem de bloqueios da conferência (`faltam`);
- **não existe a data relativa naquele formato** ("Recebido hoje"); o que existe é `atualizado_em`
  (ISO), que é o que ordena a lista;
- `"Clássico e Premium"` seria o `listing_type_id` de cada anúncio — informação que **existe**, mas
  cuja tradução para rótulo de tela é literal de UI e não pode vir do servidor por decisão deste
  plano. A nota diz **"{n} no ar"**, que é medido e honesto.

É divergência de **conteúdo**, não de **layout**: a geometria da nota (11.5px, `pub-txt-3`) é
cumprida intacta na wave 2. E `test_nota_nunca_inventa_etapa_nem_usa_travessao_como_informacao()`
reprova nota vazia, `"—"` e qualquer nota que mencione "Imagens" ou "hoje".

---

## Por que ENDPOINT e não prop

O header global vive nas **9 páginas** do Publicador (177-03). Pendurar os três grupos na prop
`cabecalho` faria **toda** página pagar `produtosParaTela()` em **todo** request, inclusive para quem
nunca abre o modal.

O padrão já estabelecido nesta milestone é o do seletor de empresa (177-03 / 177-06): **endpoint
próprio, uma chamada por montagem, só quando o usuário abre.** O motivo está escrito no docblock da
action.

Decisões do endpoint, todas com o motivo no código:

- **Nenhum parâmetro de entrada** além de `{conta}`. Sem `q`, sem `grupo`, sem `limite`: a busca é
  **local no cliente** e qualquer parâmetro a mais seria superfície nova sem necessidade (mesma
  disciplina de T-177-08).
- **Sem redirect de normalização de chave.** `produtos()`/`visaoGeral()` redirecionam quando
  `$alvo['chave'] !== $conta`; aqui **não**: é JSON consumido por `axios`, e um 302 viraria resposta
  estranha. Segue-se com `$alvo['chave']` (a canônica), que é a que entra na `url` dos itens.
  `test_endpoint_aceita_chave_nao_canonica_sem_redirecionar` prova os dois lados: 200 **e** a URL do
  item já com `empresa-N`.
- **`->where('conta', '(empresa|company)-[0-9]+')`** na rota, como nas vizinhas: `{conta}` fora do
  padrão morre **antes** do controller.
- **`throttle:120,1,publicador.nova-publicacao`**, nomeado, como todos os vizinhos.
- A rota `publicador/empresas/{conta}/nova-publicacao` **não colide** com
  `publicador/empresas/{conta}/produtos/{produto}` (`nova-publicacao` não casa `[0-9]+`) nem com
  `publicador/empresas-header` (não tem hífen seguido de dígitos). Conferido por
  `artisan route:list | grep nova-publicacao` — 1 linha, nomeada.

---

## Custo medido

| Cenário (SQLite, 12/10/2026) | Consultas |
|---|---|
| `GET …/nova-publicacao` — 1 conta, **6 produtos** (2 por grupo), 3 anúncios | **18** |
| o mesmo com **12 produtos** | **18** |

O número **não muda** quando dobram os produtos: tudo é lido em lote. O teto do teste é
`assertLessThan(30)` e a prova de ausência de N+1 é a segunda asserção, `assertLessThan($seis + 6,
$doze)` — 6 produtos a mais, se houvesse consulta por linha, somariam no mínimo 6 consultas.

⚠️ **Cada conta é medida na SEGUNDA requisição**, depois de uma de aquecimento. Sem isso a primeira
conta medida saía com 2 consultas **a mais** que a segunda (o cache do `ProgramasPublicadorService`
persiste dentro do teste), o que produzia o absurdo "12 produtos custam menos que 6" e **esconderia a
comparação**. Está escrito no docblock do teste.

Além do detector por contagem, `test_a_consulta_pesada_roda_uma_vez_por_chamada()` cobra
`->once()->passthru()` em `produtosParaTela`: duas chamadas ainda caberiam no teto de 30, então a
contagem **sozinha** não provaria "uma chamada só".

---

## ⚠️ A prova por mutação — 14 mutações, 14 reprovações, nenhuma pelo motivo errado

Rodadas em **12/10/2026** no código REAL (`NovaPublicacaoService.php`, a action e a rota), com
restauração de **cópia pristina conferida por `sha256`** depois de cada uma.

Para cada mutação ficaram registradas três coisas: (a) que o padrão **casou** (diff não vazio),
(b) **qual linha** mudou — contra a armadilha de `sed`/`String.replace` trocar só a 1ª ocorrência ou
acertar um **comentário** em vez do código, que foi a origem de 3 dos "furos" da Fase 177 —, e
(c) **quais casos caíram**, porque "reprovou" sem saber qual caso caiu é um gate que pode estar
passando pelo motivo errado.

| # | mutação | reprovou em |
|---|---|---|
| 1 | tirar `&& ($p['kits'] ?? []) === []` da regra de `prontos_fase2` | `..._que_ja_tem_kit_nao_entra_em_nenhum_grupo`, `..._bate_com_a_da_visao_geral` |
| 2 | `STATUS_NO_AR` → `['publicado']` (perder o `parcial`) | `..._base_parcial_sem_kit_tambem_entra...`, `..._bate_com_a_da_visao_geral` |
| 3 | tirar a guarda `ehComposto` | `..._composto_do_planejamento_fica_fora...`, `..._bate_com_a_da_visao_geral` |
| 4 | pôr `publicando` dentro de `rascunhos` | `..._publicando_fica_fora_dos_tres_grupos` |
| 5 | pôr `erro` dentro de `sem_publicacao` | `..._erro_de_publicacao_fica_fora_dos_tres_grupos` |
| 6 | trocar a ordem dos grupos (`rascunhos` primeiro) | 6 casos (contrato + os 3 do endpoint) |
| 7 | devolver só os grupos não vazios (o "conserto" errado de NOVA-03) | **12 casos** |
| 8 | trocar a `url` de `prontos_fase2` pela do editor | `..._pronto_aponta_para_a_tela_do_produto...`, `..._chave_nao_canonica...` |
| 9 | deixar o item de `prontos_fase2` entrar com `url_produto` nulo | `..._sem_destino_fica_fora_do_grupo` |
| 10 | trocar a nota de "conferido" por `''` | `..._nota_de_rascunhos_diz_o_estado...` |
| 11 | mandar `anuncios` (array) dentro do item | `..._seis_campos_e_nenhum_e_array_ou_objeto`, `..._shape_do_contrato` |
| 12 | tirar o `abort_if($alvo === null, 404)` | `..._endpoint_devolve_404_para_conta_inexistente` |
| 13 | tirar o `->where('conta', …)` da rota | `..._rota_recusa_conta_fora_do_padrao_antes_do_controller` |
| 14 | chamar `produtosParaTela()` **duas** vezes | `..._a_consulta_pesada_roda_uma_vez_por_chamada` |

**Restauração final conferida:**

```
da2fae9cd410420240b9b8dc9988f5f1c59b828e78e7182b0df5231f512e5a40  NovaPublicacaoService.php
65dff92a892161f6b23bf9810ddb3005bfe21437b9e9862f56d9b5952ee44283  MlbPublicadorEntradaController.php
0db2ce5d944b2b1d9672be615121784e25a1ab40d3b47982207ea4c3ad93ced0  routes/mlb_anuncios.php
```
— idênticos às cópias pristinas tiradas antes da campanha.

### ⚠️ Três gates deste arquivo NÃO são o gate óbvio — não afrouxar

Este projeto já foi mordido quatro vezes por asserção que casa sempre. Três gates aqui nasceram
dessa lição, e a mutação provou que eram necessários:

1. **A rota (mutação 13).** O gate óbvio seria "deu 404". Mas `resolver()` **também** devolve 404 para
   chave desconhecida: `/empresas/empresa-abc/nova-publicacao` daria 404 **com ou sem** o
   `->where('conta', …)`. O gate real cobra que `resolver()` **nunca seja chamado** (mock com
   `shouldReceive('resolver')->never()`). Foi exatamente esse caso — e só esse — que a mutação 13
   derrubou.
2. **O custo (mutação 14).** O gate óbvio seria a contagem de consultas. Duas chamadas a
   `produtosParaTela()` caberiam no teto de 30. O gate real é `->once()`.
3. **A base com kit (mutação 1).** O kit do caso recebe um rascunho **PUBLICADO** de propósito: sem
   isso ele cairia em `sem_publicacao` e `total === 0` deixaria de ser asserção forte.

---

## Verificação

| Gate | Comando | Resultado |
|---|---|---|
| 1 | `phpunit tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php` | **32 / 32 OK**, 178 asserções |
| 2 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1848 testes / 11421 asserções / 0 falhas** (baseline 1816/11243 → exatamente +32 casos e +178 asserções, nenhuma regressão) |
| 3 | `artisan route:list \| grep nova-publicacao` | 1 linha: `mlb.anuncios.publicador.nova-publicacao` |
| 4 | `git diff da54a9ee..HEAD -- routes/ \| grep '^-'` | **vazio** (só adição) — idem no range `3a5f2e21..HEAD` |
| 5 | `git diff --stat da54a9ee..HEAD -- app/Support/Publicador app/Services/Mlb/Publicacao/MlPublicacaoService.php` | **vazio** |
| 6 | `git diff --stat da54a9ee..HEAD -- database` | **vazio** — nenhuma migration |
| 7 | `git diff --stat da54a9ee..HEAD -- resources/js/Pages/Mlb/Publicador/Editor.jsx resources/js/Components/Publicador/Mesa` | **vazio** (território de outro dev, ATIVO) |
| 8 | `git diff --stat da54a9ee..HEAD -- app/Services/Publicador/PainelVisaoGeralService.php` | **vazio** (a regra foi copiada com citação, não movida) |
| 9 | `git diff --diff-filter=D --name-only da54a9ee..HEAD` | **vazio** — nenhum arquivo deletado |
| T-178-SC | `git diff --stat da54a9ee..HEAD -- package.json package-lock.json composer.json composer.lock` | **vazio** — nenhum pacote instalado |

**⚠️ Os gates 4–9 e T-178-SC foram conferidos como NÃO-vazios por construção.** O mesmo range
`da54a9ee..HEAD`, aplicado a `app routes tests`, devolve exatamente os 4 arquivos deste plano
(1135 linhas, 0 removidas) — logo o range está vivo. E os quatro caminhos de território proibido
(`app/Support/Publicador`, `MlPublicacaoService.php`, `Editor.jsx`, `Components/Publicador/Mesa`)
foram conferidos como **existentes no disco** antes de acreditar no diff vazio deles. Todos os gates
de diff usam **range** (`<inicial>..HEAD`), nunca a árvore.

**⚠️ Nenhuma tela foi renderizada nem conferida visualmente neste plano** — ele não toca em `.jsx`
nenhum, e não há navegador neste ambiente. Toda a prova aqui é de contrato de servidor, por teste
automatizado.

**Sem migration, nada a validar no VPS.** O plano é 100% leitura: nenhuma coluna nova, nenhuma
chamada HTTP ao ML, nenhuma rota de escrita. As armadilhas de `enum`/índice/FK do MariaDB não se
aplicam. **Nenhum deploy, nenhum push, nenhum comando no VPS.**

### Registro de ameaças

| Threat ID | Como ficou mitigado | Prova |
|---|---|---|
| T-178-01 (IDOR) | grupo `['auth','verified','role:admin']` + `where('conta', …)` + `resolver()` + `abort_if(null, 404)` | 403 para não-admin, 404 para conta inexistente, e `resolver()` nunca chamado fora do padrão |
| T-178-02 (vazamento) | item com 6 campos fechados | corpo varrido por `APP_USR` e `TG-`; `assertArrayNotHasKey` em `access_token`/`refresh_token` no topo e em cada item |
| T-178-03 (DoS) | endpoint lazy + throttle nomeado + 1 chamada à consulta pesada | `->once()` e 18 consultas estáveis entre 6 e 12 produtos |
| T-178-04 (tela preta) | item só com escalares | chaves em array literal **e** varredura `is_array`/`is_object` em todo campo |
| T-178-05 (destino inventado) | `url` por `route()` no servidor | item sem destino não entra no grupo |
| T-178-SC | nenhum pacote instalado | gate de diff em `package*.json` / `composer*.json` vazio |

---

## Task Commits

| Task | Commit | O que entrou |
|---|---|---|
| 1 (RED) | `78d2ef9c` | `test(publicador): contrato dos tres grupos do modal "Nova publicacao" (178-01, RED)` — 32 casos, 24 erros + 6 falhas (a classe não existia) |
| 1 (GREEN) | `9270fa41` | `feat(publicador): NovaPublicacaoService classifica o proximo passo de cada produto (178-01)` |
| 2 | `aae3006b` | `feat(publicador): endpoint lazy GET .../nova-publicacao com rota nomeada (178-01)` |
| 3 | `f61a9ebe` | `test(publicador): registra a campanha de mutacao no contrato da Nova publicacao (178-01)` |

Todos com pathspec (`git add -- <caminhos>` e `git commit -F <arquivo> -- <caminhos>`), porque a
árvore é **compartilhada** com outro dev ativo e outras sessões. Nenhum `git add -A`, `git stash`,
`git reset`, `git checkout` nem `--amend`.

## Arquivos criados/modificados

- `app/Services/Publicador/NovaPublicacaoService.php` **(novo, 257 linhas)** — `grupos(array $alvo)`,
  único método público; const `GRUPOS`; docblock de classe com o que o serviço **não** faz, as cinco
  exclusões e a citação `PainelVisaoGeralService.php:592`
- `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php` **(novo, 834 linhas)** — 32 casos em 9
  seções: shape, regra de `prontos_fase2` (com o gate de consistência por reflexão), os outros dois
  grupos, as exclusões, o item, as notas, a ordem, o endpoint e o custo
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — **só adição**: a action
  `novaPublicacao()` e o `use` do serviço (nenhuma linha removida)
- `routes/mlb_anuncios.php` — **só a linha nova** `publicador.nova-publicacao`, ao lado de
  `publicador.empresas-header`

## Desvios do plano

### 1. [Regra 1 — correção de fixture] `pub_produtos.origem` é NOT NULL, então o ramo `default` é provado com uma origem desconhecida, não com `null`

- **Durante:** Task 1 (GREEN)
- **Arquivo:linha:** `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php:588-591`
- **Situação:** o plano diz *"qualquer outra coisa → `Origem não informada`"*. A primeira fixture
  usava `origem => null` e o SQLite recusou:
  `NOT NULL constraint failed: pub_produtos.origem`. A coluna é
  `string(12)` com `default('publicador')` (`database/migrations/2026_10_02_100000_create_pub_produtos_table.php:64`),
  **sem enum e sem CHECK**.
- **O que foi feito:** o caso passou a usar `origem => 'legado'` — uma origem fora das duas
  conhecidas, que é exatamente o cenário real do ramo `default` (uma fase futura acrescenta uma
  origem e a nota não pode virar vazia). O motivo está escrito no teste.
- **Impacto no contrato:** nenhum. O ramo `default` continua existindo e continua provado.
- **Commit:** `9270fa41`

### 2. [Regra 1 — gate que não provava nada] o detector de N+1 não podia exigir igualdade exata sem aquecimento

- **Durante:** Task 2
- **Arquivo:linha:** `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php:764-808`
- **Situação:** a primeira versão cobrava `assertSame($doze, $seis)`. Mediu **20** com 6 produtos e
  **18** com 12 — porque o cache do `ProgramasPublicadorService` persiste dentro do teste e a
  **segunda** conta medida chega com cache morno. O gate reprovava um código correto, e pior: a
  assimetria produzia a leitura absurda "12 produtos custam menos que 6".
- **O que foi feito:** cada conta passou a ser medida na **segunda** requisição (uma de aquecimento
  antes), o que dá **18 e 18**, e a asserção virou `assertLessThan($seis + 6, $doze)` — 6 produtos a
  mais somariam no mínimo 6 consultas se houvesse N+1. O teto absoluto ficou em 30 (medido: 18). O
  motivo do aquecimento está escrito no docblock.
- **Observação honesta:** a asserção foi **endurecida em um eixo e afrouxada em outro** — deixou de
  ser igualdade exata, mas ganhou um teto que a mutação 14 (chamada dupla) não passaria de qualquer
  modo, e a chamada única ganhou gate próprio por `->once()`. Nenhuma asserção foi apagada.
- **Commit:** `aae3006b`

### 3. [informativo] Task 3 não tinha artefato de código próprio

- **Situação:** o `<action>` da Task 3 pedia acrescentar ao teste o detector de N+1, o caso de shape
  antigo e o caso D23. Os três já tinham entrado nas Tasks 1 e 2 (eram parte do contrato que o teste
  cobra), então a Task 3 ficou sendo **só** a campanha de mutação — que não produz arquivo.
- **O que foi feito:** para a Task 3 deixar rastro **no repositório** (e não só nesta SUMMARY), o
  commit `f61a9ebe` escreve no docblock da classe de teste o resultado da campanha e, principalmente,
  **por que três daqueles gates não são o gate óbvio** — é a informação que a próxima pessoa precisa
  para não "simplificar" nenhum dos três.

**Total de desvios:** 3. **Impacto:** nenhum aumento de escopo; os dois primeiros existem para o
teste provar o que diz provar.

## Problemas encontrados

- **`git status --short -- tests/`** mostra `?? tests/Feature/CompanyPortfolioAccessTest.php`. **Não
  é meu** — nada a ver com o Publicador, e já estava não rastreado quando este plano começou. Não foi
  tocado, não foi commitado, não foi apagado (a árvore é compartilhada com outro dev ativo).
- **47 `PHPUnit Deprecations`** na suíte completa do Publicador (1 só no arquivo novo). É
  **pré-existente**: `CabecalhoDoPublicadorTest` sozinho, sem nenhuma mudança, já reporta 1. Fora de
  escopo deste plano (não corrigido de propósito).
- ⚠️ **`gsd-sdk query roadmap.update-plan-progress 178` respondeu `{"updated": true, "plan_count":
  4, "summary_count": 1, "status": "In Progress"}` e NÃO escreveu uma linha no `ROADMAP.md`** —
  conferido por `diff` contra cópia tirada antes de rodar, e por `git status --short --
  .planning/ROADMAP.md` (vazio). A Fase 178 não tem linha na tabela de progresso do ROADMAP, então o
  handler não tinha o que atualizar — mas reportou sucesso. É a mesma classe de armadilha do
  `requirements.mark-complete` devolvendo `not_found` em silêncio (já registrada na memória do
  projeto). **Quem fechar a fase precisa marcar o progresso à mão.** A entrada da fase no ROADMAP
  **não foi editada** (território proibido neste plano).
- `STATE.md` recebeu um bloco **aditivo escrito à mão** (45 linhas, **0 removidas**, conferido por
  `git diff --numstat` antes do commit) com a posição, as 6 decisões desta wave e o achado acima.
  `state.advance-plan` **não foi executado** — o arquivo já foi corrompido seis vezes seguidas pelos
  handlers `state.*` (o próprio topo do frontmatter registra as seis), e não existe bloco de
  milestone para a v26.0. `ROADMAP.md` e `REQUIREMENTS-v26.md` **não foram tocados**.
- Nenhum item para `deferred-items.md`.

## Perguntas em aberto (nada aqui bloqueia a wave 2)

1. **`listing_type_id` na nota de `prontos_fase2`.** O protótipo escreve "Clássico e Premium". O dado
   existe (`anuncios[].listing_type_id`), mas a tradução para rótulo de tela é literal de UI. Se o
   usuário quiser esse texto, a decisão é: o servidor manda a lista de `listing_type_id` (seria um
   **array** no item — e o contrato proíbe), ou o cliente traduz a partir de um campo escalar novo
   (ex.: `"gold_special,gold_pro"`). **Fica para a wave 2 levantar com o usuário**; a nota de hoje
   ("{n} no ar") é medida e honesta.
2. **Produto com `status.chave = 'erro'`** hoje não aparece em lugar nenhum no modal — por decisão
   deste plano, a casa dele é a Fase 179. Se a Fase 179 mudar de desenho, vale reconferir se o modal
   precisa de um quarto grupo (seria mudança de contrato, não de render).

## Prontidão para as próximas waves

**Pronto para a wave 2 (corpo do modal) consumir sem adivinhar:**

- O contrato completo está acima, com nomes de chave exatos e a ordem dos grupos.
- O cliente é dono de: os rótulos dos grupos, os hints, os CTAs, o filtro de grupo vazio (NOVA-03), a
  busca local sem acento (NOVA-04), o vazio (NOVA-05) e o rodapé (NOVA-06).
- O servidor é dono de: quem entra em qual grupo, a nota e a `url`. **Nada disso se recalcula no
  cliente.**
- `nome`, `sku` e `nota` continuam precisando passar por `textoSeguro()` no JSX (HEAD-12 é do
  consumidor, como no 177-03).

**Pronto para a wave 3 (casca):** o endpoint é `mlb.anuncios.publicador.nova-publicacao`, aceita só
`{conta}`, e espera **uma chamada por montagem do modal** — não por tecla digitada (a busca é local).

---
*Fase: 178-modal-nova-publicacao · Plano 01*
*Concluído: 2026-10-12*

## Self-Check: PASSED

- `app/Services/Publicador/NovaPublicacaoService.php` — existe
- `tests/Feature/Publicador/NovaPublicacaoDoHeaderTest.php` — existe
- `.planning/phases/178-modal-nova-publicacao/178-01-SUMMARY.md` — existe
- commits `78d2ef9c`, `9270fa41`, `aae3006b`, `f61a9ebe`, `1a607729` — todos no histórico
- rota `mlb.anuncios.publicador.nova-publicacao` — registrada (`artisan route:list --name=nova-publicacao` devolve 1 rota)
