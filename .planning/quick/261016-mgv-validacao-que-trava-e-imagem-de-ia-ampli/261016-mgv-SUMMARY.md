---
quick_id: 261016-mgv
status: complete
data: 2026-10-16
commit: edf67f8d
---

# Resumo

- **Causa medida em produção:** kit 17 / slot 1 com 4 imagens; o `ValidarCriativoIaJob` da 4ª rodou em 4,9 ms (validação real: 5–7 s) — `julgarEGravar()` bateu no teto de 3 validações por SLOT e devolveu `indisponivel` sem gravar. A mudança 261016-lu1 (sem teto de regeneração) tornava isso frequente.
- **Testes:** `tests/Feature/Quick261016Mgv/ValidacaoNaoTravaTest.php` (3) e `tests/js/quick-261016-mgv-validacao-e-imagem-ampliada.test.js` (5). Prova inversa: PHP 3/3 reprovam com juiz+controller do HEAD; JS: polling e botão de ampliar reprovam com o HEAD (os 2 do `ImagemAmpliada` passam — arquivo novo).
- **Regressão:** criativos 277 + 1 incompleto (274 + 3), outras suítes 150, Unit 173, JS 5298/5303 (só a #191 pré-existente). Build ok.
- ⚠️ `CreativeJuiz` é do Creative Engine: o "grava indisponível no teto" vale também para o assistente antigo (correção de bug, sem mudança de regra lá).
