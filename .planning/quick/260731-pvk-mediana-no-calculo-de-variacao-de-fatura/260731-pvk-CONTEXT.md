---
quick_id: 260731-pvk
created: 2026-07-31
source: debug/resolved/baseline-quase-zero-producao.md
---

# Decisões travadas (não reabrir)

Discussão já conduzida com o usuário nesta sessão. Estas decisões são **locked** — o planner deve tratá-las como dadas.

## D-1 — Mediana, não piso de corte

A média de `faturamento_var_pct` deixa uma única empresa dominar a carteira. A primeira proposta foi um **piso** (empresa com faturamento abaixo de X no mês-base sai da conta). **O usuário RECUSOU explicitamente:** *"Não deve existir essa regra, toda faixa de faturamento deve ser considerada."*

Solução escolhida: **trocar média por mediana**, que mantém 100% das empresas na conta e apenas impede que uma sozinha mande no resultado.

**Proibido:** qualquer forma de exclusão, filtro por valor mínimo, winsorização ou cap. Toda empresa com `diff_pct` presente continua entrando.

## D-2 — Só faturamento; margem fica de fora

Alterar **apenas** `computeVarFaturamento()` (`app/Services/DesempenhoScoreService.php`, ~linha 1363, o `round($vars->avg(), 2)`).

**NÃO alterar** `computeVarMargem()` (~linha 1439), que tem a mesma estrutura e a mesma exposição. Motivo: o impacto da margem em faixa de bônus **não foi simulado**, e mudar as duas de uma vez alteraria pagamento por efeito não medido. Fica para uma rodada futura com simulação própria.

## D-3 — Critério: cada empresa vale um voto igual

O usuário escolheu medir **desempenho médio por cliente** (mediana, cada empresa com peso igual) e não crescimento em reais da carteira (soma agregada, empresas grandes pesando mais). A régua `reguaFaturamento()` não muda — só o número que chega nela.

## D-4 — Reconsolidar 2026-06 depois do deploy

Após o código no ar, rodar `desempenho:consolidar-mes --mes=2026-06`. **Conferência obrigatória por reconsulta ao snapshot**, nunca por stdout — o gate FIXMARG-03 recusa gravar quando a cobertura de margem é baixa e reporta só uma contagem na saída.

# Impacto medido em produção (competência 2026-06)

Simulação read-only já executada contra o snapshot congelado e o run_id `03787204-51a7-49fb-8478-da56a5b07e2a`:

| Pessoa | Hoje | Com mediana | Efeito |
|---|---|---|---|
| Douglas | 4,00 basico | 3,00 sem_bonus | perde o bônus (distorção comprovada) |
| Nathalia Martins | 4,03 basico | 3,69 sem_bonus | perde o bônus (efeito do critério novo) |
| Stefani | 4,56 intermediario | 4,22 basico | cai uma faixa |
| Danilo | 4,55 intermediario | 4,22 basico | cai uma faixa |

Recebem bônus: **8 de 11 hoje → 6 de 11 com mediana.**

Causa raiz (do debug `baseline-quase-zero-producao`): a empresa 332 "Lojão do Bras" faturou R$ 79,98 em maio e R$ 16.666 em junho; a Adman devolve `.diff` de +20.738% e o código repassa sem validar a magnitude do baseline. Isso levou a carteira do Douglas de **−2,3% real** (ela encolheu R$ 131 mil) para **+766,25%** na nota, garantindo 5/5 pontos em crescimento.

# Armadilhas conhecidas deste projeto

1. **Bump da chave de cache é obrigatório.** `DesempenhoScoreService` cacheia por `desempenho.compute.vN`. Sem o bump, o dashboard continua servindo a nota antiga. O bump **quebra um lote de testes** que fixam a versão como string literal (Phase96/V16/V18) — atualizar essas strings junto, no mesmo commit.
2. **Nunca rodar `cache:clear` no VPS.** Derrubou o site inteiro em 2026-07-30. Depois de um bump de chave o clear é desnecessário.
3. **Árvore git compartilhada** com outras sessões e outro dev: `git commit -- <paths>` sempre; **nunca** `git add -A`.
4. `Collection::avg()` descarta `null` sozinho — a mediana precisa fazer o mesmo explicitamente, senão `null` entra na ordenação e envenena o valor do meio.
5. Mediana de coleção vazia deve devolver `null`, igual ao comportamento atual de `avg()` — não `0`.
