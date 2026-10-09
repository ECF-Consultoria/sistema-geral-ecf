import CampoFichaTecnica from '@/Components/Portal/Estrutura/Produtos/CampoFichaTecnica';
import Explicacao from '@/Components/Explicacao';

// ─── Bloco "Medidas do produto (fora da caixa)" (09/10/2026) ────────────────
//
// O produto sozinho, sem caixa: as medidas que a categoria tem (comprimento,
// largura, altura, profundidade, diâmetro, peso…), cada uma com a sua unidade.
// São campos da ficha técnica (mesmo estado, mesmo "Salvar produto"); só moram
// aqui, perto das Variações, porque o volume de cada variação pode usar as
// mesmas medidas (caixa no cartão do volume). Sem categoria, ou se ela não tem
// nenhuma dessas medidas, o bloco não aparece.

export default function MedidasDoProduto({ ficha }) {
    const tecnica = ficha.tecnica;
    const campos = tecnica.medidas ?? [];
    if (! tecnica.temCategoria || campos.length === 0) return null;

    return (
        <section className="mt-2.5 rounded-[14px] border border-white/[0.08] bg-ecf-card p-4 lg:px-5 lg:pb-5 lg:pt-3" data-medidas-do-produto>
            <div className="flex items-center gap-1">
                <h2 className="text-[20px] font-bold text-white">Medidas do produto (fora da caixa)</h2>
                <Explicacao texto={ficha.explicacoes?.medidas_produto} nome="Medidas do produto" />
            </div>
            <p className="mt-1 text-[14px] text-white/70">O produto sozinho, sem caixa nem embalagem. As medidas da caixa ficam no volume de cada variação.</p>

            {/* Durante o "Salvando…" nada se edita (mesma regra dos outros blocos da ficha). */}
            <fieldset disabled={ficha.salvando} className="mt-3 min-w-0 border-0 p-0" data-campos-medidas-do-produto>
                <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                    {campos.map((campo) => (
                        <CampoFichaTecnica key={campo.id} campo={campo} atual={tecnica.valores[campo.id]}
                            erro={tecnica.erros[campo.id]} onMudar={ficha.mudarMedidaDoProduto} explicacao={campo.explicacao} />
                    ))}
                </div>
            </fieldset>
        </section>
    );
}
