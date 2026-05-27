---
phase: 14-consolida-o-do-modelo-de-servi-os-frente-b
plan: 07
status: completed
completed_at: 2026-05-26
commit: pending
---

# Plan 14-07 Summary

## Objetivo

Cleanup final dos 5 consumers JSX restantes apos o drop das colunas legacy de `companies`, fazendo a UI ler exclusivamente `servicos_contratados` e usar o fluxo de contratos de servico.

## Alteracoes

- `resources/js/Pages/Admin/Empresas.jsx`
  - Removeu editor inline antigo.
  - Adicionou secao "Servicos contratados" com tabela, adicionar, editar e desativar contrato.
  - Adicionou modal Add/Edit usando URLs cruas `/empresas/{id}/contratos-servico`.
  - Adicionou filtro dinamico por nome de servico presente nos contratos ativos.

- `resources/js/Pages/Comercial/Empresas.jsx`
  - Removeu formulario inline antigo de servicos.
  - Botao "Nova Empresa" navega para `/comercial/empresas/novo`, fluxo ja refatorado no Plan 14-04.
  - Editor de empresa ficou restrito a dados basicos + secao de contratos.
  - Filtro por servico deriva de `servicos_contratados`.

- `resources/js/Pages/Admin/Financeiro.jsx`
  - `ServiceBadge` nao tem mais fallback antigo.
  - Chamada passa apenas `servicos_contratados`.
  - Comentarios com referencias aos campos removidos foram limpos.

- `resources/js/Pages/Mlb/Empresas.jsx`
  - Secao de pendentes renderiza nomes via `p.servicos_contratados`.

- `resources/js/Pages/Companies/Index.jsx`
  - Secao de pendentes renderiza nomes via `p.servicos_contratados`.

- `app/Http/Controllers/ComercialController.php`
  - Payload de `Comercial/Empresas` agora envia contratos ativos com shape completo (`id`, `servico_id`, `servico_nome`, valor, datas, tipo), necessario para editar/desativar contratos nessa tela.

## Verificacao

- `rg -n "service_type|contract_type|contract_start|contract_end|additional_service|additional_service_price" resources/js/Pages app/Http/Controllers/AdminController.php app/Http/Controllers/ComercialController.php`
  - 0 matches.

- `C:\xampp\php\php.exe -l app\Http\Controllers\AdminController.php`
  - OK.

- `C:\xampp\php\php.exe -l app\Http\Controllers\ComercialController.php`
  - OK.

- `npm.cmd run build`
  - OK, Vite build completo sem erros.

- `C:\xampp\php\php.exe artisan test --filter='Phase14FechamentoUiTest|Phase14BladeRefactorTest|Phase14MlbControllerFiltroTest'`
  - 9 passed, 101 assertions.

- `C:\xampp\php\php.exe artisan phase14:verificar-cobranca --abort-on-divergence`
  - `[Phase14] Verificando cobrança em 0 empresa(s)...`
  - `[Phase14] Todas as 0 empresas conferem (0 divergências).`

## Checkpoint humano

O smoke visual fim-a-fim das 5 telas nao foi executado nesta sessao. O cleanup foi aprovado por gates automatizados (grep, build, lint PHP, regressao focada e verificador de cobranca). Debito registrado em `deferred-items.md`.

## Resultado

Phase 14 funcionalmente completa: modelo unificado de servicos em `contratos_servico`, sem referencias funcionais aos campos removidos nos consumers finais. SVC-01..07 cobertos pelos plans 14-01 a 14-07.
