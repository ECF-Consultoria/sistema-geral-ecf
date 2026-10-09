---
phase: 175-publicador-etapa-3-produto-fases
plan: 03
subsystem: api
tags: [publicador, kits, fases, portal, estrutura-ofertas, heuristica, phpunit]

# Dependency graph
requires:
  - phase: 175-01
    provides: "colunas `produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado` e `kit_sugestao_recusada_em` em `pub_produtos`"
  - phase: 164 (Publicador)
    provides: "`ProgramasPublicadorService::produtosQuery()` — o escopo de conta (MlbEmpresa OU Company)"
  - phase: PORTAL-01 (Mapeamento Estrutural)
    provides: "`estrutura_ofertas` + `estrutura_oferta_componentes` — a composição real das ofertas"
provides:
  - "`App\\Services\\Publicador\\SugestaoDeKitService` — detecção, só leitura, de combos já cadastrados"
  - "Cinco funções puras reutilizáveis: `normalizar`, `porSku`, `porNome`, `ehMisto`, `escolher`"
  - "`sugerirPara(PubProduto)` em duas camadas: fato do Portal → heurística de SKU/nome"
  - "`candidatosDaConta(?MlbEmpresa, ?Company)` — sugestões da conta inteira em lote, pronta para a coluna Fases"
affects: [175-08, 175-09, publicador-lista-de-produtos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Fato antes de heurística: quando o Portal modela a composição, ela decide; o palpite de texto só vale na ausência de fato"
    - "Serviço de sugestão é só leitura — a gravação exige confirmação humana e vive em outro plano"
    - "Funções puras `public static` testáveis sem banco, separadas por divisor de seção da parte que consulta"

key-files:
  created:
    - app/Services/Publicador/SugestaoDeKitService.php
    - tests/Unit/Publicador/SugestaoDeKitServiceTest.php
  modified: []

key-decisions:
  - "A camada de FATO do Portal (`estrutura_oferta_componentes`) tem precedência sobre a heurística de nome/SKU da §6 — um componente ×N é prova, não palpite; 2+ componentes são kit misto por construção"
  - "Combo cujo componente não tem `PubProduto` na conta devolve null e NÃO cai na heurística: adivinhar base contra um fato parcial é pior que não sugerir"
  - "`porNome` recusa N < 2 — `quantidade_kit = 1` é o valor das BASES no unique `pubprod_base_qtd_uq` do 175-01, e kit de uma unidade é o próprio produto"
  - "`ehMisto` usa fronteira de palavra (`\\be\\b`), nunca `str_contains(' e ')`: 'Mesa de Jantar' não é kit misto, 'Pote e Tampa' é"
  - "Base que já tem kit CONTINUA candidato a base (uma família pode ganhar a Fase 3); quem é excluído é o produto ANALISADO que já é base de alguém"
  - "`conflito_heuristica` roda a heurística por diagnóstico quando o Portal já decidiu — vale o Portal, mas a tela e o log ficam sabendo da divergência"

patterns-established:
  - "Contexto em lote: `contexto(?MlbEmpresa, ?Company)` faz uma leitura por assunto (produtos + ofertas com componentes) e as duas camadas trabalham em memória — ≤2 consultas por conta, provado no teste"
  - "Guardas puras antes de qualquer consulta: `kit_sugestao_recusada_em` encerra o assunto com query log vazio"

requirements-completed: [FASE-09]

# Metrics
duration: 35min
completed: 2026-10-08
---

# Fase 175 Plano 03: `SugestaoDeKitService` Summary

**Detecção de combos já cadastrados em duas camadas — a composição real da oferta do Portal (`estrutura_oferta_componentes`) decide quando existe, e a heurística de prefixo de SKU/nome da §6 cobre o produto sem oferta — tudo só leitura, com 31 testes unitários e os dois literais da §9 provados nos dois caminhos.**

## Performance

- **Duração:** ~35 min
- **Iniciado:** 2026-10-09T01:00Z (22:00 BRT)
- **Concluído:** 2026-10-09T01:30Z (22:30 BRT)
- **Tasks:** 2 (ambas TDD)
- **Arquivos criados:** 2

## Accomplishments

- `SugestaoDeKitService` com as cinco funções puras da §6 (`normalizar`, `porSku`, `porNome`, `ehMisto`, `escolher`) — cada uma uma recusa explícita: substring livre não casa, o próprio SKU não casa, kit misto não casa, ambiguidade não casa.
- Camada de **fato do Portal**: oferta `combo`/`kit`/`combit` com **exatamente 1** componente de quantidade ≥ 2 devolve base e N **exatos**; **2+** componentes é kit misto **por construção**, sem olhar o nome; `simples` não é kit.
- Camada de **heurística** intacta para produto sem oferta, com `ehMisto()` na frente e quantidade vazia quando só o SKU casou.
- `candidatosDaConta()` em lote (≤2 consultas por conta, provado por query log) para a coluna Fases do 175-09.
- Prova de que **nada é gravado**: `pub_produtos` byte-a-byte igual antes e depois de `candidatosDaConta()`.
- T-175-09 fechado: todo candidato passa por `ProgramasPublicadorService::produtosQuery()` — base de outra conta nunca aparece (teste dedicado).

## Task Commits

1. **Task 1: A parte pura — casar um candidato com um base** (TDD)
   - `c2fbcdb5` test — 13 casos, todos vermelhos (classe não existia)
   - `f22b3336` feat — as cinco funções puras, 13 verdes
2. **Task 2: As duas camadas — fato do Portal antes da heurística** (TDD)
   - `bcfc392f` test — 18 casos novos de banco (`RefreshDatabase`), vermelhos
   - `6a596527` feat — `sugerirPara`, `candidatosDaConta`, contexto em lote; 31 verdes

Sem commit de refactor (não houve limpeza a fazer depois do verde).

## Files Created/Modified

- `app/Services/Publicador/SugestaoDeKitService.php` (**criado**, 383 linhas) — as duas camadas. Parte pura `public static` acima do divisor, parte que consulta abaixo. Escopo de conta sempre por `produtosQuery()`.
- `tests/Unit/Publicador/SugestaoDeKitServiceTest.php` (**criado**, 31 testes / 92 asserções) — 13 puros (sem banco) + 18 com `RefreshDatabase`.

**Nenhum arquivo existente foi modificado.** `app/Models/PubProduto.php` é propriedade do 175-02 (que rodou em paralelo) e não foi tocado: as colunas novas são lidas por atributo e a checagem "já é base de alguém" usa query builder (`where('produto_base_id', …)`), nunca a relação `kits()`.

## Decisions Made

Ver `key-decisions` no frontmatter. As três que mais mudam comportamento:

1. **Fato antes de palpite.** Para produto de origem Portal a composição da oferta é a fonte; a heurística de texto nem roda (a não ser por diagnóstico, para `conflito_heuristica`). Isso resolve o caso do combit misto sem depender de encontrar `"+"` no nome.
2. **Combo sem base na conta não cai na heurística.** A composição já afirmou "é combo"; procurar um base por nome contra um fato parcial produziria exatamente o palpite errado que a §6 proíbe.
3. **`porNome` recusa N < 2.** Não estava no `<behavior>`, mas `quantidade_kit = 1` é o valor das bases no unique `pubprod_base_qtd_uq` (175-01) — sugerir "Kit 1" seria sugerir algo que nem grava.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] `porNome` recusa quantidade menor que 2**
- **Found during:** Task 1
- **Issue:** O `<behavior>` não dizia o que fazer com "Kit 1 Cadeira". Devolver `1` produziria uma sugestão impossível de gravar (`pubprod_base_qtd_uq` reserva `quantidade_kit = 1` para as bases) e um vínculo semanticamente errado.
- **Fix:** `porNome` devolve null para N < 2, com teste dedicado (`test_por_nome_recusa_kit_de_uma_unidade`).
- **Files modified:** `app/Services/Publicador/SugestaoDeKitService.php`, `tests/Unit/Publicador/SugestaoDeKitServiceTest.php`
- **Verification:** teste verde; nenhum outro caso de aceite afetado.
- **Committed in:** `f22b3336`

### Divergências de leitura do plano (não são correções)

**A. "Candidato a base … que não seja base de nenhum kit" — implementado como guarda do produto ANALISADO, não como filtro dos bases.**

O `<behavior>` da Task 2 lista, na mesma frase, filtros de candidato a base e guardas do produto analisado. As duas leituras não cabem juntas:

- Se "não ser base de nenhum kit" filtrasse os **bases**, uma família que já tem Kit 2 nunca receberia a sugestão de Kit 4 — perda funcional pura.
- A must_have truth nº 3 cita `kit_sugestao_recusada_em` na mesma lista, e esse flag só faz sentido no produto analisado ("não é kit" dito pela pessoa) — excluir por ele um **base** seria absurdo.

Implementado, então: o **produto analisado** é descartado se tem `produto_base_id`, se tem `kit_sugestao_recusada_em` ou se já é base de alguém (três guardas, três testes); o **candidato a base** é descartado se é ele mesmo um kit (`produto_base_id` não nulo — sem cadeia), se é de outra conta ou se é o próprio produto. Um base que já tem kits segue candidato (`test_candidato_a_base_nunca_e_ele_mesmo_um_kit` prova que o kit intermediário é pulado e o base verdadeiro é escolhido).

**B. Oferta com fase `combo`/`kit`/`combit` e ZERO componentes devolve null.** O plano enumerou 1 e "2 ou mais"; composição vazia não diz nada, então não sugere (e também não cai na heurística, pela mesma razão do item 2 das decisões).

**C. Oferta apagada no Portal com `oferta_id` pendurado devolve null.** Não deve acontecer (D27 anula o `oferta_id`), mas se acontecer o serviço se cala em vez de adivinhar.

---

**Total deviations:** 1 auto-fix (Rule 2) + 3 divergências de leitura documentadas.
**Impact on plan:** nenhuma regressão e nenhum scope creep. Nada que existia mudou de comportamento: o serviço é novo, não tem rota, não tem UI e não é chamado por ninguém ainda.

## Issues Encountered

**Suíte do Publicador em 916 verdes + 12 vermelhos — os 12 NÃO são deste plano.** Baseline antes de começar: **879 verdes** (medido com a suíte inteira `tests/Unit/Publicador tests/Feature/Publicador`). Depois: **928 testes, 916 verdes, 12 vermelhos**. A conta fecha:

- +31 meus (todos verdes);
- +18 do 175-02, que commitou em paralelo na mesma árvore — 6 verdes de `PubProduto` e os **12 vermelhos de `tests/Unit/Publicador/CriarFaseServiceTest.php`**, commitados por ele em estado RED (`7eb9ab50 test(175-02): prova do CriarFaseService antes de ele existir`) e já resolvidos depois no `b5585818 feat(175-02): CriarFaseService …`, que entrou **depois** da minha medição.

Nenhuma das 12 falhas toca `SugestaoDeKitService`. A suíte só do meu arquivo: **31 passed (92 assertions)**.

## Verificação dos dois critérios da §9, nos DOIS caminhos

| Critério | Camada de fato (Portal) | Camada de heurística (sem oferta) |
|----------|-------------------------|-----------------------------------|
| `CAD-CB2` recebe "Kit de CAD" | `test_aceite_da_spec_pelo_fato_do_portal`: oferta `CAD-CB2` é `combo` com 1 componente (`CAD`) ×2 → `base_sku = 'CAD'`, `quantidade = 2`, `origem = 'portal'` | `test_aceite_da_spec_pela_heuristica`: produto `CAD-CB2` sem oferta casa por prefixo de SKU com `CAD` → `base_sku = 'CAD'`, `quantidade = null` (o SKU não diz o N, campo fica vazio como a §6 manda), `origem = 'sku'` |
| `Combit 4 Cadeira Escritório + 1 MESA REDONDA` **não** recebe nada | mesma função de teste: oferta `combit` com **2** componentes (Cadeira ×4, Mesa ×1) → `null`, sem olhar o nome | mesma função de teste: `ehMisto()` pega o `+` e devolve `null` antes de qualquer casamento |

Reforços: `test_oferta_com_dois_componentes_e_kit_misto_por_construcao_e_nao_sugere` usa de propósito um combit **chamado** "Kit 4 Cadeira" (nome que casaria na heurística) para provar que a composição manda; `test_conflito_heuristica_e_marcado_quando_o_nome_aponta_outro_base` prova a precedência com a flag no retorno.

## Next Phase Readiness

- **175-08/175-09** podem consumir `candidatosDaConta($empresa, $company)` direto: devolve `array<produto_id, sugestão>` e a sugestão já traz `base_id`, `base_sku`, `base_nome`, `quantidade`, `origem` e `conflito_heuristica` — o suficiente para "Kit de CAD? Vincular" e para o diálogo.
- A gravação (`produto_base_id`, `quantidade_kit`, `fase`, `kit_sugestao_recusada_em`) continua sendo do 175-08/175-09; este serviço não escreve e não deve passar a escrever.
- `conflito_heuristica = true` merece um aviso na tela (e/ou um `Log::info` com tag `[Publicador]`) — a decisão de UI é do 175-09.
- Sem rota, sem UI, sem deploy. Nada a conferir em produção neste passo.

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Completed: 2026-10-08*

## Self-Check: PASSED

- `app/Services/Publicador/SugestaoDeKitService.php` — existe (383 linhas, mínimo do plano: 140)
- `tests/Unit/Publicador/SugestaoDeKitServiceTest.php` — existe (471 linhas, 31 testes)
- `.planning/phases/175-publicador-etapa-3-produto-fases/175-03-SUMMARY.md` — existe
- Commits `c2fbcdb5`, `f22b3336`, `bcfc392f`, `6a596527` — todos no histórico
- `key_links`: 7 ocorrências de `componentes` no serviço (composição real da oferta do Portal)
