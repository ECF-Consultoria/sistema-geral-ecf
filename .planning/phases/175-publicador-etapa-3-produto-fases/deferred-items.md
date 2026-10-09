# Itens adiados — Fase 175 (Publicador, Etapa 3)

Achados **fora do escopo** do plano em que foram descobertos. Nada aqui foi
corrigido: estão registrados para não se perderem.

---

## 1. `PublicacaoService::problemas()` pode derrubar o editor com 500 (descoberto no 175-09)

**Onde:** `app/Services/Publicador/PublicacaoService.php`, ~L182-187.

**O que é:** o `try/catch` em volta de `$this->schemas->obter($r->categoria_id)`
captura **só `RegraViolada`**. Mas a camada de token (`ClienteMlPublicador` →
`MlColetaService::66`) lança `RuntimeException('[MLB Coleta] Resposta de token sem
access_token')` quando o ML responde sem token — exatamente a armadilha que o
`PreviaDaFaseService::maxTitulo()` já documentou e tratou com `catch (\Throwable)`
(desvio 1 do `175-05-SUMMARY.md`).

**Consequência medida:** `EditorRascunhoService::estado()` chama
`$this->publicacoes->problemas($p)` para toda publicação que não esteja `RUNNING`.
Logo, abrir o editor de um rascunho que **já tem publicação** com o ML fora do ar
(ou com a categoria fora do cache e o token vencido) derruba a tela inteira em
500 — por uma lista de problemas que é informativa.

**Como apareceu:** o `kitPublicado()` do `EstoqueDoKitTest` precisou criar a
publicação com status `RUNNING` para o `estado()` não tentar ler o schema de um
cenário sem categoria. O comentário no teste registra isso.

**Por que não foi corrigido aqui:** é pré-existente, está em arquivo que o 175-09
não toca (`PublicacaoService`), e a troca do `catch` muda o comportamento de todo
o caminho de publicação — não é escopo de uma plan de estoque de kit.

**Correção provável:** `catch (\Throwable $e)` com `Log::warning` e `$schema = null`,
no mesmo molde do `PreviaDaFaseService::maxTitulo()`.
