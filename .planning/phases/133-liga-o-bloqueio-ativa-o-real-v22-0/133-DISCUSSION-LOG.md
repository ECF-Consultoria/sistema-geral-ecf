# Phase 133: Liga o bloqueio — ativação real — Discussion Log

**Data:** 2026-08-18
**Modo:** discuss (padrão, interativo)

> Registro humano da conversa. **Não é consumido** por researcher, planner ou executor —
> esses leem o `133-CONTEXT.md`.

---

## Antes das perguntas — o que o scout estabeleceu

Três medições feitas no código e em produção **antes** de qualquer pergunta, porque elas
mudavam as opções que fazia sentido oferecer:

1. `servicoDisparaImplementacao()` só mapeia **Polos, Assessoria e Incubadora** — os outros
   seis serviços exigem contrato mas nunca geram ficha operacional.
2. Em produção: **486 fichas, todas `POLO`**; nenhuma de Assessoria ou Incubadora; 4 de 190
   empresas com ficha vinculada.
3. `EmpresaOperacionalRouter::rotear()` **não implementa a exceção de Polos** — ela é um
   comentário marcando "ponto de extensão". Ligar a chave hoje quebraria Polos.

O item 3 foi descoberto **durante** a discussão, depois de a primeira decisão já ter sido
tomada, e mudou a leitura de "a fase não tem efeito" para "a fase impede um estrago".

---

## Área 1: Alcance real do bloqueio

**Pergunta 1** — Serviços como Gestão exigem contrato mas nunca geraram ficha. É intenção ou
lacuna?

- Opções: "É intenção — só esses 3 têm ficha" / "É lacuna — falta ficha para os outros" /
  "Não sei — preciso entender melhor"
- **Resposta do usuário:** pediu explicação — *"o que é ficha operacional? Achei que no serviço
  de gestão?"*
- **Ação:** a pergunta tinha usado um termo do código como se fosse óbvio. Foi refeita depois de
  medir o que a ficha é na prática (linha em `mlb_empresas` + checklist que gera o link público
  de implantação) e de mostrar os números de produção.

**Pergunta 1 (refeita)** — Com o quadro medido, o que a fase deve entregar?

- Opções: "A trava pronta, mesmo sem efeito hoje" / "Primeiro entender onde o contrato deveria
  travar" / "Preciso pensar"
- **Resposta:** **A trava pronta, mesmo sem efeito hoje** → D-01

**Pergunta 2** — Onde a exceção "exige contrato?" deve decidir?

- Opções: "Por serviço, dentro do laço (Recomendado)" / "Por empresa — se algum serviço exige,
  segura tudo" / "Você decide"
- **Resposta:** **Por serviço, dentro do laço** → D-02
- Nota: a opção "por empresa" foi apresentada com a ressalva de que contraria o SC 2b quando a
  empresa tem Polos junto.

---

## Área 2: Fechar a porta dos fundos (FLUXO-09)

**Pergunta 3** — Como fechar `MlbController::ativarEmpresaPendente()`?

- Opções: "Checagem da chave no método (Recomendado)" / "Refatorar para passar pelo router" /
  "Extrair a regra e chamar dos dois"
- **Resposta:** **Checagem da chave no método** → D-03
- Nota registrada na hora: a escolha duplica a regra em dois lugares, e por isso o plano precisa
  de teste nos dois caminhos.

---

## Área 3: O que a pessoa vê ao ser bloqueada

**Pergunta 4** — Quem precisa saber, e onde?

- Opções: "Quem cadastrou, na hora (Recomendado)" / "Uma lista de empresas retidas" / "Só o log
  basta"
- **Resposta:** **Uma lista de empresas retidas**
- **Ação:** antes de tratar como trabalho novo, foi conferido se a lista já existia. **Existia** —
  `ContratoAdminController::index()` da Fase 131 já lista empresas com serviço que exige
  contrato, com status, dias parado e causa. A pergunta foi refeita.

**Pergunta 4 (refeita)** — Dado que a lista já existe, o que a 133 acrescenta?

- Opções: "Um aviso de que o bloqueio está ligado (Recomendado)" / "Aviso + marca por linha" /
  "Nada na tela"
- **Resposta:** **Um aviso de que o bloqueio está ligado** → D-04

---

## Área 4: Como ligar em produção

**Pergunta 5** — Como ligar, e o que serve de prova?

- Opções: "Ligar e provar com Polos real (Recomendado)" / "Ligar em janela combinada" / "Deixar a
  chave desligada nesta fase"
- **Resposta do usuário:** pediu explicação — *"se eu ligar a chave o que irá acontecer, e por
  que o teste tem que ser em Polos sendo que empresas de serviço Polos não têm contrato?"*
- **Ação:** explicado que é justamente **porque** Polos não tem contrato que ele é o risco: a
  chave barra quem não tem contrato assinado, e Polos nunca terá. Sem a exceção, a chave o
  bloqueia para sempre. O teste com Polos não testa o bloqueio — testa a **exceção**.

**Pergunta 5 (refeita)** — Com isso claro, como conduzir?

- Opções: "Ligar e conferir o próximo Polos real" / "Ligar só depois de eu cadastrar um Polos de
  teste" / "Entregar tudo, mas não ligar agora"
- **Resposta:** **Ligar e conferir o próximo Polos real** → D-05
- Nota: diferente da Fase 132, o usuário recusou criar empresa fictícia — prefere esperar o
  cadastro real.

---

## Encerramento

**Pergunta 6** — Falta alguma zona cinzenta?

- **Resposta:** **Pronto — escreva o CONTEXT**

---

## Duas perguntas minhas que precisaram ser refeitas

Vale registrar, porque é padrão a evitar: **duas das seis perguntas usaram vocabulário do código
como se fosse compartilhado** ("ficha operacional") ou pularam o raciocínio que tornava a opção
compreensível (por que Polos é o caso de teste). Nos dois casos o usuário pediu explicação, e nos
dois a resposta veio de **medir**, não de reformular a frase. A regra do projeto contra jargão
(UI-06) vale também para a conversa, não só para a tela.

## Ideias adiadas

- Roteamento operacional para os outros seis serviços — capacidade nova, fase própria.
- Bloqueio retroativo para empresas já cadastradas sem contrato.
- Marca por linha na tela distinguindo retida de isenta.

## Itens deixados a critério do Claude

- Texto e posição da faixa de aviso.
- Forma da recusa no FLUXO-09 (flash, 403, mensagem) — desde que sem jargão.
- Estrutura dos testes, cobrindo os dois caminhos da D-03 e o caso Polos-com-chave-ligada.
