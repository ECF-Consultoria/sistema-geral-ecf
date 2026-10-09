---
tipo: quick
slug: nxp-unique-intermitente-de-pub-imagens
data: 2026-10-09
files_modified:
  - tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php
autonomous: true
---

<objective>
Fechar o item 5 do `deferred-items.md` da Fase 175: falha intermitente

```
UNIQUE constraint failed: pub_imagens.rascunho_id, pub_imagens.sha256
```

no filtro combinado do Creative Engine (`Phase160|161|162|165|168|169|170|171`),
observada em 2 de 7 rodadas durante o 175-11.

Regra desta quick: **achar o mecanismo e PROVÁ-LO** antes de mudar qualquer linha. Falha
intermitente aceita qualquer explicação plausível — e o registro original já tinha duas
(vazamento de estado entre suítes via `Storage::disk('local')` real; cache de snapshot do
`RascunhoRepository`). Nenhuma das duas pode ser adotada sem prova, porque "sumiu depois
que eu mexi" é indistinguível de "não reapareceu ainda" a essa frequência.
</objective>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: reproduzir e isolar</name>
  <files>(nenhum — investigação)</files>
  <action>
  Rodar o filtro combinado algumas vezes. Em paralelo, enumerar TODA fonte de bytes de
  imagem dos testes dessas fases e verificar quais inserts em `pub_imagens` não têm guarda
  de unique (`ImagemAssetService::receber()` tem: ele deduplica por sha e ainda pega
  `QueryException` 23000 — então o insert que estoura é de fixture, não do app).

  Procurar especificamente ALEATORIEDADE que alimente sha256: ela explica
  intermitência sem precisar de vazamento entre suítes.
  </action>
  <verify>o teste culpado nomeado, e o mecanismo reproduzido sob demanda</verify>
</task>

<task type="auto" tdd="false">
  <name>Task 2: faixa determinística</name>
  <files>tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php</files>
  <action>
  Trocar o sorteio por contador de instância numa faixa que não colida com nenhuma outra
  fixture, e registrar no docblock a faixa de cada fixture que minta bytes — senão a
  sobreposição volta na próxima que alguém escrever.

  NÃO mexer no `CenarioCriativoDoPublicador`: todo o `Phase165` depende dele, e a
  sobreposição que existe lá é determinística (ou colide sempre, ou nunca).
  </action>
  <verify>filtro combinado verde em rodadas repetidas; o teste culpado determinístico</verify>
</task>

</tasks>

<notes>
Só arquivo de teste. Nada em `app/`, nada em `resources/js/`. Sem interseção com o
redesign de UI em paralelo.
</notes>
