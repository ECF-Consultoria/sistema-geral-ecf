# Phase 133: Liga o bloqueio — ativação real (v22.0) - Research

**Researched:** 2026-08-18
**Domain:** Roteamento operacional condicional por serviço (Laravel), rede de segurança de um kill switch já existente, exposição de estado numa tela Inertia já existente
**Confidence:** HIGH (todos os achados abaixo vêm de leitura direta do código em produção neste repositório, execução real de teste e reconsulta ao banco local — não há dependência de documentação externa nesta fase)

## Summary

Esta fase não introduz tecnologia nova nem pacote novo — é cirurgia em código já
existente. O trabalho central é abrir uma exceção **por serviço** dentro de
`EmpresaOperacionalRouter::rotear()` (hoje um bloqueio total, comentário
apontando o ponto de extensão), fechar uma segunda cópia da mesma regra dentro
de `MlbController::ativarEmpresaPendente()` (FLUXO-09, a "porta dos fundos"),
adicionar uma faixa informativa numa tela React que já existe
(`ContratoAdminController::index()` → `Admin/Contratos.jsx`), e então ligar a
chave em produção com prova por reconsulta ao banco.

A pesquisa desta fase focou no que o CONTEXT.md pediu explicitamente para não
redescobrir (os três caminhos de entrada já medidos) e foi atrás do que faltava
saber para planejar com segurança. O achado mais importante: **existem duas
portas adicionais** que criam `MlbEmpresa` sem passar pelo router e sem vínculo
com `Company` — `MlbImplementacaoController::criar()` e
`MlbController::storeEmpresa()`. Nenhuma das duas é uma regressão desta fase
nem precisa necessariamente entrar no escopo (ver `## Open Questions`), mas o
usuário perguntou explicitamente se existe uma "quarta porta" e a resposta
honesta é sim — com uma ressalva importante: as duas sempre criam registros
`tipo='POLO'` (isento por D9), então hoje não violam a regra de negócio, mas
também nunca foram gate-adas.

O segundo achado de alto valor: **4 testes existentes vão quebrar** com a
implementação da exceção por serviço, porque testam
"bloqueio ligado + Polos" e afirmam que nada nasce — comportamento que a
própria D-02 desta fase muda de propósito. Estão nominalmente listados abaixo.

**Recomendação primária:** implementar a exceção como um filtro em lote (uma
única query `whereIn`) dentro de `rotear()`, nunca por nome de serviço
hardcoded; em `ativarEmpresaPendente()`, resolver a decisão a partir do
serviço realmente contratado pela empresa (`contratosServico`), não apenas do
`tipo` escolhido a mão no formulário; e atualizar os 4 testes nominalmente
listados na mesma tarefa que muda `rotear()`, para que a suíte nunca fique
vermelha entre commits.

## User Constraints

<user_constraints>
### Locked Decisions

- **D-01: Entregar a trava pronta e provada, aceitando que o efeito prático hoje é nulo.**
  Medido em produção: das 9 famílias de serviço, só Polos, Assessoria e Incubadora geram ficha
  operacional; Polos é isento de contrato (D9), e **não existe nenhuma ficha de Assessoria ou
  Incubadora na base**. Logo, com a chave ligada, nada é retido hoje. A decisão consciente do
  usuário é entregar a trava correta e provada por teste, para quando essas empresas entrarem —
  **não** inventar roteamento novo para forçar efeito.

- **D-02: A exceção decide POR SERVIÇO, dentro do laço — não por empresa.**
  Para cada serviço da empresa, consultar `Servico::exigeContrato()`. Uma empresa com Polos +
  Assessoria tem o **Polos roteado** e a Assessoria retida. A alternativa (segurar a empresa
  inteira se qualquer serviço exigir contrato) foi **rejeitada**: prenderia Polos por causa de
  outro serviço, contrariando o SC 2b.

- **D-03: Fechar por checagem dentro do próprio `MlbController::ativarEmpresaPendente()`**,
  sem refatorar o método para passar pelo `EmpresaOperacionalRouter`.
  Motivo: o router deriva o tipo do **nome do serviço**, enquanto esse método recebe o tipo
  **escolhido a mão** por quem clica (`$validated['tipo']`) — encaixar um no outro exigiria
  mexer num fluxo em uso. A opção cirúrgica foi preferida.
  ⚠️ **Consequência aceita:** a regra passa a existir em **dois lugares**. O plano DEVE incluir
  teste nos dois caminhos — senão a próxima mudança conserta um e esquece o outro.

- **D-04: Faixa na tela `/administrativo/contratos` (Fase 131) quando a chave está ligada.**
  A "lista de empresas retidas" **já existe**: `ContratoAdminController::index()` lista empresas
  ativas com serviço que exige contrato, com status por par empresa+serviço, dias parado e causa.
  O que falta é a tela **contar a consequência** — que enquanto o contrato não for assinado a
  empresa não entra na operação. A faixa some quando a chave está desligada.
  ⛔ **Não criar tela nova nem lista nova.**
  Linguagem: sem jargão (UI-06) — nada de "flag", "roteamento", "ficha operacional".

- **D-05: Ligar em produção e conferir o próximo cadastro real de Polos**, por reconsulta ao
  banco (nunca pela tela). Ficha nascendo com a chave ligada = exceção provada. Ficha não
  nascendo = **desligar na hora** — a própria chave é a saída, nunca rollback de código.
  Nada de empresa de teste: o usuário optou por esperar o cadastro real.

### Claude's Discretion

- Granularidade da faixa de aviso (texto exato, posição na tela).
- Como a checagem do FLUXO-09 devolve o erro (flash, 403, mensagem) — desde que sem jargão.
- Estrutura dos testes, desde que cubram os **dois** caminhos da D-03 e o caso
  Polos-com-chave-ligada.

### Deferred Ideas (OUT OF SCOPE)

- **Roteamento operacional para os outros seis serviços** (Gestão, Mentoria, Publicação,
  Implantação, Publicidade, Gestão de ADS Shopee). Hoje eles exigem contrato mas nunca geram
  ficha — são acompanhados por Carteira/Desempenho, que leem `companies` direto. Se um dia se
  quiser que o contrato trave alguma coisa para eles, primeiro é preciso decidir **o que** ele
  trava. Capacidade nova, fase própria.
- **Bloqueio retroativo** para empresas já cadastradas sem contrato — não discutido, não incluído.
- **Marca por linha na tela** distinguindo "retida" de "isenta" (Polos) — o usuário escolheu só a
  faixa; a marca por linha fica para quando houver retenção de verdade.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| FLUXO-01 | Empresa criada pelo webhook HubSpot deixa de ser roteada automaticamente ao operacional — exceto serviços isentos (D9) | Confirmado que `HubspotWebhookController` (linha ~666) já passa por `EmpresaOperacionalRouter::rotearServico()`; a exceção precisa nascer dentro de `rotear()` (única leitura do interruptor, D-05 da Fase 124). `$nomeServico` vem de `$servicosCriados`, nomes que já correspondem a `Servico.nome` existentes no catálogo (via `persistirContratos()`/`HubspotDealHandoffService`) — baixo risco de nome órfão. |
| FLUXO-02 | Empresa cadastrada à mão pelo Comercial segue o mesmo caminho, sem porta dos fundos | Confirmado que `ComercialController::store()` (linha ~627) já passa por `rotearCadastro($company, $servicosCriados->pluck('nome'), ...)`. Mesma correção em `rotear()` cobre os dois FLUXO-01/02 simultaneamente — é o mesmo método privado. |
| FLUXO-09 | `MlbController::ativarEmpresaPendente()` respeita o mesmo bloqueio, sem porta dos fundos | Método mapeado linha a linha (ver `## Architecture Patterns`); hoje ignora `bloqueioAtivo()` totalmente. `$validated['tipo']` é `in:polos,assessoria` — **nunca inclui `incubadora`**. Fonte de verdade recomendada: os serviços realmente contratados da empresa (`$company->contratosServico`), não o rótulo escolhido no formulário — ver `## Open Questions`. |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Decisão "este serviço exige contrato?" | API/Backend (`Servico::exigeContrato()`) | Database (`servicos.exige_contrato`) | Dado configurável já existe desde a Fase 128 (FLUXO-08); esta fase só passa a CONSULTAR o dado no ponto certo, não cria mecanismo novo |
| Exceção por serviço dentro do roteamento | API/Backend (`EmpresaOperacionalRouter::rotear()`) | — | Único ponto de leitura do interruptor (D-05 da Fase 124); toda lógica de decisão deve viver aqui, nunca duplicada nos controllers (exceto a cópia deliberada da D-03) |
| Fechamento da porta dos fundos (FLUXO-09) | API/Backend (`MlbController::ativarEmpresaPendente()`) | — | Checagem cirúrgica *dentro* do método, por decisão D-03 — não refatorado para usar o router |
| Faixa de aviso na tela | Frontend Server (Inertia — `ContratoAdminController::index()`) → Browser (`Admin/Contratos.jsx`) | — | Booleano novo calculado no backend e passado como prop; nenhuma lógica de decisão no React (padrão já usado em `resumo`/`sem_contrato_count`) |
| Ativação da chave em produção | API/Backend (`Configuracao::set()` via `php artisan tinker`) | — | Não existe UI de toggle para `administrativo_bloqueio_ativo` (nem para `CongelamentoEmissaoService` da Fase 132) — o padrão estabelecido no projeto é `tinker` via SSH/plink no VPS, não uma tela |

## Standard Stack

Esta fase **não introduz dependência nova**. Todo o trabalho é código PHP/Laravel
e React/Inertia já presentes no projeto (Laravel 12, Eloquent, Inertia 2,
React 18 — ver CLAUDE.md). Nenhuma instalação de pacote, portanto:

- **Package Legitimacy Audit:** N/A — nenhum pacote novo é instalado nesta fase.

## Architecture Patterns

### Fluxo de decisão (estado atual → estado alvo)

```
                    ┌─────────────────────────────────────────┐
                    │  3 caminhos de entrada (todos já medidos) │
                    │  • HubspotWebhookController (~666)        │
                    │  • ComercialController::store() (~627)    │
                    │  • MlbController::ativarEmpresaPendente() │
                    └───────────────┬───────────────────────────┘
                                    │
                     2 primeiros chamam rotearServico()/rotearCadastro()
                     o 3º NÃO chama nada disso hoje (porta dos fundos)
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │ EmpresaOperacionalRouter::rotear() │   ← ÚNICO ponto que lê
                    │                                 │      bloqueioAtivo()
                    │  if (bloqueioAtivo()) {          │
                    │      ❌ HOJE: return (bloqueia    │
                    │         TUDO, inclusive Polos)   │
                    │      ✅ ALVO: filtra por serviço  │
                    │         via Servico::exigeContrato()│
                    │  }                                │
                    │  aplicarRoteamento(...)           │
                    └───────────────┬───────────────────┘
                                    │
                                    ▼
                    criarFicha() → MlbEmpresa + (se polos) MlbImplementacao
```

**Ponto de extensão exato** (comentário já existe em
`app/Services/Operacional/EmpresaOperacionalRouter.php:106-125`, método
privado `rotear()`): a checagem `if ($this->bloqueioAtivo())` hoje termina em
`return` incondicional. A implementação da D-02 substitui esse `return` por um
filtro que separa os nomes de serviço ISENTOS (routeáveis mesmo com a chave
ligada) dos que EXIGEM contrato (retidos).

### Recomendação de implementação — filtro em lote, sem N+1

`rotear()` recebe `iterable $nomesServicos` como **strings** (nomes do
catálogo, não IDs — ver `rotearServico(Company, string $nomeServico)` e
`rotearCadastro(Company, iterable $nomesServicos)`). Hoje a resolução
`nome → tipo` (`ComercialController::servicoDisparaImplementacao()`) é pura,
sem tocar o banco. Introduzir `exigeContrato()` exige uma consulta — o
**risco de N+1** é real se cada nome for consultado individualmente dentro do
loop. `servicos.nome` **não tem constraint UNIQUE** no schema (verificado na
migration `2026_05_26_120001_create_servicos_table.php`), mas em produção os
10 registros atuais têm nomes distintos — ainda assim, a consulta deve ser
tolerante a duplicidade teórica (`pluck` sobre `whereIn` já lida com isso
naturalmente).

```php
// Source: leitura direta de app/Services/Operacional/EmpresaOperacionalRouter.php
// e app/Models/Servico.php (linhas 182-189, 244-257) — código deste repositório
private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
{
    if ($this->bloqueioAtivo()) {
        $nomes = collect($nomesServicos)->values();

        // Uma única query — nunca dentro de foreach. Nomes que não batem
        // contra nenhum Servico ficam FORA de $isentos e são tratados como
        // "exige contrato" por padrão (fail-safe, mesmo espírito do
        // default(true) da migration 2026_08_13_100001).
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

        // Só os isentos passam — o resto da mecânica (guard/lock/dedup) é
        // idêntica, não muda.
        $this->aplicarRoteamento($company, $liberados, $handoff, $guardPorEmpresa);
        return;
    }

    $this->aplicarRoteamento($company, $nomesServicos, $handoff, $guardPorEmpresa);
}
```

**Por que fail-safe (nome não encontrado = tratado como exige contrato):**
a própria migration `2026_08_13_100001_add_exige_contrato_to_servicos_table.php`
documenta essa escolha para o `default(true)` da coluna — "uma eventual
divergência de grafia é inofensiva: o serviço continua exigindo contrato".
A mesma filosofia deve se aplicar aqui: **nunca inferir isenção por ausência
de dado.**

### FLUXO-09 — fonte da verdade para a decisão manual

`MlbController::ativarEmpresaPendente()` (linhas 2432-2487) recebe
`$validated['tipo']` como **`required|in:polos,assessoria`** — escolhido a mão
por quem clica no modal, **não** derivado dos serviços realmente contratados
pela `Company`. Isso é diferente do router, que sempre deriva do nome real do
serviço contratado.

```php
// Source: app/Http/Controllers/MlbController.php:2432-2487 (código atual)
public function ativarEmpresaPendente(Request $request, Company $company)
{
    $this->checkPubAccess('empresas');
    abort_if(MlbEmpresa::where('company_id', $company->id)->exists(), 422, '...');
    $validated = $request->validate(['tipo' => 'required|in:polos,assessoria']);
    // hoje: nenhuma leitura de bloqueioAtivo() nem de exigeContrato() aqui
    DB::transaction(function () use ($company, $validated, $request) { /* cria MlbEmpresa direto */ });
}
```

A empresa já tem, disponível no mesmo controller, a lista de serviços
REALMENTE contratados — o próprio `empresas()` (linhas 2393-2408) já consulta
isso para montar `$empresasPendentes`:

```php
// Source: app/Http/Controllers/MlbController.php:2393-2408 (padrão já usado
// no mesmo controller, mesma classe de dado)
$e->contratosServico->where('ativo', true)->pluck('servico.nome')->filter();
```

**Recomendação:** a checagem do FLUXO-09 deve usar essa MESMA fonte — os
serviços ativos e contratados da empresa — em vez de confiar cegamente no
rótulo `$validated['tipo']`. Isso evita reintroduzir o anti-padrão que
`Servico::exigeContrato()` foi desenhado para eliminar (ver docblock do
método: *"Nenhum `if ($servico->nome === 'Polos')`... em lugar nenhum do
código"*). Concretamente: resolver o(s) `Servico` contratado(s) da empresa
cujo `servicoDisparaImplementacao()` bate com o `tipo` pedido, e checar
`exigeContrato()` neles — se nenhum serviço contratado bater, ou se o que
bater exigir contrato, bloquear com `bloqueioAtivo()` ligado. Como `tipo` só
aceita `polos`/`assessoria` (nunca `incubadora`), e Polos é sempre isento por
D9, na prática só o ramo `assessoria` precisa da checagem condicional — mas
resolvê-la a partir do dado, não do nome, mantém a mesma disciplina do resto
da fase.

### Padrão de exposição do booleano na tela (D-04)

`ContratoAdminController::index()` (linhas 57-201) já retorna props escalares
simples ao lado da paginação — `resumo` (array) e `sem_contrato_count` (int).
O padrão para a faixa nova é o mesmo: um booleano calculado no backend.

```php
// Source: padrão existente em app/Http/Controllers/ContratoAdminController.php:191-200
return Inertia::render('Admin/Contratos', [
    'linhas'             => $paginator,
    'filters'            => ['situacao' => $situacao, 'q' => $q],
    'resumo'             => $resumo,
    'sem_contrato_count' => $semContratoCount,
    // NOVO (D-04):
    'bloqueio_ativo'     => app(EmpresaOperacionalRouter::class)->bloqueioAtivo(),
]);
```

No frontend, o estilo de faixa informativa (âmbar, não vermelho — "canal
neutro", conforme comentário já existente no código-irmão) tem precedente
exato em `resources/js/Pages/Admin/ContratoDetalhe.jsx:238-245`:

```jsx
// Source: resources/js/Pages/Admin/ContratoDetalhe.jsx:240-244 (padrão visual
// já usado no mesmo módulo — CLICK-07, "canal neutro/âmbar, nunca vermelho")
<div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
    <p className="font-semibold">...título sem jargão...</p>
    <p className="mt-0.5 text-amber-300/80">...explicação sem jargão...</p>
</div>
```

Posição recomendada em `Admin/Contratos.jsx`: logo após o `<h1>` (linha ~68) e
antes do grid de resumo por situação (linha ~75) — é o primeiro coisa que o
Administrativo vê ao abrir a tela.

### Padrão de ativação da chave em produção (D-05, sem UI de toggle)

Não existe controller nem rota que chame `Configuracao::set()` para
`administrativo_bloqueio_ativo` — nem para o interruptor irmão da Fase 132
(`CongelamentoEmissaoService`). O padrão estabelecido no projeto (usado
extensivamente nos planos 132-01/02/03) é `php artisan tinker` via SSH/plink
no VPS, nunca uma tela:

```bash
# Ligar (produção) — mesmo padrão usado na Fase 132 para CongelamentoEmissaoService
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');"

# Conferir por reconsulta (nunca pela tela, D-05)
php artisan tinker --execute="echo app(App\Services\Operacional\EmpresaOperacionalRouter::class)->bloqueioAtivo() ? 'ligado' : 'desligado';"

# Desligar (saída de emergência — D-05: a chave É a saída, nunca rollback de código)
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');"
```

Localmente (ambiente sem PATH configurado, conforme CLAUDE.md/aviso do
orquestrador): `C:\xampp\php\php.exe artisan tinker --execute="..."`.

**Opcional (Claude's Discretion):** adicionar métodos `ligar()`/`desligar()`
ao `EmpresaOperacionalRouter` espelhando `CongelamentoEmissaoService::ligar()/
desligar()/ativo()` deixaria o comando de tinker mais curto e simétrico ao
padrão da Fase 132 — não é obrigatório, já que `Configuracao::set()` genérico
funciona hoje sem mudança nenhuma (é o que os testes existentes já fazem).

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|----------|-------------|-------------------|---------|
| "Este serviço exige contrato?" | Novo `if ($nome === 'Polos')` em qualquer lugar | `Servico::exigeContrato()` / `Servico::scopeExigeContrato()` (já existem, Fase 128) | É o único ponto de leitura autorizado por design (docblock do método é explícito sobre isso); espalhar por nome reintroduz exatamente o defeito que a Fase 128 corrigiu |
| Mapear nome de serviço → tipo de ficha | Nova função de matching | `ComercialController::servicoDisparaImplementacao()` (já existe) | Já é a fonte única usada tanto pelo Comercial quanto pelo router (`aplicarRoteamento()` a chama diretamente) |
| Toggle da chave em produção | Endpoint HTTP novo / botão na UI | `Configuracao::set()` via `php artisan tinker` | Nenhum interruptor deste tipo no projeto (nem o irmão da Fase 132) tem UI — é padrão deliberado, evita expor um botão que desliga o operacional inteiro sem fricção |

## Common Pitfalls

### Pitfall 1 — Quatro testes vão quebrar silenciosamente se `rotear()` mudar sem tocá-los

**O que dá errado:** `tests/Feature/Phase124KillSwitchTest.php` tem 4 testes
que ligam a chave (`Configuracao::set(CHAVE_BLOQUEIO, '1')`) e usam
**exatamente** o serviço `'Polos'`, afirmando `MlbEmpresa::count() === 0` /
`MlbImplementacao::count() === 0`. A implementação da D-02 torna esse
resultado **falso** de propósito — Polos passa a ser roteado mesmo com a
chave ligada. Confirmado rodando a suíte hoje (7/7 passam antes da mudança):

| Teste | Linha | Por que quebra |
|---|---|---|
| `test_interruptor_ligado_impede_o_roteamento_do_cadastro` | 186-196 | `rotearCadastro($company, ['Polos'])` com chave ligada — vai passar a criar `MlbEmpresa` |
| `test_interruptor_ligado_impede_o_roteamento_por_servico` | 204-214 | `rotearServico($company, 'Polos')` idem |
| `test_interruptor_ligado_impede_o_cadastro_manual_de_criar_ficha` | 253-275 | POST `/comercial/empresas` com serviço Polos, chave ligada — vai passar a criar `MlbEmpresa` |
| `test_interruptor_ligado_impede_o_webhook_de_criar_ficha` | 286-299 | Webhook HubSpot com `servico_ecf: 'Polos'`, chave ligada — idem |

**Confirmado por que os 4 quebram especificamente com 'Polos':** o `Servico`
"Polos" criado no `setUp()` deste arquivo (linha 48-53, `firstOrCreate` sem
`exige_contrato` explícito) **herda `exige_contrato=false`** da migration
`2026_05_27_100001_seed_servicos_catalog.php` + `2026_08_13_100001_...` que
já roda no `RefreshDatabase` de todo teste — ou seja, o "Polos" de teste já é
isento hoje, exatamente como produção.

**Como evitar:** reescrever os 4 testes (ou trocar o cenário deles para um
serviço que EXIGE contrato, ex. "Assessoria", mantendo um teste NOVO e
separado que prova a exceção com "Polos") **na mesma tarefa/commit** que
altera `rotear()`. Não deixar a suíte vermelha entre passos.

**Não quebram** (verificado por leitura): `Phase124RegressaoComercialTest.php`
(usa `bloqueio_ativo='0'`, comportamento inalterado);
`tests/Feature/Phase128/InvarianteRoteamentoTest.php` (usa `'Assessoria'`, que
continua exigindo contrato, e só afirma que a chave permanece `'0'` — nunca
testa cenário "ligada"); as demais suítes de `Phase129`/`Phase130` operam
sobre `liberarEmpresa()`, que **não** passa pelo interruptor (ver docblock do
próprio método, linhas 229-237 de `EmpresaOperacionalRouter.php`) — não são
afetadas por esta mudança.

### Pitfall 2 — Existem DUAS portas adicionais além das três conhecidas (achado da pesquisa)

**O que dá errado:** o CONTEXT.md documenta três caminhos de entrada
(HubSpot, Comercial, `ativarEmpresaPendente`). A pesquisa encontrou **duas
outras rotas HTTP** que criam `MlbEmpresa` diretamente, sem passar pelo
router e sem checar `bloqueioAtivo()` nem `exigeContrato()`:

1. **`MlbImplementacaoController::criar()`** (rota `POST /mlb/implementacao`,
   nome `implementacao.criar`, linha 492-549) — cria `MlbEmpresa` com
   `tipo='POLO'` **hardcoded**, sem `company_id` (a empresa nasce só com
   `nome`, digitado a mão pelo time de Publicação; não referencia nenhuma
   `Company` do Comercial).
2. **`MlbController::storeEmpresa()`** (rota `POST /mlb/empresas`, nome
   `mlb.empresas.store`, linha 2489-2522, usada de fato pelo frontend em
   `resources/js/Pages/Mlb/Empresas.jsx:279`) — cria `MlbEmpresa` com os
   campos validados; `tipo` **não está na lista de campos validados**, então
   sempre assume o **default de schema `'POLO'`** (migration
   `2026_05_04_000004_add_tipo_to_mlb_empresas_table.php`); `company_id`
   também não é validado, então nunca é setado.

**Por que isso não é uma regressão urgente:** as duas sempre criam registros
`tipo='POLO'`, que é isento de contrato por D9 — nenhuma das duas hoje viola a
regra "contrato assinado é a porta de entrada", porque Polos nunca precisou
de contrato. E nenhuma delas está ligada a uma `Company`/`Servico` real — são
telas de acompanhamento interno de projeto do time de Publicação, não o
pipeline Comercial→Administrativo→Operacional que esta milestone constrói.

**Por que vale registrar mesmo assim:** confirma exatamente o padrão descrito
em `.planning/learnings/painel-polos-status-e-meta.md` §3 — "empresa polo" é
`MlbEmpresa`, não `Company` (só 4 de 190 `Company` têm ficha vinculada,
segundo o CONTEXT.md; 486 fichas existem no total). Essas duas rotas HTTP
(mais os comandos artisan de bootstrap único listados abaixo) são plausivelmente
a explicação primária dessa divergência — a maior parte do histórico de
`mlb_empresas` nunca passou pelo Comercial. **Esta fase, como está escopada
(FLUXO-01/02/09), não cobre essas duas rotas** — ver `## Open Questions` para
a decisão que o planner/usuário precisa tomar sobre incluir ou não.

**Comandos artisan também criam `MlbEmpresa` fora do router** —
`mlb:seed-polos-fase` (`SeedPolosFase.php`), `mlb:importar-planilha`
(`ImportarPlanilhaMLB.php`), `mlb:importar-maycon`
(`ImportarPlanilhaMaycon.php`). Todos são **bootstraps únicos** documentados
em seus próprios docblocks ("NÃO usar este comando para re-importar a
planilha em runtime"), dependem de arquivos que não estão versionados no repo
(`import_mlb.json`, `maycon_data.json`) e exigem acesso de servidor — risco
operacional baixo, mas tecnicamente fora do gate se alguém os rodar de novo.
Não requerem ação nesta fase; citados por completude da pergunta do usuário.

### Pitfall 3 — `servicos.nome` não tem UNIQUE constraint

**O que dá errado:** um `whereIn('nome', ...)` presume implicitamente que
nomes de serviço são únicos. A tabela `servicos`
(`2026_05_26_120001_create_servicos_table.php`) não declara `unique()` na
coluna `nome`. Hoje (verificado via tinker em ambiente local) os 10 registros
têm nomes distintos — inclusive um resíduo de teste manual
(`"Quick 260816-d72 Servico Teste"`, id=10) que não afeta a produção real mas
mostra que a tabela aceita duplicidade sem erro.

**Como evitar:** o filtro em lote recomendado acima (`whereIn(...)->pluck(...)`)
já é tolerante a duplicidade (não falha, apenas resolveria "isento" se
QUALQUER linha com aquele nome for isenta) — mas não depender dessa tolerância
como comportamento correto; se um nome duplicado aparecer com `exige_contrato`
divergente entre as duas linhas, o resultado seria ambíguo. Não é um risco
introduzido por esta fase, mas vale registrar para quem for planejar os
testes: não é necessário adicionar migration de unique constraint nesta fase
(fora de escopo, D-01), mas o teste da exceção deve assumir nomes únicos, que
é o estado real de produção.

### Pitfall 4 — a chave por padrão em `servicos.exige_contrato` é `true`, não `false`

**O que dá errado:** ao criar um `Servico` novo em teste ou em produção sem
setar `exige_contrato` explicitamente, o valor DEFAULT do schema é `true`
(migration `2026_08_13_100001_add_exige_contrato_to_servicos_table.php`,
linha 44). Isso é deliberado (ver comentário da própria migration) mas
significa que qualquer fixture de teste que crie um "Servico Polos" sem
depender do seed real do catálogo (`2026_05_27_100001_...` +
`2026_08_13_100001_...`) vai nascer com `exige_contrato=true` — ou seja,
**não** isento — e um teste que espera a exceção funcionando vai falhar
silenciosamente por essa razão, não por bug na implementação.

**Como evitar:** ao escrever fixtures novas para "Polos isento", usar
`Servico::firstOrCreate(['nome' => 'Polos'], [...])` **sem** sobrescrever
`exige_contrato` (deixando o seed real da migration prevalecer, como já faz
`Phase124KillSwitchTest`) OU setar `exige_contrato: false` explicitamente na
fixture. Nunca assumir que "Polos" é isento por nome — sempre confirmar que
o dado (`exige_contrato`) está correto no cenário de teste.

## Code Examples

Ver seção `## Architecture Patterns` acima — todos os exemplos desta fase são
trechos de código já existente no repositório (não há biblioteca externa a
documentar).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|------------------|
| A1 | `$servicosCriados` (nomes passados a `rotearServico()`/`rotearCadastro()`) sempre correspondem a `Servico.nome` existentes no catálogo, então o caminho "fail-safe" (nome não encontrado) raramente dispara na prática | Architecture Patterns → filtro em lote | Se houver algum caminho de handoff HubSpot que gere nome livre não catalogado, esses casos cairiam no fail-safe (retido) mesmo sem realmente exigir contrato — comportamento conservador, não perigoso, mas pode gerar retenção inesperada de um serviço que deveria ser isento. Vale um teste explícito de "nome não encontrado" |
| A2 | As duas rotas adicionais (`MlbImplementacaoController::criar()`, `MlbController::storeEmpresa()`) estão fora do escopo de FLUXO-09 porque não referenciam `Company` | Common Pitfalls → Pitfall 2 | Se o usuário considerar que "nenhuma porta dos fundos" deve incluir também rotas desconectadas de `Company`, o escopo da fase precisa crescer — decisão explícita necessária, ver Open Questions |
| A3 | Não existe UI de toggle para `administrativo_bloqueio_ativo` e o padrão aceito é `php artisan tinker` via SSH — inferido por analogia direta com o irmão `CongelamentoEmissaoService` da Fase 132, que usa exatamente esse padrão em produção | Architecture Patterns → ativação da chave | Baixo — é o único mecanismo observável no código hoje; se o usuário preferir uma tela, é uma decisão de escopo nova para a fase, não um erro de pesquisa |

**Nenhuma claim de negócio, retenção ou segurança foi assumida sem verificação
de código nesta pesquisa** — todos os achados vêm de leitura direta de
arquivo, execução de teste ou consulta ao banco local.

## Open Questions

1. **A "quarta porta" (`MlbImplementacaoController::criar()` e
   `MlbController::storeEmpresa()`) entra no escopo desta fase?**
   - O que sabemos: as duas criam `MlbEmpresa` sem passar pelo router e sem
     checar a chave; as duas SEMPRE resultam em `tipo='POLO'` (uma por
     hardcode explícito, a outra por default de schema); nenhuma tem
     `company_id`, então não há `Servico`/contrato associável de forma
     direta.
   - O que é incerto: o objetivo de negócio da fase é "contrato assinado é a
     porta de entrada do operacional" — essas duas rotas nunca tiveram
     conceito de contrato, então "fechar" essas portas seria capacidade
     nova (checar contra o quê?), não uma correção de gap.
   - Recomendação: tratar como **fora de escopo desta fase** (consistente
     com D-01 "não inventar roteamento novo" e com o boundary explícito do
     CONTEXT.md, que só cita FLUXO-01/02/09) e registrar como um todo/nota
     para decisão futura explícita do usuário — não decidir por conta
     própria durante o planejamento.

2. **A checagem do FLUXO-09 deve confiar no `$validated['tipo']` escolhido a
   mão, ou resolver o serviço realmente contratado da empresa?**
   - O que sabemos: `tipo` só aceita `polos`/`assessoria`; Polos é sempre
     isento (D9); a empresa tem `contratosServico` disponível com os
     serviços realmente ativos, já usado no mesmo controller (linha 2404).
   - O que é incerto: qual comportamento o usuário prefere se alguém marca
     "assessoria" no formulário para uma empresa cujo serviço contratado real
     é outro (dado inconsistente entre UI e banco) — bloquear sempre, ou
     confiar no clique?
   - Recomendação: dado que a Fase 128 estabeleceu como princípio "quem
     decide é o dado, não o nome escolhido na tela", a checagem deveria
     preferir o dado contratado. Mas como isso é uma nuance nova (a D-03 do
     CONTEXT só fala em "checagem dentro do método", sem especificar a
     fonte), sinalizar esta escolha explicitamente no discuss-phase ou deixar
     como Claude's Discretion documentada no plano.

## Environment Availability

Não aplicável — esta fase não depende de nenhum serviço externo, biblioteca
nova, runtime adicional nem infraestrutura além do que o projeto já usa
(Laravel + MySQL/SQLite + PHP, todos já configurados e em uso pelas fases
124-132 desta mesma milestone). Único cuidado operacional herdado do
ambiente: **PHP não está no PATH** — usar `C:\xampp\php\php.exe` para
qualquer comando `artisan`/`tinker` local.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config em `phpunit.xml` |
| Config file | `phpunit.xml` (testsuites `Unit` → `tests/Unit`, `Feature` → `tests/Feature`) |
| Quick run command | `C:\xampp\php\php.exe artisan test tests/Feature/Phase124KillSwitchTest.php tests/Feature/Phase128/InvarianteRoteamentoTest.php` (ou o(s) arquivo(s) novo(s) da Fase 133) |
| Full suite command | ⚠️ **NÃO rodar `artisan test` completo** — há um teste pré-existente que estoura timeout em `MercadoLivreAdsService` (não relacionado a esta fase). Rodar por diretório/arquivo específico. |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| FLUXO-01 | Webhook HubSpot com serviço que exige contrato não roteia com chave ligada; com serviço isento (Polos), roteia mesmo ligada | feature | `php artisan test --filter=test_interruptor_ligado.*webhook` | ❌ precisa reescrever o teste existente (Pitfall 1) + caso novo isento |
| FLUXO-02 | Cadastro Comercial — mesmo comportamento | feature | `php artisan test --filter=test_interruptor_ligado.*cadastro` | ❌ idem |
| FLUXO-09 | `ativarEmpresaPendente()` respeita o bloqueio nos dois tipos (polos passa, assessoria não) | feature | novo arquivo, ex. `tests/Feature/Phase133/AtivarEmpresaPendenteBloqueioTest.php` | ❌ não existe teste nenhum hoje para este método (confirmado por busca) |
| SC 2b | Polos sempre passa mesmo com chave ligada, nos 3 caminhos | feature | mesmo conjunto acima, com asserção positiva (`MlbEmpresa::count()===1`) | ❌ |
| SC 4 | Desligar a chave sem deploy volta ao roteamento imediato | feature (já coberto) | `test_interruptor_desligado_roteia_como_sempre` (Phase124KillSwitchTest) | ✅ já existe, não muda |

### Sampling Rate

- **Por commit de task:** rodar o(s) arquivo(s) de teste tocado(s)
  diretamente (`Phase124KillSwitchTest.php`, novo arquivo de Phase133) — nunca
  a suíte inteira.
- **Por merge de wave:** rodar `tests/Feature/Phase124*`, `tests/Feature/Phase128`,
  `tests/Feature/Phase129`, `tests/Feature/Phase130` juntos (os quatro
  diretórios que tocam `EmpresaOperacionalRouter`/`Configuracao`/interruptor) —
  ainda evitando a suíte completa.
- **Gate da fase:** antes do `/gsd:verify-work`, confirmar que os 4 testes do
  Pitfall 1 foram atualizados (não apenas que passam — conferir que a
  asserção mudou de sentido, não que foi apenas relaxada) e que existe ao
  menos um teste cobrindo cada um dos dois caminhos da D-03 (router +
  `ativarEmpresaPendente`).

### Wave 0 Gaps

- [ ] `tests/Feature/Phase133/` (diretório novo) — cobertura de FLUXO-09
      (hoje zero testes para `ativarEmpresaPendente()`)
- [ ] Atualização nominal dos 4 testes listados no Pitfall 1
      (`tests/Feature/Phase124KillSwitchTest.php`)
- [ ] Nenhum framework/fixture novo necessário — `RefreshDatabase` e as
      factories/seeds de `Servico` já existentes cobrem o necessário

## Security Domain

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V4 Access Control | sim | `checkPubAccess('empresas')` já gate-ia `ativarEmpresaPendente()`; `permission:admin.contratos` já gate-ia `/administrativo/contratos` (Fase 131). Esta fase não muda controle de acesso, só a lógica de negócio dentro de rotas já protegidas |
| V5 Input Validation | sim (já coberto) | `$request->validate(['tipo' => 'required|in:polos,assessoria'])` já existe; nenhuma validação nova de input é necessária, a mudança é de regra de negócio pós-validação |
| V1 Architecture | sim | Único ponto de leitura do interruptor (`bloqueioAtivo()` dentro de `rotear()`) é o princípio arquitetural que esta fase preserva — a checagem duplicada em `ativarEmpresaPendente()` é uma exceção CONSCIENTE e documentada (D-03), não um desvio acidental |

### Known Threat Patterns for este domínio

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Bypass de gate administrativo via rota não coberta (a "quarta porta") | Elevation of Privilege (funcional, não de autenticação) | Já mitigado hoje pelo fato de as duas rotas extras sempre produzirem `tipo='POLO'` (isento por regra de negócio, não por falha) — mas documentado como risco latente em `## Open Questions` caso o tipo default mude no futuro |
| Fail-open em nome de serviço não encontrado | Tampering (dado incompleto tratado como "livre passagem") | Mitigado pelo design fail-safe recomendado (`## Architecture Patterns` — filtro em lote): nome não encontrado = tratado como "exige contrato", nunca como isento |
| Divergência entre decisão do router e decisão de `ativarEmpresaPendente()` (D-03 aceita duplicação de regra) | Repudiation (comportamento inconsistente entre dois caminhos que deveriam concordar) | Mitigado por teste nominal nos dois caminhos (exigido pelo próprio D-03) — não há mitigação de código para unificar, é risco aceito e documentado pelo usuário |

## Sources

### Primary (HIGH confidence — leitura direta de código deste repositório)

- `app/Services/Operacional/EmpresaOperacionalRouter.php` — leitura completa, incluindo docblocks das Fases 124/128/129/130
- `app/Models/Servico.php` — `exigeContrato()`, `scopeExigeContrato()`, docblocks completos
- `app/Http/Controllers/MlbController.php` — `ativarEmpresaPendente()` (2432-2487), `empresas()` (2360-2422), `storeEmpresa()` (2489-2522)
- `app/Http/Controllers/MlbImplementacaoController.php` — `criar()` (492-549)
- `app/Http/Controllers/ContratoAdminController.php` — `index()` (57-201) completo
- `app/Http/Controllers/ComercialController.php` — `servicoDisparaImplementacao()`, `store()`
- `app/Http/Controllers/Api/HubspotWebhookController.php` — trecho de roteamento (600-680)
- `app/Models/Company.php` — `contratosServico()` (408-411)
- `database/migrations/2026_05_26_120001_create_servicos_table.php`, `2026_05_27_100001_seed_servicos_catalog.php`, `2026_08_13_100001_add_exige_contrato_to_servicos_table.php`, `2026_05_04_000004_add_tipo_to_mlb_empresas_table.php` — schema real
- `tests/Feature/Phase124KillSwitchTest.php` — leitura completa + execução real (`php artisan test`, 7/7 passando antes da mudança)
- `tests/Feature/Phase128/InvarianteRoteamentoTest.php` — leitura completa
- `resources/js/Pages/Admin/Contratos.jsx`, `resources/js/Pages/Admin/ContratoDetalhe.jsx`, `resources/js/Pages/Mlb/Empresas.jsx` — leitura de estrutura/props/estilo
- `routes/web.php` — confirmação de todas as rotas citadas
- Tinker local (`C:\xampp\php\php.exe artisan tinker`) — estado real do catálogo `servicos` (10 linhas, `exige_contrato` por linha)
- `.planning/phases/132-.../132-01-PLAN.md`, `132-02-PLAN.md`, `132-03-PLAN.md` — padrão de ativação de interruptor via tinker (precedente direto para D-05)
- `.planning/learnings/painel-polos-status-e-meta.md` — cross-reference da divergência `MlbEmpresa` vs `Company`

### Secondary (MEDIUM confidence)

- Nenhuma — esta pesquisa não dependeu de fontes externas (sem biblioteca nova, sem API externa nova).

### Tertiary (LOW confidence)

- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: N/A — nenhuma dependência nova
- Architecture: HIGH — todo o desenho vem de leitura direta do código existente e de precedentes já implementados no mesmo repositório (Fase 132)
- Pitfalls: HIGH — os 4 testes que quebram foram confirmados por execução real da suíte, não por inferência; o default `exige_contrato=true` foi confirmado por leitura da migration e consulta real ao banco

**Research date:** 2026-08-18
**Valid until:** próxima mudança em `EmpresaOperacionalRouter`, `Servico`, ou nas rotas de `MlbController`/`ContratoAdminController` — não há prazo de validade por tempo, é validade por estabilidade de código (esta é uma fase de "código interno", não de integração externa sujeita a mudança de terceiros)
