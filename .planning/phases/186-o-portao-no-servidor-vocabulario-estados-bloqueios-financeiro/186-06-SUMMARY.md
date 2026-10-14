---
phase: 186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro
plan: 06
subsystem: publicador-aprovacao
tags: [migration, schema, frete, composicao-financeira, vinculo-duravel, dado-15, fin-07, terr-11]
requires:
  - pub_aprovacao_ofertas (186-04)
  - pub_imagens, pub_rascunhos (Fase 165 / 2026_10_01_200000)
  - users
  - App\Support\Publicador\ConferenciaDeFrete (SOMENTE LEITURA)
  - App\Services\Publicador\DadosEfetivosService::fretesDaLinha (SOMENTE LEITURA)
provides:
  - pub_aprovacao_fretes (o frete do vendedor informado à mão — histórico, nunca sobrescrita)
  - pub_aprovacao_composicoes (a composição congelada no ato — a face auditável de DADO-13)
  - pub_imagem_referencias (o vínculo durável imagem gerada → original, N:N — DADO-15)
  - PubAprovacaoFrete::informar() / ::vigenteDe() / ::ORIGENS
  - PubAprovacaoComposicao::linhaDeFrete() (FIN-07) / ::ANULAM_O_RESULTADO / ::ORIGEM_VIVA_DO_FRETE
  - PubImagemReferencia::originaisDe() / ::SEM_ORIGINAL (IMG-13)
affects:
  - plano 186-07 (o serviço de composição: deriva a origem viva e congela no ato)
  - plano 186-08 (a rotina das 8 mudanças relevantes: `informar()` passa a DELEGAR o incremento)
  - Fase 190 (IMG-13: a tela do estado "original de referência não disponível")
  - Fases 192 e 194 (as ÚNICAS donas da ESCRITA de `pub_imagem_referencias`)
tech-stack:
  added: []
  patterns:
    - "âncora sem FK para alcançar tabela de outro dev (`criativo_id`)"
    - "histórico por linha + flag `vigente` em vez de sobrescrita"
    - "congelamento no ato (`momento`, `versao`) em vez de cópia contínua"
key-files:
  created:
    - database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php
    - database/migrations/2026_10_15_100500_create_pub_imagem_referencias_table.php
    - app/Models/PubAprovacaoFrete.php
    - app/Models/PubAprovacaoComposicao.php
    - app/Models/PubImagemReferencia.php
    - tests/Feature/Publicador/Aprovacao/FreteInformadoTest.php
    - tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php
    - tests/gates/contagens-186-06.txt
  modified: []
decisions:
  - "`margem` é `decimal(12,3)`, não `decimal(7,3)`: o teto de ±9999,999 estoura e este projeto já mediu +20738%"
  - "a origem por linha é DERIVADA da fonte viva (186-07) e CONGELADA no ato — nunca copiada de forma contínua"
  - "embalagem sem fonte NÃO anula o resultado (exceção deliberada e única, 14/10/2026): `estimado_por_baixo`"
  - "`pub_imagem_referencias` é N:N e DURÁVEL, e esta fase CRIA sem PREENCHER — a escrita é das Fases 192/194"
  - "`criativo_id` sem FK: TERR-11 proíbe DDL encostando em `ml_anuncio_criativos`"
  - "relação nomeada `imagemOriginal()` e nunca `original()`: colide com a propriedade protegida `$original` do Eloquent"
metrics:
  duration: "~2h"
  completed: 2026-10-15
---

# Fase 186 Plano 06: O Financeiro Auditável e o Vínculo Durável da Original — Summary

Frete do vendedor informado como **histórico** (nunca sobrescrita), composição financeira
**congelada no ato** com origem e nota por linha, e o vínculo **durável e N:N** da imagem gerada
com a original de referência — a estrutura que existe porque o vínculo de hoje **morre em 48 h**.

**3 tabelas / 2 migrations / 3 modelos / 26 testes / 27 gates / 18 mutantes mortos.**

---

## Commits

| # | hash | o que |
|---|------|-------|
| 1 | `26520d6f` | `feat(186-06)` — migration do financeiro: `pub_aprovacao_fretes` + `pub_aprovacao_composicoes` |
| 2 | `cd4e260c` | `feat(186-06)` — migration do vínculo durável: `pub_imagem_referencias` (DADO-15) |
| 3 | `2c942992` | `test(186-06)` — RED dos dois arquivos de teste |
| 4 | `4cf921ea` | `feat(186-06)` — GREEN: os 3 modelos, o manifesto e os consertos dos gates |

Commit de partida: **`1c16d9e9`** (capturado na primeira ação). Todo gate de diff usa
`1c16d9e9..HEAD` — **com range**.

---

## 1. Tabela de decisão de schema — `pub_aprovacao_fretes` (DADO-12)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `oferta_id` | unsignedBigInteger | FK `pubapfr_oferta_fk` → `pub_aprovacao_ofertas`, **CASCADE** |
| `valor` | decimal(12,2) | dinheiro; folga de graça |
| `origem` | string(28) | `cotacao_transportadora` \| `tabela_cliente` \| `simulador`. **Sem `enum`**; maior = 22 |
| `nota` | string(200) | a nota de fonte legível **com a data** — ⚠️ **NOMEIA a fonte** (DADO-13/VOC-04) |
| `informado_por` | unsignedBigInteger **NULL** | FK `pubapfr_autor_fk` → `users`, **SET NULL** |
| `informado_em` | dateTime | `dateTime`, nunca `timestamp()` (teto de 2038) |
| `versao` | unsignedInteger | a versão da oferta **DEPOIS** do incremento |
| `vigente` | boolean default `true` | **uma só vigente por oferta**; informar de novo derruba a anterior |
| timestamps | | |

Índices: `pubapfr_oferta_ix` (`oferta_id`, `vigente`) · `pubapfr_autor_ix` (`informado_por`).

⚠️ **`vigente` é boolean + regra, não unique parcial:** MariaDB/MySQL não têm índice parcial, e um
unique em (`oferta_id`, `vigente`) proibiria DUAS linhas históricas da mesma oferta (todas com
`vigente = false`). A regra mora em `informar()`, numa transação, e tem teste + mutante.

## 2. Tabela de decisão de schema — `pub_aprovacao_composicoes` (DADO-13)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `oferta_id` | unsignedBigInteger | FK `pubapcp_oferta_fk` → `pub_aprovacao_ofertas`, **CASCADE** |
| `versao` | unsignedInteger | a versão no momento do ato |
| `momento` | string(12) | `aprovacao` \| `excecao`. **Sem `enum`**; maior = 9 |
| `preco` | decimal(12,2) | o preço vigente no ato (pior caso — regra do 186-07) |
| `linhas` | json | as **7** linhas, cada uma com **valor, origem e nota**. ⚠️ **json SEM default** |
| `total` | decimal(12,2) **NULL** | `null` quando `incompleto` — **nunca zero** |
| `resultado` | decimal(12,2) **NULL** | "Resultado estimado por venda" |
| `margem` | **decimal(12,3)** NULL | "Margem de contribuição estimada" — ver §4 |
| `incompleto` | boolean default `false` | falta item que ANULA o resultado |
| `estimado_por_baixo` | boolean default `false` | resultado **SEM** o custo de embalagem: o real é MENOR |
| `margem_minima` | decimal(5,2) | o mínimo que valia **no ato** |
| `margem_minima_origem` | string(24) | `parametro_da_empresa` \| `config` — **de qual lado do fallback** |
| timestamps | | |

Unique (`oferta_id`, `versao`, `momento`) = `pubapcp_versao_uq`, que **lidera por `oferta_id` e
apoia a FK** — por isso não há índice avulso de `oferta_id` (seria segundo índice com o mesmo
prefixo, custo de escrita de graça).

### ⚠️ A decisão de DADO-13, registrada no docblock

A origem por linha é **DERIVADA da fonte viva** no serviço de composição (plano **186-07**) — ela
**não** é persistida de forma contínua, porque **custo e frete mudam todo dia e uma cópia contínua
ficaria velha e mentiria**. O que é persistido é o **congelamento no momento do ato**. Isso satisfaz
*"persistida **ou** derivada de forma auditável"* pelos **dois** lados, e é a única coisa que torna a
auditoria-depois de BLOQ-09 possível, **já que não existe desfazer** (PUB-09).

### ⚠️ A exceção deliberada da Embalagem — escrita com DATA e RAZÃO

> **Decisão do usuário, 14/10/2026:** "Embalagem" sem fonte nasce `Pendente` **VISÍVEL** e **NÃO
> anula o resultado**. O resultado existe e vem com `estimado_por_baixo = true` (o real é MENOR).
> "Outros custos" nasce **R$ 0,00 `Confirmado`** (`other: {v: 0, src: 'conf', note: 'Cadastro do
> produto'}` no protótipo), porque "nenhum outro custo informado" é uma AFIRMAÇÃO do cadastro, não
> uma lacuna.
>
> **RAZÃO:** sem esta exceção o painel diria "Incompleto" em **100%** das ofertas — a embalagem
> raramente está cadastrada — e a fase inteira ficaria **inerte**.

**Quem ANULA o resultado são só CINCO:** `custo`, `comissao`, `tarifa_fixa`, `frete`, `imposto`.
Travado em `PubAprovacaoComposicao::ANULAM_O_RESULTADO`, com dois mutantes (M3: acrescentar
`embalagem`; M18: acrescentar chave que não é uma das 7) — **os dois morreram**.

## 3. Tabela de decisão de schema — `pub_imagem_referencias` (DADO-15)

| coluna | tipo | regra / por quê |
|---|---|---|
| `id` | bigint unsigned PK | |
| `imagem_id` | unsignedBigInteger | **a GERADA**. FK `pubimref_imagem_fk` → `pub_imagens`, **CASCADE** |
| `referencia_imagem_id` | unsignedBigInteger | **a ORIGINAL**. FK `pubimref_orig_fk` → `pub_imagens`, **CASCADE** |
| `criativo_id` | unsignedBigInteger **NULL** | ⚠️ **SEM FK** (TERR-11 / T-186-23) |
| `posicao` | unsignedSmallInteger default `0` | a ordem em que as originais foram escolhidas |
| `gravado_em` | dateTime | |
| timestamps | | |

Unique (`imagem_id`, `referencia_imagem_id`) = `pubimref_par_uq` (lidera por `imagem_id` e apoia a
1ª FK) · `pubimref_orig_ix` (apoia a 2ª FK **e** serve à consulta "em quais geradas esta original
foi usada") · `pubimref_criativo_ix`.

**N:N de propósito:** DADO-15 aceita **mais de uma** original por imagem gerada. Com uma coluna, o
painel mostraria UMA das originais como se fosse a única — afirmação falsa sobre como a imagem
nasceu.

---

## 4. ⚠️ `margem` com folga, e o modo de falhar assimétrico ESCRITO

`decimal(7,3)` comporta no máximo **±9999,999**, e **este projeto já mediu +20738%**
(`project_gate_roll_delta_aceito_ressalvas`). Escolhido **`decimal(12,3)`** = ±999.999.999,999,
folga de **cinco ordens de grandeza**.

**O modo de falhar, escrito no docblock da migration e conferido por gate:**

| banco | o que acontece ao estourar |
|---|---|
| **MySQL 8.0.46** (produção) | **erro 1264** (out of range) — a gravação **cai** |
| **MariaDB 10.4.32** (local) | reporta a precisão corretamente (`decimal(12,3)`, conferido) |
| **SQLite** (testes) | **grava CALADO**, truncado — a suíte passa verde e o deploy quebra |

A alternativa "derivar de `resultado / preco` na leitura" foi **recusada**: o ato congelado tem de
poder ser lido **sem recalcular nada** — é essa a diferença entre auditoria e recálculo.

`margem_minima` é `decimal(5,2)` **de propósito** (é limite configurado por gente; ninguém configura
mínimo de 1000%), e `resultado` é `decimal(12,2)`. O contraste entre os três é asserção de teste: é
ele que mostra que a escala extra da `margem` é da **porcentagem**, não descuido.

### ⚠️ ACHADO que mudou a forma do gate

`Schema::getColumns()` **no SQLite devolve `numeric`, SEM precisão nenhuma** (as três colunas
decimais desta tabela — medido em 15/10/2026), enquanto o MariaDB devolve `decimal(12,3)` e
`decimal(5,2)`. ➡️ Um gate que conferisse a precisão pelo **schema vivo** não tinha o que medir: ou
falharia sempre, ou viraria um *"se der para ler, confere"* que **PULA EM SILÊNCIO** justamente no
ambiente em que roda. A fonte de verdade passou a ser o **TEXTO da migration**, e a limitação do
SQLite virou **asserção MEDIDA** (`assertSame('numeric', $tipoVivo)` com nota de que falhar ali
seria **boa notícia**).

---

## 5. ⚠️ Nomes de índice e de FK — `strlen` MEDIDO e conferido no `information_schema`

### Migration do financeiro (`2026_10_15_100400`)

| nomeado à mão | `strlen` | nome PADRÃO do Laravel | `strlen` |
|---|---|---|---|
| `pubapfr_oferta_ix` | **17** | `pub_aprovacao_fretes_oferta_id_foreign` | **38** |
| `pubapfr_oferta_fk` | **17** | `pub_aprovacao_fretes_informado_por_foreign` | **42** |
| `pubapfr_autor_ix` | **16** | `pub_aprovacao_composicoes_oferta_id_foreign` | **43** ← o maior |
| `pubapfr_autor_fk` | **16** | | |
| `pubapcp_versao_uq` | **17** | `pub_aprovacao_fretes` = 20 · `pub_aprovacao_composicoes` = 25 | |
| `pubapcp_oferta_fk` | **17** | | |

### Migration do vínculo (`2026_10_15_100500`)

| nomeado à mão | `strlen` | nome PADRÃO do Laravel | `strlen` |
|---|---|---|---|
| `pubimref_par_uq` | **15** | `pub_imagem_referencias_imagem_id_foreign` | **40** |
| `pubimref_orig_ix` | **16** | `pub_imagem_referencias_criativo_id_foreign` | **42** |
| `pubimref_criativo_ix` | **20** ← o maior | `pub_imagem_referencias_referencia_imagem_id_foreign` | **51** ← o maior |
| `pubimref_imagem_fk` | **18** | `pub_imagem_referencias` = 22 | |
| `pubimref_orig_fk` | **16** | | |

⚠️ **O plano dizia "50 ou 51" para `pub_imagem_referencias_referencia_imagem_id_foreign`. MEDIDO por
`strlen`: 51** (22 + 1 + 20 + 8). É a **segunda vez nesta fase** que um número de nome de índice
chega do plano com margem de erro de 1 (o 186-03 achou o plano dele dizendo 57 onde era 56) — e é
exatamente por isso que o guarda **recalcula por `strlen`** a cada rodada em vez de confiar no texto.

### Conferido por `information_schema` (MariaDB local), **nunca por stdout**

```
pub_aprovacao_fretes       IDX pubapfr_autor_ix [16] (informado_por)       unique=0
                           IDX pubapfr_oferta_ix [17] (oferta_id,vigente)  unique=0
                           FK  pubapfr_autor_fk [16]  informado_por -> users              ON DELETE SET NULL
                           FK  pubapfr_oferta_fk [17] oferta_id -> pub_aprovacao_ofertas  ON DELETE CASCADE
                           COL valor decimal(12,2) · vigente tinyint(1) default '1'
pub_aprovacao_composicoes  IDX pubapcp_versao_uq [17] (oferta_id,versao,momento) unique=1
                           FK  pubapcp_oferta_fk [17] oferta_id -> pub_aprovacao_ofertas  ON DELETE CASCADE
                           COL margem decimal(12,3) · margem_minima decimal(5,2)
                           COL incompleto tinyint(1) default '0' · estimado_por_baixo tinyint(1) default '0'
pub_imagem_referencias     IDX pubimref_par_uq [15] (imagem_id,referencia_imagem_id) unique=1
                           IDX pubimref_orig_ix [16] · IDX pubimref_criativo_ix [20]
                           FK  pubimref_imagem_fk [18] imagem_id -> pub_imagens            ON DELETE CASCADE
                           FK  pubimref_orig_fk [16]  referencia_imagem_id -> pub_imagens  ON DELETE CASCADE
                           COL criativo_id bigint unsigned null=YES · posicao smallint default '0'
```

⚠️ **Nenhum índice caiu em nome PADRÃO do Laravel** (asserção de teste sobre `Schema::getIndexes`,
procurando `_foreign`), e `->constrained()` não é usado em nenhuma das duas.

---

## 6. ⚠️ A prova `migrate → rollback → migrate`, com reconsulta entre os passos

`tasklist | grep mysqld` → **`mysqld.exe PID 11008`**, no ar. Migration **só no banco LOCAL**, sempre
com `--path`.

### Migration do financeiro

| passo | comando | reconsulta à tabela `migrations` + `hasTable` |
|---|---|---|
| 1 | `migrate --path=…100400…` | `1 linha, batch=44` · `fretes=1 composicoes=1` |
| 2 | `migrate:rollback --batch=44 --path=…100400…` | `minha=0` · `fretes=0 composicoes=0` · **MÃES intactas** (`ofertas=1 users=1`) · **186-05 intocado** (`ajustes=1`) |
| 3 | `migrate --path=…100400…` | `minha=1, batch=46` · `fretes=1 composicoes=1` |

### Migration do vínculo

| passo | comando | reconsulta |
|---|---|---|
| 1 | `migrate --path=…100500…` | `batch=47` · tabela criada, índices/FKs conferidos no `information_schema` |
| 2 | `migrate:rollback --batch=47 --path=…100500…` | `minha=0` · `tabela=0` · **MÃE `pub_imagens`=1** · **`ml_anuncio_criativos`=1 (outro dev) intacta** |
| 3 | `migrate --path=…100500…` | `minha=1, batch=47` · `tabela=1` |

### ⚠️ As 9 pendentes alheias seguem **PENDENTES**

`migrate:status | grep -c Pending` → **9**, antes e depois das duas provas. São:
`2026_10_08_140000_semear_tipos_e_pares_de_banheiro`,
`2026_10_08_150000_add_estoque_to_estrutura_produto_variacoes`,
`2026_10_08_150000_create_polos_reunioes_mes_table`,
`2026_10_08_150100_add_descricao_to_estrutura_produtos`,
`2026_10_08_150200_add_estrutura_produto_id_to_pub_produtos`,
`2026_10_08_160000_create_atributo_explicacoes_table`,
`2026_10_09_180000_create_pub_tarefas_table`,
`2026_10_10_090000_create_pub_promocoes_automaticas_table`,
`2026_10_10_210000_create_polos_decola_status_table`.

---

## 7. ⚠️ FIN-07 provado — HÁ FONTE DE FRETE, e a linha NÃO nasce "Pendente"

A fonte foi **medida, não presumida**: `DadosEfetivosService::fretesDaLinha()` (estática e pura) é
chamada **de verdade** no teste — montar o array à mão provaria só que o mapa concorda comigo mesmo.
As frases saem de `App\Support\Publicador\ConferenciaDeFrete::ORIGENS` (`:28-31`), **LIDO sem
alterar** (diff do Support **vazio por range**): repetir as quatro frases criaria duas redações da
mesma verdade.

| caso | `frete_origem` (fonte viva) | estado da linha | nota (NOMEIA a fonte) |
|---|---|---|---|
| 1 | `digitado` | **Confirmado** | "Precificação do Portal: digitado no Portal" |
| 2 | `sugerido` + `fonte: conta` → `conta` | **Confirmado** | "… cotado na conta pelo Portal" |
| 3 | `sugerido` + `fonte: tabela` → `tabela` | **Estimado** | "… estimado pela tabela" |
| 4 | `outro_tipo` *(5º caso, além dos 4 exigidos)* | **Estimado** | "… o mesmo do outro tipo de anúncio" |
| 5 | `valor = null` | **Pendente** | "A Precificação está sem frete para este anúncio." |

➡️ **Só a AUSÊNCIA de valor produz `Pendente`** — asserção de contagem travada: 3 estados distintos
nos 5 casos (um mapa constante passaria em 2), e **exatamente 1** caso `Pendente`.

**E o informado à mão VENCE** (MOD-03): com `PubAprovacaoFrete` presente, a linha nasce
**`Confirmado`** com a nota do modal **+ data** ("Tabela do cliente · 15/10/2026 10:00" — o
`fNote = origem + ' · ' + agora` do protótipo, `:1204`), com isca exigindo que as duas notas sejam
**diferentes**.

**Mutante M2** (fazer `digitado` nascer `Pendente`) → **morto**.

---

## 8. ⚠️ DADO-15: estrutura criada nesta fase, ESCRITA nas Fases 192/194, sem backfill possível — o passado fica em IMG-13

**Por que a estrutura existe** (medido, com os nomes conferidos no disco): o vínculo *"esta imagem
nasceu DESTAS originais"* vive hoje **só** em `ml_anuncio_criativos.referencias`, e é apagado **duas
vezes** — na aprovação (`MlbAnuncioController::criativoAprovar()`, `:2624`) e pela varredura diária
`creative:limpar-referencias` (`app/Console/Commands/LimparReferenciasCriativos.php`), janela de
**48 h** (`services.creative.retencao_referencias_horas`, `config/services.php:439`), carimbo
`referencias_apagadas_em`, acessor `MlAnuncioCriativo::referenciasVivas()` (`:238`).

**Mas as originais são DURÁVEIS e NOSSAS:** são linhas de `pub_imagens`, e o docblock de
`PublicadorCriativoReferenciaService.php:19-32` é **textual** — *"nada aqui move nem apaga
`pub_imagens`"*. Os ids já passam por `selecionarFotos()` (`:72`).

### ⚠️ A fase dona NOMEADA, nos dois docblocks

**`ESCRITA NAS FASES 192/194 — ESTA MIGRATION SÓ *CRIA* A ESTRUTURA`** (em maiúsculas, bloco (2) do
docblock da migration) e o mesmo em `PubImagemReferencia`. Gate exige `/Fases?\s+192/` **e** `/194/`
nos dois arquivos, **com isca de fase inventada** (um `"A escrita é da Fase 999"` sintético **não**
casa — sem essa isca, um regex frouxo daria "dona declarada" para qualquer número).
**Mutante M10** (apagar o literal do docblock) → **morto**.

### ⚠️ A prova NÃO é "a tabela tem 0 linhas" — é AUSÊNCIA DE ESCRITA, com isca de dois lados

"0 linhas" é **vazio por construção**: com `RefreshDatabase` a tabela **nasce vazia em todo teste**,
e a asserção passaria igual se o código escrevesse e o teste não exercitasse o caminho.

O que foi medido: varredura de **todo o `app/`** (**1002 arquivos `.php`** — `find app -name '*.php'
| wc -l`, com asserção de que a varredura achou mais de 300; senão rodaria sobre lista vazia),
lendo o **CÓDIGO sem comentários**
pelo tokenizador do PHP, procurando
`PubImagemReferencia::(create|insert|insertGetId|updateOrCreate|firstOrCreate|upsert|forceCreate)\(`
**e** `DB::table('pub_imagem_referencias')`. Resultado: **zero ocorrências**.

**Isca de dois lados:**
- **(a)** a **mesma sonda**, apontada para `PubProduto` e `PubRascunho` — que o `app/` **realmente
  escreve** (medido: `PubProduto::create` ×3, `PubRascunho::create` ×2) —, **TEM de ACHAR**. Se não
  achasse, o padrão de busca estaria errado e o "zero" não provaria nada.
- **(b)** `test -e` em `app/Models/PubImagemReferencia.php` antes de acreditar em qualquer vazio.
- **(c)** isca do tokenizador: `PubImagemReferencia::create(...)` **em código** casa; a **mesma
  frase em comentário** não casa (senão o gate reprovaria pelo próprio conserto — o docblock fala
  de escrita).

⚠️ **Desvio declarado:** o plano sugeria usar **`PubAprovacaoEvento` (do 186-05)** como isca.
**Aquele modelo não existia no disco** no momento desta execução (`app/Models/` tinha só
`PubAprovacao{Conjunto,Defeito,Fidelidade,Imagem,ImagemAlerta,Oferta}` — o 186-05 roda em
paralelo), e **depender de arquivo de outro plano faria o gate oscilar com o progresso dele**. A
isca usa modelos cuja escrita foi **medida nesta árvore**.

### ⚠️ Nenhum backfill é possível — e na estreia isso vale para TODO o acervo

O dado **já foi apagado** pelas duas rotas acima, desde a Fase 160. **Não há de onde reconstruir.**
O passado fica no **estado declarado de IMG-13**:

```php
PubImagemReferencia::SEM_ORIGINAL        = 'sem_original_de_referencia'
PubImagemReferencia::SEM_ORIGINAL_ROTULO = 'Original de referência não disponível'
PubImagemReferencia::SEM_ORIGINAL_APOIO  = 'A referência desta imagem não foi guardada — ela é anterior a este registro.'
PubImagemReferencia::SEM_ORIGINAL_ICONE  = 'image-off'     // ⚠️ VOC-05: ícone E texto
```

`originaisDe()` devolve `['originais' => Collection, 'estado' => null|SEM_ORIGINAL]` — **nunca**
painel vazio, **nunca** zero, **nunca** a própria imagem de IA no lugar da original (asserção
explícita). A **tela** é da **Fase 190**; aqui ficam o nome, o rótulo, a frase e o registro do fato.
**Mutante M7** (devolver vazio sem o estado) → **morto**.

---

## 9. ⚠️ TERR-11 — a retenção de 48 h e o Creative Engine INTOCADOS, por RANGE

`git diff 1c16d9e9..HEAD --name-only -- <caminho>` → **VAZIO** em todos, com `test -e` /
`assertDirectoryExists` **antes** de acreditar no vazio (caminho fantasma já "provou" território
intocado 6 vezes neste projeto):

| caminho | diff por range |
|---|---|
| `app/Services/Creative` (diretório) | **VAZIO** |
| `app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` | **VAZIO** |
| `app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php` | **VAZIO** (somente leitura) |
| `app/Console/Commands/LimparReferenciasCriativos.php` | **VAZIO** |
| `config/services.php` | **VAZIO** |
| `app/Support/Publicador` (diretório, TERR-01 — nem subdiretório novo) | **VAZIO** |
| `resources/js/Components/Mlb/Publicador/aprovacao` | **VAZIO** |
| `tests/gates/verificar-testes.sh` | **VAZIO** |
| `.planning/ROADMAP.md` · `.planning/STATE.md` | **VAZIO** |

⚠️ **ISCA DO DIFF:** o **mesmo comando**, nas duas migrations que este plano criou, sai **NÃO
vazio**. Sem essa isca, um `git` que falhasse em silêncio devolveria string vazia para tudo e o gate
seria verde permanente.

**A retenção por VALOR:** `config('services.creative.retencao_referencias_horas') === 48`, com
**isca de chave inexistente** (tem de dar `null`). E o comando segue sendo o dono da janela
(`creative:limpar-referencias` presente no arquivo dele).

**`criativo_id` é SEM FK** por duas razões independentes: convenção de âncora do módulo
(`pub_tarefas`, `pub_filas_publicacao.company_id`) **e** TERR-11/T-186-23 — FK para
`ml_anuncio_criativos` é DDL encostando na tabela do outro dev. O literal `ml_anuncio_criativos`
**não aparece no CÓDIGO** da migration (só no docblock, com isca inversa). **Mutante M9**
(acrescentar a FK) → **morto**.

**Nada desta fase encosta em `ml_acervo_itens`** (asserção nas duas migrations).

---

## 10. A bijeção ids ↔ métodos, e os 27 gates

**26 métodos** nos dois arquivos · **27 linhas de gate** no manifesto (26 individuais + o composto
`territorio-e-vinculo` do `<verify>` da Task 3) · **todas as contagens MEDIDAS**, uma invocação por
método.

A bijeção é provada **por script**, nas duas direções:
1. **todo método** é coberto por ≥1 filtro do manifesto — *teste sem id **NUNCA RODA** em gate
   nenhum, e foi o sobrevivente da mutação do 186-03, onde era **lacuna real***;
2. **todo id** casa ≥1 método — *id órfão é gate rodando sobre **zero casos***.

Com **isca do casador nos dois sentidos**: ele entende alternância (`test_(a|b)` casa `test_b`) e
**não** casa por prefixo (`test_a` não casa `test_abc`) — um casador frouxo diria "tudo coberto", um
estrito diria "nada". E a sonda de métodos **tem de se achar a si mesma**.

**Mutante M17** (comentar um id do manifesto) → **morto**.

**Resultado: 27 gates rodados pelo `tests/gates/verificar-testes.sh 06 <id>`, 0 falha.**
`--autoteste` do helper: **14 casos, 0 divergência**.

### ⚠️ Colisão de nome de método entre planos — consertada antes de virar contagem frágil

Dois guardas deste plano nasceram com nome **idêntico** aos dos planos 186-03/186-04
(`test_guarda_nenhum_nome_de_indice_ou_fk_passa_de_64_medido_por_strlen_com_isca` e
`test_guarda_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca`), e o `--filter` do PHPUnit
casava os **três arquivos**, somando as contagens. ➡️ Renomeados para `*_financeiro_*`, e uma
varredura de colisão (`grep -rl "function <m>(" tests/`) volta **limpa** para os 26. **Gate cuja
contagem dependa de quantos arquivos alheios casam o filtro é frágil por construção** — é a mesma
classe que esta fase já consertou no glob de `ConjuntoCompartilhadoTest`.

---

## 11. Campanha de mutação — 18 mutantes, **18 mortos, 0 sobreviventes**

Disciplina: ocorrência **travada ANTES** (recusa se ≠ 1), **linha conferida depois**, restauração por
**sha256 em `finally`** (o script do 186-04 crashou e deixou arquivo mutado no disco), e árvore
conferida por **`git diff HEAD` só dos meus arquivos** — nunca por `git status`, que aborta pelo `??`
de outra sessão.

| # | mutante | gate que reprovou |
|---|---|---|
| M1 | `margem` 12,3 → 7,3 (o estouro do +20738%) | `margem-com-folga` |
| M2 | frete `digitado` passa a nascer `Pendente` | `fin-07-origens-vivas` |
| M3 | embalagem volta a ANULAR o resultado | `excecao-da-embalagem` |
| M4 | `vigenteDe()` ignora `vigente` | `nulo-nunca-zero` |
| M5 | `informar()` SOBRESCREVE em vez de criar linha nova | `historico-nao-sobrescrita` |
| M6 | `informar()` deixa de incrementar a versão | `historico-nao-sobrescrita` |
| M7 | `originaisDe()` devolve vazio SEM o estado declarado | `estado-sem-original` |
| M8 | a relação volta a colidir com `$original` do Eloquent | `colisao-com-eloquent` |
| M9 | `criativo_id` ganha FK para `ml_anuncio_criativos` | `guarda-vinc-nomes-e-fks` |
| M10 | o bloco (2) "ESCRITA NAS FASES 192/194" sai do docblock | `guarda-vinc-blocos` |
| M11 | unique do par (imagem, original) vira índice comum | `vinculo-n-para-n` |
| M12 | unique do congelamento vira índice comum | `congelamento-unico` |
| M13 | FK `informado_por` perde o `->nullable()` (erro 1830) | `guarda-fin-nullable` |
| M14 | nome de índice estoura 64 (erro 1059) | `guarda-fin-nomes-64` |
| M15 | modelo aceita `incompleto` COM `resultado` | `incompleto-nulo` |
| M16 | `vigente` sai do `$attributes` | `defaults-em-memoria` |
| M17 | um id sai do manifesto | `bijecao-manifesto` |
| M18 | `ANULAM_O_RESULTADO` ganha chave fora das 7 linhas | `excecao-da-embalagem` |

**Restauração verificada:** `git diff HEAD --stat` nas duas migrations (já commitadas) sai **vazio**
— byte a byte idênticas ao HEAD depois da campanha.

### ⚠️ Os dois sobreviventes da primeira rodada, INVESTIGADOS

Nas duas vezes anteriores desta fase o sobrevivente era lacuna real. Desta vez, **um era lacuna real
e o outro era defeito do mutante** — e a distinção foi feita, não assumida:

- **M16 — LACUNA REAL.** O gate `defaults-em-memoria` lia `vigente` de uma linha criada por
  `informar()`, que passa **`'vigente' => true` EXPLICITAMENTE** no `create()`. A asserção passava
  pelo valor explícito e **nunca exercitava o default em memória**: apagar o `$attributes` do modelo
  não a fazia falhar. ➡️ Passou a ler num modelo construído **SEM o atributo** (`new
  PubAprovacaoFrete()` e `create()` sem `vigente`), que é o caso de quem fizer isso nos planos
  186-07/186-08. O gate **não foi afrouxado** — passou a medir o que afirmava medir. Contagem: 11 →
  **15** asserções.
- **M10 — defeito do MUTANTE.** A primeira redação trocava só o prefixo `"BLOCO (2) — "`, deixando o
  literal `ESCRITA NAS FASES 192/194` intacto: o gate estava certo e o mutante não mutava nada de
  relevante. Corrigido para apagar o literal → **morto**.

---

## 12. ⚠️ BUG REAL achado no GREEN — a colisão com a propriedade `$original` do Eloquent

A relação chamava-se **`original()`**, e `Illuminate\Database\Eloquent\Model` tem uma **propriedade
protegida `$original`** (os atributos originais do modelo — conferido por `ReflectionClass`, que
lista `original` entre as ~60 propriedades).

| de onde se lê | o que `$v->original` devolve |
|---|---|
| **de FORA** da classe | passa por `__get()` → **a relação** (funciona) |
| **de DENTRO** da classe | o PHP enxerga a **propriedade protegida** e **não chama `__get()`** → **o array cru de atributos da própria linha** |

➡️ **Medido:** `originaisDe()` — que faz o `map()` dentro do próprio modelo — devolvia as linhas de
`pub_imagem_referencias` **em formato array** em vez das `pub_imagens`, e o painel de comparação
mostraria a coisa errada.

⚠️ **O modo de falhar é o pior possível: um teste escrito de fora PASSAVA** (foi o que aconteceu —
duas sondas minhas discordaram, uma lendo de fora e outra de dentro) **enquanto o código de dentro
estava errado.**

**Conserto:** relação renomeada para **`imagemOriginal()`**, com o raciocínio no docblock do método,
e **gate novo** (`colisao-com-eloquent`) que varre as relações dos 3 modelos contra as propriedades
de `Eloquent\Model`, com isca nos dois sentidos (`original` **tem** de estar na lista; nome inventado
não pode), isca de varredura (≥5 relações vistas) e a **prova direta de dentro do modelo**
(`assertInstanceOf(PubImagem::class, ...)`). **Mutante M8** → **morto**.

---

## Desvios do plano

### Rule 3 — `migrate:rollback --step=1` destruiu a migration da sessão PARALELA

**Achado durante:** Task 1, verificação.
**O que aconteceu:** o `<automated>` da Task 1 manda `migrate:rollback --step=1`. Mas `--step=1`
rola de volta **o último BATCH global**, e o plano **186-05 roda em paralelo nesta mesma árvore**.
Resultado medido: meu `--step=1` derrubou **`2026_10_15_100300_create_pub_aprovacao_ajustes_tables`
(do 186-05)** — e, segundos depois, o `--step=1` **deles** derrubou a **minha**. As duas sessões
estavam rolando de volta o trabalho uma da outra.
**Conserto:** `migrate:rollback --batch=<meu batch> --path=<minha migration>`, que é preciso e
**não encosta em batch alheio**. ⚠️ `--path` **sozinho não basta**: ele filtra o último batch pelo
caminho e imprime *"Migration not found"* para o alheio, **sem rolar de volta a minha** (medido).
**Arquivos:** nenhum — mudança de procedimento. **Commit:** n/a.

### Rule 3 — a asserção de "todo commit do range é meu" era errada por construção

**Achado durante:** Task 3, GREEN. Já descrito em §10/§9. Gate reapontado para
*"todo commit que tocou UM DOS MEUS ARQUIVOS tem escopo 186-06"*, com isca que **exige** commit de
escopo alheio no range. **Commit:** `4cf921ea`.

### Rule 1 — a precisão decimal não é legível no SQLite

**Achado durante:** Task 3, GREEN. Já descrito em §4. **Commit:** `4cf921ea`.

### Rule 1 — colisão com `$original`

**Achado durante:** Task 3, GREEN. Já descrito em §12. **Commit:** `4cf921ea`.

### Desvio declarado — a isca do DADO-15 não usa `PubAprovacaoEvento`

Já descrito em §8: o modelo do 186-05 não existia no disco, e depender dele faria o gate oscilar com
o progresso da sessão paralela. A isca usa `PubProduto`/`PubRascunho`, cuja escrita foi **medida**.

---

## Divergências registradas (não "ajustadas")

| o que | esperado pelo orquestrador | MEDIDO | leitura |
|---|---|---|---|
| suíte PHP (Publicador) | 2163 / 16.808 / 0 | **2216 / 17.917 / 0** | **acima**, como previsto: meus 26 + os do 186-05 em paralelo. **0 falha.** |
| `pub_imagem_referencias_referencia_imagem_id_foreign` | "50 ou 51" (plano) | **51** (`strlen`) | plano com margem de erro de 1 — 2ª vez nesta fase |
| precisão decimal no SQLite | — | **`numeric`, sem precisão** | mudou a forma do gate da `margem` (§4) |
| `PubAprovacaoEvento` no disco | existia (plano) | **ausente** | 186-05 em paralelo; isca reapontada |
| `migrate:rollback --step=1` | seguro (plano) | **destrói batch alheio** | §Desvios |
| suíte JS | 4777 / 4776 pass / 1 fail | **não rodada** | este plano **não criou nem tocou arquivo JS** (diff por range vazio em `resources/js/`), e a única falha conhecida é a pré-existente `estrutura-grade-glide.test.js` (Fase 87), que **não foi tocada** |

### ⚠️ Fato observado na árvore compartilhada (não é meu, e não mexi)

Durante a janela em que meu `--step=1` e o do 186-05 se cruzaram, a tabela `migrations` ficou com
**DUAS linhas** para `2026_10_15_100300_create_pub_aprovacao_ajustes_tables`. É estado **local** do
banco de desenvolvimento, de migration **alheia**, e **não foi tocado** por mim. Fica registrado
para a sessão 186-05 conferir — e também porque o pedido de reaplicar a migration deles para reparar
o dano foi **bloqueado pelo classificador** (`[Modify Shared Resources]`), o que está correto: é
território deles.

---

## Known Stubs

Nenhum. As três tabelas e os três modelos estão completos para o que esta fase entrega.

⚠️ **`pub_imagem_referencias` nasce e permanece VAZIA de propósito** — isso **não é stub**: é a
decisão declarada de DADO-15 (a escrita é das **Fases 192/194**), provada por **ausência de escrita
no código** com isca de dois lados, e com a fase dona **nomeada** nos dois docblocks. Não há backfill
possível, e o passado é coberto pelo estado declarado de IMG-13.

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano. As três tabelas são internas
ao Publicador, atrás da mesma permissão de publicação; `linhas` (json) carrega custo do cliente, que
é a disposição **accept** já registrada em **T-186-24** (precedente medido em
`DadosEfetivosService::custosDoProduto()`).

⚠️ Registro para o 186-07: `EstruturaPrecificacaoParametros::daEmpresa()` devolve
`array_map('floatval', ...)` de **todas** as `PADROES`, inclusive o campo homônimo do nome proibido
por VOC-03 (`:26`/`:30`/`:37`). **Não repassar o array inteiro** para dentro de `linhas` —
**selecionar as chaves** (`comissao_classico`, `comissao_premium`, `imposto`,
`margem_contribuicao`). Está escrito nos docblocks da migration e do modelo.

---

## Critérios da fase fechados por este plano

| critério | estado | prova |
|---|---|---|
| **11** — o vínculo durável existe, é nosso, não se apoia no efêmero, e `app/Services/Creative/` tem diff vazio com a retenção intocada | **FECHADO** | §8, §9 — N:N durável em `pub_imagens`, diff **vazio por range** nas 5 peças + Support, retenção **48** por valor com isca |
| **8** — FIN-07 provado: há fonte de frete, e a linha nasce `Confirmado`/`Estimado` com a nota real | **FECHADO** | §7 — 5 casos, fonte chamada de verdade, nota nomeando a fonte lida do Support |
| **7** (parte de estrutura) — origem por linha persistida de forma auditável | **FECHADO** | §2 — `linhas` json com valor/origem/nota + congelamento por (`versao`, `momento`), unique |
| **9** (parte de frete) — confirmar frete incrementa a versão | **FECHADO** | §1 — `informar()` incrementa na mesma transação; mutante M6 morto |

---

## Para quem executar os próximos planos

- **186-07** — o serviço de composição deriva a origem viva e **congela no ato**. Já tem:
  `PubAprovacaoComposicao::linhaDeFrete()` (a linha de frete pronta, FIN-07),
  `ORIGEM_VIVA_DO_FRETE`, `ANULAM_O_RESULTADO` (as 5), `estimado_por_baixo` (a exceção da
  embalagem), `margem_minima` + `margem_minima_origem`. ⚠️ **Selecionar as chaves** de
  `EstruturaPrecificacaoParametros::daEmpresa()`, nunca repassar o array.
- **186-08** — a rotina geral das 8 mudanças relevantes. ⚠️ `PubAprovacaoFrete::informar()` tem o
  incremento da versão **para o caso do frete**; quando a rotina geral chegar, o caminho certo é
  `informar()` **DELEGAR** a ela, **nunca uma segunda cópia da regra** (está escrito no docblock).
- **190** — a tela de IMG-13. As constantes estão em `PubImagemReferencia` (`SEM_ORIGINAL`, rótulo,
  frase de apoio, ícone). ⚠️ Na estreia isso vale para **TODO o acervo**.
- **192 / 194** — **as donas da ESCRITA** de `pub_imagem_referencias`. ⚠️ Há gate que reprova se
  `app/` começar a escrever antes; ao ligar a escrita, **atualizar aquele gate junto**, com a isca.
- ⚠️ **Nunca** `migrate:rollback --step=1` nesta árvore. Use
  `--batch=<N> --path=<sua migration>`.
- ⚠️ **Nunca** nomear relação Eloquent de `original` (nem `attributes`, `casts`, `changes`,
  `previous`, `relations`, `table`, `with`…). Há gate.

---

## Self-Check: PASSED

- [x] `database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php` — FOUND
- [x] `database/migrations/2026_10_15_100500_create_pub_imagem_referencias_table.php` — FOUND
- [x] `app/Models/PubAprovacaoFrete.php` — FOUND
- [x] `app/Models/PubAprovacaoComposicao.php` — FOUND
- [x] `app/Models/PubImagemReferencia.php` — FOUND
- [x] `tests/Feature/Publicador/Aprovacao/FreteInformadoTest.php` — FOUND
- [x] `tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php` — FOUND
- [x] `tests/gates/contagens-186-06.txt` — FOUND
- [x] commit `26520d6f` — FOUND
- [x] commit `cd4e260c` — FOUND
- [x] commit `2c942992` — FOUND
- [x] commit `4cf921ea` — FOUND
