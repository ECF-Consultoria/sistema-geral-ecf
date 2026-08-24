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
