---
id: 270701-melhorias-sugadores-ui-ux-e-detector
created: 2026-07-01
title: Melhorias Sugadores — UI/UX + Detector
area: sugadores
resolves_phase: 67
status: pending
---

# Melhorias Sugadores — UI/UX + Detector

**Capturado:** 2026-07-01
**Fonte:** briefing do operador
**Rota:** 2 phases (52 UI/UX + 53 detector)

---

## Bloco A — UI/UX + comportamento (Phase 52)

### A1 — Permissão analista falta
Botão de "Configurar sugador" não aparece para usuário com cargo `analista`. Analista precisa ver e usar a configuração. Verificar policy/gating no `SugadorController` e/ou middleware.

### A2 — Nomenclaturas antigas da era Adman (remover)
Cards / textos ainda referenciam análise automática que era da API Adman e hoje não roda:
- Card "Análise OK hoje"
- Frase "Análise diária já rodou hoje · próxima amanhã às 12h"

Como não existe cron ML de análise diária ainda (seed futuro), remover essas mensagens enganosas.

### A3 — Card lateral com config de captura
Na listagem de sugadores da empresa (drilldown), adicionar card ao lado mostrando como está a configuração de captura da empresa + botão levando para a página de configuração.

### A4 — Coluna "empresa" redundante na listagem
Na listagem de sugadores dentro de uma empresa, remover a coluna "empresa" (já está no subtítulo/breadcrumb da tela).

### A5 — Bug "Copiar MLBs" na listagem
Botão "Copiar MLBs" na listagem retorna "sem MLBs" mas ao entrar no sugador o botão análogo funciona e copia. Investigar: props que a listagem passa vs o que a view do sugador carrega.

### A6 — Ação em massa "Copiar MLBs dos selecionados"
Já existem checkboxes de ação em massa. Adicionar ação que junta todos os MLBs dos sugadores selecionados e copia para clipboard.

### A7 — Remover botão "Rodar análise" do card empresa
Card empresa NÃO deve ter botão de rodar análise. Só ao acessar (drilldown) o card.

### A8 — Comportamento do botão "Rodar análise" na view empresa
Hoje: alert "Rodar análise para TODAS as empresas com config ativa? Isso pode levar alguns minutos." — comportamento errado (usuário não deve poder rodar em massa).

Deve ser:
- Roda análise **só para a empresa em questão**
- Mostra cronômetro/timer na tela (~30s) para o usuário ver o progresso e não achar que travou

### A9 — Remover visualização "lista" de empresas
Só manter visualização por card.

---

## Bloco B — Inteligência do detector (Phase 53)

3 casos reais capturados que hoje viram falso-positivo (contas ML recém-conectadas expuseram o problema).

### B1 — CAMILLO PARTS: anúncio indisponível/pausado
```
Adgroup: 1902557017
Produto: Caixa De Direcao Hidraulica Chery Face 1.3 16v 2011
Detectado 01/07/2026 · Janela 01/06 → 30/06

Sistema mostra: 0 vendas
ML mostra: 2 vendas + tag "Este produto está indisponível no momento."
```

Interpretação: anúncio provavelmente já foi resolvido pelo time (pausado no ML). Não deveria ser flagged como sugador.

**Ação:** detector deve consultar status do MLB (ativo/pausado/indisponível) e excluir da lista se estiver pausado/indisponível.

### B2 — BARAOSHOP VARIEDADES: sync ML não traz MLBs
Empresa BARAOSHOP:
```
Adgroup: 496843010
Produto: Meia 7/8 Sigvaris Antitrombo Sem Ponteira 18-23 Mmhg Estéril
Janela 01/06 → 30/06

Frase exibida: "✓ Dado atualizado · última sincronização em 01/07, 03:02
Nenhum MLB encontrado neste adgroup no período."

ML mostra: anúncio com 500+ vendas + no FULL
```

Interpretação: sync não está trazendo os MLBs deste adgroup — dado incompleto. Se o anúncio tem 500+ vendas, é altamente improvável que TODAS sejam >30d.

**Ação:** investigar sync ML de MLBs por adgroup (`SyncMlbsPorAdgroup`?) e cobertura da janela.

### B3 — DINMAP: anúncio com vendas dentro do período foi flagged
```
Adgroup: 1784220962
Produto: Bota Pvc Cano Extra Curto Bracol Impermeável Limpeza Forrada Branco 41 Br
Janela 01/06 → 30/06

Sistema flagged como sugador
ML mostra: bastante vendas, inclusive dentro do período

Contexto adicional: um dos sugadores desta empresa está em campanha SGI = resolvido
```

Interpretação: critério de "não tem vendas" ou de custo/receita está errado em algum caso. Detector precisa reavaliar a lógica.

Também: relação com SGI (quarantine) — sugador que está em SGI já resolvido não deveria aparecer.

**Ação:**
- Auditar critério de detecção (SugadorAnalysisService)
- Confirmar que adgroups em campanhas SGI/quarentena não entram
- Cross-check com contagem real de vendas ML

---

## Roteamento

| Bloco | Phase | Escopo | Complexidade |
|---|---|---|---|
| A | **Phase 52** — Melhorias UI/UX + comportamento /sugadores | A1..A9 | Média (9 itens, quase todos frontend) |
| B | **Phase 53** — Inteligência do detector de sugadores | B1..B3 | Média/alta (backend SugadorAnalysisService + sync ML) |

Phase 52 é mais rápida e destrava valor operacional imediato. Phase 53 pode rolar em paralelo mas exige research profundo em SugadorAnalysisService + sync ML.

Independentes entre si.
