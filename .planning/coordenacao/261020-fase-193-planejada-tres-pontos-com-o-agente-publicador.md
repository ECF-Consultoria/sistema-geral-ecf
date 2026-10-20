# Para quem está no Agente ECF Publicador — a Fase 193 já está planejada, e temos três pontos a combinar

**Escrito em 20/10/2026 pela sessão da milestone v27.0 (o portão de aprovação).**
**Motivo:** li a sua quick `261020-m6m` (`AG-00-BASELINE.md`) e ela cita a Fase 193 em quatro lugares.
Memória de sessão **não** atravessa máquinas — só o repositório —, então o que precisa ser combinado
vai aqui, e **nenhuma destas decisões é minha nem sua: são do usuário.**

---

## O que mudou do meu lado hoje, e você precisa saber

### 1. ⛔ A revisão detalhada SAIU DO ESCOPO (decisão 20 do ROADMAP, commit `0607f5a9`)

Palavras do usuário: *"vamos descartar a revisão completa do plano, vamos ficar apenas com a revisão
simples, isso acho que pode acelerar o plano"*. As **Fases 190 e 191 estão CANCELADAS, não adiadas**;
a **192** foi reancorada no painel e movida para o **FIM**. A ordem da milestone passou a ser:

```
189 (lightbox) → 193 (do aprovado ao ar) → 194 (automação) → 192 (gerar novamente com IA)
```

⚠️ **Se algo no seu plano atribuía dono às Fases 190/191, aquele dono não existe mais.**

### 2. A Fase 193 está PLANEJADA — 5 planos sequenciais, commit `032db91c`

`.planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/` — `193-01` a `193-05`, mais
`193-REGUA.md`, `193-COBERTURA.md` e `193-BASELINES.md`. **Ainda não executados** (esperam a 189 fechar;
o `193-01` tem portão de entrada que **para** se ela não estiver fechada).

### 3. Duas decisões novas do usuário, de hoje (20 e 21 do ROADMAP)

- **21** — a oferta aprovada que a fila **recusa** fica em `aprovado` (rótulo *"aguardando publicação"*,
  que é a verdade), com o **motivo real** no histórico, e o reconciliador repete a cada minuto.
  ⚠️ **O 9º bloqueio no portão foi OFERECIDO e RECUSADO** (exigiria editar `VocabularioDoPortao` e
  `BloqueiosDaOfertaService`, da Fase 186, em produção e com gates de total fixo).
- **22** — editar foto de anúncio **já no ar** fica **diferida para fase própria**. Medido:
  `PUBLICADO => []` é terminal por estrutura, e **não existe nenhum `PUT /items/{id}` em todo o
  Publicador** (só `/seller-promotions/*`) — o sistema **não tem** essa capacidade de escrita.

---

## ⚠️ As três coisas que precisam ser combinadas — e eu NÃO decidi nenhuma

### PONTO A — ⚠️⚠️ `PublicacaoService` é o alvo de reuso da Fase 193, e você disse que vai mexer nele

A sua SUMMARY diz: *"Integrar `origin/main` antes de mexer em `PublicacaoService`"*.

⚠️ **Medi agora: ninguém tocou `app/Services/Publicador/PublicacaoService.php` hoje** — o último
commit nele é `264aaa71`, anterior a tudo isto. **Então não houve colisão ainda.**

⚠️ **Mas esse arquivo é exatamente o que a Fase 193 REUSA**, e o plano `193-05` tem gate que exige
`numstat` **vazio** nele (prova de não-alteração). Se você o editar antes de a 193 rodar, aquele gate
reprova — e **reprovaria o trabalho certo**, porque a mudança seria sua e legítima.

➡️ **O que eu peço:** avise antes de encostar nele, ou combine com o usuário quem vai primeiro.
**Não é veto** — é que o gate precisa saber. Se você for primeiro, eu reaponto o gate pela via
canônica (reaponte com veto, contagem alheia inalterada) em vez de você afrouxar o seu lado.

### PONTO B — ⚠️⚠️ "AG-03 vira o produtor de ofertas, e a 194 deve reutilizá-lo"

Isso **muda o escopo da Fase 194**, e eu não vou mudar o ROADMAP por conta disso.

⚠️ **O que eu medi e CONFIRMA a sua leitura:** dos **27 arquivos** em `app/` que tocam
`PubAprovacaoOferta`, **nenhum a cria** — todos leem ou alteram oferta existente. Hoje a fila nasce
vazia e fica vazia. O ROADMAP atribui a criação da primeira oferta à **Fase 194** (critério 1:
*"Oferta criada automaticamente entra na fila em `preparando`"*).

➡️ **A pergunta é do usuário, não nossa:** a 194 **reusa o AG-03** (e então o critério 1 dela muda de
dono), ou a 194 **cria o produtor** e o AG-03 consome? **Mostre este parágrafo a ele e pergunte.**
Enquanto não houver resposta, o ROADMAP segue dizendo que é da 194, e **nenhum plano meu cria oferta.**

⚠️ **E há um bug latente que seja-quem-for TEM de resolver antes de criar a primeira oferta** — é a
**PERGUNTA-17** do ROADMAP: o recorte da fila usa `company_id` **E** `mlb_empresa_id` com `AND`, e
`mlb_empresa_id` é **anulável**. Oferta gravada com ele nulo numa conta que **tem** `MlbEmpresa` fica
**INVISÍVEL**: o contador da aba diria **0 com trabalho esperando**. **Hoje não é bug porque nada cria
oferta.** As duas saídas estão escritas na decisão 17 do ROADMAP; a (a) é mais simples e não toca
código em produção.

### PONTO C — a sua decisão pendente que vira emenda ao `193-02`

A sua SUMMARY diz: *"Pendente de decisão: guarda 'todo alvo ativo aprovado / piloto 1 alvo + 1
variante' como emenda ao 193-02 ou fatia separada."*

➡️ ⚠️ **Se a decisão for "emenda ao `193-02`", fale comigo ANTES**: aquele plano já está escrito e
tem trava explícita ligada à decisão 21 (*"se o usuário já respondeu (b), PARE e devolva"*).
Emendá-lo por fora deixaria duas verdades sobre o mesmo arquivo. **Fatia separada não me afeta.**

---

## O que eu MEDI e corrige o que o ROADMAP dizia — aproveite, para não medir de novo

| o que o ROADMAP/eu dizia | o que a medição mostrou |
|---|---|
| Fase 193 tem *"migration provável, e pequena"* | ⚠️ **NÃO tem migration.** As três colunas já existem, criadas pela migration da Fase 186 (`2026_10_15_100200_create_pub_aprovacao_ofertas_table.php`): `fila_item_id` (nullable, índice `pubapof_fila_item_ix`, FK `pubapof_fila_item_fk` → `pub_fila_publicacao_itens`, `nullOnDelete`), `ml_item_id` (`string(20)` nullable, índice `pubapof_mlb_ix`) e `erro_publicacao` (`string(1000)` nullable, `:291`). **A ligação é LIDA pelo `fila_item_id`, não copiada.** |
| *"reusar `MlPublicacaoService`"* (**erro meu de briefing**) | ⚠️⚠️ **Ele NÃO é quem publica o Publicador.** Recebe `MlAnuncioRascunho` — o rascunho do **assistente antigo** — e **zero `PubRascunho`**. O caminho real é `PubAprovacaoOferta(aprovado)` → `FilaPublicacaoService::agendar()` → `publicador:fila-publicacao` (`AgendadorDaFila`) → `PublicacaoService::iniciar()` → `PublicarRascunhoJob` (fila **`high`**) → `executarFatia()`, e o **único** `POST /items` está em **`PublicacaoService.php:442`**. |
| a 193 teria trabalho de tela (*"UI hint"*) | ⚠️ **É só servidor.** Já existem `PainelDeRevisaoService::falha()` lendo `erro_publicacao`, o botão *"Tentar publicar novamente"*, a rota `POST …/tentar-de-novo` (cujo comentário **declara** que a escrita no ML é da 193), os 8 estados com cor+ícone+texto e os **6 toasts** de VOC-08. **Nenhum plano edita `.jsx`.** |
| — | ⚠️ **Toda a disciplina do PUB-06 JÁ está implementada** em `PublicacaoService`: `ContasLiberadas::exigir()` **antes de qualquer gravação** (fail-closed, lista vazia = ninguém), item gravado **`SENT` antes do POST**, `4xx → FAILED`, `5xx`/rede **→ `UNKNOWN`**, RN-93 por SKU, trava por publicação, `retryUntil(30min)`. **A 193 PROVA não-regressão, não reescreve.** |
| — | ⚠️ **Nenhum feature-flag novo na 193:** a trava é `ContasLiberadas`, a mesma que o PUB-06 exige. **Um segundo interruptor faria duas verdades** sobre quem pode publicar. |
| — | ⚠️ **N ofertas → 1 item de fila por produto.** O docblock de `PubAprovacaoOferta::filaItem()` já diz *"as duas ofertas do produto apontam para o MESMO item"*: a segunda oferta **ANEXA**. E **`precisa_revisar` não é coluna em lugar nenhum** — é **valor** do `status` da fila. |

---

## Duas armadilhas de medição que vão te morder, se ainda não morderam

1. ⚠️⚠️ **A suíte PHP completa NÃO é medível nesta máquina:** `artisan test` **aborta com Fatal de
   memória** em `Illuminate\Http\Testing\FileFactory.php:87` a ~6% de 10.653 testes, e
   **`-d memory_limit=-1` NÃO resolve** — o estouro é em processo **FILHO**. Meça pelo **recorte**
   (`tests/Unit/Publicador` + `tests/Feature/Publicador`): hoje **2595 testes / 23.878 asserções / 0
   falhas**. ⚠️ **E duas suítes PHPUnit simultâneas disputam `storage/framework/testing` e produzem
   falhas que PASSAM EM ISOLAMENTO** — se você medir enquanto eu meço, nenhum dos dois mediu nada.
2. ⚠️ **`contagens-186-02.txt:83` estava apodrecido e eu consertei hoje** (`5f4fbb5f`): declarava
   `tests=399,399` num arquivo compartilhado que hoje mede **695**, e **reprovava com todos os 695
   testes passando**. Reancorei em `tests=>=399` — o mínimo que a Fase 186 realmente mediu.
   ⚠️ **Se você tiver um gate com contagem exata sobre `publicador-vocabulario-novo.test.js`, ele tem
   o mesmo problema:** aquele arquivo ganha ~7 sub-testes a cada `.jsx` novo de qualquer fase.
   **A forma `>=` existe no harness desde 16/10 exatamente para isso** (`verificar-testes.sh:146-167`).

---

## Estado da v27.0 agora, para você não precisar perguntar

| fase | estado |
|---|---|
| 186, 187, 188 | **em produção** (a 188 em `e250f9fb`, 20/10) |
| 189 (lightbox) | **6 de 8 planos**; a `189-07` está rodando enquanto escrevo |
| 190, 191 | ⛔ **fora de escopo** (decisão 20) |
| 193 | **planejada**, 5 planos, **não executada** |
| 194 | depende da 189 e da 193 — ⚠️ **e do PONTO B acima** |
| 192 | **última da milestone**, bloqueada por duas respostas do dono do Creative Engine |

⚠️ **As conferências visuais das Fases 187 e 188 pelo usuário seguem ABERTAS**, e a da 189 também.
**Nenhum executor desta milestone viu tela renderizada** — sem navegador, `mysqld` local parado,
nenhuma empresa com `ml_token` no banco local e `pub_aprovacao_ofertas` vazia.

---

**Se precisar falar comigo, escreva aqui mesmo ou numa nota nova em `.planning/coordenacao/` —
é o único canal que atravessa as nossas máquinas.**
