---
phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o
plan: 03
subsystem: ml-publicador
tags: [laravel, inertia, react, creative-engine, mercado-livre, aprovacao]

requires:
  - phase: 160-01
    provides: "ml_anuncio_criativos, MlAnuncioCriativo (aprovado_por/aprovado_em/ml_picture_id/ml_picture_url já na migration)"
  - phase: 160-02
    provides: "Painel com botão Gerar, polling, imagem gerada ao lado das fotos originais (espaço reservado para o Aprovar)"
provides:
  - "criativoAprovar() — aprovação do criativo pronto: upload ao ML por MlImagemService::enviar() (PUB-02) e gravação em ml_anuncio_rascunhos.payload.pictures na posição do slot (PUB-01)"
  - "Rota mlb.anuncios.criativo.aprovar (POST, throttle:30,1)"
  - "Botão 'Aprovar e usar no anúncio' no PainelCriativosIa.jsx + callback onImagemAprovada que liga no setImagemUrl do wizard"
affects: ["161"]

tech-stack:
  added: []
  patterns:
    - "Forma de payload.pictures gravada pelo servidor IDÊNTICA à que o wizard produz ([{source: url}]) — a mitigação da armadilha do autosave é sempre dupla: forma igual no servidor + callback que atualiza o state do navegador"
    - "Estado é o guarda (não a tela): aprovação só sai de 'pronto', nunca de condição calculada no front"

key-files:
  created:
    - tests/Feature/Phase160/CriativoAprovacaoTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
    - resources/js/Pages/Mlb/AnunciarML.jsx

key-decisions:
  - "A mitigação da armadilha central do plano (autosave reconstruindo payload.pictures a partir do imagemUrl vazio) ficou OBRIGATORIAMENTE em duas metades, nenhuma sozinha resolve: (1) o servidor grava pictures[0] = {source: url} — a mesma forma que AnunciarML.jsx::montarPayload() produz; (2) o endpoint devolve a url e o painel chama onImagemAprovada(url), que faz setImagemUrl(url) no wizard. Testado só o lado do servidor aqui (Task 1/2); o lado do front é estrutural (uma linha de prop, sem lógica nova) e coberto pelo grep/contagem do Task 3, não por teste de integração JS (o projeto não tem suíte JS)."
  - "Mensagem de erro no front lê err.response.data.erros[0].mensagem (contrato real do endpoint, igual ao gerar()), com fallback para data.message — o texto do plano ('mostra data.message') foi seguido como fallback, não como único caminho, para não divergir do contrato que o próprio endpoint implementado devolve."
  - "ml_picture_url fica guardado no criativo (não só no rascunho) para auditoria e para a Fase 161 usar nas variações — decisão já anunciada no 160-02-SUMMARY, só confirmada aqui."

requirements-completed: [APROV-05, PUB-01, PUB-02]

duration: ~35min
completed: 2026-10-02
---

# Phase 160 Plan 03: Terceira fatia — aprovação fecha o fluxo Summary

**Aprovar sobe a imagem pronta ao Mercado Livre por `MlImagemService::enviar()` (o mesmo caminho de sempre), grava `payload.pictures[0]` na forma idêntica à do wizard e avisa o front via `onImagemAprovada` — a dupla mitigação que impede o autosave de apagar a aprovação em silêncio.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 5 (1 criado, 4 modificados)

## Accomplishments

- `criativoAprovar()` prova PUB-02 por grep: `pictures/items/upload` não aparece em nenhum arquivo de `app/` fora de `MlImagemService.php` — o upload mora só ali, antes e depois desta task.
- `payload.pictures[0]` é substituído na posição do slot HERO preservando as demais fotos na ordem — testado com uma foto colada à mão na posição 1 e outra na posição 2, só a primeira é trocada.
- Estado é o guarda: `pronto` aprova; `pendente`/`rodando`/`erro`/`aprovado` recusam com 422 em pt-BR e `payload.pictures` sai intacto em todos os casos — inclusive quando o ML responde 500 no upload (meia aprovação não existe: o criativo permanece `pronto`, nada é gravado).
- Duplo clique / segunda aprovação não gera segundo upload: `Http::assertSentCount(1)` depois de uma aprovação bem-sucedida seguida de uma segunda tentativa recusada.
- `PainelCriativosIa.jsx` ganha o botão "Aprovar e usar no anúncio" (só com `status=pronto`), a faixa "Aprovada" com link ao ML quando `status=aprovado`, e `AnunciarML.jsx` passa `onImagemAprovada={(url) => setImagemUrl(url)}` na montagem já existente — nenhuma função nova no arquivo, confirmado por diff.

## Task Commits

1. **Task 1: Teste de aceitação da aprovação (RED)** - `3e0e925d` (test)
2. **Task 2: Endpoint de aprovação — ML pelo caminho de sempre e gravação no rascunho (GREEN)** - `6beee38f` (feat)
3. **Task 3: Botão Aprovar no painel + ligação com o state do wizard + build** - `61b0817d` (feat)

_TDD: Task 1 (RED) → Task 2 (GREEN), commits separados como no 160-01. Task 3 não é TDD (frontend puro, sem suíte JS no projeto)._

## Files Created/Modified

- `tests/Feature/Phase160/CriativoAprovacaoTest.php` - 7 testes (PUB-01, PUB-02, APROV-05, escopo, ordem do slot)
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoAprovar()`, na mesma seção dos outros métodos de criativo
- `routes/mlb_anuncios.php` - rota `criativo.aprovar` (POST, throttle:30,1)
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - botão Aprovar, faixa de sucesso, mensagem de erro retentável
- `resources/js/Pages/Mlb/AnunciarML.jsx` - prop `onImagemAprovada` na montagem existente do painel

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: a dupla mitigação da armadilha (forma igual no servidor + callback no front) foi tratada como as duas metades obrigatórias que o plano exigiu, nenhuma delas opcional; o contrato de erro do front seguiu o formato real do endpoint (`erros[]`) com `message` como fallback, não o inverso.

## Deviations from Plan

None - plano executado exatamente como escrito. Um ajuste pontual no próprio teste (não no comportamento do sistema): a primeira versão de uma asserção de mensagem de erro usava uma expressão regular contra um valor combinado incorretamente e falhava por motivo do teste, não do endpoint; reescrita para ler `erros[0].mensagem` com fallback em `message` diretamente.

## Known Stubs

Nenhum. O botão "Gerar" permanece habilitado-mas-sem-ação quando `status=pronto`/`aprovado` (comportamento herdado da 160-02, não tocado aqui) — não é stub desta fatia.

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `160-03-PLAN.md` (T-160-15 a T-160-20) — a implementação seguiu as mitigações descritas ali: `role:admin` + double-check de empresa antes de ler bytes ou falar com o ML, `aprovado_por` vindo só de `$request->user()`, só a posição do slot é substituída, log só com ids (sem bytes/payload inteiro), e `throttle:30,1` + botão desabilitado durante a chamada contra duplo clique.

## Verification Results

1. `CriativoAprovacaoTest` — **7/7 verde** (41 assertions).
2. `CriativoReferenciaTest` + `CriativoGeracaoTest` + `CriativoGeracaoEndpointsTest` + `CriativoAprovacaoTest` (as três fatias juntas, filtro `Criativo`) — **32/32 verde** (145 assertions).
3. `grep -rn "pictures/items/upload" app/ | grep -v MlImagemService.php | wc -l` → **0** (PUB-02).
4. `npm run build` — concluído sem erro; `onImagemAprovada` aparece **1 vez** em `AnunciarML.jsx` e **4 vezes** em `PainelCriativosIa.jsx`; `git diff resources/js/Pages/Mlb/AnunciarML.jsx` confirma que nenhuma função nova foi adicionada ao arquivo.
5. `GeminiImageProviderTest` (spike V0.1) — **11/11 verde**, intocado.
6. Regressão adicional (fora do `<verification>` do plano, por segurança, mesmo escopo do 160-02): `UploadImagemTest` + `AnuncioIaAnaliseTest` + `MeusAnunciosTest` + `RascunhosMeusAnunciosTest` — **48/48 verde** (controller compartilhado só recebeu um método novo, nenhum existente foi alterado).

## Self-Check: PASSED

- `tests/Feature/Phase160/CriativoAprovacaoTest.php` — FOUND
- `app/Http/Controllers/MlbAnuncioController.php` (método `criativoAprovar`) — FOUND
- `routes/mlb_anuncios.php` (rota `criativo.aprovar`) — FOUND
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` (prop/botão `onImagemAprovada`) — FOUND
- `resources/js/Pages/Mlb/AnunciarML.jsx` (prop `onImagemAprovada`) — FOUND
- Commit `3e0e925d` — FOUND (git log)
- Commit `6beee38f` — FOUND (git log)
- Commit `61b0817d` — FOUND (git log)

---
*Phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o*
*Completed: 2026-10-02*
