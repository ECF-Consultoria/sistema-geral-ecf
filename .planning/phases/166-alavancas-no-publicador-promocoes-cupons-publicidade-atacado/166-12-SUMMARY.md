---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 12
subsystem: publicador
tags: [alavancas, react, inertia, panorama, historico, publicidade]
requires: [166-10]
provides:
  - barra Publicar | Alavancas na tela da empresa (AreaTabs)
  - página Mlb/Publicador/Alavancas (panorama, histórico, aba Publicidade só leitura)
  - useLeitura/rota (leitura JSON pelo Ziggy), rotulos.js, formato.js
  - gate de fonte automático para todo arquivo de Components/Mlb/Alavancas
affects: [166-13, 166-14, 166-15, 166-16]
key-files:
  created:
    - resources/js/Components/Mlb/Alavancas/AreaTabs.jsx
    - resources/js/Components/Mlb/Alavancas/AvisoAlavancasTravadas.jsx
    - resources/js/Components/Mlb/Alavancas/useAlavancas.js
    - resources/js/Components/Mlb/Alavancas/rotulos.js
    - resources/js/Components/Mlb/Alavancas/formato.js
    - resources/js/Components/Mlb/Alavancas/Panorama.jsx
    - resources/js/Components/Mlb/Alavancas/Historico.jsx
    - resources/js/Components/Mlb/Alavancas/AbaPublicidade.jsx
    - resources/js/Pages/Mlb/Publicador/Alavancas.jsx
    - tests/js/publicador-alavancas.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx (só import + bloco da barra)
metrics:
  completed: 2026-10-05
  tasks: 3
---

# Fase 166 Plano 12: Entrada visual das Alavancas Summary

A barra "Publicar | Alavancas" entra na tela da empresa e a área Alavancas abre no panorama da conta, com histórico de escritas e a aba Publicidade (só leitura), no padrão visual do Publicador e com gate de fonte que cobre sozinho todo arquivo novo da pasta.

## Commits

| Task | Mensagem |
|---|---|
| 1 | `feat(166-12): barra Publicar | Alavancas e a página da área` |
| 2 | `feat(166-12): panorama da conta e histórico de alterações` |
| 3 | `feat(166-12): aba Publicidade das Alavancas (só leitura)` |

(hashes: `git log --oneline --grep "166-12"`)

## O que foi feito

- `AreaTabs` troca de rota (`mlb.anuncios.publicador.produtos` / `...alavancas.index`, só `{conta}`), vale para empresa-N e company-N. Em `Produtos.jsx` o diff é só o import e o bloco da barra acima do `ModoAnuncioTabs`.
- `Alavancas.jsx`: sem `tem_conta` mostra o cartão "Conecte a conta..." + `LinkReconexao` e não monta nenhum leitor; conta não liberada recebe faixa calma (sem vermelho/âmbar) com o motivo do servidor; aba atual em `?aba=` via `history.replaceState`. `ABAS` tem só Publicidade (Promoções, Cupons e Atacado entram nos planos seguintes).
- `Panorama`: 4 cartões independentes, cada um com "Não deu para ler agora." + "Tentar de novo" (`atualizar: 1`); botão "Atualizar".
- `Historico`: filtros com select nativo, paginação, linha abre por clique/Enter e mostra payload e resposta em `<pre>` com `JSON.stringify`.
- `AbaPublicidade`: período 7/30/90 dias, campanhas, grupos de anúncios por campanha e "fora de campanha", bonificações; nenhum botão de escrita.
- `useLeitura` monta a URL só por `rota(nome, conta, params)` (Ziggy), descarta resposta velha.

## Verificação (números)

- `node --test tests/js/publicador-alavancas.test.js`: 67 testes, 0 falhas.
- `npm run test:js`: 777 testes, 775 passam, 2 falhas, ambas em arquivos que este plano não toca: `FASES_TERMINAIS cobre as três fases de saída...` (baseline Polos) e `Características secundárias nasce recolhido` (`tests/js/estrutura-grade-glide.test.js`). Nenhum teste de Alavancas/Publicador falha. Obs.: o orquestrador listou "Polos: planilha e FASES_TERMINAIS" como baseline; aqui a segunda falha é a do grade-glide, não medida contra um HEAD limpo — nenhum arquivo dela foi alterado por este plano.
- `npm run build`: exit 0. `public/build/manifest.json` (ignorado pelo git): mtime 2026-10-04 20:22:42 antes, 2026-10-05 00:24:39 depois; contém `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` e o bundle tem o nome de rota `mlb.anuncios.publicador.alavancas` (`Alavancas-*.js`, `AreaTabs-*.js`). `node_modules` do worktree já era o link para outro worktree e funcionou sem mexer.
- `tests/Feature/Publicador`: 550 testes, 3.157 asserções, exit 0 (inclui `AcessoAlavancasTest`).

## Deviations from Plan

1. **[Contrato real do 166-10] histórico devolve `linhas`, não `itens`** (o plano supunha `itens`). A tela usa `linhas` e `paginacao`.
2. **[Contrato real] `ad_groups` trazem `campanha_id` e `fora_de_campanha`** (não `campaign_id`). A aba filtra por `campanha_id` (`'0'` = fora de campanha). A string `campaign_id` só aparece num comentário do componente.
3. **Ad Groups sem `itens`**: o parâmetro `itens` é opcional no servidor; a aba pede os 50 de maior clique da janela e filtra por campanha no cliente (uma leitura por campanha aberta). Não há lista de produtos para filtrar pelo servidor neste plano.
4. **[Rule 1 - bug do meu próprio edit]** o primeiro commit da Task 1 saiu com `<ModoAnuncioTabs empresaId` quebrado (`<ModoAnuncioTabsempresaId`); detectado ao conferir o diff, corrigido com `--amend` antes de qualquer outro commit (local, sem push).
5. **[Gate]** o teste de contrato de rotas, lendo o arquivo de rotas a partir do grupo das Alavancas, pegava também rotas posteriores (`{company}`); passou a ler só até o fim do grupo (commit da Task 2). Também acrescentei teste de `janelaDeDias(dias, hoje)` com data fixa (sem relógio real).
6. Faixa não liberada aceita `children` (motivo do servidor) além do texto fixo combinado.

## Known Stubs

Nenhum. `ABAS` listar só Publicidade é intencional (Promoções, Cupons e Atacado chegam nos planos 166-13 a 166-15).

## Threat Flags

Nenhum além do registro do plano (T-166-56: só texto pelo React e `JSON.stringify`, gate proíbe `dangerouslySetInnerHTML`; T-166-58: sem `tem_conta` nenhum leitor é montado; T-166-59: leitura sob demanda, "Atualizar" explícito).

## Self-Check: PASSED

Arquivos criados conferidos; 3 commits `feat(166-12)` no branch `feat/publicador-ml-261001`; `ModoAnuncioTabs.jsx` intocado; STATE.md e ROADMAP.md intocados; sem push, deploy nem chamada ao ML.
