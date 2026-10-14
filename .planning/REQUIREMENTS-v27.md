# REQUIREMENTS — Milestone v27.0: Aprovação humana antes de publicar

**Origem:** decisão da gestão da ECF, reunião de **14/10/2026**, nas palavras do usuário:

> *"uma das coisas de maior interesse é que o sistema funcione de forma autônoma para publicar mas que
> tenha uma aprovação antes de ir para ar"*

**Autoridade de aparência e estrutura:** `design_handoff_publicador/README-v2.md` (327 linhas,
`sha256 5905f17dcce91f53022dd98d38dcefa639c9b169a795d3e41cf85c76ed0169fa`).
**Autoridade de comportamento:** a classe `Component` no fim de
`design_handoff_publicador/Aprovacao.dc.html` (1.432 linhas,
`sha256 68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2`) — `fin()` (cálculo
financeiro), `info()` (os bloqueios) e as ações (`approve`, `approveImg`, `approveSafe`, `rejectImg`,
`moveImg`, `setMain`, `replaceImg`, `addOrig`, `regen`, `saveReview`, `bulkApprove`, `retry`,
`applyPrice`, `checkChange`, `submitAdjust`, `resolveComment`, `submitException`, `submitFreight`).
**Referência da seção A:** `design_handoff_publicador/Publicacoes v2.dc.html`
(`sha256 56e6c194b896151c360543adb0c46f07302e15099ce8508d4741ddb6893e841f`).

⚠️ **`support.js` e `image-slot.js` NÃO fazem parte da implementação** — o próprio README diz que são
runtime de protótipo. **Não foram lidos.**
⚠️ **`design_handoff_publicador/` é somente leitura e NÃO É VERSIONADA** (`git status` a mostra como
`??`). Gate que dependa de ler aqueles arquivos **não mede nada na máquina do outro dev** — todo gate
de fidelidade tem de degradar para `skip` explícito quando o protótipo não existir (GATE-10).

---

## O VALOR desta milestone — e por que ela vale mais que o acabamento da v26.0

**A automação que a gestão pediu JÁ EXISTE, pronta e DESLIGADA.**
`app/Services/Publicador/Criativos/CriativosAutomaticosService.php` (10/10/2026) tem a cadeia
completa — produto chega do Portal com ficha e fotos → título → Modelo → descrição → **criativos
automáticos** — com **sete** condições de segurança, travada em `publicador.criativos_auto.ativo`
(`config/publicador.php:217`, env `PUBLICADOR_CRIATIVOS_AUTO_ATIVO`, padrão `false`). O docblock diz
textualmente que ela espera *"depois que o dono do Creative Engine ajustar o lado dele"*.

➡️ **O portão de aprovação é o que torna seguro ligar aquela chave.** Hoje ligar significaria imagem
de IA ao ar sem conferência humana. Com o portão, significa imagem de IA entrando numa fila onde
alguém aprova foto por foto antes de publicar. **São peças da mesma decisão** — e é por isso que a
Fase 194 (ligar a automação) é o destino da milestone, não um apêndice.

**O usuário decidiu em 14/10 PAUSAR a v26.0:** a Fase 181 (painel de inspeção) e a Fase 183
(Histórico) ficam para depois. As Fases 177-180 estão **no ar e aprovadas**.

---

## Decisões travadas (D1..D14 — NÃO reabrir)

- **D1 — Reproduzir, não adaptar.** Herdada da v26.0 e **não negociável**: o placar das Fases 177-180
  é de **~146 divergências** entre a tabela FID-01 derivada e o protótipo, e **em UMA só** o
  protótipo estava errado. O protótipo ganha, sempre (FID-01).
- **D2 — Dois eixos, nunca misturados.** O **andamento interno** (8 estados da ECF) nunca aparece na
  lista de Publicadas; o **status do Mercado Livre** nunca aparece na fila de aprovação. O README é
  explícito. Isto é regra de dado, não de tela (AND-02).
- **D3 — "Em revisão" nunca aparece sem qualificar.** O status do ML passa a ser **"Em análise pelo
  Mercado Livre"** (curto: **"Em análise pelo ML"**), porque "revisão" ficou exclusivo do andamento
  interno da ECF (VOC-01).
- **D4 — Nada nasce decorativo.** Palavras do README: *"**Não presuma que essas automações já existem
  no backend.** Mapeie cada dado da UI para uma fonte real. Se não houver fonte, sinalize ao time e
  use estados de 'pendente/indisponível'. **Nunca assuma valor zero.**"* É a mesma régua D5/EXMP da
  v26.0, e o usuário já a exerceu **três vezes** na milestone anterior.
- **D5 — Reusar o Creative Engine, nunca reescrever.** As quatro peças da regeneração de UMA imagem
  **já existem** (medido em 14/10) — ver a seção IA. `app/Services/Creative/` é território do outro
  dev: necessidade ali **para e pergunta**.
- **D6 — Vocabulário financeiro travado:** "Resultado estimado por venda" ou "Margem de contribuição
  estimada". **NUNCA "lucro líquido"** (VOC-03).
- **D7 — Cor nunca é o único sinal.** Todo status tem **ícone e texto** (VOC-05). Combinada com a
  regra permanente do projeto: **evitar jargão na tela** (VOC-06).
- **D8 — As taxas do README são FICTÍCIAS e NÃO entram no código como literal.** O próprio README diz
  *"não são taxas oficiais do ML"*. Neste sistema a comissão e a tarifa fixa **vêm da API do Mercado
  Livre**, não de configuração — ver FIN-02. O **mínimo de margem (12%)** é o único número do README
  que de fato não tem casa hoje e precisa de configuração nova (FIN-09).
- **D9 — Fatia vertical, servidor antes do render.** Foi o que fez as Fases 177-180 funcionarem.
  Cada fase entrega servidor **e** tela do mesmo pedaço, e o **fechamento medido é plano próprio** —
  foi no fechamento que apareceram as divergências que ninguém mais viu.
- **D10 — Esta milestone TEM migrations.** São **11 estruturas novas** (a v26.0 declarou nenhuma).
  Toda migration obedece a disciplina MIG-01..09, medida em deploys quebrados deste projeto.
- **D11 (fronteira do gate D-11) — o D-11 vale para a LISTA; a FILA escreve.**
  `tests/js/estrutura-meus-anuncios.test.js` (linhas ~`:250`, `:256`, `:285` — identificar por
  **semântica**, nunca por número) prova que **a lista de Publicações nunca escreve no ML** e que a
  Nota ECF é **"de 86"**. ⚠️ **Esta milestone ESCREVE** (aprovar, regenerar, publicar). Isso **não é
  contradição**: o D-11 é um gate sobre os arquivos da lista, e a área nova vive em
  `aprovacao/`, com gate de escrita **próprio** (TERR-05, PUB-06). **O D-11 não é estendido à área
  nova e não é afrouxado na lista.**
- **D12 — A ordem da seção A é deliberada:** o **rename** (A2) vem na primeira fase, isolado; as
  **abas** (A1) vêm na fase que **cria a rota da fila**. Justificativa em ABA-05.
- **D13 — O editor, a Mesa, o Creative Engine, o Bling e o `MlPublicacaoService` não são tocados.**
  Ver TERR-01..04.
- **D14 — O usuário pode querer parar no meio.** Cada fase declara o que entrega de valor sozinha, e
  nenhuma fase deixa botão sem destino na sua fronteira.

---

## O que eu JÁ MEDI — não refazer

### 1. A seção A, medida por script (`diff` entre os dois protótipos)

São **5 hunks / 23 linhas**, e nada além disso:

| # | o que muda | literal |
|---|---|---|
| 1 | `<header>` ganha borda | `;border-bottom:1px solid #1F1F1F` |
| 2 | coluna do H1 | `gap:6px` → `gap:14px` |
| 3 | o **subtítulo sai** | sai `<span style="font-size:13.5px;color:#A1A1AA">Ofertas ativas e históricas desta empresa no Mercado Livre</span>` |
| 3b | entram **as duas abas** | `<div style="display:flex;gap:20px;margin-bottom:-1px">` + aba ativa (`height:40px;border-bottom:2px solid #FACC15;font-size:14px;font-weight:600`, contador `font-size:12px;color:#71717A`) + aba inativa (`border-bottom:2px solid transparent;font-weight:500;color:#8A8A90`, pill `min-width:22px;height:20px;padding:0 7px;border-radius:999px;background:#FACC15;color:#0A0A0A;font-size:11.5px;font-weight:700`) |
| 4 | `ST.pending` (linha 461) | `'Em revisão'` → `'Em análise pelo Mercado Livre'` |
| 5 | `tabsDef` (linha 534) | `'Em revisão'` → `'Em análise pelo ML'` |

**Onde o literal "Em revisão" vive hoje, conferido por `grep`:**
`resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx:31`
(`em_revisao: { rotulo: 'Em revisão', … }`) e
`resources/js/Components/Mlb/Publicador/publicacoes/AbasDeStatus.jsx` (os 5 rótulos). Em
`app/Services/Publicador/AcervoTriagemService.php` ele aparece só em **comentário** citando fala do
usuário — ⚠️ mexer ali é mexer no registro de uma decisão, não num rótulo.

### 2. A regeneração de UMA imagem com instrução e referências JÁ EXISTE

Medido em 14/10, **depois** de eu escrever o contrário no aviso de coordenação (retirei a pergunta):

| peça do desenho | o que já existe |
|---|---|
| gerar **uma** imagem | `app/Services/Creative/GeminiImageProvider.php` — `gerarImagem(CreativeGenerationRequest)` |
| instrução livre do operador | `app/Services/Creative/CreativePromptBuilder.php` — `paraSlot(…, int $regeneracao, ?string $ajusteOperador)` |
| permissão de regenerar | `app/Services/Creative/CreativePermissao.php` — `podeRegenerar(User)` |
| **fotos enviadas pelo usuário** | `app/Services/Creative/ReferenciaEfemeraService.php` — `guardar(MlAnuncioCriativo, array $arquivos)` |

➡️ **O modal "Gerar novamente com IA" é COMPOSIÇÃO do que existe, não serviço novo. CHAMAR, nunca
reimplementar** (IA-01).

### 3. O que mais se REUSA (não reescrever)

- `app/Services/Publicador/DadosEfetivosService.php::custosDoProduto()` (`:198-232`) — custo do
  produto e **percentual de imposto**, com a precedência "exceção da oferta vence o parâmetro da
  empresa", a mesma do lote.
- `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` — "Quanto você recebe".
- `app/Services/Publicador/Alavancas/AnaliseAlavancasService.php` — `margem {valor, percentual,
  custo, imposto_percentual}` com tarifa **cacheada** (1 h); `app/Services/Publicador/Alavancas/CustoDoAnuncioService.php`.
- ⚠️ `app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` — **o caminho real
  NÃO é `app/Services/Creative/`** (o aviso de coordenação dizia isso e estava errado). É território
  do outro dev **pelo assunto, não pelo diretório**: reusar, não reescrever.
- `app/Models/MlAnuncioCriativo.php` — `validacao_status` (`pendente`/`aprovada`/`reprovada`/
  `indisponivel`) e `status` (`pendente`/`rodando`/`pronto`/`aprovado`/`erro`).
- ✅ **A PONTE Publicador → Creative Engine JÁ EXISTE E É INDEXADA** (medido em 14/10/2026):
  `ml_anuncio_criativos.pub_imagem_id` → `pub_imagens.id`, nullable, `nullOnDelete`, índice
  `ml_criativos_pubimg_idx`, criada pela migration da Fase 165
  (`database/migrations/2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php`,
  bloco (e), `:97-103`) e preenchida por `PublicadorCriativoAprovacaoService:117` no momento em
  que a imagem de IA entra no rascunho do Publicador. ➡️ **De `pub_imagens.id` chega-se ao
  `MlAnuncioCriativo` por `pub_imagem_id`. NÃO planejar coluna nova para isso.** Imagem que veio
  de **upload** não tem criativo — e isso é correto: é exatamente o que o "N fotos · N geradas
  por IA" distingue.
- `app/Models/PubImagem.php`, `app/Models/PubImagemAtribuicao.php`, `app/Models/PubValidacao.php`.
- `app/Services/Publicador/Fila/FilaPublicacaoService.php` e `AgendadorDaFila.php` +
  `app/Models/PubFilaPublicacao.php` / `PubFilaPublicacaoItem.php`.
- Frete: `app/Services/Publicador/FreteParaAPrecificacaoService.php`,
  `app/Services/Publicador/FreteDoPublicadoService.php`, `app/Support/Publicador/ConferenciaDeFrete.php`,
  `App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf`.

### 4. ⚠️ O que NÃO EXISTE — e é o que esta milestone constrói (as 12 estruturas)

1. os **8 estados de andamento interno** (⚠️ `pub_rascunhos` tem **6** — `DRAFT`, `VALIDATED`,
   `PUBLISHING`, `PUBLISHED`, `PARTIALLY_PUBLISHED`, `FAILED` — e são de **PUBLICAÇÃO**, eixo
   diferente);
2. estado de aprovação **por imagem NA REVISÃO** (⚠️ `PubImagem` tem status de **UPLOAD**:
   `pending`/`uploaded`/`failed` — outra coisa);
3. **conjunto de fotos COMPARTILHADO** entre ofertas, com aprovação compartilhada;
4. **checklist de fidelidade** por conjunto + imagem (7 critérios do protótipo);
5. **defeitos marcados** (6 chips);
6. **ajuste/comentário com escopo** (`offer` / `set` / `product`) e destino;
7. **histórico por oferta** com 8 tipos de evento (`sys`, `ok`, `change`, `adjust`, `reject`,
   `error`, `exc`, `save`);
8. **`version` e `approvedVersion`** da oferta;
9. **exceção autorizada por gestor** (responsável + justificativa ≥ 10 caracteres);
10. **origem por linha financeira** (`Confirmado` / `Estimado` / `Pendente`) + nota de fonte;
11. **verificações automáticas com coordenadas x/y em %** na imagem (severidade `ok`/`med`/`high`);
12. ⚠️ **o vínculo DURÁVEL "esta imagem de IA nasceu DESTAS originais"** — ver §4b.

### 4b. ⚠️ A original de referência é DURÁVEL, mas o VÍNCULO com ela morre em 48 h

**Medido em 14/10/2026 — e muda a revisão detalhada.**

- `ml_anuncio_criativos.referencias` (JSON: path/mime/bytes/hash) é **o único lugar** onde esse
  vínculo está gravado hoje.
- Ele é **apagado duas vezes**: na aprovação (`MlbAnuncioController::criativoAprovar()`) e pela
  varredura diária `creative:limpar-referencias`
  (`app/Console/Commands/LimparReferenciasCriativos.php`), com janela padrão de **48 h**
  (`services.creative.retencao_referencias_horas`). O carimbo é `referencias_apagadas_em`, e o
  acessor que respeita isso é `referenciasVivas()`.
- ➡️ ⚠️ **A comparação lado a lado NÃO pode se apoiar em `referencias`.** Para re-revisão, para
  histórico, ou para qualquer imagem com mais de 48 h, a referência **não existe mais em disco**.
- ✅ **MAS as originais em si são DURÁVEIS, e são NOSSAS.**
  `app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php` (docblock, `:19-32`)
  diz textualmente: *"As fotos do rascunho são do cliente e continuam do rascunho — nada aqui move
  nem apaga `pub_imagens`. A CÓPIA em `creative-referencias/{token}/` é efêmera."*
- ➡️ **A original é uma linha de `pub_imagens` do próprio rascunho.** O que falta é gravar
  **duravelmente QUAL** linha de `pub_imagens` virou referência de qual imagem gerada — **dado
  novo NOSSO** (DADO-15), derivável no momento da geração a partir dos ids que `selecionarFotos()`
  já recebe. **Não exige nada do outro dev e não toca `app/Services/Creative/`.**
- ⚠️ **Na estreia, NENHUMA imagem antiga terá original**, porque hoje o vínculo não é guardado —
  daí o estado declarado de IMG-13, que é critério de sucesso da fase da revisão detalhada.

### 5. ⚠️ A tensão de GRANULARIDADE que o planejamento tem de resolver

- `app/Models/PubPublicacaoItem.php` = **um `POST /items`** = uma oferta
  (`listing_type_id` + `variante_chave`), com estados `PENDING → SENT → CREATED|FAILED|UNKNOWN` e a
  regra **RN-93** (`UNKNOWN` nunca é reenviado sem reconciliar pelo SKU).
- `app/Models/PubFilaPublicacaoItem.php` = **um PRODUTO** na fila (Clássico **+** Premium, todas as
  cores, numa publicação só) — e **já guarda um conceito de conferência**: `validacao_id`,
  `plano_hash`, `revisao`, `ciente`, e `precisa_revisar` quando o produto mudou depois.
- ⚠️ **O desenho trabalha por OFERTA** (Clássico e Premium têm andamento, preço, resultado e versão
  **separados**), enquanto a fila de publicação de hoje trabalha por **PRODUTO**.
  ➡️ **DADO-03 e PUB-02 obrigam o planejamento a resolver isso explicitamente** e a **não duplicar**
  `revisao`/`ciente`/`validacao_id`.

### 6. ⚠️ As taxas do README NÃO viram configuração — a fonte real é melhor

O README manda (exemplo fictício) Clássico 12%, Premium 17%, tarifa fixa 6,75 abaixo de R$ 79,
imposto 6%, margem mínima 12%. **Medido neste repositório:**

| linha | de onde sai HOJE |
|---|---|
| comissão + tarifa fixa | **API do Mercado Livre**: `GET /sites/MLB/listing_prices` — `AnaliseAlavancasService:212` e `EditorRascunhoService:412`, via `SimuladorVoceRecebe`. **Não é config: é o número real do ML, por categoria e por preço.** |
| imposto % | `EstruturaPrecificacaoParametros::daEmpresa($companyId)['imposto']`, com a **exceção da oferta vencendo** (`DadosEfetivosService:216-232`, `CustoDoAnuncioService:82`) |
| custo do produto | `DadosEfetivosService::custosDoProduto()` (Precificação do Portal / ERP) |
| frete do vendedor | ⚠️ **a mapear** — existem `FreteParaAPrecificacaoService`, `FreteDoPublicadoService`, `ConferenciaDeFrete` e `TabelaFreteEcf`; **antes de declarar "Pendente" o planejamento tem de provar que não há fonte** (FIN-07) |
| embalagem, outros | ⚠️ **a mapear**; sem fonte ⇒ `Pendente`, **nunca zero** |
| **margem mínima** | ⚠️ **NÃO EXISTE** — `grep -rn "margem_minima\|margemMinima"` em `app/` e `config/` voltou **vazio**. É o único número do README que precisa de configuração nova (FIN-09) |

### 7. Camadas, z-index e portal

O protótipo usa `z-index` **70** (painel), **90** (lightbox), **100** (modal) e **120** (toast).
⚠️ A ordem travada deste projeto é **filtros 10 < header 30 < menu lateral e painel 40**, provada por
`tests/js/publicador-header-zindex-ordem.test.js`. ⚠️ O `backdrop-filter` do header **cria containing
block para `position:fixed`** (medido na Fase 178) ⇒ overlay precisa de **portal**; e **o
renderizador de servidor do React NÃO renderiza portal** — a Fase 178 resolveu exportando as peças
internas como **named exports** para poder conferi-las (REND-05).

### 8. Baselines de 14/10/2026 (Fase 180 no ar)

- PHP `tests/{Unit,Feature}/Publicador`: **2103 testes / 16.034 asserções / 0 falhas** (~10 min).
- JS `npm run test:js`: **4482 / 4481 pass / 1 fail** — a falha é a pré-existente
  `tests/js/estrutura-grade-glide.test.js:122`. ⚠️ **NÃO corrigir.**
- `npm run build` obrigatório; manifest **pelo JSON** (`node --input-type=commonjs`), **nunca por
  grep** (o hash sai com hífen).

### 9. ⚠️ Como o usuário confere

O banco local **não tem empresa com `ml_token`** e `ml_acervo_itens` está **vazia**. ➡️ **Validação
visual é checkpoint humano, em produção, na conta 459**, e **nenhum executor pode afirmar ter visto
tela renderizada** (GATE-14).

---

## FID — Fidelidade ao protótipo (transversal, vale em TODAS as fases)

- **FID-01** — Cada plano traz a **tabela de conferência item a item** contra o `.dc.html` nas 20
  dimensões do README (largura, altura, grid, spacing, padding, gap, tipografia, tamanho, peso, raio,
  borda, sombra, cor, alinhamento, ícone, badge, tabela, estado, densidade, posição de CTA), com
  **valor do protótipo × valor implementado** lado a lado. Quando a tabela discordar do protótipo,
  **MEÇA no `.dc.html` e o protótipo GANHA**; a divergência vai na SUMMARY com `arquivo:linha`,
  confirmada pela **SEMÂNTICA**, nunca pelo número de linha.
- **FID-02** — O planejador **DECLARA como mediu**: *tabela derivada de SCRIPT* ou *de LEITURA*.
  Medido na v26.0: a de script acertou 10/10; as de leitura erraram ~146 vezes. Tabela de leitura
  obriga o executor a re-medir item a item.
- **FID-03** — A tabela não erra valor; erra por **OMISSÃO** e por **REFERÊNCIA**. `cursor:pointer`
  foi omitido **onze** vezes numa única fase. Cada plano conta quantas medidas a tabela tem e por que
  esse é o total.
- **FID-04** — **Lista FECHADA de justificativas NÃO aceitas:** *"ficou mais parecido com o resto do
  sistema"*, *"mantive o token `ecf-*`"*, *"o componente que já existe faz parecido"*, *"o Tailwind
  não tem esse passo"*.
- **FID-05** — Tipografia pelo valor exato: `text-[13.5px]`, **nunca** `text-sm`.
- **FID-06** — **Vetados** nos arquivos novos: `ecf-bg`, `ecf-card`, `ecf-yellow`, `font-display`,
  `border-white/[0.08]`.
- **FID-07** — **viewBox:** o protótipo usa `0 0 16 16` e o Lucide `0 0 24 24` ⇒ `strokeWidth × 1,5`.
- **FID-08** — Container e densidade: `max-width: 1600px`, `padding: 0 36px`, desktop-first com
  referência de **1440px** e `min-width: 1240px`; os tokens da camada `pub-*` da Fase 177 são
  reusados, **nenhum hex literal novo fora da camada de tokens**.

## VOC — Vocabulário e rótulos

- **VOC-01** — O status do ML "Em revisão" passa a **"Em análise pelo Mercado Livre"**; na aba de
  filtro, **"Em análise pelo ML"**. ⚠️ **"Em revisão" nunca aparece sem qualificar** se é da ECF ou
  do Mercado Livre — asserção negativa recortada ao corpo (o literal "Em revisão pela ECF" é
  legítimo e **contém** "Em revisão": a negativa cega quebraria a tela certa).
- **VOC-02** — Os 8 rótulos de andamento interno são literais: "Preparando", "Aguardando aprovação",
  "Em revisão pela ECF", "Ajustes solicitados", "Aprovado", "Publicando", "Falha na publicação",
  "Publicado".
- **VOC-03** — **"Resultado estimado por venda"** ou **"Margem de contribuição estimada"**.
  ⚠️ **"lucro líquido" é proibido na tela** — gate de ausência sobre os arquivos novos.
- **VOC-04** — Os selos de origem financeira são exatamente **"Confirmado" / "Estimado" /
  "Pendente"**, e o valor ausente mostra **"Pendente"**, nunca `R$ 0,00` nem `—` silencioso.
- **VOC-05** — **Cor nunca é o único sinal:** todo status, bloqueio e aviso financeiro tem **ícone +
  texto**. Gate: para cada mapa de status, o número de entradas com rótulo textual é igual ao número
  de entradas com cor.
- **VOC-06** — **Sem jargão sem explicação** (regra permanente do projeto): "equilíbrio",
  "composição", "exceção autorizada" e "verificação automática" aparecem com a frase de apoio que o
  protótipo traz, nunca sozinhos.
- **VOC-07** — `tests/js/publicador-vocabulario-novo.test.js` é **estendido**: o diretório novo
  (`Components/Mlb/Publicador/aprovacao/`) entra na lista de diretórios do **GATE-03 daquele arquivo**
  (`:230`) e **todos** os arquivos novos entram em `ARQUIVOS_NOVOS` (`:36`). ⚠️ **`ARQUIVOS_NOVOS` é
  constante de propósito** — arquivo novo fora dela faz o gate **passar por omissão**.
- **VOC-08** — Os 6 textos de toast são literais do README: "Aprovado — aguardando publicação";
  "Publicado e confirmado no Mercado Livre · MLB…" (**só** com MLB confirmado); "Revisão salva";
  "Ajuste solicitado · Encaminhado para: …"; "Foto reprovada e removida do conjunto final";
  "Gerando nova versão com IA".

## ABA — Seção A: as abas e o rename na tela de Publicações

- **ABA-01** — O `<header>` de Publicações ganha `border-bottom: 1px solid #1F1F1F`; a coluna do H1
  passa de `gap:6px` para `gap:14px`; **o subtítulo sai** e entram **duas abas** alinhadas à base,
  com `gap:20px` e `margin-bottom:-1px` (para a borda da aba ativa cobrir a linha do header), altura
  **40px** e fonte **14px**.
- **ABA-02** — **"Publicadas no Mercado Livre"** é a aba ativa nesta página (peso 600,
  `border-bottom: 2px solid #FACC15`), com contador simples em **12px / `#71717A`**.
  **"Aguardando aprovação"** é inativa (peso 500, `#8A8A90`, borda transparente), com contador em
  **pill amarela** (`#FACC15`, texto `#0A0A0A`, 11.5px/700, altura 20px, `min-width` 22px).
- **ABA-03** — O rename é **independente e seguro** e acontece na Fase 186: o rótulo de
  `identidade/SeloDeStatusDaPublicacao.jsx` e o da aba de status em `publicacoes/AbasDeStatus.jsx`.
  ⚠️ A chave de dado (`em_revisao`, `pending`) **não muda** — muda só o rótulo; qualquer URL ou filtro
  salvo continua funcionando (TERR-08).
- **ABA-04** — Nenhum outro lugar do sistema passa a exibir "Em revisão" cru: gate de ausência do
  literal nos arquivos de `publicacoes/`, `identidade/` e `aprovacao/`, com **isca** (um literal que
  existe de verdade) para provar que a sonda não está cega.
- **ABA-05** — ⚠️ **A aba "Aguardando aprovação" só nasce quando a rota da fila existir.** O
  contador vem dos estados internos `awaiting` + `ecf_review` + `changes` (contagem do **servidor**,
  nunca somada no cliente) e o clique **navega** para a rota nova. Antes da fila existir, a aba
  apontaria para o vazio — exatamente o que D4 proíbe. **Por isso ela entra na Fase 187**, junto da
  rota que ela abre.
- **ABA-06** — Rotas: `…/publicacoes` (lista, como hoje), `…/publicacoes/aprovacao` (fila) e
  `…/publicacoes/aprovacao/:offerId/:aba` (revisão detalhada). Busca, filtros, agrupamento por
  produto, painel de inspeção, ações em lote, atalhos e header global **continuam como estão**
  (seção A3 do README) — e **a lista de Publicações continua mostrando só ofertas que existem no
  Mercado Livre**: oferta em preparação ou em aprovação aparece **apenas** na área nova.

## AND — Andamento interno (os 8 estados)

- **AND-01** — Os 8 estados existem como constantes do modelo, com **transições declaradas**:
  `preparando → aguardando_aprovacao → (em_revisao_ecf | ajustes_solicitados) → aprovado →
  publicando → (publicado | falha)`, e `falha → publicando` ("Tentar de novo").
- **AND-02** — **Os dois eixos nunca se misturam** (D2): gate provando que nenhum rótulo de
  andamento interno aparece nos componentes de `publicacoes/` e que nenhum rótulo de status do ML
  aparece nos de `aprovacao/`.
- **AND-03** — Cores e dots por estado, literais do README/`STM`: Preparando `#71717A`/`#A1A1AA`
  (com spinner; **não abre revisão**) · Aguardando aprovação `#FACC15` · Em revisão pela ECF
  `#D4D4D8` · Ajustes solicitados `#F59E0B`/`#FBBF24` · Aprovado `#22C55E`/`#4ADE80`
  ("aguardando publicação") · Publicando `#22C55E`/`#4ADE80` (spinner; **edição bloqueada**) ·
  Falha na publicação `#EF4444`/`#F87171` (revisão **preservada**; "Tentar de novo") · Publicado
  `#22C55E` (**só com MLB confirmado pelo ML**).
- **AND-04** — `publicado` **exige MLB confirmado**: sem MLB o estado não avança e o toast de
  "Publicado e confirmado" **não dispara** (VOC-08).
- **AND-05** — `live` = `awaiting` ∪ `ecf_review` ∪ `changes` — é o único conjunto em que a oferta é
  editável e aprovável, e é a base do contador da aba (ABA-05) e do "x de N na fila".
- **AND-06** — Salvar revisão parcial move `awaiting → ecf_review` e registra no histórico
  "Salvou revisão · x de y fotos aprovadas" (`saveReview`).
- **AND-07** — Pedir ajuste move as ofertas afetadas para `changes`; **resolver o último ajuste
  aberto** devolve a oferta para `awaiting` (`submitAdjust` / `resolveComment`).
- **AND-08** — Ordenação da fila, literal de `ORD`: falha(0) → aguardando/em revisão(1) →
  ajustes(2) → aprovado(3) → publicando(4) → publicado(5) → preparando(6); dentro de cada grupo,
  **maior espera primeiro**.
- **AND-09** — "Publicado hoje" é um recorte **do dia**, não o estado inteiro — a faixa de andamento
  tem 9 cards: Todas + as 7 etapas internas filtráveis + "Publicado hoje".
- **AND-10** — Quem **não** está `live` não abre revisão para editar: `preparando` mostra "Aguarde",
  `aprovado`/`publicando`/`publicado` mostram "Ver", `falha` mostra "Tentar de novo"
  (literal de `cta`).

## DADO — As estruturas novas e as migrations

- **DADO-01** — ⚠️ **Esta é a primeira migration do redesenho.** Antes do código, cada migration traz
  no **docblock** a **tabela de decisão de schema**: coluna, tipo, nulabilidade, índice, FK, e **por
  que** (MIG-07).
- **DADO-02** — **Oferta em preparação**: produto, conjunto de imagens, modalidade
  (`classic`/`premium`), unidades (1 = Unidade, N = Combo ×N), título, preço salvo, origem
  (`auto`/`manual`), responsável, **data de entrada na fila** (base do tempo de espera), `version`,
  andamento interno, `approvedBy`/`approvedAt`/`approvedVersion`, exceção, erro de publicação e
  `mlb` (**só após confirmação**).
- **DADO-03** — ⚠️ **O planejamento DECIDE e REGISTRA** se a oferta é tabela nova ou projeção de
  `pub_rascunhos` × `listing_type_id`, e **como ela se liga** a `pub_fila_publicacao_itens` (que é
  por **produto** e já tem `validacao_id`/`plano_hash`/`revisao`/`ciente`/`precisa_revisar`).
  **Proibido duplicar** aqueles campos; proibido mudar a semântica deles.
- **DADO-04** — **Conjunto de imagens** (`imageSet`): galeria **ordenada**, foto principal e imagens
  fora da publicação. ⚠️ **Pode ser COMPARTILHADO por várias ofertas** — Clássico e Premium do mesmo
  produto e formato usam o mesmo conjunto, e **a aprovação das fotos vale para todas as ofertas
  dele**.
- **DADO-05** — **Revisão de imagem** por (conjunto + imagem): estado
  `pending`/`approved`/`rejected`/`regenerating`/`replaced`, quem, quando e motivo. ⚠️ **Não reusar
  `PubImagem.status`**, que é de **upload**. A imagem guarda também **tipo** (`original`/`ai`),
  **a foto original de referência** (para IA) e o **vínculo com variação**.
- **DADO-06** — **Verificações automáticas** por imagem: severidade `ok`/`med`/`high`, texto e
  **coordenadas x/y em %** para marcar o ponto. A severidade `≠ ok` em imagem `pending` é o que
  acende o alerta vermelho (e o `approveSafe` **não** aprova essas).
- **DADO-07** — **Checklist de fidelidade** por (conjunto + imagem), os 7 critérios literais:
  cor, formato, material, textura, proporções, **quantidade do kit (só em combos)**, acessórios e
  componentes — cada um `confere`/`diverge`.
- **DADO-08** — **Defeitos marcados**, os 6 chips literais: deformação, peça duplicada, peça ausente,
  texto ilegível, recorte ruim, elemento inventado pela IA.
- **DADO-09** — **Ajuste/comentário**: item, **escopo** (`offer` = só este anúncio · `set` = conjunto
  de fotos compartilhado · `product` = cadastro base, afeta todas as ofertas do produto), destino
  legível, texto, autor, data e estado aberto/resolvido.
- **DADO-10** — **Histórico por oferta**: quem, quando, o quê e o **tipo**, os 8 literais: `sys`,
  `ok`, `change`, `adjust`, `reject`, `error`, `exc`, `save`.
- **DADO-11** — **Exceção autorizada**: responsável (gestor), justificativa (≥ 10 caracteres), tipo
  (`loss`/`margin`), data e quem pediu. Fica no histórico e é exibida como
  "§ Exceção autorizada por X · data · justificativa".
- **DADO-12** — **Frete do vendedor informado**: valor, **origem** (cotação da transportadora /
  tabela do cliente / simulador) e data; confirmar frete **incrementa a versão**.
- **DADO-13** — **Origem por linha financeira** persistida ou derivada de forma auditável:
  `Confirmado` / `Estimado` / `Pendente` + nota de fonte e atualização (ex.: "ERP Bling · atualizado
  11 out"). ⚠️ A nota **não é texto decorativo**: ela nomeia a fonte real (D4).
- **DADO-14** — Toda estrutura nova é **por conta/empresa** e respeita o recorte de permissão do
  módulo; nenhuma leitura cruza empresa.
- **DADO-15** — ⚠️ **O vínculo DURÁVEL imagem gerada → linha(s) de `pub_imagens` que serviram de
  referência** (§4b): gravado **no momento da geração**, nunca derivado de
  `ml_anuncio_criativos.referencias` (efêmero, 48 h). É a **12ª estrutura** da milestone e é
  **nossa** — `app/Services/Creative/` **não é tocado** (TERR-11). Aceita **mais de uma** original
  por imagem gerada e **sobrevive** à aprovação e à varredura de retenção. ⚠️ **Não há backfill
  possível para o acervo antigo** (o dado foi apagado): o passado fica em IMG-13.

## BLOQ — Bloqueios, aprovação e versão

- **BLOQ-01** — Os bloqueios são calculados **no servidor, por oferta**, e cada um tem **texto** e
  **ação de correção** mostrados perto do botão "Aprovar". São os 8 de `info()`:
  `photos` (há imagem do conjunto final não aprovada → "Conferir fotos", abre a **primeira com
  alerta** ou a primeira pendente) · `nophoto` (galeria vazia) · `freight` (frete do vendedor ausente
  → resultado **"Incompleto"** → "Informar frete") · `loss` (resultado < 0, sem exceção → "Solicitar
  exceção") · `margin` (margem < mínimo, sem exceção → "Solicitar exceção") · `change` (preço
  alterado depois da última conferência → "Conferir alteração", mostrando **de → para, quem e
  quando**) · `adjust` (ajuste em aberto de escopo `offer`, `set` ou `product`) · `title` (título com
  mais de 60 caracteres).
- **BLOQ-02** — ⚠️ **A ordem de `freight` → `loss` → `margin` é EXCLUSIVA e nesta ordem**
  (`if/else if/else if` em `info()`): faltando dado, **não existe** bloqueio de prejuízo nem de
  margem — porque **não há resultado**. **Custo ou frete ausente NUNCA vira zero.**
- **BLOQ-03** — Além dos 8, o conjunto `kinds` (usado pelo filtro "Motivo de bloqueio") inclui
  `alert` (há imagem pendente com alerta automático) e `failed` (falha na publicação), e `prep` para
  oferta em preparação.
- **BLOQ-04** — **Aprovar só é possível com ZERO bloqueios e andamento `live`** (AND-05). O botão
  fica `disabled=` enquanto houver bloqueio — gate com `/disabled=/`, **nunca** `/disabled/`.
- **BLOQ-05** — A aprovação fica **vinculada à `version`**: ela grava `approvedBy`, `approvedAt` e
  `approvedVersion`.
- **BLOQ-06** — **O que conta como mudança relevante** (incrementa `version` e, se a oferta estava
  `approved`, volta para `awaiting` com o registro "Aprovação reaberta: …"): reprovar, substituir,
  regenerar, **adicionar** ou **reordenar** imagem, **trocar a principal**, **salvar preço** e
  **confirmar frete**. ⚠️ A reabertura vale para **todas as ofertas do conjunto** quando a mudança é
  de foto (`reopen(D, set, …)`).
- **BLOQ-07** — "Conferir alteração" **não altera preço**: limpa o aviso e registra
  "Conferiu a alteração de preço da versão N" (`checkChange`).
- **BLOQ-08** — **Exceção autorizada libera só `loss` e `margin`** — nunca `photos`, `freight`,
  `change`, `adjust` nem `title`.
- **BLOQ-09** — ⚠️ No protótipo a exceção é aplicada direto; **no produto ela passa pelo fluxo de
  autorização do gestor**. O desenho de quem autoriza é **decisão do usuário** (ver "Decisões em
  aberto").
- **BLOQ-10** — **Lote**: o modal lista **todas** as ofertas selecionadas, marca com ✓ as aptas e com
  × as que ficam de fora **com o motivo**, e aprova **só as aptas, cada uma na versão indicada**.
- **BLOQ-11** — Uma **falha na publicação mantém `approvedVersion` e toda a revisão**; "Tentar de
  novo" muda direto para `publishing`.
- **BLOQ-12** — Sem bloqueio, o rodapé mostra a mensagem verde literal **"Fotos, preço e conteúdo
  conferidos"**; com bloqueio, o motivo em vermelho, com o primeiro bloqueio abreviado
  ("Bloqueado: frete pendente +1").

## FIN — Composição financeira

- **FIN-01** — As 7 linhas de `fin()`, cada uma com **valor, origem e nota de fonte**: custo do
  produto (**× unidades**; em Combo mostra "N un. × R$ x = R$ y"), comissão da modalidade, tarifa
  fixa por venda, frete pago pelo vendedor, impostos, embalagem e outros custos.
- **FIN-02** — ⚠️ **Comissão e tarifa fixa vêm da API do Mercado Livre** (`/sites/MLB/listing_prices`
  via `SimuladorVoceRecebe`), **não de literal nem de config**: os 12%/17%/6,75 do README são
  exemplo fictício declarado pelo próprio README. **Nenhum desses números aparece como literal no
  código novo.** Gate de ausência de `0.12`, `0.17` e `6.75` nos arquivos novos.
- **FIN-03** — **Imposto** vem de `EstruturaPrecificacaoParametros::daEmpresa()['imposto']` com a
  **exceção da oferta vencendo** — a mesma precedência do lote (`DadosEfetivosService:216-232`).
  Não se escreve conta de imposto nova.
- **FIN-04** — **Custo** vem de `DadosEfetivosService::custosDoProduto()`. O painel **nunca
  discorda** do card "Quanto você recebe" do editor para o mesmo preço.
- **FIN-05** — **`resultado estimado = preço − soma dos custos`, e é `null` se faltar QUALQUER item.**
  Com `null`, a tela mostra **"Incompleto"** em âmbar (nunca 0, nunca estimativa).
- **FIN-06** — `margem = resultado ÷ preço`; **`equilíbrio` e `preço para a margem m` só aparecem
  quando TODOS os dados existem** —
  `equilíbrio = (custo + frete + embalagem + outros + tarifa fixa) ÷ (1 − comissão − impostos)` e
  `preço p/ margem m = mesmo numerador ÷ (1 − comissão − impostos − m)`.
- **FIN-07** — ⚠️ **Antes de declarar o frete do vendedor "Pendente", o planejamento PROVA que não há
  fonte** — existem `FreteParaAPrecificacaoService`, `FreteDoPublicadoService`, `ConferenciaDeFrete`
  e `TabelaFreteEcf`. Se houver fonte, a linha nasce `Confirmado`/`Estimado` com a nota real.
- **FIN-08** — **O frete pago pelo COMPRADOR aparece como linha informativa "não entra"** — nunca
  somado.
- **FIN-09** — **A margem mínima é CONFIGURAÇÃO nova** (não existe no repositório hoje), com valor
  padrão 12%, exibida na tela como "ⓘ Margem mínima: **12%** · Toda oferta precisa de aprovação
  humana antes de publicar" — e **o número da tela vem da config**, não de literal no JSX.
- **FIN-10** — A barra de margem é escala **0 a 40%** com traço marcando o mínimo a **30% da barra**;
  cor `#EF4444` abaixo de 0, `#F59E0B` abaixo do mínimo, `#A1A1AA`/`#D4D4D8` acima — e a nota de
  texto ("acima/abaixo do mínimo de 12%", "prejuízo por venda", "dados incompletos") **sempre**
  acompanha (VOC-05).
- **FIN-11** — **Simulação de preço**: campo local que recalcula na hora e mostra resultado, margem e
  **diferença em relação ao preço salvo**, com o selo literal **"SIMULAÇÃO · NÃO SALVA"**. Atalhos
  preenchem o preço de **equilíbrio** e o preço para a **margem mínima**. "Salvar no anúncio" grava o
  preço, **incrementa a versão**, limpa o aviso de alteração e registra no histórico.
- **FIN-12** — A simulação **nunca** é persistida nem enviada ao ML; e **só pode ser salva** quando a
  oferta é `live`.
- **FIN-13** — **Comparação com a oferta irmã** (Clássico × Premium): preço, resultado, margem e
  status, clicável para trocar de oferta.
- **FIN-14** — Vocabulário (VOC-03) e **nunca "lucro líquido"**; a nota do combo é literal
  ("N un. × R$ x = R$ y").

## FILA — Tela "Aguardando aprovação" (E1)

- **FILA-01** — Ordem vertical com `gap: 16px`: cabeçalho (o da seção A, com a aba "Aguardando
  aprovação" **ativa**) → faixa de andamento → "PRÓXIMO A REVISAR" → filtros → tabela agrupada →
  rodapé de legenda.
- **FILA-02** — À direita do cabeçalho: "ⓘ Margem mínima: **12%** · Toda oferta precisa de aprovação
  humana antes de publicar" (o 12% vindo da config — FIN-09).
- **FILA-03** — **Faixa de andamento**: grid de **9 colunas**
  (`repeat(9, minmax(0,1fr))`, `gap:6px`), cada card com **dot**, rótulo de **11.5px** e número de
  **22px/600**; clicar **filtra**; o ativo tem fundo `#181818` e borda `#3F3F46`; contagem zero fica
  `#52525B`. ⚠️ As contagens são calculadas sobre os **demais** filtros aplicados (`base`), não sobre
  o conjunto já filtrado por etapa.
- **FILA-04** — **"PRÓXIMO A REVISAR"**: faixa com fundo `#14120A` e borda
  `rgba(250,204,21,0.35)`, com miniatura, título, "Modalidade · formato · aguardando há X ·
  responsável", estado ("Pronto para aprovar" em `#4ADE80` ou "N pendências" em `#FBBF24`) e o botão
  amarelo "Revisar agora". **A escolha é literal:** a oferta `awaiting` **não tocada** com maior
  espera; se não houver, a primeira `live` não tocada; se não houver, a primeira `live`.
- **FILA-05** — **Filtros**: busca (produto, título, SKU — **ignorando acento e caixa**, no
  servidor), segmentado de **Modalidade** (Todas | Clássico | Premium) e de **Formato** (Todos |
  Unidade | Combo); "Responsável" e "Motivo de bloqueio" abrem **popovers** com checkbox e
  **contagem**; com filtro ativo o botão fica com tint amarelo (borda `rgba(250,204,21,0.55)`, fundo
  `rgba(250,204,21,0.12)`). À direita, "Limpar filtros" e "N de M ofertas".
- **FILA-06** — As contagens dos popovers são sobre **todas** as ofertas (`all`), não sobre o
  recorte — é o que o protótipo faz e é o que torna o popover útil.
- **FILA-07** — **Tabela em 3 níveis**, com o grid literal
  `32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px` = checkbox · progresso ·
  Oferta · Modalidade · Fotos · Preço · Resultado/venda · Margem · Responsável · Andamento · ação.
  O cabeçalho tem **38px**, fundo `#0D0D0D`, 12px/500/`#71717A`.
- **FILA-08** — **Linha de produto** (52px, `#151515`): tile, nome + tag **PRODUTO**, SKU,
  "N ofertas" e "Cadastro completo · categoria e ficha técnica organizadas".
- **FILA-09** — **Sub-linha de conjunto de fotos** (38px, `#0F0F0F`, recuo de 62px): "Unidade" ou
  "Combo ×N" · "N fotos (N geradas por IA)"; **mini-quadrados de 10px por imagem** (aprovada = verde
  cheio `#22C55E`; a conferir = borda âmbar; com alerta = vermelho; regenerando = tracejado);
  "x/y aprovadas"; o pill **"Mesmas fotos em Clássico e Premium · aprovação compartilhada"** (só
  quando o conjunto tem mais de uma oferta) e o botão "Revisar fotos do conjunto".
- **FILA-10** — **Linha da oferta** (altura **mínima** de 64px): **Progresso** — anel de 16px, não
  analisada = borda amarela, parcial = meio preenchido cinza
  (`linear-gradient(90deg,#A1A1AA 50%,transparent 50%)`), aprovada = verde com ✓, falha = vermelho
  com ! — cada um com `title` literal.
- **FILA-11** — **Oferta**: miniatura de 44px, título (**peso 600 se não analisada**, 500 nos demais)
  e meta em **uma linha com ellipsis**: SKU · ⚡ Automática ou ✎ Manual · vN · EXCEÇÃO.
  **Modalidade**: losango ◆ para Premium (peso 600); abaixo, "Unidade · 1 un." ou "Combo ×N · N un.".
- **FILA-12** — **Fotos**: "N fotos · N IA", quadrados de **12px** e resumo colorido
  ("N com alerta" vermelho / "N a conferir" âmbar / "Todas aprovadas" verde).
  **Resultado/venda**: valor, ou **"Incompleto"** em âmbar com "frete pendente", ou negativo em
  vermelho com "prejuízo". **Margem**: percentual + barra de 4px (FIN-10), ou **"—"**.
- **FILA-13** — **Responsável**: avatar, nome e "há X" (**âmbar se passar de 24 h** — `waitMin >
  1440`). **Andamento**: pill de status + sub-linha com o primeiro bloqueio
  ("Bloqueado: frete pendente +1") ou o estado. **Ação**: "Revisar" (amarelo **só na oferta
  próxima**), "Tentar de novo", "Ver" ou "Aguarde" (AND-10).
- **FILA-14** — Fundos de linha distintos e literais: selecionada `#221D07`, foco `#1A1A1A`, marcada
  `#18160C`, padrão `#111111`, hover `#161616`.
- **FILA-15** — **Seleção em lote**: barra flutuante "N selecionadas · Aprovar K para publicação ·
  ×" que abre o modal de lote (BLOQ-10, MOD-11); o checkbox do cabeçalho marca **todas as visíveis**;
  oferta em `preparando` **não** entra na navegação nem abre painel.
- **FILA-16** — **Rodapé**: legenda dos anéis e dos quadrados. ⚠️ **"Restaurar dados de exemplo" do
  protótipo NÃO existe no produto** — é recurso de protótipo.

## REVS — Revisão simples: o painel de 1140px (E2)

- **REVS-01** — Painel **fixo à direita**, `width: min(1140px, 100vw − 100px)`, fundo `#0D0D0D`,
  borda esquerda `#2E2E2E`, sombra `-24px 0 64px rgba(0,0,0,0.6)`, backdrop `rgba(0,0,0,0.55)`.
  ⚠️ **Renderizado por PORTAL** (o `backdrop-filter` do header cria containing block) e com a camada
  mapeada para a ordem travada do projeto — **filtros < header(30) < painel**, provado pelo teste de
  z-index existente, **sem alterar o header** (REND-05).
- **REVS-02** — Fecha com **Esc** ou **clique fora**; **↑/↓** navegam pela fila (pulando
  `preparando`) e **←/→** trocam a foto.
- **REVS-03** — **Topo de 54px**: "REVISÃO SIMPLES", "x de N na fila", ↑ ↓, "Abrir revisão detalhada
  ↗" e ×.
- **REVS-04** — **Identificação**: linha acima do título com produto · SKU; título **20px/600**;
  chips de modalidade, formato, origem, versão e andamento; à direita, responsável e tempo de espera.
- **REVS-05** — **Caixa de falha** (quando houver): mensagem de erro real e "Tentar publicar
  novamente".
- **REVS-06** — **Duas colunas de MESMO peso** — é decisão de desenho declarada no README (dar o
  mesmo destaque a fotos e preço). Não reequilibrar.
- **REVS-07** — Coluna **Fotos**: progresso "x de y aprovadas"; **"Aprovar N sem alerta"** (⚠️ aprova
  **só** as `pending` **sem** alerta automático — DADO-06); a nota de conjunto compartilhado.
- **REVS-08** — Imagem grande **1:1** com posição #n, **★ PRINCIPAL**, **✦ "Gerada por IA"** ou
  "Original", estado, e **marcadores numerados** nos pontos de alerta, posicionados pelas coordenadas
  x/y em % (DADO-06); botões "✦ Gerar novamente com IA" e "⤢ Ampliar".
- **REVS-09** — Grade com **todas** as miniaturas em **6 colunas**, com borda por estado
  (selecionada `#FACC15`, alerta `#EF4444`, aprovada `#22C55E`, pendente `#F59E0B`).
- **REVS-10** — Bloco da imagem selecionada: tipo, variação, "Reprovar" e "Aprovar foto", e as
  verificações automáticas **como APOIO** (o rótulo é literal: "VERIFICAÇÃO AUTOMÁTICA · APOIO").
- **REVS-11** — Coluna **Preço e resultado**: 3 KPIs (Preço de venda · **Resultado estimado por
  venda** · Margem com barra e nota); aviso de alteração de preço não conferida; **composição
  completa com selo de origem por linha**; "Informar frete confirmado" quando faltar frete;
  simulação de preço; comparação com a oferta irmã (FIN-13).
- **REVS-12** — **Pendências**: lista de bloqueios, **cada um com o seu botão de correção**
  (`fix()`); sem bloqueios, a mensagem verde de tudo conferido.
- **REVS-13** — **Rodapé fixo de 66px**: "Salvar revisão" · "Solicitar ajustes" · motivo do bloqueio
  (vermelho) ou "Fotos, preço e conteúdo conferidos" (verde) · **"Aprovar para publicação"**
  (`disabled=` enquanto houver bloqueio) · "Próximo ↓".
- **REVS-14** — O painel **nunca** mostra status do Mercado Livre (AND-02) e **nunca** escreve no ML
  (a escrita no ML é só a da Fase 193 — PUB-06).

## AMPL — Imagem ampliada: o lightbox (E3)

- **AMPL-01** — Tela cheia, fundo `rgba(5,5,5,0.94)`, grid `minmax(0,1fr) 320px`, **acima** do
  painel na ordem de camadas (e por portal — REVS-01).
- **AMPL-02** — Imagem **1:1 com até 78vh**, **zoom de 2,5× que segue o cursor**, marcadores
  numerados, ← → e miniaturas.
- **AMPL-03** — ⚠️ **O zoom é transform/transition e NÃO usa `duration-[Nms]`/`delay-[…]`/`ease-[…]`**
  — essas classes **NÃO COMPILAM** neste projeto (`tailwindcss-animate` as torna ambíguas) e a
  transição simplesmente não acontece, com todo teste de render passando. Usar
  `style={{ transitionDuration }}` ou token em `theme.extend.transitionDuration`, com gate provando
  que a classe arbitrária **não voltou** (`duration-200` como controle).
- **AMPL-04** — **Lateral de 320px**: metadados (origem, posição, variação, estado).
- **AMPL-05** — **Original de referência** + "Comparar lado a lado na revisão detalhada" (leva para a
  aba Fotos da revisão detalhada, na mesma imagem).
- **AMPL-06** — Verificações automáticas (os mesmos pontos numerados que aparecem na imagem).
- **AMPL-07** — Botões "✦ Gerar novamente com IA", "Ampliar" e "Reprovar"/"Aprovar foto", com as
  mesmas travas de estado da revisão simples (só `live` edita; `regenerating` não aceita ação).

## REVD — Revisão detalhada: a página (E4)

- **REVD-01** — **Barra fixa abaixo do header**, sempre visível: "← Fila de aprovação" (volta para a
  **mesma posição, com os filtros mantidos**), "Posição x de N · filtros mantidos", ↑ ↓ entre ofertas
  e "REVISÃO DETALHADA".
- **REVD-02** — Miniatura principal, título, chips e um **seletor Clássico | Premium** que mostra
  **preço e resultado de cada um**.
- **REVD-03** — **Abas**: Fotos · Preço e custos · Conteúdo do anúncio · Pendências e histórico,
  **cada uma com badge de estado**; a aba aberta está na **URL** (`…/aprovacao/:offerId/:aba`).
- **REVD-04** — **Resumo de aprovação** à direita: Fotos x/y · Preço · Conteúdo · Ajustes ·
  Validações técnicas, cada item com ✓ ou ! (**e texto** — VOC-05).
- **REVD-05** — **Rodapé fixo**: o mesmo da revisão simples, **sem "Próximo"**.
- **REVD-06** — **Aba Fotos**, grid `290px | 1fr | 330px`. **Galeria da publicação**: linhas
  arrastáveis com ⋮⋮, #pos, miniatura de 46px, rótulo, "✦ IA"/"Original", estado e selo **NOVA**;
  ações ✦ (gerar novamente), ★ (principal) e ▲▼ (mover).
- **REVD-07** — Seções **"FORA DA PUBLICAÇÃO"** (reprovadas/substituídas, com **hachura vermelha**;
  **originais podem ser recolocadas**) e **"ORIGINAIS DISPONÍVEIS"** (+ Adicionar).
- **REVD-08** — **Centro**: comparação lado a lado "Gerada por IA" × "Original de referência", com
  **zoom 1×–4× SINCRONIZADO** pelo cursor; alternância para imagem única. ⚠️ **Sem original
  disponível (IMG-13), o painel da esquerda mostra o estado declarado — não um quadro vazio nem a
  imagem de IA repetida.** E ainda: **"Conjunto final"**: faixa
  de miniaturas com a principal e "N fotos vão para a publicação · N ainda precisam de aprovação".
- **REVD-09** — **Direita**: metadados + "VERIFICAÇÃO AUTOMÁTICA · APOIO"; **"FIDELIDADE À
  ORIGINAL"** com cada critério em ✓ Confere / × Diverge e o contador "x de y conferidos";
  **"DEFEITOS ENCONTRADOS"** com os 6 chips; ações **Aprovar imagem** · **Reprovar com motivo**
  (⚠️ **pré-preenchido com os critérios divergentes e os defeitos marcados**) · **Substituir por
  original** · **✦ Gerar novamente com IA**.
- **REVD-10** — **Aba Preço e custos**: **dois cards lado a lado**, Clássico e Premium, com o card
  **em revisão destacado**.
- **REVD-11** — Cada card mostra KPIs e **bloqueios financeiros com correção**.
- **REVD-12** — Tabela **"Composição | Origem e atualização | Valor"**: preço de venda, as linhas de
  custo e **"Frete pago pelo comprador · não entra"**, fechando em **"Resultado estimado por venda ·
  margem de contribuição"**.
- **REVD-13** — Nota de composição do combo, simulação (FIN-11) e **exceção registrada** quando
  houver.
- **REVD-14** — **Aba Conteúdo do anúncio** — **tabela de campos**: título com contador **/60** em
  verde ou vermelho, descrição, categoria, atributos obrigatórios com ✓, variações, SKU, estoque (em
  combos, também **"N kits disponíveis"**) e composição do kit. ⚠️ **Cada campo tem "Comentar"**, que
  abre o modal de ajuste **já com o item selecionado**.
- **REVD-15** — **Prévia fixa** do que será enviado: foto principal, miniaturas **na ordem final**,
  título, preço, modalidade, formato e disponibilidade. ⚠️ A prévia reflete o **conjunto final**, não
  a galeria original.
- **REVD-16** — **Aba Pendências e histórico** — **Ajustes**: cards com **item → destino** (em
  **âmbar** quando vai para o cadastro do produto), texto, autor/data, estado e **"Marcar como
  resolvido"**; botão "+ Solicitar ajuste".
- **REVD-17** — **Histórico e versões**: linha do tempo com **ícone por tipo de evento** (os 8 de
  DADO-10), em ordem, com quem e quando.
- **REVD-18** — Oferta `approved` mostra a caixa verde literal: "Aprovado na versão N por X.
  **Qualquer alteração em fotos, preço ou conteúdo reabre a aprovação.**" — e ela de fato reabre
  (BLOQ-06).

## IMG — Regras de imagem

- **IMG-01** — **Imagem reprovada sai da galeria final NA HORA** e vai para "Fora da publicação".
  **Se era a principal, a próxima vira principal.**
- **IMG-02** — **Imagem nova, regenerada ou original recolocada entra como `pending`.**
- **IMG-03** — Ao **substituir** por uma original, a original entra **na mesma posição**; se já
  estava aprovada, **mantém a aprovação**; caso contrário, precisa ser aprovada.
- **IMG-04** — **Aprovar foto** grava quem e quando, marca o conjunto como tocado em **todas** as
  ofertas dele, e registra no histórico.
- **IMG-05** — **"Aprovar N sem alerta"** aprova **só** as `pending` **sem** alerta automático e
  registra "Aprovou N fotos sem alerta do conjunto compartilhado".
- **IMG-06** — Toda ação de imagem que muda o conjunto final **reabre a aprovação de todas as ofertas
  do conjunto** (BLOQ-06) — reprovar, substituir, regenerar, adicionar, reordenar, trocar a
  principal.
- **IMG-07** — **Reordenar** e **definir principal** mantêm a galeria consistente: a principal é
  sempre a **primeira** da ordem.
- **IMG-08** — **Recolocar original** que estava reprovada/substituída volta a imagem para `pending`
  e a tira de "Fora da publicação".
- **IMG-09** — Imagem em `regenerating` **não aceita** aprovar, reprovar, substituir nem regenerar de
  novo.
- **IMG-10** — "Reprovar imagem" **exige motivo** e registra o motivo no histórico e no toast.
- **IMG-11** — O vínculo **imagem de IA → original de referência** é dado de primeira classe e
  **DURÁVEL** (DADO-15): sustenta a comparação lado a lado (REVD-08) e o pré-preenchimento da
  regeneração. ⚠️ **Ele NÃO se apoia em `ml_anuncio_criativos.referencias`**, que é efêmero (§4b).
- **IMG-12** — A **galeria vazia** é bloqueio (`nophoto`), não tela vazia silenciosa.
- **IMG-13** — ⚠️ **Estado declarado "original de referência não disponível"**, pela régua D4
  ("nunca assuma valor zero"): criativo gerado **antes** de DADO-15 existir, e criativo cuja
  referência efêmera já foi recolhida, **não têm original para comparar**. A comparação lado a lado
  mostra esse estado **com ícone e texto** (VOC-05) — ⚠️ **nunca painel vazio, nunca a imagem de IA
  duplicada no lugar da original, nunca zero**. ⚠️ **Vale para TODO o acervo de hoje:** na estreia,
  **nenhuma** imagem antiga terá original de referência.

## IA — Regeneração de UMA imagem (fronteira com o Creative Engine)

- **IA-01** — ⚠️ **COMPOSIÇÃO, não serviço novo:** a regeneração **CHAMA**
  `GeminiImageProvider::gerarImagem`, `CreativePromptBuilder::paraSlot(…, $regeneracao,
  $ajusteOperador)`, `CreativePermissao::podeRegenerar(User)` e
  `ReferenciaEfemeraService::guardar(MlAnuncioCriativo, array $arquivos)`. **Nada disso é
  reimplementado**, e `app/Services/Creative/` **não é modificado** (TERR-01).
- **IA-02** — O modal regenera **SÓ ESTA IMAGEM**, e o pedido enviado ao serviço inclui: **a imagem
  atual e a posição dela**, os **problemas marcados**, a **instrução livre** e as **referências**
  (originais do produto + fotos enviadas na hora).
- **IA-03** — **Chips de problema**, os 8 literais: pés/base, cor, formato/proporção,
  material/textura, peça duplicada/ausente, quantidade do kit, texto ilegível, fundo/cenário.
  ⚠️ **Eles são ESTRUTURADOS e o `$ajusteOperador` é TEXTO LIVRE.** O planejamento **decide e
  registra** se os chips viram texto **na nossa borda** ou se o problema é guardado de forma
  estruturada — e, no segundo caso, **para e pergunta ao outro dev** (decisão em aberto 1).
- **IA-04** — **Imagens de referência**: as originais do produto são selecionáveis e **a referência
  daquela imagem já vem marcada**; mais **envio de fotos pelo usuário** (clique ou arrastar, **até
  6**, com prévia e remover, só `image/*`).
- **IA-05** — ✅ **RESPONDIDA POR MEDIÇÃO (14/10/2026): o criativo É sempre alcançável a partir da
  imagem que a fila revisa** — `ml_anuncio_criativos.pub_imagem_id` → `pub_imagens.id`, indexado
  (ver §3). ➡️ **Nenhuma coluna nova para isso**, e o `ReferenciaEfemeraService` é chamado com o
  `MlAnuncioCriativo` encontrado por aquele caminho. Imagem vinda de **upload** não tem criativo, e
  esse é o comportamento correto.
- **IA-06** — **"Gerar nova versão" habilita com um problema marcado OU instrução com mais de 5
  caracteres** — nunca com os dois vazios.
- **IA-07** — Durante a geração a imagem mostra **"Gerando nova versão…"** (estado `regenerating`,
  com spinner e **ícone + texto**), e a ação fica bloqueada (IMG-09).
- **IA-08** — Ao terminar, **a nova versão entra na MESMA POSIÇÃO como NOVA e `pending`**, e a
  anterior vai para **"Fora da publicação"** como `replaced`; se a anterior era a principal, a nova
  vira principal; o histórico ganha o evento de sistema "Nova versão gerada: … · aguardando revisão".
- **IA-09** — A regeneração **é assíncrona de verdade** (fila do projeto, nunca HTTP síncrono na
  requisição) e a tela reflete o estado real — **sem `setTimeout` simulando conclusão**, que é
  artefato do protótipo. ⚠️ Job interativo vai para a fila **high** (a `default` de produção é
  congestionada e já segurou webhook por horas).
- **IA-10** — Permissão: quem não passa em `CreativePermissao::podeRegenerar` **não vê a ação
  habilitada**, com `disabled=` e explicação — nunca um clique que falha calado.

## MOD — Modais e toasts (E5)

- **MOD-01** — **Solicitar ajustes**: select de item (cada imagem, preço, frete, título, descrição,
  custo, atributos, kit/categoria), caixa **"Encaminhar para"** mostrando o destino conforme o
  **escopo**, comentário e **sugestões rápidas** literais (ex.: "IA alterou os pés da poltrona",
  "Frete do vendedor ainda não confirmado"). Texto vazio **não envia**.
- **MOD-02** — **Solicitar exceção autorizada**: caixa **vermelha** com o motivo (resultado ou
  margem), responsável e justificativa; **não envia com menos de 10 caracteres**.
- **MOD-03** — **Informar frete do vendedor**: valor, **origem** (cotação, tabela do cliente,
  simulador) e **prévia do resultado e da margem** antes de confirmar; confirmar grava
  origem `Confirmado`, a nota com a data e **incrementa a versão**.
- **MOD-04** — **Reprovar imagem**: miniatura, chips de defeito e **motivo obrigatório**.
- **MOD-05** — **Casca comum**: `width: 560px`, topo em **96px**, backdrop `rgba(0,0,0,0.65)`,
  `max-height: calc(100vh - 140px)`, fundo `#111111`, borda `#2E2E2E`, raio **16px**, sombra
  `0 30px 80px rgba(0,0,0,0.8)`. ⚠️ **Por portal**, e ⚠️ **conteúdo portado NÃO herda a fonte** —
  cada modal declara a fonte do módulo por conta própria (medido na Fase 178).
- **MOD-06** — **Substituir por foto original**: grade de originais do produto, **as que já estão na
  galeria ficam `disabled=`**, com a nota sobre o estado de aprovação.
- **MOD-07** — **Gerar novamente com IA** — imagem atual com a posição, os 8 chips e a instrução
  livre (IA-03, IA-06).
- **MOD-08** — **Imagens de referência** no mesmo modal: originais selecionáveis com a referência já
  marcada (IA-04).
- **MOD-09** — **Envio de fotos** no mesmo modal: clique ou arrastar, até 6, prévia e remover, só
  `image/*`.
- **MOD-10** — O estado de geração é refletido no modal e depois na galeria (IA-07, IA-08).
- **MOD-11** — **Aprovar em lote**: lista ✓/× com **versão** e **motivo**, e o botão
  "Aprovar K ofertas" (BLOQ-10).
- **MOD-12** — **Toasts** no **topo central**, somem em **~3,8 s**, com as 6 mensagens literais de
  VOC-08; nunca dois simultâneos (o anterior é cancelado).

## PUB — Do aprovado ao ar

- **PUB-01** — Oferta `aprovado` é **"aguardando publicação"**, e é o portão que a publicação
  consome: **nada vai ao ar sem passar por aqui**.
- **PUB-02** — ⚠️ O caminho da publicação **reusa a fila que existe**
  (`FilaPublicacaoService` / `AgendadorDaFila` / `publicador:fila-publicacao`, de minuto em minuto) e
  **resolve a diferença de granularidade** (oferta × produto — ver "O que eu já medi §5"), sem
  duplicar `revisao`/`ciente`/`validacao_id`.
- **PUB-03** — ⚠️ **`app/Services/Mlb/Publicacao/MlPublicacaoService.php` NÃO é alterado**, e o gate
  de conta travada do módulo **continua valendo**.
- **PUB-04** — `publicando` **bloqueia edição** da oferta; `publicado` só com **MLB confirmado**
  (AND-04); `falha` **preserva `approvedVersion` e toda a revisão**, com "Tentar de novo" (BLOQ-11).
- **PUB-05** — O erro de publicação exibido é o **erro real** registrado, com a frase de apoio do
  protótipo ("Nenhuma oferta foi criada; a revisão foi preservada") **só quando é verdade**.
- **PUB-06** — ⚠️ **A escrita no ML obedece a disciplina do módulo:** trava de conta, **registro
  ANTES do HTTP**, `5xx`/rede tratados como **incerto** (`UNKNOWN`), **sem reenvio por conta
  própria** — e `UNKNOWN` nunca reenviado sem reconciliar pelo SKU (**RN-93**).
- **PUB-07** — **A FILA escreve; a LISTA não** (D11): o gate D-11 de
  `tests/js/estrutura-meus-anuncios.test.js` continua intacto e **não é estendido** à área nova;
  a área nova ganha gate de escrita próprio.
- **PUB-08** — Toda publicação disparada daqui registra **quem aprovou** e **em que versão**, e isso
  aparece no histórico e na lista de Publicadas (pelo que a lista já mostra).
- **PUB-09** — ⚠️ **Decisão do usuário (em aberto 3):** aprovar **enfileira automaticamente** ou a
  pessoa ainda aperta "publicar"? O requisito é verificável nos dois desenhos; a fase **não começa**
  sem a resposta.
- **PUB-10** — Nenhuma linha desta fase cria rota, botão ou coluna sem destino (D4).

## AUTO — Ligar a automação atrás do portão

- **AUTO-01** — Oferta criada automaticamente entra na fila em **`preparando`** com a frase real do
  que falta (ex.: "calculando frete e composição de preço") e **não abre revisão** até estar pronta.
- **AUTO-02** — Quando o preparo termina, a oferta vira **`aguardando aprovação`** — **nunca**
  `aprovado`, **nunca** publicada direto.
- **AUTO-03** — A origem da oferta é marcada **`auto`** (⚡ Automática) ou **`manual`** (✎ Manual) e
  aparece na meta da linha (FILA-11).
- **AUTO-04** — ⚠️ Ligar `publicador.criativos_auto.ativo` é **mudança de configuração, com as sete
  condições de segurança do `CriativosAutomaticosService` intactas** — inclusive a lista de empresas
  (`publicador_criativos_auto_companies`), o usuário de sistema
  (`publicador_criativos_auto_usuario`) e o hash de fotos+ficha que impede repedir o mesmo produto.
  **Nenhuma condição é removida ou afrouxada.**
- **AUTO-05** — A chave é **desligável sem deploy** e o roteiro de desligamento está escrito no
  fechamento da fase.
- **AUTO-06** — ⚠️ **A ativação é por EMPRESA, começando por UMA**, com o roteiro de conferência
  humana (produção, conta 459) antes de ampliar.
- **AUTO-07** — ⚠️ **Decisão do outro dev (em aberto 4):** o que falta do lado dele para destravar.
  A fase **não liga nada** sem a resposta registrada em `.planning/coordenacao/`.
- **AUTO-08** — O fechamento da milestone mede: quantas ofertas automáticas entraram na fila, quantas
  foram aprovadas, quantas foram reprovadas e **por quê** — é o número que prova que o portão está
  funcionando.

## COL — Persistência e colaboração (seção G)

- **COL-01** — A **revisão parcial é salva no servidor**: estado de cada imagem, checklist, defeitos,
  ajustes e histórico. **Outra pessoa continua de onde a primeira parou.**
- **COL-02** — ⚠️ **Nada de `localStorage` como fonte** — é artefato do protótipo (`KEY =
  'ecf-aprovacao-v1'`). O estado vive no banco.
- **COL-03** — A **URL reflete** os filtros da fila e a oferta/aba aberta; recarregar devolve o mesmo
  estado.
- **COL-04** — **Concorrência**: se a `version` mudar enquanto alguém revisa, a tela **avisa** e
  **reabre as verificações afetadas** — nunca sobrescreve em silêncio.
- **COL-05** — Toda ação registra **autor e data reais**; "Sistema" é ator próprio para os eventos
  automáticos.
- **COL-06** — ⚠️ **Data por fonte formatada no servidor ou por regex, nunca `new Date()` no
  cliente** — `Invalid Date` e o deslocamento de fuso de data-only em BRT já morderam este projeto.

## MIG — Disciplina de migration (a primeira da série)

- **MIG-01** — ⚠️ **Produção é MySQL 8.0.46; o local é MariaDB 10.4.32; os testes são SQLite.** Toda
  decisão de schema é conferida nos três.
- **MIG-02** — **Nome de índice CURTO** — o MariaDB recusa acima de 64 caracteres (erro **1059**), o
  SQLite aceita, e a migration fica **Pending com a tabela já alterada**.
- **MIG-03** — FK com `nullOnDelete` **exige `->nullable()`** (erro **1830**; o SQLite não pega).
- **MIG-04** — **Sem `enum`** — o CHECK do SQLite quebra os testes; usar `string` + constantes no
  modelo.
- **MIG-05** — `up()` **idempotente**; **sem `try/catch` em volta de DDL**.
- **MIG-06** — Provar **migrate → rollback → migrate** no local (conferir `tasklist | grep mysqld`
  antes; o MariaDB local já esteve corrompido).
- **MIG-07** — **Tabela de decisão de schema no docblock, ANTES do código.**
- **MIG-08** — Índice novo entra **ANTES** de dropar o antigo (erro **1553** ao dropar índice usado
  por FK).
- **MIG-09** — Nenhuma migration apaga linha de tabela com FK; nenhuma renomeia coluna em uso sem
  branch de compatibilidade.

## TERR — Território e fronteiras

- **TERR-01** — ⚠️ **NÃO tocar** em `app/Services/Creative/`, nos endpoints `criativo*` do
  `MlbAnuncioController`, em `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` nem
  em `app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` — **outro dev,
  ATIVO**. **Reusar, nunca reescrever**; necessidade ali **PARA E PERGUNTA**.
- **TERR-02** — ⚠️ **NÃO tocar** no editor (`resources/js/Pages/Mlb/Publicador/Editor.jsx`,
  `resources/js/Components/Publicador/Mesa/` — **SEM** o `Mlb` no meio).
- **TERR-03** — ⚠️ **NÃO alterar** `App\Support\Publicador`,
  `app/Services/Mlb/Publicacao/MlPublicacaoService.php` nem `resources/js/Layouts/AppLayout.jsx`.
- **TERR-04** — ⚠️ **NÃO encostar** em `app/Models/Bling*`, `app/Services/Bling/` nem
  `app/Services/Portal/` — **sessão paralela** (Fases 184/185).
- **TERR-05** — ⚠️ **NÃO mexer** na Visão Geral (Fase 179) nem no corpo de Publicações (Fase 180)
  **além da seção A**.
- **TERR-06** — O header é **`z-30`** e a ordem travada é **filtros 10 < header 30 < menu lateral e
  painel 40** (`tests/js/publicador-header-zindex-ordem.test.js`): o painel de 1140px e o lightbox
  **respeitam isso** e **não alteram o header**.
- **TERR-07** — ⚠️ **D-11 e D-22 são INTOCÁVEIS** em `tests/js/estrutura-meus-anuncios.test.js`
  (≈ `:250`, `:256`, `:285` — **identificar por SEMÂNTICA**): a lista nunca escreve no ML e a Nota
  ECF é **"de 86"**. A fronteira está em D11/PUB-07.
- **TERR-08** — O rename da seção A muda **rótulo**, nunca **chave de dado** — URL, filtro salvo e
  teste de comportamento continuam funcionando.
- **TERR-09** — ⚠️ `design_handoff_publicador/` e `new_layout_publicador/` são **somente leitura** e
  **não versionadas**.
- **TERR-10** — Toda rota, config e tabela nova fica **dentro do módulo Publicador** e sob o recorte
  de permissão que ele já tem.
- **TERR-11** — ⚠️ **NÃO mudar a retenção de 48 h nem o `creative:limpar-referencias`**
  (`app/Console/Commands/LimparReferenciasCriativos.php`,
  `services.creative.retencao_referencias_horas`): é território do outro dev e a janela existe por
  **decisão de custo e privacidade da v24.0 (D-02)**, com o incidente do ECF Drive citado no
  docblock como motivo do desenho. Se parecer necessário, **PARE e devolva a pergunta** — a saída
  certa é DADO-15 (vínculo durável nosso), nunca esticar a retenção alheia.

## GATE — Política de gates (a régua medida nas Fases 177-180)

- **GATE-01** — **A pergunta que desmascara todos:** *"o que precisaria estar errado no código para
  este gate falhar?"* Sem resposta concreta, o gate é **decorativo** e não entra.
- **GATE-02** — **DOIS gates por elemento**: **CSS** (a classe existe com o valor certo, lendo o
  **corpo** da regra no CSS construído) **e HTML** (o elemento a tem, por **igualdade de conjunto**
  de classes). Eles **não se substituem**: a sonda de CSS lê o último build e a mesma classe pode ter
  **outro dono** no repositório.
- **GATE-03** — Ao escrever seletor de CSS, **escapar TODOS os metacaracteres de regex** (colchete,
  ponto, **parênteses** de `rgba()`/`calc()`/`var()`), e saber a **forma real** do valor: token
  sólido sai **decimal** (`rgb(38 38 38 / …)`), `stroke`/`fill` sólidos saem **hex literal**,
  opacidade em `border-color`/`background-color` sai **hex de 8 dígitos**, em `--tw-ring-color` sai
  **decimal**, e o cssnano **come o zero da frente** (`-.03em`).
- **GATE-04** — **Toda sonda carrega ISCA**, e a isca é classe/caminho **inexistente no projeto
  inteiro** (`w-[13px]` parecia inventada e estava presente).
- **GATE-05** — `assert.match(tag, /disabled=/)`, **nunca** `/disabled/` (casa com
  `disabled:opacity-40`). Mesma família: `/0%/` casando com `100%`, substring casando com **prefixo
  de classe** (`z-3` em `z-30`), com **sufixo de atributo** (`href=` em `data-href=`) e com o **fim
  de outro nome** (`w-[` em `shadow-[0_16px_40px_…]`). **Travar por borda nos dois lados.**
- **GATE-06** — `new RegExp` com **`String.raw`**, nunca template literal (`\b` virou **backspace**
  em quatro asserções); elemento localizado por **árvore com índices absolutos** ou por **texto
  único**, **nunca** pela lista de classes (quatro gates mediam o irmão errado).
- **GATE-07** — **Gate de diff com RANGE** (`<inicial>..HEAD`), nunca da árvore, e **`test -e` em
  cada alvo** — **seis caminhos fantasma** na v26.0.
  ⚠️ **Com sessão paralela, a prova de "não toquei X" é COMMIT POR COMMIT**, e **NÃO se confere o
  autor**: as duas sessões usam o mesmo `user.name`; o discriminador é o **escopo do assunto**.
  ⚠️ `ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` **não pertencem a nenhum plano**.
- **GATE-08** — **Classe do Tailwind só em `.jsx`:** `tailwind.config.js` varre `*.jsx` e **NÃO
  `*.js`** — classe num `.js` **não gera CSS e não existe para a tela**, com render verde. Gate
  proibindo mapa de classe em módulo `.js`; e **classe montada por concatenação o scanner não vê**.
- **GATE-09** — `duration-[Nms]`, `delay-[…]` e `ease-[…]` **NÃO COMPILAM** (AMPL-03): gate provando
  ausência, com `duration-200` como **controle** para a sonda não ficar cega.
- **GATE-10** — **Mutação** com **contagem de ocorrências travada antes** (recusar se ≠ 1),
  **qual linha mudou** conferida depois, restauração por **sha256**; **sobrevivente é INVESTIGADA**
  (quatro vezes na v26.0 era lacuna real, não equivalência). ⚠️ **Medir as DUAS contagens de teste**
  (pristino e mutado) antes da campanha — sonda com `skip` por `mtime` tem dois regimes.
- **GATE-11** — ⚠️ **Tirar o ANSI antes de filtrar** qualquer saída de `artisan test` ou `npm` — as
  sequências de cor já comeram **12 linhas de veredicto** e a campanha pareceu verde sem provar nada.
  ⚠️ **Comparar a CONTAGEM esperada de testes**, não só "0 fail": arquivo que não carrega reporta
  zero falhas (1 teste em vez de 107).
- **GATE-12** — **Asserções removidas**: exigir o par `-`/`+` **no mesmo teste** e
  `+assert > -assert`, **enumerando cada um** — `git diff | grep '^-assert'` acusa remoção onde
  houve só reaponte. **Cirurgia em teste é REAPONTE COM VETO, nunca remoção**, e **CONTE TESTES, não
  linhas**.
- **GATE-13** — **Asserção negativa só com dado PREENCHIDO** (negativa com fixture vazio é vazia por
  construção) e **só depois de medir que o literal é EXCLUSIVO** (ex.: "Em revisão" é **substring**
  de "Em revisão pela ECF" — VOC-01).
- **GATE-14** — **Baselines respeitadas** (§8) e **`npm run build` verde com manifest conferido pelo
  JSON**. ⚠️ **Nenhum executor afirma ter visto tela renderizada**; a validação visual é checkpoint
  humano em produção, conta 459.

## REND — Render real (a tela preta de 07/10, que voltou QUATRO vezes na Fase 180)

- **REND-01** — **Render REAL** (esbuild + `react-dom/server`) de todo campo novo com o valor
  **objeto, nulo e ausente**; coleção nula/string/número; chave desconhecida; e a **prop inteira
  nula**.
- **REND-02** — `assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/)`.
- **REND-03** — ⚠️ **`String(valor)` LANÇA** com `{toString: null}`;
  **`Object.prototype.hasOwnProperty.call(obj, chave)` LANÇA** pelo mesmo motivo; **qualquer
  `obj[chave]` com chave adversa LANÇA**; `textoSeguro(NaN)` devolve `'NaN'`; e **prop de NÓ chegando
  objeto comum faz o React lançar e derruba a página**. ➡️ **Guarda de tipo antes de qualquer acesso
  por chave vinda do servidor**, e **a mensagem de asserção NUNCA usa `String()` nem
  `JSON.stringify()`** do valor adverso.
- **REND-04** — ⚠️ **Rollup elimina variável de escopo do componente usada dentro de `.map()`** →
  `ReferenceError` só no bundle de produção. **Toda flag nasce DENTRO do callback.**
- **REND-05** — ⚠️ **O renderizador de servidor do React NÃO renderiza portal**: as peças internas
  de painel, lightbox e modais são **named exports** para poderem ser conferidas; e **conteúdo
  portado não herda a fonte** — cada um declara a sua.
- **REND-06** — `node --test`: **montar TODOS os bundles ANTES do primeiro `test()`** (o `after()`
  já matou um arquivo inteiro sem nenhuma falha).
- **REND-07** — Harness que bundla o header precisa de `'@radix-ui/react-dialog'` no `external`.
- **REND-08** — Teste dependente de `now()` é ancorado com `Carbon::setTestNow()` — o "aguardando há
  X" e o "âmbar acima de 24 h" **reprovariam por horário** sem isso.

---

## Fora de escopo desta milestone (não mapear em nenhuma fase)

| Item | Motivo |
|---|---|
| `support.js`, `image-slot.js` | O README diz: runtime só do protótipo. **Não lidos.** |
| `localStorage` / "Restaurar dados de exemplo" / `setTimeout` de conclusão | Artefatos de protótipo (COL-02, IA-09). |
| Os SVGs crus do protótipo | O README manda usar a biblioteca de ícones do codebase (`lucide-react`) — com `strokeWidth × 1,5` (FID-07). |
| Os dados mock (Poltrona Beny, Mesa Tora, Cadeira Oslo, os 10 `OFF`, as pessoas de `EMP`) | Viram dado real; o que não tem fonte vira **Pendente/indisponível**, nunca zero (D4). |
| As taxas 12% / 17% / 6,75 / 6% como literal ou config | A fonte real é melhor e já existe (FIN-02, FIN-03). |
| **Fase 181** (painel de inspeção) e **Fase 183** (Histórico) da v26.0 | **Pausadas pelo usuário em 14/10/2026.** Não apagadas: seguem no roadmap. |
| Redesenhar o corpo de Produtos, Produto, Alavancas, Tarefas, Em massa, Configurações | Fora do pacote de design. |
| Alterar `MlPublicacaoService`, `App\Support\Publicador`, `AppLayout` ou o gate de conta travada | TERR-03, PUB-03. |
| Alterar `app/Services/Creative/` ou `PublicadorCriativoAprovacaoService` | TERR-01 — outro dev ativo. |
| Corrigir `tests/js/estrutura-grade-glide.test.js:122` | Falha pré-existente e intencional. |
| Deploy e push | Não autorizados nesta sessão. |
| Leitura de produção | **Bloqueada** — quem precisar **para e devolve a pergunta**. |

---

## ⚠️ Decisões que precisam do usuário (ou do outro dev) ANTES de certas fases

1. **Chips de problema: texto na nossa borda ou estruturado no lado do Creative Engine?** (IA-03) —
   pergunta ao **outro dev**. Bloqueia a Fase 192.
2. ✅ ~~O `MlAnuncioCriativo` é alcançável a partir da imagem que a fila revisa?~~ — **RESPONDIDA
   POR MEDIÇÃO em 14/10/2026: SIM**, por `ml_anuncio_criativos.pub_imagem_id` (indexado). Não é
   mais pergunta e **não bloqueia nada** (IA-05).
3. **Aprovar enfileira automaticamente, ou a pessoa ainda aperta "publicar"?** (PUB-09) — decisão do
   **usuário**. Bloqueia a Fase 193.
4. **O que falta do lado do Creative Engine para ligar `publicador.criativos_auto.ativo`?** (AUTO-07)
   — pergunta ao **outro dev**. Bloqueia a Fase 194.
5. **Quem autoriza a exceção de prejuízo/margem?** (BLOQ-09) — no protótipo é direto; no produto o
   README pede "fluxo de autorização do gestor". **Decisão do usuário:** basta registrar o gestor
   responsável (como no protótipo) ou é preciso aprovação em dois passos? Bloqueia o detalhe da
   Fase 188, não a fase inteira.
6. ✅ ~~O vínculo imagem de IA → original de referência está gravado hoje?~~ — **RESPONDIDA POR
   MEDIÇÃO em 14/10/2026: está, mas é EFÊMERO (48 h)** (§4b). Virou requisito, não pergunta:
   **DADO-15** (vínculo durável, nosso, sem tocar o Creative Engine), **IMG-13** (o estado
   "original de referência não disponível", que vale para todo o acervo de hoje) e **TERR-11**
   (não mexer na retenção alheia). ⚠️ **Resta uma pergunta MENOR, de produto, não bloqueante:**
   se vale a pena oferecer "recolocar a original manualmente" para o acervo antigo, que nunca terá
   o vínculo.
