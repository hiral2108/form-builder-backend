<div style="width: 100%;height: auto;background-color: #ffffff;font-size: 14px; line-height: 1.7; margin: 0; padding: 0;font-family: system-ui;">
    <div style="max-width: 600px;width: auto;margin: 0 auto;padding: 10px;">
        <div style="width: 100%;height: auto;margin: 0 auto;text-align: center;">
            <img src="<?php echo env('LOGO_URL') ?>" alt="header logo" style="max-width: 100%; height: auto;">
        </div>
        <div class="mail-content-section">
            {!! $mailMessage !!}
        </div>
    </div>
</div>