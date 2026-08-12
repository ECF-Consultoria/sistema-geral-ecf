---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
verified: 2026-08-12T23:00:00Z
status: passed
score: 6/6 must-haves verificados
overrides_applied: 0
gaps: []
post_verification_fixes:
  - truth: "D-05 (128-CONTEXT.md, decisão acrescentada no plan-check): atribuir um serviço a um GRUPO de empresas (CompanyGroupController::atribuirServico) nunca dispara geração de contrato administrativo"
    status: resolved
    resolved_at: 2026-08-12
    commit: 39974658
    resolution: >
      `CompanyGroupController::atribuirServico()` agora envolve o laço de criação de
      `ContratoServico` em `ContratoServico::withoutEvents()`, suprimindo o
      `ContratoServicoGatilhoObserver` para este call site em lote — exatamente o mecanismo que a
      D-05 pedia como caminho mais direto. Teste
      `tests/Feature/Phase128/CompanyGroupAtribuirServicoNaoGeraContratoTest.php` (2 casos) prova
      ZERO `ContratoAssinatura`, ZERO `GerarContratoAssinaturaJob` despachado e ZERO chamada HTTP
      ao atribuir serviço a um grupo de N empresas, e confirma que o comportamento de negócio
      original (criar/pular `contratos_servico`) continua intacto.
    artifacts:
      - path: "app/Http/Controllers/CompanyGroupController.php"
        note: "foreach de atribuirServico() agora roda dentro de ContratoServico::withoutEvents()"
      - path: "tests/Feature/Phase128/CompanyGroupAtribuirServicoNaoGeraContratoTest.php"
        note: "2 testes novos: zero efeito colateral do gate + preservação do skip de duplicados"
  - truth: "WR-01 (128-REVIEW.md): GatilhoContratoAdministrativoService::avaliar() classificava empresa com ZERO ContratoServico ativos como isento em vez de aguardando_comercial/sem_servico"
    status: resolved
    resolved_at: 2026-08-12
    commit: be275b9c
    resolution: >
      `avaliar()` só entra no ramo de isenção por serviço quando `contratosServico` NÃO está vazia
      (`Collection::contains()` numa coleção vazia sempre devolve `false`, o que fazia zero
      serviço parecer "todos isentos"). Empresa sem nenhum `ContratoServico` ativo agora cai
      normalmente no 1º portão (`calcularUniversais()`) e recebe `aguardando_comercial` com a
      pendência `sem_servico`. O caso legítimo de isenção (empresa com contrato ativo e todos os
      serviços isentos, ex.: só Polos) foi preservado — suíte `GatilhoContratoPendenciaTest`
      permanece 100% verde, incluindo o teste pré-existente `empresa_so_com_polos_e_isenta_...`.
      Teste novo `empresa_sem_nenhum_contrato_servico_ativo_fica_aguardando_comercial_nunca_isenta`
      cobre `avaliar()` e `dispararSeElegivel()`.
    artifacts:
      - path: "app/Services/Contratos/GatilhoContratoAdministrativoService.php"
        note: "isenção por serviço agora condicionada a contratosServico->isNotEmpty()"
      - path: "tests/Feature/Phase128/GatilhoContratoPendenciaTest.php"
        note: "1 teste novo cobrindo o caso zero-serviço"
verification_note: >
  Verificação inicial (2026-08-12T23:00:00Z) encontrou 1 gap bloqueador (D-05) e a revisão de
  código encontrou 1 warning adicional (WR-01, mesmo arquivo). Ambos corrigidos em correção
  direta pós-verificação (fora de um novo plano GSD), autorizada pelo usuário, com teste dedicado
  para cada achado. Suíte combinada `--filter="Phase124|Phase125|Phase126|Phase127|Phase128"`
  reexecutada após as correções: 268 passed / 899 assertions (baseline pré-fix era 265/879 — os 3
  testes novos somaram 3 testes e 20 assertions, zero regressão). `status` ajustado de
  `gaps_found` para `passed`.
---

# Phase 128: Gatilhos do fluxo em modo observação (v22.0) Verification Report

**Phase Goal:** A decisão de gerar contrato passa a acontecer nos dois pontos de entrada de
empresa (webhook HubSpot e cadastro manual do Comercial), rodando lado a lado com o roteamento
automático de hoje — sem desligar nada.
**Verified:** 2026-08-12
**Status:** passed (após correção pós-verificação — ver `post_verification_fixes` no frontmatter)
**Re-verification:** No — verificação inicial + correção direta dos 2 achados (D-05 bloqueador + WR-01
warning) autorizada pelo usuário, com teste dedicado para cada um. Não gerou plano GSD novo.

## Goal Achievement

### Observable Truths

| # | Truth (ROADMAP Success Criteria) | Status | Evidência |
|---|---|---|---|
| 0 | Lista de serviços que exigem contrato é dado configurável; Polos nunca entra no fluxo administrativo, continua indo direto ao operacional, e não aparece pendente | ✓ VERIFIED | `servicos.exige_contrato` (migration `2026_08_13_100001_...`), `Servico::exigeContrato()`, isenção checada em 2 pontos (`GatilhoContratoAdministrativoService::avaliar()` + laço do `ContratoClicksignService`). Gate 3 (128-GATE.md): catálogo real medido no MariaDB, `Polos` é o único `exige_contrato=0`; empresa fictícia só-Polos cadastrada via `ComercialController::store()` → 0 `ContratoAssinatura`, `MlbEmpresa` presente. `ExigeContratoTest` (5/5), `GatilhoContratoPendenciaTest` (6/6) verdes |
| 1 | Empresa via webhook HubSpot, serviço exige contrato, sem pendência → `PendenciasComerciaisService::calcular` roda como gate; `ContratoClicksignService::iniciarParaEmpresa()` chamado de verdade (envelope real no sandbox); empresa continua roteada na mesma hora | ✓ VERIFIED | Gate 1 (128-GATE.md): 3 envelopes reais criados no sandbox Clicksign pelo caminho do webhook (`aea3c36b…`, `a8b25922…`, `d0e46b0a…`), medidos via kernel HTTP real + HMAC real, não `Http::fake()`. Bug real de produção achado e corrigido durante a medição (`ContratoPdfService::formatarData()` quebrava com `data_vencimento` nulo) — 2 testes de regressão adicionados |
| 2 | Empresa cadastrada à mão pelo Comercial passa pelo MESMO gate e mesmo disparo do caminho HubSpot (decisão A4/D-01 aplicada) | ✓ VERIFIED | `PendenciasComerciaisService::calcularUniversais()` (4 pendências universais, D-01) consumida pelo mesmo `GatilhoContratoAdministrativoService`. `ComercialController::store()` e `HubspotWebhookController` chamam o mesmo `dispararSeElegivel()`. Bug pré-existente corrigido nesta fase: `nome_contato` era validado mas nunca persistido em `Company::create()` — corrigido no plano 04 (teria quebrado SC2 quando a Fase 133 ligar o gate). `GatilhoContratoComercialTest` (2/2), `PendenciasUniversaisTest` (7/7) verdes |
| 3 | Com pendência comercial em aberto, empresa fica `aguardando_comercial` e ZERO chamada à Clicksign | ✓ VERIFIED | `GatilhoContratoAdministrativoService::avaliar()` retorna `aguardando_comercial` antes de qualquer I/O. `GatilhoContratoPendenciaTest::empresa_com_pendencia_comercial_dispararseelegivel_nao_faz_nenhuma_chamada_http` usa `Http::assertNothingSent()`. Gate 3 confirma zero chamada Clicksign para empresa só-Polos |
| 4 | Empresa nunca deixa de ser roteada ao operacional imediatamente; flag `administrativo_bloqueio_ativo` continua desligada | ✓ VERIFIED | `InvarianteRoteamentoTest` (4/4) — deliberadamente usa serviço "Assessoria" (que **de fato** cria `MlbEmpresa` via `EmpresaOperacionalRouter`) para provar o invariante em cenário que realmente exercita o roteamento, inclusive com o gate mockado para explodir (`\RuntimeException`) sem desfazer `Company`/`MlbEmpresa`. Gate 2 (128-GATE.md) mede o mesmo invariante contra dado real: `MlbEmpresa` criada 1s após a `Company`, na mesma rodada de um `ContratoAssinatura` real. Achado do gate ("Gestão sozinho não cria `MlbEmpresa`") é mecânica pré-existente do `EmpresaOperacionalRouter` (só `polos`/`assessoria`/`incubadora` criam ficha operacional, decisão da Fase 124) — não é uma falha desta fase, e não compromete a prova porque a suíte automatizada já usa um serviço que a exercita de verdade |
| D-05 | Atribuir serviço a um GRUPO de empresas (`CompanyGroupController::atribuirServico`) nunca gera contrato — decisão acrescentada no plan-check do CONTEXT desta fase, com risco explicitamente descrito ("10 empresas = 10 contratos de uma vez") | ✓ **RESOLVIDO** (correção pós-verificação, commit `39974658`) | `atribuirServico()` agora envolve o `foreach` em `ContratoServico::withoutEvents()`. Teste `CompanyGroupAtribuirServicoNaoGeraContratoTest` (2/2) prova ZERO `ContratoAssinatura`, ZERO job, ZERO chamada HTTP, preservando o comportamento de negócio original. Ver `post_verification_fixes` no frontmatter |

**Score:** 6/6 truths verificadas (D-05 corrigida em correção direta pós-verificação, fora de um plano
GSD novo, ver frontmatter). Achado adicional de revisão (WR-01, mesmo arquivo/serviço) também
corrigido na mesma rodada.

### Required Artifacts

| Artefato | Esperado | Status | Detalhes |
|---|---|---|---|
| `database/migrations/2026_08_13_100001_add_exige_contrato_to_servicos_table.php` | coluna `exige_contrato` boolean default true, Polos isento na própria migration | ✓ VERIFICADO | Código confere exatamente com o plano; `default(true)`, `UPDATE` só por `nome='Polos'`, sem `whereIn` |
| `app/Models/Servico.php` | `exigeContrato()`, `scopeExigeContrato()`, cast boolean, fillable | ✓ VERIFICADO | Confirmado por leitura; `exige_contrato` também entrou em `logOnly()` do activity log |
| `app/Services/Comercial/PendenciasComerciaisService.php` | `calcularUniversais()` + 4 helpers privados compartilhados | ✓ VERIFICADO | `calcular()` intocado (early-return HubSpot preservado); `calcularUniversais()` reusa os mesmos helpers |
| `app/Services/Contratos/GatilhoContratoAdministrativoService.php` | orquestrador único (isenção → reentrância → 1º portão → disparo), try/catch total, guard estático | ✓ VERIFICADO | Código lido integralmente; nenhum `$company->save()/update()` dentro do arquivo; sequência exatamente como o plano descreve |
| `app/Services/Clicksign/ContratoClicksignService.php` | skip de serviço isento dentro do laço, motivo `servico_isento` | ✓ VERIFICADO | Confirmado nos testes de fluxo (`GatilhoContratoHubspotTest`, `GatilhoContratoComercialTest`) |
| `app/Http/Controllers/Api/HubspotWebhookController.php` | chamada ao gate fora da `DB::transaction()`, pós-commit | ✓ VERIFICADO | Linha 275, após `notificarComercialSePendente()`, fora do `DB::transaction()` de `criarEmpresa()` (linha 525) |
| `app/Http/Controllers/ComercialController.php` | chamada ao gate fora da `DB::transaction()`, pós-transaction | ✓ VERIFICADO | Linha 618, após activity log e notificação, fora do `DB::transaction()` (linha 522-577) |
| `app/Observers/CompanyGatilhoContratoObserver.php` | `updated()` restrito a `email_cliente`/`cnpj`/`nome_contato` | ✓ VERIFICADO | `wasChanged()` como primeira linha; sem `created()`, conforme justificado |
| `app/Observers/ContratoServicoGatilhoObserver.php` | `created()`/`updated()` restrito, `DB::afterCommit()` no `created()` | ✓ VERIFICADO — call site em lote agora suprimido | O Observer em si está correto; `CompanyGroupController::atribuirServico()` agora suprime explicitamente via `ContratoServico::withoutEvents()` (correção pós-verificação, commit `39974658`) |
| `.planning/phases/128.../128-GATE.md` | 3 medições reais contra o sandbox Clicksign | ✓ VERIFICADO | Lido na íntegra; 3 envelopes reais, achados documentados, limpeza confirmada, nenhum segredo/token no documento |

### Key Link Verification

| De | Para | Via | Status | Detalhes |
|---|---|---|---|---|
| `HubspotWebhookController::processar()` | `GatilhoContratoAdministrativoService::dispararSeElegivel` | pós-commit | ✓ WIRED | Confirmado por leitura de código, fora da transação |
| `ComercialController::store()` | `GatilhoContratoAdministrativoService::dispararSeElegivel` | pós-transaction | ✓ WIRED | Confirmado por leitura de código, fora da transação |
| `GatilhoContratoAdministrativoService` | `PendenciasComerciaisService::calcularUniversais` | 1º portão | ✓ WIRED | Confirmado |
| `GatilhoContratoAdministrativoService` | `ContratoClicksignService::iniciarParaEmpresa` | disparo | ✓ WIRED | Confirmado |
| `Company` (model) | `CompanyGatilhoContratoObserver` | `#[ObservedBy]` | ✓ WIRED | Confirmado |
| `ContratoServico` (model) | `ContratoServicoGatilhoObserver` | `#[ObservedBy]` | ✓ WIRED | Aplica-se a todo call site que cria/edita `ContratoServico`; `CompanyGroupController` agora suprime explicitamente (ver linha abaixo) |
| `CompanyGroupController::atribuirServico()` | `ContratoServicoGatilhoObserver` (indireto, via model event) | `ContratoServico::withoutEvents()` | ✓ **SUPRIMIDO CONFORME D-05** | Correção pós-verificação (commit `39974658`): o laço roda dentro de `withoutEvents()`, o Observer não dispara, `CompanyGroupAtribuirServicoNaoGeraContratoTest` prova zero efeito colateral |

### Behavioral Spot-Checks

| Comportamento | Comando | Resultado | Status |
|---|---|---|---|
| Suíte da fase (36 testes, pós-fix) | `artisan test --filter=Phase128` | 36 passed (107 assertions) | ✓ PASS (reexecutado após a correção pós-verificação; 33 originais + 3 novos: 2 de D-05 + 1 de WR-01) |
| Baseline Fase 124 | `artisan test --filter=Phase124` | 16 passed (65 assertions) | ✓ PASS — zero regressão |
| Baseline Fase 127 | `artisan test --filter=Phase127` | 66 passed (221 assertions) | ✓ PASS — zero regressão |
| Combinado 124+125+126+127+128 (pós-fix) | `artisan test --filter="Phase124\|Phase125\|Phase126\|Phase127\|Phase128"` | 268 passed (899 assertions) | ✓ PASS — baseline pré-fix era 265/879; +3 testes/+20 assertions dos 2 achados corrigidos, zero regressão |
| `CompanyGroupController::atribuirServico()` gera 0 contratos para N empresas | `CompanyGroupAtribuirServicoNaoGeraContratoTest` (2 testes) | 2 passed | ✓ PASS (correção pós-verificação, commit `39974658`) |
| `GatilhoContratoAdministrativoService::avaliar()` com zero `ContratoServico` ativo → `aguardando_comercial`, nunca `isento` | `GatilhoContratoPendenciaTest::empresa_sem_nenhum_contrato_servico_ativo_...` | 1 passed | ✓ PASS (correção pós-verificação, commit `be275b9c`) |

### Probe Execution

Não aplicável — esta fase não declara nem usa `scripts/*/tests/probe-*.sh`. A verificação de "medição real" desta fase é o gate humano documentado em `128-GATE.md` (Task 2 do plano 06), que foi lido na íntegra e cujas evidências (ids de envelope, timestamps, catálogo real) foram conferidas.

### Requirements Coverage

| Requisito | Plano de origem | Descrição | Status | Evidência |
|---|---|---|---|---|
| FLUXO-08 | 128-01, 128-03, 128-06 | Lista de serviços que exigem contrato é dado configurável; Polos isento e não aparece pendente | ✓ SATISFEITO — já marcado `[x]` em REQUIREMENTS-v22.md, e a marcação está correta pela evidência de código e do Gate 3 | `servicos.exige_contrato`, isenção em 2 camadas, catálogo real medido |
| REDE-06 | 128-02, 128-03, 128-04, 128-05, 128-06 | O bloqueio do operacional pode rodar em produção em modo observação (construído mas inerte) antes de ser ligado de verdade | ⚠️ **VER NOTA ABAIXO** — desmarcado em REQUIREMENTS-v22.md (`Pending`), mas a leitura mais correta do enunciado é que ele **está** satisfeito no nível que esta fase controla | Mecanismo construído e inerte, provado por Gate 1/2/3 + suíte automatizada; a flag `administrativo_bloqueio_ativo` continua `'0'` |

**Nota sobre REDE-06 (item 1 das notas do orquestrador):** o executor deixou REDE-06 desmarcado
argumentando que o requisito exige deploy em produção, não autorizado nesta fase. Lendo o texto
literal do requisito — "O bloqueio do operacional **pode rodar** em produção em modo observação
(**construído mas inerte**) antes de ser ligado de verdade" — a leitura mais correta é sobre
**capacidade**: o código está pronto para rodar em produção em modo observação, não que ele
**já esteja** rodando lá agora. Três evidências sustentam essa leitura:
1. O próprio ROADMAP rotula esta fase como "**o coração do REDE-06 (modo observação)**" — não
   delega a satisfação do requisito para uma fase futura.
2. "Cutover para produção" é explicitamente **Fase 132**, uma fase inteira à parte — se REDE-06
   exigisse deploy real, ele só poderia ser marcado depois da Fase 132, o que tornaria a frase do
   ROADMAP ("coração do REDE-06" nesta fase) sem sentido.
3. REDE-01 — requisito irmão, sobre a mesma flag ("um admin consegue desligar o bloqueio... sem
   precisar de deploy") — já está marcado `Done` desde a Fase 124, sem que produção tenha rodado o
   mecanismo; mesmo padrão de leitura já foi aplicado antes.

Isto **não é um gap de funcionalidade** — o mecanismo em si está correto e testado (Gate 1/2/3 +
33 testes automatizados). É uma imprecisão de rastreamento de requisitos. Não force a marcação por
conta própria; registrado aqui para decisão humana.

### Anti-Patterns Found

Nenhum marcador de débito (`TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`) genuíno encontrado nos
arquivos modificados por esta fase. Os falsos-positivos de grep (`TODOS` em português, `PLACEHOLDER`
como constante de negócio deliberada em `ContratoPdfService::PLACEHOLDER = 'A DEFINIR'`) foram
conferidos manualmente e são código legítimo, não débito técnico.

### Human Verification Required

Nenhum item pendente de verificação humana — as medições que exigiam ambiente real (sandbox
Clicksign) já foram feitas e documentadas em `128-GATE.md`, com evidência bruta suficiente para
verificação por leitura (ids de envelope, timestamps, resultado do catálogo real).

### Outros pontos das notas do orquestrador — resolvidos, sem gap

- **Gate 2 / "Gestão sozinho não cria `MlbEmpresa`" (item 2):** confirmado que o invariante do SC4
  é genuinamente provado. `InvarianteRoteamentoTest` foi desenhado deliberadamente com o serviço
  "Assessoria" (que **de fato** aciona `EmpresaOperacionalRouter::criarFicha()`), não com "Gestão" —
  então a suíte automatizada exercita o roteamento de verdade, não um caso vazio. O achado do Gate 2
  humano é sobre uma mecânica pré-existente da Fase 124 (só `polos`/`assessoria`/`incubadora` geram
  `MlbEmpresa`), não uma lacuna desta fase.
- **`withoutEvents()` em 3 suítes pré-existentes / unique constraint (item 3):** isolamento legítimo
  de fixture, não risco escondido. As 3 suítes (`GatilhoContratoPendenciaTest` do plano 03,
  `ContratoClicksignServiceTest` e `IdempotenciaContratoTest` da Fase 127) testam serviços de camada
  inferior isoladamente, criando `ContratoServico` fora do fluxo real de controller (sem
  `DB::transaction()`). Sem `withoutEvents()`, o novo Observer dispararia como efeito colateral do
  SETUP do teste, não do comportamento sendo medido. A interação real Observer+fluxo HTTP é testada
  à parte, com eventos LIGADOS, em `ReavaliacaoAutomaticaTest` (6/6), incluindo um teste dedicado de
  contagem de invocações que prova que o laço não corre solto. O `unique constraint` que uma das
  suítes chegou a bater durante o desenvolvimento é a trava composta da Fase 127 (D-06) funcionando
  como última linha de defesa contra o double-dispatch que só ocorria por causa do padrão de fixture
  sem transação — não evidência de risco de contrato duplicado em produção.
- **D9 errada sobre "Gestão de ADS Shopee" (item 4):** sem impacto. A migration `2026_08_13_100001`
  foi desenhada deliberadamente para **não** depender da grafia dos 8 serviços que exigem contrato
  (`default(true)` + isenção só por nome exato de `Polos`), justamente porque a pesquisa já
  registrava confiança MÉDIA nesse nome. Nenhum código desta fase assumiu a grafia errada.
- **Migration pulada localmente por incompatibilidade do MariaDB (item 5):**
  `2026_07_07_100005_add_dedup_key_to_nps_surveys` é de outra área (NPS), não tocada por esta fase,
  e o Gate 3 mediu o catálogo `servicos` diretamente contra o MariaDB local após "94 migrations
  pendentes aplicadas" — sem indicação de que a migration pulada afetou a tabela `servicos` ou
  qualquer artefato desta fase. Sem risco para esta verificação.

### Gaps Summary

A fase entrega, com evidência sólida (código lido, testes reexecutados, gate humano com envelopes
reais no sandbox), os 5 Success Criteria do ROADMAP (SC0-SC4). A engenharia dos dois pontos oficiais
de entrada (webhook HubSpot + cadastro Comercial) e da reavaliação automática está correta, testada
em múltiplas camadas, e o invariante "empresa nunca deixa de ser roteada" é genuinamente provado —
inclusive com o gate mockado para explodir.

**Status atual: sem gaps abertos.** Esta verificação inicial havia encontrado um gap bloqueador
(D-05) e a revisão de código (`128-REVIEW.md`) havia encontrado, à parte, um warning (WR-01) no
mesmo serviço. Os dois foram corrigidos em **correção direta pós-verificação** (fora de um novo
plano GSD, autorizada explicitamente pelo usuário), cada um com teste dedicado provando o
comportamento correto:

1. **D-05 (bloqueador) — resolvido, commit `39974658`.** A própria fase, durante o plan-check,
   identificou e documentou formalmente (D-05, em `128-CONTEXT.md`) uma terceira porta de entrada
   de `ContratoServico` — `CompanyGroupController::atribuirServico()` — como um risco
   quase-incidente: um único clique de atribuição em lote poderia gerar N contratos reais para N
   clientes de uma vez, estourando a janela de rate-limit da Clicksign e disparando assinaturas
   indevidas. Nenhum dos 6 planos originalmente executados havia implementado a proteção que a
   própria D-05 exigia. Agora o laço de criação roda dentro de
   `ContratoServico::withoutEvents()`, suprimindo o `ContratoServicoGatilhoObserver` só neste call
   site, e `CompanyGroupAtribuirServicoNaoGeraContratoTest` (2 testes) prova ZERO
   `ContratoAssinatura`, ZERO job despachado e ZERO chamada HTTP na atribuição em grupo, com o
   comportamento de negócio original (criar/pular `contratos_servico`) preservado.

2. **WR-01 (warning) — resolvido, commit `be275b9c`.** `GatilhoContratoAdministrativoService::
   avaliar()` classificava empresa com ZERO `ContratoServico` ativos como `isento` em vez de
   `aguardando_comercial`/`sem_servico`, por `Collection::contains()` devolver `false` numa
   coleção vazia. A isenção por serviço agora só é avaliada quando há pelo menos um
   `ContratoServico` ativo; o caso legítimo de isenção (empresa só-Polos) permanece coberto pelos
   testes pré-existentes, e um teste novo cobre explicitamente o caso zero-serviço.

Suíte combinada `--filter="Phase124|Phase125|Phase126|Phase127|Phase128"` reexecutada após as duas
correções: **268 passed / 899 assertions** (baseline pré-fix: 265/879 — ganho de 3 testes/20
assertions dos dois achados, zero regressão). `status` do frontmatter ajustado de `gaps_found` para
`passed`.

---

_Verified: 2026-08-12_
_Verifier: Claude (gsd-verifier)_
