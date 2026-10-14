<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10/2026 — Fase 186, o portão no servidor: **AJUSTE COM ESCOPO, HISTÓRICO E EXCEÇÃO
 * AUTORIZADA** (`pub_aprovacao_ajustes`, `pub_aprovacao_eventos`, `pub_aprovacao_excecoes`).
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2 / MIG-07), escrita ANTES de o código existir.
 * Só CRIA três tabelas; nenhuma tabela existente com dado muda, nenhuma linha é apagada, nenhuma
 * coluna é renomeada (MIG-09). Sem `enum()` (o CHECK é enforçado no SQLite dos testes e quebraria
 * a suíte — MIG-04), sem `->change()`, sem backfill.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 0. ⚠️⚠️ A DECISÃO QUE É O CORAÇÃO DESTE ARQUIVO — **NÃO EXISTE "EXCEÇÃO AGUARDANDO
 *    AUTORIZAÇÃO"** (BLOQ-09, decisão do usuário em **14/10/2026**)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A exceção de prejuízo/margem é aplicada **DIRETO** por quem revisa: marca, escreve o motivo
 * (**obrigatório**), e fica gravado **quem foi e quando**. **Aprovação em dois passos foi
 * OFERECIDA e RECUSADA pelo usuário em 14/10/2026.**
 *
 * ➡️ Em `pub_aprovacao_excecoes` **NÃO EXISTE** — e a ausência é DECISÃO, não esquecimento —
 * coluna `status`, `aprovada_por`, `solicitada_em`, `aguardando`, `pendente`, nem nada que
 * sugira um segundo passo. Não existe estado intermediário: a linha nasce **valendo**.
 *
 * **A RAZÃO (e ela é a razão de toda a milestone):** como **aprovar JÁ PUBLICA e não há
 * desfazer**, o controle da exceção é **auditoria DEPOIS, não barreira ANTES**. Uma barreira
 * antes só atrasaria quem tem a decisão na mão; o que precisa existir é o REGISTRO — quem
 * liberou, quando, com que motivo, em que versão. É por isso que `liberada_por`, `liberada_em`,
 * `justificativa` e `versao` são gravados na MESMA escrita, e por isso
 * `pub_aprovacao_eventos` é **append-only** (§3).
 *
 * ⚠️ O README do protótipo pede "fluxo de autorização do gestor". **Vale a decisão do usuário**,
 * e esta é das poucas divergências deliberadas do protótipo nesta fase.
 *
 * ⚠️ **ESTE PARÁGRAFO EXISTE PARA QUE A PRÓXIMA SESSÃO NÃO "COMPLETE" O FLUXO ACHANDO QUE
 * FALTOU.** Se um dia a coluna de espera for pedida, o lugar de reabrir é aqui — com o usuário,
 * não por dedução de quem lê o protótipo. Há gate de ausência ancorado em **FORMA** (não em
 * lista de palavras) nos testes deste plano, e o gate definitivo da área inteira é do plano
 * 186-09.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 1. `pub_aprovacao_ajustes` — A TABELA DE DECISÃO DE SCHEMA (DADO-09)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna        | tipo                      | regra / por quê                                                                                   |
 * |---------------|---------------------------|---------------------------------------------------------------------------------------------------|
 * | id            | bigint unsigned PK        |                                                                                                   |
 * | escopo        | string(8)                 | `offer` \| `set` \| `product` — ver §1.2. Sem `enum` (MIG-04); o maior é `product` = 7           |
 * | oferta_id     | unsignedBigInteger NULL   | FK `pubapaj_oferta_fk` -> pub_aprovacao_ofertas, **CASCADE**: ajuste de escopo `offer` sem oferta não significa nada |
 * | conjunto_id   | unsignedBigInteger NULL   | FK `pubapaj_conjunto_fk` -> pub_aprovacao_conjuntos, **CASCADE**: mesma razão, para o escopo `set` |
 * | produto_id    | unsignedBigInteger NULL   | FK `pubapaj_produto_fk` -> pub_produtos, **SET NULL**: o cadastro base pode sair sem levar o histórico do pedido (CR-B02) |
 * | item          | string(200)               | o alvo nomeado: "Imagem #3 · Ambientada · sala de estar", "Preço Premium", "Título"              |
 * | destino       | string(120)               | ⚠️ **DESTINO LEGÍVEL, nunca chave** (VOC-06): "Este anúncio (Premium)", "Conjunto de fotos (Clássico e Premium)", "Cadastro do produto (todas as ofertas)". O maior medido é **39 bytes** (38 caracteres — o `á` de "Clássico" ocupa 2 em UTF-8, e a coluna conta CARACTERES, não bytes) |
 * | texto         | text                      | o pedido em palavras de quem revisa. `text` porque não há limite de negócio e MOD-01 só proíbe VAZIO |
 * | autor_id      | unsignedBigInteger NULL   | FK `pubapaj_autor_fk` -> users, **SET NULL**: usuário apagado não apaga o pedido                 |
 * | aberto        | boolean default true      | o `open` do protótipo (`Aprovacao.dc.html:989`). ⚠️ Default repetido no `$attributes` do modelo — §5 |
 * | resolvido_por | unsignedBigInteger NULL   | FK `pubapaj_resolvido_fk` -> users, **SET NULL**                                                  |
 * | resolvido_em  | dateTime NULL             | `dateTime`, nunca `timestamp()` (convenção do módulo)                                             |
 * | timestamps    |                           |                                                                                                   |
 *
 * ─── 1.1 ⚠️ POR QUE **TRÊS ÂNCORAS NULAS** EM VEZ DE UMA COLUNA POLIMÓRFICA ────────────────────
 *
 * A consulta de bloqueio (`BLOQ-01`, chave `ajuste_em_aberto`) precisa juntar os TRÊS escopos
 * numa leitura **com índice** — é o `openC` do protótipo (`Aprovacao.dc.html:1086`), que filtra
 * `c.offer === o.id || (c.scope === 'set' && c.set === o.set) || (c.scope === 'prod' && c.prod
 * === prod)`. Com âncora polimórfica (`ancora_tipo` + `ancora_id`) aquele `OR` não aproveitaria
 * índice nenhum, **e não haveria FK**: nem `CASCADE` (ajuste órfão ficaria pendurado, contando
 * como bloqueio de uma oferta que não existe mais) nem `SET NULL`.
 *
 * ⚠️ **A regra "exatamente UMA âncora preenchida, conforme o escopo" é do MODELO/serviço
 * (`PubAprovacaoAjuste::escopoValido()`), e NÃO é CHECK de banco** — pela mesma razão do `enum`
 * (MIG-04): o CHECK é **enforçado no SQLite dos testes** e qualquer fixture que gravasse a linha
 * de outra forma quebraria a suíte inteira, longe de onde o erro está.
 *
 * ─── 1.2 ⚠️ `product` × `prod` — A ESCOLHA, COM `arquivo:linha` DOS DOIS ───────────────────────
 *
 * O protótipo grava **`'prod'`** no `scope` (`design_handoff_publicador/Aprovacao.dc.html:1187`,
 * `:1188`, `:1189` — os três itens de escopo de cadastro; e lê em `:1086`). O README e **DADO-09**
 * dizem **`product`**. ➡️ **`product` GANHA**, por DADO-09.
 * ⚠️ E **não** existe `||` aceitando as duas formas: `'prod'` é recusado por `escopoValido()`, com
 * teste. Aceitar as duas seria o bug dos dois idiomas com outra roupa — duas chaves para a mesma
 * verdade, livres para divergir.
 *
 * ─── 1.3 ⚠️ POR QUE AS CHAVES DE `escopo` **FICAM EM INGLÊS** (e a exceção NÃO) ────────────────
 *
 * A decisão do plano 186-02 traduziu para pt-BR **as 11 chaves de BLOQUEIO** (`SHORT` ∪ `BLK`),
 * porque lá havia dois lados (PHP e `.jsx`) com a MESMA verdade em idiomas diferentes, e o selo
 * renderizava VAZIO. Aquele mesmo módulo registra a regra complementar
 * (`resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:175-180`): as 13
 * chaves de `CRIT`/`DEF` **ficaram como estão**, porque "inventar chave nova aqui seria churn sem
 * nenhum bug para resolver".
 *
 * ➡️ `escopo` (`offer`/`set`/`product`) e `pub_aprovacao_eventos.tipo` (os 8 do §2) são
 * **namespaces NOVOS**, sem contraparte em lugar nenhum: ficam com os literais que DADO-09 e
 * DADO-10 fixaram.
 * ➡️ `pub_aprovacao_excecoes.tipo` é o caso OPOSTO e por isso é pt-BR — ver §3.1.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 2. `pub_aprovacao_eventos` — A TABELA DE DECISÃO DE SCHEMA (DADO-10)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna       | tipo                     | regra / por quê                                                                        |
 * |--------------|--------------------------|----------------------------------------------------------------------------------------|
 * | id           | bigint unsigned PK       |                                                                                        |
 * | oferta_id    | unsignedBigInteger       | FK `pubapev_oferta_fk` -> pub_aprovacao_ofertas, **CASCADE**. NOT NULL: evento sem oferta não é histórico de nada |
 * | tipo         | string(8)                | os 8 literais de `PubAprovacaoEvento::TIPOS` (`HK` do protótipo, `:1386`). Maior = `change`/`adjust`/`reject` = 6 |
 * | texto        | string(500)              | a frase REAL ("Alterou o preço de R$ 799,90 para R$ 749,90 · versão 3 · verificação de preço reaberta") |
 * | autor_id     | unsignedBigInteger NULL  | FK `pubapev_autor_fk` -> users, **SET NULL**. ⚠️ **NULL = Sistema** — §2.1              |
 * | ator_sistema | boolean default false    | o ator próprio `SYS` do protótipo (`:915`, `EMP.SYS = ['Sistema', 0]`) — §2.1           |
 * | versao       | unsignedInteger NULL     | em QUE versão da oferta o evento aconteceu. NULL = evento sem versão (ex.: `sys` de preparo) |
 * | ocorrido_em  | dateTime                 | **a data REAL do servidor** (COL-05) — `now()`, nunca data vinda do cliente             |
 * | timestamps   |                          | `created_at` é quando a LINHA nasceu; `ocorrido_em` é quando o FATO aconteceu. Não são a mesma pergunta |
 *
 * ─── 2.1 ⚠️ COL-05 — O AUTOR "SISTEMA" É ATOR PRÓPRIO, NÃO UM USUÁRIO INVENTADO ───────────────
 *
 * `autor_id = NULL` + `ator_sistema = true`. **Duas colunas e não uma**, de propósito: `autor_id`
 * nulo sozinho é ambíguo (usuário apagado pelo `SET NULL` também fica nulo), e um usuário de
 * banco chamado "Sistema" seria pior — apareceria em listas de gente, poderia logar, e alguém
 * acabaria atribuindo a ele uma aprovação de verdade.
 * ⚠️ O autor exibido nunca é vazio: `PubAprovacaoEvento::autorExibido()` devolve "Sistema" quando
 * `ator_sistema`, e tem teste. Nome em branco na linha do tempo é exatamente o buraco que o
 * "auditoria depois" não pode ter.
 *
 * ─── 2.2 ⚠️⚠️ MIG-09 — A TABELA É **APPEND-ONLY**, E ISSO É A PROVA DA AUDITORIA-DEPOIS ───────
 *
 * **NENHUMA linha de histórico é apagada por nada neste sistema.** Não há rotina de limpeza, não
 * há retenção, não há `delete`, não há `truncate` — e **não existe coluna de "apagado"/"oculto"**
 * que permitisse esconder um evento sem apagá-lo (T-186-18, tampering). A única porta de escrita
 * é `HistoricoDaOfertaService::registrar()`, e ela só INSERE.
 * ⚠️ `oferta_id` é CASCADE: se a oferta inteira sair, o histórico dela vai junto — isso **não** é
 * exceção ao append-only, é a ausência do sujeito. Enquanto a oferta existe, o histórico dela é
 * completo por construção.
 * ⚠️ **Não confundir com a retenção de 48 h dos criativos** (`LimparReferenciasCriativos`), que é
 * de arquivo de imagem e é território de outro dev. Aqui não há nada parecido, e não deve haver.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 3. `pub_aprovacao_excecoes` — A TABELA DE DECISÃO DE SCHEMA (DADO-11 / MOD-02)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * | coluna        | tipo                     | regra / por quê                                                                       |
 * |---------------|--------------------------|---------------------------------------------------------------------------------------|
 * | id            | bigint unsigned PK       |                                                                                       |
 * | oferta_id     | unsignedBigInteger       | FK `pubapex_oferta_fk` -> pub_aprovacao_ofertas, **CASCADE**. NOT NULL                 |
 * | tipo          | string(32)               | **pt-BR**: `resultado_negativo` \| `margem_abaixo_do_minimo` — §3.1. O maior tem **23**; 32 dá folga sem ALTER |
 * | justificativa | string(500)              | **OBRIGATÓRIA, ≥ 10 caracteres** (`PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA`). É O motivo escrito de BLOQ-09 |
 * | liberada_por  | unsignedBigInteger NULL  | FK `pubapex_liberada_fk` -> users, **SET NULL**: **QUEM liberou** (T-186-16, repúdio)  |
 * | liberada_em   | dateTime                 | **QUANDO** — `now()` do servidor, NOT NULL. Exceção sem carimbo não é auditável        |
 * | versao        | unsignedInteger          | **em QUE versão da oferta** foi liberada. NOT NULL: BLOQ-05 reabre a aprovação quando a versão sobe, e a exceção tem de poder ser lida contra a versão em que nasceu |
 * | ativa         | boolean default true     | nasce **valendo** (§0). ⚠️ Default repetido no `$attributes` do modelo — §5             |
 * | timestamps    |                          |                                                                                       |
 *
 * ⚠️ **NÃO EXISTE** aqui: `status`, `aprovada_por`, `solicitada_em`, `aguardando`, `pendente`,
 * `autorizada_por` — ver §0. `liberada_por` **é** quem autorizou; não há um segundo ator.
 *
 * ─── 3.1 ⚠️⚠️ `tipo` É pt-BR, E ISSO É **DIVERGÊNCIA DECLARADA DO PLANO** ─────────────────────
 *
 * O plano 186-05 escreveu `tipo ∈ {loss, margin}`. ➡️ **Aqui são
 * `resultado_negativo` / `margem_abaixo_do_minimo`.** A razão:
 *
 * `loss` e `margin` **são 2 das 11 chaves de BLOQUEIO**, e aquelas 11 foram traduzidas para pt-BR
 * pela decisão do plano 186-02
 * (`resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx:155-168`:
 * `BLOQUEIO_DO_PROTOTIPO = { loss: 'resultado_negativo', margin: 'margem_abaixo_do_minimo', … }`).
 * O `tipo` da exceção **nomeia qual bloqueio ela libera** — é uma chave estrangeira POR VALOR
 * naquele namespace. Gravar `loss` aqui recriaria o defeito que a wave 1 consertou: a mesma
 * verdade em dois idiomas, com um `||` aceitando as duas formas mais à frente.
 * ⚠️ E teria efeito **colateral nos gates**: com `LIBERA = ['loss','margin']`, o teste negativo do
 * plano (`libera('photos')`, `libera('freight')`, `libera('title')`…) seria **vazio por
 * construção** — aquelas chaves inglesas não estão no nosso namespace e seriam `false` por não
 * existirem, não por serem recusadas. O negativo que VALE usa as chaves pt-BR irmãs
 * (`fotos_a_conferir`, `frete_ausente`, `alteracao_nao_conferida`, `ajuste_em_aberto`,
 * `titulo_longo`), e há asserção SEPARADA de que `loss`/`margin` **também** são recusados.
 * ⚠️ A tradução mora em UM lugar só: `PubAprovacaoExcecao::TIPO_DO_PROTOTIPO`, no molde do
 * `BLOQUEIO_DO_PROTOTIPO`, e um teste **LÊ o `.jsx`** para provar que as duas pontas batem.
 *
 * ─── 3.2 ⚠️ DIVERGÊNCIA DECLARADA DO PROTÓTIPO — A EXCEÇÃO DELE **NÃO TEM TIPO** (achado S7) ──
 *
 * Medido em `design_handoff_publicador/Aprovacao.dc.html:1093-1094`: há **um único `!o.exc`**, e
 * ele suprime `loss` **e** `margin` de uma vez —
 *   `else if (f.result < 0 && !o.exc) B.push({ k: 'loss',  … })`
 *   `else if (f.margin < this.MIN && !o.exc) B.push({ k: 'margin', … })`
 * No protótipo a exceção é um **flag**, não um registro tipado (o `kind` existe em
 * `submitException`, `:1203`, mas só para montar a frase do histórico — não para decidir o que é
 * liberado).
 *
 * ➡️ **Aqui ela TEM tipo, e uma não libera a outra.** É escolha deliberada, por dois motivos:
 * **(a) BLOQ-08** diz "exceção autorizada libera só `loss` e `margin`" — tipada, o conjunto
 * liberado é exatamente o que foi autorizado; **(b) BLOQ-09**: liberar "resultado negativo" e
 * liberar "margem abaixo do mínimo" são **dois atos diferentes, com justificativas diferentes**, e
 * **não há desfazer**. Com o flag único, autorizar margem liberaria prejuízo de graça.
 * ⚠️ O nosso comportamento é **mais restrito** que o do protótipo, nunca mais permissivo — e tem
 * teste do caso em que os dois diferem (exceção `resultado_negativo` **não** libera
 * `margem_abaixo_do_minimo`).
 * ⚠️ A regra da fase é "o protótipo ganha" para a **FORMA** e os **RÓTULOS** — e ela continua
 * valendo: a frase exibida é a dele (`:1142`, "Exceção autorizada por X · data" + a justificativa
 * entre aspas curvas, com o glifo `§` de `HK.exc`, `:1386`). O que divergiu é a **granularidade de
 * um registro de auditoria**, que não é forma nem rótulo.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 4. ⚠️ **NENHUMA COLUNA DECIMAL NAS TRÊS TABELAS, E ISSO NÃO É DESCUIDO**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * O modo de falhar de `decimal(p,s)` neste projeto é **ASSIMÉTRICO**: estourar a escala dá **erro
 * 1264 no MySQL/MariaDB** e **grava CALADO, truncado, no SQLite dos testes** — então a suíte fica
 * verde e o deploy quebra (ou, pior, grava número errado em produção). E a escala não é teórica:
 * já medimos **+20738%** neste projeto, o que estoura `decimal(7,3)` (limite ±9999,999) com folga.
 *
 * ➡️ Aqui não há percentual nem dinheiro gravado: o prejuízo e a margem que a exceção libera são
 * **LIDOS** da composição (plano 186-07), e a exceção guarda só `tipo` + `justificativa` + autoria.
 * O valor em R$ que aparece na frase do histórico vai como **texto já formatado** em
 * `pub_aprovacao_eventos.texto`, que é a frase REAL mostrada, não base de cálculo.
 * ⚠️ Se um dia alguém acrescentar um percentual em qualquer destas três tabelas, tem de **voltar a
 * este parágrafo** e dar folga de uma ordem de grandeza.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 5. ⚠️ OS DEFAULTS SÃO REPETIDOS NO `$attributes` DOS MODELOS — DEFEITO REAL, NÃO ZELO
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * O Eloquent **não lê o default do banco**. Foi defeito medido no plano 186-04: criar a linha sem
 * o campo devolvia **null em memória** enquanto o banco gravava o default, e a primeira decisão
 * tomada sobre o objeto em memória saía errada. Aqui vale para `pub_aprovacao_ajustes.aberto`
 * (ajuste nasceria "não aberto" em memória e **não contaria como bloqueio**) e
 * `pub_aprovacao_excecoes.ativa` (a exceção nasceria sem valer — e como **aprovar já publica**, o
 * operador veria o bloqueio voltar sem explicação).
 * ➡️ Os dois modelos repetem o default em `$attributes`, e há gate comparando com o default lido
 * do **SCHEMA VIVO** (nunca com outra constante do PHP — comparar PHP com PHP não acusa
 * divergência nenhuma).
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 6. NOMES DE ÍNDICE E DE FK, COM OS CARACTERES **MEDIDOS POR `strlen`** (MIG-02)
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * O MariaDB recusa nome acima de **64** (erro **1059**), o SQLite aceita, e a migration fica
 * **`Pending` com a tabela JÁ criada** — o pior estado possível
 * (`project_mariadb_nome_indice_64` nos learnings). ⚠️ Nada aqui é contado à mão: os números
 * abaixo saíram de `strlen` em 15/10/2026, o guarda recalcula por `strlen` a cada rodada, e o
 * MAIOR de cada grupo está TRAVADO no teste (o plano 186-03 mediu o plano dele errando em 1 num
 * número destes).
 *
 *   NOMEADOS À MÃO (padrão do módulo — nenhum `->constrained()`), **18** nomes:
 *     pubapaj_oferta_ix ........ 17      pubapaj_oferta_fk ........ 17
 *     pubapaj_conjunto_ix ...... 19      pubapaj_conjunto_fk ...... 19
 *     pubapaj_produto_ix ....... 18      pubapaj_produto_fk ....... 18
 *     pubapaj_autor_ix ......... 16      pubapaj_autor_fk ......... 16
 *     pubapaj_resolvido_ix ..... 20      pubapaj_resolvido_fk ..... 20
 *     pubapev_linha_ix ......... 16      pubapev_oferta_fk ........ 17
 *     pubapev_tipo_ix .......... 15      pubapev_autor_fk ......... 16
 *     pubapev_autor_ix ......... 16      pubapex_oferta_fk ........ 17
 *     pubapex_oferta_ix ........ 17      pubapex_liberada_fk ...... 19
 *   ➡️ o MAIOR declarado é **20** (`pubapaj_resolvido_ix` / `pubapaj_resolvido_fk`).
 *
 *   NOMES **PADRÃO DO LARAVEL** (`<tabela>_<coluna>_foreign`) — contados porque são os que
 *   ESTOURAM, e **nenhum é usado** (toda FK aqui é nomeada à mão, e `->constrained()` é proibido
 *   com gate). `pub_aprovacao_ajustes` = 21, `pub_aprovacao_eventos` = 21,
 *   `pub_aprovacao_excecoes` = 22:
 *     pub_aprovacao_ajustes_oferta_id_foreign ......... 39
 *     pub_aprovacao_ajustes_conjunto_id_foreign ....... 41
 *     pub_aprovacao_ajustes_produto_id_foreign ........ 40
 *     pub_aprovacao_ajustes_autor_id_foreign .......... 38
 *     pub_aprovacao_ajustes_resolvido_por_foreign ..... 43   ← o maior; passa
 *     pub_aprovacao_eventos_oferta_id_foreign ......... 39
 *     pub_aprovacao_eventos_autor_id_foreign .......... 38
 *     pub_aprovacao_excecoes_oferta_id_foreign ........ 40
 *     pub_aprovacao_excecoes_liberada_por_foreign ..... 43
 *
 * ⚠️ **QUATRO destes números estavam ERRADOS por 1 na primeira redação** (os quatro acima que
 * terminam em 41/40/38/38 tinham sido escritos como 42/41/39/39). Foram corrigidos **pela medição
 * por `strlen`**, não por recontagem à mão — é o mesmo erro de ±1 que o plano 186-03 mediu, e a
 * razão pela qual o guarda recalcula em vez de ler estes números.
 *
 * ⚠️ **`liberada_por` é a única FK SEM índice declarado à mão, e é deliberado:** ela nunca é
 * filtro de consulta (a tela EXIBE quem liberou, não busca por isso), e o MariaDB cria sozinho o
 * índice de apoio da FK com o nome da própria constraint (`pubapex_liberada_fk`, **19**, já
 * contado acima). `resolvido_por` TEM índice próprio (`pubapaj_resolvido_ix`) porque a leitura
 * "quem resolveu o quê" filtra por ele.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * 7. A DISCIPLINA RESTANTE (MIG-01..09), ITEM A ITEM
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * - **MIG-01** — **produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite**, e o
 *   SQLite é o que MENOS pega. A prova de `migrate → rollback → migrate` é no MariaDB local, com
 *   `--path` só desta migration, e os índices/FKs são conferidos por `information_schema` — nunca
 *   por stdout.
 *   ⚠️⚠️ **E O `rollback` TAMBÉM VAI COM `--path`, NUNCA COM `--step=1`.** Isto é incidente
 *   MEDIDO em 15/10/2026, não precaução: o banco local é **compartilhado** entre as sessões, e o
 *   plano 186-06 estava rodando a MESMA prova em paralelo. Os dois `migrate:rollback --step=1`
 *   se cruzaram e **cada um derrubou a migration do outro** — `--step` escolhe "o topo da tabela
 *   `migrations`" no instante em que roda, e o topo pode ser de outra sessão. Com
 *   `migrate:rollback --path=<este arquivo>` o alvo é explícito e a corrida desaparece.
 *   ⚠️ Em produção isso **não** aparece (uma sessão só), e é por isso que ninguém tropeça nisso
 *   até ter dois executores em paralelo.
 * - **MIG-03** — toda FK `nullOnDelete()` tem `->nullable()` **EXPLÍCITO** (erro **1830** do
 *   MariaDB, invisível no SQLite): `produto_id`, `autor_id`, `resolvido_por` (ajustes),
 *   `autor_id` (eventos) e `liberada_por` (exceções). As CASCADE de `oferta_id`/`conjunto_id` em
 *   `pub_aprovacao_ajustes` **também** são nullable — ali por outro motivo (só UMA âncora é
 *   preenchida por linha, §1.1) —, e as CASCADE de `oferta_id` em eventos/exceções são NOT NULL de
 *   propósito.
 * - **MIG-04** — sem `enum()`: `escopo` e os dois `tipo` são `string` + constantes no modelo.
 * - **MIG-05** — `up()` idempotente por `Schema::hasTable`, **tabela por tabela**: se o deploy
 *   morrer entre a primeira e a terceira, a re-execução completa só o que faltou. **Sem
 *   `try/catch` em volta de DDL** — neste projeto um 1553 ficou engolido por 2 meses dentro de um
 *   catch, e um gate que CONTE a palavra `try` reprovaria por esta própria frase, então o guarda
 *   lê o CÓDIGO sem comentários, pelo tokenizador do PHP.
 * - **MIG-08** — os índices são declarados **ANTES** das FKs: o MariaDB usa como apoio o índice
 *   que a coluna lidera.
 * - **MIG-09** — nenhuma linha apagada, nenhuma coluna renomeada, nenhum backfill. E o histórico é
 *   append-only (§2.2).
 * - `down()` derruba as **TABELAS** por `dropIfExists`, que subsume o par
 *   `dropForeign(['coluna'])`/`dropIndex` — aquele é a receita de migration **ADITIVA**, não de
 *   `create`. **Não há `dropIndex` aqui, e é por isso que não há `dropForeign` tampouco.**
 *   ⚠️ **A ORDEM É FILHA → MÃE**, e aqui ela é entre as três desta migration: nenhuma das três
 *   referencia outra, então a ordem é o INVERSO exato da criação (`excecoes`, `eventos`,
 *   `ajustes`) — a ordem segura por construção, independente de quem referencia quem. As
 *   tabelas-MÃES (`pub_aprovacao_ofertas`, `pub_aprovacao_conjuntos`, `pub_produtos`, `users`)
 *   são de outros planos e **nenhuma é derrubada aqui**.
 *
 * ⚠️ `pub_aprovacao_ofertas` TEM de existir antes desta migration (três FKs apontam para ela). Ela
 * vem de `2026_10_15_100200_create_pub_aprovacao_ofertas_table.php` (plano 186-04, wave 2), e está
 * aplicada no local — CONFERIDO em 15/10/2026. `pub_aprovacao_conjuntos` vem de
 * `2026_10_15_100000_...` (plano 186-03). ⚠️ Se uma destas FKs falhar por tabela ausente, a
 * resposta **não é tirar a FK**: é aplicar a migration da mãe primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_aprovacao_ajustes')) {
            Schema::create('pub_aprovacao_ajustes', function (Blueprint $t) {
                $t->id();
                $t->string('escopo', 8);
                // ⚠️ As três âncoras são anuláveis porque **exatamente uma** é preenchida por
                // linha, conforme o escopo (§1.1). A regra é do modelo, não CHECK de banco.
                $t->unsignedBigInteger('oferta_id')->nullable();
                $t->unsignedBigInteger('conjunto_id')->nullable();
                $t->unsignedBigInteger('produto_id')->nullable();
                $t->string('item', 200);
                $t->string('destino', 120);
                $t->text('texto');
                $t->unsignedBigInteger('autor_id')->nullable();
                $t->boolean('aberto')->default(true);
                $t->unsignedBigInteger('resolvido_por')->nullable();
                $t->dateTime('resolvido_em')->nullable();
                $t->timestamps();

                // ⚠️ Índices ANTES das FKs (MIG-08). Os três primeiros lideram com a âncora e
                // carregam `aberto`: é a consulta de bloqueio dos 3 escopos numa leitura (§1.1).
                $t->index(['oferta_id', 'aberto'], 'pubapaj_oferta_ix');
                $t->index(['conjunto_id', 'aberto'], 'pubapaj_conjunto_ix');
                $t->index(['produto_id', 'aberto'], 'pubapaj_produto_ix');
                $t->index('autor_id', 'pubapaj_autor_ix');
                $t->index('resolvido_por', 'pubapaj_resolvido_ix');

                $t->foreign('oferta_id', 'pubapaj_oferta_fk')->references('id')->on('pub_aprovacao_ofertas')->cascadeOnDelete();
                $t->foreign('conjunto_id', 'pubapaj_conjunto_fk')->references('id')->on('pub_aprovacao_conjuntos')->cascadeOnDelete();
                $t->foreign('produto_id', 'pubapaj_produto_fk')->references('id')->on('pub_produtos')->nullOnDelete();
                $t->foreign('autor_id', 'pubapaj_autor_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('resolvido_por', 'pubapaj_resolvido_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_eventos')) {
            Schema::create('pub_aprovacao_eventos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('oferta_id');
                $t->string('tipo', 8);
                $t->string('texto', 500);
                // ⚠️ NULL = Sistema, e `ator_sistema` é quem desambigua (§2.1).
                $t->unsignedBigInteger('autor_id')->nullable();
                $t->boolean('ator_sistema')->default(false);
                $t->unsignedInteger('versao')->nullable();
                $t->dateTime('ocorrido_em');
                $t->timestamps();

                $t->index(['oferta_id', 'ocorrido_em'], 'pubapev_linha_ix');
                $t->index(['oferta_id', 'tipo'], 'pubapev_tipo_ix');
                $t->index('autor_id', 'pubapev_autor_ix');

                $t->foreign('oferta_id', 'pubapev_oferta_fk')->references('id')->on('pub_aprovacao_ofertas')->cascadeOnDelete();
                $t->foreign('autor_id', 'pubapev_autor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('pub_aprovacao_excecoes')) {
            Schema::create('pub_aprovacao_excecoes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('oferta_id');
                $t->string('tipo', 32);
                $t->string('justificativa', 500);
                $t->unsignedBigInteger('liberada_por')->nullable();
                $t->dateTime('liberada_em');
                $t->unsignedInteger('versao');
                $t->boolean('ativa')->default(true);
                $t->timestamps();

                $t->index(['oferta_id', 'ativa', 'tipo'], 'pubapex_oferta_ix');

                $t->foreign('oferta_id', 'pubapex_oferta_fk')->references('id')->on('pub_aprovacao_ofertas')->cascadeOnDelete();
                $t->foreign('liberada_por', 'pubapex_liberada_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Derruba as TRÊS tabelas, na ordem **INVERSA da criação** — que aqui é a ordem filha→mãe
     * segura por construção, já que nenhuma das três referencia outra. Nenhuma das 4 tabelas-MÃES
     * (`pub_aprovacao_ofertas`, `pub_aprovacao_conjuntos`, `pub_produtos`, `users`) é tocada.
     */
    public function down(): void
    {
        Schema::dropIfExists('pub_aprovacao_excecoes');
        Schema::dropIfExists('pub_aprovacao_eventos');
        Schema::dropIfExists('pub_aprovacao_ajustes');
    }
};
