import AppLayout from '@/Layouts/AppLayout';
import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, ArrowLeft, FileDown, PackageCheck, RefreshCw, Truck, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    CONFIG_PADRAO, calcular, deDia, fmtDia, fmtInt, hojeDia, paraDia, porPrioridade,
} from '@/lib/planejadorFull';
import { Aviso, STATUS, STATUS_COMPRA, classeBotao, classeBotaoPrimario, classeCampo } from '@/Components/PlanejadorFull/comum';
import VisaoGeral from '@/Components/PlanejadorFull/VisaoGeral';
import ProximoEnvio from '@/Components/PlanejadorFull/ProximoEnvio';
import Produtos from '@/Components/PlanejadorFull/Produtos';
import Compras from '@/Components/PlanejadorFull/Compras';
import Envios from '@/Components/PlanejadorFull/Envios';
import Ajustes from '@/Components/PlanejadorFull/Ajustes';
import Gaveta from '@/Components/PlanejadorFull/Gaveta';

// ─── Planejador FULL de uma empresa ─────────────────────────────────────────
// Mesmo fluxo do Planejador_FULL.html (Visão geral → Próximo envio → Produtos →
// Compras → Ajustes), com os dados da coleta diária na API do ML em vez das
// planilhas, e a aba nova "Envios a caminho" para a entrada pendente.

const ABAS = [
    ['visao', 'Visão geral'],
    ['envio', 'Próximo envio'],
    ['produtos', 'Produtos no FULL'],
    ['compras', 'Compras'],
    ['envios', 'Envios a caminho'],
    ['ajustes', 'Lead times e ajustes'],
];

const CHAVES_MINIMO_UM = ['intervaloEnvios', 'limiteExcesso', 'intervaloCompras'];

const fmtDataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

const erroDe = (e) => e?.response?.data?.message
    || Object.values(e?.response?.data?.errors || {})[0]?.[0]
    || 'Não consegui salvar. Confira a conexão e tente de novo.';

function abaDaUrl() {
    const h = typeof window !== 'undefined' ? window.location.hash.replace('#', '') : '';
    return ABAS.some(([k]) => k === h) ? h : 'visao';
}

export default function Show(props) {
    return (
        <AppLayout title={`Planejador FULL · ${props.empresa.nome}`}>
            {/* key: trocar de empresa remonta tudo (os ajustes locais são da empresa). */}
            <Planejador key={props.empresa.id} {...props} />
        </AppLayout>
    );
}

function Planejador({
    empresa, dataBase, vendasPeriodo, coleta, coletaOk, produtos, config: configInicial, fornecedores: fornecedoresIniciais,
    skus: skusIniciais, itens: itensIniciais, envios, outrasEmpresas, temToken,
}) {
    const [aba, setAba] = useState(abaDaUrl);
    const [filtroProduto, setFiltroProduto] = useState('todos');
    const [filtroCompra, setFiltroCompra] = useState('todos');
    const [config, setConfig] = useState({ ...CONFIG_PADRAO, ...configInicial });
    const [fornecedores, setFornecedores] = useState(fornecedoresIniciais || []);
    const [skus, setSkus] = useState(skusIniciais || {});
    const [itens, setItens] = useState(itensIniciais || {});
    const [gaveta, setGaveta] = useState(null);
    const [aviso, setAviso] = useState(null);
    const [modalEnvio, setModalEnvio] = useState(false);
    const [atualizando, setAtualizando] = useState(coleta?.status === 'rodando');

    const avisar = useCallback((mensagem, tipo = 'info') => setAviso({ mensagem, tipo }), []);
    const fecharAviso = useCallback(() => setAviso(null), []);

    const R = useMemo(
        () => calcular({ config, dataBase, diasVendas: vendasPeriodo.dias, produtos, skus, itens, fornecedores }),
        [config, dataBase, vendasPeriodo.dias, produtos, skus, itens, fornecedores],
    );

    const fornecedoresLista = useMemo(
        () => [...new Set([...fornecedores.map((f) => f.nome), ...R.compras.map((b) => b.fornecedor)])].filter(Boolean).sort((a, b) => a.localeCompare(b, 'pt-BR')),
        [fornecedores, R.compras],
    );
    const usoFornecedor = useMemo(() => {
        const n = {};
        R.compras.forEach((b) => { n[b.fornecedor] = (n[b.fornecedor] || 0) + 1; });
        return n;
    }, [R.compras]);

    const mudarAba = useCallback((nova) => {
        setAba(nova);
        setGaveta(null);
        try { window.history.replaceState(window.history.state, '', `#${nova}`); } catch { /* sem histórico */ }
    }, []);

    const ir = useCallback((destino, { status } = {}) => {
        if (destino === 'produtos' && status) setFiltroProduto(status);
        if (destino === 'compras' && status) setFiltroCompra(status);
        mudarAba(destino);
        window.scrollTo(0, 0);
    }, [mudarAba]);

    // ─── Gravação ───────────────────────────────────────────────────────────
    const timerConfig = useRef(null);
    const renomearPendente = useRef(null);

    const enviarConfig = useCallback((cfg, forn) => {
        clearTimeout(timerConfig.current);
        timerConfig.current = setTimeout(() => {
            const renomear = renomearPendente.current;
            renomearPendente.current = null;
            window.axios.put(route('planejador-full.config', empresa.id), { config: cfg, fornecedores: forn, renomear })
                .catch((e) => avisar(erroDe(e), 'erro'));
        }, 500);
    }, [empresa.id, avisar]);

    const alterarConfig = useCallback((parcial) => {
        const novo = { ...config };
        for (const [k, v] of Object.entries(parcial)) {
            if (k === 'proximoDespacho') {
                novo[k] = v || '';
                continue;
            }
            const minimo = CHAVES_MINIMO_UM.includes(k) ? 1 : 0;
            if (v == null || !Number.isFinite(v) || v < minimo || (k === 'fatorSazonal' && v <= 0)) {
                avisar(k === 'fatorSazonal' ? 'O fator precisa ser maior que zero.' : minimo ? 'Use pelo menos 1 dia.' : 'Informe um número maior ou igual a zero.', 'erro');
                return false;
            }
            novo[k] = k === 'fatorSazonal' ? v : Math.round(v);
        }
        setConfig(novo);
        enviarConfig(novo, fornecedores);
        return true;
    }, [config, fornecedores, enviarConfig, avisar]);

    const salvarSkus = useCallback((mapa) => {
        setSkus((prev) => {
            const novo = { ...prev };
            Object.entries(mapa).forEach(([chave, campos]) => { novo[chave] = { ...(prev[chave] || {}), ...campos }; });
            return novo;
        });
        window.axios.put(route('planejador-full.skus', empresa.id), { skus: mapa }).catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, avisar]);
    const salvarSku = useCallback((chave, campos) => salvarSkus({ [chave]: campos }), [salvarSkus]);

    const salvarItens = useCallback((mapa) => {
        setItens((prev) => {
            const novo = { ...prev };
            Object.entries(mapa).forEach(([cod, campos]) => { novo[cod] = { ...(prev[cod] || {}), ...campos }; });
            return novo;
        });
        window.axios.put(route('planejador-full.itens', empresa.id), { itens: mapa }).catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, avisar]);
    const salvarItem = useCallback((cod, campos) => salvarItens({ [cod]: campos }), [salvarItens]);

    const alterarFornecedores = useCallback((lista, renomear) => {
        const nomes = lista.map((f) => f.nome.trim().toLowerCase());
        if (new Set(nomes).size !== nomes.length) {
            avisar('Já existe um fornecedor com esse nome.', 'erro');
            return;
        }
        if (renomear) {
            renomearPendente.current = renomear;
            // SKUs que usavam o nome antigo — por escolha ou pela marca do anúncio — seguem junto.
            const mapa = {};
            R.compras.forEach((b) => { if (b.fornecedor === renomear.de) mapa[b.chaveSku] = { fornecedor: renomear.para }; });
            if (Object.keys(mapa).length) salvarSkus(mapa);
        }
        setFornecedores(lista);
        enviarConfig(config, lista);
    }, [R.compras, config, enviarConfig, salvarSkus, avisar]);

    // ─── Envios ─────────────────────────────────────────────────────────────
    const recarregar = useCallback(() => router.reload({ only: ['produtos', 'envios'], preserveScroll: true }), []);

    const registrarEnvio = useCallback(async ({ dataDespacho, inboundMl, observacao, limparManuais }) => {
        const linhas = R.rows.filter((r) => r.qtdFinal > 0);
        try {
            await window.axios.post(route('planejador-full.envios.store', empresa.id), {
                dataDespacho,
                inboundMl: inboundMl || null,
                observacao: observacao || null,
                itens: linhas.map((r) => ({ cod: r.cod, quantidade: r.qtdFinal })),
            });
        } catch (e) {
            avisar(erroDe(e), 'erro');
            return false;
        }
        if (limparManuais) {
            const mapa = {};
            linhas.forEach((r) => { if (r.qtdManual != null) mapa[r.cod] = { qtdManual: null }; });
            if (Object.keys(mapa).length) salvarItens(mapa);
        }
        setModalEnvio(false);
        recarregar();
        mudarAba('envios');
        avisar(`Envio registrado: ${fmtInt(linhas.length)} itens, ${fmtInt(linhas.reduce((a, r) => a + r.qtdFinal, 0))} un. a caminho.`);
        return true;
    }, [R.rows, empresa.id, avisar, salvarItens, recarregar, mudarAba]);

    const atualizarEnvio = useCallback((id, campos) => {
        window.axios.patch(route('planejador-full.envios.update', [empresa.id, id]), campos)
            .then(recarregar)
            .catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, recarregar, avisar]);

    const excluirEnvio = useCallback((id) => {
        window.axios.delete(route('planejador-full.envios.destroy', [empresa.id, id]))
            .then(() => { recarregar(); avisar('Envio excluído.'); })
            .catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, recarregar, avisar]);

    const importarPlanilha = useCallback((arquivo) => {
        const dados = new FormData();
        dados.append('arquivo', arquivo);
        avisar('Lendo a planilha…');
        window.axios.post(route('planejador-full.envios.planilha', empresa.id), dados)
            .then(({ data }) => {
                recarregar();
                setAtualizando(true);
                avisar(`Entrada pendente importada: ${fmtInt(data.importados)} Códigos ML, ${fmtInt(data.unidades)} un.${data.ignorados ? ` · ${data.ignorados} de outra conta ignorados` : ''}. A baixa do que já chegou roda em seguida.`);
            })
            .catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, recarregar, avisar]);

    const importarGalpao = useCallback((arquivo) => {
        const dados = new FormData();
        dados.append('arquivo', arquivo);
        window.axios.post(route('planejador-full.galpao', empresa.id), dados)
            .then(({ data }) => {
                setSkus((prev) => {
                    const novo = { ...prev };
                    Object.entries(data.skus || {}).forEach(([sku, galpao]) => { novo[sku] = { ...(prev[sku] || {}), galpao }; });
                    return novo;
                });
                avisar(`Estoque do galpão atualizado para ${fmtInt(data.atualizados)} SKUs${data.ignorados ? ` · ${data.ignorados} SKUs da planilha não estão no FULL` : ''}.`);
            })
            .catch((e) => avisar(erroDe(e), 'erro'));
    }, [empresa.id, avisar]);

    // ─── Coleta ("Atualizar agora") ─────────────────────────────────────────
    const atualizar = useCallback(() => {
        router.post(route('planejador-full.atualizar', empresa.id), {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setAtualizando(true),
        });
    }, [empresa.id]);

    const coletaInicial = useRef(coleta?.iniciadoEm || null);
    useEffect(() => {
        if (!atualizando) return undefined;
        const limite = Date.now() + 20 * 60 * 1000;
        const timer = setInterval(async () => {
            if (Date.now() > limite) { setAtualizando(false); return; }
            try {
                const { data } = await window.axios.get(route('planejador-full.status', empresa.id));
                const c = data.coleta;
                const nova = c && c.iniciadoEm !== coletaInicial.current;
                if (c && c.status !== 'rodando' && (nova || coleta?.status === 'rodando')) {
                    setAtualizando(false);
                    coletaInicial.current = c.iniciadoEm;
                    if (c.status === 'erro') avisar(`A coleta falhou: ${c.erro || 'erro desconhecido'}`, 'erro');
                    else avisar('Dados do Mercado Livre atualizados.');
                    router.reload({ preserveScroll: true });
                }
            } catch { /* tenta de novo no próximo ciclo */ }
        }, 15000);
        return () => clearInterval(timer);
    }, [atualizando, empresa.id, avisar, coleta?.status]);

    // ─── Exportação ─────────────────────────────────────────────────────────
    const baixar = useCallback(async (tipo, colunas, linhas, rodape) => {
        try {
            const resp = await window.axios.post(route('planejador-full.exportar', empresa.id), { tipo, colunas, linhas, rodape }, { responseType: 'blob' });
            const nome = (resp.headers['content-disposition'] || '').match(/filename="?([^";]+)"?/)?.[1] || `${tipo}-full.xlsx`;
            const url = URL.createObjectURL(resp.data);
            const a = document.createElement('a');
            a.href = url;
            a.download = nome;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1500);
        } catch {
            avisar('Não consegui gerar a planilha.', 'erro');
        }
    }, [empresa.id, avisar]);

    const exportarEnvio = useCallback(() => {
        const linhas = R.rows.filter((r) => r.qtdFinal > 0).sort(porPrioridade);
        if (!linhas.length) { avisar('Nenhum item com quantidade a enviar.', 'erro'); return; }
        baixar(
            'envio',
            ['Código ML', 'SKU', 'Produto', 'Quantidade', 'Status', 'Curva', 'Ruptura prevista', 'Iniciar envio até', 'Observação'],
            linhas.map((r) => [r.cod, r.sku || '', r.titulo, r.qtdFinal, STATUS[r.status].label, r.abc, fmtDia(r.ruptura, 'ano'), fmtDia(r.iniciarAte, 'ano'), r.observacao]),
            [
                `Total: ${linhas.reduce((a, r) => a + r.qtdFinal, 0)} un. em ${linhas.length} itens`,
                `Despacho ${fmtDia(R.ship, 'ano')} · apto no FULL ${fmtDia(R.arrival, 'ano')} · estoque do ML de ${fmtDia(R.base, 'ano')}`,
            ],
        );
    }, [R, baixar, avisar]);

    const exportarCompras = useCallback((lista) => {
        const linhas = lista.filter((b) => b.d > 0).sort((a, b) => a.fornecedor.localeCompare(b.fornecedor, 'pt-BR') || (a.pedirAte ?? 0) - (b.pedirAte ?? 0));
        baixar(
            'compras',
            ['Fornecedor', 'SKU', 'Produto', 'Status', 'Pedir até', 'Qtd a comprar', 'Venda/dia', 'FULL (apto + a caminho)', 'Galpão', 'Em aberto', 'Lead time (dias)'],
            linhas.map((b) => [b.fornecedor, b.sku || '', b.titulo, STATUS_COMPRA[b.status].label, fmtDia(b.pedirAte, 'ano'), b.comprar, Math.round(b.d * 10) / 10, b.posFull, b.informado ? b.galpao : 'não informado', b.aberto, b.lt]),
        );
    }, [baixar]);

    const copiarEnvio = useCallback(async () => {
        const linhas = R.rows.filter((r) => r.qtdFinal > 0).sort(porPrioridade);
        const texto = ['Código ML\tSKU\tProduto\tQuantidade', ...linhas.map((r) => `${r.cod}\t${r.sku || ''}\t${r.titulo}\t${r.qtdFinal}`)].join('\n');
        try {
            await navigator.clipboard.writeText(texto);
            avisar(`${linhas.length} itens copiados. Cole numa planilha.`);
        } catch {
            avisar('Não consegui copiar. Use Exportar .xlsx.', 'erro');
        }
    }, [R.rows, avisar]);

    // ─── Tela ───────────────────────────────────────────────────────────────
    const linhaGaveta = gaveta ? R.rows.find((r) => r.cod === gaveta) : null;
    const urgentes = R.totais.ruptura + R.totais.critico;
    const comprasUrgentes = R.totais.comprarJa + R.totais.comprarSemana;
    const enviosAbertos = envios.filter((e) => e.status === 'a_caminho').length;
    const diasSemColeta = coletaOk ? hojeDia() - paraDia(dataBase) : null;
    const contador = { produtos: urgentes, compras: comprasUrgentes, envios: enviosAbertos };

    return (
        <div className="mx-auto max-w-[1440px]">
            <div className="mb-4 flex flex-wrap items-end gap-x-4 gap-y-3">
                <div className="min-w-0 flex-[1_1_360px]">
                    <Link href={route('planejador-full.index')} className="mb-1 inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70">
                        <ArrowLeft size={13} /> Todas as contas
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-white">
                            <Truck size={24} className="text-ecf-yellow" /> Planejador <span className="text-ecf-yellow">FULL</span>
                        </h1>
                        <select
                            value={empresa.id}
                            onChange={(e) => router.visit(`${route('planejador-full.show', e.target.value)}#${aba}`)}
                            aria-label="Empresa"
                            className={cn(classeCampo, 'w-auto max-w-[320px] py-1.5 text-[14px] font-semibold')}
                        >
                            {!outrasEmpresas.some((o) => o.id === empresa.id) && <option value={empresa.id}>{empresa.nome}</option>}
                            {outrasEmpresas.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                        </select>
                    </div>
                    <p className="mt-1 text-[12.5px] text-white/45">
                        {coletaOk ? (
                            <>Estoque FULL lido em <b className="text-white/80">{fmtDataHora(coletaOk.iniciadoEm)}</b> · vendas de <b className="text-white/80">{fmtDia(paraDia(vendasPeriodo.de))}</b> a <b className="text-white/80">{fmtDia(paraDia(vendasPeriodo.ate), 'ano')}</b> · direto da API do Mercado Livre, todo dia às 06:30</>
                        ) : 'Ainda sem coleta do Mercado Livre para esta conta.'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className={classeBotao} onClick={atualizar} disabled={atualizando || !temToken} title={temToken ? undefined : 'Esta empresa não tem token do Mercado Livre ativo'}>
                        <RefreshCw size={14} className={cn(atualizando && 'animate-spin')} /> {atualizando ? 'Atualizando…' : 'Atualizar agora'}
                    </button>
                    <button type="button" className={classeBotaoPrimario} onClick={exportarEnvio}><FileDown size={14} /> Exportar envio</button>
                </div>
            </div>

            {coleta?.status === 'erro' && !atualizando && (
                <Faixa tipo="erro">A última coleta ({fmtDataHora(coleta.iniciadoEm)}) falhou: {coleta.erro}. Os números abaixo são da última coleta que deu certo.</Faixa>
            )}
            {diasSemColeta != null && diasSemColeta >= 2 && (
                <Faixa tipo="alerta">O estoque é de {diasSemColeta} dias atrás. Clique em "Atualizar agora" para buscar o atual.</Faixa>
            )}
            {!temToken && (
                <Faixa tipo="alerta">Esta empresa está sem token do Mercado Livre ativo: a coleta diária não roda. Conecte a conta em Dev → ML OAuth.</Faixa>
            )}

            <nav className="mb-5 flex gap-0.5 overflow-x-auto border-b border-white/[0.08]" role="tablist" aria-label="Seções">
                {ABAS.map(([k, rotulo]) => (
                    <button
                        key={k}
                        type="button"
                        role="tab"
                        aria-selected={aba === k}
                        onClick={() => mudarAba(k)}
                        className={cn(
                            'whitespace-nowrap border-b-[3px] px-3.5 pb-2.5 pt-2 text-[14px] font-semibold transition',
                            aba === k ? 'border-ecf-yellow text-white' : 'border-transparent text-white/50 hover:text-white',
                        )}
                    >
                        {rotulo}
                        {contador[k] > 0 && (
                            <span className={cn('ml-1.5 inline-block min-w-[20px] rounded-full px-1.5 text-center text-[11px] font-bold', k === 'envios' ? 'bg-ecf-yellow/20 text-ecf-yellow' : 'bg-red-500/20 text-red-300')}>
                                {contador[k]}
                            </span>
                        )}
                    </button>
                ))}
            </nav>

            {!coletaOk && !produtos.length ? (
                <div className="rounded-xl border border-white/[0.08] bg-ecf-card px-6 py-14 text-center">
                    <Truck size={32} className="mx-auto mb-3 text-white/25" />
                    <p className="text-[15px] font-semibold text-white">Ainda não há dados do FULL desta conta</p>
                    <p className="mx-auto mt-1 max-w-[52ch] text-[13px] text-white/50">
                        A coleta roda todo dia às 06:30 para as contas com anúncio no FULL. Para não esperar, clique em "Atualizar agora": leva alguns minutos.
                    </p>
                </div>
            ) : (
                <>
                    {aba === 'visao' && <VisaoGeral R={R} ir={ir} abrirProduto={setGaveta} />}
                    {aba === 'envio' && (
                        <ProximoEnvio R={R} alterarConfig={alterarConfig} salvarItem={salvarItem} exportar={exportarEnvio} copiar={copiarEnvio} registrar={() => setModalEnvio(true)} abrirProduto={setGaveta} />
                    )}
                    {aba === 'produtos' && <Produtos R={R} filtro={filtroProduto} setFiltro={setFiltroProduto} abrirProduto={setGaveta} />}
                    {aba === 'compras' && (
                        <Compras R={R} skus={skus} fornecedoresLista={fornecedoresLista} filtro={filtroCompra} setFiltro={setFiltroCompra} salvarSku={salvarSku} importarGalpao={importarGalpao} exportar={exportarCompras} />
                    )}
                    {aba === 'envios' && <Envios envios={envios} atualizarEnvio={atualizarEnvio} excluirEnvio={excluirEnvio} importarPlanilha={importarPlanilha} />}
                    {aba === 'ajustes' && (
                        <Ajustes
                            R={R}
                            alterarConfig={alterarConfig}
                            fornecedores={fornecedores}
                            alterarFornecedores={alterarFornecedores}
                            usoFornecedor={usoFornecedor}
                            coleta={coleta}
                            coletaOk={coletaOk}
                            vendasPeriodo={vendasPeriodo}
                            atualizar={atualizar}
                            atualizando={atualizando}
                            temToken={temToken}
                        />
                    )}
                </>
            )}

            {linhaGaveta && (
                <Gaveta
                    row={linhaGaveta}
                    R={R}
                    empresaId={empresa.id}
                    dataBase={dataBase}
                    skus={skus}
                    fornecedoresLista={fornecedoresLista}
                    salvarItem={salvarItem}
                    salvarSku={salvarSku}
                    fechar={() => setGaveta(null)}
                />
            )}

            {modalEnvio && <ModalRegistrarEnvio R={R} fechar={() => setModalEnvio(false)} registrar={registrarEnvio} />}

            <Aviso mensagem={aviso?.mensagem} tipo={aviso?.tipo} aoFechar={fecharAviso} />
        </div>
    );
}

function Faixa({ tipo, children }) {
    return (
        <div className={cn(
            'mb-3 flex items-start gap-2.5 rounded-xl border px-4 py-2.5 text-[13px]',
            tipo === 'erro' ? 'border-red-500/30 bg-red-500/10 text-red-200' : 'border-amber-400/25 bg-amber-400/10 text-amber-100',
        )}
        >
            <AlertTriangle size={16} className="mt-0.5 shrink-0" />
            <span>{children}</span>
        </div>
    );
}

function ModalRegistrarEnvio({ R, fechar, registrar }) {
    const linhas = R.rows.filter((r) => r.qtdFinal > 0);
    const unidades = linhas.reduce((a, r) => a + r.qtdFinal, 0);
    const temManual = linhas.some((r) => r.qtdManual != null);
    const [dataDespacho, setDataDespacho] = useState(deDia(R.ship));
    const [inboundMl, setInboundMl] = useState('');
    const [observacao, setObservacao] = useState('');
    const [limparManuais, setLimparManuais] = useState(true);
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        const esc = (e) => { if (e.key === 'Escape') fechar(); };
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [fechar]);

    const confirmar = async () => {
        setEnviando(true);
        const ok = await registrar({ dataDespacho, inboundMl: inboundMl.trim(), observacao: observacao.trim(), limparManuais });
        if (!ok) setEnviando(false);
    };

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4" onClick={(e) => { if (e.target === e.currentTarget) fechar(); }}>
            <div role="dialog" aria-modal="true" aria-labelledby="modal-envio-titulo" className="max-h-[calc(100%-32px)] w-full max-w-[560px] overflow-y-auto rounded-xl border border-white/[0.08] bg-[#0f1116] p-5 shadow-2xl">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="modal-envio-titulo" className="flex items-center gap-2 text-[18px] font-bold text-white"><PackageCheck size={18} className="text-ecf-yellow" /> Registrar envio ao FULL</h2>
                        <p className="mt-1 text-[13px] text-white/55">
                            {fmtInt(linhas.length)} itens · <b className="text-white">{fmtInt(unidades)} unidades</b>. Elas passam a contar como "a caminho" até o ML receber — e a próxima sugestão já desconta.
                        </p>
                    </div>
                    <button type="button" onClick={fechar} className="rounded-lg border border-white/[0.12] p-1.5 text-white/70 hover:text-white" aria-label="Fechar"><X size={16} /></button>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-3">
                    <label className="grid gap-1">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Data do despacho</span>
                        <input type="date" value={dataDespacho} onChange={(e) => setDataDespacho(e.target.value)} className={classeCampo} />
                    </label>
                    <label className="grid gap-1">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Nº do envio no ML (opcional)</span>
                        <input type="text" inputMode="numeric" value={inboundMl} onChange={(e) => setInboundMl(e.target.value.replace(/\D/g, ''))} placeholder="ex.: 75714964" className={cn(classeCampo, 'font-mono')} />
                    </label>
                    <label className="col-span-2 grid gap-1">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Observação</span>
                        <input type="text" value={observacao} maxLength={255} onChange={(e) => setObservacao(e.target.value)} placeholder="Ex.: coleta agendada pela transportadora X" className={classeCampo} />
                    </label>
                    {temManual && (
                        <label className="col-span-2 flex items-center gap-2 text-[13px] text-white/80">
                            <input type="checkbox" checked={limparManuais} onChange={(e) => setLimparManuais(e.target.checked)} className="h-4 w-4 accent-[#ffe600]" />
                            Limpar as quantidades fixadas à mão depois de registrar
                        </label>
                    )}
                </div>
                <p className="mt-3 text-[12px] text-white/40">
                    O número do envio aparece no Mercado Livre quando você cria o envio para o Full. Com ele, a baixa diária conta só as chegadas deste envio; dá para preencher depois na aba Envios a caminho.
                </p>

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className={classeBotao} onClick={fechar}>Cancelar</button>
                    <button type="button" className={classeBotaoPrimario} onClick={confirmar} disabled={enviando || !dataDespacho || !linhas.length}>
                        {enviando ? 'Registrando…' : 'Registrar envio'}
                    </button>
                </div>
            </div>
        </div>
    );
}
