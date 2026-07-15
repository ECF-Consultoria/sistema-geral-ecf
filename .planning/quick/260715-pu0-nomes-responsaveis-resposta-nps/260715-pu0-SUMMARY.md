---
quick_id: 260715-pu0
status: incomplete
tasks_completas: 2
tasks_totais: 3
pendencia: checkpoint visual (Task 3) — validar em produção
data: 2026-07-15
---

# Quick Task 260715-pu0 — Nomes do analista e do estrategista no detalhe da resposta NPS

## Pedido

No modal de detalhe da resposta do NPS, mostrar **quem são** o analista e o estrategista, junto das notas individuais deles. Antes aparecia só "Analista 5.00 / Estrategista 5.00", sem dizer de quem era a nota.

## O que foi feito

| Task | Commit | Resultado |
|------|--------|-----------|
| 1 — payload `responsaveis` no `NpsController::index()` (RED→GREEN) | `f7358d6` | 3/3 testes verdes em `tests/Feature/V16/NpsResponsaveisPayloadTest.php` |
| 2 — nomes no `NotaCard` do modal (`resources/js/Pages/Nps/Index.jsx`) | `ed0ab17` | `npm run build` exit 0 |
| 3 — checkpoint visual | — | **PENDENTE** (ver abaixo) |

## Decisão de arquitetura (a parte que importa)

**A fonte do nome é `nps_score_assignments`, nunca o pivot vivo `company_users`.**

As relações `Company::consultor()` / `estrategista()` filtram apenas por `role`. Desde a Fase 76 uma empresa pode ter DUAS linhas com o mesmo `role` e `servico_id` diferentes — ex.: RELOJOARIA WENUS tem `Ana Julia consultor/performance` E `Gustavo consultor/shopee`. Um `->first()` devolve a linha mais antiga, e é exatamente por isso que a tela `/companies` mostra o Gustavo no lugar da Ana Julia hoje (bug ativo, pendente de fase própria). Usar esse caminho aqui teria repetido o bug.

`nps_score_assignments` guarda `user_id` + `role` + `service_setor` + nota **congelados no momento da resposta**, já resolvidos por serviço pelo `NpsSnapshotService`. O nome exibido é literalmente quem recebeu aquela nota — historicamente exato e coerente com o número ao lado.

Consequências registradas:
- Mapa dimensão → role: `analista` ↔ `role='consultor'`, `estrategista` ↔ `role='estrategista'`. A dimensão `empresa` não tem pessoa — não recebe nome.
- **Legado sem atribuição → "—", sem fallback.** Medido em prod: 19 respostas, 16 com atribuição, 3 sem (anteriores à Fase 79). Resolver essas pelo pivot de hoje seria mentira histórica — a resposta é de junho e o responsável pode ter mudado.
- Suporta **lista** de pessoas por papel (o `registrar()` faz `foreach ($responsaveis as $user)`), com dedup por `user_id`. Nada de `->first()`.
- Eager load via `response.scoreAssignments.user` no `->with()` existente: 2 queries por página, não 20×N.

## Armadilha evitada (teste vacuoso)

`NpsController::index` filtra pelo modelo **principal** por padrão (linhas 66-74). Como os templates de teste não são principais, sem `?template_id=__todos__` a lista viria **vazia** e os 3 testes passariam por vacuidade — verdes provando nada. Os testes assertam que o item está na lista antes de assertar os nomes.

O teste que impede a regressão é o da empresa com analista de performance E de shopee, com a linha da Ana inserida **antes** da do Gustavo: se a ordem fosse inversa, um `->first()` ingênuo devolveria o Gustavo por acaso e o teste passaria com a implementação errada.

## Gates

- `tests/Feature/V16/NpsResponsaveisPayloadTest.php` → 3/3 (RED provado antes do GREEN)
- `--filter=Nps` → **207 passed** (baseline medido antes = 204; +3 novos, zero regressão)
- `npm run build` → exit 0
- `git diff` do `Index.jsx` restrito a `NotaCard` + bloco do modal; `ChipNota` e a linha da lista intocados
- Grep de escopo: `consultorDoServico`/`->consultor` aparecem só num comentário explicando a proibição, não em código executável

## Pendência — checkpoint visual (Task 3)

Bloqueado localmente: o **MySQL do XAMPP não sobe** (`Can't open and lock privilege tables: Incorrect file format 'db'`). É o problema pré-existente do MariaDB local corrompido (ver memória `project_mariadb_local_corrompido` e `.planning/quick/260625-mrd-reparar-mariadb-local/`), sem relação com esta tarefa — os dados de `ecf_admin` (InnoDB) estão intactos; a corrupção é nas tabelas de sistema (engine Aria).

Decisão: **não** fazer cirurgia no MariaDB local só para ver a tela. Validar em produção após o deploy, que é o ambiente onde o operador tem validado tudo nesta milestone.

Passos do checkpoint (em prod, `/nps`):
1. Escolher um mês com respostas — "Todos os modelos" no `<select>` ajuda a ver ML e Shopee.
2. Abrir uma resposta **recente** (pós-Fase 79): os cards "Estrategista" e "Analista" devem trazer o nome sob a nota; "Empresa" sem nome.
3. Conferir que o nome bate com quem atende aquela empresa **naquele serviço** (o caso RELOJOARIA WENUS é o bom teste: deve mostrar Ana Julia / Luiz Henrique, nunca Gustavo / Felipe).
4. Abrir uma resposta **antiga** (pré-Fase 79): deve aparecer "—".
5. Conferir alinhamento dos 3 cards e o `title` no hover para nomes longos.
