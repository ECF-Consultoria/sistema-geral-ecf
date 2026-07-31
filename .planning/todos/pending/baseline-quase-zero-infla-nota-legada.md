---
created: 2026-07-31
source: debug/resolved/residuo-delta-douglas-danilo.md
criticality: alta
status: pending
---

# Baseline quase-zero pode estar inflando nota de desempenho no cálculo que está no ar

## O que foi observado

Na sessão de debug `residuo-delta-douglas-danilo` (2026-07-31), a reconstrução manual da `nota_antiga` do Douglas (4,00) mostrou que a régua de faturamento do cálculo legado opera sobre a **média bruta** de `faturamento_var_pct` de todas as empresas elegíveis, sem nenhuma proteção contra variação percentual calculada sobre baseline quase-zero.

Números confirmados por consulta direta ao banco (run_id `03787204-51a7-49fb-8478-da56a5b07e2a`, competência 2026-06):

| Média de `faturamento_var_pct` — carteira do Douglas | |
|---|---|
| Com as 27 empresas | **+766,25%** |
| Sem a empresa 332 | **−1,9%** |
| Segunda maior variação da carteira | +68,64% |

A empresa 332 ("Lojão do Bras") faturou ~R$0 em maio de 2026 e voltou a faturar em junho, produzindo `faturamento_var_pct = +20738,26%`. Não é crescimento econômico — é divisão por quase-zero. Ela sozinha leva o bucket da régua de faturamento de 3 pts para 5 pts.

## Por que importa

O `computeVarFaturamento()` legado é o cálculo **em produção hoje**, não apenas o lado "antigo" do comparador. Se o mecanismo for o mesmo, a nota de desempenho atual do Douglas está inflada agora, e isso alimenta bônus real.

**Isto NÃO foi medido** — é implicação forte da evidência da sessão de debug, não fato confirmado. A sessão foi encerrada antes de investigar (decisão do usuário, para não expandir escopo).

## O que investigar

1. Confirmar se o ranking/dashboard em produção usa o mesmo agregado sem guarda contra baseline quase-zero.
2. Levantar quantas empresas em quantas carteiras têm baseline quase-zero na competência anterior (a 332 aparece nas carteiras de Douglas E Danilo — provavelmente não é caso único).
3. Decidir a proteção: winsorização, exclusão de empresas com baseline abaixo de um piso, ou uso de mediana em vez de média.

Relacionado: a mesma classe de armadilha já mordeu este projeto em `created_at é artefato de reimport + histórico de métricas começa ~21/05` — empresas sem histórico suficiente distorcendo métrica derivada.
