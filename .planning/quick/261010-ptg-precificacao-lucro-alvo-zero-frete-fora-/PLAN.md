---
quick_id: 261010-ptg
slug: precificacao-lucro-alvo-zero-frete-fora
date: 2026-10-10
type: quick
wave: 1
depends_on: []
autonomous: true
requirements: [PTG-B, PTG-C, PTG-D]
files_modified:
  - app/Services/Publicador/DadosEfetivosService.php
  - app/Services/Publicador/EditorRascunhoService.php
  - resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx
  - tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php
  - tests/js/publicador-quanto-recebo.test.js

must_haves:
  truths:
    - "O card \"Quanto você recebe\" mostra, por tipo: preço sugerido, recebimento, custo, lucro e margem estimada"
    - "Recebimento abaixo do custo avisa, com preço do Portal E com preço digitado"
    - "Preço do Portal calculado sem frete aparece no card como número NÃO confiável, com entrada manual à mão"
    - "A tela diz que o Mercado Livre não desconta imposto e mostra quanto o preço reserva para ele"
    - "O Premium da Poltrona, que hoje parece sobrar R$ 1,75, aparece como prejuízo depois do imposto reservado"
    - "Nenhum campo novo derruba a tela quando vem objeto, nulo ou ausente"
    - "A fórmula do preço e os padrões de MC/LL ficam exatamente como estão"
  artifacts:
    - path: "tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php"
      provides: "simular() com Http::fake: custo, lucro, margem e os avisos (preço do Portal e digitado)"
    - path: "tests/js/publicador-quanto-recebo.test.js"
      provides: "Render REAL do card, com campo objeto/nulo/ausente"
  key_links:
    - from: "app/Services/Publicador/EditorRascunhoService.php (simular)"
      to: "app/Services/Publicador/DadosEfetivosService.php (custosDoProduto)"
      via: "custo e imposto do Portal pelo caminho que o Publicador já usa"
    - from: "resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx (QuantoRecebo)"
      to: "m.simulacao[listing_type]"
      via: "campos novos do servidor, sempre coagidos por paraNumero"
---

<objective>
O preço sugerido NÃO está errado: o `minimo` é o ponto de equilíbrio e o `anunciado` é `minimo × 1,2`,
então vendendo no anunciado sobram ~16,7% da receita. O prejuízo da Poltrona Beny veio do **frete
ausente**, não do lucro-alvo. Esta tarefa conserta o que fez o prejuízo passar calado:

- **B** — `preco()` devolve a flag `sem_frete` e **entrega o preço assim mesmo**. Passa a avisar e a
  pedir entrada manual, mostrando por tipo **preço sugerido, recebimento estimado, custo, lucro
  estimado e margem estimada**.
- **C** — aviso sempre que a simulação REAL devolver `recebimento < custo`, **independente de o preço
  ser do Portal ou digitado**. Procedência não distingue nada: um preço digitado pode ser byte a byte
  igual à sugestão sem frete, e nenhuma regra de origem separa os dois.
- **D** — o imposto de 19% está no divisor **como se o ML descontasse, e o ML não desconta**. "Quanto
  você recebe" é dinheiro que ainda tem imposto a pagar, então comparar aquele número direto com o
  custo **subestima** o prejuízo. A tela deixa de sugerir que recebimento é lucro.

⚠️ **A fórmula não muda.** O imposto no divisor é provisionamento intencional. `margem_contribuicao` e
`lucro_liquido` ficam em `0.0` nos `PADROES` — MC/LL zero é o padrão deliberado do sistema inteiro.
Nenhuma migration, nenhuma constante, nenhum número de preço se move: os divisores seguem 0,695
(clássico) e 0,645 (premium), e a Poltrona Beny segue em **483,45 / 520,92**.

Output: custo e imposto do Portal chegando à simulação do editor + card que conta a verdade, com teste
RED antes de cada conserto.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/learnings/publicador-ml.md

Fonte (ler só a faixa indicada; não reler faixa já lida):
- `app/Services/Portal/Estrutura/EstruturaPrecificacaoService.php` — `pagina()` → `por_oferta[id]` com `custo`, `excecoes`, `classico`, `premium`; e `parametros`. **Leitura apenas: este arquivo não é alterado.**
- `app/Services/Publicador/DadosEfetivosService.php` — `daProduto`/`daOferta`/`precosPorVariante`/`precosDoKit`
- `app/Services/Publicador/EditorRascunhoService.php` — `simular()` (~L357) e `estado()` (~L483)
- `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` — **não alterar**
- `app/Services/Publicador/Fila/ResumoRapidoService.php` — `dinheiro()` (~L327-420), a margem estimada do LOTE, para NÃO copiar
- `resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx` — `QuantoRecebo` (~L96-130)
- `tests/js/publicador-preco-promocao.test.js` — modelo de render REAL (esbuild + `renderToStaticMarkup`)
</context>

<interfaces>
O que o executor NÃO precisa descobrir — já medido nesta sessão:

`EstruturaPrecificacaoService::pagina($empresa, $ids)` devolve, por oferta:
```
por_oferta[$ofertaId] = [
  'custo'    => ['valor' => ?float, 'origem' => 'produto'|'digitado'|'componentes'|null, 'calculado' => ?float],
  'excecoes' => ['comissao_classico'=>?float, 'comissao_premium'=>?float, 'imposto'=>?float, 'margem_contribuicao'=>?float, 'lucro_liquido'=>?float],
  'classico' => ['comissao'=>float, 'frete'=>?float, 'frete_origem'=>?string, 'frete_sugerido'=>?array, 'minimo'=>?float, 'anunciado'=>?float, 'impossivel'=>bool, 'sem_frete'=>bool],
  'premium'  => [idem],
  'pendencia'=> ?string,
]
// e, fora do por_oferta: ['parametros' => ['imposto'=>float, ...], 'padroes' => PADROES, 'resumo' => [...]]
```

`SimuladorVoceRecebe::calcular($preco, $tarifa, ?$frete)` devolve
`['preco','tarifa','frete','voce_recebe','percentual','frete_conhecido']` — **estas seis chaves,
nesta ordem, ficam intactas** (o arquivo é `App\Support\Publicador`, território proibido).

`RascunhoSnapshot::comEfetivosDe($e)` acrescenta a cada variante
`dados['portal'][$lt] = ['anunciado','minimo','sem_frete']` e `dados['preco_do_portal'][$lt] = true`
quando o preço VEIO do Portal. `comEfetivos()` com 3 argumentos **não** traz essas chaves — é por isso
que `simular()` hoje não sabe a procedência.

`DadosEfetivosService::daProduto()` devolve `titulos`, `precos`, `promocoes`, `sem_frete`, `mlbs` e os
`*_por_variante`. **Esse array é comparado INTEIRO** (`assertSame`: chaves, valores e ORDEM) contra
`Fila/EfetivosEmLote` pelo `VisaoRapidaDoLoteTest` (learnings §20) — ver a decisão 1.
</interfaces>

<decisoes_tomadas_neste_plano>
Decisões que o executor **não** deve reabrir:

1. **O custo NÃO entra em `daProduto()` / `daOferta()`.** O `VisaoRapidaDoLoteTest` compara o array
   inteiro com `Fila/EfetivosEmLote` (§20); chave nova ali obrigaria a espelhar no lote e quebraria o
   teste de paridade até lá. O custo sai por um método público NOVO e separado (`custosDoProduto`),
   fora do array comparado.
2. **Nada de segunda fórmula de margem.** O lote (`Fila/ResumoRapidoService::dinheiro`, §20) calcula
   margem por PERCENTUAIS planejados (preço − custo − frete − (comissão+imposto) × preço). O card do
   editor usa a simulação REAL (tarifa e frete que a API respondeu agora). São perguntas diferentes,
   de propósito. Não unificar, não copiar a conta do lote para o editor, não criar cópia em JS — a
   tela só formata o que o servidor manda. A diferença fica escrita no `title` da linha de margem e no
   docblock de `simular()`.
3. **Nada novo nem alterado em `App\Support\Publicador`.** `SimuladorVoceRecebe` fica intocado, com as
   6 chaves na ordem; as contas novas moram num privado de `EditorRascunhoService`. Gate:
   `git diff --stat app/Support/Publicador/` vazio.
4. **`simular()` passa a usar `comEfetivosDe($e)`** (hoje chama `comEfetivos` com 3 argumentos), o
   mesmo caminho do `estado()`. É o único jeito de ele conhecer `preco_do_portal` e
   `portal_sem_frete`. Acréscimo puro: `simular()` só lê `dados['precos']`.
5. **Sigilo resolvido.** Custo é dado do cliente, mas o Publicador interno **já** mostra custo e
   margem na visão rápida do lote (`LinhaDoLote.jsx` ~L151, "Custo" + `ROTULO_ORIGEM_CUSTO`). Mesma
   audiência (equipe, atrás da permissão de publicação), mesma permissão. A regra de sigilo da Fase
   176 é sobre não entregar ao CLIENTE o destino do cadastro — não é tocada. Dizer isso na SUMMARY.
6. **O V-SAL-08 fica como está.** Não tentar deixá-lo esperto sobre procedência: a saída robusta é o
   aviso que não depende de procedência (conserto C).
7. **Nenhuma migration, nenhuma constante, nenhuma mudança de fórmula.** Gates:
   `git diff --stat -- database` vazio, `git diff --stat -- app/Models` vazio e
   `git diff --stat -- app/Services/Portal/Estrutura` vazio. Se o executor sentir vontade de mexer em
   `PADROES`, no `acrescimo`, na semântica `anunciado`/`minimo` ou no `imposto` do divisor, **pare e
   pergunte** — não é desta tarefa.
</decisoes_tomadas_neste_plano>

<a_poltrona_beny_antes_e_depois>
Aritmética sobre os números que o usuário JÁ mediu (custo 280; recebimento real 263,84 no Clássico e
281,75 no Premium, contra preço 483,45 e 520,92). Nada aqui é leitura nova de produção — é só a conta
dos campos que a tela passa a mostrar:

| | Clássico (483,45) | Premium (520,92) |
|---|---|---|
| Você recebe (hoje, única linha do card) | 263,84 | 281,75 |
| Custo | 280,00 | 280,00 |
| Lucro antes do imposto | **−16,16** | **+1,75** |
| Imposto reservado (19% do preço) | 91,86 | 98,97 |
| Lucro depois do imposto | **−108,02** | **−97,22** |
| Margem estimada | **−22,34%** | **−18,66%** |
| `abaixo_do_custo` | true | false |
| `prejuizo_com_imposto` | false (já avisou o de cima) | **true** |

É este o ponto do conserto D: hoje o Premium **parece** sobrar R$ 1,75 e está R$ 97,22 no vermelho. A
SUMMARY repete esta tabela — e deixa claro que, com o frete informado na Precificação do Portal, o
preço sobe e os números mudam (a conta de novo vem da API, no clique do usuário em "Calcular").
</a_poltrona_beny_antes_e_depois>

<tasks>

<task type="auto" tdd="true">
  <name>Tarefa 1: Custo e imposto do Portal até a simulação, com os avisos (B, C e D no servidor)</name>
  <files>
    tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php (novo),
    app/Services/Publicador/DadosEfetivosService.php,
    app/Services/Publicador/EditorRascunhoService.php
  </files>

  <behavior>
    Tudo com `Http::fake` — **nenhuma chamada real ao ML nem ao Gemini**. Tarifa e frete respondidos
    por fake, como os testes do Publicador já fazem (`sale_fee_amount`,
    `coverage.all_country.list_cost`).

    Caso canônico, a Poltrona Beny (recomendado: preço 483,45, `sale_fee_amount` 157,26, `list_cost`
    62,35 → recebe **263,84**; custo 280; imposto 19). A divisão entre tarifa e frete é arbitrária — o
    que foi medido é o TOTAL (~219,61); o teste trava o recebimento final, não o rateio.
    - `custo === 280.0`, `lucro === -16.16`, `imposto_reservado === 91.86`,
      `lucro_depois_do_imposto === -108.02`, `margem_pct === -22.34`, `abaixo_do_custo === true`,
      `prejuizo_com_imposto === false`
    - Premium (preço 520,92, tarifa+frete 239,17 → recebe **281,75**): `lucro === 1.75`,
      `imposto_reservado === 98.97`, `lucro_depois_do_imposto === -97.22`, `margem_pct === -18.66`,
      `abaixo_do_custo === false`, `prejuizo_com_imposto === **true**`
    - MESMO caso com o preço **DIGITADO** na variante (mesmo valor): `abaixo_do_custo` continua `true`
      e `preco_do_portal === false` — o aviso não depende de procedência
    - Preço vindo do Portal: `preco_do_portal === true`
    - Oferta cujo preço do Portal saiu sem frete: `portal_sem_frete === true`
    - Sem custo no Portal: `custo === null`, `lucro === null`, `margem_pct === null`,
      `abaixo_do_custo === false`, `prejuizo_com_imposto === false` (sem custo não se inventa prejuízo)
    - Fake sem `list_cost` (pacote sem medidas): `frete_conhecido === false`, e os campos de lucro
      seguem presentes (a tela é que marca o número como otimista)
    - As 6 chaves do `SimuladorVoceRecebe` continuam presentes, na ordem, com os mesmos valores
  </behavior>

  <action>
PASSO 0 — baseline antes de escrever código (grave a saída no scratchpad, não confie na memória):

    C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador
    npm run test:js

Esperado: **1713 passando / 0 falhando** no PHP (já confirmado) e, no JS, exatamente a falha
pré-existente `estrutura-grade-glide` ("Características secundárias nasce recolhido") — que **não** se
corrige. Como esta tarefa não altera `app/Services/Portal/Estrutura/`, `app/Models/` nem
`database/`, não há baseline de Portal/Estrutura a medir.

PASSO 1 — RED: escrever `tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php` com o
`<behavior>`. Reaproveitar cenário e fake que os testes do Publicador já têm (`CenarioCadeira` e os
helpers de `Http::fake` de `tests/Feature/Publicador/`) — não inventar harness novo. Rodar e ver
FALHAR (as chaves ainda não existem).

PASSO 2 — GREEN, em DOIS pontos:

1. `app/Services/Publicador/DadosEfetivosService.php` — método público NOVO, **sem tocar** em
   `daProduto()`, `daOferta()`, `precosAnunciados()`, `precosDePromocao()` nem `semFrete()`
   (decisão 1):

       /** @return array{custo: ?float, origem: ?string, imposto: ?float, por_variante: array<string, ?float>} */
       public function custosDoProduto(PubProduto $produto): array

   Regras:
   - sem `oferta_id` e sem kit → custo/origem nulos, `por_variante` vazio; `imposto` = o da empresa
     (`EstruturaPrecificacaoParametros::daEmpresa($produto->company_id)['imposto']`) quando houver
     company, senão `null`;
   - com oferta → UMA `pagina($empresa, [$oferta_id])`: `custo` = `por_oferta[id]['custo']['valor']`,
     `origem` = `['custo']['origem']`, `imposto` = `por_oferta[id]['excecoes']['imposto'] ?? parametros['imposto']`
     (a exceção da oferta vence o padrão da empresa — a MESMA precedência que o lote usa);
   - produto agrupado / kit da Fase N → `por_variante` = SKU normalizado → custo, pela MESMA oferta
     que dá o preço daquela cor. Para isso, acrescentar um mapa `custos` ao `$mapas` dos privados
     `precosPorVariante()` e `precosDoKit()` (que já têm o `por_oferta` em mão) e **não** propagar
     esse mapa para o retorno de `daProduto()` — ele sai só por `custosDoProduto()`;
   - docblock em pt-BR dizendo por que isto está FORA do `daProduto()` (o teste de paridade do lote) e
     que custo é dado do cliente, exibido só na tela interna.

2. `app/Services/Publicador/EditorRascunhoService.php`, `simular()`:
   - trocar `comEfetivos($e['titulos'], $e['precos'], $e['precos_por_variante'] ?? [])` por
     `comEfetivosDe($e)` (decisão 4);
   - chamar `$this->efetivos->custosDoProduto($r->produto)` UMA vez, antes do loop, e resolver o custo
     da primeira variante ativa pelo SKU dela (`EstruturaOferta::normalizarSku` do `SELLER_SKU` da
     variante, com fallback para o do produto): `por_variante[$sku] ?? custo`. É a variante que a
     simulação usa — mostrar o custo da âncora ao lado do preço de outra cor seria número errado;
   - montar a saída como **spread das 6 chaves do `SimuladorVoceRecebe` + as novas**, nesta ordem:
     `custo`, `custo_origem`, `imposto_pct`, `imposto_reservado`, `lucro`, `lucro_depois_do_imposto`,
     `margem_pct`, `abaixo_do_custo`, `prejuizo_com_imposto`, `preco_do_portal`, `portal_sem_frete`.
     Contas, num privado `recebimentoVsCusto()` do próprio serviço (decisão 3):
       imposto_reservado        = imposto_pct === null ? null : round($preco * $imposto_pct / 100, 2)
       lucro                    = custo === null ? null : round($voce_recebe - $custo, 2)
       lucro_depois_do_imposto  = (lucro === null || imposto_reservado === null) ? null : round(lucro - imposto_reservado, 2)
       margem_pct               = lucro_depois_do_imposto === null || $preco <= 0 ? null : round(lucro_depois_do_imposto / $preco * 100, 2)
       abaixo_do_custo          = custo !== null && $voce_recebe < custo
       prejuizo_com_imposto     = ! abaixo_do_custo && lucro_depois_do_imposto !== null && lucro_depois_do_imposto < 0
   - docblock de `simular()`, em pt-BR: `lucro` é ANTES do imposto porque o ML **não desconta imposto**
     (o que o preço do Portal reserva é `imposto_reservado`); a margem aqui é com tarifa e frete REAIS
     da API, diferente de propósito da "margem estimada" por percentuais da visão rápida do lote
     (`Fila/ResumoRapidoService`, §20); e a fórmula do preço **não muda** por causa disso — imposto no
     divisor é provisionamento intencional, e MC/LL zero é o padrão deliberado (o `minimo` é o ponto
     de equilíbrio e o `anunciado` é `minimo × 1,2`, ~16,7% da receita);
   - não mexer em `freteGratis()`, `estado()`, `dimensoes()`, `MlPublicacaoService`, no acervo, na tela
     de Publicações nem no filtro de status.
  </action>

  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Publicador/RecebimentoAbaixoDoCustoTest.php</automated>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador</automated>
    <automated>git diff --stat -- app/Support/Publicador app/Models database app/Services/Portal/Estrutura | tee /dev/stderr | wc -l</automated>
  </verify>

  <done>
    `simular()` devolve custo, imposto reservado, lucro, lucro depois do imposto, margem e os quatro
    sinalizadores, por tipo, com os números da Poltrona Beny travados em teste. O aviso de recebimento
    abaixo do custo dispara com preço do Portal e com preço digitado. As 6 chaves do
    `SimuladorVoceRecebe` seguem iguais e o arquivo dele está intocado. `daProduto()`/`daOferta()` não
    ganharam chave nenhuma e `VisaoRapidaDoLoteTest` segue verde. Publicador em 1713 / 0. O `git diff`
    de `app/Support/Publicador`, `app/Models`, `database` e `app/Services/Portal/Estrutura` é VAZIO.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: O card "Quanto você recebe" conta a verdade (B, C e D na tela)</name>
  <files>
    tests/js/publicador-quanto-recebo.test.js (novo),
    resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx
  </files>

  <behavior>
    Render REAL (esbuild + `react-dom/server`):
    - com simulação completa: aparecem Preço de venda, Tarifa, Frete, Você recebe, **Custo**,
      **Lucro antes do imposto**, **Imposto reservado**, **Lucro depois do imposto** e
      **Margem estimada**
    - `abaixo_do_custo: true` → aviso presente (`data-aviso-recebimento="<lt>|abaixo_do_custo"`)
    - `prejuizo_com_imposto: true` com `abaixo_do_custo: false` → aviso de prejuízo depois do imposto,
      e NÃO o de abaixo do custo (o caso do Premium da Poltrona)
    - `portal_sem_frete: true` → aviso de preço do Portal sem frete, dizendo que não é recomendação
      confiável e que dá para informar o frete no Portal ou digitar o preço ali
    - `frete_conhecido: false` → aviso de frete desconhecido ("otimista")
    - `custo: null` → linhas de lucro/margem com travessão e nenhum aviso de prejuízo
    - **cada campo novo como OBJETO (`{x:1}`), como `null` e AUSENTE**: o HTML sai sem
      `[object Object]` e sem `NaN`, e o render não lança
    - a nota fixa do imposto aparece sempre que há simulação
  </behavior>

  <action>
RED primeiro. Modelo: `tests/js/publicador-preco-promocao.test.js` — mesmo `esbuild.build` com `alias`
de `@` e do stub do `@inertiajs/react`, mesmos `external`.
⚠️ **Montar TODOS os bundles no topo do módulo, ANTES do primeiro `test()`** (`await` em escopo de
módulo ESM), e não apagar em `after()` nada que os testes ainda precisem — `after()` já matou um
arquivo inteiro deste projeto.
⚠️ `assert.match(html, /disabled/)` é asserção VAZIA aqui (as classes têm `disabled:opacity-40`): se
precisar, use `/disabled=/`.
Exportar o `QuantoRecebo` como named export para montá-lo isolado, ou montar o `EtapaCondicoes`
default com um `m` mínimo — escolha o que exigir menos props falsas; se exportar, o default export da
etapa não muda.

GREEN, em `QuantoRecebo` (`EtapaCondicoes.jsx`):
- helper local sobre `paraNumero` (de `../apoio`; importar se ainda não estiver):
  `const num = (v) => paraNumero(v)` e `const brl = (v) => (num(v) === null ? '—' : formatCurrency(num(v)))`.
  **Todo** valor numérico do servidor passa por aí — é o que impede a tela preta de campo-objeto
  (`formatCurrency({x:1})` sai "R$ NaN");
- linhas novas no `<dl>` de cada tipo, depois de "Você recebe": `Custo`,
  `Lucro antes do imposto`, `Imposto reservado (N%)` (N = `imposto_pct`; omitir o parêntese se nulo),
  `Lucro depois do imposto`, `Margem estimada` (`margem_pct` com `%`, travessão se nulo);
- `title` na linha de margem, sem jargão: "Margem estimada = (o que você recebe − custo − imposto
  reservado) ÷ preço. Tarifa e frete são os que o Mercado Livre respondeu agora.";
- tom: lucro negativo em `text-red-300`; positivo segue o `text-white`/`emerald` do card. Sem
  `bg-ecf-yellow`, sem `text-xs/sm/base/lg`, só 24/15/13/11px e pesos 400/700 — os gates de fonte do
  `publicador-mesa.test.js` valem para este arquivo;
- avisos, cada um num `<p>` próprio com `data-aviso-recebimento={`${lt}|<chave>`}`, 13px,
  `text-amber-300` para alerta e `text-red-300` para prejuízo, com `AlertTriangle` (padrão de
  `MedidasDoPacote.jsx`/`EtapaProduto.jsx`), nesta ordem:
  1. `portal_sem_frete` → "O preço sugerido pela Precificação do Portal foi calculado SEM frete: este
     número não é uma recomendação confiável. Informe o frete na Precificação do Portal ou digite o
     preço aqui."
  2. `frete_conhecido === false` → "Sem as medidas do pacote o frete do Mercado Livre não entra nesta
     conta: o que você recebe está otimista."
  3. `abaixo_do_custo` → "Você recebe {recebimento} e o custo é {custo}: prejuízo de {|lucro|} já
     antes do imposto."
  4. `prejuizo_com_imposto` → "Depois do imposto que o preço reserva ({imposto_reservado}), sobra
     {lucro_depois_do_imposto}: prejuízo."
  5. `custo === null` → "A Precificação do Portal não tem custo desta oferta: sem custo não há lucro
     nem margem para mostrar." (neutro, `text-white/50`)
- nota fixa (conserto D), 11px `text-white/45`, uma vez por card: "O Mercado Livre não desconta
  imposto: o que você recebe ainda tem imposto a pagar. O preço do Portal já reserva uma parte para
  isso — é a linha \"Imposto reservado\".";
- não tocar em `PrecoDaVariante`, `CampoPreco`, `useEfeitosDoEnvio`, `MedidasDoPacote` nem no bloco de
  envio/garantia. Nada de `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` (D-08);
- nenhuma conta de preço/margem reimplementada em JS (decisão 2).
  </action>

  <verify>
    <automated>node --test tests/js/publicador-quanto-recebo.test.js</automated>
    <automated>node --test "tests/js/publicador-*.test.js"</automated>
    <automated>npm run test:js</automated>
  </verify>

  <done>
    O card mostra, por tipo, preço, recebimento, custo, lucro (antes e depois do imposto) e margem, com
    os cinco avisos e a nota do imposto. Campo objeto/nulo/ausente não produz `[object Object]`, `NaN`
    nem exceção no render real. `npm run test:js` com EXATAMENTE uma falha: a pré-existente
    `estrutura-grade-glide`.
  </done>
</task>

<task type="auto">
  <name>Tarefa 3: Gates, build, commit e SUMMARY</name>
  <files>public/build/** (gerado), .planning/quick/261010-ptg-precificacao-lucro-alvo-zero-frete-fora-/SUMMARY.md</files>

  <action>
1. `npm run build` (obrigatório: a Tarefa 2 tocou `resources/`). Conferir o manifest **pelo JSON**,
   nunca por grep (o hash sai com hífen).
2. Rodar `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador`
   (1713 / 0) e `npm run test:js` (uma falha, a pré-existente). Conferir os `git diff --stat` vazios de
   `app/Support/Publicador`, `app/Models`, `database` e `app/Services/Portal/Estrutura`.
3. Commit — árvore COMPARTILHADA: **pathspec no `add` e no `commit`**, nunca `git add -A`,
   `git stash`, `git reset`, `git checkout`, `git commit --amend`, nunca `vendor/bin/pint`.
   `git add -- <cada caminho tocado>` e `git commit -m <msg> -- <os mesmos caminhos>` (o `-m` antes do
   `--`). Mensagem multilinha acentuada por `-F <arquivo no scratchpad>`, em pt-BR, terminando com
   `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`. **Não** incluir
   `tests/Feature/CompanyPortfolioAccessTest.php` (untracked, de outra tarefa) nem arquivo que você
   não editou.
4. **NÃO deployar, NÃO dar push, NÃO tocar no VPS.** Não mexer em `ROADMAP.md` nem rodar
   `gsd-sdk query state.advance-plan`.
5. SUMMARY.md (pt-BR) com, obrigatoriamente:
   - **a tabela antes × depois do card da Poltrona Beny** (a de `<a_poltrona_beny_antes_e_depois>`),
     deixando explícito que o **preço não mudou** (483,45 / 520,92), que o prejuízo vem do frete
     ausente, e que o Premium que parecia sobrar R$ 1,75 está R$ 97,22 no vermelho depois do imposto
     reservado. Dizer que recebimento e lucro de qualquer preço novo só podem ser medidos pelo usuário
     clicando "Calcular" (tarifa e frete vêm da API real) — **não afirmar ter visto a tela renderizada
     nem ter medido produção**;
   - **o que NÃO mudou e por quê:** `PADROES` (MC/LL em 0,0 é o padrão deliberado do sistema), a
     fórmula, o `acrescimo`, a semântica `anunciado`/`minimo`, o V-SAL-08, e nenhuma migration — com os
     `git diff --stat` vazios de `app/Models`, `database`, `app/Services/Portal/Estrutura` e
     `app/Support/Publicador` como prova. **Nada foi tocado em `app/Services/Portal/Estrutura/`**:
     nada a levar para a coordenação com o outro dev nesta tarefa;
   - baseline antes × depois: PHP 1713 / 0 e JS com a única falha pré-existente;
   - por que o custo saiu por método separado (paridade do `VisaoRapidaDoLoteTest`) e por que existem
     duas contas de margem de propósito (lote por percentuais planejados × card pela API real);
   - a nota de sigilo: custo é dado do cliente, exibido em tela interna da equipe, com o precedente da
     visão rápida do lote; nenhuma regra da Fase 176 tocada;
   - a pergunta em aberto de `<fora_de_escopo>`, para o usuário decidir depois;
   - o que o usuário precisa conferir no navegador (lista curta e literal).
  </action>

  <verify>
    <automated>node -e "const m=require('./public/build/manifest.json');const k=Object.keys(m).filter(x=>/Publicador\/Editor/.test(x));if(!k.length)throw new Error('editor fora do manifest');console.log(k.map(x=>m[x].file))"</automated>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador</automated>
    <automated>npm run test:js</automated>
    <automated>git diff --stat -- database app/Models app/Services/Portal/Estrutura app/Support/Publicador | tee /dev/stderr | wc -l</automated>
  </verify>

  <done>
    Build feito e conferido pelo JSON do manifest. Publicador em 1713 / 0 e JS com uma falha só.
    Commit por pathspec, só com os arquivos desta tarefa, mensagem em pt-BR com o `Co-Authored-By`.
    SUMMARY.md com os sete itens obrigatórios. Nenhum push, nenhum deploy, nenhum comando no VPS.
  </done>
</task>

</tasks>

<fora_de_escopo>
Não fazer nesta tarefa. O primeiro item é **pergunta em aberto para o usuário** (já levada a ele, sem
resposta ainda) e tem de aparecer na SUMMARY:

1. **O desconto automático de 14 dias desce até o ponto de equilíbrio.** Todo anúncio novo recebe a
   promoção automática, e ela baixa o preço até o `minimo` — que é exatamente o break-even. São 14
   dias vendendo com lucro zero, e com prejuízo se o frete estiver errado. **A pergunta é se esse
   desconto deveria parar ACIMA do equilíbrio.** É regra da promoção automática
   (`PrecoDaPromocao` + `promocaoAutomatica.js`, learnings §19), não desta tarefa — e mexer ali obriga
   a mexer nos dois lados juntos, com os mesmos números nos dois testes.
2. `PADROES`, fórmula, `acrescimo`, semântica `anunciado`/`minimo`, V-SAL-08, qualquer migration.
3. Unificar a margem do lote com a do card.
4. O onboarding antigo (`ImplementacaoPublica*`, `resources/js/lib/precificacaoProdutos.js`,
   `tests/Feature/Phase76`): tem a própria configuração e não lê `PADROES`.
5. Acervo, tela de Publicações, filtro de status (tarefa já commitada, `f2a6cf42`); `AnunciarML.jsx`,
   `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` (D-08).
</fora_de_escopo>

<threat_model>
## Fronteiras de confiança

| Fronteira | Descrição |
|-----------|-----------|
| tela do Publicador → `MlbPublicadorController::simular` | rota já autenticada e com permissão de publicação; nenhuma rota nova |
| servidor → tela | props novas (custo, imposto, lucro) atravessam para o React |
| Portal (dado do cliente) → Publicador (tela interna) | custo da variação é informação sensível do cliente |

## Registro STRIDE

| ID | Categoria | Componente | Decisão | Mitigação |
|----|-----------|------------|---------|-----------|
| T-ptg-01 | Information disclosure | custo e imposto no payload de `simular()` | mitigar | sem rota nova: sai pela `simular` existente, atrás da mesma permissão que já mostra custo e margem na visão rápida do lote; nada vai para o portal do cliente nem para resposta pública |
| T-ptg-02 | Tampering | preço e padrões de precificação | mitigar | esta tarefa não escreve preço, parâmetro nem schema; os gates de `git diff --stat` vazio em `app/Models`, `database` e `app/Services/Portal/Estrutura` provam |
| T-ptg-03 | Denial of service | `custosDoProduto()` chama `pagina()` da Precificação | aceitar | só no clique manual em "Calcular", que já faz duas chamadas HTTP ao ML; uma `pagina()` por clique, nada em loop nem em lote |
| T-ptg-04 | Information disclosure | número exibido como se fosse confiável | mitigar | é o próprio objetivo: `portal_sem_frete` e `frete_conhecido === false` marcam o número como não confiável, e a nota do imposto impede ler recebimento como lucro |
| T-ptg-SC | Tampering | instalação de pacote npm/pip/cargo | n/a | **nenhum pacote novo**; se virar necessário, parar e pedir o gate de legitimidade |
</threat_model>

<verification>
- `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` → **1713 passando / 0 falhando**
- `npm run test:js` → exatamente UMA falha: `estrutura-grade-glide` ("Características secundárias nasce recolhido"), não corrigida
- `npm run build` + manifest conferido pelo JSON
- `git diff --stat -- database` → VAZIO
- `git diff --stat -- app/Models` → VAZIO
- `git diff --stat -- app/Services/Portal/Estrutura` → VAZIO
- `git diff --stat -- app/Support/Publicador` → VAZIO
- Nenhum teste chamando API real (ML ou Gemini): `Http::fake` em tudo
</verification>

<success_criteria>
1. O card do editor mostra, por tipo: preço sugerido, recebimento estimado, custo, lucro (antes e
   depois do imposto) e margem estimada.
2. `recebimento < custo` avisa com preço do Portal **e** com preço digitado.
3. Preço do Portal calculado sem frete aparece marcado como número não confiável, apontando a entrada
   manual.
4. A tela diz que o ML não desconta imposto e mostra quanto o preço reserva — o Premium da Poltrona
   aparece como prejuízo, não como R$ 1,75 de sobra.
5. Campo novo como objeto, nulo ou ausente não derruba o render real.
6. `PADROES`, fórmula, `acrescimo`, semântica `anunciado`/`minimo`, V-SAL-08, `App\Support\Publicador`,
   `MlPublicacaoService`, `app/Services/Portal/Estrutura/`, `database/`, acervo, Publicações, filtro de
   status, `AnunciarML.jsx`, `PainelCriativosIa.jsx` e `KitCriativosGrade.jsx` intocados.
7. Gates no baseline; commit por pathspec; sem push, sem deploy, sem VPS.
</success_criteria>

<output>
Criar `.planning/quick/261010-ptg-precificacao-lucro-alvo-zero-frete-fora-/SUMMARY.md` ao terminar,
com os sete itens obrigatórios da Tarefa 3.
</output>
</content>
