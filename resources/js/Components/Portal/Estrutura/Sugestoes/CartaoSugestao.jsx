import { useEffect, useState } from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { avisosDoCartao, foiEditada, podeAceitar, valorDoCampo } from '@/lib/sugestoesSelecao';
import { MSG_SKU_REPETIDO, msgSkuLongo, textoTituloLongo } from '@/lib/sugestoesEstrutura';
import { Vitrine } from './PainelCriadas';
import { CaixaDeSelecao, CampoEmLinha, LogisticaEFrete, TiposDaSugestao } from './PecasDaSugestao';

// ─── Cartão da sugestão (21/10/2026) ─────────────────────────────────────────
//
// Sugestões e criadas na mesma tela: a sugestão ganhou o cartão das Criadas (a vitrine com as fotos
// dos produtos, o selo do tipo, a composição), com a borda TRACEJADA e o selo "Sugestão" para não se
// confundir com o que já existe. As regras são as da linha antiga: marcar, editar o nome e o código
// antes de aceitar (o aviso de título ou SKU longo segura o aceite), aceitar e descartar.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const BOTAO = 'inline-flex h-11 items-center justify-center gap-1.5 rounded-lg text-[13px] transition-colors disabled:pointer-events-none disabled:opacity-40 lg:h-10';

const Aviso = ({ children }) => (
    <p className="mt-1 flex items-start gap-1.5 text-[12px] text-amber-300/90">
        <AlertTriangle size={14} className="mt-px shrink-0" aria-hidden="true" />
        <span>{children}</span>
    </p>
);

/** Os itens da sugestão no formato da vitrine das Criadas: id, nome, código, foto e quantidade. */
export const composicaoDaSugestao = (sugestao) => (sugestao?.itens ?? []).map((i) => ({
    id: i.variacao_id, nome: i.produto_nome, sku: i.sku, foto: i.foto ?? null, quantidade: i.quantidade,
}));

export default function CartaoSugestao({
    sugestao, estado, limites, vocabulario, marcada = false, aceitando = false, bloqueado = false,
    erro = null, freteCotado = null, onMarcar, onEditar, onDesfazer, onAceitar, onDescartar, onTipo, className,
}) {
    const { chave } = sugestao;
    const idRazao = `razao-${chave}`;
    const nome = valorDoCampo(estado, sugestao, 'nome');
    const sku = valorDoCampo(estado, sugestao, 'sku');
    const avisos = avisosDoCartao(sugestao, estado, limites);
    const aceitavel = podeAceitar(sugestao, estado, limites);
    const editada = foiEditada(estado, chave);
    const razao = avisos.skuLongo ? msgSkuLongo(limites.max_sku) : (aceitavel ? undefined : 'Preencha o nome e o código para aceitar.');
    // Demo 23/10 (H7): o Descartar ficava colado ao Aceitar, do mesmo tamanho, e descartava no 1º clique
    // (o Desfazer some em segundos). Agora é discreto e pede o 2º clique; a confirmação some sozinha.
    const [confirmarDescarte, setConfirmarDescarte] = useState(false);
    useEffect(() => {
        if (! confirmarDescarte) return undefined;
        const t = setTimeout(() => setConfirmarDescarte(false), 5000);

        return () => clearTimeout(t);
    }, [confirmarDescarte]);

    return (
        <li aria-labelledby={`nome-${chave}`} data-sugestao data-chave={chave} data-fase={sugestao.fase}
            className={cn('relative flex flex-col overflow-hidden rounded-2xl border border-dashed bg-ecf-card transition-colors',
                marcada ? 'border-ecf-yellow/70' : 'border-white/[0.20] hover:border-white/[0.32]', className)}>
            <Vitrine item={{ fase: sugestao.fase, composicao: composicaoDaSugestao(sugestao) }}
                selo={<span className="inline-flex items-center rounded-full bg-white/[0.12] px-2 py-0.5 text-[11px] font-semibold text-white/85" data-selo-sugestao>Sugestão</span>} />

            <div className="absolute right-2 top-2 grid h-11 w-11 place-items-center lg:h-9 lg:w-9" title={aceitavel ? undefined : razao}>
                <CaixaDeSelecao checked={marcada} disabled={(! aceitavel && ! marcada) || bloqueado} onChange={() => onMarcar(chave)}
                    aria-label={`Marcar sugestão ${nome}`} aria-describedby={aceitavel ? undefined : idRazao} />
            </div>
            {! aceitavel && <span id={idRazao} className="sr-only">{razao}</span>}

            <div className="flex flex-1 flex-col gap-3 px-4 pb-4 pt-3.5">
                <div className="min-w-0 space-y-1.5" data-col="nome">
                    <CampoEmLinha id={`nome-${chave}`} campo="nome" rotulo="Nome sugerido" rotuloAcessivel="nome da oferta" valor={nome}
                        editado={estado.edicoes[chave]?.nome !== undefined} onMudar={(v) => onEditar(chave, 'nome', v, sugestao.nome)}>
                        {avisos.tituloLongo !== null && <Aviso>{textoTituloLongo(avisos.tituloLongo, limites.max_titulo)}</Aviso>}
                    </CampoEmLinha>
                    <CampoEmLinha id={`sku-${chave}`} campo="sku" rotulo="SKU sugerido" rotuloAcessivel="código (SKU)" valor={sku} mono
                        editado={estado.edicoes[chave]?.sku !== undefined} onMudar={(v) => onEditar(chave, 'sku', v, sugestao.sku)}>
                        {avisos.skuLongo && <Aviso>{msgSkuLongo(limites.max_sku)}</Aviso>}
                        {avisos.skuRepetido && <Aviso>{MSG_SKU_REPETIDO}</Aviso>}
                    </CampoEmLinha>
                    {editada && (
                        <button type="button" onClick={() => onDesfazer(chave)} data-acao="desfazer-edicao"
                            className={cn('min-h-[28px] text-[12px] text-white/55 underline-offset-2 hover:text-white hover:underline', FOCO)}>
                            Desfazer edição
                        </button>
                    )}
                    <p className="flex flex-wrap gap-1.5 pt-0.5" data-composicao>
                        {(sugestao.itens ?? []).map((i) => (
                            <span key={i.variacao_id} title={i.sku ?? undefined}
                                className="max-w-full truncate rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[11px] text-white/65">
                                {i.quantidade}× {i.produto_nome}
                            </span>
                        ))}
                    </p>
                    <TiposDaSugestao itens={sugestao.itens} onTipo={onTipo} />
                </div>

                <p data-motivo title={sugestao.porque} className="line-clamp-2 text-[12.5px] leading-snug text-white/60">{sugestao.porque}</p>
                {erro ? (
                    <p role="alert" className="text-[12px] text-red-300">Não aceitamos esta sugestão: {erro}.</p>
                ) : (
                    <LogisticaEFrete sugestao={sugestao} freteCotado={freteCotado} vocabulario={vocabulario} />
                )}

                <div className="mt-auto flex flex-col gap-2 border-t border-white/[0.06] pt-3" data-col="acoes">
                    <button type="button" onClick={() => onAceitar(chave)} disabled={! aceitavel || aceitando || bloqueado} title={aceitavel ? undefined : razao}
                        aria-label={`Aceitar sugestão ${nome}`} data-acao="aceitar"
                        className={cn(BOTAO, 'w-full bg-ecf-yellow font-semibold text-black hover:bg-ecf-yellow/90', FOCO)}>
                        {aceitando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                        {aceitando ? 'Aceitando…' : 'Aceitar'}
                    </button>
                    <button type="button" disabled={aceitando || bloqueado}
                        onClick={() => { if (confirmarDescarte) { setConfirmarDescarte(false); onDescartar(chave); } else { setConfirmarDescarte(true); } }}
                        aria-label={confirmarDescarte ? `Confirmar o descarte da sugestão ${nome}` : `Descartar sugestão ${nome}`} data-acao="descartar"
                        className={cn('self-center rounded px-2 py-1 text-[13px] underline underline-offset-2 disabled:pointer-events-none disabled:opacity-40',
                            confirmarDescarte ? 'font-semibold text-red-300 hover:text-red-200' : 'text-white/55 hover:text-white/80', FOCO)}>
                        {confirmarDescarte ? 'Clique de novo para descartar' : 'Descartar'}
                    </button>
                </div>
            </div>
        </li>
    );
}
