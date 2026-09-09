# Fase 138 — Conta de sistema "Sistema HubSpot" (D-17)

Ator da transição de nascimento na etapa 1 quando o webhook do HubSpot roda
sem sessão autenticada. Criada por `php artisan hubspot:criar-usuario-sistema --apply`.

- **id_local:** 49
- **id_vps:** (preencher no plano 138-09, depois de rodar o mesmo comando na VPS)
- **email:** sistema.hubspot@ecfconsultoria.com.br
- **criado_em:** 2026-09-03
- **role:** consultor (menor valor do enum — nunca admin)
- **active:** false (defesa em profundidade)
- **não-logável de fato por:** senha aleatória de 64 caracteres, hasheada e descartada
  na criação — NÃO pelo `active=false` (o login deste projeto não checa `users.active`).
- **sem `user_setores` e sem `company_users`** — nenhuma permissão efetiva.

⚠️ Lembrete registrado por escrito (D-17): não deixe esta conta virar login
esquecido. Precedente: usuário de review da Shopee (`users.id=30`), ativo em
produção desde 2026-07-16 porque ninguém anotou que precisava sair.

Depois de criar em cada ambiente, colocar `HUBSPOT_WEBHOOK_USER_ID={id}` no
`.env` daquele ambiente (local e VPS, separadamente).

## Confirmação por reconsulta ao banco (plano 138-09, Task 1)

Nunca pelo que o comando imprimiu na criação — reconsultado agora, direto no MariaDB local
(`DB_DATABASE=ecf_admin`), por `SELECT` explícito:

```sql
SELECT id, name, email, role, active FROM users WHERE email='sistema.hubspot@ecfconsultoria.com.br';
```

Resultado: `{"id":49,"name":"Sistema HubSpot","email":"sistema.hubspot@ecfconsultoria.com.br","role":"consultor","active":0}`

- `user_setores` para `user_id=49`: **0 linhas**
- `company_users` para `user_id=49`: **0 linhas**
- `.env` local: `HUBSPOT_WEBHOOK_USER_ID=49` — confirmado, bate com o id reconsultado

## Suíte da fase × baseline pré-migration (`138-BASELINE-TESTES.md`) — medido 2026-09-09

| Suíte | Baseline (`138-01`) | Medido agora (`138-09`) | Veredito |
|---|---|---|---|
| `tests/Unit/Phase138 tests/Feature/Phase138` | não existia ainda | **72 tests, 291 assertions — OK** | verde, sem baseline anterior para comparar (suíte nova da própria fase) |
| `tests/Feature/Phase131 tests/Unit/Phase137 tests/Feature/Phase137 tests/Feature/Phase34HubspotWebhookTest.php` | — (comando de wave não media este conjunto exato) | **186 tests, 675 assertions — OK** | verde, nenhuma regressão |
| `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` + `tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php` | 24 tests, 44 assertions — OK | **24 tests, 44 assertions — OK** | idêntico à baseline |
| `tests/Feature/Phase38/PolosControllerTest.php` | 6 falhas (12 testes, 158 assertions) | **6 falhas (12 testes, 158 assertions)** — mesmos 6 nomes: `test_meta_por_estagio`, `test_status_sim`, `test_status_em_progresso`, `test_status_problema_precedencia`, `test_status_dist`, `test_filtro_por_mes` | idêntico à baseline — não é regressão da 138 |
| `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` | 2 erros + 2 falhas (4 testes, 32 assertions) | **2 erros + 2 falhas (4 testes, 32 assertions)** — mesmas causas (`ArgumentCountError` em `SyncPolosFaturamentoJob::handle()` e faturamento zerado) | idêntico à baseline — não é regressão da 138 |

Nota sobre exit code: `138-BASELINE-TESTES.md` registra exit code 0 para os comandos de Polos com
falha; nesta execução (mesmo PHPUnit 11.5.55) os mesmos comandos saíram com exit code 1 (Polos
controller) e 2 (Polos snapshot). O **conteúdo** da falha (contagem de testes/assertions, nomes
dos testes, causa raiz) é idêntico ao registrado — a diferença de exit code é do executor, não do
código sob teste, e não muda o veredito de "não é regressão".

`npm run build`: exit code 0. `public/build/manifest.json` contém `resources/js/Pages/Admin/Contratos.jsx`,
`resources/js/Pages/Comercial/Entrada.jsx` e o chunk compartilhado `AppLayout` (`_AppLayout-zlHHTMFF.js`,
importado por praticamente toda página). `public/hot` não existe no worktree.

## Task 2 — resolução do checkpoint (2026-09-09)

### Passo 3 — tentativa de login manual no navegador

**Dispensada pelo usuário em 2026-09-09.** O usuário respondeu literalmente "Aprovar pelo teste
automatizado" quando confrontado com o passo 3 do `how-to-verify` (tentar logar na tela local com
`password`, senha vazia e o próprio e-mail). Ninguém testou pela tela.

A não-logabilidade fica provada, em vez disso, por `tests/Feature/Phase138/ComercAtorSistemaTest.php`
— reexecutado nesta resolução (`/c/xampp/php/php.exe vendor/bin/phpunit
tests/Feature/Phase138/ComercAtorSistemaTest.php --colors=never` → **OK, 5 tests, 26 assertions**):

- `Auth::attempt(['email' => 'sistema.hubspot@ecfconsultoria.com.br', 'password' => $senha])`
  devolve `false` para as 4 senhas óbvias testadas: `password`, string vazia, `Sistema HubSpot`
  (o `name` da conta) e o próprio e-mail.
- `Hash::check('password', $user->password)` devolve `false`.

Isso é uma prova mais forte que a tentativa manual — cobre 4 senhas em vez de 3, e é repetível a
cada rodada de CI — mas é uma prova diferente da pedida originalmente no `how-to-verify`. Registrado
aqui sem suavizar: **nenhuma tentativa de login foi feita na tela do navegador**; o usuário aceitou
a prova automatizada no lugar dela.

### Passo 7 — escopo OAuth `crm.objects.owners.read`

**Resposta do usuário: "Deixar anotada para o deploy".** `escopo_owners_read:` permanece
`NÃO CONFIRMADO` em `138-HUBSPOT-MEDICOES.md` — não houve tentativa nova de confirmação nesta
sessão. Registrado como **pendência declarada**, no mesmo pacote da medição da property de owner
que já estava adiada para a VPS (ver `138-HUBSPOT-MEDICOES.md`, seção "Pendências obrigatórias
para a VPS").

**Sintoma observável para quem investigar depois do deploy:** se a coluna "Responsável comercial"
das listagens Entrada e Contrato vier **sempre vazia** em produção, a causa mais provável é essa
permissão faltando no Private App do HubSpot — `GET /crm/v3/owners/{id}` devolve 403, o
`HubspotApiClient` devolve `null` por desenho (resiliente, não lança exceção), e **nenhum erro
aparece na tela nem no fluxo**. A correção é marcar `crm.objects.owners.read` nos escopos do
Private App (Configurações → Integrações → Private Apps → app do ECF Admin → Scopes); se o HubSpot
rotacionar o token ao salvar, `HUBSPOT_ACCESS_TOKEN` precisa ser atualizado no `.env` da VPS antes
de considerar o escopo resolvido — rotação sem atualizar o `.env` reintroduz o mesmo 403.

## Roteiro completo para a VPS (só na sessão de deploy, com autorização explícita do usuário)

Nenhum destes itens foi executado. É a lista completa do que precisa acontecer quando — e só
quando — o deploy desta fase for autorizado.

1. **Criar a conta de sistema na VPS e apontar o `.env` para ela.**
   - Comando: `php artisan hubspot:criar-usuario-sistema --apply` rodado **na VPS**.
   - Anotar o `id` **daquele ambiente** — ele será diferente do `id_local` (49) registrado acima.
     Preencher `id_vps:` neste arquivo com o valor real.
   - Setar `HUBSPOT_WEBHOOK_USER_ID=<id_vps>` no `.env` da VPS.
   - **Consequência de pular este passo:** o webhook do HubSpot em produção continua criando a
     empresa normalmente, mas ela nunca ganha etapa — degrada em silêncio, só com log, sem erro
     visível em tela nenhuma.

2. **Medir o nome interno real da property de owner na VPS.**
   - Comando: `php artisan hubspot:inspect-properties --objects=deals` rodado **na VPS**, com o
     `HUBSPOT_ACCESS_TOKEN` de produção já configurado (impossível medir localmente — este
     worktree não tem a chave).
   - Localizar na saída a linha cujo `name` (nome interno, não o `label`) é a property de owner
     do deal.
   - Se `name = hubspot_owner_id`: nada a fazer, o default do `config/services.php` já está certo.
   - Se `name` vier diferente: setar `HUBSPOT_PROP_DEAL_OWNER_ID=<nome_real>` no `.env` da VPS.
     **Sem mudança de código, sem redeploy** — só `php artisan config:clear` (ou reiniciar o
     queue worker/supervisor se o config estiver cacheado).

3. **Confirmar o escopo `crm.objects.owners.read` no Private App de produção.**
   - Onde: painel HubSpot → Settings → Integrations → Private Apps → app do ECF Admin → aba
     Scopes.
   - Se não estiver marcado: marcar, salvar, e verificar se o HubSpot rotacionou o token. Se
     rotacionou, atualizar `HUBSPOT_ACCESS_TOKEN` no `.env` da VPS antes de considerar resolvido.
   - **Sintoma se ficar pendente:** coluna "Responsável comercial" das listagens Contrato e
     Entrada sempre vazia em produção, sem erro em lugar nenhum (ver detalhamento na seção
     "Task 2 — resolução do checkpoint" acima).

4. **Auditar esta conta em toda revisão de usuários daqui pra frente.**
   - `sistema.hubspot@ecfconsultoria.com.br` é uma conta de sistema, não uma pessoa. Ela precisa
     entrar na lista de toda auditoria/revisão de usuários do ECF Admin, para nunca virar um
     login esquecido.
   - Precedente direto: o usuário de review da Shopee (`users.id=30`) segue ativo em produção
     desde 2026-07-16 porque ninguém escreveu que ele precisava sair depois de cumprir sua função.
     Esta conta não pode repetir isso — a diferença é que aqui já está escrito.
