---
quick_id: 260917-jol
status: complete
date: 2026-09-17
commit: c584fd7c
---

# Resumo — Shopee OAuth: robustez e visibilidade da conexão

## Diagnóstico (produção, 2026-09-17)

- **Nenhuma conexão foi perdida.** As 17 lojas com token (ERP + Ads) estão `active`, com `last_refreshed_at` de hoje às 11:15 (ERP) e 11:30 (Ads). A cadeia do refresh vem funcionando desde a conexão (23–28/07).
- **"Link expirado" é convite nunca concluído.** São 10 empresas com `shopee_link_generated_at` entre 22/07 e 03/08 e **zero** tokens: AB4 Allam, CARAIBAALUMINIO, Decoral, FACASERECHIM, Ita Prime, WEHOUSE, Vitrine do Couro, Gabs Folheados e RELOJOARIA WENUS (+ ByMobille - Teste, que tem o ERP ligado e o link ficou pendurado). Nos 15 dias de log do nginx não houve nenhum acesso a `/shopee/conectar` nem a `/oauth/shopee/callback`. O cliente precisa receber um novo link.
- **Bug de tela:** ao "Regerar link", o badge seguia "Link expirado", porque o front não recebia a nova expiração. Hoje o botão da Visammer foi clicado 7 vezes seguidas.
- **Ponto cego:** a falha de renovação só gerava `Log::warning`, e produção roda com `LOG_LEVEL=error`. O painel continuaria mostrando "Conectada" com a cadeia morta.

## O que mudou

- Migration `2026_09_17_120000_add_last_error_to_shopee_tokens_table`: colunas `last_error` e `last_error_at`.
- `ShopeeService::refreshToken($token, $force = false)` grava a falha (de conexão, transitória ou revogação) via `registrarFalha()` com `Log::error`. O erro é limpo na renovação bem-sucedida e na reconexão (`saveToken`).
- `ShopeeToken::renovacaoComProblema()`: verdadeiro quando o erro é mais novo que a última renovação ou quando a renovação passou de 36h.
- Comando `shopee:refresh-tokens` (agendado às 03:00): renova com `force` os tokens ativos sem renovação há mais de 12h (ERP e Ads) e tenta reativar os revogados há até 7 dias.
- Painel `/shopee-oauth`:
  - `initiate()` devolve `generated_at` e `expires_at`, e o badge atualiza na hora.
  - Para as conectadas, mostra "renovada há X" e o status do Ads.
  - Mostra o badge "Falha na renovação" com a mensagem do erro.
  - Os textos passaram a "Convite expirado" / "Aguardando cliente", com orientação para reenviar o link.
  - O token revogado tem uma explicação própria.

## Verificação

- `ShopeeTokenRenovacaoTest` (8 novos) + os testes Shopee existentes: 35/0.
- `npm run build` ok.
- **Sem deploy.** O deploy precisa rodar `migrate` (coluna nova). Sem a migration, o refresh quebra ao gravar `last_error`.
