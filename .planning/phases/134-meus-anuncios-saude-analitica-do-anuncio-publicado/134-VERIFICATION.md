---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
verified: 2026-08-11T13:58:48Z
status: human_needed
score: 6/6 success criteria verificados (automatizado) + 23/23 decisões D-01–D-23 com evidência de código/teste
overrides_applied: 0
human_verification:
  - test: "Cronometrar o critério de aceite literal do usuário: abrir /mlb/anuncios, escolher uma empresa com acervo grande, e medir se em ~5 segundos dá para saber quais anúncios estão saudáveis, quais estão perdendo venda e por quê, sem clicar em nada escondido"
    expected: "Usuário identifica a ação a tomar em poucos segundos, sem navegação extra"
    why_human: "Julgamento de produto/UX — não é asserção programática (134-VALIDATION.md classifica este item como Manual-Only)"
  - test: "Abrir a tela e o Modal de Detalhe numa empresa real com dados de fato coletados e avaliar legibilidade da Triagem e do gráfico de evolução (cores, densidade, contraste)"
    expected: "Triagem e gráfico legíveis, hierarquia visual clara, buracos da série honestos e perceptíveis"
    why_human: "Qualidade visual — 134-VALIDATION.md classifica como Manual-Only (\"Legibilidade da triagem e do gráfico da série\")"
  - test: "Rodar `mlb:sync-acervo --company=<maior conta>` (66.747 itens, per 134-RESEARCH.md §A-03) contra produção e medir duração e ocorrência de 429"
    expected: "Coleta completa sem estourar rate limit, dentro de janela aceitável"
    why_human: "Só é provável contra volume real de produção — Http::fake() não mede tempo de rede nem rate limit real (134-VALIDATION.md, Manual-Only)"
---

# Phase 134: "Meus Anúncios" — saúde analítica do anúncio publicado — Verificação

**Phase Goal:** Quem cuida dos anúncios de uma empresa abre uma tela e, em segundos, sabe quais anúncios estão saudáveis, quais estão perdendo venda e por quê — com dado real vindo da API do Mercado Livre, não com a análise de formulário que hoje só existe durante a criação e some no instante em que o anúncio é publicado.

**Verified:** 2026-08-11
**Status:** human_needed
**Re-verification:** Não — verificação inicial

**Nota de leitura:** nenhuma alteração de código foi feita nesta verificação. Todos os comandos abaixo foram executados de fato contra o working tree atual (`git status` limpo, nenhum arquivo pendente de outra sessão tocado).

---

## Goal Achievement — Success Criteria do ROADMAP.md

| # | Success Criterion | Status | Evidência |
|---|---|---|---|
| 1 | Existe a rota `mlb.anuncios.meus` por empresa e é a **aba inicial** (Meus Anúncios \| Individual \| Em massa \| Histórico) | ✓ VERIFIED | `routes/mlb_anuncios.php:36` — `Route::get('/meus/{company}', ...)->name('meus')` dentro do grupo `role:admin`. `ModoAnuncioTabs.jsx` — array `MODOS` tem `meus` na **primeira** posição, testado por `abas: mlb.anuncios.meus vem ANTES de mlb.anuncios.wizard` (`estrutura-meus-anuncios.test.js`, verde) |
| 2 | A tela lista **todos os anúncios ativos da conta ML do cliente**, não só os publicados pelo módulo, e cada linha diz a origem (ECF · time · legado) | ✓ VERIFIED | `MlAcervoService::enumerarIds($company)` (sem filtro de `$status`) varre a conta INTEIRA por `scroll_id`, não só o que o módulo publicou — provado por `ColetaAcervoTest::conta_com_mais_de_mil_itens_e_varrida_por_completo`. Selo de origem (3 valores) em `MlAcervoItem::ORIGEM_*`, testado por `ColetaAcervoTest::selo_de_origem_cobre_os_tres_casos` e `MeusAnunciosTest::selo_de_origem_cobre_os_tres_casos` |
| 3 | A tela lê **exclusivamente do banco** — zero chamada síncrona ao ML no request; coleta é comando agendado + job por empresa | ✓ VERIFIED | `MlbAnuncioController::meus()`/`detalheAnuncio()` sem `Http::`/`MercadoLivreService`/`MlCatalogoMetaService` no corpo (grep confirma), provado por `MeusAnunciosTest::nenhuma_chamada_sincrona_ao_ml_no_request` (`Http::assertNotSent` escopado a `mercadolibre.com`) e `DetalheAnuncioTest::detalhe_nao_faz_chamada_ao_ml` (`Http::assertNothingSent()` bruto). `mlb:sync-acervo` agendado às 11:35 e `mlb:acervo-cleanup` às 03:40 confirmados via `artisan schedule:list` rodado nesta verificação |
| 4 | Topo é triagem acionável agrupada por motivo, clique filtra a lista | ✓ VERIFIED | `MlbAnuncioController::meus()` monta `triagem` em 1 query agregada (`selectRaw`); `MeusAnuncios.jsx` renderiza chips clicáveis que fazem `router.get(..., {motivo})`; provado por `MeusAnunciosTest::triagem_agrupa_por_motivo_e_o_clique_filtra` |
| 5 | Coleta falha/está velha → tela mostra último snapshot com selo de defasagem, nunca em branco | ✓ VERIFIED | Bloco `defasagem` sempre presente na resposta (`nunca_coletado`/`horas`/`motivo`); banner renderizado condicionalmente em `MeusAnuncios.jsx:318-330`; provado por `MeusAnunciosTest::degradacao_graciosa_mostra_ultimo_snapshot_com_selo` |
| 6 | "Rascunhos recentes" saiu do aside do wizard e vive na sub-aba Rascunhos junto com "Publicar lote"; "Saúde do anúncio" continua intacta no wizard | ✓ VERIFIED | `AnunciarML.jsx` não contém mais o bloco (grep `Rascunhos recentes` → 0 ocorrências fora de comentário reescrito); `Saúde do anúncio` presente em `AnunciarML.jsx:2700`; `RascunhosPainel.jsx` tem `publicarLote`/`toggleTodos`/cards clicáveis; provado por `tests/js/estrutura-anunciar-ml.test.js` (6/6) e `RascunhosMeusAnunciosTest::publicar_lote_continua_funcionando_a_partir_da_tela_nova` |

**Score:** 6/6 Success Criteria do ROADMAP verificados por código + teste executado nesta sessão.

---

## Cobertura das 23 decisões travadas (D-01 a D-23)

Todas verificadas por leitura de código e/ou execução de teste nesta sessão (não por leitura do SUMMARY):

| Decisão | Verificação | Status |
|---|---|---|
| D-01 (acervo inteiro, não só publicado) | `enumerarIds()` sem filtro de status + teste de conta >1000 itens | ✓ |
| D-02 (escopo por empresa) | `company_id` em `escopoAcervo()`; testes de vazamento entre empresas (lista, triagem, detalhe, rascunhos) | ✓ |
| D-03 (default acionáveis = ativos+pausados, emenda 2026-08-10) | `meus()` linha 345 default `'acionaveis'`; teste `default_da_tela_traz_pausados_e_deixa_encerrados_de_fora` verde | ✓ |
| D-04 (selo de origem + join Publicacao) | `MlAcervoItem::ORIGEM_*`; `resolverOrigem()` em `MlAcervoService`; `considerado()` explicitamente NÃO aplicado na listagem (comentário linha 324 do controller e 371 do service) | ✓ |
| D-05 (zero chamada síncrona) | `Http::assertNotSent`/`assertNothingSent` nos 2 endpoints de leitura, ambos verdes nesta execução | ✓ |
| D-06 (agendamento diário, após ml:sync) | `artisan schedule:list` rodado nesta sessão: `mlb:sync-acervo` 11:35, `mlb:acervo-cleanup` 03:40 | ✓ |
| D-07 (dois níveis de persistência + retenção 90d) | `ml_acervo_itens`/`ml_acervo_metricas_diarias`; `MlAcervoCleanup` com fronteira exata 90×91 dias testada | ✓ |
| D-08 (degradação graciosa) | Bloco `defasagem` sempre presente; teste de snapshot velho com selo | ✓ |
| D-09 (triagem acionável, N≠soma dos chips) | Query agregada `total_com_motivo`; teste explícito do caso de sobreposição (2 motivos, 1 anúncio) | ✓ |
| D-10 (duas medidas de saúde lado a lado, nota fecha com a conta) | `health_ml`/`performance_score`/`nota_ecf` expostos sem conversão; checklist do modal soma exatamente `nota_ecf`, com asserção defensiva de divergência sinalizada (`Log::warning`, não silenciada) | ✓ |
| D-11 (zero write no ML) | Gate automatizado em 6+ arquivos de teste (`SondagemSaudeMlTest`, `ColetaAcervoTest`, `RotacaoDetalheTest`, `MeusAnunciosTest`, `DetalheAnuncioTest`, `estrutura-meus-anuncios.test.js`); grep manual desta sessão em todos os arquivos novos não encontrou `->post/put/patch/delete` contra a API do ML — a única exceção é `publicarLote` (BULK-01, pré-existente da Fase 80, apenas movido de endereço no frontend) | ✓ |
| D-12 (ordenação por gravidade) | `OrdenacaoGravidadeTest` (4 testes: crítico>atenção>saudável, pior nota primeiro, nulo no fim, tie-break estável) | ✓ |
| D-13 (4 abas, Meus Anúncios inicial) | `ModoAnuncioTabs.jsx` array `MODOS`; teste de ordem no gate estrutural | ✓ |
| D-14 (sub-abas Publicados\|Rascunhos, Publicar lote migra) | `SUBABAS` em `MeusAnuncios.jsx`; `RascunhosMeusAnunciosTest` (4 testes) | ✓ |
| D-15 (role:admin, sem item de menu) | Grupo de rota `role:admin`; grep em `AppLayout.jsx` não encontra referência a `mlb.anuncios`/"Meus Anúncios" | ✓ |
| D-16 (wizard intacto, Rascunhos recentes sai) | `estrutura-anunciar-ml.test.js` (6/6), grep cru confirma ausência da frase e presença de "Saúde do anúncio" | ✓ |
| D-17 (variações = 1 linha + agregado) | Colunas `has_variations`/`variations` no schema; `ColetaAcervoTest::item_com_variacoes_grava_uma_linha_com_o_agregado` | ✓ |
| D-18 ("não avaliado" nunca vira status inventado) | Helpers `saudeMlNaoSeAplica()`/`naoAvaliadoBuyBox()`; `buybox_nao_avaliado_nunca_vira_motivo` (2 arquivos de teste, D-03 e D-05 camadas) | ✓ |
| D-19 (duas camadas de custo) | `MlAcervoService` (barata, multiget 20) + `MlAcervoDetalheService` (cara, rotação); jobs e comandos separados | ✓ |
| D-20 (scroll_id, nunca offset) | `varredura_usa_scroll_e_nao_offset` — prova por mutação documentada no SUMMARY (trocar para `offsetlike` derruba o teste) | ✓ |
| D-21 (sondagem: DISPONÍVEL) | `config/mlb_acervo.php` — `saude_ml_disponivel => true` (env default), comentário com o veredicto e o item medido (`MLB5318502460`); fixtures reais em `tests/fixtures/phase134/` (9 arquivos, todos presentes) | ✓ |
| D-22 (nota base 86, nunca renormalizada) | `BarraNotaEcf` no frontend usa literal `/86`; teste `D-22: a nota aparece como "de 86", nunca convertida para 100`; guarda dupla PHP×JS (`NotaEcfFecharComContaTest` + `notaEcfConcordancia.test.js`) | ✓ |
| D-23 (rotação por fatia, N como opção) | `MlAcervoDetalheService::selecionarFatia()`; `--n=` testado por mutação (hardcode de N derruba o teste, per SUMMARY 06) | ✓ |

---

### Regressões verificadas (Fase 86 e adjacências)

| Item | Verificação | Status |
|---|---|---|
| "Anunciar semelhante em massa" / agrupamento por lote do Histórico | `git log -- resources/js/Pages/Mlb/AnunciosHistorico.jsx` mostra **zero commits da Fase 134** tocando o arquivo | ✓ Intacto |
| Deep-link `?rascunho=N` para rascunho fora dos 50 mais recentes | `wizard()` (controller) busca o alvo fora do `limit(50)` quando necessário; teste `deep_link_do_wizard_abre_rascunho_antigo` verde | ✓ |
| As três escalas de saúde nunca convertidas entre si | Grep manual em `MlAcervoService.php`/`MlAcervoDetalheService.php`/`MeusAnuncios.jsx`/`ModalDetalheAnuncio.jsx`: `health_ml`, `performance_score` e `nota_ecf` são sempre lidos/exibidos independentemente, nunca um preenchendo o outro; comentários explícitos nos 3 arquivos citando o caso `nps_medio ≠ pontos_componentes.nps` como o que se está evitando | ✓ |
| Série diária ESTADO×FLUXO | `detalheAnuncio()` linhas 624-643: `vendas`/`notaEcf` fazem forward-fill (`$ultimoVendas`/`$ultimoNota` só atualizam quando existe registro no dia); `visitas` NUNCA preenchido para frente (`$registro?->visitas`, direto). Frontend: `connectNulls={false}` nas 3 `<Line>` do Recharts (`ModalDetalheAnuncio.jsx:260-262`) | ✓ |

---

## Achado de auditoria: bug documentado como "não corrigido" foi de fato corrigido

`deferred-items.md` registra que `MlAcervoService::gravarSerieDiaria()` (134-04) teria o mesmo bug de comparação de data encontrado e corrigido em `MlAcervoDetalheService` (134-05), e que **não foi corrigido** por estar fora do escopo do plano 134-05.

Isso está **desatualizado**. O commit `26726751` (`fix(134-04): serie diaria nunca atualizava a linha do mesmo dia`, 2026-08-10 18:05:53) corrigiu exatamente esse bug em `MlAcervoService.php`, com teste novo (`serie_diaria_atualiza_de_verdade_a_linha_do_mesmo_dia`) que verifica o VALOR (não só a contagem de linhas). Confirmado nesta sessão:
- Leitura do código atual (`MlAcervoService.php:453-459`) mostra a comparação em memória (`$ultima->data->toDateString() === $hoje`), não o `where()`/`updateOrCreate()` cru que causava o bug.
- `php artisan test --filter=ColetaAcervoTest` → **8 testes verdes** (o SUMMARY do 134-05 e o `deferred-items.md` só contavam 7 — o 8º é exatamente o teste desta correção).

**Classificação:** ℹ️ INFO, não bloqueia. É uma lacuna de documentação (`deferred-items.md` não foi atualizado após o fix), não uma lacuna de código. Fica registrado aqui para quem ler `deferred-items.md` depois não ser enganado por ele.

---

## Comandos de verificação executados nesta sessão

| Comando | Resultado |
|---|---|
| `C:\xampp\php\php.exe artisan test --filter=Phase134` | **65 passed (395 assertions)** — bate com o SUMMARY do 134-10 |
| `C:\xampp\php\php.exe artisan test --filter=Mlb` | **115 passed, 4 failed (422 assertions)** — as 4 falhas são `MercadoLivreSugadoresProviderTest`, `Phase13ComercialTest`, `Phase14MlbControllerFiltroTest`, `PublicacaoDesempenhoRouteTest`. Confirmado por `git log` que **nenhum** dos 4 arquivos de teste tem commit da Fase 134 — são pré-existentes (Fases 13/14/39/49), como documentado nos SUMMARYs 134-09/134-10 |
| `npm run test:js` | **192 pass, 1 fail** — a falha é `estrutura-grade-glide.test.js` (Fase 87), confirmada como pré-existente e não tocada por nenhum commit da Fase 134 |
| `node --test tests/js/estrutura-meus-anuncios.test.js tests/js/estrutura-anunciar-ml.test.js` | **30/30 verdes**, incluindo os gates D-11/D-13/D-22 rodados isoladamente |
| `npm run build` | Limpo, `MeusAnuncios-BDxeoIm8.js` presente no manifest do Vite |
| `php artisan schedule:list` | `mlb:sync-acervo` 11:35, `mlb:acervo-cleanup` 03:40, aditivos ao lado de `ml:sync` 11:05 |
| `git status --porcelain` | Limpo — nada pendente, nenhum arquivo de outra sessão paralela tocado |

**Não foi rodado** `artisan test` sem filtro, conforme instrução explícita (timeout conhecido de 300s em `MercadoLivreAdsService`, não é regressão desta fase).

---

## Anti-Patterns

Nenhum bloqueador encontrado. Grep por `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`/"coming soon"/"em breve" nos arquivos novos/modificados da fase retornou apenas falsos positivos (a string `MLBxxxx` contendo "XXX" em comentários, e a palavra portuguesa "todos/todo" casando com o padrão "TODO"). Nenhum botão de pausar/editar/mover ML, nem desabilitado nem "em breve" (grep confirma ausência nos 3 arquivos novos de UI).

---

## Requirements Coverage

Fase avulsa sem REQ-IDs de milestone — os "requisitos" são as 23 decisões D-01–D-23 do `134-CONTEXT.md`, cobertas na tabela acima. Nenhuma ficou só no papel.

---

## Human Verification Required

### 1. Critério de aceite literal do usuário — "5 segundos"

**Test:** Abrir `/mlb/anuncios`, escolher uma empresa com acervo de porte real, e cronometrar até a primeira ação óbvia ficar clara (o que está saudável, o que está perdendo venda e por quê, o que fazer a seguir).
**Expected:** Decisão de "o que fazer" em poucos segundos, sem precisar clicar em nada escondido.
**Why human:** Julgamento de produto/UX, não é uma asserção programática — o próprio `134-VALIDATION.md` classifica este item como Manual-Only.

### 2. Legibilidade da Triagem e do gráfico de evolução

**Test:** Abrir a tela e o Modal de Detalhe numa empresa com dados reais coletados; avaliar hierarquia visual, contraste das cores de severidade, e se o buraco da série de visitas (rotação por fatia) é perceptível como buraco, não como erro visual.
**Expected:** Leitura confortável, buracos honestos e visíveis, nenhuma ambiguidade entre "não avaliado", "não se aplica" e um valor real de saúde.
**Why human:** Qualidade visual — `134-VALIDATION.md` lista explicitamente como Manual-Only.

### 3. Coleta contra a maior conta real (66.747 itens)

**Test:** Rodar `mlb:sync-acervo --company=<id da maior conta>` contra produção e medir duração total e ocorrência de HTTP 429.
**Expected:** Cobertura completa dentro de uma janela aceitável, sem estourar rate limit (a config `rotacao_n=7` foi dimensionada para isso, mas não foi provada contra volume real nesta verificação).
**Why human:** Só é mensurável contra volume real de produção — `Http::fake()` não mede tempo de rede nem rate limit real. `134-VALIDATION.md` classifica como Manual-Only.

---

## Gaps Summary

Nenhum gap bloqueador. Todos os 6 Success Criteria do ROADMAP e as 23 decisões travadas do CONTEXT têm evidência de código e teste executado nesta sessão (não apenas a alegação do SUMMARY). A suíte automatizada bate exatamente com o que os SUMMARYs 134-09/134-10 alegavam (65/65 Phase134, baseline idêntico de 4 falhas pré-existentes em `--filter=Mlb`, 192/193 em `test:js`, build limpo). Um achado de documentação desatualizada (`deferred-items.md` descrevendo como "não corrigido" um bug que foi corrigido no mesmo dia) foi registrado como INFO, sem impacto funcional.

O único motivo para não fechar como `passed` é a presença de itens que exigem verificação humana (critério de aceite de UX cronometrado, legibilidade visual, e teste de carga contra a maior conta real) — nenhum deles é verificável por grep/teste automatizado, e nenhum deles apresentou evidência de falha nesta sessão.

---

_Verified: 2026-08-11T13:58:48Z_
_Verifier: Claude (gsd-verifier)_

---

## Deploy — 2026-08-11

**DEPLOYADO** (`06edda79..32e66ad0`), isolado. Verificado por reconsulta à VPS, não por saída do script.

### Como saiu isolado, apesar da divergência

As branches tinham divergido: `origin/main` estava 10 commits à frente (trabalho de Desempenho do outro dev, **já deployado por ele**) e a local 71 à frente. Pior, a `main` local continha 9 commits de Desempenho **duplicados** — mesmas mensagens, hashes diferentes — porque o outro dev trabalhou neste mesmo checkout e depois publicou por outro caminho.

Merge com conflito **apenas em `.planning/`** (STATE.md + 3 SUMMARYs de quick, todos `add/add` do mesmo conteúdo). Resolvidos ficando com a versão do `origin`, que já trazia a marcação "DEPLOYADO 260810".

O que provou o isolamento: `git diff <HEAD-da-VPS> HEAD -- app resources routes database config` devolveu **exatamente os 21 arquivos da Fase 134** e nada mais, e `git diff origin/main` nos arquivos de Desempenho voltou **vazio** — paridade byte a byte com o que já rodava em produção.

### Estado verificado em produção

| Checagem | Resultado |
|---|---|
| HEAD da VPS | `32e66ad0` |
| Migrations | batch `112`; `ml_acervo_itens` e `ml_acervo_metricas_diarias` existem |
| Workers | `ecf-worker_00`/`_01` RUNNING, uptime 12s — prova de que a última linha do script rodou |
| Bundle (dois lados) | `de 86` **presente** em `MeusAnuncios-BfnJJjIE.js`; `Rascunhos recentes` com **zero** ocorrências em `AnunciarML-*.js` |
| Smoke | `/login` 200 · `/mlb/anuncios` 302 |
| Scheduler | `mlb:sync-acervo` 11:35 · `mlb:acervo-cleanup` 03:40 |
| Log | zero `production.ERROR` da fase; o ruído recente é Adman 429/500 do `SyncTodasVendas`, crônico e anterior ao deploy |

### Duas pegadinhas conhecidas que se confirmaram

1. **`deploy.sh` estourou os 10 min do cliente e completou mesmo assim** (o `chown -R` leva ~9 min). Não foi re-disparado; o estado da VPS é que respondeu.
2. **Workers travaram em `STOPPING`** e seguraram o `supervisorctl restart`, que é a última linha. Destravado com `supervisorctl signal KILL` nos dois — procedimento já registrado no projeto.

### Pendente de observação

A **primeira execução real** do `mlb:sync-acervo` roda às 11:35 do dia do deploy, contra 74 contas / ~406 mil itens. Camada barata estimada em ~20.347 chamadas (~14 min); camada cara em rotação N=7. Vale conferir duração, ocorrência de 429 e volume gravado na primeira passagem.
