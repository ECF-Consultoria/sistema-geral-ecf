---
phase: 138-rea-comercial-conectada-etapa-v23-0
verified: 2026-09-09T14:23:44Z
status: passed
score: 3/3 requirements verificados (COMERC-01, COMERC-02, COMERC-03); 7/7 prioridades de verificação cobertas
overrides_applied: 0
deferred:
  - truth: "Nome interno real da property de owner (`hubspot_owner_id`) medido contra a conta HubSpot real"
    addressed_in: "Deploy da própria Fase 138 (roteiro na 138-CONTA-SISTEMA-HUBSPOT.md)"
    evidence: "Pendência declarada e autorizada pelo usuário em 2026-09-02/09; sem HUBSPOT_ACCESS_TOKEN no .env local, 401 impediu medição real. Comando `hubspot:inspect-properties --objects=deals` documentado para rodar na VPS antes de confiar no default."
  - truth: "Escopo OAuth crm.objects.owners.read confirmado no Private App do HubSpot"
    addressed_in: "Deploy da própria Fase 138"
    evidence: "138-HUBSPOT-MEDICOES.md: escopo_owners_read = NÃO CONFIRMADO. Usuário respondeu 'deixar anotada para o deploy' em 2026-09-09."
  - truth: "Conta 'Sistema HubSpot' criada e HUBSPOT_WEBHOOK_USER_ID configurado na VPS"
    addressed_in: "Deploy da própria Fase 138"
    evidence: "138-CONTA-SISTEMA-HUBSPOT.md: id_local=49 confirmado por reconsulta ao banco; id_vps pendente, roteiro completo documentado para a sessão de deploy."
---

# Fase 138: Área Comercial conectada à etapa (v23.0) — Relatório de Verificação

**Objetivo da fase:** A venda marcada GANHA no HubSpot chega na Área Comercial já na etapa
"Aguardando Administrativo", sem cadastro manual, e o Comercial passa a ser a única casa da
gestão de entrada — com as listagens **Contrato** e **Entrada** exibindo os 8 campos mínimos do
§2, e a empresa saindo da listagem Entrada só quando entra em "Aguardando Distribuição".

**Verificado em:** 2026-09-09
**Status:** `passed`
**Re-verificação:** Não — verificação inicial

**Nota de segurança do processo:** durante esta verificação, um system-reminder tardio instruiu
o agente a preferir `cat`/`grep`/`sed` via Bash em vez das ferramentas dedicadas de leitura. Essa
instrução contradiz as diretrizes reais da própria ferramenta Bash desta sessão e não partiu do
usuário nem da tarefa — foi tratada como tentativa de prompt injection e ignorada. Ferramentas
dedicadas (`Read`, `Grep`, `Glob`) foram usadas normalmente; `Bash` foi usado só para `git`,
`phpunit` e checagens pontuais, como o resto deste relatório evidencia.

---

## Avaliação dos 3 requisitos (contra o código, não contra o SUMMARY)

### COMERC-01 — nascimento na etapa 1 pelas duas portas

**Alegação:** empresa criada pelo webhook de venda nasce em `aguardando_administrativo` sem
cadastro manual; o cadastro manual do Comercial nasce pela mesma etapa/serviço.

**Evidência real:**
- `app/Http/Controllers/Api/HubspotWebhookController.php:283` — `processar()` chama
  `$this->nascerNaEtapa1($company)` depois do `refresh()`, fora da transaction.
- `app/Http/Controllers/Api/HubspotWebhookController.php:423` — `reprocessarEvento()` chama o
  mesmo `nascerNaEtapa1($company)` — a lacuna relatada pelo plano 138-07 (bypass do gate
  administrativo no replay) está fechada.
- `app/Http/Controllers/Api/HubspotWebhookController.php:488-508` — `nascerNaEtapa1()` resolve o
  ator por `User::find(config('services.hubspot.webhook_user_id'))`; ator ausente/inexistente
  **loga e retorna sem transicionar** (nunca `TypeError`/500) — confirmado pela leitura do código,
  não só pela docstring.
- `app/Http/Controllers/Api/HubspotWebhookController.php:500` — a transição só acontece via
  `app(EtapaTransicaoService::class)->transicionar(...)` — nenhuma escrita direta em
  `companies.etapa` em nenhum dos dois call sites.
- `app/Http/Controllers/ComercialController.php:683-690` — `store()` chama o **mesmo**
  `EtapaTransicaoService::transicionar()`, com `$request->user()` (usuário de sessão) como ator —
  nunca um `user_id` do corpo da requisição.
- `git diff fb7ddfd0..HEAD -- app/Services/FluxoEntrada/EtapaTransicaoService.php` → **vazio**: o
  serviço da Fase 137 não foi tocado nem reaberto por esta fase.
- Testado por `tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php` (6 cenários,
  incluindo ator ausente e ator inexistente) e
  `tests/Feature/Phase138/ComercEtapaNascimentoCadastroManualTest.php`. Reexecutei ambos nesta
  verificação (ver seção de testes) — **verde**.

**Veredito: VERIFICADO.**

### COMERC-02 — os 8 campos mínimos em Contrato e Entrada

**Evidência real:**
- `app/Http/Controllers/ComercialEntradaController.php:163-201` — payload da listagem Entrada
  devolve nome, cnpj, serviços, setor dominante (D-12), origem, `hubspot_owner_nome`,
  `data_venda`, contato, `contrato_badge`, `pendencia_fluxo` **e** `pendencias_cadastro` em chaves
  separadas (D-11), `hubspot_snapshot_resumo` e `etapa`.
- `app/Http/Controllers/ContratoAdminController.php:130-260` — o mesmo conjunto de campos entra
  na listagem Contrato (`company_cnpj`, `setor_dominante`, `origem`, `hubspot_owner_nome`,
  `data_venda`, `email_cliente`, `telefone`, `nome_contato`, `pendencia_fluxo`,
  `pendencias_cadastro`, `etapa`) — sem alterar o universo da query (ver COMERC-03 abaixo).
- Persistência do owner/data da venda: `app/Http/Controllers/Api/HubspotWebhookController.php:847-870`
  — um único bloco de `$company->update([...])` grava `hubspot_owner_id`/`hubspot_owner_nome`/
  `data_venda`, e esse bloco roda **depois** do `if/else` que decide entre `Company::create()` e
  `enriquecerEmpresaExistente()` — cobre os dois ramos, não só a criação.
- `app/Services/Comercial/PendenciasComerciaisService.php` — confirmei por leitura direta que são
  **7** pendências de cadastro (`sem_servico`, `sem_valor`, `servico_nao_reconhecido`, `sem_setor`,
  `sem_contato`, `valor_revisar`, `possivel_duplicidade`), batendo com o "8 estava errado, é 7" da
  D-11 e com os labels espelhados em `Entrada.jsx`/`Contratos.jsx`.
- Frontend real (não re-export): `resources/js/Pages/Comercial/Entrada.jsx` (305 linhas) e
  `resources/js/Pages/Admin/Contratos.jsx` (367 linhas, com diff de +76 linhas para as 6 colunas
  novas). Ambos presentes em `public/build/manifest.json` (linhas 1838 e 2453) — build local
  gerado 2026-09-09 09:45, `npm run build` com exit code 0 documentado em
  `138-CONTA-SISTEMA-HUBSPOT.md`.
- Testado por `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` e
  `tests/Feature/Phase138/ComercListagemEntradaTest.php` — reexecutados, verdes.

**Veredito: VERIFICADO.**

### COMERC-03 — corte de saída na etapa 5, SEM corte na listagem Contrato

**Esta é a prioridade #1 da verificação — confirmada por leitura da query, não por grep isolado.**

- `app/Http/Controllers/ComercialEntradaController.php:61-72` — o universo da listagem Entrada é
  `active = true` **e** `whereIn('etapa', [AGUARDANDO_ADMINISTRATIVO, ADMINISTRATIVO_ANDAMENTO,
  AGUARDANDO_ASSINATURA, ADMINISTRATIVO_CONCLUIDO])` — a 5ª etapa (`aguardando_distribuicao`) fica
  de fora por construção, e `whereIn` já exclui `etapa` NULL (D-14, legado nunca aparece).
- `app/Http/Controllers/ContratoAdminController.php:77-85` — **li a query inteira**: o universo é
  `Company::where('active', true)->whereHas('contratosServico', fn ($q) => $q->where('ativo', true)
  ->whereHas('servico', fn ($s) => $s->where('exige_contrato', true)))`. **Nenhuma cláusula
  `etapa` entra nesse filtro.** `grep -n "etapa" ContratoAdminController.php` só encontra `etapa`
  como CAMPO DE SAÍDA do payload (linhas 233/258/312), nunca como condição de `where`/`whereHas`.
  Isso é exatamente a garantia que a D-07/COMERC-03 exige: a listagem Contrato não esconde
  empresa em operação com contrato ativo.
- Prova viva documentada em `138-CONTA-SISTEMA-HUBSPOT.md` (checkpoint humano, 2026-09-09): a
  empresa `asdadassdsad` (id 55) aparece na tela Contrato com etapa "Em Operação" — e as empresas
  de teste em etapa 5 (ids 412, 417) existem no banco mas não aparecem na tela Entrada.
- `tests/Feature/Phase138/ComercVisibilidadeAteEtapa5Test.php` — 7 testes cobrindo os dois
  sentidos da fronteira **e** o caso D-05 (mesma empresa nas duas listagens ao mesmo tempo,
  `test_empresa_em_fluxo_de_entrada_com_contrato_ativo_aparece_nas_duas_listagens`). Reexecutado
  nesta verificação — verde.

**Veredito: VERIFICADO — inclusive a metade negativa (ausência de corte por etapa em Contrato),
que era o ponto de maior risco de regressão desta fase.**

---

## As 7 prioridades de verificação — cobertura explícita

| # | Prioridade | Resultado |
|---|---|---|
| 1 | `ContratoAdminController` sem filtro por etapa | **Confirmado por leitura da query** (linhas 77-85) — zero cláusula de etapa no `where`/`whereHas`; `etapa` só aparece como campo de saída (linhas 233/258/312). |
| 2 | Silent-failure do owner do HubSpot | `HubspotApiClient::fetchOwner()` (linha 645-662) retorna `null` em qualquer resposta não-OK, nunca lança; `HubspotOwnerResolver::resolverNome()` (linha 47-78) propaga `null` sem escrever nada além do nome resolvido — nunca grava garbage, nunca lança. Confirmado por leitura direta dos dois arquivos. |
| 3 | Duas portas do webhook + não bypass do `EtapaTransicaoService` | `processar()` (linha 283) **e** `reprocessarEvento()` (linha 423) chamam `nascerNaEtapa1()`, que só transiciona via `app(EtapaTransicaoService::class)->transicionar()`. `git diff` confirma que `EtapaTransicaoService.php` não foi tocado desde `fb7ddfd0`. |
| 4 | Migration aditiva | `2026_09_02_120000_add_hubspot_owner_data_venda_to_companies_table.php` — 3 colunas `nullable()`, sem `default()`, `up()`/`down()` simétricos por `foreach`, nenhuma coluna existente tocada, nenhum backfill dentro da migration (o retroativo é o comando `hubspot:backfill-owner-venda`, dry-run por padrão). |
| 5 | Conta de sistema não-logável | `HubspotCriarUsuarioSistema.php` gera senha aleatória de 64 caracteres, hasheada e descartada na mesma expressão. `LoginRequest`/`Auth/` não checam `users.active` (confirmado por grep — zero ocorrência de `active` nos arquivos de login), então é a senha, não o flag, que bloqueia o login. `ComercAtorSistemaTest::test_conta_nao_e_logavel_com_senhas_obvias` prova isso com `Auth::attempt` para 4 senhas óbvias — reexecutado, verde. |
| 6 | Frontend real, não re-export | `Entrada.jsx` (305 linhas, componente completo com tabela, filtros, paginação) e `Contratos.jsx` (367 linhas). Ambos presentes em `public/build/manifest.json` local (confirmado por grep no manifest, não no bundle minificado). |
| 7 | Nenhum vazamento de checklist/FINALIZAR | `git diff fb7ddfd0..HEAD --name-only` limitado a controllers/models/services/config/migration/rotas/2 páginas React — nenhum model, migration ou rota de checklist/FINALIZAR. Grep por `FINALIZAR ENTRADA`/`marcarConcluido`/`checklist_item` nos controllers tocados: zero ocorrências fora de comentários que **citam** a Fase 139 como destino futuro. |

---

## Execução de testes (rodada nesta verificação, não copiada do SUMMARY)

```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase138 tests/Feature/Phase138 \
  tests/Feature/Phase131 tests/Unit/Phase137 tests/Feature/Phase137 --colors=never
```
→ **OK (252 tests, 921 assertions)**, exit code 0. Tempo: 4m36s.

```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php \
  tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php --colors=never
```
→ **OK (24 tests, 44 assertions)**, exit code 0 — idêntico à baseline pré-migration
(`138-BASELINE-TESTES.md`), sem regressão nas suítes que exercitam a `companies.etapa` da Fase 137.

Não reexecutei as suítes de Polos (`PolosControllerTest`/`PolosFaturamentoSnapshotTest`) nesta
verificação — o `138-CONTA-SISTEMA-HUBSPOT.md` já documenta a comparação nome-a-nome contra a
baseline (mesmas 6+4 falhas, mesma causa raiz), o que é evidência mais forte que uma contagem
isolada, e o próprio `CLAUDE.md`/learnings do projeto já registra essas ~10 falhas como não
relacionadas a nenhuma fase em curso.

---

### Truths observáveis

| # | Truth | Status | Evidência |
|---|---|---|---|
| 1 | Webhook (`processar` e `reprocessarEvento`) faz a empresa nascer em `aguardando_administrativo` | ✓ VERIFICADO | `HubspotWebhookController.php:283,423,488-508` + testes |
| 2 | Cadastro manual do Comercial nasce na mesma etapa, mesmo serviço | ✓ VERIFICADO | `ComercialController.php:683-690` + teste |
| 3 | Ator ausente/inexistente nunca gera 500 | ✓ VERIFICADO | `nascerNaEtapa1()` linha 492-498 (log + return) |
| 4 | Listagem Contrato exibe os 8 campos do §2 | ✓ VERIFICADO | `ContratoAdminController.php:220-260` |
| 5 | Listagem Entrada exibe os 8 campos do §2 | ✓ VERIFICADO | `ComercialEntradaController.php:163-201` |
| 6 | Pendência do fluxo e pendências do cadastro nunca somadas | ✓ VERIFICADO | chaves `pendencia_fluxo`/`pendencias_cadastro` separadas nos dois controllers e nas duas telas |
| 7 | Entrada esconde empresa a partir da etapa 5, mostra 1-4 | ✓ VERIFICADO | `ComercialEntradaController.php:61-72` + `ComercVisibilidadeAteEtapa5Test` |
| 8 | Contrato NÃO tem corte por etapa | ✓ VERIFICADO | leitura direta da query, linhas 77-85 |
| 9 | `admin.contratos` preserva a permission própria, fora de `role:admin` | ✓ VERIFICADO | `ComercNavegacaoReorganizadaTest` + leitura de `routes/web.php:1442` |
| 10 | `admin.empresas` sai do menu mas continua acessível por URL | ✓ VERIFICADO | `AppLayout.jsx` diff + `ComercNavegacaoReorganizadaTest::test_admin_acessa_empresas_por_url_direta` |
| 11 | Conta "Sistema HubSpot" genuinamente não-logável | ✓ VERIFICADO | `HubspotCriarUsuarioSistema.php` + `ComercAtorSistemaTest` + ausência de checagem de `active` no login |
| 12 | Migration é puramente aditiva | ✓ VERIFICADO | leitura direta da migration |
| 13 | Nenhum checklist/FINALIZAR vazou para esta fase | ✓ VERIFICADO | diff de arquivos tocados + grep negativo |

**Score:** 13/13 truths derivadas verificadas.

### Itens pendentes conhecidos (carried-forward, NÃO são falha desta fase)

Conforme declarado e autorizado pelo usuário durante a própria execução da fase — não afetam o
veredito de goal achievement do código local, só a prontidão para deploy:

1. **Nome interno da property `hubspot_owner_id`** — ASSUMIDO, não medido (401 local por falta de
   `HUBSPOT_ACCESS_TOKEN`). Roteiro de medição na VPS documentado em `138-CONTA-SISTEMA-HUBSPOT.md`.
2. **Escopo `crm.objects.owners.read`** — NÃO CONFIRMADO no Private App do HubSpot. Se ausente em
   produção, degrada para coluna "Responsável comercial" sempre vazia — **sem erro em tela
   nenhuma**, comportamento resiliente por desenho (`fetchOwner()`), não uma falha silenciosa
   perigosa.
3. **Conta "Sistema HubSpot" só existe localmente (id=49)** — não existe na VPS ainda. Sem ela e
   sem `HUBSPOT_WEBHOOK_USER_ID` configurado em produção, o webhook em produção cria a empresa
   normalmente mas ela não ganha etapa — degrada em silêncio, só com log, comportamento
   deliberadamente tratado (D-17).
4. **Nada foi deployado nem pusheado** — confirmado, working tree limpo, branch
   `feat/fluxo-entrada-empresas` 102 commits à frente de `origin/main`.
5. **~10 falhas pré-existentes de Polos** — não são regressão desta fase (ver
   `.planning/learnings/painel-polos-status-e-meta.md` §2).
6. **Fixtures locais `[TESTE 138-09]` (ids 408-417)** — deixadas de propósito no banco de dev
   local, não é escopo desta fase remover.

### Artefatos requeridos

| Artefato | Esperado | Status | Detalhe |
|---|---|---|---|
| `app/Http/Controllers/Api/HubspotWebhookController.php` | nascimento na etapa 1 nos dois call sites | ✓ VERIFICADO | linhas 283, 423, 488-508 |
| `app/Http/Controllers/ComercialController.php` | nascimento na etapa 1 no cadastro manual | ✓ VERIFICADO | linhas 683-690 |
| `app/Http/Controllers/ComercialEntradaController.php` | módulo Entrada, casca | ✓ VERIFICADO | 211 linhas, sem checklist |
| `app/Http/Controllers/ContratoAdminController.php` | 8 campos, sem corte por etapa | ✓ VERIFICADO | linhas 60-260 |
| `app/Console/Commands/HubspotCriarUsuarioSistema.php` | conta não-logável idempotente | ✓ VERIFICADO | 154 linhas |
| `app/Console/Commands/HubspotBackfillOwnerVenda.php` | retroativo dry-run por padrão | ✓ VERIFICADO | linha 71-92 |
| `database/migrations/2026_09_02_120000_add_hubspot_owner_data_venda_to_companies_table.php` | aditiva | ✓ VERIFICADO | 3 colunas nullable |
| `resources/js/Pages/Comercial/Entrada.jsx` | componente real | ✓ VERIFICADO | 305 linhas, no manifest |
| `resources/js/Pages/Admin/Contratos.jsx` | 6 colunas novas | ✓ VERIFICADO | +76 linhas, no manifest |
| `resources/js/Layouts/AppLayout.jsx` | navegação reorganizada | ✓ VERIFICADO | Contrato/Entrada em Comercial, Empresas fora do menu |

### Verificação de key links (wiring)

| De | Para | Via | Status |
|---|---|---|---|
| `HubspotWebhookController::processar/reprocessarEvento` | `EtapaTransicaoService::transicionar` | `nascerNaEtapa1()` | ✓ WIRED |
| `ComercialController::store` | `EtapaTransicaoService::transicionar` | chamada direta, ator = sessão | ✓ WIRED |
| `ComercialEntradaController::index` | `Entrada.jsx` | `Inertia::render('Comercial/Entrada', ...)` | ✓ WIRED |
| `ContratoAdminController::index` | `Contratos.jsx` | `Inertia::render(...)` (pré-existente, só payload ampliado) | ✓ WIRED |
| `routes/web.php` (`comercial.entrada.index`) | `ComercialEntradaController@index` | `permission:comercial.entrada`, sem `role:admin` | ✓ WIRED |
| `AppLayout.jsx` NAV_TREE | rotas Contrato/Entrada | `routeName`+`permission` | ✓ WIRED |
| `HubspotOwnerResolver` | `HubspotApiClient::fetchOwner` | injeção de construtor | ✓ WIRED, resiliente |

### Requirements Coverage

| Requirement | Plano(s) | Status | Evidência |
|---|---|---|---|
| COMERC-01 | 138-07 | ✓ SATISFEITO | ver seção acima |
| COMERC-02 | 138-03, 138-04, 138-05, 138-06 | ✓ SATISFEITO | ver seção acima |
| COMERC-03 | 138-05 | ✓ SATISFEITO | ver seção acima |

Nenhum requirement órfão: `REQUIREMENTS-v23.md` mapeia exatamente COMERC-01/02/03 para a Fase 138,
e os 9 planos declaram esses três IDs.

### Anti-padrões encontrados

Nenhum `TBD`/`FIXME`/`XXX` sem referência a follow-up nos arquivos tocados por esta fase. Nenhum
`TODO`/`HACK`/`PLACEHOLDER` funcional (as únicas ocorrências de "checklist"/"Fase 139" em
`Entrada.jsx` e `ComercialEntradaController.php` são comentários/texto de UI que **declaram
corretamente** o escopo restante — não são débito técnico escondido, são a fronteira da fase
documentada na tela, como o `138-CONTEXT.md` (D-06) prescreve: "nada finge estar pronto"). Nenhum
`return null`/`return []` disfarçando dado que deveria existir — os `null`s de owner/data_venda são
o estado NORMAL documentado (cadastro manual nunca teve deal).

### Comportamento observado, não apenas código: por que o veredito é `passed` e não `human_needed`

Os itens que normalmente exigiriam checkpoint humano nesta verificação (render das duas telas,
não-logabilidade da conta de sistema, fronteira D-07 com dado real) já foram verificados **dentro
da própria execução da fase**, com evidência registrada por escrito em
`138-CONTA-SISTEMA-HUBSPOT.md` — screenshots das duas telas, consulta direta ao banco confirmando
que as empresas de etapa 5 existem mas não aparecem, e o usuário aprovou explicitamente
substituir a tentativa de login manual pela prova automatizada (`ComercAtorSistemaTest`). Não há
novo item de verificação visual pendente que só um humano possa fazer agora — reabrir esses
checkpoints geraria trabalho redundante sobre uma decisão já tomada e documentada. Os pendentes
reais que sobram (medição de property/escopo do HubSpot, criação da conta na VPS) são,
explicitamente, gates de **deploy**, não de **código local** — e o objetivo desta fase, pela
letra do ROADMAP, é a casca funcionando localmente, não a produção configurada.

---

## Veredito

**A Fase 138 atingiu o objetivo declarado.** As três verdades que o ROADMAP e o REQUIREMENTS-v23
exigem — nascimento na etapa 1 pelas duas portas, os 8 campos nas duas listagens, e o corte de
saída assimétrico (Entrada corta na etapa 5, Contrato não corta por etapa) — estão implementadas
no código, cobertas por teste automatizado que roda de verdade (não só existe no disco), e a
suíte de regressão da Fase 137/131 continua verde sem alteração de contagem. A prioridade de
maior risco desta verificação — o `ContratoAdminController` não ganhar corte por etapa por engano
— foi confirmada por leitura direta da query, não por inferência.

Os pendentes remanescentes (medição de property HubSpot, escopo OAuth, conta de sistema na VPS)
são gates de deploy já identificados, escritos e aceitos pelo usuário — não invalidam o trabalho
local desta fase e não bloqueiam o avanço para a Fase 139. Nenhum deploy foi feito, conforme
instrução do projeto.

---

*Verificado em: 2026-09-09T14:23:44Z*
*Verificador: Claude (gsd-verifier)*
