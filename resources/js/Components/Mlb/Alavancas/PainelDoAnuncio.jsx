import { Link } from '@inertiajs/react';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { LINK } from '@/Components/Publicador/Mesa/comum';
import { useLeitura } from './useAlavancas';
import { ROTULO_STATUS_PROMOCAO, ROTULO_TIPO } from './rotulos';
import { fmtBRL, fmtData, fmtInt, fmtPct } from './formato';

const ATIVAS = ['started', 'pending'];

/** Uma linha de leitura: rótulo à esquerda, o que a conta tem à direita (ou o erro com "Tentar de novo"). */
function Linha({ rotulo, leitura, children }) {
    return (
        <div className="flex flex-wrap items-start gap-x-4 gap-y-1 border-t border-white/[0.06] py-3 first:border-t-0">
            <p className="w-48 shrink-0 text-[13px] font-bold text-white/70">{rotulo}</p>
            <div className="min-w-[240px] flex-1 text-[13px] font-normal text-white/70">
                {leitura.carregando && ! leitura.dados && <p className="text-white/55">Lendo…</p>}
                {leitura.erro && (
                    <p className="text-white/55">
                        {leitura.erro} <button type="button" onClick={() => leitura.recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
                    </p>
                )}
                {! leitura.erro && leitura.dados && children}
            </div>
        </div>
    );
}

/** O que uma entrada de promoção diz do anúncio: tipo, situação, preço e prazo. */
function textoDaEntrada(e) {
    const partes = [ROTULO_TIPO[e.tipo] ?? e.tipo, ROTULO_STATUS_PROMOCAO[e.status] ?? e.status];
    if (e.status === 'candidate' && e.preco_sugerido !== null && e.preco_sugerido !== undefined) {
        partes.push(`sugerido ${fmtBRL(e.preco_sugerido)}`);
    } else if (e.preco !== null && e.preco !== undefined) {
        partes.push(fmtBRL(e.preco));
    }
    if (e.fim) partes.push(`até ${fmtData(e.fim)}`);

    return partes.filter(Boolean).join(' · ');
}

/**
 * O anúncio que veio da fila de publicados (`?item=MLB…`, 09/10/2026): o que a conta já tem nele —
 * promoções e convites, campanhas automáticas e atacado — pelas leituras POR ITEM que já existem.
 * Só leitura: criar e alterar continua nas abas abaixo, com a confirmação de sempre.
 */
export default function PainelDoAnuncio({ conta, item, onAbrirAba, onFechar }) {
    const promocoes = useLeitura('produtos.promocoes', conta, { item });
    const exclusao = useLeitura('exclusao.item', conta, { item });
    const atacadoDaConta = useLeitura('atacado', conta);
    const business = atacadoDaConta.dados?.business === true;
    const atacado = useLeitura('atacado.item', conta, { item }, { ativo: business });

    const entradas = promocoes.dados?.itens ?? [];
    const ativas = entradas.filter((e) => ATIVAS.includes(e.status));
    const convites = entradas.filter((e) => e.status === 'candidate');
    const faixas = atacado.dados?.faixas ?? null;

    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-5" aria-label={`Anúncio ${item}`}>
            <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[15px] font-bold text-white">Anúncio <span className="font-mono">{item}</span></p>
                    <p className="text-[13px] font-normal text-white/55">
                        Aberto pela fila de publicados aguardando alavancas. Use as abas abaixo para criar e alterar.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Link href={route('mlb.anuncios.publicador.tarefas.index', { conta })} className={LINK}>Voltar à fila</Link>
                    <button type="button" onClick={onFechar} className={LINK}>Fechar</button>
                </div>
            </div>

            <Linha rotulo="Promoções" leitura={promocoes}>
                {ativas.length === 0 && convites.length === 0 && <p>Nenhuma promoção nem convite para este anúncio agora.</p>}
                {ativas.length > 0 && (
                    <ul className="space-y-1">
                        {ativas.map((e, i) => <li key={`a-${i}`}>{textoDaEntrada(e)}</li>)}
                    </ul>
                )}
                {convites.length > 0 && (
                    <div className="mt-1">
                        <p className="text-white/55">Pode entrar em:</p>
                        <ul className="space-y-1">
                            {convites.map((e, i) => <li key={`c-${i}`}>{textoDaEntrada(e)}</li>)}
                        </ul>
                    </div>
                )}
                <div className="mt-2">
                    <BotaoAcao onClick={() => onAbrirAba('promocoes')}>Ir para Promoções</BotaoAcao>
                </div>
            </Linha>

            <Linha rotulo="Campanhas automáticas" leitura={exclusao}>
                <p>
                    {exclusao.dados?.excluido === true
                        ? 'Bloqueado: o Mercado Livre não inclui este anúncio sozinho nas campanhas.'
                        : 'Liberado: o Mercado Livre pode incluir este anúncio nas campanhas automáticas.'}
                </p>
            </Linha>

            <Linha rotulo="Atacado" leitura={business ? atacado : atacadoDaConta}>
                {! business && <p>{atacadoDaConta.dados?.explicacao ?? 'Esta conta não tem o preço por quantidade liberado.'}</p>}
                {business && faixas === null && atacado.dados?.aviso && <p>{atacado.dados.aviso}</p>}
                {business && Array.isArray(faixas) && faixas.length === 0 && <p>Sem faixas de atacado neste anúncio.</p>}
                {business && Array.isArray(faixas) && faixas.length > 0 && (
                    <ul className="space-y-1">
                        {faixas.map((f, i) => (
                            <li key={`f-${i}`}>A partir de {fmtInt(f.quantidade_minima)} unidades: {fmtPct(f.percentual)} para empresas</li>
                        ))}
                    </ul>
                )}
                {business && (
                    <div className="mt-2">
                        <BotaoAcao onClick={() => onAbrirAba('atacado')}>Ir para Atacado</BotaoAcao>
                    </div>
                )}
            </Linha>
        </section>
    );
}
