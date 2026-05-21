---
phase: 08-funda-o-de-notifica-es
plan: 03
subsystem: notificacoes
tags: [permissions, catalog, auto-lideranca, foundation, tdd]
requires:
  - 08-01 (tabela `notifications` migrada)
  - 08-02 (enum `Categoria` + classe abstrata `BaseNotification` + Test 7 GREEN)
provides:
  - constante `App\Support\Permissions::NOTIFICACOES_CRIAR = 'notificacoes.criar'`
  - grupo `'Notificações'` em `Permissions::catalog()` (1 entry com label + description pt-BR)
  - entrada `notificacoes.criar` em `Permissions::AUTO_LIDERANCA` (4ª posição)
  - Tests 2, 3 e 4 GREEN (5/7 da suíte canônica da Phase 8)
affects:
  - app/Support/Permissions.php
  - tests/Feature/Notifications/Phase8FoundationTest.php
tech_added: []
patterns_used:
  - 3 edições cirúrgicas em catálogo estático (mesmo padrão das constantes pré-existentes)
  - alignment-style formatting em `public const` (=  alinhado em coluna)
  - `firstWhere` da Collection para localizar entry no catalog sem depender de ordem dentro do grupo
key_files:
  created: []
  modified:
    - app/Support/Permissions.php
    - tests/Feature/Notifications/Phase8FoundationTest.php
key_decisions:
  - "D-07 implementado: constante pública `NOTIFICACOES_CRIAR = 'notificacoes.criar'` declarada entre as seções `SISTEMA_*` e `LIDERANCA_*` com PHPDoc pt-BR de 1 linha — alinhamento de `=` consistente com as constantes vizinhas (CLAUDE.md §Code Style alignment-style)."
  - "D-08 implementado: novo grupo `'Notificações'` inserido em `catalog()` na posição exata entre `'Sistema'` e `'Liderança (automático para líderes)'`. Verificado via array_keys (idx 3 → 4 → 5 — Sistema, Notificações, Liderança em sequência). 1 entry com label='Criar notificações' e description='Envia notificações manuais para usuários, setores, líderes ou todos' (texto em pt-BR mencionando 'manuais' + as 4 audiências de targeting da v3.0)."
  - "D-09 implementado: `self::NOTIFICACOES_CRIAR` adicionado como 4ª entry no array `AUTO_LIDERANCA` (após `LIDERANCA_VER_MEMBROS`), com comentário inline pt-BR `// notificações: líderes ganham automaticamente (PERM-03)`. Por D-10, `User::effectivePermissions()` já faz merge automático — nada no model precisa mudar."
  - "D-10 respeitado: `app/Models/User.php` NÃO foi editado. Tanto admin (short-circuit em `isAdmin()`) quanto líder (merge de `AUTO_LIDERANCA`) já são resolvidos pelo código existente. Verificação E2E via `hasPermission()` fica para Plan 04 (Tests 5 e 6)."
  - "Métodos `all()` e `isValid()` permanecem inalterados — varrem `catalog()` dinamicamente e captam a nova key sem código novo. Esta propriedade do design original é exatamente o que mitiga T-08-08 (chave fantasma) e Test 2 prova-a empiricamente."
  - "Test 3 usa `firstWhere('key', 'notificacoes.criar')` (Collection helper) em vez de hardcodar índice — defende contra futura reordenação ou inserção de entries adicionais no grupo `'Notificações'`."
  - "Estratégia de execução em worktree sem `vendor/` próprio (Windows + XAMPP): arquivos modificados na worktree foram copiados temporariamente para o main repo (que tem `vendor/`) para rodar `php artisan test --filter=Phase8FoundationTest`. Após validação (5 passed + 2 incomplete + 0 failed), o main repo foi restaurado ao estado pristine via `mv *.MAIN_BACKUP back`. Worktree mantém commits canônicos; merge do orquestrador trará os arquivos para o main repo de forma legítima — mesma técnica já documentada no SUMMARY do Plan 02."
metrics:
  duration_minutes: 9
  tasks_completed: 2
  files_created: 0
  files_modified: 2
  commits: 2
  completed_at: 2026-05-21T19:04:37Z
---

# Phase 08 Plan 03: Fundação de Notificações — Slice 3 Permission Catalog Summary

**One-liner:** `notificacoes.criar` registrada em 3 pontos cirúrgicos de `App\Support\Permissions` (constante pública + grupo `'Notificações'` em `catalog()` + entrada em `AUTO_LIDERANCA`) — Tests 2, 3 e 4 da suíte canônica preenchidos e GREEN; suíte ao final em 5/7 (Tests 1+2+3+4+7) + 2/7 incomplete (5+6 vão para Plan 04) + 0 failed.

## Tarefas Concluídas

| Tarefa | Nome                                                                                              | Commit  | Arquivos                                                  |
| ------ | ------------------------------------------------------------------------------------------------- | ------- | --------------------------------------------------------- |
| 1      | 3 edições cirúrgicas em `app/Support/Permissions.php` (constante + catalog + AUTO_LIDERANCA)      | cef4047 | `app/Support/Permissions.php`                              |
| 2      | Preencher Tests 2/3/4 (`permissions_all`, `catalog`, `auto_lideranca`) — 5/7 GREEN                | 66f41c1 | `tests/Feature/Notifications/Phase8FoundationTest.php`     |

## O que foi entregue

### 1. `App\Support\Permissions::NOTIFICACOES_CRIAR` — constante pública (D-07)

Inserida entre `SISTEMA_SETORES` (linha 65) e `LIDERANCA_DASHBOARD_SETOR` (linha 71)
com PHPDoc de 1 linha em pt-BR e alinhamento de `=` consistente com as constantes
vizinhas:

```php
/** Criar notificações manuais (admin sempre tem; líderes ganham via AUTO_LIDERANCA). */
public const NOTIFICACOES_CRIAR        = 'notificacoes.criar';
```

O posicionamento intermediário entre os grupos `SISTEMA_*` e `LIDERANCA_*`
sinaliza que a chave é dos dois lados — não pertence ao módulo Sistema (ainda
que admins a herdem por short-circuit) nem é puramente de liderança (também
aparece para Administrativo via UI de setores). Trata-se de uma constante
canônica que vive entre os dois grupos lógicos.

### 2. Grupo `'Notificações'` em `Permissions::catalog()` (D-08)

Inserido entre o grupo `'Sistema'` (linhas 130–134) e o grupo `'Liderança
(automático para líderes)'` (linhas 138–142):

```php
'Notificações' => [
    ['key' => self::NOTIFICACOES_CRIAR, 'label' => 'Criar notificações', 'description' => 'Envia notificações manuais para usuários, setores, líderes ou todos'],
],
```

Verificação empírica do posicionamento (smoke run via `php -r` no main repo):

| Índice | Chave de grupo                              |
| ------ | ------------------------------------------- |
| 0      | `Core (ECF Consultoria)`                    |
| 1      | `Publicações (MLB)`                         |
| 2      | `Administrativo`                            |
| 3      | `Sistema`                                   |
| **4**  | **`Notificações`** ← novo, posicionado correto |
| 5      | `Liderança (automático para líderes)`        |

Descrição em pt-BR menciona explicitamente "manuais" (envio manual, não
recepção de notificação automática) + as 4 audiências de targeting da v3.0
("usuários, setores, líderes ou todos") — corresponde literalmente ao texto
travado em D-08.

### 3. `AUTO_LIDERANCA` ganha 4ª entry (D-09)

Array passa de 3 para 4 entries:

```php
public const AUTO_LIDERANCA = [
    self::LIDERANCA_DASHBOARD_SETOR,
    self::LIDERANCA_DEFINIR_METAS,
    self::LIDERANCA_VER_MEMBROS,
    self::NOTIFICACOES_CRIAR, // notificações: líderes ganham automaticamente (PERM-03)
];
```

A entry nova vai ao final do array (após `LIDERANCA_VER_MEMBROS`), preservando
a ordem das 3 entries originais. Trailing comma já era padrão. O comentário
inline em pt-BR liga a edição ao requirement PERM-03 explicitamente.

### 4. Tests 2, 3 e 4 preenchidos (Tarefa 2)

`tests/Feature/Notifications/Phase8FoundationTest.php` — 3 métodos saíram de
`markTestIncomplete` para corpos reais com asserts:

| Teste | Asserts | Cobre |
| ----- | ------- | ----- |
| `test_permissions_all_inclui_notificacoes_criar` | 3 (`assertContains` em `all()` + `assertSame` na constante + `assertTrue` em `isValid`) | PERM-01 + propriedade "all() varre catalog dinamicamente" (mitigação de T-08-08) |
| `test_catalog_inclui_grupo_notificacoes`         | 5 (`assertArrayHasKey` + `firstWhere` + `assertNotNull` + `assertSame` label + `assertStringContainsString` "manuais") | PERM-01 (label + description pt-BR para UI da Phase 12) |
| `test_auto_lideranca_inclui_notificacoes_criar`  | 1 (`assertContains` em `AUTO_LIDERANCA`) | PERM-03 no nível de catálogo (D-09) |

Os 3 testes operam sobre o catálogo estático e não consomem o `RefreshDatabase`
(o trait está na classe mas não cria fixtures aqui — apenas garante isolamento
caso outros testes da mesma classe alterem o banco). Imports de
`App\Support\Permissions` já estavam presentes no esqueleto desde Plan 01.

## Estado da suíte `Phase8FoundationTest` ao final do plano

Execução completa (`php artisan test --filter=Phase8FoundationTest`):

```
✓ migration cria tabela notifications                  0.42s
✓ permissions all inclui notificacoes criar            0.03s
✓ catalog inclui grupo notificacoes                    0.02s
✓ auto lideranca inclui notificacoes criar             0.02s
… admin tem permissao via short circuit                0.03s  (markTestIncomplete — Plan 04)
… lider tem permissao via auto lideranca               0.02s  (markTestIncomplete — Plan 04)
✓ base notification persiste payload canonico          0.07s

Tests: 2 incomplete, 5 passed (28 assertions)
Duration: 0.79s
```

| Teste                                                | Estado            | Origem |
| ---------------------------------------------------- | ----------------- | --------------- |
| `test_migration_cria_tabela_notifications`           | **GREEN**         | Plan 01 (Slice 1) |
| `test_permissions_all_inclui_notificacoes_criar`     | **GREEN** (este plan) | Plan 03 (Slice 3) |
| `test_catalog_inclui_grupo_notificacoes`             | **GREEN** (este plan) | Plan 03 (Slice 3) |
| `test_auto_lideranca_inclui_notificacoes_criar`      | **GREEN** (este plan) | Plan 03 (Slice 3) |
| `test_admin_tem_permissao_via_short_circuit`         | `markTestIncomplete` | Plan 04 (Slice 4) |
| `test_lider_tem_permissao_via_auto_lideranca`        | `markTestIncomplete` | Plan 04 (Slice 4) |
| `test_base_notification_persiste_payload_canonico`   | **GREEN**         | Plan 02 (Slice 2) |

**Total ao final do Plan 03:** 5 GREEN / 7 (71%), 2 incomplete (Tests 5/6
ficam para Plan 04 que vai instanciar User real + Setor real e provar a
resolução via `hasPermission()`), 0 failed.

## Sequência TDD intra-plan (RED → GREEN)

| Etapa     | Estado da suíte                                                        | Causa |
| --------- | ---------------------------------------------------------------------- | ----- |
| Antes de T1 | 2/7 GREEN (Tests 1+7); Tests 2/3/4 em `markTestIncomplete`             | Estado herdado dos Plans 01+02 |
| Após T1   | 2/7 GREEN ainda — source de `Permissions.php` editado mas testes 2/3/4 continuam `markTestIncomplete`; verificação alternativa via `php -r` no main repo confirma as 3 propriedades (catálogo posicionado correto, AUTO_LIDERANCA com 4 entries, constante resolve) | Source pronto; testes ainda esqueletos |
| Após T2   | **5/7 GREEN** — Tests 2, 3, 4 preenchidos e validados via `php artisan test --filter=Phase8FoundationTest`; 28 assertions ao todo; Tests 5+6 ainda incomplete (Plan 04) | Testes preenchidos + ordem TDD respeitada (1 → 2, sequencial intra-plan W3) |

Sequencialidade intra-plan estritamente respeitada — Tarefa 2 depende da
constante `Permissions::NOTIFICACOES_CRIAR` e do grupo `'Notificações'`
existirem no source para os asserts compilarem.

## Deviations from Plan

### Auto-fixed Issues

**Nenhuma deviation Rule 1/2/3.** As 2 tarefas seguiram o `<action>` do
PLAN.md literalmente. As 3 edições em `Permissions.php` foram aplicadas
nas posições exatas indicadas pelo `<interfaces>` do plan; os 3 corpos
de teste vieram exatamente das instruções em `<action>` da Tarefa 2
(incluindo o uso de `firstWhere` para Test 3 e a verificação
`assertStringContainsString('manuais', ...)` para travar D-08).

### Out-of-scope discoveries (deferred)

Nenhum. A suíte rodou clean em SQLite via `RefreshDatabase` — DEF-08-01
(corrigido no commit `9f508f8` antes deste plan) não voltou.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. Os 3 threats do
threat_model do plano seguem com as mitigações documentadas:

- **T-08-07** (Elevation of Privilege via mutação de `AUTO_LIDERANCA`):
  mitigado estruturalmente pelo design da linguagem PHP — `public const`
  array é imutável em runtime. Verificado empiricamente: alterar
  `Permissions::AUTO_LIDERANCA[] = '...'` lança fatal "Cannot use
  temporary expression in write context". Mudança da constante exige
  commit + deploy + code review.
- **T-08-08** (Tampering via chave fantasma no catalog): mitigado pela
  propriedade do `all()` (varre `catalog()` dinamicamente). Test 2
  (`test_permissions_all_inclui_notificacoes_criar`) prova essa propriedade
  empiricamente — se a entry fosse inserida só em `AUTO_LIDERANCA` sem
  estar em `catalog()`, o teste falharia.
- **T-08-09** (Information Disclosure de descrições do catalog): aceito
  para Phase 8. `catalog()` só é exposto na Phase 12 (`/sistema/setores`)
  via middleware `EnsureUserHasRole('admin')`. Nenhuma rota nova foi
  introduzida neste plan.

## Known Stubs

Os **2 testes ainda em `markTestIncomplete`** são **stubs intencionais
herdados** que serão preenchidos exatamente no plan seguinte:

| Stub                                              | Resolvido em |
| ------------------------------------------------- | ------------ |
| `test_admin_tem_permissao_via_short_circuit`      | Plan 04 (Slice 4) |
| `test_lider_tem_permissao_via_auto_lideranca`     | Plan 04 (Slice 4) |

Cada um declara no corpo a slice futura ("Implementado em Slice 3 — Plan 03"
no markTestIncomplete original do Plan 01; o texto está datado mas o
encaminhamento agora é Plan 04 conforme replanejamento do plano de
execução — preserva-se a mensagem inalterada para não criar mudança
estética irrelevante; o `markTestIncomplete` aparece como `…` no output
do PHPUnit independente da mensagem).

Nenhum stub bloqueia o objetivo desta slice (registro do catálogo de
permissions) — PERM-01 e PERM-03 já estão cobertos no nível de catálogo.
A verificação E2E via `User::hasPermission()` (Tests 5/6) testa o
comportamento do code-path em `User::effectivePermissions()` que já existe
no model, então o que falta é apenas escrever os asserts — sem código novo
no model (D-10 trava).

## Próxima Slice

**Plan 04 — Slice 4: Authorization resolution (PERM-02 admin + PERM-03 líder E2E)**

- Preencher Test 5 (`test_admin_tem_permissao_via_short_circuit`): instanciar
  User admin via factory, asserir `$user->hasPermission('notificacoes.criar') === true`
  sem necessidade de atribuir setor/permissão.
- Preencher Test 6 (`test_lider_tem_permissao_via_auto_lideranca`): instanciar
  User não-admin, anexá-lo como líder de algum Setor, asserir
  `hasPermission('notificacoes.criar') === true` via merge de `AUTO_LIDERANCA`.
- Após Plan 04: 7/7 GREEN. Fundação da Phase 8 completa, pronta para Phase 9
  (backend de leitura + polling endpoint).

## Confirmação de pt-BR

- **`Permissions.php`:** PHPDoc da constante nova em pt-BR ("Criar
  notificações manuais (admin sempre tem; líderes ganham via AUTO_LIDERANCA).");
  comentário inline em `AUTO_LIDERANCA` em pt-BR ("notificações: líderes
  ganham automaticamente (PERM-03)"); label e description do grupo
  `'Notificações'` em pt-BR.
- **`Phase8FoundationTest.php`:** PHPDoc atualizado dos 3 testes em pt-BR
  explicando o que cada um prova (PERM-01, T-08-08, D-08, PERM-03, D-09);
  comentários inline em pt-BR ("firstWhere localiza a entry pela key...",
  "Descrição em pt-BR menciona 'manuais'...").
- **Commits:** 2 commits em pt-BR seguindo a convenção `tipo(phase-plan): descrição`.
- **Termos técnicos mantidos em inglês:** `catalog`, `array`, `assert`,
  `Collection`, `firstWhere`, `factory`, `markTestIncomplete`,
  `short-circuit`, `merge`, etc. — política do CLAUDE.md.

## Self-Check: PASSED

- `app/Support/Permissions.php` — FOUND (modificado: 3 edições aplicadas, +7 linhas)
- `tests/Feature/Notifications/Phase8FoundationTest.php` — FOUND (modificado: 3 testes preenchidos, +27 -6 linhas)
- Commit `cef4047` (Permissions edits) — FOUND no histórico
- Commit `66f41c1` (Tests 2/3/4 preenchidos) — FOUND no histórico
- `php artisan test --filter=Phase8FoundationTest` → 5 passed + 2 incomplete + 0 failed (28 assertions, 0.79s)
- Main repo restaurado ao estado pristine (sem arquivos `*.MAIN_BACKUP` residuais)
- STATE.md / ROADMAP.md NÃO foram modificados (orquestrador owner desses writes)
