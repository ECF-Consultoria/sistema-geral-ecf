---
quick_id: 261008-bdg
slug: sistema-monta-o-texto-do-cadastro
type: quick
date: 2026-10-08
---

# Resumo — O SISTEMA monta a badge do Product Truth quando o modelo não propõe texto

A quick `261008-txt` (ontem) corrigiu o SINTOMA — o prompt não contradiz
mais a si mesmo quando nenhum texto foi confirmado. Esta quick corrige a
CAUSA — o LLM do planejamento nunca estava propondo badge nenhuma para o
slot `dimensions` nos criativos 34 e 40 (produção, rascunho "mesa
escritório", conta 459: `badges: []`, `fatos_usados: []` nos dois), então
`rotularSeConfirmado()` nunca tinha o que rotular. O texto que o usuário
viu e aprovou no criativo 34 era **inventado pelo modelo** (que ignorou a
proibição contraditória de ontem) — nunca vinha do cadastro.

A partir de agora, quando um slot aceita texto por tipo e o modelo não
propõe headline/badge aproveitável (ou propõe algo que `validarTexto()`
descarta), o **SISTEMA** monta a badge direto do `ProductTruth`, com o
rótulo oficial em pt-BR e o valor exato do cadastro — nunca espera o
modelo.

## Causa raiz e correção

`CreativeSlotCatalog::fatosParaTexto(string $tipo, ProductTruth $truth)`
(novo método público) decide, por tipo de slot, **qual** fato do Truth
sustenta a badge:

- `dimensions` → as medidas **do produto** (`medidasDoProduto()`) — nunca
  de embalagem (`PADRAO_ID_EMBALAGEM`, a correção da quick `261007-ifa`
  não foi reaberta), no máximo 3, na ordem largura/altura/comprimento/
  profundidade do print de referência do usuário.
- `package_content`/`how_to_use` → o **mesmo** fato que tornou o tipo
  elegível em `satisfaz()` (a contagem de kit ou o atributo de conteúdo,
  o atributo de instalação/montagem) — não há ambiguidade de "qual fato"
  aqui, porque é literalmente o fato que decidiu a elegibilidade.
- `specifications`/`benefits`/`feature_highlight` → até 2 fatos
  genéricos, priorizando `MATERIAL`/`MAIN_MATERIAL`/`COLOR` (os mais
  informativos para o domínio de mobiliário deste projeto), **excluindo**
  `MODEL` e `BRAND` e qualquer id já coberto por um slot dedicado
  (medida/embalagem/kit/instalação) — para não repetir o mesmo fato em
  três slots diferentes.

`CreativePlanner::badgesDoSistema(string $tipo, ProductTruth $truth)`
(novo método privado) chama `fatosParaTexto()` e formata cada fato como
`"{rótulo}: {valor}"` — o MESMO formato que `rotularSeConfirmado()` já
produzia para a badge aceita do modelo. É chamado nos DOIS caminhos que
produzem `slot_plano` sem proposta aproveitável do LLM (os dois são
possíveis em produção, dependendo de quando a lista do catálogo entra no
JSON pedido ao modelo):

- `montarSlotAceito()` — o LLM propôs o TIPO do slot (ex.: `dimensions`),
  mas `validarTexto()` não confirmou nenhum headline/badge (seja porque o
  modelo não propôs nada, seja porque propôs um valor que não casa com o
  cadastro — ex.: `"Largura: 999 cm"` quando o cadastro diz 120 cm).
- `montarSlotPadrao()` — o LLM nem propôs o tipo; o `CreativePlanner`
  completa pela prioridade do catálogo. Este é exatamente o caminho que
  nenhum teste cobria antes desta quick, e é plausível que seja o que
  aconteceu em produção (o LLM nem citou "dimensions" no JSON).

## Decisão de critério — quantas/quais badges

Registrada em `CreativeSlotCatalog::fatosParaTexto()` (docblock) e nos
testes:

- **Layout de MEDIDAS** (`LAYOUT_MEDIDAS`, inspirado no print do usuário:
  "a lista das medidas com marcadores quadrados... linhas de cota finas")
  pede **três cotas** → `MAX_BADGES_MEDIDAS = 3`.
- **Layout de TÓPICOS** (`LAYOUT_TOPICOS`: "Um ou dois recortes
  circulares com close-up... rótulo curto em negrito") pede **um ou dois
  recortes** → `MAX_BADGES_TOPICOS = 2`.
- `MODEL` nunca vira badge, mesmo sendo o único fato disponível — o
  atributo carrega lista de palavras-chave de SEO ("mesa escritorio
  gaveta, escrivaninha com gavetas, escrivaninha, mesa de estudos..."),
  não um fato único. "Parece fato e não é."
- `BRAND` nunca vira badge genérica — já aparece em FATOS PERMITIDOS como
  "Marca" (`CreativePromptBuilder::linhasFatosPermitidos()`); duplicá-lo
  como badge não acrescenta informação.
- Prioridade `MATERIAL`/`MAIN_MATERIAL`/`COLOR` para os tipos genéricos:
  são os fatos mais informativos de um produto físico de mobiliário
  (domínio deste projeto), mais úteis ao comprador do que, por exemplo,
  `COUNTRY_OF_ORIGIN` ou um id humanizado sem tradução conhecida.

## TRUTH-02/03 — por que isto não reabre o risco dos "quatro pés"

O número **nunca** é escolhido, completado ou arredondado pelo sistema —
é sempre o valor literal já presente em `$truth->atributosIds`/
`$truth->contagens` (que, por sua vez, só chegam ali por
`ProductTruthBuilder`, nunca por mineração de título/descrição — TRUTH-01/
02 inalteradas). A única coisa nova que o sistema decide é **qual** fato
mostrar quando o modelo não decide nada — nunca **qual número** esse fato
tem. Quando o Truth não sustenta nada para o tipo, `fatosParaTexto()`
devolve `[]` e o ramo honesto da quick `261008-txt` continua valendo
(desenha o leiaute sem texto) — não foi desfeito.

## Por que `ProductTruthBuilder::rotulo()` virou `public static`

`CreativeSlotCatalog` é instanciado sem argumento (`new
CreativeSlotCatalog()`) em cerca de 30 testes das Fases 161/162/168/170 e
quicks anteriores. Injetar `ProductTruthBuilder` no construtor para
reaproveitar o dicionário `ROTULOS_CONHECIDOS` quebraria todos esses
call-sites. Tornar `rotulo()` `public static` (mesma classe, mesmo
comportamento, só visibilidade e modificador mudaram) permite ao catálogo
chamar `ProductTruthBuilder::rotulo($id)` sem nenhuma dependência nova —
nenhum call-site existente foi tocado.

## Evidência — bloco TEXTO final para o criativo 40 (medidas 120/75/50 cm)

Reproduzido ponta a ponta (Planner + PromptBuilder, mesmo encadeamento de
`GerarCriativoIaJob`) em
`tests/Unit/Quick261008Txt/CriativoQuarentaReproducaoTest.php::test_sem_proposta_de_texto_do_modelo_o_sistema_monta_as_badges_do_truth`
— o LLM propõe o tipo `dimensions` SEM nenhum badge/headline (forma exata
gravada em produção):

```
badges: ["Largura: 120 cm","Altura: 75 cm","Profundidade: 50 cm"]

TEXTO: escreva EXATAMENTE os textos abaixo, e não acrescente nenhum outro texto.
- Badge: Largura: 120 cm
- Badge: Altura: 75 cm
- Badge: Profundidade: 50 cm
```

A claim fixa "Não escreva texto na imagem." sai de CLAIMS PROIBIDAS neste
caso (Decisão 7 + quick `261008-txt`) — o prompt nunca manda escrever e
proíbe escrever ao mesmo tempo.

## Testes

Confirmações pedidas, com teste nomeado:

- **(a) O valor vem só do cadastro:**
  `CreativeSlotCatalogFatosParaTextoTest::test_dimensions_usa_as_medidas_do_produto_rotuladas_em_pt_br`
  e `CreativePlannerBadgesDoSistemaTest::test_montar_slot_aceito_quando_badge_do_modelo_e_descartada_tambem_cai_no_sistema`
  (número inventado pelo modelo, 999 cm, nunca sobrevive; vira 120 cm, o
  real).
- **(b) `MODEL`/SEO nunca vira badge:**
  `CreativeSlotCatalogFatosParaTextoTest::test_generico_nunca_usa_model_mesmo_sendo_o_unico_fato_disponivel`
  e `CreativePlannerBadgesDoSistemaTest::test_montar_slot_aceito_sem_proposta_de_texto_monta_badges_do_sistema`
  (lista de SEO completa no `MODEL`, confirmado ausente das badges).
- **(c) Embalagem continua fora:**
  `CreativeSlotCatalogFatosParaTextoTest::test_dimensions_nunca_usa_medida_de_embalagem_mesmo_presente_no_cadastro`
  (regressão explícita sobre `261007-ifa`).
- **(d) Sem fato nenhum, o ramo honesto da `261008-txt` continua valendo:**
  `CreativePlannerBadgesDoSistemaTest::test_sem_fato_nenhum_o_slot_continua_sem_texto_e_o_prompt_nao_se_contradiz`
  (caso sutil: `feature_highlight` fica "elegível" por contagem genérica,
  mas o único fato que a sustenta já é de `dimensions` — badge vazia,
  prompt honesto) e
  `::test_truth_sem_nenhum_atributo_nunca_produz_badge_em_tipo_nenhum`.
- Caminho `montarSlotPadrao()` (LLM nem propôs o tipo — não tinha
  cobertura antes desta quick):
  `CreativePlannerBadgesDoSistemaTest::test_montar_slot_padrao_preenche_badges_do_sistema_quando_llm_nao_propoe_o_tipo`.
- Critério de quantas/quais badges (cap, prioridade, exclusões):
  `CreativeSlotCatalogFatosParaTextoTest::test_dimensions_limita_a_tres_medidas_mesmo_com_quatro_no_cadastro`,
  `::test_generico_limita_a_duas_badges_mesmo_com_muitos_atributos_elegiveis`,
  `::test_generico_prioriza_material_e_cor_sobre_outros_atributos`,
  `::test_generico_nao_repete_fato_ja_coberto_por_slot_dedicado`,
  `::test_generico_nunca_usa_brand_mesmo_sendo_o_unico_fato_disponivel`.
- `package_content`/`how_to_use` usam o fato que os tornou elegíveis:
  `::test_package_content_usa_a_contagem_de_kit_quando_existe`,
  `::test_package_content_usa_atributo_de_conteudo_quando_nao_ha_contagem_de_kit`,
  `::test_how_to_use_usa_o_atributo_de_instalacao`.

Dois testes pré-existentes que afirmavam o comportamento ANTIGO (badge
vazia depois do descarte) foram atualizados para o comportamento correto
— ver "Deviations" abaixo.

## Deviations from Plan

Nenhum desvio das Regras 1–3 que precise de aprovação — a mudança está
dentro do escopo descrito no PLAN.md. Dois testes pré-existentes
precisaram ser atualizados porque afirmavam, explicitamente, o
comportamento ANTIGO que esta quick existe para corrigir:

**1. [Ajuste de teste pré-existente]
`tests/Unit/Phase161/CreativePlannerTest.php::test_badge_com_valor_que_nao_existe_no_cadastro_e_descartada_mesmo_citando_rotulo_real`**
— afirmava `assertSame([], $slot->badges)` depois de descartar uma badge
com número inventado (999 cm). Renomeado para
`test_badge_com_valor_que_nao_existe_no_cadastro_e_descartada_e_substituida_pelo_valor_real_do_sistema`
e a asserção passou a exigir `['Largura: 120 cm']` — o descarte do número
inventado continua acontecendo (prova TRUTH-02/03 inalterada), só que
agora o vazio deixado pelo descarte é preenchido pelo sistema com o valor
VERDADEIRO, nunca mais fica sem nada.

**2. [Ajuste de teste pré-existente]
`tests/Unit/Quick261008Txt/CriativoQuarentaReproducaoTest.php::test_sem_proposta_de_texto_do_modelo_o_prompt_final_nao_se_contradiz`**
— era a prova de que o prompt "não se contradiz" aceitando terminar sem
texto nenhum (o estado da quick de ontem). Renomeado para
`test_sem_proposta_de_texto_do_modelo_o_sistema_monta_as_badges_do_truth`
e passou a exigir que as três badges apareçam — é exatamente o caso real
de produção que motivou esta quick.

Nenhuma outra mudança fora do escopo.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova — mudança é só na montagem de texto já existente
dentro do prompt, a partir do mesmo `ProductTruth` já lido em memória;
nenhum endpoint, caminho de auth, acesso a arquivo ou tabela novo.

## Resultado dos testes

- `tests/Unit/Quick261008Bdg/*` (novo): 19 testes, 0 falhas, 49 asserções.
- `tests/Unit/Phase161/CreativePlannerTest.php`: 0 falhas (1 renomeado
  com nova asserção).
- `tests/Unit/Quick261008Txt/*`: 0 falhas (1 renomeado com nova asserção).
- Suíte combinada Creative Engine (Unit+Feature de Phase160/161/162/165/
  168/169/170 + Quick261003L8o/Quick261007Amb/Quick261007Ifa/
  Quick261007Rmv/Quick261007/Quick261008Txt/Quick261008Bdg +
  `GeminiImageProviderTest`): **522 passed, 1 incomplete, 0 failed**
  (2136 asserções). O incompleto é pré-existente, fora do Creative
  Engine, não investigado por estar fora de escopo.
- `--filter=Publicador`: **899 passed, 3 failed, 1 incomplete** — as 3
  falhas são as mesmas já documentadas e reproduzidas em isolamento na
  quick `261008-txt` (`MeuPainelControllerTest` ×2,
  `PublicarEmpresaNaoAtribuidaTest::publicador_dono_nao_recebe_403`),
  todas por `[MLB Coleta] Falha ao obter app token: HTTP 400` (chamada
  real à API do Mercado Livre neste ambiente local) — nenhum arquivo
  tocado por esta quick está no caminho de execução delas.
- `tests/Feature/Publicador` + `tests/Unit/Publicador` (baseline da
  pasta): **819 passed, 0 failed** — igual à baseline antes desta quick.
- Nenhum teste chama API real da Gemini.

## Self-Check: PASSED

- app/Services/Creative/ProductTruthBuilder.php — FOUND
- app/Services/Creative/CreativeSlotCatalog.php — FOUND
- app/Services/Creative/CreativePlanner.php — FOUND
- tests/Unit/Quick261008Bdg/CreativeSlotCatalogFatosParaTextoTest.php — FOUND
- tests/Unit/Quick261008Bdg/CreativePlannerBadgesDoSistemaTest.php — FOUND
- Commit a75440db (feat: sistema monta badge do Product Truth) — FOUND em git log
