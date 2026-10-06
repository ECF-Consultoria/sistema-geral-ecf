---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 17
subsystem: fechamento-da-fase
tags: [gate, baseline, mariadb, gabarito, conferencia-visual, checkpoint]
requires: ["167-12", "167-16"]
provides:
  - baseline completado (coluna "Depois (167-17)" + rodada final depois do 167-21)
  - prova final da migration no MariaDB 10.4 local
  - gabarito da planilha real conferido (só contagens)
  - learnings §31 do Portal do Cliente
  - resposta do checkpoint humano (reprovado → lacunas 167-18..21 + D-31/D-32 → aprovado)
affects: []
key-files:
  modified:
    - .planning/phases/167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas/167-BASELINE-TESTES.md
    - .planning/learnings/portal-do-cliente.md
    - resources/js/Components/SpreadsheetGrid.jsx
    - tests/js/estrutura-grid-produtos.test.js
decisions:
  - "Checkpoint de 05/10 REPROVADO: a tela era uma planilha dentro do sistema → D-23/D-24 e planos de lacuna 167-18 (lista + ficha)"
  - "Referências visuais do usuário (06/10) → D-25..D-30 e planos 167-19 (ficha em página), 167-20 (Visual grande/Lista), 167-21 (fidelidade)"
  - "Na conferência final: D-31 (sem bolinha de cor) e D-32 (a lista destaca o produto de onde a pessoa voltou)"
  - "Checkpoint APROVADO pelo usuário em 06/10/2026"
metrics:
  tasks: 3
  completed: 2026-10-06
---

# Phase 167 Plan 17: Fechamento da fase Summary

Gate final verde contra o baseline, migration provada de novo no MariaDB 10.4, gabarito da planilha real batendo
(55 ME1 / 8 ME2 / 6 ME2·Full / 1 pendente) e a tela de Produtos **aprovada pelo usuário em 06/10/2026** — depois de
uma reprovação que mudou o desenho da tela.

## Task 1 — gate, MariaDB, gabarito e learnings

- **Gate** (mesmos comandos do baseline, saída em arquivo, exit conferido). Rodada do 167-17 e rodada final depois dos
  planos de lacuna (detalhe em `167-BASELINE-TESTES.md`):

  | Grupo | Antes | Final (06/10) | Falha nova |
  |---|---|---|---|
  | G1 `PortalCliente/Estrutura` | 83 / 724 | 224 / 1543 | 0 |
  | G2 Publicador (4 arquivos) | 22 / 118 | 22 / 118 | 0 |
  | G3 domínio + menu do Portal | 7 / 155 | 7 / 180 | 0 |
  | G4 `PortalCliente` inteiro | 231 / 1872 | 372 / 2716 | 0 |
  | G5 exclusão/migrations | 15 / 84 | 16 / 89 | 0 |
  | G6 `test:js` | 958 (2 falhas antigas) | 1049, 1047 passam (as mesmas 2) | 0 |

- **MariaDB 10.4 local (só `--path`):** `estrutura_ofertas` 13 linhas antes e depois, 0 com `variacao_id`; rollback das
  duas migrations e re-up sem 1059/1553/1830; `SHOW CREATE TABLE`: `variacao_id bigint unsigned NULL`, `UNIQUE KEY
  eo_variacao_uq`, `CONSTRAINT eo_variacao_fk ... ON DELETE SET NULL`, `eo_company_fk ... CASCADE` intacta. Tabelas
  ficam aplicadas no banco local.
- **Gabarito da aba Produtos real** (lida fora do repo, script no scratchpad apagado): 70 variações, 0 erro do
  normalizador → **55 ME1, 8 ME2, 6 ME2·Full, 1 pendente**. Só contagens registradas.
- **Learnings §31** em `portal-do-cliente.md` (medição da planilha, `nullOnDelete`, peso real × cubado, `MATCH(peso −
  0,0001)`, `SpreadsheetGrid`, "família" = linha de design, custo da variação, estados da categoria, `sticky` inline,
  busca de categoria vazia, e o porquê de não haver planilha na tela).
- **Fix de regressão achado na conferência** (`efecbab8`): coluna congelada do `SpreadsheetGrid` perdia o `sticky` por
  `position: 'relative'` inline — defeito antigo do grid compartilhado que só a tela de Produtos expôs. Fica no
  componente compartilhado (hoje nenhuma tela usa `frozen`).

## Task 2 — ambiente de conferência

SQLite isolado no scratchpad, servidor `php -S 127.0.0.1:8167`, semente que recusa rodar fora do SQLite (testado),
dados fictícios ("Loja Conferência"; depois "Loja Referência" com os 6 produtos das referências + 100 para paginar).
Capturas a 1440/1586 px, celular a 390 px. **Desmontado em 06/10 depois da aprovação**: servidor parado (porta livre,
`/login` sem resposta) e a pasta `produtos-visual/` (SQLite, capturas, scripts) apagada. Nada foi gravado no MariaDB.

## Task 3 — checkpoint humano

1. **05/10 — REPROVADO.** "Não está aprovado porque eu disse que não queria uma planilha dentro do sistema pra esse
   caso." → D-23 (nada de planilha na tela; formulário) e D-24 (importação por arquivo continua). Plano de lacuna
   **167-18**: lista de cartões + ficha em painel.
2. **06/10 — referências visuais do usuário** (`167-REF-*.jpg`, `167-REFERENCIA-VISUAL.md`): layout 1:1 com as cores
   do sistema, ficha em página inteira estilo Bling, Visual grande/Lista, foto só como quadro (D-25..D-30). Planos de
   lacuna **167-19** (ficha em página, rotas `/novo` e `/{id}`, allowlist com id numérico), **167-20** (Visual grande
   e Lista, volta preservando estado) e **167-21** (passe de fidelidade com capturas comparadas às referências).
3. **06/10 — ajustes pedidos na conferência:** **D-31** sem bolinha de cor (`f831c4d5`) e **D-32** a lista destaca o
   produto de onde a pessoa voltou, saia como sair da ficha (`ac30e384`, provado no navegador: link, voltar do
   navegador, troca forte→leve, recarregar não repete).
4. **06/10 — APROVADO** ("aprovado").

## Commits deste plano

- `417313d3` docs(167): gate final contra o baseline e learnings dos Produtos
- `efecbab8` fix(167): coluna congelada da grade perdia o sticky e desalinhava Ref/Produto do cabeçalho
- `2628611d` docs(167): learnings 31 - sticky inline e busca de categoria vazia
- (decisões e ajustes do checkpoint: `cfeed78a`, `ae4ae257`, `f831c4d5`, `ac30e384`; planos de lacuna 167-18..21 com
  SUMMARY próprio)

## Pendências (fora da fase)

- **Antes do deploy:** contar `estrutura_ofertas` em produção (só leitura — o worktree não tem `.vps_cmd.sh`) e rodar
  as 2 migrations com `--path` (`2026_10_06_100000` e `2026_10_06_100100`).
- **Depois do deploy:** ler uma vez o frete real numa conta conectada, só com "pode" do usuário (D-16).
- **"Sugerir categorias"** age só sobre os produtos da página na tela e, quando o ML não acha nada, mostra "Não deu
  para buscar agora" (o preditor devolve vazio igual nos dois casos). Oferecido ao usuário; não pedido.
- **Produto novo em empresa com mais de 100 produtos** cai na última página; a volta vai para a página 1 (o destaque
  D-32 não aparece porque o cartão não está na página).
- **Foto do produto:** só o quadro com iniciais; upload fica para uma fase de fotos (D-29).
- Push e deploy **só com autorização explícita** do usuário.

## Self-Check: PASSED
