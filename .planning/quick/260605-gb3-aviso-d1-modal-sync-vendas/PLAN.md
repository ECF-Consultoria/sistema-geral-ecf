---
quick_id: 260605-gb3
slug: aviso-d1-modal-sync-vendas
date: 2026-06-05
type: quick
---

# Quick Task: Aviso D-1 da Adman no modal Sync Vendas + Preços

## Descrição

Adicionar um aviso de defasagem D-1 dentro do modal `SyncVendasModal`
(`resources/js/Pages/Mlb/Empresas.jsx`), espelhando o disclaimer já existente
no Dashboard principal — mas **adaptado**, porque aqui o sync é **manual**, não
automático.

## Contexto / decisões

- **Fonte confirmada = API Adman.** O modal e o botão "Sync Vendas + Preços"
  consultam a Adman (ver `Empresas.jsx:99` "Consulta a API Adman..." e o tooltip
  "Sincronizar vendas via Adman" no ícone por empresa). Logo, o aviso "D-1 da
  Adman" é factualmente correto neste ponto.
- **Diferença vs. dashboard:** o dashboard tem sync automático diário às 11h
  (`adman:sync` em `routes/console.php`). Já o Sync Vendas das publicações é
  **manual** (botão → modal → escolhe mês → "Sincronizar"). Não existe sync de
  vendas das publicações agendado no scheduler (só `mlb:sync-vendas-logs-cleanup`
  às 03:20, que apenas limpa logs). Por isso **NÃO** se copia a frase
  "Sincronização automática diária às 11h" — seria enganoso.
- **Texto escolhido (adaptado, aprovado pelo usuário):**
  "Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. A
  sincronização aqui é manual, por mês."
- **Local escolhido:** dentro do modal, logo abaixo da descrição existente. O
  modal é compartilhado entre o sync por empresa e o "Todas as empresas", então
  uma única edição cobre os dois casos.

## Tarefas

1. Inserir bloco de aviso D-1 entre a descrição (`<p>`) e o `<form>` em
   `SyncVendasModal`, estilo consistente (ícone `Clock` já importado, borda/bg
   `white/[0.08]`/`white/[0.03]`, texto `text-white/40 text-[11px]`).
2. `npm run build` (convenção do projeto após edição JSX).

## Critérios de aceite

- [ ] Aviso D-1 visível no modal (sync por empresa e "Todas").
- [ ] Texto NÃO afirma sync automático.
- [ ] Build passa sem erros.
