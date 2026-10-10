import { describe, expect, it } from 'vitest';
import { NOTES_PLACEHOLDER, noticeRecipients, releaseNoticeMessage } from './releaseNotice';

const andres = { name: 'Andrés Méndez', company: 'Derivados', area: 'Compras' };
const paola = { name: 'Paola Ríos', company: 'Segurex', area: 'Ventas' };

describe('noticeRecipients', () => {
    it('groups the included requests by requester, first seen first', () => {
        const recipients = noticeRecipients([
            { key: 'GIA-118', title: 'Buscador por referencia', requester: andres },
            { key: 'GIA-120', title: 'Filtro por marca', requester: paola },
            { key: 'GIA-122', title: 'Exportar a Excel', requester: andres },
        ]);
        expect(recipients).toEqual([
            {
                requester: andres,
                requests: [
                    { key: 'GIA-118', title: 'Buscador por referencia' },
                    { key: 'GIA-122', title: 'Exportar a Excel' },
                ],
            },
            { requester: paola, requests: [{ key: 'GIA-120', title: 'Filtro por marca' }] },
        ]);
    });

    it('is empty without requests', () => {
        expect(noticeRecipients([])).toEqual([]);
    });
});

describe('releaseNoticeMessage', () => {
    const base = {
        requesterName: 'Andrés Méndez',
        requests: [{ key: 'GIA-118', title: 'Buscador por referencia' }],
        productName: 'Portafolio de productos',
        tag: 'v1.8.2',
    };

    it('greets by first name and names the request, the product and the version', () => {
        const message = releaseNoticeMessage({ ...base, notes: 'Ahora puedes buscar por referencia.' });
        expect(message).toEqual([
            'Hola, Andrés. Ya está disponible lo que pediste: Buscador por referencia (GIA-118), en Portafolio de productos v1.8.2.',
            'Ahora puedes buscar por referencia.',
            '¿Cómo te fue? Califica la entrega en un minuto.',
        ]);
    });

    it('joins several requests in Spanish', () => {
        const [first] = releaseNoticeMessage({
            ...base,
            requests: [
                { key: 'GIA-118', title: 'Buscador por referencia' },
                { key: 'GIA-122', title: 'Exportar a Excel' },
            ],
            notes: 'x',
        });
        expect(first).toBe(
            'Hola, Andrés. Ya está disponible lo que pediste: Buscador por referencia (GIA-118) y Exportar a Excel (GIA-122), en Portafolio de productos v1.8.2.',
        );
    });

    it('shows the placeholder while the notes are empty', () => {
        expect(releaseNoticeMessage({ ...base, notes: '  \n ' })[1]).toBe(NOTES_PLACEHOLDER);
    });

    it('keeps the confirmed notes trimmed', () => {
        expect(releaseNoticeMessage({ ...base, notes: '\n Línea uno.\n\nLínea dos. \n' })[1]).toBe('Línea uno.\n\nLínea dos.');
    });
});
