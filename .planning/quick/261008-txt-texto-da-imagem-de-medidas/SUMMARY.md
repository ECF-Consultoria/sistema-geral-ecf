---
quick_id: 261008-txt
slug: texto-da-imagem-de-medidas
type: quick
date: 2026-10-08
---

# Resumo — O prompt mandava desenhar texto e proibia texto na mesma respiração

Os três defeitos relatados em `PLAN.md` (achados no prompt gravado do
criativo 40, kit 7, rascunho "mesa escritório", conta 459) foram corrigidos.
A causa raiz do relato original do usuário ("a imagem não veio com a fonte
que eu descrevi") era mais fundamental do que fonte: **nenhuma fonte podia
aparecer, porque o bloco TEXTO proibia todo texto** — a identidade visual
(Fase 170) funcionou corretamente e não foi tocada.

## Defeito 1 — bloco TEXTO emitido vazio

`CreativePromptBuilder` passou a distinguir **"o tipo aceita texto"**
(`CreativeSlotCatalog::aceitaTexto()`, por catálogo) de **"esta instância do
slot tem texto confirmado"** (`temTextoConfirmado()`, novo método — olha o
`headline`/`badges` realmente presentes no `slot_plano`). `linhasTexto()`
ganhou um terceiro ramo: quando o tipo aceita texto mas nada foi confirmado,
o prompt não manda mais "escreva EXATAMENTE os textos abaixo" seguido de
nada — ele explica a ausência e instrui o modelo a desenhar a CENA **sem**
os elementos de texto que ela descreve (cabeçalho, marcadores, rótulos de
medida), em vez de reescrever a CENA em si (que continua vindo sempre do
catálogo, por decisão arquitetural anterior — `LAYOUT_MEDIDAS`/
`LAYOUT_TOPICOS`).

A claim fixa "Não escreva texto na imagem." também volta a aparecer em
CLAIMS PROIBIDAS nesse caso (antes, ela era removida sempre que o TIPO
aceitava texto, mesmo sem nenhum texto confirmado).

## Defeito 2 — a trava rejeitava o rótulo junto do valor

**Decisão do usuário, tomada ciente do risco:** `CreativePlanner::validarTexto()`
deixou de exigir igualdade byte a byte entre o texto do modelo e o valor NU
de um fato. Agora (`rotularSeConfirmado()`) compara só a parte depois do
último ":" do texto proposto (tolera o modelo já ter colado um rótulo,
certo ou errado) com o valor exato do cadastro — e quando casa, o **SISTEMA
remonta** o texto aceito como "{rótulo}: {valor}", usando sempre o rótulo
oficial do `ProductTruth`, **nunca** a frase do modelo. O número continua
vindo exclusivamente do cadastro (match por igualdade exata); a flexibilização
foi só em "ACEITAR o texto do modelo como veio" → "DECIDIR se ele se refere a
um valor confirmado". A alternativa de aceitar a frase do modelo quando ela
contém o valor foi explicitamente recusada pelo usuário e não foi
implementada.

Contagens ("portas: 2" etc.) não mudaram — continuam exigindo igualdade
exata da string inteira "peça: quantidade", porque não é esse o defeito
desta quick e relaxar ali reabriria risco de TRUTH-02/03.

Os rótulos crus em inglês (WIDTH, HEIGHT, LENGTH, DEPTH, MAIN_MATERIAL)
foram traduzidos em `ProductTruthBuilder::ROTULOS_CONHECIDOS` — sem essa
tradução, o texto remontado pelo sistema sairia com rótulo em inglês dentro
da imagem (ex.: "Width: 120 cm"), que é justamente o problema que o usuário
descreveu de outra forma.

## Defeito 3 — proibições duplicadas

Causa raiz: `CreativePlanner::proibicoesDoSlot()` copiava a lista inteira de
`$truth->claimsProibidas` (8 itens) dentro de `slot_plano['proibicoes']`, e
`CreativePromptBuilder::claimsDoSlot()` já mesclava o Truth de novo — os
mesmos itens apareciam duas vezes. `proibicoesDoSlot()` passou a gravar só a
proibição específica deste slot (hoje, só "não escreva texto" quando não há
texto confirmado); `claimsDoSlot()` ganhou `array_unique()` como defesa em
profundidade.

## Sobre a fonte (honestidade mantida)

Nenhuma mudança nesta quick promete fidelidade de fonte — modelo de imagem
não reproduz fonte por nome. Isso continua fora de escopo (ver PLAN.md).

## Evidência — bloco TEXTO final para o caso do criativo 40 (120/75/50 cm)

Reproduzido ponta a ponta (Planner + PromptBuilder) em
`tests/Unit/Quick261008Txt/CriativoQuarentaReproducaoTest.php`.

Com o modelo propondo as três medidas (badges: ['120 cm', '75 cm', '50 cm']):

```
TEXTO: escreva EXATAMENTE os textos abaixo, e não acrescente nenhum outro texto.
- Badge: Largura: 120 cm
- Badge: Altura: 75 cm
- Badge: Profundidade: 50 cm
```

Sem proposta nenhuma do modelo para este slot (caso exato do que foi
gravado em produção — badges: [], headline: null):

```
TEXTO: NENHUM valor foi confirmado no cadastro para este slot — não escreva
nenhuma palavra, número ou rótulo na imagem. Ignore qualquer cabeçalho, marcador
ou rótulo de medida descrito na CENA acima: desenhe só o leiaute visual dela (o
produto, os elementos gráficos), sem nenhum texto, do mesmo jeito que um slot que
não aceita texto.
```

Em ambos os casos, a seção CLAIMS PROIBIDAS final tem cada claim uma única
vez (conferido por `substr_count()` nos testes), e nenhum id cru em inglês
(WIDTH/HEIGHT/DEPTH) aparece no prompt.

## Testes

- (a) Sem fato nenhum, o prompt não se contradiz: `CreativePromptBuilderTextoHonestoTest::test_slot_dimensions_sem_texto_confirmado_nao_manda_escrever_lista_vazia` e `CriativoQuarentaReproducaoTest::test_sem_proposta_de_texto_do_modelo_o_prompt_final_nao_se_contradiz`.
- (b) O valor exibido vem só do cadastro: `CreativePlannerTest::test_badge_com_rotulo_proprio_do_modelo_casa_pelo_valor_e_sai_com_o_rotulo_do_truth` (rótulo errado do modelo é substituído pelo do Truth) e `test_badge_com_valor_que_nao_existe_no_cadastro_e_descartada_mesmo_citando_rotulo_real` (número inventado é descartado, mesmo com rótulo real).
- (c) Medida de embalagem continua fora: `CriativoQuarentaReproducaoTest::test_medida_de_embalagem_no_cadastro_nao_torna_dimensions_elegivel_nem_aparece_rotulada` (regressão explícita sobre a correção da quick 261007-ifa).
- Duplicação de claims: `CreativePromptBuilderTextoHonestoTest::test_claims_proibidas_nunca_aparecem_duplicadas_no_prompt` e `test_claims_proibidas_nunca_duplicam_mesmo_quando_slot_plano_repete_proibicao_do_truth`.
- Tradução de rótulos: `ProductTruthBuilderTest::test_ids_de_dimensao_e_material_principal_traduzem_para_pt_br`.

## Deviations from Plan

Nenhum desvio das Regras 1–3 que precise de aprovação — todas as mudanças
estão dentro do escopo descrito no PLAN.md. Um teste pré-existente
(`CreativePlannerTest::test_badge_que_nao_casa_e_descartada_e_a_que_casa_e_mantida_literal`)
foi renomeado e sua asserção atualizada para o novo comportamento intencional
(badge sai rotulada, não mais literal) — é a mesma correção 2 descrita no
PLAN.md, aplicada de forma uniforme a todos os tipos que aceitam texto (não
só `dimensions`), porque `LAYOUT_TOPICOS` (usado por
specifications/benefits/feature_highlight/how_to_use/package_content)
também descreve "rótulo curto em negrito" — o mesmo motivo que
`LAYOUT_MEDIDAS` tem para exigir rótulo.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova — mudança é só na montagem de texto já existente
dentro do prompt, sem novo endpoint, caminho de auth ou acesso a arquivo.

## Resultado dos testes

- `tests/Unit/Quick261008Txt/*` (novo): 11 testes, 0 falhas.
- `tests/Unit/Phase160/ProductTruthBuilderTest.php`: 11 testes, 0 falhas (1 novo).
- `tests/Unit/Phase161/CreativePlannerTest.php`: 10 testes, 0 falhas (2 novos, 1 renomeado).
- Suíte combinada Creative Engine (Phase160/161/162/165/168/169/170 + Quicks
  261003-l8o/261007-amb/261007-ifa/261007-rmv + as novas): 573 passed, 1
  incomplete (o incompleto é pré-existente, fora do Creative Engine, não
  investigado por estar fora de escopo), 0 failed.
- `--filter=Publicador` (classes cujo nome contém "Publicador", mais amplo
  que só o diretório): 899 passed, 3 failed, 1 incomplete. As 3 falhas
  (`MeuPainelControllerTest::meu_painel_passa_props_novas`,
  `MeuPainelControllerTest::sem_publicacoes`,
  `PublicarEmpresaNaoAtribuidaTest::publicador_dono_nao_recebe_403`) são
  pré-existentes e não relacionadas a esta quick — reproduzidas em
  isolamento, sem nenhum arquivo tocado por este trabalho no caminho de
  execução; o erro real é "[MLB Coleta] Falha ao obter app token: HTTP 400",
  uma tentativa de chamada HTTP real a credenciais/sandbox do Mercado Livre
  que falha neste ambiente local — não investigadas por estarem fora de
  escopo (nenhum arquivo de app/Services/Creative ou app/Services/Publicador
  relacionado a elas foi tocado).
- Nenhum teste chama API real da Gemini.

## Self-Check: PASSED

- app/Services/Creative/ProductTruthBuilder.php — FOUND
- app/Services/Creative/CreativePlanner.php — FOUND
- app/Services/Creative/CreativePromptBuilder.php — FOUND
- app/Services/Creative/Dto/CreativeSlotPlan.php — FOUND
- tests/Unit/Quick261008Txt/CreativePromptBuilderTextoHonestoTest.php — FOUND
- tests/Unit/Quick261008Txt/CriativoQuarentaReproducaoTest.php — FOUND
- Commit e56c367a (rótulos pt-BR) — FOUND em git log
- Commit fd4f0d29 (Planner: rotulagem + fim da duplicata) — FOUND em git log
- Commit ce907800 (PromptBuilder: TEXTO honesto + dedupe) — FOUND em git log
