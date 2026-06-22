---
quick_id: 260622-itn
description: "Onboarding — tutorial em texto (passo a passo Adman) no card App ECF da workspace pública"
date: 2026-06-22
status: in-progress
---

# Quick Task 260622-itn — Tutorial em texto (passo a passo Adman) no card App ECF

## Contexto

Na workspace pública do Onboarding (`resources/js/Pages/Mlb/ImplementacaoPublica.jsx`)
o item **App ECF** (`id: app_ecf`, tipo `link_admin`) hoje mostra apenas o botão
"Acessar" (link global da Adman). O sistema de tutorial existente é por **vídeo**
(YouTube → `VideoModal`); o `app_ecf` tem `tem_tutorial => false`, então não exibe
o botão "Tutorial".

O cliente precisa de orientação textual de como criar a conta na Adman e vincular
ao Mercado Livre. O passo a passo é um texto fixo institucional (não varia por
empresa), então fica no front (zero backend).

Mapeamento: "App ECF" = "Adman" = item `app_ecf`. Ver memória do projeto
[[project_mlb_module]] e o quick anterior `260618-jcb` (que removeu o tutorial de
vídeo do App ECF e tornou o link global).

## Decisão de UI (confirmada com o usuário)

- Formato: **MODAL sobreposto** (overlay) acionado por um botão discreto
  **"Passo a passo"** — NÃO accordion inline, NÃO texto solto no fluxo.
- Visual alinhado ao dark theme (`ecf-*`, `cn()`) e ao padrão dos modais já
  existentes no arquivo (espelha `VideoModal`).
- O botão aparece **somente** no card do item `app_ecf`.

## Tarefa única

### Frontend — `resources/js/Pages/Mlb/ImplementacaoPublica.jsx`

1. Adicionar a constante `ADMAN_PASSO_A_PASSO` (título, saudação, 5 passos, caixa
   de atenção) com o conteúdo fornecido pelo usuário (pt-BR).
2. Criar `PassoAPassoModal({ conteudo, onClose })` — overlay com cabeçalho + X,
   saudação, lista numerada de passos e caixa "Atenção" em amber/yellow. Mesmo
   padrão de fechar-no-backdrop e `stopPropagation` do `VideoModal`.
3. Criar `PassoAPassoBtn({ onClick })` — botão discreto (ícone `BookOpen` já
   importado, cor `ecf-yellow` para diferenciar do `TutorialBtn` vermelho de vídeo).
4. No `ChecklistItem`: aceitar `onOpenPassoAPasso` e renderizar `PassoAPassoBtn`
   ao lado do título **apenas quando `item.id === 'app_ecf'`**.
5. No componente `ImplementacaoPublica`: estado `passoAPasso` (conteúdo|null),
   passar `onOpenPassoAPasso={() => setPassoAPasso(ADMAN_PASSO_A_PASSO)}` no map
   dos `ChecklistItem`, e renderizar o modal no top-level (padrão dos demais modais).

**verify:** abrir a workspace pública; no card "App ECF" aparece o botão
"Passo a passo"; clicar abre o modal com os 5 passos + atenção; X e clique no
fundo fecham; nenhum outro item ganha o botão. `npm run build` verde.

## Conteúdo do modal (pt-BR)

- **Título:** Passo a passo para cadastro na Adman
- **Saudação:** Olá! Para realizar seu cadastro na Adman, o processo é bem simples:
- **Passos:**
  1. Acesse o link de criação de conta da Adman.
  2. Clique em "Criar uma conta".
  3. Preencha os dados solicitados no cadastro.
  4. Antes de fazer o vínculo com o Mercado Livre, confirme que você está logado no
     mesmo navegador/Chrome com a conta principal do Mercado Livre que participará
     do projeto.
  5. Faça o vínculo da Adman com essa conta do Mercado Livre.
- **Atenção:** O vínculo precisa ser feito com a conta do Mercado Livre participante
  do projeto, e não com uma conta pessoal ou outra conta que não será utilizada no
  projeto. Por isso, antes de acessar o link da Adman, abra o Mercado Livre no mesmo
  Chrome e confirme se está logado na conta correta.

## Fora de escopo (NÃO mexer)

- Backend (`MlbImplementacao`, controller, configs) — texto é fixo no front.
- Outros itens do checklist e o tutorial de vídeo existente.
- Deploy — sem autorização (apenas working tree + commit local).

## Convenções

- Comentários em pt-BR; tokens `ecf-*`; `cn()` para classes.
- `npm run build` ao final (convenção do projeto). Sem deploy.
