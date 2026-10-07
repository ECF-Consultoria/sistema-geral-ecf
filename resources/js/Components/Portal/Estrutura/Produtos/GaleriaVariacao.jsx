import { useRef, useState } from 'react';
import axios from 'axios';
import { ChevronLeft, ChevronRight, ImagePlus, X } from 'lucide-react';
import {
    ACEITA_NO_INPUT, LIMITE_IMAGENS, avisosDoErro, decidirEnvio, imagensDaResposta, montarEnvio,
    moverImagem, mudouAOrdem, ordemDeIds, prepararRemessa, soltarSobre,
} from '@/lib/imagensVariacao';
import { cn } from '@/lib/utils';

// ─── Imagens de uma variação ────────────────────────────────────────────────
//
// As fotos da cor/versão: a 1ª é a capa. Envio, ordem e exclusão são gravados NA
// HORA pelo próprio servidor (não dependem do "Salvar produto"), por isso mexer
// aqui não marca a ficha como alterada: `aoMudar` só troca a lista na tela.
// Variação ainda não gravada (sem id) não envia: aparece a dica para salvar antes.
// As contas (o que cabe, o que o servidor respondeu, a nova ordem) ficam em
// `@/lib/imagensVariacao`, com teste próprio.

const BOTAO_PEQUENO = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-white/15 bg-black/40 text-white/75 hover:bg-white/10 hover:text-white disabled:cursor-not-allowed disabled:opacity-30';

export default function GaleriaVariacao({ variacao, aoMudar }) {
    const imagens = Array.isArray(variacao.imagens) ? variacao.imagens : [];
    const entrada = useRef(null);
    const [enviando, setEnviando] = useState(false);
    const [ocupado, setOcupado] = useState(false);      // ordenando ou excluindo
    const [avisos, setAvisos] = useState([]);
    const [sucesso, setSucesso] = useState(null);
    const [arrastando, setArrastando] = useState(null);  // id da imagem no ar
    const [sobre, setSobre] = useState(null);            // id da imagem sob o arrasto
    const [confirmando, setConfirmando] = useState(null); // id da imagem à espera de "Sim, excluir"

    const envio = decidirEnvio({ variacaoId: variacao.id, total: imagens.length, enviando, ocupado });
    const gravada = !! variacao.id;
    const livre = ! enviando && ! ocupado;

    const limpar = () => { setAvisos([]); setSucesso(null); };

    const aplicar = (resposta, plano) => {
        const lista = imagensDaResposta(resposta);
        aoMudar(lista ?? plano);
    };

    const aoEscolher = async (e) => {
        const escolhidos = Array.from(e.target.files ?? []);
        e.target.value = '';   // permite escolher o mesmo arquivo de novo
        if (! escolhidos.length || ! envio.pode) return;
        limpar();
        const remessa = prepararRemessa(escolhidos, imagens.length);
        if (remessa.arquivos.length === 0) { setAvisos(remessa.avisos); return; }

        setEnviando(true);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.imagens.enviar', variacao.id), montarEnvio(remessa.arquivos), { headers: { Accept: 'application/json' } });
            aplicar(data, imagens);
            setAvisos(remessa.avisos);
            setSucesso(data?.mensagem ?? 'Imagens enviadas.');
        } catch (erro) {
            setAvisos([...new Set([...remessa.avisos, ...avisosDoErro(erro)])]);
        } finally {
            setEnviando(false);
        }
    };

    /** Mostra a nova ordem já e grava; se o servidor recusar, volta a anterior com o motivo. */
    const ordenar = async (nova) => {
        if (! gravada || ocupado || enviando || ! mudouAOrdem(imagens, nova)) return;
        limpar();
        const antes = imagens;
        setOcupado(true);
        aoMudar(nova);
        try {
            const { data } = await axios.put(route('portal.auth.estrutura.produtos.imagens.ordem', variacao.id), { ordem: ordemDeIds(nova) });
            aplicar(data, nova);
        } catch (erro) {
            aoMudar(antes);
            setAvisos(avisosDoErro(erro));
        } finally {
            setOcupado(false);
        }
    };

    const excluir = async (imagem) => {
        if (! gravada || ocupado || enviando) return;
        limpar();
        setConfirmando(null);
        setOcupado(true);
        try {
            const { data } = await axios.delete(route('portal.auth.estrutura.produtos.imagens.excluir', [variacao.id, imagem.id]));
            aplicar(data, imagens.filter((i) => i.id !== imagem.id));
            setSucesso(data?.mensagem ?? 'Imagem excluída.');
        } catch (erro) {
            setAvisos(avisosDoErro(erro));
        } finally {
            setOcupado(false);
        }
    };

    const soltar = (e, alvo) => {
        e.preventDefault();
        const no = arrastando;
        setArrastando(null);
        setSobre(null);
        if (no != null && no !== alvo) ordenar(soltarSobre(imagens, no, alvo));
    };

    return (
        <div className="mt-3 lg:mt-2 lg:grid lg:grid-cols-[84px_minmax(0,1fr)]" data-galeria-variacao>
            <span className="mb-2 block text-[15px] font-semibold text-white lg:mb-0 lg:pt-2.5">Imagens</span>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-3">
                    <input ref={entrada} type="file" multiple accept={ACEITA_NO_INPUT} onChange={aoEscolher} disabled={! envio.pode} className="sr-only" tabIndex={-1} aria-hidden="true" data-entrada-imagens />
                    <button type="button" onClick={() => entrada.current?.click()} disabled={! envio.pode} data-acao="adicionar-imagens"
                        className="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-white/20 bg-white/[0.04] px-3 text-[14px] font-medium text-white hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40 lg:min-h-9">
                        <ImagePlus size={16} /> {enviando ? 'Enviando…' : 'Adicionar imagens'}
                    </button>
                    <span className="text-[13px] tabular-nums text-white/60" data-contador-imagens>{imagens.length}/{LIMITE_IMAGENS}</span>
                    <span className="text-[12px] text-white/40">JPG, PNG ou WebP. A primeira é a capa.</span>
                </div>

                {! gravada && <p className="mt-2 text-[12px] text-white/50" data-dica-salvar>{envio.motivo}</p>}
                {gravada && ! envio.pode && envio.motivo && <p className="mt-2 text-[12px] text-white/50">{envio.motivo}</p>}

                {avisos.length > 0 && (
                    <ul role="alert" className="mt-2 space-y-0.5 text-[12px] text-red-300" data-avisos-imagens>
                        {avisos.map((a) => <li key={a}>{a}</li>)}
                    </ul>
                )}
                {sucesso && avisos.length === 0 && <p role="status" className="mt-2 text-[12px] text-emerald-300/90">{sucesso}</p>}

                {gravada && imagens.length === 0 && ! enviando && <p className="mt-2 text-[12px] text-white/45">Nenhuma imagem ainda.</p>}

                {imagens.length > 0 && (
                    <ul className="mt-3 flex flex-wrap gap-3" data-lista-imagens>
                        {imagens.map((img, i) => (
                            <li key={img.id}
                                draggable={livre && imagens.length > 1}
                                onDragStart={(e) => { setArrastando(img.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(img.id)); }}
                                onDragOver={(e) => { if (arrastando != null) { e.preventDefault(); setSobre(img.id); } }}
                                onDragLeave={() => setSobre((s) => (s === img.id ? null : s))}
                                onDrop={(e) => soltar(e, img.id)}
                                onDragEnd={() => { setArrastando(null); setSobre(null); }}
                                className={cn('w-[92px]', arrastando === img.id && 'opacity-40')}
                                data-imagem={img.id}>
                                <div className={cn('relative h-[92px] w-[92px] overflow-hidden rounded-lg border bg-black/40', sobre === img.id && arrastando !== img.id ? 'border-ecf-yellow/70 ring-1 ring-ecf-yellow/50' : 'border-white/15')}>
                                    <img src={img.url} alt={`Imagem ${i + 1} da variação ${variacao.codigo || 'nova'}`} loading="lazy" draggable={false} className="h-full w-full object-cover" />
                                    {i === 0 && <span className="absolute left-1 top-1 rounded bg-ecf-yellow px-1.5 py-0.5 text-[10px] font-semibold uppercase leading-none text-black" data-selo-capa>Capa</span>}
                                    <button type="button" onClick={() => setConfirmando(img.id)} disabled={! livre} data-acao="excluir-imagem"
                                        aria-label={`Excluir a imagem ${i + 1}`}
                                        className="absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-black/70 text-white hover:bg-red-600 disabled:opacity-40">
                                        <X size={14} />
                                    </button>
                                    {confirmando === img.id && (
                                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-black/85 p-1" data-confirmar-exclusao>
                                            <span className="text-[12px] font-medium text-white">Excluir?</span>
                                            <div className="flex gap-1.5">
                                                <button type="button" onClick={() => excluir(img)} disabled={! livre} data-acao="confirmar-excluir-imagem" className="rounded-md bg-red-600 px-2 py-1 text-[12px] font-medium text-white hover:bg-red-500 disabled:opacity-50">Sim</button>
                                                <button type="button" onClick={() => setConfirmando(null)} className="rounded-md border border-white/25 px-2 py-1 text-[12px] text-white hover:bg-white/10">Não</button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-1 flex justify-between">
                                    <button type="button" onClick={() => ordenar(moverImagem(imagens, img.id, i - 1))} disabled={! livre || i === 0} data-acao="mover-para-frente"
                                        aria-label={i === 1 ? `Tornar a imagem ${i + 1} a capa` : `Mover a imagem ${i + 1} para a frente`} className={BOTAO_PEQUENO}>
                                        <ChevronLeft size={16} />
                                    </button>
                                    <button type="button" onClick={() => ordenar(moverImagem(imagens, img.id, i + 1))} disabled={! livre || i === imagens.length - 1} data-acao="mover-para-tras"
                                        aria-label={`Mover a imagem ${i + 1} para trás`} className={BOTAO_PEQUENO}>
                                        <ChevronRight size={16} />
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {imagens.length > 1 && gravada && <p className="mt-1 text-[11px] text-white/35">Arraste para reordenar ou use as setas.</p>}
            </div>
        </div>
    );
}
