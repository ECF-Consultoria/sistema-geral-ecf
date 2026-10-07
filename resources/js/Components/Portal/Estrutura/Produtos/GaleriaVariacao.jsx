import { useRef, useState } from 'react';
import axios from 'axios';
import { ImagePlus, X } from 'lucide-react';
import {
    ACEITA_NO_INPUT, LIMITE_IMAGENS, avisosDoErro, decidirEnvio, imagensDaResposta, montarEnvio, prepararRemessa,
} from '@/lib/imagensVariacao';
import { cn } from '@/lib/utils';

// ─── Imagens de uma variação ────────────────────────────────────────────────
//
// As fotos da cor/versão. Envio e exclusão são gravados NA HORA pelo próprio
// servidor (não dependem do "Salvar produto"), por isso mexer aqui não marca a
// ficha como alterada: `aoMudar` só troca a lista na tela. A ORDEM não importa
// nesta tela: nada de capa, setas ou arrastar — o tratamento resolve isso
// depois. Variação ainda não gravada (sem id) não envia: aparece a dica para
// salvar antes. As contas (o que cabe, o que o servidor respondeu) ficam em
// `@/lib/imagensVariacao`, com teste próprio.

export default function GaleriaVariacao({ variacao, aoMudar }) {
    const imagens = Array.isArray(variacao.imagens) ? variacao.imagens : [];
    const entrada = useRef(null);
    const [enviando, setEnviando] = useState(false);
    const [ocupado, setOcupado] = useState(false);       // excluindo
    const [avisos, setAvisos] = useState([]);
    const [sucesso, setSucesso] = useState(null);
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
                    <span className="text-[12px] text-white/40">JPG, PNG ou WebP.</span>
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
                            <li key={img.id} className="w-[92px]" data-imagem={img.id}>
                                <div className="relative h-[92px] w-[92px] overflow-hidden rounded-lg border border-white/15 bg-black/40">
                                    <img src={img.url} alt={`Imagem ${i + 1} da variação ${variacao.codigo || 'nova'}`} loading="lazy" draggable={false} className="h-full w-full object-cover" />
                                    <button type="button" onClick={() => setConfirmando(img.id)} disabled={! livre} data-acao="excluir-imagem"
                                        aria-label={`Excluir a imagem ${i + 1}`}
                                        className={cn('absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-black/70 text-white hover:bg-red-600 disabled:opacity-40')}>
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
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
