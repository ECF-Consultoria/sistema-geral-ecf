---
quick_id: 261007-amb
slug: ambientada-na-capa-e-imagem-de-medidas
type: quick
date: 2026-10-07
status: concluido
---

# Ambientada na capa (móveis) e layout fixo da segunda imagem — Summary

**Uma linha:** categoria de móvel (detectada pelo `path_from_root`, nunca por lista manual) troca o 1º slot do kit de `hero` para `lifestyle` (ambientação); os tipos que aceitam texto (`dimensions` e os demais COM_FATO) passam a usar um layout fixo de medidas/tópicos, inspirado em dois prints reais mandados pelo usuário, forçado pelo servidor e não mais livre ao critério do modelo.

## O que foi feito

### Tarefa 1 — Categoria de móvel decide o primeiro slot

- `App\Services\Creative\CreativeCategoriaMobiliarioService` (novo): `ehMoveis(?string $categoriaId): bool` lê `GET /categories/{id}` via `MlCatalogoMetaService::categoria()` (mesmo cache de 7 dias já aquecido pelo wizard/incubadora — nenhuma chamada nova por geração) e examina o `path_from_root` **descartando a raiz** (índice 0). A raiz "Casa, Móveis e Decoração" contém a palavra "Móveis" mas cobre cozinha e decoração — por isso a busca só olha os nós ABAIXO da raiz, procurando a palavra "moveis" (sem acento, limite de palavra). Degrada para `false` em qualquer falha (categoria ausente, API fora do ar, app token indisponível) — nunca lança, nunca chama rede nova em teste (`Http::preventStrayRequests()` prova isso).
- `CreativeSlotCatalog::elegiveis(ProductTruth $truth, bool $categoriaMoveis = false)`: novo parâmetro opcional (default `false`, zero quebra para quem já chamava sem ele). Troca APENAS quem ocupa a posição 1 (`lifestyle` em vez de `hero`) — os COM_FATO elegíveis continuam entrando na mesma posição de sempre, e o `hero` (quando não é o primeiro) volta a disputar como qualquer outro SEM_FATO.
- `CreativePlanner::planejar(..., bool $categoriaMoveis = false)`: repassa a decisão para o catálogo e generaliza `garantirHeroPrimeiro()` → `garantirPrimeiroSlot()` (força `hero` OU `lifestyle` na posição 1, mesmo que o LLM proponha outra coisa primeiro). O Planner **nunca consulta a API** — quem decide `$categoriaMoveis` é o chamador (`PlanejarKitCriativosJob`), mantendo os testes de unidade do Planner livres de HTTP.
- `PlanejarKitCriativosJob::handle()`: resolve `CreativeCategoriaMobiliarioService` via injeção de método (Laravel resolve sozinho) e chama `ehMoveis($contexto->categoriaId)` logo antes de `planejar()`.
- `CreativePromptBuilder`: nenhuma mudança de código — conferido que o bloco AMBIENTE (ambiente brasileiro, D5/AMB-01..04) já decide por **tipo** do slot (`lifestyle`/`lifestyle_uso`/`composicao`), nunca por posição. Com `lifestyle` ocupando a posição 1 para móveis, o bloco AMBIENTE se aplica automaticamente e continua coerente com AMB-03 (a exclusão é de `hero`/`white_background`, que são os tipos regidos pela moderação de "produto isolado, sem cenário" — `lifestyle` em si já é, por definição, uma cena ambientada, esteja na posição 1 ou em qualquer outra). Só o docblock foi atualizado para deixar essa conclusão explícita.

### Tarefa 2 — Layout fixo da segunda imagem (medidas/tópicos)

- `CreativeSlotCatalog`: duas novas constantes de layout — `LAYOUT_MEDIDAS` (só para `dimensions`) e `LAYOUT_TOPICOS` (para `package_content`, `specifications`, `benefits`, `feature_highlight`, `how_to_use`). Os dois textos (ver abaixo) descrevem só FORMA/composição visual — nenhum número, nenhuma medida, nenhum valor concreto.
- `CreativePlanner::montarSlotAceito()`: para qualquer tipo com `aceita_texto=true`, a `cena` final é **sempre** a do catálogo, mesmo que o LLM proponha outra — o layout é pedido explícito do usuário, não espaço de criatividade do modelo. Tipos sem texto (visuais) continuam com a cena do LLM quando proposta, com fallback ao padrão — nenhuma mudança de comportamento aí (provado por teste de não-regressão).
- O texto exibido continua passando por `CreativePlanner::validarTexto()` — o layout nunca autoriza fato novo (TRUTH-02/03); e medida de embalagem (`SELLER_PACKAGE_*`/`PACKAGE_*`) continua fora de `dimensions` (achado da quick `261007-ifa`, intocado).

## Texto final dos dois layouts (para o usuário calibrar)

**Layout de MEDIDAS** (`dimensions`):

> Fundo claro e liso. Cabeçalho curto em caixa alta com ícone simples de régua (ex.: "TAMANHO DO PRODUTO"). Ao lado do cabeçalho, a lista das medidas com marcadores quadrados. Produto centralizado, em ângulo que mostre bem suas dimensões, com linhas de cota finas sobre ele — seta nas duas pontas de cada linha, valor da medida rotulado ao lado. Miniatura do produto em outro ângulo no canto superior. Tipografia sans-serif, texto escuro sobre fundo claro.

**Layout de TÓPICOS** (`package_content`, `specifications`, `benefits`, `feature_highlight`, `how_to_use`):

> Fundo branco. Um ou dois recortes circulares com close-up de uma parte do produto. De cada círculo, uma linha fina horizontal até um rótulo curto em negrito, com uma linha de apoio menor abaixo. Barra vertical fina de cor escura na borda esquerda da imagem.

Esses dois textos entram no prompt final de geração como a linha `CENA:` do slot (ver `CreativePromptBuilder::paraSlot()`) — tudo o resto do prompt (MASTER, TEXTO, FATOS PERMITIDOS, CONTAGENS, CLAIMS PROIBIDAS) continua exatamente como antes.

## Confirmação por teste — panela e quadro NÃO são tratados como móvel

Medido contra a estrutura real da API (`path_from_root`) e provado em dois níveis:

- **Unidade** (`tests/Unit/Quick261007Amb/CreativeCategoriaMobiliarioServiceTest.php`): `test_panela_de_pressao_nao_e_moveis_mesmo_com_moveis_na_raiz` e `test_quadro_decorativo_nao_e_moveis_mesmo_com_moveis_na_raiz` — os dois com a raiz "Casa, Móveis e Decoração" (que contém a palavra "Móveis") e um nó-filho sem a palavra ("Cozinha" / "Enfeites e Decoração da Casa"), confirmando `ehMoveis() === false`.
- **Ponta a ponta** (`tests/Feature/Quick261007Amb/PlanejarKitAmbientadaNaCapaTest.php`): `test_categoria_de_panela_de_pressao_nao_e_tratada_como_moveis_capa_continua_hero` e `test_categoria_de_quadro_decorativo_nao_e_tratada_como_moveis_capa_continua_hero` — planejam o kit de verdade via `PlanejarKitCriativosJob` (rota HTTP real, `QUEUE_CONNECTION=sync`) com a categoria fakeada e confirmam que o slot 1 sai `hero`.
- Mesmo arquivo feature: `test_categoria_de_cadeira_de_escritorio_planeja_kit_com_ambientada_na_capa` prova o caso positivo (cadeira de escritório → `Móveis para Casa` abaixo da raiz → slot 1 sai `lifestyle`), e `test_falha_ao_consultar_categoria_degrada_para_hero_e_nao_quebra_o_planejamento` prova a degradação graciosa (sem fake nenhum para a categoria, `Http::preventStrayRequests()` ativo, o planejamento ainda termina em `hero`, nunca falha).

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio das instruções do plano. Duas decisões de implementação não detalhadas no PLAN.md, registradas aqui:

1. **[Rule 2 — completude]** O PLAN.md não especificava ONDE o boolean `categoriaMoveis` seria resolvido. Decisão: resolver em `PlanejarKitCriativosJob::handle()` (não em `CreativeContextBuilder` nem em `CreativePlanner`), para que a consulta HTTP fique isolada no único ponto que já tem acesso ao container e para que `CreativeContextBuilder`/`CreativePlanner` continuem 100% testáveis sem `Http::fake()`. `CreativeContextBuilder` é chamado em MUITOS testes existentes sem nenhum fake de categoria configurado (ex.: `CreativeContextBuilderPublicadorTest`, que usa a categoria real de uma cadeira de escritório) — adicionar a consulta ali teria disparado `Http::preventStrayRequests()` nesses testes. Confirmado com a suíte completa: 819/819 em `tests/Feature/Publicador` + `tests/Unit/Publicador`, nenhuma regressão.
2. **[Rule 1/2 — forçar `cena` para tipos com texto]** O PLAN.md descreve o layout mas não deixa explícito se ele deveria ser só uma SUGESTÃO ao LLM ou uma RÉGUA fixa. Decisão: FORÇAR o layout do catálogo sempre que o tipo aceita texto, ignorando a `cena` que o LLM eventualmente proponha — sem isso, o LLM poderia inventar uma composição diferente a cada geração, o que contradiz o pedido do usuário de usar EXATAMENTE o layout de referência. Provado por teste (`CreativePlannerLayoutForcadoTest`) que a cena do LLM é descartada para `dimensions`/`feature_highlight`, e preservada para tipos visuais sem texto (não-regressão).

## Resultado real das suítes (não só relato)

- `tests/Unit/Quick261007Amb` + `tests/Feature/Quick261007Amb` (testes novos desta quick task): **34 passed**, 0 failed.
- `tests/Feature/Phase160`, `Phase161`, `Phase162`, `Phase165`, `Quick261003L8o`, `Quick261007Rmv`, `Quick261007Amb` + `tests/Unit/Phase160`, `Phase161`, `Phase162`, `Phase168`, `Quick261003L8o`, `Quick261007Rmv`, `Quick261007Ifa`, `Quick261007Amb` (Creative Engine completo, Feature+Unit): **426 passed**, 1 incomplete (pré-existente — `RotasAntigasComKitDoPublicadorTest::test_guarda_das_rotas_antigas_se_liga_sozinha_quando_chegar`, risco aceito desde 2026-10-04, não é desta tarefa).
- `tests/Feature/Publicador` + `tests/Unit/Publicador` (baseline do briefing): **819 passed**, 4106 assertions — bate exatamente com o número do baseline (819), nenhuma regressão.
- `tests/Feature/Phase75/PublicarEmpresaNaoAtribuidaTest` (conferido isoladamente): 2 de 4 falham com `[MLB Coleta] Falha ao obter app token: HTTP 400` — são falhas de rede pré-existentes neste ambiente sandboxed, documentadas nas restrições do briefing como não-minhas (o briefing cita 3 no total pela suíte `Publicador` completa).
- Nenhum arquivo `.jsx`/`.js` foi tocado nesta quick task — suíte JS não roda, `npm run build` não é necessário.

## Conferência pedida pelo usuário — custo para medir o layout com geração real

O usuário escolheu medir o layout com geração real antes de decidir se vale montar a composição no servidor (em vez de pedir ao modelo). Nenhuma geração real foi feita nesta tarefa (todos os testes usam dublês/fakes — nenhum custo de API). Para o usuário gerar e avaliar:

- **Um kit de 2** (capa ambientada + 1 slot de texto/visual): ~R$ 1,10.
- **Um slot regenerado** (ex.: só a imagem de medidas, de novo): ~R$ 0,55.

Sugestão de teste: escolher um anúncio de categoria de móvel (ex.: cadeira, mesa, sofá) com pelo menos uma medida de PRODUTO cadastrada (não de embalagem) no Mercado Livre, planejar o kit e avaliar: (1) se a capa ambientada ficou como esperado; (2) se o layout de medidas bateu com a referência dos prints.

## Known Stubs

Nenhum stub. Toda a lógica de decisão (categoria de móvel, layout fixo) está implementada e testada; nada ficou com dado fixo/mockado fora de teste.

## Threat Flags

Nenhuma superfície nova de ameaça: a nova chamada `GET /categories/{id}` já existe no projeto (mesmo endpoint, mesmo cache, mesmo app token público) — `CreativeCategoriaMobiliarioService` só lê um campo adicional (`path_from_root`) da MESMA resposta que outros serviços já consomem, sem abrir rota, sem novo parâmetro de entrada do usuário, sem tocar em autenticação.

## Self-Check

- `app/Services/Creative/CreativeCategoriaMobiliarioService.php` — FOUND
- `app/Services/Creative/CreativeSlotCatalog.php` — FOUND
- `app/Services/Creative/CreativePlanner.php` — FOUND
- `app/Services/Creative/CreativePromptBuilder.php` — FOUND
- `app/Services/Creative/Dto/CreativeSlotPlan.php` — FOUND
- `app/Jobs/PlanejarKitCriativosJob.php` — FOUND
- `tests/Unit/Quick261007Amb/CreativeCategoriaMobiliarioServiceTest.php` — FOUND
- `tests/Unit/Quick261007Amb/CreativeSlotCatalogPrimeiroSlotTest.php` — FOUND
- `tests/Unit/Quick261007Amb/CreativeSlotCatalogLayoutTest.php` — FOUND
- `tests/Unit/Quick261007Amb/CreativePlannerAmbientadaNaCapaTest.php` — FOUND
- `tests/Unit/Quick261007Amb/CreativePlannerLayoutForcadoTest.php` — FOUND
- `tests/Feature/Quick261007Amb/PlanejarKitAmbientadaNaCapaTest.php` — FOUND
- Commit `b01d797e` (feat, Tarefa 1 — capa ambientada) — FOUND no git log
- Commit `bd1c8352` (feat, Tarefa 2 — layout fixo) — FOUND no git log

## Self-Check: PASSED
