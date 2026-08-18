# Fase 130 — Gate humano em sandbox (plano 130-07)

**Preparado em:** 2026-08-13, sessão do plano 130-07.
**Status geral:** ⚠️ **NENHUM dos 3 Success Criteria pode ser marcado aprovado nesta rodada.**
SC1 e SC3 exigem ação humana no navegador (assinar de verdade na Clicksign / usar a tela
logado) que este executor não tem ferramenta para fazer. SC2 teve a metade técnica (comando +
banco) provada por este executor, mas a leitura humana da mensagem no sino continua pendente.

> Molde: `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md`. Nenhum gate foi inventado —
> "pendente" é um resultado honesto, não uma reprovação silenciosa.

---

## Ambiente preparado pelo executor (antes de pedir qualquer coisa ao usuário)

| Item | Estado encontrado | Ação tomada |
|---|---|---|
| MariaDB local (`mysqld`) | ❌ Fora do ar (`tasklist` vazio) — mesma instabilidade já registrada nos SUMMARY.md dos planos 130-01/04/05/06 | ✅ Subido via `C:\xampp\mysql\bin\mysqld.exe` |
| Apache local (`httpd`) | ❌ Fora do ar | ✅ Subido via `C:\xampp\apache_start.bat` |
| Migrations | 2 pendentes: `..._add_motivo_slug_to_contrato_liberacoes_table` e `..._add_ultimo_alerta_em_to_contrato_assinaturas_table` | ✅ `php artisan migrate --force` aplicou as 2 |
| Banco local `ecf_admin` | Vazio: 0 `companies`, 0 `contrato_assinaturas`, 0 `notifications`; 1 `users` mas soft-deleted e `role=consultor` — **nenhum admin utilizável** | Ver fixtures criadas abaixo |
| `QUEUE_CONNECTION` | `sync` (não `database`) — jobs despachados rodam IMEDIATAMENTE dentro do próprio request/comando, não existe `queue:work` para "segurar" nada | Roteiro do SC1 abaixo foi ajustado — "impedir o webhook" não é parar um worker, é não expor túnel nenhum durante a assinatura |
| `CLICKSIGN_ENV` / `CLICKSIGN_BASE_URL` | ✅ `sandbox` / `https://sandbox.clicksign.com/api/v3` — confirmado, nenhum risco de bater em produção |
| `CLICKSIGN_SIG1/2/3_NOME/EMAIL` (signatários fixos da ECF) | ❌ **Não configurados no `.env` local** — `ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()` reconsultado devolve 6 pendências (nome+e-mail de `contratada` ×2 + `testemunha`) | **Não preenchido pelo executor** — são identidade de pessoas reais (sócios da ECF); não é dado que este executor deva inventar. Fica como pré-requisito do usuário no SC1 |
| URL local real do app | `.env` tem `APP_URL=http://localhost/ecf_admin/public` (desatualizado) — a pasta do projeto é `htdocs/ecf_admin/ecf_admin`, então a URL que RESPONDE de verdade é `http://localhost/ecf_admin/ecf_admin/public/...` (confirmado por `curl`: `/login` → 200 nesse caminho, 404 no caminho do `.env`) | Documentado abaixo para os roteiros do SC2/SC3 |
| Front-end (SC3) | `public/build/manifest.json` já mais novo que `ContratosLiberacaoManual.jsx` — build está em dia | Nenhuma ação necessária |

### Fixtures de teste criadas (T-130-07-04 — empresa de teste controlada, nunca cliente real)

Todos os dados abaixo são fictícios, com domínio `@example.com`, marcados literalmente como
"Gate 130-07" para nunca serem confundidos com dado real:

| Fixture | Valor |
|---|---|
| Usuário admin de teste | `id=3`, e-mail `gate130-admin@example.com`, senha `gate130teste`, `role=admin`, `active=1` |
| Usuário NÃO-admin de teste (para o 403 do SC3) | `id=4`, e-mail `gate130-naoadmin@example.com`, senha `gate130teste`, `role=consultor`, `active=1` |
| Empresa de teste | `id=16`, nome "Empresa Ficticia Gate 130-07", `email_cliente=cliente.gate13007@example.com`, `cnpj=00000000130701`, `nome_contato` preenchido — `ContratoDadosMinimosService::faltantes()` reconsultado devolve `[]` (só falta a config ECF acima) |
| `ContratoServico` ativo | `id=7`, empresa 16, serviço 6 (Gestão), `ativo=true`, `data_contratacao` preenchida |
| `ContratoAssinatura` "preso" (recusado, 6 dias parado) | `id=9`, empresa 16, serviço 6, `status='recusado'`, `created_at`/`updated_at` forçados para 6 dias atrás (> limiar padrão de 5 dias) — usado para o SC2 (já executado, ver abaixo) e reservado para o teste da faixa vermelha D-11 do SC3 |

Nenhum dado de e-mail/CPF de signatário Clicksign real entrou em qualquer um destes registros.

---

## SC1 — Reconciliação em sandbox (+ gate empírico #10)

**STATUS: ⏳ PENDENTE.** Depende de ação humana real na interface web da Clicksign (ativar e
assinar um envelope) — nenhuma ferramenta deste executor abre navegador ou assina documento.
Nenhum resultado foi inventado.

### Pré-requisito que só o usuário pode resolver

Antes de qualquer coisa, preencher no `.env` local (nunca commitar):

```
CLICKSIGN_SIG1_NOME=...
CLICKSIGN_SIG1_EMAIL=...
CLICKSIGN_SIG2_NOME=...
CLICKSIGN_SIG2_EMAIL=...
CLICKSIGN_SIG3_NOME=...
CLICKSIGN_SIG3_EMAIL=...
```

São os 3 signatários fixos do lado ECF (2 "contratada" + 1 "testemunha") que entram em TODO
contrato gerado (`config/services.php` → `clicksign.signatarios_ecf`). Sem isso,
`ContratoClicksignService::iniciarParaEmpresa()` recusa ANTES de qualquer chamada HTTP (por
desenho — D-08 do plano 127-07). Podem ser os mesmos valores já usados nas rodadas anteriores
(Fase 126-129) se o usuário tiver esse `.env` antigo à mão, ou dados de teste válidos do sandbox.
Depois de preencher: `C:\xampp\php\php.exe artisan config:clear`.

### Roteiro ajustado ao ambiente real (não copiar literalmente o `130-07-PLAN.md` — os passos
### abaixo já incorporam os 3 achados de ambiente acima)

1. **Gerar o contrato + envelope para a empresa de teste já pronta** (id 16, serviço Gestão já
   ativo, todos os dados mínimos presentes):
   ```
   C:\xampp\php\php.exe artisan tinker --execute="
   \$company = App\Models\Company::find(16);
   \$svc = app(App\Services\Clicksign\ContratoClicksignService::class);
   print_r(\$svc->iniciarParaEmpresa(\$company));
   "
   ```
   Isso cria um `ContratoAssinatura` novo em `rascunho` e despacha
   `GerarContratoAssinaturaJob` — como `QUEUE_CONNECTION=sync`, ele roda IMEDIATAMENTE dentro
   do mesmo comando (nenhum `queue:work` necessário) e já faz a chamada real à Clicksign
   sandbox para criar o envelope. Anotar o `id` do contrato criado (reconsultar
   `ContratoAssinatura::latest()->first()`).
2. **NÃO montar túnel nenhum desta vez.** Ao contrário do gate da Fase 129 (que precisava expor
   `php artisan serve` para o webhook chegar), o objetivo AQUI é o oposto: reproduzir "o aviso
   automático nunca chegou". Sem túnel, sem `php artisan serve` de fora, o webhook da Clicksign
   simplesmente não tem para onde ir — não precisa "derrubar" nada no meio do caminho.
3. Entrar no painel do **sandbox** da Clicksign (confirmar que é sandbox — `CLICKSIGN_ENV` já
   está confirmado como sandbox neste ambiente) e localizar o envelope do contrato criado no
   passo 1 (pelo nome da empresa, "Empresa Ficticia Gate 130-07").
4. **Ativar** o envelope (rascunho é inerte — medido na Fase 129, `129-GATE.md`) e **assinar**
   até a conclusão, pela interface web.
5. Confirmar o ponto de partida por RECONSULTA AO BANCO — o contrato deve continuar em
   `aguardando_assinaturas` e `ContratoLiberacao::where('company_id', 16)->count()` deve
   continuar `0` (prova de que o webhook realmente não processou nada):
   ```
   C:\xampp\php\php.exe artisan tinker --execute="
   \$c = App\Models\ContratoAssinatura::where('company_id',16)->latest()->first();
   echo \$c->status . ' liberado_em=' . var_export(\$c->liberado_em, true) . PHP_EOL;
   echo App\Models\ContratoLiberacao::where('company_id',16)->count() . PHP_EOL;
   "
   ```
6. Rodar a reconciliação (sync, roda tudo dentro do mesmo comando):
   ```
   C:\xampp\php\php.exe artisan clicksign:reconciliar
   ```
7. Conferir por RECONSULTA AO BANCO (mesma query do passo 5, mais o carimbo):
   - `status === 'assinado'` e `liberado_em` preenchido;
   - `ContratoLiberacao::where('company_id', 16)->where('via', 'reconciliacao')->count() === 1`;
   - `json_decode(Configuracao::get('clicksign_reconciliacao_status'), true)` com `corrigidos >= 1`
     e `erro === null`.
8. **Gate empírico #10** — confrontar o que `consultarEnvelope()` devolveu (visível no log
   `storage/logs/ecf-webhooks-<data>.log`, `[ReconciliarContratoClicksignJob] contrato
   reconciliado`) com `CLICKSIGN-SANDBOX-EMPIRICO.md` §8: a granularidade (`status` do envelope +
   eventos do documento via `listarEventosDoDocumento()`) foi SUFICIENTE para decidir a liberação
   sem nenhuma chamada extra? Responder `suficiente` ou `insuficiente` + o que faltou.

### Resultado

> ✅ **DESBLOQUEADO E FECHADO NA FASE 132 (2026-08-18).** O bloqueio era da sandbox, que não
> conclui assinatura. Em produção a rodada foi possível e a rede de segurança passou — ver
> `132-GATE.md`. O texto abaixo é registro histórico.

**STATUS: ⛔ BLOQUEADO pela sandbox da Clicksign** (rodada de 2026-08-13/14, decisão do usuário
de parar aqui e fechar os demais gates).

O pré-requisito que faltava foi resolvido: `CLICKSIGN_SIG1/2/3` preenchidos, e
`ContratoDadosMinimosService::faltantesDaConfiguracaoEcf()` reconsultado devolve **0 pendências**.
O envelope foi criado e ativado de verdade. O que não foi possível foi **assinar**.

#### Até onde chegou (medido, não suposto)

| Etapa | Resultado |
|---|---|
| Contrato local | `ContratoAssinatura` id **11**, empresa 16, serviço 6 (Gestão) |
| Envelope na sandbox | `f010d235-ff75-400a-84b7-01cb89c3ef59` — criado e **ativado** (`draft` → `running`) |
| Documento | `36fac7dc-e6c7-4a51-84b7-ce168cf583a3`, `contrato-11.docx`, gerado do template `9e5d4517-…` |
| Signatários | 4 adicionados com `201` — Fulano de Tal Teste (parte), Thiago Messina + Emerson Faccioli (contratada), Jessica Oliveira (testemunha) |
| Assinatura | **0/4.** 3 eventos `signature_started`, **nenhum** `sign` |
| Baseline | `ContratoLiberacao::where('company_id',16)->count() = 0` (o sistema segue cego, como o teste exige) |

#### Achados desta rodada (valem para as próximas fases)

1. **A ativação pela interface web NÃO funciona para envelope gerado por modelo.** O documento
   fica preso em *"Enviando documento…"* na tela de rascunho e o botão **Enviar** nunca sai do
   cinza. `ativarEnvelope()` pela API resolveu na hora (`draft` → `running`). A produção usa a
   API, então o fluxo real não é afetado — mas **nenhum gate futuro deve depender de ativar pela
   tela**.

2. **A sandbox não envia e-mail.** Nenhum evento de notificação foi gerado na ativação, e nada
   chegou na caixa. Combinado com o achado 2 do `129-GATE.md` (*a v3 não expõe link de assinatura
   nos atributos do signatário — o link só sai por e-mail*), isso deixa **o painel como único
   caminho de assinatura na sandbox**.

3. **A tela de assinatura do painel não conclui.** Tanto em lote (4 de uma vez) quanto um a um,
   a tela fica em *"Estamos processando o(s) documento(s)"* indefinidamente. Cada tentativa grava
   `signature_started` e nenhuma grava `sign`.

4. **Hipótese principal, NÃO confirmada:** os 4 signatários foram apontados para o mesmo e-mail
   (`adm@ecfconsultoria.com.br`, a pedido do usuário, para não incomodar os sócios). A Clicksign
   marcou os 4 como "(Você)" no painel, e a tela pode não resolver qual dos quatro está
   assinando. **Não foi possível confirmar** — a comparação com a Fase 129 não é limpa, porque
   os envelopes que ela assinou vinham da Fase 128, criados sob outra configuração de
   signatários. A alternativa (endereços distintos via `adm+sig1@`) foi levantada e **recusada
   nesta rodada** por risco de os documentos sumirem da "Minha área de assinatura" ao deixarem de
   bater com a conta logada — trocaria um travamento por um beco sem saída.

5. **BUG ENCONTRADO EM CÓDIGO DE FASE ANTERIOR (fora do escopo da 130):**
   `ClicksignClient::reenviarNotificacao()` faz `->post($url)` **sem corpo** e a API v3 responde
   `data deve ser informado(a)`. O reenvio de notificação está quebrado **também em produção**;
   nunca foi exercitado. Merece um `/gsd:quick` próprio — não foi corrigido aqui para não
   misturar com o gate.

6. **A ativação confirma de novo o achado 3 do `129-GATE.md`:** os 4 `add_signer` +
   `update_deadline` só apareceram como eventos no instante da ativação, descrevendo
   retroativamente o que acontecera durante o rascunho.

#### O que isso significa para a fase

O que **não** foi provado é o comportamento end-to-end contra um envelope realmente assinado. O
código da reconciliação em si tem **18 testes automatizados verdes** (`ReconciliacaoDivergenciaTest`,
`ReconciliacaoEscopoTest`, `ReconciliacaoPdfPendenteTest`, `ReconciliacaoRateLimitTest`) — a lacuna
é de evidência empírica, não de corretude conhecida. O envelope segue válido até **12/09/2026**:
se a assinatura destravar antes disso, basta retomar do passo 5 do roteiro acima.

> ✅ **RESOLVIDO NA FASE 132 (2026-08-18) — ver `132-GATE.md`, seção do gate empírico #10.**
> A rodada real aconteceu contra a Clicksign de **produção**, com envelope realmente assinado
> (3 assinaturas) e o aviso automático desligado de propósito. A varredura corrigiu sozinha:
> `ContratoLiberacao id=1` com `via=reconciliacao`, e a contagem de eventos ficou inalterada
> em 25 do começo ao fim — o webhook não contribuiu com nada. **Veredito: SUFICIENTE.**
> O texto abaixo é o registro histórico de por que a Fase 130 não conseguiu fechar isto.

**Veredito do gate empírico #10: PENDENTE (à época)** — não pode ser julgado sem uma rodada real de
reconciliação para confrontar com `CLICKSIGN-SANDBOX-EMPIRICO.md` §8.

#### Estado do ambiente deixado para trás

- `.env`: `CLICKSIGN_SIG1/2/3_*` preenchidos, com os **e-mails apontados para `adm@`** e os
  endereços reais preservados em comentário ao lado de cada linha. **Restaurar antes de uso real.**
- Empresa 16: `nome_contato` corrigido para `Fulano de Tal Teste` (a Clicksign recusa nome com
  dígitos/parênteses — erro `400` no ponteiro `/data/attributes/name`) e `email_cliente` apontado
  para `adm@` (era `@example.com`, endereço inexistente que impediria assinar como cliente).
- Envelope `f010d235-…` fica **ativo e sem assinaturas** na sandbox até 12/09/2026.

---

## SC2 — Alerta em sandbox

**STATUS: 🟡 PARCIAL.** A metade técnica (gatilho, envio, gravação, cooldown) foi executada e
provada por este executor via comando real + reconsulta ao banco — nenhum `Http::fake()`/
`Notification::fake()` envolvido, é o comando de produção rodando contra o MariaDB local de
verdade. **Falta exclusivamente** a leitura humana da mensagem no sino do navegador (a
"CONFERIR A LEITURA HUMANA" do roteiro do plano) — esse pedaço não pode ser substituído por
este executor.

### O que foi executado nesta sessão (reconsultado, não por stdout)

1. Fixture: `ContratoAssinatura` id=9, empresa 16, `status='recusado'`, parado há 6 dias
   (`created_at`/`updated_at` forçados). Reconsultado ANTES:
   `ContratosPresosService::diasParado($c) = 6`, `limiarDias($c) = 5`, `estaPreso($c) = true`,
   `listar()` retorna `[9]`. `notifications` tinha `0` linhas.
2. `C:\xampp\php\php.exe artisan clicksign:alertar-presos` → saída "Alertas enviados para 1
   contrato(s), 1 destinatário(s)."
3. Reconsulta ao banco DEPOIS (não a saída de console acima):
   - `notifications` passou a ter **1 linha** — `id=60e5023c-df98-4f05-8cbb-a45658e19397`,
     `type=App\Notifications\ContratoPresoNotification`, `notifiable_id=3` (o admin de teste),
     `notifiable_type=App\Models\User`.
   - **Texto literal transcrito do campo `data` (sem edição):**
     - `titulo`: **"Empresa parada há 6 dias: Empresa Ficticia Gate 130-07"**
     - `mensagem`: **"O cliente recusou a assinatura. Fale com ele e decida entre reemitir o
       contrato ou liberar a empresa manualmente. Serviço: Gestão."**
     - `url`: `http://localhost/ecf_admin/public/admin/contratos/liberacao-manual` (nota: o
       `route()` gerou com o `APP_URL` desatualizado do `.env` — path correto real é
       `/ecf_admin/ecf_admin/public/...`, ver tabela de ambiente acima; **isso é um achado de
       configuração local, não um bug do código** — em produção `APP_URL` está correto)
     - `meta`: `{"contrato_assinatura_id":9,"company_id":16,"servico_id":6,"status":"recusado","causa":"recusado_pelo_cliente","dias_parado":6,"fonte":"rede_seguranca"}` — nenhum e-mail/CPF de signatário, confirma T-130-05-01.
   - `ContratoAssinatura::find(9)->ultimo_alerta_em` = `2026-08-13 17:27:45` (preenchido).
4. **Cooldown (D-04):** rodei o mesmo comando imediatamente de novo → saída "Nenhuma empresa
   presa fora do cooldown de alerta — nada a enviar." Reconsultei `notifications` de novo:
   **continua em 1 linha** (nenhuma duplicata).

### O que falta — só o usuário pode fazer

1. Subir o Apache local (já feito nesta sessão, `httpd.exe` de pé) e abrir
   `http://localhost/ecf_admin/ecf_admin/public/login`, logar com `gate130-admin@example.com` /
   `gate130teste`.
2. Abrir o sino de notificações e **ler visualmente** a mensagem acima renderizada de verdade na
   tela (não só no JSON do banco) — confirmar que aparece igual ao texto transcrito acima.
3. Julgar em linguagem simples: a causa está clara ("cliente recusou" ≠ "falha técnica")? Um
   leitor que não conhece Clicksign entende o que fazer a seguir?
4. Responder aqui: `aprovado` (linguagem simples confirmada) ou a lista de termos a trocar.

### Resultado

**Metade técnica: ✅ provada por reconsulta ao banco** (envio, gravação, cooldown).
**Julgamento humano da linguagem: ✅ APROVADO pelo usuário em 2026-08-14** — notificação lida na
tela, texto confirmado como correto sem pedido de alteração.

**SC2: ✅ APROVADO.**

⚠️ **Ressalva material descoberta DEPOIS deste gate:** o alerta de fato disparou e o texto está
certo, mas ele **só teria disparado uma vez**. A gravação do cooldown zerava o próprio relógio
(`dataBase()` usava `updated_at`, que o `ultimo_alerta_em` bumpava) — ver quick task `260814-cro`.
A repetição exigida pela D-04 **não estava funcionando quando este gate foi aprovado**; passou a
funcionar após a correção, agora coberta por teste. O disparo único observado aqui era, sem que
ninguém soubesse, o único que aconteceria.

---

## SC3 — Liberação manual ponta a ponta

**STATUS: ⏳ PENDENTE.** Exige interação visual no navegador (login como admin, ver a faixa
vermelha de destaque ANTES de confirmar — D-11 — e o 403 para não-admin) que este executor não
tem ferramenta para fazer. Nenhum resultado foi inventado nem simulado por HTTP direto.

### Pré-condições já confirmadas pelo executor

- Build do front-end em dia (`public/build/manifest.json` mais novo que o `.jsx` da tela).
- Rota confirmada respondendo (sem estar logado, 302 para `/login`, não 404):
  `http://localhost/ecf_admin/ecf_admin/public/admin/contratos/liberacao-manual`.
- Usuário admin de teste pronto: `gate130-admin@example.com` / `gate130teste`.
- Usuário NÃO-admin de teste pronto (para o passo do 403): `gate130-naoadmin@example.com` /
  `gate130teste`.
- Empresa de teste (id 16) + serviço Gestão (id 6) prontos.
- Contrato **recusado** já existe (id 9, o mesmo do SC2) — serve para testar a faixa vermelha
  D-11 sem precisar forçar mais nada.
- Baseline reconsultado (ANTES de qualquer liberação): `ContratoLiberacao::where('company_id',
  16)->count() = 0`; `MlbEmpresa::where('company_id', 16)->count() = 0`.

### Roteiro (URL real, não a do `.env`)

1. Logar como `gate130-admin@example.com` em
   `http://localhost/ecf_admin/ecf_admin/public/login` e abrir
   `http://localhost/ecf_admin/ecf_admin/public/admin/contratos/liberacao-manual`.
2. Confirmar que a lista mostra "Empresa Ficticia Gate 130-07" / Gestão / 6 dias parado / causa
   "O cliente recusou a assinatura..." em português simples.
3. Selecionar esse contrato (`recusado`) e conferir que a faixa vermelha de aviso aparece ANTES
   do botão de confirmar (D-11).
4. Tentar confirmar SEM preencher o motivo/detalhe → deve recusar (validação `motivo_detalhe`
   `min:5`).
5. Preencher motivo (qualquer um dos 4: "O aviso automático da Clicksign não chegou" /
   "O cliente assinou fora do sistema" / "Decisão comercial" / "Outro motivo") + detalhe, e
   confirmar.
6. Reconsultar por RECONSULTA AO BANCO (não a mensagem de sucesso da tela):
   ```
   C:\xampp\php\php.exe artisan tinker --execute="
   \$cl = App\Models\ContratoLiberacao::where('company_id',16)->where('servico_id',6)->first();
   echo \$cl->via.' autor='.\$cl->liberado_por_user_id.' slug='.\$cl->motivo_slug.' motivo='.\$cl->motivo.PHP_EOL;
   echo 'MlbEmpresa: '.App\Models\MlbEmpresa::where('company_id',16)->count().PHP_EOL;
   "
   ```
   Esperado: `via='manual'`, `liberado_por_user_id` = id do admin de teste (3), `motivo_slug` =
   o escolhido, `motivo` = o texto digitado.
7. Repetir a mesma liberação (mesma empresa/serviço) e reconsultar de novo — deve continuar
   **1** linha em `contrato_liberacoes` (idempotência).
8. Deslogar, logar como `gate130-naoadmin@example.com`, tentar abrir a mesma URL → confirmar
   **403**.
9. Registrar aqui o resultado de cada passo.

### Resultado

**STATUS: ✅ APROVADO no essencial (2026-08-14), com 3 sub-passos de tela não reportados.**

O critério literal do ROADMAP — *"um admin libera uma empresa ao operacional preenchendo motivo
(campo obrigatório); a liberação registra quem liberou e por quê, e usa o mesmo
`EmpresaOperacionalRouter::liberarEmpresa()`"* — está **provado por reconsulta ao banco**:

| Campo | Valor medido |
|---|---|
| linhas em `contrato_liberacoes` (empresa 16) | **1** |
| `via` | `manual` |
| `liberado_por_user_id` | **3** (= `gate130-admin@example.com`) |
| `motivo_slug` | `decisao_comercial` |
| `motivo` (detalhe obrigatório) | `"apenas fazendo teste"` |
| `created_at` | 2026-08-14 09:25:39 |
| `MlbEmpresa` (empresa 16) | **0 — CORRETO, não é falha** |

**Por que `MlbEmpresa = 0` é o comportamento certo:** `EmpresaOperacionalRouter::criarFicha()` só
cria ficha para os tipos `polos`, `assessoria` e `incubadora`. O serviço 6 ("Gestão") é do setor
`performance`, que não é nenhum dos três — nada deveria ser criado. Registrado aqui porque à
primeira leitura parece uma liberação que não liberou nada.

#### Bloqueio encontrado durante este gate (corrigido antes de concluir)

Na primeira tentativa a tela apareceu **VAZIA** ("nenhuma empresa no momento"), com um contrato de
7 dias parado no banco. Não era fixture: era o bug do relógio do alerta (quick task
`260814-cro`) — o alerta de 13/08 tinha bumpado `updated_at` e zerado `diasParado()`. Depois da
correção, `listar()` voltou de 0 para 1 item e o gate pôde ser executado.

#### Sub-passos de tela NÃO reportados pelo usuário (não são falhas — são não-observados)

Estes dependem de observação visual e **não foram confirmados**; não devem ser tratados como
aprovados:

- [ ] Faixa vermelha de estado real aparecendo ANTES de confirmar (D-11)
- [ ] Recusa da confirmação sem `motivo_detalhe` preenchido (validação `min:5`)
- [ ] `403` para `gate130-naoadmin@example.com` na mesma URL

Os três têm cobertura automatizada (`LiberacaoManualEstadoRealTest`, `LiberacaoManualTest`), então
a lacuna é de observação humana, não de corretude conhecida.

---

## Resumo para o usuário

**Atualizado em 2026-08-14, ao fim da rodada de gates.**

| SC | Status | O que falta |
|---|---|---|
| SC1 (reconciliação) | ⛔ BLOQUEADO | Assinatura impossível na sandbox: o painel não conclui (3 `signature_started`, 0 `sign`) e a sandbox não envia e-mail — e a v3 não expõe link de assinatura. `.env` já preenchido, envelope `f010d235-…` criado e ativado, válido até 12/09/2026 |
| Gate empírico #10 | ⏳ PENDENTE | Depende do SC1 acontecer de verdade |
| SC2 (alerta) | ✅ APROVADO | Nada — disparo provado por reconsulta ao banco, linguagem confirmada pelo usuário. Com a ressalva de que a repetição da D-04 só passou a funcionar após a quick task `260814-cro` |
| SC3 (liberação manual) | ✅ APROVADO (essencial) | O critério do ROADMAP está provado por reconsulta ao banco. 3 sub-passos de tela (faixa vermelha, validação sem motivo, 403 do não-admin) não foram observados pelo usuário — têm cobertura automatizada |

**Resultado líquido: 2 de 3 Success Criteria humanos aprovados.** O SC1 depende da sandbox da
Clicksign, não do código deste projeto.

**Achado mais valioso desta rodada não estava em nenhum roteiro:** o gate do SC3 encontrou um bug
real na própria Fase 130 — o alerta zerava o próprio relógio e nunca repetiria (quick task
`260814-cro`, 3 testes novos). Só apareceu porque alguém abriu a tela um dia depois do alerta ter
disparado. Nenhum teste automatizado o pegaria: o cooldown provava que o alerta não repete cedo
demais, e ninguém provava que ele ainda repete depois.

**MariaDB e Apache locais foram deixados DE PÉ** ao final desta sessão (ambos precisam estar de
pé para os 3 roteiros acima) — nenhum processo foi encerrado.

Nenhum token/secret/header de autorização foi colado neste documento. Nenhum e-mail/CPF real de
cliente ou signatário Clicksign foi usado — só as fixtures `@example.com` descritas acima.
