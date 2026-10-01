---
quick_id: 261001-gi1
slug: fechamento-avisa-quando-o-dado-mudou-depois
date: 2026-10-01
type: quick
status: pending
---

# O fechamento avisa quando foi fechado com dado que mudou depois

## O incidente que gerou isto (2026-10-01, produção)

Setembro foi consolidado às **10:23**. O sync da Shopee rodou às **10:42** e reescreveu o mês inteiro
de 19 empresas. A competência ficou gravada com faturamento de Shopee incompleto e **ninguém foi
avisado**: quem descobriu foi o usuário, à mão, estranhando uma empresa.

Medido antes de refazer:

| empresa | gravado na consolidação | soma real depois do sync |
|---|---|---|
| Gabs Folheados (#395) | 6.378,91 | **40.154,54** |
| GENUINEAUTOMOTIVE | 1.231.685,86 | 1.278.335,84 |
| MPozenato | 1.568.239,37 | 1.612.024,72 |
| … | | |
| **total divergindo** | **19 de 19** empresas com Shopee | todas para cima |

Refeito às 11:38, tudo bateu e **nenhuma faixa mudou** — desta vez. Foi sorte: a Gabs saltou 6× e
continuou na mesma faixa. Com outro valor, a cobrança sairia errada e ninguém saberia.

⚠️ **O mesmo risco vale para a Adman**: `adman:sync` roda 11:00, o `adman:warm-fechamento` 11:50 e a
`adman:reler-dias` 19:00 (as duas últimas são do quick 260930-njd, de ontem). Fechar antes disso grava
número velho.

---

## T1 — A verificação passa a comparar faturamento

Já existe `fechamento:verificar-consolidacao` (⚠️ leia o que ele faz hoje antes de mexer: confere
**estrutura** — linhas órfãs, divergência de contagem de grupo). Ele é o lugar natural.

Acrescentar uma conferência de **números**, por competência:

- para cada linha gravada, recalcular o faturamento **de agora** (mesmo caminho do rollup, não uma
  query nova inventada) e comparar com o gravado
- relatar, por empresa: gravado, atual, diferença e **se a faixa mudaria** — a faixa é o que decide
  dinheiro; diferença que não muda faixa é informação, diferença que muda faixa é urgência
- separar as duas causas, porque o remédio é diferente:
  - **Shopee/base local** mudou depois (foi o caso de hoje)
  - **Adman** revisou a janela (é esperado, e é por isso que existe a releitura)
- ⛔ **o comando NÃO conserta nada sozinho**: ele relata. Quem refaz é uma pessoa (ou a rotina), com o
  motivo registrado. Consolidação automática em cima de divergência tira a decisão de quem responde
  pela cobrança.

Saída enxuta: uma linha por empresa divergente, e um resumo com quantas mudariam de faixa.

---

## T2 — Dá para saber se o dado mudou depois sem recalcular tudo

Barato e direto: comparar o `gerado_em` da competência com o **maior** `updated_at`/`synced_at` dos
dados do mês (`shopee_metrics`, `adman_metrics`). Se houver dado do mês gravado **depois** da
consolidação, dizer isso em uma frase, com a hora dos dois lados.

Isso serve para a tela também: a tela do fechamento pode mostrar, em mês fechado, um aviso discreto
— *"este fechamento foi gravado em 01/10 10:23 e há dado deste mês atualizado depois, às 10:42.
Vale refazer."* ⚠️ **Sem jargão** (nada de "snapshot", "sync", "rollup"), e sem alarme quando não há
divergência.

⚠️ Cuidado com o falso positivo: `adman_metrics` recebe escrita todo dia pela releitura das 19h. Um
aviso que aparece todo santo dia vira ruído e ensina a ignorar. Sugestão: só avisar quando a diferença
**altera algum valor de faturamento** gravado (T1), não pela simples existência de escrita posterior.
Decida e **escreva o porquê** no SUMMARY.

---

## T3 — A rotina na ordem certa

Hoje a consolidação depende da hora em que a pessoa aperta o botão. Propor (e implementar, se couber
no quick) o caminho mais simples: agendar a consolidação do **primeiro dia útil** depois dos syncs do
dia — 11:00 (Adman), 11:50 (aquecimento), 13:00 (polos), e a releitura das 19:00 do dia anterior já
terá rodado.

⚠️ **Não** agendar consolidação automática sem o usuário decidir: hoje é um ato humano com motivo
registrado. Se ficar fora do escopo, registre como item deferido — o T1 e o T2 já resolvem o essencial
(ninguém mais descobre pela própria empresa).

---

## Testes

| caso | espera |
|---|---|
| competência sem divergência | relata "tudo bate", sem alarme |
| faturamento gravado ≠ atual, mesma faixa | relata como informação |
| faturamento gravado ≠ atual, **faixa mudaria** | destaca, e o resumo conta quantas |
| dado do mês escrito depois do fechamento | relata hora do fechamento × hora do dado |
| só escrita posterior, sem mudar valor | **não** alarma (senão vira ruído diário) |
| comando não altera nada | nenhuma linha de fechamento é escrita (prove por reconsulta) |
| tela em mês fechado | aviso só quando há divergência de valor |
| copy | sem jargão |

---

## Travas

⛔ Não tocar em `FechamentoFaixaResolver::classificar()`, `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes`, `podeUsarApiDaAdman()`.
⛔ O comando de verificação **não** escreve em fechamento — nem para "corrigir".
⛔ Sem deploy, sem `.env`, sem VPS, sem produção. Quem roda em produção é o orquestrador.
⛔ Nada de `cache:clear`.

⚠️ **Outro dev trabalha na mesma árvore** (77 commits na semana passada: Portal, Demandas Dev,
Tickets, Polos, PPA). Nunca `git add -A` / `git add .` / `git commit -a` / `git stash`;
`git status --porcelain app/ tests/ resources/ routes/` antes de cada commit; arquivo novo precisa de
`git add -- <caminho>`. `tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Reaproveite o rollup existente para recalcular — **não** escreva uma segunda fórmula de
faturamento. Duas fórmulas divergem com o tempo, e aí a verificação passa a mentir.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` se mexer em `.jsx`. Comentários, copy e commits em pt-BR.

**Gates (exit antes de qualquer pipe; antes de editar e ao final):**
`--filter="Phase137|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260915|Quick260916|Quick260922|Quick260930|Quick261001"`
e `--filter="Phase74|Phase110"`.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final.
