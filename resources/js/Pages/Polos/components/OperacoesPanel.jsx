import { useState } from 'react';
import { ChevronDown, ChevronRight, LayoutGrid, GitBranch, Send, ShieldAlert, UserX } from 'lucide-react';
import { VAZIO } from '@/hooks/useAutoFilter';
import StatChip from '@/Components/StatChip';
import DonutIndicador from '@/Components/DonutIndicador';

/**
 * OperacoesPanel — "Centro de Operações" acionável do Painel Polos, no estilo dos gráficos
 * do cockpit financeiro (donut echarts com nº central + emphasis; legenda estilo Coorte M1).
 * Cada donut/legenda é a renderização de af.optionsFor(coluna); clicar chama irPara(col,valor)
 * → troca de lente + af.setOnly (ou limpa, se já isolado). Auto-atualiza (deriva de `empresas`).
 *
 * CONTEXTUAL POR LENTE (rev.4): reage à aba ativa via `lente`.
 *  · Numa área (Acessos/Produtos/Logística) → só os donuts daquela área, detalhados.
 *  · Geral → tríade de comando (Fase/Envio/Precisa de ação) + 1 "principal" por área (resumo).
 *  · Financeiro → nada (o Cockpit financeiro já cobre) — o painel nem renderiza.
 */

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';

const TONE_HEX = { red: '#fca5a5', amber: '#fcd34d', green: '#6ee7b7', neutral: '#9ca3af', violet: '#c4b5fd', sky: '#7dd3fc', yellow: '#ffe600' };
const SIT_TONE = { problema: 'red', fora_prazo: 'red', pendente_envio: 'amber', sem_ficha: 'amber', ads_off: 'neutral' };
const ENVIO_TONE = { falta_enviar: 'red', enviado: 'amber', concluido: 'green' };

// Rótulo da lente ativa p/ o cabeçalho "Operações · <área>".
const LENTE_LABEL = { geral: 'Geral', acessos: 'Acessos & Setup', produtos: 'Produtos & Publicação', logistica: 'Logística', financeiro: 'Financeiro' };

// Config visual de cada coluna que vira donut (rótulo + ícone + label do centro).
// Rótulos batem com as colunas da grade / com os títulos originais dos donuts de comando.
const DONUT_CFG = {
    fase:               { label: 'Fase',            icone: GitBranch,   centro: 'empresas' },
    envio:              { label: 'Envio do link',   icone: Send,        centro: 'empresas' },
    situacao:           { label: 'Precisa de ação', icone: ShieldAlert, centro: 'pendências' },
    acesso_colaborador: { label: 'Acesso colaborador',                  centro: 'empresas' },
    grupo_whatsapp:     { label: 'Grupo de WhatsApp',                   centro: 'empresas' },
    planilha_produtos:  { label: 'Planilha produtos',                   centro: 'empresas' },
    listagem:           { label: 'Listagem',                            centro: 'empresas' },
    publicacao:         { label: 'Publicação',                          centro: 'empresas' },
    decola:             { label: 'Decola',                              centro: 'empresas' },
    erp:                { label: 'ERP',                                 centro: 'empresas' },
    places:             { label: 'Places',                             centro: 'empresas' },
    me1:                { label: 'ME1',                                 centro: 'empresas' },
    integradora:        { label: 'Integradora',                         centro: 'empresas' },
};

// Donuts por lente. `principal` = indicador-resumo da área (usado na Geral).
// Só colunas CATEGÓRICAS/booleanas viram donut — texto (contextos_logistica, gmail),
// data (data_solicitacao) e número (fin_*, onboarding) ficam de fora.
const DONUTS_POR_LENTE = {
    geral: [ // resumo: comandos globais + 1 principal por área
        { col: 'fase',               tone: 'fase' },
        { col: 'envio',              tone: 'envio' },
        { col: 'situacao',           tone: 'situacao' },      // "Precisa de ação"
        { col: 'acesso_colaborador', tone: 'valor', principal: true }, // Acessos
        { col: 'planilha_produtos',  tone: 'valor', principal: true }, // Produtos
        { col: 'erp',                tone: 'valor', principal: true }, // Logística
    ],
    acessos:   [{ col: 'acesso_colaborador', tone: 'valor', principal: true },
                { col: 'grupo_whatsapp',     tone: 'valor' }],
    produtos:  [{ col: 'planilha_produtos', tone: 'valor', principal: true },
                { col: 'listagem',          tone: 'valor' },
                { col: 'publicacao',        tone: 'valor' },
                { col: 'decola',            tone: 'valor' }],
    logistica: [{ col: 'erp',         tone: 'valor', principal: true },
                { col: 'places',      tone: 'valor' },
                { col: 'me1',         tone: 'valor' },
                { col: 'integradora', tone: 'valor' }],
    financeiro: [], // sem donuts — Cockpit financeiro já cobre
};

export default function OperacoesPanel({ af, irPara, toneValor, toneFase, lente = 'geral' }) {
    const [aberto, setAberto] = useState(true);

    const donuts = DONUTS_POR_LENTE[lente] ?? [];
    // Lente sem donuts (ex.: Financeiro) → não renderiza o painel.
    if (!donuts.length) return null;

    // Cor de uma fatia conforme a estratégia de "tom" da coluna.
    const corDe = (tone, v) => {
        if (tone === 'fase')     return TONE_HEX[toneFase(v)] ?? TONE_HEX.neutral;
        if (tone === 'envio')    return TONE_HEX[ENVIO_TONE[v] ?? 'neutral'];
        if (tone === 'situacao') return TONE_HEX[SIT_TONE[v] ?? 'neutral'];
        return TONE_HEX[toneValor(v)] ?? TONE_HEX.neutral; // 'valor'
    };
    // Opções de uma coluna, podando os "vazios" (e o 'ok' de Situação, que não é pendência).
    const optsDe = (col, tone) => {
        const opts = af.optionsFor(col);
        if (tone === 'situacao') return opts.filter((o) => o.value !== 'ok');
        if (tone === 'valor')    return opts.filter((o) => o.value !== VAZIO);
        return opts;
    };
    // activeKey de uma coluna = o valor isolado (p/ destacar a fatia). null se não isolado.
    const activeKey = (col, dados) => (af.isColumnActive(col) ? (dados.find((d) => af.isOnly(col, d.key))?.key ?? null) : null);

    const montar = (d) => {
        const cfg   = DONUT_CFG[d.col] ?? { label: d.col, centro: 'empresas' };
        const dados = optsDe(d.col, d.tone).map((o) => ({ key: o.value, label: o.label, count: o.count, cor: corDe(d.tone, o.value) }));
        return { ...d, cfg, dados };
    };
    const renderDonut = (d, height) => (
        <div key={d.col} className={CARD}>
            <DonutIndicador titulo={d.cfg.label} icone={d.cfg.icone} height={height} centroLabel={d.cfg.centro ?? 'empresas'}
                dados={d.dados} activeKey={activeKey(d.col, d.dados)} onClick={(v) => irPara(d.col, v)} />
        </div>
    );

    const montados = donuts.map(montar);
    // Na Geral: comandos (não-principais) no topo; "Resumo por área" (principais) abaixo.
    // Nas áreas: tudo junto num único grid.
    const comandos   = lente === 'geral' ? montados.filter((d) => !d.principal) : [];
    const principais = lente === 'geral' ? montados.filter((d) => d.principal) : montados;

    const semResp = af.optionsFor('responsavel').find((o) => o.value === VAZIO);

    return (
        <div className="rounded-2xl border border-white/[0.06] bg-white/[0.015]">
            <button type="button" onClick={() => setAberto((v) => !v)}
                className="flex w-full items-center gap-2 px-4 py-3 text-sm font-semibold text-white/70 transition hover:text-white">
                {aberto ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                <LayoutGrid size={15} className="text-ecf-yellow" /> Operações
                {lente !== 'geral' && <span className="font-normal text-white/35">· {LENTE_LABEL[lente] ?? lente}</span>}
            </button>

            {aberto && (
                <div className="space-y-3 px-4 pb-4">
                    {/* Sem responsável — ângulo acionável só na Geral (visão macro) */}
                    {lente === 'geral' && semResp && (semResp.count > 0 || af.isOnly('responsavel', VAZIO)) && (
                        <StatChip label="Sem responsável" count={semResp.count} icon={UserX} tone="amber"
                            active={af.isOnly('responsavel', VAZIO)} onClick={() => irPara('responsavel', VAZIO)} />
                    )}

                    {/* Donuts de comando (só na Geral) */}
                    {comandos.length > 0 && (
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            {comandos.map((d) => renderDonut(d))}
                        </div>
                    )}

                    {/* Geral: "Resumo por área" · Áreas: título da própria aba */}
                    {lente === 'geral' && principais.length > 0 && (
                        <h4 className="pt-1 text-[10px] font-semibold uppercase tracking-wider text-white/40">Resumo por área</h4>
                    )}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {principais.map((d) => renderDonut(d, 150))}
                    </div>
                </div>
            )}
        </div>
    );
}
