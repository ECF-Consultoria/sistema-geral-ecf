# Phase 74: Módulo Desempenho — simplificação para 4 parâmetros + bonificação - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-07-09
**Phase:** 74-modulo-desempenho-simplificacao-4-parametros-bonificacao
**Areas discussed:** Snapshot DB, Service refactor, Config route, Manual doc, Snapshot mode disambiguation, View default, Faixas schema, Service name, Cron scheduling, Test approach

---

## Snapshot DB

| Option | Description | Selected |
|--------|-------------|----------|
| Evoluir tabela atual + coluna `mes_referencia` | Migration alter, rows antigas preservadas com `mes_referencia = NULL`, novo cron grava YYYY-MM-01 | ✓ |
| Tabela nova `desempenho_score_snapshots_mensal` | Corte limpo, mais isolamento mas dobra Model/factory | |
| Reset da tabela atual + wipe | DROP + CREATE, perde histórico v1 | |

**User's choice:** Evoluir tabela atual + coluna `mes_referencia` — com nota adicional: "quero snapshot diário E mensal"
**Notes:** Esclarecimento crítico: usuário quer QUE AMBOS coexistam (não substituição). Round seguinte disambiguou como distinguir.

---

## Service refactor strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Substituir inplace no mesmo namespace | V1 apagado, callsites mantêm imports, assinatura muda | ✓ |
| Novo `PortfolioScoreServiceV2` + v1 `@deprecated` | Coexistência por 1 sprint, viola REQ 14 big bang | |
| Renomear v1 para `Legacy` e criar v2 | Legacy fica no code base, mais churn | |

**User's choice:** Substituir inplace
**Notes:** Combinou com escolha posterior de RENOMEAR o service (D-05).

---

## Config route

| Option | Description | Selected |
|--------|-------------|----------|
| `/desempenho/configuracao` nova rota admin dedicada | Consistente com `/nps/configuracao`, `/mlb/configuracao` | ✓ |
| Aba dentro de `/desempenho` (Dashboard com tabs) | Reusa rota, mistura visualização com config | |
| Dentro do painel Dev (`/dev`) admin only | Fora do fluxo natural do time Performance | |

**User's choice:** `/desempenho/configuracao` — nova rota admin dedicada
**Notes:** —

---

## Manual doc integration

| Option | Description | Selected |
|--------|-------------|----------|
| Novo componente `Manual/Artigos/DesempenhoBonificacao.jsx` + entrada em `artigos.js` | Segue padrão `Cronograma.jsx`, zero mudança estrutural no Manual | ✓ |
| Rota admin dedicada `/manual/desempenho-bonificacao` fora do padrão | Quebra consistência do Manual | |
| Artigo Markdown parseado no runtime | Complica infra (parser MD) sem benefício claro | |

**User's choice:** Novo componente + entrada em artigos.js
**Notes:** —

---

## Snapshot mode disambiguation

| Option | Description | Selected |
|--------|-------------|----------|
| Coluna `mes_referencia DATE NULL` distingue | Diário: `mes_referencia = NULL`. Mensal fechado: populada com YYYY-MM-01. Query simples | ✓ |
| Coluna `tipo ENUM('diario', 'mensal_fechado')` | Explicit type, duplica semântica de `mes_referencia IS NULL` | |
| Duas tabelas fisicamente separadas | Contradiz decisão anterior de evoluir a mesma tabela | |

**User's choice:** Coluna `mes_referencia DATE NULL` distingue
**Notes:** Elegante — uma coluna, dupla função (snapshot type + reference).

---

## View default no Dashboard

| Option | Description | Selected |
|--------|-------------|----------|
| Mensal como default + toggle "ver diário" | "Mês em curso" (parcial) + "Último mês fechado" (oficial), toggle para rolling | ✓ |
| Diário como default + entrada dedicada para mensal fechado | Preserva UX atual mas descoordenada com bônus | |
| Só mensal (diário some da UI) | Simplifica ao máximo mas perde track diário | |

**User's choice:** Mensal como default + toggle "ver diário"
**Notes:** Alinha com o que importa (bônus mensal) mas preserva histórico rolling para consulta.

---

## Faixas schema

| Option | Description | Selected |
|--------|-------------|----------|
| Cheias: id, slug, nome, descricao, nota_min, nota_max, ordem, ativo, timestamps | ActivityLog trait, slug estável, descricao para /manual | ✓ |
| Minimalista: id, nome, nota_min, nota_max, ordem, ativo | Sem slug (nome vira chave — frágil), sem descricao | |
| Cheia + `bonus_valor` monetário | Scope creep — SPEC só define faixas, não valores | |

**User's choice:** Colunas cheias
**Notes:** —

---

## Service naming

| Option | Description | Selected |
|--------|-------------|----------|
| Manter `PortfolioScoreService` | Sem churn de imports, mas nome legado (portfolio == carteira) | |
| Renomear para `DesempenhoScoreService` | Semântica clara, alinha com módulo `/desempenho`, pequeno churn de imports | ✓ |

**User's choice:** Renomear para `DesempenhoScoreService`
**Notes:** Prevaleceu semântica sobre menor esforço de refactor.

---

## Cron schedule

| Option | Description | Selected |
|--------|-------------|----------|
| 2 comandos separados: diário existente + `desempenho:consolidar-mes` novo | Separação clara entre modos | ✓ |
| 1 comando com flag `--tipo=diario\|mensal-fechado` | Duplica lógica no parâmetro, precisa 2 entradas no schedule mesmo assim | |
| Comando único com lógica condicional interna | Menos claro para debugar | |

**User's choice:** 2 comandos separados
**Notes:** Preserva command diário existente (nome e agendamento intactos); adiciona novo `desempenho:consolidar-mes` no dia 1 às 14:00 BRT.

---

## Test fixture

| Option | Description | Selected |
|--------|-------------|----------|
| SQLite RefreshDatabase + factories reais + provider factory swap para stub | Isola provider externo mas exercita DB real end-to-end | ✓ |
| Mock puro do service | Não exercita a lógica, só integração downstream | |

**User's choice:** SQLite + factories reais + provider stub
**Notes:** Fixture Carlos (NPS 4.25 + fat 3% + margem 2.8% → 3.35 sem bônus) vira teste âncora bloqueante.

---

## Claude's Discretion

- Layout específico dos cards no `Performance/Dashboard.jsx` (proporção grid, cores exatas) — seguir padrão dark/glass já estabelecido em `Nps/Index.jsx` (redesign 2026-07-08).
- Nome dos métodos internos privados de `DesempenhoScoreService` (`computeVarFaturamento`, `computeVarMargem`, etc) — padrão camelCase pt-BR estabelecido.
- Estrutura dos FormRequests para validação — seguir padrão existente do projeto.
- Namespace dos testes Feature — `Tests\Feature\Phase74\*Test`.

## Deferred Ideas

- **Integração real de absenteísmo** — biometria facial da porta OU login-based. Fonte de dados em definição pela diretoria. Standby até definição.
- **Notificação push/email quando fecha o mês** — ao consolidar mês (dia 1), notificar analista/estrategista do resultado. Fora de escopo desta phase.
- **Bônus com valor em R$** — coluna `bonus_valor` na tabela `bonus_faixas` para tornar bônus calculável monetariamente. Deferido — SPEC só define faixas.
- **`PublicadorScoreService` (setor MLB) receber mesma simplificação** — se a diretoria decidir mudar lá também, abrir Phase 75 dedicada.
- **Bônus diferenciado por cargo (analista vs estrategista)** — v2 usa faixas globais. Se diretoria quiser diferenciação, alterar schema para `faixa_cargo_id` FK. Futuro.
- **Comparativo v1 vs v2 na UI por período de transição** — decidido big bang (SPEC-14); sem convivência.
- **Backfill de snapshots mensais para meses anteriores a 2026-08-01** — snapshot mensal começa em agosto/2026. Sem histórico retroativo.
