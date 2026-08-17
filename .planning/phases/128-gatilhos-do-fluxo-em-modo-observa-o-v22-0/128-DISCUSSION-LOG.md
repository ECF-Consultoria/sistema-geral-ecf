# Fase 128 — Log da discussão (2026-08-12)

Registro humano. Os agentes leem o `128-CONTEXT.md`, não este arquivo.

## Áreas apresentadas

Quatro, derivadas do Goal, dos 5 Success Criteria e da decisão A4 que o ROADMAP atribuiu a esta
fase. **O usuário selecionou as quatro.**

## Área 1 — A4: pendências no cadastro manual

Antes de perguntar, li as 7 pendências em `PendenciasComerciaisService::calcular()` e separei por
natureza: 4 dependem só do que foi cadastrado, 3 dependem de dado que só o handoff HubSpot produz.

| Opção | Escolhida |
|---|---|
| Só as 4 universais | ✅ |
| Também checar duplicidade no manual | — |
| Nenhuma pendência no manual | — |

Virou **D-01**. Descoberta durante a escrita do contexto e registrada: a decisão obriga mexer no
early-return `if (!$c->is_origem_hubspot) return []`, que a Fase 124 copiou literalmente deixando
a mudança para cá — e esse método **já alimenta a listagem do Comercial**, então mexer sem cuidado
muda uma tela que ninguém pediu para mudar.

## Área 2 — Dois portões que se sobrepõem

| Opção | Escolhida |
|---|---|
| Pendências primeiro, depois dados mínimos | ✅ |
| Um portão só, unificado | — |
| Só pendências comerciais | — |

Virou **D-02**. O critério de desempate foi **quem resolve o quê**: pendência comercial é do
Comercial, dado mínimo é de quem cadastra. Fundir faria a Fase 131 ter que separar de novo na tela.

## Área 3 — Onde vive "exige contrato"

| Opção | Escolhida |
|---|---|
| Coluna no cadastro de serviços | ✅ |
| Arquivo de configuração | — |

Virou **D-03**. Pesa a favor: a Fase 127 já criou `clicksign_template_id` na mesma tabela, então o
serviço passa a carregar *se* exige contrato e *qual* modelo usar no mesmo lugar.

## Área 4 — Empresa parada esperando o Comercial

| Opção | Escolhida |
|---|---|
| Reavalia sozinho quando a pendência some | ✅ |
| Só manual, por botão | — |
| Rotina diária | — |

Virou **D-04**. Argumento decisivo: o botão manual só existe a partir da Fase 131 — até lá, "só
manual" significaria contrato nenhum.

## Verificação feita durante a discussão

Confirmei por `grep` quem consome `PendenciasComerciaisService::calcular()`: apenas
`ComercialController::listagem()` (linha ~243). E quais testes dependem do comportamento atual:
`Phase37ComercialListagemTest` e `Phase114ComercialListagemEnrichmentTest`. Ambos registrados no
CONTEXT para o planejamento não descobrir isso quebrando a suíte.

## Decisões deixadas à discrição do Claude

- **O mecanismo** da reavaliação da D-04 (Observer, evento, varredura curta, ou combinação) — a
  decisão fixa o comportamento, não o meio.
- Como preservar a listagem do Comercial ao mudar o early-return (parâmetro, modo, ou mudança
  declarada) — o CONTEXT exige que seja **escolha explícita**, não efeito colateral.
- O default da coluna "exige contrato" na migration, desde que Polos saia isento e os outros 8 saiam
  exigindo.

## Nada foi redirecionado como scope creep

Dedup de empresa no cadastro manual foi levantado ao decidir a A4 e recusado — está em
`<deferred>`, não perdido.
