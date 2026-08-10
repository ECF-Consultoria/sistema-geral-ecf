# Phase 134: "Meus Anúncios" — saúde analítica do anúncio publicado - Context

**Gathered:** 2026-08-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Uma tela nova no módulo `mlb/anuncios`, por empresa, que mostra o estado de saúde dos anúncios **já publicados** na conta do Mercado Livre do cliente, com dado real vindo da API do ML, mais uma leitura agregada e acionável do acervo. A tela passa a hospedar o bloco "Rascunhos recentes", hoje enterrado no aside do wizard.

Hoje o módulo **não lê nada do ML depois de publicar** — os únicos endpoints consumidos são `/items/validate` e `POST /items` (`MlPublicacaoService.php:80` e `:112`). A "Saúde do anúncio" existente é 100% client-side e derivada do formulário do wizard (`analisarAnuncio()` em `resources/js/lib/mlAnuncioRegras.js:174`); ela desaparece no instante em que o anúncio é publicado. **Toda a leitura pós-publicação desta fase é backend novo.**

**Não é escopo:** nenhum write na API do ML (pausar, editar, mover anúncio) e nenhuma mudança de gate de acesso.

</domain>

<decisions>
## Implementation Decisions

### Acervo — o que a tela lista

- **D-01:** A tela lista **todos os itens da conta ML do cliente** (varredura via endpoint de busca de itens do vendedor), não apenas o que este módulo publicou. Justificativa do usuário: é a única leitura que responde de verdade "meus anúncios estão saudáveis?" — o módulo é admin-only e publicou pouco, então limitar ao acervo local faria a tela nascer quase vazia e mentir por omissão.
- **D-02:** Escopo **por empresa**, com a mesma âncora `{company}` das rotas existentes (`wizard`, `massa`, `historico`), entrando pelo painel de cards de `/mlb/anuncios`. Consistente com as 3 abas atuais e com o token ML, que é por empresa. **Descartado explicitamente:** indicador de saúde agregado por empresa no painel de cards (exigiria coleta de todas as empresas para alimentar a home) — ver Deferred.
- **D-03:** A tela carrega **só anúncios ativos por padrão**; pausados e encerrados ficam atrás de filtro. A coleta pode varrer a conta inteira, mas a primeira tela não paga por isso.
- **D-04:** Cada anúncio traz **selo de origem com 3 valores**: (a) nasceu neste módulo — casar por `MlAnuncioRascunho.ml_item_id` / `ml_item_id_classico` / `ml_item_id_premium`; (b) publicado pelo time e registrado em `Publicacao.mlb_code`; (c) nenhum dos dois = legado do cliente. O join com `Publicacao` é **decisão travada**, não opcional: além da origem, ele traz `vendas_qty` e o flag `desconsiderado`.

### Frescor — como a métrica do ML chega

- **D-05:** **Snapshot em tabela + comando artisan agendado.** A tela lê **exclusivamente do banco** — nenhuma chamada síncrona ao ML no caminho do request. Um botão "Atualizar agora" enfileira job por empresa. Motivo travado: o padrão de HTTP síncrono por empresa já levou `/dashboard` a 124s neste projeto (ver `.planning/learnings/` e a memória do gate quente/frio, 2026-08-07).
- **D-06:** Frequência **diária**, agendada logo após o `ml:sync` das 11:05 em `routes/console.php` — aproveita o token já renovado pelo `ml:refresh-tokens` das 08:00 e segue o D-1 que já é o padrão de vendas ML, Adman e Shopee.
- **D-07:** Persistência em **dois níveis**: (a) uma linha corrente por anúncio, sobrescrita por upsert — é o que a tela lê; (b) uma **série diária enxuta** só dos campos que valem tendência (visitas, vendas, saúde), com retenção da ordem de 90 dias. O usuário pediu "evolução" explicitamente; sem (b) ela não existe. Retenção e índices são requisito, não polimento — a tabela cruza milhares de itens × empresas × dias.
- **D-08:** Degradação graciosa: coleta falha ou está velha → a tela mostra **o último snapshot com selo de defasagem** ("coletado há N dias") e o motivo. Nunca tela em branco. Mesmo espírito do `cotarFrete()`, que devolve `null` em vez de quebrar.

### Leitura — o que a tela responde primeiro

- **D-09:** O topo da tela é **triagem acionável**: "N anúncios precisam de você", agrupados por motivo (pausado, sem estoque, ficha incompleta, perdendo catálogo, foto insuficiente…). Cada grupo é um clique que filtra a lista. Isso vem antes de qualquer placar ou gráfico.
- **D-10:** "Saúde" na tela são **duas medidas lado a lado**: (a) o health/quality atribuído pelo próprio ML — autoridade externa, é ele que decide exposição; (b) uma nota ECF derivada das regras que já existem em `mlAnuncioRegras.js`, aplicadas ao item publicado. A divergência entre as duas é diagnóstico.
  > **Pegadinha travada:** se a nota ECF for exibida, ela **tem que fechar com a conta mostrada na própria tela**. Este projeto já teve o caso `nps_medio` ≠ `pontos_componentes.nps`, em que o card não batia com a própria conta. Ver `.planning/learnings/desempenho-bonificacao.md`.
  > **Não recalibrar régua por conta própria** — reusar os pesos de `calcularScore()` (`mlAnuncioRegras.js:263`) e, se precisar de peso novo para o contexto pós-publicação, tratar como decisão explícita, não como ajuste silencioso.
- **D-11:** A tela é **só leitura**. Cada anúncio leva ao permalink no ML e, quando nasceu no ECF, ao rascunho correspondente no wizard. **Zero write na API do ML.**
- **D-12:** Ordenação padrão **por gravidade do problema** — pausado/sem estoque no topo, ficha incompleta depois, avisos cosméticos no fim. Determinística: funciona no dia 1 mesmo que a API não entregue visitas.

### Navegação — abas e Rascunhos

- **D-13:** **4 abas**, com "Meus Anúncios" como **a inicial**: Meus Anúncios | Individual | Em massa | Histórico. O Histórico **sobrevive** — ele é o registro do que este módulo publicou, agrupado por lote, e é a base do "Anunciar semelhante em massa" (`duplicarLoteComoTemplate`). Propósitos distintos: Histórico é registro do módulo, Meus Anúncios é o acervo vivo da conta.
- **D-14:** Dentro de "Meus Anúncios", **sub-abas Publicados | Rascunhos**. São estados com colunas diferentes — rascunho não tem visita, venda nem health, e forçá-los na mesma tabela deixaria metade das colunas vazia. O botão **"Publicar lote" (BULK-01) migra junto** para a sub-aba Rascunhos e sai do aside do wizard.
- **D-15:** O módulo **continua `role:admin`, sem item de menu**. A fase não mexe no gate. O filtro por `responsavel_id` segue dormente como está hoje.
- **D-16:** A **"Saúde do anúncio" do wizard fica intacta** no aside (`AnunciarML.jsx:2761`) — ali ela guia a criação e alimenta o guard-rail de `publicar()`. A fase só **remove o bloco de Rascunhos recentes** do aside (`AnunciarML.jsx:2831`). Nenhuma alteração no caminho de publicação.

### Claude's Discretion

- Nome exato da tabela/migration, do comando artisan e do job.
- Throttle e paginação da coleta (rate limit / 429): o módulo já usa delay escalonado em `publicarLote` — replicar o padrão, parâmetros a cargo do planner.
- Layout fino e escolha de gráfico para a série temporal (Recharts já está no projeto) — cabe ao `/gsd:ui-phase`.

### Perguntas em aberto — decidir na pesquisa/planejamento, NÃO assumir

- **A-01 — Variações.** Anúncio com variações é **uma linha ou N**? No ML, estoque e venda são por variação. Muda o modelo de dados do snapshot. O usuário optou por fechar o discuss antes de decidir isso; trazer opção fundamentada no `134-RESEARCH.md`.
- **A-02 — Catálogo / buy box.** Anúncio de catálogo merece tratamento próprio? "Ganhando ou perdendo a buy box" é das métricas mais acionáveis que existem para quem anuncia. Confirmar se a API expõe isso de forma estável para item próprio e, se sim, propor onde entra na triagem (D-09).
- **A-03 — Volume real.** Quantos itens ativos as contas conectadas de fato têm? O desenho de coleta (D-05/D-06) e a retenção (D-07) dependem da ordem de grandeza. Medir contra conta real antes de dimensionar.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Módulo Anunciar Mercado Livre (o que já existe)

- `routes/mlb_anuncios.php` — todas as rotas do módulo, o gate `role:admin` e os comentários que documentam a troca futura para `permission:mlb.anunciar`. A rota nova entra aqui.
- `app/Http/Controllers/MlbAnuncioController.php` — 30+ actions; `historico()` (linha 203) é o molde mais próximo da tela nova (Inertia + paginação + busca + escopo por empresa com `abort_unless($company->mlToken !== null)`).
- `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` — segmented control das abas; **troca de ROTA, não de estado**. A 4ª aba e a reordenação entram aqui.
- `resources/js/Pages/Mlb/AnunciosHistorico.jsx` — página da aba Histórico; agrupamento por lote que **não pode regredir**.
- `resources/js/Pages/Mlb/AnunciarML.jsx` — wizard. Linha 2761 = painel "Saúde do anúncio" (**fica**); linha 2831 = bloco "Rascunhos recentes" (**sai**, junto com `publicarLote` e a seleção múltipla).
- `resources/js/lib/mlAnuncioRegras.js` — regras puras de saúde: `analisarAnuncio()` (linha 174) e `calcularScore()` (linha 263, 8 pesos fixos). Fonte da nota ECF do D-10.
- `app/Models/MlAnuncioRascunho.php` — `STATUS_PUBLICADO`, `ml_item_id`, `ml_item_id_classico`, `ml_item_id_premium`, `published_at`. Lado ECF do join do D-04.

### Integração Mercado Livre

- `app/Services/Mlb/Publicacao/MlPublicacaoService.php` — cliente HTTP do ML no módulo (`/items/validate`, `POST /items`). Molde de autenticação/base URL para o service de leitura novo.
- `app/Services/MercadoLivreService.php` — OAuth, refresh de token e `/orders/search`. Onde vive o token por empresa.
- `routes/console.php` — `ml:refresh-tokens` 08:00, `ml:sync` 11:05, `mlb:sync-vendas-logs-cleanup` 03:20. O agendamento do D-06 entra aqui; o cleanup é o molde da retenção do D-07.
- `.planning/codebase/INTEGRATIONS.md` — mapa de integrações externas, throttling e persistência de sync (nota: auditado em 2026-05-27, anterior ao OAuth ML atual — confirmar contra o código).

### Módulo de Publicações (o join do D-04)

- `app/Models/Publicacao.php` — `mlb_code`, `vendas_qty`, `cust_id`, e o scope `considerado()` (linha 171). **Regra dura já estabelecida:** toda query que CONTA precisa do scope; query que LISTA, não. A tela do D-01 LISTA — não aplicar `considerado()` na listagem, aplicar só se algum número agregado for exibido.

### Aprendizado obrigatório antes de codar

- `.planning/learnings/desempenho-bonificacao.md` — por que régua não se recalibra sozinha, e o caso da nota que não fechava com a própria conta (vale para D-10).
- `.planning/todos/pending/260626-acoes-ml-mover-sgi-pausar-via-api.md` — conclusão já tomada de que write na API do ML é fase separada com salvaguardas próprias. Sustenta D-11.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`historico()` (`MlbAnuncioController.php:203`)** — topo idêntico ao que a tela nova precisa: `loadMissing('mlToken')` + `abort_unless(... 404)` + busca + `LengthAwarePaginator` + `Inertia::render`. Copiar a forma, não reimplementar.
- **`ModoAnuncioTabs.jsx`** — adicionar item ao array `MODOS` e reordenar; a mecânica de troca de rota já está pronta e é compartilhada por wizard e grade.
- **`analisarAnuncio()` / `calcularScore()` (`mlAnuncioRegras.js`)** — regras puras, sem dependência de React. Reaproveitáveis para a nota ECF sobre item publicado; podem precisar de um adaptador do payload do ML para a forma que a função espera.
- **Bloco de Rascunhos recentes (`AnunciarML.jsx:2831-...`)** — seleção múltipla, `toggleTodos`, `publicarLote`, tratamento de `errosLote`. **Mover, não reescrever.**
- **`mlb:sync-vendas-logs-cleanup`** — precedente de comando de retenção agendado; molde para a limpeza da série do D-07.

### Established Patterns

- Abas do módulo são **páginas Inertia separadas** que trocam de rota, cada uma com sua action no controller. A tela nova segue isso — não é estado dentro do wizard.
- Toda action do módulo faz **double-check de empresa** antes de qualquer chamada ao ML (padrão dos comentários T-77-04, T-78-01).
- Design: Tailwind com tokens `ecf-*`, dark, `cn()` de `@/lib/utils`, primitivas em `@/Components/ui/`. Sem lib de UI nova. Recharts já disponível.
- Comentários em pt-BR; log prefixado com tag de módulo (`Log::error("[MLB Anuncios] ...")`).

### Integration Points

- **Rota nova** em `routes/mlb_anuncios.php` dentro do grupo `role:admin` existente (nome sugerido: `mlb.anuncios.meus`).
- **Scheduler** em `routes/console.php`, após o `ml:sync` das 11:05.
- **Token ML por empresa** via `Company->mlToken` — mesma trava de `abort_unless` das outras actions.
- **Join com `Publicacao`** por `mlb_code` ↔ id do item no ML.

</code_context>

<specifics>
## Specific Ideas

- Frase do usuário que define o critério de aceite: *"Abro, escolho a empresa, e em 5 segundos sei quais anúncios estão saudáveis, quais estão perdendo venda e por quê, e o que fazer em seguida — sem clicar em nada escondido."*
- Queixa de origem, literal: o único caminho para retomar um rascunho hoje é um botão "Abrir" de 10px dentro do aside do wizard. **"Do jeito que está não gostei."** A sub-aba Rascunhos (D-14) precisa ter alvo de clique de verdade — card com foto, título, categoria, tier e status.
- Pedido explícito: *"o máximo de métricas que o Mercado Livre fornecer que sirva para quem faz anúncios"*. Isso é **diretriz de pesquisa**, não decisão fechada — o `134-RESEARCH.md` deve **levantar o que a API de fato expõe hoje para item próprio com token do vendedor e listar o que é viável. Não inventar campo.** Candidatos a confirmar: status/sub_status, health/quality, ações sugeridas de qualidade, visitas por item, vendas, estoque, catálogo/buy box, tier (clássico × premium), frete grátis/logística, tags.
- **Autorização do usuário (2026-08-10):** está liberado **gastar chamada de API do ML em produção durante o desenvolvimento** para confirmar endpoints contra token real, em vez de supor a partir de documentação. Ler é permitido; escrever não (D-11).
- **Não deployar** — autorização de deploy é sempre explícita e separada.

</specifics>

<deferred>
## Deferred Ideas

- **Write na API do ML (pausar, editar, mover anúncio) a partir da tela.** Ação destrutiva na conta do cliente em produção; exige confirmação dupla, `activity_log` antes e depois, rollback e janela de undo. Fase própria — mesma conclusão já registrada em `.planning/todos/pending/260626-acoes-ml-mover-sgi-pausar-via-api.md` para os adgroups de Ads.
- **Abrir o módulo ao time de publicação** (`role:admin` → `permission:mlb.anunciar`), ativando o filtro por `responsavel_id` hoje dormente. Traz RBAC e teste de vazamento entre publicadores para dentro do escopo — fase própria.
- **Indicador de saúde por empresa no painel de cards de `/mlb/anuncios`.** Considerado e descartado em D-02: exigiria coleta de todas as empresas para alimentar a home, não só da empresa aberta.
- **Marcação local de "já tratado" / "ignorar"** para o anúncio sair da triagem. Considerado e descartado em D-11: introduz ciclo de vida e tabela própria. Reabrir se a lista de problemas virar ruído recorrente.
- **Meus Anúncios absorver o Histórico.** Considerado e descartado em D-13 — propósitos distintos e risco de regredir o "Anunciar semelhante em massa" da Phase 86.

</deferred>

---

*Phase: 134-"Meus Anúncios" — saúde analítica do anúncio publicado*
*Context gathered: 2026-08-10*
