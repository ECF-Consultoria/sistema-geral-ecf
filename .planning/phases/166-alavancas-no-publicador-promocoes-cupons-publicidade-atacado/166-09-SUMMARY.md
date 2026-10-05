---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 09
subsystem: publicador
tags: [alavancas, atacado, pxq-b2b, x-version, recomendacoes]
requires: [166-02, 166-03]
provides:
  - AtacadoLeitura (habilitado, faixas, recomendacoes)
  - RegrasDeFaixas (ALAV-B2B-03..06)
  - Acoes/GravarFaixasAtacado (atacado.gravar)
affects: [166-11]
key-files:
  created:
    - app/Services/Publicador/Alavancas/AtacadoLeitura.php
    - app/Services/Publicador/Alavancas/RegrasDeFaixas.php
    - app/Services/Publicador/Alavancas/Acoes/GravarFaixasAtacado.php
    - tests/Feature/Publicador/Alavancas/AtacadoLeituraTest.php
    - tests/Feature/Publicador/Alavancas/AtacadoTest.php
    - tests/Unit/Publicador/Alavancas/FaixasAtacadoRegrasTest.php
    - tests/Unit/Publicador/Alavancas/SemEndpointLegadoTest.php
    - tests/fixtures-ml/alavancas/doc/atacado/prices_display_version.json
    - tests/fixtures-ml/alavancas/doc/atacado/prices_com_faixas.json
    - tests/fixtures-ml/alavancas/doc/atacado/recommendations.json
    - tests/fixtures-ml/alavancas/doc/atacado/post_pxq_ok.json
metrics:
  completed: 2026-10-04
  tasks: 2
---

# Fase 166 Plano 09: Atacado % B2B Summary

Atacado no formato que vale depois de 27/10/2026: leitura da tag `business`, das faixas atuais com a versão do preço e das recomendações do ML (POST de consulta sob a trava), e a ação `atacado.gravar` com `X-Version` relida dentro do executor. O endpoint absoluto nunca é chamado.

## Commits

| Task | Commit | Descrição |
|---|---|---|
| 1 | `15059322` | feat(166-09): leitura do atacado e recomendações de faixa |
| 2 | `74eab16c` | feat(166-09): gravar faixas de atacado em percentual B2B |

## Doc relida (curl em developers.mercadolivre.com.br, 04/10/2026)

Página `pxq-porcentagem-b2b` lida por curl com UA de navegador (HTTP 200). Fixtures marcadas `"origem": "doc-oficial"` com a URL.

- **Campo da versão:** `version` na RAIZ da resposta de `GET /items/{id}/prices?display_version=true` (ex.: `"version": 9`), enviado em `X-Version`. Confirmado.
- **A1 confirmada em parte:** a doc diz que `GET /items/{id}/prices` com `show-all-prices: true` devolve o array `price_per_quantity`. Os exemplos desse GET NÃO trazem `version` (só o `display_version=true` traz, e esse sem faixas). Por isso `faixas()` manda os dois parâmetros (`display_version=true` + cabeçalho) numa só chamada e usa o `version` da resposta; se o ML não devolver as duas coisas juntas, o `preparo()` recusa com ALAV-B2B-09 (nada enviado) em vez de gravar às cegas. Só a prova real na #459 confirma que vêm juntas.
- **Faixa mantida:** a doc diz "enviar apenas o `id`: o preço é mantido". O plano supunha objeto completo com `id`; foi adaptado: faixa mantida vai como `{"id": "N"}`. Omitir exclui; sem `id` cria; id inexistente dá 400 "Price per quantity with id N not found".
- **Absoluto no GET:** a doc NÃO mostra como o PxQ absoluto aparece em `prices[]`. A detecção (`tem_absoluto`) segue a suposição do plano: entrada de `prices[]` com `min_purchase_unit` > 1 e sem `percentage` [ASSUMED]. Se a suposição falhar, o ML devolve 400 "Cannot add price per quantity by percentage…", que o mapeador traduz (sem dano). Confirmar na #459.
- `204` nas recomendações: a doc confirma "sem recomendação". `is_incoherent_quantity` nos três exemplos da doc é `false`; a fixture tem o 3º como `true` (anotado em `nota`).

## Códigos ALAV-B2B-*

| Código | Texto |
|---|---|
| ALAV-B2B-01 | Peça de 1 a 5 quantidades diferentes para a recomendação. / Cada quantidade é um número inteiro de 1 a 100. |
| ALAV-B2B-02 | Esta conta não tem o preço por quantidade liberado pelo Mercado Livre (o ML libera por convite). |
| ALAV-B2B-03 | Cabem no máximo 5 faixas por anúncio. |
| ALAV-B2B-04 | O percentual de cada faixa precisa ser maior que 0 e menor que 100. |
| ALAV-B2B-05 | A quantidade mínima é um número inteiro de 1 a 100. |
| ALAV-B2B-06 | Os descontos precisam crescer junto com a quantidade. |
| ALAV-B2B-07 | Este anúncio tem faixas em valor fixo. Marque a substituição para gravar em percentual. |
| ALAV-B2B-08 | Uma das faixas não existe mais no anúncio. Recarregue as faixas e revise. |
| ALAV-B2B-09 | O Mercado Livre não devolveu a versão do preço deste anúncio. Nada foi enviado. |

## Comportamento

- `AtacadoLeitura::faixas`: nunca em cache; sem `price_per_quantity` na resposta, faz `GET /items/{id}` e usa a tag `standard_price_by_quantity` (`tem_faixas: true`, `faixas: null`, aviso). Ordenadas por quantidade.
- `recomendacoes`: ordem `AlavancasLiberadas::exigir` (primeira linha, zero HTTP) -> quantidades (B2B-01) -> `habilitado` (B2B-02) -> `consultaPorPost`. As quantidades são validadas ANTES de `habilitado` (o plano listava o inverso) para que quantidade inválida não gaste nem o GET de `/users/me`.
- `GravarFaixasAtacado`: `validar()` lê as faixas (memo por instância, `resumo()` reaproveita); faixa com `id` mas valor alterado perde o `id` e vira nova (avisado no resumo); `preparo()` = GET da versão imediatamente antes do POST; `remove-absolute-pxq=true` só com `remover_absoluto`; 409 vira ERRO com a mensagem do mapeador e exatamente 1 POST.
- `resumo()` ganhou a chave extra `recomendacoes` (lista, `[]` se 204, `null` em conta não liberada ou sem faixas). Em conta liberada faz o POST de consulta; falha da consulta vira aviso, não quebra a prévia.

## Testes

- `tests/Unit/Publicador/Alavancas` + `tests/Feature/Publicador/Alavancas`: 160 testes, 560 asserções, exit 0.
- `tests/Unit/Publicador`: 223 testes, 694 asserções, exit 0 (antes: 193).
- `tests/Feature/Publicador`: 331 testes, 1862 asserções, exit 0 (antes: 282).
- Novos: AtacadoLeitura 11, Atacado 12, FaixasAtacadoRegras 11, SemEndpointLegado 1.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] `SemEndpointLegadoTest` criado**
- **Found during:** Task 2 (o comando de verificação do plano o cita, mas ele não existia — o plano que o cria é o da Publicidade).
- **Fix:** criei `tests/Unit/Publicador/Alavancas/SemEndpointLegadoTest.php` com a varredura por `token_get_all` da fonte das Alavancas contra `standard/quantity` (só strings, ignora comentários). O plano de Publicidade pode estendê-lo para os caminhos legados de Ads.
- **Commit:** `74eab16c`

**2. [Doc] Faixa mantida só com `id`** (ver acima) e **ordem das validações de `recomendacoes`** (quantidades antes de `habilitado`). Sem mudança de regra de negócio.

## Known Stubs

Nenhum.

## Notas

Nenhuma chamada de rede ao ML pelo código/testes (tudo `Http::fake`); a doc foi lida por curl só em developers.mercadolivre.com.br. STATE.md e ROADMAP.md intocados; sem push nem deploy. Pendente para a prova real na #459 (com confirmação antes de cada POST): `version` junto das faixas no mesmo GET, formato do PxQ absoluto no GET, gravar 1 faixa e relê-la.

## Self-Check: PASSED
