---
quick_id: 260731-dg9
slug: colunas-case
date: 2026-07-31
status: complete
commits:
  - 7848bafa
---

# Quick 260731-dg9 — Match de colunas case-insensitive no `polos:sync-planilha`

## O que foi feito

O índice de colunas do `polos:sync-planilha` passou a registrar a chave **exata** do cabeçalho
e, num segundo passe, um **alias normalizado** (minúsculas + espaços colapsados) como fallback.
O `$get()` resolve exato → alias → `''`; a checagem de colunas obrigatórias usa a mesma regra.
Helper novo: `chaveCol()`.

## Por que

A constante `TEXT_IMPL` procurava `'Reunião de onboarding'` (o minúsculo) e a planilha traz
`'Reunião de Onboarding'`. O `isset()` falhava em silêncio: `mlb_implementacoes.reuniao_onboarding`
**nunca era gravado**, sem erro e sem aparecer no relatório de "Campos alterados" — a falha só
apareceu ao conferir coluna por coluna do relatório contra o cabeçalho real da planilha.

Vale notar que a `mapetamento_polosv5.xlsx` (sync de 22/07) tem o mesmo cabeçalho, ou seja, o
campo já vinha sem sincronizar desde então.

## Decisão de desenho

Alias como **fallback**, não substituição da chave exata. A v5 da planilha tinha `Fase` (col 3)
e `fase` (col 0) como colunas distintas; normalizar tudo faria uma engolir a outra. Com a chave
exata tendo precedência e o alias entrando só na primeira ocorrência (`??=`), nenhuma planilha
já processada muda de comportamento.

## Verificação

Teste da resolução contra os cabeçalhos reais das duas planilhas (3107 e v5), comparando índice
antigo vs novo para as 22 colunas que o comando consome:

- ganho: `'Reunião de onboarding'` → coluna 11 (`'Reunião de Onboarding'`) — nas duas planilhas
- regressões: **0** nas outras 21 colunas
- precedência preservada na v5: `'Fase'` continua resolvendo para a coluna 3 (e não para a 0)

Em produção, após o deploy, o dry-run passou a listar `reuniao_onboarding: 394` no relatório e o
`--apply` gravou o campo nas 390 fichas ativas (266 `Sim`, 95 `Não`, 28 `Agendada`, 1 `Não compareceu`).

## Fora de escopo (registrado como pendência)

- Colunas novas da planilha ainda não mapeadas: `Data de entrada`, `Suspensão`, `Chamado suspensa`,
  `Link da Planilha`.
- `Publicação = "Sim"` (1 linha: `BB Casa Decor`) não existe no `ESTAGIO_MAP` — o `estagio` fica
  intocado e o comando reporta o valor como não mapeado.
- Match do sync ainda é **cust_id-first sem fallback para nome**: linha com `cust_id` que não casa
  cria registro novo em vez de achar o equivalente sem `cust_id`. Foi a causa das 38 duplicatas
  potenciais do sync de 31/07, contornadas por reconciliação prévia via token da ficha.

## Deploy

**DEPLOYADO 260731** — deploy isolado a pedido do usuário (local estava 145 commits atrás):
worktree a partir de `origin/main`, push fast-forward `66808af1..7848bafa`, e na VPS
`git reset --hard origin/main` (2 arquivos). Sem build Vite, sem migrate, sem restart de workers —
a mudança é de um comando de console PHP.
