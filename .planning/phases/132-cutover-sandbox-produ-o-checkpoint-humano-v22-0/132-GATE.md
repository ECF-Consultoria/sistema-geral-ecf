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

- Feito em: ______
- Confirmado por: ______
- Reconsulta (`config('services.clicksign.*')` no ambiente de produção): ______

### SC2 — Aviso automático (webhook) cadastrado na conta de PRODUÇÃO

⚠️ O usuário informou em 2026-08-17 que **provavelmente já está cadastrado** (todos os
escopos). Isso muda este passo de "cadastrar" para "conferir" — se estiver tudo certo, só
confirmar e seguir, sem recadastrar. O segredo é o item que mais engana: se o valor no
painel não for o mesmo do `.env` de produção, a Clicksign entrega o evento, o sistema
recusa com 401 e grava o evento bruto — do lado da Clicksign parece entrega bem-sucedida;
do nosso lado a empresa nunca é liberada.

- URL cadastrada (esperada: `https://admin.ecfconsultoria.com.br/api/webhooks/clicksign`,
  sem barra final, `https`): ______
- Escopos/eventos: ______ (informado: todos)
- Ativo (não cadastrado-e-desativado): ______
- Confirmado por: ______

### SC3 — Primeiro envelope de produção, empresa de teste controlada

- Empresa fictícia criada (D-03) — id: ______ nome: ______
- Contrato/envelope criado — id local: ______
- Webhook chegou e foi processado corretamente — confirmado por reconsulta em
  `contrato_assinatura_eventos`: ______
- `signature_valid` do primeiro evento real (prova melhor que ler o painel — responde de
  uma vez se o segredo do SC2 é o certo): ______

### SC4 — Gate empírico #3 (URL base de produção) confirmado contra o ambiente real

- URL base testada: ______
- Resultado (bateu com o documentado em `CLICKSIGN-SANDBOX-EMPIRICO.md`?): ______
- Confirmado por: ______

### Gate empírico #10 (rede de segurança / SC1 da Fase 130) — retomado pela D-04

- Aviso automático impedido de propósito, depois `clicksign:reconciliar` rodado: ______
- `ContratoLiberacao` criado com `via='reconciliacao'`: ______
- Veredito (`suficiente` / `insuficiente`, comparado com §8 do empírico): ______

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

- Ligado em: ______ (data, hora, quem) — reconsulta devolveu: ______
- Destravado para o contrato de teste — início: ______ fim: ______
- Travado de novo em: ______ — reconsulta devolveu: ______
- Desligado no fim em: ______ (data, hora, quem) — reconsulta devolveu: ______

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
