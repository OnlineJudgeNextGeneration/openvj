define ['openvj/core', 'jquery'], (VJ, $) ->
    $('.role-login-form').on 'submit', (event) ->
        form = $(this).serializeObject()
        if form.username.length is 0
            $('.role-username').focus()
            event.preventDefault()
        if form.password.length is 0
            $('.role-password').focus()
            event.preventDefault()