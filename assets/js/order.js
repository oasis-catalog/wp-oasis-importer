jQuery(function ($) {
    $('.send_order').click(function () {
        var data = {
            action: 'send_order',
            order_id: this.getAttribute('data-order-id')
        };
        this.setAttribute("disabled", "disabled");

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function (data) {
                if (data.success == false) {
                    alert('Error: ' + data.data);
                } else {
                    setTimeout(function () {
                        location.reload();
                    }, 3 * 1000);
                }
            }
        });
        return false;
    });
});
