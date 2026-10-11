---
quick_id: 261010-wv1
titulo: Rodapé do painel lateral do produto — uma única ação principal
tipo: frontend
ponto_de_partida: 6dc843d4
---

# 261010-wv1 — Rodapé do painel lateral do produto: uma única ação principal

## Objetivo

Item 10 do Bloco B do briefing (§17): o painel lateral do produto (`PainelDoProdutoLateral`)
renderiza **sempre dois botões** no rodapé — "Abrir produto" + a ação principal de
`acaoPrincipal(status)`. Cruzando com a tabela `ACOES`, isso produz **três** defeitos:

| `status.chave` | rótulo / destino | Defeito |
|---|---|---|
| `publicado` | "Abrir" / `produto` | dois botões, **mesmo destino** (o que o usuário viu) |
| `publicando` | "Acompanhar" / `painel` | **NO-OP**: `onAcao` tem `if (destino !== 'painel')` — clicar não faz nada |
| `parcial`, `erro` | "Ver erro" / `painel` | **NO-OP** pelo mesmo motivo |
| desconhecida | `ACAO_PADRAO` "Abrir produto" / `produto` | dois botões com o **rótulo idêntico** |
| `rascunho`, `conferir`, `pronto` | "Começar rascunho"/"Continuar"/"Publicar" / `editor` | ✅ correto, lugares diferentes |

O no-op é o pior: contraria o §29 ("um funcionário novo deve entender sozinho qual é a
próxima ação") porque o botão não é só redundante — ele mente.

**O drawer NÃO é removido.** A segunda pergunta do §17 já foi respondida pelo usuário: o
drawer se sustenta (hospeda a Sugestão de kit, a barra do que falta conferir e os links dos
MLBs — nada disso está na linha).

## Decisão de desenho

O conserto é **só no rodapé do painel**. A tabela `ACOES`, o `ACAO_PADRAO` e
`acaoPrincipal()` **não mudam** — a linha da lista lê o mesmo `acaoPrincipal` e o botão dela
está correto.

Regra: o segundo botão só existe quando ele leva a um lugar **diferente** de "Abrir produto",
isto é, quando `destino === 'editor'`. Whitelist positiva: destino em formato inesperado
(objeto, array, nulo, ausente) cai no botão único, nunca em dois botões iguais.

- `destino === 'produto'` → **um botão**: "Abrir produto" (`onAbrirProduto` → `abrir(produto)`,
  exatamente o mesmo destino que `irPara('produto', …)` faria).
- `destino === 'painel'` → **um botão**: nada de botão que não dispara. Verificado: o que o
  botão prometia está no corpo do painel — o selo de situação ("Publicando" com spinner,
  "Parte publicada", "Não publicado"), o "N de M" e a lista dos MLBs no ar na seção Anúncios,
  e o "atualizado há…". E `ProgramasPublicadorService::produtosParaTela()` devolve
  `status = {chave, rotulo, faltam}` — **não existe mensagem de erro no dado desta tela**, logo
  o botão no-op não tinha nada a mostrar. Quem quiser mais continua com "Abrir produto".
- `destino === 'editor'` → **continuam os dois** (caminho principal da Fase 1).

O botão único fica com o estilo secundário que "Abrir produto" sempre teve: o `estilo` da ação
(que pode ser `erro`, vermelho) pertence ao botão de ação, e sem botão de ação não há botão
vermelho. O sinal vermelho não se perde — o selo do corpo é vermelho.

Fallback do rótulo da ação deixa de ser `'Abrir produto'` (que duplicava o rótulo) e passa a
ser `'Abrir no editor'`, honesto para o único destino que ainda renderiza o segundo botão.

## Tarefas

1. **auto** — `resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx`: rodapé com
   uma única ação; segundo botão por whitelist `destino === 'editor'`; `data-rodape-abrir` e
   `data-rodape-acao` para o teste contar botões; comentário pt-BR explicando os três casos.
   Ajustar o comentário do `onAcao` em `Pages/Mlb/Publicador/Produtos.jsx` (só comentário — a
   guarda `destino !== 'painel'` fica como defesa).
2. **auto** — `tests/js/publicador-produtos-layout.test.js`: corrigir a asserção existente do
   rodapé (`/>Abrir</` deixa de valer no `publicado`) e acrescentar render real por destino —
   `produto`, `painel` (os três status), `editor` (os três status) e `acao` lixo/nulo/ausente.
   Cada asserção nova quebra se o segundo botão voltar.
3. **auto** — `npm run build` + conferir o manifest pelo JSON.

## Gates

- Baselines medidos ANTES: PHP `tests/Unit/Publicador tests/Feature/Publicador` = 1761/0 falhando;
  JS = 2205 testes / 2204 pass / 1 fail (`estrutura-grade-glide`, pré-existente, não corrigir).
- `npm run build` obrigatório; manifest lido por JSON (`package.json` é `"type":"module"`).
- `git diff --stat 6dc843d4..HEAD -- app/ database/` → vazio.
- Nada de deploy, push, VPS, worktree, pint. Nada do editor nem da entrada da Fase 2.
