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
});
