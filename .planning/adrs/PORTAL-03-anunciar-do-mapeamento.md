---
id: PORTAL-03
title: Anunciar — publicar o par Clássico + Premium pelo Mapeamento Estrutural
status: accepted
date: 2026-09-29
---

# ADR PORTAL-03 — Anunciar (publicação do par pelo Portal do Cliente)

## Contexto

O submódulo 6 do Mapeamento Estrutural fecha o caminho de quem começa do
zero: Lista SKUs → Precificação → Anúncios → Planejamento → Mapeamento →
**Anunciar**. Até aqui o cliente planejava (título de cada tipo na aba
Anúncios, preço na Precificação); agora ele publica no Mercado Livre sem sair
do portal. Decisões do usuário (29/09/2026), que não se reabrem:

| Pergunta | Decisão |
|---|---|
| Quem publica? | Cliente **e** equipe (sessão de equipe). O activity log grava `origem` pelo `RegistroEstrutura` |
| Quando o "Publicar" libera? | Só depois que o **próprio ML** aprovar o par (`POST /items/validate` sem erro nos dois) |
| Escopo | O **PAR** de uma oferta num formulário só. Categoria, ficha, fotos, estoque, condição, envio e descrição uma vez; título e preço por tipo, pré-preenchidos de Anúncios e Precificação |
| Fotos | Upload do computador; cada arquivo sobe pelo `MlImagemService::enviar` e o portal guarda `{id, url}` |
| Tela | Nova, no padrão visual do Mapeamento — **não** é a do `/mlb/anuncios` |

## O que se reaproveita, e o que não

O motor de publicação do módulo admin fica **intacto**: `builderPara()->montar()`
(Clássico × User Products), `MercadoLivreService::post()`, `MlImagemService`,
`MlCatalogoMetaService` e `MlItemPayloadValidator::traduzir()`. O que **não**
se reaproveita são `MlPublicacaoService::validar()`/`publicar()`: os dois
recebem `MlAnuncioRascunho`, cuja tabela tem `user_id NOT NULL` com FK para
`users` — e o portal usa o guard `portal` (o cliente não é `User`). Alterar
`ml_anuncio_rascunhos` seria migration em tabela com dado em produção (fase
GSD obrigatória). Por isso o portal ganha um service próprio,
`EstruturaPublicacaoService`, que compõe as mesmas peças.

`MlCompatibilidadeService::aplicar()` fica de fora de propósito: o formulário
não coleta veículos compatíveis (autopeças), e chamá-lo com lista vazia é um
no-op. Se um dia o par precisar de compatibilidades, é um bloco novo no
formulário e uma chamada best-effort depois do POST — como no admin.

## Schema — uma tabela NOVA, nenhuma alteração em tabela existente

### `estrutura_publicacoes` — o par de UMA oferta

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `oferta_id` | FK `estrutura_ofertas`, `cascadeOnDelete`, **unique** | um par por oferta. A empresa vem pela oferta (como `estrutura_anuncios`, ADR PORTAL-01) |
| `status` | varchar(12) NOT NULL default `rascunho` | `rascunho` · `validado` · `publicando` · `publicado` · `parcial` · `erro` — constantes no model, nada de `enum` |
| `dados` | json NULL | o formulário: categoria, atributos, fotos `[{id,url}]`, estoque, condição, envio, embalagem, garantia, descrição e, por tipo, título e preço (ver §Dados) |
| `erros` | json NULL | a última conferência ou falha, por tipo, já traduzida (`MlItemPayloadValidator`) |
| `validado_hash` | varchar(64) NULL | sha256 dos dados **efetivos** no momento em que o ML aprovou o par (ver §Conferência) |
| `ml_item_classico` | varchar(20) NULL | o MLB do Clássico, gravado no instante em que o `POST /items` devolve o id |
| `ml_item_premium` | varchar(20) NULL | idem, Premium |
| `publicando_em` | **dateTime** NULL | quando a trava `publicando` foi tomada — para soltar uma trava órfã |
| `publicado_em` | **dateTime** NULL | quando os DOIS ficaram no ar |
| `created_at`/`updated_at` | `timestamps()` (nullable) | |

Nomes curtos e explícitos: FK `epub_oferta_fk`, unique `epub_oferta_uq`.
Nenhum `timestamp()` fora do `timestamps()` (o MariaDB põe `ON UPDATE
CURRENT_TIMESTAMP` na primeira TIMESTAMP NOT NULL — `portal-do-cliente.md`
§18); nenhum `nullOnDelete`; a migration roda no MariaDB local com `--path`.

**Por que os dois MLB são colunas, e não chaves dentro de `dados`:** eles são
o que a trava de idempotência consulta ("já existe? então não republica") e o
que a tela lista. Num JSON, a garantia dependeria de quem escreve o JSON.

**Por que não há `company_id`:** a empresa vem pela oferta; duplicar criaria
duas verdades (ADR PORTAL-01). Toda leitura parte de
`EstruturaOferta::where('company_id', PortalContexto::empresa()->id)`.

### Dados (`dados` json) — forma canônica, montada num lugar só

`EstruturaPublicacaoService::normalizar()` é a única função que produz o JSON,
sempre na mesma ordem de chaves (é sobre isso que o hash é calculado):

    categoria_id, categoria_nome, categoria_origem (sugerida | escolhida),
    atributos { ID: {value_id, value_name} }, fotos [{id, url}],
    estoque, condicao (new | used), envio {modo (me2 | not_specified), frete_gratis},
    embalagem {peso_g, altura_cm, largura_cm, comprimento_cm}, garantia, descricao,
    tipos { classico: {titulo, preco}, premium: {titulo, preco} }

**Dados efetivos = rascunho + padrões do resto do módulo.** Título vazio de um
tipo vale o título planejado daquele tipo na aba Anúncios; preço vazio vale o
`anunciado` da Precificação (`PrecificacaoEstrutura`, no PHP — a tela nunca
recalcula preço). O rascunho só guarda o que a pessoa digitou; se ela não
mexeu, a Precificação continua mandando no preço — mudar o imposto da empresa
muda o preço que vai para o ML. É o mesmo princípio do ADR PORTAL-02 (preço
não é coluna).

`GET` nunca grava: abrir a oferta devolve os dados efetivos e a categoria
sugerida pelo título (`domain_discovery`), mas quem persiste é o primeiro
salvamento do cliente. Consequência visível: na lista da esquerda, "falta
categoria" só some depois que a oferta foi aberta uma vez — a lista não chama
o preditor para 25 ofertas.

## Conferência (validado_hash) — o servidor não confia no botão

O "Publicar" da tela fica desabilitado até a conferência passar e enquanto
houver mudança não conferida. Mas botão desabilitado é decisão do navegador.
A garantia mora no servidor: `validar()` grava o sha256 dos dados efetivos que
o ML aprovou; `publicar()` recalcula o hash dos dados efetivos **de agora** e
recusa se for diferente. Isso cobre também o que muda fora do formulário: um
título alterado na aba Anúncios ou um imposto alterado na Precificação mudam o
preço/título efetivo, o hash deixa de bater, e o par precisa ser conferido de
novo antes de ir para o ar.

A conferência tem duas partes, na ordem: pendências **locais** (categoria,
título e preço de cada tipo que ainda não foi publicado, títulos iguais, foto,
estoque, atributos obrigatórios da categoria) — sem tocar o ML — e, só quando
não há nenhuma, `POST /items/validate` com o payload de cada tipo que falta.
Aviso (`type: warning`) não bloqueia; erro bloqueia.

## Nunca publicar duas vezes

Duplo clique, duas abas, ou dois usuários (cliente e equipe) na mesma oferta:

1. **Trava atômica no banco.** `UPDATE estrutura_publicacoes SET status =
   'publicando', publicando_em = now() WHERE id = ? AND (status IN ('validado',
   'parcial', 'erro') OR (status = 'publicando' AND publicando_em < now − 15
   min))`. Só quem afetou **1 linha** publica; os outros recebem 422 ("já está
   sendo publicada" / "já foi publicada"). Funciona no SQLite dos testes e no
   MariaDB sem depender de cache. `rascunho` nunca entra: não foi conferido.
2. **Trava órfã solta em 15 min.** Um request que morre no meio (timeout do
   PHP, worker reiniciado) deixaria a oferta presa em `publicando` para
   sempre. Depois de 15 minutos a trava pode ser retomada; como cada MLB é
   gravado no instante em que o POST devolve o id (regra 3), a retomada
   publica só o que ainda não tem código. A janela real de risco é entre o ML
   devolver o id e o `UPDATE` da coluna — milissegundos, e só se o processo
   morrer exatamente ali.
3. **Cada MLB é gravado antes de qualquer outra coisa.** Descrição, registro
   na aba Anúncios e activity log vêm DEPOIS de `ml_item_<tipo>` estar no
   banco. Se qualquer passo seguinte falhar, o item já existe no ML e o
   sistema sabe disso.
4. **O que já tem MLB nunca é reenviado.** `publicar()` pula todo tipo cuja
   coluna `ml_item_<tipo>` está preenchida — é isso que faz o "tentar de
   novo" do `parcial` publicar só o que falta.

## Só o que falta na régua

Os tipos que uma publicação envia saem da **régua** (`EstruturaPublicacaoService::tiposPendentes()`):
tipo sem MLB que conte em `estrutura_anuncios` (importado, colado ou publicado
aqui) e sem código nesta linha. Uma oferta "Falta Premium" com o Clássico
importado publica **só o Premium** — enviar os dois criaria um segundo
Clássico no ML, duplicata que o módulo permite registrar, mas que o Anunciar
nunca deve produzir. Oferta OK (os dois no ar) não confere nem publica, e o
preditor de categoria não é chamado para ela. A conferência local também só
cobra título e preço dos tipos que vão ser enviados.

## Falha parcial

Clássico publicou e Premium falhou (ou o contrário): `ml_item_classico`
preenchido, `status = parcial`, `erros.premium` com a causa traduzida. A tela
mostra o MLB que existe (`LinkMl`) e "Tentar publicar o Premium de novo". O
retry passa pela mesma trava e pelo mesmo hash: se a pessoa mexeu no
formulário depois da falha, precisa conferir de novo. Os dois falharam →
`status = erro`, nada gravado em MLB, retry publica os dois.

A descrição (`POST /items/{id}/description`) é **best-effort**: o item já
existe; uma falha vira aviso em `erros.<tipo>` e log, não `parcial`. O admin
marcava `erro` e perdia o `ml_item_id` — aqui isso não pode acontecer.

## Depois de publicar: a oferta passa a "OK" sozinha

Cada MLB volta para a linha dele na aba Anúncios por
`EstruturaAnuncioService::cadastrar($oferta, ['tipo', 'codigo_mlb',
'titulo'], $ator)`, que já **completa o anúncio planejado** do mesmo tipo em
vez de criar um segundo (decisão de 29/09, `portal-do-cliente.md` §28). Com
isso a régua (`ReguaEstrutura`) passa a contar o par, a oferta vira "OK" e a
publicação agendada no Planejamento se resolve sozinha — ela é derivada da
situação, não tem estado próprio (ADR PORTAL-01).

Se `cadastrar()` recusar (MLB já cadastrado em outra oferta — não deveria
acontecer com um id recém-criado pelo ML), o MLB continua gravado em
`estrutura_publicacoes`, a falha vira aviso e log. Nunca se perde o código.

## Síncrono, no request

O par são dois `POST /items` e duas descrições — segundos. Publica no próprio
request (sem Job), com estado de carregamento na tela e a trava de 15 minutos
cobrindo o timeout. Se um dia virar lote, é Job em `onQueue('high')` — a
`default` de produção vive entupida (§27 do learning).

## A lista da esquerda

"A anunciar" = ofertas cuja situação na régua **não** é OK (falta Clássico,
Premium ou os dois — inclui `rascunho`, `validado`, `parcial` e `erro` deste
módulo). "Publicados" = ofertas OK, tenham sido publicadas aqui ou importadas
do ML. As contagens são do conjunto inteiro; a lista pagina no servidor (25
por página) e busca por SKU/nome — nunca filtro no navegador (ADR PORTAL-01
§Escala). O "o que falta" de cada card é a primeira pendência local, em
português; a ficha técnica não entra na lista (exigiria os atributos de cada
categoria) e aparece ao abrir a oferta.

## Sem conta do ML

A página abre com o estado vazio e o caminho para conectar (o Onboarding do
portal já tem a conexão). Categoria e atributos usam o app token (dados
públicos); upload de foto, conferência e publicação exigem o token da empresa
(`MercadoLivreService::ensureValidToken`), nunca um usuário admin.

## Activity log

`publicacao_rascunho` (criação), `publicacao_foto`, `publicacao_conferida`,
`par_publicado` / `par_parcial` / `par_erro`, sempre com `origem` cliente ou
interno. O rascunho salva sozinho a cada pausa de digitação; logar cada
salvamento inundaria o log — por isso só a criação é registrada.
