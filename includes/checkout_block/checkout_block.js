const payu_settings = window.wc.wcSettings.getSetting('payu_data', {});
//console.log(settings);
const Payulabel = window.wp.htmlEntities.decodeEntities(payu_settings.title) || window.wp.i18n.__('PayU CommercePro Plugin', 'payu');
//console.log(label);

const Content = () => {
    return window.wp.htmlEntities.decodeEntities(payu_settings.description || '');
};

const Payu_Block_Gateway = {
    name: 'payubiz',
    label: Payulabel,
    content: Object(window.wp.element.createElement)(Content, null ),
    edit: Object(window.wp.element.createElement)(Content, null ),
    //canMakePayment: () => true,
    canMakePayment: () => {
        // Ensure that the payment method is available in CommercePro mode as well
        console.log('Checking canMakePayment for PayU');
        return true;  // Always return true to test
    },
    ariaLabel: Payulabel,
    supports: {
        features: payu_settings.supports,
    },
};  
// console.log("====== Block Gateway ==============");
// console.log(Payu_Block_Gateway);
window.wc.wcBlocksRegistry.registerPaymentMethod( Payu_Block_Gateway );
 