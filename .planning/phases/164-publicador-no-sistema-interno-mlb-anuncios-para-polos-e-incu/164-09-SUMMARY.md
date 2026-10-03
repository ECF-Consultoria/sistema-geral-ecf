---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 09
subsystem: publicador
tags: [publicador, ia, anunciar-por-ia, D14, SC6]
requires: [164-03, 164-06, 164-07, 164-08]
provides:
  - "IaParaRascunhoService::aplicar(): resultado da IA -> rascunho pub_* sempre pelo EditorRascunhoService"
  - "POST mlb.anuncios.ia.analise.store aceita produto_id (+ substituir); status devolve o bloco publicador"
affects: [164-12, 164-13]
key-files:
  created:
    - app/Services/Publicador/IaParaRascunhoService.php
    - tests/Feature/Publicador/IaParaRascunhoTest.php
  modified:
    - app/Jobs/GerarAnaliseAnuncioIaJob.php
    - app/Models/MlAnuncioIaAnalise.php
    - app/Http/Controllers/MlbAnuncioController.php
decisions:
  - "Sem migration: destino em ml_anuncio_ia_analises.resultado.destino"
  - "Digitado vence: sobrescreve só com substituir=true E revisao igual a revisao_base do pedido"
  - "Idempotência por resultado.publicador.aplicado_em"
requirements-completed: [D14, SC6]
completed: 2026-10-02
---

# Phase 164 Plan 09: Anunciar por IA no Publicador Summary

O "Anunciar por IA" passa a terminar gravando no rascunho `pub_*` do produto (categoria, características, pacote, títulos Clássico/Premium, descrição, garantia, estoque/preço e variações em eixos/variantes), pelo `EditorRascunhoService`; o wizard antigo (D22) segue no caminho de sempre.

## Commits

| Tarefa | Commit |
|---|---|
| 1. Destino publicador + fatia 1 (dados do anúncio) | `c8a0b4aa` |
| 2. Fatia 2 (variações) — testes | `0a15a063` |

O código da fatia 2 já está no serviço do commit da Tarefa 1 (um arquivo só); o segundo commit traz os testes que a cobrem.

## Contrato para o hook `useIaDoPublicador` (164-12)

- **Disparo:** `POST route('mlb.anuncios.ia.analise.store')` (`/mlb/anuncios/ia/analise`, admin, `throttle:10,1`) com `{produto_id, substituir?: bool, specs?: string, produto?: string, sku?}` (`produto` é opcional: cai no nome do produto). Resposta `202 {id, status: 'pendente', rascunho_id}`. Erros: `404` (empresa arquivada/sem programa), `422` com `message: "Este anúncio já foi publicado ou está publicando."` (rascunho PUBLISHING/PUBLISHED/PARTIALLY_PUBLISHED), `422` de validação se faltar `produto_id`/`company_id`. Não exige token. `company_id` sem `produto_id` continua o caminho antigo.
- **Acompanhamento:** `GET route('mlb.anuncios.ia.analise.status', {analise: id})`. Resposta: `{id, status: pendente|rodando|concluido|erro, etapa: analise|titulos|descricao|ficha|rascunho|null, started_at, em_andamento, erro, produto, loja, titulos, descricao, analise, modelo, duracao_ms, ficha, rascunho (sempre null no Publicador), publicador}`. `publicador` é `null` até a etapa final e depois `{rascunho_id, aplicado_em (ISO), secoes (int), variacoes (bool), aviso (string|null), sobrescreveu (bool)}`. Concluído com `publicador.aviso` não nulo: mostrar como informativo (ex.: variações não montadas, garantia sem correspondência, rascunho já publicado). `erro` com `status=erro`: faixa vermelha "Nada foi alterado".
- Ao concluir, o front relê o rascunho pela API `mlb.anuncios.publicador.*` (164-08, `abrir`), que devolve `EditorRascunhoService::estado()`.
- Pedir `substituir: true` só depois da confirmação do Dialog (UI-SPEC 8.2). O servidor só sobrescreve se o rascunho não foi editado entre o pedido e a aplicação.

## Verificação

- `tests/Unit/Publicador tests/Feature/Publicador`: 361 testes, 1734 asserções, exit 0 (antes 342/1597; `IaParaRascunhoTest` = 19 testes, 137 asserções).
- `tests/Feature/PortalCliente`: 240 testes, 2123 asserções, exit 0 (igual ao antes).
- `AnuncioIaAnaliseTest`, `AnuncioIaRascunhoTest`, `AnunciosPolosNaListagemTest`, `MlTokenAncoraPolosTest`: 52 testes, 190 asserções, exit 0 (igual ao antes).
- Aceitação: `criarRascunho(` no job = 1; zero `DB::`/`MlAnuncioRascunho`/`->update(` fora de comentários no serviço; `Http::assertNothingSent` e `MlAnuncioRascunho::count() === 0` nos testes.

## Deviations from Plan

1. **[Rule 2] Premium prefere o preço Premium do cliente nas variações.** O plano dizia `gold_pro = price ?? cliente.preco_p`; a `price` da variação vem do preço Clássico, e usá-la no Premium subestimaria. Ficou `gold_special = price ?? preco_c` e `gold_pro = preco_p ?? price`.
2. **[Rule 2] `PARTIALLY_PUBLISHED` também é intocável** (além de PUBLISHING/PUBLISHED), no store (422) e no serviço.
3. **[Rule 1] `iaAnaliseStatus` pulava a checagem de token para análise do Publicador:** sem isso uma análise de produto de empresa com `company_id` e sem token daria 404 (a IA do Publicador não exige token). A rota continua `role:admin`.
4. `produto` virou opcional no store quando há `produto_id` (cai em `nomeExibido()`); título da IA é cortado em 60 caracteres.
5. Atributos da IA entram com `origem = 'ia'` e só os de papel PRODUCT (fora SELLER_PACKAGE_*, SKU/GTIN de variante e ids que a IA usou como variação); `value_id` de lista é conferido contra o schema.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do registro do plano (T-164-40 a 43 cobertos por teste: 404, só via motor, digitado vence, publicado intocado).

## Self-Check: PASSED

Arquivos criados existem; commits `c8a0b4aa` e `0a15a063` no log. STATE.md e ROADMAP.md não tocados.
