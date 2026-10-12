---
phase: 179-visao-geral-cockpit
plan: 01
subsystem: api
tags: [laravel, publicador, visao-geral, jornada, contrato-de-servidor, anel, mutacao]

requires:
  - phase: 164-publicador-interno
    provides: "ProgramasPublicadorService (resolver, produtosParaTela, contagemProdutos, ehComposto, STATUS_NO_AR, rotuloFase, bucketDaFase) e as rotas publicador.produto / publicador.editor / publicador.produtos"
  - phase: 175-fases-do-produto
    provides: "A lista ciente de fase (eh_kit, kits, rotulo_fase, url_produto) e a regra prontosParaFase2() da Visão geral"
  - phase: 178-modal-nova-publicacao
    provides: "O precedente do contrato sem rótulo de tela, o descarte de item sem destino e as notas montadas só de campo medido"
provides:
  - "JornadaDosProdutosService::jornada($alvo, $produtos) — os 4 estágios exclusivos e totais, o universo, a cobertura F1/F2 × C/P e os destinos"
  - "tests/Feature/Publicador/JornadaDosProdutosTest.php — 32 casos, provados por 12 mutações"
affects: [179-05-controller-visaogeral, 179-06-jornada-no-cliente, 179-09-composicao-da-pagina, 179-10-fechamento]

tech-stack:
  added: []
  patterns:
    - "Serviço de leitura que NÃO consulta: a lista pesada chega pronta do controller (molde de oQueFazerAgora/noArPorFase), provado por gate de 0 consultas COM ISCA"
    - "Classificação por if-else encadeado com DEFAULT em vez de match fechado: status novo cai no último estágio em vez de desaparecer e quebrar a totalidade"
    - "Total contado no UNIVERSO, nunca somando os baldes — senão o gate de totalidade é verdadeiro por construção"
    - "Divergência conhecida entre duas regras é MEDIDA por teste (o tamanho exato da diferença), não escondida nem uniformizada"

key-files:
  created:
    - app/Services/Publicador/JornadaDosProdutosService.php
    - tests/Feature/Publicador/JornadaDosProdutosTest.php
  modified: []

key-decisions:
  - "O universo do anel são os produtos BASE não compostos: o kit É a Fase 2 do base e contá-lo somaria o mesmo produto duas vezes"
  - "`erro` cai em `draft` (divergência declarada do protótipo): um quinto caso fora dos quatro faria o `N de M produtos` mentir"
  - "'No ar' tem UMA definição (STATUS_NO_AR ou anúncio CREATED vivo) — a de bucketDaFase(); a divergência com prontosParaFase2() é medida por teste"
  - "O `total` é contado no universo, não somando os quatro estágios: somá-los tornaria o gate de totalidade vazio por construção"
  - "Nenhum rótulo de tela no payload — nem `rotulo`, `sub`, `cta`, `passo` ou cor; só `chave`, números, itens e URLs"
  - "A nota do `none` usa os TRÊS textos do 178-01 em vez do 'Sem publicação ainda' do plano: o literal prescrito contém o rótulo de tela que o próprio gate do plano nega"
  - "`draft` recebe 4 status e ganhou nota por status: dizer 'conferido, pronto para publicar' para uma tentativa que FALHOU seria falso na tela"

metrics:
  duration: "~2h"
  completed: 2026-10-12
  tasks: 3
  tests_added: 32
  assertions_added: 345
  mutations: 12
  mutations_caught: 12
  queries_in_service: 0
---

# Fase 179 Plano 01: Jornada dos produtos (servidor) Summary

O contrato do servidor do anel de 176px: quatro estágios exclusivos e totais sobre os produtos base
não compostos, com percentual sem divisão por zero, até 6 itens por estágio, cobertura F1/F2 ×
Clássico/Premium lida dos anúncios CREATED reais e destino montado por `route()` — **sem uma única
consulta nova**.

## O shape EXATO do retorno (é isto que o 179-05 liga e o 179-06 renderiza)

```php
[
  'total'         => int,            // produtos base não compostos da conta (o M do "N de M produtos")
  'publicados'    => int,            // ready + done (o N)
  'pct'           => int,            // round(publicados / total * 100); 0 quando total === 0
  'tem_produtos'  => bool,           // total > 0 — o gatilho do empty state
  'proximo_passo' => 'ready'|'none', // 'ready' quando ready > 0, senão 'none'
  'estagios' => [                    // EXATAMENTE 4, nesta ordem
    [
      'chave'         => 'none'|'draft'|'ready'|'done',
      'total'         => int,        // conta o UNIVERSO do estágio, não a amostra de itens
      'share_pct'     => int,        // round(total do estágio / total * 100); 0 quando total === 0
      'itens'         => [ /* até 6 */ [
          'produto_id' => int,
          'nome'       => string,
          'sku'        => string,
          'nota'       => string,    // nunca '', nunca '—'
          'capa'       => ?string,   // miniatura -I.jpg já resolvida por produtosParaTela()
          'cobertura'  => ['f1_classico' => bool, 'f1_premium' => bool,
                           'f2_classico' => bool, 'f2_premium' => bool],
          'cta_chave'  => 'publicar_fase1'|'continuar'|'criar_combo'|'ver_publicacoes',
          'url'        => string,    // destino REAL, montado no servidor
      ] ],
      'ver_todos_url' => ?string,    // null quando $alvo['chave'] é nula (D23)
    ],
    // …draft, ready, done
  ],
]
```

**A ordem das chaves é travada por `assertSame(array_keys(...))`** nos três níveis (topo, estágio,
item) e nas quatro células da cobertura. Campo a mais aqui é convite a inventar tela na wave 3.

### ⚠️ Para o 179-06 (quem desenha) e o 179-05 (quem liga no controller)

⚠️ **Correção de endereço:** o `<output>` do `179-01-PLAN.md` manda "deixe escrito para o 179-05",
mas o **179-05 é a ligação na action `visaoGeral()`** e quem **desenha** a Jornada é o **179-06**
(objetivos medidos nos dois planos). O que está abaixo é para o **179-06**; ao 179-05 interessa só
que a prop sai deste serviço, sem transformação no caminho.

**TODO rótulo de tela é do 179-06.** Do servidor vêm só `chave`, `total`, `share_pct`, `itens`, `ver_todos_url`, `cta_chave` e
`cobertura`. **Nada** de `step`, `label`, `sub`, `cta`, `cor`, `title`, `psub` ou `rowCta` — são
literais de UI e moram no cliente, como `GRUPO_RECENTES` do 177-06 e as chaves de grupo do 178-01.
O mapa literal está medido no protótipo (`new_layout_publicador/Visao Geral.dc.html:464-469`):

| `chave` | `step` | `label` | `sub` | CTA do cartão | `cta_chave` do item → rótulo da linha |
|---|---|---|---|---|---|
| `none`  | 01 | Sem publicação | Aguardando a Fase 1 | Publicar | `publicar_fase1` → "Publicar Fase 1" |
| `draft` | 02 | Em andamento | Rascunhos no Publicador | Continuar | `continuar` → "Continuar" |
| `ready` | 03 | Fase 1 publicada | Prontos para a Fase 2 | Criar combos | `criar_combo` → "Criar combo" |
| `done`  | 04 | Fase 1 + Fase 2 | Metodologia completa | Ver | `ver_publicacoes` → "Ver publicações" |

⚠️ **ARMADILHA MEDIDA para o 179-06 — o "+N" da pilha de tiles.** O cartão mostra **até 4** tiles
(`list.slice(0, 4)`, `:478`) e o contador `'+' + (list.length - 4)` é calculado sobre a lista
**INTEIRA** do estágio, não sobre as 6 linhas do painel (`list.slice(0, 6)`, `:488`). Como `itens`
vem cortado em 6, **`+N` tem de sair de `estagio.total - 4`, nunca de `itens.length - 4`** — senão um
estágio com 13 produtos mostraria "+2" em vez de "+9". Os tiles em si saem dos 4 primeiros `itens`.

⚠️ Segunda armadilha: o `grow` da barra segmentada é `Math.max(cnt[k], 0.0001)` (`:479`) — estágio
com zero produtos recebe um `flex-grow` minúsculo, **não zero**, senão o `flex-basis:0` colapsa o
segmento e os `gap` de 3px ficam tortos.

## Os quatro estágios × a condição MEDIDA de cada um

Avaliados em if-else encadeado, **nesta ordem**, em `estagioDe()`:

| # | Condição medida | Estágio | Por que nesta posição |
|---|---|---|---|
| 1 | no ar **e** `kits !== []` | `done` | a base com Fase 2 criada não espera mais nada |
| 2 | no ar **e** `kits === []` | `ready` | é a regra de "Prontos para a Fase 2" |
| 3 | `status.chave === 'rascunho'` | `none` | `rascunho` é a chave de **não existe rascunho** (`EditorRascunhoService::prontidao()`) |
| 4 | **qualquer outro** (`conferir`, `pronto`, `publicando`, `erro`) | `draft` | **default de propósito**: status novo cai aqui em vez de desaparecer do anel |

**"No ar"** = `status.chave` em `ProgramasPublicadorService::STATUS_NO_AR` (`publicado`/`parcial`)
**ou** `anuncios !== []` — a MESMA definição de `ProgramasPublicadorService::bucketDaFase():452` e
`PainelVisaoGeralService::noArPorFase():348`. Nunca uma terceira.

**O universo** = `($p['eh_kit'] ?? false) === false` **e** `! ehComposto($p)`. Kit fora porque ele *é*
a Fase 2 do base (contá-lo somaria o mesmo produto duas vezes no anel); composto do Portal fora por
KIT-06. Lista de shape antigo (sem `eh_kit`) entra como base — igual a `bucketDaFase()`.

## A paridade com `prontosParaFase2()`

`PainelVisaoGeralService::prontosParaFase2()` (`:592`) é `private` e aquele arquivo é do **plano
179-02**; a regra foi comparada contra a original **por reflexão**, como a Fase 178-01 fez.

| Caso de fronteira | `prontosParaFase2()` | estágio `ready` | Bate? |
|---|---|---|---|
| base no ar sem kit (`publicado`) | conta | `ready` | ✅ |
| base no ar sem kit (`parcial`) | conta | `ready` | ✅ |
| base no ar **com** kit | não conta | `done` | ✅ |
| base **composto** no ar | não conta | fora do universo | ✅ |
| base fora do ar (`rascunho`/`conferir`) | não conta | `none`/`draft` | ✅ |
| lista de **shape antigo** (sem `eh_kit`) | fallback `contagemProdutos['publicados']` = 2 | 2 | ✅ |
| **base `erro` com anúncio CREATED vivo, sem kit** | **não conta** | **`ready`** | ❌ **divergência declarada** |

### A divergência, medida em vez de escondida

A última linha é o preço de "no ar" ter **uma** definição só: `prontosParaFase2()` olha só o status, e
a Jornada olha os dois ramos de `bucketDaFase()`. Diferença medida no teste
`test_divergencia_com_prontos_para_fase_2_e_medida_e_declarada()`: **exatamente 1** no cenário de 2
produtos (1 publicado + 1 em erro com anúncio vivo). O teste cobra o **tamanho** da diferença — se
ela crescer, ou se alguém "consertar" o segundo ramo, reprova e a decisão volta para a mesa.

A alternativa (usar só `STATUS_NO_AR` no `ready`) jogaria a base com anúncio vivo e republicação
falhada em `draft`: a tela diria "rascunho" sobre um produto que está vendendo. Entre os dois erros,
o escolhido é o que o `bucketDaFase()` já comete em toda a tela de Produtos — consistência de
módulo, não invenção nova.

## Divergências declaradas, com a restrição concreta

| # | O protótipo / o plano diz | O que foi entregue | Restrição concreta |
|---|---|---|---|
| D-1 | nota *"Rascunho parado em Imagens há 3 dias"* (`:397`) | `Rascunho em preenchimento · faltam {n}` | **Não existe coluna de etapa parada** nem o relativo daquela data. Mesma divergência da Fase 178 (D-1), já aceita pelo usuário. |
| D-2 | nota *"7 vendas na Fase 1 em 30 dias"*, *"Fase 1 concluída ontem"* (`:400`) | `{rotulo_fase} · {n} no ar` | Venda por produto não entra na Jornada (o bloco de vendas é outro) e a data de conclusão da fase não é gravada. `{n}` são anúncios CREATED reais. |
| D-3 | 4 estágios e **nenhum caso de erro** | `erro` cai em `draft` | **Totalidade do anel**: um quinto caso faria o `N de M produtos` mentir. O erro segue tratado pela ação "Produtos com problema na publicação" de `oQueFazerAgora()`. |
| D-4 | o plano prescreveu a nota `Sem publicação ainda` | `Recebido do Portal do Cliente` / `Cadastro manual no Publicador` / `Origem não informada` | O literal prescrito **contém** o rótulo de tela `"Sem publicação"`, que o **gate 8 do próprio plano** nega no payload: a prescrição reprovaria o próprio teste. Os três textos são os de `NovaPublicacaoService::notaDeSemPublicacao()` — o mesmo produto aparece nas duas telas e duas notas diferentes seriam contradição. |
| D-5 | o plano prescreveu 2 ramos para a nota de `draft` | 4 ramos, um por status | `draft` recebe `conferir`, `pronto`, `publicando` **e** `erro`. Com 2 ramos, uma tentativa que FALHOU receberia *"Rascunho conferido, pronto para publicar"* — **falso na tela**. |
| D-6 | — (nada no protótipo) | `nota` do no-ar **omite** `· 0 no ar` quando não há item CREATED gravado | VG-18: "medimos e é zero" não é "está fora do ar". Base `publicado` sem `ml_item_id` gravado existe; afirmar "0 no ar" num estágio de produto no ar seria contradição. Mesmo tratamento do 178-01. |
| D-7 | o cartão do protótipo monta o tile por `hue` + iniciais do nome (`tone()`, `ini()`) | o item traz `capa` (?string) e `nome` | A `capa` é dado REAL que o sistema tem; as iniciais e a cor por `hue` são decoração derivável no cliente. Nenhum dado inventado — o 179-05 escolhe entre foto e iniciais. |

## O custo medido

| O que | Medido |
|---|---|
| Consultas dentro de `jornada()` | **0** (`assertSame(0, …)`, não "menos que N") |
| Isca do mesmo teste | consultas de `produtosParaTela()` com 12 produtos: `assertGreaterThan(0, …)` — sem ela, um `enableQueryLog()` mal posicionado daria zero por construção |
| Chamadas HTTP ao Mercado Livre | **0** (`Http::fake()` sem handler em toda a classe) |

## A prova por mutação — 12 aplicadas, 12 reprovaram

Processo por mutação: **contagem de ocorrências travada ANTES** (recusa se ≠ 1), aplicação,
**conferência de QUAL linha mudou** (impressa antes/depois), execução da suíte, restauração de cópia
pristina e **conferência por `sha256`** (`03fb3a0f1f22b4a8…c7abe2` em todas as 12 rodadas).

| # | Mutação | Linha | Reprovou em |
|---|---|---|---|
| M01 | ordem dos estágios → `['draft','none','ready','done']` | 64 | 8 testes, a começar pela ordem do contrato |
| M02 | kit entra no universo | 217 | `kit_nao_entra_no_universo` + 7 |
| M03 | composto entra no universo | 217 | `composto_do_planejamento_nao_entra_no_universo`, paridade |
| M04 | `erro` promovido a 5º estágio | 239 | `erro_sem_anuncio_vivo_entra_em_draft_e_nao_desaparece_do_anel` |
| M05 | `kits === []` no ramo do `done` (ready/done trocados) | 226 | 8 testes de cobertura e estágio |
| M06 | `pct` sem o guard de `total === 0` | 252 | os 3 testes de conta vazia (DivisionByZeroError) |
| M07 | `STATUS_NO_AR` → só `['publicado']` | 245 | `parcial_esta_em_status_no_ar_e_vai_para_ready`, paridade |
| M08 | `noAr` sem o 2º ramo (`anuncios !== []`) | 246 | `erro_com_anuncio_vivo_conta_como_no_ar`, divergência medida |
| M09 | teto de itens 6 → 10 | 71 | `cada_estagio_devolve_no_maximo_seis_itens_com_o_total_intacto` |
| M10 | item sem destino entra com `url` vazia | 283 | `d23_alvo_sem_chave_nao_lanca_e_so_perde_os_itens_sem_destino` |
| M11 | cobertura de F2 marcando as células de F1 | 392 | `cobertura_le_f1_do_base_e_f2_dos_kits` + `f2_soma_todos_os_kits` |
| M12 | `gold_pro` deixa de ser Premium | 416 | `cobertura_le_f1_do_base_e_f2_dos_kits` + `f2_soma_todos_os_kits` |

## Deviations from Plan

### [Rule 1 - Bug] O gate de totalidade estava vazio por construção

- **Encontrado durante:** o desenho da mutação M04 (promover `erro` a 5º estágio), perguntando "o que
  precisaria estar errado para este gate falhar?"
- **Problema:** `$total` era calculado **somando os quatro estágios**. Um quinto caso que escapasse do
  if-else sairia dos **dois lados** da igualdade ao mesmo tempo, e `assertFecha()` passaria sempre —
  a mesma classe do `git diff --stat` sem range. A M04 teria "reprovado" por outro teste, e o gate
  central do plano seria decorativo.
- **Correção:** `$total` passou a ser contado no **universo**, numa passada só; o teste passou a cobrar
  `publicados` pelo **outro lado** (`total − none − draft`) em vez de contra a própria fórmula do
  serviço.
- **Verificado:** com a correção, M04 reprova exatamente em
  `test_erro_sem_anuncio_vivo_entra_em_draft_e_nao_desaparece_do_anel`.
- **Commit:** `8bca79ab`

### [Rule 2 - Correção] A nota prescrita para `none` reprovaria o gate do próprio plano

Ver **D-4** acima. O plano prescreveu `Sem publicação ainda` e, no gate 8, mandou negar o literal
`Sem publicação` no payload. Entregue com os três textos do 178-01, que são medidos e não colidem.
**Commit:** `17904989`

### [Rule 2 - Correção] A nota prescrita para `draft` mentiria sobre a publicação que falhou

Ver **D-5** acima. Quatro ramos em vez de dois. **Commit:** `17904989`

### [Desvio de processo] Tasks 1 e 2 num commit só, com o teste commitado ANTES

As Tasks 1 e 2 são donas do **mesmo arquivo novo**, e o `<verify>` da Task 1 é o teste completo — que
inclui os casos da Task 2 e não pode ficar verde sem ela. Separar exigiria commitar um estado
vermelho ou um `itens => []` decorativo. Entregue na ordem de gate do TDD:

| Commit | O que | Gate |
|---|---|---|
| `3f708208` | `tests/.../JornadaDosProdutosTest.php` | **RED** — 32 falhas por `class does not exist`, conferido antes do serviço existir |
| `17904989` | `app/Services/Publicador/JornadaDosProdutosService.php` | **GREEN** — 32/32, 345 asserções (Tasks 1 e 2) |
| `8bca79ab` | o fix do gate vazio por construção | Rule 1 |
| `dce3b362` | citação do protótipo na linha certa (`391-423`) | docs |
| `a7741b7a` | o docblock passa a dizer 12 mutações, igual ao rodado | docs |

### Nenhuma outra: nada fora do escopo foi tocado

`PainelVisaoGeralService.php` (dono: 179-02), `app/Support/Publicador`, `MlPublicacaoService`,
`resources/js`, `routes`, `Editor.jsx`, `Components/Publicador/Mesa`, `AppLayout.jsx`, `database`,
`composer.*` e `package*.json`: **diff vazio no range `898d68c2..HEAD`**, com os 13 caminhos
conferidos por `test -e` e a **isca** `git diff --stat 898d68c2..HEAD -- app tests` devolvendo
exatamente os 2 arquivos novos (1548 inserções) — o vazio não é por construção.

## Verification Results

| # | Gate | Resultado |
|---|---|---|
| 1 | `artisan test --filter=JornadaDosProdutosTest` | **32 passed, 345 assertions** |
| 2 | `phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1880 testes / 11766 asserções / 0 falhas** (baseline medida ANTES de editar: 1848 / 11421 / 0) |
| 3 | diff vazio em `PainelVisaoGeralService.php` (range) | ✅ |
| 4 | diff vazio em `app/Support/Publicador` + `MlPublicacaoService` | ✅ |
| 5 | diff vazio em `database` / `database/migrations` | ✅ (nenhuma migration) |
| 6 | diff vazio em `resources/js` + `routes` | ✅ (100% servidor) |
| 7 | diff vazio em `Editor.jsx` / `Mesa` / `AppLayout.jsx` | ✅ (território do outro dev intacto) |
| 8 | `git diff --diff-filter=D --name-only 898d68c2..HEAD` | ✅ vazio (nenhuma deleção) |
| 9 | os 13 caminhos proibidos existem no disco (`test -e`) + isca do range | ✅ |
| 10 | T-179-SC: `composer.json/lock`, `package.json/lock` | ✅ vazio (nenhum pacote instalado) |
| 11 | testes removidos ou afrouxados | **ZERO** — o único arquivo de teste tocado é o novo |

**Deprecations: 47 → 48.** A nova é do `@group phase179` no doc-comment desta classe — a convenção
que **75 arquivos de teste** deste projeto já usam, e exatamente a origem das 47 pré-existentes.
Trocar por `#[Group]` resolveria a deprecation e deixaria este arquivo sozinho contra 75; ficou a
convenção.

## Threat Model — resultado

| Threat | Disposição | Como ficou |
|---|---|---|
| T-179-01 Information Disclosure | mitigado | o serviço **não consulta** (0 consultas medidas); a lista chega já escopada por `produtosQuery()` |
| T-179-02 Tampering (tela preta de 07/10) | mitigado | `array_keys` do item travado; varredura `is_array`/`is_object` em todo campo exceto `cobertura`; as 4 células são `bool` |
| T-179-03 Spoofing (destino inventado) | mitigado | `url` e `ver_todos_url` por `route()` no servidor; item sem destino usável é descartado |
| T-179-04 Repudiation (número que não fecha) | mitigado | totalidade com `total` independente + exclusividade em todo cenário + paridade por reflexão |
| T-179-SC | mitigado | nenhum pacote instalado, diff vazio nos 4 manifests |
| **extra** (não estava no registro) | mitigado | `test_payload_nao_vaza_segredo_da_conta`: nem `APP_USR`, nem `TG-`, nem `access_token`/`refresh_token` no JSON |

## Known Stubs

Nenhum. Nenhum campo do payload é constante, placeholder ou valor de exemplo: todos saem de
`produtosParaTela()` ou de `route()`.

## Perguntas em aberto para o usuário

1. **`erro` com anúncio vivo aparece em `ready` ("Prontos para a Fase 2")**, e o contador de
   "Prontos para a Fase 2" do bloco "O que fazer agora" **não** o conta (divergência medida acima).
   Os dois números são defensáveis; se incomodar ver o mesmo produto como "pronto para combo" e
   como "com problema na publicação" ao mesmo tempo, a saída é alinhar `prontosParaFase2()` — e isso
   é arquivo do **plano 179-02**, não deste (é ele que toca o `PainelVisaoGeralService`).
2. **A nota de `ready`/`done` sai como `1 unidade · 2 no ar`** (`rotulo_fase` + anúncios vivos).
   É honesta e consistente com a tela de Produtos, mas `1 unidade` pode soar seco no painel; o
   protótipo escrevia `Fase 1 publicada · Clássico`, que é justamente o que as células de cobertura
   já mostram do lado. Mudar o texto muda só este serviço.
3. ⚠️ **Nada foi visto renderizado** — não há navegador neste ambiente e nenhuma tela foi aberta.
   Toda a conferência é de teste automatizado; a validação visual é do usuário, em produção, na
   conta 459.

## Self-Check: PASSED

- `app/Services/Publicador/JornadaDosProdutosService.php` — FOUND
- `tests/Feature/Publicador/JornadaDosProdutosTest.php` — FOUND
- commits `3f708208`, `17904989`, `8bca79ab`, `dce3b362`, `a7741b7a` — FOUND em `git log`
- arquivo do serviço idêntico ao commit depois das 12 mutações (`git diff --stat` vazio, com isca que
  acusou 1 inserção ao mudar um byte de propósito)
