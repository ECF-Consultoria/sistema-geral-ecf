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

- **Deploy com fila: `queue:restart`, não só o `deploy.sh`** (03/10). O `deploy.sh` reinicia apenas `ecf-worker:*`; a conferência e a publicação do Publicador rodam na fila `high` (`ecf-worker-high`) e o Creative Engine na `creative` (`ecf-worker-creative`, 3 processos). Depois do deploy, `sudo -u www-data php artisan queue:restart`: todo worker termina o job em andamento e volta com o código novo. `supervisorctl restart` mataria uma geração de criativo paga no meio. Conferir pelo uptime em `supervisorctl status`.

## 10. Melhoria de 03/10/2026 (`melhoria_publicador.docx`, 6 itens)

- **Frete grátis obrigatório: quem decide é `free_shipping_by_meli`, não o
  `discount.type`.** Nas respostas reais da sondagem (`conta/shipping_options_free_*`)
  o `discount.type` vem `mandatory` em TODAS as faixas — R$ 50, R$ 78,99, R$ 79 e
  R$ 150. A diferença é que abaixo da faixa vem `free_shipping_by_meli: true` (o ML
  banca) e a partir dela o campo some (o vendedor paga). Regra no código:
  `type = mandatory` **e** sem `free_shipping_by_meli` = obrigatório para o vendedor
  (`EditorRascunhoService::freteGratis`). O resumo da H-10 em `12-hipoteses` ("a R$ 79
  vira mandatory") lê isso errado — não "corrija" a regra por ele. O limite nunca vai
  para o código (RN-83). A flag de frete grátis é do rascunho inteiro, então a regra
  consulta a variação mais barata e a mais cara de cada tipo: obrigatório só quando
  até a mais barata cai na faixa; só a mais cara = `parcial` (o ML liga nela, aviso 350).
- **O aviso 4053 `shipping.lost_me1_by_user` é ruído e sai da tela** (`MapeadorErrosMl::ehRuido`):
  vem em toda conferência desta conta (N-16). Só some quando chega como `warning`; se o
  ML voltar a mandar como `error` (bloqueava em 10/07), ele aparece. A resposta crua
  continua guardada em `pub_validacoes.respostas_ml`; conferência gravada antes do
  filtro é limpa na leitura (`estado()`).
- **IA do Modelo/título: o Job NÃO grava no rascunho.** `GerarPalavrasChaveIaJob`
  deixa o resultado no cache por pedido (`publicador:palavras:{rascunho}:{alvo}`) e a
  TELA aplica pelo caminho normal de edição. Assim não existe uma segunda escrita
  concorrente no rascunho (as travas do item 9) e o resultado de um pedido velho não
  pisa no novo (`pedido` comparado dos dois lados). O pedido automático (ao escolher
  categoria) só aplica se o Modelo continuar vazio na hora em que a IA termina.
- **Preço "do Portal" é MOSTRADO, não gravado.** O campo exibe o efetivo da
  Precificação como valor (selo "do Portal"); sair do campo com o mesmo valor não grava
  nada — senão o preço congelaria (`16` §1.6). Só valor diferente vira digitado.
- **SELLER_PACKAGE_* só aceitam `g` e `cm`** nas 4 categorias da sondagem. A tela
  oferece kg/g e cm/mm/m e grava convertido (g inteiro, cm com 1 casa). Categoria cuja
  unidade do ML não seja g/cm cai no campo genérico do schema.
- **EAN-13 automático é do FRONT, uma vez por variação** (`CardVariacoes`, ref
  `gerados`): apagar o código à mão não o faz voltar sozinho; o botão ao lado gera outro.
  Mesmo algoritmo do assistente antigo e do `RascunhoAnuncioIaService` (prefixo 789).
- **Variações e fotos juntas, como no ML (03/10, pedido depois do docx).** Não existe mais o
  card Fotos: cada cartão de variação mostra o bloco do GRUPO de fotos dela (`grupos_imagem`).
  Sem eixo que defina a foto, a tela liga `fotos_por_variante` sozinha — senão todas cairiam na
  galeria geral. A 1ª "Nova variação" são DOIS `PUT /eixos`: primeiro a que já existe ganha o
  valor (o `RegeneradorVariantes` passa os dados do `__single__` para ela, como ancestral) e só
  depois entra a nova; mandar os dois valores de uma vez copiaria SKU/GTIN/preço do produto para
  as DUAS. Tirar com um eixo = tirar o valor (vira órfã com os dados; "trazer de volta" readiciona
  e o servidor reaproveita a chave); com mais de um eixo o servidor gera o produto cartesiano, então
  a tela desativa as combinações que nasceram junto e não foram pedidas.
- **Editor passo a passo (03/10, redesenho pelo Fable com a skill frontend-design).** 7 etapas (`ETAPAS` e
  `ETAPA_DA_SECAO` em `apoio.js`, que substituiu `CARD_DA_SECAO`); os 7 painéis ficam MONTADOS e só o atual
  aparece (`hidden`) — de propósito: preserva "Nova variação" pela metade, o EAN gerado uma vez e o que foi
  digitado. Etapa em `?etapa=` (`history.replaceState(window.history.state, …)`, sem mexer no estado do Inertia)
  + sessionStorage por produto. Sticky dentro do `<main p-6>` do AppLayout: a barra usa `-top-6` e o trilho
  `sm:top-8`; o painel compensa com `scroll-mt-[152px]`. Regra visual: um só amarelo sólido por tela, e é o
  próximo passo (`publicarEhOProximoPasso`); o gradiente amarelo mora só em `Mesa/botoes.jsx`.
- **Editor em 3 colunas (Conceito E do Stitch, 04/10 — substituiu o passo a passo e a "mesa de resumo").**
  O cliente recusou, em ordem: formulário contínuo com tudo aberto, passo a passo (Voltar/Continuar) e blocos
  fechados com resumo. Escolheu no Stitch (projeto `15646202289570387715`, 6 conceitos A–F, imagens em
  `C:/tmp/ecf-publicador-melhoria-visual/stitch/`) a árvore à esquerda + item selecionado no centro + Inspetor
  à direita. Itens em `ITENS` (`apoio.js`); subitem = `raiz/sub` (`ficha/obrigatorios`, `variacoes/<chave>`),
  na URL como `?item=`. O centro monta UM item por vez, então os efeitos do anúncio inteiro (EAN automático,
  "fotos por variação", regra do frete) saíram dos cards para hooks que a PÁGINA chama sempre
  (`useEfeitosDasVariacoes`, `useEfeitosDoEnvio`) — efeito de card desmontado não roda.
  Duas armadilhas já pagas: (1) `ITENS.variacoes` NÃO pode listar a seção `fotos` — o `find` pega o 1º item
  da seção e a pendência da galeria geral ia para Variações; a foto de uma variação chega ao subitem dela por
  `problemasDaVariante`. (2) Subitem da árvore conta pela MESMA régua do pai (bloqueios do servidor,
  `problemasDoGrupoDaFicha`), nunca por campo vazio — senão fica âmbar com o pai verde.

## 11. Análise da equipe de 04/10/2026 (cor, fotos gerais, medidas, envio)

- **"Cor principal" (MAIN_COLOR) NÃO aceita nome próprio: não "conserte" isso.** Nas respostas reais
  (`technical_specs_input` da furadeira) ela vem com `allow_custom_value: false` e o ML devolve 3510
  para valor fora da lista (H-07). Quem aceita nome livre é a "Cor" (COLOR, `allow_custom_value: true`),
  que é o nome que o comprador vê. O pedido "escolher da lista OU digitar" foi atendido como o ML faz no
  componente COLOR_INPUT: o nome é livre e a "Cor principal" (o tom dos filtros) fica AO LADO dele e se
  preenche sozinha pelo nome (`tomDaCor`, `origem: 'auto'`). Escolha da pessoa (`user`) ou da IA nunca é
  trocada. Onde ela aparece depende de onde está a Cor (`ondeFicaOTom`): no cartão da variação quando
  as variações são por Cor; na ficha, ao lado da Cor, quando a Cor é do produto.
- **Desmarcar "Usar estas fotos em todas as variações" (`incluir_geral`) tira as fotos gerais de TODOS os
  anúncios.** Com `fotos_por_variante` ligado (a tela liga sozinha), toda variação tem grupo próprio, e o
  `ResolvedorGruposImagem` só junta a galeria geral se `incluirGeral`. A foto continua guardada (vira o
  aviso V-IMG-11). Por isso a tela avisa quando está desmarcada.
- **As medidas do produto e as do pacote tinham o MESMO rótulo.** Na furadeira (MLB189007) o ML manda
  HEIGHT/WIDTH/LENGTH/WEIGHT (`hidden` → seção AVANCADO → "Mais características") com os nomes "Altura",
  "Largura"…, iguais aos do pacote no Envio. Hoje elas saem da grade para "Medidas e peso", renomeadas
  "… do produto" (`MEDIDAS_DO_PRODUTO`), ao lado do pacote fechado (SELLER_PACKAGE_*). O pacote aparece
  em Detalhes E no Envio: é o mesmo atributo do rascunho, então mudar num muda no outro. O vermelho do
  pacote só aparece no Envio (`comErro={false}` em Detalhes): é a etapa cujo "Continuar" o confere.
  `conferirPacote` avisa (sem bloquear) pacote menor/igual ao produto, comparando da maior para a menor medida.
- **Dois efeitos que gravam `atributos` da mesma variação no mesmo ciclo se apagam.** `mudarVar(chave, patch)`
  troca `atributos` inteiro, e o `v` do render é velho para o segundo efeito: o EAN automático sumia
  quando o tom automático gravava depois. Use a forma função, `mudarVar(chave, (atual) => ({ atributos: {
  ...atual.atributos, X } }))`, que lê servidor + pendente de agora.
- **"Conferir" aplica antes a regra do frete grátis** (`garantirFreteObrigatorio`): quem clicava logo
  depois de mudar o preço mandava a conferência antes de a consulta da tela (1,5 s de espera) voltar. O
  aviso 350 da conferência continua caindo em Envio.
- **"Envio próprio" (custom) não leva a tabela de custos**: o payload manda só `mode`, `free_shipping`,
  `local_pick_up` e `logistic_type` — sem `shipping.costs`. A explicação na tela diz isso; preencher a
  tabela é trabalho novo, se a equipe pedir.

## 12. Alavancas no Publicador (Fase 166, 05/10/2026)

Promoções, cupons, publicidade (só leitura) e atacado % B2B na tela da empresa, `Mlb/Publicador/Alavancas`. O que
não se deduz do código:

**Travas e escrita**
- Duas travas INDEPENDENTES. `publicador.alavancas.contas_liberadas` NÃO cai na lista da publicação (D-03): liberar uma
  não libera a outra, e a tela mostra "Publicação ainda não liberada" ao lado de Alavancas liberadas sem ser defeito.
  O default 459 está no código; **conferir o `.env` de produção antes do deploy** (`PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=459`
  e nada mais).
- TODA escrita passa pelo `EscritorAlavancas` (um teste de fonte varre a pasta e proíbe `Http::` e método não-GET fora
  dele). A consulta de recomendações do PxQ é POST na conta do cliente: vai por `consultaPorPost`, sob a mesma trava
  (mesmo critério do D26 da 164 para o `/items/validate`).
- A linha do histórico nasce PENDENTE com `enviado_em` gravado ANTES do HTTP. Reentrega do job com `enviado_em` preenchido
  vira INCERTO e NÃO reenvia; 5xx e falha de rede também são INCERTO, nunca se repete. 423 repete até 3 envios. O histórico
  por empresa filtra pelas âncoras do resolver (`daEmpresa`): é o que impede IDOR por id de linha ou de lote.
- Prévia assinada (HMAC) + uso único (`Cache::add`, 10 min): a trava é avaliada ANTES da assinatura; conta não liberada
  devolve `liberada:false`, `assinatura:null` e na tela os botões de escrita nem ligam (não dá para abrir a janela).

**Regras do ML que a tela segue**
- O `offer_id` vem SEMPRE da leitura do servidor, nunca do navegador. SMART e PRICE_MATCHING sem `CANDIDATE-` ficam só
  leitura ("aceite no Mercado Livre"); DOD e LIGHTNING ativos não saem; MARKETPLACE e VOLUME não mudam preço (tirar,
  mudar o preço do anúncio fora, reinscrever).
- Cupom sem produtos não vale para nenhuma venda (achado 3 do RESEARCH); orçamento do cupom só sobe; o código enviado
  tem no máximo 10 caracteres (a doc corrigiu o 15 que o plano supunha).
- **Armadilha 1: aumentar o preço de um anúncio derruba o PRICE_DISCOUNT e tira o item de cofinanciada/VOLUME.** Hoje nenhum
  código do projeto grava preço em anúncio existente; quem um dia gravar preço pelo Publicador precisa consultar
  `GET /seller-promotions/items/{id}` antes.
- Atacado: só % B2B, corpo aninhado (`conditions.context_restrictions`, `min_purchase_unit`, `eligible: true`); a `X-Version`
  é relida no preparo, imediatamente antes do POST; 409 nunca se repete; `remove-absolute-pxq` só com confirmação. O PxQ
  absoluto morre em 27/10/2026. A faixa mantida vai só com `{"id": "N"}`.
- Publicidade: `ads/search` foi removido em 30/05/2026 (Ad Groups no lugar) e os legados de Product Ads dão 404 desde
  27/05/2026. `MercadoLivreAdsService::listAds` (Sugadores) usa um deles: achado lateral, fora da fase, ainda por corrigir.
- "Quanto a loja recebe" em cofinanciada e boost é ESTIMATIVA (A3) até comparar com o Seller Center; `MlbEmpresa` sem
  `Company` nunca tem margem (o custo mora na Precificação do Portal).

**Teste e conferência**
- `Http::fake` das Alavancas casa por MÉTODO dentro de UMA closure (a mesma URL é GET, POST e DELETE); fake acumulado e o
  1º stub vence (§5). As fixtures do ML têm prazos ABSOLUTOS de out/2026: o relógio dos testes é parado em 2026-10-04 12:00
  (America/Sao_Paulo) por `setUpCenarioAlavancas()` da trait `CenarioAlavancas` (`470399a9`) — na virada de 04 para
  05/10 o `PanoramaTest` quebrou sem mudança de código. Teste novo com data usa a trait ou para o relógio.
- O aviso de análise limitada vem do servidor em `resumo.avisos` com o teto real (`itens_analise_previa`); a tela não
  repete a frase com número fixo (`d0a3269e`).
- Conferência visual sem custo e sem ML real: `PUBLICADOR_ML_API_BASE=http://127.0.0.1:8167` aponta o cliente para um
  servidor de mentira (`php -S` com roteador que responde pelas fixtures), e só vale fora de produção (o
  `ClienteMlPublicador` força o host oficial com `app()->isProduction()`). Mais o recheio do §7: SQLite em arquivo,
  `CACHE_STORE=file`, `Cache::put('ml_app_token_coleta', ...)` para o token de aplicação e `ASSET_URL` vazio. A tarifa da
  fixture `listing_prices` é de outro país e dá "recebe" negativo: o servidor de mentira calcula a tarifa pelo preço.
  `artisan db:table` quebra no PHP local sem `intl`: use `information_schema`.
- Imagem de produto quebrada nas capturas é esperada (as fixtures apontam para o CDN do ML e o Chrome headless não sai).
- Com `CACHE_STORE=file`, a tarifa e o frete lidos do servidor de mentira ficam no cache em arquivo do WORKTREE
  (`storage/framework/cache`), e não somem com o SQLite novo. Trocou a resposta do servidor de mentira? Rode
  `php artisan cache:clear` com as MESMAS variáveis de ambiente antes de capturar — senão a tela mostra o número velho.
  Na conferência de 05/10 isso apareceu como "recebe −R$ 1.900" num produto de R$ 100 e parecia bug de cálculo.
- A conferência de 05/10 achou 4 defeitos que os testes de fonte não pegavam (janela com "recebe —" por ler o campo
  errado do contrato da prévia; "100 → 85 (29%)" sem dizer que o % é sobre o `original_price`; conta não liberada sem
  conseguir analisar convites; motivo repetido na faixa). Gate de fonte não substitui olhar a tela com dados.

**Suposições do RESEARCH, estado em 05/10/2026** (o que os SUMMARY conferiram; o resto fica para a prova real na #459)
- Conferidas na doc por curl: forma do POST/DELETE do desconto individual, `exclusion-list` (a leitura devolve
  `{"excluded": ...}` e o POST usa `exclusion_status`), `version` na RAIZ do `GET /items/{id}/prices?display_version=true`,
  bonificações sem cabeçalho de versão, subtipos do leve mais, pague menos (BNGM/BNSP/SPONTH).
- NÃO conferidas (só `Http::fake`): A1 (as faixas e a `version` virem juntas no mesmo GET com `show-all-prices`; senão a
  tela mostra ALAV-B2B-09), a forma do PxQ absoluto em `prices[]` (a detecção é por `min_purchase_unit` > 1 sem
  `percentage`), A3 (estimativa de cofinanciada contra o Seller Center), A9 (convite "aberto") e a Questão 1 (forma de
  `benefits` nos candidatos). A permissão "Promoções" do app no DevCenter também só se prova em produção (Questão 5).

## 13. Creative Engine no Publicador (Fase 165, 05/10/2026)

Nota de numeração: o `165-08-PLAN.md` (escrito em 04/10) pedia esta seção como "## 11." — mas duas outras seções
(11 "Análise da equipe" e 12 "Alavancas", Fase 166) já ocupavam esse número quando a fase 165 terminou de executar,
em 05/10. Mesmo padrão do item abaixo sobre planos que envelhecem: o número certo, no momento de escrever, é o
próximo disponível (13), não o que o plano previa.

**Escopo e token**
- O kit do Publicador é achado pela cadeia criativo → kit → portador, via `pubRascunhoIdEfetivo()` (resolve
  próprio → kit → portador). Os 7 slots do kit NÃO têm as colunas `pub_*`: o `PlanejarKitCriativosJob` (herdado,
  não tocado por esta fase) não as copia para o slot, e não precisa — só o kit e o criativo-portador carregam
  `pub_rascunho_id`/`pub_grupo`.
- Nenhum token de 32 caracteres do Publicador sai do servidor; o kit é endereçado pelo `id` numérico, escopado por
  `pub_rascunho_id` em toda rota nova. Motivo, nos dois sentidos: as rotas antigas `criativo.*` nunca conferem
  escopo com `rascunho_id` NULL — `kit.status` antigo devolve os tokens do kit/slots/portador de um kit do
  Publicador sem recusar (provado em `RotasAntigasComKitDoPublicadorTest`), e o `planejar` antigo faz
  `where('rascunho_id', $criativo->rascunho_id)` (nunca `whereNull`), então um kit do Publicador (`rascunho_id`
  sempre NULL) jamais é achado por ali — mas um criativo ANTIGO cujo rascunho foi apagado também cai em
  `rascunho_id IS NULL` e por essa mesma lógica poderia colidir. Estado da guarda nas rotas antigas em 05/10:
  **ainda não existe** — o `abort_if` de 1 linha proposto no checkpoint do 165-01 não foi acrescentado (fora do
  `files_modified` de todos os 8 planos; ninguém tocou `MlbAnuncioController.php`). O risco fica aceito e
  registrado (T-165-20); o teste que prova isso (`RotasAntigasComKitDoPublicadorTest`, caso 2) sai
  **"incomplete" de propósito**, nunca "verde por acidente" — e se liga sozinho no dia em que a guarda chegar.
  Não expor token em tela nova enquanto essa guarda não existir.
- "Aprovado" no Publicador = foto está em `pub_imagens` (`pub_imagem_id` preenchido no slot), com
  `ml_picture_id`/`ml_picture_url` do slot **sempre nulos** (D-11) — a classe de aprovação nunca referencia esses
  dois campos nem o serviço de upload direto do assistente antigo. Em conta liberada, o arquivo sobe ao Mercado
  Livre NA HORA (`ImagemAssetService::receber()`, fora de transação), como qualquer foto do Publicador; ele só
  entra DENTRO de um anúncio na publicação, nunca na aprovação.

**Status calculado, relógio da tentativa, regenerar**
- `MlAnuncioCriativoKit::recalcularStatus()` (método herdado, não tocado) vira `gerando` quando há um slot
  `aprovado` misturado com `pronto`/`pendente` — cai no `default` do `match` interno dele. A tela do Publicador
  não sofre com isso: ela lê `PublicadorCriativoKitPresenter::statusEfetivo()`, que segue 8 passos fechados
  (aprovado → planejando → sem slot → planejado-com-tudo-pendente → gerando → erro-total → parcial → pronto) e só
  cai para o status gravado quando nenhum slot está aprovado — um kit `gerando` com um aprovado no meio aparece
  como `pronto` na tela, sem precisar corrigir (ou sequer chamar) `recalcularStatus()`.
- O motor mede o tempo-limite pelo `created_at` (slot 12 min, kit 25 min) e encerra o item "travado" antes de
  gerar de novo. No Publicador, o `created_at` do kit e dos slots recém-despachados é **regravado a cada
  tentativa** (`reiniciarRelogioDaTentativa()`, chamado antes de despachar o job de gerar/regenerar) — sem isso,
  um kit retomado horas depois do planejamento original seria encerrado como travado na hora de gerar. O início
  REAL da tentativa fica em `started_at`: qualquer leitura futura que precise saber "quando o kit nasceu" (um
  relatório, uma auditoria) tem que ler `started_at`, não `created_at`. No assistente antigo esse problema de
  leitura continua existindo — a fase 165 só resolveu o caso do Publicador, que é o próprio que regrava o relógio.
- Regenerar um slot recusa ANTES de subir o contador `regeneracoes` quando o kit está fechado ou sem referência
  efêmera viva — a ordem importa: se o job rodasse primeiro e falhasse depois, a tentativa já teria sido "gasta"
  sem produzir nada. O controller do Publicador confere essas duas condições antes de despachar.

**Referência efêmera, comparação de grupo, FK em teste**
- A referência efêmera do Publicador nasce a partir de `pub_imagens` (fotos do próprio rascunho, não upload novo):
  em modo de teste, um `UploadedFile` é construído sobre `Storage::disk('local')->path()` do arquivo já salvo, e
  há exatamente UMA chamada a `ReferenciaEfemeraService::guardar()` por lote — os índices das referências dentro
  do portador são por CHAMADA (0-based), não por foto; chamar `guardar()` duas vezes para duas fotos criaria dois
  portadores com índice 0 cada, não um portador com índices 0 e 1.
- `pub_grupo` é comparado em **PHP** (`===`) nos helpers de retomada (`retomavelDoPublicador`/
  `ultimoAprovadoDoPublicador`), nunca só num `where()` do SQL — a collation `_ci` (case-insensitive) do MariaDB
  casaria "Preto" com "preto" como o mesmo grupo, o que SQL puro não evitaria. E `pub_grupo` (varchar 600) não tem
  índice, de propósito: a consulta de retomada filtra primeiro por `pub_rascunho_id` (indexado) e só depois
  compara o grupo em PHP sobre o resultado já filtrado — um índice numa coluna de 600 bytes estouraria o limite de
  chave do InnoDB em `utf8mb4`.
- No SQLite dos testes as FKs ficam ligadas dentro da transação do `RefreshDatabase`: apagar o `pub_rascunho`
  zera `pub_rascunho_id` do criativo via `nullOnDelete` e ele cai de volta no caminho ANTIGO, com a mensagem
  antiga — isso é o comportamento esperado para um rascunho DE FATO apagado. A mensagem do ramo NOVO (criativo
  órfão por corrida, id apontando para um rascunho que nunca existiu ou que some no meio da requisição) só
  aparece testada com `PRAGMA defer_foreign_keys = ON` dentro do teste — `PRAGMA foreign_keys = OFF` sozinho é
  **ignorado** pelo SQLite dentro de uma transação já aberta (a do `RefreshDatabase`); só o `defer_foreign_keys`
  consegue simular a janela de corrida sem desligar a integridade de verdade.
- Migration aditiva com FK + índice explícito: no `down()`, `dropForeign()` tem que vir ANTES de `dropIndex()`,
  porque o MariaDB reaproveita o índice explícito como o índice interno da própria FK — dropar o índice primeiro
  dá 1553 ("cannot drop index needed in a foreign key constraint"). A migration `2026_10_03_090100` do Creative
  Engine (herdada, fora do `files_modified` desta fase) faz a ordem CONTRÁRIA no `down()` — risco só em rollback,
  não em uso normal; avisar antes de rodar `migrate:rollback` dela num MariaDB real.

**Conferência sem custo e deploy**
- Com `GEMINI_BASE_URL` apontando para uma porta fechada, o `CreativePlanner` cai no plano DETERMINÍSTICO (sem
  chamar a IA de texto) e o kit termina `planejado` com 7 slots — não em erro. Para ver o estado de ERRO de
  propósito, é preciso semear um kit já em `erro` (ou um kit `planejando` velho, que o `encerrarSeTravado()` fecha
  na hora). Em NENHUM cenário de conferência local clicar "Gerar agora" com `QUEUE_CONNECTION=sync`: os 7
  `GerarCriativoIaJob` rodariam dentro da própria requisição HTTP, chamando o provedor de imagem de verdade.
- Apagar `Company`/`MlbEmpresa` apaga os criativos pela CASCATA do Creative Engine (herdada), enquanto o
  `pub_produto` correspondente fica (SET NULL nas âncoras dele, D27 do item 9 acima) — ou seja, depois de apagar a
  empresa, o produto do Publicador sobrevive mas os criativos relacionados a ele não.
- Deploy desta fase: migration aditiva (`php artisan migrate --force`) + `sudo -u www-data php artisan
  queue:restart` (cobre a fila `creative`, 3 processos) — nunca `supervisorctl restart`, que mataria uma geração
  de imagem PAGA no meio. A ponte "Gerar criativos no assistente antigo" na tela de produtos continua ativa até o
  Publicador estar em uso real.

**PHP-FPM de produção tinha limite de upload menor do que a validação prometia (261005-si3, 05/10/2026)**
- A validação de `referencias.*` no `MlbPublicadorCriativoController::planejar()` promete "até 10 MB",
  mas o PHP-FPM de produção tinha `upload_max_filesize = 2M` / `post_max_size = 8M` — o PHP descartava
  o corpo inteiro ANTES de o Laravel ver qualquer coisa, e a tela não dava nenhum sinal (a validação
  normal reclamaria de `grupo` vazio, um campo que o operador nunca tocou). Subido para `12M` / `50M`
  com `sudo systemctl reload php8.2-fpm` em 05/10/2026 (o nginx já permitia `client_max_body_size 50M`,
  então só o PHP estava apertado). **Isto é config de VPS, fora do git** — como `pm.max_children` e
  `sort_buffer_size` do item de desempenho/bonificação: não há arquivo no repositório que documente ou
  reponha esse valor depois de uma reinstalação do PHP-FPM. Conferir com `php -i | grep -E
  'post_max_size|upload_max_filesize'` na VPS antes de assumir que a validação da aplicação é a única
  réstia.
- O código ganhou uma defesa companheira (não substitui conferir o `.env`/`php.ini` da VPS): o
  controller agora distingue "o PHP jogou o corpo fora por passar do limite" de "o operador mandou um
  POST vazio de verdade", comparando `Content-Length` (sobrevive no cabeçalho) com `post_max_size` do
  `ini_get()` — e devolve uma mensagem em pt-BR sobre o tamanho do arquivo em vez do 422 genérico.

**Validador da Fase 162 chegou no meio da execução, sem estar nos planos**
- Os 8 planos da 165 são de 04/10, escritos ANTES da Fase 162 (validador Gemini-como-juiz) mergear em
  `origin/main`. Três ondas diferentes (165-04, 165-05, 165-06) tiveram que acrescentar o MESMO gate por conta
  própria — nenhuma delas podia esperar a outra terminar: o presenter (`validacao_status`/`pode_aprovar`/
  `exige_confirmacao_risco` por slot), o controller (`aprovar()` recusa `pendente`, exige `confirmar_risco=true`
  para `reprovada`, com override auditado) e o painel React (dois cliques explícitos para "aprovar mesmo assim",
  nunca o mesmo botão de uma imagem aprovada). Sem isso em qualquer uma das três camadas, o Publicador teria
  aprovado em silêncio — ou falhado com um 422 sem explicação — uma imagem que o juiz já tinha reprovado. Quem
  mexer num plano desenhado antes de uma fase vizinha que ainda não tinha mergeado: ler o código real da fase
  vizinha antes de implementar, não só a prosa do plano.

**O painel só existe de verdade quando alguém o monta na página**
- As ondas 165-01 a 165-06 entregaram migration, dois serviços novos, 7+2 endpoints HTTP, o presenter, o hook
  (`useCriativosDoPublicador`) e o painel (`Mesa/PainelCriativos.jsx`) com TODAS as suítes verdes — e a tela real
  do editor continuava sem nenhum botão "Gerar com IA", porque nenhum arquivo de página importava
  `FotosPorGrupo.jsx`/`Editor.jsx` com o contexto ligado. Isso só aconteceu na onda 165-07, que montou o
  `<CriativosDoPublicador.Provider>` em `Editor.jsx` e leu o contexto dentro de `BlocoDeFotos`. Suíte 100% verde
  não significa funcionalidade visível — e `npm run build` sozinho também não prova isso (um componente que
  nenhuma página importa compila sem erro e simplesmente não entra em bundle nenhum).

**Ambiente, composer e git — custou tempo sem ser do Creative Engine**
- O `composer.lock` trazido pelo merge do `origin/main` (o MCP do outro dev, `laravel/mcp` + `laravel/passport`)
  exige **PHP ≥ 8.4.1** (`symfony/psr-http-message-bridge` resolvido em v8.1.0) — mas `composer.json` segue
  declarando `"php": "^8.2"` e esta máquina roda PHP **8.2.12**. `composer install` recusa com "lock file does not
  contain a compatible set of packages for your PHP version"; o `--ignore-platform-req=php-64bit` (que resolve o
  problema do `vendor/` destruído por junction, registrado no learnings do projeto) **não é suficiente** aqui — é
  preciso `--ignore-platform-reqs` (sem a exceção de um só requisito). Depois de instalar assim, os 36 testes do
  `tests/Feature/Mcp` passam normalmente em PHP 8.2: o lock está mais restritivo do que o código precisa de fato.
- `git commit -- <caminho>` **não pega arquivo novo (untracked)** — dá `pathspec '<caminho>' did not match any
  file(s) known to git`. É preciso `git add -- <caminho>` antes do commit. Isso importa especificamente neste
  projeto porque a árvore é compartilhada entre sessões e a regra é nunca usar `git add -A`/`git add .` — então
  todo arquivo NOVO (não só modificado) precisa do `add` explícito, um por um, antes do `commit -- `.
