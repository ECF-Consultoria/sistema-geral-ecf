# Phase 138: Área Comercial conectada à etapa + reorganização em Contrato e Entrada (v23.0) - Research

**Researched:** 2026-09-02
**Domain:** Reorganização de navegação Inertia/React sobre código já existente + 1 integração nova (HubSpot Owners) + ligação do primeiro chamador de produção do `EtapaTransicaoService` (Fase 137)
**Confidence:** HIGH

## Summary

Esta pesquisa não decide **o quê** fazer — o `138-CONTEXT.md` já travou D-01 a D-16 e o pré-requisito
bloqueante (reescrever `ADMIN-01`/`COMERC-02`/`ROADMAP.md`) **já foi fechado** (`STATE.md` linha 6:
"Pre-requisito bloqueante da Phase 138 FECHADO"; `ROADMAP.md` já mostra o bloco reescrito com 5
Success Criteria). O que faltava — e que este documento fecha com leitura direta do código deste
worktree — é **como** implementar as oito lacunas medidas que o CONTEXT deixou em aberto.

Três achados mudam o que o planner precisa saber:

**(a) Não existe convenção de "ator de sistema" no projeto.** `EtapaTransicaoService::transicionar()`
exige um `User $por` não-nulo (tipo estrito, não `?User`), e os dois pontos de nascimento da etapa 1
— `HubspotWebhookController::criarEmpresa()` (chamado por `receive()` e por `reprocessarEvento()`) e
`ComercialController::store()` — têm perfis de autenticação **diferentes**: `store()` roda sob sessão
autenticada (`$request->user()` funciona de graça); o webhook e o comando de reprocessamento **não
têm usuário logado nenhum**. Não existe hoje nenhum "usuário sistema" nem padrão equivalente no
projeto (busquei por `system_user`, seeders, qualquer convenção — o único precedente próximo é
`config('digisac.default_user_id')`, um ID de usuário do **Digisac**, não um `App\Models\User`). Esta
pesquisa recomenda um padrão config-driven análogo (`HUBSPOT_WEBHOOK_USER_ID` → `User::find()`) e
documenta a alternativa (tornar `$por` nullable no serviço), mas sinaliza que **isto é uma decisão de
arquitetura nova**, não uma leitura de algo que já existe — ver `Common Pitfalls` e `Open Questions`.

**(b) O caminho mais seguro para "mover" o módulo Contrato para dentro do Comercial é não mover o
arquivo físico nenhum.** O item de menu (`AppLayout.jsx` `NAV_TREE`) é um array plano
`{group, children:[{label, routeName, page, permission}]}`; a rota nomeada `admin.contratos.index`, o
controller `ContratoAdminController` e a página `resources/js/Pages/Admin/Contratos.jsx` **não
precisam mudar de lugar** para a tela aparecer sob "Comercial" — só o item do `NAV_TREE` muda de
grupo. Isso satisfaz D-15 (permissão preservada, `ContratoAdminPermissaoTest` continua verde, porque
ele testa por **nome de rota**, não por prefixo de URL nem por grupo de menu) e evita por construção
a armadilha da página React de re-export puro (`.planning/learnings/painel-polos-status-e-meta.md`
§4) — armadilha que só existe quando alguém tenta mover/duplicar o arquivo `.jsx`.

**(c) O critério "honesto" para a lista Entrada, sem checklist ainda, já está implícito em D-14.**
D-14 trava: "As listas Contrato/Entrada nascem só com quem entrar pela porta nova" — ou seja, empresa
com `etapa` `NULL` (legado) não entra. Combinando D-07 (corte de saída = entrar na etapa 5) com D-14,
o critério de pertencimento da lista Entrada nesta fase é `companies.etapa` **dentro do conjunto**
{1,2,3,4} (`ETAPA_AGUARDANDO_ADMINISTRATIVO` .. `ETAPA_ADMINISTRATIVO_CONCLUIDO`). Isto **não** viola
D-05 (proibição de separar Contrato de Entrada por etapa): D-05 proíbe usar etapa para **diferenciar**
as duas listas entre si — a lista Contrato mantém seu próprio critério, não-relacionado a etapa
(estado do envelope Clicksign, D-06, já existe e não muda). Etapa aqui só define o limite EXTERNO de
"quem está em fluxo de entrada", que é exatamente o que COMERC-03 pede — as duas coisas coincidem
porque o próprio domínio pede o mesmo corte para os dois fins.

**Recomendação principal:** trate esta fase como três frentes paralelas de baixo acoplamento — (1)
reorganização pura de navegação (não toca em nenhum arquivo PHP/JSX existente de Contrato/Empresas,
só o `NAV_TREE`), (2) integração HubSpot Owner (1 método novo em `HubspotApiClient`, 3 colunas
aditivas em `companies`, resolução de "ator de sistema" nova) e (3) ligar o `EtapaTransicaoService`
nos dois pontos de nascimento (código já existe e já foi endurecido para concorrência de propósito —
ver achado (a) e a nota do `STATE.md` linha 101-104 sobre o gap closure G4/WR-01 antecipando
exatamente este chamador). As três frentes têm superfícies de risco diferentes e podem virar planos
separados.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Nascimento da empresa na etapa 1 (webhook + cadastro manual) | API / Backend | Database / Storage | Chama `EtapaTransicaoService::transicionar()` — já existe, escreve `companies.etapa` + `company_etapa_transicoes` numa transação |
| Resolução do responsável comercial (HubSpot Owner) | API / Backend | — | `GET /crm/v3/owners/{id}` via `HubspotApiClient` novo método; nunca no frontend (token nunca sai do backend) |
| Cache do nome do responsável comercial | API / Backend | Database / Storage (cache table, driver `database`) | "Poucos vendedores" (D-08) — TTL longo, `Cache::remember`, mesmo padrão de `MercadoLivreService::695` (24h) |
| Persistência de `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` | Database / Storage | — | 3 colunas aditivas nullable em `companies` (D-09) |
| Listagem Contrato (8 campos + etapa) | API / Backend | Frontend Server (SSR via Inertia) | `ContratoAdminController::index()` já existe — só ganha colunas no payload, query NÃO muda (D-06) |
| Listagem Entrada (casca) | API / Backend | Frontend Server (SSR via Inertia) | Controller novo, query nova por `companies.etapa IN [1..4]` (achado (c) acima) |
| Reorganização de navegação (menu) | Browser / Client | — | `NAV_TREE` em `AppLayout.jsx` — array estático, sem chamada de rede |
| Permission nova do módulo Entrada | API / Backend | Browser / Client | Catálogo em `app/Support/Permissions.php` (backend, fonte da verdade) + gating visual em `AppLayout.jsx` (`permission:` no item de menu) |
| Remoção de `admin.empresas` do menu | Browser / Client | — | Só o item do `NAV_TREE` some; rota/controller/página sobrevivem por URL direta (D-16) |

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Escopo e sequência da reorganização:**
- **D-01:** A Fase 138 entrega **a casca**: reorganização da navegação + as duas listas com os 8
  campos do §2 + o nascimento na etapa 1. Os checklists de Contrato e Entrada continuam sendo a Fase
  139 (ADMIN-01..06).
- **D-02:** O módulo que funde os grupos Estrutura + Comunicação do §3 chama-se **`Entrada`**. Carrega
  os 8 itens: criar grupo de WhatsApp · criar/definir e-mail colaborador · gerar link ADMA · gerar
  Grant da consultoria · gerar link/conexão ECF · gerar mensagem de boas-vindas · inserir todos os
  links · enviar a mensagem no grupo.
- **D-03:** O módulo **Contrato** mantém os 4 itens do §3 sem mudança: revisar o contrato · enviar ao
  cliente · acompanhar a assinatura · confirmar contrato assinado.

**Onde a listagem vive e o que ela mostra:**
- **D-04:** Não existe aba "Empresas Ganhas" separada. Contrato e Entrada **são** as listagens.
- **D-05:** A separação é **por processo pendente, não por etapa**. A mesma empresa pode aparecer nas
  duas listas ao mesmo tempo. **Proibido** separar as duas listas por `companies.etapa`.
- **D-06:** Contrato já é real no dia 1 — é a tela `admin.contratos` da Fase 131 movida para dentro do
  Comercial; só ganha os 8 campos do §2 e a etapa. Entrada nasce como casca (lista com colunas do §2,
  checklist chega na Fase 139).
- **D-07:** Corte de saída = entrar na etapa 5 (`aguardando_distribuicao`). Empresa continua visível
  enquanto está em `administrativo_concluido` (4). Casa com ADMIN-06.

**Os 8 campos mínimos do §2:**
- **D-08:** Responsável comercial vem do HubSpot. Acrescentar `hubspot_owner_id` às props do deal em
  `config/services.php` e resolver o nome por `GET /crm/v3/owners/{id}`, com cache. Medido em
  2026-09-02: a string `owner` não aparece em `config/services.php`, `HubspotApiClient` nem
  `HubspotWebhookController` — o campo do §2 nunca teve fonte. Cabe na D5 do REQUIREMENTS porque
  acrescentar uma property ao fetch é integração, não reconstrução.
- **D-09:** Onde grava (discricionário, delegado ao Claude): colunas aditivas nullable em `companies`
  — `hubspot_owner_id`, `hubspot_owner_nome`, `data_venda`. Extrair JSON dentro de `WHERE`/`ORDER` no
  MariaDB é armadilha; a Fase 143 vai querer a data da venda como evento datado, não campo derivado de
  JSON. `closedate` já é buscado hoje, mas só cai dentro de `hubspot_snapshot.deal`. Segue o padrão
  das colunas `hubspot_*` que as Fases 111/113 já criaram.
- **D-10:** Owner retroativo: sim, por comando manual. `hubspot:reenriquecer-handoff` /
  `ReprocessHubspotEvent` já refazem o fetch do deal — com `hubspot_owner_id` nas props, o owner entra
  de graça no próximo reprocessamento. Rodar com dry-run e conferir a contagem por reconsulta ao
  banco, nunca por stdout.
- **D-11:** Duas pendências, em colunas separadas e nomeadas — nunca somadas. Pendência do fluxo =
  Fase 137 (declarada à mão). Pendências do cadastro = as comerciais derivadas de
  `PendenciasComerciaisService` que a tela já mostra em cards.
- **D-12:** "Setor ou segmento" = setor ECF (`servicos.setor`: performance/publicação/outros). O
  `industry` do HubSpot fica onde já está (dentro de `hubspot_snapshot.company`).

**Portas de entrada na etapa 1:**
- **D-13:** As duas portas nascem na etapa 1. O webhook **e** `ComercialController::store` chamam o
  mesmo `EtapaTransicaoService`. A tabela `TRANSICOES_PERMITIDAS` já traz a linha de nascimento
  `'' => [aguardando_administrativo]`.
- **D-14:** Legado com `etapa` NULL não é carimbado. As listas Contrato/Entrada nascem só com quem
  entrar pela porta nova.

**Navegação e permissões:**
- **D-15:** Permissões não mudam; só a navegação muda. Contrato continua exigindo `admin.contratos`.
  Entrada ganha chave própria nova no catálogo de `app/Support/Permissions.php`. `admin.contratos`
  está deliberadamente fora do `role:admin` em `routes/web.php:1442` — **não reempacotar essas rotas
  sob `role:admin` nem trocar por `comercial.cadastrar_empresa`.**
- **D-16:** `admin.empresas`: tirar do menu agora, apagar depois. Rota, `AdminController::empresas()`/
  `updateEmpresa()` e `Pages/Admin/Empresas.jsx` continuam vivos e acessíveis por URL direta.
  `AdminController::updateEmpresa()` zera campos omitidos no payload — mesmo modo de falha que já
  apagou a coluna "Link do Whats" no Polos.

### Claude's Discretion

- **D-09 (onde gravar owner/data da venda)** — decidido: colunas aditivas nullable. O planner pode
  revisitar se medir que a Fase 143 não precisa da data como coluna.
- Forma exata dos componentes de lista (tabela × cards), nome da classe do controller/serviço novo do
  módulo Entrada, e a chave literal da permission nova de Entrada.
- **(Adicionado por esta pesquisa, dentro do espírito da discricionariedade do CONTEXT — não travado
  por D-NN nenhum):** como resolver o "ator" (`User $por`) nos dois pontos de nascimento onde não há
  sessão autenticada (webhook, comando de reprocessamento) — ver `Common Pitfalls` Pitfall 1.

**Travado — mudar exige voltar ao usuário:** o nome `Entrada` (D-02); separação por processo e não
por etapa (D-05); corte de saída na etapa 5 (D-07); as duas pendências nunca somadas (D-11); as duas
portas nascendo na etapa 1 (D-13); permissões preservadas (D-15).

### Deferred Ideas (OUT OF SCOPE)

- Apagar de vez `admin.empresas` (rota, controller, página, permission) — D-16 só tira do menu.
- Segmento do cliente (`industry` do HubSpot) como coluna — `industry` continua em
  `hubspot_snapshot.company`.
- Destino da Fase 140 (COMUNIC) — decisão de roadmap, já resolvida no ROADMAP.md atual (140 sobrevive
  inteira).
- §3 × §5 não batem em contagem (12 × 9 atividades) — decisão da Fase 139, não desta.
- Filtro por etapa nas listas do Comercial — não exigido por COMERC-01..03.
- Os 12 itens de checklist de Contrato e Entrada, o botão FINALIZAR e a trava — Fase 139.
- Mensagem de boas-vindas — Fase 140.
- Tela de distribuição da Coordenação (141), onboarding nas etapas 7/8/9 (142), timeline/SLA (143).

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| COMERC-01 | Empresa criada pelo webhook de venda ganha nasce na etapa `Aguardando Administrativo` sem cadastro manual — e o cadastro manual do Comercial nasce na mesma etapa, pelo mesmo `EtapaTransicaoService` | `EtapaTransicaoService` já existe e é seguro para chamada concorrente (achado (a), `STATE.md` linhas 101-111); pontos de inserção exatos identificados: `HubspotWebhookController.php:242-275` (após `criarEmpresa()`, junto do `dispararSeElegivel()`) e `ComercialController.php:668-669` (idêntico) |
| COMERC-02 | As listagens Contrato e Entrada exibem, cada uma, os 8 campos mínimos do §2 — duas listas separadas por processo pendente, não por etapa | Contrato: `ContratoAdminController::index()` (linha 59) já existe, query não muda (D-06); Entrada: critério proposto por esta pesquisa (achado (c)) — `etapa IN [1,2,3,4]`; HubSpot Owner: `HubspotApiClient` (novo método `fetchOwner()`), 3 colunas novas em `companies` |
| COMERC-03 | A empresa permanece visível na Área Comercial até entrar na etapa `Aguardando Distribuição` | Mesmo critério do achado (c) acima — coincide com o corte da lista Entrada por construção (ambos usam `etapa < aguardando_distribuicao`) |

</phase_requirements>

## Standard Stack

Esta fase **não introduz nenhuma dependência nova**. Tudo que ela precisa já está instalado.

### Core (já instalado, reusado)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 (instalado) | Migration aditiva, Eloquent, `Cache` facade, `Http` client | Já é o stack do projeto |
| `inertiajs/inertia-laravel` + `@inertiajs/react` | ^2.0 (instalado) | Nova página `Entrada.jsx`, `Inertia::render()` no controller novo | Padrão de toda tela do projeto |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Illuminate\Support\Facades\Http` (via `HubspotApiClient`) | — | `GET /crm/v3/owners/{id}` | Novo método no client existente, mesmo padrão de `fetchCompany`/`fetchContact` |
| `Illuminate\Support\Facades\Cache` | — | Cache do nome do owner (driver `database`, já configurado) | `Cache::remember`, TTL longo (poucos vendedores) |

### Alternativas Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Novo método em `HubspotApiClient` para owner | Classe dedicada `HubspotOwnerClient` | Desnecessário — `HubspotApiClient` já concentra todos os GETs de objetos CRM (deal/company/contact/note/line_item); owner é só mais um objeto CRM v3, mesmo padrão |
| `Cache::remember` direto no controller | Serviço dedicado `HubspotOwnerResolver` (cache + fetch) | Recomendado — segue o padrão de responsabilidade única já usado em `app/Services/Hubspot/` (`HubspotCompanyMatcher`, `HubspotContactSelector`, `HubspotNameNormalizer` são todas classes pequenas de um propósito só) |

**Instalação:** nenhuma. `composer.json`/`package.json` não mudam nesta fase.

**Verificação de versão:** não aplicável — sem pacote novo.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote Composer ou npm novo — reusa
`HubspotApiClient`/`Http`/`Cache` já instalados. O protocolo de `slopcheck` não se aplica.

## Architecture Patterns

### System Architecture Diagram

```
┌──────────────────────────────┐        ┌──────────────────────────────────┐
│ HubSpot — deal vira GANHO     │        │ Comercial — cadastro manual        │
│ (webhook assíncrono)          │        │ ComercialController::store()       │
└──────────────┬────────────────┘        └──────────────────┬─────────────────┘
               │ POST /api/webhooks/hubspot                  │ auth()->user() disponível
               ▼                                              ▼
┌──────────────────────────────────────┐   ┌──────────────────────────────────┐
│ HubspotWebhookController::receive()   │   │ $company = Company::create([...])│
│  → criarEmpresa() [transação própria] │   │ (dentro de DB::transaction)      │
│    - fetchDeal (+ NOVO: owner_id)     │   └──────────────────┬─────────────────┘
│    - fetchOwner(id) [NOVO] → cache    │                      │ fora da transaction,
│    - Company::create / enriquecer     │                      │ após refresh() (mesmo
│  → $company->refresh()                │                      │ padrão do gate atual)
└──────────────┬────────────────────────┘                      ▼
               │ SEM usuário logado —            ┌──────────────────────────────────┐
               │ precisa de "ator de sistema"     │ EtapaTransicaoService             │
               │ (achado (a) — DECISÃO NOVA)      │  ->transicionar($company,         │
               ▼                                  │     ETAPA_AGUARDANDO_ADMINISTRATIVO,│
┌──────────────────────────────────────┐          │     $por, $motivo=null)          │
│ EtapaTransicaoService                 │◄─────────┤  (já existe, Fase 137 — seguro    │
│  ->transicionar($company, ETAPA_..., │          │   contra concorrência de propósito)│
│     $porSistema, $motivo=null)        │          └──────────────────────────────────┘
└──────────────┬────────────────────────┘
               │ grava companies.etapa + company_etapa_transicoes
               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ Área Comercial (navegação reorganizada — só AppLayout.jsx NAV_TREE muda)     │
│  ┌───────────────────┐  ┌───────────────────┐  ┌────────────────────────┐   │
│  │ Cadastro Empresas  │  │ Contrato           │  │ Entrada (NOVO, casca)  │   │
│  │ (já existe)        │  │ = admin.contratos  │  │ etapa IN [1,2,3,4]     │   │
│  │                    │  │ .index — SÓ ganha  │  │ controller/rota/página │   │
│  │                    │  │ colunas no payload │  │ NOVOS                  │   │
│  └───────────────────┘  └───────────────────┘  └────────────────────────┘   │
│  ambas leem hubspot_owner_id/hubspot_owner_nome/data_venda (colunas novas)   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
app/
├── Http/Controllers/
│   ├── Api/HubspotWebhookController.php   # +chamada transicionar() após refresh() (linha ~274)
│   ├── ComercialController.php            # +chamada transicionar() após refresh() (linha ~668)
│   └── ComercialEntradaController.php     # NOVO — nome sugerido, discricionário (CONTEXT)
├── Services/
│   ├── Hubspot/
│   │   └── HubspotOwnerResolver.php       # NOVO — cache + fetchOwner(), nome sugerido
│   └── HubspotApiClient.php               # +fetchOwner(string $id): ?array
database/
├── migrations/
│   └── 2026_09_0X_HHMMSS_add_hubspot_owner_data_venda_to_companies_table.php  # D-09
resources/js/
├── Pages/Comercial/
│   └── Entrada.jsx                        # NOVO — componente de verdade, NUNCA re-export
├── Layouts/
│   └── AppLayout.jsx                      # NAV_TREE: remove admin.empresas, move admin.contratos
│                                           # para o grupo Comercial, adiciona Entrada
config/
└── services.php                           # hubspot.props.deal.owner_id (+ webhook_user_id, se
                                            # a Opção A do Pitfall 1 for a escolhida)
app/Support/
├── Permissions.php                        # +COMERCIAL_ENTRADA (nome sugerido)
└── Modules.php                            # +entradas no grupo 'Comercial' (discricionário,
                                            # consistência com o Module Registry — Fase 97)
tests/Feature/Phase138/
├── ComercEtapaNascimentoWebhookTest.php
├── ComercEtapaNascimentoCadastroManualTest.php
├── ComercListagemContratoCamposTest.php
├── ComercListagemEntradaTest.php
├── ComercVisibilidadeAteEtapa5Test.php
└── ComercContratoPermissaoPreservadaTest.php  # regressão — reafirma ContratoAdminPermissaoTest
```

### Pattern 1: Reorganizar navegação sem mover arquivo (a lição do learning §4 aplicada ao inverso)

**What:** o item de menu (`NAV_TREE`) é desacoplado da rota/controller/página que ele aponta. Mover um
item de um grupo para outro é editar só o array — nunca cria/move arquivo `.jsx`.
**When to use:** sempre que a mudança pedida for "essa tela agora vive dentro de outro menu" e a tela
em si (rota, controller, página) não precisa mudar de comportamento.
**Example:**
```jsx
// Source: resources/js/Layouts/AppLayout.jsx:210-288 (estrutura real do NAV_TREE)
{
    group: 'Comercial',
    icon: Briefcase,
    children: [
        { label: 'Cadastro de Empresas', routeName: 'comercial.empresas.listagem', page: 'Comercial/EmpresasListagem', icon: Building2, permission: 'comercial.cadastrar_empresa' },
        { label: 'Grupos', routeName: 'comercial.empresas.listagem', routeParams: { tab: 'grupos' }, page: 'Comercial/EmpresasListagem', icon: ListChecks, permission: 'comercial.cadastrar_empresa' },
        // MOVIDO do grupo 'Administrativo' — rota, controller e page.jsx NÃO mudam:
        { label: 'Contrato', routeName: 'admin.contratos.index', page: 'Admin/Contratos', icon: FileSignature, permission: 'admin.contratos' },
        // NOVO — controller/rota/página nascem nesta fase:
        { label: 'Entrada', routeName: 'comercial.entrada.index', page: 'Comercial/Entrada', icon: ListChecks, permission: 'comercial.entrada' },
        // ...resto do grupo Comercial sem mudança...
    ],
},
{
    group: 'Administrativo',
    icon: Shield,
    children: [
        // 'Empresas' REMOVIDO daqui (D-16) — rota/controller/página sobrevivem, só o item some.
        // 'Contratos' REMOVIDO daqui — moveu pro grupo Comercial acima.
        { label: 'Relatório',  routeName: 'admin.relatorio',  page: 'Admin/Relatorio',  icon: FileBarChart, permission: 'admin.relatorio' },
        { label: 'Fechamento', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote,     permission: 'admin.financeiro' },
        { label: 'Inventário', routeName: 'admin.inventario', page: 'Admin/Inventario', icon: Package2,     permission: 'admin.inventario' },
    ],
},
```
**Por que isso preserva `ContratoAdminPermissaoTest`:** o teste
(`tests/Feature/Phase131/ContratoAdminPermissaoTest.php:84-101`) resolve a rota **por nome**
(`Route::getRoutes()->getByName('admin.contratos.index')`) e checa o middleware dela — nada no teste
depende de qual grupo do `NAV_TREE` aponta para essa rota. `routes/web.php:1442` (o grupo
`permission:admin.contratos`, fora de `role:admin`) **não precisa de nenhuma edição**.

### Pattern 2: Página React nova nunca nasce como re-export

**What:** para o módulo Entrada (que É novo, ao contrário do Contrato), o componente
`resources/js/Pages/Comercial/Entrada.jsx` deve ser um componente de verdade — nunca
`export { default } from '...'`.
**When to use:** toda vez que uma página nova entra no manifest do Vite.
**Anti-pattern documentado no projeto** (`.planning/learnings/painel-polos-status-e-meta.md` §4):
```jsx
// NUNCA — não entra no manifest do Vite, quebra em runtime (não no build):
export { default } from '../../OutraPagina/Index';
```
**Correto** — mesmo se o componente reusar bastante de `EmpresasListagem.jsx` (tabela, badges de
setor, formatação de moeda/data), o arquivo de página em si é uma função real:
```jsx
// Source: molde real do projeto, resources/js/Pages/Comercial/Entrada.jsx (a criar)
import AppLayout from '@/Layouts/AppLayout';
// ...imports de Table/Badge/etc., iguais aos de EmpresasListagem.jsx...

export default function Entrada({ companies, filters }) {
    return (
        <AppLayout title="Entrada">
            {/* ...tabela com os 8 campos do §2... */}
        </AppLayout>
    );
}
```

### Pattern 3: Ligar `EtapaTransicaoService` no ponto de nascimento (espelha o padrão já usado pelo gate de contrato)

**What:** chamar `transicionar()` **fora** da `DB::transaction()` de criação da empresa, depois de
`$company->refresh()` — exatamente onde `GatilhoContratoAdministrativoService::dispararSeElegivel()`
já é chamado hoje nos dois pontos.
**When to use:** os dois pontos de nascimento (COMERC-01, D-13).
**Example — `ComercialController::store()`** (o caso fácil, `$request->user()` disponível):
```php
// Baseado em app/Http/Controllers/ComercialController.php:668-669 (padrão já existente do gate)
$company->refresh();
app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);

// NOVO — mesma posição, mesmo padrão:
$resultado = app(EtapaTransicaoService::class)->transicionar(
    $company,
    Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
    $request->user(),   // T-137-02: NUNCA um user_id cru — sempre $request->user()/auth()->user()
);
if ($resultado['status'] !== 'transicionado') {
    // Não deveria acontecer para empresa recém-criada (etapa nasce NULL) — logar se acontecer.
    Log::warning('[Comercial] transição de nascimento inesperada', ['company_id' => $company->id, 'resultado' => $resultado]);
}
```
**Example — `HubspotWebhookController::criarEmpresa()`'s caller** (o caso difícil — sem usuário
logado, ver Pitfall 1 abaixo para a resolução do ator):
```php
// Baseado em app/Http/Controllers/Api/HubspotWebhookController.php:274-275
$company->refresh();

// NOVO:
$porSistema = User::find(config('services.hubspot.webhook_user_id'));
if ($porSistema === null) {
    Log::channel('ecf-webhooks')->error('[HubSpot Webhook] HUBSPOT_WEBHOOK_USER_ID não configurado ou usuário não existe — empresa NÃO transicionada para etapa 1', [
        'company_id' => $company->id,
    ]);
} else {
    $resultado = app(EtapaTransicaoService::class)->transicionar($company, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $porSistema);
    if (!in_array($resultado['status'], ['transicionado', 'recusado'], true)) {
        // 'recusado' é esperado em reprocessamento (empresa já está lá) — só 'erro' é anômalo.
        Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] transição de nascimento com status inesperado', ['company_id' => $company->id, 'resultado' => $resultado]);
    }
}

app(GatilhoContratoAdministrativoService::class)->dispararSeElegivel($company);
```
**Por que é seguro contra reentrega/retry do webhook:** `EtapaTransicaoService::transicionar()` já
releu com `lockForUpdate()` **dentro** da transação (gap closure G4/WR-01 da Fase 137, feito
justamente antecipando este chamador — `EtapaTransicaoService.php` linhas 179-208, `STATE.md` linhas
101-111). Uma segunda chamada para a mesma empresa (evento HubSpot reentregue, ou
`hubspot:reenriquecer-handoff` reprocessando quem já nasceu) recebe `status: 'recusado'` com
`requisito_faltante: "A empresa já está na etapa '...'."`— não é exceção, não quebra o webhook.

### Pattern 4: `HubspotApiClient::fetchOwner()` — mesmo molde dos outros GETs de objeto CRM

**What:** GET simples de um objeto CRM v3 por ID, resiliente (404/erro vira `null`, nunca lança).
**Example (molde real, adaptar de `fetchCompany`):**
```php
// Baseado em app/Services/HubspotApiClient.php:94-102 (fetchCompany) — mesmo padrão,
// mas resiliente como fetchAssociatedCompanyId (owner pode ter sido removido/arquivado)
public function fetchOwner(string $ownerId): ?array
{
    $res = Http::withToken($this->token)
        ->get(self::BASE . "/crm/v3/owners/{$ownerId}");

    if (!$res->ok()) {
        // NUNCA logar o token — só o ownerId + status HTTP (T-111-03, mesmo padrão do resto do client).
        Log::channel('ecf-webhooks')->warning('[HubSpot] fetchOwner falhou', [
            'owner_id' => $ownerId,
            'status'   => $res->status(),
        ]);
        return null;
    }

    return $res->json(); // {id, email, firstName, lastName, userId, archived, teams, ...}
}
```
**Fonte oficial do shape de resposta:** `developers.hubspot.com/docs/api-reference/legacy/crm/owners/guide`
(ver `Sources` abaixo) — `id`, `email`, `firstName`, `lastName`, `userId`, `archived`,
`userIdIncludingInactive`, `teams`. **Escopo exigido: `crm.objects.owners.read`** — não confirmado se
o Private App token da ECF (`HUBSPOT_ACCESS_TOKEN`) já tem esse escopo concedido (ver Pitfall 3).

### Anti-Patterns to Avoid

- **Mover fisicamente `Admin/Contratos.jsx` para `Comercial/Contrato/Index.jsx`** sem necessidade.
  D-06 diz "só ganha os 8 campos do §2 e a etapa" — o arquivo não precisa mudar de lugar para isso. Se
  o planner decidir mover mesmo assim (por preferência de organização), a única forma segura é um
  `git mv` de verdade + atualizar o **único** `Inertia::render('Admin/Contratos', ...)` em
  `ContratoAdminController.php:377` para o novo caminho — nunca criar um arquivo novo que só
  re-exporta o antigo.
- **Reempacotar `admin.contratos.*` sob `role:admin`** ou trocar a permission por
  `comercial.cadastrar_empresa` — quebra D-15 e faz `ContratoAdminPermissaoTest` ficar vermelho
  (`routes/web.php:1433-1441` documenta por quê, na letra).
- **Separar Contrato de Entrada usando `companies.etapa`** — D-05 proíbe explicitamente. O critério de
  Entrada proposto (achado (c)) usa etapa como limite EXTERNO compartilhado, não para diferenciar uma
  lista da outra.
- **Deduzir owner/data_venda de empresas com `etapa` NULL (legado)** — coerente com D-14: essas
  empresas nunca "entraram pela porta nova"; as 3 colunas novas ficam `NULL` para elas por padrão
  (nullable, sem backfill retroativo automático — só o comando manual do D-10, que só alcança quem
  tem `hubspot_deal_id`).
- **Passar um `user_id` cru (int) para `transicionar()`** em vez de resolver um `User` de verdade —
  T-137-02 exige `User` tipado; um "ator de sistema" precisa ser um `User::find()` real, nunca um
  valor inventado.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Decidir se uma transição de etapa é válida | Lógica de estado inline no controller do webhook | `EtapaTransicaoService::transicionar()` — já existe, já é o único ponto de escrita, já é seguro contra concorrência | Reescrever a régua no controller violaria ETAPA-03 (Fase 137) e duplicaria a tabela `TRANSICOES_PERMITIDAS` |
| Listar/filtrar contratos por estado do envelope | Query nova em `ComercialEntradaController` para o módulo Contrato | `ContratoAdminController::index()` — já existe, já lê os 7 estados do Clicksign corretamente (agrupamento por serviço-dono, D9 de isenção etc.) | D-06 é explícito: "só ganha os 8 campos do §2 e a etapa" — a query em si não muda |
| Buscar propriedade nova do deal HubSpot | Chamada HTTP paralela fora de `HubspotApiClient`/`config('services.hubspot.props.deal')` | Acrescentar a chave em `config/services.php` (`props.deal.owner_id`) — o fetch já pega TODAS as props configuradas numa única chamada (`fetchDeal($id, $properties)`) | O padrão config-driven já existe; criar um segundo caminho de fetch duplicaria a lógica de token/erro |
| Resolver nome do responsável comercial | Guardar nome digitado à mão em algum lugar | `GET /crm/v3/owners/{id}` + cache — HubSpot é a fonte de verdade do vendedor | D-08 é explícito: "responsável comercial vem do HubSpot" |
| Registrar "quem fez" numa transição sem sessão | Inventar um objeto `User` falso ou pular o registro | `User::find()` de um ID configurado (ator de sistema real, cadastrado no banco) | `CompanyEtapaTransicao.user_id` é FK real para `users` — precisa apontar para uma linha que existe |

**Key insight:** o único código genuinamente **novo** desta fase é pequeno — um método de fetch, um
resolver de cache, um controller+rota+página para Entrada, e duas chamadas de uma linha ao serviço já
pronto da Fase 137. A maior parte do "trabalho" é reorganização de onde as coisas aparecem no menu,
não reescrita de lógica.

## Common Pitfalls

### Pitfall 1: Não existe "ator de sistema" no projeto — é uma decisão nova, não uma leitura

**What goes wrong:** `EtapaTransicaoService::transicionar(Company $company, string $etapaDestino,
User $por, ?string $motivo = null)` exige um `User` real e não-nulo. O webhook (`receive()`) e o
comando de reprocessamento (`reprocessarEvento()`, chamado por `hubspot:reprocess-event` e
`hubspot:reenriquecer-handoff`) rodam **sem nenhuma sessão autenticada** — `auth()->user()` é `null`
nesses contextos. Sem resolver isso, a chamada a `transicionar()` explode com `TypeError` (tipo
estrito do parâmetro) e o `catch (\Throwable $e)` externo do webhook (linha 276) engoliria o erro,
marcando o evento como `status: 'erro'` — a empresa já teria sido criada (a `DB::transaction` de
`criarEmpresa()` já fechou), mas ficaria **para sempre sem etapa** (D-14 diz que só quem entra pela
porta nova ganha etapa — uma falha aqui faria a empresa nascer e nunca entrar de fato no fluxo,
contrariando o próprio princípio da fase).
**Why it happens:** o serviço foi desenhado na Fase 137 para D-13 ("o serviço recebe o `User` que
agiu") pensando em contextos com sessão (Comercial, Administrativo, Coordenação) — o caso "quem age é
uma integração automática" não tinha chamador nenhum até esta fase.
**How to avoid — duas opções, nenhuma travada pelo CONTEXT (decisão do planner):**
1. **Recomendado por esta pesquisa — ator de sistema configurável.** Seguir o precedente já existente
   no projeto de "ID de ator default via config" (`config('digisac.default_user_id')`,
   `app/Services/Digisac/NpsDigisacDispatchService.php:161-168`, resolvido a partir de
   `env('DIGISAC_DEFAULT_USER_ID')`). Adicionar `services.hubspot.webhook_user_id` (env
   `HUBSPOT_WEBHOOK_USER_ID`) apontando para o `id` de um `User` real e existente (ex.: uma conta
   dedicada "Sistema HubSpot" ou o admin operacional). Resolver com `User::find()` e tratar
   explicitamente o caso "não configurado/não encontrado" (log + **não** transicionar, deixando
   `etapa` `NULL` — mesmo efeito do fallback legado, nunca um 500). Exige **uma tarefa de setup**:
   criar/decidir esse usuário no banco (produção e local) antes do primeiro deploy — checkpoint
   humano recomendado.
2. **Alternativa — tornar `$por` nullable no serviço.** Mudar a assinatura para `?User $por = null` em
   `EtapaTransicaoService::transicionar()` e `CompanyEtapaTransicao.user_id` (já é `nullable()` no
   schema — `2026_09_01_120000_create_company_etapa_transicoes_table.php:49`, pensado originalmente
   para o caso "ator removido depois", não para "nunca houve ator"). Menor trabalho de setup, mas
   **toca um serviço que a Fase 137 já fechou e testou** (`EtapaTransicaoServiceTest.php`) — maior
   superfície de regressão, e a Fase 143 (timeline) teria que lidar com "ator desconhecido" como caso
   de exibição desde o primeiro evento, não só em exclusões futuras.
**Warning signs:** qualquer teste de feature que dispare o webhook e não injete/configure o ator de
sistema vai ou falhar com `TypeError` (se a Opção 1 não tiver fallback) ou silenciosamente não
transicionar (se o fallback for "logar e pular").

### Pitfall 2: Owner retroativo (D-10) só alcança quem tem `hubspot_deal_id` — cadastro manual nunca ganha owner

**What goes wrong:** `hubspot:reenriquecer-handoff`/`ReprocessHubspotEvent` refazem o fetch do **deal**
a partir de um `HubspotEvento` existente. Empresas cadastradas pelo Comercial via
`ComercialController::store()` (sem `hubspot_deal_id`, sem `HubspotEvento`) **nunca** vão ganhar
`hubspot_owner_id`/`hubspot_owner_nome` por nenhum comando de reprocessamento — não porque o comando
tenha um bug, mas porque não existe deal HubSpot para essas empresas. Isso é o comportamento
**correto e esperado** dado D-09 (colunas nullable), mas se não for documentado explicitamente na
tela, alguém vai reportar como bug "empresa sem responsável comercial" para cadastro manual.
**How to avoid:** UI da listagem Entrada/Contrato deve tratar `hubspot_owner_nome === null` como
estado normal (ex.: "—" ou "Cadastro manual"), não como erro. Não vale a pena construir um segundo
mecanismo de preenchimento manual do responsável comercial nesta fase — fora do escopo declarado.

### Pitfall 3: Escopo OAuth `crm.objects.owners.read` pode não estar concedido ao Private App

**What goes wrong:** o token atual (`HUBSPOT_ACCESS_TOKEN`) já tem escopo suficiente para
deals/companies/contacts/notes/line_items (todos em uso hoje), mas o endpoint de Owners exige o
escopo **`crm.objects.owners.read`** especificamente (fonte oficial —
`developers.hubspot.com/docs/api-reference/legacy/crm/owners/guide`, ver Sources). Não há como
confirmar sem acesso à conta real do HubSpot da ECF se esse escopo já está marcado no Private App.
**How to avoid:** primeira chamada a `fetchOwner()` em ambiente com credenciais reais deve ser tratada
como um checkpoint de verificação — se vier 403, é escopo faltando (a app precisa ser reconfigurada no
painel do HubSpot, ação humana fora do código). Recomendo um `checkpoint:human-verify` no plano,
similar ao que a Fase 126 fez para `max_upload_bytes` da Clicksign (gate não-medido, fechado por
checkpoint humano).

### Pitfall 4: `hubspot_owner_id` é property PADRÃO do HubSpot — mas o projeto mede tudo mesmo assim

**What goes wrong:** ao contrário das properties customizadas do deal (`servico_ecf`, `razao_social`,
os campos SPIN), `hubspot_owner_id` é uma property **padrão/sistema** presente em praticamente todo
portal HubSpot — não é algo que o admin da conta ECF poderia ter renomeado. Isso reduz bastante o
risco de "nome errado" comparado ao histórico do projeto (quick 260805-eqk), mas **não elimina a
obrigação de medir**: a disciplina do projeto, documentada na letra em `config/services.php`
(comentário linhas 156-162), é medir SEMPRE com `php artisan hubspot:inspect-properties
--objects=deals` antes de confiar num nome de property, sistema ou custom.
**How to avoid:** rodar o comando antes de fixar o default em `config/services.php`. Se a saída
confirmar `hubspot_owner_id` como `name` (interno) na tabela impressa, o default já está certo — o
comando serve para CONFIRMAR, não necessariamente para descobrir algo novo.

### Pitfall 5: `PendenciasComerciaisService` tem 7 chaves observadas, não 8 (D-11 cita "as 8 comerciais")

**What goes wrong:** o CONTEXT (D-11) fala em "as 8 comerciais derivadas de
`PendenciasComerciaisService`". A lista de chaves observadas em
`resources/js/Pages/Comercial/EmpresasListagem.jsx:35-44` (`PENDENCIAS_LABELS`) e replicada no
`$request->validate()` de `ComercialController::listagem()` (linhas 206-210) tem **7** chaves:
`sem_servico`, `sem_valor`, `servico_nao_reconhecido`, `sem_setor`, `sem_contato`, `valor_revisar`,
`possivel_duplicidade`. Não achei uma 8ª em nenhum dos dois lugares.
**Why it happens:** possivelmente uma contagem desatualizada no CONTEXT, ou uma pendência que existe
em `PendenciasComerciaisService::calcularUniversais()` mas nunca ganhou rótulo/filtro na UI.
**How to avoid:** ao implementar a coluna "pendências do cadastro" nas listas Contrato/Entrada, contar
direto de `PendenciasComerciaisService::calcularUniversais()` (linha 177) em vez de confiar no número
"8" — o texto/rótulo não muda o comportamento (a coluna simplesmente lista o que o serviço devolver),
mas vale registrar a divergência para não gerar confusão numa verificação futura.
**Warning signs:** nenhum — é só uma nota de precisão numérica, sem efeito funcional se o código ler
o serviço em vez de contar "manualmente" até 8.

## Code Examples

### Migration — 3 colunas aditivas em `companies` (D-09), seguindo o precedente de Fase 111

```php
// Baseado em database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php
// (mesmo padrão: Schema::hasColumn() defensivo, down() reversível, nada mais tocado)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'hubspot_owner_id')) {
                $table->string('hubspot_owner_id', 255)->nullable();
            }
            if (! Schema::hasColumn('companies', 'hubspot_owner_nome')) {
                $table->string('hubspot_owner_nome', 255)->nullable();
            }
            if (! Schema::hasColumn('companies', 'data_venda')) {
                // DATE, não DATETIME — mesmo formato que closedate já chega
                // ('Y-m-d', medido em HubspotDealHandoffService::parseDataHubspot()).
                $table->date('data_venda')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['hubspot_owner_id', 'hubspot_owner_nome', 'data_venda'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
```

### Backfill de `data_venda` para empresas já existentes — sem chamada de API nova

`closedate` já é buscado hoje e está dentro de `hubspot_snapshot.deal` (JSON) para toda empresa que
veio do HubSpot. Diferente do owner (Pitfall 2), `data_venda` **pode** ser retroativamente preenchida
sem custo de API — é só extrair do JSON já persistido:

```php
// Comando de backfill (a criar) — não é obrigatório para COMERC-01..03, mas fica documentado
// aqui porque a Fase 143 (histórico) se beneficia de data_venda povoada o quanto antes.
Company::whereNotNull('hubspot_snapshot')
    ->whereNull('data_venda')
    ->chunkById(100, function ($companies) {
        foreach ($companies as $company) {
            $closedate = $company->hubspot_snapshot['deal']['closedate'] ?? null;
            if ($closedate) {
                $company->update(['data_venda' => $closedate]); // string 'Y-m-d', cast 'date' no model
            }
        }
    });
```

### Critério da lista Entrada (achado (c)) — query proposta

```php
// Novo — ComercialEntradaController::index() (nome sugerido)
// Espelha o corte de COMERC-03 por construção: mesmo limite superior (< etapa 5).
$companies = Company::query()
    ->where('active', true)
    ->whereIn('etapa', [
        Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        Company::ETAPA_AGUARDANDO_ASSINATURA,
        Company::ETAPA_ADMINISTRATIVO_CONCLUIDO,
    ])
    // D-14: etapa NULL (legado) nunca entra — whereIn já exclui NULL por padrão em SQL,
    // mas o comentário fica explícito para não ser "consertado" por engano depois.
    ->get();
```

### Config — nova property `owner_id` no deal (D-08)

```php
// Baseado em config/services.php:134-181 (bloco 'deal' já existente)
'deal' => [
    // ...chaves já existentes (servico, closedate, razao_social, etc.)...

    // Fase 138 (COMERC-02, D-08) — property PADRÃO do HubSpot (não customizada),
    // mas medida do mesmo jeito que todas as outras por disciplina do projeto
    // (ver comentário acima, linhas 156-162 — precedente quick 260805-eqk).
    'owner_id' => env('HUBSPOT_PROP_DEAL_OWNER_ID', 'hubspot_owner_id'),
],
```

## Runtime State Inventory

> Fase parcialmente organizacional (reorg de navegação) + migration aditiva. Adaptado ao espírito da
> disciplina — nada aqui é rename/rebrand de string, mas há movimentação de item de menu e criação de
> colunas novas.

| Categoria | O que foi encontrado | Ação necessária |
|----------|-------------|------------------|
| Dado armazenado | `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` nascem do zero (D-09) — nenhuma coluna existente muda de significado. `data_venda` PODE ser backfilled de `hubspot_snapshot.deal.closedate` já persistido (ver Code Examples); `hubspot_owner_*` NÃO pode (Pitfall 2) | Migration aditiva + comando de backfill opcional de `data_venda`; nenhuma migração destrutiva |
| Configuração viva em serviço externo | HubSpot Private App: escopo `crm.objects.owners.read` pode não estar concedido (Pitfall 3) — configuração que vive no painel do HubSpot, não no git | `checkpoint:human-verify` antes de confiar em `fetchOwner()` em produção |
| Estado registrado no SO | Nenhum — nenhuma tarefa agendada, processo `pm2`/`systemd` referencia esta fase | Nenhuma |
| Segredos/env vars | `HUBSPOT_PROP_DEAL_OWNER_ID` (nova, opcional — default já cobre o caso comum) e, se a Opção 1 do Pitfall 1 for escolhida, `HUBSPOT_WEBHOOK_USER_ID` (nova, **obrigatória** para o webhook transicionar de verdade) | Documentar em `.env.example`; `HUBSPOT_WEBHOOK_USER_ID` precisa existir em produção E local antes do primeiro deploy desta fase |
| Artefato de build/pacote instalado | Nenhum pacote novo. `AppLayout.jsx` (client bundle) muda — exige `npm run build` ao final, como qualquer alteração de front (convenção do projeto) | `npm run build` obrigatório |
| Navegação (item específico desta fase) | `admin.empresas` sai do `NAV_TREE` (D-16); `admin.contratos` muda de grupo no `NAV_TREE`. **Bookmarks/links diretos para `/administrativo/empresas` e `/administrativo/contratos` continuam funcionando** — só o item de menu muda, rota/URL não mudam | Nenhuma ação além da edição do `NAV_TREE` — nenhum link quebra |

**Achado central desta seção:** o único runtime state genuinamente novo e "vivo fora do git" é o
escopo OAuth do HubSpot (Pitfall 3) e, se a Opção 1 do Pitfall 1 for escolhida, a existência de um
usuário real no banco apontado por `HUBSPOT_WEBHOOK_USER_ID` — sem ele configurado, o webhook cria a
empresa normalmente mas ela nunca ganha etapa (comportamento degradado, não crash, pelo desenho do
Pitfall 1).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `hubspot_owner_id` é o nome interno correto da property de owner no deal — baseado em conhecimento de que é property PADRÃO do HubSpot (WebSearch, não medido contra a conta real da ECF) | Common Pitfalls (Pitfall 4), Code Examples | Baixo — é property de sistema, não customizável; mas se por algum motivo divergir, o comando `hubspot:inspect-properties` (que o próprio plano deve rodar) pega o erro antes do deploy |
| A2 | O escopo OAuth `crm.objects.owners.read` do Private App da ECF NÃO foi confirmado como concedido | Common Pitfalls (Pitfall 3) | Médio — se não concedido, `fetchOwner()` retorna 403/null silenciosamente (client já resiliente), owner fica sempre null até alguém notar e reconfigurar no painel HubSpot |
| A3 | Não existe "ator de sistema" (`User`) hoje no projeto para contextos sem sessão — busquei por convenções (`system_user`, seeders, `Configuracao`) e não encontrei nenhuma | Common Pitfalls (Pitfall 1), Architecture Patterns (Pattern 3) | Alto se ignorado — sem essa decisão, o webhook nunca transiciona a empresa para etapa 1, quebrando COMERC-01 silenciosamente (recusa vira log, não erro visível) |
| A4 | O critério proposto para a lista Entrada (`etapa IN [1,2,3,4]`) é uma **recomendação desta pesquisa**, não uma decisão já travada no CONTEXT — deduzida combinando D-05, D-06, D-07 e D-14 | Summary achado (c), Code Examples | Médio — se o planner/usuário quiser um critério diferente (ex.: baseado em pendência declarada da Fase 137), a lista muda de forma, mas a estrutura de controller/rota/página proposta continua válida |
| A5 | `PendenciasComerciaisService::calcularUniversais()` devolve 7 chaves observadas na UI atual, não 8 como o CONTEXT (D-11) cita | Common Pitfalls (Pitfall 5) | Baixo — nota de precisão numérica, sem efeito se o código ler o serviço em vez de contar manualmente |

**Nenhuma claim sobre `arquivo:linha` deste documento é `[ASSUMED]`** — todas foram verificadas por
leitura direta do código deste worktree nesta sessão. Os itens acima são as únicas lacunas que
dependem de algo fora do repositório (conta real do HubSpot) ou de uma decisão de arquitetura nova
sem precedente direto no código.

## Open Questions

1. **Qual das duas opções do Pitfall 1 resolve o "ator de sistema"?**
   - What we know: `transicionar()` exige `User` não-nulo; nem webhook nem comando de reprocessamento
     têm sessão; a coluna `user_id` de `company_etapa_transicoes` já é nullable no schema (pensada
     para outro motivo — ator removido depois, não ausência desde o início).
   - What's unclear: se a ECF prefere criar um usuário "Sistema HubSpot" de verdade no banco (Opção 1,
     menor blast radius) ou preferir tornar o parâmetro nullable (Opção 2, toca serviço já fechado da
     Fase 137).
   - Recommendation: Opção 1 (config-driven, seguindo o precedente `digisac.default_user_id`) — não
     reabre nem retesta o serviço da Fase 137, e falha de forma segura (log + `etapa` continua NULL)
     se mal configurada, em vez de `TypeError`.

2. **O escopo OAuth `crm.objects.owners.read` está concedido ao Private App da ECF?**
   - What we know: os outros GETs (`deals`, `companies`, `contacts`, `notes`, `line_items`) já
     funcionam em produção — o token existe e tem MUITOS escopos concedidos.
   - What's unclear: se o escopo específico de Owners foi incluído. Não há como confirmar sem acesso à
     conta HubSpot real.
   - Recommendation: primeira chamada real a `fetchOwner()` em produção (ou sandbox com o token real)
     vira checkpoint humano — se 403, reconfigurar o Private App no painel HubSpot antes de prosseguir.

3. **O nome da classe do controller/serviço novo do módulo Entrada, e a chave literal da permission
   nova** — explicitamente delegado ao planner pelo CONTEXT ("Claude's Discretion"). Esta pesquisa
   sugere `ComercialEntradaController` / `Permissions::COMERCIAL_ENTRADA = 'comercial.entrada'`
   (seguindo o padrão `COMERCIAL_CADASTRAR_EMPRESA = 'comercial.cadastrar_empresa'` já existente), mas
   não é uma decisão travada.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (DB de teste = SQLite `:memory:`) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Phase138 tests/Unit/Phase138 --colors=never` |
| Full suite command | `C:\xampp\php\php.exe artisan test` — **ver aviso abaixo, não termina neste ambiente** |

**Ambiente, reconfirmado nesta pesquisa (2026-09-02, HEAD `2a9562af`):** `node_modules/` e
`public/build/` **já existem** neste worktree (o caminho `npm-build` da Fase 137, plano 137-01, já foi
executado e persiste — `git status --short` não lista nada pendente). **Não** é necessário recriar
`public/hot` nem rodar `npm install`/`npm run build` antes de rodar testes Feature que renderizam
Inertia — ao contrário do estado que a pesquisa da Fase 137 encontrou no início daquela fase.

**Aviso herdado da Fase 137 (`137-BASELINE-TESTES.md`), ainda válido:** `php artisan test` /
`--testsuite=Feature` completo **não termina** neste ambiente offline — trava numa cascata de timeout
de rede (~300s) e nunca imprime o resumo final. Rodar sempre por diretório (`tests/Feature/Phase138`,
`tests/Feature/Phase37...` etc.), nunca `--testsuite=Feature` inteiro. `tests/Feature/Phase38/
PolosControllerTest.php` e `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` têm ~10 falhas
pré-existentes documentadas (`.planning/learnings/painel-polos-status-e-meta.md` §2) — não são
regressão desta fase.

**Baseline exigida pelo `CLAUDE.md`:** a migration de D-09 é aditiva (3 colunas nullable, sem
backfill destrutivo, sem alterar coluna existente) — mais branda que a da Fase 137 (que criava coluna
+ backfillava ~500 linhas). Ainda assim, `companies` tem dado de produção e a regra do `CLAUDE.md`
("migration que altere tabela existente com dado em produção") se aplica. Recomendo capturar uma
baseline curta e específica **antes** da migration: `vendor/bin/phpunit tests/Feature/Phase37CompaniesPerformanceFilterTest.php
tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php tests/Feature/Phase131/ContratoAdminPermissaoTest.php
tests/Unit/Phase137 tests/Feature/Phase137` (as suítes que tocam `companies`, o módulo Contrato e a
máquina de estados — as três coisas que esta fase encosta). Não é preciso recapturar a suíte inteira
de 137 — só confirmar que continua verde antes de adicionar colunas.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| COMERC-01 | Webhook cria empresa → etapa nasce `aguardando_administrativo`; cadastro manual do Comercial idem | feature | `phpunit tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php` + `ComercEtapaNascimentoCadastroManualTest.php` | ❌ Wave 0 |
| COMERC-01 (regressão de concorrência) | Segunda chamada de `transicionar()` para a mesma empresa (evento reentregue) não quebra, devolve `recusado` | unit/feature | reusa `tests/Unit/Phase137/EtapaTransicaoServiceTest.php` (já cobre idempotência do serviço); teste novo só garante que o CALL SITE do webhook trata `recusado` sem lançar | ❌ Wave 0 (novo call site) |
| COMERC-02 | Listagem Contrato mostra os 8 campos do §2 (incl. owner + data_venda) | feature | `phpunit tests/Feature/Phase138/ComercListagemContratoCamposTest.php` | ❌ Wave 0 |
| COMERC-02 | Listagem Entrada mostra os 8 campos e lista só `etapa IN [1..4]`, mesma empresa pode aparecer em ambas | feature | `phpunit tests/Feature/Phase138/ComercListagemEntradaTest.php` | ❌ Wave 0 |
| COMERC-02 | Pendência do fluxo e pendências do cadastro em colunas separadas, nunca somadas | feature | incluído no teste de listagem acima (assert de shape do payload) | ❌ Wave 0 |
| COMERC-03 | Empresa em `administrativo_concluido` (4) continua visível; em `aguardando_distribuicao` (5) some | feature | `phpunit tests/Feature/Phase138/ComercVisibilidadeAteEtapa5Test.php` | ❌ Wave 0 |
| D-15 (regressão) | `admin.contratos.index` continua com `permission:admin.contratos`, nunca `role:admin` — mesmo depois da mudança de menu | feature (já existe) | `phpunit tests/Feature/Phase131/ContratoAdminPermissaoTest.php` (reusar sem modificar) | ✅ já existe |
| D-16 (regressão) | `admin.empresas` continua acessível por URL direta mesmo fora do menu | feature | novo teste leve, ou nota manual — rota já testada indiretamente por `AdminController` existente | ⚠️ verificar se já há cobertura |

### Sampling Rate

- **Por commit de task:** `tests/Unit/Phase138 tests/Feature/Phase138` (rápido).
- **Por merge de wave:** suítes acima + `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` +
  `tests/Unit/Phase137 tests/Feature/Phase137` (regressão contra o que a Fase 137 já garantiu).
- **Gate da fase:** baseline específica (não a suíte Feature inteira, que não termina neste ambiente)
  verde antes de `/gsd:verify-work`.

### Wave 0 Gaps

- [ ] `tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php` — cobre COMERC-01 (webhook)
- [ ] `tests/Feature/Phase138/ComercEtapaNascimentoCadastroManualTest.php` — cobre COMERC-01 (manual)
- [ ] `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` — cobre COMERC-02 (Contrato)
- [ ] `tests/Feature/Phase138/ComercListagemEntradaTest.php` — cobre COMERC-02 (Entrada)
- [ ] `tests/Feature/Phase138/ComercVisibilidadeAteEtapa5Test.php` — cobre COMERC-03
- [ ] Fixture/mock de `HubspotApiClient::fetchOwner()` — os testes de webhook não devem chamar a API
      real; seguir o padrão já usado pelos testes existentes de `HubspotWebhookController` (mock via
      container ou fake HTTP do Laravel, `Http::fake()`)
- [ ] Framework: nenhum a instalar — PHPUnit já configurado, ambiente Vite já destravado (ver acima)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Não diretamente | — |
| V3 Session Management | Não | — |
| V4 Access Control | **Sim** | `permission:admin.contratos` (preservada) + nova `permission:comercial.entrada`; middleware existente, mesmo padrão da Fase 131 |
| V5 Input Validation | **Sim** | `$etapaDestino` já validado contra `Company::ETAPAS` dentro de `podeTransicionar()` (Fase 137); nada novo aceita string arbitrária de fora |
| V6 Cryptography | Não | — |

### Superfícies de risco identificadas

1. **Token do HubSpot nunca sai do backend.** `fetchOwner()` roda em `HubspotApiClient`, chamado só
   por controllers/comandos server-side — igual a todo o resto do client. Nenhuma mudança de padrão
   necessária.
2. **Ator forjado numa transição (T-137-02, reafirmado aqui).** O "ator de sistema" (Pitfall 1) **não**
   pode ser um valor vindo de payload/request — só um `User::find()` de um ID fixo em config,
   resolvido no servidor. Se o dia vier a expor `transicionar()` num endpoint HTTP direto (fora do
   escopo desta fase), a mesma regra vale: nunca aceitar `user_id` do corpo da requisição.
3. **Rota `admin.contratos.*` continua exigindo a permission certa mesmo depois da reorganização de
   menu** — coberto pelo teste de regressão já existente (`ContratoAdminPermissaoTest`), que este
   plano deve rodar sem modificar.
4. **`HUBSPOT_WEBHOOK_USER_ID` mal configurado não pode virar escalação de privilégio.** Se o ID
   apontar para um usuário com role/permissão inadequada, toda empresa nascida pelo webhook fica
   registrada como transicionada por esse ator no histórico (`company_etapa_transicoes.user_id`) — não
   é uma falha de segurança em si (o histórico é só leitura/auditoria), mas vale registrar como
   consideração de design: o usuário escolhido deveria ser identificável como "sistema" na timeline da
   Fase 143, não um humano real cujo nome apareceria erroneamente como autor de todo nascimento
   automático.

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Ator forjado numa gravação que registra "quem fez" | Spoofing | `User::find()` de ID fixo em config, nunca de payload — mesma disciplina T-137-02 |
| Escalação de acesso via reempacotamento de rota (`admin.contratos` sob `role:admin`) | Elevation of Privilege | Teste de regressão dedicado (`ContratoAdminPermissaoTest`) que falha se isso acontecer |
| Vazamento de token HubSpot em log de erro do `fetchOwner()` | Information Disclosure | Seguir o padrão já estabelecido no client — logar só `owner_id` + status HTTP, nunca a mensagem crua da exceção nem o token |

## Sources

### Primary (HIGH confidence — leitura direta do código deste worktree)
- `.planning/phases/138-.../138-CONTEXT.md` (íntegro) — decisões D-01..D-16
- `.planning/REQUIREMENTS-v23.md`, `.planning/ROADMAP.md` (linhas 2076-2260) — bloco v23.0 e Phase 138
  já reescrito (pré-requisito bloqueante fechado)
- `.planning/seeds/138-140-admin-no-comercial-dois-modulos-260902.md`,
  `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` (íntegros)
- `.planning/phases/137-.../137-CONTEXT.md`, `137-RESEARCH.md`, `137-BASELINE-TESTES.md` (íntegros) —
  molde de formato e achados reusados (D-12/D-17/D-18/D-19 da Fase 137, procedimento de baseline)
- `app/Services/FluxoEntrada/EtapaTransicaoService.php` (íntegro) — já implementado, já endurecido
  contra concorrência (docblock linhas 179-208)
- `app/Models/CompanyEtapaTransicao.php` + `database/migrations/2026_09_01_120000_create_company_etapa_transicoes_table.php`
- `app/Http/Controllers/Api/HubspotWebhookController.php` (linhas 1-60, 225-300, 510-880) —
  `criarEmpresa()`, `enriquecerEmpresaExistente()`, pontos de chamada
- `app/Http/Controllers/ComercialController.php` (linhas 1-60, 190-672) — `listagem()`, `store()`
- `app/Http/Controllers/ContratoAdminController.php` (linhas 1-130) — `index()`, universo da query
- `app/Services/HubspotApiClient.php` (íntegro) — molde para `fetchOwner()`
- `config/services.php` (linhas 108-227) — bloco `hubspot`, confirma ausência de `owner`
- `app/Console/Commands/HubspotInspectProperties.php` (íntegro) — procedimento de medição
- `routes/web.php` (linhas 1390-1463) — grupos `admin.*` e `admin.contratos.*`
- `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` (íntegro) — asserção por nome de rota
- `resources/js/Layouts/AppLayout.jsx` (linhas 195-292, 515-528) — `NAV_TREE`, `isActive()`
- `resources/js/Pages/Comercial/EmpresasListagem.jsx` (linhas 1-90), `resources/js/lib/contratoStatus.js` (íntegro)
- `app/Support/Permissions.php` (linhas 70-95, 173-177), `app/Support/Modules.php` (íntegro)
- `app/Services/Digisac/NpsDigisacDispatchService.php` (linhas 130-169), `config/digisac.php` — precedente de ator/ID default via config
- `database/migrations/2026_07_24_111001_add_hubspot_fields_to_companies_table.php` — molde de migration aditiva
- `.planning/learnings/painel-polos-status-e-meta.md` (§1, §2, §4), `.planning/learnings/desempenho-bonificacao.md` (§6), `.planning/learnings/gates-do-gsd-em-projeto-pt-br.md` (íntegro)
- `.planning/STATE.md` (linhas 1-150) — gap closure G4/WR-01 antecipando este chamador
- Execução real nesta sessão: `git status --short`, `git rev-parse HEAD`, `ls node_modules public/build`, várias buscas `grep`/`find` no worktree

### Secondary (MEDIUM confidence — WebSearch/WebFetch verificado contra fonte oficial)
- `developers.hubspot.com/docs/api-reference/legacy/crm/owners/guide` (WebFetch) — shape de resposta de
  `GET /crm/v3/owners/{id}`, escopo `crm.objects.owners.read`, comportamento de `archived`
- WebSearch "hubspot_owner_id default property deals internal name" — confirma que é property PADRÃO
  do sistema, não customizável por conta

### Tertiary (LOW confidence)
- Nenhuma — todas as claims de código vêm de leitura direta; as duas claims externas (HubSpot Owners
  API) foram verificadas contra a documentação oficial, não apenas WebSearch cru.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma dependência nova, tudo confirmado já instalado
- Architecture: HIGH — todos os pontos de integração citados foram lidos por inteiro, com linha real;
  o único ponto MEDIUM é o critério da lista Entrada (achado (c) — recomendação desta pesquisa, não
  fato já decidido)
- Pitfalls: HIGH para os 5 listados — 4 foram confirmados por leitura direta de código (ausência de
  ator de sistema, escopo do `hubspot_deal_id` no comando de reprocessamento, contagem real de
  `PENDENCIAS_LABELS`, comportamento de `HubspotApiClient` existente); 1 (escopo OAuth
  `crm.objects.owners.read`) é MEDIUM por depender de estado externo não verificável nesta sessão

**Research date:** 2026-09-02
**Valid until:** 2026-09-16 (14 dias — domínio majoritariamente interno e estável, mas a integração
HubSpot Owner depende de estado externo (escopo OAuth) que pode mudar sem aviso; reconferir antes de
reabrir esta pesquisa se muito tempo passar)
