---
phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
plan: 01
subsystem: api
tags: [hubspot, dedup, contato, php, tdd]

# Dependency graph
requires:
  - phase: 112-nucleo-de-valor-e-handoff-de-contratos-hubspot
    provides: "HubspotDealHandoffService/HubspotHandoffData (padrão de classe fina em App\\Services\\Hubspot, comentários pt-BR)"
provides:
  - "HubspotContactSelector::selecionar(array $contatos): ?array — regra determinística de contato principal (tiers de prioridade + desempate por menor id)"
  - "HubspotNameNormalizer::normalizar(?string $nome): string — normalização anti-falso-positivo para match fraco de dedup"
affects: [113-02-controller-dedup-enrich, 113-03-dedup-nome-normalizado]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Unidade pura em App\\Services\\Hubspot\\ sem I/O (sem DB/config/HTTP), verificada por grep negativo no verify do plano"
    - "Normalização de acento via tabela manual (strtr) em vez de iconv TRANSLIT — evita dependência de locale do servidor"

key-files:
  created:
    - app/Services/Hubspot/HubspotContactSelector.php
    - app/Services/Hubspot/HubspotNameNormalizer.php
    - tests/Unit/Phase113ContactSelectorTest.php
    - tests/Unit/Phase113NameNormalizerTest.php
  modified: []

key-decisions:
  - "Desempate SEMPRE por menor id (não só no fallback) — refina a decisão (5) do CONTEXT ('primeiro contato retornado') porque a API HubSpot não garante ordem estável de retorno; ver seção 'Discrição documentada' abaixo"
  - "Remoção de acento via tabela de substituição manual (strtr), não iconv TRANSLIT, para robustez independente de locale do ambiente"
  - "Normalização de nome NÃO remove tokens (ltda/me/sa preservados) — é o mecanismo que impede falso positivo no match fraco de dedup"

patterns-established:
  - "Selector de contato: tier 3 (email+telefone) > tier 2 (email) > tier 1 (telefone/mobilephone) > tier 0 (nenhum); 'tem' = valor não-vazio após trim; empate → menor id (numérico quando ambos numéricos, senão string)"

requirements-completed: [HUB-CONTATO-01, HUB-DEDUP-02]

# Metrics
duration: ~5min
completed: 2026-07-24
---

# Phase 113 Plan 01: Contato Principal + Normalização de Nome (TDD) Summary

**HubspotContactSelector escolhe contato principal por tiers de prioridade (email+telefone > email > telefone > nenhum) com desempate determinístico por menor id; HubspotNameNormalizer normaliza nome de empresa para dedup fraco sem colapsar empresas distintas (testado contra falso positivo).**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-07-24T16:36:00Z (aprox., primeiro commit RED)
- **Completed:** 2026-07-24T16:40:27Z (último commit GREEN)
- **Tasks:** 2 (ambas TDD RED→GREEN)
- **Files modified:** 4 (2 classes novas + 2 suites de teste novas)

## Accomplishments
- `HubspotContactSelector::selecionar()` — regra pura e determinística de contato principal, com 9 casos de teste cobrindo tiers, empate numérico/string, campos vazios após trim e determinismo com lista embaralhada.
- `HubspotNameNormalizer::normalizar()` — normalização de nome de empresa (caixa/acento/pontuação/espaços) com 7 casos de teste, incluindo os dois casos âncora de anti-falso-positivo exigidos pelo CONTEXT ("Padaria do Zé" vs "Padaria da Ana"; "Silva Ltda" vs "Silva Ltda ME").
- Ambas as classes são 100% puras (sem DB/config/HTTP) — verificado via grep negativo, conforme `<verification>` do plano.

## Task Commits

Cada tarefa foi commitada atomicamente em ciclo RED→GREEN:

1. **Tarefa 1: HubspotContactSelector (RED)** - `1ae670c3` (test)
2. **Tarefa 1: HubspotContactSelector (GREEN)** - `4b954c3e` (feat)
3. **Tarefa 2: HubspotNameNormalizer (RED)** - `6f550bd6` (test)
4. **Tarefa 2: HubspotNameNormalizer (GREEN)** - `39ff20f6` (feat) — inclui correção de uma asserção do próprio teste (ver Deviations)

**Plan metadata:** (a ser criado no commit final desta execução)

## Files Created/Modified
- `app/Services/Hubspot/HubspotContactSelector.php` - Regra determinística de contato principal (tiers + desempate por menor id), 119 linhas
- `app/Services/Hubspot/HubspotNameNormalizer.php` - Normalização de nome de empresa anti-falso-positivo, 63 linhas
- `tests/Unit/Phase113ContactSelectorTest.php` - 9 testes unitários cobrindo o `<behavior>` do plano
- `tests/Unit/Phase113NameNormalizerTest.php` - 7 testes unitários cobrindo equivalência + anti-falso-positivo

## Decisions Made

### Discrição documentada: desempate por "menor id" em vez de "primeiro retornado"
A decisão (5) do `113-CONTEXT.md` lista como último critério de prioridade "fallback: primeiro contato retornado". A implementação usa **"menor id"** como critério de desempate em **todos** os tiers (não só no fallback final), porque a API do HubSpot não garante ordem estável de retorno entre chamadas — "primeiro retornado" na prática não é determinístico. Isso é tratado como **refinamento consciente do mesmo objetivo** (o CONTEXT já pede determinismo explicitamente no `must_haves.truths`: "o sistema escolhe SEMPRE o mesmo contato principal"), não como divergência de intenção. Documentado em comentário pt-BR no topo da classe `HubspotContactSelector` e aqui no SUMMARY, conforme instruído pelo plano (Tarefa 1, `<action>`).

### Remoção de acento sem iconv TRANSLIT
`HubspotNameNormalizer::removerAcentos()` usa uma tabela de substituição manual (`strtr`) em vez de `iconv('UTF-8', 'ASCII//TRANSLIT', ...)`. Motivo: `iconv` TRANSLIT depende da locale instalada no servidor e pode se comportar de forma inconsistente entre ambientes (dev Windows/XAMPP vs VPS Linux). A tabela manual cobre os diacríticos relevantes para nomes de empresa em pt-BR (á à â ã ä å, é è ê ë, í ì î ï, ó ò ô õ ö, ú ù û ü, ç, ñ, ý ÿ) de forma determinística e portável.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Asserção incorreta no teste de pontuação/`&` corrigida antes do commit GREEN**
- **Found during:** Tarefa 2 (HubspotNameNormalizer) — primeira rodada de `php artisan test` após implementar a classe
- **Issue:** O teste `test_pontuacao_e_e_comercial_viram_espaco_e_colapsam` (escrito no RED) esperava que `"Açaí & Cia. Ltda"` normalizasse para `"acai e cia ltda"` (com a palavra "e"). O `<behavior>` do plano já sinalizava essa ambiguidade com a nota "atenção" e recomendava validar com um par realmente equivalente — a implementação correta trata `&`/`.` como separadores (viram espaço), não como a palavra "e"; um símbolo não pode ser traduzido semanticamente sem lista de sinônimos, o que fugiria do escopo de normalização conservadora exigido pelo anti-falso-positivo.
- **Fix:** Corrigida a asserção do teste para `"acai cia ltda"` (comportamento real e correto da normalização conservadora), com comentário pt-BR explicando o porquê.
- **Files modified:** tests/Unit/Phase113NameNormalizerTest.php
- **Verification:** `php artisan test tests/Unit/Phase113NameNormalizerTest.php` — 7/7 verde após a correção.
- **Committed in:** `39ff20f6` (parte do commit GREEN da Tarefa 2, junto com a implementação — ambos os arquivos já estavam no escopo `<files>` da tarefa)

---

**Total deviations:** 1 auto-fixed (1 bug de teste, Rule 1)
**Impact on plan:** Correção pontual em asserção de teste próprio, sem alterar o comportamento especificado no `<behavior>` do plano (que já alertava sobre essa ambiguidade). Nenhum scope creep.

## Issues Encountered
- `php` não estava no `PATH` do shell (Git Bash) neste ambiente XAMPP — resolvido usando `export PATH="/c/xampp/php:$PATH"` antes de cada `php artisan test`. Não é uma mudança de código, apenas ambiente de execução local.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- `HubspotContactSelector::selecionar()` e `HubspotNameNormalizer::normalizar()` prontos para consumo pelo controller/handoff service na 113-02 (contato principal → campos estruturados) e pela lógica de dedup na 113-03 (match fraco por nome normalizado).
- Nenhum bloqueio. As duas classes são unidades puras já testadas — 113-02/113-03 podem confiar no contrato sem re-testar a regra em fluxo E2E.
- Nota para 113-02: ao integrar `selecionar()` no controller, lembrar que o "id" do array de entrada deve ser o id do contato HubSpot (string ou int) — a comparação de desempate já lida com ambos os casos.

---
*Phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip*
*Completed: 2026-07-24*

## Self-Check: PASSED

Todos os 4 arquivos criados confirmados em disco; os 4 commits (RED/GREEN x2) confirmados em `git log`.
