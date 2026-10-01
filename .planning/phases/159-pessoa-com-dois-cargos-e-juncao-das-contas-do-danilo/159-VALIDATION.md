---
phase: 159
slug: pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-09-30
---

# Fase 159 — Estratégia de Validação

> Contrato de validação por fase, para amostragem de feedback durante a execução.

---

## Infraestrutura de teste

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.5 |
| **Config** | `phpunit.xml` (SQLite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`) |
| **Comando rápido** | `APP_BASE_PATH=C:/tmp/ecf-cargo-duplo-260930 C:/xampp/php/php.exe vendor/bin/phpunit --filter=<Padrão> <arquivo>` |
| **Comando da fase** | `APP_BASE_PATH=C:/tmp/ecf-cargo-duplo-260930 C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase159/` |
| **Suíte inteira** | NÃO rodar de uma vez — estoura 512 MB. Rodar por diretório. |
| **Tempo estimado** | ~60 s para `Phase159/` + sentinelas |

**Armadilha de shell:** `phpunit ... | tail` devolve o exit code do `tail`. Capturar o exit code antes do pipe ou redirecionar para arquivo.

---

## Taxa de amostragem

- **A cada commit de tarefa:** o arquivo de teste da tarefa (`--filter`)
- **A cada wave:** `tests/Feature/Phase159/` + sentinelas:
  - `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` (regra D-02 — média dos papéis)
  - `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php`
  - testes existentes de `UserController`/`user_setores`, `CompanyController::update`/`bulkAssign`, `SetorMembroController` que a fase tocar
- **Antes do verify:** tudo acima verde; `tests/Feature/Phase119/` com **exatamente** as 17 falhas da baseline (nem mais, nem menos)
- **Latência máxima de feedback:** 120 s

---

## Mapa de verificação por critério

| Critério (ROADMAP) | Comportamento | Tipo | Arquivo | Existe? | Status |
|---|---|---|---|---|---|
| SC1 | Duas linhas `user_setores` no mesmo setor com cargos diferentes, sem erro de unique; linha duplicada (mesmo cargo) continua barrada | feature (schema) | `tests/Feature/Phase159/UserSetoresDoisCargosTest.php` | ❌ W0 | ⬜ |
| SC1 | `/users` (`syncVinculos`) grava dois cargos no mesmo setor e remover um não apaga o outro | feature | idem | ❌ W0 | ⬜ |
| SC1 | `/admin/setores/{setor}` adiciona 2º cargo a quem já é membro; remover é por cargo (D-10) | feature | `tests/Feature/Phase159/SetorMembroDoisCargosTest.php` | ❌ W0 | ⬜ |
| SC2 | `CompanyController::update` grava a mesma pessoa como analista e estrategista; salvar de novo não apaga nenhum papel; histórico registra os dois | feature | `tests/Feature/Phase159/EmpresaMesmaPessoaDoisPapeisTest.php` | ❌ W0 | ⬜ |
| SC3 | Pessoa com dois cargos aparece nos selects de analista e de estrategista (Companies, Shopee, Distribuição) | feature | `tests/Feature/Phase159/SelectsResponsavelDoisCargosTest.php` | ❌ W0 | ⬜ |
| SC4 | `/performance?cargo=analista` e `?cargo=estrategista` mostram a pessoa nas duas, mesma nota; sem filtro, uma vez | feature | `tests/Feature/Phase159/RankingDoisCargosTest.php` | ❌ W0 | ⬜ |
| SC4 | Relatório de Bonificação e Auditoria idem | feature | idem | ❌ W0 | ⬜ |
| SC5 | Loja dupla = média das duas perguntas, loja pesa 1× (sem mudança de cálculo) | feature | `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` | ✅ | ⬜ |
| SC5 | `DesempenhoScoreService.php` intocado | git | `git diff origin/main -- app/Services/DesempenhoScoreService.php` vazio | ✅ | ⬜ |
| SC6 | Comando de junção sem `--apply` não grava nada | feature (command) | `tests/Feature/Phase159/UnificarContasCommandTest.php` | ❌ W0 | ⬜ |
| SC6 | Com `--apply`: move `company_users` sem colidir no unique, move atribuições NPS/imputações da competência ≥ corte, remove snapshots `warm_cache` do corte, NUNCA toca `consolidar_mes`, dá o cargo ao destino e desativa a origem | feature (command) | idem | ❌ W0 | ⬜ |
| SC6 | Migration de `user_setores` em MariaDB: índice novo criado antes do drop, sem 1553 | manual (MariaDB) | `SHOW INDEX FROM user_setores` | — | ⬜ |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Wave 0

- [ ] `BASELINE-TESTES.md` — estado de `tests/Feature/Phase119/` (17/29 falhas pré-existentes, hash de `DesempenhoScoreService.php`) e dos sentinelas ANTES de qualquer mudança
- [ ] `tests/Feature/Phase159/` — diretório novo
- [ ] `npm run build` antes de teste Feature que renderize Inertia (manifest ausente no worktree)

---

## Verificações só manuais

| Comportamento | Critério | Por que manual | Instruções |
|---|---|---|---|
| Índices de `user_setores` no MariaDB | SC1 | SQLite dos testes não exige índice de apoio para FK (learnings §6, §10.1) | Após migrar (local MariaDB e prod): `SHOW INDEX FROM user_setores` — unique novo presente, antigo ausente, FK `user_id` com índice de apoio |
| Junção real em produção | SC6 | Dado real; banco local tem ids diferentes | `--dry-run` primeiro, conferir contagens com o usuário, backup, `--apply`, conferir por reconsulta ao banco (learnings §4) |
| Medição D-09 / D-11 em produção | SC5/SC6 | Leitura em produção exige autorização no momento | Queries de leitura do RESEARCH §8 |
| Tela /users e /admin/setores com dois cargos | SC1 | Interação visual | Marcar dois cargos no Performance, salvar, reabrir |

---

## Aprovação

- [x] Toda tarefa tem verificação `<automated>` ou dependência da Wave 0
- [x] Sem 3 tarefas seguidas sem verificação automatizada
- [x] Wave 0 cobre as referências ausentes
- [x] Sem flags de watch
- [x] Latência de feedback < 120 s
- [x] `nyquist_compliant: true` no frontmatter

**Aprovação:** aprovado 2026-09-30 (plan-checker: dimensões 8a–8d passam nos 8 planos; testes criados dentro das próprias tarefas TDD)
