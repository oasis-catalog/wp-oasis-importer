document.getElementById("total-price").onclick = upTotalPrice;

function upTotalPrice() {
    let widgets = document.querySelectorAll(".js--oasis-branding-widget");
    let brandingItem = [];

    if (widgets.length) {
        widgets.forEach((wel) => {
            let productId = wel.getAttribute("data-product-id");
            let quantity = wel.getAttribute("data-product-quantity");
            let items = wel.querySelectorAll(".oasis-branding-widget-togglers__item");

            if (items.length) {
                items.forEach((bel) => {
                    let placeId = bel.querySelector("input[name*=\"placeId\"]");

                    if (placeId) {
                        let typeId = bel.querySelector(".oasis-branding-widget-togglers__item input[name*=\"typeId\"]");
                        let dataWidth = bel.querySelector(".oasis-branding-widget-togglers__item input[name*=\"width\"]");
                        let dataHeight = bel.querySelector(".oasis-branding-widget-togglers__item input[name*=\"height\"]");

                        brandingItem.push({
                            productId: productId,
                            quantity: quantity,
                            placeId: placeId.value,
                            typeId: typeId.value,
                            width: dataWidth.value,
                            height: dataHeight.value
                        });
                    }
                });
            }
        });
    }

    sendData(brandingItem);
}

function sendData(inData) {
    let data = {};
    data.action = "oasis_action";
    data.data = inData;

    jQuery(function ($) {
        $.ajax({
            url: uptotalprice.url,
            type: "POST",
            data,
            error: function (request, status, error) {
                if (status == 500) {
                    alert("Ошибка 500");
                } else if (status == "timeout") {
                    alert("Ошибка: Сервер не отвечает, попробуй ещё.");
                } else {
                    let errormsg = request.responseText;
                    let string1 = errormsg.split("<p>");
                    let string2 = string1[1].split("</p>");
                    alert(string2[0]);
                }
            },
            success: function (data) {
                let errorOasis = document.querySelector(".woocommerce-checkout-review-order-table .order-total .error-oasis-branding");
                if (errorOasis !== null) {
                    errorOasis.remove();
                }

                document.querySelector(".woocommerce-checkout-review-order-table .order-total .woocommerce-Price-amount").outerHTML = data;
            }
        });
    });
}
