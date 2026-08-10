import { useState } from 'react';
import { ChevronRight, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    tituloEntraram, tituloNaoEntraram,
    SECAO_ENTRARAM_SUBTITULO, SECAO_NAO_ENTRARAM_SUBTITULO, NOTA_TOPO_RESSALVA,
    AVISO_CARTEIRA_SO_SHOPEE, SELO_SHOPEE_TEXTO, SELO_SHOPEE_TITULO, MARGEM_SEM_DADO_TEXTO,
    fmtPctAbs, fmtPp, fmtVarPct, fmtNotaEmpresa, fmtMargemAntesDepois, motivoLabel,
    dividirPorEntrada, carteiraTodaShopeeNaEntrada, deveColapsarNaoEntraram, ehPlaceholderShopee,
    marketplaceLabel, MARKETPLACE_TOOLTIP,
} from '@/lib/desempenhoLabels';

/**
 * Tabela de duas seções do detalhe por empresa (UIEM-02) — "entraram na
 * conta" e "não entraram", cada uma com o denominador explícito no título
 * (D-06). Componente de apresentação puro: sem router, sem usePage, sem
 * fetch — consumido por Performance/Show.jsx (Plano 04) e por
 * Desempenho/RelatorioBonificacao.jsx (Plano 05), então nada aqui pode
 * assumir uma tela específica.
 *
 * A margem por empresa segue o formato travado pela D-05 (antes → depois +
 * variação em pontos percentuais, sem concatenar "%" na variação). A
 * empresa Shopee entra na conta com ressalva visual (D-07) — selo por
 * linha no caso comum, aviso único quando a carteira inteira é Shopee,
 * nunca excluída do denominador.
 *
 * As três ramificações (particionamento da lista, colapso da segunda
 * seção e o caso de carteira inteira Shopee) vêm de funções puras já
 * travadas por teste na Fase 123 — não são reimplementadas aqui.
 */

function corFaturamento(v) {
    if (v == null) return 'text-white/40';
    return Number(v) >= 0 ? 'text-emerald-300' : 'text-rose-300';
}

function corPp(v) {
    if (v == null) return 'text-white/40';
    return Number(v) >= 0 ? 'text-emerald-300' : 'text-rose-300';
}

function corNotaOficial(v) {
    if (v == null) return 'text-white/70';
    const n = Number(v);
    if (n >= 4.5) return 'text-emerald-400';
    if (n >= 4.0) return 'text-ecf-yellow';
    return 'text-white/70';
}

/**
 * Nome da loja + marketplace (quick 260810-n5b).
 *
 * A linha de baixo imprimia `fonte_financeira` cru — "ADMAN"/"SHOPEE". "Adman"
 * é o nome da API de onde o número vem, não um marketplace, e não significa
 * nada para quem lê a tela. Agora mostra "Mercado Livre" / "Shopee", traduzido
 * pelo `marketplaceLabel()` (ver o comentário longo em `desempenhoLabels.js`
 * sobre por que a fonte é essa e não `companies.marketplace`).
 */
function CelulaEmpresa({ linha }) {
    const marketplace = marketplaceLabel(linha.fonte_financeira);

    return (
        <td className="px-3 py-3">
            <div className="text-white/80 truncate max-w-[220px]">{linha.company_name}</div>
            {marketplace !== null && (
                <div className="text-[10px] tracking-wide text-white/30" title={MARKETPLACE_TOOLTIP}>
                    {marketplace}
                </div>
            )}
        </td>
    );
}

function CelulaNps({ linha }) {
    return (
        <td className="px-3 py-3 tabular-nums">
            <div className="text-white/80">{fmtNotaEmpresa(linha.nps_pontos)}</div>
            <div className="text-[10px] text-white/30">pontos</div>
        </td>
    );
}

function CelulaFaturamento({ linha }) {
    return (
        <td className="px-3 py-3 tabular-nums">
            <div className={corFaturamento(linha.faturamento_var_pct)}>{fmtVarPct(linha.faturamento_var_pct)}</div>
            <div className="text-[10px] text-white/30">{fmtNotaEmpresa(linha.faturamento_pontos)} pontos</div>
        </td>
    );
}

/**
 * Célula de margem — três estados: formato antes→depois padrão (D-05),
 * selo Shopee por linha (D-07), ou texto único quando a carteira inteira
 * é Shopee (`ocultarSeloIndividual`, decidido uma vez pelo chamador).
 */
function CelulaMargem({ linha, ocultarSeloIndividual }) {
    const placeholderShopee = ehPlaceholderShopee(linha);
    const mostraSeloIndividual = placeholderShopee && !ocultarSeloIndividual;

    let conteudo;
    let tituloCelula;

    if (ocultarSeloIndividual && placeholderShopee) {
        conteudo = <span className="text-white/40 text-xs">{MARGEM_SEM_DADO_TEXTO}</span>;
    } else if (mostraSeloIndividual) {
        conteudo = (
            <span
                title={SELO_SHOPEE_TITULO}
                className="inline-flex items-center gap-1 rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-200"
            >
                {SELO_SHOPEE_TEXTO}
            </span>
        );
    } else {
        tituloCelula = fmtMargemAntesDepois(linha.margem_pct_anterior, linha.margem_pct_atual, linha.margem_var_pp);
        conteudo = (
            <div className="flex items-center gap-1.5">
                <span>{fmtPctAbs(linha.margem_pct_anterior)}</span>
                <span className="text-white/30">→</span>
                <span>{fmtPctAbs(linha.margem_pct_atual)}</span>
                <span className={cn('font-semibold', corPp(linha.margem_var_pp))}>{fmtPp(linha.margem_var_pp)}</span>
            </div>
        );
    }

    return (
        <td className="px-3 py-3 tabular-nums" title={tituloCelula}>
            {conteudo}
            <div className="text-[10px] text-white/30 mt-0.5">{fmtNotaEmpresa(linha.margem_pontos)} pontos</div>
        </td>
    );
}

function CelulaNota({ linha }) {
    return (
        <td className={cn('px-3 py-3 tabular-nums font-bold', corNotaOficial(linha.nota_empresa))}>
            {fmtNotaEmpresa(linha.nota_empresa)}
        </td>
    );
}

function CelulaNotaParcial({ linha }) {
    return (
        <td
            className="px-3 py-3 tabular-nums text-white/50"
            title="Média só dos componentes disponíveis — não é a nota oficial"
        >
            {fmtNotaEmpresa(linha.nota_empresa_parcial)}
        </td>
    );
}

function CelulaMotivos({ linha }) {
    const motivos = Array.isArray(linha?.quality?.motivos) ? linha.quality.motivos : [];

    if (motivos.length === 0) {
        return <td className="px-3 py-3 text-white/30">—</td>;
    }

    return (
        <td className="px-3 py-3">
            <div className="flex flex-wrap gap-1">
                {motivos.map((slug, i) => (
                    <span
                        key={`${slug}-${i}`}
                        className="inline-flex rounded-full bg-white/[0.04] border border-white/[0.08] px-2 py-0.5 text-[10px] text-white/60"
                    >
                        {motivoLabel(slug)}
                    </span>
                ))}
            </div>
        </td>
    );
}

export default function EmpresasScoreTabela({
    linhas = [],
    resumo = { entraram: 0, nao_entraram: 0 },
    className = '',
}) {
    const { entraram, naoEntraram } = dividirPorEntrada(linhas);
    const qtdNaoEntraram = resumo?.nao_entraram ?? 0;

    const [naoEntraramAberto, setNaoEntraramAberto] = useState(
        !deveColapsarNaoEntraram(qtdNaoEntraram),
    );

    // D-07 — caso Matheus Estrela: carteira inteira Shopee. Decidido uma
    // vez sobre a primeira seção; um selo por linha vira ruído quando é a
    // carteira toda.
    const carteiraSoShopee = carteiraTodaShopeeNaEntrada(entraram);

    return (
        <div className={cn('rounded-2xl bg-ecf-card border border-white/[0.08]', className)}>
            {/* Seção "Entraram na conta" */}
            <div className="p-4 sm:p-5">
                <h3 className="text-white text-sm font-semibold">{tituloEntraram(resumo?.entraram ?? 0)}</h3>
                <p className="mt-1 text-white/50 text-xs">{SECAO_ENTRARAM_SUBTITULO}</p>
                <p className="mt-0.5 text-white/50 text-xs">{NOTA_TOPO_RESSALVA}</p>

                {carteiraSoShopee && (
                    <div className="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
                        {AVISO_CARTEIRA_SO_SHOPEE}
                    </div>
                )}

                <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr className="border-b border-white/[0.08] text-left text-[10px] uppercase tracking-wider text-white/40">
                                <th className="px-3 py-3 font-semibold">Empresa</th>
                                <th className="px-3 py-3 font-semibold">NPS</th>
                                <th className="px-3 py-3 font-semibold">Faturamento</th>
                                <th className="px-3 py-3 font-semibold">Margem</th>
                                <th className="px-3 py-3 font-semibold">Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entraram.map((linha) => (
                                <tr key={linha.company_id} className="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                    <CelulaEmpresa linha={linha} />
                                    <CelulaNps linha={linha} />
                                    <CelulaFaturamento linha={linha} />
                                    <CelulaMargem linha={linha} ocultarSeloIndividual={carteiraSoShopee} />
                                    <CelulaNota linha={linha} />
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Seção "Não entraram" — inteiramente ausente quando zero */}
            {qtdNaoEntraram > 0 && (
                <div className="border-t border-white/[0.08] p-4 sm:p-5">
                    <div className="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <h3 className="text-white text-sm font-semibold">{tituloNaoEntraram(qtdNaoEntraram)}</h3>
                            <p className="mt-1 text-white/50 text-xs">{SECAO_NAO_ENTRARAM_SUBTITULO}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setNaoEntraramAberto((v) => !v)}
                            className="inline-flex items-center gap-1.5 text-[13px] text-white/60 hover:text-white transition-colors"
                        >
                            {naoEntraramAberto ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                            {naoEntraramAberto
                                ? `Ocultar as ${qtdNaoEntraram} empresas que não entraram`
                                : `Ver as ${qtdNaoEntraram} empresas que não entraram`}
                        </button>
                    </div>

                    {naoEntraramAberto && (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full text-sm min-w-[720px]">
                                <thead>
                                    <tr className="border-b border-white/[0.08] text-left text-[10px] uppercase tracking-wider text-white/40">
                                        <th className="px-3 py-3 font-semibold">Empresa</th>
                                        <th className="px-3 py-3 font-semibold">NPS</th>
                                        <th className="px-3 py-3 font-semibold">Faturamento</th>
                                        <th className="px-3 py-3 font-semibold">Margem</th>
                                        <th className="px-3 py-3 font-semibold">Nota parcial</th>
                                        <th className="px-3 py-3 font-semibold">Por que não entrou</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {naoEntraram.map((linha) => (
                                        <tr key={linha.company_id} className="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                            <CelulaEmpresa linha={linha} />
                                            <CelulaNps linha={linha} />
                                            <CelulaFaturamento linha={linha} />
                                            <CelulaMargem linha={linha} ocultarSeloIndividual={false} />
                                            <CelulaNotaParcial linha={linha} />
                                            <CelulaMotivos linha={linha} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
