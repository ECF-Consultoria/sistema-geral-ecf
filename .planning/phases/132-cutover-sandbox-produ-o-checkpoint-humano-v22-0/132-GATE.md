# Fase 132 — Roteiro da virada para a Clicksign de produção

**Preparado em:** 2026-08-17, plano 132-01 (Task 3).
**Quem lê este documento:** qualquer pessoa que execute a virada — não precisa ser quem
planejou. Todo termo técnico é explicado na primeira vez que aparece. Preferir "virar a
chave para a Clicksign de verdade" a "cutover".

**Como usar:** os planos 132-02, 132-03 e 132-04 preenchem os campos de resultado abaixo,
na ordem em que os passos acontecem. Nada aqui é preenchido de antemão — os campos nascem
em branco de propósito.

---

## 1. O que está acontecendo aqui, em uma frase

Hoje o sistema conversa com a Clicksign de **TESTE** (a sandbox): nada do que ele faz lá
vale de verdade. Depois desta virada, ele passa a conversar com a Clicksign **de verdade**
(a de produção): os contratos gerados passam a valer juridicamente e os e-mails de convite
para assinar chegam em caixas de entrada reais — inclusive as dos três signatários fixos da
ECF (`thiago@`, `emerson@`, `comercial@`, restaurados para os endereços reais em
2026-08-17).

---

## 2. Antes de começar — o que precisa estar de pé

Conferir cada item ANTES de tocar em qualquer credencial:

- [x] As DUAS correções do plano 132-01 estão publicadas em produção:
  - a correção da grafia de `CLICKSIGN_ENV` (D-01) — as quatro grafias `producao`,
    `produção`, `production` e `prod` levam ao painel de produção; qualquer outra
    continua levando ao painel de teste;
  - o interruptor de emissão de contratos (D-07) — existe, está deployado, e a tela
    explica o motivo do bloqueio sem jargão quando ligado.

  **Resultado da publicação — 2026-08-17, executado por dev.01 (sessão Claude Code):**

  ⚠️ **O deploy foi MUITO maior do que o plano supunha, e isso precisa ficar registrado.**
  Ao conferir o servidor antes de publicar, descobriu-se que a `main` local e a `origin/main`
  estavam **divergidas desde 2026-08-11**: 220 commits só locais (fases 127 a 132 — o módulo
  Clicksign inteiro) e 14 commits só no GitHub (Precificação por família, Desempenho, NPS,
  fase 136, de outra máquina). O servidor estava em `origin/main`, ou seja: **as fases 129,
  130 e 131 nunca tinham ido para produção.** Confirmado por inspeção direta no servidor —
  `ContratoAdminController.php`, `ClicksignReconciliar.php` e `Admin/ContratoDetalhe.jsx`
  não existiam lá.

  Consequência: não havia como publicar só o 132-01 (ele altera dois arquivos que não
  existiam no servidor). O usuário autorizou explicitamente o deploy completo, estreando as
  fases 129, 130 e 131 em produção junto com o 132-01.

  | Item | Resultado |
  |---|---|
  | Merge `origin/main` → `main` | 1 conflito, em `AppLayout.jsx` — cada lado acrescentou um item de menu (Contratos / Métricas manuais). Resolvido mantendo os dois. |
  | Suítes após o merge | 157 testes verdes, 603 assertions (Phase131 + Phase132 + Phase136) |
  | Tabela de rotas | íntegra, com `admin.contratos.index`, `webhooks.clicksign` e `desempenho.metricas-manuais.index` presentes |
  | Commit publicado | `99f83c75` (merge) — inclui `d15d6049`..`ecfc7251` do plano 132-01 |
  | Data/hora do deploy | 2026-08-17, ~12h (BRT) |
  | Migrations aplicadas | **9**, todas `DONE` (de `2026_08_12_100000` a `2026_08_16_100000`) |
  | `ecf-worker:*` após restart | `ecf-worker_00` e `ecf-worker_01` em `RUNNING` |

  **Reconsulta no próprio servidor (não pelo `.env`), logo após o deploy:**

  | Conferência | Devolveu | Esperado nesta etapa |
  |---|---|---|
  | `ClicksignAmbiente::ehProducao('production')` | `sim` | `sim` — a classe existe e responde em produção ✅ |
  | `config('services.clicksign.env')` | `sandbox` | `sandbox` — a chave ainda NÃO virou ✅ |
  | `config('services.clicksign.painel_url')` | `https://sandbox.clicksign.com` | painel de TESTE — estado inicial registrado ✅ |
  | `CongelamentoEmissaoService::ativo()` | `desligado` | `desligado` — nasce desligado, nada mudou ✅ |

  - [x] **Conferência humana feita** (usuário, 2026-08-17): a tela está normal, sem aviso de
        emissão pausada — o interruptor está desligado, como esperado. O bundle novo subiu
        sem efeito colateral.

  ### ⚠️ Achado da conferência humana: produção não tem NENHUMA chave `CLICKSIGN_*`

  A tela mostrou o bloco vermelho **"Configuração interna da ECF pendente"**, listando os
  três signatários fixos da ECF como não configurados. Investigado: **o aviso está correto**
  — é a guarda `ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()` (Fase 127-07),
  deliberadamente separada das pendências da empresa, porque pendência de empresa é do
  Comercial e configuração da ECF é do admin.

  A causa, porém, é maior que os signatários. Medido no servidor:

  ```
  grep -c CLICKSIGN .env   →  0
  ```

  **O `.env` de produção não tem nenhuma das 16 chaves que o bloco `clicksign` de
  `config/services.php` lê.** A integração nunca foi configurada lá, o que é coerente com o
  fato de as fases 127–131 nunca terem sido publicadas antes de hoje.

  Consequências registradas:

  1. **A Task 2 não é "trocar 5 variáveis"** — é cadastrar o bloco Clicksign do zero:
     as 5 do checklist (`CLICKSIGN_ENV`, `CLICKSIGN_BASE_URL`, `CLICKSIGN_ACCESS_TOKEN`,
     `CLICKSIGN_WEBHOOK_SECRET`, `CLICKSIGN_TEMPLATE_ID`), mais `CLICKSIGN_API_USER_EMAIL`,
     mais os 6 dos signatários (`CLICKSIGN_SIG1/2/3_NOME` e `_EMAIL`), mais os opcionais de
     prazo/lembrete/upload/painel, que têm default no config.
  2. **As credenciais de produção NÃO estão estacionadas no servidor.** O plano supunha
     `CLICKSIGN_PROD_TOKEN` e `CLICKSIGN_PROD_WEBHOOK_SECRET` já presentes no `.env` de
     produção — não estão. Precisam vir de outra fonte, com quem as tem em mãos.
  3. **O procedimento de voltar atrás muda** — corrigido na seção 5, passo 2.
  4. **A janela perigosa da D-07 é hoje menor do que se supunha**: sem nenhuma credencial,
     produção não consegue emitir contrato nenhum. O interruptor continua obrigatório: a
     janela abre no instante em que a primeira chave entrar no `.env`.
- [ ] As 4 pessoas que vão assinar (ou receber convite de assinatura) já foram avisadas.
      ⚠️ Desde 2026-08-17 os e-mails dos três signatários fixos da ECF são **reais**
      (`thiago@`, `emerson@`, `comercial@`) — qualquer envelope emitido a partir de agora
      manda e-mail de verdade para essas três pessoas, mesmo o de teste da D-03.
- [ ] As credenciais de produção da Clicksign estão em mãos (token de acesso, segredo do
      aviso automático/webhook, UUID do modelo de contrato) — nunca escritas neste arquivo.
- [ ] A janela de horário escolhida **não é perto das 07:00**. O comando
      `clicksign:reconciliar` roda sozinho todo dia por volta desse horário e pode corrigir
      o estado de um contrato antes de alguém conseguir medir o "antes" — rodar a virada
      perto desse horário embaralha a medição do gate empírico #10.

---

## 3. Ordem dos passos, e por que essa ordem

**Pular a ordem quebra a medição.** Em especial: sem o aviso automático (webhook) cadastrado
ANTES do envelope de teste, não dá para saber se ele teria chegado — a Clicksign não reenvia
retroativamente um evento de um envelope criado antes do cadastro.

1. Publicar as duas correções do plano 132-01 (grafia + interruptor de emissão).
2. **Ligar o interruptor de emissão** — ver comando exato na seção 4.
3. Conferir as variáveis de ambiente de produção (SC1 do ROADMAP, gate empírico #3).
4. Cadastrar (ou conferir, se já estiver cadastrado) o aviso automático (webhook) no painel
   de produção (SC2).
5. Destravar a emissão por alguns minutos, só para gerar o contrato de teste da empresa
   fictícia (D-03), e travar de novo logo em seguida.
6. Provar a rede de segurança da Fase 130 com o mesmo envelope (D-04, gate empírico #10).
7. Colher a aprovação explícita do usuário (SC5).
8. **Desligar o interruptor de emissão** — só depois da aprovação, nunca antes.

### O interruptor de emissão, em duas frases

Enquanto ele está ligado, **ninguém gera contrato** pela tela do Administrativo — nem para
cliente nenhum, nem por engano. Ele existe porque entre a troca das credenciais (passo 3) e
a aprovação final (passo 7) podem passar horas ou dias, e nesse intervalo o sistema já
estaria emitindo documento com valor jurídico de verdade; **todo mundo com acesso de
administrador consegue gerar contrato**, então avisar a equipe e confiar na memória de cada
um não resolve.

---

## 4. Campos de resultado

Preencher cada campo com: o que foi feito, o que o **BANCO** devolveu (nunca a tela, nunca
o console), data/hora e quem executou.

### SC1 — Checklist de cutover conferido manualmente

`CLICKSIGN_ENV=production` (ou uma das outras três grafias válidas), `CLICKSIGN_BASE_URL`,
`CLICKSIGN_ACCESS_TOKEN` e `CLICKSIGN_WEBHOOK_SECRET` de produção.

- Feito em: **2026-08-17, ~17:42 (BRT)**
- Confirmado por: **dev.01 (sessão Claude Code), com autorização explícita do usuário**
- Reconsulta (`config('services.clicksign.*')` no ambiente de produção):

| Chave | Resultado da reconsulta | Veredito |
|---|---|---|
| `env` | `production` | de produção ✅ |
| `base_url` | `https://app.clicksign.com/api/v3` | de produção ✅ |
| `painel_url` | `https://app.clicksign.com` | de produção ✅ |
| `access_token` | 36 caracteres | preenchido ✅ (valor nunca impresso) |
| `webhook_secret` | 32 caracteres | preenchido ✅ (valor nunca impresso) |
| `template_id` | 0 caracteres | **pendente de propósito** — sai da Task 3 |
| `ClicksignAmbiente::ehProducao(env)` | `sim` | ✅ |

**Conferência de coerência (passo 10 — a que pega o erro mais caro):** o ambiente diz
`production` **e** a URL base contém `app.clicksign.com`. Coerente — não há estado
meio-virado.

⭐ **A linha `painel_url` é a prova de que a correção da D-01 está valendo em produção.**
O `.env` recebeu `production` (inglês, a grafia que o ROADMAP manda). O código anterior ao
plano 132-01 comparava `=== 'producao'` (português) e teria resolvido o painel para o
**sandbox** — o botão "Registrar e ir para a Clicksign" mandaria a equipe do Administrativo
para o painel de teste enquanto o sistema já operava em produção, sem avisar ninguém. Com o
normalizador, resolveu para o painel real. A armadilha era real e foi fechada antes de
morder.

**Conferências extras feitas antes de escrever (todas no `.env` local, sem imprimir valor):**
os 3 e-mails de signatário são distintos entre si (3 de 3), o e-mail do usuário da API
difere dos três (4 de 4), token ≠ secret, e o token de produção ≠ o token de sandbox.

**Efeito colateral bom, medido depois:** `faltantesDaConfiguracaoEcf()` devolve **0**
pendências e `signatarios_ecf` traz **3** entradas. O bloco vermelho "Configuração interna
da ECF pendente", que a conferência humana da Task 1 encontrou na tela, desapareceu.

**Sobre as duas comparações `!= sandbox` que o plano pedia:** não se aplicam. Elas
pressupunham o estacionamento `CLICKSIGN_SANDBOX_*` no `.env` de produção, e produção não
tinha credencial nenhuma (ver o achado na seção 2). A comparação equivalente foi feita na
origem, no `.env` local: o token gravado é o de produção, não o de sandbox.

**Sobre as variáveis mortas `CLICKSIGN_PROD_*`:** `grep -c "^CLICKSIGN_PROD_" .env` devolve
**0** — elas nunca existiram no servidor. Continuam existindo no `.env` **local** da máquina
de desenvolvimento, onde estavam estacionadas, e é de lá que os valores saíram.

#### ⚠️ Incidente durante a execução — `.env` inválido por alguns minutos

O bloco foi montado por um script que **removeu as aspas** dos valores, e três deles têm
espaço (os nomes dos signatários). Com `CLICKSIGN_SIG1_NOME=Nome Sobrenome` sem aspas, o
parser do dotenv falhou: `Failed to parse dotenv file. Encountered unexpected whitespace`.
Como o `config:clear` já havia rodado e o `config:cache` foi o comando que falhou, produção
ficou **sem cache de configuração e com um `.env` inválido** entre o `config:clear` e a
correção.

- **Correção:** `sed` reaplicando aspas nas três linhas `CLICKSIGN_SIG[123]_NOME`, depois
  `config:cache` (sucesso) e `supervisorctl restart ecf-worker:*`.
- **Impacto medido:** site responde `http=200` em 0,079 s; `grep -c "Failed to parse dotenv"`
  no `storage/logs/laravel.log` devolve **0**. A janela passou sem atingir requisição real.
- **Lição para a próxima virada:** valor de `.env` com espaço **precisa de aspas**, e a ordem
  `config:clear` → editar → `config:cache` deixa produção descoberta no meio. O certo é
  validar o arquivo (`php artisan config:cache` num ambiente de teste, ou um parse a seco)
  **antes** de limpar o cache do que está funcionando.

### SC2 — Aviso automático (webhook) cadastrado na conta de PRODUÇÃO

⚠️ O usuário informou em 2026-08-17 que **provavelmente já está cadastrado** (todos os
escopos). Isso muda este passo de "cadastrar" para "conferir" — se estiver tudo certo, só
confirmar e seguir, sem recadastrar. O segredo é o item que mais engana: se o valor no
painel não for o mesmo do `.env` de produção, a Clicksign entrega o evento, o sistema
recusa com 401 e grava o evento bruto — do lado da Clicksign parece entrega bem-sucedida;
do nosso lado a empresa nunca é liberada.

- URL cadastrada: **`https://admin.ecfconsultoria.com.br/api/webhooks/clicksign`** ✅
  (**corrigida em 2026-08-17** — ver o achado abaixo)
- Escopos/eventos: **32 eventos**, incluindo `sign`, `close`, `auto_close`, `cancel`,
  `refusal`, `deadline`, `document_closed` ✅
- Ativo: **`status: active`** ✅
- Segredo confere com o `.env` de produção: **CONFERE** ✅ — comparado por `hash_equals`,
  sem imprimir nenhum dos dois valores; `sha256` de ambos começa com `95174e7fbb`
- Confirmado por: **dev.01 (sessão Claude Code)**, por consulta à API de produção.
  Data/hora: **2026-08-17, ~18:45 (BRT)**
- Webhook id: `193b2977-cb16-4778-90ef-ea8904cd0486` · criado em `2026-08-12T11:43:10-03:00`

---

#### 🔴 ACHADO CRÍTICO — a URL do webhook apontava para lugar nenhum

**O que estava errado.** O webhook cadastrado em 12/08 apontava para
`https://admin.ecfconsultoria.com.br/administrativo/clicksign`. Esse caminho **não casa com
nenhuma rota da aplicação** — conferido no `route:list` e provado com requisição real:

| URL | POST devolve |
|---|---|
| `…/administrativo/clicksign` (a que estava cadastrada) | **404** — não existe nada ali |
| `…/api/webhooks/clicksign` (o receiver de verdade) | **401** — vivo, recusando corretamente uma requisição sem assinatura (D-10) |

**Por que isto era grave.** Todo evento da Clicksign cairia no vazio. O contrato seria
assinado, a Clicksign entregaria o aviso, e o sistema nunca saberia — nenhuma empresa seria
liberada. É **exatamente o critério de parar da D-06** ("nenhum evento aparecer em
`contrato_assinatura_eventos`"), só que descoberto **antes** de gastar um envelope, em vez de
depois de já ter mandado documento com valor jurídico para as três caixas de entrada reais
dos signatários — sabendo que a API **não cancela** envelope em andamento.

**Como foi descoberto — e por que o plano dizia que era impossível.** O plano 132-03
registrava, no `<verify>` da Task 1: *"MISSING — não existe conferência automatizada do
painel de um terceiro"*. Existe: a API v3 responde **200** em `GET /webhooks` e devolve
`endpoint`, `status`, `events`, `created` **e o `secret`**. Isso permitiu conferir os quatro
itens do SC2 sem nenhum acesso ao painel — inclusive a comparação do segredo, que o plano
tratava como só verificável pelo `signature_valid` do primeiro evento real.

⭐ **Registrar para as próximas fases:** `GET {base_url}/webhooks` é conferência de SC2
barata, somente-leitura e conclusiva. A "Pergunta A4" do empírico (cadastro de webhook por
conta ou por envelope) fica respondida: **por conta**, e listável pela API.

**Circunstância que forçou o caminho.** O usuário informou em 2026-08-17 que cadastrou o
webhook por **outra conta, à qual não tem mais acesso** — não podia ver a configuração nem
disparar evento de teste pelo painel.

**Correção aplicada — escrita autorizada explicitamente pelo usuário.** `PATCH
/webhooks/{id}` trocando **somente** o campo `endpoint`, por script que registrou o estado
"antes" para permitir reversão. `status: 200`.

Conferido por **reconsulta** (`GET /webhooks` de novo, nunca a resposta do PATCH):

| Campo | Depois | Verificação |
|---|---|---|
| `endpoint` | `…/api/webhooks/clicksign` | correto ✅ |
| `status` | `active` | preservado ✅ |
| `events` | **32** | nenhum evento perdido no PATCH ✅ |
| `secret` | `hash_equals` com o `.env` | CONFERE ✅ |

O script foi apagado do servidor e da máquina local depois de rodar.

**Nota sobre a tabela de eventos.** A sonda que provou o 404/401 gravou uma linha real:
`id=1, signature_valid=false, contrato_assinatura_id=nulo`. É a minha própria requisição sem
assinatura, e é evidência de que o receiver grava tentativa inválida como a DADOS-03 manda.
A linha de base para o SC3 deixa de ser "tabela vazia" e passa a ser **"1 evento, inválido,
sem contrato"** — qualquer linha nova além dessa veio do contrato de teste.

**O que ainda NÃO está provado.** Que a Clicksign consegue **entregar** nesta URL. A
correção resolve o endereço; a prova de entrega ponta a ponta é o `signature_valid = 1` do
primeiro evento real, na Task 3. O que mudou é que agora existe chance real de funcionar —
antes era falha garantida.

---

#### Roteiro de conferência do SC2 — escrito em 2026-08-17

**Por que este roteiro existe:** o SC2 é o único item da fase que não tem conferência
automatizada — não há como consultar por código o painel de um terceiro. Mas dá para provar
a parte que mais engana (o segredo) **sem criar envelope nenhum e sem disparar e-mail para
ninguém**, e é isso que este roteiro faz. Fazer o cadastro "no olho" e só descobrir o erro
no primeiro contrato real custaria um envelope que a API não cancela.

**Linha de base medida antes de começar (2026-08-17, ~18:30 BRT):**

| Medida | Valor |
|---|---|
| Eventos em `contrato_assinatura_eventos` | **0** — base limpa, qualquer linha nova veio do teste |
| Impressão digital do `CLICKSIGN_WEBHOOK_SECRET` de produção | `sha256` começa com **`95174e7fbb`** |
| Comprimento do segredo | 32 caracteres |

A impressão digital é um `sha256` — não dá para voltar dela ao segredo. Serve para provar
depois que o segredo **não mudou** entre o cadastro e o primeiro contrato real: basta
recalcular e comparar com `95174e7fbb`.

##### Passo 1 — abrir o painel certo

Entrar em `https://app.clicksign.com` e **conferir na barra de endereço** que não é
`sandbox.clicksign.com`. Parece bobagem; é a confusão mais comum quando as duas contas
ficam abertas em abas vizinhas.

##### Passo 2 — conferir os três campos do webhook

| Campo | Valor esperado | Armadilha |
|---|---|---|
| URL | `https://admin.ecfconsultoria.com.br/api/webhooks/clicksign` | sem barra no fim; `https`, nunca `http` |
| Eventos | todos os de assinatura e de fechamento do documento | faltar um evento = empresa nunca liberada naquele caso |
| Estado | **ativo** | cadastrado-e-desativado parece certo na lista e não entrega nada |

##### Passo 3 — o segredo

Se o painel **não mostra** o segredo depois de salvo (o mais comum), não tente adivinhar:
**salve de novo**, colando o valor de `CLICKSIGN_WEBHOOK_SECRET` do `.env` de produção. É
mais barato regravar do que descobrir divergência com um contrato real na rua.

##### Passo 4 — a prova empírica, sem gastar envelope

O receiver grava o evento **mesmo quando a assinatura não confere** (responde 401 e salva
com `signature_valid = 0` — é evidência deliberada de segredo trocado, ver DADOS-03/D-10).
Isso permite testar o segredo sem criar contrato:

1. No painel da Clicksign, disparar um **evento de teste** para o webhook (a maioria dos
   painéis tem esse botão na tela de configuração).
2. Reconsultar o banco:
   ```
   php artisan tinker --execute="\$e = App\Models\ContratoAssinaturaEvento::latest('id')->first(); echo \$e ? ('id=' . \$e->id . ' signature_valid=' . var_export(\$e->signature_valid, true)) : 'nenhum evento chegou';"
   ```

| O que aparecer | O que significa | O que fazer |
|---|---|---|
| `signature_valid=true` | **SC2 fechado de verdade.** URL certa, alcançável e segredo batendo. | Seguir para a Task 2 |
| `signature_valid=false` | A Clicksign alcançou o servidor, mas o **segredo diverge**. É exatamente a falha silenciosa que o SC2 existe para pegar. | Regravar o segredo no painel (passo 3) e repetir |
| `nenhum evento chegou` | A Clicksign não alcançou a URL — endereço errado, webhook inativo, ou o painel não tem botão de teste. | Conferir URL e estado; se não houver botão de teste, o SC2 fica como "conferido visualmente" e a prova real vem na Task 3 |

⚠️ **Se o painel não tiver disparo de teste**, isso não bloqueia a fase: registrar aqui que
a conferência foi visual, e tratar a Task 3 como o momento da prova — com atenção redobrada
ao `signature_valid` do primeiro evento real.

##### Passo 5 — registrar acima

Preencher os campos do SC2 com data/hora, autor e o resultado do passo 4. **Nunca o valor do
segredo** — registrar `confere` / `não confere`, ou a impressão digital `sha256`.

⚠️ **O interruptor de emissão continua LIGADO durante todo este roteiro.** Cadastrar ou
testar webhook não gera contrato. Quem destrava a emissão é a Task 3, e só por alguns
minutos.

### SC3 — Primeiro envelope de produção, empresa de teste controlada

- Empresa fictícia criada (D-03) — id: **424** nome: **`TESTE CUTOVER 132 - NAO E CLIENTE`**
  (CNPJ fictício `99.999.999/0001-99`; contato `Contato de Teste`, sem dígitos e sem
  parênteses — a Clicksign recusa com 400 no ponteiro do nome quando há; `email_cliente`
  `dev.01@`, distinto dos três signatários da ECF, escolhido pelo usuário)
- Vínculo de serviço: **id 321**, serviço **Gestão (id 6)**, setor `performance`
- Contrato/envelope criado — id local: **1** · envelope `5d2458b6…` · `status: rascunho`
- Envelope na Clicksign (reconsulta à API): `status: **draft**`, nome
  `Contrato — Gestão — TESTE CUTOVER 132 - NAO E CLIENTE`, prazo `2026-09-16`
- Signatários gravados no banco até aqui: **0**
- **Nada foi enviado a ninguém.** Rascunho é inerte (§12.3 do empírico): não dispara webhook,
  não é assinável, não manda e-mail.
- Pendências antes da geração, por reconsulta: `faltantes()` **vazio** e
  `faltantesDaConfiguracaoEcf()` **vazio** ✅
- Data/hora: **2026-08-17, 17:49:20 (horário da app, America/Sao_Paulo)**

#### 🔴 ACHADO — a janela do interruptor NÃO precisou ser aberta, e isso revelou um furo

**O que aconteceu.** O plano previa: destravar a emissão por minutos → clicar em "Gerar
contrato" na tela → travar de novo. **Nada disso foi necessário.** Ao criar a empresa e
vincular o serviço de Gestão, o contrato e o envelope nasceram **sozinhos, no mesmo segundo**
(17:49:20 para os três registros), **com o interruptor ligado**.

**A causa.** O interruptor (D-07) é checado **só** em `ContratoAdminController::gerarContrato()`
— o endpoint da tela. Existe um segundo caminho de geração, da Fase 128, que não passa por
lá: os observers `ContratoServicoGatilhoObserver` e `CompanyGatilhoContratoObserver` chamam
`GatilhoContratoAdministrativoService::dispararSeElegivel()`, e esse serviço **não menciona**
`CongelamentoEmissaoService` (`grep -c` devolve 0).

**A afirmação do plano 132-01 estava errada.** Ele registrava, no `<interfaces>`:
*"`gerarContrato()` — **único** ponto de geração do sistema; conferido: não há outro
chamador"*. Não é. E o ⛔ *"não tocar em `GatilhoContratoAdministrativoService`"* reforçou o
ponto cego.

**Gravidade real, medida e não suposta: limitada.** O gatilho automático gera com
`ativar: false`, então para no rascunho — inerte. Nenhum documento foi para caixa de entrada
nenhuma. O que fica é **rascunho acumulando na conta de produção** para qualquer empresa real
cujo cadastro seja completado durante o congelamento; não é vazamento para cliente, é sujeira
na conta real, a um passo humano de ser ativada.

⚠️ **A T-132-06 do threat model precisa ser corrigida.** Ela afirma que o interruptor fecha a
janela *"estruturalmente"*. Enquanto o gatilho automático não estiver coberto, a afirmação é
mais forte do que o código entrega.

Correção registrada em
`.planning/todos/pending/260817-interruptor-emissao-nao-cobre-gatilho-automatico.md` — é
mudança de código, então vira plano de gap, não edição improvisada no meio da virada
(disciplina do próprio plano 132-02).

**Efeito prático nesta fase, e é bom:** o objetivo da Task 2 foi atingido — empresa fictícia
marcada, envelope de produção em rascunho, nada enviado — **sem nunca abrir a janela em que
qualquer administrador poderia gerar contrato**. O caminho acidental foi mais seguro que o
planejado.
- Envelope ATIVADO pelo usuário em 2026-08-18. **Três das quatro pessoas assinaram**
  (falta apenas um signatário da ECF).
- Webhook chegou e foi processado corretamente — **SIM, depois de duas correções.**
  Reconsulta final: os **11 eventos** do documento estão `processado` e ligados ao
  `contrato_assinatura_id = 1`; o contrato saiu de `rascunho` para
  **`aguardando_assinaturas`**.
- `signature_valid` do primeiro evento real: **`true`** ✅ — e em 24 dos 25 eventos da base
  (o único inválido é a sonda sem assinatura que eu mesmo disparei para provar o 404/401).
  **O segredo do webhook está certo e a entrega funciona.**

#### Veredito do critério de parar (D-06)

**NÃO manda parar.** O critério é *"depois de ativar o contrato, nenhum evento aparecer em
`contrato_assinatura_eventos`"*. Eventos apareceram, com HMAC válido, e agora resolvem para o
contrato e movem o estado dele. O mecanismo está provado ponta a ponta: Clicksign → receiver
→ validação → gravação → job → contrato.

#### 🔴 Dois defeitos precisaram ser corrigidos para chegar aqui

**1. A resolução do contrato usava o identificador errado.** O corpo do webhook é baseado em
DOCUMENTO (`document.key`), mas a busca comparava com a coluna do ENVELOPE. As três
assinaturas reais chegaram e foram **todas descartadas** com *"envelope nao pertence a nenhum
contrato deste sistema"*; `ContratoLiberacao::count()` era 0. Corrigido por
`ContratoAssinatura::resolverPorReferenciaClicksign()`, que tenta envelope e cai para
documento — coluna que já existia e já vinha preenchida com exatamente o id que chega.
6 testes novos; commit `faf54f73`.

**2. REGRESSÃO EM PRODUÇÃO — a rota do receiver tinha sido REMOVIDA.** O deploy da milestone
de onboarding (09:44 de 2026-08-18, commit `616a2711`) apagou 56 linhas de `routes/web.php`
num merge sem conflito, entre elas:

| Rota perdida | Efeito |
|---|---|
| `POST /api/webhooks/clicksign` | **o receiver inteiro** — medido ao vivo: `404`. Todo evento da Clicksign era descartado sem registro |
| `GET /admin/contratos/{id}/pdf-assinado` | download da evidência jurídica |
| grupo `admin.contratos.*` (Fase 131) | index, show, cadastro, gerar, reenviar, cancelamento, liberação manual |

O controller e as telas continuavam versionados — só as rotas sumiram. O item **"Contratos"**
do menu aponta para `admin.contratos.index`, então a barra lateral resolvia rota inexistente
para todo usuário com a permissão.

**Janela do apagão: 09:44 até ~11:00 de 2026-08-18.** Restaurado no commit `26535a0c` e
deployado; `POST /api/webhooks/clicksign` voltou a responder `401` (vivo) em vez de `404`.

⚠️ **Lição:** este merge não teve conflito. Git aceitou a remoção em silêncio. Uma milestone
paralela pode apagar rotas de outra sem nenhum aviso — vale um teste que afirme a existência
das rotas críticas (`webhooks.clicksign` acima de tudo), para que a suíte pegue o que o merge
não pega.

#### Reprocessamento das assinaturas já colhidas

Os 11 eventos ficaram gravados com payload íntegro. Depois do fix foram reenfileirados e
**todos processaram** — nenhuma assinatura se perdeu, e não foi preciso gastar outro envelope.

⚠️ O reprocessamento em lote esbarra no limite de **3/min** do bucket `clicksign-webhook`:
a primeira tentativa estourou tentativas em 8 dos 11 (`MaxAttemptsExceededException`, 8 linhas
novas em `failed_jobs`). Refeito em lotes de 2 com 70 s de intervalo, todos passaram. Quem for
reprocessar evento em massa precisa respeitar esse limite.

#### 🔴 Terceiro defeito, encontrado e CORRIGIDO — nada criava linha de signatário

`ContratoAssinaturaSignatario` estava com **0 linhas na base de produção inteira**, e o grep
por `::create` nessa tabela não achava nada: **nenhum código do sistema a preenchia.**

As três assinaturas reais chegaram, validaram o HMAC, e o sync mandou as três para
`naoReconhecidos` — porque `ContratoSignatariosSyncService` só ATUALIZA linha existente,
nunca cria. Isso é deliberado (T-129-16: o webhook não pode inventar quem assina) e a decisão
está certa; o que faltava era o lado que cria. O `ClicksignClient` **já devolvia**
`signatarios` com id, nome, e-mail e papel em `montarEnvelopePorModelo()` — o
`GerarContratoAssinaturaJob` simplesmente descartava o retorno.

Sem isso também não funcionavam:
- o **reenvio de aviso** da Fase 131 — a rota liga `{signatario}`, que nunca resolveria;
- a **guarda de evidência** (`ip_address`, `auths`, `evidencia_signer`), que só é gravada
  sobre linha existente.

**Corrigido** no commit `727183eb`: o job persiste os signatários pela chave do signer, todos
nascendo `pendente`; `updateOrCreate` torna a reentrega idempotente. 3 testes novos, sendo o
principal a prova do elo quebrado (evento `sign` acha o signatário, marca `assinou`, grava
data e IP). 176 testes verdes nas suítes 129, 131 e 132.

**Backfill do contrato 1** (nasceu antes do fix): as 4 linhas foram criadas a partir da
própria Clicksign, e o sync reaplicado devolveu:

```
{"assinaram":3,"recusaram":0,"nao_reconhecidos":[]}
```

| Signatário | Papel | Situação | Assinou em |
|---|---|---|---|
| `thiago@` | contratada | **assinou** | 2026-08-18 09:19:43 |
| `dev.01@` | contratante | **assinou** | 2026-08-18 09:16:44 |
| `comercial@` | testemunha | **assinou** | 2026-08-18 09:06:23 |
| `emerson@` | contratada | pendente | — |

✅ **A corrente está completa e provada com dados reais:** Clicksign → receiver → HMAC →
gravação → job → contrato → **situação por signatário**. O sistema sabe quem assinou, quando,
e quem falta.

- Empresa liberada para o operacional: **não** — esperado, falta a quarta assinatura.
- `signature_valid` do primeiro evento real (prova melhor que ler o painel — responde de
  uma vez se o segredo do SC2 é o certo): ______

### SC4 — Gate empírico #3 (URL base de produção) confirmado contra o ambiente real

- URL base testada: **`https://app.clicksign.com/api/v3`**
- Resultado: **bate com o documentado** em `CLICKSIGN-SANDBOX-EMPIRICO.md` §1.
- Veredito do gate empírico #3: **CONFIRMADO** — a URL foi provada por chamada real e
  somente-leitura (`clicksign:sondar-modelo --listar --producao`, um `GET /templates`)
  contra a conta de produção, não contra a documentação. A consulta respondeu e listou o
  modelo de contrato da ECF: **`modelo-contrato-gestao-ads-mercado-livre`**.
- Nenhum dos dois 403 apareceu — nem `A conta não possui acesso a essa funcionalidade`
  (que seria decisão comercial e mandaria parar), nem `E-mail do usuário da API não
  configurado`. A conta de produção já estava com o campo preenchido desde 12/08/2026.
- Executado por: **usuário, em sessão interativa no VPS** (o comando exige confirmação
  digitada fora de sandbox e aborta sozinho sem terminal — comportamento seguro por
  desenho, e o motivo de este passo não poder rodar por automação).
- Confirmado por: **dev.01 (sessão Claude Code)**, que gravou o UUID e conferiu por
  reconsulta. Data: **2026-08-17, ~18:20 (BRT)**.

**`CLICKSIGN_TEMPLATE_ID` configurado e valendo** (o UUID de produção é diferente do de
teste — é o esquecimento que o SC1 do ROADMAP não previa):

| Conferência | Resultado |
|---|---|
| `config('services.clicksign.template_id')` | **36 caracteres** ✅ (valor não registrado aqui) |
| Chaves `CLICKSIGN_*` ativas no `.env` | 12 (11 da Task 2 + o modelo) |
| `env` / `base_url` após o novo cache | `production` / `https://app.clicksign.com/api/v3` ✅ |
| Interruptor de emissão | **ainda `ligado`** ✅ — nada nesta task o desligou |
| Pendências de configuração da ECF | **0** ✅ |
| Site | `http=200` ✅ |

**Lição aplicada do incidente da Task 2:** desta vez rodou-se `config:cache` **direto**, sem
`config:clear` antes. O `config:cache` já limpa antes de gravar, então não existe janela em
que produção fique sem cache — e a validação do `.env` (chave presente, 36 caracteres,
nenhum valor com espaço sem aspas) foi feita **antes** de tocar no cache.

**Armadilha nova encontrada, e já registrada pelo outro dev hoje de manhã:** o
`supervisorctl restart ecf-worker:*` **travou** — um worker ficou preso em `STOPPING` e
segurou o comando por mais de 2 minutos. Destravado com
`supervisorctl signal KILL ecf-worker:*` seguido de `supervisorctl start ecf-worker:*`.
Estado final conferido: os dois workers em `RUNNING`. ⚠️ Quem executar a Task 4 ou o
procedimento de voltar atrás precisa **conferir o status depois do restart** — o comando
retornar não prova que o worker subiu, e é o worker quem cria o envelope na Clicksign.

### Gate empírico #10 (rede de segurança / SC1 da Fase 130) — retomado pela D-04

- Aviso automático impedido de propósito, depois `clicksign:reconciliar` rodado: **SIM** —
  2026-08-18, entre ~11:05 e ~11:14 (BRT), executado por dev.01 (sessão Claude Code).
- `ContratoLiberacao` criado com `via='reconciliacao'`: **SIM** — `id=1`, `via=reconciliacao`,
  `company_id=424`, `servico_id=6`, criado `2026-08-18 11:13:02`.
- Veredito: **SUFICIENTE.** ✅

#### Como o cenário foi montado — e por que ele é honesto

O Emerson (`contratada`) viajou e não assinaria. Sem a quarta assinatura o envelope não
fecharia, e sem envelope fechado não há liberação — o `GateLiberacaoOperacionalService`
exige `status === 'closed'` como primeira condição.

Sequência executada, cada passo conferido por reconsulta:

| # | Ação | Resultado |
|---|---|---|
| 1 | `PATCH /webhooks/{id}` → `status: inactive` | aviso desligado; endpoint e 32 eventos preservados |
| 2 | `DELETE /envelopes/{id}/signers/{id}` do pendente | **HTTP 204** — signatários no envelope: 4 → 3 |
| 3 | Aguardar auto-close | ❌ **não fechou** — ver o achado abaixo |
| 4 | `PATCH /envelopes/{id}` → `status: closed` | ❌ **HTTP 400** — `status deve estar em: draft, running` |
| 5 | `PATCH /envelopes/{id}` → `deadline_at` = agora + 3 min | ✅ HTTP 200 |
| 6 | Aguardar o prazo vencer | ✅ envelope → **`closed`** |
| 7 | `php artisan clicksign:reconciliar` | `1 contratos vistos, 1 despachados` |
| 8 | Reconsulta | ✅ contrato `assinado`, `ContratoLiberacao via=reconciliacao` |
| 9 | `PATCH /webhooks/{id}` → `status: active` | aviso religado, conferido |

**A prova é honesta porque o sistema esteve genuinamente cego.** A contagem de eventos ficou
em **25 do começo ao fim** — nenhum evento de fechamento entrou. O contrato passou de
`aguardando_assinaturas` para `assinado` **sem o webhook contribuir com nada**: quem percebeu
foi a varredura, e o carimbo `via=reconciliacao` prova isso no histórico.

A Fase 130 tem 18 testes verdes e **nunca havia enfrentado um contrato realmente assinado** —
o sandbox da Clicksign não conclui assinatura. Esta foi a primeira vez, e ela passou.

#### 🔬 Dois achados novos sobre a API, para o empírico

**1. Remover signatário de envelope `running` FUNCIONA** — `DELETE
/envelopes/{id}/signers/{signerId}` devolveu **204** e os requisitos daquele signatário
saíram junto (8 → 6). Isso não estava medido: o §15.1 dizia que corrigir e-mail de signatário
não existe e que "trocar a pessoa que assina colapsa em cancelar e reemitir". **Remover, não —
remover funciona.**

**2. Remover o último pendente NÃO dispara o auto-close.** Mesmo com `auto_close: true` e
todos os requisitos restantes cumpridos (os 6 com `modified` batendo com os horários das três
assinaturas), o envelope ficou `running`. A leitura: a Clicksign avalia o fechamento **quando
chega uma assinatura**, não quando a lista de pendentes esvazia por remoção. Quem quiser
fechar um envelope cujo último pendente saiu precisa **antecipar o `deadline_at`** e deixar o
`deadline_partial_signature_action: "closed"` agir — foi o que funcionou aqui, e é operação
suportada (`update_deadline` está na lista de eventos do webhook).

⚠️ Consequência prática para a operação: um contrato de cliente real cujo último signatário
seja removido **fica preso em `running` até o prazo** — e a empresa não é liberada nesse
tempo. Vale conhecer antes de alguém remover signatário achando que "resolve".

### SC5 — Aprovação explícita da virada

- **CHECKPOINT HUMANO.** Usuário aprova, por escrito, que a virada está correta ANTES de
  qualquer contrato real de cliente ser gerado em produção: ______
- Data/hora: ______

### Interruptor de emissão — as quatro linhas

Comandos exatos, para ninguém digitar o nome da chave à mão:

- **ligar:**
  `php artisan tinker --execute="app(App\Services\Clicksign\CongelamentoEmissaoService::class)->ligar();"`
- **desligar:**
  `php artisan tinker --execute="app(App\Services\Clicksign\CongelamentoEmissaoService::class)->desligar();"`
- **conferir:**
  `php artisan tinker --execute="echo app(App\Services\Clicksign\CongelamentoEmissaoService::class)->ativo() ? 'ligado' : 'desligado';"`

⚠️ **Conferir é obrigatório depois de ligar e depois de desligar** — a única prova é a
reconsulta, nunca a ausência de mensagem de erro.

- Ligado em: **2026-08-17, ~17:25 (BRT), por dev.01 (sessão Claude Code)** — reconsulta
  devolveu: **`ligado`** ✅
  ⚠️ Ligado **antes** de qualquer credencial entrar no `.env`, como a D-07 exige. Reconferido
  depois da troca e depois do `config:cache` + restart dos workers: continua **`ligado`** —
  confirmando que a chave é leitura de banco a cada chamada e não é afetada por cache de
  configuração nem por reinício de worker.
- Destravado para o contrato de teste — início: **NUNCA DESTRAVADO** fim: **—**
  ✅ A janela não precisou ser aberta: o envelope foi criado pelo gatilho automático da Fase
  128, que não passa pelo interruptor (ver o achado na seção SC3). O interruptor permaneceu
  **ligado sem interrupção** desde ~17:25. Do ponto de vista do risco que a D-07 existe para
  conter, é o melhor desfecho possível — em nenhum momento a tela ficou liberada para
  qualquer administrador gerar contrato.
- Travado de novo em: **não se aplica** — nunca foi destravado
- Desligado no fim em: ______ (data, hora, quem) — reconsulta devolveu: ______

**Backup do `.env` anterior à virada (passo 2 da Task 2):**
`/root/env-antes-da-virada-clicksign-20260817`, permissão `600`, dono `root`, 104 linhas —
fora de `public/` e fora do repositório, conforme T-132-10.

---

## 5. Como voltar atrás (D-02) — procedimento numerado

1. ⚠️ **O interruptor de emissão FICA LIGADO.** Voltar atrás não é hora de liberar a
   emissão de contrato: enquanto a causa do problema não estiver entendida, ninguém gera
   nada. Se por algum motivo ele estiver desligado neste momento, **ligar antes de
   qualquer outra coisa** (comando na seção 4) e conferir por reconsulta.
2. ⚠️ **CORRIGIDO em 2026-08-17 — este passo estava escrito sobre uma premissa falsa.**
   O plano supunha que o `.env` de produção tinha credenciais de TESTE para restaurar.
   **Não tinha.** Medido no servidor logo após o deploy: `grep -c CLICKSIGN .env` devolveu
   **0** — o `.env` de produção não continha nenhuma das 16 chaves `CLICKSIGN_*`, porque as
   fases 127 a 131 nunca haviam sido publicadas lá. O `sandbox` que a reconsulta devolveu
   para `config('services.clicksign.env')` era o **default do `config/services.php`**, não
   uma configuração de sandbox existente.

   Portanto, voltar atrás **não é restaurar credenciais de teste** — é **remover as chaves
   `CLICKSIGN_*` que a Task 2 acrescentou**, devolvendo o sistema ao estado em que ele
   estava antes da virada: Clicksign não configurada. Esse estado é seguro por construção —
   sem token, o sistema não consegue emitir contrato nenhum, e a tela do Administrativo
   mostra o aviso "Configuração interna da ECF pendente" em vez de gerar qualquer coisa.

   Se, mesmo assim, se quiser deixar produção apontada para o sandbox (por exemplo para
   continuar testando), aí sim usar os valores do estacionamento `CLICKSIGN_SANDBOX_*` —
   mas isso é uma escolha, não o caminho de reversão.
3. Rodar `php artisan config:clear && php artisan config:cache`. ⚠️ **Enquanto a
   configuração está em cache, editar o `.env` não muda absolutamente nada** — este passo
   não é opcional nem cosmético.
4. Reiniciar os workers da fila (`supervisorctl restart ecf-worker:*`). ⚠️ **O worker
   guarda a configuração antiga na memória** — sem reiniciar, ele continua falando com a
   Clicksign de produção mesmo com o `.env` já revertido.
5. ⚠️ **Restaurar o `.env` NÃO impede um contrato já enfileirado de sair.** A janela é
   curta (segundos até cerca de um minuto, por causa do limite de 1 envelope por minuto do
   próprio app), mas existe. **Abrir o painel da Clicksign de produção e conferir com os
   próprios olhos** se algum envelope saiu depois da reversão — nunca assumir que parou na
   hora.
6. Se saiu algo: **a API não cancela envelope em andamento** (medido —
   `CLICKSIGN-SANDBOX-EMPIRICO.md` §15.2). Cancelar é operação de **painel da Clicksign**.
   Registrar o cancelamento pela tela do Administrativo (`/administrativo/contratos`, ação
   de registrar cancelamento — Fase 131, D-13), que grava quem pediu e por quê, e
   **concluir o cancelamento no painel da Clicksign**.

---

## 6. Quando PARAR (D-06)

Um único critério manda parar e voltar atrás: **depois de ativar o contrato de teste,
nenhum evento aparecer na tabela `contrato_assinatura_eventos`.**

Por quê: sem o aviso automático confirmado, nenhuma empresa é liberada sozinha, e a Fase
133 estaria ligando o bloqueio do roteamento em cima de um mecanismo nunca provado
funcionando. É o único item que sozinho invalida a virada.

Uma diferença cosmética (um campo com nome estranho, um horário em fuso diferente) **não**
manda parar — anota-se e segue.

---

## 7. Lixo que fica para limpar depois

- A empresa fictícia e o contrato criados no plano 132-03 (D-03, custo aceito) ficam na
  base de produção. Registrar aqui os ids assim que existirem, para que a limpeza futura
  saiba o que apagar:
  - Empresa fictícia — id: ______
  - Contrato — id: ______
- **O interruptor de emissão não pode ficar ligado e esquecido.** Se a fase terminar em
  qualquer estado que não seja "aprovado" (SC5), quem parar precisa dizer por escrito,
  neste arquivo, qual é o estado do interruptor no momento em que parou e por quê:
  - Estado do interruptor ao parar: ______
  - Motivo: ______
