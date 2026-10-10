import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { ACEITA_NO_INPUT, avisosDoErro, montarEnvio } from '@/lib/imagensVariacao';
import { enviarEmRemessas, filaDeEnvio, remessas, resumoDoEnvio, semRepetidos, textoDoResumo } from '@/lib/fotosEmLote';
import { cn } from '@/lib/utils';

// ─── Fotos em lote pelo nome do arquivo (09/10/2026) ────────────────────────
//
// A pessoa escolhe as fotos de uma vez, cada uma com o nome Ref_número
// (MESA-01_1.jpg, MESA-01_2.jpg…). Primeiro só os NOMES vão ao servidor, que
// devolve o plano: em que variação cada foto entra, em que ordem, e o que fica
// de fora. Depois as fotos sobem em remessas pequenas; o servidor confere tudo
// de novo e responde foto por foto. Nada aqui decide a variação pelo nome.

const LIMITE_LISTA = 300;

function Plano({ previa }) {
    const variacoes = previa.variacoes ?? [];
    const fora = previa.fora ?? [];

    return (
        <div className="space-y-3" data-plano-fotos>
            <p className="text-[13px] text-white/80">
                <strong className="text-emerald-300">{previa.entram}</strong> {previa.entram === 1 ? 'foto entra' : 'fotos entram'} em{' '}
                <strong className="text-white">{variacoes.filter((v) => v.arquivos.some((a) => a.entra)).length}</strong> variações.
                {fora.length > 0 && <> <strong className="text-red-300">{fora.length}</strong> {fora.length === 1 ? 'fica' : 'ficam'} de fora.</>}
            </p>
            {variacoes.length > 0 && (
                <ul className="max-h-64 space-y-1.5 overflow-y-auto pr-1">
                    {variacoes.slice(0, LIMITE_LISTA).map((v) => (
                        <li key={v.variacao_id} className="rounded-lg border border-white/[0.06] bg-white/[0.015] px-3 py-2 text-[12.5px]" data-variacao-fotos={v.ref}>
                            <p className="text-white/85">
                                <span className="font-mono">{v.ref}</span> · {v.produto}{v.valor ? ` — ${v.valor}` : ''}
                                <span className="text-white/40"> · já tem {v.imagens}</span>
                            </p>
                            <p className="mt-0.5 flex flex-wrap gap-x-2 text-[12px]">
                                {v.arquivos.map((a) => (
                                    <span key={a.nome} className={a.entra ? 'text-white/55' : 'text-red-300'} title={a.motivo ?? undefined}>
                                        {a.nome}{a.entra ? '' : ' (não coube)'}
                                    </span>
                                ))}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            {fora.length > 0 && (
                <div className="rounded-lg border border-red-500/20 bg-red-500/[0.04] px-3 py-2" data-fotos-fora>
                    <p className="text-[12.5px] font-semibold text-red-300">Ficam de fora</p>
                    <ul className="mt-1 max-h-32 space-y-0.5 overflow-y-auto text-[12px]">
                        {fora.slice(0, LIMITE_LISTA).map((f, i) => (
                            <li key={`${f.nome}-${i}`}><span className="text-white/70">{f.nome}</span> <span className="text-white/45">— {f.motivo}</span></li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export default function JanelaFotosEmLote({ aberta, onFechar, onConcluir }) {
    const [arquivos, setArquivos] = useState([]);
    const [previa, setPrevia] = useState(null);
    const [etapa, setEtapa] = useState('escolher');   // escolher | lendo | plano | enviando | fim
    const [progresso, setProgresso] = useState({ feitas: 0, total: 0, esperando: false });
    const [resultado, setResultado] = useState(null);
    const [erro, setErro] = useState(null);
    const [arrastando, setArrastando] = useState(false);
    const entrada = useRef(null);
    // Cada abertura é uma rodada: o que uma rodada velha responder não entra na tela. "Parar" só
    // encerra o laço depois da remessa em curso (o que ela já mandou fica gravado).
    const rodada = useRef(0);
    const parado = useRef(false);

    useEffect(() => {
        rodada.current += 1;
        parado.current = false;
        if (aberta) { setArquivos([]); setPrevia(null); setEtapa('escolher'); setResultado(null); setErro(null); }
    }, [aberta]);

    const limparEntrada = () => { if (entrada.current) entrada.current.value = ''; };

    const escolher = async (lista) => {
        const { unicos } = semRepetidos(lista);
        limparEntrada();
        if (unicos.length === 0) return;
        setErro(null);
        setArquivos(unicos);
        setEtapa('lendo');
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.fotos.previa'), { nomes: unicos.map((a) => a.name) });
            setPrevia(data);
            setEtapa('plano');
        } catch (e) {
            setErro(avisosDoErro(e)[0]);
            setEtapa('escolher');
        }
    };

    const voltar = () => { setArquivos([]); setPrevia(null); setErro(null); setEtapa('escolher'); };

    const enviar = async () => {
        const minha = rodada.current;
        const fila = filaDeEnvio(previa, arquivos);
        if (fila.length === 0) return;
        parado.current = false;
        setEtapa('enviando');
        setProgresso({ feitas: 0, total: fila.length, esperando: false });
        const fim = await enviarEmRemessas(remessas(fila), {
            enviar: async (remessa) => (await axios.post(route('portal.auth.estrutura.produtos.fotos.enviar'), montarEnvio(remessa), { headers: { Accept: 'application/json' } })).data,
            avisoDoErro: (e) => avisosDoErro(e)[0],
            aoProgresso: (p) => { if (rodada.current === minha) setProgresso(p); },
            vivo: () => rodada.current === minha && ! parado.current,
        });
        if (rodada.current !== minha) return;
        // O que a prévia já deixou de fora (Ref não achada, formato, não coube) entra no resumo do fim.
        const naoCouberam = (previa.variacoes ?? []).flatMap((v) => (v.arquivos ?? []).filter((a) => ! a.entra).map((a) => ({ nome: a.nome, motivo: a.motivo, situacao: 'fora', ref: v.ref })));
        const resumo = resumoDoEnvio([...fim.resultados, ...naoCouberam, ...(previa.fora ?? []).map((f) => ({ ...f, situacao: 'fora' }))]);
        setResultado({ ...resumo, parou: fim.parou });
        setEtapa('fim');
        if (resumo.enviadas > 0) onConcluir?.();
    };

    const [parando, setParando] = useState(false);
    const parar = () => { parado.current = true; setParando(true); };
    useEffect(() => { if (etapa !== 'enviando') setParando(false); }, [etapa]);

    // Durante o envio a janela não fecha por fora (Esc, clique no fundo): use "Parar".
    const fechar = () => { if (etapa !== 'enviando') onFechar(); };
    const entram = previa?.entram ?? 0;

    return (
        <Janela aberta={aberta} onFechar={fechar} largura="max-w-3xl" titulo="Enviar fotos em lote">
            <div className="space-y-3" data-janela-fotos-em-lote>
                {(etapa === 'escolher' || etapa === 'lendo') && (
                    <>
                        <div
                            onDragOver={(e) => { e.preventDefault(); setArrastando(true); }}
                            onDragLeave={() => setArrastando(false)}
                            onDrop={(e) => { e.preventDefault(); setArrastando(false); escolher(e.dataTransfer.files); }}
                            onClick={() => entrada.current?.click()}
                            className={cn('cursor-pointer rounded-xl border border-dashed border-white/[0.14] p-6 text-center', arrastando && 'border-ecf-yellow/40 bg-white/[0.04]')}
                            data-area-fotos>
                            <p className="text-[13px] font-semibold text-white">
                                {etapa === 'lendo' ? <span className="inline-flex items-center gap-2"><Loader2 size={14} className="animate-spin" /> Lendo os nomes…</span> : 'Arraste as fotos ou clique para escolher'}
                            </p>
                            <p className="mx-auto mt-2 max-w-md text-[12px] text-white/45">
                                Dê a cada foto o nome da Ref da variação e o número dela: MESA-01_1.jpg, MESA-01_2.jpg… A foto 1 vira a capa da variação que ainda não tem foto. JPG, PNG ou WebP; até 12 fotos por variação.
                            </p>
                            <input ref={entrada} type="file" multiple accept={ACEITA_NO_INPUT} className="sr-only" aria-label="Escolher fotos"
                                onChange={(e) => escolher(e.target.files)} />
                        </div>
                    </>
                )}

                {erro && <p role="alert" className="text-[13px] text-red-300" data-erro-fotos>{erro}</p>}

                {etapa === 'plano' && previa && <Plano previa={previa} />}

                {etapa === 'enviando' && (
                    <div className="space-y-2" role="status" data-enviando-fotos>
                        <p className="flex items-center gap-2 text-[13px] text-white/80">
                            <Loader2 size={14} className="animate-spin" />
                            {progresso.esperando ? 'Aguardando um instante para continuar…' : `Enviando ${progresso.feitas} de ${progresso.total}…`}
                        </p>
                        <div className="h-1.5 overflow-hidden rounded-full bg-white/[0.06]">
                            <div className="h-full bg-ecf-yellow transition-all" style={{ width: `${progresso.total ? Math.round((progresso.feitas / progresso.total) * 100) : 0}%` }} />
                        </div>
                    </div>
                )}

                {etapa === 'fim' && resultado && (
                    <div className="space-y-2" data-fim-fotos>
                        <p className="text-[13px] text-white/85">{textoDoResumo(resultado)}{resultado.parou ? ' O envio foi interrompido.' : ''}</p>
                        {resultado.fora.length > 0 && (
                            <ul className="max-h-40 space-y-0.5 overflow-y-auto text-[12px]">
                                {resultado.fora.slice(0, LIMITE_LISTA).map((f, i) => (
                                    <li key={`${f.nome}-${i}`}><span className="text-white/70">{f.nome}</span> <span className="text-white/45">— {f.motivo}</span></li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
                    {etapa === 'plano' && <Botao variante="fantasma" onClick={voltar} data-acao="voltar-fotos">Voltar</Botao>}
                    {etapa === 'plano' && (
                        <Botao variante="primario" onClick={enviar} disabled={entram === 0} data-acao="enviar-fotos">
                            {entram === 1 ? 'Enviar 1 foto' : `Enviar ${entram} fotos`}
                        </Botao>
                    )}
                    {etapa === 'enviando' && (
                        <Botao variante="secundario" onClick={parar} disabled={parando} data-acao="parar-fotos">{parando ? 'Parando…' : 'Parar'}</Botao>
                    )}
                    {etapa === 'fim' && <Botao variante="primario" onClick={onFechar} data-acao="fechar-fotos">Fechar</Botao>}
                </div>
            </div>
        </Janela>
    );
}
