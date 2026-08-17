---
quick_id: 260817-d6h
slug: tira-o-email-do-colaborador-da-tela-de-c
status: completo
requirements: [ADM-01]
one-liner: "E-mail do colaborador saiu da tela de contrato (JSX + controller + validação), permanece intacto e editável em /companies"
completed: 2026-08-17
---

# Quick 260817-d6h — Tirar o e-mail do colaborador da tela de contrato: Summary

## O que foi feito

O campo `email_colaborador` deixou de aparecer na tela de detalhe do contrato
(`Admin/ContratoDetalhe.jsx`). Decisão do usuário: é dado operacional (acesso da
ECF à conta do cliente no Mercado Livre), preenchido pelo Administrativo, mas
**não é assunto de contrato** — a ADM-01 tinha juntado "quem preenche" com
"onde aparece".

O campo continua existindo na coluna `companies.email_colaborador`, continua
editável em `/companies` (`CompanyController`) e continua sinalizado lá como
pendência (badge âmbar "Sem email colaborador"). Nada foi removido do banco.

### Arquivos alterados

- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — removida a prop
  `email_colaborador_pendente`, a chave `email_colaborador` do `useForm`, o
  `<Field>`/input do e-mail do colaborador e o aviso de pendência.
- `app/Http/Controllers/ContratoAdminController.php` — removida
  `email_colaborador` da prop `company` do `show()`, a prop
  `email_colaborador_pendente`, a regra de validação `email_colaborador` em
  `atualizarCadastro()` e a chave correspondente do `->only([...])` do
  `fill()`. As duas últimas saíram juntas (T-d6h-01) — remover só uma teria
  deixado mass-assignment silencioso (chave no `->only()` sem validação) ou
  erro de validação sobre um campo que a tela não envia mais.
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — Caso 4 reescrito
  (Caso 5 e Caso 7 ajustados, ver Deviations).

## Decisões

- Nenhuma nova decisão de arquitetura — execução direta do plano.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - blocking issue] Caso 5 e Caso 7 do teste também precisaram de ajuste**

- **Encontrado em:** verificação (`artisan test --filter=Phase131`), após remover a
  regra de validação e a chave `->only()` do `email_colaborador`.
- **Problema:** o plano só mandava explicitamente reescrever o "Caso 4"
  (linhas ~185-210), mas dois outros testes do mesmo arquivo dependiam
  diretamente do comportamento removido:
  - Caso 5 (`test_atualizar_cadastro_grava_todos_os_campos_...`) enviava
    `email_colaborador` no PATCH e conferia por reconsulta ao banco que
    tinha sido gravado — deixou de ser verdade, já que o campo saiu do
    `->only()`.
  - Caso 7 (`test_atualizar_cadastro_com_email_colaborador_invalido_...`)
    esperava `assertSessionHasErrors('email_colaborador')` para um formato
    inválido — deixou de ser verdade, já que o campo saiu da regra de
    validação.
- **Fix:** Caso 5 teve a linha `email_colaborador` removida do payload e da
  asserção de reconsulta (os demais campos — CNPJ, e-mail do cliente, nome
  de contato, datas de serviço — seguem cobertos). Caso 7 foi reaproveitado
  para provar o oposto do que provava antes: um formato inválido de
  `email_colaborador` no payload agora é **ignorado silenciosamente**
  (`assertSessionDoesntHaveErrors` + reconsulta confirmando `null`), em vez
  de gerar erro de validação — o que é o comportamento correto pós-remoção
  e mantém a suíte em 72 testes (nenhum removido, nenhum criado).
- **Arquivos modificados:** `tests/Feature/Phase131/ContratoAdminDetalheTest.php`
- **Commit:** `d0564870` (mesmo commit da task, já que é consequência direta
  da mesma mudança de escopo)

Nenhum outro desvio. Escopo travado respeitado: `CompanyController.php`,
`Companies/Index.jsx`, `ComercialController.php`, `NovaEmpresa.jsx`,
`HubspotWebhookController.php` e `database/migrations/` **não** foram tocados.

## Verificação

- `C:\xampp\php\php.exe artisan test --filter=Phase131` → **72 passed (254
  assertions)**, exit code 0. (Confirma que o filtro casou de verdade — não é
  o caso de "No tests found" que sai 0 e engana.)
- `npm run build` → build 0, `Admin/ContratoDetalhe.jsx` presente no
  `public/build/manifest.json` (5 ocorrências, incluindo o chunk
  `ContratoDetalhe-BDvsx1k7.js`).
- `grep -c "email_colaborador" resources/js/Pages/Admin/ContratoDetalhe.jsx` → 0
- `grep -c "email_colaborador" app/Http/Controllers/ContratoAdminController.php` → 0
- `grep -c "email_colaborador" app/Http/Controllers/CompanyController.php` → 7 (intacto)
- `git diff --name-only` (do commit) lista só `ContratoAdminController.php`,
  `ContratoDetalhe.jsx` e `ContratoAdminDetalheTest.php` — nenhum arquivo do
  escopo travado, nenhuma migration.
- `git diff --diff-filter=D --name-only HEAD~1 HEAD` → vazio, sem exclusões
  inesperadas de arquivo.

## Commits

- `d0564870` — fix(260817-d6h): remove email do colaborador da tela de contrato

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova — a mudança é remoção de superfície (UI + validação
de contrato), não adição. Ver `<threat_model>` do plano (T-d6h-01/T-d6h-02),
ambas mitigadas conforme descrito acima.

## Self-Check: PASSED

- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — FOUND, sem `email_colaborador`
- `app/Http/Controllers/ContratoAdminController.php` — FOUND, sem `email_colaborador`
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — FOUND, atualizado
- commit `d0564870` — FOUND em `git log --oneline --all`
