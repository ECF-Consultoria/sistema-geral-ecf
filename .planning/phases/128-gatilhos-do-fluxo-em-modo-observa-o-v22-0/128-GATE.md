# Fase 128 — Gate (plano 128-06)

**Preparado em:** 2026-08-12 (Task 1 — roteiro + suítes).
**Medido em:** 2026-08-12 (Task 2 — autorização "aprovado", ambiente LOCAL, MariaDB real).
**Status geral:** as 3 medições foram feitas contra o sandbox Clicksign real. Um bug de produção
real foi encontrado e corrigido durante a medição (não estava coberto por nenhum teste porque
`Http::fake()` não teria pego — é justamente o motivo desta fase existir).

> Molde: `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-GATE.md`.
> Regra de anonimização: nenhum token/secret entra neste documento (T-128-14).
> Identificadores/e-mails/nomes abaixo são de teste (`@example.com`, "ECF Teste Sandbox" etc.) —
> nenhum dado de cliente real foi enviado.

## Ambiente da medição

- MariaDB local reparado pelo orquestrador (ver contexto do agente) e com as 94 migrations
  pendentes aplicadas — banco real, não SQLite de teste.
- `QUEUE_CONNECTION=sync` — `GerarContratoAssinaturaJob` roda inline, sem worker.
- `CLICKSIGN_ENV=sandbox`, `CLICKSIGN_BASE_URL=https://sandbox.clicksign.com/api/v3` — confirmado
  antes de qualquer requisição.
- **Mecânica da medição:** a rota `POST /api/webhooks/hubspot` foi exercitada via
  `$kernel->handle($request)` (o mesmo kernel HTTP que processa qualquer request real da aplicação
  — é a técnica que `TestCase::call()` usa por baixo), com HMAC calculado com o mesmo algoritmo do
  controller. **HubSpot foi mockado** (`Http::fake()` só em `api.hubapi.com/*`) porque está **fora
  do escopo desta fase** (128-CONTEXT.md, `<o_que_ja_esta_pronto_nao_reconstruir>`) — não existe deal
  fictício na conta real do HubSpot para acionar, e criar um poluiria o CRM de produção. **A
  Clicksign NUNCA foi mockada** — toda chamada `[Clicksign]` citada abaixo é uma requisição HTTP real
  contra o sandbox, sem `Http::fake()` no meio.
- Gate 3 (empresa só-Polos) foi cadastrado pelo caminho do Comercial invocando
  `ComercialController::store()` diretamente (mesma validação, mesma transaction, mesmo
  `EmpresaOperacionalRouter`), autenticado por um usuário admin de teste criado só para o gate e
  apagado na limpeza.
- Todas as empresas fictícias, contratos, fichas `MlbEmpresa`, eventos HubSpot e o usuário admin de
  teste foram **apagados ao final** (ver `## Limpeza` no fim do documento). A única mudança de dado
  que ficou foi `servicos.clicksign_template_id` do serviço "Gestão" — configuração real (aponta
  para o modelo `.docx` já cadastrado no sandbox), não dado fictício.

---

## Suítes automatizadas — comparadas por NOME de teste, não por contagem

A suíte completa do projeto tem falhas pré-existentes não relacionadas (~117), então comparar por
contagem total enganaria. Comparação é feita por baseline nomeada, igual ao critério de verificação
do plano.

| Suíte | Resultado | Detalhe |
|---|---|---|
| `--filter=Phase128` | ✅ **33 passed** (87 assertions) | `ExigeContratoTest` (5), `GatilhoContratoComercialTest` (2), `GatilhoContratoHubspotTest` (3), `GatilhoContratoPendenciaTest` (6), `InvarianteRoteamentoTest` (4), `PendenciasUniversaisTest` (7), `ReavaliacaoAutomaticaTest` (6) |
| `--filter=Phase125` | ✅ 31 passed (123 assertions) | baseline, sem regressão |
| `--filter=Phase126` | ✅ 117 passed (379 assertions) | baseline, sem regressão |
| `--filter=Phase127` | ✅ 66 passed (221 assertions) | baseline, sem regressão |
| `--filter=Phase124` | ✅ 16 passed (65 assertions) | baseline, sem regressão (`Phase124RegressaoComercialTest`, `Phase124RegressaoHubspotTest`, gatilho do interruptor) |

**Baseline Phase125+126+127 = 31 + 117 + 66 = 214 passed** — bate com o número declarado no plano
(`<verification>`). Zero regressão líquida em nenhuma das 5 suítes.

Todas as 5 suítes rodam em SQLite (banco de teste), sem `Http::fake()` para os pontos que a D-01 a
D-05 exigem medição, mas **sem tocar o sandbox Clicksign real** — a chamada real é exclusiva do
Gate 1, abaixo, e é isso que a Task 2 autoriza.

---

## Gate 1 — envelope REAL no sandbox pelo caminho do webhook (SC1)

**Roteiro:**
1. Confirmar `CLICKSIGN_API_TOKEN`/`CLICKSIGN_ENV`/`CLICKSIGN_BASE_URL` no `.env` apontando para
   `https://sandbox.clicksign.com/api/v3` — nunca produção.
2. Disparar um **POST real** na rota do webhook HubSpot (`HubspotWebhookController`) com payload de
   uma empresa fictícia cujo serviço exige contrato (`exige_contrato = true`, ex. `Gestão`) e cujos
   dados mínimos estão completos (e-mail, CNPJ, nome de contato).
3. Rodar o worker da fila (`php artisan queue:work --once` ou equivalente) para processar
   `GerarContratoAssinaturaJob`.
4. Registrar:
   - id do envelope criado no sandbox (Clicksign);
   - `ContratoAssinatura.id` local e `status` resultante (`rascunho` esperado, D-02 da Fase 127 —
     o fluxo não ativa sozinho);
   - resposta bruta relevante da Clicksign (sem token/secret/header de autorização — T-128-14);
   - quantas chamadas HTTP foram consumidas da janela medida de 20/min.

**RESULTADO: ✅ MEDIDO — envelope real criado no sandbox.**

### ⚠️ Achado do gate — bug real de produção, corrigido (Rule 1)

A **primeira tentativa** (empresa fictícia, deal 900128061, servico "Gestão") criou a `Company` e o
`ContratoServico` normalmente, mas o job **crashou antes de fazer qualquer chamada HTTP à
Clicksign**:

```
TypeError: App\Services\ContratoPdfService::formatarData(): Argument #1 ($data) must be of type
string, null given, called in .../ContratoPdfService.php on line 135
```

**Causa:** `ContratoServico` legado (fluxo sem line items, `data_vencimento => null` — "prazo
indeterminado" é caso **legítimo**, `ContratoDadosMinimosService::faltantes()` item 5 não reprova
isso). `ContratoClicksignService` grava esse `null` fielmente no `servicos_snapshot` congelado.
`ContratoPdfService::montarServicos()`/`montarVigencia()` assumiam `data_vencimento` sempre
presente e quebravam com `TypeError` — **todo contrato de prazo indeterminado (o caso mais comum de
assinatura recorrente) quebrava o job**, sempre, em qualquer ambiente, não só neste gate.

Nenhum teste da Fase 126/127/128 pegava isso porque nenhuma fixture usava `data_vencimento: null`
no snapshot — o `Http::fake()` das suítes de fiação nunca chega perto desse código porque o erro
acontece **antes** de qualquer chamada Clicksign, dentro de `ContratoVariaveisModeloService::montar()`.

**Fix** (`app/Services/ContratoPdfService.php`): `formatarData()` passou a aceitar `?string`;
`null`/`''` viram o texto visível `"Indeterminado"` (nunca `TypeError`, nunca branco silencioso —
mesma exigência do placeholder `A DEFINIR`). `montarVigencia()`: se **qualquer** serviço do snapshot
tem vencimento indeterminado, a vigência do CONJUNTO fica indeterminada (não dá pra apurar "a maior
data" quando uma delas não existe). 2 testes de regressão adicionados em
`tests/Feature/Phase126/ContratoPdfDadosTest.php` (12 passed, antes 10). Suíte completa após o fix:
Phase126 119 passed (antes 117), Phase127 66 passed, Phase128 33 passed — zero regressão.

**2ª tentativa** (empresa nova, deal 900128062, servico "Gestão"): passou do bug de `data_vencimento`,
mas a Clicksign **recusou de verdade** o signatário cliente: `400 bad_request`, pointer
`/data/attributes/name`, detalhe `"name não está em um formato válido"` — o nome de teste
("Beltrano Gate12806R2") tinha dígitos no sobrenome. **Achado novo, não documentado em nenhuma
pesquisa anterior:** a Clicksign valida o formato do campo `name` do signatário e recusa dígitos.
Corrigido o dado de teste (nome sem dígito) e reenviado — passou do cliente, mas quebrou nos
signatários fixos da ECF: `'Socio Um ECF (teste sandbox gate 128-06)'` tem dígitos/parênteses →
mesmo erro. Corrigido para nomes só com letras e espaços.

**3ª tentativa: sucesso.** `dispararSeElegivel()` → `status: disparado` → `ContratoAssinatura`
criado com envelope real:

```
contrato_id=6 | status=rascunho | envelope_id=aea3c36b-6f4f-40a3-a1dc-5be2422cb93f
             | document_id=664544e7-fa4c-4b42-a60e-ba09b8dbb76e
```

**Reconsulta na API (`GET /envelopes/{id}`):**

| Campo | Observado | Esperado |
|---|---|---|
| `status` | `draft` | `draft` (D-02 — não ativamos) ✅ |
| `deadline_at` | `2026-09-11T16:35:55-03:00` | hoje + 30 dias (default, `prazo_dias` não informado) ✅ |
| `remind_interval` | `3` | 3 (default) ✅ |
| `name` | `Contrato — Gestão — Empresa Ficticia Gate 128-06 Rodada 2` | ✅ |

**Rodada combinada (Gate 1 + Gate 2 na MESMA empresa/medição, deal 900128064, 2 line items —
"Gestão" + "Assessoria"):** 2 envelopes reais na mesma empresa fictícia (id 14):

```
contrato #7 | servico=Gestão (exige contrato)      | envelope=a8b25922-5a4b-4dde-a409-7082fb6dfb24
contrato #8 | servico=Assessoria (exige contrato)   | envelope=d0e46b0a-7ebb-463c-bc78-844c3e6dc737
```

⚠️ **Achado colateral (não é bug — limitação do `QUEUE_CONNECTION=sync` deste ambiente):** o job do
2º contrato (`i=1`, `->delay(5s)`) não rodou automaticamente dentro do mesmo request — ficou
`rascunho` sem envelope e sem erro. Investigado: `WithoutOverlapping('clicksign-envelope-global')`
não conseguiu o lock (o 1º job ainda segurava, ~19s de duração) e chamou `$job->release(5)`; em
`SyncJob::release()` isso só marca `released = true` — **não** há fila real para reagendar, então o
job simplesmente não roda (nem erro, nem retry). Confirmado processando o job manualmente
(`$job->handle(...)`) — funcionou de primeira e gerou o 2º envelope acima. **Em produção
(`QUEUE_CONNECTION=database` + `queue:work`), o `release()` reagenda de verdade e o worker
reprocessa em segundos — este é um artefato exclusivo de rodar sem worker, não um bug de código.**
Registrado como ponto de atenção para quem for medir throughput de contrato em lote.

### Chamadas HTTP consumidas da janela de 20/min (T-128-15)

| Rodada | Resultado | Chamadas Clicksign |
|---|---|---|
| 1ª (bug local, TypeError antes de qualquer HTTP) | reprovada | 0 |
| 2ª (nome do cliente com dígito) | reprovada (400 real) | 4 (criar envelope, anexar documento, signatário recusado, cancelar) |
| 3ª (nomes ECF com dígito/parênteses) | reprovada (400 real) | 7 |
| 4ª (sucesso — contrato #6) | ✅ aprovada | 14 |
| Combinada (contrato #7) | ✅ aprovada | 14 |
| Job manual (contrato #8) | ✅ aprovada | 14 |
| Reconsulta (`GET /envelopes`) | — | 1 |
| **Total da sessão** | | **56**, distribuídas em ~20 min, pico de 14/min — nunca perto do limite de 20/min (nenhum 429) |

**Veredito Gate 1: ✅ Success Criteria 1 atendido.** Envelope real criado no sandbox pelo caminho do
webhook (medido via kernel HTTP real + HMAC real; só a chamada HubSpot, fora do escopo desta fase,
foi mockada). 3 envelopes reais distintos confirmam repetibilidade, não sorte de uma execução.

---

## Gate 2 — a empresa continua indo ao operacional na mesma hora (SC4)

**Roteiro:** a evidência automatizada é `tests/Feature/Phase128/InvarianteRoteamentoTest.php`
(plano 04) — já executada acima dentro de `--filter=Phase128` (4 testes, todos verdes):
- `cadastro comercial com pendência roteia mas não gera contrato`
- `cadastro via webhook hubspot com pendência roteia mas não gera contrato`
- `falha do gate não desfaz o cadastro comercial nem o roteamento`
- `interruptor de emergência continua desligado após todos os cenários`

Na mesma rodada de medição do Gate 1 (empresa fictícia real, não fixture), registrar:
- o `MlbEmpresa.id` criado para a empresa fictícia usada no Gate 1;
- o intervalo entre `companies.created_at` e `mlb_empresas.created_at` (prova de que o roteamento
  aconteceu "na mesma hora", não em rotina posterior).

**RESULTADO (saída da suíte automatizada, já medida):**

```
PASS  Tests\Feature\Phase128\InvarianteRoteamentoTest
✓ cadastro comercial com pendencia roteia mas nao gera contrato        0.21s
✓ cadastro via webhook hubspot com pendencia roteia mas nao gera contrato  0.25s
✓ falha do gate nao desfaz o cadastro comercial nem o roteamento       3.26s
✓ interruptor de emergencia continua desligado apos todos os cenarios  1.63s
```

**RESULTADO: ✅ MEDIDO — MlbEmpresa criada na mesma rodada do Gate 1.**

A rodada combinada do Gate 1 (empresa fictícia id 14, deal 900128064, "Gestão" + "Assessoria" no
mesmo deal via line items) foi desenhada de propósito para provar SC1 e SC4 **juntos, na mesma
medição**, como o roteiro original pedia — mas com um ajuste em relação ao roteiro original,
documentado abaixo como achado.

```
company.created_at  = 2026-08-12 16:41:20
MlbEmpresa.id        = 3
MlbEmpresa.tipo       = ASSESSORIA
MlbEmpresa.created_at = 2026-08-12 16:41:21
intervalo             = 1 segundo
```

### ⚠️ Achado: "Gestão" sozinho NÃO cria `MlbEmpresa`

O roteiro original presumia que a mesma empresa fictícia do Gate 1 ("Gestão", que exige contrato)
também ganharia uma `MlbEmpresa`. **Isso não acontece** — lendo `EmpresaOperacionalRouter::criarFicha()`,
só os tipos `polos`/`assessoria`/`incubadora` criam `MlbEmpresa`; "Gestão" (setor `performance`) não
é um desses três, então uma empresa só-Gestão nunca ganha ficha operacional — não é falha de
roteamento, é que `MlbEmpresa` é especificamente a ficha de **implementação**, e Gestão não tem essa
mecânica. Por isso a rodada combinada usou "Gestão" (para o Gate 1) **+ "Assessoria"** (para o Gate
2) no mesmo deal — assim SC1 (contrato real) e SC4 (roteamento) ficam provados na mesma empresa/
medição, sem forçar um cenário artificial.

Tentativa intermediária com "Assessoria" **sozinha** (empresa id 13, sem Gestão): a empresa ficou
travada em `aguardando_comercial` (pendência `sem_setor`) — porque `pendenciaSemSetor()` marca
"sem_setor" quando **todos** os contratos ativos são de serviços com `setor='outros'` (Assessoria é
`outros`), e Assessoria sozinha cai nesse caso. Combinada com "Gestão" (setor `performance`), o
`sem_setor` não dispara — confirma que a checagem funciona certo, só não é combinável com
"Assessoria isolada" para este teste.

**Suíte automatizada (`InvarianteRoteamentoTest`, 4/4, reexecutada após o fix do Gate 1):**

```
PASS  Tests\Feature\Phase128\InvarianteRoteamentoTest
✓ cadastro comercial com pendencia roteia mas nao gera contrato        0.21s
✓ cadastro via webhook hubspot com pendencia roteia mas nao gera contrato  0.25s
✓ falha do gate nao desfaz o cadastro comercial nem o roteamento       3.26s
✓ interruptor de emergencia continua desligado apos todos os cenarios  1.63s
```

**Veredito Gate 2: ✅ Success Criteria 4 atendido.** O roteamento operacional (`MlbEmpresa`)
acontece na MESMA hora do cadastro (1 segundo de intervalo), em paralelo ao gate administrativo de
contrato, medido com uma empresa real (não fixture) que também tem um serviço com contrato real
disparado.

---

## Gate 3 — Polos nunca entra no fluxo (SC0)

**RESULTADO: ✅ MEDIDO.**

### 1. Catálogo real (MariaDB local, medido pelo orquestrador antes da Task 2)

```sql
SELECT id, nome, setor, exige_contrato, ativo FROM servicos ORDER BY id;
```

| id | nome | setor | exige_contrato | ativo |
|---|---|---|---|---|
| 1 | Publicação | publicacao | 1 | 1 |
| 2 | **Polos** | polos | **0** | 1 |
| 3 | Assessoria | outros | 1 | 1 |
| 4 | Incubadora | outros | 1 | 1 |
| 5 | Publicidade | outros | 1 | 1 |
| 6 | Gestão | performance | 1 | 1 |
| 7 | Mentoria | performance | 1 | 1 |
| 8 | Implantação | outros | 1 | 1 |
| 9 | **Shopee** | shopee | 1 | 1 |

`Polos` é o único com `exige_contrato = 0`; os outros 8 exigem. `exige_contrato` é `tinyint(1) NOT
NULL DEFAULT 1` (`SHOW COLUMNS`) — confirma o default seguro da D-03 (nunca isenta por ausência de
dado).

### ⚠️ Correção de fato — grafia do 9º serviço

A pesquisa (D9) registrou confiança MÉDIA no nome `"Gestão de ADS Shopee"` porque o MariaDB local
estava fora do ar. **Medido agora: o nome real é `"Shopee"`**, não `"Gestão de ADS Shopee"`. A
migration versionada `2026_07_14_100002_seed_servico_shopee` já usa
`firstOrCreate(['nome' => 'Shopee'])` — a D9 também errou ao dizer que esse serviço "não tem
migration de seed versionada"; ele tem, e o nome bate com o catálogo real medido agora. O
comportamento continua correto de qualquer forma (`default(true)` cobre a coluna
`exige_contrato`), mas o fato precisava ser medido, não presumido — exatamente o motivo desta fase.
Em produção o nome **pode** ter sido renomeado à mão pela UI `/servicos` — isso não foi medido e
segue como limitação (só o banco de produção responde por si).

### 2. Empresa fictícia só-Polos pelo caminho do Comercial

Cadastrada via `ComercialController::store()` (autenticado como admin de teste), não pelo webhook:

```
company.id = 15 | nome = "Empresa Ficticia Gate 128-06 So Polos"
ContratoAssinatura::where('company_id', 15)->count() = 0
MlbEmpresa: id=4 | tipo=POLO | projeto=POLOS | criada_em = mesmo timestamp da company
```

Zero chamada à Clicksign nesta medição — Polos é isento (D-03), o orquestrador
(`GatilhoContratoAdministrativoService::avaliar()`) nem avalia dados mínimos para uma empresa cujos
serviços ativos são todos isentos.

**Veredito Gate 3: ✅ Success Criteria 0 atendido.** Polos nunca gera `ContratoAssinatura`, e o
catálogo real confirma que só Polos é isento — todos os outros 8 serviços, incluindo o recém-
confirmado "Shopee", exigem contrato por padrão.

---

## Verificação final — flag do bloqueio (item 4 do `how-to-verify`)

```
Configuracao::get('administrativo_bloqueio_ativo')          => NULL   (chave não existe — nasce na Fase 133)
Configuracao::get('administrativo_bloqueio_ativo', '0')      => '0'   (é assim que o router chama)
EmpresaOperacionalRouter::bloqueioAtivo()                     => false
```

**A leitura no código sempre passa `'0'` como default** (`EmpresaOperacionalRouter::bloqueioAtivo()`,
`Configuracao::get(self::CHAVE_BLOQUEIO, '0') === '1'`) — o `NULL` bruto da chave inexistente nunca
chega ao caller sem esse default. `null` é tratado como **DESLIGADO**, não como ligado.
**Confirmado: a flag continua desligada ao final da fase**, como o CONTEXT exige (esta fase não liga
o bloqueio — isso é a Fase 133).

---

## Placar

| Gate | Status | Resultado |
|---|---|---|
| Gate 1 — envelope real no sandbox pelo webhook | ✅ MEDIDO | 3 envelopes reais (`aea3c36b…`, `a8b25922…`, `d0e46b0a…`), bug de produção achado e corrigido |
| Gate 2 — invariante do roteamento (SC4) | ✅ MEDIDO | `MlbEmpresa` criada 1s após a company, na mesma rodada de contrato real |
| Gate 3 — Polos fora do fluxo (SC0) | ✅ MEDIDO | catálogo real confrontado (Shopee ≠ nome presumido pela pesquisa), 0 contratos para Polos |

**Nenhum gate ficou como NÃO MEDIDO.**

## O que o gate produziu além dos vereditos

1. **Correção de código** (`ContratoPdfService::formatarData()`/`montarVigencia()`) — contrato de
   prazo indeterminado (o caso mais comum de assinatura recorrente) quebrava o job de geração de
   envelope com `TypeError`, sempre, em qualquer ambiente. 6º bug desta milestone achado por medição
   real, não pegável por `Http::fake()`. 2 testes de regressão adicionados.
2. **Achado de API não documentado antes:** a Clicksign recusa `name` de signatário com dígitos ou
   parênteses (`400`, pointer `/data/attributes/name`). Não estava no `CLICKSIGN-SANDBOX-EMPIRICO.md`
   — vale registrar lá para a próxima fase que montar nome de signatário dinamicamente.
3. **Achado de ambiente (não é bug):** sob `QUEUE_CONNECTION=sync` (sem worker), uma empresa com 2+
   serviços que exigem contrato no mesmo request pode deixar o 2º job "engolido" silenciosamente
   pelo `WithoutOverlapping` (`SyncJob::release()` não reagenda). Em produção, com `queue:work` real,
   o `release()` reagenda e o worker reprocessa — não afeta produção, só quem for medir localmente
   sem worker.
4. **Correção de fato da pesquisa:** o 9º serviço chama-se "Shopee", não "Gestão de ADS Shopee"; e
   ele TEM migration de seed versionada (a D9 errou nos dois pontos).
5. **Confirmado:** `Polos` é o único serviço isento de contrato no catálogo real; os outros 8,
   incluindo Shopee, exigem.

## O que segue NÃO MEDIDO (declarado, não escondido)

- Grafia real do serviço "Shopee" em **produção** — só medido no catálogo local; a UI `/servicos`
  permite renomear à mão, então produção pode divergir.
- Comportamento de `WithoutOverlapping`/`RateLimited` sob carga real de fila (`queue:work`,
  múltiplos workers) — só medido sob `sync`, que tem semântica diferente de release/retry.
- Lista completa de caracteres que a Clicksign aceita/recusa no campo `name` do signatário — só
  sabemos que dígito e parênteses são recusados; não foi mapeado o conjunto aceito.

## Limpeza

Todas as empresas fictícias (ids 11–15), seus `ContratoAssinatura`/`ContratoServico`/`MlbEmpresa`/
`HubspotEvento`, os 2 `HubspotLineItemMapping` de teste (`MAP-GESTAO-GATE12806`,
`MAP-ASSESSORIA-GATE12806`) e o usuário admin de teste (`admin.teste.gate12806@example.com`) foram
**apagados** ao final da medição — confirmado por reconsulta: `companies` voltou a 0 registros,
`users` voltou a 1 (o usuário pré-existente da máquina). Os 3 envelopes reais permanecem no sandbox
Clicksign em `draft` (não ativados, D-02) — não há necessidade de cancelá-los (sandbox, sem cliente
real envolvido).

A única mudança de dado que **ficou**: `servicos.clicksign_template_id` do serviço "Gestão" (id 6)
aponta para o modelo `.docx` real já cadastrado no sandbox (`9e5d4517-…`) — configuração legítima,
não dado fictício; é exatamente o que a tela da Fase 131 vai deixar o admin configurar.

## Checklist de fechamento da fase

- [x] `Configuracao::get('administrativo_bloqueio_ativo')` continua desligada ao final (`bloqueioAtivo() === false`).
- [x] Empresas fictícias criadas na medição foram limpas (ver `## Limpeza`).
- [x] Nenhum token/secret/header de autorização foi colado neste documento (T-128-14).
