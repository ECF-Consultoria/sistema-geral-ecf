---
phase: 172-ficha-do-portal-completa-ate-o-publicador
plan: 10
subsystem: publicador
tags: [portal, rascunho, fotos, combo, kit, combit, so-vazio]
requires: [172-04, 172-07, 172-08]
provides:
  - PortalParaRascunhoService::preencher cobre fotos por grupo de variação e ofertas compostas (Combo/Kit/Combit)
  - resumo.fotos_trazidas / fotos_nao_trazidas (motivo => quantidade)
affects: [172-11]
key-files:
  modified:
    - app/Services/Publicador/PortalParaRascunhoService.php
  created:
    - tests/Feature/Publicador/PortalFotosTest.php
    - tests/Feature/Publicador/PortalComposicaoNoRascunhoTest.php
requirements: [FP172-06, FP172-07, FP172-09]
completed: 2026-10-08
---

# Phase 172 Plan 10: fotos por variação e ofertas compostas no rascunho

Cada cor do Portal leva as fotos para o grupo da variação (só grupo vazio, WebP vira JPG, nada sobe ao ML) e Combo/Kit/Combit chegam com categoria e ficha do principal, pacote empilhado, estoque do conjunto e fotos de todos os componentes.

## Commits

| Task | Commit | Assunto |
|---|---|---|
| 1 (e código da 2) | 5872d7ab | feat(172-10): fotos do Portal chegam ao grupo da variação, só no vazio e sem subir ao ML |
| 2 | 959e3d63 | test(172-10): Combo, Kit e Combit no rascunho |

O serviço inteiro (fotos + ramo composto) entrou no primeiro commit, porque é um arquivo só e a extração dos helpers é comum; a segunda tarefa trouxe os testes do ramo composto.

## Decisões de implementação

- Helpers extraídos do `preencher` do 172-08, sem duplicar regra: `categoriaDoPortal`, `aplicarCategoria`, `aplicarFicha`, `concluir`. Grupo e composta passam pelo mesmo código de categoria/ficha/pacote.
- Fotos: `trazerFotosDoGrupo` liga `fotos_por_variante` (como a tela) quando há 2+ variantes, nenhum eixo define foto e o rascunho não tem foto; grupo = `ResolvedorGruposImagem::chaveDoGrupo`; grupo com atribuição existente não é tocado (D-05); `receber(enviar: false)` + `colocarFotoNoGrupo`. Intocável é rechecado por grupo.
- Motivos em `fotos_nao_trazidas`: `pequena` (V-IMG-03), `arquivo_sumido`, `formato` (não converte ou V-IMG-01), `arquivo_grande` (V-IMG-02, acima de 10 MB), `acima_do_limite` (excedente do `max_pictures_per_item`/`_var` do schema; sem limite no schema = sem corte).
- Composta: principal por `ComposicaoDoPortal::principal`; pacote por `pacoteDoConjunto`; estoque por `estoque` (só se nulo); SELLER_SKU = SKU da oferta (só se vazio); fotos no `GENERAL`, principal primeiro e depois os demais na ordem dos componentes. Rascunho com eixos da equipe: variante e fotos não são tocadas.
- Componente sem variação ou de outra empresa: o leitor já descarta com aviso; os avisos entram no resumo.

## Testes

- `PortalFotosTest`: 11 testes, 31 asserções (grupo por cor na ordem, fotos_por_variante, só-vazio, idempotência, WebP, pequena, sumida, formato, limite do schema, zero requisição ao ML com conta liberada, foto de outra empresa).
- `PortalComposicaoNoRascunhoTest`: 7 testes, 40 asserções (Combo, Kit mesa+4 cadeiras, Kit de 3 sem par, estoque nulo, componente sem variação, só-vazio e idempotência, componente de outra empresa).
- `tests/Feature/Publicador` + `tests/Unit/Publicador`: 947 testes, 4555 asserções, OK (anterior 896+).
- Mutações conferidas e desfeitas: removendo a guarda do grupo vazio, 2 testes ficam vermelhos; forçando o principal ao índice 0, o teste da mesa e o do kit de 3 ficam vermelhos.

## Deviations from Plan

Nenhuma de regra. Motivo extra `arquivo_grande` (além dos listados) para V-IMG-02. Sem front-end alterado (sem `npm run build`).

## Known Stubs

Nenhum.

## Self-Check: PASSED

Arquivos conferidos; STATE.md e ROADMAP.md intocados.
