jQuery(document).ready(function ($) {
    var $btn = $('#gt-translate-all-btn');
    var $progress = $('#gt-translate-progress');
    var $bar = $('#gt-progress-bar');
    var $text = $('#gt-progress-text');
    var $remaining = $('#gt-remaining');
    var totalTranslated = 0;
    var running = false;

    $btn.on('click', function () {
        if (running) return;
        if (!confirm('This will translate all pending strings in batches. Continue?')) return;

        running = true;
        totalTranslated = 0;
        $btn.prop('disabled', true).text('Translating...');
        $progress.show();
        translateBatch();
    });

    function translateBatch() {
        $.post(gt_ajax.url, {
            action: 'gt_translate_batch',
            nonce: gt_ajax.nonce,
            batch_size: 10
        }, function (response) {
            if (!response.success) {
                $text.text('Error: ' + (response.data || 'Unknown error'));
                finish();
                return;
            }

            var data = response.data;
            totalTranslated += data.translated;
            var total = totalTranslated + data.remaining;

            $bar.attr('max', total).val(totalTranslated);
            $remaining.text(data.remaining);
            $text.text('Translated ' + totalTranslated + ' of ' + total + ' strings...');

            if (data.errors && data.errors.length > 0) {
                $text.append(' (Errors: ' + data.errors.join(', ') + ')');
            }

            if (data.remaining > 0 && data.translated > 0) {
                translateBatch();
            } else {
                $text.text('Done! Translated ' + totalTranslated + ' strings.');
                finish();
            }
        }).fail(function () {
            $text.text('Request failed. Please try again.');
            finish();
        });
    }

    function finish() {
        running = false;
        $btn.prop('disabled', false).html('Translate All (<span id="gt-remaining">' + $remaining.text() + '</span> strings)');
        $remaining = $('#gt-remaining');
    }

    // Update check functionality
    var $updateBtn = $('#gt-check-updates-btn');
    var $updateStatus = $('#gt-update-status');
    var $updateDetails = $('#gt-update-details');
    var $updateMessage = $('#gt-update-message');

    $updateBtn.on('click', function () {
        var $self = $(this);
        $self.prop('disabled', true).text('Checking...');
        $updateStatus.hide();

        $.post(gt_ajax.url, {
            action: 'gt_check_updates',
            nonce: gt_ajax.update_nonce
        }, function (response) {
            if (!response.success) {
                $updateStatus.text('Error: ' + (response.data && response.data.message || 'Unknown error'))
                    .css('color', '#d63638').show();
                return;
            }

            var data = response.data;
            if (data.update_available) {
                $updateStatus.text('Update available: v' + data.latest)
                    .css('color', '#00a32a').show();
                $updateMessage.html(
                    'Current version: <strong>' + data.current + '</strong><br>' +
                    'Latest version: <strong>' + data.latest + '</strong>'
                );
                $updateDetails.slideDown();
            } else {
                $updateStatus.text('You are running the latest version (v' + data.current + ')')
                    .css('color', '#2271b1').show();
                $updateDetails.hide();
            }
        }).fail(function () {
            $updateStatus.text('Failed to check for updates.').css('color', '#d63638').show();
        }).always(function () {
            $self.prop('disabled', false).text('Check for Updates');
        });
    });
});
