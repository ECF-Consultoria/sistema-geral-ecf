---
tipo: quick
slug: nxp-unique-intermitente-de-pub-imagens
data: 2026-10-09
status: complete
commits:
  - 640bc60f fix(261009-nxp) — faixa determinística
files_modified:
  - tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php
  - .planning/phases/175-publicador-etapa-3-produto-fases/deferred-items.md
---

# A causa

`ColocarFotoNoGrupoSobTravaTest::fotoSemGrupo()` gerava o lado da imagem com
`jpeg(1200 + random_int(1, 200))` **para ter sha único**. Sorteio pode repetir, e
`test_foto_entra_no_fim_do_grupo_sem_mexer_nas_que_ja_estavam_la` tira TRÊS fotos no MESMO
rascunho:

```
P(colisão) = 1 − (199/200)(198/200) ≈ 1,5% por rodada
```

Como `pub_imagens` tem unique `(rascunho_id, sha256)` e o insert do fixture **não tem
guarda** (o do app tem: `ImagemAssetService::receber()` deduplica por sha e ainda pega
`QueryException` 23000), a colisão estoura a rodada.

# Como foi provado, em vez de deduzido

Falha de 1,5% aceita qualquer explicação plausível — foi por isso que o registro original
chegou a duas hipóteses erradas. Então estreitei a faixa para `random_int(1, 1)`, forçando
todo sorteio para o mesmo valor:

- `test_foto_entra_no_fim_do_grupo...` (**3 sorteios**) → falhou, com o erro **literal**
- os três testes de **1 sorteio** → passaram

É a assinatura de **auto-colisão entre sorteios**. Se fosse choque com o cenário, com o
provider falso ou vazamento entre suítes, os de um sorteio teriam caído junto.

# As duas hipóteses do registro original estavam erradas

O `deferred-items.md` apontava `Storage::disk('local')` real (sem `Storage::fake`) e cache
de snapshot do `RascunhoRepository`. Nenhum dos dois participa: `RascunhoRepository` não
tem estado estático (conferido), e a colisão é **intra-teste** — `:memory:` e
`RefreshDatabase` estavam certos o tempo todo.

Isso também explica o padrão que mais confundiu na investigação original: `Phase165`
isolado "parecia estável" em 3 rodadas (é exatamente o esperado a 1,5%), e o filtro
combinado "parecia culpado" só por acumular mais rodadas. **Não havia ordem nem suíte
culpada.**

# Verificação

- `ColocarFotoNoGrupoSobTravaTest` — 5 verdes, e agora determinístico
- Filtro combinado `Phase160|161|162|165|168|169|170|171` — **419 passed + 1 incomplete**,
  em 2 rodadas depois do fix (e 4 rodadas limpas antes dele, coerente com 1,5%)

# Limitações e risco parente que ficou de pé

- **Não deployado** (é arquivo de teste; não há o que deployar).
- ⚠️ `fotoComArquivo()` e o provider falso do `CenarioCriativoDoPublicador` dividem a faixa
  `1200 + k` — razão do desvio documentado em `RegenerarEAprovarKitTest`. Essa sobreposição
  é **determinística** (colide sempre ou nunca) e hoje não colide. Deixei como está: separar
  exigiria mexer no cenário de que todo o `Phase165` depende, para um problema que não se
  manifesta, e um dos testes se apoia na coincidência de propósito. Registrado no
  `deferred-items.md`.
- O docblock passou a listar a faixa de cada fixture que minta bytes (`900`, `1200`, `1400`,
  `300`, `1500`), para a sobreposição não voltar na próxima fixture que alguém escrever.
