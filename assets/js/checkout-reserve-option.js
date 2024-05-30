jQuery(document).ready(function($) {
    $("#add_to_reserve_order").change(function() {
        var reserve = $("#add_to_reserve_order").is(":checked") ? 1 : 0;
        $.ajax({
            url: checkoutReserveOption.ajax_url,
            type: "POST",
            data: {
                action: "toggle_reserve_shipping",
                reserve: reserve
            },
            success: function(response) {
                if (response.success) {
                    $(document.body).trigger("update_checkout");
                }
            }
        });
    });
});
