---
phase: 137-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0
verified: 2026-09-01T22:30:00Z
status: gaps_found
score: 4.5/5 truths verificadas (1 parcial)
overrides_applied: 0
gaps:
  - truth: "Não existe outro ponto do código que grave companies.etapa além de um único serviço de transição (ETAPA-03) — e isso é verificado automaticamente, para sempre"
    status: partial
    reason: >
      O invariante É verdadeiro HOJE (confirmado por grep manual e independente em todo `app/`:
      as únicas gravações de `companies.etapa` estão dentro de
      `app/Services/FluxoEntrada/EtapaTransicaoService.php`). Mas o gate estático que deveria
      manter isso verdadeiro PARA SEMPRE
      (`tests/Feature/Phase137/EtapaPontoUnicoTest.php::test_gate_nenhum_arquivo_de_app_escreve_companies_etapa_fora_do_servico`)
      é contornável de duas formas independentes, comprovadas por injeção real de código
      neste ambiente: (1) usar qualquer nome de variável diferente de `$company` (o regex de
      atribuição direta é `\$company->etapa\s*=`, escopado ao nome literal `$company` — um
      `$empresa->etapa = ...` não é pego); (2) montar o array de update indiretamente
      (`$dados['etapa'] = ...; $company->update($dados);` não casa com o padrão
      `'etapa' => ` procurado). Isso é relevante porque as Fases 138-142 vão adicionar os
      primeiros chamadores reais de `EtapaTransicaoService` em vários controllers/jobs — é
      justamente quando padrões de nomeação divergentes de `$company` aparecem no código.
    artifacts:
      - path: "tests/Feature/Phase137/EtapaPontoUnicoTest.php"
        issue: >
          Grupo 2 (varredura estática) só reconhece a atribuição direta pelo nome de variável
          literal `$company` e só reconhece a chave de array quando a string `'etapa' => `
          aparece verbatim no corpo da função — não cobre construção indireta do array nem
          nomes de variável diferentes para uma instância de `Company`.
    missing:
      - "Fortalecer o regex de atribuição direta para casar qualquer `->etapa\\s*=` cujo receiver seja tipado/inferível como `Company` (ou, mais simples e mais seguro: casar `->etapa\\s*=` sem exigir o nome `$company`, aceitando o custo de falso positivo ocasional a ser tratado por exceção nomeada, já que hoje NÃO há nenhuma ocorrência legítima de `->etapa =` em `app/` fora do serviço)."
      - "Cobrir o padrão de array indireto (`$var['etapa'] = ...` seguido de `->update($var)`/`->fill($var)` no mesmo corpo de função), ou alternativamente documentar essa lacuna como risco aceito com justificativa explícita, revisitando antes da Fase 138 adicionar o primeiro chamador real."
      - "Repetir a prova por injeção temporária (que a Task 1 de 137-06 já fez para o caso coberto) também para os dois bypasses acima, documentando o resultado em 137-06-SUMMARY.md ou nova nota de aprendizado."
---

# Phase 137: Máquina de estados — os 9 status de `companies.etapa` (v23.0) — Verificação

**Objetivo da fase:** Cada empresa carrega uma etapa própria entre os 9 status do §10, gravada e transicionada por um único serviço central, com pendência declarável em paralelo sem nunca sobrescrever a etapa — e as ~500 empresas já cadastradas migram sem quebrar o que a tela "Empresas" de `/companies` mostra hoje.

**Verificado em:** 2026-09-01
**Status:** gaps_found (1 gap parcial, WARNING — não bloqueia a fase, mas exige decisão)
**Re-verificação:** Não — verificação inicial (nenhum `137-VERIFICATION.md` anterior)

## Goal Achievement

### Observable Truths

| # | Truth (Success Criteria do ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | Toda empresa tem etapa entre as 9 do §10; ~500 já cadastradas recebem etapa no backfill; quem já estava "em operação" (analista OU estrategista) entra direto na etapa 9; quem fica sem etapa continua resolvido pelo cálculo antigo; `/companies` não perde nenhuma empresa (ETAPA-01, ETAPA-02, D2) | ✓ VERIFIED | Migration aditiva confirmada; 9 constantes + `ETAPAS` corretas e na ordem certa (18 testes Unit verdes); reconsulta direta ao banco confirma `total=180, em_operacao=1, null=179` (idêntico a `137-BACKFILL-CONTAGENS.md`); `EtapaBackfill.php` usa **exatamente** as mesmas relações (`analistaPerformance`/`estrategistaPerformance`, combinadas por OR) que `CompanyController.php:260` já usa para calcular `em_operacao` hoje — grep cruzado confirma ausência de drift; `EtapaBackfillTest::test_payload_de_companies_e_identico_antes_e_depois_do_backfill` prova que o payload de `GET /companies` não muda; suíte baseline (24/24) continua verde após o backfill local |
| 2 | Não existe outro ponto do código que grave `companies.etapa` além de um único serviço de transição (ETAPA-03) | ⚠️ PARTIAL (ver gap) | **Estado atual:** verificado por grep manual, independente do teste, em TODO `app/` — a única gravação de `companies.etapa` está em `EtapaTransicaoService.php` (`$company->update(['etapa' => ...])` e `Company::whereIn(...)->update(['etapa' => ...])`). `CompanyController::update()` tem comentário-guarda e teste comportamental provando que `PUT /companies/{company}` com `etapa`/`pendencia_*` no corpo não grava. **Mas** o gate estático que deveria manter isso verdadeiro para sempre é comprovadamente contornável — ver seção "Achado: gate estático contornável" abaixo, com prova por injeção real de código |
| 3 | Uma empresa pode ter pendência marcada permanecendo na mesma etapa — marcar/desmarcar pendência nunca move a etapa (ETAPA-04, D6) | ✓ VERIFIED | `Company::declararPendencia()`/`resolverPendencia()` só tocam os 4 campos de pendência, nunca `etapa` (leitura de código confirma); `EtapaPendenciaParaleloTest` (6 testes) prova isso em várias etapas diferentes + ciclo completo; gate de leitura direta (`test_gate_nenhum_arquivo_de_app_le_pendencia_aberta_direto`) usa varredura por substring do NOME DA COLUNA em qualquer lugar do arquivo (não por padrão de variável) — não sofre do mesmo bypass do gate de escrita da ETAPA-03, e de fato já pegou uma colisão real (obrigou renomear `pendencia_aberta` para `tem_pendencia` no payload de `/companies` no plano 137-07) |
| 4 | Pelo menos uma listagem existente pode ser filtrada por etapa e, separadamente, por "com pendência" (ETAPA-05) | ✓ VERIFIED | `CompanyController::index()` implementa `?etapa=` (allow-list contra `Company::ETAPAS` + sentinela `sem_etapa`) e `?com_pendencia=` (via `scopeComPendenciaAberta()`), independentes e combináveis; `resources/js/Pages/Companies/Index.jsx` tem os dois controles, ambos via `router.get` (nunca `Array.filter` client-side — confirmado por leitura do código); build (`public/build/assets/Index-BXM2_qql.js`) contém a string `"Sem etapa (legado)"`, confirmando que o bundle reflete o código-fonte atual; **verificação humana da tela já foi feita e aprovada pelo usuário** (visão padrão inalterada, "Sem etapa (legado)" filtra de verdade via round-trip real) |
| 5 | Tentar avançar etapa sem requisitos cumpridos é recusado com mensagem que nomeia o requisito faltante, nunca erro genérico (ETAPA-06) | ✓ VERIFIED | `EtapaTransicaoService::podeTransicionar()` é puro, sem efeito colateral (confirmado por leitura); toda recusa nomeia especificamente o problema (etapa desconhecida cita o valor recebido; etapa igual à atual nomeia a etapa; transição fora da tabela nomeia origem+destino+lista de destinos aceitos); salto 2→4 aceito e salto 1→9 recusado, cobertos por teste; retrocesso exige motivo não vazio, também coberto |

**Score:** 4/5 truths plenamente verificadas + 1/5 parcialmente verificada (verdade atual sólida, proteção automatizada de regressão com lacuna comprovada)

### Achado: gate estático da ETAPA-03 é contornável (verificado por injeção real de código)

O contexto de verificação pediu explicitamente para checar "de verdade" — não confiar na palavra do SUMMARY — que o gate estático em `EtapaPontoUnicoTest.php` "realmente pega uma escrita fora do serviço e não é trivialmente contornável". Fiz exatamente isso: criei dois arquivos-sonda temporários dentro de `app/Http/Controllers/`, rodei a suíte do gate, e removi os arquivos antes de terminar (nenhum ficou no working tree — `git status` confirmado limpo depois).

**Bypass 1 — nome de variável diferente de `$company`:**
```php
public function furarComVariavelDiferente(Company $empresa): void
{
    $empresa->etapa = Company::ETAPA_EM_OPERACAO;
    $empresa->save();
}
```
Resultado: `OK (1 test, 2 assertions)` — o gate **não pegou**. O regex de atribuição direta (`\$company->etapa\s*=(?!=)`) está escopado ao nome literal `$company`; qualquer outro nome de variável (comum no projeto — outros arquivos usam `$empresa`, `$c`, etc. para instâncias de `Company`) evade completamente.

**Bypass 2 — montagem indireta do array de update:**
```php
public function furarComArrayIndireto(Company $company): void
{
    $dados = [];
    $dados['etapa'] = Company::ETAPA_EM_OPERACAO;
    $company->update($dados);
}
```
Resultado: `OK (1 test, 2 assertions)` — também **não pegou**. O padrão da chave de array (`'etapa' => `) exige a sintaxe literal de array `['etapa' => valor]` no mesmo corpo de função; escrever a chave separadamente (`$dados['etapa'] = valor`) não casa com o regex, mesmo o `->update($dados)` estando visível logo abaixo.

(Nota lateral: minha primeira tentativa de reproduzir o Bypass 1 acusou uma falha — mas investigando, a falha veio de um COMENTÁRIO que eu tinha escrito contendo literalmente a string `$company->etapa = ` como texto explicativo, não do código de verdade. Isso é, aliás, mais um sinal de fragilidade do padrão: ele roda sobre o texto bruto do corpo de função sem excluir comentários. Removido o comentário, o bypass real passou despercebido, como demonstrado acima.)

Depois de remover os dois arquivos-sonda, a suíte completa (`tests/Feature/Phase137/EtapaPontoUnicoTest.php`) voltou a rodar limpa: `OK (4 tests, 11 assertions)`.

**Por que isto importa agora, e não é só um detalhe:** hoje o invariante se sustenta porque, na prática, ninguém mais escreve `etapa` — não porque o gate impeça. As Fases 138 (webhook→etapa 1), 139 (FINALIZAR→etapa 5), 141 (distribuição→etapa 6) e 142 (onboarding→7/8/9) são exatamente as fases que vão adicionar os primeiros chamadores reais de `EtapaTransicaoService` espalhados por múltiplos controllers/jobs — é o momento em que um dev, sob pressão, pode escrever `$empresa->etapa = ...` num controller novo (nome de variável natural em pt-BR) sem que nenhum teste avise. O gate do plano 137-06 foi desenhado, comentado e testado com cuidado real (a decisão de escopar por corpo de função para evitar falso-positivo contra `OnboardingPasso::etapa` é sólida e bem documentada) — o problema não é falta de esforço, é que o padrão de detecção ficou mais estreito do que o próprio SUMMARY declara ("prova permanente do Success Criteria nº 2").

**Classificação: WARNING, não BLOCKER.** O estado atual do código está correto e plenamente verificado por mim de forma independente (grep manual em todo `app/`, sem depender do teste). Não há violação real hoje. A fase pode prosseguir para 138 — mas o time deve decidir, antes de 138 adicionar o primeiro chamador de produção, se fortalece o gate agora ou aceita o risco conscientemente.

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `database/migrations/2026_09_01_110000_add_etapa_to_companies_table.php` | Coluna `etapa` aditiva, nullable, sem default, indexada | ✓ VERIFIED | Migration lida; `up()` só adiciona coluna+índice, `down()` só remove `etapa`; nenhuma menção a `service_type`/`->update(` |
| `app/Models/Company.php` | 9 constantes `ETAPA_*` + `ETAPAS`, docblocks de desambiguação, `etapa` em `$fillable`, fora do `logOnly` | ✓ VERIFIED | Constantes na ordem correta; docblocks presentes; `logOnly()` do activitylog não contém `etapa`; `pendenciaAberta()`/`scopeComPendenciaAberta()`/`declararPendencia()`/`resolverPendencia()` presentes e corretos |
| `tests/Unit/Phase137/CompanyEtapaConstantesTest.php` | Prova dos 9 valores/ordem/schema | ✓ VERIFIED | Parte dos 18 testes Unit da fase, todos verdes |
| `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php` | Tabela de histórico append-only | ✓ VERIFIED | Colunas corretas, sem `updated_at`, índice composto `(company_id, created_at)` |
| `app/Models/CompanyEtapaTransicao.php` | `UPDATED_AT = null`, fillable, relações | ✓ VERIFIED | Confirmado por leitura direta |
| `app/Services/FluxoEntrada/EtapaTransicaoService.php` | `podeTransicionar()` puro + `transicionar()` com efeito + `carimbarBackfill()` | ✓ VERIFIED | Lido na íntegra; único escritor real de `companies.etapa` no código hoje (confirmado por grep manual em todo `app/`); transação envolve escrita+histórico; `save()`/`update()` grava só `etapa` (não colide com `CompanyGatilhoContratoObserver`) |
| `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` | Recusa nomeada, salto 2→4, retrocesso com motivo, ator registrado | ✓ VERIFIED | Parte dos 18 testes Unit, todos verdes |
| `database/migrations/2026_09_01_130000_add_pendencia_to_companies_table.php` | 4 colunas de pendência | ✓ VERIFIED | `pendencia_aberta` NOT NULL default false; FK `pendencia_por` solta antes do `dropColumn` no `down()` (cuidado MariaDB documentado) |
| `tests/Feature/Phase137/EtapaPendenciaParaleloTest.php` | Pendência independente da etapa + gate de leitura | ✓ VERIFIED | 6 testes, cobrindo várias etapas e ciclo completo |
| `app/Console/Commands/EtapaBackfill.php` | Dry-run por padrão, dois baldes, delega escrita ao serviço | ✓ VERIFIED | Sem `--limite`/`--servico`; usa `analistaPerformance`/`estrategistaPerformance`; chama `carimbarBackfill()`; zero `update([...'etapa'...])` direto no comando |
| `tests/Feature/Phase137/EtapaBackfillTest.php` | Dois baldes, D-05, D-06, dry-run, payload preservado | ✓ VERIFIED | 9 testes, todos os cenários do plano cobertos |
| `137-BACKFILL-CONTAGENS.md` | Contagens por reconsulta SQL direta | ✓ VERIFIED | Reconsulta independente via `tinker` reproduziu exatamente os mesmos números (`total=180, em_operacao=1, null=179, distinct=['em_operacao'], transicoes=0`) |
| `tests/Feature/Phase137/EtapaPontoUnicoTest.php` | Regressão comportamental + varredura estática permanente | ⚠️ PARTIAL | Grupo 1 (comportamental) VERIFIED — provado por request HTTP real. Grupo 2 (estático) tem cobertura mais estreita do que o SUMMARY declara — ver achado acima |
| `app/Http/Controllers/CompanyController.php` (comentário-guarda) | Aviso acima da validação de `update()` | ✓ VERIFIED | Comentário de 16 linhas presente, cita `EtapaTransicaoService`, `ETAPA-03` e `EtapaPontoUnicoTest`; `git diff` do plano 137-06 mostrou só linhas adicionadas |
| `tests/Feature/Phase137/EtapaFiltroListagemTest.php` | 6 cenários do filtro | ✓ VERIFIED | 9 testes cobrindo etapa concreta, `sem_etapa`, valor inválido, pendência isolada, combinação, visão padrão |
| `resources/js/Pages/Companies/Index.jsx` (filtros) | Controles server-side | ✓ VERIFIED | `router.get` em ambos os handlers; build atualizado contém as strings novas; verificação humana aprovada |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `app/Models/Company.php` | migration `..._110000_...` | `'etapa'` em `$fillable` | ✓ WIRED | Confirmado por leitura |
| `EtapaTransicaoService::transicionar()` | `CompanyEtapaTransicao` | `CompanyEtapaTransicao::create()` dentro da mesma transação | ✓ WIRED | Testado (`EtapaTransicaoServiceTest`) |
| `EtapaTransicaoService::podeTransicionar()` | `Company::ETAPAS` | `in_array($etapaDestino, Company::ETAPAS, true)` | ✓ WIRED | Confirmado por leitura e teste |
| `Company::declararPendencia()`/`resolverPendencia()` | colunas `pendencia_*` | `$this->update([...])` | ✓ WIRED | Testado, nunca toca `etapa` |
| `EtapaBackfill` | `Company::analistaPerformance()`/`estrategistaPerformance()` | `whereHas(...)->orWhereHas(...)` | ✓ WIRED | Confirmado idêntico à lógica de `em_operacao` já usada em `CompanyController.php:260` — sem drift |
| `EtapaBackfill` | `EtapaTransicaoService::carimbarBackfill()` | chamada direta com ids do balde 1 | ✓ WIRED | Comando não grava `etapa` diretamente (confirmado) |
| `CompanyController::update()` | validação fechada | ausência de `etapa`/`pendencia_*` na lista de `$request->validate()` | ✓ WIRED | Testado por request HTTP real |
| `CompanyController::index()` | `resources/js/Pages/Companies/Index.jsx` | query params `?etapa=`/`?com_pendencia=` via `router.get` | ✓ WIRED | Testado + verificado por humano na tela real |
| `tests/Feature/Phase137/EtapaPontoUnicoTest.php` (gate estático) | árvore `app/` | varredura por `token_get_all()` | ⚠️ PARTIAL | Roda e pega o caso que cobre, mas dois bypasses comprovados (ver achado acima) fazem o link ser mais fraco do que "wired" pleno |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| `resources/js/Pages/Companies/Index.jsx` (seletor de etapa) | `etapaFilter` (prop `filters.etapa`) | `CompanyController::index()` → `where('etapa', $etapaFilter)` sobre `companies` real | Sim — reconsulta ao MariaDB confirma 1 empresa com `etapa='em_operacao'`, 179 com `NULL` | ✓ FLOWING |
| `resources/js/Pages/Companies/Index.jsx` (toggle pendência) | `comPendenciaFilter` (prop `filters.com_pendencia`) | `CompanyController::index()` → `comPendenciaAberta()` scope sobre `companies` real | Sim — coluna `pendencia_aberta` real, indexada | ✓ FLOWING |
| Payload por empresa — chave `etapa` | `$c->etapa` | Atributo Eloquent direto da coluna | Sim | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Suíte segura completa da fase (Unit+Feature Phase137 + baseline `/companies`) | `phpunit tests/Unit/Phase137 tests/Feature/Phase137 tests/Feature/Phase37CompaniesPerformanceFilterTest.php tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | `OK (70 tests, 154 assertions)` | ✓ PASS |
| Regressão Unit completa vs. baseline pré-fase | `phpunit --testsuite=Unit` | `303 tests, 1029 assertions, 9 errors, 3 failures` — exatamente os mesmos 9+3 pré-existentes da baseline (`285 tests` → `303 tests`, +18 são desta fase, todos verdes) | ✓ PASS (sem regressão) |
| Estado real do banco reflete o backfill declarado | `tinker`: contagens de `companies.etapa` e `company_etapa_transicoes` | `total=180, em_operacao=1, null=179, distinct=['em_operacao'], transicoes=0` — idêntico a `137-BACKFILL-CONTAGENS.md` | ✓ PASS |
| Gate estático da ETAPA-03 realmente pega uma violação no formato coberto | Injeção temporária de `$company->etapa = ...` (nome exato) em novo arquivo de `app/` | Gate falha nomeando arquivo:linha corretamente | ✓ PASS |
| Gate estático da ETAPA-03 pega variação de nome de variável | Injeção temporária de `$empresa->etapa = ...` | Gate **não** falha — bypass confirmado | ✗ FAIL (achado reportado acima) |
| Gate estático da ETAPA-03 pega array indireto | Injeção temporária de `$dados['etapa'] = ...; $company->update($dados);` | Gate **não** falha — bypass confirmado | ✗ FAIL (achado reportado acima) |
| Build do frontend reflete o código-fonte atual | grep de string nova (`"Sem etapa (legado)"`) no bundle compilado | String encontrada em `public/build/assets/Index-BXM2_qql.js` | ✓ PASS |

### Probe Execution

Nenhum probe (`scripts/*/tests/probe-*.sh`) declarado para esta fase nos PLAN/SUMMARY, e nenhum arquivo correspondente encontrado em `scripts/`. **SKIPPED (nenhum probe aplicável a esta fase).**

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| ETAPA-01 | 137-02 | `companies.etapa` com 9 valores do §10, aditiva, constante no model | ✓ SATISFIED | Migration + constantes + 6 testes verdes |
| ETAPA-02 | 137-05 | Backfill das já cadastradas; em operação → etapa 9; fallback para o resto | ✓ SATISFIED | Comando + 9 testes + reconsulta ao banco cruzada de forma independente |
| ETAPA-03 | 137-03 (transicionar) + 137-06 (regressão) | Único serviço decide/grava transição | ⚠️ SATISFIED COM RESSALVA | Verdade atual sólida (grep manual independente); gate de regressão automatizada tem lacuna comprovada — ver achado |
| ETAPA-04 | 137-04 | Pendência paralela, nunca move etapa | ✓ SATISFIED | 6 testes + gate de leitura robusto (sem o mesmo bypass da ETAPA-03) |
| ETAPA-05 | 137-07 | Filtro por etapa e por pendência em listagem existente | ✓ SATISFIED | 9 testes + verificação humana aprovada + build confirmado |
| ETAPA-06 | 137-03 | Transição inválida recusada com requisito nomeado | ✓ SATISFIED | Testado explicitamente (mensagens nomeadas, salto 2→4, retrocesso) |

Nenhum requirement órfão: `.planning/REQUIREMENTS-v23.md` linhas 161-166 mapeiam exatamente `ETAPA-01`..`ETAPA-06` para a Fase 137, e todos os seis aparecem em pelo menos um `requirements:` de plano desta fase.

**Nota sobre defeito de frontmatter, não de entrega:** `137-01-PLAN.md` lista `requirements: [ETAPA-01, ETAPA-02]` no frontmatter, mas esse plano só destravou o ambiente de teste e registrou a baseline — não tocou `app/`/`database/`. ETAPA-01 foi de fato entregue por 137-02 e ETAPA-02 por 137-05. Julgado como defeito de frontmatter do plano, não como gap de entrega (conforme contexto fornecido pelo orquestrador).

### Anti-Patterns Found

Nenhum encontrado. Varredura de `TBD|FIXME|XXX|TODO|HACK|PLACEHOLDER` e de frases como "coming soon"/"not yet implemented" em todos os 9 arquivos de produção tocados pela fase (migrations, model, service, controller, comando, JSX) não retornou nenhuma ocorrência real (um falso-positivo de substring `TODO` dentro da palavra "TODOS" foi descartado por inspeção).

### Human Verification Required

Nenhum item pendente. A verificação humana da tela `/companies` (visão padrão inalterada — "Empresas (1)" — os dois controles novos renderizam, e "Sem etapa (legado)" esvazia a lista via round-trip real) já foi realizada pelo usuário e aprovada, conforme registrado em `137-07-SUMMARY.md` e confirmado pelo contexto de verificação. Tratado como satisfeito.

### Dívidas conhecidas, já registradas — não reabertas aqui

- **Suíte Feature completa não termina neste worktree.** Cascata de timeout de rede de 300s pré-existente, medida por `137-01` ANTES de qualquer código desta fase existir. Registrada como dívida explícita em `137-07-SUMMARY.md` § "Dívida explícita". `137-VALIDATION.md` exigia suíte completa verde antes do `/gsd:verify-work`; essa exigência foi conscientemente flexibilizada com motivo documentado (não é um descuido silencioso).
- **`EtapaTransicaoService` sem chamador de produção.** Deliberado — Fases 138-142 é que plugam os gatilhos reais.
- **Filtro de etapa também estreita a contagem da aba "Pendências".** Comportamento pré-existente compartilhado com o filtro `cust_id_status` já existente, não introduzido por esta fase — observação registrada para acompanhamento, não defeito.

### Gaps Summary

Um gap parcial (WARNING, não BLOCKER): o gate estático de regressão da ETAPA-03
(`EtapaPontoUnicoTest.php`, Grupo 2) protege contra o formato exato de violação que o próprio
autor testou (`$company->etapa = ...`), mas não contra duas variações triviais e prováveis —
nome de variável diferente de `$company`, e montagem indireta do array de update. O invariante
que ele deveria proteger ("só o serviço grava `companies.etapa`") **é verdadeiro hoje**,
confirmado por mim de forma independente do teste. O risco é prospectivo: as Fases 138-142 vão
adicionar os primeiros chamadores reais de `EtapaTransicaoService` em múltiplos controllers/jobs,
exatamente o cenário em que nomes de variável divergentes de `$company` tendem a aparecer.
Recomendo fechar isso antes ou durante a Fase 138 (o primeiro consumidor real), não
necessariamente antes de a Fase 137 ser considerada concluída — mas não deveria ficar como
achado silencioso.

Todas as outras quatro Success Criteria do ROADMAP (SC1, SC3, SC4, SC5) estão plenamente
verificadas por evidência de código, teste automatizado independente, reconsulta direta ao banco
e verificação humana já aprovada.

---

_Verified: 2026-09-01_
_Verifier: Claude (gsd-verifier)_
