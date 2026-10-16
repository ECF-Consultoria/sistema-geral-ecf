---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 06
subsystem: publicador / fila de aprovação
tags: [filtros, busca-sem-acento, contagens, z-index, custo-ml, col-03]
requires:
  - 187-02 (FilaDeAprovacaoService::paraAConta/faixa, a rota)
  - 187-04 (a página e os 4 slots nomeados)
  - 187-05 (ProximoARevisarService, o slot `proximo`)
  - 186 (VocabularioDoPortao, BloqueiosDaOfertaService, vocabularioDoPortao.jsx)
  - 177 (identidade/identidade.js — `iniciais()` e `tomDoProduto()`)
provides:
  - FiltrosDaFilaService (busca sem acento, 4 filtros fechados, 3 recortes de contagem)
  - FiltrosDaFila.jsx + PopoverDeFiltro.jsx
  - as props `filtros_ativos`, `opcoes`, `contagem`, `algum_filtro`
  - o remapeamento de z-index da fila (overlay 10, popover 20) e a prescrição pinada do 187-09
affects:
  - MlbPublicadorAprovacaoController (payload em `$payload`, faixa INLINE)
  - Pages/Mlb/Publicador/Aprovacao.jsx (slot `filtros` preenchido)
tech-stack:
  added: []
  patterns:
    - normalização de busca em PHP (mapa único, dois lados) em vez de collation ou REPLACE em SQL
    - panorama da conta em UMA passada, lido por três consumidores
    - prescrição PINADA de z-index para wave futura, com asserção de arquivo AUSENTE
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php
    - resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx
    - tests/Unit/Publicador/Aprovacao/FiltrosDaFilaServiceTest.php
    - tests/js/publicador-fila-filtros-187.test.js
    - tests/js/publicador-fila-zindex-187.test.js
    - tests/gates/contagens-187-06.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
decisions:
  - "A busca normaliza em PHP, nos dois lados, por um mapa só — collation e cadeia de REPLACE em SQL foram RECUSADAS por medição"
  - "O teto MEDIDO do SQLite são 28 REPLACE encaixados, e o português sozinho são 28: encostar num limite rígido não é projeto"
  - "O M do 'N de M' é o total de TODAS as ofertas da conta (D8 reproduzida, não 'consertada')"
  - "O N é LIDO da faixa, nunca recontado — conta e lista têm de bater (decisões 9 e 16)"
  - "Zero cap e zero degradação no custo do ML; o pré-aquecimento é o 187-10 e não afrouxa este gate"
  - "Não há sticky na linha de filtros: o protótipo :66 não o declara"
  - "O campo `iniciais` SAIU do payload: era uma segunda implementação divergente (IDENT-08)"
metrics:
  duration: "~1 sessão"
  completed: 2026-10-16
  commits: 8
  files_touched: 10
---

# Fase 187 Plano 06: Os filtros da fila Summary

Busca acento/caixa-insensível resolvida em PHP sobre o panorama que o request já materializa — depois de o SQLite ter **estourado o parser** com a cadeia de `REPLACE` em SQL, cujo teto medido (28) é exatamente o tamanho do alfabeto português.

---

## 1. Baselines RE-MEDIDAS no commit de partida `39eedc49`

Medidas **antes** de qualquer edição, só pelo harness (`node --test | grep "^# tests"` nunca passa no Node v24, e `artisan test | grep "Tests:"` passa com teste falhando):

```
COMMIT DE PARTIDA: 39eedc49
JS   (--suite js):   5218 / 5212 pass / 1 fail / 5 skipped
PHP  (--suite php):  2414 ← medido 2396 / 20804 / 0
--autoteste:         20 casos, 0 divergência
PROTOTIPO Aprovacao.dc.html: 1432 linhas, sha256 68624883ce35547e  (INTACTO no fim)
```

| linha | baseline (39eedc49) | fim desta wave | leitura |
|---|---|---|---|
| JS testes | 5218 | **5285** | +67, acima da régua |
| JS falhas | 1 | **1** | a mesma pré-existente da Fase 87 (`estrutura-grade-glide.test.js`), **NÃO corrigida** |
| JS skips | 5 | **4** | ⚠️ **um ACORDOU** — `shadow-pub-flutuante` (ver §7) |
| PHP testes | 2396 | **2414** | +18 |
| PHP asserções | 20804 | **21119** | +315 |
| PHP falhas | **0** | **0** | ⚠️ a falha herdada que o `187-BASELINES.md` §2.2 registra **já não existe** no commit de partida desta wave — alguém a resolveu entre as waves, e **nenhum `REF_PHP_FALHAS` foi criado** |

⚠️ As duas linhas batem, número por número, com a referência do briefing (PHP 2396 / 20.804 / 0 · JS 5218 / 5212 / 1 / 5).

**`--autoteste` (20 casos), colado:**
```
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```
(os 4 casos novos da forma `>=` passam: igual ao mínimo PASSA, acima PASSA, abaixo REPROVA, e falha não é perdoada.)

---

## 2. ⚠️⚠️ A ESTRATÉGIA DA BUSCA SEM ACENTO, E COMO ELA SE COMPORTA NOS TRÊS BANCOS

**Produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite.** As três estratégias foram **MEDIDAS**, não escolhidas por gosto.

### (a) Delegar ao COLLATION — RECUSADA

É o precedente do módulo, `PublicacoesDaListaService::mlItemIdsPorProduto()`, que em 2026-10 decidiu confiar em `utf8mb4_0900_ai_ci`/`utf8mb4_general_ci` e **declarou a limitação no próprio docblock** (`app/Services/Publicador/PublicacoesDaListaService.php:191`-`:198`): o `LIKE` do SQLite dobra ASCII e **não** dobra acento, então lá a busca acento-insensível **não é provada por teste nenhum** (`test_busca_ignora_caixa_e_o_acento_depende_do_collation`).

➡️ FILA-05 exige a busca ignorando acento. Filtro cuja prova depende do banco de produção é filtro que ninguém mede — ele só é conferido quando alguém reclama.

### (b) Normalizar a COLUNA em SQL com cadeia de `REPLACE` — ⚠️⚠️ RECUSADA POR MEDIÇÃO

**Esta estratégia foi IMPLEMENTADA e rodada.** O SQLite estourou:

```
SQLSTATE[HY000]: General error: 1 parser stack overflow
```

Medi o teto, um a um, com SQLite embutido do PHP desta máquina:

| `REPLACE` encaixados | resultado |
|---|---|
| 10, 14, 20, 24, 26, **28** | OK |
| **29**, 30, 32, 36, 40, 44, 48, 52 | `parser stack overflow` |

⚠️ **O teto é exatamente 28.** E o alfabeto **do português sozinho** são 14 letras acentuadas (`á à â ã é ê í ó ô õ ú ü ç` + `ñ` do catálogo ML) × 2 caixas = **28**. A estratégia nasceria **sobre o teto**, e a primeira letra nova a derrubaria — **em produção, não no teste** (SQLite é o único dos três com esse limite). Projeto que encosta num limite rígido medido não é projeto, é sorte.

### (c) Normalizar em PHP, nos DOIS lados, com UMA função — ESCOLHIDA

```php
public function normalizarParaBusca(string $texto): string
{
    return trim(mb_strtolower($this->semAcento($texto)));
}
```

`SEM_ACENTO` é o **Latin-1 acentuado inteiro** (53 pares), e a MESMA função é aplicada ao **TERMO** e ao **CONTEÚDO**. O recorte volta ao `Builder` como `whereIn('id', …)` — **o mesmo mecanismo que o filtro por MOTIVO já exige**.

**"No SERVIDOR" (FILA-05) está satisfeito:** PHP **é** o servidor. O que FILA-05 proíbe é a busca acontecer no NAVEGADOR, e nada dela sai para o cliente.

**Custa ZERO a mais, e é isso que fecha a decisão:** `panoramaDaConta()` **já** materializa todas as ofertas da conta em memória, incondicionalmente, porque o filtro por motivo exige `para()` por oferta. O "N linhas na memória" já está pago em todo request, com ou sem busca.

### Comportamento nos TRÊS bancos

| banco | acento | caixa ASCII | caixa fora do mapa | observação |
|---|---|---|---|---|
| **MySQL 8.0.46** (produção) | pelo mapa (PHP) | `mb_strtolower` (PHP) | `mb_strtolower` (PHP) | **nenhum banco participa da comparação** |
| **MariaDB 10.4.32** (local) | idem | idem | idem | idem |
| **SQLite** (testes) | idem | idem | idem | idem |

⚠️⚠️ **O comportamento é IDÊNTICO nos três porque NENHUM dos três participa da comparação.** Era exatamente o oposto em (b), onde a coluna era normalizada pelo banco e o `LOWER()` do MySQL rebaixa multibyte enquanto o do SQLite **só** rebaixa ASCII — o par divergiria entre os bancos e a busca funcionaria local e falharia em produção.

**Provado no SQLite dos testes, sem depender de collation:** `test_os_dois_lados_da_busca_leem_o_MESMO_mapa_de_acentos_187_06` percorre **as 53 entradas do mapa**, uma oferta por entrada, e exige que a base ASCII ache a gravada acentuada **e vice-versa** — 63 asserções, verdes no SQLite.

**O que (c) GANHA sobre (b), além da portabilidade:**
- **uma** normalização, não duas (o modo de falhar de (b) era `Str::ascii()` de um lado e cadeia menor do outro: `'ñ'` virava `'n'` num lado e ficava `'ñ'` no outro, e a busca **não achava nada, sem erro**);
- o mapa pode ser o Latin-1 inteiro — **cobertura MAIOR**, não menor;
- a **CONCATENAÇÃO do protótipo é reproduzida LITERALMENTE** (`:1211`: `norm(pr.name + ' ' + o.title + ' ' + pr.sku)`), inclusive o termo que atravessa dois campos. Em (b) isso exigiria `CONCAT_WS` (MySQL/MariaDB) ou `||` (SQLite) — e `||` é **OR LÓGICO** no MySQL sem `PIPES_AS_CONCAT`;
- `%` e `_` digitados **param de ser curinga** (`str_contains`, não `LIKE`). Em `PublicacoesDaListaService` eles ainda são, e lá é limitação declarada.

**Limite declarado de (c):** a busca não usa índice e é O(n) sobre as ofertas da conta. **É o MESMO teto que o filtro por motivo já impõe** — não é um teto novo, e a mitigação é a mesma decidida pelo usuário: pré-aquecer fora do request (plano `187-10`).

---

## 3. ⚠️⚠️ O GATE DE CUSTO (id `custo-do-ml`) — 1 requisição por trio, com isca nas duas direções

`Http::fake` com gravador + `Http::preventStrayRequests()`. Duas asserções, cada uma com isca.

**Gate A — dedupe por trio (`test_CUSTO_uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_06`):**

| cenário | ofertas | requisições a `/sites/MLB/listing_prices` |
|---|---|---|
| mesmo trio `(MLB189007, gold_special, 489.90)` | 2 | **1** ✓ |
| ⚠️ **isca invertida** — trios DIFERENTES (`MLB193945/gold_special` e `MLB189007/gold_pro`) | +2 | **2** ✓ |

A isca invertida é o que torna o gate uma medição: sem ela, um `panoramaDaConta()` que **nunca** chamasse `para()` passaria na primeira asserção. E há uma terceira asserção provando que **nenhum trio repetido** sai na rede (`array_unique` sobre `categoria|tipo|preço` das chamadas gravadas), mais a prova de que as 4 ofertas recebem `kinds` de verdade — **zero cap, zero "sem tarifa", zero "incompleto por timeout"**.

A chave de cache medida é literalmente o trio: `'aprovacao:tarifa:'.$categoria.':'.$listingType.':'.(int) round($preco * 100)` (`ComposicaoFinanceiraService:474`).

**Gate B — `para()` 1× por oferta (`test_CUSTO_para_e_chamado_exatamente_uma_vez_por_oferta_187_06`):**
- espião **por SUBCLASSE** de `BloqueiosDaOfertaService` (não mock de string: renomear `para()` quebra o teste em vez de passar calado);
- 6 ofertas (uma por andamento) → **6** chamadas, conjunto exato, sem repetição;
- os **três** consumidores (`motivosDaConta`, `idsComMotivo`, `aplicar` com motivo) leem o MESMO mapa e **não somam chamada nenhuma**;
- ⚠️ **isca**: rodar `panoramaDaConta()` de novo sobe para **12** — sem ela, um contador cravado em 6 passaria.

**O que isto fecha:** chamar `para()` dentro do laço das 8 contagens multiplicaria as requisições ao ML **por oito**.

⚠️ **O aquecimento do `187-10` muda QUANDO a requisição acontece, não QUANTAS** — os dois gates continuam valendo depois dele, sem afrouxamento. E o docblock do serviço aponta para lá, registrando que *"limitar N consultas por abertura"* foi **oferecido e RECUSADO** pelo usuário em 15/10, porque **"Incompleto" bloqueia a aprovação**.

⚠️ **Aviso herdado, confirmado:** `test_a_fila_nao_chama_o_mercado_livre_187_02` está verde porque a fixture dele cria o rascunho **sem `categoria_id`**, e aí `tarifaDoMl()` devolve "sem fonte" sem sair para a rede. Aquele gate **não prova** que a fila nunca chama o ML. Os dois desta wave criam `categoria_id` de propósito e **medem a rede**.

---

## 4. A tabela FID-01, MEDIDA POR SCRIPT

**Diretório citado:** `design_handoff_publicador/` — arquivo `Aprovacao.dc.html`, 1432 linhas, sha256 `68624883ce35547e` (conferido **intacto** no fim da wave). Recorte da linha de filtros: **`:66`-`:105`** (40 linhas).

Script rodado (varredura de 39 propriedades, no molde do `187-BASELINES.md` §5, com `(^|[;"])` onde substring casa prefixo):

```
font-size        9.5px|10px|12px|12.5px|13px      position        absolute|relative
font-weight      600|800|{{ o.w }}                 left            0|11px
border-radius    4px|7px|8px|10px|50%              top             38px
height           15px|22px|32px|34px|100%          z-index         45          ⚠️ ver D1
width            15px|22px|100%|220px|240px|260px  box-shadow      0 16px 40px rgba(0,0,0,0.6)
gap              6px|8px|10px                      white-space     nowrap
padding          0 8px|0 10px|0 11px|0 10px 0 32px|6px              text-align      left
background       #111111|#1A1A1A|transparent|+5 binding            text-decoration underline
border           none|1px solid #2E2E2E|+3 binding text-underline-offset 3px
border-color     #FACC15  (style-focus)            margin-left     auto
border-right     1px solid #1F1F1F                overflow        hidden
color            #0A0A0A|#71717A|#8A8A90|#A1A1AA|#F4F4F5|+1 binding
display flex|grid · place-items center · flex-wrap wrap · outline none · pointer-events none
fill none · stroke #8A8A90 · stroke-width 1.6 · stroke-linecap round · viewBox "0 0 16 16"
style-hover  background:#1A1A1A   ·   style-focus  border-color:#FACC15
placeholder  "Produto, título ou SKU"
min-width    (nenhum no recorte — varredura vazia é medida, não descuido)
```

### ⚠️⚠️ `cursor` — COLUNA PRÓPRIA

**7 `cursor:pointer` e 7 `onClick=` no recorte — razão 1:1, contados por script.** Nenhum clicável sem cursor, nenhum cursor sem clique.

| `:linha` | elemento | o que é | implementado em |
|---|---|---|---|
| `:73` | `<button>` | cada opção do segmentado de **Modalidade** (3) | `FiltrosDaFila.CLASSES_OPCAO` |
| `:77` | `<button>` | cada opção do segmentado de **Formato** (3) | `FiltrosDaFila.CLASSES_OPCAO` |
| `:80` | `<button>` | o disparador do popover **Responsável** | `PopoverDeFiltro.CLASSES_BOTAO` |
| `:84` | `<button>` | cada linha do popover de Responsável | `PopoverDeFiltro.CLASSES_ITEM` |
| `:93` | `<button>` | o disparador do popover **Motivo de bloqueio** | `PopoverDeFiltro.CLASSES_BOTAO` |
| `:97` | `<button>` | cada linha do popover de Motivo | `PopoverDeFiltro.CLASSES_ITEM` |
| `:104` | `<button>` | "Limpar filtros" (só com `anyFilter`) | `FiltrosDaFila.CLASSES_LIMPAR` |

Nos meus arquivos: `FiltrosDaFila.jsx` tem **2** `cursor-pointer` / 2 `<button>`; `PopoverDeFiltro.jsx` tem **2** `cursor-pointer` + **1** `cursor-default` / 3 `<button>`.
⚠️ O `cursor-default` é o **overlay** de clique-fora: ele não é afordância de clique, é captura. O protótipo (`:546`) também o faz sem cursor — é um `<div>` lá, e aqui é `<button aria-label="Fechar">` por acessibilidade de teclado. **Divergência declarada.**

### ⚠️⚠️ 13 dos 20 PONTEIROS DO PLANO ESTAVAM DESLOCADOS

| elemento | plano | **MEDIDO** |
|---|---|---|
| linha / lupa / input | `:66` / `:68` / `:69` | iguais ✓ |
| segmentado | `:71` / `:75` | iguais ✓ |
| rótulo do segmentado | `:72` | `:72` **+ `:76`, que o plano OMITE** ⚠️ |
| opção ATIVA/inativa (`seg()`) | `:1280` | **`:1267`** ⚠️ |
| botão de popover | `:79` / `:90` | **`:80` / `:93`** ⚠️ |
| ATIVO/inativo (`act()`) | `:1281` | **`:1268`** ⚠️ |
| sufixo "· N" e o `▾` | `:79` | **`:80`** (+ `:93`) ⚠️ |
| popover | `:81` / `:92` | **`:82` / `:95`** ⚠️ |
| item | `:83` | **`:84` / `:97`** ⚠️ |
| checkbox | `:84` | **`:85` / `:98`** ⚠️ |
| avatar | `:85` | **`:86`** ⚠️ |
| contagem do item | `:85` / `:93` | **`:86` / `:98`** ⚠️ |
| `hint-placeholder-count="6"` (D6) | `:98` | **`:96`** ⚠️ |
| "Limpar filtros" | `:101` | **`:104`** ⚠️ |
| "N de M ofertas" | `:102` | **`:105`** ⚠️ |
| `countLabel` (D8) | `:1275` | **`:1276`** ⚠️ |
| `respOpts` / `blockOpts` | `:1277` / `:1278` | **`:1265` / `:1266`** ⚠️ |
| `anyFilter` | — | `:1270` |

### ⚠️⚠️ QUATRO OMISSÕES de `white-space:nowrap` na tabela do plano

A tabela de fidelidade deste projeto **não erra VALOR: erra por OMISSÃO e por REFERÊNCIA.** `white-space:nowrap` é declarado — e em três casos é a **PRIMEIRA** propriedade do elemento — em:

| `:linha` | elemento | consequência da omissão |
|---|---|---|
| `:73`/`:77` | opção do segmentado | "Clássico" quebraria dentro de um segmentado de 32px fixos |
| `:80`/`:93` | botão de popover | "Motivo de bloqueio · 2" quebraria em duas linhas |
| `:84`/`:97` | item do popover | "Margem abaixo do mínimo" quebraria dentro de 34px |
| `:104` | "Limpar filtros" | quebraria quando a linha envolve (`flex-wrap:wrap`) |

Todas as quatro estão implementadas e **com gate**.

---

## 5. ⚠️⚠️ O REMAPEAMENTO DE Z-INDEX (divergência D1), e a prova de que a ordem travada não quebrou

O protótipo monta o header em `z-index:50` (`:26`) e usa 44/45/60/120. **Neste projeto o header é `z-30`** e a ordem é **TRAVADA** por `tests/js/publicador-header-zindex-ordem.test.js` — invariante medido do bug que o usuário relatou em 12/10/2026.

| peça | protótipo | **prescrito aqui** | por quê |
|---|---|---|---|
| camada de filtros do corpo | — | `z-10` (travado, **lido**) | — |
| overlay que fecha o popover | `:546` z-**44** | **`z-10`** | abaixo do popover, acima das linhas |
| popover de filtro | `:82`/`:95` z-**45** | **`z-20`** | abaixo do header `z-30`, acima do overlay |
| header global | `:26` z-**50** | `z-30` (travado, **lido**) | — |
| barra flutuante de lote (187-09) | `:897` z-**60** | **`z-[35]`** (prescrição pinada) | acima do header, abaixo do menu/painel |
| menu lateral = painel do produto | — | `z-40` (travado, **lido**) | — |
| toast (187-09) | `:905` z-**120** | **`z-[45]`** (prescrição pinada) | acima de tudo |

**A ORDEM RELATIVA do protótipo foi preservada e nenhum número dele foi copiado.** Há asserção explícita: nenhuma das 6 peças pode ter valor em `[44, 45, 50, 60, 120]`.

**A prova de que a ordem travada não quebrou** (`tests/js/publicador-fila-zindex-187.test.js`, 6 testes):
- os **quatro** pontos travados são **LIDOS dos arquivos deles** (`PublicadorHeader.jsx`, `AppLayout.jsx`, `PainelDoProdutoLateral.jsx`, `Produtos.jsx`), com as **mesmas âncoras** — e há asserção comparando cada âncora, byte a byte, com o texto do arquivo travado, para que os dois gates não passem a ler elementos diferentes em silêncio;
- `overlay < popover < header < menu = painel`, por desigualdade;
- `overlay == filtrosDaGrade` (as duas peças que vivem no corpo da página);
- a extração **não casa por prefixo** (`z-1` dentro de `z-10`, `z-10` dentro de `z-100`), provado contra strings sintéticas;
- **isca** de âncora inexistente + contraprova de que a de verdade casa;
- **isca de remoção de comentário**: o docblock do popover CITA `z-index:45`/`z-44`/`z-index:50` de propósito, e o gate exige que a limpeza os tire;
- ⚠️ **`tests/js/publicador-header-zindex-ordem.test.js`: `numstat` ZERO.** Confirmado por `git show --numstat` sobre os 8 commits do escopo.

**A prescrição do 187-09 NÃO é decorativa:** ela é conferida contra os pontos **lidos** (`lote > header`, `lote < menu`, `toast > menu`), então mover o header reprova a prescrição e força decisão nova. E há asserção de que os dois arquivos do 187-09 **ainda NÃO existem** — quando nascerem, o gate obriga a trocar prescrição por leitura.

### ⚠️ NÃO HÁ STICKY, e isso é medido

O docblock da página (187-04) antecipava *"sticky de filtros, quando chegar (187-06), é `top-[108px]`"*. **Medido por script: a linha `:66` do protótipo não declara `position:sticky`, nem `top:`, nem `z-index:`** — a varredura do recorte devolve `position: absolute|relative` (a lupa e as âncoras dos popovers) e nada mais. A tabela FID do próprio plano prescreve `flex flex-wrap items-center gap-[8px]`, sem sticky.

➡️ **Não há sticky.** Inventá-lo seria efeito que o protótipo não pede e que ninguém pode conferir nesta máquina. Há **asserção de ausência** (`doesNotMatch(/sticky|top-\[108px\]|top-0/)`) para que ninguém o "conserte" sem decisão nova, com o valor certo escrito ao lado (`sticky top-[108px] z-10`, nunca `top-0`).

---

## 6. ⚠️⚠️ A PROVA DE QUE OS POPOVERS NÃO PRECISAM DE PORTAL

**Medido no protótipo:** os dois popovers são `position:absolute` (`:82`, `:95`) dentro de um pai `position:relative` da própria linha de filtros (`:79`, `:92`). ⚠️ **Não** `position:fixed`.

O `backdrop-filter` do header global cria containing block **só para `position:fixed`** — então ele **não os afeta**. ➡️ Nada de portal, e o `renderToStaticMarkup` dos gates enxerga o conteúdo do popover (confirmado: `HTML_MENU_RESP` e `HTML_MENU_MOTIVO` contêm os itens, as larguras e os avatares).

⚠️ A barra flutuante e os toasts do **187-09 são `fixed`**, e lá a análise é outra — registrado no docblock.

E o `z-20` do popover competir na raiz é consequência disso: o pai `relative` **sem** `z-index` tem `z-index: auto` e **não** cria contexto de empilhamento.

---

## 7. Os 5 tokens em skip do 187-01 — QUAIS ACORDARAM

CSS lido **pelo JSON do manifest** (`manifest['resources/js/app.jsx'].css[0]`, nunca por grep — o hash sai com HÍFEN), arquivo `assets/app-4GPg1QMX.css`, **228.009 bytes**.

| token | antes (187-01) | agora | onde ele foi MEDIDO no protótipo |
|---|---|---|---|
| **`shadow-pub-flutuante`** | skip | ⚠️ **ACORDOU** | os dois popovers (`:82`/`:95`) — **é esta wave** |
| `bg-pub-float` | skip | **segue em skip** | a barra de lote (`:897`) e o toast (`:905`) → **187-09** |
| `border-pub-float-line` | skip | **segue em skip** | idem → **187-09** |
| `bg-pub-sunken-2` | skip | **segue em skip** | a sub-linha de conjunto (`:124`) → **187-08** |
| `bg-pub-anel-parcial` | skip | **segue em skip** | a linha da oferta → **187-08** |

⚠️ **Nenhum token foi usado fora do lugar onde foi medido para "acordar o skip".** A wave 4 recusou isso e estava certa. Os popovers são `bg-pub-surface` + `border-pub-line-strong` (`#111111`/`#2E2E2E`, `:82`), **não** `float`/`float-line` (`#1C1C1C`/`#333333`, que são os FLUTUANTES `fixed`).

**CSS conferido pelo JSON, 55 classes, valores medidos** (amostra do que importa):
```
shadow-pub-flutuante    {--tw-shadow: 0 16px 40px rgba(0,0,0,.6)}      ← o valor de :82/:95
bg-pub-accent/[0.16]    {background-color:#facc1529}   alfa 0x29 = 16%  ← segmentado
bg-pub-accent/[0.12]    {background-color:#facc151f}   alfa 0x1F = 12%  ← fundo do popover
border-pub-accent/[0.55]{border-color:#facc158c}       alfa 0x8C = 55%  ← borda do popover
text-[9.5px]            {font-size:9.5px}              ← só compila porque o 187-01 liberou 9.5
font-[800]              {font-weight:800}              ← NÃO `font-extrabold` (ver §9)
w-[220px] / w-[260px]   {width:220px} / {width:260px}  ← as duas larguras, distintas
z-10 / z-20             {z-index:10} / {z-index:20}    ← o remapeamento
```
⚠️ Os **TRÊS alfas são DIFERENTES no CSS compilado**, e há asserção `new Set(alfas).size === 3` — é a prova, no artefato final, de que os dois tints não colapsaram (FID-02).

**Iscas do medidor de CSS, todas AUSENTES:** `bg-pub-isca-187-06`, `text-[99.5px]`, `w-[997px]`, `h-[461px]`, e ⚠️ **`duration-[220ms]`** — confirmando que `duration-[Nms]` **continua não compilando** neste projeto, com `duration-200` (`transition-duration:.2s`) como controle positivo. **Esta linha de filtros não tem transição nenhuma.**

**Manifest:** 494 entradas. `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` **PRESENTE**; isca `resources/js/Pages/Mlb/NaoExiste9z7.jsx` **AUSENTE**.

---

## 8. O "N de M", os 8 motivos, e os TRÊS recortes

### ⚠️ D8 reproduzida, não "consertada"

`countLabel` é `list.length + ' de ' + all.length + ' ofertas'` (**`:1276`**, confirmado por `grep` — o plano aponta `:1275`). O **M** é o total de **TODAS** as ofertas da conta, **inclusive `preparando` e `publicado`**.

Provado dos dois lados:
- **serviço**: 5 ofertas (3 ao vivo + 1 `preparando` + 1 `publicado` de outro dia) → `totalDaConta` = **5**, `contadorDaAba` = **3**, e o card "Todas" da faixa = **5** (o M e o card TÊM de ser o mesmo número);
- **tela**: o `12` do "N de M" é **idêntico** nos dois renders (sem filtro `6 de 12`, com filtro `1 de 12`) — só o **N** desce. Mudar o M para o total da fila reprovaria.

**O N é LIDO da faixa, nunca recontado** (`exibidasNaEtapa($faixa, $etapa)`). A razão é a **decisão 19**: a etapa `publicado` recorta **"publicado HOJE"** (`whereDate('updated_at', hoje)`, `AndamentoDaOfertaService:294`-`:295`), e uma segunda consulta divergiria do card. Provado: a `publicado` de 01/10 conta no M (**5**) e **não** na etapa `publicado` (**0**); a de hoje conta nos dois. O usuário recusou número divergente **duas vezes** (decisões 9 e 16).

### ⚠️ D6 — os OITO motivos: o DADO ganha do placeholder

O template do popover declara `hint-placeholder-count="6"` (**`:96`**, não `:98`) e `BLK` declara **OITO** (`:973`). **São 8**, lidos de `VocabularioDoPortao::ROTULOS` no servidor e de `ROTULO_DO_BLOQUEIO` na tela, **na ordem do módulo**:

| # | chave | rótulo |
|---|---|---|
| 1 | `fotos_a_conferir` | Fotos a conferir |
| 2 | `alerta_em_foto_de_ia` | Alerta em foto de IA |
| 3 | `resultado_negativo` | Resultado negativo |
| 4 | `margem_abaixo_do_minimo` | Margem abaixo do mínimo |
| 5 | `frete_ausente` | Frete ou custo ausente |
| 6 | `ajuste_em_aberto` | Ajuste solicitado |
| 7 | `alteracao_nao_conferida` | Alteração não conferida |
| 8 | `falha_na_publicacao` | Falha na publicação |

⚠️ **São os 8 de `ROTULOS`, NÃO `OS_OITO`** — mesmo tamanho, conteúdo diferente: `alerta_em_foto_de_ia` e `falha_na_publicacao` são `kinds` e não bloqueios; `sem_fotos`, `titulo_longo` e `em_preparacao` **não são filtráveis**. Há gate recusando os três como `?motivo=`.
⚠️ Os oito aparecem **sempre**, inclusive com contagem **0** (zero MEDIDO é fato); contagem **AUSENTE** não desenha número nenhum. E chave desconhecida **não** vira item com a própria chave na tela (seria jargão).
⚠️ Os 8 rótulos **não estão escritos no componente** — há asserção de que nenhum dos 8 literais aparece na fonte dele.

### Os TRÊS recortes, provados LADO A LADO

4 ofertas (2 "Poltrona", 2 "Mesa"; metade aguardando, metade aprovada), com `busca=poltrona&etapa=aprovado`:

| recorte | sobre o quê | número | medido no protótipo |
|---|---|---|---|
| **FAIXA** de 9 cards | a `base` = todos os filtros **MENOS** a etapa | `todas` = **2** | `cnt` sobre `base`, `:1213` |
| **POPOVERS** | **TODAS** as ofertas da conta, zero filtro | **4** | `all.filter(...)`, `:1265`/`:1266` |
| **LISTA** (o N) | a `base` **+** a etapa | **1** | `base.filter(stage)`, `:1214` |

**Três números diferentes, no mesmo caso, de propósito**, com `count(array_unique([2,4,1])) === 3` provando que o cenário distingue. E as duas direções: a **etapa NÃO move** a faixa (`assertSame($semNada, $comEtapa)`) e a **busca MOVE** (4 → 2).

---

## 9. Desvios e divergências — com `arquivo:linha`

### Desvios automáticos (Rules 1-3)

| # | regra | o quê | onde |
|---|---|---|---|
| **D-01** | **Rule 1** | ⚠️⚠️ A estratégia da busca foi **trocada por medição**: a cadeia de `REPLACE` em SQL estoura o parser do SQLite, teto 28 (= o português inteiro). Ver §2. | `FiltrosDaFilaService.php:46`-`:95` |
| **D-02** | **Rule 1** | ⚠️ O campo **`iniciais` SAIU do payload**: era uma **segunda implementação** da regra, **divergente** da única declarada (`identidade/identidade.js:27`, IDENT-08 *"nenhuma tela reimplementa"* — só palavras com MAIS de 2 letras). Medido: *"Ana de Souza Lima"* dava **AL** no PHP e **AS** no módulo. Agora o servidor manda o NOME e o componente deriva pelo módulo. | `FiltrosDaFilaService.php:~500` / `PopoverDeFiltro.jsx:2` |
| **D-03** | **Rule 1** | ⚠️ `String(` **saiu da página**: o handler de alternar filtro fazia `String(v)` sobre o array CRU do servidor, e `join(',')` chama `toString` de cada elemento — `{toString:null}` **LANÇA**. Não derrubaria o render, derrubaria o **CLIQUE**. Cada elemento passou a ser validado, e a conversão virou TEXTO→TIPO (`Number.parseInt`, que nunca lança). | `Aprovacao.jsx:300`-`:320`, `:340`-`:365` |
| **D-04** | **Rule 2** | ⚠️ `filtros` (NÓ) × **`filtros_ativos`** (DADO): o controller ia mandar dado na prop que o 187-04 declarou como SLOT DE NÓ. Prop com dois sentidos foi achada como **defeito grave** na Fase 186; o 187-05 já resolveu o caso gêmeo (`proximo` × `proximo_a_revisar`). | `MlbPublicadorAprovacaoController.php:~215` |
| **D-05** | **Rule 3** | `font-extrabold` → **`font-[800]`**: o GATE-03 aceita só os 4 pesos do README e reprova `font-(thin\|extralight\|light\|extrabold\|black)`. Mesmo peso computado, nome que a régua permite; precedente ao lado em `CabecalhoDaFila.jsx:186`. | `PopoverDeFiltro.jsx:172` |
| **D-06** | **Rule 3** | O payload virou `$payload` com a **`faixa` INLINE**: guardá-la numa variável reprovava o CONTROLE de `publicador-fila-proximo-187.test.js` (wave 5, território **PROIBIDO** a esta wave), e recalculá-la custaria **nove** `count()` a mais. Ver §11. | `MlbPublicadorAprovacaoController.php:172`-`:182` |
| **D-07** | **Rule 2** | `algumFiltro()` recebe a etapa como **BOOLEANO**, não string: receber a string obrigaria o serviço a conhecer o valor `'todas'`, que mora no controller — uma segunda cópia daquela verdade. | `FiltrosDaFilaService.php:~290` |
| **D-08** | **Rule 2** | O `key_link` do plano (`paraAConta`) estava **ausente** (medido: `grep -cE` = 0). Entrou como documentação do que de fato acontece — este serviço **recebe** o Builder e a trava T-186-14 mora em quem o monta —, **não** reescrevendo o recorte (que seria segunda verdade, e uma que pode nascer SEM a trava). | `FiltrosDaFilaService.php:~37` |

### Divergências do protótipo e do plano

| # | divergência | `arquivo:linha` |
|---|---|---|
| **V-01** | ⚠️⚠️ **13 dos 20 ponteiros da tabela FID do plano estavam DESLOCADOS**, e o plano **OMITE** o 2º rótulo de segmentado (`:76`). Tabela completa em §4. | `Aprovacao.dc.html:66`-`:105`, `:1265`-`:1276` |
| **V-02** | ⚠️⚠️ **QUATRO OMISSÕES de `white-space:nowrap`** nas classes prescritas — em três casos é a PRIMEIRA propriedade do elemento. §4. | `Aprovacao.dc.html:73`, `:80`, `:84`, `:104` |
| **V-03** | ⚠️ **NÃO HÁ STICKY** na linha de filtros, contra o que o docblock do 187-04 antecipava. Medido por script: `:66` não declara `position`, `top:` nem `z-index:`. §5. | `Aprovacao.dc.html:66` × `Aprovacao.jsx` (docblock do 187-04) |
| **V-04** | `formato=unidade` casa `quantidade_kit = 1`, e o protótipo casa `<= 1` (`!(units > 1)`). `quantidade_kit` 0 ou negativo é dado que não deveria existir, e chamá-lo de "Unidade" seria afirmação FALSA — é a MESMA decisão de `ProximoARevisarService::formato()`, que devolve `null` abaixo de 1. Kit 0 aparece em "Todos" e em nenhum dos dois. | `Aprovacao.dc.html:1211` × `FiltrosDaFilaService.php:~405` |
| **V-05** | O overlay de clique-fora é `<button aria-label="Fechar">` com `cursor-default`; no protótipo (`:546`) é um `<div>` sem cursor. `<div onClick>` não é alcançável por teclado. | `Aprovacao.dc.html:546` × `PopoverDeFiltro.jsx:~160` |
| **V-06** | **`filtros-servico`** (nome de id que o plano prescreve) **não existe**: ele cobriria os 14 métodos PHP numa alternância só, e alternância grande equivale a filtro de CLASSE, que passa dos 120 s do shell e o helper RECUSA (medição do 186-07). Virou **quatro** ids por assunto, com a bijeção provada por script. | `contagens-187-06.txt:36`-`:52` |
| **V-07** | O docblock de `FiltrosDaFila.jsx` **não cita número de z-index do protótipo** (ele fala da escala daqui), então a isca de remoção de comentário do gate de z mede **só** `PopoverDeFiltro.jsx`. Inventar prosa para alimentar isca seria pior. | `publicador-fila-zindex-187.test.js:~250` |

### Autocorreções de GATE que eu mesmo fiz (gates que reprovavam o código CERTO)

| # | o quê | por quê era errado |
|---|---|---|
| **G-01** | `\btrue\b\|\bfalse\b` saiu da regex de vazamento do render adverso | casava `aria-pressed="true"`, que é HTML legítimo e acessível — família **"gate mais largo que o invariante"** (9 ocorrências nesta milestone) |
| **G-02** | o sinal do teste do mapa de acentos ganhou **índice** (`Zz<i><letra>Zz`) | **seis** entradas do mapa compartilham a base `a`, então um sinal sem índice fazia o termo casar as SEIS linhas e a asserção `=== [$id]` reprovava o certo |
| **G-03** | a fixture passou a derivar o `titulo` do nome do produto | um literal fixo com "Poltrona" fazia **toda** oferta casar `busca=poltrona` pelo TÍTULO: **gate cego por construção**, e dois casos reprovavam o certo |
| **G-04** | `'premium '` saiu da lista de "forjados" e virou asserção própria | `trim` antes da lista fechada é **decisão**: link colado com espaço no fim não pode mudar o recorte |
| **G-05** | a ordem das ofertas vistas virou **CONJUNTO** | `panoramaDaConta()` não promete ordem (e não deve — a ordem da fila é de `ordenarFila()`) |
| **G-06** | o ramo de saída antecipada do gate de território **saiu** | dava DOIS valores de contagem (33 × 63) e o manifesto aceita um; e território sem commit é **gate vazio por construção** — tem de REPROVAR |
| **G-07** | a isca de âncora inexistente virou `zzz-` | `zz-` **colidia com a isca do próprio arquivo travado** — isca que colide com isca alheia é falso positivo esperando a hora |

---

## 10. ⚠️⚠️ A CAMPANHA DE MUTAÇÃO — 17 mutações, **DUAS LACUNAS REAIS** achadas

Receita cumprida item por item: commit **antes**, `cp` para o scratchpad **antes** de mutar, ocorrência **travada em 1 ANTES**, linha **conferida depois**, restauração **DA CÓPIA** em `trap EXIT`, `sha256` contra `git show HEAD:` a cada rodada. ⚠️ **`git checkout` NUNCA.**

### Rodada 1 — 13 mutações, 2 sobreviventes

| # | mutação | gate | veredicto |
|---|---|---|---|
| 1 | tirar a normalização de acento | `busca-sem-acento` | MORREU |
| 2 | buscar só no título | `busca-sem-acento` | MORREU |
| 3 | `is_string` → cast (o 500 do T-180-04-T) | `busca-sem-acento` | MORREU |
| 4 | fronteira do combo: `>= 2` → `> 2` | `filtros-fechados` | MORREU |
| 5 | lista fechada do motivo aceita tudo | `filtros-fechados` | MORREU |
| 6 | **popovers contados sobre o recorte** | `tres-escopos` | ⚠️ **SOBREVIVEU** |
| 7 | **o M vira o total ao vivo** | `tres-escopos` | ⚠️ **SOBREVIVEU** |
| 8 | tint do popover vira o do segmentado | `filtros-render` | MORREU |
| 9 | `w-[260px]` → `w-[220px]` | `filtros-render` | MORREU |
| 10 | popover sobe para **`z-40`** | `filtros-zindex` | MORREU ✓ (contra a ordem travada) |
| 11 | contagem ausente desenha `0` | `filtros-render` | MORREU |
| 12 | rótulo escrito em vez de lido do módulo | `filtros-render` | MORREU |
| 13 | filtro em `useState` | — | abortada pela guarda (a troca continha o alvo) |

### As duas sobreviventes — INVESTIGADAS, e eram **lacuna real**

**A causa das duas era a MESMA, e não era a asserção: era a FIXTURE.** O caso das props tinha **uma** oferta ao vivo só, e com uma oferta "a conta inteira" e "as ao vivo" davam o **MESMO número**. Fixture que não distingue é **gate cego**, mesmo com a asserção certa escrita ao lado. E as asserções de escopo moravam no **serviço** (unidade), não nas **PROPS** — que é onde a fiação do controller aparece.

**Conserto (commit `42578cd1`):** a fixture passou a ter **TRÊS** ofertas (1 ao vivo + 1 `preparando` + 1 `publicado` de hoje), com `assertSame(1, contadorDaAba())` como **isca cega** de que ela distingue; e entraram asserções pelas PROPS (`contagem.total === 3`, `opcoes.responsaveis[0].total === 3` **com os seis filtros ativos**, soma dos 8 motivos > 0, forma da opção `['id','nome','total']`).

### Rodada 2 — 4 mutações, **1 ainda sobrevivia**

| # | mutação | gate | veredicto |
|---|---|---|---|
| R2-1 | popovers contados sobre o recorte AO VIVO | `filtros-na-url`, `tres-escopos` | ⚠️ **AINDA SOBREVIVEU** |
| R2-2 | o M vira o total ao vivo | `filtros-na-url` | MORREU |
| R2-3 | responsáveis contados sobre a base FILTRADA | `filtros-na-url` | MORREU |
| R2-4 | filtro em `useState` sem URL | `filtros-render` | MORREU |

**Investigação de R2-1:** estreitar `panoramaDaConta()` passou por **todas** as asserções novas, porque as contagens de motivo da fixture vinham da oferta ao vivo e a soma continuava > 0. **Quem discrimina é a BUSCA** — ela é resolvida sobre o MESMO panorama, e uma oferta `preparando` existe no recorte DA CONTA e não no das ao vivo.

**Conserto (commit `fe910701`):** a `preparando` ganhou nome distintivo (`Banqueta Zyx Exclusiva`), e entraram duas asserções pelas PROPS — buscar por ela ACHA **1** com o panorama certo e **0** com o estreitado — mais a **isca do termo impossível** (que prova que o `1` não veio de uma busca que ignora o termo).

### Rodada 3 — as 4 de novo: **0 sobreviventes**

```
RODADA 3: 4 mutações, 0 sobrevivente(s).
```
E a restauração conferida em cada uma: `sha256 bate com git show HEAD:` (`b42e4c5e1e35526d` / `9948b95328948c87`).

⚠️ **E mutação que NÃO reprova quando deveria também é informação:** a mutação 13 **abortou** porque a minha string de troca CONTINHA o alvo e a guarda *"o alvo ainda está no arquivo"* disparou. **A guarda estava CERTA** — foi ela que impediu uma mutação pela metade de ser lida como "sobreviveu". Na rodada 2 ela virou condicional, com a razão escrita.

---

## 11. ⚠️ DOIS GATES DE OUTRAS WAVES FICARAM VERMELHOS — e os dois eram MEUS

A suíte JS apontou dois gates das waves 4 e 5. **Nenhum deles reprovou trabalho correto**, e **nenhum arquivo alheio foi tocado** (`numstat` ZERO, confirmado).

| gate | por quê ficou vermelho | conserto, do MEU lado |
|---|---|---|
| `publicador-fila-cabecalho-187.test.js` (wave 4) — *"nenhum `String(`"* | A minha página tinha `String(v)` no handler e `join(',')` sobre o array CRU do servidor. **O gate estava certo**: era a TELA PRETA com outra roupa, no CLIQUE. | validação por elemento + conversão TEXTO→TIPO (**D-03**) |
| `publicador-fila-proximo-187.test.js` (wave 5) — CONTROLE `'faixa' => $fila->faixa($base)` | Eu guardei a chamada numa variável `$faixa` porque `contagem.exibidas` precisa LER a faixa calculada (recalcular = 9 `count()` a mais). | payload em `$payload` com a chamada **INLINE** e `exibidas` preenchido depois, lendo `$payload['faixa']` — **UMA** vez, e conta e lista não podem divergir (**D-06**). Há comentário no código pedindo para **não** "arrumar" de volta sem conferir aquele controle. |

Depois do conserto (commit `ebbb6f46`), os quatro arquivos de gate da fila rodam juntos: **210 testes, 210 pass, 0 fail, 0 skipped.**

---

## 12. `ARQUIVOS_NOVOS` e o GATE-03

Os **dois** `.jsx` desta wave entraram em `ARQUIVOS_NOVOS` de `tests/js/publicador-vocabulario-novo.test.js`, **no mesmo plano que os cria** (declarar antes **LANÇA** no carregamento, porque `FONTES` lê todos os caminhos):

```js
`${DIR_APROV}/FiltrosDaFila.jsx`,
`${DIR_APROV}/PopoverDeFiltro.jsx`,
```

**GATE-03 verde:** `# tests 457 / # pass 457 / # fail 0 / # skipped 0`.

⚠️ `DIR_APROV` está em `DIRETORIOS_FECHADOS`, então o `assert.deepEqual(noDisco, declarados)` reprovaria na hora se eu tivesse esquecido — e foi exatamente esse `deepEqual` que me cobrou. Nenhum arquivo de outra wave foi pré-declarado.

⚠️⚠️ **`publicador-vocabulario-novo.test.js` NÃO entra em id composto nenhum deste manifesto**, e a regra é a nova de 16/10: a contagem dele **cresce por construção** (um subteste por arquivo varrido), ele tem id PRÓPRIO (`vocabulario-tamanhos`, em `contagens-187-01.txt`, forma `>=443`), e embuti-lo foi o que fez a wave 5 reprovar DOIS ids de outras waves. **457 ≥ 443: o `>=` daquele id já cobre esta wave, e nada no 187-01 precisou mudar.** E **`>=` não é usado em nenhum id meu** — a contagem dos meus arquivos não cresce por construção.

---

## 13. A bijeção ids ↔ métodos, nos DOIS sentidos

`tests/gates/contagens-187-06.txt`: **9 ids**, todos MEDIDOS, nenhum escrito antes de ter sido medido.

| id | modo | testes | asserções |
|---|---|---|---|
| `busca-sem-acento` | php | 4 | 84 |
| `filtros-fechados` | php | 4 | 50 |
| `tres-escopos` | php | 4 | 47 |
| `custo-do-ml` | php | 2 | 13 |
| `filtros-na-url` | php | 2 | 48 |
| `territorio-filtros` | php | 1 | 64 |
| `bijecao-manifesto` | php | 1 | 9 |
| `filtros-render` | js | **29 / 19** (dois regimes) | — |
| `filtros-zindex` | js | 6 | — |

**Os 18 métodos `test_*_187_06` estão cobertos por 7 ids php**, e a bijeção é provada **por script, nos dois sentidos**:
- **sentido 1** — método SEM id **nunca roda** em gate nenhum (foi o sobrevivente da mutação do 186-03);
- **sentido 2** — id **órfão** é gate sobre ZERO casos;
- **isca** — o `preg_match` SABE recusar (`test_metodo_que_ninguem_escreveu_187_06` → 0).

⚠️ **E esta contagem NÃO depende do número de linhas do manifesto**, ao contrário do `bijecao-manifesto` da Fase 186 (que subia 1 a cada id novo). As asserções são de forma fixa (`>= 5` linhas, `>= 14` métodos, dois `deepEqual` coletando infratores e uma isca). Era fragilidade por construção e foi tirada **de propósito** — acrescentar id aqui não mexe neste número.

**Os dois regimes de `filtros-render` (`29,19`) são MEDIDOS**, não precaução: a camada de CSS pula quando o build é mais antigo que as fontes, e mutar qualquer `.jsx` a deixa mais nova.
```
pristino (CSS mais novo):   29 testes / 29 pass / 0 fail / 0 skipped
mutado   (fonte mais nova): 19 testes / 18 pass / 0 fail / 1 skipped
```

---

## 14. Território

Exatamente **10 arquivos** tocados pelos 8 commits do escopo `(187-06)`, todos declarados no plano:

```
app/Http/Controllers/MlbPublicadorAprovacaoController.php
app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php
resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx
resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx
resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
tests/Unit/Publicador/Aprovacao/FiltrosDaFilaServiceTest.php
tests/gates/contagens-187-06.txt
tests/js/publicador-fila-filtros-187.test.js
tests/js/publicador-fila-zindex-187.test.js
tests/js/publicador-vocabulario-novo.test.js
```

**`numstat` ZERO, conferido com `test -e` antes** (caminho fantasma "provaria" território intocado medindo NADA):

```
0 linhas  tests/js/publicador-header-zindex-ordem.test.js   ← INTOCÁVEL
0 linhas  tests/js/estrutura-meus-anuncios.test.js          ← D-11/D-22, por SEMÂNTICA
0 linhas  tests/js/publicador-fila-cabecalho-187.test.js    ← wave 4
0 linhas  tests/js/publicador-fila-proximo-187.test.js      ← wave 5
0 linhas  tests/js/publicador-fila-tokens-187.test.js       ← wave 1
0 linhas  tailwind.config.js
0 linhas  package.json / package-lock.json / composer.json / composer.lock   ← T-187-SC
```

E, por asserção no gate `territorio-filtros` (28 caminhos, cada um com `test -e`): os 10 serviços da Fase 186 em `Aprovacao/` fora de `Fila/`, os da wave 2 e 5 em `Fila/`, os 6 componentes já NO AR em `aprovacao/`, `publicacoes/`, `identidade/`, `Publicador/Mesa`, `AppLayout.jsx`, `Editor.jsx`, `app/Services/Creative`, `app/Services/Publicador/Criativos`, `ImagemAssetService.php`, `app/Mcp`, `database/migrations`.

⚠️ **Esta fase NÃO tem migration**, e **`artisan migrate` NÃO foi rodado**.
⚠️ **Nenhum deploy, nenhum push, nada no VPS, nenhuma chamada à API real do ML** (`Http::preventStrayRequests()` em todo teste que toca composição).
⚠️ **Esta tela APROVA (e aprovar ENFILEIRA); ela NÃO publica.** A escrita no Mercado Livre é da **Fase 193**. Nesta wave a página só **LÊ**: há gate de ausência de `->update(`, `->save(`, `->delete(`, `dispatch(` e `DB::statement` no código **sem comentários**.

---

## 15. Os commits

| hash | mensagem |
|---|---|
| `0c9ddc34` | `test(187-06)`: os gates dos filtros da fila — e a medição que recusou a busca em SQL (**RED**) |
| `1ee00889` | `feat(187-06)`: FiltrosDaFilaService — a busca sem acento, as listas fechadas e os TRÊS recortes (**GREEN**) |
| `2fd08ba5` | `test(187-06)`: os gates de render, de z-index e o manifesto do plano (**RED**) |
| `5e695403` | `feat(187-06)`: a linha de filtros, os dois popovers e o estado na URL (**GREEN**) |
| `42578cd1` | `fix(187-06)`: a campanha de mutação achou DUAS lacunas reais, e eram do controller |
| `fe910701` | `fix(187-06)`: a rodada 2 da mutação achou o que ainda sobrevivia — e quem discrimina é a BUSCA |
| `ebbb6f46` | `fix(187-06)`: os dois gates das waves 4 e 5 que a minha fiação quebrou — consertados do MEU lado |
| `c9637993` | `docs(187-06)`: o docblock nomeia de onde vem o recorte por conta — e a trava que mora lá |

**Gates TDD:** `test(...)` antes de `feat(...)` nas duas tarefas. ⚠️ **Declaração honesta:** o serviço foi escrito **antes** do arquivo de teste nesta sessão; os commits refletem a ordem RED→GREEN, mas o degrau RED não foi literal no tempo. O que é literal: o commit `0c9ddc34` **não contém** o serviço, então aquele estado da árvore de fato falha.

⚠️ **Falta de atribuição em um commit:** `fe910701` saiu **sem** a linha `Co-Authored-By: Claude Opus 5 (1M context)`. Foi esquecimento meu ao usar `-m` em vez de `-F`; `--amend` é proibido, então fica registrado aqui.

---

## 16. Nenhum stub

Varri os 5 arquivos de produção desta wave procurando `=[]`/`={}`/`=null`/`=""` que cheguem à tela, `TODO`/`FIXME`/"coming soon"/"não disponível", e componente sem fonte de dado. **Não há stub.**

Os defaults `null`/`[]`/`false` das props são **guardas de render** (REND-01/02/03), não dados vazios fingindo ser dados: slot sem nó **não desenha casca**, contagem ausente **não desenha número**, popover sem rótulo **não desenha nada**, e botão sem handler **não é oferecido**. Cada um tem gate.

O único ponto de extensão declarado é a `contagem.exibidas` nascendo `null` no `$payload` e sendo preenchida três linhas abaixo — e isso é mecânica de ordem de avaliação, não stub (a prop nunca chega `null` à tela com a faixa presente; há asserção nas props).

---

## 17. Nenhuma `Threat Flag` nova

Os três limites de confiança do `<threat_model>` do plano foram tratados e medidos:

| Threat ID | disposição | como |
|---|---|---|
| T-187-61 | mitigado | listas FECHADAS lidas de `EstruturaAnuncio::TIPOS` e `VocabularioDoPortao::ROTULOS`, `is_string` antes de `trim` nos **seis** parâmetros, valor forjado descartado em silêncio **sem zerar a lista**, e **nada interpolado em SQL** (não há `whereRaw` no serviço — a busca saiu do SQL) |
| T-187-62 | mitigado | o gate de custo de §3, **sem afrouxamento** pelo `187-10` |
| T-187-63 | mitigado | `z-20` com gate em arquivo próprio, lendo os 4 pontos travados dos arquivos deles |
| T-187-64 | mitigado | as contagens são sobre `paraAConta()`, que **LANÇA** sem âncora de conta |
| T-187-65 | mitigado | zero `localStorage`/`sessionStorage` nos dois componentes **e** na página, com isca; estado na URL |
| T-187-SC | mitigado | nada instalado; `numstat` ZERO nos 4 arquivos de dependência |

**Nenhuma superfície de segurança nova fora do registro.** A busca saiu do SQL, o que **reduziu** a superfície: `%` e `_` deixaram de ser curinga e não há mais expressão SQL construída em PHP.

---

## 18. ⚠️ O que o usuário precisa conferir, e por quê eu não pude

**Nenhum executor desta fase viu esta tela renderizada.** O banco local não tem empresa com `ml_token` — a rota dá **404** aqui. A conferência é do usuário, **em produção, na conta 459**.

O que vale olhar:
1. os **dois amarelos** do segmentado (0.16, sem borda) e do botão de popover (0.12 com borda 0.55) **lado a lado** — eles são diferentes de propósito;
2. o popover **não** ficar por cima do header nem do menu lateral (é o remapeamento de z);
3. o **"N de M"**: o M não muda quando se filtra, e bate com o card "Todas" da faixa;
4. os **8 motivos**, incluindo os que estão em **0**;
5. **compartilhar o link** com um filtro ativo e abrir noutra aba: tem de cair no mesmo recorte.

---

## Self-Check: PASSADO
