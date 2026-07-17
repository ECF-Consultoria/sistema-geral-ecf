---
quick_id: 260717-fpc
title: Upload de fotos do PC na grade em massa (/mlb/anuncios)
date: 2026-07-17
status: complete
---

# Quick Task 260717-fpc: Upload de fotos do PC na grade "Em massa"

## Objetivo

Além de colar URL, permitir enviar fotos do computador direto na grade "Em massa"
de `/mlb/anuncios` — um botão que abre o seletor de arquivos (vários de uma vez) e
preenche as colunas de Foto na ordem escolhida (1ª = capa).

## Decisões (alinhadas com o usuário)

- **Botão, sem modal** — botão "Enviar fotos (PC)" na toolbar da grade, habilitado
  com **1 linha** selecionada (foto é por produto).
- **Mantém as colunas de URL** — o upload preenche as colunas de Foto vazias
  (`imagemUrl..imagemUrl6`) com a URL pública retornada pelo ML. Colar links segue
  funcionando; a galeria "unifica" URL + PC dentro das mesmas 6 colunas.
- **Limite prático: 6 fotos/linha** (as colunas existentes). Excedente é avisado.

## Tarefas

1. **Backend** — `MlImagemService::enviar()` passa a devolver `['id','url']`
   (`secure_url` da maior `variation` do ML) em vez de só o `picture_id`. O único
   caller, `MlbAnuncioController::uploadImagem`, responde `{picture_id, url}` —
   `picture_id` inalterado (wizard individual intacto), `url` nova para a grade.
   - verify: `php artisan test tests/Feature/Phase77/UploadImagemTest.php` verde.
2. **Frontend** — `GradeAnuncioGlide.jsx`: input `file` escondido + botão na
   toolbar. Handler sobe cada arquivo via `mlb.anuncios.rascunho.imagem` e escreve
   a URL na próxima coluna de Foto vazia (`onEditarCelula`, mesmo caminho da
   digitação/paste). Exige rascunho salvo (`linha.id`); avisa se não houver.
   - verify: `npm run build` verde.

## Fora de escopo

- Publicação e round-trip (salvar/reabrir) — já tratam `pictures: [{ source }]`,
  não mudam.
- Ampliar além de 6 fotos na massa (follow-up simples: estender `CAMPOS_FOTO` +
  `COLS_BASE`).
