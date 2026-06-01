---
phase: 17
slug: coleta-de-dados-ml-intelig-ncia-de-an-ncios-do-mercado-livre
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-01
---

# Phase 17 — Estratégia de Validação

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `17-RESEARCH.md` › Validation Architecture.

---

## Infraestrutura de Testes

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config** | `phpunit.xml` (raiz do projeto) |
| **Comando rápido** | `php artisan test --filter Phase17` |
| **Suite completa** | `php artisan test` |
| **Runtime estimado** | ~20–40 segundos |

---

## Taxa de Amostragem

- **Após cada commit de task:** `php artisan test --filter Phase17`
- **Após cada wave de plano:** `php artisan test`
- **Antes de `/gsd:verify-work`:** Suite completa verde
- **Latência máxima de feedback:** ~40 segundos

---

## Mapa de Verificação por Comportamento

> IDs de task (`{N}-PP-TT`) serão atribuídos pelo planner. Cada comportamento abaixo deve
> mapear para ≥1 task com `<automated>` verify ou ser coberto por Wave 0.

| Req derivado | Comportamento | Tipo | Comando | Arquivo (Wave 0) |
|-------------|---------------|------|---------|------------------|
| D-04-a | `normalizar("Fone Estéreo")` → `"fone estereo"` (lowercase + sem acentos) | unit | `php artisan test --filter MlKeywordMinerTest::test_normaliza_token` | `tests/Unit/MlKeywordMinerTest.php` |
| D-04-b | Stopwords pt-BR filtradas dos tokens | unit | `php artisan test --filter MlKeywordMinerTest::test_stopwords_filtradas` | `tests/Unit/MlKeywordMinerTest.php` |
| D-04-c | Unigramas/bigramas/trigramas ranqueados por frequência | unit | `php artisan test --filter MlKeywordMinerTest::test_ranking_keywords` | `tests/Unit/MlKeywordMinerTest.php` |
| D-01 | App token cacheado: 2ª chamada não faz nova request HTTP | unit (mock Http) | `php artisan test --filter MlColetaServiceTest::test_app_token_cacheado` | `tests/Unit/MlColetaServiceTest.php` |
| D-02 (fallback) | `questions` indisponível (mock 401) → pipeline continua sem abortar | unit | `php artisan test --filter MlColetaServiceTest::test_pipeline_sem_questions` | `tests/Unit/MlColetaServiceTest.php` |
| D-03 | 429 durante coleta: backoff respeitado, falha de 1 item não aborta lote | unit (mock Http) | `php artisan test --filter MlColetaServiceTest::test_429_degradacao_graciosa` | `tests/Unit/MlColetaServiceTest.php` |
| D-06 | `MlbColetaJob::failed()` atualiza `status=erro` + `erro_mensagem` | unit | `php artisan test --filter MlbColetaJobTest::test_failed_marca_erro` | `tests/Unit/MlbColetaJobTest.php` |
| D-06 | `POST /mlb/coleta` cria registro `status=pendente` + redirect | feature | `php artisan test --filter Phase17ColetaTest::test_store_cria_coleta_pendente` | `tests/Feature/Phase17ColetaTest.php` |
| D-06 | `GET /mlb/coleta/{id}/status` retorna JSON com status | feature | `php artisan test --filter Phase17ColetaTest::test_status_endpoint_json` | `tests/Feature/Phase17ColetaTest.php` |
| D-07 | Usuário sem `publication_role` recebe 403 em `/mlb/coleta` | feature | `php artisan test --filter Phase17ColetaTest::test_acesso_403_sem_pub_role` | `tests/Feature/Phase17ColetaTest.php` |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Requisitos Wave 0

- [ ] `tests/Unit/MlKeywordMinerTest.php` — stubs para D-04-a/b/c (mineração estatística pt-BR)
- [ ] `tests/Unit/MlColetaServiceTest.php` — stubs para D-01 (cache token), D-02 (fallback questions), D-03 (429/backoff)
- [ ] `tests/Unit/MlbColetaJobTest.php` — stub para D-06 (failed hook)
- [ ] `tests/Feature/Phase17ColetaTest.php` — stubs para D-06 (store+status), D-07 (403 sem permissão)

*Framework PHPUnit já instalado — apenas novos arquivos de teste necessários.*

---

## Verificações Apenas Manuais

| Comportamento | Req | Por que manual | Instruções |
|----------|-------------|------------|-------------------|
| Acesso real a `/questions/search` e `/items/{id}` de terceiros com app token (D-02 em aberto) | D-02 | Depende de resposta da API ML em produção; não simulável de forma fiel em teste | No 1º run real do Job em staging/produção, conferir se `questions_disponivel=true` no resultado; se 401/403, validar que o fallback (só title mining) entrega ranking sem quebrar |
| Render visual da página de resultado (ranking, top dúvidas, recomendação) no design `ecf-*` | D-07 | Validação visual de UI | Abrir `/mlb/coleta`, disparar coleta, conferir progresso e relatório no tema dark |

---

## Sign-Off de Validação

- [ ] Todas as tasks têm `<automated>` verify ou dependência Wave 0
- [ ] Continuidade de amostragem: sem 3 tasks consecutivas sem automated verify
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Sem flags de watch-mode
- [ ] Latência de feedback < 40s
- [ ] `nyquist_compliant: true` no frontmatter

**Aprovação:** pending
