---
quick_id: 260724-dho
title: Corrigir badge de plataforma na carteira (alinhar ao serviço do vínculo)
type: quick
---

# Quick 260724-dho — Badge de plataforma na carteira reflete o serviço do vínculo, não o mlToken global

## Objetivo
Na carteira, o badge/ícone de plataforma (Mercado Livre vs Shopee) e a flag `has_ml_oauth` são hoje derivados de propriedade GLOBAL da empresa (`mlToken` ativo / `adman_account_id`), não do serviço do vínculo do profissional. Resultado: um profissional que presta serviço SHOPEE para uma empresa que também tem OAuth ML vê o badge do Mercado Livre — dando a entender que o dado exibido é do ML, quando é Shopee. O NÚMERO financeiro já é montado certo por vínculo (`$fonteFinanceiraVencedora`); só o badge diverge.

**Regra:** o badge deve refletir `$fonteFinanceiraVencedora` (a fonte financeira do vínculo daquele profissional, que já aplica o desempate "adman vence" quando o MESMO profissional tem os dois vínculos):
- `$fonteFinanceiraVencedora === 'shopee'` → `fonte='shopee'`, `has_ml_oauth=false` (mostra ícone Shopee, esconde SVG do ML).
- `$fonteFinanceiraVencedora === 'adman'` → mantém a lógica ML/adman atual (badge ML/adman).
- Empresas performance puras: intactas.

## Task 1 — Backend: alinhar badge à fonte do vínculo (PortfolioController)
<files>app/Http/Controllers/PortfolioController.php</files>
<read_first>
- app/Http/Controllers/PortfolioController.php (renderCarteiraProfissional ~605/629-638/720; transparencia ~293-304/309; renderPortfolio ~1673/1687; fontesFinanceirasPorEmpresa ~109-118)
</read_first>
<action>
Em `renderCarteiraProfissional` (~629-638): a montagem de `$fonte` hoje testa `$temMl || $temAdman` ANTES de `$temShopee`. Reordenar/condicionar para que, quando `$fonteFinanceiraVencedora` (já disponível na linha ~605 via `$fontesPorEmpresa->get($c->id)`) for `'shopee'`, `$fonte = 'shopee'`. Só cair no ramo ML/adman quando a fonte vencedora do vínculo NÃO for shopee. Na linha ~720, `'has_ml_oauth' => $temMl` deve virar `has_ml_oauth = $temMl && $fonteFinanceiraVencedora !== 'shopee'` (não mostrar o SVG do ML quando o profissional presta Shopee pra empresa).
Em `transparencia` (~293-304 e ~309): aplicar a MESMA correção usando a fonte financeira do vínculo daquele profissional para essa empresa (mesma variável/mecânica de `fontesFinanceirasPorEmpresa`). `has_ml_oauth` idem.
Em `renderPortfolio` (self-view /portfolio, ~1673/1687): não há campo `fonte`; a flag `has_ml_oauth` (linha ~1687, hoje `$hasMlOauth = $c->mlToken?->status === 'active'`) deve respeitar a fonte financeira vencedora do vínculo do próprio profissional — se ele presta Shopee pra empresa, `has_ml_oauth=false`. Se `fontesFinanceirasPorEmpresa`/`$fonteFinanceiraVencedora` não estiver disponível nesse método, computar a fonte por empresa do MESMO jeito que as outras funções (reaproveitar `fontesFinanceirasPorEmpresa`).
Comentários em pt-BR explicando o porquê (badge = serviço do vínculo, não plataforma global da empresa). NÃO alterar a lógica do número financeiro (já correta).
</action>
<verify>Empresa dual (mlToken ativo + vínculo shopee do profissional) → payload da empresa com `fonte='shopee'` e `has_ml_oauth=false`. Empresa performance pura → `fonte` ML/adman e `has_ml_oauth=true` (inalterado).</verify>
<done>Badge/has_ml_oauth alinhados a $fonteFinanceiraVencedora nas 3 funções.</done>

## Task 2 — Teste + build
<files>tests/Feature/PortfolioShopeeCarteiraTest.php (ou suite de carteira existente); resources/js/Pages/Portfolio/*.jsx (só se necessário)</files>
<read_first>
- tests/Feature/PortfolioShopeeCarteiraTest.php
- resources/js/Pages/Portfolio/AdminCarteira.jsx (~515-516 SVG ML por c.has_ml_oauth; ~537 FonteBadge fonte)
</read_first>
<action>
Adicionar caso de teste: profissional só-Shopee em empresa com `mlToken` status='active' → o payload da carteira dessa empresa tem `fonte='shopee'` e `has_ml_oauth=false` (não pinta ML). Atualizar qualquer asserção existente que esperava ML nesse cenário. O `FonteBadge` já mapeia 'shopee' → ícone ShoppingCart/label Shopee; o SVG do ML já é gated por `c.has_ml_oauth` — então a correção do payload deve bastar no front. Se algum JSX precisar de ajuste, fazer o mínimo e rodar `npm run build`. Se nada mudar no JSX, não precisa build.
</action>
<verify>php artisan test do arquivo de teste verde; se tocou JSX, npm run build sem erro.</verify>
<done>Teste cobrindo só-Shopee+mlToken=shopee/has_ml_oauth=false; build ok se aplicável.</done>

## must_haves
- Badge da carteira reflete a fonte do vínculo do profissional (Shopee quando presta Shopee), não o mlToken global.
- has_ml_oauth=false para empresa dual quando o profissional presta Shopee.
- Empresas performance puras inalteradas.
- NÃO deployar (deploy é feito depois pelo orquestrador junto do item operacional).
