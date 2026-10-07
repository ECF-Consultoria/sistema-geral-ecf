import { AlertTriangle, Check, Loader2, Pencil, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtReais } from '@/Components/Portal/Estrutura/comum';
import { PilulaLogistica } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import { avisosDoCartao, foiEditada, podeAceitar, valorDoCampo } from '@/lib/sugestoesSelecao';
import { MSG_SKU_REPETIDO, ROTULO_FASE, msgSkuLongo, textoTituloLongo } from '@/lib/sugestoesEstrutura';

// ─── Cartão da sugestão (UI-SPEC, anatomia 1-6) ─────────────────────────────
//
// D-08: composição (N × produto, valor, Ref), o porquê, logística e frete estimado.
// D-19: nome e código editáveis, só em estado local até Aceitar; avisos de 60/120.
// Sem planilha na tela: é um cartão com campos de formulário. Nada é calculado aqui:
// o servidor manda a composição, o porquê, a logística e o frete prontos; as regras de
// marcação, edição e aceite moram em `sugestoesSelecao.js`.

const CLASSE_CAMPO = 'h-11 w-full rounded-[10px] border border-white/[0.10] bg-white/[0.04] px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0 sm:h-9';
const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';

const Aviso = ({ children }) => (
    <p className="mt-1 flex items-start gap-1.5 text-[12px] text-amber-300/90">
        <AlertTriangle size={14} className="mt-px shrink-0" aria-hidden="true" />
        <span>{children}</span>
    </p>
);

export default function CartaoSugestao({
    sugestao, estado, limites, vocabulario, marcada = false, aceitando = false, bloqueado = false,
    erro = null, freteCotado = null, onMarcar, onEditar, onDesfazer, onAceitar, onDescartar, onTipo,
}) {
    const { chave } = sugestao;
    const idNome = `nome-${chave}`;
    const idSku = `sku-${chave}`;
    const idRazao = `razao-${chave}`;
    const nome = valorDoCampo(estado, sugestao, 'nome');
    const sku = valorDoCampo(estado, sugestao, 'sku');
    const avisos = avisosDoCartao(sugestao, estado, limites);
    const aceitavel = podeAceitar(sugestao, estado, limites);
    const editada = foiEditada(estado, chave);
    const editadoNome = estado.edicoes[chave]?.nome !== undefined;
    const editadoSku = estado.edicoes[chave]?.sku !== undefined;
    const razao = avisos.skuLongo ? msgSkuLongo(limites.max_sku) : (aceitavel ? undefined : 'Preencha o nome e o código para aceitar.');

    // Tipos distintos dos componentes (pílulas clicáveis: abrem a escolha do tipo do produto).
    const tipos = [];
    for (const i of sugestao.itens) {
        if (i.tipo_nome && ! tipos.some((t) => t.tipo === i.tipo)) tipos.push({ tipo: i.tipo, nome: i.tipo_nome, produtoId: i.produto_id });
    }

    // Mesmo valor nos componentes: aparece uma vez no fim da linha de título.
    const valores = sugestao.itens.map((i) => i.valor).filter(Boolean);
    const valorUnico = sugestao.itens.length > 1 && valores.length === sugestao.itens.length && new Set(valores).size === 1 ? valores[0] : null;

    const logistica = sugestao.logistica;
    const semMedida = logistica?.sem_medida ?? [];
    const valorFrete = freteCotado?.valor ?? sugestao.frete?.valor ?? null;

    return (
        <article aria-labelledby={idNome} data-sugestao data-chave={chave}
            className={cn('relative min-w-0 rounded-[14px] border bg-ecf-card p-4', marcada ? 'border-ecf-yellow/50' : 'border-white/[0.08]')}>
            <div className="flex flex-wrap items-center gap-2">
                <label className="-m-1.5 grid h-11 w-11 shrink-0 place-items-center" title={aceitavel ? undefined : razao}>
                    <input type="checkbox" checked={marcada} disabled={(! aceitavel && ! marcada) || bloqueado} onChange={() => onMarcar(chave)}
                        aria-label={`Marcar sugestão ${nome}`} aria-describedby={aceitavel ? undefined : idRazao}
                        className={cn('h-5 w-5 rounded border-white/30 bg-transparent text-ecf-yellow', FOCO)} />
                </label>
                <span className="inline-flex h-7 items-center rounded-lg bg-white/[0.06] px-2.5 text-[13px] font-semibold text-white/80">{ROTULO_FASE[sugestao.fase] ?? sugestao.fase}</span>
                {tipos.map((t) => (onTipo ? (
                    <button key={t.tipo} type="button" onClick={() => onTipo(t.produtoId)} title="Mudar o tipo deste produto"
                        className={cn('inline-flex h-7 items-center gap-1 rounded-lg border border-white/[0.10] px-2.5 text-[13px] text-white/70 hover:bg-white/[0.06] hover:text-white', FOCO)}>
                        {t.nome} <Pencil size={11} aria-hidden="true" />
                    </button>
                ) : (
                    <span key={t.tipo} className="inline-flex h-7 items-center rounded-lg border border-white/[0.10] px-2.5 text-[13px] text-white/70">{t.nome}</span>
                )))}
                {valorUnico && <span className="ml-auto text-[12px] text-white/60">{valorUnico}</span>}
            </div>

            {! aceitavel && <span id={idRazao} className="sr-only">{razao}</span>}

            <ul className="mt-2 divide-y divide-white/[0.06] text-[14px]">
                {sugestao.itens.map((i) => (
                    <li key={`${i.variacao_id}`} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-2">
                        <span className="font-semibold tabular-nums text-white">{i.quantidade} ×</span>
                        <span className="min-w-0 max-w-full truncate text-white" title={i.produto_nome}>{i.produto_nome}</span>
                        <span className="flex min-w-0 items-baseline gap-2 text-[12px] text-white/60">
                            {! valorUnico && i.valor && <span>{i.valor}</span>}
                            {i.sku && <span className="font-mono">{i.sku}</span>}
                        </span>
                    </li>
                ))}
            </ul>

            {sugestao.porque && <p className="mt-1 text-[12px] leading-relaxed text-white/60">{sugestao.porque}</p>}

            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_220px]">
                <div className="min-w-0">
                    <label htmlFor={idNome} className="mb-1 flex items-center gap-2 text-[12px] font-semibold text-white/70">
                        Nome da oferta
                        {editadoNome && <span className="font-normal text-white/45" data-editado>· editado</span>}
                    </label>
                    <input id={idNome} value={nome ?? ''} onChange={(e) => onEditar(chave, 'nome', e.target.value, sugestao.nome)} className={CLASSE_CAMPO} />
                    {avisos.tituloLongo !== null && <Aviso>{textoTituloLongo(avisos.tituloLongo, limites.max_titulo)}</Aviso>}
                </div>
                <div className="min-w-0">
                    <label htmlFor={idSku} className="mb-1 flex items-center gap-2 text-[12px] font-semibold text-white/70">
                        Código (SKU)
                        {editadoSku && <span className="font-normal text-white/45" data-editado>· editado</span>}
                    </label>
                    <input id={idSku} value={sku ?? ''} onChange={(e) => onEditar(chave, 'sku', e.target.value, sugestao.sku)} className={cn(CLASSE_CAMPO, 'font-mono')} />
                    {avisos.skuLongo && <Aviso>{msgSkuLongo(limites.max_sku)}</Aviso>}
                    {avisos.skuRepetido && <Aviso>{MSG_SKU_REPETIDO}</Aviso>}
                </div>
            </div>
            {editada && (
                <button type="button" onClick={() => onDesfazer(chave)} className={cn('mt-1 min-h-[28px] text-[12px] text-white/55 underline-offset-2 hover:text-white hover:underline', FOCO)}>
                    Desfazer edição
                </button>
            )}

            {erro ? (
                <p role="alert" className="mt-3 text-[12px] text-red-300">Não aceitamos esta sugestão: {erro}.</p>
            ) : logistica && (
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-white/60">
                    <PilulaLogistica chave={logistica.chave} rotulos={vocabulario?.logisticas} />
                    {logistica.chave === 'pendente' && semMedida.length > 0 && (
                        <span>
                            Faltam medidas em{' '}
                            {semMedida.map((p, k) => (
                                <span key={p.id}>
                                    {k > 0 && ', '}
                                    <a href={route('portal.auth.estrutura.produtos.ficha', p.id)} className="text-white/85 underline underline-offset-2 hover:text-white">{p.nome}</a>
                                </span>
                            ))}
                            . Complete na ficha.
                        </span>
                    )}
                    {logistica.chave === 'me1' && <span title="Fora do tamanho do envio ME2. O frete usa a tabela da sua transportadora.">Frete pela sua transportadora</span>}
                    {(logistica.chave === 'me2' || logistica.chave === 'me2_full') && valorFrete !== null && (
                        <span>
                            Frete <span className="text-[14px] font-semibold tabular-nums text-white">{fmtReais(valorFrete)}</span>{' '}
                            {freteCotado && ! freteCotado.falhou ? 'Mercado Livre' : 'estimado'}
                        </span>
                    )}
                </div>
            )}

            <div className="mt-3 grid grid-cols-2 gap-2 sm:flex">
                <button type="button" onClick={() => onAceitar(chave)} disabled={! aceitavel || aceitando || bloqueado} title={aceitavel ? undefined : razao}
                    aria-label={`Aceitar sugestão ${nome}`} data-acao="aceitar"
                    className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/85 transition-colors hover:bg-white/[0.07] hover:text-white disabled:pointer-events-none disabled:opacity-40', FOCO)}>
                    {aceitando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Check size={14} aria-hidden="true" />}
                    {aceitando ? 'Aceitando…' : 'Aceitar'}
                </button>
                <button type="button" onClick={() => onDescartar(chave)} disabled={aceitando || bloqueado}
                    aria-label={`Descartar sugestão ${nome}`} data-acao="descartar"
                    className={cn('inline-flex h-11 items-center justify-center gap-1.5 rounded-xl px-4 text-[13px] font-medium text-white/60 transition-colors hover:bg-white/[0.05] hover:text-white disabled:pointer-events-none disabled:opacity-40', FOCO)}>
                    <X size={14} aria-hidden="true" /> Descartar
                </button>
            </div>
        </article>
    );
}
