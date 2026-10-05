---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 14
subsystem: portal-cliente-frontend
tags: [react, mercado-livre, categoria, frete, mapeamento-estrutural]
requires: ["167-05", "167-10", "167-11", "167-13"]
provides:
  - PickerCategoria (busca de categoria real do ML na célula, D-06)
  - JanelaSugestoesCategoria (revisão em lote, caixas desmarcadas)
  - renderFrete / renderPesoCubado com todos os estados do UI-SPEC (D-16, D-19)
  - botão "Consultar fretes no Mercado Livre" (laço até zerar pendentes)
affects: ["167-15"]
tech-stack:
  patterns: ["editor de célula via editores.categoria", "laço de requisições com teto de voltas conduzido pelo navegador"]
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Produtos/PickerCategoria.jsx
    - resources/js/Components/Portal/Estrutura/Produtos/JanelaSugestoesCategoria.jsx
    - tests/js/estrutura-produtos-ml.test.js
  modified:
    - resources/js/Pages/Portal/EstruturaProdutos.jsx
    - resources/js/lib/produtosEstrutura.js
decisions:
  - "Categoria escolhida no picker marca a linha com _categoriaEscolhida e vai ao POST linhas como categoria_ml_id (nunca o nome como texto); o servidor valida a folha"
  - "Aceitar sugestões em lote grava pela 1ª variação de cada produto marcado pelo mesmo POST linhas e o servidor devolve o grupo"
  - "Lista vazia na busca é dita como 'Nada encontrado' ou, com indisponivel, 'Não deu para buscar agora'; a tela nunca afirma que o ML caiu"
  - "colunasDaGrade ganhou a opção consultando (Set de ids) para o estado 'consultando' do frete"
metrics:
  tasks: 2
  completed: 2026-10-05
---

# Phase 167 Plan 14: Categoria e frete do Mercado Livre na grade Summary

Categoria real do ML escolhida pela pessoa na célula (nada aceito sozinho), sugestão em lote revisada com caixas desmarcadas, e a coluna de frete com todos os estados do UI-SPEC mais a consulta real em lote só sob pedido.

## O que foi feito

**Tarefa 1 (`025e8ec7`)** — `PickerCategoria`: popover de 420 px, busca com debounce de 350 ms já preenchida com o nome do produto, até 8 itens com caminho inteiro; não-folha apagada com "Escolha uma mais específica" e sem seleção; destaque de teclado na 1ª, mas `onCommit` só é chamado por Enter ou clique. Textos de buscando, vazio e indisponível com "Tentar de novo". `JanelaSugestoesCategoria`: `Set` inicial vazio, "Marcar todas", "Desmarcar", "Aceitar marcadas" (desabilitado sem marcadas), "Fechar", "Sem sugestão — escolha na tabela". Página: botão "Sugerir categorias" (existe linha gravada sem categoria confirmada), lotes de 10 `produto_id`, só "Buscando sugestões…".

**Tarefa 2 (`de3f91aa`)** — `renderFrete` (consultando, ME1, pendente, estimativa, ML, não consultado, faixa de referência, alerta de faixa com `AlertTriangle` vindo de `alerta_faixa`) e `renderPesoCubado` ("cobrado" só quando o servidor diz). Botão "Consultar fretes no Mercado Livre" só com `ml_conectado` e linha ME2/ME2·Full; "Consultando…" desabilitado; repete o POST até `pendentes` zerar (teto de 10 voltas); avisa "Fretes atualizados." ou a mensagem de falha geral. Aviso da barra ganhou `role="status"`.

## Deviations from Plan

**1. [Rule 3 - Bloqueio] `produtosEstrutura.js` entrou no commit da tarefa 1**
- **Motivo:** o picker devolve `categoria_ml_id`, e sem ajustar `linhaParaServidor`/`mudou` o POST linhas mandaria o nome da folha como `categoria_texto` (e uma escolha com mesmo nome mas outro id não contaria como mudança). O plano listava só 4 arquivos na tarefa 1.
- **Correção:** `linhaParaServidor` envia `categoria_ml_id` quando a linha tem `_categoriaEscolhida`; `mudou` considera a troca do id.

Fora isso, o plano foi executado como escrito.

## Verificação

- `node --test` dos 3 gates de produtos (ml, produtos, pickers): verde (o novo, 11 testes).
- `npm run test:js`: 1012 testes, 2 falhas, ambas do baseline (`Características secundárias nasce recolhido` e `FASES_TERMINAIS`). Nenhuma falha nova.
- `npm run build`: saiu 0, mtime do `public/build/manifest.json` mudou, `Pages/Portal/EstruturaProdutos` continua no manifest.
- `tests/Feature/PortalCliente`: OK (363 testes, 2628 asserções).
- `grep -cE "\b79\b|6000"` nos dois arquivos: 0.

## Known Stubs

Nenhum. Nenhuma chamada real ao Mercado Livre foi feita (só testes de fonte e testes PHP existentes).

## Threat Flags

Nenhum. T-167-56 (teto de 10 voltas e debounce no JS), T-167-57 (caixas desmarcadas; aceite passa pelo POST linhas que valida folha) e T-167-58 (token nunca chega ao navegador) tratados como no plano.

## Self-Check: PASSED

- Arquivos: PickerCategoria.jsx, JanelaSugestoesCategoria.jsx e estrutura-produtos-ml.test.js existem.
- Commits `025e8ec7` e `de3f91aa` existem.
