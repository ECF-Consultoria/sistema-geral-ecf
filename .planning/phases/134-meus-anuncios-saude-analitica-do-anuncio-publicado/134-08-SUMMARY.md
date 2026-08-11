---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 08
status: complete
completed: 2026-08-11
tasks_total: 3
tasks_done: 3
requirements: [D-03, D-08, D-09, D-10, D-11, D-12, D-13, D-18, D-21, D-22]
---

# Phase 134 Plan 08: 4ª aba "Meus Anúncios" + tela da sub-aba Publicados — Summary

**A tela existe e é a porta de entrada do módulo. Triagem clicável no topo, tabela ordenada por gravidade, três medidas de saúde exibidas sem conversão entre escalas, e um gate estrutural que trava o contrato de design.**

> **Nota de execução:** o agente executor deste plano foi interrompido no meio da Task 3 por limite de sessão, depois de já ter provado os dois gates por mutação. O fechamento (rodar o gate, `npm run build`, commit da Task 3 e este SUMMARY) foi feito pelo orquestrador. Nenhum trabalho foi perdido nem refeito.

## Commits

| Hash | Task | O que entrega |
|---|---|---|
| `26b7bf24` | 1 (ajuste) | Controller expõe `health_ml` e `performance_score` **sem conversão** entre escalas (D-21) |
| `f12c0d01` | 1 | 4ª aba em `ModoAnuncioTabs.jsx` + shell de `MeusAnuncios.jsx` (D-13) |
| `6989670b` | 2 | Triagem acionável + tabela com os selos de honestidade |
| `1461ba6c` | 3 | Gate estrutural do contrato de design (11 testes `node --test`) |

## O que foi construído

- **`resources/js/Pages/Mlb/MeusAnuncios.jsx`** (679 linhas) — página Inertia da aba. Triagem no topo com chips clicáveis que filtram a lista, barra utilitária (busca + select de status + "Atualizar agora"), banner de defasagem e tabela ordenada por gravidade.
- **`resources/js/Pages/Mlb/ModoAnuncioTabs.jsx`** — 4ª aba adicionada **na primeira posição**; mudança aditiva, a mecânica de troca de rota já existia e é compartilhada com o wizard e a grade.
- **`app/Http/Controllers/MlbAnuncioController.php`** — props de saúde ajustadas para o veredicto do D-21.
- **`tests/js/estrutura-meus-anuncios.test.js`** — 11 testes de gate estrutural.

## As três escalas de saúde, e por que a tela não as mistura

O veredicto do D-21 (sondagem do plano 134-01) trouxe duas medidas do próprio ML, além da nota ECF. Elas convivem na mesma linha e **a tela nunca converte uma na outra**:

| Medida | Escala | Origem | Quando falta, a tela diz |
|---|---|---|---|
| `health_ml` | 0.00–1.00 | multiget (grátis) | **"não se aplica"** — o ML não pontua anúncio de catálogo nem encerrado |
| `performance_score` | 0–100 | endpoint caro, rotativo | **"não avaliado"** — ainda não coberto pela rotação do D-23 |
| `nota_ecf` | 0–86 | derivada, `AnuncioSaudeService` | exibida como **"X de 86"**, nunca renormalizada |

Distinguir **"não se aplica"** de **"não avaliado"** não é preciosismo: são causas diferentes, e um selo único faria a tela mentir sobre por que o número está faltando. Os helpers `saudeMlNaoSeAplica()` / `saudeMlNaoAvaliada()` do model resolvem isso no backend; a tela só reflete.

## Gates provados por mutação (antes da interrupção)

O executor provou que os dois gates críticos de fato falham quando violados, e reverteu:
- Gate de vocabulário visual: introduzir `font-medium` derruba o teste.
- Gate do D-11: introduzir um verbo de escrita derruba o teste.

## Verificação

- `node --test tests/js/estrutura-meus-anuncios.test.js` → **11/11 verdes**
- `npm run build` → limpo, `MeusAnuncios-ZWGdSX4V.js` no manifest
- `php artisan test --filter=Phase134` → verde (52 testes na última execução completa)

## Checkpoint visual — o que conferir na tela

Este plano é `autonomous: false`. O usuário autorizou auto-aprovar checkpoints de UI nesta fase (parada obrigatória só antes do deploy), então a execução seguiu. O que um revisor humano deve olhar quando abrir a tela:

1. `/mlb/anuncios` → escolher empresa → a aba **Meus Anúncios** abre primeiro, com as 4 abas na ordem certa.
2. O topo mostra "N anúncios precisam de você" com os chips por motivo. Clicar num chip filtra a lista abaixo.
3. Sob o filtro padrão (**Acionáveis**), anúncios pausados aparecem — o chip "Pausado" tem contagem real, não zero.
4. Coleta velha acende o banner de defasagem com o motivo; a tela **nunca** fica em branco.
5. A nota aparece como "X de **86**" — nunca como porcentagem nem sobre 100.
6. Anúncio de catálogo mostra "não se aplica" na saúde do ML, e **não** um número inventado.
7. **Nenhum** botão de pausar, editar ou mover anúncio existe — nem desabilitado (D-11).

## Deviations

- **Ajuste de props do controller (`26b7bf24`) não estava no plano.** Foi necessário porque o plano foi escrito antes do veredicto do D-21, quando a tela teria só uma medida de saúde. Aditivo, coberto por teste.
- **Filtro default `acionaveis`** (ativos + pausados) em vez de `ativos`: decisão do usuário durante a execução, emenda ao D-03 registrada no `134-CONTEXT.md` e travada por teste no plano 134-07.

## Next Phase Readiness

Pronto para o plano **134-09** (sub-aba Rascunhos + retirada do bloco do aside do wizard). O gate estrutural `tests/js/estrutura-meus-anuncios.test.js` foi escrito com o helper `travaVocabularioVisual(caminho)` para que o 134-09 e o 134-10 o estendam aos seus arquivos novos, em vez de duplicar as regras.
