---
quick_id: 261014-ea0
slug: bling-no-dominio-do-cliente
date: 2026-10-14
origem: lacuna da Fase 184 encontrada pelo usuário ao cadastrar o app no Bling
---

# O Bling no domínio do cliente

## O que motivou

Ao cadastrar o app no Bling (14/10/2026), o usuário registrou como "Link de redirecionamento"
`https://cliente.ecfconsultoria.com.br/portal/estrutura/produtos`. Duas coisas apareceram:

1. **Aquela URL é uma TELA, não o retorno.** O Bling mandaria `?code=…&state=…` para a página de
   produtos, que ignora os parâmetros — a conexão **nunca se completaria, e sem erro visível**. O
   endereço certo é a rota `oauth/bling/callback`.
2. **No domínio `cliente.` nada do Bling existe.** `RestringeDominioDoPortal` é uma **allowlist**, e
   nem `bling/conectar/*` nem `oauth/bling/callback` estão nela → **404**.

⚠️ **O item 2 é uma lacuna da Fase 184, não erro do usuário.** O seed §7 dizia, literalmente, que o
callback público ficaria "**na allowlist do domínio do cliente**". O plano 07 criou as rotas em
`routes/web.php` e **não** mexeu na allowlist — o arquivo nem estava no `files_modified` dele. Como o
middleware **não roda no localhost** (`portal.dominio_cliente` é vazio lá), a suíte inteira passa e o
furo só aparece em produção. É exatamente o sintoma já documentado no próprio middleware: *"respondiam
404 no domínio do cliente enquanto funcionavam perfeitamente no localhost"*.

## Decisão do usuário (14/10/2026)

O link do Bling é aberto no **domínio do cliente**, `cliente.ecfconsultoria.com.br`. Ele cadastra no
app o redirect `https://cliente.ecfconsultoria.com.br/oauth/bling/callback`, e o teste será **direto em
produção, depois do deploy** (app único, sem app de desenvolvimento).

## A armadilha técnica que define a solução

`URL::temporarySignedRoute(...)` **absoluto assina o HOST junto**. Gerar no domínio do painel e trocar
o host depois (como `UrlDoPortal::noDominioDoCliente` faz) **invalidaria a assinatura** e o cliente
levaria 403 no link que acabou de receber.

➡️ Gerar a rota assinada **relativa** (`absolute: false` — a assinatura cobre só caminho e query),
montar o endereço no domínio do cliente, e validar com **`signed:relative`**.

## Tarefas

1. **Allowlist** — `app/Http/Middleware/RestringeDominioDoPortal.php`: acrescentar `bling/conectar/*`
   e `oauth/bling/callback` a `PERMITIDO`, com comentário dizendo por que cada uma existe (a
   convenção do arquivo é "uma linha = uma decisão consciente de expor algo").
2. **Link no domínio do cliente** — `app/Services/Bling/BlingOAuthService::gerarLinkDeAutorizacao()`:
   gerar relativo e passar por `UrlDoPortal::noDominioDoCliente(url($relativa))`.
   ⚠️ Sem `portal.dominio_cliente` (local), o helper devolve a URL intacta — o ambiente local segue
   funcionando sem domínio próprio.
3. **Validação relativa** — `routes/web.php`: `signed` → `signed:relative` na `bling.connect.landing`.
   **Só acréscimo/troca nessa linha**; nada mais do arquivo.
4. **Testes** — em `tests/Feature/Bling/`: o link sai no domínio do cliente quando ele está
   configurado; a landing abre com a assinatura relativa; e, no domínio do cliente, `bling/conectar/*`
   e `oauth/bling/callback` **não** dão 404 enquanto uma rota interna continua dando (par
   positivo/negativo, seguindo `tests/Feature/PortalCliente/DominioDoPortalTest.php`).
5. **`.env.example`** — registrar a URL que ficou cadastrada no app.

## Gates

- `artisan test --filter=Bling` verde, sem regressão nos **210**.
- `tests/Feature/PortalCliente` comparado com o baseline **757**.
- ⚠️ O teste novo da allowlist tem de **falhar** se as duas linhas forem removidas — é o controle
  positivo; sem ele o gate não prova nada.
- A `landing()` compara `link_url` com `$request->fullUrl()`: com o host trocado, os dois têm de
  continuar batendo.

## Fora de escopo

Deploy (precisa de autorização explícita), a Fase 185, e qualquer outra entrada na allowlist.
