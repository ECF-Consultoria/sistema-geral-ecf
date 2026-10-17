---
phase: 187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela
plan: 08
subsystem: publicador / fila de aprovação
tags: [linha-da-oferta, 11-celulas, anel-de-progresso, barra-de-margem, pub-anel-parcial, render-adverso]
requires:
  - 186 (BloqueiosDaOfertaService::para/resumoDoRodape, ComposicaoFinanceiraService, ComposicaoDaOferta, VocabularioDoPortao, PubAprovacaoOferta::CTA, PubAprovacaoExcecao::vigentesDa, SeloDeAndamentoInterno.jsx)
  - 187-01 (o token `pub-anel-parcial` — aqui ele ACORDA)
  - 187-04 (a página e os slots nomeados)
  - 187-05 (ProximoARevisarService::rotuloDaEspera — a espera FORMATADA, fórmula pública)
  - 187-06 (FiltrosDaFilaService::aplicar — o `?oferta=` que dá FOCO)
  - 187-07 (GaleriaDaOfertaService, GrupoDaFilaService::montar com o slot `linhaDaOferta`, TabelaDaFila.jsx com o GRID exportado, QuadradosDeFoto.jsx)
  - 177 (identidade/identidade.js — `iniciais()` e `tomDoProduto()`, IDENT-08)
provides:
  - LinhaDaOfertaService (as 11 células de uma oferta, com rótulo e cor resolvidos no servidor)
  - LinhaDaOferta.jsx (a linha de 64px MÍNIMOS com as 11 células do grid)
  - AnelDeProgresso.jsx (os 4 estados, com `title` SEMPRE não-vazio)
  - BarraDeMargem.jsx (0 a 40% com clamp e o traço em `minimo/40`)
  - o slot `linhaDaOferta` da tabela PREENCHIDO na página
affects:
  - 187-09 (a barra de lote vai ligar `onMarcar`/`marcada`, hoje nomeados e ausentes)
  - 188 (o painel de revisão vai substituir o FOCO pelo `openPanel`; a foto da miniatura é lá)
  - 193 (a escrita no Mercado Livre — nada aqui publica)
tech-stack:
  added: []
  patterns:
    - o servidor decide QUAL CASO é (chave semântica); o `.jsx` traduz a chave em NOME DE TOKEN
    - handler de clique EXPORTADO como função pura, para o gate exercer o CLIQUE (render estático não dispara `onClick`)
    - percentual FORMATADO no servidor + guarda de FORMA FECHADA no JSX, com a largura por `style`
    - mapa fechado + `hasOwnProperty.call` depois de guarda de TIPO, nunca `obj[chaveDoServidor]`
    - gate de ausência ANCORADO no começo de classe (`min-h-[64px]` contém `h-[64px]`)
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php
    - resources/js/Components/Mlb/Publicador/aprovacao/LinhaDaOferta.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/AnelDeProgresso.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/BarraDeMargem.jsx
    - tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php
    - tests/js/publicador-fila-linha-187.test.js
    - tests/gates/contagens-187-08.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-tabela-187.test.js
    - tests/js/publicador-fila-tokens-187.test.js
    - tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
    - tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php
    - tests/gates/contagens-186-09.txt
decisions:
  - "O PHP manda CHAVE SEMÂNTICA de cor, nunca classe nem hex: o `content` do Tailwind não varre `.php`, e classe que só existisse lá não geraria CSS — bug invisível com todo teste passando"
  - "O anel NUNCA devolve `null`: guarda que devolvesse daria elemento vazio, que passa em render e DESAPARECE na tela; chave desconhecida cai no NEUTRO, nunca em `parcial`"
  - "D10 é prescrição de FÓRMULA (`minimo/40`), não do `30%` literal: o mínimo vem do parâmetro (FIN-09) e pode ser outro por empresa"
  - "O pior caso entre N variações é BALÃO (`title`), não frase visível: a coluna tem 92px e UMA linha (`:156`); a frase é da Fase 188"
  - "`responsavel.id` entra na célula 9 (Regra 1): sem ele o tom do avatar sairia do NOME e a mesma pessoa teria uma cor na linha e outra no popover de Responsável"
  - "O clique da linha e o CTA levam ao FOCO da oferta (`?oferta=`), o mesmo destino do 'Revisar agora' da faixa — o painel é a Fase 188 e o retry é a 193"
  - "`irParaOferta(...)` é chamado DE NOVO em vez de hoisteado: o gate da wave 5 lê o TEXTO da chamada na página"
  - "`font-[800]` e não `font-extrabold` nos dois lugares que o plano prescrevia: o GATE-03 proíbe a escala nomeada em ARQUIVOS_NOVOS"
  - "A asserção `doesNotMatch(/linhaDaOferta=/)` da wave 7 foi INVERTIDA para positiva: era ausência escrita sabendo que viraria falso positivo nesta wave (item 6 da régua)"
  - "A regex de `bg-pub-anel-parcial` da wave 1 prescrevia o hex em MINÚSCULA e sai em MAIÚSCULA: prescrição escrita às cegas porque o sub-teste PULAVA enquanto o token dormia"
  - "A camada B do andamento (Fase 186, EM PRODUÇÃO) acusava EXPOR como ESCREVER: a exceção entrou PROVADA (o expositor tem de casar a sonda larga e não pode ter forma de escrita) e o manifesto foi REMEDIDO, não ajustado — expansão DECLARADA de território"
metrics:
  tarefas: 3
  commits: 5
  ids_de_gate: 10
  mutacoes: "11 aplicadas, 11 mortas, 0 sobreviventes"
  completed: 2026-10-17
---

# Fase 187 Plano 08: A linha da oferta — Summary

A fila **ganhou as linhas**: as **11 células** de cada oferta saem resolvidas no servidor
(`LinhaDaOfertaService`, 796 linhas), e três componentes as desenham — a linha de **64px mínimos**
com o grid importado da wave 7, o **anel de progresso** que nunca sai vazio, e a **barra de margem**
de 0 a 40% com o traço do mínimo calculado por **fórmula**, não cravado.

---

## 0. ⚠️⚠️ O QUE EU HERDEI, E O QUE MUDEI NISSO

**Dois agentes caíram neste plano antes de mim** (um travamento e um erro de rede). O que chegou
**pronto e SEM COMMIT** na árvore, e o que fiz com cada coisa:

| artefato | estado herdado | o que mudei |
|---|---|---|
| `app/Services/.../Fila/LinhaDaOfertaService.php` | `??` — **786 linhas, completo e correto** | **+10 linhas:** a chave `responsavel.id` (desvio Regra 1, §6). **Nada mais.** |
| `tests/Unit/.../LinhaDaOfertaServiceTest.php` | `??` — **15 métodos `*_187_08`**, 1388 linhas | `PARTIDA` remedida `07d87a56` → **`44d0a364`**; **DOIS** caminhos saíram do `$proibidos`, cada um com a razão escrita (§11). **Nenhuma asserção alterada.** |
| `app/Http/Controllers/MlbPublicadorAprovacaoController.php` | ` M` — **+97**, a fiação completa | **nada** |
| `tests/Feature/.../FilaDeAprovacaoRotaTest.php` | ` M` — **+24**, a isca da wave 2 corrigida | **nada** |
| `tests/gates/contagens-187-08.txt` | **AUSENTE** | **criado** — 306 linhas, 10 ids, **todos MEDIDOS** (§10) |
| `resources/js/.../LinhaDaOferta.jsx` | **AUSENTE** | **criado** — 666 linhas |
| `resources/js/.../AnelDeProgresso.jsx` | **AUSENTE** | **criado** — 163 linhas |
| `resources/js/.../BarraDeMargem.jsx` | **AUSENTE** | **criado** — 209 linhas |
| `tests/js/publicador-fila-linha-187.test.js` | **AUSENTE** | **criado** — 1364 linhas, **23 testes** |
| `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` | intocado pela wave | **slot `linhaDaOferta` preenchido** |
| `tests/js/publicador-vocabulario-novo.test.js` | intocado pela wave | **+3 em `ARQUIVOS_NOVOS`** |
| `tests/js/publicador-fila-tabela-187.test.js` | intocado pela wave | **1 asserção INVERTIDA** (§11) |
| `tests/js/publicador-fila-tokens-187.test.js` | intocado pela wave | **1 regex corrigida por MEDIÇÃO** (§11) |

⚠️ **O único vermelho herdado era o próprio gate de bijeção** reclamando que
`tests/gates/contagens-187-08.txt` não existia (`assertFileExists`,
`LinhaDaOfertaServiceTest:1341`). Medido na partida: **1 failed, 37 passed (543 assertions)** nos
dois arquivos PHP — nada mais estava vermelho. Por isso **escrever o manifesto foi a PRIMEIRA ação
desta sessão**, antes de uma linha de JSX, e o **lote PHP verde foi commitado** (`a893a1e0`) antes de
o JSX começar.

### ⚠️ `test_metodo_que_ninguem_escreveu_187_08` — É ISCA, e foi LIDA antes de concluir

Ele **não é método**: é a **string** do último `assert` do gate de bijeção
(`LinhaDaOfertaServiceTest:1341`), que exige que o filtro do **PRIMEIRO id `php`** do manifesto
**não** case um nome que ninguém escreveu. Sem ela, um filtro `.*` (ou um id com alternância aberta)
casaria qualquer coisa e a bijeção passaria medindo nada. **Respeitada, não removida** — e é por isso
que o primeiro id do manifesto (`anel-e-meta`) é uma alternância **ancorada e fechada**.

---

## 1. As baselines, REMEDIDAS neste HEAD

**Referência do plano 187-07:** PHP **2430 / 21.239 / 0** · JS **5354 / 5349 pass / 1 fail / 4 skip**.
Remedidas nesta sessão, **não ajustadas**:

| suíte | referência (187-07) | **MEDIDO depois deste plano** |
|---|---|---|
| PHP (`tests/Unit/Publicador` + `tests/Feature/Publicador`) | 2430 / 21.239 / **0** falhas | **2445 / 21.463 / 2** falhas ⚠️ |
| JS (`tests/js/**`) | 5354 / 5349 pass / 1 fail / 4 skip | **5409 / 5406 pass / 1 fail / 2 skipped** |

⚠️ A **única** falha JS é a **PRÉ-EXISTENTE** de `tests/js/estrutura-grade-glide.test.js`
(*"Características secundárias nasce recolhido"*, Fase 87) — **não corrigida**, e **não** existe
`REF_PHP_FALHAS`. O `--suite js` saiu com **exit 0**: nenhuma regressão.

### ⚠️⚠️ As DUAS falhas PHP — medidas, registradas, e NENHUMA é minha

A primeira medição (`2445 / 21.446 / **3**`) trouxe três. **Uma era minha e foi consertada**
(§15: a camada B do andamento acusando EXPOR como ESCREVER; depois do conserto a contagem de
asserções subiu para **21.463** porque a exceção virou **prova**). **As outras duas NÃO são deste
plano**, e isso foi medido, não suposto:

| caso | asserção | sintoma |
|---|---|---|
| `CockpitDaContaTest::publicacoes criadas mede as duas janelas e a serie de trinta dias` | `:966` `assertSame(12, $kpi['serie'][29], 'a última posição é HOJE')` | obteve **0** |
| `CockpitDaContaTest::evento sem data vai para o ultimo grupo nunca para o topo` | `:1318` compara a chave do 1º grupo | obteve **`'ontem'`** no lugar de `'hoje'` |

**A prova de que não são minhas:**
1. `git diff 44d0a364..HEAD --name-only | grep -i cockpit` → **vazio**. Nenhum dos 12 arquivos do
   escopo tem relação com o cockpit (§13).
2. Os últimos commits a tocar os dois arquivos são da **Fase 179**: `43fa4d4a` (12/10) e —
   significativo — **`0edc4462` (13/10, `test(179): ancora o relogio no caso de agrupamento da
   Atividade`)**: alguém **já teve** de ancorar o relógio em UM caso desse arquivo.
3. O arquivo usa **`now()` 52 vezes**, e as duas asserções que falham comparam contra **`'hoje'`** /
   `serie[29]` (a última posição de uma série que **termina hoje**).
4. As duas apareceram na **virada de 16 para 17/10** e **continuam** falhando depois dela — é
   fragilidade de **fronteira de dia**, não a corrida da meia-noite.

➡️ **NÃO CORRIGIDAS** (regra do escopo: só se conserta o que o próprio trabalho causou), e
registradas em
`.planning/phases/187-…/deferred-items.md` com o conserto certo para quem é dono (ancorar o relógio
nos dois casos, como `0edc4462` fez no terceiro — **sem mexer no serviço**, que está certo).

⚠️ **De onde vem o `+55` do JS, e por que o `skipped` CAIU de 4 para 2** — as duas coisas são a mesma:
- **+23** o gate novo (`publicador-fila-linha-187.test.js`);
- **+21** o GATE-03 (`publicador-vocabulario-novo.test.js` foi de **485** para **506**: ele faz ~7
  sub-testes por arquivo varrido × **3** arquivos novos);
- **+11** sub-testes que **passaram a ser REGISTRADOS**: bloco de CSS que cai no `contexto.skip()`
  **não registra** os sub-testes dele. Na medição da wave 7 havia build desatualizado em dois
  arquivos; com o build corrente eles rodam. É por isso que `skipped` caiu **4 → 2** — e os **2** que
  continuam pulando são **`pub-float` e `pub-float-line`** do `tokens-da-fila`, que são do **187-09**
  (§7).

⚠️ A régua do harness só reprova em **queda**: contagem acima da referência é esperada.

**Harness:** `sh tests/gates/verificar-testes.sh --autoteste` → **20 casos, 0 divergência. As duas
formas REPROVAM quando devem.**

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1..8   (contagem, falha, arquivo que não carregou, as 4 iscas do >=, a isca G1)
== PHP: shape ANSI REAL do Collision ==
  OK   caso 9..14  (passed, failed, error, asserções divergentes, No tests found, a isca do ANSI)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15, 16 ('187-01' resolve o arquivo certo; '01' e '186-01' no MESMO caminho)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17..20 (tests=0, tests= ausente, assertions ausente, id ausente)
---
AUTOTESTE: 20 casos, 0 divergencia.
```

---

## 2. ⚠️⚠️ A tabela FID-01, medida por SCRIPT

**DECLARO: medida por SCRIPT.** O varredor percorreu `:133`-`:177` do
**`design_handoff_publicador/Aprovacao.dc.html`** (1433 linhas) e devolveu, por linha, o `style`
inteiro + **`cursor`**, **`white-space`** e **`text-overflow`** em **colunas próprias**.

⚠️⚠️ **CITO O DIRETÓRIO:** há **duas** cópias do pacote de handoff com conteúdo **DIFERENTE**
(`design_handoff_publicador/` × `new_layout_publicador/`), e a segunda é a versão **ANTIGA** — a
ERRADA para esta fase. ⚠️ `design_handoff_publicador/` **não é versionado**: o único bloco do gate JS
que depende dele degrada para `skip` **explícito e nomeado**, nunca passa calado. **`test -e` roda em
cada um dos 9 caminhos citados** antes de qualquer leitura.

⚠️ **Mas os NÚMEROS de dinheiro NÃO saíram do protótipo:** as taxas de `fin()` (`:1064`-`:1066`) são
**ficção declarada** e a seção F do README é **prosa**. Todo esperado financeiro vem de `info()` /
`ComposicaoFinanceiraService` com `Http::fake` da tarifa real.

### As 11 células, com o literal de cada uma

| # | célula | protótipo (MEDIDO) | classe aplicada |
|---|---|---|---|
| 1 | checkbox | `:134` `16×16;border-radius:4px;padding:0;color:#0A0A0A;display:grid;place-items:center;font-size:10px;font-weight:800;cursor:pointer` | `grid h-[16px] w-[16px] cursor-pointer place-items-center rounded-pub-badge border p-0 text-[10px] font-[800] text-pub-page` |
| 2 | progresso | `:135` `16×16;border-radius:50%;color:#0A0A0A;display:grid;place-items:center;font-size:9.5px;font-weight:800` | `AnelDeProgresso` → `grid h-[16px] w-[16px] place-items-center rounded-full border-[1.5px] text-[9.5px] font-[800] text-pub-page` |
| 3 | oferta | `:136` `display:flex;align-items:center;gap:12px;min-width:0;padding-right:14px` | `flex min-w-0 items-center gap-[12px] pr-[14px]` |
| 3a | · miniatura | `:137` `position:relative;44×44;border-radius:8px;overflow:hidden;flex:none;background:#1A1A1A;color:#52525B` | `relative h-[44px] w-[44px] flex-none overflow-hidden rounded-[8px] bg-pub-hover-3 text-pub-txt-off` |
| 3b | · **coluna título+meta** | `:138` `display:flex;flex-direction:column;gap:4px;min-width:0` ⚠️ **OMITIDA no plano** | `flex min-w-0 flex-col gap-[4px]` |
| 3c | · título | `:139` `font-size:13.5px;font-weight:{600\|500};white-space:nowrap;overflow:hidden;text-overflow:ellipsis` | `truncate text-[13.5px]` + `font-semibold`\|`font-medium` |
| 3d | · meta | `:140` `display:flex;align-items:center;gap:6px;font-size:11.5px;color:#8A8A90;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0` | `flex min-w-0 items-center gap-[6px] truncate text-[11.5px] text-pub-txt-3` |
| 3e | · SKU + `·` | `:141` `font-family:'Geist Mono';font-size:11px` + `color:#3F3F46` | `font-pub-mono text-[11px]` + `text-pub-line-off` |
| 3f | · **wrapper de origem** | `:142` `display:flex;align-items:center;gap:4px` ⚠️ **OMITIDO no plano** | `flex items-center gap-[4px]` |
| 3g | · EXCEÇÃO | `:143` `font-size:10px;font-weight:700;letter-spacing:0.05em;padding:1px 5px;border-radius:4px;border:1px solid #3F3F46;color:#D4D4D8` | `rounded-pub-badge border border-pub-line-off px-[5px] py-[1px] text-[10px] font-bold tracking-[0.05em] text-pub-fase1` |
| 4 | **wrapper da modalidade** | `:147` `display:flex;flex-direction:column;gap:3px` ⚠️ **OMITIDO no plano** | `flex flex-col gap-[3px]` |
| 4a | · modalidade | `:148` `display:flex;align-items:center;gap:5px;font-size:13px;font-weight:{600\|400}` | `flex items-center gap-[5px] text-[13px]` + `font-semibold`\|`font-normal` |
| 4b | · losango | `:148` `7×7;background:#F4F4F5;transform:rotate(45deg);border-radius:1px` | `h-[7px] w-[7px] rotate-45 rounded-[1px] bg-pub-txt` |
| 4c | · formato | `:149` `font-size:11.5px;color:#8A8A90;white-space:nowrap` | `whitespace-nowrap text-[11.5px] text-pub-txt-3` |
| 5 | fotos | `:151` `display:flex;flex-direction:column;gap:5px` | `flex flex-col gap-[5px]` |
| 5a | · "N fotos · M IA" | `:152` `font-size:12.5px;color:#D4D4D8;white-space:nowrap` | `whitespace-nowrap text-[12.5px] text-pub-fase1` |
| 5b | · quadrados | `:153` `display:flex;gap:3px` + `12×12;border-radius:3px` | `QuadradosDeFoto tamanho={12}` (wave 7) |
| 5c | · resumo | `:154` `font-size:11.5px;white-space:nowrap` | `whitespace-nowrap text-[11.5px]` + a cor da chave |
| 6 | preço | `:156` `text-align:right;padding-right:14px;font-size:13.5px;font-weight:500;font-variant-numeric:tabular-nums` | `pr-[14px] text-right text-[13.5px] font-medium tabular-nums` |
| 7 | resultado | `:157` `display:flex;flex-direction:column;align-items:flex-end;gap:2px;padding-right:14px;font-variant-numeric:tabular-nums` | `flex flex-col items-end gap-[2px] pr-[14px] tabular-nums` |
| 7a | · valor | `:158` `font-size:13.5px;font-weight:600;white-space:nowrap` | `whitespace-nowrap text-[13.5px] font-semibold` |
| 7b | · sub | `:159` `font-size:11.5px` | `text-[11.5px]` |
| 8 | margem | `:161`-`:163` | `BarraDeMargem` (§5) |
| 9 | responsável | `:165` `display:flex;align-items:center;gap:8px;min-width:0` | `flex min-w-0 items-center gap-[8px]` |
| 9a | · avatar | `:166` `24×24;border-radius:50%;display:grid;place-items:center;font-size:9.5px;font-weight:600;flex:none` + `title` | `grid h-[24px] w-[24px] flex-none place-items-center rounded-full text-[9.5px] font-semibold` + `style` do tom |
| 9b | · **wrapper nome+espera** | `:167` `display:flex;flex-direction:column;gap:1px;min-width:0` ⚠️ **OMITIDO no plano** | `flex min-w-0 flex-col gap-[1px]` |
| 9c | · nome | `:167` `font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis` | `truncate text-[12.5px]` |
| 9d | · espera | `:167` `font-size:11.5px;white-space:nowrap` | `whitespace-nowrap text-[11.5px]` + a cor da chave |
| 10 | andamento | `:169` `display:flex;flex-direction:column;gap:4px;align-items:flex-start;min-width:0;padding-right:10px` | `flex min-w-0 flex-col items-start gap-[4px] pr-[10px]` |
| 10a | · pill | `:170`-`:172` | `SeloDeAndamentoInterno` (**Fase 186, SOMENTE LEITURA**) |
| 10b | · sub-linha | `:174` `font-size:11.5px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis` + `title` | `max-w-full truncate text-[11.5px]` + `title` do texto inteiro |
| 11 | ação | `:176` `display:flex;justify-content:flex-end` | `flex justify-end` |
| 11a | · botão | `:177` `white-space:nowrap;height:30px;padding:0 12px;border:1px solid …;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer` | `h-[30px] cursor-pointer whitespace-nowrap rounded-[8px] border px-[12px] text-[12.5px] font-semibold` |
| — | **a LINHA** | `:133` `display:grid;grid-template-columns:<as 11>;align-items:center;min-height:64px;padding:0 16px;border-top:1px solid #1A1A1A;cursor:pointer;transition:background .15s` + hover `#161616` | `grid <GRID_DAS_11_COLUNAS> min-h-[64px] cursor-pointer items-center border-t border-pub-hover-3 px-[16px] transition-colors duration-150 hover:bg-pub-hover-2` |

### ⚠️⚠️ `cursor` — COLUNA PRÓPRIA

**16 ocorrências de `cursor:` no recorte E1 (`:26`-`:205`), e exatamente UMA fora de `<button>`:**

| linha | valor | onde |
|---|---|---|
| **`:133`** | `pointer` | ⚠️⚠️ **a LINHA INTEIRA, num `<div>` — o ÚNICO fora de `<button>` em todo o E1** |
| `:134` | `pointer` | o checkbox da linha (`<button>`) |
| `:177` | `pointer` | o CTA (`<button>`) |
| `:46 :62 :73 :77 :80 :84 :93 :97 :104 :110 :130 :188 :200` | `pointer` | de outras waves, **todos** em `<button>` |

Os três que são meus estão aplicados: `cursor-pointer` na **raiz da linha**, no **checkbox** e no
**botão**.

### ⚠️⚠️ `white-space` — COLUNA PRÓPRIA

**11 linhas com `white-space:nowrap` em `:133`-`:177`, 13 declarações** (`:167` tem **duas** e
`:170` repete a **mesma duas vezes** — duplicação do próprio protótipo; `:170` é a pill, território
da Fase 186):

| linha | o que é | aplicado |
|---|---|---|
| `:139` | título | `truncate` (= `nowrap` + `overflow:hidden` + `ellipsis`) |
| `:140` | meta | `truncate` |
| `:149` | formato | `whitespace-nowrap` |
| `:152` | "N fotos · M IA" | `whitespace-nowrap` ⚠️ **OMITIDO na tabela do plano** |
| `:154` | resumo das fotos | `whitespace-nowrap` ⚠️ **OMITIDO na tabela do plano** |
| `:158` | valor do resultado | `whitespace-nowrap` ⚠️ **OMITIDO na tabela do plano** |
| `:167` (1ª) | nome do responsável | `truncate` |
| `:167` (2ª) | a espera | `whitespace-nowrap` |
| `:170` (×2) | a pill | `SeloDeAndamentoInterno` (Fase 186) |
| `:174` | sub-linha do andamento | `truncate` + `max-w-full` |
| `:177` | o botão do CTA | `whitespace-nowrap` |

### ⚠️ `text-overflow:ellipsis` — COLUNA PRÓPRIA

**4 ocorrências:** `:139` (título), `:140` (meta), `:167` (nome) e `:174` (sub-linha do andamento) —
**todas** aplicadas por `truncate`.

### ⚠️⚠️ Os DEZENOVE ponteiros DESLOCADOS do plano

O plano acerta até a célula 3; **a partir da miniatura ele erra quase tudo**. Reconferidos por `sed`
nesta sessão (coincidem com os do briefing):

| elemento | plano | **MEDIDO** |
|---|---|---|
| miniatura | `:138` | **`:137`** |
| título | `:141` | **`:139`** |
| meta | `:142` | **`:140`** |
| SKU | `:143` | **`:141`** |
| EXCEÇÃO | `:145` | **`:143`** |
| wrapper das fotos | `:152` | **`:151`** |
| "N fotos · M IA" | `:153` | **`:152`** |
| fila de quadrados | `:154` | **`:153`** |
| resumo das fotos | `:155` | **`:154`** |
| preço | `:157` | **`:156`** |
| resultado (wrapper) | `:158` | **`:157`** |
| margem (os três) | `:162`-`:164` | **`:161`-`:163`** |
| responsável (wrapper) | `:166` | **`:165`** |
| avatar | `:167` | **`:166`** |
| nome+espera | `:169` | **`:167`** |
| andamento (wrapper) | `:171` | **`:169`** |
| sub-linha | `:175` | **`:174`** |
| célula da ação | `:177` | **`:176`** |
| botão do CTA | `:178` | **`:177`** |

**Corretos no plano:** `:133`, `:134`, `:135`, `:136`, `:148`, `:149`, `:1241`-`:1247`, `:1263`.

⚠️ **E DOIS da tabela de `rowVm` também estavam deslocados**, medidos aqui: `prog` é **`:1229`** (o
plano diz `:1225`), `bg` é **`:1250`** (o plano diz `:1249`), e `stSub` começa em **`:1232`**, não
`:1230`.

### ⚠️ FID-02, FID-03, FID-04 e o viewBox

- **FID-02** — nenhuma justificativa da lista vetada foi usada. Onde o plano prescrevia
  `font-extrabold` (**duas** vezes: checkbox e anel), a casa escreve **`font-[800]`** — o MESMO peso
  computado, por fora da escala nomeada, porque o **GATE-03 PROÍBE** a escala nomeada em
  `ARQUIVOS_NOVOS`. É razão **medida**, não preferência, e já é o que `TabelaDaFila.jsx:141`,
  `CabecalhoDaFila.jsx:186` e `PopoverDeFiltro.jsx:181` fazem.
- **FID-03** — `text-[13.5px]`, `text-[12.5px]`, `text-[11.5px]`, `text-[9.5px]`; **nunca**
  `text-sm`. Os quatro conferidos no **CSS construído**, e há gate de ausência de
  `text-(xs|sm|base|lg)` com isca nas duas direções.
- **FID-04** — zero `ecf-bg`, `ecf-card`, `ecf-yellow`, `font-display`, `border-white/[…]` nos três
  arquivos novos.
- **viewBox** — **não se aplica**: esta wave não tem ícone de Lucide. O anel é um `<span>` com borda
  e o glifo (`✓`/`!`) é **texto** — não há `strokeWidth` para multiplicar por 1,5.

---

## 3. As 11 células do lado do SERVIDOR, com a regra de cada uma

| # | célula | chaves de saída | literal / regra |
|---|---|---|---|
| 1 | — | — | o checkbox é do **187-09**; sem `onMarcar` a célula é um `<span/>` e as 11 trilhas continuam |
| 2 | progresso | `progresso` | `aprovado\|publicando\|publicado → aprovada` · `falha → falha` · `(tocado \|\| ≠aguardando) && ≠preparando → parcial` · senão `nao_analisada`. ⚠️ **`preparando` cai em `nao_analisada`** |
| 3 | oferta | `titulo`, `titulo_forte`, `meta{sku,origem,versao,excecao,excecao_rotulo}` | peso 600 só na `nao_analisada`; SKU do combo com `-K{N}`; `EXCEÇÃO` por `PubAprovacaoExcecao::vigentesDa()` |
| 4 | modalidade | `modalidade{rotulo,premium,formato}` | `Combo ×N · N un.` ou `Unidade · 1 un.` (de `GrupoDaFilaService::PREFIXO_COMBO`/`UNIDADE`, **LIDOS**) |
| 5 | fotos | `fotos{resumo,quadrados,sub,sub_cor}` | `N fotos · M IA`; `N com alerta` (vermelho) / `N a conferir` (âmbar) / `Todas aprovadas` (verde) |
| 6 | preço | `preco{valor,titulo,ativos}` | **UMA** linha; `title` = `pior caso entre N variações · <chave>` **só** com `ativos > 1` |
| 7 | resultado | `resultado{valor,cor,sub,sub_cor,rotulo_longo}` | `Incompleto` (âmbar + `frete pendente`) / negativo (vermelho + `prejuízo`) / positivo (`estimado`) |
| 8 | margem | `margem{valor,cor,largura,barra,minimo,minimo_valor,rotulo_longo,fonte}` | `—` sem número; largura `min(40,max(0,m))/40`; traço `minimo/40`; `fonte` = *"com a tarifa real do Mercado Livre"* |
| 9 | responsável | `responsavel{id,nome,espera,espera_minutos,espera_cor}` | espera FORMATADA por `rotuloDaEspera()`; âmbar **estritamente** acima de 1440 min |
| 10 | andamento | `andamento{chave,sub,sub_cor}` | a cadeia de **7** casos de `:1232`-`:1239`; `resumoDoRodape()` **CHAMADO** (o `+N` conta os DEMAIS) |
| 11 | ação | `acao{rotulo,paleta}` | rótulo do mapa `PubAprovacaoOferta::CTA`; `Revisar` amarelo **só** com `ehProxima` |
| — | linha | `abre`, `fundo`, `id` | `abre` = `≠ preparando` (D9); `fundo` com a precedência aberta > foco > marcada > padrão |

⚠️ Os literais que a Fase 186 **já tem** são **CHAMADOS, nunca recopiados**: `resumoDoRodape()`,
`VocabularioDoPortao::INCOMPLETO`, `PubAprovacaoComposicao::ROTULO_RESULTADO`/`ROTULO_MARGEM`,
`PubAprovacaoOferta::cta()` e `ProximoARevisarService::rotuloDaEspera()`. O gate de território cobra
a presença de cada chamada **e a ausência** de `'Tentar de novo'`/`'Aguarde'` escritos à mão.
As frases que **não** existem na 186 nascem como **constantes deste arquivo** e o gate as cobra byte
a byte com os `·` (U+00B7) certos: `FALHA_SUB`, `APROVADO_SUB_PREFIXO`, `PUBLICANDO_SUB`,
`PUBLICADO_SUB_PADRAO`, `PRONTO_SUB`.

---

## 4. Os 4 estados do anel, o `title` de cada um, e a prova de que NENHUM sai vazio

| estado | glifo | borda / fundo (`:1241`) | token | `title` literal |
|---|---|---|---|---|
| `nao_analisada` | *(vazio)* | `1.5px solid #FACC15` / `transparent` | `border-pub-accent bg-transparent` | **"Ainda não analisada"** |
| `parcial` | *(vazio)* | `1.5px solid #A1A1AA` / `linear-gradient(90deg,#A1A1AA 50%,transparent 50%)` | `border-pub-txt-2` + **`bg-pub-anel-parcial`** | **"Revisão parcial salva"** |
| `aprovada` | `✓` | `1.5px solid #22C55E` / `#22C55E` | `border-pub-ok bg-pub-ok` | **"Aprovada"** |
| `falha` | `!` | `1.5px solid #EF4444` / `#EF4444` | `border-pub-danger bg-pub-danger` | **"Falha na publicação"** |
| *neutro* (desconhecido) | *(vazio)* | — | `border-pub-line-off bg-transparent` | **"Progresso não informado"** |

### ⚠️⚠️ A prova de que o anel NUNCA sai vazio

Em **dois dos quatro** estados o glifo é **string vazia** — o sinal deles é a BORDA e o FUNDO. Uma
guarda que devolvesse `null` produziria elemento **sem borda, sem fundo e sem texto**: ele **passa em
todo teste de render** (o HTML existe) e **DESAPARECE na tela**.

O gate exige, para **cada um dos 4 estados** e para **12 formas adversas** (`'nao_existe'`, `''`,
`'   '`, `null`, `undefined`, `42`, `NaN`, `{}`, `[]`, `{toString:null}`, `{hasOwnProperty:null}`,
`true`):

1. `html !== ''` — o anel desenhou **algo**;
2. existe elemento na árvore;
3. `typeof title === 'string' && title.trim() !== ''` — ⚠️ **`title=""` é um `title` AUSENTE**, e aí
   a cor viraria o único sinal;
4. a forma desconhecida cai no **NEUTRO** e **nunca** em `parcial` (que AFIRMARIA "revisão parcial
   salva" sobre uma oferta de que não se sabe nada);
5. `doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.

Mais: os **quatro `title` são DIFERENTES entre si** (`new Set(titulos).size === 4`) — dois estados
com o mesmo balão seriam indistinguíveis para quem não vê cor.

⚠️ **A COR NUNCA É O ÚNICO SINAL** (regra permanente): além da borda/fundo, o `title` nomeia o estado
por extenso **e** a célula 10 ao lado traz a pill com o rótulo **escrito**.

⚠️ **A ORDEM do ternário:** `aprovada` → `falha` → `parcial` → `nao_analisada`, com o
`&& ≠ preparando` no ramo do `parcial`. **Mutação 2** provou (§12).

---

## 5. A barra de margem: o traço do mínimo e a prova de que cor não é o único sinal

| peça | protótipo | aplicado |
|---|---|---|
| coluna | `:161` `flex-direction:column;gap:6px;padding-right:12px` | `flex flex-col gap-[6px] pr-[12px]` |
| percentual | `:162` `font-size:13px;font-weight:600;tabular-nums` | `text-[13px] font-semibold tabular-nums` + a cor da chave |
| trilha | `:163` `position:relative;height:4px;border-radius:2px;background:#1F1F1F` | `relative h-[4px] rounded-[2px] bg-pub-line-subtle` |
| preenchimento | `:163` `position:absolute;left:0;top:0;bottom:0;width:{{marginW}};border-radius:2px` | `absolute inset-y-0 left-0 rounded-[2px]` + **`style={{width}}`** |
| **traço do mínimo** | `:163` `position:absolute;left:30%;top:-3px;width:1.5px;height:10px;background:#71717A` | `absolute top-[-3px] h-[10px] w-[1.5px] bg-pub-txt-4` + **`style={{left}}`** |

### ⚠️⚠️ D10 — o traço é FÓRMULA, não o `30%` literal

O protótipo escreve `left:30%` **porque `MIN = 12`** (`:914`, **medido**) **e `12/40 = 30%`**. O
`left:30%` aparece **3 vezes** no arquivo (`:163` no E1, `:417` no E4, `:645` no E2).

➡️ `LinhaDaOfertaService::percentualDaBarra()` é **pública e estática** e calcula **a mesma fórmula**
para a largura do preenchimento **e** para a posição do traço: duas cópias divergiriam e a barra
passaria a discordar do próprio traço — e a discordância seria **invisível** (ninguém olha um traço
de 1,5px e desconfia).

**O gate prova as DUAS leituras:** com `minimo = 12` o traço sai em **`left:30%`**; com `minimo = 20`
ele sai em **`left:50%`**. E **`left-[30%]` não aparece** em `BarraDeMargem.jsx` (asserção de fonte).

### ⚠️ A cor NUNCA é o único sinal, e o clamp

- o **percentual vai ESCRITO** ao lado da barra, sempre;
- margem desconhecida sai **`—`** (U+2014), **nunca `0%`** — `0%` afirmaria margem zero, pela mesma
  razão que o resultado sai `Incompleto` em vez de `R$ 0,00` (README §D);
- a célula **INTEIRA aparece mesmo sem dado**: a coluna existe no grid de 11, e sumir com ela
  desalinharia o cabeçalho. Provado com **8 formas adversas** da prop inteira;
- os **dois mapas de cor são DISJUNTOS** no artefato (`#3F3F46/#EF4444/#F59E0B/#A1A1AA` na barra ×
  `#71717A/#F87171/#FBBF24/#F4F4F5` no número) — asserção de **interseção vazia**, porque unificar os
  dois mudaria as duas coisas;
- **clamp em DUAS portas:** o servidor aplica `min(40, max(0, m))/40`, e `percentualSeguro()` no JSX
  recusa `-5%`, `150%`, `95` (sem `%`), `NaN%`, `''`, `abc%`, `1e3%`, `35,5%` e **9 formas
  não-texto** — com **isca nas duas direções** (ela ACEITA `0%`, `30%`, `100%`, `35.5%`, `88.75%`).
  `width:"NaN%"` é CSS inválido: a barra desapareceria **sem erro**;
- **a largura vai por `style`**, nunca por classe: o preenchimento tem **zero** classes começando com
  `w-`, e nenhum arquivo do plano monta classe por concatenação (`` /`[^`]*-\[\$\{/ ``, com isca);
- **D4 / decisão 9:** o `title` da célula NOMEIA a fonte —
  *"Margem de contribuição estimada · com a tarifa real do Mercado Livre"*.
  `Fila/ResumoRapidoService` segue **intocado** e divergente **DE PROPÓSITO**.

---

## 6. ⚠️ `responsavel.id` — o ÚNICO desvio que acrescentei ao código herdado (Regra 1)

O avatar de 24px (`:166`) tem o tom computado por `tomDoProduto()` — a `tone(h)` do protótipo
(`:1050`), **fonte ÚNICA por IDENT-08**. O popover "Responsável" já a chama com a **CHAVE**, que é
`String(id)` (`PopoverDeFiltro:276` ← `FiltrosDaFila:279`).

➡️ O serviço herdado mandava **só o `nome`**. Derivar o tom do NOME faria **a mesma pessoa sair de
uma cor na linha e de outra no filtro** — duas afirmações diferentes sobre quem é quem, na mesma
tela. **+1 chave (`responsavel.id`), 10 linhas com a razão escrita no arquivo.**

⚠️ As **INICIAIS** continuam derivadas no `.jsx` por `iniciais()`: cunhá-las no PHP foi **medido como
segunda implementação divergente** na wave 6 (*"Ana de Souza Lima"* dava **AL** no PHP e **AS** no
módulo), e por isso a chave `iniciais` saiu do payload da árvore.

⚠️ **Nenhuma asserção do teste herdado foi alterada** — a chave nova não quebra nenhuma (nenhum caso
compara o array `responsavel` por igualdade) e **as contagens dos 9 ids `php` não mudaram**.

---

## 7. `pub-anel-parcial` ACORDOU — com o CSS conferido pelo JSON

| token | onde foi medido | estado |
|---|---|---|
| **`pub-anel-parcial`** | `tailwind.config.js:338`; gradiente de `:1241` | ⚠️ **ACORDOU nesta wave** |
| `pub-float` | config; barra de lote `:897` | ⚠️ **CONTINUA DORMINDO** (plano 187-09) |
| `pub-float-line` | config; toast `:905` | ⚠️ **CONTINUA DORMINDO** (plano 187-09) |

**A regra, lida do CSS construído** (manifest **pelo JSON**, `node` com script em ARQUIVO, nunca por
grep — o hash sai com HÍFEN e o grep ingênuo não casa; e **nunca heredoc**, que colapsa `\\` em `\`):

```
bg-pub-anel-parcial  =>  background-image:linear-gradient(90deg,#A1A1AA 50%,transparent 50%)
```

⚠️ Token de `backgroundImage` sai como `background-image` com o gradiente **literal** — ele **não**
vira `rgb()` decimal, que é a forma dos tokens de **cor sólida** (1ª armadilha do medidor). A isca lê
a camada para provar que o token **existe lá** com esse valor.

### ⚠️⚠️ A prova de que `pub-float`/`pub-float-line` continuam dormindo

Quatro asserções, e a última é a que torna as três primeiras honestas:

1. `regraDe('bg-pub-float') === null` — a regra **não existe** no CSS construído;
2. `regraDe('border-pub-float-line') === null`;
3. `doesNotMatch(TODAS_AS_FONTES, /pub-float/)` — **nenhum arquivo desta wave os usa**;
4. ⚠️ **ISCA:** os dois **EXISTEM na camada** (`float: '#1C1C1C'` e `'float-line': '#333333'` no
   `tailwind.config.js`) — então a ausência **não** é "token inexistente", é **token não usado**.

E o gate `tokens-da-fila` (wave 1) confirma do outro lado: ele os **PULA com razão NOMEADA**
(*"nenhum .jsx usa ... ainda — o plano 187-01 só CRIA o token"*), e são exatamente os **2 skipped**
que sobraram na suíte JS.

Usar token fora do lugar onde ele foi medido só para zerar um `skip` é o que a **wave 4 recusou, e
estava certa**: aqueles dois foram medidos na barra de lote e no toast, que são o **187-09**.

⚠️ `tailwind.config.js` está na lista de **PROIBIDOS** do gate de território: **nenhum token novo foi
criado aqui.**

### A geometria conferida no CSS CONSTRUÍDO (não só "está escrita")

`min-h-[64px]:64px` · `h-[16px]/w-[16px]:16px` · `h-[44px]/w-[44px]:44px` · `h-[24px]:24px` ·
`h-[30px]:30px` · `h-[12px]:12px` · `h-[7px]/w-[7px]:7px` · `h-[4px]:4px` · `h-[10px]:10px` ·
`w-[1.5px]:1.5px` · `top-[-3px]:-3px` · `border-[1.5px]:1.5px` · `rounded-[2px]/[8px]/[1px]` ·
`tracking-[0.05em]:.05em` (⚠️ o cssnano **come o zero da frente**) · `font-[800]:800` ·
`duration-150:.15s` · `text-[13.5px]/[12.5px]/[11.5px]/[9.5px]`.

**Os CINCO fundos de linha, distintos no artefato final:** `rgb(34 29 7)` · `rgb(26 26 26)` ·
`rgb(24 22 12)` · `rgb(17 17 17)` · `rgb(22 22 22)` — `new Set(...).size === 5`, a prova de que os
tons **não colapsaram** (FID-02). As cores semânticas da barra também:
`bg-pub-line-off` `rgb(63 63 70)` · `bg-pub-danger` `rgb(239 68 68)` · `bg-pub-warn`
`rgb(245 158 11)` · `bg-pub-txt-2` `rgb(161 161 170)`.

⚠️⚠️ **E a ISCA do bloco de CSS foi MEDIDA, não inventada:** `h-[64px]` e `left-[30%]` **EXISTEM** no
CSS construído (outros arquivos do projeto os usam), então **não servem** como isca de ausência ali —
usá-los daria "PRESENTE" e o gate reprovaria por um motivo que não é meu. As iscas são
`bg-pub-isca-187-08`, `text-[99.5px]`, `w-[997px]` e `h-[463px]`, as quatro **conferidas ausentes**, e
o **controle positivo** (`text-[12px]`) conferido **presente**. A ausência de `h-[64px]` e
`left-[30%]` é cobrada **na FONTE**, onde o invariante mora. (Também conferido: `duration-[220ms]`
**continua não compilando** e `duration-150` **compila** — a isca viva do learnings §2b.)

---

## 8. O render ADVERSO — e o CLIQUE, que render estático não exercita

**8 formas** × **53 campos** (15 de topo + 38 aninhados) = **424 renders**, mais **53** com o campo
**AUSENTE** (`delete`, que é diferente de `undefined` explícito em `hasOwnProperty`), mais a **prop
inteira** nas 8 formas, mais os **handlers** adversos, mais 5 casos nomeados (`quadrados` string,
quadrados com itens adversos, `acao` sem rótulo, `meta` vazia, só o `id`).

As formas: `{toString:null}` · `{hasOwnProperty:null}` · `{a:1}` (objeto comum) · `null` · `NaN` ·
`[1,2]` · `''` · `true`.

Para cada uma: **não LANÇA** no render, **não LANÇA no clique**, e
`doesNotMatch(/\[object Object\]|NaN|undefined|Invalid Date/)`.

⚠️⚠️ **O CLIQUE É EXERCIDO DE VERDADE, e foi para isso que `aoAbrir()` virou export.** A tela preta
da wave 6 foi **NO CLIQUE**, e `renderToStaticMarkup` **não dispara `onClick`** — sem uma função
exportada, aquele caminho ficaria **sem gate nenhum**. O gate chama `aoAbrir(linhaAdversa, espião)` e
`aoAbrir(linha, destinoAdverso)` nas **9** formas, e tem **ISCA do espião** (ele conta de verdade:
sem isso, "não chamou" poderia ser um espião quebrado e todos os casos passariam por engano).

⚠️ **A mensagem de asserção usa o NOME DA FORMA e o CAMINHO do campo** — nunca `String()` nem
`JSON.stringify()` do valor adverso, que **lançariam no próprio relatório** e trocariam a falha
informativa por um stack trace inútil.

⚠️ **ISCA DO INVENTÁRIO:** `CAMINHOS.length >= 50`. Com a lista vazia (ou com 3 campos) todo o laço
passaria medindo **NADA** — seria "gate sobre ZERO casos".
⚠️ **ISCA DA SONDA, nas duas direções:** ela PEGA `[object Object]` e `NaN`, **não** pega
`R$ 184,20`, e o **render PRISTINO não casa** (senão o laço inteiro seria ruído).

⚠️ **Zero `String(`, zero `JSON.stringify`, zero `obj[chaveDoServidor]`, zero `new Date(`** nos três
arquivos: toda leitura de mapa passa por `classeDe()`, que faz **guarda de TIPO antes** e
`Object.prototype.hasOwnProperty.call` — `hasOwnProperty` **próprio** LANÇA com
`{hasOwnProperty:null}`, e isso derrubou a página inteira em dois componentes deste projeto, pego
**só** pelo render adverso. ⚠️ **DATA POR REGEX, nunca `new Date()` no JSX**: a espera chega
FORMATADA do servidor.

⚠️ **Truthiness recusada em 5 lugares**, cada um com as formas `'sim'`/`1`/`{}`/`[]`: `titulo_forte`,
`modalidade.premium`, `meta.excecao`, `abre` e `marcada`. Pintar/afirmar por truthiness anunciaria
Premium, liberação ou abertura que ninguém concedeu.

⚠️ **Flag DENTRO do callback** no único `.map()` deste arquivo (os pedaços da meta) — o Rollup
elimina variável de escopo do componente usada ali, e o `ReferenceError` só aparece no bundle de
produção. ⚠️ **O índice NÃO é cobrado neste `.map()`, e é decisão medida:** o gate da wave 7 o cobra
**só** dos `.map()` sobre dado do SERVIDOR; este percorre a lista que `pedacosDaMeta()` acabou de
construir, com chave de uma lista **FECHADA** (`sku`/`origem`/`versao`/`excecao`). Cobrar aqui seria
gate mais largo que o invariante — e a wave 7 **já reprovou código certo assim**.

---

## 9. `ARQUIVOS_NOVOS` e o GATE-03 verde

Os **três** `.jsx` entraram em `ARQUIVOS_NOVOS` de `publicador-vocabulario-novo.test.js`. Sem isso o
**GATE-03 passaria POR OMISSÃO** para eles, e — como `DIR_APROV` está em `DIRETORIOS_FECHADOS` — o
`assert.deepEqual(noDisco, declarados)` **reprovaria na hora** (é o comportamento certo).

⚠️ **Declarar ANTES de o arquivo existir LANÇA** no carregamento do módulo (`FONTES` lê todos os
caminhos) — foi por isso que a wave 7 os deixou explicitamente **fora**, e por isso a declaração é
feita pela wave que **cria** o arquivo.

**Medido:** `sh tests/gates/verificar-testes.sh 187-01 vocabulario-tamanhos` → **506 testes / 506
pass / 0 fail / 0 skipped** (forma `>=443`). ⚠️ Ele **não** foi embutido em id composto nenhum deste
plano: a contagem dele **cresce por construção** (um sub-teste por arquivo varrido), e embuti-lo foi
exatamente o que fez a wave 5 reprovar **dois** ids de outras waves e exigiu consertar **quatro**
manifestos.

---

## 10. A bijeção nos DOIS sentidos, os 10 ids e o homônimo da Fase 186

### A bijeção

**15 métodos `*_187_08`**, **9 ids `php`** (+1 `js`), **nenhum método de fora e nenhum id órfão** —
provado por script no próprio arquivo de teste, com **UMA asserção por sentido** coletando infratores
(`assertSame([], $semId)` / `assertSame([], $orfaos)`) e a **isca** do nome que ninguém escreveu.

⚠️ A contagem desse id **não depende do número de linhas do manifesto**: as asserções são de forma
FIXA (`>= 4` linhas, `>= 8` métodos, dois `assertSame([])` e a isca). **Acrescentar o id `js` depois
NÃO mexeu no número** — medido **9 antes e 9 depois**.

| id | modo | tests / assertions |
|---|---|---|
| `anel-e-meta` | php | 2 / 20 |
| `dinheiro-da-linha` | php | 3 / 41 |
| `margem-e-preco` | php | 2 / 38 |
| `fotos-e-espera` | php | 2 / 21 |
| `andamento-e-acao` | php | 2 / 32 |
| `abertura-e-fundos` | php | 1 / 14 |
| `custo-da-linha` | php | 1 / 10 |
| `territorio-linha` | php | 1 / 28 |
| `bijecao-manifesto-08` | php | 1 / 9 |
| `linha-da-oferta-render` | js | **23** (pristino) / **14** (mutado) |

⚠️ **Nenhum id usa `>=`:** a contagem destes arquivos não cresce por construção, e a igualdade exata é
mais forte e é o padrão. ⚠️ **Nenhum id tem mais de TRÊS métodos.**

### ⚠️ Divergência declarada dos nomes de id que o plano prescreve

O plano escreve três: `linha-servico`, `anel-e-barra` e `linha-render`. **Nenhum dos três existe**, e
as razões são MEDIDAS:

1. **`linha-servico`** cobriria os **13** métodos de comportamento da tarefa 1 numa alternância só —
   e **alternância grande equivale a filtro de CLASSE**, que passa dos 120 s do shell deste ambiente e
   **o helper RECUSA** (medição do plano 186-07; é a mesma razão que quebrou o `filtros-servico` do
   187-06 em quatro ids e o 187-07 em sete). ➡️ **Sete** ids por assunto.
2. **`anel-e-barra` + `linha-render`** eram DOIS ids para o **MESMO arquivo** de gate JS. No modo `js`
   o harness roda o **arquivo inteiro**: dois ids dariam a mesma contagem duas vezes e **nenhum dos
   dois mediria a sua própria metade** — gate decorativo por construção. ➡️ **Um** id.

### ⚠️⚠️ A conferência de HOMÔNIMO contra a Fase 186

O `--filter` do PHPUnit é regex **NÃO ANCORADO**, e as duas fases numeram planos de 01 a 09+: **todo
`_0N` desta fase tem homônimo possível na 186**, que está **EM PRODUÇÃO** (deploy `a4c4187f`). Um
método meu que fosse **superstring** de um método da 186 faria o id daquela fase **SOMAR** contagens e
**reprovar o manifesto de uma fase em produção** — foi o que
`FechamentoDaFase186Test::os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos` pegou no plano
187-07.

**Conferido POR SCRIPT**, varrendo os **15** nomes deste arquivo contra **todo** `test_*` de
`tests/`, nas **DUAS direções** (o meu contém o alheio / o alheio contém o meu):

```
MEUS: 15
NENHUMA COLISAO
```

➡️ **Nenhum sufixo precisou ser trocado.** E, se precisasse, o conserto seria **sufixo no método
NOVO** — **nunca** elevar a contagem do manifesto alheio.

---

## 11. ⚠️⚠️ OS DOIS gates ALHEIOS que reprovavam o trabalho CERTO desta wave

Nos dois, o conserto certo era **do meu lado**: a wave 8 é exatamente a que preenche o slot e a que
acorda o token. Nenhuma régua foi afrouxada e nenhum número foi "ajustado para passar".

### (a) `publicador-fila-tabela-187.test.js:1009` — a asserção "ainda não"

```js
assert.doesNotMatch(FONTE_PAGINA, /linhaDaOferta=/, '`linhaDaOferta` é do plano 187-08');
```

⚠️ **Uma asserção de AUSÊNCIA cuja PRÓPRIA MENSAGEM nomeava a wave que a tornaria falsa.** É
literalmente o **item 6 da régua** — *"asserção vazia quando escrita que vira FALSO POSITIVO
depois"* — e a pergunta que ela deveria ter respondido é *"o desenho PROÍBE isso, ou eu só não vi
ainda?"*. O desenho **não** proibia: o slot nasceu **nomeado e vazio** no 187-07 exatamente para o
187-08 o preencher.

➡️ **Invertida para uma POSITIVA**, permanente e **estritamente mais forte**:

```js
assert.match(FONTE_PAGINA, /linhaDaOferta=\{/, 'a página deixou de preencher `linhaDaOferta` (plano 187-08)');
```

Sem ela, o slot poderia voltar a ficar vazio e **a fila perderia as linhas** — e nenhum outro gate
pegaria: os casos `:747` e `:765` daquele arquivo renderizam o **COMPONENTE**, não a página, e
continuam valendo intactos.

⚠️ **A contagem do id daquela wave NÃO muda** (uma asserção por uma): `tabela-niveis-1-e-2` medido
**27 / 27 pass / 0 fail** depois da inversão, com `contagens-187-07.txt` **INTOCADO**. **Conferido,
não suposto.**

### (b) `publicador-fila-tokens-187.test.js:151` — a regex prescrita ÀS CEGAS

A wave 1 prescrevia, para `bg-pub-anel-parcial`:

```js
// (iii) o gradiente sai com o hex em MINÚSCULA no CSS construído.
propriedade: /background-image:\s*linear-gradient\(90deg,#a1a1aa 50%,transparent 50%\)/,
```

**Sai em MAIÚSCULA.** Medido no CSS de verdade, com o token em uso por `AnelDeProgresso.jsx`:

```
bg-pub-anel-parcial  =>  background-image:linear-gradient(90deg,#A1A1AA 50%,transparent 50%)
```

⚠️ **Por que o erro ficou invisível:** enquanto **nenhum `.jsx`** usava a classe, o sub-teste
**PULAVA** (é o `sub.skip()` nomeado daquele arquivo), e a regex **nunca foi exercida**. Era
prescrição escrita **às cegas** — item 3 do learnings §3 (*"leia a forma REAL no CSS construído antes
de escrever a regex; prescrever às cegas foi a 8ª mentira do medidor desta milestone"*). O próprio
gate declara que **"ACORDA SOZINHO no plano que escrever a classe"**: é **por desenho** que a wave que
acorda o token confere (e conserta) a prescrição.

➡️ Corrigida para a forma **MEDIDA**, com `/i`: o que o gate prova é a **ESTRUTURA** do gradiente
(ângulo, as duas paradas em 50%, a cor), **não a CAIXA do hex** — que é detalhe do minificador e já
mudou de prescrição uma vez. Baixar para "qualquer `background-image`" é que seria afrouxar; isto
não é.

⚠️ **A contagem do id daquela wave NÃO muda** (`tests=16,9`): o sub-teste **já era registrado**, só
pulava, e `tests=` conta **testes, não skips**. Medido: **16 / 14 pass / 0 fail / 2 skipped**, e o
gate `tokens-da-fila` **PASSOU**. `contagens-187-01.txt` **INTOCADO**.

### O efeito nos dois `$proibidos`

Os dois arquivos **saíram** da lista de proibidos do meu gate de território, **cada um com a razão
escrita no próprio arquivo**. ⚠️ **A contagem daquele gate também não mudou:** o `test -e` dos ~40
caminhos é **UMA** asserção coletando fantasmas (não uma por caminho, que fazia a contagem crescer
junto com a lista — a lição do 187-07). **28 asserções antes e depois.**

### ⚠️ E o gate da wave 5 que me obrigou a NÃO hoistear

`publicador-fila-proximo-187.test.js:857` lê o **TEXTO**
`onRevisar={irParaOferta(empresaId, etapaAtiva)}` na fonte da página. ➡️
`irParaOferta(empresaId, etapaAtiva)` é chamado **de novo** para a linha, em vez de guardado numa
variável: a função não faz I/O (devolve um `router.get` fechado sobre os mesmos dois valores), então
são dois closures **idênticos** — nunca dois comportamentos. **Hoistear reprovaria aquele gate**, e a
wave 7 já registrou que o controlador tem o mesmo tipo de amarra (é por isso que `'faixa'` e
`'proximo_a_revisar'` ficam INLINE no payload).

---

## 12. A campanha de mutação — 11 aplicadas, 11 mortas, 0 sobreviventes

**Protocolo:** commit **ANTES** (`a893a1e0` e `d2a8913d`) · `cp` para o scratchpad · ocorrência
**travada em 1 ANTES** (recusa se ≠ 1) · **linha conferida DEPOIS** · restauração **DA CÓPIA** em
`trap EXIT` · `sha256` contra `git show HEAD:` · ⚠️⚠️ **`git checkout` NUNCA**.

| # | mutação | arquivo | gate | resultado |
|---|---|---|---|---|
| 1 | `min-h-[64px]` → `h-[64px]` | `LinhaDaOferta.jsx` | `linha-da-oferta-render` | **MORREU** |
| 2 | `preparando` virando `parcial` (tirar o `&& ≠ PREPARANDO`) | `LinhaDaOfertaService.php` | `anel-e-meta` | **MORREU** |
| 3 | tirar o peso 600 do título não analisado | `LinhaDaOfertaService.php` | `anel-e-meta` | **MORREU** |
| 4 | trocar a ordem dos fundos (marcada antes de aberta) | `LinhaDaOfertaService.php` | `abertura-e-fundos` | **MORREU** |
| 5 | tirar o clamp da barra de margem | `LinhaDaOfertaService.php` | `margem-e-preco` | **MORREU** |
| 6 | `1440` → `144` na fronteira da espera | `LinhaDaOfertaService.php` | `fotos-e-espera` | **MORREU** |
| 7 | `resumoDoRodape()` RECOPIADO (o `+N` desaparece) | `LinhaDaOfertaService.php` | `andamento-e-acao` | **MORREU** |
| 8 | `Revisar` amarelo em TODAS as linhas | `LinhaDaOfertaService.php` | `andamento-e-acao` | **MORREU** |
| 9 | `prejuízo` → `Incompleto` | `LinhaDaOfertaService.php` | `dinheiro-da-linha` | **MORREU** |
| 10 | o anel desconhecido devolvendo `null` | `AnelDeProgresso.jsx` | `linha-da-oferta-render` | **MORREU** |
| 11 | `bg-pub-anel-parcial` → `bg-pub-txt-2` (o vizinho) | `AnelDeProgresso.jsx` | `linha-da-oferta-render` | **MORREU** |

```
=== SOBREVIVENTES: 0
=== SHA256 contra git show HEAD: ===
ok  app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php
ok  resources/js/Components/Mlb/Publicador/aprovacao/LinhaDaOferta.jsx
ok  resources/js/Components/Mlb/Publicador/aprovacao/AnelDeProgresso.jsx
```

⚠️ **O `sha256` só é honesto com `tr -d '\r'` nos DOIS lados**: o disco é CRLF (autocrlf) e o objeto
do git é LF. Sem a normalização ele **divergiria sempre**, e a prova viraria ruído — gate que reprova
sempre é tão inútil quanto o que passa sempre. (É o mesmo falso positivo que a wave 7 registrou.)
Conferido também por `git diff HEAD` nos três arquivos: **vazio**.

### ⚠️ Os DOIS REGIMES do id JS — medidos, não precaução

A camada de CSS do gate novo é **UM `test()` com NOVE sub-testes**, e ela **PULA** quando o CSS
construído é mais ANTIGO que as fontes (`LinhaDaOferta.jsx`, `AnelDeProgresso.jsx`,
`BarraDeMargem.jsx`, `TabelaDaFila.jsx`, `Aprovacao.jsx`) — e **mutar qualquer uma delas a deixa mais
nova**. Medido com `touch` numa fonte:

- **pristino** (CSS mais novo): **23 testes / 23 pass / 0 fail / 0 skipped**
- **mutado** (fonte mais nova): **14 testes / 13 pass / 0 fail / 1 skipped**

Sem os dois regimes a campanha abortaria na 1ª execução ("BASE com contagem inesperada") ou marcaria
"SOBREVIVEU" por um motivo que **não é o código** (learnings §4 caso 11). Está escrito no manifesto.

### ⚠️ A FIXTURE QUE DISCRIMINA

Na wave 6 as duas sobreviventes **não** eram falha da asserção, eram da **FIXTURE**. Aqui a fixture
foi montada para discriminar: os **quatro** pedaços da meta preenchidos (para a ORDEM ser
mensurável), **Premium** (para o losango), margem **acima** do mínimo com `largura` **≠** `minimo`
(para o traço não coincidir com a barra e o `30%` não poder ser lido do lugar errado), responsável
**COM `id`** (para o tom do avatar), `acao.paleta` de **destaque** (a da oferta próxima), e quadrados
nos **três** estados de resumo.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `responsavel.id` faltava, e o avatar sairia de outra cor que o filtro**
- **Encontrado em:** Tarefa 3 (ao montar a célula 9)
- **Problema:** o serviço herdado mandava só o `nome`, e `tomDoProduto()` seria chamado com o nome —
  o popover "Responsável" a chama com `String(id)`. A mesma pessoa teria **uma cor na linha e outra
  no filtro**.
- **Conserto:** `+1 chave` (`responsavel.id`) com a razão escrita no arquivo.
- **Arquivo:** `app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php:628`
- **Commit:** `a893a1e0`

**2. [Rule 1 - Bug] a sonda `includes('h-[64px]')` reprovava o `min-h-[64px]` CERTO**
- **Encontrado em:** Tarefa 3 (1ª execução do gate novo)
- **Problema:** `min-h-[64px]` **contém** `h-[64px]` como substring. Mesma família que já mordeu este
  projeto quatro vezes (`z-3` em `z-30`, `/disabled/` em `disabled:opacity-40`).
- **Conserto NA RAIZ:** regex **ancorada no começo de classe** (`/(?:^|[\s'"(])h-\[64px\]/`) + **isca
  nas duas direções**. **Não** foi apagar a sonda (o conserto barato, que perderia o único gate da
  altura fixa).
- **Arquivo:** `tests/js/publicador-fila-linha-187.test.js:300`
- **Commit:** `d2a8913d`

**3. [Rule 1 - Bug] a varredura do mapa `CTA` abraçava o mapa de ANDAMENTOS do mesmo arquivo**
- **Encontrado em:** Tarefa 3 (1ª execução)
- **Problema:** `/self::\w+\s*=>\s*'([^']+)'/` no arquivo inteiro trouxe **8** rótulos ("Preparando",
  "Publicando", "Publicado", "Falha na publicação"…) em vez dos 4 do CTA. **Gate mais largo que o
  invariante**, e o conserto barato ("atualizar a lista esperada") faria o gate parar de medir o CTA e
  passar a medir o **inventário** do arquivo.
- **Conserto NA RAIZ:** recorte ao bloco `public const CTA = [ … ];`, com **isca de existência** e
  **isca de não-abraço** (`!blocoCta.includes('Preparando')`).
- **Arquivo:** `tests/js/publicador-fila-linha-187.test.js:527`
- **Commit:** `d2a8913d`

**4. [Rule 1 - Bug] a sonda `includes('w-[')` reprovava o `w-[1.5px]` LEGÍTIMO do traço**
- **Encontrado em:** Tarefa 2 (1ª execução)
- **Problema:** o traço do mínimo **tem** largura em classe (`w-[1.5px]`, `:163`); só o
  **preenchimento** é que não pode. Gate mais largo que o invariante.
- **Conserto NA RAIZ:** duas provas no lugar certo — (a) o **preenchimento** tem **zero** classes
  `w-*` (asserção sobre o ARTEFATO), e (b) nenhum arquivo monta classe por **concatenação**
  (`` /`[^`]*-\[\$\{/ ``), com isca nas duas direções.
- **Arquivo:** `tests/js/publicador-fila-linha-187.test.js:775`
- **Commit:** `d2a8913d`

**5. [Rule 3 - Blocking] a asserção "ainda não" da wave 7 reprovava o slot preenchido**
- **Problema/Conserto:** ver §11(a). Invertida para positiva; contagem do id alheio **inalterada**
  (27, conferido); o arquivo saiu do meu `$proibidos` com a razão escrita.
- **Commit:** `d2a8913d`

**6. [Rule 1 - Bug] a regex de `bg-pub-anel-parcial` da wave 1 prescrevia MINÚSCULA e sai MAIÚSCULA**
- **Problema/Conserto:** ver §11(b). Prescrição escrita **às cegas** porque o sub-teste pulava
  enquanto o token dormia; corrigida para a forma **medida**, com `/i`. Contagem do id alheio
  **inalterada** (`tests=16,9`, conferido).
- **Arquivo:** `tests/js/publicador-fila-tokens-187.test.js:151`
- **Commit:** `a0ebe1cc`

**7. [Rule 3 - Blocking] a camada B do andamento (Fase 186) acusava EXPOR como ESCREVER**
- **Encontrado em:** a medição da baseline PHP, no fechamento
- **Problema/Conserto:** ver §15. Falso positivo da família *"o gate é mais largo que o
  invariante"* (a 6ª da fase). A exceção entrou **PROVADA** (+6 asserções) e
  `contagens-186-09.txt` foi **REMEDIDO** (13→19 e 251→257), não ajustado.
- **⚠️ EXPANSÃO DECLARADA de território:** o plano não previa tocar gate da Fase 186.
- **Arquivos:** `tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php:808`,
  `tests/gates/contagens-186-09.txt`
- **Commit:** `37c3ebaf`

### Itens FORA do escopo, registrados e NÃO consertados

As **duas** falhas PHP de `CockpitDaContaTest` (Fase 179, dependentes do relógio) — ver §1 e
`deferred-items.md`. Regra do escopo: só se conserta o que o próprio trabalho causou.

### Desvio de FORMA do plano, declarado

- **Os ids do manifesto não são os três do plano** — ver §10.
- **`TabelaDaFila.jsx` NÃO foi alterado**, embora o plano o liste em `files_modified`: o slot
  `linhaDaOferta` **já nasceu** na wave 7, pronto e com gate próprio. Reescrevê-lo seria mexer em
  componente que está no ar sem necessidade.
- **`tests/js/publicador-vocabulario-novo.test.js` foi alterado** (os 3 arquivos em `ARQUIVOS_NOVOS`),
  o que o plano prevê; **`publicador-fila-tabela-187.test.js` e `publicador-fila-tokens-187.test.js`
  também**, o que ele **não** prevê — os dois estão em §11.
- **O checkbox da LINHA ficou nomeado e ausente.** O plano o descreve (`:134`) e ele está
  implementado com a geometria literal — mas **só é desenhado com `onMarcar`**, que é do **187-09**.
  Sem handler, a célula 1 é um `<span/>` e as 11 trilhas continuam. **Botão sem destino é D4
  violado**, e as duas direções estão medidas.
- **`AndamentoDaOfertaService` não foi tocado e não foi preciso entrar na camada A dele:** este
  serviço **não expõe** o andamento em chave nova nenhuma — ele repassa `andamento.chave`, que já
  existe no payload da fila desde a wave 2.

---

## Known Stubs

**Nenhum.** Varredura nos 3 arquivos criados e nos 2 alterados de produção, procurando `=[]`, `={}`,
`=null`, `=""` que cheguem ao render, e os literais "not available", "coming soon", "placeholder",
"TODO", "FIXME".

⚠️ **O que PARECE stub e não é:**

| peça | de quem é | o que acontece hoje |
|---|---|---|
| `onMarcar` / `marcada` | **187-09** | sem handler o checkbox **não é desenhado**; as duas direções medidas |
| a **miniatura** de 44px | **Fase 188** | no protótipo ela é um `image-slot` com **placeholder** (`:137`): a galeria da wave 7 manda `{id, estado, de_ia, com_alerta, rotulo, titulo}` e **não** URL. O que existe é o **QUADRO**, idêntico ao do protótipo; o acervo é território proibido (a v28 virou para o R2 em produção em 16/10) |
| a frase visível do **pior caso** | **Fase 188** | decisão do usuário de 15/10: a coluna tem 92px e UMA linha, então aqui é **BALÃO** (`title`). ⚠️ **Não é pendência — é decisão aplicada**, e o gate cobra as duas coisas (uma linha **e** o `title` com a chave da variante) |
| o **retry** de "Tentar de novo" | **Fase 193** | o rótulo vem do mapa `CTA` do modelo; o clique leva ao **FOCO da oferta** (destino real: a URL muda e a linha acende). A escrita no ML é daquela fase |

---

## Threat Flags

Nenhuma superfície de segurança nova fora do `<threat_model>` do plano.

| Threat ID | mitigação | prova |
|---|---|---|
| T-187-81 (célula de dinheiro que mente) | `Incompleto` quando falta dado (nunca zero); a **embalagem** é a exceção ÚNICA e `prejuízo`/`margem` **disparam** sobre o estimado por baixo; o **frete** anula no MESMO cenário (a isca que separa as duas) | `dinheiro-da-linha` (3 / 41) |
| T-187-82 (tela preta) | 8 formas × 53 campos + campo ausente + prop inteira + handlers + **o CLIQUE**; flag dentro do callback; guarda de tipo antes de todo `obj[chave]` | `linha-da-oferta-render` |
| T-187-83 (uma chamada ao ML por linha) | espião: `para()` **exatamente 1×** por oferta; HTTP no máximo 1 por trio distinto, com isca nas duas direções; **+** asserção de que o controlador escreve `para(` **uma única vez** | `custo-da-linha` (1 / 10) + §14 |
| T-187-84 ("lucro líquido") | gate de ausência **recortado à lista CONSTANTE** de 3 arquivos (`lucro_liquido` é campo legítimo do Portal; varrer o `app/` barrou um deploy na 186), com iscas nas duas direções | `linha-da-oferta-render` + `territorio-linha` |
| T-187-85 (`Revisar` amarelo em todas) | amarelo só com `ehProxima`; paleta desconhecida cai no **neutro**, nunca no amarelo; **mutação 8** | `andamento-e-acao` |
| T-187-86 (margem com a tarifa errada) | o `title` NOMEIA a fonte (*"com a tarifa real do Mercado Livre"*); `Fila/ResumoRapidoService` **intocado** e divergente DE PROPÓSITO | `margem-e-preco` + §5 |
| T-187-SC (npm/composer) | **nada instalado**; os 4 arquivos de dependência estão na lista de PROIBIDOS do gate de território | `territorio-linha` |

⚠️ **A fronteira desta tela, reafirmada:** ela **APROVA (e aprovar ENFILEIRA); ela NÃO publica.** A
escrita no Mercado Livre é da **Fase 193**. Neste plano **nada é escrito**: o serviço só LÊ, o JSX só
desenha, e o único efeito de clique é um `router.get` que muda a querystring.
⚠️ **Zero rede real:** `Http::preventStrayRequests()` + `Http::fake` com gravador em todo o arquivo de
teste PHP.

---

## 13. Território — o que foi medido como INTOCADO

Os commits do escopo `(187-08)` foram conferidos contra ~40 caminhos proibidos, **com `test -e` em
cada um antes** (caminho fantasma "provaria" território intocado medindo NADA — aconteceu sete vezes
nesta milestone) e com **isca de prefixo de segmento** (`app/Mcp` não casa `app/McpOutraCoisa.php`).

⚠️ **A partida é `44d0a364`, REMEDIDA** (antes `07d87a56`, que abraçava os quatro commits do 187-07).
O **autor NÃO discrimina** — as três sessões desta árvore commitam com o mesmo `user.name`: o
discriminador é o **ESCOPO**, com isca nas duas direções (não casa `(187-07)`, não casa
`fix(261016-mwe)`). ⚠️ A contagem é **28 com as duas partidas e com zero ou N commits no escopo** —
nenhuma asserção mora dentro de condicional, o `test -e` dos ~40 caminhos é **uma** asserção
coletando fantasmas, e a prova de "o range não está vazio" é **coletada**, nunca `return` antecipado.

**Os arquivos que os commits do escopo tocaram, e nenhum deles é proibido:**

```
app/Http/Controllers/MlbPublicadorAprovacaoController.php
app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php
resources/js/Components/Mlb/Publicador/aprovacao/AnelDeProgresso.jsx
resources/js/Components/Mlb/Publicador/aprovacao/BarraDeMargem.jsx
resources/js/Components/Mlb/Publicador/aprovacao/LinhaDaOferta.jsx
resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php
tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php
tests/gates/contagens-187-08.txt
tests/js/publicador-fila-linha-187.test.js
tests/js/publicador-fila-tabela-187.test.js   ← saiu do $proibidos, §11(a)
tests/js/publicador-fila-tokens-187.test.js   ← saiu do $proibidos, §11(b)
tests/js/publicador-vocabulario-novo.test.js
```

Intocados e medidos: os **9** de `app/Services/Publicador/Aprovacao/` fora de `Fila/` (incluindo
`AndamentoDaOfertaService`, a **única porta** do andamento, `BloqueiosDaOfertaService`,
`ComposicaoFinanceiraService`, `RevisaoDeImagemService` e `VocabularioDoPortao`) · os **5** de `Fila/`
das waves 2/5/6/7 (incluindo `GrupoDaFilaService`, que esta wave **não pode** editar — é por isso que
o slot nasceu lá) · os **11** componentes de `aprovacao/` já no ar · `publicacoes/` · `identidade/` ·
`Components/Publicador/Mesa/` · `AppLayout.jsx` · `Editor.jsx` · `app/Services/Creative/` ·
`Criativos/` · `ImagemAssetService.php` · `app/Support/Publicador` · `app/Mcp` ·
`app/Services/Portal` · os gates intocáveis/de outras waves (`estrutura-meus-anuncios.test.js` com
**D-11 e D-22**, `publicador-header-zindex-ordem.test.js`, `-filtros-187`, `-proximo-187`,
`-zindex-187`, `-cabecalho-187`) · `GrupoDaFilaServiceTest.php` · `contagens-187-07.txt` · os **4**
arquivos de dependência · `database/migrations` · `tailwind.config.js` · `.planning/ROADMAP.md` ·
`.planning/STATE.md` · `REQUIREMENTS-v27.md`.

⚠️ **Nenhuma migration** — esta fase não tem, e `artisan migrate` **não** foi rodado (há migration
pendente de outra milestone).
⚠️ `tests/Feature/CompanyPortfolioAccessTest.php` aparece `??` na árvore e **não é meu** — intocado.
⚠️ **Zero deploy, zero push, zero VPS, zero API real do ML.** ⚠️ **`pint` não foi rodado.**

---

## 14. A fiação do controlador — uma chamada por oferta

O controlador herdado liga o serviço pelo **slot** de `GrupoDaFilaService::montar()`, com `para()`
chamado **uma vez por oferta** da árvore (nunca uma por célula) — e o gate novo acrescenta a prova no
texto: `(fonte.match(/\$linhaDaOferta->para\(/g) || []).length === 1`.

Três detalhes herdados que estão **certos** e foram conferidos:

- **`'faixa'` e `'proximo_a_revisar'` ficam INLINE no payload** e o `grupos` é montado **depois**,
  lendo `$payload['proximo_a_revisar']['id']`. Hoistear `$proximo->para($base)` faria **duas**
  leituras de `BloqueiosDaOfertaService::para()` (uma requisição extra ao ML), abriria a chance de a
  faixa e a linha apontarem para ofertas **diferentes**, **e** reprovaria o gate da wave 5, que lê o
  texto daquelas duas chamadas.
- **`?oferta=`** é saneado com `is_string` + `ctype_digit` + `> 0` **antes** do `(int)`: `?oferta[]=x`
  entrega um ARRAY e `(int) []` emite `Array to string conversion`, que já foi **500** nesta tela
  (T-180-04-T). Valor inválido → `null` → **nenhuma** linha recebe foco, nunca a errada.
- **`aprovadoPor`** entrou no eager loading porque a sub-linha de `aprovado` diz
  *"aguardando publicação · &lt;quem&gt;"* (`:1234`) — sem isso, 30 linhas = 30 consultas só para
  aquela frase.

---

## 15. ⚠️⚠️ O TERCEIRO gate alheio — a camada B do andamento, e a EXPANSÃO DECLARADA de território

**Este é o item que o usuário pode querer vetar, e por isso vem com tudo medido.**

`FechamentoDaFase186Test::test_a_escrita_de_andamento_mora_num_lugar_so_em_app_inteiro_com_isca`
(**AND-09, camada B** — a que prova que **ninguém de fora ESCREVE** o andamento) acusou
`Fila/LinhaDaOfertaService.php` como **ESCRITOR**:

```
AND-09: a escrita de `andamento` passa SEMPRE pela única porta
Failed asserting that two arrays are identical.
+    0 => 'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
```

**Ele não escreve nada.** Ele **LÊ** a coluna e a **EXPÕE** numa chave de **SAÍDA** do payload:

```php
'andamento' => [
    'chave' => (string) $oferta->andamento,
    'sub'   => $this->subDoAndamento($oferta, $leitura),
    …
],
```

A sonda é `/'andamento'\s*=>|->andamento\s*=(?!=)/` e casa `'andamento' =>` em **qualquer** array —
inclusive o de saída. O **segundo filtro** (referenciar o portão) casa porque o serviço **recebe um
`PubAprovacaoOferta` por parâmetro**, como tem de receber. ➡️ **FALSO POSITIVO**, da **MESMA família**
do `app/Mcp/Shopee/LeitorShopee.php` que **barrou o deploy em 15/10**: *o gate é mais largo que o
invariante* — **SEXTA** ocorrência nesta fase.

### ⚠️ O que NÃO foi feito, e por quê

- **Renomear a chave de saída** para fugir da sonda (`'estado_interno' =>`, por exemplo) seria
  **dobrar a TELA para o gate**, contra a régua de **UM VOCABULÁRIO SÓ** com chaves pt-BR iguais nos
  dois lados (o `.jsx` lê `andamento.chave`). O briefing proíbe isso em letra.
- **Enfraquecer a sonda** ou **tirar o alcance de `app/` inteiro** — que é exatamente o valor deste
  gate: pegar escritor clandestino em qualquer canto.

### ➡️ O que foi feito: a exceção é PROVADA, não afirmada

Entrou uma lista de **EXPOSITORES** (hoje com **um** arquivo). ⚠️ `continue` por lista é justamente a
forma de afrouxar um gate **sem ninguém ver** — então as **6 asserções novas** exigem, para **cada**
expositor:

1. que ele **CASE a sonda larga** — senão a exceção **não tem sujeito** e é linha morta abrindo um
   buraco para o futuro;
2. que **NÃO contenha nenhuma forma de ESCRITA** (`->andamento =`, ou
   `update`/`updateOrCreate`/`create`/`fill`/`insert`/`forceFill` com a chave).

Mais **TRÊS iscas da sonda de escrita**, nas duas direções: ela **acha** a atribuição, **acha** o
`update`, e **NÃO** acusa a chave de **SAÍDA**. As duas primeiras são asserções **ÚNICAS coletando
infratores**, para a contagem não crescer junto com a lista (a lição do 187-07).

### O manifesto da 186, REMEDIDO

| id (`contagens-186-09.txt`) | antes | **MEDIDO depois** |
|---|---|---|
| `andamento-um-lugar-so` | 1 / 13 | **1 / 19** |
| `transversais` (composto, 12 métodos) | 12 / 251 | **12 / 257** |

⚠️ **A Fase 186 está EM PRODUÇÃO** (deploy `a4c4187f`) e o número **não foi "ajustado para passar"**:
foi **medido de novo**, uma invocação por id, **depois** de a mudança **ACRESCENTAR** prova. Os dois
ids rodados pelo harness: **PASSOU**.

### ⚠️ EXPANSÃO DECLARADA de território

O plano 187-08 **não previa** tocar gate da Fase 186, e `FechamentoDaFase186Test.php` /
`contagens-186-09.txt` **não estavam** na lista de proibidos do meu gate de território (por isso ele
não reprova) — mas **tocá-los é sair do território declarado**, e está registrado aqui e na mensagem
do commit `37c3ebaf`.

O briefing **autorizou a forma**: *"se precisar EXPOR o andamento numa chave de saída, ENTRE na lista
da camada A declarando que só EXPÕE e REMEÇA `contagens-186-04.txt`"*. A camada que de fato disparou
foi a **B** (`app/` inteiro, `contagens-186-09.txt`) e não a A — mesma invariante, mesmo remédio,
arquivo diferente. **A camada A (`unica-porta`, `contagens-186-04.txt`) NÃO precisou de mudança:**
ela mede dentro do diretório do portão e passou verde em todas as rodadas.

⚠️ **Se o usuário preferir o contrário**, o caminho alternativo é: reverter `37c3ebaf` e mudar a
chave de saída do payload — mas isso **renomeia vocabulário de tela para agradar a um gate**, e
arrasta `LinhaDaOferta.jsx`, o gate novo e o payload da fila.

---

## O que este plano NÃO fez, de propósito

- **A barra de lote, os toasts, os modais e os dois avisos de tela vazia** — são o **187-09**.
  **Nenhum provisório de nenhum deles.**
- **O painel de revisão** — **Fase 188**. O clique leva ao **FOCO**, que é destino real e já existe em
  produção pela faixa "PRÓXIMO A REVISAR".
- **O "Tentar de novo" de verdade** — **Fase 193**. Esta tela aprova; aprovar enfileira.
- **A foto na miniatura** — **Fase 188**; o acervo é território proibido (v28 → R2).
- **A frase visível do pior caso** — **Fase 188** (decisão do usuário de 15/10).
- **Nenhum token novo** — `pub-anel-parcial` já existia desde o 187-01; só **acordou**.
  `pub-float`/`pub-float-line` **continuam dormindo**, com gate provando dos dois lados.
- **Nenhuma tela renderizada conferida por mim:** ⚠️ o banco local **não tem empresa com `ml_token`**
  (a rota dá **404** aqui). **A conferência visual é do usuário, em produção, na conta 459.**

---

## Self-Check

```
FOUND: app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/LinhaDaOferta.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/AnelDeProgresso.jsx
FOUND: resources/js/Components/Mlb/Publicador/aprovacao/BarraDeMargem.jsx
FOUND: tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php
FOUND: tests/js/publicador-fila-linha-187.test.js
FOUND: tests/gates/contagens-187-08.txt
FOUND: a893a1e0  feat(187-08): as 11 celulas da linha da oferta resolvidas no servidor, e o manifesto que faltava
FOUND: d2a8913d  feat(187-08): a linha da oferta — as 11 celulas, o anel que nunca sai vazio e a barra de margem
FOUND: a0ebe1cc  fix(187-08): o gradiente do anel sai em MAIUSCULA — a prescricao da wave 1 foi escrita as cegas
FOUND: 37c3ebaf  fix(187-08): a camada B do andamento acusava EXPOR como ESCREVER — excecao PROVADA, nao afirmada
FOUND: .planning/phases/187-…/deferred-items.md
```

## Self-Check: PASSED
