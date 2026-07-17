---
quick_id: 260717-fpc
title: Upload de fotos do PC na grade em massa (/mlb/anuncios)
date: 2026-07-17
status: complete
commits:
  - 59b9990f  # backend: enviar() -> ['id','url']
  - 23cc9bc2  # frontend: botão Enviar fotos (PC)
---

# Summary — 260717-fpc

## O que mudou

**Backend** ([MlImagemService.php](../../../app/Services/Mlb/Publicacao/MlImagemService.php),
[MlbAnuncioController.php](../../../app/Http/Controllers/MlbAnuncioController.php)):
`enviar()` agora retorna `array{id, url}` (URL = `secure_url` da 1ª/maior `variation`
do ML, com fallbacks). `uploadImagem` responde `{ok, picture_id, url}`. `picture_id`
mantido → wizard individual não muda.

**Frontend** ([GradeAnuncioGlide.jsx](../../../resources/js/Pages/Mlb/GradeAnuncioGlide.jsx)):
botão **"Enviar fotos (PC)"** na toolbar (habilitado com 1 linha marcada + fora de
upload). Abre `<input type=file multiple accept=image/*>` escondido; envio sequencial
preenche as colunas de Foto vazias (`CAMPOS_FOTO`) com a URL retornada, na ordem de
seleção. Progresso ("Enviando N/M…") + avisos: linha sem rascunho salvo, colunas
cheias, excedente > 6.

## Verificação

- `php artisan test tests/Feature/Phase77/UploadImagemTest.php` → **4 passed** (backend intacto).
- `npm run build` → **verde** (GradeAnuncioGlide-*.js).
- Ícones `ImagePlus`/`Loader2` confirmados no lucide-react instalado.

## Não verificado (bloqueio conhecido)

Checkpoint visual/funcional ponta-a-ponta **pendente**: precisa de empresa com
`ml_token` (OAuth ML) — o banco local não tem (mesma limitação de outras quick tasks
do módulo). Validar em prod após deploy autorizado: selecionar 1 linha salva →
"Enviar fotos (PC)" → escolher arquivos → colunas de Foto preenchidas → publicar.

## Follow-ups

- Ampliar além de 6 fotos na massa (estender `CAMPOS_FOTO` + `COLS_BASE`).
- Sem deploy sem autorização (feedback do usuário).
