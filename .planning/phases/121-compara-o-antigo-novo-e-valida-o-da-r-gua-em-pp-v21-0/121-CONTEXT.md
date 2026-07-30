# Phase 121: Comparação antigo × novo e validação da régua em pp - Context

**Gathered:** 2026-07-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Esta fase **não muda cálculo nenhum**. Ela produz a **evidência** que decide se a flag da Fase 120 pode ser ligada: o delta por profissional entre a nota antiga e a nova, e a distribuição real de `margem_var_pp` na carteira.

**NÃO está nesta fase:** ligar a flag, persistir por empresa (122), telas (123).

</domain>

<decisions>
## Implementation Decisions

### Como as duas notas são obtidas

- **D-01 · UMA chamada, os dois resultados.** `compute()` roda **uma única vez** com o shadow ligado e devolve no mesmo payload a `nota_final` legada e o `empresas_score`, do qual a nota nova é derivada.
  **Razão:** duas chamadas seriam duas leituras da Adman, e esta milestone **já mediu** que a mesma empresa devolve valores levemente diferentes entre leituras. O delta ficaria contaminado por ruído de API, misturando mudança de fórmula com mudança de dado. Com uma chamada só, o universo, o NPS e a janela são idênticos por construção — o delta é puro.
  Comparar contra o **snapshot congelado** também foi descartado, pela mesma razão agravada pelo tempo: o snapshot de junho foi congelado numa passada específica de 31/07.

### Atribuição da causa do delta

- **D-02 · Decomposição por contribuição, isolando uma variável por vez.** O delta tem três fontes que se misturam: margem em pp × relativa, régua-por-empresa × régua-da-média, e empresas excluídas do denominador. O relatório calcula cada parcela isolando uma variável e nomeia a maior.
  ⚠️ **As parcelas não somam exatamente o delta total** — os efeitos interagem. **O relatório deve dizer isso explicitamente**, não esconder o resíduo. Inventar precisão aqui seria pior que admitir o limite.

### Escopo da distribuição de pp (ROLL-03)

- **D-03 · Só empresas com `financial_metrics_eligible = true`, em três competências** — a fechada mais as duas anteriores.
  **Razão:** medir todas as empresas Adman mediria a Adman, não o bônus — muitas não têm responsável e nunca entram no cálculo. E uma competência só não distingue "a régua comprime" de "junho foi atípico".
  **A pergunta que este histograma responde:** a régua reusada (D2 da milestone) comprime a distribuição na faixa 3-4? Se 80%+ das empresas caírem nessa faixa nas três competências, a compressão está confirmada e a D2 precisa ser revisitada — foi exatamente o efeito que o usuário aceitou conscientemente ao reusar a régua, e esta fase é onde ele vira número.

### Critério do gate

- **D-04 · Sem limiar automático. O comando informa; o usuário decide.**
  **Razão:** mudança de faixa de bônus é decisão de negócio, não de limiar técnico. Um teto de "quantas pessoas podem mudar de faixa" contradiria o propósito da milestone, que existe justamente para mudar a forma de calcular. E um teto de delta individual reprovaria justamente a correção grande — se alguém tinha nota inflada pela régua-da-média, corrigir muito é o resultado desejado.
  **O comando precisa apresentar bem:** delta por pessoa, quem muda de faixa e para qual, decomposição da causa por pessoa, e o histograma de pp. O SUMMARY registra a decisão do usuário e o número em que ela se baseou.

</decisions>

<canonical_refs>
## Canonical References

- `plano-implementacao-desempenho-por-empresa.md` §6 "Passo 2" e "Passo 3" — comando de comparação e as amostras de risco
- `.planning/REQUIREMENTS-v21.md` — ROLL-01, ROLL-02, ROLL-03; e a **D2 da milestone** (régua reusada como pp), que esta fase valida
- `.planning/phases/120-.../120-03-SUMMARY.md` — os 4 cenários espelho, que são o primeiro dado numérico da divergência; esta fase parte deles
- `.planning/phases/119-.../119-04-SUMMARY.md` — o risco régua-da-média ≠ média-das-réguas
- `app/Services/DesempenhoScoreService.php` — `compute($user, $mes, $periodoOverride, $incluirEmpresasScore)`; os métodos novos `computeNotaFinalPorEmpresa()` e `computeScoreStatusPorEmpresa()`
- `app/Services/Desempenho/CompanyScoreService.php` — a linha por empresa com `status` e `quality.motivos`
- `app/Console/Commands/ConsolidarMesDesempenho.php` — molde de comando que itera profissionais por competência

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`compute(..., incluirEmpresasScore: true)`** (Fase 120) — devolve os dois mundos num payload só. É o coração da D-01.
- **`computeNotaFinalPorEmpresa()` / `computeScoreStatusPorEmpresa()`** (Fase 120) — a nota nova e o status novo já existem; o comando só os consome.
- **`quality.motivos` por empresa** (Fase 119) — explica por que uma empresa ficou fora do denominador, insumo direto da parcela "denominador" da D-02.
- **`ConsolidarMesDesempenho`** — molde de iteração por profissional numa competência.

### Established Patterns
- **Persistir antes de agregar** — disciplina do probe (D-10 da Fase 117): conclusão se confere por reconsulta ao banco, nunca por stdout. Vale aqui: o relatório de comparação deve ser reconsultável, não só impresso.
- **Comando com `--mes=` obrigatório e competência FIXA** — mesma armadilha que o probe evitou.

### Integration Points
- Esta fase **não modifica** `DesempenhoScoreService` nem `CompanyScoreService`. Só lê.

</code_context>

<specifics>
## Specific Ideas

- As 7 amostras de risco da ROLL-02, que o relatório precisa tornar fáceis de achar: profissional com poucas empresas · com muitas · empresa com queda grande de faturamento · empresa com pp positivo · empresa sem baseline · empresa invalidada · profissional com Shopee.
- Referência já conhecida para conferir sanidade: a leitura da carteira do Luiz deu `~−0,59 pp`, que na régua reusada é nota 3 — contra régua 5 no snapshot congelado e régua 1 no cálculo local revertido.

</specifics>

<deferred>
## Deferred Ideas

- **Ligar a flag** — depende do gate MPP-04 e da decisão desta fase.
- **Recalibrar a régua para pp** — se o histograma confirmar a compressão, vira pauta de diretoria; está no Out of Scope da milestone.
- **Persistir a comparação como série histórica** — Fase 122, se fizer sentido.

</deferred>

---

*Phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0*
*Context gathered: 2026-07-30*
