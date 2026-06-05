# Phase 21: Manual do Sistema (artigos explicativos para usuários não-técnicos)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 20 (sequencial; nada técnico)
**Milestone:** v7.0

## Goal

Criar uma nova área **"Manual do Sistema"** no painel do ECF Admin, acessível a TODOS os usuários autenticados, que contém artigos hardcoded em JSX explicando aspectos do sistema em **linguagem simples para usuários sem conhecimento técnico** — sem nomes de comandos artisan, sem termos como "schedule", "cron", "job", "cache", "API", etc.

O **primeiro artigo é "Cronograma de horários"**: uma tabela ordenada que mostra todas as rotinas automáticas do sistema (atualmente dispersas em `routes/console.php`) com horário, nome amigável e descrição em português coloquial. A motivação veio do usuário em 2026-06-05:

> "O sistema está ficando preso a muitos horários, tem o da Adman, agora do ECF Drive, sugadores. Além disso como o sistema já está grande quero criar uma aba manual do sistema."

## Origem da fase

Citação literal do usuário:

> "Cria uma aba manual do sistema, dentro dela terá artigos, o primeiro será cronograma do sistema informando o horário que cada coisa roda e descrição disso, esse cronograma pode ser em formato linha do tempo ou tabela, lembrando que quem vai ler vai ser pessoas que não tem conhecimento com programação então não coloque nomes técnicos, explique de forma simplificada. A aba manual do sistema ficará disponível a todos."

## Decisões já travadas (via AskUserQuestion 2026-06-05)

1. **Arquitetura de artigos:** **hardcode em JSX** — cada artigo é um componente React em `resources/js/Pages/Manual/Artigos/`. Lista do menu de artigos vive em um arquivo JS central. Sem CMS no banco. Pró: simples, versionado em git, sem migration. Contra: cada artigo novo exige push + deploy (aceitável dado a frequência prevista).

2. **Formato do cronograma:** **tabela simples ordenada por horário**. Colunas: `Horário` | `O que acontece` | `Por que importa`. Linhas agrupadas por período do dia (madrugada / manhã / tarde) com cabeçalho visual de seção. Sem linha do tempo visual (rejeitado por consumir muito espaço vertical).

3. **Localização no menu lateral:** **item separado no rodapé da sidebar**, logo acima do botão Sair, com ícone `BookOpen` (ou similar do `lucide-react`). Sem disputar espaço com itens operacionais.

4. **Acesso:** **todos os usuários autenticados**. Sem `permission:*` middleware, sem `EnsureUserHasRole`. Apenas guarda `auth` do Laravel. Inclui admin, consultor, mentor, gestor MLB, publicador, líder, analista. **Não inclui** tokens públicos `/implementacao/*` (que rodam sem login).

## Inventário de rotinas automáticas (fonte: [routes/console.php](routes/console.php))

| Horário (BRT) | Identificador técnico | Nome amigável (artigo) | Descrição simples para o artigo |
|---|---|---|---|
| 03:00 | `grants:sync-ecf` | Atualiza grants do Mercado Livre | Busca a lista atualizada de grants (acessos que clientes deram para o escritório operar a conta deles) pelo sistema integrador ECF Drive. |
| 03:20 | `mlb:sync-vendas-logs-cleanup` | Limpa histórico antigo de sincronizações | Apaga registros de sincronizações de vendas com mais de 30 dias para a lista de logs não ficar imensa. |
| 04:00 | `notifications:cleanup` | Limpa notificações antigas | Remove avisos do sininho (notificações) com mais de 30 dias que já foram lidos. |
| 08:00 | `ml:refresh-tokens` | Renova permissões do Mercado Livre | Verifica permissões de acesso ao Mercado Livre que estão prestes a expirar e renova automaticamente. |
| 11:00 | `adman:sync` | Busca dados da Adman | Traz os números do dia anterior (faturamento, gastos com anúncios, vendas) do nosso integrador Adman. É a base para tudo da Dashboard. |
| 11:05 | `ml:sync` | Busca dados direto do Mercado Livre | Para as contas com permissão direta do ML (sem passar pela Adman), busca os números atualizados. |
| 11:30 | `adman:sync-faturamento` | Atualiza faturamento mensal | Calcula o faturamento bruto consolidado do mês para cada empresa. |
| 11:45 | `goals:calculate` | Recalcula metas individuais | Atualiza o progresso das metas pessoais e das metas de carteira. |
| 11:55 | `CalculateSetorGoalResults` | Recalcula metas de setor | Atualiza o progresso das metas de cada setor (publicações no mês, etc.). |
| 12:00 | `sugadores:analyze` | Detecta sugadores do dia | Procura campanhas e anúncios que estão gastando dinheiro sem gerar vendas correspondentes, para o time investigar. |
| 12:30 | `sugadores:cleanup-quarentena` | Fecha sugadores resolvidos | Marca como resolvidos os sugadores cujas campanhas foram pausadas ou movidas para quarentena pelo analista. |
| 12:45 | `RefreshGrossBillingCacheJob` | Prepara dados da Dashboard | Antecipa os cálculos de faturamento dos últimos 30 dias para a Dashboard carregar instantaneamente quando alguém abre. |
| Diário (manhã) | `prune-pending-nps-surveys` | Limpa pesquisas NPS pendentes | Remove pesquisas de satisfação que ficaram mais de 2 dias sem resposta. |
| Configurável (mensal) | `checa-envio-relatorio-fechamento` | Envia relatório mensal de fechamento | Quando ativado pelo admin em Configurações, envia automaticamente o relatório financeiro mensal por email no dia e hora configurados. |

**14 rotinas** ao todo (12 com horário fixo + 1 diária flexível + 1 mensal configurável).

## Success Criteria

1. **Nova rota `/manual`** retorna `Inertia::render('Manual/Index')` com props `artigos: [{ slug, titulo, descricao, categoria }]` — protegida por middleware `auth` apenas.

2. **Nova rota `/manual/{slug}`** retorna `Inertia::render('Manual/Show', ['slug' => $slug])`; frontend resolve o componente JSX correspondente.

3. **Página `Manual/Index.jsx`** lista artigos em cards organizados por categoria, em pt-BR.

4. **Página `Manual/Show.jsx`** recebe `slug` e renderiza dinamicamente o componente do artigo correspondente (lookup em mapa `slug → Component`).

5. **Artigo `Cronograma.jsx`**: tabela responsiva com 14 linhas (uma por rotina), colunas `Horário | O que acontece | Por que importa`, linhas agrupadas por bloco (Madrugada / Manhã / Início de tarde / Diário flexível / Mensal). Cabeçalho do artigo explica em 2-3 parágrafos por que existem rotinas automáticas (em linguagem simples).

6. **Item no menu lateral**: nova entry "Manual do Sistema" no rodapé da sidebar (acima do botão Sair), com ícone `BookOpen` do `lucide-react`. Visível para todos os usuários autenticados (sem filtro de role/permission).

7. **Linguagem do artigo Cronograma respeita as regras**:
   - **NÃO** usar: "comando", "schedule", "cron", "job", "API", "cache", "endpoint", "fetch", "background", "queue", "worker", "schedule:run", nomes de classes (`RefreshGrossBillingCacheJob`, etc), nomes de Artisan commands (`adman:sync`, `grants:sync-ecf`, etc).
   - **USAR** equivalentes: "rotina automática", "tarefa do sistema", "atualização programada", "preparação dos dados", "integrador".

8. **Arquitetura extensível**: adicionar um segundo artigo no futuro deve exigir apenas (a) criar `Manual/Artigos/NovoArtigo.jsx`; (b) adicionar entry em `Manual/artigos.js` (mapa central). Sem mudanças no controller.

9. **Testes Feature** cobrem: rota `/manual` carrega como auth e nega como guest; `/manual/{slug}` retorna 200 para slugs válidos e 404 para inválidos; lista de artigos exposta nas props contém o artigo "cronograma".

## Mapa de arquivos relevantes

### Backend novos
- `app/Http/Controllers/ManualController.php` (NOVO) — `index()` e `show($slug)`
- `routes/web.php` — adiciona 2 rotas dentro do grupo `auth`, **fora** dos grupos com `permission:*`

### Backend modificados
- Nenhuma migration (decisão de scope — hardcode)
- `routes/web.php` ajuste mínimo

### Frontend novos
- `resources/js/Pages/Manual/Index.jsx` (NOVO) — lista de artigos em cards por categoria
- `resources/js/Pages/Manual/Show.jsx` (NOVO) — wrapper que lê slug e renderiza componente do artigo
- `resources/js/Pages/Manual/Artigos/Cronograma.jsx` (NOVO) — artigo "Cronograma de horários"
- `resources/js/Pages/Manual/artigos.js` (NOVO) — mapa central `{slug: {titulo, categoria, descricao, Component}}`

### Frontend modificados
- `resources/js/Layouts/AppLayout.jsx` — adiciona item "Manual do Sistema" no rodapé da sidebar (no grupo de itens persistentes), com ícone `BookOpen`. Não passar por filtro de permission.

### Testes novos (em `tests/Feature/Phase21/`)
- `ManualControllerTest.php` — 4 testes: index OK auth, index 302 guest, show 200 slug válido (cronograma), show 404 slug inválido

### Não tocar (escopo bloqueado)
- Nenhum modelo de domínio existente
- Nenhuma migration
- Permissões / roles / policies existentes

## Pitfalls antecipados

1. **Linguagem do artigo vazando jargão técnico** — risco principal da fase. Mitigação: revisão final do W4 humano comparando o texto contra a lista de "NÃO usar" do SC-7. O executor deve gerar o texto e o usuário valida.

2. **Cronograma desatualizado quando algum schedule muda no futuro** — texto é hardcoded em JSX. Se algum dia mover `adman:sync` de 11:00 para 10:30, alguém precisa lembrar de atualizar `Cronograma.jsx`. Mitigação documentada no SUMMARY: criar follow-up "auto-extrair horários de routes/console.php" como Phase 22+ se virar manutenção pesada.

3. **Item da sidebar no rodapé pode quebrar layout responsivo em telas pequenas** — `AppLayout.jsx` já tem rodapé com botão Sair; adicionar mais 1 entry pode estourar. Mitigação: testar visual em mobile no W4 + considerar collapse em screens < 768px.

4. **Slug mismatch entre `artigos.js` e Controller** — se o controller exigir slug em allowlist e o JS tiver outro slug, abre dead link. Mitigação: controller delega a validação para o frontend (passa `slug` puro em prop; frontend faz lookup); 404 só quando frontend não encontra. **Decisão**: controller aceita qualquer string, frontend renderiza "Artigo não encontrado" se slug não bate. Mais simples.

5. **Acesso a usuários sem permission alguma** — usuários com `role` mas sem nenhum `permission:*` (raro mas possível) precisam ver `/manual`. Garantir que o grupo de rotas é só `['auth']` e não `['auth', 'verified']` ou outro.

## Não-objetivos (out of scope)

- CMS no banco (`manual_artigos` tabela) — fica para fase futura se manutenção do hardcode virar pesada
- Editor de artigos via UI — fora de escopo
- Auto-extração de horários de `routes/console.php` — fase futura (Pitfall 2)
- Versionamento de artigos / histórico de edições — fica para fase futura
- Sistema de busca dentro da Manual — fora de escopo (com 1 artigo não faz sentido; reavaliar com 5+)
- Marcadores de "lido / não-lido" — fora de escopo
- Comentários / feedback em artigos — fora de escopo
- Tradução em outras línguas — só pt-BR
- Artigos sobre outros temas além do Cronograma (ex: como interpretar a Dashboard, glossário de termos) — fica para fases futuras; arquitetura permite adicionar facilmente

## Cross-cutting constraints

- **pt-BR em tudo** (comentários, mensagens, commits, conteúdo dos artigos)
- **Linguagem simples no conteúdo dos artigos** (SC-7 — guarda explícita)
- **`npm run build` obrigatório após cada JSX**
- snake_case em props do controller; camelCase em JS local
- Sem deploy automático
- Reutilizar componentes shadcn existentes (`card`, `table`, `badge`) — não criar novos
- Activity log via Spatie não se aplica (sem CRUD)
- O artigo Cronograma deve ser **autoexplicativo** — alguém abrindo a página pela primeira vez precisa entender por que aquilo importa, sem clicar em mais nada

## Referências

- [routes/console.php](routes/console.php) — fonte autoritativa dos horários (snapshot no inventário acima)
- Phase 20 — padrão de não-CRUD (substituição pipeline)
- Memory [feedback_project_priorities.md](MEMORY.md) — regras acertividade + praticidade
- Memory [feedback_lean_planning.md](MEMORY.md) — pular discuss/research/plan-check

## Memory persistente relevante

- **Lean planning** — pular discuss/research/plan-check
- **GSD output em pt-BR**
- **Princípio de acertividade** — informação correta vence design bonito; o artigo serve para o operador entender o sistema
