# Phase 151: Área Comercial conectada à etapa (v23.0) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisões estão no `151-CONTEXT.md` — este log preserva as alternativas consideradas.

**Date:** 2026-09-02
**Phase:** 138-Área Comercial conectada à etapa (v23.0)
**Areas discussed:** Responsável comercial não existe · Onde mora a lista e quando a empresa sai · Qual pendência e qual setor · Portas de entrada na etapa 1 · (extra) Permissões, destino de `admin.empresas`, owner retroativo

---

## Seleção inicial de áreas

Quatro áreas cinzentas apresentadas, **todas as quatro selecionadas** pelo usuário.

---

## Responsável comercial não existe

| Opção | Descrição | Selected |
|---|---|---|
| Buscar do HubSpot | `hubspot_owner_id` nas props do deal + `GET /crm/v3/owners/{id}` com cache | ✓ |
| Coluna vazia por ora | Mostra "—" e COMERC-02 fica parcialmente cumprido | |
| Amarrar a um usuário ECF | Usar o usuário ECF vinculado — seria inventar dado no caminho do webhook | |

**User's choice:** Buscar do HubSpot.
**Notes:** Achado que motivou a pergunta — a string `owner` não aparece em `config/services.php`,
`HubspotApiClient` nem `HubspotWebhookController`. O campo do §2 nunca teve fonte no sistema.

| Opção | Descrição | Selected |
|---|---|---|
| Colunas novas em `companies` | `hubspot_owner_id`, `hubspot_owner_nome`, `data_venda` — indexável e ordenável | |
| Só no JSON `hubspot_snapshot` | Zero migration; leitura em PHP depois de materializar | |
| Você decide | Discricionário | ✓ |

**User's choice:** "Você decide" → Claude decidiu **colunas novas aditivas nullable**.
**Notes:** Motivos registrados — extrair JSON em `WHERE`/`ORDER` no MariaDB é armadilha que o
SQLite dos testes não pega; a Fase 156 vai querer a data da venda como evento datado.

---

## Onde mora a lista e quando a empresa sai

**Interrupção:** a primeira pergunta desta área ("aba nova × rota própria × trocar a aba
Empresas") **não foi respondida pelas opções**. O usuário trouxe uma reorganização maior e
pediu explicitamente para aguardar um documento formal antes de qualquer decisão. A discussão
foi pausada, o enunciado falado foi gravado em
`.planning/seeds/151-153-admin-no-comercial-dois-modulos-260902.md`, e o documento chegou na
mensagem seguinte — corrigindo o nome do módulo de `Comunicação` para **`Entrada`**.

### Escopo da reorganização

| Opção | Descrição | Selected |
|---|---|---|
| 138 entrega a casca; checklists na 139 | Reorganização + Empresas Ganhas + nascimento na etapa 1; tarefas ficam na Fase 152 | ✓ |
| Corrigir ROADMAP e REQUIREMENTS primeiro | Parar a discussão e só voltar com a fronteira já reescrita | |
| Tudo dentro da 138 | Absorver também os 12 itens de checklist | |

**User's choice:** 138 entrega a casca.
**Notes:** Levantado antes da pergunta que nada da reorganização está no ROADMAP e que
`ADMIN-01` diz na letra "Contrato / Estrutura / Comunicação" — é reescrita de requirement,
não interpretação.

### Encaixe da listagem nos módulos

| Opção | Descrição | Selected |
|---|---|---|
| Mesma empresa nos dois, separada por processo pendente | Sem aba separada; Contrato e Entrada são as listas | ✓ |
| Aba própria "Empresas Ganhas" + Contrato/Entrada como áreas de trabalho | Cinco itens no Comercial | |
| Contrato é a porta de entrada, Entrada vem depois | Fila sequencial, cada empresa num lugar de cada vez | |

**User's choice:** Mesma empresa nos dois, separada por processo.
**Notes:** Consequência anotada — isso reescreve `COMERC-02`, que fala numa listagem só.

### O que popula cada lista na Fase 151

| Opção | Descrição | Selected |
|---|---|---|
| Contrato já real, Entrada nasce como casca | Tela da Fase 131 movida (já lê o Clicksign) + Entrada só com as colunas do §2 | ✓ |
| As duas listas com o mesmo conjunto | Simétrico, mas duas listas idênticas lado a lado | |
| Separar por etapa da máquina de estados | Usa só o que a 137 entregou, mas contraria a escolha anterior | |

**User's choice:** Contrato já real, Entrada nasce como casca.

### Corte de saída (COMERC-03)

| Opção | Descrição | Selected |
|---|---|---|
| Sai ao entrar na etapa 5 | Continua visível em `administrativo_concluido` (4) | ✓ |
| Sai ao entrar na etapa 4 | Leitura literal do nome da etapa | |
| Você decide | Resolver junto com o botão FINALIZAR na 139 | |

**User's choice:** Sai ao entrar na etapa 5.
**Notes:** Risco apresentado — sair na 4 deixaria a empresa órfã entre o Comercial e a
Coordenação, sem ninguém capaz de agir sobre ela.

---

## Qual pendência e qual setor

| Opção | Descrição | Selected |
|---|---|---|
| As duas, em colunas separadas e nomeadas | Pendência do fluxo (137) + pendências do cadastro (as 8 derivadas) | ✓ |
| Só a pendência do fluxo (Fase 150) | Mais limpo, mas as 8 comerciais somem das listas novas | |
| Só as 8 comerciais derivadas | Rejeitada — é o modo de falha que a D-17 da Fase 150 documenta | |

**User's choice:** As duas, em colunas separadas.

| Opção | Descrição | Selected |
|---|---|---|
| Setor ECF (`servicos.setor`) | Operacional, sempre preenchido, já é filtro existente | ✓ |
| Segmento do cliente (`industry` do HubSpot) | Comercial de verdade, mas só existe com company associada e vive em JSON | |
| As duas colunas | Cobre o "ou" do §2 sem escolher | |

**User's choice:** Setor ECF.

---

## Portas de entrada na etapa 1

| Opção | Descrição | Selected |
|---|---|---|
| Sim, as duas portas nascem na etapa 1 | Webhook e `ComercialController::store` chamam o mesmo serviço | ✓ |
| Só o webhook | Leitura estrita de COMERC-01; cria segunda classe de empresa | |
| Sim, mas com escolha de quem cadastra | Checkbox "entra no fluxo de entrada" no formulário | |

**User's choice:** As duas portas.

| Opção | Descrição | Selected |
|---|---|---|
| Não carimbar o legado | Segue a D-05 da Fase 150 — nunca deduzir etapa de estado externo | ✓ |
| Carimbar quem está claramente em entrada | Listas nasceriam com conteúdo real, mas com carimbo artificial | |
| Você decide | Medir em produção antes | |

**User's choice:** Não carimbar.

---

## Pontas extras (levantadas por Claude, escolhidas pelo usuário)

O usuário optou por **discutir as três** em vez de deixá-las discricionárias.

### Permissões dos módulos Contrato e Entrada

| Opção | Descrição | Selected |
|---|---|---|
| Manter permissões; só a navegação muda | Contrato segue `admin.contratos`; Entrada ganha chave nova | ✓ |
| Tudo sob `comercial.cadastrar_empresa` | Quem tem só `admin.contratos` perderia acesso e o teste da 131 quebraria | |
| Contrato e Entrada sob uma chave nova única | Conceitualmente limpo, mas exige migrar quem já tem `admin.contratos` | |

**User's choice:** Manter permissões.

### Destino de `admin.empresas`

| Opção | Descrição | Selected |
|---|---|---|
| Tirar do menu agora, apagar depois | Rota e página seguem vivas; remoção real vira trabalho próprio | ✓ |
| Apagar tudo nesta fase | Cumpre "retirar" ao pé da letra, mas exige portar pai/filhas antes | |
| Você decide | Comparar campo a campo no planejamento | |

**User's choice:** Tirar do menu agora.
**Notes:** Motivo apresentado — `AdminController::updateEmpresa()` zera campos omitidos, o mesmo
modo de falha que já apagou a coluna "Link do Whats" no Polos.

### Owner retroativo

| Opção | Descrição | Selected |
|---|---|---|
| Sim, por comando manual | `hubspot:reenriquecer-handoff` já refaz o fetch; dry-run + reconsulta ao banco | ✓ |
| Não, só daqui pra frente | Coluna do §2 furada justamente para quem está no meio do administrativo | |
| Você decide | Medir custo de chamadas em produção | |

**User's choice:** Sim, por comando manual.

---

## Claude's Discretion

- **Onde gravar owner e data da venda** — único "você decide" da discussão. Resolvido como
  colunas aditivas nullable em `companies`.
- Forma dos componentes de lista, nome da classe do controller/serviço do módulo Entrada, e a
  chave literal da permission nova de Entrada.

## Deferred Ideas

- Apagar de vez rota/controller/página/permission de `admin.empresas`.
- `industry` do HubSpot como coluna de segmento do cliente.
- Destino da Fase 153 (mensagem de boas-vindas virou item do módulo Entrada).
- Resolver o conflito de contagem §3 (12 atividades) × §5 (9 controles) — decisão da Fase 152.
- Replicar o filtro por etapa nas listas novas do Comercial.
