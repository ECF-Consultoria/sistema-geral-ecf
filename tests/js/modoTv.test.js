import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { assinarModoTv, ligarModoTv, modoTvLigado } from '../../resources/js/lib/modoTv.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Modo TV sem aviso flutuante.
//
// Pedido de 30/09: o dev concluía um ticket e o cartão "ticket respondido" aparecia
// por cima do Modo TV do Painel Polos, na parede. Regra: nenhum Modo TV mostra aviso.
//
// Os Modos TV do Dashboard, do Dashboard MLB e de Publicações trocam a página
// inteira e SAEM do AppLayout — a pilha de avisos nem existe lá. O do Painel Polos
// é um overlay renderizado DENTRO do AppLayout, então ele liga o sinal de
// lib/modoTv.js e o layout desmonta a pilha. As travas abaixo cobrem os dois lados.
// ═══════════════════════════════════════════════════════════════════════

describe('sinal de Modo TV', () => {
    test('liga, desliga e avisa quem assina', () => {
        let avisos = 0;
        const cancelar = assinarModoTv(() => { avisos += 1; });

        assert.equal(modoTvLigado(), false);
        const desligar = ligarModoTv();
        assert.equal(modoTvLigado(), true);
        desligar();
        assert.equal(modoTvLigado(), false);
        assert.equal(avisos, 2);

        cancelar();
        ligarModoTv()();
        assert.equal(avisos, 2, 'assinatura cancelada não recebe mais aviso');
    });

    test('dois overlays: desligar um não apaga o sinal do outro', () => {
        const a = ligarModoTv();
        const b = ligarModoTv();
        a();
        assert.equal(modoTvLigado(), true);
        b();
        assert.equal(modoTvLigado(), false);
    });

    test('desligar duas vezes não desconta em dobro (cleanup repetido do StrictMode)', () => {
        const outro = ligarModoTv();
        const desligar = ligarModoTv();
        desligar();
        desligar();
        assert.equal(modoTvLigado(), true, 'o segundo desligar não pode apagar o sinal do outro overlay');
        outro();
        assert.equal(modoTvLigado(), false);
    });
});

describe('AppLayout e os Modos TV', () => {
    test('a pilha de avisos (ticket + toast) só monta fora do Modo TV', () => {
        const fonte = lerSemComentarios('resources/js/Layouts/AppLayout.jsx');
        assert.match(fonte, /const modoTv = useModoTvLigado\(\)/);
        assert.match(fonte, /\{!modoTv && \(\s*<div className="fixed bottom-5 right-5[^"]*">\s*<AvisoTicketRespondido \/>/);
        assert.equal(fonte.match(/<AvisoTicketRespondido/g).length, 1, 'aviso de ticket renderizado em outro ponto escaparia do Modo TV');
    });

    test('o Modo TV do Painel Polos (overlay dentro do AppLayout) liga o sinal', () => {
        const fonte = lerSemComentarios('resources/js/Pages/Polos/components/ModoTV.jsx');
        assert.match(fonte, /\buseAnunciarModoTv\(\)/);
    });

    test('Dashboard e Dashboard MLB: o Modo TV devolve a tela ANTES do AppLayout', () => {
        for (const caminho of ['resources/js/Pages/Dashboard/Admin.jsx', 'resources/js/Pages/Mlb/Dashboard.jsx']) {
            const fonte = lerSemComentarios(caminho);
            const tv = fonte.indexOf('if (tvMode) {');
            const layout = fonte.indexOf('<AppLayout');
            assert.ok(tv > -1 && layout > -1, `${caminho}: estrutura do Modo TV mudou — revisar se o aviso escapa`);
            assert.ok(tv < layout, `${caminho}: Modo TV passou para dentro do AppLayout — chamar useAnunciarModoTv(tvMode)`);
        }
    });

    test('Publicações: o Modo TV usa o TvShell no lugar do AppLayout', () => {
        const fonte = lerSemComentarios('resources/js/Pages/Performance/Index.jsx');
        assert.match(fonte, /tvMode\s*\?\s*<TvShell>\{conteudo\}<\/TvShell>\s*:\s*<AppLayout/);
    });
});
