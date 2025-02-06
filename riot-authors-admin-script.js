jQuery(document).ready(function($) {
    // Add new author via AJAX
    $('#add-author-form').submit(function(e) {
        e.preventDefault();
        var new_author_name = $('#new_author_name').val();
        var nonce = $('#add_author_nonce_field').val();

        $.ajax({
            type: "POST",
            url: ajaxurl, // Automatically set by WordPress
            data: {
                action: 'add_new_author',
                new_author_name: new_author_name,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#message-area').html('<p class="success-message">' + response.data.author_name + ' added successfully.</p>');
                    // Optionally, add the new author to the list dynamically
                } else {
                    $('#message-area').html('<p class="error-message">' + response.data.message + '</p>');
                }
            }
        });
    });

    // Delete author via AJAX with confirmation
    $('.delete-button').click(function(e) {
        e.preventDefault();
        if (!confirm("Are you sure you want to delete this author? This will draft all of their posts.")) {
            return;
        }
        var user_id = $(this).data('id');
        var nonce = $(this).data('delete_author_nonce');

        $.ajax({
            type: "POST",
            url: ajaxurl,
            data: {
                action: 'delete_author',
                user_id: user_id,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#author-' + response.data.user_id).remove();
                    $('#message-area').html('<p class="success-message">Author deleted successfully.</p>');
                } else {
                    $('#message-area').html('<p class="error-message">' + response.data.message + '</p>');
                }
            }
        });
    });
});
