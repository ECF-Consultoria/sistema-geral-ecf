# Fase 135: Onboarding Geral por Serviço — Pesquisa

**Pesquisado em:** 2026-08-11
**Domínio:** Motor de onboarding dirigido por template (Laravel 12 + Inertia + React), com passos automáticos que leem Adman API, ML API (OAuth) e tabelas internas já existentes (`ml_acervo_itens` da Fase 134).
**Confiança:** ALTA nos pontos A-H (evidência de código lida e citada linha a linha). MÉDIA/BAIXA nos pontos que exigem conhecimento externo à API do Mercado Livre (parsing de `seller_reputation` para medalha/Full) e em qualquer decisão de produto não coberta pelo `135-CONTEXT.md`.

## Summary

Esta fase não introduz nenhuma tecnologia nova — é modelagem de dados + Observer + resolvers em cima do stack já existente (Laravel 12, Inertia, React, Eloquent, Spatie Activitylog, queue `database`). O trabalho de pesquisa mais valioso não foi "qual biblioteca usar", foi **ler o comportamento exato de três coisas que já existem e que a fase vai reaproveitar sem poder adivinhar**: (1) a sonda de grant Adman já tem um padrão vetado em produção por dois comandos (`DiagnoseCustId`, `MarkCustIdStatus`) que usam exatamente `fetchPerformance($custId, $ontem, $ontem)` para essa finalidade — não é preciso inventar nada novo, e a resposta à pendência D-18 é definitiva: **existe, sim, uma sonda barata e confiável**; (2) o accessor `Company::adman_account_id` não lê mais a coluna flat diretamente — desde a Phase 57 (v13.0) ele lê primeiro de `company_marketplaces` (pivot) e só cai para a coluna antiga em fallback — qualquer resolver que ignore isso lerá dado errado para empresas migradas; (3) o resolver de "anúncios ativos/inativos" (passo 8) não pode ser uma leitura síncrona pós-dispatch: `mlb:sync-acervo` apenas ENFILEIRA jobs (não roda inline), e o job da camada barata tem timeout de até 1800s para contas grandes — a arquitetura precisa de um mecanismo de reavaliação posterior, não de um `dispatch()+read()` na mesma request.

O roteamento atual (`ComercialController::servicoDisparaImplementacao()`) e a duplicação de `ContratoServico::create(...)` em 4 call-sites foram confirmados byte a byte contra o `135-CONTEXT.md` — as linhas citadas em D-13 batem exatas com o código lido nesta pesquisa. O padrão de Observer via atributo PHP `#[ObservedBy(...)]` (Laravel 11+) já está em produção em `MlbEmpresa` e é o molde direto a seguir em `ContratoServico`.

Não foi encontrado nenhum precedente de **versionamento numérico imutável de template** (`versao` incremental + `publicado_em`) em nenhuma migration do projeto — o padrão mais próximo (`nps_templates`, Fase 68) edita o template em vigor e congela a RESPOSTA via colunas snapshot por linha, um mecanismo diferente do que a D-07 pede. A fase 135 será o primeiro uso desse padrão no projeto; a técnica de índice único parcial (SQLite vs. MySQL/MariaDB) usada em `nps_templates` para `is_default` é reaproveitável para garantir "só 1 versão ativa por serviço".

**Recomendação primária:** montar o motor em cima de 5 tabelas novas (schema já proposto no CONTEXT), registrar o Observer via `#[ObservedBy(ContratoServicoObserver::class)]` em `ContratoServico`, implementar os resolvers como classes registradas em catálogo fechado (D-09) que leem Eloquent (nunca SQL cru) para respeitar accessors como o de `adman_account_id`, e tratar toda chamada Adman como Job assíncrono — nunca síncrona dentro do request HTTP, seguindo a mesma restrição arquitetural já documentada no `CLAUDE.md` do projeto.

<user_constraints>
## User Constraints (from CONTEXT.md)

> Fonte: `.planning/phases/135-.../135-CONTEXT.md`. Este documento **substitui** o discuss-phase — as decisões abaixo são travadas e não devem ser reabertas pelo planner. D-01..D-08 vieram de `AskUserQuestion` com o usuário; D-09..D-17 são derivadas por discrição (marcadas como tal no próprio CONTEXT); D-18 fechou a única pendência aberta em 2026-08-11.

### Decisões travadas pelo usuário (D-01..D-08)

| # | Decisão | Escolha | Consequência |
|---|---|---|---|
| D-01 | Âncora do onboarding | Um por empresa × serviço | Casa com `contratos_servico`; painel precisa agrupar por empresa |
| D-02 | Onboarding de Polos | Coexiste **intocado** | Motor novo nasce ao lado; migração de Polos é decisão futura e separada — **NÃO tocar em `mlb_implementacoes`, `MlbImplementacaoController`, `/mlb/implementacao`, `/implementacao/{token}`** |
| D-03 | Passos automáticos | Núcleo da v1 | É o que separa isto de um formulário melhorado |
| D-04 | Quem monta o template | Admin pela UI | Exige CRUD, versionamento visível e guarda de ciclo |
| D-05 | Gatilho de criação | Rascunho + confirmação | Observer cria; SLA só corre após a Coordenação definir o responsável |
| D-06 | Link do cliente | Um por EMPRESA, agregando serviços | Token vive na empresa, não no onboarding |
| D-07 | Edição de template com onboardings vivos | Nova versão; vivos seguem na antiga | Migrar em andamento é ação explícita |
| D-08 | Serviços na v1 | **Só Gestão (Performance)** | Demais serviços ganham template depois, sem tocar no motor |

### Decisões técnicas derivadas (por discrição — D-09..D-17)

- **D-09** — `auto_fonte` vem de **catálogo fechado** registrado em código (nunca texto livre) — condição para D-04+D-03 conviverem.
- **D-10** — `template_passos.chave` nasce **agora**, mesmo sem uso na v1 (só Gestão) — necessário para D-06 (link único por empresa: passo de mesma `chave` em serviços diferentes fecha uma vez só).
- **D-11** — Resolver distingue **"não coletado"** de **"zero real"** — mesma pegadinha já sofrida no Shopee (conta nova fica vazia até o backfill). O resolver de anúncios dispara `mlb:sync-acervo --company={id}` e só então lê (ver achado A.4/Pitfall sobre timing assíncrono).
- **D-12** — Passo condicional: `condicao` faz o passo só nascer se aplicável (ex.: "excluir anúncios inativos" só nasce se houver inativos).
- **D-13** — Observer em `ContratoServico`, não lógica por controller. Contrato nasce em 4 lugares: `Api/HubspotWebhookController.php:842`, `ComercialController.php:669`, `CompanyController.php:957`, `CompanyGroupController.php:83`. Mesmo padrão do `MlbEmpresaObserver` já existente.
- **D-14** — Três donos: `dono ∈ {cliente, interno, sistema}` + `setor_id` nullable. Sem dono `administrativo` separado.
- **D-15** — Pagamento não trava o mapeamento, só a conclusão do onboarding.
- **D-16** — Ficha do cliente é anexo, não formulário (v1: "recebida" + anexo).
- **D-17** — Responsável sugerido via `Company::responsavelDoServicoOuConsolidado()`, não escolhido do zero. Rascunho nasce com sugestão; sem vínculo, não sai de rascunho.

### D-18 — Os dois grants, resolvido pelo usuário em 2026-08-11 (FECHA A ÚNICA PENDÊNCIA)

- **"Grant com o Sistema ECF"** = OAuth do app ECF → `ml_tokens`. Passo 5, dono `cliente`, depende do passo 2, fonte `ml_tokens.status = active`.
- **"Grant com a Consultoria"** = grant com a **Adman** (`api.adman.com.br`), NÃO `company_grants`. Passo 4, dono `sistema`, depende do passo 3.
- `company_grants` (populado por `SyncGrantsFromEcfDrive`) = programa de parceiros ML (medalha/programa/iniciativa) — dado que a ECF *recebe*, não acesso que o cliente *concede*. Fonte **dentro do passo 7**, nunca passo próprio.
- **Pendência explícita deixada para esta pesquisa:** qual chamada do `AdmanService` serve de sonda barata do grant Adman — **respondida no Achado A.2 abaixo com veredito definitivo.**

### Esquema de dados proposto no CONTEXT (referência para o planner)

```
onboarding_templates        servico_id, versao, ativo, publicado_em
 └ template_passos          chave, titulo, tipo, dono, setor_id, depende_de,
                            sla_dias, auto_fonte, condicao, obrigatorio
onboardings                 company_id, servico_id, template_id (versão congelada),
                            status (rascunho|andamento|concluido), responsavel_id
 └ onboarding_passos        status, valor(json), feito_por, feito_em, auto_em
onboarding_links            company_id, token          ← um por EMPRESA (D-06)
```

### Template da v1 — Gestão (Performance): 13 passos, 5 automáticos

| # | Passo | Dono | Depende | Fonte automática |
|---|---|---|---|---|
| 1 | Ficha do cliente recebida | interno | — | (anexo, D-16) |
| 2 | Acesso colaborador ML | cliente | — | — |
| 3 | Planilha de custos ADMAN | **sistema** | — | `adman_account_id` preenchido |
| 4 | Grant com a Consultoria (Adman) | **sistema** | 3 | Adman responde para o `cust_id` — ver Achado A.2 |
| 5 | Grant com o Sistema ECF (OAuth) | cliente | 2 | `ml_tokens.status = active` |
| 6 | Confirmação de pagamento | interno·financeiro | — | — |
| 7 | Métricas da conta | **sistema** | 3, 5 | Adman + `fetchUserInfo()` |
| 8 | Anúncios ativos/inativos | **sistema** | 5 | `ml_acervo_itens` |
| 9 | Excluir anúncios inativos | interno | 8 · *só se inativos>0* | — |
| 10 | Custos no App ECF | cliente | — | — |
| 11 | Grant de Ads | interno | 5 | — |
| 12 | Agendar reunião de onboarding | interno | 7, 8 | — |
| 13 | Reunião realizada → concluído | interno | 12, 6 | — |

**SLA:** 15 dias corridos ponta a ponta. Acesso colaborador 3d · grants 5d · mapeamento 1d após destravar · reunião realizada 10d.

### Fora de escopo (Deferred — não pesquisar, não planejar)

- Contrato / revisão / assinatura Clicksign — milestone v22.0 (Fases 124-133), em execução paralela.
- Relatório inicial automático de onboarding — fase própria futura; `RelatorioMensalPdfService` (confirmado existir em `app/Services/RelatorioMensalPdfService.php`) é o molde.
- Migração do onboarding de Polos para o motor novo — D-02 travou coexistência.
- Templates de Publicação, Shopee, Assessoria/Incubadora/Implantação — D-08 restringe a v1 a Gestão.
</user_constraints>

<phase_requirements>
## Phase Requirements

> Fase avulsa, sem REQ-IDs de milestone. Os 11 critérios de sucesso do `ROADMAP.md` (linhas 1762-1774) fazem o papel de REQ-IDs. Numerados aqui como SC-01..SC-11.

| ID | Descrição (ROADMAP.md) | Suporte da pesquisa |
|----|-------------|------------------|
| SC-01 | `/onboarding` cobre qualquer serviço, ancorado em `Company × Servico`, um onboarding por contrato | Confirmado `ContratoServico` como pivot correta (`app/Models/ContratoServico.php`); D-01 já trava a âncora |
| SC-02 | Onboarding de Polos continua byte-a-byte intocado | Mapeado o escopo exato a não tocar: rotas 90-91/94-95/955-981 de `routes/web.php`, `MlbImplementacaoController`, `mlb_implementacoes` — ver Achado E |
| SC-03 | Contrato criado por qualquer um dos 4 caminhos gera onboarding em rascunho via Observer | Achado B — os 4 call-sites lidos e confirmados linha a linha, molde `MlbEmpresaObserver` lido inteiro |
| SC-04 | Rascunho não corre SLA/não expõe link; só vira andamento com responsável confirmado | `responsavelDoServicoOuConsolidado()` lido (`Company.php:249-266`) — Achado B |
| SC-05 | Passo com dono/dependência/SLA/condição; dependente nasce bloqueado | Modelagem em Achado C; nenhuma trava de ciclo pré-existente encontrada (ver Pitfall) |
| SC-06 | Os 5 passos `dono=sistema` resolvem sem digitação humana | Achado A — nota de INCONSISTÊNCIA entre este texto do ROADMAP e a tabela do CONTEXT (passo 5 é `dono=cliente`), ver Open Questions |
| SC-07 | Resolver distingue "não coletado" de "zero real"; nunca aceita tabela vazia | Achado A.4 — timing assíncrono do `mlb:sync-acervo` é o ponto crítico |
| SC-08 | Admin monta/edita templates pela UI com catálogo fechado de `auto_fonte` + guarda de ciclo | Achado D + F — nenhum precedente de detecção de ciclo encontrado, UI análoga em `Pages/Nps/Configuracao.jsx` |
| SC-09 | Salvar template publica versão N+1; onboardings em andamento seguem na versão antiga | Achado D — sem precedente direto; técnica de índice único parcial de `nps_templates` é reaproveitável |
| SC-10 | Link único por empresa agregando passos `dono=cliente`; mesma `chave` fecha uma vez | Achado E — token/CSRF/rota pública de Polos lidos como molde |
| SC-11 | Painel mostra o que trava, há quantos dias, de quem é a bola | Achado F (frontend) + G (testes) |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Observer de criação de onboarding | API/Backend (Eloquent Observer) | Database (transação da criação do contrato) | Roda dentro da mesma transação DB que cria `ContratoServico` — precisa ser atômico com ela (ver Achado B) |
| Resolvers automáticos (passos 3,4,5,7,8) | API/Backend (Service + Job) | Database (leitura de `ml_tokens`, `ml_acervo_itens`, accessor `Company`) | Passos 4 e 7 chamam Adman/ML externamente — **devem** rodar em Job, nunca síncrono no request (restrição já registrada no `CLAUDE.md` do projeto) |
| CRUD de template (D-04) | API/Backend (Controller + FormRequest) | Frontend Server (Inertia render) | Molde direto: `NpsTemplateController` + `Pages/Nps/Configuracao.jsx` |
| Painel operacional (`/onboarding`) | Frontend Server (Inertia) | API/Backend (Controller monta payload agregado) | Mesma forma do Dashboard/Companies — sem API separada |
| Link público por empresa | API/Backend (rota sem CSRF, sem auth) | Browser (formulário do cliente) | Molde: `MlbImplementacaoController::workspace()` / `/implementacao/{token}` — prefixo **diferente**, ver Achado E |
| Persistência de template versionado | Database | — | Tabelas imutáveis por versão — nenhuma camada de aplicação deve fazer `UPDATE` em `template_passos` de uma versão publicada |

## Standard Stack

### Core

Nenhuma biblioteca nova é necessária. A fase é modelagem de dados + lógica de negócio sobre o stack já instalado:

| Componente | Já em uso | Onde |
|---|---|---|
| Eloquent Observer via atributo PHP | Sim (Laravel 11+ `#[ObservedBy]`) | `app/Models/MlbEmpresa.php:13` |
| Job assíncrono para chamada Adman | Sim | `app/Jobs/SyncAdmanCompanyJob.php` |
| Cast JSON em coluna | Sim | `app/Models/MlbImplementacao.php:51` (`'dados' => 'array'`) + migration `$table->json('dados')` |
| Índice único parcial (SQLite vs MySQL/MariaDB) | Sim | migration `2026_07_07_100001_create_nps_templates_v15_tables.php:87-99` |
| Token público de acesso sem auth | Sim | `MlbImplementacaoController::gerarLink()` — `Str::random(48)` + `unique()` no DB |
| Spatie Activitylog em model de auditoria | Sim | `ContratoServico`, `CompanyGrant`, `Servico` já usam `LogsActivity` |

### Alternatives Considered

Não aplicável — não há escolha de biblioteca a fazer nesta fase.

**Installation:** nenhuma (`composer.json`/`package.json` não mudam).

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo — Composer nem npm. Toda a implementação usa classes/serviços já presentes no `composer.lock`/`package-lock.json`. Nenhuma etapa do Package Legitimacy Gate precisa rodar.

## Achados da Investigação Dirigida (A–H)

### A. Os 5 resolvers automáticos

#### A.1 — `companies.adman_account_id`

**Achado crítico:** este campo **não é uma coluna flat simples** desde a Phase 57 (v13.0). `Company::getAdmanAccountIdAttribute()` (`app/Models/Company.php:611-620`) lê primeiro de `company_marketplaces` (pivot, `marketplace='meli'`, coluna `adman_id`) e só cai para `$this->attributes['adman_account_id']` se a linha do pivot não existir ou estiver nula. O mutator espelha isso: `setAdmanAccountIdAttribute()` (`Company.php:637-646`) escreve nos DOIS lugares (pivot + coluna flat) quando `$this->exists`.

**Consequência direta para o resolver do passo 3:** o resolver **precisa** ler via `$company->adman_account_id` (o accessor Eloquent), nunca via `DB::table('companies')->value('adman_account_id')` ou query raw — isso pegaria só a coluna antiga e daria falso-negativo em empresas já migradas para o pivot `company_marketplaces`. `[VERIFIED: app/Models/Company.php:611-646]`

O que significa estar nulo: nem o pivot `meli` nem a coluna flat têm valor — a planilha Adman (`SyncGrantsFromEcfDrive`) ainda não casou essa empresa, ou a empresa nunca foi cadastrada na Adman. `[VERIFIED]`

#### A.2 — Grant Adman (D-18): veredito da sonda

**Veredito: existe sonda barata e confiável, já validada em produção por dois comandos distintos.**

(a) **Qual chamada usar:** `AdmanService::fetchPerformance($custId, $ontem, $ontem, 3, $marketplace)` — a mesma chamada que os passos 3/7 já usam para faturamento/margem, mas com **range de 1 dia** (`$ontem = $ontem`) em vez de um range largo, minimizando o payload de `items[]`. Este é literalmente o padrão que dois comandos de produção já usam **exatamente para este propósito** (validar se a Adman reconhece um `custId`):
- `app/Console/Commands/DiagnoseCustId.php:288-315` (`validarViaAdman()`)
- `app/Console/Commands/MarkCustIdStatus.php:324-352` (`validarViaAdman()`, cópia intencional do mesmo método — ver docblock da classe, linha 20: "Espelha `DiagnoseCustId::classificarEmpresa`")

(b) **Como o retorno diferencia "sem grant" de "grant ok, conta sem movimento":**
- **Sucesso HTTP (sem exceção)** = grant ativo, **independente** de `summarizedData` vir com valores zerados/nulos naquele dia — a API responde 200 mesmo quando a conta não vendeu nada no dia consultado (isso é esperado, não é erro). `fetchPerformance()` só lança exceção em falha HTTP (`AdmanService.php:315,328`).
- **Exceção com mensagem contendo `400`, `404` ou `500`** = Adman não reconhece o `cust_id` → sem grant / grant não configurado (categoria `INVALIDO_CONFIRMADO` em `DiagnoseCustId.php:303-304`).
- **Exceção com `429` ou outro código** = erro transitório (rate limit, timeout, rede) → **indeterminado**, não conclua nem "sem grant" nem "grant ok"; repita depois (categoria `ERRO_INDEFINIDO`, `DiagnoseCustId.php:305-307`). Este é exatamente o mecanismo que satisfaz D-11 para este passo específico.

(c) **Custo/cadência:** throttle de 7s é aplicado **sempre**, mesmo em erro (`usleep(7_000_000)` em ambos os comandos), respeitando `AdmanService::ADMAN_RATE_LIMIT_RPM = 10` (`AdmanService.php:17-25`). O `/performance/{custId}` tem `connectTimeout(15)` / `timeout(120)` (`AdmanService.php:307-313`) — **por isso, e por causa da restrição arquitetural do `CLAUDE.md`** ("Long external API calls (Adman) must go through Jobs to avoid nginx/php-fpm timeout"), este resolver **deve rodar em Job**, nunca síncrono numa request HTTP do painel.

**O que NÃO usar como sonda:** `AdmanService::fetchAccountMetrics()` / `/accounts/{custId}/metrics` — o próprio código documenta que este endpoint **dá 404 para a maioria das contas, "todos os polos testados"** (`AdmanService.php:539-544`, comentário acima de `fetchInvestment()`). Usar esse endpoint como sonda de grant produziria falsos-negativos sistemáticos em contas com grant perfeitamente ativo. `listAccounts()` (`/accounts`, `AdmanService.php:1173-1184`) também foi descartado como sonda: não há nenhum uso em produção que filtre por `custId` específico — só é usado hoje como ferramenta de debug via `adman:sync --list-accounts` (`SyncAdmanData.php:29-38`), sem garantia documentada de que a listagem reflita grant por conta.

**Ordem de dependência confirmada:** o resolver do passo 4 só deve rodar depois que o passo 3 (campo `adman_account_id` preenchido) estiver satisfeito — evita gastar chamadas Adman em empresas que nem têm `cust_id` cadastrado. Isso já está expresso na tabela do template (`depende: 3`).

**Não usar `companies.cust_id_status` como atalho:** essa coluna (migration `2026_06_02_180000_add_cust_id_status_to_companies.php`, enum `ok|invalido|desconhecido|nao_aplicavel`) é populada pelo comando `dashboard:mark-custid-status`, que **NÃO está agendado no scheduler** — "NAO esta agendado no scheduler (decisao W5 do PLAN)" (`MarkCustIdStatus.php:44`). Para uma empresa recém-onboardada (exatamente o caso de uso desta fase), essa coluna estará em `desconhecido` (default da migration, linha 38) até alguém rodar o comando manualmente. **Não é uma fonte confiável de estado fresco para o resolver.** `[VERIFIED]`

#### A.3 — `ml_tokens`

Modelo: `app/Models/MlToken.php` (48 linhas). Coluna de status: `status` (string livre no fillable, sem enum documentado no model — valores observados no código: `'active'`, `'revoked'`). Casts: `access_token`/`refresh_token` são `encrypted`; `expires_at`/`last_refreshed_at`/`connected_at` são `datetime`.

"Token ativo" = `$company->mlToken->status === 'active'` — é exatamente o filtro usado por `mlb:sync-acervo` (`SyncMlAcervo.php:159` e `:148`) e é o mesmo filtro de `ml:sync` (`SyncMlData`), documentado como reuso intencional ("reusa EXATAMENTE o filtro de SyncMlData", `SyncMlAcervo.php:21-22`).

**Há refresh automático:** `MercadoLivreService::ensureValidToken()` (`app/Services/MercadoLivreService.php:250-267`) chama `refreshToken()` quando `$token->expiresSoon(5)` (expira em ≤5 minutos) — `expiresSoon()` está definido em `MlToken.php:42-46`. Se o refresh falhar, o token é marcado `revoked` (comentário em `MercadoLivreService.php:301`) e `ensureValidToken()` retorna `null`. **Consequência para o resolver do passo 5:** ler só `status='active'` da coluna pode estar levemente desatualizado num instante entre expirar e o próximo refresh automático — mas como o refresh é acionado em toda chamada `get()`/`fetchUserInfo()` da API ML, não há necessidade de o resolver fazer refresh manual; ele só lê a coluna.

#### A.4 — `ml_acervo_itens` (Fase 134) e `mlb:sync-acervo`

Modelo: `app/Models/MlAcervoItem.php`, tabela `ml_acervo_itens`. Campos relevantes: `status` (string — valores confirmados no código: `active`, `paused`, `under_review`, `closed`, ver `app/Http/Controllers/MlbAnuncioController.php:698` `'acionaveis' => ['active', 'paused']` e `MercadoLivreService.php:456`), `coletado_em` (datetime), `coleta_erro`.

**"Ativos vs inativos":** o campo `status` é populado **inteiramente pela camada barata** (multiget) — `MlAcervoService::coletarCamadaBarata()` (`app/Services/Mlb/Acervo/MlAcervoService.php:177+`) processa todos os itens via `enumerarIds()` + `processarLote()`; não depende da camada cara (visitas/buy box). Contagem de "ativos" ≈ `status='active'`; "inativos" ≈ demais status (`paused`/`closed`/`under_review`), a ser confirmado com o time de produto — não há uma constante central `MlAcervoItem::STATUS_ATIVOS` no model lido.

**`mlb:sync-acervo --company={id}`:** `app/Console/Commands/SyncMlAcervo.php`.
- Assinatura: `{--company=} {--n=} {--so-barata} {--so-detalhe}` (linhas 26-30).
- **NÃO é síncrono — é fan-out.** O comando `dispatch()`a `SyncMlAcervoCompanyJob` (camada barata) e `SyncMlAcervoDetalheJob` (camada cara, em lotes) com `delay()` incremental e retorna imediatamente (`SyncMlAcervo.php:71-126`). Rodar `mlb:sync-acervo --company=X` no terminal termina em segundos — quem realmente coleta é o worker, depois.
- Pré-requisito: a empresa **precisa ter `mlToken.status='active'`**, senão `resolverEmpresas()` retorna erro (`SyncMlAcervo.php:148-149`) — ou seja, o resolver do passo 8 só pode disparar essa coleta depois que o passo 5 (grant OAuth) estiver satisfeito. Isso já está expresso no template (`depende: 5`).
- **Quanto demora:** `SyncMlAcervoCompanyJob` tem `timeout = 1800` (30 minutos) — comentário explícito: "A camada barata da maior conta (66.747 itens) gira em torno de 3.340 chamadas de multiget" (`app/Jobs/SyncMlAcervoCompanyJob.php:38-41`). `tries=3`, backoff `[60, 300, 900]`, `ShouldBeUnique` com `uniqueFor()=3600` (linhas 36,56-59,62-65).
- **Empresa que nunca sincronizou:** nenhuma linha em `ml_acervo_itens` para aquele `company_id`. `MlAcervoItem` expõe helpers de "ainda não avaliado" (`naoAvaliadoBuyBox()`, `visitasNaoAvaliadas()`, `saudeMlNaoAvaliada()`, `MlAcervoItem.php:120-153`) mas **não há** um helper pronto para "empresa nunca coletou nenhum item" — o resolver precisa fazer isso com `MlAcervoItem::where('company_id', $id)->exists()`.

**Implicação arquitetural (a mais importante deste achado):** como o comando apenas enfileira e o job pode levar até 30 minutos, o resolver do passo 8 **não pode** fazer "dispara e lê" na mesma requisição/job. O desenho correto, coerente com D-11 ("dispara `mlb:sync-acervo --company={id}` e só então lê"), é: se `MlAcervoItem::where('company_id',$id)->exists()` é `false`, disparar a coleta (via `Artisan::call()` ou dispatch direto do job) e manter o passo **pendente** (não "zero", não "concluído" — um terceiro estado, ex. "aguardando coleta"); numa reavaliação **posterior** (próxima carga do painel, ou um comando agendado que reavalia passos automáticos pendentes), ler de novo e, se agora existem itens, concluir o passo e computar ativos/inativos.

#### A.5 — `MercadoLivreService::fetchUserInfo()`

```php
// app/Services/MercadoLivreService.php:514-529
/**
 * Informações básicas da conta ML autenticada.
 * @return array{id, nickname, email, seller_reputation, ...}
 */
public function fetchUserInfo(Company $company): array
{
    $token = $this->ensureValidToken($company);
    if (! $token) {
        throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
    }
    return $this->get($company, "/users/{$token->ml_user_id}");
}
```

- Sem token válido → **lança `\RuntimeException`** (não retorna `null`/array vazio). O resolver do passo 7 precisa capturar isso e tratar como "não coletado" (esperado quando o passo 5 ainda não terminou — por isso o `depende: 3, 5` no template).
- `ensureValidToken()` já faz refresh automático (ver A.3) — o resolver não precisa reimplementar isso.
- **`[ASSUMED — não verificado no código deste projeto]`** o parsing de `seller_reputation` para "reputação verde · medalha · Full" **não existe em nenhum lugar do repositório hoje**. Buscas por `seller_reputation`, `power_seller_status`, `level_id`, `reputation` no diretório `app/` retornaram apenas a menção do docblock (`MercadoLivreService.php:518`) — nenhum consumidor real extrai esses campos. Isso é **trabalho novo**, não reuso: o resolver do passo 7 vai precisar escrever a lógica de parsing do payload `/users/{id}` da API do Mercado Livre (campo `seller_reputation.level_id` para reputação/medalha, e alguma outra fonte — possivelmente `tags` ou um endpoint de itens/fulfillment — para "Full", que não é claramente exposto em `/users/{id}` segundo o conhecimento de treinamento, sem confirmação nesta sessão). **Recomendação:** o planner deve tratar o parsing exato de medalha/Full como sub-tarefa de descoberta (chamar a API real de uma conta de teste e inspecionar o payload), não como fato já resolvido.
- `CompanyGrant` (`app/Models/CompanyGrant.php`) é a fonte **complementar** dentro do passo 7 (não passo próprio, per D-18): campos `medalha_fecha_in`/`medalha_fecha_out`/`programa`/`iniciativa`/`parceiro` (fillable, linhas 28-35), `scopeActive()` (linhas 51-54), populados por `SyncGrantsFromEcfDrive` (não por ação do resolver).

### B. O Observer em `ContratoServico`

**Os 4 call-sites — confirmados linha a linha:**

1. `app/Http/Controllers/Api/HubspotWebhookController.php:842` — dentro de `persistirContratos()` (linhas 797-875), chamado por `criarEmpresa()` **dentro de um `DB::transaction()`** (abertura em `HubspotWebhookController.php:513`). Guard anti-duplicidade prévio (linhas 811-828): não duplica por `hubspot_line_item_id` (com line item) nem por `servico_id` ativo (fluxo legado). Usa `ContratoServico::create([...])` (create simples, não `updateOrCreate`).
2. `app/Http/Controllers/ComercialController.php:669` — dentro de `store()`, também **dentro de `DB::transaction()`** (abertura linha 643). Sem guard de duplicidade prévio (empresa é sempre nova nesse fluxo, checada por nome nas linhas 629-637).
3. `app/Http/Controllers/CompanyController.php:957` — `$company->contratosServico()->create([...])` dentro de `storeContrato()`. **Sem `DB::transaction()` visível** ao redor — é uma única query. Dispara o mesmo evento `created` do Eloquent (é a mesma classe, criação via relação).
4. `app/Http/Controllers/CompanyGroupController.php:83` — dentro de `atribuirServico()` (linhas 61-101), **em loop** sobre `$group->companies()->get()`, **sem `DB::transaction()` ao redor do loop**. Pode criar N `ContratoServico` (e portanto N onboardings em rascunho) numa única request se o grupo tiver N empresas sem o serviço ainda. **Risco identificado:** se o Observer fizer qualquer trabalho pesado síncrono (leitura de outras tabelas, chamada de serviço), N repetições dentro de uma request HTTP podem degradar a resposta — o Observer deve ficar limitado a criar as linhas de `onboardings`/`onboarding_passos` (barato) e delegar qualquer resolver de rede para Job.

**Roteamento atual (o que a fase substitui) — `ComercialController::servicoDisparaImplementacao()`:**

```php
// app/Http/Controllers/ComercialController.php:54-62
public static function servicoDisparaImplementacao(string $nome): ?string
{
    return match (true) {
        str_contains($nome, 'Polos')      => 'polos',
        str_contains($nome, 'Assessoria') => 'assessoria',
        str_contains($nome, 'Incubadora') => 'incubadora',
        default                            => null,
    };
}
```
Este helper estático é reaproveitado por **ambos** os controllers que hoje criam `MlbEmpresa`: `ComercialController::store()` (linhas 683-715, cria `MlbEmpresa` inline) e `HubspotWebhookController::rotearImplementacao()` (linhas 929-963, chama `ComercialController::servicoDisparaImplementacao()` na linha 931, mas com sua PRÓPRIA lógica de criação de `MlbEmpresa`/`MlbImplementacaoFactory::criarParaPolo()`, diferente do bloco inline do Comercial). **Confirma a duplicação citada em D-13:** a checagem de "qual serviço dispara o quê" é uma função compartilhada, mas a AÇÃO (criar `MlbEmpresa` + implementação) está duplicada em dois lugares com pequenas diferenças. `CompanyController` e `CompanyGroupController` **não chamam** esse roteamento — contrato criado por essas telas nunca gera nada (confirma a frase do CONTEXT "contrato criado pela tela da empresa nunca gera onboarding").

**Molde `MlbEmpresaObserver` (lido inteiro, `app/Observers/MlbEmpresaObserver.php`):**
- Registrado via atributo PHP no model, não em ServiceProvider: `#[ObservedBy(MlbEmpresaObserver::class)]` em `app/Models/MlbEmpresa.php:13` (import na linha 5). **Este é o padrão a seguir em `ContratoServico`** — adicionar `use App\Observers\ContratoServicoObserver; use Illuminate\Database\Eloquent\Attributes\ObservedBy;` + `#[ObservedBy(ContratoServicoObserver::class)]` na classe.
- Hooks usados: `created()` e `updated()` (com guard `wasChanged('cust_id')` para não rodar em todo update).
- Evita recursão: não usa `saveQuietly()` no exemplo lido — em vez disso, atualiza um model DIFERENTE (`Publicacao`), então nunca re-dispara o próprio Observer. **Nota para o planner:** se o Observer de `ContratoServico` precisar atualizar o PRÓPRIO `ContratoServico` (não deveria — ele só cria `Onboarding`/`OnboardingPasso`), usar `saveQuietly()` para não reentrar.
- Auditoria via `activity()->withProperties(...)->log(...)` — consistente com o resto do projeto.

**`Company::responsavelDoServicoOuConsolidado()` (D-17):**
```php
// app/Models/Company.php:249-266
public function responsavelDoServicoOuConsolidado(string $role, int $servicoId): \Illuminate\Support\Collection
```
Retorna uma `Collection` de `User` (pode ser vazia, 1 ou N) — primeiro tenta vínculo específico do serviço (`company_users.servico_id = $servicoId`), cai para vínculo consolidado (`servico_id` NULL) se não achar nada específico. `$role` é uma string do enum de `company_users.role` (`consultor`, `estrategista`, `analista` — confirmado na migration `2026_05_22_200001_rename_mentor_to_estrategista_in_company_users.php`). **O planner precisa decidir explicitamente qual `$role` usar** para a sugestão de responsável do onboarding — não está definido no CONTEXT qual papel é "o operacional de Gestão" (provavelmente `'consultor'`, mas isso é uma leitura minha, não uma confirmação de código — ver Open Questions).

**Riscos identificados — seeders/factories/testes que criariam `ContratoServico` em massa:**
- `ContratoServico` **não tem** `HasFactory`/`ContratoServicoFactory` — toda criação em testes é `ContratoServico::create([...])` explícita (confirmado: nenhum arquivo em `database/factories/` para esse model).
- **33 arquivos de teste** referenciam `ContratoServico::` (`grep -rl "ContratoServico::" tests/` → 33 matches). Destes, **4 arquivos citam explicitamente "Gestão"/setor performance** e portanto criam contratos que, com o Observer ativo, passarão a gerar onboarding de verdade (hoje não geram nada): `tests/Feature/Phase112HubspotHandoffWebhookTest.php`, `tests/Feature/Phase113HubspotDedupTest.php`, `tests/Feature/Phase37ComercialListagemTest.php`, `tests/Feature/Phase37CompaniesPerformanceFilterTest.php`. Estes são os candidatos de maior risco de quebra quando o Observer entrar — o planner deve rodar esses 4 arquivos filtrados antes/depois de implementar o Observer.
- O restante dos 33 usam serviços fora do setor `performance` (Polos, Publicação etc.) — o Observer, se gatilhado só quando existe template PUBLICADO para o `servico_id` do contrato (o que só existe para Gestão/id=6 na v1), não deve afetá-los.

### C. Modelo de dados e migrations

**Convenção de migrations do projeto (observada em ~15 arquivos lidos):**
- Nome: `YYYY_MM_DD_HHMMSS_verbo_substantivo.php`.
- `Schema::hasTable(...)` / `Schema::hasColumn(...)` como guarda de idempotência antes de `create`/`table` (ex.: `2026_07_07_100001_create_nps_templates_v15_tables.php:47,107,152,187,217`).
- `down()` sempre симétrico e em ordem REVERSA de dependência de FK (exemplo com docblock explícito: mesma migration, linhas 270-288).
- FK padrão: `$table->foreignId('x_id')->constrained('tabela')->cascadeOnDelete()` para "apagar pai apaga filho" (perguntas/opções de template NPS), ou `nullOnDelete()` quando o histórico deve sobreviver à exclusão do pai (`nps_response_answers.template_question_id`, linha 228-231). **Para `template_passos.depende_de`** (auto-referência a outro passo do mesmo template), o padrão análogo mais próximo seria `nullOnDelete()` ou simplesmente um `unsignedBigInteger` sem FK rígida referenciando `chave` (string) em vez de `id` — a modelagem exata não tem precedente direto no projeto e cabe ao planner decidir com base no risco de referenciar por FK (`id`) vs. por `chave` (string, mais estável entre versões).
- Comentários de coluna em português via `->comment('...')` são convenção reforçada (visto em quase toda migration recente).

**Armadilha SQLite vs MariaDB — CONFIRMADA e ATIVA neste projeto:** `phpunit.xml:27-28` define `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` para toda a suíte de testes. Produção usa MariaDB (`CLAUDE.md`, seção Configuration: "MySQL/MariaDB usado em produção"). A sintaxe `UPDATE <tabela> <alias> SET ...` (JOIN-update, só-MySQL) **não roda em SQLite** e derruba a suíte — já registrada como incidente conhecido em `.planning/learnings/painel-polos-status-e-meta.md` (migration do Decola/Painel Polos, 2026-08-03). Nenhuma migration nova desta fase deve usar essa sintaxe; usar `DB::table(...)->update([...])` do Query Builder (portável) ou `Schema::table` + `Blueprint` para alterações estruturais.

**JSON em coluna:** padrão confirmado — `$table->json('coluna')->nullable()` na migration (`2026_05_11_000001_create_mlb_implementacoes_table.php:15`, coluna `dados`) + `protected $casts = ['dados' => 'array']` no model (`MlbImplementacao.php:51`). `onboarding_passos.valor` e `template_passos.condicao` devem seguir exatamente esse padrão. **Limitação MariaDB:** não identificada nenhuma limitação de tamanho documentada no código para colunas `json` — MariaDB armazena `JSON` como `LONGTEXT` internamente (conhecimento de treinamento, `[ASSUMED]`, não verificado nesta sessão); não é um risco prático para os payloads pequenos que este schema propõe (poucos campos por passo).

**Guarda contra ciclo em `depende_de` (critério 8):** `grep` por "ciclo"/"cycle"/"topological"/"dependency graph" em `app/` não encontrou nenhum precedente de detecção de ciclo em grafo de dependências no projeto — os matches encontrados eram sobre "cache cycle" ou "job cycle", assuntos não relacionados. **Não há código para reaproveitar aqui; é implementação nova.** Recomendação (não uma decisão — cabe ao planner escolher a camada exata): a validação de ciclo é barata o suficiente (poucos passos por template, grafo pequeno) para caber tanto num `FormRequest` (validação síncrona ao salvar) quanto num Service chamado por ele — o padrão do projeto tende a colocar regra de negócio em Service (`app/Services/`) e deixar o `FormRequest` só com validação de shape, então a checagem de ciclo (DFS) provavelmente pertence a um Service chamado a partir da validação customizada do `FormRequest` (`Rule::closure` ou método `withValidator`).

### D. Versionamento de template (D-07)

**Não existe precedente de versionamento numérico imutável (`versao` inteiro incremental + `publicado_em`) em nenhuma migration do projeto.** Busca por colunas `versao integer/unsignedInteger` em `database/migrations/` não encontrou nenhum resultado relevante (os 2 falsos-positivos encontrados — `sugador_provider_runs_and_items` e `nps_respostas_customizadas` — usam "versao" com outro sentido, não como versionamento de definição).

**O precedente mais próximo é `nps_templates` (Fase 68, migration `2026_07_07_100001_create_nps_templates_v15_tables.php`), mas o mecanismo é DIFERENTE do que a D-07 pede:**
- `nps_templates` é **editado in-place** (não gera nova versão) — `active`/`is_default`/`priority` controlam qual template está em vigor.
- A imutabilidade histórica não vem de versionar o TEMPLATE, vem de **congelar a RESPOSTA**: `nps_response_answers` guarda colunas `*_snapshot` (texto/peso/dimensão da pergunta **no momento da resposta**) junto com FK viva `nullOnDelete()` para a pergunta/opção original (linhas 217-266). Ou seja: o histórico sobrevive a edições do template porque cada resposta carrega sua própria cópia dos dados relevantes, não porque referencia uma versão imutável do template.

**Isso é estruturalmente diferente do que a D-07 exige:** "salvar template publica versão N+1; onboardings em andamento seguem na versão em que nasceram" implica que a IMUTABILIDADE está no lado do TEMPLATE (nova versão = nova linha em `onboarding_templates` + novas linhas em `template_passos`, nunca `UPDATE` nas antigas), e `onboardings.template_id` aponta para essa linha congelada — mais parecido com um esquema de "content versioning" por linha nova do que com o padrão snapshot-por-resposta do NPS. **Esta é uma técnica nova para o projeto — não há atalho a copiar, o planner deve desenhá-la do zero**, mas pode reaproveitar a TÉCNICA (não o schema) do índice único parcial usado em `nps_templates` para `is_default` (`2026_07_07_100001...php:87-99`) — útil para garantir "só uma versão `ativo=true` por `servico_id`" em `onboarding_templates`, com a mesma bifurcação de driver:

```php
// Padrão reaproveitável — app/database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php:90-99
$driver = DB::connection()->getDriverName();
if ($driver === 'sqlite') {
    DB::statement("CREATE UNIQUE INDEX ... ON tabela(coluna) WHERE coluna = 1");
} else {
    // MySQL/MariaDB: coluna virtual gerada + unique nela (NULL não colide)
    DB::statement("ALTER TABLE tabela ADD COLUMN coluna_key TINYINT GENERATED ALWAYS AS (CASE WHEN coluna = 1 THEN 1 END) VIRTUAL");
    DB::statement("ALTER TABLE tabela ADD UNIQUE INDEX ... (coluna_key)");
}
```

Isso é necessário porque **MariaDB (produção) não suporta índice único parcial nativo** como o SQLite (`3.31+`) suporta — a migration acima já resolve isso com o truque de coluna gerada, e é o único lugar do projeto que precisou disso.

### E. Link público por empresa (D-06)

**Como o Polos faz hoje (molde, NÃO tocar):**
- Rotas públicas sem grupo `auth`: `routes/web.php:90-91` (`GET/PATCH /implementacao/{token}`), `:94-95` (`GET /implementacao/{token}/publicador`, `PATCH .../checkin`).
- Rotas administrativas (dentro do grupo autenticado, linhas 955-981): geração de link (`gerarLink`, linha 968), CRUD de blocos, etc.
- **CSRF desligado** para o prefixo inteiro: `bootstrap/app.php:21-24` — `$middleware->validateCsrfTokens(except: ['implementacao/*', 'api/webhooks/*'])`.
- Geração de token: `MlbImplementacaoController::gerarLink()` (`app/Http/Controllers/MlbImplementacaoController.php:576-590`) — `Str::random(48)` via `firstOrCreate(['empresa_id' => $empresa->id], ['token' => Str::random(48), ...])`. **Um link por MlbEmpresa** (mesmo princípio de "um por empresa" que D-06 quer, mas ancorado em `MlbEmpresa`, não em `Company`).
- Unicidade: `$table->string('token', 64)->unique()` (`database/migrations/2026_05_11_000001_create_mlb_implementacoes_table.php:14`) — constraint de banco, não checagem em aplicação.
- **Sem expiração:** nenhuma coluna `expires_at`/`token_expira_em` encontrada nas migrations de `mlb_implementacoes` lidas (7 migrations incrementais revisadas por nome — nenhuma adiciona expiração).
- Método público: `workspace(string $token)` (`MlbImplementacaoController.php:967-1008`) — `MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail()`, sem nenhum middleware de auth, renderiza `Inertia::render('Mlb/ImplementacaoPublica', [...])`.

**Prefixo novo para o motor geral, sem colidir com Polos:** o prefixo `implementacao/*` já está reservado e isento de CSRF para o Polos. A fase 135 precisa de um prefixo distinto (ex.: `onboarding-cliente/*` ou similar — a escolha exata é do planner/discuss, não decidida no CONTEXT) com **sua própria entrada** no array `except` de `validateCsrfTokens` em `bootstrap/app.php:21-24`.

**Como um passo `dono=cliente` de mesma `chave` em serviços diferentes fecha uma vez só (D-10):** isso implica que a "unidade de exibição" no formulário público não é `onboarding_passos` diretamente, mas algo agregado por `chave` na camada de leitura — quando o cliente marca um passo de `chave=X` como feito no contexto do onboarding de Gestão, o passo de `chave=X` no onboarding de outro serviço ativo da mesma empresa (se existir e também tiver essa chave) precisa refletir "feito" sem duplicar a UI. Como a v1 só tem o template de Gestão (D-08), esse comportamento **não tem como ser exercitado de verdade nesta fase** — mas o campo `chave` precisa nascer nas tabelas desde já (D-10), e a lógica de agregação por `chave` na tela pública é a parte que o planner precisa desenhar mesmo sem um segundo template para testar contra.

### F. Frontend

**Páginas existentes mais próximas (analogia por forma, não por domínio):**

| Página nova (a criar) | Analogia mais próxima | Caminho |
|---|---|---|
| CRUD de template (admin monta os passos) | Editor de template NPS com perguntas/opções aninhadas | `resources/js/Pages/Nps/Configuracao.jsx` + `resources/js/Components/Nps/Config/*` (componentes: `TemplatesGrid`, `TemplateEditForm`, `QuestionEditor`, `ServiceScopesModal`, `PreviewFormulario`, `ToastSalvo`) |
| Painel operacional (`/onboarding`, lista + drill-down) | Painel Polos unificado (grade de lentes) | `resources/js/Pages/Polos/Painel.jsx` (SÓ como referência de forma — não copiar; D-02 proíbe reuso direto de código de Polos) |
| Formulário público do cliente | Workspace público de implementação | `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` |
| CRUD simples de catálogo (referência menor) | Catálogo de serviços | `resources/js/Pages/Servicos/Index.jsx` + `app/Http/Controllers/ServicoController.php` |

**Controller admin análogo mais próximo:** `NpsTemplateController` (`routes/web.php:36-38,176-250` aprox.) — rotas `index/store/update/toggle-active/set-principal/duplicar/destroy` sob `Route::middleware(['auth','verified','role:admin'])` (`routes/web.php:157`). É o modelo mais direto para um `OnboardingTemplateController`.

**Componentes reusáveis confirmados:**
- `cn()` — `resources/js/lib/utils.js:1-6` (clsx + tailwind-merge), usado em todo componente novo.
- `Pages/Polos/components/CustIdCell.jsx` — componente compartilhado entre Polos e Onboarding de Polos (comentário no topo do arquivo confirma: "Extraída de Polos/Painel.jsx para o Onboarding usar a MESMA UX"). Não é diretamente aplicável ao motor novo (é sobre `cust_id`), mas é o precedente de "extrair componente para reuso entre módulos" que a fase 135 deve seguir se algo do painel novo precisar ser compartilhado entre Gestão e um futuro template.
- **`DevCard` NÃO é um componente compartilhado.** Apesar de o `CLAUDE.md` do projeto mencionar "componente `DevCard`... já existentes — manter consistência", uma busca em `resources/js/` só encontra `DevCard` definido **localmente dentro de `Pages/Dev/Desenvolvimento.jsx`** (convenção do projeto: "Local sub-components defined in the same file as the page if used only within that page"). Não há `resources/js/Components/DevCard.jsx`. **Não force o uso de `DevCard` nesta fase** — ele pertence à página `/dev/desenvolvimento`, um módulo diferente (painel de diagnóstico de sync Adman) do escopo desta fase (onboarding por serviço). Use os primitivos Radix já padronizados em `resources/js/Components/ui/` em vez disso.

**Armadilhas de frontend já registradas no projeto (aplicam-se a qualquer página nova desta fase):**
- **Página React de re-export puro some do manifest do Vite:** `export { default } from '../../Outro/Index'` não entra no bundle e a rota quebra em runtime (não no build) — usar sempre um wrapper real que importa e renderiza (`.planning/learnings/painel-polos-status-e-meta.md`, seção 4).
- **`<SelectItem value="">` do Radix Select derruba o render** — usar sentinela não-vazia para "sem valor" (ex.: `SEM_VALOR`), padrão já registrado em memória do projeto.

### G. Testes

- Estrutura: `tests/Unit/` + `tests/Feature/` (`phpunit.xml:8-13`). Convenção observada: pastas `tests/Feature/PhaseNNN/` ou `tests/Feature/PhaseNNNAlgumaCoisaTest.php` — a fase 135 deve seguir `tests/Feature/Phase135/...Test.php`.
- Driver de banco: **SQLite `:memory:`** (`phpunit.xml:27-28`) — ver Pitfall de sintaxe MySQL-only em migrations (seção C).
- `QUEUE_CONNECTION=sync` em teste (`phpunit.xml:31`) — jobs despachados em teste **rodam inline, na mesma request**, a menos que o teste use `Queue::fake()`. Isso é bom para testar o resultado final dos resolvers (não precisa de worker), mas significa que um teste que dispare o resolver do passo 4 (Adman) **vai tentar fazer a chamada HTTP de verdade** a menos que o teste faça mock do `AdmanService` (via `Http::fake()` ou substituindo o binding) — necessário para não travar a suíte em rede real.
- **Nenhuma factory para `ContratoServico`** — todo teste usa `::create()` explícito (ver Achado B). O planner deve considerar criar `ContratoServicoFactory` como parte do Wave 0 se os testes novos desta fase precisarem gerar contratos em variedade.
- `CompanyFactory` (`database/factories/CompanyFactory.php:21-27`) tem defaults: `active=true`, `marketplace='meli'`, `adman_account_id=null`, `ml_store_id=null` — uma empresa de teste "crua" já nasce sem `adman_account_id`, útil como fixture natural para testar o estado "passo 3 não coletado".
- **`artisan test` sem filtro não conclui** (timeout documentado em `MercadoLivreAdsService`, já registrado nas memórias do projeto). Rodar sempre filtrado: `C:\xampp\php\php.exe artisan test --filter=Phase135` (ajustar o filtro ao nome real da suíte criada).
- `php` não está no PATH do Bash tool deste ambiente — usar caminho completo `C:/xampp/php/php.exe` (barra normal, não invertida — confirmado nesta sessão: `C:\xampp\php\php.exe` falhou por escaping do Git Bash, `C:/xampp/php/php.exe` funcionou).

## Don't Hand-Roll

| Problema | Não construa | Use em vez disso | Por quê |
|---|---|---|---|
| Sonda de "cust_id tem grant Adman" | Novo endpoint/heurística própria | `AdmanService::fetchPerformance($custId, $ontem, $ontem, 3, $marketplace)` — mesmo padrão de `DiagnoseCustId`/`MarkCustIdStatus` | Já testado em produção contra dados reais; reinventar arrisca repetir o erro já corrigido (endpoint `/accounts/{custId}/metrics` que 404 em contas válidas) |
| Registro de observer de criação de model | Lógica duplicada em cada controller | `#[ObservedBy(...)]` no model, igual `MlbEmpresa` | É o padrão vigente do projeto (Laravel 11+), evita reintroduzir a duplicação que a própria D-13 está corrigindo |
| Refresh de token ML | Chamada manual de refresh antes de ler `seller_reputation` | `MercadoLivreService::ensureValidToken()`/`fetchUserInfo()` já fazem isso | `ensureValidToken()` já cobre expiração e marca `revoked` em falha |
| Detecção de "cust_id inválido" | Reimplementar a classificação de `DiagnoseCustId` | Copiar/estender a MESMA lógica (`validarViaAdman`) já duplicada intencionalmente em `MarkCustIdStatus` | O projeto já aceitou a duplicação desses dois métodos como trade-off deliberado (docblock de `MarkCustIdStatus.php:20-23`) — não é preciso extrair um terceiro helper agora |

**Key insight:** os problemas "difíceis" desta fase (sonda de grant, timing de coleta assíncrona, accessor de `adman_account_id`) já foram resolvidos ou sofridos pelo projeto em fases anteriores — o risco real não é technical debt novo, é **ignorar esses precedentes e reintroduzir bugs já corrigidos** (ex.: usar `/accounts/{custId}/metrics` como sonda, ou ler `companies.adman_account_id` via SQL cru ignorando o pivot `company_marketplaces`).

## Common Pitfalls

### Pitfall 1: Ler `adman_account_id` ignorando o pivot `company_marketplaces`
**O que dá errado:** resolver do passo 3 lê a coluna flat direto (SQL cru ou `getRawOriginal`) e retorna "não coletado" para empresa que na verdade já tem `adman_account_id` migrado para o pivot.
**Por que acontece:** desde Phase 57 (v13.0), `Company::getAdmanAccountIdAttribute()` (`Company.php:611-620`) lê primeiro do pivot `company_marketplaces` (`marketplace='meli'`).
**Como evitar:** sempre usar `$company->adman_account_id` (accessor Eloquent), nunca query direta na coluna.
**Sinais de alerta:** empresa que aparece com faturamento Adman normal no Dashboard, mas o passo 3 do onboarding nunca fecha.

### Pitfall 2: Resolver de Adman rodando síncrono na request HTTP
**O que dá errado:** timeout de nginx/php-fpm quando o painel/API tenta resolver o passo 4 ou 7 em tempo real.
**Por que acontece:** `fetchPerformance()` tem `timeout(120)` e pode retentar até 3x com backoff (`AdmanService.php:307-332`); é exatamente o tipo de chamada que o `CLAUDE.md` do projeto já proíbe rodar fora de Job ("Long external API calls (Adman) must go through Jobs to avoid nginx/php-fpm timeout").
**Como evitar:** todo resolver que toca Adman/ML deve rodar em `ShouldQueue`, seguindo o molde de `SyncAdmanCompanyJob` (`app/Jobs/SyncAdmanCompanyJob.php`).
**Sinais de alerta:** 504/502 no painel de onboarding, workers acumulando.

### Pitfall 3: "Dispara e lê" síncrono para o resolver de anúncios (passo 8)
**O que dá errado:** o resolver chama `mlb:sync-acervo --company={id}` e tenta ler `ml_acervo_itens` imediatamente depois, no mesmo ciclo — mas o comando só enfileira, não coleta.
**Por que acontece:** `SyncMlAcervo.php` faz `dispatch()` com `delay()`, e o job da camada barata tem `timeout=1800` (`SyncMlAcervoCompanyJob.php:41`) — para contas grandes pode levar até 30 minutos reais.
**Como evitar:** desenhar um terceiro estado ("aguardando coleta") e reavaliar em uma passada posterior (próxima carga de página, ou um comando agendado de reavaliação de passos automáticos pendentes) — nunca bloquear a request esperando o job.
**Sinais de alerta:** onboarding preso em "processando" indefinidamente sem nenhuma sinalização de progresso.

### Pitfall 4: `UPDATE tabela alias SET` em migration nova
**O que dá errado:** migration passa no MariaDB de produção mas derruba `artisan test` inteiro no SQLite.
**Por que acontece:** sintaxe de JOIN-update é MySQL/MariaDB-only; incidente já registrado no projeto (Painel Polos, `2026_08_03_140000_change_decola_to_string_in_mlb_implementacoes.php` — ver `.planning/learnings/painel-polos-status-e-meta.md`).
**Como evitar:** usar `DB::table(...)->update([...])` do Query Builder para qualquer backfill de dados dentro de migration.
**Sinais de alerta:** `artisan test` falha em massa logo após uma migration nova, com erro de sintaxe SQL.

### Pitfall 5: Observer disparando em massa em `CompanyGroupController::atribuirServico`
**O que dá errado:** atribuir um serviço a um grupo com N empresas cria N `ContratoServico` num loop sem `DB::transaction()` — se o Observer fizer qualquer trabalho síncrono não-trivial, a request fica lenta/instável, e uma falha no meio deixa contratos parcialmente criados (sem atomicidade).
**Por que acontece:** `CompanyGroupController.php:73-93` não envolve o loop numa transação.
**Como evitar:** o Observer deve ser leve (só criar `Onboarding`/`OnboardingPasso`, sem chamadas de rede); qualquer resolver pesado fica em Job disparado a partir do Observer, nunca executado inline nele.
**Sinais de alerta:** request de "atribuir serviço a grupo" ficando visivelmente mais lenta depois desta fase.

### Pitfall 6: `companies.cust_id_status` como fonte de verdade do grant
**O que dá errado:** confiar que essa coluna reflete o estado atual do grant Adman.
**Por que acontece:** o comando que a popula (`dashboard:mark-custid-status`) **não está agendado** (`MarkCustIdStatus.php:44`) — para uma empresa recém-onboardada, a coluna fica em `desconhecido` (default) indefinidamente até alguém rodar o comando manualmente.
**Como evitar:** o resolver do passo 4 deve manter seu PRÓPRIO estado (em `onboarding_passos.valor` + timestamp), nunca ler `cust_id_status`.
**Sinais de alerta:** passo 4 nunca resolve mesmo com grant Adman comprovadamente ativo.

### Pitfall 7: Regressão silenciosa em 4 testes que já criam contrato de Gestão
**O que dá errado:** `tests/Feature/Phase112HubspotHandoffWebhookTest.php`, `Phase113HubspotDedupTest.php`, `Phase37ComercialListagemTest.php`, `Phase37CompaniesPerformanceFilterTest.php` passam a disparar o Observer (criam `ContratoServico` para o serviço "Gestão", id 6, setor performance) e podem quebrar se fizerem asserções de contagem estrita de linhas/registros.
**Como evitar:** rodar esses 4 arquivos filtrados antes e depois de implementar o Observer; ajustar fixtures se necessário.

## Code Examples

### Registro de Observer via atributo PHP (padrão a seguir)
```php
// Fonte: app/Models/MlbEmpresa.php:1-16 (molde real, produção)
namespace App\Models;

use App\Observers\MlbEmpresaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(MlbEmpresaObserver::class)]
class MlbEmpresa extends Model
{
    use LogsActivity;
    // ...
}
```

### Sonda de grant Adman (padrão já validado em produção — usar como base do resolver do passo 4)
```php
// Fonte: app/Console/Commands/DiagnoseCustId.php:288-315 (adaptar: retornar enum/bool em vez de imprimir)
private function validarViaAdman(?string $custId, string $data, string $marketplace = 'meli'): string
{
    if (!$custId) {
        return self::CAT_OK;
    }

    try {
        $this->adman->fetchPerformance($custId, $data, $data, 3, $marketplace);
        $categoria = self::CAT_VALIDADO_API; // grant ativo — independe de haver movimento no dia
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '500') || str_contains($msg, '400') || str_contains($msg, '404')) {
            $categoria = self::CAT_INVALIDO_CONFIRMADO; // Adman não reconhece o cust_id
        } else {
            $categoria = self::CAT_ERRO_INDEFINIDO; // 429/timeout — indeterminado, não conclua
        }
    }

    usleep(7_000_000); // ADMAN_RATE_LIMIT_RPM = 10 — throttle sempre, mesmo em erro

    return $categoria;
}
```

### Job assíncrono para chamada Adman (padrão a seguir para qualquer resolver que toque rede)
```php
// Fonte: app/Jobs/SyncAdmanCompanyJob.php (molde completo)
class SyncAdmanCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly Company $company, public readonly ?string $date = null) {}

    public function backoff(): array { return [60, 300, 900]; }

    public function handle(AdmanService $adman): void
    {
        $adman->syncCompany($this->company, $this->date);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncAdmanCompanyJob] Falha definitiva empresa {$this->company->id}: {$e->getMessage()}");
    }
}
```

### Índice único parcial multi-driver (reaproveitar para "1 versão ativa por serviço")
```php
// Fonte: database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php:90-99
$driver = DB::connection()->getDriverName();
if ($driver === 'sqlite') {
    DB::statement("CREATE UNIQUE INDEX nps_templates_default_uniq ON nps_templates(is_default) WHERE is_default = 1");
} else {
    // MySQL 5.7+/MariaDB 10.2+: coluna virtual gerada + unique nela — NULL não colide
    DB::statement("ALTER TABLE nps_templates ADD COLUMN is_default_key TINYINT GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 END) VIRTUAL");
    DB::statement("ALTER TABLE nps_templates ADD UNIQUE INDEX nps_templates_default_uniq (is_default_key)");
}
```

## State of the Art

Não aplicável de forma tradicional (não há "abordagem antiga sendo substituída por uma nova" em nível de biblioteca). O único "antes/depois" relevante é conceitual: o onboarding de Polos (checklist hardcoded, `MlbImplementacao::CHECKLIST`, 15 itens fixos, sem dono/dependência) é o "antes" que esta fase substitui com um motor dirigido por template — mas por decisão D-02 o "antes" continua rodando em produção, intocado, ao lado do "depois".

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | Parsing de "reputação verde · medalha · Full" a partir de `seller_reputation` (payload `/users/{id}` da API ML) — campos exatos e onde "Full" aparece nesse payload | Achado A.5 | Resolver do passo 7 pode não conseguir extrair "Full" do endpoint que hoje é chamado; pode precisar de endpoint adicional não mapeado nesta pesquisa |
| A2 | `$role` correto para `Company::responsavelDoServicoOuConsolidado()` no contexto do onboarding de Gestão é `'consultor'` | Achado B | Se o papel certo for outro (`estrategista`/`analista`), a sugestão de responsável (D-17) aponta para a pessoa errada |
| A3 | "Inativos" para o passo 8 = `status` em `['paused','closed','under_review']` (tudo que não é `active`) | Achado A.4 | Contagem de anúncios inativos pode incluir/excluir estados que o produto não considera "inativo" de verdade |
| A4 | MariaDB não impõe limite prático de tamanho para coluna `json` nos payloads pequenos que este schema propõe | Seção C | Risco muito baixo dado o volume de dados por passo, mas não foi verificado nesta sessão contra a versão exata do MariaDB em produção |

**Se esta tabela parecer curta:** é porque a maior parte das afirmações desta pesquisa foi verificada por leitura direta de código (`[VERIFIED]`), não por conhecimento de treinamento — o domínio "o que já existe no projeto" dominou sobre o domínio "o que a API externa do ML faz exatamente".

## Open Questions

> **Estado em 2026-08-11 (revisão de planejamento).** As três questões abaixo estão endereçadas: OQ1 e OQ2 **resolvidas**, OQ3 **parcialmente resolvida** com verificação agendada. Ver o campo **Status** ao fim de cada item — nenhuma delas segue em aberto bloqueando a execução da fase.

1. **Inconsistência entre `ROADMAP.md` (SC-06) e a tabela do `135-CONTEXT.md` sobre quantos passos são `dono=sistema`**
   - O que sabemos: `ROADMAP.md:1769` (critério de sucesso 6) diz "Os 5 passos de `dono=sistema`... `adman_account_id`, grant Adman, `ml_tokens.status`, `ml_acervo_itens`, `fetchUserInfo()`" — listando `ml_tokens.status` (passo 5) como um dos 5 `dono=sistema`. Mas a tabela do template no `135-CONTEXT.md` (linha 139) marca o passo 5 explicitamente como `dono=cliente` (não em **negrito**, ao contrário dos passos 3,4,7,8 que estão marcados `**sistema**`).
   - O que não está claro: se isso é uma imprecisão de linguagem do ROADMAP (usando "dono=sistema" como sinônimo solto de "resolvido automaticamente", quando na verdade só 4 dos 5 passos automáticos têm `dono=sistema` — o passo 5 tem `dono=cliente` mas TAMBÉM tem um resolver automático que detecta quando o cliente completou a ação), ou se é uma divergência real que precisa de decisão do usuário.
   - Recomendação: **não é um conflito de código com decisão travada** (por isso não vai em "Conflitos com o CONTEXT") — é uma imprecisão textual entre dois documentos do próprio usuário/`AskUserQuestion`. Tratar a tabela detalhada do CONTEXT (mais granular, com `depende_de` explícito) como fonte de verdade: 5 passos têm resolver automático (3,4,5,7,8), mas só 4 têm `dono=sistema` (3,4,7,8) — o passo 5 mantém `dono=cliente` (é o cliente quem precisa AGIR, o sistema só detecta) e ainda assim conta como um dos "5 automáticos" da D-03/critério 7. O planner deve confirmar esta leitura com o usuário antes de travar a modelagem de `dono` no schema, dado que SC-06 é um critério de sucesso formal.
   - **Status: RESOLVIDA** — fechada pela **D-19** do `135-CONTEXT.md`: `dono` e `auto_fonte` são eixos independentes. 5 passos têm resolver automático (3, 4, 5, 7, 8) e só 4 têm `dono = sistema` (3, 4, 7, 8); o passo 5 mantém `dono = cliente` **e** `auto_fonte = ml_token_ativo`. Materializada no seeder do **Plano 04** (`OnboardingTemplateGestaoSeeder`, critério de aceite: `dono='sistema'` = 4 e `grant_sistema_ecf` com `dono='cliente'` + `auto_fonte='ml_token_ativo'`) e no `MlTokenAtivoResolver` do **Plano 03**.

2. **Qual role/permission gate o painel operacional `/onboarding`?**
   - O que sabemos: D-04 diz explicitamente que o CRUD de template é "Admin pela UI" — isso mapeia diretamente para `role:admin` (mesmo padrão de `NpsTemplateController`, `routes/web.php:157`). O painel operacional de acompanhamento (onde "a Coordenação escolhe o serviço" e confirma responsável, D-05) não tem o mesmo mandato explícito — pode ser mais amplo que `role:admin` (ex.: também estrategistas/consultores da carteira daquela empresa).
   - O que está incerto: se existe uma permission específica a criar (`onboarding.gerenciar`, seguindo o padrão `EnsurePermission`/`permission:` já usado em outras telas, ex. `permission:sistema.shopee_oauth`) ou se `role:admin` basta para toda a v1.
   - Recomendação: decisão de produto pendente para o discuss-phase ou para o planner assumir com discrição documentada — não é algo que a leitura de código resolve sozinha.
   - **Status: RESOLVIDA** — por discrição documentada do planner no **Plano 09**: permission dedicada `core.onboarding` (`Permissions::CORE_ONBOARDING`), com short-circuit de admin, no gate de `/onboarding` (`middleware('permission:core.onboarding')`); o CRUD de template segue em `role:admin` conforme a D-04. O plano inclui testes de 403/200 para os três perfis.

3. **Exato payload de `/users/{id}` da API do Mercado Livre para "medalha" e "Full"**
   - Ver Assumption A1. Recomendação: sub-tarefa de descoberta (chamar a API real com uma conta de teste válida e inspecionar o JSON) antes de implementar o parsing do passo 7 — não travar isso na pesquisa sem dado real.
   - **Status: PARCIALMENTE RESOLVIDA** — duas metades. (a) O **Plano 06** implementa o parsing **defensivo** e marcado `[ASSUMIDO]` no docblock: campo ausente entra em `valor['nao_obtidos']` e vale `null`, nunca `false`, nunca `0`, nunca exceção — o passo 7 conclui com o que conseguiu ler. (b) A conferência contra uma conta ML real está **agendada** como item 5 do checkpoint manual do **Plano 13**, com instrução explícita do que ajustar se o payload divergir (`MetricasContaResolver` + `OnboardingResolverMetricasTest`) e obrigação de registrar o payload observado no `135-GATE-FINAL.md`. Fecha de vez só quando esse checkpoint for executado.

## Environment Availability

Não aplicável em profundidade — esta fase não introduz nenhuma dependência externa nova. As integrações que os resolvers usam (Adman API, API do Mercado Livre via OAuth) já estão configuradas e em uso contínuo em produção (cron `adman:sync` às 11:00, `ml:sync`, etc. — `routes/console.php`), então a disponibilidade já é uma condição operacional existente, não algo a auditar nesta pesquisa.

| Dependência | Requerido por | Disponível | Versão/Config | Fallback |
|---|---|---|---|---|
| Adman API (`api.adman.com.br`) | Passos 3,4,7 | ✓ (já em uso em produção) | `config/services.php:38-41`, `ADMAN_API_KEY` no `.env` | Se sonda falhar de forma persistente, passo 4 cai para dono `interno` com checagem manual (previsto no próprio D-18) |
| API Mercado Livre (OAuth) | Passos 5,7,8 | ✓ (já em uso em produção) | `config/services.php:48+` | — |
| Fila `database` (queue) | Resolvers 4,7,8 (assíncronos) | ✓ | `QUEUE_CONNECTION=database` em produção; `sync` em teste (`phpunit.xml:31`) | — |

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `C:/xampp/php/php.exe artisan test --filter=Phase135` |
| Full suite command | `C:/xampp/php/php.exe artisan test` — **NÃO usar sem filtro**: conhecidamente não conclui (timeout em `MercadoLivreAdsService`, já registrado nas memórias do projeto) |

### Phase Requirements → Test Map

| SC-ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| SC-01 | `Onboarding` criado ancorado em `Company × Servico`, um por contrato | Feature | `artisan test --filter=OnboardingCriacaoTest` | ❌ Wave 0 |
| SC-02 | Onboarding de Polos intocado | Feature (regressão) | rodar suíte de Polos já existente + baseline conhecida de 10 falhas pré-existentes (`.planning/learnings/painel-polos-status-e-meta.md` §2) — comparar contagem antes/depois | Suítes existentes: `tests/Feature/Phase38/PolosControllerTest.php`, `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` |
| SC-03 | Observer cria onboarding em rascunho nos 4 call-sites | Feature | 1 teste por call-site (HubSpot webhook, Comercial, Company/Show, CompanyGroup) | ❌ Wave 0 |
| SC-04 | Rascunho sem responsável não vira `andamento`/não expõe link | Unit + Feature | teste do Service de transição de status | ❌ Wave 0 |
| SC-05 | Passo dependente nasce bloqueado, destrava sozinho | Unit | teste do avaliador de dependências | ❌ Wave 0 |
| SC-06 | 5 resolvers automáticos resolvem sem digitação | Unit (com `Http::fake()` para Adman/ML) | 1 teste por resolver | ❌ Wave 0 |
| SC-07 | Resolver distingue "não coletado" de "zero real" | Unit | teste específico do resolver de anúncios com `ml_acervo_itens` vazio vs. populado com zero ativos | ❌ Wave 0 |
| SC-08 | Guarda de ciclo em `depende_de` | Unit | teste do validador de ciclo (grafo com e sem loop) | ❌ Wave 0 |
| SC-09 | Nova versão de template não afeta onboardings em andamento | Feature | criar onboarding na v1 do template, publicar v2, confirmar `onboardings.template_id` inalterado | ❌ Wave 0 |
| SC-10 | Link único por empresa agrega passos `dono=cliente` | Feature | teste da rota pública nova | ❌ Wave 0 |
| SC-11 | Painel mostra "o que trava, há quantos dias, de quem é a bola" | Feature (Inertia) | teste de props da página do painel | ❌ Wave 0 |

### Sampling Rate

- **Por commit de task:** `artisan test --filter=Phase135` (suíte da fase).
- **Por merge de wave:** suíte completa filtrada por `Phase135` + os 4 arquivos de risco identificados no Achado B/Pitfall 7 (`Phase112HubspotHandoffWebhookTest`, `Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`, `Phase37CompaniesPerformanceFilterTest`).
- **Gate da fase:** antes de `/gsd:verify-work`, rodar também a suíte de Polos (`--filter=Phase38` ou caminho equivalente) e comparar contra a baseline de 10 falhas pré-existentes documentada em `.planning/learnings/painel-polos-status-e-meta.md` — qualquer falha NOVA ali é regressão real desta fase (viola D-02/SC-02).

### Wave 0 Gaps

- [ ] `database/factories/ContratoServicoFactory.php` — não existe; útil para os testes novos desta fase.
- [ ] `tests/Feature/Phase135/` — diretório e primeira suíte não existem ainda.
- [ ] Nenhuma instalação de framework necessária — PHPUnit já configurado e funcional.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Aplica | Controle padrão |
|---|---|---|
| V2 Authentication | Não (painel admin usa sessão já existente) | — |
| V3 Session Management | Não | — |
| V4 Access Control | Sim | `role:admin` middleware (CRUD de template, D-04) + permission a definir para o painel operacional (ver Open Question 2) |
| V5 Input Validation | Sim | `FormRequest` para template/passos; enum fechado de `dono`/`auto_fonte` (D-09/D-14) nunca texto livre |
| V6 Cryptography | Não diretamente — token de acesso público não é segredo criptográfico, é capacidade por posse (mesma classe do token de Polos) | `Str::random(48)` (alta entropia, ~285 bits) + `unique()` no DB |

### Known Threat Patterns for este domínio

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| Token do link público vazado/adivinhado (link do cliente sem auth, D-06) | Information Disclosure | Mesma mitigação já aceita para Polos: alta entropia (`Str::random(48)`), sem índice sequencial, `unique()` no banco. **Sem expiração** — risco já aceito no precedente (Polos), não uma regressão introduzida por esta fase; se o produto quiser expiração para o motor novo, é decisão nova a levantar, não algo herdado do molde |
| Contrato criado em massa (`CompanyGroupController::atribuirServico`) gerando N onboardings sem rate limit | Denial of Service (interno, não externo) | Observer deve permanecer leve (Pitfall 5); qualquer resolver de rede em Job, nunca inline |
| Ciclo em `depende_de` travando avaliação de passos (loop infinito na resolução de dependências) | Denial of Service | Validação de ciclo obrigatória no save do template (SC-08) — sem precedente de código a reaproveitar, ver Seção C |
| `auto_fonte` como texto livre permitindo apontar para resolver arbitrário/inexistente | Tampering | D-09 já trava isso: catálogo fechado registrado em código, nunca string livre vinda do request |

## Sources

### Primary (leitura direta de código — ALTA confiança)

- `app/Services/AdmanService.php` (1470 linhas, lido inteiro) — `fetchPerformance`, `fetchAccountMetrics`, `listAccounts`, `ADMAN_RATE_LIMIT_RPM`, comentário sobre 404 em `/accounts/{custId}/metrics`
- `app/Console/Commands/DiagnoseCustId.php` (343 linhas, lido inteiro) — sonda de grant validada
- `app/Console/Commands/MarkCustIdStatus.php` (380 linhas, lido inteiro) — confirma coluna `cust_id_status` não-agendada
- `app/Console/Commands/SyncAdmanData.php`, `SyncMlAcervo.php` (163 linhas, lido inteiro), `SyncMlAcervoCompanyJob.php` (89 linhas, lido inteiro)
- `app/Models/Company.php` (trechos: 249-307, 600-650) — accessor/mutator `adman_account_id`, `responsavelDoServicoOuConsolidado`
- `app/Models/ContratoServico.php`, `MlToken.php`, `MlAcervoItem.php`, `CompanyGrant.php`, `Servico.php` (lidos inteiros)
- `app/Models/MlbEmpresa.php` (trecho topo), `app/Observers/MlbEmpresaObserver.php` (lido inteiro)
- `app/Http/Controllers/Api/HubspotWebhookController.php` (trechos: 498-975) — `criarEmpresa`, `persistirContratos`, `rotearImplementacao`
- `app/Http/Controllers/ComercialController.php` (trechos: 54-62, 620-749)
- `app/Http/Controllers/CompanyController.php` (trechos: 930-975)
- `app/Http/Controllers/CompanyGroupController.php` (trechos: 40-103)
- `app/Http/Controllers/MlbImplementacaoController.php` (trechos: 26-41, 570-620, 895-1030)
- `app/Services/MercadoLivreService.php` (trechos: 250-310, 456, 514-529)
- `app/Services/Mlb/Acervo/MlAcervoService.php` (trecho: 177-227)
- `bootstrap/app.php` (lido inteiro), `routes/web.php` (trechos), `routes/console.php` (grep de schedules)
- `phpunit.xml` (lido inteiro)
- `database/migrations/2026_06_02_180000_add_cust_id_status_to_companies.php` (lido inteiro)
- `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php` (289 linhas, lido inteiro)
- `database/migrations/2026_05_11_000001_create_mlb_implementacoes_table.php` (grep de `token`)
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` (trecho: 125-160)
- `database/factories/CompanyFactory.php` (trecho: 21-27)
- `resources/js/lib/utils.js`, `resources/js/Pages/Polos/components/CustIdCell.jsx` (trecho topo), `resources/js/Pages/Nps/Configuracao.jsx` (trecho topo/imports)
- Consulta direta ao banco local via `artisan tinker` — catálogo real de `Servico` (11 linhas, confirma id=6 "Gestão" setor=performance)
- `grep -rl "ContratoServico::" tests/` — 33 arquivos, cruzado contra "Gestão"/performance para os 4 de maior risco

### Secondary (documentos do próprio projeto — ALTA confiança, mas não código)

- `.planning/phases/135-onboarding-geral-por-servico-motor-dirigido-por-template-com/135-CONTEXT.md` (lido inteiro)
- `.planning/ROADMAP.md` (seção Phase 135, linhas 1755-1789)
- `.planning/STATE.md` (trechos: cabeçalho + entrada de 2026-08-11 sobre a Fase 135)
- `.planning/learnings/painel-polos-status-e-meta.md` (lido inteiro — armadilhas de Polos, incidente SQLite/MySQL, página delegante sumindo do manifest)
- `CLAUDE.md` do projeto (fornecido no contexto da sessão)

### Tertiary (conhecimento de treinamento, não verificado nesta sessão — marcado `[ASSUMED]` no corpo do documento)

- Estrutura exata do payload `/users/{id}` da API do Mercado Livre para `seller_reputation`/medalha/Full (Achado A.5, Assumption A1)
- Limite prático de coluna `JSON` em MariaDB (Assumption A4)

## Metadata

**Confidence breakdown:**
- Sonda de grant Adman (D-18): ALTA — dois comandos de produção usando exatamente o mesmo padrão, lidos linha a linha
- Observer/call-sites de `ContratoServico`: ALTA — 4 call-sites lidos e confirmados contra as linhas citadas no CONTEXT
- Timing assíncrono de `mlb:sync-acervo`: ALTA — código dos jobs e do comando lidos inteiros
- Versionamento de template (D-07): MÉDIA — confirmado que não há precedente direto; a técnica de índice único parcial é reaproveitável, mas o schema de versionamento é novo
- Parsing de reputação/medalha/Full via API ML: BAIXA — nenhum consumidor existente no código, depende de inspeção da API real

**Research date:** 2026-08-11
**Valid until:** ~30 dias (stack estável; risco de obsolescência real só se a API Adman mudar contrato ou se `MarkCustIdStatus`/`cust_id_status` passar a ser agendado nesse meio tempo, o que mudaria o Achado A.2/Pitfall 6)
