jQuery(document).ready(function ($) {
  function replaceCartButton() {
    let checkoutButton = $(".wc-block-cart__submit-button");
    // If the checkout button exists and there is no PayU checkout button
    if (checkoutButton.length && !$(".payu-checkout").length) {
      checkoutButton.hide();

      // Add new Buy Now with payu button
      // checkoutButton.after(
      //     '<a href="javascript:void(0);" class="wc-block-components-button wp-element-button checkout-button payu-checkout button alt wc-forward" style="width: 82%;">Buy Now with PayU</a>'
      // );
      checkoutButton.after(
        '<div style="">' +
          '<div style="margin: 11px;gap:10px;display: flex;"><img src="' +
          plugin_data.payulogo +
          '" alt="" style="width: 26%;margin: 12px 10px 13px 10px;"><div><h3>PayUBiz</h3><img src="' +
          plugin_data.image_path +
          '" alt="" style=""></div></div>' +
          '<p style="font-size: 18px;">Pay securely by Credit or Debit card or net banking through PayUBiz</p>' +
          "</div>" +
          '<a href="javascript:void(0);" class="wc-block-components-button wp-element-button checkout-button payu-checkout button alt wc-forward" style="width: 82%;">Buy Now with PayU</a>'
      );
      // PayU checkout function call
      triggerPayUCheckout();
    }
  }

  function triggerPayUCheckout() {
    jQuery(document).on("click", ".payu-checkout", function () {
      var data = {
        billing_alt: 0,
        payment_method: "payubiz",
        _wp_http_referer: "/?wc-ajax=update_order_review",
        "woocommerce-process-checkout-nonce": wc_checkout_params.checkout_nonce,
      };

      console.log(data);
      jQuery.ajax({
        type: "POST",
        url: "?wc-ajax=checkout",
        data: data,
        success: function (response) {
          console.log(response);
          if (response.result == "success") {
            window.location = response.redirect;
          }
        },
      });
    });
  }

  // MutationObserver  page change detect
  const observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      replaceCartButton();
    });
  });

  // Cart container observe
  const cartContainer = document.querySelector(".wc-block-cart");
  if (cartContainer) {
    observer.observe(cartContainer, { childList: true, subtree: true });
  }

  // replaceCartButton();
  setTimeout(replaceCartButton, 2000);
});
