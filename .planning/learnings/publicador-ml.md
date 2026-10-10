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
- **Modelo não repete palavra de conteúdo do título (08/10, pedido do usuário).** O
  Modelo serve para EXPANDIR a busca: termo cujas palavras de conteúdo (fora de/para/
  com…) já estão todas no título é descartado — "puff sala" sai com "Puff … Sala" no
  título; "puff para quarto infantil" fica. O filtro é do SERVIDOR
  (`PalavrasChaveService::ajustarModelo` com `titulo`), não só do prompt: a IA não
  obedece sempre. O título é a união dos títulos ATIVOS gravados + o `titulo` que a
  tela manda (pode não estar salvo). Por isso o pedido automático mudou: na escolha de
  categoria só dispara se já houver título; senão dispara quando o título por IA é
  aplicado e o Modelo está vazio ou ainda com `origem: 'ia'`.
- **Modelo: fatos do produto no prompt + filtro de cor/público/tamanho no servidor (09/10).**
  "Puff Redondo" só em Azul saiu com "puff gigante, puff colorido, puff infantil, puff rosa,
  puff azul marinho": a IA só via nome, categoria e trends, e o ML diz o que é BUSCADO, não o
  que o produto É. Agora o serviço lê do rascunho (`FatosDoProduto`) as cores das variantes
  ATIVAS (eixo COLOR/MAIN_COLOR ou eixo próprio "Cor", + Cor principal da variante; sem
  variação, a cor da ficha), a ficha preenchida, as medidas e o público (AGE*/GENDER*) e manda
  como bloco "FATOS DO PRODUTO". E o servidor descarta, com vocabulário explícito, termo com
  cor fora das cores do anúncio ("azul marinho" com "Azul" é OUTRA cor; "colorido/estampado"
  só com 3+ cores ou ficha estampada; sem cor conhecida, nenhum termo com cor), público ou
  tamanho que os fatos (ficha textual + nome + categoria + título) não confirmem. Medida
  numérica NÃO confirma ("500 g" não é tamanho G). Palavra do nome do produto não conta como
  cor ("Taça Vinho"). Os descartados voltam no estado (`descartados`) e a tela diz quantos.
  O exemplo antigo do prompt ("puff para quarto infantil", "puff azul marinho") ENSINAVA o erro
  — não traga de volta exemplo com característica inventada.
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

**Prova real na #459, parte só leitura (06/10/2026, depois do deploy da 167 — sondagem pela VPS):**
- **Promoções:** `GET /seller-promotions/users/{id}` respondeu 200, então o app JÁ tem a permissão de
  Promoções (Questão 5 fechada). Convites: 0, então o A9 continua sem amostra. Exclusão do vendedor: `not_excluded`.
- **A1 em parte:** `GET /items/{id}/prices` devolve `prices` e `version` JUNTOS na mesma resposta. Mas nenhum
  anúncio tinha faixa de atacado, então a forma do PxQ em `prices[]` só aparece depois de gravar uma faixa.
- **Anúncio usado não entra em promoção:** `GET /seller-promotions/items/{id}` responde **400 "The item condition must be
  new"** para anúncio com condição usado (2 de 5 da #459). O código das Alavancas não trata essa mensagem; a
  tela mostra erro genérico. Ainda sem correção.
- **Publicidade:** `advertisers` e `ad_groups/search` respondem 200 (25 grupos) mesmo sem a permissão
  "Advertising"; `campaigns/search` responde 404 (`advertiser_campaigns_not_found`); bonificações vazias.
- **A #459 (MGSTOREL) tem 31 anúncios PAUSADOS e 0 ativos.** O desconto (PRICE_DISCOUNT) exige anúncio ativo,
  então as escritas do roteiro ficam bloqueadas até reativar um anúncio de teste com condição NOVO. Reativar é
  escrita em loja real (o anúncio volta a vender): só com decisão explícita do usuário.
- **Como ler a conta pela VPS sem sujar a árvore:** `--saida=/tmp/...` na sondagem. No `php -r`,
  `ensureValidToken()` devolve o MODEL `MlToken`; o texto está em `->access_token`. Passar o model para
  `withToken` dá 403 enganoso.

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

## 14. Sincronizar completo do Portal (Fase 176 — era 172, 08/10/2026)

O que custou descobrir e NÃO se deduz do código (o resto está nos SUMMARY da fase 176):

- **Um `pub_produtos` por produto do Portal, não por oferta.** O vínculo é `pub_produtos.estrutura_produto_id`
  (unique, FK `SET NULL`). Legado de uma cor com rascunho ainda sem publicação é ADOTADO (só o vínculo muda); legado
  publicado é intocável **por fato** (`IaParaRascunhoService::intocavel`, não por flag) e nunca é adotado nem apagado.
  Duplicados das outras cores ficam listados a cada execução, não gravados.
- **O regenerador de SKU copia o `skuExibido()` do grupo para todas as cores.** Por isso a regra "SKU igual ao de
  outra variante = vazio" (a 1ª que o tem fica): sem ela as 3 cores nascem com o mesmo SKU e a conferência trava.
- **Eixo em dois passos.** Sem eixo no rascunho, `salvarEixos` roda duas vezes (a cor âncora primeiro, depois todas),
  senão a variante única não passa os dados à âncora. Só vira COLOR/SIZE/VOLTAGE/MATERIAL/FLAVOR se o schema diz
  `podeSerEixo`; senão `~custom` com o rótulo do Portal. Nada grava MAIN_COLOR.
- **Preço por variante pelo SKU normalizado** (`precos_por_variante` em `DadosEfetivosService`): casa por
  `dados.atributos.SELLER_SKU`; sem casamento cai no preço da âncora. Produto não agrupado não recebe a chave.
- **`ImagemAssetService::receber(..., enviar: false)`** guarda a foto sem subir ao ML; o Sincronizar nunca chama
  `/items` nem sobe foto, mesmo com a conta liberada e com token (teste com `Http::preventStrayRequests`).
  WebP do Portal é convertida a JPG com GD; **confira GD com WebP no PHP de produção** antes de confiar.
- **`values_multi` (D-13) vai no snapshot e sobrevive à gravação sem a chave** (review 176 WR-03): `gravarAtributos`/
  `mesclarAtributos` sem `values_multi` mantêm a lista guardada enquanto o `value_id` (1ª opção) não muda; trocou a
  opção, a lista velha sai. O payload do ML não usa a coluna (`ValorAtributo::paraPayload` monta as chaves à mão).
- **Job por produto (`PreencherRascunhoDoPortalJob`), fila `high`, `timeout` 300 s < `retry_after`, `tries` 1.**
  Resumo agregado por `pedido` (uuid, cache 1 h) escopado por `company_id`. Com `QUEUE_CONNECTION=sync` a exceção
  sobe ao request, então `failed()` só se prova chamando-o direto.
- **Descrição MAG T8 automática: uma vez por rascunho, via `Cache::add`**, e só depois de checar rascunho sem
  descrição E texto do cliente existente (senão gasta a única chance sem material). Nunca grava no rascunho.
- **Sincronizar NÃO tem gate de piloto (D-10).** `ContasLiberadas` só governa o selo da página; o caminho do
  Sincronizar não deve citá-lo (há teste "fora do piloto também é enriquecida").
- **Vite no Windows: `ResumoDoSincronizar.jsx` x `resumoDoSincronizar.js` colidiam** (FS sem caixa; o módulo puro virou
  `regrasDoResumoDoSincronizar.js` no review 176 WR-05, e um teste recusa par só pela caixa na pasta): o build falhava com
  "default is not exported". Import com extensão explícita resolve; melhor ainda, nunca nomear dois arquivos só pela caixa.
- **`assertSemOrigem` (sigilo do Portal) não pegava acento:** o JSON do Laravel escapa "ú" como sequência unicode,
  então varrer por "anúncio" passava batido. O helper agora decodifica antes de varrer; teste de sigilo novo deve usá-lo.
- **Gates de fonte antigos em JS** (`hook.includes('toque')`, proibição de `<details`) quebram com palavras novas
  (`estoque` contém "toque"): restrinja o gate ao trecho, não afrouxe.
- **Absorção das linhas antigas por cor (09/10, decisão do usuário).** O Sincronizar de ANTES do agrupamento criou um
  `pub_produtos` por oferta; na #459 sobraram 7 (um por cor não-âncora, vazios) ao lado do grupo. Agora, com o grupo de
  pé, o legado de uma cor DO GRUPO (mesma `company_id`, `origem = portal`, `estrutura_produto_id` nulo, oferta na lista
  já filtrada pelo `CoresDoGrupo` — a variação que vira produto separado nunca entra) que NADA referencia é APAGADO e
  contado em `absorvidos`. "Nada referencia" = sem `pub_rascunhos.produto_id` (a ÚNICA FK viva para `pub_produtos.id`,
  e ela é CASCADE: apagar com rascunho levaria o trabalho da equipe junto) e, se a tabela existir,
  `pub_produto_fatos_criativo`. Publicação, análise de IA e kit de criativos pendem do RASCUNHO, então sem rascunho
  não há nada deles. Por isso a condição "sem rascunho" mora no próprio `DELETE` (subconsulta), não numa leitura antes.
  **Com rascunho, publicado ou referenciado: nunca é tocado** (fica em `duplicados` e o aviso continua). Tabela nova
  com FK para `pub_produtos.id` tem de entrar em `semReferencias()`. Prova de mutação: tirar o `whereNotExists` do
  rascunho derruba 4 testes do `SincronizaPortalAgrupamentoTest`. Atenção: rascunho em QUALQUER cor faz dela a adotada
  (`adotar` prefere quem tem rascunho), então "a âncora vazia" é absorvida quando outra cor tem rascunho.

- **Avisos do Sincronizar vão para o LOG, não para a tela (09/10, pedido do usuário: "vai poluir muito").** O painel
  é UMA linha ("Sincronizado: 13 produtos, 20 variações, 0 fotos." + "N campos atualizados" e linhas de cor juntadas só se
  > 0). `PortalParaRascunhoService::preencher` e `PublicadorSincronizaPortalService` gravam
  `Log::info('[Publicador] Sincronizar avisos', {company_id, rascunho_id, avisos})`; o JSON do resumo ainda leva
  `avisos`, a tela só não lê. Campo não preenchido aparece pendente no editor — é lá que a equipe age. Para investigar
  um "não veio", procure essa linha no `laravel.log` da VPS.
- **Texto livre: Portal = SÓ opções onde há opções; Publicador leva o LEGADO (09/10, decisão do usuário).** Causa dos
  avisos "nenhuma das opções ("Madeira maciça de eucalipto") existe na lista": os produtos 3–7 da #459 tiveram a ficha
  gravada às ~10:44 de 08/10, ANTES de `045cf1dd`/`e7af3d22` (11:03), quando `string` com `values` ainda era texto no
  Portal. O editor do Publicador ACEITA texto nesses atributos (`STRUCTURE_MATERIALS`, `CABINET_MATERIALS`,
  `RECOMMENDED_INSTALLATION_ROOMS`: `string` + `multivalued`; nas 4 fixtures, 100% dos `string` com opções têm
  `allow_custom_value: true` e 100% dos `list`, `false`), mas o ramo MULTIVALOR do `PortalValorDeAtributo` ignorava
  `aceitaTextoLivre` e recusava. Corrigido só ali: o Sincronizar leva o texto antigo como `value_name` sem `value_id`
  onde o editor aceita (multivalor sem nada casando: nomes juntos por vírgula, `revisar` se > 1); onde não aceita
  (`list`), não preenche e o campo fica pendente. **O Portal NÃO deixa digitar onde há opção** — tentei abrir
  ("Outro (digitar)", em `dc48b1b7`) e o usuário mandou desfazer: vale o §35 do portal (08/10). Servidor recusa com
  422 neutro texto fora das opções. Não "alinhe" as duas pontas de novo: a divergência é de propósito (o cliente
  cadastra, a equipe publica). Testes: `PortalSoOpcoesELegadoNoPublicadorTest` (o mesmo schema nas duas pontas) e
  `test_nas_respostas_reais_campo_com_opcao_e_so_lista_mesmo_onde_o_editor_aceita_texto`. Ficou do `dc48b1b7`: lista
  FECHADA cujas opções o filtro de sigilo derruba inteiras SAI da ficha em vez de virar texto.
- **D-05 refinado (09/10): o que não se sobrescreve é o trabalho da EQUIPE, não o do Portal.** Caso real: rascunho 13
  (Puff 2) com `SHAPE = "REDONDO"`, `origem=portal`; o cliente trocou para "Redonda" e o Sincronizar dizia "mantido".
  Agora atributo (ficha e SELLER_PACKAGE_*) com `origem = 'portal'` SEGUE o Portal: muda junto e sai se o cliente
  apagou. `user` (a tela grava `origem: 'user'` ao editar — `CampoAtributo`, `MedidasDoPacote`), `ia`, `migrated`,
  `auto` → nunca. A checagem é UMA (`daEquipe`); tirá-la derruba 4 testes. Estoque e SKU da variante não têm `origem`:
  `step_state.portal_escrito[chave] = {estoque, sku}` guarda o último valor escrito e só se atualiza se o rascunho AINDA
  o tem. Rascunho de antes da memória: valor IGUAL ao do Portal é anotado como dele (daí em diante segue); diferente =
  da equipe. Memória gravada direto na linha travada (como `portal_cores`), sem `tocar()`: rodar 2× sem mudança não
  sobe `revisao`. Kit com `estoque_calculado` (Fase 175) nunca recebe estoque. **Categoria e fotos ficaram FORA**
  (trocar categoria apaga ficha incompatível; foto trocada é decisão da equipe) — continuam só no vazio. Resumo ganhou
  `campos_atualizados`. Valor do Portal que deixou de ser resolvível (opção sumiu) também SAI do rascunho se era do
  Portal: o campo fica pendente, que é a verdade.
- **Formato canônico do NÚMERO no rascunho = texto em `value_name` (09/10, rascunho 9 da #459, Puff Redondo).** O editor
  (`CampoAtributo`: `number_unit` → `"${n} ${u}"`; `number` → o texto digitado; `MedidasDoPacote` → `"${n} ${unidadeMl}"`)
  grava `value_name = "60 kg"` / `"3"` com `value_number`/`value_unit` NULOS, e a tela SÓ lê `value_name`. O Sincronizar
  gravava `value_number = 3.0000`, `value_name` nulo → "Quantidade de pés", "Quantidade de puffs" e "Peso máximo
  suportado" apareciam VAZIOS com o Portal tendo 3, 1 e 60 kg. O payload NÃO perdia: `ValorAtributo::paraPayload` lê
  `value_number` primeiro e cai no começo de `value_name` (idem `ValidadorRascunho::medida`), então o ML receberia "60 kg".
  Agora `PortalValorDeAtributo` escreve ponto decimal sem zeros à toa ("48.5 cm", "0.015 m", "1"); `number` nunca leva a
  unidade no texto. Linha velha `origem=portal` se conserta sozinha no próximo Sincronizar: o `mesmoValor` compara as
  colunas, então `value_number=3` ≠ `value_name="3"` → reescrita e contada em `campos_atualizados`; `user`/`ia` com
  número em `value_number` ficam como estão. A IA (`IaParaRascunhoService`, "Anunciar por IA") gravava `value_number`
  sem `value_name` — **corrigido em 09/10**: usa `PortalValorDeAtributo::numeroNoFormatoDoEditor` (mesma conversão de
  unidade), prova em `IaNumeroNoFormatoDoEditorTest`. Pacote (`SELLER_PACKAGE_*`) sempre esteve certo.
  Multivalor: a tela mostra só a 1ª opção e o payload leva só ela (D-13, de propósito). Prova:
  `SincronizarNoFormatoDoEditorTest` (o "o editor mostra" espelha a leitura do `CampoAtributo` em PHP).
- **Cor do produto de UMA cor e Cor principal (09/10, Puff Redondo da #459 com "Cor"/"Cor principal" vazias).** A ficha
  do Portal não pede COLOR quando o produto varia por cor (é o eixo), e com 1 variação não há eixo no rascunho — a cor
  "Azul" não ia a lugar nenhum. Agora, com `plano.chave` nulo e todas as variações de eixo cor com o MESMO valor
  (`corUnica`), o valor entra na ficha como COLOR `origem=portal` (resolvido como qualquer atributo: opção pelo nome ou
  texto livre). MAIN_COLOR (lista fechada, §11) vai na VARIANTE (única ou cada cor do eixo) com a opção de mesmo nome
  `ChaveCanonica::texto` — sem sinônimo (isso é o `tomDaCor` da tela); sem casamento fica vazia e só loga. **Atributo de
  variante não tem `origem`** (`pub_variante_atributos` não tem a coluna; o `origem: 'auto'/'user'` da tela some ao
  gravar): a regra D-05 refinada usa `step_state.portal_escrito[chave].tom`, igual SKU/estoque. Quando a cor única vira
  eixo (cliente acrescentou cores), o COLOR de produto `origem=portal` sai (`aplicarFicha`, o eixo novo). Prova:
  `SincronizarCorEMedidasDoProdutoTest` (tirar a checagem da memória derruba o teste da equipe).
- **Medidas do produto fora da caixa chegam do Portal (09/10).** O Portal passou a pedir LENGTH/WIDTH/HEIGHT/DEPTH/
  DIAMETER/WEIGHT num bloco próprio (portal §38); o Sincronizar não mudou — já levava todo atributo de produto, no formato
  canônico ("58.5 cm", "7.5 kg"). `DIAMETER` entrou em `MEDIDAS_DO_PRODUTO` (`ferramentas.js`): sai de "Mais
  características" e fica em "Produto fora da caixa" como "Diâmetro do produto". Um teste JS amarra os ids do bloco do
  Portal aos do editor.

### Checklist de DEPLOY (só com autorização do usuário)

1. Contar em produção ANTES e DEPOIS: `estrutura_produtos`, `estrutura_produto_variacoes`, `pub_produtos`,
   `pub_rascunhos`, `pub_variantes`, `pub_imagens` (as 3 migrations são aditivas e anuláveis; nenhuma linha deve mudar).
2. `php artisan migrate --force` (3 migrations de 2026_10_08_15xxxx; o MariaDB local estava vazio nessas tabelas, a
   prova com linhas só existe em SQLite).
3. `sudo -u www-data php artisan queue:restart`: os Jobs novos rodam na fila `high`; worker velho não os conhece.
4. Conferir GD com suporte a WebP no PHP de produção (`php -r "var_dump(function_exists('imagecreatefromwebp'));"`).
5. "Sincronizar do Portal" na #459 e abrir o rascunho, sem publicar (conta de cliente: só a #459 recebe publicação).

## 15. Explicação de todo campo ao passar o mouse (08/10/2026)

Pedido do usuário ("AGID? MPN? … isso para tudo, não apenas para siglas"). O que não se deduz do código:

- **Prioridade decidida pelo usuário: glossário > guardado > ML > texto montado + IA.** Glossário em
  `config/publicador_glossario.php` (NEUTRO, ≤ 220: o teste `ExplicacaoDeAtributosRegrasTest` reprova texto que cite
  plataforma/anúncio). O `tooltip`/`hint` do ML é guardado com origem `ml` na 1ª vez e **não é atualizado** se o ML
  mudar o texto depois (a linha guardada vence o ML). Para reescrever, apague a linha em `atributo_explicacoes`.
- **Cobertura medida nas 4 fixtures da sondagem** (atributos únicos não ocultos): 43 pelo glossário, 11 pelo texto do
  ML, 89 ficam com texto montado até a IA escrever. O ML traz pouco (MLB193945: tooltip em 20 de 91, hint em 2), e boa
  parte dos tooltips é de atributo oculto. A IA roda ~1 vez por atributo, para sempre: 89 atributos ≈ 3 chamadas.
- **AGID não tem tooltip nem documentação nas fixtures** (`hierarchy: PRODUCT_IDENTIFIER`, oculto, por variação). O
  texto do glossário é genérico de propósito ("outro código de identificação… pode deixar vazio"); não "corrija" para
  um significado inventado.
- **Fila `sync` NÃO enfileira** (`ExplicacaoDeAtributos::enfileirar`): no `sync` o Job rodaria dentro de `estado()` e a
  tela esperaria a IA. Por isso testes que queiram ver o enfileiramento precisam de `Queue::fake()` (aí a conexão não é
  `SyncQueue`). Máquina local com `QUEUE_CONNECTION=database` enfileira, mas só gera com worker rodando.
- **Trava por atributo: `Cache::add('publicador:explicacao:{ID}')` por 6 h**, nunca liberada na falha — IA fora ou
  texto reprovado (longo, HTML, cita plataforma/loja) só tenta de novo quando a trava vence. Atributo oculto
  (`secao = OCULTO`) recebe texto mas nunca gasta IA.
- **Sem a tabela a tela não quebra** (`salvos` captura a exceção): glossário e ML aparecem, nada é guardado nem
  enfileirado. É o estado de produção entre o deploy do código e o `migrate`.
- **Portal (`paraPortal`)**: o texto do ML costuma citar "anúncio"; o filtro de sigilo troca pelo texto montado e, se
  nem esse passar (nome de atributo com "Marketplace"), usa "Característica do produto.". A sigla "ML" só é pega em
  caixa-alta (`\bML\b` no texto original): "500 ml" é unidade. `\bmercado\b` poupa "mercadoria".
- **Front**: o ícone mora no `Campo` (`explicacao`/`nome`), FORA do `<label>` (botão dentro do rótulo focaria o campo a
  cada clique). Balão próprio em CSS, abre no hover e no foco; o `title` nativo saiu do `RotuloAtributo` para não
  somar dois balões. A chave do campo fixo é `estoque_por_deposito` porque um gate antigo proíbe a string
  `estoque_depositos` no `CartaoVariante`.
- **Prova no MariaDB 10.4 local** (`--path` só da `2026_10_08_160000`): up → rollback → up, DONE ×3, `Ran` no lote 139;
  `UNIQUE KEY atributo_explicacoes_atributo_uq`; `INSERT IGNORE` do mesmo id não duplica (linha de prova apagada; 0
  linhas). Tabela fica criada no local. Deploy: `migrate --force` + `queue:restart` (Job novo na fila `default`).

## 16. IA prepara o rascunho ao salvar no Portal (09/10/2026)

Pedido do usuário: a IA é lenta, então trabalha ANTES — o cliente salva o produto no Portal e, quando a equipe abre
o Publicador, título, Modelo e descrição já estão lá. É a EXCEÇÃO consciente do §10 ("a IA deixa no cache e a tela
aplica"): aqui não há tela, então a automação GRAVA no rascunho. O que não se deduz do código:

- **Gatilho e debounce.** `PreparoIaAgenda::aoSalvar` é chamado por gravar linhas (só produto criado/mudado — a
  importação da planilha passa por `ProdutoCadastroService::gravarLinhas`), ficha técnica, descrição e imagens
  (enviar/excluir/ordenar) no `PortalEstruturaProdutosController`. Cada save grava `publicador:preparo:marca:{produto}`
  (uuid, 1 dia) e agenda `PrepararProdutoNoPublicadorJob` com `atraso_min` — **2 minutos** desde 10/10/2026 (era 10;
  o usuário: "se não mexer lá novamente, espera dois minutos e já pode ir gerando tudo", 10 perdia eficiência). Com a
  espera curta, quem pausa no meio da ficha pode ganhar uma geração antes de terminar: a IA só roda com categoria +
  obrigatórios, regera quando os fatos mudam (hash) e só escreve onde ainda é dela; o teto diário por empresa (60)
  segura o custo. O Job que acorda com marca diferente
  sai (`superado`): numa rajada de saves só o último age. Fila `sync` NÃO agenda (rodaria dentro do save do cliente).
  Exclusão de variação não agenda (o Sincronizar nunca remove cor).
- **O produto chega ao Publicador LOGO; só a IA espera (10/10/2026).** O usuário corrigiu: os "10 minutos" que ele
  pediu eram o espaço entre PUBLICAÇÕES (§20), nunca entre o save e o Publicador ("ou vai instantâneo ou na hora de
  sincronizar"). Por isso o `aoSalvar` agenda também `SincronizarProdutoDoPortalJob` (`sincronizar_atraso_s`, 15 s,
  fila `default`, SEM IA) → `PreparoIaDoRascunhoService::sincronizarAgora`: as mesmas travas do `preparar` (editor
  aberto, na fila de publicação, "Anunciar por IA" → `ocupado`, não toca), um por empresa de cada vez (`Cache::lock`
  com `block(120)`: a planilha agenda dezenas; dois Sincronizar do mesmo Combo esbarram nos uniques). Saves seguidos
  viram UM Job: `Cache::add` de `publicador:preparo:sincronizar:{produto}` (5 min) e o Job a APAGA ao começar — save
  que chega durante a sincronização agenda outra, nenhum fica de fora. O preparo da IA (2 min sem save) continua igual
  e sincroniza de novo antes de gerar (idempotente). Não confundir as esperas ao explicar o fluxo: ~15 s até o
  Publicador, 2 min sem save até a IA, e as rodadas da fila (§20) só na publicação.
- **Fila `high`, nunca `default` (10/10/2026, achado do teste E2E em produção).** O `SincronizarProdutoDoPortalJob`,
  o `PrepararProdutoNoPublicadorJob` e a cadeia `GerarPreparoIaJob` nasceram na `default` ("não é clique de pessoa") e
  ficaram presos: às 11:37 a `default` tinha 308 jobs + 220 atrasados (`SyncFaturamentoMensalJob`, depois
  `SyncMlAcervoCompanyJob`/`SyncMlAcervoDetalheJob`) e os 2 workers `high,default` presos em Acervo — os 8 SKUs
  salvos às 11:29 não tinham chegado ao Publicador 8 minutos depois. Workers de produção (só na VPS, fora do repo):
  2× `--queue=high,default`, 1× `--queue=high` (dedicado, quase sempre ocioso), 3× `--queue=creative`. Na `high`
  o worker dedicado pega o job na hora; os outros Jobs de IA do Publicador (descrição, palavras-chave, kit) já moram
  lá. Job novo do fluxo Portal → Publicador que precisa ser rápido vai para a `high`.
- **Sincroniza SÓ o produto**: `PublicadorSincronizaPortalService::sincronizar(..., soDoProduto)` filtra as ofertas
  Simples das variações dele + as compostas que o têm como componente; as regras são as mesmas do botão (D-05
  refinado). Não grava o "sincronizado em" da empresa. O preenchimento usa a MESMA trava do Job do botão
  (`PreencherRascunhoDoPortalJob::chaveDaTrava`).
- **IA só com ficha completa**: categoria + todos os `obrigatorio` de `FichaTecnicaDaCategoria::daAtributos` (do schema
  já guardado, com o eixo do produto fora), MENOS o `MODEL` — ele é obrigatório na cadeira (e o cliente o vê na ficha),
  mas é a IA que o gera. Combo/Kit/Combit: todos os componentes completos. Kit/fase do Publicador (`produto_base_id`)
  nunca é preparado.
- **Memória `step_state.ia_escrito`** (`MemoriaDoPreparoIa`): o último valor que a automação escreveu em
  `titulo_gold_special`, `titulo_gold_pro`, `modelo`, `descricao`. Escreve só no campo VAZIO ou que ainda tem
  EXATAMENTE esse valor (`podeEscrever`). Título planejado na aba Anúncios (efetivo) conta como preenchido; MODEL com
  `value_id` (opção/N/A) também. **Atenção:** se o cliente preencher o Modelo no Portal (obrigatório na cadeira), o
  Sincronizar o grava `origem=portal` e a IA NUNCA o substitui — era a regra até a decisão abaixo. Perder a memória (outra escrita do `step_state` que a pisou — `ConferenciaService` e `lerContaSeVencida`
  regravam o `step_state` inteiro de um modelo lido antes) é SEGURO: o campo passa a contar como da equipe. Prova de
  mutação: `podeEscrever` sempre true derruba 3 testes; só-vazio derruba 5 (`PreparoIaAoSalvarNoPortalTest`).
- **Hash dos fatos em `step_state.ia_preparo`** (nome, categoria, atributos do rascunho fora MODEL/GTIN/SELLER_SKU/
  EMPTY_GTIN_REASON, cores das variantes ativas, descrição do cliente): igual = não chama a IA. Por etapa:
  `ok`/`pulado`/`intocavel` não repetem; `erro`/`desistiu` refazem no próximo save (título refeito leva o Modelo junto);
  `rodando`/`adiado` com menos de 3 h não duplicam. Campo já preenchido pela equipe nem chama a IA (`livres`).
- **Cadeia**: `Bus::chain` de `GerarPreparoIaJob` (título → Modelo → descrição), fila `default`, `tries=1`,
  `timeout=300`, prazo da IA 240 s. A falha da IA NÃO lança (fica `erro` na etapa) para a cadeia seguir; o Modelo sem
  título nenhum é `pulado`. Quebra fora da IA para a cadeia — o `failed()` do título/Modelo põe a descrição na fila.
  Título: DOIS, um por tipo (ver "dois títulos" abaixo), cortados no `max_title_length`, com o bloco FATOS DO PRODUTO no
  prompt (vale também para o botão "Sugerir com IA"). Modelo: `gerarModelo` com os títulos GERADOS
  (`ia_preparo.titulo_gerado`, listing_type_id → título), MODEL gravado
  `{value_id: null, value_name, origem: 'ia'}` como o editor. Descrição: `DescricaoIaService::gerar` (MAG T8 intocado) e
  gasta a chance do automático do editor (`chaveAuto`), então o D-11 não gera de novo.
- **Editor aberto = não escreve.** O editor salva a chave de topo inteira da cópia local (`atributos`, `alvos` →
  `gravarAtributos`/`gravarAlvos` regravam a lista): uma escrita por trás da tela não apagaria o que a pessoa digita
  (o salvamento dela vence), mas seria desfeita sem ninguém ver no próximo salvamento. Por isso: `EditorEmUso` (cache
  `publicador:editor:em-uso:{pub_produto}`, 3 min), renovado por TODA rota do editor (`MlbPublicadorController::produto`,
  `MlbPublicadorDescricaoController`) e por um sinal por minuto da tela com a aba visível (`POST …/presenca`). Com ele
  valendo (ou "Anunciar por IA" rodando no rascunho), o preparo inteiro espera `adiar_min` (5) e tenta de novo; se a
  IA já gerou, a escrita espera com o valor pronto (`valorPronto`, sem chamar a IA de novo). Até `max_adiamentos` (24);
  depois `desistiu` e o próximo save recomeça. Adiar é Job NOVO com `delay`, nunca `release()` (§6, `tries=1`).
- **Tela**: `estado().preparo_ia` diz o que AINDA é da IA; o selo "Gerado pela IA a partir da ficha do Portal." (13px,
  `text-white/50`) aparece no título, Modelo e descrição enquanto a tela mostra o mesmo valor (`mostraSeloDaIa`). Nada
  muda no Portal (sigilo): a resposta dos saves é a mesma.
- **Custo**: `PUBLICADOR_PREPARO_IA_ATIVO=false` desliga TUDO (nem sincroniza); `PUBLICADOR_PREPARO_IA_LIMITE_DIARIO`
  (60) conta PREPARAÇÕES (um produto = título + Modelo + descrição, ~4 chamadas com a análise MAG T8) por empresa por
  dia, em cache; passou, só sincroniza e loga `[Publicador] Preparo pela IA: limite diário…`.
- **O Modelo saiu do Portal; a IA gera (decisão do usuário, 09/10/2026).** `FichaTecnicaDaCategoria::ID_MODELO`
  nunca entra na ficha do cliente, nem onde a categoria o exige (cadeira MLB193945); o PUT ignora `MODEL`. O que o
  cliente gravou antes FICA em `estrutura_produto_atributos` (o salvar da ficha não o apaga e `salvos()` não o devolve à
  tela) e entra só como FATO: `PortalProdutoLeitor::modeloDoCliente` → linha "Nome/modelo informado pelo cliente: …"
  no bloco FATOS do título e do Modelo, e no hash. O Sincronizar não leva mais `MODEL` (`daFicha`) e REMOVE o `MODEL`
  `origem=portal` de rascunho antigo (para a IA poder gerá-lo); `user`/`ia` nunca. Única diferença de propósito entre
  a ficha do Portal e o editor: o teste da régua (`test_na_cadeira_a_ficha_tem_exatamente…`) tira o Modelo do lado
  do editor (43 campos, não 44).
- **Dois títulos diferentes, sem marca nem peso (relato do usuário, 09/10/2026).** O preparo gerou "Puff Sala Redondo
  Banqueta Moderno ECF 130 kg" e gravou o MESMO título no Clássico e no Premium — o ML barra dois anúncios com o mesmo
  nome, e a marca/o peso vinham do bloco FATOS (BRAND e "Peso máximo suportado" estão na ficha). Agora o preparo faz UMA
  chamada `titulosPorTermos` que devolve `{"classico","premium"}` (mesma intenção de busca, 1–2 palavras trocadas e/ou
  ordem); o botão de um tipo manda o título do OUTRO (`tituloDoOutroTipo` → `titulo` do pedido) como "NÃO REPITA". A
  garantia é do servidor (`RegrasDoTitulo`), porque a IA não obedece sempre: `limpar` tira a marca (BRAND, como frase
  inteira, sem caixa/acento) e "ECF" sempre, número + kg/g/l/ml/W/V/mAh sempre, e medida/dimensão/quantidade/número
  solto só fica se o nome do produto ou os termos de busca a têm ("Mesa 160x90"). `mesmo` = mesma sequência de palavras
  ignorando caixa, acento e plural (ordem diferente É diferente). `diferenciar` não inventa: troca a ordem das duas
  últimas palavras (3+ palavras; o produto principal fica no começo) e só então acrescenta/troca uma palavra de termo de
  busca relacionado que passa nos fatos. Sem saída: o preparo NÃO escreve aquele tipo e o botão dá erro — nunca dois
  iguais. A escrita confere de novo contra o título que a equipe deu ao outro tipo (`titulosFixos`). O BRAND sai do
  bloco FATOS do título (vai como proibição, regra 7). `valorPronto` do título é JSON; texto puro = Job adiado de antes.
  O Modelo NÃO mudou. "Copiar do Clássico/Premium" na tela ainda copia igual — é escolha explícita da pessoa.
- **Deploy**: sem migration. `queue:restart` (Jobs novos na fila `default`); `npm run build` (selo + sinal).

## 17. Tarefas pós-publicação — "Publicados aguardando alavancas" (09/10/2026)

Pedido do usuário: publicou pelo Publicador → a tarefa chega a outro colaborador, que usa as alavancas (Central de
Promoções, ADS de lançamento, atacado, cupom, afiliados, lista de transmissão — o checklist da aba Cronograma da
planilha da ECF). Tabela `pub_tarefas` (só CREATE, decisão de schema na migration `2026_10_09_180000`). O que não se
deduz do código:

- **Gatilho em DOIS lugares de `PublicacaoService`:** o fim do `concluir()` (PUBLISHED/PARTIALLY) e o fim do
  `encerrar()` — publicação interrompida DEPOIS de criar um item (conta tirada da lista no meio, Job morto) tem MLB no
  ar e precisa da alavanca; sem item criado, `abrir()` não faz nada (é o "nada em FAILED"). Só entram os itens CREATED
  da própria publicação. Falha do gatilho só loga `[Publicador] … tarefa pós-publicação não abriu` — a publicação nunca
  é desfeita nem repetida; recuperar com `publicador:tarefas-retroativas --desde=AAAA-MM-DD` (sem sino; `--dry-run`).
- **Uma tarefa por PRODUTO:** o unique `(tipo, publicacao_id)` é a idempotência; republicar o mesmo rascunho com a
  tarefa ABERTA junta os MLBs novos nela (sem sino de novo); com a tarefa já concluída, nasce outra só com o MLB novo.
  MLB que já está em qualquer tarefa do rascunho nunca entra de novo (o retroativo rodado duas vezes não duplica).
- **Responsável padrão = `configuracoes.publicador_alavancas_responsavel`** (id), escolhido pelo admin na própria fila;
  vale para as PRÓXIMAS. Usuário inativo ou sem a chave da fila = fila comum, e o sino vai para TODOS que veem a fila
  (todos os admins + setores com `mlb.alavancas`) — sem responsável configurado, cada publicação toca o sino de todo
  admin. O sino sai em `DB::afterCommit`.
- **Acesso: chave NOVA `mlb.alavancas`** (registro `App\Support\Permissions`, "Pub · Alavancas pós-publicação"), e não
  `mlb.anunciar`: quem usa a alavanca não é quem publica, e o dia em que o Publicador abrir para `permission:mlb.anunciar`
  (cabeçalho de `routes/mlb_anuncios.php`) não pode dar a fila a quem publica nem a publicação a quem usa a alavanca. A
  fila é o ÚNICO grupo fora do `role:admin` em `routes/mlb_anuncios.php`; escrever no ML pelas Alavancas continua admin
  (o "Abrir Alavancas" nem aparece para quem não é admin). Para o "Caio" não admin: Setores → dar a chave ao setor dele.
- **Prazo D+1 útil no fuso de São Paulo** (`DiasUteis`): fixos nacionais no código (inclui 20/11, nacional desde 2024),
  móveis em `publicador.feriados` (2026–2028 já escritos; outros anos lá ou em `PUBLICADOR_FERIADOS`). Carnaval entrou
  por ser folga da ECF (é ponto facultativo) — decisão de config, não de código. `prazo` fica SEM cast `date` (texto
  `Y-m-d`): o cast gravaria `Y-m-d 00:00:00` no SQLite e a comparação por texto divergiria do DATE do MariaDB.
- **Baixa automática só pelo que APLICA a alavanca** (`TarefasPosPublicacao::chaveDaEscrita`): `convite.inscrever`/
  `convite.alterar` (→ `cupom` quando o tipo é `SELLER_COUPON_CAMPAIGN`), `desconto.criar`, `atacado.gravar` com faixas.
  Tirar, remover, excluir e gravar o atacado VAZIO não contam; `cupom.criar` e `campanha.*` são da conta (sem `item_id`)
  e ficam para marcar à mão. Casa por âncora (company/mlb_empresa) e pelo MLB em PHP — de propósito, sem JSON no SQL
  (o `json_each` do SQLite e o `JSON_CONTAINS` do MariaDB divergem). Item já marcado à mão não é sobrescrito.
- **O link da fila abre `company-N`** (sempre resolve) e o redirect para a chave canônica das Alavancas passou a
  preservar `aba` e `item` — antes ele descartava a query.
- **MariaDB 10.4 local, `--path` só da migration:** up → rollback → up, DONE ×3, `Ran` no lote 140; nomes `pubtar_*`
  conferidos no `SHOW CREATE TABLE`; unique dá 1062 em (tipo, publicacao_id) repetido e NULL repete; FK dá 1452. O
  `json` vira `longtext … CHECK (json_valid(…))`: texto não-JSON dá **4025** no MariaDB e passa no SQLite. Linhas de prova
  apagadas; a tabela fica criada no local.
- Cosmético conhecido: na própria fila o item "Publicador" do menu acende junto com "Aguardando alavancas" (o `page`
  `'Mlb/Publicador/'` daquele item casa por prefixo).
- **Deploy:** `migrate --force` (1 CREATE); `queue:restart` (o gatilho roda dentro do `PublicarRascunhoJob`, fila `high`,
  e a baixa dentro do lote das Alavancas — worker velho não conhece a classe nova); `npm run build`; depois, na fila,
  escolher o responsável padrão e dar `mlb.alavancas` ao setor de quem usa as alavancas.

## 18. Planejamento × Fase N (09/10/2026)

Decisões do usuário: (1) o combo vai ao ML como UM anúncio com as cores como variação — o kit da Fase N
(`produto_base_id` + `quantidade_kit`); o Planejamento continua gerando a oferta de CADA cor e elas viram as variantes
do kit; (2) o Planejamento do Portal é a fonte de QUAIS composições existem — o "Criar Fase N" puxa dali e cria lá o que
falta (cai a "decisão 5" do `175-DECISOES` para o base AGRUPADO); (3) Kit e Combit (produtos diferentes) seguem um
`pub_produto` por oferta composta. Nota ao outro dev: `.planning/coordenacao/261009-planejamento-x-fase2.md`. O que não
se deduz do código:

- **O vínculo oferta Combo ↔ variante do kit é DERIVADO pela cor, sem tabela** (`PlanejamentoDaFaseService` +
  `VariantesPorCor`: `ChaveCanonica::texto` do valor do eixo × `valor` da variação, só nas cores do grupo, `CoresDoGrupo`).
  Variante de 2+ eixos não casa; produto de uma cor casa a `__single__`. Cor renomeada no editor perde o casamento: fica
  sem SKU/preço do Portal (vazio, nunca o de outra cor).
- **Sincronizar:** Combo de UMA cor de produto agrupado não vira `pub_produto`. Com o Kit N, o kit entra em
  `para_preencher` e `preencherKitDaFase` leva o SKU da oferta à variante (D-05 refinado, `portal_escrito[chave].sku`). O
  `-KIT{N}` de kit antigo é da equipe e FICA; o preço chega assim mesmo, porque o mapa é pelo SKU ATUAL da variante. Cor
  cujo Combo foi publicado como avulso não recebe o mesmo SKU. Sem o Kit N: `combos_aguardando_fase` (resumo + log).
  `ofertasCobertas` conta esses Combos como cobertos — sem isso a empresa mostraria "ofertas novas" para sempre.
- **Absorção endureceu (cores e combos):** nunca apaga o que é kit nem o que é BASE de kit (`pubprod_base_fk` é SET NULL
  e soltaria o kit calado). A checagem "é base" fica no SELECT, fora do DELETE: subconsulta na própria `pub_produtos`
  dentro do DELETE é o **erro 1093 do MariaDB**, e o SQLite dos testes passa.
- **Preço do kit:** `daProduto` dá `precos_por_variante` ao kit sem oferta de base agrupado; `precos` (âncora) fica nulo
  de propósito — a cor sem Combo não herda o preço de outra. Variante sem SELLER_SKU não recebe preço (`comEfetivos`
  casa pelo SKU). Nada é gravado: "a criação grava o preço" foi lido como "vem da Precificação", nunca congelado.
- **Composto do Planejamento** = `pub_produto` NÃO-kit ligado a oferta `combo|kit|combit`, lido pela relação `oferta`
  (consulta só de `estrutura_ofertas`; nunca `fase` num JOIN). Chave `composto` na lista, `contagens.compostos` à parte
  dos 5 buckets de `por_fase` (a Visão geral desenha 5 numa ordem fixa e o `ListaPorFaseTest` pina os 5). KIT-06 vem
  ANTES do KIT-05/KIT-01 no endpoint (o conselho deles seria o errado). Combo antigo VINCULADO como kit é kit.
- **Criar Fase N:** `garantirOfertas` roda DENTRO da transação do `CriarFaseService` (savepoint): kit que não nasce leva
  as ofertas do Portal junto. Trava a Company (a mesma trava do "Aceitar") e relê os Combos sob ela; a cor aceita pelo
  cliente entre a prévia e o Confirmar é usada como está. Nome/SKU = `NomesSugeridos::combo` com o tipo inferido como no
  `RetratoDoCatalogo` (o teste compara com a sugestão da tela). Sem `user` (chamada direta) não cria nada.
- **Planejamento (E):** `RetratoDoCatalogo` conta `v{cor}*N` de cada cor do grupo de todo kit da Fase N (uma consulta, só
  leitura). Cor acrescentada ao Portal depois do kit também conta como existente — o kit não ganha cor sozinho.
- **Vínculo (F):** o base se acha pela variação do componente → grupo; combo de UMA cor para base de VÁRIAS cores = sem
  sugestão; composto nunca é base na heurística. `estruturaProduto` entrou no eager load (era N+1 por grupo no
  `skuExibido()`).
- **Deploy:** sem migration; `queue:restart` (o Job de preencher passa a receber kits); `npm run build`.

## 19. Promoção automática pós-publicação, preço sem frete e título igual (10/10/2026)

Decisões do usuário de 09/10. O que não se deduz do código:

- **A D-04 da 166 (prévia assinada + confirmação humana) foi superada SÓ aqui.** Publicou → cada anúncio CRIADO ganha
  sozinho o PRICE_DISCOUNT de 14 dias (o máximo do ML, contando as duas pontas; doc relida em 09/10). A escrita continua
  pelo `EscritorAlavancas` (trava das Alavancas, `/users/me`, linha do histórico antes do HTTP, 5xx/rede = INCERTO e
  nunca reenvia); o serviço mora em `Services/Publicador/Alavancas/` de propósito, para o `UnicoCaminhoDeEscritaTest`
  varrê-lo (o Job e o comando entraram na lista do teste).
- **O preço:** `anunciado` = publicar, `minimo` = promoção (ADR PORTAL-02). Publicado pelo anunciado → o mínimo
  (207,19 → 172,66, −16,67%); preço digitado → o MESMO percentual (mínimo ÷ anunciado) sobre ele, nunca abaixo do mínimo;
  desconto fora de 5% ≤ d < 80% ou Portal sem frete → sem promoção (o mínimo sem frete está subestimado). A conta é
  `PrecoDaPromocao` (PHP) e `promocaoAutomatica.js` (tela); os dois testes usam os MESMOS números — mudou um, mude o
  outro. Com acréscimo 20% o percentual é sempre ~16,67% (1 − 1/1,2): o frete não muda o percentual, só o mínimo.
- **Tabela `pub_promocoes_automaticas`, uma linha por CICLO** (só CREATE; docblock da `2026_10_10_090000`). Unique
  (ml_item_id, ciclo) é a idempotência; `inicio`/`fim` são DATE sem cast (texto `Y-m-d`, como o `prazo` de `pub_tarefas`).
  Status: agendada → enviando → ativa | recusada (tarefa orienta) | cancelada (já tinha desconto/anúncio encerrado);
  ativa → encerrada (renovou, ou preço mudou/anúncio fechou).
- **Job `CriarPromocaoAutomaticaJob`**: fila `high`, `tries=1`, 3 min depois de publicar. Anúncio ainda não `active` →
  Job NOVO com espera crescente (`publicador.promocao_automatica.esperas_min`, até `tentativas_max` = 8, ~8 h), nunca
  `release()`. Fila `sync` não despacha (rodaria dentro da publicação): a varredura do comando pega. Trava por anúncio
  (`publicador:promocao-automatica:{MLB}`); o Job do ciclo seguinte sai DEPOIS de soltar a trava (no `sync` ele roda na
  hora e precisaria dela).
- **Nunca em anúncio de outra conta:** a escrita exige a âncora com token = `conta_chave` e o vendedor = o do clique em
  Publicar (`pub_publicacoes.ator.conta`); sem isso recusa ANTES de qualquer leitura. O multiget das Alavancas já descarta
  anúncio de outro vendedor. Prova de mutação: tirar a checagem derruba 2 testes.
- **Ator:** quem publicou (da equipe e ativo); senão `configuracoes.publicador_usuario_sistema` (id). Nenhum → recusada.
- **Tarefa:** conta fora das Alavancas = ciclo nasce `recusada`, nada vai ao ML, e a fila mostra "Crie a promoção de
  R$ X para R$ Y (−Z%) até dd/mm no Seller Center." (a frase sai do ciclo, `orientacao()`, não é gravada na tarefa). Recusa
  do 1º ciclo põe a Central de Promoções pendente, mas nunca desfaz o que uma PESSOA marcou; recusa de RENOVAÇÃO reabre a
  tarefa concluída com prazo novo e toca o sino ("Promoção não renovada"). A baixa automática não marca "feito" enquanto
  OUTRO anúncio da tarefa tem o último ciclo recusado (mutação: derruba o teste da ordem).
- **Renovação** `publicador:promocoes-renovar`, 00:05 de São Paulo: ativo cujo `fim < hoje` + anúncio ativo + mesmo preço
  (`price` OU `original_price` = publicado) → ciclo seguinte de hoje a hoje+13; preço mudou / anúncio fechou / sumiu da
  conta → `encerrada` e nada mais. O preço do ciclo novo é o mesmo, a não ser que o MÍNIMO do Portal de agora tenha subido
  acima dele (aí refaz a conta; sem desconto possível, não renova e a tarefa avisa). A mesma rodada reenvia o Job agendado
  perdido (> 15 min) e fecha `enviando` preso há mais de 1 h como recusa ("confira no Seller Center").
- **[ASSUMED]** os textos de recusa do ML para reputação/vendas/campanha (`motivoDoMl` só acrescenta uma explicação em
  pt-BR à mensagem do `MapeadorErroAlavanca`): a #459 não tinha anúncio ativo para provar. Primeira prova real: publicar na
  #459 com um anúncio NOVO e ativo e conferir o ciclo e a linha em `pub_alavanca_escritas`.
- **V-SAL-08** (preço do Portal calculado sem frete bloqueia; o digitado passa) e **V-TIT-04** (título igual no Clássico e
  no Premium bloqueia, critério `RegrasDoTitulo::mesmo`: caixa, acento e plural simples) — os ids que o pedido sugeria
  (V-SAL-03, V-TIT-03) já eram da spec `08` (faixa de preço; título curto, erro 3715). O V-TIT-04 substitui o D1 de títulos
  (`ChaveCanonica` não pegava plural). A marca do V-SAL-08 só existe no snapshot montado por `comEfetivosDe` (conferência,
  publicação e estado do editor); `comEfetivos` com 3 argumentos devolve o de antes.
- **Prova no MariaDB 10.4 local** (`--path` só da `2026_10_10_090000`): up → rollback → up, DONE ×3, `Ran` no lote 142;
  nomes `pubpromo_*` no `SHOW CREATE TABLE`, FKs `ON DELETE SET NULL`; unique repetido = 1062; FK inexistente (publicação e
  escrita) = 1452; `fim` volta `'2026-10-22'` (texto) e o escopo do último ciclo roda no MariaDB. DML em transação desfeita:
  0 linhas. A tabela fica criada no local.
- **Deploy:** `migrate --force` (1 CREATE); `queue:restart` (Job novo na fila `high`, e o gatilho roda dentro do
  `PublicarRascunhoJob`); `npm run build`; o cron do `schedule:run` já existe (só confirmar que roda); opcional:
  `configuracoes.publicador_usuario_sistema` = id de um usuário ativo (sem ele, só quem publicou assina).

## 20. Publicação em lote — visão rápida, conferir selecionados e fila em rodadas (10/10/2026)

Pedido do usuário (09/10): publicar EM MASSA "de primeira" o que o cliente preencheu no Portal, com intervalo entre
produtos para não arriscar restrição do ML. Decisão de 09/10: 1 produto (Clássico + Premium, todas as cores) a cada
10 min. **Revista em 10/10 pelo próprio usuário:** "sobe cinco de uma vez (Clássico e Premium), depois de uns 20
minutos mais cinco" — RODADAS de 5 produtos a cada 20 min, os dois ajustáveis na tela (1–10 por rodada);
pausar/retomar/cancelar. O que não se deduz do código:

- **Visão rápida com número FIXO de consultas** (`Fila/ResumoRapidoService`, ~30 para 3 ou 12 produtos — o
  `VisaoRapidaDoLoteTest` mede, com kit da Fase N no meio). O rascunho é remontado em memória (só alvos, variantes,
  SKU, preço, estoque, valores de eixo) a partir do eager load; a última conferência vem SEM `respostas_ml` (a coluna
  pesada que a lista do Publicador carrega inteira). Os efetivos saem de `Fila/EfetivosEmLote`, o `daProduto()` em lote
  (uma `pagina()` por empresa); o `VisaoRapidaDoLoteTest::test_efetivos_em_lote_iguais_*` compara o ARRAY INTEIRO
  (`assertSame`: chaves, valores e ORDEM — `promocoes`, `sem_frete` e os `_por_variante` da §19 inclusive) com o
  `DadosEfetivosService` produto a produto (simples, agrupado, kit, sem oferta). Chave nova no `daOferta()` → este
  teste quebra até o `EfetivosEmLote` espelhar; no kit, o 1º Combo de cada SKU vence nos três mapas juntos.
- **Bloqueios ANTES de conferir** (V-TIT-04 títulos iguais, V-SAL-08 preço do Portal sem frete): a visão rápida lê
  `ValidadorRascunho::bloqueiosSemSchema` (as MESMAS funções do `validar()`, sem schema nem conta — o
  `BloqueiosSemSchemaTest` compara os dois), sobre o snapshot do `comEfetivosDe` (é ele que grava o `portal` e o
  `preco_do_portal` que o V-SAL-08 lê). Quem tem bloqueio não agenda, mesmo com conferência OK de antes da regra, e o
  agendador relê na hora de publicar (`ResumoRapidoService::linhaDe`) — bloqueio que surgiu depois vira `precisa_revisar`.
  Regra nova que se sabe sem o ML entra ali, não numa cópia na tela.
- **Margem estimada** = preço − custo − frete − (comissão% + imposto%) × preço, por cor e por tipo, com a comissão e o
  imposto da linha da Precificação (exceção do produto ou padrão da empresa). Sem custo não há margem; sem frete a
  margem sai marcada `sem_frete` (frete esquecido não some calado). Custo/frete da cor = a oferta casada pelo SKU da
  variante (como o preço); sem casamento, a oferta do produto.
- **Fila: o BANCO garante 1 viva por conta e 1 produto numa fila** — colunas-sombra `conta_ativa`/`produto_ativo` com
  unique (NULL repete nos dois bancos; nem MariaDB 10.4 nem SQLite têm índice parcial em comum). Toda transição que
  tira o item/fila de "vivo" zera a sombra; esquecer isso trava o produto para sempre. `janela_*` é `time` gravado
  `HH:MM:00` (o MariaDB devolve com segundos; o model corta em `HH:MM`).
- **Rodadas: o intervalo conta do INÍCIO da rodada** (`proximo_em` = início + intervalo) e a rodada nova nunca começa
  com um `publicando` na fila (a anterior precisa terminar). Dentro da rodada, vários publicam juntos.
  `rodada_iniciada_em`/`rodada_inicios` guardam a rodada em curso: enquanto `proximo_em` está no futuro, a rodada
  recebe as vagas que faltam (as que o teto do minuto ou um editor aberto seguraram); passado o intervalo, ela acabou,
  cheia ou não. Item que não chega a publicar (`precisa_revisar`, recusa do `iniciar()`) NÃO gasta vaga
  (`devolverVaga`); se era ele quem abria a rodada, ela nem conta e o próximo abre outra na hora. Teto GLOBAL de 2
  inícios por minuto (contador em cache por minuto, todas as contas): a rodada de 5 começa em ~3 min (2 + 2 + 1), e
  isso é de propósito — 3 workers consomem `high` em produção, e 5 publicações juntas segurariam os cliques de gente.
  `produtos_por_rodada = 1` é o passo antigo (o `FilaDePublicacaoTest` roda assim; as rodadas estão no
  `FilaEmRodadasTest`). Os defaults do BANCO ficam no passo antigo (`produtos_por_rodada` 1, `intervalo_minutos` 10:
  linha criada fora do serviço anda um por vez); quem vale é a config (5 e 20), gravada pelo serviço ao criar a fila.
  As colunas vieram numa migration SEPARADA (`2026_10_10_140000`, aditiva) porque a de criação já tinha rodado no
  MariaDB local — editar a criação deixaria o local sem as colunas (o `hasTable` pula).
- **Erro de CONTA pausa sem enviar nada** (sem token, fora de `contas_liberadas`, token de outro vendedor que o da
  conferência); **erro do ITEM vira `precisa_revisar` e a fila segue NA MESMA passada** (revisão ou plano diferentes do
  agendado, conferência vencida, avisos sem "Estou ciente", conferência sem `sellerId`). Editor do produto aberto = o
  item espera a próxima passada e a fila tenta o seguinte. O `iniciar()` confere tudo de novo (defesa dupla).
- **`digital` no agendamento** (`resumo.digital`: título efetivo de cada tipo + preço efetivo de cada cor): o preço da
  Precificação mudou no Portal depois da conferência → `precisa_revisar` ANTES de publicar. Sem isso o `prepararItens`
  pegaria o plano diferente e a publicação nasceria e morreria FAILED (sem POST, mas suja o histórico).
- **Fechar o item `publicando` roda em TODA fila** (viva, pausada ou cancelada): a publicação termina sozinha. Passou de
  40 min ainda RUNNING → a fila PAUSA com aviso, o item fica `publicando` até a publicação de fato terminar.
- **Quem agendou é o ATOR** (`AtorDoPortal::daEquipe`, vai para `pub_publicacoes.ator` e para a tarefa das alavancas).
  `User` usa SoftDeletes: apagado não zera `criada_por`; `FilaPublicacaoService::autorValido` confere existência e
  `active`, e quem retoma assume.
- **Não mexer no produto enquanto agendado:** `NaFilaDePublicacao` (irmão do `EditorEmUso`) faz o preparo pela IA
  adiar (o preparo inteiro e a escrita de cada etapa) e o Sincronizar tirar o produto do `para_preencher`. Se mesmo
  assim algo escrever (Job que já tinha passado da checagem), o item vira `precisa_revisar` — nunca publica diferente.
- **"Conferir selecionados" ESCREVE** quando a faixa de preço exige frete grátis (a regra do editor, no servidor:
  `envio.frete_gratis = true`, revisão sobe). Por isso produto na fila não confere de novo. A marca "conferindo…" é
  cache (`publicador:lote:conferindo:{produto}`), apagada no `finally` e no `failed()` do Job.
- **Imagens por IA automáticas: pronto e DESLIGADO** (`publicador.criativos_auto.ativo=false`). Condições e o que o dono
  do Creative Engine precisa decidir em `.planning/coordenacao/261010-criativos-automaticos.md`; o elo entra na cadeia
  do preparo SÓ com a chave ligada (a cadeia de 3 Jobs do `PreparoIaAoSalvarNoPortalTest` continua a mesma).
- **Prova no MariaDB 10.4 local** (`--path` só da `2026_10_10_100000`): up → rollback → up, DONE ×3, lote 141; nomes
  `pubfila_*`/`pubfilai_*` no `SHOW CREATE TABLE`; 2ª fila viva na conta e mesmo produto vivo = 1062; terminadas (NULL)
  repetem; FK inexistente = 1452; `resumo` não-JSON = 4025; `time` volta `'08:00:00'`; CASCADE da fila apaga os itens.
  DML em transação desfeita (0 linhas). As tabelas ficam criadas no local. A `2026_10_10_140000` (rodadas): up →
  rollback → up no MariaDB local, lote 143; `SHOW CREATE TABLE` com `produtos_por_rodada` smallint DEFAULT 1 logo após
  `intervalo_minutos`, `rodada_iniciada_em` datetime NULL e `rodada_inicios` DEFAULT 0 após `proximo_em` (o `after`
  de coluna nascida no mesmo ALTER funciona); rollback tira as três.
- **Deploy:** `migrate --force` (2 migrations: 2 CREATE + 1 ALTER aditivo na tabela que acabou de nascer); `queue:restart` (`ConferirEmLoteJob` na `high`; os de imagem na
  `creative`); `npm run build`; **o cron `* * * * * php artisan schedule:run` precisa rodar na VPS** — sem ele a fila
  nunca anda (o `onOneServer` usa a trava do cache: Redis em produção). Antes de agendar de verdade, só a #459.
  **Deployado 10/10/2026 `827d5a96`** (4 migrations, lote 175). Um `production.ERROR` 1146 "pub_fila_publicacao_itens
  doesn't exist" + "Scheduled command publicador:fila-publicacao failed" às 11:14 é da JANELA do deploy: o `deploy.sh`
  troca o código antes do `migrate`, e o `schedule:run` daquele minuto já viu o comando novo sem a tabela. Uma vez só;
  depois do `migrate` roda com exit 0. Não é regressão — só investigar se repetir depois do deploy.
- Testes que dependem de `Storage::fake` (`MlbPublicadorAcessoTest`, `CapaDoKitTest`) falharam UMA vez rodando ao lado de
  outro phpunit e passaram sozinhos. JS: `estrutura-grade-glide` "Características secundárias nasce recolhido" é falha
  antiga (o `bbb67657` abriu as secundárias e o teste não acompanhou), não desta entrega.

## 21. Teste de ponta a ponta em produção na #459 — o que só apareceu lá (10/10/2026)

Rodado depois do deploy `827d5a96`: o cliente cadastra pelo Portal (HTTP com o link de equipe, mesmas rotas JSON da
ficha), Planejamento, Precificação, IA, conferência. O que não se deduz do código:

- **A #459 publica na MGSTOREL, loja REAL** (`ml_user_id` 1555596317, `5_green`, 52 vendas). Os 7 anúncios de teste de
  03–07/10 nunca foram fechados e estavam `under_review [waiting_for_patch]`: 6 por infração `DOMAIN` ("título e/ou
  fotos não correspondem ao produto" — fotos/dados de teste) e 1 por `LENGUAJE` ("criado-mudo" no título que a IA
  escreveu). Ler o motivo: `GET /moderations/infractions/{user_id}?related_item_id={MLB}`. Fechar (`PUT /items/{id}
  {"status":"closed"}`) item moderado responde 200 mas ele vira **`inactive`** (segue `waiting_for_patch`), não `closed`.
  Publicar com foto gerada/placeholder nessa conta = infração nova quase certa: só com foto real, e fechar no fim.
- **Modalidade de envio da conta = `drop_off` (Correios)**, lida SÓ pela cotação real ("Cotar agora"; antes disso o
  padrão também é o dos Correios). Limites 30 kg / soma 200 / maior lado 100 → mesa de 165 cm, escrivaninha de 125 cm
  e todo combo/kit empilhado viram ME1: sem cotação do ML e sem frete sugerido, e o V-SAL-08 trava até digitarem o
  frete. Numa conta com coleta (`cross_docking`, 50/300/200) os mesmos produtos seriam ME2. Não é defeito.
- **Modelo "user products"**: cada COR vira um anúncio próprio (família por `family_name`). A cadeira com 2 cores = 4
  anúncios (2 cores × Clássico/Premium), não 2 — "5 produtos por rodada" pode significar 20 POSTs.
- **Garantia bloqueava tudo que vinha do Portal** (V-SAL-05: o Portal não pergunta e não existia padrão). Agora há a
  garantia padrão da conta (`GarantiaPadrao`, tela de Publicação em lote): `configuracoes`
  `publicador_garantia_padrao:{empresa-N|company-N}` — gravada nas DUAS âncoras da conta, lida pela do produto —,
  aplicada no `PortalParaRascunhoService::concluir` e, ao salvar, nos rascunhos que já existem (pula editor aberto e
  produto na fila de publicação). Só entra onde NÃO há garantia; sobe a revisão. Tipos do ML iguais em todas as
  categorias sondadas: 2230280 vendedor, 2230279 fábrica, 6150835 sem garantia; unidades dias/meses/anos.
- **A garantia padrão ACOMPANHA o padrão** (pedido do usuário, 10/10: "uma empresa tem 7 dias, outra 90 — todos os
  anúncios dela vão ser assim"): `GarantiaPadrao::aplicar` troca a garantia que ainda é a do padrão — marca
  `step_state.garantia_padrao` (o que o padrão gravou) — ou, sem marca, a que é EXATAMENTE o padrão anterior (os 24
  rascunhos da #459 receberam o padrão antes da marca). A escolhida no editor fica. Anúncio já publicado não muda (o
  ML guardou o `sale_terms` dele).
- **Permissão só por setor**: `User::hasPermission` = admin ou `SetorPermissao` dos setores — não há permissão por
  pessoa. O responsável padrão das alavancas precisa de `mlb.alavancas` (`definirResponsavelPadrao` recusa sem), então
  pôr o Kaio (#5, setor "Publicação") exige dar a chave ao setor inteiro ou criar um setor só para ele — decisão de
  visibilidade que o usuário disse ainda não ter (10/10).
- **Termos vetados pelo ML** (`TermosVetados`; lista em `PADRAO` + `publicador.termos_vetados`): a IA troca
  (`RegrasDoTitulo::limpar`, `PalavrasChaveService::filtrarModelo`, `DescricaoIaService::gerar`,
  `IaParaRascunhoService`) e o digitado trava — V-TIT-05 (título) e V-DES-05 (descrição), também no
  `bloqueiosSemSchema` (o `BloqueiosSemSchemaTest` cobra a paridade). Fora do Laravel (teste de unidade puro) a lista
  é a `PADRAO`. Termo novo descoberto numa infração entra na config.
- **Marca em lista fechada**: em Escrivaninhas (MLB193946) e Mesas para PC (MLB439418) o BRAND vem com `values` (5
  marcas) e o Portal só oferece lista onde há opções — o cliente não consegue informar a marca dele. Decisão pendente.
- Fila: ver §16 (a `default` travada pelo Adman/Acervo atrasou o produto e a IA; Jobs do fluxo foram para a `high`).
- **Publicação real (10/10, 13:28, autorizada pelo usuário, depois do deploy `b7c00ac5`):** cadeira E2E (rascunho 30)
  com título de teste e estoque 1, pela FILA (rodada de 1): 4 anúncios criados em segundos (MLB7784252490/…311616
  Clássico, …252514/…241448 Premium; o ML acrescenta a cor no fim do título), tarefa de alavancas #1 com prazo 13/10
  (pulou o feriado de 12/10) e aviso no sino, 4 ciclos de promoção agendados para 3 min depois. O Premium Cinza caiu em
  `DOMAIN` (foto desenhada) no 1º minuto; a promoção dele esperou e, com o anúncio encerrado, cancelou sozinha.
- **Promoção automática em anúncio NOVO não pega:** os 3 ativos voltaram `recusada` — "No candidates found for item";
  `GET /seller-promotions/items/{id}?app_version=v2` = `[]` e a conta não tinha nenhuma campanha. O ML não oferece
  desconto para o anúncio recém-criado; hoje a recusa é final e o item "central_promocao" da tarefa fica pendente
  para a pessoa. Se a decisão for insistir, é retentar dias depois (não 3 min) — decisão do usuário.
- Fechamento: os 3 ativos foram a `closed`; o moderado foi a `inactive` (ver acima). O responsável das alavancas foi
  #1 só durante o teste e voltou a NULL; a tarefa #1 ficou aberta para o usuário ver a tela.

## 22. A fila `high` é de quem espera, e o preparo pela IA a ocupa por horas (10/10/2026)

**Medido no `storage/logs/worker-high.log` da produção em 10/10:**

| Job | Duração |
|---|---|
| `SincronizarProdutoDoPortalJob` | ~0,4 s |
| `PrepararProdutoNoPublicadorJob` | ~0,3 s |
| `GerarPreparoIaJob` | 53 s a 2 min 20 s |
| `PublicarRascunhoJob` | ~3 s |

O preparo de UM produto é uma cadeia de 3 a 4 `GerarPreparoIaJob` (título → Modelo → descrição…). Dá 5 a 7 min por
produto: o "IA em ~7 min" do teste da §21.

**Quem divide a `high`:**
- São 3 consumidores: `ecf-worker` ×2 (`high,default`) e `ecf-worker-high` ×1 (só `high`).
- Na mesma fila rodam o código de acesso do Portal (`PortalCodigoDeAcesso`, com o cliente parado esperando), a
  publicação, o Sincronizar ao salvar, a Clicksign, o warm do Desempenho e o clique do Mapeamento.

**Conta, para 60 produtos importados de uma vez** (60 é o teto do preparo por empresa por dia,
`preparo_ia.limite_diario_por_empresa`):
- 60 cadeias × ~5,5 min ≈ 330 min de worker. Divididos por 3, dão **quase 2 h de `high` ocupada**.
- O que entra na fila nesse tempo espera até ~30 min: ~60 elos na frente × 1,5 min ÷ 3.
- A cadeia põe o elo seguinte no **fim** da fila. Por isso ela fica com ~1 elo por produto pendente durante quase toda
  a janela.
- Duas empresas importando no mesmo dia dobram tudo isso.
- Já acontece com a planilha. Com o Bling seria igual (seed `261010-integracao-bling-erp.md`, risco 5).

**Feito em 10/10 (autorizado pelo usuário: "pode subir sim, pode fazer"):**
- **Fila própria:** `publicador.preparo_ia.fila` (`PreparoIaAgenda::fila()`, padrão `publicador-ia`) recebe o
  `PrepararProdutoNoPublicadorJob` (a entrada, 2 min depois do save), cada `GerarPreparoIaJob` e o `Bus::chain`.
- **Fica na `high`:** o `SincronizarProdutoDoPortalJob`, que leva o produto ao Publicador "na hora", e os botões de
  IA em que alguém clica e espera (descrição, palavras-chave, sugestão de kit, análise do anúncio).
- **Programa do supervisor:** `/etc/supervisor/conf.d/ecf-worker-ia.conf` (`ecf-worker-ia`, 3 processos,
  `--queue=publicador-ia --timeout=600`, `www-data`, log `storage/logs/worker-ia.log`). É uma cópia do
  `ecf-worker-high`.
  - Entrou com `supervisorctl reread` e depois `supervisorctl update ecf-worker-ia`, **com o nome do grupo**: o
    `update` sem argumento aplicaria TODA mudança pendente em disco e reiniciaria outros grupos.
  - Provado antes do deploy: um preparo de produto inexistente, posto à mão em `publicador-ia`, saiu `DONE` em 2 s.
- **Provado em produção depois do deploy `de4fe589`** (save de descrição na E2E-MJ da #459, pelo Portal, 16:07:39):
  Sincronizar na `high` às 16:07:54 (245 ms); `PrepararProdutoNoPublicadorJob` na `publicador-ia` às 16:09:40; 6
  `GerarPreparoIaJob` em duas cadeias paralelas (o produto tem 2 produtos no Publicador), de 12 s a 1 min 14 s,
  fechando às 16:12:34. Nenhum Job de IA passou pela `high`, e ela ficou vazia o tempo todo.
- **O `deploy.sh` só reinicia `ecf-worker:*`.** O `queue:restart` depois dele pega também o `ecf-worker-ia`.
- **Válvula de emergência:** se o programa sumir (servidor refeito, conf perdida), o preparo para calado.
  `PUBLICADOR_PREPARO_IA_FILA=high` + `config:cache` devolve o que for despachado a partir daí; o que já estiver em
  `publicador-ia` espera o programa voltar.
- **Capacidade:** a VPS tem 16 GB (11,6 GB disponíveis) e 4 CPUs; os 6 workers de antes somavam 478 MB (~80 MB
  cada).

**Lições:**
- **`Bus::chain(...)->onQueue()` NÃO move elo que declara fila.** `PendingChain` faz
  `$firstJob->queue = $firstJob->queue ?: $this->queue`, e o `Queueable` faz `$next->queue ?: $this->chainQueue`. O
  `GerarPreparoIaJob` declara `high` no construtor, e o `AvaliarCriativosAutomaticosJob`, `creative`. Trocar no
  construtor.
- **O programa novo do supervisor nasce ANTES do deploy do código.** Job numa fila sem consumidor fica parado, calado.
  A config do supervisor vive só na VPS (`/etc/supervisor/conf.d/`); ela não está no repositório.
- **A `creative` (3 workers, quase ociosa: 0 jobs em 10/10, 53 no dia mais cheio da semana) não serve.** Ela é do
  estúdio de imagens, que é interativo, e a fila é FIFO: o kit de 7 imagens esperaria atrás da IA de texto.

## 23. Excluir produto do Publicador: só o que nunca foi publicado e já está solto do Portal (10/10/2026)

Pedido do usuário para limpar os produtos de teste. O serviço é o `ExcluirProdutoService`, com as rotas
`publicador.produtos.exclusao.previa` e `publicador.produtos.exclusao`. Na lista de Produtos entram "Excluir produto…"
no menu ⋯ e "Excluir selecionados" na barra da seleção.

**O que só se descobre lendo o módulo inteiro:**

- **Produto ligado ao Portal VOLTA.** Quem cria `pub_produtos` é o `PublicadorSincronizaPortalService::sincronizar`,
  para toda oferta e todo grupo sem produto. Ele roda no botão e, sozinho, a cada save do cliente (~15 s, pelo
  `SincronizarProdutoDoPortalJob`).
  - Não existe "ignorado" pronto.
  - Por isso a regra é só o SOLTO (`oferta_id` e `estrutura_produto_id` nulos), e a ordem de uso é excluir no Portal
    primeiro (lá o item fica solto, D27) e depois aqui.
- **"Nunca publicado" se decide pelo FATO, não pelo status.** Timeout ou 5xx vira item `UNKNOWN` e, depois de 2
  tentativas, `FAILED`, com o rascunho de volta a `DRAFT`. O anúncio pode ter nascido. A regra está em
  `itemPodeEstarNoMl`:
  - pode estar no ML: `ml_item_id` preenchido, `CREATED`, `SENT`, `UNKNOWN`, ou `FAILED` já tentado sem resposta
    4xx;
  - não está: `FAILED` com 4xx, que é recusa certa.
  - O `IaParaRascunhoService::intocavel` NÃO cobre `SENT`, `UNKNOWN` nem o `FAILED` incerto.
- **Publicado nunca pode sair.** A cascata de `pub_rascunhos` leva `pub_publicacoes` e `pub_publicacao_itens`, que são
  o único registro do que foi enviado (MLB, payload, resposta).
- **Os arquivos das fotos não caem na cascata.** `pub_imagens` cai, mas o arquivo em
  `storage/app/private/publicador/{rascunho}/…` só some por `Storage::delete`. O serviço coleta os caminhos antes do
  DELETE e apaga depois do commit.
- **`pub_fila_publicacao_itens.produto_ativo` não tem FK.** É a coluna-sombra do item vivo. Excluir um produto na fila
  deixaria o item "vivo" apontando para id morto, então o DELETE é condicional (`whereNotExists` nessa coluna). Item
  já terminado fica, com `produto_id` nulo.
- **A base de kit da Fase N só sai junto com os kits.** `pubprod_base_fk` é SET NULL e soltaria o kit calado. Por
  isso os kits são processados primeiro.
  - O "é base" vai num SELECT à parte: como subconsulta no DELETE da própria `pub_produtos`, o MariaDB dá o erro
    1093.
- **Ordem das travas:** rascunho e depois produto (`lockForUpdate`), a mesma de `PublicacaoService::iniciar`. O
  SQLite ignora a trava.
- **Cada produto na sua transação.** A falha de um não desfaz os outros. A resposta traz `excluidos` e `recusados`
  com a regra (`EXC-01` a `EXC-08`).

**Provado no MariaDB local (10.4), em transação desfeita:**
- o DELETE condicional;
- a cascata completa;
- a base recusada sozinha (`EXC-06`) e excluída junto com o kit.

**Sobre o banco local:** ele estava sem `2026_10_08_120000_add_fases_to_pub_produtos` e tem outras migrations do outro
dev pendentes. Para provar algo ali, rode só a migration necessária, com `--path`.

**`deploy.sh` recusa árvore suja.** "Há mudanças não commitadas" sai com exit 1 antes de tocar no servidor. Com
trabalho pela metade na mesma árvore, ou termina e commita, ou não deploya.
