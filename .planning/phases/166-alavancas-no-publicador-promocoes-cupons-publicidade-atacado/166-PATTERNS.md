# Fase 166: Alavancas no Publicador — Mapa de Padrões

**Mapeado em:** 2026-10-04
**Arquivos analisados:** 52 (novos e alterados, segundo "Estrutura recomendada" do 166-RESEARCH.md)
**Análogos encontrados:** 50 / 52 (os dois sem análogo estão no fim)
**Worktree:** `C:/tmp/ecf-publicador-spec-261001` (todos os caminhos abaixo são relativos a ele)

## Classificação dos arquivos

| Arquivo novo/alterado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade |
|---|---|---|---|---|
| `app/Support/Publicador/AlavancasLiberadas.php` | trava (support) | request-response | `app/Support/Publicador/ContasLiberadas.php` | exato |
| `config/publicador.php` (+ chave `alavancas`) | config | — | `config/publicador.php` (`contas_liberadas`, linhas 115-120) | exato |
| `app/Services/Publicador/Alavancas/EscritorAlavancas.php` | serviço (executor único de escrita) | request-response + auditoria | `PublicacaoService::contaFixada` + `ClienteMlPublicador::daConta` | parcial (papel igual, sem classe equivalente) |
| `app/Services/Publicador/Alavancas/ContextoAlavancas.php` | serviço (resolve `{conta}`) | request-response | `ProgramasPublicadorService::resolver` + `PubProduto::ancoraComToken` | exato |
| `.../LeitorContaAlavancas.php`, `PromocoesLeitura`, `CuponsLeitura`, `PublicidadeLeitura`, `AtacadoLeitura`, `ProdutosDaContaService`, `PanoramaService` | serviço de leitura | request-response (GET ao ML) | `ClienteMlPublicador::daConta` + `ContaMlService::contexto` + `EditorRascunhoService::simular` | papel igual |
| `.../AnaliseAlavancasService.php` | serviço (cálculo) | transform | `EditorRascunhoService::simular` + `SimuladorVoceRecebe` | exato no cálculo |
| `.../AssinaturaDaPrevia.php` | utilitário | transform | nenhum (HMAC simples) | sem análogo |
| `.../MapeadorErroAlavanca.php` | utilitário | transform | `app/Support/Publicador/Erros/MapeadorErrosMl.php` + `RespostaMl.php` | papel igual |
| `.../Acoes/*` (15 classes de ação) | serviço/DTO de ação | request-response | `app/Support/Publicador/Payload/PayloadPlan.php` / `ItemPlano.php` (montar requisição) | parcial |
| `app/Services/Publicador/ClienteMlPublicador.php` (alterado: `array $cabecalhos = []`) | serviço HTTP | request-response | ele mesmo (`daConta`/`enviar`, linhas 177-181 e 269-282) | exato |
| `app/Http/Controllers/MlbAlavancasController.php` | controller (Inertia + JSON) | request-response | `MlbPublicadorEntradaController` (página) + `MlbPublicadorController` (JSON) | exato |
| `app/Http/Controllers/MlbAlavancasEscritaController.php` | controller (JSON) | request-response | `MlbPublicadorController::responder()` | exato |
| `app/Http/Requests/Alavancas/*` | validação | request-response | validação inline `$request->validate([...])` em `MlbPublicadorController::salvar` (o projeto não usa FormRequest no Publicador) | role-match |
| `app/Models/PubAlavancaEscrita.php` | model | CRUD | `app/Models/PubPublicacao.php` | exato |
| `app/Jobs/ExecutarLoteAlavancaJob.php` (RESEARCH sugere `app/Jobs/`; o Publicador usa `app/Jobs/Publicador/`) | job (fila `high`) | batch/event-driven | `app/Jobs/Publicador/PublicarRascunhoJob.php` | exato |
| `database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php` | migration | — | `2026_10_02_100000_create_pub_produtos_table.php` | exato |
| `routes/mlb_anuncios.php` (+ grupo `alavancas`) | rotas | — | mesmo arquivo, linhas 35-44 e 50-90 | exato |
| `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` | página Inertia | request-response | `resources/js/Pages/Mlb/Publicador/Produtos.jsx` | exato |
| `resources/js/Pages/Mlb/Publicador/Produtos.jsx` (alterado: barra) | página | — | ele mesmo (linhas 179-181) | exato |
| `resources/js/Components/Mlb/Alavancas/AreaTabs.jsx` | componente | — | `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` (estrutura) com estilo do gate | role-match |
| `.../AvisoAlavancasTravadas.jsx` | componente | — | `Components/Mlb/Publicador/AvisoContaTravada.jsx` | exato |
| `.../Panorama`, `AbaPromocoes`, `AbaCupons`, `AbaPublicidade`, `AbaAtacado`, `Historico`, `SeletorDeProdutos` | componentes | request-response (axios) | `Components/Publicador/Mesa/SeletorDeProdutos.jsx`, `Mesa/comum.jsx`, `Pages/Mlb/Publicador/Produtos.jsx` | role-match |
| `.../ModalConfirmacao.jsx` | componente (modal) | request-response | `Components/Mlb/Publicador/ModalNovoProduto.jsx` + `Mesa/botoes.jsx` | role-match |
| `.../useAlavancas.js` | hook | request-response | `Components/Publicador/usePublicador.js` + `apoio.js` (`criarRota`, `mensagemDe`) | role-match |
| `.../rotulos.js` | utilitário | — | `Components/Publicador/apoio.js` (`NOME_TIPO`) | role-match |
| `tests/Feature/Publicador/Alavancas/*` | teste de feature | — | `ConferenciaContaNaoLiberadaTest`, `MlbPublicadorAcessoTest`, `ExclusaoDaEmpresaPreservaHistoricoTest`, `MigracoesDaFaseDetectamMariaDbTest` | exato |
| `tests/Unit/Publicador/Alavancas/*` | teste unitário | — | `tests/Unit/Publicador/Erros/MapeadorErrosMlTest.php`, `RespostaMlTest.php` | exato |
| `tests/js/publicador-alavancas.test.js` | gate de fonte | — | `tests/js/publicador-entrada.test.js` | exato |
| `tests/fixtures-ml/alavancas/**` | fixtures | — | `tests/fixtures-ml/sondagem/conta/*.json` | exato |

---

## Atribuições de padrão

### `app/Support/Publicador/AlavancasLiberadas.php` (trava, request-response)

**Análogo:** `app/Support/Publicador/ContasLiberadas.php` (39 linhas, copiar a forma inteira)

**Imports e forma** (linhas 3-30):
```php
namespace App\Support\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\Company;
use App\Models\MlbEmpresa;

final class ContasLiberadas
{
    public static function libera(?ContaMercadoLivre $conta): bool
    {
        if ($conta instanceof Company) {
            $lista = config('publicador.contas_liberadas.companies', []);
        } elseif ($conta instanceof MlbEmpresa) {
            $lista = config('publicador.contas_liberadas.mlb_empresas', []);
        } else {
            return false;
        }

        return in_array((int) $conta->id, array_map('intval', $lista), true);
    }

    /** @throws RegraViolada quando a conta não está liberada */
    public static function exigir(ContaMercadoLivre $conta): void
    {
        if (! self::libera($conta)) {
            throw new RegraViolada('CONTA-LIB', 'A publicação ainda não foi liberada ...');
        }
    }
}
```
**Diferenças a aplicar:** ler `publicador.alavancas.contas_liberadas.{companies,mlb_empresas}`; código de regra `ALAV-LIB`; mensagem própria (sem a palavra "publicação"); docblock de classe em pt-BR dizendo que é "D-03, separada de `ContasLiberadas`". A classe é `final`, só métodos `static`.

**`RegraViolada`** (`app/Support/Publicador/RegraViolada.php`, linhas 51-60): `new RegraViolada(string $regra, string $mensagem, array $contexto = [])`. IDs estáveis (`V-ACC-01`, `CONTA-LIB`); a mensagem já é a do usuário.

### `config/publicador.php` (chave `alavancas`)

**Análogo:** linhas 115-120 do próprio arquivo:
```php
'contas_liberadas' => [
    'companies' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_COMPANIES', env('PUBLICADOR_EMPRESAS_PILOTO', '459')))))),
    'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_CONTAS_LIBERADAS_MLB_EMPRESAS', ''))))),
],
```
**Convenção:** comentário de bloco em pt-BR com o ID da decisão (`// D21: ...`) acima de cada chave. Para Alavancas: `// D-03 (166): ...`, SEM o `env()` de fallback aninhado (`PUBLICADOR_EMPRESAS_PILOTO`), default `'459'` só para companies. Acrescentar também `alertas` (`convite_vence_em_dias`, `recebido_queda_percentual`, `reputacao_ok`), `cache` (TTLs) e `limites` (10 produtos por análise) no mesmo estilo (`'fatia_segundos' => 45`, comentário explicando a hipótese).

---

### `app/Services/Publicador/ClienteMlPublicador.php` (alteração: cabeçalhos)

**Análogo:** ele mesmo. Pontos exatos de edição:

**`daConta`** (linhas 176-181):
```php
public function daConta(ContaMercadoLivre $conta, string $metodo, string $caminho, array $query = [], ?array $corpo = null, bool $repetir = true): RespostaMl
{
    return $this->executar($conta, $metodo, $caminho, $repetir,
        fn (string $token) => $this->enviar($token, $metodo, $caminho, $query, $corpo));
}
```
**`enviar`** (linhas 269-282): `Http::withToken($token)->acceptJson()->timeout(...)`. Acrescentar o último parâmetro `array $cabecalhos = []` nos dois e `->withHeaders($cabecalhos)` depois de `acceptJson()`. IMPORTANTE (linha 273): em método diferente de GET o `$query` vai na URL (`'?'.http_build_query($query)`) e o corpo em `['json' => $corpo ?? []]`; o `GET` usa `$req->get($url, $query)`.

**Comportamento a respeitar na escrita:** `repetir=false` desliga a repetição de 5xx/rede (linha 288), mas 401 (renovação única) e 429 (backoff) continuam. 423 NÃO é tratado aqui (fica no `EscritorAlavancas`). O construtor aceita `?\Closure $dormir` (linhas 168-174): é assim que os testes não dormem.

**Log:** `[Publicador] {$metodo} {$caminho} → HTTP ...` (linha 307). Nunca token. Para Alavancas use o prefixo `[Alavancas]` nos logs novos (CLAUDE.md).

---

### `app/Services/Publicador/Alavancas/EscritorAlavancas.php` (executor único, request-response + auditoria)

**Análogo principal:** `PublicacaoService::contaFixada` (`app/Services/Publicador/PublicacaoService.php` linhas 231-251) — é o CR-B01 da 164: relê a âncora, confere a trava e confere o vendedor do token antes de qualquer envio.

```php
$atual = $produto?->contaOuNula()
    ?? throw new RegraViolada('V-ACC-01', 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.');
...
ContasLiberadas::exigir($atual);
if ((string) ($atual->mlToken?->ml_user_id ?? '') !== (string) $fixada['seller']) {
    throw new RegraViolada('V-ACC-03', 'A conexão desta empresa agora é de outro vendedor do Mercado Livre. Nada foi enviado: ...');
}
```
**Aplicar:** `AlavancasLiberadas::exigir($conta)` no lugar de `ContasLiberadas::exigir`; conferência `GET /users/me` `.id` × `mlToken->ml_user_id` (códigos `V-ACC-03`).

**Análogo do "grava antes de enviar":** `PublicarRascunhoJob` docblock (linhas 15-26): o item é gravado `SENT` antes do POST, para uma reentrega nunca duplicar. Mesmo princípio: linha `PENDENTE` em `pub_alavanca_escritas` antes de qualquer HTTP; resposta crua sempre guardada (V11 em `RespostaMl`: "o corpo é guardado BRUTO").

**Ordem e esqueleto:** o do RESEARCH (Padrão 1, linhas 244-262): abrirLinha → `exigir` → `conferirVendedor` → `requisicoes()` → `anotar` → `marcar`. `catch (RegraViolada)` vira `RECUSADA` (sem HTTP); `catch (\Throwable)` vira `INCERTO` com `Log::error("[Alavancas] ...")`.

**Regras do projeto:** `catch (\Throwable)`, não `\Exception`; comentários em pt-BR; `dormir` injetável no construtor (copiar a forma de `ClienteMlPublicador::__construct`, linhas 165-174) para o teste de 423 não dormir.

**Ator:** o Publicador usa `array` em `PubPublicacao.ator` (cast `'array'`). Para o ator das Alavancas use `User` (id + `name` em `ator_nome`); `AtorDoPortal` (`app/Support/Portal/AtorDoPortal.php`) existe mas é do Portal do Cliente — não reaproveitar.

---

### `app/Services/Publicador/Alavancas/ContextoAlavancas.php` e controller `MlbAlavancasController` (resolver a conta)

**Análogo:** `MlbPublicadorEntradaController::produtos()` (linhas 161-170) — copiar o bloco de resolução literalmente:
```php
$alvo = $this->programas->resolver($conta);
abort_if($alvo === null, 404);

if ($alvo['chave'] !== $conta) {
    return redirect()->route('mlb.anuncios.publicador.produtos', ['conta' => $alvo['chave']]);
}

$empresa = $this->programas->empresaParaTela($alvo);
```
Troque a rota do redirecionamento por `mlb.anuncios.publicador.alavancas.index` (e, nos endpoints JSON, não redirecione: devolva 404/409 simples).

**Resolver** (`ProgramasPublicadorService::resolver`, linhas 270-300) devolve `['mlb_empresa','company','programa','chave']` ou `null`. **Âncora com token:** `PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])` (`app/Models/PubProduto.php` linhas 53-63; MlbEmpresa antes de Company, ignora `status === 'revoked'`). Sem âncora = estado "reconectar": `empresaParaTela($alvo)` já devolve `token` (`'sem_token'|'expirado'|'ativo'`) e `link_reconexao` (linhas 396-406); nenhum painel chama o ML.

**Controller:** construtor `public function __construct(private ProgramasPublicadorService $programas) {}` (linha 111 da entrada). Docblock de classe cita a fase e "Só admins — o grupo de rotas aplica role:admin". Página:
```php
return Inertia::render('Mlb/Publicador/Produtos', [
    'empresa' => $empresa,
    'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])),
    ...
]);
```
Para a Alavancas: `'liberada' => AlavancasLiberadas::libera(...)` + `'motivo'` + `'conta_ml'`. A página Inertia precisa existir em `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` (`testing.ensure_pages_exist`).

**Alteração em `MlbPublicadorEntradaController::produtos()`:** só acrescentar, se quiser, `'alavancas' => ['url' => route('mlb.anuncios.publicador.alavancas.index', ['conta' => $alvo['chave']]), 'liberada' => AlavancasLiberadas::libera(...)]` ao array de props (linha 213). Não inflar esse controller.

---

### `MlbAlavancasController` / `MlbAlavancasEscritaController` (JSON, request-response)

**Análogo:** `MlbPublicadorController` (JSON do editor). Padrão de resposta (linhas 317-337):
```php
private function responder(\Closure $acao, int $status = 200): JsonResponse
{
    try {
        $resultado = $acao();
        ...
        return response()->json([...$estado, ...$extra, 'publicacao_liberada' => ContasLiberadas::libera(...)], $status);
    } catch (RegraViolada $e) {
        return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
    }
}
```
**Aplicar:** `RegraViolada` vira 422 com `message` e `regra`; recusa por trava vira 403 com o motivo (D-03: "o SERVIDOR recusa") — decisão do plano, mas mantenha `{message, regra}` no corpo. Cada leitura devolve `{erro: '...'}` para o painel que falhou, nunca 500 (AL166-03).

**Validação:** inline no controller, estilo de `salvar()` (linhas 53-70):
```php
$dados = $request->validate([
    'alvos' => ['sometimes', 'array', 'max:2'],
    'alvos.*.listing_type_id' => ['required_with:alvos', Rule::in(['gold_special', 'gold_pro'])],
    ...
]);
```
Imports a copiar: `Illuminate\Http\JsonResponse`, `Illuminate\Http\Request`, `Illuminate\Validation\Rule`. O RESEARCH propõe `app/Http/Requests/Alavancas/*`; o Publicador não tem FormRequest — se o plano os criar, é convenção nova (ok, mas nomeie e registre). Entradas de âncora/conta vêm SEMPRE do servidor (`resolver`), nunca do corpo: comentário literal em `criarProduto` (linha 258: "As âncoras vêm do servidor (resolver), nunca do corpo da requisição.").

---

### `app/Models/PubAlavancaEscrita.php` (model, CRUD)

**Análogo:** `app/Models/PubPublicacao.php`
```php
class PubPublicacao extends Model
{
    protected $table = 'pub_publicacoes';
    public const RUNNING = 'RUNNING';
    ...
    protected $guarded = ['id'];
    protected $casts = ['conta_snapshot' => 'array', 'ator' => 'array', 'iniciada_em' => 'datetime', ...];
}
```
**Aplicar:** `$table = 'pub_alavanca_escritas'`; constantes `PENDENTE/OK/ERRO/INCERTO/RECUSADA`, `public const` em SCREAMING_SNAKE_CASE; `$guarded = ['id']`; casts `payload`/`resumo`/`resposta` → `'array'`, `enviado_em`/`concluido_em` → `'datetime'`. Docblock da classe em pt-BR dizendo o que a linha é. Método `marcar(string $resultado, ...)` (usado no esqueleto do escritor) mora aqui.

---

### `app/Jobs/ExecutarLoteAlavancaJob.php` (job, batch)

**Análogo:** `app/Jobs/Publicador/PublicarRascunhoJob.php` (87 linhas). Pasta: o análogo mora em `app/Jobs/Publicador/`; prefira `App\Jobs\Publicador\ExecutarLoteAlavancaJob` (o RESEARCH escreveu `app/Jobs/` solto — decisão do plano).

```php
class PublicarRascunhoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public array $backoff = [30];

    public function __construct(public int $publicacaoId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function retryUntil(): \DateTimeInterface { return now()->addMinutes(30); }

    public function handle(PublicacaoService $servico): void
    {
        $trava = Cache::lock("publicador:publicacao:{$p->id}", (int) config('publicador.trava_segundos', 600));
        if (! $trava->get()) { $this->release(20); return; }
        try {
            $terminou = $servico->executarFatia($p, (int) config('publicador.fatia_segundos', 45));
        } finally { $trava->release(); }

        if (! $terminou) {
            // não um dispatch novo: no driver `sync` um dispatch aqui dentro recursaria sem pausa.
            $this->release(15);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] publicação {$this->publicacaoId} quebrou: ".$e->getMessage());
        ...
    }
}
```
**Aplicar:** `Cache::lock("alavancas:lote:{$uuid}", ...)`; fatia por `config('publicador.fatia_segundos', 45)`; NUNCA `self::dispatch()` dentro do `handle` (learnings `publicador-ml.md` §6); `failed()` marca as linhas ainda `PENDENTE` como `INCERTO` com log `[Alavancas] lote {uuid} quebrou: ...`. Cada item reaplica `AlavancasLiberadas::exigir` + conferência de vendedor (a trava vale no meio do lote — mesma razão do `contaFixada` a cada fatia).

---

### `database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php` (migration)

**Análogo:** `database/migrations/2026_10_02_100000_create_pub_produtos_table.php` (77 linhas)

**Convenção de cabeçalho** (linhas 7-51): docblock "Fase NNN Plano NNN-NN (Dxx) — ...", seguido de "DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir." e uma tabela markdown `| coluna | tipo | regra |`, depois justificativa de cada `nullOnDelete` e do limite de 64 caracteres. Copiar a tabela do RESEARCH §4 para esse cabeçalho.

**Corpo** (linhas 54-77):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pub_produtos', function (Blueprint $t) {
            $t->id();
            // SET NULL (CR-B02): excluir a empresa não apaga o histórico de publicação.
            $t->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas', 'id', 'pubprod_empresa_fk')->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained('companies', 'id', 'pubprod_company_fk')->nullOnDelete();
            $t->string('sku', 120);
            $t->timestamps();

            $t->index(['mlb_empresa_id', 'sku'], 'pubprod_empresa_sku_ix');
            $t->index('company_id', 'pubprod_company_ix');
        });
    }

    public function down(): void { Schema::dropIfExists('pub_produtos'); }
};
```
**Aplicar:** FKs `pubale_empresa_fk`, `pubale_company_fk`, `pubale_user_fk` com `constrained(tabela, 'id', nome)->nullOnDelete()` (todas anuláveis, sem erro 1830); índices nomeados à mão `pubale_conta_ix` (`conta_chave`,`id`), `pubale_lote_ix`, `pubale_item_ix` (≤ 64 chars); `dateTime` (não `timestamp()`) para `enviado_em`/`concluido_em`; sem `enum`, sem `->change()`, `down()` só `dropIfExists`. Migration só CRIA (D-05, sem ALTER). Se a migration consultar o driver: comparar com `['mysql','mariadb']`.

**Gate:** acrescentar o arquivo à lista `MIGRACOES` de `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` (linhas 18-23, hoje 4 arquivos) e fazer o teste novo `MigracaoHistoricoTest` seguir `ExclusaoDaEmpresaPreservaHistoricoTest` (SET NULL preserva a linha).

---

### `routes/mlb_anuncios.php` (grupo `alavancas`)

**Análogo:** mesmo arquivo. Esqueleto (linhas 22-45):
```php
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('mlb/anuncios')
    ->name('mlb.anuncios.')
    ->group(function () {
        Route::get('publicador/empresas/{conta}', [MlbPublicadorEntradaController::class, 'produtos'])
            ->where('conta', '(empresa|company)-[0-9]+')->name('publicador.produtos');
        Route::post('publicador/empresas/{conta}/sincronizar', [...])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:20,1,publicador.sincronizar')->name('publicador.sincronizar');
        ...
        Route::prefix('publicador/produtos/{produto}')->whereNumber('produto')->name('publicador.')->group(function () {
            Route::get(...)->middleware('throttle:120,1,publicador.abrir')->name('abrir');
```
**Aplicar:** o bloco `Route::prefix('publicador/empresas/{conta}/alavancas')->where('conta', ...)->name('publicador.alavancas.')->group(...)` do RESEARCH (linhas 323-342) entra DENTRO desse grupo `role:admin`, logo depois da rota `publicador.produtos`. Cada rota leva `->middleware('throttle:N,1,alavancas.<nome>')` (o terceiro segmento é o nome do limitador, convenção do arquivo). Acrescentar `use App\Http\Controllers\MlbAlavancasController;` e `...EscritaController;` ao bloco de `use` (linhas 3-6). Parâmetros: `whereNumber`, `where('item', 'MLB[0-9]+')`, `whereUuid('lote')`. Cuidado: o `where` do grupo prefixado já aplica `{conta}` a todas as filhas.

---

### `resources/js/Pages/Mlb/Publicador/Produtos.jsx` (alteração) e `Alavancas.jsx` (nova)

**Análogo:** `Produtos.jsx` (373 linhas).

**Imports** (linhas 1-14): `AppLayout` de `@/Layouts/AppLayout`, `cn` de `@/lib/utils`, `Link, router` de `@inertiajs/react`, ícones `lucide-react`, componentes do Publicador por alias `@/Components/Mlb/Publicador/...`.

**Ponto de inserção da barra** (linhas 179-183):
```jsx
<div className="mb-6">
    <ModoAnuncioTabs empresaId={abas.company_id} modo="individual" contaPublicador={empresa.chave} />
</div>

{!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}
```
Inserir `<AreaTabs area="publicar" conta={empresa.chave} />` acima do `div.mb-6`. Mesma barra no topo de `Alavancas.jsx` com `area="alavancas"`.

**Tokens visuais a reutilizar** (linhas 36-38 e 24-31): `BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow'`; cartão `rounded-xl bg-ecf-card`; chip ativo `border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow` (isto NÃO é `bg-ecf-yellow` sólido — passa no gate por causa do `(?!\/)` e das barras). Pílula: `rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/70`.

**Estilo da barra (decisão já tomada no RESEARCH, Padrão 4):** item ativo `bg-white/[0.08] text-white` + `border-b-2 border-ecf-yellow`. NÃO copiar o estilo de `ModoAnuncioTabs` (usa `bg-ecf-yellow` sólido e não está no gate). Troca por `router.get(route('mlb.anuncios.publicador.alavancas.index', { conta }))` — mesmo mecanismo de rota do `ModoAnuncioTabs` (troca de rota, não estado local; `ModoAnuncioTabs.jsx` linhas 1-40).

**`Alavancas.jsx` deve importar TODOS os subcomponentes** (learnings `publicador-ml.md` §9: componente que nenhuma página importa não entra no manifest do Vite) e rodar `npm run build` ao fim.

---

### `Components/Mlb/Alavancas/AvisoAlavancasTravadas.jsx`

**Análogo:** `resources/js/Components/Mlb/Publicador/AvisoContaTravada.jsx` — variantes `selo`, `faixa`, `nota`, `linha`; ícone `Lock`, `text-[13px] font-bold text-white/70`, container `rounded-xl border border-white/[0.08] bg-white/[0.03] p-4`. Estado CALMO: sem `red-`, sem `amber-`, sem `AlertTriangle` (gate em `publicador-entrada.test.js`). **Texto próprio** ("Alavancas ainda não liberadas..."): o gate da linha 59 do `publicador-entrada.test.js` trava o texto do `AvisoContaTravada`, por isso não reaproveitar. Mensagem no mesmo tom: "Você pode ver e analisar tudo aqui; criar e alterar espera a liberação".

---

### `Components/Mlb/Alavancas/ModalConfirmacao.jsx`, abas e campos

**Botões:** importar de `@/Components/Publicador/Mesa/botoes` — `BotaoAcao` (`primario` = o único amarelo sólido, gradiente `from-[#FFE600] to-[#F5D400]`, via `PRIMARIO`) e `SECUNDARIO`. Regra do arquivo (cabeçalho): "há UM botão primário por vez, e ele é sempre o próximo passo". No modal, "Confirmar" é `primario`; "Cancelar" é secundário.

**Campos e seções:** de `@/Components/Publicador/Mesa/comum` — `CAMPO` (44px, borda `border-white/20`, foco amarelo), `SELECT` (nativo; `appearance-auto [&>option]:bg-ecf-card`), `AREA`, `LINK`, `Campo`, `Secao`, `ErroDoCampo` (mensagem `text-[13px] text-red-300`). Nunca Radix Select (`value=""` derruba a tela — gate proíbe `@/Components/ui/select`).

**Modal:** seguir `Components/Mlb/Publicador/ModalNovoProduto.jsx` (modal já dentro do gate de tipografia).

**Rótulos:** mapa `rotulos.js` com `DEAL → "Campanha tradicional"` etc. (lista no RESEARCH §10), espelhado por teste contra a lista de tipos do servidor.

**Gates que valem para TODO arquivo novo** (lista `ARQUIVOS` do `publicador-entrada.test.js`, linhas 12-30 e 33-48): tamanhos só `text-[24px|15px|13px|11px]` (nada de `text-xs/sm/base...`), pesos só 400/700 (`font-normal`/`font-bold`), sem `bg-ecf-yellow` sólido, sem `uppercase` e sem contador de progresso ("Faltam N", "Completo", "N/8"), sem `dangerouslySetInnerHTML`.

---

### `Components/Mlb/Alavancas/useAlavancas.js` (hook de leitura sob demanda)

**Análogo:** `resources/js/Components/Publicador/usePublicador.js` + `apoio.js`.
```js
import axios from 'axios';
export const criarRota = (prefixo, chave) => (nome, id, extra = {}) => route(`${prefixo}.${nome}`, { [chave]: id, ...extra });
export const mensagemDe = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O servidor demorou demais. ...';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];
    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};
```
**Aplicar:** `const rota = criarRota('mlb.anuncios.publicador.alavancas', 'conta');` (`rota('panorama', empresa.chave)`); `mensagemDe` reaproveitado (importar de `@/Components/Publicador/apoio`) para mostrar `message` do 422/403. Cada painel faz `axios.get` ao aparecer; falha de uma fonte não derruba as outras. Polling de lote a cada ~2,5 s (constantes no topo do hook como `INTERVALO_ANDAMENTO = 2500` e `LIMITE_ANDAMENTO`, estilo `usePublicador.js` linhas 44-48).

---

## Padrões compartilhados

### Trava no servidor em TODA escrita (CR-B01)
**Fonte:** `PublicacaoService::contaFixada` (linhas 231-251) + `ContasLiberadas::exigir`.
**Aplicar a:** `EscritorAlavancas`, `ExecutarLoteAlavancaJob`, `MlbAlavancasEscritaController::confirmar` (chama `exigir` antes de criar lote). Teste de guarda (AL166-05): varre `app/Services/Publicador/Alavancas` e `app/Http` e falha se aparecer `'POST'|'PUT'|'DELETE'` fora de `EscritorAlavancas.php`.

### Resposta do ML classificada, nunca lança
**Fonte:** `app/Support/Publicador/Erros/RespostaMl.php`.
```php
public function ok(): bool { return $this->status >= 200 && $this->status < 300; }
public function podeTerCriado(): bool { return in_array($this->classe, [self::NETWORK, self::SERVER], true); }
// classes: OK, WARNING, VALIDATION, AUTH, PERMISSION, RATE_LIMIT, SERVER, NETWORK, UNKNOWN_FORMAT
```
`public readonly string $classe`, `public readonly mixed $corpo` (cru), `$status`, `$retryAfter`. **Não alterar `RespostaMl`** (RESEARCH §2): o formato de erro das promoções (`cause[].error_code`) e do PxQ (`{error, code, cause_id}`) é lido do `$resposta->corpo` pelo `MapeadorAlavancaErro`. Mapeamento: `status 0`/5xx na escrita → `INCERTO`; 4xx → `ERRO`; 2xx → `OK`; trava → `RECUSADA`.

**Análogo do mapeador:** `app/Support/Publicador/Erros/MapeadorErrosMl.php` (ver `tests/Unit/Publicador/Erros/MapeadorErrosMlTest.php` para o formato do teste: tabela de códigos conhecidos → mensagem pt-BR, fallback "O Mercado Livre recusou: <original>").

### Logging
**Fonte:** `ClienteMlPublicador::registrar` (linha 301-308) e `PublicarRascunhoJob::failed`.
Prefixo de módulo entre colchetes e ids: `Log::error("[Alavancas] lote {$uuid} quebrou: ".$e->getMessage())`. Nunca token nem `Authorization` (nem em `payload`).

### Cálculo "quanto a loja recebe" (D-11)
**Fonte:** `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` e `EditorRascunhoService::simular()` (linhas 252-288).
```php
SimuladorVoceRecebe::calcular(float $preco, float $tarifa, ?float $frete)
// -> ['preco','tarifa','frete','voce_recebe','percentual','frete_conhecido']
```
Tarifa pública: `$this->cliente->publico('/sites/MLB/listing_prices', ['price' => $preco, 'category_id' => ..., 'listing_type_id' => ..., 'currency_id' => 'BRL', 'logistic_type' => ..., 'shipping_mode' => ...])` e usa `$tarifa->corpo['sale_fee_amount']`. Frete: `daConta($conta, 'GET', "/users/{$sellerId}/shipping_options/free", [... 'dimensions' => $pacote, 'verbose' => 'true'])` e `$f->corpo['coverage']['all_country']['list_cost']`. **Diferença para publicados:** `logistic_type`/`shipping_mode` vêm do item (`item.shipping`), não fixos em `drop_off`/`me2`. Sem dimensões = `frete = null` (`frete_conhecido=false`), nunca inventar.

### Teste com `Http::fake` (learnings §5) — registrar UMA vez
**Fonte:** `tests/Feature/Publicador/Concerns/CenarioCadeira.php` (`fakeMl()`):
```php
Http::fake([
    '*/users/me' => fn () => Http::response($this->usuario ?? self::fixture('conta/usuario')),
    '*/items/search*' => fn (Request $req) => Http::response([... $this->skuEm[$req->data()['seller_sku'] ?? ''] ?? [] ...]),
    '*/items/validate' => fn (Request $req) => ($this->validate)($req->data()),
    ...$extra,
]);
```
Closures leem propriedades do teste (`$this->usuario`, `$this->esperas`); mude o ESTADO, nunca chame `Http::fake()` de novo (o primeiro stub que casa vence). O cliente de teste:
```php
$this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class),
    function (int $s) { $this->esperas[] = $s; }));
```
Token falso: `'access_token' => 'fake-access-token'`, `'ml_user_id' => '1555596317'` (a #459). Liberar conta no teste: `config(['publicador.alavancas.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]])`. Não liberar: `['companies' => [], 'mlb_empresas' => []]`. Âncora `MlbEmpresa` sem `Company`: `montarCenario('mlb_empresa')`. Para o cenário das Alavancas crie um trait `Concerns/CenarioAlavancas` (não herde `CenarioCadeira`: ele monta rascunho/schema de cadeira e usa `publicador.contas_liberadas`).

**Mutação de trava** (padrão de `ConferenciaContaNaoLiberadaTest`, docblock): "tirar a trava quebra um dos dois lados". Asserção de zero chamada ao cliente:
```php
foreach ($requisicoes as $q) {
    $this->assertNotSame(['Bearer fake-access-token'], $q->header('Authorization'), '...'.$q->url());
}
Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/items/validate'));
```
Para Alavancas: conta não liberada + POST em `escritas` → nenhuma requisição com método ≠ GET (`Http::recorded()`), linha `RECUSADA` gravada. Teste obrigatório "em ambos os sentidos": liberar na publicação não libera Alavancas e vice-versa (AL166-04).

### Teste de acesso
**Fonte:** `MlbPublicadorEntradaTest` e `MlbPublicadorAcessoTest`:
```php
$this->actingAs(User::factory()->create(['role' => 'consultor']))->get('/mlb/anuncios')->assertForbidden();
$r = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(...)->assertOk();
$page = $r->viewData('page'); // $page['component'], $page['props']
```
`setUp()` com `$this->withoutVite()`; `use RefreshDatabase;`; `Queue::fake()` para lote. Rotas por nome: `route("mlb.anuncios.publicador.alavancas.index", ['conta' => 'empresa-N'])`.

### Fixtures do ML
**Fonte:** `tests/fixtures-ml/sondagem/conta/*.json` (`usuario.json`, `items_search_seller_sku.json`, `shipping_options_free_*.json`). Formato: arquivo com chave `resposta` (carregada por `json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta']`). Novas em `tests/fixtures-ml/alavancas/` (pasta SEM homônima em caixa diferente — learnings §2). `usuario.json` da #459 tem `tags` com `business` e `seller_reputation.level_id = "5_green"`.

### Gate de fonte do front
**Fonte:** `tests/js/publicador-entrada.test.js` + `tests/js/_fonte.js` (`lerSemComentarios`).
```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

const ARQUIVOS = [DIR + '...', 'resources/js/Pages/Mlb/Publicador/Produtos.jsx'];
for (const caminho of ARQUIVOS) {
    const fonte = lerSemComentarios(caminho);
    test(`${caminho} — tipografia: só 24/15/13/11px`, () => { ... });
    test(`${caminho} — peso: só 400 e 700`, () => { ... });
    test(`${caminho} — sem amarelo sólido, sem select Radix, sem HTML injetado`, () => { ... });
}
```
`publicador-alavancas.test.js` copia esse laço com a lista `ARQUIVOS` dos componentes novos + `Pages/Mlb/Publicador/Alavancas.jsx`. Gates extras (RESEARCH §10): `Produtos.jsx` importa `AreaTabs`; nenhum arquivo contém `/prices/standard/quantity`, `product_ads/items` nem `ads/search`; `AvisoAlavancasTravadas` sem `red-`/`amber-`; `rotulos.js` espelha os tipos do servidor. Lembre: `Produtos.jsx` já está em `ARQUIVOS` do gate da 164 — o `bg-ecf-yellow` sólido continua proibido nele; `Produtos.jsx` ganhar `AreaTabs` não pode quebrar os gates existentes (rodar `npm run test:js`).

### Idioma e estilo de código
Comentários e docblocks em pt-BR; comentário de classe cita o ID da decisão (`D-03`, `AL166-05`); `final class` para utilitários estáticos; `catch (\Throwable)`; constantes `public const` SCREAMING_SNAKE_CASE; PHP 8.2 (`readonly`, `match`, promoção de propriedades no construtor).

---

## Sem análogo no código

| Arquivo | Papel | Fluxo | Motivo |
|---|---|---|---|
| `Services/Publicador/Alavancas/AssinaturaDaPrevia.php` | utilitário | transform | Nenhum HMAC de prévia no projeto. Usar o RESEARCH Padrão 2: `hash_hmac('sha256', json(payload_canônico + conta.chave + user.id + exp), config('app.key'))`, comparar com `hash_equals`, TTL 10 min. |
| `Services/Publicador/Alavancas/Acoes/*` (as 15 classes) | ação | request-response | Nenhuma "ação com `validar()`/`resumo()`/`requisicoes()`" existe. O mais próximo é `Support/Publicador/Payload/PayloadPlan` e `ItemPlano` (objetos de valor que viram requisição), mas o contrato `AcaoAlavanca` é novo — o plano deve defini-lo antes das classes concretas. |

(Os demais arquivos têm ao menos um análogo de papel, ainda que parcial.)

## Metadados

**Escopo da busca de análogos:** `app/Services/Publicador`, `app/Support/Publicador`, `app/Http/Controllers/MlbPublicador*`, `app/Jobs/Publicador`, `app/Models/Pub*`, `database/migrations/2026_10_0*`, `routes/mlb_anuncios.php`, `config/publicador.php`, `resources/js/Pages/Mlb/Publicador`, `resources/js/Components/{Publicador,Mlb/Publicador}`, `tests/{Feature,Unit}/Publicador`, `tests/js/publicador-*.test.js`.
**Arquivos lidos:** ~25. **Extração:** 2026-10-04.
**Observação sobre `PublicarRascunhoJob`:** mora em `app/Jobs/Publicador/` (não em `app/Jobs/`).
**Pendência de decisão do plano:** pasta do job de lote (`Jobs/` × `Jobs/Publicador/`) e se haverá `FormRequest` (o Publicador valida inline).
