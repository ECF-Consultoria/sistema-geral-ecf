---
quick_id: 260727-mx3
slug: cargo-dev-em-usuarios
date: 2026-07-27
status: complete
commits: [29a70b8a, ef6d92ca, 3c165863]
---

# Cargo Dev sai de /dev/modulos e vira cargo atribuído no usuário

## O que mudou

O cargo Dev deixou de ser concedido numa lista com botão "Tornar Dev" dentro de
`/dev/modulos` e passou a ser um **cargo de verdade**, concedido no cadastro do
usuário em `/users`. A seção **Visibilidade dos Módulos** ficou intacta.

- **Migration** `2026_07_27_140001_seed_setor_desenvolvimento_cargo_dev` — cria o setor
  `desenvolvimento` (`is_system=true`) + cargo `dev` e faz backfill do vínculo para quem
  já tinha `is_dev=1` (localmente: `dev.01@` e `admin@`).
- **`/users`** — toggle "Dev" logo abaixo do par Usuário/Admin, visível para os dois
  tipos, e selo "Dev" na listagem. `UserController::syncCargoDev()` grava o vínculo
  (`user_setores` → setor `desenvolvimento` + cargo `dev`) e o espelho `users.is_dev`
  na mesma transação.
- **`/dev/modulos`** — some a seção "Cargo Dev", os props `usuarios`/`meuId`, o método
  `updateUsuarioDev` e a rota `dev.modulos.usuario-dev`. Entrou um ponteiro para
  Administração → Usuários.

## Decisões

- **Toggle, não dropdown** (validado com o usuário): marcar **Admin** esconde o bloco
  "Setores e cargos" e o backend força um vínculo único (Administração + Admin),
  descartando os demais. Como os dois devs são `role=admin`, um cargo escolhido no
  dropdown não sobreviveria ao salvar. O toggle contorna isso sem mexer na regra do admin.
- **`users.is_dev` continua sendo o que `isAdminDev()` lê** — agora como espelho
  derivado do vínculo. `isAdminDev()` roda em toda request (menu); uma coluna custa
  zero query. Os dois são escritos juntos, por um único caminho.
- **Setor Desenvolvimento fora do dropdown** de setores: um segundo caminho de escrita
  gravaria o vínculo sem o espelho, e o Dev não veria os módulos ocultos.
- **Anti-lockout mantido**: ninguém remove o próprio cargo Dev (front desabilita,
  backend devolve erro). Qualquer admin reconcede pelo `/users`.

## Armadilhas encontradas

1. **`whereHas('cargos')` testaria o catálogo do setor**, não o `cargo_id` do pivot —
   daria sempre verdadeiro. `User::temCargoDev()` faz join direto em
   `user_setores → cargos`, como `cargoDesempenhoSlug()`.
2. **`syncVinculos()` apagaria o vínculo do Dev a cada salvamento comum**, já que ele
   não trafega no array `vinculos`. O setor Dev foi excluído da etapa de remoção e
   `syncCargoDev()` é idempotente (reaplica o estado desejado mesmo sem mudança).
   Coberto por `test_salvar_o_cadastro_sem_mexer_no_dev_preserva_o_vinculo`.
3. **Setor usa SoftDeletes com slug UNIQUE** — a migration reativa uma linha
   soft-deletada em vez de tentar recriar.

## Verificação

- `CargoDevNoUsuarioTest` (6) + `DevModulosVisibilidadeTest` (6) +
  `ModuleRegistryFoundationTest` (9) — **21/21 verdes**.
- Recorte usuários/setores/permissões: **110 passed, 4 failed** — as 4 (`Phase37ServicoSetorTest`,
  `PortfolioMultiFonteE2ETest`, `PortfolioSourceEnrichmentTest`, `PublicacaoDesempenhoRouteTest`)
  **já falham no baseline `1fd3d085`**, confirmado com checkout do commit anterior.
- `route:list` confirma `dev.modulos.usuario-dev` inexistente e as 2 de visibilidade vivas.
- `npm run build` verde.

## Pendente

- **NÃO deployado** — aguardando autorização.
- `origin/main` tem 1 commit à frente (outro dev). Fazer fetch/reconciliar antes de qualquer push.
