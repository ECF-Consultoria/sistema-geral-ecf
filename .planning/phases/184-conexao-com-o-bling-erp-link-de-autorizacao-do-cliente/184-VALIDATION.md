---
phase: 184
slug: conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
status: planejado
nyquist_compliant: true
wave_0_complete: true
created: 2026-10-13
---

# Fase 184 — Estratégia de validação

> Contrato de validação da fase, para amostragem de feedback durante a execução.
> A arquitetura em 5 camadas está em `184-RESEARCH.md` → seção **Validation Architecture**.
> O mapa por tarefa abaixo foi preenchido no planejamento (13 planos, 38 tarefas, 8 ondas).

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework (PHP)** | PHPUnit 11.x — config em `phpunit.xml`, suítes `Unit` e `Feature` |
| **Framework (JS)** | `node --test` (nativo) — `tests/js/**/*.test.js` |
| **Comando rápido** | `C:\xampp\php\php.exe artisan test --filter=Bling` |
| **Suíte completa (PHP)** | `C:\xampp\php\php.exe artisan test` |
| **Suíte completa (JS)** | `npm run test:js` |
| **Build do front** | `npm run build` — **obrigatório** após toda mudança de front (convenção do projeto) |
| **Banco dos testes** | SQLite |
| ⚠️ **Banco de verdade** | **MariaDB local** — a migration tem de ser provada nele, não só no SQLite (ver memórias: enum/CHECK, FK 1830, nome de índice 1059) |

⚠️ **PHP não está no PATH desta máquina**: usar `C:\xampp\php\php.exe`.
⚠️ Antes de qualquer comando que dependa do banco local, conferir `tasklist | grep mysqld` (o MariaDB
local já corrompeu uma vez).

---

## Taxa de amostragem

- **Depois de cada commit de tarefa:** `artisan test --filter=Bling`
- **Depois de cada onda:** `artisan test` + `npm run test:js`
- **Antes de `/gsd:verify-work`:** as duas suítes verdes, com **baseline medido ANTES de tocar em nada**
  (o projeto tem falhas pré-existentes conhecidas — ex.: `tests/js/estrutura-grade-glide.test.js:122`;
  contá-las como regressão desta fase é falso positivo)
- **Latência máxima de feedback:** ~60 s no filtro, alguns minutos na suíte completa

---

## Mapa de verificação por tarefa

*Uma linha por tarefa dos 13 planos (38 tarefas). As 35 primeiras têm gate automático; as 3 últimas
são checkpoint humano declarado, porque **não existe sandbox no Bling**.*

| Task ID | Plano | Onda | Requisito | Ameaça | Comportamento seguro | Tipo | Comando | Status |
|---|---|---|---|---|---|---|---|---|
| 184-01-T1 | 01 | 1 | BLING-01 | T-184-03/04 (índice ≥ 64 cria a tabela SEM o índice; FK sem `nullable` quebra no MariaDB) | Migration idempotente provada no MariaDB, índices nomeados | CLI | `artisan migrate --path=…` + rollback + migrate, e `SHOW CREATE TABLE bling_conexoes` | ⬜ |
| 184-01-T2 | 01 | 1 | BLING-02 | T-184-03/04 | Unique do par empresa+produto provado por 1062 no MariaDB | CLI | `artisan migrate --path=…` (vínculos) + `SHOW CREATE TABLE bling_vinculos` | ⬜ |
| 184-01-T3 | 01 | 1 | BLING-03, BLING-04 | T-184-01 (token legível em repouso), T-184-02 (credencial versionada) | Linha crua sem token em claro; `.env` vazio não estoura 500 | Automatizado | `artisan test --filter=BlingEsquema` | ⬜ |
| 184-02-T1 | 02 | 1 | BLING-05 | T-184-09 (payload de cliente real versionado) | 8 fixtures da documentação; grafia da pasta conferida no índice do git | Automatizado | script PHP de validação de JSON (ver o `<verify>` do plano) | ⬜ |
| 184-02-T2 | 02 | 1 | BLING-05, BLING-06 | T-184-06 (`preco` do Bling vazando para a Precificação), T-184-07 (HTML na descrição) | Tradutor puro; `preco` ausente do resultado; código vazio recusado | Automatizado | `artisan test --filter=TradutorBlingParaPortal` | ⬜ |
| 184-02-T3 | 02 | 1 | BLING-05 | T-184-10 (`formato E` descartado em silêncio) | Uma linha por variação; grupo pela Ref da 1ª variação; kit recusado com motivo | Automatizado | `artisan test --filter=TradutorBlingParaPortal` | ⬜ |
| 184-03-T1 | 03 | 1 | BLING-21 | T-184-11 (funcionário carimbado em ação automática) | `origem = 'bling'` com `causer_id` nulo | Automatizado | `artisan test --filter=EscritaSemPreparoIa` | ⬜ |
| 184-03-T2 | 03 | 1 | BLING-22 | T-184-12 e T-184-13 (os **dois** caminhos até `PreparoIaAgenda`) | `agendarPreparo: false` fecha o direto **e** o da oferta; par negativo + positivo | Automatizado | `artisan test --filter=EscritaSemPreparoIa` | ⬜ |
| 184-04-T1 | 04 | 2 | BLING-09, BLING-10 | T-184-16 (`state`), T-184-17 (reuso do `code` revoga o cliente), T-184-22 (credencial no corpo) | State de uso único; sem `redirect_uri`/`scope`; credenciais só no header; zero `retry` | Automatizado | `artisan test --filter=BlingOAuthService` | ⬜ |
| 184-04-T2 | 04 | 2 | BLING-11, BLING-12 | T-184-18 (renovação concorrente derruba a autorização), T-184-20 (retry pós-429) | Lock por conexão; refresh novo sempre; 429 sem retry; 5xx sem revogar | Automatizado | `artisan test --filter=BlingOAuthService` | ⬜ |
| 184-04-T3 | 04 | 2 | BLING-13 | T-184-19 (20 chamadas a `/oauth/token` em 60 s bloqueiam o IP por 60 min, para todos) | Teto de 10 renovações por minuto; agendamento horário em vez de rajada | Automatizado | `artisan test --filter=BlingRenovarTokens` | ⬜ |
| 184-05-T1 | 05 | 2 | BLING-07 | T-184-24 (unidade adivinhada), T-184-26 (medida gravando ZERO) | Metro/milímetro convertidos; unidade desconhecida com aviso; fora de faixa omite a chave | Automatizado | `artisan test --filter=TradutorBlingParaPortal` | ⬜ |
| 184-05-T2 | 05 | 2 | BLING-07 | T-184-25 (medida de multivolume replicada), T-184-30 (campo de fase futura entrando) | `volumes > 1` omite a medida com aviso; nenhum campo fora de escopo é lido | Automatizado | `artisan test --filter=TradutorBlingParaPortal` | ⬜ |
| 184-05-T3 | 05 | 2 | BLING-08 | T-184-27 (saldo ausente virando 0 e tirando anúncio do ar), T-184-28 | `NULL` ≠ `0`; piso em zero; `floor()`; soma só dos depósitos escolhidos | Automatizado | `artisan test --filter=SaldoDoBling` | ⬜ |
| 184-06-T1 | 06 | 3 | BLING-14 | T-184-31 (600 req/10 s bloqueiam o IP para **todos**), T-184-32 (a cota é do cliente) | Balde por conta (1 req/s) + balde global por IP (5/s) + balde de erros | Automatizado | `artisan test --filter=LimitadorDoBling` | ⬜ |
| 184-06-T2 | 06 | 3 | BLING-15, BLING-16 | T-184-33 (laço de renovação no 401), T-184-34 (retry pós-429), T-184-36 (token em log), T-184-37 (escrita no Bling) | 401 renova **uma** vez; 429 sem retry; nenhum método de escrita; log sem token | Automatizado | `artisan test --filter=BlingClient` | ⬜ |
| 184-07-T1 | 07 | 4 | BLING-17 | T-184-39 (link vazado conecta um Bling qualquer), T-184-42 (rota pública sem throttle) | 7 dias de validade; 403 vencido e 403 adulterado; throttle de prefixo próprio | Automatizado | `artisan test --filter=BlingLinkEAutorizacao` | ⬜ |
| 184-07-T2 | 07 | 4 | BLING-19 | T-184-40 (reuso do `code`), T-184-41 (conta de outra empresa, ou outra unidade) | Só CNPJ igual grava `ativa`; os outros 4 estados ficam `divergente` **sem token** | Automatizado | `artisan test --filter=BlingLinkEAutorizacao` | ⬜ |
| 184-07-T3 | 07 | 4 | BLING-18, BLING-29 | T-184-43 (stack trace na tela do cliente), T-184-44 (sigilo) | 4 estados amigáveis, status 200, sem jargão, `assertSemOrigem` | Automatizado | `artisan test --filter=BlingLinkEAutorizacao` | ⬜ |
| 184-08-T1 | 08 | 4 | BLING-24 | T-184-48 (worker preso 8,5 min), T-184-49 (recursão no driver `sync`), T-184-50 (fila errada) | Fatias de 45 s por `release()`; fila `publicador-ia`; `retryUntil` finito | Automatizado | `artisan test --filter=LerProdutosDoBlingJob` | ⬜ |
| 184-08-T2 | 08 | 4 | BLING-23 | T-184-51 (gravar sem prévia), T-184-54 (tela diz "concluída" sem produto) | `previa()` não grava nada; código repetido recusado no lote inteiro | Automatizado | `artisan test --filter=ImportadorDoBling` | ⬜ |
| 184-08-T3 | 08 | 4 | BLING-23, BLING-32 | T-184-47 (cadeias de IA), T-184-52 (apagar dado do Portal), T-184-53 (rodada interrompida marcando órfão) | `agendarPreparo: false`; lotes de 200; órfão vira `removido_no_bling` sem apagar nada | Automatizado | `artisan test --filter=ImportadorDoBling` | ⬜ |
| 184-09-T1 | 09 | 5 | BLING-25 | T-184-57 (sobrescrever outras colunas), T-184-58 (empresa errada), T-184-59 (`NULL` apagando estoque) | Grava **só** a coluna `estoque`, filtrada por empresa, sem agendar nada | Automatizado | `artisan test --filter=EstoqueDoBling` | ⬜ |
| 184-09-T2 | 09 | 5 | BLING-25 | T-184-64 (pedir saldo de produto removido), T-184-59 | Blocos de 50 ids; grava bloco a bloco; para sem insistir no 429 | Automatizado | `artisan test --filter=EstoqueDoBling` | ⬜ |
| 184-09-T3 | 09 | 5 | BLING-26 | T-184-61 (duas varreduras dobram a cota do cliente), T-184-62 (mutex travado), T-184-63 (fila `default`) | `withoutOverlapping(25)` + `onOneServer` + `runInBackground`, a cada 30 min | Automatizado | `artisan test --filter=Bling` e `artisan schedule:list \| grep bling:estoque` | ⬜ |
| 184-10-T1 | 10 | 6 | BLING-25 | T-184-66 (SKU alterado quebra o casamento com o anúncio), T-184-67 (estoque digitado por cima), T-184-71 (consulta no laço) | `estoque` e `codigo` fora de `presentes` na variação vinculada, com aviso; 1 consulta por lote | Automatizado | `artisan test --filter=TravaDaVariacaoVinculada` | ⬜ |
| 184-10-T2 | 10 | 6 | BLING-25 | T-184-68 (regra implementada duas vezes e divergindo), T-184-72 (chave ausente derruba tela) | `BlingVinculo::travas()` como fonte única; bloco `bling` sempre presente | Automatizado | `artisan test --filter=TravaDaVariacaoVinculada` | ⬜ |
| 184-10-T3 | 10 | 6 | BLING-25 | T-184-72, T-184-73 (jargão e sigilo) | Campos travados reusando o estilo de só-leitura existente; `bling` ausente não derruba | Automatizado | `npm run test:js` + `npm run build` | ⬜ |
| 184-11-T1 | 11 | 6 | BLING-20, BLING-31 | T-184-76 (inventar Company), T-184-80 (duas implementações do estado) | 7 estados numa fonte única; prazo do link calculado no servidor; `erp` intacto | Automatizado | `artisan test --filter=BlingNasConfiguracoes` | ⬜ |
| 184-11-T2 | 11 | 6 | BLING-27 | T-184-74 (acesso não-admin), T-184-75 (`conta` fora do padrão), T-184-77/78 (cota do cliente), T-184-81 (desconectar apagando vínculo) | 7 rotas admin-only, `where('conta', …)`, throttle de prefixo próprio, lock na leitura | Automatizado | `artisan test --filter=BlingNasConfiguracoes` e `artisan route:list --name=publicador.bling` | ⬜ |
| 184-12-T1 | 12 | 7 | BLING-28 | T-184-84 (polling sem teto), T-184-86 (desconectar por acidente) | `MAX_TENTATIVAS` + `clearInterval`; confirmação antes de desconectar | Automatizado | `npm run test:js` | ⬜ |
| 184-12-T2 | 12 | 7 | BLING-28, BLING-29 | T-184-83 (dado ausente derruba tela), T-184-87 (gravar sem prévia), T-184-88 (botão sem ação), T-184-89 (jargão/sigilo) | 7 estados; zero `<button>` onde não há ação; fallback neutro; prévia antes de gravar | Automatizado | `npm run test:js` | ⬜ |
| 184-12-T3 | 12 | 7 | BLING-28 | T-184-90 (regressão na linha "ERP · declarado no onboarding") | Seção nova **e** linha antiga juntas, na mesma asserção | Automatizado | `npm run test:js` + `npm run build` | ⬜ |
| 184-13-T1 | 13 | 8 | BLING-30 | T-184-92 (credencial em commit), T-184-93 (escopo omitido revoga todos depois) | App público com os 9 escopos fechados; `.env` fora do git | Checkpoint humano | `artisan tinker --execute="echo App\Models\BlingConexao::estaConfigurado() ? 'ok' : 'falta';"` + `git log -p <ini>..HEAD \| grep -cE "BLING_CLIENT_(ID\|SECRET)=.+"` = 0 | ⬜ |
| 184-13-T2 | 13 | 8 | BLING-30 | T-184-94 (consentimento expondo "Anúncios"/"Lojas" — **aceito**, D-03), T-184-95 (conta ligada ao ML fora da #459) | Autorização real conferida de ponta a ponta, inclusive o caso divergente | Checkpoint humano | conferência manual na conta Bling de teste (passos 1–11 do plano) | ⬜ |
| 184-13-T3 | 13 | 8 | BLING-30, BLING-31 | T-184-96 (suposição divergente virando "observação"), T-184-98 (11º cliente sem homologação), T-184-99 (fila sem consumidor na VPS) | 8 suposições medidas, datadas e assinadas; divergência vira todo com arquivo e constante | Checkpoint humano | `ls .planning/phases/184-*/184-MEDICOES.md` e `grep -c "^\| [1-8] " <arquivo>` = 8 | ⬜ |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Requisitos da Onda 0

- [x] Arquivo de teste novo para o tradutor puro (unidade, sem rede) →
      `tests/Unit/Bling/TradutorBlingParaPortalTest.php`, plano **184-02** (onda 1)
- [x] Arquivo de teste novo para o OAuth com `Http::fake` →
      `tests/Feature/Bling/BlingOAuthServiceTest.php`, plano **184-04** (onda 2)
- [x] Arquivo de teste novo de Feature para o link assinado e a landing pública →
      `tests/Feature/Bling/BlingLinkEAutorizacaoTest.php`, plano **184-07** (onda 4)
- [x] Fixtures com payloads do Bling (`formato S`, `formato V`, `dados-basicos`, `/estoques/saldos`)
      copiados da documentação — **nunca** capturados da conta real do cliente →
      `tests/fixtures-bling/` (8 fixtures) + `tests/Support/BlingFixtures.php`,
      plano **184-02**, onda 1, **primeira tarefa de tudo**

⚠️ **A pasta é `tests/fixtures-bling/`** — nova e **sem homônima** em outra caixa. Não usar
`tests/Fixtures/` nem `tests/fixtures/`: no Windows (`core.ignorecase=true`) as duas são a MESMA pasta
física, o git grava a primeira grafia que o índice conhece, e no Linux da VPS o teste não acha o
arquivo (`.planning/learnings/publicador-ml.md` §2). Conferir com
`git ls-files --stage | grep -i fixtures-bling`.

⚠️ **Não há onda de andaimes vermelhos.** Os arquivos de teste nascem na MESMA tarefa do código que
provam — é a convenção do projeto, registrada no RESEARCH §8 (*"os testes com `Http::fake` vão no mesmo
commit"*). Cada tarefa que produz código tem `tdd="true"` com um bloco `<behavior>`, e o
`<verify><automated>` dela é um comando que existe ao fim da tarefa. Deixar testes vermelhos
atravessando ondas numa suíte compartilhada com outra sessão seria pior que o problema que resolveria.

*Nada a instalar: PHPUnit, Mockery, `Http::fake`, `RateLimiter`, `esbuild` e `node --test` já existem.
**Nenhum plano desta fase instala pacote** — logo o Package Legitimacy Gate não se aplica.*

---

## Asserções NEGATIVAS obrigatórias

Classe de prova que esta fase não pode dispensar — cada uma existe porque o erro correspondente é
silencioso. A coluna da direita diz **onde** cada uma é cobrada.

| Afirmação | Por que é negativa | Onde |
|---|---|---|
| O `preco` do Bling **não** aparece no resultado do tradutor | O preço é da Precificação; se vazar, o bônus e a promoção automática leem preço errado **sem erro** | 184-02-T2 |
| `client_id`/`client_secret` **não** aparecem no corpo da requisição de token | O Bling só aceita no header; no corpo, ou falha ou vaza em log | 184-04-T1 |
| A linha crua de `bling_conexoes` **não** contém o token em claro | O cast `encrypted` quebra em silêncio se alguém trocar o tipo da coluna | 184-01-T3 |
| Nenhum texto novo que o cliente lê cita a plataforma de venda (`assertSemOrigem`) | Regra de sigilo do projeto | 184-07-T3, 184-08-T2, 184-10-T3, 184-11-T1, 184-12-T2 |
| A gravação do estoque **não** chama `PreparoIaAgenda::aoSalvar` | Risco 5: centenas de cadeias de IA na fila `high`, a mesma que publica | 184-03-T2, 184-08-T3, 184-09-T1 |
| CNPJ divergente → **nenhuma** linha de token gravada | Conexão divergente que grava token é a falha do OAuth do ML nos Polos | 184-07-T2 |
| `redirect_uri`/`scope` **não** são enviados na URL de authorize | O Bling os ignora; mandá-los esconde erro de cadastro do app | 184-04-T1 |
| 429 **não** gera nova tentativa imediata | 600 req/10 s bloqueia o IP da VPS para **todos** os clientes | 184-04-T2, 184-06-T2, 184-08-T1, 184-09-T2 |
| Saldo ausente da resposta **não** vira `0` | `NULL` = "não informado" e `0` = "sem estoque"; um zero aqui tira anúncio do ar | 184-05-T3, 184-09-T2 |
| `previa()` **não** grava nada (`assertDatabaseCount(…, 0)`) | Prévia que grava é importação sem conferência | 184-08-T2 |
| Produto removido do Bling **não** apaga variação, produto nem oferta | O ERP do cliente não manda no catálogo da ECF | 184-08-T3 |
| Desconectar **não** apaga `bling_vinculos` nem nada do Portal | Sem o histórico, reconectar exigiria reimportar tudo | 184-11-T2 |
| `AtorDoPortal::sistema()` grava com `causer_id` **nulo** | Causer aqui é um funcionário carimbado numa ação que nenhum humano fez | 184-03-T1 |
| `BlingClient` **não** tem `post`/`put`/`patch`/`delete` | Escrever no Bling é a Fase 185; a ausência tem de ser verificável | 184-06-T2 |
| `bling` ausente nas props **não** derruba a tela | `cabecalho: null` numa recarga parcial do Inertia já derrubou esta mesma página | 184-10-T3, 184-12-T2 |
| `sem_company`/`nao_configurado` renderizam **zero** `<button>` | Botão onde não há ação leva a um 422 inútil e confunde a equipe | 184-12-T2 |
| Nenhuma `Company` é criada quando a conta não tem uma | "Nunca inventar Company" (CONTEXT §4.3) | 184-11-T1, 184-11-T2 |
| A chave `erp` **não** ganha `conectado` — o estado da conexão nasce numa chave irmã `bling` | A Fase 177-03 travou por asserção negativa que o ERP **não é integração** neste sistema, em 3 lugares (`CabecalhoDoPublicadorTest.php:340-345`, `CockpitDaContaTest.php:605-612`, `publicador-visao-geral-acoes.test.js:1523-1570`). Conferido em 13/10: com a chave irmã, os três **continuam verdes**. Reprovar ali = o bloco `bling` vazou para `erp` ou para a saúde → consertar o vazamento, **nunca** a asserção | 184-11-T1, 184-12-T3 |
| Nenhuma classe Tailwind em arquivo `.js` | `tailwind.config.js` varre `*.jsx` e **não** `*.js`: classe num `.js` não gera CSS e não existe para a tela, **com os testes de render passando** (`.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md`) | 184-12-T1 |

⚠️ **Regra de gate herdada** (`.planning/learnings/`, *gate vazio por construção*): todo gate desta
fase tem de responder *"o que precisaria estar errado para este gate falhar?"*.
`git diff --stat -- <dir>` depois de commitar compara a **árvore** e sai vazio sempre — usar
`<inicial>..HEAD`.
⚠️ **E o ANSI do `artisan test` come o veredicto** — uma campanha inteira de mutação já passou em
silêncio com aparência de sucesso neste projeto. Ler o **código de saída**, nunca a aparência da saída.
⚠️ **Asserções removidas nesta fase = ZERO.** Nenhum plano apaga ou enfraquece asserção existente; os
três gates do ERP da v26.0 têm de terminar a fase verdes **e intocados** (`git diff` dos três arquivos
sai vazio).
Por isso **cada `assertNothingPushed`/`assertNothingSent` desta fase tem um par positivo** na mesma
suíte (184-03-T2 e 184-08-T3 explicitamente): sem o par, o gate passaria com a fila desligada por
outro motivo.

---

## Verificações só manuais

| Comportamento | Por que é manual | Instruções |
|---|---|---|
| Criar o app no Bling com os escopos da D-03 | Interface do Bling, exige a permissão "Cadastro de aplicativos" | Central de Extensões > Área do Integrador > Criar aplicativo; registrar Client ID/Secret no `.env` — **184-13-T1** |
| Autorização real pela landing | **Não existe sandbox** no Bling | Gerar o link, abrir anônimo, autorizar na conta de teste, conferir CNPJ e status "conectado" — **184-13-T2** |
| As 8 medições da §5 do RESEARCH | Dependem da conta real | Registrar cada resultado em `184-MEDICOES.md`; divergência vira **correção** com todo citando arquivo e constante — **184-13-T3** |
| Teto de 10 contas / homologação | Revisão humana do Bling, sem prazo | A fase só **declara e conta** o teto na tela (184-11-T1/184-12-T2); a homologação é todo próprio — **184-13-T3** |
| `.env` de produção | Só o usuário tem acesso | Sem as credenciais o fluxo degrada com mensagem clara, **nunca** 500 (provado em 184-01-T3, 184-07-T1 e 184-11-T2) — o preenchimento é **184-13-T1/T3** |
| Programa `ecf-worker-ia` na VPS | A config do supervisor vive só na VPS, **fora** do repositório | O Job de leitura usa a fila `publicador-ia`; Job em fila sem consumidor fica parado **calado**. Conferir antes do deploy — todo aberto em **184-13-T3** |

---

## O que explicitamente NÃO tem gate

- **A homologação do Bling** (revisão humana, sem prazo publicado) — fora da fase, todo aberto em
  184-13-T3.
- **O comportamento real da API em conta trial** — é a suposição 1 do RESEARCH §5, e nenhum teste
  nosso a prova.
- **A tela de consentimento do Bling**, que lista "Anúncios" e "Lojas" ao cliente — fora do nosso
  controle e **tensão aceita pelo usuário na D-03**. O checkpoint 184-13-T2 **registra** o que
  apareceu; não corrige.
- **Deploy, push e qualquer comando no VPS** — exigem autorização explícita do usuário nesta fase
  (CONTEXT §6) e a árvore de trabalho está compartilhada com a sessão da Fase 180.
