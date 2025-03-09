<?php

if (rex::isBackend() && rex::getUser()) {
    // Add JavaScript to ffmpeg page
    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg') {
        rex_view::addJsFile(rex_addon::get('ffmpeg')->getAssetsUrl('js/script.js'));
        rex_view::addCssFile(rex_addon::get('ffmpeg')->getAssetsUrl('css/style.css'));
    }
    
    // Create session variables if needed
    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
}
