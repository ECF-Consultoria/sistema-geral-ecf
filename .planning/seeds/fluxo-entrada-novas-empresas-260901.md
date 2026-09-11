# Fluxo de Entrada de Novas Empresas — Especificação funcional

> **Fonte:** PDF `Fluxo_de_Entrada_de_Novas_Empresas (1).pdf`, ECF Consultoria,
> preparado em 01/09/2026. Transcrição fiel feita em 01/09/2026 para servir de
> insumo à milestone. **Este arquivo é a fonte de verdade do escopo** — não
> parafrasear a partir de resumos.
>
> O próprio documento se declara **"a primeira parte da especificação funcional"**:
> serve de base para detalhamento técnico, prototipação das telas e critérios de
> aceite. Há partes que virão depois.

## Objetivo

Automatizar e controlar a entrada de clientes desde a venda ganha no HubSpot até a
conclusão do onboarding, com responsabilidades claras, checklists obrigatórios,
rastreabilidade e avanço condicionado à conclusão de cada etapa.

```
HubSpot → Comercial → Administrativo → Coordenação → Responsáveis → Onboarding
```

## Princípio estrutural

Todo o processo deve utilizar **um único cadastro de empresa**. O que muda ao longo
do fluxo é a etapa atual, o status, os responsáveis e as permissões de visualização.
Isso evita duplicidade e simplifica relatórios e integrações.

---

## 1. HubSpot — venda ganha

O processo começa no pipeline comercial do HubSpot. O time Comercial preenche os
dados obrigatórios da venda e do cliente e movimenta o negócio para **GANHO**.

**Ação automática:** ao identificar a mudança para GANHO, a integração deve consumir
as informações e criar a empresa em **Área Comercial → Empresas Ganhas**, sem novo
cadastro manual.

**Status inicial:** `Aguardando Administrativo`

## 2. Área Comercial — entrada da empresa

A tela deve oferecer uma visão rápida das vendas concluídas que ainda precisam passar
pela preparação administrativa.

Informações mínimas exibidas:

- Nome da empresa
- Serviço contratado
- Setor ou segmento
- Origem da venda
- Responsável comercial e data da venda
- Informações principais do cliente
- Status do contrato e existência de pendências
- Demais informações comerciais preenchidas no HubSpot

**A empresa permanece nesta etapa até a conclusão de todo o processo administrativo.**

## 3. Etapa Administrativa

O Administrativo prepara o cliente para a operação. Todos os itens abaixo devem ser
controlados por **checklist visual dentro do cadastro da empresa**.

| Grupo | Atividades obrigatórias |
|---|---|
| **Contrato** | Revisar o contrato; enviar ao cliente; acompanhar a assinatura; confirmar contrato assinado. |
| **Estrutura** | Criar grupo de WhatsApp; criar ou definir e-mail colaborador; gerar link de conexão com a ADMA; gerar Grant da consultoria; gerar link/conexão com o sistema ECF. |
| **Comunicação** | Gerar mensagem de boas-vindas; inserir todos os links necessários; enviar a mensagem no grupo de WhatsApp. |

**Fora do escopo:** o Trello não integra este fluxo.

## 4. Mensagem de boas-vindas

O sistema **pode** disponibilizar uma mensagem padrão preenchida com os dados do
cliente, pronta para o Administrativo copiar e enviar no grupo. Conteúdo:

- Boas-vindas ao cliente
- E-mail colaborador
- Link da ADMA
- Link/Grant da consultoria
- Link para conexão com o sistema
- Orientações sobre as conexões que o cliente precisa realizar

## 5. Controle de conclusão do Administrativo

Enquanto houver item obrigatório pendente, a empresa permanece em
**Aguardando Administrativo**.

| Atividade | Controle |
|---|---|
| Contrato revisado | Pendente / Concluído |
| Contrato enviado | Pendente / Concluído |
| Contrato assinado | Pendente / Concluído |
| Grupo de WhatsApp criado | Pendente / Concluído |
| E-mail colaborador criado | Pendente / Concluído |
| Link ADMA gerado | Pendente / Concluído |
| Grant consultoria gerado | Pendente / Concluído |
| Conexão com o sistema gerada | Pendente / Concluído |
| Boas-vindas enviada | Pendente / Concluído |

**Botão: FINALIZAR ENTRADA ADMINISTRATIVA** — só deve ser habilitado quando **todos**
os requisitos obrigatórios estiverem concluídos **e** o contrato estiver assinado.

## 6. Mudança automática para Mercado Livre

Ao finalizar, a empresa deixa a pendência Comercial/Administrativa e passa para
**Mercado Livre → Empresas**, preservando todos os dados do mesmo cadastro.

**Novo status:** `Aguardando Distribuição`

## 7. Coordenação — distribuição

A Coordenação deve visualizar as empresas que concluíram o Administrativo, possuem
contrato assinado e ainda não têm responsáveis operacionais definidos.

Campos de distribuição:

- **Analista responsável:** selecionar colaborador ativo e habilitado para a função
- **Estrategista responsável:** selecionar colaborador ativo e habilitado para a função

**Botão: CONFIRMAR DISTRIBUIÇÃO** — ao confirmar, o sistema registra: analista,
estrategista, **coordenador que realizou a distribuição**, data e horário.

**Novo status:** `Aguardando Onboarding`

## 8. Entrada para Analista e Estrategista

Após a distribuição, a mesma empresa aparece automaticamente em **Minhas Empresas**
dos responsáveis selecionados, com destaque visual de novo cliente e onboarding
pendente.

> EMPRESA XYZ — Status: Novo cliente — Onboarding pendente · selo **NOVA**

## 9. Onboarding e operação

O checklist de onboarding deve **variar conforme o serviço contratado**. O analista
e/ou estrategista acessa a empresa, inicia o processo e conclui todas as atividades
previstas.

1. Onboarding pendente
2. Onboarding em andamento
3. Onboarding concluído
4. Em operação

### Fluxo operacional resumido

1. Venda marcada como GANHA no HubSpot
2. Integração cria a empresa na Área Comercial
3. Administrativo conclui contrato, estrutura e boas-vindas
4. Empresa avança para Mercado Livre → Empresas
5. Coordenação define Analista e Estrategista
6. Responsáveis recebem novo cliente
7. Onboarding pendente → em andamento → concluído
8. Empresa entra em operação normal

## 10. Status padronizados

Os status abaixo permitem filtros, indicadores e automações sem criar variações
desnecessárias.

| Ordem | Status |
|---|---|
| 1 | Aguardando Administrativo |
| 2 | Administrativo em andamento |
| 3 | Aguardando assinatura do contrato |
| 4 | Administrativo concluído |
| 5 | Aguardando Distribuição |
| 6 | Aguardando Onboarding |
| 7 | Onboarding em andamento |
| 8 | Onboarding concluído |
| 9 | Em operação |

**Pendência deve ser um sinalizador paralelo, não um status principal.**
Exemplo: Status = Aguardando Administrativo; Pendência = Contrato não assinado.

## 11. Regras de negócio e travas

1. A empresa não pode avançar para Coordenação sem concluir o Administrativo.
2. O Administrativo não pode finalizar a etapa sem o contrato assinado.
3. Todos os itens obrigatórios do checklist precisam estar concluídos antes do avanço.
4. A empresa não pode iniciar onboarding sem responsáveis definidos.
5. A distribuição deve ser realizada pela Coordenação.
6. Analista e Estrategista recebem automaticamente a empresa após a distribuição.
7. Todos os avanços e alterações relevantes devem ser registrados no histórico.

**Resultado esperado:** HubSpot cria a empresa → Administrativo prepara → Coordenação
distribui → Operação executa. O sistema controla o avanço, preserva o cadastro único
e deixa claro quem deve agir em cada etapa.

## 12. Histórico e rastreabilidade

O histórico será essencial para **medir SLA, cobrar responsáveis e identificar
gargalos**. Cada evento deve registrar data, horário, ação e usuário responsável.

Exemplo:

```
01/09/2026 09:43 — Empresa recebida do HubSpot
01/09/2026 10:12 — Contrato enviado por João
01/09/2026 14:28 — Contrato assinado
02/09/2026 08:40 — Administrativo concluído por Maria
02/09/2026 09:10 — Analista: Ana definido por Luiz
02/09/2026 09:10 — Estrategista: Felipe definido por Luiz
02/09/2026 09:11 — Empresa enviada para onboarding
```

**Modelo conceitual:** `EMPRESA + ETAPA ATUAL + STATUS + RESPONSÁVEIS + PERMISSÕES`

---

# Levantamento técnico prévio (01/09/2026) — não faz parte do PDF

Cruzamento da especificação com o código de `origin/main` em `695711f5`. Serve para o
planejamento **não propor reconstruir o que já existe**.

## Já construído

| PDF | Onde |
|---|---|
| §1 HubSpot venda ganha → cria empresa | `app/Http/Controllers/Api/HubspotWebhookController.php`, `app/Services/Hubspot/HubspotDealHandoffService.php`, `HubspotCompanyMatcher`, `HubspotValueResolver`, model `HubspotEvento`, campos hubspot em `companies` |
| §2 Área Comercial | `resources/js/Pages/Comercial/EmpresasListagem.jsx` (+ `NovaEmpresa`, `AtribuirServico`), `ComercialController` |
| §3 grupo **Contrato** inteiro | Clicksign — fases 126/127/129/132 já executadas: `ClicksignClient`, `ContratoClicksignService`, `ClicksignWebhookController`, `ProcessarEventoClicksignJob`, `ReconciliarContratoClicksignJob`, comandos `ClicksignReconciliar`/`ClicksignVerificarAssinatura`/`ClicksignAlertarPresos` |
| §9 checklist varia por serviço | `app/Support/Onboarding/DefinicaoOnboarding.php` (`VERSAO` 17), motor `OnboardingEngineService` |
| §7 os dois responsáveis (analista + estrategista) | schema já alterado na Fase 135 / Etapa 1 do onboarding em `/companies` |
| §12 base de histórico | `spatie/laravel-activitylog` já aplicado a `Company`, `User` e demais models principais |

## Peças soltas a integrar (existem, mas não como checklist do §3/§5)

- **Grupo de WhatsApp:** `companies.digisac_group_mapping`
- **E-mail colaborador:** por empresa, não há mais padrão global (commits `57cab2cd`, `9ac89d87`)
- **Link ADMA:** `companies.ml_link_url` / `ml_link_generated_at`
- **Grant da consultoria:** `SyncGrantsFromSftp`, `company_grants`
- **Link/conexão com o sistema ECF:** `OnboardingLinkService::paraEmpresa` — o link é **por empresa**, não por onboarding
- **Mensagem de boas-vindas:** existe hoje **só para Polos**, e está **salva no banco** (Padrões Globais) — editar a constante em código não muda produção

## Não existe

- §5 checklist administrativo + botão FINALIZAR ENTRADA ADMINISTRATIVA
- §7 tela de distribuição da Coordenação e registro de **quem** distribuiu
- §10 os 9 status padronizados
- §10 pendência como sinalizador paralelo ao status

## Conflitos com o sistema atual — decidir antes de planejar

1. **`em_operacao` hoje é derivado, não status.** `CompanyController.php:179` calcula
   `em_operacao = (tem analista Performance) OU (tem estrategista Performance)`. O PDF
   quer "Em operação" como **status 9**, atingido só depois do onboarding concluído.
   A aba "Empresas" de `/companies` **esconde quem não está `em_operacao`** — mudar a
   semântica muda o que aquela tela lista.

2. **`companies.status` já existe com outro significado.** Migration
   `2026_05_25_100001_add_status_to_companies` criou a coluna como string livre com
   default `'ativo'` e backfill em produção. Reaproveitá-la para os 9 status é
   **migration em tabela com dado em produção → fase GSD obrigatória** pelo `CLAUDE.md`.
   A alternativa é coluna nova (`etapa`) convivendo com `status`.

3. **§6 diz "passa para Mercado Livre → Empresas"**, mas a milestone v13.0 tornou o
   sistema multi-marketplace (`company_marketplaces`, N:N, 126 empresas meli / 0 shopee
   / 0 amazon). Decidir se o destino é ML literal ou "o marketplace do contrato".

4. **A Fase 135 (Onboarding geral por serviço, em `/companies`) está com código
   completo aguardando gate humano**, e a régua já evoluiu até v17. O onboarding em si
   **não se joga fora** — o redesenho é de tudo que vem *antes* dele. Ver
   `.planning/learnings/onboarding-regua-congelada.md`: mudar a definição não alcança
   quem já roda; existe o comando `onboarding:sincronizar-dependencias`.

## Leitura obrigatória antes de planejar

- `.planning/learnings/onboarding-regua-congelada.md` — as 3 dimensões de congelamento
- `.planning/learnings/painel-polos-status-e-meta.md` — precedente de "flag paralelo não
  vira status principal", exatamente o padrão que o §10 pede
- `.planning/learnings/onboarding-cockpit-e-o-sc11.md`
- `.planning/learnings/portal-do-cliente.md`
