---
quick_id: 260820-my3
status: complete
completed: 2026-08-20
---

# Contrato na fila `high` + a tela para de mentir quando a montagem trava — Summary

**Resumo em uma linha:** `GerarContratoAssinaturaJob` agora entra na fila `high` (não compete
mais com sync de acervo pesado) e a tela ganhou um sub-estado próprio ("Travado") para quando
nada foi criado, em vez de continuar dizendo "Falta enviar" e mandar procurar na Clicksign.

## O que foi feito

### Tarefa 1 — Fila `high`

`app/Services/Clicksign/ContratoClicksignService.php:171` — `GerarContratoAssinaturaJob::dispatch($contrato)`
passou a levar `->onQueue('high')`, com o `->delay(now()->addSeconds($i * 5))` preservado
(bucket de 1 envelope/min da Clicksign — sem relação com a fila). Comentário no código cita o
incidente da Maderatto (id=6) como motivo.

**Teste:** `tests/Feature/Phase127/ContratoClicksignServiceTest.php` — teste novo
(`job_e_despachado_na_fila_high_preservando_o_delay_escalonado`) prova, na mesma chamada, que o
job vai para `high` (`Queue::assertPushedOn`) e que o delay escalonado por serviço continua
intacto (primeiro contrato do laço com delay menor que o segundo).

### Tarefa 2 — A tela para de mentir

`ContratoAssinatura::estaMontagemTravada()` — método novo, irmão de `estaPreparando()`: mesma
condição (`rascunho` + envelope vazio), do lado OPOSTO da mesma janela de `JANELA_PREPARANDO_MINUTOS`
(`lte` em vez de `gt`). Mutuamente exclusivo com `estaPreparando()` por construção — os dois
juntos cobrem os dois lados da janela para todo `rascunho` com envelope vazio; um `rascunho`
com envelope preenchido nunca ativa nenhum dos dois.

Prop `montagem_travada` adicionada nos três pontos de emissão de `ContratoAdminController`
(lista com contrato, lista sem contrato — sempre `false`, detalhe), mesma disciplina de
`preparando`: nunca entra no resumo de 7 contagens (D-04).

**`resources/js/lib/contratoStatus.js`** — rótulo, classe e avisos próprios:
- `MONTAGEM_TRAVADA_LABEL = 'Travado'`
- `MONTAGEM_TRAVADA_CLS` — laranja (`bg-orange-500/10 text-orange-400 border-orange-500/20`),
  mesmo tom de `expirado` (reuso de cor, não de significado — este estado não entra no mapa de
  7 chaves). Mais alarmante que o âmbar de `preparando`, mas nunca o rose de `erro` (não é uma
  falha registrada, é montagem que não terminou).
- `rotuloContratoComPreparo()`/`classeContratoComPreparo()` ganharam um terceiro parâmetro
  opcional `travado = false` — chamadas antigas de 2 argumentos continuam funcionando sem
  alteração.

**Bug real corrigido no detalhe (Rule 1):** `ContratoDetalhe.jsx` calculava
`faltaEnviarPelaClicksign = c.status === 'rascunho' && !c.preparando` — exatamente o mesmo bug
do incidente, só que nesta tela: um `rascunho` com envelope NULO fora da janela (`preparando=false`,
`montagem_travada=true`) caía nesse ramo e mostrava "já foi montado, falta enviar". Corrigido
para excluir `!c.montagem_travada` também.

**Whitelist de props** (`tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php`) atualizada
conscientemente com `montagem_travada`, motivo escrito no próprio teste — mesmo precedente de
`erro_mensagem`/`ja_tentou_antes`.

## A copy escolhida (e por quê)

| Peça | Texto | Onde |
|---|---|---|
| Badge (lista e detalhe) | `Travado` | `MONTAGEM_TRAVADA_LABEL` |
| Tooltip do badge na lista | "O contrato foi pedido, mas não ficou pronto. Não procure na Clicksign — avise o time técnico." | `MONTAGEM_TRAVADA_TITULO` |
| Faixa explicativa no detalhe | "Este contrato foi pedido, mas não ficou pronto — ainda não existe nada para encontrar na Clicksign. Avise o time técnico para verificar." | `MONTAGEM_TRAVADA_AVISO` |

Critério do plano: sem jargão ("envelope", "job", "fila", "worker" nunca aparecem), e precisa
deixar claro que **não adianta procurar na Clicksign** — foi exatamente o que faltou na tela
antiga e causou o incidente (usuário foi procurar o contrato da Maderatto na Clicksign e não
achou nada, porque nada tinha sido criado). "Travado" foi escolhido para o badge (uma palavra,
cabe na coluna estreita da lista, mesmo padrão de "Preparando") em vez de algo mais longo como
"Precisa de atenção" — a explicação completa fica no tooltip/faixa, não no badge.

## Testes

- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` — 1 teste novo (Tarefa 1).
- `tests/Feature/Phase131/ContratoAdminMontagemTravadaTest.php` — novo arquivo, 6 testes:
  os 4 cenários pedidos (rascunho+NULL dentro/fora da janela, rascunho+envelope preenchido
  independente da idade, par sem contrato), mais o invariante do resumo de 7 chaves e a
  contagem de exatamente 7 chaves em `CONTRATO_STATUS_LABELS` (asserção por leitura do arquivo
  fonte JS — o projeto não tem runner de teste JS configurado em `package.json`, mesma
  disciplina do teste 11 de `IdempotenciaContratoTest`, que também faz asserção sobre código
  fonte lido como string).
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` — whitelist de props atualizada.

**Gate:** `--filter=Phase126|Phase127|Phase130|Phase131|Phase132|Phase133` — **Tests: 444,
Assertions: 1502, verde** (rodado duas vezes, antes e depois do commit da Tarefa 2, mesmo
resultado).

`npm run build` — limpo, sem erros, `public/build/` não versionado (`.gitignore`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `ContratoDetalhe.jsx` tinha o mesmo bug do incidente, só que no detalhe**
- **Found during:** Tarefa 2, ao mapear onde `estaPreparando()`/`preparando` já eram consumidos.
- **Issue:** `faltaEnviarPelaClicksign = c.status === 'rascunho' && !c.preparando` tratava
  qualquer rascunho fora da janela de "preparando" como "envelope já montado, falta enviar" —
  inclusive quando o envelope nunca existiu (o caso exato do incidente).
- **Fix:** adicionado `&& !c.montagem_travada` à condição.
- **Files modified:** `resources/js/Pages/Admin/ContratoDetalhe.jsx`.
- **Commit:** `02dec5f7`.

Fora isso: plano executado como escrito.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum — mudança é só sub-estado de exibição derivado de dados já existentes (status +
envelope + timestamp), sem endpoint, rota, ou caminho de auth novo.

## Commits

- `700f56fc` — `fix(quick-260820-my3): despacha GerarContratoAssinaturaJob na fila high`
- `02dec5f7` — `feat(quick-260820-my3): tela para de dizer 'Falta enviar' quando nada foi criado`

## Self-Check: PASSED

- `app/Services/Clicksign/ContratoClicksignService.php` — FOUND (dispatch com `onQueue('high')`).
- `app/Models/ContratoAssinatura.php` — FOUND (`estaMontagemTravada()`).
- `app/Http/Controllers/ContratoAdminController.php` — FOUND (`montagem_travada` nos 3 pontos).
- `resources/js/lib/contratoStatus.js` — FOUND (`MONTAGEM_TRAVADA_*`).
- `resources/js/Pages/Admin/Contratos.jsx` — FOUND.
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — FOUND.
- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` — FOUND.
- `tests/Feature/Phase131/ContratoAdminMontagemTravadaTest.php` — FOUND.
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` — FOUND.
- Commit `700f56fc` — FOUND em `git log`.
- Commit `02dec5f7` — FOUND em `git log`.
