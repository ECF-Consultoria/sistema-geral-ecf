# Fase 167: Cadastro de Produto no Mapeamento Estrutural - Mapa de Padrões

**Mapeado em:** 2026-10-05 (worktree `C:/tmp/ecf-publicador-spec-261001`)
**Arquivos analisados:** 36 (novos e modificados)
**Analogs encontrados:** 33 / 36 (3 sem analog direto, ver "Sem analog")

Todos os caminhos são relativos à raiz do worktree. Números de linha vêm da leitura feita nesta sessão.

## Classificação dos arquivos

### Backend: migrations e models

| Arquivo novo/modificado | Papel | Fluxo de dados | Analog mais próximo | Qualidade |
|---|---|---|---|---|
| `database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php` | migration | DDL (tabelas novas) | `database/migrations/2026_09_23_160000_create_estrutura_tables.php` | exato |
| `database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` | migration | DDL idempotente em tabela viva | `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` | exato |
| `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (modifica lista `MIGRACOES`) | test | varredura estática | o próprio arquivo | exato |
| `app/Models/EstruturaFamilia.php`, `EstruturaAmbiente.php` | model | CRUD | `app/Models/EstruturaOferta.php` | role-match |
| `app/Models/EstruturaProduto.php`, `EstruturaProdutoVariacao.php`, `EstruturaProdutoVolume.php` | model | CRUD | `app/Models/EstruturaOferta.php` (+ `EstruturaOfertaComponente.php`) | role-match |
| `app/Models/EstruturaOferta.php` (modifica: `variacao_id`, `fillable`, relação) | model | CRUD | o próprio arquivo | exato |

### Backend: services

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` | service | CRUD em lote transacional | `app/Services/Portal/Estrutura/EstruturaOfertaService.php` | exato |
| `.../Produtos/ListasDaEmpresaService.php` | service | CRUD | `EstruturaOfertaService::excluir` (recusa em uso) | role-match |
| `.../Produtos/ImportadorProdutos.php` | service | batch (plano/aplicar) | `app/Services/Portal/Estrutura/ColagemAnunciosService.php` | exato |
| `.../Produtos/ModeloProdutosXlsx.php` | service | file-I/O (escrita xlsx) | `PolosController::streamXlsx` (linhas 885-930) | role-match |
| `.../Produtos/LogisticaProduto.php`, `TabelaFreteEcf.php`, `PendenciasDoProduto.php`, `VolumesTexto.php`, `NumeroBr.php` | utility | transform (funções puras) | `app/Services/Portal/Estrutura/PrecificacaoEstrutura.php` | exato |
| `.../Produtos/FreteMe2Service.php` | service | request-response (API ML) + cache | `app/Services/Publicador/EditorRascunhoService.php` (277-281, 349-359) + `MercadoLivreService::getMany` (349-383) | role-match |
| `app/Services/Portal/Estrutura/EstruturaOfertaService.php` (modifica: criar com `variacao_id`, `sincronizarDaVariacao`, proteção na oferta ligada) | service | CRUD | o próprio arquivo | exato |
| `app/Services/Portal/Estrutura/EstruturaPrecificacaoService.php` (modifica `pagina`/`calcular`/`salvarOferta`) | service | transform | o próprio arquivo | exato |
| `app/Services/Portal/Estrutura/EstruturaConjunto.php`, `EstruturaVisaoService.php` (modifica: expor `variacao_id`) | service | leitura | os próprios | exato |
| Sugestão de categoria (reuso, sem arquivo novo) | service | request-response | `app/Services/Incubadora/Publicador/CategoriaSugestaoService.php` | exato |

### Backend: HTTP, config e integração

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `app/Http/Controllers/PortalEstruturaProdutosController.php` | controller | request-response (Inertia + JSON + download) | `app/Http/Controllers/PortalEstruturaController.php` | exato |
| `routes/web.php` (modifica: grupo `portal.auth`, linhas ~200-265) | route | request-response | as rotas `estrutura.*` existentes | exato |
| `app/Http/Middleware/RestringeDominioDoPortal.php` (modifica `PERMITIDO`, linhas ~119-146) | middleware | allowlist | o próprio arquivo | exato |
| `app/Support/Portal/ModulosPortal.php` (modifica `SUBMODULOS`, linhas 106-114) | config | catálogo | o próprio arquivo | exato |
| `config/estrutura_produtos.php` | config | regras globais | `config/publicador.php` | role-match |

### Frontend

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `resources/js/Pages/Portal/EstruturaProdutos.jsx` | component (página Inertia) | CRUD + autosave | `resources/js/Pages/Portal/EstruturaLista.jsx` (casca) + `Pages/Mlb/ImplementacaoPublica.jsx` (uso da grade) | role-match |
| `resources/js/Components/SpreadsheetGrid.jsx` (modifica, aditivo) | component | event-driven | o próprio arquivo | exato |
| `resources/js/Components/Portal/Estrutura/Produtos/*` (pickers, listas, prévia de importação, sheet mobile) | component | request-response | `PreviaColagem.jsx`, `Janela.jsx`, `FormOferta.jsx` | role-match |
| `resources/js/lib/produtosEstrutura.js` | utility | só formatação/colunas | `resources/js/Components/Portal/Estrutura/comum.jsx` (`fmtReais`, linha 31) | parcial |
| `resources/js/Pages/Portal/EstruturaPrecificacao.jsx`, `EstruturaLista.jsx` (modifica: origem `produto`, selo "do Produtos") | component | CRUD | os próprios | exato |

### Testes

| Arquivo | Papel | Analog | Qualidade |
|---|---|---|---|
| `tests/Feature/PortalCliente/Estrutura/ProdutosCadastroTest.php` e afins | test (feature) | `tests/Feature/PortalCliente/Estrutura/ColagemEEsperaTest.php` | exato |
| `tests/Unit/.../LogisticaProdutoTest.php`, `NumeroBrTest.php`, `VolumesTextoTest.php` | test (unit, função pura) | `tests/Feature/PortalCliente/Estrutura/PrecificacaoEstruturaTest.php` | role-match |
| `tests/js/estrutura-produtos.test.js`, extensões do grid | test (gate estrutural) | `tests/js/estrutura-grid-textarea.test.js` + `tests/js/_fonte.js` | exato |
| `PortalSemAnunciarTest.php` (110-124) e `AcessoAoModuloEstruturaTest.php` (60) (atualizar, quebram de propósito) | test | os próprios | exato |

## Atribuições de padrão

### `database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php` (migration, DDL)

**Analog:** `database/migrations/2026_09_23_160000_create_estrutura_tables.php`

**Cabeçalho de decisão + armadilhas de MariaDB** (linhas 8-24): docblock que registra a decisão de schema (CLAUDE.md, disciplina 2), sem `enum`, `timestamps()` nullable, nomes curtos.

**Padrão de tabela com FK nomeada** (linhas 33-47):
```php
Schema::create('estrutura_ofertas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies', 'id', 'eo_company_fk')->cascadeOnDelete();
    $table->string('sku', 120);
    ...
    $table->timestamps();
    $table->index('company_id', 'eo_company_idx');
});
Schema::create('estrutura_oferta_componentes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'eoc_oferta_fk')->cascadeOnDelete();
    $table->foreignId('componente_id')->constrained('estrutura_ofertas', 'id', 'eoc_componente_fk')->restrictOnDelete();
    $table->unsignedSmallInteger('quantidade');
    $table->unique(['oferta_id', 'componente_id'], 'eoc_oferta_comp_unq');
});
```
Aplicar: nomes do RESEARCH (`efam_company_fk`, `epr_familia_fk` com `nullOnDelete` em coluna nullable, `epv_company_cod_uq`, pivot `estrutura_produto_ambiente` com PK composta e FKs cascade). `familia_id` precisa ser `unsignedBigInteger()->nullable()` declarado ANTES do `foreign(...)->nullOnDelete()` (erro 1830 se NOT NULL). Nenhum `json` (LONGTEXT no MariaDB 10.4): `categoria_ml_caminho` é `text`.

---

### `database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` (migration, DDL idempotente em tabela viva)

**Analog:** `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` (copiar o esqueleto e os três helpers).

**Helpers a copiar integralmente** (linhas 155-198): `emMysql()` (mysql OU mariadb, WR-B06), `hasIndex()`, `hasForeignKey()`. Ajustar o literal da coluna no `PRAGMA foreign_key_list` (linha 192: `'produto_id'` vira `'variacao_id'`).

**Núcleo do `up()`**, cada DDL em `Schema::table` separado e sob checagem de existência (linhas 42-46, 93-103):
```php
if (! Schema::hasColumn('pub_rascunhos', 'produto_id')) {
    Schema::table('pub_rascunhos', function (Blueprint $t) {
        $t->unsignedBigInteger('produto_id')->nullable()->after('id');
    });
}
if (! $this->hasIndex('pub_rascunhos', 'pubr_produto_uq')) {
    Schema::table('pub_rascunhos', function (Blueprint $t) { $t->unique('produto_id', 'pubr_produto_uq'); });
}
if (! $this->hasForeignKey('pub_rascunhos', 'pubr_produto_fk')) {
    Schema::table('pub_rascunhos', function (Blueprint $t) {
        $t->foreign('produto_id', 'pubr_produto_fk')->references('id')->on('pub_produtos')->cascadeOnDelete();
    });
}
```
Diferenças desta fase: coluna fica **nullable para sempre**, FK usa `->nullOnDelete()` (válido porque nullable), unique `eo_variacao_uq`, FK `eo_variacao_fk`. **Sem backfill** (D-09), então os passos 3 e 4 do analog NÃO se copiam.

**`down()`** (linhas 129-143): FK primeiro (no SQLite pela forma de coluna), depois unique, depois coluna:
```php
if ($this->hasForeignKey('pub_rascunhos', 'pubr_produto_fk')) {
    Schema::table('pub_rascunhos', function (Blueprint $t) {
        $t->dropForeign($this->emMysql() ? 'pubr_produto_fk' : ['produto_id']);
    });
}
```
Regra do analog (linhas 27-29): **proibido `try/catch` ao redor de DDL**. Não tocar `eo_company_idx` nem `eo_company_fk`.

---

### `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` (service, CRUD transacional em lote)

**Analog:** `app/Services/Portal/Estrutura/EstruturaOfertaService.php`

**Imports e DI** (linhas 5-11, 35-37):
```php
use App\Models\Company;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
public function __construct(private EstruturaAnuncioService $anuncios, private SoltarProdutoDaOfertaService $publicador) {}
```

**Padrão de escrita: transação + serviço de oferta + um log por ação** (linhas 43-59). `gravarLinhas` chama `EstruturaOfertaService::criar` (herda `varrerEspera` e o log `oferta_criada`) dentro de `DB::transaction`, e registra UM evento por lote (`produtos_gravados`, com totais), como `anuncios_colados`:
```php
return DB::transaction(function () use ($empresa, $campos, $componentes, $ator) {
    $oferta = EstruturaOferta::create([...$campos, 'company_id' => $empresa->id]);
    $absorvidos = $this->varrerEspera($empresa, [$oferta->sku]);
    RegistroEstrutura::registrar($ator, $empresa, $oferta, 'oferta_criada',
        "Oferta {$oferta->sku} criada ({$oferta->fase})", ['absorvidos_da_espera' => $absorvidos]);
    return [$oferta, $absorvidos];
});
```

**Validação por linha com `ValidationException::withMessages`** (linhas 288-323, método `campos()`): acumula `$erros[...]`, lança no fim; trim, `mb_strlen` e `mb_substr`. Para a grade, a diferença é que erro de UMA linha vira `erros[]` na resposta e não derruba as demais (padrão da colagem: "N linha(s) com erro não gravada(s)").

**Excluir variação** (D-22, linhas 193-231): reusar `EstruturaOfertaService::excluir` DENTRO da transação e só depois apagar a variação. Já entrega: bloqueio quando é componente (mensagem lista as ofertas), anúncios voltam à espera, `SoltarProdutoDaOfertaService::antesDeExcluir` (D27).
```php
$usos = $oferta->usadaEm()->with('oferta:id,sku')->get();
if ($usos->isNotEmpty()) {
    throw ValidationException::withMessages([
        'oferta' => "Esta oferta entra em {$lista}. Exclua ou edite essas variações antes.",
    ]);
}
```

**Resolução por empresa** (id de outra empresa = mesma resposta de inexistente): comentário das linhas 375-377 de `composicao()` e `PortalEstruturaController::oferta()` (linhas 462-465).

---

### `app/Services/Portal/Estrutura/EstruturaOfertaService.php` (MODIFICA; service, CRUD)

**Analog:** o próprio arquivo. Pontos de inserção:

- `criar()` (linha 43): aceitar `variacao_id` opcional (e `campos()` linha 316-322 devolver o campo), OU método irmão `criarParaVariacao`. Reusar o corpo de `criar` para manter `composicao()` e `varrerEspera`.
- `atualizar()` (linha 150): se `$oferta->variacao_id !== null`, ignorar/recusar `sku`, `nome`, `fase` (logística e observações seguem editáveis). Mesmo estilo de recusa da linha 158-162:
```php
if ($campos['fase'] !== EstruturaOferta::FASE_SIMPLES && $oferta->usadaEm()->exists()) {
    throw ValidationException::withMessages(['fase' => '...']);
}
```
- `excluir()` (linha 193): recusar com "Esta oferta vem do Produtos; exclua a variação lá." quando `variacao_id` preenchido, exceto quando chamado via produto (parâmetro `viaProduto`).
- Método novo estreito `sincronizarDaVariacao(EstruturaOferta, sku, nome, ator)`: copiar a lógica do trecho de SKU mudado (linhas 171-178), incluindo `varrerEspera([skuAntigo, skuNovo])` e `RegistroEstrutura::registrar(... 'oferta_editada' ...)`. NÃO usar `atualizar()` (reescreve fase e componentes, linhas 167-169).

---

### `app/Models/EstruturaOferta.php` (MODIFICA)

**Analog:** o próprio arquivo, linha 64 e relações 66-90:
```php
protected $fillable = ['company_id', 'sku', 'fase', 'nome', 'logistica', 'observacoes'];
public function company(): BelongsTo { return $this->belongsTo(Company::class); }
public function usadaEm(): HasMany { return $this->hasMany(EstruturaOfertaComponente::class, 'componente_id'); }
```
Acrescentar `variacao_id` ao `$fillable` e `variacao(): BelongsTo`. Constantes de vocabulário seguem o padrão `FASES`/`LOGISTICAS` (linhas 29-52): para o eixo da variação, constante na model nova (`EstruturaProdutoVariacao::EIXOS`) espelhada em JS, sem `enum` (D-20).

---

### `app/Services/Portal/Estrutura/EstruturaPrecificacaoService.php` (MODIFICA; D-10)

**Analog:** o próprio arquivo. Único ponto de leitura do custo (RESEARCH §Custo).

**Ponto de alteração em `pagina()`** (linhas 32-38):
```php
$linhas = EstruturaPrecificacao::query()
    ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_precificacoes.oferta_id')
    ->where('o.company_id', $empresa->id)
    ->get(['estrutura_precificacoes.*'])
    ->keyBy('oferta_id');
$custos = $linhas->map(fn (EstruturaPrecificacao $l) => $l->custo)->all();
```
Logo depois, sobrescrever com o custo da variação (inclusive `null`) para toda oferta com `variacao_id`, via um join `estrutura_ofertas x estrutura_produto_variacoes` filtrado por `company_id`. Esse mesmo `$custos` alimenta combo/kit/combit através de `PrecificacaoEstrutura::custo($linha?->custo, $o['componentes'], $custos)` (linha 114), então nada em `PrecificacaoEstrutura` muda. Em `calcular()`, devolver `origem = 'produto'` quando ligada.

**`salvarOferta()`** (linhas 82-99): recusar `custo` em oferta ligada com `ValidationException::withMessages(['custo' => ...])`, no estilo de `dinheiro()` (linhas 145-155).

**Padrão para a conta de frete/preço de cotação:** `PrecificacaoEstrutura::preco(custo, frete, comissao, imposto, mc, ll, acrescimo)` retorna `['minimo','anunciado','impossivel','sem_frete']` (linhas 31-40); `EstruturaPrecificacaoParametros::daEmpresa($empresa->id)` entrega os parâmetros (linha 30).

---

### `app/Services/Portal/Estrutura/Produtos/ImportadorProdutos.php` (service, batch plano/aplicar)

**Analog:** `app/Services/Portal/Estrutura/ColagemAnunciosService.php`

**Duas etapas e a segunda não confia na primeira** (docblock linhas 17-22, métodos 71-124):
```php
public function previa(Company $empresa, string $texto, string $modo, ?int $maxLinhas = null, array $skusFuturos = []): array
{
    $plano = $this->plano($empresa, $texto, $modo, $maxLinhas, $skusFuturos);
    $grupos = [];
    foreach (['novos', 'atualizados', 'espera', 'erros', 'removidos'] as $g) {
        $grupos[$g] = array_slice($plano[$g], 0, self::DETALHE_MAXIMO);   // DETALHE_MAXIMO = 200
    }
    return ['erro_geral' => ..., 'totais' => array_map('count', ...), 'grupos' => $grupos];
}

public function aplicar(Company $empresa, string $texto, string $modo, AtorDoPortal $ator, ?int $maxLinhas = null): array
{
    return DB::transaction(function () use (...) {
        $plano = $this->plano($empresa, $texto, $modo, $maxLinhas);   // REFAZ o plano do zero
        if ($plano['erro_geral'] !== null) { return ['erro_geral' => $plano['erro_geral']]; }
        foreach ([...$plano['novos'], ...$plano['atualizados'], ...$plano['espera']] as $item) { $this->executar($empresa, $item); }
        RegistroEstrutura::registrar($ator, $empresa, null, 'anuncios_colados', "...", ['modo' => $modo, 'totais' => $totais]);
        return $totais;
    });
}
```
Diferenças: a entrada é o arquivo `.xlsx` reenviado (não texto); grupos são `novos / atualizados / sem_mudanca / erros` mais `criar_listas {familias[], ambientes[]}`; não existe modo "substituir" (D-14, nada é apagado); chave de casamento = código da variação normalizado dentro da empresa (`EstruturaOferta::normalizarSku`). Reuso do `LeitorColagemAnuncios` como referência de leitor com `erros[]` por linha e `erro_geral`; a leitura xlsx usa `IOFactory::createReaderForFile($caminho)->setReadDataOnly(true)` (precedente `app/Console/Commands/ImportarDemandasDevPlanilha.php:59`), sem `getCalculatedValue()`.

---

### `app/Services/Portal/Estrutura/Produtos/ModeloProdutosXlsx.php` (service, file-I/O)

**Analog:** `app/Http/Controllers/PolosController.php::streamXlsx` (linhas 885-930)

```php
$planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$folha    = $planilha->getActiveSheet();
$folha->setTitle(mb_substr($nomeAba, 0, 31));
$letraDe = fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
$folha->getStyle("A1:{$ultimaLetra}1")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
]);
$folha->setCellValueExplicit($celula, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
```
Aplicar: aba `Produtos` com as 11 colunas da planilha real, 1 linha de exemplo FICTÍCIA, aba `Instruções`; `TYPE_STRING` explícito em tudo (evita notação científica e injeção de fórmula). Resposta por streaming, rota GET comum (link `<a href>`).

---

### `app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php`, `TabelaFreteEcf.php`, `PendenciasDoProduto.php`, `VolumesTexto.php`, `NumeroBr.php` (utility, funções puras)

**Analog:** `app/Services/Portal/Estrutura/PrecificacaoEstrutura.php`

Padrão: `final class` com métodos `static`, constantes `PENDENCIA_*`, nenhum acesso a banco, percentuais/valores recebidos por parâmetro; docblock cita o ADR e a lição de que "duas cópias desta conta já publicaram preço 43% errado" (linhas 3-19). `LogisticaProduto` segue o esqueleto do RESEARCH §Exemplos de Código (regras vindas de `config('estrutura_produtos')`, peso REAL nos limites ME2/Full, nunca literais).

Gabarito de teste unitário (RESEARCH §Logística): `93×55×6, 9,5` ME2; `91×59×20, 13,5` ME2 com faturado 17,90; `131×48×17, 33` ME1; `186×43×24, 39,9` ME1; sem volume pendente.

---

### `app/Services/Portal/Estrutura/Produtos/FreteMe2Service.php` (service, request-response + cache)

**Analogs:** `app/Services/Publicador/EditorRascunhoService.php` (chamada) e `app/Services/MercadoLivreService.php::getMany` (lote). **Não** usar `ClienteMlPublicador::daConta` em laço (faz `sleep()` até 16 s dentro da requisição web).

**Chamada e leitura do valor** (EditorRascunhoService linhas 277-281):
```php
$f = $this->cliente->daConta($r->conta(), 'GET', "/users/{$conta['sellerId']}/shipping_options/free", [
    'item_price' => $preco, 'listing_type_id' => $alvo->listingTypeId, 'mode' => 'me2', 'condition' => 'new',
    'logistic_type' => 'drop_off', 'dimensions' => $pacote, 'verbose' => 'true',
]);
$frete = $f->ok() && isset($f->corpo['coverage']['all_country']['list_cost']) ? (float) $f->corpo['coverage']['all_country']['list_cost'] : null;
```

**Frete grátis obrigatório decidido pela resposta, nunca por limite no código** (linhas 353-358):
```php
$cobertura = $f->ok() && is_array($f->corpo) ? ($f->corpo['coverage']['all_country'] ?? null) : null;
return ($cobertura['discount']['type'] ?? null) === 'mandatory' && empty($cobertura['free_shipping_by_meli']);
```

**Lote paralelo** (`MercadoLivreService::getMany`, linhas 349-383): assinatura `getMany(ContaMercadoLivre $company, array $pedidos, int $porLote = 10)`, `pedidos` = `chave => [endpoint, query]`; falha de um pedido volta como `\Throwable` naquela chave; levanta `\RuntimeException` sem token (capturar e cair na tabela ECF).

**Conta conectada:** `AnunciosMercadoLivreService::conectado($empresa)` (linha 124: `$empresa->mlToken?->status === 'active'`); seller id em `$empresa->mlToken->ml_user_id`.

**Dimensions:** `"{altura}x{largura}x{comprimento},{peso_em_gramas}"`, inteiros (ordem AxLxC, não CxLxA). Cache `Cache::put("estrutura:frete:v1:{company_id}:{dimensions}:{round(preco)}", ..., 6h)`; máx. ~12 cotações novas por requisição. Todo valor sai com origem `api` | `tabela_ecf` | `referencia`.

---

### Sugestão de categoria (reuso, sem arquivo novo; D-06)

**Analog:** `app/Services/Incubadora/Publicador/CategoriaSugestaoService.php`

`sugerir(string $produto)` devolve até 8 `{id, nome, dominio, caminho:[{id,nome}]}`; `detalhe($id)` devolve `{id, nome, caminho, folha}` (necessário para validar folha). Construtor `(MlCatalogoMetaService $meta, MlColetaService $coleta)`; dado público via app token; cache `ml_meta_categoria_{id}` de 7 dias (linhas 24-34) compartilhado com o wizard. O controller injeta o serviço e expõe `GET produtos/categorias?q=` e `POST produtos/categorias/sugerir` (lotes de até 10).

---

### `app/Http/Controllers/PortalEstruturaProdutosController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/PortalEstruturaController.php` (NÃO engordar esse; 531 linhas)

**Docblock de contrato** (linhas 25-39): empresa SEMPRE do `PortalContexto`; id de outra empresa responde 404 igual ao inexistente.

**Imports e DI** (linhas 5-52): `PortalClienteService`, `ModulosPortal`, `PortalContexto`, `Inertia`, `Rule`; serviços por construtor promovido.

**Render de página** (linhas 78-90, `lista()`): o padrão de props do portal.
```php
return Inertia::render('Portal/EstruturaLista', [
    ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.lista', PortalContexto::ator()),
    'estrutura'      => $this->visao->paginaOfertas($empresa, 'todas', $busca, (int) $request->query('pagina', 1)),
    'filtros'        => ['q' => $busca],
    'vocabulario'    => EstruturaVisaoService::vocabulario(),
    'ml_conectado'   => AnunciosMercadoLivreService::conectado($empresa),
    'opcoes_ofertas' => Inertia::optional(fn () => $this->visao->opcoesDeOfertas($empresa)),
]);
```
A chave ativa é `ModulosPortal::ESTRUTURA.'.produtos'` (só `estrutura` acende o módulo e nenhum submódulo).

**Prévia JSON + confirmação com flash** (linhas 293-318): `previaColagem` devolve `response()->json(...)`; `aplicarColagem` devolve `back()->withErrors(['texto' => $erro_geral])` ou `back()->with('success', '...')` (flash `success`, chave já compartilhada; chave nova exigiria linha em `HandleInertiaRequests`).

**Resolução por empresa** (linhas 462-465, 482-485):
```php
private function oferta(int $id): EstruturaOferta
{
    return EstruturaOferta::where('company_id', $this->empresaId())->findOrFail($id);
}
```
Copiar para variação/produto/família/ambiente.

**Validação inline** (linhas 489-500): `$request->validate([... Rule::in(array_keys(...)) ...])`.

**Excluir com mensagem de anúncios** (linhas 248-257): conta `anuncios()->count()` antes e monta o texto de sucesso. Para D-22 a tela já mostra a contagem na confirmação; o controller devolve o mesmo texto.

**Ator:** sempre passar `PortalContexto::ator()` ao serviço (esquecer grava sem `origem`).

---

### `routes/web.php` (MODIFICA)

**Analog:** linhas 200-265 do próprio arquivo (grupo `portal.auth`). Regra: `whereNumber` nos ids e `throttle:N,1,<prefixo-próprio>` em TODA rota (o 3º parâmetro isola o contador; sem ele o cliente toma 429 espúrio, `web.php:177-185`).
```php
Route::get('/estrutura/lista', [PortalEstruturaController::class, 'lista'])->name('portal.auth.estrutura.lista');
Route::put('/estrutura/ofertas/{oferta}', [PortalEstruturaController::class, 'atualizarOferta'])
    ->whereNumber('oferta')->middleware('throttle:60,1,estrutura.ofertas.atualizar')->name('portal.auth.estrutura.ofertas.atualizar');
Route::post('/estrutura/colagem/previa', [PortalEstruturaController::class, 'previaColagem'])
    ->middleware('throttle:30,1,estrutura.colagem.previa')->name('portal.auth.estrutura.colagem.previa');
Route::post('/estrutura/colagem', [PortalEstruturaController::class, 'aplicarColagem'])
    ->middleware('throttle:10,1,estrutura.colagem')->name('portal.auth.estrutura.colagem');
```
Nomes novos: `portal.auth.estrutura.produtos`, `.produtos.modelo`, `.produtos.linhas`, `.produtos.importacao.previa`, `.produtos.importacao`, `.produtos.familias`, etc. `entrada()` (linhas 64-71) implementa D-21 (escolher Produtos ou Lista SKUs conforme a empresa).

---

### `app/Http/Middleware/RestringeDominioDoPortal.php` (MODIFICA)

**Analog:** o próprio, `PERMITIDO`, linhas 119-146. Uma linha por rota nova, sem curinga genérico:
```php
'portal/estrutura',
'portal/estrutura/lista',
'portal/estrutura/precificacao/parametros',
'portal/estrutura/ofertas/*/precificacao',
'portal/estrutura/colagem',
'portal/estrutura/colagem/previa',
```
Atenção: `Str::is('a/*')` atravessa `/`; NÃO criar `portal/estrutura/produtos/*` genérico. A rede de segurança é `DominioLiberaTodoModuloTest` (falha se uma rota `portal.auth.*` ficar fora da allowlist).

---

### `app/Support/Portal/ModulosPortal.php` (MODIFICA)

**Analog:** o próprio, `SUBMODULOS`, linhas 106-114. Inserir `produtos` como PRIMEIRO (a ordem de inserção é a ordem do menu):
```php
private const SUBMODULOS = [
    self::ESTRUTURA => [
        'produtos'     => ['rotulo' => 'Produtos',     'rota_auth' => 'portal.auth.estrutura.produtos'],
        'lista'        => ['rotulo' => 'Lista SKUs',   'rota_auth' => 'portal.auth.estrutura.lista'],
        ...
```
Atualizar também o docblock da ordem (linhas 94-99). Testes que quebram de propósito: `PortalSemAnunciarTest.php:110-124` e `AcessoAoModuloEstruturaTest.php:60`.

---

### `config/estrutura_produtos.php` (config, regras globais)

**Analog:** `config/publicador.php` (linhas 1-40): docblock "toda hipótese vira opção aqui em vez de regra fixa", chaves comentadas com referência de origem. Conteúdo (RESEARCH §Padrão 3): `fator_cubagem` 6000, `peso_cubado_minimo` 5, `me2`, `full`, `frete.preco_referencia`, `frete.vigente_desde`, `frete.faixas_preco`, `frete.tabela`. Comentário explícito de que as faixas 0/19/49/79/... são limites de coluna da tabela do ML, NÃO a regra de frete grátis obrigatório (nenhum literal `79` em lógica; gate de teste procura o literal fora do config).

---

### `resources/js/Pages/Portal/EstruturaProdutos.jsx` (página Inertia, CRUD com autosave)

**Analogs:** `resources/js/Pages/Portal/EstruturaLista.jsx` (casca da página) e `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` (uso da grade).

**Imports e casca** (EstruturaLista linhas 1-10):
```jsx
import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronRight, DownloadCloud, Layers, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, FotoProduto, Paginacao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { cn } from '@/lib/utils';
```
Cabeçalho comentado em pt-BR explicando a decisão da tela (linhas 12-27). Pílulas por mapa de estilo (linhas 29-42, `ESTILO_FASE` + `PilulaFase`): copiar para ME2/ME2·Full/ME1/Pendente com as cores do UI-SPEC. Ações por linha com `aria-label` (linhas 53-60).

**Uso da grade** (ImplementacaoPublica linhas 217-232 e 497):
```jsx
const PRODUTOS_COLS = [
    { id: 'curva', label: 'Curva', type: 'select', options: ['Curva A', 'Curva B', 'Curva C'], width: 100 },
    { id: 'sku',   label: 'SKU',   type: 'text',   width: 120 },
    ...
    { id: 'descricao', label: 'Descrição', type: 'textarea', width: 200 },
];
<SpreadsheetGrid columns={PRODUTOS_COLS} rows={rows} onChange={handleChange} minRows={10} exportFilename="produtos" showImportExport={false} />
```
Persistência: o analog grava o array inteiro (autosave 800 ms); aqui é diff por linha (`rowKey` + `onRowsCommit(prev, next)`) para `POST /produtos/linhas`. Colunas calculadas usam `compute: row => row.logistica_rotulo`, lendo só o que o servidor devolveu (a conta nunca é duplicada no JS).

---

### `resources/js/Components/SpreadsheetGrid.jsx` (MODIFICA, extensões aditivas; D-12)

**Analog:** o próprio arquivo (1.084 linhas). Todas as extensões são props opcionais; default = comportamento atual (a Planilha de Produtos do Onboarding é página pública com cliente).

Assinatura atual (linha 206-212): `SpreadsheetGrid({ columns, rows, onChange, minRows = 10, headerGroups = null, exportFilename = 'planilha', showImportExport = true })`. Tipos de coluna documentados nas linhas 190-203.

**Lacuna 1: colar descarta excedente** (linhas 565-579). `navigator.clipboard.readText()` e `if (tr < ctx.current.R && ...)` descarta o que passa de `R`. Trocar por evento DOM `paste` (`clipboardData.getData('text')`) com `growOnPaste`, criando linhas e mapeando por cabeçalho quando a 1ª linha colada tem os nomes do modelo (o mapeamento por nome já existe no import de arquivo, linhas 527-545: `headers.findIndex(h => h === col.label.toLowerCase() || h === col.id.toLowerCase())`).

**Lacuna 2: Tab** (linha 588): hoje `clamp(c+(e.shiftKey?-1:1),0,C-1)` fica na mesma linha, não pula `readonly`/`compute`, não cria linha. Estender com `tabWrap`. Enter (linhas 589-593) já abre editor para `select`/`tags`/`textarea`: incluir `picker`.

**Lacuna 3: tipo `picker`**: copiar o desenho do `TextareaPopup` (linha 37, estado `textareaPopup` linha 258, grava ao fechar). `renderEditor`/`onPick`; resumo na célula; popover vira `Sheet` no mobile.

**Lacuna 4: `rowKey` + `onRowsCommit`**, `stickyCols` (a coluna já tem `frozen`, linha 198).

Gate estrutural obrigatório em `tests/js/` no padrão de `estrutura-grid-textarea.test.js` (ver abaixo).

---

### `resources/js/Components/Portal/Estrutura/Produtos/*` (componentes)

**Analogs:** `PreviaColagem.jsx` (prévia de importação), `Janela.jsx` (janelas), `comum.jsx` (botões, campos).

**Prévia agrupada** (`PreviaColagem.jsx` linhas 11-17, 50-75): array `GRUPOS = [{chave, rotulo, cor}]`, `abertos` por grupo, `previa.totais[g.chave]`, `previa.grupos[g.chave]`, `data-previa` e `data-grupo` para teste. Mapear: novos (emerald), atualizados (sky), sem mudança (white/45), erros (red-300). O UI-SPEC pede título "Prévia — nada foi gravado ainda" em caixa normal (o analog usa `uppercase tracking-wide`, que o UI-SPEC proíbe: não copiar essa classe).

**Janela** (`Janela.jsx` linhas 11-23): `Janela({ aberta, onFechar, titulo, descricao, largura = 'max-w-lg', children })`; `grid-cols-1` obrigatório (comentário explica o motivo).

**Primitivos reaproveitados** de `comum.jsx`: `Botao` (variante, linha 247), `Campo` (266), `CLASSE_INPUT` (277), `Seletor` (282), `AvisoFlash` (301), `CabecalhoEstrutura` (335, com `etapa="produtos"`), `Paginacao` (521, `rotulo`), `fmtReais` (31).

---

### `resources/js/lib/produtosEstrutura.js` (utility, só formatação/colunas)

**Analog parcial:** `fmtReais` em `comum.jsx:31`. Regra (D-07 e RESEARCH anti-padrões): só formatação e definição de colunas; NÃO importar nem estender `agruparFamilias` de `resources/js/lib/precificacaoProdutos.js`, e NÃO duplicar cubagem/ME2/Full em JS.

---

### Testes

**Feature (PHP)** analog `tests/Feature/PortalCliente/Estrutura/ColagemEEsperaTest.php` (linhas 1-50):
```php
class ColagemEEsperaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;
    ...
    $empresa = $this->empresaDoGabarito();
    $ator = $this->atorCliente($empresa);
```
Fixtures: `tests/Concerns/GabaritoDaPlanilhaEstrutural` (`empresaDoGabarito`, `atorCliente`, `listaDoGabarito`), `tests/Concerns/EntraNoPortal` (`clienteDoPortal`, `entrarNoPortal`; sessão do guard `portal`, não `actingAs`), `EquipeNoPortalTest` para o caminho de equipe. Docblock da classe descreve o modo de falha que o teste impede (linhas 14-21). Casos obrigatórios: id de outra empresa = 404; `origem` cliente/interno no activity log; colisão de acento ("Cômoda"/"Comoda") capturada como 23000; custo da oferta ligada lido pela Precificação; excluir variação com anúncios volta à espera.

**JS (gate estrutural)** analog `tests/js/estrutura-grid-textarea.test.js` + `tests/js/_fonte.js`:
```js
import { lerSemComentarios } from './_fonte.js';
const fonte = lerSemComentarios('resources/js/Components/SpreadsheetGrid.jsx');
assert.match(fonte, /function TextareaPopup\(\{[^}]*\bonSave\b[^}]*\bonCancel\b[^}]*\}\)/);
```
O helper tira comentários antes de casar, para o gate não passar pelo comentário em pt-BR.

**Migração e MariaDB:** acrescentar as duas migrations novas à lista `MIGRACOES` de `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php`.

**Comando de teste:** `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit <diretório>`, por diretório; não pipar para `tail` (engole o exit code). Baseline a registrar em `167-BASELINE-TESTES.md` antes do primeiro commit que toque `estrutura_ofertas`: 83 testes em `tests/Feature/PortalCliente/Estrutura`, 22 nos consumidores do Publicador, 7 em `DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest`.

## Padrões compartilhados

### Autoria cliente x equipe (todo serviço de escrita)
**Fonte:** `app/Services/Portal/Estrutura/RegistroEstrutura.php` (linhas 19-36)
**Aplicar a:** `ProdutoCadastroService`, `ListasDaEmpresaService`, `ImportadorProdutos`, `EstruturaOfertaService::sincronizarDaVariacao`
```php
activity('portal')->causedBy($ator->modelo)->withProperties([
    'modulo' => 'estrutura', 'evento' => $evento,
    'origem' => $ator->equipe ? 'interno' : 'cliente',
    'company_id' => $empresa->id, ...$extra,
]);
```
`RegistroEstrutura::registrar($ator, $empresa, $alvo|null, 'evento', 'Descrição em pt-BR', [extra])`. O `causer_id` não distingue cliente de equipe (learnings `portal-do-cliente.md` §12).

### Empresa só do contexto, id de outra empresa = 404
**Fonte:** `PortalEstruturaController.php` linhas 25-39 e 462-485; `EstruturaOfertaService.php` linhas 375-381
**Aplicar a:** todo método do controller novo.

### Erro de validação por `ValidationException::withMessages`
**Fonte:** `EstruturaOfertaService.php` linhas 288-323 e 201-204; `EstruturaPrecificacaoService.php` linhas 145-155
**Aplicar a:** serviços de escrita. Mensagens em pt-BR, no tom do UI-SPEC ("Use só números. Exemplo: 27,8").

### Transação por lote e varredura da espera
**Fonte:** `EstruturaOfertaService.php` linhas 48-58 e 243-284
**Aplicar a:** toda gravação de variação (criar/alterar SKU/excluir chama `varrerEspera` dentro da mesma transação; nunca num GET).

### Normalização de SKU
**Fonte:** `EstruturaOferta::normalizarSku()` (trim + minúsculas; vazio vira null). Usar para casar variação por código (importação) em vez de novo `strtolower`.

### Normalização de nome de lista (família/ambiente)
Sem analog no código (ver "Sem analog"): `Str::ascii` + minúsculas + espaços colapsados NO PHP, porque o collation `utf8mb4_unicode_ci` do MariaDB ignora caixa e acento e o SQLite dos testes é binário (RESEARCH Armadilha 2). Capturar `QueryException` 23000 e traduzir em mensagem de validação.

### Registro de rotas com throttle isolado
**Fonte:** `routes/web.php` linhas 204-265. Prefixo do throttle único por rota.

### Allowlist do domínio do cliente
**Fonte:** `RestringeDominioDoPortal.php` linhas 119-146. Uma linha por rota; confiar em `DominioLiberaTodoModuloTest`.

### Convenções de projeto aplicáveis (CLAUDE.md)
Comentários em pt-BR; sem `enum` (varchar + constante); `timestamps()` nullable; índices/FK com nome curto explícito; `npm run build` ao fim de qualquer alteração de frontend (conferir `public/build/manifest.json`: a página nova precisa ser componente real, re-export puro some do manifest); `git commit -- <caminhos>` e nunca `git add -A`; nenhum deploy sem autorização; conta de cliente ML só leitura (`shipping_options/free` é GET). Migration rodada também no MariaDB 10.4 local com `--path` (`SHOW CREATE TABLE estrutura_ofertas`, `SHOW INDEX`, rollback e reaplicação, contagem de linhas antes/depois).

## Sem analog

| Arquivo | Papel | Fluxo | Motivo |
|---|---|---|---|
| `Produtos/ListasDaEmpresaService.php` (normalização de nome, "em uso" bloqueia exclusão, renomear mantém produtos) | service | CRUD | Não existe lista por empresa com unicidade normalizada; usar RESEARCH Armadilha 2 + o padrão de recusa de `EstruturaOfertaService::excluir` |
| `Produtos/NumeroBr.php` | utility | transform | Não existe parser PHP de número BR compartilhado (medido na pesquisa); escrever com testes ("27,8", "27.8", "1.234,50") |
| Tipo `picker` com popover/Sheet no `SpreadsheetGrid` | component | event-driven | Só há `TextareaPopup` como editor popup (linha 37), servindo de esqueleto; não existe picker com busca remota na grade. A grade Glide (`Pages/Mlb/GradeAnuncioGlide.jsx`) tem `provideEditor` customizado, mas o D-12 e o UI-SPEC descartaram o Glide |

## Metadados

**Escopo da busca de analogs:** `app/Services/Portal/Estrutura`, `app/Http/Controllers/PortalEstruturaController.php`, `app/Models/Estrutura*`, `app/Support/Portal`, `app/Http/Middleware/RestringeDominioDoPortal.php`, `app/Services/Publicador`, `app/Services/Incubadora/Publicador`, `app/Services/MercadoLivreService.php`, `database/migrations` (estrutura e pub_), `resources/js/Pages/Portal`, `resources/js/Components/Portal/Estrutura`, `resources/js/Components/SpreadsheetGrid.jsx`, `tests/Feature/PortalCliente/Estrutura`, `tests/js`, `config/publicador.php`, `routes/web.php`.
**Arquivos lidos nesta sessão:** CONTEXT, RESEARCH, UI-SPEC, `EstruturaOfertaService`, `RegistroEstrutura`, `EstruturaPrecificacaoService`, `ColagemAnunciosService` (1-150), `PrecificacaoEstrutura` (1-40), migrations `add_produto_id_to_pub_rascunhos` e `create_estrutura_tables` (1-70), `ModulosPortal` (1-120), `PortalEstruturaController` (trechos), `PreviaColagem`, `Janela`, `EstruturaLista` (1-60), trechos de `SpreadsheetGrid`, `ImplementacaoPublica`, `EditorRascunhoService`, `MercadoLivreService`, `CategoriaSugestaoService`, `PolosController::streamXlsx`, `RestringeDominioDoPortal`, testes de amostra.
**Data da extração:** 2026-10-05
