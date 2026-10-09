---
phase: 173-publicador-etapa-2-visao-geral
plan: 04b
subsystem: publicador-visao-geral
tags: [publicador, visao-geral, oQueFazerAgora, correcao-de-lacuna]
dependency-graph:
  requires:
    - "173-04: PainelVisaoGeralService::oQueFazerAgora()"
    - "173-01: ProgramasPublicadorService::produtosParaTela()/contagemProdutos()"
  provides:
    - "PainelVisaoGeralService::oQueFazerAgora() com as 8 linhas da seção 3 do handoff, na ordem certa e com os destinos certos"
  affects:
    - "app/Services/Publicador/PainelVisaoGeralService.php"
    - "app/Http/Controllers/MlbPublicadorEntradaController.php"
key-files:
  modified:
    - app/Services/Publicador/PainelVisaoGeralService.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - tests/Unit/Publicador/PainelVisaoGeralServiceTest.php
    - tests/Feature/Publicador/VisaoGeralTest.php
decisions:
  - "Linha 6 ('Rascunhos com pendências') recebe um novo parâmetro opcional $produtos (default []) em oQueFazerAgora() — não quebra nenhuma chamada antiga, e sem produtos a linha simplesmente não aparece (mesma regra de 'número > 0' das outras 7)."
  - "Linha 8 ('Ficha, catálogo ou foto') muda de destino: Publicações › No ar filtrado (mlb.anuncios.meus), nunca Produtos ?motivo=. O handoff (ETAPA-2-visao-geral.md §3) é a fonte de verdade; o PLAN.md 173-04 estava errado nesse ponto, conforme apontado pelo usuário."
metrics:
  duration: "~40min"
  completed: "2026-10-08"
---

# Fase 173 Plano 04b: Duas correções de "O que fazer agora" (linha 6 e destino da linha 8) Summary

Fecha as duas lacunas documentadas no 173-04-SUMMARY.md: a linha 6 da seção 3 do handoff ("Rascunhos com pendências") estava ausente, e a linha 8 ("Ficha, catálogo ou foto") apontava para a tela errada (Produtos em vez de Publicações). Nenhuma página React tocada — mudança 100% em `PainelVisaoGeralService::oQueFazerAgora()` e na chamada do controller.

## As 8 linhas, na ordem, com destino (conferir contra a tabela do handoff)

| # | Linha | Número | Destino (final) |
|---|---|---|---|
| 1 | Conta precisa de reconexão | — (null) | `{ acao: 'reconectar', url }` — aparece SOZINHA quando token ≠ 'ativo' |
| 2 | Anúncios pausados ou sem estoque | pausados + sem_estoque (chips) | `{ rota: 'mlb.anuncios.meus', params: { company } }` → Publicações › No ar filtrado |
| 3 | Produtos com problema na publicação | `contagemProdutos.com_problema` | `{ rota: 'mlb.anuncios.publicador.produtos', params: { conta, filtro: 'com_problema' } }` → Produtos › Com problema |
| 4 | Prontos para a Fase 2 | `contagemProdutos.publicados` | `{ rota: 'mlb.anuncios.publicador.produtos', params: { conta, filtro: 'publicados' } }` → Produtos › Publicados |
| 5 | Conferidos, prontos para publicar | `contagemProdutos.conferidos` | `{ rota: 'mlb.anuncios.publicador.produtos', params: { conta, filtro: 'conferidos' } }` → Produtos › Conferidos |
| **6** | **Rascunhos com pendências** (NOVA) | status `conferir` com `faltam > 0` | `{ rota: 'mlb.anuncios.publicador.produtos', params: { conta, filtro: 'rascunho' } }` → Produtos › Rascunho. Vem com `exemplo: { nome, faltam }` do rascunho de MENOR `faltam`. |
| 7 | Ofertas novas no Portal | `situacaoPortal.novas` | `{ acao: 'sincronizar' }` |
| **8** | Ficha incompleta / Perdendo catálogo / Foto insuficiente — cada motivo uma linha distinta | contagem do chip da triagem | **CORRIGIDO**: `{ rota: 'mlb.anuncios.meus', params: { company, motivo } }` → Publicações › No ar filtrado (era `Produtos ?motivo=`) |

Ordem fixa confirmada por teste (`test_o_que_fazer_agora_ordem_fixa_e_motivos_distintos`): a linha 6 entra exatamente entre a 5 ("Conferidos, prontos para publicar") e a 7 ("Ofertas novas no Portal"), como a tabela do handoff pede.

## O que foi feito

### Lacuna 1 — linha 6 ("Rascunhos com pendências")

`oQueFazerAgora()` ganhou um parâmetro `array $produtos = []` (último, com default — nenhuma chamada antiga quebra). `visaoGeral()` já calculava `$produtos` (via `produtosParaTela()`, usado em `contagemProdutos()`) e passou a repassar para o service.

Dentro do método: filtra `$produtos` por `status.chave === 'conferir' && status.faltam > 0` (não dá pra usar `contagemProdutos['rascunho']` — esse bucket mistura a chave `'rascunho'`, que é "a preencher" com `faltam` sempre 0, com `'conferir'`, que é "em preenchimento"; a linha 6 é só a segunda, com pendência real). Entre os filtrados, ordena por `faltam` ascendente e cita o primeiro (`exemplo.nome` + `exemplo.faltam`) — "o mais perto de pronto", como pede a tabela do handoff e a referência visual (`Publicador - Diagnostico e Proposta.dc.html`, linha "rascunhos com pendências").

Sem nenhum produto com pendência, ou sem `$produtos` (chamada antiga), a linha simplesmente não entra — mesma regra "número > 0" das outras 7 linhas.

### Lacuna 2 — destino da linha 8

O `PLAN.md` da 173-04 mandava para `Produtos ?motivo=<chave>`; a tabela da seção 3 do handoff (`ETAPA-2-visao-geral.md`) manda para "Publicações › No ar filtrado" — o mesmo destino da linha 2, que também é sobre anúncios já publicados com problema (pausado/sem estoque), não produto em rascunho. Ficha incompleta, perdendo catálogo e foto insuficiente são os mesmos 3 motivos acionáveis da triagem de Meus Anúncios (`AcervoTriagemService`), então fazem sentido na mesma tela que já lista e filtra por `motivo`.

Troquei o destino das 3 linhas de motivo (cada motivo continua sendo uma linha distinta, isso não mudou) para `{ rota: 'mlb.anuncios.meus', params: { company: $companyId, motivo: $motivo } }` — confirmei em `MlbAnuncioController::meus()` que a rota já aceita `?motivo=` com os mesmos valores (`ficha_incompleta`, `perdendo_catalogo`, `foto_insuficiente`) usados pelos chips de `AcervoTriagemService::motivosDef()`.

## Deviations from Plan

Nenhuma automática (Regras 1-3) fora do que já estava no escopo pedido. As duas mudanças desta plan SÃO as duas deviations encomendadas pelo usuário em cima do que o `173-04-SUMMARY.md` tinha documentado — não há desvio adicional não pedido.

## Known Stubs

Nenhum.

## Verificação executada (resultado real)

```
php artisan test --filter=PainelVisaoGeralServiceTest                 → 16 passed (73 assertions)
php artisan test --filter='VisaoGeralTest|ConfiguracoesContaTest'     → 11 passed (56 assertions)
php artisan test tests/Feature/Publicador tests/Unit/Publicador      → 871 passed (4324 assertions), 0 failed
```

Baseline (173-04-SUMMARY.md) era 869 — subiu para 871 com os 2 testes novos deste plano (1 Unit: `test_o_que_fazer_agora_linha_6_fica_fora_sem_pendencia_ou_sem_produtos`; 1 Feature: `test_visao_geral_mostra_rascunhos_com_pendencias_citando_o_de_menor_faltam`), sem nenhuma regressão. O teste `test_o_que_fazer_agora_ordem_fixa_e_motivos_distintos` foi ampliado (fixture de `$produtos` + asserções novas de `exemplo` e dos 2 destinos corrigidos) em vez de duplicado.

Os dois comandos de suíte completa foram rodados em segundo plano (cada um demorou entre 170s e 195s) por causa do timeout padrão de 120s do shell — nenhuma falha, nenhum teste pulado.

Falhas pré-existentes conhecidas (fora do escopo desta plan, confirmadas como já existentes antes): nenhuma apareceu nesses dois filtros (`tests/Feature/Publicador tests/Unit/Publicador`), consistente com o que 173-04-SUMMARY.md já registrou — as 2 falhas conhecidas (`Phase38Publicador/MeuPainelControllerTest`, "[MLB Coleta] Falha ao obter app token") vivem fora dessas pastas.

`Http::preventStrayRequests()` continua ativo em `VisaoGeralTest` — zero chamada ao Mercado Livre confirmada de novo (nenhuma migration tocada, nenhuma query nova fora das já existentes em `contagemProdutos()`/`AcervoTriagemService`).

## Self-Check: PASSED

- `app/Services/Publicador/PainelVisaoGeralService.php` — FOUND, `oQueFazerAgora()` com 8 linhas na ordem certa
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — FOUND, `visaoGeral()` passa `$produtos` ao service
- `tests/Unit/Publicador/PainelVisaoGeralServiceTest.php` — FOUND, teste de ordem fixa cobre as 8 linhas + teste dedicado da linha 6 ausente
- `tests/Feature/Publicador/VisaoGeralTest.php` — FOUND, teste ponta a ponta da linha 6 via `visaoGeral()`
- Commits `f0e8b339` (lacuna 1 — linha 6) e `8219d9d6` (lacuna 2 — destino da linha 8) confirmados em `git log --oneline`, cada um isolado aos arquivos da sua lacuna (nenhum arquivo de outra sessão paralela incluído, apesar da árvore compartilhada — `git add --/git commit --` com pathspec explícito em ambos)

## Nota sobre árvore compartilhada

Durante a execução, outra sessão (Fase 173, Plano 07) commitou em paralelo na mesma branch `main` (`5fdac73f`, `6b440a2e`, arquivos JS do hook de identidade por conta e `Configuracoes.jsx`). Os dois commits desta plan ficaram intercalados com os dela no histórico, mas isolados corretamente — usei `git add -- <arquivos>` e `git commit -- <arquivos>` com pathspec explícito nos dois commits, exatamente como a disciplina de árvore compartilhada documentada em `.planning/learnings/` exige. Nenhum arquivo da outra sessão foi tocado ou commitado por mim.
