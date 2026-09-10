# Phase 152: Checklist administrativo + trava de finalização (v23.0) - Research

**Researched:** 2026-09-09
**Domain:** Checklist de itens obrigatórios ancorado em `Company`, leitura de estado externo (Clicksign/ML OAuth), trava de transição de máquina de estados (`EtapaTransicaoService`, Fase 150)
**Confidence:** MEDIUM — a maior parte das perguntas bloqueantes (Q1-Q9) foi respondida por leitura direta de código com citação `arquivo:linha`; uma questão (Q7, transições intermediárias de etapa) não está resolvida em código nenhum e é sinalizada como conflito/lacuna, não uma suposição.

## Summary

O CONTEXT.md já travou 14 decisões (D-01 a D-14) com base em medição real contra o código — a maior parte se confirma nesta pesquisa. Três achados desta pesquisa **vão além do que o CONTEXT.md decidiu** e precisam de decisão explícita do planejador antes da task de implementação:

1. **Existe um sinal de conexão ML muito mais estável que o zeramento de `ml_link_url`** — a tabela `ml_tokens` (`Company::mlToken()`), com `status` (`active`/`expired`/`revoked`) e `connected_at`. Mais importante: **já existe um resolver na própria base** (`MlTokenAtivoResolver`, do motor de Onboarding da Fase 135) que resolve exatamente esta pergunta (`$company->mlToken?->status === 'active'`) para um item de checklist análogo. Esta pesquisa recomenda copiar esse padrão literalmente para o item 7.

2. **`ContratoAssinatura.status`/`assinado_em` não é o único caminho para "contrato assinado" em produção.** Existe uma liberação manual (`ContratoAdminController::liberarManual()` → `EmpresaOperacionalRouter::liberarEmpresa()`) que grava `ContratoLiberacao` (fato "liberado para o operacional") **sem nunca tocar** `contrato_assinaturas.status`/`assinado_em`. Se o item 3 do checklist ler só as colunas que o D-03 nomeia, uma empresa liberada manualmente (Clicksign fora do ar, cliente assinou fora do sistema) fica **presa para sempre** no ADMIN-05, porque o item nunca fecha. Isto é reportado como `⚠️ CONFLITO COM O CONTEXT.md` — não foi resolvido nesta pesquisa.

3. **Não existe, hoje, nenhum chamador de produção que transicione `companies.etapa` para os valores 2, 3 ou 4** (`administrativo_andamento`, `aguardando_assinatura`, `administrativo_concluido`). Os dois únicos call sites de `EtapaTransicaoService::transicionar()` (Fase 151) só escrevem etapa 1. A tabela `TRANSICOES_PERMITIDAS` só permite chegar em etapa 5 (`aguardando_distribuicao`) **a partir da etapa 4** — nunca direto da 1 ou 2. Isso significa que o próprio ato de "trabalhar o checklist" precisa avançar a etapa progressivamente (ou o FINALIZAR precisa encadear múltiplas chamadas a `transicionar()`), e **nenhuma decisão do CONTEXT.md cobre isso**. `ComercialEntradaController::index()` já consulta empresas nas etapas 1-4, o que sugere que popular essas etapas intermediárias é esperado — mas quem dispara cada uma não está definido em lugar nenhum.

**Primary recommendation:** seguir as 14 decisões travadas do CONTEXT.md ao pé da letra; para os 3 pontos acima, o planejador precisa decidir explicitamente antes de escrever tasks (não são bugs a corrigir silenciosamente — são lacunas de especificação).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Contagem e obrigatoriedade dos itens**

- **D-01:** A lista que vale é a do §5 — 9 itens, não os 12 do §3. Medida a diferença exata: o §5 é o §3 menos `acompanhar a assinatura`, `gerar mensagem de boas-vindas` e `inserir todos os links necessários`. Os três excluídos são passos intermediários que desembocam em item já controlado (`contrato assinado`, `boas-vindas enviada`) — marcá-los como concluídos não teria significado próprio. O §5 é a lista dos estados terminais, e é a lista que o próprio PDF diz que trava o FINALIZAR (ADMIN-05). → Fecha a decisão que o `ROADMAP.md` marcou como em aberto no bloco da Fase 152.

- **D-02:** Não existe estado "não aplicável". Todo item exibido é obrigatório. O usuário rejeitou explicitamente o `nao_aplicavel` do motor de Onboarding. ⚠️ Risco declarado e aceito: empresa que legitimamente não precise de um item não terá saída senão marcar "concluído" mentindo — o que apaga o rastro. A D-07 remove o único caso concreto conhecido hoje (isenção de contrato) por montagem condicional, não por marcação. Se aparecer um segundo caso, a decisão precisa ser reaberta, não contornada.

- **D-03:** Os 9 itens e como cada um fecha (a coluna "fecha por" é decisão, não sugestão):

  **Grupo Contrato — 3 itens, só existe quando `Servico::exigeContrato()` é `true` (ver D-07):**

  | # | Item | Fecha por | Fonte |
  |---|---|---|---|
  | 1 | Contrato revisado | manual, com autoria | nenhuma — ver D-06 |
  | 2 | Contrato enviado | auto | `contrato_assinaturas.enviado_em !== null` |
  | 3 | Contrato assinado | auto | `contrato_assinaturas.assinado_em` / `status === assinado` |

  **Grupo Entrada — 6 itens, sempre:**

  | # | Item | Fecha por | Fonte |
  |---|---|---|---|
  | 4 | Grupo de WhatsApp criado | manual, com autoria | nenhuma |
  | 5 | E-mail colaborador criado | manual, com autoria | nenhuma |
  | 6 | Link Adman entregue | manual, com autoria | nenhuma — link fixo, ver D-04 |
  | 7 | Grant da consultoria (= OAuth do Mercado Livre) | auto | cliente conectou — ver D-05 |
  | 8 | Conexão com o sistema ECF gerada | auto | `onboarding_links` da empresa existe |
  | 9 | Boas-vindas enviada | manual, com autoria | nenhuma |

  Total: 9 itens com contrato, 6 para serviço isento.

**Correção de duas premissas erradas do §3/§5 (medidas contra o código e confirmadas pelo usuário)**

- **D-04:** "Gerar link de conexão com a ADMA" está errado em dois níveis. Primeiro, o nome é Adman, não ADMA (correção do usuário, 2026-09-09). Segundo, e mais importante: não é um link gerado nem por empresa — é um link fixo de cadastro, idêntico para todas: `https://app.ad-man.io/register?ref=588D0DD78C4F`. Verificado: essa URL não existe em lugar nenhum do repositório hoje, e o `AdmanService` é somente leitura (puxa métricas; não há ação "conectar ao Adman" per-company). Onde mora: `config/services.php`, como `env('ADMAN_REGISTER_URL', 'https://app.ad-man.io/register?ref=588D0DD78C4F')`. Trocar o código `ref` em produção passa a ser mudança de `.env` na VPS — sem deploy e sem mudança de código, o mesmo padrão que a Fase 151 usou para as properties do HubSpot. Consequência para o item 6: não há estado observável, logo o item é manual.

- **D-05:** "Gerar Grant da consultoria" é o OAuth do Mercado Livre, não a planilha de grants por SFTP (confirmação literal do usuário: "seria o OAuth do mercado livre para o cliente conectar, nao tem nada com SFTP"). Isso derruba a leitura de que o item usaria `company_grants` / `grants:sync-sftp` — esse comando é global (baixa um XLSX inteiro e faz upsert para todas as empresas, com lockfile em `storage/app/grants_sync_status.json`), e nunca poderia ser acionado como "gerar para esta empresa". O que o item usa: `POST /companies/{company}/ml/initiate` → `MercadoLivreService::buildAuthUrl()` (`companies.ml_link_url` + `ml_link_generated_at`, TTL de 7 dias). Quando fecha: somente quando o cliente realmente conectou — o callback OAuth bem-sucedido — e não quando o link foi gerado. Link gerado ≠ link usado: ele expira em 7 dias e o checklist estaria mentindo o tempo todo. Detecção: `MercadoLivreOAuthController` zera `ml_link_url` no callback bem-sucedido, então "gerado mas não conectado" e "conectado" são distinguíveis. O planejador deve confirmar qual sinal é o mais estável (o zeramento é efeito colateral, não um carimbo de sucesso — se houver um campo de token/conexão mais explícito, prefira-o). ⚠️ Armadilha já registrada no projeto: clique interno por usuário logado na própria conta ML gera autorização FALSA (`project_polos_oauth_link_boas_vindas_260827`). Um botão "gerar link" dentro do checklist multiplica a chance de alguém clicar para testar — o desenho precisa levar isso em conta (copiar link ≠ abrir link).

- **D-06:** "Contrato revisado" é marcação MANUAL, com autoria. Revisar um contrato é ato humano e não deixa rastro digital; os 7 estados do envelope (`rascunho`, `aguardando_assinaturas`, `assinado`, `recusado`, `expirado`, `cancelado`, `erro`) não contêm "revisado", e não existe nenhum accessor ou scope derivado hoje em `ContratoAssinatura`. As alternativas foram apresentadas e recusadas: mapear para `ContratoDadosMinimosService::estaPronta()` mediria completude de cadastro, não revisão; `status !== rascunho` seria na prática a mesma condição de "enviado", tornando um dos dois itens decorativo. ⚠️ Isto é exceção explícita ao ADMIN-02, que diz que o grupo Contrato reflete o envelope "sem marcação manual paralela". A exceção deve ser registrada em `.planning/REQUIREMENTS-v23.md` junto ao ADMIN-02 — não deixada implícita no código.

**Isenção de contrato**

- **D-07:** O grupo Contrato não existe para serviço com `exige_contrato = 0`. O checklist é montado conforme o serviço: empresa isenta nasce com 6 itens, empresa com contrato nasce com 9, e o denominador do progresso acompanha. Não é `nao_aplicavel` marcado à mão (proibido pela D-02) — é o item nem existir para aquela empresa. Medido em 2026-09-09, não assumido: `Polos` (`servicos.id=2`) é o único serviço com `exige_contrato = 0`, e tem 5 vínculos `contratos_servico` ativos hoje. Sem esta decisão, essas 5 empresas — e toda Polos futura — teriam 3 itens pendentes para sempre e nunca poderiam finalizar. Reusa `Servico::exigeContrato()`, a mesma isenção (D9) que `ContratoAdminController::index()`, `ComercialController` e `ComercialEntradaController` já respeitam — nada novo a inventar.

**Tela e permissão**

- **D-08:** Uma ficha única por empresa, com as duas seções na mesma tela, aberta pelas duas listagens. Reusa `admin.contratos.show` → `resources/js/Pages/Admin/ContratoDetalhe.jsx` (1127 linhas), que já carrega `contratos_servico`, `ContratoAssinatura`, signatários, `podeGerarContrato` e `motivoBloqueio` — metade do que o checklist precisa já está no payload. A listagem Entrada ganha uma ação "Abrir" apontando para a mesma ficha (hoje `Comercial/Entrada.jsx` é listagem pura, 305 linhas, sem rota de detalhe). Motivo de fundo: o FINALIZAR precisa ficar onde dá para ver tudo que ele exige — separar as seções em duas telas obrigaria quem vai finalizar a conferir a outra para saber se o contrato assinou.

- **D-09:** As permissões de módulo da Fase 151 bastam — `comercial.entrada` para os itens de Entrada e para o FINALIZAR, `admin.contratos` para os itens de Contrato. Nenhuma chave nova. As duas já são liberáveis por setor sem deploy (D-15 da Fase 151).

### Claude's Discretion

Decisões tomadas por delegação implícita, ancoradas em medição do código. O usuário viu o resumo das três primeiras e não corrigiu; as demais são consequência direta das decisões acima.

- **D-10:** Copiar o shape do motor de Onboarding, não hospedar nele. A tabela nova é ancorada em `company_id`, não em `contrato_servico_id`. Motivo medido: `Onboarding` exige um `ContratoServico` (`criarParaContrato()`), a tabela tem `unique(contrato_servico_id)`, e `DefinicaoOnboarding::paraServico()` só devolve passos quando `eGestao($servico)` — hospedar o checklist administrativo ali exigiria inventar um serviço fantasma e furar a unique. O que copiar de `OnboardingEngineService` / `onboarding_passos`: `chave` + `natureza` + `dono` + `auto_fonte`; resolver com resultado de 3 estados (`concluido | nao_coletado | indeterminado`, `OnboardingResolverResultado`), nunca booleano; a recusa de `concluirManualmente()` em passo que tem `auto_fonte` (`DomainException` salvo `$forcar`); e `reabrirPasso()` com activity log. ⚠️ Note que `nao_aplicavel` do Onboarding não é copiado — a D-02 o proíbe.

- **D-11:** Autoria: copiar `onboarding_passos.feito_por` / `feito_em` / `auto_em` verbatim. É o padrão dominante do projeto (`mlb_revisoes`, `mlb_publicacoes`, `sugadores`, `companies.empresa_nova_visto_por`). Três disciplinas que vêm junto e não são opcionais: (1) `belongsTo(User::class, 'feito_por')->withTrashed()` — usuário desligado não pode apagar a autoria; (2) desmarcar limpa os dois campos juntos (`RevisaoService:304-305`, `OnboardingEngineService::reabrirPasso`); (3) `spatie/activitylog` é trilha secundária — a leitura da tela vem sempre das colunas, nunca do log.

- **D-12:** Destino do FINALIZAR: `Company::primaryMarketplace()`, que já lê `is_primary` na pivot `company_marketplaces` e cai para a coluna flat `companies.marketplace` quando a pivot está vazia — e essa coluna é `NOT NULL` com `default('meli')`, então "empresa sem marketplace" na prática não existe. ⚠️ `is_primary` é guard de Model, não constraint de banco — nada impede duas linhas `is_primary = true`. O planejador deve decidir o comportamento nesse caso em vez de assumir que não acontece.

- **D-13:** Os itens sem estado observável são manuais, não inventados: `Grupo de WhatsApp criado`, `E-mail colaborador criado`, `Link Adman entregue` e `Boas-vindas enviada`. Quatro dos nove. Fabricar um sinal automático para qualquer um deles seria marcar "concluído" sem evidência — o modo de falha que a D-05 e a D-06 existem para evitar.

- **D-14:** Item 8 (conexão com o sistema ECF) fecha por existência, não por clique. `OnboardingLinkService::paraEmpresa(Company $company)` é `firstOrCreate` por `company_id` (unique), sem rede e sem depender de o onboarding existir — é idempotente, então "o link existe" é sinal suficiente e o botão pode ser acionado quantas vezes for.

### Deferred Ideas (OUT OF SCOPE)

- **Reversibilidade do FINALIZAR** — o que acontece se alguém finalizar por engano, e se um item concluído pode ser desmarcado depois. Levantado como candidato e não discutido nesta sessão. O `reabrirPasso()` do Onboarding é o precedente óbvio se isso voltar à mesa.
- **Cliente desconecta o OAuth depois de finalizado** — o item 7 voltaria a ficar aberto numa empresa que já saiu da etapa administrativa. Não discutido.
- **Integração per-company de Grant com o Mercado Livre** — foi cogitada enquanto se acreditava que o item era sobre a planilha SFTP. A D-05 tornou a questão sem objeto: o item é OAuth, e OAuth já existe per-company. Registrado para que ninguém reabra por engano.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ADMIN-01 | Checklist visual dentro do cadastro, agrupado em Contrato e Entrada — 9/12 itens é decisão explícita da fase (fechada pela D-01: são 9) | Q4/Q10 — schema e molde do motor de Onboarding para hospedar os itens; Architecture Patterns |
| ADMIN-02 | Os itens do grupo Contrato refletem o envelope Clicksign, sem marcação manual paralela (exceto item 1, D-06) e sem reimplementar assinatura (D5) | Q3 — leitura de `ContratoAssinatura`/`ProcessarEventoClicksignJob`; Pitfall 2 (conflito D-03/`ContratoLiberacao`) |
| ADMIN-03 | Link Adman, conexão ECF e Grant da consultoria gerados pelo próprio checklist, que marca o item ao gerar (D3 do REQUIREMENTS — nota: D-04/D-05 da Fase 152 corrigem a leitura original do D3) | Q1 (Grant = OAuth ML), Q4 (`OnboardingLinkService::paraEmpresa`), Q9 (link fixo Adman) |
| ADMIN-04 | Grupo de WhatsApp, e-mail colaborador e boas-vindas marcados manualmente, com autoria | Q4 — molde `feito_por`/`feito_em` de `OnboardingEngineService::concluirManualmente()`/`reabrirPasso()` |
| ADMIN-05 | Botão FINALIZAR só habilita com todos os itens obrigatórios concluídos E contrato assinado | Q3 (o que conta como "assinado" — conflito reportado), Q6 (isenção muda o denominador), Q10 (schema) |
| ADMIN-06 | Finalizar move a empresa para `Aguardando Distribuição` e para o módulo do marketplace do contrato, mesmo cadastro (D4) | Q2 (`primaryMarketplace()`/`is_primary` duplicado), Q7 (`EtapaTransicaoService` — gap das transições intermediárias, ver Open Questions) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Diretivas acionáveis extraídas de `CLAUDE.md` (raiz do worktree `ecf_fluxo_entrada`), com a mesma autoridade de uma decisão travada:

- **Stack:** Laravel 12 + Inertia.js + React — nenhuma mudança de stack. Esta fase não precisa de nenhuma (confirmado na seção Standard Stack abaixo).
- **Comentários em pt-BR**; nomes de classes, métodos, rotas e colunas em inglês — convenção a seguir em todo código novo desta fase.
- **`git commit -- <caminhos>`, nunca `git add -A`/`git add .`** — árvore compartilhada por mais de uma sessão/dev.
- **`npm run build` ao fim de qualquer alteração de frontend** — obrigatório após tocar em `ContratoDetalhe.jsx`/`Entrada.jsx`.
- **Nenhum deploy sem autorização explícita do usuário.**
- **Fluxo de trabalho por risco, não por padrão:** esta fase já está sob GSD completo (`/gsd:plan-phase` → `/gsd:execute-phase`) porque toca `companies.etapa` (state machine com trava de negócio) — não é elegível para "trabalho direto" mesmo que partes dela (ex.: seção de UI) pareçam simples isoladamente.
- **GSD Output Language — pt-BR (REQUIRED):** todo artefato de planejamento desta fase (PLAN.md, RESEARCH.md, VERIFICATION.md, etc.) e as mensagens de commit devem ser em pt-BR — termos técnicos consagrados (queue worker, middleware, endpoint, etc.) podem ficar em inglês.
- **Leitura obrigatória antes de tocar em status de empresa / meta por polo:** `.planning/learnings/painel-polos-status-e-meta.md` — já consultada (§2, falhas pré-existentes; §4, anti-padrão de página re-export). Não há trabalho desta fase que toque em Polos diretamente, mas o padrão de "problema como sinalizador paralelo, nunca status principal" (D6 do REQUIREMENTS-v23) é o mesmo princípio por trás da distinção `pendencia_fluxo` vs `companies.etapa` já em produção desde a Fase 150/138.
- **Leitura obrigatória antes de tocar em desempenho/bônus:** `.planning/learnings/desempenho-bonificacao.md` — §6 (armadilhas de MariaDB) já aplicado ao schema proposto (Pitfall 6); §10 (`git commit -- <caminhos>`) já listado acima.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Checklist administrativo (9/6 itens, estado por empresa) | API/Backend (novo model + service, `company_id`-anchored) | Frontend Server (Inertia props em `ContratoDetalhe.jsx`) | Estado precisa sobreviver a reload e ser auditável — não é estado de UI |
| Auto-marcação (itens 2,3,7,8) | API/Backend (services que leem `ContratoAssinatura`/`MlToken`/`OnboardingLink`) | — | Leitura de estado já existente noutras tabelas — nunca duplicar a fonte |
| Marcação manual + autoria (itens 1,4,5,6,9) | API/Backend (endpoint dedicado, grava `feito_por`/`feito_em`) | Browser (checkbox React) | Autoria exige usuário autenticado da sessão — nunca aceitar `user_id` do corpo |
| Trava do FINALIZAR (ADMIN-05) | API/Backend (service que calcula "tudo obrigatório concluído + assinado") | Browser (botão desabilitado, espelha a mesma régua) | Nunca confiar só no `disabled` do botão — a mesma checagem tem que rodar no servidor no momento do clique (padrão já usado por `EtapaTransicaoService::podeTransicionar()`) |
| Transição de etapa (ADMIN-06) | API/Backend (`EtapaTransicaoService::transicionar()`, único ponto de escrita) | — | D-12 da Fase 150: nenhuma escrita direta de `companies.etapa` fora deste service |
| Link OAuth Mercado Livre (item 7) | API/Backend (`MercadoLivreService::buildAuthUrl()`, já existe) | Browser ("copiar link", nunca "abrir link" pelo usuário ECF) | Ver Q1 — trava anti-autorização-falsa não existe no fluxo `Company`, só no de Polos |
| Link fixo do Adman (item 6) | API/Backend (`config('services.adman.register_url')`) | Browser (texto estático + botão copiar) | Não há geração por empresa — é constante de configuração (D-04) |

## Standard Stack

Esta fase **não introduz nenhuma biblioteca nova**. Todo o trabalho usa o stack já instalado: Laravel 12, Inertia.js 2, React 18, Eloquent, PHPUnit 11. Nenhuma dependência de `composer.json`/`package.json` precisa mudar.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel Eloquent | 12.x (já instalado) | Model novo (`AdministrativoChecklistItem` ou nome equivalente) + migration aditiva | Já é o ORM do projeto inteiro |
| Inertia.js + React | 2.x / 18.x (já instalado) | Seção de checklist dentro de `ContratoDetalhe.jsx` | Já é o padrão de toda tela administrativa |

### Package Legitimacy Audit

**Não aplicável — nenhum pacote novo é instalado nesta fase.** O protocolo de verificação de legitimidade de pacotes (`slopcheck`/registro) foi avaliado e **não roda** porque não há `npm install`/`composer require` previsto em nenhuma das tasks. Se o plano decidir usar algo além do stack existente (não há indício disso nas decisões travadas), a gate precisa ser reexecutada naquele momento.

## Architecture Patterns

### System Architecture Diagram

```
[Browser: ContratoDetalhe.jsx]
        │  abre por admin.contratos.show (permission:admin.contratos)
        │  OU por ação "Abrir" em Comercial/Entrada.jsx (permission:comercial.entrada)
        │  ⚠️ ver Q5 — hoje só a 1ª rota existe e é gated por admin.contratos
        ▼
[ContratoAdminController::show()]  ──> já monta: company, contratos_servico, contratos (ContratoAssinatura[]), faltantes
        │
        ├─> [NOVO] ChecklistAdministrativoService::paraEmpresa(Company)
        │         │
        │         ├─ resolve grupo Contrato: existe SE Servico::exigeContrato() true p/ algum contratosServico ativo (D-07)
        │         │      item 1 "Contrato revisado"  ← manual (D-06)
        │         │      item 2 "Contrato enviado"   ← auto: ContratoAssinatura.enviado_em (D-03)
        │         │      item 3 "Contrato assinado"  ← auto: ContratoAssinatura.assinado_em/status
        │         │                                     ⚠️ CONFLITO — ver Q3 (ContratoLiberacao bypassa isso)
        │         │
        │         └─ grupo Entrada, sempre 6 itens:
        │                item 4 WhatsApp        ← manual
        │                item 5 E-mail colab.   ← manual
        │                item 6 Link Adman      ← manual (link fixo, D-04)
        │                item 7 Grant ML OAuth  ← auto: Company->mlToken->status==='active' (recomendação Q1)
        │                item 8 Conexão ECF     ← auto: OnboardingLink::paraEmpresa() existe (D-14)
        │                item 9 Boas-vindas     ← manual
        │
        └─> [NOVO] FinalizarEntradaAdministrativaService (ou método do controller)
                  │  gate: todos os itens obrigatórios concluídos
                  │  gate: contrato assinado (mesma condição do item 3)
                  ▼
            [EtapaTransicaoService::transicionar($company, ETAPA_AGUARDANDO_DISTRIBUICAO, $request->user())]
                  │  ⚠️ só aceita esta transição vindo de ETAPA_ADMINISTRATIVO_CONCLUIDO (etapa 4) —
                  │     ver Q7, NENHUM chamador hoje avança a empresa até a etapa 4
                  ▼
            companies.etapa = 'aguardando_distribuicao' + linha em company_etapa_transicoes
```

### Recommended Project Structure

```
app/
├── Models/
│   └── ChecklistAdministrativoItem.php          # NOVO — company_id-anchored, ver Q10
├── Services/
│   └── ChecklistAdministrativo/
│       ├── ChecklistAdministrativoDefinicao.php  # os 9 itens em código (molde: DefinicaoOnboarding)
│       ├── ChecklistAdministrativoService.php    # monta o checklist p/ 1 empresa (lazy, ver Q10)
│       └── Resolvers/                            # molde literal: app/Services/Onboarding/Resolvers/
│           ├── ContratoEnviadoResolver.php        # lê ContratoAssinatura.enviado_em
│           ├── ContratoAssinadoResolver.php       # lê ContratoAssinatura + ContratoLiberacao — ver Q3
│           ├── MlOAuthConectadoResolver.php        # copia literal de MlTokenAtivoResolver
│           └── ConexaoEcfResolver.php              # chama OnboardingLinkService::paraEmpresa()
├── Http/Controllers/
│   └── ContratoAdminController.php                # show() ganha 'checklist' no payload; +ação marcar/desmarcar/finalizar
resources/js/Pages/Admin/
└── ContratoDetalhe.jsx                            # ganha seção "Checklist Administrativo" (D-08)
```

### Pattern 1: Resolver de 3 estados (copiar de `App\Contracts\OnboardingResolver`)

**What:** interface com `resolver(): OnboardingResolverResultado` de 3 estados (`concluido | nao_coletado | indeterminado`), nunca `bool`.
**When to use:** para todo item AUTO do checklist (2, 3, 7, 8).
**Example (item 7, cópia quase literal):**
```php
// Source: app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php:52-68
public function resolver(Company $company): ChecklistResolverResultado
{
    $token = $company->mlToken;

    if ($token?->status === 'active') {
        return ChecklistResolverResultado::concluido([
            'ml_user_id'   => $token->ml_user_id,
            'conectado_em' => optional($token->connected_at)->toIso8601String(),
        ]);
    }

    return ChecklistResolverResultado::naoColetado(
        $token !== null ? 'Autorização do cliente foi revogada' : 'Cliente ainda não autorizou o acesso'
    );
}
```
Diferente do motor de Onboarding, os itens 7/8 desta fase são **síncronos** (leitura de coluna local, sem chamada de rede) — o item 7 não precisa da complexidade assíncrona de `AdmanGrantResolver` (que sonda a API Adman); `MlTokenAtivoResolver` é o molde certo, não `AdmanGrantResolver`.

### Pattern 2: Autoria manual (copiar de `OnboardingEngineService::concluirManualmente()`/`reabrirPasso()`)

**What:** `feito_por`/`feito_em` gravados juntos; desmarcar limpa os dois juntos; `belongsTo(User::class, 'feito_por')->withTrashed()`.
**Example:**
```php
// Source: app/Services/Onboarding/OnboardingEngineService.php:587-664
// marcar:
$item->status = 'concluido';
$item->feito_por = $usuario->id;
$item->feito_em = now();
$item->save();
// desmarcar (reabrirPasso):
$item->status = 'aberto';
$item->feito_por = null;
$item->feito_em = null;
$item->save();
```

### Anti-Patterns to Avoid

- **Ler `contrato_assinaturas.status`/`assinado_em` como única fonte de "contrato assinado"**: ignora o caminho de liberação manual (`ContratoLiberacao`) — ver `⚠️ CONFLITO COM O CONTEXT.md` na seção de Pitfalls.
- **Botão "abrir link ML" dentro do checklist**: um clique de usuário ECF logado autoriza a própria conta ML como se fosse a do cliente (ver Pitfall 1). O botão precisa ser "copiar link".
- **Página React que só re-exporta outra** (`export { default } from ...`): não entra no manifest do Vite — não relevante aqui porque D-08 reusa `ContratoDetalhe.jsx` existente, mas relevante se o plano criar QUALQUER página nova.
- **Aceitar `chave` de item livre vinda do body da requisição** para marcar/desmarcar: mirar o padrão de catálogo fechado de `OnboardingResolver`/`Rule::in()` — nunca string livre.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Saber se o cliente conectou o ML | Verificação própria de `ml_link_url`/`ml_link_generated_at` | `Company::mlToken` + `MlTokenAtivoResolver` como molde | Já existe, já testado, já é o padrão do motor de Onboarding para o MESMO problema |
| Conexão com o sistema ECF | Novo token/link | `OnboardingLinkService::paraEmpresa()` (D-14) | `firstOrCreate` idempotente, já em produção |
| Resultado de resolver automático | `bool` solto | `OnboardingResolverResultado` (ou um clone dele para este domínio) | Distingue concluído/não-coletado/indeterminado — já evitou bug de "vazio lido como zero" no Shopee |
| Trava de transição de etapa | `if` disperso em controller | `EtapaTransicaoService::transicionar()` | D-12 da Fase 150: único ponto de escrita, histórico automático em `company_etapa_transicoes` |

**Key insight:** o motor de Onboarding (Fase 135) já resolveu, no mesmo código-base, praticamente todos os problemas de desenho que esta fase enfrenta (resolver de 3 estados, autoria, desmarcar, catálogo fechado). A decisão D-10 do CONTEXT.md ("copiar o shape, não hospedar") está certa — e o resolver mais próximo do item 7 já existe literalmente pronto para cópia.

## Common Pitfalls

### Pitfall 1 — Autorização OAuth falsa por clique interno (item 7)
**What goes wrong:** um usuário ECF logado clica em "iniciar OAuth" da empresa e autoriza com a PRÓPRIA conta Mercado Livre (o ML não força tela de login quando o navegador já tem sessão ativa).
**Why it happens:** o fluxo de `Company` (`MercadoLivreOAuthController::callback()`, linhas 119-171) **sobrescreve incondicionalmente** `ml_store_id`/token — não existe checagem de divergência aqui. **A trava anti-divergência só existe no fluxo de Polos** (`callbackPolos()`, linhas 214-227, guardando `$divergente` e recusando sobrescrever quando `$custAnterior !== '' && $custAnterior !== $custRecebido`). Como Polos usa `MlbEmpresa` (nunca tem `ml_store_id` anterior cadastrado na maioria dos casos), essa trava já recusou autorização LEGÍTIMA por engano (ver `project_polos_oauth_link_boas_vindas_260827` nos learnings do projeto) — não é uma trava que se possa copiar sem ajuste.
**How to avoid:** o botão do item 7 deve ser "copiar link" (via `POST /companies/{company}/ml/initiate`, que já devolve a URL em JSON — `MercadoLivreOAuthController::initiate()`, linhas 52-57), nunca um link `<a href>` que abre direto. Isso não elimina o risco de um usuário colar o link no PRÓPRIO navegador por engano, mas remove o convite de um clique.
**Warning signs:** se o log `[MercadoLivre] ml_store_id corrigido empresa {id}` (linha 138) aparecer para uma empresa recém-onboardada, é o sinal de que uma conta diferente da esperada assinou o OAuth.

### Pitfall 2 — ✅ RESOLVIDO pela D-16 (era: ⚠️ CONFLITO COM O CONTEXT.md): item 3 "Contrato assinado" pode nunca fechar para empresas liberadas manualmente
**Decisão impactada:** D-03 (tabela dos 9 itens), coluna "Fecha por" do item 3: `contrato_assinaturas.assinado_em` / `status === assinado`.
**O que o código mostra:** existem **3 vias** de liberação de uma empresa para o operacional (`ContratoLiberacao::VIA_TODAS` — `webhook`, `manual`, `reconciliacao`, `app/Models/ContratoLiberacao.php:61-70`). Na via `webhook` (`ProcessarEventoClicksignJob.php:227-239`) e na via `reconciliacao` (`ReconciliarContratoClicksignJob.php:108-115`), `status=assinado` e `assinado_em` **são sempre gravados no mesmo `save()`** que cria a `ContratoLiberacao` — nesses dois casos ler as colunas do D-03 é equivalente a ler `ContratoLiberacao`.
Mas na via `manual` (`ContratoAdminController::liberarManual()`, linhas 1201-1243 → `EmpresaOperacionalRouter::liberarEmpresa()`, linhas 284-361), **o `status`/`assinado_em` do `ContratoAssinatura` NUNCA são tocados** — só `liberado_em` é gravado (linha 348, e só se um `$contrato` foi passado; `contrato_assinatura_id` é `nullable` na validação, linha 1206, então a liberação manual pode existir SEM nenhum `ContratoAssinatura`). Esta via existe exatamente para o cenário "Clicksign fora do ar, contrato recusado, envelope apagado, cliente assinou fora do sistema" (docblock de `liberarManual()`, linha 1195-1196).
**Consequência para o plano:** se o item 3 ler só `contrato_assinaturas.assinado_em`/`status`, toda empresa liberada pela via manual fica **permanentemente travada** no ADMIN-05 (FINALIZAR nunca habilita), mesmo já estando oficialmente liberada para o operacional segundo o próprio sistema de contratos (v22.0). **Este relatório não resolve isso** — o planejador precisa decidir se o item 3 também deve ler `ContratoLiberacao::existeParaServico($company->id, $servico->id)` como sinal alternativo de fechamento, ou se aceita o risco descrito.
**NÃO MEDIDO:** o banco local (`ecf_admin`, MariaDB via XAMPP) tem **0 linhas** em `contrato_assinaturas` e **0 linhas** em `contrato_liberacoes` no momento desta pesquisa — não há como medir a frequência real desse cenário; a análise acima é 100% leitura de código, não de dado.

### Pitfall 3 — Ambiguidade do "envelope vigente" quando a empresa tem 2+ serviços que exigem contrato
**✅ Fechado pela D-18** (usuário, 2026-09-09): manda o mais atrasado — itens 2 e 3 só fecham quando TODOS os envelopes ativos atingiram o estado.
**O que o código mostra:** `ContratoClicksignService::iniciarParaEmpresa()` itera `foreach ($porGrupo as $servicoId => $membrosDoGrupo)` (linhas 138-223) e pode criar **um `ContratoAssinatura` por grupo de serviço** — ou seja, uma empresa com 2 serviços não-combinados que exigem contrato pode ter 2 envelopes simultâneos, cada um em um estado diferente. `ContratoAdminController::show()` já expõe isso como lista achatada (`orderByDesc('id')`, linhas 567-570) sem escolher "o" envelope — a tela atual mostra todos.
**Medido:** no banco local, a empresa `id=64` ("Teste Dev 02") tem 3 `contratos_servico` ativos — `servico_id=2` (Polos, isento) + `servico_id=1` e `servico_id=5` (ambos exigem contrato). É uma empresa de teste, sem nenhum `ContratoAssinatura` gerado ainda, então **não dá para observar o comportamento real do checklist neste caso** — só confirmar que o schema permite a situação (1 empresa entre ~190 no banco local tem 2+ serviços não-Polos simultâneos).
**Consequência para o plano:** o CONTEXT.md (D-03) descreve o grupo Contrato como 3 itens únicos por empresa, sem prever múltiplos envelopes concorrentes. O planejador precisa decidir: (a) o checklist agrega por "pior estado entre os envelopes ativos" (ex.: se um está assinado e outro não, item 3 fica pendente), ou (b) o checklist assume 1 serviço-com-contrato por empresa como caso normal e trata o resto como fora de escopo. Nenhuma das duas está decidida.

### Pitfall 4 — Rota da ficha (`admin.contratos.show`) não é acessível por quem só tem `comercial.entrada`
**✅ Fechado pela D-17** (usuário, 2026-09-09): mesma rota, `permission:admin.contratos,comercial.entrada` (o middleware `EnsurePermission` já aceita OR nativamente). A seção Contrato dentro da ficha continua gated por `admin.contratos` — sem isso a mudança de rota vira vazamento de dado contratual.
**Decisão impactada:** D-08 ("A listagem Entrada ganha uma ação 'Abrir' apontando para a MESMA ficha") + D-09 ("nenhuma chave nova").
**O que o código mostra:** `admin.contratos.show` (`/administrativo/contratos/empresa/{company}`) está dentro de `Route::middleware(['auth', 'verified', 'permission:admin.contratos'])` (`routes/web.php:1443`). Não há checagem alternativa dentro de `ContratoAdminController::show()` — só o middleware da rota decide. Um usuário com `comercial.entrada` mas **sem** `admin.contratos` (perfil plausível: alguém do time Comercial que só cuida de Entrada) receberia **403** ao clicar em "Abrir" a partir de `Comercial/Entrada.jsx`.
**Consequência para o plano:** D-09 precisa de um ajuste — ou o middleware da rota passa a aceitar `admin.contratos` OU `comercial.entrada` (checagem dupla), ou nasce uma rota irmã sob o grupo `comercial.entrada.*` apontando para o MESMO controller/método. Qualquer uma das duas ainda respeita "nenhuma chave nova" (nenhuma permission nova é criada), mas o desenho da rota precisa mudar — isso não está nas 14 decisões travadas.

### Pitfall 5 — Nenhum chamador transiciona `companies.etapa` para 2, 3 ou 4 hoje
**✅ Fechado pela D-15** (usuário, 2026-09-09): o progresso do checklist dirige as etapas 2/3/4; o FINALIZAR faz 4→5. Toda transição via `EtapaTransicaoService::transicionar()`.
Ver a seção "Architecture Patterns" acima e o achado #3 do Summary — detalhado com toda a evidência abaixo, em "Open Questions".

### Pitfall 6 — Armadilhas de MariaDB que o SQLite dos testes não pega (aplicar ao schema novo)
De `.planning/learnings/desempenho-bonificacao.md` §6, aplicado à tabela nova desta fase:
- **Nome de índice > 64 caracteres** falha com erro 1059 no MariaDB (não no SQLite). Um índice único óbvio como `unique(['company_id', 'chave'])` numa tabela chamada `checklist_administrativo_itens` gera um nome autogerado longo (`checklist_administrativo_itens_company_id_chave_unique` = 54 caracteres — dentro do limite, mas qualquer nome de tabela mais longo ou chave composta adicional estoura). **Nomear o índice explicitamente** (`$table->unique(['company_id', 'chave'], 'cai_company_chave_unique')`) elimina o risco por completo, independente do nome da tabela.
- **`nullOnDelete()` exige coluna `nullable()`** (erro 1830) — aplicar em `feito_por` (mesma coluna que `onboarding_passos.feito_por`, já `nullable()->constrained('users')->nullOnDelete()` na migration 2026_08_11_120100).
- **Dropar índice usado por FK falha** (erro 1553) — não relevante aqui porque é `CREATE TABLE`, não `ALTER`, mas relevante se um plano futuro alterar esta tabela.

## Code Examples

### Como ler se o cliente conectou o ML (item 7) — molde exato a copiar
```php
// Source: app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php:52-68
$token = $company->mlToken; // relação já existe: app/Models/Company.php:597

if ($token?->status === 'active') {
    // concluído — cliente conectou
}
// $token === null (nunca conectou) OU $token->status !== 'active' (revogado/expirado)
// => item aberto
```

### Como ler se a conexão ECF existe (item 8) — molde exato a copiar
```php
// Source: app/Services/Onboarding/OnboardingLinkService.php:42-48
$link = OnboardingLinkService::paraEmpresa($company); // firstOrCreate, idempotente, sem rede
// existência da linha ($link->wasRecentlyCreated === false OU true, tanto faz) já fecha o item
```

### Como ler o estado do envelope Clicksign (itens 2 e 3) — sem escrever nada (D5)
```php
// enviado_em e assinado_em SÓ são gravados em:
//   app/Jobs/ProcessarEventoClicksignJob.php:193-199 (enviado_em) e :227-239 (assinado_em)
//   app/Jobs/ReconciliarContratoClicksignJob.php:108-115 (assinado_em, via reconciliação)
// Nunca gravar aqui — só ler:
$contrato = ContratoAssinatura::where('company_id', $company->id)
    ->where('servico_id', $servicoQueExigeContrato->id)
    ->latest('id')
    ->first(); // ⚠️ Pitfall 3 — pode não ser "o" envelope se houver múltiplos
```

## State of the Art

Não aplicável no sentido tradicional (não é uma biblioteca com versões). O "estado da arte" relevante aqui é interno ao projeto:

| Padrão antigo (não usar) | Padrão atual (usar) | Onde mudou | Impacto |
|---------------------------|----------------------|------------|---------|
| Ler `ml_link_url`/`ml_link_generated_at` como sinal de conexão | Ler `Company::mlToken` (`ml_tokens.status`) | Já existia desde a migration `2026_05_28_100001` — o zeramento do link é efeito colateral do callback, não um design novo | O sinal correto já existe e é mais estável (sobrevive a expiração/refresh do token, distingue "nunca conectou" de "revogado") |
| Confiar em `nao_aplicavel` para isenção | Montagem condicional do grupo (item nem existe) | D-02/D-07 desta fase | Muda o desenho da tabela: sem coluna de estado "não aplicável" |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | O item 7 deve fechar por `status === 'active'` (não apenas "linha existe") — segue o precedente de `MlTokenAtivoResolver` | Pattern 1 / Q1 | Se a decisão correta for "linha existe, qualquer status", uma revogação de token reabriria o item 7 sem necessidade — impacto baixo, reversível em código |
| A2 | O checklist deve ser montado **lazy** (item ausente = pendente), não eager (linhas pré-criadas) | Q10 | Se eager for a escolha certa, a tabela precisa de um comando de backfill e um gatilho de criação — mudança de escopo, não de dado |

**Nenhuma claim de nome de pacote foi feita** — esta fase não instala pacotes novos.

## Open Questions (RESOLVED — 2026-09-09)

> As três perguntas abaixo foram levadas ao usuário no `/gsd:plan-phase` e **fechadas por ele**.
> As decisões correspondentes estão travadas em `152-CONTEXT.md` (D-15, D-16, D-18) e o Pitfall 4
> foi fechado pela D-17. Nenhuma delas segue em aberto.

1. **Quem transiciona `companies.etapa` para 2, 3 e 4, e quando?**
   - O que sabemos: `EtapaTransicaoService::TRANSICOES_PERMITIDAS` (`app/Services/FluxoEntrada/EtapaTransicaoService.php:58-75`) só permite chegar em `aguardando_distribuicao` (etapa 5) **a partir de** `administrativo_concluido` (etapa 4). Os dois únicos chamadores de produção hoje (`HubspotWebhookController.php:500` e `ComercialController.php:683`) só escrevem etapa 1. `ComercialEntradaController::index()` (`app/Http/Controllers/ComercialEntradaController.php:61-72`) já filtra empresas nas etapas 1 a 4 — o que implica que popular 2/3/4 é esperado pelo sistema, mas nenhuma decisão do CONTEXT.md nem do ROADMAP diz QUEM dispara essas transições.
   - O que está incerto: se cada item do checklist deve disparar sua própria transição (ex.: "Contrato enviado" fecha → etapa vai para 3; "todos os itens + contrato assinado" → etapa vai para 4) — o que dá granularidade real para a Fase 156 medir SLA por etapa —, ou se o botão FINALIZAR deve encadear múltiplas chamadas a `transicionar()` num único clique, partindo de onde a empresa estiver.
   - ✅ **RESOLVIDA pela D-15** (usuário, 2026-09-09): o progresso do checklist dirige as etapas 2/3/4 — 1º item concluído → 2; envelope enviado → 3; tudo pronto → 4; FINALIZAR → 5, com salto 2→4 para empresa isenta. É a opção recomendada abaixo, escolhida com o custo de escopo aceito explicitamente.
   - Recommendation: recomendo a primeira opção (transições disparadas pelo progresso do checklist, não só pelo clique final) porque é a única que preserva o propósito documentado da máquina de estados (`EtapaTransicaoService.php:28-32`: "a etapa NUNCA é derivada... a Fase 156 (HIST-03) não teria o instante real da transição"). Mas isso expande o escopo desta fase além do que ADMIN-01..06 descreve literalmente — **o planejador/usuário precisa confirmar isso explicitamente antes de escrever tasks**, porque é decisão de produto, não só técnica.

2. **Item 3 deve ler `ContratoLiberacao` além de (ou em vez de) `contrato_assinaturas.status`/`assinado_em`?**
   - ✅ **RESOLVIDA pela D-16** (usuário, 2026-09-09): o item 3 fecha por `assinado_em`/`status === assinado` **OU** por `ContratoLiberacao` existente para aquele serviço — cobre a via `manual`, que não escreve `contrato_assinaturas`. Continua leitura pura (D5 preservada).
   - Ver Pitfall 2 acima (registro original, mantido para histórico).

3. **Empresa com 2+ serviços que exigem contrato simultaneamente: o checklist agrega como?**
   - ✅ **RESOLVIDA pela D-18** (usuário, 2026-09-09): manda o mais atrasado — os itens 2 e 3 só fecham quando TODOS os envelopes ativos atingiram aquele estado. Mesmo precedente que o `EtapaTransicaoService` já declara para o onboarding da Fase 155.
   - Ver Pitfall 3 (registro original, mantido para histórico).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | Toda a fase | ✓ | 8.2+ (`C:\xampp\php\php.exe`) | — |
| Composer | Nenhum pacote novo previsto | ✓ | `C:\xampp\php\composer.bat` | — |
| MariaDB local | Medições desta pesquisa, testes locais | ✓ | via XAMPP, `DB_CONNECTION=mysql`, banco `ecf_admin`, 190 empresas | — |
| Node/npm | `npm run build` após mudança de frontend | ✓ | Node v24.15.0 (medido em fases anteriores) | — |
| API Mercado Livre / Clicksign / Adman | Itens 2,3,7 do checklist (leitura de estado já sincronizado) | Não medido nesta pesquisa — não são chamadas NOVAS, reusam client já configurado | — | — |

Nenhuma dependência externa nova é introduzida por esta fase — não há gate bloqueante de ambiente.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) — já instalado |
| Config file | `phpunit.xml` (banco de teste = SQLite `:memory:`) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase152 tests/Feature/Phase152 --colors=never` |
| Full suite command (por wave) | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase152 tests/Feature/Phase152 tests/Feature/Phase151 tests/Feature/Phase131/ContratoAdminPermissaoTest.php tests/Unit/Phase150 tests/Feature/Phase150 --colors=never` |

> ⚠️ **`php artisan test` e `--testsuite=Feature` NÃO terminam neste ambiente** (travam em cascata de timeout de rede ~300s, herdado de `150-BASELINE-TESTES.md` e reconfirmado em `151-VALIDATION.md`). Rodar sempre por diretório/arquivo, nunca a suíte inteira.
>
> ⚠️ **~10 falhas pré-existentes** em `tests/Feature/Phase38/PolosControllerTest.php` e `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` — documentadas em `.planning/learnings/painel-polos-status-e-meta.md` §2, não são regressão desta fase.

### Baseline exigida antes de tocar em `EtapaTransicaoService`
Esta fase adiciona **novos chamadores** de `EtapaTransicaoService::transicionar()` (etapas 2/3/4/5) — comportamento novo sobre uma máquina de estados que já tem baseline própria. Capturar ANTES de qualquer código novo, em `152-BASELINE-TESTES.md`:
```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase150 tests/Feature/Phase150 tests/Feature/Phase151 --colors=never
```

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ADMIN-01 | Checklist aparece agrupado em Contrato/Entrada, com 9 itens (contrato) ou 6 (isento) | feature | `phpunit tests/Feature/Phase152/ChecklistContagemItensTest.php` | ❌ Wave 0 |
| ADMIN-02 | Itens 2/3 mudam sozinhos conforme `ContratoAssinatura` avança; item 1 é manual (exceção documentada, D-06) | feature | `phpunit tests/Feature/Phase152/ChecklistGrupoContratoAutoTest.php` | ❌ Wave 0 |
| ADMIN-03 | Itens 6 (link fixo)/7 (OAuth)/8 (conexão ECF) — 7 e 8 auto-marcam; 6 é manual (D-04) | feature | `phpunit tests/Feature/Phase152/ChecklistGrupoEntradaAutoTest.php` | ❌ Wave 0 |
| ADMIN-04 | Marcar manualmente grava `feito_por`/`feito_em`; desmarcar limpa os dois | feature | `phpunit tests/Feature/Phase152/ChecklistMarcacaoManualAutoriaTest.php` | ❌ Wave 0 |
| ADMIN-05 | FINALIZAR desabilitado com item pendente OU contrato não assinado; habilita no instante exato | feature | `phpunit tests/Feature/Phase152/FinalizarTravaTest.php` | ❌ Wave 0 |
| ADMIN-06 | FINALIZAR move companies.etapa → aguardando_distribuicao, mesmo company_id, `primaryMarketplace()` resolve destino | feature | `phpunit tests/Feature/Phase152/FinalizarTransicaoEtapaTest.php` | ❌ Wave 0 |
| D-08 (regressão) | Comercial/Entrada consegue abrir a mesma ficha sem 403 | feature | `phpunit tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` — **depende da decisão do Pitfall 4** | ❌ Wave 0 |
| Q2 (defesa) | 2 linhas `is_primary=true` não quebra `FinalizarTransicaoEtapaTest` (comportamento determinístico, ainda que arbitrário) | unit | incluído em `FinalizarTransicaoEtapaTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** comando rápido (`tests/Unit/Phase152 tests/Feature/Phase152`)
- **Per wave merge:** comando de suíte completo (inclui regressão 137/138/131)
- **Phase gate:** baseline específica verde + suíte por wave verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase152/ChecklistContagemItensTest.php` — ADMIN-01
- [ ] `tests/Feature/Phase152/ChecklistGrupoContratoAutoTest.php` — ADMIN-02
- [ ] `tests/Feature/Phase152/ChecklistGrupoEntradaAutoTest.php` — ADMIN-03
- [ ] `tests/Feature/Phase152/ChecklistMarcacaoManualAutoriaTest.php` — ADMIN-04
- [ ] `tests/Feature/Phase152/FinalizarTravaTest.php` — ADMIN-05
- [ ] `tests/Feature/Phase152/FinalizarTransicaoEtapaTest.php` — ADMIN-06
- [ ] `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` — Pitfall 4 (depende de decisão do planejador)
- [ ] Framework: nada a instalar

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não | não há novo fluxo de autenticação |
| V3 Session Management | não | reusa sessão Inertia existente |
| V4 Access Control | sim | `permission:admin.contratos`/`permission:comercial.entrada` (padrão já em produção, `app/Support/Permissions.php:75,99`) — ver Pitfall 4 para o gap concreto a fechar |
| V5 Input Validation | sim | `Rule::in()` sobre catálogo fechado de `chave` de item (mesmo padrão de `ContratoLiberacao::MOTIVOS_MANUAIS`, `app/Http/Controllers/ContratoAdminController.php:1208`) |
| V6 Cryptography | não | nenhum dado novo de criptografia — tokens ML já são `encrypted` cast em `MlToken` |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR: marcar item de checklist de OUTRA empresa via ID solto no body | Tampering | Validar que qualquer ID de item pertence ao `company_id` da rota — mesmo padrão de `ContratoAdminController::liberarManual()` linhas 1220-1225 (`abort(422, ...)` quando não bate) |
| Mass assignment em model novo | Tampering | `$fillable` explícito, nunca `$guarded = []` — convenção já documentada em todos os models de contrato (`ContratoAssinatura.php:53`) |
| `user_id` vindo do corpo da requisição para autoria/transição | Spoofing | Sempre `$request->user()` — nunca aceitar `user_id`/`feito_por` do payload (mesmo padrão de `ComercialController::store()` linha 683-690, que passa `$request->user()`, nunca um id do corpo) |
| Autorização ML falsa por clique interno | Spoofing | Ver Pitfall 1 — "copiar link", nunca "abrir link" |
| CSRF em rota de marcar/desmarcar/finalizar | Tampering | Rotas ficam dentro do grupo autenticado padrão (CSRF ativo) — nunca replicar a exceção de `/implementacao/*` |

## Sources

### Primary (HIGH confidence — leitura direta do código, com arquivo:linha)
- `app/Http/Controllers/MercadoLivreOAuthController.php` (377 linhas, lido por completo)
- `app/Services/MercadoLivreService.php` (linhas 1-300 lidas)
- `app/Models/MlToken.php` (lido por completo)
- `database/migrations/2026_05_28_100001_create_ml_tokens_table.php` (lido por completo)
- `app/Models/Company.php` (linhas 270-310, 740-847 lidas)
- `database/migrations/2026_07_03_190000_create_company_marketplaces_table.php` (lido por completo)
- `database/migrations/2026_06_02_190000_add_marketplace_to_companies.php` (lido por completo)
- `app/Http/Controllers/ShopeeOAuthController.php` (linhas 145-175 lidas)
- `app/Models/ContratoAssinatura.php` (lido por completo, 410 linhas)
- `app/Http/Controllers/ContratoAdminController.php` (linhas 507-693, 1195-1244 lidas)
- `app/Jobs/ProcessarEventoClicksignJob.php` (linhas 160-260 lidas)
- `app/Jobs/ReconciliarContratoClicksignJob.php` (linhas 90-130 lidas)
- `app/Services/Operacional/EmpresaOperacionalRouter.php` (linhas 284-361 lidas)
- `app/Models/ContratoLiberacao.php` (lido por completo)
- `app/Services/Contratos/GatilhoContratoAdministrativoService.php` (lido por completo)
- `app/Services/Clicksign/ContratoClicksignService.php` (grep de estrutura, linhas 51-437)
- `app/Models/Servico.php` (linhas 293-297 lidas)
- `app/Services/Onboarding/OnboardingEngineService.php` (lido por completo, 715 linhas)
- `app/Services/Onboarding/OnboardingResolverResultado.php` (lido por completo)
- `app/Contracts/OnboardingResolver.php` (lido por completo)
- `app/Services/Onboarding/Resolvers/AdmanGrantResolver.php` (lido por completo)
- `app/Services/Onboarding/Resolvers/MlTokenAtivoResolver.php` (lido por completo)
- `app/Services/Onboarding/OnboardingSituacaoService.php` (linhas 220-300 lidas)
- `app/Services/Onboarding/OnboardingLinkService.php` (lido por completo)
- `database/migrations/2026_08_11_120100_create_onboardings_tables.php` (lido por completo)
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` (cabeçalho + grep estrutural)
- `resources/js/Pages/Comercial/Entrada.jsx` (linhas 1-100 lidas)
- `app/Http/Controllers/ComercialEntradaController.php` (linhas 1-80 lidas)
- `routes/web.php` (linhas 860-885, 1055-1075, 1430-1477 lidas)
- `app/Services/FluxoEntrada/EtapaTransicaoService.php` (lido por completo, 333 linhas)
- `app/Support/Permissions.php` (grep de chaves)
- `config/services.php` (linhas 40-46 lidas + grep)
- `.env.example` (linhas 100-128 lidas)
- `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php` (lido por completo)
- Medições diretas via `php artisan tinker` contra `ecf_admin` (MariaDB local, XAMPP): contagem de `is_primary` duplicado (0/190 empresas), contagem de serviços `exige_contrato=0` (Polos, id=2, 5 vínculos ativos), contagem de `contrato_assinaturas`/`contrato_liberacoes` (0 linhas — não medido em dado real)

### Secondary (MEDIUM confidence)
- `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/151-VERIFICATION.md` — citações de linha reusadas onde a Fase 151 já havia confirmado (ex.: ausência de corte por etapa em `ContratoAdminController`)
- `.planning/learnings/painel-polos-status-e-meta.md` §2, §4 — falhas pré-existentes e o anti-padrão de página re-export

### Tertiary (LOW confidence)
- Nenhuma fonte externa (WebSearch/Context7) foi usada — toda a pesquisa desta fase é interna ao código do projeto, conforme D0 do `REQUIREMENTS-v23.md` ("Sem pesquisa de domínio nesta abertura")

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhum pacote novo, stack 100% já instalado e verificado
- Architecture: MEDIUM — os padrões a copiar são HIGH confidence (lidos linha a linha), mas a questão da transição de etapa (Pitfall 5/Open Question 1) não tem resposta em código nenhum
- Pitfalls: HIGH — todos os 6 pitfalls são citação direta de código, não suposição

**Research date:** 2026-09-09
**Valid until:** 30 dias (código interno estável; revisitar se qualquer uma das Fases 150/138 sofrer hotfix antes do planejamento desta fase)
