# Handoff — Módulo MLB/Anúncios — 2026-07-15

Onde paramos e como continuar. Escrito no fim da sessão de 15/07.

---

# ▶ AO CHEGAR AMANHÃ (no PC da empresa) — LEIA SÓ ISTO PRIMEIRO

**Está tudo no GitHub.** `origin/main` = `a01e6d1`. Nada ficou preso na máquina de casa —
código, testes e documentação, tudo pushado. É só puxar.

### 1. Pegar o trabalho

```bash
cd <pasta do projeto>
git checkout main
git pull origin main
```

### 2. Instalar as dependências novas (OBRIGATÓRIO — senão o build quebra)

A sessão de ontem adicionou **5 pacotes** (a lib da planilha). O `package-lock.json` já veio no pull
com as versões certas, então um `npm install` puro resolve:

```bash
npm install
npm run build     # tem que terminar verde
```

⚠️ **Se o `npm install` travar por muitos minutos:** é o conflito do `marked`. A lib exige
`marked@^4.0.10`; se algo trouxer a v18, o npm entra em backtracking e pendura **10+ min sem
escrever log** (parece rede/antivírus, não é). O lockfile já fixa `marked@^4.3.0` — só acontece se
alguém rodar `npm install marked` solto. Conserto: `npm install marked@^4.0.10`.

### 3. Conferir que está tudo de pé

```bash
npm run test:js                                  # 68/68
php artisan test --filter=Phase82                # 10/10
php artisan test --filter=Phase86                # 6/6
php artisan test --filter=Phase75                # 3 failed / 40 passed  ← É O BASELINE, não é bug
```

> As 3 falhas do Phase75 são **pré-existentes e de ambiente** (`cURL error 60` — certificado SSL ao
> chamar a API do ML de verdade). Não perca tempo com elas.

### 4. ⚠️ RESOLVER A QUESTÃO DO GIT DA VPS — é literalmente 1 comando

**O problema:** a VPS está com o HEAD antigo (`2295471`) + os arquivos do módulo aplicados por fora
do git (resultado dos deploys parciais de ontem). Funciona, mas o git de lá não sabe o que está
rodando — confuso de auditar.

**A solução, no PC da empresa:**

```bash
bash deploy.sh
```

**É seguro, e isso foi PROVADO ontem, não presumido:**

- O `deploy.sh` faz `git reset --hard origin/main` na VPS. Como **tudo já está em `origin/main`**,
  ele traz exatamente o mesmo conteúdo que já está lá — comparei os **md5 dos 6 arquivos de
  runtime** (controller, rotas, as 4 telas): **batem byte a byte**.
- O commit do outro dev (`2295471`) é **ancestral** de `origin/main` → o reset só **avança**, nada
  dele se perde.
- **Não há migration nova** → o `migrate --force` do script é no-op. O banco não é tocado.
- Depois disso o git da VPS volta a bater com o disco, e os próximos deploys ficam limpos.

**A única ressalva:** se o outro dev tiver editado algum arquivo **direto na VPS sem commitar**, o
reset descarta. O `git status` de lá não acusou nada dele ontem — mas quem sabe disso é você. Se
tiver dúvida, antes de rodar:

```bash
# na VPS: mostra qualquer coisa não commitada
cd /var/www/ecf_admin && git status
```

### 5. Antes de codar: VALIDAR em produção o que subiu ontem

Nada disso é testável em localhost (**0 empresas com `ml_token` no banco local**, sem OAuth do ML —
o painel vem vazio e a grade nem abre). Em `https://admin.ecfconsultoria.com.br/mlb/anuncios`:

- **Aba Histórico** → lista os publicados com foto/preço/link; "Anunciar semelhante" abre o wizard
  já preenchido.
- **Cores e collapse** → faixas de cor por grupo; clicar no cabeçalho recolhe; "Características
  secundárias" começa fechado; os chips reabrem.

### 6. Aí sim, a decisão do dia

**Variações** é a única coisa do seu feedback que não foi feita — e a razão está na seção
"PENDENTE 1" mais abaixo (não é preguiça: muda o modelo de dados da planilha inteira).

**Antes de começar, responda:** variação de verdade (1 anúncio com P/M/G, estoque por tamanho) é dor
real hoje? Seu erro `[COLOR, SIZE]` **já está resolvido** — anúncio simples com Cor e Tamanho
publica (você confirmou ontem). Se o volume atual for de anúncio simples, tem coisa mais barata e
útil antes (ver "Se for continuar", no fim).

---

## TL;DR

A planilha de anúncio em massa (`/mlb/anuncios` → aba "Em massa") virou uma **planilha de verdade**
(canvas) e **publica em produção** — o usuário confirmou ("agora deu certo") depois que as fases 85
resolveram o erro 400 real do ML.

**6 fases entregues e deployadas hoje: 82, 83, 84, 85, 86, 87.**
**1 fase mapeada e NÃO feita: variações** (a única do feedback que ficou de fora — motivo abaixo).

---

## Estado exato

| Onde | Estado |
|---|---|
| **Git local** | `a01e6d1` — árvore limpa (só `.env.bak-*` e o `.xlsm` untracked, ambos sem relação) |
| **origin/main** | `a01e6d1` — **sincronizado** (`0/0`). Tudo que existe local existe no GitHub |
| **VPS** | HEAD em `2295471` (commit do outro dev) **+ 9 arquivos meus aplicados via pscp** |

**Sobre os 9 arquivos fora do git da VPS:** é o resultado do deploy parcial. **Não há risco** — como
tudo está em `origin/main`, um `bash deploy.sh` (de quem quer que seja) faz `git reset --hard
origin/main` e traz **exatamente o mesmo conteúdo** pelo git. O deploy virou idempotente. (De manhã
não era assim: ver "A lição que custou caro" abaixo.)

---

## O que foi entregue hoje

| Fase | O quê | Estado |
|---|---|---|
| **82** | Planilha Excel-like em canvas (glide-data-grid): range multi-rect, fill handle bidirecional, copiar/colar do Excel, teclado nativo, seleção de linha/coluna, dropdown de valores válidos, tema `ecf-*` | ✅ prod |
| **83** | Correções do feedback: loading eterno da publicação, resultado na tela (merge + polling), erro completo da API expansível, avisos do ML visíveis, preço `129,99`, Delete, remover categoria | ✅ prod |
| **84** | Undo/redo local (Ctrl+Z / Ctrl+Y), 50 ações, paste conta como 1 undo | ✅ prod |
| **85** | **As colunas que faltavam para publicar**: foto por URL (6 col.), atributos obrigatórios escondidos (COLOR/SIZE), características secundárias, validação antes de chamar a API | ✅ prod — **validada pelo usuário** |
| **86** | Histórico dos publicados (3ª aba) + "Anunciar Semelhante" | ✅ prod — checkpoint visual pendente |
| **87** | Cores por grupo de coluna (padrão Amazon) + grupos colapsáveis | ✅ prod — checkpoint visual pendente |

Cobertura do feedback de 9 itens do usuário: **8 feitos**, 1 (variações) fora.

---

## PENDENTE 1 — Variações (a única fase não feita)

**Por que não foi feita:** a grade inteira é construída sobre `1 linha = 1 rascunho = 1 anúncio`.
Essa premissa sustenta o autosave por linha, o merge do polling, o `linhaPublicavel`, o status por
linha e o `publicarLote`. Variação **inverte** isso: 3 linhas (P/M/G) precisam virar **UM** anúncio
com estoque e foto por variação — hoje viram três rascunhos e três anúncios.

Não é coluna nova: é trocar o modelo de dados da planilha **e** o backend de publicação, mexendo nas
peças que acabaram de estabilizar em produção. Merece sessão limpa e planejamento próprio.

**Contexto já levantado (não precisa redescobrir):**

- Shape real do payload (do wizard, `AnunciarML.jsx`):
  ```js
  variations: [{
    available_quantity,                       // estoque POR variação
    attribute_combinations: [{id,name,value_id,value_name}],  // COLOR, SIZE
    attributes: [{id:'GTIN'|'SELLER_SKU', value_name}],
    picture_ids: []                           // fotos POR variação
  }]
  ```
- **Variação NÃO tem preço próprio** — herda do item. Preço divergente = erro ML 357
  (`item.variations.price.different`). O preço fica no nível do item.
- **Erro ML 146** (`item.attributes.invalid`): um mesmo atributo não pode estar no item **e** na
  variação. Se COLOR vira variação, ele tem que **sair** da lista `attributes` do item — e hoje a
  Fase 85 o coloca lá (para anúncio simples). Os dois modos precisam coexistir sem colidir.
- **Desenho sugerido (o do próprio template Amazon do usuário):** colunas coral do `.xlsm` — **SKU
  pai**, tema de variação, tipo de relação. Linhas com o mesmo SKU pai = variações do mesmo anúncio;
  linha sem SKU pai = anúncio simples (comportamento atual, preservado).
- **O nó real:** o autosave é por linha (1 linha → 1 PUT em 1 rascunho). Com N linhas → 1 rascunho,
  ou o autosave agrupa, ou o `publicarLote` agrupa no backend. **Decidir isso primeiro** — é a
  decisão que define a fase.

**Antes de abrir a fase, perguntar ao usuário:** variação de verdade é dor real hoje? O caso dele
(erro `[COLOR, SIZE]`) **já está resolvido** com anúncio simples — ele confirmou que publica. Pode
não ser urgente.

---

## PENDENTE 2 — Checkpoints visuais (rápido, mas precisa do usuário)

**Nada disso é verificável em localhost:** o banco local tem **0 empresas com `ml_token`** e não há
OAuth do ML — o painel vem vazio e a grade nem abre. Toda verificação visual é **em produção**.

Falta o usuário conferir em `https://admin.ecfconsultoria.com.br/mlb/anuncios`:

1. **Aba Histórico** (fase 86): lista os publicados com foto/preço/link; "Anunciar semelhante" abre
   o wizard preenchido.
2. **Cores e collapse** (fase 87): faixas de cor por grupo; clicar no cabeçalho recolhe;
   "Características secundárias" começa fechado; os chips reabrem.

---

## PENDENTE 3 — Follow-ups pequenos anotados no caminho

- **`AnunciarML.jsx:2469`** tem `text-emerald-400\70` — barra invertida onde devia ser `/`. Classe
  Tailwind morta, bug **pré-existente**. Candidato a `/gsd-quick`.
- **3 falhas em `--filter=Phase75`** (3 failed / 40 passed) são **pré-existentes e de ambiente**:
  `cURL error 60` (certificado SSL) ao chamar `api.mercadolibre.com` de verdade. **Não são
  regressão** — é o baseline. Não perder tempo com elas.

---

## Como rodar as coisas (economiza tempo amanhã)

```bash
# PHP: SEMPRE o 8.5, nunca o do XAMPP
C:/php/php.exe artisan test --filter=Phase82     # 10/10 (grade em massa)
C:/php/php.exe artisan test --filter=Phase86     # 6/6  (histórico)
C:/php/php.exe artisan test --filter=Phase75     # 3F/40P = BASELINE (SSL, pré-existente)

npm run test:js    # 68/68 — testes JS (node --test, nativo, zero dependência)
npm run build      # gate obrigatório de frontend do projeto
```

**Os testes JS são o padrão desta sequência de fases.** Funções puras (`gradeMassaUtils.js`) têm
teste de **comportamento**; a fiação tem gate estrutural lendo a fonte via
`tests/js/_fonte.js::lerSemComentarios` (a prosa pt-BR do projeto cita os próprios identificadores —
um `grep -c` cru passaria pelo motivo errado).

⚠️ **NUNCA escrever gate como `node -e "...\\\\s..."` inline.** O bash come as barras, o regex não
casa nada e o gate passa por motivo errado. Isso queimou os 7 planos da Fase 82 inteira. Sempre
arquivo `.cjs` ou `tests/js/*.test.js`.

---

## Deploy parcial — a receita que funciona

O usuário **exige** deploy parcial ("sem mexer no que o outro dev fez"). O `deploy.sh` é
tudo-ou-nada por design (`git reset --hard origin/main` + `migrate --force` + restart de workers).

**Receita (validada 5x hoje):**

```bash
# 1. PUSH PRIMEIRO — não é opcional (ver "a lição" abaixo)
git push origin main

# 2. Derivar a lista do diff NA HORA — nunca reciclar a lista do deploy anterior
VPS=$(./plink.exe ... "cd /var/www/ecf_admin && git log -1 --format=%h")
git diff --name-only "$VPS"..HEAD -- . ':!.planning'

# 3. pscp de cada arquivo → 4. build na VPS → 5. chown www-data
# 6. Provar: HEAD da VPS intacto + git status só com arquivos do módulo
```

**Gotchas do deploy (cada um mordeu de verdade hoje):**

- **Rota nova** → `php artisan route:cache` na VPS, senão a rota **não existe** (fase 86).
- **Blade mudou** → `php artisan view:clear && view:cache` (o portal do canvas ficou fora do cache).
- **`package.json` com dep nova** → `npm install` na VPS (foi o caso da fase 82).
- **A lista MUDA:** o 2º deploy quase quebrou o build da VPS porque `AnunciarMassa.jsx` passou a
  importar `gradeMassaUtils.js` e a lista antiga tinha 4 arquivos. Sempre derivar do `git diff`.
- **Sempre conferir:** os hashes do build da VPS devem bater com os do build local.

---

## A lição que custou caro hoje (não repetir)

Deploy parcial **sem push** = a alteração tem prazo de validade de horas.

O que aconteceu: deployei as abas via pscp sem pushar. O outro dev rodou `deploy.sh`, o
`git reset --hard origin/main` executou e **apagou as 4 telas de produção**. Pior: ficou um estado
**frankenstein** — os arquivos rastreados voltaram à versão antiga, mas o arquivo **novo**
(`ModoAnuncioTabs.jsx`) sobreviveu órfão, porque `reset --hard` não remove untracked.

Eu tinha **avisado** o usuário do risco e mesmo assim tratei o deploy como concluído. Avisar não
basta: **push antes do pscp, sempre.** Depois disso, o outro dev rodou `deploy.sh` de novo e **nada
se perdeu** — o reset apenas reafirmou o código.

(Registrado na memória do projeto: `deploy_parcial_modulo.md`.)

---

## Colaboração com o outro dev (MB.ECF-100376)

Trabalha no mesmo `origin/main`, em **NPS/Performance** — zero sobreposição com MLB/Anúncios até
agora. Hoje ele empurrou ~27 commits enquanto eu trabalhava.

- **Sempre `git fetch` antes de assumir qualquer coisa** — o `STATE.md` local mente (de manhã dizia
  que o NPS aguardava deploy; ele já tinha deployado).
- **Rebase (não merge)** sobre `origin/main`. Conflito conhecido: `.planning/STATE.md` (a tabela de
  quick tasks) — resolver **preservando os dois lados**, nunca escolher um.
- Ele também tem checkpoints visuais pendentes nas fases dele.

---

## Arquitetura do módulo (mapa rápido)

```
resources/js/Pages/Mlb/
  AnunciosEmpresas.jsx    → painel de cards (1 destino: o wizard)
  ModoAnuncioTabs.jsx     → abas Individual | Em massa | Histórico (troca de ROTA)
  AnunciarML.jsx          → wizard individual (2.6k linhas — o coração, cuidado)
  AnunciarMassa.jsx       → a PÁGINA da grade: dona do estado, autosave, publicação
  GradeAnuncioGlide.jsx   → a GRADE em canvas: só desenha e delega
  gradeMassaUtils.js      → funções puras (validação/coerção) — testadas
  AnunciosHistorico.jsx   → o histórico (3ª aba)
  anuncioHistoricoUtils.js→ link do ML (fonte única)
```

**Divisão deliberada:** a página é dona do estado; a grade só desenha. **Canvas não é DOM** — classes
Tailwind não alcançam o conteúdo desenhado (cor via `temaEcf` / `themeOverride` / `draw()`); o que é
DOM: a toolbar e os editores de overlay (que abrem no `<div id="portal">` do `app.blade.php` — **sem
ele nenhuma célula abre para edição, em silêncio**).

**Backend:** `MlbAnuncioController` — `massa()` (traz tudo MENOS publicado), `historico()` (só
publicado), `colunasCategoria()` (obrigatórios + opcionais), `duplicarComoTemplate()` (o clone do
"Anunciar semelhante"), `publicarLote()` (fan-out escalonado a 3s/posição).

---

## Se for continuar, na ordem que faz sentido

1. **Perguntar ao usuário** se ele validou o histórico e as cores em prod, e se **variação** é dor
   real (o caso dele já publica sem).
2. Se variação for: `/gsd-plan-phase` numa sessão limpa, decidindo **primeiro** o nó do autosave
   (N linhas → 1 rascunho).
3. Se não for: o próximo gap de paridade wizard × massa é levantar o **inventário completo** dos
   campos (descrição, garantia, condição, catálogo, grades de moda) — o usuário pediu "tudo que
   tiver no individual tem que ter no em massa", e fotos/secundárias foram só os dois maiores.
