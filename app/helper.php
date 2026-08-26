<?php

use App\Libraries\Shopifyapi;
use App\Models\AdminUser;

if (! function_exists('check_theme_embeded_status')) {
    function check_theme_embeded_status($shop, $token)
    {
        $userData = AdminUser::where('shop_url', $shop)->first();

        $params = [
            'shop_domain' => $shop,
            'token' => $token,
            'api_key' => config('services.shopify.key'),
            'secret' => config('services.shopify.secret'),
        ];
        $shopifyapi = new Shopifyapi($params);

        $store_themes = $shopifyapi->directrequest($shop, $token, 'GET', '/admin/api/'.config('services.shopify.version').'/themes.json');
        $app_block_templates = ['product', 'collection', 'index'];
        $store_theme_id = '';

        if (isset($store_themes['themes']) && ! empty($store_themes['themes'])) {
            foreach ($store_themes['themes'] as $store_theme) {
                if (isset($store_theme['role']) && $store_theme['role'] == 'main') {
                    $store_theme_id = $store_theme['id'];
                    break;
                }
            }
        }
        $app_embeded_notification = 0;
        $store_theme_settings = $shopifyapi->directrequest($shop, $token, 'GET', '/admin/api/'.config('services.shopify.version').'/themes/'.$store_theme_id.'/assets.json?asset[key]=config/settings_data.json');
        $setting_value = [];
        if (isset($store_theme_settings['asset']['value'])) {
            $setting_value = json_decode($store_theme_settings['asset']['value'], true);
        }

        if (isset($setting_value['current']['blocks']) && $setting_value['current']['blocks']) {
            foreach ($setting_value['current']['blocks'] as $blocks) {

                if (strpos($blocks['type'], env('EXTENSION_ID')) !== false) {
                    if ($blocks['disabled'] == 1) {
                        $app_embeded_notification = 1;
                    } else {
                        $app_embeded_notification = 2;
                    }
                }
            }
        }

        if ($app_embeded_notification == 0) {
            $app_embeded_notification = checkThemeAppBlock($userData);
        }

        if ($app_embeded_notification == 0) {
            $app_embeded_notification = 1;
        }

        if (config('app.check_theme_app_block') === true) {
            return $app_embeded_notification;
        } else {
            return 0;
        }

    }
}

if (! function_exists('checkThemeAppBlock')) {
    function checkThemeAppBlock($userData)
    {
        $params = [
            'shop_domain' => $userData->shop_url,
            'token' => $userData->token,
            'api_key' => config('services.shopify.key'),
            'secret' => config('services.shopify.secret'),
        ];
        $shopifyapi = new Shopifyapi($params);
        $acceptsAppBlock = 0;
        $store_themes = $shopifyapi->directrequest($userData->shop_url, $userData->token, 'GET', '/admin/api/'.config('services.shopify.version').'/themes.json');
        $app_block_templates = ['product', 'collection', 'index'];
        $store_theme_id = '';
        if (isset($store_themes['themes']) && ! empty($store_themes['themes'])) {
            foreach ($store_themes['themes'] as $store_theme) {
                if (isset($store_theme['role']) && $store_theme['role'] == 'main') {
                    $store_theme_id = $store_theme['id'];
                    break;
                }
            }

            foreach ($app_block_templates as $block_template) {
                $store_block_template_asset = $shopifyapi->directrequest($userData->shop_url, $userData->token, 'GET', '/admin/api/'.config('services.shopify.version').'/themes/'.$store_theme_id.'/assets.json?asset[key]=templates/'.$block_template.'.json');
                if (isset($store_block_template_asset['asset']) && $store_block_template_asset['asset']['value']) {
                    $asset_value = json_decode($store_block_template_asset['asset']['value'], true);
                    if (! empty($asset_value)) {
                        foreach ($asset_value['sections'] as $key => $value) {
                            if ($key == 'main' && strpos($value['type'], 'main-') !== false) {
                                $store_block_liquid_asset = $shopifyapi->directrequest($userData->shop_url, $userData->token, 'GET', '/admin/api/'.config('services.shopify.version').'/themes/'.$store_theme_id.'/assets.json?asset[key]=sections/'.$value['type'].'.liquid');
                                $liquid_asset_value = $store_block_liquid_asset['asset']['value'];

                                $pattern = '/\{\%\s+schema\s+\%\}([\s\S]*?)\{\%\s+endschema\s+\%\}/m';
                                $match = preg_match('/\{\%\s+schema\s+\%\}([\s\S]*?)\{\%\s+endschema\s+\%\}/m', $liquid_asset_value, $matches);

                                $schema = json_decode($matches[1], true);

                                if ($schema && array_key_exists('blocks', $schema)) {
                                    foreach ($schema['blocks'] as $block) {
                                        if (array_key_exists('type', (array) $block) && $block['type'] === '@app') {
                                            $acceptsAppBlock = 1;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

        }

        return $acceptsAppBlock;
    }
}

if (! function_exists('get_slack_message')) {
    function get_slack_message($pretext, $title, $text, $color)
    {
        if (env('TEST_MESSAGE')) {
            $channel = env('SLACK_CHANNEL');
            $username = 'chirag';

            $url = 'https://hooks.slack.com/services/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';

            $args = [
                'channel' => $channel,
                'username' => $username,
                'icon_emoji' => ':tada:',
                'icon_url' => 'https://cdn3.iconfinder.com/data/icons/glypho-computers-andother-tech/64/user-spy-thief-glasses-hat-512.png',
                'attachments' => [
                    [
                        'text' => html_entity_decode($text),
                        'title' => html_entity_decode($title),
                        'pretext' => html_entity_decode($pretext),
                        'color' => $color,
                    ],
                ],
            ];

            $headers = [
                'content-type' => 'application/json',
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
            $result = curl_exec($ch);
            curl_close($ch);
        }
    }
}

if(!function_exists('shortcode_atts')) {
    function shortcode_atts( $pairs, $atts ) {
        $atts = (array) $atts;
        $out  = array();
        foreach ( $pairs as $name => $default ) {
            if ( array_key_exists( $name, $atts ) ) {
                $out[ $name ] = $atts[ $name ];
            } else {
                $out[ $name ] = $default;
            }
        }
        return $out;
    }
}

if(!function_exists('get_default_form_field_setting')) {
    function get_default_form_field_setting() {
        return [
            'fields' => [],
            'selectedFieldId' => null,
            'submitButtonText' => "Submit",
            'submitButtonSize' => "lg",
            'submitButtonPlacement' => "center",
            'submitButtonContainerClass' => "",
            'submitButtonElementClass' => "",
        ];
    }
}

if(!function_exists('get_default_form_style_setting')) {
     function get_default_form_style_setting() {
         return [
             'formInfo' => [
                 'formTitle' => "",
                'formDescription' => "",
             ],
             'labelStyle' => [
                 'showLabel' => "show",
                'textColor' => "#1E293B",
                'fontSize' => 14,
                'fontWeight' => "medium",
             ],
             'inputStyle' => [
                'inputWidth' => "default",
                'inputSize' => "default",
                'inputBgColor' => "#FFFFFF",
                'inputTextColor' => "#1E293B",
                'inputBorderColor' => "#CCCCCC",
                'inputBorderWidth' => 1,
                'inputBorderRadius' => 12,
                'inputBoxShadowX' => 0,
                'inputBoxShadowY' => 0,
                'inputBoxShadowBlur' => 0,
                'inputBoxShadowOpacity' => 0,
                'inputBoxShadowColor' => "#000000",
                'inputPlaceholderColor' => "#5F6368",
                'inputBorderStyle' => "solid",
                'inputFontSize' => 14,
                'inputFontWeight' => "medium",
                'inputPaddingTop' => 8,
                'inputPaddingRight' => 12,
                'inputPaddingBottom' => 8,
                'inputPaddingLeft' => 12,
                'inputMarginTop' => 0,
                'inputMarginRight' => 0,
                'inputMarginBottom' => 0,
                'inputMarginLeft' => 0
             ],
             'buttonStyle' => [
                'btnBgColor' => "#0D9488",
                'btnTextColor' => "#FFFFFF",
                'btnBorderColor' => "#CBD5E1",
                'btnBorderWidth' => 0,
                'btnBorderRadius' => 8,
                'btnBoxShadowX' => 0,
                'btnBoxShadowY' => 0,
                'btnBoxShadowBlur' => 0,
                'btnBoxShadowOpacity' => 0,
                'btnBoxShadowColor' => "#000000",
                'btnBorderStyle' => "none",
                'btnFontSize' => 14,
                'btnFontWeight' => "medium",
                'btnPaddingTop' => 10,
                'btnPaddingRight' => 20,
                'btnPaddingBottom' => 10,
                'btnPaddingLeft' => 20,
                'btnMarginTop' => 0,
                'btnMarginRight' => 0,
                'btnMarginBottom' => 0,
                'btnMarginLeft' => 0,
             ],
             'formStyle' => [
                 'formBgColor' => "#FFFFFF",
             ],
             'formTitleStyle' => [
                'formTitleTextColor' => "#1E293B",
                'formTitlePaddingTop' => 4,
                'formTitlePaddingRight' => 0,
                'formTitlePaddingBottom' => 0,
                'formTitlePaddingLeft' => 0,
                'formTitleMarginTop' => 0,
                'formTitleMarginRight' => 0,
                'formTitleMarginBottom' => 0,
                'formTitleMarginLeft' => 0,
                'formTitleFontSize' => 16,
                'formTitleAlign' => "left"
             ],
             'formDescStyle' => [
                'formDescTextColor' => "#5F6368",
                'formDescPaddingTop' => 0,
                'formDescPaddingRight' => 0,
                'formDescPaddingBottom' => 0,
                'formDescPaddingLeft' => 0,
                'formDescMarginTop' => 0,
                'formDescMarginRight' => 0,
                'formDescMarginBottom' => 0,
                'formDescMarginLeft' => 0,
                'formDescFontSize' => 14,
                'formDescAlign' => "left",
             ]
         ];
     }
}

if(!function_exists('get_default_display_rule_setting')) {
    function get_default_display_rule_setting()
    {
        return [
            'form_type' => "sticky",
            'sticky_buttton_position' => "right",
            'sticky_button_alignment' => "middle",
            'cta_bg_color' => "#14B8A6",
            'cta_text_color' => "#FFFFFF",
            'button_text' => "Contact Us",
            'cta_icon' => "chat-lines",
            'cta_icon_size' => "54",
            'cta_custom_size' => "54",
            'cta_icon_position' => "right",
            'attention_effect' => "attention-none",
            'tooltip_bg_color' => "#14B8A6",
            'tooltip_text_color' => "#FFFFFF",
            'custom_cta_file' => "",
            'custom_cta_url' => ""
        ];
    }
}

if(!function_exists('get_default_submission_setting')) {
    function get_default_submission_setting() {
        return [
            'confirmationType' => "same_page",
            'messageToShow' => "Thank you! Your submission has been received successfully.",
            'customUrl' => "",
            'afterSubmission' => "hide_form",
            'saveToDatabase' => true,
            'sendEmail' => false,
            'emailSettings' => [
                'name' => "",
                'sendToEmail' => "",
                'subject' => "",
                'emailBody' => "",
                'replyTo' => "",
                'bcc' => "",
                'cc' => "",
                'selectedFields' => [],
            ],
        ];
    }
}

if(!function_exists('get_default_time_delay_setting')) {
    function get_default_time_delay_setting() {
        return [
            'is_time_delay' => 0,
            'seconds' => 0
        ];
    }
}

if(!function_exists('get_default_scroll_based_setting')) {
    function get_default_scroll_based_setting() {
        return [
            'is_scroll_trigger' => 0,
            'scroll' => 0
        ];
    }
}

if(!function_exists('get_default_page_rule_setting')) {
    function get_default_page_rule_setting() {
        return [
            'has_page_rule' => 0,
            'rule_setting' => []
        ];
    }
}

if(!function_exists('get_default_date_time_setting')) {
    function get_default_date_time_setting() {
        return [
            'has_date_rule' => 0,
            'timezone' => '0',
            'rule_setting' => []
        ];
    }
}

if(!function_exists('get_default_day_hour_setting')) {
    function get_default_day_hour_setting() {
        return [
            'has_day_hour_rule' => 0,
            'timezone' => '0',
            'time_schedule' => [
                0 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                1 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                2 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                3 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                4 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                5 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ],
                6 => [
                    'status' => 1,
                    'rule_setting' => [
                        [
                            'start_time' => '10:00',
                            'end_time' => '19:00'
                        ]
                    ]
                ]
            ]
        ];
    }
}

if(!function_exists('get_default_country_rule_setting')) {
    function get_default_country_rule_setting() {
        return [
            'has_country_rule' => 0,
            'countries' => []
        ];
    }
}

