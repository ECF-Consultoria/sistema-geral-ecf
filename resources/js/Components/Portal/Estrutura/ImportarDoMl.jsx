import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import Janela from './Janela';
import PreviaColagem from './PreviaColagem';
import { Botao } from './comum';

// ─── Importar do Mercado Livre ──────────────────────────────────────────────
//
// O passo 2 da aula sem colar nada: lê os anúncios da conta conectada pelo
// OAuth e mostra a MESMA prévia da colagem — casa pelo SKU, e o que não casa
// vai para "aguardando oferta". A leitura roda em segundo plano (uma conta
// grande são minutos de API); a tela pergunta o estado a cada 2 segundos.
// Nada é gravado até "Importar".

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
        onError: (e) => setErro(e.importacao ?? 'Não foi possível importar.'),
    });

    const pronto = estado?.estado === 'pronto';
    const temAlgo = pronto && (estado.previa.totais.novos + estado.previa.totais.atualizados + estado.previa.totais.espera) > 0;

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} largura="max-w-2xl"
            titulo="Importar do Mercado Livre"
            descricao="Traz os anúncios Clássico e Premium que você já tem no ML e liga cada um à oferta com o mesmo SKU. Os que não casarem ficam aguardando oferta — aí você procura e liga à mão.">
            <div className="space-y-3" data-importar-ml>
                {! conectado && (
                    <p className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-3 py-2 text-[13px] text-amber-200">
                        A conta do Mercado Livre desta empresa não está conectada. Conecte pelo Onboarding e volte aqui.
                    </p>
                )}

                {conectado && estado?.estado === 'lendo' && (
                    <p className="flex items-center gap-2 text-[13px] text-white/70" data-lendo>
                        <Loader2 size={15} className="animate-spin" /> Lendo seus anúncios no Mercado Livre… numa conta grande, isso leva alguns minutos. Pode fechar e voltar depois.
                    </p>
                )}

                {conectado && estado?.estado === 'erro' && <p className="text-[13px] text-red-400">{estado.erro}</p>}

                {pronto && (
                    <>
                        <p className="text-[12.5px] text-white/55">
                            {estado.total} anúncio(s) Clássico e Premium lidos{estado.ignorados > 0 ? ` · ${estado.ignorados} de outro tipo, ignorados` : ''}.
                        </p>
                        <PreviaColagem previa={estado.previa} vocabulario={vocabulario} />
                    </>
                )}

                {erro && <p className="text-[12.5px] text-red-400">{erro}</p>}

                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Fechar</Botao>
                    {conectado && ! pronto && (
                        <Botao variante="primario" onClick={ler} disabled={estado === null || estado?.estado === 'lendo'} data-acao="ler-ml">
                            Ler meus anúncios
                        </Botao>
                    )}
                    {pronto && <Botao onClick={ler} data-acao="ler-ml">Ler de novo</Botao>}
                    {pronto && (
                        <Botao variante="primario" onClick={importar} disabled={enviando || ! temAlgo} data-acao="importar-ml">
                            Importar
                        </Botao>
                    )}
                </div>
            </div>
        </Janela>
    );
}
