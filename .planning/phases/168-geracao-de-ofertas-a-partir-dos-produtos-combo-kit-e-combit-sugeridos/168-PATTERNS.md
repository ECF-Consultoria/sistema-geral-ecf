# Fase 168: Geração de ofertas a partir dos produtos (Combo, Kit e Combit sugeridos) - Mapa de Padrões

**Mapeado em:** 2026-10-06 (worktree `C:/tmp/ecf-publicador-spec-261001`)
**Arquivos analisados:** 38 (novos e modificados)
**Analogs encontrados:** 36 / 38 (2 sem analog direto, ver "Sem analog")

Caminhos relativos à raiz do worktree. Números de linha vêm da leitura desta sessão. Todos os analogs são da Fase 167 (em produção desde 06/10), salvo onde indicado. Nenhum dado real do cliente foi lido (a planilha não foi aberta).

## Classificação dos arquivos

### Backend: classes puras (sem banco, sem `config()`, sem relógio)

| Arquivo novo | Papel | Fluxo | Analog mais próximo | Qualidade |
|---|---|---|---|---|
| `app/Services/Portal/Estrutura/Geracao/GeradorDeSugestoes.php` | utility (regra pura) | transform | `app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php` | exato (forma) |
| `.../Geracao/ChaveDeComposicao.php` | utility | transform | `Produtos/NormalizadorDeLinha.php` / `NumeroBr.php` | role-match |
| `.../Geracao/TipoDoProduto.php` | utility | transform | `Produtos/ListasDaEmpresaService::chave()` (normalização sem caixa/acento, linhas 50-53) | role-match |
| `.../Geracao/Quantidades.php` | utility | transform | `Produtos/NumeroBr.php` + `Produtos/VolumesTexto.php` | role-match |
| `.../Geracao/NomesSugeridos.php` | utility | transform | `Produtos/PendenciasDoProduto.php` (constantes + rótulos puros) | role-match |
| `.../Geracao/VariacoesEmParalelo.php` | utility | transform | `Produtos/LogisticaProduto::pacote` (função pura sobre lista) | role-match |
| `.../Geracao/ConjuntoLogistico.php` | utility | transform | `LogisticaProduto::daVolumes` (chamada, 112-115) | exato |

### Backend: I/O, serviço, models

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `.../Geracao/RetratoDoCatalogo.php` | service (leitura) | CRUD (read) | `Produtos/ProdutoLinhas::paraProdutos` (69-115) | exato |
| `.../Geracao/SugestoesService.php` | service | CRUD transacional + batch | `EstruturaOfertaService::criar/criarCombos` + `Produtos/ListasDaEmpresaService` | exato |
| `app/Models/EstruturaTipoProduto.php`, `EstruturaTipoPar.php`, `EstruturaProdutoGeracao.php`, `EstruturaSugestaoDescartada.php` | model | CRUD | `app/Models/EstruturaProdutoVariacao.php` | exato |
| `config/estrutura_geracao.php` | config | regras globais | `config/estrutura_produtos.php` | exato |

### Backend: HTTP, rotas, acesso

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `app/Http/Controllers/PortalEstruturaSugestoesController.php` | controller | request-response (Inertia + JSON) | `app/Http/Controllers/PortalEstruturaProdutosController.php` | exato |
| `app/Http/Controllers/DevEstruturaGeracaoController.php` | controller | CRUD admin (Inertia + flash) | `app/Http/Controllers/DevController.php` (+ rotas `role:admin`) | role-match |
| `routes/web.php` (modifica: grupo `portal.auth` ~205-240 e grupo `role:admin` ~1145) | route | request-response | rotas `estrutura.produtos.*` | exato |
| `app/Http/Middleware/RestringeDominioDoPortal.php` (modifica `PERMITIDO`, ~119-141) | middleware | allowlist | o próprio arquivo | exato |
| `app/Http/Middleware/HandleInertiaRequests.php` | só se surgir flash key nova | - | n/a (usar `success`, já compartilhada) | evitar |

### Migrations

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `database/migrations/2026_10_07_100000_create_estrutura_geracao_tables.php` | migration | DDL (tabelas novas) | `2026_10_06_100000_create_estrutura_produtos_tables.php` | exato |
| `database/migrations/2026_10_07_100100_semear_estrutura_tipos_e_pares.php` | migration | dados (`insertOrIgnore`) | `2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` (esqueleto idempotente) | parcial |
| `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (modifica `MIGRACOES`, linhas 19-29) | test | varredura estática | o próprio | exato |

### Frontend

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `resources/js/Pages/Portal/EstruturaSugestoes.jsx` | página Inertia | CRUD + filtros no servidor | `resources/js/Pages/Portal/EstruturaProdutos.jsx` | exato |
| `resources/js/Components/Portal/Estrutura/Sugestoes/CartaoSugestao.jsx` | component | event-driven | `Components/Portal/Estrutura/Produtos/CartaoProdutoGrande.jsx` + `PecasDoProduto.jsx` (`PilulaLogistica`) | role-match |
| `.../Sugestoes/FiltrosSugestoes.jsx`, `CabecalhoFamilia.jsx`, `BarraDeMarcadas.jsx`, `ExplicacaoDasOfertas.jsx` | component | event-driven | `Produtos/BarraAcoesProdutos.jsx` | role-match |
| `.../Sugestoes/PainelSemTipo.jsx`, `JanelaTipo.jsx`, `ListaDescartadas.jsx` | component | CRUD | `Produtos/JanelaListas.jsx`, `JanelaSugestoesCategoria.jsx` + `Estrutura/Janela.jsx` | role-match |
| `resources/js/lib/sugestoesEstrutura.js` | utility | só formatação/rótulos | `resources/js/lib/produtosEstrutura.js` + `produtosFretes.js` | exato |
| `resources/js/Pages/Dev/EstruturaGeracao.jsx` | página Inertia admin | CRUD | `resources/js/Pages/Dev/Desenvolvimento.jsx` (`DevCard`) | role-match |
| `resources/js/Components/Dev/DevCard.jsx` (extração) | component | apresentação | `DevCard` local de `Desenvolvimento.jsx` (30-45) | exato |
| `resources/js/Pages/Dev/Desenvolvimento.jsx` (modifica: importa `DevCard`, link do admin) | página | apresentação | o próprio | exato |
| `resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx` (modifica: 6ª ação) | component | apresentação | o próprio (12-39) | exato |
| `resources/js/Pages/Portal/EstruturaLista.jsx` (modifica: link no cabeçalho) | página | apresentação | o próprio (`LINK_SECUNDARIO`) | exato |

### Testes

| Arquivo | Papel | Analog | Qualidade |
|---|---|---|---|
| `tests/Unit/PortalEstrutura/Geracao/*Test.php` (6) | test unit (regra pura) | `tests/Unit/PortalEstrutura/LogisticaProdutoTest.php` | exato |
| `tests/Feature/PortalCliente/Estrutura/Sugestoes/*Test.php` (8) | test feature | `tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php` + `ColagemEEsperaTest.php` | exato |
| `tests/js/estrutura-sugestoes.test.js` | test (gate estrutural) | `tests/js/estrutura-produtos-lista.test.js` + `tests/js/_fonte.js` | exato |

---

## Atribuições de padrão

### `app/Services/Portal/Estrutura/Geracao/GeradorDeSugestoes.php` (+ as 5 classes puras irmãs)

**Analog:** `app/Services/Portal/Estrutura/Produtos/LogisticaProduto.php`

**Forma da classe pura** (linhas 5-24): `final class`, métodos `static`, constantes de vocabulário, nenhum acesso a banco, regras recebidas por parâmetro (`?array $regras = null` com `config()` só como padrão). Docblock diz "ÚNICA implementação desta conta; a tela só exibe" e cita o PORTAL-02.
```php
final class LogisticaProduto
{
    public const PENDENTE = 'pendente';
    public const ME1      = 'me1';
    ...
    /** @param list<array{c: float|int, l: float|int, a: float|int, kg: float|int}> $volumes */
    public static function daVolumes(array $volumes): array
    {
        return self::avaliar(self::pacote($volumes));
    }
}
```
**Diferença desta fase:** o `LogisticaProduto::avaliar` aceita `$regras` mas cai em `config()`. O gerador NÃO pode chamar `config()`: o teto de sugestões, quantidades e pares entram no `$retrato` (RESEARCH PR168-01). O serviço de I/O lê o config e passa.

**`ConjuntoLogistico`** é só o laço de repetição + `LogisticaProduto::daVolumes($volumes)` (RESEARCH §Logística do conjunto, linhas 399-412). Volume tem a forma `['c','l','a','kg']`; no banco os campos se chamam `comprimento/largura/altura/peso` e a conversão está em `ProdutoLinhas` (99):
```php
->map(fn ($v) => ['c' => (float) $v->comprimento, 'l' => (float) $v->largura, 'a' => (float) $v->altura, 'kg' => (float) $v->peso])
```

**Normalização de texto** (`TipoDoProduto`, família, ambiente, palavras-chave): copiar a conta de `ListasDaEmpresaService::chave()` (50-53), um helper só para as quatro coisas:
```php
public static function chave(string $nome): string
{
    return mb_strtolower(Str::ascii(self::limpar($nome)));
}
public static function limpar(string $nome): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $nome));
}
```
Chamar `ListasDaEmpresaService::chave` direto (é `public static`), sem nova cópia.

**Vocabulário de fase:** usar as constantes do model, não literais: `EstruturaOferta::FASE_COMBO|FASE_KIT|FASE_COMBIT` (`app/Models/EstruturaOferta.php` 30-33).

**Teste (unit puro)** copia `tests/Unit/PortalEstrutura/LogisticaProdutoTest.php` (1-41): `extends Tests\TestCase`, helper privado `vol()`, `#[DataProvider('gabarito')]` com array de casos nomeados e `assertSame` / `assertEqualsWithDelta`. Fixture do gerador é SINTÉTICA, em arrays no próprio teste.

---

### `app/Services/Portal/Estrutura/Geracao/RetratoDoCatalogo.php` (service de leitura)

**Analog:** `app/Services/Portal/Estrutura/Produtos/ProdutoLinhas.php::paraProdutos` (69-115)

**Padrão de carga sem N+1, tudo escopado por `company_id`** (75-90):
```php
$produtos = EstruturaProduto::query()
    ->where('company_id', $empresa->id)
    ->whereIn('id', $produtoIds)
    ->with(['familia', 'ambientes', 'variacoes.volumes'])
    ->orderBy('id')
    ->get();

$variacaoIds = $produtos->flatMap(fn ($p) => $p->variacoes->pluck('id'))->all();

$ofertas = $variacaoIds === [] ? collect() : EstruturaOferta::query()
    ->where('company_id', $empresa->id)
    ->whereIn('variacao_id', $variacaoIds)
    ->get()
    ->keyBy('variacao_id');
```
**Diferença:** o retrato carrega a empresa inteira (sem `forPage`), e acrescenta: composições existentes (join `estrutura_oferta_componentes` x `estrutura_ofertas` por `company_id`, só as de componentes todos com `variacao_id`), ajustes 1:1, tipos/pares globais, descartadas. Passo 2 do analog (UMA estimativa de frete só para a página) é o que `SugestoesService::listar` reproduz em `FreteMe2Service::estimar` (115):
```php
$fretes = $itens === [] ? [] : $this->frete->estimar($empresa, $itens);
```
com `$itens[$chave] = ['pacote' => $log['pacote'], 'peso_faturado' => $log['peso_faturado'], 'logistica' => $log['logistica'], 'custo' => ...]` (106-111). Custo do conjunto = soma quantidade x custo da variação, ou `null` se algum for nulo (RESEARCH, linha 426).

**Paginação no servidor** (copiar de `ProdutoLinhas::pagina`, 48-56): `$total`, `$paginas = max(1, ceil(...))`, `$pagina = min(max(1, $pagina), $paginas)`; saída `['paginacao' => ['pagina','paginas','total']]`. Aqui a página é sobre o ARRAY gerado (`array_slice`), não sobre query, e `POR_PAGINA = 20` vem do config. Busca por termo: `Str::lower(trim($busca))` e `addcslashes($termo, '%_\\')` (38-40).

---

### `app/Services/Portal/Estrutura/Geracao/SugestoesService.php` (aceitar, descartar, restaurar, definirGeracao)

**Analog:** `app/Services/Portal/Estrutura/EstruturaOfertaService.php` (`criar` 48-65, `criarCombos` 81-131, `composicao` 417-474) e `Produtos/ListasDaEmpresaService.php` (corrida 23000).

**Aceitar cria pelo caminho da Lista SKUs, com `varrerEspera: false` e varredura única no fim** (assinatura, linha 48):
```php
public function criar(Company $empresa, array $dados, AtorDoPortal $ator, bool $varrerEspera = true): array
// $dados: sku, fase, nome, logistica?, observacoes?, componentes: [['id' => oferta_id, 'quantidade' => n], ...]
// retorna [EstruturaOferta, int $absorvidos]
```
A regra de composição vem de graça e é a ÚNICA validação (437-447): combo = 1 componente com quantidade >= 2; kit = >= 2 componentes, todos quantidade 1; combit = >= 2 componentes e algum >= 2; componentes da MESMA empresa e SIMPLES. O gerador deve emitir exatamente isso, senão `ValidationException` no aceite (capturar por item, como o esboço do RESEARCH §Aceitar, 446-477).

**Padrão de lote que pula o que já existe por COMPOSIÇÃO e não por SKU** (`criarCombos` 94-102, 110-114):
```php
$existentes = EstruturaOferta::query()
    ->where('company_id', $base->company_id)
    ->where('fase', EstruturaOferta::FASE_COMBO)
    ->whereHas('componentes', fn ($q) => $q->where('componente_id', $base->id))
    ->with('componentes')->get()
    ->map(fn ($o) => $o->componentes->first()?->quantidade)->filter()->all();
...
if (in_array($n, $existentes, true)) { $r['pulados'][] = $n; continue; }
```
e a convenção do nome/SKU do combo (116-123): `"{$base->sku}-CB{$n}"`, `"Combo {$n} {$nome}"`. A 168 muda só o NOME (título "Kit {n} {Plural} ..." quando o produto tem tipo) e mantém o SKU `-CB{n}`.

**Uma transação por sugestão, erro de uma não derruba as outras** (o mesmo desenho da colagem, `ColagemAnunciosService::aplicar` + `gravarLinhas` da 167): `DB::transaction` externa com `lockForUpdate` na empresa; dentro, `try { ... } catch (ValidationException $e) { $resultado['erros'][] = [...] }`.

**Corrida e unique** (descartar): capturar `QueryException` e tratar `'23000'` como "já estava", como em `ListasDaEmpresaService::criar` (112-122):
```php
} catch (QueryException $e) {
    if ((string) $e->getCode() === '23000') { ...existente... }
    throw $e;
}
```
No descarte, 23000 em `esd_company_chave_uq` = idempotente (usar `insertOrIgnore`/`firstOrCreate`).

**Trilha (toda escrita):** `RegistroEstrutura::registrar($ator, $empresa, $alvo|null, 'evento', 'Descrição pt-BR', [extra])` (`RegistroEstrutura.php` 19-36). Eventos novos: `sugestoes_aceitas`, `sugestoes_descartadas`, `sugestoes_restauradas`, `tipo_do_produto_definido`. A 167 registra UM evento por lote (`produtos_gravados`) com totais, não um por item; o aceite ainda gera o `oferta_criada` de cada oferta via `criar()`.

**Imports** (copiar do `EstruturaOfertaService`, 5-11): `App\Models\Company`, `App\Support\Portal\AtorDoPortal`, `Illuminate\Support\Facades\DB`, `Illuminate\Validation\ValidationException`; DI por construtor promovido.

**Validação por `ValidationException::withMessages`** com mensagens pt-BR (ex.: 425, 428, 470). Quantidades inválidas: "Use números inteiros de 2 a 999, separados por vírgula." (UI-SPEC).

**Empresa de fora = 404 uniforme:** produto/tipo vêm de `->where('company_id', $empresa->id)->findOrFail($id)` (controller, ver abaixo); `tipo_id` só da lista global.

---

### `app/Models/EstruturaTipoProduto.php`, `EstruturaTipoPar.php`, `EstruturaProdutoGeracao.php`, `EstruturaSugestaoDescartada.php`

**Analog:** `app/Models/EstruturaProdutoVariacao.php` (1-60)

```php
class EstruturaProdutoVariacao extends Model
{
    public const EIXOS = [ 'cor' => 'Cor', ... ];     // vocabulário como constante, sem enum
    protected $table = 'estrutura_produto_variacoes'; // tabela explícita (nome não segue o plural do Laravel)
    protected $fillable = ['produto_id', 'company_id', 'ordem', 'codigo', 'eixo', 'valor', 'custo'];
    protected $casts = ['custo' => 'float', 'ordem' => 'integer'];
    public function produto(): BelongsTo { return $this->belongsTo(EstruturaProduto::class, 'produto_id'); }
}
```
Aplicar: `$table` explícito em todas; `$fillable` explícito, nunca `company_id` do request; constante `COMBIT_REPETE = ['a' => ..., 'b' => ..., 'ambos' => ...]` no `EstruturaTipoPar` (sem `enum`); `EstruturaProdutoGeracao` tem `$primaryKey = 'produto_id'`, `$incrementing = false`. Comentário de classe em pt-BR citando a fase e a decisão.

---

### `config/estrutura_geracao.php`

**Analog:** `config/estrutura_produtos.php` (mesmo estilo de `config/publicador.php`: docblock "toda hipótese vira opção aqui", cada chave comentada com a origem). Chaves: `por_pagina` (20), `lote_aceite` (100), `teto_sugestoes` (5000), `max_titulo` (60), `max_sku` (120), catálogo-semente de tipos/palavras/quantidades e de pares. A **lista concreta de pares sai do roteiro local e só entra depois do ok do usuário** (CONTEXT D-20; RESEARCH Questão 3). Seguros desde já: mesa + cadeira, mesa + banco, cama + criado-mudo.

---

### `database/migrations/2026_10_07_100000_create_estrutura_geracao_tables.php`

**Analog:** `database/migrations/2026_10_06_100000_create_estrutura_produtos_tables.php` (copiar o esqueleto inteiro)

**Cabeçalho:** docblock "DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2)" + armadilhas de MariaDB evitadas + "IDEMPOTENTE" (8-34). Escrever o desenho das 4 tabelas ali, na mesma forma.

**Constantes `INDICES` e `FKS` no topo + `hasTable` por CREATE + reposição por nome no fim** (37-73, 77-86, 160-183):
```php
private const INDICES = [
    'estrutura_familias' => ['efam_company_nome_uq' => ['unique', ['company_id', 'nome']]],
    ...
];
private const FKS = [
    'estrutura_produtos' => [
        'epr_company_fk' => ['company_id', 'companies', 'cascade'],
        'epr_familia_fk' => ['familia_id', 'estrutura_familias', 'null'],
    ],
];
if (! Schema::hasTable('estrutura_familias')) {
    Schema::create('estrutura_familias', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained('companies', 'id', 'efam_company_fk')->cascadeOnDelete();
        $table->string('nome', 80);
        $table->timestamps();
        $table->unique(['company_id', 'nome'], 'efam_company_nome_uq');
    });
}
```
**Nulável + `nullOnDelete`** (`epr_familia_fk`, 105 e 113): `unsignedBigInteger()->nullable()` declarado antes do `->foreign(...)->nullOnDelete()`. Serve para `estrutura_produto_geracao.tipo_id` / `epg_tipo_fk` (evita 1830).

**PK composta/chave própria sem `id`** (`estrutura_produto_ambiente`, 117-127): `$table->primary([...], 'epa_pk')`. `estrutura_produto_geracao` usa `produto_id` como PK com nome `epg_pk`.

**Helpers copiados integralmente** (196-230): `emMysql()` (mysql OU mariadb), `hasIndex()` (information_schema / PRAGMA), `hasForeignKey()`. `down()` na ordem inversa com `Schema::dropIfExists` (186-194). **Proibido `try/catch` em volta de DDL.**

**Nomes de índice/FK** do RESEARCH (`etp_slug_uq`, `etpar_uq`, `etpar_a_fk`, `etpar_b_fk`, `etpar_b_idx`, `epg_pk`, `epg_produto_fk`, `epg_company_fk`, `epg_tipo_fk`, `epg_tipo_idx`, `esd_company_fk`, `esd_company_chave_uq`): todos < 64. Sem `enum`, sem `json`, `timestamps()` nullable. **Nenhum ALTER** em tabela da 167 nem em `estrutura_ofertas`.

---

### `database/migrations/2026_10_07_100100_semear_estrutura_tipos_e_pares.php`

**Analog:** `database/migrations/2026_10_06_100100_add_variacao_id_to_estrutura_ofertas.php` (esqueleto) + `2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` (helpers).

Só dados: `DB::table('estrutura_tipos_produto')->insertOrIgnore([...])` por `slug` lendo `config('estrutura_geracao')`, depois os pares resolvendo `tipo_a_id <= tipo_b_id`. `down()` VAZIO (a ECF pode ter editado). Reexecutar 2x não muda a contagem (teste). Migration separada da de criação, para a de criação não ficar `Pending` com tabela sem índice.

**Teste da varredura:** acrescentar as duas ao array `MIGRACOES` de `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (19-29), após o bloco "Fase 167":
```php
        // Fase 168
        '2026_10_07_100000_create_estrutura_geracao_tables.php',
        '2026_10_07_100100_semear_estrutura_tipos_e_pares.php',
```
O teste proíbe `getDriverName() === 'mysql'` (82-83).

---

### `app/Http/Controllers/PortalEstruturaSugestoesController.php`

**Analog:** `app/Http/Controllers/PortalEstruturaProdutosController.php` (NÃO engordar os controllers existentes)

**Docblock de contrato** (26-42): este controller só orquestra; empresa SEMPRE do `PortalContexto`; id de outra empresa = 404 igual ao inexistente; quem escreve = cliente e equipe, `AtorDoPortal` segue para o serviço e vira `origem`.

**Imports e DI** (5-24, 48-57): `PortalClienteService`, `ModulosPortal`, `PortalContexto`, `Inertia`, `Illuminate\Http\Request`; serviços por construtor promovido.

**Render de página** (62-77), chave ativa **`ModulosPortal::ESTRUTURA.'.produtos'`** (D-20, sem submódulo novo):
```php
return Inertia::render('Portal/EstruturaSugestoes', [
    ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', PortalContexto::ator()),
    'sugestoes'    => $this->sugestoes->listar($empresa, $filtros, (int) $request->query('pagina', 1)),
    'filtros'      => $filtros,
    'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
    'frete_tabela' => $this->freteTabela(),
    'vocabulario'  => $this->vocabulario(),
]);
```
**Recurso por empresa, 404 uniforme** (89-95):
```php
$p = EstruturaProduto::query()
    ->where('company_id', PortalContexto::empresa()->id)
    ->findOrFail($produto);
```
**Escrita por JSON com validação + ator** (105-120): `$request->validate([... 'max:'.CONST ...])`, serviço com `PortalContexto::empresa()` e `PortalContexto::ator()`, `response()->json(...)`. Para o aceite: `'sugestoes' => 'required|array|min:1|max:100'`, `'sugestoes.*.chave' => ['required','string','regex:/^v\d+\*\d+(\+v\d+\*\d+){0,2}$/']`, `nome` `max:255`, `sku` `max:120`. O navegador manda SÓ `chave+nome+sku`.

**`ConvertEmptyStringsToNull`** (122-141): o Laravel transforma `''` em `null`. Para `qtd_combo`/`qtd_combit` ("nenhuma" = `'0'`, nunca `''`), seguir o desenho do RESEARCH Armadilha 5; se precisar do `''` cru, ler o JSON como `linhasComoVieram` faz (131-141).

**Resultado de sucesso** por flash `success` (228, `back()->with('success', ...)`), chave já compartilhada; **não criar flash key nova** (exigiria linha em `HandleInertiaRequests`). Mensagens do aceite seguem o contrato de copy da UI-SPEC.

**Cotação real de frete da página** (330-360, `cotarFretes`): reutilizar a rota/serviço existente da 167 (`FreteMe2Service::cotar`) com `variacao_ids` quando o item é uma variação; para conjunto, enviar `[chave => ['pacote','peso_faturado','logistica','custo']]` direto, mesmo contrato (FreteMe2Service docblock 34-38). Só GET na conta do cliente; teto `max_por_requisicao`.

**Helpers `vocabulario()` / `freteTabela()`** (380-397): copiar a forma.

---

### `app/Http/Controllers/DevEstruturaGeracaoController.php` e rotas admin

**Analog:** `app/Http/Controllers/DevController.php` (render Inertia em `Dev/...`) e `routes/web.php` ~1145-1151.

```php
Route::middleware('role:admin')->group(function () {
    ...
    Route::get('/dev/desenvolvimento', [DevController::class, 'index'])->name('dev.desenvolvimento');
```
Acrescentar ali, dentro do mesmo `role:admin`: `GET /dev/estrutura-geracao` + `POST/PUT/DELETE` de tipos e pares. Controller no namespace `App\Http\Controllers` (não existe `Controllers/Dev` além dos três arquivos `Dev*.php` soltos; o analog é `DevController`). Escrita de admin devolve `back()->with('success', 'Tipo salvo.')` e validação por `$request->validate`. Rotas NÃO entram na allowlist do domínio do cliente. Teste: não-admin = 403.

---

### `routes/web.php` (grupo `portal.auth`)

**Analog:** linhas 205-217 do próprio arquivo (rotas de Produtos). Regra: `whereNumber` nos ids e `throttle:N,1,<prefixo-próprio>` em TODA rota (o 3º parâmetro isola o contador).
```php
Route::get('/estrutura/produtos/novo', [PortalEstruturaProdutosController::class, 'novo'])
    ->middleware('throttle:120,1,estrutura.produtos.novo')->name('portal.auth.estrutura.produtos.novo');
Route::delete('/estrutura/produtos/variacoes/{variacao}', [PortalEstruturaProdutosController::class, 'excluirVariacao'])
    ->whereNumber('variacao')->middleware('throttle:60,1,estrutura.produtos.variacoes.excluir')->name('portal.auth.estrutura.produtos.variacoes.excluir');
```
Rotas novas (RESEARCH §Acesso): `GET /estrutura/sugestoes` (`60,1,estrutura.sugestoes`), `POST .../aceitar` (`30,1,...`), `POST .../descartar`, `POST .../restaurar`, `PUT /estrutura/sugestoes/produtos/{produto}/geracao`, `POST .../frete` opcional. Nomes: `portal.auth.estrutura.sugestoes[.aceitar|.descartar|.restaurar|.geracao|.frete]`.

---

### `app/Http/Middleware/RestringeDominioDoPortal.php` (MODIFICA `PERMITIDO`)

**Analog:** o próprio, linhas 119-141. Uma linha por rota, com comentário datado. O `*` do `Str::is` atravessa `/`: NUNCA `portal/estrutura/sugestoes/*`.
```php
'portal/estrutura/lista',
// Fase 167 (05/10/2026) — Produtos. Uma linha por rota; o `*` só ocupa o
// id numérico (`whereNumber`). NUNCA `portal/estrutura/produtos/*`: ...
'portal/estrutura/produtos',
'portal/estrutura/produtos/variacoes/*',
```
Acrescentar: `portal/estrutura/sugestoes`, `.../aceitar`, `.../descartar`, `.../restaurar`, `.../produtos/*/geracao`, `.../frete`. A rota com id no meio (`produtos/{produto}/geracao`) casa por `*` em `PERMITIDO`; o padrão `{id}` só vale para o `PERMITIDO_COM_ID` (179-181, usado por `portal/estrutura/produtos/{id}`). Rede de segurança: `DominioLiberaTodoModuloTest` falha se uma rota `portal.auth.*` não estiver liberada.

---

### `resources/js/Pages/Portal/EstruturaSugestoes.jsx`

**Analog:** `resources/js/Pages/Portal/EstruturaProdutos.jsx`

**Imports** (1-16):
```jsx
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Loader2, Plus, X } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, CabecalhoEstrutura, Paginacao } from '@/Components/Portal/Estrutura/comum';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import { avisoDosFretes, consultarFretesEmBlocos } from '@/lib/produtosFretes';
```
(`Janela` vem de `@/Components/Portal/Estrutura/Janela`; `guardaDoVoltar` de `@/lib/guardaDoVoltar`.)

**Cabeçalho comentado em pt-BR** explicando a decisão da tela (18-35); "D-23: nada de planilha dentro do sistema" vale aqui: cartões e botões, NÃO `SpreadsheetGrid`.

**Filtros e página NO SERVIDOR com debounce, sem filtrar no navegador** (88-100):
```jsx
const visitar = (params) => {
    router.get(route('portal.auth.estrutura.produtos'), params, {
        preserveState: true, preserveScroll: false, replace: true, only: ['produtos', 'filtros'],
    });
};
const buscaInicial = useRef(true);
useEffect(() => {
    if (buscaInicial.current) { buscaInicial.current = false; return; }
    const t = setTimeout(() => visitar({ q: busca || undefined }), 350);
    return () => clearTimeout(t);
}, [busca]);
```
Aqui `only: ['sugestoes', 'filtros']` e `preserveScroll: true` (UI-SPEC: preservar aba/filtros/página/rolagem).

**Estado derivado do servidor com `useRef` de primeira passada** (74-86): `useEffect` que ignora o 1º render e ressincroniza quando a prop muda. Copiar para sugestões e para o painel.

**Link secundário** (46): `LINK_SECUNDARIO = 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 py-2 text-[13px] font-medium text-white/80 ...'`, o mesmo usado na entrada da Lista SKUs.

**Frete em blocos** (`lib/produtosFretes.js`, 1-40): `consultarFretesEmBlocos(ids, { enviar, aoReceber })` com `BLOCO_FRETES=200`, teto de consultas por clique; sem React/axios, injeta `enviar`. Reutilizar a mecânica (ou um irmão `consultarFretesDaPagina`), nenhuma regra de frete no JS.

**Guarda do voltar** (`resources/js/lib/guardaDoVoltar.js`, 17-23): `const desliga = definirGuardaDoVoltar((e) => { ...; e.stopImmediatePropagation(); })` num `useEffect` que devolve `desliga`, ligada só quando há nome/SKU editado e não aceito. Ouvinte único já registrado em `app.jsx`; a tela só liga e desliga. Mensagem do contrato de copy.

---

### Componentes de `resources/js/Components/Portal/Estrutura/Sugestoes/*`

**Analogs:** `Produtos/BarraAcoesProdutos.jsx`, `Produtos/PecasDoProduto.jsx`, `Produtos/JanelaListas.jsx`, `Estrutura/Janela.jsx`

**Estilos de botão/campo** (`BarraAcoesProdutos.jsx` 12-13): constantes `ACAO` e `SECUNDARIA` no topo do arquivo; `cn()` para condicionais. A UI-SPEC pede botão "Aceitar" do cartão SECUNDÁRIO e amarelo só na `BarraDeMarcadas`:
```jsx
const SECUNDARIA = 'border border-white/[0.10] bg-white/[0.03] font-medium text-white/85 hover:bg-white/[0.07] hover:text-white';
<button type="button" ... className={cn(ACAO, temProdutos ? 'bg-ecf-yellow font-semibold text-black hover:bg-ecf-yellow/90' : SECUNDARIA)}>
```
**Busca com X e `aria-label`** (41-51): `Search` à esquerda, botão X de limpar. Copiar para `FiltrosSugestoes` (UI-SPEC: `rounded-[10px]`, debounce 350 ms, `lg:w-[336px] lg:ml-auto`).

**Entrada em Produtos** (`BarraAcoesProdutos.jsx` 15, 34-38): 6ª ação `Sparkles` + "Sugestões de ofertas" com `SECUNDARIA`; nova prop (`onSugestoes`/ou `<a href={route('portal.auth.estrutura.sugestoes')}>` como o "Baixar modelo", 30-33, que já é link). Só aparece com produtos.

**Pílula de logística** (`PecasDoProduto.jsx` 54-65): reusar `PilulaLogistica({ chave, rotulos, ponto })` e `ESTILO_LOGISTICA` de `@/lib/produtosEstrutura`; NÃO criar outra.

**Janela** (`Estrutura/Janela.jsx`): `Janela({ aberta, onFechar, titulo, descricao, largura = 'max-w-lg', children })`; `JanelaTipo` usa `largura="max-w-md"`.

**Pílula com texto, nunca só cor; pílula de fase neutra** (UI-SPEC). `lib/sugestoesEstrutura.js` só formata e rotula, a regra mora no PHP (anti-padrão do RESEARCH). Seguir `tests/js/estrutura-produtos-lista.test.js` como gate: `lerSemComentarios(...)` + `assert.match` das assinaturas + proibir `SpreadsheetGrid` e regra duplicada.

---

### `resources/js/Pages/Dev/EstruturaGeracao.jsx`, `Components/Dev/DevCard.jsx` e `Pages/Dev/Desenvolvimento.jsx`

**Analog:** `resources/js/Pages/Dev/Desenvolvimento.jsx` (1-6, 30-45, 249-263)

**Imports** (1-6): `AppLayout`, `cn`, `router` de `@inertiajs/react`, ícones `lucide-react`, `useState`.

**`DevCard` a extrair sem mudar o markup** (30-45):
```jsx
function DevCard({ icon: Icon, title, subtitle, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
            <div className="flex items-start gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <Icon size={18} className="text-ecf-yellow" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-white font-semibold text-[15px] leading-tight">{title}</h3>
                    {subtitle && <p className="text-white/40 text-[12px] mt-0.5">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}
```
Mover para `resources/js/Components/Dev/DevCard.jsx` (`export default`) e trocar o `function DevCard` local por `import DevCard from '@/Components/Dev/DevCard'`. Uso (249-263): `<DevCard icon={Puzzle} title="..." subtitle="...">...</DevCard>`. Acrescentar o link "Tipos e pares das sugestões de ofertas" num `DevCard` de ferramentas.

**Atenção:** `npm run build` e conferir `public/build/manifest.json`: página só re-exportada some do manifest (CLAUDE.md, learnings da 167). `EstruturaGeracao.jsx` e `EstruturaSugestoes.jsx` devem ser componentes reais.

---

### Testes de feature (`tests/Feature/PortalCliente/Estrutura/Sugestoes/*`)

**Analog:** `tests/Feature/PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest.php` (1-90)

**Esqueleto** (3-30, 82-90):
```php
class AcessoAosProdutosTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;
    ...
    public function test_o_cliente_abre_a_pagina_com_as_props_do_contrato(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaProdutos')...
```
**Equipe pelo portal** (56-62): `app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1')` e `get(route('portal.equipe.entrar', ['t' => $ticket]))`; helpers `admin()` e `analistaNaCarteira()` (28-54) para o caso de equipe e para o `403` do admin da ECF. Sem sessão: `->assertRedirect()` (79). Fixtures: traits `GabaritoDaPlanilhaEstrutural` (`empresaDoGabarito`, `atorCliente`) e `EntraNoPortal` (`entrarNoPortal`, sessão do guard `portal`, não `actingAs`). Casos obrigatórios: id de outra empresa = 404; `origem` cliente/interno em `Activity`; aceite duplo cria 1; oferta criada à mão entre gerar e aceitar; `Http::assertNothingSent()` na lista; nada em `estrutura_precificacoes`; oferta aceita na Lista SKUs e na Precificação. Comando: `C:/xampp/php/php.exe -d memory_limit=1024M vendor/phpunit/phpunit/phpunit <diretório>` por diretório, sem `| tail`.

---

## Padrões compartilhados

### Autoria cliente x equipe (toda escrita)
**Fonte:** `app/Services/Portal/Estrutura/RegistroEstrutura.php` (19-36)
**Aplicar a:** `SugestoesService` (aceitar, descartar, restaurar, definir tipo/quantidades) e a escrita admin (esta última sem `AtorDoPortal`: usar log padrão do sistema).
```php
RegistroEstrutura::registrar($ator, $empresa, $alvo|null, 'evento', 'Descrição em pt-BR', [extra]);
// grava activity('portal') com 'origem' => $ator->equipe ? 'interno' : 'cliente'
```

### Empresa só do contexto, id de outra empresa = 404
**Fonte:** `PortalEstruturaProdutosController.php` (33-37, 89-95) e `EstruturaOfertaService.php` (461-471)
**Aplicar a:** todo método do `PortalEstruturaSugestoesController` e todo componente que o aceite reconstrói (o navegador manda só `chave+nome+sku`, o servidor regera).

### Erro de validação por `ValidationException::withMessages`
**Fonte:** `EstruturaOfertaService.php` (425, 428, 449-451, 470)
**Aplicar a:** `Quantidades`, `SugestoesService`, controller admin.

### Transação por item, varredura da espera uma vez no fim
**Fonte:** `EstruturaOfertaService.php` (48-65, 329) e o parâmetro `$varrerEspera = false` (40-45, "só para quem cria MUITAS ofertas num lote")
**Aplicar a:** `SugestoesService::aceitar`.

### Conta pura única; tela só exibe
**Fonte:** `LogisticaProduto.php` (8-9), `ProdutoLinhas.php` (11-18)
**Aplicar a:** logística/frete do conjunto, chave, nomes. Nunca duplicar no JS.

### Migration idempotente
**Fonte:** `2026_10_06_100000_create_estrutura_produtos_tables.php` (37-73, 160-230)
**Aplicar a:** as duas migrations da 168.

### Rotas com throttle isolado + allowlist por linha
**Fonte:** `routes/web.php` 205-217; `RestringeDominioDoPortal.php` 119-141, 179-181.

### Convenções de projeto (CLAUDE.md)
Comentários em pt-BR; sem `enum`/`json`; `timestamps()` nullable; índices/FK com nome curto; `npm run build` ao fim de qualquer alteração de frontend (conferir manifest); `git commit -- <caminhos>`, nunca `git add -A`; conferir `git show <sha>` antes do push; sem deploy sem autorização; conta de cliente ML só leitura; fixture SINTÉTICA (planilha real fora do repo, nada de nome/SKU/custo em teste, log ou stdout); `--path` em toda migration no MariaDB local compartilhado.

---

## Sem analog

| Arquivo | Papel | Fluxo | Motivo |
|---|---|---|---|
| `Geracao/VariacoesEmParalelo.php` (casar por `eixo+valor`, senão `ordem`, 1xN, nunca cartesiano) | utility | transform | Não existe casamento de variações entre dois produtos. Escrever pelo RESEARCH Pergunta 2/PR168-03, com testes de unit (1x1, 2x2 paralelo, 1xN, sobra ignorada). A forma da classe segue `LogisticaProduto` |
| Barra fixa de marcadas com seleção que sobrevive a página/filtro (teto 100) | component | event-driven | Nenhum componente do módulo mantém seleção entre páginas. Estado em `useState` guardado por `chave` na página; layout vem do UI-SPEC. O gate JS do estilo `estrutura-produtos-lista.test.js` cobre a assinatura |

## Metadados

**Escopo da busca de analogs:** `app/Services/Portal/Estrutura` (+ `Produtos/`), `app/Http/Controllers/PortalEstruturaProdutosController.php`, `DevController.php`, `app/Models/Estrutura*`, `app/Http/Middleware/RestringeDominioDoPortal.php`, `routes/web.php`, `database/migrations/2026_10_0*`, `resources/js/Pages/Portal/EstruturaProdutos.jsx`, `resources/js/Pages/Dev/Desenvolvimento.jsx`, `resources/js/Components/Portal/Estrutura/Produtos`, `resources/js/lib`, `tests/Unit/PortalEstrutura`, `tests/Feature/PortalCliente/Estrutura/Produtos`, `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php`, `tests/js`.
**Arquivos lidos nesta sessão:** CONTEXT, RESEARCH, UI-SPEC, 167-PATTERNS, `LogisticaProduto`, `create_estrutura_produtos_tables`, `PortalEstruturaProdutosController`, `FreteMe2Service` (1-140), `EstruturaOfertaService` (40-145, 415-482), `ProdutoLinhas` (1-120), `RegistroEstrutura`, `ListasDaEmpresaService` (trechos), `EstruturaProdutoVariacao`, `BarraAcoesProdutos`, `PecasDoProduto` (1-80), `EstruturaProdutos.jsx` (1-120), `Desenvolvimento.jsx` (trechos), `guardaDoVoltar.js`, `produtosFretes.js` (1-40), `RestringeDominioDoPortal` (trechos), `LogisticaProdutoTest`, `AcessoAosProdutosTest`, `estrutura-produtos-lista.test.js`, `MigracoesDaFaseDetectamMariaDbTest` (trecho).
**Data da extração:** 2026-10-06
