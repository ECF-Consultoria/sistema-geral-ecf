---
tipo: quick
slug: ng9-catch-do-schema-na-lista-de-problemas
data: 2026-10-09
status: complete
commits:
  - 4e9c84f7 test(261009-ng9) — teste RED
  - 0607fe38 fix(261009-ng9) — o catch largo
files_modified:
  - app/Services/Publicador/PublicacaoService.php
  - tests/Feature/Publicador/PublicacaoTest.php
  - .planning/phases/175-publicador-etapa-3-produto-fases/deferred-items.md
---

# O que foi feito

`PublicacaoService::problemas()` pegava só `RegraViolada`. Agora pega `\Throwable`, loga
`Log::warning` e segue com `$schema = null` — o molde do `PreviaDaFaseService::maxTitulo()`.

Fecha o item 1 do `deferred-items.md` da Fase 175.

# O bug, reproduzido antes de corrigir

O `CategorySchemaRepository::obter()` só lança `RegraViolada` quando **conseguiu falar**
com o ML e as quatro fontes da categoria recusaram. Antes disso ele passa pelo app token:

```
CategorySchemaRepository::obter()
  └─ ClienteMlPublicador::publico()        :99
       └─ MlColetaService::getAppToken()   :58  throw \RuntimeException
```

`\RuntimeException` não é `RegraViolada` ⇒ escapava. E como
`EditorRascunhoService:571` chama `problemas()` para **toda** publicação que não esteja
`RUNNING`, abrir o editor de um anúncio **que está no ar e correto** voltava 500 — por uma
lista que é apenas informativa.

O teste `test_problemas_sobrevive_a_falha_de_token_e_segue_explicando_o_erro_do_ml`
reproduz o cenário de produção (categoria fora do cache + app token recusado) e falhou com
a pilha literal acima antes do fix.

# Duas coisas medidas que contrariam o registro original

1. **`$schema = null` não é estado novo.** É exatamente o que o `catch (RegraViolada)` já
   produzia, e `MapeadorErrosMl::problema()` o recebe sem reclamar. Sem schema a causa do
   ML continua chegando à tela, só não traduzida para o nome do atributo em português — o
   teste prova isso afirmando `alvo['atributo'] === 'MODEL'`.

2. **A ressalva do `deferred-items.md` estava errada num ponto.** Ele dizia que "a troca do
   `catch` muda o comportamento de todo o caminho de publicação". Não muda: `problemas()`
   tem **um único chamador** (conferido por grep em `app/`), e `iniciar()` /
   `executarFatia()` têm os `catch` próprios. O docblock novo registra que o catch largo
   vale SÓ para esta leitura, justamente para ninguém o replicar nos dois outros métodos,
   que precisam seguir falhando alto (TC-85/TC-88).

# Verificação

- `artisan test --filter=PublicacaoTest` — **33 verdes**, 241 asserções
- `artisan test tests/Feature/Publicador` — **949 verdes**, 6445 asserções, zero regressão

# Limitações

- **Não deployado.** O fix está em `main` local, sem push.
- O teste cobre o ramo HTTP 400 do `getAppToken()` (`MlColetaService:58`). O outro ramo
  (`:66`, "resposta de token sem access_token") é a mesma classe de exceção pelo mesmo
  `catch`, e não ganhou teste próprio.
- Backend puro: nada em `resources/js/`. Sem interseção com o redesign de UI que roda em
  paralelo (`261009-t01`).
