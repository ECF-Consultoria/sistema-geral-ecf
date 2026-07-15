---
phase: 80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s
plan: 01
subsystem: bonus
tags: [nps, desempenho, bonus, atribuicoes, dual-path, eloquent, tdd]

# Dependency graph
requires:
  - phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
    provides: "nps_score_assignments (atribuição congelada média×pessoa×role×serviço) escrita pelo NpsSnapshotService"
  - phase: 74-desempenho-engine-v2
    provides: "DesempenhoScoreService::computeNpsMedio + fixture Carlos (âncora 4.08/basico)"
provides:
  - "computeNpsMedio dual-path: união DISJUNTA por resposta entre atribuições (Fase 79) e cálculo legado"
  - "notasPorAtribuicao(): notas do user no mês por JOIN até nps_surveys.completed_at, deduped 1× por (resposta, papel)"
  - "notasLegado(): cruzamento read-time histórico preservado, com ->principal() e skip por (resposta, papel)"
  - "A nota do NPS Shopee entra no bônus/ranking de quem responde pelo Shopee"
  - "Suite tests/Feature/V16/BonusAtribuicoesNpsTest — Ajuste 3, Shopee, dedup e isolamento nos dois sentidos"
affects: [80-02 (bump de cache v3 + widgets), 80-03 (leitores de apresentação), consolidação mensal do bônus]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dual-path como união DISJUNTA por resposta (não por corte de data)"
    - "Fonte única do mês compartilhada entre os dois ramos (nps_surveys.completed_at)"
    - "Set de skip derivado dos registros já carregados, nunca de uma 2ª query"

key-files:
  created:
    - tests/Feature/V16/BonusAtribuicoesNpsTest.php
  modified:
    - app/Services/DesempenhoScoreService.php

key-decisions:
  - "Predicado do skip = DEC-80-B1 (autoritativo por PAPEL), não o literal '(user, resposta)' — o literal reabriria a super-atribuição no sentido inverso"
  - "->principal() PERMANECE no ramo legado — DEC-80-D vale só para o ramo das atribuições"
  - "Mês por JOIN em nps_surveys.completed_at — nunca assigned_at nem month_reference"
  - "Dedup via groupBy (nps_response_id, role) + MAX(average_score) — determinístico e ONLY_FULL_GROUP_BY-safe"
  - "Guard de carteira vazia desceu para notasLegado — user com atribuições e carteira vazia recebe a média das atribuições"
  - "Teste de dedup tornado DISCRIMINANTE com uma 2ª resposta (o exemplo literal do plano era matematicamente vacuoso)"

patterns-established:
  - "União disjunta: ramo (A) produz as notas E o set de skip do ramo (B), sem 2ª query"
  - "Fallback PERMANENTE documentado no código: ramo legado não é ponte temporária"

requirements-completed: [DEC-80-A, DEC-80-B0, DEC-80-B, DEC-80-D]

# Metrics
duration: 38min
completed: 2026-07-15
---

# Phase 80 Plan 01: DesempenhoScoreService lê atribuições por serviço — Summary

**`computeNpsMedio` virou união disjunta por resposta entre as atribuições congeladas da Fase 79 e o cálculo legado intacto — a nota do NPS Shopee (Gustavo 3.11 na Decoral) finalmente entra no bônus sem contaminar nenhum mês histórico e sem que ninguém receba nota de serviço alheio.**

## Performance

- **Duração:** ~38 min
- **Tarefas:** 2/2
- **Arquivos:** 1 criado (teste), 1 modificado (service)
- **Commits:** 3 (RED, GREEN, docs)

## Accomplishments

### Tarefa 1 — Testes RED (`tests/Feature/V16/BonusAtribuicoesNpsTest.php`)

4 testes gerando as atribuições pelo **fluxo real** (`POST /nps/{token}` → `NpsSnapshotService::registrar`), não por inserção na mão (80-RESEARCH Pitfall 6). `computeNpsMedio` invocado por reflection.

**Saída do RED registrada (todas falhas de VALOR, nenhum erro de setup/fatal):**

```
1) test_media_soma_atribuicoes_ml_e_shopee
   Failed asserting that 0.0 is identical to 3.0.
2) test_nps_shopee_entra_na_media_da_pessoa
   Failed asserting that 5.0 is identical to 2.0.
3) test_dedup_conta_uma_vez_por_resposta_e_role
   Failed asserting that 0.0 is identical to 4.0.
4) test_analista_shopee_nao_recebe_nota_ml_da_mesma_empresa
   Failed asserting that 5.0 is identical to 2.0.

Tests: 4, Assertions: 13, Failures: 4
```

Os diagnósticos bateram 1:1 com a previsão do plano:
- **0.0** nos testes 1 e 3 → o `->principal()` mata os modelos escopados; o legado não acha survey nenhum.
- **5.0 vs 2.0** nos testes 2 e 4 → **a super-atribuição real, reproduzida**: o analista de Shopee recebe hoje a nota do NPS de **ML** da mesma empresa. É exatamente o bug que a Fase 79 foi construída para impedir e que o DEC-80-B1 fecha.

### Tarefa 2 — Dual-path GREEN (`app/Services/DesempenhoScoreService.php`)

- `computeNpsMedio(User, Carbon): float` — assinatura e `0.0` literal preservados; merge dos dois ramos + `round(avg, 2)`.
- `notasPorAtribuicao()` — JOIN `nps_score_assignments → nps_responses → nps_surveys`, mês por `s.completed_at`, `groupBy(nps_response_id, role)` + `MAX(average_score)`. Sem filtro de `service_setor` (Ajuste 3) e sem refiltro pela carteira viva (congelamento). `selectRaw` só com nomes de coluna literais; todos os valores por bind.
- `notasLegado()` — cópia fiel de `:287-339` com **uma única adição** (o skip). `->principal()` preservado com um bloco `⚠⚠` no docblock explicando por que não pode ser "limpo". Guard de carteira vazia movido para cá.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Funcionalidade crítica ausente] Teste de dedup era matematicamente vacuoso**

- **Encontrado em:** Tarefa 1
- **Issue:** O plano especificava para o dedup "única resposta com 3.0 → resultado `3.0`, não a média inflada por peso duplo". **A média de `[3.0, 3.0]` é 3.0** — idêntica à de `[3.0]`. Com uma única resposta, o teste passaria **tanto com quanto sem** o dedup: não provaria nada, e o `groupBy` poderia ser removido no futuro sem quebrar nada.
- **Fix:** Adicionada uma **segunda resposta com nota diferente (5.0)** numa segunda empresa. Agora: deduped = `(3.0 + 5.0)/2 = 4.0`; sem dedup = `(3.0 + 3.0 + 5.0)/3 = 3.67`. O teste passou a discriminar de fato. Assert final `assertSame(4.0, ...)`.
- **Arquivo:** `tests/Feature/V16/BonusAtribuicoesNpsTest.php`
- **Commit:** `30f7b92`

**2. [Discrição do plano — fidelidade ao fluxo real] Par duplicado gerado naturalmente, não inserido na mão**

- **Encontrado em:** Tarefa 1
- **Contexto:** O plano autorizava `NpsScoreAssignment::create()` manual no teste de dedup ("aqui fabricar o par duplicado É o ponto"); o 80-RESEARCH Pitfall 6 diz que a inserção direta é "aceitável" — não obrigatória.
- **Escolha:** Gerar o par duplicado pelo **fluxo real**: um modelo que cobre `performance` **e** `shopee` numa empresa onde o mesmo user é o `consultor` dos dois serviços → o `NpsSnapshotService` cria naturalmente 2 linhas de `(resposta, role='consultor', user)` com `average_score` idêntico. Pré-condição explicitamente assertada no teste (`assertSame(2, ...count())`) e **verificada verde**.
- **Por quê:** prova o cenário real de ponta a ponta em vez de um fixture que a Fase 79 talvez nunca produzisse. Zero inserção manual em toda a suite.

**Nenhuma divergência de arredondamento observada.** Os pesos foram escolhidos para dividir exato (4/1, 2/1, 3/1, 5/1 → `decimal(5,2)` sem dízima), então `assertSame` estrito passou sem precisar de `assertEqualsWithDelta`. O risco do Pitfall 4 (legado sem `round` vs atribuição `decimal(5,2)`) permanece real para pesos com dízima — está documentado no RESEARCH e não foi exercitado aqui.

## Verification Results

| Verificação | Resultado |
|---|---|
| `--filter=BonusAtribuicoesNpsTest` | **4/4 verdes** (era 4/4 RED) |
| `test_fixture_carlos_retorna_nota_4_08_basico` | **VERDE** — nota_final 4.08 / faixa `basico` (âncora da diretoria) |
| `--filter=DesempenhoScoreServiceTest` | 14/14 verdes |
| `--filter=Desempenho` | 56 testes, 1 falha — **idêntico ao baseline** (falha pré-existente e não relacionada, ver abaixo) |
| `--filter=Performance` | 37/37 verdes |
| `--filter=Nps` | 172/172 verdes (inclui a suite nova) |
| `--filter=AtribuicaoPorServico` | 8/8 verdes — escrita da Fase 79 intocada |
| `grep -c "principal()"` no service | **4** (≥ 1 — scope legado preservado, `:436`) |
| `git diff --stat` | só `DesempenhoScoreService.php` + o teste novo; nenhum arquivo da Fase 79 alterado |

## Deferred Issues

**Falha pré-existente fora do escopo** (registrada em `deferred-items.md`):
`Tests\Feature\PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403 ≠ 200). É teste de rota/permissão do módulo de **publicação**, capturado pelo `--filter=Desempenho` só por coincidência de nome de classe. **Confirmada falhando em `HEAD 47f38d5` com a árvore limpa, antes de qualquer edição minha.** Não corrigida (SCOPE BOUNDARY).

## Known Stubs

Nenhum. O ramo legado **não é stub nem código temporário** — é fallback **permanente** (empresas sem contrato performance ativo ficaram com `company_users.servico_id = NULL` no backfill → nunca geram atribuição → sempre caem nele). Documentado no docblock de `computeNpsMedio` para que ninguém o trate como dívida a remover.

## Threat Flags

Nenhuma superfície de segurança nova. Mitigações do `<threat_model>` aplicadas e cobertas por teste:

| Threat ID | Mitigação aplicada | Prova |
|---|---|---|
| T-80-01 | `->principal()` preservado + skip por (resposta, papel) | `test_analista_shopee_nao_recebe_nota_ml_da_mesma_empresa` (dois sentidos) |
| T-80-02 | `groupBy` (nps_response_id, role) | `test_dedup_conta_uma_vez_por_resposta_e_role` (discriminante) |
| T-80-03 | Set de skip derivado dos surveys já carregados, nunca de 2ª query | união disjunta por construção |
| T-80-04 | Mês por JOIN em `nps_surveys.completed_at` | fonte única compartilhada com o legado |
| T-80-05 | `selectRaw` só com nomes de coluna literais, zero interpolação | binds via query builder |
| T-80-SC | Nenhuma dependência instalada | `composer.json`/`package.json` intocados |

## Notas para o próximo plano

- **DEC-80-C (bump de cache v2→v3) NÃO está neste plano** — `requirements` do 80-01 é `[DEC-80-A, DEC-80-B0, DEC-80-B, DEC-80-D]`; o bump pertence ao **80-02** (`[DEC-80-B, DEC-80-C, DEC-80-E]`). O cache segue em `v2` de propósito. **Bloqueante antes do deploy:** sem o bump, prod serve a nota antiga (sem Shopee) do Redis por até 7 dias no mês fechado.
- **Backend-only:** nenhum `npm run build`, nenhum deploy executado.
- **Dev em paralelo (anunciar-ml):** reconciliar antes do deploy (memória `feedback_perguntar_antes_deploy_v9`).
- Ao fim da fase, atualizar a memória `project_nps_modelo_principal` com a nuance: "só o principal conta" segue valendo no ramo legado, mas foi superada no ramo das atribuições.

## Self-Check: PASSED

- `tests/Feature/V16/BonusAtribuicoesNpsTest.php` — FOUND
- `app/Services/DesempenhoScoreService.php` (`notasPorAtribuicao` + `notasLegado`) — FOUND
- Commit `30f7b92` (test RED) — FOUND
- Commit `3de84e1` (feat GREEN) — FOUND
- Gate TDD: `test(...)` → `feat(...)` na ordem correta — CONFIRMADO
