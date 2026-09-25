import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import Janela from './Janela';
import PreviaColagem from './PreviaColagem';
import { Botao } from './comum';
import { cn } from '@/lib/utils';

// ─── Puxar do Mercado Livre ─────────────────────────────────────────────────
//
// O cliente não digita a lista de produtos: ela sai dos anúncios. Lê os mais
// vendidos que ainda não estão aqui, cria uma oferta por SKU e junta nela os
// anúncios Clássico e Premium com aquele SKU. Conta grande vem em lotes — o
// próximo "Puxar" continua de onde o anterior parou. A leitura roda em
// segundo plano; a tela pergunta o estado a cada 2 segundos. Nada é gravado
// até "Criar ofertas".

function Par({ classicos, premiums }) {
    const completo = classicos > 0 && premiums > 0;

    return (
        <span className={cn('whitespace-nowrap', completo ? 'text-emerald-300' : 'text-amber-300')}>
            {classicos} Clássico · {premiums} Premium
        </span>
    );
}

function OfertasNovas({ ofertas }) {
    const [aberta, setAberta] = useState(true);

    if (ofertas.total === 0) return null;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 space-y-2" data-ofertas-novas>
            <button type="button" onClick={() => setAberta((a) => ! a)} className="flex items-center gap-1.5 text-[13px]">
                {aberta ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                <strong className="text-emerald-300">{ofertas.total}</strong>
                <span className="text-white/70">oferta(s) nova(s)</span>
                <span className="text-white/45">· {ofertas.pares} com Clássico e Premium</span>
            </button>
            {aberta && (
                <ul className="ml-5 space-y-1 max-h-60 overflow-y-auto">
                    {ofertas.itens.map((o) => (
                        <li key={o.sku} className="text-[12px] flex flex-wrap gap-x-2 text-white/70" data-oferta-nova={o.sku}>
                            <span className="font-mono text-white/85">{o.sku}</span>
                            <span className="truncate max-w-[22rem]">{o.nome}</span>
                            {o.logistica_rotulo && <span className="text-white/40">{o.logistica_rotulo}</span>}
                            <Par classicos={o.classicos} premiums={o.premiums} />
                        </li>
                    ))}
                    {ofertas.total > ofertas.itens.length && (
                        <li className="text-[11.5px] text-white/35">e mais {ofertas.total - ofertas.itens.length}…</li>
                    )}
                </ul>
            )}
            <p className="text-[12px] text-white/45">
                Todas entram como Fase 1 (Simples). Combo e kit você ajusta depois, na oferta.
            </p>
        </div>
    );
}

function Progresso({ estado }) {
    return (
        <p className="flex items-center gap-2 text-[13px] text-white/70" data-lendo>
            <Loader2 size={15} className="animate-spin shrink-0" />
            {{
                fila: 'Aguardando a vez para ler seus anúncios…',
                procurar: `Lendo seus anúncios mais vendidos… ${estado.lidos} lidos, ${estado.skus} SKU(s) encontrados.`,
                irmaos: `Juntando os anúncios Clássico e Premium de cada SKU… ${estado.procurados} de ${estado.skus}.`,
            }[estado.etapa]}
            {' '}Pode fechar e voltar depois.
        </p>
    );
}

export default function ImportarDoMl({ aberta, onFechar, conectado, vocabulario }) {
    const [estado, setEstado] = useState(null);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);
    const relogio = useRef(null);

    const parar = () => { clearInterval(relogio.current); relogio.current = null; };

    const consultar = async () => {
        const { data } = await axios.get(route('portal.auth.estrutura.importacao.estado'));
        setEstado(data);
        if (data.estado !== 'lendo') parar();

        return data;
    };

    const acompanhar = () => {
        parar();
        relogio.current = setInterval(() => consultar().catch(() => {}), 2000);
    };

    useEffect(() => {
        if (! aberta) { parar(); return undefined; }
        setErro(null);
        setEstado(null);
        if (conectado) {
            consultar().then((d) => d.estado === 'lendo' && acompanhar()).catch(() => setEstado({ estado: 'nenhum' }));
        }

        return parar;
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const ler = async () => {
        setErro(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.importacao.iniciar'));
            setEstado(data);
            if (data.estado === 'lendo') acompanhar();
        } catch (e) {
            setErro(e.response?.data?.errors?.importacao?.[0] ?? 'Não foi possível começar a leitura.');
        }
    };

    const importar = () => router.post(route('portal.auth.estrutura.importacao.aplicar'), {}, {
        preserveScroll: true,
        onStart: () => setEnviando(true),
        onFinish: () => setEnviando(false),
        onSuccess: () => onFechar(true),
        onError: (e) => setErro(e.importacao ?? 'Não foi possível gravar.'),
    });

    const pronto = estado?.estado === 'pronto';
    const temAlgo = pronto && (estado.ofertas_novas.total + estado.previa.totais.novos + estado.previa.totais.atualizados + estado.previa.totais.espera) > 0;

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} largura="max-w-2xl"
            titulo="Puxar do Mercado Livre"
            descricao="Lê seus anúncios mais vendidos, cria uma oferta para cada SKU e junta nela os anúncios Clássico e Premium com esse SKU. Nada é gravado antes de você conferir.">
            <div className="space-y-3" data-importar-ml>
                {! conectado && (
                    <p className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-3 py-2 text-[13px] text-amber-200">
                        A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding e volte aqui.
                    </p>
                )}

                {conectado && estado?.estado === 'lendo' && <Progresso estado={estado} />}

                {conectado && estado?.estado === 'erro' && <p className="text-[13px] text-red-400">{estado.erro}</p>}

                {pronto && (
                    <>
                        <p className="text-[12.5px] text-white/55" data-resumo-importacao>
                            {estado.lidos} anúncio(s) lido(s) · {estado.skus} SKU(s) · {estado.total} anúncio(s) Clássico e Premium
                            {estado.sem_sku > 0 ? ` · ${estado.sem_sku} sem SKU (ficam aguardando oferta)` : ''}
                            {estado.sem_anuncio > 0 ? ` · ${estado.sem_anuncio} oferta(s) sua(s) sem nenhum anúncio com o mesmo SKU` : ''}.
                        </p>
                        {estado.acabou
                            ? <p className="text-[12.5px] text-emerald-300/80" data-acabou>Não há mais anúncios seus fora do Mapeamento.</p>
                            : <p className="text-[12.5px] text-white/45">Depois de criar, clique em “Puxar do Mercado Livre” de novo para trazer os próximos {estado.limite} SKUs.</p>}
                        <OfertasNovas ofertas={estado.ofertas_novas} />
                        <PreviaColagem previa={estado.previa} vocabulario={vocabulario} />
                    </>
                )}

                {erro && <p className="text-[12.5px] text-red-400">{erro}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Fechar</Botao>
                    {conectado && ! pronto && (
                        <Botao variante="primario" onClick={ler} disabled={estado === null || estado?.estado === 'lendo'} data-acao="ler-ml">
                            {estado?.estado === 'erro' ? 'Puxar de novo' : 'Puxar meus anúncios'}
                        </Botao>
                    )}
                    {pronto && <Botao onClick={ler} data-acao="ler-ml">Ler de novo</Botao>}
                    {pronto && (
                        <Botao variante="primario" onClick={importar} disabled={enviando || ! temAlgo} data-acao="importar-ml">
                            {estado.ofertas_novas.total > 0 ? 'Criar ofertas' : 'Gravar anúncios'}
                        </Botao>
                    )}
                </div>
            </div>
        </Janela>
    );
}
