import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, Sparkles, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from './BarraDaConta';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';
import { BASE_BOTAO, PRIMARIO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';
import useSugestaoKitIa from './useSugestaoKitIa';

// ─── O painel "Criar Fase N" (§4 da ETAPA-3, Fase 175) ──────────────────────
//
// Painel lateral de 620px sobre a tela do Produto. A pessoa confirma só o que
// muda: TUDO o que aparece aqui foi calculado no SERVIDOR
// (`PreviaDaFaseService`), e o painel não recalcula nada por conta própria — se
// ele divergisse do servidor, o que nasce seria diferente do que ela viu.
//
// ⚠️ A lição de 05-07/10/2026 (tela preta em produção: "Objects are not valid
// as a React child") vale literal aqui: TODO campo da prévia, TODO aviso e TODO
// pedaço do resultado da capa passa por `textoSeguro()`/`numeroSeguro()` antes
// do JSX. Este painel expõe de uma vez o payload inteiro de dois endpoints.
//
// ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
// variável de escopo do componente lida DENTRO de `.map()` já foi eliminada no
// bundle de produção. Aqui todo `.map()` calcula o que precisa no próprio
// callback — e quando precisa do tamanho da lista usa o TERCEIRO argumento do
// `map`, que o runtime passa, não uma variável capturada.
//
// ═══ Por que o arquivo tem dois componentes ═════════════════════════════════
//
// `PainelCriarFase` é a CASCA: estado, chamadas ao servidor, hook da IA.
// `CorpoDoPainel` é o CORPO, puro: recebe o payload do servidor e os valores
// dos campos e só desenha. A divisão existe porque não há DOM nos testes deste
// projeto (sem jsdom): efeito de React não roda lá, então um painel que só
// mostrasse a prévia DEPOIS do `useEffect` jamais seria exercitado pelo gate de
// render — exatamente a fenda pela qual o campo-objeto chegou a produção. Com o
// corpo separado, o gate renderiza o payload REAL do servidor, inclusive
// adverso, sem depender de efeito nenhum.

const DEBOUNCE = 350;

const CAMPO = 'h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const CAMPO_TRAVADO = 'h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 text-[13px] font-normal text-white/55 focus:outline-none';
const ROTULO = 'mb-1 block text-[13px] font-normal text-white/70';
const AJUDA = 'mt-1 block text-[11px] font-normal text-white/40';
const ERRO = 'mt-1 block text-[13px] font-normal text-red-300';
const MARCA = 'ml-2 rounded-full bg-white/[0.06] px-2 py-0.5 text-[11px] font-normal text-white/55';
const AMBAR = 'rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-[13px] font-normal text-amber-300';
const BLOCO = 'rounded-lg border border-white/[0.08] bg-white/[0.03] p-3';

/** Os três campos que a prévia sugere e a pessoa pode editar. */
export const CAMPOS_DA_PREVIA = ['sku', 'titulo', 'descricao'];

const rotaDoPublicador = criarRota('mlb.anuncios.publicador', 'conta');

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Lista do servidor em forma segura; qualquer outra coisa vira `[]`. */
const listaSegura = (valor) => (Array.isArray(valor) ? valor : []);

/** Só number finito do servidor; qualquer outra forma cai em null. */
const numeroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) ? valor : null);

/** O booleano de um campo de um mapa que pode não ser mapa. */
const marcado = (mapa, chave) => objetoSeguro(mapa)[chave] === true;

/** Algum aviso do servidor tem esta chave? (função, não variável capturada) */
const temAviso = (avisos, chave) => listaSegura(avisos).some((item) => objetoSeguro(item).chave === chave);

/**
 * O título que a prévia sugere. O painel tem UM campo de título (§4) e o corpo
 * do POST manda UM `titulo`; a ordem de `tipos` decide qual sugestão aparece.
 */
export function tituloSugerido(dados) {
    const d = objetoSeguro(dados);
    const porTipo = objetoSeguro(d.titulo_por_tipo);
    const tipos = listaSegura(d.tipos);
    const chaves = tipos.length > 0 ? tipos : Object.keys(porTipo);

    for (const chave of chaves) {
        const valor = porTipo[typeof chave === 'string' ? chave : ''];
        if (typeof valor === 'string' && valor !== '') {
            return valor;
        }
    }

    return '';
}

/** O que a prévia sugere para cada campo editável. */
function sugestoesDaPrevia(dados) {
    const d = objetoSeguro(dados);

    return {
        sku: textoSeguro(d.sku, ''),
        titulo: tituloSugerido(d),
        descricao: textoSeguro(d.descricao, ''),
    };
}

/**
 * A regra da §4: "mudar N atualiza SKU, título e descrição **se ainda não
 * editados**". Campo em `tocados` mantém o valor da pessoa; o resto recebe a
 * sugestão nova do servidor.
 *
 * ⚠️ É esta função que impede a prévia de apagar o que a pessoa digitou. Sem o
 * mapa de tocados, trocar o N limparia o SKU que ela acabou de escrever.
 *
 * @param {{dados?: Object, tocados?: Object, valores?: Object}} entrada
 * @returns {{sku: string, titulo: string, descricao: string}}
 */
export function valoresAposPrevia({ dados, tocados, valores } = {}) {
    const sugerido = sugestoesDaPrevia(dados);
    const atual = objetoSeguro(valores);
    const resultado = {};

    for (const campo of CAMPOS_DA_PREVIA) {
        resultado[campo] = marcado(tocados, campo) ? textoSeguro(atual[campo], '') : sugerido[campo];
    }

    return resultado;
}

/**
 * O erro de campo que o painel sabe sozinho, para não gastar uma chamada ao
 * servidor com "1" ou "abc". O teto é do servidor (65535): a §4 pede "sem teto
 * de interface", então quantidade grande vai ao servidor e volta como 422.
 */
export function erroLocalDaQuantidade(texto) {
    const t = String(texto ?? '').trim();
    if (t === '') return 'Informe quantas unidades o kit tem.';
    if (!/^\d+$/.test(t)) return 'Use um número inteiro de unidades, sem vírgula.';
    if (Number(t) < 2) return 'Um kit tem 2 unidades ou mais.';

    return null;
}

/**
 * A recusa do servidor (422) repartida entre campo e mensagem geral.
 *
 * ⚠️ O `campo` sai do contexto da recusa e **não é sempre `quantidade`**:
 * KIT-03 e KIT-04 marcam o campo das unidades, mas KIT-01/02/05 são recusas do
 * produto inteiro e não têm campo nenhum para marcar — tratá-las como erro de
 * campo marcaria o input errado em "este produto já é um kit".
 *
 * @returns {{porCampo: Object, geral: ?string}}
 */
export function erroDeRecusa(dados) {
    const d = objetoSeguro(dados);
    const mensagem = typeof d.message === 'string' && d.message.trim() !== ''
        ? d.message
        : 'Não foi possível criar a fase. Tente de novo.';
    const campo = typeof d.campo === 'string' && d.campo.trim() !== '' ? d.campo : null;
    const porCampo = {};

    for (const [chave, lista] of Object.entries(objetoSeguro(d.errors))) {
        const primeira = Array.isArray(lista)
            ? lista.find((item) => typeof item === 'string' && item.trim() !== '')
            : (typeof lista === 'string' && lista.trim() !== '' ? lista : null);
        if (primeira) porCampo[chave] = primeira;
    }

    if (campo !== null) {
        porCampo[campo] = mensagem;
    }

    return { porCampo, geral: Object.keys(porCampo).length === 0 ? mensagem : null };
}

/**
 * O que contar sobre a capa depois do 201.
 *
 * ⚠️ Recusa da capa **não é recusa da fase**: o endpoint responde 201 com
 * `capa.ok = false` e o motivo. O painel mostra o motivo e segue oferecendo o
 * editor do kit — tratar isso como erro de criação faria a pessoa achar que a
 * fase não nasceu, e ela nasceu.
 */
export function motivoDaCapa(dados) {
    const d = objetoSeguro(dados);
    if (d.capa_pedida !== true) return null;
    const capa = objetoSeguro(d.capa);
    if (capa.ok === true) return null;

    return typeof capa.motivo === 'string' && capa.motivo.trim() !== ''
        ? capa.motivo
        : 'A fase foi criada, mas a capa do kit não pôde ser gerada agora. Dá para gerar depois, no editor do kit.';
}

/** O rótulo de uma linha de estoque: o SELLER_SKU, nunca a chave interna da variante. */
function rotuloDaVariante(dadosDaVariante) {
    return textoSeguro(objetoSeguro(dadosDaVariante).seller_sku, '');
}

/**
 * O CORPO do painel — puro. Recebe o payload do servidor e os valores dos
 * campos, e só desenha. Nenhuma chamada, nenhum efeito, nenhum estado.
 */
export function CorpoDoPainel({
    previa = null,
    carregando = false,
    erroPrevia = null,
    quantidade = '',
    erroQuantidade = null,
    sku = '',
    titulo = '',
    descricao = '',
    tocados = null,
    sugeridos = null,
    anteriores = null,
    capa = true,
    mostrarCapa = false,
    enviando = false,
    errosCampo = null,
    erroGeral = null,
    resultado = null,
    iaEstados = null,
    proximoNumero = null,
    onQuantidade,
    onSku,
    onTitulo,
    onDescricao,
    onCapa,
    onSugerirIa,
    onDesfazer,
    onConfirmar,
    onFechar,
}) {
    const p = objetoSeguro(previa);
    const erros = objetoSeguro(errosCampo);
    const ia = objetoSeguro(iaEstados);
    const numero = numeroSeguro(proximoNumero);
    const limiteTitulo = numeroSeguro(p.max_title_length);
    const erroDoCampoUnidades = textoSeguro(erroQuantidade, '') || textoSeguro(erros.quantidade, '') || textoSeguro(p.erro_campo, '');
    const erroDoCampoSku = textoSeguro(erros.sku, '');
    const temPrevia = Object.keys(p).length > 0;

    const iaTitulo = objetoSeguro(ia.titulo);
    const iaDescricao = objetoSeguro(ia.descricao);
    const iaRodando = iaTitulo.status === 'rodando' || iaDescricao.status === 'rodando';
    const erroDaIa = textoSeguro(iaTitulo.erro, '') || textoSeguro(iaDescricao.erro, '');

    const feito = objetoSeguro(resultado);
    const urlDoEditor = typeof feito.url === 'string' && feito.url !== '' ? feito.url : null;
    const motivoDaCapaRecusada = textoSeguro(feito.capaMotivo, '');

    const comprimentoDoTitulo = String(titulo ?? '').length;
    const passouDoLimite = limiteTitulo !== null && comprimentoDoTitulo > limiteTitulo;
    const skuPreenchido = String(sku ?? '').trim() !== '';
    const podeConfirmar = !enviando && !carregando && temPrevia && skuPreenchido
        && erroDoCampoUnidades === '' && urlDoEditor === null;

    // O 201 já voltou: a fase existe. Daqui a pessoa só vai para o editor — e,
    // se a capa foi recusada, ela lê o motivo antes de sair.
    if (urlDoEditor !== null) {
        return (
            <div className="space-y-4">
                <p className="text-[13px] font-bold text-white">
                    {numero === null ? 'Fase criada.' : `Fase ${numero} criada.`}
                </p>
                {motivoDaCapaRecusada !== '' && <p className={AMBAR}>{motivoDaCapaRecusada}</p>}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onFechar} className={cn(BASE_BOTAO, SECUNDARIO)}>
                        Ficar aqui
                    </button>
                    <button type="button" onClick={onConfirmar} className={cn(BASE_BOTAO, PRIMARIO)}>
                        Abrir o editor do kit
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {/* 1 — Unidades no kit */}
            <label className="block">
                <span className={ROTULO}>Unidades no kit</span>
                <input
                    type="number"
                    min="2"
                    step="1"
                    inputMode="numeric"
                    value={String(quantidade ?? '')}
                    onChange={(e) => onQuantidade?.(e.target.value)}
                    className={cn(CAMPO, 'w-32', erroDoCampoUnidades !== '' && 'border-red-400/40')}
                    autoFocus
                />
                {erroDoCampoUnidades !== '' && <span className={ERRO}>{erroDoCampoUnidades}</span>}
                {carregando && <span className={AJUDA}>recalculando o que vai nascer…</span>}
            </label>

            {erroPrevia !== null && textoSeguro(erroPrevia, '') !== '' && (
                <p className={AMBAR}>{textoSeguro(erroPrevia, '')}</p>
            )}

            {/* 2 — SKU */}
            <label className="block">
                <span className={ROTULO}>
                    SKU do kit
                    {marcado(tocados, 'sku') && <span className={MARCA}>editado por você</span>}
                </span>
                <input
                    type="text"
                    maxLength={120}
                    value={String(sku ?? '')}
                    onChange={(e) => onSku?.(e.target.value)}
                    className={cn(CAMPO, 'font-mono', erroDoCampoSku !== '' && 'border-red-400/40')}
                />
                {erroDoCampoSku !== '' && <span className={ERRO}>{erroDoCampoSku}</span>}
                {temAviso(p.avisos, 'sku_repetido') && (
                    <span className={AJUDA}>este código já existe nesta empresa — dá para seguir assim</span>
                )}
            </label>

            {/* 3 — Estoque (somente leitura) */}
            <div className={BLOCO}>
                <p className="text-[13px] font-normal text-white/70">Estoque do kit</p>
                <p className={AJUDA}>calculado do produto base</p>
                <div className="mt-2 space-y-2">
                    {Object.entries(objetoSeguro(p.variantes)).length === 0 ? (
                        <p className="text-[13px] font-normal text-white/40">—</p>
                    ) : Object.entries(objetoSeguro(p.variantes)).map(([chave, item], indice, lista) => {
                        // Flags calculadas DENTRO do callback; o tamanho vem do
                        // terceiro argumento do map, não de variável capturada.
                        const v = objetoSeguro(item);
                        const umaSo = lista.length <= 1;
                        const rotulo = rotuloDaVariante(v);
                        const calculado = numeroSeguro(v.estoque);
                        const porDeposito = Object.entries(objetoSeguro(v.depositos));
                        const desligada = v.ativa === false;

                        return (
                            <div key={typeof chave === 'string' ? chave : indice}>
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="font-mono text-[11px] text-white/55">
                                        {umaSo && rotulo === '' ? 'todas as unidades' : (rotulo === '' ? `variação ${indice + 1}` : rotulo)}
                                        {desligada && <span className={MARCA}>desativada no base</span>}
                                    </span>
                                    <input
                                        type="text"
                                        readOnly
                                        value={calculado === null ? '—' : String(calculado)}
                                        className={cn(CAMPO_TRAVADO, 'w-20 text-right')}
                                        tabIndex={-1}
                                    />
                                </div>
                                {porDeposito.length > 0 && (
                                    <ul className="mt-1 space-y-0.5">
                                        {porDeposito.map(([nome, valor], ordem) => {
                                            // Flags dentro do callback (armadilha do Rollup).
                                            const doDeposito = numeroSeguro(valor);
                                            const nomeDoDeposito = textoSeguro(nome, '—');

                                            return (
                                                <li key={ordem} className="flex justify-between text-[11px] font-normal text-white/40">
                                                    <span>{nomeDoDeposito}</span>
                                                    <span>{doDeposito === null ? '—' : doDeposito}</span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* 4 — Título */}
            <label className="block">
                <span className={ROTULO}>
                    Título do kit
                    {marcado(tocados, 'titulo') && <span className={MARCA}>editado por você</span>}
                    {marcado(sugeridos, 'titulo') && <span className={MARCA}>sugerido pela IA</span>}
                </span>
                <input
                    type="text"
                    maxLength={255}
                    value={String(titulo ?? '')}
                    onChange={(e) => onTitulo?.(e.target.value)}
                    className={cn(CAMPO, passouDoLimite && 'border-amber-400/40')}
                />
                <span className={cn(AJUDA, passouDoLimite && 'text-amber-300')}>
                    {comprimentoDoTitulo}
                    {limiteTitulo === null ? ' caracteres' : ` de ${limiteTitulo} caracteres`}
                    {temAviso(p.avisos, 'titulo_cortado') && ' · cortado na última palavra inteira'}
                </span>
                {marcado(sugeridos, 'titulo') && objetoSeguro(anteriores).titulo && (
                    <button type="button" onClick={() => onDesfazer?.('titulo')} className="mt-1 text-[11px] font-normal text-white/55 underline decoration-dotted hover:text-ecf-yellow">
                        Desfazer
                    </button>
                )}
                {listaSegura(p.tipos).length > 1 && (
                    <span className={AJUDA}>
                        A Fase 1 tem um título por tipo de anúncio. Editar aqui faz o mesmo título valer para todos.
                    </span>
                )}
                <ul className="mt-1 space-y-0.5">
                    {Object.entries(objetoSeguro(p.titulo_por_tipo)).map(([tipo, valor], ordem, lista) => {
                        // Flags dentro do callback (armadilha do Rollup).
                        if (lista.length <= 1) return null;
                        const textoDoTipo = textoSeguro(valor, '');
                        if (textoDoTipo === '') return null;

                        return (
                            <li key={ordem} className="text-[11px] font-normal text-white/40">
                                {textoSeguro(tipo, '—')}: {textoDoTipo}
                            </li>
                        );
                    })}
                </ul>
            </label>

            {/* 5 — Descrição */}
            <label className="block">
                <span className={ROTULO}>
                    Descrição do kit
                    {marcado(tocados, 'descricao') && <span className={MARCA}>editado por você</span>}
                    {marcado(sugeridos, 'descricao') && <span className={MARCA}>sugerido pela IA</span>}
                </span>
                <textarea
                    rows={6}
                    value={String(descricao ?? '')}
                    onChange={(e) => onDescricao?.(e.target.value)}
                    className="w-full rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 py-2 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                />
                {marcado(sugeridos, 'descricao') && objetoSeguro(anteriores).descricao && (
                    <button type="button" onClick={() => onDesfazer?.('descricao')} className="mt-1 text-[11px] font-normal text-white/55 underline decoration-dotted hover:text-ecf-yellow">
                        Desfazer
                    </button>
                )}
            </label>

            {/* 6 — Sugerir com IA */}
            <div className={BLOCO}>
                <button
                    type="button"
                    onClick={onSugerirIa}
                    disabled={iaRodando || !temPrevia}
                    className={cn(BASE_BOTAO, SECUNDARIO)}
                >
                    {iaRodando
                        ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                        : <Sparkles className="h-4 w-4" aria-hidden="true" />}
                    {iaRodando ? 'pedindo…' : 'Sugerir com IA'}
                </button>
                <p className={AJUDA}>Mexe só no título e na descrição. Dá para desfazer.</p>
                {erroDaIa !== '' && <p className={ERRO}>{erroDaIa}</p>}
            </div>

            {/* 7 — Capa (só quando o servidor disse que pode) */}
            {mostrarCapa === true && (
                <div className={BLOCO}>
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={capa === true}
                            onChange={(e) => onCapa?.(e.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-white/[0.15] bg-white/[0.04] accent-ecf-yellow"
                        />
                        <span className="text-[13px] font-normal text-white/70">
                            Gerar a capa do kit
                            <span className={AJUDA}>
                                Duas opções — uma ambientada e uma de fundo limpo. Você escolhe qual vira a foto 1.
                            </span>
                        </span>
                    </label>
                    {capa !== true && (
                        <p className={cn(AMBAR, 'mt-2')}>A capa ainda mostra 1 unidade</p>
                    )}
                </div>
            )}

            {/* Os avisos do servidor, todos, antes do Confirmar */}
            {listaSegura(p.avisos).length > 0 && (
                <div className={AMBAR}>
                    <p className="font-bold">Antes de confirmar</p>
                    <ul className="mt-1 space-y-1">
                        {listaSegura(p.avisos).map((item, ordem) => {
                            // Flags dentro do callback (armadilha do Rollup).
                            const aviso = objetoSeguro(item);
                            const mensagem = textoSeguro(aviso.mensagem, '');
                            if (mensagem === '') return null;

                            return <li key={ordem}>{mensagem}</li>;
                        })}
                    </ul>
                </div>
            )}

            {textoSeguro(erroGeral, '') !== '' && (
                <p className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-[13px] font-normal text-red-300">
                    {textoSeguro(erroGeral, '')}
                </p>
            )}

            {/* 8 — Confirmar */}
            <div className="flex justify-end gap-2 border-t border-white/[0.08] pt-4">
                <button type="button" onClick={onFechar} className={cn(BASE_BOTAO, SECUNDARIO)} disabled={enviando}>
                    Voltar
                </button>
                <button
                    type="button"
                    onClick={onConfirmar}
                    disabled={!podeConfirmar}
                    className={cn(BASE_BOTAO, PRIMARIO)}
                >
                    {enviando && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                    Confirmar
                </button>
            </div>
        </div>
    );
}

/**
 * A CASCA: overlay, foco, Escape, chamadas ao servidor e o hook da IA.
 *
 * @param {{aberto: boolean, onFechar?: Function, conta?: string, produtoBase?: Object,
 *   proximaFase?: Object, criativosIa?: boolean, onCriado?: Function}} props
 *   `criativosIa` é CAPACIDADE DO SERVIDOR (chave do Creative Engine +
 *   `CreativePermissao`), não escolha da pessoa: falso não desabilita a caixa da
 *   capa, ele não a renderiza.
 */
export default function PainelCriarFase({
    aberto = false,
    onFechar,
    conta = null,
    produtoBase = null,
    proximaFase = null,
    criativosIa = false,
    onCriado,
}) {
    const base = objetoSeguro(produtoBase);
    const proxima = objetoSeguro(proximaFase);
    const produtoId = numeroSeguro(base.id);
    const contaSegura = typeof conta === 'string' && conta !== '' ? conta : null;
    const sugerida = numeroSeguro(proxima.quantidade_sugerida) ?? 2;
    const numero = numeroSeguro(proxima.numero);

    const [quantidade, setQuantidade] = useState(String(sugerida));
    const [previa, setPrevia] = useState(null);
    const [carregando, setCarregando] = useState(false);
    const [erroPrevia, setErroPrevia] = useState(null);
    const [sku, setSku] = useState('');
    const [titulo, setTitulo] = useState('');
    const [descricao, setDescricao] = useState('');
    const [tocados, setTocados] = useState({});
    const [sugeridos, setSugeridos] = useState({});
    const [anteriores, setAnteriores] = useState({});
    const [capa, setCapa] = useState(true);
    const [enviando, setEnviando] = useState(false);
    const [errosCampo, setErrosCampo] = useState({});
    const [erroGeral, setErroGeral] = useState(null);
    const [resultado, setResultado] = useState(null);

    // Os valores e o mapa de tocados também num ref: a resposta da prévia chega
    // depois e não pode fechar sobre o estado da renderização em que saiu.
    const valores = useRef({ sku, titulo, descricao });
    valores.current = { sku, titulo, descricao };
    const tocadosRef = useRef(tocados);
    tocadosRef.current = tocados;

    const erroQuantidade = erroLocalDaQuantidade(quantidade);
    const n = erroQuantidade === null ? Number(String(quantidade).trim()) : null;

    const aplicarSugestaoDaIa = useCallback((alvo, valor) => {
        const texto = typeof valor === 'string' ? valor : '';
        if (texto === '') return;
        const antes = alvo === 'titulo' ? valores.current.titulo : valores.current.descricao;
        setAnteriores((atual) => ({ ...atual, [alvo]: { valor: antes, tocado: tocadosRef.current[alvo] === true } }));
        setSugeridos((atual) => ({ ...atual, [alvo]: true }));
        // A sugestão NÃO conta como "editado à mão": mudar o N depois recalcula o
        // campo e apaga a marca, porque uma sugestão feita para Kit 2 fala de 2
        // unidades e não serve para o Kit 3.
        if (alvo === 'titulo') setTitulo(texto);
        else setDescricao(texto);
    }, []);

    const ia = useSugestaoKitIa({
        conta: contaSegura,
        produtoId,
        quantidade: n ?? sugerida,
        onPronto: aplicarSugestaoDaIa,
    });

    // Abrir (ou reabrir) devolve o painel ao estado inicial: o que a pessoa
    // digitou vale até o Confirmar, nunca entre duas aberturas.
    useEffect(() => {
        if (!aberto) return;
        setQuantidade(String(sugerida));
        setPrevia(null);
        setErroPrevia(null);
        setSku('');
        setTitulo('');
        setDescricao('');
        setTocados({});
        setSugeridos({});
        setAnteriores({});
        setCapa(true);
        setEnviando(false);
        setErrosCampo({});
        setErroGeral(null);
        setResultado(null);
        ia.limpar();
        // `ia.limpar` é estável (useCallback sem dependência); fora das deps de
        // propósito, para reabrir não disparar duas vezes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [aberto, sugerida]);

    // A prévia, com debounce: o campo das unidades é digitado.
    useEffect(() => {
        if (!aberto || contaSegura === null || produtoId === null || n === null) {
            return undefined;
        }
        let vivo = true;
        const espera = setTimeout(async () => {
            setCarregando(true);
            setErroPrevia(null);
            try {
                const { data } = await axios.get(
                    rotaDoPublicador('fases.previa', contaSegura, { produto: produtoId, quantidade: n }),
                );
                if (!vivo) return;
                setPrevia(data);
                const novos = valoresAposPrevia({ dados: data, tocados: tocadosRef.current, valores: valores.current });
                setSku(novos.sku);
                setTitulo(novos.titulo);
                setDescricao(novos.descricao);
                // Campo recalculado perde a marca da IA (a sugestão era do N antigo).
                setSugeridos((atual) => {
                    const resto = { ...atual };
                    for (const campo of CAMPOS_DA_PREVIA) {
                        if (tocadosRef.current[campo] !== true) delete resto[campo];
                    }

                    return resto;
                });
                setErrosCampo({});
            } catch (e) {
                if (vivo) setErroPrevia(mensagemDe(e));
            } finally {
                if (vivo) setCarregando(false);
            }
        }, DEBOUNCE);

        return () => { vivo = false; clearTimeout(espera); };
    }, [aberto, contaSegura, produtoId, n]);

    // Escape fecha o painel (molde do ModalNovoProduto).
    useEffect(() => {
        if (!aberto) return undefined;
        const aoTeclar = (ev) => {
            if (ev.key === 'Escape' && !enviando) onFechar?.();
        };
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [aberto, enviando, onFechar]);

    const tocar = (campo, valor) => {
        setTocados((atual) => ({ ...atual, [campo]: true }));
        setSugeridos((atual) => { const resto = { ...atual }; delete resto[campo]; return resto; });
        if (campo === 'sku') setSku(valor);
        if (campo === 'titulo') setTitulo(valor);
        if (campo === 'descricao') setDescricao(valor);
    };

    const desfazer = (campo) => {
        const antes = objetoSeguro(objetoSeguro(anteriores)[campo]);
        const valor = typeof antes.valor === 'string' ? antes.valor : '';
        if (campo === 'titulo') setTitulo(valor);
        if (campo === 'descricao') setDescricao(valor);
        setSugeridos((atual) => { const resto = { ...atual }; delete resto[campo]; return resto; });
        setTocados((atual) => ({ ...atual, [campo]: antes.tocado === true }));
        setAnteriores((atual) => { const resto = { ...atual }; delete resto[campo]; return resto; });
    };

    const confirmar = async () => {
        // Já criou: o botão agora é "Abrir o editor do kit".
        const feito = objetoSeguro(resultado);
        if (typeof feito.url === 'string' && feito.url !== '') {
            onCriado?.(feito.url);

            return;
        }
        if (enviando || carregando || contaSegura === null || produtoId === null || n === null) return;
        setEnviando(true);
        setErrosCampo({});
        setErroGeral(null);
        try {
            const { data } = await axios.post(
                rotaDoPublicador('fases.criar', contaSegura, { produto: produtoId }),
                {
                    quantidade: n,
                    sku: String(sku ?? '').trim(),
                    // Campo não tocado vai como null de propósito: o servidor então
                    // mantém a sugestão POR TIPO dele, preservando a diferença entre
                    // os títulos da Fase 1 que o campo único não sabe representar.
                    titulo: marcado(tocados, 'titulo') || marcado(sugeridos, 'titulo') ? String(titulo ?? '') : null,
                    descricao: marcado(tocados, 'descricao') || marcado(sugeridos, 'descricao') ? String(descricao ?? '') : null,
                    seller_skus: Object.fromEntries(
                        Object.entries(objetoSeguro(objetoSeguro(previa).variantes))
                            .map(([chave, item]) => [chave, rotuloDaVariante(item)])
                            .filter(([, valor]) => valor !== ''),
                    ),
                    capa: criativosIa === true && capa === true,
                },
            );
            const motivo = motivoDaCapa(data);
            const url = typeof objetoSeguro(data).url === 'string' ? objetoSeguro(data).url : null;
            if (motivo === null && url !== null) {
                // Nada a contar sobre a capa: segue direto para o editor do kit.
                onCriado?.(url);

                return;
            }
            setResultado({ url, capaMotivo: motivo });
        } catch (e) {
            const repartido = erroDeRecusa(e?.response?.data);
            setErrosCampo(repartido.porCampo);
            setErroGeral(repartido.geral ?? (Object.keys(repartido.porCampo).length === 0 ? mensagemDe(e) : null));
        } finally {
            setEnviando(false);
        }
    };

    if (!aberto) return null;

    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            <div
                className="absolute inset-0 bg-black/60"
                onClick={() => { if (!enviando) onFechar?.(); }}
                aria-hidden="true"
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={`Criar Fase ${numero ?? ''}`.trim()}
                className="relative z-10 flex h-full w-[620px] max-w-full flex-col overflow-y-auto border-l border-white/[0.08] bg-ecf-card p-6"
            >
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-[15px] font-bold text-white">
                            Criar Fase {numero ?? ''}
                        </h2>
                        <p className="text-[13px] font-normal text-white/55">
                            {textoSeguro(base.nome, 'Produto base')}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => { if (!enviando) onFechar?.(); }}
                        aria-label="Fechar"
                        className="rounded-lg p-1 text-white/55 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>

                <CorpoDoPainel
                    previa={previa}
                    carregando={carregando}
                    erroPrevia={erroPrevia}
                    quantidade={quantidade}
                    erroQuantidade={erroQuantidade}
                    sku={sku}
                    titulo={titulo}
                    descricao={descricao}
                    tocados={tocados}
                    sugeridos={sugeridos}
                    anteriores={anteriores}
                    capa={capa}
                    mostrarCapa={criativosIa === true}
                    enviando={enviando}
                    errosCampo={errosCampo}
                    erroGeral={erroGeral}
                    resultado={resultado}
                    iaEstados={ia.estados}
                    proximoNumero={numero}
                    onQuantidade={setQuantidade}
                    onSku={(v) => tocar('sku', v)}
                    onTitulo={(v) => tocar('titulo', v)}
                    onDescricao={(v) => tocar('descricao', v)}
                    onCapa={setCapa}
                    onSugerirIa={() => { ia.pedir('titulo'); ia.pedir('descricao'); }}
                    onDesfazer={desfazer}
                    onConfirmar={confirmar}
                    onFechar={onFechar}
                />
            </div>
        </div>
    );
}
