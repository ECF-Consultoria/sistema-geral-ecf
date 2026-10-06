import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { cn } from '@/lib/utils';

// ─── Importar planilha de Produtos (D-13/D-14) ──────────────────────────────
//
// Escolher o arquivo → prévia (nada é gravado) → confirmar. A prévia é só leitura:
// ao confirmar, o ARQUIVO é reenviado e o servidor refaz o plano (T-167-60) —
// nada que a prévia mostrou é mandado de volta como "o que gravar". A checagem de
// extensão e tamanho aqui só avisa cedo; quem valida de verdade é o servidor.

const GRUPOS = [
    { chave: 'novos',       rotulo: 'novos',                              cor: 'text-emerald-300', aberto: false },
    { chave: 'atualizados', rotulo: 'atualizados',                        cor: 'text-sky-300',     aberto: false },
    { chave: 'sem_mudanca', rotulo: 'sem mudança',                        cor: 'text-white/45',    aberto: false },
    { chave: 'erros',       rotulo: 'com erro — não serão gravados',      cor: 'text-red-300',     aberto: true },
];

const ORIENTACAO_ERRO = 'Confira se é o modelo .xlsx de Produtos (até 2 MB) e envie de novo.';

function ItemPrevia({ grupo, item }) {
    if (grupo === 'erros') {
        return (
            <li className="text-[12px]">
                <span className="text-white/40">linha {item.linha}:</span> <span className="text-red-300">{item.mensagem}</span>
            </li>
        );
    }

    return (
        <li className="flex flex-wrap gap-x-2 text-[12px] text-white/70">
            <span className="text-white/35">linha {item.linha}</span>
            <span className="font-mono">{item.codigo}</span>
            {item.nome && <span>{item.nome}</span>}
        </li>
    );
}

function Previa({ previa }) {
    const [abertos, setAbertos] = useState(() => Object.fromEntries(GRUPOS.map((g) => [g.chave, g.aberto])));
    const familias = previa.criar_listas?.familias ?? [];
    const ambientes = previa.criar_listas?.ambientes ?? [];

    return (
        <div className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-previa>
            <p className="text-[12px] text-white/45">Prévia — nada foi gravado ainda</p>
            {previa.erro_geral && (
                <p role="alert" className="text-[13px] text-red-300" data-erro-geral>
                    {previa.erro_geral} {ORIENTACAO_ERRO}
                </p>
            )}
            {! previa.erro_geral && GRUPOS.filter((g) => (previa.totais?.[g.chave] ?? 0) > 0).map((g) => (
                <div key={g.chave} data-grupo={g.chave}>
                    <button type="button" onClick={() => setAbertos((a) => ({ ...a, [g.chave]: ! a[g.chave] }))}
                        className="flex items-center gap-1.5 text-[13px]" aria-expanded={abertos[g.chave]}>
                        {abertos[g.chave] ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        <strong className={g.cor}>{previa.totais[g.chave]}</strong>
                        <span className="text-white/70">{g.rotulo}</span>
                    </button>
                    {abertos[g.chave] && (
                        <ul className="ml-5 mt-1 max-h-44 space-y-0.5 overflow-y-auto">
                            {(previa.grupos?.[g.chave] ?? []).slice(0, 200).map((item, i) => <ItemPrevia key={i} grupo={g.chave} item={item} />)}
                            {previa.totais[g.chave] > (previa.grupos?.[g.chave] ?? []).length && (
                                <li className="text-[12px] text-white/35">e mais {previa.totais[g.chave] - previa.grupos[g.chave].length}…</li>
                            )}
                        </ul>
                    )}
                </div>
            ))}
            {! previa.erro_geral && (familias.length > 0 || ambientes.length > 0) && (
                <p className="text-[12px] text-white/50" data-criar-listas>
                    Serão criadas nas listas:
                    {familias.length > 0 && <> Famílias: {familias.join(', ')}</>}
                    {familias.length > 0 && ambientes.length > 0 && ' · '}
                    {ambientes.length > 0 && <> Ambientes: {ambientes.join(', ')}</>}
                </p>
            )}
            {! previa.erro_geral && (previa.avisos ?? []).map((a, i) => (
                <p key={i} className="text-[12px] text-amber-300" data-aviso-previa>{a}</p>
            ))}
        </div>
    );
}

export default function JanelaImportacao({ aberta, onFechar, limites }) {
    const [arquivo, setArquivo] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [erro, setErro] = useState(null);
    const [lendo, setLendo] = useState(false);
    const [importando, setImportando] = useState(false);
    const [arrastando, setArrastando] = useState(false);
    const entrada = useRef(null);

    const mb = limites?.arquivo_mb ?? 2;
    const linhas = limites?.linhas_arquivo ?? 1000;

    useEffect(() => {
        if (aberta) { setArquivo(null); setPrevia(null); setErro(null); setLendo(false); setImportando(false); }
    }, [aberta]);

    const escolher = async (f) => {
        if (! f) return;
        setErro(null);
        setPrevia(null);
        if (! /\.xlsx$/i.test(f.name)) { setErro('Envie um arquivo .xlsx. Baixe o modelo se precisar.'); return; }
        if (f.size > mb * 1024 * 1024) { setErro(`O arquivo passa de ${mb} MB. Divida a planilha e importe em partes.`); return; }
        setArquivo(f);
        setLendo(true);
        const dados = new FormData();
        dados.append('arquivo', f);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.importacao.previa'), dados);
            setPrevia(data);
        } catch (e) {
            setArquivo(null);
            setErro(e.response?.data?.errors?.arquivo?.[0] ?? e.response?.data?.message ?? 'Não foi possível ler a planilha agora. Tente de novo.');
        } finally {
            setLendo(false);
        }
    };

    const voltar = () => { setArquivo(null); setPrevia(null); setErro(null); if (entrada.current) entrada.current.value = ''; };

    const podeConfirmar = !! previa && ! previa.erro_geral && ((previa.totais?.novos ?? 0) + (previa.totais?.atualizados ?? 0)) > 0;

    const confirmar = () => {
        if (! podeConfirmar || importando) return;
        setImportando(true);
        setErro(null);
        // Reenvia o arquivo: o servidor refaz o plano e grava (a prévia não é a fonte da gravação).
        router.post(route('portal.auth.estrutura.produtos.importacao'), { arquivo }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onFechar(),
            onError: (erros) => setErro(erros?.arquivo ?? 'Não foi possível importar agora. Tente de novo.'),
            onFinish: () => setImportando(false),
        });
    };

    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-3xl" titulo="Importar planilha">
            <div className="space-y-3" data-janela-importacao>
                {! previa && (
                    <>
                        <div
                            onDragOver={(e) => { e.preventDefault(); setArrastando(true); }}
                            onDragLeave={() => setArrastando(false)}
                            onDrop={(e) => { e.preventDefault(); setArrastando(false); escolher(e.dataTransfer.files?.[0]); }}
                            onClick={() => entrada.current?.click()}
                            className={cn('cursor-pointer rounded-xl border border-dashed border-white/[0.14] p-6 text-center', arrastando && 'border-ecf-yellow/40 bg-white/[0.04]')}
                            data-area-arquivo>
                            <p className="text-[13px] font-semibold text-white">{lendo ? 'Lendo a planilha…' : 'Arraste o arquivo ou clique para escolher'}</p>
                            <p className="mx-auto mt-2 max-w-md text-[12px] text-white/45">
                                Aceita .xlsx, até {mb} MB e {Number(linhas).toLocaleString('pt-BR')} linhas. Tem a planilha de Produtos do Planejamento? Pode importar como está.
                            </p>
                            <input ref={entrada} type="file" accept=".xlsx" className="sr-only" aria-label="Escolher planilha .xlsx"
                                onChange={(e) => escolher(e.target.files?.[0])} />
                        </div>
                        <a href={route('portal.auth.estrutura.produtos.modelo')} download className="inline-block text-[12px] text-ecf-yellow hover:underline">
                            Baixar o modelo
                        </a>
                    </>
                )}
                {erro && <p role="alert" className="text-[13px] text-red-300" data-erro-arquivo>{erro}</p>}
                {previa && <Previa previa={previa} />}
                <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <p className="text-[12px] text-white/45">Reimportar atualiza pelo código da variação. Nada é apagado.</p>
                    <div className="flex gap-2">
                        {previa && <Botao variante="fantasma" onClick={voltar} disabled={importando} data-acao="voltar-importacao">Voltar</Botao>}
                        <Botao variante="primario" onClick={confirmar} disabled={! podeConfirmar || importando} data-acao="confirmar-importacao">
                            {importando ? 'Importando…' : 'Confirmar importação'}
                        </Botao>
                    </div>
                </div>
            </div>
        </Janela>
    );
}
