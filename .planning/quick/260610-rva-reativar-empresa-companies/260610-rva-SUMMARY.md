---
quick_id: 260610-rva
slug: reativar-empresa-companies
date: 2026-06-10
status: complete
---

# Summary 260610-rva: Reativar empresa inativa em /companies

## Contexto
Excluir uma empresa no Comercial (`ComercialController::destroy`) é um soft-delete
(`active = false`) — preserva registros relacionados. A empresa continua visível em
`/companies` com status "Inativa", mas não havia como reativá-la pela UI.

## Mudanças
- `routes/web.php` — nova rota admin `POST /companies/{company}/ativar`
  (`companies.ativar`), dentro do grupo `role:admin`.
- `app/Http/Controllers/CompanyController.php` — método `ativar()`: seta
  `active = true` + log de atividade no canal `comercial` (espelha o destroy do
  Comercial). Retorna `back()->with('success', ...)`.
- `resources/js/Pages/Companies/Index.jsx` — handler `ativar(c)` (router.post,
  preserveScroll) + na coluna Ações: quando `c.active === false` mostra botão
  verde "Reativar" (ícone RotateCcw) no lugar da lixeira. Empresa ativa mantém a
  lixeira. (Bônus: evita hard-delete acidental — `CompanyController::destroy` faz
  `->delete()` físico — numa empresa já inativa.)

## Verificação
- `php -l` CompanyController → sem erros.
- `route:list --name=companies.ativar` → rota registrada.
- `npm run build` → ✓.

## Pendência
Não deployado (outro dev em paralelo na milestone v10.0). Aguardando autorização
para deploy agrupado.
