# Requirements: ECF Admin — Milestone v23.0

**Defined:** 2026-09-01
**Milestone:** v23.0 — Fluxo de Entrada de Novas Empresas
**Core Value:** Um único cadastro de empresa atravessa HubSpot → Comercial → Administrativo → Coordenação → Onboarding → Em operação, com etapa e status explícitos, checklist obrigatório por etapa, travas que impedem avanço incompleto, e histórico datado por evento para medir SLA.
**Especificação canônica:** `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` — transcrição fiel do PDF *Fluxo de Entrada de Novas Empresas* (ECF Consultoria, 01/09/2026) + levantamento técnico contra `origin/main` `695711f5`.
**Pesquisa:** não executada — o PDF **é** a especificação funcional e o levantamento contra o código foi feito na abertura. Ver D0.

---

## Decisões travadas (LOCKED — não reperguntar)

### D0 — Sem pesquisa de domínio nesta abertura
Decisão do usuário (2026-09-01). O PDF já é a especificação funcional escrita pela própria ECF, e o cruzamento com o código de `origin/main` foi feito antes da abertura. Pesquisar "como funciona onboarding de clientes" responderia o que o documento já responde.

### D1 — Os 9 status moram em coluna NOVA `companies.etapa`
Decisão do usuário (2026-09-01). `companies.status` **fica como está** (string livre, default `'ativo'`, semântica de contrato ativo/inativo) e nasce `companies.etapa` com os 9 valores do §10.

Motivo: reusar `status` seria migration destrutiva em tabela com dado de produção (~500 registros), exigindo backfill e auditoria de **todo** consumidor atual da coluna. A coluna nova é aditiva e reversível.

**Consequência a resolver no planejamento:** dois campos de nome parecido convivem. A documentação de schema precisa dizer, em uma linha, qual é qual — senão a próxima sessão escolhe o errado.

### D2 — "Em operação" passa a ser a etapa 9; o cálculo atual vira fallback legado
Decisão do usuário (2026-09-01). Hoje `em_operacao` é **derivado** em `CompanyController.php:179` (`tem analista OU tem estrategista`). Passa a ser a etapa 9, atingida só depois do onboarding concluído.

As empresas já existentes entram **direto na etapa 9** no backfill; o cálculo antigo permanece como fallback para quem não tiver etapa.

**Consequência aceita pelo usuário:** a aba "Empresas" de `/companies` esconde quem não está `em_operacao`, então ela passa a listar coisa diferente — de propósito. Empresa em entrada deixa de aparecer ali e aparece na etapa correspondente.

### D3 — O checklist gera o que já tem gerador; o resto é marcação manual
Decisão do usuário (2026-09-01).

**Gera e marca sozinho** (o sistema já sabe fazer): link ADMA (`companies.ml_link_url`), link/conexão com o sistema ECF (`OnboardingLinkService::paraEmpresa`), Grant da consultoria (`company_grants` / `SyncGrantsFromSftp`).

**Marcação manual** (quem marcou e quando): criar grupo de WhatsApp, criar/definir e-mail colaborador, enviar a mensagem no grupo.

**Reflete estado externo, não duplica**: os quatro itens do grupo Contrato leem o estado real do Clicksign entregue na v22.0.

Motivo: criar grupo de WhatsApp de verdade exige integração nova com o Digisac, e provisionar e-mail é outro sistema. Fica em Future Requirements.

### D4 — O destino do §6 é o marketplace do contrato, não "Mercado Livre" literal
Decisão do usuário (2026-09-01). O PDF escreve "passa para **Mercado Livre** → Empresas". A implementação resolve o destino por `company_marketplaces` (modelo N:N da v13.0).

Hoje isso produz Mercado Livre em 100% dos casos (126 meli / 0 shopee / 0 amazon), então **o comportamento visível é idêntico ao do PDF** — só não nasce amarrado a um marketplace.

### D5 — HubSpot e Clicksign se INTEGRAM, nunca se reconstroem
Travada na abertura a partir do levantamento técnico. `HubspotWebhookController` + `HubspotDealHandoffService` já implementam o §1. As fases 126/127/129/132 da v22.0 já implementam o grupo Contrato do §3, e a Fase 131 já entregou a tela administrativa de contratos.

Qualquer plano que proponha criar cliente HTTP de assinatura, webhook de contrato ou ingestão de deal ganho está errado por construção.

### D6 — Pendência é sinalizador paralelo, nunca status principal
Do próprio PDF (§10). Exemplo do documento: `Status = Aguardando Administrativo` **e** `Pendência = Contrato não assinado`.

Precedente direto no projeto: o flag `problema` do Painel Polos, que deliberadamente **não** tira a empresa da meta. Ver `.planning/learnings/painel-polos-status-e-meta.md` antes de planejar a fase da máquina de estados.

---

## v23.0 Requirements

Cada requirement mapeia para exatamente uma phase no ROADMAP.md.

### ETAPA — Máquina de estados dos 9 status (§10, §11)

- [x] **ETAPA-01**: `companies.etapa` existe com os 9 valores do §10 na ordem definida, aditiva a `companies.status`, com constante de domínio no model `Company`
- [ ] **ETAPA-02**: Empresas já existentes recebem etapa no backfill — as em operação entram na etapa 9 (D2), preservando o comportamento atual das telas que ainda leem o derivado
- [x] **ETAPA-03**: Um serviço único decide se uma transição de etapa é válida; nenhum controller muda etapa na mão
- [x] **ETAPA-04**: Pendência é campo paralelo à etapa — uma empresa pode estar em qualquer etapa **e** ter pendência declarada, sem que a pendência mude a etapa (D6)
- [ ] **ETAPA-05**: Usuário pode filtrar as listagens por etapa e por existência de pendência
- [x] **ETAPA-06**: Tentativa de transição inválida é recusada com mensagem que diz **qual** requisito falta, não um 403 genérico

### COMERC — Área Comercial, entrada da empresa (§1, §2)

- [ ] **COMERC-01**: Empresa criada pelo webhook de venda ganha nasce na etapa `Aguardando Administrativo` sem cadastro manual
- [ ] **COMERC-02**: A listagem de Empresas Ganhas exibe os 8 campos mínimos do §2 (nome, serviço contratado, setor/segmento, origem da venda, responsável comercial e data da venda, informações principais do cliente, status do contrato e pendências, demais dados comerciais do HubSpot)
- [ ] **COMERC-03**: A empresa permanece visível na Área Comercial até o processo administrativo concluir

### ADMIN — Checklist administrativo e trava de finalização (§3, §5)

- [ ] **ADMIN-01**: Dentro do cadastro da empresa existe checklist visual com os 9 itens obrigatórios do §5, agrupados em Contrato / Estrutura / Comunicação
- [ ] **ADMIN-02**: Os quatro itens do grupo Contrato refletem o estado real do envelope Clicksign entregue na v22.0 — sem marcação manual paralela e sem reimplementar assinatura (D5)
- [ ] **ADMIN-03**: Link ADMA, link de conexão com o sistema ECF e Grant da consultoria são gerados pelo próprio checklist, que marca o item ao gerar (D3)
- [ ] **ADMIN-04**: Grupo de WhatsApp, e-mail colaborador e envio da mensagem são marcados manualmente, registrando quem marcou e quando (D3)
- [ ] **ADMIN-05**: O botão FINALIZAR ENTRADA ADMINISTRATIVA só habilita quando todos os itens obrigatórios estão concluídos **e** o contrato está assinado
- [ ] **ADMIN-06**: Finalizar move a empresa para a etapa `Aguardando Distribuição` e para o módulo do marketplace do contrato, preservando o mesmo cadastro (D4)

### COMUNIC — Mensagem de boas-vindas (§4)

- [ ] **COMUNIC-01**: O sistema monta a mensagem de boas-vindas preenchida com os dados da empresa, pronta para o Administrativo copiar
- [ ] **COMUNIC-02**: A mensagem contém os 6 blocos do §4 — boas-vindas, e-mail colaborador, link da ADMA, link/Grant da consultoria, link de conexão com o sistema e orientações das conexões que o cliente precisa fazer
- [ ] **COMUNIC-03**: A mensagem funciona para qualquer serviço, não só Polos, e o texto padrão é editável sem deploy

### DISTRIB — Distribuição pela Coordenação (§7)

- [ ] **DISTRIB-01**: A Coordenação vê a fila de empresas que concluíram o Administrativo, têm contrato assinado e ainda não têm responsáveis operacionais
- [ ] **DISTRIB-02**: Os seletores de analista e de estrategista listam apenas colaborador ativo e habilitado para a função
- [ ] **DISTRIB-03**: Confirmar distribuição registra analista, estrategista, **o coordenador que distribuiu**, data e horário
- [ ] **DISTRIB-04**: Confirmar distribuição move a empresa para a etapa `Aguardando Onboarding`

### RESP — Chegada ao analista e ao estrategista (§8)

- [ ] **RESP-01**: Depois da distribuição, a empresa aparece automaticamente em Minhas Empresas dos dois responsáveis
- [ ] **RESP-02**: A empresa aparece com destaque visual de novo cliente e a informação de onboarding pendente

### ONBRD — Onboarding dentro da máquina de estados (§9)

- [ ] **ONBRD-01**: Iniciar o onboarding move a empresa de `Aguardando Onboarding` para `Onboarding em andamento`
- [ ] **ONBRD-02**: Concluir todas as atividades previstas move para `Onboarding concluído` e, na sequência, para `Em operação`
- [ ] **ONBRD-03**: O checklist de onboarding continua variando por serviço contratado, reusando a régua existente (`DefinicaoOnboarding`) sem recriá-la
- [ ] **ONBRD-04**: Empresa sem analista **e** sem estrategista definidos não consegue iniciar o onboarding

### HIST — Histórico e rastreabilidade (§12)

- [ ] **HIST-01**: Cada empresa tem uma timeline de entrada com data, horário, ação e usuário responsável por evento
- [ ] **HIST-02**: A timeline registra, no mínimo, os 7 eventos do exemplo do §12 — recebida do HubSpot, contrato enviado, contrato assinado, administrativo concluído, analista definido, estrategista definido, enviada para onboarding
- [ ] **HIST-03**: É possível ler quanto tempo a empresa passou em cada etapa, para medir SLA e identificar gargalo

---

## Cobertura das travas do §11

As 7 regras de negócio do PDF não têm REQ-ID próprio — cada uma é garantida por um requirement acima. Mapeamento explícito para que nenhuma se perca:

| §11 | Regra | Garantida por |
|-----|-------|---------------|
| 1 | Não avança para Coordenação sem concluir o Administrativo | ADMIN-05, ADMIN-06 |
| 2 | Administrativo não finaliza sem contrato assinado | ADMIN-05 |
| 3 | Todos os itens obrigatórios concluídos antes do avanço | ADMIN-01, ADMIN-05 |
| 4 | Não inicia onboarding sem responsáveis definidos | ONBRD-04 |
| 5 | A distribuição é realizada pela Coordenação | DISTRIB-01, DISTRIB-03 |
| 6 | Analista e Estrategista recebem a empresa automaticamente | RESP-01 |
| 7 | Todos os avanços e alterações relevantes ficam no histórico | HIST-01, HIST-02 |

E a trava estrutural que atravessa tudo — **cadastro único**, do "Princípio estrutural" do PDF — é garantida por ETAPA-01 (a etapa é coluna da própria `companies`) e ADMIN-06 (avançar não cria registro novo).

---

## Future Requirements

Reconhecidos, fora desta milestone:

- **Criar o grupo de WhatsApp automaticamente** via API do Digisac — hoje só existe `companies.digisac_group_mapping`, que mapeia grupo já existente (D3)
- **Provisionar o e-mail colaborador** automaticamente — depende de outro sistema (D3)
- **Enviar a mensagem de boas-vindas pelo sistema** em vez de copiar e colar — o PDF pede explicitamente "pronta para o Administrativo copiar e enviar no grupo"
- **Painel de SLA agregado** (tempo médio por etapa, ranking de gargalo) — HIST-03 entrega o dado por empresa; o painel é produto separado
- **A segunda parte da especificação funcional** — o PDF se declara "a primeira parte"

## Out of Scope

- **Trello** — excluído explicitamente pelo próprio PDF ("Fora do escopo: o Trello não integra este fluxo")
- **Reconstruir a ingestão do HubSpot** — já existe e funciona (D5)
- **Reconstruir assinatura de contrato** — entregue pela v22.0 (D5)
- **Reescrever a régua de onboarding por serviço** — `DefinicaoOnboarding` está na VERSAO 17 e é reusada (ONBRD-03)
- **Fechar a v22.0** — a Fase 133 e o plano `133-05` seguem abertos e são trabalho daquela milestone, não desta

---

## Traceability

| REQ-ID | Fase |
|--------|------|
| ETAPA-01 | Fase 137 |
| ETAPA-02 | Fase 137 |
| ETAPA-03 | Fase 137 |
| ETAPA-04 | Fase 137 |
| ETAPA-05 | Fase 137 |
| ETAPA-06 | Fase 137 |
| COMERC-01 | Fase 138 |
| COMERC-02 | Fase 138 |
| COMERC-03 | Fase 138 |
| ADMIN-01 | Fase 139 |
| ADMIN-02 | Fase 139 |
| ADMIN-03 | Fase 139 |
| ADMIN-04 | Fase 139 |
| ADMIN-05 | Fase 139 |
| ADMIN-06 | Fase 139 |
| COMUNIC-01 | Fase 140 |
| COMUNIC-02 | Fase 140 |
| COMUNIC-03 | Fase 140 |
| DISTRIB-01 | Fase 141 |
| DISTRIB-02 | Fase 141 |
| DISTRIB-03 | Fase 141 |
| DISTRIB-04 | Fase 141 |
| RESP-01 | Fase 141 |
| RESP-02 | Fase 141 |
| ONBRD-01 | Fase 142 |
| ONBRD-02 | Fase 142 |
| ONBRD-03 | Fase 142 |
| ONBRD-04 | Fase 142 |
| HIST-01 | Fase 143 |
| HIST-02 | Fase 143 |
| HIST-03 | Fase 143 |

**Cobertura:** 31/31 requirements mapeados. Nenhum órfão, nenhuma duplicidade — cada REQ-ID em exatamente uma fase.
