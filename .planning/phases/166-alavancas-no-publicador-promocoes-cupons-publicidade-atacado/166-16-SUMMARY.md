---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 16
subsystem: fechamento
tags: [alavancas, gate, baseline, mariadb, conferencia-visual, learnings]
requires:
  - phase: 166-01..15
    provides: Alavancas completas (servidor, escrita, telas)
provides:
  - gate final contra o baseline (coluna "Depois (166-16)" preenchida)
  - prova da migration pub_alavanca_escritas no MariaDB local
  - learnings §12 (Alavancas no Publicador)
  - conferência visual aprovada pelo usuário
  - pendência registrada da prova real na #459
affects: []
key-files:
  created:
    - .planning/todos/pending/261004-e2e-alavancas-459.md
  modified:
    - .planning/phases/166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado/166-BASELINE-TESTES.md
    - .planning/learnings/publicador-ml.md
    - resources/js/Pages/Mlb/Publicador/Alavancas.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/ItensDoConvite.jsx
    - resources/js/Components/Mlb/Alavancas/ModalConfirmacao.jsx
    - app/Services/Publicador/Alavancas/Acoes/InscreverNoConvite.php
    - app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php
    - tests/Feature/Publicador/Alavancas/ConvitesMatrizTest.php
    - tests/js/publicador-alavancas-promocoes.test.js
requirements-completed: [AL166-18, AL166-05, AL166-06, AL166-10, AL166-15]
completed: 2026-10-05
---

# Fase 166 Plano 16: fechamento das Alavancas Summary

**Gate verde contra o baseline, migration provada no MariaDB local, learnings §12 escrito, conferência visual
aprovada pelo usuário depois de 4 correções achadas nas capturas, e a prova real na #459 registrada como pendência
(depende de deploy).**

## Execução

| Task | Commit | O que entregou |
|------|--------|----------------|
| 1 | `85430e09` | gate final no `166-BASELINE-TESTES.md` + learnings §12 |
| 2 | — (nada no repo) | ambiente de conferência no scratchpad: SQLite isolado, servidor de mentira do ML em 127.0.0.1:8167, semente com duas guardas, `subir.sh`/`descer.sh`, 27 capturas |
| 2 (achados) | `1271c0b1`, `dfef3257` | 4 correções feitas pelo orquestrador antes de passar a tela ao usuário (abaixo) |
| 3 | — | checkpoint: usuário respondeu **"aprovado"** |
| 4 | `aed9eb8b` | checkpoint: **adiado** (ver abaixo) — `.planning/todos/pending/261004-e2e-alavancas-459.md` |
| — | `612b529a` | learnings §12: cache do servidor de mentira e os 4 achados |

O executor fez as Tasks 1 e 2 e parou no checkpoint da Task 3; o orquestrador corrigiu os achados, subiu o ambiente,
refez as capturas e, depois das respostas, desmontou o ambiente e fechou o plano inline.

## Gate final (Task 1)

| Grupo | Baseline | Depois (166-16) |
|---|---|---|
| `tests/Feature/Publicador` | 238 / 1473, 0 falha | 550 / 3157, 0 falha (exit 0, ~1 min 30 s) |
| `tests/Unit/Publicador` | 156 / 522, 0 falha | 243 / 815, 0 falha (exit 0) |
| `npm run test:js` | 710, 2 falhas | 903, as MESMAS 2 falhas do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`), nenhuma nova |
| Alavancas (Unit+Feature) | — | 400 / 1980, 0 falha (depois das correções do orquestrador) |

Build: `npm run build` exit 0; manifest regenerado em 05/10 09:56 com `Pages/Mlb/Publicador/Alavancas.jsx`.

**Migration no MariaDB local** (`.env` do worktree = mysql/`ecf_admin`), só com
`--path=database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php`: `migrate:status` Pending →
`migrate` DONE em 249 ms, sem 1059/1830. Conferido por `information_schema` (`artisan db:table` quebra no PHP local sem
`intl`): índices `pubale_empresa_ix`, `pubale_company_ix`, `pubale_conta_ix`, `pubale_lote_ix`, `pubale_item_ix`; FKs
`pubale_empresa_fk`, `pubale_company_fk`, `pubale_user_fk`, todas ON DELETE SET NULL; 0 linhas.

## Conferência visual (Tasks 2 e 3)

Ambiente: SQLite em arquivo + `PUBLICADOR_ML_API_BASE=http://127.0.0.1:8167` (servidor de mentira respondendo pelas
fixtures; o `requisicoes.log` recebeu as chamadas, nada foi ao ML real). Semente aborta sem sqlite (exit 2) e com o host
oficial do ML (exit 3), testado uma vez cada; o MariaDB ficou com 0 linhas em `pub_alavanca_escritas`.

**Achados nas capturas, corrigidos antes de passar ao usuário:**
1. Janela de confirmação mostrava "A loja recebe — no preço normal → — na promoção": o contrato da prévia (166-11)
   manda `recebe.normal`/`recebe.promocao` como número e `frete_conhecido` no próprio `recebe`; o modal lia
   `.voce_recebe` de cada um (`dfef3257`).
2. "Preço atual R$ 100 → R$ 85 (29,17% de desconto)": o % é sobre o `original_price` do ML (preço riscado, R$ 120) —
   o que o comprador vê e o único que faz sentido ao alterar item já em promoção. O cálculo ficou; o resumo de
   `convite.inscrever`/`convite.alterar` ganhou `preco_original` quando difere do preço atual e a janela diz "sobre o
   preço original R$ 120,00" (`dfef3257`, com teste).
3. Conta não liberada não conseguia analisar convites: a caixa de seleção (que alimenta a `TabelaAnalise`) ficava
   desligada, contra a D-03 ("ver e analisar tudo"). Agora seleciona e analisa; "Revisar e inscrever" segue desligado
   com o motivo (`1271c0b1`, com teste).
4. A faixa de conta não liberada repetia o motivo (texto fixo + `alavancas.motivo` do servidor, que diz o mesmo)
   (`1271c0b1`, com teste).

Também: o "recebe −R$ 1.900" das primeiras capturas era cache em arquivo da tarifa do servidor de mentira antigo
(learnings §12); depois de `cache:clear` a tela deu R$ 80,00 → R$ 67,10, igual na tabela e na janela.

**Resposta do usuário (Task 3): "aprovado".** Ambiente desmontado depois da resposta: os dois `php -S` parados
(8166/8167 não respondem), cache em arquivo limpo, pasta `alavancas-visual/` (SQLite, scripts e as 27 capturas)
apagada do scratchpad.

## Prova real na #459 (Task 4)

**Adiado.** O usuário aprovou a conferência visual sem pedir o deploy; a prova depende do código em produção, e o
deploy é decisão dele, fora da fase. Ficou em `.planning/todos/pending/261004-e2e-alavancas-459.md` (commit sozinho
`aed9eb8b`) com as pré-condições, o roteiro de 5 passos e as perguntas que só a conta real responde (A1, A3, A9,
Questão 1, permissão "Promoções" do app).

Nenhuma escrita em conta real, nenhum push, nenhum deploy: `git log origin/main..HEAD` mostra os commits da fase só no
worktree `C:/tmp/ecf-publicador-spec-261001` (branch `feat/publicador-ml-261001`).

## Próximo passo

1. Conferir o `.env` de PRODUÇÃO antes do deploy: `PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=459` e nada mais
   (Questão 6 do RESEARCH; sem a variável o default do código também é só a 459).
2. Deploy só com autorização explícita do usuário; migration só cria `pub_alavanca_escritas`.
3. `sudo -u www-data php artisan queue:restart` depois do deploy (lote na fila `high`).
4. Prova real na #459 pela pendência acima.

## Desvios

- As 4 correções acima foram feitas pelo orquestrador no meio do plano (entre a Task 2 e o checkpoint), cada uma com
  teste no mesmo commit; o plano previa só registrar problemas como gap closure.
- A Task 4 foi fechada como "adiado" a partir de uma resposta só "aprovado": a prova real não tinha como ter sido feita
  (não houve deploy), e "adiado" é o caminho que não toca em nada.

## Self-Check: PASSED

- `166-BASELINE-TESTES.md` com "Depois (166-16)" preenchido e a prova da migration — sim (`85430e09`).
- Learnings com `## 12.`, `EscritorAlavancas`, `consultaPorPost`, `enviado_em`, `CANDIDATE-`, `X-Version`,
  `27/10/2026`, Ad Groups e `PUBLICADOR_ML_API_BASE` — sim.
- Ambiente desmontado (8166/8167 sem resposta; pasta apagada) — sim.
- Pendência da #459 commitada sozinha — sim (`aed9eb8b`).
