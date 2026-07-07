---
id: 270707-v15-nps-templates-rewrite
created: 2026-07-07
status: seed
trigger_when: "Após v14.0 fechar (Phase 67 done + complete-milestone), abrir v15.0 dedicada NPS Templates"
related_phases: [68, 69, 70, 71, 72, 73]
priority_when_triggered: high
---

# Seed — Milestone v15.0: NPS Templates (reescrita)

## Contexto

Em 2026-07-07, durante execução da Phase 63 da v14.0, usuário trouxe spec detalhada (~200 linhas) para reescrever o módulo NPS. Escopo é maior que polish — é uma reescrita completa do módulo baseado em **modelos configuráveis de formulário**.

Decidido em 2026-07-07 (2 AskUserQuestion respondidas):
1. **Nova milestone v15.0** (não estender v14.0)
2. **Seed "NPS Padrão"** cobrindo 100% do histórico legado (retro-associa todas surveys existentes)

## Objetivo alto-nível

Transformar NPS de "3 perguntas fixas + extras globais" em "modelos de formulário configuráveis" onde:
- Cada template tem título, envio automático, perguntas com opções e pesos
- Templates diferentes por tipo de serviço (gestão vs mentoria)
- Nota por dimensão (estrategista / analista / empresa / geral) calculada pela média dos pesos das perguntas da dimensão
- Bloqueio de resposta duplicada por (company + mês + template)
- Alerta de pendência com "dia de cobrança" configurável
- Formulário público limpo (cinza + amarelo ativo, mobile-friendly)
- Zero uso de Promotor/Neutro/Detrator (escala 1-5)

## Estrutura da milestone (6 fases)

| # | Nome | Etapa do brief |
|---|---|---|
| 68 | Schema + modelos + seed NPS Padrão retroativo | 1 |
| 69 | Backend: TemplateService, ScoreCalculator, dedup mês, dispatch | 2 |
| 70 | UI Config — CRUD templates + drag/drop opções + pesos | 3 |
| 71 | Formulário público — dinâmico por template, UX limpa | 4 |
| 72 | Dashboards + pendências + dia de cobrança | 5 |
| 73 | Limpeza legado (Promotor/Neutro/Detrator + score_overall) + testes | 6 |

## Schema-alvo (5 tabelas novas + 2 alter)

Ver detalhes completos em memory `project_v15_nps_templates.md`.

**Novas:** `nps_templates`, `nps_template_questions`, `nps_template_options`, `nps_template_service_scopes`, `nps_response_answers`.

**Alter:** `nps_surveys +template_id +template_snapshot_json`; `nps_responses` scores → nullable.

## Gaps do NPS atual a resolver na v15.0

1. `PerformanceController.php:301` — Promotor/Neutro/Detrator hardcoded (bug na escala 1-5)
2. `CompanyController.php:482-487` — refs legadas `score_overall/consultant/mentor` (Plan 31-05 nunca fechou)
3. `CalculateGoalResults.php:155` — `metric='nps'` não é calculada no job
4. Janelas de expiração/horário/prune todos hardcoded

## Critérios de aceite (10 do brief do usuário)

1. Admin cria/edita modelos
2. Admin configura perguntas + opções + pesos + ordem
3. Cliente responde formulário do modelo correto (por tipo de serviço)
4. Snapshot preservado em `nps_response_answers`
5. Nota por dimensão calculada corretamente
6. Empresa sem resposta no mês → pendência visível
7. Bloqueio de duplicata (company + mês + template) via unique index parcial
8. Formulário público redesenhado (cinza + amarelo ativo)
9. Zero Promotor/Neutro/Detrator restante
10. Testes cobrindo tudo acima

## Próximo passo quando triggar

1. Fechar v14.0 (Phase 67 done + `/gsd:complete-milestone`)
2. `/gsd:new-milestone v15.0 "NPS Templates"` — usar este seed + memory `project_v15_nps_templates.md` como semente
3. Roadmapper vai mapear os 6 REQ blocks (NPS-01 a NPS-06) para as 6 fases (68-73)

## Anti-patterns (não fazer)

- ❌ Não estender v14.0 com essas fases — foi decisão explícita do usuário
- ❌ Não descartar histórico — seed "NPS Padrão" é obrigatório
- ❌ Não deixar Promotor/Neutro/Detrator em código — regra sistêmica anti-jargão
- ❌ Não usar escala 0-10 em nenhum lugar — escala é 1-5 (peso das opções pode ir além mas visual é 5 opções)
- ❌ Não fazer refactor grande fora do escopo do NPS — brief explícito do usuário
