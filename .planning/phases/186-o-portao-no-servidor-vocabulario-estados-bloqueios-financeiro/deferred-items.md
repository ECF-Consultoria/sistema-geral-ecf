
## 15/10/2026 — gate `guarda-sem-enum-sem-try` do plano 186-04: colisão de homônimo (PRÉ-EXISTENTE)

Achado durante o plano **186-08**, ao reexecutar os 33 gates do `contagens-186-04.txt` depois de
elevar as transições para 13 pares. ⚠️ **NÃO é regressão do 186-08** — nenhum arquivo meu tem
método com esse nome.

```
[guarda-sem-enum-sem-try] REPROVOU: nao casou 'Tests: 1 passed (20 assertions)'.
                          medido:   Tests: 2 passed (44 assertions)
```

**Causa:** o filtro do manifesto é
`test_guarda_a_migration_nao_usa_enum_nem_try_catch_e_o_up_e_idempotente`, e o `--filter` do
PHPUnit é regex **não ancorado** ⇒ ele casa **dois** métodos, em arquivos de planos diferentes:

- `tests/Feature/Publicador/Aprovacao/OfertaDoPortaoTest.php` → `…_idempotente`
- `tests/Feature/Publicador/Aprovacao/MigrationDoPortaoGuardaTest.php` → `…_idempotente_por_tabela`

É exatamente a armadilha "nome de método de guarda genérico COLIDE entre planos: o `--filter` casa
homônimos de vários arquivos e SOMA contagens". O gate continua **verde nos dois testes** (2 passed,
0 failed) — o que está errado é a **contagem do manifesto**, que mede dois arquivos achando que mede
um.

**Conserto (de quem for dono daqueles dois planos):** ancorar o filtro (`…_idempotente$` não é
possível no formato atual) ou **dar sufixo próprio** a um dos dois métodos e remedir a linha.
⚠️ **Não toquei**: é manifesto e arquivo de outro plano, e não foi este plano que quebrou.

## 15/10/2026 — ⚠️ BLOQUEANTE: a ISCA do gate `ligacao-e-so-ligacao` (plano 186-05) reprovou

**Causado pelo plano 186-08**, por uma mudança **AUTORIZADA** pelo usuário em 15/10/2026 (o 13º par
`aprovado → aguardando_aprovacao`). **NÃO é regressão de comportamento** — é uma ISCA cuja premissa
deixou de ser verdadeira.

```
tests/Feature/Publicador/Aprovacao/AjustesEHistoricoTest.php:419
  ISCA DO DIFF: o modelo do plano 186-04 aparece como tocado — este plano não o altera
  Expected: ''      Actual: 'app/Models/PubAprovacaoOferta.php'
```

**Por que ela reprovou.** O gate mede com RANGE **ABERTO** (`1c16d9e9..HEAD`, constante `PARTIDA` em
`:72`), e usa como **isca** *"este plano não toca `app/Models/PubAprovacaoOferta.php`"*. A isca é
boa e o range é certo (sem range o diff sai vazio sempre). Mas `..HEAD` significa *"tudo o que
aconteceu desde o começo do 186-05"* — então a isca passa a medir **os planos seguintes também**, e
qualquer plano posterior que legitimamente toque aquele modelo a derruba.

Conferido por `git log 1c16d9e9..HEAD -- app/Models/PubAprovacaoOferta.php`: **um** commit, o
`1370046f` deste plano. As **outras 45 asserções** daquele teste continuam passando — inclusive as
que importam (`TRANSICOES` não removido do `AndamentoDaOfertaService`, `DB::transaction` intacta,
`mover()` não reescrito, os dois ganchos presentes).

⚠️ **NÃO TOQUEI**, e o motivo é regra: o usuário autorizou, nominalmente, **só** o gate `transicoes`
em `AndamentoDaOfertaServiceTest.php:262` e a linha dele no `contagens-186-04.txt`. Mexer num
**terceiro** gate, de **outro plano**, é fora do autorizado — e trocar sujeito de isca é mudar o
CONTEÚDO do gate, não remedir um número (o que eu fiz, e declarei, nas duas linhas de contagem que
os meus arquivos novos moveram).

### O conserto, escrito para ser aprovado em um passo

Trocar o **sujeito da isca** por um arquivo que seja intocado no range — a isca existe para provar
*"o `git diff` não devolve vazio para tudo"*, e **qualquer** arquivo intocado serve igual. Medidos
agora como intocados em `1c16d9e9..HEAD`:

- `app/Support/Publicador/RegraViolada.php`
- `app/Models/PubRascunho.php`
- `app/Models/PubImagem.php`

```diff
-        $this->assertSame('', $this->gitDiff('app/Models/PubAprovacaoOferta.php'), 'ISCA DO DIFF: o modelo do plano 186-04 aparece como tocado — este plano não o altera');
+        // ⚠️ O sujeito da isca mudou em 15/10/2026: o RANGE é ABERTO (`..HEAD`), e o modelo do
+        // 186-04 passou a ser tocado LEGITIMAMENTE pelo plano 186-08 (o 13º par da reabertura,
+        // decisão do usuário). A isca prova que o `git diff` não devolve vazio para tudo — e
+        // qualquer arquivo intocado no range serve igual, sem perder força.
+        $this->assertSame('', $this->gitDiff('app/Support/Publicador/RegraViolada.php'), 'ISCA DO DIFF: um arquivo intocado no range aparece como tocado — o `git` está devolvendo o nome para tudo');
```

⚠️ A contagem do manifesto **não muda** (é a mesma asserção: `tests=1 assertions=46`) — mas
**remedir é obrigatório** depois da troca.

⚠️ **Lição para o `learnings` (classe nova, não é nenhuma das nove):** *gate com range ABERTO
(`<partida>..HEAD`) cuja isca é "ninguém mais toca neste arquivo" tem validade de UM plano.* Ele não
mede o plano dele — mede **tudo o que vier depois**. A isca de "não tocado" só é estável se o
sujeito for um arquivo **fora** do assunto da milestone (como `RegraViolada.php`), ou se o range for
**fechado** no commit final do plano.
