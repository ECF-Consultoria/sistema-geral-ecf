# Publicador ML (Anunciar do portal) — o que não se deduz do código

Leitura recomendada antes de mexer no Publicador novo do Anunciar
(`.planning/publicador-ml-spec/`). Começado em 01/10/2026, prazo 20/10/2026.
O plano e as decisões estão em `.planning/publicador-ml-spec/16-analise-do-portal.md`.

## 1. A especificação é a fonte; o código antigo é o que se corrige

A spec (`00`–`15`) foi escrita sem olhar o código, de propósito. Quando o
código do Anunciar antigo (`EstruturaPublicacaoService`, ADR PORTAL-03) ou do
`/mlb/anuncios` contradiz uma regra [ML] ou [ARQ], o código está errado — a
lista de violações está no `16` §3. Hipótese [HIP] nunca vira regra fixa:
vai para `config/publicador.php`.

## 2. `tests/Fixtures` × `tests/fixtures` — o git grava a grafia errada no Windows

O repositório tem as duas pastas (`tests/Fixtures/ClicksignSandboxFixtures.php`
e `tests/fixtures/phase134/`). No Windows (`core.ignorecase=true`) elas são a
MESMA pasta física, e um `git add tests/fixtures/x.json` grava
`tests/Fixtures/x.json` — a primeira grafia que o índice conhece. No Linux da
VPS o arquivo fica em outra pasta e o teste que lê `tests/fixtures/...` não o
acha. Por isso as fixtures do Publicador vivem em `tests/fixtures-ml/`, pasta
sem homônima. Arquivo novo em `tests/fixtures/`: conferir com
`git ls-files --stage | grep -i <nome>` a grafia gravada.

## 3. A sondagem (Fase 0) roda em duas máquinas

`php artisan publicador:sondar` só lê e só valida (recusa `POST /items`).
- `--publico`: categorias, atributos, `technical_specs`, `sale_terms`, tarifas —
  app token, roda no local.
- `--empresa=459`: dados da conta (tags, envios, `validate`) — o token é cifrado
  com a APP_KEY de produção, então só roda lá. A conta de teste definida pelo
  usuário é a empresa **#459 "Dev 02 Testes API"**.

As fixtures vão para o git: o comando tira dado pessoal (`sanitizar()`) e nunca
grava token (só vai no cabeçalho). Não afrouxe isso.

**Como a parte da conta rodou na produção sem deploy (01/10):** `pscp` do
`PublicadorSondar.php` + um `run.php` para `/tmp/<pasta>` da VPS; o `run.php` faz
o boot do app de `/var/www/ecf_admin`, dá `require` no comando e o registra com
`$kernel->registerCommand(new PublicadorSondar())`, depois
`$kernel->call('publicador:sondar', ['--empresa' => '459', '--categorias' => '…', '--saida' => __DIR__.'/saida'])`.
Rodar como **www-data** (`chown -R www-data` na pasta + `su -s /bin/sh www-data -c 'php run.php'`):
log ou cache criado como root deixaria o PHP-FPM sem escrita. Trazer `saida/conta`
de volta com `pscp -r` e apagar a pasta da VPS. Nada em `/var/www` é tocado.

**A conta da #459 é a MGSTOREL (1555596317), loja REAL**, não usuário de teste
do ML — a mesma conta da antiga "Dev 02 Teste" (#356), que em 10/07 era clássica
e hoje é **User Products**. Anúncio criado nela é visível a compradores: título
"Item de teste - Não ofertar", fechar logo depois, e confirmação do usuário antes
de cada `POST /items`.

**Conta de cliente: nunca publicar nem mexer em anúncio** (regra do usuário, 01/10). No
máximo leitura e `validate`, e só com autorização dele para aquilo. A lista de depósitos
(`/users/{id}/stores/search`) traz **endereço e coordenadas** da loja: o `sanitizar()` passou
a remover `location`/`address_line`/`latitude`/`longitude`, e as respostas de conta de
cliente são anonimizadas (id do vendedor, apelido) antes de irem para `tests/fixtures-ml/`.

**Medido em 01/10 nas contas de cliente:** 33 de 33 com token válido são User Products (o
perfil público `GET /users/{id}` NÃO traz `tags` — só o token da conta mostra), e 23 de 33
têm `warehouse_management`. Nessas, `available_quantity` é obrigatório no corpo (sem ele, 369)
mas **ignorado** (aviso 469): o estoque vem dos depósitos.

## 3a. O `/items/validate` devolve 400 mesmo quando só há avisos

Nesta conta sempre vêm dois avisos (`4053 lost_me1_by_user`, `350
mandatory_free_shipping`), e o status é **400**, não 204. "Passou" = nenhuma
causa com `type: error`. Quem testar `status === 204` vai achar que nada nunca
passa. O `lost_me1_by_user`, que em 10/07 bloqueava, hoje é aviso.

Outra armadilha da sondagem: numa conta UP, payload com `title` (ou sem
`family_name`) para no erro de corpo (369 / `body.invalid_fields`) ANTES de
qualquer outra validação — cenário montado sobre a base errada não testa nada.

## 4. O que a API real mostrou e a documentação não dizia (01/10/2026)

Detalhes em `12-hipoteses-e-pendencias.md` §Resultado. Os que mais mudam código:
- `technical_specs/input` traz TODOS os atributos, agrupados; em `/attributes`
  todos são `attribute_group_id = OTHERS`. Tags: lista no `technical_specs`,
  objeto no `/attributes` — normalizar os dois.
- "Aceita valor livre?" = `ui_config.allow_custom_value` do componente.
- `settings.shipping_modes` não existe no MLB (vem `null`).
- `max_variations_allowed` da categoria (100) diverge do domínio (250) na camiseta.
- Garantia: `sale_terms` existe; `WARRANTY_TYPE` 2230280 vendedor / 2230279
  fábrica / 6150835 sem garantia.
- Autopeças: `GTIN` é `read_only` e `VEHICLE_TYPE` é `required` + `fixed` —
  `fixed`/`read_only` vencem `required`.

## 5. Teste com `Http::fake` que muda no meio: mude o ESTADO, não o fake

`Http::fake()` acumula, e o primeiro stub que casa vence. Chamar `Http::fake()`
de novo no meio do teste para "o ML agora responde outra coisa" não tem efeito
— e o teste pode PASSAR sem testar nada (aconteceu em 01/10 com "ML fora do ar
usa o schema guardado": verde por engano). Padrão do Publicador: registrar o
fake uma vez, com closures que leem propriedades do teste
(`$this->fontesFora`, `$this->ajusteCategoria`), e mudar a propriedade
(`tests/Feature/Publicador/CamadaMlTest.php`). Conferir com uma mutação que o
teste quebra quando a regra some.

## 6. Job em fatias: a próxima fatia é `release()`, nunca `dispatch()` de dentro do `handle`

`PublicarRascunhoJob` trabalha ~45 s e volta para a fila. Com `self::dispatch()`
dentro do `handle`, o driver `sync` (testes, máquina local sem worker) executa o
novo Job NA HORA, dentro do anterior: um item `UNKNOWN` esperando a hora de
reconciliar vira recursão sem pausa. `$this->release(15)` + `retryUntil()` faz o
mesmo no Redis de produção e não recursa no `sync`. A trava (`Cache::lock` por
publicação) cobre a fatia inteira mais um POST lento: quem não pega a trava faz
`release(20)` — e o item gravado `SENT` antes do POST garante que uma reentrega
vá para a reconciliação, nunca para um segundo POST.

## 7. Conferência visual sem tocar no MariaDB compartilhado

O MariaDB local é de todas as sessões; semear cenário nele atropela os outros.
O que funcionou (01/10, F1.11):

- `DB_CONNECTION=sqlite DB_DATABASE=<arquivo> SESSION_DRIVER=file ... php artisan migrate --force`
  (as migrations rodam em SQLite — é o que os testes usam);
- semear com um PHP avulso que dá `bootstrap()` no app e se recusa a rodar se
  `database.default` não for `sqlite`;
- sessão do portal sem passar pelo código por e-mail: gravar o arquivo em
  `storage/framework/sessions/<id>` com `serialize([guard('portal')->getName() => id, 'portal_empresa_id' => id, '_token' => …])`
  e mandar o cookie `encrypt(CookieValuePrefix::create(nome, chave).$id, false)`;
- servidor: `php -S` **de dentro de `public/`** com o `server.php` do framework —
  ele usa a pasta atual como `public`; de fora, `index.php` não é achado;
- `node_modules` do worktree: junção para outro worktree com `package-lock.json`
  idêntico (conferir com `diff` antes). O checkout principal pode estar atrás
  (em 01/10 não tinha `@dnd-kit`).

## 8. Duas pegadinhas de teste

- `Storage::assertExists($caminho, $mensagem)` NÃO aceita mensagem: o 2º
  argumento é o CONTEÚDO esperado do arquivo — o teste falha comparando bytes.
- Timeout do ML em teste: `Http::failedConnection()` (lança `ConnectionException`).
  Uma closure que lança outra exceção não fica em `Http::recorded()` — conte as
  chamadas por um contador próprio.

## 9. Publicador interno (Fase 164, 02/10/2026)

O que não se deduz do código, na ordem em que mais custou descobrir.

**Modelo**
- O rascunho é do PRODUTO (`pub_produtos`), não da oferta. O vínculo com a oferta
  mora só no produto (`pub_produtos.oferta_id`); `pub_rascunhos.oferta_id` ficou
  como coluna LEGADA DORMENTE de propósito (D27, opção (a)): não dropar, não
  recriar `pubr_oferta_*` — dá erro 1553 no MariaDB (a FK é usada por um índice).
  A oferta do rascunho vem do produto (`hasOneThrough`).
- `pubprod_oferta_fk` é SET NULL: `nullOnDelete` vale em coluna ANULÁVEL (o 1830
  do learnings de desempenho é só para coluna NOT NULL).
- Apagar a oferta pela Lista SKUs congela título/preço no rascunho
  (`SoltarProdutoDaOfertaService`, chamado em `EstruturaOfertaService::excluir`,
  que é o único caminho Eloquent). Apagar a `Company` apaga as ofertas pelo banco
  (o produto fica com `oferta_id` NULL SEM congelar título/preço — fora do D27),
  mas NÃO leva mais o `pub_produto`.
- `pubprod_empresa_fk` e `pubprod_company_fk` são SET NULL (CR-B02 do code review,
  decisão do usuário em 02/10): excluir `MlbEmpresa` (o `DELETE /mlb/empresas/{empresa}`
  é de gestor/líder de Polos, não de admin) ou `Company` mantém produto, rascunho,
  publicações e itens — `ml_item_id`, payload e resposta crua do ML. Com as duas
  âncoras nulas o produto vira órfão: some das telas (404 no editor) e `conta()`
  lança V-ACC-01. Até 02/10 eram CASCADE e apagavam esse histórico; banco onde a
  criação já rodou assim é consertado pela `2026_10_02_200000_pub_produtos_ancoras_sem_cascata`
  (rodada no MariaDB local; em produção a criação já nasce SET NULL). Não voltar a
  CASCADE: o `down()` dela não volta de propósito.
- Company 5 ≠ MlbEmpresa 5. "Empresa polo" é `MlbEmpresa`; produto de polo pode
  não ter `company_id`.

**Conta e trava**
- A conta do ML é a âncora que TEM token (`PubProduto::conta()`), e a trava D21
  olha essa âncora: `publicador.contas_liberadas` vazio = ninguém publica. O
  `config/publicador.php` ainda aceita `PUBLICADOR_EMPRESAS_PILOTO` como
  fallback de `contas_liberadas.companies` (o `.env` de produção pode ter só a
  antiga) — não remover o fallback.
- A trava vale em TODA escrita, não só no clique (CR-B01): `iniciar()` grava a conta
  fixada em `pub_publicacoes.ator.conta` (`chave` da âncora + `seller` da conferência —
  sem migration, a coluna JSON já existia) e `PublicacaoService::contaFixada()` relê a
  âncora do banco antes das fotos, de cada `POST /items` e de cada descrição: âncora
  diferente, conta fora da lista ou token de outro vendedor → nada sai. Publicação
  sem `ator.conta` (anterior a 02/10) falha FECHADO; conferência sem
  `respostas_ml.conta.sellerId` não publica — teste que fabrica L3 precisa dele.
- D26: conta NÃO liberada confere só LOCAL (camada `L2`, resultado `LOCAL`,
  `conferencia.local`), não recebe foto (a foto fica `pending`; miniatura por
  `mlb.anuncios.publicador.fotos.arquivo`) e nunca faz POST. A leitura de conta
  ao abrir e o "Quanto eu recebo?" continuam: são GET.
- Produto SEM token ativo é "não liberada" para foto e conferência (WR-B04): a foto
  fica `pending` e entra no grupo, a conferência é a local (`respostas_ml.motivo =
  V-ACC-01`; fora da lista, `CONTA-LIB`). O V-ACC-01 (reconectar) só aparece ao
  publicar. Use `contaOuNula()` em código novo que só LÊ ou decide se escreve;
  `conta()` (que lança) só onde a escrita é obrigatória.
- Rascunho tem UMA trava de linha (WR-B02): `RascunhoRepository::travar()` =
  `lockForUpdate` na linha de `pub_rascunhos`, pega por `iniciar`, pela IA (cada
  escrita dela, em `IaParaRascunhoService::sobTrava`) e pelo editor (`salvar`,
  `trocarCategoria`, `salvarEixos`, `salvarVariantes`). Escrita nova no rascunho:
  travar PRIMEIRO e ler o snapshot DEPOIS, na mesma transação; o que pode ir ao ML
  (schema) fica fora da trava. A IA grava só as chaves que preenche
  (`mesclarAtributos`, `gravarTitulos`) — nunca `gravarAtributos`/`gravarAlvos`,
  que regravam a lista inteira. No SQLite o `FOR UPDATE` não existe: os testes
  simulam a corrida com um `CategorySchemaRepository` que age no meio do `obter()`
  e um repositório que age depois do `snapshot()` (`IaParaRascunhoTest`, `wr_b02`).
- O programa (Polos/Incubadora) compara `projeto`/`fase`/`tipo` sem caixa e sem
  espaço nas pontas, IGUAL no SQL (`LOWER(TRIM(col))` no `scopePrograma`) e no PHP
  (`programaPublicador()`) — WR-B05. `projeto` é texto livre; com a comparação
  `_ci` do MariaDB de um lado e `===` do outro, "Polos" era listada e dava 404.
- D20: o passo em PRODUÇÃO é do usuário — `php artisan publicador:empresa-teste`
  (simulação) e depois `--confirmar`. Só foi construído e testado.

**O Anunciar saiu do Portal (D18, 164-15)**
- As rotas `/estrutura/anunciar*`, `…/publicacao*` e `…/publicador*` respondem 404
  para todos; saíram da allowlist de `RestringeDominioDoPortal`. O Mapeamento
  Estrutural tem 5 submódulos. Quem publica é a equipe ECF, no admin.
- O assistente antigo (`mlb.anuncios.wizard`) segue vivo SÓ para rascunhos antigos
  e "Anunciar semelhante" (D22). `EstruturaPublicacao`/`estrutura_publicacoes` e
  `MigracaoAnunciarAntigo` ficam (histórico e migração).
- `EditorRascunhoService::estado()` não devolve mais `oferta` nem `piloto`; a
  oferta é `produto.oferta_id`.

**Testes e ambiente**
- Teste de migration que usa `->change()` não roda com `RefreshDatabase`/`DatabaseMigrations`
  no SQLite: o `migrate:rollback` do teardown quebra no `down()` de
  `2026_09_14_100000_add_parent_id_to_company_groups_table` ("dropping foreign
  keys by name"). `MigracaoProdutoRascunhoTest` chama `artisan('migrate')` no
  `setUp`. O backfill só foi provado em SQLite (o MariaDB local tinha 0 linhas).
- `testing.ensure_pages_exist = true`: teste de página nova lê `viewData('page')`
  e a página React precisa existir.
- Componente que nenhuma página importa NÃO é compilado pelo `npm run build`:
  import quebrado ali só aparece quando alguém o importa — cheque com esbuild.
  Depois de apagar arquivo de front, rode o build e confira o manifest.
- `artisan route:list` trava o timeout nesta máquina com o `.env` local; com
  `DB_CONNECTION=sqlite DB_DATABASE=:memory:` no ambiente do processo e
  `timeout 120` roda. Alternativa: `Route::has()` num teste.
- O Bash tool corrompe barras invertidas em `sed` (os imports viraram
  `AppContracts...`): edite PHP/JS só com Edit/Write e confira com `php -l`.
- Teste que passa por código do ML sem `Http::fake` é INTERMITENTE
  (`Phase75/PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`
  pede app token real e já falhou com HTTP 400; isolado, passou). Rode isolado
  antes de chamar de regressão — e prefira consertar o teste com `Http::fake`.
- Suíte inteira estoura 512 MB: rode por pasta, redirecione para arquivo e leia o
  arquivo (`| tail` engole o exit code).
- `file_get_contents(UploadedFile::fake()->image(...)->getPathname())` em uma linha
  falha ("No such file"): o arquivo temporário some quando o objeto é liberado.
  Guarde o `UploadedFile` numa variável antes de ler.
