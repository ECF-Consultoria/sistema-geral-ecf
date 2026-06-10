# Mudança — Agrupamento de empresas por dono (principal + vinculadas)

> **Escopo:** SOMENTE localhost. Sem commit e **sem deploy** — tudo no working tree
> para revisão manual. Convenção pt-BR e tokens `ecf-*` preservados.
> **Data:** 2026-06-09 · **Quick:** 260609-nqr

## O que é "grupo"

Um **grupo** é uma **empresa principal (dono)** mais as **empresas vinculadas** a ela.
O vínculo já existia no banco via `companies.parent_company_id` (migration
`2026_05_20_100003_add_parent_company_id_to_companies.php`) e no model `Company`
(relacionamentos `pai()` / `filhas()`). Esta mudança torna esse conceito **editável**
no Comercial e **filtrável** no Dashboard — sem nova migration.

Regra de modelagem: **1 nível de profundidade**. Uma empresa é principal **ou** vinculada,
nunca as duas coisas. O grupo é gerenciado **pela principal** (abre-se a principal e
marcam-se as vinculadas — não se edita a filha para apontar o pai).

---

## Frente 1 — Vínculo no Comercial (gerenciado pela principal)

### Backend — `app/Http/Controllers/ComercialController.php`

- **`empresas()`** — eager load de `pai:id,name` e `filhas:id,name,parent_company_id`,
  e `parent_company_id` adicionado às colunas selecionadas. Cada empresa passa a expor:
  - `nome_pai` — nome da principal (quando esta é vinculada);
  - `filhas_count` — quantas vinculadas tem (quando é principal);
  - `is_principal` — `true` se tem ao menos uma vinculada (dirige o badge na listagem).
- **`update()`** — valida `filha_ids` (`nullable|array`; cada item `integer|exists:companies,id`
  e `notIn[self]`). `filha_ids` não é fillable, então é ignorado no `update()` dos campos
  básicos; quando **presente** no payload, chama `sincronizarFilhas()`. Campo **ausente** =
  não mexe nos vínculos.
- **Novo helper `sincronizarFilhas(Company $principal, array $filhaIds)`** — reatribui as
  vinculadas com as travas de 1 nível (mensagens pt-BR via `ValidationException`):
  - a própria principal não pode já estar vinculada a outra (`parent_company_id != null`);
  - nenhuma selecionada pode ser, ela mesma, principal de outras (`whereHas('filhas')`);
  - desvincula (`parent_company_id = null`) quem saiu da lista;
  - vincula as selecionadas (movendo de outro grupo, se for o caso).

### Frontend — `resources/js/Pages/Comercial/Empresas.jsx`

- **`FormularioEditar`** — novo bloco **"Empresas vinculadas"** (lista de checkboxes,
  `accent-ecf-yellow`) populado por `vinculaveis` e iniciado por `filhaIdsAtuais`. Estados:
  - se a empresa **já é vinculada** (`company.parent_company_id`): mostra nota
    "Esta empresa está vinculada a **{nome_pai}**. Para gerenciar vinculadas, abra a
    empresa principal." (sem checkboxes — espelha a trava do backend);
  - se não há candidatas: nota "Nenhuma outra empresa disponível para vincular.";
  - caso contrário: lista marcável, enviada como `filha_ids` no `put`.
- **Candidatas (`vinculaveis`)** derivadas no próprio componente a partir de `companies`:
  ativas, que **não** são principais (`!is_principal`), e livres ou já desta principal
  (`parent_company_id == null || === empresaEditar.id`), excluindo a própria.
- **`EmpresaRow`** — badge **"Principal · N vinculada(s)"** (`ecf-yellow/10`) ao lado do
  nome quando `is_principal`; e linha discreta **"↳ vinculada a {nome_pai}"** quando a
  empresa é uma vinculada (espelha o ↳ do Relatório de Fechamento).

> **Correção colateral no mesmo arquivo** (bundled, não faz parte do agrupamento):
> os contratos de serviço e os botões "Nova Empresa" passaram a usar o helper `route()`
> do Ziggy em vez de caminhos absolutos crus (`/empresas/...`), que quebravam (404 do
> Apache) quando o app roda em subdiretório.

---

## Frente 2 — Filtro por grupo no Dashboard principal

> **Mudança de rumo:** o filtro foi para o **Dashboard principal** (`/dashboard`), e a
> ideia original de um filtro no Dashboard ECF foi **descartada** (o Dashboard ECF inteiro
> foi removido nesta passada — ver SUMMARY). Opera sobre os dados da **Adman**
> (faturamento/TACOS/NPS/ranking), na mesma infra dos filtros já existentes.

### Backend — `app/Http/Controllers/DashboardController.php` (`adminDashboard`)

- Lê novo filtro `group_id` do request.
- Quando preenchido, filtra `$companiesQuery` para a principal + suas vinculadas:
  `where(id = group_id OR parent_company_id = group_id)`.
- Monta `grupos_list` — principais ativas, sem pai, **com** vinculadas
  (`whereNull('parent_company_id')->whereHas('filhas')`), ordenadas por nome.
- Expõe `group_id` em `filters` e `grupos_list` no payload do Inertia.

### Frontend — `resources/js/Pages/Dashboard/Admin.jsx`

- Recebe `grupos_list = []`.
- Novo `ECFSelect` **"Todos os grupos"** (só renderiza quando `grupos_list.length > 0`),
  ao lado dos filtros de empresa/consultor/estrategista. Ao escolher, chama
  `applyFilter('group_id', v)`.

---

## Arquivos alterados

| Arquivo | Frente |
|---|---|
| `app/Http/Controllers/ComercialController.php` | 1 — backend do vínculo + `sincronizarFilhas()` |
| `resources/js/Pages/Comercial/Empresas.jsx` | 1 — UI de vinculadas + badges (+ fix Ziggy) |
| `app/Http/Controllers/DashboardController.php` | 2 — filtro `group_id` + `grupos_list` |
| `resources/js/Pages/Dashboard/Admin.jsx` | 2 — `ECFSelect` de grupo |

Sem migration nova (`parent_company_id` já existia).

## Como testar (localhost)

1. **Comercial → editar uma empresa de topo:** marcar empresas em "Empresas vinculadas",
   salvar, conferir o badge **"Principal · N vinculada(s)"** na listagem e o **"↳ vinculada a …"**
   nas filhas.
2. **Travas de 1 nível:** tentar vincular uma empresa que já é principal de outro grupo →
   erro pt-BR; abrir uma empresa já vinculada → bloco informativo sem checkboxes.
3. **Dashboard `/dashboard`:** o seletor **"Todos os grupos"** só aparece se existir ao
   menos um grupo; ao escolher, os KPIs/ranking refletem a principal + vinculadas.

## Status

- `npm run build` verde na execução original.
- **Não commitado, não deployado** — apenas localhost, por decisão do usuário.
