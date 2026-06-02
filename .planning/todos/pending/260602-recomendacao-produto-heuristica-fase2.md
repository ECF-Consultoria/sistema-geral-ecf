---
id: 260602-recomendacao-produto-heuristica-fase2
created: 2026-06-02
updated: 2026-06-02
priority: high
effort_estimate: 1 fase
category: feature
references:
  - .planning/phases/17-coleta-de-dados-ml-intelig-ncia-de-an-ncios-do-mercado-livre/17-05-SUMMARY.md
  - C:/Users/User/Documents/painel-ecf/PROMPT_MAG_T8_ECF_Consultoria.md
status: pending
---

# Fase 2 — Recomendação ciente do produto (heurística, SEM IA / SEM custo)

## Decisão do usuário (2026-06-02)

**NÃO usar API de IA. SEM custo recorrente.** A recomendação deve melhorar de forma 100%
heurística (PHP puro, estendendo o `MlKeywordMinerService` que já existe), e o MAG T8
continua sendo rodado **manualmente** pelo usuário no chat de IA que ele já usa de graça.

## Problema a resolver

A recomendação da Fase 1 é genérica porque não conhece o produto do usuário. O usuário quer
informar as especificações do produto e receber o que faz sentido frente à 1ª página de
concorrentes — sem que a aplicação pague por IA.

## Escopo proposto (heurístico, zero custo)

1. **Entrada de specs do produto** na coleta — `nome do produto` + `especificações/diferenciais`
   (texto livre). Nova coluna `produto_specs` (text nullable) em `mlb_coletas`.
2. **Cruzamento heurístico (PHP)** das specs do usuário com o ranking de keywords coletado:
   - **Oportunidades:** keywords de alta frequência na 1ª página que o usuário NÃO usa.
   - **Já cobertas:** keywords que o usuário já menciona.
   - **Diferencial:** termos das specs do usuário que os concorrentes NÃO destacam.
3. **Título sugerido ciente do produto:** nome do produto + top keywords que faltam,
   respeitando o limite de título do ML (~60 chars).
4. **Dúvidas a antecipar:** já vêm de `top_duvidas` (agruparPerguntas).
5. **Botão "Copiar prompt MAG T8"** (zero custo): gera o texto do prompt MAG T8
   (`PROMPT_MAG_T8_ECF_Consultoria.md`) já preenchido com [nome do produto] + os dados
   coletados (ranking, dúvidas, diferencial), pronto para o usuário colar no chat de IA
   que já usa. A aplicação NÃO chama IA.

## Não-objetivos

- Nenhuma chamada a Claude/OpenAI/qualquer LLM dentro da aplicação.
- Nenhuma `ANTHROPIC_API_KEY` / billing.

## Notas

- Estende `MlKeywordMinerService` (PHP puro) e a página `Mlb/Coleta.jsx` (form + card de
  recomendação). Sem novas dependências externas.
- Extensão Chrome relacionada: `C:/Users/User/Documents/painel-ecf`.
