---
quick_id: 260727-mx3
slug: cargo-dev-em-usuarios
date: 2026-07-27
status: in-progress
---

# Quick: Cargo Dev sai de /dev/modulos e vira cargo atribuído no usuário

## Objetivo

Tirar a lista de usuários com o botão "Tornar Dev" da tela `/dev/modulos` e passar a
conceder o cargo **Dev** no próprio cadastro do usuário (`/users`), como um cargo real
do sistema de Setores/Cargos. A seção **Visibilidade dos Módulos** de `/dev/modulos`
fica intacta — o usuário validou que aquele jeito de ocultar módulos faz sentido.

## Decisões (validadas com o usuário)

- **DEC-1 — UI:** o Dev é um **toggle "Dev"** logo abaixo do par Usuário/Admin no
  formulário de `/users`, visível para admin e não-admin. Motivo: hoje, ao marcar
  **Admin**, o bloco "Setores e cargos" some da tela e o backend força um vínculo único
  (Administração + cargo Admin), apagando os demais. Os dois devs atuais (`dev.01`,
  `admin@`) são `role=admin` — um cargo escolhido no dropdown não sobreviveria ao salvar.
- **DEC-2 — Mecanismo:** por baixo o toggle grava um **vínculo de cargo real**
  (`user_setores` → setor `desenvolvimento` + cargo `dev`), então o Dev é um cargo de
  verdade e aparece em Administração → Setores.
- **DEC-3 — Fonte de leitura:** `users.is_dev` continua sendo o que `isAdminDev()` lê
  (custo zero no menu, que roda em toda request), agora como **espelho derivado** do
  vínculo — os dois são escritos juntos, na mesma transação, por um único caminho.
- **DEC-4 — Sem duplo caminho:** o setor `desenvolvimento` é **filtrado** do dropdown
  "Setores e cargos". Só o toggle governa esse vínculo, evitando divergência entre o
  vínculo e o espelho.
- **DEC-5 — Anti-lockout:** mantida a trava atual — ninguém remove o próprio cargo Dev
  (front desabilita, backend ignora). Qualquer admin pode reconceder pelo `/users`.

## Tarefas

1. **Migration** — cria setor `desenvolvimento` (Desenvolvimento, `is_system=true`) +
   cargo `dev` (Dev); faz backfill do vínculo para quem já tem `is_dev=1`. `down()`
   desfaz vínculo/cargo/setor.
2. **User model** — constantes dos slugs + docblock de `isAdminDev()` explicando que
   `is_dev` é espelho do cargo.
3. **UserController** — expõe `is_dev` no payload; filtra o setor `desenvolvimento` do
   catálogo de dropdown; valida `is_dev`; aplica o vínculo + espelho no `syncVinculos`
   (depois do override de admin, para sobreviver a ele) com a trava anti-lockout.
4. **Users/Index.jsx** — toggle "Dev" no form, selo "Dev" na listagem, `is_dev` no payload.
5. **DevModulosController + rota + Dev/Modulos.jsx** — remove `updateUsuarioDev`, a rota
   `dev.modulos.usuario-dev`, os props `usuarios`/`meuId` e a seção "Cargo Dev" da tela.
6. **Testes** — atualiza `DevModulosVisibilidadeTest` (some a rota de promoção) e cria
   cobertura do novo caminho (toggle concede/remove cargo + espelho; anti-lockout).
7. **`npm run build`** — convenção do projeto.

## Critérios de aceite

- Marcar "Dev" no meu usuário em `/users` → passo a ver todos os módulos e o item
  "Controle Dev" no menu (mesmo efeito do antigo "Tornar Dev").
- `/dev/modulos` mostra só a Visibilidade dos Módulos; a rota `usuario-dev` não existe mais.
- `user_setores` tem a linha (setor Desenvolvimento + cargo Dev) e `users.is_dev` bate com ela.
- Não dá pra remover o próprio cargo Dev.
- Suíte de testes verde.
