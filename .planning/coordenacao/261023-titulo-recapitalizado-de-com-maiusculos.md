# ⚠️ Título saindo com `De`/`Com` maiúsculos — 2 testes vermelhos na `origin/main` · 23/10/2026

**Para quem mantém `app/Support/Publicador/` e o título do Publicador.** Eu **não toquei em nada
disto** e **não vou tocar** — é território seu e o `CLAUDE.md` me proíbe de alterar
`App\Support\Publicador`. Este aviso existe porque a falha **vai para anúncio real** e eu a
encontrei ao medir a suíte depois de integrar a sua `origin/main`.

---

## O que falha

```
Tests\Unit\Publicador\Payload\MontadorDePlanoTest

  ✖ d1 classico e premium saem do mesmo rascunho com titulo…
      Failed asserting that two strings are identical.
      - 'Cadeira de Escritório ECF Executiva com Giro'      ← esperado
      + 'Cadeira De Escritório ECF Executiva Com Giro'      ← obtido

  ✖ tc02 um eixo sem defines picture dois itens da mesma…
      O payload mudou em relação ao snapshot up_furadeira_voltagem.
```

⚠️ **O título errado é `De` e `Com` em maiúscula — português errado.** E o mais importante:
`'Cadeira de Escritório ECF Executiva com Giro'` é a constante **`TITULO_PREMIUM`** do próprio
teste (`MontadorDePlanoTest.php:34`), isto é, **o título que o teste ENTREGA**. Então não é o
gerador escolhendo mal uma palavra nova: **algo no caminho está recapitalizando um título que já
estava correto.** Se isso valer para título escrito por pessoa ou pela IA, o anúncio publicado
sai com `De`/`Com` maiúsculos.

---

## O que eu PROVEI (e o que não)

**Provado — a falha já existia na `origin/main`; a integração não a criou:**

```
git diff --numstat ed8fdc15..HEAD -- app/Support/Publicador \
    app/Services/Publicador/Payload tests/Unit/Publicador/Payload
→ VAZIO
```

Nada do caminho dessas duas falhas mudou entre o seu tip (`ed8fdc15`, 23/10 11:39) e o nosso
`HEAD`. Como não há diferença, o resultado tem de ser o mesmo nas duas pontas. Também confirmei
que **não** toquei em `app/Support/Publicador` depois do merge (`git diff ed8fdc15..HEAD` nesse
caminho sai vazio) e que as duas falham **isoladas**, fora da suíte inteira — não é poluição de
ordem de teste.

**Provado — o arquivo de teste é seu:** `MontadorDePlanoTest.php` foi tocado por último em
`b15b3698`, *"o Premium tem a mesma sequência de fotos do Clássico, só a capa muda"*, autor
`ECF Dev`, 22/10/2026.

**⚠️ NÃO provado — eu não achei o culpado, e não vou chutar.** Procurei quem aplica title-case no
caminho e achei **três** pontos; **nenhum dos três** capitaliza um título inteiro:

| arquivo | o que faz | mudou nos seus 118 commits? |
|---|---|---|
| `ComposicaoDoComposto.php:176` | title-case **só na primeira palavra** do nome de um item (`preg_split(...)[0]`) | ⚠️ **arquivo NOVO** (+224 linhas) |
| `RegrasDoTitulo.php:124` | title-case **de uma palavra** candidata que vai ser acrescentada | não mudou |
| `TermosVetados.php:111` | title-case **da troca** de um termo vetado | não mudou |

Então o ponto que recapitaliza o título **todo** está em outro lugar, e você conhece esse caminho
muito melhor do que eu. O commit `cf44db31` (*"título vazio da IA não vira 'Kit 2' e **acabamentos
do título** do combit"*) é só o **palpite mais próximo** pelo nome — **não confirmei**.

---

## O que eu NÃO fiz, de propósito

⚠️ **Não "consertei" o teste para aceitar `De`/`Com`.** Seria ajustar a régua em vez do código, e
é justamente o erro que este projeto já catalogou (*"número que muda quando o trabalho certo é
feito treina o executor seguinte a ajustar a régua"*). O teste está **certo**; quem está errado é
a saída.

**Não toquei no código nem no snapshot** `up_furadeira_voltagem`. Snapshot de payload só deve ser
regravado por quem sabe que a mudança é intencional — regravar por conveniência apaga a prova.

---

## Uma coisa que EU consertei, e que é vizinha disto

No mesmo dia, o gate do **sinal fantasma** (`FotosDoPainelServiceTest`) ficou vermelho porque você
trocou `EditorEmUso::emUso` por `::editando` nos **três** pontos do `PreparoIaDoRascunhoService`.

➡️ **O gate estava certo** — a mensagem dele pede *"renomeado ou acrescentado **sem documentar**"*,
e o conserto que ele cobra é **documentar**. Documentei na lista dele (commit `db46cf05`), com a
medição: `git show ed8fdc15:` já tinha `editando` nos três **antes** do merge, logo a mudança é
sua e a resolução do merge só preservou a sua escolha, acrescentando o gancho `agenteParado` no
terceiro ponto.

⚠️ E deixei registrado lá que **não é rename de método**: `EditorEmUso` tem os **dois**, e são
**sinais diferentes** (`marcar()`/`emUso()` usam `chave()`; `marcarEdicao()`/`editando()` usam
`chaveDaEdicao()`). As outras **quatro** entradas da lista seguem em `emUso` e continuam legítimas
— se alguém "uniformizar" as quatro, quebra o outro sinal.

---

## Estado da suíte, para você comparar

| medição | antes do `db46cf05` | depois |
|---|---|---|
| `tests/Unit/Publicador` + `tests/Feature/Publicador` | 2880 passed · **3 failed** | 2881 passed · **2 failed** |
| asserções · duração | 26.874 · 1147 s | — |
| suíte JS | 6190 · 6189 pass · **1 fail** (pré-existente da Fase 87) | igual |

As **2 que sobram** são as duas deste aviso.

⚠️ **E uma armadilha de medição, para você não cair nela:** rodei a suíte com
`artisan test … > log; echo "exit=$?"`, e o `echo` devolveu **0** com **3 testes vermelhos** —
o código de saída que chegou foi o do `echo`, não o do teste. **O veredicto está na linha
`Tests:`**, nunca no exit code de um comando composto.
