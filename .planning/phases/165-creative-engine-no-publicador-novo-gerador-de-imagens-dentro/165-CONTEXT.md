# Fase 165: Creative Engine no Publicador novo — Contexto

**Reunido em:** 2026-10-04
**Status:** Pronto para planejar (execução depende do ok do outro dev — ver "Coordenação")
**Fonte:** decisões do usuário nesta conversa (04/10/2026) + bloco `AVISO-COORDENACAO-164` do `CLAUDE.md` (03/10)

<domain>
## Fronteira da fase

O publicador gera o kit de criativos por IA (Creative Engine, milestone v24.0 do outro dev, Fases 160–161 em
produção) **sem sair do editor do Publicador interno** (`/mlb/anuncios`, editor de 3 colunas "Conceito E",
`bb1b61f8`), a partir do **rascunho do Publicador** (`pub_rascunhos`), não do rascunho do assistente antigo
(`ml_anuncio_rascunhos`). As imagens aprovadas viram **fotos do próprio rascunho do Publicador** (`pub_imagens`, na
galeria geral ou no grupo da variação) e só sobem ao Mercado Livre na conferência/publicação, com a mesma trava de
conta liberada (D26) que qualquer outra foto do Publicador.

Hoje o gerador mora no assistente antigo (`AnunciarML.jsx`, etapa 5, painel "Criativos por IA") e está preso ao
rascunho antigo em três pontos: o `CreativeContextBuilder` lê `ml_anuncio_rascunhos.payload`, a aprovação grava
`payload.pictures` (`CreativeKitPublicacao`) e as rotas partem de `/rascunho/{rascunho}/criativo/...`. No Publicador
existe só a ponte "Gerar criativos no assistente antigo" na tela de produtos (Fase 164).
</domain>

<decisions>
## Decisões de implementação (travadas)

### D-01 — Integração de verdade, não ponte
O usuário escolheu (04/10) a integração nativa no lugar da "ponte rápida" (rascunho espelho no assistente antigo +
painel embutido). Nada de criar `ml_anuncio_rascunhos` por trás para o Publicador.

### D-02 — Só ACRESCENTAR no Creative Engine (código do outro dev)
- Coluna nova **anulável** `pub_rascunho_id` em `ml_anuncio_criativos` e `ml_anuncio_criativo_kits` (FK para
  `pub_rascunhos`, `nullOnDelete`, índice simples — nada de índice único). Tabelas COM dado em produção → migration
  aditiva, idempotente por `Schema::hasColumn` (convenção das migrations dessas tabelas), conferida contra as
  armadilhas de MariaDB do learnings (§6 de `desempenho-bonificacao.md`; 1553/1830/1059).
- `rascunho_id` (antigo) já é anulável — criativo do Publicador tem `rascunho_id = NULL` e `pub_rascunho_id` preenchido.
- `CreativeContextBuilder::paraCriativo` ganha um **segundo caminho**: com `pub_rascunho_id`, o contexto vem do
  rascunho do Publicador (título efetivo, categoria, atributos, descrição, variações/eixos, fotos). O caminho do
  `payload` antigo fica **byte a byte igual** (os testes `Phase160`/`Phase161`/`Quick261003L8o` provam).
- Fora desta fase: `MlPublicacaoService`, `CreativeKitPublicacao` (dono de `payload.pictures` do assistente antigo),
  o gate do PUB-03, o assistente antigo e a Fase 162 (validador).

### D-03 — Endpoints próprios do Publicador
Rotas novas em `mlb.anuncios.publicador.*` (arquivo `routes/mlb_anuncios.php`, grupo `role:admin`), por PRODUTO do
Publicador (`publicador/produtos/{produto}/criativos/...`), reaproveitando a lógica de planejar/gerar/regenerar/
aprovar do kit (Fase 161 + quick 261003-l8o: variação obrigatória na regeneração, confirmação de custo, limitadores
nomeados em pt-BR). Autorização igual à do editor (`MlbPublicadorController::produto()` → 404 fora do escopo) MAIS a
do Creative Engine (`CreativeEngineAtivo` + `CreativePermissao::podeGerar`).

### D-04 — Aprovada vira `pub_imagens`, nunca envio direto ao ML
A imagem aprovada entra no rascunho do Publicador pelo caminho de foto que já existe (`ImagemAssetService` —
conferência de tamanho/formato, disco privado, envio ao ML só se a conta está liberada, D26) e é atribuída ao grupo
de onde o kit foi pedido: galeria geral (`GENERAL`) quando pedido pelo item "Fotos"; grupo da variação quando pedido
no bloco de fotos de uma variação. Entra no FIM do grupo, na ordem dos slots; nada que já estava lá é apagado (o
operador reordena e tira à mão). Limite de fotos: a validação do Publicador já bloqueia excesso (V-IMG-06/07) — não
truncar em silêncio.

### D-05 — Tela: dentro do editor de 3 colunas
- O item "Fotos" (galeria geral) e o bloco de fotos de cada variação ganham **"Gerar com IA"**.
- Reaproveitar `PainelCriativosIa` / `KitCriativosGrade` (Pages/Mlb/components) com props novas (rotas e destino),
  **sem mudar o comportamento no assistente antigo**. Se o acoplamento ao `AnunciarML` impedir, extrair a parte
  comum para um componente compartilhado — decidir no planejamento, com o gate de não-regressão do assistente antigo.
- Seguir a identidade da mesa (gates de `tests/js/publicador-mesa.test.js`: tipografia 24/15/13/11, pesos 400/700,
  select nativo, sem `route(` nos cards, nenhum amarelo sólido em card; o único amarelo da tela é do Inspetor).
- Sem UI-SPEC nesta fase (`--skip-ui`): o desenho é o Conceito E já aprovado e publicado + o painel/grade de criativos
  já em uso pela equipe.

### D-06 — Chave e permissão
Mesma chave `creative_engine_ativo` (registro em `configuracoes`) e mesma permissão `podeGerar` do Creative Engine.
Sem a chave ou sem a permissão, o botão não aparece (como a ponte da Fase 164). **Pendente de confirmação do outro dev.**

### D-07 — Custo
O Creative Engine usa Gemini pago (~US$ 0,101 por imagem, ~US$ 0,71 por kit de 7). Manter a confirmação de custo
antes de gerar (quick 261003-l8o) e os tetos/limitadores existentes. Nada de gerar sozinho.

### Critério de Claude (decidir no planejamento, com justificativa)
- **Fotos de referência:** preferência por reaproveitar as fotos que já estão no rascunho do Publicador (do grupo de
  onde o kit foi pedido) como referência, com upload novo opcional; respeitar a retenção efêmera do Creative Engine
  (D-02 da v24: a foto ORIGINAL enviada só para gerar não vira acervo).
- Contexto da variação: quando pedido numa variação, incluir os valores dela (ex.: Cor = Azul) no contexto/Product Truth.
- Como o hook `usePublicador` acompanha o kit (polling próprio como `useIaDoPublicador`/palavras-IA) e como a mesa
  se atualiza quando a foto aprovada entra (reler o estado pelo caminho de estrutura, `estruturar`).
- Divisão em planos/ondas (fatia fina ponta a ponta primeiro, como a v24 fez).
</decisions>

## Decisões fechadas depois da pesquisa (04/10, padrões recomendados no 165-RESEARCH.md §9)

- **D-08 (Q5) — painel NATIVO da mesa**, não reuso literal de `PainelCriativosIa`/`KitCriativosGrade`: eles têm
  `route()` fixos do assistente antigo e classes fora da identidade da mesa, e são a área da Fase 162. Refina a D-05:
  a experiência (planejar → confirmar custo → grade de 7 → regenerar → aprovar) é a MESMA; o componente é novo
  (`Mesa/PainelCriativos.jsx` + hook `useCriativosDoPublicador`), entra na lista `CARDS` dos gates.
- **D-09 — controller novo** `MlbPublicadorCriativoController` com rotas `mlb.anuncios.publicador.criativos.*`; NÃO
  reaproveitar as rotas antigas `criativo.*` com token do Publicador (o escopo antigo some com `rascunho = NULL` e
  `planejarKitSobLock` devolveria kit de outro produto). Amarração token↔`pub_rascunho_id` (404 se diferente).
- **D-10 — colunas:** além de `pub_rascunho_id`, `pub_grupo` (string 600, anulável, SEM índice — índice estouraria a
  chave do InnoDB) nas duas tabelas e `pub_imagem_id` (FK `nullOnDelete`) em `ml_anuncio_criativos`.
- **D-11 (Q2) — "aprovado" no Publicador** = entrou em `pub_imagens` (`pub_imagem_id` preenchido, `ml_picture_*`
  nulos). Documentar no docblock do model; a Fase 162 não pode inferir "está no ML" a partir de `aprovado`.
- **D-12 (Q3) — re-adicionar:** slot aprovado cuja foto foi tirada do rascunho pode ser adicionado de novo (a imagem
  está em disco; não paga de novo).
- **D-13 (Q4) — rotas antigas:** teste de regressão provando que token do Publicador não devolve kit alheio pelas
  rotas antigas; o `abort_if` de 1 linha nos métodos antigos só entra se o outro dev concordar (vai no recado).
- **D-14 (Q6) — grupo da variação:** o grupo é o de `ResolvedorGruposImagem` (valor do eixo que define a foto, ou a
  chave da variante quando "fotos por variação" está ligado); o contexto usa todos os valores daquele grupo.
- **D-15 (Q7) — retomar kit:** no máximo um kit ativo por (`pub_rascunho_id`, `pub_grupo`); reabrir o painel retoma o
  kit existente (`criativos.atual`). "Descartar kit e começar de novo" fica fora desta fase.
- **D-16 (Q8) — exclusão de empresa:** apagar `Company`/`MlbEmpresa` apaga criativos pela cascata do Creative Engine
  enquanto o `pub_produto` fica (SET NULL) — aceito, anotar.
- **Referências (critério de Claude fechado):** fotos do próprio rascunho viram cópias EFÊMERAS em
  `creative-referencias/{token}/` via `ReferenciaEfemeraService::guardar()` sem alterá-lo (suposição A2 a provar em
  teste; se falhar, adaptador do lado do Publicador, nunca mexer no serviço sem combinar).
- **`company_id` não é exigido:** a loja vem da conta do produto (`contaOuNula()`), como o Publicador já faz.

<coordination>
## Coordenação com o outro dev (bloqueia a EXECUÇÃO, não o planejamento)

Recado entregue ao usuário em 04/10 para mandar ao outro dev (MB.ECF-100376):
1. A Fase 162 (validador) vai mexer no `CreativeContextBuilder`, `ProductTruthBuilder`, `GerarCriativoIaJob` ou na
   `KitCriativosGrade`? Se sim, qual ordem: 165 antes ou depois da 162?
2. Ok usar a mesma chave `creative_engine_ativo` e a permissão `podeGerar`?

O plano deve deixar claro, por plano, quais arquivos do Creative Engine são tocados, para facilitar essa conversa.
Quando os dois combinarem: remover o bloco `AVISO-COORDENACAO-164` do `CLAUDE.md`, a entrada `hooks` de
`.claude/settings.json` e `.claude/hooks/aviso-coordenacao.mjs`.
</coordination>

<canonical_refs>
## Referências canônicas (leitura obrigatória antes de planejar/implementar)

### Creative Engine (v24.0)
- `.planning/ROADMAP.md` — seções "Fase avulsa — Creative Engine no Publicador novo" (Fase 165) e "Milestone v24.0"
- `.planning/REQUIREMENTS-v24.md` — REQ-IDs FOTO/CTX/TRUTH/PLAN/GEN/APROV/PUB/OPS e decisões D-01…D-06 da v24
- `.planning/phases/160-*/` e `.planning/phases/161-*/` — planos e summaries (decisões 8–10 da 161)
- `.planning/quick/261003-l8o-correcoes-de-uso-real-do-creative-engine/261003-l8o-SUMMARY.md`
- `app/Services/Creative/*` (`CreativeContextBuilder`, `ProductTruthBuilder`, `CreativePlanner`,
  `CreativeKitDespachante`, `CreativeKitPublicacao`, `CreativePermissao`, `CreativeEngineAtivo`,
  `ReferenciaEfemeraService`), `app/Jobs/GerarCriativoIaJob.php`, `app/Jobs/PlanejarKitCriativosJob.php`,
  `app/Models/MlAnuncioCriativo.php`, `app/Models/MlAnuncioCriativoKit.php`, endpoints `criativo*` do
  `app/Http/Controllers/MlbAnuncioController.php`, `routes/mlb_anuncios.php`
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`

### Publicador (Fase 164 + melhorias de 03–04/10)
- `.planning/phases/164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu/` — planos, UI-SPEC e REVIEW
- `.planning/learnings/publicador-ml.md` — §7 (conferência visual no SQLite isolado), §9 (modelo, trava D21/D26,
  trava de linha WR-B02), §10 (melhoria do docx, variações+fotos, 3 colunas)
- `app/Http/Controllers/MlbPublicadorController.php`, `app/Services/Publicador/EditorRascunhoService.php`,
  `ImagemAssetService.php`, `RascunhoRepository.php`, `app/Support/Publicador/Imagem/ResolvedorGruposImagem.php`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx`, `resources/js/Components/Publicador/usePublicador.js`,
  `Mesa/CardFotos.jsx`, `Mesa/CartaoVariante.jsx`, `FotosPorGrupo.jsx` (`BlocoDeFotos`), `apoio.js` (`ITENS`)
- Testes: `tests/Feature/Publicador/*`, `tests/Unit/Publicador/*`, `tests/js/publicador-*.test.js`,
  e os do Creative Engine (`tests/Feature/Phase160*`, `Phase161*`, `Quick261003L8o/*`, `tests/Unit/Creative*`)

### Regras do projeto
- `CLAUDE.md` — GSD por risco (migration em tabela com dado em produção), `git commit -- <caminhos>`, `npm run build`
- `.planning/learnings/desempenho-bonificacao.md` §6 — armadilhas de MariaDB que o SQLite dos testes não pega
</canonical_refs>

<specifics>
## Detalhes concretos

- Empresa de teste para E2E: #459 "Dev 02 Testes API" (única conta liberada para publicar); geração de imagem tem
  custo real — E2E só com confirmação do usuário.
- Fila: o Creative Engine roda na fila `creative` (3 workers) e `high`; depois do deploy, `queue:restart` (não
  `supervisorctl restart`, que mataria uma geração paga no meio).
- Produção: `creative_engine_ativo` estava LIGADO em 03/10.
</specifics>

<deferred>
## Fora de escopo (ideias adiadas)

- Fase 162 (validador Gemini-juiz e regeneração automática) e Fase 163 (custo por projeto/POC) — do outro dev.
- Gerar criativos para várias variações de uma vez (um kit por variação de cada vez nesta fase).
- Remover a ponte "Gerar criativos no assistente antigo" — só depois que a 165 estiver em uso.
</deferred>

---

*Fase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Contexto reunido em 2026-10-04 a partir das decisões do usuário na conversa*
