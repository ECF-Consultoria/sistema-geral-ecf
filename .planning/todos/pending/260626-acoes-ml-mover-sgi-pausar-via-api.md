---
id: 260626-acoes-ml-mover-sgi-pausar-via-api
created: 2026-06-26
updated: 2026-06-26
priority: medium
effort_estimate: 1 phase (~3-5 plans)
category: feature
references:
  - .planning/quick/260626-qgf-exibir-todos-mlbs-do-adgroup-sugador-no-/260626-qgf-SUMMARY.md
  - app/Services/Sugadores/MercadoLivreAdsService.php
  - app/Models/Sugador.php  # constante TIPO_ADGROUP, scope quarentena SGI
status: pending
---

# Ações ML diretas no Sugador: mover adgroup pra SGI + pausar via API

## Origem

Conversa ideação durante o quick task `260626-qgf` (drilldown de MLBs). Operador propôs:

> "já que estamos usando a API do próprio ML, existe a possibilidade de quando acharmos um adgroup sugador uma opção de ação a se tomar no sistema ser mover o adgroup para uma campanha de sugador (SGI) que normalmente é uma campanha pausada — ou então pausar diretamente tudo isso pelo sistema sem ter que ir até o mercado livre?"

Decisão na hora: **NÃO** entrar no quick 260626-qgf (escopo desse era só leitura/exibição). Abrir phase separada por ser ação destrutiva — precisa de salvaguardas próprias.

## Problema a resolver

Hoje o analista identifica um sugador-adgroup no painel ECF mas precisa **abrir o painel do Mercado Ads em outra aba e executar a ação manualmente** (mover pra campanha SGI ou pausar). Cliques redundantes, risco de pausar o adgroup errado, sem rastreabilidade no histórico do sugador.

## Escopo proposto

Aproveitar a integração ML já existente (`MercadoLivreAdsService` + token OAuth) para expor 2 ações no `Show.jsx` do sugador:

1. **Mover adgroup pra campanha SGI** (campanha de quarentena pausada)
   - Lista campanhas SGI da conta (regex `\b(sgi|sugadores?)\b/iu` já usada em `SugadorAnalysisService::QUARANTINE_NAME_REGEX`)
   - Operador escolhe a SGI destino (combobox)
   - `PATCH` no endpoint de adgroup do ML mudando `campaign_id` → criar campanha SGI nova se não existir (botão "Criar campanha SGI")

2. **Pausar adgroup in-place**
   - `PATCH /advertising/.../product_ads_groups/{id}` com `status: paused`
   - Versão "rápida" da ação 1 quando o operador não quer mover, só desligar

Ambas devem:
- Pedir **confirmação dupla** na UI (modal com nome do adgroup + texto da ação)
- Registrar em `activity_log` (Spatie) — quem fez, quando, qual adgroup, status anterior
- Atualizar `Sugador.status` → `movido` (já existe esse status no enum)
- Idealmente **persistir o status anterior** pra permitir undo numa janela curta (ex: 5 min)

## Pré-requisitos a validar antes da phase

- [ ] Confirmar que a API ML Product Ads **realmente aceita** `PATCH` de status em adgroup/campaign (especulação do quick 260626-qgf — não foi smoke-testado)
- [ ] Verificar escopo do token OAuth atual (`MlAdvertiser.scopes`) — precisa de escopo de escrita, não só leitura
- [ ] Confirmar com o operador se a campanha SGI é **uma por conta** ou **uma por estratégia** — afeta UX (combobox vs criar-na-hora)
- [ ] Decidir comportamento em caso de erro 4xx/5xx no PATCH (ex: token expirado, adgroup já removido)

## Risco principal

Ação **destrutiva direto na conta do cliente em produção**. Qualquer bug pode pausar receita do cliente sem aviso. Salvaguardas obrigatórias:

1. Confirmação dupla com nome literal do adgroup + nome da campanha destino
2. `activity_log` antes E depois da chamada
3. Try/catch defensivo com rollback de `Sugador.status` se PATCH falhar
4. Feature flag inicial (opt-in por usuário/role) até bater 50+ ações sem incidente
5. Undo na janela 5min (botão "Desfazer" que faz PATCH reverso)

## Estimativa

1 phase nova (44+), provavelmente 3-5 plans:
- Plan 44-01: smoke do PATCH na API ML (validar pré-requisitos acima)
- Plan 44-02: backend — `MercadoLivreAdsService` ganha métodos `moveAdgroup` e `pauseAdgroup`; controller actions; activity_log
- Plan 44-03: UI — modal de confirmação dupla, integração no `Show.jsx`
- Plan 44-04: undo + feature flag
- Plan 44-05 (opcional): ações em lote (selecionar N sugadores na listagem, mover/pausar todos)

## Quando rodar

Após a Phase 43 (remoção do path Adman) estabilizar. Não acelerar — não há urgência operacional; o operador hoje consegue via painel ML em ~5 cliques. Ganho é ergonômico, não funcional.

## Decisões já tomadas

- **NÃO incluir no quick 260626-qgf** — escopo de leitura vs ação destrutiva são incompatíveis (decidido pelo operador 2026-06-26).
- **Phase separada (não Plan de phase existente)** — toca em superfície de segurança nova (write na API ML), merece discuss-phase próprio.
