---
created: 2026-08-03
source: sessão de 2026-07-31/08-03 (debug baseline-quase-zero + quick 260731-pvk)
status: decided-no-action
criticality: media
---

# Margem: decisão de NÃO mexer (régua nem agregação)

Decisão do usuário em 2026-08-03: **a régua não será modificada** e a margem **continua agregando por média** (`computeVarMargem()` com `avg()`), diferente do faturamento, que passou a usar mediana em 31/07.

Este arquivo existe para que ninguém refaça a análise. Ela já foi feita, com números.

## Por que a mediana NÃO serve para margem

Simulação read-only sobre o run `03787204-51a7-49fb-8478-da56a5b07e2a` (competência 2026-06):

| Cenário | Quem recebe bônus |
|---|---|
| Snapshot vigente | 5 de 10 |
| Média (dados de 31/07) | 6 de 10 |
| **Mediana (mesmos dados)** | **1 de 10** |

Faturamento e margem têm distribuições diferentes. No faturamento a mediana **coincide com a realidade econômica** (Douglas: mediana −3,46% contra crescimento real agregado de −2,3%). Na margem, a maioria das empresas fica perto de zero e poucas puxam para cima — a mediana joga quase todo mundo para 3 pontos e zera o bônus de cinco pessoas por um efeito que não corresponde a piora real de desempenho.

**Não aplicar mediana na margem "por coerência de critério".** A coerência aqui seria um erro.

## O problema real, conscientemente não resolvido

A régua de margem (`reguaMargem()`: ≤−5→1, ≤−2→2, ≤1→3, ≤4→4, >4→5) dá nota máxima para qualquer margem acima de 4%. Como a maioria fica entre 6% e 21%, quase todo mundo gabarita — e quem fica perto da fronteira muda de faixa por ruído de leitura.

Caso concreto: **Danilo perdeu o bônus de junho/2026** entre 31/07 e 01/08 sem nenhuma mudança de código. Estava em 4,24% (a 0,24 pp da fronteira de 4%) e a releitura deu 2,52% → margem caiu de 5 para 4 pontos → nota 4,22 → 3,89 → `basico` → `sem_bonus`. O faturamento dele ficou idêntico nas duas leituras.

Caso de instabilidade genuína: **Gustavo oscilou 7,8 pp** (7,52% → −0,27%) na mesma competência fechada, em 14 horas.

## Achados laterais não tratados

- **Felipe**: margem calculada sobre **3 empresas** de 30 na carteira — as outras não têm dado de margem. Essas 3 decidem um terço da nota dele.
- **Matheus Estrela**: nenhuma empresa com dado de margem (carteira só-Shopee, e Shopee não fornece margem). A nota de margem dele vem do placeholder.

## Se um dia isso voltar à pauta

O caminho não é trocar a agregação — é calibrar a régua (decisão de política de bônus, diretoria) ou reduzir a fragilidade de fronteira. Uma medição útil que ficou por fazer: quantas pessoas, em quantas competências, ficam a menos de 0,5 pp de uma fronteira de régua.
