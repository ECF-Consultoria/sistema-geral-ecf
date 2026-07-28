# Phase 118: NPS por empresa - Context

**Gathered:** 2026-07-28
**Status:** Ready for planning

<domain>
## Phase Boundary

Esta fase entrega **um serviço de leitura**: a nota de NPS de um profissional agrupada por `company_id`, preservando os três ramos, a janela M+1, as dedupes e as invalidações que já existem.

**NÃO está nesta fase:** calcular `nota_empresa` (é a Fase 119), agregar a nota do profissional (Fase 120), mudar telas (Fase 123). Esta fase não altera nenhum número em produção — é insumo para a 119.

</domain>

<decisions>
## Implementation Decisions

### De quem é o NPS que entra na nota da empresa

- **D-01 · A `nota_empresa` usa a nota da dimensão do PAPEL do profissional naquela empresa.** Estrategista entra com a nota de estrategista; analista, com a de analista. **Não** se usa a dimensão `empresa`.
  **Razão:** a milestone v21.0 muda *granularidade*, não *de quem é o NPS*. O bônus hoje lê `nps_score_assignments`, que já é por papel. Trocar para a dimensão `empresa` seria uma segunda mudança semântica, não pedida, que passaria a penalizar o estrategista pela percepção do cliente sobre o analista.
  **Consequência aceita:** a mesma empresa tem `nota_empresa` **diferente** para o estrategista e para o analista — o bloco financeiro é idêntico, o NPS difere. `empresas_score` é, portanto, por `(user_id, company_id)`, nunca só por `company_id`.

### Colapso quando há mais de uma nota para a mesma empresa

- **D-02 · Profissional que acumula papéis na mesma empresa entra com a MÉDIA das dimensões que ele exerce.** Se João é estrategista **e** analista da empresa X (4,8 e 3,2), o NPS dele para X é 4,0.
  **Razão:** hoje as duas notas entram separadas na média do profissional, o que faz a empresa X **pesar dobrado** na carteira dele. A fórmula da milestone é média por EMPRESA — uma empresa, um peso.

- **D-03 · Empresa com mais de um survey na competência: usa o survey do SERVIÇO DO VÍNCULO.** Quem responde pela Performance entra com a nota do survey de Performance; quem responde pela Shopee, com a de Shopee.
  **Fallback obrigatório para o vínculo consolidado:** quando `company_users.servico_id` é `NULL` (responsável consolidado), usa a **média de todos os surveys da empresa** na competência.
  ⚠️ **Este fallback não é opcional — sem ele a decisão recria um bug conhecido de produção.** Ver `<code_context>` e a memória `project_nps_assignment_consolidado_gap`: responsável consolidado com `servico_id NULL` já sumiu do escopo e ficou fora do bônus exatamente por não casar com resolução por serviço. O planner deve tratar `servico_id NULL` como caminho de primeira classe, com teste dedicado, não como exceção.

### Empresa sem NPS na competência

- **D-04 · Empresa da carteira sem NENHUM NPS na competência entra com nota 1.** Isso vale inclusive quando **nunca houve disparo** para aquela empresa.

  🔁 **ISTO REVERTE A D3 DA FASE 116, que era decisão travada.** Texto original (2026-07-27):
  > *"Só vira nota 1 o NPS efetivamente disparado (existe survey/envio para aquela empresa+responsável+competência). Empresa sem disparo **nunca** entra como 1 — senão pune quem não tinha o que enviar. Este é o invariante mais importante da fase."*

  A reversão foi apresentada ao usuário com o texto acima e o impacto explicitado, e **confirmada por ele em 2026-07-28**. O incentivo muda de "envie o NPS" para "tenha NPS configurado e disparando em toda empresa da carteira".

  **O que NÃO é afetado:** as 245 imputações aplicadas em produção em 2026-07-28 tratam *disparado e não respondido* — regra distinta, inalterada. `nps_imputed_assignments` não cobre o caso "nunca disparado", então nenhuma linha existente fica inválida.

  **Onde a regra vive:** o "1" de empresa sem disparo **não** é materializado em `nps_imputed_assignments`. É um fallback de leitura, calculado neste serviço novo quando a empresa está na carteira e não tem nota por nenhum dos três ramos. O planner deve resistir à tentação de materializar isso na tabela — materializar contradiria a D3 da Fase 116 no nível do dado, e a reversão é de leitura para o bônus, não de semântica de imputação.

### Claude's Discretion

- **D-05 · Assinatura e local do serviço.** `NpsPorEmpresaService::notasNpsPorEmpresa()` conforme NPSE-01, em `app/Services/Desempenho/` ou `app/Services/Nps/` — o planner escolhe pelo que for coerente com os vizinhos. O retorno deve permitir auditar a origem (quantas notas, de qual ramo, qual dimensão, qual survey) — sem isso a Fase 121 não consegue explicar deltas.
- **D-06 · Esta fase não muda nenhum consumidor.** É aditiva, como a 117. `DesempenhoScoreService` continua calculando a média agregada como hoje; quem passa a ler por empresa é a Fase 119.

</decisions>

<risks>
## Risco registrado — divergência de coerência com a área de NPS

A D-04 cria uma divergência deliberada entre dois call-sites:

- **Bônus (a partir da Fase 119):** empresa da carteira sem disparo vale **1**.
- **Área de NPS (Fase 116, já em produção):** essa mesma empresa não tem nota — ela não aparece, porque não há survey.

A Fase 116 entregou em `116-08` um **teste de coerência entre call-sites** justamente para impedir que a regra do piso divergisse entre telas. NPSE-06 exige que esse teste conheça o call-site novo e continue verde.

**O planner precisa decidir e registrar** qual das duas leituras é a correta para o teste de coerência:
1. O teste passa a tolerar a divergência, documentando que bônus e área de NPS respondem perguntas diferentes ("quanto vale a empresa para o bônus" × "o que o cliente respondeu"); ou
2. A área de NPS também adota o fallback, e aí a mudança vaza para fora do escopo desta fase e precisa de fase própria.

**Recomendação:** opção 1 nesta fase — a área de NPS mostra o que o cliente **respondeu**, e inventar notas 1 para empresas sem questionário ali seria mentira de tela. Mas a decisão precisa ser explícita no plano, não emergente.

</risks>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plano canônico e requirements
- `plano-implementacao-desempenho-por-empresa.md` §2.6 — descrição dos três ramos de NPS e das tabelas com `company_id`
- `plano-implementacao-desempenho-por-empresa.md` §4 "Fase 2" — contrato sugerido de `notasNpsPorEmpresa()`
- `.planning/REQUIREMENTS-v21.md` — NPSE-01..NPSE-06

### Decisões da Fase 116 que esta fase toca (LER ANTES DE MEXER)
- `.planning/phases/116-nps-n-o-respondido-conta-como-nota-m-nima-1/116-CONTEXT.md` `<decisions>` — **D3 (revertida pela D-04 desta fase)**, D2 (provisório/definitivo), D4 (escala 1-5), D5 (empresa invalidada fora), D6 (disparo manual), D7 (dimensão empresa)
- `.planning/phases/116-nps-n-o-respondido-conta-como-nota-m-nima-1/116-08-SUMMARY.md` — o teste de coerência entre call-sites que NPSE-06 exige manter verde

### Código que define o comportamento atual
- `app/Services/DesempenhoScoreService.php:748` `computeNpsWindow()` — resolução da janela M+1 e dos pisos
- `app/Services/DesempenhoScoreService.php:883` `notasPorAtribuicao()` — ramo 1
- `app/Services/DesempenhoScoreService.php:953` `notasLegado()` — ramo 2
- `app/Services/DesempenhoScoreService.php:1057` `notasImputadas()` — ramo 3
- `app/Services/Nps/NpsImputationService.php:295` `notasDaEmpresa()` — **leitura por empresa que JÁ EXISTE**

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`NpsImputationService::notasDaEmpresa()` (linha 295)** — já recebe `Collection $companyIds`, `string $dimensao`, janela, `$invalidadas` e `$templateIds`, e filtra por `vigentes()`. O ramo 3 já está pronto por empresa; a fase praticamente só o consome.
- **`nps_imputed_assignments.dimensao`** — já é `estrategista|analista|empresa` (`app/Models/NpsImputedAssignment.php:31`). A dimensão de papel exigida pela D-01 já existe no dado.
- **`notasPorAtribuicao()` (883)** — já faz `join('nps_surveys as s')` e já usa `s.company_id` no filtro de invalidadas. **O `company_id` está na query, só não é selecionado** — o `groupBy` é `(nps_response_id, role)` e o retorno é `Collection<int, float>`. Expor company_id é acrescentar ao select/groupBy, não reescrever.
- **`notasLegado()` (953)** — já faz `whereIn('company_id', $companyIds)` e já aplica `->principal()`. Mesma situação: `company_id` disponível, não retornado.

### Established Patterns
- **Dedupe por `(response_id, role)`** no ramo 1 e por `(survey_id, role)` no ramo 3 — NPSE-02 exige preservar ambas. A D-02 (média dos papéis) opera **depois** da dedupe, não no lugar dela.
- **`->principal()`** só no ramo legado — ver memória `project_nps_modelo_principal`: "só o principal conta" foi superada pela v16.0 e sobrevive apenas nesse ramo.
- **Invalidação por competência** via `$invalidadas` já é parâmetro dos três ramos.

### Integration Points
- **`DesempenhoScoreService::computeNpsMedio()` (818)** é quem hoje soma os três ramos. A fase adiciona um caminho paralelo por empresa **sem** alterar esse método (D-06).
- **`CarteiraContextService::forUser()`** é a fonte do universo de empresas e de `servico_id` — é ali que a D-03 resolve serviço→survey e detecta o vínculo consolidado.

</code_context>

<specifics>
## Specific Ideas

- Exemplo numérico da D-01 e D-02, para virar teste:
  - Empresa X, junho: NPS estrategista 4,8 · analista 3,2 · empresa 4,0
  - Luiz (só estrategista de X) → NPS de X = **4,8**
  - Ana (só analista de X) → NPS de X = **3,2**
  - João (estrategista **e** analista de X) → NPS de X = **4,0** (média dos dois papéis), e X pesa **1×** na carteira dele, não 2×
- Exemplo da D-03: empresa Y com survey Performance 4,6 e Shopee 3,0 — responsável com `servico_id=6` usa 4,6; com `servico_id=9` usa 3,0; **consolidado com `servico_id NULL` usa 3,8**.

</specifics>

<deferred>
## Deferred Ideas

- **Alinhar a área de NPS ao fallback da D-04** — se a decisão for que a tela também deve mostrar 1 para empresa sem disparo, é fase própria (ver `<risks>`).
- **Materializar "sem disparo" em `nps_imputed_assignments`** — deliberadamente fora: a D-04 é regra de leitura para o bônus, não mudança de semântica da imputação.
- **Usar a dimensão `empresa` em algum lugar do bônus** — descartado pela D-01; se a diretoria quiser avaliar a empresa como um todo, é decisão de produto, não desta fase.
- **Corrigir o gap do responsável consolidado na origem** (fazer o disparo gerar assignment para `servico_id NULL`) — a D-03 contorna na leitura; a correção na origem é outro escopo, registrada em `project_nps_assignment_consolidado_gap`.

</deferred>

---

*Phase: 118-nps-por-empresa-v21-0*
*Context gathered: 2026-07-28*
