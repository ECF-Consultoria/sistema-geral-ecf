import { useRef, useState } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import { cn } from '@/lib/utils';

// ─── Ficha técnica pela planilha (09/10/2026) ───────────────────────────────
//
// O 2º arquivo, depois que as categorias estão confirmadas: baixar a planilha
// (uma aba por categoria, já com o que cada produto tem), preencher fora e
// enviar. A prévia só lê; ao confirmar, o ARQUIVO vai de novo e o servidor
// refaz tudo. Grava mesclando: célula em branco não apaga, o que está fora das
// opções não entra e o obrigatório que falta aparece na lista.

const LIMITE_LISTA = 200;

const plural = (n, um, varios) => (Number(n) === 1 ? `1 ${um}` : `${Number(n) || 0} ${varios}`);

function Resumo({ totais, gravado = false }) {
    return (
        <p className="text-[13px] text-white/80" data-resumo-ficha>
            <strong className="text-emerald-300">{plural(totais.campos, 'campo', 'campos')}</strong> {gravado ? 'gravados' : 'para gravar'} em{' '}
            <strong className="text-white">{plural(totais.produtos, 'produto', 'produtos')}</strong>.
            {totais.com_falta > 0 && <> {plural(totais.com_falta, 'produto ainda tem', 'produtos ainda têm')} campo obrigatório a preencher.</>}
            {totais.nao_encontrados > 0 && <> {plural(totais.nao_encontrados, 'linha não achou', 'linhas não acharam')} o produto.</>}
        </p>
    );
}

function ListaDosProdutos({ produtos }) {
    const comAlgo = (produtos ?? []).filter((p) => p.campos > 0 || p.erros.length > 0 || p.faltam.length > 0);
    if (comAlgo.length === 0) return null;

    return (
        <ul className="max-h-64 space-y-1.5 overflow-y-auto pr-1" data-produtos-ficha>
            {comAlgo.slice(0, LIMITE_LISTA).map((p) => (
                <li key={`${p.aba}-${p.linha}`} className="rounded-lg border border-white/[0.06] bg-white/[0.015] px-3 py-2 text-[12.5px]">
                    <p className="text-white/85">
                        <span className="font-mono">{p.grupo}</span>{p.nome ? ` · ${p.nome}` : ''}
                        <span className="text-white/40"> · {p.aba}, linha {p.linha}</span>
                        {p.campos > 0 && <span className="text-emerald-300"> · {plural(p.campos, 'campo', 'campos')}</span>}
                    </p>
                    {p.erros.map((e, i) => <p key={`e${i}`} className="text-[12px] text-red-300">{e}</p>)}
                    {p.faltam.length > 0 && <p className="text-[12px] text-amber-300">Falta preencher: {p.faltam.join(', ')}.</p>}
                </li>
            ))}
        </ul>
    );
}

export default function ImportacaoDaFichaTecnica({ onConcluir }) {
    const [arquivo, setArquivo] = useState(null);
    const [previa, setPrevia] = useState(null);
    const [resultado, setResultado] = useState(null);
    const [erro, setErro] = useState(null);
    const [lendo, setLendo] = useState(false);
    const [gravando, setGravando] = useState(false);
    const [arrastando, setArrastando] = useState(false);
    const entrada = useRef(null);

    const limparEntrada = () => { if (entrada.current) entrada.current.value = ''; };
    const mensagemDoErro = (e) => e.response?.data?.errors?.arquivo?.[0] ?? e.response?.data?.message ?? 'Não foi possível ler a planilha agora. Tente de novo.';

    const escolher = async (f) => {
        if (! f) return;
        setErro(null);
        setPrevia(null);
        setResultado(null);
        limparEntrada();
        if (! /\.xlsx$/i.test(f.name)) { setErro('Envie um arquivo .xlsx: a planilha da ficha técnica baixada aqui.'); return; }
        setArquivo(f);
        setLendo(true);
        const dados = new FormData();
        dados.append('arquivo', f);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.fichas.previa'), dados);
            if (data.erro_geral) { setErro(data.erro_geral); setArquivo(null); } else setPrevia(data);
        } catch (e) {
            setArquivo(null);
            setErro(mensagemDoErro(e));
        } finally {
            setLendo(false);
        }
    };

    const voltar = () => { setArquivo(null); setPrevia(null); setResultado(null); setErro(null); limparEntrada(); };

    const podeGravar = !! previa && (previa.totais?.campos ?? 0) > 0 && ! gravando;

    const gravar = async () => {
        if (! podeGravar) return;
        setGravando(true);
        setErro(null);
        // Reenvia o ARQUIVO: o servidor refaz o plano e grava (a prévia não é a fonte da gravação).
        const dados = new FormData();
        dados.append('arquivo', arquivo);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.fichas.importacao'), dados);
            setResultado(data);
            setPrevia(null);
            if ((data.campos ?? 0) > 0) onConcluir?.();
        } catch (e) {
            setErro(mensagemDoErro(e));
        } finally {
            setGravando(false);
        }
    };

    return (
        <div className="space-y-3" data-importacao-ficha>
            {! previa && ! resultado && (
                <>
                    <p className="text-[12.5px] text-white/55">
                        Depois de confirmar as categorias, baixe a planilha da ficha técnica: uma aba por categoria, com os campos dela e o que cada produto já tem. Preencha e envie aqui.
                    </p>
                    <a href={route('portal.auth.estrutura.produtos.fichas.modelo')} download className="inline-block text-[12px] text-ecf-yellow hover:underline" data-acao="baixar-planilha-ficha">
                        Baixar a planilha da ficha técnica
                    </a>
                    <div
                        onDragOver={(e) => { e.preventDefault(); setArrastando(true); }}
                        onDragLeave={() => setArrastando(false)}
                        onDrop={(e) => { e.preventDefault(); setArrastando(false); escolher(e.dataTransfer.files?.[0]); }}
                        onClick={() => entrada.current?.click()}
                        className={cn('cursor-pointer rounded-xl border border-dashed border-white/[0.14] p-6 text-center', arrastando && 'border-ecf-yellow/40 bg-white/[0.04]')}
                        data-area-arquivo-ficha>
                        <p className="text-[13px] font-semibold text-white">
                            {lendo ? <span className="inline-flex items-center gap-2"><Loader2 size={14} className="animate-spin" /> Lendo a planilha…</span> : 'Arraste a planilha da ficha técnica ou clique para escolher'}
                        </p>
                        <input ref={entrada} type="file" accept=".xlsx" className="sr-only" aria-label="Escolher a planilha da ficha técnica"
                            onChange={(e) => escolher(e.target.files?.[0])} />
                    </div>
                </>
            )}

            {erro && <p role="alert" className="text-[13px] text-red-300" data-erro-ficha>{erro}</p>}

            {previa && (
                <div className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-previa-ficha>
                    <p className="text-[12px] text-white/45">Prévia — nada foi gravado ainda</p>
                    <Resumo totais={previa.totais} />
                    <ListaDosProdutos produtos={previa.produtos} />
                    {(previa.avisos ?? []).map((a, i) => <p key={i} className="text-[12px] text-amber-300" data-aviso-ficha>{a}</p>)}
                    <p className="text-[12px] text-white/40">Célula em branco não apaga nada do que já está salvo.</p>
                </div>
            )}

            {resultado && (
                <div className="space-y-2 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3" data-resultado-ficha>
                    <Resumo totais={resultado} gravado />
                    {(resultado.nao_entraram ?? []).length > 0 && (
                        <ul className="max-h-40 space-y-0.5 overflow-y-auto text-[12px] text-red-300">
                            {resultado.nao_entraram.slice(0, LIMITE_LISTA).map((t, i) => <li key={i}>{t}</li>)}
                        </ul>
                    )}
                </div>
            )}

            <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
                {(previa || resultado) && <Botao variante="fantasma" onClick={voltar} disabled={gravando} data-acao="voltar-ficha">{resultado ? 'Enviar outra' : 'Voltar'}</Botao>}
                {previa && (
                    <Botao variante="primario" onClick={gravar} disabled={! podeGravar} data-acao="confirmar-ficha">
                        {gravando ? 'Gravando…' : 'Gravar a ficha técnica'}
                    </Botao>
                )}
            </div>
        </div>
    );
}
