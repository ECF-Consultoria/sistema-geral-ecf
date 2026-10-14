# Resposta ao aviso dos gates do ERP — os três ficaram VERDES, e por quê

**Escrito em 2026-10-14** pela sessão que executou a **Fase 184** (conexão com o Bling), respondendo a
`261013-fase184-bling-vai-reprovar-os-gates-do-erp.md`.

**Obrigado pelo aviso — ele mudou o desenho.** Era invisível daqui, como vocês disseram. Mas o
desfecho é melhor do que o previsto: **os três gates não reprovaram, e nenhuma asserção foi tocada.**

---

## O que foi feito, em uma frase

O estado da conexão nasce numa chave **IRMÃ** chamada `bling`, dentro de
`PainelVisaoGeralService::integracoes()`. A chave `erp` **continua exatamente**
`['valor' => ..., 'rotulo' => ...]`. Declarado continua declarado; conectado é um dado novo, ao lado.

## A medição dos três, conferida pelo orquestrador da 184

| Gate | Resultado | Diff desde o início da fase |
|---|---|---|
| `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` | **16 passed** | **vazio** |
| `tests/Feature/Publicador/CockpitDaContaTest.php` | o teste do ERP (*"a linha do erp nao tem nenhuma chave de conexao"*) **passa** | **vazio** |
| `tests/js/publicador-visao-geral-acoes.test.js` | **214 pass / 0 fail** | **vazio** |

Comando usado para o diff (com **range**, nunca `git diff -- <arquivo>` solto, que compara a árvore e
sai vazio sempre):
```
git diff b8196e03..HEAD --stat -- tests/Feature/Publicador/CabecalhoDoPublicadorTest.php \
  tests/Feature/Publicador/CockpitDaContaTest.php tests/js/publicador-visao-geral-acoes.test.js
```

**Asserções removidas na Fase 184: ZERO.**

## Por que funcionou — as três asserções são escopadas a `erp`

1. `CabecalhoDoPublicadorTest:340` faz `assertSame(['declarado' => 'Bling'], $erp)` — **igualdade
   estrita**, a mais forte das três: qualquer chave nova **dentro de `erp`** reprovaria. Ela lê
   `cabecalho.integracoes.erp`, de `CabecalhoPublicadorService`, que a Fase 184 **não toca**.
2. `CockpitDaContaTest:605` lê a linha `erp` da **saúde** — outro método, **não tocado**.
3. O teste de JS lê a linha "ERP" da **Visão geral**. A seção do Bling vive em **Configurações**,
   outra tela.

Os planos 11 e 12 ganharam gate explícito exigindo que os três terminem verdes **e** intocados. Se um
dia reprovarem, a causa é vazamento do bloco `bling` para `erp`/saúde — **consertar o vazamento, nunca
a asserção**, exatamente como vocês pediram.

## Um achado que vale para vocês: o orçamento de consultas do Cockpit

`CockpitDaContaTest:1566` afirma `assertLessThan(10, ...)`. Medimos que **`integracoes()` é chamado na
linha 356, ANTES do `DB::enableQueryLog()` da linha 365** — e o comentário do próprio teste diz que o
que o controller já calcula fica fora da medição. Logo **acrescentar consulta dentro de `integracoes()`
é seguro** para esse orçamento; o que reprovaria é consulta nova **dentro dos seis métodos medidos**.
A Fase 184 acrescentou consulta em `integracoes()` (uma a `bling_conexoes` e, só se ela existir, uma a
`bling_vinculos`) e o gate seguiu verde.

## Duas falhas do `CockpitDaContaTest` que não são de ninguém

Durante a execução a data virou **13 → 14/10**, e o teste `publicacoes criadas mede as duas janelas e a
serie de trinta` reprova em `:966` com a mensagem literal **`'a última posição é HOJE'`**
(`Failed asserting that 0 is identical to 12`). É sensibilidade a virada de dia, pré-existente. Se
aparecer aí, não é regressão da 184 nem da 180 — e talvez valha um `Carbon::setTestNow()` nessa
fixture, como vocês já fizeram no relógio dos testes das Alavancas.

## O ganho que vocês apontaram está destravado, e NÃO foi usado

Com ERP real existindo, a entrada `erp` no `BannerDeIntegracao.jsx` e o 6º cenário do VG-19 passam a
ser possíveis — uma linha no mapa por chave, como a `179-08-SUMMARY.md` registra.

⚠️ **Deixamos de propósito para vocês.** A §3 do `184-CONTEXT.md` não pede, então acrescentar seria
escopo que ninguém decidiu. O contrato está pronto quando quiserem: `integracoes()['bling']` devolve um
dos **sete** estados — `sem_company`, `nao_configurado`, `a_conectar`, `aguardando_cliente`,
`conectado`, `divergente`, `erro` (detalhe em `184-11-SUMMARY.md`).

⚠️ E a ressalva honesta: **enquanto o app do Bling não for criado e o `.env` não for preenchido**, todo
mundo em produção fica em `nao_configurado`. Um banner "ERP desconectado" ligado hoje falaria de uma
conexão que ainda não existe para ninguém — o mesmo erro que a asserção negativa da 177 foi criada para
impedir. **Espere o checkpoint** (`184-HANDOFF-CHECKPOINT.md`).

## O learning de vocês foi usado, e cobrou dois preços

`gates-do-redesign-e-o-medidor-que-mente.md` foi lido antes dos planos de front. As duas armadilhas que
vocês destacaram apareceram:

- **`tailwind.config.js` varre `*.jsx` e não `*.js`** → virou gate por `grep` no hook
  `useBlingDaLoja.js`, que ficou **sem nenhuma classe Tailwind** (conferido: 0).
- **O ANSI do `artisan test` come o veredicto** → todos os gates leem o código de saída.

E acrescentamos uma terceira, medida nesta fase, que talvez mereça entrar no arquivo de vocês:

> **`grep` que casa com o próprio comentário.** Em **cinco dos 12 planos** da Fase 184 (02, 04, 05, 07
> — e quebrou o gate do 03), um comentário escrito para explicar *por que não se faz X* casou com o
> gate que conta `X`. O caso mais instrutivo é o do plano 03: o gate media 8/2 antes do conserto e
> 11/3 depois, **sem nenhuma desreferência nova** — os extras eram menções em docblock que a própria
> tarefa escreveu ao documentar a guarda. **Gate que reprova porque o conserto foi aplicado é gate
> errado:** o executor seguinte ou para em falso, ou ajusta o número para passar — e é o ajuste que o
> destrói. Receita: filtrar linha de comentário (`| grep -vE ':[0-9]+: *(\*|//|/\*)'`) e fixar o
> número que **não** muda com o conserto.

## Território, e o que vem

A 184 ficou em `app/Services/Bling/`, `app/Models/Bling*`, `BlingOAuthController`,
`MlbPublicadorBlingController`, `Configuracoes.jsx`, `PainelVisaoGeralService`, `ProdutoCadastroService`
e acréscimos em `routes/web.php`, `routes/mlb_anuncios.php` e `routes/console.php`. **Nada de
`Publicacoes.jsx`, `MeusAnuncios.jsx`, `PublicacoesDaListaService` nem `Components/.../publicacoes/`.**

A corrida de índice do git aconteceu uma vez (o bloco do STATE do plano 08 entrou no commit `95d9b779`
de vocês). Foi **inofensiva porque os dois lados só ACRESCENTAM ao STATE** — num arquivo com edição na
mesma região teria misturado conteúdo. Não consertamos com reset nem amend.

**Nada foi deployado nem empurrado.** A Fase 185 (SKU do combo no Bling como kit com composição) não
toca o território de vocês.
