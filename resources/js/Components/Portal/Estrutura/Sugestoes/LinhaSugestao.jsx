import { AlertTriangle, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { avisosDoCartao, foiEditada, podeAceitar, valorDoCampo } from '@/lib/sugestoesSelecao';
import { MSG_SKU_REPETIDO, msgSkuLongo, textoDoComponente, textoTituloLongo } from '@/lib/sugestoesEstrutura';
import { CaixaDeSelecao, CampoEmLinha, LogisticaEFrete, QuadrosDaSugestao, SeloFase, TiposDaSugestao } from './PecasDaSugestao';

// ─── Linha compacta da sugestão (168-18, D-24..D-31) ────────────────────────
//
// Sete áreas, na ordem da referência: seleção | imagens | tipo | nome/SKU | composição |
// motivo + logística + frete | ações. Abaixo de 1280 px as mesmas áreas empilham.
// Mesmas props e mesmas regras do cartão antigo (removido no 168-19): nada é calculado aqui.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const DIVISORIA = 'xl:border-l xl:border-white/[0.06] xl:pl-4';
const ROTULO = 'text-[11px] leading-[13px] text-white/50 xl:text-[12px] xl:leading-[14px]';
const BOTAO = 'inline-flex h-11 items-center justify-center gap-1.5 rounded-lg text-[13px] transition-colors disabled:pointer-events-none disabled:opacity-40 xl:h-9 xl:w-[96px]';

const Aviso = ({ children }) => (
    <p className="mt-1 flex items-start gap-1.5 text-[12px] text-amber-300/90">
        <AlertTriangle size={14} className="mt-px shrink-0" aria-hidden="true" />
        <span>{children}</span>
    </p>
);

export default function LinhaSugestao({
    sugestao, estado, limites, vocabulario, marcada = false, aceitando = false, bloqueado = false,
    erro = null, freteCotado = null, onMarcar, onEditar, onDesfazer, onAceitar, onDescartar, onTipo,
}) {
    const { chave } = sugestao;
    const idRazao = `razao-${chave}`;
    const nome = valorDoCampo(estado, sugestao, 'nome');
    const sku = valorDoCampo(estado, sugestao, 'sku');
    const avisos = avisosDoCartao(sugestao, estado, limites);
    const aceitavel = podeAceitar(sugestao, estado, limites);
    const editada = foiEditada(estado, chave);
    const editadoNome = estado.edicoes[chave]?.nome !== undefined;
    const editadoSku = estado.edicoes[chave]?.sku !== undefined;
    const razao = avisos.skuLongo ? msgSkuLongo(limites.max_sku) : (aceitavel ? undefined : 'Preencha o nome e o código para aceitar.');

    return (
        <article aria-labelledby={`nome-${chave}`} data-sugestao data-chave={chave}
            className={cn('min-w-0 rounded-[10px] border bg-ecf-card p-3.5',
                'flex flex-col gap-3 xl:grid xl:min-h-[84px] xl:grid-cols-[24px_172px_80px_minmax(0,1.05fr)_minmax(0,1fr)_minmax(0,1.3fr)_208px] xl:items-center xl:gap-x-4 xl:gap-y-0 xl:px-3.5 xl:py-2',
                marcada ? 'border-ecf-yellow/60' : 'border-white/[0.07]')}>
            <div className="flex items-center gap-3 xl:contents">
                <div data-col="selecao" className="grid h-11 w-11 shrink-0 place-items-center xl:h-6 xl:w-6" title={aceitavel ? undefined : razao}>
                    <CaixaDeSelecao checked={marcada} disabled={(! aceitavel && ! marcada) || bloqueado} onChange={() => onMarcar(chave)}
                        aria-label={`Marcar sugestão ${nome}`} aria-describedby={aceitavel ? undefined : idRazao} />
                </div>
                {! aceitavel && <span id={idRazao} className="sr-only">{razao}</span>}

                <div data-col="imagens" className="min-w-0">
                    <QuadrosDaSugestao nome={nome} itens={sugestao.itens} />
                </div>

                <div data-col="tipo" className="flex flex-col items-start gap-0.5">
                    <SeloFase fase={sugestao.fase} />
                    <TiposDaSugestao itens={sugestao.itens} onTipo={onTipo} />
                </div>
            </div>

            <div data-col="nome" className={cn('min-w-0 space-y-0.5', DIVISORIA)}>
                <CampoEmLinha id={`nome-${chave}`} campo="nome" rotulo="Nome sugerido" rotuloAcessivel="nome da oferta" valor={nome} editado={editadoNome} caixa
                    onMudar={(v) => onEditar(chave, 'nome', v, sugestao.nome)}>
                    {avisos.tituloLongo !== null && <Aviso>{textoTituloLongo(avisos.tituloLongo, limites.max_titulo)}</Aviso>}
                </CampoEmLinha>
                <CampoEmLinha id={`sku-${chave}`} campo="sku" rotulo="SKU sugerido" rotuloAcessivel="código (SKU)" valor={sku} editado={editadoSku} mono
                    onMudar={(v) => onEditar(chave, 'sku', v, sugestao.sku)}>
                    {avisos.skuLongo && <Aviso>{msgSkuLongo(limites.max_sku)}</Aviso>}
                    {avisos.skuRepetido && <Aviso>{MSG_SKU_REPETIDO}</Aviso>}
                </CampoEmLinha>
                {editada && (
                    <button type="button" onClick={() => onDesfazer(chave)} data-acao="desfazer-edicao"
                        className={cn('min-h-[28px] text-[12px] text-white/55 underline-offset-2 hover:text-white hover:underline', FOCO)}>
                        Desfazer edição
                    </button>
                )}
            </div>

            <div data-col="composicao" className={cn('min-w-0', DIVISORIA)}>
                <p className={ROTULO}>Composição</p>
                <ul className="mt-1 space-y-1.5">
                    {sugestao.itens.map((i) => {
                        const texto = textoDoComponente(i);

                        return (
                            <li key={i.variacao_id} className="flex items-center gap-2">
                                <QuadroFotoProduto nome={i.produto_nome} tamanho="icone" />
                                <span className="min-w-0 truncate text-[13px] text-white/85" title={texto}>{texto}</span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div data-col="motivo" className={cn('min-w-0 space-y-2', DIVISORIA)}>
                <div>
                    <p className={ROTULO}>Motivo da sugestão</p>
                    <p data-motivo title={sugestao.porque} className="text-[13.5px] leading-snug text-white xl:line-clamp-2">{sugestao.porque}</p>
                </div>
                {erro ? (
                    <p role="alert" className="text-[12px] text-red-300">Não aceitamos esta sugestão: {erro}.</p>
                ) : (
                    <LogisticaEFrete sugestao={sugestao} freteCotado={freteCotado} vocabulario={vocabulario} />
                )}
            </div>

            <div data-col="acoes" className="grid grid-cols-2 gap-2 xl:flex xl:justify-end xl:gap-3">
                <button type="button" onClick={() => onDescartar(chave)} disabled={aceitando || bloqueado}
                    aria-label={`Descartar sugestão ${nome}`} data-acao="descartar"
                    className={cn(BOTAO, 'border border-sky-200/20 bg-white/[0.02] font-medium text-white/85 hover:bg-white/[0.06]', FOCO)}>
                    Descartar
                </button>
                <button type="button" onClick={() => onAceitar(chave)} disabled={! aceitavel || aceitando || bloqueado} title={aceitavel ? undefined : razao}
                    aria-label={`Aceitar sugestão ${nome}`} data-acao="aceitar"
                    className={cn(BOTAO, 'bg-ecf-yellow font-semibold text-black hover:bg-ecf-yellow/90', FOCO)}>
                    {aceitando && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
                    {aceitando ? 'Aceitando…' : 'Aceitar'}
                </button>
            </div>
        </article>
    );
}
