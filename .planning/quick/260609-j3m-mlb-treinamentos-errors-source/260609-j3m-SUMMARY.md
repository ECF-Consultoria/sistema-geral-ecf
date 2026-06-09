---
quick_id: 260609-j3m
slug: mlb-treinamentos-errors-source
type: summary
mode: quick
created: 2026-06-09
status: complete
commits:
  - ad63983
files_modified:
  - resources/js/Pages/Mlb/Treinamentos.jsx
derived_from: 260609-gr4
---

# Quick Task 260609-j3m — SUMMARY

## Resumo do fix

Corrigida a fonte do `errors` no form de treinamento em
`resources/js/Pages/Mlb/Treinamentos.jsx`. O bug era o mesmo padrão já corrigido
no quick `260609-gr4` (`resources/js/Pages/Users/Index.jsx`), identificado
durante o audit estruturado daquela task como o único outro arquivo com o
antipadrão confirmado (13 triados na interseção, 12 falso positivo, 1 bug =
este).

**Antipadrão:** `errors` destructurado de `useForm(...)`, mas o submit é feito
via `router.put` / `router.post` globais. `useForm.errors` só popula quando o
próprio form submete via `form.post/put/patch/delete`. Como o submit usa o
`router` global, os errors da resposta 302 do backend vão para
`usePage().props.errors` (shared prop do Inertia) — mas o componente lia de
`useForm.errors` que sempre fica `{}`. Resultado: mensagens de validação de
`titulo` e `url_video` nunca renderizavam, modal ficava preso sem feedback.

**Fix aplicado (2 linhas de mudança real + 3 linhas de comentário):**

1. `import { router, useForm, usePage } from '@inertiajs/react';`
   (adicionado `usePage` ao import)
2. `const { data, setData, processing, reset } = useForm({...})`
   (removido `errors` do destructuring)
3. `const { errors } = usePage().props;`
   (nova linha lendo `errors` da shared prop, com comentário pt-BR explicando
   por que a troca foi necessária)

**Intocado conforme constraint:**

- `configForm` (segundo form da página, linha 26) — continua usando
  `configForm.post(route('mlb.config'), ...)` próprio, então
  `configForm.errors` interno funcionaria normalmente se fosse lido na render.
- Backend (nenhuma rota / controller alterado).
- Nenhum outro arquivo da pasta `Pages/Mlb/`.

## Verifies executados

### Verify 1 — `usePage` foi adicionado ao import

```
$ grep -n "usePage" resources/js/Pages/Mlb/Treinamentos.jsx
8:import { router, useForm, usePage } from '@inertiajs/react';
24:    const { errors } = usePage().props;
```

OK — `usePage` aparece no import (linha 8) e é usado para extrair `errors`
(linha 24).

### Verify 2 — `errors` NÃO está mais no destructuring do `useForm` de treinamento

```
$ grep -n "useForm({" resources/js/Pages/Mlb/Treinamentos.jsx -A 3
18:    const { data, setData, processing, reset } = useForm({
19-        titulo: '', descricao: '', url_video: '', ordem: 0, ativo: true,
20-    });
21-    // errors vem da shared prop do Inertia (não do useForm) porque o submit
--
26:    const configForm = useForm({ link_acesso: linkAcesso ?? '' });
27-
28-    const openCreate = () => {
29-        reset();
```

OK — `useForm` de treinamento (linha 18) destructura apenas `data, setData,
processing, reset`. `errors` não aparece mais ali. `configForm` (linha 26)
permanece exatamente igual.

### Verify 3 — `configForm` NÃO foi tocado

```
$ grep -n "configForm" resources/js/Pages/Mlb/Treinamentos.jsx
26:    const configForm = useForm({ link_acesso: linkAcesso ?? '' });
52:        configForm.post(route('mlb.config'), { onSuccess: () => setEditingConfig(false) });
121:                                    value={configForm.data.link_acesso}
122:                                    onChange={e => configForm.setData('link_acesso', e.target.value)}
129:                                    <Button type="submit" size="sm" disabled={configForm.processing}>
131:                                        {configForm.processing ? 'Salvando...' : 'Salvar'}
```

OK — 6 ocorrências de `configForm` (declaração, submit `.post()`, leitura de
`.data.link_acesso`, `.setData`, `.processing` x2) idênticas ao estado
pré-task. Nada alterado no segundo form.

## Deviations from Plan

None — plan executado exatamente como escrito. Apenas as 2 mudanças
especificadas (import + fonte do `errors`), comentário pt-BR de 3 linhas
incluído conforme diff do plano.

## Self-Check

- [x] `resources/js/Pages/Mlb/Treinamentos.jsx` modificado e commitado (`ad63983`)
- [x] Verify 1 (usePage import) — PASS
- [x] Verify 2 (errors fora do useForm destructuring) — PASS
- [x] Verify 3 (configForm intocado) — PASS
- [x] Commit em pt-BR, mensagem prefixada `fix(mlb):`
- [x] Nenhum arquivo backend modificado
- [x] Nenhum outro `.jsx` modificado
- [x] PLAN.md / SUMMARY.md / STATE.md NÃO commitados (delegado ao docs commit final do orquestrador)

## Self-Check: PASSED
