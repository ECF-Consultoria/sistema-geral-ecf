# 17 — Publicador no sistema interno (`/mlb/anuncios`)

Revisão de 02/10/2026, depois do piloto no Portal (`16`). O motor do Publicador
(`app/Support/Publicador`, `app/Services/Publicador`, tabelas `pub_*`) não muda: muda
**onde ele é aberto, por quem e de onde vêm os produtos**.

## 1. Decisões do usuário (02/10)

| # | Decisão | Por quê |
|---|---|---|
| D12 | O Publicador sai do Portal do Cliente e vira o assistente de `/mlb/anuncios` (sistema interno) | Vai ter API de gerar imagens (não é coisa do cliente) e atende dois projetos: Polos e Incubadora |
| D13 | Ao entrar, escolhe-se **Polos** ou **Incubadora** | Os dois programas publicam pelo mesmo módulo |
| D14 | Em `/mlb/anuncios` troca **só o assistente individual** (`AnunciarML.jsx`). Meus Anúncios, Em massa e Histórico ficam; o "Anunciar por IA" vira botão dentro do Publicador | São recursos no ar e usados |
| D15 | Empresa **sem Portal**: a equipe cadastra os produtos no próprio Publicador. Com Portal, eles vêm do Portal | 535 de 539 empresas de Polos não têm `Company`, e o Portal pendura tudo em `Company` |
| D16 | "Sincronizar do Portal" é **ligado**: produto, título planejado e preço seguem o Portal; o que a equipe digita no Publicador vence; o botão só traz produtos novos | É o comportamento que o motor já tem (`DadosEfetivosService`, `comEfetivos()`) |
| D17 | Acesso: **só admins**, como hoje em `/mlb/anuncios` (`role:admin`) | Decisão do usuário |
| D18 | O Anunciar sai do Portal **para todos os clientes** (o piloto e o formulário antigo do par, no ar desde 29/09) quando o interno estiver no ar — confirmado pelo usuário | Consequência de D12: publicar passa a ser só pela equipe ECF |
| D19 | **Fase GSD completa** (`/gsd-plan-phase` → `/gsd-execute-phase`), com baseline de testes e VERIFICATION | A migration altera `pub_rascunhos`, que já tem dado em produção (CLAUDE.md) — escolha do usuário, mesmo sendo só 2 rascunhos de teste |

| D20 | A Dev 02 vira empresa da **Incubadora** ligada à #459 | Testar pelo caminho real, com sincronização |
| D21 | **Trava por conta** (company_id e mlb_empresa_id), começa só com a #459 | Conta de cliente nunca recebe publicação de teste |
| D22 | Assistente antigo **escondido**: abre rascunhos antigos e "Anunciar semelhante"; remoção em fase própria | Não quebrar Histórico nem 4 rascunhos abertos |
| D23 | Seletor **Polos · Incubadora · Gestão** | As contas da consultoria continuam no módulo |
| D26 | A trava por conta fecha também a **conferência no ML**: em conta não liberada, "Conferir" é só local (sem upload de fotos, sem `/items/validate`, sem consultas à conta) | Conta de cliente recebe no máximo leitura; validate só com o "pode" do usuário |
| D27 | Oferta apagada no Portal: o **produto fica**, solto do Portal (`oferta_id` → NULL); rascunho e histórico de publicações continuam | Nada que já foi publicado some; payload e resposta do ML ficam guardados |

Medido em produção (02/10, leitura): 603 `MlbEmpresa` ativas, 3 da Incubadora, só 5 ligadas ao Portal, 34 com token ML próprio.

## 2. O problema de modelo

Hoje cada rascunho é de uma **oferta do Portal**: `pub_rascunhos.oferta_id` NOT NULL →
`estrutura_ofertas.company_id` NOT NULL → `companies`. Empresa de Polos/Incubadora sem
`Company` não tem oferta, logo não tem rascunho. E a conta do ML é lida por
`$rascunho->oferta->company`.

O serviço do ML já aceita as duas âncoras de conta (`ContaMercadoLivre`, implementado por
`Company` e `MlbEmpresa`; `ml_tokens.mlb_empresa_id` desde 21/09). O que falta é a âncora do
**produto**.

## 3. Desenho de dados

### 3.1 Tabela nova `pub_produtos` — a lista de produtos do Publicador

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint | |
| `mlb_empresa_id` | FK `mlb_empresas`, nullable | a empresa do programa (Polos/Incubadora) |
| `company_id` | FK `companies`, nullable | quando a conta do ML é de `Company` (Gestão) |
| `oferta_id` | FK `estrutura_ofertas`, nullable, **unique** | o vínculo VIVO com o Portal (D16); nulo = cadastrado no Publicador (D15) |
| `sku` | string(120) | |
| `nome` | string(255) | |
| `origem` | string(12) | `portal` · `publicador` |
| `timestamps` | | |

- Pelo menos uma das âncoras de conta (`mlb_empresa_id`, `company_id`) — conferido no serviço
  (MariaDB não tem CHECK confiável em todas as versões do servidor).
- `unique(oferta_id)` com nulos repetidos é permitido no MariaDB (`NULL` não colide) — é o
  que deixa vários produtos "do Publicador" sem oferta.
- Nomes curtos de FK/unique (`pubprod_*`), como as demais `pub_*` (learnings §6).
- O programa (Polos/Incubadora) **não é gravado**: vem da `MlbEmpresa`
  (`tipo = 'INCUBADORA'` / `FASE_PARA_PROJETO`), para não divergir dela.

### 3.2 Alteração em `pub_rascunhos`

- `produto_id` FK `pub_produtos`, **unique** (um rascunho por produto, como era por oferta).
- `oferta_id` passa a **nullable** (o vínculo com o Portal mora no produto; o campo fica por
  compatibilidade e leitura dos 2 rascunhos existentes).
- Os rascunhos existentes ganham o seu `pub_produto` (origem `portal`, `company_id` e
  `oferta_id` da oferta atual) na mesma migration.

**Dado em produção:** `pub_rascunhos` tem **2 linhas**, ambas da #459 (Dev 02 Testes API,
conta de teste), criadas no piloto de 02/10. Nenhum cliente.

### 3.3 O que muda no código

- O rascunho passa a ser aberto por **produto**; quem lê a conta do ML lê
  `produto → (mlbEmpresa | company)` como `ContaMercadoLivre`.
- `ClienteMlPublicador`, `ContaMlService`, `ImagemAssetService`, `ConferenciaService`,
  `PublicacaoService` passam de `Company` para `ContaMercadoLivre`.
- Efetivos (título/preço do Portal) e cadastro na régua (aba Anúncios) **só quando o produto
  tem `oferta_id`**. Sem Portal, o título e o preço são os digitados.
- "Sincronizar do Portal": para cada `EstruturaOferta` da `Company` ligada à `MlbEmpresa`
  que ainda não tem `pub_produto`, cria um (origem `portal`). Idempotente.

## 4. Telas (layout pelo Stitch, projeto "ECF Admin — Identidade")

1. `/mlb/anuncios` — Publicador: Polos | Incubadora, empresas com conta ML, situação do
   Portal (sincronizado / nunca / sem Portal), "Sincronizar do Portal" e "Publicar".
2. Produtos da empresa — os do Portal e os cadastrados aqui; "+ Produto".
3. Editor — o mesmo do piloto (seções 01–08 + trilho), dentro do layout interno, com o
   botão "Anunciar por IA".

As abas Meus Anúncios, Em massa e Histórico continuam como estão.
