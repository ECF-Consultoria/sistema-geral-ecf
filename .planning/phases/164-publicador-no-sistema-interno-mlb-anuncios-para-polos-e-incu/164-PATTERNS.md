# Fase 164: Publicador no sistema interno (/mlb/anuncios) — Mapa de Padrões

**Mapeado em:** 2026-10-02
**Arquivos analisados:** 47 (novos e alterados)
**Analogs encontrados:** 44 / 47 (3 sem analog direto: ver "Sem analog")
**Worktree:** `C:/tmp/ecf-publicador-spec-261001` (branch `feat/publicador-ml-261001`)

> Linhas citadas foram lidas neste worktree. Onde o RESEARCH já trazia o arquivo:linha de acoplamento (§1), este documento não repete a tabela inteira: remete a ela e extrai só o excerto a copiar.

## Classificação dos arquivos

### Backend: migrations e models

| Arquivo novo/alterado | Papel | Fluxo | Analog mais próximo | Qualidade |
|---|---|---|---|---|
| `database/migrations/2026_10_02_100000_create_pub_produtos_table.php` | migration | create | `2026_10_01_200000_create_publicador_tables.php` (L36-59) | exato |
| `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` | migration (alter + backfill) | batch | `2026_09_21_120000_alter_ml_tokens_add_mlb_empresa_anchor.php` + `2026_09_30_100000_amplia_unique_user_setores_por_cargo.php` | exato (dois moldes) |
| `app/Models/PubProduto.php` | model | CRUD | `app/Models/PubRascunho.php` (L18-46) + `MlbEmpresa::mlToken` (L125) | exato |
| `app/Models/PubRascunho.php` (alterar) | model | CRUD | ele mesmo (L43-46 `oferta()`) | exato |
| `database/factories/PubProdutoFactory.php` | factory | — | `database/factories/UserFactory.php` / `Company::factory()` | role-match |

### Backend: serviços (generalização do motor)

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `app/Services/Publicador/ClienteMlPublicador.php` (alterar: L46, 57, 72, 112) | service | request-response | ele mesmo | exato |
| `app/Services/Publicador/ContaMlService.php` (alterar L23) | service | request-response | ele mesmo | exato |
| `app/Services/Publicador/DadosEfetivosService.php` (+`daProduto`) | service | transform | ele mesmo (L27-48) | exato |
| `ConferenciaService`, `EditorRascunhoService`, `ImagemAssetService`, `PublicacaoService`, `RascunhoRepository` (alterar) | service | CRUD / event-driven | RESEARCH §1 (tabela completa arquivo:linha) | exato |
| `app/Services/Publicador/MigracaoAnunciarAntigo.php` + `PublicadorMigrarAnunciar` (alterar ou aposentar, Q9) | service/command | batch | ele mesmo | exato |
| `app/Services/Publicador/PublicadorSincronizaPortalService.php` (novo) | service | CRUD idempotente | `DadosEfetivosService` (forma) + exemplo `firstOrCreate` do RESEARCH | parcial |
| `app/Services/Publicador/ProgramasPublicadorService.php` (novo) ou `MlbEmpresa::scopePrograma` | service/scope | CRUD (leitura agregada) | `MlbAnuncioController::empresasDePolos()` (L2404+) + `MlbEmpresa::scopeAtivas` (L74) | role-match |
| `app/Services/Publicador/CategoriaBuscaService.php` (novo, extraído) | service | request-response | `EstruturaPublicacaoService::categorias()` (L223-262) | exato (mover código) |
| `app/Services/Publicador/IaParaRascunhoService.php` (novo) | service | transform | `RascunhoAnuncioIaService::criarRascunho()` (só o papel; formato difere) | role-match |
| trava de contas liberadas (D21) em `PublicacaoService::iniciar` + `config/publicador.php` | config/guard | request-response | `PublicacaoService.php` L66-70 + `config/publicador.php` L57 | exato |

### Backend: HTTP

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `app/Http/Controllers/MlbPublicadorController.php` (novo) | controller | request-response (JSON) | `PortalPublicadorController.php` (292 linhas) | exato |
| `app/Http/Controllers/MlbPublicadorEntradaController.php` ou métodos de entrada/produtos (novo) | controller | request-response (Inertia) | `MlbAnuncioController::index` (L71) + `empresasDePolos` (L2404) | role-match |
| `routes/mlb_anuncios.php` (alterar) | route | request-response | o próprio arquivo, grupo L23-26 | exato |
| `app/Http/Controllers/MlbAnuncioController.php` (alterar `iaAnaliseStore`) | controller | event-driven | ele mesmo L2219-2231 | exato |
| `app/Jobs/GerarAnaliseAnuncioIaJob.php` (etapa 5, ramo "publicador") | job | event-driven | ele mesmo | exato |
| `app/Http/Controllers/PortalEstruturaController.php`, `routes/web.php`, `RestringeDominioDoPortal.php`, `ModulosPortal.php` (onda E: remover) | controller/route/config | — | (remoção) | n/a |

### Frontend

| Arquivo | Papel | Fluxo | Analog | Qualidade |
|---|---|---|---|---|
| `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` (reescrita: cards viram lista) | page | request-response | ela mesma (`TokenBadge` L21, `CardEmpresa` L49, `RodapePolos` L71) | exato |
| `resources/js/Pages/Mlb/Publicador/Produtos.jsx` (novo) | page | CRUD | `AnunciosEmpresas.jsx` + `ModoAnuncioTabs.jsx` | role-match |
| `resources/js/Pages/Mlb/Publicador/Editor.jsx` (novo) | page | request-response (JSON + polling) | `Components/Publicador/EditorPublicador.jsx` (só lógica, não composição — D24) | role-match |
| `resources/js/Components/Publicador/Mesa/*` (BarraDoEditor, FaixaDeProdutos, Card*, Lateral*) | component | — | `Components/Publicador/*` (CampoAtributo, EditorDeEixos, GradeVariantes, FotosPorGrupo, Problemas) | role-match |
| `resources/js/Components/Publicador/usePublicador.js` (hook extraído) | hook | event-driven (polling) | estado/salvar/polling de `EditorPublicador.jsx` | exato (extrair) |
| `resources/js/Components/Publicador/apoio.js` (alterar L6) | utility | — | ele mesmo | exato |
| `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` (alterar MODOS L19-24) | component | — | ele mesmo | exato |
| `SeletorPrograma`, `IndicadoresDoPrograma`, `PainelComoFunciona`, `SeloPortal`, `SeloStatusProduto`, `AvisoContaTravada`, `ModalNovoProduto`, `BotaoAnunciarPorIa` (novos) | component | — | `AnunciosEmpresas.jsx` (selos), `ModoAnuncioTabs.jsx` (segmentado) | role-match |

### Testes

| Arquivo | Papel | Analog | Qualidade |
|---|---|---|---|
| `tests/Feature/Publicador/Concerns/CenarioCadeira.php` (alterar L48, 81, 89-90) | test helper | ele mesmo | exato |
| `tests/Feature/Publicador/MlbPublicadorTest.php` (converte de `PortalPublicadorTest`) | feature test | `PortalPublicadorTest.php` (L1-60) | exato |
| `MlbPublicadorEntradaTest`, `SincronizaPortalTest`, `PublicaMlbEmpresaSemCompanyTest`, `MigracaoProdutoRascunhoTest`, `MlbPublicadorAcessoTest`, `IaParaRascunhoTest`, `PortalSemAnunciarTest` (novos) | feature test | `CenarioCadeira` + `PortalPublicadorTest` | role-match |
| `tests/js/estrutura-meus-anuncios.test.js` (ajustar L95-102) + novo teste de fonte do `apoio.js` | js test | `tests/js/_fonte.js` + `estrutura-meus-anuncios.test.js` | exato |

---

## Atribuição de padrões

### `database/migrations/2026_10_02_100000_create_pub_produtos_table.php` (migration, create)

**Analog:** `database/migrations/2026_10_01_200000_create_publicador_tables.php`

**Cabeçalho/docblock com a decisão de schema** (L7-18 do analog): docblock em pt-BR explicando as armadilhas de MariaDB ("FK e unique com nome curto explícito; nenhum `nullOnDelete`; rodar no MariaDB local com `--path`"). Copiar a estrutura e acrescentar a tabela de colunas do RESEARCH §2.2 (a decisão de schema vai POR ESCRITO, e o docblock é o lugar, como no molde de `2026_09_30_100000` L8-55).

**Padrão de FK com nome curto + unique nomeado** (L36-59 do analog):
```php
Schema::create('pub_rascunhos', function (Blueprint $t) {
    $t->id();
    $t->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'pubr_oferta_fk')->cascadeOnDelete();
    // ...
    $t->timestamps();

    $t->unique('oferta_id', 'pubr_oferta_uq');
});
```
Aplicar em `pub_produtos`: três `foreignId(...)->nullable()->constrained(<tabela>, 'id', 'pubprod_<x>_fk')`; `unique('oferta_id', 'pubprod_oferta_uq')`; `index(['mlb_empresa_id','sku'], 'pubprod_empresa_sku_ix')`; `index('company_id', 'pubprod_company_ix')`. Nomes < 64 caracteres. Sem `nullOnDelete` (Q5 decide cascade vs restrict; recomendado `cascadeOnDelete` na oferta).

**Atenção (do analog `ml_tokens`, L35-46):** FK e UNIQUE em chamadas `Schema::table` separadas quando a tabela já existe; aqui a tabela é nova, então o `Schema::create` único do molde `create_publicador_tables` serve.

---

### `database/migrations/2026_10_02_100100_add_produto_id_to_pub_rascunhos.php` (migration, alter + backfill)

**Analogs:** `2026_09_21_120000_alter_ml_tokens_add_mlb_empresa_anchor.php` (nullable + change + guard `hasColumn`) e `2026_09_30_100000_amplia_unique_user_setores_por_cargo.php` (helper `hasIndex`, ordem create-antes-de-drop, `down()` que recusa).

**Coluna sozinha, depois índice/FK em chamada separada, guardado por `hasColumn`** (ml_tokens L35-46):
```php
if (! Schema::hasColumn('ml_tokens', 'mlb_empresa_id')) {
    Schema::table('ml_tokens', function (Blueprint $table) {
        $table->unsignedBigInteger('mlb_empresa_id')->nullable()->after('company_id');
    });
    Schema::table('ml_tokens', function (Blueprint $table) {
        $table->unique('mlb_empresa_id');
        $table->foreign('mlb_empresa_id')->references('id')->on('mlb_empresas')->cascadeOnDelete();
    });
}
```

**`oferta_id` nullable por `->change()` com FK existente (precedente, L50-52); não tocar em `pubr_oferta_uq`/`pubr_oferta_fk`:**
```php
Schema::table('pub_rascunhos', function (Blueprint $table) {
    $table->unsignedBigInteger('oferta_id')->nullable()->change();
});
```

**Helper `hasIndex` cross-driver para copiar literalmente** (`2026_09_30_100000` L120-138):
```php
private function hasIndex(string $table, string $index): bool
{
    if (DB::getDriverName() === 'mysql') {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
    foreach (DB::select('PRAGMA index_list(' . DB::getPdo()->quote($table) . ')') as $row) {
        if (($row->name ?? null) === $index) { return true; }
    }
    return false;
}
```

**`down()` que recusa em vez de perder dado** (L85-98): contar antes de tocar em qualquer índice e lançar `\RuntimeException` com mensagem pt-BR. Aqui: recusar se existir rascunho cujo produto tenha `oferta_id` NULL; dropar FK `pubr_produto_fk` antes do unique `pubr_produto_uq`.

**Regras do molde:** nenhum `try/catch` em volta de DDL (o try/catch da 140001 engoliu o 1553); backfill por `DB::table` (sem Eloquent), idempotente por `whereNull('produto_id')`; conferir `count === 0` e lançar. Ordem do `up()` no RESEARCH §2.2 passos 1-5.

---

### `app/Models/PubProduto.php` (model)

**Analog:** `app/Models/PubRascunho.php` (L18-46) para a forma do model; `MlbEmpresa.php:125` / `Company.php:622` para `mlToken()`.

**Forma do model** (PubRascunho L18-30, 43-46):
```php
class PubRascunho extends Model
{
    protected $table = 'pub_rascunhos';
    protected $guarded = ['id'];
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EstruturaOferta::class, 'oferta_id');
    }
```
**Âncoras do token** (MlbEmpresa L125-128; Company L622-625):
```php
public function mlToken(): HasOne { return $this->hasOne(MlToken::class, 'mlb_empresa_id'); } // MlbEmpresa
public function mlToken(): HasOne { return $this->hasOne(MlToken::class); }                    // Company (company_id)
```
Copiar o esqueleto `PubProduto::conta()` do RESEARCH "Exemplos de código" (escolhe a âncora que TEM token; lança `RegraViolada('V-ACC-01', …)`, mesma mensagem de `ClienteMlPublicador::desconectada()`, L179). Constantes `ORIGEM_PORTAL`/`ORIGEM_PUBLICADOR`. Comentários em pt-BR.

**`PubRascunho` (alterar):** manter `oferta()` (L43-46); acrescentar `produto(): BelongsTo` (`produto_id`) e `conta(): ContaMercadoLivre` delegando para `$this->produto->conta()`.

---

### `app/Services/Publicador/ClienteMlPublicador.php` e `ContaMlService.php` (alterar tipos)

**Analog:** o próprio arquivo. Trocar `Company $empresa` por `ContaMercadoLivre $empresa` em `daConta` (L46), `enviarFoto` (L57), `executar` (L72), `tokenValido` (L112); `ContaMlService::contexto` (L23). Corpo igual: `$this->ml->ensureValidToken($empresa)` já aceita a interface.

**Contrato de tipo** (`app/Contracts/ContaMercadoLivre.php`): `mlToken()`, `chaveContaMl()`, `nomeContaMl()`, `colunaAncoraMl()`. Importar `use App\Contracts\ContaMercadoLivre;`.

**Fallback de token pela âncora certa** (`MercadoLivreService.php:308-314`): já usa `$company->mlToken ?? MlToken::where($company->colunaAncoraMl(), $company->getKey())->first()`. Nenhuma mudança necessária.

**Armadilha:** nunca `$model->id` cru em cache/lock; usar `chaveContaMl()`.

---

### `app/Services/Publicador/DadosEfetivosService.php` (+`daProduto`)

**Analog:** o próprio arquivo (L27-48). Manter `daOferta(EstruturaOferta)`; acrescentar:
```php
public function daProduto(PubProduto $p): array
{
    if ($p->oferta_id === null) {
        return ['titulos' => ['gold_special' => null, 'gold_pro' => null],
                'precos'  => ['gold_special' => null, 'gold_pro' => null], 'mlbs' => []];
    }
    return $this->daOferta($p->oferta);
}
```
Forma do retorno (L25): `array{titulos: array<string, ?string>, precos: array<string, ?float>, mlbs: list<string>}`.

**Teste:** `CenarioCadeira.php:81` mocka `daOferta`; mudar para `daProduto` (ver seção de testes).

---

### Demais serviços do motor (Conferencia, Editor, Imagem, Publicacao, Repository)

**Analog:** RESEARCH §1 já lista arquivo:linha e a troca. Excertos-chave a copiar:

**`RascunhoRepository::criar`** (L39-48 atual) vira:
```php
public function criar(PubProduto $produto, array $alvos, ?array $ator = null): PubRascunho
{
    return DB::transaction(function () use ($produto, $alvos, $ator) {
        $r = PubRascunho::create(['produto_id' => $produto->id, 'oferta_id' => $produto->oferta_id,
            'status' => PubRascunho::DRAFT, 'ator' => $ator,
            'envio' => ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false]]);
        // gravarAlvos / gravarVariacao como hoje
```
(manter `oferta_id` denormalizado, RESEARCH §2.2 "D-aberta").

**Trava de piloto em `PublicacaoService::iniciar`** (L66-70 atual):
```php
$piloto = (array) config('publicador.empresas_piloto', []);
if ($piloto !== [] && ! in_array((int) $r->oferta->company_id, $piloto, true)) {
    throw new RegraViolada('PILOTO', 'O Publicador novo ainda está em teste e não publica para esta empresa.');
}
```
Generalizar (D21): checar `company_id` **e** `mlb_empresa_id` do `$r->produto` contra duas listas em `config/publicador.php` (acrescentar ao lado de `empresas_piloto`, L57, no mesmo estilo `env(... explode(',') ...)` + `array_filter(array_map('intval'))`). Preservar `PublicacaoTest` "fora do piloto não publica" (L188-191).

**`cadastrarNaRegua`** (L520-545): topo `if ($r->produto->oferta_id === null) return;` (D16).

**Critério de aceite da onda B (RESEARCH Pitfall 4):** `grep -rn "oferta->company\|->oferta\b" app/Services/Publicador app/Jobs` vazio, exceto sob `if oferta_id`.

---

### `app/Http/Controllers/MlbPublicadorController.php` (controller, request-response JSON)

**Analog:** `app/Http/Controllers/PortalPublicadorController.php`

**Construtor/imports** (L5-21, 37-43): copiar o bloco de `use` e a injeção `EditorRascunhoService`, `ImagemAssetService`, `ConferenciaService`, `PublicacaoService`, `RascunhoRepository`. Remover `PortalContexto`, `Company`, `EstruturaOferta`; adicionar `PubProduto`, `AtorDoPortal`.

**Núcleo `responder()` (copiar tal qual, L257-267):**
```php
private function responder(\Closure $acao, int $status = 200): JsonResponse
{
    try {
        $resultado = $acao();
        [$r, $extra] = is_array($resultado) ? $resultado : [$resultado, []];
        return response()->json([...$this->editor->estado($r), ...$extra], $status);
    } catch (RegraViolada $e) {
        return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
    }
}
```

**Resolução da entidade (trocar L269-280):**
```php
// hoje
$empresa = PortalContexto::empresa();
abort_unless(self::noPiloto($empresa), 404);
return EstruturaOferta::where('company_id', $empresa->id)->findOrFail($id);
// ...
return PubRascunho::where('oferta_id', $this->oferta($oferta)->id)->firstOrFail();
```
Novo: `PubProduto::findOrFail($id)` + `abort_unless(<produto pertence a empresa de programa>, 404)`; `rascunho()` por `produto_id` com `firstOrFail()` (ou `editor->abrir($produto)` cria).

**Ator (L227):** `PortalContexto::ator()` vira `AtorDoPortal::daEquipe(auth()->user())` (`app/Support/Portal/AtorDoPortal.php`).

**Validação:** copiar os arrays `$request->validate([...])` de `salvar` (L59-78), `categoria` (L90: `regex:/^MLB[0-9]+$/`), `eixos` (L101-109), `variantes` (L120-129), `foto` (L146: `max:10240`), `atribuirFotos` (L185-190). Mesmas regras, mesma ordem.

**Fila (L211-219, 221-231):** `conferir` -> `ConferirRascunhoJob::dispatch($r->id)` com 202; `publicar` com `ciente`.

**Rotas dentro do grupo:** `Route::middleware(['auth','verified','role:admin'])->prefix('mlb/anuncios')->name('mlb.anuncios.')` (`routes/mlb_anuncios.php` L23-26). Acrescentar `->prefix('publicador/produtos/{produto}')->whereNumber('produto')->name('publicador.')` espelhando as 14 rotas de `routes/web.php` L234-262, com os mesmos throttles (`throttle:120,1,publicador.abrir` etc.) e nomes `mlb.anuncios.publicador.{abrir,salvar,categoria,eixos,variantes,fotos,fotos.atribuir,fotos.remover,fotos.reenviar,condicionais,conferir,publicar,descricao,simular}`. Registrar novas rotas de entrada ANTES de `/meus/{company}`? Não colidem (prefixo `publicador/`), mas manter `/` `index` como está. `mlb.anuncios.publicador.*` não colide com `incubadora.publicador.*` (módulo oculto distinto, RESEARCH R5).

**Nova rota de categorias:** `GET publicador/categorias` -> `CategoriaBuscaService::categorias($q)` (substitui `portal.auth.estrutura.anunciar.categorias`, `EditorPublicador.jsx:141`); manter o formato `{id, nome, caminho[]}`.

---

### Controller/entrada: `index` por programa, produtos da empresa, sincronizar (Inertia)

**Analog:** `MlbAnuncioController::empresasDePolos()` (L2404-2430) e `MlbEmpresa::scopeAtivas` (L74-77).

**Padrão de agregação sem N+1 (copiar a ideia, L2418-2425):**
```php
$contagens = MlAnuncioRascunho::query()
    ->whereIn('mlb_empresa_id', $ids)
    ->selectRaw('mlb_empresa_id, status, COUNT(*) as total')
    ->groupBy('mlb_empresa_id', 'status')
    ->get()
    ->groupBy('mlb_empresa_id');
```
Trocar por `PubProduto`/`PubRascunho` agrupados por `mlb_empresa_id`.

**Consulta base (sempre `ativas()` antes do programa):**
```php
MlbEmpresa::query()->ativas()->with(['mlToken', 'company.mlToken'])
```
**Não copiar** o filtro `whereHas('implementacao', fn ($q) => $q->whereNotNull('dados->ml_oauth'))` (L2409): o carimbo mede "autorizou", não "tem token" (RESEARCH §3.2).

**Programa (D13):** `scopePrograma` novo em `MlbEmpresa`. Polos: `projeto = 'POLOS'` **ou** (projeto nulo e `fase` em `FASE_PARA_PROJETO` mapeando POLOS), referência `PolosController.php:365`. Incubadora: `tipo='INCUBADORA' OR projeto='Incubadora' OR (projeto nulo/vazio AND fase='Incubadora')`. `FASE_PARA_PROJETO` em `MlbEmpresa.php:96`; `projeto()` em L109-111.

**Sincronizar:** copiar o esqueleto `firstOrCreate` por `oferta_id` do RESEARCH "Exemplos"; capturar `QueryException` SQLSTATE `23000` (corrida) e seguir.

**Retorno Inertia:** padrão do módulo `Inertia::render('Mlb/...', [...])` com props montadas no PHP (CLAUDE.md "Architecture"). Abort por `abort(403/404)`; `$request->validate([...])` direto no controller; mensagens em pt-BR.

---

### `resources/js/Components/Publicador/apoio.js` (alterar)

**Analog:** o próprio arquivo, L6:
```js
export const rota = (nome, ofertaId, extra = {}) => route(`portal.auth.publicador.${nome}`, { oferta: ofertaId, ...extra });
```
Trocar por fábrica (RESEARCH "Rota parametrizável"):
```js
export const criarRota = (prefixo, chave) => (nome, id, extra = {}) => route(`${prefixo}.${nome}`, { [chave]: id, ...extra });
// uso: criarRota('mlb.anuncios.publicador', 'produto')
```
Manter `mensagemDe` (L8-13), `NOME_TIPO`, `NOME_ETAPA`, `ABA_DA_ETAPA` intactos. Teste de fonte novo garante que `apoio.js` não contém mais `portal.auth` (padrão `tests/js/_fonte.js`).

---

### `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` (alterar)

**Analog:** o próprio arquivo, L19-24 (`MODOS`) e L26-36:
```jsx
const MODOS = [
    { chave: 'meus',       label: 'Meus Anúncios', rota: 'mlb.anuncios.meus',      Icone: Gauge },
    { chave: 'individual', label: 'Individual',     rota: 'mlb.anuncios.wizard',    Icone: FileText },
    ...
export default function ModoAnuncioTabs({ empresaId, modo }) {
    if (!empresaId) return null;
    function trocarModo(item) {
        if (item.chave === modo) return;
        router.get(route(item.rota, { company: empresaId }));
    }
```
Mudança: `individual` aponta para a rota do Publicador (parâmetro de empresa diferente: `{company}` x identificador do Publicador); nova prop (ex.: `semCompany`) que, para `MlbEmpresa` sem `Company`, aplica `aria-disabled="true"`, `opacity-40`, `cursor-not-allowed`, `title="Disponível só para empresas cadastradas no sistema"` em Meus/Massa/Histórico (D23, UI-SPEC §7). Estilo ativo atual: `bg-ecf-yellow text-black font-semibold` (a UI-SPEC pede 400/700 em telas novas; aqui é componente existente, manter). Atualizar `tests/js/estrutura-meus-anuncios.test.js:95-102`.

---

### `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` (reescrita) e `Publicador/Produtos.jsx`

**Analog:** `AnunciosEmpresas.jsx`.

**Imports (L1-5):**
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Store, RefreshCw, Rocket, FileText, Search, PackageCheck, Loader2, Copy, Check } from 'lucide-react';
```
**Reuso:** `TokenBadge` (L21-31, chave `sem_token | expirado | ativo` via `TOKEN_BADGE`/`TOKEN_LABEL`), `fmtData` (L34-40), `RodapePolos` (L71, link de reconexão que preserva o aviso de que o clique deve vir do navegador do cliente). Extrair para `Components/Mlb/` se a tela B também usar. Rótulos novos (UI-SPEC §6): "Conectada" / "Reconectar" / "Falta reconectar".

**Troca de programa:** `router.get(route('mlb.anuncios.index'), { programa }, { preserveState: true })`. Seletor `role="radiogroup"` no estilo do container de `ModoAnuncioTabs` (`rounded-lg border border-white/[0.08] bg-ecf-card p-1`) com opção ativa em `border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow`.

**Conta travada:** `AvisoContaTravada` neutro (`Lock`, `white/55`), sem vermelho/âmbar (UI-SPEC §9).

---

### `resources/js/Pages/Mlb/Publicador/Editor.jsx` + `Components/Publicador/Mesa/*` + `usePublicador`

**Analog (lógica, não composição):** `resources/js/Components/Publicador/EditorPublicador.jsx` e os componentes de campo.

**Pontos de acoplamento a trocar** (RESEARCH §6): 13 chamadas `rota(...)` em `EditorPublicador.jsx` (l.278, 285, 307, 330, 393, 399, 409, 417, 421, 428, 435, 538-540, 755) passam pelo único `apoio.js:6`; `route('portal.auth.estrutura.anunciar.categorias')` em l.141 -> `route('mlb.anuncios.publicador.categorias')`; `estado.oferta.{id,sku,nome}` (l.131, 224, 460-461) -> `estado.produto`; textos "Abrindo a oferta…" (l.348,351) para "produto".

**Puros reaproveitáveis** (`Components/Portal/Estrutura/comum`): `Botao`, `Campo`, `CLASSE_INPUT`, `Seletor` (nunca Radix Select com `value=""`), `LinkMl`, `fmtReais`. `FotosDoPar.jsx` + `lib/fotosDoPar.js` permanecem (usados por `FotosPorGrupo`; `tests/js/fotos-do-par.test.js`).

**Normalização tipográfica obrigatória nos componentes reaproveitados** (UI-SPEC §4): 13px/11px e pesos 400/700.

**Contrato do servidor:** toda resposta devolve o estado inteiro (`editor->estado($r)`); salvamento com espera de 900ms; polling 2,5s; limite de 4 min (UI-SPEC §11). Nunca rodapé fixo com pendências.

---

### `GerarAnaliseAnuncioIaJob` / `IaParaRascunhoService` (IA -> `pub_*`)

**Analog:** `RascunhoAnuncioIaService::criarRascunho()` (L527) e `MlbAnuncioController::iaAnaliseStore` (L2219-2231). Copiar **o papel** (etapa 5 do job: gerar rascunho), **não** `montarPayload()` (formato do wizard antigo).

**Para o novo serviço:** usar sempre `EditorRascunhoService` (`trocarCategoria`, `salvar`, `salvarEixos`, `salvarVariantes`), nunca SQL direto. `MlAnuncioIaAnalise` já tem `mlb_empresa_id` (migration `2026_09_21_140000`). Ramo no job selecionado por campo da análise (ex.: `produto_id`). Entregar em duas fatias (RESEARCH §4 e R1): (1) título/descrição/categoria/atributos/pacote/garantia; (2) variações. Teste com `Http::fake`/modelo falso, sem IA real.

---

### `CategoriaBuscaService` (novo, extraído)

**Analog:** `app/Services/Portal/Estrutura/EstruturaPublicacaoService.php` L223-315 (`categorias()` e `categoriasEmLote()`).

**Assinatura a preservar** (L215-225): retorno `array<int, array{id, nome, dominio, caminho: array<int,string>}>`; usa `MlCatalogoMetaService::preverCategoria($q)` + `categoriasEmLote(array_keys($candidatos))`; categoria cujo caminho falhou aparece com `caminho` vazio, a lista não cai. Extrair ANTES de apagar o serviço (D18, Pitfall 5). O teste `AnunciarEstruturaTest.php:291` migra com ele.

---

### Onda E: saída do Portal (remoções)

**Analog:** é remoção; os pontos exatos:
- `routes/web.php` L213-262 (famílias `/estrutura/anunciar*`, `/estrutura/ofertas/{oferta}/publicacao*`, `prefix('/estrutura/ofertas/{oferta}/publicador')`) e `use ...PortalPublicadorController;` (L60).
- `app/Http/Middleware/RestringeDominioDoPortal.php` L148-168 (remover as linhas junto com as rotas).
- `app/Support/Portal/ModulosPortal.php:113`: `'anunciar' => ['rotulo' => 'Anunciar', 'rota_auth' => 'portal.auth.estrutura.anunciar'],` (o rótulo "Anunciar" em `EstruturaPrecificacao.jsx:150,172` é outra coisa, não tocar).
- Estratégia: primeiro rotas + allowlist + menu (reversível por `git revert`), código morto em commit seguinte. Fazer só com o interno no ar e verificado.
- Testes: `AcessoAoModuloEstruturaTest.php:60-69` (5 submódulos), novo `PortalSemAnunciarTest` (404 nas 3 famílias de rota).

---

### Testes

**`tests/Feature/Publicador/Concerns/CenarioCadeira.php` (adaptar, base de ~31 testes)**

Trechos a mudar (L48, 76, 81, 89-90):
```php
protected Company $empresa;
$this->empresa = Company::factory()->create();
MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', ...]);
$this->mock(DadosEfetivosService::class, fn ($m) => $m->shouldReceive('daOferta')->andReturnUsing(fn () => $this->efetivos));
$oferta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
$this->r = $this->repo->criar($oferta, [new Alvo('gold_special', '...')]);
```
Novo: criar `PubProduto` (`origem` `portal`, `company_id`, `oferta_id`) e `repo->criar($produto, …)`; o mock passa a `shouldReceive('daProduto')`.

**Preservar o padrão `Http::fake` UMA vez** (docblock L22-29 e `fakeMl()` L106-124: closures sobre estado do teste; "primeiro stub que casa vence", learnings §5). Fixtures em `tests/fixtures-ml/sondagem/...` via `fixture()` (L126-129). `ClienteMlPublicador` instanciado com closure de espera (`function (int $s) { $this->esperas[] = $s; }`).

**Novo cenário `MlbEmpresa` sem `Company`:** criar `MlbEmpresa` + `MlToken::create(['mlb_empresa_id' => ..., ...])` (sem `company_id`); `PubProduto` com `mlb_empresa_id`; afirmar que `ensureValidToken` usa a âncora certa (mutação: apontar âncora errada deve quebrar o teste). Adicionar caso em `CamadaMlTest.php` com `MlbEmpresa`.

**`PortalPublicadorTest.php` -> `MlbPublicadorTest`:** analog L1-60: `Queue::fake(); Storage::fake('local');`, `config(['publicador.empresas_piloto' => [...]])`, `MlToken::create(...)`, `$this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));`, schemas por `MlCategoriaSchema::create`. Autenticar como `User` admin (guard `web`), não sessão de portal. Traits: `CarregaSchemas`, `GabaritoDaPlanilhaEstrutural`, `RefreshDatabase`.

**`RascunhoRepositoryTest.php:162-166` (`test_excluir_a_oferta_leva_o_rascunho`):** fixa o comportamento da FK; decidir Q5 e adaptar (cascade oferta -> produto -> rascunho).

**Disciplina:** rodar `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` (baseline 230/952, 16 s) a cada commit; suíte completa estoura 512 MB, rodar por pastas; `| tail` engole o exit code.

---

## Padrões compartilhados

### Acesso (role:admin, sem permissão nova)
**Fonte:** `routes/mlb_anuncios.php` L23-26
```php
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('mlb/anuncios')
    ->name('mlb.anuncios.')
    ->group(function () {
```
**Aplica a:** todas as rotas novas do Publicador (D17). Não usar `permission:mlb.anunciar` (fora da fase).

### Erro de domínio -> 422 com mensagem
**Fonte:** `PortalPublicadorController::responder` (L257-267) + `RegraViolada`. **Aplica a:** todo endpoint JSON do `MlbPublicadorController`. Auth/404 por `abort(...)`, nunca exceção solta (CLAUDE.md "Error Handling").

### Token ML por âncora (`ContaMercadoLivre`)
**Fonte:** `app/Contracts/ContaMercadoLivre.php` + `MercadoLivreService::ensureValidToken` (L308-316). **Aplica a:** `ClienteMlPublicador`, `ContaMlService`, `ImagemAssetService`, `PubProduto::conta()`. Nunca ler token só por `company_id` (Pitfall 3).

### Efetivos do Portal opcionais
**Fonte:** `DadosEfetivosService::daOferta` L27-48 + `daProduto` novo. **Aplica a:** `ConferenciaService` (L212), `EditorRascunhoService` (L243, 294-295), `PublicacaoService`. Vazio = sem Portal; título/preço digitados vencem.

### Migrations MariaDB-safe
**Fonte:** `2026_10_01_200000` (nomes curtos, sem `nullOnDelete`) + `2026_09_30_100000` (`hasIndex`, sem try/catch, `down()` recusa). **Aplica a:** as duas migrations novas. Verificação real: `SHOW CREATE TABLE`/`SHOW INDEX` no MariaDB local com `--path` (nunca semear banco compartilhado).

### Logs (se algum serviço novo logar)
**Fonte:** CLAUDE.md "Logging": prefixo `[Publicador]`, entidade com id/nome; `catch (\Throwable)`; nunca logar `access_token` (`ClienteMlPublicador::registrar` já omite).

### Front: tokens e utilitário
**Fonte:** `cn()` de `@/lib/utils`; tokens `ecf-*`; `AppLayout`. Selos semânticos translúcidos (UI-SPEC §5). Amarelo só nos usos listados da UI-SPEC. `npm run build` ao fim e conferir `public/build/manifest.json` (worktree novo pode sair 0 sem buildar).

### Comentários
pt-BR; docblock de classe explicando a responsabilidade; seções `// ═══ Apoio ═══` como em `PortalPublicadorController` L251.

---

## Sem analog encontrado

| Arquivo | Papel | Fluxo | Motivo |
|---|---|---|---|
| Conversão `ficha.variacoes` (IA) -> eixos/variantes em `IaParaRascunhoService` | transform | transform | Nenhuma conversão existente (RESEARCH A6, R1). Usar RESEARCH §4 e o contrato do `EditorRascunhoService::salvarEixos/salvarVariantes`; planejar como fatia 2. |
| `PubProdutoFactory` / helper de `MlbEmpresa` com `MlToken` | factory | — | Não existe factory de `PubProduto` (RESEARCH Wave 0); seguir `Company::factory()` e `database/factories/UserFactory.php`. |
| Teste de migration com backfill (`MigracaoProdutoRascunhoTest`) | feature test | batch | Nenhum teste de migration com dado legado no repo; abordagem: migrar até a A, inserir `pub_rascunhos` sem `produto_id`, rodar B (RESEARCH §7 critério 5). |

---

## Metadados

**Escopo da busca de analogs:** `database/migrations`, `app/Models`, `app/Services/Publicador`, `app/Http/Controllers` (`PortalPublicadorController`, `MlbAnuncioController`), `routes/mlb_anuncios.php`, `routes/web.php`, `resources/js/Pages/Mlb`, `resources/js/Components/Publicador`, `tests/Feature/Publicador`, `tests/js`.
**Arquivos lidos para excertos:** 20.
**Data da extração:** 2026-10-02
