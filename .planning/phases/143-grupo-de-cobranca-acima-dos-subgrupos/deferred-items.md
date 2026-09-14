# Fase 143 — itens descobertos e NÃO feitos no plano 01

Descobertos durante a execução do 143-01, fora do escopo declarado dele
(que é o motor: migration + model + `ConsolidarMesFechamento` + resolver).

⚠️ **Nenhum deles causa problema hoje**, porque `parent_id` nasce nulo nos 15
grupos e ninguém ganha pai nesta entrega. Todos viram problema **no dia em que
a UI do plano seguinte permitir montar a árvore**.

---

## 1. A tela AO VIVO do fechamento ainda agrega por `company_group_id` cru

`AdminController::fechamentoAgregarGruposAoVivo()`
(`app/Http/Controllers/AdminController.php:1095`) e o ramo congelado análogo
(`:1334`) fazem `->groupBy('company_group_id')`, sem passar pela raiz.

Consequência depois que alguém pendurar um subgrupo: a tela ao vivo mostra
quatro linhas e o `fechamento:consolidar-mes` congela uma — **divergência
silenciosa entre o que a pessoa confere na tela e o que vira cobrança**. É
exatamente a classe de defeito que o comentário da Fase 138 no próprio
comando avisa ("divergência silenciosa em tabela de cobrança só aparece na
fatura do cliente").

⚠️ **Prioridade máxima para o plano que ligar a UI.** O degrau de tabela da
tela já segue a raiz (ela chama `FechamentoFaixaResolver::paraEmpresa()`, que
mudou) — só a AGREGAÇÃO ficou para trás.

## 2. `CompararMensalidadeFechamento` agrega por `company_group_id` cru

`app/Console/Commands/CompararMensalidadeFechamento.php:191-192`.

É o comando do comparativo ANTES × DEPOIS. O CONTEXT exige que "nenhuma
mudança entre sem um comparativo antes×depois por grupo" — então este comando
precisa agregar pela raiz ANTES de ser usado para aprovar a montagem da
árvore em produção, senão ele compara quatro linhas contra uma e reporta
lixo.

## 3. O snapshot de empresa perdeu a informação de qual SUBGRUPO ela é

`fechamento_snapshots.company_group_id` passou a guardar a raiz (obrigatório:
`fechamento:verificar-consolidacao` casa membro com grupo por essa coluna).
Com isso o snapshot não registra mais em qual subgrupo a empresa estava.

Se a tela quiser mostrar a composição por subgrupo dentro da linha do
cliente, vai precisar ou ler `companies.company_group_id` ao vivo (que não é
histórico) ou de uma coluna nova `subgrupo_id` no snapshot. Não foi feito
aqui porque nenhuma tela pede isso hoje.

## 4. Não existe UI para definir `parent_id`

`CompanyGroupController::store()/update()` validam só `name` e `color`. O
model aceita `parent_id` (está no `$fillable`) e valida a trava de um nível no
`saving`, com mensagens já em pt-BR prontas para exibir — mas não há rota,
campo nem tela. É o objeto do plano seguinte (D-06: "a tela tem de ser boa o
bastante para o pessoal resolver sozinho, sem pedir migration").
