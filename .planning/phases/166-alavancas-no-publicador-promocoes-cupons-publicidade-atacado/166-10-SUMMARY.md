---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 10
subsystem: publicador
tags: [alavancas, rotas, leituras-json, historico, inertia]
requires: [166-04, 166-05, 166-06, 166-09]
provides:
  - MlbAlavancasController (página + leituras JSON + histórico)
  - rotas mlb.anuncios.publicador.alavancas.* (todas em role:admin)
affects: [166-11, 166-12, 166-13, 166-14, 166-15]
key-files:
  created:
    - app/Http/Controllers/MlbAlavancasController.php
    - tests/Feature/Publicador/Alavancas/AcessoAlavancasTest.php
    - tests/Feature/Publicador/Alavancas/LeiturasHttpTest.php
    - tests/Feature/Publicador/Alavancas/HistoricoTelaTest.php
  modified:
    - routes/mlb_anuncios.php
decisions:
  - Controller enxuto: resolve a conta pelo resolver e delega; comConta() concentra 409/403/422/502
  - Histórico não usa comConta (não depende de token nem do ML)
metrics:
  completed: 2026-10-05
  tasks: 2
---

# Fase 166 Plano 10: Rotas de leitura, controller e histórico Summary

Porta HTTP de leitura das Alavancas: página (`Mlb/Publicador/Alavancas`), endpoints JSON sob demanda e histórico por empresa, só admin, nas duas âncoras (empresa-N e company-N), sem chamar o ML ao renderizar a página.

## Commits

| Task | Commit | Descrição |
|---|---|---|
| 1 | ver `git log` (`feat(166-10): rotas e leituras JSON das Alavancas`) | rotas + `MlbAlavancasController` completo + `AcessoAlavancasTest` + `LeiturasHttpTest` |
| 2 | ver `git log` (`feat(166-10): histórico das Alavancas por empresa ...`) | `HistoricoTelaTest` |

## Rotas (prefixo `/mlb/anuncios/publicador/empresas/{conta}/alavancas`, nomes `mlb.anuncios.publicador.alavancas.*`)

| Nome | Método e caminho | Throttle |
|---|---|---|
| `index` | GET `/` | — |
| `panorama` | GET `panorama` (`?atualizar=1`) | 60/min |
| `promocoes` | GET `promocoes` (`?atualizar=1`) | 60/min |
| `promocoes.itens` | GET `promocoes/{promocao}/itens` | 120/min |
| `produtos` | GET `produtos` | 60/min |
| `produtos.promocoes` | GET `produtos/{item}/promocoes` | 120/min |
| `analise` | POST `analise` | 20/min |
| `cupons` | GET `cupons` | 60/min |
| `exclusao` | GET `exclusao` | 60/min |
| `exclusao.item` | GET `exclusao/{item}` | 120/min |
| `publicidade` | GET `publicidade` | 30/min |
| `publicidade.ad-groups` | GET `publicidade/ad-groups` | 30/min |
| `atacado` | GET `atacado` | 30/min |
| `atacado.item` | GET `atacado/{item}` | 60/min |
| `atacado.recomendacoes` | POST `atacado/{item}/recomendacoes` | 30/min |
| `historico` | GET `historico` | 60/min |
| `historico.mostrar` | GET `historico/{escrita}` | 120/min |

`{conta}` = `(empresa|company)-[0-9]+`; `{item}` = `MLB[0-9]+`; `{promocao}` = `[A-Za-z0-9-]{1,40}`; `{escrita}` numérico.

## Contrato JSON (para a tela)

Erros comuns de todos os endpoints (menos histórico): conta sem token ativo = **409** `{message, regra: 'V-ACC-01'}`; `RegraViolada` `ALAV-LIB`/`V-ACC-03` = **403** `{message, regra}`; outra `RegraViolada` = **422** `{message, regra}`; validação = 422 padrão do Laravel; falha inesperada = **502** `{message: 'Não deu para ler agora. Tente de novo em instantes.'}`.

- **Página (Inertia)** props: `empresa` (de `empresaParaTela`) e `alavancas` `{liberada, motivo (null quando liberada), tem_conta, limites {itens_por_lote, itens_por_analise}, alertas (config publicador.alavancas.alertas)}`.
- **panorama**: `{conta, convites, cupons, publicidade, atacado}`; cada fonte pode vir `{erro: 'Não deu para ler agora.'}`.
- **promocoes**: `{itens[] (+alertas: [{codigo, texto}]), total, truncado}`.
- **promocoes/{id}/itens**: query `tipo` (obrigatório, TiposDePromocao::TODOS), `cursor`, `status` (candidate|pending|started), `status_item` (active|paused); resposta `{itens[], proximo, reiniciado}`.
- **produtos**: query `busca` (até 120), `pagina` (1..1000); resposta `{itens, total, pagina, por_pagina, aviso, busca_local}`.
- **produtos/{item}/promocoes**: `{itens: [...]}`.
- **analise** (POST): `{itens: [{item_id, preco_promocao?, promotion_type?, meli_percentage?, seller_percentage?, estoque_minimo?, boost?}] (1..limites.itens_por_analise)}`; resposta `{itens, parcial}`.
- **cupons**: `{itens, truncado}`. **exclusao**: `{excluida}`. **exclusao/{item}**: `{item_id, excluido}`.
- **publicidade**: query `de`/`ate` (Y-m-d; padrão últimos 30 dias de São Paulo; janela inválida = 422 `ALAV-DATA`). Resposta `{de, ate, anunciante, campanhas, resumo, bonificacoes}` ou só `{indisponivel}`.
- **publicidade/ad-groups**: query `itens` (csv de MLB, até 20), `de`, `ate`; resposta `{ad_groups, resumo?, indisponivel?}`.
- **atacado**: `{business, explicacao}` (explicação do D-10 quando sem business).
- **atacado/{item}**: `{versao, preco_padrao, faixas, tem_faixas, tem_absoluto, aviso}`.
- **atacado/{item}/recomendacoes** (POST): `{quantidades: int[1..5] (1..100), preco > 0}`; resposta `{recomendacoes[], sem_recomendacao}`; conta não liberada = 403 `ALAV-LIB` sem nenhuma chamada ao ML.
- **historico**: query `alavanca`, `resultado`, `pagina`; resposta `{linhas: [{id, quando, enviado_em, ator_nome, alavanca, acao, promotion_type, promotion_id, item_id, resultado, http_status, erro_codigo, mensagem, lote_uuid}], paginacao: {pagina, por_pagina (20), total, ultima}}`.
- **historico/{id}**: o mesmo mais `concluido_em, metodo, caminho, conta_chave, ml_seller_id, payload, resumo, resposta`; de outra empresa = 404.

## Testes

- Os 3 arquivos novos: 33 testes, 159 asserções, exit 0.
- `tests/Unit/Publicador`: 239 testes, 736 asserções, exit 0 (baseline 156 antes da fase).
- `tests/Feature/Publicador`: 509 testes, 2.875 asserções, **1 falha** (abaixo), exit 1.

## Deviations from Plan

1. **[Rule 3 - ajuste de teste]** O layout (`HandleInertiaRequests`) consulta o ECF Drive (`files.ecfconsultoria.com.br/api/v1/signals`) ao renderizar qualquer página; por isso o teste de "não chama o ML" filtra as chamadas por host `mercadolibre` em vez de exigir `chamadas === []`. Nenhuma chamada ao ML sai.
2. **[Organização]** As duas tarefas foram escritas juntas; o controller já nasceu completo no commit 1 (publicidade, atacado e histórico incluídos) e os casos de publicidade/atacado ficaram em `LeiturasHttpTest` no commit 1. O commit 2 traz o `HistoricoTelaTest`.
3. Validação extra no `adGroups`: `itens` fora de `MLB\d+` ou mais de 20 = 422 (o serviço só descartaria em silêncio).

## Deferred Issues

- `PanoramaTest::test_junta_conta_convites_cupons_publicidade_e_atacado` (do 166-05) falha hoje (05/10/2026): o convite `VOLUME` da fixture `users_promotions` tem prazo vencido e agora é filtrado como convite encerrado. Dependente de data, não causado por este plano (falha rodando o arquivo isolado, sem nada deste plano envolvido). Corrigir fixando a data do teste (`Carbon::setTestNow`) ou datas relativas na fixture.

## Known Stubs

Nenhum. A página React `Mlb/Publicador/Alavancas` nasce no 166-12 (os testes leem `viewData('page')`).

## Threat Flags

Nenhum além do registro do plano (T-166-45..49 mitigados: role:admin com teste 403 por família; histórico sempre por `daEmpresa` com as âncoras do resolver; regex nas rotas + validação inline; throttle por rota; 409/502 sem stack).

## Self-Check: PASSED

Arquivos criados conferidos; commits `feat(166-10)` x2 presentes no branch `feat/publicador-ml-261001`; diff de `routes/mlb_anuncios.php` só com acréscimos; STATE.md e ROADMAP.md intocados.
