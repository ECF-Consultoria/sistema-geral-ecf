---
phase: 115-suite-e2e-documenta-o-da-regra-de-valor-v20-0
verified: 2026-07-25T00:00:00Z
status: passed
score: 6/6 must-haves verificados
overrides_applied: 0
---

# Phase 115: Suite E2E + documentação da regra de valor Verification Report

**Phase Goal:** Cobertura de teste dos fluxos novos (valor, enriquecimento, dedup, replay, listagem) com `Http::fake` SEMPRE, e doc curta explicando a regra mensal×anual em CLAUDE.md ou novo doc técnico. Fecha os critérios de aceite do prompt.
**Verified:** 2026-07-25
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | `HubspotValueResolverTest` cobre os 6 casos-âncora (monthly, annually P1Y, MRR×ARR, serviço único, tolerância, valor_revisar) | VERIFIED | 9 métodos no arquivo mapeando 1:1 os 6 casos + variantes (paridade qty>1, tolerância dentro/fora de 5%). Executado ao vivo: `php artisan test --filter=HubspotValueResolverTest` → 9/9 passed, 34 assertions. Docblock de rastreabilidade SC→método presente (linhas 28-35 do arquivo) |
| 2 | Enriquecimento prova contato email+telefone, mobilephone fallback, nome_contato estruturado, IDs HubSpot, snapshot deal/company/contact/line_items | VERIFIED | `Phase113HubspotEnrichmentTest::test_contato_principal_escolhido_entre_3_e_campos_estruturados_gravados` assere `nome_contato='Ana Costa'`, `telefone='11977776666'` (fallback mobilephone, company sem phone), `hubspot_contact_id/deal_id/company_id/domain`. `test_snapshot_completo_contem_todos_os_contatos_e_metadados` assere as 7 chaves do snapshot + `assertCount(3, contacts)`. Executado ao vivo: 3/3 passed, 31 assertions |
| 3 | Dedup prova CNPJ enriquece sem duplicar, hubspot_company_id sem duplicar contrato, match fraco por nome → warning sem merge | VERIFIED | `test_match_forte_por_cnpj_enriquece_sem_duplicar` (Company::count()===1, campo manual `dor` intocado, campos vazios enriquecidos), `test_match_forte_por_hubspot_company_id_guard_contrato_line_item_inedito` (só line item 21002 inédito vira contrato, 21001 permanece 1x), `test_match_fraco_por_nome_cria_empresa_nova_e_grava_warning` (2 companies, candidata intocada, warning `possivel_duplicidade` no payload E no snapshot). Executado ao vivo: 14/14 passed, 63 assertions |
| 4 | Replay prova: line item sem mapping não cria contrato → mapping cadastrado → replay cria o contrato e zera o efeito prático da pendência | VERIFIED | `test_replay_cria_contrato_faltante_apos_mapping_cadastrado`: contrato 0→1 após replay, e assert reforçado (adicionado nesta fase) que `line_items_nao_mapeados` deixa de conter "Serviço X" após materialização — prova o efeito prático, não só a existência do contrato. `test_replay_e_idempotente_rodando_2x` confirma zero duplicação em 2 execuções. Bug real corrigido no controller (`persistirContratos()` agora dá `unset` na chave quando `$naoMapeados` fica vazio). Executado ao vivo: 3/3 passed, 21 assertions |
| 5 | Listagem prova contato/observação/confiança/warning por linha e valor_revisar só para origem HubSpot | VERIFIED | `test_payload_expoe_campos_de_contato_e_ids_hubspot`, `test_contrato_expoe_bloco_de_valor_hubspot` (confidence/warning/billing_frequency), `test_empresa_legada_NAO_recebe_nenhuma_pendencia_nova` prova o gate crítico: empresa sem `HubspotEvento` associado recebe `pendencias_comerciais=[]` mesmo com `nome_contato=null` + contrato `confidence=low` + snapshot com warning — o guard `ComercialController::calcularPendenciasComerciais()` linha 472 (`if (!$c->is_origem_hubspot) return []`) confirma a implementação. Executado ao vivo: 18/18 passed, 76 assertions |
| 6 | Nenhum teste chama HubSpot real; tokens nunca em log; doc da regra escrita | VERIFIED | `Phase115HubspotInvariantesTest` (suíte nova desta fase): `test_nenhuma_chamada_hubspot_real_no_processamento_do_webhook` usa `Http::preventStrayRequests()` cobrindo webhook E replay sob a mesma guarda; `test_tokens_e_segredos_nunca_aparecem_no_log_ecf_webhooks` injeta `Monolog\Handler\TestHandler` no canal `ecf-webhooks` e assere ausência de `access_token`/`client_secret` em todos os registros (não vácuo — `assertNotEmpty($registros)`). Executado ao vivo: 2/2 passed, 17 assertions. Doc `docs/hubspot-regra-de-valor.md` existe com 7 subseções (`## `), fiel ao `HubspotValueResolver.php` (nenhuma regra inventada — confrontado linha a linha), cobre P1Y/MRR/ARR/tolerância 5%/valor_revisar/mapa de auditoria das colunas `hubspot_valor_*` |

**Score:** 6/6 truths verificadas

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `tests/Unit/HubspotValueResolverTest.php` | 6 casos-âncora + docblock rastreabilidade | VERIFIED | 9 métodos, docblock SC1-6→método presente, 9/9 verde |
| `tests/Feature/Phase113HubspotEnrichmentTest.php` | Enriquecimento + snapshot | VERIFIED | 3 métodos, docblock SC2→método presente, 3/3 verde |
| `tests/Feature/Phase113HubspotDedupTest.php` | Dedup forte/fraco | VERIFIED | 14 métodos (10 unit-style matcher + 4 E2E), docblock SC3→método presente, 14/14 verde |
| `tests/Feature/Phase114HubspotReplayTest.php` | Replay efeito prático + idempotência | VERIFIED | 3 métodos, assert reforçado de limpeza de `line_items_nao_mapeados`, 3/3 verde |
| `tests/Feature/Phase114ComercialListagemEnrichmentTest.php` | Listagem enriquecida + gate origem HubSpot | VERIFIED | 18 métodos, docblock SC5(listagem)→método presente, 18/18 verde |
| `tests/Feature/Phase115HubspotInvariantesTest.php` | Invariantes transversais (novo) | VERIFIED | Arquivo criado nesta fase, 2 métodos (`preventStrayRequests` + `TestHandler`), min_lines 60 satisfeito (259 linhas), 2/2 verde |
| `docs/hubspot-regra-de-valor.md` | Doc da regra mensal×anual | VERIFIED | 125 linhas, 7 subseções `## `, contém `valor_revisar`/`P1Y`/`MRR`/`hubspot_valor_original`, fiel ao resolver (comparado linha a linha) |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `HubspotValueResolverTest` | `HubspotValueResolver::resolve()` | chamada direta em memória | WIRED | Todos os 9 testes chamam `$this->resolver()->resolve($servico, $lineItem, $dealProps)` |
| `Phase113HubspotEnrichmentTest` | `HubspotWebhookController` | webhook POST + `Http::fake` | WIRED | `disparaWebhook()` + HMAC v3 válido, todas as chamadas HubSpot mockadas via `Http::fake($fakes)` |
| `Phase115HubspotInvariantesTest` | `Illuminate\Support\Facades\Http` | `Http::preventStrayRequests()` antes do fake | WIRED | Confirmado no código (linha 177) e por execução ao vivo — sem `StrayRequestException` |
| `Phase115HubspotInvariantesTest` | canal `ecf-webhooks` | `Monolog\Handler\TestHandler` | WIRED | `Log::channel('ecf-webhooks')->getLogger()->pushHandler($handler)` confirmado + `assertNotEmpty` nos registros |
| `docs/hubspot-regra-de-valor.md` | `HubspotValueResolver.php` | descrição fiel do shape de 8 chaves | WIRED | Cada ramo do doc (monthly/annually/mrr/único/tolerância/valor_revisar) corresponde 1:1 ao código-fonte lido linha a linha |

### Behavioral Spot-Checks (execução ao vivo, não confiando no SUMMARY)

| Suite | Comando | Resultado | Status |
|---|---|---|---|
| HubspotValueResolverTest | `php artisan test --filter=HubspotValueResolverTest` | 9 passed, 34 assertions | PASS |
| Phase113HubspotEnrichmentTest | `php artisan test --filter=Phase113HubspotEnrichmentTest` | 3 passed, 31 assertions | PASS |
| Phase113HubspotDedupTest | `php artisan test --filter=Phase113HubspotDedupTest` | 14 passed, 63 assertions | PASS |
| Phase114HubspotReplayTest | `php artisan test --filter=Phase114HubspotReplayTest` | 3 passed, 21 assertions | PASS |
| Phase114ComercialListagemEnrichmentTest | `php artisan test --filter=Phase114ComercialListagemEnrichmentTest` | 18 passed, 76 assertions | PASS |
| Phase115HubspotInvariantesTest | `php artisan test --filter=Phase115HubspotInvariantesTest` | 2 passed, 17 assertions | PASS |

**Total confirmado ao vivo nesta verificação: 49/49 testes verdes, 242 assertions**, cobrindo integralmente as 6 suítes que compõem os critérios de aceite da Fase 115. Não foi rodado o `--filter=Hubspot` completo (>7 min, nota de contexto do executor) — as 6 suítes-alvo da fase foram todas confirmadas individualmente, o que é o escopo exato desta fase de auditoria (não uma regressão ampla de toda a milestone).

### Anti-Patterns Found

Nenhum bloqueador. Grep por `TODO|FIXME|HACK|XXX|TBD|PLACEHOLDER` nos arquivos modificados retornou apenas falsos-positivos (substring "TODOS" em português dentro de comentários, não a marca de dívida técnica "TODO").

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| — | — | Nenhum anti-pattern real encontrado | — | — |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| HUB-TEST-01 | 115-01 | Cobertura HubspotValueResolver (6 casos) | SATISFIED | 9/9 verde + docblock |
| HUB-TEST-02 | 115-01 | Cobertura enriquecimento | SATISFIED | 3/3 verde + docblock |
| HUB-TEST-03 | 115-01 | Cobertura dedup | SATISFIED | 14/14 verde + docblock |
| HUB-TEST-04 | 115-02 | Cobertura replay | SATISFIED | 3/3 verde + assert reforçado + bug corrigido |
| HUB-TEST-05 | 115-02 | Cobertura listagem + invariantes transversais | SATISFIED | 18/18 (listagem) + 2/2 (invariantes) verdes |
| HUB-DOC-01 | 115-03 | Doc técnico da regra de valor | SATISFIED | `docs/hubspot-regra-de-valor.md`, 7 subseções, fiel ao código |

**Nota:** os IDs HUB-* não constam em `.planning/REQUIREMENTS.md` (que rastreia apenas a milestone v17.0). Isso é dívida de processo pré-existente do projeto, não um gap desta fase — o ROADMAP.md é a fonte de verdade da milestone v20.0 e cobre os 6 IDs listados acima. Nenhum requirement órfão específico desta fase foi encontrado além dessa lacuna de rastreamento já documentada nas notas de contexto da tarefa.

### Human Verification Required

Nenhum item. Esta fase é 100% teste automatizado + documentação — sem mudança de UI/UX que exija checkpoint visual humano. Nenhum bloco `<human-check>` foi encontrado nos 3 PLAN.md da fase.

### Gaps Summary

Nenhum gap encontrado. Todos os 6 critérios de aceite do ROADMAP para a Fase 115 estão implementados, rastreados (docblocks SC→método) e comprovados por execução ao vivo dos testes nesta verificação (não apenas por citação do SUMMARY.md). O bug real corrigido durante a auditoria (`persistirContratos()` não limpava `line_items_nao_mapeados` pós-replay) foi verificado tanto no código-fonte quanto no teste que o exercita.

---

_Verified: 2026-07-25_
_Verifier: Claude (gsd-verifier)_
