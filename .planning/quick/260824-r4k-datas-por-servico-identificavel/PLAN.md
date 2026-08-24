---
quick_id: 260824-es1
slug: datas-por-servico-identificavel
date: 2026-08-24
status: in-progress
---

# "Datas por serviço" deixa de mostrar dois blocos idênticos

## O problema (relatado com print, 2026-08-24)

Depois da consolidação de fases (quick `260824-bte`), a empresa com pagamento escalonado tem
**dois `ContratoServico` do mesmo serviço**. A seção "Datas por serviço" de
`ContratoDetalhe.jsx` (~linha 524) usa `cs.servico_nome` como único título de cada bloco.

Resultado na tela da Mons Bike: **dois blocos escritos "Gestão"**, idênticos, sem nada que os
distinga.

Palavras do usuário:

> "um dos serviço eu preciso definir com 3 meses de duração seria o com desconto parcela de
> 5500 reais, mas não mostra o valor por isso não sei qual deles é o com desconto"

A pessoa precisa preencher datas diferentes em cada fase e **não tem como saber qual é qual**.

## Tarefa 1 — o título identifica a fase

`resources/js/Pages/Admin/ContratoDetalhe.jsx`, o `contratos_servico.map()` da seção "Datas por
serviço": o título passa a trazer, além do nome do serviço, **o valor** e **a quantidade de
parcelas** daquela fase.

`valor_contratado` **já vem** nas props (`ContratoAdminController` ~linha 436) — só não é
exibido. A quantidade de parcelas **não vem** e precisa ser exposta (Tarefa 2).

Formato sugerido (ajuste o que ficar melhor no layout escuro existente, mas mantenha os três
dados legíveis numa linha):

```
Gestão · R$ 5.500,00 · 3 parcelas
Gestão · R$ 6.000,00 · 9 parcelas
```

- Serviço **sem** quantidade de parcelas conhecida: mostra só nome + valor. Não inventar "1
  parcela" nem escrever "null".
- Já existe helper de moeda no arquivo? Reuse. Não criar um segundo formatador.
- Copy **sem jargão** (regra do projeto): "parcelas", não "período", não "P3M", não "billing".

## Tarefa 2 — expor a quantidade de parcelas

`ContratoAdminController`, no `map()` de `contratos_servico` (~linha 431): acrescentar a
quantidade de parcelas da fase.

A fonte é `contratos_servico.hubspot_billing_period`, no formato ISO-8601 `P<N>M` (`P3M` = 3,
`P9M` = 9). **O quick `260824-bte` já fez exatamente essa conversão** em
`ContratoClicksignService` para montar o snapshot — **procure e reutilize**, não escreva um
segundo parser do mesmo formato em outro lugar.

Valor `null` quando o período não existe ou não casa com o formato — nunca chutar.

⚠️ Existe **teste de whitelist de props (PII)** que fixa o conjunto EXATO exposto por `show()`.
Rode-o e ajuste junto; ele é a proteção contra vazar dado sem querer, então o ajuste é
deliberado e deve aparecer no diff.

## Fora de escopo

- Não mexer na consolidação de fases, na composição de `{{plano_parcelas}}`, na guarda de ordem
  ambígua nem no `atualizarCadastro()`.
- Não mudar quais campos são editáveis por fase.

## Testes

- `show()` expõe a quantidade de parcelas: `P3M` → 3, `P9M` → 9.
- Período ausente ou fora do formato → `null` (não 0, não 1).
- Whitelist de props atualizada e passando.
- Regressão zero: empresa com um serviço só continua igual.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- `npm run build` ao final (mexe em JSX). Comentários e copy em pt-BR. Commits atômicos.
