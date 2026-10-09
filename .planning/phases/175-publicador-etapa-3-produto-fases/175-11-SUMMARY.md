---
phase: 175-publicador-etapa-3-produto-fases
plan: 11
subsystem: api
tags: [publicador, kits, capa-do-kit, creative-engine, prompt, slot-plano, inertia-props, truth, phpunit]

# Dependency graph
requires:
  - phase: 175-04
    provides: "`MlbPublicadorFaseController::mostrar()` e o contrato de props da tela do Produto (`Mlb/Publicador/Produto`)"
  - phase: 175-06
    provides: "`CapaDoKitService` (os dois slots `['lifestyle','hero']`), o terceiro parâmetro `tiposFixos` do `PlanejarKitCriativosJob` e `CreativeContext::$unidadesDoKit`"
  - phase: 175-07
    provides: "o painel `PainelCriarFase` com a caixa 'Gerar a capa do kit', gated por `criativosIa`"
  - phase: 161 (Creative Engine)
    provides: "`PlanejarKitCriativosJob`, `CreativePlanner`, `CreativeSlotPlan::paraPrompt()` e a coluna `ml_anuncio_criativos.slot_plano`"
  - phase: 165 (Creative Engine no Publicador)
    provides: "`CreativeContextBuilder::paraPublicador()` — a porta que lê `pub_produtos.quantidade_kit`"
  - phase: 160 (Creative Engine)
    provides: "`CreativePromptBuilder::paraSlot()`, que já imprime a linha `CENA:` a partir do `slot_plano`"
provides:
  - "Prop `criativos_ia` (BOOLEANA) em `MlbPublicadorFaseController::mostrar()` — a capa do kit passa a ser alcançável pela interface"
  - "`PlanejarKitCriativosJob::cenaComComposicao(string, int): string` — estático, puro e idempotente"
  - "Composição de N unidades acrescentada à `cena` de cada slot, SÓ na capa de kit e SÓ com o N do cadastro"
  - "10 testes novos em `tests/Feature/Publicador/CapaDoKitTest.php`, incluindo a regressão byte-a-byte do kit sem `tiposFixos`"
affects: [176, qualquer fase que mexa em capa de kit, prompt de slot ou props da tela do Produto]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Capacidade do servidor vai à tela como BOOLEANO por `podeGerar()`, nunca por `exigir()`: `exigir()` faz abort(403) e derrubaria a página de quem não pode agir"
    - "Prop nova em presenter de produção é ADITIVA e tem gate de ORDEM (`array_intersect` contra lista literal), não só de presença"
    - "Instrução de ARRANJO entra pelo PLANO (`slot_plano['cena']`), nunca no construtor de prompt compartilhado — o builder que monta o prompt de todos os criativos fica intocado"
    - "Número que vai ao prompt é montado em PHP a partir de INTEIRO de cadastro; abaixo do mínimo que faz sentido, nada é emitido (TRUTH-02/03)"
    - "Auditoria do plano guarda o que o PLANNER produziu; `slot_plano` guarda o que foi PEDIDO ao modelo — a divergência é deliberada e documentada"
    - "Gate de 'nada muda para quem não é o caso novo' provado por RECÁLCULO do plano determinístico e comparação byte a byte, não por inspeção visual"

key-files:
  created:
    - ".planning/phases/175-publicador-etapa-3-produto-fases/175-11-SUMMARY.md"
  modified:
    - "app/Http/Controllers/MlbPublicadorFaseController.php"
    - "app/Jobs/PlanejarKitCriativosJob.php"
    - "tests/Feature/Publicador/CapaDoKitTest.php"

key-decisions:
  - "O conserto do furo 2 NÃO passou pelo `CreativePromptBuilder`: o builder já imprime `'CENA: '.$slotPlano['cena']`, e a `cena` vem do plano — a composição entrou pelo `PlanejarKitCriativosJob`, o único lugar que sabe que o kit é capa de kit e quantas unidades ele tem. `git diff --stat app/Services/Creative/` saiu VAZIO."
  - "O canal `ajusteOperador` foi descartado por dois motivos do corpo de `linhasVariacao()`: ele devolve vazio com `$regeneracao < 1` (a capa nasce com 0) e o próprio bloco manda o modelo ignorar o que vier por ali em matéria de QUANTIDADE — proteção deliberada de TRUTH-02/03."
  - "A trava da composição é `$fixos !== []` (capa de kit) **E** `unidadesDoKit !== null` (o inteiro do cadastro). Nenhuma das duas sozinha."
  - "`cenaComComposicao()` não emite nada abaixo de 2 unidades: 'exatamente 1 unidades idênticas lado a lado' seria um pedido sem sentido que o modelo obedeceria com confiança."
  - "A composição é ACRESCENTADA à cena, nunca substitui: a cena original descreve o enquadramento do slot (`lifestyle` ambientada × `hero` fundo limpo) e precisa continuar valendo."
  - "`ml_anuncio_criativo_kits.plano` (auditoria) continua guardando o plano como o planner o produziu; a composição entra só no `slot_plano` de cada criativo, que é o que de fato alimenta o prompt — dá para ver o planejado e o pedido lado a lado."
  - "A prop é a BOOLEANA de `MlbPublicadorEntradaController::editor()` (L260), não a `['url' => …]` homônima de `produtos()`."
  - "`CreativeEngineAtivo`/`CreativePermissao` entram pela ASSINATURA DO MÉTODO, não pelo construtor: o construtor do `MlbPublicadorFaseController` é compartilhado por endpoints que não têm nada a ver com criativos."

patterns-established:
  - "Gate de regressão 'byte a byte': recalcular o artefato pelo mesmo caminho determinístico e comparar com o gravado, em vez de afirmar que não mudou"
  - "Fixture única provando os DOIS ramos: o mesmo produto roda o job com e sem `tiposFixos`, então o ramo novo e o antigo partem do mesmo estado"
  - "Prop de capacidade sempre tem três testes (ligada+permitida / chave desligada / sem permissão) mais um de forma aditiva"

requirements-completed: [FASE-08]

# Metrics
duration: 60min
completed: 2026-10-09
---

# Fase 175 Plano 11: Fechamento dos dois furos da capa do kit — Summary

**A capa do kit deixou de ser inalcançável: a prop booleana `criativos_ia` chega à tela do Produto (a caixa "Gerar a capa do kit" aparece) e o prompt de cada slot da capa passa a pedir a composição de N unidades idênticas lado a lado, com N vindo de `pub_produtos.quantidade_kit` — tudo isso sem encostar no `CreativePromptBuilder`.**

## Performance

- **Duration:** ~60 min (dominado por rodadas de teste: 1 baseline de 223s, 1 final de 187s e 7 rodadas das suítes do Creative Engine)
- **Started:** 2026-10-09T12:25Z (09:25 BRT)
- **Completed:** 2026-10-09T13:25Z (10:25 BRT)
- **Tasks:** 3
- **Files modified:** 3 (+1 criado: esta SUMMARY)

## Accomplishments

- **Furo 1 fechado.** `MlbPublicadorFaseController::mostrar()` envia `criativos_ia` como booleano. Até aqui a prop simplesmente não existia naquele controller (zero ocorrências, conferido por grep): o `PainelDoProduto` caía no default `false`, `PainelCriarFase` não renderizava a caixa e o Confirmar mandava `capa: false` **sempre**. A capa inteira da Fase 175 estava construída no servidor e inalcançável pela interface.
- **Furo 2 fechado.** Cada slot da capa agora pede o ARRANJO: `Mostre exatamente {N} unidades idênticas do mesmo produto, lado a lado, preservando cor, forma e acabamento de todas; nada além do produto na cena.` Antes, o N chegava ao prompt como FATO ("CONTAGENS CONFIRMADAS NO CADASTRO") mas **nenhuma linha pedia a composição**, e o bloco MASTER manda "nunca mude a quantidade ou o conteúdo da embalagem" — com a foto de referência mostrando UMA unidade, a capa provavelmente saía com uma só.
- **`CreativePromptBuilder` intocado.** `git diff --stat app/Services/Creative/` saiu **vazio** nos dois commits. O arquivo que monta o prompt de todos os criativos em produção não foi aberto.
- **Nada mudou para quem não é capa de kit**, provado por recálculo byte a byte do plano determinístico (ver "A prova do caminho antigo").
- **+10 testes, 0 regressões.** Suíte do Publicador: 1131 → 1141.

## Task Commits

1. **Task 1: A prop `criativos_ia` chega à tela do Produto** — `8eb134b9` (fix)
2. **Task 2: O prompt da capa pede a composição de N unidades** — `79e9d9d5` (feat)
3. **Task 3: Gates e SUMMARY** — commit de metadados deste plano

_Os dois primeiros foram TDD com RED medido antes (Task 1: 3 falhas; Task 2: 4 falhas)._

## Files Created/Modified

- `app/Http/Controllers/MlbPublicadorFaseController.php` — `mostrar()` recebe `Request`, `CreativeEngineAtivo` e `CreativePermissao` pela assinatura do método e envia `'criativos_ia' => $creativeAtivo->ativa() && $creativePermissao->podeGerar($request->user())`. Chave **aditiva**, no fim do array.
- `app/Jobs/PlanejarKitCriativosJob.php` — novo `public static function cenaComComposicao(string $cenaOriginal, int $unidades): string` (puro, idempotente) e a emenda no `handle()`: `$unidadesDaComposicao` calculado antes da transação e aplicado ao `slot_plano` de cada criativo.
- `tests/Feature/Publicador/CapaDoKitTest.php` — 10 testes novos em duas seções novas.

## A frase de composição, exatamente como foi gravada

Medida rodando o método de verdade (`artisan tinker --execute`), não transcrita do plano:

```
[N=2 / cena terminada em ponto]
Produto posicionado num ambiente coerente com seu uso. Mostre exatamente 2 unidades idênticas do mesmo produto, lado a lado, preservando cor, forma e acabamento de todas; nada além do produto na cena.

[N=4 / cena sem pontuação final]
Fundo branco liso, sem props — Mostre exatamente 4 unidades idênticas do mesmo produto, lado a lado, preservando cor, forma e acabamento de todas; nada além do produto na cena.

[N=1]
Fundo branco liso.
```

Três coisas a notar:

1. **A cena original vem primeiro e inteira.** Ela descreve o enquadramento do slot (`lifestyle` ambientada × `hero` fundo limpo) e continua valendo.
2. **O separador se adapta:** cena terminada em `.`/`!`/`?` recebe só um espaço; sem pontuação final, entra ` — `. Isso evita o "…seu uso. — Mostre…" que o concatenador fixo produziria.
3. **N=1 (e N=0) não emite nada.** Guarda de TRUTH-02/03 — ver abaixo.

## TRUTH-02/03 — como o número foi mantido honesto

- A frase é montada **em PHP a partir do inteiro** `$contexto->unidadesDoKit`, que `CreativeContextBuilder:172` lê de `$rascunho->produto?->quantidade_kit` quando é `>= 2`. **Nunca** de texto livre, de nome de produto, nem de nada que o operador digite.
- O número **já estava** no bloco "CONTAGENS CONFIRMADAS NO CADASTRO (respeite exatamente)" do mesmo prompt. A linha nova pede só o **arranjo** — não afirma fato novo.
- `cenaComComposicao()` **recusa** abaixo de 2: "exatamente 1 unidades idênticas lado a lado" é um pedido sem sentido que o modelo obedeceria com confiança, e número errado no prompt é pior que número nenhum. Coberto por `test_a_composicao_nunca_pede_uma_unidade_so`.
- Capa de kit com `unidadesDoKit` **nulo** (produto de 1 unidade) não acrescenta uma linha. Coberto por `test_capa_sem_as_unidades_do_cadastro_nao_acrescenta_nada`.

## Por que o conserto do furo 2 não passou pelo `CreativePromptBuilder`

O builder já imprime, em `paraSlot()`:

```php
$linhas[] = 'CENA: '.$this->sanitizar((string) ($slotPlano['cena'] ?? ($padrao['cena_padrao'] ?? '')));
```

A `cena` vem do **plano do slot**. O `PlanejarKitCriativosJob` é o único lugar que sabe, ao mesmo tempo, que **este kit é uma capa de kit** (`$tiposFixos`) e **quantas unidades ele tem** (`$contexto->unidadesDoKit`). Logo a composição entra pelo plano — e o arquivo que monta o prompt de **todos** os criativos em produção não precisou ser aberto.

**O canal `ajusteOperador` foi avaliado e descartado**, por dois motivos que só aparecem no corpo de `CreativePromptBuilder::linhasVariacao()`:

1. ele devolve vazio quando `$regeneracao < 1`, e a capa nasce com `0`;
2. o próprio bloco instrui o modelo a **ignorar o que vier por ali em matéria de quantidade** — proteção deliberada de TRUTH-02/03.

Usar aquele canal seria furar uma trava que existe de propósito.

## A prova do caminho antigo (kit sem `tiposFixos`)

Este é o caminho de **todo** kit em produção, incluindo os 3 kits de 7 slots que existem lá. A prova é `test_kit_sem_tipos_fixos_grava_a_cena_byte_a_byte_igual_a_do_plano`, e ela não se contenta com "não contém a frase":

1. roda o `handle()` **de verdade** com `tiposFixos = null`;
2. **recalcula o mesmo plano** depois, pelo mesmo caminho (`CreativeContextBuilder::paraCriativo()` → `ProductTruthBuilder` → `CreativePlanner::planejar(..., [])`) — o planner é determinístico quando o provedor de texto está fora do ar, que é o caso do dublê;
3. compara `slot_plano['cena']` de cada criativo gravado com `$plano->slots[i]->cena` por `assertSame`, isto é, **byte a byte**;
4. e só então confere que nem `'unidades idênticas'` nem `'Mostre exatamente'` aparecem.

O fixture usa `quantidade_kit = 4` **de propósito**: `unidadesDoKit` não é nulo ali, e mesmo assim nada é acrescentado — o que prova que a trava é `$fixos !== []`, não a ausência das unidades.

Prova estrutural complementar, por grep: o **único** chamador que passa o terceiro parâmetro é `CapaDoKitService:158` (Fase 175). `MlbAnuncioController:1926` e `MlbPublicadorCriativoController:205` despacham com 2 argumentos → `$fixos === []` → `$unidadesDaComposicao === null` → o array `paraPrompt()` segue **intacto**. A mudança é provavelmente um no-op para todo caminho pré-existente.

## Gates medidos

| Gate | Antes | Depois |
|---|---|---|
| `tests/{Feature,Unit}/Publicador` | **1131 passed** (5581 assertions) | **1141 passed** (5633 assertions) |
| Creative Engine (`--filter="Phase160\|Phase161\|Phase162\|Phase165\|Phase168\|Phase169\|Phase170\|Phase171"`) | — | **419 passed + 1 incomplete, 0 failed** |
| `grep -c criativos_ia` no `MlbPublicadorFaseController` | 0 | **1** (booleana, L153) |
| `git diff --stat app/Services/Creative/` | — | **vazio** |
| `git diff --stat resources/js/` | — | **vazio** |

Baseline do Publicador medido por mim antes de tocar em nada (bate com o do plano). O delta de **+10** é exatamente o número de testes que escrevi (4 na Task 1 + 6 na Task 2) — nenhuma regressão.

`npm run build` não foi necessário: nenhum `.jsx`/`.js` foi tocado (`git diff --stat resources/js/` vazio nos dois commits).

## Decisions Made

Ver `key-decisions` no frontmatter. As duas que mais importam para quem vier depois:

1. **Instrução de arranjo entra pelo plano, não pelo builder.** Vale como regra: qualquer pedido novo que dependa de saber "que tipo de kit é este" pertence a quem despacha o planejamento, não ao construtor de prompt compartilhado.
2. **A auditoria e o prompt divergem de propósito.** `ml_anuncio_criativo_kits.plano` guarda o plano **como o planner o produziu**; `ml_anuncio_criativos.slot_plano` guarda o que foi **pedido ao modelo**. Quem auditar "por que a imagem veio com 4 unidades" tem de olhar o `slot_plano`, que é o que o builder lê. Está comentado no código, no `update()` do kit.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Guarda de `$unidades < 2` em `cenaComComposicao()`**
- **Found during:** Task 2
- **Issue:** O plano trava a chamada em `$contexto->unidadesDoKit !== null` (que é `>= 2` por construção em `CreativeContextBuilder:172`), mas o método estático é público e ficava sem defesa própria. Um chamador futuro passando `1` produziria "Mostre exatamente 1 unidades idênticas do mesmo produto, lado a lado" — um pedido sem sentido que o modelo obedeceria com confiança, exatamente o modo de falha que TRUTH-02/03 existe para evitar (e que já custou caro nesta milestone: afirmamos "exatamente quatro pés" num produto de cinco, o modelo obedeceu e a imagem passou pela revisão humana).
- **Fix:** `if ($unidades < 2) { return $cenaOriginal; }` no topo do método, documentado no docblock.
- **Files modified:** `app/Jobs/PlanejarKitCriativosJob.php`
- **Verification:** `test_a_composicao_nunca_pede_uma_unidade_so` (N=1 e N=0 devolvem a cena intacta).
- **Committed in:** `79e9d9d5`

**2. [Rule 2 - Missing Critical] Separador sensível à pontuação da cena**
- **Found during:** Task 2
- **Issue:** O plano admite "espaço ou ` — `". Concatenar ` — ` fixo produz `"…coerente com seu uso. — Mostre exatamente…"` nas cenas determinísticas do catálogo, que terminam em ponto — um travessão depois de ponto final é ruído num texto que vai para um modelo de imagem.
- **Fix:** cena terminada em `.`/`!`/`?` recebe espaço; sem pontuação final, ` — `. Cena vazia devolve só a frase.
- **Files modified:** `app/Jobs/PlanejarKitCriativosJob.php`
- **Verification:** medido com as duas formas (ver "A frase de composição"); `test_a_composicao_acrescenta_sem_apagar_a_cena_do_plano`.
- **Committed in:** `79e9d9d5`

**3. [Rule 2 - Missing Critical] Gate de ORDEM na regressão de forma, não só de presença**
- **Found during:** Task 1
- **Issue:** O plano pede um teste provando que "todas as chaves que `mostrar()` já enviava continuam presentes". Presença sozinha não cobre a outra metade do must-have ("nenhuma chave muda de nome, de **ordem** ou de valor").
- **Fix:** além do `assertArrayHasKey` por chave, um `assertSame` entre a lista literal esperada e `array_values(array_intersect(array_keys($props), $contrato))`, mais a conferência de três valores (`produto.id`, `empresa.chave`, `fase_destacada`). O recorte por `array_intersect` deixa de fora as props compartilhadas do `HandleInertiaRequests`, de propósito.
- **Files modified:** `tests/Feature/Publicador/CapaDoKitTest.php`
- **Verification:** `test_a_prop_da_capa_e_aditiva_e_nao_mexe_no_contrato_da_tela`
- **Committed in:** `8eb134b9`

**4. [Rule 2 - Missing Critical] Sexto teste na Task 2 (o plano pedia cinco)**
- **Found during:** Task 2
- **Issue:** Os cinco testes do plano cobriam o ramo novo, o ramo antigo e a ponta a ponta, mas a guarda do `< 2` (desvio 1) ficaria sem prova.
- **Fix:** `test_a_composicao_nunca_pede_uma_unidade_so` acrescentado.
- **Files modified:** `tests/Feature/Publicador/CapaDoKitTest.php`
- **Verification:** verde; o delta da suíte (+10) confere com 4+6.
- **Committed in:** `79e9d9d5`

---

**Total deviations:** 4 auto-fixed (4× Rule 2 — funcionalidade crítica ausente)
**Impact on plan:** Nenhum escopo novo. Três das quatro endurecem exatamente as travas que o plano nomeia como invioláveis (TRUTH-02/03 e "a prop é aditiva"); a quarta é qualidade do texto que vai ao modelo. Nenhum arquivo fora dos três declarados em `files_modified` foi tocado.

## Issues Encountered

**Flakiness pré-existente nas suítes combinadas do Creative Engine.** Em 7 rodadas do filtro combinado, 5 saíram limpas (419 passed + 1 incomplete) e 2 acusaram 1 e 2 falhas, sempre com a mesma mensagem: `SQLSTATE[23000] … UNIQUE constraint failed: pub_imagens.rascunho_id, pub_imagens.sha256`.

Investigado e descartado como regressão minha, por quatro razões:

1. **Não é determinístico.** Contagem de falhas variando entre rodadas com código idêntico não pode vir de um diff determinístico.
2. **`Phase165` isolado é estável:** 3 rodadas, `1 incomplete, 151 passed` nas três. A falha só aparece no filtro combinado — é vazamento de estado/ordem entre suítes, não um teste quebrado.
3. **Meu diff não escreve em `pub_imagens`.** `PlanejarKitCriativosJob` grava `MlAnuncioCriativo` e `MlAnuncioCriativoKit`; a prop do controller não grava nada.
4. **Nenhum chamador pré-existente passa `tiposFixos`** (grep acima), então o ramo novo nem é alcançado por aquelas suítes.

Não consertei: está **fora do escopo** deste plano (fixtures de `tests/Feature/Phase165/**`, de outra fase). Registrado em `deferred-items.md`.

**Divergência de número entre plano e realidade.** O plano dá o baseline do Creative Engine como "379 verdes + 1 incompleto". O medido é **419 verdes + 1 incompleto** com o filtro literal do plano. A diferença (+40) é de testes acrescentados pelas waves 5–8 depois de o plano ter sido escrito, não de nada que este plano fez. O que importa para o gate — **0 falhas e o mesmo 1 incompleto pré-existente** — está mantido.

**Sessão paralela.** O `175-10` commitou entre os meus dois commits (`a3b50803`, `83baa8a3`, `a49defbb`). Conferido: ele tocou só `resources/js/`, `tests/js/` e `.planning/`. Zero sobreposição de arquivo com este plano, e o delta de +10 na suíte PHP é inteiramente meu.

## User Setup Required

Nenhuma configuração de serviço externo. Mas a capa só aparece com **a chave operacional ligada**:

```php
// na VPS, sem deploy e sem config:cache
Configuracao::set(\App\Services\Creative\CreativeEngineAtivo::CHAVE, '1');  // 'creative_engine_ativo'
```

E, se `creative_engine_usuarios` estiver **preenchida** em `configuracoes`, o id do usuário precisa estar na lista (2ª camada do OPS-04). Lista ausente ou vazia = sem restrição extra.

## Roteiro clicável em produção (conta #459)

1. Abrir `/mlb/anuncios/publicador` → escolher a conta **#459** → abrir um produto **base** com a Fase 1 publicada.
2. Na tela do Produto, abrir o painel **"Criar Fase N"**. **A caixa "Gerar a capa do kit" tem de estar visível e marcada por padrão.** Se não estiver, é a chave do Creative Engine desligada ou o usuário fora de `creative_engine_usuarios` — não é este plano.
3. Com a caixa desmarcada, o painel mostra o aviso âmbar "A capa ainda mostra 1 unidade". Marcar de novo.
4. Informar a quantidade (ex.: **2**) e Confirmar. Resposta 201 leva ao editor do kit em "Condições de venda"; o kit nasce **sem preço** (decisão 2 do `175-DECISOES.md`).
5. Conferir no card de **Fotos** do editor do kit: duas gerações pendentes/prontas, uma **ambientada** (`lifestyle`) e uma de **fundo limpo** (`hero`).

> ⚠️ **A prova honesta da composição é gerar uma capa de verdade (~R$ 1,10 pelas duas imagens) e olhar se vêm N unidades.** Nenhum teste automatizado prova isso: os testes provam que a frase chega ao prompt na linha `CENA:`, não que o modelo obedece. A geração paga é o único jeito de saber — e, se vier uma unidade só, o problema passa a ser a tensão entre esta linha e o bloco MASTER ("nunca mude a quantidade … que aparece nas fotos de referência"), que é a próxima coisa a investigar.

Para auditar sem gerar: `MlAnuncioCriativo::where('kit_id', …)->pluck('slot_plano')` e olhar a chave `cena` — a frase tem de estar lá, depois da cena do slot.

## Next Phase Readiness

- Os dois furos que deixavam a capa inalcançável estão fechados. A Fase 175 agora entrega a capa **no produto**, não só no servidor.
- **Pendência deliberada, não bloqueante:** a obediência do modelo à composição não foi verificada (exige geração paga). É o item 5 do roteiro acima.
- **Risco conhecido a observar:** a linha de composição e o bloco MASTER tratam de quantidade em direções opostas. Se a capa real sair com uma unidade, o ajuste é no bloco MASTER (`CreativePromptBuilder`) e aí sim passa pelo arquivo compartilhado — exigiria plano próprio, com gate nas suítes inteiras do Creative Engine.
- Flakiness de `tests/Feature/Phase165/**` no filtro combinado segue aberta em `deferred-items.md`.

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Plan: 11*
*Completed: 2026-10-09*

## Self-Check: PASSED

Conferido por reconsulta ao disco e ao git, não por memória da sessão:

- `175-11-SUMMARY.md`, `MlbPublicadorFaseController.php`, `PlanejarKitCriativosJob.php` e
  `CapaDoKitTest.php` existem.
- Commits `8eb134b9`, `79e9d9d5` e `cb1f6d3e` existem em `git log --all`.
- `cenaComComposicao()` existe e é `public static` (L132 do job).
- `grep -c criativos_ia` no `MlbPublicadorFaseController` = **1**.
- `git diff --stat` dos meus commits para `app/Services/Creative/` e `resources/js/`:
  **vazio** nos dois.
- Árvore de trabalho limpa em `app/`, `tests/` e `resources/` (fora o
  `tests/Feature/CompanyPortfolioAccessTest.php` untracked, que não é deste plano).
