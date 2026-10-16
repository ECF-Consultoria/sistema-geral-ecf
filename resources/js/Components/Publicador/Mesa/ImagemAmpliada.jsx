import { useEffect } from 'react';
import { BotaoAcao } from './botoes';

// ─── A imagem em tela cheia (Quick 261016-mgv) ──────────────────────────────
//
// Pedido do usuário: "poder expandir a imagem gerada com IA para ver mais detalhes". Mesmo
// comportamento do `ImagemAmpliada` interno de AcervoDaConta.jsx (v28.0 Fase 198) — mantido lá sem
// mudança porque a v28.0 está em andamento em outra frente; arquivo próprio para o painel não
// importar o acervo (e o hook dele) só por causa disto. Apresentação pura: fecha no Esc, no clique
// fora ou no botão.

/** O campo pode chegar em formato inesperado — nunca derruba a tela (REND-02). */
const textoSeguro = (v) => (typeof v === 'string' || typeof v === 'number' ? v : null);
const urlSegura = (v) => (typeof v === 'string' && v !== '' ? v : null);

export default function ImagemAmpliada({ url, rotulo = null, largura = null, altura = null, onFechar }) {
    useEffect(() => {
        const aoTeclar = (e) => e.key === 'Escape' && onFechar();
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [onFechar]);

    const src = urlSegura(url);
    const w = Number.isFinite(largura) ? largura : null;
    const h = Number.isFinite(altura) ? altura : null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4" role="dialog" aria-modal="true"
            aria-label={textoSeguro(rotulo) ?? 'Imagem ampliada'} onClick={onFechar} data-imagem-ampliada>
            <div className="max-h-full max-w-4xl" onClick={(e) => e.stopPropagation()}>
                {src && <img src={src} alt={textoSeguro(rotulo) ?? 'Imagem ampliada'} className="max-h-[80vh] w-auto rounded-lg object-contain" />}
                <div className="mt-3 flex items-center justify-between gap-3 text-[12px] font-normal text-white/60">
                    <span>{textoSeguro(rotulo) ?? ''}{w && h ? `${textoSeguro(rotulo) ? ' · ' : ''}${w} × ${h} px` : ''}</span>
                    <div className="flex items-center gap-3">
                        {src && <a href={src} target="_blank" rel="noopener noreferrer" className="text-white/70 underline-offset-2 hover:text-white hover:underline">Abrir em nova aba</a>}
                        <BotaoAcao onClick={onFechar} className="h-8 text-[12px]">Fechar</BotaoAcao>
                    </div>
                </div>
            </div>
        </div>
    );
}
