---
phase: quick-260618-jpx
plan: "01"
subsystem: onboarding
tags: [mlb, implementacao, envio-link, responsavel, rastreio]
dependency_graph:
  requires: []
  provides: [ONB-ENVIO-LINK, ONB-RESPONSAVEL]
  affects: [mlb_implementacoes, MlbImplementacao, MlbImplementacaoController, Implementacao.jsx]
tech_stack:
  added: []
  patterns: [Radix Select sentinela __sem__→null, filtro Collection pós-get, eager load relações belongsTo]
key_files:
  created:
    - database/migrations/2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php
  modified:
    - app/Models/MlbImplementacao.php
    - app/Http/Controllers/MlbImplementacaoController.php
    - routes/web.php
    - resources/js/Pages/Mlb/Implementacao.jsx
decisions:
  - Filtro falta_enviar aplicado em Collection pós-get (mesmo padrão de fora_do_prazo) — depende de statusEnvio() que usa progresso()+ultimo_acesso, não coluna SQL
  - Sentinela __sem__ → null no Select Radix de responsável (memória do projeto: nunca <SelectItem value="">)
  - statusEnvio() com precedência concluido > acessou > enviado > falta_enviar, conforme plano
  - Botões marcar/desfazer visíveis na coluna Ações da tabela e na aba "Link & Status" do ImplModal
  - Colunas "Status do envio" e "Responsável" usam hidden md/lg para não quebrar responsividade
metrics:
  duration: "~25min"
  completed: "2026-06-18"
  tasks: 3
  files: 5
---

# Quick Task 260618-jpx: Rastrear Envio do Link e Responsável no Onboarding — SUMMARY

Rastreio manual de envio do link do cliente (4 estados) + atribuição de responsável por onboarding na listagem `/implementacao`. A entrega separa "nunca enviado" de "enviado mas cliente não abriu" e dá accountability operacional via dono do processo.

## Tarefas Executadas

| Tarefa | Nome | Commit | Arquivos-chave |
|--------|------|--------|----------------|
| T1 | Backend — migração + model + controller + rotas | 5061721 | migration, MlbImplementacao.php, MlbImplementacaoController.php, routes/web.php |
| T2 | Frontend — Implementacao.jsx | fdb9ca7 | Implementacao.jsx |
| T3 | Build final + verificação ponta-a-ponta | — | (sem mudanças de código — verificação) |

## O Que Foi Entregue

### Backend (T1)

**Migração** `2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes`:
- `link_enviado_em` timestamp nullable after `ultimo_acesso`
- `link_enviado_por` unsignedBigInteger nullable + FK `users.id` nullOnDelete
- `responsavel_id` unsignedBigInteger nullable + FK `users.id` nullOnDelete

**MlbImplementacao** (model):
- `$fillable`: 3 novas colunas adicionadas
- `$casts`: `link_enviado_em` → `'datetime'`
- Relações: `responsavel()` e `linkEnviadoPor()` (ambas `belongsTo(User::class)`)
- Método `statusEnvio(): string` com precedência `concluido > acessou > enviado > falta_enviar`

**MlbImplementacaoController**:
- `index()`: eager load `['empresa','responsavel','linkEnviadoPor']`; filtro `$filtroFaltaEnviar`; props novas `status_envio`, `link_enviado_em`, `link_enviado_por`, `responsavel_id`, `responsavel_nome`; prop `usuarios` (ativos ordenados por nome); `filtros.falta_enviar`
- `marcarLinkEnviado()`: grava `link_enviado_em=now()` + `link_enviado_por=$user->id`
- `desfazerEnvio()`: seta ambos a null
- `atribuirResponsavel()`: valida `nullable|integer|exists:users,id`, atualiza `responsavel_id`
- Todas as ações chamam `checkAccess()` e registram `activity('implementacao')` com tag `[Onboarding]`

**routes/web.php**:
- `POST /mlb/implementacao/{impl}/marcar-enviado` → `mlb.implementacao.marcar-enviado`
- `POST /mlb/implementacao/{impl}/desfazer-envio` → `mlb.implementacao.desfazer-envio`
- `PATCH /mlb/implementacao/{impl}/responsavel` → `mlb.implementacao.responsavel`

### Frontend (T2)

**Implementacao.jsx**:
- `STATUS_ENVIO_LABELS` + `STATUS_ENVIO_BADGE` (4 estados com cores)
- Prop `usuarios = []` na assinatura do componente
- `aplicarFiltro`: preserva `falta_enviar` no spread de params
- Contador `faltamEnviar` no header (badge vermelho discreto quando > 0)
- Toggle "Falta enviar link" ao lado de "Fora do prazo" (mesmo padrão visual)
- "Limpar filtros" inclui `filtros?.falta_enviar`
- Coluna "Status do envio" (badge + "por/em" quando enviado)
- Coluna "Responsável" (Select Radix com sentinela `__sem__` → null)
- Botões "Marcar enviado"/"Desfazer envio" na coluna Ações da tabela
- Mesmos botões na aba "Link & Status" do `ImplModal`
- `colSpan` do empty-state: 7 → 9
- empty-state e "Limpar filtros" consideram `filtros?.falta_enviar`

## Verificações Ponta-a-Ponta (T3)

- `php artisan migrate --force`: migração aplicada sem erro (330ms)
- `php artisan route:list`: 3 rotas novas listadas (`marcar-enviado`, `desfazer-envio`, `responsavel`)
- `npm run build`: build verde (10.47s, sem warnings)
- Sanidade Radix: nenhum `<SelectItem value="">` no JSX funcional (apenas em comentário)
- Rotas referenciadas no JSX: `implementacao.marcar-enviado` (2x), `implementacao.desfazer-envio` (2x), `implementacao.responsavel` (1x)
- Nenhum deploy executado

## Desvios do Plano

Nenhum — plano executado exatamente como escrito.

## Known Stubs

Nenhum — todas as props de envio e responsável são lidas diretamente do banco. O Select de responsável exibe `usuarios` ativos do banco. Botões disparam rotas reais.

## Threat Flags

Nenhuma nova superfície de segurança introduzida. As 3 novas rotas estão dentro do grupo `middleware(['auth','verified'])` e protegidas por `checkAccess()` em cada ação.

## Self-Check: PASSED

- migration: FOUND `database/migrations/2026_06_18_000000_add_link_enviado_e_responsavel_to_mlb_implementacoes.php`
- model: FOUND `app/Models/MlbImplementacao.php` (statusEnvio + relações + fillable + cast)
- controller: FOUND `app/Http/Controllers/MlbImplementacaoController.php` (marcarLinkEnviado + desfazerEnvio + atribuirResponsavel)
- routes: FOUND `mlb.implementacao.marcar-enviado` / `mlb.implementacao.desfazer-envio` / `mlb.implementacao.responsavel`
- frontend: FOUND `resources/js/Pages/Mlb/Implementacao.jsx` (STATUS_ENVIO_BADGE + botões + Select Radix sentinela)
- commits: 5061721 FOUND, fdb9ca7 FOUND
