---
quick_id: 261016-mwe
descricao: imagem regenerada que não troca na tela e texto bugado nas imagens de IA
data: 2026-10-16
---

# Imagem regenerada que não troca e texto bugado

1. URL da imagem do slot com `v` (finished_at): arquivo sobrescrito no mesmo caminho + URL igual = <img> não recarrega.
2. `CreativePromptBuilder::paraSlot()` — tipo com layout fixo usa a cena ATUAL do catálogo, não a do `slot_plano` (kit 17 planejado antes do 261016-lu1 seguia pedindo miniatura).
3. Texto: cabeçalho "TAMANHO DO PRODUTO" na lista TEXTO; LAYOUT_MEDIDAS com cada medida uma vez (sem lista duplicada); LAYOUT_TOPICOS sem "linha de apoio"; regras de escrita depois da lista. Frases fixadas por teste ("escreva EXATAMENTE…", "- Badge: …") mantidas.
