---
phase: 56
status: aprovado
tested_at: 2026-07-03
tested_by: dev.01
environment: prod
---

## Nota de fechamento

UAT feito diretamente em prod (usuário optou por pular local, autorizou deploy imediato).
Aprovado após 3 hotfixes visuais durante o UAT:

1. **Dados Estratégicos movido pra dentro do grupo Mercado Livre** (commit `31c78ee`)
   Racional: dados vem do ECF Drive, fonte ML-only. Antecipou uma decisão que estava
   marcada como Phase 59 no CONTEXT.md.

2. **Reuniões movido pra top-level acima de "Enviar notificação"** (commit `b24c6e2`)
   Racional: Reuniões é transversal, não específica de ML.

3. **Logos oficiais das marcas em vez de ícones lucide** (commits `bba291a`, `5f914cf`,
   `7013a28`, `e00adaa`)
   Novo campo opcional `iconSrc` em NAV_TREE renderiza SVG quando presente. Aplicado
   em Mercado Livre, Shopee, Amazon. Ícone ML iterado 3x até ficar aprovado.

Além disso, integrado por rebase o commit `cbb9bb7 feat(polos): Painel de Polos unificado`
de outro dev (adicionou item "Painel Polos" ao NAV_TREE grupo ML) sem conflito.

Zero regressão observada em rotas existentes.

# Phase 56 — UAT Checklist

**Milestone:** v13.0 Reorganização Multi-Marketplace
**Requirements cobertos:** NAV-01, NAV-02, NAV-03, NAV-04

**Waves 1 e 2 já shipped:** commits `5859abe` (Wave 1 refactor NAV_TREE) e `78f0d55` (Wave 2 rota + placeholder). Falta só a validação humana antes de fechar Phase 56.

## Preparação

- [x] `git status` limpo (só falta este UAT + PHASE-SUMMARY)
- [x] `npm run build` passou (12.32s Wave 1, 11.50s Wave 2)
- [x] `php artisan route:list --name=em-desenvolvimento` confirma rota registrada com middleware `auth` + `verified`

**Para testar:** iniciar servidor local (XAMPP Apache + MySQL rodando ou `php artisan serve`), login como admin, navegar pela sidebar.

## Checkpoints visuais

### C1 — Grupo Mercado Livre (NAV-01)

- [ ] Sidebar mostra grupo `Mercado Livre` no TOPO (primeiro item)
- [ ] Grupo aparece ABERTO por padrão (chevron para cima; children visíveis) na primeira visita
- [ ] Ordem dos children ANTES do divider: Dashboard, Desempenho, Empresas, Carteira, Reuniões, Sugadores, Metas, PPA
- [ ] Divider mostra label "POLOS" pequeno + hr sutil (label não clicável)
- [ ] Após divider: Onboarding, Empresas Polos, Faturamento Polos
- [ ] Cada item com ícone + Sugadores tem badge vermelho de contador (se houver pendentes)
- [ ] Clicar em Dashboard → carrega `/dashboard` sem erro
- [ ] Clicar em Sugadores → carrega `/sugadores` sem erro
- [ ] Clicar em Onboarding → carrega `/mlb/implementacao` sem erro
- [ ] Fechar grupo ML manualmente → colapsa; abrir de novo → volta aberto
- [ ] Recarregar página (F5) → grupo permanece aberto (sessionStorage funcionou)
- [ ] Fechar grupo ML → F5 → grupo permanece FECHADO (preferência salva vence defaultOpen)

### C2 — Publicação transversal (NAV-02)

- [ ] Grupo `Publicação` (singular, com cedilha e acento) aparece FORA da pasta Mercado Livre
- [ ] Grupo `Publicações` (plural antigo) NÃO aparece em lugar algum
- [ ] Sub-items do grupo Publicação continuam funcionando (Pub · Dashboard, Desempenho, Treinamentos, etc)
- [ ] Grupo `Polos` velho (separado) NÃO aparece — foi absorvido pelo grupo Mercado Livre

### C3 — Stubs Shopee/Amazon com badge (NAV-03)

- [ ] Items `Shopee` e `Amazon` aparecem na sidebar (posição: logo após o grupo Mercado Livre)
- [ ] Cada um tem ícone (ShoppingCart Shopee, Package2 Amazon)
- [ ] Cada um tem badge cinza discreto com texto "Em breve" à direita do nome
- [ ] Badge NÃO é vermelho (não é contador — é label estático)

### C4 — Placeholder /em-desenvolvimento (NAV-04)

- [ ] Clicar em `Shopee` na sidebar → navega para `/em-desenvolvimento?marketplace=shopee`
- [ ] Página renderiza com título "Shopee em desenvolvimento" no card central
- [ ] Ícone de obra (Construction) em círculo amarelo destacado
- [ ] Texto explicativo em pt-BR menciona "Shopee" no corpo
- [ ] Botão amarelo "Voltar ao Dashboard" visível
- [ ] Clicar no botão → navega para `/dashboard`
- [ ] Repetir para `Amazon` — título "Amazon em desenvolvimento"
- [ ] Sidebar permanece visível durante a navegação (AppLayout wraps ✓)

### C5 — Permissões continuam gatando (regressão)

- [ ] Logout + login como usuário NÃO-admin (analista/publicador se disponível)
- [ ] Grupo `Mercado Livre` mostra APENAS os items que o usuário tem permissão
- [ ] Se usuário sem permissão em NENHUM item de ML → grupo inteiro some (filtro do `filteredTree`)
- [ ] Items `Shopee` e `Amazon` VISÍVEIS para todos autenticados (sem permission gating)
- [ ] Grupo `Publicação` continua respeitando permissions dos sub-items `mlb.*` (mesma lógica de antes)

### C6 — Zero regressão em rotas existentes

- [ ] Navegar por 5 rotas aleatórias (ex: Comercial > Empresas, Admin > Fechamento, NPS > Pesquisas, Sugadores drilldown, PPA) — todas carregam sem erro
- [ ] Console do navegador sem warnings novos
- [ ] Testar sidebar colapsada (botão collapse) — grupos e items aparecem em modo icônico sem quebrar

## Fecha

- [ ] Todos os C1-C6 marcados [x]
- [ ] Sem regressões observadas
- [ ] Aprovar → editar frontmatter deste arquivo: `status: aprovado`, `tested_at: 2026-07-03`, `tested_by: dev.01`

Se algum C falhar → `status: gaps_found`, listar os itens abaixo do checklist e escalar pra hotfix (não fecha Phase 56).

## Instruções rápidas para o UAT

1. Confirmar Apache + MySQL do XAMPP rodando
2. Abrir `http://localhost/ecf_admin` (ou URL de dev local) em janela anônima
3. Login como admin
4. Ir batendo os C1 → C6 na ordem
5. Quando terminar, me diga o resultado e eu:
   - Se APROVADO → atualizo frontmatter deste UAT + escrevo PHASE-SUMMARY.md + atualizo ROADMAP + REQUIREMENTS + STATE + commit final + pergunto sobre deploy
   - Se GAPS → me diga quais C falharam, listamos os fixes e rodamos hotfix na mesma wave
