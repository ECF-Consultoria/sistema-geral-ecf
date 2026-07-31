---
slug: residuo-delta-douglas-danilo
status: resolved
trigger: Na rodada real do comparador (Fase 121, run_id=03787204-51a7-49fb-8478-da56a5b07e2a), a decomposição do delta não explica a queda de nota de Douglas e Danilo — o resíduo não-decomposto é maior que qualquer parcela isolada (Douglas: resíduo −1,22 de um delta de −1,36; Danilo: −0,70 de −1,07). Nos outros 9 profissionais a decomposição explica. É a ressalva registrada no GATE do ROADMAP.
created: 2026-07-31
updated: 2026-07-31
criticality: alta
---

# Resíduo não-decomposto no delta de Douglas e Danilo

## Symptoms

**Expected behavior:**
A decomposição do delta (Plano 121-03) isola uma variável por vez e atribui a causa da diferença entre a nota antiga e a nota nova. As três parcelas — P1 (margem em pp × relativa), P2 (régua-por-empresa × régua-da-média) e P3 (composição do denominador) — devem explicar a maior parte do delta, com o resíduo da interação ficando pequeno. É assim que se comporta para 9 dos 11 profissionais.

**Actual behavior:**
Para dois profissionais o resíduo domina e a `maior_causa_delta` sai como `interacao_nao_decomposta`:

| Profissional | Delta | P1 | P2 | P3 | Resíduo | % do delta sem explicação |
|---|---|---|---|---|---|---|
| Douglas | −1.36 | +0.14 | −0.44 | +0.16 | **−1.22** | ~90% |
| Danilo | −1.07 | +0.06 | −0.51 | +0.08 | **−0.70** | ~65% |

Para comparação, um caso bem-comportado — Gabriela Aguiar: delta −1.06, P2 −0.84, resíduo −0.46 (a parcela isolada domina, como esperado).

O Douglas é justamente a maior queda individual de todas (nota 4.00 → 2.64) e cai de faixa `basico` → `sem_bonus`. Ou seja: o caso de maior impacto é também o menos explicado.

**Error messages:**
Nenhum. O comando rodou com `falhas=0` nas três competências, e não houve nenhum 429 / rate-limit no log do dia inteiro. Não é falha de execução — é limitação de atribuição de causa.

**Timeline:**
Primeira medição contra dados reais foi hoje, 2026-07-31 (run_id acima, competência alvo 2026-06). O comportamento nunca tinha sido observado antes porque o comparador nasceu nesta fase. Não sabemos se o resíduo já aparecia grande nos cenários sintéticos dos testes da Fase 121 — isso precisa ser checado, não assumido.

**Reproduction:**
`php artisan desempenho:comparar-score-empresa --run=03787204-51a7-49fb-8478-da56a5b07e2a` no VPS — reimprime o relatório inteiro em ~0,3s, sem tocar a Adman e sem gravar nada. Os números por empresa estão em `desempenho_comparador_empresas` para o mesmo `run_id`.

## Contexto relevante

- Douglas: 29 empresas (23 complete / 6 partial). Danilo: 30 empresas (27/3). Não são carteiras pequenas nem majoritariamente `partial` — logo a hipótese fácil de "poucos dados" não se sustenta de cara.
- Ambos mantiveram `status: official → official` (não viraram `partial`), diferente de Felipe/Matheus/Gustavo.
- A sessão `margem-adman-diff-instavel` (resolvida em 2026-07-23) atribuiu instabilidade de margem a falhas 429 ao vivo. **Esta rodada teve zero 429**, então essa causa provavelmente não se aplica aqui — mas o mecanismo de fallback que aquela sessão descreveu pode ser relevante.
- Decisão do usuário no GATE: aceito COM RESSALVAS, e esta é exatamente a ressalva. Precisa estar resolvida antes da ativação da flag (`metrics.performance_company_first_score`, hoje `false`).

## Current Focus

- **hypothesis:** CONFIRMADA — não é bug de cálculo, é limitação metodológica conhecida (o próprio `DecomposicaoDeltaTest` cenário 3 já documentava isso). O resíduo grande de Douglas/Danilo é causado por DUAS dimensões que nenhuma das 3 parcelas testa: (1) escopo de população — `computeVarFaturamento()`/`computeVarMargem()` (legado) somam TODA empresa elegível com `diff_pct` presente, sem filtrar por `status='complete'`, enquanto P1/P2 só usam o `conjuntoC` (só `complete`); (2) NPS legado (`computeNpsWindow`) é um pipeline totalmente separado, nunca variado por nenhuma parcela.
- **next_action:** aplicado fix aditivo (novos avisos de escopo) + teste local verde. Falta decisão do usuário: rodar `--force` no VPS (custa ~12min + Adman) para confirmar os avisos na rodada real de Douglas/Danilo, ou aceitar a verificação local como suficiente.
- **test:** `DecomposicaoDeltaTest::test_aviso_de_escopo_reporta_empresas_fora_de_c_com_fat_ou_margem_presentes` (fixture hand-computada)
- **expecting:** avisos `empresas_fora_de_c_no_agregado_legado` e `empresas_fora_de_c_fat_extremo` aparecem corretamente na decomposição

## Evidence

- timestamp: 2026-07-31 (investigação)
  checked: `.planning/phases/121-.../121-03-SUMMARY.md` e `tests/Feature/Phase121/DecomposicaoDeltaTest.php` (cenário 3, linhas 361-366)
  found: o próprio teste já documenta e prova, com fixture hand-computada, que o resíduo pode dominar 100% do delta "porque a nota antiga vem de um pipeline metodologicamente diferente do que produz as parcelas" — comportamento ESPERADO e TESTADO desde o Plano 03, não um defeito introduzido depois.
  implication: a pista 1 (aditividade por construção) estava certa — a decomposição usa contrafactuais "leave-one-out" a partir de `nota_nova`, nunca reconstrói `nota_antiga` variando as 3 dimensões simultaneamente a partir dela. Não é bug de cálculo.

- timestamp: 2026-07-31 (consulta VPS, run_id=03787204-51a7-49fb-8478-da56a5b07e2a)
  checked: linha de Douglas (user_id=19) em `desempenho_comparador_profissionais`: nota_antiga=4.00, nota_nova=2.64, alt1=2.50, alt2=3.08, alt3=2.48
  found: nenhuma das 3 contrafactuais (alt1/alt2/alt3) chega perto de 4.00 — a mais próxima (alt2=3.08) ainda fica a quase 1 ponto de distância. Reconstruindo manualmente a partir das 29 linhas de `desempenho_comparador_empresas` (target=2026-06): régua(faturamento) sobre a média de TODAS as 27 empresas com `faturamento_var_pct` presente (complete+partial) = 766,25% → 5 pts; régua(margem) sobre a média de TODAS as 27 com `margem_diff_pct` presente = 18,48% → 5 pts; (5+5+nps)/3=4,00 → nps≈2,00. Bate exatamente com nota_antiga=4,00.
  implication: nota_antiga usa a população INTEIRA de empresas elegíveis (23 complete + 6 partial), não só o `conjuntoC` (23) que todas as 3 parcelas usam. As 6 empresas `partial` de Douglas (excluídas de C por falta de `margem_var_pp`) ainda tinham `faturamento_var_pct`/`margem_diff_pct` presentes e entraram no agregado legado sem nunca aparecer em nenhuma parcela.

- timestamp: 2026-07-31 (consulta VPS)
  checked: empresa cid=332 ("Lojão do Bras"), presente nas carteiras de Douglas E Danilo, status='partial' (motivo: margem_pp_indisponivel), `faturamento_var_pct=20738,26%`
  found: histórico de `AdmanMetric.revenue` mostra R$0,00 de 12 a 18/06 e só passa a faturar a partir de 19/06 — baseline de maio praticamente zero. O `diff_pct` de +20738% é artefato de baseline quase-zero, não sinal econômico real.
  implication: essa empresa sozinha, incluída sem filtro no agregado legado (régua-da-média), empurra a média bruta de faturamento de Douglas de ~0,79% (só C, 23 empresas) para 766,25% (27 empresas) — suficiente para virar o bucket de régua de 3pts para 5pts. Como ela é 'partial' (excluída de C), NENHUMA das 3 parcelas testa esse efeito. Danilo compartilha a mesma empresa 332 na carteira (mesmo padrão, resíduo também dominante).

- timestamp: 2026-07-31 (cálculo local)
  checked: reconstrução análoga para Danilo (user_id=15): fat média-C(27→ excluindo 332 parcial)=9,00% (>5→5pts) vs fat média-completa(30)=699,68% (>5→5pts) — mesmo bucket nos dois casos para faturamento; margem também bate no bucket 5pts nos dois casos.
  implication: para Danilo o efeito de escopo (outlier cid=332) NÃO muda o bucket final de fat/margem — logo o resíduo de Danilo é mais provavelmente dominado pela dimensão NPS legado (não testada por nenhuma parcela), não pela mesma causa exata de Douglas. As duas dimensões (escopo de população E metodologia de NPS) são independentes e ambas ficam invisíveis no relatório atual.

## Eliminated

- hypothesis: o resíduo é um bug de cálculo em `calcularDecomposicao()` (soma errada, sinal trocado, etc.)
  evidence: a fórmula `residuo = delta - soma(parcelas)` é uma identidade por construção (não uma aproximação que deveria fechar); `DecomposicaoDeltaTest` (4 cenários pré-existentes + o novo) prova a aritmética correta com fixtures hand-computadas. `--filter=Phase121` 20/20 verde, `--filter=Desempenho` 14 failed/100 passed (baseline exata do Plano 03, zero regressão).
  timestamp: 2026-07-31

## Resolution

root_cause: >
  NÃO é um bug de cálculo. É uma limitação metodológica conhecida e já testada
  (DecomposicaoDeltaTest cenário 3) do desenho "leave-one-out" da decomposição:
  as 3 parcelas partem de `nota_nova` e revertem UMA dimensão por vez, mas nunca
  reconstroem `nota_antiga` de fato — que vem de um pipeline estruturalmente
  diferente (`computeVarFaturamento`/`computeVarMargem`/`computeNpsWindow`).
  Duas dimensões reais nunca são testadas por nenhuma parcela: (1) ESCOPO DE
  POPULAÇÃO — o agregado legado soma toda empresa elegível com diff_pct
  presente (complete+partial), enquanto as 3 parcelas restringem a `conjuntoC`
  (só complete); (2) METODOLOGIA DE NPS — `computeNpsWindow` (legado,
  portfolio-wide) nunca é variado, todas as parcelas usam o NPS por-empresa do
  pipeline novo. Para Douglas, o efeito de escopo é concreto e quantificado: a
  empresa cid=332 ("Lojão do Bras", baseline de maio ~R$0, artefato de
  variação de +20738%) está em 'partial' (excluída de C) mas ainda entra no
  agregado legado, empurrando o bucket de régua de faturamento de 3pts (só C)
  para 5pts (população completa) — consistente com nota_antiga=4,00
  reconstruída manualmente. Danilo compartilha a mesma empresa 332, mas nesse
  caso o efeito de escopo não muda o bucket final — o resíduo dele é mais
  provavelmente dominado pela dimensão de NPS, não testada.
fix: >
  Fix aditivo e de baixo risco em `calcularDecomposicao()`
  (app/Console/Commands/CompararScoreEmpresa.php): dois novos campos em
  `avisos` — `empresas_fora_de_c_no_agregado_legado` (conta linhas fora do
  conjuntoC que ainda têm faturamento_var_pct OU margem_diff_pct presentes,
  ou seja, que entrariam no agregado legado sem aparecer em nenhuma parcela)
  e `empresas_fora_de_c_fat_extremo` (subconjunto com |faturamento_var_pct| >
  200%, heurística para baseline quase-zero). NENHUM valor de nota_final,
  nota_final_por_empresa, parcela (P1/P2/P3), resíduo ou maior_causa_delta foi
  alterado — é só visibilidade adicional, mesmo espírito de
  `p1_empresas_sem_diff_pct` já existente. A dimensão NPS legado não foi
  instrumentada nesta rodada de debug (ficaria fora do escopo de um fix
  aditivo simples — precisaria de acesso ao resultado de `computeNpsWindow`
  dentro do comando, que hoje não é exposto no payload).
verification: >
  Local (SQLite): novo teste
  `test_aviso_de_escopo_reporta_empresas_fora_de_c_com_fat_ou_margem_presentes`
  verde, prova a contagem correta com fixture hand-computada (2
  empresas fora de C com fat/margem presentes, 1 delas extrema). Suíte
  completa: `--filter=Phase121` 20/20 verde (19 anteriores + 1 novo);
  `--filter=Desempenho` 14 failed/100 passed — IDÊNTICO à baseline documentada
  no 121-03-SUMMARY.md antes desta mudança (zero regressão).
  PENDENTE: confirmação em dado real do VPS — o run_id
  03787204-51a7-49fb-8478-da56a5b07e2a já persistido NÃO ganha os novos
  avisos retroativamente (eles só são calculados em `handle()`, na coleta,
  não em `--run=`). Confirmar exige uma rodada nova (`--force`, ~12min +
  chamadas à Adman) — não executada nesta sessão por decisão de custo,
  aguardando autorização do usuário.
files_changed:
  - app/Console/Commands/CompararScoreEmpresa.php
  - tests/Feature/Phase121/DecomposicaoDeltaTest.php


## Decisao do usuario (2026-07-31)

Encerrada com a **verificacao local aceita** — sem rodada `--force`. Justificativa: a causa-raiz ja foi provada por reconstrucao contra os dados reais persistidos (as 29 empresas do Douglas reconstroem exatamente `nota_antiga=4,00`), e os dois avisos novos tem teste com fixture calculada a mao. A rodada `--force` custaria ~12min + cota da Adman apenas para observar os avisos populados, e eles entram sozinhos na proxima coleta natural.

Confirmacao independente feita pelo orquestrador (consulta ao banco, sem custo de API): media de `faturamento_var_pct` da carteira do Douglas = **+766,25% com as 27 empresas** contra **-1,9% sem a empresa 332**. A segunda maior variacao da carteira e +68,64%. Uma unica empresa com baseline de maio ~R$0 inverte o retrato da carteira inteira.

### Achado que extrapola esta sessao (nao investigado)

Se a reconstrucao estiver certa, a falta de protecao contra variacao percentual sobre baseline quase-zero esta no calculo **legado — o que esta no ar hoje**, nao so no comparador. Isso implicaria que a nota de desempenho atual do Douglas pode estar inflada agora, valendo bonus real. **Nao foi medido** — e implicacao forte da evidencia, nao fato confirmado. O usuario optou por nao investigar nesta rodada. Registrado como todo pendente.
