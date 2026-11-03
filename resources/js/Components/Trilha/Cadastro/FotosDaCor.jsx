import { useRef, useState } from 'react';
import { ImagePlus, Maximize2, Trash2 } from 'lucide-react';
import { ACEITA_NO_INPUT, LIMITE_IMAGENS, LIMITE_MB, avisosDoErro } from '@/lib/imagensVariacao';
import { contadorDeFotos, fotosPorCor, remessaDeFotos, textoDoEnvio, urlOriginalDaFoto } from '@/lib/trilhaCadastro';
import { cn } from '@/lib/utils';
import { Botao, Etiqueta, LinkDeAcao, Pilula } from '@/Components/Trilha/Pecas';
import { FOTOS_MINIMAS } from '@/Components/Trilha/formatos';
import { AvisoNaGaveta, ConfirmacaoNaGaveta } from '@/Components/Trilha/Cadastro/PecasDoCadastro';

// ─── Trilha de produtos — as fotos do produto, uma galeria por cor (F3) ─────
//
// §5.7 do desenho. As fotos são da COR: com cor e tamanho, moram na "dona" da
// cor (a 1ª variação dela com foto, senão a 1ª — `fotosPorCor`, sobre o
// `gruposPorCor` do Portal); com um eixo só, cada variação tem as suas; sem
// variação, uma galeria só.
//
// Tudo é AÇÃO IMEDIATA, sem o "Salvar": escolher as fotos já envia (uma
// remessa por cor, conferida antes com `remessaDeFotos` — formato, tamanho e o
// que ainda cabe; o servidor é tudo ou nada), e excluir pede a confirmação
// DENTRO da galeria e exclui na hora. A resposta é o retrato novo, que o hook
// junta ao estado sem apagar o que está mexido e não salvo.
//
// A 1ª foto é a capa; ordem e capa não se mudam por aqui (como no Portal).
// As miniaturas vêm pela rota da equipe (`trilha.fotos`); "Ver maior" abre a
// original (`?original=1`) ali mesmo, na gaveta. Nenhum componente do Portal:
// tokens `trilha-*`, claro e escuro.

/** O produto já está no Publicador: o Sincronizar só copia foto para galeria vazia (§5.7). */
export const AVISO_DAS_FOTOS_NO_PUBLICADOR = 'Foto nova aqui não entra no anúncio que já está sendo montado: troque no Publicador.';

const FOCO = 'focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-trilha-run';

/** "P, M e G". */
const emLista = (itens) => (itens.length > 1 ? `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}` : itens[0] ?? '');

/** Como a foto se chama para o leitor de tela: "foto 2 de Natural", "foto 1 (capa)". */
const nomeDaFoto = (indice, cor) => `foto ${indice + 1}${cor ? ` de ${cor}` : ''}${indice === 0 ? ' (capa)' : ''}`;

/**
 * A miniatura com o "Ver maior". Na gaveta só de leitura ela é um LINK para a original (em outra aba): o `fieldset
 * disabled` da gaveta desliga todo botão dentro dele, e ver a foto grande não é mexer no cadastro.
 */
function Miniatura({ foto, capa, rotulo, leitura, aberta, onClick }) {
    const classe = cn(
        'relative block aspect-square w-full overflow-hidden rounded-lg border bg-trilha-surface2',
        aberta ? 'border-trilha-run' : 'border-trilha-line',
        FOCO,
    );
    const miolo = (
        <>
            <img src={foto.url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />
            {capa && (
                <Etiqueta tom="roxo" className="absolute left-1 top-1">
                    capa
                </Etiqueta>
            )}
            <span aria-hidden="true" className="absolute bottom-1 right-1 inline-flex h-6 w-6 items-center justify-center rounded-md bg-trilha-surface/90 text-trilha-fg2">
                <Maximize2 size={13} />
            </span>
        </>
    );

    if (leitura) {
        return (
            <a href={urlOriginalDaFoto(foto) ?? undefined} target="_blank" rel="noopener noreferrer" data-ver-maior={foto.id} aria-label={rotulo} className={classe}>
                {miolo}
            </a>
        );
    }

    return (
        <button type="button" data-ver-maior={foto.id} aria-label={rotulo} aria-expanded={aberta} onClick={onClick} className={classe}>
            {miolo}
        </button>
    );
}

/**
 * A galeria DESENHADA a partir do que se sabe — sem pedido nem estado (é o que o teste de render desenha).
 *
 * @param {{
 *   galeria: {chave: string, variacaoId: number, cor: ?string, tamanhos: string[], fotos: Array, onde: string},
 *   limite?: number, mb?: number, leitura?: boolean, bloqueada?: boolean,
 *   enviando?: number, excluindo?: ?number, confirmando?: ?number, maior?: ?number,
 *   avisos?: string[], status?: ?string,
 *   onEscolher?: () => void, onPedirExclusao?: (id: number) => void, onConfirmarExclusao?: () => void,
 *   onCancelarExclusao?: () => void, onVerMaior?: (id: number) => void, onFecharMaior?: () => void,
 *   refDoEscolher?: object, refDoFecharMaior?: object,
 * }} props  `bloqueada`: outra ação (ou o "Salvar") está em curso; `enviando`: quantas fotos estão subindo.
 */
export function VistaDaGaleria({
    galeria, limite = LIMITE_IMAGENS, mb = LIMITE_MB, leitura = false, bloqueada = false, enviando = 0, excluindo = null,
    confirmando = null, maior = null, avisos = [], status = null, onEscolher = () => {}, onPedirExclusao = () => {},
    onConfirmarExclusao = () => {}, onCancelarExclusao = () => {}, onVerMaior = () => {}, onFecharMaior = () => {},
    refDoEscolher = null, refDoFecharMaior = null,
}) {
    const { cor, fotos, onde, tamanhos } = galeria;
    const contador = contadorDeFotos(fotos.length, FOTOS_MINIMAS);
    const cheia = fotos.length >= limite;
    const indiceDaConfirmacao = fotos.findIndex((f) => f.id === confirmando);
    const indiceDaMaior = fotos.findIndex((f) => f.id === maior);
    const fotoMaior = indiceDaMaior >= 0 ? fotos[indiceDaMaior] : null;
    const original = fotoMaior ? urlOriginalDaFoto(fotoMaior) : null;

    return (
        <div
            className="flex min-w-0 flex-col gap-2 rounded-xl border border-trilha-line2 bg-trilha-surface px-3 py-2.5"
            data-galeria={galeria.chave}
            data-variacao-das-fotos={galeria.variacaoId}
            aria-busy={enviando > 0 || excluindo != null ? true : undefined}
        >
            <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <span className="min-w-0 text-[12.5px] font-semibold text-trilha-fg [overflow-wrap:anywhere]">
                    {cor ?? (onde === 'neste produto' ? 'Fotos do produto' : 'Fotos desta variação')}
                </span>
                <Pilula tom={contador.tom} data-contador-de-fotos={contador.tom}>
                    {contador.texto}
                </Pilula>
            </div>
            {tamanhos.length > 1 && (
                <span className="text-[12px] text-trilha-muted" data-tamanhos-da-cor="">
                    Valem para os tamanhos {emLista(tamanhos)}.
                </span>
            )}

            <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(84px,1fr))] gap-2 p-0">
                {fotos.map((foto, i) => (
                    <li key={foto.id} className="flex min-w-0 flex-col gap-1" data-foto={foto.id}>
                        <Miniatura
                            foto={foto}
                            capa={i === 0}
                            rotulo={`Ver maior: ${nomeDaFoto(i, cor)}`}
                            leitura={leitura}
                            aberta={maior === foto.id}
                            onClick={() => (maior === foto.id ? onFecharMaior() : onVerMaior(foto.id))}
                        />
                        {!leitura && (
                            <button
                                type="button"
                                data-acao="excluir-foto"
                                data-excluir-foto={foto.id}
                                disabled={bloqueada}
                                onClick={() => onPedirExclusao(foto.id)}
                                className={cn(
                                    'inline-flex items-center justify-center gap-1 rounded-md px-1.5 py-0.5 text-[11.5px] font-semibold text-trilha-bad hover:bg-trilha-bad-bg',
                                    'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
                                    FOCO,
                                )}
                            >
                                <Trash2 size={12} aria-hidden="true" />
                                {excluindo === foto.id ? 'Excluindo…' : 'Excluir'}
                                <span className="sr-only"> a {nomeDaFoto(i, cor)}</span>
                            </button>
                        )}
                    </li>
                ))}
                {!leitura && !cheia && (
                    <li className="flex min-w-0 flex-col">
                        <button
                            ref={refDoEscolher}
                            type="button"
                            data-acao="enviar-fotos"
                            disabled={bloqueada && !enviando}
                            aria-disabled={enviando > 0 || undefined}
                            onClick={() => {
                                if (!enviando) onEscolher();
                            }}
                            className={cn(
                                'flex aspect-square w-full flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-trilha-line bg-trilha-surface px-1 text-center text-[12px] font-semibold text-trilha-fg2',
                                'hover:border-trilha-run hover:text-trilha-run disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-trilha-line disabled:hover:text-trilha-fg2',
                                enviando > 0 && 'cursor-progress opacity-60',
                                FOCO,
                            )}
                        >
                            <ImagePlus size={18} aria-hidden="true" />
                            {enviando > 0 ? `Enviando ${enviando}…` : 'Enviar fotos'}
                            {cor && <span className="sr-only"> de {cor}</span>}
                        </button>
                    </li>
                )}
            </ul>

            {fotos.length === 0 && leitura && <span className="text-[12.5px] text-trilha-muted">Nenhuma foto.</span>}

            {fotoMaior && (
                <figure
                    className="m-0 flex flex-col gap-2 rounded-xl border border-trilha-line bg-trilha-surface2 p-2"
                    data-foto-maior={fotoMaior.id}
                    onKeyDown={(evento) => {
                        if (evento.key !== 'Escape') return;
                        // Fecha só a foto grande: a gaveta lê o `defaultPrevented` e fica aberta.
                        evento.preventDefault();
                        onFecharMaior();
                    }}
                >
                    <img
                        src={original}
                        alt={nomeDaFoto(indiceDaMaior, cor).replace(/^f/, 'F')}
                        className="max-h-[420px] w-full rounded-lg bg-trilha-surface object-contain"
                    />
                    <figcaption className="flex flex-wrap items-center gap-2 text-[12px] text-trilha-muted">
                        <span className="mr-auto min-w-0 [overflow-wrap:anywhere]">
                            {fotoMaior.nome_original || `Foto ${indiceDaMaior + 1}`}
                            {indiceDaMaior === 0 && ' · capa'}
                        </span>
                        <LinkDeAcao href={original} target="_blank" rel="noopener noreferrer" className="px-2.5 py-1 text-[12.5px]" data-link="foto-original">
                            Abrir em outra aba
                        </LinkDeAcao>
                        <Botao ref={refDoFecharMaior} className="px-2.5 py-1 text-[12.5px]" data-acao="fechar-foto-maior" onClick={onFecharMaior}>
                            Fechar
                        </Botao>
                    </figcaption>
                </figure>
            )}

            {indiceDaConfirmacao >= 0 && (
                <div className="rounded-lg border border-trilha-bad/40 bg-trilha-bad-bg px-2.5 py-2" data-confirmar-exclusao-da-foto={confirmando}>
                    <ConfirmacaoNaGaveta
                        texto={
                            indiceDaConfirmacao === 0 && fotos.length > 1
                                ? 'Excluir a capa? Ela sai do cadastro na hora, e a 2ª foto passa a ser a capa.'
                                : `Excluir a foto ${indiceDaConfirmacao + 1}? Ela sai do cadastro na hora.`
                        }
                        acoes={[
                            {
                                rotulo: excluindo != null ? 'Excluindo…' : 'Excluir foto',
                                acao: 'confirmar-exclusao-da-foto',
                                perigo: true,
                                primario: true,
                                ocupado: excluindo != null,
                                onClick: onConfirmarExclusao,
                            },
                            { rotulo: 'Cancelar', acao: 'cancelar-exclusao-da-foto', foco: true, onClick: onCancelarExclusao },
                        ]}
                    />
                </div>
            )}

            {!leitura && (
                <span className="text-[12px] text-trilha-muted">
                    {cheia
                        ? `Já há ${limite} fotos ${onde}, o máximo. Exclua alguma para enviar outra.`
                        : `JPG, PNG ou WebP, até ${mb} MB cada; cabem até ${limite} ${onde}. A 1ª foto é a capa.`}
                </span>
            )}
            {avisos.length > 0 && (
                <ul role="alert" className="m-0 flex list-none flex-col gap-0.5 p-0 text-[12px] font-semibold text-trilha-bad" data-avisos-da-galeria="">
                    {avisos.map((aviso) => (
                        <li key={aviso} className="[overflow-wrap:anywhere]">
                            {aviso}
                        </li>
                    ))}
                </ul>
            )}
            <span role="status" className="text-[12px] font-semibold text-trilha-ok empty:hidden" data-status-da-galeria="">
                {status ?? ''}
            </span>
        </div>
    );
}

/**
 * Uma galeria com o que ela faz: escolher (e já enviar), ver maior, excluir com confirmação.
 *
 * @param {{galeria: object, limite?: number, mb?: number, leitura?: boolean, acao?: ?object,
 *   onEnviar: (variacaoId: number, arquivos: File[]) => Promise<{ok: boolean, falha?: any, travado?: boolean, ignorada?: boolean}>,
 *   onExcluir: (variacaoId: number, imagemId: number) => Promise<{ok: boolean, falha?: any, travado?: boolean, ignorada?: boolean}>}} props
 *   `acao`: a ação imediata em curso na gaveta (desta ou de outra galeria, ou o frete).
 */
export default function FotosDaCor({ galeria, limite = LIMITE_IMAGENS, mb = LIMITE_MB, leitura = false, ocupada = false, acao = null, onEnviar, onExcluir }) {
    const [avisos, setAvisos] = useState([]);
    const [status, setStatus] = useState(null);
    const [confirmando, setConfirmando] = useState(null);
    const [maior, setMaior] = useState(null);
    const caixa = useRef(null);
    const arquivo = useRef(null);
    const escolher = useRef(null);
    const fecharMaior = useRef(null);
    const desta = acao?.variacaoId === galeria.variacaoId;
    const enviando = desta && acao?.tipo === 'fotos' ? acao.quantas || 1 : 0;
    const excluindo = desta && acao?.tipo === 'foto' ? acao.imagemId : null;

    const focar = (seletor) => {
        if (typeof requestAnimationFrame === 'undefined') return;
        requestAnimationFrame(() => caixa.current?.querySelector(seletor)?.focus?.());
    };

    const aoEscolher = async (evento) => {
        const escolhidos = Array.from(evento.target.files ?? []);
        // O mesmo arquivo pode ser escolhido de novo (depois de um aviso, por exemplo).
        evento.target.value = '';
        if (!escolhidos.length) return;
        setStatus(null);
        const { arquivos, avisos: antes } = remessaDeFotos(escolhidos, galeria.fotos.length, { limite, mb, onde: galeria.onde });
        setAvisos(antes);
        if (!arquivos.length) return;
        const r = await onEnviar(galeria.variacaoId, arquivos);
        if (r?.ok) setStatus(textoDoEnvio(arquivos.length));
        else if (r?.falha && !r.travado) setAvisos([...antes, ...avisosDoErro(r.falha)]);
    };

    const confirmarExclusao = async () => {
        const id = confirmando;
        if (id == null) return;
        setStatus(null);
        setAvisos([]);
        const r = await onExcluir(galeria.variacaoId, id);
        setConfirmando(null);
        if (r?.ok) {
            setStatus('Foto excluída.');
            if (maior === id) setMaior(null);
            focar('[data-acao="enviar-fotos"]');
        } else if (r?.falha && !r.travado) {
            setAvisos(avisosDoErro(r.falha));
            focar(`[data-excluir-foto="${id}"]`);
        }
    };

    return (
        <div ref={caixa} className="min-w-0">
            <VistaDaGaleria
                galeria={galeria}
                limite={limite}
                mb={mb}
                leitura={leitura}
                // Uma ação de cada vez na gaveta (o hook também recusa a segunda): tudo espera a que está correndo.
                bloqueada={ocupada || acao != null}
                enviando={enviando}
                excluindo={excluindo}
                confirmando={confirmando}
                maior={maior}
                avisos={avisos}
                status={status}
                onEscolher={() => arquivo.current?.click()}
                onPedirExclusao={(id) => {
                    setStatus(null);
                    setConfirmando(id);
                }}
                onConfirmarExclusao={confirmarExclusao}
                onCancelarExclusao={() => {
                    const id = confirmando;
                    setConfirmando(null);
                    focar(`[data-excluir-foto="${id}"]`);
                }}
                onVerMaior={(id) => {
                    setMaior(id);
                    focar('[data-acao="fechar-foto-maior"]');
                }}
                onFecharMaior={() => {
                    const id = maior;
                    setMaior(null);
                    focar(`[data-ver-maior="${id}"]`);
                }}
                refDoEscolher={escolher}
                refDoFecharMaior={fecharMaior}
            />
            {!leitura && (
                <input
                    ref={arquivo}
                    type="file"
                    multiple
                    accept={ACEITA_NO_INPUT}
                    className="sr-only"
                    tabIndex={-1}
                    aria-hidden="true"
                    onChange={aoEscolher}
                    data-arquivos-da-galeria={galeria.chave}
                />
            )}
        </div>
    );
}

/**
 * As fotos do produto (§5.7): o rótulo, o aviso de quem já está no Publicador e uma galeria por cor.
 *
 * @param {{retrato: object, c: object, leitura?: boolean}} props  `c`: o retorno de `useCadastroDoProduto`.
 */
export function FotosDoProduto({ retrato, c, leitura = false }) {
    const galerias = fotosPorCor(retrato);
    if (!galerias.length) return null;
    const limite = Number(retrato?.limites?.fotos_por_variacao) || LIMITE_IMAGENS;
    const mb = Number(retrato?.limites?.foto_mb) || LIMITE_MB;
    const porCor = galerias.some((g) => g.onde === 'nesta cor');
    const semAcao = async () => ({ ok: false, ignorada: true });

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" data-campo="fotos">
            <legend className="mb-1 p-0 text-[12.5px] font-semibold text-trilha-fg2">
                Fotos
                <span aria-hidden="true" className="text-trilha-bad">
                    {' '}*
                </span>
                <span className="ml-1.5 font-normal text-trilha-muted">
                    {porCor ? `mínimo ${FOTOS_MINIMAS} em cada cor` : `mínimo ${FOTOS_MINIMAS}`}
                </span>
            </legend>
            {!leitura && retrato?.situacao?.no_publicador && (
                <AvisoNaGaveta tom="run" data-aviso-das-fotos-no-publicador="">
                    {AVISO_DAS_FOTOS_NO_PUBLICADOR}
                </AvisoNaGaveta>
            )}
            {galerias.map((galeria) => (
                <FotosDaCor
                    key={galeria.chave}
                    galeria={galeria}
                    limite={limite}
                    mb={mb}
                    leitura={leitura}
                    ocupada={!!c?.salvando}
                    acao={c?.acao ?? null}
                    onEnviar={c?.enviarFotos ?? semAcao}
                    onExcluir={c?.excluirFoto ?? semAcao}
                />
            ))}
        </fieldset>
    );
}
