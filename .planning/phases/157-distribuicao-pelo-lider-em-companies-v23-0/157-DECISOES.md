---
phase: 157-distribuicao-pelo-lider-em-companies-v23-0
tipo: decisoes-de-desenho
data: 2026-09-10
origem: pedido do usuário em 2026-09-10, posterior ao fechamento da v23.0
---

# Fase 157 — a distribuição muda de dono e de lugar

Pedido do usuário depois da v23.0 fechar. **Não é correção da Fase 154** — é mudança de produto: a
distribuição sai da Coordenação e passa a ser ato do **líder do setor Performance**, dentro de
`/companies`.

## O que foi MEDIDO antes de decidir

| Fato | Consequência |
|---|---|
| `/companies` **não filtra por carteira** — todo usuário com acesso vê todas as empresas de Performance | a visibilidade pedida é **mudança de comportamento**, não ajuste |
| Não existe linha em `setores` com slug `performance` | "líder do setor" não tinha como ser expresso |
| Nenhum líder cadastrado em `setor_lideres`, e nenhum usuário Luiz no banco local | o cenário precisa ser construído |
| A aba Pendências tem **`sem_responsavel`** entre os 5 tipos | é exatamente o que a Distribuição resolve — a substituição não é arbitrária |

## D-A — Visibilidade: analista/estrategista veem só as suas; líder vê tudo

Decisão do usuário.

- **Admin**: vê tudo (inalterado).
- **Líder do setor Performance**: vê tudo — precisa, para distribuir.
- **Quem tem cargo `analista` ou `estrategista`**: vê apenas empresas onde está vinculado em
  `company_users` (qualquer papel).
- **Demais perfis**: comportamento inalterado — vêem o que viam.

⚠️ **Isto MUDA o que usuários atuais enxergam.** Quem tinha cargo e via ~180 empresas passa a ver
só as suas. É o pedido na letra ("vai aparecer apenas as empresas destinadas pra ele"), e está
registrado aqui porque alguém vai estranhar antes de lembrar que foi pedido.

O Luiz é líder **e** estrategista. A regra de líder vence: ele vê tudo em `/companies`. O pedido
dele — "em empresas vai aparecer apenas as empresas que ele colocar ele mesmo como responsável" —
é atendido pelo filtro **"Minhas empresas"**, que fica ligado por padrão para quem tem cargo e
disponível para o líder. Assim ele tem as duas visões sem precisar de duas telas.

## D-B — O setor é **Performance**

Decisão do usuário: *"no caso o setor é a Performance mesmo"*.

Migration **cria** a linha em `setores` com slug `performance`, casando com o valor que
`servicos.setor` já usa. O líder é expresso por `setor_lideres`, mecanismo que já existe e que
`User::isLiderDe()` já consome.

**Efeito colateral desejado:** isso fecha o buraco medido na Fase 154 — `servicos.setor='performance'`
era o setor de mais empresas e **não tinha linha correspondente**, o que fazia o seletor de
analista/estrategista cair sempre no fallback "mostrando todos". Com o setor existindo, o filtro
por setor passa a funcionar de verdade para as empresas de Gestão/Mentoria.

## D-C — A aba Pendências vira Distribuição, sem perder os outros 4 tipos

Decisão do usuário. A aba `pendencias` de `/companies` passa a ser `distribuicao`, visível **só para
o líder e para admin**.

**O que a substituição levaria junto, e como fica preservado:** a aba tinha 5 cards clicáveis de
pendência. `sem_responsavel` é absorvido pela própria Distribuição — é o mesmo problema. Os outros
quatro (`sem_cust_id`, `sem_email_colaborador`, `sem_grant_ativo`, `empresa_nova`) viram **filtro na
aba Empresas**, onde os badges já apareciam. Nada some; muda de lugar.

## D-D — A tela `/coordenacao/distribuicao` sai do menu

Decisão do usuário: um lugar só para distribuir. Duas telas fazendo o mesmo divergem com o tempo.

**A rota e o controller ficam vivos**, apenas fora do menu — mesma disciplina da D-16 da Fase 151
com `admin.empresas`: apagar tela junto com mudança de navegação mistura dois riscos. A remoção real,
se for o caso, é trabalho próprio.

`DistribuicaoService` é **reusado inteiro** — a régua de elegibilidade, o desempate determinístico e
a transição 5→6 não mudam. O que muda é quem chama e de onde.

## D-E — Distribuir aqui continua movendo a etapa

O ato do líder é o mesmo ato: grava os dois vínculos e move a empresa `aguardando_distribuicao` →
`aguardando_onboarding`, com o líder como ator na linha de histórico. A Fase 156 continua lendo
isso sem saber que a tela mudou.

## Fora de escopo

- Redistribuir/trocar responsável depois (segue como na Fase 154).
- Notificar o analista/estrategista escolhido.
- Apagar a rota `/coordenacao/distribuicao`.
