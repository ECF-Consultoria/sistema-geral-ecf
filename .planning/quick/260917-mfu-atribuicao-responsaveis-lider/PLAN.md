---
quick_id: 260917-mfu
slug: atribuicao-responsaveis-lider
date: 2026-09-17
status: in-progress
---

# Quick 260917-mfu — Atribuição de responsáveis: 403 do líder + gravação sem efeito

## Sintoma relatado

1. O Luiz, **líder do setor Performance**, não consegue atribuir/editar
   analista e estrategista de uma empresa — a tela responde "acesso negado".
2. Promovido a `admin` para contornar, ele editou o analista de uma empresa,
   a tela disse "salvo" — e os responsáveis continuaram os de antes.

## Diagnóstico (medido em produção, 2026-09-17)

### Bug 1 — 403 do líder

`PUT /companies/{company}` (`companies.update`) e `POST /companies/bulk-assign`
estão dentro do grupo `Route::middleware('role:admin')` em `routes/web.php`.
O botão de lápis em `Companies/Index.jsx:630` é renderizado **sem condição**,
então quem alcança a tela por `permission:core.empresas` (o líder, e também
analista/estrategista com a visão da própria carteira) vê o botão, abre o
modal e toma 403 no salvar.

`CompanyController` **já** tem o predicado certo: `ehLiderDaPerformance()`,
usado para montar `pode_distribuir`. Falta aplicá-lo à escrita.

### Bug 2 — grava, mas a tela mostra o antigo

Escrita e leitura têm escopos DIFERENTES:

- **Leitura** (`Company::analistaPerformance()` / `estrategistaPerformance()`):
  linhas com `servico_id` de serviço `setor='performance'` **OU** `servico_id
  NULL` (quando a empresa tem contrato performance ativo).
- **Escrita** (`CompanyController::update()` e `bulkAssign()`): apaga só o slot
  devolvido por `servicoPerformanceAtivoId()` — `MIN(servico_id)` dos contratos
  performance ativos, ou NULL se não houver nenhum.

Com uma linha legada `servico_id = NULL` e um contrato Gestão (id 6) ativo, o
delete mira `servico_id = 6` (que não existe), a linha NULL **sobrevive** e o
attach cria uma segunda linha. A leitura enxerga as duas e `->first()`, sem
`ORDER BY`, devolve a mais antiga.

Evidência — empresa 251 (ALCOMERCIOEIMPORTACAO), a edição do Luiz de hoje:

| id  | role         | user          | servico_id | created_at       |
|-----|--------------|---------------|-----------:|------------------|
| 220 | consultor    | Danilo (15)   |       NULL | 2026-06-11 18:17 |
| 500 | consultor    | Gustavo (16)  |          6 | 2026-09-17 15:54 |
| 221 | estrategista | Nathalia (11) |       NULL | 2026-06-11 18:17 |
| 501 | estrategista | Nathalia (11) |          6 | 2026-09-17 15:54 |

Contrato ativo da 251: só Gestão (`servico_id=6`, setor performance).
A troca Danilo → Gustavo foi gravada (linha 500) e não apareceu (linha 220).

Alcance em produção: 7 linhas com `servico_id NULL` em 4 empresas (184, 188,
189, 251); só a 251 tem o par duplicado. **Nenhuma** das quatro tem contrato
Shopee — apagar a linha NULL não derruba o fallback consolidado de Shopee de
ninguém hoje.

Terceiro sintoma da mesma família: `if (!empty($sync))` faz a limpeza dos dois
responsáveis (selecionar "—" nos dois campos) virar um no-op silencioso.

## Tarefas

- **T1** — `routes/web.php`: tirar `companies.update` e `companies.bulk-assign`
  do `role:admin` via `withoutMiddleware('role:admin')` (mesmo padrão já usado
  em `companies.portal.abrir`), mantendo-as no lugar.
- **T2** — `CompanyController`: helper `podeGerirEmpresa()` (admin OU líder da
  Performance) + `abort_unless` no topo de `update()` e `bulkAssign()`.
- **T3** — `CompanyController`: helper `limparSlotPerformance()` que apaga
  `role IN (...)` com `servico_id` em serviço de setor performance **OU** NULL;
  usar em `update()` e `bulkAssign()` no lugar do delete escopado a um único
  `servico_id`. Não toca Shopee (servico_id do setor shopee) nem outros setores.
- **T4** — `CompanyController::update()`: rodar a troca de responsáveis quando
  os campos vierem no request, mesmo vazios (permite LIMPAR), e registrar o
  histórico de gestão também nesse caso.
- **T5** — `CompanyController::index()` + `Companies/Index.jsx`: prop
  `pode_editar_empresa` gateando o botão de lápis (some para quem levaria 403).
- **T6** — Testes em `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php`:
  linha NULL legada + contrato performance ativo → a edição substitui de fato;
  líder da Performance (não-admin) consegue editar; outro não-admin leva 403.
- **T7** — `npm run build`.
- **T8** — Produção: apagar as 2 linhas órfãs da empresa 251 (ids 220 e 221)
  depois do deploy, conferindo por reconsulta ao banco.

## Fora de escopo (reportar ao usuário)

`DistribuicaoService` (Fase 154/157) grava `company_users.role = 'analista'`
(`ROLE_ANALISTA`), papel que **nenhum** leitor de carteira/bônus/`/companies`
consulta — todos leem `'consultor'`. Hoje não há nenhuma linha `'analista'` em
produção, mas a aba Distribuição do líder passa por ali. Não corrigido aqui.
