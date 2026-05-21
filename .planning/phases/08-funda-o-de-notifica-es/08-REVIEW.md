---
phase: 08-funda-o-de-notifica-es
reviewed: 2026-05-21T00:00:00Z
depth: standard
files_reviewed: 6
files_reviewed_list:
  - database/migrations/2026_05_21_100001_create_notifications_table.php
  - database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php
  - app/Notifications/Categoria.php
  - app/Notifications/BaseNotification.php
  - app/Support/Permissions.php
  - tests/Feature/Notifications/Phase8FoundationTest.php
findings:
  critical: 0
  warning: 3
  info: 5
  total: 8
status: issues_found
---

# Phase 8 — Relatório de Code Review

**Reviewed:** 2026-05-21
**Depth:** standard (per-file, PHP 8.2+ idioms, segurança, schema)
**Files Reviewed:** 6
**Status:** issues_found (advisory — não bloqueia)

## Resumo

Fundação sólida e bem documentada. O schema da tabela `notifications` segue o stub canônico Laravel 12 (text + cast array), o `BaseNotification` trava corretamente `via()` em `['database']` e o `Categoria` enum elimina a classe de bug "categoria forjada" via `from()` no read path. A suíte de testes é abrangente (7 testes implementados) e a hipótese de short-circuit do admin é checada explicitamente.

Os findings abaixo são **todos advisory**:

- 3 **WARNING** — assimetria up/down na migration de rename (fix DEF-08-01), gap de cobertura em `Categoria::from()` para defesa anti-tampering, e janela TOCTOU teórica no discovery de check constraints.
- 5 **INFO** — comentários desalinhados com o código, dependência implícita em ordem de migrations, ausência de teste para a invariante "via() é fixo", magic strings em testes e ausência de doc sobre por que `down()` não recria os check constraints.

Nenhum bloqueador. A fundação pode avançar para Phase 9.

## Warnings

### WR-01: `down()` da migration de rename não recria os check constraints removidos em `up()`

**File:** `database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php:71-80`
**Issue:** O `up()` remove (em MySQL 8) os check constraints `json_valid()` autocriados antes do rename, o que é correto. Mas o `down()` apenas renomeia colunas de volta — não recria os check constraints. Após um rollback nesse ambiente, as colunas `publication_permissions` / `publication_role` / `publication_meta` voltam com o nome original mas SEM o `json_valid()` que o MySQL 8 anexa quando o Laravel declara `json('...')`. Resultado: schema pós-rollback diverge do schema pré-migration em modo silencioso. Isso afeta tanto integridade quanto qualquer migration de cleanup que ainda espere o constraint.
**Fix:** Documentar explicitamente que `down()` é uma reversão *best-effort* (intenção: rollback de emergência, não round-trip simétrico) ou recriar via `DB::statement('ALTER TABLE users ADD CONSTRAINT ... CHECK (json_valid(...))')` dentro de um `if (driver === mysql)`. Mínimo aceitável: comentário pt-BR no topo do `down()` avisando da assimetria.

### WR-02: `BaseNotification::toArray()` não valida invariante `meta` em runtime — defesa anti-tampering parcial

**File:** `app/Notifications/BaseNotification.php:43,75-85`
**Issue:** O docblock garante "`meta` é sempre `array` (defaulta a `[]`, NUNCA `null`)" e o consumidor da Phase 9 vai ler `$row->data['meta']` sem coalesce. O type hint `array $meta = []` cobre o caminho do construtor, mas nada impede uma subclasse de Phase 11/12 fazer `$this->meta = null` (`array` em PHP não é readonly aqui; promotion é `public` writable). Se acontecer, `toArray()` vai retornar `'meta' => null` e quebrar o read path. Isso é uma assimetria entre invariante documentada e invariante imposta.
**Fix:** Tornar a property `readonly` (PHP 8.2 suporta em promoted properties) — assim qualquer reassignment dispara `Error` em tempo de escrita:
```php
public function __construct(
    public readonly string $titulo,
    public readonly string $mensagem,
    public readonly Categoria $categoria,
    public readonly ?int $autorUserId = null,
    public readonly ?string $url = null,
    public readonly array $meta = [],
) {}
```
Ganho colateral: `Categoria` enum + readonly = `Notification` virtualmente imutável após construção, melhorando T-08-12 (defesa contra tampering via cache stale ou serialização entre dispatch e channel).

### WR-03: Discovery de check constraints via `information_schema` ignora schemas múltiplos e tem janela TOCTOU

**File:** `database/migrations/2026_05_20_200008_rename_legacy_columns_in_users_table.php:32-49`
**Issue:** A query filtra por `CONSTRAINT_SCHEMA = DATABASE()`, o que é correto para o caso single-tenant atual, mas (a) outro processo no mesmo MySQL pode estar criando/dropando check constraints no mesmo schema, e (b) `LIKE '%publication_permissions%'` pode dar falso-positivo se algum check constraint não relacionado mencionar a string no `CHECK_CLAUSE` (improvável aqui, mas vale registrar). Adicionalmente, `LIKE '%`setor`%'` com backticks pode não casar — o MySQL 8 às vezes normaliza `CHECK_CLAUSE` removendo backticks ou adicionando alias `\`users\`.\`setor\``.
**Fix:** Substituir LIKE por casamento mais estrito (REGEXP com word boundary) ou cruzar com `information_schema.COLUMNS` para limitar aos constraints que de fato referenciam colunas-alvo:
```php
$columns = ['publication_permissions','publication_role','publication_meta','setor','cargo'];
foreach ($columns as $col) {
    DB::select("
        SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CHECK_CLAUSE REGEXP CONCAT('[[:<:]]', ?, '[[:>:]]')
    ", [$col]);
}
```
Não é crítico — o `try/catch (\Throwable)` no DROP CHECK já protege contra "constraint not found" — mas reduz risco de falso-positivo e ruído de log.

## Info

### IN-01: Docblock do `Phase8FoundationTest` está desatualizado em relação ao código

**File:** `tests/Feature/Notifications/Phase8FoundationTest.php:27-28`
**Issue:** O comentário diz "Nesta Slice 1 apenas o Test 1 fica implementado; os demais entram como esqueletos `markTestIncomplete`". Mas o arquivo tem os 7 testes implementados com asserts reais (`assertTrue`, `assertSame`, `assertContains`, etc.). Nenhum `markTestIncomplete` aparece em todo o arquivo.
**Fix:** Atualizar docblock para refletir o estado final: "Os 7 testes da phase 8 estão implementados nesta suíte". Comentários enganosos drift e viram fonte falsa de verdade para o próximo dev.

### IN-02: Ausência de teste validando que `Categoria::from('invalida')` dispara `ValueError`

**File:** `app/Notifications/Categoria.php:17-18`
**Issue:** O docblock promete "valores inválidos disparam `ValueError` em tempo de leitura — defesa contra 'categoria forjada' persistida fora do dispatch". Essa invariante é uma propriedade nativa do `BackedEnum` do PHP 8.2 — não há código novo a testar — mas o consumidor da Phase 9 vai depender dela, e nada na suíte da Phase 8 documenta-a. Sem o teste, uma mudança futura para `Categoria::tryFrom()` (que retorna `null` em vez de throwear) poderia passar despercebida.
**Fix:** Adicionar teste curto:
```php
public function test_categoria_from_dispara_value_error_em_valor_invalido(): void {
    $this->expectException(\ValueError::class);
    Categoria::from('categoria_forjada');
}
```

### IN-03: Smoke test não cobre invariante "via() trava em ['database']"

**File:** `tests/Feature/Notifications/Phase8FoundationTest.php:215-261`
**Issue:** A documentação do `BaseNotification` é enfática em "Subclasses concretas das Phases 11/12 NÃO devem sobrescrever este método", mas nada na suíte verifica que `via($user) === ['database']`. Se uma subclasse futura adicionar acidentalmente `mail` ou `broadcast`, só descobriríamos em produção (ou pelo crash do channel mail/broadcast em ambiente que não configurou).
**Fix:** Adicionar uma asserção no `test_base_notification_persiste_payload_canonico`:
```php
$this->assertSame(['database'], $notif->via($user));
```
Custa 1 linha e tranca D-01 explicitamente.

### IN-04: Magic strings de permissão duplicadas — `'notificacoes.criar'` vs `Permissions::NOTIFICACOES_CRIAR`

**File:** `tests/Feature/Notifications/Phase8FoundationTest.php:70,72,96,110,140,200`
**Issue:** Os asserts misturam a string literal `'notificacoes.criar'` com a constante `Permissions::NOTIFICACOES_CRIAR`. O Test 2 deliberadamente prova que ambas batem (linha 71), mas Tests 4/5/6 usam apenas a string. Se a key for renomeada (improvável mas possível), os Tests 4/5/6 quebrariam silenciosamente no compile path errado.
**Fix:** Substituir literais por `Permissions::NOTIFICACOES_CRIAR` em todos os locais exceto no Test 2 (que existe especificamente para travar a string canônica). Padrão minimal-diff:
```php
$this->assertContains(Permissions::NOTIFICACOES_CRIAR, Permissions::AUTO_LIDERANCA);
// ...
$this->assertTrue($admin->hasPermission(Permissions::NOTIFICACOES_CRIAR));
```

### IN-05: Migration `create_notifications_table` não documenta dependência de ordem com `users`

**File:** `database/migrations/2026_05_21_100001_create_notifications_table.php:30-37`
**Issue:** Embora `notifications` não tenha FK declarada para `users` (polimórfica via `morphs`), a coluna `notifiable_id`/`notifiable_type` em prática sempre aponta para `users.id`. Em ambientes que rodam migrations parcialmente (ex.: filtro por path), nada documenta que `users` precisa existir antes. Não é bug — é só ausência de comentário.
**Fix:** Adicionar comentário no docblock da migration: "Não declara FK em `notifiable_*` por design (polimórfica), mas `users` deve existir antes — única notifiable do MVP v3.0." Custa zero risco e ajuda navegação futura.

## Notas Positivas (não-findings, registro para auditoria)

- `BaseNotification::toArray()` retorna o `BackedEnum->value` em `categoria` (linha 80) — caminho correto. Bug clássico de Laravel/Inertia é serializar enum direto e quebrar JSON; aqui o autor já mitigou.
- Comentário sobre `Notification::fake()` (Pitfall 1) está no docblock E no inline do smoke (linhas 31-32, 236-237) — defesa em profundidade contra futuro dev que "limpe" o teste com `fake()`.
- O Test 6 invoca `$user->refresh()` após `attach` e justifica o motivo (mitigação T-08-12, linha 161-163). Esse tipo de comentário é alto valor para code archaeology.
- `Permissions::all()` é construído iterando `catalog()` (linha 152-159) — propriedade que evita "chaves fantasma" (constante existente mas não exposta no catálogo).
- O `if (driver === mysql)` no rename migration (linha 31) está corretamente posicionado e o comentário explicativo (linhas 28-30) explica o motivo do guard.

---

_Reviewed: 2026-05-21_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
