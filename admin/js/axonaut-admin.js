(function ($) {
    'use strict';

    /**
     * All of the code for your admin-facing JavaScript source
     * should reside in this file.
     *
     * Note: It has been assumed you will write jQuery code here, so the
     * $ function reference has been prepared for usage within the scope
     * of this function.
     *
     * This enables you to define handlers, for when the DOM is ready:
     *
     * $(function() {
	 *
	 * });
     *
     * When the window is loaded:
     *
     * $( window ).load(function() {
	 *
	 * });
     *
     * ...and/or other possibilities.
     *
     * Ideally, it is not considered best practise to attach more than a
     * single DOM-ready or window-load handler for a particular page.
     * Although scripts in the WordPress core, Plugins and Themes may be
     * practising this, we should strive to set a better example in our own work.
     */

    $('form[name=sync]').submit(function () {

        // Maybe show a loading indicator...
        $.get($(this).attr('action'), $(this).serialize(), function (res) {
            // Do something with the response `res`
            $("#loading").hide();
            alert(res);
            location.reload();
            // Don't forget to hide the loading indicator!
        });

        return false; // prevent default action

    });

    $(document).ready(function () {
        $(document).ajaxStart(function () {
            $('input[name="axonaut_submit_synchro"]').hide();
            $('#axonaut_last_sync_date').val(new Date());
            $("#loading").show();
        }).ajaxStop(function () {
            $("#loading").hide();
            $('input[name="axonaut_submit_synchro"]').show();
            // $('input[name="submit"]').show();
        });
    });

})(jQuery);
