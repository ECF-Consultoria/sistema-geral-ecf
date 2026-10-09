# Portal do Cliente — o que não é dedutível do código

Escrito em 21/08/2026, quando `/onboarding-cliente/{token}` virou
`/portal-cliente/{token}` e o Onboarding deixou de ser o portal inteiro para
ser um módulo ao lado de Início e PPA.

Leia antes de mexer em qualquer coisa sob `portal-cliente/`, na logo de
empresa, ou no PPA visto pelo cliente.

---

## 1. Não existia logo de empresa no sistema — e a busca por ela é o passo que se pula

O pedido chegou como "já temos as logos das empresas cadastradas, aproveite
essa estrutura". Não havia nada. A varredura que provou isso:

```sql
SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (COLUMN_NAME LIKE '%logo%' OR COLUMN_NAME LIKE '%avatar%'
       OR COLUMN_NAME LIKE '%imagem%' OR COLUMN_NAME LIKE '%thumbnail%'
       OR COLUMN_NAME LIKE '%foto%');
```

Devolveu `users.avatar_url`, `sugadores.thumbnail`,
`mlb_publicacoes.thumbnail_url`, `ml_acervo_itens.thumbnail` — todos de PESSOA
ou de ANÚNCIO. Nenhum de empresa.

`companies.logo_url` nasceu daí. Descartada como fonte: a thumbnail do vendedor
no Mercado Livre — só existe para empresa com conta ML conectada, vem pequena e
redonda, e com frequência é foto pessoal e não a marca.

**A lição geral:** quando o pedido afirma que algo "já existe no sistema",
confirme no `INFORMATION_SCHEMA` antes de planejar em cima disso. Aqui a
diferença entre as duas respostas era uma migration e uma tela de upload.

## 2. `mlb_empresas.company_id` é quase sempre NULL — e isso decide se o PPA de Polos aparece

**3 linhas de 308 tinham `company_id` preenchido no banco local (21/08/2026).**

Importa porque os dois escopos de PPA amarram em lugares diferentes:

| escopo  | coluna preenchida | como chega à Company do portal |
|---------|-------------------|--------------------------------|
| `geral` | `ppas.company_id` | direto |
| `polos` | `ppas.mlb_empresa_id` (e `company_id` **nulo**, de propósito — ver `PolosPpaController::store()`) | por `mlb_empresas.company_id` |

O Portal do Cliente é por `Company`. Logo, **PPA de Polos de empresa sem esse
vínculo não aparece para o cliente**, e o sintoma é silencioso: lista vazia,
sem erro nenhum.

Se um cliente de Polos disser que não vê o plano, olhe
`mlb_empresas.company_id` ANTES de olhar a query em
`PortalPpaService::ppasDaEmpresa()`. A query está certa; o vínculo é que não
existe.

**A cobertura em produção não foi medida** — o número acima é local. Vale
medir antes de prometer o módulo a um cliente de Polos.

## 3. Trocar prefixo de URL transforma teste em vácuo, e ele continua verde

`PortalPessoasDoClienteTest::nao_existe_rota_publica_de_remover_pessoa()`
filtrava as rotas por `str_starts_with($r->uri(), 'onboarding-cliente/')` e
afirmava que nenhuma delas era DELETE ou PUT.

Depois da mudança de prefixo o filtro passou a devolver **lista vazia** — só
sobrou o redirect 301 de compatibilidade. As duas asserções passaram por
vacuidade. O teste seguiria verde mesmo que alguém abrisse um DELETE público no
portal, e a suíte inteira (392 testes) não acusaria nada.

Corrigido com o prefixo novo **e** um `assertNotEmpty($rotas)` antes das
asserções — a guarda que faz o teste quebrar em vez de emudecer na próxima vez
que o prefixo mudar.

**Todo teste que varre rotas/arquivos/registros por prefixo precisa afirmar
primeiro que achou alguma coisa.**

## 4. Os nomes de rota do onboarding continuam `onboarding.publico.*`

Só a URL mudou. Renomear para `portal.onboarding.*` arrastaria dezenas de
call-sites e testes sem ganhar nada — e o nome continua descrevendo com
precisão o que a rota faz.

O que mudou de nome: a rota antiga `onboarding.publico.workspace` virou
`portal.onboarding` (a tela) e o redirect 301 de compatibilidade ficou com
`portal.legado.onboarding`. Isso foi deliberado: deixar o nome antigo apontando
para o redirect faria todo `route('onboarding.publico.workspace')` novo gerar
URL obsoleta em silêncio.

**Módulo novo usa o namespace `portal.*`.**

## 5. O link antigo está no WhatsApp de clientes e não pode morrer

`GET /onboarding-cliente/{token}` responde 301 para
`portal.onboarding`. Não há como recolher um link já enviado.

Só o GET tem redirect — as demais rotas antigas eram POST/PATCH disparados de
dentro da própria página, que agora é servida já com as URLs novas. Os dois
prefixos seguem isentos de CSRF em `bootstrap/app.php`.

## 6. Onde a régua de progresso do Onboarding vive (e por que o hub não a repete)

A barra de progresso é calculada **no JSX** (`Onboarding/Publico.jsx`,
`calcularProgresso()`): passos + mapeamentos visíveis + reuniões.

O Início do portal precisava de um resumo por módulo e a tentação era
recalcular isso em PHP. Não foi feito: seriam duas réguas para o mesmo número,
divergindo na primeira mudança feita em uma só — o cliente veria 60% no Início
e 75% no Onboarding.

O hub usa **contagem de pendências acionáveis** (`status === 'aberto'`), que é a
mesma régua do badge do menu, calculada uma vez em
`PortalClienteService::pendenciasOnboarding()`.

Consequência visível e correta: o badge do menu (7) não bate com o denominador
da barra (8) — a barra inclui a reunião e o mapeamento, que não são passos
acionáveis. Não é bug.

## 7. Todo módulo entra pela mesma porta — e o motivo é o `ultimo_acesso`

`PortalClienteService::resolver()` faz o `firstOrFail()` (o 404 de token
adivinhado, T-135-11-01) **e** carimba `ultimo_acesso`.

O painel interno distingue "não fez" de "nem viu" por essa coluna. Um módulo
novo que resolvesse o token por conta própria funcionaria perfeitamente e ainda
assim faria o painel mostrar "nem viu" para um cliente que entrou todo dia pelo
PPA.

## 8. Os arquivos deste worktree misturam CRLF e LF

`.gitattributes` diz `* text=auto eol=lf`, mas em disco há arquivos CRLF puros
(`OnboardingPublicoController.php`, `Publico.jsx`, `routes/web.php`) e LF puros
(`Company.php`, `bootstrap/app.php`) lado a lado.

Isso quebra edição por `str_replace` de trechos multilinha: o padrão com `\n`
não casa em arquivo CRLF, e a mensagem é só "trecho não encontrado". Normalize
para LF, edite, e regrave na terminação original.

---

## Referências rápidas

| O quê | Onde |
|---|---|
| Catálogo de módulos (adicionar módulo novo) | `app/Support/Portal/ModulosPortal.php` |
| Token → empresa + contexto do menu | `app/Services/Portal/PortalClienteService.php` |
| Régua de visibilidade e posse do PPA | `app/Services/Portal/PortalPpaService.php` |
| Moldura de todas as páginas do portal | `resources/js/Layouts/PortalClienteLayout.jsx` |
| Logo com fallback e sem distorção | `resources/js/Components/Portal/LogoEmpresa.jsx` |
| Resize compartilhado com o avatar de usuário | `app/Support/ImagemUpload.php` |
| Testes | `tests/Feature/PortalCliente/` |
| Mapeamento Estrutural (régua, colagem, agenda) | `app/Services/Portal/Estrutura/` · ADR `PORTAL-01` |

---

# Anexo — o quadro do PPA (redesign de 21/08/2026)

A tela individual do PPA (`Pages/Ppa/Kanban.jsx`, compartilhada com o quadro de
Polos por re-export) ganhou cabeçalho, cards de resumo, cards ricos,
drag-and-drop e colunas extras.

## 9. Coluna extra é refinamento POR CIMA do `status`, nunca substituto

`ppa_tasks.status` é um ENUM `('todo','doing','done')` e continua sendo a
verdade sobre a etapa. As três colunas fixas **não** têm linha em
`ppa_colunas` — elas SÃO o ENUM.

Cada coluna extra declara um `status_base`. Uma tarefa em "Aguardando Cliente"
(`status_base = 'doing'`) tem `status = 'doing'` no banco e `coluna_id`
apontando para a extra. Consequências, todas desejadas:

- o Portal do Cliente, que desenha três colunas, a mostra em "Em andamento";
- `PortalPpaService` e `PpaController::index()` não mudam de régua;
- apagar a coluna devolve a tarefa à base sem perder nada (`nullOnDelete`).

**Se um dia alguém migrar as três fixas para `ppa_colunas`**, o Portal do
Cliente e todos os contadores param de enxergar as tarefas — e o sintoma é
silencioso.

`status_base` **não é editável** depois de criada. Trocá-lo moveria de etapa,
de uma vez e sem aviso, todas as tarefas da coluna: uma coluna de revisão
virando "Concluído" marcaria como feito trabalho que ninguém terminou, e isso
apareceria na hora no portal do cliente.

## 10. `useState` inicializado com props NÃO se atualiza sozinho

O quadro mantém cópia local das tarefas para a atualização otimista do arraste.
Sem um `useEffect` que reconcilie com as props, `router.reload()` traz os dados
certos, o React re-renderiza — e **a tela não muda**. O sintoma real: a tarefa
recém-criada só aparecia depois de um F5.

```jsx
useEffect(() => { setTarefas(tarefasIniciais); }, [tarefasIniciais]);
```

Não briga com o arraste, porque aquele fluxo recarrega só `resumo`.

## 11. `transform()` do Inertia React não é encadeável

```js
form.transform(fn).put(url, opts)   // ✗ "Cannot read properties of undefined (reading 'put')"
form.transform(fn); form.put(url, opts)   // ✓
```

`transformFunction` faz `transform.current = callback` e devolve `undefined`
(`node_modules/@inertiajs/react/dist/index.js`). O erro acontece no submit, então
o diálogo simplesmente **não fecha**, sem nada na tela explicando. Mordeu duas
vezes no mesmo dia, em arquivos diferentes.

## 12. O `causer_id` do activity log NÃO distingue cliente de equipe

O Portal do Cliente roda no grupo `web`. Uma sessão interna aberta em outra aba
faz o Spatie carimbar um usuário nosso numa ação feita pelo cliente — medido em
21/08/2026, com `causer_id` preenchido em movimentações vindas do portal.

Quem responde é a propriedade **`origem`** (`'interno'` | `'cliente'`), gravada
explicitamente pela rota que executou a ação. É ela que o card "Última
atualização" lê.

## 13. `updated_at` não serve como data de conclusão

Ele anda a cada correção de vírgula, e a data de conclusão andaria junto —
dizendo ao cliente que a tarefa foi concluída num dia em que só se ajustou o
texto. Daí `ppa_tasks.concluida_em`, carimbado na transição para `done` e limpo
quando a tarefa sai de `done`.

O carimbo vive em `PpaTask::moverPara()`, ponto único de movimentação usado
pelos dois lados (quadro interno e portal). Qualquer caminho novo de mudança de
status precisa passar por lá, senão o carimbo fica só num deles.

## 14. Dependência nova: `@dnd-kit`

`@dnd-kit/core`, `/sortable` e `/utilities` entraram no `package.json` (que é
compartilhado). Escolhido sobre o drag nativo do HTML5 porque este não funciona
em toque.

Detalhes que custaram tempo:
- `PointerSensor` precisa de `activationConstraint: { distance: 6 }`, senão o
  clique que abre os detalhes vira início de arraste e o diálogo nunca abre.
- O `DragOverlay` tem de renderizar o conteúdo PURO do card. Usar o componente
  ordenável ali dispara `useSortable` duas vezes para o mesmo id e o fantasma
  some no meio do arraste.
- A coluna inteira é `useDroppable`, não só a lista: soltar no espaço vazio
  abaixo do último card é justamente onde a pessoa mira.

## 15. Kanban que preenche a tela sem contar colunas

O quadro nasceu com colunas de largura fixa (`w-[300px] shrink-0`) e sobrava
meia tela vazia à direita com três ou quatro colunas.

A solução não precisou de JS nem de limiar por quantidade:

```jsx
// no trilho
<div className="flex gap-3 overflow-x-auto items-stretch">
// em cada coluna
<div className="flex-1 min-w-[264px] self-stretch">
```

`flex-1` estica enquanto houver espaço; `min-w` impede que fiquem ilegíveis; e
o `overflow-x-auto` do trilho assume assim que a soma dos mínimos não couber.
Medido: 4 colunas → 290px cada, sem rolagem; 7 colunas → 264px cada, rolando.

**`self-stretch` é o que iguala as alturas** — sem ele o quadro vira uma escada,
porque cada coluna fica com a altura do próprio conteúdo.

O botão "Adicionar coluna" saiu do fim do trilho e foi para a barra de filtros:
como coluna pontilhada ele reservava ~190px permanentes de tela para uma ação
rara, e era justamente a área vazia que mais incomodava.

## 16. Teste de UI precisa de gancho estável

Os scripts de verificação selecionavam colunas por `[class*="w-[300px]"]`. O
refino de layout trocou a classe e os testes passaram a explodir com
`Cannot read properties of null`.

As colunas agora têm `data-coluna={coluna.key}`. Classe de layout muda a cada
ajuste visual; a chave da coluna, não.

Mesmo motivo para o título do card: o seletor era `p.font-medium` e virou
`p.font-semibold` no refino. E atenção ao `innerText` de texto com
`uppercase` — ele devolve JÁ em maiúsculas, então comparação de conteúdo
precisa ser case-insensitive.

## 17. Subdomínio do Portal — e o `ASSET_URL` que quebra tudo em silêncio

`cliente.ecfconsultoria.com.br` subiu em 24/08/2026: **mesma aplicação**, mesmo
`root` (`/var/www/ecf_admin/public`), mesmo banco, mesmo deploy. Só outra porta
de entrada. Vhost em `/etc/nginx/sites-available/ecf-cliente`, certificado por
`certbot --nginx`.

**A armadilha:** o `.env` de produção tinha
`ASSET_URL=https://admin.ecfconsultoria.com.br`. Com ele, o subdomínio servia o
HTML certo (`component: Portal/Inicio`, HTTP 200) mas carregava o JS de
`admin.*` — e o `laravel-vite-plugin` marca os módulos com `crossorigin`, o que
faz o navegador exigir CORS que o admin não envia. Resultado: **página branca com
status 200 e zero erro no log do servidor.**

`curl` não pega isso — o HTML chega inteiro. Só renderizando num navegador de
verdade aparece.

Correção: comentar `ASSET_URL` no `.env` + `php artisan config:cache`. Sem ele,
`asset()` usa o host da requisição e cada domínio serve os próprios assets.
Conferido depois nos dois lados: admin renderiza e a logo carrega
(`asset_url` monta o `logoSrc` em `AppLayout.jsx:310` e `Auth/Login.jsx:6`).

**Regra geral: `ASSET_URL` fixo e multi-domínio são incompatíveis.** Se um dia
alguém repuser aquela linha, o Portal volta a dar página branca.

Backups do dia: `/root/backup-nginx-20260824-170449` e `/root/env-backup-*`.

---

# Anexo — login do Portal (24/08/2026)

Identidade por pessoa: `portal_usuarios` + pivot `portal_usuario_empresa` +
`portal_codigos_acesso`, guard `portal` separado do `web`. Entrada por e-mail e
código de 6 dígitos, sem senha.

## 18. `timestamp` NOT NULL no MariaDB ganha `ON UPDATE CURRENT_TIMESTAMP`

**Custou uma hora de depuração e teria ido para produção.**

`$table->timestamp('expira_em')` na primeira coluna TIMESTAMP NOT NULL sem
default vira, no MariaDB:

```
default='current_timestamp()'  extra='on update current_timestamp()'
```

Efeito: o `increment('tentativas')` dentro da validação **reescrevia
`expira_em` para agora**, o código morria no primeiro palpite e NENHUM login
funcionava. O sintoma era "código inválido" com o código certo.

**O SQLite dos testes não reproduz** — os testes passavam. Só apareceu ao rodar
o service contra o MariaDB local.

Regra: **`dateTime()` para toda coluna de data que não seja `created_at`/
`updated_at`.** `timestamp()` só com `nullable()` ou default explícito.

## 19. Chave de flash nova exige linha no `HandleInertiaRequests`

Reincidente (já mordeu em `nps_link_existente`, agosto/2026). O controller faz
`back()->with('portal_codigo_enviado', true)`, o servidor responde 302, e a tela
**volta ao começo como se nada tivesse acontecido** — o código foi gerado e
enviado, mas o front nunca soube.

Nenhum erro, nenhum log. Só aparece testando a tela num navegador.

## 20. Amarre o código ao CONTEÚDO da sessão, nunca ao id dela

A primeira versão amarrava ao `session()->getId()`. Dois problemas:

1. O Laravel **regenera o id** no login (proteção contra fixation) e em outras
   situações — o login legítimo quebraria sozinho.
2. Em teste com `SESSION_DRIVER=array` o id muda a cada requisição, e nada
   funcionava.

A correção é um `portal_desafio` (`Str::random(48)`) guardado no CONTEÚDO da
sessão: sobrevive ao `regenerate()`, e continua sendo específico do navegador.

**É essa amarração que responde "e se o cliente repassar o e-mail?"** — quem
receber está em outro navegador e o código não abre nada.

## 21. Por que 6 dígitos bastam (e o que os quebra)

Sozinho, um código de 6 dígitos é fraco. O que o sustenta é a SOMA de quatro
limites, e afrouxar qualquer um muda a conta:

- validade de 10 minutos (`expira_em`);
- uso único (`usado_em`);
- teto de 5 tentativas (`tentativas`) — depois o código morre;
- amarração ao navegador que pediu (`sessao_id`, que guarda o desafio).

Mais: pedir código novo invalida o anterior (senão dez pedidos dariam dez
chances simultâneas), e o hash em repouso impede que quem tenha `SELECT` no
banco entre como qualquer um.

## 22. O guard cacheia o usuário — releia do banco

`Auth::guard('portal')->user()` devolve a cópia resolvida em memória. Sem
`->fresh()` no middleware, desativar alguém só valeria quando a sessão
expirasse — trinta dias depois. Uma query por requisição é o preço de a
revogação ser imediata, que é o requisito.

## 23. `/ppa` e `/onboarding` JÁ são do admin

As rotas autenticadas do portal nasceram como `/inicio`, `/onboarding`, `/ppa` —
e as duas últimas foram **silenciosamente sobrescritas** por `ppa.index` e
`onboarding.painel.index`. O `route:list` mostrava as internas respondendo
naquelas URIs, sem nenhum aviso.

Daí o prefixo `/portal/...`. `/entrar` e `/sair` ficam na raiz porque são as que
o cliente digita.

## 24. Allowlist com curinga não é allowlist

A primeira versão da allowlist do `RestringeDominioDoPortal` tinha `portal/*`.
Isso liberou **`/portal/usuarios`** — a tela ADMIN de gerenciar acessos —
no domínio do cliente. Descoberto testando produção logo após o deploy: ela
respondia 302 em vez de 404.

Não vazou dado (o `/login` já era 404 lá, então ninguém autenticaria), mas a
rota interna existia no endereço público. Duas correções, porque uma só não
bastava:

1. **Uma linha por rota**, nunca curinga. Curinga numa allowlist reintroduz
   exatamente o vazamento que ela existe para impedir.
2. **A tela admin saiu do prefixo `portal/`** e virou `/acessos-portal`. Ter as
   rotas do cliente e as da equipe sob o mesmo prefixo era o que obrigava a
   allowlist a distinguir uma da outra por padrão de string.

Há um teste que quebra se alguém reintroduzir `portal/*`.

**A lição maior:** o `curl` rota a rota contra PRODUÇÃO, depois do deploy, foi o
que pegou. A suíte passava — porque o teste que eu tinha escrito verificava as
rotas que eu me lembrei de listar, e `/portal/usuarios` não estava entre elas.

## 25. A régua de agrupamento do PPA vive em QUATRO lugares — e eles têm de concordar

Em 21/09/2026 as duas listas de PPA (a do portal e a interna) passaram a se
agrupar sozinhas em **Em andamento · A fazer · Concluídos**, para que um cliente
com 20 planos não recebesse 20 quadros de três colunas empilhados.

A mesma régua existe em quatro implementações, e nenhuma delas é opcional:

1. **`resources/js/lib/ppaAgrupamento.js`** — decide o grupo de cada plano na
   tela, a partir das tarefas. Tem teste próprio em
   `tests/js/ppaAgrupamento.test.js`.
2. **`Ppa::scopeOrdenadoPorAtencao()`** — o MESMO critério em SQL. Existe porque
   a lista interna **pagina de 20 em 20**: se o banco devolvesse em outra ordem,
   a seção "Em andamento" apareceria VAZIA para quem tem plano andando na página
   2. O sintoma é silencioso — nada quebra, só some.
3. **`PortalPpaService::visao()`** — manda `fazendo` / `a_fazer` / `prazo_dias`.
   Sem essas contagens todo plano cai em "A fazer" e a hierarquia vira enfeite.
4. **`Ppa::scopeDaSituacao()`** — a MESMA régua no `WHERE`, para o filtro de
   situação da lista interna (22/09/2026). Nasceu depois dos outros três e é o
   mais fácil de esquecer: um filtro que discorde da régua devolve planos que a
   seção escolhida não desenha — lista vazia com o contador dizendo que há sete.

Mudar uma sem as outras não quebra teste nenhum de forma óbvia: a tela continua
renderizando, só que errado.

### `withCount` + alias em `ORDER BY` funciona no MariaDB — verificado, não suposto

`scopeOrdenadoPorAtencao` usa os aliases de `withCount` (`tasks_count`,
`tasks_done_count`, `tasks_doing_count`) **dentro de um `CASE` no `ORDER BY`**.
O SQLite dos testes aceitaria de qualquer jeito, então isso foi conferido contra
o **MariaDB 10.4.32** local, com a tela carregando de verdade. `ORDER BY` é
avaliado depois da projeção — ao contrário do `WHERE`, onde o alias NÃO vale.

### Duas decisões de produto que parecem bug e não são

- **Plano 100% feito desce para "Concluídos" mesmo sem a equipe encerrar.**
  `ppas.status = completed` é um ato da equipe, e ela nem sempre volta para
  marcar. O plano desce para a gaveta, mas continua EDITÁVEL — só o encerrado
  pela equipe vira leitura, regra que já existia.
- **A ordem NÃO se reorganiza enquanto o cliente trabalha.** O agrupamento usa
  as tarefas como chegaram do servidor, não o estado vivo: concluir a última
  tarefa faria o plano saltar de seção no instante em que o card foi solto, e o
  quadro sumiria de sob o cursor. Contadores e percentual, esses sim, são vivos.
  A posição nova vale na próxima visita.

### O filtro no `WHERE` NÃO pode reaproveitar os aliases do `withCount`

`scopeOrdenadoPorAtencao` usa `tasks_count` / `tasks_done_count` /
`tasks_doing_count` dentro de um `CASE` no `ORDER BY`, e funciona. A tentação é
copiar as mesmas expressões para o filtro — e aí quebra: alias de SELECT vale em
`ORDER BY` (avaliado depois da projeção) e **não** em `WHERE`. Por isso
`scopeDaSituacao` usa `whereHas` / `whereDoesntHave`, que viram subconsulta.

O SQLite dos testes é permissivo com alias em `WHERE` em alguns casos; o MariaDB
não é. Seria mais uma armadilha do tipo "passa no teste, estoura na tela".

### O PPA interno virou a tela do Portal — um desenho só (23/09/2026)

Duas revisões seguidas foram recusadas antes de a pergunta certa aparecer. A
primeira pôs uma fileira de chips acima da lista ("ficou muito ruim"); a
segunda acertou o peso, mas ainda era a lista de linhas. O que o usuário queria
era outra coisa: **"o PPA do Portal está diferente do nosso interno; gostei
apenas do Portal, então o mesmo que está no Portal eu quero no interno"**.

A lição de processo: quando alguém recusa um ajuste de tela duas vezes, pare de
ajustar. "Como era antes" pode significar uma tela que você nem está olhando —
e, aqui, a tela boa já existia, do outro lado do mesmo módulo.

**O que mudou.** Equipe e cliente desenhavam o MESMO plano de jeitos
diferentes: o cliente tinha o quadro de três colunas que abre e fecha, e a
equipe tinha uma lista de linhas com o Kanban em outra página. Falar ao
telefone sobre "o card que está em andamento" exigia traduzir entre as duas.
Agora:

- os componentes saíram de `Components/Portal/Ppa/` para **`Components/Ppa/`** e
  servem os dois lados: `PlanoPpa`, `ColunaPpa`, `CardTarefaPpa`,
  `IndicadoresPpa`, `TituloSecaoPpa`;
- o payload comum sai de um lugar só: **`PpaListaService::linha()` monta em
  cima de `PortalPpaService::visao()`** e acrescenta o que é da equipe. A
  direção da dependência é de propósito — o payload do cliente é o mais
  restrito, e por isso é ele quem define o mínimo comum;
- o que só a equipe vê entra por PROPRIEDADE do `PlanoPpa` (`meta`, `chips`,
  `acoes`, `somenteLeitura`, `vazioTexto`), **nunca por cópia da tela**.

O gate `tests/js/estrutura-ppa-filtros.test.js` quebra se alguém reintroduzir
um `function Indicador` ou um `const COLUNAS` dentro de uma das páginas — que é
o primeiro passo da divergência, sempre feito "só para ajustar uma coisinha".

**Chaves do payload:** a lista interna deixou de falar `title`/`tasks_count`/
`due_date_dias` e passou a falar `titulo`/`total`/`prazo_dias`, como o cliente.
Componente compartilhado exige as mesmas chaves; chave que diverge é componente
que quebra do outro lado.

**A equipe NÃO herda a trava de leitura do cliente.** No portal, plano encerrado
vira consulta. Internamente quem encerrou foi a própria equipe, e impedí-la de
reabrir seria uma trava sem dono — daí `somenteLeitura={false}` na lista
interna, com o comportamento do portal como padrão do componente.

**O arraste interno usa `ppa.tasks.mover`, não `ppa.tasks.update`.** A primeira
responde JSON; a segunda responde Inertia e faria o quadro piscar a cada card.
A rota serve os dois escopos porque a tarefa pertence ao PPA, não ao escopo.

### A tela do CLIENTE tambem filtra — e lá o filtro é do navegador

Unificadas as telas, veio o óbvio: *"não estou conseguindo filtrar pelo portal
do cliente"*. O portal ganhou os MESMOS dois seletores da lista interna, e a
busca deixou de sumir quando o cliente tem 3 planos ou menos.

**O filtro do portal roda no NAVEGADOR, e o da lista interna no BANCO.** Não é
descuido: `PortalPpaController::indexAutenticado` manda TODOS os planos do
cliente de uma vez, sem paginação. Sem paginação não existe o risco que obriga
o outro lado ao SQL (mostrar "3 vencidos" para quem tem 19 na página seguinte),
e filtrar no cliente é instantâneo. Se um dia o portal paginar, o filtro TEM de
descer para o servidor junto.

Para as duas telas não divergirem, o que é comum mora em
`lib/ppaAgrupamento.js`: `SITUACOES_PPA`, `ORDENS_PPA`, as sentinelas
(`TODAS_SITUACOES`, `ORDEM_PADRAO`), `filtrarPorSituacao` e
`ordenarPorAtualizacao`. `filtrarPorSituacao` **não é uma quinta
implementação da régua**: o grupo de cada plano já foi decidido por
`grupoDoPlano`, e "vencido" olha `prazoDias`, que o servidor calcula e que já
vem nulo em plano encerrado.

**`atualizado_em` passou a viajar para o cliente**, quebrando a regra de "datas
de controle não vão no payload". É deliberado: ordenar por "atualizados
recentemente" sem mostrar a data seria ordenar por critério invisível. É a data
de mexida no plano DELE, não um dado interno.

**Os números do topo NÃO seguem o filtro no portal, e seguem na lista interna.**
Também estrutural: no portal existe o conjunto inteiro para somar, e segui-lo
faria "Concluídos" mostrar 100% e zero pendências — lido de relance, "acabou
tudo". Na lista interna o filtro é do servidor e a página já chega recortada;
não há conjunto inteiro para somar.

### Sem ESLint, constante órfã só estoura na cara do usuário

Ao mover os rótulos dos filtros para a lib, o bloco removido levou junto uma
constante que continuava em uso (`SEM_PLANOS`). **`npm run build` passou.** O
que pegou foi o gate estrutural de `tests/js/estrutura-ppa-filtros.test.js`, que
afirma a existência da declaração. Sem ele, a tela do PPA abriria em branco com
um `ReferenceError` no console. Mesma família do `setCollapsed` órfão da
sidebar — vale a pena o gate citar as declarações, não só os usos.

### O "quadro completo" foi desligado — e ele era o ÚNICO lugar que criava tarefa

Pedido: *"eu não quero quadro completo, não vou usar isso, ninguém vai, o
simples está bom"*. Com o quadro desenhado dentro da lista, a segunda página
virou a mesma coisa maior.

**A armadilha:** tirar o botão sozinho deixaria o módulo sem como adicionar uma
ação. `Ppa/Kanban.jsx` era o único consumidor de `ppa.tasks.store`, e a lista era
o único link até ele. Por isso a criação veio para o rodapé do quadro na lista,
no mesmo commit.

A página e as rotas continuam existindo, **sem link nenhum**. Ficaram sem
caminho pela tela: área, prioridade, prazo e lado responsável da TAREFA, e as
colunas extras (`ppa_colunas`). O quadro do portal e o da lista mostram
`prazo_dias` e `responsavel_lado` dos cards — eles seguem viajando no payload,
mas ninguém mais tem onde preenchê-los. Se alguém sentir falta, o caminho é
trazer esses campos para o card na lista, não ressuscitar a página.

**Criar tarefa recarrega com `preserveState: true`**, para o plano não fechar
debaixo de quem está trabalhando nele. Quem traz a tarefa nova para a tela é um
`useEffect` que re-semeia `tarefasPorPlano` quando os props chegam — sem ele o
estado local continuaria o de antes e a tarefa só apareceria no F5. E `linhas`
PRECISA de referência estável (`?? SEM_PLANOS`, constante de módulo): com
`?? []` o efeito giraria em falso para sempre.

### Filtro de data virou ORDENAÇÃO, não intervalo

Também recusado: "os filtros de data eu quero filtrar não data exata, mas do
mais recente atualizado, ou dos mais antigos". Intervalo `de`/`até` responde
"o que nasceu nesta semana"; ninguém procura PPA assim. A pergunta real era
"o que anda parado há tempo demais".

Virou `Ppa::scopeOrdenadoPorAtencao($ordem)`, e o **grupo continua sendo o
critério principal** — a ordem só desempata dentro da seção. Um concluído
mexido agora não pode pular na frente de um plano andando, ou as seções viram
enfeite.

Como a tela agrupa o que recebe, ela não pode reordenar por conta:
`seccionar(planos, { ordenar: false })` na lista interna. Sem isso o JS
reordenaria por prazo e desfaria, calado, a escolha do usuário.

### `GREATEST` não existe no SQLite dos testes

"Quando mexeram neste plano pela última vez" é o maior entre `ppas.updated_at`
e o `MAX(updated_at)` das tarefas. `GREATEST(a, b)` resolveria em uma linha no
MariaDB e **não existe no SQLite** (lá é `MAX(a, b)` escalar, que no MariaDB é
agregação). A forma que os dois entendem é um `CASE` — ver
`Ppa::sqlUltimaAtividade()`.

A subconsulta aparece DUAS vezes dentro do `CASE` de propósito: alias de SELECT
não pode ser referenciado por outra expressão do mesmo SELECT no MySQL.

**Ao escrever teste disso, envelheça a TAREFA também.** Tarefa nasce com
`updated_at` de agora; três planos com tarefas novas empatam no mesmo instante e
a ordem sai aleatória. Custou uma falha que parecia bug de ordenação.

### "Vencido" não é um grupo, e por isso não entrou em `GRUPOS`

O pedido foi "filtrar por vencido, em andamento e concluído". Vencido parece o
quarto grupo, mas não é: um plano vencido continua estando em andamento **ou** a
fazer — ele atravessa as seções em vez de substituí-las. Virou filtro, e a lista
filtrada continua se agrupando normalmente: quem pede "Vencidos" vê os atrasados
já separados entre o que está andando e o que nem começou.

A fronteira é a mesma do selo da tela: `due_date < hoje`, **estrito**. "Vence
hoje" não é vencido. E plano encerrado pela equipe fica de fora, pela mesma razão
que `diasAteOPrazo()` devolve `null` nele — atraso de trabalho fechado não cobra
ninguém.

### "Atualizado em" tem de contar TAREFA, senão mente

`ppas.updated_at` responde "quando alguém editou o plano", e não "quando mexeram
nisso". Mover um card é trabalho no plano, mas grava em `ppa_tasks` — outra
tabela. Sem contar a tarefa, um plano com o quadro andando todo dia aparece
parado desde a última vez que alguém trocou o título, que é o oposto do que a
coluna promete. `Ppa::scopeComUltimaAtividade()` traz o `MAX(updated_at)` das
tarefas por subconsulta, e `atualizadoEm()` devolve a mais recente das duas.

Plano sem tarefa nenhuma faz a subconsulta devolver NULL: o fallback para o
`updated_at` do plano existe porque, sem ele, a data sumiria justamente nos
planos recém-criados.

### Filtrar por "Concluídos" tinha de abrir a gaveta que vem fechada

A seção "Concluídos" nasce recolhida, para tirar do caminho o que ninguém pediu.
Com o filtro, ela passou a ser exatamente o que se pediu — e a tela vinha vazia
com o contador dizendo "12". Daí `soConcluidos` na lista interna: filtrou por
concluídos, a gaveta abre e o cabeçalho deixa de ser botão.

### O filtro é do SERVIDOR; a busca por texto continua sendo da página

A busca por título/empresa/responsável varre só a página atual, de propósito
(é o alcance que os olhos tinham na tabela). Os filtros novos **não** podiam
seguir esse caminho: a lista pagina de 20 em 20, e recortar só o que chegou
mostraria "3 vencidos" para quem tem 19 espalhados pelas páginas seguintes.
Pelo mesmo motivo a paginação carrega os filtros na URL, e mudar um filtro volta
para a página 1.

### No portal o card arrasta, mas não reordena

`useDraggable`, não `useSortable` — ao contrário do quadro interno. A rota do
portal (`PortalPpaController::moverTarefa`) persiste **status**, e só. Um card
que se reordenasse dentro da coluna mostraria uma organização que o próximo F5
desfaz. Se um dia a reordenação pelo cliente for desejada, ela exige passar
`ordem` pela rota do portal — e aí vale lembrar que a ordem é COMPARTILHADA com
o quadro que a equipe usa.

## 26. Link de compartilhar PPA: o login precisa devolver ao destino — e só a destinos do portal

Em 23/09/2026 a lista interna (PPA e PPA Polos) ganhou "Compartilhar" por plano
(`PpaListaService::compartilhamento()`). Duas vias, sem reabrir o token de
Company aposentado em 15/09:

- **PPA de empresa** → `/portal/ppa?plano=ID` (login). A tela do portal abre e
  rola até `#plano-ID`; se o plano está concluído, abre a gaveta também.
- **PPA de Polos** → `ppa.workspace` por token, sem login. O token nasce no
  clique (POST `workspace.generate` com `Accept: application/json`), nunca na
  listagem.

**Antes disto o login do portal SEMPRE ia para o Início** — qualquer link
profundo para o portal morria no login. Agora `abrirSessao()` honra o
`url.intended` gravado por `redirect()->guest()`, com duas travas:
só caminho que começa em `/portal/`, e reduzido a caminho + query (sem host).
O `url.intended` é a MESMA chave que o login do sistema interno usa; honrar
qualquer valor mandaria o cliente para uma URL do admin, e aceitar host seria
redirecionamento aberto. Teste: `tests/Feature/PpaQuadro/CompartilharPpaTest.php`.

Cliente com várias empresas: se o plano do link é de uma empresa que NÃO é a
ativa na sessão, o `?plano=` não casa e a lista abre normal — não troca de
empresa sozinho.

## 27. Mapeamento Estrutural: a planilha do Projeto Polos virou módulo (23/09/2026)

Decisões e porquês em `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md`
— leia antes de mexer em `estrutura_*`. O que mais facilmente se desfaz sem
querer:

- **O gabarito é a planilha.** `tests/Concerns/GabaritoDaPlanilhaEstrutural.php`
  monta o exemplo dela (cadeira + mesa) e `ReguaDoGabaritoTest` exige os números
  que a própria planilha calculou (9 · 2/5/1/1 · 18 · 4 · 14 · 1 · 22,2%). Se
  uma mudança quebrar esse teste, a mudança está errada. A linha K10 ("Kit
  virtual") foi tirada de propósito: era contagem de fase.
- **Agenda sem estado para Publicação.** Concluir pela agenda É cadastrar o
  anúncio (MLB obrigatório só ali). Pôr um "feito" na linha da agenda recria a
  contradição da planilha (CB3 OK no Planejamento, "Publicar" no Mapeamento).
- **Espera só se move em ESCRITA** (criar/renomear/excluir oferta). Um GET que
  promovesse linhas esconderia efeito colateral — há teste disso.
- **Paginação sempre no servidor**, painel sempre sobre o conjunto inteiro. Não
  introduza "filtro no navegador para quem tem pouco": são dois caminhos, e o de
  cima só o maior cliente (2.688 anúncios) exercita.
- **O mapeamento de colunas da colagem ainda não viu uma exportação real do
  ML.** Fica todo em `LeitorColagemAnuncios::CABECALHOS`, com teste. Quando a
  primeira exportação aparecer, é ali (e um caso no teste) que se ajusta — não
  use os `Anunciar-*.xlsx`, que são template de publicação em massa.
- **Visível para toda empresa.** `mlb_empresas.company_id` preenchido em 3 de
  308: não há caminho confiável de Company até "é de Polos".

### O que liga o Clássico ao Premium no ML é o SELLER_SKU — medido (25/09)

Nos 21 anúncios reais dos fixtures da Fase 134 (`tests/fixtures/phase134/`),
os pares Clássico + Premium do mesmo produto tinham o **mesmo `SELLER_SKU`**
(`1808`, `1301-UN-NA`) e **`user_product_id` / `family_name` diferentes** —
no modelo "User Products" cada anúncio tem o seu. O título muda de propósito
("mesmo SKU, títulos diferentes", regra da aula). `catalog_product_id` só liga
quando os dois estão no mesmo produto de catálogo. 15 dos 21 tinham SKU.

### Puxar do ML cria as ofertas — em LOTES dos mais vendidos, nunca a conta inteira

O fluxo que o usuário quer (25/09) é o contrário de "cadastre a oferta e depois
importe": **os anúncios do ML criam as ofertas**. Cada SKU vira uma oferta
(Fase 1, simples; nome = título do anúncio mais vendido do SKU; logística do
`shipping` do anúncio) com TODOS os anúncios Clássico e Premium daquele SKU.
A versão intermediária, que exigia cadastrar oferta antes e mostrava "Cadastre
as ofertas primeiro", o usuário não entendeu — não volte a ela.

**O SKU é o par, e só ele** — medido nos 40 mais vendidos da #131: SKU
`30069Full` = 12 anúncios, 6 Clássico + 6 Premium, títulos diferentes;
`user_product_id` e `family_id` são DIFERENTES em cada anúncio (não servem
para juntar). `30069` e `30069Full` são ofertas SEPARADAS (decisão do usuário:
SKU diferente = oferta diferente). SKU como `33132x2Full` (kit de 2) entra como
Simples — não se adivinha combo pelo SKU.

**Escala**: a CAMILLOPARTSFILIALSCCAMILLO (#131) tem ~101 mil anúncios no
acervo (46k Clássico + 53k Premium ativos). Ler tudo = ~5.000 multigets, mais
de uma hora, milhares de ofertas de uma vez. Por isso cada "Puxar" lê os
**mais vendidos** (`sold_quantity` do acervo) que ainda NÃO estão no módulo
(nem anúncio, nem espera) até juntar 500 SKUs; o próximo "Puxar" continua
sozinho, porque o que foi importado sai dos candidatos. Para cada SKU,
`/users/{id}/items/search?seller_sku=` traz os irmãos (128 ms na #131).

**Em fatias de 45 s, com `rodada`**: um lote de 500 SKUs passa de 90 s, e a
fila `database` reentrega Job reservado há mais que `retry_after` (90 s) — duas
leituras simultâneas. O Job trabalha 45 s, guarda o progresso no cache e
despacha o próximo; a `rodada` (uuid) impede leitura velha de escrever por cima
da nova. O estado interno (lista de MLBs) NUNCA vai para o navegador — o
`estado()` devolve só o progresso.

**Produção é REDIS, e a fila `default` vive cheia** (medido 25/09): o CLAUDE.md
diz "queue database", mas `QUEUE_CONNECTION=redis` na VPS e os workers rodam
`queue:work redis --queue=high,default`. A `default` tinha **157 jobs** do sync
do acervo; a primeira versão do "Puxar" ia para ela e NUNCA começou — a tela
ficou em "0 lidos" e depois "parou no meio", sem log e sem `failed_jobs`
(o job só estava esperando). Job disparado por clique de alguém que está
olhando a tela vai para `onQueue('high')`, como o `ResolveOnboardingPassoJob`.
Para diagnosticar: `Redis::lrange('queues:default', 0, -1)` pelo tinker — a
tabela `jobs` fica vazia em produção e engana. A tela agora distingue "na
fila" (espera 15 min) de "começou e parou" (3 min).

**Métricas do ML no Mapeamento (28/09) — o acervo NÃO tem visitas nem buy box
na #131** (0% de 99 mil ativos). Dois defeitos da camada cara da Fase 134
(`MlAcervoDetalheService`), medidos em produção e NÃO corrigidos aqui:
1. `/items/{id}/visits` com `date_from=...T00:00:00.000-00:00` volta **400
   "unknown date format"**. Aceitos: `Y-m-d` puro ou
   `/items/{id}/visits/time_window?last=7&unit=day`.
2. `price_to_win` devolve `status: "competing"`, fora de
   `BUYBOX_STATUS_VALIDOS` — a coleta grava null.
O Mapeamento lê visitas, vendas de 7 dias (`/orders/search?item=MLB…` conta só
aquele anúncio) e buy box NA HORA, sob demanda, com cache de 30 min
(`AnunciosMercadoLivreService::metricasDaOferta`). Vendas vitalícias, preço,
fotos e alertas vêm do acervo (100% preenchidos). Estoque é FAIXA (pares
Clássico+Premium do mesmo SKU têm estoques próprios); nada de "dá para montar
N kits" — estoque no Full não está na mão do seller (usuário).

**A estação do produto substituiu a gaveta (28/09)** — o usuário: "o painel
lateral limita muito; não dá gráfico, não dá para ver as fotos". Recorte =
FAMÍLIA (produto + combos, ou kit + componentes), numa resposta só
(`ofertas.estacao`), relida a cada escrita (prop `versao` = `estrutura`).
Clássico e Premium são colunas lado a lado — a régua vira layout; lado que
falta = coluna vazia com a ação. Inspetor à direita: fotos (multiget com
`pictures`, junto com o SKU — `anuncios-ml` GET), série de visitas do
anúncio. Decisões que não se deduzem:
- Visitas em PARALELO: `MercadoLivreService::getMany()` (Http::pool, lotes de
  10, falha refeita pelo `get()` de sempre). Método NOVO; os existentes não
  mudaram. 30 anúncios: ~1 s em vez de 12 s.
- Vendas de 7 dias: pedidos da LOJA, pré-aquecidos por
  `AquecerPedidosMlEstruturaJob` (fila high) ao abrir a página; a resposta das
  métricas sai com `vendas_prontas: false` e a tela consulta de novo a cada
  3 s. Trava `estrutura:vendas7d-aquecendo` (Cache::add, 5 min) evita 2 jobs.
- `?abrir=ID&metricas=1` (da Jardinagem): a limpeza da URL precisa de
  `setTimeout` — o Inertia regrava a URL logo depois da montagem e desfazia o
  `replaceState` síncrono.
- **O preço do acervo (e o `price` do `/items`) é o CHEIO, sem promoção.**
  MLB4645047625: R$ 2.021,08 no acervo, R$ 1.666,37 no anúncio (17% OFF,
  campanha do marketplace). O preço que o cliente paga: `/items/{id}/sale_price
  ?context=channel_marketplace` → `amount` (e o cheio em `regular_amount`);
  `original_price` vem null. Uma chamada por anúncio, lida em paralelo com o
  SKU e as fotos. O ML trunca o desconto (17,55% → "17% OFF").
- Vendas por dia: o job lê 30 dias de pedidos DIA A DIA (a primeira página de
  cada dia numa leva paralela, as demais noutra) — ~10 mil pedidos/mês na #131
  pediriam offset > 10 mil numa busca única. Dia do pedido = horário do Brasil.
- No celular, tocar no MLB do card abre o ML e NÃO seleciona o card (é o
  `stopPropagation` do `LinkMl`) — tocar na foto/título seleciona.

**A prévia com ofertas que ainda não existem**: a colagem casaria os anúncios
contra ofertas reais e jogaria tudo em "aguardando oferta". `previa()` aceita
`$skusFuturos` (id negativo, nunca chega ao `executar()`); a confirmação cria
as ofertas ANTES e refaz o plano real.

O `ml_acervo_itens` não guarda SKU, e acrescentar a coluna seria migration em
tabela com dado em produção (fase GSD obrigatória) — por isso o SKU sai da API.


## 28. O Mapeamento virou submódulos, e anúncio sem MLB deixou de contar (29/09/2026)

O usuário achou a página única "poluída" e corrigiu a premissa do módulo: ele
é **principalmente para quem começa do zero**, sem anúncio no ML. Importar do ML
continua, mas como porta secundária. Ordem dos submódulos (menu e trilha):
Lista SKUs → Precificação → Anúncios → Planejamento → Mapeamento → Anunciar.
Tudo sai de `ModulosPortal::SUBMODULOS`. `rota_auth` nulo = "Em breve".

- **A chave ativa é `estrutura.<sub>`** (`ModulosPortal::ESTRUTURA.'.lista'`).
  O `paraEmpresa()` parte no ponto: o que vem antes é o módulo, o resto é o
  submódulo. Página nova do módulo que passar só `estrutura` acende o módulo
  e nenhum submódulo.
- **`/portal/estrutura` só redireciona.** Sem query, vai para a Lista SKUs.
  Com `abrir`/`q`/`situacao`/`pagina`/`metricas`, vai para o Mapeamento com a
  query intacta: são os links que a agenda e a Jardinagem já espalharam.
- **Sem código MLB = PLANEJADO, e não conta como publicado** (decisão do
  usuário). A aba Anúncios passou a receber o título do Clássico e do Premium
  antes de publicar. Contar isso como publicado faria o progresso mentir. A
  regra mora em `EstruturaAnuncio::conta($status, $codigoMlb)`, sem migration.
  Medido em produção antes: só 3 anúncios sem MLB, todos da #447; os 2.996 da
  #131 têm código. Duas consequências que parecem bug e não são:
  - colagem com SKU + tipo e sem MLB agora deixa a oferta em "Falta …";
  - **cadastrar com MLB COMPLETA o planejado do mesmo (oferta, tipo)** em vez
    de criar um segundo. Sem isso, o "Concluir" da agenda deixava o título
    planejado órfão ao lado do publicado.
- O gabarito da planilha (4 publicados de 18) não mudou: todos os anúncios
  dele têm MLB.
- **Precificação** (mesmo dia): decisões em `.planning/adrs/PORTAL-02-precificacao-do-mapeamento.md`.
  A conta da Calculadora roda no PHP (`PrecificacaoEstrutura`), e o preço
  NÃO é coluna: mudar o imposto da empresa recalcula tudo na leitura. Todo
  percentual é gravado em ponto percentual. O custo de combo, kit e combit é a
  soma dos componentes, e um componente sem custo anula a soma de propósito.
  A migration só cria tabelas novas; no MariaDB local compartilhado ela foi
  rodada com `--path`, para não arrastar migrations pendentes de outras sessões.

## 29. Anunciar: o par Clássico + Premium publicado pelo portal (29/09/2026)

Decisões e schema em `.planning/adrs/PORTAL-03-anunciar-do-mapeamento.md`.
O que custou caro e não se deduz do código:

- **`ml_anuncio_rascunhos` não serve ao portal.** `user_id` é NOT NULL com FK
  para `users`, e o cliente entra pelo guard `portal`. Alterá-la é migration em
  tabela com dado em produção (fase GSD). Por isso `estrutura_publicacoes` e
  um service próprio (`EstruturaPublicacaoService`) que compõe o motor do
  admin (`builderPara()->montar()`, `MercadoLivreService::post()`,
  `MlImagemService`, `MlCatalogoMetaService`, `MlItemPayloadValidator`) sem
  usar `MlPublicacaoService::validar()/publicar()`.
- **A trava contra publicar duas vezes é um UPDATE condicional** (`WHERE
  status IN (validado, parcial, erro) OR (publicando AND publicando_em < −15
  min)`) que precisa afetar 1 linha — funciona no SQLite dos testes e no
  MariaDB sem cache. **Cada MLB é gravado no instante em que o POST devolve o
  id**, antes da descrição e da aba Anúncios; o admin marcava `erro` quando a
  descrição falhava e perdia o `ml_item_id`. Aqui descrição é best-effort.
- **O botão desabilitado não é a trava.** O servidor guarda o sha256 dos dados
  EFETIVOS (rascunho + título planejado da aba Anúncios + preço anunciado da
  Precificação) na conferência e recusa publicar se o hash de agora for outro.
  Consequência que parece bug: mudar o custo na Precificação depois de
  conferir obriga a conferir de novo — é o preço que vai ao ML que mudou.
- **`Http::fake` + `UploadedFile::fake()->create()` = "A 'contents' key is
  required".** O `create()` gera arquivo VAZIO; `attach('file', '')` do Guzzle
  recusa antes de qualquer fake, e a falha aparece como "o ML não aceitou a
  imagem" sem nenhuma requisição registrada. Use `->image()` (GD existe no
  XAMPP). Ganhou um guarda contra arquivo vazio no service.
- **`Http::recorded()` preserva as chaves** do filtro: `[0]` não existe depois
  de filtrar — `->values()` antes de indexar.
- **`array_replace_recursive` não esvazia uma lista** (`['fotos' => []]` deixa
  as fotos): para "tirar as fotos" na fixture é `array_replace` no nível de cima.
- **Duas sessões do portal no mesmo teste não funcionam** (o guard cacheia o
  usuário — §22): "oferta de outra empresa → 404" e "empresa sem conta do ML"
  são testes separados, cada um com a sua sessão.
- **Resposta JSON incompleta derruba a tela inteira.** O `salvar` devolvia só
  `publicacao` e a tela fazia `pendencias.length` → React 18 desmonta a raiz
  e a página fica PRETA, sem erro no PHP e com os testes verdes. Pego só no
  probe (captura preta). O teste do rascunho agora afirma a forma da resposta.
- **Probe sem tocar o ML de verdade:** a empresa 1 local não tem conta; para
  ver o formulário, semear um `MlToken` FALSO + o rascunho JÁ com
  `categoria_id` + a meta da categoria no cache de arquivo
  (`ml_meta_categoria_<id>`, `ml_meta_atributos_<id>`). Com a categoria salva,
  `abrir()` não chama o preditor; foto/conferir/publicar não se clicam. Mas a
  página abre SOZINHA a primeira oferta da lista — se ela não tem categoria,
  `abrir()` chama o preditor no servidor (app token, dado público). Para um
  probe hermético, deixe TODAS as ofertas da página com categoria ou abra a
  semeada direto. Apagar depois: `EstruturaPublicacao` da oferta, o token,
  as chaves do cache e o activity log `publicacao_*` da empresa — e conferir
  por reconsulta.
- **A lista da esquerda não confere a ficha técnica** (exigiria a meta de cada
  categoria, uma chamada por categoria): o card diz "pronto para conferir" e
  o formulário, abaixo, "Falta Altura total". É deliberado.
- **Sugestão de categoria mostra a ÁRVORE inteira antes de escolher** (usuário,
  29/09: "Caixa de Direção" × "Caixas de Direção Hidráulica" só se distinguem
  pelo caminho). O `domain_discovery` não traz `path_from_root`; é um
  `GET /categories/{id}` por sugestão (até 8). O portal lê o que falta em
  PARALELO (`Http::pool`, app token) e grava na chave `ml_meta_categoria_{id}`
  (7 dias) — a chave do `MlCatalogoMetaService` já era contrato de fato (o
  `MlbAnuncioController::preverCategoria` a lê direto), e assim `categoria()`
  e a próxima busca acham tudo pronto sem tocar o service do admin.
  `MercadoLivreService::getMany()` NÃO serve aqui: exige a conta da empresa
  (token dela, refresh, lock) para dado público de cache compartilhado.
  `Http::pool` passa pelo `Http::fake` (o `Pool` cria cada pedido por
  `Factory::async()`, que aplica os stubs) e é gravado em `Http::recorded()`.
  Ao contar chamadas a `/categories/`, lembre que `/categories/{id}/attributes`
  também casa — filtre por `#/categories/MLB\d+$#`.
- **Fotos reordenam por arraste com `@dnd-kit`, não com o drag nativo do
  `/mlb/anuncios`** (usuário, 29/09): o nativo não existe no toque, e o portal
  é usado no celular. `FotosDoPar.jsx`: `MouseSensor` (distance 6) +
  `TouchSensor` (delay 200, tolerance 8) + `KeyboardSensor`, como no PPA;
  `touch-action: manipulation` (com `none`, o dedo sobre a foto não rola a
  página); `draggable={false}` na `<img>` (senão o navegador inicia o arraste
  NATIVO da imagem e cancela o ponteiro do dnd-kit); `pointer-events-none` na
  imagem para o ponteiro cair na alça. A ordem é a do rascunho e vira
  `pictures` no POST — o `validado_hash` já cobre (as fotos entram no
  `normalizar()`), então reordenar caduca a conferência. A lógica de mover é
  pura em `lib/fotosDoPar.js`, com teste em node. **Puppeteer 25 arrasta por
  toque de verdade**: `setViewport({ hasTouch: true })`,
  `page.touchscreen.touchStart(x, y)` → `handle.move(x, y)` em passos →
  `handle.end()`; espere mais que o `delay` do sensor antes de mover.

## 30. Precificação: frete em branco virava ZERO e o Premium saía abaixo do Clássico (30/09/2026)

A fórmula do portal e a do onboarding de Polos são **idênticas** — "copiar a
lógica do onboarding" não resolveria. Lá o frete em branco também entra como
zero; só que o Simulador mostra um tipo por vez e o campo pisca vermelho,
então ninguém lê o preço como final. No portal os dois preços ficam lado a
lado, e o Premium sem frete parecia o preço certo. Correção: tipo em branco
usa o frete do outro (ADR PORTAL-02, revisão de 30/09). Ao receber "a conta
está errada" aqui, olhar primeiro o DADO gravado (`estrutura_precificacoes`)
antes da fórmula — foi o que achou a causa em minutos.


## 31. Produtos no Mapeamento Estrutural (Fase 167, 06/10/2026)

O cliente cadastra o produto (uma linha por VARIAÇÃO) e a oferta simples da Lista
SKUs nasce dele. O que não se deduz do código:

- **A aba Produtos real, medida (70 variações):** a coluna "Variação" é um
  ORDINAL (`1`, `2`, `única`), não "Cor: Natural" — o normalizador aceita os
  dois formatos; há 11 grafias diferentes de ambiente para os mesmos poucos
  ambientes (por isso o ambiente vira lista da empresa, com "Usar “Sala Estar”"
  quando só muda caixa/acento); 55 de 70 são ME1, 8 ME2, 6 ME2·Full, 1 sem
  medida. Conclusão de produto: a cotação de frete pela API só atinge ~20% das
  linhas (as ME2); as ME1 (55 de 70) ficam fora dessa cotação. Conferir com
  `LeitorPlanilhaProdutos` + `LogisticaProduto::daVolumes`
  — saída só em contagens, a planilha tem custo real do cliente.
- **`nullOnDelete` × `restrict` no `variacao_id` da oferta.** `restrict` daria
  1451 na cascata de `Company` (apagar a empresa apaga ofertas e variações na
  mesma transação; a ordem não é garantida). Com `nullOnDelete` a coluna
  nullable e SEM backfill dispensa o 1830 e a oferta sobrevive à variação. A
  proteção "não exclua variação que entra num combo" é então de SERVIÇO
  (`excluirVariacao` recusa), não de banco — o banco só impede o órfão.
- **ME2/Full usam o peso REAL; o cubado só entra no frete.** Regra de elegibilidade
  (peso ≤ 30 kg, soma ≤ 200 cm, maior lado ≤ 100 cm; Full: ≤ 20 kg e ≤ 80 cm) olha
  o peso real. O faturado só usa o cubado quando ele passa do mínimo do config
  (5 kg) e do real. A tabela de frete da ECF casa por faixa com
  `MATCH(peso − 0,0001)` como a planilha: peso exatamente 0,3 fica em "até 0,3".
  Tirar o −0,0001 move todo peso redondo uma faixa para cima.
- **`SpreadsheetGrid` descartava a colagem além das linhas exibidas** (colar 70
  linhas gravava 10). Foi estendido só com props OPCIONAIS (`growOnPaste`,
  `tabWrap`, `makeRow`, `rowKey`, `onRowsCommit`, `variant`, `rowActions`,
  `rowNote`, coluna `picker`); sem elas, o comportamento é o de antes — o
  Onboarding não muda. O colar usa o evento DOM `paste` lendo tudo de um ref
  (listener de montagem única).
- **"Família" aqui é linha de design** (Farmhouse, Nordic), não o grupo de
  variações (esse é o "Grupo/produto"). É lista da empresa; não confundir com a
  "família" da Precificação.
- **Custo da oferta ligada vem da VARIAÇÃO.** A Precificação mostra "vem do
  produto" e `salvarOferta` RECUSA custo em oferta ligada.
  Oferta antiga (sem produto) continua editável.
- **Categoria tem três estados:** texto livre ("a confirmar", sem id), id NÃO
  validado (o ML estava fora do ar: guarda o id, avisa "não validada agora") e
  confirmada (folha validada no ML, com caminho). Texto colado no campo do id
  nunca vira id. Categoria já confirmada e igual à do produto não é revalidada
  (a grade devolve a categoria em toda linha).
- **Pendências de verificação manual (não dá para provar sem conta conectada):**
  (1) a leitura real de `shipping_options/free` numa conta de cliente — A2 do
  RESEARCH: o ML pode IGNORAR as dimensões enviadas e devolver o mesmo frete
  para tudo; até medir, o frete é rotulado "estimado"; (2) contar
  `estrutura_ofertas` em produção antes de rodar a migration do vínculo.
- **Entrada de equipe para conferir a tela:** o ticket vale 60 segundos e é de
  uso único; para conferência demorada, emitir de novo na hora de abrir. A rota
  é `/equipe/entrar?t=...` (fora do prefixo `/portal`).
- **`style={{ position: 'relative' }}` inline vence a classe `sticky` do Tailwind.**
  No `SpreadsheetGrid`, o `td` de coluna congelada tinha `position: relative`
  inline e `left` calculado: a célula saía deslocada pelo `left` e Ref/Produto
  ficavam fora do alinhamento do cabeçalho (texto sobreposto). Ninguém via
  porque nenhuma tela anterior usava `frozen`. Só a conferência no navegador
  achou — nem teste de PHP nem `npm run build` pegariam. Correção: `position`
  decidido no próprio estilo (`frozen ? 'sticky' : 'relative'`).
- **Busca de categoria sem resultado aparece como "Não deu para buscar agora".**
  O endpoint devolve `indisponivel: true` também quando a lista vem vazia (nome
  fictício, sem categoria no preditor do ML). A busca em si funciona com o app
  token (dado público) — confirmado em 06/10 com "mesa de jantar" → MLB4341.
- **Sem planilha dentro do sistema no cadastro de produto (D-23, 06/10).** A tela
  de Produtos nasceu como grade tipo planilha (o D-12 lido como "tabela
  editável") e o usuário reprovou na conferência visual: "eu disse que não
  queria uma planilha dentro do sistema pra esse caso". "Na tela, no sistema
  mesmo" quer dizer FORMULÁRIO. Ficou lista de cartões + ficha (primeiro em
  painel; desde o 167-19, página inteira com URL própria, pelas referências do
  usuário) e a planilha só como ARQUIVO (baixar
  o modelo, preencher fora, importar com prévia). As extensões do
  `SpreadsheetGrid` (167-04) continuam no componente compartilhado, sem uso
  nesta tela. Não voltar a pôr grade no cadastro de produto sem perguntar ao
  usuário. Lição de processo: "tabela editável" e "planilha" soam iguais para
  quem vê a tela; antes de construir uma grade, mostrar o desenho.

## 32. Voltar do navegador com Inertia: a guarda tem de nascer antes dele (Fase 167, 06/10/2026)

- **Captura no `window` NÃO passa à frente do ouvinte do Inertia.** Para um
  evento disparado no próprio `window` (o `popstate`), os ouvintes rodam na
  ORDEM DE REGISTRO, com ou sem `capture: true`. Medido no Chrome 152, com
  evento real e sintético. O Inertia registra o dele quando o app monta. Uma
  tela que registra depois, mesmo em captura, chega tarde: o Inertia já trocou
  a página. Na ficha de Produtos, o "ficar" do "Sair sem salvar?" recarregava a
  ficha e perdia o digitado, e os testes de `tests/js` passavam, porque só liam
  o texto do código.
- **O que funciona:** `resources/js/lib/guardaDoVoltar.js` registra UM ouvinte
  na importação do `app.jsx`, antes do `createInertiaApp`. A tela liga a guarda
  dela com `definirGuardaDoVoltar(fn)`. Dentro da guarda,
  `e.stopImmediatePropagation()` segura a pessoa na tela e o Inertia não vê o
  popstate. Use o mesmo módulo em qualquer outra tela que precise de "alterações
  não salvas" no voltar do navegador.
- **`history.back()` só volta para a lista se ela estiver no MESMO documento.**
  Depois de um F5 na ficha, ou com a ficha aberta pela URL, a entrada anterior
  é de outro documento. O voltar traz a página velha do cache do navegador
  (bfcache): o React não monta de novo, e não há recarga, aviso nem destaque.
  Confira `navigation.entries()[i].sameDocument` antes de voltar pelo histórico.
  Na lista, `pageshow` com `e.persisted` pede `router.reload`.
- **Comportamento de navegação se prova no navegador, não no `tests/js`.** O
  roteiro com puppeteer (banco SQLite isolado, dados fictícios) achou os dois
  defeitos acima depois de a revisão e as correções estarem "verdes".

## 33. Sugestões de ofertas: Combo, Kit e Combit (Fase 168, 07/10/2026)

- **A planilha NÃO tem tipo de produto.** A coluna "Tipo" da aba Planejamento é o
  tipo da OFERTA (Simples, Combo, Kit, Combit). "Mesa", "cadeira", "cama" têm de
  ser inferidos por palavra-chave (categoria primeiro, nome depois; ambíguo na
  categoria não cai para o nome). Com o vocabulário genérico do config, os 56
  produtos da planilha real saíram com tipo (0 ambíguo, 0 sem tipo).
- **O par de tipos precisa de direção no Combit.** Sem dizer quem se repete
  ("mesa + 4 cadeiras", nunca "4 mesas + cadeira"), o mesmo par gera o item
  repetido nos dois lados e o Combit vai de ~28 para 140-162 contra 25 reais. A
  lista de pares é GLOBAL e CURTA de propósito: os 44 pares de produto da
  planilha viram ~21-23 pares de tipo (18 na lista aprovada), e uma lista curta é
  previsível ("sugeri porque mesa + cadeira está na lista"). A ECF amplia pela
  tela admin, sem deploy.
- **Trios ficam fora da v1.** 21 das 129 composições da planilha têm 3 itens;
  propor trios geraria 124 para acertar 12 (precisão ~10%). A pessoa monta o trio
  à mão na Lista SKUs. É a causa de 21 dos 23 acertos a menos do gabarito.
- **Gabarito da planilha real (lista aprovada, 18 pares): 106 de 129.** Combo
  46/46, Kit 36/44, Combit 24/39; 167 geradas (59/62/46). Os 23 que faltam: 21
  trios, 1 sem ambiente em comum, 1 quantidade/direção do Combit. É um TETO de
  reprodutibilidade (a lista nasceu da mesma planilha), não precisão em outro
  cliente: essa só se mede pelos descartes em uso.
- **Variações casam em PARALELO, e o `valor` é a armadilha.** A planilha só tem o
  ordinal (1, 2, única). Se o roteiro passa o ordinal como `valor` das duas
  pontas, `VariacoesEmParalelo` entende "valores diferentes nunca casam" e o Kit
  cai de 36 para 20 acertos. Variação sem eixo/valor casa por posição.
- **A chave da composição é por VARIAÇÃO (`v12*1+v30*4`), não por oferta.** Assim
  sobrevive a uma oferta recriada e permite não ressugerir o que foi descartado.
- **A sugestão é calculada na hora; só o DESCARTE persiste.** Persistir a
  sugestão envelheceria a cada edição de produto, família, ambiente ou tipo. O
  aceite REGERA dentro do lock e confere a chave: o que o navegador manda não vale
  por si.
- **Quantidades: o D-07 literal contra a planilha.** A reunião falava em 2/4/6;
  a planilha usa cadeira ×8, banco ×2/×4 e vários tipos ×2. A semente comitada
  (D-22) traz as quantidades COMO A PLANILHA USA e a ECF amplia pelo admin.
- **`'0'` e vazio são coisas diferentes** por causa do middleware
  `ConvertEmptyStringsToNull`: o campo vazio chega como `null` e significa "herda o
  padrão do tipo"; "nenhuma quantidade" é gravada como `'0'`.
- **Teste de Inertia exige o arquivo da página.** `assertInertia` confere o
  componente no disco: antes de a página existir, `component('...', false)`.
- **O `AvisoFlash` não carrega ação** ("Desfazer", "Ver na Lista SKUs"): por isso
  a tela tem o `AvisoSugestoes`, irmão dele com ação opcional.
- **O que a prova no navegador achou (puppeteer, SQLite isolado, 14 casos):**
  - O Inertia restaura a página do histórico com as props da DATA DA VISITA, sem
    pedir nada ao servidor. Aceitar/descartar por axios, ir à Lista SKUs e voltar
    mostrava cartões já aceitos. `pageshow` com `persisted` não pega isso (não é
    bfcache). A correção é saber que a tela chegou por um voltar:
    `chegouPeloHistorico()` em `guardaDoVoltar.js`, que registra o último
    `popstate`, e a tela faz `router.reload({ only: ['sugestoes'] })` ao montar.
  - A entrada de equipe no Portal chama `session()->invalidate()`: a sessão do
    admin (para `/dev/estrutura-geracao`) tem de ficar em OUTRO contexto do
    navegador, senão o login some.
  - No Kit, o custo na Precificação aparece como `placeholder` do campo de custo,
    com a nota "soma dos componentes", e não como valor digitado. A Lista SKUs
    deixa os cartões recolhidos: o nome da oferta nova só aparece com `?q=`.
  - A resposta 422 do teste de "1" em Combo aparece no console do navegador e é
    esperada: não é erro da tela.
  - Armadilhas do puppeteer no Chrome headless: triplo clique não seleciona o
    texto do campo (use Ctrl+A e Backspace); o site tem rolagem suave, então
    `scrollIntoView` medido logo depois posiciona o clique no lugar errado (use
    `behavior: 'instant'` antes de clicar).
- **Redesenho (168-17..21): variável sem declarar derruba a tela inteira e passa
  em `npm run test:js` e no build.** O 168-19 trocou a constante local `comFiltro`
  pela função `filtroAtivo` e deixou um uso para trás: `ReferenceError` em runtime,
  tela PRETA, e o esbuild e os testes de contrato (que leem o código como texto)
  ficaram verdes. Só o roteiro no navegador achou (T1..T12 falharam juntos). O
  teste de contrato do redesenho ganhou um gate para esse caso, mas a regra
  continua: refatoração de nome em tela grande exige abrir a tela.
- **Controle com largura fixa estoura a 1280 px.** A barra de filtros da
  referência (larguras fixas) media 1314 px de `scrollWidth` a 1280; a saída foi
  `flex-wrap` a partir de `xl`, e a barra quebra em 2 linhas nessa largura.

## 34. Ficha rica do produto: campos da categoria do ML + imagens por variação, SOB SIGILO (07/10/2026)

Trabalho direto (sem GSD) em cima da ficha da Fase 167 (`/portal/estrutura/produtos/novo`), branch
`feat/publicador-ml-261001`. A ficha virou um cadastro rico tipo Bling.

- **REGRA DE SIGILO (negócio, do usuário):** o cliente NÃO pode perceber que o que ele preenche é para o
  Mercado Livre. O bloco se chama "Ficha técnica"; os rótulos são os `name` genéricos do atributo. NADA de
  "Mercado Livre"/"anúncio"/"publicar"/"MLB" em texto, placeholder, title, aria-label, id exibido ou comentário
  visível do bloco novo e da galeria. Há gates de teste (PHP e JS) que barram esses termos no JSON ao cliente e
  no JSX. **Alcance (decisão do usuário):** o sigilo vale só nos CAMPOS NOVOS; a copy da 167 ("Categoria do
  Mercado Livre", frete do ML, conectar conta) fica, porque o cliente já conecta a conta dele para o frete.
  Mexer na copy aprovada da 167 pede nova decisão dele.
- **Campos vêm do ML por app token** (`MlCatalogoMetaService::atributos`, `GET /categories/{id}/attributes`,
  dado público, sem conta de cliente, cache 7 dias). O serviço `FichaTecnicaDaCategoria::daAtributos()` é a função
  pura que vira os grupos/campos: descarta `hidden`/`read_only`/`fixed`/variação, SKU, GRID, PACKAGE_*; mapeia
  `value_type` → texto/número/número+unidade/sim-não/lista; agrupa por `attribute_group_name`. **Cuidado:** o
  filtro de sigilo descarta um atributo INTEIRO se o `name` dele casar "mercado/anúncio/publicar/mlb/ml"; um
  obrigatório assim deixa de ser exigido (raro, mas vigiar). O cache vazio do ML é esquecido para não grudar 7 dias.
- **Tabelas novas (aditivas, provadas no MariaDB com `--path`):** `estrutura_produto_atributos` (EAV da ficha:
  company_id, produto_id, atributo_id, atributo_nome, valor, valor_id, unidade) e `estrutura_produto_variacao_imagens`
  (company_id, produto_id, variacao_id, caminho, nome_original, mime, tamanho, largura, altura, ordem).
- **Imagens:** disco PRIVADO (`local`), servidas por rota autenticada do portal (isolamento por empresa, nunca URL
  pública). Upload/exclusão gravam NA HORA (não dependem do "Salvar produto"). Teto 12/variação é tudo-ou-nada.
  `post_max_size` → 422 com mensagem clara (um `render` no `bootstrap/app.php` cobre o 413 antes do controller).
  **A ORDEM/posição não importa nesta tela (decisão do usuário):** sem capa, setas nem arrastar; a rota e a lógica
  de ordem existem no servidor/lib, sem uso na tela, para o "tratamento" depois.
  **Órfãos:** apagar produto/empresa por cascata do banco NÃO apaga os arquivos (o evento do model não dispara);
  falta um comando de limpeza se isso ocorrer em prod.
- **Produto novo:** os campos da ficha técnica se preenchem antes do `produto_id`; o PUT da ficha espera o id (logo
  após o 1º salvar). ~~A galeria só envia quando a variação tem id (mostra "Salve o produto…" antes).~~
  **Superado em 08/10/2026** — a galeria passou a aceitar foto antes do id, guardando o arquivo na aba; ver §35.
- **O tratamento/envio ao Publicador fica para outra fase** ("outro dia", palavras do usuário). Ver
  [[project-ficha-rica-produto-atributos-ml-261007]].

## 35. Ficha técnica: ter opção é o que faz o campo ser lista — não o `value_type` (08/10/2026)

Sete ajustes pedidos pelo usuário na ficha do produto (`/portal/estrutura/produtos/{id}`). Três deles
("Desenho do tecido devia ter as opções do ML", "revise os campos que já têm opção lá", "materiais devia
aceitar mais de um, em cardzinho com X") tinham **uma causa só**, e ela não é dedutível lendo o código:

- **O catálogo entrega a MAIOR PARTE das opções em atributo `value_type: "string"` que traz `values` junto.**
  `FichaTecnicaDaCategoria` lia `values` só quando `value_type === 'list'`; todo o resto caía no `default` e
  virava texto livre. Medido em Pufes (MLB31039): dos 17 campos da ficha, **4 eram lista disfarçada de texto** —
  `SHAPE` (Quadrada/Redonda/Retangular/Pera), `FABRIC_DESIGN` (Liso/Listras/Florido), `MATERIALS`
  (Algodão/Couro/Couro sintético/Microfibra) e `POUF_TYPE` (Baú/Pé palito). Só `MATERIAL`(singular) e `STYLE`
  chegavam como `list`.
- **O prejuízo já estava gravado em produção:** o produto 2 tinha `Forma = "REDONDO"` (a opção é "Redonda") e
  `Tipo de pufe = "Redondo"` (não é opção — as opções são Baú e Pé palito). Valor fora da lista é o que o ML
  recusa na publicação. Texto livre onde havia lista não é só feio: **grava dado que não publica.**
- **A regra agora é: ter opção faz o campo ser lista, qualquer que seja o `value_type`.** Número e Sim/Não
  mantêm o controle deles e descartam a lista de opções.
- **`multivalued` é a tag que libera os chips.** Em Pufes só `MATERIALS` (`FILTRABLE_COLOR` também é, mas sai
  da ficha por ser atributo de variação). O padrão visual de chips **já existia** no projeto: é o campo
  *Ambientes* em `FichaDadosGerais.jsx`. E `multivalued` já era lido em
  `ClassificadorAtributos::classificarUm()` (`multivalor:`) — o Publicador fazia certo; a ficha do Portal é
  uma segunda implementação, mais pobre, que ignorava. **Antes de mexer em atributo de categoria, olhe o que
  o `ClassificadorAtributos` já resolveu.**

### Como o multivalor é gravado (decisão de schema, sem migration)

`estrutura_produto_atributos` tem unique `(company_id, produto_id, atributo_id)` e `valor_id` é **varchar(40)**:
não cabe uma linha por opção nem os ids emendados. Como `valor` é `text`, grava-se **uma linha com os NOMES
separados por `" | "`** (`FichaTecnicaDoProduto::SEPARADOR`) e **`valor_id` nulo**. Os nomes vêm da definição da
categoria, nunca do que o cliente digitou, então quem publicar reencontra o id pelo nome na mesma definição.
Dar coluna própria aos ids é ALTERAR tabela com dado em produção — **fase GSD, não trabalho direto**; vale a
pena quando o publicador precisar dos ids.

### Duas armadilhas vizinhas, achadas no caminho

- **O filtro de sigilo derruba opção legítima.** `TERMOS_PROIBIDOS` tem `\bml\b`: uma opção `"500 ml"` ou uma
  unidade de id `ml` é descartada em silêncio — e **se TODAS as opções caírem, o campo degrada para texto
  livre**, que é exatamente a falha acima. Em Pufes não morde; em bebida, cosmético ou tinta, morde.
  (Já havia o aviso irmão na §34: `name` que casa o filtro derruba o atributo inteiro.)
- **Os grupos da ficha não existem.** Os 17 campos vêm todos em "Outros". É esperado, não bug:
  `GET /categories/{id}/attributes` devolve tudo com `attribute_group_id = OTHERS` — o próprio projeto
  documenta (N-04, no docblock do `ClassificadorAtributos`). Os grupos de verdade e o `allow_custom_value`
  (lista que também aceita texto livre) só existem em `technical_specs`, que a ficha do Portal **não
  consulta**. Decisão do usuário em 08/10: ficou de fora desta leva.

### Medida do produto × medida do embalado

A ficha mostrava DOIS conjuntos e a pessoa digitava os dois (produto 2: 12/12/12 em ambos). Só **Volumes** é o
embalado, e é o único que alimenta peso cubado, logística e frete. Agora `LENGTH/WIDTH/HEIGHT/DEPTH/DIAMETER/
WEIGHT` **só aparecem na ficha quando a categoria os marca `required`** — onde o ML exige, esconder deixaria o
cadastro incompleto. `MAX_WEIGHT_SUPPORTED` fica de fora dessa regra de propósito: é quanto o móvel aguenta,
não medida dele. **O Publicador resolveu o MESMO problema de outro jeito** (§11 de [[publicador-ml]]: renomeia
para "… do produto" e põe ao lado do pacote). A divergência é intencional — lá o vendedor publica, aqui o
cliente cadastra.

### Imagem antes de o produto existir

`POST /variacao/{variacao}/imagens` exige o id, que só nasce no Salvar. Em vez de bloquear, o arquivo agora
fica **na aba** (`imagemPendente`, entra na mesma lista `imagens` marcada `pendente`, com `URL.createObjectURL`
para a prévia) e sobe sozinho no Salvar (`enviarPendentes`, com os ids que o POST `linhas` devolveu).
Consequências que o código não conta:
- **Fechar a aba antes do Salvar perde as pendentes** — o rascunho guarda texto, não arquivo. Por isso a
  galeria diz, em palavras, que elas sobem junto com o Salvar, e a miniatura tem borda tracejada e selo.
- **Falha no envio das fotos segura a ficha na tela** (`ok = false`): sair ali perderia os arquivos.
- As fotos sobem **mesmo se a ficha técnica reprovar** — as variações já existem e o arquivo só vive na aba.
- O quadro da foto (`QuadroFotoProduto`) passou a **exibir** a 1ª imagem (a pendente inclusive). Isso **não
  fere a D-29**, que proíbe *upload* no quadro, não exibição. A **lista** de produtos continua com as iniciais:
  `ProdutoLinhas::pagina()` não traz `imagens` — levar a foto para lá é trabalho de servidor, não de tela.

### Campo que VIRA lista precisa casar por NOME (regressão do próprio §35)

Pega 40 minutos depois, na conferência em produção — e é o motivo de a conferência existir.
Transformar um campo de texto em lista **quebra o que já estava gravado nele**: a linha antiga tem
o NOME em `valor` e `valor_id` nulo, e o `<select>` casa por id. Resultado: campo correto aparecendo
"Selecione", e **salvar assim APAGA o valor** (vazio = remover a linha). No produto 2, `FABRIC_DESIGN`
tinha "Liso" — opção válida — e sumiu da tela. `MATERIALS` escapou só porque os chips já casavam por nome.

`idDeLista(campo, bruto)` resolve id OU nome (tolerando a caixa) e `idsMultivalor` reusa a mesma função.
Valor que não bate em opção nenhuma continua vazio **de propósito** (`SHAPE = "REDONDO"` onde a opção é
"Redonda"): é dado que não publica, e o obrigatório volta como "Preencha …" em vez de 422 travando a
ficha inteira. **Regra geral: ao promover texto → lista, casar por nome não é refinamento, é migração
de dado feita em leitura.**

## 36. Planejamento (ex-"Sugestões de ofertas"): banheiro, Kit de 3 e ordem (08/10/2026)

O teste do usuário (mesa + cadeira, gabinete + espelho + lixeira) só dava Combos de cadeira. Eram
quatro causas somadas, e consertar uma só não muda a 1ª página:
- **Tipo nulo por nome com dois tipos.** "Gabinete Armário ... Nichos" casava gabinete + nicho e
  "Espelho com Prateleira" casava prateleira. Agora a categoria só decide quando aponta UM tipo;
  ambígua ou sem tipo cai para o nome, onde vence a 1ª palavra-tipo (empate: a mais longa). Isso
  também passa a tipar "Penteadeira com Espelho" e "Cômoda ... com Espelho" pela 1ª palavra.
- **Cores que nunca se repetem davam zero Kit** (D-17 pulava par posicional com valor nos dois
  lados). Quando NADA casa por valor, casa por posição: min(n, m), sem cartesiano.
- **O Combo vinha primeiro na família** e as cadeiras ×2/×4/×6/×8 por cor enchiam a página de 20.
  A ordem dentro da família virou Kit (3 antes de 2) → Combit → Combo, e os Combos de cada
  família viraram UMA linha da paginação ("Ver N combos"), aberta por `?combos=<família>`
  na mesma página. Sem isso, entre famílias o problema voltava: com a mesa em 3 cores o
  banheiro ia para a página 2. A paginação anda sobre LINHAS (`paginacao.linhas`); os totais
  (`total`, `blocos`, resumo, "aceitar os filtrados") continuam contando sugestões.
- **O Kit de 3 exige os TRÊS pares na lista**, e a lista D-21 tirou de propósito os 5 pares que a
  planilha só usava em trios (banco+cadeira, buffet+cadeira, cabeceira+cômoda, cama+cômoda,
  guarda-roupa+prateleira). Logo os trios reais da planilha (mesa+cadeira+banco...) NÃO saem só
  com esta regra. Pôr esses pares de volta pelo admin libera os trios, mas também o Kit de 2
  deles (banco + cadeira sozinhos), que a 168 evitou. Decisão do usuário (08/10): não recolocar;
  a ECF põe pelo admin se quiser.
- **Semente nova de tipos: nunca reler o config inteiro.** A semente da 168 já rodou em produção e
  a ECF edita/exclui tipos pelo admin; a migration de 08/10 só insere os slugs e pares dela
  (`insertOrIgnore`), senão ressuscitaria o que a ECF apagou.
- **"Planejamento" era o nome do submódulo da agenda** (`ModulosPortal`, chave `planejamento`, rota
  `portal.auth.estrutura.agenda`). Desde 08/10 o rótulo dele é "Cronograma" e "Planejamento" é a
  tela de sugestões (rota `.sugestoes`). Chave e rotas não mudaram: código que procura a chave
  `planejamento` está falando da AGENDA.

## 37. Estoque por variação e descrição do produto (Fase 176 — era 172, 08/10/2026)

- **Estoque por variação: `0` é diferente de vazio e NÃO herda da 1ª variação.** Vazio (`null`) = "não informado"; `0` =
  "sem estoque". No POST, campo ausente ou `''` não mexe; `null` explícito limpa. Teto 99.999.999.
- **Descrição do produto** (`estrutura_produtos.descricao`) segue o sigilo da 167: nenhum texto do campo fala de Mercado
  Livre, anúncio ou publicar (o Publicador a lê, o cliente não sabe).
- **Sem coluna na planilha-modelo (D-14):** estoque e descrição só entram pela ficha na tela, não pela importação XLSX.

## 38. Ficha do Portal = régua do editor do Publicador, e "Não se aplica" (08/10/2026)

- **A ficha técnica agora usa o `ClassificadorAtributos` como régua** (`FichaTecnicaDaCategoria::classificar`): entra todo
  atributo de PRODUTO nas seções PRINCIPAIS/FICHA/AVANCADO. Antes, `TAGS_FORA` descartava `hidden` e `allow_variations`
  inteiros e o cliente nunca via 16 campos que a equipe preenchia à mão (MLB193945). Teste
  `test_na_cadeira_a_ficha_tem_exatamente_os_atributos_de_produto_que_o_editor_deixa_editar` compara as duas listas
  com o schema COMPLETO (com `technical_specs`); se ele quebrar, uma das duas regras mudou sozinha.
- **O classificador recebe só id/nome/tags/value_type** (sem `technical_specs`): sem grupo `MAIN` tudo cai em FICHA, o
  que para o Portal dá no mesmo (PRINCIPAIS e FICHA entram juntos). Opções/unidades continuam lidas pelo Portal, com o
  filtro de sigilo.
- **O eixo é decidido por PRODUTO, não por categoria (corrigido no mesmo dia).** `EstruturaProdutoVariacao::EIXO_PARA_ATRIBUTO`
  (cor→COLOR, tamanho→SIZE, voltagem→VOLTAGE, material→MATERIAL, sabor→FLAVOR) é a MESMA tabela do Sincronizar. A
  definição da categoria classifica SEM eixo e devolve o atributo-eixo com `eixo_do_portal` ('cor', 'material'…) só
  quando ele tem `allow_variations`; sem a tag é atributo comum, sem marca. Quem tira é o produto: `FichaTecnicaDaCategoria::doProduto`
  (servidor, eixos lidos de `estrutura_produto_variacoes.eixo`) e `gruposDoProduto`/`eixosEmUso` (tela, pelo
  `eixo_rotulo` das variações AINDA NÃO SALVAS) — as duas regras têm de concordar. Na gravação o campo-eixo é ignorado
  e, como gravar substitui, a linha antiga dele SAI. Isso só funciona porque o "Salvar produto" grava as linhas ANTES da
  ficha (`useFichaProduto`); se essa ordem mudar, o servidor julga pelo eixo velho.
  - **Efeito colateral aceito:** produto SEM eixo (ou só "Outro") passa a ver "Cor" na ficha; onde a categoria marca COLOR
    `required` (camiseta MLB31447), ele vira obrigatório para esse produto. É o que o editor também exige.
  - O teste da cadeira agora compara DOIS pares: ficha por cor × editor com eixo COLOR (44 campos) e ficha sem eixo ×
    editor sem eixo (45: a cor volta). Nenhuma fixture real tem MATERIAL com `allow_variations`; o teste do Sincronizar
    (`PortalCamposDoEditorNoRascunhoTest`) acrescenta um ao schema da cadeira.
  - O Sincronizar não precisou mudar: `aplicarFicha` já pula `$id === $chaveEixo`. Mas essa chave só é o id do ML quando
    o schema diz `podeSerEixo` — exatamente a mesma condição da marca. Se uma das duas mudar sozinha, um material
    gravado no Portal some do rascunho (ou o eixo vira atributo).
- **Explicação de todo campo da ficha (o "o que é isto?").** O componente é UM só, `resources/js/Components/Explicacao.jsx`
  (saiu de `Components/Publicador/`); o Portal usa via `RotuloComExplicacao` (`PecasDoProduto.jsx`), com o ícone FORA
  do `<label>`. A ficha técnica leva `explicacao` em cada campo do `campos-categoria` (`definicaoComExplicacoes`, que
  chama `ExplicacaoDeAtributos::paraPortal` — pode enfileirar a IA; na fila `sync` não). A validação do PUT usa
  `definicao()` SEM explicação, de propósito (não gasta consulta nem IA a cada salvar). Os campos fixos vêm na prop
  `explicacoes_campos` (fora de `ficha_tecnica`, porque `entradaDoProduto` sobrescreve `ficha_tecnica` inteira no
  histórico), do glossário `portal_campos` + o Estoque de `campos.estoque` (um texto para as duas telas).
  `camposDoPortal()` DESCARTA (não reescreve) texto fixo que revele o destino: o campo fica sem ícone.
  - O comentário do `Explicacao.jsx` é lido pelo gate de sigilo (fonte crua): nada de "Publicador"/"ML" nele — a regex
    do Portal pega `public(ar|ação|ador)` e `\bML\b`.
  - O `title` antigo da Família (no gatilho) e o `title`/`aria-description` do `CampoFichaTecnica` saíram: com o balão
    novo seriam dois.
- **`hidden` editável vai para "Mais detalhes", no fim, ABERTO** (gate JS: a ficha não recolhe nada). Um
  `hidden` com `required` fica no grupo normal, senão o rótulo "opcional" mentiria.
- **"Não se aplica" = `valor_id = '-1'`, `valor` e `unidade` nulos** em `estrutura_produto_atributos` (sem migration):
  é o id do N/A do próprio editor (`ValorAtributo::NAO_SE_APLICA`). A tela pede por `{id, nao_se_aplica: true}`, NUNCA
  pelo valor — `'-1'` digitado num texto continua texto. Só onde `aceitaNaoSeAplica` (produto e não obrigatório), então
  obrigatório nunca recebe N/A, lá nem cá. O Sincronizar leva o N/A como N/A, só no vazio; no rascunho o `-1` conta
  como preenchido (re-sincronizar não mexe).
