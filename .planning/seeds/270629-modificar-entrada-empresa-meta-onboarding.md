---
id: 270629-modificar-entrada-empresa-meta-onboarding
created: 2026-06-29
status: seed
trigger_when: "Após Phase 48 fechar (carteira sem meta agregada) — só faz sentido modificar entrada de empresa quando o modelo 'meta por empresa' estiver visível na UI"
related_phases: [48, "futuro-entrada-empresa"]
priority_when_triggered: medium
---

# Seed — Modificar fluxo de entrada de empresa pra incluir meta no onboarding

## Ideia original

Durante o briefing 2026-06-29 (Item 4), o usuário decidiu remover o conceito de "meta agregada da carteira" do sistema. Modelo correto: meta é POR EMPRESA, definida na reunião de onboarding (primeiros dias da empresa sob gestão da ECF).

Como consequência, o fluxo atual de **entrada de empresa** (criar empresa via Comercial → atribuir analista/estrategista → começar a operar) precisa ser modificado pra incluir **explicitamente o campo de meta na etapa de onboarding** — caso contrário a meta por empresa fica vazia ou tem default arbitrário, comprometendo:

- Categoria "atingimento de meta" do PortfolioScoreService
- Indicador de progresso por empresa nos cards da carteira (Phase 48)
- Eventual relatório de "empresas abaixo de X% da meta" (potencial Phase futura)

## O que mudar (escopo provisório)

Quando trigger acontecer (Phase 48 deployada), considerar:

1. **UI de criação de empresa** (`/comercial/empresas/novo` ou similar): adicionar campo `meta_inicial` (R$ valor mensal esperado) marcado como obrigatório ou com sugestão baseada em segmento/porte
2. **Tela de onboarding da empresa** (já existe — Phase 30+ de onboarding): card/etapa dedicada "Definir meta no Mercado Livre" antes de marcar onboarding concluído
3. **Migration de empresas legadas**: empresas existentes sem meta podem receber meta = revenue médio últimos 3 meses (defensivo) OU pendência visível "Meta não definida" pra forçar definição manual
4. **Histórico de mudanças de meta**: armazenar quando a meta foi alterada e por quem (pode entrar em `activity_log` via Spatie — já em uso no projeto)

## Contexto que justifica trigger

Hoje o usuário não sente urgência de modificar entrada de empresa porque:
- Carteira ainda mostra meta agregada (Phase 48 ainda não rodou)
- Sistema legado ainda funciona com defaults
- Foco operacional está na Phase 44 (mover SGI via API ML) e nas melhorias de carteira/desempenho

Quando Phase 48 entregar carteira sem meta agregada, o GAP fica visível imediatamente — empresas sem meta vão "quebrar" cards/categorias. Aí a trigger acontece naturalmente.

## Não fazer agora (anti-pattern)

- ❌ Adicionar campo de meta na entrada agora sem migrar empresas existentes — gera inconsistência
- ❌ Forçar default arbitrário pra todas empresas existentes — distorce relatórios históricos
- ❌ Mexer no fluxo de onboarding antes de Phase 48 fechar — escopo creep

## Próximo passo quando triggar

1. Promover este seed pra todo regular via `/gsd-capture` (sem flag)
2. Discutir mudança de schema (nova coluna ou tabela `company_metas` com histórico)
3. Decidir estratégia de migração (default sugestão vs flag "pendente")
4. Adicionar como Phase nova no v12.0 ou novo milestone v13.0 dependendo do timing
