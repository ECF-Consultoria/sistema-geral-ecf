---
phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k
plan: 03
subsystem: creative-engine
tags: [laravel, inertia, react, creative-engine, mercado-livre, publicacao]

requires:
  - phase: 161-01
    provides: "MlAnuncioCriativoKit (estados/tetos/recalcularStatus), CreativePermissao, kit_id/slot_indice/slot_plano em ml_anuncio_criativos"
  - phase: 161-02
    provides: "GerarCriativoIaJob adaptado ao slot (uniqueId por criativo, fila creative), CreativeKitDespachante, KitCriativosGrade.jsx com o espaço reservado para os botões"
  - phase: 160-03
    provides: "criativoAprovar() — upload ao ML por MlImagemService::enviar() (PUB-02), gravação em payload.pictures (PUB-01)"
  - phase: 160-04
    provides: "ReferenciaEfemeraService::apagar() — deleção na aprovação + varredura diária de 48h como rede de segurança"
provides:
  - "criativoRegenerar() — reabre UM slot do kit (status=pendente, slot_plano intacto), teto por asset (coluna nova regeneracoes) e por kit"
  - "migration aditiva regeneracoes em ml_anuncio_criativos — contagem de CLIQUE do operador, separada de tentativas"
  - "CreativeKitPublicacao::aplicarPictures() — reconstrói payload.pictures a partir dos aprovados do kit, em ordem de slot_indice, truncado no limite da categoria (PUB-04)"
  - "criativoAprovar() generalizado: com kit, usa aplicarPictures() e NÃO apaga a referência; sem kit, caminho da Fase 160 intocado"
  - "criativoKitAprovar() — aprova todos os slots prontos numa chamada, resume aprovadas/falharam, só fecha o kit (e apaga a referência) sem nenhuma falha e com o mínimo atingido"
  - "GET kit.status estendido com regeneracoes/regeneracoes_restantes/ml_picture_url por slot e prontas/aprovadas no kit"
  - "KitCriativosGrade.jsx com botões de regenerar/aprovar por cartão; PainelCriativosIa.jsx com o botão Aprovar kit"
affects: ["161-04"]

tech-stack:
  added: []
  patterns:
    - "Contagem de clique do operador em coluna PRÓPRIA, nunca reaproveitando um contador que também sobe por retentativa automática do provedor/fila ($tries do job) — a mesma distinção que já existe entre tentativas (job) e regeneracoes (clique)"
    - "payload.pictures com kit é sempre RECONSTRUÍDO do banco (nunca escrito por índice) — elimina a ordem de aprovação como variável e é a mesma função que a publicação (161-04) vai chamar de novo antes de publicar"
    - "Resumo de lote por item (['aprovadas'=>n,'falharam'=>[indices]]) em vez de tudo-ou-nada — molde já usado em publicarDuplo/publicarLote deste controller"

key-files:
  created:
    - database/migrations/2026_10_03_090200_add_regeneracoes_to_ml_anuncio_criativos_table.php
    - app/Services/Creative/CreativeKitPublicacao.php
    - tests/Feature/Phase161/CriativoRegeneracaoTest.php
    - tests/Feature/Phase161/CriativoKitAprovacaoTest.php
  modified:
    - app/Models/MlAnuncioCriativo.php
    - app/Models/MlAnuncioCriativoKit.php
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx

key-decisions:
  - "Decisão do ponto em aberto do plano: tentativas-1 NÃO serve de teto de regeneração por asset, porque GerarCriativoIaJob::$tries=2 faz o Laravel chamar handle() de novo (incrementando tentativas) numa retentativa automática por falha transitória do provedor — sem nenhum clique do operador. Criada a coluna regeneracoes em ml_anuncio_criativos (migration aditiva, default 0, sem FK, sem índice novo — não se aplicam as armadilhas de nullOnDelete/índice de 64 chars desta migration específica), incrementada SÓ dentro de criativoRegenerar(). MlAnuncioCriativoKit::podeRegenerarAsset()/motivoDoTetoAsset() foram atualizados para usar esta coluna em vez de tentativas-1."
  - "criativoAprovar() (endpoint único desde a 160-03) passou a servir dois papéis: aprovação do fluxo de 1 imagem (Fase 160, intocado) E aprovação de UM slot de um kit (Fase 161) — não foi criado endpoint novo porque um slot de kit É um MlAnuncioCriativo normal; a diferença de comportamento (apagar referência sim/não, escrever pictures direto ou via aplicarPictures) é decidida por criativo->kit ser null ou não, dentro do mesmo método."
  - "Resposta de aprovação (tanto criativoAprovar quanto criativoKitAprovar) devolve a URL do SLOT 1 (hero) do kit, não a do slot recém-aprovado — pode ser null quando o slot 1 ainda não foi aprovado. É a mesma mitigação da armadilha do autosave da 160-03 (onImagemAprovada aponta o imagemUrl do wizard para a imagem principal), generalizada para 7 slots sem tocar em AnunciarML.jsx."
  - "Falha parcial na aprovação do kit NÃO chama recalcularStatus(): esse método só sabe contar pendente/rodando/pronto/erro (não entende 'aprovado' misturado com 'pronto') e cairia no branch default ('gerando') mesmo sem nada gerando de fato. Em falha parcial o status do kit simplesmente não é tocado — ele segue refletindo o estado de GERAÇÃO, independente do estado de APROVAÇÃO de cada slot."

requirements-completed: [APROV-02, APROV-03, PUB-04]

duration: ~1h50min
completed: 2026-10-02
---

# Phase 161 Plan 03: Kit dinâmico de 7 — regeneração, aprovação por imagem e aprovação do kit Summary

**O operador regenera uma imagem ruim sem afetar as outras 6 (teto por asset e por kit, medido em cliques — não em retentativas automáticas), aprova imagem por imagem ou o kit inteiro de uma vez, e `payload.pictures` é sempre reconstruído do banco em ordem de slot, truncado no limite de fotos da categoria.**

## Performance

- **Duration:** ~1h50min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 10 (3 criados, 7 modificados)

## Accomplishments

- `criativoRegenerar()` prova APROV-02 pelo teste mais importante do plano: regenerar o slot `benefits` deixa os outros 6 **byte a byte iguais** (status/imagem_path/tentativas inalterados) — a diferença entre "regenerar um slot" e "redisparar o kit".
- A armadilha do ponto em aberto foi resolvida ANTES de escrever o teste (conforme o plano pediu): `tentativas - 1` contaria uma retentativa automática do provedor (`$tries=2`) como clique do operador. Coluna `regeneracoes` nova mede exatamente o que o teto de custo precisa medir — clique, não retentativa.
- `CreativeKitPublicacao::aplicarPictures()` prova a Decisão 8 do plano por teste: aprovar o slot 3 antes do slot 1 nunca promove a foto errada à posição principal — a lista é reconstruída do zero, em ordem de `slot_indice`, a cada aprovação.
- A armadilha central da fase (apagar a referência na 1ª aprovação mataria a regeneração dos outros 6) tem dois testes dedicados: aprovar 1 slot não apaga nada (`referencias_apagadas_em` continua nulo, diretório continua em disco); aprovar o KIT INTEIRO apaga — e só então.
- `criativoKitAprovar()` prova a falha parcial sem meia-aprovação: um upload que falha deixa aquele slot `pronto` (não `erro`, não `aprovado`), os outros 4 que subiram ficam `aprovado`, o kit não fecha, e a referência do portador continua viva — a regeneração do slot que falhou ainda é possível depois.
- PUB-04 provado com categoria `max_pictures_per_item=6` e 7 aprovadas: `payload.pictures` sai com 6, sempre começando pelo slot 1 (o corte nunca remove a imagem principal).
- `KitCriativosGrade.jsx` ganha os botões por cartão (pronto: regenerar+aprovar; erro: só regenerar; aprovado: faixa com link ao ML, sem botão) e `PainelCriativosIa.jsx` ganha o botão "Aprovar kit", desabilitado até `prontas+aprovadas >= minimo_aprovadas` — números que vêm do servidor, nunca recalculados na tela.
- `npm run build` sem erro; `git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx` continua vazio.

## Task Commits

1. **Task 1: Regenerar uma imagem do kit, com teto e sem tocar nas outras** - `1e6a39fc` (feat, com teste)
2. **Task 2: Aprovar imagem por imagem, aprovar o kit, e a ordem dos slots no rascunho** - `426801c1` (feat, com teste)
3. **Task 3: Botões de regenerar e aprovar na grade, e o Aprovar kit no painel** - `c46843c2` (feat)

_Nenhuma task teve RED/GREEN em commits separados — igual às duas ondas anteriores desta fase: teste e implementação escritos e verificados juntos antes de cada commit._

## Files Created/Modified

- `database/migrations/2026_10_03_090200_add_regeneracoes_to_ml_anuncio_criativos_table.php` - coluna aditiva `regeneracoes` (cliques do operador)
- `app/Models/MlAnuncioCriativo.php` - `regeneracoes` no fillable
- `app/Models/MlAnuncioCriativoKit.php` - `podeRegenerarAsset()`/`motivoDoTetoAsset()` migrados de `tentativas-1` para `regeneracoes`; `regeneracoesRestantesAsset()`, `maxRegeneracoesAsset()`, `maxRegeneracoesKit()` novos
- `app/Services/Creative/CreativeKitPublicacao.php` (novo) - `aplicarPictures()`/`limiteDaCategoria()` (PUB-01/PUB-04), consumido também pelo 161-04
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoRegenerar()`, `criativoKitAprovar()`, `criativoAprovar()` ganhou os 3 ajustes do plano, `criativoKitStatus()` estendido
- `routes/mlb_anuncios.php` - `criativo.regenerar` (throttle:12,1), `criativo.kit.aprovar` (throttle:12,1)
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` - botões por cartão (`onRegenerar`/`onAprovar`), faixa de aprovado com link
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - `regenerar()`/`aprovarSlot()`/`aprovarKit()`, botão "Aprovar kit"
- `tests/Feature/Phase161/CriativoRegeneracaoTest.php` (novo) - 13 testes (APROV-02, tetos, escopo, OPS-04)
- `tests/Feature/Phase161/CriativoKitAprovacaoTest.php` (novo) - 13 testes (APROV-03, ordem dos slots, PUB-04, armadilha 1, falha parcial)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: `regeneracoes` é coluna nova e própria (não reaproveita `tentativas`); `criativoAprovar()` ficou sendo o endpoint único para aprovar QUALQUER criativo, com ou sem kit, decidindo o comportamento por `criativo->kit`; a resposta de aprovação sempre devolve a URL do slot 1 (hero), nunca a do slot recém-aprovado; falha parcial no `criativoKitAprovar()` não toca no status do kit porque `recalcularStatus()` não entende a mistura `aprovado`+`pronto`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `consultarKit()` nunca repassava `referencias`/`prontas`/`aprovadas` do servidor para o state do kit**
- **Found during:** Task 3, ao escrever o botão "Aprovar kit" (que lê `kit.prontas`/`kit.aprovadas`)
- **Issue:** Desde a 161-02, `criativoKitStatus()` já devolvia `referencias` (fotos originais) no JSON, mas `consultarKit()` em `PainelCriativosIa.jsx` montava o novo `kit` state sem o campo `referencias` — a seção "Fotos originais usadas como referência" da grade nunca aparecia depois da primeira consulta de status (ficava presa no array vazio do `planejarKit()`/`gerarKit()` inicial). Era um stub silencioso, não causava erro.
- **Fix:** `consultarKit()` passou a incluir `referencias: data.referencias ?? []`, junto com os campos novos `prontas`/`aprovadas` que esta task precisava de qualquer forma.
- **Files modified:** `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx`
- **Verification:** `npm run build` sem erro; conferido por leitura do fluxo de dados (sem suíte JS no projeto, mesma limitação documentada desde a 160-03).
- **Commit:** `c46843c2`

**2. [Rule 2 - Funcionalidade crítica ausente] `criativoKitAprovar()` não devolvia o campo `url`**
- **Found during:** Task 3, ao escrever `aprovarKit()` no painel
- **Issue:** O plano exige que `onImagemAprovada(url)` seja chamado "em qualquer aprovação que a produza" com a URL do slot 1. `criativoAprovar()` (Task 2) já devolvia isso; `criativoKitAprovar()` (também Task 2) não — a aprovação do KIT INTEIRO não alimentaria a mitigação do autosave da 160-03 para o `imagemUrl` do wizard.
- **Fix:** Adicionado `'url' => $kit->slots()->where('slot_indice', 1)->first()?->ml_picture_url` na resposta de `criativoKitAprovar()`, mesmo contrato de `criativoAprovar()`. Teste `test_aprovar_kit_com_minimo_atingido_sobe_todos_os_prontos_e_fecha_o_kit` (já existente, escrito na Task 2) ganhou a asserção do campo `url`.
- **Files modified:** `app/Http/Controllers/MlbAnuncioController.php`, `tests/Feature/Phase161/CriativoKitAprovacaoTest.php`
- **Verification:** `CriativoKitAprovacaoTest` 13/13 verde com a asserção nova.
- **Commit:** `c46843c2` (mesmo commit da Task 3, por ser consumido só a partir dela)

---

**Total deviations:** 2 (1 bug de stub silencioso na mesma função que a Task 3 precisava tocar; 1 funcionalidade crítica ausente para o contrato do callback)
**Impact on plan:** Nenhum em escopo — os dois ajustes vivem nas MESMAS funções/arquivos que as tasks já modificavam, nenhum arquivo fora do declarado em `files_modified` do plano foi tocado além do que a Decisão do ponto em aberto já previa (a migration de `regeneracoes`).

### Divergência de documentação (não é bug)

O `<verification>` do plano (item 5) espera **10 rotas** depois deste plano. O total real é **11**: a Task 1 acrescenta `criativo.regenerar` e a Task 2 acrescenta `criativo.kit.aprovar` — são 2 rotas novas sobre as 9 que já existiam depois da 161-02 (confirmado pelo `161-02-SUMMARY.md`), não 1. Confirmado por `artisan route:list --name=mlb.anuncios.criativo` → 11 rotas, todas as esperadas, nenhuma faltando ou duplicada. Mesma natureza das divergências já documentadas nas duas Summaries anteriores desta fase — a aritmética do texto do plano ficou um passo atrás do número real de rotas acrescentadas.

### Observação fora do escopo deste plano (não é regressão)

`grep -rln "pictures/items/upload" app/ | grep -v MlImagemService.php` devolve **2 ocorrências** (`app/Services/Publicador/ClienteMlPublicador.php`, `app/Services/Publicador/ImagemAssetService.php`), não 0 como o `<verification>` do plano espera literalmente. Essas duas ocorrências pertencem a um módulo **completamente diferente** ("Publicador", commits `feat(publicador): F1.7/F1.8`, datados de 2026-10-01 — ANTES mesmo da 161-01), não tocado por nenhum plano desta fase. Escopado só ao Creative Engine (`app/Http/Controllers/MlbAnuncioController.php` + `app/Services/Creative/` + `app/Services/Mlb/Publicacao/`), a contagem é **0** — PUB-02 continua garantido onde este plano tem jurisdição. Registrado aqui por honestidade de verificação, não corrigido (Rule de escopo: não é código tocado por esta fase, módulo de outra sessão/milestone na árvore compartilhada).

## Known Stubs

Nenhum introduzido por este plano. O stub pré-existente da 161-02 (fotos de referência nunca apareciam na grade do kit) foi corrigido como parte do Deviation 1 acima.

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `161-03-PLAN.md` (T-161-16 a T-161-23, T-161-SC) — a implementação seguiu as mitigações descritas ali: `throttle:12,1` em ambas as rotas novas + tetos por asset/kit/imagens + recusa de slot em andamento (T-161-16); `aplicarPictures()` reconstrói por `slot_indice` a cada chamada, nunca escreve por índice (T-161-17); corte em `aplicarPictures()` pelo `max_pictures_per_item` preservando o slot 1 (T-161-18); `role:admin` + double-check de empresa + `CreativePermissao::exigir()` antes de ler bytes ou falar com o ML em `criativoRegenerar()`/`criativoKitAprovar()` (T-161-19); `aprovado_por`/`aprovado_em` sempre de `$request->user()` (T-161-20); log só com ids (T-161-21); deleção da referência só na aprovação do KIT, com `try/catch` (T-161-22); estado é o guarda contra segunda aprovação/upload (T-161-23). Nenhum pacote novo foi instalado (T-161-SC).

## Verification Results

1. `phpunit tests/Unit/Phase161 tests/Feature/Phase161` — **80/80 verde** (374 assertions).
2. `phpunit --filter=Criativo` (Fase 160 + 161 juntas) — **103/103 verde** (491 assertions) — inclui os 7 testes de `CriativoAprovacaoTest` (160-03) e 9 de 160-04/`CriativoRetencaoTest` intocados.
3. `grep -rln "pictures/items/upload" app/ | grep -v MlImagemService.php` — **2 ocorrências, fora do escopo do Creative Engine** (ver nota de observação acima); escopado a `app/Http/Controllers/MlbAnuncioController.php` + `app/Services/Creative/` + `app/Services/Mlb/Publicacao/` → **0**.
4. `npm run build` — concluído sem erro (3m28s); `git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx` — **vazio**.
5. `artisan route:list --name=mlb.anuncios.criativo` → **11 rotas** (9 da 161-02 + 2 novas — ver nota de divergência de documentação acima).
6. Regressão adicional (fora do `<verification>` do plano, por segurança, mesmo escopo das Summaries anteriores): `UploadImagemTest` + `AnuncioIaAnaliseTest` + `MeusAnunciosTest` + `RascunhosMeusAnunciosTest` — **48/48 verde** (163 assertions).
7. Conjunto completo pinado pelas restrições do executor (`tests/Feature/Phase160/`, `tests/Unit/Phase160/`, `tests/Feature/Phase161/`, `tests/Unit/Phase161/`, `tests/Unit/GeminiImageProviderTest.php`) — **146/146 verde** (635 assertions), nenhuma regressão.

## Self-Check: PASSED

- `database/migrations/2026_10_03_090200_add_regeneracoes_to_ml_anuncio_criativos_table.php` — FOUND
- `app/Services/Creative/CreativeKitPublicacao.php` — FOUND
- `tests/Feature/Phase161/CriativoRegeneracaoTest.php` — FOUND
- `tests/Feature/Phase161/CriativoKitAprovacaoTest.php` — FOUND
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` — FOUND
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` — FOUND
- Commit `1e6a39fc` — FOUND (git log)
- Commit `426801c1` — FOUND (git log)
- Commit `c46843c2` — FOUND (git log)

---
*Phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k*
*Completed: 2026-10-02*
