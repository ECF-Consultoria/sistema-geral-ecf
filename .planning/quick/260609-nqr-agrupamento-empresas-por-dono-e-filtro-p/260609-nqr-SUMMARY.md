---
phase: quick-260609-nqr
plan: 01
status: complete
date: 2026-06-09
commits: none (por decisão do usuário — só edição, sem commit)
---

# Resumo — Agrupamento de empresas por dono + filtro por grupo no Dashboard ECF

Entrega das duas frentes do conceito de **grupo** (empresa principal/dono +
empresas vinculadas via `companies.parent_company_id`, que já existia). **Nenhum
commit** foi feito — o usuário pediu apenas as edições no working tree para revisar
e commitar manualmente (a árvore tinha trabalho em andamento untracked, inclusive
o próprio DashboardEcf). Execução **inline** (sem gsd-executor) para não varrer
arquivos não relacionados. Somente localhost; sem deploy.

## Frente 1 — Vinculação no Comercial (gerenciada PELA principal)

Modelo: abre-se a empresa **principal** e marca-se as **empresas vinculadas** (filhas)
do grupo — não se edita a filha para apontar o pai. Rótulo sem "(dono)".

- **app/Http/Controllers/ComercialController.php**
  - `empresas()`: eager load de `pai`/`filhas` + coluna `parent_company_id`; expõe
    por empresa `parent_company_id`, `nome_pai`, `filhas_count`, `is_principal`. O front
    deriva candidatas/vinculadas atuais da própria lista `companies`.
  - `update()`: valida `filha_ids` (`nullable|array`; itens `integer|exists|notIn[self]`),
    persiste name/cnpj/notes e chama `sincronizarFilhas()` quando `filha_ids` vem no payload.
  - Novo helper `sincronizarFilhas()`: reatribui as vinculadas com limite de 1 nível
    (a principal não pode ela mesma ser vinculada; vinculada não pode ser principal de
    outras) e desvincula quem saiu da lista. Mensagens pt-BR.
- **resources/js/Pages/Comercial/Empresas.jsx**
  - `FormularioEditar`: multi-select (checkboxes) **"Empresas vinculadas"** populado por
    `vinculaveis` (ativas, não-principais, livres ou já desta principal). Se a empresa já
    é uma vinculada, mostra nota "gerencie pela empresa principal".
  - `EmpresaRow`: badge "Principal · N vinculada(s)" e linha "↳ vinculada a {nome_pai}".

## ⚠️ Revisão (mudança de rumo do usuário)

O filtro por grupo foi **movido para o Dashboard PRINCIPAL** e o **Dashboard ECF foi
EXCLUÍDO** por completo (decisão do usuário). A Frente 2 abaixo descreve a implementação
original no Dashboard ECF — **descartada**. O que vale agora:

- **Excluídos** (eram untracked, removidos do filesystem): `app/Http/Controllers/DashboardEcfController.php`,
  `app/Services/DashboardEcfService.php`, `app/Console/Commands/DashboardEcfSync.php`,
  `resources/js/Pages/DashboardEcf/` (Index + componentes). Wiring removido: rota
  `dashboard-ecf` (web.php), agendamento `ecf:dashboard-sync` (console.php), item de
  menu (AppLayout.jsx).
- **Filtro de grupo no Dashboard principal** (`DashboardController::adminDashboard` +
  `Dashboard/Admin.jsx`): novo `ECFSelect` "Todos os grupos" listando as principais com
  vinculadas (`grupos_list`); ao escolher um grupo, filtra `$companies` para a principal +
  suas vinculadas (`where id = group_id OR parent_company_id = group_id`). Opera sobre os
  dados da **Adman** (faturamento/TACOS/NPS/ranking) — mesma infra dos filtros já existentes
  (`company_id`/`consultor_id`/`estrategista_id`). A Frente 1 (vinculação no Comercial) é
  quem alimenta os grupos.

## ~~Frente 2 — Filtro por grupo no Dashboard ECF~~ (DESCARTADA — ver revisão acima)

- **app/Services/DashboardEcfService.php**
  - `computar(array $grupos = [])`: numa ÚNICA leitura dos arquivos brutos, acumula
    o GERAL e, em paralelo, os acumuladores do grupo E de cada empresa-membro a que
    cada `custId` pertence (índices `custId → grupo` e `custId → empresa`). Helpers:
    `acumularLinhaMensal()`, `acumularLinhaGrant()`, `montarPayload()` (reuso idêntico).
  - Retorno: payload GERAL (retrocompat byte-equivalente) + chave aditiva `grupos`
    (`[{id, nome, agregado, empresas:[{id, nome, agregado}]}]`) — o total do grupo MAIS
    o agregado individual de cada empresa, para filtrar grupo OU empresa isolada.
    Grupos/empresas sem dados no ECF Drive são omitidos.
  - `sincronizar(array $grupos = [])` repassa os grupos.
- **app/Console/Commands/DashboardEcfSync.php**
  - Carrega principais ativas COM filhas, monta `[{id, nome, empresas:[{id, nome,
    cust_id}]}]` via accessor `cust_id` (`ml_store_id ?: adman_account_id`), descarta
    empresas/grupos sem custId e passa a `sincronizar($grupos)`. Log reporta a contagem.
- **app/Http/Controllers/DashboardEcfController.php**
  - Sem mudança funcional (o cache já traz `grupos`); comentário documenta o contrato.
- **resources/js/Pages/DashboardEcf/Index.jsx**
  - Estado `grupoSel` + `view` (agregado selecionado); todas as seções
    (KPIs/grants/reputação/programas/níveis/evolução) leem de `view`. Seletor
    hierárquico (`optgroup`) com chaves `geral` / `grupo:{id}` / `empresa:{id}`:
    Geral, "Grupo completo (total)" e "↳ {empresa}" para cada membro. Só aparece
    quando `dados.grupos.length > 0`. Fallback `[]` para caches antigos.

## Verificação

- `php -l` limpo: ComercialController, DashboardEcfService, DashboardEcfSync.
- `npm run build` — **verde** (`✓ built in 11.21s`, exit 0); assets `Empresas` e
  `DashboardEcf/Index` gerados.
- Sem migration nova (`parent_company_id` já existia).

## Pendências de teste manual (localhost)

1. **Comercial:** editar uma empresa → escolher "Empresa principal (dono)" → conferir
   badge na listagem e a nota quando a empresa já é principal.
2. **Dashboard ECF:** rodar `php artisan ecf:dashboard-sync` para regenerar o cache
   COM a chave `grupos` — o seletor "Grupo" só aparece após o re-sync e só lista
   grupos cujas empresas têm `cust_id` e dados no ECF Drive.

## Nota de processo

Commits e atualização de STATE.md foram intencionalmente PULADOS (decisão do usuário).
Diff inteiro fica no working tree para revisão.
