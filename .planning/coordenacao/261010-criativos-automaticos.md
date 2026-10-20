# Imagens por IA automáticas no Publicador — gatilho pronto e DESLIGADO

**De:** ECF Dev (sessão da publicação em lote do Publicador)
**Para:** MB.ECF-100376 (dono do Creative Engine: Fases 160–162, 165, 175-06)
**Data:** 2026-10-10 — branch `feat/publicador-ml-261001`

## O pedido do usuário

Quando o produto chega do Portal com a ficha completa e as fotos do cliente, o Creative Engine deve poder gerar as
imagens **sozinho** (2 imagens por kit, ≈ US$ 0,20), sem ninguém clicar "Gerar com IA". A aprovação continua de
gente. Decisão dele: o gatilho fica **desligado por chave até você ajustar o seu lado**.

## O que já existe (nada no seu código foi editado)

| Peça | Onde | O que faz |
|---|---|---|
| Serviço | `app/Services/Publicador/Criativos/CriativosAutomaticosService.php` | Confere as condições, planeja o kit (mesmo lock e ordem do `CapaDoKitService`/`planejarSobLock`) e encadeia a geração |
| Elo da cadeia | `app/Jobs/Publicador/AvaliarCriativosAutomaticosJob.php` (fila `creative`) | Último elo do preparo pela IA de texto (título → Modelo → descrição → imagens); sozinho quando o texto já estava em dia |
| Geração | `app/Jobs/Publicador/GerarCriativosAutomaticosJob.php` (fila `creative`) | Encadeado DEPOIS do seu `PlanejarKitCriativosJob`; chama o seu `CreativeKitDespachante::despachar` (o mesmo do botão) |
| Chaves | `config/publicador.php` → `criativos_auto` | `ativo` (env `PUBLICADOR_CRIATIVOS_AUTO_ATIVO`, padrão **false**), `limite_diario_por_empresa` (10), `slots` (`['lifestyle', 'hero']`), `todas_as_cores` (false) |
| Testes | `tests/Feature/Publicador/CriativosAutomaticosTest.php` | Desligado por padrão + cada condição; nenhuma IA de verdade (`Queue::fake`) |

Do seu código, só **uso** (sem editar): `CreativeEngineAtivo`, `CreativePermissao`, `PlanejarKitCriativosJob` (com
`tiposFixos`), `CreativeKitDespachante`, `MlAnuncioCriativoKit::retomavelDoPublicador`/`ultimoAprovadoDoPublicador`,
`PublicadorCriativoReferenciaService::criarPortador`/`guardar`/`gruposValidos`.

## As condições (TODAS, nesta ordem)

1. `publicador.criativos_auto.ativo`, a chave do Creative Engine (`configuracoes.creative_engine_ativo = '1'`) e a
   empresa em `configuracoes.publicador_criativos_auto_companies` (ids separados por vírgula; vazio = ninguém);
2. não é kit da Fase N (`produto_base_id` — o kit tem a capa própria) e o rascunho não está publicado/publicando;
3. ficha do Portal completa (a mesma régua da IA de texto, `PreparoIaDoRascunhoService::fichaCompleta`);
4. usuário de sistema `configuracoes.publicador_criativos_auto_usuario` (id) existe, está ativo e passa no
   `CreativePermissao::podePlanejar`/`podeGerar` (chave `mlb.criativos_ia` **e** a lista de ids, se houver);
5. o grupo (galeria geral; sem ela, a 1ª cor; todas as cores só com `todas_as_cores`) tem ≥ 1 foto com arquivo no
   disco (até `PublicadorCriativoReferenciaService::MAX` vão de referência);
6. nenhum kit retomável ou aprovado do rascunho + grupo (D-15);
7. o hash das fotos do grupo + ficha + nome + categoria é diferente do último pedido (`step_state.criativos_auto`);
8. cabe no teto diário da empresa.

Planejar e gerar vão na **mesma passada**: as referências efêmeras somem em 48 h (`creative:limpar-referencias`),
então planejar e esperar um clique desperdiçaria o plano. O `created_at` do kit e dos slots é regravado antes do
despacho (o mesmo cuidado do `MlbPublicadorCriativoController::gerar`, por causa do `encerrarSeTravado`).

## O que precisa de você antes de ligar

1. **Os 2 tipos de imagem.** Mandamos `tiposFixos = ['lifestyle', 'hero']` (o mesmo par da capa do kit, que é o
   que o usuário aprovou para 2 imagens). Para produto de 1 unidade com as fotos do CLIENTE de referência, confirme
   se é esse o par (ou se `hero` + `white_background` serve melhor de capa) — muda em `criativos_auto.slots`, sem
   código.
2. **Fila.** As gerações automáticas disputam o `ecf-worker-creative` (3 processos) com os cliques da equipe. Se
   quiser prioridade para quem clica, uma fila própria (ex.: `creative-auto`) exige mudar o `onQueue` do
   `GerarCriativoIaJob`/`PlanejarKitCriativosJob` (seus) e o supervisor da VPS.
3. **Validador (Fase 162).** Hoje o juiz roda igual ao fluxo manual e a aprovação é humana. Se a geração automática
   deve parar quando o juiz reprova (para não gastar a 2ª imagem), isso é do seu lado.
4. **Autoria.** O kit e o portador ficam com `user_id` = usuário de sistema; acervo e relatórios de custo vão
   mostrá-lo como autor.
5. **Aviso de "prontas para revisar".** A visão rápida da publicação em lote mostra "Imagens de IA prontas para
   revisar" (kit `pronto`/`parcial`). O painel de criativos do editor (`PainelCriativos`, seu) já mostra o kit como
   qualquer outro — se quiser um selo "gerado automaticamente", o kit tem `user_id` do sistema para distinguir.

Nada aqui exige `User` que não exista: o `criarPortador` recebe o usuário de sistema. Não encontramos bloqueio no seu
código para o fluxo sem clique.

## Para ligar (quando você disser)

1. `.env`: `PUBLICADOR_CRIATIVOS_AUTO_ATIVO=true` (e `config:cache`);
2. `Configuracao::set('publicador_criativos_auto_usuario', '<id>')` — usuário ativo com `mlb.criativos_ia`;
3. `Configuracao::set('publicador_criativos_auto_companies', '<id>,<id>')` — começar pela #459;
4. `queue:restart` (os Jobs novos rodam na fila `creative`).

## Adendo de 20/10/2026 — as imagens entram SOZINHAS no anúncio

Decisão do usuário: o colaborador só confere a Prévia (etapa 5); "a IA faz o resto, e se estiver errado a gente
corrige". As imagens do kit AUTOMÁTICO entram sozinhas e passam a ser AS fotos do grupo, na ordem do kit: "a imagem
do cliente é usada apenas para referência de recriar igual" (como no seu assistente antigo, onde
`CreativeKitPublicacao::aplicarPictures` monta o anúncio só com os criativos aprovados). A foto do cliente sai da
galeria e fica no rascunho como referência; se nenhuma imagem do kit for aprovável, as do cliente ficam.

O kit automático agora é o MESMO do botão: sem tipos fixos (`criativos_auto.slots` vazio), o SEU planejador escolhe
e a quantidade é `services.creative.kit.slots` (2 em produção). E cada cor ganha o seu kit
(`criativos_auto.todas_as_cores` passou a `true`).

**Nada do seu motor foi editado.** O que fizemos:
- ganchos `updated` nos SEUS modelos (`MlAnuncioCriativoKit` e `MlAnuncioCriativo`), registrados no
  `AppServiceProvider`: só conferem a chave e o status e põem um Job na fila `creative`; nunca lançam;
- o Job chama `CriativosAutomaticosService::aplicar`, que aprova pelo SEU `PublicadorCriativoAprovacaoService::aprovarSlot`
  (como o botão) e depois deixa o grupo só com elas (`EditorRascunhoService::trocarFotosDoGrupo`);
- imagem por IA nunca é referência de outro kit automático: contam só as fotos do cliente (fora o ativo do acervo
  `ia` e a foto ligada a criativo por `pub_imagem_id`, a mesma regra do `acervo:registrar-existentes`);
- espera o juiz e a refação automática (VAL-05); imagem reprovada que não vai ser refeita NÃO entra (fica no painel);
- o kit pedido no botão continua com aprovação de gente.

Se você mudar como o status do kit ou o `validacao_status` são gravados (por exemplo, por query builder em vez do
modelo), o gancho deixa de ouvir — nos avise. Os 5 pontos acima (par de imagens, fila, juiz, autoria, selo) seguem
seus; ligar em produção continua dependendo do usuário.
