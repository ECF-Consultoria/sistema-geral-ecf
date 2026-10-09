# Fase 175 — decisões do usuário em 2026-10-08

Respostas aos dois checkpoints bloqueantes desta fase, dadas **antes** de as waves 4 e 5
começarem. Valem sobre o texto dos planos onde houver divergência.

---

## 1. Capa do kit — **gerar DUAS imagens, o usuário escolhe**

**Pergunta:** com 1 slot, a capa do kit em categoria de móveis sairia AMBIENTADA (consequência
do `categoriaMoveis`, que faz o primeiro slot ser `lifestyle` em vez de `hero`) em vez de fundo
branco. Mantém?

**Resposta do usuário:** *"Gerar as duas e você escolhe"*.

### O que isso muda

⚠️ **O `175-06-PLAN.md` foi escrito para um kit de UM slot ("Imagem principal"). Está
SUPERADO neste ponto:** o kit da capa tem **DOIS** slots — `lifestyle` (ambientada) e `hero`
(fundo limpo) — e o operador aprova um dos dois como foto 1 do kit.

- Custo: **2 imagens por capa de kit (~R$ 1,10)**, não 1 (~R$ 0,55). O usuário aceitou o dobro
  em troca da escolha.
- `SLOTS_PADRAO` já é **2** (quick 261007-kit2), então o terceiro parâmetro de quantidade no
  `PlanejarKitCriativosJob` **pode nem ser necessário** — confira antes de acrescentá-lo. O que
  importa é que os dois slots sejam **exatamente** `lifestyle` e `hero`, não os dois que o
  planner escolheria sozinho pelo Truth.
- **Premissa que eu assumi e o usuário não decidiu explicitamente:** gerar os dois slots em
  **toda** categoria, não só em móveis. A pergunta e o preview falavam de móveis, mas "gerar as
  duas e você escolhe" não faz sentido só em metade dos casos, e o custo é o mesmo nos dois
  ramos. Se ele quiser 1 slot fora de móveis, é mudança de uma linha.
- D5 continua valendo: **ambiente brasileiro subentendido só no slot `lifestyle`**, NUNCA no
  `hero` nem no `white_background`, que são regidos pela moderação do ML.

### O que NÃO muda

- A instrução de mostrar **N unidades idênticas** entra como **FATO** (TRUTH-02/03): `N` vem de
  `pub_produtos.quantidade_kit` (cadastro) e atravessa `CreativeContext` → `ProductTruth::contagens`
  → `linhasContagens()` ("CONTAGENS CONFIRMADAS NO CADASTRO (respeite exatamente)"). **Nunca como
  frase solta no prompt.** Número errado no prompt é pior que número nenhum.
- A referência é a **cópia própria do kit** da foto 1 do base (o clone do `175-02` já a criou);
  `selecionarFotos()` continua recusando foto de outro rascunho e **`planejar` não muda**.
- Só vira foto 1 do kit **depois de aprovada**; as fotos 2+ seguem herdadas do base.
- Throttles, tetos de custo, `CreativePermissao::exigir()` e a chave do Creative Engine valem
  como estão.

---

## 2. Preço do kit — **vazio como especificado, mas com aviso no painel**

**Pergunta:** o kit nasce com preço vazio (decisão 6 do handoff). Medido: como ele fica com
`oferta_id` NULL (decisão 5 — o SKU do kit existe só no Publicador), o preço nulo **não herda da
Precificação do Portal**. Fica realmente vazio, e a conferência reprova até alguém digitar — ou
seja, o kit existe num estado que não publica. Como tratar?

**Resposta do usuário:** *"Como especificado, com aviso no painel"*.

### O que isso significa

- O painel Criar Fase 2 **continua sem campo de preço e sem sugestão** (decisão 6 intacta).
- O painel **avisa antes de confirmar**, em pt-BR e sem jargão, que falta o preço e que o kit
  será criado mas só publica depois que o valor for informado no editor.
- O aviso **não bloqueia** o Confirmar.
- A exigência do preço continua onde já está: **conferir/publicar**.

---

*Registrado por mim (sessão do Publicador) em 2026-10-08, depois da wave 2 e com a wave 3 em
execução. Os dois checkpoints dos planos `175-05` (Task 3) e `175-06` (Task 3) estão
**respondidos por este arquivo** — o executor não precisa parar neles, mas precisa ler isto.*
