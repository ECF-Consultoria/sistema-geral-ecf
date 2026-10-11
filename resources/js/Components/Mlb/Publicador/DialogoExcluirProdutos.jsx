import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from './BarraDaConta';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';
import { BASE_BOTAO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';

// ─── Excluir produtos do Publicador (10/10/2026) ────────────────────────────
//
// Um produto (menu ⋯ da linha) ou vários (a seleção da lista). Ao abrir, pede ao
// servidor a PRÉVIA: quem pode sair e por que o resto fica. A regra é toda do
// servidor (`ExcluirProdutoService`): só sai o que nunca foi publicado e já está
// solto do Portal do Cliente. A confirmação manda só os ids que a prévia liberou.
//
// ⚠️ Lição da tela preta de 05-07/10/2026 ("Objects are not valid as a React
// child"): todo campo da prévia passa por `textoSeguro()`/`numeroSeguro()` antes
// do JSX. E nada de `window.confirm`: a confirmação é este diálogo.

const rotaDoPublicador = criarRota('mlb.anuncios.publicador', 'conta');

const PERIGO = 'border border-red-500/40 bg-red-500/10 text-red-200 hover:bg-red-500/20';
const BLOCO = 'rounded-lg border border-white/[0.08] bg-white/[0.03] p-3';

/** Quantos nomes a lista mostra antes de resumir em "e mais N". */
export const NOMES_A_MOSTRAR = 6;

const listaSegura = (valor) => (Array.isArray(valor) ? valor.filter((x) => x && typeof x === 'object') : []);
const numeroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) ? valor : 0);
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/**
 * Os recusados agrupados pelo motivo, na ordem em que apareceram: uma frase por motivo, com os
 * produtos dela embaixo.
 *
 * @returns {Array<{ motivo: string, produtos: Array<{ id: number, rotulo: string }> }>}
 */
export function agruparRecusados(recusados) {
    const grupos = new Map();
    listaSegura(recusados).forEach((r) => {
        const motivo = textoSeguro(r.motivo, 'Não pode ser excluído agora.');
        if (! grupos.has(motivo)) grupos.set(motivo, []);
        grupos.get(motivo).push({ id: r.id, rotulo: [textoSeguro(r.sku, ''), textoSeguro(r.nome, '')].filter(Boolean).join(' · ') || 'Produto' });
    });

    return [...grupos.entries()].map(([motivo, produtos]) => ({ motivo, produtos }));
}

/**
 * As frases da prévia.
 *
 * @returns {{ titulo: string, sai: string|null, fica: string|null, botao: string, nomes: string[], resto: number }}
 */
export function frasesDaPrevia(previa) {
    const excluiveis = listaSegura(previa?.excluiveis);
    const n = excluiveis.length;
    const recusados = listaSegura(previa?.recusados).length;
    const fotos = numeroSeguro(previa?.totais?.fotos);
    const nomes = excluiveis.map((p) => [textoSeguro(p.sku, ''), textoSeguro(p.nome, '')].filter(Boolean).join(' · ') || 'Produto');

    return {
        titulo: n === 1 ? 'Excluir este produto?' : n > 1 ? `Excluir ${n} produtos?` : 'Nada para excluir',
        sai: n === 0 ? null
            : `${n === 1 ? 'Sai o rascunho dele' : 'Saem os rascunhos deles'}, com títulos, preços, conferências${fotos > 0 ? ` e ${plural(fotos, 'foto', 'fotos')}` : ''}. Não dá para desfazer.`,
        fica: recusados === 0 ? null : `${plural(recusados, 'produto não pode ser excluído e fica', 'produtos não podem ser excluídos e ficam')} como está:`,
        botao: n === 1 ? 'Excluir produto' : `Excluir ${n} produtos`,
        nomes: nomes.slice(0, NOMES_A_MOSTRAR),
        resto: Math.max(0, nomes.length - NOMES_A_MOSTRAR),
    };
}

/** O miolo do diálogo, desenhado a partir da prévia do servidor. */
export function PreviaDaExclusao({ previa }) {
    const f = frasesDaPrevia(previa);
    const grupos = agruparRecusados(previa?.recusados);
    const sumidos = numeroSeguro(previa?.nao_encontrados);

    return (
        <div className="space-y-3 text-[13px] font-normal text-white/70" data-previa-exclusao>
            {f.sai && (
                <div className={BLOCO} data-excluiveis>
                    <ul className="list-disc space-y-0.5 pl-5 text-white/90">
                        {f.nomes.map((nome, k) => <li key={`${nome}-${k}`}>{nome}</li>)}
                        {f.resto > 0 && <li className="list-none text-white/55">e mais {f.resto}</li>}
                    </ul>
                    <p className="mt-2">{f.sai}</p>
                    <p className="mt-1 text-white/50">O que já virou anúncio no Mercado Livre não é afetado.</p>
                </div>
            )}
            {f.fica && (
                <div data-recusados>
                    <p>{f.fica}</p>
                    <ul className="mt-1.5 max-h-48 space-y-2 overflow-y-auto">
                        {grupos.map((g) => (
                            <li key={g.motivo} className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-amber-200">
                                <p>{g.motivo}</p>
                                <ul className="mt-1 list-disc pl-5 text-amber-100/80">
                                    {g.produtos.map((p) => <li key={p.id}>{p.rotulo}</li>)}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {sumidos > 0 && <p className="text-white/50" data-nao-encontrados>{plural(sumidos, 'produto marcado não existe mais', 'produtos marcados não existem mais')}.</p>}
        </div>
    );
}

/**
 * @param conta        chave da conta (`company-459`)
 * @param ids          ids dos produtos a excluir
 * @param onConcluido  recebe `{ texto, excluidos }` (a mensagem do servidor e os ids que saíram)
 */
export default function DialogoExcluirProdutos({ aberto, onFechar, conta, ids = [], onConcluido }) {
    const [previa, setPrevia] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);
    const pedido = useRef(0);
    const contaSegura = textoSeguro(conta, '');
    const chave = ids.join(',');

    const carregar = async () => {
        const id = ++pedido.current;
        setCarregando(true);
        setErro(null);
        try {
            const { data } = await axios.post(rotaDoPublicador('produtos.exclusao.previa', contaSegura), { produtos: ids });
            if (id === pedido.current) setPrevia(data && typeof data === 'object' ? data : null);
        } catch (e) {
            if (id === pedido.current) setErro(mensagemDe(e));
        } finally {
            if (id === pedido.current) setCarregando(false);
        }
    };

    useEffect(() => {
        if (! aberto || ids.length === 0) return;
        setPrevia(null); setEnviando(false);
        carregar();
    }, [aberto, chave]); // eslint-disable-line react-hooks/exhaustive-deps

    // Escape fecha sem excluir (molde do DialogoVincularKit).
    useEffect(() => {
        if (! aberto) return undefined;
        const aoTeclar = (ev) => {
            if (ev.key === 'Escape' && ! enviando) onFechar?.();
        };
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [aberto, enviando, onFechar]);

    if (! aberto || ids.length === 0) return null;

    const fechar = () => {
        if (enviando) return;
        onFechar?.();
    };

    const excluiveis = listaSegura(previa?.excluiveis);
    const frases = frasesDaPrevia(previa);

    const excluir = async () => {
        if (excluiveis.length === 0 || enviando) return;
        setEnviando(true);
        setErro(null);
        try {
            // Só os que a prévia liberou: o servidor confere de novo, um por um.
            const { data } = await axios.post(rotaDoPublicador('produtos.exclusao', contaSegura), { produtos: excluiveis.map((p) => p.id) });
            onConcluido?.({ texto: textoSeguro(data?.mensagem, 'Produtos excluídos.'), excluidos: Array.isArray(data?.excluidos) ? data.excluidos : [] });
        } catch (e) {
            // 422 (nenhum pôde sair): fica aberto, com a mensagem, e mostra a prévia de agora.
            setErro(mensagemDe(e));
            setEnviando(false);
            carregar();
        }
    };

    const titulo = previa ? frases.titulo : 'Excluir produtos';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60" onClick={fechar} aria-hidden="true" />
            <div role="dialog" aria-modal="true" aria-label={titulo} data-dialogo-excluir-produtos
                className="relative z-10 flex max-h-[90vh] w-[520px] max-w-full flex-col gap-4 overflow-y-auto rounded-2xl border border-white/[0.08] bg-ecf-card p-6">
                <div className="flex items-start justify-between gap-3">
                    <h2 className="text-[15px] font-bold text-white">{titulo}</h2>
                    <button type="button" onClick={fechar} disabled={enviando} aria-label="Fechar"
                        className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-white/55 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:opacity-40">
                        <X size={16} aria-hidden="true" />
                    </button>
                </div>

                {carregando && ! previa && (
                    <p className="flex items-center gap-2 text-[13px] font-normal text-white/55" data-carregando-previa>
                        <Loader2 size={15} className="animate-spin" aria-hidden="true" /> Conferindo o que pode ser excluído…
                    </p>
                )}
                {previa && <PreviaDaExclusao previa={previa} />}
                {erro && <p role="alert" className="text-[13px] font-normal text-red-300" data-erro-exclusao>{erro}</p>}

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={fechar} disabled={enviando} className={cn(BASE_BOTAO, SECUNDARIO)} data-acao="manter-produtos">
                        {excluiveis.length > 0 ? 'Manter' : 'Fechar'}
                    </button>
                    {excluiveis.length > 0 && (
                        <button type="button" onClick={excluir} disabled={enviando || carregando} className={cn(BASE_BOTAO, PERIGO)} data-acao="confirmar-exclusao-produtos">
                            {enviando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                            {enviando ? 'Excluindo…' : frases.botao}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
