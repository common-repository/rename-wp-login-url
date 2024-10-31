jQuery(document).ready(function($){
    $(document).on('keyup','input[name="wplur_page"]',function(){
        var login_url = $(this).val();
        var redirect_url = $('input[name="wplur_redirect_admin"]').val();
        if(login_url == redirect_url){
            $('<p style="color:red;">Login URL & Redirect URL both are same.</p>').insertAfter('#wplur-settings');
        }
    });
    $(document).on('keyup','input[name="wplur_redirect_admin"]',function(){
        var login_url = $('input[name="wplur_page"]').val();
        var redirect_url = $(this).val();
        if(login_url == redirect_url){
            $('<p style="color:red;">Login URL & Redirect URL both are same.</p>').insertAfter('#wplur-settings');
        }
    });
});