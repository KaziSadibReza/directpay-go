/**
 * DirectPay Go Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Download single translation
        $('.download-translation, .redownload-translation').on('click', function() {
            const $button = $(this);
            const $card = $button.closest('.directpay-language-card');
            const locale = $button.data('locale');
            const language = $button.data('language');
            
            downloadTranslation(locale, language, $card);
        });
        
        // Download all missing translations
        $('#download-all-missing').on('click', function() {
            const $button = $(this);
            const $cards = $('.directpay-language-card.not-installed');
            
            if ($cards.length === 0) {
                alert('All translations are already installed!');
                return;
            }
            
            if (!confirm(`Download ${$cards.length} missing translations?`)) {
                return;
            }
            
            $button.prop('disabled', true);
            
            let completed = 0;
            const total = $cards.length;
            
            $cards.each(function(index) {
                const $card = $(this);
                const locale = $card.find('button').data('locale');
                const language = $card.find('button').data('language');
                
                setTimeout(() => {
                    downloadTranslation(locale, language, $card).then(() => {
                        completed++;
                        if (completed === total) {
                            $button.prop('disabled', false);
                            alert('All translations downloaded successfully!');
                            location.reload();
                        }
                    });
                }, index * 2000); // Stagger downloads by 2 seconds
            });
        });
        
        /**
         * Download translation for a specific locale
         */
        function downloadTranslation(locale, language, $card) {
            return new Promise((resolve, reject) => {
                const $progress = $card.find('.download-progress');
                const $progressFill = $card.find('.progress-fill');
                const $progressMessage = $card.find('.progress-message');
                const $button = $card.find('button');
                
                // Show progress
                $button.prop('disabled', true);
                $progress.show();
                $progressMessage.text(`Downloading ${language}...`);
                
                // Animate progress bar
                let progress = 0;
                const progressInterval = setInterval(() => {
                    progress += 5;
                    if (progress <= 90) {
                        $progressFill.css('width', progress + '%');
                    }
                }, 100);
                
                // Make AJAX request
                $.ajax({
                    url: directPayAdmin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'directpay_download_translation',
                        nonce: directPayAdmin.nonce,
                        locale: locale
                    },
                    success: function(response) {
                        clearInterval(progressInterval);
                        $progressFill.css('width', '100%');
                        
                        if (response.success) {
                            $progressMessage.text(response.data.message);
                            
                            setTimeout(() => {
                                // Update card to installed state
                                $card.removeClass('not-installed').addClass('installed');
                                $card.find('.status-badge')
                                    .removeClass('badge-warning')
                                    .addClass('badge-success')
                                    .html('✓ Installed');
                                
                                const now = new Date();
                                $card.find('.status-message')
                                    .removeClass('warning')
                                    .addClass('success')
                                    .html(`
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        Installed on ${now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                    `);
                                
                                $button.removeClass('download-translation')
                                    .addClass('redownload-translation')
                                    .removeClass('button-primary')
                                    .html(`
                                        <span class="dashicons dashicons-update"></span>
                                        Re-download
                                    `);
                                
                                $progress.hide();
                                $button.prop('disabled', false);
                                
                                resolve();
                            }, 1000);
                        } else {
                            $progressMessage.text('Error: ' + response.data.message);
                            $progressFill.css('background', '#dc3232');
                            
                            setTimeout(() => {
                                $progress.hide();
                                $button.prop('disabled', false);
                            }, 3000);
                            
                            reject();
                        }
                    },
                    error: function(xhr, status, error) {
                        clearInterval(progressInterval);
                        $progressMessage.text('Error: ' + error);
                        $progressFill.css('background', '#dc3232');
                        
                        setTimeout(() => {
                            $progress.hide();
                            $button.prop('disabled', false);
                        }, 3000);
                        
                        reject();
                    }
                });
            });
        }
        
    });
    
})(jQuery);
