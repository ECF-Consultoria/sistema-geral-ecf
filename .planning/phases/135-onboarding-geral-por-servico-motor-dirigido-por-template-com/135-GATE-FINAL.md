---
phase: 135
executado_em: "2026-08-12T18:44:54Z"
sha: 08ee1d79087403acad08e415dd5e2c709e6fb105
sha_baseline: 735b8f7da6c3dd9d0b164b4d8ebcb53f7f9318f3
---

# Fase 135 — Gate Final

> Fecha a fase provando duas coisas que nenhum plano isolado prova sozinho: que o Polos não
> regrediu (SC-02/D-02) e que os 11 critérios de sucesso têm evidência reexecutável. Ver
> `135-13-PLAN.md` para o contrato completo.

---

## Gate de Polos (SC-02/D-02)

**Veredito: APROVADO**

### Frente 1 — diff de arquivos (byte-a-byte intocado)

Comando:
```
git diff --name-only 735b8f7da6c3dd9d0b164b4d8ebcb53f7f9318f3..HEAD
```

Resultado: 144 arquivos modificados entre a baseline e o HEAD atual (`08ee1d79`). **Nenhum**
arquivo/diretório da lista de escopo intocável do bloco `<interfaces>` do `135-13-PLAN.md`
aparece nesse diff:

| Arquivo/diretório vigiado | Aparece no diff? |
|---|---|
| `app/Models/MlbImplementacao.php` | Não |
| `app/Http/Controllers/MlbImplementacaoController.php` | Não |
| `app/Models/MlbEmpresa.php` | Não |
| `app/Observers/MlbEmpresaObserver.php` | Não |
| `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` | Não |
| `resources/js/Pages/MlbImplementacao/` (diretório) | Não |
| `resources/js/Pages/Mlb/OnboardingFicha.jsx` | Não |
| Migrations de `mlb_implementacoes` | Não |

Comando de checagem (busca literal, sem confiar em leitura visual da lista de 144 arquivos):
```
git diff --name-only 735b8f7d..HEAD | grep -iE "mlbimplementacao|mlbempresa|MlbEmpresaObserver|OnboardingFicha|ImplementacaoPublica|mlb_implementacoes"
→ NENHUM MATCH
```

`routes/web.php` e `bootstrap/app.php` **aparecem** no diff (a fase adiciona rotas/entradas
próprias de Onboarding), mas nenhuma linha de Polos foi removida ou alterada:

```
git diff 735b8f7d..HEAD -- routes/web.php | grep -c '^-.*implementacao'
→ 0
git diff 735b8f7d..HEAD -- bootstrap/app.php | grep -c "^-.*'implementacao/\*'"
→ 0
```

### Frente 2 — contagem de falhas contra a baseline

Todos os comandos rodados filtrados, um por vez, com `C:/xampp/php/php.exe`:

| Suíte | Passed | Failed | Baseline (735b8f7d) | Regressão? |
|---|---|---|---|---|
| `PolosControllerTest` | 6 | 6 | 6 passed / 6 failed | Não — mesmas 6 falhas |
| `PolosFaturamentoSnapshotTest` | 0 | 4 | 0 passed / 4 failed | Não — mesmas 4 falhas |
| `Phase112HubspotHandoffWebhookTest` | 6 | 0 | 6 passed / 0 failed | Não |
| `Phase113HubspotDedupTest` | 14 | 0 | 14 passed / 0 failed | Não |
| `Phase37ComercialListagemTest` | 17 | 0 | 17 passed / 0 failed | Não |
| `Phase37CompaniesPerformanceFilterTest` | 15 | 0 | 15 passed / 0 failed | Não |
| `Phase135` (suíte inteira da fase) | 162 | 0 | não existia | 0 failures |

As 6 falhas de `PolosControllerTest` batem nome por nome com a baseline: `meta por estagio`,
`status sim`, `status em progresso`, `status problema precedencia`, `status dist`, `filtro por
mes`. As 4 falhas de `PolosFaturamentoSnapshotTest` batem tipo por tipo: 2 `ArgumentCountError`
(`job persiste snapshot no sucesso`, `job nao sobrescreve snapshot no erro`) + 2 falhas de
asserção (`fallback snapshot evita r0 no mes corrente`, `cache fresco prevalece sobre
snapshot`). Causa raiz de ambas já documentada em `.planning/learnings/painel-polos-status-e-meta.md`
§2 (faturamento migrou de CSV para Adman; `SyncPolosFaturamentoJob` mudou de assinatura) —
nenhuma relação com o motor de Onboarding desta fase.

Detalhe completo, incluindo a nota sobre o worktree ter trabalho não commitado de outra sessão
durante a medição, em `135-BASELINE-TESTES.md` §"Medição depois da fase".

### Composer/npm — nenhum pacote novo (verificação de fecho, T-135-13-SC)

```
git diff 735b8f7d..HEAD -- composer.json package.json
→ (vazio)
```

**Conclusão da Frente 1 + Frente 2: o onboarding de Polos está byte-a-byte intocado e nenhuma
suíte vigiada regrediu. Gate D-02/SC-02 = APROVADO.**

---

## Suítes de risco do Observer

O `ContratoServicoObserver` (nasce no Plano 05, commit `d7f86c25`) passa a disparar em qualquer
criação de `ContratoServico`, inclusive nos 4 call-sites que essas suítes exercitam
indiretamente. Comparação: baseline "antes" (735b8f7d, pré-Observer) → medição "depois do
Observer" (Plano 05, HEAD `d8e0bcaa`) → medição deste gate final (HEAD `08ee1d79`, ~7 planos
depois do Observer entrar em cena).

| Suíte | Antes (735b8f7d) | Depois do Observer (d8e0bcaa) | Gate final (08ee1d79) | Regressão? |
|---|---|---|---|---|
| `Phase112HubspotHandoffWebhookTest` | 6/0 | 6/0 | 6/0 | Não |
| `Phase113HubspotDedupTest` | 14/0 | 14/0 | 14/0 | Não |
| `Phase37ComercialListagemTest` | 17/0 | 17/0 | 17/0 | Não |
| `Phase37CompaniesPerformanceFilterTest` | 15/0 | 15/0 | 15/0 | Não |

(formato: `passed/failed`)

**Zero falha nova em nenhum dos 3 pontos de medição.** O único ajuste necessário por causa do
Observer foi em `OnboardingSchemaTest` — suíte da própria Fase 135, fora do escopo destas 4
suítes de risco — e já está registrado em `135-BASELINE-TESTES.md` (commit `47be2771`).
