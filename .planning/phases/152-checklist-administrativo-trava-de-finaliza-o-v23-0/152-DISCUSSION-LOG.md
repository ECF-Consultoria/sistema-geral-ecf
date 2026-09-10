# Phase 152: Checklist administrativo + trava de finalização (v23.0) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-09-09
**Phase:** 152-checklist-administrativo-trava-de-finaliza-o-v23-0
**Areas discussed:** A contagem (12 vs 9), O Grant que não dá pra gerar, "Contrato revisado" sem fonte, Onde o checklist mora na tela

---

## A contagem: 12 ou 9 itens

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| 9 do §5 — só terminais | Os três de fora são passos que desembocam em item já controlado. É a lista que o §5 diz que trava o FINALIZAR. | ✓ |
| 12 visíveis, 9 travam | As 12 aparecem como roteiro, só as 9 entram no gate. Custo: duas listas em sincronia. | |
| 12 do §3 — tudo trava | Fiel à letra do §3, mas cria três itens manuais redundantes. | |

**User's choice:** 9 do §5 — só terminais
**Notes:** A diferença exata foi medida e apresentada antes da escolha — o §5 é o §3 menos `acompanhar a assinatura`, `gerar mensagem de boas-vindas` e `inserir todos os links necessários`. Fecha a decisão que o ROADMAP marcou como em aberto.

---

## Item "não aplicável"

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Sim, com autoria | Item vai para `nao_aplicavel` e sai do denominador — é o que o motor do Onboarding já faz. | |
| Não — todos obrigatórios | Mais rígido. Risco apresentado: empresa que legitimamente não precisa fica travada, e a saída vira marcar "concluído" mentindo. | ✓ |
| Você decide | | |

**User's choice:** "não - todos obrigatorios"
**Notes:** O risco foi apresentado na própria opção antes da escolha. O único caso concreto conhecido (isenção de contrato) acabou resolvido por montagem condicional na área seguinte, não por marcação.

---

## O Grant que não dá pra gerar / o link do Adman

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| É o link OAuth do Mercado Livre | `ml_link_url` via `POST /companies/{company}/ml/initiate`. | |
| É preencher o Cust ID | `companies.adman_account_id` — cadastro, não link. | |
| São as duas coisas | | |

**User's choice:** *(resposta livre)* — "é esse link para todos: `https://app.ad-man.io/register?ref=588D0DD78C4F`"
**Notes:** **Nenhuma das opções apresentadas estava certa.** O item do Adman não é gerado nem per-company — é um link fixo de cadastro/indicação, idêntico para todas as empresas, que não existe em lugar nenhum do repositório. As três opções partiam de uma premissa errada herdada do §3, que fala em "gerar link de conexão". O usuário também corrigiu a nomenclatura: **Adman**, não "ADMA".

| Opção (Grant) | Descrição | Selecionada |
|--------|-------------|----------|
| Auto-marcar pelo estado de `company_grants` | + botão "sincronizar agora" disparando o sync global. | |
| Marcação manual | | |
| Você decide | | |

**User's choice:** *(resposta livre)* — "nesse caso seria o OAuth do mercado livre para o cliente conectar, nao tem nada com SFTP"
**Notes:** **Também nenhuma opção estava certa.** As três partiam da premissa de que "Grant da consultoria" se referia à planilha de grants por SFTP (`company_grants` / `grants:sync-sftp`). Não se refere: é o OAuth do Mercado Livre. A pergunta inteira era sobre o objeto errado. Isso remapeou dois dos nove itens.

---

## Quando o item do OAuth fecha

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Quando o cliente conectou | Callback bem-sucedido. Link tem TTL de 7 dias: gerado ≠ usado. | ✓ |
| Quando o link é gerado | Marca "concluído" sem nada ter acontecido do lado do cliente. | |
| Dois itens separados | Quebraria a decisão de 9 itens — viraria 10. | |

**User's choice:** Quando o cliente conectou
**Notes:** A armadilha do clique interno gerando autorização falsa foi apresentada junto e consta do CONTEXT.

---

## Onde mora o link fixo do Adman

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Config com override por env | `ADMAN_REGISTER_URL` em `config/services.php`; trocar o `ref` vira mudança de `.env` na VPS. | ✓ |
| No banco, editável por tela | Custa tabela + tela + permissão, e a Fase 153 já fará edição sem deploy. | |
| Constante no código | Trocar o `ref` exigiria deploy. | |

**User's choice:** Config com override por env

---

## "Contrato revisado" sem fonte

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Marcação manual, com autoria | Leitura honesta: revisar é ato humano. Fura a letra do ADMIN-02. | ✓ |
| Auto por `estaPronta()` | Cumpre o ADMIN-02, mas mede completude de cadastro, não revisão. | |
| Auto por `status !== rascunho` | Na prática a mesma condição de "enviado" — um dos itens viraria decoração. | |

**User's choice:** Marcação manual, com autoria
**Notes:** A exceção ao ADMIN-02 precisa ser registrada em `REQUIREMENTS-v23.md`, não deixada implícita.

---

## Empresa sem exigência de contrato

| Opção (1ª rodada) | Descrição | Selecionada |
|--------|-------------|----------|
| Grupo Contrato só aparece se exigir | Montagem condicional; denominador acompanha. | |
| Itens auto-concluídos quando não exige | "Concluído" passaria a significar duas coisas. | |
| Todo serviço exige contrato | Se for assim na prática, o caso não existe. | ✓ (revertida) |

**User's choice (1ª rodada):** Todo serviço exige contrato
**Notes:** A escolha foi **medida contra o banco antes de ser travada, e não se sustentou**: `Polos` (`servicos.id=2`) tem `exige_contrato = 0` e **5 vínculos `contratos_servico` ativos** hoje. O próprio `ContratoAdminController` comenta a isenção na letra ("D9 — isenção, ver `Servico::exigeContrato()`"). Manter essa resposta deixaria 5 empresas reais sem poder finalizar, para sempre. A medição foi apresentada e a pergunta reaberta.

| Opção (2ª rodada) | Descrição | Selecionada |
|--------|-------------|----------|
| Grupo Contrato não existe pra elas | Empresa Polos nasce com 6 itens; reusa `Servico::exigeContrato()`. | ✓ |
| Itens auto-concluídos com razão registrada | | |
| Polos não entra neste fluxo | | |

**User's choice (2ª rodada):** Grupo Contrato não existe pra elas

---

## Onde o checklist mora na tela

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Uma ficha única, aberta pelas duas listagens | Reusa `ContratoDetalhe.jsx` (1127 linhas), que já traz assinatura e signatários. | ✓ |
| Cada módulo mostra o seu grupo | Duas telas; quem finaliza teria que conferir a outra. | |
| Drawer nas duas listagens | Jogaria fora a ficha existente. | |

**User's choice:** Uma ficha única, aberta pelas duas listagens

---

## Permissão para marcar e finalizar

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| A permissão do módulo já basta | `comercial.entrada` e `admin.contratos`, ambas da Fase 151. | ✓ |
| Permissão própria só pro FINALIZAR | Mais controle sobre ação irreversível; mais uma chave pra configurar. | |
| Você decide | | |

**User's choice:** A permissão do módulo já basta

---

## Claude's Discretion

Nenhuma pergunta foi respondida com "você decide". As decisões discricionárias (D-10 a D-14 no CONTEXT) foram tomadas por delegação implícita, ancoradas em medição do código, e três delas foram apresentadas ao usuário no resumo de fechamento sem correção:

- **D-10** — copiar o *shape* do motor de Onboarding, com tabela nova ancorada em `company_id` (hospedar em `onboardings` exigiria serviço fantasma e furaria a unique de `contrato_servico_id`)
- **D-11** — autoria copiando `feito_por`/`feito_em`/`auto_em` verbatim, com `withTrashed()` e limpeza pareada
- **D-12** — destino do FINALIZAR por `Company::primaryMarketplace()`, com o alerta de que `is_primary` não tem trava de banco
- **D-13** — os quatro itens sem estado observável são manuais, não inventados
- **D-14** — item da conexão ECF fecha por existência do link, não por clique (é `firstOrCreate`)

## Deferred Ideas

- **Reversibilidade do FINALIZAR** — oferecido como zona cinzenta adicional na pergunta de fechamento; o usuário escolheu seguir para o CONTEXT. Não discutido.
- **Cliente desconecta o OAuth depois de finalizado** — idem.
- **Integração per-company de Grant com o ML** — perdeu o objeto quando a D-05 esclareceu que o item é OAuth, não planilha SFTP. Registrado para ninguém reabrir por engano.
