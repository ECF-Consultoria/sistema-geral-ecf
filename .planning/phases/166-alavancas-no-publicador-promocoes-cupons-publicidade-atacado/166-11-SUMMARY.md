---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 11
subsystem: publicador
tags: [alavancas, escrita, previa-assinada, lote, job, trava]
requires: [166-07, 166-08, 166-09, 166-10]
provides:
  - RegistroDeAcoes (15 ações por nome)
  - PreviaAlavancasService (previa, confirmar)
  - MlbAlavancasEscritaController + rotas escritas.previa, escritas.confirmar, lotes
  - ExecutarLoteAlavancaJob (lote em fatias, fila high)
affects: [166-12, 166-13, 166-14, 166-15]
key-files:
  created:
    - app/Services/Publicador/Alavancas/RegistroDeAcoes.php
    - app/Services/Publicador/Alavancas/PreviaAlavancasService.php
    - app/Http/Controllers/MlbAlavancasEscritaController.php
    - app/Jobs/Publicador/ExecutarLoteAlavancaJob.php
    - tests/Unit/Publicador/Alavancas/RegistroDeAcoesTest.php
    - tests/Feature/Publicador/Alavancas/PreviaEConfirmarTest.php
    - tests/Feature/Publicador/Alavancas/TravaEscritaHttpTest.php
    - tests/Feature/Publicador/Alavancas/LoteJobTest.php
    - tests/Feature/Publicador/Alavancas/Concerns/ApoioEscritaHttp.php
  modified:
    - routes/mlb_anuncios.php (só acréscimo: 1 import e 3 rotas)
    - app/Services/Publicador/Alavancas/AnaliseAlavancasService.php (parâmetro opcional)
metrics:
  completed: 2026-10-05
  tasks: 3
---

# Fase 166 Plano 11: Porta HTTP da escrita (prévia assinada, confirmar e lote) Summary

Toda escrita das Alavancas passa por prévia assinada e confirmação: a prévia só lê (GET) e assina; o confirmar avalia a trava antes de tudo, confere a assinatura, queima-a (uso único) e escreve 1 produto na hora ou cria um lote (uma linha PENDENTE por produto + job na fila `high`), sempre pelo `EscritorAlavancas`.

## Commits

| Task | Mensagem | Conteúdo |
|---|---|---|
| 1 | `feat(166-11): registro das ações e prévia assinada das Alavancas` | RegistroDeAcoes, PreviaAlavancasService (previa + confirmar), RegistroDeAcoesTest |
| 2 | `feat(166-11): prévia e confirmação das escritas das Alavancas` | controller, 3 rotas, trait de apoio, PreviaEConfirmarTest, TravaEscritaHttpTest, ajuste na análise |
| 3 | `feat(166-11): lote das Alavancas por job em fatias na fila high` | ExecutarLoteAlavancaJob, LoteJobTest |

(hashes: `git log --oneline --grep "166-11"`)

## Contrato JSON

Rotas (`/mlb/anuncios/publicador/empresas/{conta}/alavancas/...`, nomes `mlb.anuncios.publicador.alavancas.*`, só `role:admin`):

| Nome | Método e caminho | Throttle |
|---|---|---|
| `escritas.previa` | POST `escritas/previa` | 60/min |
| `escritas.confirmar` | POST `escritas` | 30/min |
| `lotes` | GET `lotes/{lote}` (`whereUuid`) | 240/min |

**previa** — corpo `{acao: string<=32, itens: array 1..50}`. 200:
```
{acao, liberada: bool, motivo: ?string, regra: ?'ALAV-LIB',
 resumo: {conta: {nome, chave, nickname}, itens: [<resumo() da ação, sem 'analise'> + recebe?], avisos: [..]},
 assinatura: ?'<hmac64>.<exp>', parcial: bool, analise_limitada?: true}
```
`recebe` (só nos 20 primeiros com preço): `{normal, promocao, estimativa, depende_do_carrinho, frete_conhecido, margem, alertas, calculado, erro}`. Conta não liberada: mesmo resumo, `liberada:false`, `assinatura:null`. Erros: 409 `V-ACC-01` (sem token); 422 `ALAV-ACAO`/`ALAV-LOTE`/regra da ação com `{message, regra, contexto:{item, item_id}}` (mensagem prefixada com o MLB quando há mais de um item); 422 de validação com chaves `itens.N.<campo>`; 502 texto fixo.

**confirmar** — corpo `{acao, itens, assinatura?: string<=200}`:
- 200 `{escrita: {id, resultado, item_id, acao, alavanca, mensagem, http_status, erro_codigo, promotion_id}}` — OK, ERRO ou INCERTO (a requisição foi processada).
- 202 `{lote: uuid, total, url}` — vários itens.
- 403 `{message: AlavancasLiberadas::MOTIVO, regra: 'ALAV-LIB', escritas: [..]}` — conta fora da lista, com ou sem assinatura (RECUSADA gravada, uma por item); 403 `{message, regra: 'V-ACC-03', escrita}` — vendedor diferente.
- 409 `{regra: 'ALAV-ASSIN-USADA', message: 'Esta confirmação já foi usada.'}`; 409 `V-ACC-01` sem token.
- 422 `{regra: 'ALAV-ASSIN', message: 'A conferência expirou ou mudou. Refaça a conferência antes de confirmar.'}`; 422 `{message, regra, escrita}` para RECUSADA por regra local; 422 `ALAV-ACAO`/`ALAV-LOTE`.

**lotes/{uuid}** — 200 `{total, por_resultado: {PENDENTE, OK, ERRO, INCERTO, RECUSADA}, terminado, itens: [{item_id, resultado, mensagem}]}`; lote de outra empresa ou inexistente = 404 (consulta por `daEmpresa` com as âncoras do resolver).

## Ordem do `confirmar`

ação conhecida e 1..50 itens → **trava** (`! liberada()` executa cada item pelo escritor e devolve 403, antes de qualquer assinatura) → forma dos itens (`regras()`) e normalização (round 2) → `AssinaturaDaPrevia::confere` → uso único (`Cache::add`, 10 min) → 1 item: `executar`; vários: `abrirLinha` por item + `ExecutarLoteAlavancaJob::dispatch`.

Na recusa o `item_id` é normalizado (`^MLB[0-9]{1,17}$` ou null; tipo e promoção como texto de até 40 caracteres) antes de gravar, para nunca estourar a coluna nem dar 500 (testado com array e 200 caracteres).

## Job

`ExecutarLoteAlavancaJob(lote, userId)`, fila `high`, `retryUntil` 30 min. `Cache::lock("alavancas:lote:{uuid}")`: ocupada = `release(20)`. Fatia de `publicador.fatia_segundos` (45 s); sobrou PENDENTE = `release(15)` (nunca `dispatch`). Por linha: `enviado_em` preenchido = INCERTO sem reenviar; conta da linha (`ContextoAlavancas::daLinha`) nula ou com `chaveConta()` diferente de `conta_chave` = RECUSADA `V-ACC-03`; senão reconstrói a ação por `RegistroDeAcoes::classe` e chama `executar` (que reaplica trava e vendedor). Uma `LeiturasDaAcao` por conta e por fatia, pré-carregada com todas as linhas PENDENTE (conta não liberada não lê nada). `failed()` loga `[Alavancas]`, marca PENDENTE sem `enviado_em` como RECUSADA `ALAV-LOTE-PAROU` e com `enviado_em` como INCERTO. Usuário inexistente = RECUSADA `ALAV-LOTE-USUARIO`.

## Mutação (learnings §5, T-166-51)

No ramo `recusado` do `PreviaAlavancasService::confirmar`, troquei `$this->escritor->executar(...)` por uma linha avulsa sem gravar nem passar pelo escritor. O `TravaEscritaHttpTest` quebrou em 7 casos (linha RECUSADA nas duas âncoras, com e sem assinatura, conta que saiu da lista, item_id malformado). Arquivo restaurado; sem diff residual.

## Testes

- `tests/Unit/Publicador`: 243 testes, 815 asserções, exit 0 (era 239 no 166-10).
- `tests/Feature/Publicador` (inteira): 550 testes, 3.157 asserções, exit 0, **0 falhas** (a falha do `PanoramaTest` registrada no 166-10 não ocorre mais com o relógio parado da trait).
- Novos: `RegistroDeAcoesTest` 4; `PreviaEConfirmarTest` 14; `TravaEscritaHttpTest` 12 (com data provider nas duas âncoras); `LoteJobTest` 15. `UnicoCaminhoDeEscritaTest` segue verde com o job novo na varredura.
- Tempo medido da prévia de 50 produtos (ML simulado por `Http::fake`): 0,03 s. Chamadas ao ML nessa prévia: no máximo 3 a `/items?ids=` (20+20+10), 1 passada pelos itens da promoção, 0 leituras por item de `/seller-promotions/items/`. Tempo real depende da latência do ML (3 multigets + 1 passada + análise dos 20 primeiros).

## Deviations from Plan

1. **[Rule 3 - bloqueio] `AnaliseAlavancasService::analisar` ganhou parâmetro opcional `?array $produtosLidos = null`.** Sem ele a análise dos 20 primeiros relia o multiget (4 chamadas a `/items?ids=` para 50 produtos, contra o teto de 3 do plano). A prévia passa os produtos que já leu em bloco; sem o argumento o comportamento é o de antes (`AnaliseRecebeTest` verde).
2. **[Organização]** O `PreviaAlavancasService` nasceu completo no commit da Task 1 (incluindo `confirmar` e o ramo de lote, que referencia o job da Task 3), em vez de ser acrescentado por tarefa. O commit da Task 3 traz só o job e o teste. Entre os commits 1 e 3 o ramo de lote apontava para uma classe ainda inexistente (só cairia em runtime, e nenhum teste o exercia).
3. **[Contrato do resumo]** Nenhum ajuste nas 3 ações de preço: o 166-07/08 já devolvia `analise` em `convite.inscrever`, `convite.alterar` e `desconto.criar`.
4. **[Escopo]** O `confirmar` de 1 produto não pré-carrega leituras (a leitura preguiçosa da própria ação faz as mesmas chamadas); o pré-carregamento em bloco vale na prévia e na fatia do job, onde há vários itens.
5. **[Teste]** Trait extra `Concerns/ApoioEscritaHttp` (cenário DEAL com N produtos e helpers de rota); "não escreveu" filtra pelo host do Mercado Livre porque o layout chama o ECF Drive em cada render (aviso do orquestrador).

## Known Stubs

Nenhum. A tela React que consome estas rotas é dos planos 166-12 em diante.

## Threat Flags

Nenhum além do registro do plano (T-166-50 a 55 mitigados: assinatura + uso único, trava antes da assinatura, leitura em bloco e análise limitada, trava/vendedor por item no job, `enviado_em` + lock por lote, IDOR por `daEmpresa` + `whereUuid`, teto de 50 itens e throttle 30/min).

## Self-Check: PASSED

Arquivos criados conferidos; 3 commits `feat(166-11)` no branch `feat/publicador-ml-261001`; diff de `routes/mlb_anuncios.php` só com acréscimos; STATE.md e ROADMAP.md intocados; sem push, deploy nem chamada real ao ML.
