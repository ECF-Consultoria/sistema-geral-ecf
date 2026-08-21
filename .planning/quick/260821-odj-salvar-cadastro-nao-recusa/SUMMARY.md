---
quick_id: 260821-odj
slug: salvar-cadastro-nao-recusa
date: 2026-08-21
status: complete
---

# O salvar do cadastro para de recusar, e a lista de pendências diz O QUE falta

## Resumo

O incidente em produção (empresa 430 Mons Bike, `nome_contato = "Vitor"`) tinha causa raiz
confirmada: `ContratoAdminController::atualizarCadastro()` validava `cnpj` e `nome_contato` com
Rules semânticas (`CnpjValido`, `NomeCompletoValido`) que reprovavam a **requisição inteira**
quando um desses dois campos tinha problema — perdendo razão social/endereço digitados junto,
mesmo sem problema nenhum.

**Tarefa 1** removeu `new CnpjValido()` e `new NomeCompletoValido()` das regras de validação do
salvar, mantendo `nullable`/`string`/`max:*` e o `'email'` de `email_cliente`. O gate da
**geração** (`ContratoDadosMinimosService::faltantes()`) não foi tocado — continua recusando
CNPJ com dígito trocado e nome de uma palavra só, com `motivo: 'formato'`, antes de qualquer
chamada à Clicksign.

**Tarefa 2** ajustou `ContratoDetalhe.jsx`: a lista de pendências agora distingue `ausente` de
`formato` (chave que `faltantes()` já devolvia e a prop `faltantes` já repassava crua — não foi
preciso mexer no controller), com copy sem jargão por campo (`nome_contato`: "precisa de nome
e sobrenome"; `cnpj`: "o número não confere").

## Tarefas executadas

1. **`app/Http/Controllers/ContratoAdminController.php`** — removidas as duas Rules semânticas
   de `atualizarCadastro()`; comentários pt-BR atualizados explicando por que saíram e por que
   é seguro (redundantes com o gate da geração). Imports não usados (`App\Rules\CnpjValido`,
   `App\Rules\NomeCompletoValido`) removidos. Classes de Rule e helpers
   `App\Support\Cnpj`/`App\Support\NomeCompleto` **não foram tocados** — seguem em uso pelo
   gate da geração.
   - Commit: `939de075`

2. **`tests/Feature/Phase131/ContratoAdminDetalheTest.php`** — os dois testes que fixavam o
   comportamento antigo (`assertSessionHasErrors` + `assertNull`) foram substituídos por
   `test_atualizar_cadastro_com_cnpj_de_digito_trocado_grava_os_demais_campos` e
   `test_atualizar_cadastro_com_nome_contato_de_uma_palavra_grava_os_demais_campos`: enviam
   CNPJ inválido / nome de uma palavra + razão social + endereço completo, confirmam por
   **reconsulta ao banco** que tudo foi gravado, e confirmam que `faltantes()` continua
   bloqueando a geração desses mesmos campos com `motivo: 'formato'`.
   - Commit: `939de075` (mesmo commit da Tarefa 1 — teste nasce junto do código que prova)

3. **`resources/js/Pages/Admin/ContratoDetalhe.jsx`** — lista de pendências (~linha 294-303)
   agora usa `item.motivo` para decidir o texto: `ausente` mostra só o rótulo (como já era);
   `formato` acrescenta o que exatamente está errado, via novo lookup `FORMATO_INVALIDO_TEXTO`
   (`nome_contato`/`cnpj`). Nenhuma mudança no controller foi necessária — a prop `faltantes`
   já repassava o array cru do service (confirmado por
   `test_show_de_empresa_incompleta_devolve_200_componente_e_faltantes_batendo_com_o_service`,
   que faz `assertSame()` com o retorno bruto de `faltantes()`), e não existe teste de
   whitelist de props que exclua a chave `motivo` (a chave já era exposta e não carrega PII).
   - Commit: `aceb8dac`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `npm run build` falhava por dependência ausente em `node_modules`**
- **Found during:** verificação final (`npm run build`, exigida pelo plano)
- **Issue:** `Kanban.jsx` (adicionado por commit alheio já mergeado na árvore, `6c710dee feat(portal-cliente)`) importa `@dnd-kit/core`, já declarado em `package.json`/`package-lock.json` com resolução pinada, mas ausente em `node_modules` local.
- **Fix:** `npm install` (sem argumento — sincroniza `node_modules` com o lockfile já existente; não instala pacote novo nem não-verificado, por isso fora da exclusão de Rule 3 sobre instalação de pacotes).
- **Files modified:** nenhum arquivo versionado ficou fora do estado anterior — `npm install` alterou `package-lock.json` (campo `name` normalizado), revertido com `git checkout -- package-lock.json` para manter o diff desta quick escopado.
- **Commit:** nenhum (não fazia parte do escopo, e o lockfile foi revertido ao original)

Nenhum outro desvio. `email_cliente` continua exigindo `'email'` (formato, não semântica); a
guarda de IDOR e a ordem "valida pertencimento de TODOS antes de gravar qualquer um" não foram
tocadas.

## Testes

- `C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131|Phase132|Phase133"`
  → **372 testes, 1276 assertions, 0 failures** (verde). 450 PHPUnit Deprecations pré-existentes
  (não relacionadas a esta mudança).
- `npm run build` → sucesso (`✓ built in 23.45s`), após o `npm install` documentado acima.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. A mudança reduz superfície de validação no salvar (campo antes recusado agora é
aceito), mas o gate de segurança relevante (impedir dado inválido de chegar à Clicksign)
permanece intacto em `ContratoDadosMinimosService::faltantes()`, sem alteração.

## Self-Check: PASSED

- `app/Http/Controllers/ContratoAdminController.php` — FOUND, `CnpjValido`/`NomeCompletoValido`
  ausentes das regras de `atualizarCadastro()` (confirmado por leitura).
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — FOUND, `FORMATO_INVALIDO_TEXTO` presente e
  usado no render da lista de pendências.
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — FOUND, os dois testes novos presentes
  e verdes na última rodada da suíte.
- Commit `939de075` — FOUND em `git log --oneline`.
- Commit `aceb8dac` — FOUND em `git log --oneline`.
