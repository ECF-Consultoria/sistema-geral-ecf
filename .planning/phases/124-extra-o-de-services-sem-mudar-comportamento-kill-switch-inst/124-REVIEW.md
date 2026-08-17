---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
reviewed: 2026-08-07T00:00:00Z
depth: standard
files_reviewed: 7
files_reviewed_list:
  - app/Http/Controllers/Api/HubspotWebhookController.php
  - app/Http/Controllers/ComercialController.php
  - app/Services/Comercial/PendenciasComerciaisService.php
  - app/Services/Operacional/EmpresaOperacionalRouter.php
  - tests/Feature/Phase124KillSwitchTest.php
  - tests/Feature/Phase124RegressaoComercialTest.php
  - tests/Feature/Phase124RegressaoHubspotTest.php
findings:
  critical: 1
  warning: 10
  info: 9
  total: 20
status: issues_found
---

# Fase 124: Relatório de Code Review

**Revisado:** 2026-08-07
**Profundidade:** standard
**Arquivos revisados:** 7
**Status:** issues_found

## Sumário

A Fase 124 extraiu duas mecânicas de `ComercialController`/`HubspotWebhookController` para
`PendenciasComerciaisService` e `EmpresaOperacionalRouter`, e introduziu um interruptor de
emergência novo (`administrativo_bloqueio_ativo`).

**Equivalência comportamental da refatoração: verificada e aprovada.** Comparei o diff
(`f34a4ca8..HEAD`) linha a linha contra os corpos originais:

- Comercial: `map(servicoDisparaImplementacao) → filter → unique → values → foreach` é
  reproduzido exatamente por `rotearCadastro()` (`guardPorEmpresa: false` liga o `unique()`).
- Webhook: `if tipo === null return; if MlbEmpresa::exists() return; criar` é reproduzido por
  `rotearServico()` (`guardPorEmpresa: true`, lista de 1 elemento — o `filter()` remove o
  `null` antes do guard, mesma ordem de efeito).
- `MlbImplementacaoFactory::criarParaPolo($mlbEmp, [])` é idêntico a
  `criarParaPolo($mlbEmp)` (default do parâmetro é `[]`) — a assimetria D-02 segue preservada.
- `PendenciasComerciaisService::calcular()` é cópia literal, incluindo o early-return de
  origem HubSpot e a cache estática.

**Execução de testes (rodei de fato, não presumi):**

- `--filter Phase124` → 16/16 verdes, 65 asserções.
- `--filter "Phase37ComercialListagem|Phase114ComercialListagemEnrichment"` → 38/38 verdes
  (cobrem a extração FLUXO-03, que os arquivos de baseline da própria fase não cobrem).
- `--filter "Phase14Comercial|Phase35Hubspot|Phase113|Phase75CadastroShopee|ComercialControllerHelper"`
  → 68/69. A única falha (`Phase14ComercialTest::test_update_ignora_campos_legacy`, coluna
  legacy `service_type`) é **pré-existente e não relacionada** — toca `ComercialController::update()`,
  método que esta fase não alterou. Ver IN-09.

**Onde estão os problemas reais:** todos no código NOVO — o interruptor de emergência
(mecanismo, cobertura e observabilidade) e a documentação do service novo, que já nasceu
mentindo sobre o próprio estado. Nada foi reportado sobre as divergências congeladas de
propósito (D-02, D-08, ausência de guard em `rotearCadastro()`), que estão corretamente
preservadas e documentadas.

## Issues Críticos

### CR-01: Interruptor de emergência bloqueia em silêncio — sem registro persistido, sem sinal ao usuário, sem caminho de reconciliação

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:97-111`
(interação com `app/Http/Controllers/ComercialController.php:562-568,600` e
`app/Http/Controllers/Api/HubspotWebhookController.php:650-653`)

**Issue:** Quando `administrativo_bloqueio_ativo` está ligado, `rotear()` faz `return` cedo e o
único vestígio é um `Log::warning` no canal default contendo **apenas `company_id`**. Consequências
concretas, todas confirmadas pelos próprios testes da fase:

1. **O usuário do Comercial vê sucesso total.** `Phase124KillSwitchTest:253-275` prova que a
   `Company` e o `ContratoServico` são criados normalmente; `ComercialController::store():600`
   retorna `back()->with('success', 'Empresa "X" cadastrada com sucesso.')`. Nada na UI indica
   que o roteamento operacional foi suprimido. O operador do Comercial não tem como saber que
   aquela empresa ficou sem ficha.
2. **Não há registro persistido.** A empresa bloqueada não entra em nenhuma tabela, flag ou
   `activity()`. O log não diz nem o nome da empresa nem quais serviços foram bloqueados —
   `$nomesServicos` está em escopo na linha 106 e não é logado.
3. **Não existe reconciliação.** Ao desligar a chave, nada re-roteia as empresas que passaram
   pela janela de bloqueio. Recuperar exige cruzar `laravel.log` (canal default, rotacionado)
   com `companies`/`mlb_empresas` à mão.

O contrato desta fase é "o interruptor precisa estar **provado** antes de a Fase 133 depender
dele". O mecanismo prova que *para* de criar ficha, mas não prova que a operação consegue
**sobreviver** a ele ligado. Não afeta produção hoje (chave desligada) — **bloqueia a ativação
da Fase 133**, não o deploy da Fase 124.

**Fix:** persistir o bloqueio de forma consultável e sinalizar ao chamador. Mínimo viável:

```php
private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
{
    $tipos = collect($nomesServicos)
        ->map(fn (string $nome) => ComercialController::servicoDisparaImplementacao($nome))
        ->filter();

    if (!$guardPorEmpresa) {
        $tipos = $tipos->unique();
    }

    // Nada a rotear: não é bloqueio, não gera alarme (ver WR-03).
    if ($tipos->isEmpty()) {
        return;
    }

    if ($this->bloqueioAtivo()) {
        $nomes = collect($nomesServicos)->values()->all();

        Log::channel('ecf-webhooks')->warning(
            '[Administrativo] Roteamento operacional bloqueado pelo interruptor de emergência.',
            [
                'chave'      => self::CHAVE_BLOQUEIO,
                'company_id' => $company->id,
                'empresa'    => $company->name,
                'servicos'   => $nomes,
                'tipos'      => $tipos->values()->all(),
            ],
        );

        // Rastro consultável para a reconciliação da Fase 131/133.
        activity('administrativo')
            ->performedOn($company)
            ->withProperties(['servicos' => $nomes, 'tipos' => $tipos->values()->all()])
            ->log('Roteamento operacional bloqueado pelo interruptor de emergência');

        return;
    }

    foreach ($tipos->values() as $tipo) { /* ... */ }
}
```

Complementarmente, `ComercialController::store()` deve trocar a flash de sucesso por uma
mensagem que diga a verdade quando o roteamento foi suprimido (ex.: consultar
`$router->bloqueioAtivo()` após a transaction e emitir
`'Empresa cadastrada. Atenção: a liberação operacional está bloqueada — acione o Administrativo.'`).

## Warnings

### WR-01: Docblock do service novo afirma que ele não tem chamador — é falso desde o commit seguinte

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:30-33`

**Issue:**

> "Este service NÃO tem chamador ainda — os dois controllers continuam com o código inline de
> hoje. Religar os dois caminhos para consumir este router é escopo do plano 124-05."

Os commits `7536e5e7` e `2e7af3ed` (plano 124-05, dentro desta MESMA fase) já religaram os dois
controllers. Um mantenedor lendo este docblock conclui que produção ainda roda o código inline e
que mexer neste arquivo é inofensivo — exatamente o oposto da verdade. Doc-rot num arquivo que
tem 4 fases futuras (128/131/133) dependendo dele é risco de manutenção real, não estilo.

**Fix:** substituir por:

```php
 * Consumido por `ComercialController::store()` (cadastro manual, via
 * rotearCadastro()) e por `HubspotWebhookController::criarEmpresa()`
 * (webhook, via rotearServico()) desde a própria Fase 124 (plano 124-05).
```

### WR-02: Três referências pendentes ao método removido `rotearImplementacao()`

**Arquivos:**
- `app/Http/Controllers/Api/HubspotWebhookController.php:797`
- `app/Services/Operacional/EmpresaOperacionalRouter.php:18`
- `app/Services/Operacional/EmpresaOperacionalRouter.php:61`

**Issue:** `HubspotWebhookController::rotearImplementacao()` foi **removido** por esta fase (diff
confirma a deleção do bloco inteiro), mas segue citado como se existisse:

- Linha 797: `@return array<int, string> nomes dos servicos criados (alimenta rotearImplementacao)`
  — o `@return` de `persistirContratos()` aponta para um método inexistente; hoje alimenta
  `EmpresaOperacionalRouter::rotearServico()`.
- Linhas 18 e 61 do router descrevem a mecânica como "hoje `HubspotWebhookController::rotearImplementacao()`",
  quando ela mora no próprio arquivo que está sendo lido.

**Fix:**

```php
// HubspotWebhookController.php:797
 * @return array<int, string>           nomes dos servicos criados (alimenta EmpresaOperacionalRouter::rotearServico)
```

```php
// EmpresaOperacionalRouter.php:18 e :61 — trocar "hoje HubspotWebhookController::rotearImplementacao()"
// por "originada de HubspotWebhookController::rotearImplementacao() (removido na Fase 124)".
```

Os mesmos textos aparecem nos arquivos de teste de baseline (`Phase124RegressaoHubspotTest:18,283,338`
e `Phase124RegressaoComercialTest:171`), mas ali são **descrição histórica correta** do estado
"antes da extração" e o arquivo é baseline congelado — não devem ser alterados.

### WR-03: O interruptor é lido ANTES de resolver os tipos — gera alarme falso e trabalho inútil

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:95-123`

**Issue:** `bloqueioAtivo()` é consultado no topo de `rotear()`, antes de o `map/filter` descobrir
se algum serviço realmente dispararia ficha. Efeitos:

1. **Alarme falso.** Um cadastro só de "Publicidade"/"Gestão"/"Publicação" (que retornam `null`
   em `servicoDisparaImplementacao()` e **nunca** criariam `MlbEmpresa`) emite
   `[Administrativo] Roteamento operacional bloqueado...`. Durante um incídente com a chave
   ligada, o log fica cheio de bloqueios que nunca existiram — e o operador não tem como
   distinguir, porque o log não diz quais serviços estavam em jogo (ver CR-01).
2. **Query desnecessária.** No webhook, `rotearServico()` é chamado uma vez por serviço criado
   (`HubspotWebhookController.php:651-653`), e cada chamada faz um `SELECT` em `configuracoes`
   (`Configuracao::get` não tem cache — `app/Models/Configuracao.php:23-26`), inclusive para
   serviços que não roteiam nada.

**Fix:** resolver `$tipos` primeiro e sair cedo quando vazio, antes de consultar a chave — ver
o snippet do CR-01, que já corrige os dois pontos.

### WR-04: Interruptor de emergência falha ABERTO para qualquer valor que não seja exatamente a string `'1'`

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:54-57`

**Issue:**

```php
return Configuracao::get(self::CHAVE_BLOQUEIO, '0') === '1';
```

Comparação estrita de string. `Configuracao::set()` (`app/Models/Configuracao.php:31-33`) aceita
`mixed` e não normaliza nada. Qualquer gravação como `true`, `1` (int), `'true'`, `'on'` ou `' 1'`
resulta em `false` → **o bloqueio não acontece e ninguém percebe**, porque não há log de "chave
com valor inválido".

O padrão `=== '1'` é convenção estabelecida no projeto (`nps_envio_email_ativo`,
`email_envio_auto_ativo`), então não é desvio de estilo. A diferença que importa é a **direção da
falha**: nas outras chaves, valor errado desliga uma feature (falha segura); aqui, valor errado
**libera** o roteamento que deveria estar travado (falha insegura). E o escritor desta chave —
a tela da Fase 131 — ainda não existe, então não há garantia nenhuma de que ela vai gravar `'1'`.

**Fix:** normalizar na leitura e logar valor não reconhecido.

```php
public function bloqueioAtivo(): bool
{
    $bruto = Configuracao::get(self::CHAVE_BLOQUEIO, '0');
    $valor = mb_strtolower(trim((string) $bruto));

    if (in_array($valor, ['1', 'true', 'on', 'sim'], true)) {
        return true;
    }

    if (!in_array($valor, ['0', '', 'false', 'off', 'nao', 'não'], true)) {
        // Valor irreconhecível num interruptor de emergência: falha FECHADO.
        Log::warning('[Administrativo] Valor irreconhecível no interruptor — bloqueando por segurança.', [
            'chave' => self::CHAVE_BLOQUEIO,
            'valor' => $valor,
        ]);
        return true;
    }

    return false;
}
```

### WR-05: `EmpresaOperacionalRouter` (Service) depende de `ComercialController` (Controller) — inversão de camada introduzida pela extração

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:5,116`

**Issue:**

```php
use App\Http\Controllers\ComercialController;
// ...
->map(fn(string $nome) => ComercialController::servicoDisparaImplementacao($nome))
```

O `CLAUDE.md` define as camadas explicitamente: Services "dependem de Models e do HTTP client" e
são "usados por Controllers, Console Commands, Jobs". Um Service importando um Controller inverte
essa dependência. Antes da fase isso existia entre dois Controllers (`HubspotWebhookController` →
`ComercialController`), o que já era feio mas ficava dentro da mesma camada; a extração **moveu a
dependência para dentro da camada de Service**, tornando a regra de negócio Polos/Assessoria/Incubadora
inalcançável sem carregar um Controller HTTP.

Isto é relevante para a Fase 128 (isenção por serviço), que vai precisar consultar esta mesma regra.

**Fix:** mover o helper para o próprio `EmpresaOperacionalRouter` (ou para um `Support`/`Enum`
dedicado) e deixar `ComercialController::servicoDisparaImplementacao()` como proxy fino, para
não quebrar `tests/Unit/ComercialControllerHelperTest.php` nem `Phase75CadastroShopeeTest:160`:

```php
// EmpresaOperacionalRouter.php
public static function tipoDeFicha(string $nome): ?string
{
    return match (true) {
        str_contains($nome, 'Polos')      => 'polos',
        str_contains($nome, 'Assessoria') => 'assessoria',
        str_contains($nome, 'Incubadora') => 'incubadora',
        default                           => null,
    };
}

// ComercialController.php — proxy de compatibilidade
public static function servicoDisparaImplementacao(string $nome): ?string
{
    return EmpresaOperacionalRouter::tipoDeFicha($nome);
}
```

### WR-06: `PendenciasComerciaisService::calcular()` tem pré-condições implícitas que fazem um consumidor novo receber `[]` em silêncio

**Arquivo:** `app/Services/Comercial/PendenciasComerciaisService.php:40-47,79,143`

**Issue:** o método depende de três coisas que o chamador precisa preparar e que **não estão na
assinatura nem são verificadas**:

1. `$c->is_origem_hubspot` (linha 42) — confirmei que **NÃO é accessor nem `$appends` do model
   `Company`**: é atributo dinâmico atribuído em `ComercialController::listagem():242`, uma linha
   antes da chamada. Se qualquer outro consumidor chamar `calcular($company)` sem setá-lo,
   Eloquent devolve `null`, `!null` é `true` e o método **retorna `[]` silenciosamente** — "esta
   empresa não tem pendência nenhuma", que é a resposta errada mais perigosa possível para uma
   tela de pendências.
2. `$c->contratosServico` (linha 47) e `$c->hubspotEventos` (linhas 79 e 143) precisam estar
   eager-loaded — o comentário da linha 138 ("sem query nova — mesma coleção usada em
   servico_nao_reconhecido") assume o eager-load feito em `listagem():206-214`.

Enquanto era método `private` duas linhas abaixo da atribuição, a pré-condição era autoevidente.
O docblock do próprio service (linhas 17-19) anuncia que ele "passa a ser consumido por
Comercial, HubSpot e, a partir da Fase 131, pela tela do Administrativo" — ou seja, o acoplamento
oculto vai ser exercitado por quem não escreveu o código.

**Fix:** tornar a pré-condição explícita e auto-suficiente, sem mudar o comportamento observável
do chamador atual:

```php
public function calcular(Company $c): array
{
    // Auto-suficiente: se o chamador não anotou a flag, resolve pela relação.
    $origemHubspot = $c->is_origem_hubspot
        ?? (bool) ($c->hubspot_evento_origem_exists ?? $c->hubspotEventoOrigem()->exists());

    if (!$origemHubspot) {
        return [];
    }

    $c->loadMissing(['contratosServico.servico', 'hubspotEventos']);
    // ...
}
```

(Se a fase preferir congelar 100% do corpo, o mínimo aceitável é um `@param` no docblock
declarando que `is_origem_hubspot`, `contratosServico` e `hubspotEventos` precisam estar
preenchidos pelo chamador.)

### WR-07: A cache estática `$matchCache` deixou de ter vida de-uma-request ao virar service

**Arquivo:** `app/Services/Comercial/PendenciasComerciaisService.php:67-77`

**Issue:** `static $matchCache = []` dentro de um método é ligado ao **método/classe**, não à
instância nem ao container — persiste por toda a vida do processo PHP. Enquanto era método
`private` de `ComercialController`, o único caminho de execução era HTTP (`listagem()`), então
"vida do processo" ≡ "vida da request e a cache era descartada. Agora é um service público,
resolvível por qualquer processo, e o docblock já prevê consumidores novos (Fase 131).

Num contexto de vida longa (`php artisan queue:work`, comando com laço, Octane), um
`HubspotLineItemMapping` cadastrado durante a execução **nunca** é visto: `paraNome()` já foi
resolvido como `false` para aquele nome e o resultado fica cravado até o processo morrer. O
efeito visível é a pendência `servico_nao_reconhecido` persistindo depois de resolvida.

Registro que a mudança da cache está **explicitamente adiada** para a Fase 128 (A4) pelo docblock
das linhas 13-15 — o que está sendo reportado aqui não é a cache em si, e sim a **ampliação de
exposição** que a extração produziu e que a fase não avaliou.

**Fix:** trocar a `static` de escopo de método por propriedade de instância, o que restaura a
vida "uma resolução do container" sem alterar o resultado de nenhum caminho atual:

```php
/** @var array<string, bool> */
private array $matchCache = [];

private function nomeResolve(string $nome): bool
{
    $key = mb_strtolower(trim($nome));
    if ($key === '') {
        return true;
    }
    return $this->matchCache[$key] ??= (bool) HubspotLineItemMapping::paraNome($nome);
}
```

### WR-08: Log do bloqueio sai no canal default, divergindo do canal do fluxo que ele interrompe

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:10,106`

**Issue:** o router usa `Log::warning(...)` (canal default → `laravel.log`), enquanto o fluxo
webhook inteiro loga em `Log::channel('ecf-webhooks')` (`HubspotWebhookController` — linhas 166,
213, 229, 249, 269, 823, 865, 905, 972, 985, 992, 1017). Um bloqueio disparado pelo webhook fica
num arquivo diferente de todo o resto do rastro daquele evento, o que quebra a correlação
justamente no cenário em que a correlação importa (incidente com a chave ligada).

**Fix:** logar em `ecf-webhooks` (canal já configurado, mesmo destino usado pelo lado HubSpot) ou
criar um canal `administrativo` dedicado e usá-lo consistentemente nas Fases 128/131/133. Ver o
snippet do CR-01, que já aplica o canal e enriquece o contexto.

### WR-09: O interruptor não cobre `MlbController::ativarEmpresaPendente()`, que cria a mesma ficha pelo caminho manual

**Arquivos:** `app/Services/Operacional/EmpresaOperacionalRouter.php:12-14` (afirmação do docblock)
e `app/Http/Controllers/MlbController.php:2432-2487` (caminho descoberto)

**Issue:** o docblock do service se apresenta como

> "o lugar único que sabe transformar 'serviço contratado' em ficha de operação (`MlbEmpresa` +
> `MlbImplementacao`)"

Isso é falso. `MlbController::ativarEmpresaPendente()` faz exatamente a mesma transformação —
"Ativa uma company pendente com tipo 'publicacao' como POLO ou Assessoria. Chamado pelo time de
Publicação ao receber uma empresa cadastrada pelo Comercial" — criando `MlbEmpresa` (linhas 2448
e 2475) e `MlbImplementacao` (linha 2469), com uma **cópia inline** da lógica de
`MlbImplementacaoFactory::criarParaPolo()` (linhas 2458-2473 replicam `tutorial_intro`,
`tutoriais`, `links_admin_extra` e `Str::random(48)`).

Consequência para REDE-01: com a chave ligada, o time de Publicação continua conseguindo liberar
a operação de uma empresa pela tela `/mlb/empresas`. O interruptor cobre os dois caminhos
**automáticos**, não o caminho **manual** — e é o manual que um humano usa quando percebe que "a
empresa não apareceu". Isso precisa estar decidido antes da Fase 133, não descoberto durante ela.

**Fix:** (a) corrigir o docblock para "o lugar único do roteamento **automático**"; e (b) abrir
decisão explícita para a Fase 131/133 sobre `ativarEmpresaPendente()` — no mínimo consultar
`app(EmpresaOperacionalRouter::class)->bloqueioAtivo()` e devolver
`abort(423, 'Liberação operacional bloqueada pelo Administrativo.')`, e trocar as linhas 2458-2473
por `MlbImplementacaoFactory::criarParaPolo($empresa)` para eliminar a duplicação.

### WR-10: Testes desreferenciam `->first()` sem guard e contêm asserção vazia

**Arquivos:**
- `tests/Feature/Phase124RegressaoComercialTest.php:113-118` e `:203-211`
- `tests/Feature/Phase124KillSwitchTest.php:269-270`

**Issue:**

1. `Phase124RegressaoComercialTest:113-114`:
   ```php
   $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
   $impl   = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
   ```
   Se o roteamento regredir e não criar a `MlbEmpresa`, o teste morre com
   `Attempt to read property "id" on null` em vez de uma falha de asserção legível. Este arquivo
   é o **baseline de regressão da fase** — é exatamente o arquivo em que a mensagem de falha
   precisa apontar o dedo para a causa. Mesmo padrão em `:203-211` (`firstWhere('tipo','POLO')`
   e `firstWhere('tipo','ASSESSORIA')` desreferenciados direto), embora ali o `assertCount(2, ...)`
   anterior mitigue parcialmente.
2. `Phase124KillSwitchTest:269-270`:
   ```php
   $company = Company::where('name', '...')->firstOrFail();
   $this->assertNotNull($company, 'O cadastro comercial não quebra — a Company é criada normalmente');
   ```
   `firstOrFail()` já garante não-nulo — a asserção nunca pode falhar. Asserção morta que infla
   a contagem e dá falsa sensação de cobertura.

**Fix:**

```php
// Phase124RegressaoComercialTest.php:113
$mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
$this->assertNotNull($mlbEmp, 'MlbEmpresa POLO deveria ter sido criada');
$impl = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
$this->assertNotNull($impl, 'MlbImplementacao deveria ter sido criada para POLO');
```

```php
// Phase124KillSwitchTest.php:269 — trocar firstOrFail+assertNotNull por asserção com significado
$this->assertSame(
    1,
    Company::where('name', 'Empresa Teste Interruptor Ligado Comercial')->count(),
    'O cadastro comercial não quebra — a Company é criada normalmente',
);
$company = Company::where('name', 'Empresa Teste Interruptor Ligado Comercial')->firstOrFail();
```

## Info

### IN-01: Import não utilizado em `ComercialController`

**Arquivo:** `app/Http/Controllers/ComercialController.php:8`
**Issue:** `use App\Models\HubspotEvento;` — a classe não é referenciada em lugar nenhum do
arquivo (só aparece em comentários nas linhas 460, 486 e 564 do arquivo original). Confirmei que
já estava sem uso em `f34a4ca8`, ou seja é **pré-existente**; mas a fase removeu justamente o
método que dava a impressão de justificá-lo, então era a hora natural de limpar.
**Fix:** remover a linha 8.

### IN-02: Parâmetro `$handoff` de `rotearServico()` é morto

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:65`
**Issue:** `rotearServico(Company $company, string $nomeServico, array $handoff = [])` — o único
chamador (`HubspotWebhookController:652`) nunca passa o terceiro argumento, e o docblock do teste
`Phase124RegressaoHubspotTest:236-247` documenta que o webhook **nunca** vai preencher
`gmail_colaborador` (assimetria D-02, congelada). Parâmetro sem consumidor previsto.
**Fix:** manter se a Fase 128 já prevê uso; caso contrário, remover e simplificar a assinatura.

### IN-03: `return` onde a semântica pedida é `continue`

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:126-128`
**Issue:** o guard dentro do `foreach` faz `return`, abortando o laço inteiro em vez de pular o
tipo. Só é inofensivo porque `guardPorEmpresa: true` é usado exclusivamente por `rotearServico()`,
que sempre passa uma lista de 1 elemento. Se alguém no futuro chamar `rotear(..., guardPorEmpresa: true)`
com múltiplos tipos, o comportamento silenciosamente diverge do esperado.
**Fix:** trocar por `continue;` (equivalente hoje, correto amanhã) ou anotar a invariante
"`guardPorEmpresa` só é usado com lista unitária" no docblock de `rotear()`.

### IN-04: Duplicação de ~50 linhas de helpers de webhook entre dois arquivos de teste

**Arquivos:** `tests/Feature/Phase124KillSwitchTest.php:99-156` e
`tests/Feature/Phase124RegressaoHubspotTest.php:68-189`
**Issue:** `assinatura*`, `servidor*`, `evento*Padrao`, `disparaWebhook*` e `mockaHubSpot` são
cópias quase idênticas. O próprio docblock (`Phase124KillSwitchTest:98-100`) declara isso como
convenção do projeto ("cada arquivo de teste de webhook tem sua própria cópia, sem trait
compartilhada"), então não é violação — é dívida conhecida. Uma mudança no cálculo do HMAC v3
exigirá editar N arquivos.
**Fix:** avaliar um `Tests\Concerns\FakeHubspotWebhook` quando o número de cópias passar de 3.

### IN-05: Literal da chave hardcoded no arquivo de baseline

**Arquivo:** `tests/Feature/Phase124RegressaoComercialTest.php:232`
**Issue:** `Configuracao::set('administrativo_bloqueio_ativo', '0')` usa o literal em vez de
`EmpresaOperacionalRouter::CHAVE_BLOQUEIO`. Justificável (o arquivo é baseline congelado e a
constante não existia quando ele foi escrito), mas é uma quarta ponta a atualizar caso a chave
mude — as outras três estão listadas no docblock da constante (linhas 38-42).
**Fix:** ao descongelar o baseline (Fase 128+), trocar pelo `::CHAVE_BLOQUEIO`.

### IN-06: Docblocks truncados em `ComercialController`

**Arquivo:** `app/Http/Controllers/ComercialController.php:45-46` e `:789-790`
**Issue:** `"Helper PURO — testável sem precisar do container Laravel. Substitui o"` e
`"Mapeia o NOME de um serviço (catálogo Frente A) para o slug do setor"` terminam no meio da
frase. Pré-existente, não introduzido por esta fase.
**Fix:** completar as frases (`"...Substitui o enum legacy service_type."` /
`"...para o slug do setor que deve ser notificado."`).

### IN-07: Uma query em `configuracoes` por serviço roteado

**Arquivo:** `app/Services/Operacional/EmpresaOperacionalRouter.php:56` +
`app/Http/Controllers/Api/HubspotWebhookController.php:651-653`
**Issue:** `Configuracao::get()` não tem cache (`app/Models/Configuracao.php:23-26`) e
`bloqueioAtivo()` roda a cada `rotearServico()`, ou seja uma vez por serviço criado, dentro da
`DB::transaction` do webhook. Anotado como contexto de correção do WR-03 (o early-return por
`$tipos` vazio já elimina a maioria dos casos); performance pura está fora do escopo desta review.
**Fix:** resolver junto do WR-03; opcionalmente memoizar em propriedade de instância do router.

### IN-08: Os arquivos de baseline da fase não cobrem a extração FLUXO-03

**Arquivos:** `tests/Feature/Phase124RegressaoComercialTest.php`,
`tests/Feature/Phase124RegressaoHubspotTest.php`
**Issue:** os dois arquivos de caracterização cobrem só o roteamento (FLUXO-04/05). A extração de
`PendenciasComerciaisService` (plano 124-03) não tem nenhum teste próprio na fase — a prova
declarada foi "diff nominal vazio" (commit `0cbe8c38`). Verifiquei na prática que a cobertura
existe fora da fase: `Phase37ComercialListagemTest` e `Phase114ComercialListagemEnrichmentTest`
exercitam as 7 pendências e passaram (38/38). Registro apenas para que a dependência dessas duas
suítes seja consciente — se elas forem tocadas, a rede de segurança do FLUXO-03 some.
**Fix:** citar as duas suítes como cobertura do FLUXO-03 no summary da fase.

### IN-09: Falha pré-existente e não relacionada na suíte adjacente

**Arquivo:** `tests/Feature/Phase14ComercialTest.php:322` (fora do escopo da fase)
**Issue:** `test_update_ignora_campos_legacy` falha com
`"service_type legacy deve manter o valor anterior — null does not match expected type array"`.
Toca `ComercialController::update()`, método **não alterado** por esta fase, e a causa é a coluna
legacy `service_type` (droppada no Plan 14-06). Não é regressão da Fase 124 — registro para que
não seja atribuída a ela.
**Fix:** abrir quick task para atualizar ou remover o teste legacy.

---

_Revisado: 2026-08-07_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
