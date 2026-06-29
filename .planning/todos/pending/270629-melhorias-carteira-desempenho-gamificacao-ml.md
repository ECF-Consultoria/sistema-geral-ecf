---
id: 270629-melhorias-carteira-desempenho-gamificacao-ml
created: 2026-06-29
priority: high
effort_estimate: |
  Phase 47 (extensão scoring negativo + balanceamento): 1-1.5 dias
  Phase 48 (redesign carteira + diferenciado por função): 2-3 dias
  Phase 49 (rankings por função + publicação): 1 dia
  Phase 50 (gamificação OAuth ML): 1.5-2 dias
category: brief-umbrella
related_phases: [47, 48, 49, 50]
related_briefings:
  - briefing-carteira-analistas-ui.md (untracked no root — direção visual completa pra Phase 48)
  - metodologia-desempenho-carteira.md (untracked no root — usado em Phase 46 também)
  - .planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md (briefing umbrella anterior — Items 1-5 originais)
blocks_on: []
status: pending
---

# Briefing 2026-06-29 — Carteira + Desempenho + Gamificação OAuth ML

Capturado em 2026-06-29 sequencial à Phase 45 (em execução, UAT 45-04 pendente). Sessão de produto detalhou 6 melhorias que tocam 3 áreas e foram roteadas em 1 extensão de phase + 3 phases novas no v12.0.

## Sumário rápido

| Item | Destino | Tamanho |
|------|---------|---------|
| 1 — 4 rankings em /desempenho + ranking publicação separado | **Phase 49** NOVA | Pequena |
| 2 — Mini-gráfico crescimento por empresa na carteira | **Phase 48** NOVA | Média (parte) |
| 3 — Histórico NPS na carteira | **Phase 48** NOVA | Média (parte) |
| 4 — Remover meta agregada da carteira | **Phase 48** NOVA | Média (parte) |
| 5 — Carteira analista vs estrategista + scoring negativo + balanceamento por volume | **EXTENDE Phase 47** + parte UI em **Phase 48** | Editar 47 |
| 6 — Gamificação OAuth ML para Líder + Estrategistas | **Phase 50** NOVA | Média |

## Decisões locked na captura

1. **Publicação fica SEPARADA** de /desempenho (Item 1) — rota nova dentro do dropdown "Publicação" do menu lateral; não vai junto com Geral/Analistas/Estrategistas.
2. **Meta agregada da carteira NÃO EXISTE MAIS** (Item 4) — meta é por empresa, definida no onboarding. PortfolioScoreService precisa migrar categoria "atingimento de meta" pra média de % das empresas individuais.
3. **Scoring NEGATIVO por sugador não-resolvido** (Item 5) — incentiva limpar fila em vez de só recompensar atividade. Implementação automática quando Phase 44 estiver em prod.
4. **Balanceamento por volume é princípio geral** (Item 5) — atividade frequente (sugador diário) = recompensa pequena por unidade; atividade rara (PPA mensal) = recompensa maior por unidade. Evita distorção entre funções.

## Item 1 — Detalhes operacionais (Phase 49)

- /performance ganha 3 tabs: Geral (atual) / Analistas / Estrategistas
- Filtro reusa pattern canônico `user_setores → cargos.slug` (igual Phase 45 fix)
- Mesmo `PortfolioScoreService` com argumento `?string $funcaoFilter`
- Rota nova `/publicacao/desempenho` (nome final na discuss-phase) — sidebar entry no dropdown Publicação, excludeRoles=`analista,estrategista` (só publicação acessa)
- Ranking de publicação pode usar service base ou ter service dedicado (decidir conforme métricas divergirem)

## Item 2 — Indicador de crescimento por empresa (Phase 48)

- Mini-gráfico sparkline inline na coluna da tabela de empresas (carteira individual)
- Verde subindo / vermelho descendo / cinza regular
- Critério: revenue do período vs período anterior (mesma janela usada no PortfolioScoreService)
- Recharts já está no projeto — usar `Sparklines` ou `LineChart` minimalista

## Item 3 — Histórico NPS na carteira (Phase 48)

- Widget na carteira individual mostrando notas NPS ao longo do tempo recebidas pelo profissional dono da carteira
- Fontes: tabelas/services entregues nas Phases 31-33 (NPS mensal automatizado + customização)
- Visual: gráfico de evolução + média do período + última nota recebida + count de avaliações

## Item 4 — Remover meta da carteira (Phase 48 + impacto PortfolioScoreService)

**O QUE remover:**
- Card "Meta da carteira" (R$ X / R$ Y)
- "% atingido da carteira"
- "R$ restante pra meta"
- Qualquer agregação meta_carteira no controller

**O QUE manter:**
- Meta por empresa (já existe — `companies.meta_*` colunas ou similar)
- Categoria "atingimento de meta" no scoring → vira média ponderada de % das empresas individuais (não soma agregada vs meta agregada)

**Sinal pro futuro:** usuário sinalizou que vai modificar fluxo de entrada de empresa pra incluir definição de meta no onboarding. Capturado como seed `270629-modificar-entrada-empresa-meta-onboarding.md` em `.planning/seeds/`.

## Item 5 — Scoring por função + balanceamento (Phase 47 EXTENDIDA + UI em Phase 48)

**Phase 47 backend:**
- Analista: counter `sugadores_resolvidos`, `sugadores_pendentes`, `sugadores_nao_resolvidos` no breakdown_json do score
- Estrategista: counter `ppas_concluidos` no breakdown_json
- Fórmula: peso de sugador resolvido é PEQUENO (sugador é diário); peso de PPA é MAIOR (PPA é mensal+)
- Scoring negativo: cada sugador NÃO resolvido na fila do analista subtrai score (pequeno mas presente — incentiva atendimento)
- Pesos exatos: discutir na discuss-phase com base em volume observado em prod

**Phase 48 UI:**
- Bloco na carteira ANALISTA mostrando counters de sugadores (resolvidos / pendentes / não-resolvidos) + impacto no score
- Bloco na carteira ESTRATEGISTA mostrando counter de PPAs + impacto no score
- Componentes podem ser reusados na página /performance individual (`/performance/{user_id}`)

## Item 6 — Gamificação OAuth ML (Phase 50)

**Contexto estratégico:**
- Maioria das empresas hoje usa Adman como fonte canônica
- Migrar pra API ML destrava: Sugadores via API ML (Phase 44), métricas mais precisas, redução de dependência Adman
- Conectar OAuth ML exige ação humana: conversar com cliente em reunião ou mensagem

**Responsáveis:**
- Líder de Performance (tem visão global da carteira)
- Estrategistas (têm relação direta com cliente)

**UI:**
- Nova rota no menu lateral (provavelmente em "Dados Estratégicos" — decidir na discuss-phase)
- Lista de empresas com 3 status:
  - "ML conectado" (success — `mlToken.status='active'`)
  - "Em conversa com cliente" (intermediário — toggle manual)
  - "Pendente" (default — sem ação)
- Para Estrategista: filtro `company_users` por empresa atribuída a ele
- Para Líder Performance: TODAS as empresas
- Ranking/badge/score por conexão concluída — incentiva ação proativa
- Possível integração com Phase 47 (parâmetro adicional no score) — discutir na discuss-phase

## Dependências (ordem de execução possível)

```
Phase 45 (em curso) ──> UAT 45-04 ──> Phase 45 fecha

Independentes (qualquer ordem):
  Phase 46 (histórico longitudinal)
  Phase 48 (redesign carteira) — independente
  Phase 50 (gamificação OAuth ML) — independente

Bloqueadas:
  Phase 47 (scoring) ── depends_on Phase 44 + Phase 46
  Phase 49 (rankings + publicação) ── depends_on Phase 47 + Phase 45

Recomendação de execução (paralelizando o que dá):
  1ª onda: Phases 46 + 48 + 50 (paralelas, todas independentes)
  Phase 44 destrava ── ──>
  2ª onda: Phase 47 (depende de 44 + 46)
  3ª onda: Phase 49 (depende de 47)
```

## Próximos passos sugeridos

1. **Imediato:** UAT 45-04 quando puder visualizar prod (~5 min)
2. **Depois do UAT:** decidir qual phase atacar primeiro entre 46/48/50 (todas independentes)
3. **Phase 44 destravar:** smoke ML write (operador precisa acesso DevCenter ML) → Phase 47 fica disponível
4. **Médio prazo:** Phase 49 fecha o ciclo de rankings diferenciados

## Referências cruzadas

- `briefing-carteira-analistas-ui.md` (root, untracked) — fonte primária pra Phase 48
- `metodologia-desempenho-carteira.md` (root, untracked) — fonte pra Phase 46
- `.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md` — briefing umbrella anterior
- `.planning/seeds/270629-modificar-entrada-empresa-meta-onboarding.md` — seed do fluxo de entrada (futuro)
- Memory: `feedback_lean_planning` — guia de execução
- Memory: `feedback_project_priorities` — acertividade + praticidade
