---
tipo: quick
slug: ng9-catch-do-schema-na-lista-de-problemas
data: 2026-10-09
files_modified:
  - app/Services/Publicador/PublicacaoService.php
  - tests/Feature/Publicador/PublicacaoTest.php
autonomous: true
---

<objective>
Abrir o editor de um rascunho JÁ PUBLICADO derruba a tela em 500 quando o token do
Mercado Livre falha — por causa de uma lista de problemas que é apenas informativa.

`PublicacaoService::problemas()` (L182-187) embrulha `CategorySchemaRepository::obter()`
num `catch (RegraViolada)`. Mas `obter()` só lança `RegraViolada` quando **conseguiu
falar** com o ML e as quatro fontes recusaram (L37-44). Antes disso ele chama
`ClienteMlPublicador::publico()` → `MlColetaService::getAppToken()`, que lança
`\RuntimeException` em dois caminhos:

- `MlColetaService.php:58` — `'[MLB Coleta] Falha ao obter app token: HTTP {status}'`
- `MlColetaService.php:66` — `'[MLB Coleta] Resposta de token sem access_token'`

Nenhum dos dois é `RegraViolada`, então escapam.

**Consequência medida:** o único chamador é `EditorRascunhoService.php:571`, que roda
`problemas($p)` para **toda** publicação que não esteja `RUNNING`. Logo, com o ML fora do
ar (ou com a categoria fora do cache e o app token vencido), `estado()` estoura e a tela
inteira do editor volta 500 — mesmo que o anúncio esteja no ar e correto.

Registrado em `.planning/phases/175-*/deferred-items.md` item 1, achado na execução do
175-09 e deixado de fora de propósito (arquivo que aquele plano não tocava). O molde da
correção já existe e está em produção: `PreviaDaFaseService::maxTitulo()` (L304-319) pega
`\Throwable`, loga `Log::warning` e segue com o padrão.

⚠️ `$schema = null` **já é um estado suportado** neste método — é exatamente o que o
`catch (RegraViolada)` de hoje produz, e `MapeadorErrosMl::problema()` recebe o null sem
reclamar. A correção não inventa um caminho novo: ela faz o caminho que já existe cobrir
também a falha de token.

Fora de escopo: qualquer outro `catch` do caminho de publicação. `iniciar()` e
`executarFatia()` **precisam** falhar alto quando a conta não responde — é o que grava
`FAILED` com motivo e o que os testes TC-85/TC-88 provam. Aqui o assunto é só a leitura
informativa de `problemas()`.
</objective>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `problemas()` sobrevive à falha de token, com aviso no log</name>
  <files>app/Services/Publicador/PublicacaoService.php, tests/Feature/Publicador/PublicacaoTest.php</files>
  <action>
  Trocar `catch (RegraViolada)` por `catch (\Throwable $e)` com `Log::warning` e
  `$schema = null`, no mesmo molde do `PreviaDaFaseService::maxTitulo()`. `Log` já está
  importado (L27). Comentário em pt-BR dizendo POR QUE é `\Throwable` e não `RegraViolada`
  — senão alguém "aperta" o catch de novo na próxima leitura.

  Conferir se o `use ...RegraViolada` continua necessário no arquivo antes de mexer nele
  (há outros usos no caminho de publicação): se continuar, fica.

  Teste (RED antes), em `PublicacaoTest`: publicar com o item recusado em 400 (payload do
  `tc80`, que já existe), e então reproduzir o cenário de produção — apagar a linha de
  `ml_categoria_schemas` (categoria fora do cache), `Cache::forget('ml_app_token_coleta')`
  e ligar `$this->oauthFalha` (o flag do cenário já faz o `*/oauth/token` responder 400).
  `problemas($p)` tem de DEVOLVER a lista, não lançar.

  O teste precisa provar as duas metades, senão não prova nada:
  1. que hoje isso lança (RED), e
  2. que a lista devolvida ainda descreve o erro do ML com `$schema = null`.
  </action>
  <verify>
  - `artisan test --filter=PublicacaoTest` verde
  - `artisan test --filter=Publicador` sem regressão (era 214 verdes no recorte das fases)
  </verify>
</task>

</tasks>

<notes>
Backend puro. NÃO toca em `resources/js/` — há outra sessão fazendo redesign de UI do
Publicador (`AnunciosEmpresas.jsx` e os commits `261009-t01`). Zero interseção.

Commitar só por caminho (`git commit -- <files>`), nunca `git add -A`: a árvore é
compartilhada com a outra sessão (`project_sessoes_paralelas_working_tree`).
</notes>
