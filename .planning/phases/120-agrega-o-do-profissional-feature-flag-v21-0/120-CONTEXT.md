# Phase 120: Agregação do profissional + feature flag - Context

**Gathered:** 2026-07-29
**Status:** Ready for planning

<domain>
## Phase Boundary

A `nota_final` do profissional passa a ser a **média das `nota_empresa`**, atrás da feature flag `metrics.performance_company_first_score`. É aqui que a milestone efetivamente **muda o número que paga bônus** — as Fases 117, 118 e 119 foram todas aditivas.

**NÃO está nesta fase:** comparar antigo × novo (Fase 121), persistir por empresa (122), telas (123), nem **ligar a flag em produção** — isso depende do gate e da aprovação do delta na 121.

</domain>

<blocking_dependency>
## ⚠️ GATE MPP-04 — bloqueia LIGAR A FLAG, não escrever o código

Reposicionado da Fase 119 para cá em 2026-07-29 (decisão do usuário).

**Estado em 2026-07-29 12:30 BRT: `reprovado`**, conferido por reconsulta a `adman_probe_margem_prev_vereditos` (`cobertura_prev = 0.6415`, `total_rodadas = 5`).

Sob contenção real, a cobertura de `percentageMargin.prev` caía para 64,2% com 15 de 53 empresas falhando em HTTP 429.

**O fix já foi entregue e medido** (quick `20260729-adman-retry-resiliente`): retry de 6 tentativas, ~60s de janela, jitter de ±40% e respeito ao `Retry-After`. Medição pós-fix sob contenção real, com três jobs de produção em paralelo: **0 falhas, cobertura 92,5%**.

**Falta o veredito formal.** A leitura pós-fix está etiquetada `manual`, não `contencao_11h`. Para fechar, entre 11:00 e 12:00 BRT:

```
php artisan adman:probe-margem-prev --mes=2026-06 --janela=contencao_11h
php artisan adman:probe-margem-prev --relatorio --mes=2026-06 --desde="2026-07-29 12:00"
```

O `--desde` é necessário porque o gate mede a **pior rodada**, e a rodada reprovada de 11:02 permaneceria eternamente como a pior. O recorte fica auditável em `janela_desde`.

⚠️ **Atenção ao contar rodadas com o recorte:** com corte em 12:00 de 29/07 existe apenas **uma** rodada posterior. O mínimo é 5 — serão necessárias mais quatro, ou um corte mais permissivo (que traria de volta a rodada ruim). **Decidir junto com a leitura.**

</blocking_dependency>

<decisions>
## Implementation Decisions

### Denominador da média

- **D-01 · Só empresas `complete` entram na média, e a cobertura vira guarda de status.** Empresa `partial`, `sem_fonte` ou `sem_dados` fica **fora do denominador** — não entra com `nota_empresa_parcial`.
  **Razão:** entrar com a parcial misturaria empresa medida por 3 dimensões com empresa medida por 1, e a incompleta poderia até tirar nota **maior** (`4,80` contra `4,53` do caso âncora). Já a leitura literal do plano §3.4 — qualquer incompleta puxa o profissional para `partial` — foi descartada porque a memória do projeto mostra que empresa sem baseline é caso **comum** (`adman_metrics` começa ~21/05; Shopee sem baseline antes de 01/06): quase toda carteira cairia em `partial` e o status pararia de distinguir qualquer coisa.
  **A guarda é o que impede o abuso:** sem ela, um profissional com 10 empresas e 2 completas seria julgado por 2, com aparência de nota oficial.

### Status

- **D-02 · `score_status` deriva da cobertura de empresas completas:**
  - `official` — cobertura ≥ patamar
  - `partial` — tem nota, mas cobertura abaixo do patamar
  - `blocked` — **nenhuma** empresa com nota alguma

  **A trava da Fase 109 é preservada sem exceção especial:** empresa Shopee é `complete` (o placeholder de margem 1.0 conta como componente presente, D-02 da Fase 119), então profissional só-Shopee tem cobertura 100% e continua `official`.

- **D-03 · Patamar de cobertura = 70%.** *(Decisão minha — o usuário não fixou o número; fácil de sobrepor.)*
  **Razão:** é exatamente o patamar de `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7`, que já governa a recusa de congelar snapshot degradado. Reusar o mesmo número evita que o sistema passe a ter **dois** conceitos concorrentes de "cobertura suficiente" — que foi exatamente o problema que a C-01 da Fase 119 apontou quando descobri a constante órfã de 0,8.

### Shadow

- **D-04 · Com a flag desligada, `empresas_score` é calculado apenas nos COMANDOS** — `desempenho:warm-cache`, `desempenho:consolidar-mes` e o comparador da Fase 121 — **nunca em leitura interativa**.
  **Razão:** a memória do projeto registra dashboard de 70 segundos por chamada HTTP síncrona à Adman (`project_desempenho_compute_cache`). Rodar o caminho novo inteiro — dispatcher por empresa + NPS por empresa — em toda requisição de tela dobraria esse custo com a flag ainda desligada. Nos comandos o custo é aceitável e a auditoria fica preservada.
  Leitura literal da AGRE-02 ("shadow nos dois modos") fica **atendida no espírito**: o shadow existe e é auditável, só não paga o preço na tela.

### ⚠️ Aferição pós-Fase 119.1 (2026-07-30) — LER ANTES DE EXECUTAR

A Fase 119.1 (sessão paralela) foi concluída — 9 planos, 9 summaries — e mexeu nos três serviços que esta fase consome: **+107 linhas** em `DesempenhoScoreService`, **+68** em `NpsPorEmpresaService`, **+12** em `CompanyScoreService`. Reaferi o impacto:

| Item | Situação |
|---|---|
| **Chaves do payload** | **inalteradas** — nenhuma chave nova. O teste dourado de byte-equivalência segue válido na estrutura. |
| **Valores congelados** | **sem risco** — o plano manda *capturar em execução* os números reais de hoje, não usa literais escritos antes. |
| **Versão de cache** | **já está em `v13`** (a 119.1 consumiu, como previsto). A regra corrente+1 leva a Fase 120 para **`v14`** automaticamente. |
| **Linhas citadas nos planos** | **DEFASADAS.** Localizar os métodos **pelo nome**, nunca pela linha. |

Referência atual (2026-07-30): `computeCached` **230** · `cacheKey` **304** · `computeScoreStatus` **706** · `computeNotaFinal` **1329**.

`computeNpsMedio` ganhou um 4º ramo na 119.1 — não afeta o ponto de agregação desta fase, mas pode mudar valores de NPS. Mais uma razão para os valores do teste dourado serem capturados na hora, e não copiados de qualquer registro anterior.

### Resoluções pós-pesquisa (verificadas no código)

- **C-01 · Dois sinais independentes, nunca um só.** A **feature flag** (`config('metrics.performance_company_first_score')`) decide **qual resultado vira `nota_final`**. O **shadow** é um parâmetro separado que decide **se `CompanyScoreService` sequer roda**. Confundir os dois é o caminho direto para o custo vazar para a tela (D-04). Só `desempenho:warm-cache` e `desempenho:consolidar-mes` passam o shadow como `true`.

- **C-02 · A armadilha do `Cache::remember` no warm — confirmada no código.** `computeCached()` envolve tudo em `Cache::remember` (`DesempenhoScoreService.php:57`) e `WarmDesempenhoCache` o chama na linha 122. **Se o cache já estiver quente, o closure não roda e o shadow é silenciosamente pulado naquele ciclo.** `desempenho:consolidar-mes` não sofre disso porque chama `compute()` direto (`ConsolidarMesDesempenho.php:139`).
  **Solução escolhida — nem forçar sempre, nem aceitar o gap:** o warm lê a entrada cacheada e **recomputa apenas quando o payload em cache não contém `empresas_score`**. Custa uma leitura de cache por user e garante que o shadow popula, sem pagar ~70s de recomputação a cada 8 minutos.
  Forçar `Cache::forget()` incondicionalmente foi **rejeitado**: recomputaria tudo em todo ciclo, exatamente o custo que o warm existe para evitar.

- **C-03 · `componentes.var_margem_pp` é `null` quando o shadow não rodou.** Em leitura interativa com a flag desligada, o caminho novo não executa — então o campo não tem valor calculado. Reportar `null` é honesto; inventar um número agregado seria pior, e reaproveitar `var_margem_pct` confundiria as duas unidades, que é justamente o que a milestone existe para separar.

### Testes

- **D-05 · `DesempenhoShopeeScoreTest` ganha cenários espelho para o modo flag-ligada, mantendo os 7 atuais intactos.**
  **A pesquisa precisou o alvo:** dos 7 testes, **4 dependem de `margemPontos()`** (asserem `pontos_componentes.margem` vindo do blend por contagem) e por isso não generalizam para o caminho novo. Os outros 3 — fonte financeira, dispatcher e cacheKey — valem nos dois modos. Os cenários espelho cobrem os 4.
  **Razão:** enquanto a flag estiver desligada — e ela fica desligada até o gate aprovar e o delta da 121 ser aceito —, o caminho antigo **é** o de produção. Reescrever os invariantes validaria um caminho que não roda. A suíte passa a documentar as duas semânticas e a diferença entre elas, que é justamente o que a Fase 121 vai auditar.

</decisions>

<risks>
## Risco herdado da Fase 119 — régua-da-média ≠ média-das-réguas

Registrado no `119-04-SUMMARY.md` e reproduzido numericamente em `CompanyScoreServiceReconciliacaoTest`.

`DesempenhoScoreService::margemPontos()` aplica a régua **uma vez** sobre a média agregada e depois pondera os placeholders Shopee por contagem. O modelo novo aplica a régua **por empresa** e promedia depois. O docblock daquele método declara como invariante testado:

> *"Só-performance (`$nShopeePlaceholder=0`) → devolve exatamente `reguaMargem($varMargemReal)` — IDÊNTICO ao comportamento pré-Fase 109 (regressão zero)."*

**Esse invariante não vale no caminho novo.** É esperado — é o ponto da milestone — mas significa que ligar a flag **muda notas**, e é isso que a Fase 121 tem de quantificar antes de qualquer ativação.

**Aposentadoria de `margemPontos()`:** é decisão desta fase. Enquanto a flag existir com dois caminhos, ele continua vivo como o caminho da flag desligada. Só sai quando a flag virar permanente.

</risks>

<canonical_refs>
## Canonical References

### Plano canônico e requirements
- `plano-implementacao-desempenho-por-empresa.md` §3.3, §3.4 e §4 "Fase 4" — agregação do profissional e status
- `plano-implementacao-desempenho-por-empresa.md` §6 — rollout com feature flag
- `.planning/REQUIREMENTS-v21.md` — AGRE-01..AGRE-06 e a decisão aberta que a D-01 desta fase resolve

### Fases anteriores
- `.planning/phases/119-.../119-CONTEXT.md` — D-01 (dois números por empresa), D-02 (Shopee `complete`), D-03 (`sem_fonte` listada), C-01..C-04
- `.planning/phases/119-.../119-04-SUMMARY.md` — o risco régua-da-média, registrado para esta fase
- `.planning/phases/109-.../109-CONTEXT.md` — trava de que só-Shopee não cai em `blocked`/`partial`
- `.planning/quick/20260729-adman-retry-resiliente/SUMMARY.md` — o fix de resiliência e o que falta para o gate

### Código
- `app/Services/DesempenhoScoreService.php:462` `computeNotaFinal()` · `:465` `computeScoreStatus()` · `~:1348` `margemPontos()`
- `app/Services/Desempenho/CompanyScoreService.php` — a linha por empresa que esta fase agrega
- `config/metrics.php` — já hospeda `unified_metrics_enabled`; a flag nova segue o mesmo padrão
- `app/Console/Commands/ConsolidarMesDesempenho.php:76` — `MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7`, origem do patamar da D-03

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`CompanyScoreService::computeEmpresasScore()`** (Fase 119) — entrega a linha por empresa com `status`, `nota_empresa`, `nota_empresa_parcial` e `componentes_presentes`. Esta fase só agrega.
- **`config/metrics.php`** — precedente de feature flag no mesmo arquivo (`unified_metrics_enabled`), com docblock explicando o rollout. Seguir o formato.
- **`computeScoreStatus()`** (linha 465) — recebe contadores; a versão nova recebe a contagem de empresas completas × total.

### Established Patterns
- **Flag com default `false` e caminho legado intocado** — é como `unified_metrics_enabled` foi feito.
- **Bump de cache versionado** — `cacheKey()` está em `v12`; sobe para `v13` (AGRE-03), com 4 suítes carregando a string hardcoded: `DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`.

### Integration Points
- **Esta é a primeira fase que MODIFICA `DesempenhoScoreService`.** As Fases 117-119 mantiveram o arquivo byte-a-byte intocado com gate de hash. Aqui o gate cai — e com ele some a rede de proteção que pegou vários erros. O plano precisa compensar com cobertura de teste equivalente.

</code_context>

<specifics>
## Specific Ideas

- Guarda de cobertura, caso concreto: profissional com 26 empresas, 20 `complete` ⇒ cobertura 77% ⇒ `official`. Com 15 completas ⇒ 58% ⇒ `partial`.
- Só-Shopee: todas as empresas `complete` (placeholder conta) ⇒ cobertura 100% ⇒ `official`, preservando a trava da Fase 109 sem código especial.
- `blocked` fica reservado ao caso extremo: nenhuma empresa com nota alguma.

</specifics>

<deferred>
## Deferred Ideas

- **Ligar a flag em produção** — depende do gate MPP-04 aprovado **e** do delta da Fase 121 aceito.
- **Aposentar `margemPontos()` e as réguas duplicadas** — só quando a flag virar permanente; a unificação de `reguaFaturamento`/`reguaMargem` (débito da C-03 da Fase 119) entra junto.
- **Persistir `empresas_score`** — Fase 122.
- **Exibir a lista de empresas com nota** — Fase 123.

</deferred>

---

*Phase: 120-agrega-o-do-profissional-feature-flag-v21-0*
*Context gathered: 2026-07-29*
