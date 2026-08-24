# Acesso por token — o que ele NÃO protege

Escrito em 24/08/2026, depois de uma auditoria de 13 agentes sobre o Portal do
Cliente. Leia antes de criar QUALQUER rota pública por token.

## As 4 falhas confirmadas em 24/08/2026 (estado: NÃO corrigidas)

### 1. `marcarFeitoPorChave()` não filtra `dono` — EXPLORADA por execução

`OnboardingLinkService::marcarFeitoPorChave()` filtra `chave` + `company_id` +
`emAndamento()`. A régua de LEITURA (`passosDoCliente()`, :71) filtra
`dono = cliente`. **A de escrita não.**

Reproduzido: `PATCH /portal-cliente/{token}/onboarding/passo` com
`{"chave":"adman_preenchimento_interno"}` levou um passo `dono=interno` de
`bloqueado` para `concluido`. Sem sessão, sem CSRF.

Dois agravantes: fura o gate de dependências (o passo estava bloqueado, e :445 só
pula `STATUS_CONCLUIDO`); e como `$ehDeclaracao = $primeiro->auto_fonte !== null`
dá `false`, **não grava `declarado_pelo_cliente` nem `declarado_ip`** — no painel
interno fica idêntico a um consultor tendo marcado à mão.

Alvos hoje: só `adman_preenchimento_interno` e `reuniao_realizada` (os únicos
`dono=interno` com `auto_fonte=null`). `desmarcarPorChave()` (:250) tem o espelho
exato do defeito.

**A lição geral: toda régua de leitura precisa de uma régua de ESCRITA
equivalente.** Filtrar na listagem e esquecer no endpoint de ação é o padrão de
falha mais fácil de cometer aqui.

### 2. `routes/web.php:129` aponta para método inexistente

`implementacao/{token}/publicador/frete` → `salvarFretePublicador`, que não existe
em `MlbImplementacaoController`. Qualquer PATCH ali é 500 público.

### 3. `/publicador` usa o MESMO token do cliente

`MlbImplementacaoController::publicador()` faz `where('token', $token)`. Basta
acrescentar `/publicador` na URL do cliente para ver margem, lucro e a
metodologia de precificação da ECF. Falha de MODELAGEM: um segredo servindo dois
níveis de confidencialidade.

### 4. Credencial de ERP de terceiro em claro

`MlbImplementacao` casta `'dados' => 'array'`, não `encrypted:array`. O formulário
público pede "Login, senha ou link de acesso ao ERP"
(`ImplementacaoPublica.jsx:1760`). 467 tokens vivos. Não é dado nosso — é
responsabilidade sobre o sistema do cliente.

## A escala, medida em produção (24/08/2026)

| | |
|---|---|
| Empresas ativas | 196 |
| Empresas com link de Portal | **6** |
| Tokens de `/implementacao` (Polos) | **467** |
| PPAs com `workspace_token` | 10 |
| `onboarding_contatos` | **0** |
| `companies.email_cliente` | 88 |

**O Portal não é a maior exposição — o Polos é.** E migrar o Portal agora custa 6
convites; depois pode custar 196.

## O que o token protege bem (não mexer)

`Str::random(48)` = ~286 bits. Não é enumerável nem força-brutável. **O problema
nunca foi a força do segredo** — é ele ser permanente, compartilhado, sem dono e
viajar na URL (entra em histórico, Referer, log de proxy e prévia de link do
WhatsApp).

Corolário contraintuitivo: **código OTP de 6 dígitos INTRODUZ uma classe de
ataque que hoje não existe.** Se um dia for adotado, precisa de lockout e rate
limit por identidade E por IP.

## Por que SMS/WhatsApp foram descartados

`.env` de produção tem **zero** provedores (sem Twilio/Zenvia/Vonage). Existe
`app/Services/Digisac/` no repositório, mas desligado em produção. E-mail já
funciona. Qualquer proposta com SMS/WhatsApp carrega contrato + integração +
aprovação de template.

## Recomendação registrada

Híbrida: o link vira CONVITE e identificação, nunca credencial; a pessoa
autentica por magic link de e-mail; o servidor valida vínculo usuário↔empresa em
toda rota. Documento completo da auditoria (diagnóstico linha a linha,
comparação A–G, modelagem, middleware, fases) publicado em 24/08/2026.

**Fase 0 (2–3 dias, custo zero, sem decisão de produto):** corrigir as 4 falhas
acima + throttle nos endpoints de escrita + fechar o vazamento de rotas do Ziggy.
Vale mesmo que a arquitetura nova demore meses.
