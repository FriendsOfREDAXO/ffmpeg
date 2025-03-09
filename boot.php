<?php

if (rex::isBackend() && rex::getUser()) {
    // Add JavaScript to ffmpeg page
    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg') {
        rex_view::addJsFile($this->getAssetsUrl('js/script.js'));
    }
    
    // Create session variables if needed
    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
}
