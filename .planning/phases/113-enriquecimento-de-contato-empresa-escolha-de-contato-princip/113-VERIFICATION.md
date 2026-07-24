---
phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
verified: 2026-07-24T17:45:13Z
status: passed
score: 5/5 must-haves verificados
overrides_applied: 0
---

# Phase 113: Enriquecimento de contato/empresa + escolha de contato principal + dedup — Relatório de Verificação

**Phase Goal:** O webhook deixa de pegar só o primeiro contato: busca todos os contatos associados, escolhe o principal de forma determinística e grava nome/cargo/telefone/email estruturados (não só em `notes`). Antes de criar `Company`, procura empresa existente (hubspot_company_id → cnpj → email → domain → nome normalizado) e enriquece em vez de duplicar; match fraco vira warning/pendência, não merge agressivo. Snapshot guarda todos os contatos.

**Verificado em:** 2026-07-24
**Status:** PASSED
**Re-verificação:** Não — verificação inicial

## Veredito

**PASSED.** Os 5 Success Criteria do ROADMAP.md (Phase 113) estão implementados no código real, cobertos por testes automatizados que efetivamente exercitam o comportamento (não apenas existência de arquivo), e a suíte completa de regressão HubSpot (Phase34/35/111/112/113) roda 100% verde — 100 testes, 401 asserções, sem nenhuma asserção alterada nas suítes pré-existentes.

## Metodologia

Verificação goal-backward: cada SC foi lido a partir do ROADMAP.md, mapeado para os arquivos-fonte declarados no CONTEXT.md, o código foi lido linha a linha (não o SUMMARY.md), e a suíte de testes foi executada localmente (PHP 8.2 via XAMPP) para confirmar comportamento observável, não apenas presença de classe/método.

```
php artisan test --filter=Hubspot          → 84 passed (383 assertions)
php artisan test tests/Unit/Phase113*.php  → 16 passed (18 assertions)
Total: 100 passed, 401 assertions, 0 failures
```

Confirmado via `git log -1 -- <arquivo>` que as 4 suítes de regressão obrigatórias (`Phase34HubspotWebhookTest`, `Phase35HubspotV2Test`, `Phase112HandoffServiceTest`, `Phase112HubspotHandoffWebhookTest`) não foram tocadas por nenhum commit da Fase 113 (últimos commits nesses arquivos são de antes do início da fase 113: 12/06, 17/06 e dois de 24/07 anteriores ao primeiro commit RED da 113-01) — ou seja, o INVARIANTE de não-regressão foi respeitado sem alterar asserções existentes.

## Goal Achievement

### Observable Truths (Success Criteria do ROADMAP)

| # | Truth (SC do ROADMAP) | Status | Evidência |
|---|------|--------|-----------|
| 1 | Deal com vários contatos escolhe o principal por prioridade (email+telefone → email → telefone/mobilephone → primeiro); regra isolada e testada | ✓ VERIFICADO | `app/Services/Hubspot/HubspotContactSelector.php:34-119` — classe pura (sem I/O), tiers 3/2/1/0 + desempate determinístico por menor id. `tests/Unit/Phase113ContactSelectorTest.php` (9 testes, incluindo determinismo com lista embaralhada) — 9/9 verde. Consumida em `HubspotWebhookController::processar()` linha 216 via fetch batch (`fetchAssociatedContactIds`+`fetchContacts`, linhas 195-215), substituindo o fetch singular da Fase 35. `Phase113HubspotEnrichmentTest::test_contato_principal_escolhido_entre_3...` prova E2E que entre 3 contatos reais mockados via `Http::fake` o tier 3 (email+mobilephone) vence. |
| 2 | `companies.nome_contato`/`cargo_contato` gravados estruturados; `email_cliente`/`telefone` seguem company e caem pro contato principal (incl. `mobilephone`) quando a company não tem; `notes` deixa de ser fonte única | ✓ VERIFICADO | `HubspotWebhookController::criarEmpresa()` linhas 311-397 — `$nomeContato`/`$cargoContatoRaw` extraídos do contato principal; fallback `$foneFinal` = Company > contato.phone > contato.mobilephone (linha 324-326); `Company::create()` grava `nome_contato`, `cargo_contato`, `hubspot_deal_id`, `hubspot_company_id`, `hubspot_contact_id`, `hubspot_domain`, `hubspot_observacao` (linhas 390-396); linha `notes` legada preservada linhas 399-408. `Company::$fillable` (`app/Models/Company.php:44-48`) inclui todos os campos novos. Teste E2E `test_contato_principal_escolhido_entre_3_e_campos_estruturados_gravados` assere `nome_contato='Ana Costa'`, `cargo_contato='Gerente Comercial'`, `telefone='11977776666'` (mobilephone, pois company.phone=''), e `notes` contendo a linha legada — tudo simultâneo. |
| 3 | Match forte (`hubspot_company_id`/`cnpj`) enriquece campos VAZIOS sem sobrescrever preenchidos manualmente; contrato novo só se `hubspot_line_item_id` inédito — sem duplicar empresa/contrato | ✓ VERIFICADO | `HubspotCompanyMatcher::encontrar()` (`app/Services/Hubspot/HubspotCompanyMatcher.php:35-99`) resolve precedência hubspot_company_id→cnpj→email→domain→nome ANTES de qualquer escrita (chamado em `criarEmpresa` linha 342-348). Match forte → `enriquecerEmpresaExistente()` (linhas 499-550): loop `foreach ($candidatos as $campo => $valorNovo)` só grava se `$valorAtual` for null/''(linhas 533-543) — nunca sobrescreve. Guard de contrato duplicado em `persistirContratos()` linhas 576-600 (`hubspot_line_item_id` ou `servico_id` ativo). Teste E2E `test_match_forte_por_cnpj_enriquece_sem_duplicar` prova: `Company::count()===1`, campo manual `dor` intocado, campo vazio `email_cliente`/`telefone`/`nicho` enriquecidos, `empresa_nova` permanece `false`. Teste `test_match_forte_por_hubspot_company_id_guard_contrato_line_item_inedito` prova que line item já existente (`21001`) não duplica contrato e o inédito (`21002`) cria — `ContratoServico::where('company_id',...)->count()===2`. |
| 4 | Match fraco só por nome normalizado não faz merge automático de campos críticos; gera warning `possivel_duplicidade` no `hubspot_eventos.payload` | ✓ VERIFICADO | `HubspotNameNormalizer::normalizar()` (`app/Services/Hubspot/HubspotNameNormalizer.php`) — normalização conservadora (caixa/acento/pontuação/espaço) que preserva tokens (`ltda`/`me`) para evitar falso positivo; testado contra os 2 casos âncora ("Padaria do Zé" vs "Padaria da Ana"; "Silva Ltda" vs "Silva Ltda ME") em `tests/Unit/Phase113NameNormalizerTest.php` (7/7 verde). No controller, match fraco (linhas 437-467) NÃO chama `enriquecerEmpresaExistente` (cai no `else` de criação normal) e grava `payload['possivel_duplicidade']` no `HubspotEvento` + `warnings[]` no snapshot da empresa nova. Teste E2E `test_match_fraco_por_nome_cria_empresa_nova_e_grava_warning` prova: `Company::count()===2` (não bloqueia criação), candidata original intocada (`cnpj`/`email_cliente` inalterados), `payload['possivel_duplicidade']['via']==='nome'`, warning também presente no `hubspot_snapshot['warnings']` da empresa nova. |
| 5 | `companies.hubspot_snapshot` guarda deal/company/TODOS os contatos/line_items normalizados; regressão zero na suite | ✓ VERIFICADO | `criarEmpresa()` linhas 475-485 — `$company->update(['hubspot_snapshot' => [...]])` com chaves `deal`, `company`, `contacts` (lista completa, não só o principal), `primary_contact_id`, `line_items`, `warnings`, `captured_at`. `Company::$casts['hubspot_snapshot']='array'` (linha 68). Teste `test_snapshot_completo_contem_todos_os_contatos_e_metadados` assere as 7 chaves presentes e `count($snapshot['contacts'])===3` (todos os 3 contatos mockados, não só o escolhido). Regressão: suíte HubSpot completa (`--filter=Hubspot`) = 84/84 verde incluindo as 4 suítes pré-existentes (Phase34/35/111/112) SEM nenhuma asserção alterada (confirmado via `git log` — arquivos intocados desde antes da Fase 113). |

**Score:** 5/5 truths verificadas

### Required Artifacts

| Artefato | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `app/Services/Hubspot/HubspotContactSelector.php` | Regra pura de contato principal, testável | ✓ VERIFICADO | 119 linhas, sem I/O (grep negativo confere), 9 testes unitários verdes |
| `app/Services/Hubspot/HubspotNameNormalizer.php` | Normalização anti-falso-positivo | ✓ VERIFICADO | 63 linhas, sem I/O, 7 testes unitários verdes (2 casos âncora anti-falso-positivo) |
| `app/Services/Hubspot/HubspotCompanyMatcher.php` | Resolução de empresa existente por precedência | ✓ VERIFICADO | 100 linhas, 10 testes unit-style verdes cobrindo precedência/anti-falso-positivo/critérios vazios |
| `app/Http/Controllers/Api/HubspotWebhookController.php` | Fetch batch + campos estruturados + dedup + snapshot | ✓ VERIFICADO/WIRED | `processar()` usa `fetchAssociatedContactIds`+`fetchContacts` (batch); `criarEmpresa()` bifurca forte/sem-forte; `enriquecerEmpresaExistente()` e guard de contrato implementados; snapshot sempre reescrito |
| `app/Services/Hubspot/HubspotDealHandoffService.php` + `HubspotHandoffData.php` | DTO `company_data`/`contact_data` preenchidos | ✓ VERIFICADO/WIRED | `build()` ganha 4 parâmetros opcionais (linhas 48-56); `company_data`/`contact_data` normalizados (linhas 116-140); chamadas antigas de 3 args (Phase112HandoffServiceTest) continuam idênticas — confirmado pelos testes 112 passando sem alteração |
| `app/Models/Company.php` | Fillable/casts para campos novos | ✓ VERIFICADO | `$fillable` linhas 46-48 inclui os 8 campos; `$casts['hubspot_snapshot']='array'` linha 68 |

### Key Link Verification

| De | Para | Via | Status | Detalhes |
|----|------|-----|--------|----------|
| `HubspotWebhookController::processar()` | `HubspotContactSelector::selecionar()` | chamada direta linha 216 sobre lista batch normalizada | WIRED | Contatos vêm de `fetchAssociatedContactIds`+`fetchContacts` (batch real, não mais singular) |
| `HubspotWebhookController::criarEmpresa()` | `HubspotCompanyMatcher::encontrar()` | `app(HubspotCompanyMatcher::class)->encontrar(...)` linha 342, ANTES de qualquer `Company::create`/`update` | WIRED | Resolução de dedup sempre roda primeiro — confirmado por teste e por leitura do fluxo |
| `criarEmpresa()` (match fraco) | `HubspotNameNormalizer::normalizar()` | usado dentro do Matcher (critério 5) e novamente na gravação do warning (linha 446) | WIRED | — |
| `criarEmpresa()` | `HubspotDealHandoffService::build()` | chamada linha 417-425 com 7 args (contatos/contatoPrincipal/propsCompany inclusos) | WIRED | `company_data`/`contact_data` deixam de ser sempre-null quando há company/contatos |
| `criarEmpresa()` | `Company::update(['hubspot_snapshot' => ...])` | linha 475-485, dentro da mesma `DB::transaction` | WIRED | Sempre executado, independente do resultado do match |

### Requirements Coverage

| Requirement | Plano de origem | Descrição | Status | Evidência |
|---|---|---|---|---|
| HUB-CONTATO-01 | 113-01, 113-02 | Todos os contatos + principal determinístico | ✓ SATISFEITO | `HubspotContactSelector` + fetch batch no controller |
| HUB-CONTATO-02 | 113-02 | Campos estruturados | ✓ SATISFEITO | `nome_contato`/`cargo_contato`/IDs HubSpot gravados |
| HUB-DEDUP-01 | 113-03 | Match forte enriquece | ✓ SATISFEITO | `enriquecerEmpresaExistente()` + guard de contrato |
| HUB-DEDUP-02 | 113-01, 113-03 | Match fraco = warning/pendência | ✓ SATISFEITO | `HubspotNameNormalizer` + warning `possivel_duplicidade` |
| HUB-DEDUP-03 | 113-02 | Snapshot completo | ✓ SATISFEITO | `hubspot_snapshot` com deal/company/contacts/line_items |

Nenhum requirement órfão detectado — os 5 requirements do ROADMAP para a Fase 113 aparecem no `requirements-completed` dos 3 planos (113-01: HUB-CONTATO-01+HUB-DEDUP-02; 113-02: HUB-CONTATO-01+HUB-CONTATO-02+HUB-DEDUP-03; 113-03: HUB-DEDUP-01+HUB-DEDUP-02).

### Anti-Patterns Found

Nenhum marcador de débito (`TODO`/`FIXME`/`TBD`/`XXX`/`HACK`/`PLACEHOLDER`) encontrado nos arquivos modificados/criados pela Fase 113. As únicas ocorrências de "TODO" na busca eram falsos positivos (a palavra "TODOS" em comentários pt-BR).

Nenhuma implementação vazia (`return null`/`=> {}`), nenhum handler stub, nenhum dado hardcoded vazio detectado nos arquivos-chave lidos.

### Escopo — verificação negativa (vazamento da Fase 114)

Confirmado que a Fase 113 NÃO antecipou trabalho da Fase 114:
- Nenhum comando `hubspot:reprocess-event` existe em `app/Console/Commands/` (só `HubspotInspectProperties.php`, pré-existente).
- Nenhuma pendência `possivel_duplicidade`/`sem_contato`/`valor_revisar` (origem HubSpot) renderizada em `resources/js/` — a única ocorrência de `sem_contato` encontrada é pré-existente e não relacionada (módulo Shopee).

### Observação (não-bloqueante)

O `HubspotCompanyMatcher` classifica como **match fraco** não só o critério "nome normalizado" (redação literal do SC4), mas também `email_cliente` e `hubspot_domain` — uma extensão consciente e documentada em código (`HubspotCompanyMatcher.php:12-27`) e coberta por testes dedicados (`test_match_por_email_e_fraco`, `test_match_por_domain_e_fraco`). O CONTEXT.md já listava esses 2 critérios na mesma ordem de precedência sem classificá-los explicitamente como forte, e o comportamento observável (não faz merge agressivo, cria empresa nova + warning) é idêntico ao do match por nome — portanto não constitui um desvio de intenção, apenas uma cobertura mais ampla do mesmo mecanismo de proteção. Não bloqueia o veredito.

### Human Verification Required

Nenhum item — todos os 5 Success Criteria são verificáveis via código + teste automatizado (regra de negócio pura, sem dependência de UI/aparência visual/comportamento em tempo real).

### Gaps Summary

Nenhum gap. Os 5 Success Criteria da Fase 113 estão implementados, testados e verificados diretamente no código-fonte (não apenas no SUMMARY.md). A suíte de regressão obrigatória (Phase34/Phase35/Phase112) permanece 100% verde, sem nenhuma asserção alterada, confirmando o INVARIANTE de não-regressão do CONTEXT.md. Escopo respeitado — nenhum artefato da Fase 114 (UI, pendências renderizadas, comando de replay) foi antecipado.

---

*Verificado: 2026-07-24T17:45:13Z*
*Verificador: Claude (gsd-verifier)*
