---
quick: 261021-fix-beco-do-retry-e-o-gate-188-05
fase_relacionada: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
tipo: conserto
autonomous: true
partida: ddbfa230
criado: 2026-10-21
---

# Quick `261021-fix` — o beco do retry, o motivo que voltava atrás, e o gate `188-05` vermelho

**Três itens independentes, todos autorizados pelo usuário.** Dois são decisões novas (25 e 26,
tomadas em 21/10/2026) e um é o conserto de um gate alheio que o `193-04` deixou **VERMELHO** e
**devolveu** em vez de consertar.

```
PARTIDA: ddbfa230   (capturada na PRIMEIRA ação, antes de qualquer leitura)
```

⚠️ **A PARTIDA é SÓ referência de leitura.** O território sai do **escopo do assunto `(193-fix)` no
`%s`**, em duas passadas, com isca nas duas direções — **nunca** de range aberto a partir dela.

---

## §0 — O QUE JÁ ESTÁ MEDIDO ANTES DE QUALQUER EDIÇÃO

| medição | comando | resultado |
|---|---|---|
| a constante | `grep -n JA_PASSARAM_PELO_PORTAO …PublicacaoDoPortaoService.php` | declarada em **`:217`**, lida em **`:500`** ✅ (confere com o briefing) |
| o grafo | `sed -n` do `const TRANSICOES` em `PubAprovacaoOferta.php:169` | `FALHA => [PUBLICANDO]` · `PUBLICANDO => [PUBLICADO, FALHA]` · `APROVADO => [PUBLICANDO, AGUARDANDO_APROVACAO]` |
| o gate vermelho | `sh tests/gates/verificar-testes.sh 188-05 tentar-de-novo` | **`EXIT_REAL=1`**, `Tests: 2 failed (2 assertions)`, `422` onde se esperava `302`, em `:976` ✅ |
| a fixture da 188 | `sed -n '179,290p' SalvarEAprovarDoPainelTest.php` | `cenario()` cria **os DOIS alvos** sem `ativo` (default **true**), **mesmo título** nos dois, **sem** `PubValidacao`, e **sem** `contas_liberadas` |
| a receita publicável | `ComandoDoPortaoDaPublicacaoTest.php:170-265` | `contas_liberadas` + títulos distintos + `fresh()` antes da `PubValidacao` + `PubValidacao` L3 `OK` |
| a tolerância de rede | `SalvarEAprovarDoPainelTest.php:89` | `LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices']` **já existe** — a leitura de tarifa da prontidão **não** viola a sonda |

### ⚠️ A medição do grafo que **derruba a razão escrita no docblock**

Quem aponta **para** `FALHA`: **só** `PUBLICANDO`.
Quem aponta **para** `PUBLICANDO`: `APROVADO` e `FALHA`.
➡️ O único caminho de entrada no ciclo `{PUBLICANDO, FALHA}` vindo de fora é **`APROVADO`**.
➡️ **Tudo que está em `falha` JÁ FOI APROVADO por uma pessoa.** Logo a frase do docblock de
`:205-215` — *"faria uma oferta que falhou arrastar a irmã para a fila sem a irmã ter sido
reconferida"* — **é falsa para este estado**: a irmã em `falha` **foi** conferida.

---

## Tarefa 1 — DECISÃO 25: irmã em `falha` conta como já aprovada

**Arquivo:** `app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php`
(⚠️ **só** a constante `JA_PASSARAM_PELO_PORTAO` e o docblock dela).

### O beco que motivou (medido pelo `193-04`, item `193-I`)

Produto com **dois alvos ativos**: quando a publicação falha, **as DUAS ofertas falham junto** (a fila
publica o **produto inteiro**). Aí o *"Tentar de novo"* de cada uma **recusa nomeando a outra**, e
`TRANSICOES[falha] = [publicando]` é a **única** saída. ➡️ **Nenhuma das duas pode ser retentada
pela tela.**

### O que muda

```php
public const JA_PASSARAM_PELO_PORTAO = [
    PubAprovacaoOferta::APROVADO,
    PubAprovacaoOferta::PUBLICANDO,
    PubAprovacaoOferta::PUBLICADO,
    PubAprovacaoOferta::FALHA,      // <- decisão 25
];
```

### ⚠️⚠️ E A RAZÃO DO DOCBLOCK É **REESCRITA**, não só a lista

Deixar a prosa antiga contradizendo a lista é **pior que o defeito**: o próximo executor leria a
razão e *"consertaria"* de volta. A razão nova **carrega a medição do grafo** (quem aponta para
`FALHA`, quem aponta para `PUBLICANDO`), e diz que `aguardando_aprovacao`, `em_revisao_ecf`,
`ajustes_solicitados` e `preparando` **continuam fora** pelo motivo direto: ninguém terminou de
revisar.

### A prova

| asserção | forma |
|---|---|
| a constante | **IGUALDADE DE CONJUNTO** (`assertEqualsCanonicalizing` + contagem), nunca piso — assim um quinto estado por descuido **reprova** |
| o grafo | **alcançabilidade medida no próprio `TRANSICOES`**, não afirmada: quem entra em `FALHA` e em `PUBLICANDO`, por varredura |
| isca (+) | irmã em **`falha`** → a oferta **ENFILEIRA** (`enfileirada: true`, `fila_item_id` num item `VIVOS`) |
| isca (−) | irmã em **`aguardando_aprovacao`** → **BARRA**, e o motivo **NOMEIA** a modalidade |
| a decisão 23 não afrouxa | os **quatro** estados `aguardando_aprovacao` / `em_revisao_ecf` / `ajustes_solicitados` / `preparando` barrando, **um por um** |

⚠️ **Os testes das waves 02/03/04 que dependem da constante podem mudar de resultado.** A disciplina:
**MEDIR** antes e depois; expectativa obsoleta conserta-se **no lado do teste**; mudança de
**comportamento** → **PARAR e devolver**.

---

## Tarefa 2 — DECISÃO 26: o motivo da recusa PARA de voltar atrás

**Arquivo:** `app/Http/Controllers/MlbPublicadorAprovacaoController.php` (⚠️ **só** a action
`tentarDeNovo` e o docblock dela).

### O que está medido (pergunta `193-04/B`)

A action roda `enfileirar()` e `tentarDeNovo()` **numa transação só** — o que **está certo** e
**elimina por construção** o estado *"`publicando` com item terminado"*. **Mas na recusa o
`rollback` leva embora a linha de histórico** que explicaria o motivo. A pessoa vê no `422` e **nada
fica registrado**.

### O que muda

⚠️⚠️ **A TRANSAÇÃO EM SI NÃO MUDA** — não é removida, não é dividida. A razão dela está no docblock
e é o que torna um estado impossível.

O que muda é que, **depois** de a transação voltar atrás, o motivo da recusa é gravado **como linha
de histórico com ator Sistema** — o **mesmo mecanismo** das decisões 21, 23 e 24.

```
try { DB::transaction( enfileirar -> (recusa? lança) -> tentarDeNovo ) }
catch (RegraViolada $e) {
    // ⚠️ FORA da transação que voltou atrás: aqui o rollback JÁ aconteceu.
    <grava a linha de histórico com o motivo REAL>
    return $this->recusaDoPainel($e);   // o 422 CONTINUA saindo
}
```

⚠️ **Nada novo é cunhado:** nenhum toast (os 6 de VOC-08 são **fechados**), nenhum estado, nenhum
rótulo, nenhuma rota. O prefixo da linha e o mecanismo de de-duplicação são **os que já existem**.

⚠️ **A de-duplicação continua contra a ÚLTIMA linha** (decidido pelo orquestrador no item `193-H`):
o mesmo motivo voltando **depois de outro fato** é informação nova e **entra**. **NÃO muda.**

### A prova

| asserção | forma |
|---|---|
| a linha sobrevive | ⚠️ **COLETORA desde o início** — a lista de textos do histórico da oferta, não contagem de eventos (achado do `193-02`: contagem subiu **26→27** sem defeito nenhum) |
| o `422` continua | `assertStatus(422)` + `assertJsonPath('regra', …)` |
| isca (+) | **recusa** → a linha **existe depois do rollback**, e o texto traz o **motivo REAL** |
| isca (−) | **sucesso** (302) → **não existe** linha de recusa |
| o rollback ainda vale | a linha **inteira** da oferta, byte a byte, intacta; **zero** item de fila vivo novo |
| a transação não foi dividida | ocorrência de `DB::transaction(` na action = **1**, no código **sem comentário** |

⚠️ **E a asserção de nível de transação é VAZIA POR CONSTRUÇÃO se escrita como
`assertGreaterThan(0, $transactionLevel)`** — o `RefreshDatabase` abre transação própria e o nível já
vem **1**. ➡️ Se eu medir nível, é **estritamente maior que a referência medida FORA**.

---

## Tarefa 3 — o gate `188-05 tentar-de-novo`

**Arquivo:** `tests/Feature/Publicador/Aprovacao/SalvarEAprovarDoPainelTest.php`
⚠️⚠️ **POR MÉTODO. NUNCA no `cenario()`** (compartilhado por **9** métodos de um teste de fase **EM
PRODUÇÃO**). ⚠️ **NENHUMA sonda daquele arquivo é tocada.**

### A causa, medida (três motivos empilhados, do `193-04`)

```
fixture da 188 (produto com as DUAS modalidades ATIVAS, só o Clássico em `falha`):
 [1] contas_liberadas VAZIO + irmã aguardando → a guarda da DECISÃO 23 DOMINA
 [2] conta LIBERADA, irmã aguardando          → o MESMO motivo
 [3] conta LIBERADA, irmã INATIVA             → 'Confira no Mercado Livre antes de agendar.' (falta a L3)
```

➡️ **A fixture daquela fase NUNCA FOI PUBLICÁVEL.** Enquanto o retry era só transição de estado, ela
**redirecionava**; agora que ele **enfileira de verdade**, **recusa (422)** onde a 188 esperava
**302**. ⚠️ **Recusar é o comportamento CERTO** — o que ficou obsoleto é o `assertRedirect()`, **não
as asserções de invariante** (*"zero escrita no ML"*, *"sem MLB"*), que seguem corretas.

⚠️ **A decisão 25 NÃO conserta este caso:** a irmã daquela fixture está em `aguardando_aprovacao`,
não em `falha`. **Os dois consertos são independentes.**

### O que muda

Um auxiliar **novo**, chamado **apenas** pelos **dois** métodos vermelhos (não pelos outros 7),
que torna a fixture publicável:

1. `config(['publicador.contas_liberadas.companies' => [$company->id]])` (fail-closed);
2. a **irmã** sai do caminho — **desativar o alvo** (`pub_rascunho_alvos.ativo = false`), que é a
   âncora da própria guarda;
3. **títulos distintos** nos dois alvos (**V-TIT-04**);
4. `PubValidacao` **L3** `OK`, com `revisao` lida do rascunho **relido** (⚠️ o Eloquent **não** lê o
   default do banco).

⚠️ **Os itens 2, 3 e 4 serão medidos um a um**: o que não for necessário é **declarado** como
medido-e-não-necessário, em vez de aplicado "por garantia".

### A prova

- as DUAS pontas de **`tests/gates/contagens-188-05.txt`** medidas e **coladas**, valor antigo
  **REMOVIDO** (⚠️ **se a contagem NÃO mudar, DESCONFIE e investigue**: pode ser que eu não toquei o
  que pensei);
- os outros **5** ids daquele manifesto **IDÊNTICOS**;
- o **recorte PHP de volta a ZERO falhas** — a prova de sucesso declarada pelo briefing;
- `numstat` do `cenario()`: a prova é que o **diff do arquivo não toca as linhas 179-313**.

---

## §4 — O MANIFESTO DESTA QUICK, E A ESCOLHA DECLARADA

`tests/gates/contagens-261021-01.txt`, chamado **`261021-01`**.

⚠️ **MEDIDO: a forma com LETRA não funciona.** `verificar-testes.sh:122` resolve o 1º argumento por
`sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'` — **dois grupos de DÍGITOS**. Letra cai no `else` e monta
`contagens-186-<arg>.txt`, o manifesto de uma fase **EM PRODUÇÃO**. ➡️ Sigo a forma da quick de
20/10 (`contagens-261020-01.txt`, chamada `261020-01`), que é o **primeiro manifesto de QUICK do
projeto**. **A aceitação pelo harness é PROVADA, não suposta.**

**Regras do manifesto:** manifesto incompleto **ABORTA** · **nenhum id com mais de TRÊS métodos**
(`--filter` é regex **NÃO ANCORADO** e 186/187/188 estão em produção) · **ZERO órfão** · **bijeção
nos DOIS sentidos com expansor de alternância** · **sufixo `_261021_fix` em TODO método novo** ·
`--autoteste` **20/0 colado**.

---

### ⚠️ Os `<verify>` desta quick — a TERCEIRA bijeção (id ↔ `<verify>`)

**id órfão não falha: ele simplesmente NUNCA RODA.** Os três ids do manifesto são invocados aqui, e
nenhum `<verify>` usa id que não esteja no manifesto. Há asserção durável disso.

```
sh tests/gates/verificar-testes.sh 261021-01 irma-em-falha-conta
sh tests/gates/verificar-testes.sh 261021-01 motivo-da-recusa-registrado
sh tests/gates/verificar-testes.sh 261021-01 bijecao-manifesto-261021-01
```

**E os gates ALHEIOS que têm de continuar verdes, declarados:**

```
sh tests/gates/verificar-testes.sh 188-05 tentar-de-novo              <- o VERMELHO do item 3
sh tests/gates/verificar-testes.sh 188-05 aprovar-e-enfileirar
sh tests/gates/verificar-testes.sh 188-05 ausencia-de-segundo-passo
sh tests/gates/verificar-testes.sh 193-02 guarda-modalidade-irma      <- o dependente da constante
sh tests/gates/verificar-testes.sh 193-04 retry-republica             <- o dependente da transação
sh tests/gates/verificar-testes.sh 186-04 unica-porta
sh tests/gates/verificar-testes.sh 186-04 oferta-do-portao
sh tests/gates/verificar-testes.sh 186-09 transversais
sh tests/gates/verificar-testes.sh 186-09 andamento-um-lugar-so
sh tests/gates/verificar-testes.sh 186-09 sem-segundo-passo
sh tests/gates/verificar-testes.sh 186-09 bijecao-e-homonimo
```

---

## §5 — A MUTAÇÃO (obrigatória nos três itens)

`mutar-261021-fix.sh` (⚠️ nome que **carrega a tarefa** — o scratchpad é **COMPARTILHADO**), commit
**ANTES**, `cp -p` para **fora** do repo, ocorrência **travada em 1 ANTES** por string exata, **linha
conferida** depois, aplicação por **divergência de `sha256`**, restauração **DA CÓPIA** em
`trap EXIT`, `sha256` final contra `git show HEAD:` com `tr -d '\r'` nos dois lados.
⚠️⚠️ **`git checkout` NUNCA.** ⚠️⚠️ **O `# tests` do mutado tem de ser IGUAL ao do pristino.**
⚠️⚠️ **SOBREVIVENTE É INVESTIGADA.**

**As duas mutações óbvias, declaradas:**
- tirar `FALHA` da constante → tem de **MORRER nomeando o estado**;
- fazer a gravação do motivo **voltar para dentro** da transação → tem de **MORRER**.

---

## §6 — AS TRÊS FACES DO TERRITÓRIO

**sobre-captura** (`--grep` lê a mensagem inteira) · **sub-captura** (concretizou-se nas waves 02 e
nas da 189 — **vizinhos medidos À MÃO, por diferença**) · **o autor, com CINCO identidades**
(`MB.ECF-100376` ×3715, `ECF Dev` ×2008, `Dev ECF` ×57, `root` ×5, `ecfmaycon` ×4) → **`%an` é vazio
por construção** como discriminador.

⚠️ **DUAS SESSÕES COMMITANDO** → `git diff HEAD -- <arquivo>` antes de **CADA** edição.
⚠️ *"Árvore limpa"* por `git status` **ABORTA** pelo `??` alheio → `git diff HEAD` nos **meus**
arquivos. ⚠️ **27 stashes: APENAS contados.**

---

## §7 — COMMITS

⚠️⚠️ **TRÊS AGENTES CAÍRAM na Fase 187**, um deixando 2/3 de um plano sem commit. ➡️ **COMMIT
ATÔMICO POR ITEM — são TRÊS itens independentes, logo pelo menos TRÊS commits.** Se o turno pesar:
**PARAR, commitar o provado, e dizer EXATAMENTE em que item parou.**

`git add -- <caminho>` e **pathspec no commit**; `-m` **ANTES** do `--`; **`-F <arquivo>` sempre**
(heredoc come a barra invertida e falha por CRLF); **`--amend` proibido**.
⚠️ **NUNCA** `git add -A`, `git stash`, `git reset`, `git checkout`, `git clean`.
Commits terminam com `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.

---

## §8 — OS LIMITES

❌ **nenhuma migration**, ❌ **`artisan migrate` NÃO roda**, ❌ **nenhum deploy/push/VPS**, ❌ **nenhuma
chamada à API real do ML**, ❌ **nenhum `pint`**, ❌ **nenhum worktree**, ❌ **nenhum `.jsx`**,
❌ **nenhuma rota nova**, ❌ **nenhum estado/rótulo/toast novo**.

**SOMENTE LEITURA:** `PublicacaoService` (⚠️ `numstat` **VAZIO** — o único `POST /items` é o `:442`)
· `FilaPublicacaoService` · `AgendadorDaFila` · `ResumoRapidoService` (⚠️ em `Fila/`, não em
`Aprovacao/Fila/`) · `MlPublicacaoService` · `AndamentoDaOfertaService` (⚠️ **ÚNICA PORTA** — eu
**chamo** `mover()`) · `PreparadorDaAprovacao` e todo `Agente/` (**outra sessão**) · serviços e
`.jsx` das Fases **186/187/188/189** · `Aprovacao.jsx`.

❌ **NÃO tocar** `.planning/ROADMAP.md`, `REQUIREMENTS-v27.md`, `.planning/STATE.md` (**GATE-07**),
os `193-NN-PLAN.md`, a `193-REGUA.md`, a `193-COBERTURA.md` nem a `193-BASELINES.md`.
❌ **NÃO** rodar `state.advance-plan` — **o orquestrador escreve no ROADMAP/STATE**.

⚠️ **`test -e` em CADA caminho citado** — **DEZOITO fantasmas na milestone**; o fechamento da 189
pegou **6 de 32 (19%)**, **todos de NOMES**. **Um NOME não é um CAMINHO.**

## §9 — PARAR E DEVOLVER SE

- o conserto do item 3 exigir mexer no `cenario()` compartilhado ou em alguma **sonda**;
- a decisão 25 fizer algum teste das waves 02/03/04 mudar de **COMPORTAMENTO** (não só de
  expectativa);
- gravar o motivo fora da transação exigir **dividir ou remover** a transação;
- for preciso alterar `PublicacaoService`, `FilaPublicacaoService`, `AndamentoDaOfertaService` ou
  serviço/`.jsx` das Fases 186-189;
- algum id da `186-09` mudar **por causa do meu trabalho**.
