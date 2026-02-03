jQuery(document).ready(function($) {
    'use strict';
    
    const modal = $('#location-modal');
    const form = $('#location-form');
    let editingId = 0;
    let locationsData = [];
    
    // Open modal for adding new location
    $('#add-location-btn').on('click', function() {
        editingId = 0;
        form[0].reset();
        $('#location-id').val('0');
        $('#modal-title').text('Add New Location');
        modal.fadeIn(200);
    });
    
    // Close modal
    $('.modal-close, .modal-cancel, .modal-overlay').on('click', function() {
        modal.fadeOut(200);
    });
    
    // Edit location
    $(document).on('click', '.edit-location', function() {
        const id = $(this).data('id');
        const card = $(this).closest('.location-card');
        
        // Get data from card (in real scenario, fetch from server)
        $('#location-id').val(id);
        $('#modal-title').text('Edit Location');
        
        // Populate form with existing data
        // This would normally fetch from server
        $.ajax({
            url: directpayShipping.ajaxUrl,
            type: 'POST',
            data: {
                action: 'directpay_get_location',
                nonce: directpayShipping.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    const loc = response.data.location;
                    $('#location-carrier').val(loc.carrier);
                    $('#location-type').val(loc.type);
                    $('#location-name').val(loc.name);
                    $('#location-address').val(loc.address);
                    $('#location-city').val(loc.city);
                    $('#location-postal').val(loc.postal_code);
                    $('#location-country').val(loc.country);
                    $('#location-phone').val(loc.phone);
                }
            }
        });
        
        modal.fadeIn(200);
    });
    
    // Delete location
    $(document).on('click', '.delete-location', function() {
        if (!confirm('Are you sure you want to delete this location?')) {
            return;
        }
        
        const id = $(this).data('id');
        const card = $(this).closest('.location-card');
        
        $.ajax({
            url: directpayShipping.ajaxUrl,
            type: 'POST',
            data: {
                action: 'directpay_delete_pickup_location',
                nonce: directpayShipping.nonce,
                id: id
            },
            beforeSend: function() {
                card.css('opacity', '0.5');
            },
            success: function(response) {
                if (response.success) {
                    card.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Show no locations message if grid is empty
                        if ($('.location-card').length === 0) {
                            $('.locations-grid').html(
                                '<div class="no-locations">' +
                                '<p>No pickup locations added yet. Click "Add New Location" to get started.</p>' +
                                '</div>'
                            );
                        }
                    });
                    showNotice('Location deleted successfully', 'success');
                } else {
                    card.css('opacity', '1');
                    showNotice(response.data.message || 'Error deleting location', 'error');
                }
            },
            error: function() {
                card.css('opacity', '1');
                showNotice('Error deleting location', 'error');
            }
        });
    });
    
    // Save location
    form.on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            action: 'directpay_save_pickup_location',
            nonce: directpayShipping.nonce,
            id: $('#location-id').val(),
            carrier: $('#location-carrier').val(),
            type: $('#location-type').val(),
            name: $('#location-name').val(),
            address: $('#location-address').val(),
            city: $('#location-city').val(),
            postal_code: $('#location-postal').val(),
            country: $('#location-country').val(),
            phone: $('#location-phone').val()
        };
        
        $.ajax({
            url: directpayShipping.ajaxUrl,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                form.find('button[type="submit"]').prop('disabled', true).text('Saving...');
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Location saved successfully', 'success');
                    modal.fadeOut(200);
                    
                    // Reload page to show updated data
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                } else {
                    showNotice(response.data.message || 'Error saving location', 'error');
                }
            },
            error: function() {
                showNotice('Error saving location', 'error');
            },
            complete: function() {
                form.find('button[type="submit"]').prop('disabled', false).text('Save Location');
            }
        });
    });
    
    // Filter locations
    function filterLocations() {
        const carrier = $('#filter-carrier').val();
        const type = $('#filter-type').val();
        const country = $('#filter-country').val();
        
        $('.location-card').each(function() {
            const card = $(this);
            let show = true;
            
            if (carrier && card.data('carrier') !== carrier) show = false;
            if (type && card.data('type') !== type) show = false;
            if (country && card.data('country') !== country) show = false;
            
            if (show) {
                card.fadeIn(200);
            } else {
                card.fadeOut(200);
            }
        });
    }
    
    $('#filter-carrier, #filter-type, #filter-country').on('change', filterLocations);
    
    // Save shipping settings
    $('#shipping-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            action: 'directpay_save_shipping_settings',
            nonce: directpayShipping.nonce,
            chronopost_express_price: $('input[name="chronopost_express_price"]').val(),
            chronopost_normal_price: $('input[name="chronopost_normal_price"]').val(),
            mondial_relay_express_price: $('input[name="mondial_relay_express_price"]').val(),
            mondial_relay_normal_price: $('input[name="mondial_relay_normal_price"]').val(),
            chronopost_enabled: $('input[name="chronopost_enabled"]').is(':checked') ? 1 : 0,
            mondial_relay_enabled: $('input[name="mondial_relay_enabled"]').is(':checked') ? 1 : 0
        };
        
        $.ajax({
            url: directpayShipping.ajaxUrl,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                $('#shipping-settings-form button[type="submit"]')
                    .prop('disabled', true)
                    .html('<span class="dashicons dashicons-update-alt"></span> Saving...');
            },
            success: function(response) {
                if (response.success) {
                    showNotice('Settings saved successfully', 'success');
                } else {
                    showNotice(response.data.message || 'Error saving settings', 'error');
                }
            },
            error: function() {
                showNotice('Error saving settings', 'error');
            },
            complete: function() {
                $('#shipping-settings-form button[type="submit"]')
                    .prop('disabled', false)
                    .html('<span class="dashicons dashicons-yes-alt"></span> Save Settings');
            }
        });
    });
    
    // Show notice
    function showNotice(message, type) {
        const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.directpay-shipping-admin h1').after(notice);
        
        setTimeout(function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
});
