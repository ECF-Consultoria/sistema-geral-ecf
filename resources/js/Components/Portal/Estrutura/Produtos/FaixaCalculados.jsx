import { Plus } from 'lucide-react';
import { PilulaLogistica } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { fmtKg, renderFrete, renderPesoCubado } from '@/lib/produtosEstrutura';
import { cn } from '@/lib/utils';

// ─── Faixa de calculados (REF-2, 167-19; D-28: leitura, nunca campo) ────────
//
// Nº de volumes, peso total, logística provável, peso cubado e frete ME2 vêm
// do SERVIDOR (PORTAL-02). Aqui não há soma, contagem nem cubagem: variação que
// ainda não foi gravada mostra "—" e a explicação.

const CELULA = 'min-w-0 lg:border-l lg:border-white/[0.06] lg:px-3 lg:text-center';
const ROTULO = 'block text-[12px] text-white/60';
const VALOR = 'mt-0.5 block text-[16px] font-semibold tabular-nums text-white';

export default function FaixaCalculados({ variacao, ficha, vocabulario }) {
    const gravada = !! variacao.id;
    const mexida = gravada && (Array.isArray(variacao.volumes_digitados) || String(variacao.custo) !== String(variacao._base?.custo ?? ''));
    const traco = <span className="text-white/45">—</span>;

    const celulas = [
        ['n_volumes', 'Nº de volumes', gravada ? variacao.n_volumes : null],
        ['peso_total', 'Peso total', gravada && variacao.peso_total != null ? fmtKg(variacao.peso_total) : null],
        ['logistica', 'Logística provável', gravada
            ? <PilulaLogistica chave={variacao.logistica ?? 'pendente'} rotulos={vocabulario?.logisticas} ponto />
            : null],
        ['peso_cubado', 'Peso cubado', gravada ? renderPesoCubado(variacao) : null],
        ['frete', 'Frete ME2', gravada ? renderFrete(variacao, {}, 'pilha') : null],
    ];

    return (
        <div data-faixa-calculados>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-[164px_repeat(5,minmax(0,1fr))] lg:items-center lg:gap-0">
                <button type="button" onClick={() => ficha.adicionarVolume(variacao)} data-acao="adicionar-volume"
                    className="col-span-2 inline-flex h-11 items-center justify-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[14px] text-white hover:bg-white/[0.07] lg:col-span-1 lg:h-9">
                    <Plus size={14} /> Adicionar volume
                </button>
                {celulas.map(([chave, rotulo, conteudo]) => (
                    <div key={chave} className={cn(CELULA)} data-calculado={chave}>
                        <span className={ROTULO}>{rotulo}{chave === 'frete' ? ' (estimativa)' : ''}</span>
                        <span className={VALOR}>{conteudo ?? traco}</span>
                    </div>
                ))}
            </div>
            {! gravada && <p className="mt-2 text-[12px] text-white/45">Os calculados aparecem ao salvar.</p>}
            {mexida && <p className="mt-2 text-[12px] text-white/45">Recalcula ao salvar.</p>}
        </div>
    );
}
