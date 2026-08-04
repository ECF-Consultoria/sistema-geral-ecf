---
task: Nova opção de Integrador Logístico + troca de "Banida" por "Não iniciado" na Publicação
date: 2026-08-04
slug: mek-integrador-me-e-publicacao-nao-iniciado
status: complete
---

# Ajuste de opções: Integrador Logístico e Publicação

## Contexto

Dois ajustes de listas de opções pedidos pelo usuário:

1. **Link do cliente** (`/implementacao/{token}` — checklist público): o item
   *Integrador Logístico* precisa de um valor novo para a empresa que não contrata
   integrador nenhum e despacha tudo pelo Mercado Envios.
2. **Ficha de onboarding** (`/implementacao/{empresa}/ficha`, bloco Produtos):
   o campo *Publicação* não deve mais oferecer **Banida**; no lugar entra
   **Não iniciado**.

## Fonte de verdade

As duas listas nascem em `app/Models/MlbImplementacao.php` e são espelhadas
manualmente no JSX (não há enum compartilhado PHP↔JS — convenção do projeto).

## Alterações

| Arquivo | O quê |
|---|---|
| `app/Models/MlbImplementacao.php` | `INTEGRADOR_OPCOES` ganha `'Trabalhar apenas com Mercado Envios'` (antes de `'Outro'`) |
| `app/Models/MlbImplementacao.php` | `ONB_PUBLICACAO_OPCOES`: sai `'Banida'`, entra `'Não iniciado'` (primeiro da lista) |
| `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` | espelho hardcoded do select do checklist público |
| `resources/js/Pages/Mlb/OnboardingFicha.jsx` | fallback `ONB_PUBLICACAO_OPCOES` (usado quando `opcoes` não chega via props) |

## Riscos verificados

- **Nenhuma validação de enum fechado.** `salvarItem` (checklist público) não valida
  `valor` contra `INTEGRADOR_OPCOES`; `salvarBlocoProdutos` valida `publicacao` como
  `string|max:150` — as `ONB_*_OPCOES` são sugestões do select, não enum. Logo, remover
  `'Banida'` não gera 422 nem quebra registro existente.
- **Dados em produção.** Consulta em `mlb_implementacoes` (2026-08-04) mostra que
  **nenhum registro** usa `publicacao = 'Banida'`. Valores existentes: `Concluido` (96),
  `Não` (210), `Estágio 2` (15), `Estágio 3` (13), `1º Anúncio` (31), `Estagio 1` (25),
  `Sim` (1), `null` (25) — vários já fora da lista de opções (vêm da planilha via
  `polos:sync-planilha`). Nada a migrar.
- **Cor do status.** `'Não iniciado'` não entra em `positivos`/`emProgresso`/`negativos`
  de `corStatus` (ficha) nem em `VAL_*` (Painel Polos) → renderiza neutro, de propósito.
  `'Banida'` fica nas listas de cor para não descolorir registro legado que apareça.

## Verificação

- `npm run build` — OK.
- Consulta de produção confirmando ausência de `'Banida'` — OK.
