---
phase: 134
slug: meus-anuncios-saude-analitica-do-anuncio-publicado
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-10
---

# Phase 134 — Validation Strategy

> Contrato de validação por fase, para amostragem de feedback durante a execução.
> Derivado da seção `## Validation Architecture` do `134-RESEARCH.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework (backend)** | PHPUnit 11.x com `RefreshDatabase` (SQLite in-memory) + `Http::fake()` — molde: `tests/Feature/Phase86/HistoricoAnunciosTest.php` |
| **Framework (lógica pura JS)** | `node --test` — molde: `tests/js/mlAnuncioRegras.test.js` |
| **Config file** | `phpunit.xml` (existente) — nenhum config novo necessário |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter=Phase134` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test` |
| **Estimated runtime** | ~20-40s no filtro; suíte completa é longa (ver ressalva abaixo) |

> **Ressalva registrada no aprendizado do projeto:** `artisan test` sem filtro pode **não concluir** (timeout de 300s em `MercadoLivreAdsService`). Durante o desenvolvimento, usar sempre `--filter`; reservar a suíte completa para o gate final da fase e tratar um timeout ali como conhecido, não como regressão desta fase.

---

## Sampling Rate

- **Após cada commit de task:** `C:\xampp\php\php.exe artisan test --filter=Phase134` + `node --test tests/js/*anuncio*`
- **Após cada wave:** suíte do módulo MLB (`--filter=Mlb`)
- **Antes de `/gsd:verify-work`:** suíte completa verde, respeitando a ressalva de timeout acima
- **Max feedback latency:** 60s

---

## Per-Task Verification Map

Mapa por decisão (fase avulsa — sem REQ-IDs de milestone; os IDs são as decisões travadas no `134-CONTEXT.md`). O planner preenche `Task ID` / `Plan` / `Wave` ao gerar os PLAN.md.

| Decisão | Comportamento a provar | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------------------------|------------|-----------------|-----------|-------------------|-------------|--------|
| D-01 | A tela lista itens que **não** vieram deste módulo (acervo inteiro da conta) | — | — | Feature | `--filter=test_lista_itens_fora_do_modulo` | ❌ W0 | ⬜ pending |
| D-02 / V4 | Escopo por empresa: usuário não vê item de outra empresa na listagem nem na triagem | T-134-01 | Toda query filtra por `company_id`; nunca só no frontend | Feature | `--filter=test_escopo_por_empresa` | ❌ W0 | ⬜ pending |
| D-04 | Selo de origem cobre os 3 casos: ECF · time · legado do cliente | — | — | Feature | `--filter=test_selo_origem` | ❌ W0 | ⬜ pending |
| D-05 | **Zero chamada HTTP síncrona ao ML** no request de leitura | — | — | Feature | `Http::fake()` + `assertNothingSent()` na rota GET | ❌ W0 | ⬜ pending |
| D-08 | Snapshot velho/ausente → selo de defasagem, **nunca** tela em branco | — | — | Feature | `--filter=test_degradacao_graciosa` | ❌ W0 | ⬜ pending |
| D-09 | Triagem agrupa por motivo e o clique filtra a lista | — | — | Feature | `--filter=test_triagem_por_motivo` | ❌ W0 | ⬜ pending |
| D-11 | Nenhum write na API do ML em todo o caminho da tela | T-134-03 | `Http::fake()` assere que nenhum POST/PUT/DELETE saiu para `api.mercadolibre.com` | Feature | `--filter=test_zero_write_ml` | ❌ W0 | ⬜ pending |
| D-12 | Ordenação por gravidade é determinística **sem** dados da camada cara | — | — | Unit | `--filter=test_ordenacao_gravidade` | ❌ W0 | ⬜ pending |
| D-14 | Sub-abas Publicados/Rascunhos; `publicarLote` (BULK-01) segue funcionando após migrar | — | — | Feature | reusa/estende os testes de BULK-01 | parcial | ⬜ pending |
| D-16 | "Saúde do anúncio" **permanece** no wizard; bloco de Rascunhos **saiu** | — | — | Estrutura (JS) | `node --test tests/js/estrutura-anunciar-ml.test.js` | ❌ W0 | ⬜ pending |
| D-17 | Item com variações grava agregado do pai + `has_variations` + JSON | — | — | Unit | teste do upsert com fixture de item com `variations[]` | ❌ W0 | ⬜ pending |
| D-18 | Item de catálogo não coberto pela rotação aparece como **"não avaliado"**, nunca com status inventado | — | — | Unit | `--filter=test_buybox_nao_avaliado` | ❌ W0 | ⬜ pending |
| D-20 | Varredura usa `scroll_id` e sobrevive a conta com >1000 itens | — | — | Unit | `Http::fake()` com 3 páginas de scroll | ❌ W0 | ⬜ pending |
| D-21 | Fallback: sem saúde do ML, a tela mostra ausência explícita — não um número simulado | — | — | Feature | `--filter=test_saude_ml_ausente` | ❌ W0 | ⬜ pending |
| D-22 | **Guarda de regressão da régua:** o mesmo fixture pontua igual no scorer JS e no PHP; nota em base 86, nunca renormalizada | — | — | Unit (fixture compartilhado) | `node --test` + `--filter=NotaEcfFecharComConta` | ❌ W0 | ⬜ pending |
| D-23 | Rotação por fatia cobre 100% do acervo em N execuções, sem item órfão | — | — | Unit | `--filter=test_rotacao_cobre_acervo` | ❌ W0 | ⬜ pending |
| V5 | "Atualizar agora" rejeita empresa não autorizada / sem token ML | T-134-02 | `abort_unless($company->mlToken !== null, 404)` + double-check de empresa antes de enfileirar | Feature | `--filter=test_atualizar_agora_autorizacao` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase134/MeusAnunciosTest.php` — cobre D-01, D-02, D-04, D-05, D-08, D-09, D-11, D-21, V5
- [ ] `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` — cobre D-12
- [ ] `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` — cobre D-22 (comparação PHP × JS sobre fixture compartilhado)
- [ ] `tests/Unit/Phase134/ColetaAcervoTest.php` — cobre D-17, D-18, D-20, D-23
- [ ] `tests/js/estrutura-anunciar-ml.test.js` — cobre D-16 (molde: `tests/js/estrutura-grade-glide.test.js`)
- [ ] **Fixtures de resposta real da API do ML** (multiget, scroll, `price_to_win`, item com variações) capturados na pesquisa — usar como corpo de `Http::fake()`. **Não inventar o shape do payload**: a pesquisa já provou que a documentação do ML mente em pelo menos um ponto (`/visits/items?ids=` promete lote de 20 e aceita 1).

---

## Manual-Only Verifications

| Behavior | Decisão | Why Manual | Test Instructions |
|----------|---------|------------|-------------------|
| Sondagem de `/item/{id}/performance` em item **clássico** real | D-21 | Depende de conta e item reais de produção; nenhum fixture pode provar se o endpoint responde ao app | Rodar leitura contra um item sem `user_product_id` numa conta com token ativo. Registrar o resultado no PLAN/SUMMARY. **Somente leitura** — D-11 |
| "Abro, escolho a empresa e em 5 segundos sei o que fazer" | Critério de aceite do usuário | Julgamento de produto, não asserção | UAT em `/gsd:verify-work`: abrir a tela numa empresa real com acervo grande e cronometrar até a primeira ação óbvia |
| Legibilidade da triagem e do gráfico da série | D-09 / D-07b | Visual | UAT com a tela renderizada; `/gsd:ui-phase` define o contrato antes |
| Coleta contra conta grande real (66k itens) sem estourar tempo/rate limit | D-19 / D-23 | Só se prova contra volume real; `Http::fake()` não mede tempo de rede | Rodar o comando com `--company=` na maior conta e medir duração e ocorrência de 429 |

---

## Validation Sign-Off

- [ ] Toda task tem verify `<automated>` ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: não existem 3 tasks consecutivas sem verify automatizado
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Nenhuma flag de watch mode
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` no frontmatter

**Approval:** pending
