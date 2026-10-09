---
phase: 173-publicador-etapa-2-visao-geral
plan: 07
subsystem: publicador-configuracoes
tags: [publicador, configuracoes-da-conta, identidade-visual, react, inertia]

requires:
  - phase: 173-01
    provides: "rotas mlb.anuncios.publicador.conta.identidade.mostrar/salvar (identidade por CONTA)"
  - phase: 173-03
    provides: "AbasDaConta com a aba 'configuracoes'; BarraDaConta::textoSeguro export nomeado"
  - phase: 173-04
    provides: "MlbPublicadorEntradaController::configuracoes() + rota mlb.anuncios.publicador.configuracoes"
provides:
  - "Página Mlb/Publicador/Configuracoes.jsx — Identidade visual, Conexões (só leitura), Programa e responsável (só leitura)"
  - "useIdentidadeDaContaPorConta.js — hook novo e isolado de identidade por CONTA"
  - "Link 'Gerenciar em Configurações da conta' no topo da etapa Imagens do editor"
affects: [publicador-identidade-visual, publicador-editor]

tech-stack:
  added: []
  patterns:
    - "Parte pura de apresentação separada do hook (ConteudoConfiguracoes) para permitir render test com dado adverso — mesma separação de CampoIdentidade/BarraDaConta"
    - "Hook por CONTA isolado do hook por produto: mesma forma de estado, rotas diferentes, zero import cruzado"
    - "Arquivo de apresentação que precisa de rota própria entra em COM_ROTA no gate de fonte (publicador-mesa.test.js), mesma exceção já aberta para Publicar.jsx"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/useIdentidadeDaContaPorConta.js
    - resources/js/Pages/Mlb/Publicador/Configuracoes.jsx
    - tests/js/publicador-configuracoes-render.test.js
  modified:
    - resources/js/Components/Publicador/Mesa/EtapaImagens.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - tests/js/publicador-editor.test.js
    - tests/js/publicador-mesa.test.js

decisions:
  - "A prop Inertia `identidade` (texto cru de configuracoes()) chega na página mas NÃO é usada pelo componente default — o hook novo sempre busca o texto ele mesmo via GET, mesmo padrão já usado pelo hook de identidade por produto (que também ignora qualquer estado inicial do servidor e sempre faz sua própria leitura). Evita um segundo ponto de verdade no front; o servidor é sempre a fonte."
  - "ERP: só 'declarado', nunca 'conectado' — rótulo monta '{valor} · declarado no onboarding' quando há valor, 'Não informado' quando não há (decisão 8 do handoff, D-13 da Fase 173)."
  - "'Salvo por' nunca aparece — creative_identidades_conta não guarda autor; só 'Salvo em {data}' com o atualizado_em devolvido pela rota."
  - "EtapaImagens.jsx passou a chamar route() diretamente (precisa montar a URL de Configurações) — movido de CARDS para COM_ROTA no gate de fonte de publicador-mesa.test.js, mesma exceção arquitetural já existente para Publicar.jsx (não é uma mudança de regra nova, é aplicar a regra já existente ao caso novo)."

requirements-completed: [CONF-01, CONF-02, CONF-03]

duration: ~55min
completed: 2026-10-08
---

# Fase 173 Plano 07: Configurações da conta — identidade compartilhada com o editor, conexões e programa só leitura Summary

Página `Mlb/Publicador/Configuracoes.jsx` (coluna única de 760px) com Identidade visual da conta (editável, mesmo registro de banco do editor), Conexões e Programa/responsável (só leitura) — mais o link "Gerenciar em Configurações da conta" no topo da etapa Imagens do editor, com diff mínimo (1 linha) no arquivo do outro desenvolvedor.

## O que foi entregue

### Task 1 — `useIdentidadeDaContaPorConta.js` (hook novo, próprio)

Hook paralelo ao hook de identidade por produto (território do editor, nunca tocado): mesma forma de estado (`texto, carregando, salvando, erro, salvar, limparErro`), mas parametrizado por `{ conta }` em vez de `{ produtoId }`, e com `atualizadoEm` adicional (a página usa para "Salvo em"). Fala com as rotas por conta da Fase 173-01 (`mlb.anuncios.publicador.conta.identidade.mostrar/salvar`) via `route()` global + `axios` direto — sem o helper genérico de montagem de rota do editor (uma rota só não precisa dele). Extrator de mensagem de erro (`mensagemDeErro`, export nomeado) copiado localmente, nunca importado do editor.

Zero import do território do editor — confirmado por grep literal (`Mesa/`, `apoio.js`) e por teste de fonte.

### Task 2 — `Configuracoes.jsx`

`ConteudoConfiguracoes` é um export nomeado puro (recebe tudo já pronto, não chama o hook) — a mesma separação já usada em `CampoIdentidade`/`BarraDaConta`, para permitir teste de render real com dado adverso. Três seções:

1. **Identidade visual da conta** — `CampoIdentidade` (reusado de `IdentidadeDaConta.jsx`, mesmo texto de ajuda, mesmo `maxLength={4000}`, mesmo botão Salvar) ligado ao hook novo. Abaixo, "Salvo em {data}" quando `atualizadoEm` vem preenchido; nada quando vem `null` ou em formato inesperado.
2. **Conexões** (só leitura) — Mercado Livre (`SeloConta`), Publicação, Alavancas, Portal (`SeloPortal`), ERP.
3. **Programa e responsável** (só leitura) — `responsavel ?? '—'`.

O componente default (`Configuracoes`) monta `AppLayout` + `BarraDaConta` + `AbasDaConta aba="configuracoes"` + `ConteudoConfiguracoes`, chamando o hook e passando o estado dele como props.

### Task 3 — Link no editor

Uma linha nova em `Editor.jsx` (`<EtapaImagens m={m} produtoId={produto.id} empresa={empresa} />` — `empresa` já existia no escopo da página, usado em outras rotas por conta) e o link em `EtapaImagens.jsx`, acima do campo de identidade:

```jsx
{empresa?.chave && (
    <div className="flex justify-end">
        <Link href={route('mlb.anuncios.publicador.configuracoes', { conta: empresa.chave })} className={LINK}>
            Gerenciar em Configurações da conta
        </Link>
    </div>
)}
```

Nenhuma outra linha da etapa Imagens mudou. `EtapaImagens.jsx` ganhou `route()` direto — o gate de fonte `publicador-mesa.test.js` (que proíbe `route()` em componentes de "apresentação pura") foi ajustado: o arquivo saiu de `CARDS` e entrou em `COM_ROTA`, a MESMA exceção que `Publicar.jsx` já tinha (não é uma regra nova, é aplicar a regra existente ao caso novo).

## Textos finais da tela (copiados literalmente, como pedido)

- Título da seção 1: **"Identidade visual da conta"** (mesmo de `CampoIdentidade`, inalterado).
- Dica da seção 1 (mesma de `CampoIdentidade`): *"Cadastrada uma vez por conta — vale para todos os produtos dela, não só este. Opcional: sem isto, a geração de imagens continua normal. Pode ser só um texto, por exemplo as cores de preferência em hexadecimal e a fonte usada pela empresa."*
- Abaixo do campo, quando salvo: **"Salvo em {dd/mm/aaaa} às {HH:mm}"** (nunca "Salvo por").
- Título da seção 2: **"Conexões"**; subtítulo: *"Só para consulta — nada aqui se edita nesta tela."*
  - Linhas: "Mercado Livre" (selo `SeloConta`), "Publicação" → "Liberada"/"Travada", "Alavancas" → "Liberada"/"Travada", "Portal" (selo `SeloPortal`), "ERP" → `"{rotulo} · declarado no onboarding"` ou **"Não informado"**.
- Título da seção 3: **"Programa e responsável"**; linhas "Programa" e "Responsável" (`"—"` quando nulo).
- Link no editor (`EtapaImagens.jsx`): **"Gerenciar em Configurações da conta"**.

## `EtapaImagens.jsx` — linhas tocadas

`git diff --stat` reporta **17 inserções / 1 remoção** (3 linhas de import + 1 linha de comentário de contexto + 1 bloco condicional de 7 linhas do link + o parâmetro `empresa` na assinatura da função). Nenhuma outra linha do arquivo foi tocada — `IdentidadeDaConta`, `FotosEVariacoes` e `AcervoDaConta` continuam exatamente como estavam.

Em `Editor.jsx`: **1 linha** (acrescentada a prop `empresa={empresa}` à chamada de `EtapaImagens`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — bloqueador do gate de fonte] `EtapaImagens.jsx` movido de `CARDS` para `COM_ROTA` em `publicador-mesa.test.js`**
- **Encontrado em:** Task 3, ao rodar a suíte completa antes de comitar.
- **Problema:** o gate de fonte `publicador-mesa.test.js` tem uma regra "sem rota direta (apresentação pura)" aplicada a todos os arquivos de `CARDS`, e `EtapaImagens.jsx` precisava chamar `route()` para montar o link.
- **Fix:** removido `EtapaImagens.jsx` de `CARDS` e acrescentado a `COM_ROTA` (junto de `Publicar.jsx`, que já tinha a mesma exceção pelo mesmo motivo). Todos os outros gates (tipografia, peso, acento, Select nativo) continuam valendo para o arquivo — só a regra "sem rota direta" foi exceptuada, mesmo tratamento já dado a `Publicar.jsx`.
- **Arquivo:** `tests/js/publicador-mesa.test.js`
- **Verificação:** `node --test tests/js/publicador-mesa.test.js` → 128 passed.

**2. [Rule 3 — bloqueador do gate de fonte] Regex de `publicador-editor.test.js` atualizado para o novo prop `empresa`**
- **Encontrado em:** Task 3.
- **Problema:** um teste de fonte existente (`Editor.jsx — 4 etapas...`) comparava a linha exata `<EtapaImagens m={m} produtoId={produto.id} />` — sem a prop nova.
- **Fix:** regex atualizado para `<EtapaImagens m={m} produtoId={produto.id} empresa={empresa} />`, com comentário explicando a origem (Fase 173, plano 07).
- **Arquivo:** `tests/js/publicador-editor.test.js`
- **Verificação:** `node --test tests/js/publicador-editor.test.js` → 48 passed.

---

**Total de desvios:** 2 auto-corrigidos (ambos Rule 3 — ajustes de gate de teste exigidos pela própria mudança pedida no plano, sem mudança de regra nova).
**Impacto no plano:** nenhum. Os dois ajustes são consequência mecânica e esperada de dar a `EtapaImagens.jsx` uma rota própria — documentados no próprio texto do plano ("se precisar... documente isso no SUMMARY").

## Known Stubs

Nenhum. As 3 seções da tela são funcionais de ponta a ponta (identidade grava de verdade; conexões e programa/responsável leem o JSON real de `configuracoes()`).

## Verificação executada (resultado real)

```
node --test tests/js/publicador-configuracoes-render.test.js   → 15 passed
node --test tests/js/publicador-editor.test.js                 → 48 passed
node --test tests/js/publicador-mesa.test.js                   → 128 passed
grep -rn "Mesa/\|apoio.js" .../useIdentidadeDaContaPorConta.js  → vazio (exit 1)
npm run build                                                   → ✓ built in 36.86s (exit 0)
                                                                   Configuracoes.jsx presente no manifest.json
npm run test:js (suíte completa)                                → 1398 tests, 1396 passed, 2 failed
```

As 2 falhas são as pré-existentes e documentadas nas restrições do plano (`estrutura-grade-glide.test.js` — `RECOLHIDOS_INICIAIS = [G_SECUND]`; `polosEntrantes.test.js` — `FASES_TERMINAIS`), confirmadas por nome e mensagem de erro, nenhuma nova. O total de testes subiu de 1353 (baseline do 173-03-SUMMARY.md) para 1398 por conta do trabalho deste plano (+15 novos) e de outro trabalho em andamento na mesma árvore compartilhada (parcial de PHP visto em `git status` no início da execução, fora do escopo deste plano).

Não executei `php artisan test` — este plano não tocou em nenhum arquivo PHP (confirmado: só `resources/js/**` e `tests/js/**` foram modificados/criados).

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/useIdentidadeDaContaPorConta.js` — FOUND, commit `5fdac73f`
- `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx` — FOUND, commit `6b440a2e`
- `resources/js/Components/Publicador/Mesa/EtapaImagens.jsx` — FOUND, commit `4708c351`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx` — FOUND, commit `4708c351`
- `tests/js/publicador-configuracoes-render.test.js` — FOUND, commits `5fdac73f` (hook) / `6b440a2e` (página)
- `tests/js/publicador-editor.test.js` — FOUND, commit `4708c351`
- `tests/js/publicador-mesa.test.js` — FOUND, commit `4708c351`
- Commits `5fdac73f79385e58dcec9d23b9cf0167d3472b42`, `6b440a2ec9c824e3a4bed4313b5494961639515d`, `4708c351c7ace41c6e27e19c1446e4738ac8e773` confirmados em `git log --oneline`

## Next Phase Readiness

Configurações da conta está pronta e navegável via `mlb.anuncios.publicador.configuracoes` (aba "Configurações" da `AbasDaConta`, já existente desde 173-03). A Visão geral (plano 173-06, em execução paralela na mesma árvore) é independente deste plano — nenhuma dependência cruzada. O critério de aceite "identidade editada em Configurações aparece no editor e vice-versa" está garantido por construção (mesmo controller, mesma tabela `creative_identidades_conta`, mesma chave dupla `company_id`/`mlb_empresa_id` — provado pelos testes de backend do 173-01); não há teste automatizado de ponta a ponta (editor + Configurações) neste plano, só a garantia estrutural de que os dois hooks apontam para a mesma dupla de rotas que o controller já testou cruzado em `IdentidadePorContaTest`.

---
*Phase: 173-publicador-etapa-2-visao-geral*
*Completed: 2026-10-08*
