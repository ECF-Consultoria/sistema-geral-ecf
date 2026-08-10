---
task_id: 260810-dv6
slug: link-whats-polos
date: 2026-08-10
status: in-progress
---

# Quick 260810-dv6 — Coluna "Link do Whats" no Painel Polos

## Pedido

> "no painel polos, cria uma coluna onde vai ter Link do Whats — lá vão colocar o link do
> whatsapp, do grupo"

## Decisão de modelagem

O campo mora em **`mlb_implementacoes.link_whatsapp`** (bloco **Acessos**), colado no
`grupo_whatsapp` que já existe (boolean "grupo criado sim/não"). Motivos:

- É o mesmo assunto do `grupo_whatsapp` — o boolean diz SE existe, o novo campo diz ONDE está.
- Reusa 100% do mecanismo de edição inline do painel (`BLOCO_DE` → `PATCH
  mlb.implementacao.bloco.acessos`), sem endpoint novo.
- Custo: empresa **sem ficha** mostra "criar ficha" na célula (idêntico ao `gmail_colaborador`).
  Medido no banco local: 269 de 284 empresas POLOS ativas têm ficha (95%) — as 15 sem ficha já
  convivem com essa limitação em todas as outras colunas de onboarding.

`grupo_whatsapp` **não** é tocado: `MetasPanel`/`EntrantesM0Panel` contam realizado por ele
(`temGrupo = e.grupo_whatsapp === true`). Mexer no tipo quebraria a aba Metas.

## Tarefas

1. **Migration** `add_link_whatsapp_to_mlb_implementacoes` — `string(255) nullable` after
   `grupo_whatsapp`; `down()` derruba a coluna.
2. **Model** `MlbImplementacao` — `link_whatsapp` no `$fillable` (sem cast: string pura).
3. **`MlbImplementacaoController::salvarBlocoAcessos`** — valida `nullable|string|max:255`.
4. **`PolosController::painel`** — expõe `'link_whatsapp' => $impl?->link_whatsapp` no row.
5. **`Painel.jsx`**:
   - `BLOCO_DE.link_whatsapp = 'acessos'`
   - coluna em `COLUNAS` (filtrável: serve p/ isolar "(Sem link)")
   - `COLS_POR_LENTE`: entra na lente **acessos** e na **geral**, logo após `grupo_whatsapp`
   - célula `EditLink` — input inline + botão que abre o grupo em nova aba
6. `npm run build`.

## Fora de escopo (consciente)

- **Ficha de Onboarding** (`OnboardingFicha.jsx`) não ganha o campo agora — o pedido é a coluna
  do painel. O dado já fica salvo no mesmo bloco, então incluir depois é 1 linha.
- **Edição em massa** (`painelBulk`) não recebe `link_whatsapp`: link é único por empresa,
  aplicar o mesmo a N empresas seria sempre errado.

## Verificação

- `php artisan migrate` local + teste do bloco Acessos (`Phase33OnboardingFichaTest`).
- Round-trip: salvar link inline no painel → recarregar → valor persiste.
