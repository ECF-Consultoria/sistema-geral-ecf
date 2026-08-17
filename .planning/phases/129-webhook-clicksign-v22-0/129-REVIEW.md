---
phase: 129-webhook-clicksign-v22-0
reviewed: 2026-08-13T16:59:45Z
depth: deep
files_reviewed: 18
files_reviewed_list:
  - app/Console/Commands/ClicksignVerificarAssinatura.php
  - app/Http/Controllers/Api/ClicksignWebhookController.php
  - app/Http/Controllers/ContratoPdfAssinadoController.php
  - app/Jobs/BaixarPdfContratoAssinadoJob.php
  - app/Jobs/ProcessarEventoClicksignJob.php
  - app/Models/ContratoAssinatura.php
  - app/Models/ContratoAssinaturaEvento.php
  - app/Models/ContratoLiberacao.php
  - app/Providers/AppServiceProvider.php
  - app/Services/Clicksign/ClicksignClient.php
  - app/Services/Clicksign/ContratoSignatariosSyncService.php
  - app/Services/Contratos/GateLiberacaoOperacionalService.php
  - app/Services/Operacional/EmpresaOperacionalRouter.php
  - app/Support/Clicksign/ClicksignHmacVarredura.php
  - database/migrations/2026_08_14_100000_create_contrato_assinatura_eventos_table.php
  - database/migrations/2026_08_14_100001_create_contrato_liberacoes_table.php
  - database/migrations/2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php
  - routes/web.php
findings:
  critical: 1
  warning: 2
  info: 2
  total: 5
status: issues_found
---

# Phase 129: Code Review Report

> **Atualização 2026-08-13 (pós-revisão):** CR-01, WR-02 e IN-01 foram corrigidos
> diretamente (fora de um plano GSD novo, autorizado pelo usuário). Ver a seção
> "Resolução" dentro de cada achado abaixo para os commits. WR-01 e IN-02
> permanecem abertos — dependem do gate humano do plano 129-07 (Task 1), que
> não foi fechado por esta correção nem deveria ser.

**Reviewed:** 2026-08-13T16:59:45Z
**Depth:** deep (leitura completa dos 18 arquivos + rastreamento de chamadas entre `ClicksignWebhookController` → `ProcessarEventoClicksignJob` → `GateLiberacaoOperacionalService`/`ContratoSignatariosSyncService` → `EmpresaOperacionalRouter`, conferência de schema em `mlb_empresas` fora do diff, e cruzamento com `129-GATE.md`/`129-06-SUMMARY.md`/`129-07-SUMMARY.md`/`CLICKSIGN-SANDBOX-EMPIRICO.md`)
**Files Reviewed:** 18
**Status:** issues_found

## Summary

A engenharia central desta fase — validação HMAC (`ClicksignHmacVarredura`), gravação idempotente do
evento bruto (`payload_hash` UNIQUE + `catch` correto por `(string) $e->getCode() === '23000'`, nunca
`errorInfo`), o gate de liberação (`GateLiberacaoOperacionalService`, que trata corretamente o achado
`deadline_partial_signature_action: "closed"` e é fail-closed sem contratante) e a disciplina de nunca
logar segredo/payload/PII — está sólida e corresponde ao que os 10 pontos de escrutínio pediam. Não
encontrei nenhum caminho em que assinatura ausente ou inválida passe, nenhum vazamento de secret/token
em log, exceção ou coluna, e nenhuma leitura remanescente de `['data']['attributes']` sobre o recurso
já desembrulhado que `ClicksignClient::consultarEnvelope()`/`consultarDocumento()` devolvem — os únicos
dois lugares que usam essa forma (`data.attributes.name`/`data.id`) são fallbacks deliberados sobre o
**payload bruto do webhook**, não sobre a reconsulta, e estão documentados como tal.

O achado grave (CR-01) é diferente do que os pontos de escrutínio anteciparam: não é a idempotência de
um único evento/envelope (essa está corretamente protegida por `payload_hash` e por
`cl_empresa_servico_uniq`), é a garantia de **ficha única por empresa** (D-02) quando **dois serviços
diferentes da MESMA empresa** (ex.: Polos + Assessoria, cenário que o próprio código cita nominalmente)
são liberados por **dois envelopes Clicksign diferentes processados por workers de fila realmente
concorrentes** — o `WithoutOverlapping` do job é chaveado por `envelope_id`, não por `company_id`, e o
guard de `MlbEmpresa` em `EmpresaOperacionalRouter` não tem nenhuma trava de banco por trás (ao
contrário de `ContratoLiberacao`, que tem `cl_empresa_servico_uniq`).

Os outros dois achados (WR-01, WR-02) são riscos de robustez, não de segurança: a ordem de resolução do
link de download do PDF assinado nunca foi medida contra uma resposta real (o próprio `129-07-SUMMARY.md`
confirma que o checkpoint humano que provaria isso continua aberto), e a ordenação de eventos do
documento por comparação de STRING em vez de timestamp parseado é frágil a variação de formato que nunca
foi observada, mas também nunca foi descartada.

## Critical Issues

### CR-01: Guard de "ficha única por empresa" (D-02) não sobrevive a liberação concorrente de dois serviços diferentes — sem constraint de banco por trás

**File:** `app/Services/Operacional/EmpresaOperacionalRouter.php:150-159` (guard) e `:203-278`
(`liberarEmpresa()`), em interação com `app/Jobs/ProcessarEventoClicksignJob.php:79-87` (`middleware()`)

**Issue:**

`EmpresaOperacionalRouter::aplicarRoteamento()` decide se cria uma `MlbEmpresa` assim:

```php
foreach ($tipos->values() as $tipo) {
    if ($guardPorEmpresa && MlbEmpresa::where('company_id', $company->id)->exists()) {
        return $criouFicha;
    }
    $this->criarFicha($company, $tipo, $handoff);
    $criouFicha = true;
}
```

Isto é um clássico "check-then-act" sem transação nem lock. O docblock de `liberarEmpresa()` (linhas
190-196) afirma que a garantia "é por RECONSULTA, não por estado em memória" e que isso "sobrevive a
liberações separadas no tempo" — o que é verdade para chamadas **sequenciais** (prova:
`tests/Feature/Phase129/LiberarEmpresaIdempotenteTest.php::liberar_dois_servicos_em_chamadas_separadas_no_tempo_cria_uma_mlbempresa_so`,
cujo próprio nome já diz "separadas no tempo"). Não há nenhuma prova, nem trava, para o caso
**concorrente de verdade**.

O caminho que introduz essa concorrência é exatamente o que esta fase acrescenta:
`ProcessarEventoClicksignJob::middleware()` só serializa por envelope —

```php
$chave = 'clicksign-evento-' . ($this->evento->clicksign_envelope_id ?: 'evento-' . $this->evento->id);
return [(new WithoutOverlapping($chave))->releaseAfter(10), new RateLimited('clicksign-webhook')];
```

— então dois eventos de **envelopes diferentes** da mesma empresa (ex.: contrato de Polos e contrato de
Assessoria, o par que `EmpresaOperacionalRouter::aplicarRoteamento()` já cita nominalmente no docblock
de `rotearCadastro()` como cenário real) podem ser processados por dois workers de fila ao mesmo tempo.
`RateLimited('clicksign-webhook')` é um limitador de vazão (3/min), não um mutex — com mais de um worker
`ecf-worker:*` (o padrão de nome plural em `deploy.sh`/`deploy_vps.sh` sugere `numprocs > 1`), os dois
jobs podem rodar em processos PHP distintos, ao mesmo tempo.

Cada um chama `liberarEmpresa($company, $servicoDiferente, ...)`. Como os `servico_id` são diferentes,
o guard de leitura de `ContratoLiberacao` (`existeParaServico`) não colide, e a constraint real
`cl_empresa_servico_uniq` (por `company_id + servico_id`) também não colide — cada liberação grava sua
própria linha, corretamente. O problema é o passo seguinte: os dois processos chegam quase juntos em
`aplicarRoteamento()`, os dois executam `MlbEmpresa::where('company_id', ...)->exists()` **antes** de
qualquer um dos dois ter commitado a criação, os dois veem `false`, e os dois chamam `criarFicha()` —
duas linhas de `MlbEmpresa` para a mesma empresa.

Confirmei que não existe nenhuma trava de banco atrás dessa garantia: `mlb_empresas.company_id` só tem
FK (`nullOnDelete`), nunca `unique()`
(`database/migrations/2026_05_25_100002_add_company_id_to_mlb_empresas.php:18-19`, fora deste diff mas
é a tabela que este diff passou a escrever de um segundo caminho assíncrono). É exatamente a mesma
classe de corrida que `contrato_liberacoes` resolveu corretamente com `cl_empresa_servico_uniq` — só que
para `mlb_empresas` a trava nunca existiu, porque até esta fase os dois caminhos que criavam
`MlbEmpresa` (`ComercialController::store()`, webhook HubSpot) rodavam dentro de uma única requisição
HTTP síncrona, nunca em dois processos de fila paralelos.

Consequência de uma empresa com duas `MlbEmpresa`: quebra a suposição "uma empresa = uma ficha
operacional" em que telas de implementação, meta por polo e (potencialmente) apuração de bônus
confiam — a mesma classe de problema que este projeto já registrou como caro em incidentes anteriores
de duplicação de registro de empresa.

**Fix:**

Duas opções, não excludentes:

1. Fechar a garantia no nível certo — adicionar um índice único em `mlb_empresas.company_id` (nova
   migration) e envolver o guard + `criarFicha()` numa transação com `lockForUpdate()`:

```php
DB::transaction(function () use ($company, $tipos, $handoff, $guardPorEmpresa, &$criouFicha) {
    foreach ($tipos->values() as $tipo) {
        if ($guardPorEmpresa) {
            $existe = MlbEmpresa::where('company_id', $company->id)->lockForUpdate()->exists();
            if ($existe) {
                return;
            }
        }
        $this->criarFicha($company, $tipo, $handoff);
        $criouFicha = true;
    }
});
```

2. E/ou serializar por empresa em vez de por envelope no job: `WithoutOverlapping('clicksign-empresa-' .
   $contrato->company_id)` além do (ou no lugar do) chaveamento por envelope em
   `ProcessarEventoClicksignJob::middleware()` — mais barato de implementar, mas cobre só este
   chamador; a Fase 130 (liberação manual) vai precisar da mesma disciplina, o que reforça a opção 1
   como a correção estrutural.

Adicionar um teste que force a corrida de verdade (dois `liberarEmpresa()` para serviços diferentes
da mesma empresa dentro de transações concorrentes, ou pelo menos um teste que documente a lacuna se a
correção for adiada).

**Resolução (2026-08-13, commit `f50e123c`):** implementada a opção 2 da lista acima na forma de
`Cache::lock()` por `company_id` (não a opção 1 — o usuário recusou explicitamente a migration de
índice único, porque produção pode já ter `MlbEmpresa` duplicada de antes desta fase e a migration
quebraria o deploy sem forma de verificar localmente; registrado como possível segunda camada
futura). A trava envolve `aplicarRoteamento()` inteiro quando `guardPorEmpresa=true`
(`rotearServico()`/`liberarEmpresa()`); `rotearCadastro()` continua sem lock de propósito (D-08). O
lock vive em `EmpresaOperacionalRouter::lockDaEmpresa()` (`protected`, ponto de extensão), reusável
pela Fase 130 (SC4) sem duplicar a trava por chamador. Funciona com `CACHE_STORE=database` porque a
tabela `cache_locks` já existe (`DatabaseLock`, mutex real via INSERT/UPDATE condicional).
Teste novo: `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php` — prova a corrida
entrelaçando duas chamadas via um decorator na fábrica de lock (PHPUnit é single-thread; não há
paralelismo real de SO disponível nesta stack de teste). Validado manualmente que o teste falha
(2 `MlbEmpresa` em vez de 1) se a trava for removida — não é teste vazio.

## Warnings

### WR-01: Ordem de resolução do link do PDF assinado nunca foi medida contra uma resposta real da Clicksign

**File:** `app/Jobs/BaixarPdfContratoAssinadoJob.php:102-106`

**Issue:**

```php
$link = $documento['links']['files']['signed']
    ?? $documento['attributes']['files']['signed']
    ?? $documento['links']['files']['original']
    ?? $documento['attributes']['files']['original']
    ?? null;
```

As quatro localizações são um palpite defensivo, não um achado medido — o próprio
`129-06-SUMMARY.md` (linha 44) registra isso explicitamente: *"a ordem de resolução do link seguiu
literalmente o texto do plano [...] o 129-GATE.md não registrou a forma exata do bloco de arquivo do
endpoint de documento (só do envelope), então não havia override a aplicar"*. E o `129-07-SUMMARY.md`
confirma que a Task 1 do gate humano (assinar um contrato de teste real e conferir o download do PDF)
**não foi executada** — é o "CHECKPOINT REACHED" com que a Fase 129 fica, na prática, "6/7 plans
executed".

Se nenhuma das quatro chaves bater com a resposta real, `blank($link)` fica sempre verdadeiro, o job
lança `ClicksignException` nas 3 tentativas (`tries = 3`) e cai em `failed()` — o PDF nunca é baixado, e
isso só fica visível em `contrato_assinaturas.pdf_assinado_erro`/log, nunca trava a liberação (D-14
funciona como desenhado), mas o "circuito completo" que o docblock da classe promete continua não
comprovado por tráfego real, só por `Http::fake()` — que o próprio docblock do job (linha 42-47)
alerta que "não prova que a forma do payload/resposta está certa" e cita 5 bugs desta milestone que
nasceram exatamente de fixture confirmando a si mesma.

**Fix:** não é um bug de código a corrigir agora — é uma dependência explícita do checkpoint humano já
identificado (129-07 Task 1) que ainda não fechou. Tratar como bloqueio de "pronto para produção" para
este pipeline específico até que `clicksign:verificar-assinatura`/uma rodada real confirme (ou corrija)
a chave certa; se a Task 1 revelar uma quinta localização, adicionar como novo fallback e registrar a
medição no `129-GATE.md`, mesma disciplina já usada para o HMAC.

### WR-02: Ordenação de eventos do documento por comparação de STRING, não por timestamp parseado

**File:** `app/Services/Clicksign/ContratoSignatariosSyncService.php:70`

**Issue:**

```php
->sortBy(fn (array $evento) => (string) ($evento['attributes']['created'] ?? ''))
```

A classe inteira existe para garantir imunidade a entrega fora de ordem (D-06/D-07, gate #11
permanentemente não medido) — o próprio docblock diz "a ordem que vale é a do `created` da Clicksign".
Comparar como STRING só produz ordem cronológica correta se todos os valores de `created` vierem no
MESMO formato ISO-8601 (mesmo offset, mesma precisão de subsegundo). Não há medição registrada
confirmando isso — os exemplos capturados em `CLICKSIGN-SANDBOX-EMPIRICO.md`/`129-GATE.md` não
documentam o formato exato de `attributes.created` do endpoint de eventos do documento. Se a API algum
dia devolver um evento com `Z` e outro com `-03:00` (ou precisão diferente), a ordenação lexicográfica
quebra silenciosamente e um evento `refusal` mais antigo pode ser aplicado DEPOIS de um `sign` mais
novo, ou vice-versa, sem nenhum erro visível.

**Fix:** ordenar por timestamp parseado em vez de string:

```php
->sortBy(fn (array $evento) => \Illuminate\Support\Carbon::parse($evento['attributes']['created'] ?? '1970-01-01')->getTimestampMs())
```

Baixo custo, remove a suposição de formato por completo.

**Resolução (2026-08-13, commit `0838b8f1`):** aplicado exatamente o fix sugerido acima.

## Info

### IN-01: `failed()` dos dois jobs novos não usa o canal `ecf-webhooks`, diferente de todo o resto do subsistema

**File:** `app/Jobs/ProcessarEventoClicksignJob.php:302`, `app/Jobs/BaixarPdfContratoAssinadoJob.php:190`

**Issue:** todo log deste subsistema (`ClicksignWebhookController`, `ClicksignClient`,
`ContratoSignatariosSyncService`, e os próprios `handle()` destes dois jobs) usa
`Log::channel('ecf-webhooks')`, que é um canal dedicado com arquivo próprio e retenção de 14 dias
(`config/logging.php:135-141`). Os dois `failed()` usam `Log::error(...)` puro (canal padrão), então a
mensagem de "falha definitiva" — o sinal que o docblock de D-11 descreve como o que a Fase 130 (REDE-03)
vai varrer — é a única deste subsistema que não aparece em `ecf-webhooks.log`. Isto copia o padrão de
`GerarContratoAssinaturaJob::failed()` (mesmo comportamento, fase anterior), então não é uma
inconsistência nova inventada aqui, mas os dois jobs novos a herdam. Não afeta o sinal real que a Fase
130 vai consumir (`erro_msg`/`pdf_assinado_erro` são colunas do banco, gravadas corretamente em
`failed()`) — é puramente uma questão de triagem por log humano.

**Fix:** `Log::channel('ecf-webhooks')->error(...)` nos dois `failed()`, por consistência com o resto do
arquivo e do subsistema.

**Resolução (2026-08-13, commit `4d0a596f`):** aplicado exatamente o fix sugerido acima nos dois
`failed()` (`ProcessarEventoClicksignJob`, `BaixarPdfContratoAssinadoJob`).

### IN-02: Gate humano ponta a ponta (Plano 129-07, Task 1) segue aberto no fim deste diff

**File:** N/A — status do processo, não linha de código

**Issue:** conforme `129-07-SUMMARY.md`, a prova real do circuito completo (assinar um contrato de
teste de verdade, conferir liberação real + download real do PDF, recusar um segundo contrato, e
tentar observar um prazo vencendo) **não foi executada**. O que existe hoje é: (a) suíte automatizada
com `Http::fake()` cobrindo a fiação, e (b) uma pré-verificação sintética do receiver de produção
(200/401/401-sem-duplicar) contra tráfego HTTP real, mas com um payload sintético que não casa com
nenhum contrato real. Isto não é um defeito de código — é uma lacuna de validação já auto-registrada
pelo executor — mas junto com WR-01 (link do PDF nunca medido), significa que o caminho "assinatura real
→ liberação real → PDF real dentro do sistema" continua sem uma única execução ponta a ponta provada
contra a API real da Clicksign.

**Fix:** nenhuma ação de código. Sinalizar para quem for decidir se este diff pode receber tráfego real
de produção: o checkpoint humano do plano 129-07 precisa fechar antes disso.

---

_Reviewed: 2026-08-13T16:59:45Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
