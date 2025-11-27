/**
 * Chap Chap Pay Blocks Integration
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { __ } = window.wp.i18n;

const ChapChapPayPaymentMethod = {
    name: 'chap_chap_pay',
    label: __('Chap Chap Pay', 'chap-chap-pay'),
    content: React.createElement(
        'div',
        { className: 'chapchap-payment-method' },
        React.createElement(
            'p',
            { style: { fontSize: '14px', color: '#666', marginBottom: '10px' } },
            __('Vous serez redirigé vers Chap Chap Pay pour choisir votre moyen de paiement et finaliser la transaction.', 'chap-chap-pay')                                                                                                                                                                                                    //211101-071104
        )
    ),
    edit: null,
    placeOrderButtonLabel: __('Payer avec Chap Chap Pay', 'chap-chap-pay'),
    ariaLabel: __('Chap Chap Pay payment method', 'chap-chap-pay'),

    canMakePayment: () => {
        const currency = window.wc.wcSettings.getSetting('currency');
        if (currency && currency.code !== 'GNF') {
            return false;
        }
        return true;
    },

    supports: {
        features: ['products']
    }
};

if (typeof registerPaymentMethod === 'function') {
    registerPaymentMethod(ChapChapPayPaymentMethod);
}

let originalFetch = window.fetch;
window.fetch = function(...args) {
    if (args[0] && args[0].includes('/wc/store/v1/checkout')) {
        
        if (args[1] && args[1].body) {
            try {
                const body = JSON.parse(args[1].body);
                
                if (body && !body.payment_method) {
                    body.payment_method = 'chap_chap_pay';
                    args[1].body = JSON.stringify(body);
                }
                
            } catch (e) {
                console.error('Error modifying request:', e);
            }
        }
        
        return originalFetch.apply(this, args).then(response => {
            if (!response.ok) {
                return response.clone().text().then(text => {
                    throw new Error(text);
                });
            }
            return response;
        });
    }
    return originalFetch.apply(this, args);
};