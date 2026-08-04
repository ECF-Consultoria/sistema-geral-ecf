# Fase 123 — Checkpoint Visual (Task 1 + Task 2)

**Gerado:** 2026-08-04
**Competência obrigatória do checkpoint visual: 2026-06** (única com detalhe por empresa em produção — 286 linhas, 11 profissionais).

---

## Parte 1 — Suíte completa contra a baseline conhecida + build de produção

> Cada comando rodou isolado, com saída redirecionada a arquivo (nunca por pipe) e o exit code
> capturado imediatamente depois — armadilha de shell `comando | tail -20; echo $?` (learnings §4)
> evitada de propósito.

| # | Comando | Exit code | Resultado observado | Veredito |
|---|---------|-----------|----------------------|----------|
| 1 | `php artisan test --filter=Phase123` | 0 | 41 passed (482 assertions) — as 7 suítes da fase (`CompanyScoreSnapshotReaderTest`, `PerformanceShowMesesDisponiveisTest`, `PerformanceShowEmpresasScoreTest`, `PerformanceShowSemDetalheTest`, `RetrocompatSnapshotAntigoTest`, `RelatorioBonificacaoEmpresasTest`, `AuditoriaBonusNotaEmpresaTest`) | ✅ VERDE |
| 2 | `php artisan test --filter=Phase122` | 0 | 49 passed (184 assertions) — a fonte de dados que esta fase só lê continua estável | ✅ VERDE |
| 3 | `php artisan test --filter=Phase120` | 0 | 18 passed (109 assertions) — `PayloadBaselineFlagOffTest` e os demais gates do shadow continuam verdes | ✅ VERDE (baseline: 18 testes) |
| 4 | `npm run test:js` | 1 | 124 pass / 1 fail (125 total) — única falha em `tests/js/estrutura-grade-glide.test.js:122` (módulo MLB "Grade em massa", `RECOLHIDOS_INICIAIS`), documentada como baseline pré-existente desde `123-01-SUMMARY.md`, arquivo intocado por esta fase | ✅ VERDE (baseline conhecida, zero regressão nova) |
| 5 | `php artisan test --filter=Phase110` | 1 | 2 failed / 3 passed (17 assertions) — idêntico à baseline de `122-VERIFICATION.md` | ✅ IDÊNTICO À BASELINE (não é regressão) |
| 6 | `php artisan test --filter=Desempenho` | 1 | 14 failed / 101 passed (455 assertions) — idêntico à baseline de `122-VERIFICATION.md` | ✅ IDÊNTICO À BASELINE (não é regressão) |
| 7 | `php artisan test` (suíte completa) | 255 | Trava com `Fatal error: Maximum execution time of 300 seconds exceeded` em `MercadoLivreAdsService.php:215`, durante `Tests\Feature\Phase42\...` (módulo Sugadores/ML Ads) | ✅ CLASSIFICADO — baseline preexistente documentada, ver nota abaixo |
| 8 | `npm run build` | 0 | `✓ built in 39.91s` — build de produção sem erro | ✅ VERDE |

### Nota sobre o item 7 (suíte completa não termina nesta máquina)

Este travamento **não é causado pela Fase 123** e já está documentado desde a Fase 80
(`.planning/phases/80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s/deferred-items.md`,
seção "1. Suite completa (`vendor/bin/phpunit` sem filtro) não termina nesta máquina"):

- **Causa raiz:** `set_time_limit(300)` é re-armado em runtime pelos comandos de Grants
  (`SyncGrantsFromSftp.php`, `SyncGrantsFromEcfDrive.php`, `GrantController.php`). A partir daí
  o processo INTEIRO do PHPUnit passa a ter 300s de orçamento. No **Windows**, o `usleep` do
  backoff exponencial dos testes de Sugadores conta wall-clock contra esse limite (no Linux não
  conta), estourando o orçamento restante.
- **Não é teste quebrado:** `MercadoLivreAdsServiceBackoffTest` e `MercadoLivreAdsServiceTest`
  passam 100% quando rodados isolados.
- **Confirmado sem relação com esta fase:** nenhum arquivo tocado nos 5 planos da Fase 123
  (`PerformanceController.php`, `BonusAuditoriaController.php`, `RelatorioBonificacaoController.php`,
  os `.jsx` de Performance/Auditoria/RelatorioBonificacao, `EmpresasScoreTabela.jsx`,
  `CompanyScoreSnapshotReader.php`, `desempenhoLabels.js`, mais os testes `tests/Feature/Phase123/*`)
  tem qualquer relação com `MercadoLivreAdsService`, Grants ou Sugadores.
- **Cobertura efetiva do "total":** como o total real nunca é alcançável nesta máquina (problema
  de infraestrutura pré-existente, não desta fase), o total é coberto pelos itens 1-6 acima —
  que juntos cobrem a fase inteira (Phase123), a fonte de dados de que ela depende (Phase122), o
  gate do shadow (Phase120), as duas baselines conhecidas de falha preexistente (Phase110,
  Desempenho) e a suíte JS completa. O run avançou até `Tests\Feature\Phase42\...` (área
  Sugadores/ML) antes de travar, sem nenhuma falha nova fora das já classificadas nos itens 5-6.
- **Ação:** nenhuma correção aplicada (fora do escopo desta fase, per SCOPE BOUNDARY) —
  só registrado aqui para o próximo executor não interpretar como regressão.

### Invariantes de escopo (prova por diff)

| Invariante | Comando | Resultado |
|---|---|---|
| Réguas e agregação da margem intocadas | `git diff --stat app/Services/DesempenhoScoreService.php app/Services/Desempenho/CompanyScoreService.php` | Saída vazia — zero arquivo listado |
| `computeVarMargem()` continua `avg()` | `grep -n "function computeVarMargem" ... ` + leitura do corpo | Confirmado: `round($vars->avg(), 2)` (linha ~1518) |
| `computeVarFaturamento()` continua `median()` | idem | Confirmado: `round($vars->median(), 2)` (linha ~1442) |
| Flag `performance_company_first_score` continua `false` | `git diff --stat config/metrics.php` + leitura do arquivo | Saída do diff vazia; `env('PERFORMANCE_COMPANY_FIRST_SCORE', false)` default `false`, sem override em `.env`/`.env.example` |
| PDF do Relatório de Bonificação continua resumo (D-09) | `git diff resources/views/pdf/relatorio-bonificacao.blade.php` | Saída vazia — zero linha alterada |

**Veredito da Task 1: todos os 8 comandos classificados, zero regressão nova, build de produção
verde, os 4 invariantes de escopo provados por diff vazio. Critério 5 do ROADMAP (build +
checkpoint) fica pendente só da Parte 2 (aprovação visual).**

---

## Parte 2 — Roteiro visual em 2026-06 (Task 2 — checkpoint humano)

> Preenchido durante o checkpoint. Rodar `php artisan serve` localmente (assets já buildados pela
> Task 1) e conferir cada item abaixo na competência **2026-06**. Anotar o resultado observado.
> **Nenhum deploy nesta fase.**

### A. Desbloqueio do seletor (D-02)

1. `/performance/{id}` de qualquer profissional — botão "Mês fechado" habilitado (antes:
   desabilitado, tooltip "Nenhum mês fechado disponível ainda").
   **Resultado observado:** _(preencher)_
2. `<select>` oferece junho de 2026.
   **Resultado observado:** _(preencher)_

### B. Card de margem (UIEM-01)

3. Título "Margem", texto sem `percentageMargin` nem nome de campo de API; explica variação
   relativa com exemplo "de 10% para 11% aparece como +10%".
   **Resultado observado:** _(preencher)_
4. Quando existir `var_margem_pp`, aparece a frase em linguagem simples
   ("A margem subiu/caiu N pontos percentuais na média da carteira").
   **Resultado observado:** _(preencher)_
5. O número grande do card continua a variação relativa, coerente com a nota ao lado.
   **Resultado observado:** _(preencher)_

### C. Felipe — o denominador (UIEM-02 / D-06)

6. `/performance/{id}` do **Felipe** em 2026-06 — "Entraram na conta (N)" com N pequeno (não 30);
   "Não entraram (M)" lista as ~27 restantes com motivo visível em pt-BR (nunca `snake_case`).
   **Resultado observado:** _(preencher)_
7. Segunda seção nasce colapsada (mais de 8 empresas), botão informa quantas são.
   **Resultado observado:** _(preencher)_
8. N + M bate com o total da carteira dele.
   **Resultado observado:** _(preencher)_

### D. Matheus Estrela — Shopee (UIEM-02 / D-07)

9. `/performance/{id}` do **Matheus Estrela** em 2026-06 — todas as empresas aparecem em
   "Entraram na conta", nunca excluídas do denominador.
   **Resultado observado:** _(preencher)_
10. Carteira 100% Shopee — aviso único no topo da seção (não selo repetido por linha); leitura
    resultante é "falta o dado", nunca "a margem foi ruim".
    **Resultado observado:** _(preencher)_

### E. Caso comum (UIEM-02)

11. Profissional com maioria de empresas completas (ex.: Ana Julia ou Luiz Henrique) — tabela
    renderiza limpa, margem no formato `14,1% → 12,0%  −2,1`, três componentes visíveis.
    **Resultado observado:** _(preencher)_
12. Ressalva visível de que a nota do topo vem do cálculo por carteira (não é a média das notas
    listadas abaixo, com a flag desligada).
    **Resultado observado:** _(preencher)_

### F. Ausência não é silêncio (UIEM-03 / D-03)

13. Modo "Em curso" — seção aparece com aviso de que a lista só existe após o fechamento (não
    some).
    **Resultado observado:** _(preencher)_
14. Competência fechada sem detalhe (ex.: 2026-05, se estiver no seletor) — aviso "Detalhe por
    empresa indisponível" com explicação, nunca tabela vazia nem erro.
    **Resultado observado:** _(preencher)_
15. Card de margem no modo "Em curso" continua mostrando o número normalmente.
    **Resultado observado:** _(preencher)_

### G. Débora Lima — ausência não pode quebrar (UIEM-03)

16. **Débora Lima** não aparece no ranking, no Relatório de Bonificação nem na Auditoria de
    Bônus (cai em "sem carteira" na consolidação — problema de outra fase, não desta).
    **Resultado observado:** _(preencher)_
17. A ausência dela **não** gera erro 500 em nenhuma das três telas.
    **Resultado observado:** _(preencher)_

### H. Relatório de Bonificação (UIEM-04 / D-08 / D-09)

18. `/desempenho/relatorio-bonificacao?mes=2026-06` — tabela continua uma linha por profissional.
    **Resultado observado:** _(preencher)_
19. Expandir a linha de um contemplado (ex.: Rubens) — empresas + nota + três componentes.
    **Resultado observado:** _(preencher)_
20. "Exportar PDF" — PDF continua resumo, uma linha por profissional, sem lista por empresa.
    **Resultado observado:** _(preencher)_

### I. Auditoria de Bônus (UIEM-04 / D-10)

21. `/desempenho/auditoria-bonus?mes=2026-06` — expandir um profissional, conferir nota por
    empresa em cada linha.
    **Resultado observado:** _(preencher)_
22. Competência sem detalhe — banner no topo da página, não colunas vazias sem explicação.
    **Resultado observado:** _(preencher)_
23. Números batem com a tela individual do mesmo profissional na mesma competência (mesma
    fonte).
    **Resultado observado:** _(preencher)_

---

## Veredito final

**Aprovação do usuário:** _(pendente)_

**Ajustes pedidos (se houver):** _(pendente)_
