# Phase 133: Liga o bloqueio — ativação real (v22.0) - Mapa de Padrões

**Mapeado:** 2026-08-18
**Arquivos analisados:** 7 (5 código + 1 dívida/todo + fixtures de teste)
**Análogos encontrados:** 7 / 7

## Classificação dos Arquivos

| Arquivo novo/modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Services/Operacional/EmpresaOperacionalRouter.php` (método privado `rotear()`, MODIFICAR) | service | CRUD condicional (filtra antes de criar) | O próprio comentário "PONTO DE EXTENSÃO" já dentro do método (linhas 106-125) + `Servico::scopeExigeContrato()` | exato — RESEARCH.md já traz o trecho-alvo pronto |
| `app/Http/Controllers/MlbController.php::ativarEmpresaPendente()` (MODIFICAR) | controller (method) | request-response, recusa condicional | `ContratoAdminController::gerarContrato()` (checagem `CongelamentoEmissaoService`, linhas 408-428) | exato — mesmo formato "check no topo + log + recusa" |
| `app/Http/Controllers/ContratoAdminController.php::index()` (MODIFICAR — 1 prop nova) | controller (method) | request-response, prop escalar | O próprio `index()`, bloco de retorno `Inertia::render` (linhas 196-204) | exato — mesmo arquivo, mesmo padrão de prop booleana/escalar |
| `resources/js/Pages/Admin/Contratos.jsx` (MODIFICAR — faixa nova) | component (page) | request-response (prop → render condicional) | `resources/js/Pages/Admin/ContratoDetalhe.jsx` (bloco "emissão pausada", linhas 251-271) | exato — mesmo módulo, mesmo tom "canal âmbar/neutro" |
| `tests/Feature/Phase124KillSwitchTest.php` (MODIFICAR — 4 testes) | test | feature (HTTP + chamada direta) | O próprio arquivo (estrutura já existe) + `tests/Feature/Phase128/InvarianteRoteamentoTest.php` (fixture `exige_contrato` explícito) | exato |
| `tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php` (CRIAR) | test | feature (HTTP) | `Phase124KillSwitchTest.php` (estrutura de setUp + helpers) + `InvarianteRoteamentoTest.php` (fixture Assessoria `exige_contrato=true`) | role-match — não existe teste hoje para `ativarEmpresaPendente()` |
| `.planning/todos/pending/*.md` (CRIAR — dívida D-06) | doc (não código) | — | `.planning/todos/pending/280726-debora-sem-carteira-na-consolidacao-mensal.md` (estrutura de todo) | exato |

## Atribuições de Padrão

### `app/Services/Operacional/EmpresaOperacionalRouter.php::rotear()` (service, CRUD condicional)

**Análogo:** o próprio método (comentário-alvo já existe) + `Servico::scopeExigeContrato()` / `Servico::exigeContrato()`

**Estado atual** (`app/Services/Operacional/EmpresaOperacionalRouter.php:106-125`):
```php
private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
{
    if ($this->bloqueioAtivo()) {
        // PONTO DE EXTENSÃO da Fase 128 (FLUXO-08/D-09): aqui vai entrar a consulta
        // "este serviço exige contrato?", que isenta Polos do bloqueio.
        Log::warning('[Administrativo] Roteamento operacional bloqueado pelo interruptor de emergência (' . self::CHAVE_BLOQUEIO . ').', [
            'company_id' => $company->id,
        ]);

        return;
    }

    $this->aplicarRoteamento($company, $nomesServicos, $handoff, $guardPorEmpresa);
}
```

**Consulta reutilizável já existente** (`app/Models/Servico.php:181-189, 244-257`):
```php
/**
 * Scope: apenas serviços que exigem contrato (Fase 128-01, D-03).
 * Exemplo: Servico::query()->exigeContrato()->pluck('nome')
 */
public function scopeExigeContrato($query)
{
    return $query->where('exige_contrato', true);
}

/**
 * quem decide é o dado (`servicos.exige_contrato`), não o nome. A
 * migration `2026_08_13_100001_add_exige_contrato_to_servicos_table`
 * já nasce com Polos isento e os demais serviços exigindo (default
 * seguro), sem precisar enumerar nomes fora dela.
 */
public function exigeContrato(): bool
{
    return (bool) $this->exige_contrato;
}
```

**Implementação recomendada (já validada pelo RESEARCH.md, filtro em lote sem N+1):**
```php
private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
{
    if ($this->bloqueioAtivo()) {
        $nomes = collect($nomesServicos)->values();

        // Uma única query — nunca dentro de foreach. Nomes que não batem
        // contra nenhum Servico ficam FORA de $isentos (fail-safe: exige
        // contrato por padrão — mesmo espírito do default(true) da
        // migration 2026_08_13_100001).
        $isentos = Servico::whereIn('nome', $nomes)
            ->where('exige_contrato', false)
            ->pluck('nome');

        $liberados = $nomes->intersect($isentos)->values();
        $retidos   = $nomes->diff($liberados)->values();

        if ($retidos->isNotEmpty()) {
            Log::warning('[Administrativo] Roteamento operacional retido pelo gate administrativo.', [
                'company_id'       => $company->id,
                'servicos_retidos' => $retidos->all(),
            ]);
        }

        if ($liberados->isEmpty()) {
            return;
        }

        $this->aplicarRoteamento($company, $liberados, $handoff, $guardPorEmpresa);
        return;
    }

    $this->aplicarRoteamento($company, $nomesServicos, $handoff, $guardPorEmpresa);
}
```

**Padrão de log a seguir** (mesmo prefixo `[Administrativo]` + `company_id`, já usado em 3 lugares no mesmo service — `liberarEmpresa()` linhas 325-331, `rotear()` linha 117): sempre `Log::warning`/`Log::info` com array associativo incluindo `company_id`. Nunca string interpolada solta.

---

### `app/Http/Controllers/MlbController.php::ativarEmpresaPendente()` (controller, request-response, D-03/D-07)

**Análogo exato — o precedente da Fase 132:** `app/Http/Controllers/ContratoAdminController.php::gerarContrato()` (linhas 408-428)

```php
// Source: app/Http/Controllers/ContratoAdminController.php:408-428
public function gerarContrato(
    Company $company,
    ContratoDadosMinimosService $dados,
    GatilhoContratoAdministrativoService $gatilho,
    CongelamentoEmissaoService $congelamento,
): RedirectResponse {
    // Fase 132 Plano 01 (D-07) — PRIMEIRA coisa do método, antes de
    // qualquer avaliação ou I/O. Fecha a janela entre a troca de
    // credenciais (plano 132-02) e a aprovação final (plano 132-04):
    // enquanto congelada, nenhum contrato nasce para empresa nenhuma,
    // nem para quem já está com o cadastro completo e elegível. O
    // `disabled` do botão no client não é controle (T-131-04-03) — esta
    // checagem no servidor é.
    if ($congelamento->ativo()) {
        Log::warning('[Administrativo] Tentativa de gerar contrato com a emissão congelada', [
            'company_id' => $company->id,
            'user_id'    => auth()->id(),
        ]);

        return back()->with('error', 'A emissão de contratos está pausada no momento. ...');
    }

    $avaliacao = $gatilho->avaliar($company);
    // ... resto do método
}
```

**Elementos a copiar (mesma estrutura, aplicada em `ativarEmpresaPendente()`):**
1. Checagem **primeiro**, antes de qualquer `abort_if`/`validate`/`DB::transaction` — mesma disciplina "primeira coisa do método".
2. `Log::warning('[Administrativo] ...')` com `company_id` (aqui + `user_id` se fizer sentido, como no exemplo).
3. Recusa — aqui NÃO é `back()->with('error', ...)` porque o método atual devolve `back()->with('success', ...)`. Ver `## Padrões Compartilhados` para a decisão de formato (Claude's Discretion do CONTEXT.md).

**Método-alvo atual completo, para referência de onde entra a checagem** (`app/Http/Controllers/MlbController.php:2432-2487`):
```php
public function ativarEmpresaPendente(Request $request, Company $company)
{
    $this->checkPubAccess('empresas');

    abort_if(
        MlbEmpresa::where('company_id', $company->id)->exists(),
        422,
        'Esta empresa já possui um registro MLB.'
    );

    $validated = $request->validate([
        'tipo' => 'required|in:polos,assessoria',
    ]);

    // <<< AQUI ENTRA A CHECAGEM (D-03/D-07) — antes da DB::transaction >>>

    DB::transaction(function () use ($company, $validated, $request) {
        if ($validated['tipo'] === 'polos') {
            // ... cria MlbEmpresa POLO + MlbImplementacao
        } else {
            // ... cria MlbEmpresa ASSESSORIA
        }
    });

    $label = $validated['tipo'] === 'polos' ? 'Polos (com Onboarding)' : 'Assessoria';
    return back()->with('success', '"' . $company->name . '" ativada como ' . $label . '.');
}
```

**Fonte da verdade para a decisão (D-07 — NÃO usar `$validated['tipo']` cru):** consultar os serviços realmente contratados da empresa, mesma coleção já usada no mesmo controller em `empresas()`:

```php
// Source: app/Http/Controllers/MlbController.php:2404-2406 (padrão já usado
// no mesmo controller, mesma classe de dado — $company->contratosServico)
$nomes = $e->contratosServico->where('ativo', true)->pluck('servico.nome')->filter();
```

E a relação em si (`app/Models/Company.php:408-411`):
```php
public function contratosServico()
{
    return $this->hasMany(ContratoServico::class);
}
```

Combinar com `ComercialController::servicoDisparaImplementacao()` (mesmo helper que o router usa, `app/Http/Controllers/ComercialController.php:58-66`) para resolver qual `Servico` contratado corresponde ao `$validated['tipo']` pedido, e então checar `exigeContrato()` nesse `Servico` (não em nenhum outro).

---

### `app/Http/Controllers/ContratoAdminController.php::index()` (controller, prop booleana nova — D-04)

**Análogo:** o próprio `index()`, bloco de retorno atual (`app/Http/Controllers/ContratoAdminController.php:196-204`):

```php
return Inertia::render('Admin/Contratos', [
    'linhas'             => $paginator,
    'filters'            => [
        'situacao' => $situacao,
        'q'        => $q,
    ],
    'resumo'             => $resumo,
    'sem_contrato_count' => $semContratoCount,
]);
```

**Padrão a seguir para a prop nova** — booleano calculado no backend, nome em `snake_case`, sem lógica no React (já confirmado em `resources/js/Pages/Admin/Contratos.jsx:36`, que já desestrutura `sem_contrato_count = 0` com default):

```php
// NOVO (D-04) — usar App\Services\Operacional\EmpresaOperacionalRouter,
// resolvido via container (mesmo padrão de injeção que o método `show()`
// já usa para CongelamentoEmissaoService, linha 221).
'bloqueio_ativo' => app(EmpresaOperacionalRouter::class)->bloqueioAtivo(),
```

⚠️ Import necessário: `app/Http/Controllers/ContratoAdminController.php` já importa `App\Services\Operacional\EmpresaOperacionalRouter` (linha 18) — está listado no `use` mas **hoje não é usado em `index()`** (verificar se é usado em outro método antes de assumir que precisa adicionar o `use`; se não, o import já existe e só falta a chamada).

---

### `resources/js/Pages/Admin/Contratos.jsx` (component, faixa condicional — D-04)

**Análogo exato de tom e estrutura:** `resources/js/Pages/Admin/ContratoDetalhe.jsx:251-271` (bloco "emissão pausada", Fase 132):

```jsx
// Source: resources/js/Pages/Admin/ContratoDetalhe.jsx:257-271
<Card>
    <CardContent className="p-4 space-y-3">
        <h2 className="text-white/85 text-[15px] font-semibold">A emissão de contratos está pausada</h2>
        <p className="text-[13px] text-white/60">
            É temporário e proposital — vale para todas as empresas, não é problema desta
            empresa nem do que foi preenchido. Nada do que já foi preenchido se perde.
        </p>
        <p className="text-[13px] text-white/50">
            Fale com o time técnico e tente de novo quando for avisado.
        </p>
        <Button disabled className="opacity-40 cursor-not-allowed">
            Gerar contrato
        </Button>
    </CardContent>
</Card>
```

**Análogo alternativo mais simples (mesmo arquivo-irmão, faixa âmbar sem Card, usada para avisos de flash):**
```jsx
// Source: resources/js/Pages/Admin/ContratoDetalhe.jsx:240-245
{flash?.aviso && (
    <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
        <p className="font-semibold">Aguarde um pouco antes de reenviar</p>
        <p className="mt-0.5 text-amber-300/80">{flash.aviso}</p>
    </div>
)}
```

**Recomendação (D-04, granularidade em Claude's Discretion):** usar a variante SEM `<Card>` (segunda opção acima) — é uma faixa curta no topo da lista, não um bloco substituindo uma ação, então o padrão `flash?.aviso` (div âmbar direta) é o mais próximo do "faixa" pedido pelo CONTEXT.md.

**Posição e integração recomendadas** (RESEARCH.md já indica): logo após o `<h1>` (linha 66-69 do `Contratos.jsx`) e antes do grid de resumo (linha 76). Prop nova recebida por desestruturação, seguindo o padrão já usado nas outras props escalares:

```jsx
// Estado atual (resources/js/Pages/Admin/Contratos.jsx:36) — padrão de
// desestruturação com default a seguir para bloqueio_ativo:
export default function Contratos({ linhas, filters = {}, resumo = {}, sem_contrato_count = 0, bloqueio_ativo = false }) {
```

**Linguagem sem jargão (D-04, UI-06):** nada de "flag", "roteamento", "ficha operacional" — seguir o mesmo tom do bloco de `ContratoDetalhe.jsx` acima ("é temporário e proposital", "nada do que já foi preenchido se perde"). A faixa deve comunicar a CONSEQUÊNCIA ("enquanto o contrato não for assinado, a empresa não entra na operação"), não o mecanismo.

---

### `tests/Feature/Phase124KillSwitchTest.php` (4 testes a reescrever — Pitfall 1)

**Estrutura atual completa lida** (`tests/Feature/Phase124KillSwitchTest.php:1-300`). Os 4 testes que quebram:

| Teste | Linha | Asserção atual (fica FALSA com a mudança) |
|---|---|---|
| `test_interruptor_ligado_impede_o_roteamento_do_cadastro` | 186-196 | `rotearCadastro($company, ['Polos'])` com chave ligada → `MlbEmpresa::count() === 0` |
| `test_interruptor_ligado_impede_o_roteamento_por_servico` | 204-214 | `rotearServico($company, 'Polos')` idem |
| `test_interruptor_ligado_impede_o_cadastro_manual_de_criar_ficha` | 253-275 | POST `/comercial/empresas` com Polos, chave ligada → `MlbEmpresa::count() === 0` |
| `test_interruptor_ligado_impede_o_webhook_de_criar_ficha` | 286-299 | Webhook HubSpot `servico_ecf: 'Polos'`, chave ligada → idem |

**Padrão de fixture no `setUp()` atual (herda isenção do seed real — é isso que causa a quebra):**
```php
// Source: tests/Feature/Phase124KillSwitchTest.php:44-53
protected function setUp(): void
{
    parent::setUp();

    foreach (['Polos', 'Assessoria', 'Incubadora'] as $nome) {
        Servico::firstOrCreate(
            ['nome' => $nome],
            ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
        );
    }
    // ...
}
```

**Como corrigir cada um dos 4 (recomendação do RESEARCH.md — trocar o cenário para um serviço que EXIGE contrato, ex. 'Assessoria', e adicionar um teste NOVO e separado provando a exceção com 'Polos'):**

1. Trocar `'Polos'` por `'Assessoria'` nas 4 chamadas/POSTs — `Assessoria` já nasce com `exige_contrato` herdado do default `true` da migration (confirmado, `InvarianteRoteamentoTest.php` fixture abaixo), então continua sendo retida com a chave ligada — a asserção original (`count() === 0`) volta a ser verdadeira.
2. Adicionar teste(s) NOVO(s) e SEPARADO(s) provando SC 2b: `Polos` roteia mesmo com a chave ligada (`count() === 1`, `assertNotNull`).

**Fixture com `exige_contrato` explícito, análogo a copiar** (`tests/Feature/Phase128/InvarianteRoteamentoTest.php:45-58`):
```php
// Source: tests/Feature/Phase128/InvarianteRoteamentoTest.php:45-58
// 'Assessoria' dispara MlbEmpresa (tipo ASSESSORIA) via
// EmpresaOperacionalRouter E exige contrato por padrão (só 'Polos' é
// isento, plano 128-01) — serviço certo para provar os dois portões
// (operacional + administrativo) na mesma empresa.
Servico::firstOrCreate(
    ['nome' => 'Assessoria'],
    [
        'valor_padrao'   => 500,
        'tipo_cobranca'  => Servico::TIPO_MENSAL,
        'ativo'          => true,
        'setor'          => Servico::SETOR_PUBLICACAO,
        'exige_contrato' => true,
    ],
);
```

⚠️ **Pitfall 4 do RESEARCH.md (a armadilha central desta fase):** o DEFAULT de schema para `Servico` novo é `exige_contrato = true` (migration `2026_08_13_100001_add_exige_contrato_to_servicos_table.php`). Um `firstOrCreate(['nome' => 'Polos'], [...])` **sem** setar `exige_contrato` herda o valor do SEED real (que já roda no `RefreshDatabase`, migration `2026_05_27_100001_seed_servicos_catalog.php` + `2026_08_13_100001_...`), que É `false` para Polos especificamente. Mas se qualquer fixture nova criar `Servico::create([...])` puro (sem `firstOrCreate` contra o seed), **nasce com `exige_contrato=true`** — inclusive se o nome for literalmente "Polos". Nunca assumir isenção pelo nome; sempre confirmar o dado.

**Teste de referência para "nasce isento por nome via seed, nunca por hardcode":** `tests/Feature/Phase128/ExigeContratoTest.php` (arquivo completo, 74 linhas) — em especial:
```php
// Source: tests/Feature/Phase128/ExigeContratoTest.php:20-27
#[Test]
public function polos_semeado_pela_migration_ja_nasce_isento_de_contrato(): void
{
    $polos = Servico::where('nome', 'Polos')->first();

    $this->assertNotNull($polos, 'seed do catálogo deveria ter criado o serviço Polos');
    $this->assertFalse($polos->exigeContrato());
}
```

---

### `tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php` (CRIAR — cobertura FLUXO-09, hoje zero)

**Análogo de estrutura/helpers:** `tests/Feature/Phase124KillSwitchTest.php` inteiro — copiar os helpers privados reaproveitáveis:

```php
// Source: tests/Feature/Phase124KillSwitchTest.php:79-92 (helpers a reaproveitar)
private function criarEmpresa(string $nome = 'Empresa Teste Interruptor'): Company
{
    return Company::create(['name' => $nome]);
}

private function router(): EmpresaOperacionalRouter
{
    return app(EmpresaOperacionalRouter::class);
}

private function userAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}
```

**Cenários mínimos exigidos pelo CONTEXT.md (D-03: cobrir os dois caminhos + caso Polos-com-chave-ligada) e pelo RESEARCH.md (`Phase Requirements → Test Map`):**
1. Chave ligada + empresa com serviço "Polos" contratado (`ContratoServico` ativo) → `POST` para a rota de `ativarEmpresaPendente` com `tipo=polos` → `MlbEmpresa::count() === 1` (Polos passa, SC 2b).
2. Chave ligada + empresa com serviço "Assessoria" contratado → `tipo=assessoria` → recusa (sem criar `MlbEmpresa`, resposta conforme decisão de formato — ver `## Padrões Compartilhados`).
3. Chave desligada (estado atual de produção) → comportamento inalterado nos dois tipos (regressão).
4. (Opcional, mas recomendado pelo D-07) — `$validated['tipo']` divergente do serviço realmente contratado: confirma que a checagem usa `contratosServico`, não o rótulo do formulário.

**Fixture de `Servico` a usar** — reaproveitar a MESMA lógica do Pitfall 4 acima: Polos via seed real (`firstOrCreate` sem sobrescrever `exige_contrato`), Assessoria com `exige_contrato: true` explícito (copiar do `InvarianteRoteamentoTest.php` acima). E, adicionalmente, criar o vínculo `ContratoServico` ativo entre a `Company` de teste e o `Servico`, já que a checagem de D-07 lê `$company->contratosServico`.

---

### `.planning/todos/pending/*.md` (CRIAR — dívida D-06, as "duas portas extras")

**Análogo de estrutura de todo:** `.planning/todos/pending/280726-debora-sem-carteira-na-consolidacao-mensal.md` (título H1, blocos `**Criado:**`/`**Criticidade:**`/`**Descoberto em:**`, seções `## O sintoma`, `## Onde investigar`, sem seção de "correção pronta" quando a investigação ainda está em aberto).

**Conteúdo a registrar (conforme D-06 do CONTEXT.md):**
- Nome sugerido: `260818-mlbimplementacao-storeempresa-fora-do-router.md` (convenção `YYMMDD-slug.md`, ver arquivos existentes no diretório).
- Rotas: `MlbImplementacaoController::criar()` (`POST /mlb/implementacao`, linha 492-549, sempre `tipo='POLO'` hardcoded, sem `company_id`) e `MlbController::storeEmpresa()` (`POST /mlb/empresas`, linha 2489-2522, `tipo` não validado → default de schema `'POLO'`, sem `company_id`).
- Criticidade: baixa hoje (ambas sempre produzem `tipo='POLO'`, isento por D9) — sobe se algum dia o comportamento default mudar ou se alguém adicionar campo `tipo` validado a `storeEmpresa()`.
- Fonte: `133-CONTEXT.md` D-06, `133-RESEARCH.md` Pitfall 2 / Open Question 1.

## Padrões Compartilhados

### Checagem de interruptor com recusa + log (aplica a `rotear()` E `ativarEmpresaPendente()`)
**Fonte:** `app/Http/Controllers/ContratoAdminController.php::gerarContrato()` (linhas 408-428), precedente da Fase 132 para `CongelamentoEmissaoService`.
**Aplicar a:** `EmpresaOperacionalRouter::rotear()` (já usa este padrão, só falta o filtro por serviço) e `MlbController::ativarEmpresaPendente()` (checagem nova, D-03).
```php
if ($algumInterruptor->ativo() /* ou condição equivalente */) {
    Log::warning('[Administrativo] <descrição da recusa>', [
        'company_id' => $company->id,
        // demais chaves relevantes ao contexto
    ]);

    return /* recusa — formato a decidir (ver abaixo) */;
}
```

### Formato de recusa do FLUXO-09 (Claude's Discretion do CONTEXT.md — decisão a tomar no plano)
Duas opções com precedente no mesmo módulo:
- **`abort(422, '...')`** — mesmo padrão já usado 3 linhas acima no próprio método (`abort_if(MlbEmpresa::where(...)->exists(), 422, 'Esta empresa já possui um registro MLB.')`, linha 2436-2440). Vantagem: consistência local, mesma "família" de erro (empresa não pode ser ativada agora).
- **`back()->with('error', '...')`** — padrão do análogo direto (`gerarContrato()`). Vantagem: mensagem mais rica/explicativa, alinhada ao tom "sem jargão" (UI-06) exigido pelo D-04.

Recomendação: seguir o padrão LOCAL do próprio método (`abort(422, ...)`) para não introduzir uma segunda convenção de erro dentro do mesmo controller — mas registrar explicitamente no plano qual foi escolhida, porque o CONTEXT.md deixou em aberto.

### Booleano em `Configuracao` como string '1'/'0' (não muda nesta fase, só é LIDO)
**Fonte:** `EmpresaOperacionalRouter::bloqueioAtivo()` (linhas 60-63) e `CongelamentoEmissaoService::ativo()` (linhas 46-50) — mesmo padrão nos dois interruptores irmãos:
```php
public function bloqueioAtivo(): bool
{
    return Configuracao::get(self::CHAVE_BLOQUEIO, '0') === '1';
}
```
Não há UI de toggle para nenhum dos dois — ativação em produção via `php artisan tinker` (ver RESEARCH.md, seção "Padrão de ativação da chave em produção").

### Prop escalar nova em Inertia::render (D-04)
**Fonte:** `ContratoAdminController::index()` (linhas 202-203, `resumo`/`sem_contrato_count`) — booleano/valor calculado no backend, nome em `snake_case`, desestruturado no componente React com default (`Contratos.jsx:36`). Nenhuma lógica de decisão migra para o frontend.

### Log com prefixo `[Administrativo]` + `company_id`
**Fonte:** usado consistentemente em `EmpresaOperacionalRouter` (3 ocorrências) e `ContratoAdminController::gerarContrato()`. Sempre array associativo, nunca string interpolada solta. Aplicar em toda checagem nova desta fase.

## Sem Análogo Direto

Nenhum arquivo desta fase ficou sem análogo — é uma fase de "cirurgia em código existente" (RESEARCH.md), todos os pontos de extensão já têm precedente direto no mesmo repositório (Fase 128 para a consulta `exigeContrato()`, Fase 132 para o padrão de checagem+recusa+log).

## Metadados

**Escopo da busca de análogos:** `app/Services/Operacional/`, `app/Http/Controllers/ContratoAdminController.php`, `app/Http/Controllers/MlbController.php`, `app/Models/Servico.php`, `app/Models/Company.php`, `resources/js/Pages/Admin/`, `tests/Feature/Phase124KillSwitchTest.php`, `tests/Feature/Phase128/*`, `.planning/todos/pending/`
**Arquivos lidos:** 12
**Data da extração de padrões:** 2026-08-18
