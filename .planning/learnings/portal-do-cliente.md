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
não medida dele. **Superado em 09/10/2026:** agora todas aparecem, num bloco próprio — ver §38. **O Publicador resolveu o MESMO problema de outro jeito** (§11 de [[publicador-ml]]: renomeia
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
  **Superado em 09/10/2026** — o modelo v2 tem as duas colunas; ver §39.

## 38. Ficha do Portal = régua do editor do Publicador, e "Não se aplica" (08/10/2026)

- **A ficha técnica agora usa o `ClassificadorAtributos` como régua** (`FichaTecnicaDaCategoria::classificar`): entra todo
  atributo de PRODUTO nas seções PRINCIPAIS/FICHA/AVANCADO. Antes, `TAGS_FORA` descartava `hidden` e `allow_variations`
  inteiros e o cliente nunca via 16 campos que a equipe preenchia à mão (MLB193945). Teste
  `test_na_cadeira_a_ficha_tem_exatamente_os_atributos_de_produto_que_o_editor_deixa_editar` compara as duas listas
  com o schema COMPLETO (com `technical_specs`); se ele quebrar, uma das duas regras mudou sozinha.
- **Exceção à régua: o Modelo (`MODEL`) NUNCA aparece na ficha do cliente** (09/10/2026, decisão do usuário: a IA o
  gera no Publicador), mesmo obrigatório. O valor que o cliente gravou antes fica no banco e vira fato para a IA. Ver
  [[publicador-ml]] §16.
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
- **Superado em 11/10/2026 — o usuário pediu o valor próprio de volta, com outra regra; ver §46.** O que segue é o
  registro de como estava.
- **Campo com opção é SÓ lista, mesmo onde o editor interno aceita texto (09/10/2026, decisão do usuário).** O §35
  continua valendo: a ficha não oferece "Outro (digitar)" (foi aberto em `dc48b1b7` e desfeito no mesmo dia) e o
  servidor recusa com 422 neutro texto fora das opções. O texto ANTIGO gravado antes de 08/10 nesses campos (ex.:
  "Madeira maciça de eucalipto" na #459) não aparece nos chips e sai no próximo salvar do cliente — mas antes disso o
  Sincronizar já o leva ao rascunho como texto onde o editor aceita. **Atenção:** como o rascunho segue o que o
  Portal escreveu (D-05 refinado), quando o cliente salvar a ficha de novo o legado sai também do rascunho no
  Sincronizar seguinte — se a equipe quer guardar, basta editar o campo no Publicador (vira `origem = user`). Lista fechada cujas opções o filtro de sigilo
  derruba inteiras sai da ficha (não vira texto). Ver [[publicador-ml]] §14. (Com o D-05 refinado, re-sincronizar
  passa a ATUALIZAR o que o Portal escreveu.)
- **Medidas do produto fora da caixa: bloco próprio, e o §35 "só quando exigida" caiu (09/10/2026, pedido do usuário).**
  No Puff Redondo da #459 o cliente pôs 23×22×15 cm / 8 kg no Volume e o "Produto fora da caixa" do editor ficou vazio
  (e o "Diâmetro" nunca era pedido). Agora `FichaTecnicaDaCategoria::IDS_MEDIDA_DO_PRODUTO` (LENGTH, WIDTH, HEIGHT, DEPTH,
  DIAMETER, WEIGHT — os de `MEDIDAS_DO_PRODUTO` do editor + o diâmetro) saem TODOS os que a categoria tem, num grupo
  `medidas_do_produto: true` ("Medidas do produto (fora da caixa)", antes de "Mais detalhes"). A tela tira esse grupo da
  Ficha técnica (`gruposSemMedidas`) e o mostra antes das Variações (`MedidasDoProduto.jsx`); estado, PUT e validação
  são os da ficha técnica (sem coluna nova). Medida própria da categoria (SEAT_WIDTH, BASE_DIAMETER) NÃO entra no bloco.
  - **"Usar as mesmas medidas do produto fora da caixa"** (no `CartaoVolume`, só com 1 volume e C/L/A do produto
    preenchidos) é DERIVADA, sem gravar nada: marcada quando o volume já tem as medidas do produto (o peso não conta).
    A escolha na tela (`vinculos` no `useFichaProduto`) vence o derivado; marcada, trava C/L/A e acompanha as mudanças do
    produto (`seguirMedidasDoProduto`, puro). O peso do volume continua obrigatório e editável: marcar copia o peso do
    produto se houver; depois só acompanha enquanto ele ainda for a cópia. Recarregar a página volta ao derivado.
  - **REMOVIDA em 14/10/2026, a pedido do usuário** ("remove isso: Usar as mesmas medidas do produto fora da caixa"):
    a caixa, o vínculo (`vinculos`), a regra de acompanhar (`seguirMedidasDoProduto` e as conversões de unidade da
    `lib/medidasDoProduto.js`) e a explicação `mesmas_medidas` do glossário. As medidas do volume voltaram a ser
    sempre digitadas, e mudar as do produto não mexe nelas. Não trazer de volta sem pedido: o bloco "Medidas do
    produto (fora da caixa)" continua, só a ligação com o volume saiu.

## 39. Planilha de produtos v2: o modelo, a planilha baixada e as categorias em lote (09/10/2026)

Trabalho direto (sem GSD), decisões do usuário de 09/10: o cliente baixa e sobe SÓ o modelo de produtos, sem nenhum
termo da plataforma; a 3Planejamento inteira continua sendo entregável da ECF. O que não se deduz do código:

- **A planilha real, medida (só contagens):** 70 variações / 56 produtos / 0 erros; Variação ordinal ("1" 48, "2" 14,
  "3" 1, "única" 7) → 14 produtos com mais de uma variação e NENHUMA com nome; 24 nomes de categoria para 56 produtos.
  Sem nome, `CoresDoGrupo` (régua do Sincronizar) tira as 2ªs cores do produto. A prévia agora diz isso num aviso só,
  neutro ("dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto"), calculado pela MESMA
  `CoresDoGrupo::separar` sobre como o produto vai ficar (arquivo + o que já está gravado).
- **Modelo v2 (`ModeloProdutosXlsx`, 14 colunas):** Ref* | Produto (grupo)* | Tipo de variação | Variação (nome) | Nome do
  produto* | Família | Ambiente(s) | Categoria | Nº volumes | Volumes | Peso total | Custo | Estoque | Descrição; abas
  Produtos, Instruções e Listas (oculta). **O leitor casa pelo NOME e a ordem dos casos importa:** "Produto (grupo)"
  tem de vir antes de "Produto …" (o nome na 3Planejamento) e "Tipo de variação" antes de "Variação". O arquivo antigo
  ("Grupo (anúncio)", "Categoria ML", ordinal) entra pela mesma regra — `ModeloEImportacaoTest` monta os arquivos com os
  11 cabeçalhos ANTIGOS de propósito, é a prova de compatibilidade. A linha de exemplo do modelo antigo também é ignorada.
- **Com o Tipo preenchido, a Variação é o nome ao pé da letra.** Sem isso "220" de Voltagem virava `ordem` 220. Sem o
  Tipo vale a regra antiga (1/2/única = posição). Tipo fora da lista vira "Outro" com aviso, nunca erro.
- **Estoque do Excel chega como double (10.0):** o normalizador só aceita inteiro em texto; por isso `estoque` está nos
  `TEXTUAIS` do leitor (10.0 → "10"; 2,5 → "2.5" → erro de unidades inteiras). 0 continua ≠ vazio.
- **Descrição é do produto:** grava pelo MESMO `DescricaoDoProduto` da ficha, dentro do `gravarLinhas` (só a 1ª linha do
  produto). O importador leva para a 1ª linha a primeira descrição que aparecer nas linhas do produto — quem escreveu na
  2ª não perde. Descrição que muda sozinha conta como "atualizada" e agenda o `PreparoIaAgenda`.
- **Baixar meus produtos (`/produtos/exportar`) faz ida e volta sem mudança:** medido na planilha real (importar →
  baixar → prévia = 70 "sem mudança"). A categoria sai pelo NOME (o id nunca sai, e nome não rebaixa id — BE-CR-02);
  produto sem código próprio sai com a Ref da 1ª variação no grupo, e o cadastro em MODO_IMPORTACAO aceita grupo = Ref
  de uma variação cadastrada (só se nenhum produto tiver aquele código).
- **Categorias em lote:** a prévia agrupa pelo nome digitado (sem caixa/acento) por PRODUTO, fora os que já têm id; a
  tela pede as sugestões por `/categorias/sugerir-nomes` (10 por pedido, 50 nomes por prévia, cache de 7 dias por nome
  só quando ACHA). A confirmação vai junto do arquivo (`categorias[{texto,id}]`) e o servidor descarta id que não é folha.
  O seletor é o `PickerCategoria` dentro de um Popover do Radix DENTRO do Dialog: provado no navegador que o clique não
  fecha a janela e que o Esc fecha só o seletor (as escolhas ficam).
- **Validação por intervalo some ao reler com o PhpSpreadsheet:** ele espalha `C2:C1001` em mil validações por célula.
  O arquivo está certo; teste de validação lê o XML da aba (`PlanilhaDeProdutosV2Test::validacoesDoArquivo`).
- **Fotos em lote (`FotosEmLoteService`, rotas `fotos/previa` e `fotos`):** `Ref_número.jpg`. Quem decide a variação é o
  SERVIDOR (a prévia recebe só os nomes): o nome inteiro que já é Ref vence ("MESA_2.jpg" é da Ref MESA_2 se ela existe),
  senão a Ref antes do ÚLTIMO "_". Grava pelo `VariacaoImagensService::enviar` de sempre, por variação, na ordem do número e
  DEPOIS das fotos que ela já tem, cortando no teto (12). O MIME é conferido arquivo por arquivo ANTES do `enviar`, que é
  tudo-ou-nada por variação: sem isso um .jpg falso derrubava as outras fotos da mesma cor. A tela manda remessas de 8
  arquivos / 8 MB e espera no 429 (`Retry-After`). O 413 do `bootstrap/app.php` só cobre a rota por variação; aqui a tela
  trata o 413 pelo `avisosDoErro`.
- **Ficha técnica pela planilha (`PlanilhaDasFichasTecnicas` + `ImportadorFichasTecnicas`, aba "Ficha técnica" da janela
  Importar planilha):** uma aba por categoria confirmada, colunas = campos da categoria ("*" no obrigatório, unidade no
  cabeçalho), listas na aba oculta. Volta casando a LINHA pelo "Produto (grupo)" (código ou Ref) e a COLUNA pelo NOME do
  campo na definição ATUAL (o nome da aba não importa). O `gravar()` da ficha SUBSTITUI e recusa a ficha inteira quando
  falta obrigatório — por isso a planilha usa `gravarParcial` (mesma `normalizar`, nunca apaga, o inválido volta listado,
  o obrigatório que falta vai em `faltam`). O campo-eixo do produto (a Cor de quem tem cores) é ignorado em silêncio.
- **Mensagens do arquivo:** `mimes:xlsx` sem mensagem própria mostrava a chave crua `validation.mimes` ao cliente nas
  importações; agora é `MENSAGENS_DO_ARQUIVO` no controller.
- **Ainda fala da plataforma (fora deste trabalho):** a página da lista diz "Consultar fretes no Mercado Livre" e
  "Conectando sua conta do Mercado Livre" (copy da 167, §34). Mudar pede decisão do usuário.

## 40. Frete do Mercado Envios segue o ML: tabela de 24/08, cubado sem corte, modalidade e frete sugerido (09/10/2026)

Tudo em `frete-mercado-envios.md` — leia antes de mexer em frete, cubagem, ME1/ME2/Full ou no frete da
Precificação. Em uma linha cada:
- a tabela de custos do ML mudou em **24/08/2026** (30 faixas, ganhou "9 a 10 kg"); o sistema seguia na de
  02/03 — provado pela cotação real da #459 (8,45 · 14,45 · 21,35) e pela página oficial, célula a célula;
- peso faturado = max(real, cubado), **sem** o mínimo de 5 kg da planilha;
- limites do ME2 pela **modalidade** da conta (Correios × Agências/Coleta × Full), lida de
  `shipping_preferences` na cotação real e guardada 7 dias; sem ela, Correios;
- **frete sugerido** na Precificação (D-19 revogada, ADR PORTAL-02): digitado → sugerido do próprio tipo →
  outro tipo (só sem sugestão) → nada; "Cotar agora" é a própria rota com `?cotar=1`;
- o ML trocou a tabela **2× em 2026**: falta uma checagem periódica (sugestão no §1 do arquivo).

## 41. Montar kit no Planejamento, o kit vai sozinho ao Publicador e o cliente vê só 4 submódulos (09/10/2026)

Pedido do usuário: "produtos, planejamento e precificação muito conectados; mapeamento para saber o que está
pendente; o resto vai ser meio inútil". Trabalho direto (sem GSD). O que não se deduz do código:

- **Menu: chave `sugestoes` = "Planejamento"**, logo depois de Produtos (o D-20 caiu). A chave `planejamento`
  continua sendo a AGENDA ("Cronograma", §36). Quem vê o quê: `VisibilidadeDoMapeamento` — cliente vê Produtos,
  Planejamento, Precificação e Mapeamento; equipe vê os 7; empresa com oferta simples SEM produto (importou, #131)
  ganha Lista SKUs e Anúncios. Sem deploy, em `configuracoes` (CSV, padrão do `creative_engine_usuarios`):
  `portal_estrutura_submodulos_cliente` troca a lista padrão; `portal_estrutura_submodulos_empresa_{id}` é a lista
  EXATA da empresa (vence a exceção automática; `todos` = os 7). **Esconder não remove**: rota, allowlist e
  controller ficam; a página escondida aberta por link aparece no menu marcada `oculto: true`, e o front
  (`portalSubmodulos.js::submoduloVisivel`) NÃO a conta como visível — é assim que os links internos (Lista SKUs,
  Cronograma) somem para quem não vê. A entrada do módulo usa a mesma régua (`ModulosPortal::submoduloVisivel`).
- **Montar kit** (`Geracao/MontagemManualDeOferta` + `RegrasDaMontagem`, rotas `sugestoes/montar/previa` e
  `sugestoes/montar`): item pela variação, ou pela oferta quando ela não tem variação (importada). A fase
  repete a regra de `EstruturaOfertaService::composicao` (privada, fora do escopo) — a gravação passa por ela de
  novo e um teste cruza as duas. Duplicata em DUAS réguas: as ofertas (mesmos componentes e quantidades, vale
  para item sem variação) e a chave de variação do `RetratoDoCatalogo` (pega os kits da Fase N); o retrato agora
  leva `detalhes.existentes_sku` para dizer "já existe: SKU X". `ChaveDeComposicao` aceita até 6 componentes.
- **Um padrão de nome/SKU**: a Lista SKUs (`FormOferta`) passou a pedir Kit/Combit à mesma prévia (antes
  "A+B-KIT"/"-CBT4"). O Combo também, em seguida (ver §41.1).
- **Vai sozinho ao Publicador**: `EstruturaOfertaService::criar` de oferta COMPOSTA chama
  `PreparoIaAgenda::aoSalvar` pelos produtos dos componentes, em `DB::afterCommit` (rollback não agenda); o
  "Aceitar" em lote e os combos em lote passam `prepararNoPublicador: false` e agendam UMA vez. Componente sem
  produto não agenda (segue pelo Sincronizar). Na fila `sync` nada agenda: o teste usa `Queue::fake()`.
- **Funil do Mapeamento** (`FunilDoMapeamento`, prop ADIADA `funil` — `Inertia::defer`): a ficha técnica só é
  conferida com a definição JÁ guardada (`ml_categoria_schemas` ou o cache `ml_meta_atributos_{cat}`), para o
  Mapeamento nunca buscar fora; os números do Planejamento e da Precificação são os das próprias telas (teste).
- **Teste**: trocar de empresa no mesmo teste pede `$this->app['auth']->forgetGuards()` (o guard guarda o
  usuário da requisição anterior e a página sai com o menu da outra empresa). Render no Node (esbuild): deixar
  `@radix-ui/*`, `react-remove-scroll` & cia. em `external`, senão "Dynamic require of react".
- **Textos que citavam a Lista para o cliente** (resolvido, ver §41.1): o vazio da Precificação, a frase "Cada
  variação vira uma oferta na Lista SKUs" (Produtos e ficha) e os exemplos do `ComoFunciona`.

### 41.1 O Combo com um nome só, e os textos de quem não vê a Lista (10/10/2026)

- **Combo da Lista = padrão do Planejamento** (`NomesSugeridos::combo`): o lote (`criarCombos`) monta o nome
  pelo produto da variação, o valor e o tipo efetivo (escolhido no Planejamento > inferido) — "Kit 4 Cadeiras
  Polo — Natural"; o combo de UMA quantidade do `FormOferta` pede a prévia do Montar kit com a oferta como item
  e mostra "Combo N …" só até a resposta (o campo nunca fica com a quantidade errada). **Oferta sem produto
  (importada) continua "Combo N {nome}", sem tipo — de propósito**: o Montar kit trata o avulso assim
  (`itemAvulso`); inferir o tipo pelo nome criaria a divergência de volta. Por isso `ComposicaoDaOfertaTest` e
  `AcessoAoModuloEstruturaTest` ("Combo 2/4 Cadeira 01", ofertas sem produto) não mudaram.
- **Nada foi renomeado**: os combos criados antes seguem com "Combo N …" no banco; só o padrão de criação mudou.
- **A leitura do tipo de UM produto agora existe em dois privados** (`PlanejamentoDaFaseService::tipoParaNome`
  e `EstruturaOfertaService::tipoParaNome`) além do retrato em lote; quem mexer na regra do tipo efetivo mexe
  nos três. O `CombosDaListaNoPadraoDoPlanejamentoTest` cruza o nome gravado com a sugestão do gerador.
- **Textos por visibilidade**: `portalSubmodulos.js::textoDaVariacao(modulos)` dá a frase de Produtos/ficha;
  o vazio da Precificação leva o cliente a Produtos. Os exemplos do `ComoFunciona` são conferidos contra o
  `NomesSugeridos` (`NomesSugeridosTest`, que lê o .jsx).
- **Ainda citam a Lista para o cliente (não mexido)**: `JanelaExcluirVariacao.jsx` ("também será excluída da
  Lista SKUs", na ficha) e `MSG_SKU_REPETIDO` de `sugestoesEstrutura.js` ("…na Lista SKUs", no Planejamento).

## 42. Todos veem só os 4 submódulos e a Marca é livre (10/10/2026)

- **Equipe também fica com os 4.** O usuário, vendo o portal como equipe: "Lista SKUs e Anúncios eu não vou usar,
  pode tirar — fica Produtos, Planejamento, Precificação e Mapeamento". `VisibilidadeDoMapeamento::visiveis` deixou de
  dar `null` (todos) para `AtorDoPortal->equipe` e perdeu a exceção de quem importou ofertas (#131 via Lista SKUs e
  Anúncios). O Cronograma (chave `planejamento`, rótulo "Cronograma") também saiu: não está na lista dele. Válvula sem
  deploy: `configuracoes.portal_estrutura_submodulos_empresa_{id}` (`todos` = os 7) e
  `portal_estrutura_submodulos_cliente` (padrão de todos). As páginas escondidas continuam abrindo por link; os textos
  que dependiam de quem vê a Lista já usavam `submoduloVisivel` — agora todos leem a versão "sem Lista". Os fixos que
  citavam a Lista (excluir variação, SKU repetido, padrão do `textoResultadoAceite`, aviso do "Criar Fase N") mudaram.
- **Marca é texto livre.** A regra "quem tem opção vira lista" (§35) fechava a Marca em Escrivaninhas (MLB193946) e
  Mesas para PC (MLB439418), onde o catálogo traz 5 marcas — com `value_type: string` (a plataforma aceita outra).
  `FichaTecnicaDaCategoria::ID_MARCA` vira `texto` sempre; as opções vão em `sugestoes` (um `<datalist>` no
  `CampoFichaTecnica`). Os outros campos com opção continuam "só escolhe da lista".

## 43. Excluir produtos inteiros e a metodologia à vista no "Montar à mão" (10/10/2026)

**Excluir produtos (`ExclusaoDeProdutos`, rotas `produtos.exclusao.previa` e `produtos.exclusao`).** Pedido do
usuário para limpar os produtos de teste. Antes só existia excluir variação por variação, dentro da ficha.

- **Não há regra nova por baixo.** Cada variação sai por `ProdutoCadastroService::excluirVariacao`, e cada oferta
  montada por `EstruturaOfertaService::excluir`, tudo numa transação só.
- **A diferença para a variação é decisão do usuário.** A exclusão de variação (D-22) BLOQUEIA quem é componente de
  combo ou kit. A de produto leva as ofertas montadas JUNTO. Componente é sempre oferta simples, então a cascata tem
  um nível só; as montadas saem primeiro, porque a FK `eoc_componente_fk` é a única `restrict` do caminho.
- **Dois passos, de propósito.** A prévia diz o que sai junto, e a exclusão só segue com as montadas que a pessoa
  VIU (`montadas` no corpo). Apareceu outra no meio do caminho: 422 em `montadas`, e a janela mostra a lista de agora.
- **O item que a equipe já tinha do outro lado continua lá, solto (D27).** O Portal não o apaga; isso é do
  "Excluir" do Publicador (learnings publicador-ml §23).
- **Id de outra empresa conta como id que não existe:** fica de fora e entra em `nao_encontrados`. Se nenhum é da
  empresa, responde 404.
- **`em_uso`** junta os dois sinais (anúncio do módulo antigo ligado à oferta, ou item do Publicador ligado à oferta
  ou ao produto) numa frase que não revela nada: "já está em uso pela equipe da ECF".

**Três gates de teste que pegam quem mexer nessas telas:**
- **Sigilo barra classe de estilo.** O `PROIBIDO` dos testes de sigilo tem `\bMLB?\b` sem distinção de caixa, e
  `className="ml-auto"` casa. Em arquivo novo do Portal, alinhe com `justify-between` ou `mr-auto`.
- **"Nada vermelho" nos cartões** (`estrutura-produtos-lista.test.js`, T-167-79): `PecasDoProduto`, os dois cartões e
  a `ListaProdutos` não podem ter classe `red-N`. O "Excluir produto" do menu ⋮ é neutro; o vermelho fica na janela
  de confirmação e na barra da seleção.
- **A ficha conta as saídas.** `estrutura-produtos-ficha.test.js` exige exatamente 3 `irParaLista(`. A exclusão do
  produto e a da última variação saem pela mesma `sairComProdutoExcluido`.

**"Montar à mão" (`MontarKitAMao`).** O usuário disse que a criação manual "não tinha a metodologia de combo, kit e
combit". O servidor já classificava certo pela composição (`RegrasDaMontagem::fase`, a regra da Lista SKUs), e as 6
ofertas montadas da #459 estavam com o tipo certo. O problema era só de tela: tudo se chamava "Montar kit" e o tipo
aparecia num selo pequeno da prévia. Agora o título e os botões dizem "combo, kit ou combit", há uma legenda fixa dos
três tipos (Fase 2, 3 e 4, com as palavras do "Como funciona") que acende o tipo da composição pela `fase` que o
servidor devolve, e o botão diz o que nasce ("Criar Combit"). Nenhuma regra foi para o navegador.

## 44. Precificação agrupada por família — quem agrupa é o servidor, antes de paginar (11/10/2026)

**Pedido:** a Precificação era "uma lista inteira sem saber o que é". O usuário queria o produto unitário com as
variações dele embaixo, e levantou o nó do kit: "vou pegar um produto de um e de outro", então o kit teria dois
produtos-pai na tela.

**O desenho aprovado** (artefato + pergunta com prévia, sim em 11/10):
- grupos por família, que abrem e fecham;
- cada produto UMA vez, com os combos dele pendurados;
- kit e combit em "Conjuntos desta família", uma vez só;
- o produto que entra num conjunto diz "Também entra em";
- "Sem família" por último.

**O que não é óbvio:**
- A família já existia no cadastro de Produtos (`estrutura_produtos.familia_id`). A oferta chega a ela pela
  variação (`estrutura_ofertas.variacao_id`). Combo, kit e combit NÃO têm variação própria: herdam a do componente
  (`FamiliasDasOfertas::resolver`).
- O conjunto fica na família do PRIMEIRO componente que tiver uma. Componente de outra família (ou sem família)
  aponta para ele pelo `tambem_em`, que `paginaOfertas` já mandava para as outras telas.
- **A ordem por família tem de vir ANTES da paginação.** A página é de 25 blocos na ordem de vendas; agrupar só o
  que a página trouxe repetiria a mesma família em páginas distantes. `paginaOfertas(..., ['por_familia' => true])`
  ordena e manda `familia` em cada bloco. Sem a opção, as outras três telas saem byte a byte como antes.
- O filtro por tipo (`?tipo=combo`) é do servidor pelo mesmo motivo. A contagem dos tipos é a de `painel.por_fase`,
  da empresa inteira; contar na página daria "Combo 2" sobre uma lista de 40 (o mesmo erro do §25).
- Com filtro ou busca, o bloco pode vir SEM o produto principal (só os combos dele). A tela mostra o que casou,
  raso, ainda na família do produto.
- A coluna do produto tem ~220 px (a tabela usa quase toda a largura em preço e frete). Por isso o código quebra
  de linha em vez de cortar, o nome mostra duas linhas, e o atalho "Também entra em" mostra tipo + código.
- `EstruturaVisaoService.php` está em CRLF no disco. Script de edição que casa texto com `\n` não acha nada lá.

**Prova sem servidor:** `scratchpad/prova_familia.mjs` desenha a página de verdade no Puppeteer com o CSS do build
e props no formato do servidor, clica em recolher, fechar e no atalho, e fotografa.

## 45. Categoria: navegar pela árvore, além da busca (11/10/2026)

O usuário procurou a categoria de um anúncio cujo caminho no Mercado Livre era "Casa, Móveis e Decoração > Móveis
para Casa > Cadeiras, Sofás e Banquetas > Sofás > Cadeira" e não achou. Pediu para entender por quê e para poder
navegar nível a nível, "assim como é no Mercado Livre".

- **A busca nunca foi a árvore: é o preditor** (`domain_discovery/search`, até 8 palpites). Com texto curto ele erra
  feio — "cadeira" devolve Abraçadeiras e Chocadeiras. Para o texto do usuário devolveu "Sofás", que ERA a categoria
  daquele anúncio.
- **"Sofás" (`MLB1626`) é o último nível.** O "Cadeira" que a página do anúncio mostra depois dele não é categoria
  (conferido: não é filha na árvore, nem opção de "Tipo de produto", que em Sofás e Poltronas tem Sofá, Divan, Futon,
  Poltrona e Sillón). Quem cadastra escolhe "Sofás". Não adianta procurar um nível a mais.
- **A árvore vem de dois endpoints públicos:** `/sites/MLB/categories` (32 raízes, cada uma com 4 a 40 filhas) e
  `/categories/{id}` (`children_categories`, `path_from_root`, `settings.listing_allowed`). `children_categories`
  NÃO diz se a filha é a última: para a tela saber quem "abre" e quem "se escolhe", `CategoriaBuscaService::nivel`
  lê as filhas em paralelo e guarda no mesmo cache de 7 dias da busca (`ml_meta_categoria_{id}`). Filha que não
  respondeu sai com `folha: null` e a tela descobre ao entrar.
- **`MlCatalogoMetaService::categoria()` guarda o `[]` de uma queda por 7 dias** (`Cache::remember`). Na navegação
  isso deixaria a categoria "fora do ar" até o cache vencer; `categoriasEmLote` solta a chave quando a leitura única
  volta vazia. (O `CategoriaSugestaoService` do Portal já evitava o problema por outro caminho.)
- **Um componente só para as duas telas:** `Components/Categorias/NavegadorDeCategorias.jsx` +
  `useArvoreDeCategorias` + `lib/arvoreDeCategorias.js`. Cada tela passa a sua rota
  (`portal.auth.estrutura.produtos.categorias.arvore` e `mlb.anuncios.publicador.categorias.arvore`). Como aparece
  para o cliente, os três arquivos estão sob o teste de sigilo (nada de plataforma nem em comentário).
- **Abrindo com categoria já escolhida, a navegação começa no nível de cima dela**, com a escolhida marcada entre as
  vizinhas — recomeçar de "Todas as categorias" obrigava a refazer quatro cliques para trocar Poltronas por Sofás.
- Rota nova do Portal = linha nova na allowlist de `RestringeDominioDoPortal` (§ da MAG); sem ela o domínio do
  portal responde 404.

**Prova sem servidor:** `scratchpad/nav/provar.mjs` monta o componente de verdade (hook com efeitos) no Puppeteer com
uma árvore de mentira: desce três níveis, volta pela trilha sem ler de novo, escolhe, e abre numa categoria já
escolhida. Teste estático não exercita o vai-e-volta.

## 46. "Outro": valor próprio na lista, só onde o catálogo aceita (11/10/2026)

**Reabre, a pedido do usuário, o que ele mandou fechar em 09/10** (§38, "campo com opção é SÓ lista"; o "Outro
(digitar)" de `dc48b1b7` foi desfeito no mesmo dia). Em 11/10, replicando um produto, o "Material do estofamento"
certo não estava entre as opções e a ficha obrigava a escolher uma: "além das opções, deixa também para colocar
personalizado, os que aceitarem. Então verifica no Mercado Livre o que dá para aceitar".

- **O que o catálogo aceita (medido em Poltronas e Sofás, `technical_specs/input`):** dos 12 atributos `string` com
  opções, 11 trazem `allow_custom_value: true` (Material do estofamento, Material dos pés, Materiais da estrutura,
  Desenho do tecido, Linha, Densidade da espuma, Acessórios…) e o 12º, a Cor, não traz o sinal; os 5 `list` trazem
  `false` (Tipo de produto, Estilo, Formato, Orientação, Quantidade de lugares). A regra da ficha — `aceita_outro` =
  lista cujo atributo é `string` com opções — é a mesma do classificador sem `ui_config`, e um teste confere que ela
  bate com o que o editor decide pelo `allow_custom_value` nas quatro respostas reais guardadas.
- **A diferença para a versão de 09/10:** a lista continua vindo primeiro e "Outro (escrever)" é a última opção, uma
  escolha explícita; o texto que bate com o nome de uma opção vira a opção (não nasce "COURO" ao lado de "Couro");
  lista fechada continua fechada; e o valor próprio vai MARCADO no corpo (`proprio: true`, ou `proprios: [...]` no
  campo de várias opções). Sem a marca o servidor se comporta como antes: um id que saiu do catálogo, ou um texto
  cru, continua 422 — não vira valor próprio por engano.
- **Sem migration.** O texto vai em `valor` com `valor_id` nulo, o mesmo formato do texto antigo. "|" é recusado no
  valor próprio porque é o separador das várias opções na linha gravada.
- **Planilha da ficha:** nesses campos a lista suspensa passa a só sugerir (a validação estrita do Excel barraria o
  valor próprio), a célula fora das opções entra marcada, e a planilha baixada devolve o texto (antes saía em branco).
- **No rascunho da equipe:** valor único vai como `value_name` sem `value_id`. Com várias opções, opções e próprios
  vão JUNTOS como texto separado por vírgula, com `revisar` — `values_multi` só guarda id de opção. Isto troca o D-13
  ("com opção resolvida, o nome que não casa fica de fora"): quando ele foi escrito o nome fora da lista era lixo
  antigo; agora é o que o cliente pediu. Se o schema do editor disser que o campo é fechado, o valor não vai e o
  campo aparece pendente — a ficha nunca publica o que o catálogo recusa.
- O editor da equipe (`CampoAtributo`) já aceitava texto nesses campos (caixa com sugestões); nada mudou lá.

## 47. O combo que "sumiu" e ficou sem frete: ele é oferta, e o pacote somado vira ME1 (11/10/2026)

O usuário montou combos no Planejamento e relatou: "não aparece para onde ele vai… talvez seria bom ele ir para
Produtos, e saber que esse produto é um combo de outro"; e "no kit mostrou o ME2, deu o valor; no combo não mostrou
nada de frete. Nem sei se é ME1".

- **Combo, kit e combit são OFERTAS, não produtos.** Gravam em `estrutura_ofertas` + `estrutura_oferta_componentes`
  (`EstruturaOfertaService::criar`); nada nasce em `estrutura_produtos`. A aba Produtos lista só produtos, e o único
  retorno do Planejamento era um aviso de 6 s ou a tela "criado" da janela — fechou, sumiu o rastro.
- **Não há tratamento por tipo no frete: a diferença é a conta.** `ConjuntoLogistico::volumes` repete as caixas de
  cada unidade e `LogisticaProduto::pacote` soma alturas e pesos. Medido na #459: a poltrona (53×60×80, 8 kg) cabe no
  Mercado Envios (Correios: 100 cm no maior lado, 200 na soma) e mostra frete; o Combo 2 dela vira 53×60×160, 16 kg,
  passa nos DOIS limites e é ME1. ME1 nunca tem frete do ML a sugerir. Os 7 combos da conta estão assim; em
  Agências/Coleta (200 / 300) cinco deles caberiam. Não é "a conta não tem ME1": é o tamanho.
- **A Precificação ficava muda:** campo de frete em branco, só o selo "Sem frete". Agora cada oferta leva `envio`
  (`EstruturaPrecificacaoService::envio`): a logística, o pacote (dito "somado", com o nº de caixas, no conjunto), os
  limites que passou (`LogisticaProduto::estouros`), quem está sem medida e a logística declarada. A tela mostra isso
  sob o nome e "sem tabela: digite" sob o frete em branco; a explicação inteira vai no balão.
- **Produtos passou a apontar para os conjuntos:** `oferta.conjuntos` em `ProdutoLinhas` (tipo, código, nome,
  quantidade) vira "Também vendido em [Combo 2 · SKU]" na lista e na ficha, com atalho para a Precificação.
  `oferta.usada_em` (só SKUs) continua como estava: é o contrato da janela de exclusão.
- **O combo aberto sozinho diz do que é feito.** "Precificar agora" abre `?q=<SKU do combo>`, e a busca tira o
  produto pai da página; sem ele por perto, a linha mostra "2× produto".
- **Em aberto (decisão do usuário):** as medidas do conjunto não são editáveis — são a soma. Dar pacote próprio ao
  combo (as duas poltronas numa embalagem menor) exige guardar medidas por oferta e levar a mesma regra ao
  `PacoteDoKit` do Publicador, senão a Precificação e o anúncio discordam. A soma segue provisória
  ("por enquanto deixa somando", [[frete-mercado-envios]]).
- Lido e NÃO conferido rodando: com "Ver N combos" aberto, a página do Planejamento pode mandar mais de 20 chaves
  para `sugestoes.frete`, cuja validação é `max:20` (422). Conferir antes de dar como defeito.

## 48. Planilha: o modelo completo, com a categoria escolhida ANTES de baixar (13/10/2026)

Pedido do usuário: "preciso dela completa; o problema é que a ficha técnica vem de acordo com a categoria. Queria
saber se tem como… eu escolher a categoria do produto na planilha e me mostrar as fichas técnicas. Se não, vamos ter
que fazer que nem na Amazon." Aprovado: "ok, faça modo Amazon".

- **Dentro da planilha não dá.** Um .xlsx não consulta nada sozinho (só com macro, que cliente não abre e que não roda
  no Google Planilhas), e cada categoria tem colunas diferentes (20 a 45 campos nas medidas) entre milhares de
  categorias. O que dá é escolher a categoria antes e baixar o arquivo já montado para ela.
- **Como ficou:** em "Importar planilha" → "Baixar o modelo completo (com a ficha técnica)" a pessoa escolhe de 1 a
  10 categorias com o MESMO seletor da ficha (buscar pelo nome ou navegar). O arquivo tem uma aba por categoria, com
  as colunas do produto (as do modelo de Produtos, sem a "Categoria" e com o grupo na frente) e, em cabeçalho mais
  claro, uma coluna por campo da ficha. `ModeloCompleto` monta; as colunas da ficha saem de
  `PlanilhaDasFichasTecnicas::colunasDaFicha` (mesmas listas, "*", unidades e "Outro").
- **A importação NÃO tem regra nova:** `ImportadorModeloCompleto` roda as duas que já existiam, na ordem. (1) As
  colunas do produto de cada aba viram um arquivo de passagem no formato do modelo de Produtos — com as linhas nos
  MESMOS números da aba enviada, para o "linha 7" da prévia ser a linha 7 — e passam pela `ImportadorProdutos`, já com
  a categoria da aba confirmada. (2) A ficha passa pela `ImportadorFichasTecnicas` sobre o mesmo arquivo. O envio é o
  de sempre (`produtos.importacao`): o servidor reconhece o modelo pela aba oculta "Categorias" (marca em A1).
- **A prévia confere a ficha de produto que ainda não existe** com um `EstruturaProduto` de passagem (nunca gravado)
  na categoria da aba — `planejarParcial` só lê. Na gravação há a trava inversa: produto que não foi criado não recebe
  ficha (`! $produto->exists`), senão o `updateOrCreate` gravaria com `produto_id` nulo.
- **A ficha é do PRODUTO, não da variação:** preenche-se na 1ª linha; as outras linhas do mesmo produto só completam
  o que ficou em branco (sem o aviso "vale a primeira", que continua valendo na planilha antiga da ficha).
- **Produto que já está em outra categoria** não troca de categoria nem recebe a ficha da aba errada, e isso é dito
  ("já está em outra categoria; a ficha técnica desta aba não vale para ele"). Sem a mensagem a coluna era ignorada
  em silêncio.
- **Sigilo no arquivo:** a aba oculta guarda só os NÚMEROS do código da categoria (o prefixo é devolvido na leitura);
  o teste varre abas, células e validações atrás dos termos.
- **Número digitado como número** (custo 120,5; estoque 10) chega do Excel como float: vira texto com vírgula antes de
  ir à importação de produtos, senão "120.5" poderia ser lido como milhar.
- Os dois modelos antigos (Produtos; Ficha técnica) continuam iguais. Rota nova = linha nova na allowlist do Portal e
  +1 nas duas contagens do `AcessoAosProdutosTest`.
- **Fora do escopo:** fotos (seguem em "Enviar fotos em lote") e combos/kits (são ofertas, não produtos).

**Mesmo dia, segunda volta — "todos têm que vir tudo completo, não ter opção de baixar apenas modelo" e "volumes vir
separado por coluna":**

- **Nenhuma tela baixa mais o modelo só de produtos.** "Baixar modelo" (barra, estado vazio e janela de importação)
  abre a escolha da categoria (`JanelaBaixarModelo`, larga, duas colunas: achar a categoria / as escolhidas). As rotas
  antigas (`produtos.modelo`, `produtos.exportar`) seguem existindo e a importação continua aceitando os arquivos
  antigos — quem já tinha uma planilha preenchida não perde o trabalho. Só os botões saíram.
- **"Baixar meus produtos" também é o completo** (`ModeloCompleto::exportar`, rota `produtos.exportar_completo`): uma
  aba por categoria confirmada, com a ficha já salva na 1ª linha de cada produto. Produto sem categoria confirmada vai
  para a aba **"Sem categoria"**, que mantém a coluna "Categoria" e não tem ficha; na aba oculta ela aparece com `-` no
  lugar do código, e a importação da ficha pula essa aba (`$abasDoModelo[aba] = null`).
- **Volumes em colunas:** "Volume N: comprimento (cm) / largura (cm) / altura (cm) / peso (kg)". O modelo vazio traz 3
  volumes; o exportado, quantos o maior produto tiver (teto 8). Na leitura as quatro colunas viram o MESMO texto de
  antes (`186×43×12 · 27,8 | …`) e seguem pela regra que já existia — volume com medida faltando é aviso, não erro
  mudo. A planilha antiga, com tudo numa célula, continua sendo lida.
- **O arquivo leva o nome da categoria** ("poltronas-modelo.xlsx"; duas ou três, todas no nome; acima disso, as duas
  primeiras e "e-mais-N"). Quem dá o nome é o servidor (`ModeloCompleto::nomeDoArquivo`), só com letras sem acento,
  números e hífen. **A tela baixa por `axios` com `blob`, e aí o nome do `Content-Disposition` NÃO vale sozinho:** o
  navegador usa o `download` do link criado no JavaScript. Por isso a tela lê o cabeçalho
  (`nomeDoArquivoBaixado`) em vez de ter o nome fixo; trocar o nome só no controller não muda nada para quem baixa.
- **Armadilha:** a importação da ficha avisa "coluna que não é da ficha" para todo cabeçalho que não reconhece. As
  colunas do produto e dos volumes precisam estar na lista de `ModeloCompleto::chavesDosCabecalhos()`, senão cada
  planilha importada vem com uma dúzia de avisos falsos.

**Mesmo dia, terceira volta — o usuário preenchendo a planilha de verdade:** "tem muitas dimensões que pedem para
preencher mas não é especificado o que é, como produto por fora da caixa, embalado"; "uma medida de colchão que pedem
de uma poltrona não deve ser aplicada, mas mostra como se eu tivesse que preencher"; "tem colunas que preciso marcar
Não se aplica, mas não mostra as opções"; e o erro "Quantidade de lugares - Escolha da lista".

- **O erro era número contra texto.** "Quantidade de lugares" é uma LISTA cujas opções são "1", "2", "3"… A planilha
  gravava as opções como texto; quem digita 1 na célula digita um NÚMERO, e a validação em lista compara pelo tipo:
  recusava o 1. Agora a opção que é só um inteiro vai para a aba "Listas" como número
  (`PlanilhaDasFichasTecnicas::opcaoNaLista`), e o valor já salvo também (`escreverValor`). Na volta o número vira "1"
  e casa com a opção pelo nome, como sempre.
- **A mensagem que a pessoa vê depende do programa.** O texto que o usuário colou ("Ocorreu um erro / Quantidade de
  lugares - Escolha da lista.") é o `promptTitle` + o `prompt` da validação — a DICA, não o `error`. Há programa de
  planilha que mostra a dica quando recusa. Por isso a dica agora diz sozinha o que fazer ("Escolha uma das opções da
  lista (a seta da célula)…"), e não se pode contar com a dica aparecer ao clicar na célula: o que precisa ser lido
  vai em CÉLULA.
- **Três linhas acima dos produtos** (`ModeloCompleto::apresentar`): a 1 é a FAIXA dos blocos (Produto / Produto
  EMBALADO · Volume N / Custo, estoque e descrição / Ficha técnica / Medidas do produto FORA DA CAIXA / Mais detalhes),
  repetida a cada 4 colunas para não sumir ao rolar; a 2 é o cabeçalho, escuro no obrigatório e claro no opcional; a 3
  é a AJUDA ("Opcional. Só o número, em cm. Se o produto não tem isto, escreva: Não se aplica."). Os produtos começam
  na 4. A explicação longa (o mesmo "o que é isto?" da tela) vai de nota no cabeçalho e na aba "Guia dos campos".
- **"Não se aplica" em coluna de número não tem lista**, e não deve ter: uma lista de uma opção marcaria todo número
  digitado como inválido em quem trata lista como aviso. O caminho é a linha de ajuda dizer, coluna por coluna, onde
  ele vale. A importação já aceitava o texto "Não se aplica" em qualquer coluna que aceita.
- **A importação não ganhou versão.** `ImportadorFichasTecnicas::abaDaMatriz` acha o cabeçalho pela coluna "Produto
  (grupo)" entre as 3 primeiras linhas e pula a linha cuja célula do grupo começa com "Como preencher". O arquivo de
  antes (cabeçalho na linha 1) entra igual, e apagar a faixa não quebra nada. A aba "Guia dos campos" é pulada pelo
  NOME: ela lista "Produto (grupo)*" numa célula e seria tomada por aba de produtos.
- **As medidas do produto tinham explicação errada vinda do catálogo**, e valia para qualquer categoria (a explicação
  é guardada por id do atributo): Largura = "Largura x Altura"; Profundidade de poltrona = "…da mesa". As seis
  (`LENGTH`, `WIDTH`, `HEIGHT`, `DEPTH`, `DIAMETER`, `WEIGHT`) ganharam texto fixo em `publicador_glossario.atributos`,
  que vence o guardado — vale também para a ficha na tela e para o editor interno.
- **Volumes recolhidos** ("em produto embalado, deixa apenas um volume; o resto deixa minimizado"): os volumes que
  não estão em uso vão num grupo de colunas recolhido (`recolherVolumes`). No modelo fica aberto só o Volume 1; no
  "meus produtos", quantos o produto com mais caixas DAQUELA aba usa — medida preenchida nunca nasce escondida. O
  botão "+" fica à esquerda do grupo (`setShowSummaryRight(false)` + `collapsed` na última coluna aberta), senão ele
  aparece lá no "Custo", longe do Volume 1; a faixa do último volume aberto avisa onde clicar. A importação lê coluna
  recolhida como qualquer outra. Para conferir pelo Excel: lá `OutlineLevel` conta a partir de 1 (2 = agrupada).
- **Para ver a planilha sem Excel licenciado:** o Excel desta máquina está sem ativação e rejeita `CopyPicture` por
  COM (`RPC_E_CALL_REJECTED`), deixando um processo oculto para trás — encerrar SÓ o de `MainWindowHandle` 0 e
  `StartTime` de agora, nunca todos (o usuário costuma estar com a planilha aberta). O que funciona:
  `Writer\Html` do PhpSpreadsheet sobre as primeiras linhas + Puppeteer. Abrir por COM ainda serve para provar que o
  arquivo abre sem reparo (nome sem "[Reparado]", contagem de abas e de notas).

## 49. O menu do Portal mostra só o Mapeamento Estrutural (13/10/2026)

Pedido do usuário: "no portal do cliente, ocultar tudo e deixar apenas mapeamento estrutural".

- **O que mudou:** sem configuração, o menu tem só o Mapeamento Estrutural, para cliente E equipe (a mesma régua dos
  submódulos, `VisibilidadeDoMapeamento`: quem confere o portal pela porta da equipe precisa ver o que o cliente vê).
  Início, Onboarding, Calculadora de Custo e PPA ficam escondidos. Quem decide é `VisibilidadeDosModulos`.
- **Esconder não remove.** Rotas, allowlist e controllers continuam; `/onboarding`, `/ppa` e `/calculadora` abrem por
  link direto, com o item no menu marcado `oculto` enquanto a pessoa está nele. **A exceção é o Início:** o login, a
  porta da equipe e o link aberto mandam para `/inicio` (5 lugares), então, escondido, ele redireciona para o primeiro
  módulo do menu (`ModulosPortal::entrada`). Sem isso todo mundo cairia numa tela que não está no menu.
- **Como devolver um módulo, sem deploy** (linhas em `configuracoes`, lista separada por vírgula com `inicio`,
  `onboarding`, `calculadora`, `ppa`, `estrutura`):
  - `portal_modulos_cliente` = lista padrão de todos; `todos` devolve os cinco;
  - `portal_modulos_empresa_{id}` = lista EXATA daquela empresa (vence a padrão); `todos` também vale.
  Lista só com lixo vale como ausente (o portal nunca fica sem menu).
- **Por que foi seguro fazer para todos, medido em produção no dia:** 2 usuários de cliente ativos no Portal, ligados
  a 1 empresa só; nenhum plano de PPA nela. Ou seja, nenhum cliente real perdeu tela. **O que muda de verdade é para a
  equipe:** as portas do Portal ficam no painel do Onboarding (`Onboarding/Detalhe`, aba Onboarding de `/companies`), e
  quem entra por ali agora cai no Mapeamento Estrutural, não no Onboarding. Havia 5 onboardings em andamento e 14 em
  rascunho. Se a operação do onboarding pelo Portal voltar, é `portal_modulos_empresa_{id} = onboarding,estrutura`
  (ou a lista padrão), não código.
- **Antes de dizer a um cliente "está no portal"** (PPA, onboarding), confira o menu dele: o docblock de
  `ModulosPortal` explica por que módulo que some sem aviso é o pior comportamento, e isso voltou a valer.
- **`portal_tickets_equipe` não é histórico de uso:** `emitir()` apaga os vencidos a cada passagem nova. Contar linhas
  ali para saber "quanto a equipe usa o Portal" dá sempre 1 ou 2.
- **Testes:** 19 testes de login, sessão e Início dependiam de `/inicio` responder 200 e do menu inteiro. Eles não são
  sobre o menu, então as 8 classes ganharam a trait `Tests\Concerns\PortalComTodosOsModulos`, que grava
  `portal_modulos_cliente = todos` (o Laravel chama `setUp{NomeDaTrait}` sozinho, depois de recriar o banco). Teste
  novo de página do Portal que precise do Início ou de outro módulo no menu usa a mesma trait; o regime padrão está em
  `VisibilidadeDosModulosTest`.

## 50. Precificação em "linhas enxutas": um cartão por produto, o detalhe sob demanda (13/10/2026)

O usuário, dois dias depois de aprovar a tabela por família (§44): "está muito ruim e confuso… o jeito que ficou em
famílias ficou muito ruim". Pediu propostas em artefato; entre quatro (linhas enxutas, cartões com abas, lista +
ficha, tabela por quantidade) escolheu a primeira: "traz o anúncio simples, o combo e etc juntos para precificar no
mesmo card".

**O que não é dedutível do código:**
- **O agrupamento do §44 não mudou; o desenho, sim.** Família, "Conjuntos desta família" e "Também entra em" seguem
  valendo, e quem agrupa continua sendo o servidor. O que o usuário reprovou foi a TABELA: seis linhas de letra miúda
  por oferta, quatro preços com o mesmo peso e o motivo do ME1 repetido em todo combo. A família virou um título
  leve; a unidade é o cartão (`cartoesDaFamilia`), com a unidade e os combos dela dentro.
- **O que saiu da linha foi para o detalhe, não para o lixo:** envio por extenso, um frete por tipo com a origem de
  cada um, os quatro preços com botão de copiar, "Feito de", "Também entra em" e "Ajustar percentuais". Na linha ficam
  a etiqueta do envio ("Full", "ME1" — `seloDoEnvio`, texto inteiro no balão) e a frase sob o frete em branco ("sem
  tabela: digite"). O medo de 11/10 ("nem sei se é ME1", §47) continua atendido por esses dois.
- **A comissão é da empresa: foi para o cabeçalho da coluna.** Produto com percentual próprio leva o selo "Ajustado".
- **Um campo de frete na linha, e ele grava nos DOIS tipos.** Vale quando os dois valem o mesmo: digitados iguais, os
  dois em branco, ou um em branco HERDANDO o outro (`frete_origem === 'outro_tipo'`, o "mesmo do Clássico" de quem
  digitou só um). Com Clássico digitado e Premium em branco com sugestão PRÓPRIA não é um frete só: a linha mostra os
  dois campos (`freteUnico`). Quem decide entre um campo e dois é o que o SERVIDOR tem gravado, não o que se digita
  agora — decidir pelo estado da digitação troca o campo de forma no meio dela e o cursor se perde.
- **O preço da PROMOÇÃO é o número grande; o de anunciar vai embaixo.** É o que o comprador paga e o que fecha a
  conta. Clicar em qualquer um copia (o ícone aparece ao passar o mouse, FORA da coluna, para o número continuar
  alinhado à direita entre os cartões). Sem o frete o preço aparece apagado e sem copiar: ainda não é o preço.
- **O cabeçalho das colunas e cada linha usam a MESMA grade** (`COLUNAS`). Cada cartão é uma grade própria; com
  larguras diferentes os números desalinham de um cartão para o outro. A prova mede a borda direita do preço em
  todas as linhas e cobra um valor só.
- **A linha acesa pelo atalho fica dentro de um cartão com `overflow-hidden`.** O contorno do `[data-aceso]` é para
  fora e sumiria nas pontas: há uma regra em `app.css` que o põe para dentro só em `[data-linha-preco]`.
- **Ficou de fora:** o filtro "Só pendentes" das propostas. Pendência é calculada no PHP por oferta e a página é
  paginada por bloco: filtrar só o que a página trouxe daria o erro do §25. Precisa ser filtro do servidor.
- **Prova sem servidor:** `scratchpad/prova_linhas.mjs <pasta>` desenha a página no Puppeteer com o CSS do build e os
  números do print do usuário, abre o detalhe, usa o atalho, recolhe, edita os parâmetros, digita no frete único e
  confere o PUT que sairia (os dois tipos), e fotografa em 1440 e em 900 px.

## 51. A porta da equipe virou aba de `/companies` — "Portal do cliente" (19/10/2026)

Pedido do usuário: entrar no portal sem passar pelo Onboarding, porque o portal passou a ser usado sobretudo pelo
Mapeamento Estrutural. Até aqui a única porta pela tela era o menu "…" de cada linha da aba Onboarding — **empresa sem
onboarding não tinha como ser aberta** (o §49 ainda descreve esse estado).

- **Onde ficou:** aba `?tab=portal` de `/companies`, ao lado de Onboarding. Sub-abas "Empresas" (todos que podem
  entrar) e "Acessos do portal" (admin). **Acessos saiu do Onboarding** por decisão do usuário; os endereços velhos
  continuam chegando: `?tab=onboarding&sub=acessos` é traduzido no `useState` da aba em `Companies/Index.jsx`, e
  `/acessos-portal`, o checklist da Fase 152 e o "Sair do portal" já apontam para `tab=portal`.
- **A lista é a régua de `PortalEquipeService::podeEntrar()`, montada no servidor** (`portal_empresas`): admin vê todas
  as empresas da página, os demais só a carteira (`company_users`). O universo é o de `/companies` —
  `EmpresasVisiveisService`: Performance com contrato ativo, **sem MlbEmpresa** (Polos entrou depois, ver §53). Empresa de Polos ou sem contrato
  ativo continua sem porta pela tela; a rota `companies.portal.abrir` abre qualquer `Company`, só falta onde clicar.
  `AbaPortalEmCompaniesTest::toda_empresa_listada_abre_pela_porta_da_equipe` clica em todas as linhas: se alguém
  alargar a lista sem mexer em `podeEntrar()`, ele quebra (conferido por mutação: sem o filtro de carteira, 2 testes
  caem).
- **"Último login" é da PESSOA, não da empresa.** `portal_usuarios.ultimo_acesso_em` é carimbado no login
  (`PortalLoginService`), e quem responde por um grupo carimba a mesma data em todas as empresas dele. Não existe
  registro de "último acesso à empresa X" para o portal autenticado — `onboarding_links.ultimo_acesso` é do link por
  token, aposentado em 15/09. Não renomeie a coluna para "último acesso" sem criar esse registro.
- **A contagem é uma consulta só** (`AcessosDoPortalService::loginsPorEmpresa`, GROUP BY no pivot): acesso por GRUPO
  vira uma linha por empresa no `portal_usuario_empresa`, então contar o pivot já cobre o grupo. Desativado não conta
  nem empurra a data.
- **Falhas que NÃO são desta mudança** (iguais no `origin/main@7791d7aa` sem ela):
  `Phase18/CompaniesCustIdFilterTest` (2) — as empresas do teste não têm contrato, e a listagem exige contrato
  Performance ativo — e `Phase14MlbControllerFiltroTest` (1), causa não investigada.

## 52. Título de venda separado do nome, montado com os termos da categoria (19/10/2026)

Pedido do usuário: o cliente dá o NOME do produto, escolhe a categoria e monta o TÍTULO clicando nos termos mais
buscados (ou digitando). Trabalho direto, sem GSD, com autorização explícita para a migration (`estrutura_produtos.titulo`,
varchar 255 NULL, aditiva, sem backfill). O que não se deduz do código:

- **"O mesmo registro" não existe.** O usuário pediu "o produto do Portal é o mesmo registro que o Publicador lê, sem
  sincronização". Não é: `estrutura_produtos` × `pub_produtos` + `pub_rascunho_alvos` (um título POR TIPO, Clássico e
  Premium). O que cumpre o pedido é LER ao vivo, como o nome já é lido: `PubProduto::tituloDoCadastro()` (só produto
  agrupado da MESMA empresa) entra em `DadosEfetivosService::comTituloDoCadastro` como o **planejado do Clássico**, só
  quando a aba Anúncios não planejou um. O Premium fica para a IA — o ML recusa dois títulos iguais (V-TIT-04). O que a
  equipe digita no editor vence (efetivo só preenche vazio). `EfetivosEmLote` espelha a regra (teste de paridade).
- **A IA não escreve por cima do título do cliente, nem quando escreveu antes dele.** `PreparoIaDoRascunhoService::livres`
  trata o planejado como preenchido também quando o campo ainda tem o último texto da IA, e `devolvidosAoPortal` APAGA
  esse texto (para o efetivo do cliente aparecer). O hash dos fatos ganha `titulo_do_cliente` **só quando existe** —
  produto sem título mantém o hash de antes e nada é refeito por causa da chave nova.
- **TODA palavra começa com maiúscula ("Puff Pera Com Enchimento") — o usuário corrigiu em 20/10 a primeira versão, que só
  capitalizava a 1ª letra do título: "tem que ser todos", inclusive as de ligação. Só a 1ª letra de cada palavra muda
  ("MDF" fica); palavra que começa por número fica.** A regra vale no servidor, em quatro pontos, porque há caminhos
  que pulam o model: o `Alvo` (snapshot,
  validação e `family_name` do payload), o mutator de `PubRascunhoAlvo` (Eloquent), o `RascunhoRepository::gravarTitulos`
  (update por query NÃO passa pelo mutator) e os títulos da IA DEPOIS do `RegrasDoTitulo::semRepetir` (a troca de ordem
  pode pôr palavra minúscula na frente). A memória da IA compara o último texto dela capitalizado, senão um título
  antigo minúsculo pareceria "editado pela equipe" e a IA nunca mais o refaria. Portal: mutator de `EstruturaProduto` e
  `NormalizadorDeLinha` (ficha e planilha).
- **Limite (`LimiteDoTitulo`):** título de até 60 caracteres passa SEM consultar o schema — é o limite da imensa maioria
  das categorias e nenhuma lida até hoje pede menos; assim Salvar e planilha não esperam o catálogo, e os testes não
  fazem HTTP. Acima de 60 vale o `max_title_length` da categoria. Se aparecer categoria com limite < 60, isso tem de mudar.
- **Pendência `titulo`:** todo produto sem título fica pendente — os que já existiam em produção também, então a contagem
  "com pendência" do Mapeamento sobe de uma vez no deploy. É o comportamento pedido ("importa e fica pendente").
- **Sigilo:** a rota `termos-titulo` repassa só termo/posição/grupo/relacionado/origem e o nome da categoria de cima — o
  `/trends` traz `url` de `lista.mercadolivre.com.br` e o id `MLB…`, que NÃO podem chegar ao cliente. O componente comum
  (`Components/Titulo/`) tem textos neutros; o editor do Publicador passa os dele (que citam o ML) por `textos`.
- **Planilha:** coluna "Título" logo depois de "Nome do produto*" nos dois modelos. A linha de exemplo ganhou título; o
  importador também reconhece o exemplo SEM título (modelo baixado antes), senão "Mesa de exemplo" viraria produto.
- **Componente único:** `lib/tituloDoProduto.js` + `Components/Titulo/{CampoDoTitulo,TermosDaCategoria}.jsx`, usados pela
  ficha (`FichaTitulo.jsx`) e pelo editor (`Mesa/TermosMaisBuscados.jsx` é casca). O chip marcado é DERIVADO do texto
  (`termoNoTitulo`); o bug do produto 67 era o clique chamar só `juntarTermo`, que não faz nada quando o termo já está lá.
- **Tarefa 4 (campo Modelo com ";") não foi feita:** não existe no código campo que exija ";" (o MODEL saiu da ficha do
  Portal em 09/10, e a IA do Publicador junta com vírgula). Esperando o usuário dizer onde vê a regra.
- **Ponteiro "arquivo:linha" em teste do outro dev (20/10/2026).** `FotosDoPainelServiceTest` (Fase 188) confere que a
  linha 131 de `PreparoIaDoRascunhoService.php` (e outras quatro) contém `EditorEmUso::`. O `use TituloDoProduto` que
  este trabalho pôs no topo empurrou a linha para 132 e quebrou esse teste no `main`; a correção foi tirar o `use` e
  escrever `\App\Support\TituloDoProduto::` nos três pontos. Acrescentar linha ACIMA desses pontos nesses cinco
  arquivos quebra o gate dele — ajuste a tabela `$leitores` do teste junto, ou não desloque.

## Mapeamento oculto do Portal por CONFIGURAÇÃO, não por código (20/10/2026)

Pedido do usuário: "oculta a parte 4 do portal. Mapeamento". Em produção,
`configuracoes.portal_estrutura_submodulos_cliente` = `produtos,sugestoes,precificacao` — vale para todas as empresas,
cliente e equipe (`VisibilidadeDoMapeamento`). O `PADRAO_CLIENTE` do código continua com `mapeamento`: quem procurar
"por que o Mapeamento sumiu" no código não acha — está no banco. Esconder não remove: a rota abre por link direto e,
nela, o item aparece no menu marcado `oculto`. Para voltar: apagar a chave (ou incluir `mapeamento` na lista). Uma
empresa com `portal_estrutura_submodulos_empresa_{id}` vence a padrão — em 20/10 nenhuma tinha.

## Planejamento sem abas: sugestões em cima, criados embaixo (21/10/2026)

Pedido do usuário: "em Criadas fica muito oculto, então parece que não tem nada"; ele escolheu, entre mockups, a
tela com as duas seções. O que não se deduz de olhar a tela:

- **Duas listas, duas URLs.** As criadas usam `criadas_fase`, `criadas_q` e `criadas_pagina` (mais `situacao`, `envio`,
  `ordem`); as sugestões seguem com `fase`, `q`, `pagina`. Cada visita parcial carrega os parâmetros da outra, senão um
  filtro apaga o outro da URL. `?aba=` de link antigo é ignorado (a `aba` do serviço é sempre `sugestoes`).
- **As duas props são preguiçosas** (closures no `Inertia::render`): o filtro das criadas não regera as sugestões, e
  vice-versa. O aceite recarrega `sugestoes` + `criadas` juntas — é assim que o cartão "desce" para Criados.
- **A prévia** (`sugestoes.previa`, 4 itens, Kits e Combits na frente) é do conjunto inteiro, sem filtro e sem Combos
  recolhidos: com os Combos recolhidos por família, a página 1 de quem só tem Combos não traz item nenhum.
- **Frete estimado continua UMA chamada por listagem** (`LogisticaDoConjuntoTest`): página e prévia passam juntas pelo
  `enriquecer`. Chamar duas vezes reprova o teste.
- **Produto sem tipo** não tem mais aba: o cartão mostra "Sem tipo" (âmbar) e abre a `JanelaTipo`. Descartada só volta
  pelo "Desfazer" do aviso; a rota `restaurar` continua existindo.

## Termos mais buscados na célula do Título da planilha (21/10/2026)

Pedido do usuário: levar os termos da categoria para a planilha, "apenas na célula da coluna do Título" — ele recusou
colunas de termo separadas. Código: `TermosDoTituloNaPlanilha` + `ModeloCompleto::setaDoTitulo()`. O que não se deduz:

- **Lista que só sugere, e não soma.** A validação "Lista" do Excel escolhe UM valor e troca o que estava na célula.
  Somar termos como os chips da ficha só com macro — que o Excel bloqueia em arquivo baixado e o Google Planilhas não
  roda. Por isso: no modelo vazio a seta traz os termos (escolhe um e completa com F2); no "meus produtos", onde o
  nome já existe, traz TÍTULOS PRONTOS (nome + palavras novas de cada termo, `TituloDoProduto::juntarTermo`, a mesma
  regra do clique na tela), com o título de hoje em primeiro. `showErrorMessage=false`: o título digitado vale.
- **Google Planilhas** pode marcar com aviso o título digitado que não está na lista. Não bloqueia; já acontecia nos
  campos da ficha que aceitam "Outro".
- **Prazo de 20 s por arquivo** para ler os termos, e para na 1ª falha: o "meus produtos" pode ter até 60 categorias,
  e cada leitura tem timeout de 15 s. Categoria que fica sem termos sai sem a seta, igual a antes.
- **Teste:** os termos leem o schema INTEIRO da categoria (`CategorySchemaRepository::obter`), que pede também
  `technical_specs/input` e `sale_terms`. Com o falso respondendo 404 nesses dois, a leitura falha calada e a planilha
  sai sem seta — o teste parece de código quebrado. O `ModeloCompletoTest` responde `[]` 200 para os dois.
- **Memória:** a pasta `tests/Feature/PortalCliente/Estrutura/Produtos` inteira num processo passa de 512 MB (só o
  `ModeloCompletoTest` usa ~470 MB). Rodar com `php -d memory_limit=3G`.

## A ficha do produto precifica ANTES de salvar — custo e caixa digitados (21/10/2026)

O usuário: "só atualiza o frete quando salva o produto" e, no produto novo ("Salve o produto para ver o preço"), "já
deveria aparecer aqui e não salvar para depois voltar; a usabilidade fica horrível".

- **Produto salvo:** a prévia da Precificação (`GET produtos/{id}/precificacao`) já levava `custos[variacao_id]`; agora
  leva também `caixas[variacao_id] = "CxLxAxKG|…"` (ponto decimal). `doProduto(..., $caixasDeVariacao)` troca as
  caixas da variação dentro de `logisticas()` — nada é gravado. Caixa com medida em branco ou ≤ 0 não vai (vale a salva).
- **Produto novo:** `GET produtos/precificacao-previa` → `previaSemProduto()`, por chave da variação na TELA (`_k`), com
  os percentuais da EMPRESA e o frete pela caixa (mesmo `calcular()`/`envio()`/`FreteMe2Service`, sem chamar o ML). Na
  tela, frete e percentuais próprios ficam travados (`bloqueado`) até salvar: eles gravam na oferta, que só nasce ao
  salvar. O custo é o campo da variação, como sempre.
- **Rota nova no domínio do cliente** = linha na allowlist (`RestringeDominioDoPortal::PERMITIDO`) e as contagens do
  `AcessoAosProdutosTest` (rotas com throttle `estrutura.produtos` e linhas da allowlist). Esquecer a allowlist dá 404 só
  em `cliente.`, e os testes do Admin passam.
- A faixa de calculados da variação (peso cubado, "Frete ME2") continua vindo do servidor ao salvar (D-28: a conta não
  vai para o JS); com a caixa mexida ela fica apagada e diz que a Precificação já usa as medidas digitadas.
- Conferido no servidor local de demonstração (Chrome sem janela): produto novo, custo 270 e caixa 120 × 50 × 91 cm com
  10 kg → frete R$ 168,85 ("referência do ML para a caixa") e preço na hora, sem salvar.

## Poltrona sem Combo nem Kit no Planejamento é DADO, não código (22/10/2026)

Teste da empresa #459: duas poltronas completas e publicadas (mesma família, ambiente Sala, tipo Poltrona) sem
nenhuma sugestão, e uma mesa de cabeceira incompleta com Combo. O gerador está certo:

- A semente (`config/estrutura_geracao.php`) dá ao tipo **Poltrona** `qtd_combo = null` e nenhum par de tipos tem
  Poltrona — nem **Poltrona + Poltrona** (como o Cama + Cama). Kit entre dois produtos do MESMO tipo só sai com o par
  do tipo com ele mesmo. O criado-mudo ("mesa de cabeceira") nasce com Combo de 2.
- Completude e "já publicado" não entram na regra: basta a variação ter oferta simples ligada (D-09).
- Kit de duas cores do MESMO produto nunca é sugerido (o par é entre produtos); sai pelo "Montar kit".
- O conserto é na tela admin `/dev/estrutura-geracao`: Combo "2" no tipo Poltrona e o par Poltrona + Poltrona
  ("Combit: não"). `PoltronasNaSementeTest` prova os dois lados.

## 53. Empresas dos Polos na aba Portal do cliente: o cadastro nasce no "Liberar portal" (26/10/2026)

Pedido do usuário: dar acesso ao portal para as empresas dos Polos a partir da aba **Portal do cliente** de
`/companies` (uma aba "Polos" separada chegou a subir no mesmo dia e foi retirada: não era o pedido). O portal é todo
por `Company`, e só **4 de 464** Polos ativas tinham `mlb_empresas.company_id` em produção. Escolha do usuário entre
três desenhos: criar o cadastro **na hora, só para a empresa liberada** (não para as 460 de uma vez, nem só listar as 4).

- **Onde:** `PolosNoPortalService` monta as linhas (admin only: as escritas do portal são `role:admin`, e Polos não tem
  carteira em `company_users`) e `liberar()` cria a `Company`; rota `portal.polos.liberar`
  (`POST /acessos-portal/polos/{mlbEmpresa}/liberar`). Depois de liberar, a tela vai para "Acessos do portal" com o
  "Dar acesso" aberto na empresa (o `portal_company` da URL + `onLiberada` que troca a sub-aba — a visita do Inertia
  PRESERVA o estado e, sem isso, a tela ficava em "Empresas").
- **Recorte:** `ativas()` + `programa('polos')` **menos a fase `Churn`** (pedido do usuário; "Protocolo Churn",
  "Desistência" e "Encerrado" ficam — em produção eram 111 Encerrado). Fora do recorte a rota dá 404.
- **O cadastro é mínimo de propósito**, e cada ausência protege um módulo:
  sem Cust ID (`AdmanService::syncAll`, `RefreshGrossBillingCacheJob` e `SyncFaturamentoMensal` pegam toda `Company`
  ativa COM cust — com o cust copiado a Adman passaria a sincronizar a conta em dobro, com a key de 10 rpm);
  sem contrato (contrato dispara Onboarding e `EmpresaOperacionalRouter`, que criaria OUTRA ficha de Polos; Fechamento,
  Dashboard e a aba Empresas exigem contrato); sem etapa (a fila do Comercial/Administrativo é por etapa);
  `empresa_nova = false`. O nome de contato vem da ficha (`mlb_implementacoes.nome_contato`), só como sugestão do
  "Dar acesso".
- **Onde ele ainda aparece, e onde não:** a listagem "Cadastro de Empresas" do Comercial mostra toda `Company` ativa e
  o marcaria com a pendência `sem_servico`. Saiu de lá pelo scope `Company::excetoCadastroDoPortal()` — **ligada a uma
  `MlbEmpresa` e sem NENHUM contrato, nem inativo**. É marca estrutural, sem coluna: empresa do Comercial nasce com
  contrato, e a do HubSpot ainda sem contrato não tem ficha de Polos (as duas continuam na fila — há teste). Ele
  APARECE nas listas de seleção de empresa (ex.: o próprio "Dar acesso", PPA) e no contador "sem mapeamento" do NPS
  automático — aceito.
- **Limite conhecido — Mercado Livre no portal:** os recursos do portal que falam com o ML
  (`AnunciosMercadoLivreService`, frete ME2) leem `Company::mlToken()`. A conta ML de uma empresa Polos fica em
  `ml_tokens.mlb_empresa_id` (`MlbEmpresa::mlToken()`), então o cadastro novo nasce "sem conta ML" no portal mesmo
  com a Polos conectada. Se o cliente Polos precisar do "Puxar do ML" no Mapeamento, é aí que se mexe — não copie o
  token para a `Company` sem pensar no Publicador, que distingue `company-N` de `empresa-N` pela âncora.
- **Bônus que vem de graça:** com o `company_id` preenchido, o PPA de Polos daquela empresa passa a aparecer no portal
  (§2), porque `PortalPpaService` chega à Company por `mlb_empresas.company_id`.
- Testes: `tests/Feature/PortalCliente/PolosNoPortalTest.php` (recorte, cadastro mínimo, idempotência, 404, 403,
  Comercial e aba Empresas limpos). Falhas antigas que seguem iguais: as 3 do §51.

### 53.1 O contato do cliente Polos NÃO está nas tabelas dos Polos (medido em produção, 26/10/2026)

Pedido seguinte: o "Dar acesso" vir com e-mail/telefone do cliente. Nas 464 Polos: `mlb_implementacoes.nome_contato`
vazio em TODAS; `mlb_empresas.gmail` preenchido em 331, mas **todos são contas da ECF** (146 `@ecfconsultoria.com.br`,
69 `@polosmoveis.com.br`, o resto gmails com "ecf" no nome; 306 iguais ao `gmail_colaborador`). O portal manda o código
de login para esse e-mail — com um deles o código chega à ECF. A planilha Polos não tem coluna de contato e
`company_grants` não tem linha para nenhum Cust de Polos.

O que existe: **`/users/me` da conta ML conectada** (58 das 464 com token ativo em `ml_tokens.mlb_empresa_id`) e o
**ECF Drive** `/clientes/{cust}` (campos `email`/`telefone`, cobertura não medida — a amostra veio vazia, `enrichedAt`
nulo). `PolosNoPortalService::contatoDoCliente()` usa os dois nessa ordem, cada um só completa o que faltou, descarta
domínio da ECF, o alias `@mail.mercadolivre.com` e os dois e-mails da própria empresa Polos; o aviso verde diz a fonte.
Busca FORA da transação (HTTP não segura a trava da linha). Em cadastro que já existe só completa campo VAZIO, com
`saveQuietly()` — `email_cliente`/`nome_contato` são gatilho do `CompanyGatilhoContratoObserver`. Por isso o "Dar acesso"
de Polos ainda sem login também passa pela rota de liberar.

## 54. Multi-abas: a empresa do portal foi para o ENDEREÇO (26/10/2026)

Relato do usuário: com dois portais abertos (modo equipe), voltar à primeira aba mostrava a última empresa aberta. Era
pior do que a tela: a empresa vinha só de `session('portal_empresa_id')`, uma sessão para todas as abas — o **salvar da
aba antiga gravava na última empresa aberta**. E `PortalEquipeController::abrirSessaoDeEquipe()` fazia `invalidate()` a
cada "Entrar no portal", então nem guardar várias empresas na sessão bastaria.

O desenho (escolha do usuário entre "empresa no endereço" e "só a equipe, por cabeçalho"):

- **Rotas:** o grupo `portal.auth` virou `prefix('portal/{portalEmpresa}')` com `where [0-9]+`; os 99 nomes de rota não
  mudaram. `EnsurePortalAutenticado` confere o número a cada requisição — cliente: `podeVer()`; equipe: a empresa
  precisa estar em `PortalContexto::SESSAO_EMPRESAS_EQUIPE` (`[id => 'passagem'|'link']`, cada uma aberta pela porta da
  equipe, o que mantém a auditoria por empresa) **e** `podeEntrar()`. Depois põe a empresa em
  `request()->attributes` (`PortalContexto::empresa()` lê dali), faz `URL::defaults` com ela e
  **`forgetParameter()`** — os controllers recebem os parâmetros POR POSIÇÃO; sem isso `renomearFamilia(Request, int
  $lista)` receberia o id da empresa.
- **Recusas:** cliente em empresa que não é dele → GET volta ao Início da padrão (`Inertia::location`), escrita 403.
  Equipe em empresa que não abriu → 403 **sem derrubar a sessão** (as outras abas seguem). Perder a carteira ainda
  derruba tudo, como antes.
- **`'0'` = "a empresa da sessão"** — `URL::defaults(['portalEmpresa' => '0'])` no `AppServiceProvider`. É o que sai de
  `route('portal.auth.…')` fora de uma requisição do portal (os 474 `route()` de 69 arquivos de teste, jobs, telas do
  admin). Texto, não número: o Ziggy trata `0` numérico como "sem padrão" e lançaria "parameter is required". Chegando
  por `/portal/0/…`, `Inertia::resolveUrlUsing` troca a URL da página pelo número real (o F5 da aba fica preso nele).
- **Ziggy:** o `@routes` exporta `URL::defaults` — cada aba nasce com a empresa dela como padrão, e todo `route()` do
  navegador sai certo sem mexer em JSX. Por isso a **troca de empresa do cliente** (`portal.auth.empresa`) virou
  `Inertia::location()`: uma visita do Inertia manteria o Ziggy da empresa anterior. Em teste, o `@routes` só imprime
  os padrões na PRIMEIRA página do processo (`BladeRouteGenerator::$generated` estático) — zere antes de conferir.
- **Endereço antigo** (`/portal/inicio`, `/portal/ppa?plano=12`, favoritos e WhatsApp): `PortalEnderecoAntigoController`
  manda GET para o mesmo caminho na empresa da sessão e **não refaz escrita** (iria para a empresa errada — é o defeito
  corrigido). A rota só casa caminho que começa por letra.
- **Domínio do cliente:** a allowlist continua escrita SEM a empresa; `RestringeDominioDoPortal::liberado()` tira o
  número logo depois de `portal/`. `DominioLiberaTodoModuloTest` compara o caminho sem `{portalEmpresa}`.
- **Outros lugares que olhavam o caminho:** a tradução do 413 de upload em `bootstrap/app.php` (regex ganhou
  `([0-9]+/)?`). Links para o cliente gerados fora do portal levam a empresa: compartilhar PPA (`$ppa->company_id`) e
  boas-vindas (`$company->id`).
- **Local engana:** sem `PORTAL_CLIENTE_DOMINIO`, portal e admin dividem o cookie, e a primeira entrada da equipe (que
  invalida a sessão) desloga o admin — já era assim. Para ver duas abas localmente, sirva o portal noutro host. E não
  mexa no `.env` do worktree com testes rodando: o phpunit lê o mesmo `.env`.
- **Em teste o gerador de URL sobrevive entre requisições** (em produção não: cada requisição começa do zero). O
  `URL::defaults` da última requisição do portal fica valendo para o `route()` seguinte do teste — e é por isso que
  `entrarNoPortal()` (`tests/Concerns/EntraNoPortal.php`), o login do cliente e a entrada da equipe também fixam a
  empresa recém-aberta: teste que troca de empresa no meio geraria o link da anterior. Tentei zerar para `'0'` no fim
  do middleware e quebrou o lado oposto (redirect com id real × `route()` com `0`) — não faça.
- Testes: `tests/Feature/PortalCliente/MultiAbasPortalTest.php` (11); 20 arquivos de teste antigos ajustados para o
  endereço novo (`'/portal/…'` escrito à mão virou `'/portal/0/…'`; redirect pós-login compara com o id da empresa).

## 55. "Trazer do Mercado Livre": anúncio ativo vira Produto para a IA jardinar (28/10/2026)

Pedido: cliente que já vende no ML traz os anúncios ativos para Produtos; a IA completa o que falta pela
metodologia; as fotos são as do anúncio original (sem gerar imagem); o Portal mostra o selo "Importado do
Mercado Livre". Decisões do usuário: o anúncio original **continua no ar** (é um clone para melhorar — título e
o resto vão mudar); **qualquer um** (cliente ou equipe) escolhe, por anúncio ou em lote; a escolha é guiada por
diagnóstico (título, Modelo, campos, medidas) **e números** (vendas e visitas de 30 dias, conversão).

**O que não é óbvio no código:**

- **O título de venda fica VAZIO de propósito.** Título no cadastro conta como "planejado" e o preparo da IA no
  Publicador não o reescreve (`PreparoIaDoRascunhoService::livres`). O título do anúncio vira o `nome`.
- **O Modelo do anúncio não vai para a ficha** (saiu dela em 09/10): fica em `estrutura_produto_origens_ml.retrato`
  e entra no prompt da IA como "Modelo informado pelo vendedor".
- **O sigilo da ficha continua** (`FichaTecnicaDaCategoria::TERMOS_PROIBIDOS`). A exceção é só o selo
  (`SeloImportadoDoMl.jsx`, arquivo próprio) e a página `/estrutura/produtos/mercado-livre`. O painel de importação
  diz "Completamos N campos da ficha técnica", nunca "a IA".
- **Ref que já existe NÃO é atualizada.** No modo importação o `gravarLinhas` ATUALIZA variação de mesma Ref e o
  grupo CASA com produto existente — importar por cima apagaria o que o cliente digitou. O importador recusa como
  "já existe" (é o Clássico + Premium do mesmo SKU) e troca o grupo para o MLB se ele bater em produto alheio.
- **A ficha pode não carregar na importação** (catálogo fora): por isso o retrato guarda `atributos_ml` cru e a
  jardinagem REAPLICA o que o anúncio tinha antes de chamar a IA (achado na demo; antes os campos se perdiam).
  O Job da jardinagem tenta 3 vezes nesse caso.
- **O ML guarda UMA caixa** (`SELLER_PACKAGE_*`). Móvel em vários volumes chega com um volume só.
  `shipping.dimensions` ("AxLxC,gramas") é só reserva — nos 20 itens reais da Fase 134 veio `null` em todos.
- **A lista sai do acervo** (`ml_acervo_itens`, sem chamada ao ML); o diagnóstico ao vivo é só da página (30).
  Conta recém-conectada fica com a lista vazia até o `mlb:sync-acervo` do dia seguinte.

**Armadilhas de ambiente achadas no caminho (não são regressão):**

- `PolosController` faz `ini_set('memory_limit', '512M')`: na suíte inteira, um teste dele baixa o limite do
  processo e um teste de planilha depois estoura em `zipstream-php/src/File.php:334` — mesmo com
  `-d memory_limit=3G`. Rode em lotes (por pasta).
- Suíte PHP rodando no MESMO worktree de uma demo apaga as fotos da demo: os testes usam o disco `local` real com
  os mesmos ids (empresa 1, produto 1). Demo e suíte em worktrees separados, ou uma depois da outra.
- No Node 26 os testes JS `publicador-*-187/188` estouram a pilha no próprio hook de `require.resolve` — ambiente,
  não código.
