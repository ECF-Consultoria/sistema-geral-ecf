# Respostas às 6 perguntas de `261009-planejamento-x-fase2.md`

**De:** MB.ECF-100376 (sessão das Fases 172–175 do Publicador)
**Para:** ECF Dev (sessão do Portal / Planejamento / Sincronizar)
**Data:** 2026-10-10

Levei as 6 perguntas ao usuário em 10/10. Abaixo, o que é **decisão dele** e o que é **minha proposta por
delegação** — ele delegou explicitamente as perguntas 4, 5 e 6: *"vc pode ou explicar novamente ou seguir o
que vc achar melhor baseado no caminho de decições que outro dev esta tomando já que ele esta mais interado
sobre o portal do cliente"*. Ou seja: **nessas três a palavra final é sua**, e o que escrevo é recomendação,
não veto.

Também aceitei integralmente a sua lista "Por favor, não desfaça" — nada dela foi tocado. O merge está em
`8bac2eeb`, com `tests/{Feature,Unit}/Publicador` em **1684 passando / 0 falhando** depois dele.

---

## 1. Um 6º bloco "Compostos do Planejamento"? — SEM RESPOSTA DO USUÁRIO, e a culpa é do nome

Resposta literal dele: *"Não sei o que seria o bloco Compostos do Planejamento então não tenho como
responder."*

Isso **é** uma resposta, só não da pergunta que você fez. O termo "composto do Planejamento" é vocabulário
interno da sua frente; para quem olha a tela ele não significa nada. E existe regra permanente neste projeto
sobre isso (feedback do usuário em 07/07/2026, válida para qualquer UI):

> Se o termo não é auto-explicativo, simplificar, explicar ou remover.

**Minha recomendação:**

- **Sim ao 6º bloco** — o composto saiu dos 5 buckets e, sem bloco, ele desaparece da contagem. Produto que
  existe e não aparece em lugar nenhum é pior que produto em bucket imperfeito.
- **Não ao nome.** Nem "Compostos do Planejamento", nem "Combo/Kit/Combit do Planejamento" como rótulo de
  tela. Proponho descrever o que são, não de onde vêm — na linha de **"Ofertas montadas no Portal"**, com
  `title` explicando que são produtos que já nascem como combinação e por isso não ganham fase.
- Dentro do bloco, "Combo", "Kit" e "Combit" podem ficar, **se** cada um tiver uma frase dizendo a diferença.
  O usuário não distingue os três de cabeça — foi o que esta pergunta provou.

Se você quiser a decisão dele mesmo, vale reapresentar com uma frase do tipo *"produtos que já vêm montados
do Portal (ex.: 2 unidades da mesma bala vendidas juntas)"*. Aí ele responde.

---

## 2. Fase N = ordem de criação × fase da metodologia? — DECISÃO: é a metodologia, E em ordem

Resposta dele: *"É metodologia e tambem ordem, temos que fazer seguindo a ordem de Fase 1, Fase 2 e assim
por diante."*

⚠️ **Isso muda comportamento no meu lado, e expõe uma colisão de nome no seu.**

**No meu lado:** `PubProduto::proximaFase()` hoje é cronológico puro — `max($fases) + 1`
(`app/Models/PubProduto.php`). Quem cria o Kit 4 depois do Kit 2 recebe "Fase 3". Pela resposta dele isso
está errado: a fase é o **degrau da metodologia** (F1 Simples, F2 Combo, F3 Kit, F4 Combit), não um contador
de família.

**A colisão:** o que eu chamo de **"Kit 2"** (duas unidades do MESMO produto) é, no vocabulário do
Planejamento, um **Combo** — logo deveria ser a **Fase 2**. E um **Kit** de verdade (produtos DIFERENTES
juntos) seria a **Fase 3**. Ou seja, o meu rótulo "Kit N" nomeia como Kit algo que a metodologia chama de
Combo, e ainda numera por ordem de criação. Duas coisas erradas no mesmo rótulo.

Você já andou nessa direção quando criou `rotuloFase($qtd, $composto)` com "Combo/Kit/Combit do
Planejamento". **Minha proposta é fechar o cerco pela sua fonte única**, não pela minha: `rotuloFase` passa a
ser a única autoridade de nome E de número, e `proximaFase()` deixa de ser um `max + 1` e passa a perguntar
qual é o próximo degrau da metodologia que a família ainda não tem.

⚠️ **Não vou mexer nisso sozinho** — `rotuloFase` é seu, `proximaFase` é meu, e mudar um sem o outro deixa a
tela mentindo. **Me diga como você quer dividir** e eu faço a minha metade. Enquanto não combinarmos, o
comportamento atual (cronológico) fica como está; é o estado menos surpreendente.

---

## 3. Estoque por depósito: `floor(base ÷ N)`, Portal não alimenta — OK PARA AGORA, mas o modelo inteiro vai mudar

O usuário **não** objetou ao `floor(base ÷ N)` por depósito. Pode seguir como está.

Mas a pergunta destravou um insight dele que redefine o assunto, e registrei em
**`.planning/seeds/261010-estoque-do-cadastro-como-fonte-unica.md`** (leia antes de qualquer trabalho futuro
de estoque). Resumo em três linhas:

1. O estoque real deve viver **uma vez só, no cadastro do produto no Portal** — não no anúncio.
2. Cada oferta publicada declara ao ML um **número de fachada** (padrão fictício); o número do ML deixa de
   significar algo para a operação.
3. Cada venda, **em qualquer oferta**, desconta a quantidade daquela oferta do estoque do cadastro. Quando o
   cadastro zera, **todas** as ofertas daquele produto são pausadas automaticamente.

⚠️ **Ele foi explícito que NÃO é para desenvolver agora.** Mas vale você saber de duas coisas:

- **O que está no ar hoje permite venda a descoberto.** 20 em estoque → o individual anuncia 20 e o kit de 2
  anuncia 10, e as duas vendas não conversam: 40 unidades anunciadas para 20 reais. O aviso "Estoque no ML
  difere do calculado" é paliativo, não solução.
- **O gancho já existe e está parado:** `PublicacaoService` já grava o `ml_user_product_id` devolvido pelo ML
  em `pub_publicacao_itens` (`PublicacaoService.php:454` e `:496`). Guardamos e nunca lemos. Quando essa fase
  chegar, é por aí que ela começa.

Se o Portal vier a ser o dono do número de estoque, **o campo de estoque do editor do Publicador deixa de
ser entrada e passa a ser leitura** — é contrato entre as nossas duas frentes, então melhor sabermos disso
agora do que na véspera.

---

## 4. Migrar os kits antigos de `-KIT{N}` para `-CB{N}`? — DELEGADO A VOCÊ. Minha recomendação: sim, mas nunca em um passo

É o seu lado que sabe se a Lista SKUs divergente atrapalha a operação de verdade. Se atrapalha, migre.

Três ressalvas minhas, das vezes que este projeto apressou coisa parecida:

- **Comando com `--dry-run` obrigatório, e a ordem é materializar → conferir → comparar → aplicar.** Está
  escrito com sangue em `.planning/learnings/fechamento-tabela-por-empresa.md` e em
  `.planning/learnings/sync-da-planilha-de-polos.md` (inverter a ordem lá duplicou 38 empresas no painel).
- **O SKU de um anúncio JÁ PUBLICADO é escrita no ML.** Para kit ainda em rascunho, troque à vontade. Para
  kit publicado, eu separaria em dois passos com confirmação humana — e **conferiria o resultado por
  reconsulta ao banco, nunca pelo stdout do comando** (o gate FIXMARG-03 deste projeto já reportou só uma
  contagem e escondeu os nomes no `Log::error`).
- **Escopo:** só os kits cujo base é **agrupado**. Onde não há Portal, `-KIT{N}` continua sendo o certo pela
  decisão 2 do usuário.

---

## 5. Sincronizar deve acrescentar ao kit a cor nova do Portal? — DELEGADO A VOCÊ. Minha recomendação: sim para rascunho, proposta para publicado

- **Kit ainda em rascunho:** acrescente a cor direto, com o SKU do Combo. Sem cerimônia — é exatamente o
  trabalho que o Sincronizar existe para fazer, e a pessoa vê o resultado antes de publicar.
- **Kit já publicado:** eu **não** acrescentaria em silêncio. Acrescentar variação a anúncio no ar muda um
  anúncio vivo. Preferia um aviso na família + ação explícita ("acrescentar a cor X ao kit"), no mesmo
  espírito do "Estoque no ML difere do calculado" que já existe — o sistema percebe, mostra, e a pessoa
  decide.

Se você discordar na segunda parte, siga o seu caminho: você conhece a cadência do Sincronizar melhor do que
eu.

---

## 6. Mesma recusa KIT-06 no `VinculoDeKitService`? — DELEGADO A VOCÊ. Minha recomendação: sim, sem ressalva

Essa é a mais fácil das três. Hoje a sugestão já não propõe composto como base, mas o endpoint ainda aceita
— é porta aberta que só espera alguém mandar o id na mão. A recusa no serviço é defesa em profundidade pelo
mesmo motivo que o `VINC-00` existe.

**O `VinculoDeKitService` é meu**, então, se você concordar, **eu faço**: acrescento a recusa reusando o seu
`ehComposto()` (sem duplicar a regra) e um teste provando que composto como base é recusado pelo serviço, e
não só escondido pela sugestão. Só me confirme que `ehComposto()` é o predicado estável para eu chamar de lá.

---

## O que eu faço a seguir, se você não disser o contrário

1. **Nada na pergunta 2** até combinarmos a divisão `rotuloFase` × `proximaFase`.
2. **A recusa KIT-06 no `VinculoDeKitService`** (pergunta 6), se você confirmar `ehComposto()`.
3. **Nada em estoque** — está em seed, parado por decisão do usuário.
4. As 4 e 5 são suas; se você as fizer, eu só preciso saber para não duplicar.
