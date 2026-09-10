---
phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
plan: 08
subsystem: testing
tags: [phpunit, static-analysis, token_get_all, static-gate, companies-etapa]

# Dependency graph
requires:
  - phase: 150-06
    provides: "Gate estático original (EtapaPontoUnicoTest.php Grupo 2), escopado por corpo de função com regex hardcoded ao nome $company"
provides:
  - "Gate estático de companies.etapa endurecido contra os 3 bypasses do 150-VERIFICATION.md (variável de nome divergente, array indireto, DB::table('companies'))"
  - "Neutralização de comentários (T_COMMENT/T_DOC_COMMENT) antes de qualquer varredura estática do gate"
  - "Escopo de varredura ampliado para database/migrations/, além de app/"
  - "Mecanismo de exceção nomeada (EXCECOES_REGRA_A), vazio por desenho"
affects: [138, 139, 140, 141, 142]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Neutralização de comentário via token_get_all() antes de regex estática — evita falso positivo por texto explicativo em docblock/comentário-guarda"
    - "Guard de classe avaliado por ARQUIVO (não por corpo de função) quando o corpo extraído por token balancing descarta a assinatura/tipo do parâmetro"

key-files:
  created: []
  modified:
    - tests/Feature/Phase150/EtapaPontoUnicoTest.php

key-decisions:
  - "Regra A (atribuição direta ->etapa =) roda sobre o ARQUIVO inteiro já limpo de comentário, não por corpo de função — pega também atribuição fora de método"
  - "Guard de escrita em Company (clausula c) avaliado no ARQUIVO, não no corpo, porque corposDeFuncao() descarta a assinatura da função e um parâmetro Company $empresa some do texto varrido"
  - "EXCECOES_REGRA_A nasce vazia — mecanismo existe mas não tem entrada nenhuma hoje, por não haver ocorrência legítima medida de ->etapa = fora do serviço"

patterns-established:
  - "Toda regra estática nova sobre companies.etapa deve rodar sobre o código já limpo de comentário (removerComentarios()), nunca sobre o texto bruto"

requirements-completed: [ETAPA-03]

# Metrics
duration: 20min
completed: 2026-09-02
---

# Phase 150 Plan 08: Fecha os 3 bypasses do gate estático de companies.etapa Summary

**Gate estático de `EtapaPontoUnicoTest.php` reescrito com 3 regras (atribuição direta, array indireto, `DB::table('companies')`) avaliadas sobre código já limpo de comentário, provado vermelho por injeção real nas 3 formas de bypass do `150-VERIFICATION.md` e verde na árvore atual sem nenhuma exceção nomeada.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-02 (leitura dos arquivos-fonte do read_first)
- **Completed:** 2026-09-02T12:34:59Z
- **Tasks:** 2/2
- **Files modified:** 1

## Accomplishments

- Fechado o G1 (CRITICAL) do `150-VERIFICATION.md`: os 3 bypasses do gate estático da ETAPA-03 (nome de variável divergente de `$company`, array de update montado indiretamente, `DB::table('companies')->update()`) estão fechados por regras testadas.
- Comentários (`T_COMMENT`/`T_DOC_COMMENT`) deixaram de ser fonte de falso positivo — neutralizados antes de qualquer regex rodar, preservando a numeração de linha.
- Escopo de varredura ampliado para `database/migrations/`, cobrindo o idioma de migration corretiva que não passa pelo serviço.
- As 3 sondas de bypass, mais uma sonda de regressão da forma original e uma sonda de falso positivo de comentário, foram injetadas de verdade (uma de cada vez, sempre removendo a anterior) e produziram exatamente o veredito esperado — evidência colada abaixo.
- Zero falsos positivos na árvore atual: `EXCECOES_REGRA_A` permanece vazia; as quase-colisões conhecidas (`OnboardingPasso::etapa` em 3 arquivos + a migration de `onboarding_passos`) continuam passando, confirmado por leitura de cada corpo de função envolvido, não só pela suíte verde.

## Task Commits

Each task was committed atomically:

1. **Task 1: Ampliar a varredura estática para fechar as 3 formas de bypass** - `2a1aa606` (test)
2. **Task 2: Provar o gate por injeção real das 3 sondas de bypass, e desfazer** - `ff5e2ee1` (test)

**Plan metadata:** ver commit final deste SUMMARY (`docs(150-08): ...`)

_Nota: plano `tdd="true"` na Task 1, mas não é RED/GREEN clássico — o gate já existia (verde) desde 150-06; a Task 1 é um endurecimento do próprio gate, verificado por rodar a suíte antes/depois e, na Task 2, por injeção real de violação (equivalente ao RED de um gate de regressão)._

## Files Created/Modified

- `tests/Feature/Phase150/EtapaPontoUnicoTest.php` - Grupo 2 (varredura estática) reescrito: `removerComentarios()`, `descreveViolacaoNoCorpo()`, `escreveNaCompany()`, `caminhoRelativo()`, constantes `EXCECOES_REGRA_A`/`REGEX_ATRIBUICAO_DIRETA`/`REGEX_ARRAY_OFFSET`/`REGEX_CHAVE_ARRAY`, escopo estendido a `database/migrations/`, docblock reescrito com as 3 regras + detector ampliado + quase-colisões + prova por injeção datada 2026-09-02

## Decisions Made

- **Regra A por arquivo inteiro, não por corpo de função.** O plano exigia isso explicitamente (pega também atribuição de propriedade de classe ou fora de método) — seguido à risca.
- **Guard `Company` da cláusula (c) avaliado no ARQUIVO.** Confirmado necessário: `corposDeFuncao()` descarta a assinatura ao extrair o corpo via balanceamento de chaves, então um parâmetro `Company $empresa` nunca aparece no texto do corpo — só o nome do arquivo (via `use App\Models\Company;` ou qualquer outra menção real) preserva o sinal.
- **`EXCECOES_REGRA_A` nasce vazia**, com docblock explicando que acrescentar uma entrada é ato deliberado e revisado — não foi preciso popular nenhuma entrada, porque a árvore atual não tem nenhuma ocorrência legítima de `->etapa =` fora do serviço.

## Deviations from Plan

None - plan executado exatamente como escrito. As regras A/B/C, o detector ampliado (a-d), a neutralização de comentário e o escopo estendido a `database/migrations/` seguem a `<action>` do plano item a item.

## Issues Encountered

None. Todas as 5 sondas produziram o veredito esperado na primeira tentativa; nenhum ajuste de regex foi necessário depois da implementação inicial.

## Prova por injeção real — evidência (Task 2)

Comando usado em todas as sondas:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase150/EtapaPontoUnicoTest.php --colors=never --filter test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
```

**Sonda 1 — nome de variável diferente (`$empresa->etapa = Company::ETAPA_EM_OPERACAO; $empresa->save();`):**
```
There was 1 failure:
1) Tests\Feature\Phase150\EtapaPontoUnicoTest::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
Success Criteria nº 2 da Fase 150 violado (ETAPA-03): companies.etapa é gravado fora de App\Services\FluxoEntrada\EtapaTransicaoService em: C:\xampp\htdocs\ecf_fluxo_entrada\app\Http\Controllers\SondaGate137_08Temp.php:15 — [Regra A] atribuição direta ->etapa = ... fora de EtapaTransicaoService.
Failed asserting that an array is empty.
```

**Sonda 2 — array indireto, sem token `Company` no corpo (`$dados['etapa'] = 'em_operacao'; $company->update($dados);`):**
```
There was 1 failure:
1) Tests\Feature\Phase150\EtapaPontoUnicoTest::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
Success Criteria nº 2 da Fase 150 violado (ETAPA-03): companies.etapa é gravado fora de App\Services\FluxoEntrada\EtapaTransicaoService em: C:\xampp\htdocs\ecf_fluxo_entrada\app\Http\Controllers\SondaGate137_08Temp.php:19 — [Regra B] escrita por offset $var['etapa'] = ... dentro de um corpo que também grava no model Company.
Failed asserting that an array is empty.
```

**Sonda 3 — `DB::table('companies')->where(...)->update(['etapa' => ...])` (idioma literal de `DiagnoseCustId.php:206`):**
```
There was 1 failure:
1) Tests\Feature\Phase150\EtapaPontoUnicoTest::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
Success Criteria nº 2 da Fase 150 violado (ETAPA-03): companies.etapa é gravado fora de App\Services\FluxoEntrada\EtapaTransicaoService em: C:\xampp\htdocs\ecf_fluxo_entrada\app\Http\Controllers\SondaGate137_08Temp.php:18 — [Regra C] chave 'etapa' => dentro de uma escrita no model Company.
Failed asserting that an array is empty.
```

**Sonda 4 — regressão da forma original (`$company->etapa = Company::ETAPA_EM_OPERACAO; $company->save();`, já coberta pelo 150-06):**
```
There was 1 failure:
1) Tests\Feature\Phase150\EtapaPontoUnicoTest::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico
Success Criteria nº 2 da Fase 150 violado (ETAPA-03): companies.etapa é gravado fora de App\Services\FluxoEntrada\EtapaTransicaoService em: C:\xampp\htdocs\ecf_fluxo_entrada\app\Http\Controllers\SondaGate137_08Temp.php:16 — [Regra A] atribuição direta ->etapa = ... fora de EtapaTransicaoService.
Failed asserting that an array is empty.
```

**Sonda 5 — falso positivo de comentário (único conteúdo relevante é um comentário contendo literalmente `$company->etapa = Company::ETAPA_EM_OPERACAO;` como texto explicativo, sem código de escrita real):**
```
OK (1 test, 2 assertions)
```

Ao final: `SondaGate137_08Temp.php` removido, `git status --short` confirmado sem `??` em `app/`, suíte da fase re-executada limpa.

## Verificação final (árvore limpa, sem sonda)

```
$ C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase150 tests/Feature/Phase150 --colors=never
OK (46 tests, 110 assertions)

$ C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never
OK (24 tests, 44 assertions)

$ git status --short
(vazio)
```

Checagens mecânicas do plano (`acceptance_criteria` da Task 1), todas satisfeitas:
- `grep -c "database/migrations" tests/Feature/Phase150/EtapaPontoUnicoTest.php` → 5 (≥1)
- `grep -vE '^\s*(//|\*|/\*)' ... | grep -c 'T_DOC_COMMENT'` → 1 (≥1)
- `grep -vE '^\s*(//|\*|/\*)' ... | grep -c 'DB::table'` → 1 (≥1)
- `grep -vE '^\s*(//|\*|/\*)' ... | grep -c '\$company->etapa'` → 0 (a Regra A não depende mais do nome literal `$company`)

## Zero falsos positivos — quase-colisões conferidas por leitura de código

Além da suíte verde, cada quase-colisão citada no `<measured_facts>` do plano foi lida linha a linha para confirmar POR QUE passa, não só que passa:

- `app/Services/Onboarding/OnboardingEngineService.php:368` — as 3 menções a "Company" no arquivo estão todas dentro de docblock (linhas 36, 101, 345), somem na neutralização de comentário; `arquivoCitaCompany` fica `false`.
- `app/Services/Onboarding/OnboardingLinkService.php:96` (`passosDoCliente()`) — o arquivo cita `Company` de verdade (import + 5 assinaturas de método), mas o corpo específico de `passosDoCliente()` não tem `->update/fill/forceFill/save`.
- `app/Http/Controllers/OnboardingController.php:948` (`$payload['etapa'] = $trava->etapa;`, dentro de `proximaAcaoPayload()`) e `:1135` (`'etapa' => $passo->etapa,`, dentro de `detalhePasso()`) — o arquivo cita `Company` (outro método, `gerarLink()`), mas nenhum dos dois corpos específicos tem `->update/fill/forceFill/save`.
- `app/Http/Controllers/CompanyController.php:272` e `:397` (dentro de `index()`, ~354 linhas, o "pior caso" citado no plano) — o corpo inteiro de `index()` foi varrido por grep e não contém `->update(`/`->fill(`/`->forceFill(`/`->save(`; os únicos `DB::table(...)` presentes são `'user_setores'` e `'contratos_servico as cs'`, não `'companies'`.
- `database/migrations/2026_08_17_120000_add_etapa_to_onboarding_passos_table.php:69` — zero ocorrências do token `Company` no arquivo inteiro (confirmado por grep antes de implementar).

## Next Phase Readiness

O G1 (CRITICAL) do `150-VERIFICATION.md`/`150-REVIEW.md` (CR-01) está fechado. As Fases 151-142 (primeiros chamadores reais de `EtapaTransicaoService`) agora escrevem sob um gate que recusa as 3 formas de bypass já identificadas, não só a forma literal `$company->etapa =`.

Restam 3 outros gaps de gap-closure planejados para a Fase 150, ainda não executados (ver `ROADMAP.md`, Wave 5-6): `150-09` (G2 — histórico sobrevive a `ON DELETE SET NULL`), `150-10` (G4+G5+G6) e `150-11` (G3 — filtros de `/companies`). Nenhum bloqueio entre eles e este plano.

---
*Phase: 150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0*
*Plan: 08*
*Completed: 2026-09-02*

## Self-Check: PASSED

- FOUND: `.planning/phases/150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/150-08-SUMMARY.md`
- FOUND: commit `2a1aa606` (Task 1)
- FOUND: commit `ff5e2ee1` (Task 2)
