import test from 'node:test';
import assert from 'node:assert/strict';
import { contatoDoAlvo, acessoComEmail } from '../../resources/js/lib/acessosPortal.js';

test('selecionar empresa aproveita contato; empresa vazia ou grupo não herda outro contato', () => {
    const empresas = [{ id: 1, contato: { nome: 'Ana', email: 'ana@example.test' } }, { id: 2 }];
    assert.equal(contatoDoAlvo('e:1', empresas).email, 'ana@example.test');
    for (const alvo of ['e:2', 'g:1', '', 'e:999']) {
        assert.deepEqual(contatoDoAlvo(alvo, empresas), { nome: '', email: '', telefone: '', cargo: '' });
    }
});

test('identifica conta existente sem diferenciar caixa ou espaços, inclusive desativada', () => {
    const conta = { id: 7, email: 'ana@example.test', ativo: false };
    assert.equal(acessoComEmail(' ANA@EXAMPLE.TEST ', [conta]), conta);
    assert.equal(acessoComEmail('', [conta]), undefined);
    assert.equal(acessoComEmail('outra@example.test', [conta]), undefined);
});
