# Phase 133: Liga o bloqueio — ativação real (v22.0) - Context

**Gathered:** 2026-08-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Fazer o interruptor `administrativo_bloqueio_ativo` ser **seguro de ligar**, ligá-lo em
produção e provar por medição real que ele não quebrou o que funciona.

O que esta fase entrega:
1. A **exceção por serviço** que hoje não existe (Polos nunca pode ser bloqueado).
2. O fechamento da **porta dos fundos** do time de Publicação (FLUXO-09).
3. Um **aviso na tela já existente** do Administrativo quando a chave está ligada.
4. A **chave ligada em produção**, com prova por reconsulta ao banco.

⚠️ **O que esta fase NÃO faz:** criar roteamento operacional para serviços que hoje não têm
ficha (Gestão, Mentoria, Publicação, Implantação, Publicidade, Gestão de ADS Shopee). Isso
seria capacidade nova — ver `<deferred>`.

</domain>

<decisions>
## Implementation Decisions

### Alcance do bloqueio

- **D-01: Entregar a trava pronta e provada, aceitando que o efeito prático hoje é nulo.**
  Medido em produção: das 9 famílias de serviço, só Polos, Assessoria e Incubadora geram ficha
  operacional; Polos é isento de contrato (D9), e **não existe nenhuma ficha de Assessoria ou
  Incubadora na base**. Logo, com a chave ligada, nada é retido hoje. A decisão consciente do
  usuário é entregar a trava correta e provada por teste, para quando essas empresas entrarem —
  **não** inventar roteamento novo para forçar efeito.

- **D-02: A exceção decide POR SERVIÇO, dentro do laço — não por empresa.**
  Para cada serviço da empresa, consultar `Servico::exigeContrato()`. Uma empresa com Polos +
  Assessoria tem o **Polos roteado** e a Assessoria retida. A alternativa (segurar a empresa
  inteira se qualquer serviço exigir contrato) foi **rejeitada**: prenderia Polos por causa de
  outro serviço, contrariando o SC 2b.

### Porta dos fundos (FLUXO-09)

- **D-03: Fechar por checagem dentro do próprio `MlbController::ativarEmpresaPendente()`**,
  sem refatorar o método para passar pelo `EmpresaOperacionalRouter`.
  Motivo: o router deriva o tipo do **nome do serviço**, enquanto esse método recebe o tipo
  **escolhido a mão** por quem clica (`$validated['tipo']`) — encaixar um no outro exigiria
  mexer num fluxo em uso. A opção cirúrgica foi preferida.
  ⚠️ **Consequência aceita:** a regra passa a existir em **dois lugares**. O plano DEVE incluir
  teste nos dois caminhos — senão a próxima mudança conserta um e esquece o outro.

### Aviso ao usuário

- **D-04: Faixa na tela `/administrativo/contratos` (Fase 131) quando a chave está ligada.**
  A "lista de empresas retidas" **já existe**: `ContratoAdminController::index()` lista empresas
  ativas com serviço que exige contrato, com status por par empresa+serviço, dias parado e causa.
  O que falta é a tela **contar a consequência** — que enquanto o contrato não for assinado a
  empresa não entra na operação. A faixa some quando a chave está desligada.
  ⛔ **Não criar tela nova nem lista nova.**
  Linguagem: sem jargão (UI-06) — nada de "flag", "roteamento", "ficha operacional".

### Rollout

- **D-05: Ligar em produção e conferir o próximo cadastro real de Polos**, por reconsulta ao
  banco (nunca pela tela). Ficha nascendo com a chave ligada = exceção provada. Ficha não
  nascendo = **desligar na hora** — a própria chave é a saída, nunca rollback de código.
  Nada de empresa de teste: o usuário optou por esperar o cadastro real.

### Claude's Discretion

- Granularidade da faixa de aviso (texto exato, posição na tela).
- Como a checagem do FLUXO-09 devolve o erro (flash, 403, mensagem) — desde que sem jargão.
- Estrutura dos testes, desde que cubram os **dois** caminhos da D-03 e o caso
  Polos-com-chave-ligada.

### Folded Todos

- **`260818-ficha-operacional-nao-criada-na-liberacao.md`** — **RESOLVIDO durante este scout,
  não é mais dívida.** A ficha não nasceu no gate #10 da Fase 132 porque o contrato de teste era
  de **Gestão**, e `ComercialController::servicoDisparaImplementacao()` só mapeia Polos,
  Assessoria e Incubadora. `gerou_ficha: false` foi gravado corretamente. Não havia bug — o todo
  pode ser fechado. O achado, porém, é o que revelou o alcance real do bloqueio (D-01).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### O interruptor e o roteamento
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — `CHAVE_BLOQUEIO`, `bloqueioAtivo()`,
  `rotear()` (o `return` que hoje bloqueia TUDO), `aplicarRoteamento()`, `criarFicha()`,
  `liberarEmpresa()`. ⚠️ O comentário dentro de `rotear()` marca o **ponto de extensão** da
  D-09/FLUXO-08 que esta fase precisa preencher.
- `app/Http/Controllers/ComercialController.php` — `servicoDisparaImplementacao()`: o mapa que
  decide quais serviços geram ficha (só Polos/Assessoria/Incubadora).
- `app/Models/Servico.php` — `exigeContrato()` e o scope correspondente (Fase 128).

### Os três caminhos de entrada
- `app/Http/Controllers/Api/HubspotWebhookController.php` (~linha 664) — FLUXO-01, já passa pelo
  router.
- `app/Http/Controllers/ComercialController.php` (~linha 574) — FLUXO-02, já passa pelo router.
- `app/Http/Controllers/MlbController.php` — `ativarEmpresaPendente()`: FLUXO-09, cria
  `MlbEmpresa` + `MlbImplementacao` **inline**, por fora do router. É a porta dos fundos.

### A tela que ganha a faixa
- `app/Http/Controllers/ContratoAdminController.php` — `index()`: a listagem que já mostra quem
  está sem contrato.
- `resources/js/Pages/Admin/Contratos.jsx` — a tela.

### Contexto das fases anteriores
- `.planning/phases/132-cutover-sandbox-produ-o-checkpoint-humano-v22-0/132-GATE.md` — o cutover
  aprovado, que é a letra (d) do checkpoint humano desta fase; e o gate empírico #10 fechado.
- `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-CONTEXT.md` — origem da
  D-09 (Polos isento) e do FLUXO-08.
- `.planning/REQUIREMENTS-v22.md` — FLUXO-01, FLUXO-02, FLUXO-09.
- `.planning/ROADMAP.md`, seção "Phase 133" — Success Criteria 1, 2, 2b, 3, 4, 5 e o checkpoint
  humano.

</canonical_refs>

<code_context>
## Existing Code Insights

### Fatos medidos em produção (2026-08-18) — não são suposição

| Medida | Valor |
|---|---|
| Fichas em `mlb_empresas` | **486, todas do tipo `POLO`** |
| Fichas de Assessoria ou Incubadora | **zero** |
| Empresas (`companies`) | 190 |
| Empresas com ficha vinculada | **4** |

| Serviço | exige contrato | gera ficha |
|---|---|---|
| Assessoria | sim | `assessoria` |
| Incubadora | sim | `incubadora` |
| Polos | **não** | `polos` |
| Gestão, Gestão de ADS Shopee, Implantação, Mentoria, Publicação, Publicidade | sim | **não** |

### 🔴 O achado que define a fase

`EmpresaOperacionalRouter::rotear()` hoje faz, em essência:

    if ($this->bloqueioAtivo()) {
        // PONTO DE EXTENSÃO da Fase 128 (FLUXO-08/D-09): aqui vai entrar a consulta
        // "este serviço exige contrato?", que isenta Polos do bloqueio.
        Log::warning(...);
        return;
    }

**A exceção de Polos não existe — é comentário.** Ligar a chave hoje bloquearia Polos, que é o
único fluxo que realmente cria ficha. Implementar essa exceção é o trabalho central desta fase, e
sem ela o SC 2b é impossível.

### Reusable Assets
- `Servico::exigeContrato()` — a consulta já existe desde a Fase 128; é só chamá-la no laço.
- `ContratoAdminController::index()` — a lista de retidas já existe, só falta a faixa.
- `ContratoLiberacao::gerou_ficha` — coluna que já registra se a ficha nasceu; serve de evidência
  no rollout sem precisar de instrumento novo.

### Established Patterns
- Booleano em `Configuracao` como string `'1'`/`'0'`, ausência = desligado (`CHAVE_BLOQUEIO`).
- `Log::warning('[Administrativo] …')` com `company_id` a cada recusa — mesmo padrão que a Fase
  132 usou no interruptor de emissão.
- Conferência por **reconsulta ao banco**, nunca por stdout nem por tela (disciplina das fases
  130/132).

### Integration Points
- Dentro de `rotear()`, antes do `return` do bloqueio: a decisão por serviço.
- Início de `ativarEmpresaPendente()`: a mesma decisão, segunda cópia (D-03).
- `ContratoAdminController::index()`: um booleano novo na prop para a faixa.

</code_context>

<specifics>
## Specific Ideas

- **A prova de sucesso desta fase é "nada quebrou", não "algo foi bloqueado".** Não há o que
  bloquear em produção hoje. Quem verificar a fase precisa saber disso, ou vai procurar um efeito
  que não existe e concluir que falhou.
- O usuário rejeitou criar empresa de teste para o rollout — quer esperar o cadastro real de
  Polos. Diferente da Fase 132, onde aceitou a empresa fictícia.

</specifics>

<deferred>
## Deferred Ideas

- **Roteamento operacional para os outros seis serviços** (Gestão, Mentoria, Publicação,
  Implantação, Publicidade, Gestão de ADS Shopee). Hoje eles exigem contrato mas nunca geram
  ficha — são acompanhados por Carteira/Desempenho, que leem `companies` direto. Se um dia se
  quiser que o contrato trave alguma coisa para eles, primeiro é preciso decidir **o que** ele
  trava. Capacidade nova, fase própria.
- **Bloqueio retroativo** para empresas já cadastradas sem contrato — não discutido, não incluído.
- **Marca por linha na tela** distinguindo "retida" de "isenta" (Polos) — o usuário escolheu só a
  faixa; a marca por linha fica para quando houver retenção de verdade.

### Reviewed Todos (not folded)
- `270629-melhorias-carteira-desempenho-gamificacao-ml.md` — casou só por palavra-chave genérica
  ("phase", "por"). Sem relação com esta fase.
- `270701-investigar-gap-sync-grants-ml-vs-local.md` — idem.

</deferred>

---

*Phase: 133-liga-o-bloqueio-ativa-o-real-v22-0*
*Context gathered: 2026-08-18*
