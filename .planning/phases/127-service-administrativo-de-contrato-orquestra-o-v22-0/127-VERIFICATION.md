---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
verified: 2026-08-12T00:00:00Z
status: passed
score: 5/5 must-haves verificados (Success Criteria do ROADMAP)
overrides_applied: 0
---

# Fase 127: Service administrativo de contrato — orquestração (v22.0) — Verificação

**Phase Goal:** Existe um único ponto que decide se uma empresa está pronta para contrato, monta o
envelope na Clicksign com prazo e lembrete configurados, e nunca gasta uma chamada HTTP com dado que
já sabia estar incompleto.
**Verified:** 2026-08-12
**Status:** passed
**Re-verification:** Não — verificação inicial.

## Goal Achievement

### Observable Truths (5 Success Criteria do ROADMAP)

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | `iniciarParaEmpresa()` recusa continuar (sem chamar a Clicksign) quando falta e-mail, CNPJ válido ou nome do contato — erro claro antes de PDF/envelope | ✓ VERIFIED | `ContratoDadosMinimosService::faltantes()` roda ANTES de qualquer I/O em `ContratoClicksignService::iniciarParaEmpresa()` (linhas 56-60). Provado por `ContratoClicksignServiceTest::empresa_sem_email_cliente_e_recusada_sem_nenhuma_chamada_http` e `..._com_cnpj_invalido_ou_sem_nome_contato_e_recusada`, ambos com `Http::assertNothingSent()` + `Queue::assertNothingPushed()` + `ContratoAssinatura::count() === 0`. **Estendido durante o gate (127-07) para cobrir o 5º bug da milestone**: config da própria ECF (signatários fixos) vazia também bloqueia ANTES de I/O — `ConfiguracaoEcfBloqueiaTest::config_da_ecf_vazia_bloqueia_SEM_gastar_nenhuma_chamada_http` prova, com empresa PERFEITA + config vazia, `Http::assertNothingSent()` e `assertDatabaseCount('contrato_assinaturas', 0)`. Achado real: sem esta guarda o fluxo queimava 3 chamadas da janela de 20/min antes de falhar no 1º signatário (`email - não pode ficar em branco`), medido contra o sandbox real em 12/08/2026 e corrigido no commit `50415659`. |
| 2 | Empresa com dados completos gera envelope com documento, signatários e requisitos corretos, e lembrete nativo (`remind_interval`) configurado — sem scheduler próprio | ✓ VERIFIED | Medido contra o SANDBOX REAL no gate (127-GATE.md, Task 1): `GET /envelopes/{id}` reconsultado devolveu `status=draft`, 1 documento, 4 signatários, 8 requisitos (2 por signatário), `remind_interval=7` — todos conforme o pedido. `routes/console.php` não contém nenhuma entrada de scheduler para lembrete de contrato (grep vazio) — o lembrete é 100% nativo da Clicksign via `remind_interval` no payload de criação (`GerarContratoAssinaturaJob::handle()`, `$dadosEnvelope`). |
| 3 | Um contrato pode ter prazo de assinatura diferente do padrão, refletido no requisito criado na Clicksign | ✓ VERIFIED | D-03 medida DUAS VEZES: (a) na criação — `POST /envelopes` aceita `deadline_at`/`remind_interval` e devolve exatamente os valores pedidos (10 dias/7, medido 11/08); (b) **GATE 1 do plano 127-07 mediu que o prazo SOBREVIVE à ativação feita pela interface web** — reconsulta pela API depois do usuário ativar mostrou `deadline_at` **idêntico ao segundo** entre antes e depois. Testes automatizados: `GerarContratoAssinaturaJobTest::deadline_at_usa_prazo_dias_da_coluna_quando_preenchido` / `..._usa_padrao_da_config_quando_prazo_dias_e_null`; `ContratoClicksignServiceTest::prazo_e_lembrete_sao_gravados_quando_informados_e_ficam_null_por_padrao`. |
| 4 | Decisão A2 tomada e aplicada: falha no meio da montagem tem comportamento determinístico e testado | ✓ VERIFIED | D-04: cancela e recomeça (`DELETE /envelopes/{id}` em `draft`, medido 204 na Fase 126). `ClicksignClient::montarEnvelopeComum()` guarda `envelope_id` e cancela no `catch`, propagando a exceção ORIGINAL (não a de cancelamento). Testado em `ClicksignParaNoRascunhoTest::montar_envelope_por_modelo_com_ativar_false_ainda_cancela_no_meio_da_falha` (e par para `montarEnvelope()`) e em `GerarContratoAssinaturaJobTest::falha_no_meio_da_montagem_propaga_excecao_original_sem_duplicar_rollback`, que confirma `clicksign_envelope_id` fica `null` após a falha e o `DELETE` foi de fato enviado. |
| 5 | `iniciarParaEmpresa()` chamado duas vezes para a mesma empresa não cria dois envelopes — idempotente por si só | ✓ VERIFIED | A garantia é o **índice único composto do banco** (`ca_empresa_servico_andamento_uniq` em `(company_id_em_andamento, servico_id_em_andamento)`), não um `where` de código: `ContratoClicksignService::iniciarParaEmpresa()` captura `QueryException` com `(string) $e->getCode() === '23000'` (SQLSTATE, portável SQLite/MariaDB) — não `errorInfo[1]`, que é MySQL-specific e se comportaria diferente nos testes. `IdempotenciaContratoTest::a_garantia_real_e_a_constraint_nao_o_guard_de_leitura` cria um contrato "por fora" do guard de leitura para forçar a constraint a agir, e passa. `IdempotenciaContratoTest::o_catch_reconhece_a_violacao_pelo_sqlstate_nao_pelo_errorinfo_do_mysql` lê o código-fonte e confirma `23000` presente e `errorInfo` ausente. |

**Score:** 5/5 truths verified

### D-06 (extensão da D-05, achada no cruzamento com a Fase 126) — N contratos por empresa

Verificado por evidência direta, não por dedução:
- `empresa_com_dois_servicos_diferentes_tem_dois_contratos_em_rascunho_ao_mesmo_tempo` (`ContratoAssinaturaServicoTest`) — mesma empresa + serviços diferentes coexistem.
- `empresa_com_dois_contratos_do_mesmo_servico_em_andamento_estoura_query_exception` — mesma empresa + MESMO serviço é barrado por `QueryException`.
- `IdempotenciaContratoTest::chamar_duas_vezes_nao_duplica_contratos_nem_jobs` — empresa com 2 serviços chamada 2x gera exatamente 2 contratos (não 4), `Queue::assertPushed(..., 2)`.
- Migration `2026_08_12_100000_...` cria o índice composto **antes** de dropar o antigo (armadilha MariaDB 1553), confirmado por teste estático (`MigrationsFase127ConvencoesTest::indice_composto_novo_e_criado_antes_de_dropar_o_indice_antigo`) que lê a posição textual no arquivo.

### `enviado_em` — confirmado ausente do código de produção (D-02)

`grep -rn "enviado_em" app/` mostra a coluna declarada em `$fillable`/`$casts`/`activitylog` de
`ContratoAssinatura.php`, mas **nenhuma atribuição de valor** em `ContratoClicksignService` ou
`GerarContratoAssinaturaJob` — só comentários explicando por que não é tocado. Testado
explicitamente por `GerarContratoAssinaturaJobTest::job_nao_grava_enviado_em`.

### Os 4 bugs herdados da Fase 126 — continuam travados por teste

| Bug | Onde vive a trava | Status |
|---|---|---|
| `communicate_by` nunca enviado | `ClicksignClient::adicionarSignatario()` só manda `name`/`email`/`group` | ✓ código e testes da Fase 126 intactos (não tocados por esta fase) |
| Cancelamento por `DELETE`, não `PATCH status=canceled` | `ClicksignClient::cancelarEnvelope()` usa `DELETE /envelopes/{id}` | ✓ reusado sem alteração; testado em `ClicksignParaNoRascunhoTest` (novo, desta fase) |
| `filename` termina em `.docx` (não `.pdf`) para documento por modelo | Guard em `anexarDocumentoPorModelo()` | ✓ `GerarContratoAssinaturaJob` usa `"contrato-{$contrato->id}.docx"` |
| Download por `links.files.original` (não `attributes.files`) | `ClicksignSondarModelo.php:479` | ✓ arquivo não tocado por esta fase, download em si é escopo da Fase 129 |

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `database/migrations/2026_08_12_100000_...` | Trava (empresa+serviço), índice novo antes de dropar o antigo | ✓ VERIFIED | Lida linha a linha; ordem correta; `servico_id` nullable por limitação do SQLite, obrigatoriedade real no hook do model |
| `database/migrations/2026_08_12_100001_...` | `clicksign_template_id` em `servicos` | ✓ VERIFIED | Coluna nullable, sem armadilha aplicável |
| `app/Models/ContratoAssinatura.php` | Hook `saving` alimenta as 2 colunas derivadas; `servico_id` obrigatório | ✓ VERIFIED | `booted()` lança `RuntimeException` se `servico_id` vazio; `emAndamentoDoServico()`/`emAndamentoDaEmpresa()` consultam a FONTE (não o espelho) |
| `app/Services/Contratos/ContratoDadosMinimosService.php` | Checagem sem I/O, 5 blocantes + config ECF separada | ✓ VERIFIED | `faltantes()` puro; `faltantesDaConfiguracaoEcf()` acrescentado no gate (127-07) |
| `app/Services/Clicksign/ContratoClicksignService.php` | Ponto único `iniciarParaEmpresa()` | ✓ VERIFIED | Sequência bloqueio→config ECF→serviços→criação+catch→dispatch, na ordem documentada |
| `app/Services/Clicksign/ClicksignClient.php` | `$ativar` parametrizável (D-02) | ✓ VERIFIED | `montarEnvelope()`/`montarEnvelopePorModelo()`/`montarEnvelopeComum()` |
| `app/Jobs/GerarContratoAssinaturaJob.php` | Worker de fila, rate limit + guard de reentrega | ✓ VERIFIED | `WithoutOverlapping` + `RateLimited('clicksign-envelope')`; guard `filled($contrato->clicksign_envelope_id)` |
| `app/Models/Servico.php::clicksignTemplateId()` | Modelo por serviço com fallback global | ✓ VERIFIED | `ModeloPorServicoTest` cobre os 3 casos (próprio, fallback, nenhum) |
| `.planning/phases/.../127-GATE.md` | 3 gates medidos contra API real | ✓ VERIFIED | Task 1 + GATE 1/2/3, todos "MEDIDO", nenhum "NÃO MEDIDO" |
| `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §11 | Seção da Fase 127 | ✓ VERIFIED | `grep -c "Fase 127"` > 0 |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `ContratoClicksignService::iniciarParaEmpresa()` | `ContratoDadosMinimosService` | chamada direta antes de qualquer I/O | ✓ WIRED | Testado com `Http::assertNothingSent()` |
| `ContratoClicksignService` | `GerarContratoAssinaturaJob` | `::dispatch($contrato)->delay(...)` | ✓ WIRED | `Queue::assertPushed(GerarContratoAssinaturaJob::class, N)` |
| `GerarContratoAssinaturaJob` | `ClicksignClient::montarEnvelopePorModelo(..., ativar: false)` | chamada direta no `handle()` | ✓ WIRED | `Http::assertNotSent()` da chamada de ativação |
| `GerarContratoAssinaturaJob` | `Servico::clicksignTemplateId()` | guard antes de qualquer HTTP | ✓ WIRED | `servico_sem_modelo_falha_antes_de_qualquer_requisicao` |
| `ContratoAssinatura` (hook `saving`) | índice `ca_empresa_servico_andamento_uniq` | `save()`/`create()`, nunca `update()` de query builder | ✓ WIRED | Docblock explícito sobre onde o hook NÃO roda; todo o código de produção usa `create()`/`save()` |

### Behavioral Spot-Checks / Probe Execution

Não aplicável no sentido de probe scripts — esta fase usa **gate humano medido contra a API real do
sandbox e (com autorização) de produção**, documentado e reconferido linha a linha em `127-GATE.md`.
Reproduzi a leitura do gate e cruzei cada afirmação com o código correspondente; não repeti as
chamadas HTTP (proibido pelo ambiente desta verificação — nem sandbox nem produção).

Testes automatizados executados por mim nesta verificação (não apenas lidos):

| Comando | Resultado |
|---|---|
| `php artisan test --filter="Phase125\|Phase126\|Phase127"` | **214 passed (723 assertions)** — bate exatamente com o baseline declarado |
| `php artisan test` (suíte completa, sem filtro) | Interrompida por limite de tempo desta verificação após ~1480 linhas de output; nenhuma falha nova encontrada nas centenas de testes percorridos além das já conhecidas como pré-existentes (ver seção "Achados não-bloqueantes") |

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|---|---|---|---|---|
| CLICK-02 | 127-02, 127-05, 127-06 | Sistema cria o envelope com documento, signatários e requisitos | ✓ SATISFIED | `REQUIREMENTS-v22.md:255` marcado `Done`; código + gate medido |
| CLICK-08 | 127-04, 127-05, 127-07 | Lembrete automático nativo, sem scheduler próprio | ✓ SATISFIED | `REQUIREMENTS-v22.md:261` marcado `Done`; `remind_interval` na criação, sem entrada em `routes/console.php` |
| DADOS-06 | 127-01, 127-04, 127-05, 127-06, 127-07 | Prazo por contrato | ✓ SATISFIED | `REQUIREMENTS-v22.md:253` marcado `Done`; GATE 1 mediu sobrevivência à ativação humana |
| REDE-05 | 127-03, 127-06 | Validação de dados mínimos antes de PDF/envelope, exibida na tela do Administrativo | ⚠️ PARCIAL (correto conforme roadmap) | `REQUIREMENTS-v22.md:272` **corretamente** marcado `Pending`, com nota explícita: "Metade cumprida na Fase 127 ... a exibição na tela do Administrativo é da Fase 131 — só marcar [x] quando as duas metades existirem." Não é um gap desta fase — é o comportamento documentado corretamente. A metade que cabia à Fase 127 (checagem sem I/O integrada ao orquestrador) está completa e testada. |

Nenhum requisito órfão encontrado: os 4 IDs declarados no ROADMAP (CLICK-02, CLICK-08, DADOS-06,
REDE-05) aparecem distribuídos corretamente entre os planos 127-01 a 127-07, e o estado final em
`REQUIREMENTS-v22.md` é coerente com o que o código sustenta — inclusive o caso do REDE-05, onde o
documento resiste corretamente à tentação de marcar `[x]` cedo demais.

### Anti-Patterns Found

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` real encontrado nos arquivos de produção
desta fase (`app/Services/Clicksign/`, `app/Services/Contratos/`, `app/Jobs/GerarContratoAssinaturaJob.php`,
`app/Models/ContratoAssinatura.php`, `app/Models/Servico.php`, migrations). As duas ocorrências de
`PLACEHOLDER` em `ContratoVariaveisModeloService.php` são o uso do enum documentado
`ContratoPdfService::PLACEHOLDER` (decisão de produto do checkpoint 126-06 para os campos "A DEFINIR"),
não um marcador de dívida técnica.

### Achados não-bloqueantes (WARNING / informativo)

1. **ROADMAP.md desatualizado no bookkeeping do plano 127-07.** A linha 1701 ainda mostra
   `- [ ] 127-07-PLAN.md` e a linha 1692 mostra `**Plans:** 6/7 plans executed`, mas
   `127-07-SUMMARY.md` existe, está completo, e `127-GATE.md` fecha os 3 gates como "MEDIDO" —
   inclusive com autorização e consulta real à conta de produção. Isto é só falha de checkbox no
   ROADMAP.md, não uma lacuna de execução. Recomendo marcar `[x]` e atualizar para `7/7` antes de
   avançar para a Fase 128.
2. **`CLICKSIGN_PROD_TOKEN` ainda presente no `.env` local.** O próprio `127-07-SUMMARY.md` já
   sinaliza isto como pendência de limpeza ("Remover `CLICKSIGN_PROD_TOKEN` do `.env` quando não for
   mais necessário"). Confirmei que a chave está de fato só no `.env` (gitignored, não versionado) e
   que nenhum código de produção a referencia (`grep clicksign config/services.php` não a lista) —
   risco baixo, mas fica registrado.
3. **Suíte completa (`php artisan test` sem filtro) revela mais falhas pré-existentes do que as 3
   declaradas no contexto desta verificação** (`AdminFechamentoControllerTest`, `Phase14MigrationTest`,
   `Phase42\AnalyzeCompanyMlWindowQuarantineTest`). Encontrei adicionalmente `CalcularFaixaTest`,
   `CompanyServiceTypeTest`, `Phase39\MercadoLivreSugadoresProviderTest`, `DesempenhoShopeeScoreTest`,
   `DevControllerTest`, `ExampleTest`, `FechamentoMigrationTest`,
   `Phase110\ConsolidarMesMargemResilienteTest`, `Phase116\NpsMaterializarNaoRespondidosCommandTest`
   falhando. Confirmei por `grep` que nenhum desses arquivos de teste referencia `clicksign`/
   `ContratoAssinatura`, e por `git log` que nenhum dos arquivos de produção por trás deles
   (ex.: `AdminController.php`) foi tocado por commits das Fases 125/126/127 — são falhas
   pré-existentes e não-relacionadas a esta fase (ex.: `CalcularFaixaTest` quebra por
   `ArgumentCountError` em `AdminController::__construct()`, um problema de injeção de dependência
   alheio ao Clicksign). A suíte completa foi interrompida antes de terminar por limite de tempo
   desta verificação; a suíte filtrada por `Phase125|Phase126|Phase127` (o escopo real desta fase)
   rodou até o fim com **214/214 verdes**, batendo exatamente com o baseline declarado.

### Human Verification Required

Nenhuma. Os 3 gates que exigiam ação humana (ativação real no sandbox, autorização para consultar
produção, confronto do modelo de produção) já foram executados e fechados dentro do próprio plano
127-07, com evidência registrada em `127-GATE.md` — não há checkpoint pendente para esta verificação
reabrir.

### Gaps Summary

Nenhum gap bloqueante. Os 5 Success Criteria do ROADMAP estão verificados com evidência de código,
teste automatizado E medição real contra a API (sandbox e, com autorização, produção) — não apenas a
alegação do SUMMARY. A D-06 (extensão descoberta durante o planejamento desta fase) está corretamente
implementada e testada nos dois sentidos (permite N serviços, bloqueia duplicata do mesmo serviço). O
achado do 5º bug da milestone durante o próprio gate (config ECF vazia queimando chamadas) foi
corrigido e coberto por teste antes do fechamento da fase — exatamente o tipo de descoberta que
justifica medir contra a API real em vez de confiar só em `Http::fake()`.

Os 3 itens da seção "Achados não-bloqueantes" são recomendações de limpeza/bookkeeping, não lacunas
de comportamento: nenhum deles impede a Fase 128 de prosseguir.

---

_Verified: 2026-08-12_
_Verifier: Claude (gsd-verifier)_
