---
quick_id: 261016-lu1
status: complete
data: 2026-10-16
commit: 64f51c5a
---

# Resumo — sem teto no Publicador e medidas sem miniatura

- **Testes novos:** `tests/Feature/Quick261016Lu1/SemTetoNoPublicadorTest.php` (6) e
  `tests/js/quick-261016-lu1-sem-teto-publicador.test.js` (4). Prova inversa (model + catálogo do HEAD):
  6/6 falham — ressalva: os 2 de GUARDA (assistente antigo mantém teto; juiz regenera 1 vez sem teto)
  falham no HEAD só por chamarem `semTeto()`; eles guardam comportamento, não provam a mudança. JS com o
  painel do HEAD: o caso `null` falha, os de contador 3/0 passam (guardas).
- **Juiz sem teto:** reprovada duas vezes → 1 regeneração, 1 job — a trava anti-laço é própria.
- **Testes que mudaram de propósito (decisão do usuário):** `Phase165/RegenerarEAprovarKitTest`
  (`test_teto_de_regeneracoes…422` → `test_sem_teto_no_publicador…`) e `Phase165/KitIdempotenciaTest`
  (`test_gerar_com_teto_atingido_devolve_422` → `…nao_e_barrado…`), com docblock.
- **Regressão:** Feature de criativos 274 + 1 incompleto (268 + 6), 0 falhas; outras suítes de kit 150;
  Unit do Creative 173 (= linha de base); JS 5289/5294, única falha a #191 pré-existente. Build ok.
- ⚠️ Mexe em `app/Services/Creative/CreativeSlotCatalog.php` (Creative Engine): o layout de medidas vale
  também para o assistente antigo — avisar o dev do Creative Engine.
