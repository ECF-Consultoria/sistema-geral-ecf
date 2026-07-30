---
phase: 260730-jzx-nps-faltantes-pendentes
plan: "01"
subsystem: nps
tags: [nps, faltantes, pendentes, grupo, inertia, react]
dependency_graph:
  requires:
    - phase: 119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas
      provides: [NpsElegibilidadeService, NpsGrupoCoberturaService, NpsGroupSurvey]
  provides: [colapsarFaltantesPorGrupo, de_grupo no payload de survey, modal de detalhe do link pendente]
  affects: [NpsController.index, Nps/Index.jsx]
tech_stack:
  added: []
  patterns: [reuso de service já existente em vez de reimplementar régua de negócio, agregação de contadores por campo em vez de contagem de linhas]
key_files:
  created:
    - tests/Feature/NpsFaltantesGrupoEResponsavelTest.php
  modified:
    - app/Http/Controllers/NpsController.php
    - resources/js/Pages/Nps/Index.jsx
    - tests/Feature/NpsFaltantesPorModeloTest.php
    - tests/Feature/Phase119_1/NpsAreaD1Test.php
    - tests/Feature/Phase116/NpsFloorAreaNpsTest.php
decisions:
  - "Contadores somam empresas_count/conta_nota_1_count, nunca contam linhas (DQ-03) — colapsar um grupo de N empresas em 1 linha não muda nenhum número exibido"
  - "Elegibilidade (ajuste 4) usa a MESMA NpsElegibilidadeService::empresasElegiveis() do cálculo de nota — nenhuma segunda régua no controller"
  - "Colapso de grupo (ajuste 3) chama NpsGrupoCoberturaService::calcular() 1x por (grupo, setor), nunca por empresa"
  - "3 testes pré-existentes (NpsFaltantesPorModeloTest, Phase119_1/NpsAreaD1Test, Phase116/NpsFloorAreaNpsTest) tiveram fixtures/asserções ajustadas porque descreviam o comportamento ANTIGO que os ajustes 1 e 4 substituem de propósito"
metrics:
  duration: "~50 min"
  completed: "2026-07-30"
  tasks_completed: 4
  files_modified: 5
---

# Quick Task 260730-jzx: NPS Faltantes/Pendentes — 4 ajustes de uso real Summary

**Faltantes filtra por estrategista atribuído (mesma régua da nota), colapsa grupo cuidado pelas mesmas pessoas em 1 linha via `NpsGrupoCoberturaService`, tira o aviso de contato da tela, e Pendentes ganha modal de detalhe do link (quem gerou, quando, validade, se é de grupo).**

## Performance

- **Duration:** ~50 min
- **Tasks:** 3 + checkpoint visual (Task 4)
- **Checkpoint:** **APROVADO pelo usuário** — resposta literal "aprovado", sem pedido de ajuste. Deploy autorizado (feito pelo orquestrador após o fechamento desta quick task).
- **Files modified:** 5 (1 criado, 4 modificados) + 3 arquivos de teste pré-existentes ajustados

## Accomplishments

- Ajuste 1: removida a chave `motivo`/selo "Sem contato cadastrado" — o envio de NPS é manual, contato cadastrado não muda nada; a regra de nota 1 (D5) continua idêntica.
- Ajuste 4: `$faltantes` agora exige estrategista atribuído (via `NpsElegibilidadeService::empresasElegiveis()`, a mesma régua que o cálculo da nota já usa) — empresa fora da operação some da lista de trabalho.
- Ajuste 3: grupo cuidado pelas mesmas pessoas (estrategista E analista, D6) colapsa em 1 linha com contagem de empresas, via `NpsGrupoCoberturaService::calcular()` reusado (1 chamada por grupo+setor). Empresa com responsável divergente ou grupo com só 1 faltante continua individual (DQ-04). Contadores somam empresas, não linhas (DQ-03) — o colapso não muda nenhum número da tela.
- Ajuste 2: survey pendente expõe `de_grupo` no payload; a tela ganhou um botão (ícone olho) que abre modal com endereço do link, botão de copiar, quem gerou, quando, validade e se é link de empresa ou de grupo.
- Botão "Gerar link do grupo" na linha de grupo abre o modal "Gerar Link" já pré-selecionado no modo grupo (grupo + modelo).

## Task Commits

Each task was committed atomically (mais 1 commit extra para as correções de testes pré-existentes, ver Deviations):

1. **Task 1 + Task 2 (backend, feitas juntas na mesma cirurgia de `NpsController::index()`)** — `40cdf2fc`
   - `app/Http/Controllers/NpsController.php`, `tests/Feature/NpsFaltantesGrupoEResponsavelTest.php` (8 testes)
2. **Fix dos testes pré-existentes quebrados pelos ajustes 1/4 (deviation Rule 1)** — `d26bc8ca`
   - `tests/Feature/NpsFaltantesPorModeloTest.php`, `tests/Feature/Phase119_1/NpsAreaD1Test.php`, `tests/Feature/Phase116/NpsFloorAreaNpsTest.php`
3. **Task 3 (frontend)** — `f8268f88`
   - `resources/js/Pages/Nps/Index.jsx`
4. **Task 4 (checkpoint:human-verify — conferência visual)** — **APROVADA pelo usuário**, resposta literal "aprovado", sem pedido de ajuste. Sem commit próprio (checkpoint de verificação, não de código). Deploy autorizado pelo usuário, executado pelo orquestrador após o fechamento desta quick task.

_Nota sobre a ordem dos commits: Tasks 1 e 2 do plano foram implementadas na mesma passagem pelo bloco de `$faltantes` (o colapso de grupo da Task 2 já assume a estrutura que a Task 1 monta) e ficaram no mesmo commit — deviation da regra "1 commit por task" documentada abaixo._

## Files Created/Modified

- `app/Http/Controllers/NpsController.php` — `$elegiveisPorEmpresa` movido para ANTES do laço dos setores (filtro), `$faltantes` sem `motivo`/colunas de contato, com `tipo`/`empresas_count`/`conta_nota_1_count`; novo método privado `colapsarFaltantesPorGrupo()` (chamado depois do laço de `conta_nota_1`, antes do `usort`); `NpsGrupoCoberturaService` injetado no construtor; `de_grupo` no payload do survey.
- `resources/js/Pages/Nps/Index.jsx` — `FaltantesView` sem selo de contato, com linha `tipo === 'grupo'` (nome + selo de contagem + nomes resumidos + botão "Gerar link do grupo"); `abrirGerarLink(preselecao)` novo, substitui o `onClick` direto; novo estado `linkPendente`/`linkPendenteCopiado` + Dialog de detalhe do link + botão (ícone `Eye`) na coluna de ações dos pendentes.
- `tests/Feature/NpsFaltantesGrupoEResponsavelTest.php` (novo) — 8 testes: 4 da Task 1 (elegibilidade/motivo/de_grupo) + 4 da Task 2 (colapso de grupo/divergência/DQ-04/contadores).
- `tests/Feature/NpsFaltantesPorModeloTest.php` — fixture do teste de dedup por setor ganhou um estrategista atribuído (senão a empresa sairia de Faltantes pelo motivo errado sob a regra nova).
- `tests/Feature/Phase119_1/NpsAreaD1Test.php` — os 2 testes que verificavam `motivo` (`sem_contato`/`sem_link`) foram reescritos para provar que a chave não existe mais e que D5 continua valendo.
- `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` — o teste que provava "empresa não elegível aparece em faltantes com `conta_nota_1=false`" foi atualizado: ela agora NÃO aparece mais (ajuste 4 supera esse comportamento de propósito).

## Texto Exato da Tela (rastreabilidade)

Quem mexer nesta tela daqui a meses precisa saber exatamente o que foi aprovado. Textos literais (pt-BR), copiados do JSX aprovado:

**Linha de GRUPO na aba Faltantes** (`FaltantesView`, `resources/js/Pages/Nps/Index.jsx`):
- Título: `{nome do grupo}` (ex.: "Grupo Camillo Parts") + selo `{N} empresas`.
- Selo do modelo ao lado (inalterado — ex.: "NPS Padrão").
- Subtítulo (linha única, reticências, `title` com a lista completa): nomes das empresas do grupo separados por vírgula, ex.: `"Empresa Alfa, Empresa Beta, Empresa Gama"`.
- Texto de estado: `"Um link só vale para as {N} empresas — todas são cuidadas pelas mesmas pessoas."` — e, quando `conta_nota_1_count > 0`, é acrescentado: `" {N} já estão contando nota 1 neste mês."` (concatenado na mesma frase, sem quebra de linha).
- Botão: `"Gerar link do grupo"` (`title` idêntico ao rótulo).

**Linha INDIVIDUAL na aba Faltantes** (sem mudança de texto, só perdeu o selo/parágrafo de contato):
- Texto de estado: `"Sem link neste mês — já está contando nota 1."` (quando `conta_nota_1 === true`) ou `"Sem link neste mês — ainda dá tempo de enviar."` (caso contrário).
- Botão: `"Gerar link"` (`title="Gerar link de NPS"`).

**Botão novo na coluna de ações dos PENDENTES** (ícone `Eye`, ao lado do botão de copiar existente):
- `title="Ver detalhes do link"`.

**Modal "Detalhe do link"** (aberto pelo botão acima):
- Título: `"{nome da empresa} — Link da pesquisa"`.
- Caixa copiável: endereço do link + botão (ícone `Copy` → `CheckCircle` por 2s ao copiar) — mesmo padrão visual do modal "Gerar Link" da Fase 119.1 (`bg-muted`, `text-sm break-all`).
- `"Gerado por:"` — nome de quem gerou, ou `"Envio automático"` quando `auto_generated=true` e não há nome, ou `"Não informado"` como último fallback.
- `"Gerado em:"` — data/hora formatada (`created_at`).
- `"Vale até:"` — data de expiração, ou `"Sem prazo"` quando `expires_at` é nulo.
- `"Tipo de link:"` — `"Link de grupo (vale para todas as empresas do grupo)"` quando `de_grupo === true`, ou `"Link só desta empresa"` caso contrário.
- Rodapé: botão `"Fechar"`.

## Decisions Made

- **DQ-03 — contadores contam EMPRESAS, nunca LINHAS.** `contadores.faltantes`, `contadores.contam_nota_1` e `contadores.todos` somam os campos `empresas_count`/`conta_nota_1_count` de cada linha (`array_sum(array_column($faltantes, ...))`), nunca `count($faltantes)`. **Por que importa:** o colapso de grupo (ajuste 3) reduz N linhas de empresa em 1 linha de grupo. Se os contadores contassem LINHAS, o chip "Faltantes" cairia de, por exemplo, 160 para 156 só porque 5 empresas viraram 1 linha — **um número mudando sem que nada tivesse mudado de verdade** (nenhuma empresa saiu da operação, só a apresentação mudou). Isso quebraria a coerência com os cards do `NpsSemLinkService` (mesma régua usada no bônus, Fase 119.1 T-119.1-15) e confundiria quem lê o painel achando que o volume de trabalho diminuiu. Com `empresas_count` como unidade de soma, o único cenário em que o chip cai é o ajuste 4 (empresa sem responsável saindo de fato da lista) — o que é o comportamento CORRETO e esperado. Provado pelo teste `test_colapso_de_grupo_nao_altera_os_contadores`.
- Elegibilidade (ajuste 4) reusa `NpsElegibilidadeService::empresasElegiveis()` sem reimplementar a checagem de estrategista no controller (DQ-02).
- `NpsGrupoCoberturaService::calcular()` chamado exatamente 1 vez por `(grupo, setor)` dentro de `colapsarFaltantesPorGrupo()` — nunca dentro de um laço por empresa (confirmado por grep: `calcular(` aparece 1 única vez no arquivo).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug/Regressão de teste] `NpsFaltantesPorModeloTest::test_dois_modelos_no_mesmo_setor_nao_duplicam_a_empresa` quebrava sob a regra nova**
- **Found during:** verificação de baseline após Task 1 (`--filter=NpsFaltantesPorModelo`)
- **Issue:** a fixture criava uma empresa com contrato ativo mas SEM estrategista atribuído. Sob o ajuste 4 (empresa sem responsável sai de Faltantes), a empresa sumia da lista pelo motivo ERRADO (falta de elegibilidade), mascarando o que o teste realmente prova (dedup de 2 modelos no mesmo setor).
- **Fix (só na fixture, ZERO asserção alterada):** adicionada uma linha em `company_users` com `role=estrategista` + `servico_id=$servicoPerf` antes do bloco "Sem survey no mês...". Nenhuma linha de `assert*` foi tocada — `$this->assertEquals(1, ...)`/`assertSame($company->id, ...)` continuam literalmente as mesmas.
- **Files modified:** `tests/Feature/NpsFaltantesPorModeloTest.php`
- **Verification:** `--filter=NpsFaltantesPorModelo` → 3/3 passed.
- **Committed in:** `d26bc8ca`

**2. [Rule 1 - Bug/Regressão de teste] `Phase119_1/NpsAreaD1Test` tinha 2 testes que verificavam a chave `motivo`, removida por este quick task**
- **Found during:** `--filter=Phase119_1` após Task 1
- **Issue:** `test_faltante_traz_motivo_sem_contato_quando_falta_email_e_digisac` e `test_faltante_traz_motivo_sem_link_quando_ja_tem_email_cadastrado` afirmavam `$faltante['motivo'] === 'sem_contato'/'sem_link'` — exatamente a chave que o ajuste 1 remove de propósito.
- **Asserção ANTES (teste 1):** `$this->assertSame('sem_contato', $faltante['motivo']);` + `$this->assertTrue($faltante['conta_nota_1']);`
  **Asserção DEPOIS** (renomeado `test_faltante_sem_contato_nao_expoe_motivo_e_continua_contando_nota_1`): `$this->assertArrayNotHasKey('motivo', $faltante);` + `$this->assertTrue($faltante['conta_nota_1']);` — a prova de D5 (contato não afeta nota 1) **NÃO foi tocada**, só a asserção sobre `motivo` foi realinhada (de "vale X" para "a chave nem existe").
- **Asserção ANTES (teste 2):** `$this->assertSame('sem_link', $faltante['motivo']);`
  **Asserção DEPOIS** (renomeado `test_faltante_com_contato_cadastrado_tambem_nao_expoe_motivo`): `$this->assertArrayNotHasKey('motivo', $faltante);`
- **Files modified:** `tests/Feature/Phase119_1/NpsAreaD1Test.php`
- **Verification:** `--filter=Phase119_1` → 108/108 passed (baseline exata).
- **Committed in:** `d26bc8ca`

**3. [Rule 1 - Bug/Regressão de teste] `Phase116/NpsFloorAreaNpsTest::test_empresa_nao_elegivel_sem_survey_no_mes_nao_altera_medias_e_aparece_em_faltantes` esperava a empresa não-elegível APARECENDO em Faltantes com `conta_nota_1=false`**
- **Found during:** `--filter=Phase116` após Task 1
- **Issue:** este teste (D3, Fase 116) provava o comportamento ANTIGO — empresa sem estrategista continuava na lista, só não contava nota 1. O ajuste 4 supera esse comportamento de propósito: agora ela nem aparece.
- **Asserção ANTES:**
  ```php
  $this->assertNotNull($faltante, 'empresa sem NENHUM survey no mês deve continuar aparecendo em faltantes (D3).');
  $this->assertFalse($faltante['conta_nota_1'], 'empresa NÃO elegível nunca conta nota 1 (NPSMAN-07).');
  ```
- **Asserção DEPOIS** (teste renomeado `..._nao_aparece_em_faltantes`):
  ```php
  $faltante = collect($props['faltantes'])->firstWhere('company_id', $empresaSemSurvey->id);
  $this->assertNull($faltante, 'empresa sem estrategista não deve mais aparecer em Faltantes (ajuste 4).');
  ```
  As duas asserções sobre os CARDS (`$props['cards']['empresa']['media'] === 1.0` e `['total'] === 1`, que prova que a empresa não-elegível não distorce a média) **não foram tocadas** — continuam provando que só a empresa COM survey pesa na média.
- **Files modified:** `tests/Feature/Phase116/NpsFloorAreaNpsTest.php`
- **Verification:** `--filter=Phase116` → 79/79 passed (baseline exata).
- **Committed in:** `d26bc8ca`

---

**Nenhuma asserção foi afrouxada — todas foram REALINHADAS para a regra nova, com o mesmo nível de especificidade (ou mais: `assertArrayNotHasKey` é mais específico que checar o valor de uma chave que deixou de existir).** Nenhuma cobertura foi removida; os 2 testes da `Phase119_1` inclusive continuam provando D5 (contato não afeta nota 1) e os 2 testes do `Phase116` continuam provando que a média dos cards não é distorcida pela empresa não-elegível.

**Total deviations:** 3 auto-fixed (Rule 1, todos regressões de teste diretamente causadas pelos ajustes 1 e 4 desta task — nenhuma regra de negócio nova foi introduzida além do que o plano pedia).
**Impact on plan:** Nenhum. Os 3 testes descreviam comportamento que os próprios ajustes 1/4 do usuário substituem de propósito; corrigir era necessário para a suíte continuar verde sem mascarar a mudança de regra.

**Nota adicional (não é bug, é organização de commit):** Tasks 1 e 2 do plano foram implementadas juntas no mesmo commit (`40cdf2fc`) em vez de 2 commits separados — o colapso de grupo da Task 2 depende diretamente da reestruturação do laço de `$faltantes` feita na Task 1 (campos `empresas_count`/`conta_nota_1_count`), e ambas foram escritas e testadas na mesma sessão antes do primeiro commit.

## Issues Encountered

None além das deviations acima.

## Verification

| Suíte | Resultado medido | Baseline herdada | Regressão? |
|---|---|---|---|
| `--filter=NpsFaltantesGrupoEResponsavel` (novo) | 8 passed | — (arquivo novo) | n/a |
| `--filter=NpsFaltantesPorModelo` | 3 passed | 3 passed | Não |
| `--filter=NpsFiltroPorPessoa` | 3 passed | 3 passed | Não |
| `--filter=Phase119_1` | 108 passed | 108 passed | Não (exata) |
| `--filter=Phase116` | 79 passed | 79 passed | Não (exata) |
| `--filter=Desempenho` | 14 failed / 94 passed | 14 failed / 94 passed | Não — herdada de outra sessão (commit `25a958b3`, `AdmanMetricDiffService`), não relacionada a este quick task |
| `--filter=Nps` | 5 failed / 547 passed | 5 failed / 543 passed | Não — as 5 falhas são as mesmas herdadas (2 `ConsolidarMesJanelaNpsTest`, 1 `JanelaNpsBonusTest`, 2 de `expires_at` de survey manual dependentes de data); +4 no total de passed vem dos 4 testes novos da Task 1 já existentes no momento desta medição específica (antes dos 4 da Task 2 serem adicionados ao mesmo arquivo) |
| `npm run build` | exit code 0 | — | — |

Comando usado para os testes: `$env:Path = "C:\xampp\php;$env:Path"; php artisan test --filter=<Nome>` (PowerShell, PHP fora do PATH).

## Known Stubs

None.

## Threat Flags

None — sem novo endpoint, sem nova superfície de auth. O modal de detalhe do link só LÊ campos já presentes no payload de `surveys.data` (mesmo escopo de carteira/permissão que já existia).

## Self-Check: PASSED

- [x] `app/Http/Controllers/NpsController.php` modificado, existe
- [x] `resources/js/Pages/Nps/Index.jsx` modificado, existe
- [x] `tests/Feature/NpsFaltantesGrupoEResponsavelTest.php` existe (8 testes)
- [x] Commit `40cdf2fc` existe (Tasks 1+2 backend)
- [x] Commit `d26bc8ca` existe (fix de testes pré-existentes)
- [x] Commit `f8268f88` existe (Task 3 frontend)
- [x] `grep sem_contato` em NpsController.php → vazio
- [x] `grep de_grupo` em NpsController.php → presente na closure do survey
- [x] `grep empresasElegiveis` → chamada antes do `foreach ($setores...)`
- [x] `grep calcular(` → exatamente 1 ocorrência, fora do laço por empresa
- [x] `npm run build` → exit 0
- [x] Task 4 (checkpoint visual) — **aprovada pelo usuário** ("aprovado", sem ajuste pedido); deploy autorizado
