import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Janela from './Janela';
import { Botao, CLASSE_INPUT, Campo, Seletor } from './comum';

// ─── Cadastrar / editar anúncio ─────────────────────────────────────────────
//
// O MESMO formulário serve a gaveta da oferta e o "Concluir" da agenda —
// porque concluir uma publicação É cadastrar o anúncio (ADR PORTAL-01: um
// registro só). A única diferença é `viaAgenda`: aí o tipo já vem escolhido e
// o código MLB é obrigatório, porque quem acabou de publicar tem o código na
// tela, e é ali que nasceria o registro que ninguém reconhece depois.

export default function FormAnuncio({ aberta, onFechar, oferta, anuncio = null, tipoFixo = null, viaAgenda = false, vocabulario }) {
    const [dados, setDados] = useState({});
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        if (! aberta) return;
        setErros({});
        setDados(anuncio
            ? { tipo: anuncio.tipo, codigo_mlb: anuncio.codigo_mlb ?? '', titulo: anuncio.titulo ?? '', status: anuncio.status, catalogo: anuncio.catalogo, kit_virtual: anuncio.kit_virtual }
            : { tipo: tipoFixo ?? 'classico', codigo_mlb: '', titulo: '', status: 'ativo', catalogo: false, kit_virtual: false });
    }, [aberta]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (k, v) => setDados((d) => ({ ...d, [k]: v }));

    const enviar = () => {
        const opcoes = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onSuccess: () => onFechar(true),
            onError: setErros,
        };

        if (anuncio) {
            router.put(route('portal.auth.estrutura.anuncios.atualizar', anuncio.id), dados, opcoes);
        } else {
            router.post(route('portal.auth.estrutura.anuncios.criar', oferta.id), { ...dados, via_agenda: viaAgenda }, opcoes);
        }
    };

    const tipoRotulo = vocabulario.tipos[dados.tipo];

    return (
        <Janela
            aberta={aberta}
            onFechar={() => onFechar(false)}
            titulo={anuncio ? 'Editar anúncio' : viaAgenda ? `Concluir ${tipoRotulo} de ${oferta?.sku}` : `Anúncio de ${oferta?.sku}`}
            descricao={viaAgenda
                ? 'Informe o código do anúncio que você acabou de publicar no Mercado Livre.'
                : 'Mesmo SKU, títulos diferentes: Clássico para o melhor preço à vista, Premium para o parcelado.'}
        >
            <div className="space-y-3" data-form-anuncio>
                {! tipoFixo && (
                    <div className="flex gap-2" role="radiogroup" aria-label="Tipo">
                        {Object.entries(vocabulario.tipos).map(([v, r]) => (
                            <button key={v} type="button" role="radio" aria-checked={dados.tipo === v} onClick={() => set('tipo', v)}
                                className={`flex-1 rounded-xl border px-3 py-2 text-[13px] ${dados.tipo === v ? 'border-ecf-yellow/50 bg-ecf-yellow/10 text-ecf-yellow' : 'border-white/[0.10] text-white/60'}`}>
                                {r}
                            </button>
                        ))}
                    </div>
                )}
                {erros.tipo && <p className="text-[12px] text-red-400">{erros.tipo}</p>}

                <Campo rotulo={viaAgenda ? 'Código MLB (obrigatório)' : 'Código MLB'} erro={erros.codigo_mlb}
                    dica={viaAgenda ? undefined : 'Opcional aqui, mas ajuda a reconhecer o anúncio depois.'}>
                    <input value={dados.codigo_mlb ?? ''} onChange={(e) => set('codigo_mlb', e.target.value)}
                        placeholder="MLB1234567890" className={`${CLASSE_INPUT} font-mono`} data-campo="codigo_mlb" autoFocus />
                </Campo>
                <Campo rotulo="Título do anúncio" erro={erros.titulo}>
                    <input value={dados.titulo ?? ''} onChange={(e) => set('titulo', e.target.value)} className={CLASSE_INPUT} />
                </Campo>
                {! viaAgenda && (
                    <Campo rotulo="Status" erro={erros.status} dica="Pausado conta como publicado; Inativo não conta.">
                        <Seletor valor={dados.status} onChange={(v) => set('status', v ?? 'ativo')} opcoes={vocabulario.status} />
                    </Campo>
                )}
                <div className="flex flex-wrap gap-4 text-[13px] text-white/75">
                    <label className="inline-flex items-center gap-2">
                        <input type="checkbox" checked={!! dados.catalogo} onChange={(e) => set('catalogo', e.target.checked)} className="rounded border-white/20 bg-transparent text-ecf-yellow" />
                        Está no catálogo
                    </label>
                    <label className="inline-flex items-center gap-2">
                        <input type="checkbox" checked={!! dados.kit_virtual} onChange={(e) => set('kit_virtual', e.target.checked)} className="rounded border-white/20 bg-transparent text-ecf-yellow" />
                        Montado como kit virtual
                    </label>
                </div>

                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={() => onFechar(false)}>Cancelar</Botao>
                    <Botao variante="primario" onClick={enviar} disabled={enviando} data-acao="salvar-anuncio">
                        {anuncio ? 'Salvar' : viaAgenda ? 'Concluir' : 'Cadastrar'}
                    </Botao>
                </div>
            </div>
        </Janela>
    );
}
