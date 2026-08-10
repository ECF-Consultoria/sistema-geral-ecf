---
phase: 125-estrutura-de-dados-administrativa-v22-0
verified: 2026-08-10T00:00:00Z
status: passed
score: 5/5 truths verificadas
overrides_applied: 0
deferred:
  - truth: "Excluir uma empresa não deve destruir contratos assinados e a evidência jurídica dos signatários (CR-01 do code review)"
    addressed_in: "Decisão explícita do usuário"
    evidence: "Registrado no briefing de verificação como 'achado do review deixado em aberto de propósito' — mudança de comportamento em código pré-existente (CompanyController::destroy), fora do escopo textual da Fase 125. Nenhuma fase futura do roadmap assume esse conserto; fica registrado aqui como dívida rastreável, não como gap desta fase."
---

# Fase 125: Estrutura de dados administrativa (v22.0) Verification Report

**Phase Goal:** O processo de assinatura de cada empresa passa a ser um dado persistido e
consultável, com estados que nunca confundem "cliente recusou" com "a API caiu".
**Verified:** 2026-08-10
**Status:** passed
**Re-verification:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Toda empresa pode ter um `ContratoAssinatura` com estado atual e datas de envio, assinatura e liberação — schema comprovado por migration + factory testadas (SC1) | ✓ VERIFIED | Migration `2026_08_10_100000_create_contrato_assinaturas_table.php` cria `status`, `enviado_em`, `assinado_em`, `liberado_em`. `ContratoAssinaturaFactory::definition()` cria contrato válido sem argumento. Suíte reexecutada por este verificador: 30/30 testes verdes (122 assertions), incluindo `factory_cria_contrato_valido_sem_argumento`. Evidência de produção (MariaDB real) no `125-03-SUMMARY.md`: `php artisan db:table contrato_assinaturas` confirma as 13 colunas e os 3 índices nomeados |
| 2 | Cada signatário registrado com papel, contato e situação individual, vinculado ao contrato correto (SC2) | ✓ VERIFIED | Migration `2026_08_10_100001_...` cria `papel`, `nome`, `email`, `cpf`, `situacao`. `ContratoAssinaturaSignatario::contrato()` (belongsTo) + `ContratoAssinatura::signatarios()` (hasMany) confirmados no código lido. Teste `signatario_pertence_ao_contrato_correto` prova as duas pontas do vínculo, isolando 2 contratos distintos. Verde na reexecução |
| 3 | `recusado` e `expirado` são estados próprios, nunca colapsados em `cancelado`/`erro` — teste unitário comprova que os dois nunca resolvem para o mesmo valor interno (SC3) | ✓ VERIFIED | `ContratoAssinatura::STATUS_TODOS` tem os 7 valores distintos, incluindo `recusado` e `expirado` como constantes próprias (não há mapeamento que os funda em `cancelado`/`erro` em código algum da fase). Teste `recusado_e_expirado_nunca_resolvem_para_cancelado_nem_erro` confere as 4 constantes distintas duas a duas e faz round-trip via banco. Nota de calibração: o teste é parcialmente tautológico (compara literais de constante), mas não há nenhum código de mapeamento nesta fase que pudesse colapsar os estados — a fonte real de risco desse colapso só nascerá na Fase 129 (webhook Clicksign→status), fora do escopo aqui |
| 4 | Gate empírico #9 resolvido e refletido no campo de payload do signatário (SC4) | ✓ VERIFIED | `evidencia_signer` (JSON, bloco `data.signer` íntegro) + `auths`/`ip_address`/`assinado_em` promovidos a colunas — todas as chaves do payload real documentado em `CLICKSIGN-SANDBOX-EMPIRICO.md` §3 (`sign_as`, `key`, `auths`, `address`, `latitude/longitude`, flags de biometria, `federal_data_validation`, `phone_number*`, `communicate_by`, `url`) estão presentes na migration (linhas 90-114). Teste `round_trip_evidencia_signer` confirma round-trip. Fixture `assinou()` reflete a forma real (confirmado por leitura direta do arquivo) |
| 5 | A mesma empresa não consegue ter dois contratos em andamento ao mesmo tempo (D-01, sustenta SC1) | ✓ VERIFIED (com ressalva) | Índice único `ca_company_andamento_uniq` sobre `company_id_em_andamento`, alimentado pelo hook `saving()`. Teste `empresa_nao_pode_ter_dois_contratos_em_andamento` (model) e `indice_unico_barra_segundo_contrato_em_andamento` (schema puro via `DB::table()`) verdes. Confirmado em produção que o índice existe no InnoDB (`ca_company_andamento_uniq`, `Non_unique=0`) — mas **a unicidade não foi exercida por um INSERT duplicado real em produção** (o `125-03-SUMMARY.md` documenta essa ressalva honestamente). Julgamento: para o Success Criteria 1 ("schema comprovado por migration + factory testadas"), a barra exigida é o par migration+teste, não o INSERT em produção — e esse par está cumprido, com o índice adicionalmente confirmado existir no engine real. Aceito como verificado, sem exigir gate humano adicional (ver Gaps Summary) |

**Score:** 5/5 truths verificadas

### Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|-------------|----------|
| 1 | CR-01 do code review — `cascadeOnDelete` de `Company` até os signatários permite que excluir uma empresa apague contratos assinados e PII dos signatários sem trilha | Decisão explícita do usuário | Confirmado por leitura do código atual: `Company` não usa `SoftDeletes` (`app/Models/Company.php:13`) e `CompanyController::destroy()` (linha 797) permanece sem guard. É mudança de comportamento em código pré-existente (fora do texto da Fase 125), registrada para decisão humana futura, não como gap desta fase |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` | Tabela `contrato_assinaturas` com índices nomeados à mão | ✓ VERIFIED | Existe; contém `ca_company_andamento_uniq`, `ca_clicksign_envelope_uniq`, `ca_company_status_idx`, todos `<64 chars`. Confirmado rodando em produção (evidência `db:table` no 125-03-SUMMARY) |
| `app/Models/ContratoAssinatura.php` | Constantes dos 7 estados, casts, `LogsActivity`, sincronia da coluna auxiliar | ✓ VERIFIED | `STATUS_TODOS` (7), `STATUS_EM_ANDAMENTO` (2), cast `array` em `servicos_snapshot`, `LogsActivity` com `logOnly` fechado (sem `servicos_snapshot`) |
| `database/factories/ContratoAssinaturaFactory.php` | Factory + state `emAndamento()` | ✓ VERIFIED | `definition()` sem argumento obrigatório; `emAndamento()`/`assinado()` presentes |
| `tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` | Prova de schema | ✓ VERIFIED | 5 testes, reexecutados verdes |
| `tests/Feature/Phase125/ContratoAssinaturaModelTest.php` | Prova dos 7 estados, cast array, guard D-01 | ✓ VERIFIED | 8 testes, reexecutados verdes |
| `database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php` | Tabela com FKs e índices nomeados à mão | ✓ VERIFIED | Contém `cas_contrato_fk`, `cas_user_fk`, `cas_contrato_situacao_idx`, `cas_clicksign_signer_idx`; `user_id` nullable **antes** da FK `nullOnDelete` na mesma leitura. Confirmado em produção (`ON DELETE SET NULL`, `DEFAULT NULL`) |
| `app/Models/ContratoAssinaturaSignatario.php` | Constantes de papel/situação, casts JSON/datetime | ✓ VERIFIED | `PAPEL_TODOS` (3), `SITUACAO_TODAS` (3), sem interseção com `ContratoAssinatura::STATUS_TODOS`; **sem** `LogsActivity`, conforme decisão documentada (T-125-10) |
| `database/factories/ContratoAssinaturaSignatarioFactory.php` | Factory + states `assinou()`/`recusou()` | ✓ VERIFIED | Presentes, mais `daEcf()`. Valores de PII sintéticos (RFC 5737 + UUID fixo) após correção do WR-07, confirmada por leitura direta do arquivo |
| `tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php` | Prova de schema (colunas, índices, `user_id` nullable) | ✓ VERIFIED | 5 testes, reexecutados verdes |
| `tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php` | Prova do vínculo, round-trip da evidência, preservação dos dados copiados | ✓ VERIFIED | 6 testes, reexecutados verdes |
| `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` | Guarda estática das 3 cicatrizes de schema | ✓ VERIFIED (com limitação conhecida) | 6 testes, reexecutados verdes. WR-01 do review (não corrigido): a guarda usa regex `\$table->(unique\|index\|foreign)\(` e não cobre `foreignId()->constrained()` — a FK autogerada de `company_id` (39 chars) passa despercebida pela guarda, mesmo cabendo hoje no limite. Risco baixo (39 << 64) mas a guarda não é tão abrangente quanto o nome dos testes promete |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `app/Models/Company.php` | `App\Models\ContratoAssinatura` | `hasMany contratoAssinaturas()` | ✓ WIRED | Confirmado por leitura; alteração aditiva (per `git diff` citado nos SUMMARYs) |
| `app/Models/ContratoAssinatura.php` | coluna `company_id_em_andamento` | hook `saving()` | ✓ WIRED (com ressalva documentada) | Hook confirmado presente e correto. Ressalva: caminhos que pulam eventos Eloquent (`update()` de query builder, `insert()`, `upsert()`, `saveQuietly()`) não passam pelo hook — documentado explicitamente no docblock atual (corrigido pós-review, commit `9dda8190`), sem consumidor atual que exercite esses caminhos nesta fase |
| `app/Models/ContratoAssinatura.php` | `App\Models\ContratoAssinaturaSignatario` | `hasMany signatarios()` | ✓ WIRED | Confirmado por leitura do model |
| `contrato_assinatura_signatarios.user_id` | `users.id` | FK `cas_user_fk`, nullable + `nullOnDelete` | ✓ WIRED | Confirmado no schema E em produção (`ON DELETE SET NULL`, `DEFAULT NULL`) |

### Data-Flow Trace (Level 4)

Não aplicável — fase de schema puro, sem controller/rota/tela que renderize dado dinâmico
(confirmado: nenhuma rota nova, nenhum controller tocado, threat model da própria fase declara
"única fronteira é código PHP interno → banco").

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Suíte completa da fase passa | `& "C:\xampp\php\php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase125/` | 30/30 testes, 122 assertions, `OK` | ✓ PASS |
| Regressão de vizinhança (Company/schema recente) | `& "C:\xampp\php\php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase122/ tests/Feature/BonusInvalidacaoEmpresaTest.php` | 54/54 testes, 199 assertions, `OK` | ✓ PASS |
| Guarda estática não se autoinvalida (comentários contêm `enum`/`1830`/`1059`) | Reconfirmado por leitura do teste + reexecução da suíte completa (30/30 verde) | Confirmado presente helper de remoção de comentários (regex) que remove blocos antes de casar padrões | ✓ PASS |

### Probe Execution

Não há probes convencionais (`scripts/*/tests/probe-*.sh`) declarados nesta fase — fase de
schema Laravel, verificação via PHPUnit (já coberta acima). Nenhum probe encontrado por
`find scripts -path '*/tests/probe-*.sh'`.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|--------------|--------|----------|
| DADOS-01 | 125-01 | Registra processo de assinatura por empresa, com estado atual e datas de envio/assinatura/liberação | ✓ SATISFIED | Migration + model + factory + 13 testes; marcado `[x]` em `REQUIREMENTS-v22.md:141` |
| DADOS-02 | 125-02 | Registra cada signatário com papel, contato e situação individual | ✓ SATISFIED | Migration + model + factory + 11 testes; marcado `[x]` em `REQUIREMENTS-v22.md:142` |
| DADOS-04 | 125-01 | Recusa e prazo expirado são estados próprios, distintos de cancelamento/falha técnica | ✓ SATISFIED | `STATUS_TODOS` com 7 valores distintos; teste round-trip; marcado `[x]` em `REQUIREMENTS-v22.md:144` |

Nenhum requisito órfão: `REQUIREMENTS-v22.md` mapeia DADOS-03 para a Fase 129 (`Pending`,
linha 248) — corretamente fora do escopo desta fase, conforme `125-CONTEXT.md` declara
explicitamente na fronteira.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Models/Company.php` / `CompanyController.php:797` | — | CR-01 do review: `cascadeOnDelete` sem `SoftDeletes`/guard permite apagar contrato assinado + PII via exclusão de empresa | ⚠️ WARNING (deferred) | Ver seção Deferred acima — decisão do usuário, fora do escopo textual da fase |
| `app/Models/ContratoAssinatura.php` (hook `saving`) | 126-133 | CR-02 do review: parcialmente corrigido — docblock e guard (`emAndamentoDaEmpresa`) agora dizem a verdade e o guard não herda mais o defeito da coluna espelho, mas o helper de mutação segura (`mudarStatusEmLote`) sugerido pelo review **não foi implementado**, nem o teste de regressão (`bulk_update_de_status_nao_pode_deixar_a_empresa_travada`, IN-05) | ⚠️ WARNING | Risco real fica documentado explicitamente no código para a Fase 127 (que fará `expira_em` + expiração em lote); nenhum consumidor atual desta fase exercita o caminho vulnerável (bulk update), então não é um gap do goal desta fase — é uma dívida de robustez a carregar para a Fase 127 |
| `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` | ~117, ~145 | WR-01 do review, não corrigido: regex da guarda estática não cobre `foreignId()->constrained()`, então a FK autogerada de `company_id` (39 chars) passa sem verificação | ℹ️ INFO | Baixo risco hoje (39 << 64 chars), mas a guarda promete mais cobertura do que entrega |
| `database/factories/ContratoAssinaturaSignatarioFactory.php` | 40-49 | WR-07 do review — **corrigido** (commit `9dda8190`): IP público real e chave de signatário real substituídos por RFC 5737 e UUID sintético | — (resolvido) | Confirmado por leitura do arquivo atual |
| Demais 11 warnings (WR-02 a WR-06, WR-08 a WR-13) e 5 infos (IN-01 a IN-05) do `125-REVIEW.md` | — | Não corrigidos nesta rodada | ℹ️ INFO | Nenhum bloqueia o goal da fase (schema puro sem consumidor); a maioria é dívida explícita para as Fases 126/127/129/131 que vão escrever/ler estas tabelas pela primeira vez |

Nenhum `TBD`/`FIXME`/`XXX` encontrado nos arquivos da fase (migrations, models, factories) —
confirmado por grep direto.

### Human Verification Required

Nenhum item. Todos os must-haves desta fase são verificáveis programaticamente (schema puro,
sem UI/UX/comportamento em tempo real) e foram verificados por reexecução direta da suíte e
leitura do código atual — não apenas por confiança no SUMMARY. As duas ressalvas identificadas
durante a verificação (unicidade da D-01 sem INSERT duplicado real em produção; comportamento
de bulk update ainda não exercitado por nenhum consumidor) são notas para quando as **Fases
126/127** forem escritas, não pendências de verificação desta fase — documentadas em detalhe na
seção Gaps Summary abaixo.

### Gaps Summary

Nenhum gap bloqueia o goal desta fase. As duas tabelas (`contrato_assinaturas`,
`contrato_assinatura_signatarios`), seus models, factories e 30 testes existem, passam
(reexecutados por este verificador, não só confirmados por SUMMARY), e as migrations já rodaram
contra o MariaDB de produção real com todos os 7 índices/chaves confirmados via
`php artisan db:table` — evidência literal transcrita no `125-03-SUMMARY.md`, consistente com o
que o código local mostra hoje.

Os dois achados **críticos** do code review (`125-REVIEW.md`) foram tratados de formas
diferentes, ambas aceitáveis para o escopo desta fase de schema puro sem consumidor:

- **CR-01** (exclusão de empresa destrói contrato assinado) — deixado em aberto por decisão
  explícita do usuário, registrado como item `deferred` nesta verificação, não como gap.
- **CR-02** (docblock mentia sobre a garantia da D-01) — corrigido onde importava mais
  (documentação correta + guard `emAndamentoDaEmpresa()` não herda mais o defeito da coluna
  espelho), mas o item de robustez adicional (helper de mutação segura + teste de regressão)
  não foi implementado. Como não há nenhum consumidor desta fase que exercite bulk update, isto
  não compromete o goal atual — fica como aviso explícito no código para a Fase 127.

Duas notas para acompanhamento nas próximas fases (não são pendências desta verificação):

1. **Unicidade da D-01 sob INSERT duplicado real em produção** — o índice único
   `ca_company_andamento_uniq` foi confirmado existir no InnoDB de produção e o teste
   `indice_unico_barra_segundo_contrato_em_andamento` está verde no SQLite via `DB::table()`
   bruto, mas nenhum INSERT duplicado real chegou a ser tentado contra o MariaDB de produção
   (decisão documentada do `125-03-SUMMARY.md`, para não sujar dados de produção). Quando a
   Fase 126 gerar o primeiro contrato real, vale confirmar que um duplo clique é de fato
   recusado com `QueryException` 23000.
2. **Comportamento de `company_id_em_andamento` sob bulk update** — quando a Fase 127
   implementar a expiração por prazo, ela precisa usar `->each(fn ($c) => $c->save())` (ou
   equivalente que passe pelo hook `saving()`), nunca `ContratoAssinatura::where(...)->update([...])`.
   O aviso já está explícito no docblock do hook (corrigido pós-review); falta só o autor da
   Fase 127 respeitá-lo.

Os demais 13 achados warning/info do review permanecem em grande parte não corrigidos, mas
nenhum deles impede a fase de entregar o que prometeu: dado persistido, consultável, com
estados que não colapsam entre si.

---

_Verified: 2026-08-10_
_Verifier: Claude (gsd-verifier)_
