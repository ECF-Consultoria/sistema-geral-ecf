# Cobertura da Fase 193 — os 10 critérios × PUB-01..10 × os 5 planos

**Autoridade:** a entrada da **Fase 193** no `.planning/ROADMAP.md` (os **10 critérios de sucesso**
são o contrato) e **PUB-01..10** em `.planning/REQUIREMENTS-v27.md`.

⚠️ Nenhum item fica sem plano. Onde a cobertura é **por NÃO-REGRESSÃO** (porque o comportamento já
existe no código em produção), está dito — e isso **não** é um atalho: é a leitura correta de PUB-02
(*"reusa a fila que existe"*) e de PUB-03 (*"`MlPublicacaoService` NÃO é alterado"*).

---

## §1 — Os 10 critérios do ROADMAP

| # | critério (resumo) | REQ | plano | como é provado |
|---|---|---|---|---|
| **1** | nada vai ao ML sem passar pelo portão: `approvedBy` + `approvedVersion` registrados e no histórico | PUB-01, PUB-08 | **193-02** | `aoAprovar()` roda **dentro** da transação que gravou `aprovado_por`/`aprovado_em`/`versao_aprovada`; o evento do histórico (186-05) está na mesma transação. Caso provando que **enfileirar e os três campos são atômicos** (falha no enfileiramento ⇒ a aprovação não fica sem prova) |
| **2** | a publicação **REUSA** a fila existente e a granularidade oferta × produto está resolvida, **sem duplicar** `validacao_id`/`plano_hash`/`revisao`/`ciente`/`precisa_revisar` | PUB-02, DADO-03 | **193-02** | `FilaPublicacaoService::agendar()` é **chamado**; `fila_item_id` é a ligação; caso com **DUAS ofertas do mesmo produto** provando **UM** item de fila (anexo) e as 4 colunas **LIDAS** por `filaItem`, com gate de **ausência** das 4 na oferta (já existe: `oferta-do-portao`, da 186-04) |
| **3** | `MlPublicacaoService` com **diff VAZIO** e o gate de conta travada continuando valendo | PUB-03, TERR-03 | **193-05** (diff) + **193-02/03** (trava) | `git diff --numstat <partida>..HEAD -- app/Services/Mlb/Publicacao/MlPublicacaoService.php` **vazio** (⚠️ **por RANGE**, nunca da árvore) + `test -e` do caminho; a trava de conta é `ContasLiberadas::exigir()` dentro de `PublicacaoService::iniciar()`, **reusada**, com caso provando que conta não liberada **não publica** |
| **4** | `publicando` bloqueia edição · `publicado` só com MLB confirmado (sem MLB o estado **não avança** e o toast **não dispara**) · `falha` preserva `approvedVersion` e a revisão · "Tentar de novo" vai direto a `publishing` | PUB-04, AND-04, BLOQ-11 | **193-03** + **193-04** | `podeEditar()` é `ehLive()` e `publicando` ∉ `LIVE` (caso com isca); a porta **recusa** `publicado` sem `ml_item_id` (`REGRA_SEM_MLB`) e o reconciliador grava o MLB **antes** de mover; `mover(FALHA)` **não toca** `versao`/`versao_aprovada`/`aprovado_por`/`aprovado_em`; o retry enfileira **e então** move |
| **5** | o erro exibido é o **erro real** e a frase *"Nenhuma oferta foi criada; a revisão foi preservada"* aparece **só quando é verdade** | PUB-05 | **193-03** | a mensagem vem de `conta_snapshot.motivo` / `avisos.mensagem` do item FAILED (precedente `AgendadorDaFila::fechar()`); a cláusula é **concatenada só** quando **nenhum** `PubPublicacaoItem` **do `listing_type_id` desta oferta** ficou `CREATED` — com caso **PARCIAL** ao lado como **isca invertida** (o Clássico criou, o Premium não: a cláusula sai **só** na oferta que não criou) |
| **6** | trava de conta · **registro ANTES do HTTP** · `5xx`/rede = **UNKNOWN** · **sem reenvio por conta própria** · `UNKNOWN` nunca reenviado sem reconciliar pelo SKU (**RN-93**) | PUB-06 | **193-03** (não-regressão) | ⚠️ **tudo isso JÁ está em `PublicacaoService`** (`SENT` antes do POST, `UNKNOWN`, `reconciliar_apos_segundos`, trava por publicação, `retryUntil`). O que a 193 prova: (a) não-regressão daquele caminho; (b) ⚠️ **o reconciliador JAMAIS toca item `PENDING`/`SENT`/`UNKNOWN`** — com isca invertida (a fixture **tem** item `UNKNOWN` e o andamento **não se move**) |
| **7** | a fronteira do **D-11**: o gate continua **intacto**, **não foi estendido** à área nova, e a área nova tem **gate de escrita próprio** | PUB-07, D11 | **193-05** | `numstat` **vazio** em `tests/js/estrutura-meus-anuncios.test.js` por range; **igualdade de CONJUNTO** das asserções daquele arquivo (⚠️ piso `>=` **não prova presença**); identificação do D-11/D-22 por **SEMÂNTICA**, nunca por linha; e o gate de escrita **próprio** da 193 sobre a **lista constante** dos arquivos desta fase |
| **8** | a decisão de enfileirar automaticamente está **registrada** e o código faz o que ela diz — **nunca os dois caminhos ao mesmo tempo** | PUB-09 | **193-02** + **193-05** | a decisão está em `193-REGUA.md` §0 e no docblock do serviço novo; **gate de AUSÊNCIA com isca** de *"confirmar publicação"* / *"desfazer"* / *"segundo passo"* / *"cancelar aprovação"*, lido **sem comentário**; e **UM caminho só**: caso provando que existe **exatamente uma** chamada a `enfileirar()` no gancho e **nenhuma** rota/action de publicar separada |
| **9** | **nenhuma rota, botão ou coluna sem destino** nesta fase | PUB-10, D4 | **193-05** | igualdade de **CONJUNTO** das rotas de `aprovacao` (⚠️ **CORRIGIDO EM 21/10/2026: são 16 rotas, medidas pelo ROUTER, não "6+5"** — e **mais nenhuma**: esta fase **não cria rota**); **nenhuma coluna nova** (§2 da régua); **nenhum `.jsx` tocado** |
| **10** | o fechamento mede o caminho completo numa **oferta real, em produção, conta 459**, por **checkpoint humano** — ⚠️ nenhum executor afirma ter visto a tela | GATE-14 | **193-05** | `checkpoint:human-verify` com roteiro numerado. ⚠️ **DECLARADO:** só executável **depois da Fase 194**, que cria a primeira oferta (hoje `pub_aprovacao_ofertas` está **VAZIA** e **nada em `app/` cria oferta**) |

---

## §2 — PUB-01..10

| REQ | plano | observação |
|---|---|---|
| **PUB-01** | 193-02 | `aprovado` = *"aguardando publicação"*; o portão é o que a publicação consome |
| **PUB-02** | 193-02 | ⚠️ a fila reusada é `FilaPublicacaoService`/`AgendadorDaFila`/`publicador:fila-publicacao`. ⚠️⚠️ **Medido: `MlPublicacaoService` NÃO está neste caminho** (ele serve o assistente antigo, `MlAnuncioRascunho`) — ver `193-REGUA.md` §1-M2 |
| **PUB-03** | 193-05 | diff vazio por **range** + `test -e`; e o gate de conta travada **reusado**, não recriado |
| **PUB-04** | 193-03, 193-04 | — |
| **PUB-05** | 193-03 | a cláusula **só quando é verdade**, com isca invertida no caso PARCIAL |
| **PUB-06** | 193-03 | não-regressão + o reconciliador **nunca** toca `PENDING`/`SENT`/`UNKNOWN` |
| **PUB-07** | 193-05 | D-11 intacto, não estendido, gate próprio |
| **PUB-08** | 193-02 | quem aprovou e em que versão, no histórico; e o MLB na lista de Publicadas chega **pelo que a lista já mostra** (⚠️ `SincronizarAcervoDoPublicadoJob`, já disparado por `PublicacaoService::concluir()` — **não é trabalho novo**) |
| **PUB-09** | 193-02, 193-05 | a decisão registrada + ausência com isca + **um caminho só** |
| **PUB-10** | 193-05 | nada sem destino |

---

## §3 — ⚠️ O QUE ESTA FASE **NÃO** FAZ, e por quê (para ninguém achar que faltou)

| item | por quê | dono |
|---|---|---|
| **migration** | ⚠️ **MEDIDO: as três colunas já existem** (`fila_item_id` com índice e FK `nullOnDelete` nullable, `ml_item_id`, `erro_publicacao`) — `193-REGUA.md` §1-M1 e §2 | ninguém |
| **qualquer `.jsx`** | ⚠️ **MEDIDO:** a caixa de falha, o botão *"Tentar publicar novamente"*, os 8 estados com cor+ícone+texto e os **6 toasts** já existem (186/187/188) | ninguém |
| **o 7º toast** | VOC-08 é **lista FECHADA** | ninguém |
| **feature-flag de "ligar publicar"** | ⚠️ a trava é `ContasLiberadas` (**fail-closed**, lista vazia = ninguém), que é a mesma que PUB-06 exige. Um segundo interruptor faria **duas verdades** | ninguém |
| **9º bloqueio no portão** ("conferência do ML pendente") | ⚠️ exigiria editar `VocabularioDoPortao` e `BloqueiosDaOfertaService` — **serviços da Fase 186, em produção** | ⚠️ **PERGUNTA-193-A — USUÁRIO** |
| **editar foto de anúncio JÁ NO AR** (decisão 13) | ⚠️ **MEDIDO:** `PUBLICADO => []` é terminal **por estrutura** e **não existe NENHUM `PUT /items/{id}`** no Publicador. Nenhum dos 10 critérios o pede | ⚠️ **PERGUNTA-193-B — USUÁRIO** (recomendação: **diferir**) |
| **o toast `publicado` disparar sozinho** | a confirmação chega minutos depois, pelo scheduler, e **não há polling**. ⚠️ O critério 4 exige justamente que ele **NÃO dispare sem MLB** — é asserção de **ausência** | **PERGUNTA-193-C** — Fase 194 ou fase de tela |
| **PERGUNTA-17** (`mlb_empresa_id` nulo escondendo oferta da fila) | é da **Fase 194**, que é quem **cria** oferta | Fase **194** |
| **as Fases 190/191** | ⚠️ **FORA DE ESCOPO** (decisão **20**, 20/10/2026). Nenhum plano as cita como fornecedoras de nada | — |
| **revisão detalhada, `ajuste_em_aberto`/`titulo_longo` com destino, botão "Abrir revisão detalhada"** | ⚠️ **deixaram de ser pendência em 20/10**: as duas ressalvas do fechamento da 188 (CRIT-02, CRIT-09) **viraram decisão** | — |
