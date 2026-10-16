---
quick_id: 261016-mwe
status: complete
data: 2026-10-16
commit: 52ac8ae6
---

# Resumo

- **Medido em produção (kit 17 / slot 2):** prompt com a CENA do planejamento das 15:19 (com "Miniatura… canto superior"), identidade pedindo fonte serif contra layout sans-serif, CENA pedindo cabeçalho + lista + rótulos nas cotas e TEXTO dizendo "só estes 3 textos".
- **Testes:** Unit `Quick261016Mwe/PromptDeTextoELayoutAtualTest` (6) + Feature `Quick261016Mwe/ImagemRegeneradaTrocaNaTelaTest` (2). Prova inversa: 5 das mudanças reprovam com o HEAD; 3 guardas passam nos dois.
- **Regressão:** criativos 311 + 1 incompleto, outras 128, Unit 197, JS só a #191 pré-existente.
- ⚠️ **Limite honesto:** texto em imagem gerada por IA continua probabilístico — as correções tiram as contradições do prompt, não garantem 100%. Garantia só renderizando o texto por código sobre a imagem (proposta, não feita).
- ⚠️ Mexe em `app/Services/Creative/` (Creative Engine): layout e regras de texto valem também para o assistente antigo.
