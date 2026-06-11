# Phase 32: Customização NPS — Context

**Gathered:** 2026-06-11
**Status:** Ready for execution (lean — sem discuss/research/plan-check)
**Source:** Conversational direto + investigação inline

<domain>
## Phase Boundary

### O que esta fase entrega

A função NPS Mensal Automatizado (Phase 31) ganha **identidade visual ECF** + **personalização total dos textos** + **rastreabilidade dos envios**:

1. **Logo ECF** renderizado tanto no email mensal quanto na página `/nps/{token}` que o cliente acessa para responder (especificado em HTML/CSS pelo usuário — Montserrat bold "ECF" + barra gradiente vertical azul→rosa→laranja→amarelo + "Consultoria & Assessoria" em uppercase).
2. **Página admin `/nps/configuracao`** onde o admin edita 7 textos do fluxo NPS (assunto/corpo do email + 5 perguntas/labels da página de resposta) com **preview live** de como o email vai ficar.
3. **Página admin `/nps/emails-enviados`** que lista todos os envios mensais — destinatário, empresa, data, status, link pro survey gerado.

Mantém TUDO da Phase 31 funcionando — escala 1-5, idempotência, schedule diário 09:00 BRT, NPS manual.

### Estado atual investigado (2026-06-11)

- Phase 31 deployed: comando `nps:disparar-mensal` rodando, `NpsMonthlyMail` + template `emails/nps/mensal.blade.php` ativos, `Nps/Respond.jsx` em escala 1-5.
- Configurações editáveis: já existe `App\Models\Configuracao` com pattern KV (`Configuracao::get('chave')` / `Configuracao::set('chave', $valor)`) e tabela `configuracoes`. Vou usar **1 chave JSON `nps_textos`** (decisão D-03).
- Não há tabela de log de emails enviados — Phase 28 (`EnviarRelatorioMensal`) também não tem. Vai precisar criar.
- 82 empresas com `email_cliente` preenchido pós quick task 260611-eml; primeiro disparo do cron NPS é amanhã 09:00 BRT.

</domain>

<decisions>
## Implementation Decisions

### Logo (D-01)

**D-01 — Logo ECF como Blade partial reaproveitável (LOCKED).**

Criar `resources/views/partials/logo-ecf.blade.php` com HTML/CSS inline (necessário para emails — CSS externo não é suportado pela maioria dos clientes de email). Aceita props `$theme = 'dark'` (default — fundo escuro `#0f1116`) e `$theme = 'light'` opcional para fundo claro.

Spec do logo (HTML/CSS dado pelo usuário, conferido contra print):
- Container flex, gap 10px, Montserrat 700, 42px, letter-spacing -0.02em
- Texto "ECF" em branco
- Barra vertical 4px × 32px, gradiente `linear-gradient(0deg, #1e5ef3 0%, #e84393 40%, #f97316 70%, #facc15 100%)`, sem border-radius
- Subtítulo "Consultoria<br>&amp; Assessoria" — Montserrat 300, 12px, letter-spacing 0.12em, uppercase, branco

Usado em:
- Email `emails/nps/mensal.blade.php` (versão para email — inline styles + tabela como fallback)
- React `Nps/Respond.jsx` (versão React equivalente, sem Blade — componente JSX `LogoEcf`)

### Permissão (D-02)

**D-02 — Páginas admin restritas a `role:admin` (LOCKED).**

Rotas `/nps/configuracao` e `/nps/emails-enviados` protegidas pelo middleware `role:admin` já configurado. Mesma camada da `/admin/*`. Não usar `core.nps` — esse permissionamento granular existe para acesso de leitura ao módulo NPS, mas editar templates é gestão de marca/comunicação, escopo restrito.

### Storage de textos (D-03)

**D-03 — 1 chave JSON `nps_textos` em `configuracoes` (LOCKED).**

`Configuracao::set('nps_textos', json_encode([...]))`. Schema do JSON:

```json
{
  "email_assunto": "Pesquisa mensal de satisfação ECF — {mes_referencia}",
  "email_saudacao": "Olá!",
  "email_corpo": "Esta é a nossa pesquisa mensal de satisfação. Sua resposta nos ajuda a entender o que está funcionando e o que podemos melhorar.\n\nSeu estrategista é **{nome_estrategista}**{bloco_analista}.\n\nLeva menos de 2 minutos.",
  "email_cta": "Responder pesquisa",
  "email_assinatura": "Obrigado,\nEquipe ECF",
  "perg_estrategista": "O atendimento do {nome_estrategista}",
  "perg_analista": "O atendimento do {nome_analista}",
  "perg_empresa": "A ECF está atendendo suas expectativas?",
  "perg_comentario_label": "Comentário (opcional)",
  "perg_comentario_placeholder": "Opiniões, sugestões ou outra coisa que queira compartilhar",
  "perg_nome_label": "Seu nome (opcional)"
}
```

**Placeholders suportados:**
- `{nome_estrategista}` — nome do estrategista da empresa
- `{nome_analista}` — nome do analista (omitir bloco quando mentoria pura)
- `{nome_empresa}` — nome da empresa
- `{mes_referencia}` — mês de referência da survey ("junho/2026")
- `{bloco_analista}` — substituído por ` e o analista é **{nome_analista}**` quando há analista, ou string vazia quando mentoria pura (usado só no `email_corpo`)

Renderização via helper PHP `App\Support\NpsTextRenderer::render($template, array $vars)` — `str_replace` simples, sem Blade-in-Blade. Aplica HTML escape automático nos vars (XSS).

**Defaults:** se a chave `nps_textos` não existe na tabela `configuracoes`, helper retorna os defaults acima. Seed na migration popula a chave com defaults.

### Log de envios (D-04)

**D-04 — Tabela `nps_email_envios` (LOCKED).**

Schema:
- `id` (bigint pk)
- `survey_id` (bigint nullable fk → nps_surveys cascade) — null quando survey foi apagada
- `company_id` (bigint fk → companies) — preservado mesmo se survey vai embora
- `destinatario` (varchar 255) — email_cliente no momento do envio
- `assunto` (varchar 255) — assunto renderizado no momento
- `status` (enum: 'enviado', 'falha') — sem 'pending'; síncrono
- `erro_msg` (text nullable) — quando status=falha
- `created_at` / `updated_at`

Cada execução do `NpsDispararMensal` grava 1 registro por empresa elegível (sucesso OU falha). Idempotência preservada — se o comando rodar 2x no mesmo dia, o `whereDate('month_reference', ...)` já evita duplicar o survey; logo, não há duplicação de log.

### Preview do email (D-05)

**D-05 — Preview server-rendered (LOCKED).**

A página `/nps/configuracao` posta em `nps.configuracao.preview` que renderiza o template Blade com vars de exemplo fixas (`nome_estrategista = "Nathália"`, `nome_analista = "Igor"`, `nome_empresa = "Empresa Exemplo"`, `mes_referencia = "junho/2026"`) e retorna o HTML completo via JSON. Frontend renderiza o HTML num `<iframe srcdoc>` para isolar estilos do email do resto do app.

Botão "Atualizar preview" no admin recalcula via Ajax sem reload da página.

### Página de envios (D-06)

**D-06 — Filtros mínimos: mês + busca (LOCKED).**

Page `/nps/emails-enviados`:
- Filtro mês (default = mês corrente, via `?mes=YYYY-MM`)
- Busca por nome empresa ou email destinatário (`?q=`)
- Paginação 25/pg
- Colunas: data, empresa, destinatário, assunto, status (badge verde/vermelho), link pro survey (abre `/nps/{token}` em nova aba se status=enviado e survey existe)

Sem CSV export nesta fase — defer para futuro.

### Claude's Discretion

- Estética interna de `/nps/configuracao` — pode usar Tabs (Email / Perguntas) ou um único form com seções
- Layout do logo no Respond.jsx — centralizado no topo do header, padding generoso
- Cor de fundo do logo no email — `#0f1116` (`ecf-bg`) para combinar com header dark do site
- Componentes shadcn usados — Textarea/Input/Button já presentes
- Botão "Restaurar padrão" na página de configuração — opcional mas recomendado (reset para defaults)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these antes de planejar ou implementar.**

### Phase 31 (estado atual)
- `app/Mail/NpsMonthlyMail.php` — Mailable a estender
- `resources/views/emails/nps/mensal.blade.php` — template a estender
- `app/Console/Commands/NpsDispararMensal.php` — comando que vai gravar log + ler textos
- `app/Http/Controllers/NpsController.php` — adicionar métodos configuracao/preview/emailsEnviados + adaptar respond() para enviar textos+logo no payload
- `resources/js/Pages/Nps/Respond.jsx` — adicionar logo + ler textos via prop

### Configuração / KV existente
- `app/Models/Configuracao.php` — KV usar `Configuracao::get('nps_textos')` / `set`

### Padrão de Mailable + SMTP (Phase 28)
- `app/Mail/RelatorioMensalMail.php` — referência inline styles para email
- `app/Console/Commands/EnviarRelatorioMensal.php` — referência tratamento Mail::to + try/catch

### Permissão / rotas / sidebar
- `app/Http/Middleware/EnsureUserHasRole.php` — `role:admin` middleware alias
- `routes/web.php` — onde adicionar as 3 novas rotas (`nps.configuracao.index`, `nps.configuracao.update`, `nps.configuracao.preview`, `nps.emails-enviados.index`)
- `resources/js/Layouts/AppLayout.jsx` — opcional adicionar item "NPS Config" no sidebar (admin only); se preferir, deixar acessível só via link no topo da `/nps`

</canonical_refs>

<specifics>
## Specific Ideas

- **Helper de render** — `App\Support\NpsTextRenderer` em `app/Support/` (cria diretório se não existir). Método `render(string $template, array $vars): string` + `renderHtml(string $template, array $vars): string` (com `e()` para XSS).
- **Componente JSX** — `resources/js/Components/LogoEcf.jsx` reutilizável. Props `theme`, default dark.
- **Fonte Montserrat no email** — emails via SMTP não conseguem importar Google Fonts; usar `font-family: 'Montserrat', 'Helvetica', sans-serif` com fallback. Funciona em clientes que têm Montserrat; cai pra Helvetica em outros (aceitável).
- **Fonte Montserrat no React** — pode adicionar `@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap')` no `resources/css/app.css` se ainda não estiver. Verificar antes.
- **Idempotência do log de envio** — mesmo se rodar 2x, o `whereDate('month_reference', ...)` no comando garante 0 surveys novos no 2º run → 0 logs novos. Não precisa unique constraint.
- **Preview com iframe srcdoc** — isola completamente CSS do email do CSS do app (Tailwind). Sem JS no preview.

</specifics>

<deferred>
## Deferred Ideas

- CSV export da lista de envios (D-06)
- Edição WYSIWYG do email (rich text) — usuario edita Markdown raw
- A/B testing de templates
- Logo dinâmico via upload (logo do parceiro / white-label)
- Versionamento dos textos (histórico de edições)
- Reenvio manual de email a partir da página de envios

</deferred>

---

*Phase: 32-customizacao-nps*
*Context gathered: 2026-06-11*
