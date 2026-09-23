import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, ChevronRight } from 'lucide-react';
import Janela from './Janela';
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

const GRUPOS = [
    { chave: 'novos',       rotulo: 'novos',              cor: 'text-emerald-300' },
    { chave: 'atualizados', rotulo: 'já cadastrados (serão atualizados)', cor: 'text-sky-300' },
    { chave: 'espera',      rotulo: 'aguardando oferta',  cor: 'text-amber-300' },
    { chave: 'erros',       rotulo: 'com erro (não serão gravados)', cor: 'text-red-300' },
    { chave: 'removidos',   rotulo: 'serão REMOVIDOS',    cor: 'text-red-400' },
];

function LinhaPrevia({ grupo, item, vocabulario }) {
    if (grupo === 'erros') {
        return (
            <li className="text-[12px]">
                <span className="text-white/40">linha {item.numero}:</span> <span className="text-red-300">{item.motivo}</span>
                <div className="font-mono text-white/30 truncate">{item.texto}</div>
            </li>
        );
    }

    return (
        <li className="text-[12px] text-white/70 flex flex-wrap gap-x-2">
            {item.numero && <span className="text-white/35">l.{item.numero}</span>}
            <span className="font-mono">{item.sku ?? item.oferta_sku ?? '—'}</span>
            {item.oferta_sku && item.sku && item.oferta_sku !== item.sku && <span className="text-white/40">→ {item.oferta_sku}</span>}
            <span>{vocabulario.tipos[item.tipo]}</span>
            {item.codigo_mlb && <span className="font-mono text-white/45">{item.codigo_mlb}</span>}
            {item.motivo_texto && <span className="text-amber-300/80">({item.motivo_texto})</span>}
            {item.mudou_de_oferta && <span className="text-sky-300/80">(muda de oferta)</span>}
        </li>
    );
}

export default function ColarAnuncios({ aberta, onFechar, vocabulario }) {
    const [texto, setTexto] = useState('');
    const [modo, setModo] = useState('acrescentar');
    const [previa, setPrevia] = useState(null);
    const [abertos, setAbertos] = useState({});
    const [entendi, setEntendi] = useState(false);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(null);

    useEffect(() => {
        if (aberta) {
            setTexto(''); setModo('acrescentar'); setPrevia(null); setAbertos({}); setEntendi(false); setErro(null);
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
            setAbertos({ espera: true, erros: true, removidos: true });
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
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3 space-y-2" data-previa>
                        <p className="text-[11.5px] uppercase tracking-wide text-white/35">Prévia — nada foi gravado ainda</p>
                        {previa.erro_geral && <p className="text-[13px] text-red-400">{previa.erro_geral}</p>}
                        {! previa.erro_geral && GRUPOS.filter((g) => (previa.totais[g.chave] ?? 0) > 0).map((g) => (
                            <div key={g.chave} data-grupo={g.chave}>
                                <button type="button" onClick={() => setAbertos((a) => ({ ...a, [g.chave]: ! a[g.chave] }))}
                                    className="flex items-center gap-1.5 text-[13px]">
                                    {abertos[g.chave] ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                                    <strong className={g.cor}>{previa.totais[g.chave]}</strong>
                                    <span className="text-white/70">{g.rotulo}</span>
                                </button>
                                {abertos[g.chave] && (
                                    <ul className="mt-1 ml-5 space-y-0.5 max-h-44 overflow-y-auto">
                                        {previa.grupos[g.chave].map((item, i) => <LinhaPrevia key={i} grupo={g.chave} item={item} vocabulario={vocabulario} />)}
                                        {previa.totais[g.chave] > previa.grupos[g.chave].length && (
                                            <li className="text-[11.5px] text-white/35">e mais {previa.totais[g.chave] - previa.grupos[g.chave].length}…</li>
                                        )}
                                    </ul>
                                )}
                            </div>
                        ))}
                        {! previa.erro_geral && previa.totais.espera > 0 && (
                            <p className="text-[12px] text-white/45">
                                Os que aguardam oferta ficam guardados e aparecem no aviso da tela. Assim que você criar a oferta com aquele SKU, eles entram nela sozinhos.
                            </p>
                        )}
                        {removidos > 0 && (
                            <label className="flex items-start gap-2 text-[12.5px] text-red-300">
                                <input type="checkbox" checked={entendi} onChange={(e) => setEntendi(e.target.checked)} className="mt-0.5" />
                                <span><AlertTriangle size={13} className="inline -mt-0.5" /> Entendi que {removidos} anúncio(s) serão removidos.</span>
                            </label>
                        )}
                    </div>
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
