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

**A prévia com ofertas que ainda não existem**: a colagem casaria os anúncios
contra ofertas reais e jogaria tudo em "aguardando oferta". `previa()` aceita
`$skusFuturos` (id negativo, nunca chega ao `executar()`); a confirmação cria
as ofertas ANTES e refaz o plano real.

O `ml_acervo_itens` não guarda SKU, e acrescentar a coluna seria migration em
tabela com dado em produção (fase GSD obrigatória) — por isso o SKU sai da API.

