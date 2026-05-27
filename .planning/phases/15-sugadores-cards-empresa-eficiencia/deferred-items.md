# Phase 15 — Deferred Items

## Falhas pré-existentes não relacionadas à Phase 15

Rodada da suíte completa em 2026-05-27 (W4-T1) retornou **45 falhas / 133 passes / 853 assertions**. Investigado: nenhuma falha foi introduzida pela Phase 15. A suíte específica `--filter=Sugador` permanece 13/13 verdes (134 assertions), incluindo:

- `AutoResolveTest` (5/5) — auto-resolução backend
- `SugadoresIndexTest` (8/8) — `companies_summary` agregação + ordenação + view_mode

### Origem das falhas pré-existentes (todas registradas no STATE.md como deferred Phase 13/14)

| Suite falhando | Origem | Status no STATE.md |
|----------------|--------|--------------------|
| `Tests\Unit\CalcularFaixaTest` (9 falhas — `ArgumentCountError`) | Wave 0 Phase 14 — assinatura do helper mudou pós-drop | "Suites de coexistencia pos-drop obsoletas" — pending-regression-cleanup |
| `Tests\Feature\AdminFechamentoControllerTest` (5+ falhas) | Phase 14 pré-existente | "AdminFechamentoControllerTest 5 testes falhando pré-existentes" — quick task |
| `Tests\Feature\Phase13ComercialTest` (vários) | Phase 14 obsoleta — cobertura em `Phase14ComercialTest` | "Phase13ComercialTest obsoleta" — port/deletion |
| `Tests\Feature\Phase14MigrationTest > migration pode rodar 2x` | Timezone Carbon na fixture `contract_start` | Pre-existing Phase 14 |
| `Tests\Feature\Phase14VerificarCobrancaTest` (1) | `--abort-on-divergence` assert pré-existente | "phase14:verificar-cobranca no host" — pending-host-run |
| `Tests\Feature\FechamentoMigrationTest > migration adiciona colunas` | Migration legacy testando colunas dropadas na Phase 14 | "Suites de coexistencia pos-drop obsoletas" — pending-regression-cleanup |
| `Tests\Feature\DevControllerTest` (vários) | Schema antigo — anterior à Phase 14 | Não regride na Phase 15 |
| `Tests\Feature\ExampleTest` (1) | Default Laravel test esperando 200 mas app redireciona 302 (auth) | Trivial — fixture do scaffold |
| `Tests\Unit\CompanyServiceTypeTest` (1) | Aceita `polo` (singular) — Phase 13 renomeou para `polos` | Pre-existing Phase 13 |

### Conclusão

Phase 15 não regride nenhum teste. As 45 falhas devem ser endereçadas em quick tasks de manutenção fora do escopo desta fase, conforme STATE.md já indica.

## Smoke visual humano (W4-T2)

A verificação UX completa (cards/toggle/reanalisar/copy/chip) fica para o usuário em sessão real — documentado no PLAN.md como `checkpoint:human-verify`. Não bloqueia o fechamento dos commits porque toda lógica subjacente passou nos automated tests.
