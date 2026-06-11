---
quick_id: 260611-cgp
slug: melhorias-companies
date: 2026-06-11
status: complete
---

# Summary 260611-cgp: Melhorias na aba /companies

Feature solicitada pelo usuário, planejada e aprovada. 3 decisões fechadas via
AskUserQuestion: grupo = entidade nomeada (1 empresa = 1 grupo); pendências em
aba dedicada; grant via dados locais sincronizados (company_grants).

## Entregas

**1. Filtro por serviço + contagem**
- Chips no topo da aba Empresas, um por tipo de serviço, com o total de empresas
  ativas que têm contrato ativo daquele serviço (`servico_counts` calculado por
  query em `CompanyController::index`). Clicar filtra a tabela.

**2. Aba Pendências (dedicada)**
- 4 pendências calculadas por empresa ativa: `sem_responsavel`, `sem_cust_id`,
  `sem_email_colaborador`, `sem_grant_ativo` (via `withCount` de `company_grants`
  com status=active). Contadores por tipo + tabela com badges e botão "Resolver".

**3. Grupos nomeados (tipo carteira)**
- Nova tabela `company_groups` (name, color) + `companies.company_group_id` (FK
  nullOnDelete). Model `CompanyGroup`, relação `Company::grupo()`.
- `CompanyGroupController` (store/update/destroy) + `companies.set-group` (atribui/
  remove sem efeitos colaterais). Aba Grupos visual: cards por grupo (cor, nº,
  membros), criar/editar/excluir, adicionar/remover empresa. Badge colorido do
  grupo na linha da empresa.

**4. Cadastro removido de /companies**
- Botão "Nova Empresa", modal de criação e rota/método `companies.store` removidos.
  Entrada de empresa fica exclusiva por `/comercial/empresas`. Modal vira só edição
  (ganhou select de Grupo).

## Arquivos
- `database/migrations/2026_06_11_120000_create_company_groups_table.php` (novo)
- `app/Models/CompanyGroup.php` (novo)
- `app/Models/Company.php` (fillable + relação grupo)
- `app/Http/Controllers/CompanyGroupController.php` (novo)
- `app/Http/Controllers/CompanyController.php` (index, update, setGroup, remove store)
- `routes/web.php`
- `resources/js/Pages/Companies/Index.jsx` (reescrito com 3 abas)

## Verificação
- `php -l` ok nos arquivos PHP; `route:list` confirma rotas novas e ausência de
  companies.store; `npm run build` ok.
- Migration validada com `migrate --pretend` e aplicada em DB local; smoke da
  `CompanyController::index()` sem exceção; FK nullOnDelete confirmada.

## Pendência
**Precisa deploy + `php artisan migrate`** em prod (cria tabela `company_groups`).
Não deployado (outro dev na milestone v10.0) — aguardando combinar.
