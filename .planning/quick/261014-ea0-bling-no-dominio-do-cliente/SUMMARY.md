---
quick_id: 261014-ea0
slug: bling-no-dominio-do-cliente
date: 2026-10-14
status: complete
---

# O Bling no domínio do cliente — concluído

## O que mudou

| Arquivo | O quê |
|---|---|
| `app/Http/Middleware/RestringeDominioDoPortal.php` | `bling/conectar/*` e `oauth/bling/callback` na `PERMITIDO` |
| `app/Services/Bling/BlingOAuthService.php` | link assinado **relativo** + `UrlDoPortal::noDominioDoCliente()` |
| `routes/web.php` | `signed` → `signed:relative` na `bling.connect.landing` (1 linha) |
| `tests/Feature/Bling/BlingNoDominioDoClienteTest.php` | 9 testes (novo) |
| `.env.example` | a URL cadastrada no app, e por que o domínio é o do cliente |

## Por que relativo

`URL::temporarySignedRoute()` **absoluto assina o HOST junto**. Gerar no domínio do painel e trocar o
host depois daria **403 no link que o cliente acabou de receber**. Relativo, a assinatura cobre só
caminho e query, e a rota valida com `signed:relative` — que **continua validando a assinatura**, só
deixa de cobrir o host (há teste de adulteração provando isso).

## Gates

- `artisan test --filter=Bling` → **219 passed**, exit 0 (210 anteriores + 9 novos, zero regressão)
- `tests/Feature/PortalCliente` → **757 passed**, exit 0 — igual ao baseline
- ⚠️ **Controle positivo rodado:** removendo as duas linhas da allowlist, **6 dos 9 testes reprovam**.
  Arquivo restaurado por cópia pristina, com `sha256` idêntico (`9128c93eb1b55d1b`). Sem esse controle
  o gate não provaria nada — o middleware **não roda no localhost**, então um teste mal feito aqui
  passaria com a allowlist vazia.
- O par positivo/negativo está dentro da própria suíte: as rotas do Bling respondem **e** `/dashboard`
  continua dando 404 no domínio do cliente.

## Um achado do teste, que NÃO é defeito do produto

A primeira versão do teste de "regerar" reprovou: duas gerações **no mesmo segundo** produzem a URL
**idêntica**, porque o `expires` é o mesmo e a assinatura é determinística. Não há "anterior" a
invalidar nesse caso — o link é o mesmo. Na operação real sempre passam segundos entre dois cliques. O
teste passou a usar `travel(2)->seconds()` e a afirmar o comportamento de verdade, pelo `data-estado`
da landing: o novo mostra `convite`, o antigo mostra `erro`.

## O que isto conserta

Lacuna da **Fase 184**: o seed §7 dizia que o callback público ficaria "na allowlist do domínio do
cliente", e o plano 07 criou as rotas sem tocar no middleware — o arquivo nem estava no
`files_modified` dele. Como o middleware não roda no localhost, a suíte inteira passava e o 404 só
apareceria em produção.

## Fora

Deploy (precisa de autorização explícita) e a Fase 185.
