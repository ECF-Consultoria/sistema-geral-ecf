---
quick_id: 260731-dg9
slug: colunas-case
date: 2026-07-31
status: in-progress
---

# Quick 260731-dg9 — Match de colunas case-insensitive no `polos:sync-planilha`

## Problema

O comando `polos:sync-planilha` monta o índice de colunas com a chave **exata** do cabeçalho:

```php
$idx[trim($h)] = $i;
...
$get = fn (string $col) => isset($idx[$col]) ? trim((string) ($r[$idx[$col]] ?? '')) : '';
```

A constante `TEXT_IMPL` procura `'Reunião de onboarding'` (o minúsculo), mas a planilha
`mapeamentopolos_3107.xlsx` (e também a `mapetamento_polosv5.xlsx`, de 22/07) traz
`'Reunião de Onboarding'` (O maiúsculo). O `isset()` falha em silêncio e o campo
`mlb_implementacoes.reuniao_onboarding` **nunca é gravado** — sem erro, sem aviso no relatório.

Confirmado no sync de 31/07: `reuniao_onboarding` não apareceu na lista "Campos alterados",
enquanto as 13 outras colunas de texto apareceram com 378–394 alterações cada.

## Escopo

- Resolver a coluna pelo cabeçalho **exato** e, se não achar, por um **alias normalizado**
  (minúsculas + espaços colapsados).
- Precedência do exato preservada: se a planilha tiver duas colunas que só diferem na caixa
  (a v5 tinha `Fase` e `fase`), a chave exata continua ganhando e o alias só entra como fallback
  da primeira ocorrência — comportamento atual não muda para nenhuma planilha já processada.
- Vale também para a checagem de colunas obrigatórias (`Loja`, `Cust ID`, `Fase`, `Polo`).

**Fora de escopo:** mapear as colunas novas da planilha (`Data de entrada`, `Suspensão`,
`Chamado suspensa`, `Link da Planilha`) e o valor `Publicação = "Sim"` não previsto no `ESTAGIO_MAP`.

## Tarefas

1. Adicionar helper `chaveCol()` que normaliza um cabeçalho (trim + minúsculas + espaços colapsados).
2. Registrar no `$idx` a chave exata e, em segundo passe, o alias normalizado (`??=`, primeira ocorrência vence).
3. `$get()` resolve exato → alias normalizado → `''`.
4. Checagem de coluna obrigatória usando a mesma resolução.

## Verificação

- `php artisan polos:sync-planilha --file=mapeamentopolos_3107.xlsx` (dry-run) passa a listar
  `reuniao_onboarding` em "Campos alterados".
- As contagens das demais colunas permanecem idênticas ao run de 31/07 (nenhuma regressão de match).
