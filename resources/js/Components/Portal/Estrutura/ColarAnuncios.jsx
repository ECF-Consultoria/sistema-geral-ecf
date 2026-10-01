import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import Janela from './Janela';
import PreviaColagem from './PreviaColagem';
import { Botao, CLASSE_INPUT } from './comum';
import { cn } from '@/lib/utils';

// ─── Colar anúncios ─────────────────────────────────────────────────────────
//
// O passo 2 da aula: "Cole aqui o SKU e o tipo (Clássico/Premium) de cada
// anúncio que você já tem no Mercado Livre."
//
// Duas etapas. A PRÉVIA não grava nada (é JSON). O CONFIRMAR manda o mesmo
// texto de novo e o servidor refaz o plano antes de gravar — o navegador nunca
// diz o que gravar, só o que foi colado.
//
// "Substituir todos" remove o que não está na colagem. A prévia lista quem sai,
// e o botão só destrava depois que a pessoa marca que entendeu.

export default function ColarAnuncios({ aberta, onFechar, vocabulario }) {
    const [texto, setTexto] = useState('');
    const [modo, setModo] = useState('acrescentar');
    const [previa, setPrevia] = useState(null);
    const [entendi, setEntendi] = useState(false);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(null);

    useEffect(() => {
        if (aberta) {
            setTexto(''); setModo('acrescentar'); setPrevia(null); setEntendi(false); setErro(null);
        }
    }, [aberta]);

    // Mudar o texto ou o modo invalida a prévia: confirmar algo diferente do
    // que se viu é exatamente o erro que as duas etapas existem para evitar.
    useEffect(() => { setPrevia(null); setEntendi(false); }, [texto, modo]);

    const preVisualizar = async () => {
        setCarregando(true); setErro(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.colagem.previa'), { texto, modo });
            setPrevia(data);
        } catch (e) {
            setErro(e.response?.data?.message ?? 'Não foi possível ler o que foi colado.');
        } finally {
            setCarregando(false);
        }
    };

    const confirmar = () => {
        router.post(route('portal.auth.estrutura.colagem'), { texto, modo }, {
            preserveScroll: true,
            onStart: () => setCarregando(true),
            onFinish: () => setCarregando(false),
            onSuccess: () => onFechar(true),
            onError: (e) => setErro(e.texto ?? 'Não foi possível gravar.'),
        });
    };

    const removidos = previa?.totais?.removidos ?? 0;
    const podeConfirmar = previa && ! previa.erro_geral && (removidos === 0 || entendi)
        && (previa.totais.novos + previa.totais.atualizados + previa.totais.espera + removidos) > 0;

    return (
        <Janela aberta={aberta} onFechar={() => onFechar(false)} largura="max-w-2xl"
            titulo="Colar anúncios que você já tem"
            descricao="Copie do Excel ou do Sheets (colunas separadas por tabulação), com cabeçalho ou na ordem SKU · Código MLB · Título · Tipo · Catálogo? · Status. O mínimo é SKU e tipo.">
            <div className="space-y-3" data-colar>
                <textarea rows={8} value={texto} onChange={(e) => setTexto(e.target.value)} spellCheck={false}
                    placeholder={'SKU\tCÓDIGO MLB\tTÍTULO\tTIPO\tCATÁLOGO?\tSTATUS\nCAD-01\tMLB1234567890\tCadeira de Jantar…\tClássico\tNão\tAtivo'}
                    className={cn(CLASSE_INPUT, 'font-mono text-[12px] whitespace-pre')} data-campo="texto" />

                <div className="flex flex-wrap gap-4 text-[13px] text-white/75">
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" name="modo" checked={modo === 'acrescentar'} onChange={() => setModo('acrescentar')} />
                        Acrescentar / atualizar
                    </label>
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" name="modo" checked={modo === 'substituir'} onChange={() => setModo('substituir')} />
                        Substituir todos
                    </label>
                </div>
                {modo === 'substituir' && (
                    <p className="text-[12px] text-amber-300/80">
                        Anúncios que não estiverem nesta colagem serão removidos — inclusive os colados que aguardam oferta.
                    </p>
                )}

                {erro && <p className="text-[12.5px] text-red-400">{erro}</p>}

                {previa && (
                    <PreviaColagem previa={previa} vocabulario={vocabulario}>
                        {removidos > 0 && (
                            <label className="flex items-start gap-2 text-[12.5px] text-red-300">
                                <input type="checkbox" checked={entendi} onChange={(e) => setEntendi(e.target.checked)} className="mt-0.5" />
                                <span><AlertTriangle size={13} className="inline -mt-0.5" /> Entendi que {removidos} anúncio(s) serão removidos.</span>
                            </label>
                        )}
                    </PreviaColagem>
                )}

                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                    {! previa
                        ? <Botao variante="primario" onClick={preVisualizar} disabled={carregando || ! texto.trim()} data-acao="previa">Pré-visualizar</Botao>
                        : <Botao variante="primario" onClick={confirmar} disabled={carregando || ! podeConfirmar} data-acao="confirmar-colagem">Confirmar</Botao>}
                </div>
            </div>
        </Janela>
    );
}
