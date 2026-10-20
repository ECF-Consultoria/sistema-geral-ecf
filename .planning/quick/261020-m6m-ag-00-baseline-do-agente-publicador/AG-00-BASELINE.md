# AG-00 — Baseline do Agente ECF Publicador

**Data:** 20/10/2026 · **Contrato de origem:** `PLANO_AGENTE_ECF_MVP_CLAUDE_CODE.md` (auditado contra `998e78f0`)
**Natureza:** só leitura — nada de código, banco ou VPS foi tocado.

## 1. Onde estamos (SHA)

| Ref | SHA | Observação |
|---|---|---|
| HEAD local (`main`) | `f11af1ac` | A 189-06 segue commitando nesta mesma árvore. |
| `origin/main` | `f57f57d0` | |
| merge-base | `e250f9fb` | `998e78f0` (o commit auditado) está **só** na origin — não é ancestral do HEAD. |

- **origin → local (18 commits):** vitrine de cartões (`publicador.vitrine`), etapa 5 "Prévia", combos do Planejamento, capa por IA automática, "token só vencido não é desconectado". Mexe em `PublicacaoService` (+20: `concluir()`/`encerrar()` chamam `criarCombosDoPlanejamento()`, só agenda job), `config/publicador.php`, `CabecalhoPublicadorService`, `ProgramasPublicadorService`, `routes/mlb_anuncios.php`. **Nada em `database/`, `Aprovacao/`, `Fila/`, `PreparoIaAgenda`, `PublicarRascunhoJob`.**
- **local → origin (40 commits):** Fase 188/189 (painel e lightbox da Aprovação) + planejamento da 193 (`032db91c`, `4d816ee0`, `838d1d57`).
- **Consequência:** integrar `origin/main` **antes** de qualquer fatia que mexa em `PublicacaoService` ou `CabecalhoPublicadorService` (AG-05b, AG-07). AG-01..03 não dependem disso.

Todas as classes que o plano cita existem no HEAD local com as assinaturas esperadas. Destaques: `AndamentoDaOfertaService::aoAprovar()` :333 está **vazio de propósito** e é chamado dentro da transação de `mover()` :157; `AprovacaoDaOfertaService::aprovar(..., ?int $versaoEsperada = null)` :187 aceita `null` e então não confere; `PublicacaoService::prepararItens` :283 e `abrirTarefaPosPublicacao` :616 são `private`; `AgendadorDaFila` pula produto com `EditorEmUso::emUso()` :337.

## 2. A Fase 193 × o plano do agente — o que sai, o que fica

Decisão do usuário (20/10): **o que a 193 já planejou sai do plano do agente.** A 193 tem 5 planos, nenhum executado, e o 193-01 só começa com a 189 fechada.

| Item do plano | 193 cobre? | Fica no agente |
|---|---|---|
| **G1** — aprovar não enfileira | **Sim** (193-02 liga `aoAprovar()` → `PublicacaoDoPortaoService::enfileirar()`; 193-04 o "Tentar de novo") | — sai |
| **Intenção durável** (escolha AG-00) | **Sim, por estado + varredura**: `fila_item_id` na oferta + reconciliador por minuto ("aprovado sem item vivo → enfileira de novo") | — sai. **Decisão AG-00: adotar a forma da 193. Sem outbox, sem tabela nova de intenção.** |
| **AG-05c** — resultado por item | **Parcial** (193-03: `publicado` só com MLB do próprio `listing_type_id`; falha com erro real) | Fica: provar que UNKNOWN/falha de busca não volta para PENDING e faz POST cego (G9). |
| **G5** — `versao_esperada` obrigatória | **Não** — a 193 põe `AprovacaoDaOfertaService` em "NÃO ALTERAR" (REGUA:463-470) | Fica, como fatia curta **depois** da 193. Só servidor: o front já manda `versao_esperada` em toda chamada (`Aprovacao.jsx:1170`), então **não colide com a 189**. |
| **G3** — granularidade oferta × produto | **Diverge**: "N ofertas → 1 item de fila por produto; a fila publica o produto inteiro" (REGUA:95-108) | **Fica, e é a decisão pendente** (ver §5). |
| **AG-05b** — bypass individual/lote/legado | **Não** | Fica. |
| **G8** — promoção herdada no pós-publicação | **Não** (`PublicacaoService` intacto) | Fica. |
| **G10** — `EditorEmUso` segurando a fila | **Não** | Fica (AG-06). |

**Sobreposição com a 194** (bloqueada no AUTO-07 do dev do Creative Engine): é lá que "nasce a primeira oferta" (AUTO-01/02) — hoje **nenhum código de produção cria `PubAprovacaoOferta`**, só os testes. **O AG-03 passa a ser esse produtor**; a 194 deve reutilizá-lo para a oferta com imagem automática, e não criar um segundo. A G4 (`mlb_empresa_id` nulo, decisão 17) é resolvida no AG-03.

## 3. Entradas que publicam no ML

| Entrada | Caminho | Consulta o portão? |
|---|---|---|
| POST `mlb.anuncios.publicador.publicar` (`routes/mlb_anuncios.php:184`) | `MlbPublicadorController::publicar` → `PublicacaoService::iniciar` | **Não** |
| Cron `publicador:fila-publicacao` (`routes/console.php:599`), alimentado por POST `.../lote/agendar` | `AgendadorDaFila::iniciar` → `PublicacaoService::iniciar` | **Não** |
| `PublicarRascunhoJob` (fila `high`) | despachado só por `iniciar()` | herda: não |
| Legado `/rascunho/{r}/publicar`, `/publicar-duplo`, `/empresa/{c}/publicar-lote` | `MlbAnuncioController` → `MlPublicacaoService` | **Não** — mas opera em `MlAnuncioRascunho`, não em `PubRascunho` |
| POST `.../aprovacao/{oferta}/aprovar` e `/aprovacao/lote` | `AprovacaoDaOfertaService::aprovar` → `aoAprovar()` vazio | É o portão, ainda não publica |

**Hoje nenhum caminho de publicação lê `PubAprovacaoOferta`.** Os portões existentes em `iniciar()` são outros: `ContasLiberadas`, conferência L3 com `revisao`/`plano_hash`, `ciente` com avisos, seller da conferência = seller do token, RN-93. Ponto único para a guarda do AG-05b: **`PublicacaoService::iniciar()`** — todas as entradas do Publicador passam por ele. O legado não publica `PubRascunho`; basta um teste provando isso.

## 4. Esquema real

- **`pub_aprovacao_ofertas`** (`2026_10_15_100200`): `alvo_id` NOT NULL **unique** (FK cascade `pub_rascunho_alvos`), `rascunho_id`, `produto_id` null, `company_id`/`mlb_empresa_id` **nuláveis e sem FK**, `andamento` (default `preparando`), `origem`, `versao` (default 1), `aprovado_por`/`aprovado_em`/`versao_aprovada`, `fila_item_id` (FK set null), `ml_item_id`, `erro_publicacao`. **Não tem** `plano_hash`, `revisao`, `ciente`, `validacao_id` — esses ficam no item da fila, como o plano pede.
- **Conjuntos/imagens:** `pub_aprovacao_conjuntos` unique `(rascunho_id, grupo_hash)` → `_imagens` → alertas/fidelidade/defeitos.
- **`pub_fila_publicacao_itens`:** `produto_ativo` **unique** (um item vivo por produto), `validacao_id`, `plano_hash`, `revisao`, `ciente`, `publicacao_id`.
- **`pub_publicacoes`:** `chave_idempotencia` unique, `revisao` NOT NULL, `plano_hash`. **`pub_publicacao_itens`:** unique `(publicacao_id, indice)`, `listing_type_id`, `variante_chave`, `payload_hash`, `ml_item_id`.
- **Âncoras de conta:** em `pub_produtos` (`company_id`/`mlb_empresa_id` com FK set null).
- **Nome livre:** zero ocorrências de `agente`/`pub_agente_*` em `app/`, `database/`, `routes/`, `config/`, `resources/js`.
- **Banco:** local `mysql` (MariaDB); `phpunit.xml` usa SQLite `:memory:` → DDL nova precisa ser validada no MariaDB local antes do deploy.

## 5. Contrato de uma modalidade

Alvos = `pub_rascunho_alvos` (unique `rascunho_id, listing_type_id`, flag `ativo`); variantes = `pub_variantes` (sempre ≥1, `__single__`). A publicação faz `alvosAtivos() × variantesAtivas()` (`PayloadBuilderUserProducts::montar` :60, `RascunhoSnapshot` :156) e cria um item por par. **Não existe trava de "um alvo só".**

**Contrato proposto para o piloto:** uma oferta gerenciada só pode ir para a fila se **todo alvo ativo do rascunho tiver oferta `aprovado`**, e — no piloto — se houver exatamente **1 alvo ativo e 1 variante ativa**. Fora disso: não enfileira, a oferta mostra o motivo, nada é desativado em silêncio.

**Conflito com a 193:** o 193-02 enfileira "o produto inteiro" na primeira aprovação. Precisa decidir se a guarda entra **como emenda ao 193-02 antes de executá-lo** (recomendado — evita uma janela em que Premium não aprovado sai junto com Clássico aprovado) ou como fatia separada depois.

## 6. Os 3 riscos prioritários

1. **Bypass do portão:** o botão Publicar e a fila em lote publicam sem consultar aprovação. Enquanto o AG-05b não existir, oferta gerenciada não pode ser criada em conta de produção liberada.
2. **Modalidade irmã não aprovada:** a 193 como está escrita publica o produto inteiro (§5).
3. **DDL só validada em SQLite:** já quebrou deploy três vezes (FK SET NULL sem nullable — 1830; drop de índice usado por FK — 1553; nome de índice > 64 chars — 1059). `pub_agente_*` gera nomes longos → **índices nomeados à mão**, migration rodada no MariaDB local.

## 7. Ordem revisada e o que roda em paralelo com a 189

| Fatia | Toca | Com a 189 rodando? |
|---|---|---|
| **AG-01** configuração/controle/execução por loja | migrations novas `pub_agente_*`, models, `app/Services/Publicador/Agente/*`, `tests/Feature/Publicador/Agente/*` | **Sim** — área própria |
| **AG-02** elegibilidade + entrada única do preparo | `Agente/*` + chamada a `PreparoIaAgenda` (patch curto) | Sim |
| **AG-03** produtor de ofertas (inclui G4) | `Agente/*`, leitura dos `PubAprovacao*` | Sim, só backend; ajuste de link da revisão fica para depois da 189 |
| **193** (com a emenda do §5, se aprovada) | `Aprovacao/*`, controller (`tentarDeNovo`), `routes/console.php` | Depois da 189 fechar |
| **AG-04'** `versao_esperada` obrigatória + releitura sob lock | `AprovacaoDaOfertaService` | Depois da 193 |
| **AG-05b'** guarda em `PublicacaoService::iniciar()` + promoção herdada | `PublicacaoService` | Depois de integrar `origin/main` |
| **AG-05c'** UNKNOWN sem POST cego | `PublicacaoService` | Idem |
| AG-06, AG-07, AG-08, AG-09 | como no plano | AG-07 depois da 189 |

**AG-01 — arquivos de escrita:** `database/migrations/2026_10_20_*_create_pub_agente_configuracoes_table.php`, `..._create_pub_agente_execucoes_table.php`, `app/Models/PubAgenteConfiguracao.php`, `app/Models/PubAgenteExecucao.php`, `app/Services/Publicador/Agente/{PoliticaDoAgente,ContaDoAgente}.php`, `tests/Feature/Publicador/Agente/*`. Nenhum arquivo compartilhado com a 189.
