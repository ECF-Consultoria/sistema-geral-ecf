import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Link2, Plus, Trash2 } from 'lucide-react';
import Janela from './Janela';
import { Botao, CLASSE_INPUT } from './comum';
import { cn } from '@/lib/utils';

// ─── Colados que aguardam oferta ────────────────────────────────────────────
//
// A linha colada cujo SKU não casou. Três saídas manuais — vincular a uma
// oferta, criar a oferta a partir dela, descartar — e uma automática: criar ou
// renomear uma oferta para aquele SKU faz a linha entrar nela sozinha (no
// servidor, na escrita; nunca ao abrir esta tela).

function Linha({ linha, ofertas, vocabulario, onCriarOferta, recarregar }) {
    const [filtro, setFiltro] = useState(linha.sku_colado ?? '');
    const [escolhida, setEscolhida] = useState('');
    const [ocupado, setOcupado] = useState(false);

    const candidatas = useMemo(() => {
        const t = filtro.trim().toLowerCase();

        return (t ? ofertas.filter((o) => `${o.sku} ${o.nome ?? ''}`.toLowerCase().includes(t)) : ofertas).slice(0, 100);
    }, [ofertas, filtro]);

    const visitar = (metodo, url, dados = {}) => router[metodo](url, dados, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setOcupado(true),
        onFinish: () => setOcupado(false),
        onSuccess: recarregar,
    });

    return (
        <li className="rounded-xl border border-white/[0.08] p-3 space-y-2" data-espera={linha.id}>
            <div className="flex flex-wrap items-baseline gap-x-2 text-[13px]">
                <span className="font-mono text-white">{linha.sku_colado ?? 'sem SKU'}</span>
                <span className="text-white/60">{vocabulario.tipos[linha.tipo]}</span>
                {linha.codigo_mlb && <span className="font-mono text-white/45">{linha.codigo_mlb}</span>}
                <span className="text-[11.5px] text-amber-300/80">{linha.motivo_texto}</span>
            </div>
            {linha.titulo && <p className="text-[12px] text-white/45 truncate">{linha.titulo}</p>}
            <div className="flex flex-wrap gap-2">
                <input value={filtro} onChange={(e) => setFiltro(e.target.value)} placeholder="Procurar oferta…"
                    className={cn(CLASSE_INPUT, 'w-40 flex-1')} />
                <select value={escolhida} onChange={(e) => setEscolhida(e.target.value)} className={cn(CLASSE_INPUT, 'flex-1 [&>option]:bg-ecf-card')} aria-label="Oferta">
                    <option value="">Escolha a oferta…</option>
                    {candidatas.map((o) => <option key={o.id} value={o.id}>{o.sku}{o.nome ? ` — ${o.nome}` : ''}</option>)}
                </select>
                <Botao disabled={! escolhida || ocupado}
                    onClick={() => visitar('post', route('portal.auth.estrutura.espera.vincular', linha.id), { oferta_id: Number(escolhida) })}>
                    <Link2 size={14} /> Vincular
                </Botao>
            </div>
            <div className="flex gap-2">
                <Botao variante="fantasma" onClick={() => onCriarOferta(linha)}><Plus size={14} /> Criar oferta com este SKU</Botao>
                <Botao variante="fantasma" disabled={ocupado} className="text-red-300/80"
                    onClick={() => visitar('delete', route('portal.auth.estrutura.espera.descartar', linha.id))}>
                    <Trash2 size={14} /> Descartar
                </Botao>
            </div>
        </li>
    );
}

export default function EsperaAnuncios({ aberta, onFechar, linhas, ofertas, vocabulario, onCriarOferta }) {
    const recarregar = () => router.reload({ only: ['espera_linhas', 'opcoes_ofertas', 'estrutura'] });

    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-2xl"
            titulo="Anúncios colados que aguardam oferta"
            descricao="Estes anúncios já existem no Mercado Livre, mas o SKU colado não bateu com nenhuma oferta (ou bateu com mais de uma). Eles não entram no painel até terem oferta.">
            {linhas === undefined || ofertas === undefined
                ? <p className="text-[13px] text-white/40">Carregando…</p>
                : linhas.length === 0
                    ? <p className="text-[13px] text-white/50">Nada aguardando. Tudo o que foi colado encontrou oferta.</p>
                    : (
                        <ul className="space-y-2" data-lista-espera>
                            {linhas.map((l) => (
                                <Linha key={l.id} linha={l} ofertas={ofertas} vocabulario={vocabulario}
                                    onCriarOferta={onCriarOferta} recarregar={recarregar} />
                            ))}
                        </ul>
                    )}
        </Janela>
    );
}
