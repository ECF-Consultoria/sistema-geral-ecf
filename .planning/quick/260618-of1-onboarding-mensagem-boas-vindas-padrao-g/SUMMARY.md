---
quick_id: 260618-of1
slug: onboarding-mensagem-boas-vindas-padrao-g
date: 2026-06-18
status: complete
---

# SUMMARY — Onboarding: mensagem de boas-vindas padrão + Grant configurável por polo

## O que foi entregue

Mensagem de boas-vindas padronizada e **copiável por empresa** na aba "Link & Status"
do Onboarding (`/implementacao`), com o **link do formulário da empresa** e o **link do
Grant da região** já preenchidos automaticamente. Mensagem e grants são configuráveis em
**Padrões Globais**.

## Como funciona

- **Padrões Globais** (modal "Padrões") ganhou duas seções:
  - **Grants por Polo**: para cada polo (`ONB_POLO_OPCOES`), um campo de nome do projeto
    + URL do Grant. Pré-preenchidos com os 4 grants fornecidos.
  - **Mensagem de Boas-vindas**: textarea com o texto padrão + ajuda dos placeholders.
- **Por empresa** (modal "Ver" › aba "Link & Status"): bloco "Mensagem de Boas-vindas"
  mostra o texto renderizado e um botão **"Copiar mensagem"**. Placeholders substituídos:
  - `{link_formulario}` → URL do workspace da empresa
  - `{link_grant}` / `{projeto_grant}` → resolvidos pelo **polo cadastrado** da empresa
  - `{empresa}` → nome da empresa
  - Aviso âmbar quando a empresa não tem polo ou o polo não tem Grant configurado.

## Decisão de design

- Grant resolvido pelo **polo da empresa** (`mlb_empresas.polo`), não por texto livre →
  sem divergência de nomes.
- Polo **"Bento Gonçalves"** mantido (sem renomear / sem migração); recebe o Grant da
  **Serra Gaúcha**, com o nome do projeto configurável.

## Arquivos alterados

- `app/Models/MlbConfiguracao.php` — `implementacaoPadroes()` + constantes
  `MENSAGEM_BOAS_VINDAS_PADRAO` e `GRANTS_POR_POLO_PADRAO`.
- `app/Http/Controllers/MlbImplementacaoController.php` — validação em `salvarPadroes()`.
- `resources/js/Pages/Mlb/Implementacao.jsx` — `PadroesModal` (2 seções novas + submit) e
  `ImplModal` (mensagem renderizada + copiar + aviso).
- `tests/Feature/Phase33OnboardingFichaTest.php` — 2 testes novos.

## Verificação

- `php artisan test --filter Phase33OnboardingFichaTest` → **13/13 verdes (126 assertions)**.
- Suite Onboarding/Implementação (filtro amplo) → **32/32 verdes** antes dos novos testes.
- `npm run build` → verde.
- Defaults conferidos via script: mensagem com 3 placeholders + 4 grants corretos.

## Notas

- `public/build` não é versionado — build roda na VPS no deploy (hashes Windows não batem).
- Sem deploy (conforme pedido). Sem migração — os defaults vêm do merge em
  `implementacaoPadroes()` e ficam editáveis/persistidos via Padrões Globais.
