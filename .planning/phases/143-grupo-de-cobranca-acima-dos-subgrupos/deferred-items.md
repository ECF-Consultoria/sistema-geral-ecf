# Fase 143 — itens descobertos e NÃO feitos

⚠️ **Nenhum deles causa problema hoje**, porque `parent_id` continua nulo nos
15 grupos de produção — nem o 143-01 nem o 143-02 penduram ninguém. Todos
viram problema **no dia em que alguém montar a árvore pela tela** (143-03).

---

## Fechados pelo plano 143-02

### ~~1. A tela do fechamento ainda agrega por `company_group_id` cru~~ ✔

Fechado no 143-02 (T1, commit `d22ae486`). Os **dois** ramos —
`fechamentoAgregarGruposAoVivo()` e `fechamentoAgregarGruposCongelados()` —
passaram a agrupar por `CompanyGroup::raizId()`. O ramo congelado entrou junto
como desvio (Regra 2): ele indexa `fechamento_grupo_snapshots` por
`company_group_id`, que desde o 143-01 guarda a RAIZ — agrupando por subgrupo,
cada linha de grupo viria em branco numa competência já fechada.

### ~~2. `CompararMensalidadeFechamento` agrega por `company_group_id` cru~~ ✔

Fechado no 143-02 (T1, commit `d22ae486`). Era a pendência mais urgente: é a
ferramenta de conferência ANTES×DEPOIS que o CONTEXT exige para aprovar a
montagem em produção.

### ~~4. Não existe UI para definir `parent_id`~~ — parcialmente

O 143-02 (T3, commit `f66214cf`) entregou as **rotas** e a **prévia do
impacto**; a tela em si é o 143-03.

---

## Abertos

### 3. O snapshot de empresa perdeu a informação de qual SUBGRUPO ela é

`fechamento_snapshots.company_group_id` passou a guardar a raiz (obrigatório:
`fechamento:verificar-consolidacao` casa membro com grupo por essa coluna).
Com isso o snapshot não registra mais em qual subgrupo a empresa estava.

A prévia do 143-02 contorna isso ao vivo (cada linha traz `subgrupos[]` com a
composição, lida de `companies.company_group_id`), mas **isso não é
histórico**: uma competência já fechada não sabe dizer a composição por
subgrupo daquele mês. Se a tela do 143-03 quiser mostrar a composição de um
mês passado, vai precisar de uma coluna `subgrupo_id` no snapshot.

### 5. A tela do fechamento não tem como saber que uma linha é uma árvore

A linha de grupo das props (`tipo => 'grupo'`) hoje traz `filhas[]` (as
empresas) e `grupo` (a raiz), mas **não diz quais subgrupos compõem a linha**.
Com a árvore montada, a pessoa veria "MPozenato, 10 empresas" sem saber que
DRossi, Gran Belo e Lyam estão lá dentro.

O `SimuladorGrupoCobrancaService` já monta esse dado (`subgrupos[]` por linha)
— o 143-03 pode expor a mesma chave nas props do fechamento. Não foi feito
aqui porque o 143-02 não abre `.jsx` e a chave sem consumidor é peso morto.

### 6. Prévia com competência única

`SimuladorGrupoCobrancaService::simular()` recebe UM mês. Uma decisão de
cobrança que vale daqui pra frente se beneficiaria de ver o impacto em 2 ou 3
competências seguidas (o faturamento do cliente oscila, e um mês atípico pode
fazer a faixa parecer outra). O serviço aceita a chamada repetida sem efeito
colateral — quem quiser, itera. Não foi embutido porque nenhuma tela pede.

### 7. `fechamento:consolidar-mes` não avisa quando a composição do grupo mudou

Se alguém pendurar um subgrupo entre duas competências, a linha do cliente
muda de faturamento e de faixa por **composição**, não por desempenho — e o
aviso de mudança de faixa (Passo 8) vai reportar como se fosse crescimento.
Fora do escopo do 143-02; vale registrar antes de a árvore existir de verdade.
