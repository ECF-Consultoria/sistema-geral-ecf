---
quick_id: 260611-pbm
slug: pendencias-bulk
date: 2026-06-11
status: complete
---

# Summary 260611-pbm: Melhorias na aba Pendências (/companies)

Continuação do 260611-cgp. Sem mudança de schema (só código).

## Entregas
1. **Filtro por tag**: os 4 cards de pendência viram botões; clicar (ex: "Sem grant
   ativo") filtra a tabela para mostrar só empresas com aquela pendência. Card ativo
   ganha ring; link "limpar filtro".
2. **Excluir por linha**: ação de lixeira (hard-delete via `companies.destroy`) ao
   lado do "Resolver".
3. **Seleção + ações em massa**: checkbox por linha + "selecionar todas" no header.
   Barra de ações (aparece com seleção): **Excluir selecionadas**, **Atribuir
   Analista**, **Atribuir Estrategista**.

## Backend
- `companies.bulk-destroy` (POST): hard-delete em massa. Seguro — todas as FKs
  filhas de `companies` são `cascadeOnDelete`; `parent_company_id`/`mlb_empresas`
  são `nullOnDelete`.
- `companies.bulk-assign` (POST): atribui Analista (`role=consultor`) ou
  Estrategista em massa, substituindo só o papel alvo (preserva o outro) via
  `DB delete` do papel + `attach`.

## Arquivos
- `app/Http/Controllers/CompanyController.php` (bulkDestroy, bulkAssign)
- `routes/web.php` (2 rotas POST no grupo role:admin)
- `resources/js/Pages/Companies/Index.jsx` (aba Pendências reescrita)

## Verificação
- `php -l` ok; `route:list` confirma bulk-destroy/bulk-assign; `npm run build` ok.
- Smoke local do bulkAssign não rodou (sem dados locais) — lógica espelha o
  `update()` existente; verificar em prod pós-deploy.

## Pendência
Precisa **deploy** (só código, sem migrate). Aguardando confirmação (outro dev na v10.0).
