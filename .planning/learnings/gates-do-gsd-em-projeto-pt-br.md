# Os gates do GSD medem errado neste projeto (planos em pt-BR + REQUIREMENTS.md parado na v17)

Descoberto em 2026-09-01, planejando a Fase 137. Os dois gates automáticos do
`/gsd-plan-phase` reportaram falha grave numa fase que estava correta. Nenhum dos dois
é dedutível do código do projeto — são detalhes do SDK do GSD cruzados com duas
convenções nossas.

**Vale para todas as fases da v23.0 (137-143) e para qualquer fase futura**, porque o
`CLAUDE.md` obriga artefato de planejamento em pt-BR.

## 1. `check.decision-coverage-plan` só enxerga cabeçalho em inglês

O gate é **BLOQUEANTE** — recusa marcar a fase como planejada. Na Fase 137 ele acusou
**20 de 23 decisões do CONTEXT "não cobertas"**, enquanto o `gsd-plan-checker`, lendo os
mesmos arquivos, confirmava 23/23. O checker estava certo.

Fonte: `sdk/src/query/check-decision-coverage.ts` no pacote `get-shit-done-cc`.

O gate **não varre o plano inteiro**. Ele monta um "designated" e só procura ali:

- no frontmatter YAML: as chaves `must_haves`, `truths`, `objective`
- no corpo: seções sob cabeçalho que casa com
  `/^#{1,6}\s+(?:must[_ ]haves?|truths?|tasks?|objective)\b/i`

E antes disso **remove comentários HTML e blocos de código cercados**.

Nossos planos escrevem `## Decisão de planejamento: D-18 ...`, `## Tarefas`, e usam a tag
XML `<objective>` (não a chave YAML `objective:`). Nada disso casa com a regex. Resultado:
as 23 citações `D-NN` existiam, mas todas fora do campo de visão do gate.

O match em si é permissivo — `\bD-NN\b`, então `(D-19)` e `D-16,` contam. **O problema é
só onde ele procura.** Há também um soft match por frase (as 6 primeiras palavras
normalizadas da decisão); foi ele que salvou as 3 decisões que passaram (D-08, D-16, D-19),
por coincidência de texto.

**Correção que funciona:** citar os `D-NN` dentro de `must_haves.truths` no frontmatter —
chave YAML, independente de idioma. Na 137 virou uma linha por plano:

```yaml
must_haves:
  truths:
    - "Decisões do CONTEXT implementadas por este plano: D-17 (...), D-18 (...), D-19 (...)"
```

23/23 imediatamente. Não é gambiarra para o gate: é a rastreabilidade decisão→plano que o
`verify-phase` também vai procurar depois, com o **mesmo matcher**
(`check.decision-coverage-verify`). Sem isso, a fase passa no planejamento e falha de novo
na verificação.

## 2. `gap-analysis` lê `REQUIREMENTS.md`, que parou na v17.0

O `gsd-tools.cjs gap-analysis` (passo 13e) tem o caminho **fixo** em
`.planning/REQUIREMENTS.md`. Esse arquivo é da **milestone v17.0** — `CART-*`, `CTX-*`,
`DESEMP-*`, `MENU-01`. Os requirements de verdade estão em `REQUIREMENTS-v23.md`
(e antes, `-v18`, `-v21`, `-v22`).

Na Fase 137 ele reportou **"22 of 22 items not covered"** — 22 requirements de outra
milestone, nenhum deles da fase. O gate é **não-bloqueante**, então o estrago é só
confundir quem lê. A cobertura real (6/6 ETAPA-01..06) tem de ser conferida à mão contra
`REQUIREMENTS-v23.md`.

O mesmo vale para o `requirements_path` do `init.plan-phase`: ele devolve
`.planning/REQUIREMENTS.md`. **Todo subagente precisa receber `REQUIREMENTS-v23.md`
explicitamente**, senão pesquisa e planeja contra a milestone errada — e não há erro,
só ausência silenciosa dos REQ-IDs certos.

## 3. O detector de UI dispara com palavra em português

O passo 5.6 roda `grep -iE "UI|interface|frontend|component|layout|page|screen|view|form|..."`
na seção da fase, **sem fronteira de palavra**. Em pt-BR isso casa com `req**ui**sito`,
`seg**ui**r`, `in**form**ações`. Na Fase 137 deu 5 falsos positivos e o workflow queria
exigir `UI-SPEC.md`.

Sinal confiável no lugar do grep: o campo **`**UI hint:** yes`** que o próprio ROADMAP
carrega por fase. A 138 tem; a 137 não.

---

**Regra prática para a próxima fase:** confie no `gsd-plan-checker` e nos números
conferidos à mão; trate os dois gates automáticos como suspeitos até provar o contrário.
Os três problemas acima são do ferramental, não do plano.

---

## 4. `state.record-session` corrompe o `STATE.md` de três jeitos (medido 2026-09-02)

O `STATE.md` já carregava um aviso escrito sobre `state.advance-plan`. O
`state.record-session` — usado pelo passo `update_state` do `discuss-phase` — tem
problemas próprios. Medidos rodando uma vez, na sessão de contexto da Fase 138:

1. **Achata o `last_activity` para só a data.** A linha descritiva anterior
   (`2026-09-02 -- Phase 137 Plan 11 concluído ... FASE 137 COMPLETA, 11/11 planos`)
   virou `last_activity: 2026-09-02`. O registro do que foi feito **se perde**, e é
   justamente o campo que a próxima sessão lê para saber onde parou.

2. **Insere linhas em branco espúrias no corpo do arquivo.** Duas linhas em branco
   apareceram no meio de um parágrafo de prosa da seção `Current Position`, a ~700
   linhas do que estava sendo editado, partindo a frase no meio. O diff acusou
   `8 inserções / 6 deleções` para uma atualização que deveria ser de 3 linhas.

3. **Sobrescreve o topo da pilha do `Session Continuity` em vez de empilhar.** A
   entrada mais recente (137-11) foi **substituída** pela nova, em vez de a nova
   entrar acima dela. As entradas mais antigas ficaram intactas — ou seja, o histórico
   perde exatamente o registro anterior, sempre.

**Como trabalhar:** rodar o comando, depois `git diff -- .planning/STATE.md` e **ler o
diff inteiro** antes de commitar. Conferir os três pontos acima e corrigir à mão. Um
`cp .planning/STATE.md /tmp/STATE.md.bak-<fase>` antes de rodar poupa o trabalho de
reconstruir o texto perdido. Nunca commitar a saída do `record-session` sem olhar — o
número de linhas mudadas não bate com o tamanho da mudança pretendida, e esse é o
sintoma barato.
