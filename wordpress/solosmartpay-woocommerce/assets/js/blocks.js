(function () {
  var registry = window.wc && window.wc.wcBlocksRegistry;
  var settings = window.wc && window.wc.wcSettings;
  if (!registry || !settings) return;

  var data = settings.getSetting('solosmartpay_data', {});
  var createElement = window.wp.element.createElement;
  var decode = window.wp.htmlEntities.decodeEntities;
  var label = decode(data.title || 'Solosmartpay');

  var Content = function () {
    return createElement('div', null, decode(data.description || ''));
  };

  registry.registerPaymentMethod({
    name: 'solosmartpay',
    label: createElement('span', null, label),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    canMakePayment: function () { return true; },
    ariaLabel: label,
    supports: { features: data.supports || ['products'] },
  });
})();
