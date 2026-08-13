# Phase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0) - Research

**Researched:** 2026-08-13
**Domain:** Laravel 12 interno — comando agendado de reconciliação contra API externa (Clicksign),
notificação in-app com cooldown, rota administrativa mínima com auditoria. Nenhuma biblioteca nova.
**Confidence:** HIGH (100% baseado em código já existente no repositório e em medições empíricas
registradas nas Fases 126-129; zero dependência nova, zero chamada de API não medida)

## Summary

Esta fase não introduz nenhuma tecnologia nova — é composição de peças que a Fase 129 deixou
explicitamente prontas para isso: `EmpresaOperacionalRouter::liberarEmpresa()` (idempotente,
travado por `Cache::lock()` por empresa), `ContratoLiberacao` (já com a coluna `via` aceitando um
terceiro valor sem migration), `GateLiberacaoOperacionalService::avaliar()` (puro, reusável fora do
job de webhook) e `ClicksignClient::consultarEnvelope()`/`consultarDocumento()` (client já
validado contra o sandbox real). O trabalho da Fase 130 é ORQUESTRAÇÃO: um comando agendado que
chama essas peças para o subconjunto de contratos em `aguardando_assinaturas` e para os PDFs
pendentes; um alerta idempotente que varre `contrato_assinaturas` por idade; e uma rota+página
mínima que chama exatamente o mesmo `liberarEmpresa()` com `via='manual'`.

O achado mais importante desta pesquisa, não coberto explicitamente no CONTEXT: o bucket de rate
limit `clicksign-webhook` (`RateLimiter::for('clicksign-webhook')`, `AppServiceProvider.php`) está
travado em **3/min GLOBAL para a conta inteira** (6 chamadas/min, porque cada evento processado
custa 2 chamadas) — não os 20/min medidos brutos do sandbox. Um comando de reconciliação que
reconsulta N contratos num laço síncrono simples, sem passar por esse bucket, pode facilmente
estourar o limite se houver mais de ~3 contratos pendentes no dia. A arquitetura correta é o
comando ENFILEIRAR um job por contrato pendente (reusando `RateLimited('clicksign-webhook')`,
o mesmo middleware que `ProcessarEventoClicksignJob`/`BaixarPdfContratoAssinadoJob` já usam), não
fazer HTTP direto dentro do `handle()` do comando.

**Primary recommendation:** um comando `clicksign:reconciliar` que apenas SELECIONA os
contratos elegíveis e despacha um job por contrato (`ReconciliarContratoClicksignJob`, novo,
mesma família de `ProcessarEventoClicksignJob`) que reusa `consultarEnvelope()` +
`GateLiberacaoOperacionalService::avaliar()` + `EmpresaOperacionalRouter::liberarEmpresa(...,
via: 'reconciliacao')` + redisparo condicional de `BaixarPdfContratoAssinadoJob`. O alerta
(REDE-02) é um comando SEPARADO, síncrono, sem HTTP externo (só lê o banco local) — pode rodar
`dailyAt` sem tocar rate limit nenhum. A liberação manual (REDE-03/DADOS-05) é uma rota
`role:admin` + controller fino + página Inertia descartável, reusando o mesmo `liberarEmpresa()`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Varredura de reconciliação (REDE-04) | API/Backend (Console Command + Job) | — | Não há tela; roda via `Schedule::command()`. HTTP externo isolado num Job para herdar `RateLimited`. |
| Alerta de contrato preso (REDE-02) | API/Backend (Console Command + Notification) | Browser (sino já existente) | Geração é backend puro (lê `contrato_assinaturas`, grava `notifications`); a exibição do sino é código já existente, fora do escopo desta fase. |
| Liberação manual (REDE-03/DADOS-05) | API/Backend (Controller) | Frontend Server/SSR (página Inertia descartável) | Formulário simples só-admin; toda a regra de negócio (idempotência, lock, auditoria) já vive no Service — o controller só valida input e chama. |
| Auto-monitoramento (carimbo + ausência) | API/Backend (Configuracao + Command) | Frontend Server/SSR (se o check for movido para middleware, ver Pitfall 2) | Ver discussão detalhada na pergunta 2 abaixo — a resposta honesta é que um comando agendado sozinho NÃO cobre "cron inteiro morto". |

## User Constraints

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Todas as 12 decisões abaixo são LOCKED (usuário decidiu explicitamente, nenhuma delegada nesta
rodada) — copiadas verbatim de `130-CONTEXT.md`:

- **D-01:** Canal é o sino in-app (`BaseNotification::via()` → `['database']`). Sem e-mail/WhatsApp.
  Consequência assumida: o alerta chega quando alguém abre o sistema, não em minutos reais.
- **D-02:** Audiência = `role:admin` ∪ usuários ATIVOS vinculados ao setor `comercial`. NÃO reusar
  `AudienciaComercial::lideresEPermissionados()` como está (é mais estreito — só líderes).
- **D-03:** Gatilho é "o que vier primeiro" entre um número de dias configurável (igual para
  todos) OU uma fração do prazo do próprio contrato.
- **D-04:** O alerta REPETE em intervalo até resolver — exige registrar quando avisou pela
  última vez.
- **D-05:** O alerta cobre TUDO que não terminou bem (`rascunho` parado, `aguardando_assinaturas`
  além do prazo, `recusado`, `expirado`, `erro`), não só o que a reconciliação corrige. Regra:
  "empresa sem liberação há tempo demais", causa aparece na mensagem.
- **D-06:** Reconciliação roda uma vez por dia (`dailyAt`), como todo o agendamento do projeto.
- **D-07:** Ao achar divergência, CORRIGE SOZINHA pelo mesmo caminho — `liberarEmpresa()` com
  `via='reconciliacao'`.
- **D-08:** A reconciliação também redispara `BaixarPdfContratoAssinadoJob` para PDF pendente.
- **Escopo da varredura ≠ escopo do alerta:** reconsultar a Clicksign só em
  `aguardando_assinaturas` + PDFs pendentes. O alerta é mais largo (D-05). Não uniformizar.
- **D-09:** Registro de execução (quando rodou, quantos contratos viu, o que corrigiu) + checagem
  de ausência que acusa quando a última execução é mais velha que o esperado (cobre cron parado).
- **D-10:** Rota mínima com formulário simples, só-admin (empresa, serviço, motivo). Tela
  deliberadamente descartável — Fase 131 reescreve; backend é o mesmo e permanece.
- **D-11:** A liberação manual IGNORA o gate automático, mas mostra o estado real com destaque
  antes de confirmar. Admin pode liberar em qualquer estado, inclusive `recusado`.
- **D-12:** Motivo = lista de motivos (`webhook_nao_chegou`, `cliente_assinou_fora_do_sistema`,
  `decisao_comercial`, `outro` — sugestões, plano pode refinar) + campo de detalhe obrigatório.

### Claude's Discretion

- Onde exatamente mora o carimbo da D-09 (tabela nova vs. `Configuracao`) — ver pergunta 1 abaixo.
- Os valores default da D-03 (dias fixos + fração do prazo) — ver pergunta 3 abaixo.
- O intervalo da D-04 (repetição do alerta) — ver pergunta 4 abaixo.
- O refinamento da lista de motivos da D-12 — ver pergunta 7 abaixo.

### Deferred Ideas (OUT OF SCOPE)

- Alerta por e-mail ou WhatsApp — recusado na D-01.
- Tela definitiva do Administrativo e permissão `admin.contratos` — Fase 131.
- Setor "Administrativo" como estrutura organizacional — não existe hoje.
- Painel de taxa/tempo de assinatura — já fora de escopo pela D3 da milestone.
- Ligar o bloqueio do roteamento — Fase 133.
- **Polling como mecanismo primário** — `REQUIREMENTS-v22.md` §"Out of Scope": a reconciliação é
  rede de segurança, nunca o mecanismo principal.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| REDE-02 | O sistema avisa quando uma empresa está parada aguardando assinatura além do prazo aceitável | Ver "Alerta de contrato preso (D-01 a D-05)" abaixo — schema, query, notificação, cooldown |
| REDE-03 | Um admin consegue liberar uma empresa ao operacional manualmente quando a Clicksign falha, e essa liberação fica registrada com autor e motivo | Ver "Liberação manual (D-10 a D-12)" — rota, controller, reuso de `liberarEmpresa()` |
| REDE-04 | Uma varredura periódica reconcilia com a Clicksign os contratos cujo webhook nunca chegou (D3) | Ver "Reconciliação (D-06 a D-08)" — comando + job, reuso de `GateLiberacaoOperacionalService` |
| DADOS-05 | Quando um admin libera a empresa manualmente, o sistema registra quem liberou e por quê | Coberto pela tabela `contrato_liberacoes` já existente (D-05 da Fase 129) — `liberado_por_user_id` + `motivo`, zero migration nova |
</phase_requirements>

## Perguntas concretas — respostas

### 1. Onde mora o carimbo da D-09 (tabela nova vs. `Configuracao`)

**Recomendação: `Configuracao` (existente), não tabela nova.** [VERIFIED: código do projeto]

`app/Models/Configuracao.php` já é key/value genérico (`chave` unique + `valor` text), e o
próprio docblock da classe já lista como exemplo de uso: *"Exemplos de uso: destinatários de
email, **datas de último envio**, feature flags"* — é literalmente este caso de uso. Há
precedente direto: a migration `2026_05_25_...seed_polo_limiares` grava valores de negócio
ajustáveis sem deploy usando `Configuracao::set()`, e a Fase 131 (tela do Administrativo) é a
candidata natural para expor esses valores numa UI depois.

Como `valor` é `text` (não JSON nativo), grave um JSON-encoded blob numa única chave, por
exemplo `clicksign_reconciliacao_status`:
```php
Configuracao::set('clicksign_reconciliacao_status', json_encode([
    'executado_em'  => now()->toIso8601String(),
    'vistos'        => $vistos,
    'corrigidos'    => $corrigidos,
    'pdfs_redisparados' => $pdfsRedisparados,
    'erro'          => null, // ou a mensagem, se a execução lançou exceção
]));
```
Ler de volta com `json_decode(Configuracao::get('clicksign_reconciliacao_status'), true)`.

**Por que NÃO tabela nova:** uma tabela para um único carimbo (append-only de 1 linha, sempre
`updateOrCreate`) não ganha nada de uma tabela dedicada — sem histórico multi-linha necessário
(D-09 pede "a última execução", não "as últimas 30"), sem relacionamento com outra entidade, sem
índice de busca. Se a auditoria de histórico de execuções virar necessidade real depois, ISSO sim
justificaria tabela — mas não é o que a D-09 pede hoje. Zero migration, zero armadilha de MariaDB
(nome de índice, `enum`, FK `nullOnDelete`) — o `Configuracao` já passou por todas essas.

**Nenhuma migration necessária para esta parte da fase.**

### 2. Como a checagem de "cron parado" se auto-verifica sem depender do próprio cron parado

**Resposta honesta, não confortável:** um comando `Schedule::command(...)->dailyAt(...)` que
verifica a idade do carimbo **não cobre** o caso "o crontab do SO parou de disparar
`schedule:run` inteiramente" — porque, nesse cenário, o PRÓPRIO comando de verificação também
para de rodar. Isso não é um detalhe implementacional evitável: é uma limitação estrutural de
qualquer verificação que depende do mesmo mecanismo que está sendo verificado. [ASSUMED —
raciocínio lógico, não há biblioteca ou doc a citar aqui]

Duas coisas diferentes estão sendo confundidas na frase da D-09, e vale nomear a diferença para o
plano:

| Falha | Um 2º comando `dailyAt` detecta? |
|---|---|
| A. `clicksign:reconciliar` especificamente parou (bug, `Schedule::command()` removido por engano, exceção não tratada antes de gravar o carimbo) — o RESTO do agendamento (são ~20 outros `Schedule::command()->dailyAt()` no projeto) continua rodando normalmente | **SIM** — um 2º comando agendado em outro horário lê o carimbo e alerta se estiver velho |
| B. O `crontab -e`/agendador do SO parou de disparar `php artisan schedule:run` de vez — NADA no Laravel roda, nem o comando de reconciliação nem o de verificação | **NÃO** — nenhum comando agendado, de nenhum tipo, é capaz de se auto-verificar quando o gatilho externo que os dispara está morto |

O cenário A é o mais provável na prática (é exatamente o tipo de falha silenciosa que este projeto
já viveu — comparar com o "job morto sem canal de alerta" que motivou a D-11 da Fase 129). O
cenário B, se acontecer, derruba **todos** os ~20 comandos agendados do projeto simultaneamente
(`adman:sync`, `ml:sync`, etc.) — o que tende a ficar visível por outros sintomas (dashboards
parados) antes/independente desta fase.

**Recomendação prática para o plano — resolve A com esforço mínimo, documenta honestamente que B
fica fora:**
- Um SEGUNDO comando `dailyAt`, em horário DIFERENTE do `clicksign:reconciliar` (ex.: se a
  reconciliação roda às 07:00, a checagem roda às 08:00 — 1h de folga), que lê o carimbo da
  `Configuracao` e, se `executado_em` for mais velho que um limiar (ex.: 26h — folga sobre as 24h
  do ciclo diário), dispara a MESMA notificação in-app (D-01/D-02) com uma mensagem distinta
  ("a varredura de reconciliação não rodou hoje").
- Se o time quiser fechar o cenário B de verdade, a única forma estrutural é mover o check para
  algo que NÃO depende do cron — por exemplo, dentro de `HandleInertiaRequests` (roda a cada
  request autenticado, já compartilha props globais), cacheado por alguns minutos para não bater
  no banco a cada request. Isso está **fora do escopo desta fase** por decisão implícita da
  D-01 ("o alerta chega quando alguém abre o sistema" já é a filosofia aceita) — mas vale registrar
  como opção futura se o cenário B algum dia importar de verdade.

### 3. D-03 (gatilho "o que vier primeiro") — onde está o prazo e como combinar os dois critérios

**Prazo por contrato já existe: `ContratoAssinatura::prazoDiasEfetivo()`.** [VERIFIED: código,
`app/Models/ContratoAssinatura.php` linhas 230-233] Devolve `prazo_dias` (coluna, Fase 127-01) ou
o fallback `config('services.clicksign.prazo_dias_padrao')` (30 dias, valor MEDIDO como default da
própria Clicksign — `CLICKSIGN-SANDBOX-EMPIRICO.md` §11.1).

**⚠️ Não confundir com `lembreteDiasEfetivo()` / `CLICKSIGN_LEMBRETE_DIAS`.** Esse é o
`remind_interval` que a PRÓPRIA Clicksign usa para reenviar e-mail ao SIGNATÁRIO cliente — "o
lembrete é nativo da Clicksign — não criar scheduler próprio, duplicaria notificação" (comentário
em `config/services.php`). O alerta da D-04 é para a EQUIPE ECF (admin+comercial), audiência e
propósito completamente diferentes. Reusar a mesma configuração misturaria dois conceitos.

**Fórmula de "o que vier primeiro":**
```php
$idadeDias = now()->diffInDays($dataBase); // ver "data base" abaixo, por estado
$limiarDias = min(
    (int) Configuracao::get('rede_alerta_dias_fixo', 5), // sugestão de default, ver abaixo
    (int) round($contrato->prazoDiasEfetivo() * (float) Configuracao::get('rede_alerta_fracao_prazo', 0.5))
);
$estaPreso = $idadeDias >= $limiarDias;
```

**Data base por estado (novo — não estava explícito no CONTEXT, decisão do plano):**
- `rascunho`: `created_at` (nunca foi enviado; §11.2 do empírico mede que a Clicksign apaga
  rascunho sozinha em 7 dias, então um limiar fixo abaixo de 7 dias é obrigatório para este caso —
  ver Common Pitfalls).
- `aguardando_assinaturas`: `enviado_em`.
- `assinado` com `liberado_em` nulo (PDF pendente, D-08): `assinado_em`.
- `recusado`/`expirado`/`erro`: sem coluna de data própria hoje — usar `updated_at` (atualizado na
  transição de estado pelo hook `saving`, é a aproximação mais próxima disponível sem migration).

**Defaults sugeridos** [ASSUMED — não há medição para "aceitável" em dias; é julgamento de
negócio, sinalizado explicitamente para confirmação do usuário]:
- `rede_alerta_dias_fixo = 5` — menor que o prazo padrão de 30 dias, maior que o `remind_interval`
  padrão de 3 dias da Clicksign (dá tempo do lembrete nativo dela agir primeiro).
- `rede_alerta_fracao_prazo = 0.5` — metade do prazo do contrato; um contrato de 10 dias alerta em
  5, um de 60 dias alerta em 30 (nunca deixando passar mais da metade do prazo sem ninguém saber).

**Onde configurar:** `Configuracao` (mesmo raciocínio da pergunta 1) — ajustável sem deploy,
candidato natural a virar campo editável na tela da Fase 131.

### 4. D-04 (alerta repete até resolver) — onde registrar `ultimo_alerta_em` e intervalo default

**Recomendação: coluna nova em `contrato_assinaturas`, não tabela própria.** [ASSUMED — decisão de
schema, não uma verificação factual]

Toda causa do D-05 ("empresa sem liberação há tempo demais") tem um `ContratoAssinatura`
correspondente — mesmo `rascunho`/`recusado`/`expirado`/`erro` são estados DESSE model (ver
docblock do model, os 7 estados). Uma migration aditiva simples, seguindo o precedente EXATO de
`2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php` (mesma tabela, mesma
técnica `if (!Schema::hasColumn(...))`, sem FK, sem índice — não há armadilha de MariaDB aplicável):

```php
$table->timestamp('ultimo_alerta_em')->nullable()->after('pdf_assinado_erro');
```

**Cooldown de disparo:**
```php
$precisaAlertar = $contrato->ultimo_alerta_em === null
    || $contrato->ultimo_alerta_em->lt(now()->subDays($intervaloRepeticao));
```

**Intervalo default sugerido:** [ASSUMED] `rede_alerta_repeticao_dias = 3` (via `Configuracao`,
mesmo padrão das outras duas chaves da pergunta 3) — repete a cada 3 dias até alguém agir, número
familiar no domínio (mesmo valor do `remind_interval` padrão da Clicksign), fácil de justificar.

**Padrão de "notificação com cooldown" já existente no projeto?** Não exatamente — o precedente
mais próximo é a idempotência de `dispatchAtingidaIfNeeded` (Fase 11, `notificado_em` em
`GoalResult`/similares), que dispara UMA vez e nunca mais (sem repetição). D-04 exige repetição
periódica, que é um padrão NOVO no projeto — a coluna `ultimo_alerta_em` + comparação de data é a
forma mais simples e consistente com o resto do schema (nenhuma dependência nova).

### 5. D-02 (audiência) — estrutura real e por que `lideresEPermissionados()` não serve

**Estrutura confirmada** [VERIFIED: `app/Models/User.php`, `app/Models/Setor.php`]:
- `User::isAdmin(): bool` → `$this->role === 'admin'`.
- `users.active` (boolean) — já usado em `AudienciaComercial` (`->where('active', true)`).
- `Setor::membros(): BelongsToMany` — TODOS os membros do setor via pivot `user_setores`
  (`withPivot('cargo_id', 'is_principal', 'assigned_at')`). **Esta é a relação certa** — não
  `Setor::lideres()` (pivot `setor_lideres`, só líderes — é o que `lideresEPermissionados()` usa
  e por isso é estreito demais para a D-02).

**Query recomendada** (nova classe, não reusar `AudienciaComercial` como está):
```php
$admins = User::where('active', true)->where('role', 'admin')->get();

$setorComercial = Setor::where('slug', 'comercial')->first();
$comerciais = $setorComercial
    ? $setorComercial->membros()->where('active', true)->get()
    : collect();

$audiencia = $admins->concat($comerciais)->unique('id')->values();
```

**Por que `lideresEPermissionados()` NÃO serve, textualmente:** ela devolve (a) só os LÍDERES do
setor Comercial (pivot `setor_lideres`) + (b) usuários com a permission
`comercial.cadastrar_empresa` — nenhuma das duas pontas é "todo membro ativo do setor comercial".
Um analista comercial comum, membro do setor mas sem a permission nem liderança, NÃO apareceria —
exatamente a lacuna que a D-02 aponta.

**Ponto em aberto explícito para o plano decidir:** o texto da D-02 diz "role:admin ∪ usuários
ATIVOS vinculados ao setor comercial" — gramaticalmente "ATIVOS" modifica só o segundo grupo. A
query acima também filtra `active=true` nos admins por segurança (evita notificar conta
desligada) — **isso é uma pequena divergência do texto literal, sinalizada aqui para confirmação**,
não uma decisão silenciosa.

**Categoria da notificação:** `App\Notifications\Categoria` (enum) hoje só tem `META_ATRIBUIDA`,
`META_ATINGIDA`, `MANUAL`, `ALERTA_ECF`. Nenhuma serve bem para "contrato preso" — `MANUAL` é o
fallback mais próximo (foi o escolhido por `EmpresaHubspotPendenteNotification`, cenário análogo:
"evento de sistema que pede ação humana"). Adicionar um case novo (`CONTRATO_PRESO` ou similar) é
mudança de 1 linha no enum e é a opção mais correta semanticamente — mas fica a critério do plano
(nenhuma decisão do CONTEXT trava isso, e ambas as opções são baratas).

### 6. Reconciliação (D-06/D-07/D-08) — detecção de divergência e redisparo do PDF

**Forma exata de `consultarEnvelope()` — CONFIRMADO no código, não só na doc:**
[VERIFIED: `app/Services/Clicksign/ClicksignClient.php` + 2 chamadores reais]
```php
$envelope = $client->consultarEnvelope($contrato->clicksign_envelope_id);
$statusEnvelope = $envelope['attributes']['status'] ?? null; // NUNCA $envelope['data']['attributes']
```
`ProcessarEventoClicksignJob.php:149` e `GateLiberacaoOperacionalService.php:56` já fazem
exatamente isso — o padrão é idêntico ao que a reconciliação precisa, e **é o mesmo array que
`GateLiberacaoOperacionalService::avaliar(ContratoAssinatura $contrato, array
$envelopeReconsultado)` já espera como segundo parâmetro** — reuso direto, zero adaptação.

**Reconciliação = reexecutar o MESMO caminho do webhook, sem o evento.** O job da reconciliação
deveria fazer exatamente o que `ProcessarEventoClicksignJob::handle()` faz nos passos 3-7 (sync de
signatários via `ContratoSignatariosSyncService`, avaliação via
`GateLiberacaoOperacionalService::avaliar()`, liberação via `EmpresaOperacionalRouter::liberarEmpresa(...,
via: ContratoLiberacao::VIA_RECONCILIACAO, contrato: $contrato)`), só que disparado por VARREDURA
em vez de por evento — SEM tocar `ContratoAssinaturaEvento` (não há evento nenhum por trás).

**Requer 1 constante nova no model** — `ContratoLiberacao` hoje só tem `VIA_WEBHOOK`/`VIA_MANUAL`
em `VIA_TODAS`:
```php
public const VIA_RECONCILIACAO = 'reconciliacao'; // cabe em string(20), zero migration (D-05 da 129 já previu)
```

**Identificar "PDF pendente" (D-08):** `status = 'assinado'` E `pdf_assinado_path` vazio (não
importa `pdf_assinado_erro` — pode estar `null` ainda [nunca tentou] ou preenchido [tentou e
falhou]; os dois casos merecem redisparo):
```php
ContratoAssinatura::where('status', ContratoAssinatura::STATUS_ASSINADO)
    ->whereNull('pdf_assinado_path')
    ->get();
```

**Redisparar sem duplicar:** `BaixarPdfContratoAssinadoJob::dispatch($contrato)` — o job JÁ é
idempotente por construção (guard no topo do `handle()`: `if (filled(pdf_assinado_path) &&
Storage::exists(...)) return;`) e já tem `WithoutOverlapping('clicksign-pdf-' . $contrato->id)`.
Chamar de novo é seguro por desenho — não precisa de guard adicional na reconciliação.

**Escopo da consulta HTTP** (a decisão já travada — "escopo da varredura ≠ escopo do alerta"):
```php
ContratoAssinatura::where('status', ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS)
    ->whereNotNull('clicksign_envelope_id')
    ->get(); // só este subconjunto reconsulta a Clicksign
```

### 7. Liberação manual (D-10/D-11/D-12) — menor caminho consistente

**Molde direto:** `ContratoPdfAssinadoController.php` — rota `role:admin`, controller fino,
sem camada extra. Padrão de registro em `routes/web.php`:
```php
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/admin/contratos/liberacao-manual', [ContratoLiberacaoManualController::class, 'index'])
        ->name('contratos.liberacao-manual.index');
    Route::post('/admin/contratos/liberacao-manual', [ContratoLiberacaoManualController::class, 'store'])
        ->name('contratos.liberacao-manual.store');
});
```

**Listar empresas presas** — reusa a MESMA query da D-05 (o alerta e a lista da tela olham o mesmo
recorte: contratos "sem liberação há tempo demais"), sem duplicar a regra.

**Controller ilustrativo** (chama `liberarEmpresa()` direto, D-11 respeitado: sem passar por
`GateLiberacaoOperacionalService`):
```php
public function store(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
{
    $data = $request->validate([
        'company_id' => 'required|exists:companies,id',
        'servico_id' => 'required|exists:servicos,id',
        'motivo_slug' => ['required', Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)],
        'motivo_detalhe' => 'required|string|max:1000',
    ]);

    $router->liberarEmpresa(
        Company::findOrFail($data['company_id']),
        Servico::findOrFail($data['servico_id']),
        ContratoLiberacao::VIA_MANUAL,
        liberadoPorUserId: $request->user()->id,
        motivo: $data['motivo_slug'] . ': ' . $data['motivo_detalhe'], // ou 2 colunas — ver nota abaixo
    );

    return back()->with('success', 'Empresa liberada manualmente.');
}
```

**Nota sobre o motivo (D-12 — "lista + detalhe obrigatório"):** a coluna `motivo` de
`contrato_liberacoes` é `text` único hoje. Duas opções, ambas viáveis, decisão do plano:
1. Concatenar `"{slug}: {detalhe}"` num único `text` (zero migration).
2. Migration aditiva simples (`motivo_slug` string + `motivo_detalhe` text), útil se a Fase 131
   quiser filtrar/agrupar por slug depois ("80% das manuais são webhook perdido" — a própria
   justificativa da D-12 pede AGRUPAR, o que um único campo texto livre concatenado dificulta com
   `LIKE`). **Recomendação: opção 2** — é a única que entrega de verdade o "permite agrupar e
   enxergar padrão" que a D-12 pede como motivo da decisão; segue o mesmo padrão aditivo de
   `pdf_assinado_erro` (sem FK, sem índice, sem armadilha de MariaDB).

**Onde a lista de motivos vive:** const no model, espelhada em JS — padrão confirmado do projeto
(`ContratoAssinatura::STATUS_TODOS` no PHP, replicado manualmente como objeto JS nas páginas que
consomem; ver seção "Domain constants" do CLAUDE.md/arquitetura). Sugestão:
```php
// ContratoLiberacao.php
public const MOTIVO_WEBHOOK_NAO_CHEGOU = 'webhook_nao_chegou';
public const MOTIVO_ASSINOU_FORA_DO_SISTEMA = 'cliente_assinou_fora_do_sistema';
public const MOTIVO_DECISAO_COMERCIAL = 'decisao_comercial';
public const MOTIVO_OUTRO = 'outro';
public const MOTIVOS_MANUAIS = [
    self::MOTIVO_WEBHOOK_NAO_CHEGOU,
    self::MOTIVO_ASSINOU_FORA_DO_SISTEMA,
    self::MOTIVO_DECISAO_COMERCIAL,
    self::MOTIVO_OUTRO,
];
```

**Tela (Inertia/React):** local natural `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` —
`Pages/Admin/` já existe com páginas soltas de página única (`Empresas.jsx`, `Financeiro.jsx`), é
o padrão certo para uma tela "deliberadamente descartável" (D-10). **D-11 exige exibir o estado
real do contrato com destaque** — a página precisa receber e mostrar `contrato.status` /
`contrato.enviado_em` antes do botão de confirmar (ex.: badge vermelho "RECUSADO PELO CLIENTE" se
`status === 'recusado'`).

### 8. SC4 (corrida) — como provar sem reimplementar

**Teste pronto para copiar:**
`tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php` — já prova exatamente este
cenário (duas chamadas concorrentes de `liberarEmpresa()` para serviços diferentes da mesma
empresa, via o decorator `routerComGatilhoDeCorrida()` que intercepta `lockDaEmpresa()` para
simular concorrência sem paralelismo real de SO). O plano da Fase 130 deve **adaptar este teste**
trocando um dos dois `via` (ex.: `VIA_WEBHOOK` vs `VIA_MANUAL`, ou `VIA_RECONCILIACAO` vs
`VIA_MANUAL`) para provar especificamente o cenário do SC4 — não escrever um mecanismo de lock
novo. A asserção central é a mesma: `MlbEmpresa::where('company_id', ...)->count()` === 1.

**`Cache::lock()` no ambiente de teste — funciona, sem artifício.**
[VERIFIED: `phpunit.xml` linha 26 `CACHE_STORE=array`] O driver `array` do Laravel implementa
`LockProvider` (locks em memória, por processo) — suficiente para os testes deste projeto porque
PHPUnit roda single-thread mesmo (o próprio docblock do teste CR-01 documenta isso: "PHPUnit é
single-thread — não existe forma de ter dois processos PHP disputando a trava no MESMO instante").
Não precisa de `CACHE_STORE=database` nem de nenhum outro artifício para testar — o padrão já
validado (interceptar `lockDaEmpresa()` via subclasse anônima) é suficiente e já está provado
funcionando pela suíte da Fase 129.

### 9. Testabilidade — comando agendado + notificação + HTTP Clicksign

**Comando agendado:** testar via `$this->artisan('clicksign:reconciliar')->assertExitCode(0)`
(mesmo padrão de `ClicksignVerificarAssinaturaCommandTest.php`), com `Http::fake()` para simular
`consultarEnvelope()`/`consultarDocumento()` — **mas lembrar do Pitfall C do 129-RESEARCH**:
`Http::fake()` não prova forma de payload real, só prova fiação (o job/comando reconsulta em vez
de confiar em estado antigo, o guard de idempotência funciona, o redisparo do PDF acontece). A
forma real da resposta já foi medida nas Fases 126-129 (`§12.7` do empírico) — não é preciso
remedir aqui, só reusar o shape já confirmado (`{id, type, attributes, relationships}` no topo).

**Notificação:** `Notification::fake()` é o padrão correto AQUI (diferente da suíte fundacional
Phase 9-12, que proíbe `fake()` de propósito porque está testando o PRÓPRIO mecanismo de
persistência). `tests/Feature/Phase35HubspotNotifyTest.php` é o molde direto — mesmo cenário
("audiência calculada + notificação disparada para cada membro + dedup"):
```php
Notification::fake();
// ... roda o comando/ação ...
Notification::assertSentTo($admin, ContratoPresoNotification::class);
Notification::assertSentTo($comercial, ContratoPresoNotification::class);
Notification::assertNotSentTo($usuarioInativo, ContratoPresoNotification::class);
```

**Fila:** todos os jobs desta fase (`ReconciliarContratoClicksignJob`, o redisparo do PDF) já
seguem o padrão `QUEUE_CONNECTION=sync` do `phpunit.xml` — rodam inline nos testes, sem precisar de
`Queue::fake()` a menos que o teste queira só verificar QUE foi despachado sem executar (padrão já
usado em `ClicksignWebhookDespachaFilaTest.php`).

**Molde de estrutura de suíte:** criar `tests/Feature/Phase130/` espelhando
`tests/Feature/Phase129/` (1 classe por comportamento: `ReconciliacaoDivergenciaTest`,
`ReconciliacaoPdfPendenteTest`, `AlertaContratoPresoTest`, `AlertaCooldownTest`,
`LiberacaoManualTest`, `LiberacaoManualCorridaTest`, `AutoMonitoramentoCarimboTest`).

## Standard Stack

Nenhuma dependência nova. Todas as peças já estão instaladas e em uso:

| Componente | Já existe em | Uso nesta fase |
|---|---|---|
| `Illuminate\Console\Scheduling\Schedule` | `routes/console.php` | `Schedule::command('clicksign:reconciliar')->dailyAt(...)` |
| `Illuminate\Support\Facades\Cache` (locks) | `EmpresaOperacionalRouter::lockDaEmpresa()` | Herdado sem mudança — `liberarEmpresa()` já protegido |
| `Illuminate\Notifications\Notification` | `App\Notifications\BaseNotification` | Nova subclasse concreta (`ContratoPresoNotification` ou similar) |
| `Illuminate\Queue\Middleware\RateLimited` | `ProcessarEventoClicksignJob`, `BaixarPdfContratoAssinadoJob` | Novo job de reconciliação DEVE usar o mesmo bucket `clicksign-webhook` |
| `App\Services\Clicksign\ClicksignClient` | Fase 126 | `consultarEnvelope()` (já usado); nenhum método novo necessário |
| `App\Services\Contratos\GateLiberacaoOperacionalService` | Fase 129 | Reuso direto, puro, já testado |
| `App\Services\Operacional\EmpresaOperacionalRouter` | Fase 124/129 | `liberarEmpresa()` — ponto único, já pronto para os 2 novos `via` |
| `App\Models\Configuracao` | Fase 38 | Carimbo do D-09 + thresholds do D-03/D-04 |

**Installation:** nenhuma. `composer.json`/`package.json` não mudam.

## Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote novo (nem PHP/Composer nem JS/npm). Todas
as classes/serviços usados já existem no repositório, construídos nas Fases 124-129 da mesma
milestone.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────── REDE-04: Reconciliação (dailyAt) ───────────────────────────┐
│                                                                                          │
│  Schedule::command('clicksign:reconciliar')                                             │
│         │                                                                                │
│         ▼                                                                                │
│  ClicksignReconciliar (Command)                                                          │
│    │ SELECT contrato_assinaturas WHERE status='aguardando_assinaturas'                   │
│    │ SELECT contrato_assinaturas WHERE status='assinado' AND pdf_assinado_path IS NULL   │
│    │                                                                                      │
│    ├──► dispatch ReconciliarContratoClicksignJob (1 por contrato pendente) ──┐           │
│    ├──► dispatch BaixarPdfContratoAssinadoJob (1 por PDF pendente) ──────────┤           │
│    │                                                                          │           │
│    └──► Configuracao::set('clicksign_reconciliacao_status', {...})           │           │
│                                                                                │           │
│         ┌──────────────────────────────────────────────────────────────────┘           │
│         ▼            [fila, respeitando RateLimited('clicksign-webhook') = 3/min]        │
│  ReconciliarContratoClicksignJob::handle()                                               │
│    1. ClicksignClient::consultarEnvelope($envelopeId)   ──► HTTP Clicksign                │
│    2. ClicksignClient::listarEventosDoDocumento(...)     ──► HTTP Clicksign               │
│    3. ContratoSignatariosSyncService::aplicar(...)                                       │
│    4. GateLiberacaoOperacionalService::avaliar($contrato, $envelope)                     │
│    5. SE liberar=true → EmpresaOperacionalRouter::liberarEmpresa(..., via='reconciliacao')│
│                              │                                                            │
│                              ▼                                                            │
│                    ContratoLiberacao::create(...) ──► MlbEmpresa (se aplicável)          │
└──────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────── REDE-02: Alerta de contrato preso (dailyAt) ─────────────────┐
│                                                                                          │
│  Schedule::command('clicksign:alertar-presos')                                          │
│         │                                                                                │
│         ▼                                                                                │
│  ClicksignAlertarPresos (Command) — SEM HTTP externo, só banco local                     │
│    │ SELECT contrato_assinaturas WHERE status IN (7 estados) [D-05]                      │
│    │ FILTER idade >= min(dias_fixo, fracao * prazo_dias_efetivo) [D-03]                  │
│    │ FILTER ultimo_alerta_em IS NULL OR < now()-intervalo [D-04]                         │
│    │                                                                                      │
│    ├──► AudienciaRedeSeguranca::adminsEComercial() [D-02]                                │
│    ├──► Notification::send($audiencia, new ContratoPresoNotification($contrato))         │
│    └──► $contrato->update(['ultimo_alerta_em' => now()])                                 │
└──────────────────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────── REDE-03/DADOS-05: Liberação manual (síncrono) ───────────────┐
│                                                                                          │
│  Admin (browser) ──► GET /admin/contratos/liberacao-manual ──► lista de empresas presas  │
│                       (mesma query do alerta REDE-02)                                    │
│         │                                                                                │
│         ▼ preenche empresa + serviço + motivo(slug) + detalhe                            │
│  POST /admin/contratos/liberacao-manual                                                  │
│         │                                                                                │
│         ▼                                                                                │
│  ContratoLiberacaoManualController::store()                                              │
│    └──► EmpresaOperacionalRouter::liberarEmpresa(..., via='manual', liberadoPorUserId, motivo)│
│              (MESMO ponto único que webhook e reconciliação — protegido pelo mesmo lock) │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
app/Console/Commands/
├── ClicksignReconciliar.php              # REDE-04 — só SELECT + dispatch, zero HTTP direto
├── ClicksignAlertarPresos.php            # REDE-02 — só banco local, sem HTTP
└── ClicksignVerificarVarredura.php       # D-09 — checa idade do carimbo (2º comando, horário distinto)

app/Jobs/
└── ReconciliarContratoClicksignJob.php   # HTTP Clicksign + reconsulta, herda RateLimited

app/Notifications/
└── ContratoPresoNotification.php         # extends BaseNotification, categoria MANUAL (ou nova)

app/Http/Controllers/
└── ContratoLiberacaoManualController.php # index() + store(), role:admin

app/Support/
└── AudienciaRedeSeguranca.php            # D-02 — NÃO reusar AudienciaComercial::lideresEPermissionados()

resources/js/Pages/Admin/
└── ContratosLiberacaoManual.jsx          # descartável (D-10) — Fase 131 substitui

database/migrations/
├── ..._add_ultimo_alerta_em_to_contrato_assinaturas_table.php   # D-04, aditiva, sem FK/índice
└── ..._add_motivo_slug_to_contrato_liberacoes_table.php         # D-12, aditiva, opcional (ver pergunta 7)
```

### Pattern 1: Comando que só orquestra, job que fala HTTP

**O quê:** o comando agendado (`ClicksignReconciliar`) nunca chama `ClicksignClient` diretamente
— ele só faz `SELECT` e `dispatch()`. Todo HTTP à Clicksign vive dentro de um Job com
`RateLimited('clicksign-webhook')`.

**Quando usar:** sempre que houver mais de 1 chamada HTTP potencial por execução de um comando
`dailyAt` — o rate limiter só protege chamadas que passam pelo middleware de Job; um `foreach`
dentro de `handle()` de um Command não é limitado por nada.

**Por quê (achado desta pesquisa, não estava no CONTEXT):** `RateLimiter::for('clicksign-webhook')`
em `AppServiceProvider.php` está em **3/min GLOBAL** (não os 20/min brutos medidos no sandbox —
ver comentário da própria classe: cada evento processado custa 2 chamadas, 3×2=6, com folga para
`clicksign-envelope` no mesmo minuto). Um dia com, por exemplo, 8 contratos parados em
`aguardando_assinaturas` faria o comando estourar o limite se chamasse `consultarEnvelope()` em
laço direto — o próprio SDK da Clicksign devolveria 429 e a `enviar()` do client já teria
retentado 3× cada, piorando o problema. Despachando 1 job por contrato, a fila absorve o
throttling naturalmente (o mesmo comportamento que já protege `ProcessarEventoClicksignJob` contra
uma rajada de webhooks).

### Pattern 2: Alerta e reconciliação leem o MESMO recorte de "preso", mas com filtros diferentes

**O quê:** REDE-02 (alerta) varre `contrato_assinaturas` em 7 estados possíveis, sem HTTP.
REDE-04 (reconciliação) varre um subconjunto MUITO menor (`aguardando_assinaturas` +
`assinado`/PDF-pendente) e SÓ ESSE subconjunto faz HTTP. A lista de "empresas presas" da tela de
liberação manual (D-10) deve consumir a MESMA query do alerta (7 estados), não a da reconciliação
— senão a tela de liberação manual escondería `recusado`/`expirado`/`erro`, exatamente os casos em
que o admin mais precisa agir.

### Anti-Patterns to Avoid

- **Reimplementar o lock de `EmpresaOperacionalRouter`:** o SC4 já está resolvido — qualquer
  tentativa de adicionar um segundo `Cache::lock()` na rota de liberação manual ou no job de
  reconciliação duplicaria a trava e criaria dois lugares para errar (exatamente o que o docblock
  de `liberarEmpresa()` já avisa).
- **Ler `$evento->payload` dentro do job de reconciliação:** não existe evento por trás da
  reconciliação — ela SEMPRE decide por `consultarEnvelope()` reconsultado, nunca por payload
  antigo. Copiar `GateLiberacaoOperacionalService::avaliar()` é seguro porque ele já é puro e não
  depende de evento nenhum.
- **Chamar `ClicksignClient` de dentro do comando `dailyAt` diretamente:** ver Pattern 1.
- **Misturar `CLICKSIGN_LEMBRETE_DIAS` (reminder nativo da Clicksign pro signatário) com o
  intervalo de repetição do alerta D-04 (para a equipe ECF):** públicos e propósitos diferentes.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Lock contra corrida webhook×manual×reconciliação | `Cache::lock()` próprio na rota/job desta fase | `EmpresaOperacionalRouter::lockDaEmpresa()` (herdado via `liberarEmpresa()`) | SC4 já resolvido na Fase 129 (CR-01, commit `f50e123c`); reimplementar cria 2º ponto de falha |
| Decisão "pode liberar?" | Reescrever a lógica de estado agregado/contratante-pendente | `GateLiberacaoOperacionalService::avaliar()` (puro, testado) | Encapsula o Pitfall A medido (envelope `closed` com assinatura parcial) |
| Link de download do PDF assinado | Guardar/reusar link de payload antigo | Sempre `ClicksignClient::consultarDocumento()` na hora (D-12 da Fase 129) | Link S3 expira em 5 min (`X-Amz-Expires=300`, medido) — link velho falha 100% |
| Throttling de chamadas à Clicksign | `sleep()`/contador manual dentro do comando | `RateLimited('clicksign-webhook')` no Job | Bucket já existe, já é usado por 2 outros jobs da mesma integração |

**Key insight:** esta fase inteira é composição — toda regra de negócio "difícil" (idempotência,
lock, decisão de liberação, link fresco) já foi resolvida e testada na Fase 129. O trabalho novo é
só "quando disparar" (agendamento, threshold de idade, cooldown de alerta) e "quem pode disparar
manualmente" (rota admin).

## Common Pitfalls

### Pitfall 1: Rascunho expira em 7 dias na Clicksign — e nada na API avisa

**O que dá errado:** um contrato em `rascunho` (nunca ativado — Fase 127 D-02 deixa o envio para o
Comercial) some da Clicksign depois de 7 dias (achado só visível na INTERFACE do sandbox, não em
nenhuma resposta de API — `CLICKSIGN-SANDBOX-EMPIRICO.md` §11.2). Depois disso,
`clicksign_envelope_id` no nosso banco aponta para um envelope que já não existe (`GET
/envelopes/{id}` → 404, mesmo comportamento do descarte medido em §9.2).

**Por que acontece:** rascunho é justamente o estado que a D-02 da Fase 127 CRIA de propósito
(parar antes do envio para o Comercial revisar) — é o caso comum, não exceção.

**Como evitar:** o default de `rede_alerta_dias_fixo` (pergunta 3) TEM que ser menor que 7 —
`5` já respeita isso com folga de 2 dias. Se o plano escolher outro valor, validar contra este
limite. **Fora do escopo desta fase (por decisão explícita — "escopo da varredura ≠ escopo do
alerta"):** confirmar via reconsulta que um rascunho preso ainda existe na Clicksign (isso exigiria
`GET /envelopes/{id}` também para rascunho, que a D-06/D-07 não cobre). O alerta idade-based ainda
funciona sem essa confirmação — só não distingue "rascunho vivo esperando revisão" de "rascunho já
apagado pela Clicksign".

**Warning signs:** se a Fase 131 (tela definitiva) um dia tentar reativar um rascunho velho e
receber 404 da Clicksign, é este cenário.

### Pitfall 2: `ContratoAssinaturaEvento::STATUS_ERRO` não é o mesmo sinal que `ContratoAssinatura::STATUS_ERRO`

**O que dá errado:** ler só `ContratoAssinatura.status == 'erro'` para achar "job morreu" (D-05)
PERDE o caso em que `ProcessarEventoClicksignJob` morreu de vez (D-11 da Fase 129) — porque esse
job morto NÃO altera `ContratoAssinatura.status` (ele só marca o EVENTO,
`contrato_assinatura_eventos.status = 'erro'`, deixando o contrato visivelmente parado em
`aguardando_assinaturas`).

**Por que acontece:** são dois sinais de erro DIFERENTES por desenho: `ContratoAssinatura::STATUS_ERRO`
é setado por `GerarContratoAssinaturaJob` (falha na CRIAÇÃO do envelope, Fase 127);
`ContratoAssinaturaEvento::STATUS_ERRO` é setado por `ProcessarEventoClicksignJob::failed()`
(falha no PROCESSAMENTO de um evento já recebido, Fase 129).

**Como evitar:** o gate de idade da D-03/D-05 já cobre este caso de forma indireta — um contrato
`aguardando_assinaturas` cujo processamento morreu simplesmente fica "velho demais" pela mesma
regra que pega qualquer outro atraso, então NÃO precisa de um ramo especial para "job morreu"
funcionar corretamente. **Opcional, refinamento de mensagem** (não requisito): a mensagem do
alerta pode enriquecer citando "há um evento de webhook que falhou ao processar para este
contrato" quando existir `ContratoAssinaturaEvento::where('contrato_assinatura_id',
$contrato->id)->where('status', 'erro')->exists()` — mais acionável para quem recebe o alerta, mas
não é necessário para o alerta disparar corretamente.

**Boa notícia estrutural:** a reconciliação (D-07) conserta este caso automaticamente mesmo sem
saber que ele existe — porque ela reconsulta e reavalia `GateLiberacaoOperacionalService::avaliar()`
DIRETO, sem depender de `ProcessarEventoClicksignJob` ter rodado com sucesso nenhuma vez.

### Pitfall 3: Rate limit `clicksign-webhook` é 3/min GLOBAL, não por contrato

Já detalhado no Pattern 1 acima — repetido aqui porque é o achado com maior potencial de causar
bug sutil em produção (falha só aparece quando há MAIS de ~3 contratos presos no mesmo dia,
cenário que os testes com poucos registros não exercitam).

### Pitfall 4: Migrations aditivas ainda seguem as 3 armadilhas de MariaDB do projeto

Mesmo migrations pequenas e sem FK (como `ultimo_alerta_em`) devem seguir a disciplina já
documentada e usada em `2026_08_14_100002_add_pdf_assinado_erro_...php`:
1. `string()`/`text()`/`timestamp()`, nunca `enum()` de banco — CHECK do MariaDB derruba o SQLite
   dos testes.
2. FK `nullOnDelete()` sem `->nullable()` na coluna → erro 1830 no MariaDB (invisível no SQLite).
   Não se aplica a `ultimo_alerta_em` (sem FK), mas SE a opção 2 da pergunta 7 (`motivo_slug`) for
   escolhida, verificar se alguma FK nova é necessária — não é, `motivo_slug` é string livre.
3. Nome de índice/constraint acima de 64 caracteres → erro 1059, falha SILENCIOSA (cria a coluna
   mas não o índice, migration fica `Pending`). Nenhuma das duas migrations desta fase precisa de
   índice novo (não são critério de busca) — mas se o plano decidir indexar `ultimo_alerta_em`
   para a query do alerta, nomear o índice à mão, prefixo curto (ex.: `ca_ultimo_alerta_idx`).

## Code Examples

### Reconciliação reusando o gate de liberação (padrão a copiar)

```php
// Fonte: app/Jobs/ProcessarEventoClicksignJob.php linhas 148-149, 197-220 — padrão a REUSAR,
// não reinventar, dentro de ReconciliarContratoClicksignJob::handle()
$envelope = $client->consultarEnvelope($contrato->clicksign_envelope_id);

if (filled($contrato->clicksign_document_id)) {
    $eventosDoc = $client->listarEventosDoDocumento($contrato->clicksign_envelope_id, $contrato->clicksign_document_id);
    $sync->aplicar($contrato, $eventosDoc);
}

$veredito = $gate->avaliar($contrato, $envelope);

if ($veredito['liberar'] === true) {
    $contrato->status      = ContratoAssinatura::STATUS_ASSINADO;
    $contrato->assinado_em = /* ... mesmo cálculo de max() sobre signatário contratante ... */;
    $contrato->save();

    $router->liberarEmpresa($contrato->company, $contrato->servico, ContratoLiberacao::VIA_RECONCILIACAO, contrato: $contrato);
}
```

### Job de reconciliação — middleware idêntico aos jobs irmãos

```php
// Fonte: app/Jobs/ProcessarEventoClicksignJob.php linhas 79-87 — mesmo bucket, adaptar a chave
public function middleware(): array
{
    return [
        (new WithoutOverlapping('clicksign-reconciliar-' . $this->contratoAssinatura->id))->releaseAfter(10),
        new RateLimited('clicksign-webhook'), // MESMO bucket de ProcessarEventoClicksignJob/BaixarPdfContratoAssinadoJob — 3/min global
    ];
}
```

### Query do alerta (D-03/D-04/D-05 combinados)

```php
$candidatos = ContratoAssinatura::whereIn('status', ContratoAssinatura::STATUS_TODOS)
    ->where(function ($q) {
        $q->whereNull('liberado_em'); // "sem liberação", D-05 — inclui assinado-sem-liberação
    })
    ->get()
    ->filter(function (ContratoAssinatura $c) {
        $dataBase   = match ($c->status) {
            ContratoAssinatura::STATUS_RASCUNHO => $c->created_at,
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS => $c->enviado_em ?? $c->created_at,
            ContratoAssinatura::STATUS_ASSINADO => $c->assinado_em ?? $c->updated_at,
            default => $c->updated_at, // recusado/expirado/erro/cancelado
        };
        $limiarDias = min(
            (int) Configuracao::get('rede_alerta_dias_fixo', 5),
            (int) round($c->prazoDiasEfetivo() * (float) Configuracao::get('rede_alerta_fracao_prazo', 0.5))
        );
        return $dataBase->diffInDays(now()) >= $limiarDias;
    })
    ->filter(function (ContratoAssinatura $c) {
        $intervalo = (int) Configuracao::get('rede_alerta_repeticao_dias', 3);
        return $c->ultimo_alerta_em === null || $c->ultimo_alerta_em->lt(now()->subDays($intervalo));
    });
```
*(Nota: filtro em memória via `->filter()`, não `->where()` de banco — o volume de
`contrato_assinaturas` desta fase é pequeno o suficiente (dezenas, não milhares) para não exigir
SQL otimizado; se o volume crescer, mover a lógica de `prazoDiasEfetivo()` para o banco exigiria
desnormalizar `prazo_dias` no cálculo, o que não vale a complexidade agora.)*

## State of the Art

Não aplicável a este domínio — não há mudança de ferramenta/versão externa. Toda "evolução" é
interna ao próprio projeto (Fases 124→129→130 compondo o mesmo subsistema).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `rede_alerta_dias_fixo = 5` como default | Pergunta 3 | Baixo — é `Configuracao`, ajustável sem deploy; só precisa ficar abaixo de 7 (Pitfall 1) |
| A2 | `rede_alerta_fracao_prazo = 0.5` como default | Pergunta 3 | Baixo — mesma mitigação (config ajustável) |
| A3 | `rede_alerta_repeticao_dias = 3` como default do cooldown D-04 | Pergunta 4 | Baixo — mesma mitigação |
| A4 | Data base "recusado/expirado/erro/cancelado" = `updated_at` (sem coluna própria) | Pergunta 3 | Médio — se algum outro código também atualizar `updated_at` sem mudar `status` (ex.: correção manual de outro campo), a idade calculada fica errada; nenhum código hoje faz isso, mas vale checar no plano se `save()` de outro fluxo pode tocar o contrato sem mudar status |
| A5 | Coluna nova `ultimo_alerta_em` em `contrato_assinaturas` (não tabela própria) | Pergunta 4 | Baixo — migration aditiva reversível, mesmo padrão já usado 2x na Fase 129 |
| A6 | `motivo_slug` como coluna nova em `contrato_liberacoes` (opção 2 da pergunta 7) | Pergunta 7 | Baixo — aditiva; se o plano preferir a opção 1 (concatenar em texto), zero migration, só perde a capacidade de `GROUP BY` limpo |
| A7 | Query do alerta reusa o mesmo recorte para a tela de liberação manual (D-10) | Pattern 2 | Baixo — é composição, não decisão de schema; plano pode decidir extrair um Service compartilhado |
| A8 | Interpretar "ATIVOS" da D-02 como aplicável também a admins (não só comerciais) | Pergunta 5 | Baixo — divergência mínima do texto literal, sinalizada explicitamente; se o usuário quiser admins inativos incluídos, é trocar 1 `where()` |

## Open Questions

1. **Cenário B da pergunta 2 (cron do SO inteiramente morto) fica de fato fora de escopo?**
   - O que sabemos: nenhuma verificação agendada pelo próprio Laravel consegue detectar isso —
     é uma limitação estrutural, não de implementação.
   - O que é incerto: se o usuário considera este cenário aceitável de ficar descoberto (dado que
     outros ~20 comandos agendados quebrariam junto e provavelmente seriam notados por outra via),
     ou se quer o mecanismo mais robusto (check via `HandleInertiaRequests`, fora do escopo natural
     desta fase).
   - Recomendação: seguir com o comando `dailyAt` duplo (cobre o cenário A, muito mais provável) e
     documentar a limitação no plano — não implementar o mecanismo de middleware nesta fase, é
     escopo maior do que o resto da fase sugere.

2. **Categoria nova no enum `Categoria` ou reusar `MANUAL`?**
   - O que sabemos: `EmpresaHubspotPendenteNotification` (cenário análogo) usa `MANUAL`.
   - O que é incerto: se o time quer distinguir estatisticamente "contrato preso" de outras
     notificações manuais na UI/relatórios futuros.
   - Recomendação: reusar `MANUAL` nesta fase (consistente com o precedente, zero mudança de
     schema) — trocar por categoria dedicada é migration trivial se precisar depois.

## Environment Availability

Não aplicável — nenhuma dependência externa nova. `ClicksignClient`, filas, cache e banco já estão
configurados e em uso pela Fase 129 (mesmo `.env`, mesmas credenciais sandbox/produção).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), `phpunit.xml` já configurado |
| Config file | `phpunit.xml` (`CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`) |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=Phase130` |
| Full suite command | `C:\xampp\php\php.exe artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REDE-04 | Reconciliação corrige divergência (webhook nunca chegou, envelope já `closed`) | Feature | `--filter=ReconciliacaoDivergenciaTest` | ❌ Wave 0 |
| REDE-04 | Reconciliação redispara PDF pendente sem duplicar | Feature | `--filter=ReconciliacaoPdfPendenteTest` | ❌ Wave 0 |
| REDE-04 | Reconciliação NÃO reconsulta contratos fora do escopo (`recusado`, `rascunho`) | Feature | `--filter=ReconciliacaoEscopoTest` | ❌ Wave 0 |
| REDE-02 | Alerta dispara para os 7 estados de "preso" com gatilho "o que vier primeiro" | Feature | `--filter=AlertaContratoPresoTest` | ❌ Wave 0 |
| REDE-02 | Alerta respeita cooldown (D-04) — não repete antes do intervalo | Feature | `--filter=AlertaCooldownTest` | ❌ Wave 0 |
| REDE-02 | Audiência = admin ∪ comercial ativo (D-02), NÃO usa `lideresEPermissionados()` | Feature | `--filter=AudienciaRedeSegurancaTest` | ❌ Wave 0 |
| REDE-03/DADOS-05 | Liberação manual grava autor+motivo, usa `liberarEmpresa()` | Feature | `--filter=LiberacaoManualTest` | ❌ Wave 0 |
| REDE-03 | Liberação manual funciona mesmo em `recusado` (D-11), exibe estado real | Feature | `--filter=LiberacaoManualEstadoRealTest` | ❌ Wave 0 |
| SC4 (roadmap) | Corrida manual×webhook não duplica `MlbEmpresa` | Feature | `--filter=LiberacaoManualCorridaTest` (adaptado de `LiberarEmpresaCorridaConcorrenteTest`) | ❌ Wave 0 (adapta existente) |
| D-09 | Comando grava carimbo; comando de verificação acusa ausência | Feature | `--filter=AutoMonitoramentoCarimboTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase130`
- **Per wave merge:** `php artisan test` (suíte cumulativa completa — já em ~350+ testes das Fases anteriores)
- **Phase gate:** Full suite green antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php`
- [ ] `tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php`
- [ ] `tests/Feature/Phase130/AlertaContratoPresoTest.php`
- [ ] `tests/Feature/Phase130/AlertaCooldownTest.php`
- [ ] `tests/Feature/Phase130/AudienciaRedeSegurancaTest.php`
- [ ] `tests/Feature/Phase130/LiberacaoManualTest.php`
- [ ] `tests/Feature/Phase130/LiberacaoManualCorridaTest.php`
- [ ] `tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php`
- [ ] Framework: nenhum install — PHPUnit já configurado, nenhuma mudança de `phpunit.xml` necessária

**Disciplina do projeto a repetir nos testes desta fase:** conferir consolidação por RECONSULTA
AO BANCO (`ContratoLiberacao::where(...)->count()`, `MlbEmpresa::where(...)->count()`,
`Configuracao::get(...)` fresco), nunca por stdout do comando — mesmo princípio já registrado nas
memórias do projeto para consolidação de desempenho/bônus.

## Security Domain

> `security_enforcement` não está definido em `.planning/config.json` como `false` — tratado como
> habilitado.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não (herdado — sessão já existe) | `auth` middleware, já aplicado em todas as rotas admin do projeto |
| V3 Session Management | não (herdado) | Sessão Laravel padrão, já configurada |
| V4 Access Control | **sim** | `role:admin` middleware (`EnsureUserHasRole`) na rota de liberação manual — mesmo controle usado por `ContratoPdfAssinadoController` |
| V5 Input Validation | **sim** | `$request->validate([...])` no controller — `company_id`/`servico_id` via `exists:`, `motivo_slug` via `Rule::in()` fechado, `motivo_detalhe` obrigatório |
| V6 Cryptography | não | Nenhum dado criptografado nesta fase (o HMAC do webhook é escopo da Fase 129, já fechado) |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Admin sem `role:admin` acessando rota de liberação manual | Elevation of Privilege | Middleware `role:admin` na rota (padrão já usado, `EnsureUserHasRole`) |
| Motivo forjado fora da lista fechada (`motivo_slug`) | Tampering | `Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)` na validação — nunca aceitar string livre no slug |
| Reconciliação/alerta expondo dado de signatário (PII) em log | Information Disclosure | Seguir a disciplina já estabelecida em `ClicksignClient::enviar()`/`podarPii()` — nunca logar payload/resposta inteiros, só campos nomeados |
| Liberação manual em massa sem motivo real (abuso do "outro") | Repudiation | `motivo_detalhe` obrigatório mesmo com slug preenchido (D-12) — nunca permitir slug sozinho sem texto |
| IDOR: liberar empresa/serviço arbitrário via manipulação de `company_id`/`servico_id` no POST | Tampering | `exists:companies,id` / `exists:servicos,id` na validação; `liberarEmpresa()` já é idempotente e auditado — mesmo em uso indevido, fica rastreável por `liberado_por_user_id` |

## Sources

### Primary (HIGH confidence — leitura direta do código do repositório)
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — `liberarEmpresa()`, `lockDaEmpresa()`, `aplicarRoteamento()`
- `app/Models/ContratoLiberacao.php` + migration `2026_08_14_100001_create_contrato_liberacoes_table.php`
- `app/Models/ContratoAssinatura.php` + migrations `2026_08_10_100000`, `2026_08_12_100000`, `2026_08_14_100002`
- `app/Services/Contratos/GateLiberacaoOperacionalService.php`
- `app/Jobs/ProcessarEventoClicksignJob.php`, `app/Jobs/BaixarPdfContratoAssinadoJob.php`
- `app/Services/Clicksign/ClicksignClient.php`
- `app/Models/Configuracao.php` + migrations de seed (`polo_limiares`)
- `app/Notifications/BaseNotification.php`, `EmpresaHubspotPendenteNotification.php`, `Categoria.php`
- `app/Support/AudienciaComercial.php`
- `app/Models/User.php`, `app/Models/Setor.php`
- `app/Providers/AppServiceProvider.php` (rate limiters `clicksign-envelope`, `clicksign-webhook`)
- `app/Http/Controllers/ContratoPdfAssinadoController.php`
- `routes/web.php`, `routes/console.php`
- `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`,
  `ClicksignVerificarAssinaturaCommandTest.php`, `tests/Feature/Phase35HubspotNotifyTest.php`,
  `tests/Feature/Notifications/Phase11AutoTest.php`
- `phpunit.xml`

### Secondary (HIGH confidence — medições empíricas registradas em fases anteriores da mesma milestone)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §7 (link 5 min), §8 (gate #10 fechado), §11.2
  (rascunho expira em 7 dias), §12.3 (rascunho inerte), §12.6 (rajada retroativa), §12.7 (recurso
  desembrulhado)
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md`, `129-VERIFICATION.md`, `129-REVIEW.md`
- `.planning/phases/129-webhook-clicksign-v22-0/129-CONTEXT.md`
- `.planning/REQUIREMENTS-v22.md` §"Decisões travadas (LOCKED)", §"Out of Scope", tabela de gates

### Tertiary (LOW confidence — julgamento de negócio sem medição, marcado em Assumptions Log)
- Nenhuma fonte externa consultada — não houve necessidade de WebSearch/Context7 nesta pesquisa
  (domínio 100% interno ao repositório).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero dependência nova, tudo já em produção nas Fases 124-129
- Architecture: HIGH — todos os pontos de reuso (`liberarEmpresa()`, `GateLiberacaoOperacionalService`,
  `ClicksignClient`) lidos linha a linha, não inferidos
- Pitfalls: HIGH para os achados de código (rate limit, `ContratoAssinaturaEvento` vs
  `ContratoAssinatura` erro); MEDIUM para os defaults de dias/intervalo (julgamento de negócio,
  sinalizado em Assumptions Log)

**Research date:** 2026-08-13
**Valid until:** 30 dias (domínio interno estável; revalidar se a Fase 131 mudar o schema de
`contrato_liberacoes`/`contrato_assinaturas` antes deste plano ser executado)
