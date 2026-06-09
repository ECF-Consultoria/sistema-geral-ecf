---
quick_id: 260609-gr4
slug: users-form-errors-source
type: execute
mode: quick
status: complete
files_modified:
  - resources/js/Pages/Users/Index.jsx
  - .planning/quick/260609-gr4-users-form-errors-source/260609-gr4-SUMMARY.md
commits:
  - hash: f616b10
    message: "fix(users): corrige fonte de errors para exibir mensagens de validacao no form"
audit:
  intersecao_total: 13
  bug_confirmado: 1
  falso_positivo: 12
  ambiguo: 0
---

# Quick Task 260609-gr4 — Fix users form errors source

## Resumo executivo

Bug em `resources/js/Pages/Users/Index.jsx` corrigido: o componente lia `errors` do `useForm()` enquanto o submit usava `router.put/post` (router global do Inertia), resultando em `errors` permanentemente `{}` no escopo do componente — `FormErrorBanner` nunca renderizava, mensagens abaixo dos inputs nunca apareciam, modal travava "sem feedback". Fix trivial de 2 linhas: trocar a fonte de `errors` para `usePage().props.errors` (shared prop do Inertia).

Audit estruturado em `resources/js/Pages/` identificou 13 arquivos na interseção dos 2 conjuntos (A: destructura `errors` de `useForm`; B: usa `router.post/put/patch/delete`). Após inspeção manual de cada um, **apenas 1 arquivo apresenta o mesmo antipadrão** (`Mlb/Treinamentos.jsx`) — os outros 12 são falso positivo (o `router.*` é para ações isoladas — delete/restore/disparo de Job — e o form de validação usa `form.post()`/`form.put()`/`form.patch()` do `useForm` corretamente).

## Mudanças aplicadas

Arquivo: `resources/js/Pages/Users/Index.jsx`

1. **Linha 9 (import):** adicionado `usePage` ao import do `@inertiajs/react`.
   ```jsx
   import { useForm, router, usePage } from '@inertiajs/react';
   ```

2. **Linhas 39-43 (fonte do `errors`):** removido `errors` do destructuring do `useForm` e lido de `usePage().props`, com comentário em pt-BR explicando a escolha:
   ```jsx
   const { data, setData, processing, reset } = useForm(initialForm());
   // errors vem da shared prop do Inertia (não do useForm) porque o submit
   // abaixo usa router.* (global). O errors do useForm local só populava
   // quando o próprio form submete via form.post/put.
   const { errors } = usePage().props;
   ```

**Não foram modificados:** `UserController.php`, `FormErrorBanner.jsx`, nem o submit `router.put/post` (linhas 117-118 — funcionavam corretamente, apenas a fonte de leitura dos erros estava errada).

## Causa raiz

`useForm.errors` do `@inertiajs/react` só é populado quando o submit acontece via os métodos do PRÓPRIO hook (`form.post()`, `form.put()`, `form.patch()`, `form.delete()`, `form.submit()`). Como o componente `Users/Index.jsx` faz o submit via `router.put()`/`router.post()` (router global), o hook `useForm` não observa a resposta e o `errors` local fica permanentemente `{}`. Os erros, no entanto, CHEGAM ao frontend — apenas pelo outro canal: a shared prop `usePage().props.errors`, populada automaticamente pelo `parent::share()` em `HandleInertiaRequests` após qualquer redirect 302 com `withErrors()` ou `ValidationException`. O fix é trocar a fonte de leitura para essa shared prop, sem mexer no submit.

## Audit do mesmo antipadrão

**Heurística:** interseção dos dois greps em `resources/js/Pages/`:
- **Conjunto A** (24 arquivos): páginas que destructuram `errors` a partir de `useForm()`.
- **Conjunto B** (29 arquivos): páginas que usam `router.post/put/patch/delete`.
- **Interseção A ∩ B** (13 arquivos, excluindo `Users/Index.jsx` já corrigido e `Auth/*` excluído por convenção): tabela abaixo.

| Arquivo                                       | A (useForm.errors) | B (router.*) | Lê errors no JSX? | Submit do form usa router.*? | Classificação    | Fix sugerido / Nota                                                                                                                            |
| --------------------------------------------- | ------------------ | ------------ | ----------------- | ---------------------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `resources/js/Pages/Mlb/Treinamentos.jsx`     | sim                | sim          | sim (l.225, 245)  | **sim** (l.40, 42)           | **BUG CONFIRMADO** | Mesma troca de 2 linhas: `import { ..., usePage }` + `const { errors } = usePage().props` no lugar de `errors` do `useForm`. configForm da linha 22 usa `configForm.post()` — não afetado. |
| `resources/js/Pages/Sugadores/Index.jsx`      | sim                | sim          | sim (l.518)       | não — usa `patch(...)` do useForm (l.484) | FALSO POSITIVO   | `router.post` (l.733, 763) é só para disparo de Job `analyze-all`/`analyze-company` — sem form/validação.                                       |
| `resources/js/Pages/Sugadores/Config.jsx`     | sim                | sim          | sim (l.226+)      | não — usa `put(...)` do useForm (l.152)   | FALSO POSITIVO   | `router.post` (l.158) é só para disparo de Job `analyze-company`.                                                                              |
| `resources/js/Pages/Servicos/Index.jsx`       | sim                | sim          | sim (l.200, 214, 236) | não — usa `post/put` do useForm (l.69, 71) | FALSO POSITIVO   | `router.put` (l.77) é toggle inline ativo (sem leitura de errors); `router.delete` (l.88) é destroy.                                            |
| `resources/js/Pages/Profile/Edit.jsx`         | sim                | sim          | sim (l.31, 36, 69, 74) | não — usa `patch/put` do useForm (l.17, 56) | FALSO POSITIVO   | `router.delete` (l.92) é disconnect Google Calendar — sem form.                                                                                |
| `resources/js/Pages/Mlb/Publicacoes.jsx`      | sim                | sim          | sim (l.93+)       | não — usa `post/patch` do useForm (l.68, 127) | FALSO POSITIVO   | Múltiplos modais cada um com seu `useForm`; `router.patch`/`router.delete` (l.186, 435, 891, 895) são ações isoladas sem leitura de errors.    |
| `resources/js/Pages/Mlb/Metas.jsx`            | sim                | sim          | sim (l.88, 102, 119) | não — usa `post(...)` do useForm (l.29)    | FALSO POSITIVO   | `router.delete` (l.17) é remoção de meta — sem feedback de validação.                                                                          |
| `resources/js/Pages/Mlb/Empresas.jsx`         | sim                | sim          | sim (l.120+, 230+) | não — usa `post/put` do useForm (l.80, 211, 213) | FALSO POSITIVO   | 3 useForms — todos submetem via `form.*`; `router.patch`/`router.delete` (l.186, 435, 509, 891, 895) são ações isoladas.                       |
| `resources/js/Pages/Lideranca/Setor.jsx`      | sim                | sim          | sim (l.216)       | não — usa `post/put` do useForm (l.190, 192) | FALSO POSITIVO   | `router.delete` (l.198) é destroy de meta — sem leitura de errors.                                                                            |
| `resources/js/Pages/Companies/Index.jsx`      | sim                | sim          | sim (l.404)       | não — usa `post/put` do useForm (l.187, 189) | FALSO POSITIVO   | `router.delete` (l.195) é destroy de empresa — sem leitura de errors.                                                                          |
| `resources/js/Pages/Comercial/Empresas.jsx`   | sim                | sim          | sim (l.143, 155, 167) | não — usa `put(...)` do useForm (l.104)   | FALSO POSITIVO   | `router.delete` (l.112, 314) são destroys (empresa + contrato).                                                                                |
| `resources/js/Pages/Admin/Setores/Show.jsx`   | sim                | sim          | sim (l.252, 256, 260, 374, 381, 457, 519, 523) | não — múltiplos useForms, todos com `post/put` do useForm (l.247, 279, 367, 449, 504) | FALSO POSITIVO | `router.put` (l.110) é toggle inline de ativo; `router.delete` (l.212, 343, 427, 512) são destroys de cargo/membro/líder/setor — sem leitura de errors. |
| `resources/js/Pages/Admin/Empresas.jsx`       | sim                | sim          | sim (l.200, 215)  | não — usa `patch(...)` do useForm (l.157) | FALSO POSITIVO   | `router.delete` (l.362) é destroy de contrato — sem leitura de errors.                                                                          |

**Arquivos ignorados por convenção (mesmo aparecendo no Conjunto A):** `resources/js/Pages/Auth/Login.jsx`, `Register.jsx`, `ResetPassword.jsx`, `ConfirmPassword.jsx`, `ForgotPassword.jsx` — todos seguem o padrão Breeze (`form.post()`/`form.put()`) e não estão no Conjunto B com mistura suspeita.

**Arquivos do Conjunto A que NÃO aparecem no Conjunto B (descartados — sem mistura):** `Sugadores/Show.jsx`, `Nps/Respond.jsx`, `Nps/Index.jsx`, `Notificacoes/Nova.jsx`, `Comercial/NovaEmpresa.jsx`, `Admin/Setores/Index.jsx`.

## Próximos passos (não escopo desta task)

- [ ] Criar 1 quick task agrupando o fix do BUG CONFIRMADO restante:
  - **Sugestão:** `/gsd-quick` com slug `mlb-treinamentos-errors-source` (1 arquivo, mesma troca de 2 linhas do Users/Index.jsx).
  - Critério de aceite: ao tentar criar treinamento com `url_video` em formato inválido, o erro do backend renderiza abaixo do input.
- [ ] Em revisão futura, considerar refatorar todos os submits "mistos" para usar consistentemente `form.*` (do `useForm`) em vez de `router.*` — isso elimina a classe inteira de bugs sem precisar de `usePage().props.errors`. Fora de escopo aqui (mudança comportamental maior).

## Validação

### Tarefa 1 — greps automatizados (todos verdes)

```bash
# 1) import inclui usePage:
grep -nE "import \{ useForm, router, usePage \} from '@inertiajs/react'" resources/js/Pages/Users/Index.jsx
# → 9:import { useForm, router, usePage } from '@inertiajs/react';

# 2) useForm NÃO destructura errors:
grep -nE "const \{ data, setData, processing, reset \} = useForm\(initialForm\(\)\);" resources/js/Pages/Users/Index.jsx
# → 39:    const { data, setData, processing, reset } = useForm(initialForm());

# 3) errors lido da shared prop:
grep -nE "const \{ errors \} = usePage\(\)\.props;" resources/js/Pages/Users/Index.jsx
# → 43:    const { errors } = usePage().props;

# 4) Submit via router intacto:
grep -nE "router\.(put|post)\(route\('users\.(update|store)'" resources/js/Pages/Users/Index.jsx
# → 117:        if (editing) router.put(route('users.update', editing.id), payload, opts);
# → 118:        else router.post(route('users.store'), payload, opts);
```

### Tarefa 2 — escopo restrito

- Apenas `resources/js/Pages/Users/Index.jsx` modificado (Tarefa 1) + este SUMMARY.md criado.
- Nenhum outro `.jsx`, nenhum `.php`, nenhum `FormErrorBanner.jsx` tocado.
- ROADMAP.md / STATE.md / MILESTONES.md não alterados.

### Smoke humano (a ser rodado pelo usuário após `npm run build`)

- [ ] `/users` → "Novo Usuário" → preencher Nome + E-mail + password "abc12345" SEM Confirmar Senha → Criar.
- [ ] Modal NÃO fecha.
- [ ] `FormErrorBanner` renderiza no topo do modal com "Senha: A senha e a confirmação não coincidem."
- [ ] `<p class="text-destructive text-xs">` aparece abaixo do input de senha com o mesmo texto.
- [ ] Console do browser sem novos erros JS.

## Self-Check: PASSED

- [x] `resources/js/Pages/Users/Index.jsx` modificado (commit `f616b10`).
- [x] 4 greps de verificação da Tarefa 1 dão match exato.
- [x] `.planning/quick/260609-gr4-users-form-errors-source/260609-gr4-SUMMARY.md` criado com as 6 seções obrigatórias (Resumo executivo, Mudanças aplicadas, Causa raiz, Audit do mesmo antipadrão, Próximos passos, Validação).
- [x] Tabela de audit tem 13 linhas (1 BUG + 12 FALSO POSITIVO).
- [x] Nenhum outro `.jsx` ou `.php` modificado.
