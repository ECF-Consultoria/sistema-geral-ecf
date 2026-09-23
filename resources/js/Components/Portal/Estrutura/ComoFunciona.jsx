import { Sheet, SheetContent } from '@/Components/ui/sheet';
import { DialogTitle } from '@radix-ui/react-dialog';

// ─── Como funciona — a aula ─────────────────────────────────────────────────
//
// O texto da aba "Como usar" da planilha do Projeto Polos. É a aula que o
// consultor dá; aqui ela fica à mão como consulta, não como tela de grade. As
// 4 fases, os exemplos (cadeira + mesa) e as regras de ouro são literais; o
// passo a passo fala das telas do módulo em vez das abas da planilha.

const FASES = [
    ['Fase 1 · Simples', '1 unidade do produto', '1 Cadeira 01', 'CAD-01'],
    ['Fase 2 · Combo', 'Mesmo produto, mais unidades', 'Combo 2 Cadeiras 01', 'CAD-01-CB2'],
    ['Fase 3 · Kit', 'Produtos diferentes juntos', 'Mesa Marfim + 1 Cadeira 01', 'MSA-MR+CAD-01-KIT'],
    ['Fase 4 · Combit', 'Kit com mais unidades de um item', 'Mesa Marfim + 4 Cadeiras 01', 'MSA-MR+CAD-01-CBT4'],
];

const PASSOS = [
    ['Liste os produtos', 'Liste TODOS os produtos em Fase 1. Depois, para cada um, pergunte: dá combo? em quantas unidades? combina com qual outro produto (kit/combit)? Cada resposta vira uma oferta.'],
    ['Traga o que já está no ar', 'Cole o SKU e o tipo (Clássico/Premium) de cada anúncio que você já tem no Mercado Livre. Quem ainda não vende pula este passo.'],
    ['Veja os buracos', 'Automático: cada oferta mostra se já tem Clássico, Premium e Catálogo, e o que falta publicar.'],
    ['Agende', 'Pegue os buracos e agende: data, oferta, Publicação ou Jardinagem. Ao publicar, informe o código MLB — é isso que marca como feito.'],
];

const REGRAS = [
    'Publique TUDO que você vende — inclusive o que é "entrega a combinar". Implantação = subir tudo; depois otimizar cada oferta.',
    'Toda oferta sai em Clássico (melhor preço à vista) E Premium (melhor preço parcelado). Mesmo SKU, títulos diferentes para explorar mais palavras-chave.',
    'Se existir catálogo do produto, avalie entrar. Catálogo não é só preço: logística e prazo também decidem quem ganha.',
    'Kit que vira multivolume: use kit virtual do ML (várias etiquetas) ou envie por transportadora/ME1.',
    'Ritmo: 1 publicação por dia até zerar a lista. 7 dias depois, agende a Jardinagem (olhar métricas e ajustar o anúncio).',
    'Olhe suas vendas: cliente que compra 2, 4 unidades está pedindo um combo que você ainda não criou.',
];

export default function ComoFunciona({ aberta, onFechar }) {
    return (
        <Sheet open={aberta} onOpenChange={(v) => ! v && onFechar()}>
            <SheetContent className="overflow-y-auto">
                <div className="px-6 py-5 space-y-6 text-white">
                    <div>
                        <DialogTitle className="font-display text-xl font-bold">Como funciona</DialogTitle>
                        <p className="text-white/50 text-[13px] mt-1 leading-relaxed">
                            Tirar o estoque da cabeça e da prateleira e colocar numa lista: todo produto que você tem vira oferta publicada. Produto guardado não vende.
                        </p>
                    </div>

                    <section>
                        <h3 className="text-[12px] uppercase tracking-wide text-white/40 mb-2">As 4 fases da oferta</h3>
                        <ul className="space-y-2">
                            {FASES.map(([fase, oQue, exemplo, sku]) => (
                                <li key={fase} className="rounded-xl border border-white/[0.08] p-3">
                                    <p className="text-[13.5px] font-semibold">{fase} <span className="text-white/45 font-normal">— {oQue}</span></p>
                                    <p className="text-[12.5px] text-white/55 mt-0.5">{exemplo} · <span className="font-mono">{sku}</span></p>
                                </li>
                            ))}
                        </ul>
                        <p className="text-[12px] text-white/45 mt-2 leading-relaxed">
                            Produto satélite = o item que se compõe com o principal (almofada com sofá, cadeira com mesa). O padrão de SKU é livre — só mantenha consistente e confira o limite de caracteres do seu ERP.
                        </p>
                    </section>

                    <section>
                        <h3 className="text-[12px] uppercase tracking-wide text-white/40 mb-2">Passo a passo</h3>
                        <ol className="space-y-2">
                            {PASSOS.map(([t, d], i) => (
                                <li key={t} className="text-[13px] leading-relaxed">
                                    <strong className="text-ecf-yellow">{i + 1}. {t}.</strong> <span className="text-white/65">{d}</span>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section>
                        <h3 className="text-[12px] uppercase tracking-wide text-white/40 mb-2">Regras de ouro</h3>
                        <ul className="space-y-1.5">
                            {REGRAS.map((r) => <li key={r} className="text-[13px] text-white/70 leading-relaxed">• {r}</li>)}
                        </ul>
                    </section>
                </div>
            </SheetContent>
        </Sheet>
    );
}
