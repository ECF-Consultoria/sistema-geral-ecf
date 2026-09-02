# Reorganização: o Administrativo é absorvido pela Área Comercial, em DOIS módulos

**Registrado:** 2026-09-02
**Origem:** decisão trazida pelo usuário durante `/gsd-discuss-phase 138`, repassada a ele
por terceiros ("foi isso que me mandaram"). Substitui o enunciado falado da primeira versão
deste arquivo — em especial, **o módulo que funde Estrutura + Comunicação chama-se `Entrada`,
não `Comunicação`**.

---

## O enunciado, na letra

- Retirar a área de **Empresas** do Administrativo.
- Centralizar essa gestão dentro do **Comercial**, mantendo distinção clara entre os tipos de
  empresas/processos.
- Incorporar as funcionalidades necessárias do **Administrativo** dentro da área Comercial.
- Dentro dessa estrutura, separar as empresas/processos por **Contrato** e **Entrada**.
- Incluir as **tarefas de checklist** correspondentes a cada etapa, seguindo o documento de fluxo.

## Estrutura resultante

```
Área Comercial
  ├── Cadastro de Empresas   (já existe — comercial.empresas.listagem, aba `empresas`)
  ├── Grupos                 (já existe — mesma rota, aba `grupos`)
  ├── Contrato               ← absorve o Administrativo › Contratos (Fase 131)
  └── Entrada                ← módulo NOVO: grupos Estrutura + Comunicação do §3, fundidos
```

### Módulo **Contrato** — 4 itens (§3, sem mudança)

- Revisar o contrato
- Enviar ao cliente
- Acompanhar a assinatura
- Confirmar contrato assinado

### Módulo **Entrada** — 8 itens (Estrutura + Comunicação do §3, sob nome único)

Vindos de **Estrutura**:
- Criar grupo de WhatsApp
- Criar ou definir e-mail colaborador
- Gerar link de conexão com a ADMA
- Gerar Grant da consultoria
- Gerar link/conexão com o sistema ECF

Vindos de **Comunicação**:
- Gerar mensagem de boas-vindas
- Inserir todos os links necessários
- Enviar a mensagem no grupo de WhatsApp

---

## O que isso encosta no código que já existe (medido em 2026-09-02)

| Hoje | Onde | O que fazer |
|---|---|---|
| Administrativo › **Empresas** | rota `admin.empresas` + `admin.empresas.update`, `AdminController::empresas()`/`updateEmpresa()`, `resources/js/Pages/Admin/Empresas.jsx`, item de menu em `AppLayout.jsx:282`, permission `admin.empresas` | **retirar** |
| Administrativo › **Contratos** | rotas `admin.contratos.*` (`routes/web.php:1442`), `ContratoAdminController`, `Pages/Admin/Contratos.jsx` + `ContratoDetalhe.jsx`, menu em `AppLayout.jsx:283`, permission **própria** `admin.contratos` | **incorporar** no Comercial como módulo Contrato |
| Comercial › listagem | `comercial.empresas.listagem`, `ComercialController::listagem()`, `Pages/Comercial/EmpresasListagem.jsx` (842 linhas, abas Empresas·Grupos, 8 cards de pendência, modal Detalhes HubSpot) | vira a casa dos 4 módulos |

**Armadilhas já conhecidas nesses arquivos:**
- `AdminController::updateEmpresa()` **zera campos omitidos** no payload — o mesmo modo de falha
  que já apagou a coluna "Link do Whats" no Polos. Se essa edição migrar para o Comercial, o
  comportamento migra junto se ninguém olhar.
- A permission `admin.contratos` é **deliberadamente própria** e está FORA do grupo `role:admin`
  (comentário no `routes/web.php:1435-1441`): a Fase 131 fez isso para o Administrativo receber
  a permissão por setor sem deploy, e existe `ContratoAdminPermissaoTest` que fica vermelho se
  alguém reempacotar essas rotas sob `role:admin`. Mover para o Comercial **não pode** virar
  `permission:comercial.cadastrar_empresa` sem decidir o que acontece com quem tem hoje só
  `admin.contratos`.
- Página React de re-export puro **some do manifest do Vite** — learning já registrado. Se a
  absorção for feita com arquivo que só re-exporta o componente antigo, a tela some em produção.

## O que isso contradiz — precisa de reescrita, não de interpretação

1. **`ADMIN-01` (`.planning/REQUIREMENTS-v23.md`)** diz na letra: checklist *"agrupados em
   **Contrato / Estrutura / Comunicação**"*. Vira **Contrato / Entrada**.
2. **Fase 139 no `ROADMAP.md`** repete "os 9 itens do §5" e a divisão em três grupos.
3. **Fase 140** existe só para a mensagem de boas-vindas (COMUNIC-01/02/03). "Gerar mensagem de
   boas-vindas" agora é item do módulo **Entrada** — decidir se a 140 continua existindo ou é
   absorvida pela 139.
4. **§3 × §5 não batem em contagem, e a fusão não resolve:** o §3 lista **12** atividades, o §5
   controla **9** com Pendente/Concluído. É o §5 que trava o botão FINALIZAR (ADMIN-05). Qual das
   duas listas vale precisa ser decidido explicitamente.
5. **Nada disto está no ROADMAP hoje.** Retirar `admin.empresas` e mover `admin.contratos` é
   trabalho que nenhuma das 7 fases da v23.0 descreve. Precisa entrar como escopo declarado de
   uma fase, ou como fase própria — não pode aparecer como deviation no meio da execução.

## O que NÃO muda

- **D5** — o grupo Contrato **lê** o estado do envelope Clicksign entregue pelas Fases
  126/127/129/132. Mover a tela para dentro do Comercial é mudança de navegação; continua
  proibido reimplementar assinatura.
- **D3** — link ADMA, link de conexão ECF e Grant marcam sozinhos ao gerar; grupo de WhatsApp,
  e-mail colaborador e envio da mensagem são marcação manual com quem marcou e quando.
- **Fase 137 inteira** — a máquina de estados não é tocada. `EtapaTransicaoService` segue em
  `app/Services/FluxoEntrada/`, único ponto de escrita de `companies.etapa`, já com a transição
  de nascimento `'' → aguardando_administrativo` na tabela de permitidas.

## Decisões da Fase 138 já fechadas

- **Responsável comercial vem do HubSpot**: acrescentar `hubspot_owner_id` às props do deal em
  `config/services.php` e resolver o nome por `GET /crm/v3/owners/{id}`, com cache. Medido: hoje
  `owner` **não aparece** em `config/services.php`, `HubspotApiClient` nem
  `HubspotWebhookController` — o campo do §2 nunca teve fonte.
- **Onde grava** (discricionário, delegado ao Claude): colunas aditivas nullable
  `hubspot_owner_id`, `hubspot_owner_nome`, `data_venda` em `companies`. Motivo: extrair JSON em
  `WHERE`/`ORDER` no MariaDB é armadilha que o SQLite dos testes não pega, e a Fase 143 vai
  querer a data da venda como evento datado. `closedate` já é buscado hoje, mas só cai dentro do
  JSON `hubspot_snapshot.deal`.
