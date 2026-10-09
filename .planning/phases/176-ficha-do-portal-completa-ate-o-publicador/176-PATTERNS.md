# Fase 176: Ficha do portal completa até o Publicador - Mapa de Padrões

**Mapeado em:** 2026-10-08
**Arquivos analisados:** 24 (novos e modificados)
**Analogs encontrados:** 22 / 24 (os 2 sem analog exato estão em "Sem analog")
**Base:** worktree `C:/tmp/ecf-publicador-spec-261001`. Números de linha lidos neste worktree em 08/10 (alguns diferem 1-9 linhas do RESEARCH; use os daqui).

## Classificação dos arquivos

| Arquivo novo/modificado | Papel | Fluxo de dados | Analog mais próximo | Qualidade |
|---|---|---|---|---|
| `database/migrations/2026_10_08_15xxxx_add_estoque_to_estrutura_produto_variacoes.php` | migration | aditiva | `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` | exato |
| `database/migrations/2026_10_08_15xxxx_add_descricao_to_estrutura_produtos.php` | migration | aditiva | `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` | exato (só a parte da coluna) |
| `database/migrations/2026_10_08_15xxxx_add_estrutura_produto_id_to_pub_produtos.php` | migration | aditiva (col + unique + FK) | `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` | exato |
| `app/Models/EstruturaProdutoVariacao.php` (fillable/cast `estoque`) | model | CRUD | o próprio arquivo (campo `custo`) | exato |
| `app/Models/EstruturaProduto.php` (fillable `descricao`) | model | CRUD | o próprio arquivo | exato |
| `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php` | utility | transform | bloco "Custo" do mesmo arquivo | exato |
| `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` | service | CRUD | ramos de `custo` do mesmo arquivo | exato (com exceção: não herdar) |
| `app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php` | service | request-response | linha `'custo'` do mesmo arquivo | exato |
| `app/Services/Portal/Estrutura/Produtos/DescricaoDoProduto.php` (novo) | service | CRUD | `FichaTecnicaDoProduto.php` (`gravar`/`salvos`) | role-match |
| `app/Http/Controllers/PortalEstruturaProdutosController.php` (`gravarDescricao`, `renderFicha`) | controller | request-response | `gravarFichaTecnica` (:369-396) | exato |
| `routes/web.php` (PUT descrição) | route | request-response | rota `ficha-tecnica` (:245-246) | exato |
| `resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx` | component | request-response | campo Custo (:62-65) | exato |
| `resources/js/lib/produtosEstrutura.js` | utility | transform | `custoParaTexto`, `CAMPOS_EDITAVEIS`, ramo `custo` de `linhaParaServidor` | exato |
| `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js` | hook | request-response | `tecnica.gravar` (:270-278) e `novaVariacao` (:175-187) | exato |
| `resources/js/Components/Portal/Estrutura/Produtos/useDescricaoProduto.js` (novo) + bloco na ficha | hook/component | request-response | `useFichaTecnica.js` | role-match |
| `tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php` (`assertSemOrigem`) | test | request-response | o próprio arquivo (:432-437) | exato |
| `app/Services/Publicador/PortalParaRascunhoService.php` (novo) | service | CRUD sob trava | `IaParaRascunhoService.php` | exato (mesma coreografia) |
| `app/Services/Publicador/PortalProdutoLeitor.php` (novo) | service | leitura | `DadosEfetivosService.php` (leitura ao vivo da oferta) | parcial |
| `app/Services/Publicador/PublicadorSincronizaPortalService.php` | service | batch/CRUD | o próprio arquivo (:23-60) | exato |
| `app/Models/PubProduto.php` (`skuExibido`/`nomeExibido`) | model | CRUD | o próprio arquivo (:99-107) | exato |
| `app/Services/Publicador/ImagemAssetService.php` (`receber(..., enviar:false)`) | service | file-I/O | o próprio arquivo (:35-57) | exato |
| `app/Services/Publicador/DadosEfetivosService.php` (`precos_por_variante`) | service | transform | o próprio arquivo (:44-65) | exato |
| `app/Jobs/Publicador/PreencherRascunhoDoPortalJob.php` (novo) | job | event-driven (fila `high`) | `GerarPalavrasChaveIaJob.php` | role-match |
| `app/Services/Publicador/DescricaoIaService.php` + `app/Jobs/Publicador/GerarDescricaoIaJob.php` (novos) | service + job | event-driven + cache | `PalavrasChaveService.php` + `GerarPalavrasChaveIaJob.php` | exato |
| `resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx` (painel + botão) + hook `useDescricaoIa.js` | component/hook | request-response + polling | `Descricao` (:169-185) e o hook do Modelo | role-match |

## Atribuição de padrões

### Três migrations (estoque, descrição, `pub_produtos.estrutura_produto_id`)

**Analog:** `database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php`. Atenção: o `2026_10_07_100200` citado no CONTEXT é um `CREATE TABLE`; serve só para o estilo do docblock "DECISÃO DE SCHEMA" e para o helper `emMysql()`. Para ALTER aditivo o molde é o `100100` da 167.

**Coluna aditiva, cada DDL num `Schema::table` separado, sob `hasColumn`/`hasIndex`/`hasForeignKey`** (linhas 33-50):
```php
if (! Schema::hasColumn('estrutura_ofertas', 'variacao_id')) {
    Schema::table('estrutura_ofertas', function (Blueprint $t) {
        $t->unsignedBigInteger('variacao_id')->nullable()->after('company_id');
    });
}
if (! $this->hasIndex('estrutura_ofertas', 'eo_variacao_uq')) {
    Schema::table('estrutura_ofertas', function (Blueprint $t) {
        $t->unique('variacao_id', 'eo_variacao_uq');
    });
}
if (! $this->hasForeignKey('estrutura_ofertas', 'eo_variacao_fk')) {
    Schema::table('estrutura_ofertas', function (Blueprint $t) {
        $t->foreign('variacao_id', 'eo_variacao_fk')->references('id')->on('estrutura_produto_variacoes')->nullOnDelete();
    });
}
```

**`down()` na ordem FK -> unique -> coluna** (linhas 53-75), com `dropForeign($this->emMysql() ? 'eo_variacao_fk' : ['variacao_id'])`.

**Helpers a copiar inteiros** (linhas 78-125): `emMysql()` (`in_array(DB::getDriverName(), ['mysql','mariadb'], true)`), `hasIndex()` (information_schema / `PRAGMA index_list`), `hasForeignKey()` (information_schema / `PRAGMA foreign_key_list`, que compara `$row->from` com a coluna; trocar `'variacao_id'` por `'estrutura_produto_id'` na migration 3).

Aplicação:
- Migration 1: `unsignedInteger('estoque')->nullable()->after('custo')` em `estrutura_produto_variacoes`. Só o primeiro bloco; sem unique/FK.
- Migration 2: `text('descricao')->nullable()->after('categoria_ml_caminho')` em `estrutura_produtos`. Só o primeiro bloco.
- Migration 3 (única que toca tabela `pub_*` com dado): três blocos como acima; unique `pubprod_eprod_uq`, FK `pubprod_eprod_fk` -> `estrutura_produtos.id` com `nullOnDelete()`. Nomes < 64 caracteres. Sem backfill, sem default, sem `->change()`.
- Acrescentar os 3 arquivos à constante `MIGRACOES` de `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (linhas 19-29+; a varredura :119-127 rejeita `getDriverName() === 'mysql'`).
- Prefixo: já existe `2026_10_08_140000_semear_tipos_e_pares_de_banheiro.php` (outra sessão); usar `150000+`.
- Prova: `migrate --path=<arquivo>` / `migrate:rollback --path=<arquivo>` no MariaDB local; nunca `migrate` puro.

---

### Estoque por variação (portal): `NormalizadorDeLinha`, `ProdutoCadastroService`, `ProdutoLinhas`, model

**Analog:** o campo `custo`, ponta a ponta.

**Normalizador** (`NormalizadorDeLinha.php:237-257`). Chave ausente = "não mexi"; `null` explícito = limpar e entra em `presentes`; string vazia = "não mexi":
```php
if (array_key_exists('custo', $bruta)) {
    $custoBruto = $bruta['custo'];
    $vazioTexto = is_string($custoBruto) && trim($custoBruto) === '';
    if ($custoBruto === null) {
        $presentes[] = 'custo';
    } elseif (! $vazioTexto) {
        try {
            $custo = NumeroBr::interpretar($custoBruto, NumeroBr::DINHEIRO);
            if ($custo !== null && $custo > self::MAX_CUSTO) {
                $erros['custo'] = 'O custo é alto demais. Confira o valor.';
            } else { $campos['custo'] = $custo; $presentes[] = 'custo'; }
        } catch (InvalidArgumentException) { $erros['custo'] = NumeroBr::MENSAGEM; }
    }
}
```
Para `estoque`: inteiro >= 0 (0 é valor válido, não vazio; Pitfall 10), teto razoável (constante `MAX_ESTOQUE`), mensagem de erro neutra (sem "mercado/anúncio"). Registrar `'estoque' => null` no array de campos padrão (perto de `'custo' => null`, :68).

**Cadastro** (`ProdutoCadastroService.php`):
- Criação (:578-590): `custo` herda da 1ª variação via `$copiar?->custo`. **Estoque NÃO herda**: `'estoque' => in_array('estoque', $presentes, true) ? $campos['estoque'] : null`.
- Atualização (:603-607): estender o laço
```php
foreach (['eixo', 'valor', 'ordem', 'custo'] as $c) {
    if (in_array($c, $presentes, true)) { $novo[$c] = $campos[$c]; }
}
```
com `'estoque'`.

**Linha de retorno** (`ProdutoLinhas.php`): somar `'estoque' => $variacao->estoque,` junto de `'custo' => $variacao->custo,` (:167, no array final da `linha()`; os :110 e :139 são do frete e das pendências e NÃO precisam de estoque).

**Model:** `EstruturaProdutoVariacao`: `'estoque'` em `$fillable`, cast `integer` (NULL continua NULL). `EstruturaProduto`: `'descricao'` em `$fillable`.

---

### Estoque na tela do portal (JS)

**Analog:** o campo Custo.

`CartaoVariacao.jsx:46` (grade) e `:62-65` (campo):
```jsx
<div className="... lg:grid-cols-[203fr_168fr_185fr_158fr] lg:gap-5">
...
<div>
    <label className={ROTULO} htmlFor={`custo-${k}`}>Custo (R$)</label>
    <input id={`custo-${k}`} className={cn(CAMPO, 'tabular-nums')} inputMode="decimal" value={variacao.custo ?? ''} onChange={(e) => ficha.alterar(k, 'custo', e.target.value)} placeholder="0,00" />
</div>
```
Estoque: 5ª coluna da grade, `htmlFor={`estoque-${k}`}`, rótulo neutro "Estoque (un.)", `inputMode="numeric"`, `placeholder="0"`.

`lib/produtosEstrutura.js`:
- `linhaDoServidor` (:167): `custo: custoParaTexto(linha.custo)`. Estoque: `estoque: linha.estoque === null || linha.estoque === undefined ? '' : String(linha.estoque)` (0 vira `'0'`, não vazio).
- `CAMPOS_EDITAVEIS` (:176): acrescentar `'estoque'` (alimenta `_base`, o retrato do que o servidor tem).
- `linhaParaServidor` (:238-240), mesmo ramo do custo:
```js
if (novaDeProdutoGravado) out.custo = vazio('custo') ? null : row.custo;
else if (alterou('custo') && ! vazio('custo')) out.custo = row.custo;
else if (limpou('custo')) out.custo = null;
```
- `useFichaProduto.js`: `linhaEmBranco` (:33) ganha `estoque: ''`; **`novaVariacao` (:175-187) usa `...base`, que copiaria o estoque da 1ª: forçar `estoque: ''`** junto de `valor: ''`.
- Planilha-modelo `ModeloProdutosXlsx::CAMPOS` (:40): NÃO mexer (D-14).

---

### Descrição do produto (portal): endpoint, serviço, bloco, hook

**Analog:** ficha técnica (`gravarFichaTecnica`, `FichaTecnicaDoProduto`, `useFichaTecnica`).

**Controller** (`PortalEstruturaProdutosController.php:364-396`). 404 de outra empresa ANTES da validação:
```php
public function gravarFichaTecnica(Request $request, int $produto)
{
    $empresa = PortalContexto::empresa();
    $p = EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produto);

    $request->validate([ 'atributos' => 'present|array|max:300', ... ]);

    $salvos = $this->fichaTecnica->gravar($empresa, $p, (array) $request->input('atributos'), PortalContexto::ator());

    return response()->json(['salvos' => $salvos, 'mensagem' => 'Ficha técnica salva.']);
}
```
`gravarDescricao`: mesma ordem; `validate(['descricao' => 'nullable|string|max:5000'])`; `''` vira null pelo middleware (limpar é intencional); mensagem `'Descrição salva.'` (neutra).

**Rota** (`routes/web.php:245-246`):
```php
Route::put('/estrutura/produtos/{produto}/ficha-tecnica', [PortalEstruturaProdutosController::class, 'gravarFichaTecnica'])
    ->whereNumber('produto')->middleware('throttle:60,1,estrutura.produtos.ficha_tecnica')->name('portal.auth.estrutura.produtos.ficha_tecnica');
```
Copiar para `/estrutura/produtos/{produto}/descricao`, throttle `estrutura.produtos.descricao`, nome `portal.auth.estrutura.produtos.descricao`. Ficar dentro do mesmo grupo (módulo já está na allowlist de `RestringeDominioDoPortal`).

**Props da ficha** (`renderFicha`, :534-545): somar `'descricao' => $produto?->descricao,` ao lado de `'ficha_tecnica' => ['salvos' => ...]`.

**Serviço** `DescricaoDoProduto::gravar(Company, EstruturaProduto, ?string, AtorDoPortal)`: mesmos imports de `FichaTecnicaDoProduto.php:5-12` (`Company`, `EstruturaProduto`, `RegistroEstrutura`, `AtorDoPortal`, `DB`). Trim, vazio -> NULL, e `RegistroEstrutura::registrar` (auditoria), como a ficha faz.

**Hook JS:** `useFichaProduto.salvar()` chama `tecnica.gravar(r.produtoId)` só depois de o produto existir (:268-278):
```js
if (ok) {
    const t = await tecnica.gravar(r.produtoId);
    if (t.ok) { salvosDaFicha = t.pulou ? null : t.salvos; }
    else { ok = false; setAviso('O produto foi salvo, mas a ficha técnica precisa de ajustes. ...'); }
}
```
Gravar a descrição logo depois com o mesmo contrato `{ok, pulou}`; protege saída sem salvar via `aoAlterar: () => setAlterado(true)` (:119). Texto de ajuda sem as palavras proibidas.

---

### Sigilo (`assertSemOrigem`)

**Analog:** `tests/Feature/PortalCliente/Estrutura/Produtos/FichaTecnicaDoProdutoTest.php:432-437`:
```php
private function assertSemOrigem(string $json, string $onde): void
{
    foreach (['mercado', 'mercadolib', 'anúncio', 'anuncio', 'publicar', 'mlb'] as $termo) {
        $this->assertStringNotContainsStringIgnoringCase($termo, $json, "“{$termo}” vazou em {$onde}");
    }
}
```
Usado em `test_o_que_o_cliente_recebe_nao_revela_de_onde_vem_a_ficha` (:439+). Estender varrendo as props da `ficha` (com `estoque` e `descricao` preenchidos com texto neutro) e a resposta do `PUT` descrição, mais as mensagens de erro de 422 do estoque. Cuidado: `ml_conectado` (:542) já existe e é anterior; a varredura é sobre chaves/rótulos novos, não sobre o que o cliente digita.

---

### `PortalParaRascunhoService` (novo)

**Analog:** `app/Services/Publicador/IaParaRascunhoService.php`. Mesma coreografia (trava, só-vazio, motor); fonte confiável (portal) em vez da IA.

**Imports** (:5-18): `PubPublicacao`, `PubPublicacaoItem`, `PubRascunho`, `RegraViolada`, `AtributoClassificado`, `ClassificadorAtributos`, `ContextoClassificacao`, `SchemaClassificado`, `ChaveCanonica`, `Eixo`, `ValorEixo`, `Variante`, `DB`. Construtor (:66-70): `EditorRascunhoService $editor, RascunhoRepository $repo, CategorySchemaRepository $schemas`.

**Intocável por FATO, não por status** (:77-83): reusar `IaParaRascunhoService::intocavel($r)` (é `public static`); não reimplementar.
```php
public static function intocavel(PubRascunho $r): bool
{
    return in_array($r->status, self::INTOCAVEIS, true)
        || $r->publicacoes()->where('status', PubPublicacao::RUNNING)->exists()
        || PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))
            ->where('status', PubPublicacaoItem::CREATED)->exists();
}
```

**Trava `sobTrava`** (:278-295). Travar PRIMEIRO, ler o snapshot depois, na mesma transação. No Sincronizar não há "substituir": o `$sobrescrever` é sempre `false` (D-05), então a versão do portal é a mesma sem `sobrescrever`/`revisaoEsperada`:
```php
private function sobTrava(int $rascunhoId, callable $escrita): bool
{
    return DB::transaction(function () use ($rascunhoId, $escrita) {
        $r = PubRascunho::whereKey($rascunhoId)->lockForUpdate()->first();
        if (! $r || self::intocavel($r)) { return false; }
        $escrita($r);
        return true;
    });
}
```

**Categoria** (:115-142). Schema lido FORA da trava (`lerSchemas`, :311-322, pode ir ao ML); dentro da trava só `trocarCategoria`, capturando `RegraViolada` como aviso do resumo:
```php
$erroCategoria = $this->lerSchemas([$categoria, $r->categoria_id]);
$vivo = $this->sobTrava($r->id, function (PubRascunho $r) use ($categoria, $erroCategoria, &$avisos) {
    if ($r->categoria_id !== null) { return false; }   // D-05: só preenche vazio
    if ($erroCategoria !== null) { $avisos[] = '...: '.$erroCategoria; return false; }
    try { $this->editor->trocarCategoria($r, $categoria); }
    catch (RegraViolada $e) { $avisos[] = '...: '.$e->getMessage(); return false; }
    return true;
});
```
`schemaQueVale` (:325-328) e `schemaDoRascunho` (:522-535, usa `ClassificadorAtributos` + `ContextoClassificacao($s->condicao, $eixos)`): copiar tal e qual.

**Só gravar atributo do PRODUTO e nada de eixo/embalagem** (`atributosDaIa`, :544-581; molde para a resolução de valores):
```php
$def = $schema->atributo($id);
if ($def === null || $def->papel !== AtributoClassificado::PRODUCT || in_array($id, $idsDeVariacao, true)
    || $def->secao === AtributoClassificado::SECAO_EMBALAGEM) { continue; }
// Lista fechada: o id tem de existir no schema
if ($def->valores !== [] && ! in_array((string) $item['value_id'], array_column($def->valores, 'id'), true)) { continue; }
```
Adaptação D-08/D-13: multivalor gravado com `' | '` (`FichaTecnicaDoProduto::SEPARADOR`) e `valor_id` nulo -> dividir e resolver cada nome contra `$def->valores` com `ChaveCanonica::texto`; gravar a 1ª opção em `value_id/value_name`, todos os ids em `values_multi`, `revisar => true`, `origem => 'portal'`. Número+unidade -> `value_number` (float) + `value_unit`.

**Escrita só das chaves vazias** (:157-185). Usar `mesclarAtributos` (nunca `gravarAtributos`):
```php
foreach ($this->atributosDaIa(...) as $id => $valor) {
    if ($this->preenchido($snap->atributos[$id] ?? null) && ! $sobrescrever) { continue; }
    $atributos[$id] = $valor + ['origem' => 'ia'];      // portal: 'origem' => 'portal'
}
if ($atributos !== []) { $this->repo->mesclarAtributos($r, $atributos); }
```
`preenchido` (:636-641) copiar a regra (value_id '-1' conta como preenchido):
```php
return $valor !== null && (trim((string) ($valor['value_id'] ?? '')) !== ''
    || trim((string) ($valor['value_name'] ?? '')) !== ''
    || isset($valor['value_number']));
```

**Pacote `SELLER_PACKAGE_*`** (:584-597), formato `value_name` "N cm"/"N g":
```php
foreach (['SELLER_PACKAGE_HEIGHT' => ['altura_cm', 'cm'], 'SELLER_PACKAGE_WIDTH' => ['largura_cm', 'cm'],
    'SELLER_PACKAGE_LENGTH' => ['comprimento_cm', 'cm'], 'SELLER_PACKAGE_WEIGHT' => ['peso_g', 'g']] as $id => [$campo, $unidade]) {
    $n = $this->numeroPositivo($p[$campo] ?? null);
    if ($n !== null) { $saida[$id] = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.').' '.$unidade; }
}
```
Fonte do pacote: `LogisticaProduto::pacote(array $volumes)` (`app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php:31-43`; volumes `[{c,l,a,kg}]` -> `c`=max, `l`=max, `a`=soma, `peso_real`=soma kg). Peso em g = `round(peso_real * 1000)`. Não reimplementar a soma.

**Eixos e variantes** (`aplicarVariacoes`, :333-404). Molde para montar `$eixos` e depois `salvarEixos` seguido de UMA chamada `salvarVariantes` com o array completo (Pitfall 4):
```php
$eixos[$id] ??= ['chave' => $id, 'nome' => ..., 'defines_picture' => $schema?->atributo($id)?->definePicture ?? false, 'valores' => []];
$eixos = array_values(array_map(fn ($e) => ['valores' => array_values($e['valores'])] + $e, $eixos));
try { $this->editor->salvarEixos($r, $eixos); }
catch (RegraViolada $e) { $avisos[] = '...: '.$e->getMessage(); return false; }
// depois: casar cada Variante do snapshot com o dado do portal e gravar de uma vez
$this->editor->salvarVariantes($r->fresh(), $porChave);
```
Casar a variante com a variação do portal pelo mapa (`mapaDaVariante`, :407-413, ordena por `ksort` e usa `chaveDoValor`). Diferenças a respeitar: (1) o Sincronizar ACRESCENTA valor de eixo (IA pula se já há eixos, :339); (2) idempotência por `ChaveCanonica::texto(nome)`; (3) SKU igual ao de outra variante conta como vazio (cópia do ancestral por `RegeneradorVariantes`, Pitfall 3).

**Campos aceitos por `salvarVariantes`** (`EditorRascunhoService.php:224`): `['estoque', 'precos', 'atributos', 'estoque_depositos']`; chave ausente = intacta.

**Fotos:** ver a seção do `ImagemAssetService`.

**Idempotência:** só chamar `$this->repo->tocar($r)`/`salvar` quando algo realmente gravou (:219-224), para a 2ª execução não subir `revisao`.

---

### `PublicadorSincronizaPortalService` (modificar)

**Analog:** o próprio arquivo, `app/Services/Publicador/PublicadorSincronizaPortalService.php:23-60`. Preservar o que já funciona: consulta por `company_id`, `$jaTem` pelo `oferta_id`, e a captura do unique:
```php
$produto = PubProduto::create([
    'oferta_id' => $oferta->id, 'company_id' => $company->id, 'mlb_empresa_id' => $empresa?->id,
    'sku' => $oferta->sku, 'nome' => $oferta->nome ?: $oferta->sku, 'origem' => PubProduto::ORIGEM_PORTAL,
]);
...
} catch (QueryException $e) {
    // Corrida no unique pubprod_oferta_uq: outra requisição criou antes — pula.
    if ((string) $e->getCode() !== '23000') { throw $e; }
}
...
Cache::forever('publicador.portal_sincronizado_em.company-'.$company->id, now()->toIso8601String());
Log::info("[Publicador] Sincronizar do Portal: empresa {$company->id} ({$company->name}) — {$criados} produto(s) novo(s).");
```
Acrescentar: agrupar ofertas Simples por `variacao->produto_id` (grupo, `estrutura_produto_id`, âncora = menor `ordem`); adoção do legado sem publicação por UPDATE de `estrutura_produto_id`; mesma captura `23000` para o novo unique `pubprod_eprod_uq`; devolver também o resumo do preenchimento. Log com prefixo `[Publicador]` e `empresa {id} ({name})`.

`PubProduto.php:98-107`: `skuExibido()`/`nomeExibido()` seguem a oferta ao vivo (`$this->oferta?->sku ?? $this->sku`); com `estrutura_produto_id`, devolver código/nome do produto do portal.

---

### `ImagemAssetService::receber` (parâmetro `enviar`) e fotos por grupo

**Analog:** `ImagemAssetService.php:35-57`.
```php
public function receber(PubRascunho $r, string $conteudo, string $nomeOriginal): array
{
    $meta = self::metadados($conteudo);
    $problemas = ValidadorImagem::problemas('nova', $meta, new ContextoValidacao());
    if (array_filter($problemas, fn (Problema $p) => $p->bloqueia())) {
        return ['imagem' => null, 'problemas' => $problemas, 'nova' => false];
    }
    $sha = hash('sha256', $conteudo);
    if ($existente = $r->imagens()->where('sha256', $sha)->first()) {
        return ['imagem' => $existente, 'problemas' => $problemas, 'nova' => false];
    }
    $caminho = "publicador/{$r->id}/{$sha}.".($meta['mime'] === 'image/png' ? 'png' : 'jpg');
    Storage::disk(self::DISCO)->put($caminho, $conteudo);
    $imagem = $r->imagens()->create([... 'upload_status' => PubImagem::PENDENTE]);

    return ['imagem' => $this->enviarAoMl($imagem, $conteudo), 'problemas' => $problemas, 'nova' => true];
}
```
Mudança: assinatura `receber(..., bool $enviar = true)` e na última linha `$enviar ? $this->enviarAoMl($imagem, $conteudo) : $imagem`. Os callers atuais não mudam. Sem `enviar`, a foto fica `pending` e sobe em `enviarPendentes` (D26 intacto). D-15: o serviço do portal converte WebP para JPG (GD) ANTES de chamar `receber`; foto abaixo do lado mínimo vem de `problemas` bloqueantes e é contada no resumo.

**Atribuição ao grupo** (`EditorRascunhoService::colocarFotoNoGrupo`, :265-278): já trava e já é idempotente ("já está no grupo" não grava). Só chamar se o grupo da variante estiver vazio (D-05):
```php
DB::transaction(function () use ($r, $imagem, $grupo) {
    $this->repo->travar($r);
    $atuais = $this->repo->snapshot($r)->imagens;
    $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
    if (in_array((string) $imagem->id, array_map('strval', array_column($doGrupo, 'imagem')), true)) { return; }
    $this->repo->gravarAtribuicoes($r, [...$atuais, ['imagem' => $imagem->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)]]);
    $this->repo->tocar($r);
});
```
Chave do grupo: `ResolvedorGruposImagem::chaveDoGrupo($variante, $eixos, $fotosPorVariante)` (não montar string à mão). Segurança: `caminho` vem do banco; checar `str_starts_with($caminho, "estrutura/{$company}/")` antes de `Storage::disk('local')->get`.

---

### `DadosEfetivosService` (`precos_por_variante`)

**Analog:** `DadosEfetivosService.php:29-65`. `daProduto` devolve cedo quando `oferta_id === null` (:31-33); `daOferta` monta `titulos`/`precos` por `EstruturaPublicacao::LISTING_TYPES`:
```php
$o = EstruturaConjunto::daEmpresa($empresa)->oferta($oferta->id) ?? ['anuncios' => []];
$preco = $this->precificacao->pagina($empresa, [$oferta->id])['por_oferta'][$oferta->id] ?? null;
...
$precos[$listingType] = isset($preco[$tipo]['anunciado']) ? (float) $preco[$tipo]['anunciado'] : null;
```
Reusar `daOferta` por oferta de variante (escopado por `company_id`, SKU casado por `EstruturaOferta::normalizarSku`). Chave nova opcional na resposta; callers atuais ignoram.

---

### `GerarDescricaoIaJob` + `DescricaoIaService` (e `PreencherRascunhoDoPortalJob`)

**Analog:** `app/Jobs/Publicador/GerarPalavrasChaveIaJob.php` + `PalavrasChaveService.php`.

**Job** (todo o arquivo, 70 linhas). Copiar a estrutura: traits (`Dispatchable, InteractsWithQueue, Queueable, SerializesModels`), `tries=1`, `timeout=300`, `failOnTimeout=true`, `PRAZO_S=240`, fila `high` NO CONSTRUTOR (`Queueable` já declara `$queue`):
```php
public int $tries = 1;
public int $timeout = 300;
public bool $failOnTimeout = true;
private const PRAZO_S = 240;

public function __construct(public int $rascunhoId, public string $alvo, public string $pedido, ...)
{
    // Clique de pessoa: fila `high`. No construtor porque `Queueable` já declara `$queue`.
    $this->onQueue('high');
}

public function handle(PalavrasChaveService $servico): void
{
    $r = PubRascunho::find($this->rascunhoId);
    if (! $r) { $servico->falhou($this->rascunhoId, $this->alvo, $this->pedido, 'O rascunho não existe mais.'); return; }
    try {
        $servico->executar($r, ..., microtime(true) + self::PRAZO_S, ...);
        Log::info("[Publicador] IA de palavras-chave ({$this->alvo}) pronta para o rascunho {$r->id}.");
    } catch (\Throwable $e) {
        Log::warning("[Publicador] ... falhou no rascunho {$r->id}: {$e->getMessage()}");
    }
}

public function failed(\Throwable $e): void
{
    Log::error("[Publicador] ... do rascunho {$this->rascunhoId} quebrou: ".$e->getMessage());
    app(PalavrasChaveService::class)->falhou($this->rascunhoId, $this->alvo, $this->pedido, 'A IA não terminou a tempo. Tente de novo.');
}
```
`PreencherRascunhoDoPortalJob` (Pitfall 8, um por produto/grupo) segue o mesmo esqueleto (`onQueue('high')`, `failed()` com `Log::error`), sem `pedido` mas com o resumo no cache.

**Serviço** (`PalavrasChaveService.php`):
- `pedir()` (:73-82): `$pedido = (string) Str::uuid(); Cache::put(self::chave($r->id, $alvo), ['pedido' => $pedido, 'status' => 'rodando', 'valor' => null, 'erro' => null], self::TTL_PEDIDO); GerarPalavrasChaveIaJob::dispatch(...); return $pedido;`
- `estado()` (:85-90): `Cache::get(self::chave(...))`, `is_array` ou null.
- `executar()` (:93-106): grava `pronto`/`erro` só pelo `concluir`, e relança; **não grava no rascunho** (a TELA aplica pelo caminho normal de edição, learnings `publicador-ml.md` §10):
```php
$valor = ...;
if ($valor === '') { throw new \RuntimeException('A IA não devolveu nada aproveitável. Tente de novo.'); }
$this->concluir($r->id, $alvo, $pedido, ['status' => 'pronto', 'valor' => $valor, 'erro' => null]);
} catch (\Throwable $e) {
    $this->concluir($r->id, $alvo, $pedido, ['status' => 'erro', 'valor' => null, 'erro' => $e->getMessage()]);
    throw $e;
}
```
- `concluir()` ignora pedido velho (ver ~:224-232 do arquivo; ler o corpo antes de copiar).
- Cache `publicador:descricao:{rascunho}`, TTL 1800 s.
- Entrada da IA: `AnaliseAnuncioService::descricao($produto, $loja, $specs, $analise)` (:83-90) e `blocoSpecs` (:289-294). Prompt intocado; só montar `$specs` (ficha técnica + medidas + "Descrição fornecida pelo cliente: ..."). Limpar HTML com a regra de `IaParaRascunhoService::limparDescricao` (:644-650):
```php
$texto = preg_replace('~</(p|li|h[1-6]|div)>|<br\s*/?>~i', "\n", (string) $html);
$texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));
```
- D-11: disparo automático ao abrir rascunho vazio (uma vez por rascunho) e botão manual. Ainda assim, "a tela aplica, não aplica se a pessoa digitou no meio".

---

### Editor do Publicador: painel "Descrição do cliente" + botão

**Analog:** `resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx:169-185`:
```jsx
function Descricao({ m }) {
    const texto = m.rasc.descricao ?? '';
    const maximo = m.schema?.limites?.max_description_length ?? null;
    const erro = useErroDoCampo((x) => x.campo === 'descricao', { preenchido: texto.trim() !== '' });
    return (
        <Secao id="descricao" titulo="Descrição" descricao="Texto simples, sem formatação. ...">
            <Campo rotulo="Descrição do anúncio" htmlFor="campo-descricao" erro={erro} dica="..." extra={...}>
                <textarea id="campo-descricao" value={texto} onChange={(e) => m.mudarRasc({ descricao: e.target.value })} disabled={m.disabled} rows={12} ... data-campo="descricao" />
            </Campo>
        </Secao>
    );
}
```
Aplicar o texto tratado SEMPRE por `m.mudarRasc({ descricao })` (mesmo caminho da digitação). O hook de polling segue o do Modelo (`usePublicador.js`/`ferramentas.js` — arquivos com alteração de outra sessão: usar hook novo `useDescricaoIa.js`, tocar o mínimo). `estado().portal.descricao_cliente` vem de `EditorRascunhoService::estado` (lido ao vivo via `produto->oferta->variacao->produto`).

---

## Padrões compartilhados

### Só preenche o vazio, sob trava, pelo motor (D-05)
**Fonte:** `IaParaRascunhoService::sobTrava`/`preenchido`/`intocavel` (:278-295, :636-641, :77-83).
**Aplicar a:** `PortalParaRascunhoService` inteiro. Nunca SQL direto em `pub_*`; sempre `EditorRascunhoService`/`RascunhoRepository`. Travar primeiro, ler snapshot depois, schema (pode ir ao ML) fora da trava. `mesclarAtributos` (`RascunhoRepository.php:138-149`), nunca `gravarAtributos`.

### Migrations MariaDB-seguras
**Fonte:** `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php`. **Aplicar a:** as 3 migrations. Ver seção acima.

### Escopo por empresa e sigilo
**Fonte:** `gravarFichaTecnica` (`findOrFail` escopado por `company_id` ANTES de validar) e `assertSemOrigem`. **Aplicar a:** endpoint de descrição, leitura do portal no Sincronizar (sempre por `company_id` do `Company` resolvido no servidor, nunca por id do corpo), e todo texto novo da ficha.

### Logs
**Fonte:** `PublicadorSincronizaPortalService.php:57`, `GerarPalavrasChaveIaJob.php:58-67`. Prefixo `[Publicador]`, `empresa {id} ({name})`, `failed()` sempre definido. Comentários e mensagens em pt-BR.

### Testes
- `Http::fake()` ÚNICO por teste (1º stub vence) e fake de `CategorySchemaRepository`/`MlCatalogoMetaService` em todo teste novo que passe por schema (Pitfall 11).
- Rodar por pasta, redirecionando para arquivo (suíte inteira estoura 512 MB).
- `git add -- <caminho>` antes de `git commit -- <caminho>` para arquivo novo (Pitfall 13).

## Sem analog

| Arquivo | Papel | Fluxo | Motivo |
|---|---|---|---|
| `app/Services/Publicador/PortalProdutoLeitor.php` | service | leitura agregada (produto + variações + fotos + composição) | Nenhum leitor do portal para o Publicador existe. Usar como referência parcial `DadosEfetivosService::daOferta` (leitura ao vivo escopada por empresa) e `MigracaoAnunciarAntigo::planejar` (:87-151, mapeia atributos/pacote/estoque/fotos do formato antigo; bom modelo de mapeamento). Composição de Combo/Kit/Combit: `EstruturaOfertaComponente(oferta_id, componente_id, quantidade)`; "principal" do Kit pelo par de tipos `estrutura_tipo_pares` (D-12) — NÃO lido nesta passada, o planner deve abrir `estrutura_tipo_pares` e o gerador da Fase 168 antes de planejar o 176-08. |
| Conversão WebP -> JPG (D-15) | utility | transform | Nenhuma conversão com GD no repositório de portal/Publicador foi localizada; `ValidadorImagem::FORMATOS` só aceita JPG/PNG. Confirmar GD em prod (A7). |

## Metadados

**Escopo de busca:** `app/Services/Publicador`, `app/Jobs/Publicador`, `app/Services/Portal/Estrutura/Produtos`, `app/Http/Controllers/PortalEstruturaProdutosController.php`, `app/Models/PubProduto.php`, `routes/web.php`, `database/migrations`, `resources/js/{lib,Components/Portal/Estrutura/Produtos,Components/Publicador/Mesa}`, `tests/Feature/{PortalCliente,Publicador}`.
**Arquivos lidos:** ~22.
**Colisão com outras sessões:** `AnaliseAnuncioService.php`, `PalavrasChaveService.php`, `GerarPalavrasChaveIaJob.php`, `usePublicador.js`, `ferramentas.js`, `MlbPublicadorController.php` têm alterações não commitadas de outra sessão. Esses arquivos são analogs para LEITURA; a fase deve criar arquivos novos e reler `git status` antes de cada plano.
**Data da extração:** 2026-10-08
