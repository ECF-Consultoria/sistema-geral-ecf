# Fase 108 — Redesign da página individual da empresa (/companies/{id})

**Status:** executing · **Criado:** 2026-07-23

## Objetivo
Reescrever `Companies/Show.jsx` (1.303 linhas, cresceu desordenado) numa página
limpa, moderna, minimalista e assertiva, com a identidade visual de brilhos da
dashboard ML / /portfolio. Só os dados que importam.

## Decisões (discuss 2026-07-23)
- Histórico de gerenciamento: **construir agora** (captura daqui pra frente +
  tabela nova; começa vazio, sem backfill — o passado é irrecuperável).
- Medalhas ML: **manter só as medalhas** (via ECF Drive), remover o resto do
  bloco Análise ECF Drive.
- **Reescrever** o JSX limpo. Sem mockup.

## Estrutura nova (na ordem)
1. Hero: nome, CNPJ, segmento, Cust Id, **data de entrada** (nova), badge
   Ativa/Inativa + fonte, status ML resumido.
2. Responsáveis: analista + estrategista atuais (com "desde") + **histórico de
   gerenciamento** (tabela nova).
3. Financeiro 30d: Faturamento + Margem (cards com brilho).
4. NPS: média + histórico.
5. Metas ativas (GoalProgressPanel — reuso).
6. Serviços contratados (serviço, valor, tipo, início, vencimento, status).
7. Mercado Livre: integração (MlConnectionCard — reuso) + medalhas (HistoricoMedalhas — reuso).
8. Informações comerciais (Close): nicho, dor, vende ML, faturamento declarado, marketplaces, contatos.

## Sai
Tacos, Acos, Absenteísmo, Análise ECF Drive (exceto medalhas), Alertas
estratégicos, Reuniões Recentes, Histórico de Métricas Adman.

## Backend
- `company_manager_history` (migration + model): company_id, user_id, papel
  (analista|estrategista), evento (entrada|saida), changed_by, created_at.
- Captura em `CompanyController::update()` (compara antes/depois da troca de
  responsáveis, loga entrada/saída).
- `analistaPerformance`/`estrategistaPerformance`: `withPivot('assigned_at')`.
- Payload show(): + `data_entrada` (created_at), responsáveis com `desde`,
  `historico_gestao`; remove tacos/acos/meetings/adman_metrics/ml_metrics/
  absenteísmo; `ecf_drive` → só `medalhas` (+ medalha atual).

## Verificação
Página carrega em /companies/131; sem os itens removidos; troca de responsável
grava histórico; medalhas aparecem; metas/NPS/contratos/ML intactos.
