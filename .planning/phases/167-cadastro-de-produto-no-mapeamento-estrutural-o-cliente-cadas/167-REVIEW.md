---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
reviewed: 2026-10-06T17:25:22Z
depth: standard
diff_base: 8bbfc5e2
files_reviewed: 61
files_reviewed_list:
  - app/Http/Controllers/PortalEstruturaController.php
  - app/Http/Controllers/PortalEstruturaProdutosController.php
  - app/Http/Middleware/RestringeDominioDoPortal.php
  - app/Models/EstruturaAmbiente.php
  - app/Models/EstruturaFamilia.php
  - app/Models/EstruturaOferta.php
  - app/Models/EstruturaProduto.php
  - app/Models/EstruturaProdutoVariacao.php
  - app/Models/EstruturaProdutoVolume.php
  - app/Services/Portal/Estrutura/EstruturaConjunto.php
  - app/Services/Portal/Estrutura/EstruturaOfertaService.php
  - app/Services/Portal/Estrutura/EstruturaPrecificacaoService.php
  - app/Services/Portal/Estrutura/EstruturaVisaoService.php
  - app/Services/Portal/Estrutura/Produtos/FreteMe2Service.php
  - app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php
  - app/Services/Portal/Estrutura/Produtos/LeitorPlanilhaProdutos.php
  - app/Services/Portal/Estrutura/Produtos/ListasDaEmpresaService.php
  - app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php
  - app/Services/Portal/Estrutura/Produtos/ModeloProdutosXlsx.php
  - app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php
  - app/Services/Portal/Estrutura/Produtos/NumeroBr.php
  - app/Services/Portal/Estrutura/Produtos/PendenciasDoProduto.php
  - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
  - app/Services/Portal/Estrutura/Produtos/ProdutoCustos.php
  - app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php
  - app/Services/Portal/Estrutura/Produtos/TabelaFreteEcf.php
  - app/Services/Portal/Estrutura/Produtos/VolumesTexto.php
  - app/Support/Portal/ModulosPortal.php
  - config/estrutura_produtos.php
  - database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php
  - database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php
  - routes/web.php
  - resources/js/Pages/Portal/EstruturaProdutos.jsx
  - resources/js/Pages/Portal/EstruturaProdutoFicha.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js
  - resources/js/lib/produtosEstrutura.js
  - resources/js/lib/produtosNavegacao.js
  - resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoGrande.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoProdutoLinha.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoVolume.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/FaixaCalculados.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/JanelaListas.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/ListaProdutos.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/PickerLista.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/SeletorVisualizacao.jsx
  - resources/js/Components/Portal/Estrutura/ComoFunciona.jsx
  - resources/js/Components/Portal/Estrutura/FormOferta.jsx
  - resources/js/Components/Portal/Estrutura/comum.jsx
  - resources/js/Components/SpreadsheetGrid.jsx
  - resources/js/lib/gradeTeclado.js
  - resources/js/Components/ui/sheet.jsx
  - resources/js/Pages/Portal/EstruturaLista.jsx
  - resources/js/Pages/Portal/EstruturaPrecificacao.jsx
findings:
  critical: 5
  warning: 16
  info: 22
  total: 43
status: issues_found
---

# Fase 167: Revisão de Código

**Revisado:** 2026-10-06
**Profundidade:** standard, em duas frentes paralelas: backend (32 arquivos) e frontend (29 arquivos), pelo diff `8bbfc5e2..HEAD`
**Status:** issues_found

## Consolidado

| Severidade | Backend | Frontend | Distintos |
|---|---|---|---|
| BLOCKER | 2 | 4 | **5** (BE-CR-01 e FE-CR-01 são o mesmo defeito) |
| WARNING | 8 | 8 | 16 |
| INFO | 8 | 14 | 22 |

**Os 5 que bloqueiam o envio:**

1. **BE-CR-01 / FE-CR-01:** "Novo produto" cai dentro de um produto existente pelo `grupo`. O produto existente é renomeado e perde a categoria.
2. **BE-CR-02:** reimportar a planilha rebaixa a categoria confirmada para texto, e "SEM MEDIDAS" apaga os volumes digitados (contra o D-14).
3. **FE-CR-02:** o voltar do navegador descarta a ficha sem perguntar.
4. **FE-CR-03:** esvaziar Valor, Eixo ou Custo não grava, e a tela diz "Produto salvo.".
5. **FE-CR-04:** a variação nova mostra um custo e eixo e grava os da 1ª variação já atualizada.

**Conferido sem achado:**
- isolamento por empresa em todo acesso por id;
- allowlist com id numérico ancorado;
- migration do ALTER em `estrutura_ofertas` (idempotente, `nullOnDelete`, sem backfill);
- preço igual ao de antes para oferta sem produto;
- frete nunca gravado como preço;
- nenhum token do ML exposto;
- `urlDeVolta` sem redirecionamento aberto;
- storage em try/catch;
- componentes compartilhados sem regressão.

---

## Parte 1 — Backend

**Revisado:** 2026-10-06T17:25:22Z
**Profundidade:** standard (com rastreio das chamadas que a fase passou a fazer: `EstruturaOfertaService`, `MercadoLivreService::getMany`, `CategoriaSugestaoService`, `useFichaProduto.js`/`produtosEstrutura.js` para o contrato do POST de linhas)
**Arquivos revisados:** 32 (os já existentes antes da fase só pelo diff `8bbfc5e2..HEAD`)
**Status:** issues_found

### Resumo

O isolamento entre empresas está correto em todos os acessos por id. A allowlist nova também está certa: a regex é ancorada (`\A…\z`), aceita só dígitos e é igual ao `Request::is()` antigo. A migration do ALTER em `estrutura_ofertas` também passa. Ela é idempotente, os nomes ficam abaixo de 64 caracteres, a FK `nullOnDelete` está sobre coluna nullable, não há json, enum nem `timestamp()` solto, e o `down()` só tira o vínculo. A Precificação dá o mesmo preço de antes para oferta sem produto. O frete nunca é gravado como preço (D-19). E não sai token do ML para o cliente nem para o log.

Os dois problemas que bloqueiam são de **integridade do catálogo**:

1. A ficha de "Novo produto" usa o código da 1ª variação como `grupo`. O backend casa esse `grupo` com um produto que já existe. A variação nova então entra no produto de outra pessoa e sobrescreve o nome e a categoria dele.
2. Reimportar a planilha **apaga trabalho feito no sistema**: a categoria do ML confirmada volta a texto "a confirmar", e "SEM MEDIDAS" apaga os volumes já digitados.

Os avisos se concentram em quatro pontos. A leitura do .xlsx não tem limite antes de montar a matriz inteira. A importação dá retorno falso: erros de gravação somem. O nome das ofertas irmãs fica velho. E há chamadas HTTP e trabalho por linha dentro de uma transação longa.

Verificação feita no MariaDB 10.4.32 local, com o `sql_mode` que o Laravel aplica (`ONLY_FULL_GROUP_BY,…`). O `$produto->variacoes()->max('ordem')` (relação com `orderBy`) **compila sem ORDER BY** e roda sem o erro 1140. Por isso não entrou como achado: o 1140 aparece no SQL cru com ORDER BY, mas o Laravel não gera esse SQL aqui.

### Critical Issues

#### BE-CR-01: "Novo produto" pela ficha cai dentro de um produto existente e sobrescreve nome e categoria dele

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:386-389` (com `:397-418`, `:444-460`, `:545-554`); origem do `grupo` em `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:147-160`

**Problema:** no produto novo, a ficha manda `grupo = código da 1ª variação` (useFichaProduto.js:149). Em MODO_GRADE, sem `id` nem `produto_id`, o backend procura esse `grupo` em `produtos_codigo` e, se achar, **usa o produto existente**. Como ele ainda não está em `definidos`, a linha vira `primeiraDoProduto`. A partir daí:
- o nome do produto existente é trocado pelo da ficha nova (`:410`, `:455-458`);
- a categoria é sobrescrita. A ficha nova sempre manda `categoria_texto: ''` (`produtosEstrutura.js:209-212`, `alterou()` é sempre verdadeiro sem `_base`), e `resolverCategoria` então **zera** `categoria_ml_id`/`nome`/`caminho`;
- a variação nova é pendurada no produto alheio e copia eixo, custo e volumes da 1ª variação dele (`:480-506`);
- como a variação é nova, só a oferta dela é criada (`:545-546`). As ofertas irmãs continuam com o nome antigo (ver BE-WR-03).

O `codigo` do produto fica "órfão" com facilidade, porque nunca acompanha a variação. Ele nasce como o código da 1ª variação (`:445`), mas renomear ou excluir essa variação não o altera. Também pode vir de uma coluna "Grupo" da planilha que ninguém usa como Ref.

**Cenário concreto:**
1. O produto P nasce pela ficha com Ref `A1`, e `P.codigo` vira `A1`.
2. A pessoa renomeia a Ref para `B1` (ou exclui `A1` e mantém `A2`).
3. Depois ela cria "Novo produto" com Ref `A1` e nome "Mesa Nova".

Resultado: nenhum produto novo é criado. P passa a se chamar "Mesa Nova", perde a categoria confirmada e ganha a variação `A1`. O valor antigo não fica registrado em lugar nenhum: o `produtos_gravados` só guarda totais.

**Correção:** o `grupo` que vem da ficha só deve juntar as linhas **do próprio lote**, nunca casar com produto que já existe. No backend:
```php
// gravarLinha, MODO_GRADE: grupo só reaproveita produto criado NESTE lote
if ($produto === null && $campos['grupo'] !== null) {
    $idDoGrupo = $estado['produtos_codigo'][self::chaveCodigo($campos['grupo'])] ?? null;
    if ($idDoGrupo !== null && $modo === self::MODO_GRADE && isset($estado['existiam'][$idDoGrupo])) {
        throw ValidationException::withMessages(['codigo' => "Já existe um produto com o código {$campos['grupo']}. Abra a ficha dele para adicionar a variação."]);
    }
    $produto = $idDoGrupo !== null ? $estado['produtos'][$idDoGrupo] : null;
}
```
A alternativa é uma chave própria de lote (ex.: `lote_produto`) que não se confunda com o código do produto. Vale também atualizar `estrutura_produtos.codigo` quando a variação que deu origem a ele muda de código, ou deixar de derivar o código do produto do código da variação.

#### BE-CR-02: Reimportar a planilha apaga a categoria do ML confirmada e os volumes digitados no sistema

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:581-591`; `app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php:201-208`; `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php:244-258`; `app/Services/Portal/Estrutura/Produtos/VolumesTexto.php:29-31`

**Problema:** D-14 define reimportar como "acrescentar e atualizar […] nada é apagado". Dois caminhos violam isso:

1. **Categoria.** Uma célula "Categoria ML" com nome (o normal na planilha real) vira `categoria_texto`. Em `resolverCategoria`, texto sem id grava `categoria_ml_id = null`, `categoria_ml_nome = texto` e `caminho = null`. Isso vale **mesmo quando o texto é igual ao nome da categoria já confirmada**. O D-06 manda que texto nunca vire id, então a importação só consegue rebaixar.
2. **Volumes.** "SEM MEDIDAS" é o marcador que o próprio modelo ensina a usar ("Escreva SEM MEDIDAS quando o produto ainda não tem medidas", `ModeloProdutosXlsx.php:88`). `VolumesTexto` devolve `volumes: [] / valido: true`, o normalizador marca `volumes` como presente com lista vazia, e `gravarLinha` apaga todos os volumes da variação (`:521-534`).

**Cenário concreto:** a pessoa importa a planilha do Emerson, com categorias em texto e várias linhas "Sem medidas". Depois escolhe no sistema a categoria real de cada um dos ~56 produtos e digita as medidas que faltavam. Uma semana depois, reimporta a mesma planilha só para atualizar custos. Todas as categorias voltam a "a confirmar" e todas as medidas digitadas somem. A prévia lista "categoria"/"volumes" em `mudou`, mas a pessoa não tem como prever que um nome idêntico apaga a categoria.

**Correção:** em MODO_IMPORTACAO, texto nunca substitui uma categoria com id, e "SEM MEDIDAS" funciona como célula em branco.
```php
// resolverCategoria — texto só preenche quando o produto não tem categoria com id
if ($id === null) {
    if ($produto && trim((string) $produto->categoria_ml_id) !== '') {
        return []; // importação não rebaixa categoria confirmada
    }
    ...
}
```
```php
// NormalizadorDeLinha — "sem medidas" não é presença
} elseif (($t = self::texto($bruta['volumes_texto'] ?? null)) !== '' && Str::lower(Str::ascii($t)) !== 'sem medidas') {
```
Ajustar também `ImportadorProdutos::diferencas` para a prévia não mostrar mudança nesses casos.

### Warnings

#### BE-WR-01: Leitura do .xlsx monta a matriz inteira antes de checar o limite (OOM/DoS com arquivo de poucos KB)

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/LeitorPlanilhaProdutos.php:55-69, 120-122`

**Problema:** `load()` carrega **todas** as abas. `readEmptyCells` está no padrão (`true`), então cada `<c r="…"/>` vazio vira célula. Em seguida `toArray()` monta `A1:{maiorColuna}{maiorLinha}` (PhpSpreadsheet 2.4.5, `Worksheet.php:3124-3139`). O teto de 1.000 linhas só é conferido **depois**, e o `catch (Throwable)` não pega estouro de memória, que é fatal.

**Cenário concreto:** um .xlsx de poucos KB com uma célula vazia em `XFD1048576` faz o `toArray` tentar alocar 17 bilhões de posições. Um arquivo real com 300 mil linhas repetitivas comprime para menos de 2 MB e estoura `memory_limit` do mesmo jeito. Em ambos os casos o worker do PHP-FPM morre com 500. O throttle (10/min na prévia) limita, mas não impede.

**Correção:**
```php
$leitor->setReadDataOnly(true);
$leitor->setReadEmptyCells(false);
$info = collect($leitor->listWorksheetInfo($caminho))->firstWhere(fn ($i) => Str::lower(trim($i['worksheetName'])) === 'produtos')
    ?? $leitor->listWorksheetInfo($caminho)[0];
if ($info['totalRows'] > self::MAX_LINHAS + 1 || $info['totalColumns'] > 60) { return $vazio('…'); }
$leitor->setLoadSheetsOnly([$info['worksheetName']]);
$leitor->setReadFilter(new class implements IReadFilter { public function readCell($c, $r, $w = ''): bool { return $r <= 1001 && Coordinate::columnIndexFromString($c) <= 60; } });
// e depois rangeToArray num intervalo fixo, nunca toArray()
```

#### BE-WR-02: Fórmula em coluna de texto é gravada literalmente como código/nome/grupo

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/LeitorPlanilhaProdutos.php:69, 151-173`

**Problema:** `toArray(null, false, …)` devolve `$cell->getValue()`, que numa célula com fórmula é o texto `"=…"` (`Worksheet::cellToArray`, `:2885`). O comentário só pensou no custo. Em `codigo`/`grupo`/`nome`/`familia`/`ambientes`, a fórmula passa pelo normalizador como texto válido.

**Cenário concreto:** o cliente monta a Ref no modelo com `=B2&"-"&C2`. A importação cria a variação de código `=B2&"-"&C2` e a oferta com esse SKU na Lista SKUs. Isso vale para todas as linhas com a mesma fórmula, e a 2ª linha em diante cai em "código repetido". A planilha real do Emerson não tem fórmulas na aba Produtos (conferido localmente, só a contagem). O risco vem dos arquivos que os clientes montam.

**Correção:** para célula com fórmula, usar o valor em cache que o Excel salvou (`$cell->getOldCalculatedValue()`, sem executar nada) ou recusar a linha com uma mensagem clara. Exemplo: `rangeToArray` próprio que, quando `$cell->isFormula()`, usa `getOldCalculatedValue()` e, se vier `null`, vira erro "Ref com fórmula — cole como valor".

#### BE-WR-03: Nome das ofertas irmãs fica desatualizado quando o produto é renomeado numa linha que cria variação

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:544-554`

**Problema:** a sincronização de todas as variações do produto só roda no `elseif`. Quando a linha que define o produto (`primeiraDoProduto`) também cria uma variação, `mudouProduto` é verdadeiro, mas só a oferta nova é criada. As ofertas das outras variações ficam com "Nome antigo — Valor". As linhas seguintes do mesmo produto não são `primeiraDoProduto` e, sem mudança própria, caem em `sem_mudanca` sem sincronizar.

**Cenário concreto:** na importação, a planilha traz `1014-3` (nova) antes de `1014-1` e `1014-2`, com "Produto" renomeado. O produto é renomeado, mas as ofertas `1014-1`/`1014-2` ficam com o nome antigo na Lista SKUs e no "Sincronizar do Portal" do Publicador (D16 herda o título da oferta). O mesmo acontece no BE-CR-01.

**Correção:**
```php
if ($variacaoNova) {
    $absorvidos += $this->criarOferta($empresa, $produto, $variacao, $ator);
}
if ($mudouProduto && ! $produtoNovo) {
    foreach (EstruturaProdutoVariacao::where('produto_id', $produto->id)->whereKeyNot($variacao->id)->get() as $v) {
        $absorvidos += $this->sincronizarOferta($empresa, $produto, $v, $ator);
    }
}
if (! $variacaoNova && $mudouVariacao && ! $mudouProduto) {
    $absorvidos += $this->sincronizarOferta($empresa, $produto, $variacao, $ator);
}
```

#### BE-WR-04: Importação esconde os erros de gravação e a prévia promete linhas que a aplicação recusa

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php:80-90`; `app/Http/Controllers/PortalEstruturaProdutosController.php:202`

**Problema:** `aplicar()` usa só `$res['totais']` e descarta `$res['erros']`, que traz as mensagens por linha. O controller responde "Importação concluída: X novos, Y atualizados." sem citar erros, nem os da própria prévia. A prévia não valida o que só falha na gravação:
- `MLB…` que não é folha: `resolverCategoria` lança `ValidationException` e **a variação inteira não é criada**;
- código que colide no unique do MariaDB (23000);
- Ref repetida dentro do mesmo arquivo: as duas aparecem como "novos", mas a 2ª sobrescreve a 1ª em MODO_IMPORTACAO.

**Cenário concreto:** a planilha traz o código de uma categoria pai (`MLB1574`). A prévia mostra o produto em "novos" e a importação diz "concluída", mas o produto não existe.

**Correção:** devolver `erros` (linha, código, mensagem) em `aplicar()` e mostrar no flash ou numa resposta JSON, por exemplo "N linhas não entraram: …". Na prévia, marcar a Ref repetida no arquivo e validar a categoria pelo mesmo `detalhe()` memoizado (em lote, fora de transação).

#### BE-WR-05: Chamada HTTP ao ML e trabalho por linha dentro de uma transação única de até 1.000 linhas

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:105, 413, 598-600`; `app/Services/Portal/Estrutura/EstruturaOfertaService.php:323-341` (chamado por `criarOferta` em toda variação nova)

**Problema:** `gravarLinhas` abre a transação e, linha a linha:
- valida a categoria no ML (`detalhe()` → `Http::pool` com timeout de 15 s, mais o app token) com a transação aberta;
- em cada oferta criada, `varrerEspera` carrega **todas** as linhas da espera da empresa com `sku_colado` e hidrata cada uma (`get()` e filtro no PHP).

A importação aplica até 1.000 linhas de forma síncrona na requisição web.

**Cenário concreto:** uma importação de 1.000 linhas, numa empresa com 500 linhas na espera, faz cerca de 1.000 consultas da espera e ~500 mil models hidratados, além de ~1.000 activity logs. Somadas às chamadas de categoria fora do cache, a requisição passa do timeout do nginx/php-fpm, e enquanto isso a transação segura locks de `estrutura_ofertas`. Outras escritas da mesma empresa (Lista SKUs) esperam até `innodb_lock_wait_timeout`.

**Correção:** antes de abrir a transação, resolver as categorias distintas do lote de uma vez (`categoriasEmLote`) e passar o mapa para dentro. Dentro do lote, criar as ofertas sem varrer a espera e chamar `varrerEspera($empresa, $todosOsSkus)` **uma vez** no fim. Considerar a importação de 1.000 linhas como job na fila (padrão do projeto para operação longa).

#### BE-WR-06: `cotarFretes` pode prender a requisição por minutos quando o ML degrada

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/FreteMe2Service.php:21-22, 165`; `app/Services/MercadoLivreService.php:349-383, 528-550`

**Problema:** o docblock diz que evita o `ClienteMlPublicador` "porque ele dorme dentro da requisição web". Mas o `getMany` usado aqui tem dois comportamentos que contradizem isso:
- o pool não define timeout, então vale o padrão de 30 s do cliente HTTP;
- **toda** resposta que falha no pool é refeita em série por `get()`, com `comRetry429` (até 3 × 8 s de `sleep`) e outro timeout de 30 s.

**Cenário concreto:** o ML responde 429 ou fica lento. São 12 cotações: 30 s no pool, mais 12 × (30 s + até 24 s) em série, o que passa de 10 minutos para um único POST `/fretes`. O resultado é um 504 e um worker preso. Com throttle de 20/min por usuário, poucos cliques ocupam vários workers.

**Correção:** para o frete, não usar o fallback em série. Uma opção é um `getMany(..., fallbackSerial: false, timeout: 8)`. Outra é chamar o `Http::pool` direto com `->timeout(8)->connectTimeout(3)` e tratar a falha como `tabela_ecf`/`falhou`, como o próprio serviço já faz.

#### BE-WR-07: N+1 nas listas de família/ambiente e no cache de frete, em toda tela e toda escrita

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ListasDaEmpresaService.php:62-73, 231-246`; `app/Http/Controllers/PortalEstruturaProdutosController.php:355-363`; `app/Services/Portal/Estrutura/Produtos/FreteMe2Service.php:53-61`

**Problema:**
- `lista()` faz um `count()` por item (`emUso`). `listasDaEmpresa()` roda em `index`, `renderFicha` e **em toda resposta JSON de escrita** (`gravarLinhas`, criar/renomear/excluir lista).
- `achar()` carrega a lista inteira da empresa a **cada nome** procurado. `resolverNomes` chama `achar` por nome em cada linha, e `criar()` chama de novo.
- `estimar()` faz um `Cache::get` por variação. Com o store `database`, é uma query por variação: 100 produtos por página × variações.

**Cenário concreto:** com 40 famílias e 30 ambientes, cada POST de linhas termina com 70 `COUNT` a mais. Um lote de 200 linhas com família e 2 ambientes faz cerca de 600 leituras da lista inteira.

**Correção:** `withCount('produtos')` para família e um `GROUP BY ambiente_id` único para ambientes. Carregar a lista uma vez por lote e indexar por `chave()` (um mapa em memória passado para `resolverNomes`). Em `estimar`, usar `Cache::many($chaves)`.

#### BE-WR-08: Migration de CREATE não é idempotente (learnings §6)

**Arquivo:** `database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php:29-101`

**Problema:** são seis `Schema::create` seguidos, sem `hasTable`. No MariaDB as FKs entram como `ALTER` separados depois do `CREATE`. Se qualquer passo falhar (FK, engine, permissão), as tabelas anteriores ficam criadas e a migration continua `Pending`. A próxima tentativa morre em "table already exists", e o `deploy.sh` (`migrate --force`) para em todo deploy seguinte até alguém intervir à mão. É exatamente o modo de falha descrito no learnings §6. A migration irmã do ALTER foi feita idempotente; esta não.

**Correção:** envolver cada `Schema::create` em `if (! Schema::hasTable('…'))` e acrescentar uma checagem de índice/FK no padrão `hasIndex`/`hasForeignKey` da migration 100100, ou documentar no runbook do deploy o `dropIfExists` em ordem reversa.

### Info

#### BE-IN-01: `mimes:xlsx` pode recusar .xlsx válido, conforme o libmagic do servidor

**Arquivo:** `app/Http/Controllers/PortalEstruturaProdutosController.php:184, 193`
**Problema:** `mimes:xlsx` decide pelo `guessExtension()` (finfo). Um OOXML cuja 1ª entrada do zip não é `[Content_Types].xml`/`_rels/.rels` é identificado como `application/zip`/`octet-stream`, e a validação falha. Não há precedente no projeto. O leitor já confere a assinatura `PK\x03\x04` e a extensão do nome, e usa o leitor `Xlsx` fixo.
**Correção:** testar em produção com exportações do Google Sheets, LibreOffice e Excel Mac. Se alguma falhar, trocar por `file|max:2048` + extensão + assinatura, que já existem.

#### BE-IN-02: `ordem` aceita até 18 dígitos, mas a coluna é SMALLINT UNSIGNED, e o erro derruba o lote inteiro só no MariaDB

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php:129-136, 279-289`; migration `:79`
**Problema:** `ordem: 70000` num POST montado à mão gera o erro 22003 (fora de faixa) no MariaDB estrito, que não é 23000. Ele é relançado em `gravarLinhas:124-126` e toda a transação dá 500. No SQLite passa.
**Correção:** limitar `ordem` a 1..65535 no normalizador e devolver erro da linha.

#### BE-IN-03: Medidas abaixo da precisão da coluna passam na validação e são gravadas como zero; dimensões vão ao ML arredondadas para inteiro

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php:347-355`; `FreteMe2Service.php:40`
**Problema:** "0,001" passa no `> 0` e vira `0.00` em `decimal(7,2)`. O peso "0,0004" vira `0.000`. O usuário vê "salvo", e a variação aparece como pendente ou sem peso. Na cotação, `round()` transforma lado < 0,5 cm em `0x…`, o ML devolve 400 e a cotação cai em `falhou`.
**Correção:** validar o mínimo representável (≥ 0,01 cm; ≥ 0,001 kg) e usar `max(1, round(...))` nas dimensões do ML.

#### BE-IN-04: O catch de SQLSTATE 23000 atribui qualquer violação de integridade a "código repetido"

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:123-131`
**Problema:** 23000 também cobre FK (1452), `epr_company_cod_uq` (código do **produto**) e `eo_variacao_uq`. A mensagem "O código X já existe em outro produto" engana nesses casos.
**Correção:** decidir pela `errorInfo[1]` (1062) e pelo nome do índice na mensagem. Nos demais casos, dar uma mensagem genérica e registrar no log.

#### BE-IN-05: O contrato de linha não permite tirar a família de um produto

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php:166-176`
**Problema:** família vazia não conta como presente, ao contrário de `ambientes: []` e `custo: null`. Uma vez definida, só pode ser trocada por outra, nunca removida. O front (`produtosEstrutura.js:201`) também não envia vazio.
**Correção:** aceitar `familia: null` explícito como "limpar", no padrão de `custo`.

#### BE-IN-06: A linha de exemplo do modelo é importada se o cliente não apagar

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ModeloProdutosXlsx.php:37-49`
**Problema:** `EXEMPLO-1` / "Mesa de exemplo" vira produto, oferta na Lista SKUs, a família "Linha Exemplo" e dois ambientes.
**Correção:** no `ImportadorProdutos`, ignorar (com aviso) a linha idêntica a `ModeloProdutosXlsx::EXEMPLO`.

#### BE-IN-07: `NumeroBr` em DINHEIRO lê "0.500" como 500

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/NumeroBr.php:71-75`
**Problema:** o regex de milhar `^\d{1,3}(\.\d{3})+$` aceita "0.500". Um custo de R$ 0,50 digitado com ponto vira R$ 500. Um número não começa com `0.` seguido de milhar.
**Correção:** exigir que o primeiro grupo não seja `0` (`^[1-9]\d{0,2}(\.\d{3})+$`).

#### BE-IN-08: Os models do `$estado` são alterados antes do savepoint confirmar; o rollback não os restaura

**Arquivo:** `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:117, 357, 454-459, 515-518`
**Problema:** o `$estado` é passado por valor, mas os `EstruturaProduto`/`EstruturaProdutoVariacao` dentro dele são objetos compartilhados. `fill()`+`save()` sincronizam o `original`. Se a linha falhar depois disso (o caminho 23000, que existe justamente porque a collation do MariaDB e a `chaveCodigo` podem divergir), o savepoint desfaz no banco, mas o objeto continua com os valores novos. A próxima linha do mesmo produto faz `fill` dos mesmos dados, não vê nada sujo e não grava. O produto fica com o nome antigo e a oferta não sincroniza, sem erro. É raro, mas silencioso.
**Correção:** no `catch`, recarregar do banco os models tocados (`$estado['produtos'][$id]->refresh()`, idem a variação) ou trabalhar com `replicate()` e só publicar no `$estado` em `aplicarNoEstado`.

---

### Conferido sem achado (para o orquestrador não refazer)

- **Isolamento por empresa:** `ficha`, `excluirVariacao`, `sugerirCategorias`, `cotarFretes`, `renomear`/`excluir` de lista, `gravarLinhas` (`id`/`produto_id`/grupo resolvidos só dentro de `carregar($empresa)`) e `EstruturaOfertaService::variacaoLigada` filtram por `company_id` e respondem 404/"não encontrado" para id de outra empresa. `variacao_id` não entra pelo `dadosOferta()` da Lista SKUs (`validate` devolve só as chaves declaradas).
- **Allowlist:** `liberado()` tem a mesma semântica de `Request::is()`. `PERMITIDO_COM_ID` usa `\A…[0-9]+\z` sobre `decodedPath()`, então `12/x`, `12%2Fx`, `abc`, `12\n` ficam barrados. As linhas novas com `*` (`variacoes/*`, `familias/*`, `ambientes/*`) só têm filhos numéricos.
- **Token ML:** nenhuma prop, JSON ou log expõe `access_token`. Os logs só levam a mensagem da exceção, e o token vai no header.
- **Migration 100100:** idempotente, nomes < 64, FK `nullOnDelete` sobre coluna nullable (sem 1830), sem backfill (D-09), `eo_company_*` intocados (sem 1553), `down()` só tira o vínculo. Rodada no MariaDB local: as tabelas existem no `ecf_admin`.
- **D-09:** criar produto com o SKU de uma oferta antiga não toca na oferta antiga. Só a espera é reavaliada (`sku_repetido`), como na Lista SKUs.
- **D-10/Precificação:** para oferta sem produto, `custo()` recebe o mesmo `$linha?->custo` e o mesmo mapa de componentes de antes, e o preço não muda. O Publicador (`DadosEfetivosService`, `CustoDoAnuncioService`, `MigracaoAnunciarAntigo`) lê pelo mesmo `pagina()`, então a origem nova do custo chega lá. `salvarOferta` recusa custo em oferta ligada, e o front não o envia.
- **D-19:** o frete calculado só vai para o `Cache` (`estrutura:frete:v1:{empresa}:…`) e nunca para `estrutura_precificacoes`.
- **D-15/D-17:** as fórmulas de cubagem, ME2, Full e empilhamento batem com o CONTEXT, e a tabela de reserva tem 29 × 8 e bate com `faixas_*`.
- **MariaDB 10.4 + `ONLY_FULL_GROUP_BY`:** `variacoes()->max('ordem')` gera SQL sem ORDER BY (conferido com `DB::pretend` e execução real). Não há 1140.

---

## Parte 2 — Frontend

**Revisado:** 2026-10-06T17:23:44Z
**Profundidade:** standard (com leitura do contrato do servidor onde o front depende dele: `ProdutoCadastroService::gravarLinha`, `NormalizadorDeLinha`, `ProdutoLinhas`, `FreteMe2Service::cotar`, o controller e o `handlePopstateEvent` do `@inertiajs/core` 2.3.21)
**Arquivos revisados:** 29 (arquivos pré-existentes revisados pelo diff `8bbfc5e2..HEAD`)
**Status:** issues_found

### Resumo

A ficha do produto tem quatro defeitos que gravam errado ou perdem o que foi digitado sem a pessoa perceber.
Os quatro passam pelos testes de `tests/js`, porque esses testes conferem o texto do código-fonte, não o
comportamento:

1. **Produto novo pode ser gravado DENTRO de outro produto** (FE-CR-01). A ficha manda `grupo` = Ref da 1ª
   variação, e o servidor usa esse `grupo` para achar um produto existente pelo `codigo`. Com coincidência, o
   outro produto é renomeado, perde a categoria e a variação nova herda eixo, custo e volumes dele.
2. **O voltar do navegador descarta a ficha sem confirmação** (FE-CR-02). A guarda só escuta
   `router.on('before')` e `beforeunload`, e o popstate do Inertia não dispara nenhum dos dois.
3. **Esvaziar Valor, Eixo ou Custo não grava** (FE-CR-03), e mesmo assim a tela diz "Produto salvo.".
4. **A variação nova mostra um custo e o servidor grava outro** (FE-CR-04). Acontece quando a 1ª variação é
   editada depois de clicar em "Nova variação".

Os avisos tratam de: falha de rede descartando ids já gravados, edição feita durante o "Salvando…", guarda que
desliga para sempre, histórico com lista velha, `mostrarCartao` desfazendo a rolagem do D-27, consulta de fretes
que quebra acima de 200 variações e o picker de categoria preso em "Não deu para buscar agora".

Segurança no cliente está bem resolvida:
- `urlDeVolta` só aceita o caminho da lista, sem redirecionamento aberto.
- Nenhum HTML cru e nenhum dado do cliente em `style`.
- Todo acesso a `localStorage`/`sessionStorage` está em try/catch.

Componentes compartilhados:
- `CabecalhoEstrutura` só muda com `amplo`.
- O `sheet.jsx` produz as mesmas classes por padrão.
- No `SpreadsheetGrid` os padrões preservam o comportamento antigo. A correção de `sticky` não atinge o
  Onboarding, porque `ImplementacaoPublica.jsx` não tem coluna `frozen`. Ficou, porém, código morto (FE-IN-06).

### Problemas críticos (BLOCKER)

#### FE-CR-01: "Novo produto" pode ser fundido num produto existente, que é renomeado e perde a categoria

**Arquivos:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:146-161`, `resources/js/lib/produtosEstrutura.js:196,209-213`
(servidor: `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php:386-389, 409-418, 479-506`)

**Problema:** para produto novo, o hook define `grupo = vars[0].codigo` e manda esse `grupo` em todas as linhas,
inclusive quando o produto tem uma variação só. No `MODO_GRADE`, o servidor procura
`produtos_codigo[chaveCodigo(grupo)]` e, se existir produto com esse `codigo`, usa esse produto. O `codigo` do
produto é fixado na criação (= `grupo` ou a 1ª Ref) e nunca acompanha mudanças nas variações. Por isso a
coincidência acontece com frequência:
- a Ref foi corrigida depois de criada;
- a 1ª variação foi excluída e as outras ficaram;
- o produto veio da importação com Grupo "1014" e Refs "1014-1"/"1014-2".

A checagem de código repetido não barra, porque nenhuma VARIAÇÃO tem aquele código.

**Cenário de falha:**
1. O cliente cria "Mesa Jantar" com Ref `100` e depois corrige a Ref para `MJ-100`. A variação muda; o
   `produto.codigo` continua `100`.
2. Ele cria um produto novo, "Cadeira Palhinha", com Ref `100`. A ficha envia
   `{grupo:'100', codigo:'100', nome:'Cadeira Palhinha', categoria_texto:''}`.
3. O servidor acha "Mesa Jantar" pelo grupo. Como é a 1ª linha daquele produto no lote:
   - renomeia a Mesa para "Cadeira Palhinha";
   - **apaga a categoria** dela, porque `alterou('categoria')` é sempre verdadeiro em linha sem `_base` e manda
     `categoria_texto: ''`, que significa limpar;
   - cria a variação `100` copiando eixo, custo e volumes da Mesa, pelo `$copiar`.
4. A ficha recebe `erros: []`, mostra "Produto salvo." e volta para a lista. O nome da Mesa se perdeu, sem
   desfazer. A comparação `chaveCodigo` ignora caixa e acento, o que amplia a colisão (`abc` × `ABC`).

**Correção:** não identificar produto novo por `grupo`. Grave a 1ª variação sozinha e sem `grupo`. Nesse caminho
o servidor cria um produto e, se o código do produto já existir, usa `codigo = null`. As demais variações vão
com o `produto_id` devolvido:
```js
let produtoId = vars.find((v) => v.produto_id)?.produto_id ?? null;
let fila = vars;
if (! produtoId) {
    const [primeira, ...resto] = vars;
    const { data } = await axios.post(rota, { linhas: [linhaParaServidor(primeira)] }); // sem grupo
    acumular(data);
    produtoId = (data.linhas ?? []).find((l) => l.chave === primeira._k)?.produto_id ?? null;
    if (! produtoId) { /* erro na 1ª: mostra no bloco e PARA — nada de grupo */ }
    fila = resto;
}
// lotes de `fila` sempre com { ...v, produto_id: produtoId }
```
Defesa no servidor: aceitar uma marca `novo_produto: true` vinda da ficha e, com ela, ignorar a busca por `grupo`.
Falta também um teste de feature para "produto novo com Ref igual ao `codigo` de outro produto".

#### FE-CR-02: o voltar do navegador (botão, Alt+←, gesto do Android) descarta a ficha sem perguntar

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:42-64`

**Problema:** a guarda cobre `router.visit` (evento `before`) e fechar a aba (`beforeunload`). O voltar do
navegador não passa por nenhum dos dois. Em `@inertiajs/core` 2.3.21, `handlePopstateEvent` chama
`page.setQuietly(data, { preserveState: false })` sem disparar `before`. O componente desmonta e o rascunho some.
O próprio D-32 lista "pelo voltar do navegador" como uma das saídas da ficha, e a UI-SPEC exige a confirmação
"Há alterações não salvas neste produto. Sair sem salvar?". No celular, o gesto de voltar é o jeito normal de sair
da página.

**Cenário de falha:** o cliente abre a ficha no celular, cadastra 3 variações com volumes e custo e usa o gesto de
voltar para "ver a lista antes de salvar". Volta para a lista sem aviso e perde tudo.

**Correção:** o caminho robusto é um rascunho em `sessionStorage`:
- a cada alteração, gravar o rascunho na chave `ecf.produtos.rascunho.{id|novo}`;
- ao montar a ficha, oferecer "Você tinha alterações não salvas — recuperar?";
- apagar o rascunho ao salvar com sucesso.

Complemento possível: um listener de `popstate` em captura no `window`, registrado antes do do Inertia.
Ele chama `stopImmediatePropagation()`, pede a confirmação e, se a pessoa ficar, faz `history.go(1)`, com uma
marca para ignorar o popstate seguinte. A ordem de captura no próprio alvo varia entre navegadores, então esse
complemento não substitui o rascunho.

#### FE-CR-03: esvaziar Valor, Eixo ("—") ou Custo e salvar não grava, mas a tela diz "Produto salvo."

**Arquivo:** `resources/js/lib/produtosEstrutura.js:199-200,217`
(servidor: `NormalizadorDeLinha.php:139-164` ignora eixo e valor vazios; `:214-220` aceita `custo: null` para limpar)

**Problema:** `linhaParaServidor` só envia esses campos quando estão preenchidos:
`if (alterou('eixo_rotulo') && row.eixo_rotulo)`, `if (alterou('valor') && String(row.valor) !== '')` e
`if (alterou('custo') && String(row.custo).trim() !== '')`. A regra nasceu na grade ("célula em branco não apaga
dado"). Na ficha, porém, `alterou()` já prova que a pessoa mexeu: um campo que tinha valor e foi esvaziado é
intenção de limpar. O resultado é sucesso visual com o valor antigo intacto. O custo é o pior caso, porque é ele
que a Precificação usa (D-10).

**Cenário de falha:** o custo de 120,00 da variação `1014-2` está errado e ainda não se sabe o certo. O cliente
apaga o campo e salva, e aparece "Produto salvo.". A oferta continua precificada com 120,00, e o cartão continua
sem "Falta: custo". O mesmo vale para trocar o Eixo para "—" ou apagar o Valor "Natural".

**Correção:**
```js
// só para linha com retrato do servidor: mexeu e esvaziou = limpar de propósito
if (row._base && alterou('custo') && String(row.custo ?? '').trim() === '') out.custo = null;
if (row._base && alterou('eixo_rotulo') && ! row.eixo_rotulo) out.eixo = null;
if (row._base && alterou('valor') && String(row.valor ?? '').trim() === '') out.valor = null;
```
No `NormalizadorDeLinha`, tratar `array_key_exists('eixo'|'valor', $bruta) && $bruta[...] === null` como
"presente = limpar", como já é feito com `custo`. Enquanto isso não existir no servidor, a ficha deveria ao menos
recusar o salvar com "Não dá para deixar o Valor vazio".

#### FE-CR-04: a "Nova variação" de produto já gravado mostra um custo e eixo e grava outros

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:113-115`
(servidor: `ProdutoCadastroService.php:479-486`)

**Problema:** `nova._base = { ...campoEditaveis(nova), codigo: '', valor: '' }` faz custo e eixo copiados contarem
como "não mexidos", e por isso não vão no POST. O servidor então copia da 1ª variação **do banco**
(`$produto->variacoes()->first()`). No mesmo lote, essa 1ª variação já foi atualizada com o que a pessoa digitou
nela. O que a tela mostra na variação nova não é o que se grava.

**Cenário de falha:**
1. O produto tem uma variação `1014-1` com custo 100. O cliente clica em "Nova variação", que aparece com
   custo 100, e preenche Valor "Preto".
2. Ele percebe que a `1014-1` custa 150 e corrige só ela. A nova continua mostrando 100, de propósito: a preta
   custa 100.
3. Ao salvar, a `1014-1` grava 150 e a nova grava **150**, copiada do banco já atualizado.

A ficha volta para a lista com "Produto salvo.", e o custo errado vai para a Precificação. O mesmo acontece com o
Eixo.

**Correção:** o que a variação nova mostra tem de ir explícito. Marque os campos da variação que sempre seguem:
```js
// novaVariacao
const nova = { ...base, /* ... */, _explicitos: ['eixo_rotulo', 'custo'] };
// linhaParaServidor
const alterou = (c) => ! row._base || row._explicitos?.includes(c) || String(row[c] ?? '') !== String(base[c] ?? '');
```
Família, ambientes e categoria são do produto e podem continuar fora. A cópia do servidor (D-04) fica só para a
importação e para a grade.

### Avisos (WARNING)

#### FE-WR-01: falha de rede ou 5xx depois de um lote gravado descarta os ids e a nova tentativa vira "código já existe em outro produto"

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:155-178`

**Problema:** o `catch` devolve `{ ok: false, data: null }` e joga fora `juntas`. Os lotes que o servidor já
gravou, com variações, produto e ofertas criados, não aplicam `id`/`produto_id` no rascunho, e o `ultimoRef` da
ficha não é atualizado. Isso não depende de haver mais de 200 variações: com um lote só basta a resposta se perder
depois do commit (504 do nginx, queda de conexão).

**Cenário de falha:** produto novo com 3 variações, a resposta do POST cai em 504 e a ficha diz "Não foi possível
salvar agora… tente de novo". Na nova tentativa as linhas vão sem `id`: as 3 voltam com "O código X já existe em
outro produto" (é o mesmo produto). O cliente fica preso numa ficha `/novo` que não consegue mais gravar, com um
produto meio criado na lista.

**Correção:** no `catch`, aplicar o que já voltou antes de sair (mesmo trecho do ramo parcial: `porChave` →
`linhaDoServidor`) e devolver `{ ok: false, data: juntas }`. Para o caso de um lote só, a ficha pode
reconsultar o produto pela Ref antes de reenviar (ou o servidor aceitar idempotência pela `chave`).

#### FE-WR-02: o que se digita durante o "Salvando…" é perdido sem aviso

**Arquivos:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:152-198`, `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:139-173`

**Problema:** durante o POST só o botão "Salvar produto" fica desabilitado. Os campos, "Nova variação", "Excluir
variação" e os pickers continuam ativos, e o envio usa a foto de `vars` tirada no clique.
- **Sucesso:** `setAlterado(false)` apaga a marca de "não salvo" que a digitação acabou de pôr, e a página navega.
- **Parcial:** as linhas gravadas são trocadas pelo que veio do servidor (`linhaDoServidor`), o que sobrescreve a
  digitação.

**Cenário de falha:** produto com 30 variações e frete estimado para todas, o que leva cerca de 2 s. O cliente
clica em Salvar, nota um erro de digitação no nome e o corrige. A página volta para a lista com o nome antigo.

**Correção:** `<fieldset disabled={ficha.salvando}>` em volta de dados gerais e variações (e desabilitar "Nova
variação"/"Excluir"); ou um contador de revisão que, se mudou durante o POST, não zera `alterado` nem navega.

#### FE-WR-03: depois de um "Sair sem salvar? → OK", a guarda fica desligada para sempre

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:53-55`

**Problema:** `liberado.current = true` nunca volta a `false`. Se a visita confirmada falha ou é cancelada, o
cliente continua na ficha sem guarda nenhuma: nem o `before`, nem o `beforeunload` (linha 44) protegem mais. Os
casos são rede caindo, sessão expirada ou outra visita que cancela a primeira. O `usePublicador` já resolve isso
com `onFinish: () => { liberado = false; }`.

**Cenário de falha:** o cliente clica num item do menu, confirma a saída e a rede falha, então ele fica na ficha.
Continua editando por 10 minutos e fecha a aba, sem aviso nenhum.

**Correção:** igual ao `usePublicador`. Devolva `false`, confirme e refaça a visita com
`onFinish: () => { liberado.current = false; }`. Outra opção é zerar `liberado` em `router.on('finish')`
enquanto a ficha estiver montada.

#### FE-WR-04: excluir a última variação GRAVADA apaga o produto e descarta as variações novas ainda não gravadas

**Arquivos:** `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:94-111`, `resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx:58`

**Problema:** o cálculo de `ultima` só conta variações com `id`. Com uma variação gravada e outra nova digitada, a
janela diz "É a última variação, então o produto X também será excluído". `aoExcluida` então liga
`liberado.current = true` e volta para a lista. A variação digitada some sem a confirmação "Sair sem salvar?",
porque a guarda foi liberada.

**Cenário de falha:** o cliente cria a variação `A-2` com volumes e custo e decide apagar a antiga `A`. O produto
inteiro é excluído e a `A-2` não chega a ser gravada.

**Correção:** com variações não gravadas na ficha, não exclua o produto em silêncio. As opções são:
- avisar "Há variações novas não salvas; salve-as antes de excluir a última gravada";
- ou, depois da exclusão, manter a ficha como produto novo: tirar `produto_id`/`_base` das variações restantes e
  não navegar.

#### FE-WR-05: "Salvar" e "Excluir" deixam no histórico uma lista com props velhas, e "Cancelar"/"← Produtos" empilham entradas

**Arquivos:** `resources/js/lib/produtosNavegacao.js:60-67`, `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:86-91,106,68`

**Problema:** com `replace: true`, a entrada da ficha é trocada pela lista nova e a pilha fica
`[…, lista(velha), lista(nova)]`. O voltar do navegador restaura a `lista(velha)` direto do `history.state`, sem
ir ao servidor. Aparecem o produto novo ausente, o nome antigo ou o produto excluído ainda na tela (clicar nele dá
404). "Cancelar" e "← Produtos" fazem `push`: a pilha fica `[lista, ficha, lista]`, e o voltar retorna para a
ficha.

**Cenário de falha:** o cliente exclui a última variação do produto e cai na lista. Usa o voltar para sair do
módulo e vê a lista anterior com o produto excluído. Clica nele e recebe 404.

**Correção:** quando a ficha foi aberta pela lista (marcar `origem: 'lista'` em `guardarRetorno`), volte com
`window.history.back()`. Na lista, ao montar com `volta` ou `ultimo`, faça
`router.reload({ only: ['produtos'], preserveScroll: true })`. Assim uma só entrada da lista recebe dados frescos,
inclusive quando se volta pelo navegador.

#### FE-WR-06: `mostrarCartao` trata cartão parcialmente visível como "fora da tela" e desfaz a rolagem restaurada (D-27)

**Arquivo:** `resources/js/lib/produtosNavegacao.js:120-127` (linha 125)

**Problema:** a condição `top < 0 || bottom > window.innerHeight` também é verdadeira para cartão cortado na borda
e para cartão mais alto que a janela. Os dois casos são comuns: o cartão clicado na última fileira visível
costuma estar cortado embaixo. O resultado é que a lista restaura `scrollY` (D-27) e, um quadro depois, rola
suavemente para centralizar o cartão. O D-32 pede só "trazido para a tela se estiver fora dela".

**Cenário de falha:** no Visual grande, a pessoa clica num cartão cuja parte de baixo está fora da tela, salva e
volta. A página restaura a posição e em seguida desliza sozinha.

**Correção:** `if (bottom <= 0 || top >= window.innerHeight) cartao.scrollIntoView({ block: 'center', behavior: 'smooth' });`
(rolar só quando o cartão está inteiramente fora; opcionalmente `block: 'nearest'`).

#### FE-WR-07: "Consultar fretes" quebra acima de 200 variações e diz "Fretes atualizados." com fretes por consultar

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutos.jsx:207-230`
(servidor: `cotarFretes` valida `variacao_ids` `max:200`; `max_por_requisicao` 12; rota `throttle:20,1`)

**Problema:**
- Todos os ids ME2 da página vão num POST só. Uma página tem até 100 produtos, e com 2 ou mais variações em média
  passa de 200: o servidor responde 422 e a tela mostra "Não deu para consultar o Mercado Livre agora" em toda
  tentativa.
- O laço para em `VOLTAS_FRETE = 10`, ou seja, 10 × 12 = 120 cotações distintas. Mesmo com `pendentes > 0` ele
  mostra "Fretes atualizados.".
- Dois cliques no mesmo minuto passam de 20 requisições e geram 429.

**Cenário de falha:** empresa com 100 produtos de 3 cores na página. O botão falha sempre, e a tela culpa o Mercado
Livre.

**Correção:** dividir `ids` em blocos de no máximo 200. Repetir enquanto `pendentes > 0`, respeitando o throttle.
Se o laço terminar com pendência, dizer "Consultamos parte dos fretes; clique de novo para continuar". Tratar 422
e 429 separados de "Mercado Livre fora do ar".

#### FE-WR-08: o picker de categoria fica preso em "Não deu para buscar agora" quando o nome tem mais de 120 caracteres ou só 1

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx:18,28-45`
(servidor: `buscarCategorias` valida `q` `min:2|max:120`; o nome do produto aceita 255)

**Problema:** a busca abre já preenchida com `row.nome`. Um nome com mais de 120 caracteres ou com 1 só gera 422,
o `catch` mostra "Não deu para buscar agora…" e "Tentar de novo" repete o mesmo 422 indefinidamente.

**Cenário de falha:** nome "Mesa de Jantar Retangular Farmhouse em Madeira Maciça com Tampo de Vidro e 6 Cadeiras
Estofadas Linho Cinza Claro" (cerca de 130 caracteres). O picker nunca busca, e o cliente acha que o ML caiu.

**Correção:** `useState((textoInicial ?? row?.nome ?? '').slice(0, 120))`, `maxLength={120}` no input, não chamar
com menos de 2 caracteres ("Digite ao menos 2 letras") e tratar `e.response?.status === 422` à parte.

### Informações (INFO)

#### FE-IN-01: as listas da página não acompanham a importação

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutos.jsx:63`

`useState(listasIniciais)` não reage a mudanças nas props. Depois de "Confirmar importação" (`back()` traz `listas`
novas), "Famílias e ambientes" continua sem as famílias e ambientes criados pela planilha e com `em_uso` velho até
recarregar. Correção: `useEffect(() => setListas(listasIniciais), [listasIniciais])`.

#### FE-IN-02: abrir e fechar o picker de Ambientes sem mudar nada marca a ficha como alterada

**Arquivos:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:64-68`, `PickerLista.jsx:43-49`

Fechar o picker sempre chama `onCommit` e `aplicarEscolha`, que faz `setAlterado(true)` sem comparar. O resultado
é um falso "Há alterações não salvas" no Cancelar. O mesmo ocorre ao escolher a mesma família. Correção: só marcar
`alterado` quando o patch muda algum campo.

#### FE-IN-03: a Ref sugerida da "Nova variação" pode colidir, e o erro fala em "outro produto"

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:108`

`${grupo ?? codigo}-${vars.length + 1}`: depois de excluir a `1014-2` de 1014-1/2/3, a nova sugere `1014-3`, que
já existe. O servidor recusa com "O código 1014-3 já existe em outro produto", mas é o mesmo produto. Correção:
usar o menor sufixo livre entre as Refs da ficha. Sem Ref na 1ª variação, a sugestão vira `-2`.

#### FE-IN-04: `sessionStorage` sem validade para a volta e o retorno; `produtoId` não validado no seletor

**Arquivo:** `resources/js/lib/produtosNavegacao.js:45-47,60-67,82`

- **Retorno sem validade:** `ecf.produtos.retorno` não expira. Uma ficha aberta por URL direta "volta" para
  uma busca e página de horas atrás.
- **Volta sem validade:** `ecf.produtos.volta` é gravada em `onStart`. Se a visita falhar depois de começar,
  a próxima entrada na lista mostra um "Produto salvo." velho.
- **Seletor sem validação:** `rolarParaVolta` interpola `volta.produtoId` cru em
  `querySelector('[data-produto-id="…"]')`. Um valor estranho lança DOMException dentro do rAF.

Correção: carimbo `em` com validade, como em `CHAVE_ULTIMO`, e `Number.isInteger(volta.produtoId)` antes de
interpolar.

#### FE-IN-05: dicas em `title` de ícone SVG não aparecem

**Arquivos:** `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:148`, `resources/js/lib/produtosEstrutura.js:93`

O lucide-react 1.11 repassa `title` como atributo do `<svg>`, que os navegadores não exibem como dica (no SVG a dica
precisa do elemento `<title>`). Ficam sem dica "Nova variação já vem com eixo, volumes e custo da primeira…" e o
"Neste preço o frete pode mudar de faixa." que a UI-SPEC exige no alerta do frete. Correção: `title` no `<span>`
que envolve o ícone, ou `<title>` filho.

#### FE-IN-06: extensões do `SpreadsheetGrid`, `gradeTeclado.js` e `side` do `sheet.jsx` ficaram sem consumidor

**Arquivos:**
- `resources/js/Components/SpreadsheetGrid.jsx` (cerca de 300 linhas: `growOnPaste`, `picker`/`CamadaPicker`,
  `tabWrap`, `rowKey`, `rowActions`, `rowNote`, `selecionar`, `variant='portal'`);
- `resources/js/lib/gradeTeclado.js`;
- `resources/js/Components/ui/sheet.jsx:27-41`.

Com o D-23 a grade saiu da tela de Produtos. Nenhum consumidor usa essas props (o único é
`ImplementacaoPublica.jsx`), e ninguém passa `side`. O componente é compartilhado com o Onboarding público e
carrega listeners de documento (`paste`, `keydown` em captura) que hoje só ligam com as props. Os padrões estão
corretos e não houve regressão. Fica a recomendação de remover numa limpeza ou registrar no learnings que o código
está ali sem uso.

#### FE-IN-07: depois de um erro, escolher o mesmo arquivo de novo não faz nada

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/JanelaImportacao.jsx:109-110,118-120`

Nos retornos de extensão ou tamanho inválidos e no `catch`, `entrada.current.value` não é zerado. Escolher o mesmo
arquivo depois de corrigi-lo não dispara `onChange`. Correção: `if (entrada.current) entrada.current.value = ''`
nesses caminhos.

#### FE-IN-08: tirar ambiente pelo X do chip com o picker aberto faz o ambiente voltar ao fechar

**Arquivos:** `resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx:38-41,93`, `PickerLista.jsx:30-32,43-49`

`marcados` é lido só ao montar o picker. Com o popover aberto, o clique no X do chip (dentro do gatilho, que o
Radix não trata como "fora") remove o ambiente. Ao fechar, o picker grava o `marcados` velho e o ambiente reaparece.
Correção: sincronizar `marcados` com `valor` ou fechar o picker antes de `tirarAmbiente`.

#### FE-IN-09: controles aninhados nos gatilhos de Ambientes e Categoria (acessibilidade)

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx:85-101,125-130`

- **X do chip pelo teclado:** o `onKeyDown` da `div role="button"` faz `preventDefault` em Enter e Espaço, então
  apertar Enter no X do chip abre o picker em vez de tirar o ambiente.
- **"Limpar categoria" dentro de `<button>`:** é um `span role="button" tabIndex=0` aninhado num botão, conteúdo
  interativo dentro de botão, que é HTML inválido.

Correção: tirar os botões de chip e de limpar de dentro do gatilho, como irmãos posicionados.

#### FE-IN-10: produto novo gravado em parte continua na URL `/novo`

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutoFicha.jsx:76-83`

Na gravação parcial, o produto já existe, mas a URL segue `/portal/estrutura/produtos/novo`. Recarregar a página
(depois do aviso do `beforeunload`) abre uma ficha em branco, e o que foi gravado só aparece pela lista. Correção:
`history.replaceState` para a URL `…/produtos/{id}` quando o primeiro `produto_id` voltar.

#### FE-IN-11: mensagens 419 e 429 aparecem em inglês no aviso da ficha

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:172-174`

Para qualquer status abaixo de 500, o aviso mostra `e.response.data.message`. O Laravel responde
"CSRF token mismatch." (419) e "Too Many Attempts." (429, com `throttle:120,1`) sem tradução, e o
`bootstrap/app.php` não personaliza. Correção: mensagens próprias para 419 ("Sua sessão expirou, recarregue a
página; o que você digitou fica aqui") e 429.

#### FE-IN-12: família escolhida não pode ser removida

**Arquivos:** `resources/js/Components/Portal/Estrutura/Produtos/PickerLista.jsx:68-81`, `resources/js/lib/produtosEstrutura.js:201`

O picker de família não tem a opção "nenhuma", e `linhaParaServidor` não envia família vazia. Uma família
escolhida por engano só pode ser trocada por outra. O D-28 trata família como opcional. Correção: item
"Sem família" e envio de `familia: null`, com o servidor tratando como limpar.

#### FE-IN-13: os testes JS conferem o texto do código, não o comportamento

**Arquivo:** `tests/js/estrutura-produtos-ficha.test.js:51-52` (e semelhantes)

`assert.match(hook, /codigo: `\$\{base\.grupo …/)` confere o texto do código-fonte. FE-CR-01, 03 e 04 passam nesses
testes. Correção: testar `linhaParaServidor` e `salvar` de forma comportamental (entrada → payload), mais um teste
de feature no PHP para a colisão de `grupo`.

#### FE-IN-14: "100 por página" repetido no front

**Arquivo:** `resources/js/Pages/Portal/EstruturaProdutos.jsx:297`

`por_pagina: 100` no JSX repete `ProdutoLinhas::POR_PAGINA`. Correção: o servidor manda `por_pagina` em
`paginacao`.

---

_Revisado: 2026-10-06T17:23:44Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
