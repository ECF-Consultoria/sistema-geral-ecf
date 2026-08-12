---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 06
subsystem: api
tags: [laravel, artisan, desempenho, read-only, relatorio-de-impacto, privacidade, phpunit]

# Dependency graph
requires: ["136-01"]
provides:
  - "desempenho:relatorio-impacto-fonte — comando READ-ONLY de impacto da correcao de D-10, com --mes= e --json"
  - "Instrumento de decisao da retroatividade (D-11): quais empresas e profissionais mudariam de numero, sem reconsolidar nada"
  - "Reconciliacao contra producao: 3 booleanos de configuracao de conta (adman_account_id / ml_store_id / cust_id) por empresa divergente"
  - "Contagem de celulas manuais ativas da competencia, agrupada por metrica, a partir da tabela propria"
affects: ["136-07"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Regra revogada preservada dentro do proprio comando de relatorio de impacto (molde do setIncluirImputadas(false) de DesempenhoScoreService), com comentario declarando que e regra morta"
    - "Prova de read-only por Http::preventStrayRequests() SEM nenhum Http::fake() — qualquer tentativa de HTTP quebra o teste"
    - "Guarda de tabela ausente (Schema::hasTable) para um comando de diagnostico nunca quebrar por dependencia de outro plano"

key-files:
  created:
    - app/Console/Commands/RelatorioImpactoFonteDesempenho.php
    - tests/Feature/Phase136/RelatorioImpactoDesempateTest.php
  modified: []

key-decisions:
  - "O universo de vinculos filtra por role in (consultor, estrategista) — mesma lista de CarteiraContextService::ROLES_VALIDOS — para reproduzir a carteira que o Desempenho enxerga, nao a pivot crua"
  - "Celula manual ATIVA sozinha ja derruba o exit code para 1, mesmo sem nenhuma empresa divergente: o veredito e 'existe impacto a decidir', nao 'existe divergencia de fonte'"
  - "A regra revogada de desempate vive exclusivamente dentro deste comando, para produzir a coluna 'antes'; a coluna 'depois' delega ao FinancialSourceResolver, nunca reescreve a regra nova"

patterns-established:
  - "Comando de diagnostico do Desempenho: docblock READ-ONLY + exit code como veredito + warn declarando que a tabela humana nao e o contrato (agora em 2 comandos: verificar-consolidacao e relatorio-impacto-fonte)"

requirements-completed: ["D-11"]

# Metrics
duration: 13min
completed: 2026-08-12
---

# Phase 136 Plan 06: Comando read-only de impacto da correção do desempate de fonte (D-11) Summary

**Entrega `desempenho:relatorio-impacto-fonte`: um comando Artisan estritamente read-only que lista quais empresas mudam de fonte financeira pela correção de D-10 (com os três booleanos que permitem reconciliar contra produção), quais profissionais as têm na carteira — só por contador, nunca com resultado individual de bonificação — e quantas células manuais estão ativas na competência, devolvendo o veredito no exit code e sem reconsolidar absolutamente nada.**

## Performance

- **Duration:** ~13 min (10:22 → 10:35 BRT, 2026-08-12)
- **Started:** 2026-08-12T13:22:51Z
- **Completed:** 2026-08-12T13:35:00Z
- **Tasks:** 2/2
- **Files modified:** 2 criados, 0 modificados

## Accomplishments

- `desempenho:relatorio-impacto-fonte` criado (414 linhas), com `--mes=` (default = mês anterior ao hoje, parse por `createFromFormat('Y-m-d', ...)` — nunca `'Y-m'` sozinho) e `--json`, no molde exato de `VerificarConsolidacaoDesempenho`: docblock declarando READ-ONLY, semântica de exit code no `$description`, e `warn` avisando que a tabela humana é conveniência e a conferência oficial é o exit code ou o `--json`.
- Três seções no relatório: `fonte_divergente` (a reconciliação, com `tem_adman_account_id`, `tem_ml_store_id` e `cust_id_presente` por empresa), `profissionais_afetados` (contador + lista de `company_ids`) e `celulas_manuais` (agrupada por métrica, a partir de `desempenho_metricas_manuais`), mais o bloco `resumo`.
- A coluna "depois" delega ao `FinancialSourceResolver` do Plano 01 — se a regra nova mudar, o relatório muda junto, por construção. A coluna "antes" é a expressão revogada (`$sources->contains('adman') ? 'adman' : $sources->first()`), preservada **exclusivamente** dentro deste comando e marcada como regra morta, no mesmo padrão do toggle de relatório de impacto de `DesempenhoScoreService::setIncluirImputadas(false)`.
- 12 testes novos, verdes, provando: read-only (contagem idêntica de `companies`, `company_users`, `desempenho_company_score_snapshots`, `desempenho_metricas_manuais` e `activity_log` antes e depois das **duas** saídas), ausência de HTTP (`Http::preventStrayRequests()` sem nenhum `Http::fake()`), exit code como veredito (0 no cenário limpo, 1 com empresa mista sem `cust_id` e 1 com célula manual sozinha), detecção correta (`adman` → `shopee`, `cust_id_presente === false`), zero falso positivo (empresa mista **com** conta Adman e empresa de fonte única não entram) e privacidade (nenhuma chave de `profissionais_afetados` casa com `/nota|faixa|bonific/i`).
- Suíte de regressão da fase reconfirmada **exatamente** na baseline congelada: 9 failed / 18 passed, os mesmos 9 nomes de `136-BASELINE-TESTES.md`. `--filter="Phase136"` fecha em 60 passed / 266 assertions, exit code 0.

## Task Commits

Each task was committed atomically:

1. **Task 1: Comando read-only de impacto da correção de fonte** - `ce4939e1` (feat)
2. **Task 2: Suíte provando que o relatório é read-only e que o exit code é o veredito** - `683187f2` (test)

## Files Created/Modified

- `app/Console/Commands/RelatorioImpactoFonteDesempenho.php` - comando read-only; universo de vínculos elegíveis com `distinct`, comparação antiga×nova por empresa, contadores por profissional, contagem de células manuais com guarda de tabela ausente, saída `--json` limpa e tabelas humanas com os dois avisos (contrato e D-11)
- `tests/Feature/Phase136/RelatorioImpactoDesempateTest.php` - 12 testes (46 assertions) cobrindo read-only, ausência de HTTP, exit code, detecção, falso positivo, privacidade, agrupamento de células manuais e recorte de competência

## Decisions Made

- **Universo filtra por `role in ('consultor', 'estrategista')`** — a mesma lista de `CarteiraContextService::ROLES_VALIDOS`. O `<interfaces>` do plano declarou o universo por setor de serviço e empresa ativa; o filtro de papel foi acrescentado para o relatório reproduzir a carteira que o Desempenho de fato enxerga (a pivot nunca grava `'analista'` — o cargo vive em `user_setores`), e não a pivot crua.
- **Célula manual ativa sozinha já devolve exit 1**, mesmo sem nenhuma empresa divergente. O veredito do comando é "existe impacto a decidir", não "existe divergência de fonte" — está explicitado no docblock e coberto por teste dedicado.
- **A contagem de células manuais degrada para zeros** quando `desempenho_metricas_manuais` não existe no ambiente (`Schema::hasTable`). Um comando de diagnóstico que quebra por dependência de outro plano deixa de ser diagnóstico.
- **Nenhuma nota é recalculada, por dois motivos somados**: privacidade (learnings §11 — nome pareado com resultado individual de bonificação não pode ser versionado) e a disciplina read-only (recalcular chamaria o roteador de métricas financeiras e dispararia HTTP síncrono à Adman, por empresa). Os dois motivos estão escritos no método `profissionaisAfetados()`, para a próxima sessão não "melhorar" o relatório acrescentando a nota.

## Deviations from Plan

None - plano executado exatamente como escrito. O único acréscimo à letra do `<interfaces>` é o filtro por papel na query do universo, registrado acima em Decisions Made.

## Issues Encountered

**1. MySQL local indisponível — a verificação automatizada da Task 1 não podia rodar contra o banco de desenvolvimento.**
`artisan desempenho:relatorio-impacto-fonte --mes=2026-07 --json` devolveu `SQLSTATE[HY000] [2002]` (conexão recusada em `127.0.0.1:3306` — o MySQL do XAMPP está parado nesta máquina). O comando não foi alterado por causa disso: a verificação foi feita rodando o **CLI real** (não os helpers de teste) contra um SQLite temporário criado no scratchpad da sessão, migrado com `migrate --force` e apontado por variáveis de ambiente na invocação. Resultado: `artisan list` mostra o comando, `--json` produz saída inteiramente parseável com as quatro seções, exit code 0 no banco vazio, e a saída humana renderiza sem erro. **Nada foi escrito no banco de desenvolvimento e nenhum arquivo temporário entrou no repositório.**
A cobertura comportamental de verdade vem da suíte da Task 2, que roda em SQLite `:memory:` com fixtures reais — a mesma disciplina registrada no SUMMARY do Plano 02 ("usar sempre `artisan test` para verificação comportamental, nunca tinker sem isolamento explícito").

**2. Limitação conhecida de cobertura do universo (não é defeito, é recorte declarado).**
O relatório percorre apenas vínculos com `company_users.servico_id` **preenchido**. O ramo legado de `CarteiraContextService` (`servico_id NULL`, resolvido como performance quando a empresa tem contrato performance ativo) fica de fora — é o recorte declarado no `<interfaces>` do plano. Na prática isso é inócuo: a contagem em produção registrada no docblock do próprio `CarteiraContextService` (2026-07-16) mostra que, dos 268 vínculos, os 7 com `servico_id NULL` **não pertencem a nenhuma empresa com contrato performance ou Shopee ativo** — nenhum deles pode produzir empresa com duas fontes concorrentes. Se essa contagem mudar em produção, o relatório passaria a subnotificar empresas cuja única fonte "performance" venha do ramo legado. Registrado em `deferred-items.md`.

## User Setup Required

None — nenhuma configuração de serviço externo necessária.

## Verification Performed

| Verificação | Resultado |
|---|---|
| `artisan list` inclui `desempenho:relatorio-impacto-fonte` | OK |
| `--json` parseável por inteiro, com `fonte_divergente` / `profissionais_afetados` / `celulas_manuais` / `resumo` | OK (CLI real contra SQLite temporário + teste dedicado) |
| `grep -c "nota_final\|faixa_bonus\|valor_bonificacao"` no comando | 0 |
| `grep -c "diffDispatcher\|MetricDiffDispatcher"` no comando | 0 |
| `grep -c "->update(\|->create(\|->save(\|->delete(\|Cache::put"` no comando | 0 |
| `grep -c "createFromFormat('Y-m',"` no comando | 0 |
| `grep -c "READ-ONLY"` no comando | 3 |
| `artisan test --filter="RelatorioImpactoDesempateTest"` | 12 passed, exit 0 |
| `artisan test --filter="Phase136"` | 60 passed (266 assertions), exit 0 |
| Suíte de regressão da baseline (5 suítes) | **9 failed / 18 passed** — idêntico a `136-BASELINE-TESTES.md`, mesmos 9 nomes, **zero regressão** |

## Next Phase Readiness

- O comando está pronto para o gate do Plano 07 e para a execução contra produção quando houver autorização de deploy.
- **Pendências explícitas registradas para quando houver autorização (o comando é read-only, mas rodá-lo em produção exige o deploy do arquivo):**
  1. Rodar `desempenho:relatorio-impacto-fonte --json` contra **produção** para fechar a reconciliação que o RESEARCH (A1) exige — o banco local já mentiu sobre o `cust_id` de pelo menos uma empresa conhecida (Utilarshop), e os três booleanos por empresa existem exatamente para essa conferência.
  2. Rodar `desempenho:verificar-consolidacao --mes=YYYY-MM --json` e **ler o exit code** (nunca o stdout, nunca depois de um pipe — learnings §4) para medir o efeito de D-10 sobre o gate FIXMARG-03 antes de qualquer consolidação.
- **A reconsolidação continua fora do escopo desta fase (D-11).** O comando coloca o número na mesa; a decisão de reconsolidar competência fechada é ato separado e deliberado do usuário, com backup prévio em `storage/app/private/backups/desempenho/`.

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-12*

## Self-Check: PASSED

- Os 3 arquivos-chave (comando, suíte e este SUMMARY) verificados no disco — FOUND
- Os 2 commits de task (`ce4939e1`, `683187f2`) verificados em `git log --oneline --all` — FOUND
- Cada commit conferido por `git show --stat`: apenas o arquivo da própria task entrou; nenhum arquivo da Fase 135 foi tocado, staged, stashado ou commitado
