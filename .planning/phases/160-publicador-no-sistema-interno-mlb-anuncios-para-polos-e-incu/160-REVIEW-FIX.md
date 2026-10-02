---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
fixed_at: 2026-10-02
review_path: .planning/phases/160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu/160-REVIEW.md
iteration: 1
escopo: escolha do usuário — 4 BLOCKERs (CR-B01, CR-B02, CR-F01, CR-F02) + 9 warnings (WR-B01..B06, WR-F02, WR-F04, WR-F07)
findings_in_scope: 13
fixed: 13
skipped: 0
status: all_fixed
fora_do_escopo: 14 warnings e 16 infos do 160-REVIEW.md, sem correção nesta fase
---

# Correções do code review — Fase 160

Duas passadas em sequência na mesma árvore (backend primeiro, porque o WR-B01 podia mudar
props do front). O corretor de backend foi interrompido por pausa pedida pelo usuário no meio
do WR-B02 e retomado depois. Os 6 commits anteriores à pausa foram conferidos no passo 0 da
retomada (Publicador 367/1784 e PortalCliente 231/1872, verdes).

Conferido pelo orquestrador: o autosave parcial do front (CR-F02) bate com o servidor —
`EditorRascunhoService::salvar` só mexe na chave que vier (e `atributos`/`alvos` vão como mapa
inteiro da cópia local, por chave de topo), e `salvarVariantes` aplica por campo
(`array_key_exists`), sem zerar o que não veio.

## Resultado final

| Grupo | Depois das correções |
|---|---|
| Publicador (Unit + Feature) | 376 testes / 1874 asserções, verde |
| PortalCliente | 231 / 1872, verde |
| Soltos (IA, listagem, token) | 52 / 190, verde |
| Phase75 | 43 / 151, verde (4 deprecations da baseline) |
| `npm run test:js` | 645 testes, 643 passam, 2 falham (as 2 pré-existentes da baseline) |
| `npm run build` | ok; Editor, Produtos e AnunciosEmpresas no manifest |

## Precisa de conferência humana

- WR-B02: a trava `lockForUpdate` única do rascunho (IA, editor e `iniciar`) não roda no SQLite.
  No MariaDB, salvar durante a geração da IA deve esperar milissegundos, sem deadlock nem 500.
- CR-F01, CR-F02, WR-F02: o modelo de salvamento mudou (fila única, envio só do que foi editado,
  mesa só leitura durante a IA, novas tentativas e aviso ao sair) — ver a lista do relatório de frontend.

---

# Parte 1 — Backend

# Fase 160: Relatório das correções do code review (backend)

**Corrigido em:** 2026-10-02T23:06:16Z
**Review de origem:** `160-REVIEW.md` (Parte 1, backend)
**Worktree / branch:** `C:/tmp/ecf-publicador-spec-261001`, `feat/publicador-ml-261001` (não pushado, não deployado)
**Iteração:** 1. Os 6 primeiros IDs foram feitos por outro agente antes da pausa. Esta continuação conferiu esses 6 e fez WR-B02, WR-B04 e WR-B05.

**Escopo do usuário:** os 4 BLOCKERs e WR-B01..B06. Os achados de backend são CR-B01, CR-B02 e WR-B01..B06, total de 8. Os outros warnings (WR-B07..B09) e os INFO ficam registrados no review, sem correção nesta fase.

**Resumo**
- Achados no escopo: 8
- Corrigidos: 8. O WR-B02 pede verificação humana no MariaDB (ver abaixo).
- Pulados: 0
- `resources/js/` não foi tocado.

**Nota sobre o isolamento:** o agente trabalhou direto no worktree indicado, como pedia a configuração (`use_worktrees=false`, mais a mudança parcial do WR-B02 que já estava no worktree). Não criou outro worktree. Cada commit foi feito por caminho, conferido com `git diff --cached --name-only` e `git show --stat`.

## Suítes

| Grupo | Passo 0 (antes desta continuação) | Final | Exit |
|---|---|---|---|
| `tests/Unit/Publicador tests/Feature/Publicador` | 367 / 1784, verde | **376 / 1874**, verde | 0 |
| `tests/Feature/PortalCliente` | 231 / 1872, verde | **231 / 1872**, verde | 0 |
| 4 soltos (`AnunciosPolosNaListagem`, `MlTokenAncoraPolos`, `AnuncioIaAnalise`, `AnuncioIaRascunho`) | — | **52 / 190**, verde | 0 |
| `tests/Feature/Phase75` | — | **43 / 151**, 4 PHPUnit Deprecations (as da baseline) | 0 |

- **Passo 0:** os 6 commits anteriores estavam verdes, então nada precisou ser corrigido neles. A suíte do Publicador já tinha 367 testes, contra 350 da baseline 160-15: são os 17 testes que esses commits adicionaram.
- **Do passo 0 ao final:** +9 testes no Publicador. São 5 do WR-B02, 2 do WR-B04 e 2 do WR-B05. Os outros 3 testes do WR-B04 já existiam e foram reescritos.
- **Phase75:** a falha intermitente `test_admin_nao_recebe_403_no_update` não apareceu.
- **Saídas brutas** em `C:/tmp/ecf-160-gates/fixb/`: `p0-*.txt` (passo 0), `final-*.txt` (final) e `b0*-mutacao.txt` (testes rodados contra o código anterior).

## Corrigidos

### CR-B01: a trava D21 não era reaplicada no job nem no reenvio de descrição
**Commit:** `e057cc7b` (agente anterior)
**Arquivos:** `app/Services/Publicador/PublicacaoService.php`, `app/Services/Publicador/ImagemAssetService.php`, testes em `PublicacaoTest`, `MlbPublicadorTest` e `MlbPublicadorAcessoTest`

**O que mudou:**
- `iniciar()` fixa a conta em `pub_publicacoes.ator.conta`: guarda `chave` (a âncora) e `seller` (o vendedor lido na conferência). Não foi preciso migration, porque a coluna JSON já existia.
- `contaFixada()` relê a âncora antes de cada escrita: fotos, cada `POST /items`, descrição e reenvio de descrição. Se a âncora mudou, se a conta saiu da lista ou se o token é de outro vendedor, nada é enviado.
  - Regras novas: `V-ACC-03`, mais `CONTA-LIB` e `V-ACC-01`.
- Publicação sem `ator.conta` (anterior a 02/10) falha FECHADO. Conferência L3 sem `respostas_ml.conta.sellerId` não autoriza publicar.

**Testes que provam:** `PublicacaoTest::test_cr_b01_*` (8 testes). Cobrem OAuth concluído no meio, token revogado que derruba para a outra âncora, conta tirada da lista, outro vendedor, troca entre itens da mesma fatia e reenvio de descrição. Em todos, nenhum `POST` sai.

**Contrato para o front:**
- `estado.publicacao.motivo` pode trazer as mensagens novas, por exemplo "A conta do Mercado Livre deste produto mudou desde o clique em Publicar…" ou "…agora é de outro vendedor…".
- Itens que nunca foram ao ML viram `FAILED`, com `mensagem` = motivo.

### CR-B02: FKs em CASCADE apagavam o histórico de publicação ao excluir a empresa
**Commit:** `20069271` (agente anterior). A atualização do learnings foi no `ba3cac8d`.
**Arquivos:** `database/migrations/2026_10_02_100000_create_pub_produtos_table.php` e a migration nova `2026_10_02_200000_pub_produtos_ancoras_sem_cascata.php`

**O que mudou:**
- `pubprod_empresa_fk` e `pubprod_company_fk` passaram a `nullOnDelete`, por decisão do usuário.
- A migration nova conserta, com SET NULL, o banco onde a criação já rodou em CASCADE. Ela só age em mysql/mariadb e é idempotente. O `down()` de propósito não volta a CASCADE.
- Já rodou no MariaDB local (lote [125]). Em produção a criação já nasce SET NULL.

**Testes que provam:** `ExclusaoDaEmpresaPreservaHistoricoTest` (4 testes).
- Excluir a MlbEmpresa ou a Company mantém produto, rascunho, publicação e item, com `ml_item_id`, payload e resposta.
- O produto órfão some das telas e `conta()` lança V-ACC-01.

**Contrato para o front:** nenhum.

### WR-B01: a tela mostrava a conta da EMPRESA; a publicação usa a do PRODUTO
**Commit:** `8a1d5db8` (agente anterior)
**Arquivos:** `app/Http/Controllers/MlbPublicadorEntradaController.php`, `app/Services/Publicador/ProgramasPublicadorService.php`, teste em `MlbPublicadorProdutosTest`

**O que mudou:** o editor e a tela B passaram a mostrar a conta que de fato publica o produto.

**Teste que prova:** `MlbPublicadorProdutosTest::test_wr_b01_editor_e_tela_b_mostram_a_conta_que_publica_o_produto`.

**Contrato para o front (importante):**
- **Editor (props Inertia de `Mlb/Publicador/Editor`):**
  - `empresa.token`, `empresa.link_reconexao`, `empresa.conta_nome` e `empresa.conta_ml_id` vêm agora da âncora do PRODUTO (`$produto->contaOuNula()`). Os nomes das props não mudaram.
  - `empresa.chave`, o nome e a navegação continuam sendo da empresa da tela.
  - O link de reconexão é o da `MlbEmpresa` do produto.
  - O prop `liberada` = `ContasLiberadas::libera($produto->contaOuNula())`. Agora ele sempre concorda com o `publicacao_liberada` do JSON.
- **Tela B (props `Mlb/Publicador/Produtos`):** cada item de `produtos[]` ganhou três campos NOVOS. Hoje o front não os usa, e cabe a ele sinalizar o produto que publica por outra conta.
  - `conta_nome` (string|null): a conta do PRODUTO.
  - `conta_diferente` (bool): true quando a conta do produto difere da do cabeçalho.
  - `liberada` (bool): da conta do produto.
- O objeto `empresa` da tela B (cabeçalho) continua com a conta da empresa.

### WR-B02: a IA no rascunho (D14) checava "intocável" e a revisão uma vez só e regravava listas inteiras
**Commit:** `79658830`. **Status: fixed — requer verificação humana.** O `FOR UPDATE` não existe no SQLite dos testes, então o comportamento da trava no MariaDB não está provado por teste automático.

**Arquivos:**
- `app/Services/Publicador/IaParaRascunhoService.php`
- `app/Services/Publicador/RascunhoRepository.php`
- `app/Services/Publicador/EditorRascunhoService.php`
- `tests/Feature/Publicador/IaParaRascunhoTest.php`
- `tests/Feature/Publicador/RascunhoRepositoryTest.php`

**O que mudou:**
- **Toda escrita da IA passa por `sobTrava()`.** Numa transação, o rascunho é relido com `lockForUpdate` e só depois se decide:
  - Se está publicando ou publicado pelo fato (`intocavel`: RUNNING ou item CREATED), não grava nada e a IA para ali.
  - O "substituir" é refeito a cada escrita. Revisão diferente da esperada (a do pedido; depois, a da última escrita da própria IA) significa que a pessoa editou. A partir daí, até o fim da aplicação, a IA só preenche o vazio.
- **Gravações parciais.** Atributos vão por `mesclarAtributos()` e títulos por `gravarTitulos()`: só as chaves que a IA preenche. `ativo`, ordem e o resto da lista ficam como estão. A IA não chama mais `gravarAtributos`/`gravarAlvos`.
- **Schemas fora da trava.** Os schemas, que podem ir ao ML, são lidos antes da trava. Dentro dela só vale o schema guardado, e só se a categoria ainda for a mesma.
- **Editor com a mesma trava.** Foi adicionado `RascunhoRepository::travar()`. `salvar`, `trocarCategoria`, `salvarEixos` e `salvarVariantes` pegam a trava primeiro e leem o snapshot depois. Assim editor, IA e `iniciar` escrevem um de cada vez, sem deadlock por ordem invertida.
- **Publicação que começa no meio:** o aviso novo diz que a IA parou.

**Testes que provam.** Os 4 da IA falham com o serviço anterior: `b02-mutacao.txt`.
- `test_wr_b02_edicao_da_pessoa_durante_a_geracao_fica_mesmo_pedindo_substituir`: característica, título e descrição digitados durante a leitura do schema ficam. O vazio é preenchido e `sobrescreveu=false`.
- `test_wr_b02_publicacao_que_comeca_durante_a_geracao_deixa_o_rascunho_intocado`: publicação RUNNING e item CREATED criados no meio. Nada muda, `secoes=0`.
- `test_wr_b02_publicacao_que_comeca_no_meio_da_aplicacao_para_a_ia_ali`: a categoria gravada antes fica e o resto não é escrito.
- `test_wr_b02_ia_nunca_regrava_a_lista_inteira`: não há nenhuma chamada a `gravarAtributos`/`gravarAlvos`. Valor mudado, característica nova e título trocado logo depois da leitura da IA ficam. O tipo desligado continua desligado.
- `RascunhoRepositoryTest::test_mesclar_atributos_e_gravar_titulos_so_tocam_o_que_veio`.

**Verificação humana sugerida:** no MariaDB, um PUT `salvar` durante a etapa "rascunho" da IA deve esperar milissegundos, sem deadlock nem 500. O rascunho final deve ter a edição da pessoa.

**Contrato para o front** (para o WR-F04 e o CR-F02):
- `resultado.publicador` continua com `secoes` como INTEIRO (o front lia `.length`).
- `aviso` pode trazer a frase nova: "A publicação começou enquanto a IA preenchia; ela parou ali e não mexeu mais no anúncio."
- `sobrescreveu` agora é o estado FINAL. É true só se a IA substituiu do começo ao fim. Se a pessoa editou no meio, é false, e o front pode dizer "como houve edição durante a geração, a IA só preencheu o vazio".
- O servidor não sobrescreve mais o que a pessoa grava. Ainda vale o inverso do CR-F02: o autosave do documento inteiro apaga o que a IA gravou. Isso continua sendo assunto do front.

### WR-B03: "Conferir" rebaixava rascunho publicado ou publicando para VALIDATED
**Commit:** `f95475dd` (agente anterior)
**Arquivos:** `app/Services/Publicador/ConferenciaService.php`, `app/Services/Publicador/IaParaRascunhoService.php` e testes

**O que mudou:**
- A conferência aprovada faz um UPDATE condicional: mesma revisão E status em DRAFT/VALIDATED/FAILED. PUBLISHING/PUBLISHED/PARTIALLY_PUBLISHED não mudam.
- `intocavel()` passou a olhar o fato: publicação RUNNING ou algum item CREATED.

**Testes que provam:**
- `ConferenciaTest::test_wr_b03_conferir_nao_rebaixa_rascunho_publicando_ou_publicado`
- `IaParaRascunhoTest::test_wr_b03_item_criado_ou_publicacao_rodando_tornam_intocavel_qualquer_status`

**Contrato para o front:** nenhum novo. A tela B deixa de mostrar "conferido" num produto publicado.

### WR-B04: produto sem token — a foto voltava 422 e a conferência local nem rodava
**Commit:** `cc46ceaf`
**Arquivos:**
- `app/Services/Publicador/ImagemAssetService.php`
- `app/Services/Publicador/ConferenciaService.php`
- testes em `ConferenciaContaNaoLiberadaTest`, `ConferenciaTest`, `MlbPublicadorAcessoTest` e `PublicaMlbEmpresaSemCompanyTest`

**O que mudou:**
- `enviarAoMl()` e `conferir()` usam `$r->produto->contaOuNula()`. Sem token ativo (ou conta fora da lista), a conta conta como "não liberada":
  - a foto fica `pending`, sem `upload_erro`, entra no grupo e sobe depois pelo `enviarPendentes`;
  - a conferência é a local (camada `L2`, resultado `LOCAL`/`BLOQUEADO`), sem nenhuma chamada.
- `respostas_ml.motivo` = `V-ACC-01` quando não há token e `CONTA-LIB` quando a conta está fora da lista.
- O V-ACC-01 continua exigido em `iniciar` (`conta()`), então publicar responde 422 `V-ACC-01`.

**Testes que provam.** Os 5 falham com os serviços anteriores: `b04-mutacao.txt`.
- `MlbPublicadorAcessoTest::test_wr_b04_sem_token_foto_entra_no_grupo_conferencia_e_local_e_publicar_pede_reconectar`, ponta a ponta pelas rotas:
  - upload devolve 200 com a foto pendente no grupo `GENERAL`;
  - o mesmo arquivo de novo não duplica;
  - reenviar devolve 200 e a foto continua pendente;
  - a conferência é local;
  - publicar devolve 422 `V-ACC-01`;
  - nenhuma chamada ao ML.
- `ConferenciaContaNaoLiberadaTest::test_wr_b04_sem_token_confere_local_sem_chamada_mesmo_em_conta_liberada`.
- `ConferenciaContaNaoLiberadaTest::test_wr_b04_foto_sem_token_fica_pendente_sem_erro_e_sobe_depois_da_reconexao`.
- Três testes que fixavam o comportamento antigo (ERRO V-ACC-01 na conferência) foram renomeados e agora esperam a conferência local: `ConferenciaTest::test_modelo_que_mudou_bloqueia_e_conta_desconectada_confere_so_local` e `PublicaMlbEmpresaSemCompanyTest::test_sem_token_abrir_grava_o_erro_conferir_e_local_e_publicar_falha_com_v_acc_01`. O terceiro é o teste de conferência sem token em `ConferenciaContaNaoLiberadaTest`, listado acima.

**Contrato para o front:**
- Sem token:
  - `POST fotos` e `POST fotos.reenviar` devolvem 200 (antes, 422 "reconecte"), com a foto `upload_status: pending`, `tem_arquivo: true` e a miniatura pela rota interna.
  - "Conferir" roda e devolve `conferencia.local = true`.
  - `publicacao_liberada` = false.
  - Publicar continua 422 com `regra: 'V-ACC-01'`.
- Ajuste de texto: `Editor.jsx` (faixa `tokenExpirado`) ainda diz "precisa ser reconectada antes de conferir ou publicar". Hoje a conferência local roda sem token, então o texto ficou impreciso. Sugestão: "antes de conferir no Mercado Livre ou publicar".

### WR-B05: programa derivado de dois jeitos (SQL sem caixa × PHP estrito) — empresa listada dava 404
**Commit:** `93416570`
**Arquivos:** `app/Models/MlbEmpresa.php`, `tests/Feature/Publicador/ProgramaPublicadorTest.php`, `tests/Feature/Publicador/MlbPublicadorProdutosTest.php`

**O que mudou:**
- `MlbEmpresa::normalizado()` = `mb_strtolower(trim(valor, ' '))`, aplicado a `projeto`, `fase` e `tipo` em `programaPublicador()`.
- **Além do pedido:** o `scopePrograma` (SQL) passou a comparar com a MESMA normalização, `LOWER(TRIM(col))`. Assim "listada ⇒ abre" vale nos dois bancos, e o SQLite dos testes deixa de esconder a diferença.
  - O `TRIM` do SQL tira só espaço, e o PHP também (`trim(…, ' ')`).
  - O PAD SPACE do MariaDB ignorava só o espaço à DIREITA. Com o TRIM nos dois lados, `' POLOS '` (com espaço à esquerda) passa a contar como Polos no SQL e no PHP.
- **Conferido só com leitura no MariaDB local** (SELECT dentro de transação desfeita; script em `scratchpad/wr_b05_mariadb_leitura.php`):
  - o SQL novo é válido;
  - lista as mesmas 284 empresas de Polos e 3 da Incubadora que o antigo;
  - PHP e SQL concordam em todas as ativas.
  - O MariaDB local só tem as grafias canônicas. **Produção não foi medida.** A medição que o review sugere (`GROUP BY BINARY projeto`) fica para quem tiver acesso de leitura.

**Testes que provam.** Os 2 falham com o modelo anterior: `b05-mutacao.txt`.
- `ProgramaPublicadorTest::test_wr_b05_caixa_e_espacos_nas_pontas_dao_o_mesmo_programa_no_sql_e_no_php`: matriz com `'Polos'`, `' POLOS '`, `'polos  '`, fase e tipo em outra caixa e não-casos.
- `MlbPublicadorProdutosTest::test_wr_b05_projeto_com_outra_caixa_ou_espacos_aparece_na_tela_a_e_abre_a_tela_b`: `'Polos'` e `' POLOS '` aparecem na aba Polos e abrem a tela B e o editor sem 404.

**Contrato para o front:** nenhum.

### WR-B06: a migration com dado de produção só reconhecia o driver `mysql`
**Commit:** `7487c288` (agente anterior)
**Arquivo:** `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php`

**O que mudou:** o helper `emMysql()` aceita `mysql` e `mariadb` em `hasIndex`, `hasForeignKey` e no `dropForeign` do `down()`.

**Testes que provam:** `MigracoesDaFaseDetectamMariaDbTest::test_a_migration_do_backfill_trata_mariadb_como_mysql` e `test_nenhuma_migration_da_fase_compara_o_driver_so_com_mysql`.

**Contrato para o front:** nenhum.

## Commits de documentação
- `ba3cac8d` (agente anterior): learnings §9 sobre CR-B01 e CR-B02.
- `b5bfb90a` (esta continuação): learnings §9 com quatro registros:
  - sem token = não liberada (V-ACC-01 só ao publicar);
  - a trava única do rascunho: travar antes e ler depois; a IA só grava as chaves que preenche; como os testes simulam a corrida no SQLite;
  - o programa normalizado no SQL e no PHP;
  - a pegadinha do `UploadedFile::fake()` lido em uma linha.

## O que fica para depois
- **Frontend:** CR-F01, CR-F02, WR-F02, WR-F04 e WR-F07, além dos contratos acima. O mais importante é o WR-B01, porque a tela B ganhou `conta_nome`, `conta_diferente` e `liberada` por produto.
- **Gate da fase:** `npm run test:js`, `npm run build` e o manifest, depois `160-REVIEW-FIX.md` e a VERIFICATION.
- Nenhum deploy, push nem chamada real ao ML foi feito. Nenhuma escrita no MariaDB: só o SELECT de conferência do WR-B05, numa transação desfeita.

---

_Corrigido em: 2026-10-02T23:06:16Z_
_Corretor: Claude (gsd-code-fixer)_
_Iteração: 1 (continuação)_

---

# Parte 2 — Frontend

# Fase 160: correções do code review (frontend)

**Corrigido em:** 2026-10-02T23:35:00Z
**Review de origem:** `160-REVIEW.md`, Parte 2 (frontend)
**Worktree e branch:** `C:/tmp/ecf-publicador-spec-261001`, `feat/publicador-ml-261001`. Nada foi pushado nem deployado.
**Iteração:** 1

**Escopo (escolha do usuário):** CR-F01, CR-F02, WR-F02, WR-F04 e WR-F07. Os demais achados F- ficam no review. Não toquei em `app/`, `routes/`, `database/` nem `config/`.

**Resumo**
- Achados no escopo: 5
- Corrigidos: 5. CR-F01, CR-F02 e WR-F02 mudam o modelo de salvamento (concorrência e estado), então ficam como **fixed: requer verificação humana** no navegador.
- Pulados: 0

**Nota sobre o isolamento.** Trabalhei direto no worktree indicado, como o agente do backend. Esse worktree já é isolado do checkout principal, e o `npm run build` precisa do `public/build` e da junction `node_modules` dele. Não criei outro worktree nem sentinela.

Cada commit:
- foi feito por caminho (`git add -- <caminhos>` e `git commit -- <caminhos>`);
- foi conferido com `git diff --cached --name-only` (só os meus arquivos) e com `git show --stat`.

A árvore terminou limpa.

## Suítes e build

| Gate | Antes (passo 0) | Final |
|---|---|---|
| `npm run test:js` | 624 testes: 622 passam, 2 falham | **645 testes: 643 passam, 2 falham** |
| `node --test tests/js/publicador-editor.test.js` | 29/29 | **50/50** |
| `npm run build` | — | **exit 0** (`✓ built in 26.04s`) |

**As 2 falhas são as da baseline e nenhuma é nova:**
- "Características secundárias nasce recolhido (é o grupo que mais infla)"
- "FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha"

**Manifest:**
- O `public/build/manifest.json` passou de mtime `2026-10-02 15:44:45 -0300` para `2026-10-02 20:34:15 -0300`.
- As três páginas estão no manifest, com o arquivo existindo e mtime novo:
  - `Pages/Mlb/Publicador/Editor.jsx` → `assets/Editor-5aXH09rt.js`
  - `Pages/Mlb/Publicador/Produtos.jsx` → `assets/Produtos-r9yaj-Ph.js`
  - `Pages/Mlb/AnunciosEmpresas.jsx` → `assets/AnunciosEmpresas-WN-6oHn2.js`
- As strings novas ("Não salvo — tentando de novo" e "Li os avisos da conferência") estão no `Editor-5aXH09rt.js`. Isso prova que o build saiu. Não prova o comportamento.

**Saídas brutas** em `C:/tmp/ecf-160-gates/`:
- `front-p0-testjs.txt` (baseline);
- `front-crf01-testjs.txt`, `front-crf02-testjs.txt`, `front-wrf02-testjs.txt`, `front-wrf04-testjs.txt` e `front-wrf07-testjs.txt` (suíte depois de cada commit);
- `front-build.txt`.

**Sintaxe:** depois de cada edição, `esbuild` (só parse) rodou em todos os `.js` e `.jsx` mexidos.

## Corrigidos

### CR-F01: ação de estrutura trocava a cópia local inteira e o autosave gravava a versão antiga
**Commit:** `06ad4683`. **Status: fixed — requer verificação humana.**
**Arquivos:**
- `resources/js/Components/Publicador/derivados.js`
- `resources/js/Components/Publicador/usePublicador.js`
- `tests/js/publicador-editor.test.js`

**O que mudou:**
- **Fila de escrita.** Todo PUT, POST e DELETE do editor passa por `enfileirar`: salvamentos, ações de estrutura, conferir e publicar. Agora `descarregar = () => enfileirar(salvarTudoAgora)`, ou seja, espera também o salvamento que já está em voo.
- **Base.** O hook guarda o que o servidor já tem da cópia local: a última leitura ou o último salvamento que deu certo. Campo em que a cópia local difere da base é edição ainda não salva.
- **`estruturar(fazer)`.** Todas as ações de estrutura passam por ele. Isso vale para:
  - foto: enviar, atribuir, remover e reenviar;
  - categoria;
  - eixos.

  Dentro da fila, `estruturar` salva o pendente e então faz o pedido com `tudo: true`. Na volta, aplica a resposta com `mesclarComPendentes`, uma função pura de três vias:
  - o campo editado durante a ação fica na tela e é reagendado para salvar;
  - o resto vem do servidor;
  - atributos são decididos um a um, títulos por tipo e variantes campo a campo;
  - variante que o servidor não tem mais cai.
- `enviarFotos` dá uma volta da fila por arquivo. Assim, o que se digita enquanto as fotos sobem é salvo entre um envio e outro.
- `atribuirFotos` reaplica a ordem otimista depois do salvamento prévio, para a miniatura não "pular".
- As cópias locais passaram a ser atualizadas junto com o estado (`porRasc`/`porVars`), não no render. Antes, um render com estado ainda não processado podia voltar a ref.

**Decisão:** não travei a digitação durante a ação de estrutura (a 3ª sugestão do review). O cenário 2 (escrever a descrição enquanto 6 fotos sobem) pede que a mesa continue editável, e a mescla resolve isso sem trava.

**Testes que provam** (todos em `publicador-editor.test.js`):
- `CR-F01 título digitado durante a ação de foto…` (cenário 1)
- `CR-F01 descrição digitada enquanto as fotos sobem…` (cenário 2)
- `CR-F01 troca de categoria…`: o descartado sai; o editado e o apagado no meio ficam.
- `CR-F01 variantes…`
- `CR-F01 sem edição pendente…`
- `CR-F01 iguais`
- `CR-F01 envioDoRascunho e envioDasVariantes`
- **Gate de fonte:**
  - as 6 ações usam `estruturar(` e nenhuma chama `chamar(` por fora da fila;
  - `estruturar` faz `await salvarTudoAgora()` antes de `chamar(fazer, { tudo: true })`;
  - `chamar` com `tudo` mescla;
  - `descarregar` entra na fila;
  - não sobrou `setRasc(doEstado` nem a ref atualizada no render.

### CR-F02: o autosave do documento inteiro apagava o que a IA gravou, e `recarregar` corria um PUT contra o GET
**Commit:** `ecdb0b8a`. **Status: fixed — requer verificação humana.**
**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js`
- `resources/js/Components/Publicador/useIaDoPublicador.js`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx`
- `tests/js/publicador-editor.test.js`

**O que mudou:**
- **Pausa.** `usePublicador({ …, pausado })` recebe do Editor `pausado: ia.estado === 'andamento'`. Pausado:
  - o `disabled` da mesa inclui `pausado || relendo`, então a mesa é só leitura e Conferir e Publicar também ficam desligados;
  - `salvarRascAgora`/`salvarVarsAgora` não enviam nada;
  - a faixa azul ganhou o aviso calmo: "Enquanto ela trabalha, a mesa fica só para leitura. O que ela preencher aparece aqui quando terminar."
- **Fim da IA.** No fim, concluída ou com erro no servidor (`onFalhou`, novo em `useIaDoPublicador`; um erro pode ter gravado parte), roda `recarregarDepoisDaIa`. Ele:
  - liga `relendo` no mesmo lote do React que tira a pausa, então a mesa não fica editável nem por um render;
  - relê dentro da fila e só então libera;
  - se a leitura falhar, mantém a mesa só leitura e pede para recarregar a página.
- **`recarregar`** (reenviar descrição e "Tentar de novo") agora é `enfileirar(() => reler({ descarregarAntes: true }))`: salva o pendente, espera e só então faz o GET. Nenhum PUT corre contra a leitura. O estado `recarga` e o efeito que disparava o PUT fire-and-forget no cleanup saíram.
- **Complemento sugerido no review.** O PUT do autosave leva só os campos editados (`envioDoRascunho` e `envioDasVariantes`). O servidor ignora a chave ausente (`EditorRascunhoService::salvar`/`salvarVariantes`: "Chave ausente = não mexe"). Antes ia o documento inteiro. A troca de produto descarrega na fila, só o pendente e sem mexer na tela.

**Decisão:** `recarregarDepoisDaIa` lê ANTES de salvar o pendente, e não "descarrega e depois lê". Esse pendente só existe se um salvamento falhou antes ou durante a IA. Mandado antes, ele levaria o mapa `atributos` inteiro de antes da IA (o servidor regrava a lista inteira de atributos) e apagaria as características que a IA acabou de gravar. Lido primeiro, ele é mesclado atributo a atributo e só depois salvo. Os dois caminhos ficam dentro da fila, então também neste não há PUT correndo com o GET. Era o que a orientação queria evitar.

**Testes que provam:**
- `CR-F02 salvamento manda só o campo editado…`
- `CR-F02 releitura depois da IA…`: entram o BRAND da pessoa e o COLOR e o MATERIAL da IA, e o que sai depois é o mapa mesclado.
- **Gate do Editor:**
  - `pausado: ia.estado === 'andamento'`;
  - `onConcluiu` e `onFalhou` chamam `recarregarDepoisDaIa`;
  - o texto do aviso está lá;
  - não sobrou `onConcluiu: () => pub.recarregar()`;
  - `useIaDoPublicador` chama `aoFalhar`.
- **Gate do hook:**
  - a pausa vem antes do `axios.put`;
  - o payload é `envio`;
  - em `reler`, o `await salvarTudoAgora()` vem antes do `axios.get`;
  - `recarregar` e `recarregarDepoisDaIa` entram na fila;
  - não sobrou `setRecarga` nem PUT de `rascRef.current` ou `varsRef.current` inteiros.

**Contrato do backend (WR-B02)** foi considerado no desenho: o `secoes` inteiro, o novo `aviso` "A publicação começou…" e o `sobrescreveu` final. O front não sobrescreve mais o que a IA gravou nos outros campos.

### WR-F02: autosave que falhava não tentava de novo, o indicador seguia "Salvo" e não havia aviso ao sair
**Commit:** `d1732e98`. **Status: fixed — requer verificação humana.**
**Arquivos:**
- `resources/js/Components/Publicador/usePublicador.js`
- `resources/js/Components/Publicador/derivados.js`
- `resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx`
- `tests/js/publicador-editor.test.js`

**O que mudou:**
- **Nova tentativa.** Salvamento que falha (422, 429, 419 ou rede) é reagendado sozinho depois de 2 s, 5 s e 15 s (`esperaDaNovaTentativa`).
  - Esgotadas as tentativas, a faixa vermelha diz "As últimas alterações não foram salvas: {mensagem} Elas continuam na tela; edite de novo para tentar outra vez."
  - Edição nova ganha as tentativas de novo.
  - Um salvamento que passa limpa a falha e a faixa que ela abriu.
- **Erro de fundo.** O salvamento de fundo (`chamar(…, { fundo: true })`) não limpa nem acende a faixa a cada tentativa. Isso resolve de passagem a parte "não limpar `erro` em salvamento de fundo" do WR-F10.
- **Indicador.** `pub.salvamento.estado` vem de `estadoDoSalvamento`, uma função pura em `derivados.js`. A barra só mostra "✓ Salvo há…" no estado `salvo`, isto é, sem nada por salvar. Os outros estados:

  | Estado | O que a barra mostra |
  |---|---|
  | pendente ou em voo | "Salvando…" |
  | tentando | "Não salvo — tentando de novo" (âmbar) |
  | falhou | "Não salvo — {mensagem}" (vermelho, com `title`) |
  | pausado | "Salva quando a IA terminar" |

- **Saída da página:**
  - `beforeunload` enquanto houver pendente ou pedido em voo.
  - Na navegação do Inertia, `router.on('before')` cancela a visita, espera `descarregar()` e refaz a visita. Se o salvamento não passar, pergunta com `window.confirm`.
  - Pré-carregamento (`visit.prefetch`) e recarga parcial da própria página (`only`, `except` e `reset`, como o `router.reload({ only: ['produtos'] })` do `onPublicou`) passam direto.
  - Conferi no `@inertiajs/core` 2.3.21: retornar `false` no `before` cancela a visita, e o prefetch também dispara `before`.

**Testes que provam:**
- `WR-F02 esperaDaNovaTentativa…`
- `WR-F02 estadoDoSalvamento…`
- **Gate do hook:**
  - `fundo: true`, `salvamentoFalhou` e `salvamentoEmDia` por tipo;
  - backoff com `setTimeout` e `desistiu: true`;
  - `beforeunload` adicionado e removido;
  - `router.on('before'`;
  - o filtro `prefetch`/`only`;
  - `window.confirm(CONFIRMA_SAIR)`.
- **Gate da barra:** "Salvo há" só no estado `salvo` e os dois textos "Não salvo".

### WR-F04: a faixa da IA lia o contrato errado (sempre "0 seções") e escondia aviso e erro
**Commit:** `c4146ea3`. **Status: fixed.**
**Arquivos:**
- `resources/js/Components/Publicador/derivados.js`
- `resources/js/Components/Publicador/useIaDoPublicador.js`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx`
- `tests/js/publicador-editor.test.js`

**O que mudou:**
- `conclusaoDaIa(resumo, { pediuSubstituir })` devolve:
  - `secoes` como número (a lista antiga conta 0);
  - o `aviso` do servidor;
  - `soPreencheuOVazio`: pediu "Substituir" mas `sobrescreveu === false`;
  - `semVariacoes`.
- O `useIaDoPublicador` guarda `pediuSubstituir` do último disparo. Ele não sobrevive ao F5: depois de um F5 a linha "só o vazio" não aparece, mas o resto aparece.
- **Faixa de conclusão:**
  - "A IA preencheu N seção(ões). Revise antes de conferir no Mercado Livre.", ou "A IA não preencheu nenhuma seção.";
  - o aviso do servidor;
  - "Como houve edição durante a geração, a IA só preencheu o que estava vazio.";
  - a nota de variações.
- **Faixa de erro:**
  - "A IA não conseguiu preparar este anúncio.";
  - a mensagem do servidor, quando houver;
  - "Se ela chegou a preencher algo, já está nos cards. Tente de novo ou preencha à mão."

  O "Nada foi alterado" fixo saiu. Com o CR-F02, os cards já estão relidos quando a faixa aparece.
- `estadoDaIa` não inventa mais a mensagem genérica quando o servidor não manda erro. Ela vinha duplicada com o texto da página.

**Testes que provam:**
- O teste antigo (`publicador-editor.test.js`, antigas linhas 154-156) passa a usar o contrato real, `secoes: 3`, e confere `c.secoes === 3`.
- `WR-F04 conclusaoDaIa…`: substituir com ou sem edição, a IA que parou e o formato antigo em lista.
- `WR-F04 Editor…`: não sobrou `secoes?.length` nem "Nada foi alterado", e há `ia.erro` e `pediuSubstituir`.
- O gate antigo do Editor foi ajustado ao texto novo da faixa de erro.

### WR-F07: conferência do ML bloqueada por regra local virava "O Mercado Livre apontou 0 pendência(s)"
**Commit:** `e153f861`. **Status: fixed.**
**Arquivos:**
- `resources/js/Components/Publicador/derivados.js`
- `resources/js/Components/Publicador/usePublicador.js`
- `resources/js/Components/Publicador/Mesa/LateralValidacao.jsx`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx`
- `tests/js/publicador-editor.test.js`

**O que mudou:**
- `pendenciasDaConferencia(conf)` vale para a conferência que ainda vale e não é local. Ela traz TODOS os `issues`, L2 e L3, separados em `bloqueios` e `avisos`, e diz se vieram todos do ML (`…DoMl`).
- No hook, essas pendências entram em `todos` por `semRepetir`, sem duplicar os locais. Assim a seção não diz "pronta" ao lado de um BLOQUEADO.
- `nPendencias` conta só os bloqueios da conferência. Antes somava também os problemas da publicação.
- A lateral lista os bloqueios (`pub.conferencia.bloqueios`) e os avisos (`pub.conferencia.listaDeAvisos`).
- **Textos:**
  - "A conferência apontou N pendência(s)" quando nem todas são L3. "O Mercado Livre apontou…" fica só quando são.
  - "Conferido, com avisos da conferência" quando os avisos não são do ML.
  - O ciente passou a "Li os avisos da conferência e quero publicar assim mesmo."
  - O `textoDaConferencia` ganhou o 4º parâmetro, `doMl`, com padrão `false`: esquecer o parâmetro dá o texto neutro, nunca o que atribui ao ML.
- **Faixa `tokenExpirado`** (pedido do orquestrador, por conta do WR-B04): "…antes de conferir no Mercado Livre ou publicar."
- **CR-B01:** `LateralResumo` já mostrava `publicacao.motivo` quando a publicação falha. Agora um gate trava isso. O `motivo` vem de `conta_snapshot.motivo`, que o `PublicacaoService` grava ao falhar.

**Testes que provam:**
- `WR-F07 bloqueio da ficha achado na conferência do ML não vira "o Mercado Livre apontou"`: L2 só, L3 só, misturado, avisos L2, conferência que não vale e conferência local.
- `WR-F07 semRepetir…`
- `WR-F07 usePublicador e lateral…`:
  - não sobrou filtro `camada === 'L3'`;
  - o texto é decidido pela origem;
  - a lateral lista `bloqueiosConf`;
  - não sobrou "Li os avisos do Mercado Livre";
  - o gate do `publicacao.motivo` (CR-B01).
- A tabela antiga de textos passa `doMl = true` nos casos do ML.
- Os gates antigos da lateral (o ciente e `avisosConf`) e do Editor (o texto do token) foram ajustados.

## Contratos do backend conferidos

| Contrato | Situação no front |
|---|---|
| WR-B02 | Usado em CR-F02 e WR-F04: `secoes` inteiro, `aviso` novo e `sobrescreveu` final. |
| WR-B04 | Texto do token ajustado. Foto sem token voltando 200 com a foto pendente não pede mudança: a mesa já mostra "guardada" em conta não liberada. |
| CR-B01 | `motivo` mostrado na lateral, com gate. |
| WR-B01 | Os nomes das props não mudaram e nada foi tocado. Os campos novos da tela B (`conta_nome`, `conta_diferente`, `liberada` por produto) continuam sem uso, porque estão fora do escopo escolhido. |

## O que só o navegador prova (verificação humana)

Nada disto foi visto no navegador. Não subi servidor, como pedido.

1. **CR-F01:**
   - Digitar o título do Clássico e, em menos de 1 s, clicar ◀ numa foto (ou "tornar capa", ou a lixeira). O título continua na tela e, depois de recarregar a página, continua no banco.
   - Escolher 6 fotos e escrever a descrição enquanto sobem. A descrição não volta.
   - Trocar a categoria e, enquanto ela carrega, editar uma característica. O valor editado fica.
2. **CR-F02:**
   - "Anunciar por IA": durante a geração a mesa fica cinza ou só leitura e a faixa azul dá o aviso.
   - Ao terminar, os cards mostram o que a IA gravou (características, títulos e descrição), sem o autosave apagar nada. Conferir pela releitura do produto (F5) que o banco tem o mesmo.
   - Repetir com "Substituir com a IA".
   - Reenviar descrição e o "Tentar de novo" da abertura recarregam sem perder edição.
3. **WR-F02:**
   - Com o PHP parado ou o servidor respondendo 500 no `PUT …/salvar`, editar um campo. A barra mostra "Não salvo — tentando de novo"; depois de cerca de 22 s, "Não salvo — {mensagem}" e a faixa vermelha. Voltando o servidor, uma edição nova salva e a barra volta a "Salvo há 0s".
   - F5 com edição pendente: o navegador pergunta.
   - Clicar "Voltar aos produtos", a trilha ou outro produto da faixa com salvamento falhando: aparece o `confirm`. Com salvamento OK: navega sem perguntar.
   - O "Ver todos" e os links com prefetch, se houver, não navegam sozinhos.
4. **WR-F04:** com o job real da IA (não chamado aqui), a faixa diz o número certo de seções e mostra o `aviso`. Com erro, mostra a mensagem do servidor.
5. **WR-F07:** em conta liberada (a #459, sem publicar), provocar um bloqueio L2 que só aparece na conferência (condicional). A lateral diz "A conferência apontou N pendência(s)" e lista o bloqueio, e a seção correspondente deixa de dizer pronta.
6. **Geral:**
   - Abrir e trocar de produto pela faixa várias vezes. Sem 409/422 inesperado, sem estado de um produto aparecendo no outro (IN-F07 continua valendo: o hook supõe remontagem).
   - Na aba Network, a ordem dos pedidos é um de cada vez, e o PUT `salvar` leva só as chaves editadas.

## Fora do escopo (continuam no review)

- **WR-F01:** a fila resolve a ordem no servidor (b) e na prática também (a), porque não há mais dois PUTs em paralelo. Ficou de fora aplicar a resposta pela `revisao`, assim como o polling que ignora a ordem.
- **WR-F10:** só a parte "não limpar `erro` no salvamento de fundo" foi coberta (pelo WR-F02). Ainda faltam acumular as recusas de foto e desfazer a ordem otimista quando a gravação falha.
- **Sem nenhuma mudança:**
  - WR-F03, WR-F05, WR-F06, WR-F08, WR-F09, WR-F11 a WR-F14;
  - IN-F01 a IN-F11.

  O IN-F02 não foi resolvido: `reler` ainda zera o acompanhamento da conferência, como antes.

---

_Corrigido em: 2026-10-02T23:35:00Z_
_Corretor: Claude (gsd-code-fixer)_
_Iteração: 1 (frontend)_
