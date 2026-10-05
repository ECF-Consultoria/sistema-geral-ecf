import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CAMPO, SELECT } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { useLeitura } from '../useAlavancas';
import { ROTULO_STATUS_PROMOCAO, ROTULO_TIPO } from '../rotulos';
import { fmtData, hojeSP, somarDias } from '../formato';
import ModalConfirmacao from '../ModalConfirmacao';
import ItensDoConvite from './ItensDoConvite';
import AdicionarProdutos from './AdicionarProdutos';

const TIPOS_DO_VENDEDOR = ['SELLER_CAMPAIGN', 'VOLUME'];

// Subtipos do leve mais, pague menos (doc "campanhas de desconto por quantidade").
const SUBTIPOS = {
    BNGM: 'Leve N e pague M (ex.: leve 3, pague 2)',
    BNSP: 'Desconto de P% comprando N (ex.: 50% de desconto comprando 2)',
    SPONTH: 'Desconto de P% na N-ésima unidade (ex.: 50% de desconto na 2ª unidade)',
};

const dia = (iso) => String(iso ?? '').slice(0, 10);

const inteiro = (texto) => {
    const n = Number(String(texto ?? '').trim());

    return Number.isInteger(n) && n > 0 ? n : null;
};

const decimal = (texto) => {
    const bruto = String(texto ?? '').trim().replace(',', '.');
    const n = Number(bruto);

    return bruto !== '' && Number.isFinite(n) && n > 0 ? n : null;
};

const VAZIO = { tipo: 'SELLER_CAMPAIGN', nome: '', inicio: '', fim: '', sub: 'BNGM', compra: '', paga: '', percentual: '', combina: null };

/** Formulário de criar/alterar. `original` (campanha lida) liga o modo alterar. */
function Formulario({ original, liberada, motivo, onMontar, onCancelar }) {
    const alterando = Boolean(original);
    const hoje = hojeSP();
    const ativa = original?.status === 'started';
    const [f, setF] = useState(alterando
        ? { ...VAZIO, tipo: original.tipo, nome: original.nome ?? '', inicio: dia(original.inicio), fim: dia(original.fim) }
        : { ...VAZIO, inicio: hoje, fim: somarDias(hoje, 13) });
    const mudar = (campo, valor) => setF((antes) => ({ ...antes, [campo]: valor }));

    const volume = f.tipo === 'VOLUME';
    // Campanha iniciada não muda a data de início; no leve mais, pague menos as datas nunca mudam e, depois de iniciado, só o nome.
    const datasTravadas = alterando && volume;
    const inicioTravado = datasTravadas || (alterando && ativa);
    const regraTravada = alterando && volume && ativa;

    function montar() {
        if (! alterando) {
            const dados = { promotion_type: f.tipo, name: f.nome.trim(), start_date: f.inicio, finish_date: f.fim };
            if (volume) {
                dados.sub_type = f.sub;
                dados.buy_quantity = inteiro(f.compra);
                if (f.sub === 'BNGM') dados.pay_quantity = inteiro(f.paga); else dados.discount_percentage = decimal(f.percentual);
                dados.allow_combination = f.combina === true;
            }

            return { acao: 'campanha.criar', titulo: 'Criar campanha', itens: [dados] };
        }

        // Só vai o que mudou.
        const dados = { promotion_id: original.id, promotion_type: original.tipo };
        if (f.nome.trim() !== (original.nome ?? '')) dados.name = f.nome.trim();
        if (! datasTravadas && ! ativa && f.inicio !== dia(original.inicio)) dados.start_date = f.inicio;
        if (! datasTravadas && f.fim !== dia(original.fim)) dados.finish_date = f.fim;
        if (volume && ! regraTravada) {
            if (inteiro(f.compra) !== null) dados.buy_quantity = inteiro(f.compra);
            if (inteiro(f.paga) !== null) dados.pay_quantity = inteiro(f.paga);
            if (decimal(f.percentual) !== null) dados.discount_percentage = decimal(f.percentual);
            if (f.combina !== null) dados.allow_combination = f.combina;
        }

        return { acao: 'campanha.alterar', titulo: 'Alterar campanha', itens: [dados], mudou: Object.keys(dados).length > 2 };
    }

    const montada = montar();
    const nomeOk = f.nome.trim() !== '';
    const datasOk = f.inicio !== '' && f.fim !== '' && f.fim >= f.inicio && (volume || somarDias(f.inicio, 13) >= f.fim);
    const regraOk = ! volume || alterando || (inteiro(f.compra) !== null && (f.sub === 'BNGM' ? inteiro(f.paga) !== null : decimal(f.percentual) !== null));
    const pronto = nomeOk && (alterando ? montada.mudou : datasOk && regraOk);

    return (
        <div className="space-y-4 rounded-lg border border-white/[0.08] bg-white/[0.03] p-4">
            <div className="flex flex-wrap gap-3">
                <label className="block w-64">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Tipo</span>
                    <select value={f.tipo} disabled={alterando} onChange={(ev) => mudar('tipo', ev.target.value)} className={cn(SELECT, 'h-10')}>
                        <option value="SELLER_CAMPAIGN">{ROTULO_TIPO.SELLER_CAMPAIGN}</option>
                        <option value="VOLUME">{ROTULO_TIPO.VOLUME}</option>
                    </select>
                </label>
                <label className="block min-w-[220px] flex-1">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Nome da campanha</span>
                    <input type="text" maxLength={60} value={f.nome} onChange={(ev) => mudar('nome', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
            </div>

            <div className="flex flex-wrap gap-3">
                <label className="block w-44">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Início</span>
                    <input type="date" value={f.inicio} min={alterando ? undefined : hoje} disabled={inicioTravado} onChange={(ev) => mudar('inicio', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
                <label className="block w-44">
                    <span className="mb-1 block text-[11px] font-normal text-white/55">Fim</span>
                    <input type="date" value={f.fim} min={f.inicio} disabled={datasTravadas} onChange={(ev) => mudar('fim', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                </label>
            </div>
            {! volume && <p className="text-[13px] font-normal text-white/55">A campanha do vendedor dura até 14 dias.</p>}
            {alterando && ativa && <p className="text-[13px] font-normal text-white/55">Campanha iniciada: a data de início não muda.</p>}
            {datasTravadas && <p className="text-[13px] font-normal text-white/55">No leve mais, pague menos as datas não mudam; depois de iniciado, só o nome muda.</p>}

            {volume && (
                <div className="space-y-3">
                    <div className="flex flex-wrap gap-3">
                        <label className="block min-w-[260px] flex-1">
                            <span className="mb-1 block text-[11px] font-normal text-white/55">Como funciona</span>
                            <select value={f.sub} disabled={regraTravada} onChange={(ev) => mudar('sub', ev.target.value)} className={cn(SELECT, 'h-10')}>
                                {Object.entries(SUBTIPOS).map(([chave, rotulo]) => <option key={chave} value={chave}>{rotulo}</option>)}
                            </select>
                        </label>
                        <label className="block w-40">
                            <span className="mb-1 block text-[11px] font-normal text-white/55">
                                {f.sub === 'BNGM' ? 'Quantidade que leva' : (f.sub === 'BNSP' ? 'Quantidade comprada' : 'Número da unidade')}
                            </span>
                            <input type="text" inputMode="numeric" value={f.compra} disabled={regraTravada} onChange={(ev) => mudar('compra', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                        </label>
                        {f.sub === 'BNGM' ? (
                            <label className="block w-40">
                                <span className="mb-1 block text-[11px] font-normal text-white/55">Quantidade que paga</span>
                                <input type="text" inputMode="numeric" value={f.paga} disabled={regraTravada} onChange={(ev) => mudar('paga', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                            </label>
                        ) : (
                            <label className="block w-40">
                                <span className="mb-1 block text-[11px] font-normal text-white/55">Desconto (%)</span>
                                <input type="text" inputMode="decimal" value={f.percentual} disabled={regraTravada} onChange={(ev) => mudar('percentual', ev.target.value)} className={cn(CAMPO, 'h-10')} />
                            </label>
                        )}
                    </div>
                    <label className="flex items-center gap-2 text-[13px] font-normal text-white/70">
                        <input
                            type="checkbox"
                            checked={f.combina === true}
                            disabled={regraTravada}
                            onChange={(ev) => mudar('combina', ev.target.checked)}
                            className="h-4 w-4"
                        />
                        Permitir combinar produtos diferentes na mesma compra
                    </label>
                    {alterando && ! regraTravada && (
                        <p className="text-[13px] font-normal text-white/55">Preencha só o que quiser mudar; o resto continua como está no Mercado Livre.</p>
                    )}
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                <BotaoAcao
                    primario
                    disabled={! liberada || ! pronto}
                    title={liberada ? undefined : motivo}
                    onClick={() => onMontar(montada)}
                >
                    {alterando ? 'Revisar alteração' : 'Revisar e criar'}
                </BotaoAcao>
                <BotaoAcao onClick={onCancelar}>Cancelar</BotaoAcao>
            </div>
        </div>
    );
}

/** Campanhas do vendedor e leve mais, pague menos: criar, alterar, excluir e cuidar dos produtos. */
export default function CampanhasDoVendedor({ conta, liberada, motivo, limites }) {
    const { dados, erro, carregando, recarregar } = useLeitura('promocoes', conta);
    const [form, setForm] = useState(null);
    const [aberta, setAberta] = useState(null);
    const [alvo, setAlvo] = useState(null);
    const campanhas = (dados?.itens ?? []).filter((c) => TIPOS_DO_VENDEDOR.includes(c.tipo));

    function aoConcluir() {
        setForm(null);
        recarregar();
    }

    return (
        <div className="space-y-4 border-t border-white/[0.06] px-3 py-4">
            {! form && (
                <BotaoAcao disabled={! liberada} title={liberada ? undefined : motivo} onClick={() => setForm({ original: null })}>
                    Nova campanha
                </BotaoAcao>
            )}
            {form && (
                <Formulario
                    key={form.original?.id ?? 'nova'}
                    original={form.original}
                    liberada={liberada}
                    motivo={motivo}
                    onMontar={setAlvo}
                    onCancelar={() => setForm(null)}
                />
            )}

            {carregando && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {erro && (
                <p className="text-[13px] font-normal text-white/55">
                    {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
                </p>
            )}
            {! carregando && ! erro && campanhas.length === 0 && (
                <p className="text-[13px] font-normal text-white/55">Nenhuma campanha do vendedor agora.</p>
            )}

            {campanhas.map((c) => {
                const estaAberta = aberta === c.id;

                return (
                    <div key={c.id} className="rounded-xl border border-white/[0.08] bg-white/[0.03]">
                        <div className="flex flex-wrap items-start gap-3 p-3">
                            <button
                                type="button"
                                aria-expanded={estaAberta}
                                aria-label={`Produtos de ${c.nome ?? c.id}`}
                                onClick={() => setAberta(estaAberta ? null : c.id)}
                                className="mt-1 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            >
                                {estaAberta
                                    ? <ChevronDown className="h-4 w-4 text-white/55" aria-hidden="true" />
                                    : <ChevronRight className="h-4 w-4 text-white/55" aria-hidden="true" />}
                            </button>
                            <div className="min-w-[200px] flex-1 space-y-1 text-[13px] font-normal text-white/70">
                                <p className="font-bold text-white/90">{c.nome ?? c.id}</p>
                                <p className="text-white/55">
                                    {ROTULO_TIPO[c.tipo] ?? c.tipo} · {ROTULO_STATUS_PROMOCAO[c.status] ?? c.status} · {fmtData(c.inicio)} a {fmtData(c.fim)}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <BotaoAcao onClick={() => setAberta(estaAberta ? null : c.id)}>Produtos</BotaoAcao>
                                <BotaoAcao disabled={! liberada} title={liberada ? undefined : motivo} onClick={() => setForm({ original: c })}>Alterar</BotaoAcao>
                                <BotaoAcao
                                    disabled={! liberada}
                                    title={liberada ? undefined : motivo}
                                    onClick={() => setAlvo({
                                        acao: 'campanha.excluir',
                                        titulo: 'Excluir campanha',
                                        itens: [{ promotion_id: c.id, promotion_type: c.tipo }],
                                    })}
                                >
                                    Excluir
                                </BotaoAcao>
                            </div>
                        </div>
                        {estaAberta && (
                            <>
                                <ItensDoConvite conta={conta} convite={c} liberada={liberada} motivo={motivo} limites={limites} />
                                <AdicionarProdutos
                                    conta={conta}
                                    promocao={c}
                                    comPreco={c.tipo === 'SELLER_CAMPAIGN'}
                                    liberada={liberada}
                                    motivo={motivo}
                                    limites={limites}
                                />
                            </>
                        )}
                    </div>
                );
            })}

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
