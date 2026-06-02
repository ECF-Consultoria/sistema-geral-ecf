---
id: 260602-recomendacao-ia-mag-t8-fase2
created: 2026-06-02
priority: high
effort_estimate: 1-2 fases
category: feature
references:
  - .planning/phases/17-coleta-de-dados-ml-intelig-ncia-de-an-ncios-do-mercado-livre/17-05-SUMMARY.md
  - C:/Users/User/Documents/painel-ecf/PROMPT_MAG_T8_ECF_Consultoria.md
status: pending
---

# Fase 2 — Recomendação por IA no framework MAG T8

## Contexto

A Fase 17 entregou a Coleta de Dados ML com **recomendação heurística** (D-05, sem IA, com aviso "Fase 2"). No checkpoint visual da página `/mlb/coleta`, o usuário validou que a feature funciona, mas que **a recomendação heurística tem pouco valor porque não conhece o produto do usuário** — ele quer informar as especificações do produto e receber de volta o que faz sentido frente à 1ª página de concorrentes.

## Escopo proposto (Fase 2)

1. **Entrada de specs do produto** — campo/textarea no formulário de coleta (nome do produto, especificações, empresa). Nova coluna em `mlb_coletas` (ex: `produto_specs` text nullable).
2. **Análise por IA (Claude API)** — gerar a recomendação cruzando: specs do produto + dados coletados (ranking de keywords, top dúvidas, tendências, produtos da 1ª página). Saída no framework **MAG T8** da ECF:
   - Parte 1: Análise Estratégica (Persona, Mapa de Empatia, Jornada, Gatilhos, JTBD, PUV, Funcionalidades-chave, Diferencial, Prova Social)
   - Parte 2: Roteiro de 7 imagens estratégicas
   - Parte 3: Texto da descrição do anúncio + título sugerido
3. **Integração técnica** — `ANTHROPIC_API_KEY` + billing; usar o modelo Claude mais recente; **prompt caching** do template MAG T8 (template fixo) para reduzir custo de entrada.

## Custo (estimativa)

Pay-as-you-go por token. ~US$ 0,01–0,03/coleta (Haiku), ~US$ 0,05–0,10 (Sonnet), ~US$ 0,30–0,40 (Opus). Para uso interno da equipe de Publicação, poucos dólares/mês.

## Pendência de decisão

Usuário ainda vai decidir sobre a chave/billing Anthropic e o modelo. Ver memória do projeto `project_mag_t8_fase2_ia` e a extensão Chrome em `C:/Users/User/Documents/painel-ecf` (mesmo domínio).
