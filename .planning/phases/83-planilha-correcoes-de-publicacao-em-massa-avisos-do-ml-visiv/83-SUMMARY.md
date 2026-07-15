---
phase: 83-planilha-correcoes-de-publicacao-em-massa-avisos-do-ml-visiv
date: 2026-07-15
status: pending-checkpoint
requirements: [FIX-83-1, FIX-83-2, FIX-83-3, FIX-83-4, FIX-83-5, FIX-83-6]
one-liner: "Publicação em massa destrava, o resultado chega na tela com o motivo legível de cada falha, e a planilha ganha Delete, preço BR e remover categoria"
commits:
  - 226f0dc feat(83-01) normalizarPreco, campos de status do servidor e runner de teste JS
  - e69d4a4 fix(83-02) publicacao em massa destrava e o resultado chega na tela
  - 0ae7423 feat(83-03) status terminal na grade, Delete em todas as colunas e preco BR
  - 226ca89 feat(83-04) painel de erros/avisos, contadores e remover categoria
---

# Fase 83 — Correções da planilha — Summary

## O bug principal (FIX-83-1 + FIX-83-2) — eram o MESMO bug, em duas camadas

O usuário reportou como dois problemas ("o botão fica em loading para sempre" e "os erros só
aparecem no individual"). São o mesmo:

1. **`publicarLote` não tinha `finally`.** `setPublicandoLote(false)` só existia dentro do `catch` —
   no caminho de **sucesso** o botão nunca destravava. Agrava: `router.reload({only:['rascunhos']})`
   é recarga **parcial** do Inertia e **preserva o estado local do React** (o componente não remonta,
   o estado não zera).
2. **A página lia a prop `rascunhos` UMA vez**, num `useEffect(..., [])`. Mesmo com o `finally` e um
   polling perfeito, **nada mudaria na tela** — faltava o merge de volta para `abas[].linhas[]`.
   Essa era a peça central da fase, e nenhum dos dois consertos sozinho resolveria.
3. **O reload único de 1500ms perdia a corrida**: o backend escalona os jobs a **3s por posição**
   (com 4 linhas, o último termina em ~12s). A tela recarregava cedo, via tudo "publicando" e nunca
   mais perguntava.

**Correção:** `finally` + `mesclarStatusRascunhos` (função pura, testada) + polling copiando a forma
que **já roda em produção no wizard** (`setInterval` 3s, `only:['rascunhos']`, para quando ninguém
mais publica) + **teto de segurança** (o wizard não tem; o lote aceita 50 rascunhos e um job morto
deixaria o polling infinito — que é o próprio bug desta fase, de outra forma).

### Dois achados que teriam virado bug

- **`massa()` não devolve rascunho `publicado`** (o `whereIn` só traz rascunho/validado/erro/
  publicando). A regra ingênua ("sumiu da prop → mantém a linha") deixaria **toda linha publicada
  com sucesso travada em 'publicando' para sempre**. A regra correta — "sumiu da prop **enquanto
  publicava**" == sucesso — está testada, junto com o caso oposto: linha recém-criada também não
  está na prop, e marcá-la como publicada apagaria o trabalho do usuário.
- **Delete não funcionava em 8 das 10 colunas** (verificado no bundle real da lib, não em docs): as
  custom cells da Fase 82 não herdam o `onDelete` nativo. E havia um segundo bug que ninguém tinha
  visto: no Tipo, o **nosso próprio** `onCellsEdited` engolia o Delete (`normalizarTipoAnuncio('')`
  → `null` → `if (cod)` descartava).

## Requisitos

| Req | O que era | Como ficou |
|-----|-----------|------------|
| FIX-83-1 | Botão em loading eterno | `finally` sempre destrava |
| FIX-83-2 | Resultado nunca chegava na tela | merge + polling com teto; glifo ✓/✕ por linha; chips "N publicado(s)" / "N com erro na publicação" |
| FIX-83-3 | Erro da API cortado | `<details>` com a resposta **crua** + botão "Copiar erro" |
| FIX-83-4 | "4 com avisos" sem como ver | painel âmbar: "Linha 4 · categoria · título → motivo" |
| FIX-83-5 | Preço só aceitava `129.99` | aceita `129,99` e `1.234,56` (paste do Excel BR) |
| FIX-83-6 | Sem Delete, sem remover categoria | Delete em todas as colunas; remover categoria move p/ "Sem categoria" |

## Decisões do usuário respeitadas

- **Remover categoria MOVE, não apaga** — nenhum `destroy`; as linhas migram para "Sem categoria".
  Consequência tratada: elas param de contar como publicáveis (`linhaPublicavel` passou a exigir
  `aba.category_id`), senão publicar mandaria `category_id: null` = 400 garantido, uma vez por linha.
- **Preço coerido na ENTRADA** (`onCellsEdited`), não na saída: o autosave chama
  `montarPayloadLinha` a cada 600ms e persistiria `price: null` (`Number('129,99')` = `NaN`) antes de
  qualquer tentativa de publicar. Pior: `errosLocaisLinha` faz `Number(price) > 0` → a linha ficaria
  vermelha com "falta preço" e o campo visivelmente preenchido.

## Gates — desta vez de verdade

A Fase 82 teve os `<verify>` escritos como `node -e` inline com regex escapado; o bash come as
barras (`\\\\s` → `\\s`) e o gate passa por motivo errado. Nesta fase:

- **`tests/js/` com `node --test`** (nativo do Node 24, zero dependência nova; `phpunit.xml` só
  coleta `tests/Unit` e `tests/Feature`, então não interfere).
- **49 testes verdes.** As funções puras têm teste de **comportamento**; a fiação tem gates
  estruturais que leem a fonte **sem comentários** (a prosa pt-BR deste projeto cita os próprios
  identificadores — um `grep -c` cru passaria pelo motivo errado).
- O merge é testado contra o risco real: linha com `salvo: false` sendo digitada + rascunho com
  `payload.title` diferente → `title`/`price`/`attrs`/`origem` saem **idênticos**. Mais dois
  `assert.strictEqual` de identidade de referência: sem eles o canvas redesenha a cada 3s e o cursor
  pisca.
- Durante a execução, **3 gates meus falharam e o código estava certo** (slice na ordem errada,
  regex sem acento, escaping do heredoc) — corrigi os testes, não o código.

## Verificação

- `npm run test:js` **49/49**; `npm run build` verde; `php artisan test --filter=Phase82` **9/9**.
- Comportamento de canvas e o fluxo real de publicação **não são verificáveis aqui**: 0 empresas com
  `ml_token` no banco local, sem OAuth ML. **Checkpoint só em produção.**
- **Nada deployado.**

## Checkpoint (bloqueante) — roteiro em produção

1. Publicar em massa → **o botão destrava** (não fica em loading eterno).
2. Enquanto publica: glifo `↑` amarelo nas linhas; a cada 3s a tela atualiza sozinha.
3. Ao terminar: linha publicada fica **verde com ✓**; linha que falhou fica **vermelha com ✕**.
4. Abaixo da grade: "N linha(s) falharam ao publicar" → expandir → **resposta completa da API** +
   "Copiar erro".
5. "Validar tudo" → o painel âmbar lista os avisos por linha ("Linha 4 · … → motivo").
6. Selecionar células e apertar **Delete** → limpa (testar Título, Preço, Gênero e Tipo).
7. Digitar `129,99` no Preço → salva e publica sem erro de preço.
8. "Remover categoria" → confirma → as linhas aparecem em "Sem categoria", **nenhuma sumiu**;
   a PublishBar mostra "N sem categoria" e não oferece publicá-las.

## Fora do escopo (fases já mapeadas)

- **Phase 84** — Ctrl+Z/Ctrl+Y (a lib não tem nativo; decisão do usuário: undo/redo **local**,
  ~50 ações, sem desfazer linha já persistida).
- **Phase 85** — arquitetura de schema de colunas (fixas + dinâmicas por categoria, provider por
  marketplace para ML/Amazon/Shopee/Magalu) + validação local prévia antes de chamar a API.
- **Phase 86** — cores por grupo, grupos colapsáveis e identidade visual das variações (dependem do
  schema da 85). Referência do usuário analisada: o `.xlsm` é template **Amazon** de vestuário
  (`fptcustom`, 477 colunas, 10 grupos por cor) — usar o padrão, não o conteúdo.
