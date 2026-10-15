# Fase 201 — Retenção e custo: relatórios em dry-run (v28.0)

**Status:** concluída em 15/10/2026 · **Requisitos:** RET-01, RET-02

## O que foi feito

- **`acervo:relatorio {--dias=90} {--meses=6} {--orfaos}`** — só leitura, nunca apaga:
  1. inventário do disco local por pasta (`publicador`, `imagens`, `miniaturas`, `creative-geradas`,
     `creative-referencias`) e estado do acervo (local × R2, fotos sem ativo, excluindo/falhou);
  2. por conta: imagens, MB, enviadas × IA, entradas no mês anterior e no atual; série mensal de entradas/IA/MB;
     total e excluídas;
  3. candidatos a descarte (só lista): sem uso há mais de N dias, só em rascunho parado não publicado, e contas
     sem atividade (nenhum rascunho atualizado e nenhuma imagem nova em N dias);
  4. `--orfaos`: arquivos locais que nenhum registro aponta (conjunto de referências montado uma vez).
- A definição de "conta inativa" usada aqui é **provisória** — a oficial é decisão da operação da ECF (pergunta
  aberta do plano, item 7.1). Nenhuma exclusão automática por inatividade foi criada.

## Testes

- Coberto por `Phase200/MigracaoDoLegadoParaR2Test::test_relatorio_e_so_de_leitura` (saída esperada; arquivos e
  linhas idênticos antes e depois). Rodado também contra o MariaDB local (consultas válidas com `GROUP BY` estrito).
